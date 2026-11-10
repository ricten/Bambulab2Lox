<?php
/**
 * Fake-Drucker fuer Tests: MQTT-Broker mit TLS (selbstsigniertes Zertifikat), der sich wie ein Bambu-Lab-Drucker verhaelt.
 *   php tests/fake_bambu.php <port> <verzeichnis> <zertifikat.pem> <seriennummer>
 * Spielt die anonymisierten Reports aus tests/fixtures (H2C) ab und kennt: get_version, pushall, ledctrl, pause, resume, stop, print_speed.
 * Steuerung ueber <verzeichnis>/bbl_<port>.json (wird laufend gelesen), z. B. {"modell":"h2c","dev":true,"code":"testcode","state":"druckt"}:
 *   modell  h2c  = bei jedem Report der volle Zustand (wie X1 und H2)
 *           p1   = nur geaenderte Werte (wie P1 und A1); pushall liefert alles
 *   dev     true = Entwicklermodus an; false = print.*-Befehle antworten "mqtt message verify failed"
 *   code    erwarteter Access Code (falscher Code: CONNACK 4)
 *   state   druckt | leerlauf (laedt den entsprechenden Zustand neu); reset: Zahl erhoehen laedt den Zustand ebenfalls neu
 * Log: <verzeichnis>/bbl_<port>.log ("CONNECT\t<ok|bad>", "SUB\t<topic>", "REQ\t<json>").
 */
$port = (int)($argv[1] ?? 18861);
$dir = $argv[2] ?? sys_get_temp_dir();
$pem = $argv[3] ?? '';
$serial = $argv[4] ?? 'TESTSERIAL001';
$fix = __DIR__ . '/fixtures';
$logf = "$dir/bbl_$port.log";
$ctlf = "$dir/bbl_$port.json";

$ctx = stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false, 'allow_self_signed' => true]]);
$srv = stream_socket_server("ssl://127.0.0.1:$port", $e, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}
function logline(string $f, string $l): void
{
    file_put_contents($f, $l . "\n", FILE_APPEND);
}
function mstr(string $s): string
{
    return pack('n', strlen($s)) . $s;
}
function mlen(int $l): string
{
    $o = '';
    do {
        $d = $l % 128;
        $l = intdiv($l, 128);
        if ($l > 0) {
            $d |= 0x80;
        }
        $o .= chr($d);
    } while ($l > 0);
    return $o;
}
function senden($c, string $data): bool
{
    $done = 0;
    $len = strlen($data);
    $tries = 0;
    while ($done < $len) {
        $n = @fwrite($c, substr($data, $done));
        if ($n === false || $n === 0) {
            if (++$tries > 500) {
                return false;
            }
            usleep(5000);
            continue;
        }
        $tries = 0;
        $done += $n;
    }
    return true;
}
function ctl(string $f): array
{
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return array_merge(['modell' => 'h2c', 'dev' => true, 'code' => 'testcode', 'state' => 'druckt'], is_array($j) ? $j : []);
}
function ladeZustand(string $fix, string $state): array
{
    $f = $state === 'leerlauf' ? 'h2c_leerlauf_fertig.json' : 'h2c_druckt.json';
    $j = json_decode((string)file_get_contents("$fix/$f"), true);
    return $j['print'];
}

$cfg = ctl($ctlf);
$state = $cfg['state'];
$resetNr = $cfg['reset'] ?? 0;
$print = ladeZustand($fix, $state);
$clients = [];       // id => ['s' => stream, 'buf' => string, 'ok' => bool, 'sub' => bool, 'last' => array]
$lastPush = 0.0;
$version = json_decode((string)file_get_contents("$fix/h2c_get_version.json"), true);
echo "listening $port\n";

/** Report mit dem aktuellen Zustand bauen. */
$report = function (array $p, string $seq = '0'): string {
    $p['command'] = 'push_status';
    $p['sequence_id'] = $seq;
    return json_encode(['print' => $p]);
};
$publishReport = function ($c, string $json) use ($serial): void {
    $topic = "device/$serial/report";
    $body = mstr($topic) . $json;
    senden($c, chr(0x30) . mlen(strlen($body)) . $body);
};

while (true) {
    $now = microtime(true);
    // Zustand nach Steuerdatei
    $new = ctl($ctlf);
    if ($new['state'] !== $state || ($new['reset'] ?? 0) !== $resetNr) {      // reset: Zustand neu laden (nach Befehlen wie Stopp)
        $state = $new['state'];
        $resetNr = $new['reset'] ?? 0;
        $print = ladeZustand($fix, $state);
    }
    $cfg = $new;

    // neue Verbindungen
    $r = [$srv];
    $w = $x = null;
    if (@stream_select($r, $w, $x, 0, 20000)) {
        $c = @stream_socket_accept($srv, 2);
        if ($c) {
            stream_set_blocking($c, false);
            $clients[(int)$c] = ['s' => $c, 'buf' => '', 'ok' => false, 'sub' => false, 'last' => []];
        }
    }

    foreach ($clients as $id => &$cl) {
        $s = $cl['s'];
        $d = @fread($s, 65536);
        if ($d === false || ($d === '' && feof($s))) {
            @fclose($s);
            unset($clients[$id]);
            continue;
        }
        $cl['buf'] .= $d;
        while (strlen($cl['buf']) >= 2) {
            $mul = 1;
            $rem = 0;
            $i = 1;
            do {
                if ($i >= strlen($cl['buf'])) {
                    break 2;
                }
                $b = ord($cl['buf'][$i++]);
                $rem += ($b & 127) * $mul;
                $mul *= 128;
            } while ($b & 128);
            if (strlen($cl['buf']) < $i + $rem) {
                break;
            }
            $first = ord($cl['buf'][0]);
            $body = substr($cl['buf'], $i, $rem);
            $cl['buf'] = substr($cl['buf'], $i + $rem);
            $type = $first >> 4;
            if ($type === 1) {                                   // CONNECT: Benutzer und Passwort pruefen
                $o = 2 + unpack('n', substr($body, 0, 2))[1];     // Protokollname
                $level = ord($body[$o]);
                $flags = ord($body[$o + 1]);
                $o += 4;                                         // Level, Flags, Keepalive
                $cl_id_len = unpack('n', substr($body, $o, 2))[1];
                $o += 2 + $cl_id_len;
                if ($flags & 0x04) {                              // Will ueberspringen
                    $o += 2 + unpack('n', substr($body, $o, 2))[1];
                    $o += 2 + unpack('n', substr($body, $o, 2))[1];
                }
                $user = $pass = '';
                if ($flags & 0x80) {
                    $ul = unpack('n', substr($body, $o, 2))[1];
                    $user = substr($body, $o + 2, $ul);
                    $o += 2 + $ul;
                }
                if ($flags & 0x40) {
                    $pl = unpack('n', substr($body, $o, 2))[1];
                    $pass = substr($body, $o + 2, $pl);
                }
                $good = $user === 'bblp' && $pass === $cfg['code'];
                logline($logf, 'CONNECT' . "\t" . ($good ? 'ok' : 'bad') . "\twill=" . ($flags & 0x04 ? '1' : '0'));
                senden($s, chr(0x20) . chr(2) . chr(0) . chr($good ? 0 : 4));
                if (!$good) {
                    @fclose($s);
                    unset($clients[$id]);
                    continue 2;
                }
                $cl['ok'] = true;
            } elseif (!$cl['ok']) {
                continue;
            } elseif ($type === 8) {                              // SUBSCRIBE
                $pid = substr($body, 0, 2);
                $fl = unpack('n', substr($body, 2, 2))[1];
                logline($logf, "SUB\t" . substr($body, 4, $fl));
                senden($s, chr(0x90) . chr(3) . $pid . chr(0));
                $cl['sub'] = true;
            } elseif ($type === 12) {
                senden($s, chr(0xD0) . chr(0));
            } elseif ($type === 3) {                              // PUBLISH an device/<sn>/request
                $qos = ($first >> 1) & 3;
                $tl = unpack('n', substr($body, 0, 2))[1];
                $o = 2 + $tl;
                if ($qos > 0) {
                    senden($s, chr(0x40) . chr(2) . substr($body, $o, 2));    // PUBACK
                    $o += 2;
                }
                $payload = substr($body, $o);
                logline($logf, "REQ\t" . $payload . "\tqos=$qos");
                $o2 = json_decode($payload, true);
                if (!is_array($o2)) {
                    continue;
                }
                $typ = array_key_first($o2);
                $cmd = $o2[$typ]['command'] ?? '';
                $seq = (string)($o2[$typ]['sequence_id'] ?? '0');
                if ($typ === 'info' && $cmd === 'get_version') {
                    $v = $version;
                    $v['info']['sequence_id'] = $seq;
                    if ($cfg['modell'] === 'p1') {
                        foreach ($v['info']['module'] as &$m) {
                            if (($m['name'] ?? '') === 'ota') {
                                $m['product_name'] = 'Bambu Lab P1S';
                            }
                        }
                        unset($m);
                    }
                    $publishReport($s, json_encode($v));
                } elseif ($typ === 'pushing' && $cmd === 'pushall') {
                    $publishReport($s, $report($print, $seq));
                    $cl['last'] = $print;
                } elseif ($typ === 'system' && $cmd === 'ledctrl') {
                    $node = $o2['system']['led_node'] ?? '';
                    $mode = $o2['system']['led_mode'] ?? '';
                    foreach ($print['lights_report'] as &$l) {
                        if ($l['node'] === $node) {
                            $l['mode'] = $mode;
                        }
                    }
                    unset($l);
                    $publishReport($s, json_encode(['system' => ['command' => 'ledctrl', 'sequence_id' => $seq, 'led_node' => $node, 'led_mode' => $mode, 'result' => 'success', 'reason' => '']]));
                } elseif ($typ === 'print' && in_array($cmd, ['pause', 'resume', 'stop', 'print_speed'], true)) {
                    if (!$cfg['dev']) {
                        $publishReport($s, json_encode(['print' => ['command' => $cmd, 'sequence_id' => $seq, 'param' => $o2['print']['param'] ?? '', 'result' => 'failed', 'reason' => 'mqtt message verify failed']]));
                    } else {
                        if ($cmd === 'pause') {
                            $print['gcode_state'] = 'PAUSE';
                        } elseif ($cmd === 'resume') {
                            $print['gcode_state'] = 'RUNNING';
                        } elseif ($cmd === 'stop') {
                            $print['gcode_state'] = 'FAILED';
                            $print['mc_percent'] = 0;
                        } else {
                            $lvl = (int)($o2['print']['param'] ?? 2);
                            $print['spd_lvl'] = $lvl;
                            $print['spd_mag'] = [1 => 50, 2 => 100, 3 => 124, 4 => 166][$lvl] ?? 100;
                        }
                        $publishReport($s, json_encode(['print' => ['command' => $cmd, 'sequence_id' => $seq, 'param' => $o2['print']['param'] ?? '', 'result' => 'SUCCESS', 'reason' => 'SUCCESS']]));
                    }
                }
            }
        }
    }
    unset($cl);

    // regelmaessige Reports (etwa einmal pro Sekunde)
    if ($now - $lastPush >= 1.0) {
        $lastPush = $now;
        foreach ($clients as $id => &$cl) {
            if (!$cl['ok'] || !$cl['sub']) {
                continue;
            }
            if ($cfg['modell'] === 'p1') {                      // nur geaenderte Werte der obersten Ebene
                $diff = [];
                foreach ($print as $k => $v) {
                    if (!array_key_exists($k, $cl['last']) || $cl['last'][$k] !== $v) {
                        $diff[$k] = $v;
                    }
                }
                $cl['last'] = $print;
                if ($diff) {
                    $publishReport($cl['s'], $report($diff));
                }
            } else {
                $publishReport($cl['s'], $report($print));
            }
        }
        unset($cl);
    }
}
