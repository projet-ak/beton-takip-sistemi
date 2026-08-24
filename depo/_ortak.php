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
