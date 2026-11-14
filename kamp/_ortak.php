<?php
/**
 * kamp/_ortak.php — Kamp Takip modülü çekirdeği (kamp_*)
 *
 * Şantiye kampı: BİNA → KAT → (ÜNİTE / konteyner) → ODA → YATAK hiyerarşisi,
 * kimin hangi yatakta hangi tarihten hangi tarihe kaldığı (konaklama defteri),
 * firmalar ve oda demirbaşları.
 *
 *  • Bina türleri: Staff (mühendis / teknik ofis — iki katlı, her konteyner ikiye
 *    bölünmüş: sol oda · ortak banyo-WC · sağ oda) · ranzalı koğuş blokları ·
 *    formen yatakhanesi · sosyal / ortak alanlar.
 *  • Odanın TÜRÜ yatak barındırıp barındırmadığını belirler: konteynerdeki bazı odalar
 *    çamaşırhane ya da depo olarak kullanılıyor → yatakları yoktur, kapasiteye girmez.
 *  • KONAKLAMA: kişi + yatak + giriş tarihi + çıkış tarihi (NULL = hâlâ kalıyor).
 *    "Şu tarihte kim kalıyordu", "bu ay kim geldi / kim çıktı", firma bazında kişi-gece
 *    icmali buradan çıkar. Kayıt SİLİNMEZ, çıkış tarihiyle kapanır.
 *
 * ⭐ §0 — MODÜL BAĞIMSIZLIĞI: kendi veritabanı (`KAMP_DB_NAME`), kendi kişi ve firma
 * listesi; başka modülün tablosuna JOIN atmaz, çekirdeğini require etmez.
 *
 * ⚠ Gece sayımı: giriş günü gecesi sayılır, çıkış günü sayılmaz (otel mantığı) —
 * 01.11 giriş / 05.11 çıkış = 4 gece. Hâlâ kalan kişi BU GECE dahil sayılır.
 */

const KAMP_BINA_TUR = [
    'staff'  => ['Staff (Mühendis / Teknik Ofis)', 'bi-building',     'primary'],
    'kogus'  => ['Ranzalı Blok (İşçi)',             'bi-buildings',    'secondary'],
    'formen' => ['Formen Yatakhanesi',              'bi-house-door',   'warning'],
    'sosyal' => ['Sosyal / Ortak Alan',             'bi-cup-hot',      'info'],
    'diger'  => ['Diğer',                           'bi-box',          'light'],
];

/** Oda türü: anahtar => [ad, ikon, yatak barındırır mı] */
const KAMP_ODA_TUR = [
    'yatak'   => ['Yatak Odası',        'bi-door-closed',   true],
    'kogus'   => ['Koğuş',              'bi-people',        true],
    'ortak'   => ['Ortak Banyo / WC',   'bi-droplet',       false],
    'camasir' => ['Çamaşırhane',        'bi-basket',        false],
    'depo'    => ['Depo',               'bi-box-seam',      false],
    'mutfak'  => ['Mutfak / Yemekhane', 'bi-cup-hot',       false],
    'ofis'    => ['Ofis',               'bi-briefcase',     false],
    'diger'   => ['Diğer',              'bi-grid',          false],
];

const KAMP_YATAK_TUR = [
    'tek'        => 'Tek kişilik',
    'ranza_alt'  => 'Ranza alt',
    'ranza_orta' => 'Ranza orta',
    'ranza_ust'  => 'Ranza üst',
];

/** Kişi kategorisi: anahtar => [ad, renk] */
const KAMP_KATEGORI = [
    'staff'   => ['Mühendis / Staff', 'primary'],
    'formen'  => ['Formen',           'warning text-dark'],
    'isci'    => ['İşçi',             'secondary'],
    'misafir' => ['Misafir',          'info text-dark'],
    'diger'   => ['Diğer',            'light text-dark border'],
];

/** Demirbaş durumu: anahtar => [ad, renk, kullanılabilir mi] */
const KAMP_DB_DURUM = [
    'saglam'  => ['Sağlam',            'success',          true],
    'arizali' => ['Arızalı',           'danger',           false],
    'bakimda' => ['Bakımda / Tamirde', 'warning text-dark', false],
    'kayip'   => ['Kayıp',             'dark',             false],
    'hurda'   => ['Hurda',             'secondary',        false],
];

const KAMP_DEMIRBAS_ONERI = [
    'Baza', 'Yatak', 'Ranza', 'Yastık', 'Battaniye / Yorgan', 'Nevresim takımı', 'Elbise dolabı',
    'Komodin', 'Masa', 'Sandalye', 'Buzdolabı', 'Klima', 'Isıtıcı / Kombi', 'Televizyon',
    'Çamaşır makinesi', 'Kurutma makinesi', 'Ütü', 'Ütü masası', 'Su ısıtıcı', 'Lavabo',
    'Duş / Duş teknesi', 'Klozet', 'Ayna', 'Perde', 'Çöp kovası', 'Yangın tüpü', 'Priz / Uzatma',
];

const KAMP_CIKIS_SEBEP = [
    'İşten ayrıldı', 'Firmanın işi bitti', 'Şantiye / proje değişikliği', 'İzin',
    'Kamp dışında kalacak', 'Disiplin', 'Diğer',
];

// ─────────────────────────────────────────────────────────────── yardımcılar
function kamp_norm(?string $s): string
{
    $s = mb_strtoupper(trim((string)$s), 'UTF-8');
    $s = str_replace(['İ','I','ı','i','Ş','Ğ','Ü','Ö','Ç'], ['I','I','I','I','S','G','U','O','C'], $s);
    $s = preg_replace('/[^A-Z0-9]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Türkçe doğru BÜYÜK HARF (mb_strtoupper 'i'yi 'I' yapar, 'İ' olmalı). */
function kamp_buyuk(?string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    if ($s === '') return '';
    return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $s), 'UTF-8');
}

function kamp_kisi_ad(array $p): string { return trim(($p['ad'] ?? '') . ' ' . ($p['soyad'] ?? '')); }

function kamp_kat_ad(int $k): string
{
    if ($k < 0) return ($k === -1 ? '' : abs($k) . '. ') . 'Bodrum Kat';
    return $k === 0 ? 'Zemin Kat' : $k . '. Kat';
}
function kamp_kat_kisa(int $k): string { return $k < 0 ? 'B' . abs($k) : ($k === 0 ? 'Z' : (string)$k); }

function kamp_oda_yatakli(?string $tur): bool { return !empty(KAMP_ODA_TUR[(string)$tur][2]); }

/** SQL: yatak barındıran oda türleri ('yatak','kogus') — sorgulara elle yazılmaz. */
function kamp_yatakli_sql(string $alias = 'o'): string
{
    $t = [];
    foreach (KAMP_ODA_TUR as $k => $v) if ($v[2]) $t[] = "'" . $k . "'";
    return ($alias !== '' ? $alias . '.' : '') . 'tur IN (' . implode(',', $t) . ')';
}

function kamp_katRozet(?string $k): string
{
    $x = KAMP_KATEGORI[(string)$k] ?? [(string)$k ?: '—', 'light text-dark border'];
    return '<span class="badge bg-' . $x[1] . '">' . htmlspecialchars($x[0]) . '</span>';
}
function kamp_dbRozet(?string $d): string
{
    $x = KAMP_DB_DURUM[(string)$d] ?? [(string)$d, 'secondary'];
    return '<span class="badge bg-' . $x[1] . '">' . htmlspecialchars($x[0]) . '</span>';
}

/** Tarih normalize: Y-m-d · d.m.Y · d/m/Y · Excel seri → Y-m-d, geçersizse null. */
function kamp_tarih($v): ?string
{
    if ($v === null) return null;
    if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {
        return date('Y-m-d', (int)round(((float)$v - 25569) * 86400));
    }
    $s = trim((string)$v);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) [$y, $a, $g] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})/', $s, $m)) {
        [$g, $a, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($y < 100) $y += 2000;
    } else return null;
    if (!checkdate($a, $g, $y) || $y < 2000 || $y > 2100) return null;
    return sprintf('%04d-%02d-%02d', $y, $a, $g);
}
function kamp_tarih_yaz(?string $t): string { return $t ? date('d.m.Y', strtotime($t)) : '—'; }

/** İki tarih arası gün farkı (b − a), takvim günü (yaz saatinden etkilenmez). */
function kamp_gun_fark(string $a, string $b): int
{
    $d = (new DateTimeImmutable($a))->diff(new DateTimeImmutable($b));
    return $d->invert ? -$d->days : $d->days;
}

/**
 * [bas, bit] (iki uç DAHİL) aralığına düşen gece sayısı.
 * Giriş günü gecesi sayılır, çıkış günü sayılmaz; açık konaklama bu gece dahil sürer.
 */
function kamp_gece(string $giris, ?string $cikis, string $bas, string $bit): int
{
    $yarin = date('Y-m-d', strtotime('+1 day'));
    $son   = $cikis ?: $yarin;                                  // hariç
    $a = max($giris, $bas);
    $b = min($son, date('Y-m-d', strtotime($bit . ' +1 day')));
    return $b > $a ? kamp_gun_fark($a, $b) : 0;
}

/** Konaklamanın toplam gecesi (bugüne kadar). */
function kamp_sure(string $giris, ?string $cikis): int
{
    return kamp_gun_fark($giris, $cikis ?: date('Y-m-d', strtotime('+1 day')));
}

// ─────────────────────────────────────────────────────────────── şema
/**
 * Şemayı kurar (runtime + kurulum_kamp.php). İdempotent.
 * ⚠ Transaction DIŞINDA çağrılmalı — MySQL'de DDL örtük commit yapar.
 *
 * Konaklama tablosundaki iki STORED üretilmiş kolon (acik_yatak / acik_kisi) + UNIQUE
 * index "bir yatakta tek açık konaklama" ve "bir kişinin tek açık konaklaması" kuralını
 * VERİTABANI düzeyinde garanti eder (MySQL'de kısmi index yok; NULL'lar çakışmaz).
 * Bu yüzden konaklamada FK yoktur — MySQL üretilmiş kolonun taban kolonunda
 * SET NULL / CASCADE'e izin vermez; bütünlük uygulamada korunur (dolu yatak silinmez).
 */
function kamp_semasi_kur(PDO $pdo): void
{
    static $kuruldu = false;
    if ($kuruldu) return;
    $E = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec("CREATE TABLE IF NOT EXISTS kamp_binalar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ad VARCHAR(120) NOT NULL,
        kod VARCHAR(20) NULL,
        tur VARCHAR(20) NOT NULL DEFAULT 'kogus',
        aciklama VARCHAR(255) NULL,
        sira INT NOT NULL DEFAULT 0,
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $E");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kamp_odalar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bina_id INT NOT NULL,
        kat INT NOT NULL DEFAULT 0,
        unite VARCHAR(30) NULL COMMENT 'konteyner / bölüm no (aynı ünitedeki odalar yan yana çizilir)',
        konum VARCHAR(10) NULL COMMENT 'ünite içi: sol | orta | sag',
        ad VARCHAR(60) NOT NULL,
        tur VARCHAR(20) NOT NULL DEFAULT 'yatak',
        aciklama VARCHAR(255) NULL,
        sira INT NOT NULL DEFAULT 0,
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_kamp_oda (bina_id, ad),
        KEY ix_kamp_oda_kat (bina_id, kat)
    ) $E");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kamp_yataklar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        oda_id INT NOT NULL,
        no VARCHAR(20) NOT NULL,
        tur VARCHAR(20) NOT NULL DEFAULT 'tek',
        aktif TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = kullanım dışı (kırık, kaldırıldı)',
        aciklama VARCHAR(255) NULL,
        sira INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_kamp_yatak (oda_id, no)
    ) $E");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kamp_firmalar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ad VARCHAR(150) NOT NULL,
        yetkili VARCHAR(120) NULL,
        telefon VARCHAR(30) NULL,
        vergi_no VARCHAR(20) NULL,
        aciklama VARCHAR(255) NULL,
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $E");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kamp_kisiler (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ad VARCHAR(80) NOT NULL,
        soyad VARCHAR(80) NOT NULL DEFAULT '',
        tc_no VARCHAR(20) NULL,
        sicil_no VARCHAR(40) NULL,
        firma_id INT NULL,
        gorev VARCHAR(120) NULL,
        kategori VARCHAR(20) NOT NULL DEFAULT 'isci',
        telefon VARCHAR(30) NULL,
        acil_kisi VARCHAR(120) NULL,
        acil_tel VARCHAR(30) NULL,
        notlar TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        KEY ix_kamp_kisi_firma (firma_id),
        KEY ix_kamp_kisi_tc (tc_no)
    ) $E");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kamp_konaklama (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kisi_id INT NOT NULL,
        yatak_id INT NULL,
        oda_id INT NULL,
        firma_id INT NULL COMMENT 'giriş anındaki firma (icmal bunu kullanır)',
        giris DATE NOT NULL,
        cikis DATE NULL COMMENT 'NULL = hâlâ kalıyor; çıkış günü gecesi sayılmaz',
        cikis_sebep VARCHAR(120) NULL,
        aciklama VARCHAR(255) NULL,
        kullanici_id INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        acik_yatak INT GENERATED ALWAYS AS (CASE WHEN cikis IS NULL THEN yatak_id END) STORED,
        acik_kisi  INT GENERATED ALWAYS AS (CASE WHEN cikis IS NULL THEN kisi_id END) STORED,
        UNIQUE KEY uq_kamp_acik_yatak (acik_yatak),
        UNIQUE KEY uq_kamp_acik_kisi (acik_kisi),
        KEY ix_kamp_kon_kisi (kisi_id),
        KEY ix_kamp_kon_yatak (yatak_id),
        KEY ix_kamp_kon_tarih (giris, cikis)
    ) $E");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kamp_demirbas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bina_id INT NULL,
        oda_id INT NULL,
        ad VARCHAR(120) NOT NULL,
        adet INT NOT NULL DEFAULT 1,
        durum VARCHAR(20) NOT NULL DEFAULT 'saglam',
        marka VARCHAR(80) NULL,
        seri_no VARCHAR(80) NULL,
        aciklama VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        KEY ix_kamp_db_oda (oda_id),
        KEY ix_kamp_db_durum (durum)
    ) $E");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kamp_demirbas_hareket (
        id INT AUTO_INCREMENT PRIMARY KEY,
        demirbas_id INT NOT NULL,
        tarih DATETIME DEFAULT CURRENT_TIMESTAMP,
        eski_durum VARCHAR(20) NULL,
        yeni_durum VARCHAR(20) NULL,
        aciklama VARCHAR(255) NULL,
        kullanici_id INT NULL,
        KEY ix_kamp_dbh (demirbas_id)
    ) $E");

    $kuruldu = true;
}

// ─────────────────────────────────────────────────────────────── yapı
function kamp_binalar(PDO $pdo, bool $pasifDahil = false): array
{
    static $onb = [];
    $k = $pasifDahil ? 'h' : 'a';
    if (!isset($onb[$k])) {
        $onb[$k] = [];
        foreach ($pdo->query("SELECT * FROM kamp_binalar" . ($pasifDahil ? '' : ' WHERE aktif=1') . " ORDER BY sira, ad") as $r)
            $onb[$k][(int)$r['id']] = $r;
    }
    return $onb[$k];
}

/**
 * Bir binanın (ya da hepsinin) oda + yatak + şu an yatan kişi ağacı.
 * Dönüş: [bina_id => [kat => [oda_id => oda + ['yataklar' => [...]]]]]
 */
function kamp_yapi(PDO $pdo, ?int $binaId = null, bool $pasifOda = false): array
{
    $w = []; $p = [];
    if ($binaId) { $w[] = 'o.bina_id=?'; $p[] = $binaId; }
    if (!$pasifOda) $w[] = 'o.aktif=1';
    $st = $pdo->prepare("SELECT o.* FROM kamp_odalar o" . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
                      . " ORDER BY o.bina_id, o.kat, o.sira, o.ad");
    $st->execute($p);
    $odalar = [];
    foreach ($st->fetchAll() as $o) { $o['yataklar'] = []; $odalar[(int)$o['id']] = $o; }
    if ($odalar) {
        $in = implode(',', array_map('intval', array_keys($odalar)));
        $yt = $pdo->query("SELECT y.*, k.id AS konaklama_id, k.giris, k.kisi_id, p.ad AS kisi_ad, p.soyad AS kisi_soyad,
                                  p.kategori, p.gorev, p.telefon, f.ad AS firma_ad
                             FROM kamp_yataklar y
                             LEFT JOIN kamp_konaklama k ON k.yatak_id = y.id AND k.cikis IS NULL
                             LEFT JOIN kamp_kisiler p ON p.id = k.kisi_id
                             LEFT JOIN kamp_firmalar f ON f.id = COALESCE(k.firma_id, p.firma_id)
                            WHERE y.oda_id IN ($in)
                            ORDER BY y.sira, y.id")->fetchAll();
        foreach ($yt as $y) $odalar[(int)$y['oda_id']]['yataklar'][] = $y;
    }
    $agac = [];
    foreach ($odalar as $id => $o) $agac[(int)$o['bina_id']][(int)$o['kat']][$id] = $o;
    return $agac;
}

/** Oda sayaçları: aktif yatak / dolu / kullanım dışı. */
function kamp_oda_sayac(array $oda): array
{
    $s = ['yatak' => 0, 'dolu' => 0, 'disi' => 0];
    foreach ($oda['yataklar'] ?? [] as $y) {
        if (!(int)$y['aktif']) { $s['disi']++; continue; }
        $s['yatak']++;
        if (!empty($y['konaklama_id'])) $s['dolu']++;
    }
    return $s;
}

/** Bina bazında doluluk (yalnız yatak barındıran, aktif odalar). */
function kamp_doluluk(PDO $pdo): array
{
    $sql = "SELECT b.id, b.ad, b.tur,
                   COUNT(DISTINCT CASE WHEN " . kamp_yatakli_sql('o') . " THEN o.id END) AS oda,
                   SUM(CASE WHEN y.aktif=1 AND " . kamp_yatakli_sql('o') . " THEN 1 ELSE 0 END) AS yatak,
                   SUM(CASE WHEN y.aktif=1 AND " . kamp_yatakli_sql('o') . " AND k.id IS NOT NULL THEN 1 ELSE 0 END) AS dolu,
                   SUM(CASE WHEN y.aktif=0 THEN 1 ELSE 0 END) AS disi
              FROM kamp_binalar b
              LEFT JOIN kamp_odalar o ON o.bina_id = b.id AND o.aktif = 1
              LEFT JOIN kamp_yataklar y ON y.oda_id = o.id
              LEFT JOIN kamp_konaklama k ON k.yatak_id = y.id AND k.cikis IS NULL
             WHERE b.aktif = 1
             GROUP BY b.id, b.ad, b.tur, b.sira
             ORDER BY b.sira, b.ad";
    $o = [];
    foreach ($pdo->query($sql) as $r) {
        foreach (['oda','yatak','dolu','disi'] as $k) $r[$k] = (int)$r[$k];
        $r['bos'] = $r['yatak'] - $r['dolu'];
        $o[(int)$r['id']] = $r;
    }
    return $o;
}

/** Boş (yerleştirilebilir) yataklar — taşıma / yerleştirme menüleri için. */
function kamp_bos_yataklar(PDO $pdo): array
{
    return $pdo->query("SELECT y.id, y.no, y.tur, o.id AS oda_id, o.ad AS oda_ad, o.kat, o.unite, b.id AS bina_id, b.ad AS bina_ad
                          FROM kamp_yataklar y
                          JOIN kamp_odalar o ON o.id = y.oda_id AND o.aktif = 1 AND " . kamp_yatakli_sql('o') . "
                          JOIN kamp_binalar b ON b.id = o.bina_id AND b.aktif = 1
                          LEFT JOIN kamp_konaklama k ON k.yatak_id = y.id AND k.cikis IS NULL
                         WHERE y.aktif = 1 AND k.id IS NULL
                         ORDER BY b.sira, b.ad, o.kat, o.sira, o.ad, y.sira, y.id")->fetchAll();
}

/** Yatağın tam adı: "Staff Binası › Zemin Kat › Z-03A › Yatak 1". */
function kamp_yatak_etiket(array $r, bool $bina = true): string
{
    $p = [];
    if ($bina && !empty($r['bina_ad'])) $p[] = $r['bina_ad'];
    if (isset($r['kat']) && $r['kat'] !== null) $p[] = kamp_kat_ad((int)$r['kat']);
    if (!empty($r['oda_ad'])) $p[] = $r['oda_ad'];
    if (!empty($r['yatak_no']) || !empty($r['no'])) $p[] = 'Yatak ' . ($r['yatak_no'] ?? $r['no']);
    return implode(' › ', $p);
}

/** Yatak + oda + bina bilgisini tek satırda getirir. */
function kamp_yatak_bul(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT y.*, y.no AS yatak_no, o.ad AS oda_ad, o.kat, o.tur AS oda_tur, o.aktif AS oda_aktif,
                                o.bina_id, b.ad AS bina_ad, b.aktif AS bina_aktif
                           FROM kamp_yataklar y
                           JOIN kamp_odalar o ON o.id = y.oda_id
                           JOIN kamp_binalar b ON b.id = o.bina_id
                          WHERE y.id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// ─────────────────────────────────────────────────────────────── kişi / firma
function kamp_firmalar(PDO $pdo, bool $pasifDahil = false): array
{
    static $onb = [];
    $k = $pasifDahil ? 'h' : 'a';
    if (!isset($onb[$k])) {
        $onb[$k] = [];
        foreach ($pdo->query("SELECT * FROM kamp_firmalar" . ($pasifDahil ? '' : ' WHERE aktif=1') . " ORDER BY ad") as $r)
            $onb[$k][(int)$r['id']] = $r;
    }
    return $onb[$k];
}

function kamp_firma_options(PDO $pdo, $secili = null, string $bos = '— firma seçin —'): string
{
    $o = '<option value="">' . htmlspecialchars($bos) . '</option>';
    $liste = kamp_firmalar($pdo);
    // Pasif ama seçili firma kaybolmasın
    if ($secili && !isset($liste[(int)$secili])) {
        $tum = kamp_firmalar($pdo, true);
        if (isset($tum[(int)$secili])) $liste = [(int)$secili => $tum[(int)$secili]] + $liste;
    }
    foreach ($liste as $id => $f)
        $o .= '<option value="' . $id . '"' . ((int)$secili === $id ? ' selected' : '') . '>' . htmlspecialchars($f['ad']) . '</option>';
    return $o;
}

/** Firma adını bulur (normalize); yoksa ve $ac ise açar. Dönüş: id | null. */
function kamp_firma_bul(PDO $pdo, string $ad, bool $ac = false): ?int
{
    $n = kamp_norm($ad);
    if ($n === '') return null;
    foreach ($pdo->query("SELECT id, ad FROM kamp_firmalar") as $f)
        if (kamp_norm($f['ad']) === $n) return (int)$f['id'];
    if (!$ac) return null;
    $pdo->prepare("INSERT INTO kamp_firmalar (ad) VALUES (?)")->execute([kamp_buyuk($ad)]);
    return (int)$pdo->lastInsertId();
}

function kamp_kisi_bul(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT p.*, f.ad AS firma_ad FROM kamp_kisiler p LEFT JOIN kamp_firmalar f ON f.id = p.firma_id WHERE p.id=?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Kişinin AÇIK konaklaması (yer bilgisiyle) ya da null. */
function kamp_acik_konaklama(PDO $pdo, int $kisiId): ?array
{
    $st = $pdo->prepare("SELECT k.*, y.no AS yatak_no, o.ad AS oda_ad, o.kat, o.bina_id, b.ad AS bina_ad
                           FROM kamp_konaklama k
                           LEFT JOIN kamp_yataklar y ON y.id = k.yatak_id
                           LEFT JOIN kamp_odalar o ON o.id = COALESCE(y.oda_id, k.oda_id)
                           LEFT JOIN kamp_binalar b ON b.id = o.bina_id
                          WHERE k.kisi_id = ? AND k.cikis IS NULL");
    $st->execute([$kisiId]);
    return $st->fetch() ?: null;
}

/**
 * Kişi listesi + şu anki yeri + son konaklaması (tek sorgu ailesi).
 * durum: kampta (açık konaklaması var) | ayrildi (konaklaması var, açık yok) | kayitsiz (hiç yok)
 */
function kamp_kisi_liste(PDO $pdo): array
{
    $sql = "SELECT p.*, f.ad AS firma_ad,
                   ak.id AS konaklama_id, ak.giris AS acik_giris, ak.yatak_id,
                   y.no AS yatak_no, o.ad AS oda_ad, o.kat, o.bina_id, b.ad AS bina_ad,
                   (SELECT MAX(k2.cikis) FROM kamp_konaklama k2 WHERE k2.kisi_id = p.id) AS son_cikis,
                   (SELECT COUNT(*) FROM kamp_konaklama k3 WHERE k3.kisi_id = p.id) AS konaklama_sayi
              FROM kamp_kisiler p
              LEFT JOIN kamp_firmalar f ON f.id = p.firma_id
              LEFT JOIN kamp_konaklama ak ON ak.kisi_id = p.id AND ak.cikis IS NULL
              LEFT JOIN kamp_yataklar y ON y.id = ak.yatak_id
              LEFT JOIN kamp_odalar o ON o.id = COALESCE(y.oda_id, ak.oda_id)
              LEFT JOIN kamp_binalar b ON b.id = o.bina_id
             ORDER BY p.ad, p.soyad";
    $o = [];
    foreach ($pdo->query($sql) as $r) {
        $r['durum'] = $r['konaklama_id'] ? 'kampta' : ((int)$r['konaklama_sayi'] > 0 ? 'ayrildi' : 'kayitsiz');
        $o[] = $r;
    }
    return $o;
}

/**
 * Türkçe harf duyarsız, kelime sırası serbest süzme (SQL LIKE'ta 'İ' ↔ 'i' eşleşmez;
 * ad + soyad birlikte yazılınca tek alanda geçmediği için sonuç çıkmıyordu — IT/PTS deseni).
 */
function kamp_kisi_suz(array $liste, string $q): array
{
    $kel = array_values(array_filter(explode(' ', kamp_norm($q))));
    if (!$kel) return $liste;
    return array_values(array_filter($liste, function ($p) use ($kel) {
        $hay = ' ' . kamp_norm(kamp_kisi_ad($p) . ' ' . ($p['tc_no'] ?? '') . ' ' . ($p['sicil_no'] ?? '') . ' '
             . ($p['firma_ad'] ?? '') . ' ' . ($p['gorev'] ?? '') . ' ' . ($p['telefon'] ?? '') . ' '
             . ($p['oda_ad'] ?? '') . ' ' . ($p['bina_ad'] ?? '')) . ' ';
        foreach ($kel as $k) if (!str_contains($hay, $k)) return false;
        return true;
    }));
}

/** Yerleştirme penceresindeki arama (JSON) — en çok 25 sonuç, önce ad başı eşleşenler. */
function kamp_kisi_ara(PDO $pdo, string $q): array
{
    $liste = kamp_kisi_suz(kamp_kisi_liste($pdo), $q);
    $nq = kamp_norm($q);
    usort($liste, function ($a, $b) use ($nq) {
        $pa = str_starts_with(kamp_norm(kamp_kisi_ad($a)), $nq) ? 0 : 1;
        $pb = str_starts_with(kamp_norm(kamp_kisi_ad($b)), $nq) ? 0 : 1;
        return $pa <=> $pb ?: strcmp(kamp_kisi_ad($a), kamp_kisi_ad($b));
    });
    $o = [];
    foreach (array_slice($liste, 0, 25) as $r) {
        $o[] = [
            'id'     => (int)$r['id'],
            'ad'     => kamp_kisi_ad($r),
            'firma'  => (string)($r['firma_ad'] ?? ''),
            'gorev'  => (string)($r['gorev'] ?? ''),
            'kat'    => KAMP_KATEGORI[$r['kategori']][0] ?? '',
            'yer'    => $r['konaklama_id'] ? kamp_yatak_etiket($r) : '',
            'giris'  => $r['acik_giris'] ? kamp_tarih_yaz($r['acik_giris']) : '',
        ];
    }
    return $o;
}

/**
 * Yeni kişi kaydı (form + hızlı yerleştirme + içe aktarma ortak).
 * Mükerrer: TC no / sicil no aynıysa YENİ AÇILMAZ, mevcut id döner (['yeni'=>false]).
 */
function kamp_kisi_ekle(PDO $pdo, array $v): array
{
    $ad = kamp_buyuk($v['ad'] ?? '');
    $soyad = kamp_buyuk($v['soyad'] ?? '');
    if ($ad === '' && $soyad === '') throw new RuntimeException('Ad soyad boş olamaz.');
    $tc = preg_replace('/\D+/', '', (string)($v['tc_no'] ?? ''));
    $sicil = trim((string)($v['sicil_no'] ?? ''));
    if ($tc !== '') {
        $st = $pdo->prepare("SELECT id FROM kamp_kisiler WHERE tc_no=?"); $st->execute([$tc]);
        if ($id = $st->fetchColumn()) return ['id' => (int)$id, 'yeni' => false, 'neden' => 'TC no'];
    }
    if ($sicil !== '') {
        $st = $pdo->prepare("SELECT id FROM kamp_kisiler WHERE sicil_no=?"); $st->execute([$sicil]);
        if ($id = $st->fetchColumn()) return ['id' => (int)$id, 'yeni' => false, 'neden' => 'sicil no'];
    }
    $kat = isset(KAMP_KATEGORI[$v['kategori'] ?? '']) ? $v['kategori'] : 'isci';
    $pdo->prepare("INSERT INTO kamp_kisiler (ad, soyad, tc_no, sicil_no, firma_id, gorev, kategori, telefon, acil_kisi, acil_tel, notlar)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$ad !== '' ? $ad : $soyad, $ad !== '' ? $soyad : '', $tc ?: null, $sicil ?: null,
                   ((int)($v['firma_id'] ?? 0)) ?: null, kamp_buyuk($v['gorev'] ?? '') ?: null, $kat,
                   trim((string)($v['telefon'] ?? '')) ?: null, kamp_buyuk($v['acil_kisi'] ?? '') ?: null,
                   trim((string)($v['acil_tel'] ?? '')) ?: null, trim((string)($v['notlar'] ?? '')) ?: null]);
    return ['id' => (int)$pdo->lastInsertId(), 'yeni' => true];
}

// ─────────────────────────────────────────────────────────────── konaklama
/**
 * Kişinin/yatağın verilen tarihten itibaren (açık uçlu ya da $bit'e kadar) başka bir
 * konaklamayla çakışıp çakışmadığı. $haric: kontrol dışı tutulacak kayıt (düzenlenen).
 * Aralık [giris, cikis) — çıkış günü bir sonraki kişinin giriş günü olabilir.
 */
function kamp_cakisma(PDO $pdo, string $kolon, int $deger, string $giris, ?string $cikis, int $haric = 0): ?array
{
    if (!in_array($kolon, ['kisi_id', 'yatak_id'], true)) throw new InvalidArgumentException('kolon');
    $sql = "SELECT k.*, p.ad, p.soyad FROM kamp_konaklama k LEFT JOIN kamp_kisiler p ON p.id = k.kisi_id
             WHERE k.$kolon = ? AND k.id <> ? AND (k.cikis IS NULL OR k.cikis > ?)";
    $par = [$deger, $haric, $giris];
    if ($cikis !== null) { $sql .= " AND k.giris < ?"; $par[] = $cikis; }
    $st = $pdo->prepare($sql . " ORDER BY k.giris LIMIT 1");
    $st->execute($par);
    return $st->fetch() ?: null;
}

/**
 * Kişiyi yatağa yerleştirir. Kişi başka yatakta kalıyorsa o konaklama $tarih ile kapanır
 * ve yenisi açılır (TAŞIMA — "Yatak değişikliği" sebebiyle; geçmiş korunur).
 * Tek transaction; yatak kilitlenir; DB'deki UNIQUE (acik_yatak/acik_kisi) son güvencedir.
 */
function kamp_yerlestir(PDO $pdo, int $kisiId, int $yatakId, string $tarih, string $aciklama = '', ?int $userId = null): array
{
    $tarih = kamp_tarih($tarih) ?? throw new RuntimeException('Geçersiz giriş tarihi.');
    if ($tarih > date('Y-m-d')) throw new RuntimeException('Giriş tarihi bugünden ileri olamaz.');
    $kisi = kamp_kisi_bul($pdo, $kisiId) ?? throw new RuntimeException('Kişi bulunamadı.');

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id FROM kamp_yataklar WHERE id=? FOR UPDATE"); $st->execute([$yatakId]);
        $y = kamp_yatak_bul($pdo, $yatakId) ?? throw new RuntimeException('Yatak bulunamadı.');
        if (!(int)$y['aktif'])      throw new RuntimeException('Bu yatak kullanım dışı.');
        if (!(int)$y['oda_aktif'] || !(int)$y['bina_aktif']) throw new RuntimeException('Oda / bina pasif.');
        if (!kamp_oda_yatakli($y['oda_tur'])) throw new RuntimeException('Bu oda yatak odası değil (' . (KAMP_ODA_TUR[$y['oda_tur']][0] ?? $y['oda_tur']) . ').');

        $eski = kamp_acik_konaklama($pdo, $kisiId);
        if ($eski && (int)$eski['yatak_id'] === $yatakId) throw new RuntimeException(kamp_kisi_ad($kisi) . ' zaten bu yatakta kalıyor.');
        if ($eski && $tarih < $eski['giris']) throw new RuntimeException('Taşınma tarihi (' . kamp_tarih_yaz($tarih)
            . ') kişinin mevcut yatağa giriş tarihinden (' . kamp_tarih_yaz($eski['giris']) . ') önce olamaz.');

        // Yatakta bu tarihten sonra kalan var mı (açık konaklama ya da geriye dönük girişte sonradan kapanmış kayıt)
        $dolu = kamp_cakisma($pdo, 'yatak_id', $yatakId, $tarih, null);
        if ($dolu) throw new RuntimeException(($dolu['cikis'] ? 'Bu yatakta o tarihte başka biri kalıyordu: ' : 'Yatak dolu: ')
            . trim($dolu['ad'] . ' ' . $dolu['soyad'])
            . ' (' . kamp_tarih_yaz($dolu['giris']) . ($dolu['cikis'] ? ' → ' . kamp_tarih_yaz($dolu['cikis']) : ' → hâlâ') . ').');

        $tasindi = false;
        if ($eski) {
            $pdo->prepare("UPDATE kamp_konaklama SET cikis=?, cikis_sebep=? WHERE id=?")
                ->execute([$tarih, 'Yatak değişikliği → ' . $y['bina_ad'] . ' › ' . $y['oda_ad'] . ' › ' . $y['yatak_no'], (int)$eski['id']]);
            $tasindi = true;
        }
        // Kişinin geçmiş kayıtlarıyla çakışma (geriye dönük girişlerde)
        if ($c = kamp_cakisma($pdo, 'kisi_id', $kisiId, $tarih, null))
            throw new RuntimeException(kamp_kisi_ad($kisi) . ' bu tarihte başka bir yatakta kayıtlı ('
                . kamp_tarih_yaz($c['giris']) . ' → ' . kamp_tarih_yaz($c['cikis']) . ').');

        $pdo->prepare("INSERT INTO kamp_konaklama (kisi_id, yatak_id, oda_id, firma_id, giris, aciklama, kullanici_id)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$kisiId, $yatakId, (int)$y['oda_id'], $kisi['firma_id'] ?: null, $tarih,
                       trim($aciklama) ?: null, $userId]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['id' => $id, 'tasindi' => $tasindi, 'yer' => kamp_yatak_etiket($y)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && ($e->errorInfo[0] ?? '') === '23000')
            throw new RuntimeException('Yatak ya da kişi az önce başka bir kayıtla eşleşti — sayfayı yenileyip tekrar deneyin.');
        throw $e;
    }
}

/** Açık konaklamayı kapatır (çıkış). */
function kamp_cikis(PDO $pdo, int $konaklamaId, string $tarih, string $sebep = '', string $aciklama = ''): array
{
    $tarih = kamp_tarih($tarih) ?? throw new RuntimeException('Geçersiz çıkış tarihi.');
    if ($tarih > date('Y-m-d')) throw new RuntimeException('Çıkış tarihi bugünden ileri olamaz.');
    $st = $pdo->prepare("SELECT * FROM kamp_konaklama WHERE id=?"); $st->execute([$konaklamaId]);
    $k = $st->fetch() ?: throw new RuntimeException('Konaklama kaydı bulunamadı.');
    if ($k['cikis']) throw new RuntimeException('Bu konaklama zaten ' . kamp_tarih_yaz($k['cikis']) . ' tarihinde kapanmış.');
    if ($tarih < $k['giris']) throw new RuntimeException('Çıkış tarihi giriş tarihinden (' . kamp_tarih_yaz($k['giris']) . ') önce olamaz.');
    $not = trim(($k['aciklama'] ? $k['aciklama'] . ' · ' : '') . $aciklama, ' ·');
    $pdo->prepare("UPDATE kamp_konaklama SET cikis=?, cikis_sebep=?, aciklama=? WHERE id=?")
        ->execute([$tarih, trim($sebep) ?: null, $not ?: null, $konaklamaId]);
    return $k;
}

/** Geçmiş konaklama ekleme (içe aktarma — çıkışı belli eski kayıt). */
function kamp_konaklama_gecmis(PDO $pdo, int $kisiId, ?int $yatakId, int $odaId, string $giris, string $cikis, string $sebep = ''): int
{
    if ($cikis < $giris) throw new RuntimeException('Çıkış girişten önce.');
    if ($c = kamp_cakisma($pdo, 'kisi_id', $kisiId, $giris, $cikis))
        throw new RuntimeException('kişinin ' . kamp_tarih_yaz($c['giris']) . ' tarihli kaydıyla çakışıyor');
    if ($yatakId && ($c = kamp_cakisma($pdo, 'yatak_id', $yatakId, $giris, $cikis)))
        throw new RuntimeException('yatakta ' . trim($c['ad'] . ' ' . $c['soyad']) . ' kayıtlı');
    $kisi = kamp_kisi_bul($pdo, $kisiId);
    $pdo->prepare("INSERT INTO kamp_konaklama (kisi_id, yatak_id, oda_id, firma_id, giris, cikis, cikis_sebep) VALUES (?,?,?,?,?,?,?)")
        ->execute([$kisiId, $yatakId, $odaId, $kisi['firma_id'] ?? null, $giris, $cikis, $sebep ?: null]);
    return (int)$pdo->lastInsertId();
}

/** Konaklama tarihlerini düzeltir (yanlış girilen tarih). Çakışma denetimli. */
function kamp_konaklama_duzelt(PDO $pdo, int $id, string $giris, ?string $cikis): void
{
    $giris = kamp_tarih($giris) ?? throw new RuntimeException('Geçersiz giriş tarihi.');
    $cikis = $cikis !== null && trim($cikis) !== '' ? (kamp_tarih($cikis) ?? throw new RuntimeException('Geçersiz çıkış tarihi.')) : null;
    if ($giris > date('Y-m-d') || ($cikis && $cikis > date('Y-m-d'))) throw new RuntimeException('Tarih bugünden ileri olamaz.');
    if ($cikis && $cikis < $giris) throw new RuntimeException('Çıkış girişten önce olamaz.');
    $st = $pdo->prepare("SELECT * FROM kamp_konaklama WHERE id=?"); $st->execute([$id]);
    $k = $st->fetch() ?: throw new RuntimeException('Kayıt bulunamadı.');
    if ($c = kamp_cakisma($pdo, 'kisi_id', (int)$k['kisi_id'], $giris, $cikis, $id))
        throw new RuntimeException('Kişinin ' . kamp_tarih_yaz($c['giris']) . ' tarihli başka konaklamasıyla çakışıyor.');
    if ($k['yatak_id'] && ($c = kamp_cakisma($pdo, 'yatak_id', (int)$k['yatak_id'], $giris, $cikis, $id)))
        throw new RuntimeException('Aynı yatakta ' . trim($c['ad'] . ' ' . $c['soyad']) . ' ile çakışıyor ('
            . kamp_tarih_yaz($c['giris']) . ').');
    try {
        $pdo->prepare("UPDATE kamp_konaklama SET giris=?, cikis=? WHERE id=?")->execute([$giris, $cikis, $id]);
    } catch (PDOException $e) {
        if (($e->errorInfo[0] ?? '') === '23000') throw new RuntimeException('Çıkışı geri almak mümkün değil: kişi ya da yatak şu an başka bir açık kayıtta.');
        throw $e;
    }
}

/** Belirli bir GECE kampta kalanlar: giriş ≤ tarih < çıkış (ya da açık). */
function kamp_tarihte(PDO $pdo, string $tarih): array
{
    $st = $pdo->prepare("SELECT k.*, p.ad, p.soyad, p.kategori, p.gorev, p.telefon, f.ad AS firma_ad, COALESCE(k.firma_id, p.firma_id) AS fid,
                                y.no AS yatak_no, o.ad AS oda_ad, o.kat, b.ad AS bina_ad, b.id AS bina_id
                           FROM kamp_konaklama k
                           JOIN kamp_kisiler p ON p.id = k.kisi_id
                           LEFT JOIN kamp_firmalar f ON f.id = COALESCE(k.firma_id, p.firma_id)
                           LEFT JOIN kamp_yataklar y ON y.id = k.yatak_id
                           LEFT JOIN kamp_odalar o ON o.id = COALESCE(y.oda_id, k.oda_id)
                           LEFT JOIN kamp_binalar b ON b.id = o.bina_id
                          WHERE k.giris <= ? AND (k.cikis IS NULL OR k.cikis > ?)
                          ORDER BY b.sira, b.ad, o.kat, o.sira, o.ad, y.sira, y.id");
    $st->execute([$tarih, $tarih]);
    return $st->fetchAll();
}

/**
 * Aylık icmal — "ay sonu dökümü": firma bazında kişi / kişi-gece / giren / çıkan / ay sonu kalan
 * + kişi bazında satırlar. Ay devam ediyorsa bugüne kadar sayılır.
 */
function kamp_icmal(PDO $pdo, string $ay): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
    $bas = $ay . '-01';
    $bit = date('Y-m-t', strtotime($bas));
    $sonGun = min($bit, date('Y-m-d'));               // sayımın son günü
    $st = $pdo->prepare("SELECT k.*, p.ad, p.soyad, p.tc_no, p.kategori, p.gorev, COALESCE(k.firma_id, p.firma_id) AS fid,
                                f.ad AS firma_ad, y.no AS yatak_no, o.ad AS oda_ad, o.kat, b.ad AS bina_ad
                           FROM kamp_konaklama k
                           JOIN kamp_kisiler p ON p.id = k.kisi_id
                           LEFT JOIN kamp_firmalar f ON f.id = COALESCE(k.firma_id, p.firma_id)
                           LEFT JOIN kamp_yataklar y ON y.id = k.yatak_id
                           LEFT JOIN kamp_odalar o ON o.id = COALESCE(y.oda_id, k.oda_id)
                           LEFT JOIN kamp_binalar b ON b.id = o.bina_id
                          WHERE k.giris <= ? AND (k.cikis IS NULL OR k.cikis >= ?)
                          ORDER BY f.ad, p.ad, p.soyad, k.giris");
    $st->execute([$bit, $bas]);
    $firma = []; $kisi = []; $top = ['kisi' => 0, 'gece' => 0, 'giren' => 0, 'cikan' => 0, 'kalan' => 0];
    $kisiSet = [];
    foreach ($st->fetchAll() as $r) {
        $gece  = $sonGun >= $bas ? kamp_gece($r['giris'], $r['cikis'], $bas, $sonGun) : 0;
        $giren = $r['giris'] >= $bas && $r['giris'] <= $bit;
        $cikan = $r['cikis'] && $r['cikis'] >= $bas && $r['cikis'] <= $bit && !str_starts_with((string)$r['cikis_sebep'], 'Yatak değişikliği');
        $kalan = !$r['cikis'] || $r['cikis'] > $sonGun;
        // Yatak değişikliğinde açılan ikinci kayıt yeni "giriş" sayılmaz — kişi zaten kamptaydı
        if ($giren) {
            $s = $pdo->prepare("SELECT 1 FROM kamp_konaklama WHERE kisi_id=? AND cikis=? AND cikis_sebep LIKE 'Yatak değişikliği%' LIMIT 1");
            $s->execute([(int)$r['kisi_id'], $r['giris']]);
            if ($s->fetchColumn()) $giren = false;
        }
        $fid = (int)($r['fid'] ?? 0);
        $fa  = $r['firma_ad'] ?: '(firma girilmemiş)';
        $firma[$fid] ??= ['firma_id' => $fid, 'firma' => $fa, 'kisi' => [], 'gece' => 0, 'giren' => 0, 'cikan' => 0, 'kalan' => 0];
        $firma[$fid]['kisi'][(int)$r['kisi_id']] = true;
        $firma[$fid]['gece']  += $gece;
        $firma[$fid]['giren'] += $giren ? 1 : 0;
        $firma[$fid]['cikan'] += $cikan ? 1 : 0;
        $firma[$fid]['kalan'] += $kalan ? 1 : 0;
        $kisiSet[(int)$r['kisi_id']] = true;
        $top['gece'] += $gece; $top['giren'] += $giren ? 1 : 0; $top['cikan'] += $cikan ? 1 : 0; $top['kalan'] += $kalan ? 1 : 0;
        $r['gece'] = $gece; $r['giren'] = $giren; $r['cikan'] = $cikan; $r['kalan'] = $kalan;
        $kisi[] = $r;
    }
    foreach ($firma as &$f) $f['kisi'] = count($f['kisi']);
    unset($f);
    uasort($firma, fn($a, $b) => $b['gece'] <=> $a['gece'] ?: strcmp($a['firma'], $b['firma']));
    $top['kisi'] = count($kisiSet);
    return ['ay' => $ay, 'bas' => $bas, 'bit' => $bit, 'son_gun' => $sonGun, 'firma' => array_values($firma),
            'kisi' => $kisi, 'toplam' => $top];
}

/** Dashboard özeti. */
function kamp_ozet(PDO $pdo): array
{
    $o = ['yatak' => 0, 'dolu' => 0, 'bos' => 0, 'disi' => 0, 'oda' => 0];
    foreach (kamp_doluluk($pdo) as $b) foreach (['yatak','dolu','bos','disi','oda'] as $k) $o[$k] += $b[$k];
    $bugun = date('Y-m-d'); $ayBas = date('Y-m-01');
    $q = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
    $yd = "cikis_sebep LIKE 'Yatak değişikliği%'";
    $o['kampta']      = $q("SELECT COUNT(*) FROM kamp_konaklama WHERE cikis IS NULL");
    $o['bugun_giris'] = $q("SELECT COUNT(*) FROM kamp_konaklama k WHERE giris=? AND NOT EXISTS (SELECT 1 FROM kamp_konaklama x WHERE x.kisi_id=k.kisi_id AND x.cikis=k.giris AND x.$yd)", [$bugun]);
    $o['ay_giris']    = $q("SELECT COUNT(*) FROM kamp_konaklama k WHERE giris>=? AND NOT EXISTS (SELECT 1 FROM kamp_konaklama x WHERE x.kisi_id=k.kisi_id AND x.cikis=k.giris AND x.$yd)", [$ayBas]);
    $o['ay_cikis']    = $q("SELECT COUNT(*) FROM kamp_konaklama WHERE cikis>=? AND (cikis_sebep IS NULL OR NOT $yd)", [$ayBas]);
    $o['kisi']        = $q("SELECT COUNT(*) FROM kamp_kisiler");
    $o['firma']       = $q("SELECT COUNT(DISTINCT COALESCE(k.firma_id, p.firma_id)) FROM kamp_konaklama k JOIN kamp_kisiler p ON p.id=k.kisi_id WHERE k.cikis IS NULL");
    $o['db_sorun']    = $q("SELECT COUNT(*) FROM kamp_demirbas WHERE durum IN ('arizali','bakimda')");
    $o['db_kayip']    = $q("SELECT COUNT(*) FROM kamp_demirbas WHERE durum IN ('kayip')");
    $o['bina']        = count(kamp_binalar($pdo));
    return $o;
}

// ─────────────────────────────────────────────────────────────── yapı üretici
/**
 * Hızlı yapı üretici: bir binaya kat × ünite × oda × yatak düzenini toplu açar.
 *  $o: katlar (int[]), unite (kat başına konteyner sayısı; 0 = ünitesiz koğuş düzeni),
 *      unite_oda (1|2 — konteyner ikiye bölünmüşse 2: sol A / sağ B), ortak (bool: ünitede
 *      ortak banyo/WC kaydı), oda (ünitesizde kat başına oda), yatak (oda başına),
 *      yatak_tur (tek | ranza2 | ranza3), oda_tur (yatak | kogus), bas (ilk numara)
 * Aynı adlı oda varsa ATLANIR (tekrar çalıştırmak mükerrer açmaz).
 */
function kamp_yapi_uret(PDO $pdo, int $binaId, array $o): array
{
    $katlar = array_values(array_unique(array_map('intval', $o['katlar'] ?? [0])));
    $unite  = max(0, min(60, (int)($o['unite'] ?? 0)));
    $uOda   = (int)($o['unite_oda'] ?? 2) === 1 ? 1 : 2;
    $ortak  = !empty($o['ortak']);
    $odaN   = max(0, min(120, (int)($o['oda'] ?? 0)));
    $yatakN = max(0, min(40, (int)($o['yatak'] ?? 1)));
    $yTur   = in_array($o['yatak_tur'] ?? 'tek', ['tek','ranza2','ranza3'], true) ? $o['yatak_tur'] : 'tek';
    $odaTur = isset(KAMP_ODA_TUR[$o['oda_tur'] ?? '']) ? $o['oda_tur'] : ($unite ? 'yatak' : 'kogus');
    $basNo  = max(1, (int)($o['bas'] ?? 1));
    if (!$katlar) throw new RuntimeException('En az bir kat seçin.');
    if (!$unite && !$odaN) throw new RuntimeException('Konteyner ya da oda sayısı girin.');

    $var = [];
    $st = $pdo->prepare("SELECT ad FROM kamp_odalar WHERE bina_id=?"); $st->execute([$binaId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) $var[kamp_norm($a)] = true;
    $sira = (int)$pdo->query("SELECT COALESCE(MAX(sira),0) FROM kamp_odalar WHERE bina_id=" . (int)$binaId)->fetchColumn();

    $insO = $pdo->prepare("INSERT INTO kamp_odalar (bina_id, kat, unite, konum, ad, tur, sira) VALUES (?,?,?,?,?,?,?)");
    $s = ['oda' => 0, 'yatak' => 0, 'atlanan' => 0];
    $plan = [];   // [kat, unite, konum, ad, tur, yatakli]
    foreach ($katlar as $kat) {
        $kk = kamp_kat_kisa($kat);
        if ($unite) {
            for ($u = $basNo; $u < $basNo + $unite; $u++) {
                $un = sprintf('%s-%02d', $kk, $u);
                if ($uOda === 2) {
                    $plan[] = [$kat, $un, 'sol', $un . 'A', $odaTur, true];
                    if ($ortak) $plan[] = [$kat, $un, 'orta', $un . ' Ortak', 'ortak', false];
                    $plan[] = [$kat, $un, 'sag', $un . 'B', $odaTur, true];
                } else {
                    $plan[] = [$kat, $un, 'sol', $un, $odaTur, true];
                    if ($ortak) $plan[] = [$kat, $un, 'orta', $un . ' Ortak', 'ortak', false];
                }
            }
        } else {
            for ($n = $basNo; $n < $basNo + $odaN; $n++) $plan[] = [$kat, null, null, sprintf('%s-%02d', $kk, $n), $odaTur, true];
        }
    }
    $pdo->beginTransaction();
    try {
        foreach ($plan as [$kat, $un, $konum, $ad, $tur, $yatakli]) {
            if (isset($var[kamp_norm($ad)])) { $s['atlanan']++; continue; }
            $insO->execute([$binaId, $kat, $un, $konum, $ad, $tur, ++$sira]);
            $odaId = (int)$pdo->lastInsertId();
            $var[kamp_norm($ad)] = true;
            $s['oda']++;
            if ($yatakli && $yatakN) $s['yatak'] += kamp_yatak_ekle($pdo, $odaId, $yatakN, $yTur);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $s;
}

/**
 * Odaya N yatak ekler (numaralar mevcutların devamı). ranza2: 1-Alt/1-Üst, ranza3: + 1-Orta.
 * Dönüş: eklenen yatak sayısı.
 */
function kamp_yatak_ekle(PDO $pdo, int $odaId, int $adet, string $tur = 'tek'): int
{
    if ($adet <= 0) return 0;
    $mevcut = $pdo->prepare("SELECT no, sira FROM kamp_yataklar WHERE oda_id=?"); $mevcut->execute([$odaId]);
    $var = []; $sira = 0; $enBuyuk = 0;
    foreach ($mevcut->fetchAll() as $r) {
        $var[$r['no']] = true; $sira = max($sira, (int)$r['sira']);
        if (preg_match('/^(\d+)/', $r['no'], $m)) $enBuyuk = max($enBuyuk, (int)$m[1]);
    }
    $ins = $pdo->prepare("INSERT INTO kamp_yataklar (oda_id, no, tur, sira) VALUES (?,?,?,?)");
    $eklenen = 0; $n = $enBuyuk;
    $katman = $tur === 'ranza3' ? [['Alt','ranza_alt'],['Orta','ranza_orta'],['Üst','ranza_ust']]
            : ($tur === 'ranza2' ? [['Alt','ranza_alt'],['Üst','ranza_ust']] : null);
    while ($eklenen < $adet) {
        $n++;
        if (!$katman) {
            if (isset($var[(string)$n])) continue;
            $ins->execute([$odaId, (string)$n, 'tek', ++$sira]); $eklenen++;
        } else {
            foreach ($katman as [$et, $yt]) {
                if ($eklenen >= $adet) break;
                $no = $n . '-' . $et;
                if (isset($var[$no])) continue;
                $ins->execute([$odaId, $no, $yt, ++$sira]); $eklenen++;
            }
        }
    }
    return $eklenen;
}

/**
 * Odanın aktif yatak sayısını hedefe getirir: fazlası varsa BOŞ yataklar kaldırılır
 * (hiç kullanılmamışsa silinir, geçmişi varsa "kullanım dışı" yapılır — konaklama
 * geçmişi kopmasın). Dolu yatak asla kaldırılmaz.
 */
function kamp_yatak_sayisi_ayarla(PDO $pdo, int $odaId, int $hedef, string $tur = 'tek'): array
{
    $hedef = max(0, min(60, $hedef));
    $st = $pdo->prepare("SELECT y.*, (SELECT COUNT(*) FROM kamp_konaklama k WHERE k.yatak_id=y.id) AS gecmis,
                                (SELECT COUNT(*) FROM kamp_konaklama k WHERE k.yatak_id=y.id AND k.cikis IS NULL) AS dolu
                           FROM kamp_yataklar y WHERE y.oda_id=? AND y.aktif=1 ORDER BY y.sira DESC, y.id DESC");
    $st->execute([$odaId]);
    $aktif = $st->fetchAll();
    $s = ['eklenen' => 0, 'silinen' => 0, 'kapatilan' => 0];
    $fark = count($aktif) - $hedef;
    if ($fark < 0) { $s['eklenen'] = kamp_yatak_ekle($pdo, $odaId, -$fark, $tur); return $s; }
    foreach ($aktif as $y) {
        if ($fark <= 0) break;
        if ((int)$y['dolu']) continue;
        if ((int)$y['gecmis']) { $pdo->prepare("UPDATE kamp_yataklar SET aktif=0 WHERE id=?")->execute([(int)$y['id']]); $s['kapatilan']++; }
        else { $pdo->prepare("DELETE FROM kamp_yataklar WHERE id=?")->execute([(int)$y['id']]); $s['silinen']++; }
        $fark--;
    }
    if ($fark > 0) throw new RuntimeException("Odada $fark yatak daha DOLU — önce kalanları çıkarın ya da taşıyın.");
    return $s;
}

// ─────────────────────────────────────────────────────────────── demirbaş
function kamp_demirbas_durum(PDO $pdo, int $id, string $yeni, string $aciklama = '', ?int $userId = null): void
{
    if (!isset(KAMP_DB_DURUM[$yeni])) throw new RuntimeException('Geçersiz durum.');
    $st = $pdo->prepare("SELECT durum FROM kamp_demirbas WHERE id=?"); $st->execute([$id]);
    $eski = $st->fetchColumn();
    if ($eski === false) throw new RuntimeException('Demirbaş bulunamadı.');
    if ($eski === $yeni && trim($aciklama) === '') return;
    $pdo->prepare("UPDATE kamp_demirbas SET durum=?, updated_at=NOW() WHERE id=?")->execute([$yeni, $id]);
    $pdo->prepare("INSERT INTO kamp_demirbas_hareket (demirbas_id, eski_durum, yeni_durum, aciklama, kullanici_id) VALUES (?,?,?,?,?)")
        ->execute([$id, $eski, $yeni, trim($aciklama) ?: null, $userId]);
}

/** Odanın demirbaşları (ad sırasıyla). */
function kamp_oda_demirbas(PDO $pdo, int $odaId): array
{
    $st = $pdo->prepare("SELECT * FROM kamp_demirbas WHERE oda_id=? ORDER BY ad, id"); $st->execute([$odaId]);
    return $st->fetchAll();
}

/**
 * İşlem günlüğü ANA veritabanının audit_log tablosuna yazılır (kamp DB'sinde audit_log yok;
 * aktivite raporu tek yerden okur). Bağlantı kurulamazsa sessizce geçer — günlük işlemin
 * tamamlayıcısıdır, ön koşulu değil.
 */
function kamp_audit(string $tablo, int $id, string $islem, $eski = null, $yeni = null): void
{
    static $p = false;
    if ($p === false) {
        $p = null;
        try {
            if (($GLOBALS['pdo'] ?? null) instanceof PDO) $p = $GLOBALS['pdo'];
            elseif (defined('DB_HOST')) $p = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                                              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) { $p = null; }
    }
    if ($p && function_exists('audit_log')) audit_log($p, $tablo, $id, $islem, $eski, $yeni, function_exists('current_user_id') ? current_user_id() : null);
}

/** Güvenli geri dönüş adresi: yalnız bu modülün sayfaları (açık yönlendirme olmasın). */
function kamp_geri(?string $u, string $varsayilan = 'index.php'): string
{
    $u = (string)$u;
    return preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-+:#]*)?(#[a-z0-9_-]+)?$/', $u) ? $u : $varsayilan;
}
