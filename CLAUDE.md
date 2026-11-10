# Bambulab2Lox – LoxBerry-Plugin

**Version: siehe `bambulab2lox/bin/lib/AppVersion.php` (muss mit `bambulab2lox/plugin.cfg` und [CHANGELOG.md](CHANGELOG.md) übereinstimmen; `build.sh` prüft das). Ziel-PHP: 8.4 (CLI + Web).**

Ersetzt den ioBroker-Adapter `bambulab`. Liest Bambu-Lab-Drucker über deren lokalen MQTT-Broker (TLS) und veröffentlicht per MQTT; nimmt Befehle aus Loxone an.
Spezifikation und Befunde am H2C: [SPEZIFIKATION.md](SPEZIFIKATION.md).
Übergeordnete Regeln (Topics, Status, Versionierung): `../CLAUDE.md` und `../README.md`. Bei Widerspruch gelten die Regeln dieser Datei.

## Layout
- `bambulab2lox/` = Inhalt des Plugin-ZIP (plugin.cfg, bin/, config/, cron/, daemon/, uninstall/, webfrontend/, *.sh)
- `../common/php/` = gemeinsamer Code (Util, Logger, MqttClient, GatewayInfo). `build.sh` kopiert ihn nach `bin/lib` des ZIP; nie aus einem anderen Plugin importieren
- `tests/` = `php tests/run.php` (Unit + Ende-zu-Ende mit Fake-Drucker und Fake-Broker) – **nicht** im ZIP; `tests/fixtures/` sind anonymisierte Reports eines H2C

## Harte Regeln
- Keine hartkodierten `/opt/loxberry`-Pfade. PHP: `loxberry_system.php` (`LBPCONFIGDIR` …); Shell/daemon/cron: Platzhalter `REPLACELBPBINDIR` usw.
- Dateien mit LF-Zeilenenden. `.gitattributes`/`.editorconfig` beachten.
- Plugin-Identität nie ändern (`AUTHOR.NAME` + `AUTHOR.EMAIL` + `PLUGIN.NAME` + `PLUGIN.FOLDER`), sonst entsteht beim Installieren ein zweites Plugin.
- Plugin-Ordner nie fest eintragen (kann `bambulab2lox_000` heißen): Skripte nehmen ihn aus `$3` bzw. aus ihrem Ort. MQTT-Client-ID enthält den Ordner (`bambulab_<Ordner>`).
- Weboberfläche und Dienst: `LBWeb::lbheader()` und `mqtt_connectiondetails()` überschreiben die globale Variable `$cfg`. Eigene Konfiguration heißt deshalb `$bbl`.
- Laufzeitstatus (`status.json`, PID, Sperre) nur nach `/dev/shm/<Ordner>/`. Konfiguration atomar schreiben (Temp + rename), Rechte 0600.
- **Access Codes** (und das Passwort eines eigenen MQTT-Brokers) nie loggen (Logger maskiert registrierte Secrets), nie per MQTT senden, nie in der Oberfläche oder in `status.json` anzeigen (Passwortfeld, leer = unverändert). Keine Seriennummer, IP oder Access Code eines echten Druckers in Code, Tests, Fixtures oder Doku; Tests prüfen das.
- Kein MQTT-Paket als Abhängigkeit: eigener Client (`MqttClient`, für den Drucker mit der TLS-Option). Das Zertifikat des Druckers ist selbst signiert und wird nicht geprüft (Gerät im Heimnetz); das steht in der Oberfläche und der Doku.
- **Befehle:** nur die in `BambuCommands::LISTE` (Licht, Pause, Fortsetzen, Geschwindigkeit, Stopp), in drei Stufen je Drucker, ab Werk alle gesperrt, immer mit Quittung (`angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>`), nie retained. Nie: rohes G-Code, Druckaufträge, Firmware, Kalibrierung, Access-Code-Befehle. Höchstens ein Befehl je Sekunde und Drucker, nur bei bestehender Verbindung. Antworten des Druckers ohne Beachtung der Groß-/Kleinschreibung auswerten (`success` bei `ledctrl`, `SUCCESS` bei den Druckbefehlen). Die Firmware lehnt `print.*`-Befehle ohne Entwicklermodus mit `mqtt message verify failed` ab: Quittung „Drucker verlangt den Entwicklermodus“.
- **Erwartete Zustände sind kein Fehler:** Ein ausgeschalteter Drucker ohne laufenden Druck ist `aus` (`verbunden` = 0, `status/ok` bleibt 1). Fehler sind: falscher Access Code, Verbindungsverlust während eines Drucks, ein verbundener Drucker, der nicht antwortet. Fällt ein Drucker aus, laufen die anderen weiter, `status/zaehler` läuft weiter.
- Nur Änderungen veröffentlichen (der H2C sendet etwa jede Sekunde den vollen Zustand), alle 5 Minuten alle Werte. Ohne gültigen Wert wird nichts gesendet. Plausibilitätsfilter nicht entfernen: Schichtzahl beim Start (Ausreißer), AMS-Temperatur −50, Restmenge −1.
- Deutungen ohne offizielle Doku sind im Mapper und in `SPEZIFIKATION.md` als solche gekennzeichnet (gepackte Temperaturen, aktive Düse aus `device.extruder.state`, Lüfterskala 0–15). Nichts erfinden: was unklar ist, nicht senden.
- Topic-Namen unter `bambulab/` nie umbenennen (Loxone-Eingänge hängen daran), sonst MAJOR-Version erhöhen. Keine Leerzeichen in Topics und Gerätenamen.
- **Logo und Icons:** `tools/make_logo.py` erzeugt `docs/logo/bambulab2lox.png` und `bambulab2lox/icons/icon_{64,128,256,512}.png` im Stil der anderen Plugins (runder grüner LoxBerry-Stil, links die H2C schematisch nach Ricos Foto ohne Herstellerlogo, rechts der Miniserver). Die Dateinamen `icon_*.png` sind vom Installer vorgegeben, nie umbenennen. Kein `icon.svg` (der Installer würde es vor den PNGs nehmen). Änderungen am Logo nur auf Wunsch von Rico.

## Entwicklung
- PHP lokal nötig für die Tests; fehlt es, das offen sagen und nichts als „getestet“ melden. Der Fake-Drucker braucht die PHP-Erweiterung `openssl` (unter Windows ohne `php.ini`: `php -d extension_dir=<php>/ext -d extension=openssl tests/run.php`).
- Nicht lokal testbar (nur auf dem LoxBerry bzw. am echten Drucker): LoxBerry-Bibliotheken (`LBWeb`, `LBLog`, `mqtt_connectiondetails`), das Aussehen der Weboberfläche, das echte Zertifikat und Verhalten von X1, P1, A1 und den anderen H2-Modellen. Die Fixtures sind aus dem Mitschnitt eines H2C anonymisiert.
- Dienst lokal: `BBL_DEV_DIR=<Ordner mit bambulab.json> BBL_DEV_MQTT=127.0.0.1:18841 php bambulab2lox/bin/bambulabd.php` (Fake-Server: `php tests/fake_bambu.php 18871 <Ordner> <zertifikat.pem> <seriennummer>`, `php tests/fake_mqtt.php 18841 <Ordner>`).
- Build: `bash build.sh` → `bambulab2lox-<version>.zip` und `bambulab2lox.zip` (ohne Version).

## Release-Ablauf (Autoupdate über `release.cfg`)
1. Version in `plugin.cfg` und `AppVersion.php` erhöhen, Eintrag in `CHANGELOG.md`, Tests, `bash build.sh` (baut beide ZIPs).
2. Committen (ohne `release.cfg`), pushen, Tag `vX.Y.Z` setzen, `gh release create vX.Y.Z bambulab2lox-X.Y.Z.zip bambulab2lox.zip …` (beide ZIPs anhängen: das ZIP ohne Version macht den Link `https://github.com/ricten/Bambulab2Lox/releases/latest/download/bambulab2lox.zip` zum festen Link auf das neueste Release).
3. **Danach** `bash build.sh release-cfg`, `release.cfg` committen und pushen. Erst dann sehen die Installationen das Update. Die Datei darf nie auf eine Version zeigen, deren ZIP noch nicht existiert.
- **`release.cfg` nur nach Rücksprache mit Rico anlegen oder ändern** (auch `bash build.sh release-cfg` nicht ohne Freigabe): Sie löst bei allen Installationen mit aktivierten Auto-Updates sofort ein Update aus.
- `plugin.cfg` enthält `AUTOMATIC_UPDATES=true` und `RELEASECFG=https://raw.githubusercontent.com/ricten/Bambulab2Lox/main/release.cfg` (`PRERELEASECFG` leer). LoxBerry lädt die `release.cfg` per curl (folgt Weiterleitungen) und vergleicht `VERSION` mit der installierten Version. `true` schaltet bei **neuen** Installationen die automatischen Release-Updates ein.
- Weboberfläche: LoxBerry Design System (`lb-*`, `lbheader(…, true)`), kein jQuery Mobile; Farben nur über `var(--lb-…, Rückfall)`.

## Vorgaben aus dem LoxBerry-Entwicklerwiki (Stand 2026-11-10)
- `plugin.cfg`: `CUSTOM_LOGLEVELS=true` schaltet die Loglevel-Auswahl frei (der Installer liest nur diesen Schlüssel; `LOGLEVELS_ENABLED` gibt es nicht). Version dreiteilig ohne Buchstaben, `ARCHITECTURE` mit Werten immer in Anführungszeichen, `TITLE` höchstens 25 Zeichen.
- **Gateway-Abo:** `config/mqtt_subscriptions.cfg` ist **zeilenweise** (ein Topic je Zeile, z. B. `bambulab/#`), kein INI-Format. Nur das Gateway V1 (Perl) liest sie; das **Gateway V2 (Python) liest sie nicht** (LoxBerry-Quellcode `sbin/mqtt_gateway.py`). Die Oberfläche prüft deshalb `config/system/subscriptions.json` (`GatewayInfo::subscription`) und sagt dem Benutzer, dass er das Abo im Gateway eintragen muss. Nie behaupten, das Plugin trage das Abo selbst ein.
- **UDP-Port** des Gateways nie fest eintragen: `mqtt_connectiondetails()['udpinport']` bzw. `general.json` (`Mqtt.Udpinport`), Standard 11884. Einfache Befehlsform `publish <topic> <wert>` (Leerzeichen im Topic nur in der JSON-Form). Das Gateway hat außerdem eine HTTP-Schnittstelle (`/admin/system/tools/mqtt.php`, mit LoxBerry-Anmeldung).
- Ungenutzte Installationsskripte (`preinstall.sh` usw.) und eine leere `dpkg/apt` nicht mitliefern (verzögert die Installation).
- Healthcheck: `bin/healthcheck` (Argumente `title` und `check`, Ausgabe als JSON mit `desc`, `status`, `result`, optional `logfile`; Status 3 Fehler = Benachrichtigung und E-Mail, 4 Warnung, 5 OK, 6 Info; schnell, nur lesend, nur das JSON ausgeben, Zeitzone setzen). Ein erwarteter Zustand (z. B. ein ausgeschalteter Drucker) ist nie Fehler oder Warnung.
- Weboberfläche: Menüleiste über `$navbar` (vor `LBWeb::lbheader()` definieren), nicht über eigene Links; Tests rendern jeden Reiter mit Attrappen der LoxBerry-Bibliotheken (`tests/stubs`, `tests/ui_page.php`).
- Keine festen `/opt/loxberry`-Pfade (auch nicht in Kommentaren: der Installer warnt dann), Platzhalter `REPLACELBP…` oder Konstanten verwenden.
