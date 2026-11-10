<?php
/**
 * Bambulab-Dienst: haelt je Drucker eine TLS-MQTT-Verbindung zum Broker des Druckers, fuehrt die Reports zusammen und
 * veroeffentlicht nur geaenderte Werte auf dem MQTT-Broker des LoxBerry. Nimmt Befehle aus Loxone an (bambulab/befehl/<name>/<befehl>).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!getenv('BBL_DEV_DIR')) {
    require_once 'loxberry_system.php';
    require_once 'loxberry_io.php';
    require_once 'loxberry_log.php';
}
require_once __DIR__ . '/lib/bootstrap.php';

const T = 'bambulab';    // Topic-Wurzel

$paths = Paths::detect();

// Nur eine Instanz
$lock = fopen($paths->lockFile(), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
file_put_contents($paths->pidFile(), (string)getmypid());

$log = new Logger($paths->logfile, 'Dienst', 'Bambulab-Dienst');
/** Loglevel aus der LoxBerry-Pluginverwaltung (im Test BBL_DEV_LOGLEVEL). Wird im Betrieb regelmaessig neu gelesen. */
function applyLogLevel(Logger $log): void
{
    if (getenv('BBL_DEV_DIR')) {
        $log->setLevel(getenv('BBL_DEV_LOGLEVEL') ?: 'debug');
    } elseif (class_exists('LBSystem') && method_exists('LBSystem', 'pluginloglevel')) {
        $lvl = (int)LBSystem::pluginloglevel();
        if ($lvl > 0) {
            $log->setLoxberryLevel($lvl);
        }
    }
}
applyLogLevel($log);
$logLevelCheck = 0;
register_shutdown_function(function () use ($log) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $log->error('Dienst abgestuerzt: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')');
    }
});
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/** Broker-Daten: eigener Broker aus den Einstellungen, sonst LoxBerry-Konfiguration; im Test BBL_DEV_MQTT=host:port. */
function brokerDetails(Logger $log, array $bbl): ?array
{
    $dev = getenv('BBL_DEV_MQTT');
    if ($dev) {
        [$h, $p] = explode(':', $dev, 2) + [1 => '1883'];
        return [$h, (int)$p, '', ''];
    }
    $own = BambuConfig::ownBroker($bbl);
    if ($own !== null) {
        return $own;
    }
    if (!function_exists('mqtt_connectiondetails')) {
        $log->error('LoxBerry-Funktion mqtt_connectiondetails() nicht verfuegbar', 'mqtt-details');
        return null;
    }
    $d = mqtt_connectiondetails();     // ueberschreibt die globale Variable $cfg - die Dienstkonfiguration heisst deshalb $bbl
    $host = is_array($d) ? (string)($d['brokerhost'] ?? '') : '';
    $port = is_array($d) ? (int)($d['brokerport'] ?? 0) : 0;
    if (($host === '' || $port === 0) && is_array($d) && !empty($d['brokeraddress']) && str_contains((string)$d['brokeraddress'], ':')) {
        [$h2, $p2] = explode(':', (string)$d['brokeraddress'], 2);
        $host = $host !== '' ? $host : $h2;
        $port = $port ?: (int)$p2;
    }
    if ($host === '') {
        $log->error('Keine Broker-Daten in der LoxBerry-Konfiguration (MQTT eingerichtet?)', 'mqtt-details');
        return null;
    }
    return [$host, $port ?: 1883, (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? '')];
}

/** Befehl aus Loxone: bambulab/befehl/<name>/<befehl> = Wert. Erste Quittung sofort, das Ergebnis des Druckers folgt (takeAcks). */
function handleCommand(string $topic, string $msg, array $printers, MqttClient $mqtt, Logger $log): void
{
    $p = BambuCommands::parseTopic($topic);
    if ($p === null) {
        return;         // auch alle .../quittung
    }
    [$name, $befehl] = $p;
    $quit = $topic . '/quittung';
    if (!isset($printers[$name])) {
        $log->warn("Befehl $befehl fuer $name abgelehnt: unbekanntes Geraet");
        $mqtt->publish($quit, 'abgelehnt: unbekanntes Geraet');
        return;
    }
    $mqtt->publish($quit, $printers[$name]->befehl($befehl, $msg, microtime(true)));
}

// ------------------------------------------------------------------ Hauptschleife
$bbl = BambuConfig::load($paths->config);
$cfgMtime = -1;
/** @var array<string,BambuPrinter> $printers */
$printers = [];
$mqtt = null;
$mqttErr = '';
$mqttBackoff = 2;
$mqttNext = 0;
$mqttSince = 0;
$zaehler = 0;
$lastStatusTopics = 0;
$lastPublish = 0;
$lastStatus = 0;
$ok = 0;
$results = [];
$started = time();
$log->info('Bambulab-Dienst ' . AppVersion::CURRENT . ' gestartet (PID ' . getmypid() . ')');

while ($running) {
    try {
        $now = time();
        if ($now >= $logLevelCheck) {      // Loglevel-Aenderung der Pluginverwaltung ohne Neustart uebernehmen
            $logLevelCheck = $now + 30;
            applyLogLevel($log);
        }

        // Konfiguration neu laden, wenn sich die Datei geaendert hat
        clearstatcache(true, $paths->config);
        $mt = is_file($paths->config) ? (int)filemtime($paths->config) : 0;
        if ($mt !== $cfgMtime) {
            $cfgMtime = $mt;
            $oldMqtt = $bbl['mqtt'];
            $bbl = BambuConfig::load($paths->config);
            if ($oldMqtt != $bbl['mqtt'] && $mqtt !== null) {      // Broker geaendert: neu verbinden
                $mqtt->close();
                $mqtt = null;
                $mqttNext = 0;
                $mqttBackoff = 2;
            }
            foreach (BambuConfig::secrets($bbl) as $s) {
                $log->addSecret($s);
            }
            $soll = [];
            foreach (BambuConfig::drucker($bbl) as $d) {
                $soll[$d['name']] = $d;
            }
            foreach ($printers as $n => $pr) {          // entfernte oder umbenannte Drucker
                if (!isset($soll[$n])) {
                    $pr->close();
                    unset($printers[$n]);
                }
            }
            foreach ($soll as $n => $d) {
                if (isset($printers[$n])) {
                    $printers[$n]->configure($d, (int)$bbl['pushall_abstand']);
                } else {
                    $printers[$n] = new BambuPrinter($d, $log, $paths->folder, (int)$bbl['pushall_abstand']);
                }
            }
            $log->info('Konfiguration geladen: ' . count($printers) . ' Drucker');
        }

        // MQTT zum LoxBerry-Broker (mit wachsendem Abstand bei Fehlern)
        if (($mqtt === null || !$mqtt->connected()) && $now >= $mqttNext) {
            $bd = brokerDetails($log, $bbl);
            if ($bd === null) {
                $mqttErr = 'Keine Broker-Daten';
                $mqttNext = $now + 30;
            } else {
                $mqtt = new MqttClient($bd[0], $bd[1], $bd[2], $bd[3], 'bambulab_' . $paths->folder, T . '/status/ok');
                $log->addSecret($bd[3]);
                $mqtt->subscribe(T . '/befehl/#');
                if ($mqtt->connect()) {
                    $log->info("MQTT verbunden ({$bd[0]}:{$bd[1]})");
                    $mqttErr = '';
                    $mqttSince = $now;
                    $mqttBackoff = 2;
                    foreach ($printers as $pr) {
                        $pr->vergesseGesendet();
                    }
                    $lastStatusTopics = 0;
                } else {
                    $mqttErr = $mqtt->lastError();
                    $log->error('MQTT: ' . $mqttErr, 'mqtt-connect');
                    $mqttNext = $now + $mqttBackoff;
                    $mqttBackoff = min(60, $mqttBackoff * 2);
                }
            }
        }
        $mqttOk = $mqtt !== null && $mqtt->connected();
        if ($mqttOk && !$mqtt->loop()) {
            $mqttErr = $mqtt->lastError();
            $mqttOk = false;
            $log->warn('MQTT getrennt: ' . $mqttErr, 'mqtt-lost');
        }
        if ($mqttOk) {
            foreach ($mqtt->takeMessages() as [$topic, $payload]) {
                handleCommand($topic, $payload, $printers, $mqtt, $log);
            }
        }

        // Drucker: Verbindung halten, Reports verarbeiten
        foreach ($printers as $pr) {
            $pr->tick($now);
            if ($mqttOk) {
                foreach ($pr->takeAcks() as [$befehl, $text]) {
                    $mqtt->publish(T . '/befehl/' . $pr->name . '/' . $befehl . '/quittung', $text);
                }
            }
        }

        // Geaenderte Werte hoechstens einmal pro Sekunde senden
        if ($now !== $lastPublish) {
            $lastPublish = $now;
            $results = [];
            foreach ($printers as $n => $pr) {
                $results[$n] = $pr->status($now);
                if ($mqttOk) {
                    $pr->veroeffentlichen($mqtt, $now, (bool)$bbl['hms_text']);
                }
            }
            $ok = $results && !array_filter($results, fn($r) => !$r['ok']) ? 1 : 0;
        }
        if ($mqttOk && $now - $lastStatusTopics >= 10) {     // Lebenszeichen alle 10 s
            $lastStatusTopics = $now;
            if (!$printers) {
                $log->warn('Kein Drucker konfiguriert', 'no-printer');
            }
            $zaehler = ($zaehler + 1) % 1000;
            $mqtt->publish(T . '/status/ok', (string)$ok);
            $mqtt->publish(T . '/status/zaehler', (string)$zaehler);
            $mqtt->publish(T . '/status/ts', (string)time());
            $log->rotateCheck();
        }

        if ($now !== $lastStatus) {      // Status hoechstens einmal pro Sekunde (liegt im RAM, /dev/shm)
            $lastStatus = $now;
            Util::jsonSave($paths->statusFile(), [
                'service' => 'running', 'time' => $now, 'started' => $started,
                'ok' => $ok, 'zaehler' => $zaehler, 'ts' => $lastStatusTopics,
                'mqtt' => $mqttOk ? 1 : 0, 'mqtt_error' => $mqttErr, 'mqtt_seit' => $mqttSince,
                'drucker' => $results, 'version' => AppVersion::CURRENT,
            ]);
        }
    } catch (Throwable $e) {
        $log->error('Fehler in der Hauptschleife: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'loop');
    }
    usleep(100000);
}

if ($mqtt !== null && $mqtt->connected()) {
    $mqtt->publish(T . '/status/ok', '0');
    $mqtt->close();
}
foreach ($printers as $pr) {
    $pr->close();
}
$log->info('Bambulab-Dienst beendet');
$log->end();
@unlink($paths->pidFile());
