# Vorlage für das LoxBerry-Wiki

`bambulab2lox_start.txt` ist die Seite für das [LoxBerry-Wiki](https://wiki.loxberry.de/plugins/start) im **DokuWiki-Format**
(Aufbau wie die Seite des Plugins [Sonos4Loxone](https://wiki.loxberry.de/plugins/sonos4loxone/start): Plugin-Daten, Überblick,
Konfiguration, Referenz, Miniserver-Anbindung, FAQ, Changelog).

## Vor dem Einstellen anpassen

- `FORUM-LINK-EINTRAGEN`: Link zum Forenbeitrag des Plugins (Zeile „Diskussion“ der Plugin-Daten).
- Bild `plugins:bambulab2lox:bambulab2lox.png`: Datei `docs/logo/bambulab2lox.png` im Medien-Manager des Wikis hochladen
  (Namensraum `plugins:bambulab2lox`).
- Das Feld **Status** („BETA“) und **Min. LB Version** („4.0“) prüfen. Die Version steht in `bambulab2lox/plugin.cfg`.
- Bei jedem Release die Felder **Version** und **Release Download** sowie die Changelog-Tabelle aktualisieren.
- Screenshots (z. B. Druckerliste, Test) lassen sich als `{{:plugins:bambulab2lox:name.png?600|Text}}` einfügen.

## Einstellen

Die Seite gehört in den Namensraum `plugins:bambulab2lox:start`. Das Anlegen und Bearbeiten im LoxBerry-Wiki setzt ein Wiki-Konto
bzw. die Freigabe durch das LoxBerry-Team voraus (Details im LoxBerry-Forum). Den Inhalt der Datei im Wiki-Editor einfügen.
