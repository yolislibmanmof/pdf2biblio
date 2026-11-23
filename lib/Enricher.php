<?php
namespace PDF2Biblio;

/**
 * PDF2Biblio — perkayaan metadata & cover (v2.5.0 FINAL).
 * OpenLibrary (ISBN → judul) → Google Books; cover multi-sumber + cache ISBN.
 *
 * Perubahan v2.5.0:
 *  - Uji kemiripan judul sebelum menerima hasil pencarian (cegah buku asing menimpa).
 *  - CA bundle dicari dulu; verifikasi SSL tidak lagi dimatikan diam-diam.
 *  - Cache kegagalan diingat (array_key_exists, bukan isset).
 *  - Pola stream tidak lagi match "endstream".
 *  - Pesan kegagalan API ditampilkan di log UI.
 */
class Enricher
{
    private static array $cache = [];
    private static string $cacheDir = '';   // cache persisten tmp/api_*.cache
    private static string $httpErr = '';
    private const CACHE_TTL = 604800;       // 7 hari
    private const MIN_SIM   = 70;           // ambang kemiripan judul (%)

    public static function enrich(array &$d, bool $wantCover, string $tmp, string $token, string $pdfPath): array
    {
        self::$cacheDir = $tmp;
        self::$httpErr = '';
        $log = [];
        $d['_covers'] = [];
        $ol = null;
        if (($d['isbn'] ?? '') !== '') {
            $ol = self::olData((string)$d['isbn']);
            if ($ol) $log[] = 'OpenLibrary: data ISBN ditemukan.';
        }
        if (!$ol && ($d['title'] ?? '') !== '') {
            $ol = self::olSearch((string)$d['title'], (string)($d['author'] ?? ''));
            if ($ol) $log[] = 'OpenLibrary: cocok via judul/penulis (kemiripan ≥ ' . self::MIN_SIM . '%).';
        }
        if ($ol) self::fill($d, $ol, 'OL');

        if (($d['publisher'] ?? '') === '' || ($d['place'] ?? '') === '' || ($d['isbn'] ?? '') === ''
            || empty($d['subjects']) || ($d['year'] ?? '') === '') {
            $gb = self::google($d);
            if ($gb) { self::fill($d, $gb, 'GB'); $log[] = 'Google Books: data dilengkapi.'; }
        }

        if ($wantCover) {
            $src = self::cover($d, $tmp, $token, $pdfPath);
            $log[] = $src ? "Cover: $src." : 'Cover: tidak ditemukan.';
        }
        if (self::$httpErr !== '') { $log[] = self::$httpErr; self::$httpErr = ''; }
        $d['confidence'] = Extractor::score($d);
        return $log;
    }

    private static function fill(array &$d, array $x, string $src): void
    {
        foreach (['title','author','year','isbn','publisher','place','pages','language','classification'] as $k) {
            $localWeak = ($k === 'title' && ($d['sources']['title'] ?? '') === 'FILE');
            if ((($d[$k] ?? '') === '' || $localWeak) && !empty($x[$k])) {
                $d[$k] = (string)$x[$k];
                $d['sources'][$k] = $src;
            }
        }
        if (!empty($x['subjects'])) {
            $d['subjects'] = array_slice(array_unique(array_merge((array)($d['subjects'] ?? []), (array)$x['subjects'])), 0, 8);
            if (($d['sources']['subjects'] ?? '') === '') $d['sources']['subjects'] = $src;
        }
        if (!empty($x['description']) && mb_strlen((string)($d['notes'] ?? '')) < 300) {
            $d['notes'] = trim(($d['notes'] ?? '') . "\n" . mb_substr((string)$x['description'], 0, 1200));
            $d['sources']['notes'] = $src;
        }
        if (!empty($x['cover'])) $d['_covers'][] = $x['cover'];
        if (($d['collation'] ?? '') === '' && !empty($d['pages'])) $d['collation'] = $d['pages'] . ' hlm.';
    }

    /** Kemiripan judul 0–100, tahan huruf besar/kecil. */
    private static function sim(string $a, string $b): float
    {
        $a = mb_strtolower(trim($a)); $b = mb_strtolower(trim($b));
        if ($a === '' || $b === '') return 0.0;
        $p = 0.0;
        similar_text($a, $b, $p);
        return (float)$p;
    }

    private static function year(string $s): string
    {
        return preg_match('/\b((?:19|20)\d{2})\b/', $s, $m) ? $m[1] : '';
    }

    private static function olData(string $isbn): ?array
    {
        $j = self::getJson('https://openlibrary.org/api/books?bibkeys=ISBN:' . rawurlencode($isbn) . '&format=json&jscmd=data');
        $b = $j['ISBN:' . $isbn] ?? null;
        if (!is_array($b)) return null;
        return [
            'title' => trim((string)($b['title'] ?? '') . (isset($b['subtitle']) ? ': ' . $b['subtitle'] : '')),
            'author' => implode('; ', array_column((array)($b['authors'] ?? []), 'name')),
            'publisher' => (string)($b['publishers'][0]['name'] ?? ''),
            'place' => (string)($b['publish_places'][0]['name'] ?? ''),
            'year' => self::year((string)($b['publish_date'] ?? '')),
            'pages' => (string)($b['number_of_pages'] ?? ''),
            'subjects' => array_slice(array_column((array)($b['subjects'] ?? []), 'name'), 0, 6),
            'isbn' => $isbn,
            'classification' => (string)($b['classifications']['dewey_decimal_class'][0] ?? ''),
            'cover' => ['https://covers.openlibrary.org/b/isbn/' . $isbn . '-L.jpg?default=false', 'OpenLibrary'],
        ];
    }

    private static function olSearch(string $title, string $author): ?array
    {
        $q = 'title=' . rawurlencode($title);
        if ($author !== '') $q .= '&author=' . rawurlencode(trim(explode(';', $author)[0]));
        $j = self::getJson('https://openlibrary.org/search.json?' . $q . '&limit=1&fields=title,author_name,first_publish_year,publisher,publish_place,isbn,number_of_pages_median,subject,cover_i,language');
        $doc = $j['docs'][0] ?? null;
        if (!is_array($doc)) return null;

        // WAJIB: buku yang sama? Tanpa ini judul lokal bisa ditimpa buku asing.
        if (self::sim($title, (string)($doc['title'] ?? '')) < self::MIN_SIM) return null;

        $isbn = '';
        foreach ((array)($doc['isbn'] ?? []) as $i) {
            $i = strtoupper((string)preg_replace('/[^0-9Xx]/', '', (string)$i));
            if (strlen($i) === 13 && Extractor::isbn13($i)) { $isbn = $i; break; }
            if (strlen($i) === 10 && Extractor::isbn10($i) && $isbn === '') $isbn = Extractor::to13($i);
        }
        $cover = null;
        if (!empty($doc['cover_i'])) $cover = ['https://covers.openlibrary.org/b/id/' . $doc['cover_i'] . '-L.jpg', 'OpenLibrary'];
        elseif ($isbn !== '') $cover = ['https://covers.openlibrary.org/b/isbn/' . $isbn . '-L.jpg?default=false', 'OpenLibrary'];
        $lang = (string)($doc['language'][0] ?? '');
        return [
            'title' => (string)($doc['title'] ?? ''),
            'author' => implode('; ', array_slice((array)($doc['author_name'] ?? []), 0, 6)),
            'publisher' => (string)($doc['publisher'][0] ?? ''),
            'place' => (string)($doc['publish_place'][0] ?? ''),
            'year' => (string)($doc['first_publish_year'] ?? ''),
            'pages' => (string)($doc['number_of_pages_median'] ?? ''),
            'subjects' => array_slice((array)($doc['subject'] ?? []), 0, 6),
            'isbn' => $isbn,
            'language' => ['ind'=>'id','eng'=>'en'][$lang] ?? '',
            'cover' => $cover,
        ];
    }

    private static function google(array $d): ?array
    {
        $byIsbn = ($d['isbn'] ?? '') !== '';
        $q = $byIsbn ? 'isbn:' . $d['isbn']
            : 'intitle:' . rawurlencode((string)$d['title'])
              . (($d['author'] ?? '') !== '' ? '+inauthor:' . rawurlencode(trim(explode(';', (string)$d['author'])[0])) : '');
        $j = self::getJson('https://www.googleapis.com/books/v1/volumes?q=' . $q . '&maxResults=1');
        $v = $j['items'][0]['volumeInfo'] ?? null;
        if (!is_array($v)) return null;

        // Pencarian berdasarkan ISBN sudah otoritatif; berdasarkan judul harus diuji.
        if (!$byIsbn && self::sim((string)($d['title'] ?? ''), (string)($v['title'] ?? '')) < self::MIN_SIM) return null;

        $isbn = '';
        foreach ((array)($v['industryIdentifiers'] ?? []) as $id) {
            if (($id['type'] ?? '') === 'ISBN_13') { $isbn = (string)$id['identifier']; break; }
            if (($id['type'] ?? '') === 'ISBN_10' && $isbn === '') $isbn = Extractor::to13(strtoupper((string)$id['identifier']));
        }
        $thumb = (string)($v['imageLinks']['thumbnail'] ?? ($v['imageLinks']['smallThumbnail'] ?? ''));
        if ($thumb !== '') {
            $thumb = str_replace('http://', 'https://', $thumb);
            $thumb = (string)preg_replace('/zoom=\d+/', 'zoom=1', $thumb);
            $thumb = str_replace('&edge=curl', '', $thumb);
        }
        return [
            'title' => trim((string)($v['title'] ?? '') . (isset($v['subtitle']) ? ': ' . $v['subtitle'] : '')),
            'author' => implode('; ', (array)($v['authors'] ?? [])),
            'publisher' => (string)($v['publisher'] ?? ''),
            'year' => self::year((string)($v['publishedDate'] ?? '')),
            'pages' => (string)($v['pageCount'] ?? ''),
            'subjects' => (array)($v['categories'] ?? []),
            'description' => trim(strip_tags((string)($v['description'] ?? ''))),
            'isbn' => $isbn,
            'language' => ['id'=>'id','en'=>'en'][(string)($v['language'] ?? '')] ?? '',
            'cover' => $thumb !== '' ? [$thumb, 'Google Books'] : null,
        ];
    }

    private static function cover(array $d, string $tmp, string $token, string $pdfPath): string
    {
        $isbn = strtoupper((string)preg_replace('/[^0-9Xx]/', '', (string)($d['isbn'] ?? '')));
        // Cache cover per ISBN — reuse antar impor.
        if ($isbn !== '' && defined('SB')) {
            foreach (['jpg','png','webp','gif'] as $ext) {
                $p = rtrim(SB, '/') . '/images/docs/p2b_isbn_' . $isbn . '.' . $ext;
                if (is_file($p) && is_readable($p)) {
                    $blob = (string)@file_get_contents($p);
                    if (self::saveImg($blob, $tmp, $token)) return 'Cache ISBN (images/docs)';
                }
            }
        }
        // 1) JPEG tertanam di PDF = sampul asli
        if (is_file($pdfPath) && (int)@filesize($pdfPath) <= 40 * 1024 * 1024) {
            $jpg = self::embeddedCover($pdfPath);
            if ($jpg !== null && self::saveImg($jpg, $tmp, $token)) { self::writeIsbnCache($isbn, $jpg); return 'PDF (JPEG tertanam)'; }
        }
        // 2) Render halaman 1 (butuh Imagick + Ghostscript; opsional)
        if (extension_loaded('imagick') && is_file($pdfPath)) {
            try {
                $im = new \Imagick();
                $im->setResolution(120, 120);
                $im->readImage($pdfPath . '[0]');
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(85);
                $blob = $im->getImageBlob();
                $im->clear();
                if (self::saveImg($blob, $tmp, $token)) { self::writeIsbnCache($isbn, $blob); return 'PDF (render sampul)'; }
            } catch (\Throwable $e) {}
        }
        // 3) Cover dari API
        foreach ((array)($d['_covers'] ?? []) as $c) {
            if (!is_array($c) || empty($c[0])) continue;
            $body = self::http((string)$c[0]);
            if ($body !== null && self::saveImg($body, $tmp, $token)) { self::writeIsbnCache($isbn, $body); return (string)($c[1] ?? 'API'); }
        }
        return '';
    }

    private static function writeIsbnCache(string $isbn, string $blob): void
    {
        if ($isbn === '' || !defined('SB')) return;
        $info = @getimagesizefromstring($blob);
        if (!$info) return;
        $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$info['mime'] ?? ''] ?? '';
        if ($ext === '') return;
        $dir = rtrim(SB, '/') . '/images/docs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) @file_put_contents($dir . '/p2b_isbn_' . $isbn . '.' . $ext, $blob);
    }

    /** Ekstrak JPEG DCTDecode (sampul) dari stream PDF. */
    private static function embeddedCover(string $pdfPath): ?string
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) return null;
        $raw = @file_get_contents($pdfPath);
        if ($raw === false || strlen($raw) < 8) return null;
        $best = null; $bestScore = 0; $found = 0; $blocks = 0; $offset = 0;
        while ($blocks < 1500 && $found < 12 && preg_match('/(?<!end)stream\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $end = strpos($raw, 'endstream', $start);
            if ($end === false) break;
            $dict = substr($raw, max(0, $m[0][1] - 600), min(600, $m[0][1]));
            $offset = $end + 9; $blocks++;
            if (strpos($dict, '/Image') === false || strpos($dict, 'DCTDecode') === false) continue;
            $data = substr($raw, $start, $end - $start);
            $pos = strpos($data, "\xFF\xD8");
            if ($pos === false) continue;
            $eoi = strrpos($data, "\xFF\xD9");
            $jpg = $eoi !== false ? substr($data, $pos, $eoi - $pos + 2) : substr($data, $pos);
            $info = @getimagesizefromstring($jpg);
            if (!$info || empty($info[0]) || empty($info[1])) continue;
            $w = (int)$info[0]; $h = (int)$info[1];
            if ($w < 150 || $h < 150) continue;
            $found++;
            $ratio = $h / max(1, $w);
            $score = $w * $h;
            if ($ratio >= 1.0 && $ratio <= 1.9) $score *= 2;
            if ($score > $bestScore) { $bestScore = $score; $best = $jpg; }
        }
        unset($raw);
        return $best;
    }

    private static function saveImg(string $blob, string $tmp, string $token): bool
    {
        if (strlen($blob) < 1000) return false;
        $info = @getimagesizefromstring($blob);
        if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg','image/png','image/webp','image/gif'], true)) return false;
        return (bool)@file_put_contents($tmp . '/' . $token . '.cover', $blob);
    }

    private static function getJson(string $url): ?array
    {
        $b = self::http($url);
        if ($b === null || $b === '') return null;
        $j = json_decode($b, true);
        return is_array($j) ? $j : null;
    }

    /** Cari berkas CA bundle yang tersedia (Laragon/XAMPP sering belum set curl.cainfo). */
    private static function caFile(): string
    {
        foreach ([(string)ini_get('curl.cainfo'), (string)ini_get('openssl.cafile')] as $p) {
            if ($p !== '' && is_file($p)) return $p;
        }
        $local = dirname(__DIR__) . '/cacert.pem';
        if (is_file($local)) return $local;
        foreach ([
            'C:\\laragon\\etc\\ssl\\cacert.pem',
            'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',
            'C:\\xampp\\php\\extras\\ssl\\cacert.pem',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
        ] as $p) { if (is_file($p)) return $p; }
        return '';
    }

    private static function http(string $url): ?string
    {
        if (array_key_exists($url, self::$cache)) return self::$cache[$url];
        $key = self::$cacheDir !== '' ? self::$cacheDir . '/api_' . sha1($url) . '.cache' : '';
        if ($key !== '' && is_file($key) && (int)@filemtime($key) > time() - self::CACHE_TTL) {
            $c = (string)@file_get_contents($key);
            if ($c !== '') { self::$cache[$url] = $c; return $c; }
        }
        usleep(150000); // sopan terhadap API
        $body = null;
        if (function_exists('curl_init')) {
            $ca = self::caFile();
            // Tanpa CA: coba terverifikasi dulu, baru fallback + peringatan terang.
            foreach (($ca !== '' ? [true] : [true, false]) as $verify) {
                $ch = curl_init($url);
                $opt = [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                    CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_USERAGENT => 'PDF2Biblio/2.5 (SLiMS; +https://github.com/yolislibmanmof/pdf2biblio)',
                    CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                ];
                if ($verify && $ca !== '') $opt[CURLOPT_CAINFO] = $ca;
                curl_setopt_array($ch, $opt);
                $b = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_errno($ch); $msg = curl_error($ch);
                curl_close($ch);
                if ($b !== false && $code >= 200 && $code < 300) { $body = $b; break; }
                if (!in_array($err, [60, 77, 58, 59], true)) {
                    if ($err !== 0) self::$httpErr = 'Perkayaan: gagal hubungi API (' . $msg . ').';
                    break;
                }
                if ($verify) self::$httpErr = 'Perkayaan: CA sertifikat tidak ditemukan. Set curl.cainfo di php.ini atau taruh cacert.pem di folder plugin.';
            }
        } elseif (ini_get('allow_url_fopen')) {
            $ca = self::caFile();
            $ssl = $ca !== ''
                ? ['cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true]
                : ['verify_peer' => false, 'verify_peer_name' => false];
            if ($ca === '') self::$httpErr = 'Perkayaan: CA sertifikat tidak ditemukan — koneksi API tanpa verifikasi SSL.';
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true,
                'header' => "User-Agent: PDF2Biblio/2.5\r\n"], 'ssl' => $ssl]);
            $b = @file_get_contents($url, false, $ctx);
            if ($b !== false) $body = $b;
        }
        if ($body !== null && $key !== '') @file_put_contents($key, $body);
        self::$cache[$url] = $body;
        return $body;
    }
}