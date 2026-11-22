<?php
/**
 * PDF2Biblio - pemetaan hasil ekstraksi ke field bibliografi SLiMS (v1.2.0).
 * Prioritas: metadata PDF > nama file terkurasi > heuristik teks.
 */
if (!defined('INDEX_AUTH')) { die('direct access not allowed'); }

class P2B_BiblioMapper
{
    private static $stopId = array('yang','dan','di','ke','dari','untuk','dengan','adalah','dalam','tidak','pada','ini','itu','atau','juga','akan','oleh','sebagai','dapat','kami','kita','anda','bahwa','karena','secara','telah','sudah','buku','penulis','penerbit','edisi','cetakan','hak','cipta','dilarang','memperbanyak','sebagian','seluruh','bentuk','apa','pun','tanpa','izin','tertulis');
    private static $stopEn = array('the','and','of','to','in','is','that','for','with','this','are','was','were','be','been','it','as','at','by','an','or','from','on','not','have','has','had','but','which','their','its','can','will','would','there','these','those','such','into','about','also','more','most','other','some','any','all','may','should','could','book','author','publisher','edition','copyright','rights','reserved','printed','press','first','published','print');
    private static $months = 'januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember|january|february|march|april|may|june|july|august|september|october|november|december';

    public static function map($ex, $dbs, $opts = array())
    {
        $text = isset($ex['text']) ? $ex['text'] : '';
        $meta = isset($ex['meta']) ? $ex['meta'] : array();
        $pages = isset($ex['pages']) ? (int)$ex['pages'] : 0;
        $lines = self::lines($text);
        $head = implode("\n", array_slice($lines, 0, 400)); // cukup untuk halaman hak cipta
        $body = implode("\n", array_slice($lines, 60));
        $f = array('title'=>'','edition'=>'','isbn_issn'=>'','year'=>'','collation'=>'','seriesTitle'=>'','callNumber'=>'','notes'=>'','publisherID'=>'','placeID'=>'','classification'=>'','gmdID'=>'','languageID'=>'','contentTypeID'=>'','mediaTypeID'=>'','carrierTypeID'=>'');
        $conf = array(); $authors = array(); $topics = array(); $warnings = array(); $coverUrl = '';

        // ---- Nama file terkurasi: "T 338 - Judul Buku.pdf" / "(+ISBN) T 338 - Judul.pdf"
        $fnTitle = ''; $fnClass = '';
        $fname = isset($opts['filename']) ? basename((string)$opts['filename']) : '';
        if ($fname !== '') {
            $base = preg_replace('/\.pdf$/i', '', $fname);
            $base = preg_replace('/^\s*\(\s*\+?\s*ISBN\s*\)\s*/i', '', $base);
            $base = preg_replace('/\s*\(\d+\)\s*$/', '', $base);
            $base = trim($base);
            if (preg_match('/^([A-Za-z]{1,4}\s*\d{3}(?:\.\d+)?)\s*[-–—]\s*(.+)$/u', $base, $m)) {
                $fnClass = trim($m[1]); $fnTitle = trim($m[2]);
            }
        }

        $lang = self::detectLang($text !== '' ? $text : $head);
        if (!empty($meta['lang'])) {
            if (stripos($meta['lang'], 'id') === 0 || stripos($meta['lang'], 'ind') !== false) { $lang = 'ind'; }
            elseif (stripos($meta['lang'], 'en') === 0) { $lang = 'eng'; }
        }

        // ---- Judul: meta > nama file > teks
        $mt = isset($meta['title']) ? trim($meta['title']) : '';
        if ($mt !== '' && strlen($mt) >= 4 && !preg_match('/microsoft word|\.docx?|\.indd|\.tex|untitled|layout \d|print\.pdf|^slide/i', $mt)) {
            $f['title'] = self::limit($mt, 250); $conf['title'] = 0.95;
        } elseif ($fnTitle !== '' && strlen($fnTitle) >= 4) {
            $f['title'] = self::limit($fnTitle, 250); $conf['title'] = 0.9;
        } else {
            $t = self::guessTitle($lines);
            if ($t !== '') { $f['title'] = self::limit($t, 250); $conf['title'] = 0.6; }
        }

        // ---- Pengarang
        if (!empty($meta['author'])) {
            foreach (self::splitNames($meta['author']) as $nm) { $authors[] = array('name'=>$nm,'level'=>1); }
            if ($authors) { $conf['authors'] = 0.9; }
        }
        if (!$authors) { $authors = self::guessAuthors($lines); if ($authors) { $conf['authors'] = 0.7; } }
        foreach ($authors as $i => $a) { if ($i > 0 && $a['level'] == 1) { $authors[$i]['level'] = 2; } }

        // ---- ISBN / ISSN
        $isbn = self::findIsbn($head . "\n" . substr($text, 0, 20000));
        if ($isbn !== '') { $f['isbn_issn'] = $isbn; $conf['isbn_issn'] = 0.95; }
        else { $issn = self::findIssn($head); if ($issn !== '') { $f['isbn_issn'] = $issn; $conf['isbn_issn'] = 0.85; } }

        // ---- Tahun / penerbit / tempat
        $py = self::guessYear($head, $meta);
        if ($py !== '') { $f['year'] = $py; $conf['year'] = 0.75; }
        $pub = self::guessPublisherPlace($head);
        if ($pub['publisher'] !== '') { $f['publisherID'] = $pub['publisher']; $conf['publisher'] = $pub['conf']; }
        if ($pub['place'] !== '') { $f['placeID'] = $pub['place']; $conf['place'] = 0.65; }

        $ed = self::guessEdition($head);
        if ($ed !== '') { $f['edition'] = $ed; $conf['edition'] = 0.8; }
        if (preg_match('/^(?:seri|series|buku seri)\s*[:\-]?\s*(.{3,80})$/im', $head, $m)) { $f['seriesTitle'] = trim($m[1]); $conf['seriesTitle'] = 0.6; }

        // ---- Klasifikasi: nama file > DDC teks
        if ($fnClass !== '') { $f['classification'] = $fnClass; $conf['classification'] = 0.9; }
        else { $ddc = self::guessDdc($head); if ($ddc !== '') { $f['classification'] = $ddc; $conf['classification'] = 0.8; } }

        if ($pages > 0) { $f['collation'] = $pages . ' ' . ($lang === 'ind' ? 'hlm.' : 'p.'); $conf['collation'] = 0.9; }
        $abs = self::guessAbstract($text, $body);
        if ($abs !== '') { $f['notes'] = $abs; $conf['notes'] = 0.6; }

        // ---- Perkayaan online
        $ol = null;
        if (!empty($opts['enrich']) && $isbn !== '') {
            $ol = self::enrichOpenLibrary($isbn);
            if ($ol === null) { $warnings[] = 'Perkayaan online gagal/tidak tersedia (offline?). Data lokal tetap digunakan.'; }
        }
        if ($ol) {
            if ((!isset($conf['title']) || $conf['title'] < 0.7) && !empty($ol['title'])) { $f['title'] = self::limit($ol['title'], 250); $conf['title'] = 0.85; }
            if (!$authors && !empty($ol['authors'])) {
                foreach ($ol['authors'] as $a) { if (!empty($a['name'])) { $authors[] = array('name'=>$a['name'],'level'=>1); } }
                foreach ($authors as $i => $a) { if ($i > 0) { $authors[$i]['level'] = 2; } }
                if ($authors) { $conf['authors'] = 0.85; }
            }
            if ($f['publisherID'] === '' && !empty($ol['publishers'][0]['name'])) { $f['publisherID'] = $ol['publishers'][0]['name']; $conf['publisher'] = 0.8; }
            if ($f['year'] === '' && !empty($ol['publish_date']) && preg_match('/(19|20)\d{2}/', $ol['publish_date'], $m)) { $f['year'] = $m[0]; $conf['year'] = 0.8; }
            if ($f['collation'] === '' && !empty($ol['number_of_pages'])) { $f['collation'] = (int)$ol['number_of_pages'] . ' ' . ($lang === 'ind' ? 'hlm.' : 'p.'); $conf['collation'] = 0.8; }
            if ($f['classification'] === '' && !empty($ol['dewey_decimal_class'][0])) {
                $d = trim($ol['dewey_decimal_class'][0]);
                if (preg_match('/^\d{3}(\.\d+)?$/', $d)) { $f['classification'] = $d; $conf['classification'] = 0.7; }
            }
            if (!empty($ol['cover']['large'])) { $coverUrl = $ol['cover']['large']; }
            elseif (!empty($ol['cover']['medium'])) { $coverUrl = $ol['cover']['medium']; }
        }

        // ---- Subjek
        $topics = self::matchTopics($dbs, $text !== '' ? $text : $head, 6);
        if ($topics) { $conf['topics'] = 0.8; }
        if (!$topics && $ol && !empty($ol['subjects'])) {
            foreach (array_slice($ol['subjects'], 0, 6) as $s) { if (!empty($s['name'])) { $topics[] = self::limit($s['name'], 60); } }
            if ($topics) { $conf['topics'] = 0.7; }
        }
        if (!$topics && strlen($text) > 2000) { $topics = self::keywords($text, 5); if ($topics) { $conf['topics'] = 0.4; $warnings[] = 'Subjek berupa kata kunci otomatis - mohon tinjau manual.'; } }

        // ---- Nomor panggil
        if ($f['classification'] !== '' && $f['title'] !== '') {
            $w = preg_split('/\s+/', trim($f['title'])); $cut = '';
            foreach ($w as $wd) {
                $wd = preg_replace('/[^A-Za-z]/', '', $wd);
                if (strlen($wd) >= 3 && !in_array(strtolower($wd), array('the','and','for','dan','yang','untuk','dari','dengan','pada','dalam','buku','ajar'))) { $cut = strtoupper(substr($wd, 0, 3)); break; }
            }
            $f['callNumber'] = trim($f['classification'] . ' ' . $cut); $conf['callNumber'] = 0.7;
        }

        // ---- Lookup DB
        $f['gmdID'] = self::lookup($dbs, 'mst_gmd', 'gmd_id', 'gmd_name', array('elektronik','electronic','e-book','ebook','digital','online','daring'));
        if ($f['gmdID']) { $conf['gmd'] = 0.9; }
        if ($lang === 'ind') { $f['languageID'] = self::lookup($dbs, 'mst_language', 'language_id', 'language_name', array('indones')); }
        elseif ($lang === 'eng') { $f['languageID'] = self::lookup($dbs, 'mst_language', 'language_id', 'language_name', array('english','inggris')); }
        if ($f['languageID']) { $conf['language'] = 0.7; }
        $f['contentTypeID'] = self::lookup($dbs, 'mst_content_type', 'id', 'content_type', array('text','teks'));
        $f['mediaTypeID'] = self::lookup($dbs, 'mst_media_type', 'id', 'media_type', array('computer','komputer'));
        $f['carrierTypeID'] = self::lookup($dbs, 'mst_carrier_type', 'id', 'carrier_type', array('online','daring','computer','komputer'));

        return array('fields'=>$f,'authors'=>$authors,'topics'=>$topics,'confidence'=>$conf,'warnings'=>$warnings,'isbn'=>$isbn,'cover_url'=>$coverUrl,'pages'=>$pages,'lang'=>$lang);
    }

    private static function limit($s, $n) { $s = trim($s); return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }
    private static function lines($text) { $l = array(); foreach (explode("\n", (string)$text) as $x) { $x = trim($x); if ($x !== '') { $l[] = $x; } } return $l; }

    private static function detectLang($text)
    {
        $t = strtolower(substr($text, 0, 20000)); $id = 0; $en = 0;
        foreach (self::$stopId as $w) { $id += preg_match_all('/\b' . $w . '\b/', $t); }
        foreach (self::$stopEn as $w) { $en += preg_match_all('/\b' . $w . '\b/', $t); }
        if ($id === 0 && $en === 0) { return 'ind'; }
        return $id >= $en ? 'ind' : 'eng';
    }

    private static function guessTitle($lines)
    {
        $skip = '/^(oleh|by|penulis|author|editor|penerbit|publisher|isbn|issn|http|www\.|cetakan|edisi|copyright|hak cipta|daftar isi|kata pengantar|\d+$)/i';
        $out = ''; $n = count($lines);
        for ($i = 0; $i < min($n, 20); $i++) {
            $ln = $lines[$i];
            if (strlen($ln) < 2 || preg_match($skip, $ln)) { if ($out !== '') { break; } continue; }
            $isCaps = (strtoupper($ln) === $ln && preg_match('/[A-Z]/', $ln));
            if ($out === '') {
                if (preg_match('/^[\d\W]+$/', $ln)) { continue; }
                $out = $ln;
            } else {
                if ($isCaps && strlen($ln) <= 80) { $out .= ' ' . $ln; }
                elseif (preg_match('/^[A-Z][a-z]/', $ln) && strlen($ln) <= 60 && substr_count($out, ' ') < 8 && !preg_match('/[.!?]$/', $out)) { $out .= ' ' . $ln; }
                else { break; }
            }
            if (strlen($out) > 200) { break; }
        }
        $out = trim(preg_replace('/\s+/', ' ', $out));
        if ($out !== '' && strtoupper($out) === $out) { $out = ucwords(strtolower($out)); }
        return rtrim($out, " :;,");
    }

    public static function splitNames($s)
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        $parts = preg_split('/\s*(?:;|\bdan\b|\band\b|&|\|)\s*/i', $s);
        $out = array();
        foreach ($parts as $p) {
            $p = trim($p, " ,.\t");
            if ($p === '') { continue; }
            if (strpos($p, ',') !== false && !preg_match('/^[^,]{2,40},\s*[A-Z][.\s]/', $p)) {
                $sub = array_map('trim', explode(',', $p)); $ok = true;
                foreach ($sub as $x) { if (str_word_count($x) < 2) { $ok = false; break; } }
                if ($ok) { foreach ($sub as $x) { $out[] = $x; } continue; }
            }
            $out[] = $p;
        }
        $res = array();
        foreach ($out as $nm) {
            $nm = trim($nm);
            if (strlen($nm) < 4 || strlen($nm) > 80) { continue; }
            if (preg_match('/\d|@|http|www\./i', $nm)) { continue; }
            if (strtoupper($nm) === $nm) { continue; } // merek/heading ALL-CAPS (mis. DELL)
            if (preg_match('/\b(PT|CV|Press|Publishing|Pustaka|Media|Gramedia|Erlangga|Universitas|Institut|Indonesia)\b/i', $nm)) { continue; }
            $res[] = self::limit($nm, 80);
        }
        return array_slice(array_values(array_unique($res)), 0, 8);
    }

    private static function looksLikeName($s)
    {
        $s = trim($s);
        if (strlen($s) < 4 || strlen($s) > 90) { return false; }
        if (preg_match('/\d|@|http|www\./i', $s)) { return false; }
        if (strtoupper($s) === $s) { return false; }
        if (preg_match('/\b(PT|CV|Press|Publishing|Pustaka|Media|Gramedia|Erlangga|Universitas|Institut|Indonesia)\b/i', $s)) { return false; }
        return true;
    }

    private static function guessAuthors($lines)
    {
        $res = array(); $n = count($lines);
        $labelRe = '/^(?:oleh|ditulis oleh|penulis|author|by)\b\s*[:\-]?\s*(.*)$/i';
        $editRe = '/^(?:editor|penyunting|edited by)\b\s*[:\-]?\s*(.*)$/i';
        $transRe = '/^(?:penerjemah|translator|translated by)\b\s*[:\-]?\s*(.*)$/i';
        $stopRe = '/^(?:editor|penyunting|penerjemah|translator|penerbit|publisher|isbn|issn|cetakan|edisi|copyright|hak cipta|daftar isi|kata pengantar|seri|series)\b/i';
        for ($i = 0; $i < min($n, 120); $i++) {
            $ln = $lines[$i]; $level = 1; $val = '';
            if (preg_match($labelRe, $ln, $m)) { $val = $m[1]; $level = 1; }
            elseif (preg_match($editRe, $ln, $m)) { $val = $m[1]; $level = 3; }
            elseif (preg_match($transRe, $ln, $m)) { $val = $m[1]; $level = 4; }
            else { continue; }
            $cand = array();
            if (trim($val) !== '') { $cand[] = trim($val); }
            for ($j = $i + 1; $j < min($n, $i + 6); $j++) {
                $nx = $lines[$j];
                if (preg_match($stopRe, $nx)) { break; }
                if (trim($nx) === '') { continue; }
                if (self::looksLikeName($nx)) { $cand[] = $nx; } else { break; }
            }
            foreach ($cand as $c) { foreach (self::splitNames($c) as $nm) { $res[] = array('name'=>$nm,'level'=>$level); } }
            if (count($res) >= 8) { break; }
        }
        return array_slice($res, 0, 8);
    }

    private static function isbnValid($d)
    {
        $len = strlen($d);
        if ($len === 13 && preg_match('/^97[89]\d{10}$/', $d)) {
            $sum = 0; for ($i = 0; $i < 12; $i++) { $sum += (int)$d[$i] * ($i % 2 ? 3 : 1); }
            return ((10 - ($sum % 10)) % 10) === (int)$d[12];
        }
        if ($len === 10 && preg_match('/^\d{9}[\dXx]$/', $d)) {
            $sum = 0; for ($i = 0; $i < 9; $i++) { $sum += (int)$d[$i] * (10 - $i); }
            $chk = strtoupper($d[9]) === 'X' ? 10 : (int)$d[9];
            return ($sum + $chk) % 11 === 0;
        }
        return false;
    }

    private static function findIsbn($text)
    {
        $found13 = ''; $found10 = '';
        if (preg_match_all('/[0-9][0-9Xx\-\s]{8,17}[0-9Xx]/', $text, $mm)) {
            $cands = array_slice($mm[0], 0, 300);
            foreach ($cands as $c) {
                $d = preg_replace('/[^0-9Xx]/', '', $c);
                if (strlen($d) === 13 && self::isbnValid($d) && $found13 === '') { $found13 = $d; }
                if (strlen($d) === 10 && self::isbnValid($d) && $found10 === '') { $found10 = strtoupper($d); }
                if ($found13 !== '') { break; }
            }
        }
        return $found13 !== '' ? $found13 : $found10;
    }

    private static function findIssn($text)
    {
        if (preg_match_all('/\b(\d{4})-(\d{3}[\dXx])\b/', $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $d = $m[1] . $m[2]; $sum = 0;
                for ($i = 0; $i < 7; $i++) { $sum += (int)$d[$i] * (8 - $i); }
                $chk = strtoupper($d[7]) === 'X' ? 10 : (int)$d[7];
                if (($sum + $chk) % 11 === 0) { return $m[0]; }
            }
        }
        return '';
    }

    private static function guessYear($head, $meta)
    {
        $maxY = (int)date('Y') + 1;
        if (preg_match('/(?:©|\(c\)|copyright|hak cipta|diterbitkan|published)[^\n]{0,80}?((?:19|20)\d{2})/i', $head, $m)) {
            if ((int)$m[1] <= $maxY) { return $m[1]; }
        }
        $years = array();
        if (preg_match_all('/\b((?:19|20)\d{2})\b/', $head, $mm)) {
            foreach ($mm[1] as $y) { $y = (int)$y; if ($y >= 1900 && $y <= $maxY) { $years[] = $y; } }
        }
        if ($years) { $cnt = array_count_values($years); arsort($cnt); reset($cnt); return (string)key($cnt); }
        if (!empty($meta['creationdate']) && preg_match('/D:(\d{4})/', $meta['creationdate'], $m)) {
            if ((int)$m[1] >= 1900 && (int)$m[1] <= $maxY) { return $m[1]; }
        }
        return '';
    }

    private static function guessPublisherPlace($head)
    {
        $res = array('publisher'=>'','place'=>'','conf'=>0.5);
        $badPub = '/\b(' . self::$months . '|cetakan|edisi|copyright|hak cipta|tanpa izin|dilarang|memperbanyak|tertulis|dari|untuk|yang|dengan|penulis|editor|isbn|issn)\b/i';
        $badPlace = '/\b(' . self::$months . '|cetakan|edisi|pertama|kedua|ketiga|keempat|copyright|hak cipta|indonesia|penulis|editor|penerbit|isbn|issn|judul|title|author)\b/i';
        $pubKeyword = '/\b(Gramedia|Erlangga|Andi|Informatika|Pustaka|Press|Publishing|Media|Universitas|Institut|Kementerian|Badan|Penerbit|Salemba|Rajawali|Kencana|Bumi Aksara|Alfabeta|Remaja|Gadjah Mada|UI Press|ITB Press|Deepublish|Yayasan|Sekolah|Kompas|Mizan|Republika|Elex|Bentang|Akademika|Literasi|Nusantara|Persada|Graha)\b/i';
        $lines = self::lines($head);

        // 1) Label eksplisit: "Penerbit: X" / "Diterbitkan oleh X" / "Published by X"
        if (preg_match('/(?:penerbit|publisher|diterbitkan oleh|published by)\s*[:\-]?\s*([^\n]{3,80})/i', $head, $m)) {
            $cand = trim($m[1], " :;,. ");
            if (!preg_match($badPub, $cand) && str_word_count($cand) <= 8) { $res['publisher'] = self::limit($cand, 80); $res['conf'] = 0.8; }
        }
        // 2) "Diterbitkan pertama kali oleh X"
        if ($res['publisher'] === '' && preg_match('/diterbitkan\s+(?:pertama\s+kali\s+)?oleh\s*[:\-]?\s*([^\n]{3,60})/i', $head, $m)) {
            $cand = trim($m[1], " :;,. ");
            if (!preg_match($badPub, $cand) && str_word_count($cand) <= 8) { $res['publisher'] = self::limit($cand, 80); $res['conf'] = 0.75; }
        }
        // 3) "Copyright (c) 2024 oleh X" / "(c) 2024 X"
        if ($res['publisher'] === '' && preg_match('/(?:copyright|hak cipta|©|\(c\))\s*(?:\(c\)\s*)?(?:19|20)\d{2}\s*(?:oleh|by)?\s*([^\n]{3,60})/i', $head, $m)) {
            $cand = trim($m[1], " :;,. ");
            if (!preg_match($badPub, $cand) && str_word_count($cand) <= 8) { $res['publisher'] = self::limit($cand, 80); $res['conf'] = 0.7; }
        }
        // 4) Baris klasik "Kota : Penerbit, Tahun"
        if (preg_match('/([A-Za-z][A-Za-z .\'\-]{2,40})\s*:\s*([A-Za-z][A-Za-z .\'&\-]{2,60}?)\s*,\s*(?:19|20)\d{2}/', $head, $m)) {
            $pl = trim($m[1]); $pu = trim($m[2]);
            if ($res['place'] === '' && !preg_match($badPlace, $pl)) { $res['place'] = self::limit($pl, 40); }
            if ($res['publisher'] === '' && !preg_match($badPub, $pu)) { $res['publisher'] = self::limit($pu, 80); $res['conf'] = 0.7; }
        }
        // 5) Label tempat eksplisit
        if ($res['place'] === '' && preg_match('/(?:tempat terbit|kota terbit|place of publication|publishing place)\s*[:\-]?\s*([^\n]{2,40})/i', $head, $m)) {
            $cand = trim($m[1], " :;,. ");
            if (!preg_match($badPlace, $cand)) { $res['place'] = self::limit($cand, 40); }
        }
        // 6) "Kota : Penerbit" tanpa tahun (hindari label Penulis/Editor/ISBN)
        if ($res['place'] === '' || $res['publisher'] === '') {
            foreach ($lines as $ln) {
                if (preg_match('/^([A-Z][A-Za-z .\'\-]{2,30})\s*:\s*([A-Z][A-Za-z .\'&\-]{2,50})$/', $ln, $m)) {
                    $pl = trim($m[1]); $pu = trim($m[2]);
                    if ($res['place'] === '' && !preg_match($badPlace, $pl)) { $res['place'] = self::limit($pl, 40); }
                    if ($res['publisher'] === '' && !preg_match($badPub, $pu)) { $res['publisher'] = self::limit($pu, 80); $res['conf'] = 0.6; }
                }
            }
        }
        // 7) Baris berkatakunci penerbit
        if ($res['publisher'] === '') {
            foreach ($lines as $ln) {
                if (preg_match($pubKeyword, $ln) && strlen($ln) <= 80 && !preg_match($badPub, $ln)) {
                    $res['publisher'] = self::limit(trim($ln, " :;,. "), 80); $res['conf'] = 0.5; break;
                }
            }
        }
        // 8) Guard: satu kata negara / label bukan penerbit
        if ($res['publisher'] !== '' && str_word_count($res['publisher']) < 2
            && !preg_match('/\b(Press|Pustaka|Penerbit|Publishing|Media|Gramedia|Erlangga|Andi|Informatika|Deepublish|Salemba|Rajawali|Kencana)\b/i', $res['publisher'])) {
            $res['publisher'] = ''; $res['conf'] = 0.5;
        }
        // 9) Sanity tempat
        if ($res['place'] !== '' && (strlen($res['place']) < 3 || strcasecmp($res['place'], $res['publisher']) === 0)) { $res['place'] = ''; }
        return $res;
    }

    private static function guessEdition($head)
    {
        if (!preg_match('/(?:edisi|edition|ed\.)\s*(?:ke[-\s]*)?(\d{1,2}|pertama|kedua|ketiga|keempat|kelima|keenam|first|second|third|fourth|fifth|revisi|revised)/i', $head, $m)) { return ''; }
        $map = array('pertama'=>1,'kedua'=>2,'ketiga'=>3,'keempat'=>4,'kelima'=>5,'keenam'=>6,'first'=>1,'second'=>2,'third'=>3,'fourth'=>4,'fifth'=>5);
        $v = strtolower($m[1]);
        if (isset($map[$v])) { return 'Ed. ' . $map[$v]; }
        if (preg_match('/revisi|revised/', $v)) { return 'Ed. revisi'; }
        return 'Ed. ' . (int)$v;
    }

    private static function guessDdc($head)
    {
        if (preg_match('/\b(?:DDC|Dewey|Klasifikasi|Classification)\s*[:.]?\s*(\d{3}(?:\.\d+)?)/i', $head, $m)) { return $m[1]; }
        if (preg_match('/\b(\d{3}\.\d{2,})\b/', $head, $m)) { return $m[1]; }
        return '';
    }

    private static function guessAbstract($text, $body)
    {
        if (preg_match('/(?:abstrak|abstract|ringkasan|summary)\s*[:\n]\s*(.{100,1500})/is', $text, $m)) {
            $chunk = preg_split('/\n{2,}/', $m[1]);
            $s = trim(preg_replace('/\s+/', ' ', $chunk[0]));
            if (strlen($s) >= 80) { return self::limit($s, 1500); }
        }
        $src = $body !== '' ? $body : $text;
        foreach (preg_split('/\n{2,}/', $src) as $para) {
            $para = trim(preg_replace('/\s+/', ' ', $para));
            if (strlen($para) >= 200 && substr_count($para, ' ') >= 25) { return self::limit($para, 1500); }
        }
        return '';
    }

    private static function keywords($text, $n)
    {
        $t = strtolower($text);
        $t = preg_replace('/[^a-z\x{00C0}-\x{024F}\s-]/u', ' ', $t);
        $words = preg_split('/\s+/', (string)$t);
        $stop = array_merge(self::$stopId, self::$stopEn);
        $freq = array();
        foreach ($words as $w) { if (strlen($w) < 4 || in_array($w, $stop, true)) { continue; } if (!isset($freq[$w])) { $freq[$w] = 0; } $freq[$w]++; }
        arsort($freq);
        $out = array();
        foreach (array_slice(array_keys($freq), 0, $n) as $w) { $out[] = ucwords($w); }
        return $out;
    }

    private static function q($dbs, $sql)
    {
        try { if (!is_object($dbs) || !method_exists($dbs, 'query')) { return null; } $r = $dbs->query($sql); return $r ? $r : null; } catch (\Throwable $e) { return null; }
    }

    private static function lookup($dbs, $table, $idCol, $nameCol, $needles)
    {
        $r = self::q($dbs, "SELECT `$idCol`, `$nameCol` FROM `$table` LIMIT 500");
        if (!$r) { return ''; }
        try { while ($row = $r->fetch_row()) { $name = strtolower((string)$row[1]); foreach ($needles as $nd) { if (strpos($name, strtolower($nd)) !== false) { return (string)$row[0]; } } } } catch (\Throwable $e) { return ''; }
        return '';
    }

    private static function matchTopics($dbs, $text, $max)
    {
        $out = array();
        if (strlen($text) < 200) { return $out; }
        $r = self::q($dbs, 'SELECT topic FROM mst_topic LIMIT 3000');
        if (!$r) { return $out; }
        $sample = substr($text, 0, 30000);
        try { while ($row = $r->fetch_row()) { $topic = trim((string)$row[0]); if (strlen($topic) < 4) { continue; } if (preg_match('/\b' . preg_quote($topic, '/') . '\b/iu', $sample)) { $out[] = $topic; if (count($out) >= $max) { break; } } } } catch (\Throwable $e) { return $out; }
        return $out;
    }

    private static function enrichOpenLibrary($isbn)
    {
        $url = 'https://openlibrary.org/api/books?bibkeys=ISBN:' . rawurlencode($isbn) . '&format=json&jscmd=data';
        $raw = null;
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_USERAGENT=>'PDF2Biblio/1.0 (SLiMS plugin)'));
                $raw = curl_exec($ch); curl_close($ch);
            } else {
                $ctx = stream_context_create(array('http'=>array('timeout'=>8, 'header'=>"User-Agent: PDF2Biblio/1.0\r\n")));
                $raw = @file_get_contents($url, false, $ctx);
            }
        } catch (\Throwable $e) { return null; }
        if (!is_string($raw) || $raw === '') { return null; }
        $j = json_decode($raw, true);
        if (!is_array($j)) { return null; }
        $key = 'ISBN:' . $isbn;
        return isset($j[$key]) && is_array($j[$key]) ? $j[$key] : null;
    }
}