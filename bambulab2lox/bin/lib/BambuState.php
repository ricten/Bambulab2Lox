<?php
/**
 * Zustand eines Druckers aus den Reports (device/<Seriennummer>/report) zusammenfuehren.
 *
 * X1 und H2 senden bei jedem Report das volle Objekt, P1 und A1 nur geaenderte Werte: deshalb Deep Merge.
 * Listen werden nur dort ueber ihren Schluessel zusammengefuehrt, wo die Eintraege stabil sind (AMS-Einheiten und Slots ueber `id`,
 * Lichter ueber `node`, Extruder ueber `id`); alle anderen Listen ersetzt der neue Wert (z. B. hms, device.nozzle.info:
 * beim Duesenwechsel aendern sich die ids der Plaetze, ein Merge liesse alte Eintraege stehen).
 */
final class BambuState
{
    /** Pfade (Punktschreibweise, Listenindex als *), deren Listen ueber den Schluessel zusammengefuehrt werden: Pfad => Schluessel. */
    private const array LISTEN = [
        'ams.ams' => 'id',
        'ams.ams.*.tray' => 'id',
        'lights_report' => 'node',
        'device.extruder.info' => 'id',
        'vir_slot' => 'id',
    ];

    /** Neuen Report (Teilobjekt oder vollstaendig) in den Zustand einarbeiten. */
    public static function merge(array $state, array $new, string $path = ''): array
    {
        foreach ($new as $k => $v) {
            $p = $path === '' ? (string)$k : $path . '.' . $k;
            if (is_array($v) && !array_is_list($v) && isset($state[$k]) && is_array($state[$k]) && !array_is_list($state[$k])) {
                $state[$k] = self::merge($state[$k], $v, $p);
            } elseif (is_array($v) && array_is_list($v) && isset($state[$k]) && is_array($state[$k]) && array_is_list($state[$k])
                && ($key = self::listKey($p)) !== null) {
                $state[$k] = self::mergeList($state[$k], $v, $key, $p);
            } else {
                $state[$k] = $v;
            }
        }
        return $state;
    }

    private static function listKey(string $path): ?string
    {
        return self::LISTEN[(string)preg_replace('/\.\d+\./', '.*.', $path)] ?? null;
    }

    private static function mergeList(array $alt, array $neu, string $key, string $path): array
    {
        foreach ($neu as $item) {
            if (!is_array($item) || !isset($item[$key])) {
                continue;
            }
            $found = false;
            foreach ($alt as $i => $a) {
                if (is_array($a) && isset($a[$key]) && (string)$a[$key] === (string)$item[$key]) {
                    $alt[$i] = self::merge($a, $item, $path . '.' . $i);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $alt[] = $item;
            }
        }
        return array_values($alt);
    }
}
