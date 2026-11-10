# Entwicklung

## Aufbau

```
bambulab2lox/               Inhalt des Plugin-ZIP
├── plugin.cfg
├── postinstall.sh preupgrade.sh postupgrade.sh
├── uninstall/uninstall  daemon/daemon  cron/cron.01min
├── bin/
│   ├── bambulabd.php       Dienst (Drucker-Verbindungen, MQTT, Befehle)
│   ├── healthcheck         LoxBerry-Healthcheck (Dienst, Broker, Drucker)
│   ├── start.sh stop.sh
│   └── lib/                 BambuConfig, BambuState, BambuMapper, BambuCommands, BambuPrinter, BambuHmsTexte, bootstrap, AppVersion
│                            + Util, Logger, MqttClient, GatewayInfo (aus ../common/php, kopiert build.sh)
├── config/mqtt_subscriptions.cfg
├── icons/icon_{64,128,256,512}.png
└── webfrontend/htmlauth/index.php
tests/                       Unit-/Ende-zu-Ende-Tests, Fake-Drucker und Fake-Broker, anonymisierte Reports (nicht im ZIP)
docs/                        Wiki, Vorlage für das LoxBerry-Wiki, Logo (nicht im ZIP)
SPEZIFIKATION.md             Spezifikation und Befunde am H2C
tools/make_logo.py           erzeugt Logo und Plugin-Icons (Pillow)
```

Der gemeinsame Code liegt außerhalb des Repos in `loxberry-plugins/common/php/`. `build.sh` kopiert ihn ins ZIP; kein Plugin importiert aus einem anderen. Logo und Plugin-Icons erzeugt `tools/make_logo.py` (Pillow) im Stil der anderen Plugins; die Dateinamen `icons/icon_*.png` sind vom Installer vorgegeben. Kein `icon.svg`.

## Klassen

| Klasse | Aufgabe |
|---|---|
| `BambuPrinter` | Eine Drucker-Verbindung (TLS-MQTT, Wiederverbinden, `pushall`), Zustand, Veröffentlichen nur geänderter Werte, Befehle mit Quittung, Status (ok / aus / Fehler) |
| `BambuState` | Reports zusammenführen (Deep Merge; Listen nur dort über ihren Schlüssel, wo die Einträge stabil sind) |
| `BambuHmsTexte` | Klartext zu HMS-Meldungen und Fehlercodes: lädt die öffentliche Bambu-Liste (nur bei eingeschalteter Option, höchstens einmal je Woche, Zwischenspeicher im Datenordner, Ausfall nie fatal) |
| `BambuMapper` | Zustand → Topics (Packwerte, Lüfterskala, HMS-Text, Plausibilität, H2-Düsen, AMS) |
| `BambuCommands` | erlaubte Befehle, Prüfung, Nachricht an den Drucker, Auswertung der Antwort |
| `BambuConfig` | Standardwerte, Laden, Prüfen der Eingaben, Druckerliste, Geheimnisse |
| `MqttClient` (common) | MQTT 3.1.1; optional TLS ohne Zertifikatsprüfung für den Drucker, kein Letzter Wille bei leerem Topic |

## Ablauf des Dienstes

1. Konfiguration laden (und bei Änderung der Datei neu); je aktivem Drucker ein `BambuPrinter`.
2. Verbindung zum LoxBerry-Broker (mit Letztem Willen `bambulab/status/ok`), Abo `bambulab/befehl/#`.
3. Je Drucker: TLS-Verbindung (Port 8883, Benutzer `bblp`, Access Code), Abo `device/<Seriennummer>/report`, `get_version` und `pushall` senden, Reports einarbeiten. Bei Modellen mit Teil-Updates (P1, A1) im eingestellten Abstand erneut `pushall`.
4. Einmal pro Sekunde: geänderte Werte an den LoxBerry-Broker senden (alle 5 Minuten alle), Status in `/dev/shm`.
5. Befehle: Prüfung (Stufe, Wert, Zustand, Verbindung, 1 Befehl/s), Nachricht an `device/<Seriennummer>/request`, die Antwort mit gleicher `sequence_id` wird als Quittung veröffentlicht.

## Tests

```bash
php tests/run.php
```

(Unter Windows ohne `php.ini`: `php -d extension_dir=<php>/ext -d extension=openssl tests/run.php`.)

- **Unit:** Mapper gegen die anonymisierten Reports eines H2C (`tests/fixtures/`), Packwerte, Deep Merge, Befehle, Konfiguration, Logger-Maskierung.
- **Ende zu Ende:** `bambulabd.php` im Entwicklungsmodus (`BBL_DEV_DIR`, `BBL_DEV_MQTT`) gegen den **Fake-Drucker** `tests/fake_bambu.php` (MQTT-Broker mit TLS, selbst signiertes Testzertifikat, das bei jedem Lauf neu erzeugt wird; spielt die Fixtures ab, antwortet auf `get_version`, `pushall` und die Befehle, kann „Entwicklermodus aus“ und P1-Teil-Updates nachbilden) und den Fake-Broker `tests/fake_mqtt.php`.
- Healthcheck, Weboberfläche mit Attrappen der LoxBerry-Bibliotheken (`tests/stubs`, `tests/ui_page.php`), `build.sh` und der ZIP-Inhalt.

**Nicht lokal testbar** (nur auf dem LoxBerry bzw. am echten Drucker): LoxBerry-Bibliotheken (`LBWeb`, `LBLog`, `mqtt_connectiondetails`), das Aussehen der Weboberfläche, das echte Zertifikat und das Verhalten von X1, P1, A1 und den anderen H2-Modellen. Am echten H2C wurden von Hand geprüft: Lesen, die drei Lichter, Geschwindigkeit, Pause, Fortsetzen und Stopp (mit einem Testskript, siehe `SPEZIFIKATION.md`) sowie mit dem installierten Plugin die Verbindung, der Status und der Abruf der HMS-Textliste. Befehle über das Plugin sind noch nicht am echten Drucker geprüft.

## Dienst lokal starten

```bash
BBL_DEV_DIR=<Ordner mit bambulab.json> BBL_DEV_MQTT=127.0.0.1:18841 php bambulab2lox/bin/bambulabd.php
php tests/fake_mqtt.php 18841 <Ordner>
php tests/fake_bambu.php 18871 <Ordner> <zertifikat.pem> <seriennummer>
```

## Regeln

Siehe [CLAUDE.md](../../CLAUDE.md) im Repository: Topic-Namen nie umbenennen, Access Codes nie loggen oder senden, Befehle ab Werk gesperrt, `release.cfg` nur nach Rücksprache.
