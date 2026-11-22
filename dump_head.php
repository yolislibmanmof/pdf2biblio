<?php
define('INDEX_AUTH', 1);
require __DIR__ . '/lib/PdfExtractor.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) { require __DIR__ . '/vendor/autoload.php'; }
$p2bSrc = __DIR__ . '/vendor/smalot/pdfparser/src/Smalot/Pdfparser';
if (!class_exists('\Smalot\Pdfparser\Parser') && is_dir($p2bSrc)) {
    spl_autoload_register(function ($class) use ($p2bSrc) {
        $prefix = 'Smalot\\Pdfparser\\';
        if (strpos($class, $prefix) === 0) {
            $file = $p2bSrc . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
        }
    });
}
$path = isset($argv[1]) ? $argv[1] : '';
if (!is_file($path)) { echo "PDF tidak ditemukan\n"; exit(1); }
$ex = P2B_PdfExtractor::extract($path, 90000, false);
$lines = array_values(array_filter(array_map('trim', explode("\n", $ex['text'])), function ($x) { return $x !== ''; }));
$out = implode("\n", array_slice($lines, 0, 100));
file_put_contents(__DIR__ . '/head.txt', $out);
echo "engine=" . $ex['engine'] . " | 100 baris pertama ditulis ke head.txt\n";