<?php
namespace PDF2Biblio;

/** Ekstraksi bibliografi menyeluruh dari PDF: KDT, hak cipta, sampul, metadata, nama file. */
class Extractor
{
    private const CITIES = ['Jakarta','Bandung','Yogyakarta','Surabaya','Semarang','Malang','Solo','Surakarta','Depok','Tangerang','Bekasi','Bogor','Medan','Padang','Pekanbaru','Palembang','Banda Aceh','Bandar Lampung','Makassar','Manado','Denpasar','Mataram','Kupang','Maumere','Ende','Samarinda','Banjarmasin','Pontianak','Balikpapan','Jayapura','Ambon','Cirebon','Purwokerto','Klaten','Sukoharjo','Karanganyar','Sidoarjo','Gresik','Jember','Kediri','Blitar','Madiun','Tasikmalaya','Garut','Ciamis','Serang','Cimahi','Salatiga','Magelang','Pekalongan','Tegal','Banyumas','Cilacap','Kebumen','Kudus','Jepara','Pati','Bojonegoro','Tuban','Lamongan','Mojokerto','Pasuruan','Probolinggo','Banyuwangi','Sleman','Bantul','Wonogiri','Ponorogo','Nganjuk','Ngawi','Pemalang','Brebes','Batang','Kendal','Demak','Grobogan','Sragen','Boyolali','Temanggung','Wonosobo','Purworejo','Singapura','Singapore','Kuala Lumpur','London','New York','Boston','Tokyo','Delhi','Amsterdam','Berlin','Heidelberg'];

    private const COUNTRIES = ['Indonesia','India','Australia','USA','UK','United States','Germany','Japan','China','Singapore','Malaysia','Thailand','Philippines','Vietnam','Korea','England','France','Italy','Spain','Netherlands','Brazil','Canada'];

    private const PUB_WORDS = 'Penerbit|Press|Publishing|Publishers|Media|Pustaka|Gramedia|Erlangga|Andi|Rajawali|Kencana|Salemba|Deepublish|Alfabeta|Bumi Aksara|Prenada|Widya|Insan|Cendekia|Literasi|Laksana|Jejak|Samudra|Bentang|Serambi|Mizan|Gema|Yrama|Wacana|Azka|Tahta|Gava|Bintang|Cahaya|Nusantara|Quantum|Nem|Republika|University|Universitas|Institut|Politeknik|Akademi|Kementerian|Balai|Badan|Lembaga';

    private const SMALL_FILE_BYTES = 8388608; // 8 MB: batas aman smalot (port mesin legacy anti-crash)

    public static function blank(): array
    {
        return ['title'=>'','author'=>'','year'=>'','isbn'=>'','publisher'=>'','place'=>'',
            'pages'=>'','collation'=>'','edition'=>'','series'=>'','language'=>'','classification'=>'',
            'call_number'=>'','subjects'=>[],'notes'=>'','pdf_name'=>'','pdf_pages'=>0,'size'=>0,
            'scanned'=>false,'sources'=>[],'isbns_all'=>[],'confidence'=>0];
    }

    public static function extract(string $file, string $orig, array $preset = []): array
    {
        $d = self::blank();
        $d['pdf_name'] = $orig;
        $d['size'] = (int)@filesize($file);
        $log = [];
        $fp = self::fileParts($orig);
        $stem = $fp['stem'];
        $stemSane = mb_strlen($stem) >= 8 && str_word_count($stem) >= 2 && !self::isPersonLine($stem);

        $meta = []; $front = []; $total = 0; $tmTitle = '';
        if ($preset) {
            // Hasil parse dari child (ParseRunner): lewati seluruh blok guard di bawah.
            $meta = (array)($preset['meta'] ?? []);
            $front = (array)($preset['front'] ?? []);
            $total = (int)($preset['total'] ?? 0);
            $tmTitle = (string)($preset['tm'] ?? '');
        }
        if (!$preset) {
        // Guard anti-crash: smalot hanya file kecil + Config limit; bendera crash memaksa native.
        $hash = (string)@sha1_file($file);
        $crashFlag = dirname(__DIR__) . '/tmp/crash_' . $hash . '.flag';
        $useSmalot = $d['size'] <= self::SMALL_FILE_BYTES
            && class_exists('\Smalot\PdfParser\Parser')
            && !is_file($crashFlag);
        if ($useSmalot) {
            @file_put_contents($crashFlag, '1'); // bila proses mati di sini, flag tersisa → retry pakai native
            try {
                $config = class_exists('\Smalot\PdfParser\Config') ? new \Smalot\PdfParser\Config() : null;
                if ($config) {
                    if (method_exists($config, 'setRetainImageContent')) $config->setRetainImageContent(false);
                    if (method_exists($config, 'setDecodeMemoryLimit')) $config->setDecodeMemoryLimit(64 * 1024 * 1024);
                }
                $parser = $config ? new \Smalot\PdfParser\Parser([], $config) : new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($file);
                $pages = $pdf->getPages();
                $total = count($pages);
                $meta = (array)$pdf->getDetails();
                for ($i = 0; $i < min(12, $total); $i++) {
                    try { $front[$i] = self::clean($pages[$i]->getText()); } catch (\Throwable $e) { $front[$i] = ''; }
                }
                if ($total > 12) {
                    for ($i = $total - 3; $i < $total; $i++) {
                        try { $front[$i] = self::clean($pages[$i]->getText()); } catch (\Throwable $e) {}
                    }
                }
                try { $tmTitle = self::fontTitle(array_slice($pages, 0, 2)); } catch (\Throwable $e) {}
                unset($pdf, $parser, $pages, $config);
                if (function_exists('gc_collect_cycles')) gc_collect_cycles();
                @unlink($crashFlag); // parse selesai sehat
            } catch (\Throwable $e) {
                @unlink($crashFlag);
                $log[] = 'Parser gagal: ' . $e->getMessage() . ' — pakai parser native + perkayaan API.';
                $useSmalot = false;
                $front = [];
            }
        } elseif (is_file($crashFlag)) {
            $log[] = 'Smalot pernah crash pada file ini (bendera crash) — langsung parser native anti-crash.';
        } else {
            $log[] = 'File > 8 MB — parser native anti-crash (port mesin legacy).';
        }
        if (!$useSmalot) {
            try {
                if (!class_exists('P2B_PdfExtractor')) {
                    $pf = __DIR__ . '/PdfExtractor.php';
                    if (is_file($pf)) {
                        if (!defined('INDEX_AUTH')) define('INDEX_AUTH', 1);
                        require_once $pf;
                    }
                }
                if (class_exists('P2B_PdfExtractor')) {
                    $nat = \P2B_PdfExtractor::extract($file, 90000, true);
                    $total = (int)$nat['pages'];
                    $meta = (array)$nat['meta'];
                    $txt = self::clean((string)$nat['text']);
                    foreach (mb_str_split($txt, 3000) as $i => $ch) $front[$i] = $ch;
                    if (!$front) $front = [''];
                    foreach (($nat['warnings'] ?? []) as $w) $log[] = $w;
                    unset($nat, $txt);
                }
            } catch (\Throwable $e) {
                $log[] = 'Parser native gagal: ' . $e->getMessage() . ' — mengandalkan metadata + nama file + API.';
            }
        }
        }
        // B1: normalisasi blok KDT multi-baris (label ⏎ :  nilai, gelar & ISBN terpecah baris).
        foreach ($front as $k => $t) $front[$k] = self::kdtNormalize($t);
        $d['pdf_pages'] = $total;
        $page1 = $front[0] ?? '';
        $all = self::clean(implode("\n", $front));
        $flat = preg_replace('/\s+/u', ' ', $all);
        if ($total > 0 && mb_strlen($all) < 200) {
            $d['scanned'] = true;
            $log[] = 'Teks minim — kemungkinan PDF scan (perlu OCR). Mengandalkan metadata + API.';
        }

        $copy = ''; $best = -1;
        foreach ($front as $t) {
            $s = preg_match_all('/ISBN|Hak\s+Cipta|Katalog\s+Dalam\s+Terbitan|\bKDT\b|Cetakan|Diterbitkan|Copyright|©/iu', $t);
            if ($s > $best) { $best = $s; $copy = $t; }
        }
        if ($best < 2) $copy = $all;

        // ---- ISBN ----
        [$isbn, $allIsbn] = self::bestIsbn($copy, $all);
        $d['isbn'] = $isbn; $d['isbns_all'] = $allIsbn;
        if ($isbn !== '') $d['sources']['isbn'] = 'PDF';

        // ---- JUDUL: KDT > metadata > font sampul > baris sampul > nama file; anti nama-orang ----
        $title = self::grab('/Judul(?:\s+Buku)?\s*[:\-]\s*([^\r\n]{4,200})/iu', $copy);
        if ($title === '' || self::isPersonLine($title)) {
            $mt = self::metaVal($meta, 'Title');
            if ($title === '' && self::saneTitle($mt) && !self::isPersonLine($mt)) $title = $mt;
        }
        if ($title === '' && $tmTitle !== '' && !self::isPersonLine($tmTitle)) $title = $tmTitle;
        if ($title === '' || self::isPersonLine($title)) {
            $ct = self::coverTitle($page1);
            if ($ct !== '' && !self::isPersonLine($ct)) $title = $ct;
        }
        if ($title === '' || self::isPersonLine($title)) { $title = $stem; $d['sources']['title'] = 'FILE'; }
        // verifikasi: nama file bersih muncul di teks PDF & kandidat sekarang melenceng → pakai nama file
        if ($stemSane && stripos($flat, $stem) !== false && similar_text(mb_strtolower($title), mb_strtolower($stem)) < 55) {
            $title = $stem;
            $d['sources']['title'] = 'PDF';
        }
        $d['title'] = self::tidyTitle($title);
        if ($d['title'] !== '' && !isset($d['sources']['title'])) $d['sources']['title'] = 'PDF';

        // ---- PENULIS ----
        $raw = self::authorBlock($copy); // B1: blok multi-baris sampai label berikutnya
        if ($raw === '') $raw = self::grab('/(?:Penulis|Pengarang|Penyusun|Authors?|Disusun\s+oleh|Ditulis\s+oleh)\s*[:\-]?\s*([^\r\n]{3,200})/iu', $copy);
        if ($raw === '') $raw = self::grab('/\bOleh\s*[:\-]?\s*([A-Z][^\r\n]{3,150})/u', $page1);
        if ($raw === '') { $ma = self::metaVal($meta, 'Author'); if (self::saneAuthor($ma)) $raw = $ma; }
        if ($raw === '') $raw = self::grab('/(?:Editor|Penyunting)\s*[:\-]\s*([^\r\n]{3,150})/iu', $copy);
        $authors = self::splitAuthors($raw);
        $d['author'] = implode('; ', $authors);
        if ($d['author'] !== '') $d['sources']['author'] = 'PDF';

        // ---- IMPRINT ----
        $imp = [];
        if (preg_match('/([A-Z][A-Za-z .\'-]{2,40})\s*:\s*([A-Z][^,\r\n]{2,70}?)\s*,\s*((?:19|20)\d{2})/u', $copy, $m)) {
            if (self::isCity($m[1])) $imp = [trim($m[1]), trim($m[2]), $m[3]];
        }

        // ---- PENERBIT (label wajib titik-dua; kandidat dibersihkan berantai) ----
        $pubCands = [];
        $pubCands[] = self::grab('/(?:^|\n)\s*(?:Penerbit|Diterbitkan\s+oleh|Dipublikasikan\s+oleh|Diterbitkan\s+pertama\s+kali\s+oleh|Published\s+by)\s*[:\-]\s*\n?\s*([^\r\n]{3,90})/iu', $copy);
        $pubCands[] = self::grab('/\b(?:Penerbit|Diterbitkan\s+oleh|Dipublikasikan\s+oleh|Published\s+by)\s*[:\-]\s*\n?\s*([^\r\n]{3,90})/iu', $copy);
        $pubCands[] = self::grab('/(?:©|\(c\)|Copyright)\s*(?:\d{4}\s*)?(?:oleh|by|pada)?\s*[,:\-]?\s*\n?\s*([A-Z][^\r\n]{3,70})/iu', $copy);
        $pubCands[] = self::scanPublisher($front, $copy);
        $pubCands[] = self::publisherFromDomain($copy . "\n" . $all);
        if ($imp) $pubCands[] = $imp[1];
        $pub = '';
        foreach ($pubCands as $c) {
            $c = self::cleanPublisher($c);
            if ($c !== '') { $pub = $c; break; }
        }
        $d['publisher'] = $pub;
        if ($pub !== '') $d['sources']['publisher'] = 'PDF';
        if ($pub === '') $log[] = 'DEBUG teks hak cipta: ' . mb_substr(preg_replace('/\s+/u', ' ', $copy), 0, 1200);

        // ---- TEMPAT ----
        $place = self::grab('/(?:Tempat\s*(?:Terbit|Penerbitan)?|Kota\s+Terbit|Diterbitkan\s+di|Published\s+in)\s*[:\-]?\s*([A-Z][A-Za-z .\'-]{2,30})/iu', $copy);
        if ($place === '' && preg_match('/\bKota\s+([A-Z][a-z]+(?:\s[A-Z][a-z]+)?)/u', $copy, $m)) $place = $m[1];
        if ($place === '' && $imp) $place = $imp[0];
        if ($place === '' || !self::isCity($place)) {
            $dict = self::cityNear($copy, $d['publisher']);
            if ($dict !== '') $place = $dict;
        }
        $d['place'] = self::isCity($place) ? self::canonCity($place) : '';
        if ($d['place'] !== '') $d['sources']['place'] = 'PDF';

        // ---- TAHUN ----
        $years = [];
        if (preg_match_all('/(?:Cetakan|Terbit|Diterbitkan|Copyright|©|Published)[^\n]{0,60}?\b((?:19|20)\d{2})\b/iu', $copy, $m)) $years = $m[1];
        if ($imp) $years[] = $imp[2];
        $year = $years ? (string)min($years) : '';
        if ($year === '' && preg_match_all('/\b((?:19|20)\d{2})\b/', $copy, $m)) {
            $ok = array_filter($m[1], fn($y) => $y >= 1900 && $y <= (int)date('Y') + 1);
            if ($ok) $year = (string)min($ok);
        }
        if ($year === '' && preg_match('/D:((?:19|20)\d{2})/', self::metaVal($meta, 'CreationDate'), $m)) $year = $m[1];
        if ($year === '' && $fp['year']) $year = $fp['year'];
        $d['year'] = $year;
        if ($year !== '') $d['sources']['year'] = 'PDF';

        // ---- HLM / KOLASI ----
        $printed = self::grab('/((?:[xivlcdmIVXLCDM]{1,7}\s*[+,;]\s*)?\d{1,4})\s*(?:hlm|halaman|hal\.?|pages)\b/iu', $copy);
        $printed = preg_replace_callback('/^[xivlcdm]+/i', fn($m) => strtolower($m[0]), $printed);
        $dim = '';
        if (preg_match('/(\d{1,2}(?:[.,]\d)?)\s*[x×]\s*(\d{1,2}(?:[.,]\d)?)\s*cm/iu', $copy, $m)) {
            $dim = max((float)str_replace(',', '.', $m[1]), (float)str_replace(',', '.', $m[2])) . ' cm';
        }
        $d['pages'] = $printed !== '' ? preg_replace('/\s+/', '', $printed) : (string)$total;
        $d['collation'] = trim($d['pages'] . ' hlm.' . ($dim ? '; ' . $dim : ''));
        if ($d['pages'] !== '') $d['sources']['pages'] = 'PDF';

        // ---- EDISI / SERI ----
        $ed = self::grab('/(?:Edisi|Edition)\s*(?:ke[-\s]?)?([0-9]{1,2}|[IVXivx]{1,5}|Pertama|Kedua|Ketiga|Keempat|Kelima|Revisi|Revised|Baru)/iu', $copy);
        $d['edition'] = self::normEdition($ed);
        $d['series'] = self::grab('/(?:Seri|Series)\s*[:\-]\s*([^\r\n]{3,80})/iu', $copy);

        // ---- SUBJEK ----
        $subs = [];
        foreach (['Keywords', 'Subject'] as $k) {
            $v = self::metaVal($meta, $k);
            if ($v !== '') $subs = array_merge($subs, preg_split('/[,;]\s*/u', $v));
        }
        $kk = self::grab('/Kata\s+[Kk]unci\s*[:\-]\s*([^\r\n]{3,200})/iu', $all);
        if ($kk !== '') $subs = array_merge($subs, preg_split('/[,;]\s*/u', $kk));
        $subs = array_slice(array_unique(array_filter(array_map(fn($s) => mb_substr(trim($s), 0, 60), $subs))), 0, 8);
        $d['subjects'] = $subs;
        if ($subs) $d['sources']['subjects'] = 'PDF';

        // ---- DESKRIPSI ----
        $desc = self::grab('/(?:Abstrak|Abstract|Sinopsis|Ringkasan|Tentang\s+Buku(?:\s+Ini)?)\s*[:\n]\s*(.{80,2500}?)(?:\n\s*\n|Kata\s+[Kk]unci|Keywords|Daftar\s+Isi|\z)/isu', $all);
        $desc = trim(preg_replace('/\s+/u', ' ', strip_tags($desc)));
        if (mb_strlen($desc) > 1500) $desc = mb_substr($desc, 0, 1500) . '…';

        // ---- KLASIFIKASI / NOMOR PANGGIL ----
        $class = self::grab('/(?:DDC|Dewey|Klasifikasi(?:\s+DDC)?)\s*[:\-]?\s*(\d{3}(?:\.\d+)?)/iu', $copy);
        if ($class === '' && $fp['class'] !== '') { $class = $fp['class']; $d['sources']['classification'] = 'FILE'; }
        $d['classification'] = $class;
        $call = $fp['call'] !== '' ? $fp['call'] : $class;
        if ($call !== '') {
            $a3 = '';
            if ($authors) {
                $words = preg_split('/\s+/u', $authors[0]);
                $words = array_values(array_filter($words, fn($w) => !self::isDegree($w)));
                $last = end($words);
                if ($last) $a3 = strtoupper(mb_substr($last, 0, 3));
            }
            $t1 = $d['title'] !== '' ? strtolower(mb_substr($d['title'], 0, 1)) : '';
            $d['call_number'] = trim($call . ($a3 ? ' ' . $a3 : '') . ($t1 ? ' ' . $t1 : ''));
        }

        // ---- BAHASA ----
        $sample = mb_strtolower(mb_substr($all, 0, 6000));
        $id = preg_match_all('/\b(yang|dan|dari|untuk|dengan|pada|adalah|ini|itu|tidak|akan|dapat|dalam|atau|tersebut)\b/u', $sample);
        $en = preg_match_all('/\b(the|and|of|to|in|is|that|for|with|are|this|by|an)\b/u', $sample);
        $d['language'] = ($en > $id && $en > 3) ? 'en' : 'id';

        // ---- CATATAN ----
        $notes = [];
        if ($desc !== '') $notes[] = $desc;
        $notes[] = 'Diimpor dari PDF: ' . $orig . ($total ? " ($total hlm. PDF)" : '') . ' — PDF2Biblio ' . date('Y-m-d H:i');
        if (count($allIsbn) > 1) $notes[] = 'ISBN lain: ' . implode(', ', array_slice($allIsbn, 1));
        $cet = self::grab('/(Cetakan[^\r\n]{0,60})/iu', $copy);
        if ($cet !== '') $notes[] = $cet;
        $editor = self::grab('/(?:Editor|Penyunting)\s*[:\-]\s*([^\r\n]{3,120})/iu', $copy);
        if ($editor !== '' && $d['author'] !== '' && stripos($d['author'], $editor) === false) $notes[] = 'Editor: ' . $editor;
        $d['notes'] = mb_substr(implode("\n", $notes), 0, 2000);

        $d['confidence'] = self::score($d);
        $d['log'] = $log;
        return $d;
    }

    public static function score(array $d): int
    {
        $w = ['title'=>20,'author'=>18,'isbn'=>15,'publisher'=>14,'year'=>10,'place'=>6,'pages'=>5,'subjects'=>5,'notes'=>4,'call_number'=>3];
        $s = 0;
        foreach ($w as $k => $v) if (!empty($d[$k])) $s += $v;
        return min(100, $s);
    }

    // ================= HELPERS =================
    private static function clean(string $t): string
    {
        if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
        $t = str_replace(["\r\n", "\r"], "\n", $t);
        $t = preg_replace('/[ \t]+/u', ' ', $t);
        $t = preg_replace('/-\n(?=[a-z])/u', '', $t);
        return trim($t);
    }

    /** B1: satukan label KDT yang terpisah baris ("Penulis ⏎ :  nama"), gelar & ISBN terpecah. */
    private static function kdtNormalize(string $t): string
    {
        $t = preg_replace('/\s*\n\s*:\s*\n\s*/u', ': ', $t);
        $t = preg_replace('/\s*\n\s*:/u', ':', $t);
        $t = preg_replace('/\s*\n\s*(?=[,.;:\-–—])/u', '', $t);
        $t = preg_replace('/([,.;:\-–—])\s*\n\s*/u', '$1 ', $t);
        $t = preg_replace_callback('/ISBN\s*[:\-]?\s*([0-9][0-9\-\s]{9,30})/iu',
            fn($m) => 'ISBN: ' . preg_replace('/[^0-9Xx]/', '', $m[1]), $t);
        return $t;
    }

    /** B1: ambil blok penulis multi-baris sampai label berikutnya; baris → pemisah ';'. */
    private static function authorBlock(string $copy): string
    {
        if (!preg_match('/(?:^|\n)\s*(?:Penulis|Pengarang|Penyusun|Authors?|Disusun\s+oleh|Ditulis\s+oleh)\s*[:\-]\s*/iu', $copy, $m, PREG_OFFSET_CAPTURE)) return '';
        $start = $m[0][1] + strlen($m[0][0]);
        $rest = substr($copy, $start);
        if (preg_match('/\n\s*(?:Editor|Penyunting|ISBN|Penerbit|Diterbitkan|Cetakan|Hak\s+Cipta|Copyright|Kata\s+Kunci|Desain|Penata)\b/iu', $rest, $s, PREG_OFFSET_CAPTURE)) {
            $rest = substr($rest, 0, $s[0][1]);
        }
        $rest = trim(mb_substr($rest, 0, 600));
        return preg_replace('/\s*\n\s*/u', '; ', $rest);
    }

    private static function grab(string $re, string $hay): string
    {
        return ($hay !== '' && preg_match($re, $hay, $m)) ? trim($m[1]) : '';
    }

    private static function metaVal(array $meta, string $key): string
    {
        foreach ($meta as $k => $v) if (strcasecmp($k, $key) === 0) return trim((string)$v);
        return '';
    }

    private static function fileParts(string $orig): array
    {
        $stem = pathinfo($orig, PATHINFO_FILENAME);
        $stem = preg_replace('/\(\+?ISBN\)/i', '', $stem);
        $call = ''; $class = '';
        if (preg_match('/^([A-Z]{1,3}\s?\d{1,3}(?:[.,]\d+)?)\s*[-–_]\s*(.+)$/u', $stem, $m)) {
            $call = trim($m[1]);
            if (preg_match('/(\d{1,3}(?:[.,]\d+)?)/', $call, $mm)) $class = str_replace(',', '.', $mm[1]);
            $stem = $m[2];
        }
        $stem = preg_replace('/\s*\(\d+\)\s*$/', '', $stem);
        $stem = trim(preg_replace('/\s+/u', ' ', str_replace(['_', '--'], ' ', $stem)));
        $year = preg_match('/\b((?:19|20)\d{2})\b/', $stem, $m) ? $m[1] : '';
        return ['stem'=>$stem, 'call'=>$call, 'class'=>$class, 'year'=>$year];
    }

    private static function saneTitle(string $t): bool
    {
        $t = trim($t);
        if (mb_strlen($t) < 4) return false;
        if (preg_match('/^(Microsoft|Word|Untitled|Document|Scan|IMG_|DOC\d|Print|Layout|PowerPoint|Excel|Canva|InDesign)/i', $t)) return false;
        if (preg_match('/\.(docx?|indd|pdf|cdr|pptx?)$/i', $t)) return false;
        if (preg_match('/^[A-Z]:|^\/Users\//', $t)) return false;
        return true;
    }

    private static function saneAuthor(string $t): bool
    {
        $t = trim($t);
        if (mb_strlen($t) < 3 || preg_match('/^\d+$/', $t) || strpos($t, '@') !== false) return false;
        return !preg_match('/(Administrator|Admin|User|Windows|Owner|\bPC\b|\bHP\b|Acer|Asus|Lenovo|Microsoft|Adobe|Canva|Corel|WPS|Foxit|Acrobat|Printer|Scan)/i', $t);
    }

    /** Deteksi baris nama orang (bergelar / pola "Nama, S.Xx., M.Xx.") agar tidak jadi judul/penerbit. */
    public static function isPersonLine(string $s): bool
    {
        $s = trim($s);
        if ($s === '') return false;
        if (preg_match('/^(?:Dr|Drs|Dra|Prof|Ir|H|Hj|Penulis|Pengarang|Oleh|Editor|Penyunting)\b/iu', $s)) return true;
        if (preg_match('/(?:,\s*(?:S|M)\.[A-Z]|,\s*Ph\.?D|\b(?:S|M)\.(?:Pd|E|Kom|T|Sos|Ag|H|Psi|Si|Sn|Kep|Farm|Ak|Hum|Ikom|Th)\b)/u', $s)) return true;
        $chunks = preg_split('/[,;]/u', $s);
        if (count($chunks) >= 2) {
            $deg = 0; $name = 0;
            foreach ($chunks as $c) {
                $c = trim($c);
                if ($c === '') continue;
                if (self::isDegree($c)) $deg++;
                elseif (preg_match('/^[A-Z][a-z]+(?:\s[A-Z][a-z]+)+$/u', $c)) $name++;
            }
            if ($deg >= 1 && $name >= 1) return true;
        }
        return false;
    }

    private static function tidyTitle(string $t): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t), " \t\n\r\0\x0B.,;:");
        if ($t !== '' && mb_strtoupper($t) === $t) $t = mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
        $t = preg_replace_callback('/\s(Dan|Di|Ke|Dari|Untuk|Terhadap|Pada|Dalam|Atau|Yang|Dengan|Sebagai|Tanpa|Oleh|Bagi|Via|Versus)\b/u', fn($m) => ' ' . strtolower($m[1]), $t);
        return mb_substr($t, 0, 300);
    }

    private static function fontTitle(array $pages): string
    {
        $groups = [];
        foreach ($pages as $p) {
            foreach ($p->getDataTm() as $row) {
                if (!is_array($row) || count($row) < 2) continue;
                $size = (int)round(abs((float)$row[0][0]));
                $txt = trim((string)$row[1]);
                if ($size <= 0 || mb_strlen($txt) < 2) continue;
                $groups[$size][] = $txt;
            }
        }
        if (!$groups) return '';
        krsort($groups, SORT_NUMERIC);
        foreach ($groups as $parts) {
            $t = self::tidyTitle(implode(' ', $parts));
            $len = mb_strlen($t);
            if ($len < 8 || $len > 180 || substr_count($t, ' ') > 25) continue;
            if (preg_match('/ISBN|www\.|@|Cetakan|Hak\s+Cipta|^\d+$/iu', $t)) continue;
            if (self::isPersonLine($t)) continue;
            if (count($groups) > 1 && preg_match('/(?:' . self::PUB_WORDS . ')/iu', $t)) continue;
            return $t;
        }
        return '';
    }

    private static function coverTitle(string $page1): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $page1))));
        $best = '';
        foreach (array_slice($lines, 0, 10) as $i => $ln) {
            if (mb_strlen($ln) < 8 || mb_strlen($ln) > 120) continue;
            if (preg_match('/ISBN|www\.|@|Cetakan|Hak\s+Cipta|^\d+$/iu', $ln)) continue;
            if (self::isPersonLine($ln)) continue;
            if (preg_match('/(?:' . self::PUB_WORDS . ')/iu', $ln)) continue;
            if (mb_strlen($ln) > mb_strlen($best)) {
                $best = $ln;
                $next = $lines[$i + 1] ?? '';
                if ($next !== '' && mb_strlen($next) < 60 && preg_match('/^(dan|untuk|di|:|[a-z])/u', $next) && !self::isPersonLine($next)) $best .= ' ' . $next;
            }
        }
        return $best;
    }

    public static function isbn13(string $n): bool
    {
        if (!preg_match('/^97[89]\d{10}$/', $n)) return false;
        $s = 0;
        for ($i = 0; $i < 13; $i++) $s += (int)$n[$i] * ($i % 2 ? 3 : 1);
        return $s % 10 === 0;
    }

    public static function isbn10(string $n): bool
    {
        if (!preg_match('/^\d{9}[\dX]$/', $n)) return false;
        $s = 0;
        for ($i = 0; $i < 10; $i++) $s += ($n[$i] === 'X' ? 10 : (int)$n[$i]) * (10 - $i);
        return $s % 11 === 0;
    }

    public static function to13(string $n10): string
    {
        $base = '978' . substr($n10, 0, 9);
        $s = 0;
        for ($i = 0; $i < 12; $i++) $s += (int)$base[$i] * ($i % 2 ? 3 : 1);
        return $base . ((10 - $s % 10) % 10);
    }

    private static function bestIsbn(string $copy, string $all): array
    {
        $cands = [];
        foreach ([$copy, $all] as $hay) {
            if ($hay === '') continue;
            if (!preg_match_all('/(?<![\dA-Za-z])((?:97[89][-\s]?)?(?:\d[-\s]?){9}[\dXx])(?![\dA-Za-z])/u', $hay, $m, PREG_OFFSET_CAPTURE)) continue;
            foreach ($m[1] as $hit) {
                $n = strtoupper(preg_replace('/[^0-9Xx]/', '', $hit[0]));
                $ok = (strlen($n) === 13 && self::isbn13($n)) || (strlen($n) === 10 && self::isbn10($n));
                if (!$ok) continue;
                if (strlen($n) === 10) $n = self::to13($n);
                $ctx = mb_substr($hay, max(0, $hit[1] - 30), 30);
                $score = (preg_match('/ISBN/iu', $ctx) ? 4 : 0) + ($hay === $copy ? 2 : 0);
                $cands[$n] = max($cands[$n] ?? 0, $score);
            }
        }
        if (!$cands) return ['', []];
        arsort($cands);
        return [array_key_first($cands), array_keys($cands)];
    }

    public static function isDegree(string $s): bool
    {
        $s = trim($s, " .,");
        if ($s === '') return true;
        if (preg_match('/^(?:Dr|Drs|Dra|Prof|Ir|H|Hj|PhD|Ph\.D|MM|MSi|MA|MSc|SE|SSi|ST|MT|SKom|MKom|SPd|MPd|SAg|MAg|SH|MH|SPsi|MPsi|SFarm|Apt|AMd|Lc|CA|CPA|CFA)(?:\.[A-Za-z]{1,4})*\.?$/u', $s)) return true;
        if (strpos($s, '.') !== false && mb_strlen($s) <= 12 && !preg_match('/\s[A-Z][a-z]{2,}/u', $s)) return true;
        return false;
    }

    private static function splitAuthors(string $raw): array
    {
        $raw = trim(preg_replace('/^(?:Oleh|Penulis|Pengarang)\s*[:\-]?\s*/iu', '', $raw));
        if ($raw === '') return [];
        if (preg_match('/\b(dkk\.?|et\s+al\.?)\s*$/iu', $raw)) $raw = preg_replace('/\b(dkk\.?|et\s+al\.?)\s*$/iu', '', $raw);
        $raw = preg_replace('/\s+(?:dan|&|DAN)\s+|;/', '|', $raw);
        $out = [];
        foreach (explode('|', $raw) as $part) {
            $cur = '';
            foreach (explode(',', $part) as $chunk) {
                $t = trim($chunk, " .,;:\t");
                if ($t === '' || preg_match('/^(dkk|et al)$/i', $t)) continue;
                if ($cur !== '' && (self::isDegree($t) || mb_strlen($t) < 3)) { $cur .= ', ' . $t; continue; }
                if ($cur !== '') $out[] = $cur;
                $cur = $t;
            }
            if ($cur !== '') $out[] = $cur;
        }
        $out = array_values(array_unique(array_filter(array_map(fn($a) => trim(preg_replace('/\s+/u', ' ', $a), " .,"), $out))));
        return array_slice($out, 0, 12);
    }

    private static function cleanPublisher(string $p): string
    {
        $p = preg_replace('/^(?:Penerbit|Diterbitkan\s+oleh|Published\s+by)\s*[:\-]?\s*/iu', '', $p);
        $p = preg_split('/\b(?:Jl\.|Jalan|No\.|Telp|Fax|Email|@|www\.|http)\b/iu', $p)[0];
        $p = trim(preg_replace('/\s+/u', ' ', $p), " .,;:-");
        if (mb_strlen($p) < 3 || mb_strlen($p) > 80) return '';
        if (preg_match('/^(Dicetak|Percetakan|Printing)/i', $p)) return '';
        if (!preg_match('/^[A-Z]/u', $p)) return '';
        if (preg_match('/(tanpa izin|tertulis|dilarang|memperbanyak|memfotokopi|undang|hak cipta|dilindungi|all rights|reserved|sebagian atau seluruh)/i', $p)) return '';
        foreach (self::COUNTRIES as $c) if (strcasecmp($p, $c) === 0) return '';
        if (self::isCity($p)) return '';
        if (self::isPersonLine($p)) return '';
        return $p;
    }

    private static function bottomPublisher(string $page1): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', trim($page1)))));
        foreach (array_slice($lines, -6) as $ln) {
            if (mb_strlen($ln) < 4 || mb_strlen($ln) > 50 || preg_match('/\d/', $ln)) continue;
            if (preg_match('/^(Dicetak|Percetakan|Printing)/i', $ln)) continue;
            foreach (self::COUNTRIES as $c) if (strcasecmp($ln, $c) === 0) continue 2;
            if (self::isCity($ln) || self::isPersonLine($ln)) continue;
            if (preg_match('/(?:' . self::PUB_WORDS . ')/iu', $ln)) return $ln;
            if (mb_strtoupper($ln) === $ln && preg_match('/^[A-Z][A-Z .&\'-]+$/u', $ln)) return $ln;
        }
        return '';
    }

    /** Scanner penerbit v2: brand ATAU Title-Case dekat alamat/baris hak cipta. */
    private static function scanPublisher(array $front, string $copy): string
    {
        $best = ''; $bestScore = -1;
        foreach (array_slice($front, 0, 8) as $text) {
            if ($text === '') continue;
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text))));
            $n = count($lines);
            for ($i = 0; $i < $n; $i++) {
                for ($len = 1; $len <= 3 && $i + $len <= $n; $len++) {
                    $cand = trim(implode(' ', array_slice($lines, $i, $len)));
                    $L = mb_strlen($cand);
                    if ($L < 4 || $L > 80) continue;
                    if (preg_match('/ISBN|www\.|@|http|^\d+$/iu', $cand)) continue;
                    if (preg_match('/^(Dicetak|Percetakan|Printing|Printed|Jl\.|Jln\.|Jalan|Alamat|All Rights Reserved|Hak Cipta Dilindungi|Dilindungi Undang|Undang-Undang)/i', $cand)) continue;
                    if (self::isPersonLine($cand)) continue;
                    $plain = preg_replace('/\s+/u', ' ', $cand);
                    $skip = false;
                    foreach (self::COUNTRIES as $c) if (strcasecmp($plain, $c) === 0) $skip = true;
                    if ($skip || self::isCity($plain)) continue;
                    $brand = preg_match('/\b(?:' . self::PUB_WORDS . ')\b/iu', $cand)
                        || preg_match('/^(?:PT\.?|CV\.?|Yayasan|Lembaga|Badan|Penerbit)\b/iu', $cand);
                    $titleCase = preg_match('/^[A-Z][A-Za-z\'-]+(?:\s[A-Z][A-Za-z\'-]+){1,5}$/u', $plain);
                    $preAddr = isset($lines[$i + $len]) && preg_match('/^(Jl\.|Jln\.|Jalan|Alamat|Komplek|Perum|Gedung)/i', $lines[$i + $len]);
                    $postCopy = false;
                    for ($k = max(0, $i - 2); $k < $i; $k++) {
                        if (preg_match('/©|Copyright|Hak\s+Cipta/iu', $lines[$k])) { $postCopy = true; break; }
                    }
                    if (!$brand && !($titleCase && ($preAddr || $postCopy))) continue;
                    $score = 1;
                    if ($brand) $score += 3;
                    if ($preAddr) $score += 4;
                    if ($postCopy) $score += 3;
                    if (preg_match('/^(?:PT\.?|CV\.?|Penerbit|Yayasan)\b/iu', $cand)) $score += 2;
                    for ($k = max(0, $i - 3); $k <= min($n - 1, $i + $len + 1); $k++) {
                        if (preg_match('/Penerbit|Diterbitkan|Dipublikasikan|Published/iu', $lines[$k])) { $score += 2; break; }
                    }
                    if ($text === $copy) $score += 2;
                    if ($len >= 2 && preg_match('/\b(?:' . implode('|', self::COUNTRIES) . ')$/iu', $cand)) $score += 1;
                    if ($score > $bestScore) { $bestScore = $score; $best = $cand; }
                }
            }
        }
        return $best;
    }

        /** Fallback penerbit dari domain website/email di halaman hak cipta (nama penerbit sering hanya logo). */
    private static function publisherFromDomain(string $hay): string
    {
        $map = [
            'mediapenerbitindonesia' => 'PT Media Penerbit Indonesia',
            'deepublish' => 'Deepublish',
            'sonpedia' => 'Sonpedia Publishing',
            'eurekamediaaksara' => 'CV. Eureka Media Aksara',
            'mediasainsindonesia' => 'CV. Media Sains Indonesia',
            'globaleksekutifteknologi' => 'PT. Global Eksekutif Teknologi',
            'yayasankitamenulis' => 'Yayasan Kita Menulis',
            'radjapublika' => 'CV. Radja Publika',
            'heipublishing' => 'CV. Hei Publishing',
            'pusdikra' => 'CV. Pusdikra Mitra Jaya',
        ];
        $hosts = [];
        if (preg_match_all('/(?:https?:\/\/|www\.)([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})/i', $hay, $m)) $hosts = array_merge($hosts, $m[1]);
        if (preg_match_all('/[a-z0-9._%+-]+@([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})/i', $hay, $m)) $hosts = array_merge($hosts, $m[1]);
        $skip = ['gmail','yahoo','hotmail','outlook','wordpress','wix','blogspot','google','facebook','instagram','twitter','youtube','tiktok','linkedin','wa','linktr'];
        foreach ($hosts as $h) {
            $h = strtolower(preg_replace('/^www\./', '', $h));
            $label = preg_replace('/\.(co\.id|or\.id|ac\.id|go\.id|com|id|net|org|info|biz|me|io)$/', '', $h);
            $label = preg_replace('/\.[a-z]{2,}$/', '', $label);
            $seg = explode('.', $label);
            $label = end($seg);
            if ($label === '' || in_array($label, $skip, true)) continue;
            if (isset($map[$label])) return $map[$label];
            $parts = preg_split('/(media|penerbit|press|publishing|publisher|pustaka|buku|sains|global|teknologi|aksara|eureka|sonpedia|deepublish|indonesia|nusantara|cendekia|literasi|laksana|jejak|samudra|bentang|serambi|mizan|gema|wacana|azka|tahta|bintang|cahaya|quantum|widya|insan|prenada|alfabeta|salemba|kencana|rajawali|erlangga|gramedia|yayasan|lembaga|badan|jaya|mitra|utama|sejahtera|abadi|karya|cipta|mandiri|persada|raya|sinar|harapan|bersama|edukasi)/i', $label, -1, PREG_SPLIT_DELIM_CAPTURE);
            $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
            if (count($parts) < 2) continue;
            $name = implode(' ', array_map(fn($x) => ucfirst(strtolower($x)), $parts));
            if (mb_strlen($name) >= 6 && mb_strlen($name) <= 60) return $name;
        }
        return '';
    }

    private static function isCity(string $s): bool
    {
        $s = trim(preg_replace('/^Kota\s+/iu', '', $s));
        foreach (self::CITIES as $c) if (strcasecmp($s, $c) === 0) return true;
        return false;
    }

    private static function canonCity(string $s): string
    {
        $s = trim(preg_replace('/^Kota\s+/iu', '', $s));
        foreach (self::CITIES as $c) if (strcasecmp($s, $c) === 0) return $c;
        return $s;
    }

    private static function cityNear(string $hay, string $pub): string
    {
        if ($hay === '') return '';
        $pubPos = ($pub !== '' && ($p = mb_stripos($hay, $pub)) !== false) ? $p : null;
        $best = ''; $bestScore = PHP_INT_MIN;
        foreach (self::CITIES as $c) {
            if (preg_match('/\b' . preg_quote($c, '/') . '\b/iu', $hay, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                $score = $pubPos !== null ? -abs($pos - $pubPos) : -$pos;
                if ($score > $bestScore) { $bestScore = $score; $best = $c; }
            }
        }
        return $best;
    }

    private static function normEdition(string $ed): string
    {
        $ed = trim($ed);
        if ($ed === '') return '';
        $map = ['Pertama'=>1,'Kedua'=>2,'Ketiga'=>3,'Keempat'=>4,'Kelima'=>5];
        if (isset($map[$ed])) return 'Ed. ' . $map[$ed];
        if (preg_match('/^(Revisi|Revised|Baru)$/i', $ed)) return 'Ed. Revisi';
        if (preg_match('/^[IVXivx]{1,5}$/', $ed)) {
            $r = ['I'=>1,'V'=>5,'X'=>10]; $u = strtoupper($ed); $n = 0; $prev = 0;
            for ($i = strlen($u) - 1; $i >= 0; $i--) { $v = $r[$u[$i]] ?? 0; $n += $v < $prev ? -$v : $v; $prev = $v; }
            return $n ? 'Ed. ' . $n : '';
        }
        return is_numeric($ed) ? 'Ed. ' . (int)$ed : '';
    }
}