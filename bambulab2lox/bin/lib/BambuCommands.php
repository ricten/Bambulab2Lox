<?php
/**
 * Befehle aus Loxone an den Drucker: bambulab/befehl/<name>/<befehl> = Wert, Quittung unter .../quittung (nie retained).
 *
 * Erlaubt sind nur die hier aufgelisteten Befehle, in drei Stufen, die je Drucker einzeln freizugeben sind (ab Werk alle gesperrt):
 *   licht  - Kammerlicht, zweites Kammerlicht, Arbeitslicht
 *   druck  - Pause, Fortsetzen, Geschwindigkeit
 *   stopp  - Druck abbrechen
 * Nie vorgesehen: rohes G-Code, Druckauftraege, Firmware, Kalibrierung, Access-Code-Befehle.
 */
final class BambuCommands
{
    /** Befehl => [Stufe, erlaubte Werte als Regex, Bezeichnung]. */
    public const array LISTE = [
        'licht_kammer' => ['licht', '/^[01]$/', 'Kammerlicht (1 an, 0 aus)'],
        'licht_kammer2' => ['licht', '/^[01]$/', 'zweites Kammerlicht (1 an, 0 aus; nur H2)'],
        'licht_arbeit' => ['licht', '/^[01]$/', 'Arbeitslicht (1 an, 0 Ruhezustand/blinkend)'],
        'pause' => ['druck', '/^1$/', 'Druck pausieren (nur während des Drucks)'],
        'fortsetzen' => ['druck', '/^1$/', 'Pausierten Druck fortsetzen'],
        'geschwindigkeit' => ['druck', '/^[1-4]$/', 'Geschwindigkeit (1 leise, 2 standard, 3 sport, 4 irrsinn)'],
        'stopp' => ['stopp', '/^1$/', 'Druck abbrechen (während des Drucks oder in der Pause)'],
    ];

    /** Befehlstopic zerlegen: bambulab/befehl/<name>/<befehl>; Quittungen (…/quittung) und Fremdes ergeben null. @return array{0:string,1:string}|null */
    public static function parseTopic(string $topic): ?array
    {
        return preg_match('#^bambulab/befehl/([a-z0-9_-]+)/([a-z0-9_]+)$#', $topic, $m) ? [$m[1], $m[2]] : null;
    }

    /**
     * Prueft einen Befehl gegen Freigabe, Wert und Zustand. Liefert null (in Ordnung) oder den Ablehnungsgrund (ohne "abgelehnt: ").
     * @param array $befehle Freigaben des Druckers (licht, druck, stopp)
     */
    public static function pruefen(array $befehle, string $befehl, string $wert, ?string $gcodeState): ?string
    {
        if (!isset(self::LISTE[$befehl])) {
            return 'Befehl nicht erlaubt';
        }
        [$stufe, $regex] = self::LISTE[$befehl];
        if (empty($befehle[$stufe])) {
            return 'Befehle sind gesperrt (Stufe „' . BambuConfig::STUFEN[$stufe] . '“ freigeben)';
        }
        if (!preg_match($regex, trim($wert))) {
            return 'ungültiger Wert';
        }
        $state = $gcodeState !== null ? strtoupper($gcodeState) : null;
        if ($befehl === 'pause' && $state !== 'RUNNING') {
            return 'es läuft kein Druck';
        }
        if ($befehl === 'fortsetzen' && $state !== 'PAUSE') {
            return 'der Druck ist nicht pausiert';
        }
        if ($befehl === 'stopp' && !in_array($state, ['RUNNING', 'PAUSE'], true)) {
            return 'es läuft kein Druck';
        }
        return null;
    }

    /**
     * Nachricht an den Drucker (device/<Seriennummer>/request) bauen.
     * @return array{0:string,1:string,2:int}  [Typ (print|system), JSON, QoS]; Pause, Fortsetzen und Stopp laufen mit QoS 1
     */
    public static function bauen(string $befehl, string $wert, string $seq): array
    {
        $wert = trim($wert);
        $led = fn(string $node, string $modus) => ['system', json_encode(['system' => ['sequence_id' => $seq, 'command' => 'ledctrl', 'led_node' => $node, 'led_mode' => $modus,
            'led_on_time' => 500, 'led_off_time' => 500, 'loop_times' => 1, 'interval_time' => 1000]]), 0];
        $druck = fn(string $cmd, string $param, int $qos) => ['print', json_encode(['print' => ['sequence_id' => $seq, 'command' => $cmd, 'param' => $param]]), $qos];
        return match ($befehl) {
            'licht_kammer' => $led('chamber_light', $wert === '1' ? 'on' : 'off'),
            'licht_kammer2' => $led('chamber_light2', $wert === '1' ? 'on' : 'off'),
            'licht_arbeit' => $led('work_light', $wert === '1' ? 'on' : 'flashing'),      // Ruhezustand des Arbeitslichts am H2C: flashing
            'pause' => $druck('pause', '', 1),
            'fortsetzen' => $druck('resume', '', 1),
            'stopp' => $druck('stop', '', 1),
            'geschwindigkeit' => $druck('print_speed', $wert, 0),
        };
    }

    /** Drucker-Befehl, der zu einem Loxone-Befehl gehoert (steht in der Antwort unter `command`). */
    public static function druckerBefehl(string $befehl): string
    {
        return match ($befehl) {
            'licht_kammer', 'licht_kammer2', 'licht_arbeit' => 'ledctrl',
            'fortsetzen' => 'resume',
            'stopp' => 'stop',
            'geschwindigkeit' => 'print_speed',
            default => $befehl,
        };
    }

    /**
     * Antwort des Druckers auswerten (Objekt unter `print` oder `system` mit result und reason; Vergleich ohne Beachtung von Gross- und Kleinschreibung:
     * ledctrl antwortet "success", die Druckbefehle "SUCCESS").
     * @return string "ausgefuehrt" oder "abgelehnt: <Grund>"
     */
    public static function antwort(array $a): string
    {
        $result = strtolower((string)($a['result'] ?? ''));
        if ($result === 'success') {
            return 'ausgefuehrt';
        }
        $reason = trim((string)($a['reason'] ?? ''));
        if (stripos($reason, 'verify failed') !== false) {
            return 'abgelehnt: Drucker verlangt den Entwicklermodus';       // Authorization Control der Firmware: print.*-Befehle brauchen ihn
        }
        return 'abgelehnt: ' . ($reason !== '' && strtolower($reason) !== 'fail' ? $reason : ($result !== '' ? $result : 'Drucker lehnt ab'));
    }
}
