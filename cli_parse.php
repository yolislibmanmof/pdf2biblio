<?php
/**
 * PDF2Biblio — child parser terisolasi (v2.5.0). Dipanggil ParseRunner.
 * Semua kegagalan ditulis ke berkas output sebagai JSON {error,msg} agar parent
 * bisa membedakan CRASH (patut dibenderai) dari GAGAL LUNAK (tidak dibenderai).
 * Kode keluar: 0 ok, 1 argumen kosong, 2 sapi salah, 4 input/argumen salah,
 *              5 fatal error, 6 exception, 7 baris kosong, 8 gagal tulis.
 */
$argvv = $argv ?? [];
$file = $argvv[1] ?? '';
$orig = $argvv[2] ?? '';
$eng  = $argvv[3] ?? 'smalot';
$out  = $argvv[4] ?? '';

$fail = function (string $kind, string $msg = '', int $code = 3) use ($out) {
    if ($out !== '') {
        @file_put_contents($out, json_encode(
            ['error' => $kind, 'msg' => $msg, 'sapi' => PHP_SAPI],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }
    exit($code);
};

if (PHP_SAPI !== 'cli') $fail('sapi', 'child dijalankan dengan SAPI ' . PHP_SAPI . ', butuh cli', 2);
if ($file === '' || $out === '') exit(1);
if (!is_file($file) || !is_readable($file)) $fail('input', 'file PDF tidak terbaca: ' . basename($file), 4);
if (!in_array($eng, ['smalot', 'native'], true)) $fail('arg', 'mesin tidak dikenal: ' . $eng, 4);

register_shutdown_function(function () use ($fail) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $fail('fatal', $e['message'] . ' @' . basename($e['file']) . ':' . $e['line'], 5);
    }
});

define('INDEX_AUTH', 1);
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/lib/Extractor.php';

@ini_set('pcre.jit', '0');
@ini_set('memory_limit', '512M');
@set_time_limit(55);   // harus < CHILD_TIMEOUT parent (60 dtk)
@ignore_user_abort(true);
chdir(__DIR__);

try {
    if ($eng === 'smalot') {
        $row = \PDF2Biblio\Extractor::extract($file, $orig);
    } else {
        require __DIR__ . '/lib/PdfExtractor.php';
        $nat = \P2B_PdfExtractor::extract($file, 90000, true);
        $txt = (string)$nat['text'];
        if (!mb_check_encoding($txt, 'UTF-8')) $txt = (string)@mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
        $front = [];
        foreach (mb_str_split($txt, 3000) as $i => $ch) $front[$i] = $ch;
        if (!$front) $front = [''];
        $row = \PDF2Biblio\Extractor::extract($file, $orig, [
            'meta' => (array)$nat['meta'], 'front' => $front, 'total' => (int)$nat['pages'], 'tm' => '',
        ]);
    }
} catch (\Throwable $e) {
    $fail('exception', $e->getMessage(), 6);
}

if (!is_array($row) || !isset($row['title'])) $fail('badrow', 'hasil ekstraksi kosong', 7);
$json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false || @file_put_contents($out, $json) === false) $fail('write', 'gagal menulis berkas output', 8);
exit(0);