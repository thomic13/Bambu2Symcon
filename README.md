# Bambu2Symcon

`Bambu2Symcon` ist ein IP-Symcon-Modul fuer Bambu Lab 3D-Drucker. Es verarbeitet MQTT-JSON-Payloads, haelt den letzten gueltigen Druckerstatus intern im Cache und stellt eine moderne Kachel fuer die neue IP-Symcon Visualisierung bereit.

Das Modul ist als eigenstaendiger Parser mit Visualisierung gedacht. Optional koennen die wichtigsten Werte zusaetzlich als IP-Symcon-Variablen unterhalb der Instanz angelegt werden.

## Funktionen

- Parser fuer Bambu MQTT-Statuspayloads mit `print`-Block.
- Interner Cache des letzten gueltigen Zustands.
- Moderne HTML-Kachel mit rundem Fortschrittsring.
- Anzeige von Druckstatus, Druckname, Fortschritt, Restzeit, Layern, Nozzle-, Bett- und Bauraumtemperatur.
- Optionale Zusatzwerte fuer WLAN, Fehler, AMS-Temperatur und AMS-Feuchte.
- Optionales automatisches Anlegen von Statusvariablen unterhalb der Modulinstanz.
- Bewusst keine automatische Abbildung des gesamten Bambu-JSON-Baums.

## Anforderungen

- IP-Symcon 9.0 oder neuer.
- Kachelvisualisierung.
- Bambu Lab Drucker mit MQTT-Statusdaten.
- Fuer lokale H2/H2S-Daten ist je nach Modell der Developer Mode erforderlich.

## Installation

1. In IP-Symcon die Verwaltungskonsole oeffnen.
2. Zu `Kern Instanzen` -> `Modules` bzw. `Module Control` wechseln.
3. Dieses Repository hinzufuegen:

   ```text
   https://github.com/thomic13/Bambu2Symcon
   ```

4. Eine neue Instanz `Bambu2Symcon` anlegen.
5. Die Instanz in der Kachelvisualisierung platzieren.
6. MQTT-Payloads per Skript an die Instanz uebergeben.

## MQTT-Payload uebergeben

Die erste Version stellt eine oeffentliche Parser-Methode bereit. Ein MQTT-/RegisterVariable-Skript kann den Rohpayload so uebergeben:

```php
$instanceID = 12345; // ID der Bambu2Symcon Instanz
$payload = $_IPS['VALUE'] ?? '';

if ($payload !== '') {
    BAMBU_ProcessPayload($instanceID, $payload);
}
```

Das Modul sucht automatisch den ersten JSON-Anfang im Payload. Dadurch funktionieren auch Daten, denen vor dem JSON noch MQTT-/Transportbytes vorangestellt sind.

## Verarbeitete Felder

Das Modul liest aktuell unter anderem:

| Wert | Bambu-Felder |
| --- | --- |
| Status | `print.gcode_state` |
| Druckname | `print.subtask_name`, `print.project_name`, `print.gcode_file` |
| Fortschritt | `print.mc_percent`, `print.percent` |
| Restzeit | `print.mc_remaining_time`, `print.remain_time` |
| Layer | `print.layer_num`, `print.total_layer_num` |
| Nozzle | `print.nozzle_temper`, `print.nozzle_target_temper` |
| Bett | `print.bed_temper`, `print.bed_target_temper` |
| Bauraum | `print.chamber_temper`, `print.device.ctc.info.temp` |
| WLAN | `print.wifi_signal` |
| Fehler | `print.print_error` |
| AMS | `print.ams.ams.0.temp`, `print.ams.ams.0.humidity` |

## Design

Die Kachel ist eine eigenstaendige IP-Symcon-Visualisierung. Sie nutzt als zentrales Element einen runden Fortschrittsring, ohne Code, Assets oder Layoutdateien anderer Projekte zu uebernehmen.

## Entwicklung

Repository-Struktur:

```text
library.json
README.md
Bambu2Symcon/
  form.json
  module.html
  module.json
  module.php
```

