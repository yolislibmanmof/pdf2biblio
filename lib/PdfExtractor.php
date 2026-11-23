<?php
/**
 * PDF2Biblio - mesin ekstraksi teks & metadata PDF (v1.0.3 anti-crash).
 * Utama: smalot/pdfparser (hanya file <=8MB). Fallback: parser native pure-PHP.
 * v1.0.3: namespace \Smalot\PdfParser (kapital P — PSR-0 case-sensitive),
 *         pola stream tidak lagi match "endstream".
 */
if (!defined('INDEX_AUTH')) { die('direct access not allowed'); }

class P2B_PdfExtractor
{
    const MAX_FILE_BYTES = 83886080; // 80 MB
    const SMALL_FILE_BYTES = 8388608; // 8 MB: batas aman smalot

    public static function extract($path, $maxTextChars = 90000, $forceNative = false)
    {
        @ini_set('pcre.jit', '0');
        @ini_set('memory_limit', '512M');
        $out = array('engine' => 'none', 'meta' => array(), 'pages' => 0, 'text' => '', 'cover' => null, 'warnings' => array());
        if (!is_file($path) || !is_readable($path)) { throw new \Exception('File PDF tidak dapat dibaca.'); }
        $size = filesize($path);
        if ($size < 8) { throw new \Exception('File PDF kosong / rusak.'); }
        if ($size > self::MAX_FILE_BYTES) { throw new \Exception('File PDF terlalu besar (>80 MB). Kompres / pecah file terlebih dahulu.'); }

        if (!$forceNative && $size <= self::SMALL_FILE_BYTES && class_exists('\Smalot\PdfParser\Parser')) {
            try {
                $config = null;
                if (class_exists('\Smalot\PdfParser\Config')) {
                    $config = new \Smalot\PdfParser\Config();
                    if (method_exists($config, 'setRetainImageContent')) { $config->setRetainImageContent(false); }
                    if (method_exists($config, 'setDecodeMemoryLimit')) { $config->setDecodeMemoryLimit(64 * 1024 * 1024); }
                }
                $parser = $config ? new \Smalot\PdfParser\Parser(array(), $config) : new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($path);
                $out['meta'] = self::normMeta($pdf->getDetails());
                $pages = $pdf->getPages();
                $out['pages'] = is_array($pages) ? count($pages) : 0;
                $txt = ''; $n = 0;
                foreach ($pages as $pg) {
                    if ($n >= 10 || strlen($txt) > $maxTextChars) { break; }
                    try { $t = $pg->getText(); } catch (\Throwable $e) { $t = ''; }
                    if (is_string($t) && trim($t) !== '') { $txt .= $t . "\n\n"; }
                    $n++;
                }
                $out['text'] = self::cleanText($txt);
                $out['engine'] = 'smalot';
                unset($pdf, $parser, $pages, $config);
                if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
            } catch (\Throwable $e) {
                $out['warnings'][] = 'Parser utama gagal (' . $e->getMessage() . '); memakai parser fallback.';
                $out['engine'] = 'none';
                if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
            }
        }

        $raw = @file_get_contents($path);
        if ($raw === false) { throw new \Exception('Gagal membaca isi file PDF.'); }

        if ($out['engine'] === 'none' || trim($out['text']) === '') {
            try {
                $nat = self::nativeParse($raw, $maxTextChars);
            } catch (\Throwable $e) {
                $nat = array('meta' => array(), 'pages' => 0, 'text' => '');
                $out['warnings'][] = 'Parser fallback gagal: ' . $e->getMessage();
            }
            if (!$out['meta']) { $out['meta'] = $nat['meta']; }
            if (!$out['pages']) { $out['pages'] = $nat['pages']; }
            if (trim($out['text']) === '') {
                $out['text'] = $nat['text'];
                $out['engine'] = $out['engine'] === 'smalot' ? 'smalot+native' : 'native';
            }
        }

        $out['cover'] = self::embeddedJpeg($raw);
        unset($raw);

        if (trim($out['text']) === '') {
            $out['warnings'][] = 'Teks tidak terbaca (PDF kemungkinan hasil scan/gambar). Metadata & cover tetap dicoba; untuk hasil penuh gunakan OCR terlebih dahulu.';
        }
        if (!$out['pages']) { $out['warnings'][] = 'Jumlah halaman tidak terdeteksi.'; }
        return $out;
    }

    private static function normMeta($details)
    {
        $meta = array();
        if (!is_array($details)) { return $meta; }
        $map = array('title' => 'Title', 'author' => 'Author', 'subject' => 'Subject', 'keywords' => 'Keywords', 'creator' => 'Creator', 'producer' => 'Producer', 'creationdate' => 'CreationDate', 'moddate' => 'ModDate', 'lang' => 'Lang');
        foreach ($map as $k => $src) {
            foreach ($details as $dk => $dv) {
                if (strcasecmp($dk, $src) === 0) {
                    if (is_array($dv)) { $dv = implode('; ', $dv); }
                    if (is_scalar($dv)) { $meta[$k] = trim((string)$dv); }
                }
            }
        }
        return $meta;
    }

    public static function cleanText($txt)
    {
        $txt = str_replace(array("\r\n", "\r"), "\n", (string)$txt);
        if (strlen($txt) > 150000) { $txt = substr($txt, 0, 150000); }
        $clean = preg_replace('/[^\P{C}\n\t]+/u', ' ', $txt);
        if ($clean !== null) { $txt = $clean; }
        $lines = array();
        foreach (explode("\n", $txt) as $ln) { $ln = trim((string)preg_replace('/[ \t]+/', ' ', $ln)); $lines[] = $ln; }
        $txt = implode("\n", $lines);
        $txt = (string)preg_replace('/\n{3,}/', "\n\n", $txt);
        return trim($txt);
    }

    private static function toUtf8($bin)
    {
        if ($bin === '' || $bin === null) { return ''; }
        if (substr($bin, 0, 2) === "\xFE\xFF") {
            $bin = substr($bin, 2);
            if (function_exists('mb_convert_encoding')) { return (string)@mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE'); }
            if (function_exists('iconv')) { return (string)@iconv('UTF-16BE', 'UTF-8//IGNORE', $bin); }
            $out = '';
            for ($i = 0; $i + 1 < strlen($bin); $i += 2) { $c = (ord($bin[$i]) << 8) | ord($bin[$i + 1]); if ($c < 128) { $out .= chr($c); } }
            return $out;
        }
        if (preg_match('//u', $bin)) { return $bin; }
        if (function_exists('mb_convert_encoding')) { return (string)@mb_convert_encoding($bin, 'UTF-8', 'Windows-1252'); }
        if (function_exists('iconv')) { return (string)@iconv('Windows-1252', 'UTF-8//IGNORE', $bin); }
        return (string)preg_replace('/[\x80-\xFF]/', '', $bin);
    }

    private static function decodePdfString($s)
    {
        $s = trim($s);
        if ($s === '') { return ''; }
        if ($s[0] === '<') {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', $s);
            if (strlen($hex) % 2) { $hex .= '0'; }
            $bin = @hex2bin($hex);
            return self::toUtf8($bin === false ? '' : $bin);
        }
        if ($s[0] === '(') {
            $s = substr($s, 1, -1);
            $s = preg_replace('/\\\\\r?\n/', '', $s);
            $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) { return chr(octdec($m[1]) & 0xFF); }, $s);
            $s = str_replace(array('\\n', '\\r', '\\t', '\\b', '\\f', '\\(', '\\)', '\\\\'), array("\n", "\r", "\t", '', '', '(', ')', '\\'), $s);
            return self::toUtf8($s);
        }
        return self::toUtf8($s);
    }

    private static function streamBlocks($raw, $maxBlocks = 4000)
    {
        $offset = 0; $n = 0;
        while ($n < $maxBlocks && preg_match('/(?<!end)stream\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $end = strpos($raw, 'endstream', $start);
            if ($end === false) { break; }
            $dict = substr($raw, max(0, $m[0][1] - 600), min(600, $m[0][1]));
            $data = substr($raw, $start, $end - $start);
            yield array('dict' => $dict, 'data' => $data);
            $offset = $end + 9; $n++;
        }
    }

    private static function inflate($data)
    {
        foreach (array('gzuncompress', 'gzinflate', 'gzdecode') as $fn) {
            if (function_exists($fn)) { $r = @$fn($data); if ($r !== false && $r !== null && $r !== '') { return $r; } }
        }
        $r = @gzinflate(substr($data, 2));
        if ($r !== false && $r !== null && $r !== '') { return $r; }
        return false;
    }

    private static function nativeParse($raw, $maxTextChars)
    {
        $res = array('meta' => array(), 'pages' => 0, 'text' => '');
        // Regex berat HANYA pada irisan awal+akhir (bukan seluruh file besar).
        $probe = substr($raw, 0, 2000000) . "\n" . substr($raw, -2000000);
        foreach (array('Title', 'Author', 'Subject', 'Keywords', 'Creator', 'Producer', 'CreationDate', 'ModDate') as $key) {
            if (preg_match('/\/' . $key . '\s*(\((?:\\\\.|[^\\\\()])*\)|<[0-9A-Fa-f\s]+>)/s', $probe, $m)) {
                $v = trim(self::decodePdfString($m[1]));
                if ($v !== '') { $res['meta'][strtolower($key)] = $v; }
            }
        }
        if (preg_match('/<x:xmpmeta.*?<\/x:xmpmeta>/s', $probe, $xm)) {
            $x = $xm[0];
            if (empty($res['meta']['title']) && preg_match('/<dc:title>.*?<rdf:li[^>]*>(.*?)<\/rdf:li>/s', $x, $m)) { $res['meta']['title'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')); }
            if (empty($res['meta']['author']) && preg_match('/<dc:creator>.*?<rdf:li[^>]*>(.*?)<\/rdf:li>/s', $x, $m)) { $res['meta']['author'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')); }
            if (empty($res['meta']['subject']) && preg_match('/<dc:subject>.*?<rdf:li[^>]*>(.*?)<\/rdf:li>/s', $x, $m)) { $res['meta']['subject'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')); }
            if (empty($res['meta']['keywords']) && preg_match('/<pdf:Keywords>(.*?)<\/pdf:Keywords>/s', $x, $m)) { $res['meta']['keywords'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')); }
        }
        unset($probe);
        $pageCount = preg_match_all('/\/Type\s*\/Page\b(?!s)/', $raw);
        $maxCount = 0;
        if (preg_match_all('/\/Count\s+(\d+)/', $raw, $cm)) { foreach ($cm[1] as $c) { $maxCount = max($maxCount, (int)$c); } }
        $res['pages'] = max((int)$pageCount, $maxCount);

        $text = ''; $processed = 0;
        foreach (self::streamBlocks($raw) as $blk) {
            if (strlen($text) > $maxTextChars || $processed > 200) { break; }
            $processed++;
            if (strpos($blk['dict'], '/Subtype') !== false) { continue; }
            if (strlen($blk['data']) > 4194304) { continue; }
            $content = $blk['data'];
            if (strpos($content, 'BT') === false || (strpos($content, 'Tj') === false && strpos($content, 'TJ') === false)) {
                if (strpos($blk['dict'], 'FlateDecode') !== false) {
                    $dec = self::inflate($content);
                    if ($dec === false) { continue; }
                    $content = $dec;
                }
                if (strpos($content, 'BT') === false || (strpos($content, 'Tj') === false && strpos($content, 'TJ') === false)) { continue; }
            }
            $text .= self::parseContentStream($content) . "\n\n";
        }
        $res['text'] = self::cleanText($text);
        return $res;
    }

    private static function parseContentStream($content)
    {
        if (strlen($content) > 2097152) { $content = substr($content, 0, 2097152); }
        $lines = array(); $buf = '';
        $ok = preg_match_all('/(\((?:\\\\.|[^\\\\()])*\))|<([0-9A-Fa-f\s]{4,})>|\b(Td|TD|T\*|ET|BT)\b/', $content, $mm, PREG_SET_ORDER);
        if (!$ok) { return ''; }
        foreach ($mm as $m) {
            if (!empty($m[1])) { $buf .= self::decodePdfString($m[1]); }
            elseif (!empty($m[2])) { $buf .= self::decodePdfString('<' . $m[2] . '>'); }
            elseif (!empty($m[3])) {
                $op = $m[3];
                if ($op === 'BT') { $buf = ''; }
                elseif (trim($buf) !== '') { $lines[] = trim($buf); $buf = ''; }
            }
        }
        if (trim($buf) !== '') { $lines[] = trim($buf); }
        return implode("\n", $lines);
    }

    private static function embeddedJpeg($raw)
    {
        $best = null; $bestScore = 0; $n = 0;
        foreach (self::streamBlocks($raw, 1500) as $blk) {
            if ($n >= 12) { break; }
            if (strpos($blk['dict'], '/Image') === false || strpos($blk['dict'], 'DCTDecode') === false) { continue; }
            $data = $blk['data'];
            $pos = strpos($data, "\xFF\xD8");
            if ($pos === false) { continue; }
            $eoi = strrpos($data, "\xFF\xD9");
            $jpg = $eoi !== false ? substr($data, $pos, $eoi - $pos + 2) : substr($data, $pos);
            $info = @getimagesizefromstring($jpg);
            if (!$info || empty($info[0]) || empty($info[1])) { continue; }
            $w = $info[0]; $h = $info[1];
            if ($w < 150 || $h < 150) { continue; }
            $n++;
            $ratio = $h / max(1, $w);
            $score = $w * $h;
            if ($ratio >= 1.0 && $ratio <= 1.9) { $score *= 2; }
            if ($score > $bestScore) { $bestScore = $score; $best = $jpg; }
        }
        return $best;
    }
}