<?php
namespace PDF2Biblio;

/** Penyimpanan relasional penuh ke SLiMS — INSERT dinamis (kompatibel skema lama/baru). */
class Importer
{
    private static array $cols = [];

    private static function cols($dbs, string $t): array
    {
        if (!isset(self::$cols[$t])) {
            self::$cols[$t] = [];
            $r = @$dbs->query("SHOW COLUMNS FROM `$t`");
            if ($r) while ($row = $r->fetch_assoc()) self::$cols[$t][] = $row['Field'];
        }
        return self::$cols[$t];
    }

    private static function e($dbs, $v): string
    {
        return method_exists($dbs, 'real_escape_string') ? $dbs->real_escape_string((string)$v) : $dbs->escape_string((string)$v);
    }

    private static function one($dbs, string $sql): ?array
    {
        $r = @$dbs->query($sql);
        return ($r && $r->num_rows) ? $r->fetch_assoc() : null;
    }

    private static function ins($dbs, string $t, array $data): int
    {
        $c = self::cols($dbs, $t);
        if (!$c) return 0;
        $data = array_intersect_key($data, array_flip($c));
        $cols = []; $vals = [];
        foreach ($data as $k => $v) {
            if ($v === null || $v === '') continue;
            $cols[] = "`$k`"; $vals[] = "'" . self::e($dbs, $v) . "'";
        }
        if (!$cols) return 0;
        return @$dbs->query("INSERT INTO `$t` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")") ? (int)$dbs->insert_id : 0;
    }

    private static function master($dbs, string $t, string $idc, string $nc, string $name, array $extra = []): int
    {
        $name = trim($name);
        if ($name === '' || !self::cols($dbs, $t)) return 0;
        $row = self::one($dbs, "SELECT `$idc` FROM `$t` WHERE `$nc`='" . self::e($dbs, $name) . "' LIMIT 1");
        if ($row) return (int)$row[$idc];
        $now = date('Y-m-d H:i:s');
        return self::ins($dbs, $t, array_merge([$nc => $name, 'input_date' => $now, 'last_update' => $now], $extra));
    }

    private static function lookup($dbs, string $t, string $idc, string $base, array $names): int
    {
        $c = self::cols($dbs, $t);
        if (!$c) return 0;
        $nc = null;
        foreach ([$base . '_name', $base, 'name'] as $cand) if (in_array($cand, $c)) { $nc = $cand; break; }
        if (!$nc) return 0;
        foreach ($names as $n) {
            $row = self::one($dbs, "SELECT `$idc` FROM `$t` WHERE `$nc` LIKE '%" . self::e($dbs, $n) . "%' LIMIT 1");
            if ($row) return (int)$row[$idc];
        }
        return 0;
    }

    private static function langId($dbs, string $code): string
    {
        $code = strtolower(trim($code)) ?: 'id';
        $code = ['ind'=>'id','eng'=>'en'][$code] ?? substr($code, 0, 5);
        $row = self::one($dbs, "SELECT language_id FROM mst_language WHERE language_id='" . self::e($dbs, $code) . "' LIMIT 1");
        if ($row) return $row['language_id'];
        $row = self::one($dbs, "SELECT language_id FROM mst_language WHERE language_name LIKE '%indonesia%' LIMIT 1");
        return $row ? $row['language_id'] : 'en';
    }

    private static function slug(string $t): string
    {
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($t));
        return mb_substr(trim($s, '-'), 0, 60) ?: 'buku';
    }

    /** Webroot stabil (SWB SLiMS berubah tergantung script yang berjalan). */
    public static function webroot(): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $root = preg_replace('#/(admin|plugins)/.*$#', '/', $script);
        if (!is_string($root) || $root === '') $root = '/';
        return rtrim($root, '/') . '/';
    }

    public static function save(array $r, array $o, $dbs, int $uid): array
    {
        $now = date('Y-m-d H:i:s');
        $title = trim($r['title'] ?? '');
        if ($title === '') return ['ok'=>false, 'msg'=>'Judul kosong.'];
        $isbn = strtoupper(preg_replace('/[^0-9Xx]/', '', $r['isbn'] ?? ''));

        if (!empty($o['skip_dup'])) {
            $dup = null;
            if ($isbn !== '') $dup = self::one($dbs, "SELECT biblio_id FROM biblio WHERE isbn_issn='" . self::e($dbs, $isbn) . "' LIMIT 1");
            if (!$dup && ($r['year'] ?? '') !== '')
                $dup = self::one($dbs, "SELECT biblio_id FROM biblio WHERE LOWER(title)='" . self::e($dbs, mb_strtolower($title)) . "' AND publish_year='" . self::e($dbs, $r['year']) . "' LIMIT 1");
            if ($dup) return ['ok'=>true, 'dup'=>true, 'id'=>(int)$dup['biblio_id'], 'msg'=>'Duplikat — dilewati (#' . $dup['biblio_id'] . ').'];
        }

        $pubId = self::master($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $r['publisher'] ?? '');
        $placeId = self::master($dbs, 'mst_place', 'place_id', 'place_name', $r['place'] ?? '');
        $attach = !empty($o['attach']) && !empty($r['_pdf']) && is_file($r['_pdf']);

        $data = [
            'title' => $title, 'edition' => $r['edition'] ?? '', 'isbn_issn' => $isbn,
            'publisher_id' => $pubId ?: null, 'publish_place_id' => $placeId ?: null,
            'publish_year' => $r['year'] ?? '',
            'collation' => ($r['collation'] ?? '') !== '' ? $r['collation'] : (($r['pages'] ?? '') !== '' ? $r['pages'] . ' hlm.' : ''),
            'series_title' => $r['series'] ?? '', 'call_number' => $r['call_number'] ?? '',
            'classification' => $r['classification'] ?? '', 'language_id' => self::langId($dbs, $r['language'] ?? 'id'),
            'gmd_id' => self::lookup($dbs, 'mst_gmd', 'gmd_id', 'gmd', ['teks', 'text']) ?: null,
            'content_type_id' => self::lookup($dbs, 'mst_content_type', 'content_type_id', 'content_type', ['text', 'teks']) ?: null,
            'media_type_id' => self::lookup($dbs, 'mst_media_type', 'media_type_id', 'media_type', $attach ? ['computer', 'komputer'] : ['unmediated']) ?: null,
            'carrier_type_id' => self::lookup($dbs, 'mst_carrier_type', 'carrier_type_id', 'carrier_type', $attach ? ['online resource', 'sumber daya online'] : ['volume']) ?: null,
            'notes' => trim($r['notes'] ?? ''), 'spec_detail_info' => trim($r['notes'] ?? ''),
            'opac_hide' => 0, 'promoted' => 0, 'uid' => $uid, 'input_date' => $now, 'last_update' => $now,
        ];
        if (in_array('notes', self::cols($dbs, 'biblio'))) unset($data['spec_detail_info']);
        else unset($data['notes']);

        $bid = self::ins($dbs, 'biblio', $data);
        if (!$bid) return ['ok'=>false, 'msg'=>'Gagal insert biblio: ' . $dbs->error];

        $level = 1;
        foreach (array_slice(array_filter(array_map('trim', explode(';', $r['author'] ?? ''))), 0, 12) as $n) {
            $org = preg_match('/^(Tim|Panitia|Lembaga|Badan|Kementerian|Universitas|Institut|Fakultas|Jurusan|Program Studi|Penerbit|PT\b|CV\b)/iu', $n);
            $aid = self::master($dbs, 'mst_author', 'author_id', 'author_name', $n, ['authority_type' => $org ? 'o' : 'p', 'author_year' => '', 'auth_list' => '']);
            if ($aid) self::ins($dbs, 'biblio_author', ['biblio_id'=>$bid, 'author_id'=>$aid, 'level'=>$level, 'input_date'=>$now, 'last_update'=>$now]);
            $level = 2;
        }

        $subs = is_array($r['subjects'] ?? null) ? $r['subjects'] : explode(',', (string)($r['subjects'] ?? ''));
        foreach (array_slice(array_filter(array_map('trim', $subs)), 0, 8) as $s) {
            $tid = self::master($dbs, 'mst_topic', 'topic_id', 'topic', mb_substr($s, 0, 60), ['topic_type' => 't']);
            if ($tid) self::ins($dbs, 'biblio_topic', ['biblio_id'=>$bid, 'topic_id'=>$tid, 'level'=>1, 'input_date'=>$now, 'last_update'=>$now]);
        }

        if ($attach) {
            $dir = rtrim(SB, '/') . '/repository';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            if (is_dir($dir) && is_writable($dir)) {
                $slug = self::slug($title); $tk = substr($r['token'] ?? 'x', 0, 6);
                $name = $slug . '_' . $tk . '.pdf'; $i = 1;
                while (file_exists($dir . '/' . $name)) $name = $slug . '_' . $tk . '_' . $i++ . '.pdf';
                if (@copy($r['_pdf'], $dir . '/' . $name)) {
                    $fid = self::ins($dbs, 'files', ['file_title'=>$title, 'file_name'=>$name, 'file_url'=>'repository/' . $name,
                        'file_dir'=>'repository', 'mime_type'=>'application/pdf', 'file_desc'=>'PDF buku — impor PDF2Biblio',
                        'uploader_id'=>$uid, 'input_date'=>$now, 'last_update'=>$now]);
                    if ($fid) self::ins($dbs, 'biblio_attachment', ['biblio_id'=>$bid, 'file_id'=>$fid, 'access_type'=>'public', 'access_limit'=>'']);
                }
            }
        }

        if (!empty($r['_cover']) && is_file($r['_cover'])) {
            $blob = (string)@file_get_contents($r['_cover']);
            $info = @getimagesizefromstring($blob);
            if ($info) {
                $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$info['mime']] ?? 'jpg';
                $cdir = rtrim(SB, '/') . '/images/docs';
                if (!is_dir($cdir)) @mkdir($cdir, 0775, true);
                $cname = 'p2b_' . $bid . '_' . substr($r['token'] ?? 'x', 0, 6) . '.' . $ext;
                if (is_writable($cdir) && @file_put_contents($cdir . '/' . $cname, $blob)) {
                    if (in_array('image', self::cols($dbs, 'biblio'))) {
                        @$dbs->query("UPDATE biblio SET image='" . self::e($dbs, $cname) . "' WHERE biblio_id=$bid");
                    } else {
                        $fid = self::ins($dbs, 'files', ['file_title'=>'Cover ' . $title, 'file_name'=>$cname, 'file_url'=>'images/docs/' . $cname,
                            'file_dir'=>'images/docs', 'mime_type'=>$info['mime'], 'uploader_id'=>$uid, 'input_date'=>$now, 'last_update'=>$now]);
                        if ($fid) self::ins($dbs, 'biblio_attachment', ['biblio_id'=>$bid, 'file_id'=>$fid, 'access_type'=>'public', 'access_limit'=>'']);
                    }
                }
            }
        }

        return ['ok'=>true, 'dup'=>false, 'id'=>$bid, 'msg'=>'Tersimpan #' . $bid,
            'opac' => self::webroot() . 'index.php?p=show_detail&id=' . $bid];
    }
}