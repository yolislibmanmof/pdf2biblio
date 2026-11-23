<?php
namespace PDF2Biblio;

/**
 * PDF2Biblio — runner parse terisolasi (v2.5.0).
 *
 * Alur: child PHP CLI (smalot -> native) -> in-process (ParseGuard) -> safe-mode.
 * Perbaikan atas v2.4.0:
 *  1. Deteksi PHP CLI diuji lewat PHP_SAPI, bukan nama file (php-cgi/php-fpm tidak lagi terpilih).
 *  2. proc_open mode argv-array + bypass_shell: aman untuk path berspasi, tanpa cmd.exe.
 *  3. proc_close() SELALU dipanggil, termasuk saat timeout (tidak ada proses zombie).
 *  4. Bendera crash hanya untuk crash/timeout nyata, TTL 15 menit, butuh 2x crash.
 *  5. CLI tidak ada / gagal lunak -> parse in-process sungguhan, bukan safe-mode miskin data.
 */
class ParseRunner
{
    private const CHILD_TIMEOUT = 60;   // detik, per mesin
    private const PROBE_TIMEOUT = 10;   // detik, uji kandidat PHP CLI
    private const FLAG_TTL      = 900;  // detik, bendera crash kedaluwarsa 15 menit
    private const MAX_CRASH     = 2;    // crash ke-2 baru memblokir mesin

    private static ?string $php = null;
    private static bool $phpResolved = false;
    private static array $info = ['engine' => '', 'php' => '', 'why' => ''];

    /** Dipakai Api untuk log/diag: mesin apa yang benar-benar dipakai. */
    public static function info(): array
    {
        return self::$info;
    }

    /** Hapus semua bendera crash + paksa deteksi ulang PHP CLI. */
    public static function resetFlags(): int
    {
        $n = 0;
        foreach (glob(self::tmpDir() . '/crash_*.flag') ?: [] as $f) {
            if (@unlink($f)) $n++;
        }
        self::$php = null;
        self::$phpResolved = false;
        self::$info = ['engine' => '', 'php' => '', 'why' => ''];
        return $n;
    }

    public static function extract(string $file, string $orig): array
    {
        $hash = (string)@sha1_file($file);
        if ($hash === '') $hash = md5($orig . '|' . (string)@filesize($file));
        $tmp = self::tmpDir();
        $php = self::phpCli();
        $notes = [];
        $crashed = false;

        if ($php === '') {
            $notes[] = self::$info['why'] !== '' ? self::$info['why'] : 'PHP CLI tidak tersedia.';
        } else {
            foreach (['smalot', 'native'] as $eng) {
                $flag = $tmp . '/crash_' . $hash . '_' . $eng . '.flag';
                $st = self::flagState($flag);
                if ($st['blocked']) {
                    $crashed = true;
                    $notes[] = 'Mesin ' . $eng . ' dilewati: ' . $st['n'] . 'x crash pada file ini (bendera aktif 15 menit).';
                    continue;
                }

                $out = $tmp . '/parse_' . $hash . '_' . $eng . '.json';
                @unlink($out);
                $r = self::spawn([$php, dirname(__DIR__) . '/cli_parse.php', $file, $orig, $eng, $out], self::CHILD_TIMEOUT);
                $j = is_file($out) ? json_decode((string)@file_get_contents($out), true) : null;
                @unlink($out);

                if ($r['ok'] && is_array($j) && isset($j['title'])) {
                    self::forget($flag);
                    self::$info['engine'] = $eng;
                    $j['log'] = array_merge((array)($j['log'] ?? []), $notes,
                        ['Mesin: ' . $eng . ' (child PHP CLI terisolasi, batas ' . self::CHILD_TIMEOUT . ' detik).']);
                    return $j;
                }

                $err = is_array($j) ? (string)($j['error'] ?? '') : '';
                $msg = is_array($j) ? (string)($j['msg'] ?? '') : '';
                $hard = $r['timeout'] || $r['signal'] || $err === 'fatal' || ($r['exit'] !== 0 && $err === '');

                if ($hard) {
                    $crashed = true;
                    $why = $r['timeout'] ? 'timeout' : ($err === 'fatal' ? 'fatal' : 'crash');
                    self::remember($flag, $why, $st['n'] + 1);
                    $notes[] = 'Mesin ' . $eng . ' ' . $why . ' (exit=' . $r['exit'] . ')'
                        . ($msg !== '' ? ': ' . mb_substr($msg, 0, 160) : '')
                        . ($r['err'] !== '' ? ' | stderr: ' . mb_substr(trim($r['err']), 0, 160) : '');
                    continue;
                }

                // Gagal lunak: BUKAN crash, jangan tulis bendera.
                $notes[] = 'Mesin ' . $eng . ' gagal lunak (' . ($err !== '' ? $err : 'output tidak valid') . ')'
                    . ($msg !== '' ? ': ' . mb_substr($msg, 0, 160) : '') . ' — tanpa bendera crash.';
                if (in_array($err, ['sapi', 'input', 'arg', 'write'], true)) break; // tidak tergantung mesin
            }
        }

        if ($crashed) {
            self::$info['engine'] = 'safe-mode';
            return self::safe($file, $orig, $notes);
        }
        return self::inProcess($file, $orig, $notes);
    }

    /** Parse di proses web (guarded) — dipakai bila child CLI tidak ada / gagal lunak. */
    private static function inProcess(string $file, string $orig, array $notes): array
    {
        $pg = __DIR__ . '/ParseGuard.php';
        if (is_file($pg)) {
            require_once $pg;
            if (class_exists('\PDF2Biblio\ParseGuard')) {
                try {
                    $d = ParseGuard::extract($file, $orig);
                    if (is_array($d) && isset($d['title'])) {
                        self::$info['engine'] = 'in-process';
                        $d['log'] = array_merge((array)($d['log'] ?? []), $notes);
                        return $d;
                    }
                } catch (\Throwable $e) {
                    $notes[] = 'Parse in-process gagal: ' . $e->getMessage();
                }
            }
        }
        self::$info['engine'] = 'safe-mode';
        return self::safe($file, $orig, $notes);
    }

    /**
     * Jalankan perintah (argv array, tanpa shell) dengan batas waktu.
     * Mengembalikan ['ok','exit','timeout','signal','out','err'].
     */
    private static function spawn(array $argv, int $timeout): array
    {
        $res = ['ok' => false, 'exit' => -1, 'timeout' => false, 'signal' => false, 'out' => '', 'err' => ''];
        if (!function_exists('proc_open')) { $res['err'] = 'proc_open() dinonaktifkan'; return $res; }

        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $opts = ['suppress_errors' => true, 'bypass_shell' => true];
        $p = @proc_open($argv, $spec, $pipes, dirname(__DIR__), null, $opts);
        if (!is_resource($p)) { $res['err'] = 'proc_open gagal'; return $res; }

        fclose($pipes[0]); // child tidak mewarisi stdin web
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start = microtime(true);
        $exit = -1; $sig = false; $running = true;
        while (true) {
            $st = proc_get_status($p);
            if (!$st['running']) { $running = false; $exit = (int)$st['exitcode']; $sig = !empty($st['signaled']); break; }
            if (microtime(true) - $start > $timeout) break;
            $res['out'] .= (string)@fread($pipes[1], 8192);
            $res['err'] .= (string)@fread($pipes[2], 8192);
            if (strlen($res['out']) > 262144) $res['out'] = substr($res['out'], -262144);
            if (strlen($res['err']) > 65536)  $res['err'] = substr($res['err'], -65536);
            usleep(50000);
        }

        if ($running) {
            $res['timeout'] = true;
            @proc_terminate($p, 9);
            $t0 = microtime(true);
            while (microtime(true) - $t0 < 3.0) {
                $st = proc_get_status($p);
                if (!$st['running']) { $exit = (int)$st['exitcode']; break; }
                usleep(50000);
            }
        }

        $res['out'] .= (string)@stream_get_contents($pipes[1], 262144);
        $res['err'] .= (string)@stream_get_contents($pipes[2], 65536);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $close = proc_close($p); // WAJIB: selalu dipanggil, termasuk jalur timeout
        if ($exit === -1 && $close !== -1) $exit = $close;

        $res['exit'] = $exit;
        $res['signal'] = $sig;
        $res['ok'] = !$res['timeout'] && !$sig && $exit === 0;
        return $res;
    }

    /** Cari PHP CLI asli. Uji `echo PHP_SAPI;` — bukan cocokkan nama file. */
    private static function phpCli(): string
    {
        if (self::$phpResolved) return (string)self::$php;
        self::$phpResolved = true;
        self::$php = null;
        self::$info['php'] = '';
        self::$info['why'] = '';

        if (!function_exists('proc_open')) {
            self::$info['why'] = 'proc_open() dinonaktifkan di php.ini — parse dijalankan in-process.';
            return '';
        }

        $win = DIRECTORY_SEPARATOR === '\\';
        $cands = [];

        // Override manual: konstanta P2B_PHP_CLI atau berkas php_cli.txt di root plugin.
        if (defined('P2B_PHP_CLI')) {
            $v = trim((string)constant('P2B_PHP_CLI'));
            if ($v !== '') $cands[] = $v;
        }
        $manual = dirname(__DIR__) . '/php_cli.txt';
        if (is_file($manual)) {
            $v = trim((string)@file_get_contents($manual));
            if ($v !== '') $cands[] = $v;
        }

        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $cands[] = PHP_BINDIR . DIRECTORY_SEPARATOR . ($win ? 'php.exe' : 'php');
        }
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') $cands[] = PHP_BINARY;
        if ($win) {
            foreach (['C:\\laragon\\bin\\php\\*\\php.exe', 'C:\\xampp\\php\\php.exe', 'C:\\php\\php.exe', 'C:\\tools\\php*\\php.exe'] as $g) {
                foreach (glob($g) ?: [] as $hit) $cands[] = $hit;
            }
        } else {
            foreach (['/usr/bin/php', '/usr/local/bin/php', '/opt/lampp/bin/php'] as $hit) $cands[] = $hit;
        }
        $cands[] = 'php'; // andalkan PATH

        $seen = [];
        foreach ($cands as $c) {
            $key = strtolower($c);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $bn = strtolower(basename($c));
            if (preg_match('/^(php-cgi|php-fpm|phpdbg|php\d*apache|httpd|apache|nginx|lsphp)/', $bn)) continue;
            if ($c !== 'php' && !is_file($c)) continue;
            $r = self::spawn([$c, '-n', '-r', 'echo PHP_SAPI;'], self::PROBE_TIMEOUT);
            if ($r['ok'] && strtolower(trim($r['out'])) === 'cli') {
                self::$php = $c;
                self::$info['php'] = $c;
                return $c;
            }
        }

        self::$info['why'] = 'PHP CLI tidak ditemukan atau tidak boleh dijalankan. Isi berkas php_cli.txt di root plugin dengan path php.exe/php CLI bila perlu.';
        return '';
    }

    private static function tmpDir(): string
    {
        $tmp = dirname(__DIR__) . '/tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
        return $tmp;
    }

    private static function flagState(string $flag): array
    {
        if (!is_file($flag)) return ['n' => 0, 'blocked' => false];
        if (time() - (int)@filemtime($flag) > self::FLAG_TTL) { @unlink($flag); return ['n' => 0, 'blocked' => false]; }
        $j = json_decode((string)@file_get_contents($flag), true);
        $n = is_array($j) ? max(1, (int)($j['n'] ?? 1)) : 1; // bendera format lama (teks) dihitung 1
        return ['n' => $n, 'blocked' => $n >= self::MAX_CRASH];
    }

    private static function remember(string $flag, string $why, int $n): void
    {
        @file_put_contents($flag, json_encode(['n' => $n, 'why' => $why, 't' => time()]), LOCK_EX);
    }

    private static function forget(string $flag): void
    {
        if (is_file($flag)) @unlink($flag);
    }

    /** Jalan terakhir: scan linear tanpa smalot & tanpa PCRE backtrack berat. */
    private static function safe(string $file, string $orig, array $notes = []): array
    {
        $d = Extractor::blank();
        $d['pdf_name'] = $orig;
        $d['size'] = (int)@filesize($file);
        $raw = (string)@file_get_contents($file, false, null, 0, 4000000);

        $meta = [];
        foreach (['Title', 'Author', 'Subject', 'Keywords', 'CreationDate'] as $k) {
            $pos = strpos($raw, '/' . $k);
            if ($pos === false) continue;
            $s = ltrim(substr($raw, $pos + strlen($k) + 1, 400));
            if ($s !== '' && $s[0] === '(') {
                $end = strpos($s, ')');
                $v = $end === false ? substr($s, 1, 200) : substr($s, 1, $end - 1);
                if (!mb_check_encoding($v, 'UTF-8')) $v = (string)@mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
                $meta[strtolower($k)] = trim($v);
            }
        }

        $isbn = ''; $off = 0;
        while (($p = stripos($raw, 'ISBN', $off)) !== false) {
            $digits = strtoupper((string)preg_replace('/[^0-9Xx]/', '', substr($raw, $p, 60)));
            if (strlen($digits) >= 13 && Extractor::isbn13(substr($digits, 0, 13))) { $isbn = substr($digits, 0, 13); break; }
            if (strlen($digits) >= 10 && Extractor::isbn10(substr($digits, 0, 10))) { $isbn = Extractor::to13(substr($digits, 0, 10)); break; }
            $off = $p + 4;
        }

        $stem = (string)preg_replace('/\(\+?ISBN\)/i', '', pathinfo($orig, PATHINFO_FILENAME));
        $stem = (string)preg_replace('/^[A-Z]{1,3}\s?\d{1,3}(?:[.,]\d+)?\s*[-–_]\s*/u', '', $stem);
        $stem = trim((string)preg_replace('/\s+/u', ' ', str_replace(['_', '--'], ' ', $stem)));
        $mt = (string)($meta['title'] ?? '');
        $d['title'] = mb_substr($mt !== '' && mb_strlen($mt) >= 6 ? $mt : $stem, 0, 300);
        $d['sources']['title'] = $mt !== '' && mb_strlen($mt) >= 6 ? 'PDF' : 'FILE';
        $d['author'] = trim((string)($meta['author'] ?? ''));
        if ($d['author'] !== '') $d['sources']['author'] = 'PDF';
        $d['isbn'] = $isbn;
        if ($isbn !== '') $d['sources']['isbn'] = 'PDF';

        $year = '';
        if (preg_match('/D:((?:19|20)\d{2})/', (string)($meta['creationdate'] ?? ''), $m)) $year = $m[1];
        if ($year === '' && preg_match('/\b(?:19|20)\d{2}\b/', $raw, $m)) $year = $m[0];
        $d['year'] = $year;
        if ($year !== '') $d['sources']['year'] = 'PDF';

        $pages = (int)preg_match_all('/\/Type\s*\/Page\b(?!s)/', $raw);
        if (preg_match_all('/\/Count\s+(\d+)/', $raw, $cm)) {
            foreach ($cm[1] as $c) $pages = max($pages, (int)$c);
        }
        $d['pdf_pages'] = $pages;
        $d['pages'] = (string)$pages;
        $d['scanned'] = false; // tidak diketahui di safe-mode; jangan tandai semua file sebagai scan
        $d['confidence'] = Extractor::score($d);
        $d['log'] = array_merge($notes, ['Safe-mode: mesin parse penuh tidak tersedia untuk file ini. Data dari metadata + nama file; perkayaan API & edit manual tetap tersedia.']);
        return $d;
    }
}