# Bambu Connect

`Bambu Connect` ist ein IP-Symcon-Modul fuer Bambu Lab 3D-Drucker. Es verbindet sich direkt per MQTT mit dem Drucker, verarbeitet die Statusdaten und stellt eine moderne Kachel fuer die neue IP-Symcon Visualisierung bereit.

Das Modul ist als eigenstaendige Druckerinstanz gedacht. Die MQTT-Verbindung wird direkt in der Instanz konfiguriert.

## Funktionen

- Direkte MQTT-Anbindung ueber eine IP-Symcon Client-Socket-IO-Instanz.
- Eingebauter MQTT-Client fuer `CONNECT`, `SUBSCRIBE`, `PUBLISH` und `PINGREQ`.
- Standard-Topic mit Seriennummer-Platzhalter: `device/{SERIAL}/report`.
- Interner Cache des letzten gueltigen Druckerstatus.
- Moderne Kachel mit rundem Fortschrittsring.
- Konfigurierbare Akzentfarbe und Groesse des Fortschrittskreises.
- Anzeige von Druckstatus, Druckname, Fortschritt, Restzeit, Layern, Nozzle-, Bett- und Bauraumtemperatur.
- Zusatzfelder fuer WLAN, Fehlerstatus, AMS-Temperatur und AMS-Feuchte.
- AMS-Filamentanzeige mit Material, Restmenge und Filamentfarbe.
- Optionales Anlegen von Statusvariablen unterhalb der Instanz.
- Optionales Anlegen von AMS-Zusatzvariablen, inklusive Farbwerten im Symcon-RGB-Format.
- Automatischer Reconnect bei ausbleibenden Daten.
- Bewusst keine automatische Abbildung des gesamten Bambu-JSON-Baums.

## Anforderungen

- IP-Symcon 9.0 oder neuer.
- Moderne IP-Symcon Kachelvisualisierung.
- Bambu Lab Drucker mit lokal erreichbaren MQTT-Statusdaten.
- Zugriffscode des Druckers.
- Je nach Druckermodell muss der lokale MQTT-/Developer-Modus am Drucker aktiviert sein.

## Getestete Drucker

Getestet wurde das Modul bisher mit:

- Bambu Lab H2S

Voraussichtlich funktioniert das Modul auch mit weiteren Bambu Lab Druckern, die ein kompatibles lokales MQTT-Status-Topic mit `print`-Payload bereitstellen, zum Beispiel Modelle aus den H-, X-, P- und A-Serien. Das ist noch nicht abschliessend getestet und daher ohne Gewaehr.

## Installation

1. In IP-Symcon die Verwaltungskonsole oeffnen.
2. Zu `Kern Instanzen` -> `Modules` bzw. `Module Control` wechseln.
3. Dieses Repository hinzufuegen:

   ```text
   https://github.com/thomic13/BambuConnect
   ```

4. Eine neue Instanz `BambuConnect` anlegen.
5. Eine eigene Client-Socket-IO-Instanz fuer den Drucker anlegen oder verbinden.
6. Die `BambuConnect`-Instanz in der Kachelvisualisierung platzieren.

## Drucker vorbereiten

Am Drucker muss der lokale Zugriff per MQTT moeglich sein.

Typische Werte:

```text
Host: IP-Adresse des Druckers
Port: 8883
SSL: aktiv
Benutzername: bblp
Passwort / Access Code: Zugriffscode des Druckers
```

Der Zugriffscode wird am Drucker bzw. in der Bambu-Konfiguration angezeigt. Cloud-Zugangsdaten werden nicht verwendet.

## Client Socket einrichten

Die Client-Socket-IO-Instanz enthaelt nur die TCP-/TLS-Verbindung zum Drucker. Der MQTT-Teil wird vom Modul selbst gesprochen.

Empfohlene Client-Socket-Konfiguration:

```text
Aktiv: ja
Host: IP-Adresse des Druckers
Port: 8883
Benutze SSL: ja
Ueberpruefe Peer: nein
Ueberpruefe Host: nein
Zertifikat verwenden: nein
```

Wichtig: Diese Client-Socket-Instanz darf nicht gleichzeitig als Parent eines separaten IP-Symcon MQTT-Client-Splitters genutzt werden. Pro TCP-Verbindung darf nur ein MQTT-Client sprechen. Fuer `Bambu Connect` daher eine eigene Client-Socket-Instanz verwenden oder den bisherigen MQTT-Client-Splitter fuer diesen Drucker deaktivieren.

## Modul konfigurieren

In der `BambuConnect`-Instanz:

```text
Drucker Seriennummer: 01ABC2345678901
MQTT Topic: device/{SERIAL}/report
Client ID: BambuConnect oder leer lassen
Benutzername: bblp
Passwort / Access Code: Zugriffscode des Druckers
KeepAlive Intervall: 60 Sekunden
Nach Verbindung automatisch abonnieren: aktiv
Verbindung bei ausbleibenden Daten automatisch neu aufbauen: aktiv
```

`{SERIAL}` wird automatisch durch die eingetragene Drucker-Seriennummer ersetzt. Alternativ kann das Topic vollstaendig eingetragen werden, zum Beispiel:

```text
device/01ABC2345678901/report
```

Die MQTT-Protokollversion befindet sich unter `Entwickler-Tools`. Standard ist `MQTT 3.1.1`.

## Verbindung testen

1. Client Socket speichern und aktivieren.
2. In der `BambuConnect`-Instanz `MQTT verbinden` ausfuehren.
3. Im Debug der Instanz auf `CONNACK OK` achten.
4. Danach sollte automatisch `SUBSCRIBE gesendet` und `SUBACK empfangen` erscheinen.
5. Sobald MQTT-Daten eintreffen, aktualisieren sich Kachel und Variablen.

Wenn `CONNACK Fehlercode 5` erscheint, ist in der Regel der Access Code falsch oder der lokale Zugriff am Drucker nicht aktiv.

Wenn keine Daten eintreffen, Seriennummer und Topic pruefen.

## Datenvariablen

Unter `Daten` kann gesteuert werden, welche Werte als IP-Symcon-Variablen unterhalb der Instanz angelegt werden.

Statusvariablen:

- Status
- Druckname
- Fortschritt
- Restzeit Minuten und Restzeit Text
- Nozzle Ist und Soll
- Bett Ist und Soll
- Bauraum Temperatur
- Layer und Layer Gesamt
- WLAN Signal
- Druck Fehlercode und Druck Fehlertext

AMS-Zusatzvariablen:

- AMS Temperatur
- AMS Feuchte
- AMS Slot Material
- AMS Slot Farbe
- AMS Slot Rest

Die AMS-Farbvariablen werden als String im IP-Symcon-RGB-Format gespeichert, zum Beispiel:

```json
{"r":33,"g":150,"b":243}
```

Damit koennen sie bei Bedarf manuell in IP-Symcon als Farbdarstellung mit RGB-Kodierung verwendet werden.

## Kachel

Die Kachel ist eine eigenstaendige IP-Symcon-Visualisierung. Sie nutzt als zentrales Element einen runden Fortschrittsring, ohne Code, Assets oder Layoutdateien anderer Projekte zu uebernehmen.

Konfigurierbar sind:

- Titel
- Akzentfarbe
- Groesse des Fortschrittskreises
- Zusatzwerte in grosser Kachel
- AMS-Filamente in grosser Kachel
- AMS-Restmengen

Die Kachel passt ihre Inhalte an die verfuegbare Groesse an. In groesseren Kacheln koennen zusaetzliche AMS-Details eingeblendet werden.

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
| AMS Klima | `print.ams.ams.0.temp`, `print.ams.ams.0.humidity`, `print.ams.ams.0.humidity_raw` |
| AMS Filamente | `print.ams.ams.*.tray.*` |

## Hinweise

Der Drucker kann die TCP-Verbindung gelegentlich schliessen. In diesem Fall kann der IP-Symcon Client Socket Meldungen wie `Fehler beim Lesen: End of file` ausgeben. Das Modul baut die Verbindung bei aktivem Auto-Reconnect wieder auf, sobald keine Daten mehr eintreffen.

## Entwicklung

Repository-Struktur:

```text
library.json
README.md
BambuConnect/
  form.json
  module.html
  module.json
  module.php
```
