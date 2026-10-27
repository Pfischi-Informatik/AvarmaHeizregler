# Avarma Heizregler - IP-Symcon Modul

Liest und steuert eine Hofman-Avarma-Waermepumpe ueber Modbus TCP und bringt
optional einen eigenen Heizregler mit, der den Heiz-Sollwert (P02) nach einer
Ruecklauf-Kennlinie fuehrt und Takten verhindert. Ersetzt das fruehere Skript
`avarma_modbus.php`.

Benoetigt **IP-Symcon >= 6.0** und ein Modbus-TCP-Gateway an der Waermepumpe
(z.B. RS485-zu-WLAN/LAN, Standard-Port 8899).

## Installation

1. In Symcon: Modulverwaltung (Kern-Instanzen -> Modules) -> `+` ->
   `https://github.com/Pfischi-Informatik/AvarmaHeizregler`
2. Alternativ das Repository nach `<Symcon>/modules/AvarmaHeizregler/` kopieren
   und den Symcon-Dienst neu starten.
3. Instanz anlegen: `AvarmaHeizregler` (Hersteller: Hofman).
4. Gateway-IP, Port und Slave-ID (P108 an der Waermepumpe) eintragen, uebernehmen.
5. **Jetzt abfragen** druecken; die Variable "Verbindung OK" muss an sein.

Wichtig: Pro Instanz gibt es genau eine TCP-Verbindung gleichzeitig. Das alte
Skript darf nicht mehr parallel zyklisch laufen.

## Funktionen

Alle Funktionen werden in der Instanzkonfiguration einzeln eingeschaltet.

| Funktion | Einstellung | Standard |
|---|---|---|
| Betriebswerte zyklisch lesen (Temperaturen, Druecke, Frequenzen, Status) | Abfrage aktiv, Abfrageintervall | an, 30 s |
| Systemparameter P00-P115 und erweiterte P116-P136 lesen | Parameter-Leseintervall | 600 s |
| Parameter schreiben, mit Bereichspruefung | Hauptschalter + Haken "Schreiben" pro Datenpunkt | aus |
| Heizregler | Heizregler (Sollwertfuehrung P02, Taktsperre, Heizgrenze) | aus |

### Datenpunkte waehlen

Unter "Datenpunkte" stehen alle bekannten Register mit Gruppe, Adresse und
Wertebereich. **Aktiv** legt die Variable an. Abwaehlen loescht sie samt
Archivdaten. **Schreiben** erlaubt das Aendern aus Symcon, nur bei Parametern
mit Wertebereich. Datenpunkte, die der Heizregler braucht, werden bei
eingeschaltetem Regler immer angelegt.

### Schreiben

Eine Aenderung wird sofort angezeigt und erst nach der Schreibverzoegerung
(Standard 5 s nach der **letzten** Aenderung) in einem Durchgang geschrieben,
mit bis zu 3 Versuchen. Danach wird zurueckgelesen; eine Abweichung landet im
Log. Beim Wechsel des Betriebsmodus wird P01 auf Wunsch mitgeschrieben.

WP Ein/Aus, Betriebsmodus und die Sollwerte Heizen, Kuehlen und Trinkwasser
sind immer schaltbar; der Hauptschalter gilt nur fuer die Systemparameter P..
Ist der Heizregler aktiv, ueberschreibt er "Sollwert Heizen" beim naechsten Lauf.

Betriebsmodi: Trinkwasser, Heizen, Trinkwasser + Heizen, Kuehlen,
Trinkwasser + Kuehlen, OTC, Trinkwasser + OTC.

## Heizregler

Laeuft nur im Modus "Heizen" oder "Trinkwasser + Heizen", nicht bei aktivem
Raumthermostat (P67 = 1) und pausiert, solange Trinkwasser bereitet wird.

1. **Ziel-Ruecklauf** aus einer Geraden zwischen zwei Punkten der
   Aussentemperatur (1-h-Mittel), plus Parallelverschiebung.
   Standard: -10 °C -> 30 °C, 15 °C -> 23 °C.
2. **Ziel-Vorlauf** = Ziel-Ruecklauf + gelernte Spreizung + Raumkorrektur,
   begrenzt auf 24-38 °C. Die Spreizung lernt der Regler im Betrieb; bis dahin
   gilt der Ersatzwert (4 K).
3. **Raumkorrektur** (optional, mit Raumtemperatur-Variable): alle 15 min um
   0,5 K Richtung Raum-Ziel (22 °C), hoechstens ±3 K.
4. **Sollwertfuehrung P02**: Verdichter aus -> direkt auf Ziel. Laeuft er,
   hoechstens 1 K anheben alle 10 min. Absenken sofort, wenn der Vorlauf schon
   darunter liegt, sonst spaetestens nach 30 min (Absenkbremse).
5. **Taktsperre**: 2 Verdichterstarts innerhalb von 45 min (ohne
   Trinkwasser) -> 120 min Pause mit Sollwert 20 °C. Ein laufender Zyklus
   darf zu Ende laufen. Aufheben per Button **Taktsperre aufheben**.
6. **Heizgrenze**: ab 16 °C (1-h-Mittel) Sollwert 20 °C, wieder frei bei
   1 K darunter.

Was der Regler gerade tut, steht in der Variable "Heizregler Status".
Weitere Variablen: Aussentemperatur (1-h-Mittel), Ziel Ruecklauf, Ziel
Vorlauf, Spreizung, Raumkorrektur, Starts im Fenster, Taktsperre bis.
Mit "Heizregler aktiv" laesst er sich in der Visualisierung ein- und
ausschalten.

## Funktionen fuer eigene Skripte

```php
AVHR_Update($id);                    // sofort lesen, ausstehende Aenderungen schreiben, regeln
AVHR_ReadParameters($id);            // Parameterblock sofort lesen
AVHR_WriteParameter($id, 45, 52);    // Parameter schreiben, hier P45 = 52
AVHR_ResetLock($id);                 // Taktsperre aufheben
```

`AVHR_WriteParameter` schreibt nur freigegebene Parameter (Hauptschalter +
Haken "Schreiben") innerhalb ihres Wertebereichs.

## Adressen

Die Adressen sind Drahtadressen (nullbasiert): die Spalte "Decimal+1" der
Hofman-Registerdoku minus 1, z.B. P58 = 8249 -> 0x2038.
