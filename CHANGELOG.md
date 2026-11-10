# Changelog

Alle nennenswerten Änderungen an Bambulab2Lox. Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/) (`MAJOR.MINOR.PATCH`).

Release-Ablauf: Version in `bambulab2lox/plugin.cfg` und `bambulab2lox/bin/lib/AppVersion.php` erhöhen, hier einen Eintrag ergänzen,
`bash build.sh` ausführen (prüft die Übereinstimmung), danach Git-Tag `vX.Y.Z` setzen (Einzelheiten und `release.cfg`: `CLAUDE.md`).

## [Unveröffentlicht]

## [0.1.0] – 2026-11-10

Erste Version (lokal gebaut, noch nicht veröffentlicht). Ersetzt den ioBroker-Adapter `bambulab`. Am echten Drucker wurde nur ein **H2C** geprüft
(Lesen, Lichter, Geschwindigkeit, Pause, Fortsetzen, Stopp); X1, P1, A1 und die anderen H2-Modelle sind nach der inoffiziellen Beschreibung des
Druckerprotokolls (OpenBambuAPI) umgesetzt und ungeprüft.

### Neu
- **Status als Klartext:** `status_text` und die Oberfläche zeigen bereit, bereitet vor, druckt, pausiert, fertig, abgebrochen oder Fehler statt IDLE/RUNNING/FAILED. Der Rohwert der Firmware steht zusätzlich in `status_roh`.
- **Hinweis zum Entwicklermodus:** Lehnt der Drucker Pause, Fortsetzen, Geschwindigkeit oder Stopp ab (`mqtt message verify failed`), zeigt die Oberfläche in der Statuszeile des Druckers, dass ohne Entwicklermodus nur das Licht schaltbar ist. Der Hinweis verschwindet, sobald ein Druckbefehl wieder durchgeht.
- **Auslesen ohne LAN- und Entwicklermodus** (am H2C beobachtet): Doku und Oberfläche sagen das jetzt so; bei anderen Modellen kann der LAN-Modus nötig sein.
- Die harmlose Meldung der LoxBerry-Logbibliothek „log_db_queryid: Could not find database entry“ beim allerersten Start blendet der Reiter Logdateien aus.
- **Verbindung zum Drucker:** lokaler MQTT-Broker des Druckers (TLS, Port 8883, Benutzer `bblp`, Access Code), mehrere Drucker (bis 8), Wiederverbinden mit wachsendem Abstand. Das selbst signierte Zertifikat des Druckers wird nicht geprüft. Dafür bekommt der gemeinsame `MqttClient` eine abwärtskompatible TLS-Option (Standard aus).
- **Lesen:** Zustand (`gcode_state` als Text und als Schalter inklusive `abgebrochen` und `fehler`), Fortschritt, Restzeit, Schicht, Auftrag, Temperaturen, Lüfter, Geschwindigkeit, Lichter, WLAN, Fehlercode und HMS-Meldungen, AMS (Temperatur, Feuchte, Slots mit Typ, Farbe, Restmenge), externe Spulen, Modell und Firmware. H2: zwei Düsen (gepackte Temperaturen, aktive Düse, Düsenwechsel) und die Düsen im Halter.
- Der Zustand wird zusammengeführt (X1 und H2 senden volle Reports, P1 und A1 nur Änderungen); gesendet werden nur **geänderte Werte** (alle 5 Minuten alle). Bei P1 und A1 fordert das Plugin den vollen Zustand im einstellbaren Abstand neu an.
- Plausibilität: Die kurzzeitig falsche Schichtzahl beim Druckstart (Wert des vorigen Drucks) und ungültige AMS-Werte (−50 °C, Restmenge −1) werden nicht gesendet.
- **Befehle aus Loxone** (`bambulab/befehl/<name>/<befehl>`, Quittung unter `…/quittung`), ab Werk gesperrt, je Drucker in drei Stufen freizugeben: Licht (Kammerlicht, zweites Kammerlicht, Arbeitslicht), Druck (Pause, Fortsetzen, Geschwindigkeit 1–4), Stopp. Höchstens ein Befehl je Sekunde und Drucker, nur bei bestehender Verbindung. Ohne Entwicklermodus lehnt die Firmware die Druckbefehle ab; das Plugin quittiert das mit „abgelehnt: Drucker verlangt den Entwicklermodus“.
- Fehlerbehandlung: ein ausgeschalteter Drucker ist kein Fehler (`verbunden` = 0, `status/ok` bleibt 1); ein falscher Access Code oder ein Verbindungsverlust **während eines Drucks** setzt `status/ok` auf 0. `status/ok|zaehler|ts` mit Letztem Willen, Healthcheck für die LoxBerry-Healthcheck-Seite.
- Weboberfläche im LoxBerry Design System: Reiter Drucker, MQTT (mit Prüfung des Gateway-Abos), Einbindung in Loxone (alle Topics und Befehle), Test (Sofort abrufen, Selbstprüfung) und Logdateien.
- Automatische Updates (`release.cfg` im Repository, `bash build.sh release-cfg`), ZIP ohne Version im Namen für einen festen Download-Link.
- Tests mit einem Fake-Drucker (MQTT mit TLS) und den anonymisierten Reports eines H2C.
