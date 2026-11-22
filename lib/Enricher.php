<?php
namespace PDF2Biblio;

/** Perkayaan: OpenLibrary (ISBN + search judul) → Google Books; cover multi-sumber + cache ISBN. */
class Enricher
{
    private static array $cache = [];
    private static string $cacheDir = ''; // A4: cache persisten lintas request (tmp/api_*.cache)
    private const CACHE_TTL = 604800; // 7 hari

    public static function enrich(array &$d, bool $wantCover, string $tmp, string $token, string $pdfPath): array
    {
        self::$cacheDir = $tmp;
        $log = [];
        $d['_covers'] = [];
        $ol = null;
        if ($d['isbn'] !== '') {
            $ol = self::olData($d['isbn']);
            if ($ol) $log[] = 'OpenLibrary: data ISBN ditemukan.';
        }
        if (!$ol && $d['title'] !== '') {
            $ol = self::olSearch($d['title'], $d['author']);
            if ($ol) $log[] = 'OpenLibrary: cocok via judul/penulis.';
        }
        if ($ol) self::fill($d, $ol, 'OL');

        if ($d['publisher'] === '' || $d['place'] === '' || $d['isbn'] === '' || !$d['subjects'] || $d['year'] === '') {
            $gb = self::google($d);
            if ($gb) { self::fill($d, $gb, 'GB'); $log[] = 'Google Books: data dilengkapi.'; }
        }

        if ($wantCover) {
            $src = self::cover($d, $tmp, $token, $pdfPath);
            $log[] = $src ? "Cover: $src." : 'Cover: tidak ditemukan.';
        }
        $d['confidence'] = Extractor::score($d);
        return $log;
    }

    private static function fill(array &$d, array $x, string $src): void
    {
        foreach (['title','author','year','isbn','publisher','place','pages','language','classification'] as $k) {
            $localWeak = ($k === 'title' && ($d['sources']['title'] ?? '') === 'FILE');
            if (($d[$k] === '' || $localWeak) && !empty($x[$k])) { $d[$k] = (string)$x[$k]; $d['sources'][$k] = $src; }
        }
        if (!empty($x['subjects'])) {
            $d['subjects'] = array_slice(array_unique(array_merge((array)$d['subjects'], $x['subjects'])), 0, 8);
            if (($d['sources']['subjects'] ?? '') === '') $d['sources']['subjects'] = $src;
        }
        if (!empty($x['description']) && mb_strlen((string)$d['notes']) < 300) {
            $d['notes'] = trim($d['notes'] . "\n" . $x['description']);
            $d['sources']['notes'] = $src;
        }
        if (!empty($x['cover'])) $d['_covers'][] = $x['cover'];
        if ($d['collation'] === '' && !empty($d['pages'])) $d['collation'] = $d['pages'] . ' hlm.';
    }

    private static function year(string $s): string
    {
        return preg_match('/\b((?:19|20)\d{2})\b/', $s, $m) ? $m[1] : '';
    }

    private static function olData(string $isbn): ?array
    {
        $j = self::getJson('https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn . '&format=json&jscmd=data');
        $b = $j['ISBN:' . $isbn] ?? null;
        if (!$b) return null;
        return [
            'title' => trim(($b['title'] ?? '') . (isset($b['subtitle']) ? ': ' . $b['subtitle'] : '')),
            'author' => implode('; ', array_column($b['authors'] ?? [], 'name')),
            'publisher' => $b['publishers'][0]['name'] ?? '',
            'place' => $b['publish_places'][0]['name'] ?? '',
            'year' => self::year($b['publish_date'] ?? ''),
            'pages' => (string)($b['number_of_pages'] ?? ''),
            'subjects' => array_slice(array_column($b['subjects'] ?? [], 'name'), 0, 6),
            'isbn' => $isbn,
            'classification' => $b['classifications']['dewey_decimal_class'][0] ?? '',
            'cover' => ['https://covers.openlibrary.org/b/isbn/' . $isbn . '-L.jpg?default=false', 'OpenLibrary'],
        ];
    }

    private static function olSearch(string $title, string $author): ?array
    {
        $q = 'title=' . rawurlencode($title);
        if ($author !== '') $q .= '&author=' . rawurlencode(trim(explode(';', $author)[0]));
        $j = self::getJson('https://openlibrary.org/search.json?' . $q . '&limit=1&fields=title,author_name,first_publish_year,publisher,publish_place,isbn,number_of_pages_median,subject,cover_i,language');
        $doc = $j['docs'][0] ?? null;
        if (!$doc) return null;
        $isbn = '';
        foreach (($doc['isbn'] ?? []) as $i) {
            $i = preg_replace('/[^0-9Xx]/', '', strtoupper($i));
            if (strlen($i) === 13 && Extractor::isbn13($i)) { $isbn = $i; break; }
            if (strlen($i) === 10 && Extractor::isbn10($i) && $isbn === '') $isbn = Extractor::to13($i);
        }
        $cover = null;
        if (!empty($doc['cover_i'])) $cover = ['https://covers.openlibrary.org/b/id/' . $doc['cover_i'] . '-L.jpg', 'OpenLibrary'];
        elseif ($isbn !== '') $cover = ['https://covers.openlibrary.org/b/isbn/' . $isbn . '-L.jpg?default=false', 'OpenLibrary'];
        $lang = $doc['language'][0] ?? '';
        return [
            'title' => $doc['title'] ?? '',
            'author' => implode('; ', array_slice($doc['author_name'] ?? [], 0, 6)),
            'publisher' => $doc['publisher'][0] ?? '',
            'place' => $doc['publish_place'][0] ?? '',
            'year' => (string)($doc['first_publish_year'] ?? ''),
            'pages' => (string)($doc['number_of_pages_median'] ?? ''),
            'subjects' => array_slice($doc['subject'] ?? [], 0, 6),
            'isbn' => $isbn,
            'language' => ['ind'=>'id','eng'=>'en'][$lang] ?? '',
            'cover' => $cover,
        ];
    }

    private static function google(array $d): ?array
    {
        $q = $d['isbn'] !== '' ? 'isbn:' . $d['isbn']
            : 'intitle:' . rawurlencode($d['title']) . ($d['author'] !== '' ? '+inauthor:' . rawurlencode(trim(explode(';', $d['author'])[0])) : '');
        $j = self::getJson('https://www.googleapis.com/books/v1/volumes?q=' . $q . '&maxResults=1');
        $v = $j['items'][0]['volumeInfo'] ?? null;
        if (!$v) return null;
        $isbn = '';
        foreach (($v['industryIdentifiers'] ?? []) as $id) {
            if (($id['type'] ?? '') === 'ISBN_13') { $isbn = $id['identifier']; break; }
            if (($id['type'] ?? '') === 'ISBN_10' && $isbn === '') $isbn = Extractor::to13(strtoupper($id['identifier']));
        }
        $thumb = $v['imageLinks']['thumbnail'] ?? ($v['imageLinks']['smallThumbnail'] ?? '');
        if ($thumb !== '') {
            $thumb = str_replace('http://', 'https://', $thumb);
            $thumb = preg_replace('/zoom=\d+/', 'zoom=1', $thumb);
            $thumb = str_replace('&edge=curl', '', $thumb);
        }
        return [
            'title' => trim(($v['title'] ?? '') . (isset($v['subtitle']) ? ': ' . $v['subtitle'] : '')),
            'author' => implode('; ', $v['authors'] ?? []),
            'publisher' => $v['publisher'] ?? '',
            'year' => self::year($v['publishedDate'] ?? ''),
            'pages' => (string)($v['pageCount'] ?? ''),
            'subjects' => $v['categories'] ?? [],
            'description' => trim(strip_tags($v['description'] ?? '')),
            'isbn' => $isbn,
            'language' => ['id'=>'id','en'=>'en'][$v['language'] ?? ''] ?? '',
            'cover' => $thumb !== '' ? [$thumb, 'Google Books'] : null,
        ];
    }

    private static function cover(array $d, string $tmp, string $token, string $pdfPath): string
    {
        $isbn = preg_replace('/[^0-9Xx]/', '', (string)($d['isbn'] ?? ''));
        // B6: cache cover per ISBN — reuse antar impor, hemat bandwidth, cover konsisten.
        if ($isbn !== '' && defined('SB')) {
            foreach (['jpg','png','webp','gif'] as $ext) {
                $p = rtrim(SB, '/') . '/images/docs/p2b_isbn_' . $isbn . '.' . $ext;
                if (is_file($p)) {
                    $blob = (string)@file_get_contents($p);
                    if (self::saveImg($blob, $tmp, $token)) return 'Cache ISBN (images/docs)';
                }
            }
        }
        // 1) JPEG tertanam di PDF = sampul asli (cara mesin lama, terbukti works)
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
        // 3) API covers
        foreach ($d['_covers'] as $c) {
            if (!is_array($c) || empty($c[0])) continue;
            $body = self::http($c[0]);
            if ($body !== null && self::saveImg($body, $tmp, $token)) { self::writeIsbnCache($isbn, $body); return $c[1]; }
        }
        return '';
    }

    /** B6: tulis cache cover per ISBN ke images/docs (dipakai ulang impor berikutnya). */
    private static function writeIsbnCache(string $isbn, string $blob): void
    {
        if ($isbn === '' || !defined('SB')) return;
        $info = @getimagesizefromstring($blob);
        if (!$info) return;
        $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$info['mime']] ?? '';
        if ($ext === '') return;
        $dir = rtrim(SB, '/') . '/images/docs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) @file_put_contents($dir . '/p2b_isbn_' . $isbn . '.' . $ext, $blob);
    }

    /** Ekstrak JPEG DCTDecode (sampul) dari stream PDF — port mesin lama anti-crash. */
    private static function embeddedCover(string $pdfPath): ?string
    {
        $raw = @file_get_contents($pdfPath);
        if ($raw === false || strlen($raw) < 8) return null;
        $best = null; $bestScore = 0; $found = 0; $blocks = 0; $offset = 0;
        while ($blocks < 1500 && $found < 12 && preg_match('/stream\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE, $offset)) {
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
            if ($ratio >= 1.0 && $ratio <= 1.9) $score *= 2; // proporsi sampul buku
            if ($score > $bestScore) { $bestScore = $score; $best = $jpg; }
        }
        unset($raw);
        return $best;
    }

    private static function saveImg(string $blob, string $tmp, string $token): bool
    {
        if (strlen($blob) < 1000) return false;
        $info = @getimagesizefromstring($blob);
        if (!$info || !in_array($info['mime'], ['image/jpeg','image/png','image/webp','image/gif'])) return false;
        return (bool)@file_put_contents($tmp . '/' . $token . '.cover', $blob);
    }

    private static function getJson(string $url): ?array
    {
        $b = self::http($url);
        if ($b === null) return null;
        $j = json_decode($b, true);
        return is_array($j) ? $j : null;
    }

    private static function http(string $url): ?string
    {
        if (isset(self::$cache[$url])) return self::$cache[$url];
        // A4: cache persisten 7 hari (JSON API maupun blob cover) → impor ulang cepat & tahan offline.
        $key = self::$cacheDir !== '' ? self::$cacheDir . '/api_' . sha1($url) . '.cache' : '';
        if ($key !== '' && is_file($key) && filemtime($key) > time() - self::CACHE_TTL) {
            $c = @file_get_contents($key);
            if ($c !== false && $c !== '') { self::$cache[$url] = $c; return $c; }
        }
        usleep(150000); // sopan terhadap API
        $body = null;
        if (function_exists('curl_init')) {
            foreach ([true, false] as $verify) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                    CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_USERAGENT => 'PDF2Biblio/2.0 (SLiMS; +https://github.com/yolislibmanmof/pdf2biblio)',
                    CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                ]);
                $b = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_errno($ch);
                curl_close($ch);
                if ($b !== false && $code >= 200 && $code < 300) { $body = $b; break; }
                if (!in_array($err, [60, 77, 58, 59], true)) break; // retry hanya utk masalah CA/SSL
            }
        } elseif (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true,
                'header' => "User-Agent: PDF2Biblio/2.0\r\n"], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $b = @file_get_contents($url, false, $ctx);
            if ($b !== false) $body = $b;
        }
        if ($body !== null && $key !== '') @file_put_contents($key, $body);
        self::$cache[$url] = $body;
        return $body;
    }
}