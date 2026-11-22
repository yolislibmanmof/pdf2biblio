<?php
/**
 * PDF2Biblio — pintu tunggal plugin_container.php.
 * Ada parameter "action" → router AJAX (Api). Tanpa → render UI.
 */
defined('SB') or die('Akses langsung ditolak.');

$GLOBALS['p2b_autoload_loaded'] = $GLOBALS['p2b_autoload_loaded'] ?? false;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    echo '<div style="padding:16px;font-family:sans-serif"><b>vendor/autoload.php hilang.</b> Jalankan <code>composer install</code> di folder plugin ini.</div>';
    return;
}
require_once $autoload;

foreach (['lib/Extractor.php', 'lib/Enricher.php', 'lib/Importer.php', 'lib/Api.php'] as $lf) {
    if (!is_file(__DIR__ . '/' . $lf)) {
        echo '<div style="padding:16px;font-family:sans-serif"><b>File hilang: ' . htmlspecialchars($lf) . '</b> — tempel ulang paket PDF2Biblio 2.4.</div>';
        return;
    }
    require_once __DIR__ . '/' . $lf;
}
$GLOBALS['p2b_autoload_loaded'] = true;

\PDF2Biblio\Api::boot();

if (isset($_REQUEST['action'])) {
    \PDF2Biblio\Api::handle(); // selalu exit
}

// ================= RENDER UI =================
$_SESSION['p2b_csrf'] = $_SESSION['p2b_csrf'] ?? bin2hex(random_bytes(16));
$csrf = $_SESSION['p2b_csrf'];

// Webroot stabil (SWB SLiMS = dirname script berjalan, tidak bisa dipercaya)
$script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$webroot = preg_replace('#/(admin|plugins)/.*$#', '/', $script);
if (!is_string($webroot) || $webroot === '') $webroot = '/';
$webroot = rtrim($webroot, '/') . '/';

$uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$q = $_GET;
unset($q['action'], $q['_'], $q['t'], $q['k']);
$q['ajaxload'] = '1';
$endpoint = $uri . '?' . http_build_query($q);
if (strpos($uri, 'plugin_container.php') === false) {
    $endpoint = $webroot . 'plugins/pdf2biblio/ajax.php';
}

$repoOk = is_writable(rtrim(SB, '/') . '/repository');
$imgOk = is_writable(rtrim(SB, '/') . '/images/docs');
$badge = fn($ok, $label) => '<span class="p2b-badge ' . ($ok ? 'ok' : 'bad') . '">' . $label . ': ' . ($ok ? 'ya' : 'tidak') . '</span>';
?>
<style>
.p2b-wrap{font-size:13px}
.p2b-badge{display:inline-block;padding:2px 10px;border-radius:12px;border:1px solid #ccc;margin:0 6px 6px 0;background:#fff}
.p2b-badge.ok{color:#1a7f37;border-color:#1a7f37}.p2b-badge.bad{color:#c62828;border-color:#c62828}
.p2b-drop{border:2px dashed #7aa7e9;border-radius:8px;padding:26px;text-align:center;color:#5b7db1;background:#f6faff;margin:8px 0;transition:.2s}
.p2b-drop.over{background:#e3f0ff;border-color:#1668dc}
.p2b-drop a{color:#1668dc;cursor:pointer;text-decoration:underline}
.p2b-opts{margin:10px 0;display:flex;gap:18px;flex-wrap:wrap;justify-content:center}
.p2b-table{width:100%;border-collapse:collapse;background:#fff;margin-top:8px}
.p2b-table th,.p2b-table td{border:1px solid #d9d9d9;padding:4px 6px;vertical-align:top}
.p2b-table th{background:#fafafa;font-weight:600;text-align:left}
.p2b-table input[type=text]{width:100%;box-sizing:border-box;padding:4px 6px;border:1px solid #bbb;border-radius:4px;font-size:12px}
.p2b-table textarea{width:100%;box-sizing:border-box;min-height:64px;font-size:12px;border:1px solid #bbb;border-radius:4px;padding:4px 6px}
.p2b-cov{width:42px;height:58px;object-fit:cover;border-radius:3px;background:#eee;display:block}
.p2b-btn{background:#1668dc;color:#fff;border:0;border-radius:6px;padding:9px 18px;font-weight:600;cursor:pointer;margin:10px 6px 0 0}
.p2b-btn.gray{background:#8a8a8a}.p2b-btn.red{background:#d32f2f;padding:4px 9px;margin:0}
.p2b-btn.mini{background:#eef9ee;color:#1a7f37;border:1px solid #1a7f37;padding:3px 8px;margin:0;font-weight:500}
.p2b-btn:disabled{opacity:.5;cursor:not-allowed}
.p2b-log{background:#0b1220;color:#9fd0ff;border-radius:8px;padding:10px 14px;margin-top:12px;max-height:220px;overflow:auto;font-family:Consolas,monospace;font-size:12px;line-height:1.7}
.p2b-log .err{color:#ff8a80}.p2b-log .warn{color:#ffd54f}.p2b-log .ok{color:#b9f6ca}
.p2b-st{font-size:11px;font-weight:700}.p2b-st.siap{color:#1a7f37}.p2b-st.proses{color:#1668dc}.p2b-st.gagal{color:#c62828}.p2b-st.dup{color:#b26a00}.p2b-st.simpan{color:#0b7a2e}
.p2b-bar{height:5px;background:#e0e0e0;border-radius:3px;margin-top:3px}.p2b-bar i{display:block;height:5px;background:#1a7f37;border-radius:3px}
.p2b-detail td{background:#fbfdff}
.p2b-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
.p2b-grid label{font-size:11px;color:#666;display:block}
.p2b-src{font-size:11px;color:#777;margin-top:6px}
.p2b-hidden{display:none}
.p2b-count{color:#666;margin-left:8px}
</style>

<div class="p2b-wrap">
    <div>
        <?= $badge($repoOk, 'Repository writable') ?>
        <?= $badge($imgOk, 'images/docs writable') ?>
        <?= $badge(function_exists('curl_init'), 'cURL') ?>
        <?= $badge(class_exists('\Smalot\PdfParser\Parser'), 'pdfparser') ?>
        <span class="p2b-badge">Upload maks: <?= htmlspecialchars(ini_get('upload_max_filesize')) ?></span>
    </div>

    <div class="p2b-drop" id="p2bDrop">
        Tarik &amp; letakkan <b>banyak file PDF</b> sekaligus di sini, atau <a id="p2bPick">pilih file</a>
        <input type="file" id="p2bFile" accept="application/pdf,.pdf" multiple class="p2b-hidden">
        <div class="p2b-opts">
            <label><input type="checkbox" id="p2bEnrich" checked> Perkaya (OpenLibrary + Google Books)</label>
            <label><input type="checkbox" id="p2bAttach" checked> Lampirkan PDF</label>
            <label><input type="checkbox" id="p2bCover" checked> Ambil cover</label>
            <label><input type="checkbox" id="p2bSkipDup" checked> Lewati duplikat</label>
        </div>
    </div>

    <table class="p2b-table" id="p2bTable">
        <thead><tr>
            <th>#</th><th>Cover</th><th style="min-width:170px">Judul</th><th style="min-width:140px">Pengarang</th>
            <th>Tahun</th><th>ISBN</th><th>Penerbit</th><th>Tempat</th><th>Hlm</th><th>%</th><th>Status</th><th></th>
        </tr></thead>
        <tbody id="p2bBody"></tbody>
    </table>

    <button class="p2b-btn" id="p2bSave">Simpan Semua ke Bibliografi</button>
    <button class="p2b-btn gray" id="p2bClear">Bersihkan Antrian</button>
    <span class="p2b-count" id="p2bCount">0 file dalam antrian.</span>

    <div class="p2b-log" id="p2bLog"></div>
</div>

<script>
(function(){
    const AJAX = <?= json_encode($endpoint) ?>, CSRF = <?= json_encode($csrf) ?>;
    const $ = id => document.getElementById(id);
    const body = $('p2bBody'), logEl = $('p2bLog');
    let rows = [], busy = 0;

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    function log(msg, cls){
        const t = new Date().toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
        const div = document.createElement('div');
        if (cls) div.className = cls;
        div.textContent = '[' + t + '] ' + msg;
        logEl.appendChild(div); logEl.scrollTop = logEl.scrollHeight;
    }
    function count(){ $('p2bCount').textContent = rows.length + ' file dalam antrian.'; $('p2bSave').disabled = busy > 0 || rows.length === 0; }

    const MAIN = ['title','author','year','isbn','publisher','place','pages'];
    const DETAIL = ['edition','series','language','classification','call_number','collation'];

    function render(row, idx){
        const tr = document.createElement('tr');
        tr.dataset.token = row.token;
        let cells = '<td>' + (idx+1) + '</td>';
        cells += '<td>' + (row.cover_url ? '<img class="p2b-cov" src="' + esc(row.cover_url) + '" loading="lazy">' : '<span class="p2b-cov"></span>') + '</td>';
        MAIN.forEach(k => { cells += '<td><input type="text" data-k="' + k + '" value="' + esc(row[k]) + '"></td>'; });
        cells += '<td><span>' + row.confidence + '%</span><div class="p2b-bar"><i style="width:' + row.confidence + '%"></i></div></td>';
        cells += '<td><span class="p2b-st siap" data-st>siap</span>' + (row.scanned ? '<br><small title="PDF scan — perlu OCR">⚠ scan</small>' : '') + '</td>';
        cells += '<td><button class="p2b-btn mini" data-det>Detail</button> <button class="p2b-btn red" data-del>x</button></td>';
        tr.innerHTML = cells;

        const dr = document.createElement('tr');
        dr.className = 'p2b-detail p2b-hidden';
        let g = '<div class="p2b-grid">';
        DETAIL.forEach(k => { g += '<div><label>' + k + '</label><input type="text" data-k="' + k + '" value="' + esc(row[k]) + '"></div>'; });
        g += '<div style="grid-column:span 2"><label>Subjek (pisahkan koma)</label><input type="text" data-k="subjects" value="' + esc((row.subjects||[]).join(', ')) + '"></div>';
        g += '<div style="grid-column:span 4"><label>Catatan / Deskripsi</label><textarea data-k="notes">' + esc(row.notes) + '</textarea></div></div>';
        const src = Object.entries(row.sources || {}).map(([k,v]) => k + ': ' + v).join(' • ');
        g += '<div class="p2b-src">Sumber → ' + esc(src || '-') + ' | PDF: ' + esc(row.pdf_name) + ' (' + row.pdf_pages + ' hlm)</div>';
        dr.innerHTML = '<td colspan="12">' + g + '</td>';

        const bind = inp => inp.addEventListener('input', () => {
            row[inp.dataset.k] = inp.dataset.k === 'subjects' ? inp.value.split(',').map(s=>s.trim()).filter(Boolean) : inp.value;
        });
        tr.querySelectorAll('input,textarea').forEach(bind);
        dr.querySelectorAll('input,textarea').forEach(bind);
        tr.querySelector('[data-det]').addEventListener('click', () => dr.classList.toggle('p2b-hidden'));
        tr.querySelector('[data-del]').addEventListener('click', async () => {
            if (!row.saved) {
                const fd = new URLSearchParams(); fd.append('action','clear_queue'); fd.append('t',CSRF); fd.append('tokens[]',row.token);
                await fetch(AJAX, {method:'POST', body:fd});
            }
            tr.remove(); dr.remove(); rows = rows.filter(r => r !== row); count();
            log('Dihapus dari antrian: ' + row.pdf_name);
        });
        body.appendChild(tr); body.appendChild(dr);
        row._tr = tr;
    }

    function setStatus(row, cls, html){
        const el = row._tr.querySelector('[data-st]');
        el.className = 'p2b-st ' + cls; el.innerHTML = html;
    }

    async function extractFile(file){
        const fd = new FormData();
        fd.append('action','bulk_extract'); fd.append('t',CSRF); fd.append('pdf',file);
        fd.append('enrich', $('p2bEnrich').checked ? 1 : 0);
        fd.append('cover', $('p2bCover').checked ? 1 : 0);
        const res = await fetch(AJAX, {method:'POST', body:fd});
        const j = await res.json();
        if (!j.ok) throw new Error(j.msg || 'gagal');
        return j.row;
    }

    async function handleFiles(files){
        const list = [...files].filter(f => /\.pdf$/i.test(f.name) || f.type === 'application/pdf');
        if (!list.length) return;
        let i = 0;
        busy += list.length; count();
        async function worker(){
            while (i < list.length){
                const f = list[i++];
                log('Memproses: ' + f.name);
                try {
                    const row = await extractFile(f);
                    (row.log || []).forEach(l => log(l, /gagal|tidak ditemukan/i.test(l) ? 'warn' : ''));
                    rows.push(row); render(row, rows.length - 1);
                } catch(e){ log('GAGAL ' + f.name + ': ' + e.message, 'err'); }
                busy--; count();
            }
        }
        await Promise.all([worker(), worker()]);
        log('Selesai memproses ' + list.length + ' file.', 'ok');
    }

    const drop = $('p2bDrop'), fileInput = $('p2bFile');
    $('p2bPick').addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => { handleFiles(fileInput.files); fileInput.value=''; });
    ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
    ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
    drop.addEventListener('drop', e => handleFiles(e.dataTransfer.files));

    $('p2bSave').addEventListener('click', async () => {
        const todo = rows.filter(r => !r.saved);
        if (!todo.length || busy > 0) return;
        $('p2bSave').disabled = true;
        todo.forEach(r => setStatus(r, 'proses', 'proses…'));
        log('Menyimpan ' + todo.length + ' bibliografi…');
        try {
            const payload = {action:'bulk_save', t:CSRF,
                opts:{attach: $('p2bAttach').checked ? 1 : 0, skip_dup: $('p2bSkipDup').checked ? 1 : 0},
                rows: todo.map(r => { const o = {}; ['token',...MAIN,...DETAIL,'subjects','notes'].forEach(k => o[k] = r[k]); return o; })};
            const res = await fetch(AJAX, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
            const j = await res.json();
            if (!j.ok) throw new Error(j.msg);
            j.results.forEach(out => {
                const row = todo.find(r => r.token === out.token);
                if (!row) return;
                if (out.ok && out.dup){ setStatus(row, 'dup', 'duplikat <a href="' + esc(out.opac || '#') + '" target="_blank">#' + out.id + '</a>'); log(row.title + ': ' + out.msg, 'warn'); }
                else if (out.ok){ row.saved = true; setStatus(row, 'simpan', 'tersimpan <a href="' + esc(out.opac) + '" target="_blank">#' + out.id + '</a>');
                    row._tr.querySelectorAll('input,textarea').forEach(x => x.disabled = true); log(row.title + ': ' + out.msg, 'ok'); }
                else { setStatus(row, 'gagal', 'gagal'); log(row.title + ': ' + out.msg, 'err'); }
            });
        } catch(e){ todo.forEach(r => setStatus(r,'gagal','gagal')); log('GAGAL menyimpan: ' + e.message, 'err'); }
        count();
    });

    $('p2bClear').addEventListener('click', async () => {
        const tokens = rows.filter(r => !r.saved).map(r => r.token);
        if (tokens.length) {
            const fd = new URLSearchParams(); fd.append('action','clear_queue'); fd.append('t',CSRF);
            tokens.forEach(t => fd.append('tokens[]', t));
            await fetch(AJAX, {method:'POST', body:fd});
        }
        rows = rows.filter(r => r.saved);
        body.innerHTML = ''; rows.forEach((r,i) => render(r,i));
        count(); log('Antrian dibersihkan.');
    });

    count();
    log('PDF2Biblio 2.4 siap. Pintu tunggal index.php (terbukti ada di disk). Ekstraksi: KDT + hak cipta + sampul + metadata + nama file.');
})();
</script>