<?php
namespace PDF2Biblio;

class Api
{
    private const JFLAG = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    private static int $uid = 0;
    private static string $tmp = '';
    private static int $obLevel = 0;
    private static bool $manifestReady = false;

    public static function boot(): void
    {
        if (!empty($GLOBALS['p2b_booted'])) return; // cegah BOOT dobel
        $GLOBALS['p2b_booted'] = true;

        // A2: buffer milik plugin — notice/warning liar tidak akan mencemari JSON.
        self::$obLevel = ob_get_level();
        ob_start();

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
        // A1: tutup akses web langsung ke tmp.
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
            if ($b === 'index.html' || strpos($b, 'api_') === 0) continue; // cache jangan dibuang
            if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f); // antrian/manifest TTL 24 jam
        }
        self::lg('BOOT main ok PHP=' . PHP_VERSION . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '-'));

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
        if (is_file($f) && filesize($f) > 262144) @rename($f, $f . '.1');
        @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $m . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function out(array $a, int $code = 200, string $tag = ''): void
    {
        if ($tag !== '') self::lg('json out ' . $tag);
        while (ob_get_level() > self::$obLevel) ob_end_clean();
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($a, self::JFLAG);
        exit;
    }

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

    private static function exec($dbs, string $sql): bool
    {
        try { return (bool)@$dbs->query($sql); } catch (\Throwable $e) { self::lg('SQL ERR ' . $e->getMessage()); return false; }
    }

    private static function rows($dbs, string $sql): array
    {
        try {
            $r = @$dbs->query($sql); $out = [];
            if ($r) while ($x = $r->fetch_assoc()) $out[] = $x;
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    /** B4: manifest batch — lazy create, tanpa bergantung mekanisme migration core. */
    private static function ensureManifest($dbs): void
    {
        if (self::$manifestReady) return;
        self::$manifestReady = true;
        self::exec($dbs, "CREATE TABLE IF NOT EXISTS `plugin_pdf2biblio_batch` (
            `batch_id` varchar(32) NOT NULL, `uid` int(11) NOT NULL DEFAULT 0,
            `label` varchar(60) NOT NULL DEFAULT '', `created_at` datetime NOT NULL,
            `summary` text NULL, PRIMARY KEY (`batch_id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");
        self::exec($dbs, "CREATE TABLE IF NOT EXISTS `plugin_pdf2biblio_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT, `batch_id` varchar(32) NOT NULL,
            `biblio_id` int(11) NOT NULL DEFAULT 0, `token` varchar(16) NOT NULL DEFAULT '',
            `file_name` varchar(255) NOT NULL DEFAULT '', `status` varchar(10) NOT NULL DEFAULT '',
            `msg` varchar(255) NOT NULL DEFAULT '', `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`), KEY `batch_id` (`batch_id`), KEY `biblio_id` (`biblio_id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");
    }

    public static function handle(): void
    {
        $action = (string)($_REQUEST['action'] ?? '');
        $alias = ['extract' => 'bulk_extract', 'save' => 'bulk_save', 'clear' => 'clear_queue', 'undo' => 'undo_batch'];
        $action = $alias[$action] ?? $action;
        self::lg('ACTION ' . $action);

        if (!self::logged()) {
            self::lg('auth FAIL');
            self::out(['ok' => false, 'msg' => 'Sesi admin tidak terbaca. Login ulang.'], 401, $action);
        }
        self::$uid = (int)($_SESSION['uid'] ?? 0);
        self::lg('auth ok');

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
                case 'queue':        self::queue(); break;
                case 'meta':         self::meta(); break;
                case 'batches':      self::batches(); break;
                case 'undo_batch':   self::undo(); break;
                case 'diag':         self::diag(); break;
                default: self::out(['ok' => false, 'msg' => 'Aksi tidak dikenal: ' . $action], 400, $action);
            }
        } catch (\Throwable $e) {
            self::lg('ERROR ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::out(['ok' => false, 'msg' => 'Error server: ' . $e->getMessage()], 500, $action);
        }
    }

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

        // B5: dedupe upload berdasarkan hash isi file — file sama tidak diekstrak ulang.
        $hash = (string)@sha1_file($f['tmp_name']);
        if ($hash !== '') {
            $old = json_decode((string)@file_get_contents(self::$tmp . '/h_' . $hash . '.json'), true);
            $otk = (string)($old['token'] ?? '');
            if (preg_match('/^[a-f0-9]{16}$/', $otk) && is_file(self::$tmp . '/' . $otk . '.json')) {
                $q = json_decode((string)@file_get_contents(self::$tmp . '/' . $otk . '.json'), true);
                if (is_array($q) && (int)($q['uid'] ?? -1) === self::$uid && !empty($q['row'])) {
                    $row = $q['row'];
                    $row['cover_url'] = is_file(self::$tmp . '/' . $otk . '.cover') ? self::url(['action' => 'cover', 'k' => $otk]) : '';
                    $row['log'] = array_merge((array)($row['log'] ?? []), ['Upload duplikat: file sama sudah dalam antrian (hash identik).']);
                    self::lg('upload dup hash=' . substr($hash, 0, 12) . ' token=' . $otk);
                    self::out(['ok' => true, 'row' => $row], 200, $action);
                }
            }
        }

        $token = bin2hex(random_bytes(8));
        $dest = self::$tmp . '/' . $token . '.pdf';
        if (!move_uploaded_file($f['tmp_name'], $dest)) self::out(['ok' => false, 'msg' => 'Gagal memindah file upload.'], 500, $action);
        if ($hash !== '') @file_put_contents(self::$tmp . '/h_' . $hash . '.json', json_encode(['token' => $token]));
        self::lg('upload ok bytes=' . $f['size'] . ' forceNative=false');

        // A3: hardening parser di call-site (port mesin legacy anti-crash).
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
        // B5: manifest antrian per sesi (tahan refresh; TTL 24 jam via cleanup boot).
        @file_put_contents(self::$tmp . '/' . $token . '.json', json_encode(['uid' => self::$uid, 'created' => time(), 'row' => $row], self::JFLAG));
        self::out(['ok' => true, 'row' => $row], 200, $action);
    }

    private static function save(): void
    {
        $action = 'bulk_save';
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in) || empty($in['rows'])) self::out(['ok' => false, 'msg' => 'Payload kosong.'], 400, $action);
        $dbs = self::dbs();
        self::ensureManifest($dbs);
        $oo = (array)($in['opts'] ?? []);
        $label = mb_substr(trim(preg_replace('/[^\p{L}\p{N} _.\-]/u', '', (string)($oo['label'] ?? ''))), 0, 60);
        if ($label === '') $label = 'p2b-' . date('Ymd-His');
        $batch = bin2hex(random_bytes(8));
        $opts = [
            'attach' => !empty($oo['attach']), 'skip_dup' => !empty($oo['skip_dup']),
            'item' => !empty($oo['item']), 'coll_type' => (int)($oo['coll_type'] ?? 0),
            'location' => (int)($oo['location'] ?? 0), 'item_prefix' => (string)($oo['item_prefix'] ?? 'P2B'),
            'private' => !empty($oo['private']), 'limit' => (array)($oo['limit'] ?? []),
            'label' => $label,
        ];
        self::exec($dbs, "INSERT INTO plugin_pdf2biblio_batch (batch_id, uid, label, created_at) VALUES ('" . $dbs->real_escape_string($batch) . "', " . self::$uid . ", '" . $dbs->real_escape_string($label) . "', '" . date('Y-m-d H:i:s') . "')");
        self::lg('batch ' . $batch . ' label=' . $label);
        $res = [];
        $count = ['ok' => 0, 'dup' => 0, 'gagal' => 0];
        foreach ($in['rows'] as $r) {
            $tk = (string)($r['token'] ?? '');
            if (!preg_match('/^[a-f0-9]{16}$/', $tk)) { $res[] = ['ok' => false, 'msg' => 'Token baris tidak valid.', 'token' => $tk]; $count['gagal']++; continue; }
            $r['_pdf'] = self::$tmp . '/' . $tk . '.pdf';
            $r['_cover'] = self::$tmp . '/' . $tk . '.cover';
            // A2: isolasi per baris — satu PDF bermasalah tidak membatalkan seluruh batch.
            try {
                $o = Importer::save($r, $opts, $dbs, self::$uid ?: (int)($_SESSION['uid'] ?? 1));
            } catch (\Throwable $e) {
                $o = ['ok' => false, 'msg' => 'Error baris: ' . $e->getMessage()];
                self::lg('ERROR row ' . $tk . ' ' . $e->getMessage());
            }
            if (!empty($o['ok']) && empty($o['dup'])) {
                @unlink($r['_pdf']); @unlink($r['_cover']);
                @unlink(self::$tmp . '/' . $tk . '.json'); // B5: baris tersimpan keluar dari antrian
            }
            $o['token'] = $tk;
            $st = !empty($o['ok']) ? (!empty($o['dup']) ? 'dup' : 'ok') : 'gagal';
            $count[$st]++;
            self::lg('save ' . $st . ' #' . ($o['id'] ?? 0) . ' ' . ($o['msg'] ?? ''));
            // B4: manifest per baris (fondasi undo & laporan).
            self::exec($dbs, "INSERT INTO plugin_pdf2biblio_log (batch_id, biblio_id, token, file_name, status, msg, created_at) VALUES ('"
                . $dbs->real_escape_string($batch) . "', " . (int)($o['id'] ?? 0) . ", '" . $dbs->real_escape_string($tk) . "', '"
                . $dbs->real_escape_string(mb_substr((string)($r['pdf_name'] ?? ''), 0, 255)) . "', '" . $st . "', '"
                . $dbs->real_escape_string(mb_substr((string)($o['msg'] ?? ''), 0, 255)) . "', '" . date('Y-m-d H:i:s') . "')");
            $res[] = $o;
        }
        self::exec($dbs, "UPDATE plugin_pdf2biblio_batch SET summary='" . $dbs->real_escape_string(json_encode($count, self::JFLAG)) . "' WHERE batch_id='" . $dbs->real_escape_string($batch) . "'");
        self::out(['ok' => true, 'results' => $res, 'batch_id' => $batch, 'label' => $label, 'count' => $count], 200, $action);
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
            @unlink(self::$tmp . '/' . $t . '.json'); // B5
        }
        self::out(['ok' => true], 200, $action);
    }

    /** B5: pulihkan antrian milik sesi ini setelah refresh halaman. */
    private static function queue(): void
    {
        $rows = [];
        foreach (glob(self::$tmp . '/*.json') ?: [] as $f) {
            if (!preg_match('/^[a-f0-9]{16}\.json$/', basename($f))) continue;
            $q = json_decode((string)@file_get_contents($f), true);
            if (!is_array($q) || (int)($q['uid'] ?? -1) !== self::$uid || empty($q['row'])) continue;
            $row = $q['row'];
            $tk = (string)($row['token'] ?? '');
            if (!preg_match('/^[a-f0-9]{16}$/', $tk) || !is_file(self::$tmp . '/' . $tk . '.pdf')) continue;
            $row['cover_url'] = is_file(self::$tmp . '/' . $tk . '.cover') ? self::url(['action' => 'cover', 'k' => $tk]) : '';
            $rows[] = $row;
        }
        self::lg('queue restore ' . count($rows));
        self::out(['ok' => true, 'rows' => $rows], 200, 'queue');
    }

    /** B3: daftar master untuk select UI (tipe koleksi, lokasi, tipe anggota). */
    private static function meta(): void
    {
        $dbs = self::dbs();
        $list = function (string $t, string $ic, string $nc) use ($dbs): array {
            $out = [];
            foreach (self::rows($dbs, "SELECT `$ic` AS id, `$nc` AS name FROM `$t` ORDER BY `$nc` ASC LIMIT 200") as $x) {
                $out[] = ['id' => (int)$x['id'], 'name' => (string)$x['name']];
            }
            return $out;
        };
        $coll = $list('mst_collection_type', 'coll_type_id', 'coll_type_name');
        $loc  = $list('mst_location', 'location_id', 'location_name');
        $mt   = $list('mst_member_type', 'member_type_id', 'member_type_name');
        self::out(['ok' => true, 'coll_types' => $coll, 'locations' => $loc, 'member_types' => $mt,
            'defaults' => ['coll_type' => $coll[0]['id'] ?? 0, 'location' => $loc[0]['id'] ?? 0]], 200, 'meta');
    }

    /** B4: daftar batch terakhir milik sesi ini (untuk undo & laporan). */
    private static function batches(): void
    {
        $dbs = self::dbs();
        self::ensureManifest($dbs);
        $out = [];
        foreach (self::rows($dbs, "SELECT batch_id, label, created_at, summary FROM plugin_pdf2biblio_batch WHERE uid=" . self::$uid . " ORDER BY created_at DESC LIMIT 20") as $b) {
            $c = ['ok' => 0, 'dup' => 0, 'gagal' => 0];
            foreach (self::rows($dbs, "SELECT status, COUNT(*) AS n FROM plugin_pdf2biblio_log WHERE batch_id='" . $dbs->real_escape_string($b['batch_id']) . "' GROUP BY status") as $x) {
                $c[$x['status']] = (int)$x['n'];
            }
            $out[] = ['batch_id' => $b['batch_id'], 'label' => $b['label'], 'created_at' => $b['created_at'], 'count' => $c];
        }
        self::out(['ok' => true, 'batches' => $out], 200, 'batches');
    }

    /** B4: undo batch — hapus biblio hasil batch ini beserta relasi, file & indeksnya. */
    private static function undo(): void
    {
        $action = 'undo_batch';
        $dbs = self::dbs();
        self::ensureManifest($dbs);
        $batch = (string)($_REQUEST['batch_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $batch)) self::out(['ok' => false, 'msg' => 'Batch tidak valid.'], 400, $action);
        if ((string)($_REQUEST['yes'] ?? '') !== '1') self::out(['ok' => false, 'msg' => 'Konfirmasi diperlukan (yes=1).'], 400, $action);
        $b = self::rows($dbs, "SELECT batch_id, uid, label FROM plugin_pdf2biblio_batch WHERE batch_id='" . $dbs->real_escape_string($batch) . "' LIMIT 1");
        if (!$b) self::out(['ok' => false, 'msg' => 'Batch tidak ditemukan.'], 404, $action);
        if ((int)$b[0]['uid'] !== self::$uid && self::$uid > 0) self::out(['ok' => false, 'msg' => 'Batch milik admin lain.'], 403, $action);
        $deleted = 0;
        foreach (self::rows($dbs, "SELECT biblio_id FROM plugin_pdf2biblio_log WHERE batch_id='" . $dbs->real_escape_string($batch) . "' AND status='ok' AND biblio_id>0") as $x) {
            $bid = (int)$x['biblio_id'];
            // file lampiran milik biblio ini (batch ok = biblio baru, semua lampiran milik kita)
            foreach (self::rows($dbs, "SELECT f.file_id, f.file_url FROM biblio_attachment a JOIN files f ON f.file_id=a.file_id WHERE a.biblio_id=$bid") as $f) {
                $url = (string)$f['file_url'];
                if (strpos($url, 'repository/') === 0 || strpos($url, 'images/docs/') === 0) @unlink(rtrim(SB, '/') . '/' . $url);
                self::exec($dbs, "DELETE FROM files WHERE file_id=" . (int)$f['file_id']);
            }
            self::exec($dbs, "DELETE FROM biblio_attachment WHERE biblio_id=$bid");
            self::exec($dbs, "DELETE FROM item WHERE biblio_id=$bid");
            self::exec($dbs, "DELETE FROM biblio_author WHERE biblio_id=$bid");
            self::exec($dbs, "DELETE FROM biblio_topic WHERE biblio_id=$bid");
            self::exec($dbs, "DELETE FROM search_biblio WHERE biblio_id=$bid");
            if (self::exec($dbs, "DELETE FROM biblio WHERE biblio_id=$bid")) $deleted++;
        }
        self::exec($dbs, "DELETE FROM plugin_pdf2biblio_log WHERE batch_id='" . $dbs->real_escape_string($batch) . "'");
        self::exec($dbs, "DELETE FROM plugin_pdf2biblio_batch WHERE batch_id='" . $dbs->real_escape_string($batch) . "'");
        self::lg('undo batch=' . $batch . ' deleted=' . $deleted);
        self::out(['ok' => true, 'deleted' => $deleted, 'msg' => 'Undo batch: ' . $deleted . ' bibliografi dihapus.'], 200, $action);
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
        while (ob_get_level() > self::$obLevel) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
            header('Cache-Control: private, max-age=3600');
        }
        echo $blob;
        exit;
    }
}