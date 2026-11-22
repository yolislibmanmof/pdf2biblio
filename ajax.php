<?php
/**
 * PDF2Biblio — shim BC + diagnostik mandiri.
 * diag boleh tanpa sesi admin HANYA dari loopback (localhost); hasil juga ke diag.json.
 */
define('INDEX_AUTH', 1);
require dirname(__DIR__, 2) . '/sysconfig.inc.php';
foreach ([SB . 'admin/default/session.inc.php', SB . 'lib/session.inc.php'] as $s) {
    if (is_file($s)) { require $s; break; }
}
if (session_status() === PHP_SESSION_NONE) @session_start();

$action = $_REQUEST['action'] ?? '';

if ($action === 'diag') {
    $loopback = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    $isAdmin = !empty($_SESSION['uid']) || !empty($_SESSION['username']);
    if (!$loopback && !$isAdmin) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'diag hanya untuk localhost atau admin login.']);
        exit;
    }
    $root = __DIR__;
    $files = [];
    foreach (['vendor/autoload.php','lib/Extractor.php','lib/Enricher.php','lib/Importer.php','lib/Api.php','index.php','manager.php','ajax.php','pdf2biblio.plugin.php'] as $f) {
        $p = $root . '/' . $f;
        $files[$f] = is_file($p) ? filesize($p) : false;
    }
    $sessionFiles = [];
    foreach (['admin/default/session.inc.php','admin/default/session_check.inc.php','lib/session.inc.php','admin/session.inc.php'] as $f) {
        $sessionFiles[$f] = is_file(SB . $f);
    }
    $props = null; $menus = null; $psrc = null;
    if (class_exists('SLiMS\Plugins')) {
        try {
            $inst = \SLiMS\Plugins::getInstance();
            $r = new ReflectionObject($inst);
            foreach ($r->getProperties() as $p) {
                $p->setAccessible(true);
                $v = $p->getValue($inst);
                if (is_array($v) || is_scalar($v)) $props[$p->getName()] = $v;
            }
            foreach (['getMenus', 'getMenu', 'menus'] as $m) {
                if (method_exists($inst, $m)) { try { $menus[$m] = $inst->$m(); } catch (\Throwable $e) {} }
            }
            $pf = $r->getFileName();
            if ($pf && is_file($pf)) $psrc = substr((string)file_get_contents($pf), 0, 8000);
        } catch (\Throwable $e) { $props = ['err' => $e->getMessage()]; }
    }
    $dbPlugins = null; $tables = null;
    try {
        $dbs = $GLOBALS['dbs'] ?? null;
        if (!$dbs) $dbs = @new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
        if ($dbs && !$dbs->connect_error) {
            $r = @$dbs->query("SHOW TABLES LIKE '%plugin%'");
            if ($r) { $tables = []; while ($row = $r->fetch_array()) $tables[] = $row[0]; }
            $r = @$dbs->query('SELECT * FROM plugins');
            if ($r) { $dbPlugins = []; while ($row = $r->fetch_assoc()) $dbPlugins[] = $row; }
        }
    } catch (\Throwable $e) {}
    $md5 = [];
    foreach ([$root . '/manager.php', $root . '/index.php', 'plugins/pdf2biblio/manager.php', 'plugins/pdf2biblio/index.php', SB . 'plugins/pdf2biblio/manager.php', SB . 'plugins/pdf2biblio/index.php'] as $c) {
        $md5[$c] = md5($c);
    }
    $payload = [
        'ok' => true, 'php' => PHP_VERSION, 'SB' => SB, 'SWB' => defined('SWB') ? SWB : null,
        'DIR' => $root, 'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
        'files' => $files, 'dir' => scandir($root),
        'lib' => is_dir($root . '/lib') ? scandir($root . '/lib') : null,
        'session_files' => $sessionFiles,
        'session_keys' => array_keys($_SESSION), 'uid' => $_SESSION['uid'] ?? null, 'username' => $_SESSION['username'] ?? null,
        'get' => $_GET, 'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'plugins_props' => $props, 'plugins_menus' => $menus,
        'db_tables_plugin' => $tables, 'db_plugins' => $dbPlugins,
        'md5_candidates' => $md5,
        'container_src' => is_file(SB . 'admin/plugin_container.php') ? substr((string)file_get_contents(SB . 'admin/plugin_container.php'), 0, 8000) : null,
        'plugins_src' => $psrc,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);
    @file_put_contents($root . '/diag.json', $json);
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
    exit;
}

require $root . '/manager.php';