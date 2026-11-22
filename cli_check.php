<?php
/**
 * PDF2Biblio — guard regresi CLI (Batch A).
 * Pakai: php cli_check.php [http://localhost/digilib]
 * Memeriksa: file inti, skema kolom yang dipakai Importer, nilai RDA, tmp writable + proteksi web.
 * Exit code 0 = semua hijau; 1 = ada gagal.
 */
if (PHP_SAPI !== 'cli') { die("CLI only\n"); }
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/plugins/pdf2biblio/cli_check.php';
define('INDEX_AUTH', 1);
require dirname(__DIR__, 2) . '/sysconfig.inc.php';

$root = __DIR__;
$fail = 0;
function ok(string $m): void { echo "[OK]   $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "[FAIL] $m\n"; }

// 1) File inti
foreach (['vendor/autoload.php','lib/Extractor.php','lib/Enricher.php','lib/Importer.php','lib/Api.php','index.php','ajax.php','pages/manager.php','pdf2biblio.plugin.php'] as $f) {
    is_file($root . '/' . $f) ? ok("file $f") : bad("file $f hilang");
}

// 2) Skema yang dipakai Importer (kolom nyata, bukan asumsi)
$dbs = @new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($dbs->connect_error) {
    bad('koneksi DB: ' . $dbs->connect_error);
} else {
    $dbs->set_charset('utf8mb4');
    $need = [
        'biblio' => ['biblio_id','title','edition','isbn_issn','publisher_id','publish_place_id','publish_year','collation','series_title','call_number','classification','language_id','notes','opac_hide','promoted','uid','input_date','last_update','image','gmd_id','content_type_id','media_type_id','carrier_type_id'],
        'biblio_author' => ['biblio_id','author_id','level'],
        'biblio_topic' => ['biblio_id','topic_id','level'],
        'biblio_attachment' => ['biblio_id','file_id','access_type','access_limit'],
        'files' => ['file_id','file_title','file_name','file_url','file_dir','mime_type','uploader_id'],
        'mst_publisher' => ['publisher_id','publisher_name'],
        'mst_place' => ['place_id','place_name'],
        'mst_author' => ['author_id','author_name','authority_type'],
        'mst_topic' => ['topic_id','topic','topic_type'],
        'mst_language' => ['language_id','language_name'],
        'mst_gmd' => ['gmd_id','gmd_name'],
        'mst_content_type' => ['id','content_type'],
        'mst_media_type' => ['id','media_type'],
        'mst_carrier_type' => ['id','carrier_type'],
    ];
    foreach ($need as $t => $cols) {
        $r = @$dbs->query("SHOW COLUMNS FROM `$t`");
        if (!$r) { bad("tabel $t tidak ada"); continue; }
        $have = [];
        while ($row = $r->fetch_assoc()) $have[] = $row['Field'];
        $miss = array_diff($cols, $have);
        $miss ? bad("tabel $t kurang kolom: " . implode(',', $miss)) : ok("skema $t");
    }
    foreach ([['mst_content_type','content_type','text'],['mst_media_type','media_type','computer'],['mst_media_type','media_type','unmediated'],['mst_carrier_type','carrier_type','volume'],['mst_carrier_type','carrier_type','online resource']] as $q) {
        $r = @$dbs->query("SELECT id FROM `$q[0]` WHERE `$q[1]`='" . $dbs->real_escape_string($q[2]) . "' LIMIT 1");
        ($r && $r->num_rows) ? ok("RDA $q[0]/$q[2]") : bad("RDA $q[0]/$q[2] tidak ada");
    }
}

// 3) tmp writable + proteksi
$tmp = $root . '/tmp';
if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
is_writable($tmp) ? ok('tmp writable') : bad('tmp tidak writable');
is_file($tmp . '/.htaccess') ? ok('tmp/.htaccess ada') : bad('tmp/.htaccess hilang — buka halaman plugin sekali (Api::boot menulisnya), lalu ulang');
is_file($tmp . '/index.html') ? ok('tmp/index.html ada') : bad('tmp/index.html hilang — buka halaman plugin sekali, lalu ulang');

// 4) Uji web tmp (opsional, butuh argumen URL basis SLiMS)
$url = $argv[1] ?? '';
if ($url !== '') {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $b = @file_get_contents(rtrim($url, '/') . '/plugins/pdf2biblio/tmp/', false, $ctx);
    $h = $http_response_header[0] ?? '';
    (strpos($h, '403') !== false || strpos($h, '404') !== false) ? ok("tmp web tertutup ($h)") : bad("tmp web TERBUKA: $h");
} else {
    echo "[INFO] uji proteksi web: php cli_check.php http://localhost/digilib\n";
}

echo $fail ? "\n$fail PEMERIKSAAN GAGAL\n" : "\nSEMUA PEMERIKSAAN HIJAU\n";
exit($fail ? 1 : 0);