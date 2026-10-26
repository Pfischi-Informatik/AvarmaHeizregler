<?php

declare(strict_types=1);

/**
 * Hofman Avarma - Modbus TCP + Heizregler (ersetzt avarma_modbus.php)
 *
 * Funktionen (per Checkbox in der Instanzkonfiguration):
 *   - Betriebswerte zyklisch lesen (immer, sobald "Abfrage aktiv")
 *   - Systemparameter P00-P115 lesen (eigenes, langsameres Intervall)
 *   - Erweiterte Parameter P116-P136 lesen
 *   - Parameter schreiben (nur freigegebene, mit Bereichsprüfung)
 *   - Heizregler: Sollwertführung P02 nach Rücklauf-Kennlinie, Absenkbremse,
 *     Taktsperre, Heizgrenze
 *
 * Schreiben: Eine Änderung wird sofort angezeigt und nach "WriteDelay"
 * Sekunden ab der LETZTEN Änderung in einem Durchgang geschrieben. Danach
 * wird zurückgelesen und eine Abweichung ins Log geschrieben.
 *
 * Es gibt pro Instanz genau eine TCP-Verbindung gleichzeitig (Semaphore).
 * Das alte Skript darf parallel NICHT mehr zyklisch laufen.
 *
 * Adressen sind Drahtadressen (nullbasiert): Symcon-/Doku-Spalte "Decimal+1"
 * minus 1, z.B. P58 = 8249 -> 0x2038.
 */
class AvarmaHeizregler extends IPSModule
{
    // [Ident, Name, Adresse, Faktor, Profil, signed]
    private const SENSORS = [
        ['C31_BetriebsStatus', 'Betriebsstatus (Bitfeld)',    0x1102, 1,    '',               false],
        ['C36_AusgangsStatus', 'Ausgangsstatus (Bitfeld)',    0x1103, 1,    '',               false],
        ['C29_EingangsStatus', 'Eingangsstatus (Bitfeld)',    0x1104, 1,    '',               false],
        ['C00_Coil',           'Lamellentemperatur',          0x1106, 0.1,  'AVARMA.Temp',    true],
        ['C01_Discharge',      'Druckgastemperatur',          0x1107, 0.1,  'AVARMA.Temp',    true],
        ['C02_Ambient',        'Aussentemperatur',            0x1108, 0.1,  'AVARMA.Temp',    true],
        ['C03_Suction',        'Ansaugtemperatur',            0x1109, 0.1,  'AVARMA.Temp',    true],
        ['C04_EVI_In',         'EVI Eintritt',                0x110A, 0.1,  'AVARMA.Temp',    true],
        ['C05_EVI_Out',        'EVI Austritt',                0x110B, 0.1,  'AVARMA.Temp',    true],
        ['C06_Liquid',         'Kaeltemittel fluessig',       0x110C, 0.1,  'AVARMA.Temp',    true],
        ['C07_WaterIn',        'Wasser Eintritt (Ruecklauf)', 0x110D, 0.1,  'AVARMA.Temp',    true],
        ['C08_WaterOut',       'Wasser Austritt (Vorlauf)',   0x110E, 0.1,  'AVARMA.Temp',    true],
        ['C09_DHW',            'Trinkwasserspeicher',         0x110F, 0.1,  'AVARMA.Temp',    true],
        ['C10_Flow',           'Wasserdurchfluss',            0x1110, 0.1,  'AVARMA.Flow',    false],
        ['C11_DeltaT',         'Spreizung Hauptkreis',        0x1111, 0.1,  'AVARMA.Temp',    true],
        ['C12_DeltaT_EVI',     'Spreizung EVI-Kreis',         0x1112, 0.1,  'AVARMA.Temp',    true],
        ['C13_HighPressure',   'Hochdruck',                   0x1113, 0.01, 'AVARMA.Press',   false],
        ['C14_LowPressure',    'Niederdruck',                 0x1114, 0.01, 'AVARMA.Press',   false],
        ['C15_CompFreq',       'Verdichterfrequenz',          0x1115, 1,    'AVARMA.Freq',    false],
        ['C16_Fan1',           'Ventilator 1 Drehzahl',       0x1116, 1,    'AVARMA.Rpm',     false],
        ['C17_Fan2',           'Ventilator 2 Drehzahl',       0x1117, 1,    'AVARMA.Rpm',     false],
        ['C20_CompFreqTarget', 'Verdichter Zielfrequenz',     0x111A, 1,    'AVARMA.Freq',    false],
        ['C21_CompCurrent',    'Verdichterstrom',             0x111B, 0.1,  'AVARMA.Amp',     false],
        ['C22_IPM',            'IPM-Temperatur',              0x111C, 1,    'AVARMA.TempInt', true],
        ['C23_VoltAC',         'Netzspannung AC',             0x111D, 1,    'AVARMA.VoltInt', false],
        ['C24_VoltDC',         'Zwischenkreis DC',            0x111E, 1,    'AVARMA.VoltInt', false],
        ['C25_T6',             'Sensor T6',                   0x111F, 0.1,  'AVARMA.Temp',    true],
        ['C26_RoomTemp',       'Raumtemperatur (T2)',         0x1120, 0.1,  'AVARMA.Temp',    true],
        ['C27_Evaporator',     'Verdampfertemperatur',        0x1121, 0.1,  'AVARMA.Temp',    true],
        ['C28_Condenser',      'Verfluessigertemperatur',     0x1122, 0.1,  'AVARMA.Temp',    true],
        ['R4387_FreqLimit',    'Frequenzbegrenzung (Grund)',  0x1123, 1,    '',               false],
        ['R4388_SollWW_Heiz',  'Berechnete Solltemp. WW/Heizen', 0x1124, 0.1, 'AVARMA.Temp',  true],
        ['R4389_SollKuehl',    'Berechnete Solltemp. Kuehlen',   0x1125, 0.1, 'AVARMA.Temp',  true],
    ];

    private const STATS = [
        ['S4611_PumpSpeed',   'C1 Pumpendrehzahl', 0x1203, 1, 'AVARMA.Percent', false],
        ['S4612_RunningMode', 'Running Mode',      0x1204, 1, 'AVARMA.RunMode', false],
    ];

    // [Ident, Name, Adresse, Profil, Min, Max] - Min null = nur lesen
    private const SETPOINTS = [
        ['P00_OnOff',   'WP Ein/Aus',           0x1000, 'AVARMA.OnOff',   0,    1],
        ['P02_HeatSet', 'Sollwert Heizen',      0x1002, 'AVARMA.HeatSet', 10,   75],
        ['P03_CoolSet', 'Sollwert Kuehlen',     0x1003, 'AVARMA.CoolSet', 7,    25],
        ['P04_DHWSet',  'Sollwert Trinkwasser', 0x1004, 'AVARMA.HeatSet', 10,   75],
        ['P05_RoomSet', 'Sollwert Raum',        0x1005, 'AVARMA.TempInt', null, null],
    ];

    // UI-Wert => [Bezeichnung, Bits 0x1001, P19/OTC]
    private const MODES = [
        1  => ['Trinkwasser',           1, 0],
        2  => ['Heizen',                2, 0],
        3  => ['Trinkwasser + Heizen',  3, 0],
        4  => ['Kuehlen',               4, 0],
        5  => ['Trinkwasser + Kuehlen', 5, 0],
        12 => ['OTC',                   2, 1],
        13 => ['Trinkwasser + OTC',     3, 1],
    ];

    private const REG_ONOFF = 0x1000;
    private const REG_MODE  = 0x1001;
    private const REG_P01   = 0x2001;
    private const REG_P19   = 0x2011;
    private const REG_P67   = 0x2041;

    /**
     * Systemparameter: [Nr, Offset ab 0x2000, Teiler, Bezeichnung, Min, Max]
     * Wert = Rohwert / Teiler. Min/Max in Anzeigeeinheit laut Registerdoku.
     * Min null = nur lesen (Schutzfunktionen, Regelbeiwerte, Ueberhitzung,
     * Modus-Register - die laufen ueber "Betriebsmodus").
     */
    private const PARAMS = [
        [ 1, 0x01, 1,   'Funktionsauswahl 2',                     null, null],
        [ 6, 0x04, 10,  'A/C Hysterese',                          1,    15],
        [ 7, 0x05, 10,  'WW Hysterese',                           1,    15],
        [ 8, 0x06, 10,  'OTC Maximaltemperatur',                  35,   75],
        [ 9, 0x07, 10,  'OTC Kurvenverschiebung',                 -10,  10],
        [10, 0x08, 1,   'Sterilisation Intervall (Tage)',         1,    99],
        [11, 0x09, 1,   'Sterilisation Startzeit',                0,    23],
        [12, 0x0A, 1,   'Sterilisation Laufzeit',                 5,    99],
        [13, 0x0B, 10,  'Sterilisation Temperatur',               50,   70],
        [14, 0x0C, 1,   'Sterilisation Modus',                    0,    2],
        [15, 0x0D, 1,   'Nachtmodus Start',                       0,    23],
        [16, 0x0E, 1,   'Nachtmodus Ende',                        0,    23],
        [17, 0x0F, 1,   'Nachtmodus aktiv',                       0,    1],
        [18, 0x10, 1,   'WW Zusatzheizung Funktion',              0,    1],
        [19, 0x11, 1,   'OTC Heizen aktiv',                       null, null],
        [20, 0x12, 1,   'Pumpen-Betriebsmodus',                   0,    2],
        [21, 0x13, 1,   'Pumpe Frostschutzzeit',                  5,    50],
        [22, 0x14, 10,  'E2 Start Aussentemperatur',              -30,  20],
        [23, 0x15, 10,  'E1 Start Aussentemperatur',              -30,  20],
        [24, 0x16, 10,  'E1/E2 Stopp Hysterese',                  1,    15],
        [25, 0x17, 10,  'A/C Frostschutztemperatur',              -15,  5],
        [26, 0x18, 1,   'Abtau Intervallfaktor',                  0,    4],
        [27, 0x19, 1,   'Abtauzyklus',                            15,   99],
        [28, 0x1A, 1,   'Abtaumodus',                             0,    1],
        [29, 0x1B, 10,  'Abtaustart Lamellentemperatur',          -8,   5],
        [30, 0x1C, 10,  'Abtauende Lamellentemperatur',           5,    30],
        [31, 0x1D, 1,   'Abtau Maximaldauer',                     2,    20],
        [32, 0x1E, 1,   'Hauptventil Regelart',                   null, null],
        [33, 0x1F, 1,   'Hauptventil manuell Heizen',             null, null],
        [34, 0x20, 1,   'Hauptventil manuell Kuehlen',            null, null],
        [35, 0x21, 10,  'WW Verdichter Obergrenze Wassertemp.',   0,    70],
        [36, 0x22, 1,   'Intervall Verdichter/E1 Start',          null, null],
        [37, 0x23, 10,  'Luefterdrehzahl Heizen',                 2,    15],
        [38, 0x24, 10,  'Luefterdrehzahl Kuehlen',                3,    18],
        [39, 0x25, 1,   'Verdichtermodell',                       null, null],
        [40, 0x26, 1,   'Funktion Festfrequenz',                  null, null],
        [41, 0x27, 1,   'Oelrueckfuehrungsfrequenz',              10,   100],
        [42, 0x28, 10,  'Strom Frequenzanhebung gesperrt',        null, null],
        [43, 0x29, 10,  'Strom Frequenzabsenkung',                null, null],
        [44, 0x2A, 10,  'Strom Verdichter-Abschaltung',           null, null],
        [45, 0x2B, 1,   'Verdichter Maximalfrequenz',             50,   120],
        [46, 0x2C, 1,   'Verdichter Mindestfrequenz',             0,    90],
        [47, 0x2D, 1,   'Verdichter Abtaufrequenz',               30,   90],
        [48, 0x2E, 1,   'WW Maximalfrequenz (Prozent 2-10)',      2,    10],
        [49, 0x2F, 10,  'Ueberhitzung Proportionalbeiwert',       null, null],
        [50, 0x30, 1,   'Ueberhitzung Differenzialbeiwert',       null, null],
        [51, 0x31, 10,  'HD Frequenzanhebung gesperrt',           null, null],
        [52, 0x32, 10,  'HD Sperre aufgehoben',                   null, null],
        [53, 0x33, 10,  'Hochdruck Schutzpunkt',                  null, null],
        [54, 0x34, 10,  'Niederdruck Schutzpunkt',                null, null],
        [55, 0x35, 10,  'HD Schutz Hysterese',                    null, null],
        [56, 0x36, 100, 'ND Schutz Hysterese',                    null, null],
        [57, 0x37, 1,   'Druckgas Schutztemperatur',              null, null],
        [58, 0x38, 10,  'C1 Pumpe Regel-Spreizung',               3,    8],
        [59, 0x39, 1,   'PWM-Pumpe Mindestdrehzahl (x10 %)',      2,    8],
        [60, 0x3A, 0.1, 'DC-Luefter Maximaldrehzahl',             500,  1500],
        [61, 0x3B, 1,   'Mindestdurchfluss',                      3,    80],
        [62, 0x3C, 1,   'A/C Funktionsauswahl',                   0,    2],
        [63, 0x3D, 1,   'WW Funktionsauswahl',                    0,    1],
        [64, 0x3E, 1,   'EEV Mindestoeffnung',                    null, null],
        [65, 0x3F, 1,   'Pumpe C2 Funktion',                      null, null],
        [66, 0x40, 1,   'Wasserquelle Kuehloption',               null, null],
        [67, 0x41, 1,   'Raumthermostat',                         0,    1],
        [68, 0x42, 1,   'Typ Stroemungswaechter',                 null, null],
        [69, 0x43, 1,   'Luefertyp',                              null, null],
        [70, 0x44, 1,   'Netzausfall-Speicher',                   0,    1],
        [71, 0x45, 1,   'DC-Luefter Drehzahlregelung',            null, null],
        [72, 0x46, 10,  'DC-Luefter Handdrehzahl',                null, null],
        [73, 0x47, 1,   'Drucksensor aktiv',                      null, null],
        [74, 0x48, 1,   'EVI-Ventil Regelart',                    null, null],
        [75, 0x49, 1,   'EVI-Ventil Startoeffnung Heizen',        null, null],
        [76, 0x4A, 1,   'EVI-Ventil Startoeffnung Kuehlen',       null, null],
        [77, 0x4B, 10,  'EVI Ueberhitzung Heizen',                null, null],
        [78, 0x4C, 10,  'EVI Ueberhitzung Kuehlen',               null, null],
        [79, 0x4D, 1,   'WLAN Uploadzyklus',                      null, null],
        [80, 0x4E, 10,  'Beiwert Mindestlauffrequenz',            0,    10],
        [81, 0x4F, 1,   'E1/E2 Funktionsmodus',                   null, null],
        [82, 0x50, 10,  'Zweite Waermequelle Starttemperatur',    -30,  20],
        [83, 0x51, 1,   'WW Zirkulationspumpe Modus',             0,    3],
        [84, 0x52, 10,  'WW Zirkulationspumpe Spreizung',         4,    20],
        [85, 0x53, 10,  'Abtauen Aussentemperatur',               0,    20],
        [86, 0x54, 10,  'Abtauen Delta Umgebung/Lamelle',         0,    20],
        [87, 0x55, 1,   'Werkseinstellungen zuruecksetzen',       null, null],
        [88, 0x56, 1,   'Pumpe C3 Funktion',                      null, null],
        [89, 0x57, 10,  'Sauggasueberhitzung Proportionalbeiwert', null, null],
        [90, 0x58, 1,   'Sauggasueberhitzung Differenzialbeiwert', null, null],
        [91, 0x59, 10,  'Abtauen Delta Umgebung/Lamelle 2',       0,    20],
        [92, 0x5A, 10,  'Zielueberhitzung Heizen 1',              null, null],
        [93, 0x5B, 10,  'Zielueberhitzung Heizen 2',              null, null],
        [94, 0x5C, 10,  'Zielueberhitzung Heizen 3',              null, null],
        [95, 0x5D, 10,  'Zielueberhitzung Kuehlen',               null, null],
        [96, 0x5E, 10,  'Zielueberhitzung Heizen 4',              null, null],
        [97, 0x5F, 1,   'Parameter 96',                           null, null],
        [98, 0x60, 1,   'Signalinvertierung Ventil G1',           null, null],
        [99, 0x61, 1,   'Signalinvertierung Ventil G2',           null, null],
        [100, 0x62, 1,  'Signalinvertierung Ventil G3',           null, null],
        [101, 0x63, 1,  'EEV Schritte beim Abtauen',              null, null],
        [102, 0x64, 10, 'Schutzwert Spreizung Ein-/Austritt',     8,    20],
        [103, 0x65, 1,  'EEV Startoeffnung Haltezeit',            null, null],
        [104, 0x66, 1,  'Startfrequenz Leistungsberechnung',      20,   60],
        [105, 0x02, 1,  'Verdichter Plateaufrequenz',             20,   60],
        [106, 0x03, 1,  'Verdichter Plateau Verzoegerung',        0,    300],
        [107, 0x67, 1,  'PRt Berechnungsvolumen',                 1,    100],
        [108, 0x68, 1,  'RS485 Adresse',                          null, null],
        [109, 0x69, 1,  'Druckgas Grenzwert 1',                   null, null],
        [110, 0x6A, 1,  'Druckgas Grenzwert 2',                   null, null],
        [111, 0x6B, 1,  'Druckgas Grenzwert 3',                   null, null],
        [112, 0x6C, 1,  'EEV Korrektur bei hoher Druckgastemp.',  null, null],
        [113, 0x6D, 1,  'EEV Korrekturzeit bei hoher Druckgastemp.', null, null],
        [114, 0x6E, 1,  'Frequenzreduktion nach Sollwert',        0,    60],
        [115, 0x6F, 1,  'Schutzwert Austrittstemperatur zu hoch', 70,   90],
    ];

    // Erweiterte Parameter 0x2070-0x2086 (Bereiche nicht dokumentiert -> nur lesen)
    private const PARAMS_EXT = [
        [116, 0x70, 1, 'Elektro-Zusatzheizung Berechnung',  null, null],
        [117, 0x71, 1, 'E0 Heizleistung',                   null, null],
        [118, 0x72, 1, 'E1 Heizleistung',                   null, null],
        [119, 0x73, 1, 'E2 Heizleistung',                   null, null],
        [121, 0x77, 1, 'PV-Aktivierung',                    null, null],
        [122, 0x78, 1, 'Estrichtrocknung',                  null, null],
        [123, 0x79, 1, 'Estrich Periode 1',                 null, null],
        [124, 0x7A, 1, 'Estrich Periode 1 Starttemp.',      null, null],
        [125, 0x7B, 1, 'Estrich Periode 1 Endtemp.',        null, null],
        [126, 0x7C, 1, 'Estrich Periode 2',                 null, null],
        [127, 0x7D, 1, 'Estrich Periode 2 Starttemp.',      null, null],
        [128, 0x7E, 1, 'Estrich Periode 2 Endtemp.',        null, null],
        [129, 0x7F, 1, 'Estrich Periode 3',                 null, null],
        [130, 0x80, 1, 'Estrich Periode 3 Starttemp.',      null, null],
        [131, 0x81, 1, 'Estrich Periode 3 Endtemp.',        null, null],
        [132, 0x82, 1, 'Estrich Periode 4',                 null, null],
        [133, 0x83, 1, 'Estrich Periode 4 Starttemp.',      null, null],
        [134, 0x84, 1, 'Estrich Periode 4 Endtemp.',        null, null],
        [135, 0x85, 1, 'OTC Heizgrenze',                    null, null],
        [136, 0x86, 1, 'OTC Wiedereinschalttemperatur',     null, null],
    ];

    private const RM_DHW  = 1;
    private const RM_HEAT = 2;

    /** Socket nur waehrend eines Buszugriffs */
    private $fp = null;

    // =====================================================================
    // Lebenszyklus
    // =====================================================================

    public function Create()
    {
        parent::Create();

        // Verbindung / Funktionen
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 8899);
        $this->RegisterPropertyInteger('SlaveID', 1);
        $this->RegisterPropertyInteger('Timeout', 3);
        $this->RegisterPropertyBoolean('EnablePolling', true);
        $this->RegisterPropertyInteger('PollInterval', 30);
        $this->RegisterPropertyInteger('ParamInterval', 600);
        $this->RegisterPropertyBoolean('EnableParamWrite', false);
        // Auswahltabelle: welche Variablen angelegt / beschrieben werden
        $this->RegisterPropertyString('Selection', json_encode($this->defaultSelection()));
        $this->RegisterPropertyBoolean('SyncP01', true);
        $this->RegisterPropertyInteger('WriteDelay', 5);
        $this->RegisterPropertyInteger('WriteRetries', 3);
        $this->RegisterPropertyBoolean('EnableControl', false);

        // Heizregler
        $this->RegisterPropertyInteger('RoomTempVar', 0);
        $this->RegisterPropertyFloat('RoomTarget', 22.0);
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

        // Interner Zustand
        $this->RegisterAttributeString('Pending', '{"last":0,"items":{}}');
        $this->RegisterAttributeInteger('LastParamRead', 0);
        $this->RegisterAttributeInteger('LastFreq', 0);
        $this->RegisterAttributeInteger('LastControl', 0);
        $this->RegisterAttributeFloat('TaAvg', -999.0);
        $this->RegisterAttributeFloat('SpreadAvg', -999.0);
        $this->RegisterAttributeFloat('Corr', 0.0);
        $this->RegisterAttributeInteger('LastCorr', 0);
        $this->RegisterAttributeInteger('LastChange', 0);
        $this->RegisterAttributeString('Starts', '[]');
        $this->RegisterAttributeInteger('LockUntil', 0);
        $this->RegisterAttributeBoolean('HeatOff', false);

        $this->RegisterTimer('Poll', 0, 'AVHR_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Flush', 0, 'AVHR_Flush($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->selCache = null;
        if ($this->normalizeSelection()) {
            return;   // ApplyChanges laeuft mit der bereinigten Tabelle erneut
        }

        $this->ensureProfiles();

        // Variablen laut Auswahltabelle anlegen bzw. entfernen
        $write   = $this->ReadPropertyBoolean('EnableParamWrite');
        $active  = 0;
        $removed = [];
        foreach ($this->catalog() as $row) {
            $ident = $row['Ident'];
            $keep  = $this->isActive($ident);
            if (!$keep) {
                $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($vid !== false) {
                    $this->UnregisterVariable($ident);
                    // Fallback, falls die Variable nicht vom Modul selbst angelegt wurde
                    if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false) {
                        IPS_DeleteVariable($vid);
                    }
                    $removed[] = $ident;
                }
                continue;
            }
            $active++;
            $this->MaintainVariable($ident, $row['VarName'], $row['Type'], $row['Profile'], $row['Pos'], true);
            if ($row['Group'] === 'Parameter' || $row['Group'] === 'Parameter erweitert') {
                if ($write && $row['Writable'] && $this->isWrite($ident)) {
                    $this->EnableAction($ident);
                } else {
                    $this->DisableAction($ident);
                }
            } elseif ($row['Writable']) {
                $this->EnableAction($ident);
            }
        }
        $this->RegisterVariableBoolean('Online', 'Verbindung OK', '', 99);
        $this->LogMessage(sprintf('Auswahl: %d Datenpunkte aktiv, %d entfernt%s', $active, count($removed),
            empty($removed) ? '' : ' (' . implode(', ', $removed) . ')'), KL_MESSAGE);

        // Heizregler-Variablen nur bei aktivierter Funktion
        $ctl = $this->ReadPropertyBoolean('EnableControl');
        $this->MaintainVariable('Active', 'Heizregler aktiv', VARIABLETYPE_BOOLEAN, '~Switch', 900, $ctl);
        $this->MaintainVariable('State', 'Heizregler Status', VARIABLETYPE_STRING, '', 901, $ctl);
        $this->MaintainVariable('AmbientAvg', 'Aussentemperatur (1-h-Mittel)', VARIABLETYPE_FLOAT, '~Temperature', 902, $ctl);
        $this->MaintainVariable('TargetReturn', 'Ziel Ruecklauf', VARIABLETYPE_FLOAT, '~Temperature', 903, $ctl);
        $this->MaintainVariable('TargetFlow', 'Ziel Vorlauf', VARIABLETYPE_FLOAT, '~Temperature', 904, $ctl);
        $this->MaintainVariable('Spread', 'Spreizung Mittel (K)', VARIABLETYPE_FLOAT, '', 905, $ctl);
        $this->MaintainVariable('Correction', 'Raumkorrektur (K)', VARIABLETYPE_FLOAT, '', 906, $ctl);
        $this->MaintainVariable('Starts', 'Starts im Fenster', VARIABLETYPE_INTEGER, '', 907, $ctl);
        $this->MaintainVariable('LockUntil', 'Taktsperre bis', VARIABLETYPE_INTEGER, '~UnixTimestamp', 908, $ctl);
        if ($ctl) {
            $this->EnableAction('Active');
        }

        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetTimerInterval('Poll', 0);
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);
        $this->SetTimerInterval('Poll', $this->ReadPropertyBoolean('EnablePolling')
            ? max(10, $this->ReadPropertyInteger('PollInterval')) * 1000 : 0);
    }

    // =====================================================================
    // Oeffentliche Funktionen (AVHR_*)
    // =====================================================================

    /** Zyklischer Lauf: ausstehende Befehle schreiben, alles lesen, regeln. */
    public function Update()
    {
        $this->busRun(false);
    }

    /** Schreibverzoegerung abgelaufen. */
    public function Flush()
    {
        $this->SetTimerInterval('Flush', 0);
        $this->busRun(false);
    }

    /** Parameterblock sofort lesen (unabhaengig vom Intervall). */
    public function ReadParameters()
    {
        $this->busRun(true);
    }

    /** Parameter per Skript schreiben, z.B. AVHR_WriteParameter(12345, 45, 52); */
    public function WriteParameter(int $Number, float $Value)
    {
        $def = $this->findParam($Number);
        if ($def === null) {
            throw new Exception("Parameter P$Number unbekannt");
        }
        $this->RequestAction($this->paramIdent($Number), $Value);
    }

    public function ResetLock()
    {
        $this->WriteAttributeInteger('LockUntil', 0);
        $this->WriteAttributeString('Starts', '[]');
        if ($this->ReadPropertyBoolean('EnableControl')) {
            $this->SetValue('LockUntil', 0);
            $this->SetValue('Starts', 0);
        }
        $this->LogMessage('Taktsperre manuell aufgehoben', KL_MESSAGE);
    }

    public function RequestAction($Ident, $Value)
    {
        // Heizregler ein/aus
        if ($Ident === 'Active') {
            $this->SetValue('Active', (bool) $Value);
            if (!$Value) {
                $this->SetValue('State', 'inaktiv - Sollwert bleibt, wie er ist');
            }
            return;
        }

        // Betriebsmodus
        if ($Ident === 'Mode') {
            $v = (int) $Value;
            if (!isset(self::MODES[$v])) {
                echo "Unbekannter Modus $v";
                return;
            }
            $this->SetValue('Mode', $v);
            $this->queue('Mode', ['mode' => $v]);
            return;
        }

        // Sollwerte / Ein-Aus
        foreach (self::SETPOINTS as [$ident, $name, $reg, $profile, $min, $max]) {
            if ($Ident !== $ident) {
                continue;
            }
            $v = (int) round((float) $Value);
            if ($min === null || $v < $min || $v > $max) {
                echo "$name: $v ausserhalb $min-$max";
                return;
            }
            if ($ident === 'P02_HeatSet' && $this->controlActive()) {
                echo 'Hinweis: Heizregler ist aktiv und ueberschreibt den Sollwert beim naechsten Lauf.';
            }
            $this->SetValue($ident, $v);
            $this->queue($ident, ['reg' => $reg, 'raw' => $v & 0xFFFF]);
            return;
        }

        // Systemparameter
        if (preg_match('/^P(\d{3})$/', (string) $Ident, $m)) {
            $nr  = (int) $m[1];
            $def = $this->findParam($nr);
            if ($def === null) {
                echo "P$nr unbekannt";
                return;
            }
            [$n, $off, $div, $label, $min, $max] = $def;
            if (!$this->ReadPropertyBoolean('EnableParamWrite')) {
                echo 'Parameter schreiben ist in der Instanz deaktiviert';
                return;
            }
            if ($min === null) {
                echo sprintf('P%02d %s ist schreibgeschuetzt', $n, $label);
                return;
            }
            if (!$this->isWrite($Ident)) {
                echo sprintf('P%02d %s: in der Auswahltabelle ist "Schreiben" nicht angehakt', $n, $label);
                return;
            }
            $val = (float) $Value;
            if ($val < $min || $val > $max) {
                echo sprintf('P%02d %s: %s ausserhalb %s-%s', $n, $label, $val, $min, $max);
                return;
            }
            $raw = (int) round($val * $div);
            $this->SetValue($Ident, $div == 1 ? (int) $raw : $raw / $div);
            $this->queue($Ident, ['reg' => 0x2000 + $off, 'raw' => $raw & 0xFFFF, 'param' => true]);
            $this->LogMessage(sprintf('P%02d %s -> %s (roh %d) vorgemerkt', $n, $label, $val, $raw), KL_MESSAGE);
            return;
        }

        throw new Exception('Ungueltiger Ident ' . $Ident);
    }

    // =====================================================================
    // Buszugriff
    // =====================================================================

    private function busRun(bool $forceParams): void
    {
        if ($this->GetStatus() !== 102) {
            return;
        }
        $sem = 'AVHR_Bus_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 15000)) {
            $this->SendDebug('Bus', 'belegt - Lauf uebersprungen', 0);
            return;
        }
        try {
            if (!$this->connect()) {
                $this->SetValue('Online', false);
                return;
            }

            // 1. Ausstehende Schreibbefehle
            $res = $this->flushPending();
            if ($res['count'] > 0) {
                usleep(250000);
            }
            $skip = array_keys($this->pendingLoad()['items']);

            // 2. Steuerblock 0x1000-0x1005
            $ctrl = $this->readBlock(0x1000, 6);
            if ($ctrl !== null) {
                foreach (self::SETPOINTS as [$ident, $name, $reg, $profile, $min, $max]) {
                    if (isset($ctrl[$reg]) && !in_array($ident, $skip, true) && $this->isActive($ident)) {
                        $this->SetValue($ident, $this->signed($ctrl[$reg]));
                    }
                }
            }

            // 3. Betriebswerte, Statistik
            usleep(100000);
            $raw = $this->readBlock(0x1102, 0x24);
            if ($raw !== null) {
                $this->applyDefs(self::SENSORS, $raw);
            }
            usleep(80000);
            $stat = $this->readBlock(0x1203, 2);
            if ($stat !== null) {
                $this->applyDefs(self::STATS, $stat);
            }
            $this->SetValue('Online', $ctrl !== null && $raw !== null);

            // 4. Parameter
            $params = null;
            $anyStd = $this->anyActive(self::PARAMS);
            $anyExt = $this->anyActive(self::PARAMS_EXT);
            $due    = time() - $this->ReadAttributeInteger('LastParamRead') >= $this->ReadPropertyInteger('ParamInterval');
            if (($anyStd || $anyExt) && ($forceParams || $due || $res['params'])) {
                if ($anyStd || $res['params']) {
                    usleep(100000);
                    $params = $this->readBlock(0x2001, 0x6F);
                    if ($params !== null) {
                        $this->applyParams(self::PARAMS, $params, $skip);
                    }
                }
                $this->WriteAttributeInteger('LastParamRead', time());
                if ($anyExt) {
                    usleep(100000);
                    $ext = $this->readBlock(0x2070, 0x17);
                    if ($ext !== null) {
                        $this->applyParams(self::PARAMS_EXT, $ext, $skip);
                    }
                }
            }

            // 5. Rueckkontrolle der geschriebenen Werte
            foreach ($res['expect'] as $reg => $val) {
                $is = $ctrl[$reg] ?? $params[$reg] ?? null;
                if ($is !== null && $is !== $val) {
                    $this->LogMessage(sprintf('0x%04X: geschrieben %d, WP meldet %d', $reg, $val, $is), KL_WARNING);
                }
            }

            // 6. Betriebsmodus zurueckrechnen
            if ($ctrl !== null && !in_array('Mode', $skip, true) && $this->isActive('Mode')) {
                usleep(80000);
                $otc = $params[self::REG_P19] ?? $this->readOne(self::REG_P19);
                $m   = $this->matchMode($ctrl[self::REG_MODE], $otc);
                if ($m !== null) {
                    $this->SetValue('Mode', $m);
                }
            }

            // 7. Heizregler
            if ($raw !== null && $stat !== null && $this->controlActive()) {
                $this->control();
            } elseif ($raw !== null) {
                $this->WriteAttributeInteger('LastFreq', (int) $raw[0x1115]);
            }
        } finally {
            if (is_resource($this->fp)) {
                @fclose($this->fp);
            }
            $this->fp = null;
            IPS_SemaphoreLeave($sem);
        }
    }

    /**
     * Schreibt alle reifen Befehle. Reihenfolge: Modus, Sollwerte, Parameter,
     * Ein/Aus zuletzt.
     */
    private function flushPending(): array
    {
        $out   = ['count' => 0, 'expect' => [], 'params' => false];
        $items = $this->pendingTakeRipe();
        if (empty($items)) {
            return $out;
        }

        $order = array_keys($items);
        usort($order, function ($a, $b) {
            $rank = function ($k) {
                return $k === 'Mode' ? 0 : ($k === 'P00_OnOff' ? 3 : (strpos($k, 'P0') === 0 && strpos($k, '_') ? 1 : 2));
            };
            return $rank($a) <=> $rank($b);
        });

        $failed = [];
        foreach ($order as $key) {
            $item = $items[$key];
            $ok   = true;

            if ($key === 'Mode') {
                [$label, $bits, $otc] = self::MODES[$item['mode']];
                $ok = $this->writeReg(self::REG_MODE, $bits);
                usleep(120000);
                $ok = $this->writeReg(self::REG_P19, $otc) && $ok;
                if ($this->ReadPropertyBoolean('SyncP01')) {
                    usleep(120000);
                    $ok = $this->writeReg(self::REG_P01, $bits) && $ok;
                }
                $out['expect'][self::REG_MODE] = $bits;
                $out['params']                 = true;
                $this->LogMessage("Modus $label -> Bits $bits, OTC $otc, ok=" . var_export($ok, true), KL_MESSAGE);
            } else {
                $ok = $this->writeReg($item['reg'], $item['raw']);
                $out['expect'][$item['reg']] = $item['raw'];
                if (!empty($item['param'])) {
                    $out['params'] = true;
                }
                $this->LogMessage(sprintf('%s -> 0x%04X = %d, ok=%s', $key, $item['reg'], $item['raw'],
                    var_export($ok, true)), KL_MESSAGE);
            }
            if (!$ok) {
                $failed[$key] = $item;
            }
            $out['count']++;
            usleep(120000);
        }

        if (!empty($failed)) {
            $this->pendingRequeue($failed);
        }
        return $out;
    }

    // =====================================================================
    // Heizregler
    // =====================================================================

    private function controlActive(): bool
    {
        return $this->ReadPropertyBoolean('EnableControl')
            && @$this->GetIDForIdent('Active') > 0
            && $this->GetValue('Active');
    }

    private function control(): void
    {
        $now   = time();
        $rm    = (int) $this->GetValue('S4612_RunningMode');
        $freq  = (int) $this->GetValue('C15_CompFreq');
        $vl    = (float) $this->GetValue('C08_WaterOut');
        $dt    = (float) $this->GetValue('C11_DeltaT');
        $ta    = (float) $this->GetValue('C02_Ambient');
        $cur   = (int) $this->GetValue('P02_HeatSet');

        // ---- Starts zaehlen (WW-Starts nicht) ----
        $starts    = json_decode($this->ReadAttributeString('Starts'), true) ?: [];
        $lockUntil = $this->ReadAttributeInteger('LockUntil');
        $blocked   = $lockUntil > $now || $this->ReadAttributeBoolean('HeatOff');
        if ($this->ReadAttributeInteger('LastFreq') === 0 && $freq > 0 && $rm !== self::RM_DHW && !$blocked) {
            $starts[] = $now;
            $this->SendDebug('Start', "Verdichterstart mit $freq Hz", 0);
        }
        $this->WriteAttributeInteger('LastFreq', $freq);
        $window = $this->ReadPropertyInteger('CycleWindowMin') * 60;
        $starts = array_values(array_filter($starts, function ($t) use ($now, $window) {
            return $now - $t <= $window;
        }));
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

        // ---- Aussentemperatur mitteln (Zeitkonstante 1 h) ----
        $last  = $this->ReadAttributeInteger('LastControl');
        $step  = $last > 0 ? max(1, min(3600, $now - $last)) : 0;
        $this->WriteAttributeInteger('LastControl', $now);
        $taAvg = $this->ReadAttributeFloat('TaAvg');
        $taAvg = ($taAvg < -100 || $step === 0) ? ($taAvg < -100 ? $ta : $taAvg)
            : $taAvg + ($step / ($step + 3600)) * ($ta - $taAvg);
        $this->WriteAttributeFloat('TaAvg', $taAvg);
        $this->SetValue('AmbientAvg', round($taAvg, 1));

        // ---- Plausibilitaet ----
        $mode = (int) $this->GetValue('Mode');
        if (!in_array($mode, [2, 3], true)) {
            $this->SetValue('State', 'pausiert: Betriebsmodus ist nicht "Heizen" bzw. "Trinkwasser + Heizen"');
            return;
        }
        $p67 = $this->readOne(self::REG_P67);
        if ($p67 !== null && $p67 !== 0) {
            $this->SetValue('State', 'pausiert: Raumthermostat aktiv (P67 = 1)');
            return;
        }
        if ($rm === self::RM_DHW) {
            $this->SetValue('State', 'Trinkwasser laeuft - Heizregler wartet');
            return;
        }
        $running = $freq > 0 && $rm === self::RM_HEAT;

        // ---- Spreizung lernen ----
        $spread = $this->ReadAttributeFloat('SpreadAvg');
        if ($spread < -100) {
            $spread = $this->ReadPropertyFloat('SpreadFallback');
        }
        if ($running && $dt >= 0.5 && $dt <= 12.0) {
            $spread += 0.1 * ($dt - $spread);
        }
        $this->WriteAttributeFloat('SpreadAvg', $spread);
        $this->SetValue('Spread', round($spread, 1));

        // ---- Raumkorrektur ----
        $corr    = $this->ReadAttributeFloat('Corr');
        $roomVid = $this->ReadPropertyInteger('RoomTempVar');
        if ($roomVid > 0 && IPS_VariableExists($roomVid)) {
            if ($now - $this->ReadAttributeInteger('LastCorr') >= $this->ReadPropertyInteger('CorrIntervalMin') * 60) {
                $diff = $this->ReadPropertyFloat('RoomTarget') - (float) GetValue($roomVid);
                $s    = $this->ReadPropertyFloat('CorrStep');
                $mx   = $this->ReadPropertyFloat('CorrMax');
                if ($diff > 0.2) {
                    $corr = min($mx, $corr + $s);
                } elseif ($diff < -0.2) {
                    $corr = max(-$mx, $corr - $s);
                }
                $this->WriteAttributeFloat('Corr', $corr);
                $this->WriteAttributeInteger('LastCorr', $now);
            }
        } else {
            $corr = 0.0;
            $this->WriteAttributeFloat('Corr', 0.0);
        }
        $this->SetValue('Correction', $corr);

        // ---- Ziele ----
        $rlTarget   = $this->curve($taAvg) + $this->ReadPropertyFloat('Offset');
        $flowTarget = max($this->ReadPropertyInteger('FlowMin'),
            min($this->ReadPropertyInteger('FlowMax'), $rlTarget + $spread + $corr));
        $this->SetValue('TargetReturn', round($rlTarget, 1));
        $this->SetValue('TargetFlow', round($flowTarget, 1));
        $want = (int) round($flowTarget);

        // ---- Heizgrenze (1 K Hysterese) ----
        $limit   = $this->ReadPropertyFloat('HeatLimit');
        $heatOff = $this->ReadAttributeBoolean('HeatOff');
        if (!$heatOff && $taAvg >= $limit) {
            $heatOff = true;
        } elseif ($heatOff && $taAvg <= $limit - 1.0) {
            $heatOff = false;
        }
        $this->WriteAttributeBoolean('HeatOff', $heatOff);

        // ---- Sperren: laufenden Zyklus nicht abwuergen ----
        $block = $this->ReadPropertyInteger('BlockSetpoint');
        $lock  = $lockUntil > $now;
        if ($heatOff || $lock) {
            $what = $heatOff ? sprintf('Heizgrenze (Mittel %.1f >= %.1f)', $taAvg, $limit)
                : 'Taktsperre bis ' . date('H:i', $lockUntil);
            if ($running) {
                $this->SetValue('State', "$what - laufender Zyklus darf zu Ende laufen");
                return;
            }
            $this->setpoint($block, $what);
            $this->SetValue('State', "$what - Sollwert $block °C");
            return;
        }

        // ---- Sollwertfuehrung ----
        $since = $now - $this->ReadAttributeInteger('LastChange');
        $next  = $cur;
        if (!$running) {
            $next = $want;
            $why  = 'Verdichter aus - direkt auf Ziel';
        } elseif ($cur < $want) {
            if ($since >= $this->ReadPropertyInteger('RaiseIntervalMin') * 60) {
                $next = $cur + 1;
                $why  = 'Anheben um 1 K';
            } else {
                $why = 'Anheben wartet (Intervall)';
            }
        } elseif ($cur > $want) {
            if ($vl <= $cur - 1) {
                $next = $cur - 1;
                $why  = sprintf('Absenken, Vorlauf %.1f liegt schon darunter', $vl);
            } elseif ($since >= $this->ReadPropertyInteger('LowerMaxWaitMin') * 60) {
                $next = $cur - 1;
                $why  = 'Absenken nach Wartezeit';
            } else {
                $why = sprintf('Absenkbremse: Vorlauf %.1f noch ueber %d', $vl, $cur - 1);
            }
        } else {
            $why = 'auf Ziel';
        }

        $this->setpoint($next, $why);
        $this->SetValue('State', sprintf('%s | Soll %d °C (Ziel %.1f), Vorlauf %.1f, %d Hz',
            $why, $next, $flowTarget, $vl, $freq));
    }

    /** Schreibt P02 direkt (wir sind bereits im Buszugriff). */
    private function setpoint(int $value, string $why): void
    {
        $value = max(10, min(75, $value));
        $cur   = (int) $this->GetValue('P02_HeatSet');
        if ($cur === $value) {
            return;
        }
        usleep(100000);
        if ($this->writeReg(0x1002, $value)) {
            $this->SetValue('P02_HeatSet', $value);
            $this->WriteAttributeInteger('LastChange', time());
            $this->LogMessage(sprintf('Sollwert Heizen %d -> %d (%s)', $cur, $value, $why), KL_MESSAGE);
        } else {
            $this->LogMessage("Sollwert Heizen $value konnte nicht geschrieben werden", KL_ERROR);
        }
    }

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

    // =====================================================================
    // Schreibpuffer
    // =====================================================================

    private function queue(string $key, array $item): void
    {
        $this->pendingLocked(function (array &$d) use ($key, $item) {
            $item['tries']    = 0;
            $d['items'][$key] = $item;
            $d['last']        = microtime(true);
        });
        $delay = $this->ReadPropertyInteger('WriteDelay');
        if ($delay <= 0) {
            $this->busRun(false);
        } else {
            $this->SetTimerInterval('Flush', $delay * 1000);   // jede Aenderung startet neu
        }
    }

    private function pendingLoad(): array
    {
        $d = json_decode($this->ReadAttributeString('Pending'), true);
        if (!is_array($d)) {
            $d = [];
        }
        $d += ['last' => 0, 'items' => []];
        if (!is_array($d['items'])) {
            $d['items'] = [];
        }
        return $d;
    }

    private function pendingLocked(callable $fn)
    {
        $sem = 'AVHR_Pending_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 3000)) {
            $this->LogMessage('Schreibpuffer gesperrt - Aenderung verworfen', KL_WARNING);
            return null;
        }
        try {
            $d   = $this->pendingLoad();
            $res = $fn($d);
            $this->WriteAttributeString('Pending', json_encode($d));
            return $res;
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    private function pendingTakeRipe(): array
    {
        $delay = $this->ReadPropertyInteger('WriteDelay');
        $r     = $this->pendingLocked(function (array &$d) use ($delay) {
            if (empty($d['items']) || microtime(true) - $d['last'] < $delay - 0.5) {
                return [];
            }
            $items      = $d['items'];
            $d['items'] = [];
            return $items;
        });
        return $r ?? [];
    }

    private function pendingRequeue(array $failed): void
    {
        $max   = $this->ReadPropertyInteger('WriteRetries');
        $added = (bool) $this->pendingLocked(function (array &$d) use ($failed, $max) {
            $added = false;
            foreach ($failed as $key => $item) {
                $item['tries']++;
                if ($item['tries'] >= $max) {
                    $this->LogMessage("$key: nach {$item['tries']} Versuchen aufgegeben", KL_ERROR);
                    continue;
                }
                if (!isset($d['items'][$key])) {   // neuere Eingabe hat Vorrang
                    $d['items'][$key] = $item;
                    $added            = true;
                }
            }
            if ($added) {
                $d['last'] = microtime(true);
            }
            return $added;
        });
        if ($added) {
            $this->SetTimerInterval('Flush', max(5, $this->ReadPropertyInteger('WriteDelay')) * 1000);
        }
    }

    // =====================================================================
    // Modbus TCP
    // =====================================================================

    private function connect(): bool
    {
        if (is_resource($this->fp)) {
            @fclose($this->fp);
        }
        $t  = max(1, $this->ReadPropertyInteger('Timeout'));
        $fp = @fsockopen($this->ReadPropertyString('Host'), $this->ReadPropertyInteger('Port'), $errno, $errstr, $t);
        if (!$fp) {
            $this->SendDebug('Bus', "Verbindung fehlgeschlagen: $errstr ($errno)", 0);
            $this->fp = null;
            return false;
        }
        stream_set_timeout($fp, $t);
        $this->fp = $fp;
        usleep(200000);
        return true;
    }

    private function attempt(string $pdu, bool &$dead): ?string
    {
        static $tid = 0;
        $tid  = ($tid + 1) & 0xFFFF;
        $dead = false;

        if (@fwrite($this->fp, pack('nnn', $tid, 0, strlen($pdu)) . $pdu) === false) {
            $dead = true;
            return null;
        }
        $deadline = microtime(true) + 2.0;
        $head     = $this->readBytes(7, $deadline, $dead, true);
        if ($head === null) {
            return null;
        }
        $meta    = unpack('ntid/nproto/nlen', substr($head, 0, 6));
        $payload = $this->readBytes($meta['len'] - 1, $deadline, $dead, false);
        return $payload;
    }

    private function readBytes(int $need, float $deadline, bool &$dead, bool $eofIsDead): ?string
    {
        $buf = '';
        while (strlen($buf) < $need && microtime(true) < $deadline) {
            $c = @fread($this->fp, $need - strlen($buf));
            if ($c === false) {
                $dead = true;
                return null;
            }
            if ($c === '') {
                if ($eofIsDead && feof($this->fp)) {
                    $dead = true;
                    return null;
                }
                usleep(10000);
                continue;
            }
            $buf .= $c;
        }
        return strlen($buf) < $need ? null : $buf;
    }

    private function transact(string $pdu, string $ctx): ?string
    {
        if (!is_resource($this->fp)) {
            return null;
        }
        $dead    = false;
        $payload = $this->attempt($pdu, $dead);
        if ($payload === null && $dead) {
            $this->SendDebug('Bus', "Verbindung abgebrochen bei $ctx - neu aufbauen", 0);
            if (!$this->connect()) {
                return null;
            }
            $payload = $this->attempt($pdu, $dead);
        }
        if ($payload === null) {
            $this->SendDebug('Bus', "Keine/zu kurze Antwort bei $ctx", 0);
            return null;
        }
        if ((ord($payload[0]) & 0x80) !== 0) {
            $this->SendDebug('Bus', sprintf('Exception 0x%02X bei %s', ord($payload[1]), $ctx), 0);
            return null;
        }
        return $payload;
    }

    private function readBlock(int $start, int $count): ?array
    {
        $slave = $this->ReadPropertyInteger('SlaveID');
        $resp  = $this->transact(chr($slave) . chr(0x03) . pack('nn', $start, $count), sprintf('0x%04X', $start));
        if ($resp === null || strlen($resp) < 2 + $count * 2 || ord($resp[1]) < $count * 2) {
            return null;
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[$start + $i] = (ord($resp[2 + $i * 2]) << 8) | ord($resp[3 + $i * 2]);
        }
        return $out;
    }

    private function readOne(int $addr): ?int
    {
        $r = $this->readBlock($addr, 1);
        return $r === null ? null : $r[$addr];
    }

    private function writeReg(int $addr, int $value): bool
    {
        $slave = $this->ReadPropertyInteger('SlaveID');
        return $this->transact(chr($slave) . chr(0x06) . pack('nn', $addr, $value & 0xFFFF),
            sprintf('Schreiben 0x%04X', $addr)) !== null;
    }

    // =====================================================================
    // Variablen / Profile
    // =====================================================================

    private function signed(int $v): int
    {
        return $v > 32767 ? $v - 65536 : $v;
    }

    private function applyDefs(array $defs, array $raw): void
    {
        foreach ($defs as [$ident, $name, $addr, $factor, $profile, $signed]) {
            if (!isset($raw[$addr]) || !$this->isActive($ident)) {
                continue;
            }
            $v = $signed ? $this->signed($raw[$addr]) : $raw[$addr];
            $this->SetValue($ident, $factor == 1 ? (int) $v : round($v * $factor, 3));
        }
    }

    private function paramIdent(int $nr): string
    {
        return sprintf('P%03d', $nr);
    }

    // =====================================================================
    // Auswahltabelle
    // =====================================================================

    /** Vom Heizregler benoetigt - bei aktivem Regler immer angelegt */
    private const CONTROL_REQUIRED = [
        'Mode', 'P02_HeatSet', 'C02_Ambient', 'C07_WaterIn', 'C08_WaterOut',
        'C11_DeltaT', 'C15_CompFreq', 'S4612_RunningMode',
    ];

    /** @var array|null Auswahl-Cache fuer diesen Aufruf */
    private $selCache = null;

    /** Alle verfuegbaren Datenpunkte mit Anzeige- und Variablendaten. */
    private function catalog(): array
    {
        $rows = [];
        $rows[] = [
            'Ident' => 'Mode', 'VarName' => 'Betriebsmodus', 'Group' => 'Steuerung',
            'Address' => '0x1001 (4098)', 'Range' => 'Modusliste', 'Type' => VARIABLETYPE_INTEGER,
            'Profile' => 'AVARMA.Mode', 'Pos' => 1, 'Writable' => true, 'Default' => true,
        ];
        $pos = 2;
        foreach (self::SETPOINTS as [$ident, $name, $reg, $profile, $min, $max]) {
            $rows[] = [
                'Ident' => $ident, 'VarName' => $name, 'Group' => 'Steuerung',
                'Address' => $this->addrText($reg), 'Range' => $min === null ? 'nur lesen' : "$min … $max",
                'Type' => VARIABLETYPE_INTEGER, 'Profile' => $profile, 'Pos' => $pos++,
                'Writable' => $min !== null, 'Default' => true,
            ];
        }
        $pos = 100;
        foreach ([['Betriebswerte', self::SENSORS], ['Statistik', self::STATS]] as [$group, $defs]) {
            foreach ($defs as [$ident, $name, $addr, $factor, $profile, $signed]) {
                $rows[] = [
                    'Ident' => $ident, 'VarName' => $name, 'Group' => $group,
                    'Address' => $this->addrText($addr), 'Range' => 'nur lesen',
                    'Type' => $factor == 1 ? VARIABLETYPE_INTEGER : VARIABLETYPE_FLOAT,
                    'Profile' => $profile, 'Pos' => $pos++, 'Writable' => false, 'Default' => true,
                ];
            }
        }
        foreach ([['Parameter', self::PARAMS, true], ['Parameter erweitert', self::PARAMS_EXT, false]] as [$group, $defs, $def]) {
            foreach ($defs as [$nr, $off, $div, $label, $min, $max]) {
                $rows[] = [
                    'Ident' => $this->paramIdent($nr), 'VarName' => sprintf('P%02d %s', $nr, $label), 'Group' => $group,
                    'Address' => $this->addrText(0x2000 + $off), 'Range' => $min === null ? 'nur lesen' : "$min … $max",
                    'Type' => $div == 1 ? VARIABLETYPE_INTEGER : VARIABLETYPE_FLOAT,
                    'Profile' => '', 'Pos' => 1000 + $nr, 'Writable' => $min !== null, 'Default' => $def,
                ];
            }
        }
        return $rows;
    }

    /** Drahtadresse hex + 1-basierte Dezimaladresse wie in der Doku */
    private function addrText(int $addr): string
    {
        return sprintf('0x%04X (%d)', $addr, $addr + 1);
    }

    /** Zeilen fuer die Tabelle (= gespeicherter Property-Inhalt) */
    private function defaultSelection(): array
    {
        $out = [];
        foreach ($this->catalog() as $r) {
            $out[] = $this->listRow($r, $r['Default'], false);
        }
        return $out;
    }

    private function listRow(array $r, bool $active, bool $write): array
    {
        return [
            'Active'  => $active,
            'Name'    => $r['VarName'],
            'Group'   => $r['Group'],
            'Address' => $r['Address'],
            'Range'   => $r['Range'],
            'Write'   => $r['Writable'] ? $write : false,
            'Note'    => in_array($r['Ident'], self::CONTROL_REQUIRED, true) ? 'Heizregler: immer aktiv' : '',
            'Ident'   => $r['Ident'],
        ];
    }

    /** Ident => ['Active' => bool, 'Write' => bool]; neue Katalogeintraege mit Standardwert */
    private function selection(): array
    {
        if ($this->selCache !== null) {
            return $this->selCache;
        }
        $stored = [];
        foreach (json_decode($this->ReadPropertyString('Selection'), true) ?: [] as $row) {
            if (isset($row['Ident'])) {
                $stored[$row['Ident']] = $row;
            }
        }
        $sel = [];
        foreach ($this->catalog() as $r) {
            $s                 = $stored[$r['Ident']] ?? null;
            $sel[$r['Ident']] = [
                'Active' => $s === null ? $r['Default'] : (bool) ($s['Active'] ?? false),
                'Write'  => $r['Writable'] && $s !== null && (bool) ($s['Write'] ?? false),
            ];
        }
        return $this->selCache = $sel;
    }

    private function isActive(string $ident): bool
    {
        if ($this->ReadPropertyBoolean('EnableControl') && in_array($ident, self::CONTROL_REQUIRED, true)) {
            return true;
        }
        return $this->selection()[$ident]['Active'] ?? false;
    }

    private function isWrite(string $ident): bool
    {
        return $this->selection()[$ident]['Write'] ?? false;
    }

    private function anyActive(array $paramDefs): bool
    {
        foreach ($paramDefs as $def) {
            if ($this->isActive($this->paramIdent($def[0]))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gespeicherte Tabelle mit dem Katalog abgleichen (neue/entfernte
     * Datenpunkte nach einem Modulupdate). Die Tabelle wird von Symcon aus
     * der Property gefuellt - deshalb wird sie hier und NICHT per
     * GetConfigurationForm befuellt (sonst speichert Symcon Aenderungen nicht).
     * Liefert true, wenn die Property angepasst und neu uebernommen wurde.
     */
    private function normalizeSelection(): bool
    {
        $stored = json_decode($this->ReadPropertyString('Selection'), true) ?: [];
        $have   = array_column($stored, 'Ident');
        $want   = array_column($this->catalog(), 'Ident');
        $noteOk = true;
        foreach ($stored as $row) {
            if (!array_key_exists('Note', $row)) {
                $noteOk = false;
                break;
            }
        }
        if ($have === $want && $noteOk) {
            return false;
        }
        $sel  = $this->selection();
        $rows = [];
        foreach ($this->catalog() as $r) {
            $rows[] = $this->listRow($r, $sel[$r['Ident']]['Active'], $sel[$r['Ident']]['Write']);
        }
        IPS_SetProperty($this->InstanceID, 'Selection', json_encode($rows));
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    private function findParam(int $nr): ?array
    {
        foreach (array_merge(self::PARAMS, self::PARAMS_EXT) as $def) {
            if ($def[0] === $nr) {
                return $def;
            }
        }
        return null;
    }

    private function applyParams(array $defs, array $raw, array $skip): void
    {
        foreach ($defs as $def) {
            [$nr, $off, $div] = $def;
            $addr  = 0x2000 + $off;
            $ident = $this->paramIdent($nr);
            if (!isset($raw[$addr]) || in_array($ident, $skip, true) || !$this->isActive($ident)) {
                continue;
            }
            $v = $this->signed($raw[$addr]);
            $this->SetValue($ident, $div == 1 ? $v : round($v / $div, 3));
        }
    }

    private function matchMode(int $bits, ?int $otc): ?int
    {
        foreach (self::MODES as $value => [$label, $mBits, $mOtc]) {
            if ($mBits === $bits && ($otc === null || $mOtc === $otc)) {
                return $value;
            }
        }
        foreach (self::MODES as $value => [$label, $mBits, $mOtc]) {
            if ($mBits === $bits) {
                return $value;
            }
        }
        return null;
    }

    private function ensureProfiles(): void
    {
        $simple = [
            'AVARMA.Temp'    => [2, ' °C',    1],
            'AVARMA.TempInt' => [1, ' °C',    0],
            'AVARMA.Flow'    => [2, ' l/min', 1],
            'AVARMA.Press'   => [2, ' MPa',   2],
            'AVARMA.Freq'    => [1, ' Hz',    0],
            'AVARMA.Rpm'     => [1, ' rpm',   0],
            'AVARMA.Amp'     => [2, ' A',     1],
            'AVARMA.VoltInt' => [1, ' V',     0],
            'AVARMA.Percent' => [1, ' %',     0],
        ];
        foreach ($simple as $name => [$type, $suffix, $digits]) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $type);
                IPS_SetVariableProfileText($name, '', $suffix);
                if ($type == 2) {
                    IPS_SetVariableProfileDigits($name, $digits);
                }
            }
        }
        foreach ([['AVARMA.CoolSet', 7, 25], ['AVARMA.HeatSet', 10, 75]] as [$name, $min, $max]) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, 1);
            }
            IPS_SetVariableProfileText($name, '', ' °C');
            IPS_SetVariableProfileValues($name, $min, $max, 1);
        }
        $assoc = [
            'AVARMA.OnOff'   => [0 => 'Aus', 1 => 'Ein'],
            'AVARMA.RunMode' => [0 => 'Aus', 1 => 'Trinkwasser', 2 => 'Heizen', 4 => 'Kuehlen'],
            'AVARMA.Mode'    => array_map(function ($m) {
                return $m[0];
            }, self::MODES),
        ];
        foreach ($assoc as $name => $list) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, 1);
            }
            foreach ($list as $v => $t) {
                IPS_SetVariableProfileAssociation($name, $v, $t, '', -1);
            }
        }
    }
}
