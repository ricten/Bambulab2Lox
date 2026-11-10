<?php
// Rendert einen Reiter der Weboberflaeche mit Attrappen der LoxBerry-Bibliotheken (nur fuer Tests): php ui_page.php <reiter>
// Umgebung: STUB_INDEX (index.php), STUB_BIN, STUB_CONFIG, STUB_LOG, STUB_SYSCONFIG, STUB_UDP, <PLUGIN>_DEV_DIR.
$_GET['tab'] = $argv[1] ?? 'einstellungen';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_ADDR'] = '192.0.2.25';
include (string)getenv('STUB_INDEX');
