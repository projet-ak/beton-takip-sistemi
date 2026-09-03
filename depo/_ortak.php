<?php
/** _ortak.php — Depo modülü ortak yardımcıları */

function dp_sayi($v): float {
    $v = trim((string)$v);
    if ($v==='' || strncmp($v,'#',1)===0) return 0.0;
    $v = str_replace([' ',"\xc2\xa0"],'',$v);
    if (strpos($v,',')!==false) { $v=str_replace('.','',$v); $v=str_replace(',','.',$v); }
    return is_numeric($v) ? (float)$v : 0.0;
}

$GLOBALS['DP_KATEGORI'] = [
    'demirbas' => ['ad'=>'Demirbaşlar', 'ikon'=>'bi-hdd-stack', 'birim'=>'Ad'],
    'sarf'     => ['ad'=>'Sarf Malzeme', 'ikon'=>'bi-basket', 'birim'=>'Ad'],
    'el_aleti' => ['ad'=>'El Aletleri', 'ikon'=>'bi-tools', 'birim'=>'Adet'],
];
function dp_katAd(string $k): string { return $GLOBALS['DP_KATEGORI'][$k]['ad'] ?? $k; }
function dp_katGecerli(string $k): bool { return isset($GLOBALS['DP_KATEGORI'][$k]); }

/** Kategori özetleri: kalem sayısı, toplam stok, mali değer */
function dp_ozet(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT kategori,
               COUNT(*) adet,
               SUM(sayim+gelen-giden) stok,
               SUM((sayim+gelen-giden) * COALESCE(birim_fiyat,0)) deger,
               SUM(CASE WHEN (sayim+gelen-giden)<=0 THEN 1 ELSE 0 END) tukenen
        FROM depo_kalemler WHERE aktif=1 GROUP BY kategori")->fetchAll();
    $o = [];
    foreach ($rows as $r) $o[$r['kategori']] = $r;
    return $o;
}

// ─────────────────────────────────────────────────────────────────────────────
// Hareket defteri (MALZEME GİRİŞ/ÇIKIŞ + TAŞERON MALZEME GİRİŞ/TESLİMAT)
//
// `depo_kalemler` stok FOTOĞRAFIDIR (SAYIM+GELEN−GİDEN); `depo_hareketler` ise
// o stoğu oluşturan TEK TEK HAREKETLERDİR: hangi malzeme, ne zaman, hangi
// irsaliye/fiş ile, kimden geldi / kime gitti, kim onayladı.
// ─────────────────────────────────────────────────────────────────────────────

$GLOBALS['DP_HAREKET'] = [
    'giris' => ['ad'=>'Giriş',   'ikon'=>'bi-box-arrow-in-down', 'renk'=>'success'],
    'cikis' => ['ad'=>'Çıkış',   'ikon'=>'bi-box-arrow-up',      'renk'=>'danger'],
];
$GLOBALS['DP_KAYNAK'] = [
    'depo'    => ['ad'=>'Depo Malzemesi',    'ikon'=>'bi-box-seam'],
    'taseron' => ['ad'=>'Taşeron Malzemesi', 'ikon'=>'bi-people'],
];
function dp_turAd(string $t): string    { return $GLOBALS['DP_HAREKET'][$t]['ad'] ?? $t; }
function dp_kaynakAd(string $k): string { return $GLOBALS['DP_KAYNAK'][$k]['ad'] ?? $k; }

/** Excel'den gelen tarihi YYYY-AA-GG'ye çevirir (05.01.2026 · 2026-01-02 00:00:00 · seri no). */
function dp_tarih($v): ?string
{
    $s = trim((string)$v);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T\s]|$)/', $s, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
    if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/', $s, $m))
        return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
    // Excel seri numarası (1900 tabanlı; 1900 artık yıl hatası için -2 gün)
    if (preg_match('/^\d{4,6}(\.\d+)?$/', $s)) {
        $n = (int)$s;
        if ($n > 1000 && $n < 100000) return date('Y-m-d', mktime(0,0,0,1,$n-1,1900));
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** depo_hareketler tablosunu garanti et (runtime migration). */
function dp_hareket_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS depo_hareketler (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        tur           ENUM('giris','cikis') NOT NULL,
        kaynak        ENUM('depo','taseron') NOT NULL DEFAULT 'depo',
        tarih         DATE NULL,
        belge_tarihi  DATE NULL          COMMENT 'irsaliye/fatura tarihi (girişlerde)',
        belge_no      VARCHAR(60) NULL   COMMENT 'irsaliye no (giriş) / fiş no (çıkış)',
        malzeme       VARCHAR(255) NOT NULL,
        ozellik       VARCHAR(255) NULL,
        birim         VARCHAR(20) NULL,
        miktar        DECIMAL(14,3) NOT NULL DEFAULT 0,
        firma         VARCHAR(150) NULL  COMMENT 'gönderen firma / çıkış yapılan firma / taşeron',
        teslim_alan   VARCHAR(150) NULL,
        onay          VARCHAR(150) NULL,
        lokasyon      VARCHAR(150) NULL,
        aciklama      VARCHAR(255) NULL,
        created       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tur_kaynak (tur, kaynak),
        KEY idx_tarih (tarih),
        KEY idx_belge (belge_no),
        KEY idx_firma (firma),
        KEY idx_malzeme (malzeme)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Elle (günlük) girilen hareketler: Excel tam yenilemesinde SİLİNMEZ.
    // kalem_id doluysa hareket stok kalemine de işlenmiştir (gelen/giden artırıldı).
    $kolon = [];
    foreach ($pdo->query("SHOW COLUMNS FROM depo_hareketler")->fetchAll(PDO::FETCH_ASSOC) as $c) $kolon[$c['Field']] = true;
    $ekle = [];
    if (!isset($kolon['elle']))     $ekle[] = "ADD COLUMN elle TINYINT(1) NOT NULL DEFAULT 0";
    if (!isset($kolon['kalem_id'])) $ekle[] = "ADD COLUMN kalem_id INT NULL, ADD KEY idx_kalem (kalem_id)";
    if (!isset($kolon['evrak_url'])) $ekle[] = "ADD COLUMN evrak_url VARCHAR(500) NULL COMMENT 'imzalı tutanak taraması'";
    if (!isset($kolon['hurda']))    $ekle[] = "ADD COLUMN hurda TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'hurdaya ayırma çıkışı'";
    if ($ekle) $pdo->exec("ALTER TABLE depo_hareketler " . implode(', ', $ekle));
}

/** Bölüm bazında son yükleme günlüğü (hangi dosya ne zaman yüklendi). */
function dp_import_log_kur(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS depo_import_log (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        bolum     VARCHAR(30) NOT NULL,
        dosya     VARCHAR(255) NULL,
        satir     INT NOT NULL DEFAULT 0,
        kullanici VARCHAR(100) NULL,
        created   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bolum (bolum)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Hareketin stok etkisini uygular/geri alır.
 * Giriş → kalem.gelen, çıkış → kalem.giden; $yon=-1 geri alma (silme/düzenleme öncesi).
 */
function dp_stok_islet(PDO $pdo, ?int $kalemId, string $tur, float $miktar, int $yon = 1): void
{
    if (!$kalemId || $miktar == 0.0) return;
    $alan = $tur === 'giris' ? 'gelen' : 'giden';
    $pdo->prepare("UPDATE depo_kalemler SET $alan = $alan + ? WHERE id = ?")
        ->execute([$yon * $miktar, $kalemId]);
}

/** Hareket özeti: tür×kaynak bazında satır sayısı, toplam miktar, tarih aralığı. */
function dp_hareket_ozet(PDO $pdo): array
{
    dp_hareket_semasi_kur($pdo);
    $rows = $pdo->query("SELECT tur, kaynak, COUNT(*) adet, SUM(miktar) miktar,
                                MIN(tarih) ilk, MAX(tarih) son
                         FROM depo_hareketler GROUP BY tur, kaynak")->fetchAll();
    $o = [];
    foreach ($rows as $r) $o[$r['tur'].'|'.$r['kaynak']] = $r;
    return $o;
}

/** Malzeme adını karşılaştırılabilir biçime indirger (ekstre eşleşmesi için). */
function dp_mal_norm(string $s): string {
    $s = str_replace(['İ','I','ı','i','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'],
                     ['I','I','I','I','S','S','G','G','U','U','O','O','C','C'], $s);
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return preg_replace('/\s+/', ' ', $s);
}

/**
 * Stok kalemlerinde hızlı arama (hareket formundaki açılır menü için).
 *
 * Süzme SQL LIKE ile DEĞİL, PHP'de `dp_mal_norm` ile yapılır: Türkçe harfler
 * ASCII'ye katlandığı için "ampul" yazınca "Ampül" de bulunur, "sise" → "Şişe".
 * (LIKE ile ön süzme denenmemeli — 'İ' MySQL'de i/ı ile eşleşmediğinden kayıtlar
 * sessizce düşüyor, LIMIT'li ön süzme de alfabetik ilk N kaydı alıp gerisini atıyordu.)
 * Kalem sayısı binler ölçeğinde olduğundan tüm liste okunup bellekte süzülür.
 *
 * Sıralama: adı aranan metinle BAŞLAYAN → içinde geçen; sonra kısa ad önce.
 *
 * @return array<int,array{id:int,ad:string,ozellik:string,birim:string,alan:string,stok:float,kategori:string}>
 */
function dp_kalem_ara(PDO $pdo, string $q, ?string $kategori = null, int $limit = 25): array
{
    $q   = trim($q);
    $sql = "SELECT id, kategori, ad, COALESCE(ozellik,'') ozellik, COALESCE(birim,'') birim,
                   COALESCE(alan,'') alan, COALESCE(kod,'') kod, (sayim+gelen-giden) stok
            FROM depo_kalemler WHERE aktif=1";
    $par = [];
    if ($kategori !== null && dp_katGecerli($kategori)) { $sql .= " AND kategori=?"; $par[] = $kategori; }
    // Arama yoksa en çok stoğu olanlar (menü ilk açıldığında dolu görünsün)
    $sql .= $q === '' ? " ORDER BY (sayim+gelen-giden) DESC, ad LIMIT " . (int)$limit
                      : " ORDER BY ad LIMIT 20000";
    $st = $pdo->prepare($sql);
    $st->execute($par);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($q !== '') {
        $n      = dp_mal_norm($q);
        $kelime = array_values(array_filter(explode(' ', $n), fn($w) => $w !== ''));
        $es     = [];
        foreach ($rows as $r) {
            $ad  = dp_mal_norm($r['ad']);
            $tum = $ad . ' ' . dp_mal_norm($r['ozellik'] . ' ' . $r['kod']);
            $poz = mb_strpos($ad, $n);
            if ($poz === 0)                                  $sira = 0;   // ad aranan metinle BAŞLIYOR
            elseif ($poz !== false)                           $sira = 1;   // adın içinde geçiyor
            elseif (mb_strpos($tum, $n) !== false)            $sira = 2;   // özellik / malzeme kodunda
            // Kelimeler farklı sırada yazılmış olabilir ("dolap soyunma" → "Soyunma Dolabı")
            elseif (count($kelime) > 1
                    && !array_filter($kelime, fn($w) => mb_strpos($tum, $w) === false)) $sira = 3;
            else continue;
            $r['_sira'] = $sira;
            $es[] = $r;
        }
        usort($es, fn($a, $b) => [$a['_sira'], mb_strlen($a['ad']), $a['ad']] <=> [$b['_sira'], mb_strlen($b['ad']), $b['ad']]);
        $rows = array_slice($es, 0, $limit);
    }
    return array_map(fn($r) => [
        'id'       => (int)$r['id'],
        'ad'       => $r['ad'],
        'ozellik'  => $r['ozellik'],
        'birim'    => $r['birim'] ?: ($GLOBALS['DP_KATEGORI'][$r['kategori']]['birim'] ?? 'Adet'),
        'alan'     => $r['alan'],
        'stok'     => (float)$r['stok'],
        'kategori' => dp_katAd($r['kategori']),
    ], $rows);
}

/**
 * Aynı FİŞE ait hareket satırları (bir çıkış fişinde birden çok malzeme olur).
 * Anahtar: tür + kaynak + hurda + belge no + tarih + firma. Belge no boşsa yalnız kendisi.
 * @return int[] id listesi (sıralı)
 */
function dp_fis_satirlari(PDO $pdo, array $hr): array
{
    $kendi = [(int)$hr['id']];
    if (trim((string)($hr['belge_no'] ?? '')) === '') return $kendi;
    $sql = "SELECT id FROM depo_hareketler
            WHERE tur=? AND kaynak=? AND hurda=? AND belge_no=? AND COALESCE(firma,'')=?";
    $par = [$hr['tur'], $hr['kaynak'], (int)($hr['hurda'] ?? 0), $hr['belge_no'], (string)($hr['firma'] ?? '')];
    if (!empty($hr['tarih'])) { $sql .= " AND tarih=?"; $par[] = $hr['tarih']; }
    else                      { $sql .= " AND tarih IS NULL"; }
    $sql .= " ORDER BY id";
    $st = $pdo->prepare($sql);
    $st->execute($par);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return $ids ?: $kendi;
}

/**
 * İmzalı evrak (çıkış fişi / irsaliye / tutanak) yükler.
 * Fiş no varsa belge o fişin TÜM satırlarına bağlanır — bir imzalı fiş tüm kalemleri kapsar.
 * @return array{0:bool,1:string} [başarılı mı, mesaj]
 */
function dp_evrak_kaydet(PDO $pdo, array $hr, array $f): array
{
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return [false, 'Dosya seçilmedi.'];
    $ad   = (string)($f['name'] ?? '');
    $mime = guess_mime($f['tmp_name'], $ad);
    if (!in_array($mime, ['application/pdf','image/jpeg','image/png','image/webp'], true))
        return [false, 'Desteklenmeyen tür (PDF, JPG, PNG, WEBP): ' . $mime];
    if ((int)($f['size'] ?? 0) > 10 * 1024 * 1024) return [false, 'Dosya 10 MB sınırını aşıyor.'];

    $hid = (int)$hr['id'];
    $dir = __DIR__ . '/../uploads/depo_hareket/' . $hid;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [false, 'Klasör oluşturulamadı.'];
    $ext  = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'pdf';
    $yeni = 'evrak_' . date('Ymd_His') . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $yeni)) return [false, 'Dosya diske yazılamadı.'];

    $ids  = dp_fis_satirlari($pdo, $hr);
    $yer  = implode(',', array_fill(0, count($ids), '?'));
    // Değiştirilen belgeler: bağ koptuktan SONRA diskten silinecek (başka satır kullanıyorsa kalır)
    $q = $pdo->prepare("SELECT DISTINCT evrak_url FROM depo_hareketler WHERE id IN ($yer) AND evrak_url IS NOT NULL");
    $q->execute($ids);
    $eskiler = $q->fetchAll(PDO::FETCH_COLUMN);

    $url = 'uploads/depo_hareket/' . $hid . '/' . $yeni;
    $pdo->prepare("UPDATE depo_hareketler SET evrak_url=? WHERE id IN ($yer)")
        ->execute(array_merge([$url], $ids));
    foreach ($eskiler as $e) if ($e && $e !== $url) dp_evrak_dosya_temizle($pdo, (string)$e);

    return [true, 'İmzalı evrak yüklendi' . (count($ids) > 1 ? ' ve bu fişin ' . count($ids) . ' satırına bağlandı.' : '.')];
}

/** Evrak bağını kaldırır; dosyayı yalnız son bağ da koptuysa diskten siler. */
function dp_evrak_bagi_kaldir(PDO $pdo, int $id): bool
{
    $v = $pdo->prepare("SELECT evrak_url FROM depo_hareketler WHERE id=?");
    $v->execute([$id]);
    $url = (string)($v->fetchColumn() ?: '');
    if ($url === '') return false;
    $pdo->prepare("UPDATE depo_hareketler SET evrak_url=NULL WHERE id=?")->execute([$id]);
    dp_evrak_dosya_temizle($pdo, $url);
    return true;
}

/** Dosyayı diskten sil — ama yalnız hiçbir hareket artık ona bağlı değilse. */
function dp_evrak_dosya_temizle(PDO $pdo, string $url): void
{
    $v = $pdo->prepare("SELECT COUNT(*) FROM depo_hareketler WHERE evrak_url=?");
    $v->execute([$url]);
    if ((int)$v->fetchColumn() === 0 && str_starts_with($url, 'uploads/depo_hareket/')) @unlink(__DIR__ . '/../' . $url);
}
