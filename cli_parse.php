<?php
/** PDF2Biblio — child parser terisolasi. Dipanggil ParseRunner; crash di sini tidak membunuh web. */
if (PHP_SAPI !== 'cli') exit(1);
$file = $argv[1] ?? ''; $orig = $argv[2] ?? ''; $eng = $argv[3] ?? 'smalot'; $out = $argv[4] ?? '';
if ($file === '' || $out === '') exit(1);
define('INDEX_AUTH', 1);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/lib/Extractor.php';
@ini_set('pcre.jit', '0');
@ini_set('memory_limit', '512M');
@set_time_limit(55);
if ($eng === 'smalot') {
    $row = \PDF2Biblio\Extractor::extract($file, $orig);
} else {
    require dirname(__DIR__) . '/lib/PdfExtractor.php';
    $nat = \P2B_PdfExtractor::extract($file, 90000, true);
    $txt = (string)$nat['text'];
    if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    $front = [];
    foreach (mb_str_split($txt, 3000) as $i => $ch) $front[$i] = $ch;
    if (!$front) $front = [''];
    $row = \PDF2Biblio\Extractor::extract($file, $orig, [
        'meta' => (array)$nat['meta'], 'front' => $front, 'total' => (int)$nat['pages'], 'tm' => '',
    ]);
}
file_put_contents($out, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
exit(0);