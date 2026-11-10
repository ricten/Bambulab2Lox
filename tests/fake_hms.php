<?php
/**
 * Fake der Bambu-Textliste fuer Tests: php tests/fake_hms.php <port> <verzeichnis>
 * Antwortet per HTTP (nur Tests; das Plugin nutzt bei Bambu selbst HTTPS mit Zertifikatspruefung) auf GET /query.php?lang=<de|en>&d=<Modell>
 * mit einer kleinen Liste im Format der echten Datenbank (data.device_hms.<lang>[] und data.device_error.<lang>[] mit ecode und intro).
 * Die Texte sind eigene Testtexte, keine Texte von Bambu.
 * Schreibt <verzeichnis>/hms.log (eine Zeile je Abruf: "GET <uri>"); <verzeichnis>/hms_ctl.json {"modus": "ok" | "503" | "kaputt" | "gross" | "leer"} steuert die Antwort.
 */
$port = (int)($argv[1] ?? 18844);
$dir = $argv[2] ?? sys_get_temp_dir();
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}
$liste = function (string $lang): array {
    $de = $lang === 'de';
    return [
        'device_hms' => ['ver' => 1, $lang => [
            ['ecode' => '0501040000030002', 'intro' => $de ? 'Testtext: Gewindestangen schmieren (Prüfung: ÄÖÜ ß, 日本語)' : 'Test text: lubricate the threaded rods'],
            ['ecode' => '050002000003000a', 'intro' => $de ? 'Testtext: zweite Meldung' : 'Test text: second message'],     // klein geschrieben: wird gross gelesen
            ['ecode' => 'KEINHEX', 'intro' => 'wird verworfen'],
        ]],
        'device_error' => ['ver' => 1, $lang => [
            ['ecode' => '05004042', 'intro' => $de ? 'Testtext: Fehler des Druckers' : 'Test text: printer error'],
        ]],
    ];
};
echo "listening $port\n";
while (true) {
    $r = [$srv];
    $w = $x = null;
    if (!stream_select($r, $w, $x, 0, 200000)) {
        continue;
    }
    $c = @stream_socket_accept($srv, 2);
    if (!$c) {
        continue;
    }
    stream_set_timeout($c, 2);
    $req = '';
    while (!str_contains($req, "\r\n\r\n") && strlen($req) < 8192) {
        $d = fread($c, 4096);
        if ($d === '' || $d === false) {
            break;
        }
        $req .= $d;
    }
    $zeile = strtok($req, "\r\n") ?: '';
    if ($zeile === '') {                    // Verbindungstest ohne Anfrage (Warten auf den Port)
        fclose($c);
        continue;
    }
    $uri = explode(' ', $zeile)[1] ?? '/';
    file_put_contents("$dir/hms.log", 'GET ' . $uri . "\n", FILE_APPEND);
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
    $lang = ($q['lang'] ?? 'de') === 'en' ? 'en' : 'de';
    $ctl = is_file("$dir/hms_ctl.json") ? json_decode((string)file_get_contents("$dir/hms_ctl.json"), true) : [];
    $modus = is_array($ctl) ? ($ctl['modus'] ?? 'ok') : 'ok';
    $status = '200 OK';
    if ($modus === '503') {
        $status = '503 Service Unavailable';
        $body = '{"result":1}';
    } elseif ($modus === 'kaputt') {
        $body = '{"result":0,"data":{';
    } elseif ($modus === 'leer') {
        $body = json_encode(['result' => 0, 'data' => ['device_hms' => ['ver' => 1, 'de' => []], 'device_error' => ['ver' => 1, 'de' => []]]]);
    } elseif ($modus === 'gross') {
        $body = str_repeat(' ', 5500000) . '{}';
    } else {
        $body = json_encode(['result' => 0, 't' => time(), 'ver' => 1, 'data' => $liste($lang)], JSON_UNESCAPED_UNICODE);
    }
    fwrite($c, "HTTP/1.1 $status\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n");
    $o = 0;
    $n = strlen($body);
    while ($o < $n) {
        $k = @fwrite($c, substr($body, $o, 65536));
        if ($k === false || $k === 0) {
            break;
        }
        $o += $k;
    }
    fclose($c);
}
