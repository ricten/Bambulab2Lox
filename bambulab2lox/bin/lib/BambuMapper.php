<?php
/**
 * Wandelt den zusammengefuehrten Zustand eines Bambu-Lab-Druckers (Objekt `print` aus den Reports) in MQTT-Topics um.
 * Topics sind relativ zu bambulab/<name>/; Werte sind reine Zahlen mit Punkt bzw. Text, Zustaende 0/1.
 * Felder, die ein Modell nicht liefert, fehlen im Ergebnis (nichts erfinden). Grundlage sind die Reports eines H2C und die
 * inoffizielle Beschreibung OpenBambuAPI; Annahmen stehen als Kommentar an der jeweiligen Stelle.
 */
final class BambuMapper
{
    /** Zahl als Text: Punkt als Dezimalzeichen, keine Nullen am Ende, null bei Unbrauchbarem. */
    public static function num($v): ?string
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '' || !is_numeric($v)) {
                return null;
            }
            $v += 0;
        }
        if (!is_int($v) && !is_float($v)) {
            return null;
        }
        if (is_float($v)) {
            if (!is_finite($v)) {
                return null;
            }
            $s = rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
            return $s === '-0' || $s === '' ? '0' : $s;
        }
        return (string)$v;
    }

    /**
     * Gepackter Wert (H2: device.extruder.info[k].temp, device.bed.info.temp, device.ctc.info.temp):
     * untere 16 Bit = Ist, obere 16 Bit = Soll (z. B. 3276848 = 48 Grad Ist, 50 Grad Soll). Kleine Werte sind nur der Istwert.
     * @return array{0:int,1:int|null}|null
     */
    public static function gepackt($v): ?array
    {
        if (!is_int($v) && !(is_string($v) && ctype_digit($v)) && !(is_float($v) && $v == (int)$v)) {
            return null;
        }
        $v = (int)$v;
        return $v > 0xFFFF ? [$v & 0xFFFF, ($v >> 16) & 0xFFFF] : [$v, null];
    }

    /** Luefter: Bambu meldet 0 bis 15 (15 = 100 %), wird in Prozent umgerechnet. Annahme: gleiche Skala bei allen Modellen. */
    public static function luefterProzent($v): ?string
    {
        $n = self::num($v);
        return $n === null ? null : (string)max(0, min(100, (int)round((float)$n / 15 * 100)));
    }

    /** HMS-Meldung als Text HMS_AAAA_BBBB_CCCC_DDDD (attr hoch/tief, code hoch/tief; der Klartext steht bei Bambu online). */
    public static function hmsText(array $h): ?string
    {
        if (!isset($h['attr'], $h['code']) || !is_numeric($h['attr']) || !is_numeric($h['code'])) {
            return null;
        }
        $a = (int)$h['attr'];
        $c = (int)$h['code'];
        return sprintf('HMS_%04X_%04X_%04X_%04X', ($a >> 16) & 0xFFFF, $a & 0xFFFF, ($c >> 16) & 0xFFFF, $c & 0xFFFF);
    }

    /** Modell und Firmware aus der Antwort auf get_version (Liste der Module). @return array{modell:?string,firmware:?string} */
    public static function version(array $module): array
    {
        foreach ($module as $m) {
            if (is_array($m) && ($m['name'] ?? '') === 'ota') {
                return ['modell' => ($m['product_name'] ?? '') !== '' ? (string)$m['product_name'] : null, 'firmware' => ($m['sw_ver'] ?? '') !== '' ? (string)$m['sw_ver'] : null];
            }
        }
        return ['modell' => null, 'firmware' => null];
    }

    /** Liefert der Drucker nur Teil-Updates (P1, A1)? Dann muss der volle Zustand regelmaessig neu angefordert werden. */
    public static function teilUpdates(?string $modell): bool
    {
        return $modell !== null && (bool)preg_match('/\b(P1|A1)/i', $modell);
    }

    /**
     * @param array $p   zusammengefuehrtes Objekt `print`
     * @param array $ver Ergebnis von version() oder leer
     * @return array<string,string> relatives Topic => Wert
     */
    /** Deutscher Klartext zum Zustand des Druckers; FAILED ohne Fehlercode ist ein Abbruch, kein Fehler. */
    public static function zustandText(?string $state, bool $fehlerCode = false): string
    {
        switch (strtoupper((string)$state)) {
            case 'IDLE':
                return 'bereit';
            case 'PREPARE':
                return 'bereitet vor';
            case 'RUNNING':
                return 'druckt';
            case 'PAUSE':
                return 'pausiert';
            case 'FINISH':
                return 'fertig';
            case 'FAILED':
                return $fehlerCode ? 'Fehler' : 'abgebrochen';
            default:
                return (string)$state;
        }
    }

    public static function map(array $p, array $ver = [], bool $hmsText = true, ?BambuHmsTexte $texte = null): array
    {
        $o = [];
        $set = function (string $t, ?string $v) use (&$o): void {
            if ($v !== null) {
                $o[$t] = $v;
            }
        };
        $dev = is_array($p['device'] ?? null) ? $p['device'] : [];
        $state = isset($p['gcode_state']) ? strtoupper((string)$p['gcode_state']) : null;

        // --- Zustand
        if ($state !== null && $state !== '') {
            $fehlerCode = (int)($p['print_error'] ?? 0) !== 0 || (string)($p['mc_print_error_code'] ?? '0') !== '0';
            $set('status_text', self::zustandText($state, $fehlerCode));      // deutscher Klartext (bereit, druckt, abgebrochen ...)
            $set('status_roh', $state);                                         // Rohwert des Druckers (IDLE, RUNNING, FAILED ...)
            $set('zustand/leerlauf', $state === 'IDLE' ? '1' : '0');
            $set('zustand/druckt', in_array($state, ['PREPARE', 'RUNNING'], true) ? '1' : '0');      // PREPARE (Vorbereitung) zaehlt als Druck
            $set('zustand/pausiert', $state === 'PAUSE' ? '1' : '0');
            $set('zustand/fertig', $state === 'FINISH' ? '1' : '0');
            // Ein vom Benutzer abgebrochener Druck steht wie ein fehlgeschlagener auf FAILED (am H2C beobachtet): nur der Fehlercode trennt beides
            $set('zustand/abgebrochen', $state === 'FAILED' && !$fehlerCode ? '1' : '0');
            $set('zustand/fehler', $state === 'FAILED' && $fehlerCode ? '1' : '0');
        }
        $set('fortschritt_prozent', self::num($p['mc_percent'] ?? null));
        $set('restzeit_min', self::num($p['mc_remaining_time'] ?? null));
        $layer = isset($p['layer_num']) && is_numeric($p['layer_num']) ? (int)$p['layer_num'] : null;
        $total = isset($p['total_layer_num']) && is_numeric($p['total_layer_num']) ? (int)$p['total_layer_num'] : null;
        if ($total !== null && $total > 0) {
            $set('schichten_gesamt', (string)$total);
        }
        // Plausibilitaet: beim Start meldet der Drucker kurz die Schichtzahl des vorigen Drucks (460 bei 30 Schichten gesamt)
        if ($layer !== null && $state !== 'PREPARE' && ($total === null || $total === 0 || $layer <= $total)) {
            $set('schicht', (string)$layer);
        }
        $name = (string)($p['subtask_name'] ?? '');
        if ($name === '' && isset($p['gcode_file']) && (string)$p['gcode_file'] !== '') {
            $name = basename((string)$p['gcode_file']);
        }
        if (isset($p['subtask_name']) || isset($p['gcode_file'])) {
            $set('auftrag', $name);
        }

        // --- Temperaturen
        $set('duese_c', self::num($p['nozzle_temper'] ?? null));
        $set('duese_soll_c', self::num($p['nozzle_target_temper'] ?? null));
        $set('bett_c', self::num($p['bed_temper'] ?? null));
        $set('bett_soll_c', self::num($p['bed_target_temper'] ?? null));
        if (isset($p['chamber_temper'])) {
            $set('kammer_c', self::num($p['chamber_temper']));
        } else {                                    // H2: Kammer in device.ctc.info.temp (gepackt) bzw. info.temp
            $ctc = self::gepackt($dev['ctc']['info']['temp'] ?? ($p['info']['temp'] ?? null));
            if ($ctc !== null) {
                $set('kammer_c', (string)$ctc[0]);
            }
        }

        // --- H2: zwei Duesen und Duesenhalter
        $ext = is_array($dev['extruder'] ?? null) ? $dev['extruder'] : null;
        if ($ext !== null) {
            if (isset($ext['state']) && is_numeric($ext['state'])) {
                $s = (int)$ext['state'];
                $akt = ($s >> 4) & 0xF;             // Deutung aus der Aufnahme eines Drucks mit Duesenwechseln, ohne offizielle Doku
                $set('duese/anzahl', (string)($s & 0xF));
                $set('duese/aktiv', $akt === 0xF ? '-1' : (string)$akt);
            }
            foreach (is_array($ext['info'] ?? null) ? $ext['info'] : [] as $e) {
                if (!is_array($e) || !isset($e['id']) || !is_numeric($e['id'])) {
                    continue;
                }
                $t = self::gepackt($e['temp'] ?? null);
                if ($t !== null) {
                    $set('duese/' . (int)$e['id'] . '/temperatur_c', (string)$t[0]);
                    if ($t[1] !== null) {
                        $set('duese/' . (int)$e['id'] . '/soll_c', (string)$t[1]);
                    }
                }
            }
        }
        if (is_array($dev['holder'] ?? null) && isset($dev['holder']['stat']) && is_numeric($dev['holder']['stat'])) {
            $set('duesenwechsel', (int)$dev['holder']['stat'] !== 0 ? '1' : '0');
        }
        foreach (is_array($dev['nozzle']['info'] ?? null) ? $dev['nozzle']['info'] : [] as $n) {
            if (!is_array($n) || !isset($n['id']) || !is_numeric($n['id']) || ($n['sn'] ?? '') === 'N/A' || (string)($n['type'] ?? '') === '') {
                continue;       // leerer Platz im Duesenhalter
            }
            $id = (int)$n['id'];
            $set("duesen/$id/typ", (string)$n['type']);
            $set("duesen/$id/durchmesser_mm", self::num($n['diameter'] ?? null));
            $set("duesen/$id/verschleiss", self::num($n['wear'] ?? null));
        }

        // --- Luefter, Geschwindigkeit, Licht, WLAN
        $set('luefter/bauteil_prozent', self::luefterProzent($p['cooling_fan_speed'] ?? null));
        $set('luefter/hilfs_prozent', self::luefterProzent($p['big_fan1_speed'] ?? null));
        $set('luefter/kammer_prozent', self::luefterProzent($p['big_fan2_speed'] ?? null));
        $set('luefter/duesenkuehlung_prozent', self::luefterProzent($p['heatbreak_fan_speed'] ?? null));
        $set('geschwindigkeit/stufe', self::num($p['spd_lvl'] ?? null));
        $set('geschwindigkeit/prozent', self::num($p['spd_mag'] ?? null));
        $lichter = ['chamber_light' => 'kammer', 'work_light' => 'arbeit', 'chamber_light2' => 'kammer2'];
        foreach (is_array($p['lights_report'] ?? null) ? $p['lights_report'] : [] as $l) {
            if (is_array($l) && isset($lichter[$l['node'] ?? ''])) {
                $set('licht/' . $lichter[$l['node']], ($l['mode'] ?? '') === 'on' ? '1' : '0');      // flashing (Ruhezustand des Arbeitslichts am H2C) = 0
            }
        }
        if (isset($p['wifi_signal']) && preg_match('/^\s*(-?\d+)/', (string)$p['wifi_signal'], $m)) {
            $set('wlan_dbm', $m[1]);
        }

        // --- Fehler und HMS
        $ec = $p['print_error'] ?? ($p['mc_print_error_code'] ?? null);
        $set('fehler/code', self::num($ec));
        if (is_array($p['hms'] ?? null)) {
            $codes = [];
            foreach ($p['hms'] as $h) {
                $t = is_array($h) ? self::hmsText($h) : null;
                if ($t !== null) {
                    $codes[] = $t;
                }
            }
            $set('fehler/hms_anzahl', (string)count($codes));
            if ($hmsText) {
                $set('fehler/hms_text', $codes ? implode(',', $codes) : 'keine');
            }
            if ($texte !== null) {
                // Klartext aus der Bambu-Textliste; fuer Meldungen ohne Text bleibt der Code stehen (nicht jeder Code hat einen Text)
                $klar = [];
                foreach ($p['hms'] as $h) {
                    $code = is_array($h) ? self::hmsText($h) : null;
                    if ($code !== null) {
                        $klar[] = $texte->hmsText($h) ?? $code;
                    }
                }
                $set('fehler/hms_klartext', $klar ? BambuHmsTexte::kuerzen(implode(' | ', $klar)) : 'keine');
                $set('fehler/hms_code', $codes ? $codes[0] : 'keine');
            }
        }
        if ($texte !== null && $ec !== null && is_numeric($ec)) {
            $n = (int)$ec;
            $set('fehler/text', $n === 0 ? 'keine' : BambuHmsTexte::kuerzen($texte->fehlerText($n) ?? BambuHmsTexte::ecodeFehler($n)));
        }

        // --- AMS
        $ams = is_array($p['ams'] ?? null) ? $p['ams'] : [];
        foreach (is_array($ams['ams'] ?? null) ? $ams['ams'] : [] as $a) {
            if (!is_array($a) || !isset($a['id']) || !is_numeric($a['id'])) {
                continue;
            }
            $n = (int)$a['id'];
            $temp = self::num($a['temp'] ?? null);
            if ($temp !== null && (float)$temp > -40) {                // AMS lite meldet -50.0 = ungueltig
                $set("ams/$n/temperatur_c", $temp);
            }
            $set("ams/$n/feuchte", self::num($a['humidity'] ?? null));
            $raw = self::num($a['humidity_raw'] ?? null);
            if ($raw !== null && (float)$raw > 0) {
                $set("ams/$n/feuchte_prozent", $raw);
            }
            $set("ams/$n/trocknung_min", self::num($a['dry_time'] ?? null));
            foreach (is_array($a['tray'] ?? null) ? $a['tray'] : [] as $t) {
                if (!is_array($t) || !isset($t['id']) || !is_numeric($t['id'])) {
                    continue;
                }
                $k = (int)$t['id'];
                $typ = (string)($t['tray_type'] ?? '');
                $set("ams/$n/slot/$k/belegt", $typ !== '' ? '1' : '0');
                if ($typ === '') {
                    continue;
                }
                $set("ams/$n/slot/$k/typ", $typ);
                if (isset($t['tray_color']) && preg_match('/^[0-9A-Fa-f]{6}/', (string)$t['tray_color'])) {
                    $set("ams/$n/slot/$k/farbe", strtoupper(substr((string)$t['tray_color'], 0, 6)));
                }
                $remain = self::num($t['remain'] ?? null);
                if ($remain !== null && (float)$remain >= 0) {            // -1 = unbekannt
                    $set("ams/$n/slot/$k/rest_prozent", $remain);
                }
            }
        }
        $set('ams/aktiv_slot', self::num($ams['tray_now'] ?? null));

        // --- Externe Spule(n): H2 liefert vir_slot (ids 254 und 255), X1/P1 vt_tray
        foreach (is_array($p['vir_slot'] ?? null) ? $p['vir_slot'] : [] as $t) {
            if (is_array($t) && isset($t['id']) && is_numeric($t['id']) && (string)($t['tray_type'] ?? '') !== '') {
                $k = (int)$t['id'] - 254;
                $set("extern/$k/typ", (string)$t['tray_type']);
                if (preg_match('/^[0-9A-Fa-f]{6}/', (string)($t['tray_color'] ?? ''))) {
                    $set("extern/$k/farbe", strtoupper(substr((string)$t['tray_color'], 0, 6)));
                }
            }
        }
        if (is_array($p['vt_tray'] ?? null) && (string)($p['vt_tray']['tray_type'] ?? '') !== '') {
            $set('extern/typ', (string)$p['vt_tray']['tray_type']);
            if (preg_match('/^[0-9A-Fa-f]{6}/', (string)($p['vt_tray']['tray_color'] ?? ''))) {
                $set('extern/farbe', strtoupper(substr((string)$p['vt_tray']['tray_color'], 0, 6)));
            }
        }

        // --- Info (die Seriennummer steht in der Konfiguration und wird nicht gesendet)
        if (!empty($ver['modell'])) {
            $set('info/modell', (string)$ver['modell']);
        }
        if (!empty($ver['firmware'])) {
            $set('info/firmware', (string)$ver['firmware']);
        }
        $set('info/duesen_durchmesser_mm', self::num($p['nozzle_diameter'] ?? null));
        if (isset($p['nozzle_type']) && (string)$p['nozzle_type'] !== '') {
            $set('info/duesen_typ', (string)$p['nozzle_type']);
        }
        return $o;
    }
}
