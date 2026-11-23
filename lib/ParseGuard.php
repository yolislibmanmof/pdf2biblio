<?php
namespace PDF2Biblio;

/**
 * Gate parse in-process (v2.5.0): smalot hanya file <= 8 MB & belum pernah crash; selain itu native.
 * Dipakai ParseRunner sebagai fallback ketika child PHP CLI tidak tersedia / gagal lunak.
 */
class ParseGuard
{
    private const SMALL    = 8388608; // 8 MB
    private const FLAG_TTL = 900;     // 15 menit

    public static function extract(string $file, string $orig): array
    {
        $size = (int)@filesize($file);
        $hash = (string)@sha1_file($file);
        $tmp = dirname(__DIR__) . '/tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
        $flag = $tmp . '/crash_' . $hash . '.flag';

        $hadFlag = is_file($flag);
        if ($hadFlag && time() - (int)@filemtime($flag) > self::FLAG_TTL) { @unlink($flag); $hadFlag = false; }

        $useSmalot = $size <= self::SMALL && class_exists('\Smalot\PdfParser\Parser') && !$hadFlag;

        if ($useSmalot) {
            @file_put_contents($flag, 'in-progress'); // bila proses mati di sini, bendera tersisa -> retry native
            $d = Extractor::extract($file, $orig);
            @unlink($flag);
            $d['log'] = array_merge((array)($d['log'] ?? []), ['Mesin: smalot in-process (file <= 8 MB).']);
            return $d;
        }

        if (!class_exists('P2B_PdfExtractor')) {
            $pf = __DIR__ . '/PdfExtractor.php';
            if (is_file($pf)) {
                if (!defined('INDEX_AUTH')) define('INDEX_AUTH', 1);
                require_once $pf;
            }
        }

        $front = []; $meta = []; $total = 0;
        if (class_exists('P2B_PdfExtractor')) {
            $nat = \P2B_PdfExtractor::extract($file, 90000, true);
            $meta = (array)$nat['meta'];
            $total = (int)$nat['pages'];
            $txt = (string)$nat['text'];
            if (!mb_check_encoding($txt, 'UTF-8')) $txt = (string)@mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
            foreach (mb_str_split($txt, 3000) as $i => $ch) $front[$i] = $ch;
            unset($nat, $txt);
        }
        if (!$front) $front = [''];

        $d = Extractor::extract($file, $orig, ['meta' => $meta, 'front' => $front, 'total' => $total, 'tm' => '']);
        $reason = $hadFlag ? 'bendera crash smalot masih aktif'
            : ($size > self::SMALL ? 'file > 8 MB' : 'smalot tidak tersedia');
        $d['log'] = array_merge((array)($d['log'] ?? []), ['Mesin: native in-process (' . $reason . ').']);
        return $d;
    }
}