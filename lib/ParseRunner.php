<?php
namespace PDF2Biblio;

/** Menjalankan parse di child PHP CLI (isolasi crash/timeout), lalu safe-mode linear bila semua mesin mati. */
class ParseRunner
{
    private const CHILD_TIMEOUT = 60;

    public static function extract(string $file, string $orig): array
    {
        $hash = (string)@sha1_file($file);
        $tmp = dirname(__DIR__) . '/tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
        $php = self::phpCli();
        foreach (['smalot', 'native'] as $eng) {
            $flag = $tmp . '/crash_' . $hash . '_' . $eng . '.flag';
            if (is_file($flag)) continue;
            if ($php === '') { @file_put_contents($flag, 'no-php-cli'); continue; }
            $out = $tmp . '/parse_' . $hash . '_' . $eng . '.json';
            @unlink($out);
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg(dirname(__DIR__) . '/cli_parse.php')
                . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($orig)
                . ' ' . escapeshellarg($eng) . ' ' . escapeshellarg($out);
            $ok = self::run($cmd, self::CHILD_TIMEOUT);
            $j = is_file($out) ? json_decode((string)@file_get_contents($out), true) : null;
            @unlink($out);
            if ($ok && is_array($j) && isset($j['title'])) {
                $j['log'] = array_merge((array)($j['log'] ?? []), ['Mesin: ' . $eng . ' (child terisolasi, batas 60 detik).']);
                return $j;
            }
            @file_put_contents($flag, $ok ? 'bad-output' : 'crash/timeout');
        }
        return self::safe($file, $orig);
    }

    private static function run(string $cmd, int $timeout): bool
    {
        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open($cmd, $spec, $pipes, dirname(__DIR__));
        if (!is_resource($p)) return false;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = time(); $done = false;
        while (true) {
            $st = proc_get_status($p);
            if (!$st['running']) { $done = true; break; }
            if (time() - $start > $timeout) { proc_terminate($p); break; }
            fread($pipes[1], 8192); fread($pipes[2], 8192); // buang output agar pipe tidak penuh
            usleep(100000);
        }
        fclose($pipes[1]); fclose($pipes[2]);
        return $done && proc_close($p) === 0;
    }

    private static function phpCli(): string
    {
        $cands = [];
        if (stripos(basename(PHP_BINARY), 'php') === 0) $cands[] = PHP_BINARY;
        foreach (glob('C:\\laragon\\bin\\php\\*\\php.exe') ?: [] as $g) $cands[] = $g;
        $cands[] = 'php';
        foreach ($cands as $c) {
            if ($c !== 'php' && !is_file($c)) continue;
            $v = @shell_exec(escapeshellarg($c) . ' -v 2>NUL');
            if (is_string($v) && stripos($v, 'PHP ') === 0) return $c;
            if ($c !== 'php' && stripos(basename($c), 'php.exe') === 0) return $c;
        }
        return '';
    }

    /** Safe-mode: scan linear (strpos/preg linear), tanpa smalot & tanpa PCRE backtrack berat. */
    private static function safe(string $file, string $orig): array
    {
        $d = Extractor::blank();
        $d['pdf_name'] = $orig;
        $d['size'] = (int)@filesize($file);
        $raw = (string)@file_get_contents($file, false, null, 0, 2000000);
        $meta = [];
        foreach (['Title', 'Author', 'Subject', 'Keywords', 'CreationDate'] as $k) {
            $pos = strpos($raw, '/' . $k);
            if ($pos === false) continue;
            $s = ltrim(substr($raw, $pos + strlen($k) + 1, 400));
            if ($s !== '' && $s[0] === '(') {
                $end = strpos($s, ')');
                $meta[strtolower($k)] = $end === false ? substr($s, 1, 200) : substr($s, 1, $end - 1);
            }
        }
        $isbn = ''; $off = 0;
        while (($p = stripos($raw, 'ISBN', $off)) !== false) {
            $digits = preg_replace('/[^0-9Xx]/', '', substr($raw, $p, 60)); // linear, aman
            if (strlen($digits) >= 13 && Extractor::isbn13(substr($digits, 0, 13))) { $isbn = substr($digits, 0, 13); break; }
            $off = $p + 4;
        }
        $stem = preg_replace('/\(\+?ISBN\)/i', '', pathinfo($orig, PATHINFO_FILENAME));
        $stem = preg_replace('/^[A-Z]{1,3}\s?\d{1,3}(?:[.,]\d+)?\s*[-–_]\s*/u', '', $stem);
        $d['title'] = mb_substr(trim(str_replace(['_', '--'], ' ', $stem)), 0, 300);
        $d['sources']['title'] = 'FILE';
        $d['author'] = trim((string)($meta['author'] ?? ''));
        if ($d['author'] !== '') $d['sources']['author'] = 'PDF';
        $d['isbn'] = $isbn;
        if ($isbn !== '') $d['sources']['isbn'] = 'PDF';
        $d['year'] = preg_match('/\b(?:19|20)\d{2}\b/', $raw, $m) ? $m[0] : '';
        if ($d['year'] !== '') $d['sources']['year'] = 'PDF';
        $d['pdf_pages'] = preg_match_all('/\/Type\s*\/Page\b/', $raw, $mm) ? count($mm[0]) : 0;
        $d['pages'] = (string)$d['pdf_pages'];
        $d['scanned'] = true;
        $d['confidence'] = Extractor::score($d);
        $d['log'] = ['Safe-mode: smalot & native crash/timeout pada file ini (bendera tersimpan). Data dari metadata + nama file; perkayaan API & edit manual tetap tersedia.'];
        return $d;
    }
}