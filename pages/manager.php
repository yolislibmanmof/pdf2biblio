<?php
/**
 * PDF2Biblio manager — entry point plugin_container.php (render + AJAX + diag).
 * Berada di pages/ → basis path plugin = dirname(__DIR__).
 */
defined('SB') or die('Akses langsung ditolak.');

$base = dirname(__DIR__); // FIX: dulu __DIR__ (= pages/) → vendor/lib/index tidak ketemu
$need = ['vendor/autoload.php', 'lib/Extractor.php', 'lib/Enricher.php', 'lib/Importer.php', 'lib/Api.php'];
$missing = array_values(array_filter($need, fn($f) => !is_file($base . '/' . $f)));
if ($missing) {
    if (isset($_REQUEST['action'])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'File hilang: ' . implode(', ', $missing)]);
        exit;
    }
    echo '<div style="padding:16px;font-family:sans-serif"><b>File hilang:</b> ' . htmlspecialchars(implode(', ', $missing)) . ' — tempel ulang paket.</div>';
    return;
}

$GLOBALS['p2b_autoload_loaded'] = true; // fix warning global lama
require_once $base . '/vendor/autoload.php';
require_once $base . '/lib/Extractor.php';
require_once $base . '/lib/Enricher.php';
require_once $base . '/lib/Importer.php';
require_once $base . '/lib/Api.php';

\PDF2Biblio\Api::boot();

// ajaxload=1 dipakai SLiMS untuk render halaman JUGA → pembeda aksi = parameter "action"
if (isset($_REQUEST['action'])) {
    \PDF2Biblio\Api::handle(); // selalu exit
}

require $base . '/index.php';