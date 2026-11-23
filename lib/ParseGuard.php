<?php
namespace PDF2Biblio;

/** Gate mesin parse anti-crash: smalot hanya file <=8MB & belum pernah crash; selain itu native. */
class ParseGuard
{
    private const SMALL = 8388608; // 8 MB batas aman smalot (port mesin legacy)

    public static function extract(string $file, string $orig): array
    {
        $size = (int)@filesize($file);
        $hash = (string)@sha1_file($file);
        $tmp = dirname(__DIR__) . '/tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
        $flag = $tmp . '/crash_' . $hash . '.flag';
        $useSmalot = $size <= self::SMALL && class_exists('\Smalot\PdfParser\Parser') && !is_file($flag);

        if ($useSmalot) {
            @file_put_contents($flag, '1'); // bila proses mati di sini, flag tersisa → retry pakai native
            $d = Extractor::extract($file, $orig);
            @unlink($flag); // parse selesai sehat
            $d['log'][] = 'Mesin: smalot (file <= 8 MB, belum pernah crash).';
            return $d;
        }

        // Native anti-crash (port mesin legacy): teks+meta+pages, lalu heuristik KDT yang sama.
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
            if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
            foreach (mb_str_split($txt, 3000) as $i => $ch) $front[$i] = $ch;
            unset($nat, $txt);
        }
        if (!$front) $front = [''];
        $d = Extractor::extract($file, $orig, ['meta' => $meta, 'front' => $front, 'total' => $total, 'tm' => '']);
        $d['log'][] = is_file($flag)
            ? 'Mesin: native anti-crash (bendera crash: smalot pernah mati pada file ini).'
            : 'Mesin: native anti-crash (file > 8 MB).';
        return $d;
    }
}