<?php
/**
 * _ortak.php — Prekast Takip modülü ortak yardımcıları
 *
 * Kaynak: sahadan GÜNLÜK gelen "İŞ TAKİP ÇİZELGESİ" Excel'i (taşeron hakkediş çizelgesi).
 * Bir satır = bir iş kalemi: blok + daire için **kesim** ve **silikon** işi.
 * Akış: kesim yapılır → silikon yapılır → metraj ölçülür → hakkediş = metraj × birim fiyat.
 *
 * Çizelge sabit bir iş listesidir; her gün aynı satırlar gelir, **durumlar dolar**.
 * Bu yüzden içe aktarma tam yenileme değil BİRLEŞTİRME'dir (CRM modülüyle aynı mantık):
 * satır ilk kez "Yapıldı" olduğunda o günün tarihi damgalanır — ilerleme takvimi böyle oluşur.
 * Ayrıca her yükleme `prekast_gunluk` tablosuna anlık toplam yazar (trend grafiği).
 *
 * Veritabanı: **CRM ile aynı** (`$pdoCrm`, `CRM_DB_NAME`), tablolar `prekast_` önekli.
 */

/** Türkçe harf duyarsız normalize (başlık/eşleştirme/arama). */
function pk_norm(string $s): string
{
    $s = str_replace(['İ','I','ı','i','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'],
                     ['I','I','I','I','S','S','G','G','U','U','O','O','C','C'], $s);
    return preg_replace('/\s+/', ' ', mb_strtoupper(trim($s), 'UTF-8'));
}

/** Türkçe/İngilizce ondalık ayraçlı sayıyı float'a çevirir ("4,85" ve "4.85" → 4.85). */
function pk_sayi($v): float
{
    $s = trim((string)$v);
    if ($s === '' || strncmp($s, '#', 1) === 0) return 0.0;
    $s = str_replace([' ', "\xc2\xa0"], '', $s);
    // Hem "1.234,56" hem "1234.56" biçimini destekle
    if (strpos($s, ',') !== false) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
    return is_numeric($s) ? (float)$s : 0.0;
}

/** Hücre "yapıldı" anlamına geliyor mu? (boş / "-" / "yok" değilse yapılmış sayılır) */
function pk_yapildi($v): bool
{
    $u = pk_norm((string)$v);
    if ($u === '' || $u === '-' || $u === 'YOK' || $u === 'HAYIR' || $u === '0') return false;
    return true;
}

/**
 * İş kalemi kimliği. Excel'de ID yok ve **blok+daire tekrar edebiliyor**
 * (aynı dairede iki ayrı cephe işi olabiliyor: B/61 iki satır). Bu yüzden kimliğe
 * çizelge + blok + daire + o gruptaki sıra (1,2,3…) girer.
 */
function pk_anahtar(string $cizelge, string $blok, string $daire, int $tekrar): string
{
    return md5(implode('|', [pk_norm($cizelge), pk_norm($blok), pk_norm($daire), $tekrar]));
}

$GLOBALS['PK_DURUM'] = [
    'bekliyor' => ['ad' => 'Kesim bekliyor',   'renk' => 'secondary', 'ikon' => 'bi-hourglass'],
    'kesim'    => ['ad' => 'Silikon bekliyor', 'renk' => 'warning',   'ikon' => 'bi-scissors'],
    'tamam'    => ['ad' => 'Tamamlandı',       'renk' => 'success',   'ikon' => 'bi-check-circle'],
];
function pk_durumAd(string $d): string   { return $GLOBALS['PK_DURUM'][$d]['ad'] ?? $d; }
function pk_durumRenk(string $d): string { return $GLOBALS['PK_DURUM'][$d]['renk'] ?? 'secondary'; }

/** Kesim/silikon durumundan iş durumu. */
function pk_durum(bool $kesim, bool $silikon): string
{
    if ($silikon) return 'tamam';
    return $kesim ? 'kesim' : 'bekliyor';
}

/** Şema garantisi (runtime migration; kurulum_prekast.php de aynı şemayı kurar). */
function pk_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS prekast_isler (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        kayit_anahtari CHAR(32) NOT NULL   COMMENT 'çizelge+blok+daire+tekrar (pk_anahtar)',
        cizelge        VARCHAR(200) NULL   COMMENT 'Excel başlığı: KARTAL BATIYAKASI C ve B PARSEL T PROFİL',
        is_tipi        VARCHAR(60) NULL    COMMENT 'başlıktan çıkarılan iş tipi (T PROFİL…)',
        sira           INT NULL            COMMENT 'Excel No sütunu (bilgi amaçlı, benzersiz değil)',
        blok           VARCHAR(20) NULL,
        daire          VARCHAR(20) NULL,
        daire_sira     INT NOT NULL DEFAULT 0 COMMENT 'daire no sayısal sıralama için',
        tekrar         INT NOT NULL DEFAULT 1 COMMENT 'aynı blok+dairedeki kaçıncı iş',
        kesim          TINYINT(1) NOT NULL DEFAULT 0,
        kesim_metin    VARCHAR(40) NULL    COMMENT 'hücrenin ham metni (Yapıldı…)',
        kesim_tarih    DATE NULL           COMMENT 'ilk kez yapıldı görüldüğü rapor günü',
        silikon        TINYINT(1) NOT NULL DEFAULT 0,
        silikon_metin  VARCHAR(40) NULL,
        silikon_tarih  DATE NULL,
        metraj         DECIMAL(12,2) NOT NULL DEFAULT 0,
        birim_fiyat    DECIMAL(12,2) NOT NULL DEFAULT 0,
        hakkedis       DECIMAL(14,2) NOT NULL DEFAULT 0,
        durum          ENUM('bekliyor','kesim','tamam') NOT NULL DEFAULT 'bekliyor',
        dosyada        TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'son raporda satır var mıydı',
        ilk_gorulme    DATE NULL,
        son_gorulme    DATE NULL,
        ic_not         TEXT NULL           COMMENT 'sistem içi not (Excel dışı, aktarımda korunur)',
        created        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated        TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_anahtar (kayit_anahtari),
        KEY idx_durum (durum),
        KEY idx_blok (blok),
        KEY idx_cizelge (cizelge),
        KEY idx_silikon_tarih (silikon_tarih)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Her yüklemenin anlık toplamı — trend grafiği bundan çizilir (gün başına tek satır)
    $pdo->exec("CREATE TABLE IF NOT EXISTS prekast_gunluk (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        rapor_tarihi  DATE NOT NULL,
        cizelge       VARCHAR(200) NULL,
        satir         INT NOT NULL DEFAULT 0,
        kesim         INT NOT NULL DEFAULT 0,
        silikon       INT NOT NULL DEFAULT 0,
        metraj        DECIMAL(14,2) NOT NULL DEFAULT 0,
        hakkedis      DECIMAL(16,2) NOT NULL DEFAULT 0,
        yeni_satir    INT NOT NULL DEFAULT 0,
        yeni_kesim    INT NOT NULL DEFAULT 0,
        yeni_silikon  INT NOT NULL DEFAULT 0,
        dosya         VARCHAR(255) NULL,
        kullanici     VARCHAR(100) NULL,
        created       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_gun (rapor_tarihi, cizelge),
        KEY idx_tarih (rapor_tarihi)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Dashboard/rapor KPI'ları (opsiyonel çizelge filtresi). */
function pk_ozet(PDO $pdo, string $cizelge = ''): array
{
    pk_semasi_kur($pdo);
    // KPI'lar çizelgenin GÜNCEL hâlini anlatır: dosyadan çıkarılmış satırlar (dosyada=0)
    // toplamlara girmez, ayrıca "düşen" olarak sayılır.
    $w = ' WHERE dosyada = 1' . ($cizelge !== '' ? ' AND cizelge = ?' : '');
    $p = $cizelge !== '' ? [$cizelge] : [];
    $st = $pdo->prepare("SELECT
            COUNT(*) toplam,
            SUM(kesim=1)   kesim,
            SUM(silikon=1) silikon,
            SUM(kesim=1 AND silikon=0) silikonBekleyen,
            SUM(kesim=0) kesimBekleyen,
            COALESCE(SUM(metraj),0)   metraj,
            COALESCE(SUM(hakkedis),0) hakkedis,
            COUNT(DISTINCT blok) blok,
            MAX(silikon_tarih) sonTamam
        FROM prekast_isler $w");
    $st->execute($p);
    $o = $st->fetch() ?: [];
    $dw = ' WHERE dosyada = 0' . ($cizelge !== '' ? ' AND cizelge = ?' : '');
    $ds = $pdo->prepare("SELECT COUNT(*) FROM prekast_isler $dw"); $ds->execute($p);
    $o['dusen'] = (int)$ds->fetchColumn();
    foreach (['toplam','kesim','silikon','silikonBekleyen','kesimBekleyen','dusen','blok'] as $k) $o[$k] = (int)($o[$k] ?? 0);
    foreach (['metraj','hakkedis'] as $k) $o[$k] = (float)($o[$k] ?? 0);
    $o['oran'] = $o['toplam'] ? round($o['silikon'] * 100 / $o['toplam'], 1) : 0.0;
    // Bekleyen işlerin tahmini hakkedişi: tamamlananların ortalama metrajı × birim fiyat
    $ortMetraj = $o['silikon'] ? $o['metraj'] / $o['silikon'] : 0;
    $bf = (float)($pdo->query("SELECT birim_fiyat FROM prekast_isler WHERE birim_fiyat>0 ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
    $o['ortMetraj']      = $ortMetraj;
    $o['birimFiyat']     = $bf;
    $o['tahminiKalan']   = round($ortMetraj * $bf * $o['silikonBekleyen'], 2);
    return $o;
}

/** Son yükleme kaydı (dashboard "rapor güncel mi?" bandı). */
function pk_son_import(PDO $pdo): ?array
{
    try { return $pdo->query("SELECT * FROM prekast_gunluk ORDER BY id DESC LIMIT 1")->fetch() ?: null; }
    catch (Throwable $e) { return null; }
}

/**
 * Günlük ilerleme serisi: her rapor gününün anlık toplamı + o gün tamamlanan iş sayısı.
 * `prekast_gunluk` anlık fotoğrafları verir; `silikon_tarih` ise gün gün tamamlananları.
 * Aradaki boş günler doldurulur ki zaman ekseni doğru olsun.
 */
function pk_gunluk_seri(PDO $pdo, int $sonGun = 0): array
{
    pk_semasi_kur($pdo);
    $anlik = [];
    foreach ($pdo->query("SELECT rapor_tarihi, MAX(satir) satir, MAX(kesim) kesim, MAX(silikon) silikon,
                                 MAX(metraj) metraj, MAX(hakkedis) hakkedis
                          FROM prekast_gunluk GROUP BY rapor_tarihi ORDER BY rapor_tarihi")->fetchAll() as $r)
        $anlik[$r['rapor_tarihi']] = $r;
    // Gün gün tamamlanan (ilk kez "Yapıldı" damgası)
    $tamam = [];
    foreach ($pdo->query("SELECT silikon_tarih t, COUNT(*) n, COALESCE(SUM(metraj),0) m, COALESCE(SUM(hakkedis),0) h
                          FROM prekast_isler WHERE silikon_tarih IS NOT NULL
                          GROUP BY silikon_tarih ORDER BY silikon_tarih")->fetchAll() as $r) $tamam[$r['t']] = $r;
    $kesimG = [];
    foreach ($pdo->query("SELECT kesim_tarih t, COUNT(*) n FROM prekast_isler
                          WHERE kesim_tarih IS NOT NULL GROUP BY kesim_tarih")->fetchAll() as $r) $kesimG[$r['t']] = (int)$r['n'];

    $gunler = array_unique(array_merge(array_keys($anlik), array_keys($tamam), array_keys($kesimG)));
    if (!$gunler) return [];
    sort($gunler);
    $son = max($gunler[count($gunler) - 1], date('Y-m-d'));

    $seri = []; $sonAnlik = null;
    $d = new DateTime($gunler[0]); $bit = new DateTime($son);
    while ($d <= $bit) {
        $g = $d->format('Y-m-d');
        if (isset($anlik[$g])) $sonAnlik = $anlik[$g];
        $seri[] = [
            'gun'          => $g,
            'tamamlanan'   => (int)($tamam[$g]['n'] ?? 0),
            'kesilen'      => (int)($kesimG[$g] ?? 0),
            'gunMetraj'    => (float)($tamam[$g]['m'] ?? 0),
            'gunHakkedis'  => (float)($tamam[$g]['h'] ?? 0),
            'toplamSilikon'=> (int)($sonAnlik['silikon'] ?? 0),
            'toplamMetraj' => (float)($sonAnlik['metraj'] ?? 0),
            'toplamHakkedis'=> (float)($sonAnlik['hakkedis'] ?? 0),
        ];
        $d->modify('+1 day');
    }
    return $sonGun > 0 ? array_slice($seri, -$sonGun) : $seri;
}

/**
 * Liste/rapor filtrelerini WHERE + parametreye çevirir (hepsi prepared).
 * @return array{0:string,1:array,2:array}
 */
function pk_filtre(array $g): array
{
    $w = []; $p = []; $etkin = [];
    // Varsayılan görünüm çizelgede DURAN işlerdir; dosyadan düşenler (dosyada=0) silinmediği
    // için ayrıca istenmedikçe listeye karışmaz.
    $dosyada = (string)($g['dosyada'] ?? '');
    if ($dosyada === '0')            { $w[] = 'dosyada = 0'; $etkin['dosyada'] = '0'; }
    elseif ($dosyada === 'hepsi')    { $etkin['dosyada'] = 'hepsi'; }
    else                             { $w[] = 'dosyada = 1'; }
    foreach (['blok' => 'blok', 'daire' => 'daire', 'cizelge' => 'cizelge', 'is_tipi' => 'is_tipi'] as $par => $kolon) {
        $v = trim((string)($g[$par] ?? ''));
        if ($v === '') continue;
        $w[] = "$kolon = ?"; $p[] = $v; $etkin[$par] = $v;
    }
    $durum = trim((string)($g['durum'] ?? ''));
    if (isset($GLOBALS['PK_DURUM'][$durum])) { $w[] = 'durum = ?'; $p[] = $durum; $etkin['durum'] = $durum; }
    foreach (['bas' => '>=', 'bit' => '<='] as $par => $op) {
        $v = trim((string)($g[$par] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) continue;
        $w[] = "silikon_tarih $op ?"; $p[] = $v; $etkin[$par] = $v;
    }
    $ara = trim((string)($g['ara'] ?? ''));
    if ($ara !== '') {
        $w[] = "(blok LIKE ? OR daire LIKE ? OR cizelge LIKE ? OR ic_not LIKE ?)";
        for ($i = 0; $i < 4; $i++) $p[] = '%' . $ara . '%';
        $etkin['ara'] = $ara;
    }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p, $etkin];
}

/** Bir kolonun benzersiz değerleri (filtre menüleri) — whitelist. */
function pk_secenekler(PDO $pdo, string $kolon): array
{
    if (!in_array($kolon, ['blok','daire','cizelge','is_tipi'], true)) return [];
    $sql = $kolon === 'daire'
        ? "SELECT daire v FROM prekast_isler WHERE daire IS NOT NULL AND daire <> '' GROUP BY daire, daire_sira ORDER BY daire_sira, daire"
        : "SELECT DISTINCT $kolon v FROM prekast_isler WHERE $kolon IS NOT NULL AND $kolon <> '' ORDER BY $kolon";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
}

/* ══════════════════════════════════════════════════════════════════════════
   BLOK BAZINDA İCMAL  (icmal.php + import mutabakatı)
   --------------------------------------------------------------------------
   Kaynak dosyanın "İCMAL" sayfası "HESAPLAMA" sayfasından SUMIFS ile beslenir;
   HESAPLAMA'nın sayaç/metraj sütunları (D–G) ise FORMÜL DEĞİL elle yazılmış
   değerlerdir ve eski listeden kalan satırlar taşır — bu yüzden Excel'in icmali
   bayat kalabiliyor. Sistem icmali her zaman güncel iş satırlarından CANLI
   hesaplar; içe aktarmada Excel'in icmaliyle karşılaştırıp farkı raporlar.

   Excel'in mantığı (aynen uygulanır):
     • Daire sayıları BENZERSİZ blok|daire üzerinden (B/61 iki satır → 1 daire)
     • Metraj ölçülmemiş satırlara ÖLÇÜLENLERİN ORTALAMASI yazılır (HESAPLAMA I2 =
       ölçülen toplam / ölçülen adet). Sistem bunu "tahmini" olarak AYRI gösterir.
     • Metraj toplamları her satırı sayar (her satır ayrı bir cephe işidir).
   ⚠ Excel'de Silikon (mt) = Kesim (mt) çıkar (G sütunu F'nin kopyası) — sistemde
     silikon metrajı yalnız silikonu yapılmış satırlardan toplanır (amaçlanan bu).
   ══════════════════════════════════════════════════════════════════════════ */
function pk_icmal(PDO $pdo, string $cizelge = ''): array
{
    pk_semasi_kur($pdo);
    $w = ' WHERE dosyada = 1' . ($cizelge !== '' ? ' AND cizelge = ?' : '');
    $st = $pdo->prepare("SELECT blok, daire, tekrar, kesim, silikon, metraj FROM prekast_isler $w ORDER BY blok, daire_sira, tekrar");
    $st->execute($cizelge !== '' ? [$cizelge] : []);
    $satirlar = $st->fetchAll();

    // Ortalama metraj: ölçülen (metraj > 0) satırların ortalaması — Excel HESAPLAMA!I2
    $olcToplam = 0.0; $olcAdet = 0;
    foreach ($satirlar as $r) if ((float)$r['metraj'] > 0) { $olcToplam += (float)$r['metraj']; $olcAdet++; }
    $ort = $olcAdet ? $olcToplam / $olcAdet : 0.0;

    $blok = [];
    $bos = fn() => ['kesimDaire'=>0, 'silikonDaire'=>0, 'kesimMt'=>0.0, 'kesimTahmini'=>0.0,
                    'silikonMt'=>0.0, 'silikonTahmini'=>0.0, 'satir'=>0, 'tahminiSatir'=>0];
    $daireGoruldu = [];   // blok|daire → kesim/silikon sayıldı mı
    foreach ($satirlar as $r) {
        $b = pk_norm((string)$r['blok']);
        if ($b === '') continue;
        $blok[$b] = $blok[$b] ?? $bos();
        $g = &$blok[$b];
        $g['satir']++;
        $anahtar = $b . '|' . pk_norm((string)$r['daire']);
        $olculen = (float)$r['metraj'] > 0;
        $m = $olculen ? (float)$r['metraj'] : $ort;
        if (!$olculen && ($r['kesim'] || $r['silikon'])) $g['tahminiSatir']++;
        if ($r['kesim']) {
            if (empty($daireGoruldu[$anahtar]['k'])) { $g['kesimDaire']++; $daireGoruldu[$anahtar]['k'] = true; }
            $g['kesimMt'] += $m; if (!$olculen) $g['kesimTahmini'] += $m;
        }
        if ($r['silikon']) {
            if (empty($daireGoruldu[$anahtar]['s'])) { $g['silikonDaire']++; $daireGoruldu[$anahtar]['s'] = true; }
            $g['silikonMt'] += $m; if (!$olculen) $g['silikonTahmini'] += $m;
        }
        unset($g);
    }
    ksort($blok);
    $toplam = $bos();
    foreach ($blok as $b => $g) {
        $blok[$b]['oran'] = $g['kesimDaire'] ? $g['silikonDaire'] / $g['kesimDaire'] : 0.0;
        foreach ($toplam as $k => $v) $toplam[$k] += $g[$k];
    }
    $toplam['oran'] = $toplam['kesimDaire'] ? $toplam['silikonDaire'] / $toplam['kesimDaire'] : 0.0;
    return ['blok'=>$blok, 'toplam'=>$toplam, 'ortMetraj'=>$ort, 'olculenAdet'=>$olcAdet, 'olculenToplam'=>$olcToplam];
}
