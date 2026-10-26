<?php

declare(strict_types=1);

/**
 * Avarma Heizregler
 *
 * Führt den Heiz-Sollwert (P02, Vorlauf) der Hofman Avarma selbst nach,
 * statt die interne OTC-Kurve bzw. den Raumthermostat (P67) zu nutzen:
 *
 *   Rücklauf-Ziel  = Kennlinie(Aussentemperatur, 1-h-Mittel) + Verschiebung
 *   Vorlauf-Ziel   = Rücklauf-Ziel + gemessene Spreizung + Raumkorrektur
 *
 * Der Sollwert wird so nah am tatsächlichen Vorlauf gehalten, dass der
 * Verdichter abregelt statt bis an den Anschlag hochzufahren:
 *   - Verdichter aus:   Sollwert direkt auf das Ziel
 *   - Verdichter läuft: Anheben max. 1 K pro Intervall, Absenken nur,
 *                       wenn der Vorlauf schon darunter liegt (Absenkbremse)
 *   - Taktsperre:       n Starts in x Minuten -> Sollwert für y Minuten
 *                       auf den Sperrwert (WP startet nicht)
 *   - Heizgrenze:       über der Heizgrenze ebenfalls Sperrwert
 *
 * Das Modul spricht NICHT selbst Modbus. Es schreibt per RequestAction auf
 * die Variable P02_HeatSet; das bestehende Modbus-Skript puffert und
 * schreibt (eine Verbindung zum Gateway, Bus-Semaphore bleibt gültig).
 *
 * Voraussetzungen an der WP: Betriebsmodus "Heizen" oder
 * "Trinkwasser + Heizen" (OTC aus, P19 = 0) und P67 = 0.
 */
class AvarmaHeizregler extends IPSModule
{
    /** Idents aus avarma_modbus.php */
    private const IDENTS = [
        'amb'  => 'C02_Ambient',
        'rl'   => 'C07_WaterIn',
        'vl'   => 'C08_WaterOut',
        'dt'   => 'C11_DeltaT',
        'freq' => 'C15_CompFreq',
        'rm'   => 'S4612_RunningMode',
        'set'  => 'P02_HeatSet',
        'mode' => 'Mode',
        'otc'  => 'P019',
        'p67'  => 'P067',
    ];

    private const RM_DHW  = 1;
    private const RM_HEAT = 2;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('AvarmaCategory', 0);
        $this->RegisterPropertyInteger('RoomTempVar', 0);
        $this->RegisterPropertyFloat('RoomTarget', 22.0);
        $this->RegisterPropertyInteger('Interval', 60);

        $this->RegisterPropertyFloat('TaCold', -10.0);
        $this->RegisterPropertyFloat('RlCold', 30.0);
        $this->RegisterPropertyFloat('TaWarm', 15.0);
        $this->RegisterPropertyFloat('RlWarm', 23.0);
        $this->RegisterPropertyFloat('Offset', 0.0);
        $this->RegisterPropertyFloat('SpreadFallback', 4.0);
        $this->RegisterPropertyInteger('FlowMin', 24);
        $this->RegisterPropertyInteger('FlowMax', 38);
        $this->RegisterPropertyFloat('HeatLimit', 16.0);

        $this->RegisterPropertyInteger('RaiseIntervalMin', 10);
        $this->RegisterPropertyInteger('LowerMaxWaitMin', 30);
        $this->RegisterPropertyFloat('CorrStep', 0.5);
        $this->RegisterPropertyInteger('CorrIntervalMin', 15);
        $this->RegisterPropertyFloat('CorrMax', 3.0);

        $this->RegisterPropertyInteger('CycleStarts', 2);
        $this->RegisterPropertyInteger('CycleWindowMin', 45);
        $this->RegisterPropertyInteger('LockMin', 120);
        $this->RegisterPropertyInteger('BlockSetpoint', 20);

        $this->RegisterAttributeFloat('TaAvg', -999.0);
        $this->RegisterAttributeFloat('SpreadAvg', -999.0);
        $this->RegisterAttributeFloat('Corr', 0.0);
        $this->RegisterAttributeInteger('LastCorr', 0);
        $this->RegisterAttributeInteger('LastChange', 0);
        $this->RegisterAttributeString('Starts', '[]');
        $this->RegisterAttributeInteger('PendingStart', 0);
        $this->RegisterAttributeInteger('LockUntil', 0);
        $this->RegisterAttributeBoolean('HeatOff', false);
        $this->RegisterAttributeInteger('LastFreq', 0);

        $this->RegisterTimer('Control', 0, 'AVHR_Control($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterVariableBoolean('Active', 'Regelung aktiv', '~Switch', 10);
        $this->EnableAction('Active');
        $this->RegisterVariableString('State', 'Status', '', 20);
        $this->RegisterVariableFloat('AmbientAvg', 'Aussentemperatur (1-h-Mittel)', '~Temperature', 30);
        $this->RegisterVariableFloat('TargetReturn', 'Ziel Rücklauf', '~Temperature', 40);
        $this->RegisterVariableFloat('TargetFlow', 'Ziel Vorlauf', '~Temperature', 50);
        $this->RegisterVariableFloat('Spread', 'Spreizung (Mittel, K)', '', 60);
        $this->RegisterVariableFloat('Correction', 'Raumkorrektur (K)', '', 70);
        $this->RegisterVariableInteger('Starts', 'Starts im Fenster', '', 80);
        $this->RegisterVariableInteger('LockUntil', 'Taktsperre bis', '~UnixTimestamp', 90);

        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($sender, $message);
            }
        }
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $cat = $this->ReadPropertyInteger('AvarmaCategory');
        if ($cat <= 0 || !IPS_ObjectExists($cat)) {
            $this->SetTimerInterval('Control', 0);
            $this->SetStatus(201);
            return;
        }

        $missing = [];
        foreach (self::IDENTS as $ident) {
            if (@IPS_GetObjectIDByIdent($ident, $cat) === false) {
                $missing[] = $ident;
            }
        }
        if (!empty($missing)) {
            $this->SendDebug('ApplyChanges', 'Fehlende Idents: ' . implode(', ', $missing), 0);
            $this->SetTimerInterval('Control', 0);
            $this->SetStatus(202);
            return;
        }

        $this->RegisterMessage($this->vid('freq'), VM_UPDATE);
        $this->WriteAttributeInteger('LastFreq', (int) GetValue($this->vid('freq')));
        $this->SetTimerInterval('Control', max(30, $this->ReadPropertyInteger('Interval')) * 1000);
        $this->SetStatus(102);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE) {
            return;
        }

        // Verdichterstart erkennen (0 Hz -> > 0 Hz). Ob es ein Heiz- oder
        // ein WW-Start war, entscheidet Control(), wenn der Running Mode
        // aus demselben Abfragezyklus sicher vorliegt.
        $new  = (int) $Data[0];
        $last = $this->ReadAttributeInteger('LastFreq');
        $this->WriteAttributeInteger('LastFreq', $new);

        if ($last === 0 && $new > 0) {
            $this->WriteAttributeInteger('PendingStart', time());
            $this->SendDebug('Start', "Verdichter gestartet mit $new Hz", 0);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Active':
                $this->SetValue('Active', (bool) $Value);
                if ($Value) {
                    $this->Control();
                } else {
                    $this->SetValue('State', 'inaktiv - Sollwert bleibt, wie er ist');
                }
                break;
            default:
                throw new Exception('Ungültiger Ident ' . $Ident);
        }
    }

    public function ResetLock()
    {
        $this->WriteAttributeInteger('LockUntil', 0);
        $this->WriteAttributeString('Starts', '[]');
        $this->SetValue('LockUntil', 0);
        $this->SetValue('Starts', 0);
        $this->LogMessage('Taktsperre manuell aufgehoben', KL_MESSAGE);
        $this->Control();
    }

    public function Control()
    {
        if ($this->GetStatus() !== 102) {
            return;
        }
        $now = time();

        // ---------- Starts auswerten (auch wenn inaktiv, für die Statistik) ----------
        $rm     = (int) GetValue($this->vid('rm'));
        $starts = json_decode($this->ReadAttributeString('Starts'), true) ?: [];
        $ps     = $this->ReadAttributeInteger('PendingStart');
        if ($ps > 0 && $now - $ps >= 20) {
            if ($rm !== self::RM_DHW) {
                $starts[] = $ps;
            } else {
                $this->SendDebug('Start', 'WW-Start, zählt nicht', 0);
            }
            $this->WriteAttributeInteger('PendingStart', 0);
        }
        $window = $this->ReadPropertyInteger('CycleWindowMin') * 60;
        $starts = array_values(array_filter($starts, function ($t) use ($now, $window) {
            return $now - $t <= $window;
        }));

        $lockUntil = $this->ReadAttributeInteger('LockUntil');
        if ($lockUntil <= $now && count($starts) >= $this->ReadPropertyInteger('CycleStarts')) {
            $lockUntil = $now + $this->ReadPropertyInteger('LockMin') * 60;
            $this->WriteAttributeInteger('LockUntil', $lockUntil);
            $this->LogMessage(sprintf('Taktsperre: %d Starts in %d min -> Pause bis %s',
                count($starts), $window / 60, date('H:i', $lockUntil)), KL_WARNING);
            $starts = [];
        }
        $this->WriteAttributeString('Starts', json_encode($starts));
        $this->SetValue('Starts', count($starts));
        $this->SetValue('LockUntil', $lockUntil > $now ? $lockUntil : 0);

        // ---------- Aussentemperatur mitteln (Zeitkonstante ~1 h) ----------
        $interval = max(30, $this->ReadPropertyInteger('Interval'));
        $ta       = (float) GetValue($this->vid('amb'));
        $taAvg    = $this->ReadAttributeFloat('TaAvg');
        if ($taAvg < -100) {
            $taAvg = $ta;
        } else {
            $a     = $interval / ($interval + 3600);
            $taAvg = $taAvg + $a * ($ta - $taAvg);
        }
        $this->WriteAttributeFloat('TaAvg', $taAvg);
        $this->SetValue('AmbientAvg', round($taAvg, 1));

        if (!$this->GetValue('Active')) {
            $this->SetValue('State', 'inaktiv - Sollwert bleibt, wie er ist');
            return;
        }

        // ---------- Plausibilität WP-Konfiguration ----------
        $mode = (int) GetValue($this->vid('mode'));
        if (!in_array($mode, [2, 3], true)) {
            $this->SetValue('State', 'pausiert: Betriebsmodus ist nicht "Heizen" bzw. "Trinkwasser + Heizen"');
            return;
        }
        if ((int) GetValue($this->vid('otc')) !== 0) {
            $this->SetValue('State', 'pausiert: OTC aktiv (P19 = 1) - Modus ohne OTC wählen');
            return;
        }
        if ((int) GetValue($this->vid('p67')) !== 0) {
            $this->SetValue('State', 'pausiert: Raumthermostat aktiv (P67 = 1) - am Display auf 0 setzen');
            return;
        }

        $freq    = (int) GetValue($this->vid('freq'));
        $vl      = (float) GetValue($this->vid('vl'));
        $dt      = (float) GetValue($this->vid('dt'));
        $setVid  = $this->vid('set');
        $current = (int) GetValue($setVid);

        if ($rm === self::RM_DHW) {
            $this->SetValue('State', 'Trinkwasser läuft - Heizregelung wartet');
            return;
        }
        $running = $freq > 0 && $rm === self::RM_HEAT;

        // ---------- Spreizung nur im laufenden Heizbetrieb lernen ----------
        $spread = $this->ReadAttributeFloat('SpreadAvg');
        if ($spread < -100) {
            $spread = $this->ReadPropertyFloat('SpreadFallback');
        }
        if ($running && $dt >= 0.5 && $dt <= 12.0) {
            $spread += 0.1 * ($dt - $spread);
        }
        $this->WriteAttributeFloat('SpreadAvg', $spread);
        $this->SetValue('Spread', round($spread, 1));

        // ---------- Raumkorrektur (langsam) ----------
        $corr    = $this->ReadAttributeFloat('Corr');
        $roomVid = $this->ReadPropertyInteger('RoomTempVar');
        if ($roomVid > 0 && IPS_VariableExists($roomVid)) {
            if ($now - $this->ReadAttributeInteger('LastCorr') >= $this->ReadPropertyInteger('CorrIntervalMin') * 60) {
                $diff = $this->ReadPropertyFloat('RoomTarget') - (float) GetValue($roomVid);
                $step = $this->ReadPropertyFloat('CorrStep');
                $max  = $this->ReadPropertyFloat('CorrMax');
                if ($diff > 0.2) {
                    $corr = min($max, $corr + $step);
                } elseif ($diff < -0.2) {
                    $corr = max(-$max, $corr - $step);
                }
                $this->WriteAttributeFloat('Corr', $corr);
                $this->WriteAttributeInteger('LastCorr', $now);
            }
        } else {
            $corr = 0.0;
            $this->WriteAttributeFloat('Corr', 0.0);
        }
        $this->SetValue('Correction', $corr);

        // ---------- Ziele ----------
        $rlTarget   = $this->curve($taAvg) + $this->ReadPropertyFloat('Offset');
        $flowTarget = $rlTarget + $spread + $corr;
        $flowTarget = max($this->ReadPropertyInteger('FlowMin'), min($this->ReadPropertyInteger('FlowMax'), $flowTarget));
        $this->SetValue('TargetReturn', round($rlTarget, 1));
        $this->SetValue('TargetFlow', round($flowTarget, 1));
        $want = (int) round($flowTarget);

        // ---------- Heizgrenze mit 1 K Hysterese ----------
        $limit   = $this->ReadPropertyFloat('HeatLimit');
        $heatOff = $this->ReadAttributeBoolean('HeatOff');
        if (!$heatOff && $taAvg >= $limit) {
            $heatOff = true;
        } elseif ($heatOff && $taAvg <= $limit - 1.0) {
            $heatOff = false;
        }
        $this->WriteAttributeBoolean('HeatOff', $heatOff);

        $block = $this->ReadPropertyInteger('BlockSetpoint');
        // Einen laufenden Zyklus nicht abwürgen - sonst gibt es genau den
        // Kurzlauf, den die Sperre verhindern soll. Gesperrt wird nach dem Stopp.
        if (($heatOff || $lockUntil > $now) && $running) {
            $this->SetValue('State', ($heatOff ? 'Heizgrenze' : 'Taktsperre')
                . ' aktiv - laufender Zyklus darf zu Ende laufen');
            return;
        }
        if ($heatOff) {
            $this->writeSetpoint($block, 'über Heizgrenze');
            $this->SetValue('State', sprintf('Heizgrenze: Mittel %.1f °C ≥ %.1f °C - Sollwert %d °C', $taAvg, $limit, $block));
            return;
        }
        if ($lockUntil > $now) {
            $this->writeSetpoint($block, 'Taktsperre');
            $this->SetValue('State', sprintf('Taktsperre bis %s - Sollwert %d °C', date('H:i', $lockUntil), $block));
            return;
        }

        // ---------- Sollwertführung ----------
        $sinceChange = $now - $this->ReadAttributeInteger('LastChange');
        $next        = $current;
        $why         = '';

        if (!$running) {
            $next = $want;
            $why  = 'Verdichter aus - direkt auf Ziel';
        } elseif ($current < $want) {
            if ($sinceChange >= $this->ReadPropertyInteger('RaiseIntervalMin') * 60) {
                $next = $current + 1;
                $why  = 'Anheben um 1 K';
            } else {
                $why = 'Anheben wartet (Intervall)';
            }
        } elseif ($current > $want) {
            if ($vl <= $current - 1) {
                $next = $current - 1;
                $why  = sprintf('Absenken, Vorlauf %.1f liegt schon darunter', $vl);
            } elseif ($sinceChange >= $this->ReadPropertyInteger('LowerMaxWaitMin') * 60) {
                $next = $current - 1;
                $why  = 'Absenken nach Wartezeit';
            } else {
                $why = sprintf('Absenkbremse: Vorlauf %.1f noch über %d', $vl, $current - 1);
            }
        } else {
            $why = 'auf Ziel';
        }

        $this->writeSetpoint($next, $why);
        $this->SetValue('State', sprintf('%s | Soll %d °C (Ziel %.1f), Vorlauf %.1f, %d Hz',
            $why, $next, $flowTarget, $vl, $freq));
    }

    // ------------------------------------------------------------------

    private function vid(string $key): int
    {
        $id = @IPS_GetObjectIDByIdent(self::IDENTS[$key], $this->ReadPropertyInteger('AvarmaCategory'));
        return $id === false ? 0 : $id;
    }

    /** Rücklauf-Ziel linear zwischen kaltem und warmem Punkt, ausserhalb konstant. */
    private function curve(float $ta): float
    {
        $t0 = $this->ReadPropertyFloat('TaCold');
        $r0 = $this->ReadPropertyFloat('RlCold');
        $t1 = $this->ReadPropertyFloat('TaWarm');
        $r1 = $this->ReadPropertyFloat('RlWarm');
        if ($t1 <= $t0) {
            return $r0;
        }
        $x = max(0.0, min(1.0, ($ta - $t0) / ($t1 - $t0)));
        return $r0 + ($r1 - $r0) * $x;
    }

    private function writeSetpoint(int $value, string $why): void
    {
        $value = max(10, min(75, $value));
        $vid   = $this->vid('set');
        if ((int) GetValue($vid) === $value) {
            return;
        }
        $this->WriteAttributeInteger('LastChange', time());
        $this->LogMessage(sprintf('Sollwert Heizen %d -> %d (%s)', (int) GetValue($vid), $value, $why), KL_MESSAGE);
        // Läuft über die Aktion des Modbus-Skripts (Puffer + verzögertes Schreiben)
        RequestAction($vid, $value);
    }
}
