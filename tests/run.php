<?php
/**
 * Test-Runner ohne Abhaengigkeiten: php tests/run.php   (unter Windows ohne php.ini: php -d extension_dir=<php>/ext -d extension=openssl tests/run.php)
 * Teil 1: Unit-Tests (Konfiguration, Mapper gegen die anonymisierten H2C-Reports, Zustand zusammenfuehren, Befehle)
 * Teil 2: Ende-zu-Ende: der Dienst (bambulabd.php im Entwicklungsmodus) gegen Fake-Drucker (MQTT mit TLS) und Fake-Broker.
 * Teil 3: Healthcheck, Weboberflaeche mit Attrappen, build.sh und ZIP.
 * Nicht getestet werden koennen hier: LoxBerry-Bibliotheken, das echte Aussehen der Oberflaeche, echte Drucker (nur ein H2C wurde von Hand
 * geprueft; X1, P1, A1 nur nach Dokumentation), das echte Zertifikat der Drucker und der Dienst auf dem LoxBerry.
 */
$root = dirname(__DIR__);
date_default_timezone_set('Europe/Berlin');
// Gemeinsamer Code direkt aus ../common (im ZIP liegt er in bin/lib)
foreach (['Util', 'Logger', 'MqttClient', 'GatewayInfo'] as $c) {
    require_once $root . '/../common/php/' . $c . '.php';
}
foreach (['BambuConfig', 'BambuState', 'BambuMapper', 'BambuCommands', 'BambuPrinter', 'AppVersion'] as $c) {
    require_once $root . '/bambulab2lox/bin/lib/' . $c . '.php';
}

$fails = 0;
$count = 0;
function check(string $name, $actual, $expected): void
{
    global $fails, $count;
    $count++;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL $name: erwartet " . var_export($expected, true) . ", erhalten " . var_export($actual, true) . "\n";
    }
}
function waitFor(callable $f, float $timeout = 10.0): bool
{
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        if ($f()) {
            return true;
        }
        usleep(100000);
    }
    return false;
}
function startProc(array $cmd, array $env = []): array
{
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/bbl_test_out.txt', 'a'], 2 => ['file', sys_get_temp_dir() . '/bbl_test_err.txt', 'a']], $pipes, null, $env ? array_merge(getenv(), $env) : null);
    return [$p, $pipes];
}
function stopProc(array $pp): void
{
    if (!is_resource($pp[0])) {
        return;
    }
    @proc_terminate($pp[0]);
    foreach ($pp[1] as $h) {
        @fclose($h);
    }
    @proc_close($pp[0]);
}
/** Selbstsigniertes Testzertifikat (nur fuer den Fake-Drucker, wird bei jedem Lauf neu erzeugt). Unter Windows ohne openssl.cnf mit eigener Minimalkonfiguration. */
function makeCert(string $pem): bool
{
    if (!extension_loaded('openssl')) {
        return false;
    }
    $cnf = sys_get_temp_dir() . '/bbl_test_openssl.cnf';
    file_put_contents($cnf, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=localhost\n");
    $o = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $k = @openssl_pkey_new($o);
    if (!$k) {
        return false;
    }
    $csr = openssl_csr_new(['commonName' => 'localhost'], $k, $o);
    $crt = openssl_csr_sign($csr, null, $k, 2, $o);
    openssl_x509_export($crt, $c);
    openssl_pkey_export($k, $kk, null, $o);
    return file_put_contents($pem, $c . $kk) !== false;
}
function fixture(string $name): array
{
    return json_decode((string)file_get_contents(__DIR__ . '/fixtures/' . $name), true);
}

$tmp = __DIR__ . '/tmp';
@mkdir($tmp, 0777, true);
$dir = $tmp . '/t' . getmypid();
@mkdir($dir, 0777, true);

// ---------------------------------------------------------------- Fixtures: anonymisiert?
$fixTxt = '';
foreach (glob(__DIR__ . '/fixtures/*.json') as $f) {
    $fixTxt .= (string)file_get_contents($f);
}
preg_match_all('/"sn": "([^"]*)"/', $fixTxt, $sn);
check('Fixtures: Seriennummern der Module sind nullen oder N/A', array_values(array_filter(array_unique($sn[1]), fn($s) => $s !== 'N/A' && trim($s, '0') !== '')), []);
check('Fixtures: keine private IP-Adresse (nur 192.0.2.x aus dem Dokumentationsbereich)', (bool)preg_match('/\b(192\.168|10\.\d+|172\.(1[6-9]|2\d|3[01]))\.\d+\.\d+\b/', $fixTxt), false);
check('Fixtures: Auftragsname ist ein Platzhalter', (bool)preg_match('/"subtask_name": "(?!Testauftrag")[^"]+"/', $fixTxt), false);

// ---------------------------------------------------------------- Unit: Zahlenformat, Packwerte, Luefter, HMS
check('num ganzzahl', BambuMapper::num(2500), '2500');
check('num Float', BambuMapper::num(30.1), '30.1');
check('num Float ohne Nullen', BambuMapper::num(12.0), '12');
check('num Text mit Zahl', BambuMapper::num('22.7'), '22.7');
check('num Zahltext ganz', BambuMapper::num('5'), '5');
check('num leer', BambuMapper::num(''), null);
check('num Text', BambuMapper::num('abc'), null);
check('num null', BambuMapper::num(null), null);
check('num minus null', BambuMapper::num(-0.0), '0');
check('gepackt: 48 Ist, 50 Soll', BambuMapper::gepackt(3276848), [48, 50]);
check('gepackt: klein = nur Ist', BambuMapper::gepackt(36), [36, null]);
check('gepackt: Zahltext', BambuMapper::gepackt('55'), [55, null]);
check('gepackt: Bett 55/55', BambuMapper::gepackt(3604535), [55, 55]);
check('gepackt: Unsinn', BambuMapper::gepackt('abc'), null);
check('Luefter 15 = 100 %', BambuMapper::luefterProzent('15'), '100');
check('Luefter 0', BambuMapper::luefterProzent('0'), '0');
check('Luefter 14 = 93 %', BambuMapper::luefterProzent('14'), '93');
check('Luefter 4 = 27 %', BambuMapper::luefterProzent(4), '27');
check('Luefter Unsinn', BambuMapper::luefterProzent('x'), null);
check('HMS-Text', BambuMapper::hmsText(['attr' => 83887616, 'code' => 131078]), 'HMS_0500_0600_0002_0006');
check('HMS-Text ohne Felder', BambuMapper::hmsText(['attr' => 1]), null);
check('teilUpdates P1S', BambuMapper::teilUpdates('Bambu Lab P1S'), true);
check('teilUpdates A1 mini', BambuMapper::teilUpdates('Bambu Lab A1 mini'), true);
check('teilUpdates H2C', BambuMapper::teilUpdates('Bambu Lab H2C'), false);
check('teilUpdates X1C', BambuMapper::teilUpdates('Bambu Lab X1 Carbon'), false);
check('teilUpdates unbekannt', BambuMapper::teilUpdates(null), false);

// ---------------------------------------------------------------- Unit: Mapper gegen die H2C-Reports
$gv = fixture('h2c_get_version.json');
$ver = BambuMapper::version($gv['info']['module']);
check('Version: Modell und Firmware aus dem Modul ota', $ver, ['modell' => 'Bambu Lab H2C', 'firmware' => '01.03.00.00']);
$pD = fixture('h2c_druckt.json')['print'];
$m = BambuMapper::map($pD, $ver);
check('H2C druckt: status_text deutsch, status_roh unveraendert', [$m['status_text'], $m['status_roh']], ['druckt', 'RUNNING']);
check('H2C druckt: Zustand druckt/pausiert/fertig/leerlauf', [$m['zustand/druckt'], $m['zustand/pausiert'], $m['zustand/fertig'], $m['zustand/leerlauf']], ['1', '0', '0', '0']);
check('H2C druckt: Fortschritt, Restzeit', [$m['fortschritt_prozent'], $m['restzeit_min']], ['22', '42']);
check('H2C druckt: Schicht und Gesamtzahl', [$m['schicht'], $m['schichten_gesamt']], ['1', '30']);
check('H2C druckt: Auftrag', $m['auftrag'], 'Testauftrag');
check('H2C druckt: Duese Ist/Soll', [$m['duese_c'], $m['duese_soll_c']], ['220', '220']);
check('H2C druckt: Bett Ist/Soll', [$m['bett_c'], $m['bett_soll_c'] ?? null], ['55', '55']);
check('H2C druckt: Kammer aus device.ctc (kein chamber_temper)', $m['kammer_c'], '28');
check('H2C druckt: Duese 0 gepackt (Ist 220, Soll 220)', [$m['duese/0/temperatur_c'], $m['duese/0/soll_c']], ['220', '220']);
check('H2C druckt: Duese 1 nur Ist', [$m['duese/1/temperatur_c'], isset($m['duese/1/soll_c'])], ['39', false]);
check('H2C druckt: aktive Duese 0 von 2', [$m['duese/aktiv'], $m['duese/anzahl']], ['0', '2']);
check('H2C druckt: kein Duesenwechsel', $m['duesenwechsel'], '0');
check('H2C druckt: Luefter in Prozent', [$m['luefter/bauteil_prozent'], $m['luefter/kammer_prozent'], $m['luefter/duesenkuehlung_prozent']], ['0', '27', '93']);
check('H2C druckt: Geschwindigkeit', [$m['geschwindigkeit/stufe'], $m['geschwindigkeit/prozent']], ['2', '100']);
check('H2C druckt: WLAN', $m['wlan_dbm'], '-66');
check('H2C druckt: HMS Anzahl und erster Text', [$m['fehler/hms_anzahl'], explode(',', $m['fehler/hms_text'])[0]], ['5', 'HMS_0500_0600_0002_0006']);
check('H2C druckt: Fehlercode 0', $m['fehler/code'], '0');
check('H2C druckt: AMS lite meldet -50 Grad = ungueltig, nicht gesendet', isset($m['ams/0/temperatur_c']), false);
check('H2C druckt: AMS 2 Pro Temperatur, Feuchte und Feuchte in Prozent', [$m['ams/1/temperatur_c'], $m['ams/1/feuchte'], $m['ams/1/feuchte_prozent']], ['30.1', '1', '41']);
check('H2C druckt: AMS ohne Prozentwert (humidity_raw 0)', [$m['ams/0/feuchte'], isset($m['ams/0/feuchte_prozent'])], ['5', false]);
check('H2C druckt: aktiver Slot', $m['ams/aktiv_slot'], '2');
check('H2C druckt: Modell, Firmware, Duesentyp', [$m['info/modell'], $m['info/firmware'], $m['info/duesen_typ'], $m['info/duesen_durchmesser_mm']], ['Bambu Lab H2C', '01.03.00.00', 'HS01-0.4', '0.4']);
check('H2C druckt: die Seriennummer wird nicht gesendet', array_filter(array_keys($m), fn($k) => str_contains($k, 'serien')), []);
$aktiveDuesen = array_filter($pD['device']['nozzle']['info'], fn($n) => ($n['sn'] ?? '') !== 'N/A' && ($n['type'] ?? '') !== '');
check('H2C druckt: Duesenhalter, leere Plaetze fehlen', count(array_filter(array_keys($m), fn($k) => (bool)preg_match('#^duesen/\d+/typ$#', $k))), count($aktiveDuesen));
foreach ($pD['lights_report'] as $l) {
    $t = ['chamber_light' => 'kammer', 'work_light' => 'arbeit', 'chamber_light2' => 'kammer2'][$l['node']];
    check('H2C druckt: Licht ' . $t, $m['licht/' . $t], $l['mode'] === 'on' ? '1' : '0');
}
check('H2C druckt: Arbeitslicht blinkt im Ruhezustand = 0', $m['licht/arbeit'], '0');
check('H2C druckt: AMS Slot belegt mit Typ und Farbe', [$m['ams/1/slot/0/belegt'], isset($m['ams/1/slot/0/typ']), (bool)preg_match('/^[0-9A-F]{6}$/', $m['ams/1/slot/0/farbe'] ?? '')], ['1', true, true]);
check('H2C druckt: externe Spule(n) aus vir_slot', isset($m['extern/0/typ']), true);
foreach ($m as $k => $v) {
    if (!is_string($v)) {
        check("Topic $k ist Text", gettype($v), 'string');
    }
    if (preg_match('/\s/', $k)) {
        check("Topic $k ohne Leerzeichen", $k, 'ohne');
    }
}
$pI = fixture('h2c_leerlauf_fertig.json')['print'];
$mi = BambuMapper::map($pI, $ver);
check('H2C fertig: Zustand', [$mi['status_text'], $mi['status_roh'], $mi['zustand/fertig'], $mi['zustand/druckt']], ['fertig', 'FINISH', '1', '0']);
check('H2C fertig: Schicht = Gesamtzahl', [$mi['schicht'], $mi['schichten_gesamt']], ['460', '460']);
check('H2C fertig: kein Soll fuer Duese 1', isset($mi['duese/1/soll_c']), false);
check('Mapper: ohne print leer', BambuMapper::map([]), []);

// Zustaende
foreach ([['IDLE', 0, 'zustand/leerlauf'], ['PREPARE', 0, 'zustand/druckt'], ['RUNNING', 0, 'zustand/druckt'], ['PAUSE', 0, 'zustand/pausiert'], ['FINISH', 0, 'zustand/fertig'],
    ['FAILED', 0, 'zustand/abgebrochen'], ['FAILED', 50348044, 'zustand/fehler']] as [$st, $err, $topic]) {
    $r = BambuMapper::map(['gcode_state' => $st, 'print_error' => $err]);
    check("Zustand $st (Fehler $err) -> $topic", $r[$topic], '1');
    check("Zustand $st (Fehler $err): genau ein Zustand", count(array_filter($r, fn($v, $k) => str_starts_with($k, 'zustand/') && $v === '1', ARRAY_FILTER_USE_BOTH)), 1);
}
// Klartext des Zustands (kein "FAILED" in der Oberflaeche und in Loxone)
foreach ([['IDLE', 0, 'bereit'], ['PREPARE', 0, 'bereitet vor'], ['RUNNING', 0, 'druckt'], ['PAUSE', 0, 'pausiert'], ['FINISH', 0, 'fertig'], ['FAILED', 0, 'abgebrochen'], ['FAILED', 50348044, 'Fehler']] as [$st, $err, $text]) {
    $r = BambuMapper::map(['gcode_state' => $st, 'print_error' => $err]);
    check("Klartext $st (Fehler $err) -> $text", [$r['status_text'], $r['status_roh']], [$text, $st]);
}
// Plausibilitaet der Schichtzahl: beim Start meldet der H2C kurz die Schichtzahl des vorigen Drucks
check('Schicht-Ausreisser (460 bei 30 gesamt) wird nicht gesendet', isset(BambuMapper::map(['gcode_state' => 'RUNNING', 'layer_num' => 460, 'total_layer_num' => 30])['schicht']), false);
check('Schicht in der Vorbereitung wird nicht gesendet', isset(BambuMapper::map(['gcode_state' => 'PREPARE', 'layer_num' => 3, 'total_layer_num' => 30])['schicht']), false);
check('Schicht plausibel wird gesendet', BambuMapper::map(['gcode_state' => 'RUNNING', 'layer_num' => 3, 'total_layer_num' => 30])['schicht'], '3');
check('Schichten gesamt 0 wird nicht gesendet', isset(BambuMapper::map(['gcode_state' => 'IDLE', 'layer_num' => 0, 'total_layer_num' => 0])['schichten_gesamt']), false);
// Duesenwechsel und Umschalten
$w = BambuMapper::map(['device' => ['extruder' => ['state' => 33042, 'info' => []], 'holder' => ['stat' => 9]]]);
check('Duesenwechsel laeuft (holder.stat 9)', $w['duesenwechsel'], '1');
check('Umschalten (state mit 0xF in Bits 4-7) = -1', BambuMapper::map(['device' => ['extruder' => ['state' => 41458]]])['duese/aktiv'], '-1');
check('Duese 1 aktiv', BambuMapper::map(['device' => ['extruder' => ['state' => 33042]]])['duese/aktiv'], '1');
check('Gepackte Duesentemperatur beim Aufheizen', BambuMapper::map(['device' => ['extruder' => ['info' => [['id' => 0, 'temp' => 3276848]]]]])['duese/0/soll_c'], '50');
// X1/P1-Stil: chamber_temper und vt_tray
$x = BambuMapper::map(['chamber_temper' => 41.5, 'vt_tray' => ['tray_type' => 'PETG', 'tray_color' => 'FF8800FF', 'id' => '254'],
    'ams' => ['ams' => [['id' => '0', 'humidity' => '3', 'temp' => '24.5', 'tray' => [['id' => '0', 'tray_type' => 'PLA', 'tray_color' => '00AE42FF', 'remain' => -1], ['id' => '1']]]], 'tray_now' => '254']]);
check('X1/P1: Kammer aus chamber_temper', $x['kammer_c'], '41.5');
check('X1/P1: externe Spule', [$x['extern/typ'], $x['extern/farbe']], ['PETG', 'FF8800']);
check('AMS: Restmenge -1 (unbekannt) wird nicht gesendet', isset($x['ams/0/slot/0/rest_prozent']), false);
check('AMS: leerer Slot ist nicht belegt', [$x['ams/0/slot/1/belegt'], isset($x['ams/0/slot/1/typ'])], ['0', false]);
check('AMS: Slot belegt, Farbe ohne Alpha', [$x['ams/0/slot/0/belegt'], $x['ams/0/slot/0/farbe']], ['1', '00AE42']);
check('AMS: aktiv_slot 254 = externe Spule', $x['ams/aktiv_slot'], '254');
check('HMS-Text abschaltbar', isset(BambuMapper::map(['hms' => [['attr' => 83887616, 'code' => 131078]]], [], false)['fehler/hms_text']), false);
check('HMS leer = keine', BambuMapper::map(['hms' => []])['fehler/hms_text'], 'keine');

// ---------------------------------------------------------------- Unit: Zustand zusammenfuehren
$voll = fixture('h2c_druckt.json')['print'];
$s1 = BambuState::merge([], $voll);
check('Merge in leeren Zustand = Original', $s1, $voll);
$s2 = BambuState::merge($s1, ['ams' => ['ams' => [['id' => '1', 'tray' => [['id' => '0', 'remain' => 33]]]]], 'mc_percent' => 50]);
check('Teil-Update: Restmenge des Slots geaendert', $s2['ams']['ams'][1]['tray'][0]['remain'], 33);
check('Teil-Update: Typ des Slots bleibt', $s2['ams']['ams'][1]['tray'][0]['tray_type'], $voll['ams']['ams'][1]['tray'][0]['tray_type']);
check('Teil-Update: gleiche Zahl AMS-Einheiten und Slots', [count($s2['ams']['ams']), count($s2['ams']['ams'][1]['tray'])], [count($voll['ams']['ams']), count($voll['ams']['ams'][1]['tray'])]);
check('Teil-Update: einfacher Wert', [$s2['mc_percent'], $s2['layer_num']], [50, $voll['layer_num']]);
$s3 = BambuState::merge($s1, ['ams' => ['ams' => [['id' => '5', 'temp' => '20.0']]]]);
check('Neue AMS-Einheit wird angehaengt', array_column($s3['ams']['ams'], 'id'), array_merge(array_column($voll['ams']['ams'], 'id'), ['5']));
$s4 = BambuState::merge($s1, ['lights_report' => [['node' => 'chamber_light', 'mode' => 'off']]]);
check('Licht: ueber den Knoten zusammengefuehrt', [count($s4['lights_report']), array_column($s4['lights_report'], 'mode', 'node')['chamber_light'], array_column($s4['lights_report'], 'mode', 'node')['chamber_light2']],
    [count($voll['lights_report']), 'off', array_column($voll['lights_report'], 'mode', 'node')['chamber_light2']]);
$s5 = BambuState::merge($s1, ['hms' => []]);
check('HMS-Liste wird ersetzt', $s5['hms'], []);
$s6 = BambuState::merge($s1, ['device' => ['nozzle' => ['info' => [['id' => 17, 'type' => 'HS01', 'diameter' => 0.4, 'sn' => 'N/A']]]]]);
check('Duesenliste wird ersetzt (ids wechseln beim Duesenwechsel)', count($s6['device']['nozzle']['info']), 1);
check('Merge im Objekt device bleibt sonst erhalten', isset($s6['device']['extruder']['info']), true);
$s7 = BambuState::merge($s1, ['device' => ['extruder' => ['state' => 33042, 'info' => [['id' => 1, 'temp' => 77]]]]]);
check('Extruder ueber id zusammengefuehrt', [count($s7['device']['extruder']['info']), $s7['device']['extruder']['info'][1]['temp'], $s7['device']['extruder']['info'][0]['temp']],
    [2, 77, $voll['device']['extruder']['info'][0]['temp']]);

// ---------------------------------------------------------------- Unit: Konfiguration
$d0 = BambuConfig::defaults();
check('Vorgabe: acht Drucker, erster heisst drucker1, nichts aktiv', [count($d0['drucker']), $d0['drucker'][0]['name'], count(array_filter($d0['drucker'], fn($g) => $g['aktiv']))], [8, 'drucker1', 0]);
check('Vorgabe: alle Befehle gesperrt', array_unique(array_merge(...array_map(fn($g) => array_values($g['befehle']), $d0['drucker']))), [false]);
check('Vorgabe: Port 8883, voller Zustand alle 300 s, HMS-Text an', [$d0['drucker'][0]['port'], $d0['pushall_abstand'], $d0['hms_text']], [8883, 300, true]);
check('Keine Drucker aktiv = leere Liste', BambuConfig::drucker($d0), []);
$cf = $dir . '/cfg.json';
file_put_contents($cf, json_encode(['drucker' => [['aktiv' => true, 'name' => 'Werkstatt Drucker', 'host' => '10.0.0.5', 'seriennummer' => 'ab12', 'zugangscode' => 'geheim1234']], 'pushall_abstand' => 5]));
$lc = BambuConfig::load($cf);
check('Laden: fehlende Schluessel (Port, Befehle) werden mit Standard ergaenzt', [$lc['drucker'][0]['port'], $lc['drucker'][0]['befehle']['licht'], count($lc['drucker'])], [8883, false, 8]);
check('Laden: voller Zustand mindestens 60 s', $lc['pushall_abstand'], 60);
$lst = BambuConfig::drucker($lc);
check('Drucker: Name bereinigt, Seriennummer gross, Host', [$lst[0]['name'], $lst[0]['seriennummer'], $lst[0]['host']], ['werkstatt_drucker', 'AB12', '10.0.0.5']);
$cfgDup = $lc;
$cfgDup['drucker'][1] = ['aktiv' => true, 'name' => 'Werkstatt Drucker', 'host' => 'h2', 'port' => 8883, 'seriennummer' => 'X2', 'zugangscode' => 'a', 'befehle' => []];
$cfgDup['drucker'][2] = ['aktiv' => true, 'name' => '', 'host' => 'h3', 'port' => 8883, 'seriennummer' => 'X3', 'zugangscode' => 'b', 'befehle' => []];
$cfgDup['drucker'][3] = ['aktiv' => true, 'name' => 'ohne', 'host' => 'h4', 'port' => 8883, 'seriennummer' => '', 'zugangscode' => 'c', 'befehle' => []];
check('Drucker: doppelte Namen bekommen eine Nummer, leere drucker<Zeile>, ohne Seriennummer ausgelassen', array_column(BambuConfig::drucker($cfgDup), 'name'), ['werkstatt_drucker', 'werkstatt_drucker_2', 'drucker3']);
$old = BambuConfig::load($cf);
$old['drucker'][0]['zugangscode'] = 'alt-code-1';
$new = BambuConfig::fromPost($old, ['drucker' => [0 => ['aktiv' => '1', 'name' => 'A B', 'host' => 'https://192.0.2.7/x', 'port' => '', 'seriennummer' => ' 01p00a 123 ', 'zugangscode' => '', 'befehle' => ['licht' => '1']]]]);
check('fromPost: leerer Access Code behaelt den gespeicherten', $new['drucker'][0]['zugangscode'], 'alt-code-1');
check('fromPost: Host ohne Schema, Port leer = 8883, Seriennummer bereinigt, Name', [$new['drucker'][0]['host'], $new['drucker'][0]['port'], $new['drucker'][0]['seriennummer'], $new['drucker'][0]['name']], ['192.0.2.7', 8883, '01P00A123', 'a_b']);
check('fromPost: nur Licht freigegeben', $new['drucker'][0]['befehle'], ['licht' => true, 'druck' => false, 'stopp' => false]);
$new2 = BambuConfig::fromPost($old, ['drucker' => [0 => ['aktiv' => '1', 'zugangscode' => 'neuer code 1']]]);
check('fromPost: neuer Access Code ersetzt, Leerraum entfernt', $new2['drucker'][0]['zugangscode'], 'neuercode1');
check('fromPost: ohne Drucker-Abschnitt bleibt alles', BambuConfig::fromPost($old, ['pushall_abstand' => '90', 'hms_text' => ''])['drucker'], $old['drucker']);
check('fromPost: voller Zustand 90 s, HMS-Text aus', [BambuConfig::fromPost($old, ['pushall_abstand' => '90'])['pushall_abstand'], BambuConfig::fromPost($old, ['pushall_abstand' => '90'])['hms_text']], [90, false]);
check('fromPost: Port ausserhalb wird begrenzt', BambuConfig::cleanPort(99999), 65535);
$mq = BambuConfig::fromPost($old, ['mqtt' => ['eigener_broker' => '1', 'host' => 'broker.lan', 'port' => '1884', 'benutzer' => 'u', 'passwort' => '']]);
check('fromPost: MQTT-Passwort leer behaelt altes', $mq['mqtt']['passwort'], $old['mqtt']['passwort']);
check('ownBroker', BambuConfig::ownBroker($mq), ['broker.lan', 1884, 'u', '']);
check('ownBroker aus: null', BambuConfig::ownBroker($old), null);
check('Geheimnisse der Konfiguration fuers Log', BambuConfig::secrets($old), ['alt-code-1']);
Util::jsonSave($cf, $old);
if (DIRECTORY_SEPARATOR === '/') {
    check('Konfigurationsdatei hat Rechte 0600', substr(sprintf('%o', fileperms($cf)), -4), '0600');
}
$lg = new Logger($dir . '/maske.log', 'Test', 'Test');
$lg->setLevel('debug');
$lg->addSecret('alt-code-1');
$lg->info('Anmeldung mit alt-code-1 abgelehnt');
check('Logger maskiert den Access Code', str_contains((string)file_get_contents($dir . '/maske.log'), 'alt-code-1'), false);

// ---------------------------------------------------------------- Unit: Befehle
check('Topic: Befehl', BambuCommands::parseTopic('bambulab/befehl/h2c/licht_kammer'), ['h2c', 'licht_kammer']);
check('Topic: Quittung ist kein Befehl', BambuCommands::parseTopic('bambulab/befehl/h2c/licht_kammer/quittung'), null);
check('Topic: fremdes Topic', BambuCommands::parseTopic('nut/befehl/h2c/x'), null);
$alle = ['licht' => true, 'druck' => true, 'stopp' => true];
$keine = ['licht' => false, 'druck' => false, 'stopp' => false];
check('Befehl gesperrt (Stufe nicht freigegeben)', str_starts_with((string)BambuCommands::pruefen($keine, 'licht_kammer', '1', 'IDLE'), 'Befehle sind gesperrt'), true);
check('Befehl: Licht an', BambuCommands::pruefen(['licht' => true] + $keine, 'licht_kammer', '1', null), null);
check('Befehl: Licht ist nicht Druck (Pause bleibt gesperrt)', str_starts_with((string)BambuCommands::pruefen(['licht' => true] + $keine, 'pause', '1', 'RUNNING'), 'Befehle sind gesperrt'), true);
check('Befehl unbekannt (auch rohes G-Code)', [BambuCommands::pruefen($alle, 'gcode_line', 'M104', 'RUNNING'), BambuCommands::pruefen($alle, 'datei_drucken', '1', 'IDLE')], ['Befehl nicht erlaubt', 'Befehl nicht erlaubt']);
check('Befehl: ungueltiger Wert', [BambuCommands::pruefen($alle, 'geschwindigkeit', '5', 'RUNNING'), BambuCommands::pruefen($alle, 'licht_kammer', '2', null), BambuCommands::pruefen($alle, 'pause', '0', 'RUNNING')], ['ungültiger Wert', 'ungültiger Wert', 'ungültiger Wert']);
check('Pause nur waehrend des Drucks', [BambuCommands::pruefen($alle, 'pause', '1', 'RUNNING'), BambuCommands::pruefen($alle, 'pause', '1', 'IDLE')], [null, 'es läuft kein Druck']);
check('Fortsetzen nur aus der Pause', [BambuCommands::pruefen($alle, 'fortsetzen', '1', 'PAUSE'), BambuCommands::pruefen($alle, 'fortsetzen', '1', 'RUNNING')], [null, 'der Druck ist nicht pausiert']);
check('Stopp nur bei Druck oder Pause', [BambuCommands::pruefen($alle, 'stopp', '1', 'RUNNING'), BambuCommands::pruefen($alle, 'stopp', '1', 'PAUSE'), BambuCommands::pruefen($alle, 'stopp', '1', 'FINISH'), BambuCommands::pruefen($alle, 'stopp', '1', null)],
    [null, null, 'es läuft kein Druck', 'es läuft kein Druck']);
check('Geschwindigkeit 1 bis 4 erlaubt', [BambuCommands::pruefen($alle, 'geschwindigkeit', '1', 'IDLE'), BambuCommands::pruefen($alle, 'geschwindigkeit', '4', 'IDLE')], [null, null]);
[$typ, $js, $qos] = BambuCommands::bauen('licht_kammer', '1', '77');
$j = json_decode($js, true);
check('Kammerlicht an: system.ledctrl chamber_light on, QoS 0', [$typ, $j['system']['command'], $j['system']['led_node'], $j['system']['led_mode'], $j['system']['sequence_id'], $qos], ['system', 'ledctrl', 'chamber_light', 'on', '77', 0]);
check('Kammerlicht 2 aus', json_decode(BambuCommands::bauen('licht_kammer2', '0', '1')[1], true)['system']['led_node'] . json_decode(BambuCommands::bauen('licht_kammer2', '0', '1')[1], true)['system']['led_mode'], 'chamber_light2off');
check('Arbeitslicht 0 = flashing (Ruhezustand am H2C), 1 = on', [json_decode(BambuCommands::bauen('licht_arbeit', '0', '1')[1], true)['system']['led_mode'], json_decode(BambuCommands::bauen('licht_arbeit', '1', '1')[1], true)['system']['led_mode']], ['flashing', 'on']);
[$typ, $js, $qos] = BambuCommands::bauen('pause', '1', '5');
check('Pause: print.pause, param leer, QoS 1', [$typ, json_decode($js, true)['print']['command'], json_decode($js, true)['print']['param'], $qos], ['print', 'pause', '', 1]);
check('Fortsetzen: print.resume QoS 1, Stopp: print.stop QoS 1', [json_decode(BambuCommands::bauen('fortsetzen', '1', '5')[1], true)['print']['command'], BambuCommands::bauen('fortsetzen', '1', '5')[2], json_decode(BambuCommands::bauen('stopp', '1', '5')[1], true)['print']['command'], BambuCommands::bauen('stopp', '1', '5')[2]], ['resume', 1, 'stop', 1]);
check('Geschwindigkeit: print.print_speed mit param als Text', [json_decode(BambuCommands::bauen('geschwindigkeit', '3', '5')[1], true)['print']['command'], json_decode(BambuCommands::bauen('geschwindigkeit', '3', '5')[1], true)['print']['param']], ['print_speed', '3']);
check('Drucker-Befehl fuer die Antwort', [BambuCommands::druckerBefehl('licht_arbeit'), BambuCommands::druckerBefehl('fortsetzen'), BambuCommands::druckerBefehl('stopp'), BambuCommands::druckerBefehl('geschwindigkeit'), BambuCommands::druckerBefehl('pause')], ['ledctrl', 'resume', 'stop', 'print_speed', 'pause']);
check('Antwort success (klein, wie ledctrl)', BambuCommands::antwort(['result' => 'success', 'reason' => '']), 'ausgefuehrt');
check('Antwort SUCCESS (gross, wie die Druckbefehle)', BambuCommands::antwort(['result' => 'SUCCESS', 'reason' => 'SUCCESS']), 'ausgefuehrt');
check('Antwort: mqtt message verify failed = Entwicklermodus', BambuCommands::antwort(['result' => 'failed', 'reason' => 'mqtt message verify failed']), 'abgelehnt: Drucker verlangt den Entwicklermodus');
check('Antwort: anderer Grund wird durchgereicht', BambuCommands::antwort(['result' => 'failed', 'reason' => 'busy']), 'abgelehnt: busy');
check('Antwort: ohne Grund', BambuCommands::antwort(['result' => 'failed']), 'abgelehnt: failed');
// Drucker-Objekt ohne Verbindung
$log0 = new Logger(null, 'T', 'T');
$pr0 = new BambuPrinter(['name' => 'x', 'host' => '127.0.0.1', 'port' => 1, 'seriennummer' => 'S1', 'zugangscode' => 'abcd1234', 'befehle' => $alle], $log0, 'f');
$pr0->aufnehmen(['print' => $pD + ['command' => 'push_status']], time());
check('Drucker ohne Verbindung: Befehl abgelehnt', $pr0->befehl('licht_kammer', '1', microtime(true)), 'abgelehnt: Drucker nicht verbunden');
check('Drucker: Werte aus dem aufgenommenen Report', $pr0->werte(true)['status_text'], 'druckt');
check('Drucker: Zustand', $pr0->zustand(), 'RUNNING');
$pr1 = new BambuPrinter(['name' => 'y', 'host' => '127.0.0.1', 'port' => 1, 'seriennummer' => 'S1', 'zugangscode' => 'abcd1234', 'befehle' => $keine], $log0, 'f');
check('Drucker mit gesperrten Befehlen: gesperrt vor Verbindung', str_starts_with($pr1->befehl('licht_kammer', '1', microtime(true)), 'abgelehnt: Befehle sind gesperrt'), true);
check('Drucker: Status vor dem ersten Verbindungsversuch ist in Ordnung', [$pr1->status(time())['ok'], $pr1->status(time())['aus']], [true, false]);

// ---------------------------------------------------------------- Prozesse (TLS-Fake-Drucker und Fake-Broker)
$php = PHP_BINARY;
$phpOpt = [];
if (PHP_OS_FAMILY === 'Windows' && !php_ini_loaded_file() && !extension_loaded('openssl_dummy')) {
    $phpOpt = ['-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=openssl'];       // ohne php.ini ist openssl nicht geladen
}
$tlsOk = extension_loaded('openssl') && makeCert($dir . '/cert.pem');
if (!$tlsOk) {
    $fails++;
    $count++;
    echo "FEHLT: PHP-Erweiterung openssl (unter Windows: php -d extension_dir=<php>/ext -d extension=openssl tests/run.php); TLS-Tests uebersprungen\n";
}
$bblSkip = !$tlsOk;
$mqPort = 18841;
$pA = 18871;      // H2C, Entwicklermodus aus
$pB = 18872;      // P1S, Entwicklermodus an
$pWeg = 18899;   // nichts dahinter (Drucker aus)
$SN_A = 'TESTSERIAL001';
$SN_B = 'TESTSERIAL002';
$ctl = fn(int $port, array $c) => file_put_contents("$dir/bbl_$port.json", json_encode($c));
$bblog = fn(int $port) => (string)@file_get_contents("$dir/bbl_$port.log");
$mlog = fn() => (string)@file_get_contents($dir . '/mqtt.log');
$has = fn(string $topic, string $val) => (bool)preg_match('/' . preg_quote($topic, '/') . "\t" . preg_quote($val, '/') . '(\r?\n|$)/', $mlog());
$inject = fn(string $zeilen) => file_put_contents($dir . '/inject.txt', $zeilen);
if (!$bblSkip) {
    $ctl($pA, ['modell' => 'h2c', 'dev' => false, 'code' => 'testcode', 'state' => 'druckt']);
    $ctl($pB, ['modell' => 'p1', 'dev' => true, 'code' => 'testcode', 'state' => 'druckt']);
    $mqProc = startProc([$php, __DIR__ . '/fake_mqtt.php', (string)$mqPort, $dir]);
    $prA = startProc(array_merge([$php], $phpOpt, [__DIR__ . '/fake_bambu.php', (string)$pA, $dir, $dir . '/cert.pem', $SN_A]));
    $prB = startProc(array_merge([$php], $phpOpt, [__DIR__ . '/fake_bambu.php', (string)$pB, $dir, $dir . '/cert.pem', $SN_B]));
    foreach ([$mqPort, $pA, $pB] as $pt) {
        waitFor(fn() => @stream_socket_client("tcp://127.0.0.1:$pt", $e, $es, 1) !== false, 8);
    }

    // ---------------------------------------------------------------- Ende-zu-Ende: Dienst
    $e2e = $dir . '/e2e';
    @mkdir($e2e, 0777, true);
    $conf = BambuConfig::load($e2e . '/bambulab.json');
    $conf['pushall_abstand'] = 600;
    $conf['drucker'][0] = ['aktiv' => true, 'name' => 'h2c', 'host' => '127.0.0.1', 'port' => $pA, 'seriennummer' => $SN_A, 'zugangscode' => 'testcode', 'befehle' => ['licht' => false, 'druck' => false, 'stopp' => false]];
    $conf['drucker'][1] = ['aktiv' => true, 'name' => 'p1', 'host' => '127.0.0.1', 'port' => $pB, 'seriennummer' => $SN_B, 'zugangscode' => 'testcode', 'befehle' => ['licht' => true, 'druck' => true, 'stopp' => true]];
    Util::jsonSave($e2e . '/bambulab.json', $conf);
    @unlink($dir . '/mqtt.log');
    $svc = startProc(array_merge([$php], $phpOpt, [dirname(__DIR__) . '/bambulab2lox/bin/bambulabd.php']),
        ['BBL_DEV_DIR' => $e2e, 'BBL_DEV_MQTT' => "127.0.0.1:$mqPort", 'BBL_DEV_LOGLEVEL' => 'debug']);
    $bl = fn() => (string)@file_get_contents($e2e . '/bambulab.log');
    $stat = fn() => Util::jsonLoad($e2e . '/run/status.json');

    check('Dienst: Letzter Wille fuer status/ok beim Broker angemeldet', waitFor(fn() => str_contains($mlog(), "CONNECT\tbambulab_bambulab2lox\tbambulab/status/ok"), 12), true);
    check('H2C: verbunden 1', waitFor(fn() => $has('bambulab/h2c/verbunden', '1'), 15), true);
    check('P1: verbunden 1', waitFor(fn() => $has('bambulab/p1/verbunden', '1'), 15), true);
    check('H2C: Status RUNNING', waitFor(fn() => $has('bambulab/h2c/status_text', 'druckt')), true);
    check('H2C: druckt = 1', $has('bambulab/h2c/zustand/druckt', '1'), true);
    check('H2C: Fortschritt 22 und Restzeit 42', $has('bambulab/h2c/fortschritt_prozent', '22') && $has('bambulab/h2c/restzeit_min', '42'), true);
    check('H2C: aktive Duese 0, Duese 0 Ist 220 / Soll 220', $has('bambulab/h2c/duese/aktiv', '0') && $has('bambulab/h2c/duese/0/temperatur_c', '220') && $has('bambulab/h2c/duese/0/soll_c', '220'), true);
    check('H2C: Modell und Firmware (get_version)', waitFor(fn() => $has('bambulab/h2c/info/modell', 'Bambu Lab H2C') && $has('bambulab/h2c/info/firmware', '01.03.00.00')), true);
    check('H2C: AMS-Feuchte', $has('bambulab/h2c/ams/1/feuchte_prozent', '41'), true);
    check('P1: Modell P1S', waitFor(fn() => $has('bambulab/p1/info/modell', 'Bambu Lab P1S')), true);
    check('P1: Werte aus Teil-Updates, AMS-Slot mit Typ', waitFor(fn() => $has('bambulab/p1/zustand/druckt', '1') && str_contains($mlog(), 'bambulab/p1/ams/1/slot/0/typ')), true);
    check('status/ok 1 (beide Drucker in Ordnung)', waitFor(fn() => $has('bambulab/status/ok', '1'), 15), true);
    check('Lebenszeichen status/zaehler und status/ts', waitFor(fn() => (bool)preg_match('/bambulab\/status\/zaehler\t\d+/', $mlog()) && (bool)preg_match('/bambulab\/status\/ts\t\d{10}/', $mlog())), true);
    check('status.json: Dienst laeuft, Drucker ok', [$stat()['service'] ?? '', $stat()['drucker']['h2c']['ok'] ?? null, $stat()['drucker']['p1']['modell'] ?? null], ['running', true, 'Bambu Lab P1S']);
    $gA = $bblog($pA);
    check('H2C: TLS-Anmeldung ohne Letzten Willen, Abo auf den Report, get_version und pushall', [str_contains($gA, "CONNECT\tok\twill=0"), str_contains($gA, "SUB\tdevice/$SN_A/report"),
        str_contains($gA, '"command":"get_version"'), str_contains($gA, '"command":"pushall"')], [true, true, true, true]);
    check('H2C: pushall nur einmal beim Verbinden (volle Reports, kein Anfordern noetig)', substr_count($bblog($pA), '"command":"pushall"'), 1);
    // nur Aenderungen: bei gleichbleibenden Werten kommt nichts Neues
    @unlink($dir . '/mqtt.log');
    sleep(4);
    check('Nur Aenderungen: gleiche Werte werden nicht erneut gesendet', [str_contains($mlog(), "bambulab/h2c/fortschritt_prozent\t"), str_contains($mlog(), "bambulab/h2c/duese/0/temperatur_c\t"), str_contains($mlog(), "bambulab/h2c/verbunden\t")], [false, false, false]);
    // Zugangsdaten nie im Log, in MQTT oder im Status
    check('Access Code nirgends im Log, in MQTT oder im Status', [str_contains($bl(), 'testcode'), str_contains($mlog(), 'testcode'), str_contains((string)@file_get_contents($e2e . '/run/status.json'), 'testcode')], [false, false, false]);

    // ---------------------------------------------------------------- Befehle
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/h2c/licht_kammer\t0\n");
    check('Befehl gesperrt (H2C: keine Stufe frei)', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/h2c/licht_kammer/quittung\tabgelehnt: Befehle sind gesperrt")), true);
    check('Befehl gesperrt: nichts an den Drucker gesendet', str_contains($bblog($pA), 'ledctrl'), false);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/licht_kammer\t0\n");
    check('P1 Licht aus: Quittung angenommen, dann ausgefuehrt', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/p1/licht_kammer/quittung\tangenommen") && str_contains($mlog(), "bambulab/befehl/p1/licht_kammer/quittung\tausgefuehrt")), true);
    check('P1 Licht aus: ledctrl chamber_light off beim Drucker', (bool)preg_match('/REQ\t\{"system":\{"sequence_id":"\d+","command":"ledctrl","led_node":"chamber_light","led_mode":"off"/', $bblog($pB)), true);
    check('P1 Licht aus: neuer Zustand kommt als Wert zurueck', waitFor(fn() => $has('bambulab/p1/licht/kammer', '0')), true);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/licht_arbeit\t1\n");
    check('P1 Arbeitslicht an: ausgefuehrt', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/p1/licht_arbeit/quittung\tausgefuehrt")), true);
    check('P1 Arbeitslicht an: Wert 1', waitFor(fn() => $has('bambulab/p1/licht/arbeit', '1')), true);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/licht_arbeit\t0\n");
    check('P1 Arbeitslicht 0: flashing (Ruhezustand), Wert 0', waitFor(fn() => $has('bambulab/p1/licht/arbeit', '0')) && str_contains($bblog($pB), '"led_node":"work_light","led_mode":"flashing"'), true);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/geschwindigkeit\t3\n");
    check('P1 Geschwindigkeit 3: ausgefuehrt', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/p1/geschwindigkeit/quittung\tausgefuehrt")), true);
    check('P1 Geschwindigkeit 3: Stufe 3 und 124 %, print_speed mit QoS 0', waitFor(fn() => $has('bambulab/p1/geschwindigkeit/stufe', '3') && $has('bambulab/p1/geschwindigkeit/prozent', '124')) && (bool)preg_match('/"command":"print_speed","param":"3"\}\}\tqos=0/', $bblog($pB)), true);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/pause\t1\n");
    check('P1 Pause: ausgefuehrt, pausiert = 1, QoS 1', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/p1/pause/quittung\tausgefuehrt") && $has('bambulab/p1/zustand/pausiert', '1')) && (bool)preg_match('/"command":"pause","param":""\}\}\tqos=1/', $bblog($pB)), true);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/pause\t1\n");
    check('P1 Pause in der Pause: abgelehnt (es laeuft kein Druck)', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/p1/pause/quittung\tabgelehnt: es läuft kein Druck")), true);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/fortsetzen\t1\n");
    check('P1 Fortsetzen: ausgefuehrt, druckt = 1', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/p1/fortsetzen/quittung\tausgefuehrt") && $has('bambulab/p1/zustand/druckt', '1')), true);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/stopp\t1\n");
    check('P1 Stopp: ausgefuehrt, abgebrochen = 1', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/p1/stopp/quittung\tausgefuehrt") && $has('bambulab/p1/zustand/abgebrochen', '1')), true);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/stopp\t1\n");
    check('P1 Stopp ohne Druck: abgelehnt', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/p1/stopp/quittung\tabgelehnt: es läuft kein Druck")), true);
    sleep(2);
    // zwei Befehle kurz hintereinander: der zweite wird gebremst (hoechstens einer je Sekunde)
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/licht_kammer\t1\nbambulab/befehl/p1/licht_kammer2\t0\n");
    check('Zwei Befehle in einer Sekunde: der zweite wird abgelehnt (zu schnell)', waitFor(fn() => str_contains($mlog(), 'bambulab/befehl/p1/licht_kammer2/quittung	abgelehnt: zu schnell')), true);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/p1/geschwindigkeit\t9\n");
    check('Ungueltiger Wert abgelehnt', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/p1/geschwindigkeit/quittung\tabgelehnt: ungültiger Wert")), true);
    $inject("bambulab/befehl/gibtsnicht/licht_kammer\t1\n");
    check('Unbekanntes Geraet abgelehnt', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/gibtsnicht/licht_kammer/quittung\tabgelehnt: unbekanntes Geraet")), true);
    sleep(2);
    $inject("bambulab/befehl/p1/gcode_line\tM104\n");
    check('Rohes G-Code ist kein erlaubter Befehl', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/p1/gcode_line/quittung\tabgelehnt: Befehl nicht erlaubt")) && !str_contains($bblog($pB), 'gcode_line'), true);

    // H2C: Druckbefehle ohne Entwicklermodus werden vom Drucker abgelehnt, mit Entwicklermodus ausgefuehrt
    sleep(1);
    $conf['drucker'][0]['befehle'] = ['licht' => true, 'druck' => true, 'stopp' => false];
    Util::jsonSave($e2e . '/bambulab.json', $conf);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/h2c/pause\t1\n");
    check('H2C ohne Entwicklermodus: angenommen, dann Ablehnung mit Hinweis auf den Entwicklermodus', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/h2c/pause/quittung\tangenommen")
        && str_contains($mlog(), "bambulab/befehl/h2c/pause/quittung\tabgelehnt: Drucker verlangt den Entwicklermodus")), true);
    check('H2C ohne Entwicklermodus: Zustand bleibt RUNNING', $has('bambulab/h2c/zustand/pausiert', '1'), false);
    check('H2C ohne Entwicklermodus: status.json merkt sich die Ablehnung (dev_modus_fehlt) fuer den Hinweis in der Oberflaeche', waitFor(fn() => ($stat()['drucker']['h2c']['dev_modus_fehlt'] ?? false) === true, 6), true);
    check('H2C: status.json hat den Klartext des Zustands', $stat()['drucker']['h2c']['zustand_text'] ?? null, 'druckt');
    $ctl($pA, ['modell' => 'h2c', 'dev' => true, 'code' => 'testcode', 'state' => 'druckt']);
    sleep(2);
    @unlink($dir . '/mqtt.log');
    $inject("bambulab/befehl/h2c/pause\t1\n");
    check('H2C mit Entwicklermodus: ausgefuehrt, pausiert = 1', waitFor(fn() => str_contains($mlog(), "bambulab/befehl/h2c/pause/quittung\tausgefuehrt") && $has('bambulab/h2c/zustand/pausiert', '1')), true);
    check('H2C mit Entwicklermodus: Hinweis dev_modus_fehlt verschwindet wieder', waitFor(fn() => ($stat()['drucker']['h2c']['dev_modus_fehlt'] ?? true) === false, 6), true);
    sleep(2);
    $inject("bambulab/befehl/h2c/stopp\t1\n");
    check('H2C: Stopp bleibt gesperrt (Stufe nicht freigegeben)', waitFor(fn() => str_contains($mlog(), 'bambulab/befehl/h2c/stopp/quittung	abgelehnt: Befehle sind gesperrt')), true);
    sleep(2);
    $inject("bambulab/befehl/h2c/fortsetzen\t1\n");
    waitFor(fn() => $has('bambulab/h2c/zustand/druckt', '1'));

    // ---------------------------------------------------------------- P1: voller Zustand wird regelmaessig angefordert, H2C nicht
    $conf['pushall_abstand'] = 2;
    Util::jsonSave($e2e . '/bambulab.json', $conf);
    sleep(1);
    $n0 = substr_count($bblog($pB), '"command":"pushall"');
    $nh0 = substr_count($bblog($pA), '"command":"pushall"');
    check('P1 (Teil-Updates): pushall wird im eingestellten Abstand wiederholt', waitFor(fn() => substr_count($bblog($pB), '"command":"pushall"') >= $n0 + 2, 12), true);
    check('H2C (volle Reports): kein wiederholtes pushall', substr_count($bblog($pA), '"command":"pushall"'), $nh0);
    $conf['pushall_abstand'] = 600;
    Util::jsonSave($e2e . '/bambulab.json', $conf);

    // ---------------------------------------------------------------- Fehlerfaelle: falscher Access Code, Drucker aus
    $conf['drucker'][2] = ['aktiv' => true, 'name' => 'falsch', 'host' => '127.0.0.1', 'port' => $pA, 'seriennummer' => $SN_A, 'zugangscode' => 'falsch1234', 'befehle' => []];
    $conf['drucker'][3] = ['aktiv' => true, 'name' => 'weg', 'host' => '127.0.0.1', 'port' => $pWeg, 'seriennummer' => 'TESTSERIAL009', 'zugangscode' => 'egal1234', 'befehle' => []];
    Util::jsonSave($e2e . '/bambulab.json', $conf);
    check('Falscher Access Code: status/ok 0', waitFor(fn() => $has('bambulab/status/ok', '0'), 25), true);
    check('Falscher Access Code: Fehler im Log, Code nicht im Log', [str_contains($bl(), '[falsch] Anmeldung abgelehnt: Access Code falsch?'), str_contains($bl(), 'falsch1234')], [true, false]);
    check('Falscher Access Code: status.json (kein Aus-Zustand, Fehlertext)', [$stat()['drucker']['falsch']['ok'] ?? null, $stat()['drucker']['falsch']['aus'] ?? null, str_contains((string)($stat()['drucker']['falsch']['error'] ?? ''), 'Access Code')], [false, false, true]);
    check('Drucker aus (nicht erreichbar, kein Druck): erwartet, kein Fehler', [$stat()['drucker']['weg']['ok'] ?? null, $stat()['drucker']['weg']['aus'] ?? null], [true, true]);
    check('Drucker aus: verbunden 0', waitFor(fn() => $has('bambulab/weg/verbunden', '0')), true);
    check('Die anderen Drucker laufen weiter', [$stat()['drucker']['h2c']['verbunden'] ?? null, $stat()['drucker']['p1']['verbunden'] ?? null], [true, true]);
    $conf['drucker'][2]['aktiv'] = false;
    Util::jsonSave($e2e . '/bambulab.json', $conf);
    @unlink($dir . '/mqtt.log');
    check('Falschen Drucker entfernt: status/ok wieder 1 (der ausgeschaltete Drucker ist kein Fehler)', waitFor(fn() => $has('bambulab/status/ok', '1'), 20), true);
    check('Access Code der anderen Drucker auch jetzt nirgends im Log', [str_contains($bl(), 'egal1234'), str_contains($bl(), 'testcode')], [false, false]);

    // ---------------------------------------------------------------- Verbindungsverlust waehrend des Drucks = Fehler, danach Wiederverbinden
    $ctl($pB, ['modell' => 'p1', 'dev' => true, 'code' => 'testcode', 'state' => 'druckt', 'reset' => 1]);      // der Druck wurde oben abgebrochen: neuer Druck
    @unlink($dir . '/mqtt.log');
    check('P1 druckt wieder', waitFor(fn() => $has('bambulab/p1/zustand/druckt', '1'), 12), true);
    stopProc($prB);
    check('P1 faellt waehrend des Drucks aus: verbunden 0', waitFor(fn() => $has('bambulab/p1/verbunden', '0'), 20), true);
    check('Verbindungsverlust im Druck: status/ok 0 und Hinweis', waitFor(fn() => $has('bambulab/status/ok', '0'), 15) && str_contains((string)($stat()['drucker']['p1']['error'] ?? ''), 'Verbindung verloren'), true);
    $ctl($pB, ['modell' => 'p1', 'dev' => true, 'code' => 'testcode', 'state' => 'druckt', 'reset' => 1]);
    @unlink($dir . '/mqtt.log');
    $prB = startProc(array_merge([$php], $phpOpt, [__DIR__ . '/fake_bambu.php', (string)$pB, $dir, $dir . '/cert.pem', $SN_B]));
    check('Wiederverbinden: P1 wieder verbunden', waitFor(fn() => $has('bambulab/p1/verbunden', '1'), 40), true);
    check('Wiederverbinden: status/ok wieder 1', waitFor(fn() => $has('bambulab/status/ok', '1'), 20), true);
    check('Wiederverbinden: der Dienst hat den Zustand wieder (Werte laut status.json)', waitFor(fn() => ($stat()['drucker']['p1']['werte'] ?? 0) > 20, 10), true);
    // Drucker nach Druckende ausgeschaltet: kein Fehler
    $ctl($pB, ['modell' => 'p1', 'dev' => true, 'code' => 'testcode', 'state' => 'leerlauf', 'reset' => 1]);
    check('P1 Zustand fertig', waitFor(fn() => $has('bambulab/p1/zustand/fertig', '1'), 12), true);
    stopProc($prB);
    check('Drucker nach dem Druck ausgeschaltet: verbunden 0', waitFor(fn() => $has('bambulab/p1/verbunden', '0'), 20), true);
    sleep(3);
    check('Drucker nach dem Druck ausgeschaltet: kein Fehler (status.json, status/ok)', [$stat()['drucker']['p1']['ok'] ?? null, $stat()['drucker']['p1']['aus'] ?? null, $stat()['ok'] ?? null], [true, true, 1]);

    // ---------------------------------------------------------------- Dienst beenden: status/ok 0
    stopProc($svc);
    stopProc($prA);
    stopProc($prB);
    stopProc($mqProc);
} else {
    echo "Hinweis: TLS-Ende-zu-Ende-Tests uebersprungen\n";
}

// ---------------------------------------------------------------- Healthcheck (LoxBerry-Healthcheck-Seite)
$hcDir = $dir . '/hc';
@mkdir($hcDir . '/run', 0777, true);
$hc = function (string $arg, ?array $status) use ($php, $root, $hcDir): array {
    $f = $hcDir . '/run/status.json';
    @unlink($f);
    if ($status !== null) {
        file_put_contents($f, json_encode($status));
    }
    $p = proc_open([$php, $root . '/bambulab2lox/bin/healthcheck', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['BBL_DEV_DIR' => $hcDir]));
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$out, $err, $rc];
};
$basis = ['service' => 'running', 'time' => time(), 'mqtt' => 1, 'mqtt_error' => '', 'version' => AppVersion::CURRENT,
    'drucker' => ['h2c' => ['ok' => true, 'aus' => false, 'error' => '', 'werte' => 90]]];
[$o, $e, $rc] = $hc('title', null);
check('Healthcheck title', trim($o), 'Bambulab2Lox: Dienst, MQTT-Broker und Drucker');
check('Healthcheck title: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck ohne Argument: keine Ausgabe', $hc('', null)[0], '');
$res = function (?array $st) use ($hc): array {
    [$o, $e, $rc] = $hc('check', $st);
    $j = json_decode($o, true);
    return [is_array($j) ? $j : ['status' => -1, 'result' => $o], $rc, $e];
};
[$j, $rc, $e] = $res(null);
check('Healthcheck: ohne Status = Fehler 3', $j['status'], 3);
check('Healthcheck: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck: veralteter Status = Fehler 3', $res(['time' => time() - 120] + $basis)[0]['status'], 3);
$j = $res(['mqtt' => 0, 'mqtt_error' => 'Verbindung abgelehnt'] + $basis)[0];
check('Healthcheck: Broker weg = Warnung 4', $j['status'], 4);
check('Healthcheck: Broker-Grund im Text', str_contains($j['result'], 'Verbindung abgelehnt'), true);
check('Healthcheck: nichts eingerichtet = Info 6', $res(['drucker' => []] + $basis)[0]['status'], 6);
$j = $res(['drucker' => ['h2c' => ['ok' => false, 'aus' => false, 'error' => 'Anmeldung abgelehnt: Access Code falsch?']]] + $basis)[0];
check('Healthcheck: Drucker mit Problem = Warnung 4', $j['status'], 4);
check('Healthcheck: Drucker und Grund im Text', str_contains($j['result'], 'h2c (Anmeldung abgelehnt: Access Code falsch?)'), true);
$j = $res(['drucker' => ['h2c' => ['ok' => true, 'aus' => true, 'error' => 'nicht erreichbar']]] + $basis)[0];
check('Healthcheck: ausgeschalteter Drucker = OK 5, keine Warnung', $j['status'], 5);
check('Healthcheck: ausgeschalteter Drucker im Text', str_contains($j['result'], 'vermutlich ausgeschaltet: h2c'), true);
$j = $res($basis)[0];
check('Healthcheck: alles gut = OK 5', $j['status'], 5);
check('Healthcheck: Text zaehlt Drucker und nennt Version', $j['result'], '1 Drucker in Ordnung, Version ' . AppVersion::CURRENT);
check('Healthcheck: ohne Logdatei kein logfile', isset($j['logfile']), false);
file_put_contents($hcDir . '/bambulab.log', "x\n");
check('Healthcheck: mit Logdatei der Pfad', $res($basis)[0]['logfile'] ?? null, $hcDir . '/bambulab.log');
check('Healthcheck: Ausgabe ist genau eine JSON-Zeile', substr_count(rtrim($hc('check', $basis)[0], "\n"), "\n"), 0);

// ---------------------------------------------------------------- Weboberflaeche mit Attrappen der LoxBerry-Bibliotheken
// Rendert jeden Reiter (ohne echtes LoxBerry): findet undefinierte Variablen, Syntax- und Laufzeitfehler und prueft Menueleiste,
// Gateway-Abo und UDP-Port. Nicht pruefbar: das echte Aussehen in LoxBerry.
$uiDir = $dir . '/ui';
foreach (['config', 'log', 'data', 'sys', 'dev'] as $sub) {
    @mkdir("$uiDir/$sub", 0777, true);
}
@mkdir("$uiDir/sess", 0777, true);
$uiPage = function (string $tab, array $env = []) use ($php, $root, $uiDir): array {
    $e = array_merge(getenv(), [
        'STUB_INDEX' => $root . '/bambulab2lox/webfrontend/htmlauth/index.php', 'STUB_BIN' => $root . '/bambulab2lox/bin', 'STUB_CONFIG' => "$uiDir/config",
        'STUB_LOG' => "$uiDir/log", 'STUB_DATA' => "$uiDir/data", 'STUB_SYSCONFIG' => "$uiDir/sys", 'BBL_DEV_DIR' => "$uiDir/dev",
    ], $env);
    $cmd = [$php, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'include_path=' . $root . '/tests/stubs', '-d', 'session.save_path=' . "$uiDir/sess",
        $root . '/tests/ui_page.php', $tab];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return [$out, $err];
};
$bad = fn(string $o, string $e) => (bool)preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)/', $o . $e);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 2, 'Udpinport' => '12345']]));
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'shellies/#']]]));
file_put_contents("$uiDir/log/test.log", "2026-01-01 <INFO> Testzeile\n");
$uiConf = BambuConfig::defaults();
$uiConf['drucker'][0] = ['aktiv' => true, 'name' => 'werkstatt', 'host' => '192.0.2.50', 'port' => 8883, 'seriennummer' => '01P00A000000001', 'zugangscode' => 'geheimcode99',
    'befehle' => ['licht' => true, 'druck' => false, 'stopp' => false]];
$uiConf['mqtt']['passwort'] = 'brokerpasswort77';
Util::jsonSave("$uiDir/dev/bambulab.json", $uiConf);
foreach (['drucker', 'mqtt', 'loxone', 'test', 'logs'] as $tab) {
    [$o, $e] = $uiPage($tab, ['STUB_UDP' => '12345']);
    check("UI $tab: ohne PHP-Warnungen und -Fehler", [$bad($o, $e), $e], [false, '']);
    check("UI $tab: Kopf und Fuss", str_contains($o, '<!--HEADER:') && str_contains($o, '<!--FOOTER-->'), true);
    preg_match('/<!--NAVBAR:(.*?)-->/', $o, $m);
    $nav = json_decode($m[1] ?? '[]', true) ?: [];
    check("UI $tab: Menueleiste mit 5 Eintraegen", count($nav), 5);
    check("UI $tab: genau ein aktiver Eintrag, und zwar dieser", array_values(array_filter($nav, fn($n) => !empty($n['active'])))[0]['URL'] ?? null, '?tab=' . $tab);
    check("UI $tab: nur ein aktiver Eintrag", count(array_filter($nav, fn($n) => !empty($n['active']))), 1);
    check("UI $tab: kein eigener Tab-Block mehr", str_contains($o, '-tabs"'), false);
    check("UI $tab: Design System (nojqm gesetzt, lb-content, kein jQuery-Mobile-Rest)", [str_contains($o, '<!--NOJQM:1-->'), str_contains($o, 'class="lb-content'), str_contains($o, 'data-role='), str_contains($o, 'data-ajax=')], [true, true, false, false]);
    check("UI $tab: kein Zugangsdatum im HTML", [str_contains($o, 'geheimcode99'), str_contains($o, 'brokerpasswort77')], [false, false]);
}
[$o] = $uiPage('drucker');
check('UI Drucker: Schalter, Eingabefelder und Speichern-Knopf im Design System', [str_contains($o, 'class="lb-toggle"'), str_contains($o, 'class="lb-input'), str_contains($o, 'lb-btn lb-btn-primary'),
    str_contains($o, 'name="drucker[0][aktiv]"'), str_contains($o, 'name="drucker[0][zugangscode]"'), str_contains($o, 'name="drucker[0][seriennummer]"'), str_contains($o, 'name="pushall_abstand"'), str_contains($o, 'name="hms_text"')], [true, true, true, true, true, true, true, true]);
check('UI Drucker: Stufen der Befehlsfreigabe je Drucker', [str_contains($o, 'name="drucker[0][befehle][licht]" value="1" checked'), str_contains($o, 'name="drucker[0][befehle][druck]" value="1">'), str_contains($o, 'name="drucker[0][befehle][stopp]"')], [true, true, true]);
check('UI Drucker: Access Code als Passwortfeld ohne Wert, "(gesetzt)"', [str_contains($o, 'type="password" name="drucker[0][zugangscode]" value=""'), str_contains($o, 'placeholder="(gesetzt)"')], [true, true]);
check('UI Drucker: Hinweise zu LAN-Modus, Entwicklermodus und Zertifikat', [str_contains($o, 'LAN-Modus'), str_contains($o, 'Entwicklermodus'), str_contains($o, 'selbst signiertes Zertifikat')], [true, true, true]);
check('UI Drucker: Hinweis, dass ohne Entwicklermodus nur das Licht schaltbar ist; Auslesen ohne LAN-/Entwicklermodus', [str_contains($o, 'nur das Licht'), str_contains($o, 'ohne LAN-Modus und ohne Entwicklermodus')], [true, true]);
$uiCode = (string)file_get_contents($root . '/bambulab2lox/webfrontend/htmlauth/index.php');
check('UI Drucker: Klartext statt Rohwert im Statusfeld, Hinweis bei abgelehnten Druckbefehlen im Code', [str_contains($uiCode, 'zustand_text'), str_contains($uiCode, 'dev_modus_fehlt')], [true, true]);
check('UI Drucker: acht Zeilen', substr_count($o, '][aktiv]"'), 8);
[$o] = $uiPage('loxone', ['STUB_UDP' => '12345']);
check('UI Loxone: Befehlstabelle mit UDP-Port, Topics und Quittung', [str_contains($o, '/dev/udp/&lt;LoxBerry-IP&gt;/12345'), str_contains($o, 'bambulab/befehl/werkstatt/stopp'), str_contains($o, 'bambulab/befehl/werkstatt/licht_kammer2'),
    str_contains($o, 'quittung'), str_contains($o, 'bambulab_werkstatt_verbunden')], [true, true, true, true, true]);
check('UI Loxone: kein rohes G-Code als Befehl', str_contains($o, 'gcode'), false);
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Gateway V2, Abo fehlt, mit Anleitung', str_contains($o, 'Gateway V2: Das Abo „bambulab/#“ fehlt'), true);
check('UI mqtt: nichts abonniert = rotes Feld', str_contains($o, 'gw-abo-fehler') && !str_contains($o, 'gw-abo-ok'), true);
check('UI mqtt: rotes Feld ohne Topic-Liste', str_contains($o, 'gw-abo-fehler') && !str_contains($o, '<ul style="margin:4px 0 0 18px'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'bambulab/#']]]));
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Gateway V2, Abo eingetragen', str_contains($o, 'Gateway V2: eingetragen (bambulab/#)'), true);
check('UI mqtt: vollstaendig abonniert = gruenes Feld mit Topic-Liste', str_contains($o, 'gw-abo-ok') && str_contains($o, '<li><code>bambulab/#</code></li>') && !str_contains($o, 'gw-abo-fehler'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'bambulab/status/#']]]));
[$o] = $uiPage('mqtt');
check('UI mqtt: nur ein Teil abonniert = gruen (nicht rot)', str_contains($o, 'gw-abo-ok') && !str_contains($o, 'gw-abo-fehler'), true);
check('UI mqtt: Teil-Abo: Hinweis und Topic aufgelistet', str_contains($o, 'teilweise abonniert (bambulab/status/#)') && str_contains($o, '<li><code>bambulab/status/#</code></li>'), true);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 1]]));
file_put_contents("$uiDir/config/mqtt_subscriptions.cfg", "bambulab/#\n");
[$o] = $uiPage('mqtt');
check('UI mqtt: Gateway V1, Abo in der Datei = gruen', str_contains($o, 'Gateway V1: eingetragen (bambulab/#)') && str_contains($o, 'gw-abo-ok'), true);
@unlink("$uiDir/config/mqtt_subscriptions.cfg");
[$o] = $uiPage('mqtt');
check('UI mqtt: Gateway V1, Abo-Datei fehlt', str_contains($o, 'Gateway V1: Das Abo'), true);
[$o] = $uiPage('logs');
check('UI logs: zeigt die Logdatei', str_contains($o, 'Testzeile'), true);

// ---------------------------------------------------------------- plugin.cfg, Installationsskripte, Bauen, ZIP
$pcfg = (string)file_get_contents($root . '/bambulab2lox/plugin.cfg');
check('plugin.cfg: Identitaet', [str_contains($pcfg, "NAME=Rico Tengler\nEMAIL=rico@cod-lan.de\n"), str_contains($pcfg, "NAME=bambulab2lox\nFOLDER=bambulab2lox\nTITLE=Bambulab2Lox\n"), str_contains($pcfg, 'VERSION=' . AppVersion::CURRENT . "\n")], [true, true, true]);
check('plugin.cfg: Autoupdate an, RELEASECFG auf raw.githubusercontent.com (main), PRERELEASECFG leer, WEBSITE',
    [str_contains($pcfg, "\nAUTOMATIC_UPDATES=true\n"), str_contains($pcfg, "\nRELEASECFG=https://raw.githubusercontent.com/ricten/Bambulab2Lox/main/release.cfg\n"), str_contains($pcfg, "\nPRERELEASECFG=\n"), str_contains($pcfg, "\nWEBSITE=https://github.com/ricten/Bambulab2Lox\n")], [true, true, true, true]);
check('plugin.cfg: Loglevel-Auswahl der Plugin-Verwaltung frei', str_contains($pcfg, "\nCUSTOM_LOGLEVELS=true\n"), true);
check('Plugin-Icons als PNG in allen vier Groessen, kein icon.svg, keine release.cfg im Plugin', [is_file($root . '/bambulab2lox/icons/icon_64.png') && is_file($root . '/bambulab2lox/icons/icon_128.png') && is_file($root . '/bambulab2lox/icons/icon_256.png') && is_file($root . '/bambulab2lox/icons/icon_512.png'), is_file($root . '/bambulab2lox/icons/icon.svg'), is_file($root . '/bambulab2lox/release.cfg')], [true, false, false]);
check('Abo-Datei: ein Topic je Zeile', file_get_contents($root . '/bambulab2lox/config/mqtt_subscriptions.cfg'), "bambulab/#\n");
$hart = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/bambulab2lox', FilesystemIterator::SKIP_DOTS)) as $f) {
    if (str_ends_with($f->getFilename(), '.png')) {
        continue;           // Bilddateien sind Binaerdaten (enthalten zufaellig CRLF-Bytes)
    }
    $t = (string)file_get_contents($f->getPathname());
    if (str_contains($t, '/opt/loxberry')) {
        $hart[] = $f->getFilename();
    }
    if (str_contains($t, "\r\n")) {
        $hart[] = 'CRLF:' . $f->getFilename();
    }
}
check('Keine festen /opt/loxberry-Pfade, nur LF-Zeilenenden im Plugin', $hart, []);

$bashOk = false;
$pr = @proc_open(['bash', '-c', 'command -v python3 >/dev/null 2>&1 || command -v python >/dev/null 2>&1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
if (is_resource($pr)) {
    stream_get_contents($pp[1]);
    stream_get_contents($pp[2]);
    $bashOk = proc_close($pr) === 0;
}
if ($bashOk) {
    // build.sh in einer Kopie des Repos (sonst entstuende eine echte release.cfg bzw. ein ZIP im Repo)
    $rc = $dir . '/rc';
    $copy = function (string $from, string $to) use (&$copy): void {
        @mkdir($to, 0777, true);
        foreach (scandir($from) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$from/$f") ? $copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    };
    @mkdir("$rc/bambulab", 0777, true);
    copy($root . '/build.sh', "$rc/bambulab/build.sh");
    copy($root . '/CHANGELOG.md', "$rc/bambulab/CHANGELOG.md");
    $copy($root . '/bambulab2lox', "$rc/bambulab/bambulab2lox");
    $copy(dirname($root) . '/common', "$rc/common");
    $ver = AppVersion::CURRENT;
    $bs = function (string $arg = '') use ($rc): string {
        $cmd = $arg !== '' ? ['bash', 'build.sh', $arg] : ['bash', 'build.sh'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$rc/bambulab");
        $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        return $o;
    };
    $bs();
    check('build.sh: baut beide ZIPs (mit und ohne Version), ohne release.cfg zu schreiben', [file_exists("$rc/bambulab/bambulab2lox-$ver.zip"), file_exists("$rc/bambulab/bambulab2lox.zip"), file_exists("$rc/bambulab/release.cfg")], [true, true, false]);
    check('build.sh: die beiden ZIPs sind gleich', md5_file("$rc/bambulab/bambulab2lox-$ver.zip"), md5_file("$rc/bambulab/bambulab2lox.zip"));
    $bs('release-cfg');
    $cfgtxt = (string)@file_get_contents("$rc/bambulab/release.cfg");
    check('build.sh release-cfg: Version, ZIP-Link des Release-Anhangs, Info-Link, LF', [str_contains($cfgtxt, "[AUTOUPDATE]\n"), str_contains($cfgtxt, "\nVERSION=$ver\n"),
        str_contains($cfgtxt, "\nARCHIVEURL=https://github.com/ricten/Bambulab2Lox/releases/download/v$ver/bambulab2lox-$ver.zip\n"), str_contains($cfgtxt, "\nINFOURL=https://github.com/ricten/Bambulab2Lox/releases/tag/v$ver\n"), str_contains($cfgtxt, "\r")], [true, true, true, true, false]);
    // ZIP-Inhalt mit Python lesen (die PHP-Erweiterung zip ist nicht ueberall da); alle Textdateien nach Zugangsdaten durchsuchen
    $zp = proc_open(['bash', '-c', 'P=$(command -v python3 || command -v python); "$P" -c "import sys,zipfile;z=zipfile.ZipFile(sys.argv[1]);print(chr(10).join(z.namelist()));print(chr(0));print(sum(1 for n in z.namelist() if b\'testcode\' in z.read(n) or b\'ACCESS_CODE\' in z.read(n)))" "$1"', 'x', "$rc/bambulab/bambulab2lox-$ver.zip"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $zpp);
    $zout = str_replace("\r\n", "\n", stream_get_contents($zpp[1]));
    stream_get_contents($zpp[2]);
    proc_close($zp);
    [$zl, $zs] = array_pad(explode("\n\0\n", $zout, 2), 2, '');
    $namen = explode("\n", $zl);
    check('ZIP: plugin.cfg zuerst, Dienst, Healthcheck, gemeinsame Klassen, Weboberflaeche', [$namen[0] ?? '', in_array('bin/bambulabd.php', $namen, true), in_array('bin/healthcheck', $namen, true), in_array('bin/lib/MqttClient.php', $namen, true),
        in_array('bin/lib/GatewayInfo.php', $namen, true), in_array('webfrontend/htmlauth/index.php', $namen, true), in_array('uninstall/uninstall', $namen, true)], ['plugin.cfg', true, true, true, true, true, true]);
    check('ZIP: keine release.cfg, vier PNG-Icons und kein icon.svg, keine Tests, keine Fixtures', [in_array('release.cfg', $namen, true), count(array_filter($namen, fn($n) => preg_match('#^icons/icon_(64|128|256|512)\.png$#', $n))), in_array('icons/icon.svg', $namen, true), count(array_filter($namen, fn($n) => str_contains($n, 'tests/') || str_contains($n, 'fixtures'))), count(array_filter($namen, fn($n) => str_contains($n, 'zip')))], [false, 4, false, 0, 0]);
    check('ZIP: keine Zugangsdaten in den Dateien', trim($zs), '0');
    file_put_contents("$rc/bambulab/release.cfg", str_replace("VERSION=$ver", 'VERSION=0.0.1', $cfgtxt));
    check('build.sh: abweichende release.cfg nur als Hinweis (kein Fehler)', str_contains($bs(), 'Hinweis: release.cfg zeigt auf 0.0.1'), true);
} else {
    echo "Hinweis: bash/python fehlen, build.sh-Tests uebersprungen\n";
}

echo "\n$count Pruefungen, $fails Fehler\n";
exit($fails ? 1 : 0);
