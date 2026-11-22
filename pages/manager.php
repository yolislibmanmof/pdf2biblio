<?php
/**
 * PDF2Biblio - endpoint AJAX + halaman manager (v1.1.0 FINAL).
 * Rute resmi: admin/plugin_container.php?mod=bibliography&id=<md5 file ini>
 */
if (!defined('INDEX_AUTH')) { define('INDEX_AUTH', 1); }
if (!defined('SB')) { require __DIR__ . '/../../../sysconfig.inc.php'; }
require_once SB . 'admin/default/session.inc.php';

// Daftarkan penangkap fatal PALING AWAL (sebelum require lain) agar kematian
// di tahap require/vendor pun tercatat dan dibalas JSON, bukan 500 bisu.
register_shutdown_function('p2b_fatal_catcher');
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) { return false; } // hormati operator @ (gzuncompress/unlink wajar gagal)
    if ($no & (E_WARNING | E_ERROR | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR)) {
        p2b_trace("PHP[$no] $str @ " . basename($file) . ":$line");
    }
    return false;
});

require_once __DIR__ . '/../lib/PdfExtractor.php';
require_once __DIR__ . '/../lib/BiblioMapper.php';

$p2bVendorFile = __DIR__ . '/../vendor/autoload.php';
$GLOBALS['p2b_vendor_file'] = is_file($p2bVendorFile);
if ($GLOBALS['p2b_vendor_file']) { require_once $p2bVendorFile; }
// Fallback autoloader PSR-4 manual bila autoload composer tidak registering.
if (empty($GLOBALS['p2b_autoload_loaded'])) {
    $p2bSrc = __DIR__ . '/../vendor/smalot/pdfparser/src/Smalot/Pdfparser';
    if (is_dir($p2bSrc)) {
        spl_autoload_register(function ($class) use ($p2bSrc) {
            $prefix = 'Smalot\\Pdfparser\\';
            if (strpos($class, $prefix) === 0) {
                $file = $p2bSrc . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) { require_once $file; }
            }
        });
        $GLOBALS['p2b_autoload_loaded'] = class_exists('\Smalot\Pdfparser\Parser');
    }
}


global $dbs, $sysconf;

if (!function_exists('p2b_trace')) {
    function p2b_trace($msg)
    {
        $dir = defined('UPLOAD') ? UPLOAD . 'p2b_stage' : sys_get_temp_dir();
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'p2b_trace.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
    }
}

function p2b_fatal_catcher()
{
    if (!empty($GLOBALS['p2b_fatal_done'])) { return; }
    $GLOBALS['p2b_fatal_done'] = true;
    $e = error_get_last();
    if (!$e || !in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) { return; }
    $msg = 'PHP Fatal: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line'];
    $sep = defined('DS') ? DS : DIRECTORY_SEPARATOR;
    $dir = defined('UPLOAD') ? UPLOAD . 'p2b_stage' : sys_get_temp_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . $sep . 'p2b_error.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
    p2b_trace('FATAL ' . $msg);
    $tok = isset($_POST['token']) ? preg_replace('/[^a-f0-9]/', '', (string)$_POST['token']) : '';
    if ($tok !== '') { @file_put_contents($dir . $sep . 'fatal_' . $tok . '.flag', $msg); }
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode(array('status'=>'error','message'=>$msg,'hint'=>'Detail: files/p2b_stage/p2b_error.log. Coba lagi - plugin otomatis memakai mode fallback.'));
}

function p2b_json($data, $code = 200)
{
    if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); }
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode($data);
    exit;
}

function p2b_auth()
{
    global $sysconf;
    if (empty($_SESSION['uid'])) { p2b_json(array('status'=>'error','message'=>'Sesi berakhir. Silakan login ulang.'), 401); }
    $timeout = isset($sysconf['session_timeout']) ? (int)$sysconf['session_timeout'] : 7200;
    if (empty($_SESSION['remember_me']) && isset($_SESSION['logintime']) && (time() - (int)$_SESSION['logintime']) >= $timeout) {
        p2b_json(array('status'=>'error','message'=>'Sesi berakhir (timeout). Login ulang.'), 401);
    }
    $_SESSION['logintime'] = time();
    if (!class_exists('utility') || !utility::havePrivilege('bibliography', 'w')) {
        p2b_json(array('status'=>'error','message'=>'Anda tidak berhak menulis data bibliografi.'), 403);
    }
}

function p2b_stage_dir()
{
    $dir = UPLOAD . 'p2b_stage';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (is_dir($dir)) {
        if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
        if (!is_file($dir . '/index.html')) { @file_put_contents($dir . '/index.html', ''); }
    }
    return $dir;
}

function p2b_gc()
{
    if (mt_rand(1, 10) !== 1) { return; }
    $dir = p2b_stage_dir();
    foreach ((array)@glob($dir . '/*') as $f) {
        if (is_file($f) && substr(basename($f), 0, 1) !== '.' && @filemtime($f) < time() - 43200) { @unlink($f); }
    }
    if (!empty($_SESSION['p2b']['batch']) && is_array($_SESSION['p2b']['batch'])) {
        foreach ($_SESSION['p2b']['batch'] as $bid => $files) {
            foreach ((array)$files as $fid => $rec) {
                if ((isset($rec['time']) ? $rec['time'] : 0) < time() - 43200) {
                    if (!empty($rec['path']) && is_file($rec['path'])) { @unlink($rec['path']); }
                    if (!empty($rec['cover_path']) && is_file($rec['cover_path'])) { @unlink($rec['cover_path']); }
                    unset($_SESSION['p2b']['batch'][$bid][$fid]);
                }
            }
        }
    }
}

function p2b_cover_from_binary($bin, $maxKB)
{
    if (!$bin || !function_exists('imagecreatefromstring')) { return null; }
    $im = @imagecreatefromstring($bin);
    if (!$im) { return null; }
    $w = imagesx($im); $h = imagesy($im);
    if ($w < 1 || $h < 1) { imagedestroy($im); return null; }
    $tw = min(480, $w); $th = (int)round($h * ($tw / $w));
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($im);
    $maxBytes = max(10, (int)$maxKB) * 1024;
    $data = '';
    for ($q = 82; $q >= 35; $q -= 12) {
        ob_start(); imagejpeg($dst, null, $q); $data = ob_get_clean();
        if (strlen($data) <= $maxBytes) { break; }
    }
    while (strlen($data) > $maxBytes && $tw > 120) {
        $cw = max(1, (int)($tw * 0.75)); $ch = max(1, (int)($th * 0.75));
        $d2 = imagecreatetruecolor($cw, $ch);
        imagecopyresampled($d2, $dst, 0, 0, 0, 0, $cw, $ch, $tw, $th);
        imagedestroy($dst); $dst = $d2; $tw = $cw; $th = $ch;
        ob_start(); imagejpeg($dst, null, 72); $data = ob_get_clean();
    }
    imagedestroy($dst);
    if (!$data || strlen($data) > $maxBytes) { return null; }
    return array('base64'=>base64_encode($data), 'ext'=>'jpg', 'bytes'=>strlen($data));
}

function p2b_imagick_cover($path)
{
    if (!class_exists('Imagick') || !is_file($path)) { return null; }
    try {
        $im = new \Imagick();
        $im->setResolution(72, 72);
        $im->readImage($path . '[0]');
        $im->setImageFormat('jpeg');
        $blob = $im->getImageBlob();
        $im->clear();
        return $blob ? $blob : null;
    } catch (\Throwable $e) { return null; }
}

function p2b_download_cover($url)
{
    if (!$url || !preg_match('#^https?://#i', $url)) { return null; }
    $raw = null;
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>'PDF2Biblio/1.0'));
            $raw = curl_exec($ch); curl_close($ch);
        } else {
            $raw = @file_get_contents($url, false, stream_context_create(array('http'=>array('timeout'=>10))));
        }
    } catch (\Throwable $e) { return null; }
    return (is_string($raw) && $raw !== '') ? $raw : null;
}

function p2b_store_pdf($stagedPath, $title)
{
    if (!is_file($stagedPath)) { return null; }
    $name = 'p2b_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.pdf';
    $sub = 'p2b';
    $destDir = REPOBS . $sub;
    if (!is_dir($destDir)) { @mkdir($destDir, 0755, true); }
    $dest = $destDir . DS . $name;
    $ok = @rename($stagedPath, $dest);
    if (!$ok) { $ok = @copy($stagedPath, $dest); if ($ok) { @unlink($stagedPath); } }
    if (!$ok || !is_file($dest)) { return null; }
    return array('file_name'=>$name, 'file_dir'=>$sub, 'path'=>$dest);
}

function p2b_insert_file($stored, $title, $desc = '')
{
    global $dbs;
    require_once SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
    $sql_op = new simbio_dbop($dbs);
    $now = date('Y-m-d H:i:s');
    $data = array(
        'uploader_id'=>(int)$_SESSION['uid'],
        'file_title'=>$dbs->escape_string($title !== '' ? $title : 'Ebook PDF'),
        'file_name'=>$dbs->escape_string($stored['file_name']),
        'file_url'=>'',
        'file_dir'=>$dbs->escape_string($stored['file_dir']),
        'file_desc'=>$dbs->escape_string($desc),
        'mime_type'=>'application/pdf',
        'input_date'=>$now, 'last_update'=>$now,
    );
    if (@$sql_op->insert('files', $data)) { return (int)$sql_op->insert_id; }
    return 0;
}

function p2b_attach($biblioID, $fileID, $access = 'public')
{
    global $dbs;
    require_once SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
    $sql_op = new simbio_dbop($dbs);
    if ($biblioID > 0) {
        $q = $dbs->query('SELECT file_id FROM biblio_attachment WHERE biblio_id=' . (int)$biblioID . ' AND file_id=' . (int)$fileID);
        if ($q && $q->num_rows) { return true; }
        return (bool)@$sql_op->insert('biblio_attachment', array('biblio_id'=>(int)$biblioID, 'file_id'=>(int)$fileID, 'access_type'=>$access));
    }
    if (!isset($_SESSION['biblioAttach']) || !is_array($_SESSION['biblioAttach'])) { $_SESSION['biblioAttach'] = array(); }
    $_SESSION['biblioAttach'][$fileID] = array('file_id'=>$fileID, 'access_type'=>$access);
    return true;
}

function p2b_add_authors($biblioID, $authors)
{
    global $dbs;
    require_once SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
    $sql_op = new simbio_dbop($dbs);
    $added = array();
    foreach ((array)$authors as $a) {
        $name = is_array($a) ? (isset($a['name']) ? $a['name'] : '') : (string)$a;
        $level = is_array($a) && isset($a['level']) ? (int)$a['level'] : 1;
        $name = trim(strip_tags($name));
        if ($name === '' || !class_exists('utility')) { continue; }
        $id = (int)utility::getID($dbs, 'mst_author', 'author_id', 'author_name', $name);
        if (!$id) { continue; }
        if ($biblioID > 0) {
            $q = $dbs->query('SELECT author_id FROM biblio_author WHERE biblio_id=' . (int)$biblioID . ' AND author_id=' . $id);
            if (!$q || !$q->num_rows) {
                if (@$sql_op->insert('biblio_author', array('biblio_id'=>(int)$biblioID, 'author_id'=>$id, 'level'=>$level))) { $added[] = $id; }
            }
        } else {
            if (!isset($_SESSION['biblioAuthor']) || !is_array($_SESSION['biblioAuthor'])) { $_SESSION['biblioAuthor'] = array(); }
            $_SESSION['biblioAuthor'][$id] = array($id, $level);
            $added[] = $id;
        }
    }
    return $added;
}

function p2b_add_topics($biblioID, $topics)
{
    global $dbs;
    require_once SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
    $sql_op = new simbio_dbop($dbs);
    $added = array();
    foreach ((array)$topics as $t) {
        $name = trim(strip_tags(is_array($t) ? (isset($t['name']) ? $t['name'] : '') : (string)$t));
        if ($name === '' || !class_exists('utility')) { continue; }
        $id = (int)utility::getID($dbs, 'mst_topic', 'topic_id', 'topic', $name);
        if (!$id) { continue; }
        if ($biblioID > 0) {
            $q = $dbs->query('SELECT topic_id FROM biblio_topic WHERE biblio_id=' . (int)$biblioID . ' AND topic_id=' . $id);
            if (!$q || !$q->num_rows) {
                if (@$sql_op->insert('biblio_topic', array('biblio_id'=>(int)$biblioID, 'topic_id'=>$id, 'level'=>1))) { $added[] = $id; }
            }
        } else {
            if (!isset($_SESSION['biblioTopic']) || !is_array($_SESSION['biblioTopic'])) { $_SESSION['biblioTopic'] = array(); }
            $_SESSION['biblioTopic'][$id] = array($id, 1);
            $added[] = $id;
        }
    }
    return $added;
}

function p2b_validate_upload($fileKey, $maxKB)
{
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) { return 'Tidak ada file terunggah / upload error.'; }
    if ($_FILES[$fileKey]['size'] > $maxKB * 1024) { return 'File melebihi batas upload server (' . round($maxKB / 1024) . ' MB).'; }
    if (!preg_match('/\.pdf$/i', $_FILES[$fileKey]['name'])) { return 'File harus berformat PDF.'; }
    $tmp = $_FILES[$fileKey]['tmp_name'];
    if (!is_uploaded_file($tmp)) { return 'File upload tidak valid.'; }
    $fh = @fopen($tmp, 'rb'); $magic = $fh ? fread($fh, 1024) : ''; if ($fh) { fclose($fh); }
    if (strpos($magic, '%PDF-') === false) { return 'Bukan file PDF yang valid.'; }
    return '';
}

function p2b_first_row($table, $col)
{
    global $dbs;
    try {
        $r = $dbs->query("SELECT `$col` FROM `$table` ORDER BY `$col` ASC LIMIT 1");
        if ($r && ($row = $r->fetch_row())) { return (string)$row[0]; }
    } catch (\Throwable $e) {}
    return '';
}

// ============================ ROUTING ============================
$action = isset($_REQUEST['p2b_action']) ? (string)$_REQUEST['p2b_action'] : '';

if ($action !== '') {
    p2b_trace('ACTION ' . $action);
    try {
        p2b_auth();
        p2b_trace('auth ok');
        p2b_gc();
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);
        if (!isset($_SESSION['p2b']) || !is_array($_SESSION['p2b'])) { $_SESSION['p2b'] = array('tokens'=>array(), 'batch'=>array(), 'work'=>array()); }
        $maxKB = isset($sysconf['max_upload']) ? (int)$sysconf['max_upload'] : 20480;
        $maxImgKB = isset($sysconf['max_image_upload']) ? (int)$sysconf['max_image_upload'] : 500;
        $token = isset($_POST['token']) ? preg_replace('/[^a-f0-9]/', '', (string)$_POST['token']) : '';

        if ($action === 'status') {
            p2b_json(array('status'=>'ok', 'env'=>array(
                'smalot'=>class_exists('\Smalot\Pdfparser\Parser'),
                'imagick'=>class_exists('Imagick'),
                'gd'=>function_exists('imagecreatefromstring'),
                'max_upload_mb'=>round($maxKB / 1024, 1),
                'repo_writable'=>is_writable(REPOBS),
            )));
        }

        if ($token === '' || !isset($_SESSION['p2b']['tokens'][$token])) {
            p2b_json(array('status'=>'error','message'=>'Token tidak valid. Muat ulang halaman/form lalu coba lagi.'), 400);
        }
        p2b_trace('token ok');

        if ($action === 'extract' || $action === 'bulk_extract') {
            $err = p2b_validate_upload('pdf', $maxKB);
            if ($err !== '') { p2b_json(array('status'=>'error','message'=>$err), 400); }
            $stage = p2b_stage_dir();
            $forceNative = is_file($stage . DS . 'fatal_' . $token . '.flag');
            if ($action === 'extract') {
                $staged = $stage . DS . $token . '.pdf';
            } else {
                $fid = substr(md5(uniqid('', true)), 0, 10);
                $staged = $stage . DS . 'b_' . $token . '_' . $fid . '.pdf';
            }
            if (!@move_uploaded_file($_FILES['pdf']['tmp_name'], $staged)) { p2b_json(array('status'=>'error','message'=>'Gagal menyimpan file sementara.'), 500); }
            if (filesize($staged) > 8388608) { $forceNative = true; } // PDF >8MB: parser native (hemat memori)
            p2b_trace('upload ok bytes=' . filesize($staged) . ' forceNative=' . var_export($forceNative, true));
            @set_time_limit(300);
            try {
                $ex = P2B_PdfExtractor::extract($staged, 90000, $forceNative);
            } catch (\Throwable $e) {
                @unlink($staged);
                p2b_trace('EXTRACT THROW ' . $e->getMessage());
                p2b_json(array('status'=>'error','message'=>'Ekstraksi gagal: ' . $e->getMessage()), 500);
            }
            p2b_trace('extract done engine=' . $ex['engine'] . ' pages=' . $ex['pages']);
            try {
                $map = P2B_BiblioMapper::map($ex, $dbs, array('enrich'=>!empty($_POST['enrich']), 'filename'=>$_FILES['pdf']['name']));
            } catch (\Throwable $e) {
                @unlink($staged);
                p2b_trace('MAP THROW ' . $e->getMessage());
                p2b_json(array('status'=>'error','message'=>'Pemetaan gagal: ' . $e->getMessage()), 500);
            }
            p2b_trace('map done title=' . $map['fields']['title'] . ' | isbn=' . $map['isbn'] . ' | pub=' . $map['fields']['publisherID'] . ' | place=' . $map['fields']['placeID'] . ' | year=' . $map['fields']['year']);
            @unlink($stage . DS . 'fatal_' . $token . '.flag');

            $cover = null; $coverBin = null;
            if (!empty($_POST['cover'])) {
                $coverBin = $ex['cover'];
                if (!$coverBin) { $coverBin = p2b_imagick_cover($staged); }
                if (!$coverBin && !empty($map['cover_url'])) { $coverBin = p2b_download_cover($map['cover_url']); }
                if ($coverBin) {
                    $cover = p2b_cover_from_binary($coverBin, $maxImgKB);
                    if (!$cover) { $map['warnings'][] = 'Cover ditemukan tetapi gagal diproses / terlalu besar.'; }
                }
            }
            p2b_trace('cover done');

            if ($action === 'bulk_extract') {
                $coverPath = '';
                if ($cover) {
                    $coverPath = $stage . DS . 'b_' . $token . '_' . $fid . '.jpg';
                    @file_put_contents($coverPath, base64_decode($cover['base64']));
                }
                $_SESSION['p2b']['batch'][$token][$fid] = array(
                    'path'=>$staged, 'cover_path'=>$coverPath, 'fields'=>$map['fields'],
                    'authors'=>$map['authors'], 'topics'=>$map['topics'], 'time'=>time(),
                );
                p2b_trace('json out bulk_extract');
                p2b_json(array('status'=>'ok', 'fid'=>$fid, 'fields'=>$map['fields'],
                    'authors'=>array_map(function ($a) { return $a['name']; }, $map['authors']),
                    'topics'=>$map['topics'], 'pages'=>$map['pages'], 'warnings'=>$map['warnings'],
                    'cover_preview'=>$cover ? ('data:image/jpeg;base64,' . $cover['base64']) : ''));
            }

            $biblioID = isset($_POST['biblioID']) ? (int)$_POST['biblioID'] : 0;
            $authorIds = p2b_add_authors($biblioID, $map['authors']);
            $topicIds = p2b_add_topics($biblioID, $map['topics']);
            p2b_trace('authors+topics done');
            $attached = false; $fileID = 0;
            if (!empty($_POST['attach'])) {
                $stored = p2b_store_pdf($staged, $map['fields']['title']);
                if ($stored) {
                    $fileID = p2b_insert_file($stored, $map['fields']['title'], 'Diunggah otomatis oleh PDF2Biblio');
                    if ($fileID) { p2b_attach($biblioID, $fileID); $attached = true; }
                }
                if (!$attached) { $map['warnings'][] = 'Lampiran PDF gagal disimpan (periksa izin tulis folder repository).'; }
            }
            p2b_trace('attach done attached=' . var_export($attached, true));
            $_SESSION['p2b']['work'][$token] = array('biblioID'=>$biblioID, 'authors'=>$authorIds, 'topics'=>$topicIds, 'fileID'=>$fileID, 'time'=>time());
            p2b_trace('json out extract');
            p2b_json(array('status'=>'ok', 'fields'=>$map['fields'],
                'authors'=>array_map(function ($a) { return $a['name']; }, $map['authors']),
                'topics'=>$map['topics'], 'confidence'=>$map['confidence'], 'warnings'=>$map['warnings'],
                'attached'=>$attached, 'cover'=>$cover, 'engine'=>$ex['engine'], 'pages'=>$map['pages']));
        }

        if ($action === 'reset') {
            $w = isset($_SESSION['p2b']['work'][$token]) ? $_SESSION['p2b']['work'][$token] : null;
            if ($w) {
                require_once SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
                $sql_op = new simbio_dbop($dbs);
                if ((int)$w['biblioID'] > 0) {
                    foreach ((array)$w['authors'] as $id) { @$sql_op->delete('biblio_author', 'biblio_id=' . (int)$w['biblioID'] . ' AND author_id=' . (int)$id); }
                    foreach ((array)$w['topics'] as $id) { @$sql_op->delete('biblio_topic', 'biblio_id=' . (int)$w['biblioID'] . ' AND topic_id=' . (int)$id); }
                    if (!empty($w['fileID'])) { @$sql_op->delete('biblio_attachment', 'biblio_id=' . (int)$w['biblioID'] . ' AND file_id=' . (int)$w['fileID']); }
                } else {
                    foreach ((array)$w['authors'] as $id) { unset($_SESSION['biblioAuthor'][$id]); }
                    foreach ((array)$w['topics'] as $id) { unset($_SESSION['biblioTopic'][$id]); }
                    if (!empty($w['fileID'])) { unset($_SESSION['biblioAttach'][$w['fileID']]); }
                }
                if (!empty($w['fileID'])) {
                    $fid = (int)$w['fileID'];
                    $cnt = $dbs->query('SELECT COUNT(*) AS c FROM biblio_attachment WHERE file_id=' . $fid);
                    $row = $cnt ? $cnt->fetch_assoc() : null;
                    if (!$row || (int)$row['c'] === 0) {
                        $fq = $dbs->query('SELECT file_name, file_dir FROM files WHERE file_id=' . $fid);
                        $fr = $fq ? $fq->fetch_assoc() : null;
                        if ($fr) {
                            $path = REPOBS . ($fr['file_dir'] ? $fr['file_dir'] . DS : '') . $fr['file_name'];
                            if (is_file($path)) { @unlink($path); }
                            @$sql_op->delete('files', 'file_id=' . $fid);
                        }
                    }
                }
                unset($_SESSION['p2b']['work'][$token]);
            }
            p2b_json(array('status'=>'ok','message'=>'Isian plugin dibatalkan.'));
        }

        if ($action === 'bulk_commit') {
            $fid = isset($_POST['fid']) ? preg_replace('/[^a-f0-9]/', '', (string)$_POST['fid']) : '';
            $rec = isset($_SESSION['p2b']['batch'][$token][$fid]) ? $_SESSION['p2b']['batch'][$token][$fid] : null;
            if (!$rec) { p2b_json(array('status'=>'error','message'=>'Data antrean tidak ditemukan / kedaluwarsa.'), 400); }
            $f = $rec['fields'];
            $ov = json_decode(isset($_POST['fields']) ? (string)$_POST['fields'] : '{}', true);
            if (is_array($ov)) {
                foreach ($ov as $k => $v) { if (is_string($v) && array_key_exists($k, $f)) { $f[$k] = trim(strip_tags($v)); } }
            }
            $authors = $rec['authors'];
            if (!empty($_POST['authors']) && is_string($_POST['authors'])) {
                $authors = array();
                foreach (P2B_BiblioMapper::splitNames($_POST['authors']) as $i => $nm) { $authors[] = array('name'=>$nm, 'level'=>$i === 0 ? 1 : 2); }
            }
            $topics = $rec['topics'];
            if (!empty($_POST['topics']) && is_string($_POST['topics'])) {
                $topics = array_map('trim', preg_split('/[;,]/', $_POST['topics']));
                $topics = array_values(array_filter($topics, function ($x) { return $x !== ''; }));
            }
            $title = trim($f['title']);
            if ($title === '') { p2b_json(array('status'=>'error','message'=>'Judul kosong - lewati / isi manual.'), 400); }

            require_once SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
            $sql_op = new simbio_dbop($dbs);
            $now = date('Y-m-d H:i:s');
            $gmd = (int)$f['gmdID']; if (!$gmd) { $gmd = (int)p2b_first_row('mst_gmd', 'gmd_id'); }
            $lang = $f['languageID']; if ($lang === '') { $lang = p2b_first_row('mst_language', 'language_id'); }
            $data = array(
                'title'=>$dbs->escape_string($title),
                'sor'=>'',
                'edition'=>$dbs->escape_string($f['edition']),
                'isbn_issn'=>$dbs->escape_string($f['isbn_issn']),
                'classification'=>$dbs->escape_string($f['classification']),
                'gmd_id'=>$gmd,
                'publish_year'=>$dbs->escape_string($f['year']),
                'collation'=>$dbs->escape_string($f['collation']),
                'series_title'=>$dbs->escape_string($f['seriesTitle']),
                'call_number'=>$dbs->escape_string($f['callNumber']),
                'language_id'=>$dbs->escape_string($lang),
                'notes'=>$dbs->escape_string($f['notes']),
                'opac_hide'=>'literal{0}', 'promoted'=>'literal{0}', 'labels'=>'literal{NULL}',
                'frequency_id'=>'literal{0}', 'spec_detail_info'=>'',
                'uid'=>(int)$_SESSION['uid'], 'input_date'=>$now, 'last_update'=>$now,
            );
            if (!empty($f['publisherID']) && class_exists('utility')) { $data['publisher_id'] = (int)utility::getID($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $f['publisherID']); }
            if (!empty($f['placeID']) && class_exists('utility')) { $data['publish_place_id'] = (int)utility::getID($dbs, 'mst_place', 'place_id', 'place_name', $f['placeID']); }
            if (!empty($f['contentTypeID'])) { $data['content_type_id'] = (int)$f['contentTypeID']; }
            if (!empty($f['mediaTypeID'])) { $data['media_type_id'] = (int)$f['mediaTypeID']; }
            if (!empty($f['carrierTypeID'])) { $data['carrier_type_id'] = (int)$f['carrierTypeID']; }

            if (!@$sql_op->insert('biblio', $data)) {
                p2b_json(array('status'=>'error','message'=>'Gagal menyimpan bibliografi: ' . $sql_op->error), 500);
            }
            $bid = (int)$sql_op->insert_id;
            p2b_add_authors($bid, $authors);
            p2b_add_topics($bid, $topics);

            if (!empty($_POST['attach']) && is_file($rec['path'])) {
                $stored = p2b_store_pdf($rec['path'], $title);
                if ($stored) { $fileID = p2b_insert_file($stored, $title, 'Diunggah otomatis oleh PDF2Biblio'); if ($fileID) { p2b_attach($bid, $fileID); } }
            } elseif (is_file($rec['path'])) { @unlink($rec['path']); }

            if (!empty($_POST['cover']) && !empty($rec['cover_path']) && is_file($rec['cover_path'])) {
                $bytes = @file_get_contents($rec['cover_path']);
                if ($bytes) {
                    $imgName = 'cover_p2b_' . substr(md5($title . microtime()), 0, 12) . '.jpg';
                    if (!is_dir(IMGBS . 'docs')) { @mkdir(IMGBS . 'docs', 0755, true); }
                    if (@file_put_contents(IMGBS . 'docs' . DS . $imgName, $bytes)) {
                        @$sql_op->update('biblio', array('image'=>$dbs->escape_string($imgName)), 'biblio_id=' . $bid);
                    }
                }
                @unlink($rec['cover_path']);
            }
            try {
                require_once MDLBS . 'system/biblio_indexer.inc.php';
                $idx = new biblio_indexer($dbs);
                $idx->makeIndex($bid);
                if (method_exists($idx, 'makeIndexWord')) { $idx->makeIndexWord($bid); }
            } catch (\Throwable $e) {}
            if (function_exists('writeLog')) { writeLog('staff', $_SESSION['uid'], 'bibliography', $_SESSION['realname'] . ' impor bibliografi dari PDF (PDF2Biblio) biblio_id=' . $bid); }
            unset($_SESSION['p2b']['batch'][$token][$fid]);
            p2b_trace('json out bulk_commit id=' . $bid);
            p2b_json(array('status'=>'ok','biblio_id'=>$bid,'title'=>$title));
        }

        p2b_json(array('status'=>'error','message'=>'Aksi tidak dikenal.'), 400);
    } catch (\Throwable $e) {
        p2b_trace('THROW ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        p2b_json(array('status'=>'error','message'=>'Throwable: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()), 500);
    }
    exit;
}

// ============================ UI (manager) ============================
if (!class_exists('utility') || !utility::havePrivilege('bibliography', 'w')) {
    echo '<div class="errorBox">Anda tidak berhak mengakses halaman ini.</div>';
    return;
}
$batchToken = function_exists('p2b_new_token') ? p2b_new_token() : '';
$ajaxUrl = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
$env = array(
    'smalot'=>class_exists('\Smalot\Pdfparser\Parser'),
    'vendor_file'=>!empty($GLOBALS['p2b_vendor_file']),
    'autoload'=>!empty($GLOBALS['p2b_autoload_loaded']),
    'imagick'=>class_exists('Imagick'),
    'gd'=>function_exists('imagecreatefromstring'),
    'maxmb'=>round((isset($sysconf['max_upload']) ? (int)$sysconf['max_upload'] : 20480) / 1024, 1),
    'repo'=>is_writable(REPOBS),
    'php'=>PHP_VERSION,
    'memory'=>ini_get('memory_limit'),
);
$cfg = array('ajax'=>$ajaxUrl, 'token'=>$batchToken, 'maxKB'=>isset($sysconf['max_upload']) ? (int)$sysconf['max_upload'] : 20480);
?>
<div class="menuBox"><div class="menuBoxInner biblioIcon"><div class="per_title"><h2>PDF2Biblio &mdash; Impor Massal dari PDF</h2></div></div></div>
<style>
.p2b-wrap{padding:10px}.p2b-cards{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.p2b-card{border:1px solid #d5dbe3;border-radius:6px;padding:6px 12px;background:#f7f9fc;font-size:12px}
.p2b-ok{color:#1e7e34}.p2b-no{color:#c0392b}
#p2bBulkDrop{border:2px dashed #9fb3cd;border-radius:8px;padding:26px;text-align:center;background:#fff;color:#41546e;margin-bottom:12px}
#p2bBulkDrop.over{border-color:#2c7be5;background:#eef5ff}
.p2b-tbl{width:100%;border-collapse:collapse;font-size:12px}.p2b-tbl th,.p2b-tbl td{border:1px solid #dde3ea;padding:5px 6px;vertical-align:top}
.p2b-tbl input{width:100%;box-sizing:border-box;font-size:12px;padding:2px 4px}
.p2b-thumb{width:40px;height:56px;object-fit:cover;border-radius:3px}
.p2b-log{margin-top:10px;font-size:12px;max-height:180px;overflow:auto;background:#0f172a;color:#d1e3ff;padding:8px;border-radius:6px}
</style>
<div class="p2b-wrap">
  <div class="p2b-cards">
    <span class="p2b-card">Parser utama (smalot): <b class="<?php echo $env['smalot']?'p2b-ok':'p2b-no'; ?>"><?php echo $env['smalot']?'AKTIF':'fallback native'; ?></b></span>
    <span class="p2b-card">vendor/autoload.php: <b class="<?php echo $env['vendor_file']?'p2b-ok':'p2b-no'; ?>"><?php echo $env['vendor_file']?'ada':'TIDAK ADA'; ?></b></span>
    <span class="p2b-card">Autoload ter-load: <b class="<?php echo $env['autoload']?'p2b-ok':'p2b-no'; ?>"><?php echo $env['autoload']?'ya':'tidak'; ?></b></span>
    <span class="p2b-card">Imagick: <b class="<?php echo $env['imagick']?'p2b-ok':'p2b-no'; ?>"><?php echo $env['imagick']?'ada':'tidak ada'; ?></b></span>
    <span class="p2b-card">GD: <b class="<?php echo $env['gd']?'p2b-ok':'p2b-no'; ?>"><?php echo $env['gd']?'ada':'tidak ada'; ?></b></span>
    <span class="p2b-card">Batas upload: <b><?php echo $env['maxmb']; ?> MB</b></span>
    <span class="p2b-card">PHP: <b><?php echo htmlspecialchars($env['php']); ?></b> | memory_limit: <b><?php echo htmlspecialchars($env['memory']); ?></b></span>
    <span class="p2b-card">Repository writable: <b class="<?php echo $env['repo']?'p2b-ok':'p2b-no'; ?>"><?php echo $env['repo']?'ya':'TIDAK'; ?></b></span>
  </div>
  <div id="p2bBulkDrop">
    Tarik &amp; letakkan <b>banyak file PDF</b> sekaligus di sini, atau
    <label style="color:#2c7be5;text-decoration:underline;cursor:pointer">pilih file<input type="file" id="p2bBulkFiles" accept="application/pdf,.pdf" multiple style="display:none"></label>
    <div style="margin-top:8px">
      <label><input type="checkbox" id="bEnrich" checked> Perkaya (OpenLibrary)</label> &nbsp;
      <label><input type="checkbox" id="bAttach" checked> Lampirkan PDF</label> &nbsp;
      <label><input type="checkbox" id="bCover" checked> Ambil cover</label>
    </div>
  </div>
  <table class="p2b-tbl" id="p2bBulkTbl">
    <thead><tr><th>#</th><th>Cover</th><th>Judul</th><th>Pengarang</th><th>Tahun</th><th>ISBN</th><th>Penerbit</th><th>Tempat</th><th>Hlm</th><th>Status</th><th></th></tr></thead>
    <tbody></tbody>
  </table>
  <div style="margin-top:10px">
    <button type="button" class="btn btn-primary" id="p2bCommitAll">Simpan Semua ke Bibliografi</button>
    <button type="button" class="btn btn-default" id="p2bClear">Bersihkan Antrean</button>
    <span id="p2bBulkStatus" style="margin-left:10px;font-size:13px"></span>
  </div>
  <div class="p2b-log" id="p2bLog"></div>
</div>
<script>
var P2BB = <?php echo json_encode($cfg); ?>;
(function ($) {
    var rows = [];
    function log(m) { $('#p2bLog').append($('<div>').text('[' + new Date().toLocaleTimeString() + '] ' + m)).scrollTop(1e9); }
    function esc(s) { return $('<i>').text(s == null ? '' : s).html(); }
    function srvMsg(x, fallback) { return (x && x.responseJSON && x.responseJSON.message) ? x.responseJSON.message : fallback; }
    function render() {
        var $tb = $('#p2bBulkTbl tbody').empty();
        rows.forEach(function (r, i) {
            var f = r.fields || {};
            var tr = $('<tr>');
            tr.append($('<td>').text(i + 1));
            tr.append($('<td>').html(r.cover ? '<img class="p2b-thumb" src="' + r.cover + '">' : '&mdash;'));
            ['title', 'authorsStr', 'year', 'isbn_issn', 'publisherID', 'placeID'].forEach(function (k) {
                var val = k === 'authorsStr' ? (r.authors || []).join('; ') : (f[k] || '');
                tr.append($('<td>').html('<input data-k="' + k + '" value="' + esc(val) + '">'));
            });
            tr.append($('<td>').text(r.pages || ''));
            tr.append($('<td class="st">').html(r.status || 'siap'));
            tr.append($('<td>').html('<button type="button" class="btn btn-danger btn-sm p2b-del">x</button>'));
            tr.data('idx', i);
            $tb.append(tr);
        });
    }
    $('#p2bBulkTbl').on('click', '.p2b-del', function () {
        var i = $(this).closest('tr').data('idx'); rows.splice(i, 1); render();
    });
    $('#p2bBulkTbl').on('input', 'input', function () {
        var i = $(this).closest('tr').data('idx'), k = $(this).data('k'), v = $(this).val();
        if (k === 'authorsStr') { rows[i].authors = v.split(/\s*;\s*/).filter(Boolean); }
        else { rows[i].fields[k] = v; }
    });
    function uploadOne(file) {
        var dfd = $.Deferred();
        if (!/\.pdf$/i.test(file.name)) { log('Lewati (bukan PDF): ' + file.name); dfd.resolve(); return dfd; }
        var fd = new FormData();
        fd.append('p2b_action', 'bulk_extract'); fd.append('token', P2BB.token); fd.append('pdf', file);
        fd.append('enrich', $('#bEnrich').is(':checked') ? '1' : '0');
        fd.append('cover', $('#bCover').is(':checked') ? '1' : '0');
        fd.append('attach', '0');
        log('Ekstrak: ' + file.name);
        $.ajax({ url: P2BB.ajax, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
            .done(function (r) {
                if (r && r.status === 'ok') {
                    rows.push({ fid: r.fid, fields: r.fields, authors: r.authors, topics: r.topics, pages: r.pages, cover: r.cover_preview, status: 'siap', name: file.name });
                    render(); log('OK: ' + (r.fields.title || file.name) + (r.warnings && r.warnings.length ? ' | ' + r.warnings.join(' ') : ''));
                } else { log('GAGAL ' + file.name + ': ' + ((r && r.message) || '?')); }
                dfd.resolve();
            }).fail(function (x) { log('GAGAL ' + file.name + ' -> ' + srvMsg(x, 'HTTP ' + x.status)); dfd.resolve(); });
        return dfd;
    }
    function queue(files) {
        var chain = $.Deferred().resolve();
        $.each(files, function (i, f) { chain = chain.then(function () { return uploadOne(f); }); });
        chain.then(function () { $('#p2bBulkStatus').text(rows.length + ' file dalam antrean.'); });
    }
    $('#p2bBulkFiles').on('change', function () { queue(this.files); this.value = ''; });
    $('#p2bBulkDrop').on('dragover', function (e) { e.preventDefault(); $(this).addClass('over'); })
        .on('dragleave', function (e) { e.preventDefault(); $(this).removeClass('over'); })
        .on('drop', function (e) { e.preventDefault(); $(this).removeClass('over'); var dt = e.originalEvent.dataTransfer; if (dt && dt.files) { queue(dt.files); } });
    $('#p2bClear').on('click', function () { rows = []; render(); $('#p2bBulkStatus').text(''); });
    $('#p2bCommitAll').on('click', function () {
        if (!rows.length) { return; }
        var $btn = $(this).prop('disabled', true);
        var chain = $.Deferred().resolve();
        var done = 0, fail = 0;
        rows.slice().forEach(function (r) {
            chain = chain.then(function () {
                var dfd = $.Deferred();
                var fd = new FormData();
                fd.append('p2b_action', 'bulk_commit'); fd.append('token', P2BB.token); fd.append('fid', r.fid);
                fd.append('fields', JSON.stringify(r.fields));
                fd.append('authors', (r.authors || []).join('; '));
                fd.append('topics', (r.topics || []).join('; '));
                fd.append('attach', $('#bAttach').is(':checked') ? '1' : '0');
                fd.append('cover', $('#bCover').is(':checked') ? '1' : '0');
                $.ajax({ url: P2BB.ajax, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
                    .done(function (res) {
                        if (res && res.status === 'ok') { done++; log('TERSIMPAN biblio_id=' + res.biblio_id + ' : ' + res.title); r.status = 'tersimpan (id ' + res.biblio_id + ')'; }
                        else { fail++; log('GAGAL simpan: ' + ((res && res.message) || '?')); r.status = 'gagal'; }
                        render(); dfd.resolve();
                    }).fail(function (x) { fail++; log('GAGAL simpan -> ' + srvMsg(x, 'HTTP ' + x.status)); r.status = 'gagal'; render(); dfd.resolve(); });
                return dfd;
            });
        });
        chain.then(function () { $btn.prop('disabled', false); $('#p2bBulkStatus').text('Selesai: ' + done + ' tersimpan, ' + fail + ' gagal.'); });
    });
})(jQuery);
</script>