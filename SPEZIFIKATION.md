# Bambulab2Lox – Spezifikation

Stand: 2026-11-10 · Status: **Beta 0.1.1** (Entwurf umgesetzt, am H2C geprüft). Abschnitt 9 enthält die Befunde der Messungen am H2C, Abschnitt 11 die Umsetzung und die Abweichungen vom ursprünglichen Entwurf.
Ersetzt den ioBroker-Adapter `bambulab` (DrozmotiX/ioBroker.bambulab, MIT-Lizenz; dient nur als Vorlage für die Datenfelder, Code wird nicht übernommen).
Es gelten die Regeln aus `../CLAUDE.md` und `../README.md`.

## 1. Ziel

Das Plugin verbindet den LoxBerry mit einem oder mehreren **Bambu-Lab-Druckern** im Heimnetz, liest den Zustand und veröffentlicht ihn per MQTT. Über das MQTT-Gateway landet er als virtuelle Eingänge im Miniserver. Zusätzlich nimmt das Plugin **Befehle** aus Loxone an (Licht, Pause, Fortsetzen, Geschwindigkeit, Stopp). Befehle sind ab Werk gesperrt und werden quittiert.

Unterstützt werden **alle Modelle**, soweit sie das lokale MQTT-Protokoll sprechen: X1/X1C/X1E, P1P/P1S, A1/A1 mini, H2D/H2C/H2S. Eigentümer testet am **H2C**. Die übrigen Modelle sind nach Dokumentation umgesetzt und ungeprüft (das steht in jeder Beschreibung und im Changelog).

Nicht Teil des Plugins: Kamerabild (RTSP/Tutk), Dateiübertragung (FTPS), Druckaufträge starten, rohes G-Code senden, Bambu-Cloud.

## 2. Verbindung zum Drucker

| Punkt | Wert |
|---|---|
| Broker | im Drucker: `mqtts://<Drucker-IP>:8883` (TLS, Zertifikat von Bambu, selbstsigniert) |
| Zugang | Benutzer `bblp`, Passwort = **Access Code** (am Display unter Einstellungen → WLAN/Netzwerk; am H2C ist dafür kein LAN-Modus nötig, bei anderen Modellen eventuell) |
| Lesen | Topic `device/<Seriennummer>/report` |
| Schreiben | Topic `device/<Seriennummer>/request`, JSON `{"<Typ>": {"sequence_id": "<n>", "command": "…"}}` |
| Vollbild | `{"pushing":{"sequence_id":"0","command":"pushall","version":1,"push_target":1}}` |
| Version | `{"info":{"sequence_id":"0","command":"get_version"}}` (Modell, Firmware, AMS-Module) |

Verhalten je Modellreihe (laut OpenBambuAPI): X1 und H2 senden bei jedem Report das **volle Objekt**, P1 und A1 senden **nur geänderte Werte**. Das Plugin hält je Drucker einen zusammengeführten Zustand (Deep Merge, AMS-Einheiten und Slots über ihre `id`) und veröffentlicht nur Werte, die sich geändert haben (zur Auffrischung alle 5 Minuten alle). `pushall` wird beim Verbinden gesendet; danach bei P1/A1 im einstellbaren Abstand (Standard 5 Minuten, laut Quelle bei P1P nicht kürzer), bei X1/H2 nicht.

**TLS:** PHP verbindet per `ssl://` (Stream-Kontext). Zertifikatsprüfung ist standardmäßig **aus** (selbstsigniert, Gerät im Heimnetz), das steht in der Oberfläche als Hinweis. Optional später: Pinning des Bambu-Zertifikats. Der gemeinsame `MqttClient` (`../common/php`) bekommt dafür eine **abwärtskompatible** Option `tls` (Standard aus); die anderen Plugins ändern sich nicht.

**Zugang und Firmware (am H2C geprüft, 2026-11-10):**
- **LAN-Modus:** Am H2C geht das Auslesen auch ohne LAN-Modus (Rico, mit dem Plugin). Ob andere Modelle ihn brauchen, ist ungeprüft. Mit LAN-Modus ist die Cloud-Anbindung des Druckers aus.
- Seit der *Authorization Control* (X1 ab 03/2025, P1/A1 ab 06/2025) ist das **Steuern** per LAN nur im **Entwicklermodus** möglich (Einstellungen → Netzwerk → Nur LAN-Modus → Entwicklermodus). **Lesen geht ohne Entwicklermodus** (am H2C geprüft, 2026-11-10: nur LAN-Modus an, Anmeldung, `get_version`, `pushall` und der laufende Report funktionieren). **Druckbefehle** (`print.*`) lehnt der H2C ohne Entwicklermodus ab (siehe Abschnitt 9); die Oberfläche zeigt einen Hinweis, wenn der Drucker Befehle ablehnt.
- Mindestens vier MQTT-Clients gleichzeitig gehen am H2C (Bambu Studio zweimal, ioBroker-Adapter, Test-Skript, siehe Abschnitt 9). Das Plugin verbindet sich je Drucker mit **einer** Verbindung und baut sie bei Abbruch mit steigender Wartezeit neu auf.

## 3. Konfiguration

Bis zu 8 Drucker. Je Drucker:

| Feld | Beschreibung |
|---|---|
| Aktiv | Nur aktive Drucker werden abgefragt |
| Name im Topic | `a-z 0-9 _ -`, leer = `drucker1`, … Doppelte Namen bekommen `_2` |
| IP/Host | Adresse im Heimnetz |
| Seriennummer | Pflicht (steckt im Topic) |
| Access Code | Wird nie angezeigt (Passwortfeld, leer = unverändert), nie geloggt, nie per MQTT gesendet, Datei mit Rechten 0600 |
| Befehle freigeben | Stufen siehe 5; ab Werk **aus** |

Allgemein: Abstand für `pushall` (P1/A1), Option „HMS-Meldungen als Text“, MQTT-Broker des LoxBerry oder eigener Broker (wie bei den anderen Plugins). Das Modell erkennt das Plugin aus `get_version`/Seriennummer und zeigt es in der Oberfläche an.

## 4. MQTT-Topics (Lesen)

Schema `bambulab/<name>/<wert>`, Zahlen mit Punkt, Zustände 0/1, nicht retained, nur reine Werte. Felder, die das Modell nicht liefert, werden nicht gesendet. (Vorläufig, wird am H2C geprüft.)

| Topic | Einheit | Quelle (`print.…`) |
|---|---|---|
| `verbunden` | 0/1 | MQTT-Verbindung steht und Report kommt |
| `zustand/leerlauf`, `druckt`, `pausiert`, `fertig`, `abgebrochen`, `fehler` | 0/1 | aus `gcode_state` (IDLE, RUNNING, PAUSE, FINISH, FAILED): `abgebrochen` = FAILED mit `print_error` 0, `fehler` = FAILED mit `print_error` ≠ 0; zwischen Auftrag und Start gibt es kurz `PREPARE` (zählt als `druckt`) |
| `status_text` | Text | `gcode_state` als deutscher Klartext: bereit, bereitet vor, druckt, pausiert, fertig, abgebrochen (FAILED ohne Fehlercode), Fehler (FAILED mit Fehlercode); der Rohwert steht in `status_roh` |
| `fortschritt_prozent` | % | `mc_percent` |
| `restzeit_min` | min | `mc_remaining_time` |
| `schicht`, `schichten_gesamt` | – | `layer_num`, `total_layer_num` |
| `auftrag` | Text | `subtask_name` (sonst `gcode_file`) |
| `duese_c`, `duese_soll_c` | °C | `nozzle_temper`, `nozzle_target_temper` |
| `duese/<k>/temperatur_c`, `soll_c` | °C | nur H2: `device.extruder.info[k].temp` ist **gepackt**: untere 16 Bit = Ist, obere 16 Bit = Soll (z. B. `3276848` = 48 °C Ist, 50 °C Soll) |
| `duese/aktiv` | 0/1/−1 | nur H2: `(device.extruder.state >> 4) & 0xF` = Index der aktiven Düse, `15` (0xF) = Düse wird gewechselt (→ −1) |
| `duese/anzahl` | – | nur H2: `device.extruder.state & 0xF` (2) |
| `duesenwechsel` | 0/1 | nur H2C: 1 solange `device.holder.stat` ≠ 0 (Düsenhalter tauscht eine Düse am Werkzeugkopf) |
| `duesen/<id>/typ`, `durchmesser_mm`, `verschleiss` | Text, mm, – | nur H2C: `device.nozzle.info[]` (Düsenhalter mit mehreren Düsen: `type`, `diameter`, `wear`, `fila_id`) |
| `bett_c`, `bett_soll_c` | °C | `bed_temper`, `bed_target_temper` |
| `kammer_c` | °C | `chamber_temper` (X1/P1) bzw. `device.ctc.info.temp` (H2, dort fehlt `chamber_temper`) |
| `luefter/bauteil_prozent`, `hilfs_`, `kammer_`, `duesenkuehlung_` | % | `cooling_fan_speed`, `big_fan1_speed`, `big_fan2_speed`, `heatbreak_fan_speed` (Bambu meldet 0–15, wird in % umgerechnet) |
| `geschwindigkeit/stufe`, `prozent` | 1–4, % | `spd_lvl` (1 leise, 2 standard, 3 sport, 4 irrsinn), `spd_mag` |
| `licht/kammer`, `licht/arbeit`, `licht/kammer2` | 0/1 | `lights_report[]` (`chamber_light`, `work_light`, am H2C zusätzlich `chamber_light2`; Modus `on`/`off`/`flashing`) |
| `wlan_dbm` | dBm | `wifi_signal` |
| `fehler/code` | – | `print_error` / `mc_print_error_code` |
| `fehler/hms_anzahl`, `fehler/hms_text` | –, Text | `hms[]` (Zahl und Codes `HMS_xxxx_xxxx_xxxx_xxxx`) |
| `fehler/hms_klartext`, `fehler/hms_code`, `fehler/text` | Text | nur mit der Option **HMS-Klartext** (ab Werk aus): Klartext der Meldungen (mit ` \| ` getrennt, höchstens 1000 Zeichen), Code der ersten Meldung, Text zum `print_error`. Quelle: öffentliche Textliste von Bambu (`https://e.bambulab.com/query.php?lang=<de\|en>&d=<erste 3 Zeichen der Seriennummer>`, JSON `data.device_hms.<lang>[]` und `data.device_error.<lang>[]` mit `ecode` und `intro`); Zuordnung `attr`+`code` → `%08X%08X`, `print_error` → `%08X` |
| `ams/<n>/temperatur_c`, `feuchte`, `slot/<k>/typ`, `farbe`, `rest_prozent` | | `ams.ams[].temp/humidity`, `tray[].tray_type/tray_color/remain` |
| `ams/aktiv_slot` | – | `ams.tray_now` (254 = externe Spule, 255 = keiner, sonst `AMS×4 + Slot`) |
| `extern/typ`, `farbe` | Text | `vt_tray` (externe Spule, X1/P1) |
| `extern/<k>/typ`, `farbe` | Text | `vir_slot[]` (H2: externe Spulen mit `id` 254 und 255) |
| `info/modell`, `firmware`, `duesen_durchmesser_mm`, `duesen_typ` | Text/mm | `get_version`, `nozzle_diameter`, `nozzle_type` (die Seriennummer wird bewusst nicht gesendet, siehe 11) |

Status des Plugins (wie bei allen): `bambulab/status/ok` (1 = alle aktiven Drucker liefern Werte oder sind erwartet aus), `bambulab/status/zaehler` (Lebenszeichen), `bambulab/status/ts`.

**Erwartete Zustände sind kein Fehler:** Ein Drucker, der **ausgeschaltet** ist (nicht erreichbar), meldet `verbunden` = 0, aber `status/ok` bleibt 1, wenn der Drucker nach dem Plugin-Zustand „aus“ ist (z. B. kein Report seit Minuten und Ping fehlgeschlagen). Falscher Access Code oder abgelehnte Anmeldung ist ein **Fehler** (`status/ok` = 0, Text im Reiter MQTT). Das Verhalten wird mit Rico abgestimmt (analog zum Nachtbetrieb bei Fronius2Lox).

## 5. Befehle (Schreiben)

Eingang: `bambulab/befehl/<name>/<befehl>` mit dem Wert 1 (bzw. Zahl), Quittung unter `…/quittung` (`angenommen`, `ausgefuehrt`, `abgelehnt: <Grund>`), wie bei Nut2Lox. Ohne Freigabe antwortet das Plugin `abgelehnt: gesperrt`.

| Stufe | Befehl | Topic (`<befehl>`) | Drucker-Befehl |
|---|---|---|---|
| 1 Licht | Kammerlicht an/aus | `licht_kammer` (1/0) | `system.ledctrl` (`chamber_light`) |
| 1 Licht | Arbeitslicht an/aus | `licht_arbeit` (1/0) | `system.ledctrl` (`work_light`) |
| 2 Druck | Pause | `pause` | `print.pause` (nur mit Entwicklermodus) |
| 2 Druck | Fortsetzen | `fortsetzen` | `print.resume` (nur mit Entwicklermodus) |
| 2 Druck | Geschwindigkeit | `geschwindigkeit` (1–4) | `print.print_speed` (nur mit Entwicklermodus) |
| 3 Stopp | Druck abbrechen | `stopp` | `print.stop` (nur wenn `gcode_state` RUNNING oder PAUSE; nur mit Entwicklermodus) |

Regeln:
- Jede Stufe ist je Drucker einzeln freizugeben, ab Werk alle **aus**.
- Befehle gelten nur, wenn der Drucker verbunden ist, und höchstens einmal je Sekunde je Drucker (Sperre gegen Wiederholung).
- **Nie** vorgesehen: rohes G-Code, Druckauftrag starten, Firmware-Befehle (`upgrade.*`), Zugangscode lesen oder ändern, Kalibrierung.
- Eingang laut Gateway-Befehlsformat (UDP, einfache Form `publish <topic> <wert>`), ohne Leerzeichen in Topics. Der UDP-Port kommt aus der Konfiguration des Gateways (Standard 11884).
- Quittung auch bei Ablehnung durch den Drucker (`result` aus dem Report) mit dessen Grund. Meldet der Drucker `mqtt message verify failed`, lautet die Quittung `abgelehnt: Drucker verlangt den Entwicklermodus` (Stufen 2 und 3); die Oberfläche weist darauf hin, und die Doku nennt, dass der Entwicklermodus die Bambu-Cloud abschaltet.

## 6. Oberfläche (LoxBerry Design System)

Reiter: **Drucker · MQTT · Einbindung in Loxone · Test · Logdateien**, Menüleiste über `$navbar`.
- Drucker: Liste mit Erreichbarkeits-Kästchen (grün/rot/grau), Modell, Firmware, Stufen der Befehlsfreigabe.
- MQTT: Broker, Gateway-Abo-Feld (grün ab einem passenden Abo, rot nur ohne Abo; V2 braucht das Abo von Hand).
- Einbindung in Loxone: Tabelle der Topics, Eingänge und fertigen Befehle (Adresse, Befehl, Quittung).
- Test: *Sofort abrufen* (zeigt alle Topics und Werte), *Selbstprüfung* (Konfiguration, TLS/Anmeldung, Broker, Gateway-Abo, Dienst), *Dienst neu starten*.
- Hinweis zu Entwicklermodus, LAN-Modus und selbstsigniertem Zertifikat.

## 7. Struktur und Technik

Wie Fronius2Lox/Nut2Lox: eigenes Repo `bambulab/` mit `plugin.cfg` (Name `bambulab2lox`, Ordner `bambulab2lox`, `AUTOMATIC_UPDATES=true`, `RELEASECFG`, `WEBSITE`, `CUSTOM_LOGLEVELS=true`), `bin/` (Dienst `bambulabd.php`, Healthcheck, Bibliotheken), `daemon/`, `cron/` (Watchdog), `webfrontend/htmlauth/`, `build.sh` (mit `release-cfg` und ZIP ohne Version), `tests/`. Gemeinsamer Code kommt aus `../common/php` (Util, Logger, MqttClient, GatewayInfo); der Bambu-spezifische Teil (TLS-MQTT zum Drucker, Zustand, Mapper, Befehle) liegt im Plugin.
- Zeitzone `Europe/Berlin`, Sprache Deutsch, keine Zugangsdaten im Log, Konfiguration atomar mit Rechten 0600, übersteht Updates.
- Healthcheck `bin/healthcheck` (nur lesend, schnell). Logo und Icons: `tools/make_logo.py` erzeugt sie im Stil der anderen Plugins; Änderungen nur auf Wunsch von Rico.

## 8. Tests

- **Fake-Drucker** (`tests/fake_bambu.php`): MQTT-Broker mit TLS auf localhost, der `report`/`request` wie ein X1 (Vollbild) und wie ein P1 (nur Änderungen, `pushall`) nachbildet, mit Beispielnachrichten aus OpenBambuAPI. Prüft: Deep Merge, Topic-Mapping, nur Änderungen senden, Wiederverbinden, falscher Access Code, Befehle (gesperrt, Stufen, Quittung, Sperre je Sekunde).
- Oberfläche mit Attrappen der LoxBerry-Bibliotheken (wie bei den anderen Plugins).
- **Nicht lokal prüfbar:** echte Druckerantworten, Zertifikat, Firmware-Verhalten; das geschieht am H2C (Test-Skript liest Zugangsdaten aus einer lokalen Datei, nie aus dem Repo).

## 9. Befunde am H2C und offene Punkte

**Messung am 2026-11-10** (nur lesen: Anmeldung, `get_version`, `pushall`, 30 s mitgelesen; Entwicklermodus war am Drucker **an**):
- TLS 1.3 auf Port 8883, Anmeldung mit `bblp` + Access Code klappt, selbstsigniertes Zertifikat (Prüfung aus).
- Produkt laut `get_version`: **Bambu Lab H2C**, Firmware `01.03.00.00`, dazu AMS (1) und AMS 2 Pro (2) und weitere Module (`ahb`, `th`, `ext`, `smc`, `wtm` …).
- Der H2C sendet **bei jedem Report das volle Objekt** (101 Schlüssel, etwa ein Report je Sekunde, 27 in 30 s). Ein Deep Merge ist also nicht nötig, das Veröffentlichen **nur bei Änderung** aber sehr wohl.
- H2-Besonderheiten im Report: `device.extruder.info[]` mit zwei Düsen, `device.nozzle.info[]` (Düsenhalter mit mehreren Düsen), `device.ctc` und Top-Level `info.temp` für die Kammertemperatur (`chamber_temper` fehlt), `device.bed`, drei Lichter in `lights_report`, zwei externe Spulen in `vir_slot`, `hms[]` mit `attr` und `code`, `ams.ams[]` mit `dry_setting` (Trocknung), `humidity` und `temp` (AMS lite meldet `-50.0` = ungültig, wird nicht gesendet).
- Status am Messzeitpunkt: `gcode_state` FINISH, 100 %, Düse 38 °C, Bett 34 °C, Kammer 30 °C, WLAN −62 dBm.
- Der Auftragsname (`subtask_name`) ist UTF-8 mit Umlauten; Topics und Werte müssen UTF-8 sauber durchreichen.
- Die HMS-Codes sind Zahlen (`attr` + `code`); der Klartext steht bei Bambu online (HMS-Datenbank), nicht im Drucker. Am H2C hatten bei einer Abfrage mit dem Modellcode `31B` zwei der fünf Meldungen einen deutschen Text (ohne Modellcode nur eine); die übrigen stehen nicht in der Datenbank. Umsetzung siehe Abschnitt 11.

**Aufnahme eines Drucks mit Düsenwechseln (2026-11-10, nur lesen, erste Minuten):** Die Auswertung der Änderungen (jede Wertänderung mit Zeitstempel) zeigt, ohne offizielle Doku, diese Deutung. Sie ist mit den Zeitpunkten der Wechsel abgeglichen, aber noch **zu bestätigen**:
- `device.extruder.state`: untere 4 Bit = Zahl der Düsen (2), Bits 4–7 = **aktive Düse** (0 oder 1), `0xF` während des Umschaltens. Der Umschaltzeitpunkt deckt sich mit der Aktivität in `device.extruder.info[1]` und mit der Schicht-Phase (`stg_cur`).
- `device.extruder.info[k].temp` ist gepackt (Ist unten, Soll oben, je 16 Bit). Sonst steht dort bei Ruhe nur die Ist-Temperatur (z. B. 36). Das Top-Level-Feld `nozzle_temper` zeigt die Ist-Temperatur der gerade aktiven Düse.
- **Hotend-Wechsel am Düsenhalter:** `device.holder.stat` läuft 6 → 8 → 9 → 10 → 0, `device.holder.pos` wechselt dabei zwischen 1, 2 und 3, `device.nozzle.src_id`/`tar_id` zeigen die Kennung der Düse vor und nach dem Tausch (hier 19 → 16 → 19). Während des Wechsels ändern sich `device.nozzle.exist` (Bitmaske der belegten Plätze) und die `id`-Einträge in `device.nozzle.info[]`.
- `device.nozzle.info[]` hat 7 Einträge (Plätze) mit `id`, `type` (z. B. `HS01`), `diameter` (0,4 / 0,2), `fila_id` (zuletzt verwendetes Filament) und `wear`.
- Die Schritte eines Wechsels erscheinen in `stg_cur` (Stufenkennung des Druckers, z. B. 29, 13, 74, 72, 4, 8, 14).
- Der Druck startete mit `PREPARE` → `RUNNING`; `layer_num` sprang beim Start kurz auf den Wert des vorigen Drucks (460), bevor es 0 wurde. Das Plugin soll solche Ausreißer beim Start nicht an Loxone weitergeben (Wert erst nach `RUNNING` und Plausibilität `layer_num ≤ total_layer_num`).

**Befunde und Stand der Prüfungen:**
1. **Erledigt:** Lesen geht auch **ohne** Entwicklermodus (zweite Messung, 20 s, 19 Reports mit je 101 Schlüsseln, `get_version` beantwortet). **Befehlstest (2026-11-10, Entwicklermodus aus, LAN-Modus an, Bambu Studio offen):** `system.ledctrl` für `chamber_light` (aus, dann wieder an) wurde mit `result: success` quittiert, und `lights_report` folgte sofort (`off`, dann `on`). Licht geht also **ohne Entwicklermodus**. **Druckbefehle ohne Entwicklermodus (2026-11-10, laufender Druck, Schicht 3, ruhige Phase, Entwicklermodus aus, LAN-Modus an):** `print.print_speed` (Stufe 3 und zurück auf 2), `print.pause` und `print.resume` wurden vom Drucker **abgelehnt**: `result: failed`, `reason: mqtt message verify failed`. Der Druck lief unverändert weiter (`spd_lvl` blieb 2, `gcode_state` blieb RUNNING). Das ist die Authorization Control: **Befehle, die den Druck betreffen (`print.*`), brauchen den Entwicklermodus**, Licht (`system.ledctrl`) nicht. **Mit Entwicklermodus (2026-11-10, derselbe Druck, Schicht 3, ruhige Phase):** `print.print_speed` Stufe 3 → `result: SUCCESS`, `spd_lvl` 3, `spd_mag` 124 (Stufe 2 = 100); danach Stufe 2 → `SUCCESS`, `spd_lvl` 2. `print.pause` → `SUCCESS`, `gcode_state` PAUSE; `print.resume` → `SUCCESS`, `gcode_state` RUNNING. Der Druck lief danach weiter. Die Antworten schreiben `SUCCESS` groß (bei `ledctrl` klein), das Plugin vergleicht ohne Rücksicht auf Groß-/Kleinschreibung. **Stopp** (Rico gab den Druck zum Wegwerfen frei, Schicht 4, 42 %, Entwicklermodus an): `print.stop` → `result: SUCCESS`, `gcode_state` sofort `FAILED`, `print_error` 0, `fail_reason` 0, danach `mc_percent` 0. Ein vom Benutzer abgebrochener Druck steht also **wie ein fehlgeschlagener** auf `FAILED`; das Plugin kann beides nicht unterscheiden und nennt den Zustand `fehler` nur zusammen mit `print_error` ≠ 0, sonst `abgebrochen`. Stopp ohne Entwicklermodus ist nicht getestet (nach dem Muster der anderen `print.*`-Befehle abgelehnt).
2. **Gleichzeitige MQTT-Clients:** Ein zweiter Client neben Bambu Studio konnte sich anmelden und 27 Reports in 30 s lesen (Bambu Studio war nach Ricos Angabe offen). Bambu Studio blieb dabei live (Rico bestätigt). Später liefen **mindestens vier Clients gleichzeitig** ohne Probleme (Bambu Studio zweimal, der ioBroker-Adapter und die Test-Skripte). Die Höchstzahl ist für das Plugin nicht wichtig und wird nicht weiter getestet.
3. **Düsenwechsel:** `device.extruder.state`, `holder` und `nozzle.exist` wurden an einem Druck mit mehreren Düsenwechseln aufgezeichnet und ausgewertet (siehe oben). Die Deutung (aktive Düse, Düsenwechsel) deckt sich mit den Zeitpunkten, ist aber **nicht offiziell dokumentiert**. Welche Düse links und welche rechts ist, steht nicht in den Daten.
4. Befehle am H2C: `ledctrl` für `chamber_light` ist geprüft und geht ohne Entwicklermodus (siehe 1). Stufe 2 (Pause, Fortsetzen, Geschwindigkeit) wird ohne Entwicklermodus abgelehnt und geht mit ihm (geprüft). Alle drei Lichter sind geprüft (`chamber_light`, `chamber_light2`, `work_light`: je `result: success`, `lights_report` folgt sofort; das Arbeitslicht steht im Ruhezustand auf `flashing` und ließ sich auf `on` und wieder auf `flashing` stellen). Stopp ist mit Entwicklermodus geprüft (siehe 1). Keine Befehlstests mehr offen.
5. **Erledigt:** Verhalten von `status/ok`, wenn der Drucker ausgeschaltet ist: erwartet, kein Fehler, solange kein Druck lief (umgesetzt, siehe Abschnitt 11).
6. **Offen (nicht am echten Drucker geprüft):** Pause, Fortsetzen und Stopp **über das Plugin** (QoS 1; die erfolgreichen Befehle am H2C kamen von einem Testskript mit QoS 0), die Lüfterskala 0–15 in Prozent, die gepackte Kammertemperatur beim Aufheizen, alle anderen Modelle (X1, X1C, X1E, P1P, P1S, A1, A1 mini, H2D, H2S).

## 10. Vorgehen

1. Spezifikation abstimmen (dieses Dokument).
2. Am H2C lesen (Zugangsdatei, nur Lesen, `pushall` aufzeichnen) und Abschnitt 4 und 9 anpassen. **Erster Lesetest erledigt (2026-11-10)**, siehe Abschnitt 9.
3. Gerüst aus Fronius2Lox, Fake-Drucker, Lesen mit Tests; Oberfläche.
4. Befehle in den Stufen 1 bis 3, jeweils mit Test am H2C.
5. Doku (`docs/wiki`, LoxBerry-Wiki-Seite, Forenbeitrag), `0.1.0` erst nach Ricos Freigabe veröffentlichen.

## 11. Umsetzung 0.1.0 (Stand 2026-11-10)

Gebaut und gegen einen **Fake-Drucker** (MQTT mit TLS, anonymisierte Reports des H2C aus `tests/fixtures/`) getestet; 375 Prüfungen. Die Messungen und Befehlstests in Abschnitt 9 stammen von einem Python-Skript am echten H2C. **Das Plugin selbst** (PHP-Dienst) hat Rico am 2026-11-10 an seinem H2C installiert: Verbindung, Status und Zustand in der Oberfläche sowie der Abruf der HMS-Textliste (2692 HMS- und 973 Fehlertexte für den Modellcode `31B`) funktionieren. **Befehle über das Plugin** (insbesondere QoS 1 bei Pause, Fortsetzen und Stopp) sind am echten Drucker noch nicht geprüft.

Abweichungen und Ergänzungen gegenüber dem Entwurf:
- `info/seriennummer` wird **nicht** gesendet (die Seriennummer steht nur in der Konfiguration).
- Zusätzliche Topics: `ams/<n>/feuchte` (Stufe 1–5), `ams/<n>/feuchte_prozent` (nur wenn `humidity_raw` > 0, AMS 2 Pro), `ams/<n>/trocknung_min`, `ams/<n>/slot/<k>/belegt`, `duese/anzahl`, `zustand/abgebrochen`. Slot-Farbe als `RRGGBB` ohne Alpha.
- `kammer_c`: `chamber_temper`, sonst `device.ctc.info.temp` (gepackt, untere 16 Bit; am H2C im Ruhezustand ein kleiner Wert, die Packung ist beim Aufheizen der Kammer nicht beobachtet) bzw. `info.temp`.
- Lüfter: Skala 0–15 → Prozent (`round(v/15*100)`); die Beziehung zu `fan_gear` (gepackt) ist **nicht** geklärt.
- `schicht` wird nicht gesendet in `PREPARE` und wenn `layer_num > total_layer_num` (damit fällt der Ausreißer 460 beim Start weg).
- `pushall` bei Teil-Updates: Modelle, deren `product_name` P1 oder A1 enthält (aus `get_version`); bei X1 und H2 nur beim Verbinden. Vor der Antwort auf `get_version` gilt ein Modell als „voller Zustand“.
- Befehle: Pause nur bei `RUNNING`, Fortsetzen nur bei `PAUSE`, Stopp bei `RUNNING`/`PAUSE`. Pause, Fortsetzen und Stopp gehen mit **QoS 1** hinaus (laut OpenBambuAPI; die Tests am H2C liefen mit QoS 0 und waren erfolgreich, QoS 1 ist am echten Drucker **ungeprüft**; das PUBACK des Druckers wird nicht ausgewertet).
- Die Quittung `angenommen` kommt sofort, `ausgefuehrt` oder `abgelehnt: <Grund>` folgt mit der Antwort des Druckers (gleiche `sequence_id` und gleicher Befehl), nach 10 s ohne Antwort `abgelehnt: keine Antwort des Druckers`.
- **HMS-Klartext** (Option `hms_klartext`, ab Werk aus; `hms_sprache` de/en): `bin/lib/BambuHmsTexte.php` lädt `https://e.bambulab.com/query.php?lang=<de|en>&d=<Modellcode>` (HTTPS mit Zertifikatsprüfung, 10 s, höchstens 5 MB, kein Konto, keine Zugangsdaten, nur die ersten 3 Zeichen der Seriennummer), speichert die Liste im Datenordner (`hms_<Modell>_<Sprache>.json`, atomar), lädt höchstens einmal je Woche neu und arbeitet bei Ausfall mit dem Zwischenspeicher weiter (Wiederholung frühestens nach 1 Stunde, verdoppelt sich bis höchstens 1 Tag). Drucker gleichen Modellcodes teilen sich eine Liste. Die neuen Topics heißen `fehler/hms_klartext`, `fehler/hms_code`, `fehler/text`; **`fehler/hms_text` bleibt unverändert die Codeliste** (bestehende Topics nicht umbenannt). Ohne Text bleibt der Code stehen; ohne Meldung bzw. Fehlercode 0 steht `keine`. Die Oberfläche lädt nichts aus dem Internet, sie liest nur den Zwischenspeicher. Nur gegen einen Fake-Server (HTTP) getestet; der echte Abruf bei Bambu (HTTPS-Zertifikat, Antwortgröße von rund 650 KB, Texte zu den echten Codes) ist am LoxBerry mit Internet zu prüfen. Testzahl 0.1.0 jetzt 375.
- `status/ok`: ausgeschalteter Drucker ohne laufenden Druck = erwartet (`aus`, `status/ok` bleibt 1); falscher Access Code, Verbindungsverlust bei `RUNNING`/`PAUSE`/`PREPARE` und ein verbundener, aber schweigender Drucker (60 s) = Fehler.
- Der Dienst verbindet die Drucker nacheinander in einer Schleife (Verbindungs-Timeout 3 s, wachsender Abstand bis 60 s, bei falschem Access Code bis 120 s); viele gleichzeitig ausgeschaltete Drucker verlängern also die Schleife.
- Logo und Icons: `tools/make_logo.py` erzeugt `docs/logo/bambulab2lox.png` und `bambulab2lox/icons/icon_*.png` (der H2C schematisch nach Ricos Foto, ohne Herstellerlogo).
