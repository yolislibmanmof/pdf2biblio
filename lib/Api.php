<?php
namespace PDF2Biblio;

class Api
{
    private const JFLAG = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    private static int $uid = 0;
    private static string $tmp = '';
    private static int $obLevel = 0; // A2: level buffer milik plugin (di atas buffer core)

    public static function boot(): void
    {
        if (!empty($GLOBALS['p2b_booted'])) return; // cegah BOOT dobel
        $GLOBALS['p2b_booted'] = true;

        // A2: buffer milik plugin — notice/warning liar tidak akan mencemari JSON.
        self::$obLevel = ob_get_level();
        ob_start();

        // Container SLiMS TIDAK memulai sesi admin → kita start sendiri.
        // JANGAN require admin/default/session.inc.php: file itu butuh $sanitizer
        // dari scope admin/index.php (undefined variable → Exception).
        if (session_status() === PHP_SESSION_NONE) {
            if (class_exists('\SLiMS\Session\Factory')) {
                try {
                    $driver = config('customSession', \SLiMS\Session\Driver\Files::class);
                    \SLiMS\Session\Factory::use($driver)->start('admin');
                } catch (\Throwable $e) {
                    @session_start();
                }
            } else {
                $ses = SB . 'admin/default/session.inc.php';
                if (is_file($ses)) {
                    // stub sanitizer bila harus lewat file lama (named args filter/exception)
                    $sanitizer = new class {
                        public function cleanUp(array $filter = [], array $exception = []) {}
                    };
                    require $ses;
                } else {
                    @session_start();
                }
            }
        }

        // Body JSON (bulk_save) TIDAK mengisi $_REQUEST/$_POST di PHP.
        // Parse sekali di sini agar router di semua pintu (index.php / manager.php / ajax.php)
        // melihat action + t. Api::save() tetap membaca php://input sendiri (stream boleh dibaca ulang).
        if (!isset($_REQUEST['action'])
            && stripos($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
            $p2bJson = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($p2bJson)) {
                $_POST = array_merge($_POST, $p2bJson);
                $_REQUEST = array_merge($_REQUEST, $p2bJson);
            }
        }

        self::$tmp = dirname(__DIR__) . '/tmp';
        if (!is_dir(self::$tmp)) @mkdir(self::$tmp, 0775, true);
        // A1: tutup akses web langsung ke tmp (PDF ber-hak-cipta + cover hanya lewat Api::cover).
        if (is_dir(self::$tmp)) {
            if (!is_file(self::$tmp . '/.htaccess')) {
                @file_put_contents(self::$tmp . '/.htaccess',
                    "# PDF2Biblio: tolak akses web langsung\n" .
                    "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
                    "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
            }
            if (!is_file(self::$tmp . '/index.html')) @file_put_contents(self::$tmp . '/index.html', '');
        }
        foreach (glob(self::$tmp . '/*') ?: [] as $f) {
            $b = basename($f);
            if ($b === 'index.html' || strpos($b, 'api_') === 0) continue; // A4: cache jangan dibuang
            if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
        }
        self::lg('BOOT main ok PHP=' . PHP_VERSION . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '-'));

        // Guard render: tanpa sesi admin, jangan bocorkan UI/CSRF ke tamu.
        if (!self::logged() && !isset($_REQUEST['action'])) {
            if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
            echo '<div style="padding:16px;font-family:sans-serif"><b>Sesi admin tidak ditemukan.</b> Login ulang di admin SLiMS, lalu buka kembali menu PDF2Biblio.</div>';
            exit;
        }
    }

    private static function logged(): bool
    {
        return !empty($_SESSION['uid']) || !empty($_SESSION['username']) || !empty($_SESSION['realname']);
    }

    private static function lg(string $m): void
    {
        $f = dirname(__DIR__) . '/debug.log';
        // A5: rotasi 1 generasi bila > 256 KB agar log tidak tumbuh tanpa batas.
        if (is_file($f) && filesize($f) > 262144) @rename($f, $f . '.1');
        @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $m . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function out(array $a, int $code = 200, string $tag = ''): void
    {
        if ($tag !== '') self::lg('json out ' . $tag);
        // A2: buang buffer plugin (dan apa pun yang terbuka setelah boot) sebelum JSON.
        while (ob_get_level() > self::$obLevel) ob_end_clean();
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($a, self::JFLAG);
        exit;
    }

    /** URL aksi dalam konteks container saat ini (sesi admin terjamin). */
    private static function url(array $add): string
    {
        $uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        $q = $_GET;
        unset($q['action'], $q['_'], $q['k'], $q['t']);
        $q['ajaxload'] = '1';
        $q['t'] = $_SESSION['p2b_csrf'] ?? '';
        return $uri . '?' . http_build_query(array_merge($q, $add));
    }

    private static function dbs()
    {
        $dbs = $GLOBALS['dbs'] ?? null;
        if (!$dbs) {
            $dbs = @new \mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
            if ($dbs->connect_error) self::out(['ok' => false, 'msg' => 'Koneksi DB gagal: ' . $dbs->connect_error], 500, 'db');
            $dbs->set_charset('utf8mb4');
            $GLOBALS['dbs'] = $dbs;
        }
        return $dbs;
    }

    public static function handle(): void
    {
        $action = (string)($_REQUEST['action'] ?? '');
        $alias = ['extract' => 'bulk_extract', 'save' => 'bulk_save', 'clear' => 'clear_queue'];
        $action = $alias[$action] ?? $action;
        self::lg('ACTION ' . $action);

        if (!self::logged()) {
            self::lg('auth FAIL');
            self::out(['ok' => false, 'msg' => 'Sesi admin tidak terbaca. Login ulang.'], 401, $action);
        }
        self::$uid = (int)($_SESSION['uid'] ?? 0);
        self::lg('auth ok');

        // diag = read-only untuk admin, tanpa CSRF (URL manual)
        if ($action !== 'diag') {
            $csrf = (string)($_REQUEST['t'] ?? '');
            if (empty($_SESSION['p2b_csrf']) || !hash_equals($_SESSION['p2b_csrf'], $csrf)) {
                self::lg('token FAIL');
                self::out(['ok' => false, 'msg' => 'Token CSRF tidak valid. Muat ulang halaman plugin.'], 403, $action);
            }
            self::lg('token ok');
        }

        try {
            switch ($action) {
                case 'bulk_extract': self::extract(); break;
                case 'bulk_save':    self::save(); break;
                case 'clear_queue':  self::clear(); break;
                case 'cover':        self::cover(); break;
                case 'diag':         self::diag(); break;
                default: self::out(['ok' => false, 'msg' => 'Aksi tidak dikenal: ' . $action], 400, $action);
            }
        } catch (\Throwable $e) {
            self::lg('ERROR ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::out(['ok' => false, 'msg' => 'Error server: ' . $e->getMessage()], 500, $action);
        }
    }

    /** Diagnostik: bongkar resolusi path container + kondisi disk. Admin only. */
    private static function diag(): void
    {
        $files = [];
        foreach (['vendor/autoload.php','lib/Extractor.php','lib/Enricher.php','lib/Importer.php','lib/Api.php','index.php','manager.php','ajax.php','pdf2biblio.plugin.php'] as $f) {
            $p = dirname(__DIR__) . '/' . $f;
            $files[$f] = is_file($p) ? filesize($p) : false;
        }
        $methods = null; $psrc = null;
        if (class_exists('SLiMS\Plugins')) {
            $r = new \ReflectionClass('SLiMS\Plugins');
            $methods = array_map(fn($m) => $m->getName(), $r->getMethods());
            $pf = $r->getFileName();
            if ($pf && is_file($pf)) $psrc = substr((string)file_get_contents($pf), 0, 6000);
        }
        $csrc = is_file(SB . 'admin/plugin_container.php') ? substr((string)file_get_contents(SB . 'admin/plugin_container.php'), 0, 6000) : null;
        self::out([
            'ok' => true, 'php' => PHP_VERSION, 'SB' => SB, 'SWB' => defined('SWB') ? SWB : null, 'DIR' => dirname(__DIR__),
            'files' => $files,
            'dir' => scandir(dirname(__DIR__)),
            'lib' => is_dir(dirname(__DIR__) . '/lib') ? scandir(dirname(__DIR__) . '/lib') : null,
            'session_keys' => array_keys($_SESSION),
            'uid' => $_SESSION['uid'] ?? null, 'username' => $_SESSION['username'] ?? null,
            'get' => $_GET, 'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'plugins_methods' => $methods, 'plugins_src' => $psrc, 'container_src' => $csrc,
        ], 200, 'diag');
    }

    private static function extract(): void
    {
        $action = 'bulk_extract';
        $f = $_FILES['pdf'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) self::out(['ok' => false, 'msg' => 'Upload gagal/kosong.'], 400, $action);
        if ($f['size'] > 64 * 1024 * 1024) self::out(['ok' => false, 'msg' => 'File > 64 MB.'], 400, $action);
        $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 1024);
        if (strpos($head, '%PDF-') === false) self::out(['ok' => false, 'msg' => 'Bukan PDF valid: ' . $f['name']], 400, $action);

        $token = bin2hex(random_bytes(8));
        $dest = self::$tmp . '/' . $token . '.pdf';
        if (!move_uploaded_file($f['tmp_name'], $dest)) self::out(['ok' => false, 'msg' => 'Gagal memindah file upload.'], 500, $action);
        self::lg('upload ok bytes=' . $f['size'] . ' forceNative=false');

        // A3: hardening parser di call-site (port mesin legacy anti-crash):
        // PCRE JIT rawan crash stack pada subjek PDF besar di Windows; beri napas memori/waktu.
        $oldJit = (string)@ini_get('pcre.jit');
        $oldMem = (string)@ini_get('memory_limit');
        @ini_set('pcre.jit', '0');
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);
        $d = Extractor::extract($dest, $f['name']);
        @ini_set('pcre.jit', $oldJit);
        @ini_set('memory_limit', $oldMem);
        self::lg('extract done engine=smalot pages=' . $d['pdf_pages']);
        $log = $d['log'] ?? [];
        unset($d['log']);
        $log[] = 'Ekstrak: ' . $f['name'];

        if (!empty($_POST['enrich'])) {
            $log = array_merge($log, Enricher::enrich($d, !empty($_POST['cover']), self::$tmp, $token, $dest));
        }
        self::lg('map done title=' . $d['title'] . ' | isbn=' . $d['isbn'] . ' | pub=' . $d['publisher'] . ' | place=' . $d['place'] . ' | year=' . $d['year']);

        $coverUrl = '';
        if (is_file(self::$tmp . '/' . $token . '.cover')) {
            $coverUrl = self::url(['action' => 'cover', 'k' => $token]);
            self::lg('cover done');
        } else {
            self::lg('cover none');
        }

        $fields = ['title','author','year','isbn','publisher','place','pages','collation','edition','series','language','classification','call_number','subjects','notes','pdf_name','pdf_pages','size','scanned','sources','confidence'];
        $row = array_intersect_key($d, array_flip($fields));
        $row['token'] = $token;
        $row['cover_url'] = $coverUrl;
        $row['log'] = $log;
        $filled = count(array_filter([$row['title'], $row['author'], $row['isbn'], $row['publisher'], $row['place'], $row['year']]));
        $log[] = "OK: {$row['title']} — $filled/6 field inti terisi ({$row['confidence']}%).";
        self::out(['ok' => true, 'row' => $row], 200, $action);
    }

    private static function save(): void
    {
        $action = 'bulk_save';
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in) || empty($in['rows'])) self::out(['ok' => false, 'msg' => 'Payload kosong.'], 400, $action);
        $dbs = self::dbs();
        $opts = ['attach' => !empty($in['opts']['attach']), 'skip_dup' => !empty($in['opts']['skip_dup'])];
        $res = [];
        foreach ($in['rows'] as $r) {
            $tk = (string)($r['token'] ?? '');
            if (!preg_match('/^[a-f0-9]{16}$/', $tk)) { $res[] = ['ok' => false, 'msg' => 'Token baris tidak valid.', 'token' => $tk]; continue; }
            $r['_pdf'] = self::$tmp . '/' . $tk . '.pdf';
            $r['_cover'] = self::$tmp . '/' . $tk . '.cover';
            // A2: isolasi per baris — satu PDF bermasalah tidak membatalkan seluruh batch.
            try {
                $o = Importer::save($r, $opts, $dbs, self::$uid ?: (int)($_SESSION['uid'] ?? 1));
            } catch (\Throwable $e) {
                $o = ['ok' => false, 'msg' => 'Error baris: ' . $e->getMessage()];
                self::lg('ERROR row ' . $tk . ' ' . $e->getMessage());
            }
            if (!empty($o['ok']) && empty($o['dup'])) { @unlink($r['_pdf']); @unlink($r['_cover']); }
            $o['token'] = $tk;
            self::lg('save ' . (!empty($o['ok']) ? (!empty($o['dup']) ? 'dup' : 'ok') : 'gagal') . ' #' . ($o['id'] ?? 0) . ' ' . ($o['msg'] ?? ''));
            $res[] = $o;
        }
        self::out(['ok' => true, 'results' => $res], 200, $action);
    }

    private static function clear(): void
    {
        $action = 'clear_queue';
        $tokens = $_POST['tokens'] ?? [];
        if (is_string($tokens)) $tokens = [$tokens];
        foreach ((array)$tokens as $t) {
            if (!preg_match('/^[a-f0-9]{16}$/', (string)$t)) continue;
            @unlink(self::$tmp . '/' . $t . '.pdf');
            @unlink(self::$tmp . '/' . $t . '.cover');
        }
        self::out(['ok' => true], 200, $action);
    }

    private static function cover(): void
    {
        $k = (string)($_GET['k'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $k)) self::out(['ok' => false, 'msg' => 'Token cover tidak valid.'], 400, 'cover');
        $f = self::$tmp . '/' . $k . '.cover';
        if (!is_file($f)) self::out(['ok' => false, 'msg' => 'Cover tidak ada.'], 404, 'cover');
        $blob = (string)file_get_contents($f);
        $info = @getimagesizefromstring($blob);
        self::lg('cover serve ' . $k);
        // A2: bersihkan buffer plugin sebelum blob gambar.
        while (ob_get_level() > self::$obLevel) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
            header('Cache-Control: private, max-age=3600');
        }
        echo $blob;
        exit;
    }
}