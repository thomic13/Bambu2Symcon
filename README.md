# Bambu2Symcon

`Bambu2Symcon` ist ein IP-Symcon-Modul fuer Bambu Lab 3D-Drucker. Es verarbeitet MQTT-JSON-Payloads, haelt den letzten gueltigen Druckerstatus intern im Cache und stellt eine moderne Kachel fuer die neue IP-Symcon Visualisierung bereit.

Das Modul ist als eigenstaendiger Parser mit Visualisierung gedacht. Optional koennen die wichtigsten Werte zusaetzlich als IP-Symcon-Variablen unterhalb der Instanz angelegt werden.

## Funktionen

- Parser fuer Bambu MQTT-Statuspayloads mit `print`-Block.
- Interner MQTT-Fragmentbuffer fuer RegisterVariable-Datenstroeme.
- Direkte Datenfluss-Anbindung an eine Client-Socket-IO-Instanz.
- Eigener MQTT-Client im Modul fuer `CONNECT`, `SUBSCRIBE`, `PUBLISH` und `PINGREQ`.
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
6. Die Instanz mit einer Client-Socket-IO-Instanz verbinden oder MQTT-Payloads per Skript an die Instanz uebergeben.

## Direkte MQTT-Anbindung ueber Client Socket

`Bambu2Symcon` kann direkt unter einer Client-Socket-IO-Instanz betrieben werden. Der Client Socket enthaelt nur Host/IP, Port und optional SSL. Das Modul uebernimmt den MQTT-Teil selbst.

Client Socket:

```text
Host: IP-Adresse des Druckers
Port: 8883 oder der am Drucker genutzte MQTT-Port
SSL: je nach Drucker-/Symcon-Konfiguration
```

Wichtig: Diese Client-Socket-Instanz darf nicht gleichzeitig als Parent eines separaten IP-Symcon MQTT-Client-Splitters genutzt werden. Pro TCP-Verbindung darf nur ein MQTT-Client sprechen. Fuer Tests entweder eine eigene Client-Socket-Instanz fuer `Bambu2Symcon` anlegen oder den bisherigen MQTT-Client-Splitter/Bridge-Weg deaktivieren.

Bambu2Symcon:

```text
Seriennummer: 0938BC612702364
MQTT Topic: device/0938BC612702364/report
Client ID: Bambu2Symcon
Benutzername: bblp
Passwort / Access Code: Zugriffscode des Druckers
KeepAlive: 60 Sekunden
Beim Anwenden automatisch verbinden: aktiv
Nach Verbindung automatisch abonnieren: aktiv
```

Das Modul verarbeitet eingehende MQTT-PUBLISH-Pakete direkt ueber den IP-Symcon-Datenfluss. RegisterVariable und Zielskript werden in dieser Variante nicht mehr benoetigt.

## MQTT-Payload uebergeben

Alternativ kann ein MQTT-/RegisterVariable-Skript den Rohpayload weiterhin so uebergeben:

```php
$instanceID = 12345; // ID der Bambu2Symcon Instanz
$payload = $_IPS['VALUE'] ?? '';

if ($payload !== '') {
    BAMBU_ProcessPayload($instanceID, $payload);
}
```

Das Modul sucht automatisch den ersten JSON-Anfang im Payload und puffert unvollstaendige JSON-Fragmente. Dadurch funktionieren auch RegisterVariable-Datenstroeme, bei denen grosse MQTT-Nachrichten in mehreren Teilen beim Zielskript ankommen.

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
| AMS | `print.ams.ams.0.temp`, `print.ams.ams.0.humidity`, `print.ams.ams.0.humidity_raw` |

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
