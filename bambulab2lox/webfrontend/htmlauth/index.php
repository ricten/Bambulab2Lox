<?php
// Weboberflaeche Bambulab2Lox: Drucker · MQTT · Einbindung in Loxone · Test · Logdateien
// Achtung: LBWeb::lbheader() und mqtt_connectiondetails() ueberschreiben die globale Variable $cfg -> eigene Konfiguration heisst $bbl.

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once LBPBINDIR . '/lib/bootstrap.php';

session_start();
if (empty($_SESSION['bbl_token'])) {
    $_SESSION['bbl_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['bbl_token'];

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Schalter im Stil des LoxBerry Design Systems (Checkbox mit lb-toggle); $text darf feste HTML-Auszeichnung enthalten. */
function schalter(string $name, bool $an, string $text = ''): string
{
    return '<label class="lb-toggle"><input type="checkbox" name="' . h($name) . '" value="1"' . ($an ? ' checked' : '') . '><span class="lb-toggle-slider"></span></label>'
        . ($text !== '' ? ' <span class="bbl-schalter-text">' . $text . '</span>' : '');
}

$paths = Paths::detect();
$bbl = BambuConfig::load($paths->config);
$tab = $_GET['tab'] ?? 'drucker';
if (!in_array($tab, ['drucker', 'mqtt', 'loxone', 'test', 'logs'], true)) {
    $tab = 'drucker';
}
$meldungen = [];

// ------------------------------------------------------------ Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        http_response_code(403);
        exit('Ungueltiges Formular-Token, Seite neu laden.');
    }
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'speichern') {
        $neu = BambuConfig::fromPost($bbl, $_POST);
        if (Util::jsonSave($paths->config, $neu)) {
            $meldungen[] = 'Einstellungen gespeichert.';
        } else {
            $meldungen[] = 'FEHLER: Einstellungen konnten nicht gespeichert werden.';
        }
        $bbl = $neu;
    } elseif ($aktion === 'neustart') {
        exec(escapeshellarg(LBPBINDIR . '/stop.sh') . ' 2>&1');
        exec(escapeshellarg(LBPBINDIR . '/start.sh') . ' 2>&1');
        $meldungen[] = 'Dienst neu gestartet.';
    }
}

$status = Util::jsonLoad($paths->statusFile());
$alter = isset($status['time']) ? time() - (int)$status['time'] : null;
$laeuft = $alter !== null && $alter <= 10;
$basis = '?tab=';

$tabs = ['drucker' => 'Drucker', 'mqtt' => 'MQTT', 'loxone' => 'Einbindung in Loxone', 'test' => 'Test', 'logs' => 'Logdateien'];
// Menüleiste der LoxBerry-Oberfläche: $navbar muss vor lbheader() definiert sein
$navbar = [];
$nr = 10;
foreach ($tabs as $k => $t) {
    $navbar[$nr] = ['Name' => $t, 'URL' => $basis . $k] + ($tab === $k ? ['active' => true] : []);
    $nr += 10;
}
// UDP-Port des Gateways für Befehle aus Loxone (einstellbar, steht in den MQTT-Verbindungsdaten)
$udp = GatewayInfo::udpPort(function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : null, GatewayInfo::general());

// 4. Parameter true: LoxBerry Design System (lb-*-Klassen) statt jQuery Mobile
LBWeb::lbheader('Bambulab2Lox ' . AppVersion::CURRENT, '', '', true);
?>
<style>
 .bbl pre { background:var(--lb-gray-100,#f4f4f4); color:var(--lb-text,#222); padding:8px; overflow:auto; max-height:420px; border-radius:var(--lb-radius-sm,4px); }
 .bbl .ok { color:var(--lb-success,#2a8a2a); font-weight:bold; } .bbl .fehler { color:var(--lb-danger,#c0392b); font-weight:bold; }
 .bbl .hinweis { color:var(--lb-text-muted,#666); font-size:90%; }
 .bbl .lb-card { margin:12px 0; padding:4px 16px 12px 16px; }
 .bbl .bbl-opt { margin:6px 0; display:flex; align-items:flex-start; gap:8px; }
 .bbl .bbl-opt .lb-toggle { flex:none; }
 .bbl .lb-table td .lb-input, .bbl .lb-table td .lb-select { width:auto; max-width:100%; }
 .bbl .bbl-schmal { width:90px !important; } .bbl .bbl-mittel { width:150px !important; }
 .bbl .bbl-befehle label.lb-toggle { vertical-align:middle; }
 .bbl-led { display:inline-block; width:18px; height:18px; border-radius:3px; vertical-align:middle; border:1px solid rgba(0,0,0,.35); }
 .bbl-warn { color:var(--lb-warning,#b9770e); }
 .bbl-led-ok { background:var(--lb-success,#2ecc40); } .bbl-led-fehler { background:var(--lb-danger,#e74c3c); } .bbl-led-aus { background:var(--lb-gray-400,#bbb); }
</style>
<div class="lb-content bbl">
<?php foreach ($meldungen as $m) { echo '<div class="lb-callout' . (str_starts_with($m, 'FEHLER') ? ' lb-callout-warning' : '') . '"><span class="' . (str_starts_with($m, 'FEHLER') ? 'fehler' : 'ok') . '">' . h($m) . '</span></div>'; } ?>

<?php if ($tab === 'drucker') { ?>
<form method="post" action="<?= $basis ?>drucker">
<input type="hidden" name="token" value="<?= h($token) ?>">

<div class="lb-card">
<h3>Allgemein</h3>
<div class="lb-form-row"><label class="lb-form-label" for="pushall_abstand">Voller Zustand alle … Sekunden anfordern (60–3600)</label>
<div class="lb-form-field"><input class="lb-input bbl-schmal" type="number" id="pushall_abstand" name="pushall_abstand" min="60" max="3600" value="<?= (int)$bbl['pushall_abstand'] ?>"></div></div>
<p class="hinweis">Nur für Modelle, die ihren Zustand in Teilen melden (P1P, P1S, A1, A1 mini). X1, X1C, X1E und H2 senden bei jedem Report den vollen Zustand.</p>
<div class="bbl-opt"><?= schalter('hms_text', (bool)$bbl['hms_text'], 'HMS-Meldungen (Fehler und Warnungen des Druckers) als Text senden: <code>bambulab/&lt;name&gt;/fehler/hms_text</code>') ?></div>
</div>

<div class="lb-card">
<h3>Drucker</h3>
<div class="lb-callout">
<span class="lb-callout-title">Voraussetzungen am Drucker</span>
<p><b>Auslesen</b> funktioniert am H2C ohne LAN-Modus und ohne Entwicklermodus; Seriennummer und <b>Access Code</b> stehen am Drucker unter Einstellungen → WLAN/Netzwerk. Bei anderen Modellen kann der <b>LAN-Modus</b> nötig sein (Nur LAN-Modus schaltet die Bambu-Cloud für diesen Drucker ab; Bambu Studio im selben Netz funktioniert weiter, andere Programme dürfen parallel mitlesen).<br>
<b>Schaltbefehle:</b> Ohne Entwicklermodus ist <b>nur das Licht</b> schaltbar. Pause, Fortsetzen, Geschwindigkeit und Stopp nimmt die Firmware nur im <b>Entwicklermodus</b> an (Einstellungen → WLAN/Netzwerk → Nur LAN-Modus → Entwicklermodus). Der Entwicklermodus ist von Bambu nicht offiziell unterstützt. Lichter lassen sich auch ohne ihn schalten; Lesen geht in jedem Fall.</p>
</div>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Status</th><th>Aktiv</th><th>Name im Topic</th><th>Host/IP</th><th>Port</th><th>Seriennummer</th><th>Access Code</th><th>Befehle freigeben</th></tr>
<?php
$aktive = [];
foreach (BambuConfig::drucker($bbl) as $d) {
    $aktive[$d['host'] . ':' . $d['port'] . ':' . $d['seriennummer']] = $d['name'];
}
foreach ($bbl['drucker'] as $i => $g) {
    $name = $aktive[trim((string)$g['host']) . ':' . BambuConfig::cleanPort($g['port'] ?? 8883) . ':' . $g['seriennummer']] ?? null;
    $r = ($name !== null && $laeuft) ? ($status['drucker'][$name] ?? null) : null;
    if (empty($g['aktiv']) || $g['host'] === '') {
        [$led, $tip] = ['aus', $g['host'] === '' ? 'nicht eingerichtet' : 'nicht aktiv'];
    } elseif ($r === null) {
        [$led, $tip] = ['aus', $laeuft ? 'noch keine Meldung des Dienstes' : 'der Dienst läuft nicht'];
    } elseif (!empty($r['aus'])) {
        [$led, $tip] = ['aus', 'nicht erreichbar (ausgeschaltet?) – kein Fehler'];
    } elseif (empty($r['ok'])) {
        [$led, $tip] = ['fehler', (string)($r['error'] ?? 'Fehler')];
    } else {
        [$led, $tip] = ['ok', 'verbunden' . (!empty($r['modell']) ? ', ' . $r['modell'] : '') . (!empty($r['zustand_text']) ? ', ' . $r['zustand_text'] : '')];
    }
    ?>
<tr>
 <td><span class="bbl-led bbl-led-<?= $led ?>" title="<?= h($tip) ?>"></span> <span class="hinweis"><?= h($tip) ?></span>
<?php if ($r !== null && !empty($r['dev_modus_fehlt'])) { ?><br><span class="hinweis bbl-warn">Der Drucker lehnt Pause, Fortsetzen, Geschwindigkeit und Stopp ab: <b>Entwicklermodus</b> am Drucker einschalten. Ohne ihn ist nur das Licht schaltbar.</span><?php } ?></td>
 <td><?= schalter('drucker[' . $i . '][aktiv]', !empty($g['aktiv'])) ?></td>
 <td><input class="lb-input bbl-mittel" type="text" name="drucker[<?= $i ?>][name]" value="<?= h($g['name']) ?>" placeholder="drucker<?= $i + 1 ?>"></td>
 <td><input class="lb-input bbl-mittel" type="text" name="drucker[<?= $i ?>][host]" value="<?= h($g['host']) ?>"></td>
 <td><input class="lb-input bbl-schmal" type="number" name="drucker[<?= $i ?>][port]" value="<?= (int)BambuConfig::cleanPort($g['port'] ?? 8883) ?>"></td>
 <td><input class="lb-input bbl-mittel" type="text" name="drucker[<?= $i ?>][seriennummer]" value="<?= h($g['seriennummer']) ?>" autocomplete="off"></td>
 <td><input class="lb-input bbl-mittel" type="password" name="drucker[<?= $i ?>][zugangscode]" value="" autocomplete="new-password" placeholder="<?= $g['zugangscode'] !== '' ? '(gesetzt)' : '' ?>"></td>
 <td class="bbl-befehle">
<?php foreach (BambuConfig::STUFEN as $k => $t) { ?>
  <div class="bbl-opt"><?= schalter('drucker[' . $i . '][befehle][' . $k . ']', !empty($g['befehle'][$k]), h($t)) ?></div>
<?php } ?>
 </td>
</tr>
<?php } ?>
</table></div>
<p class="hinweis">Bis zu acht Drucker (alle Bambu-Lab-Modelle mit lokalem MQTT: X1, X1C, X1E, P1P, P1S, A1, A1 mini, H2D, H2C, H2S; geprüft nur am H2C). Der Name steckt im Topic (<code>bambulab/&lt;name&gt;/…</code>; nur a–z, 0–9, _ und -), leer = drucker1, drucker2 …
Der Access Code wird nie angezeigt; ein leeres Feld behält den gespeicherten Code. Der Drucker verwendet ein selbst signiertes Zertifikat: das Plugin prüft es nicht (Gerät im Heimnetz).
Ein ausgeschalteter Drucker ist kein Fehler (<code>bambulab/status/ok</code> bleibt 1, <code>verbunden</code> = 0); ein falscher Access Code oder eine verlorene Verbindung während eines Drucks schon.</p>
<p class="hinweis"><b>Befehle:</b> ab Werk gesperrt. Stufe <i>Licht</i>: Kammerlicht, zweites Kammerlicht, Arbeitslicht. Stufe <i>Pause, Fortsetzen, Geschwindigkeit</i>: greift in den laufenden Druck ein. Stufe <i>Stopp</i>: bricht den Druck ab. Die Quittung steht unter <code>…/quittung</code>.</p>
</div>

<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
</form>

<?php } elseif ($tab === 'mqtt') {
    $own = BambuConfig::ownBroker($bbl);
    $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
    $gw = GatewayInfo::subscription('bambulab/#', GatewayInfo::general());
    ?>
<h3>MQTT</h3>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th><?= $own !== null ? 'Broker (eigener, aus den Plugin-Einstellungen)' : 'Broker (aus der LoxBerry-Konfiguration)' ?></th><td><?= h(($d['brokerhost'] ?? '?') . ':' . ($d['brokerport'] ?? '?')) ?></td></tr>
<tr><th>Gateway-Abo</th><td><?= GatewayInfo::feldHtml($gw, 'bambulab/#') ?></td></tr>
<tr><th>Dienst</th><td><?= $laeuft ? '<span class="ok">läuft</span> (Version ' . h($status['version'] ?? '?') . ')' : '<span class="fehler">läuft nicht</span> (der Wächter startet ihn spätestens nach einer Minute)' ?></td></tr>
<tr><th>Broker-Verbindung des Dienstes</th><td><?= !empty($status['mqtt']) ? '<span class="ok">verbunden</span>' : '<span class="fehler">getrennt</span> ' . h($status['mqtt_error'] ?? '') ?></td></tr>
<tr><th>status/ok</th><td><?= isset($status['ok']) ? (int)$status['ok'] : '-' ?></td></tr>
<tr><th>status/zaehler</th><td><?= isset($status['zaehler']) ? (int)$status['zaehler'] : '-' ?></td></tr>
<tr><th>status/ts</th><td><?= !empty($status['ts']) ? h(date('d.m.Y H:i:s', (int)$status['ts'])) : '-' ?></td></tr>
</table></div>
<?php foreach (($status['drucker'] ?? []) as $a => $r) {
    echo '<p>' . h($a) . ': ' . (!empty($r['aus']) ? '<span class="ok">nicht erreichbar (ausgeschaltet?)</span> - kein Fehler' : (!empty($r['ok']) ? '<span class="ok">verbunden</span> (' . (int)($r['werte'] ?? 0) . ' Werte' . (!empty($r['modell']) ? ', ' . h($r['modell']) : '') . ')' : '<span class="fehler">' . h($r['error'] ?? 'Fehler') . '</span>')) . '</p>';
} ?>
<form method="post" action="<?= $basis ?>mqtt">
<input type="hidden" name="token" value="<?= h($token) ?>">
<h3>MQTT-Broker</h3>
<?php $mq = $bbl['mqtt']; ?>
<div class="bbl-opt"><?= schalter('mqtt[eigener_broker]', !empty($mq['eigener_broker']), 'Eigenen MQTT-Broker verwenden (statt dem Broker des LoxBerry)') ?></div>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Host/IP</th><td><input class="lb-input" type="text" name="mqtt[host]" value="<?= h($mq['host']) ?>"></td>
<th>Port</th><td><input class="lb-input bbl-schmal" type="number" name="mqtt[port]" value="<?= (int)$mq['port'] ?>"></td></tr>
<tr><th>Benutzer</th><td><input class="lb-input bbl-mittel" type="text" name="mqtt[benutzer]" value="<?= h($mq['benutzer']) ?>" autocomplete="off"></td>
<th>Passwort</th><td><input class="lb-input bbl-mittel" type="password" name="mqtt[passwort]" value="" autocomplete="new-password" placeholder="<?= $mq['passwort'] !== '' ? '(gesetzt)' : '' ?>"></td></tr>
</table></div>
<p class="hinweis">Ohne Haken verwendet das Plugin den MQTT-Broker aus der LoxBerry-Konfiguration (empfohlen, Zugangsdaten verwaltet LoxBerry). Mit eigenem Broker erreichen die Werte den Miniserver nur, wenn das MQTT-Gateway diesen Broker abonniert. Ein leeres Passwortfeld behält das gespeicherte Passwort. Dieser Broker ist <i>nicht</i> der des Druckers: die Verbindung zum Drucker richtet sich nach dem Reiter „Drucker“.</p>
<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
</form>

<?php } elseif ($tab === 'loxone') {
    $namen = array_map(fn($g) => $g['name'], BambuConfig::drucker($bbl)) ?: ['drucker1'];
    // [Topic (relativ zu bambulab/<name>/), Einheit, Bedeutung, Gruppe]
    $werte = [
        ['verbunden', '0/1', 'Verbindung zum Drucker steht und Reports kommen an', 'Status'],
        ['status_text', 'Text', 'Zustand des Druckers als Text: bereit, bereitet vor, druckt, pausiert, fertig, abgebrochen, Fehler', 'Status'],
        ['status_roh', 'Text', 'Zustand des Druckers, wie ihn die Firmware meldet: IDLE, PREPARE, RUNNING, PAUSE, FINISH, FAILED', 'Status'],
        ['zustand/leerlauf | druckt | pausiert | fertig | abgebrochen | fehler', '0/1', 'Zustand als Schalter (druckt schließt die Vorbereitung ein; abgebrochen = FAILED ohne Fehlercode, fehler = FAILED mit Fehlercode)', 'Status'],
        ['fortschritt_prozent | restzeit_min', '%, min', 'Fortschritt und Restzeit des Drucks', 'Druck'],
        ['schicht | schichten_gesamt', '-', 'Aktuelle und gesamte Schichtzahl (Ausreißer beim Start werden nicht gesendet)', 'Druck'],
        ['auftrag', 'Text', 'Name des Druckauftrags', 'Druck'],
        ['duese_c | duese_soll_c', '°C', 'Düse (die gerade aktive): Ist und Soll', 'Temperaturen'],
        ['bett_c | bett_soll_c', '°C', 'Druckbett: Ist und Soll', 'Temperaturen'],
        ['kammer_c', '°C', 'Kammer (X1, H2)', 'Temperaturen'],
        ['duese/<k>/temperatur_c | soll_c', '°C', 'H2: Ist und Soll je Düse (k = 0, 1)', 'Düsen (H2)'],
        ['duese/aktiv | duese/anzahl', '-', 'H2: Index der aktiven Düse (−1 = Wechsel läuft) und Zahl der Düsen', 'Düsen (H2)'],
        ['duesenwechsel', '0/1', 'H2C: 1, solange der Düsenhalter eine Düse tauscht', 'Düsen (H2)'],
        ['duesen/<id>/typ | durchmesser_mm | verschleiss', '-', 'H2C: Düsen im Halter (Typ, Durchmesser, Verschleiß); leere Plätze fehlen', 'Düsen (H2)'],
        ['luefter/bauteil_prozent | hilfs_prozent | kammer_prozent | duesenkuehlung_prozent', '%', 'Lüfter (Bambu meldet 0–15, umgerechnet in Prozent)', 'Lüfter'],
        ['geschwindigkeit/stufe | prozent', '1–4, %', 'Geschwindigkeit: 1 leise, 2 standard, 3 sport, 4 irrsinn', 'Druck'],
        ['licht/kammer | kammer2 | arbeit', '0/1', 'Lichter (Arbeitslicht: blinkend im Ruhezustand = 0)', 'Licht'],
        ['wlan_dbm', 'dBm', 'WLAN-Signal des Druckers', 'Status'],
        ['fehler/code | hms_anzahl | hms_text', '-', 'Fehlercode, Zahl der HMS-Meldungen und ihre Codes (HMS_xxxx_xxxx_xxxx_xxxx, der Klartext steht bei Bambu online)', 'Fehler'],
        ['ams/<n>/temperatur_c | feuchte | feuchte_prozent | trocknung_min', '°C, 1–5, %, min', 'AMS-Einheit: Temperatur, Feuchte (Stufe 1–5, bei AMS 2 Pro auch Prozent), Trocknungszeit', 'AMS'],
        ['ams/<n>/slot/<k>/belegt | typ | farbe | rest_prozent', '0/1, Text, RRGGBB, %', 'Slot: belegt, Filamenttyp, Farbe, Restmenge (unbekannt = nicht gesendet)', 'AMS'],
        ['ams/aktiv_slot', '-', 'Aktiver Slot: 254/255 = externe Spule, sonst AMS × 4 + Slot', 'AMS'],
        ['extern/<k>/typ | farbe', 'Text', 'H2: externe Spulen (k = 0, 1); X1/P1: extern/typ und extern/farbe', 'AMS'],
        ['info/modell | firmware', 'Text', 'Modell und Firmware (aus get_version)', 'Info'],
        ['info/duesen_durchmesser_mm | duesen_typ', 'mm, Text', 'Düse laut Drucker', 'Info'],
    ];
    ?>
<h3>Einbindung in Loxone</h3>
<p>Der Eingangsname ist das Topic mit _ statt /. Die Werte kommen über das MQTT-Gateway (Abo bambulab/#) als virtuelle Eingänge, z. B. <code>bambulab_drucker1_fortschritt_prozent</code>.
Werte, die der Drucker nicht liefert, werden nicht gesendet. Gesendet werden nur Änderungen und alle 5 Minuten alle Werte.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Gruppe</th><th>Topic</th><th>Eingangsname</th><th>Einheit</th><th>Bedeutung</th><th>retained</th></tr>
<?php
$einzel = fn(string $t) => !preg_match('/[|<]/', $t);     // genau ein festes Topic?
foreach ($namen as $a) {
    foreach ($werte as $w) {
        $topic = 'bambulab/' . $a . '/' . $w[0];
        $name = $einzel($w[0]) ? str_replace('/', '_', $topic) : '(Topic mit _ statt /)';
        echo '<tr><td>' . h($w[3]) . '</td><td>' . h($topic) . '</td><td>' . h($name) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
    }
}
foreach ([['bambulab/status/ok', '0/1', 'Alle Drucker in Ordnung (ein ausgeschalteter Drucker ist kein Fehler; No Cache)'], ['bambulab/status/zaehler', '0–999', 'Lebenszeichen, zählt alle 10 s (No Cache)'],
    ['bambulab/status/ts', 's', 'Unix-Zeitstempel des letzten Lebenszeichens']] as $w) {
    echo '<tr><td>Status</td><td>' . h($w[0]) . '</td><td>' . h(str_replace('/', '_', $w[0])) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
}
?>
</table></div>
<p class="hinweis">Beim Absturz des Dienstes setzt der Broker bambulab/status/ok per Letzter-Wille-Nachricht auf 0 (retained).</p>

<h3>Befehle aus Loxone</h3>
<p>Gesperrt, bis sie je Drucker im Reiter „Drucker“ freigegeben sind. Virtueller Ausgang: Adresse <code>/dev/udp/&lt;LoxBerry-IP&gt;/<?= (int)$udp ?></code>,
Befehl bei EIN <code>publish bambulab/befehl/&lt;name&gt;/&lt;befehl&gt; &lt;wert&gt;</code> (ohne HTTP-Header/Body, Trennzeichen leer). Die Quittung steht unter <code>…/&lt;befehl&gt;/quittung</code>:
<code>angenommen</code>, dann <code>ausgefuehrt</code> oder <code>abgelehnt: &lt;grund&gt;</code> (nie retained). Höchstens ein Befehl je Sekunde und Drucker; nur bei bestehender Verbindung.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Topic</th><th>Wert</th><th>Stufe</th><th>Wirkung</th></tr>
<?php
foreach ($namen as $a) {
    foreach (BambuCommands::LISTE as $k => [$stufe, , $text]) {
        $w = $k === 'geschwindigkeit' ? '1–4' : (str_starts_with($k, 'licht') ? '1 oder 0' : '1');
        echo '<tr><td>bambulab/befehl/' . h($a) . '/' . h($k) . '</td><td>' . h($w) . '</td><td>' . h(BambuConfig::STUFEN[$stufe]) . '</td><td>' . h($text) . '</td></tr>';
    }
}
?>
</table></div>
<p class="hinweis">Pause, Fortsetzen, Geschwindigkeit und Stopp lehnt die Firmware ohne Entwicklermodus ab (Quittung: „abgelehnt: Drucker verlangt den Entwicklermodus“). Nie vorgesehen: G-Code senden, Druck starten, Firmware, Kalibrierung.</p>

<?php } elseif ($tab === 'test') { ?>
<h3>Test</h3>
<form method="post" action="<?= $basis ?>test">
<input type="hidden" name="token" value="<?= h($token) ?>">
<button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="abruf">Sofort abrufen</button>
<button class="lb-btn" type="submit" name="aktion" value="selbst">Selbstprüfung</button>
<button class="lb-btn lb-btn-danger" type="submit" name="aktion" value="neustart">Dienst neu starten</button>
</form>
<?php
    $a = (string)($_POST['aktion'] ?? '');
    $drucker = BambuConfig::drucker($bbl);
    /** Kurz mit dem Drucker verbinden (TLS, Access Code), Zustand anfordern und warten. @return array{0:?array,1:array,2:string} [print-Zustand, Version, Fehler] */
    $holen = function (array $dev, int $sekunden): array {
        $m = new MqttClient($dev['host'], $dev['port'], 'bblp', $dev['zugangscode'], 'lox_test_' . substr(md5((string)microtime()), 0, 6), '', 60, true, 4);
        $report = 'device/' . $dev['seriennummer'] . '/report';
        $m->subscribe($report);
        $m->acceptRetained(true);
        if (!$m->connect()) {
            return [null, [], $m->lastError()];
        }
        $req = 'device/' . $dev['seriennummer'] . '/request';
        $m->publish($req, json_encode(['info' => ['sequence_id' => '1', 'command' => 'get_version']]));
        $m->publish($req, json_encode(['pushing' => ['sequence_id' => '2', 'command' => 'pushall', 'version' => 1, 'push_target' => 1]]));
        $print = [];
        $ver = [];
        $ende = microtime(true) + $sekunden;
        while (microtime(true) < $ende) {
            $m->loop();
            foreach ($m->takeMessages() as [$t, $p]) {
                $o = json_decode($p, true);
                if (!is_array($o)) {
                    continue;
                }
                if (is_array($o['print'] ?? null) && ($o['print']['command'] ?? '') === 'push_status') {
                    $print = BambuState::merge($print, $o['print']);
                }
                if (is_array($o['info']['module'] ?? null)) {
                    $ver = BambuMapper::version($o['info']['module']);
                }
            }
            if ($print && $ver) {
                break;
            }
            usleep(100000);
        }
        $m->close();
        return [$print ?: null, $ver, $print ? '' : 'Keine Antwort des Druckers innerhalb von ' . $sekunden . ' s'];
    };
    if ($a === 'abruf') {
        if (!$drucker) {
            echo '<p class="fehler">Kein Drucker konfiguriert (aktiv, mit Host und Seriennummer).</p>';
        }
        foreach ($drucker as $dev) {
            echo '<h4>' . h($dev['name']) . ' (' . h($dev['host'] . ':' . $dev['port']) . ')</h4>';
            [$print, $ver, $err] = $holen($dev, 8);
            if ($print === null) {
                echo '<p class="fehler">' . h($err) . '</p>';
                continue;
            }
            echo '<div class="lb-table-scroll"><table class="lb-table"><tr><th>Topic</th><th>Wert</th></tr>';
            foreach (BambuMapper::map($print, $ver, (bool)$bbl['hms_text']) as $k => $v) {
                echo '<tr><td>bambulab/' . h($dev['name'] . '/' . $k) . '</td><td>' . h($v) . '</td></tr>';
            }
            echo '</table></div>';
        }
    } elseif ($a === 'selbst') {
        $zeile = function (string $name, bool $ok, string $text): void {
            echo '<tr><td>' . h($name) . '</td><td class="' . ($ok ? 'ok' : 'fehler') . '">' . ($ok ? 'OK' : 'Fehler') . '</td><td>' . h($text) . '</td></tr>';
        };
        echo '<div class="lb-table-scroll"><table class="lb-table"><tr><th>Prüfung</th><th>Ergebnis</th><th>Details</th></tr>';
        $zeile('Konfiguration', count($drucker) > 0, count($drucker) . ' Drucker konfiguriert');
        foreach ($drucker as $dev) {
            [$print, $ver, $err] = $holen($dev, 6);
            $zeile('Drucker ' . $dev['name'], $print !== null, $print !== null
                ? 'TLS und Anmeldung in Ordnung, ' . ($ver['modell'] ?? 'Modell unbekannt') . ', Firmware ' . ($ver['firmware'] ?? '?') . ', Zustand ' . (isset($print['gcode_state']) ? BambuMapper::zustandText((string)$print['gcode_state'], (int)($print['print_error'] ?? 0) !== 0) : '?')
                : $err);
        }
        $own = BambuConfig::ownBroker($bbl);
        $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1], 'brokeruser' => $own[2], 'brokerpass' => $own[3]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
        $mq = new MqttClient((string)($d['brokerhost'] ?? 'localhost'), (int)($d['brokerport'] ?? 1883), (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? ''), 'bambulab_test_' . $paths->folder, 'bambulab/status/test');
        $okm = $mq->connect();
        $zeile('Broker', $okm, $okm ? 'Verbindung möglich' : $mq->lastError());
        $mq->close();
        $gw = GatewayInfo::subscription('bambulab/#', GatewayInfo::general());
        $zeile('Gateway-Abo', $gw['state'] === 'ok', $gw['text']);
        $zeile('Dienst', $laeuft, $laeuft ? 'läuft, letzte Statusmeldung vor ' . $alter . ' s' : 'keine aktuelle Statusmeldung');
        echo '</table></div>';
    }
} elseif ($tab === 'logs') {
    echo '<h3>Logdateien</h3>';
    $dateien = glob(LBPLOGDIR . '/*.log') ?: [];
    if (!$dateien) {
        echo '<p>Noch keine Logdatei vorhanden.</p>';
    }
    foreach ($dateien as $f) {
        $size = (int)filesize($f);
        $text = (string)@file_get_contents($f, false, null, max(0, $size - 20000));   // nur das Ende lesen
        $zeilen = array_slice(explode("\n", $text), -200);
        // Beim allerersten Start meldet die LoxBerry-Logbibliothek, dass die neue Logdatei noch nicht in ihrer Datenbank steht. Harmlos, nicht anzeigen.
        $zeilen = array_filter($zeilen, fn($z) => !str_contains($z, 'log_db_queryid: Could not find database entry'));
        echo '<h4>' . h(basename($f)) . ' (letzte Zeilen)</h4><pre>' . h(implode("\n", $zeilen)) . '</pre>';
    }
}
?>
</div>
<?php LBWeb::lbfooter(); ?>
