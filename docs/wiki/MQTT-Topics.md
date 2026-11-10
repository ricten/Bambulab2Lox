# MQTT-Topics

Schema `bambulab/<name>/<wert>`, `<name>` = Name des Druckers aus den Einstellungen (Standard `drucker1`). Zahlen mit Punkt und ohne Einheit, Zustände 0/1, nicht retained. Werte, die ein Modell nicht liefert, werden nicht gesendet. Gesendet werden nur **Änderungen**, alle 5 Minuten zusätzlich alle Werte. Grundlage sind die Reports eines H2C und die inoffizielle Beschreibung [OpenBambuAPI](https://github.com/Doridian/OpenBambuAPI/blob/main/mqtt.md); Deutungen ohne offizielle Doku sind markiert.

## Status

| Topic | Einheit | Bedeutung |
|---|---|---|
| `verbunden` | 0/1 | Verbindung zum Drucker steht und Reports kommen an |
| `status_text` | Text | Zustand als deutscher Klartext: `bereit`, `bereitet vor`, `druckt`, `pausiert`, `fertig`, `abgebrochen` (FAILED ohne Fehlercode), `Fehler` (FAILED mit Fehlercode) |
| `zustand/leerlauf`, `druckt`, `pausiert`, `fertig`, `abgebrochen`, `fehler` | 0/1 | genau einer ist 1. `druckt` schließt die Vorbereitung ein. Ein vom Benutzer abgebrochener Druck steht wie ein fehlgeschlagener auf `FAILED`: ohne Fehlercode ist das `abgebrochen`, mit Fehlercode `fehler` |
| `status_roh` | Text | Zustand, wie ihn die Firmware meldet (IDLE, PREPARE, RUNNING, PAUSE, FINISH, FAILED) |
| `wlan_dbm` | dBm | WLAN-Signal des Druckers |

## Druck

| Topic | Einheit | Bedeutung |
|---|---|---|
| `fortschritt_prozent` | % | Fortschritt (`mc_percent`) |
| `restzeit_min` | min | Restzeit (`mc_remaining_time`) |
| `schicht`, `schichten_gesamt` | – | aktuelle und gesamte Schichtzahl. Beim Start des Drucks meldet der Drucker kurz die Schichtzahl des vorigen Drucks; solche Werte (Schicht größer als die Gesamtzahl, oder in der Vorbereitung) werden nicht gesendet |
| `auftrag` | Text | Name des Druckauftrags (sonst Dateiname) |
| `geschwindigkeit/stufe` | 1–4 | 1 leise, 2 standard, 3 sport, 4 irrsinn |
| `geschwindigkeit/prozent` | % | z. B. 100 (standard), 124 (sport) |

## Temperaturen und Lüfter

| Topic | Einheit | Bedeutung |
|---|---|---|
| `duese_c`, `duese_soll_c` | °C | Düse (die gerade aktive): Ist und Soll |
| `bett_c`, `bett_soll_c` | °C | Druckbett: Ist und Soll |
| `kammer_c` | °C | Kammer (X1: `chamber_temper`; H2: aus `device.ctc`) |
| `luefter/bauteil_prozent`, `hilfs_prozent`, `kammer_prozent`, `duesenkuehlung_prozent` | % | Bauteil-, Hilfs-, Kammerlüfter und Kühlung des Düsenkopfs. Der Drucker meldet 0–15 (15 = 100 %), das Plugin rechnet um (Annahme, am H2C nicht gegen eine Anzeige abgeglichen) |

## H2: Düsen

| Topic | Einheit | Bedeutung |
|---|---|---|
| `duese/<k>/temperatur_c`, `soll_c` | °C | je Düse (k = 0, 1). Der Drucker meldet den Wert gepackt: untere 16 Bit Ist, obere 16 Bit Soll (z. B. 3276848 = 48 °C Ist, 50 °C Soll) |
| `duese/aktiv` | 0/1/−1 | Index der aktiven Düse; −1 während des Umschaltens. Deutung aus der Aufnahme eines Drucks mit Düsenwechseln (`device.extruder.state`, Bits 4–7); welche Düse links und welche rechts ist, steht nicht in den Daten |
| `duese/anzahl` | – | Zahl der Düsen (`device.extruder.state`, untere 4 Bit) |
| `duesenwechsel` | 0/1 | H2C: 1, solange der Düsenhalter eine Düse tauscht (`device.holder.stat` ungleich 0) |
| `duesen/<id>/typ`, `durchmesser_mm`, `verschleiss` | Text, mm, – | H2C: Düsen im Halter. Leere Plätze fehlen |

## Licht

| Topic | Einheit | Bedeutung |
|---|---|---|
| `licht/kammer`, `licht/kammer2`, `licht/arbeit` | 0/1 | Kammerlicht, zweites Kammerlicht (H2), Arbeitslicht. Das Arbeitslicht steht am H2C im Ruhezustand auf „blinkend“ und zählt dann als 0 |

## Fehler und HMS

| Topic | Einheit | Bedeutung |
|---|---|---|
| `fehler/code` | – | Fehlercode (`print_error`), 0 = kein Fehler |
| `fehler/hms_anzahl` | – | Zahl der HMS-Meldungen |
| `fehler/hms_text` | Text | Codes kommagetrennt (`HMS_xxxx_xxxx_xxxx_xxxx`), `keine` ohne Meldung; abschaltbar. Den Klartext zu einem Code gibt es bei Bambu online (HMS-Datenbank) |

## AMS und externe Spulen

| Topic | Einheit | Bedeutung |
|---|---|---|
| `ams/<n>/temperatur_c` | °C | Temperatur der AMS-Einheit (der ungültige Wert −50 des AMS lite wird nicht gesendet) |
| `ams/<n>/feuchte` | 1–5 | Feuchtestufe |
| `ams/<n>/feuchte_prozent` | % | Feuchte in Prozent (nur AMS 2 Pro, wenn gemeldet) |
| `ams/<n>/trocknung_min` | min | verbleibende Trocknungszeit |
| `ams/<n>/slot/<k>/belegt` | 0/1 | Slot ist belegt |
| `ams/<n>/slot/<k>/typ`, `farbe`, `rest_prozent` | Text, `RRGGBB`, % | Filamenttyp, Farbe (ohne Alpha), Restmenge (unbekannt = nicht gesendet) |
| `ams/aktiv_slot` | – | aktiver Slot: 254 oder 255 = externe Spule, 255 auch „keiner“, sonst AMS-Nummer × 4 + Slot |
| `extern/<k>/typ`, `farbe` | Text | H2: externe Spulen (k = 0, 1); X1/P1: `extern/typ` und `extern/farbe` |

## Info

| Topic | Einheit | Bedeutung |
|---|---|---|
| `info/modell`, `info/firmware` | Text | aus der Antwort auf `get_version` (z. B. `Bambu Lab H2C`, `01.03.00.00`) |
| `info/duesen_durchmesser_mm`, `info/duesen_typ` | mm, Text | Düse laut Drucker (z. B. `0.4`, `HS01-0.4`) |

Die Seriennummer des Druckers wird nicht gesendet.

## Status des Plugins

| Topic | Bedeutung |
|---|---|
| `bambulab/status/ok` | 1 = alle Drucker in Ordnung (ein ausgeschalteter Drucker ohne laufenden Druck ist kein Fehler); der Broker setzt 0, wenn der Dienst abbricht |
| `bambulab/status/zaehler` | Lebenszeichen 0–999, zählt alle 10 s |
| `bambulab/status/ts` | Unix-Zeitstempel des letzten Lebenszeichens |

## Befehle (Eingang) und Quittung

| Topic | Wert | Wirkung |
|---|---|---|
| `bambulab/befehl/<name>/licht_kammer` | 1 / 0 | Kammerlicht an / aus |
| `bambulab/befehl/<name>/licht_kammer2` | 1 / 0 | zweites Kammerlicht an / aus (nur H2) |
| `bambulab/befehl/<name>/licht_arbeit` | 1 / 0 | Arbeitslicht an / Ruhezustand (blinkend) |
| `bambulab/befehl/<name>/pause` | 1 | Druck pausieren (nur während des Drucks) |
| `bambulab/befehl/<name>/fortsetzen` | 1 | pausierten Druck fortsetzen |
| `bambulab/befehl/<name>/geschwindigkeit` | 1–4 | Geschwindigkeit setzen |
| `bambulab/befehl/<name>/stopp` | 1 | Druck abbrechen (während des Drucks oder in der Pause) |
| `bambulab/befehl/<name>/<befehl>/quittung` | Text | `angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>` (nie retained) |

Mögliche Gründe: „Befehle sind gesperrt (Stufe … freigeben)“, „Befehl nicht erlaubt“, „ungültiger Wert“, „unbekanntes Geraet“, „Drucker nicht verbunden“, „zu schnell (höchstens ein Befehl je Sekunde)“, „es läuft kein Druck“, „der Druck ist nicht pausiert“, „Drucker verlangt den Entwicklermodus“, „keine Antwort des Druckers“.
