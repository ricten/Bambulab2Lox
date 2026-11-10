<?php
/**
 * Klartext zu den HMS-Meldungen und Fehlercodes eines Bambu-Lab-Druckers.
 *
 * Der Drucker meldet nur Zahlen (HMS: attr + code, Fehler: print_error). Die Texte stehen in einer oeffentlichen Liste bei Bambu
 * (https://e.bambulab.com/query.php?lang=<de|en>&d=<erste 3 Zeichen der Seriennummer>, JSON, ohne Anmeldung). Das Plugin laedt sie nur,
 * wenn die Option "HMS-Klartext" an ist, hoechstens einmal je Woche, und merkt sie sich im Datenordner. Es sendet nichts ausser dieser
 * GET-Abfrage (nur der Modellcode aus drei Zeichen der Seriennummer, nie der Access Code). Die Texte gehoeren Bambu und liegen nicht im Repo.
 *
 * Zuordnung: HMS {attr, code} -> sprintf('%08X%08X', attr, code) (16 Hex-Zeichen), Fehler print_error -> sprintf('%08X', n).
 * Nicht jeder Code hat einen Text; dann bleibt der Code stehen. Fehler beim Laden sind nie fatal: der Zwischenspeicher bleibt in Gebrauch,
 * neue Versuche fruehestens nach einer Stunde (verdoppelt sich bei jedem Fehlschlag, hoechstens ein Tag).
 */
final class BambuHmsTexte
{
    public const int WOCHE = 604800;          // Sekunden zwischen zwei Abrufen
    public const int MAX_BYTES = 5242880;     // Groessenlimit der Antwort (5 MB)
    public const int RETRY_BASIS = 3600;      // erster Wiederholungsabstand nach einem Fehler
    public const int RETRY_MAX = 86400;
    public const int MAX_LAENGE = 1000;       // Zeichen (Bytes) je Topic
    public const string BASIS_URL = 'https://e.bambulab.com/query.php';

    /** @var array<string,string> 16-stelliger HMS-Code => Text */
    private array $hms = [];
    /** @var array<string,string> 8-stelliger Fehlercode => Text */
    private array $fehler = [];
    private int $geladenAm = 0;               // Zeitpunkt des Abrufs, aus dem die Texte stammen (0 = noch keine)
    private int $naechsterVersuch = 0;
    private int $fehlschlaege = 0;
    private string $letzterFehler = '';
    private bool $cacheGelesen = false;
    private string $modell;
    private string $sprache;
    /** @var callable|null */
    private $fetch;

    /**
     * @param string        $verzeichnis Datenordner fuer den Zwischenspeicher
     * @param string        $seriennummer Seriennummer des Druckers (nur die ersten 3 Zeichen werden verwendet)
     * @param callable|null $fetch       Ersatz fuer den Abruf (Tests): function(string $url): ?string
     */
    public function __construct(private readonly string $verzeichnis, string $seriennummer, string $sprache = 'de', private readonly ?string $basisUrl = null, ?callable $fetch = null)
    {
        $this->modell = self::modellCode($seriennummer);
        $this->sprache = self::cleanSprache($sprache);
        $this->fetch = $fetch;
    }

    /** Modellcode der Datenbank: die ersten drei Zeichen der Seriennummer (Grossbuchstaben und Ziffern), sonst leer. */
    public static function modellCode(string $sn): string
    {
        $sn = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $sn));
        return strlen($sn) >= 3 ? substr($sn, 0, 3) : '';
    }

    public static function cleanSprache($s): string
    {
        return in_array($s, ['de', 'en'], true) ? (string)$s : 'de';
    }

    /** Schluessel zum Gleichsetzen von Druckern gleichen Modells und gleicher Sprache. */
    public function schluessel(): string
    {
        return ($this->modell !== '' ? $this->modell : 'alle') . '_' . $this->sprache;
    }

    public function datei(): string
    {
        return rtrim($this->verzeichnis, '/\\') . '/hms_' . $this->schluessel() . '.json';
    }

    public function url(): string
    {
        $basis = $this->basisUrl ?? (getenv('BBL_DEV_HMS_URL') ?: self::BASIS_URL);
        return $basis . '?lang=' . $this->sprache . ($this->modell !== '' ? '&d=' . $this->modell : '');
    }

    /** HMS-Code aus attr und code (Hex, 16 Zeichen). */
    public static function ecodeHms($attr, $code): string
    {
        return sprintf('%08X%08X', ((int)$attr) & 0xFFFFFFFF, ((int)$code) & 0xFFFFFFFF);
    }

    /** Fehlercode des Druckers (print_error) als Hex mit 8 Zeichen. */
    public static function ecodeFehler($n): string
    {
        return sprintf('%08X', ((int)$n) & 0xFFFFFFFF);
    }

    /** Liest den Zwischenspeicher, falls vorhanden (kein Netzzugriff). */
    public function ladeCache(): bool
    {
        $this->cacheGelesen = true;
        $j = Util::jsonLoad($this->datei());
        if (!is_array($j['hms'] ?? null) || !is_array($j['fehler'] ?? null)) {
            return false;
        }
        $this->hms = array_map('strval', $j['hms']);
        $this->fehler = array_map('strval', $j['fehler']);
        $this->geladenAm = (int)($j['abruf'] ?? 0);
        return true;
    }

    /**
     * Zwischenspeicher lesen und die Liste bei Bedarf neu laden (beim ersten Start und danach hoechstens einmal je Woche).
     * @return bool wahr, wenn gerade neue Texte geladen wurden
     */
    public function aktualisieren(int $now): bool
    {
        if (!$this->cacheGelesen) {
            $this->ladeCache();
            if ($this->geladenAm > $now) {
                $this->geladenAm = $now;                  // Uhr zurueckgestellt
            }
        }
        if ($this->geladenAm > 0 && $now - $this->geladenAm < self::WOCHE) {
            return false;
        }
        if ($now < $this->naechsterVersuch) {
            return false;
        }
        $raw = $this->holen($this->url());
        $liste = $raw === null ? null : self::parse($raw, $this->sprache);
        if ($liste === null) {
            $this->fehlschlaege++;
            $this->naechsterVersuch = $now + min(self::RETRY_MAX, self::RETRY_BASIS * (2 ** min(10, $this->fehlschlaege - 1)));
            $this->letzterFehler = $raw === null ? 'Abruf nicht möglich' : 'Antwort ohne brauchbare Textliste';
            return false;
        }
        $this->hms = $liste['hms'];
        $this->fehler = $liste['fehler'];
        $this->geladenAm = $now;
        $this->fehlschlaege = 0;
        $this->letzterFehler = '';
        Util::jsonSave($this->datei(), ['abruf' => $now, 'modell' => $this->modell, 'sprache' => $this->sprache, 'hms' => $this->hms, 'fehler' => $this->fehler]);
        return true;
    }

    /** Abruf der URL; null bei Fehler, Zeitueberschreitung (10 s) oder zu grosser Antwort. */
    private function holen(string $url): ?string
    {
        if ($this->fetch !== null) {
            try {
                $r = ($this->fetch)($url);
            } catch (Throwable $e) {
                return null;
            }
            return is_string($r) && strlen($r) <= self::MAX_BYTES ? $r : null;
        }
        $alt = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '10');
        try {
            $ctx = stream_context_create([
                'http' => ['method' => 'GET', 'timeout' => 10, 'follow_location' => 1, 'max_redirects' => 3, 'ignore_errors' => true,
                    'header' => "Accept: application/json\r\nUser-Agent: Bambulab2Lox\r\n"],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],        // echte Zertifikatspruefung (nur der Test-Fake laeuft per HTTP)
            ]);
            $fp = @fopen($url, 'r', false, $ctx);
            if ($fp === false) {
                return null;
            }
            $meta = stream_get_meta_data($fp);
            $kopf = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
            $status = 0;
            foreach ($kopf as $z) {
                if (is_string($z) && preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) {
                    $status = (int)$m[1];                  // bei Weiterleitung zaehlt die letzte Statuszeile
                }
            }
            $body = (string)stream_get_contents($fp, self::MAX_BYTES + 1);
            fclose($fp);
            if ($status !== 200 || strlen($body) > self::MAX_BYTES) {
                return null;
            }
            return $body;
        } catch (Throwable $e) {
            return null;
        } finally {
            ini_set('default_socket_timeout', is_string($alt) ? $alt : '60');
        }
    }

    /**
     * Antwort der Bambu-Datenbank auswerten: data.device_hms.<sprache>[] und data.device_error.<sprache>[] mit ecode und intro.
     * @return array{hms:array<string,string>,fehler:array<string,string>}|null null, wenn nichts Brauchbares darin steht
     */
    public static function parse(string $raw, string $sprache = 'de'): ?array
    {
        $j = json_decode($raw, true);
        if (!is_array($j) || !is_array($j['data'] ?? null)) {
            return null;
        }
        $hole = function ($liste, int $stellen): array {
            $out = [];
            foreach (is_array($liste) ? $liste : [] as $e) {
                if (!is_array($e) || !isset($e['ecode'], $e['intro']) || !is_string($e['ecode']) || !is_string($e['intro'])) {
                    continue;
                }
                $code = strtoupper(trim($e['ecode']));
                $text = trim((string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $e['intro']));
                if (!preg_match('/^[0-9A-F]{' . $stellen . '}$/', $code) || $text === '' || !preg_match('//u', $text)) {
                    continue;
                }
                $out[$code] = $text;
            }
            return $out;
        };
        $hms = $hole($j['data']['device_hms'][$sprache] ?? null, 16);
        $fehler = $hole($j['data']['device_error'][$sprache] ?? null, 8);
        return $hms || $fehler ? ['hms' => $hms, 'fehler' => $fehler] : null;
    }

    /** Text zu einer HMS-Meldung (Eintrag aus print.hms[] mit attr und code) oder null. */
    public function hmsText(array $h): ?string
    {
        if (!isset($h['attr'], $h['code']) || !is_numeric($h['attr']) || !is_numeric($h['code'])) {
            return null;
        }
        return $this->hms[self::ecodeHms($h['attr'], $h['code'])] ?? null;
    }

    /** Text zum Fehlercode des Druckers (print_error) oder null. */
    public function fehlerText(int $n): ?string
    {
        return $this->fehler[self::ecodeFehler($n)] ?? null;
    }

    /** Kuerzt auf hoechstens $max Bytes, nie mitten in einem UTF-8-Zeichen; ein Auslassungszeichen zeigt das Kuerzen. */
    public static function kuerzen(string $s, int $max = self::MAX_LAENGE): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        $s = substr($s, 0, max(0, $max - 3));
        while ($s !== '' && !preg_match('//u', $s)) {
            $s = substr($s, 0, -1);
        }
        return $s . '…';
    }

    /** Zustand fuer status.json und die Oberflaeche. @return array{geladen:int,hms:int,fehler:int,fehlschlaege:int,letzter_fehler:string,modell:string,sprache:string} */
    public function info(): array
    {
        return ['geladen' => $this->geladenAm, 'hms' => count($this->hms), 'fehler' => count($this->fehler), 'fehlschlaege' => $this->fehlschlaege,
            'letzter_fehler' => $this->letzterFehler, 'modell' => $this->modell, 'sprache' => $this->sprache];
    }
}
