# Fehlersuche

Zuerst im Plugin den Reiter **Test** öffnen: **Selbstprüfung** und **Sofort abrufen**. Details stehen im Reiter **Logdateien** (`bambulab.log`); den Loglevel stellt man in der LoxBerry-Plugin-Verwaltung ein.

| Symptom | Ursache / Lösung |
|---|---|
| Kästchen grau, „nicht erreichbar (ausgeschaltet?) – kein Fehler“ | Der Drucker ist aus oder nicht im Netz. Kein Fehler, solange kein Druck lief. Host/IP prüfen, Drucker einschalten |
| Kästchen rot, „Anmeldung abgelehnt: Access Code falsch?“ | Der Access Code stimmt nicht (er ändert sich, wenn man ihn am Drucker neu erzeugt). Neu eintragen. Ist der **LAN-Modus** aus? Ohne LAN-Modus nimmt der Drucker die lokale Anmeldung nicht an |
| Kästchen rot, „Verbindung verloren, während ein Druck lief“ | Der Drucker war mitten im Druck nicht mehr erreichbar (WLAN, Router, Stromausfall). Das Plugin verbindet sich selbst wieder; der Fehler verschwindet danach |
| Selbstprüfung: „TCP-Verbindung … fehlgeschlagen“ | Falsche IP, Drucker aus, Firewall. Die IP am Drucker unter Einstellungen → Netzwerk nachsehen und eine feste IP vergeben |
| Selbstprüfung: „Keine Antwort des Druckers innerhalb von … s“ | Anmeldung klappt, aber es kommen keine Reports. Falsche **Seriennummer** (steckt im Topic `device/<Seriennummer>/report`)? Groß-/Kleinschreibung beachten, das Plugin schreibt sie groß |
| Keine Werte in Loxone, aber in MQTT | **Gateway V2:** Das Abo `bambulab/#` muss von Hand im MQTT Gateway unter „Abonnements“ stehen (V2 liest die Abo-Datei des Plugins nicht); der Reiter MQTT zeigt, ob es eingetragen ist |
| Befehl wird mit „Befehle sind gesperrt“ abgelehnt | Die Stufe des Befehls ist für diesen Drucker nicht freigegeben (Reiter Drucker, „Befehle freigeben“) |
| Befehl wird mit „Drucker verlangt den Entwicklermodus“ abgelehnt | Pause, Fortsetzen, Geschwindigkeit und Stopp lehnt die Firmware ohne **Entwicklermodus** ab (am Drucker: Einstellungen → WLAN/Netzwerk → Nur LAN-Modus → Entwicklermodus; schaltet die Bambu-Cloud ab). Das Licht geht ohne |
| Befehl: „es läuft kein Druck“ / „der Druck ist nicht pausiert“ | Pause und Stopp gehen nur während des Drucks (Stopp auch in der Pause), Fortsetzen nur aus der Pause |
| Befehl: „zu schnell“ | Höchstens ein Befehl je Sekunde und Drucker. Mehrere Befehle in der Loxone-Konfiguration zeitlich versetzen |
| Befehl: „keine Antwort des Druckers“ | Der Drucker hat nach 10 s nicht geantwortet. Meist war er nicht erreichbar oder beschäftigt; erneut senden |
| `schicht` springt beim Start kurz auf einen hohen Wert | Der Drucker meldet beim Start kurz die Schichtzahl des vorigen Drucks. Das Plugin sendet solche Werte nicht (Schicht größer als die Gesamtzahl, oder in der Vorbereitung) |
| Kammertemperatur fehlt | Nicht jedes Modell hat einen Kammersensor (P1, A1: nein). Beim H2 kommt der Wert aus `device.ctc`, beim X1 aus `chamber_temper` |
| AMS-Temperatur fehlt | Das AMS lite meldet −50 °C (ungültig); das Plugin sendet das nicht |
| Es kommen sehr viele Meldungen | Das Plugin sendet nur Änderungen. Temperaturen und Lüfter ändern sich beim Drucken ständig; im Gateway lassen sich Eingänge, die man nicht braucht, einfach weglassen |
| Bambu Studio zeigt den Drucker nicht mehr in der Cloud | Das ist der LAN-Modus (bzw. Entwicklermodus): Er schaltet die Cloud-Anbindung des Druckers ab. Zum Auslesen ist er am H2C nicht nötig. Bambu Studio im selben Netz und das Plugin funktionieren weiter |
| In `start-error.log` steht einmal „log_db_queryid: Could not find database entry …“ | Harmlos: Beim allerersten Start kennt die LoxBerry-Logbibliothek die neue Logdatei noch nicht. Beim nächsten Start ist die Meldung weg; die Oberfläche blendet sie aus |
| Status zeigt „abgebrochen“ oder „Fehler“ | Der Drucker meldet nach einem abgebrochenen oder fehlgeschlagenen Druck weiter FAILED, bis ein neuer Druck startet. „abgebrochen“ = ohne Fehlercode, „Fehler“ = mit Fehlercode. Der Rohwert steht in `status_roh` |
| Hinweis „Entwicklermodus am Drucker einschalten“ | Der Drucker hat Pause, Fortsetzen, Geschwindigkeit oder Stopp abgelehnt. Ohne Entwicklermodus ist nur das Licht schaltbar |
| Dienst läuft nicht | Der Wächter startet ihn spätestens nach einer Minute; sonst `log/plugins/bambulab2lox/start-error.log` ansehen oder „Dienst neu starten“. Fehlt die PHP-Erweiterung `openssl`, steht das dort |
| `status/ok` = 0 | Mindestens ein Drucker hat einen echten Fehler (falscher Access Code, Verbindungsverlust im Druck, antwortet nicht). Name und Grund stehen im Reiter MQTT und im Log. Ein ausgeschalteter Drucker zählt nicht |
