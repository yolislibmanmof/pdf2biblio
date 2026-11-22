<?php
// Uji ekstraksi tanpa web server. Jalankan: php cli_test.php "path\ke\file.pdf"
define('INDEX_AUTH', 1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '1G');
ini_set('pcre.jit', '0');
set_time_limit(0);
require __DIR__ . '/lib/PdfExtractor.php';
require __DIR__ . '/lib/BiblioMapper.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) { require __DIR__ . '/vendor/autoload.php'; }

$path = isset($argv[1]) ? $argv[1] : '';
if (!is_file($path)) { echo "PDF tidak ditemukan: {$path}\n"; exit(1); }
echo 'PHP ' . PHP_VERSION . ' | memory_limit=' . ini_get('memory_limit') . ' | pcre.jit=' . ini_get('pcre.jit') . "\n";
echo 'smalot loaded: ' . (class_exists('\Smalot\Pdfparser\Parser') ? 'YA' : 'TIDAK') . "\n";

$t = microtime(true);
$ex = P2B_PdfExtractor::extract($path, 90000, true); // paksa native
echo 'engine=' . $ex['engine'] . ' pages=' . $ex['pages'] . ' textlen=' . strlen($ex['text'])
   . ' cover=' . ($ex['cover'] ? strlen($ex['cover']) : 0) . ' waktu=' . round(microtime(true) - $t, 1) . "s\n";
echo 'META: ' . json_encode($ex['meta'], JSON_UNESCAPED_UNICODE) . "\n";
echo "TEXT HEAD:\n" . substr($ex['text'], 0, 400) . "\n";
echo 'WARN: ' . implode(' | ', $ex['warnings']) . "\n";
echo 'PEAK MEM: ' . round(memory_get_peak_usage(true) / 1048576, 1) . " MB\n";
echo "NATIVE OK\n";