# Konfiguration

Reiter: **Drucker · MQTT · Einbindung in Loxone · Test · Logdateien**. Änderungen wirken ohne Neustart des Dienstes. Jeder Reiter speichert nur seine eigenen Einstellungen.

## Allgemein (Reiter Drucker)

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Voller Zustand alle … Sekunden anfordern | 300 s | 60–3600 s. Nur für Modelle, die ihren Zustand in Teilen melden (P1P, P1S, A1, A1 mini). X1, X1C, X1E und H2 senden bei jedem Report den vollen Zustand; bei ihnen wird der volle Zustand nur beim Verbinden angefordert |
| HMS-Meldungen als Text senden | an | `fehler/hms_text` mit den Codes (`HMS_xxxx_xxxx_xxxx_xxxx`) |
| HMS-Klartext aus der Textliste von Bambu laden | aus | Zusätzlich `fehler/hms_klartext`, `fehler/hms_code` und `fehler/text` (siehe [MQTT-Topics](MQTT-Topics.md)). Braucht **Internet** auf dem LoxBerry: Das Plugin lädt die öffentliche Textliste von Bambu (`e.bambulab.com`, kein Konto, keine Zugangsdaten; gesendet wird nur der Modellcode aus den ersten drei Zeichen der Seriennummer), beim ersten Start und danach einmal je Woche, und merkt sie sich im Datenordner. Fällt das Internet aus, bleibt die gespeicherte Liste in Gebrauch (neuer Versuch frühestens nach einer Stunde, dann in wachsendem Abstand). Die Texte liegen nicht im Plugin |
| Sprache des Klartextes | Deutsch | Deutsch oder Englisch (jede Sprache hat ihre eigene gespeicherte Liste) |

## Drucker

Bis zu acht Einträge. Jeder Eintrag braucht Host/IP, Seriennummer und Access Code.

| Spalte | Beschreibung |
|---|---|
| Status | Kästchen: grün = verbunden (mit Modell und Zustand), grau = nicht erreichbar (vermutlich ausgeschaltet, kein Fehler) oder nicht aktiv, rot = Fehler (Text daneben) |
| Aktiv | Nur aktive Einträge werden abgefragt |
| Name im Topic | `a-z 0-9 _ -`; leer = `drucker1`, `drucker2` … (Nummer der Zeile). Doppelte Namen bekommen `_2` |
| Host/IP, Port | Adresse des Druckers im Heimnetz, MQTT-Port (Standard 8883) |
| Seriennummer | Steht am Drucker unter Einstellungen → Netzwerk (und auf dem Gerät). Sie steckt im MQTT-Topic des Druckers, wird aber nicht an Loxone gesendet |
| Access Code | Steht am Drucker unter Einstellungen → WLAN/Netzwerk (bei manchen Modellen erst mit LAN-Modus). Wird nie angezeigt; ein leeres Feld behält den gespeicherten Code |
| Befehle freigeben | Drei Stufen (siehe unten), ab Werk alle aus |

### Befehle freigeben

Befehle aus Loxone (siehe [Loxone-Anbindung](Loxone-Anbindung.md)) sind ab Werk gesperrt. Je Drucker gibt es drei Stufen:

| Stufe | Befehle | Hinweis |
|---|---|---|
| Licht | Kammerlicht, zweites Kammerlicht (nur H2), Arbeitslicht | gehen auch ohne Entwicklermodus |
| Pause, Fortsetzen, Geschwindigkeit | `pause`, `fortsetzen`, `geschwindigkeit` (1–4) | greift in den laufenden Druck ein; braucht den **Entwicklermodus** am Drucker |
| Stopp | `stopp` | bricht den Druck ab; braucht den **Entwicklermodus** am Drucker |

Nie vorgesehen sind rohes G-Code, Druckaufträge starten, Firmware-Befehle und Kalibrierung.

## Erwartete Zustände und Fehler

| Zustand | `verbunden` | `status/ok` |
|---|---|---|
| Drucker verbunden, Reports kommen | 1 | 1 |
| Drucker aus oder nicht im Netz, **kein Druck lief** | 0 | **1** (kein Fehler) |
| Verbindung weg, **während ein Druck lief** (Vorbereitung, Druck, Pause) | 0 | 0 |
| Access Code falsch (Anmeldung abgelehnt) | 0 | 0 |
| Verbunden, aber der Drucker antwortet nicht | – | 0 |

Fällt ein Drucker aus, laufen die anderen weiter. `status/zaehler` läuft immer weiter.

## MQTT-Broker (Reiter MQTT)

Das **Gateway-Abo** steht als farbiges Feld oben im Reiter: **grün**, sobald mindestens ein passendes Abo im MQTT Gateway eingetragen ist (ist nur ein Teil der Topics abonniert, steht ein Hinweis dabei), **rot** nur, wenn keines eingetragen ist. Die eingetragenen Abos werden darunter aufgelistet.

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Eigenen MQTT-Broker verwenden | aus | Aus: Broker des LoxBerry (Zugangsdaten verwaltet LoxBerry) |
| Host/IP, Port | – / 1883 | Eigener Broker (ohne TLS) |
| Benutzer, Passwort | leer | Optional; das Passwort wird nie angezeigt oder geloggt |

Das ist **nicht** der Broker des Druckers: die Verbindung zum Drucker richtet sich nach dem Reiter „Drucker“. Mit eigenem Broker erreichen die Werte den Miniserver nur, wenn das MQTT-Gateway diesen Broker abonniert.

## Test

| Aktion | Wirkung |
|---|---|
| Sofort abrufen | Verbindet sich mit jedem Drucker, holt den Zustand und zeigt alle Topics und Werte, die das Plugin veröffentlichen würde |
| Selbstprüfung | Konfiguration, TLS und Anmeldung je Drucker (mit Modell, Firmware und Zustand), Broker, Gateway-Abo, Dienst |
| Dienst neu starten | Beendet und startet den Dienst |

Der Loglevel wird in der LoxBerry-Plugin-Verwaltung eingestellt.
