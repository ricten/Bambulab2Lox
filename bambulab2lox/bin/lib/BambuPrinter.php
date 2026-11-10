<?php
/**
 * Ein Drucker: TLS-MQTT-Verbindung zum Broker des Druckers (Port 8883, Benutzer bblp, Passwort = Access Code),
 * zusammengefuehrter Zustand, Veroeffentlichen nur geaenderter Werte und Befehle mit Quittung.
 *
 * Erwartete Zustaende sind kein Fehler: Ist der Drucker ausgeschaltet (nicht erreichbar) und lief kein Druck, bleibt status/ok = 1.
 * Ein falscher Access Code, ein Verbindungsverlust waehrend des Drucks oder ein Drucker, der trotz Verbindung nicht antwortet, sind Fehler.
 */
final class BambuPrinter
{
    private const int REFRESH = 300;            // Sekunden: alle Werte auch ohne Aenderung erneut senden
    private const int ACK_TIMEOUT = 10;         // Sekunden bis "keine Antwort des Druckers"
    private const float MIN_ABSTAND = 1.0;      // hoechstens ein Befehl je Sekunde

    public string $name;
    private ?MqttClient $mqtt = null;
    private array $print = [];                  // zusammengefuehrtes Objekt `print`
    private array $ver = [];                    // modell, firmware
    private array $sent = [];                   // Topic => [Wert, Zeit]
    private int $nextConnect = 0;
    private int $backoff = 2;
    private string $error = '';
    private bool $authFehler = false;
    private bool $versucht = false;             // mindestens ein Verbindungsversuch gemacht
    private int $connectedSince = 0;
    private int $lastReport = 0;
    private int $lastPushall = 0;
    private array $pending = [];                // sequence_id => [befehl, drucker-befehl, frist]
    private array $acks = [];                   // fertige Quittungen: [befehl, text]
    private float $lastCmd = 0.0;
    private int $seq;
    private int $reports = 0;

    public function __construct(private array $cfg, private readonly Logger $log, private readonly string $folder, private int $pushallAbstand = 300)
    {
        $this->name = $cfg['name'];
        $this->seq = 1000 + random_int(0, 8000);   // klar ueber den sequence_id des Druckers (zaehlt ab 0)
    }

    /** Einstellungen aktualisieren; bei geaendertem Zugang wird neu verbunden. */
    public function configure(array $cfg, int $pushallAbstand): void
    {
        $zugang = fn(array $c) => [$c['host'], $c['port'] ?? 8883, $c['seriennummer'], $c['zugangscode']];
        if ($zugang($cfg) !== $zugang($this->cfg) && $this->mqtt !== null) {
            $this->mqtt->close();
            $this->mqtt = null;
            $this->nextConnect = 0;
            $this->backoff = 2;
            $this->authFehler = false;
        }
        $this->cfg = $cfg;
        $this->name = $cfg['name'];
        $this->pushallAbstand = $pushallAbstand;
    }

    public function connected(): bool
    {
        return $this->mqtt !== null && $this->mqtt->connected();
    }

    private function topicReport(): string
    {
        return 'device/' . $this->cfg['seriennummer'] . '/report';
    }

    private function topicRequest(): string
    {
        return 'device/' . $this->cfg['seriennummer'] . '/request';
    }

    private function sendRequest(string $json, int $qos = 0): bool
    {
        return $this->mqtt !== null && $this->mqtt->publish($this->topicRequest(), $json, false, $qos);
    }

    private function nextSeq(): string
    {
        return (string)(++$this->seq);
    }

    /** Verbindung halten, Nachrichten verarbeiten, Zeitueberschreitungen der Befehle. */
    public function tick(int $now): void
    {
        if (!$this->connected()) {
            if ($now >= $this->nextConnect) {
                $this->verbinden($now);
            }
            $this->ackFristen($now);
            return;
        }
        if (!$this->mqtt->loop()) {
            $this->error = 'Verbindung zum Drucker verloren: ' . $this->mqtt->lastError();
            $this->log->warn("[{$this->name}] " . $this->error, 'lost-' . $this->name);
            $this->mqtt = null;
            $this->nextConnect = $now + 2;
            $this->backoff = 2;
            return;
        }
        foreach ($this->mqtt->takeMessages() as [$topic, $payload]) {
            if ($topic === $this->topicReport()) {
                $obj = json_decode($payload, true);
                if (is_array($obj)) {
                    $this->aufnehmen($obj, $now);
                }
            }
        }
        // Modelle mit Teil-Updates (P1, A1): den vollen Zustand regelmaessig neu anfordern
        if (BambuMapper::teilUpdates($this->ver['modell'] ?? null) && $now - $this->lastPushall >= $this->pushallAbstand) {
            $this->pushall($now);
        }
        $this->ackFristen($now);
    }

    private function verbinden(int $now): void
    {
        $this->versucht = true;
        $c = $this->cfg;
        $this->mqtt = new MqttClient($c['host'], (int)($c['port'] ?? 8883), 'bblp', $c['zugangscode'],
            'lox_' . $this->folder . '_' . $this->name, '', 60, true, 3);
        $this->mqtt->subscribe($this->topicReport());
        $this->mqtt->acceptRetained(true);
        if ($this->mqtt->connect()) {
            $this->log->info("[{$this->name}] mit dem Drucker verbunden ({$c['host']}:" . ($c['port'] ?? 8883) . ')');
            $this->error = '';
            $this->authFehler = false;
            $this->connectedSince = $now;
            $this->backoff = 2;
            $this->sendRequest(json_encode(['info' => ['sequence_id' => $this->nextSeq(), 'command' => 'get_version']]));
            $this->pushall($now);
            return;
        }
        $err = $this->mqtt->lastError();
        $this->mqtt = null;
        $this->authFehler = stripos($err, 'Benutzer/Passwort falsch') !== false || stripos($err, 'nicht autorisiert') !== false;
        $this->error = $this->authFehler ? 'Anmeldung abgelehnt: Access Code falsch?' : 'nicht erreichbar: ' . $err;
        if ($this->authFehler) {
            $this->log->error("[{$this->name}] {$this->error}", 'auth-' . $this->name);
        } else {
            $this->log->info("[{$this->name}] {$this->error}", 'down-' . $this->name);
        }
        $this->nextConnect = $now + $this->backoff;
        $this->backoff = min($this->authFehler ? 120 : 60, $this->backoff * 2);
    }

    private function pushall(int $now): void
    {
        $this->lastPushall = $now;
        $this->sendRequest(json_encode(['pushing' => ['sequence_id' => $this->nextSeq(), 'command' => 'pushall', 'version' => 1, 'push_target' => 1]]));
    }

    /** Einen Report (decodiertes JSON) verarbeiten: Zustand zusammenfuehren, Version merken, Befehlsantworten zuordnen. */
    public function aufnehmen(array $obj, int $now): void
    {
        $this->lastReport = $now;
        if (is_array($obj['print'] ?? null)) {
            $p = $obj['print'];
            if (($p['command'] ?? '') === 'push_status') {
                $this->print = BambuState::merge($this->print, $p);
                $this->reports++;
            } else {
                $this->antwort($p, $now);
            }
        }
        if (is_array($obj['system'] ?? null)) {
            $this->antwort($obj['system'], $now);
        }
        if (is_array($obj['info'] ?? null) && ($obj['info']['command'] ?? '') === 'get_version' && is_array($obj['info']['module'] ?? null)) {
            $this->ver = BambuMapper::version($obj['info']['module']);
        }
    }

    /** Antwort auf einen unserer Befehle (gleiche sequence_id und gleicher Befehl). */
    private function antwort(array $a, int $now): void
    {
        $seq = (string)($a['sequence_id'] ?? '');
        if ($seq === '' || !isset($this->pending[$seq]) || ($a['command'] ?? '') !== $this->pending[$seq][1] || !isset($a['result'])) {
            return;
        }
        [$befehl] = $this->pending[$seq];
        unset($this->pending[$seq]);
        $text = BambuCommands::antwort($a);
        if (str_contains($text, 'Entwicklermodus')) {
            $this->devModusFehlt = true;                    // Druckbefehl wurde wegen der Authorization Control abgelehnt
        } elseif (in_array($befehl, ['pause', 'fortsetzen', 'geschwindigkeit', 'stopp'], true) && str_starts_with($text, 'ausgef')) {
            $this->devModusFehlt = false;                   // Druckbefehl ging durch: Entwicklermodus ist an
        }
        $this->acks[] = [$befehl, $text];
        $this->log->info("[{$this->name}] Befehl $befehl: $text");
    }

    private function ackFristen(int $now): void
    {
        foreach ($this->pending as $seq => [$befehl, , $frist]) {
            if ($now >= $frist) {
                unset($this->pending[$seq]);
                $this->acks[] = [$befehl, 'abgelehnt: keine Antwort des Druckers'];
                $this->log->warn("[{$this->name}] Befehl $befehl: keine Antwort des Druckers");
            }
        }
    }

    /** Fertige Quittungen abholen: Liste von [befehl, text]. */
    public function takeAcks(): array
    {
        $a = $this->acks;
        $this->acks = [];
        return $a;
    }

    /**
     * Befehl aus Loxone ausfuehren. Gibt die erste Quittung zurueck: "angenommen" oder "abgelehnt: <Grund>".
     * Das Ergebnis des Druckers folgt spaeter ueber takeAcks().
     */
    public function befehl(string $befehl, string $wert, float $jetzt): string
    {
        $grund = BambuCommands::pruefen($this->cfg['befehle'], $befehl, $wert, $this->print['gcode_state'] ?? null);
        if ($grund === null && !$this->connected()) {
            $grund = 'Drucker nicht verbunden';
        }
        if ($grund === null && $jetzt - $this->lastCmd < self::MIN_ABSTAND) {
            $grund = 'zu schnell (höchstens ein Befehl je Sekunde)';
        }
        if ($grund !== null) {
            $this->log->warn("[{$this->name}] Befehl $befehl abgelehnt: $grund");
            return 'abgelehnt: ' . $grund;
        }
        $this->lastCmd = $jetzt;
        $seq = $this->nextSeq();
        [, $json, $qos] = BambuCommands::bauen($befehl, trim($wert), $seq);
        if (!$this->sendRequest($json, $qos)) {
            $this->log->error("[{$this->name}] Befehl $befehl konnte nicht gesendet werden");
            return 'abgelehnt: Senden zum Drucker fehlgeschlagen';
        }
        $this->pending[$seq] = [$befehl, BambuCommands::druckerBefehl($befehl), (int)$jetzt + self::ACK_TIMEOUT];
        $this->log->info("[{$this->name}] Befehl $befehl gesendet");
        return 'angenommen';
    }

    /** @return array<string,string> alle aktuellen Topics (relativ), ohne verbunden */
    public function werte(bool $hmsText): array
    {
        return $this->print ? BambuMapper::map($this->print, $this->ver, $hmsText) : [];
    }

    /** Wahr, wenn der Drucker zuletzt einen Druckbefehl wegen fehlendem Entwicklermodus abgelehnt hat. */
    private bool $devModusFehlt = false;

    /** Deutscher Klartext des Zustands (bereit, druckt, pausiert, fertig, abgebrochen, Fehler). */
    public function zustandText(): ?string
    {
        $st = $this->zustand();
        if ($st === null) {
            return null;
        }
        $fehler = (int)($this->print['print_error'] ?? 0) !== 0 || (string)($this->print['mc_print_error_code'] ?? '0') !== '0';
        return BambuMapper::zustandText($st, $fehler);
    }

    public function zustand(): ?string
    {
        return isset($this->print['gcode_state']) ? strtoupper((string)$this->print['gcode_state']) : null;
    }

    /**
     * Geaenderte Werte (und alle 5 Minuten alle) an den LoxBerry-Broker senden.
     * @return int Zahl der gesendeten Topics
     */
    public function veroeffentlichen(MqttClient $out, int $now, bool $hmsText): int
    {
        $n = 0;
        $alle = ['verbunden' => ($this->connected() && $this->reports > 0) ? '1' : '0'];
        if ($this->connected()) {
            $alle += $this->werte($hmsText);
        }
        foreach ($alle as $t => $v) {
            $key = "bambulab/{$this->name}/$t";
            if (!isset($this->sent[$key]) || $this->sent[$key][0] !== $v || $now - $this->sent[$key][1] >= self::REFRESH) {
                if ($out->publish($key, $v)) {
                    $this->sent[$key] = [$v, $now];
                    $n++;
                }
            }
        }
        return $n;
    }

    /** Beim neuen Verbinden zum LoxBerry-Broker alle Werte erneut senden. */
    public function vergesseGesendet(): void
    {
        $this->sent = [];
    }

    public function close(): void
    {
        if ($this->mqtt !== null) {
            $this->mqtt->close();
            $this->mqtt = null;
        }
    }

    /**
     * Zustand fuer status.json und Healthcheck.
     * ok: false nur bei echten Fehlern; aus: Drucker nicht erreichbar, aber erwartet (kein Druck lief).
     * @return array{ok:bool,aus:bool,error:string,verbunden:bool,zustand:?string,modell:?string,firmware:?string,werte:int,reports:int}
     */
    public function status(int $now): array
    {
        $st = $this->zustand();
        $base = ['verbunden' => $this->connected(), 'zustand' => $st, 'zustand_text' => $this->zustandText(), 'dev_modus_fehlt' => $this->devModusFehlt, 'modell' => $this->ver['modell'] ?? null, 'firmware' => $this->ver['firmware'] ?? null,
            'werte' => count($this->werte(true)), 'reports' => $this->reports];
        if ($this->connected()) {
            if ($now - $this->connectedSince > 60 && $this->lastReport < $this->connectedSince) {
                return ['ok' => false, 'aus' => false, 'error' => 'Der Drucker antwortet nicht (keine Reports seit der Anmeldung)'] + $base;
            }
            return ['ok' => true, 'aus' => false, 'error' => ''] + $base;
        }
        if (!$this->versucht) {
            return ['ok' => true, 'aus' => false, 'error' => ''] + $base;
        }
        if ($this->authFehler) {
            return ['ok' => false, 'aus' => false, 'error' => $this->error] + $base;
        }
        if (in_array($st, ['RUNNING', 'PAUSE', 'PREPARE'], true)) {
            return ['ok' => false, 'aus' => false, 'error' => 'Verbindung verloren, während ein Druck lief (' . $this->error . ')'] + $base;
        }
        return ['ok' => true, 'aus' => true, 'error' => $this->error] + $base;     // ausgeschaltet oder nicht im Netz: erwartet
    }
}
