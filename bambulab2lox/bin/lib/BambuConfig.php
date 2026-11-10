<?php
/**
 * Konfiguration des Plugins (bambulab.json, Rechte 0600).
 * Enthaelt die Access Codes der Drucker und ggf. das Passwort eines eigenen MQTT-Brokers: nie anzeigen, nie loggen, nie per MQTT senden.
 */
final class BambuConfig
{
    public const int MAX_DRUCKER = 8;
    /** Stufen der Befehlsfreigabe je Drucker: licht (Lichter), druck (Pause, Fortsetzen, Geschwindigkeit), stopp (Druck abbrechen). */
    public const array STUFEN = ['licht' => 'Licht', 'druck' => 'Pause, Fortsetzen, Geschwindigkeit', 'stopp' => 'Stopp'];

    public static function defaultDrucker(string $name = ''): array
    {
        return ['aktiv' => false, 'name' => $name, 'host' => '', 'port' => 8883, 'seriennummer' => '', 'zugangscode' => '',
            'befehle' => ['licht' => false, 'druck' => false, 'stopp' => false]];
    }

    public static function defaults(): array
    {
        $d = [self::defaultDrucker('drucker1')];
        while (count($d) < self::MAX_DRUCKER) {
            $d[] = self::defaultDrucker();
        }
        return [
            'pushall_abstand' => 300,    // Sekunden: nur bei Modellen mit Teil-Updates (P1, A1) wird der volle Zustand so oft neu angefordert
            'hms_text' => true,          // HMS-Meldungen als Text (HMS_xxxx_xxxx_xxxx_xxxx) senden
            'mqtt' => ['eigener_broker' => false, 'host' => '', 'port' => 1883, 'benutzer' => '', 'passwort' => ''],
            'drucker' => $d,
        ];
    }

    public static function cleanBefehle($b): array
    {
        $b = is_array($b) ? $b : [];
        $out = [];
        foreach (array_keys(self::STUFEN) as $k) {
            $out[$k] = !empty($b[$k]);
        }
        return $out;
    }

    public static function load(string $file): array
    {
        $def = self::defaults();
        $c = Util::jsonLoad($file);
        $out = array_merge($def, $c);
        $d = [];
        foreach (is_array($c['drucker'] ?? null) ? $c['drucker'] : $def['drucker'] as $x) {
            $g = array_merge(self::defaultDrucker(), is_array($x) ? $x : []);
            $g['aktiv'] = !empty($g['aktiv']);
            $g['port'] = self::cleanPort($g['port']);
            $g['befehle'] = self::cleanBefehle($g['befehle']);
            $d[] = $g;
        }
        while (count($d) < self::MAX_DRUCKER) {
            $d[] = self::defaultDrucker();
        }
        $out['drucker'] = array_slice($d, 0, self::MAX_DRUCKER);
        $out['pushall_abstand'] = max(getenv('BBL_DEV_DIR') ? 1 : 60, min(3600, (int)$out['pushall_abstand']));
        $out['hms_text'] = (bool)$out['hms_text'];
        $out['mqtt'] = array_merge($def['mqtt'], is_array($c['mqtt'] ?? null) ? $c['mqtt'] : []);
        return $out;
    }

    /** Host: Name oder IP, ohne Schema und Pfad. */
    public static function cleanHost(string $h): string
    {
        $h = trim($h);
        $h = (string)preg_replace('#^[a-z]+://#i', '', $h);
        $h = explode('/', $h, 2)[0];
        return (string)preg_replace('/[^A-Za-z0-9_.-]/', '', $h);
    }

    /** MQTT-Port des Druckers (Standard 8883); leeres Feld = Standard. */
    public static function cleanPort($p): int
    {
        $p = (int)$p;
        return $p > 0 ? min(65535, $p) : 8883;
    }

    /** Seriennummer: Buchstaben und Ziffern (steckt im Topic device/<Seriennummer>/report), Grossbuchstaben wie am Drucker. */
    public static function cleanSeriennummer(string $s): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', trim($s)));
    }

    /** Access Code: kein Leerraum, keine Steuerzeichen. */
    public static function cleanZugangscode(string $s): string
    {
        return (string)preg_replace('/[\x00-\x20\x7f]/', '', $s);
    }

    /** Eingaben der Weboberflaeche pruefen und mit der bisherigen Konfiguration zusammenfuehren. */
    public static function fromPost(array $old, array $post): array
    {
        $new = $old;
        // Jeder Reiter sendet nur seine Abschnitte; fehlende bleiben unveraendert.
        if (isset($post['pushall_abstand'])) {
            $new['pushall_abstand'] = max(60, min(3600, (int)$post['pushall_abstand']));
            $new['hms_text'] = !empty($post['hms_text']);
        }
        if (is_array($post['drucker'] ?? null)) {
            for ($i = 0; $i < self::MAX_DRUCKER; $i++) {
                $p = is_array($post['drucker'][$i] ?? null) ? $post['drucker'][$i] : [];
                $code = self::cleanZugangscode((string)($p['zugangscode'] ?? ''));
                $new['drucker'][$i] = [
                    'aktiv' => !empty($p['aktiv']),
                    'name' => Util::name((string)($p['name'] ?? '')),
                    'host' => self::cleanHost((string)($p['host'] ?? '')),
                    'port' => self::cleanPort($p['port'] ?? 8883),
                    'seriennummer' => self::cleanSeriennummer((string)($p['seriennummer'] ?? '')),
                    'zugangscode' => $code !== '' ? $code : (string)($old['drucker'][$i]['zugangscode'] ?? ''),   // leer = unveraendert
                    'befehle' => self::cleanBefehle($p['befehle'] ?? []),
                ];
            }
        }
        if (is_array($post['mqtt'] ?? null)) {
            $m = $post['mqtt'];
            $mpw = (string)($m['passwort'] ?? '');
            $new['mqtt'] = [
                'eigener_broker' => !empty($m['eigener_broker']),
                'host' => (string)preg_replace('/[^A-Za-z0-9_.:-]/', '', trim((string)($m['host'] ?? ''))),
                'port' => max(1, min(65535, (int)($m['port'] ?? 1883))),
                'benutzer' => trim((string)($m['benutzer'] ?? '')),
                'passwort' => $mpw !== '' ? $mpw : (string)$old['mqtt']['passwort'],
            ];
        }
        return $new;
    }

    /**
     * Abzufragende Drucker (aktiv, mit Host, Seriennummer und Access Code). Der Name im Topic ist der Name, sonst drucker<Nummer>;
     * doppelte Namen bekommen eine Nummer.
     * @return list<array{name:string,host:string,port:int,seriennummer:string,zugangscode:string,befehle:array}>
     */
    public static function drucker(array $cfg): array
    {
        $out = [];
        $used = [];
        foreach ($cfg['drucker'] as $i => $g) {
            if (empty($g['aktiv']) || trim((string)$g['host']) === '' || trim((string)$g['seriennummer']) === '') {
                continue;
            }
            $name = Util::name((string)$g['name']);
            $name = $name !== '' ? $name : 'drucker' . ($i + 1);
            $base = $name;
            for ($n = 2; isset($used[$name]); $n++) {
                $name = $base . '_' . $n;
            }
            $used[$name] = true;
            $out[] = ['name' => $name, 'host' => trim((string)$g['host']), 'port' => self::cleanPort($g['port'] ?? 8883), 'seriennummer' => self::cleanSeriennummer((string)$g['seriennummer']),
                'zugangscode' => (string)$g['zugangscode'], 'befehle' => self::cleanBefehle($g['befehle'] ?? [])];
        }
        return $out;
    }

    /**
     * Eigener MQTT-Broker aus der Konfiguration (statt dem LoxBerry-Broker).
     * @return array{0:string,1:int,2:string,3:string}|null  [host, port, user, pass]; null = LoxBerry-Broker verwenden
     */
    public static function ownBroker(array $cfg): ?array
    {
        $m = $cfg['mqtt'];
        if (empty($m['eigener_broker']) || trim((string)$m['host']) === '') {
            return null;
        }
        return [trim((string)$m['host']), (int)$m['port'] > 0 ? (int)$m['port'] : 1883, (string)$m['benutzer'], (string)$m['passwort']];
    }

    /** Alle Geheimnisse der Konfiguration (fuer die Maskierung im Log). @return list<string> */
    public static function secrets(array $cfg): array
    {
        $s = [(string)$cfg['mqtt']['passwort']];
        foreach ($cfg['drucker'] as $g) {
            $s[] = (string)($g['zugangscode'] ?? '');
        }
        return array_values(array_filter($s, fn($x) => strlen($x) >= 4));
    }
}
