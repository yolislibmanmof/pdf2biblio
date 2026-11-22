<?php
/**
 * Plugin Name: PDF2Biblio
 * Plugin URI: https://github.com/yolislibmanmof/pdf2biblio
 * Description: Ekstrak metadata PDF/ebook lalu isi otomatis semua field form bibliografi (judul, pengarang, penerbit, tempat, tahun, ISBN, edisi, seri, klasifikasi, subjek, bahasa, GMD, kolasi, abstrak, cover, lampiran PDF). Kompatibel SLiMS 9.x.
 * Version: 1.0.0
 * Author: yolislibmanmof
 * Author URI: https://github.com/yolislibmanmof
 */

use SLiMS\Plugins;

if (!defined('INDEX_AUTH')) { die('direct access not allowed'); }
if (!class_exists('SLiMS\Plugins')) { return; }

if (is_file(__DIR__ . '/vendor/autoload.php')) { require_once __DIR__ . '/vendor/autoload.php'; }
require_once __DIR__ . '/lib/PdfExtractor.php';
require_once __DIR__ . '/lib/BiblioMapper.php';
// --- diagnosa dini v1.0.3: trace + fatal-catcher khusus endpoint PDF2Biblio ---
if (!function_exists('p2b_trace')) {
    function p2b_trace($msg)
    {
        $dir = defined('UPLOAD') ? UPLOAD . 'p2b_stage' : sys_get_temp_dir();
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'p2b_trace.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
    }
}
$p2bIsOurs = isset($_REQUEST['p2b_action']) || (isset($_GET['id']) && $_GET['id'] === md5(realpath(__DIR__ . '/pages/manager.php')));
if ($p2bIsOurs) {
    p2b_trace('BOOT main ok PHP=' . PHP_VERSION . ' uri=' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '-'));
    set_error_handler(function ($no, $str, $file, $line) {
        if ($no & (E_WARNING | E_ERROR | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR)) {
            p2b_trace("PHP[$no] $str @ " . basename($file) . ":$line");
        }
        return false;
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
            p2b_trace('FATAL ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']);
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode(array('status'=>'error','message'=>'FATAL: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']));
        }
    });
}

if (!function_exists('p2b_menu_url')) {
    function p2b_menu_url()
    {
        $id = md5(realpath(__DIR__ . '/pages/manager.php'));
        $base = defined('AWB') ? AWB : '../admin/';
        return $base . 'plugin_container.php?mod=bibliography&id=' . $id;
    }
}

if (!function_exists('p2b_new_token')) {
    function p2b_new_token()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) { return ''; }
        if (!isset($_SESSION['p2b']) || !is_array($_SESSION['p2b'])) {
            $_SESSION['p2b'] = array('tokens' => array(), 'batch' => array(), 'work' => array());
        }
        try { $t = bin2hex(random_bytes(12)); } catch (\Throwable $e) { $t = md5(uniqid('', true)); }
        $_SESSION['p2b']['tokens'][$t] = time();
        foreach ($_SESSION['p2b']['tokens'] as $k => $v) {
            if (time() - $v > 43200) { unset($_SESSION['p2b']['tokens'][$k]); }
        }
        return $t;
    }
}

if (!function_exists('p2b_form_js')) {
    function p2b_form_js($cfg)
    {
        $js = 'var P2BCFG = ' . json_encode($cfg) . ';' . "\n";
        $js .= <<<'P2BJS'
(function () {
    if (window.__p2bLoaded) { return; }
    window.__p2bLoaded = true;
    var C = window.P2BCFG || {};
    function toast(type, msg) {
        try {
            var t = (window.top && window.top.toastr) || (window.parent && window.parent.toastr) || window.toastr;
            if (t && t[type]) { t[type](msg, 'PDF2Biblio'); }
        } catch (e) {}
    }
    function status(msg, cls) { $('#p2bStatus').attr('class', 'p2b-status ' + (cls || '')).html(msg); }
    function setVal(sel, v, ow) {
        var $el = $(sel);
        if (!$el.length || v === null || v === undefined || v === '') { return false; }
        if (!ow && $.trim($el.val() || '') !== '') { return false; }
        $el.val(v).addClass('p2b-filled');
        return true;
    }
    function selEmpty($el) {
        var v = $el.val();
        return !v || v === '0' || v === 'NONE' || $.trim($el.find('option:selected').text()) === '';
    }
    function setSelect(id, val, label, ow) {
        var $el = $('#' + id);
        if (!$el.length || !val) { return false; }
        if (!ow && !selEmpty($el)) { return false; }
        if ($el.find('option[value="' + val + '"]').length === 0) { $el.append(new Option(label || val, val)); }
        $el.val(val).trigger('change').addClass('p2b-filled');
        return true;
    }
    function refreshIframe(match) {
        var $f = $('iframe[src*="' + match + '"]');
        if ($f.length) {
            var s = ($f.attr('src') || '').split('&_p2b=')[0];
            $f.attr('src', s + '&_p2b=' + Date.now());
        }
    }
    var css = ''
        + '.p2b-panel{border:1px solid #d5dbe3;border-left:4px solid #2c7be5;border-radius:6px;padding:12px 14px;margin:0 0 14px;background:#f7f9fc;font-size:13px}'
        + '.p2b-head{font-size:14px;margin-bottom:8px}.p2b-sub{color:#6b7a90;font-weight:normal;margin-left:6px}'
        + '.p2b-drop{border:2px dashed #9fb3cd;border-radius:6px;padding:14px;text-align:center;color:#41546e;background:#fff}'
        + '.p2b-drop.p2b-over{border-color:#2c7be5;background:#eef5ff}'
        + '.p2b-pick{color:#2c7be5;cursor:pointer;text-decoration:underline}'
        + '.p2b-opts{margin-top:8px;display:flex;flex-wrap:wrap;gap:12px}.p2b-opts label{font-weight:normal;margin:0;cursor:pointer}'
        + '.p2b-status{margin-top:8px;min-height:18px}.p2b-status.err{color:#c0392b}.p2b-status.ok{color:#1e7e34}.p2b-status.run{color:#2c7be5}'
        + '.p2b-chips{margin-top:6px;display:flex;flex-wrap:wrap;gap:6px}'
        + '.p2b-chip{background:#e8f1ff;border:1px solid #bcd4f6;color:#1b4f9c;border-radius:12px;padding:2px 9px;font-size:12px}'
        + '.p2b-chip.low{background:#fff6e5;border-color:#f0d9a8;color:#8a6116}'
        + '.p2b-actions{margin-top:8px}'
        + '.p2b-filled{border-left:3px solid #28a745 !important;background:#f4fbf5 !important}';
    $('<style>').text(css).appendTo('head');

    var owDefault = C.editMode ? '' : ' checked';
    var html = ''
        + '<div id="p2bPanel" class="p2b-panel">'
        + '<div class="p2b-head"><b>&#128218; PDF2Biblio</b><span class="p2b-sub">Katalog otomatis dari PDF/ebook &mdash; unggah PDF, semua field terisi</span></div>'
        + '<div id="p2bDrop" class="p2b-drop">Tarik &amp; letakkan file PDF di sini, atau '
        + '<label class="p2b-pick">pilih file<input type="file" id="p2bFile" accept="application/pdf,.pdf" style="display:none"></label>'
        + '<div class="p2b-opts">'
        + '<label><input type="checkbox" id="p2bEnrich" checked> Perkaya data (OpenLibrary)</label>'
        + '<label><input type="checkbox" id="p2bAttach" checked> Lampirkan PDF</label>'
        + '<label><input type="checkbox" id="p2bCover" checked> Ambil cover</label>'
        + '<label><input type="checkbox" id="p2bOverwrite"' + owDefault + '> Timpa field yang sudah terisi</label>'
        + '</div></div>'
        + '<div id="p2bStatus" class="p2b-status"></div>'
        + '<div id="p2bChips" class="p2b-chips"></div>'
        + '<div class="p2b-actions"><button type="button" class="btn btn-default btn-sm" id="p2bReset">Batalkan isian plugin</button></div>'
        + '</div>';
    var $form = $('form').has('#title').first();
    if (!$form.length) { $form = $('#title').closest('form'); }
    if (!$form.length) { return; }
    $form.prepend(html);

    function apply(r) {
        var f = r.fields || {}, ow = $('#p2bOverwrite').is(':checked'), filled = [];
        if (setVal('#title', f.title, ow)) { filled.push('Judul'); }
        if (setVal('#edition', f.edition, ow)) { filled.push('Edisi'); }
        if (setVal('#isbn_issn', f.isbn_issn, ow)) { filled.push('ISBN/ISSN'); }
        if (setVal('#year', f.year, ow)) { filled.push('Tahun'); }
        if (setVal('#collation', f.collation, ow)) { filled.push('Kolasi'); }
        if (setVal('#seriesTitle', f.seriesTitle, ow)) { filled.push('Seri'); }
        if (setVal('#notes', f.notes, ow)) { filled.push('Abstrak'); }
        if (setSelect('publisherID', f.publisherID ? 'NEW:' + f.publisherID : '', f.publisherID, ow)) { filled.push('Penerbit'); }
        if (setSelect('placeID', f.placeID ? 'NEW:' + f.placeID : '', f.placeID, ow)) { filled.push('Tempat terbit'); }
        if (setSelect('class', f.classification ? 'NEW:' + f.classification : '', f.classification, ow)) { filled.push('Klasifikasi'); }
        if (setVal('#callNumber', f.callNumber, ow)) { filled.push('Nomor panggil'); }
        if (setSelect('gmdID', f.gmdID, null, ow)) { filled.push('GMD'); }
        if (setSelect('languageID', f.languageID, null, ow)) { filled.push('Bahasa'); }
        if (setSelect('contentTypeID', f.contentTypeID, null, ow)) { filled.push('RDA konten'); }
        if (setSelect('mediaTypeID', f.mediaTypeID, null, ow)) { filled.push('RDA media'); }
        if (setSelect('carrierTypeID', f.carrierTypeID, null, ow)) { filled.push('RDA carrier'); }
        if (r.cover && r.cover.base64) {
            var mime = r.cover.ext === 'png' ? 'image/png' : 'image/jpeg';
            $('#base64picstring').val(r.cover.base64 + '#image/type#' + (r.cover.ext || 'jpg'));
            $('#imageFilename img').attr('src', 'data:' + mime + ';base64,' + r.cover.base64);
            filled.push('Cover');
        }
        if ((r.authors && r.authors.length) || (r.topics && r.topics.length) || r.attached) {
            refreshIframe('iframe_author.php');
            refreshIframe('iframe_topic.php');
            refreshIframe('iframe_attach.php');
        }
        var keyMap = { 'Judul':'title','Edisi':'edition','ISBN/ISSN':'isbn_issn','Tahun':'year','Kolasi':'collation','Seri':'seriesTitle','Abstrak':'notes','Penerbit':'publisher','Tempat terbit':'place','Klasifikasi':'classification','Nomor panggil':'callNumber','Bahasa':'language' };
        var chips = '';
        $.each(filled, function (i, label) {
            var c = (r.confidence || {})[keyMap[label]] || 0;
            var pct = c ? ' ' + Math.round(c * 100) + '%' : '';
            chips += '<span class="p2b-chip' + (c && c < 0.6 ? ' low' : '') + '">' + label + pct + '</span>';
        });
        if (r.authors && r.authors.length) { chips += '<span class="p2b-chip">' + r.authors.length + ' pengarang</span>'; }
        if (r.topics && r.topics.length) { chips += '<span class="p2b-chip">' + r.topics.length + ' subjek</span>'; }
        if (r.attached) { chips += '<span class="p2b-chip">PDF terlampir</span>'; }
        $('#p2bChips').html(chips);
        var warn = (r.warnings || []).join(' ');
        status('Selesai &mdash; ' + filled.length + ' field terisi. Periksa kembali lalu klik Simpan. ' + warn, 'ok');
        toast('success', 'PDF2Biblio: ' + filled.length + ' field terisi otomatis.');
    }

    function extract(file) {
        if (!file) { return; }
        if (!/\.pdf$/i.test(file.name) && file.type !== 'application/pdf') { status('File harus berformat PDF.', 'err'); return; }
        if (C.maxKB && file.size > C.maxKB * 1024) { status('File melebihi batas upload server (&plusmn;' + Math.round(C.maxKB / 1024) + ' MB).', 'err'); return; }
        var fd = new FormData();
        fd.append('p2b_action', 'extract');
        fd.append('token', C.token || '');
        fd.append('pdf', file);
        fd.append('biblioID', $('[name=updateRecordID]').val() || '0');
        fd.append('enrich', $('#p2bEnrich').is(':checked') ? '1' : '0');
        fd.append('attach', $('#p2bAttach').is(':checked') ? '1' : '0');
        fd.append('cover', $('#p2bCover').is(':checked') ? '1' : '0');
        status('Mengunggah &amp; mengekstrak <b>' + $('<i>').text(file.name).html() + '</b> &hellip;', 'run');
        $.ajax({
            url: C.ajax, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            xhr: function () {
                var x = new XMLHttpRequest();
                x.upload.onprogress = function (e) {
                    if (e.lengthComputable) { status('Mengunggah ' + Math.round(e.loaded / e.total * 100) + '% &hellip;', 'run'); }
                };
                return x;
            }
        }).done(function (r) {
            if (!r || r.status !== 'ok') { status((r && r.message) || 'Gagal mengekstrak PDF.', 'err'); return; }
            apply(r);
        }).fail(function (x) {
            var m = 'Gagal menghubungi server.';
            if (x.status === 401 || x.status === 403) { m = 'Sesi berakhir / tidak berwenang. Muat ulang halaman lalu login kembali.'; }
            else if (x.responseJSON && x.responseJSON.message) { m = x.responseJSON.message; }
            else if (x.responseText && x.responseText.charAt(0) === '<') { m = 'Server mengembalikan halaman error HTML. Buka files/p2b_stage/p2b_error.log untuk penyebabnya.'; }
            status(m, 'err');
        });
    }

    $('#p2bFile').on('change', function () { if (this.files && this.files[0]) { extract(this.files[0]); } });
    $('#p2bDrop').on('dragover', function (e) { e.preventDefault(); e.stopPropagation(); $(this).addClass('p2b-over'); })
        .on('dragleave', function (e) { e.preventDefault(); e.stopPropagation(); $(this).removeClass('p2b-over'); })
        .on('drop', function (e) {
            e.preventDefault(); e.stopPropagation(); $(this).removeClass('p2b-over');
            var dt = e.originalEvent.dataTransfer;
            if (dt && dt.files && dt.files[0]) { extract(dt.files[0]); }
        });
    $('#p2bReset').on('click', function () {
        if (!confirm('Batalkan pengarang/subjek/lampiran/cover yang ditambahkan plugin? (teks field tidak dihapus)')) { return; }
        $.post(C.ajax, { p2b_action: 'reset', token: C.token || '' }, function (r) {
            $('.p2b-filled').removeClass('p2b-filled');
            $('#base64picstring').val('');
            refreshIframe('iframe_author.php'); refreshIframe('iframe_topic.php'); refreshIframe('iframe_attach.php');
            $('#p2bChips').empty();
            status((r && r.message) || 'Isian plugin dibatalkan.', 'ok');
        }, 'json').fail(function () { status('Gagal membatalkan isian plugin.', 'err'); });
    });
})();
P2BJS;
        return $js;
    }
}

$p2b = Plugins::getInstance();
$p2b->registerMenu('bibliography', 'PDF2Biblio', __DIR__ . '/pages/manager.php', 'Katalog otomatis dari PDF/ebook');
$p2b->register('advance_custom_field_form', function ($form = null, &$js = null, $data = array()) {
    if (!is_object($form)) { return; }
    if (!class_exists('utility') || !utility::havePrivilege('bibliography', 'w')) { return; }
    $token = p2b_new_token();
    $form->addHidden('p2b_token', $token);
    $sysconf = isset($GLOBALS['sysconf']) ? $GLOBALS['sysconf'] : array();
    $cfg = array(
        'ajax' => p2b_menu_url(),
        'token' => $token,
        'maxKB' => isset($sysconf['max_upload']) ? (int)$sysconf['max_upload'] : 20480,
        'editMode' => !empty($form->edit_mode),
    );
    $js .= "\n" . p2b_form_js($cfg);
});