# Loxone-Anbindung

Der Weg: **Drucker → Bambulab2Lox → LoxBerry-MQTT-Broker → LoxBerry-MQTT-Gateway → Miniserver (virtuelle Eingänge)**. Befehle gehen den umgekehrten Weg: **Miniserver (virtueller Ausgang) → UDP → Gateway → Broker → Bambulab2Lox → Drucker**.

## 1. MQTT-Gateway einrichten

**Abonnement `bambulab/#` im MQTT Gateway:** Beim **Gateway V1** (Perl) trägt das Plugin es über die Datei `mqtt_subscriptions.cfg` selbst ein (eine Zeile je Topic). Das **Gateway V2** (Python) liest diese Datei nicht (LoxBerry-Quellcode): Dort das Abo **von Hand** im MQTT Gateway unter „Abonnements“ eintragen: `bambulab/#`. Das Plugin prüft im Reiter *MQTT* und in der Selbstprüfung, ob es eingetragen ist. Mit **eigenem Broker** (Reiter MQTT) bekommt der Miniserver die Werte nur, wenn das Gateway diesen Broker abonniert.

Für Lebenszeichen und Fehlererkennung die Topics `bambulab/status/ok` und `bambulab/status/zaehler` im Gateway auf **No Cache** stellen.

## 2. Virtuelle Eingänge in Loxone Config

Das Gateway ersetzt `/` durch `_`. Aus `bambulab/drucker1/fortschritt_prozent` wird der Eingang `bambulab_drucker1_fortschritt_prozent`. Die Tabelle aller Topics samt Eingangsnamen zeigt die Plugin-Oberfläche im Reiter **Einbindung in Loxone**. Typische Eingänge:

| Eingang | Bedeutung |
|---|---|
| `bambulab_drucker1_verbunden` | 1 = Drucker verbunden |
| `bambulab_drucker1_zustand_druckt` | 1 = Vorbereitung oder Druck läuft |
| `bambulab_drucker1_zustand_fertig` | 1 = Druck fertig |
| `bambulab_drucker1_zustand_fehler` | 1 = Druck mit Fehler beendet |
| `bambulab_drucker1_fortschritt_prozent` | Fortschritt (%) |
| `bambulab_drucker1_restzeit_min` | Restzeit (min) |
| `bambulab_drucker1_duese_c`, `…_bett_c`, `…_kammer_c` | Temperaturen |
| `bambulab_status_ok` | 1 = alle Drucker in Ordnung |
| `bambulab_status_zaehler` | Lebenszeichen 0…999 |

Hinweise:

- **Ausfallerkennung:** `bambulab_status_zaehler` ändert sich alle 10 s. Bleibt er stehen, läuft der Dienst nicht mehr.
- **Drucker aus:** Ein ausgeschalteter Drucker ist kein Fehler: `verbunden` = 0, `status/ok` bleibt 1. Verlässt der Drucker das Netz **während eines Drucks**, wird `status/ok` 0. Für „Druck fertig“ eignet sich `zustand/fertig`, für „Druck abgebrochen“ `zustand/abgebrochen`.
- Es werden nur Änderungen gesendet (alle 5 Minuten alle Werte). Eingänge ohne Änderung behalten ihren Wert.
- Keine Leerzeichen in Druckernamen verwenden, sonst findet das Gateway die Eingänge nicht.

## 3. Befehle aus Loxone

Befehle sind ab Werk **gesperrt** und je Drucker in drei Stufen im Reiter *Drucker* freizugeben (Licht · Pause, Fortsetzen, Geschwindigkeit · Stopp).

Virtueller Ausgang in Loxone Config:

| Feld | Wert |
|---|---|
| Adresse | `/dev/udp/<LoxBerry-IP>/<UDP-Port>` |
| Befehl bei EIN | `publish bambulab/befehl/<name>/<befehl> <wert>` |
| Trennzeichen / HTTP | leer, **ohne** HTTP-Header/Body |

`<UDP-Port>` ist der Befehlseingang des MQTT-Gateways (Standard 11884; der Port ist einstellbar und steht im Plugin im Reiter „Einbindung in Loxone“). An einen virtuellen Ausgang lassen sich mehrere Befehle hängen, einer je Zeile.

| `<befehl>` | `<wert>` | Beispiel |
|---|---|---|
| `licht_kammer` | `1` oder `0` | `publish bambulab/befehl/drucker1/licht_kammer 1` |
| `licht_kammer2` | `1` oder `0` | zweites Kammerlicht (H2) |
| `licht_arbeit` | `1` oder `0` | 1 an, 0 Ruhezustand (blinkend) |
| `pause` | `1` | nur während des Drucks |
| `fortsetzen` | `1` | nur aus der Pause |
| `geschwindigkeit` | `1`–`4` | 1 leise, 2 standard, 3 sport, 4 irrsinn |
| `stopp` | `1` | bricht den Druck ab |

**Quittung:** Unter `bambulab/befehl/<name>/<befehl>/quittung` meldet das Plugin zuerst `angenommen` und dann `ausgefuehrt` oder `abgelehnt: <Grund>` (nie retained). Auch dieses Topic lässt sich im Gateway als Eingang nutzen. Höchstens ein Befehl je Sekunde und Drucker; ein zweiter Befehl in derselben Sekunde wird abgelehnt.

**Entwicklermodus:** Pause, Fortsetzen, Geschwindigkeit und Stopp lehnt die Firmware des Druckers ohne Entwicklermodus ab. Die Quittung lautet dann `abgelehnt: Drucker verlangt den Entwicklermodus`. Das Licht geht auch ohne.

Der Befehlseingang des Gateways kennt kein Zurückmelden: ob ein Befehl ankam, zeigt nur die Quittung.
