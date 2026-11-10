# Installation

## ZIP bauen

```bash
bash build.sh
```

Ergebnis: `bambulab2lox-<version>.zip` und ein gleiches `bambulab2lox.zip` ohne Version im Namen (enthält nur den Inhalt von `bambulab2lox/` plus die gemeinsamen Klassen, keine Tests und keine Doku). Fertige ZIPs gibt es unter den GitHub-Releases des Repos.

**Immer das neueste Release:** Der Link `https://github.com/ricten/Bambulab2Lox/releases/latest/download/bambulab2lox.zip` lädt das ZIP des jeweils letzten Releases (ohne Version im Dateinamen). Er lässt sich in der LoxBerry-Plugin-Verwaltung beim Installieren von einer Internetadresse eintragen und muss nach einem neuen Release nicht angepasst werden.

## Am Drucker vorbereiten

1. **IP-Adresse, Seriennummer und Access Code** stehen am Drucker unter Einstellungen → WLAN/Netzwerk. Am H2C geht das **Auslesen** ohne LAN-Modus und ohne Entwicklermodus. Bei anderen Modellen kann der **LAN-Modus** nötig sein (Einstellungen → WLAN/Netzwerk → **Nur LAN-Modus**): Mit ihm ist die Bambu-Cloud für diesen Drucker abgeschaltet (Bambu Handy und die Cloud-Funktionen gehen dann nicht mehr), Bambu Studio im selben Netz funktioniert weiter.
2. Nur für Schaltbefehle außer Licht (**Pause, Fortsetzen, Geschwindigkeit, Stopp**): den **Entwicklermodus** einschalten (Einstellungen → WLAN/Netzwerk → Nur LAN-Modus → Entwicklermodus). Seit der Firmware mit *Authorization Control* (2025) lehnt der Drucker diese Befehle sonst mit `mqtt message verify failed` ab. Der Entwicklermodus ist von Bambu nicht offiziell unterstützt. **Lesen** und die **Lichter** gehen auch ohne Entwicklermodus (am H2C geprüft).
3. Für den Drucker eine feste IP-Adresse im Router einrichten (DHCP-Reservierung).

## Auf dem LoxBerry installieren

1. LoxBerry-Oberfläche → **Plugin-Verwaltung** → **Plugin installieren**.
2. ZIP hochladen und installieren. Die Installation startet den Dienst.
3. Plugin **Bambulab2Lox** öffnen, Reiter **Drucker**: Host/IP, Seriennummer und Access Code eintragen, **Aktiv** anhaken, **Speichern** (siehe [Konfiguration](Konfiguration.md)).
4. Im **MQTT Gateway** das Abo `bambulab/#` eintragen: Beim Gateway V1 macht das die Abo-Datei des Plugins, beim **Gateway V2** muss es von Hand unter „Abonnements“ stehen. Der Reiter **MQTT** zeigt, ob es eingetragen ist.
5. Reiter **Test** → **Selbstprüfung** und **Sofort abrufen**.
6. Nach wenigen Sekunden erscheinen die Werte unter `bambulab/<name>/…` (siehe [MQTT-Topics](MQTT-Topics.md)).

## Was die Installation einrichtet

| Teil | Ort (von LoxBerry gesetzt) |
|---|---|
| Dienst und Bibliotheken | `bin/plugins/bambulab2lox/` |
| Konfiguration (mit Access Codes) | `config/plugins/bambulab2lox/bambulab.json` (Rechte 0600) |
| Logdatei | `log/plugins/bambulab2lox/bambulab.log` |
| Live-Status (RAM) | `/dev/shm/bambulab2lox/status.json` |
| Autostart | Daemon beim Boot (root → startet als `loxberry`) |
| Watchdog | Cronjob jede Minute startet den Dienst, falls er nicht läuft |

Das Plugin ändert nichts außerhalb seiner eigenen Ordner. Das Logo (`icons/icon_*.png`) liegt im Plugin.

## Sicherheit

- Der **Access Code** liegt nur in der Konfigurationsdatei (Rechte 0600). Er wird nie angezeigt, nie geloggt und nie per MQTT gesendet.
- Der Drucker verwendet ein **selbst signiertes Zertifikat**. Das Plugin prüft es nicht (das Gerät steht im Heimnetz); die Verbindung ist verschlüsselt, aber nicht gegen einen Angreifer im selben Netz abgesichert.
- Befehle sind ab Werk gesperrt (siehe [Konfiguration](Konfiguration.md)).

## Update

Beim Update sichern `preupgrade.sh`/`postupgrade.sh` **Konfiguration und Daten** und spielen sie zurück. Neue Konfigurationsschlüssel ergänzt der Dienst beim Laden automatisch mit Standardwerten.

### Automatische Updates

Das Plugin nutzt die Update-Funktion der LoxBerry-Plugin-Verwaltung. In der `plugin.cfg` steht der Verweis auf die Datei `release.cfg` im GitHub-Repository (`ricten/Bambulab2Lox`, Zweig `main`). LoxBerry prüft sie täglich und vergleicht die dort eingetragene Version mit der installierten.

- **Neue Installationen** stehen in der Plugin-Verwaltung bei „Automatische Updates“ auf **Releases**: eine neuere Version wird selbst heruntergeladen und installiert. Dort lässt sich auch *Aus* oder *Benachrichtigen* wählen.
- Konfiguration und Daten bleiben wie bei jedem Update erhalten (siehe oben).
- Die `release.cfg` zeigt immer auf das ZIP der jeweils letzten Version; sie wird erst nach dem GitHub-Release angepasst, damit nie auf ein fehlendes ZIP verwiesen wird.

## Deinstallation

Plugin in der Plugin-Verwaltung entfernen. Der Dienst wird beendet und `/dev/shm/bambulab2lox` gelöscht. Am Drucker bleibt der LAN-Modus eingeschaltet, bis du ihn dort wieder ausschaltest.
