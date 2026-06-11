# Bambu Connect

`Bambu Connect` ist ein IP-Symcon-Modul fuer Bambu Lab 3D-Drucker. Es verarbeitet die lokalen MQTT-Statusdaten des Druckers und stellt eine moderne Kachel fuer die neue IP-Symcon Visualisierung bereit.

Das Modul ist als eigenstaendige Druckerinstanz gedacht. Die MQTT-Verbindung selbst laeuft store-konform ueber die IP-Symcon Instanzen `Client Socket` und `MQTT Client`.

## Funktionen

- Anbindung ueber den IP-Symcon `MQTT Client` Splitter.
- Interner Cache des letzten gueltigen Druckerstatus.
- Offline-Anzeige in der Kachel, wenn ueber den MQTT Client keine gueltigen Statusdaten mehr eintreffen.
- Moderne Kachel mit rundem Fortschrittsring.
- Konfigurierbare Akzentfarbe und Groesse des Fortschrittskreises.
- Anzeige von Druckstatus, Druckname, Fortschritt, Restzeit, Layern, Nozzle-, Bett- und Bauraumtemperatur.
- Zusatzfelder fuer WLAN, Fehlerstatus, AMS-Temperatur und AMS-Feuchte.
- AMS-Filamentanzeige mit Material, Restmenge und Filamentfarbe.
- Optionales Anlegen von Statusvariablen unterhalb der Instanz.
- Optionales Anlegen von AMS-Zusatzvariablen, inklusive Farbwerten im Symcon-RGB-Format.
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
5. Einen `Client Socket` fuer den Drucker anlegen.
6. Einen `MQTT Client` an diesen Client Socket haengen.
7. Die `BambuConnect`-Instanz mit dem MQTT Client verbinden.
8. Die `BambuConnect`-Instanz in der Kachelvisualisierung platzieren.

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

Die Client-Socket-IO-Instanz enthaelt nur die TCP-/TLS-Verbindung zum Drucker.

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

## MQTT Client einrichten

Der IP-Symcon `MQTT Client` wird mit dem Client Socket verbunden und uebernimmt MQTT-Login, KeepAlive und Subscription.

Empfohlene MQTT-Client-Konfiguration:

```text
Client ID: eindeutiger Name, z. B. BambuConnect-01ABC2345678901
Benutzername: bblp
Passwort: Zugriffscode des Druckers
KeepAlive Intervall: 60 Sekunden
Subscription: device/01ABC2345678901/report
QoS: 0
```

Die Seriennummer im Subscription-Topic muss zur Seriennummer des Druckers passen.

## Modul konfigurieren

In der `BambuConnect`-Instanz werden nur Daten- und Kacheloptionen konfiguriert. Die Drucker-Seriennummer wird im Subscription-Topic des verbundenen MQTT Clients verwendet, nicht im Modul selbst.

Zugangsdaten, Client ID, KeepAlive und Subscription werden in der verbundenen MQTT-Client-Instanz konfiguriert.

## Verbindung testen

1. Client Socket speichern und aktivieren.
2. MQTT Client speichern und mit dem Client Socket verbinden.
3. Im Debug des MQTT Clients auf erfolgreiche Verbindung und empfangene Nachrichten achten.
4. `Bambu Connect` per `Gateway aendern` mit dem MQTT Client verbinden.
5. Sobald MQTT-Daten eintreffen, aktualisieren sich Kachel und Variablen.

Wenn der MQTT Client keine Daten empfaengt, sind meist Access Code, lokaler Zugriff, Client Socket oder Subscription-Topic zu pruefen.

Wenn der MQTT Client Daten empfaengt, aber `Bambu Connect` nicht aktualisiert, ist meist die Gateway-Verknuepfung zwischen `Bambu Connect` und dem MQTT Client zu pruefen.

Wenn fuer laengere Zeit kein gueltiger Statuspayload in `Bambu Connect` ankommt, zeigt die Kachel oben rechts `Offline`. Sobald wieder gueltige Daten eintreffen, wird der normale Druckerstatus wieder angezeigt.

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

Version 2.0 stellt die MQTT-Anbindung auf den IP-Symcon `MQTT Client` Splitter um. Dadurch werden MQTT-Login, KeepAlive und Subscription in den dafuer vorgesehenen IP-Symcon-Instanzen konfiguriert, waehrend `Bambu Connect` nur noch die empfangenen Topic-/Payload-Daten auswertet. Diese Anpassung ist die Basis fuer eine store-konformere Modulstruktur.

Der Drucker kann die TCP-Verbindung gelegentlich schliessen. In diesem Fall kann der IP-Symcon Client Socket Meldungen wie `Fehler beim Lesen: End of file` ausgeben. Die Wiederverbindung liegt bei Client Socket und MQTT Client.

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
