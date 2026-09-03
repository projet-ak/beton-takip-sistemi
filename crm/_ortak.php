<?php
/**
 * _ortak.php — CRM (Üretim Arızaları) modülü ortak yardımcıları
 *
 * Kaynak: CRM sisteminden GÜNLÜK alınan "UretimArizalari" Excel raporu.
 * Rapor **o anda AÇIK olan arızaların anlık görüntüsüdür** (hepsi "Etkin",
 * Çözümlenme Tarihi 1.01.0001 = boş). Bu yüzden içe aktarma tam yenileme DEĞİL
 * **birleştirme (merge)**:
 *   - dosyada olup sistemde olmayan → YENİ arıza (durum: acik)
 *   - dosyada da sistemde de olan   → alanlar güncellenir, son_gorulme yenilenir
 *   - sistemde AÇIK ama dosyada YOK → arıza kapanmış demektir → otomatik ÇÖZÜLDÜ
 * Böylece tek bir günlük rapordan "kaç yeni geldi / kaç kapandı / ne kadar bekledi"
 * çıkar. Arıza kaydının kendi ID'si olmadığından kimlik `crm_anahtar()` ile üretilir.
 */

/** Türkçe harf duyarsız normalize (eşleştirme/arama için). */
function crm_norm(string $s): string
{
    $s = str_replace(['İ','I','ı','i','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'],
                     ['I','I','I','I','S','S','G','G','U','U','O','O','C','C'], $s);
    return preg_replace('/\s+/', ' ', mb_strtoupper(trim($s), 'UTF-8'));
}

/**
 * CRM tarih biçimi → MySQL DATETIME.
 * "30.07.2025 17:00:15" → "2025-07-30 17:00:15"; "1.01.0001 00:00:00" = BOŞ (null).
 */
function crm_tarih($v): ?string
{
    $s = trim((string)$v);
    if ($s === '' || str_starts_with($s, '1.01.0001') || str_starts_with($s, '01.01.0001')) return null;
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/', $s, $m)) {
        if ((int)$m[3] < 1900) return null;
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s . ' 00:00:00', 0, 19);
    // Excel seri numarası
    if (preg_match('/^\d{4,6}(\.\d+)?$/', $s)) {
        $n = (float)$s;
        if ($n > 1000 && $n < 100000) return date('Y-m-d H:i:s', (int)round(($n - 25569) * 86400));
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/**
 * Arıza kimliği. Excel'de ID kolonu YOK; aynı arıza her gün aynı satırla geldiğinden
 * kimlik içerikten üretilir: konut + oluşturma anı + şikayet zinciri + açıklama.
 * (Yalnız konut+tarih yetmiyor — aynı dakikada aynı daireye iki kayıt açılabiliyor.)
 */
function crm_anahtar(array $a): string
{
    return md5(implode('|', array_map('crm_norm', [
        (string)($a['konut'] ?? ''),
        (string)($a['olusturma'] ?? ''),
        (string)($a['sikayet_turu'] ?? ''),
        (string)($a['sikayet_konusu'] ?? ''),
        (string)($a['sikayet_aciklamasi'] ?? ''),
        (string)($a['ariza_tipi'] ?? ''),
        mb_substr((string)($a['aciklama'] ?? ''), 0, 120),
    ])));
}

/** Kat etiketini sıralanabilir sayıya çevirir (3. Bodrum < Zemin < 1. Kat …). */
function crm_kat_sira(string $kat): int
{
    $u = crm_norm($kat);
    if (str_contains($u, 'BODRUM')) return preg_match('/(\d+)/', $u, $m) ? -(int)$m[1] : -1;
    if (str_contains($u, 'ZEMIN'))  return 0;
    return preg_match('/(\d+)/', $u, $m) ? (int)$m[1] : 99;
}

$GLOBALS['CRM_DURUM'] = [
    'acik'    => ['ad'=>'Açık',    'renk'=>'danger',  'ikon'=>'bi-exclamation-circle'],
    'cozuldu' => ['ad'=>'Çözüldü', 'renk'=>'success', 'ikon'=>'bi-check-circle'],
];
function crm_durumAd(string $d): string { return $GLOBALS['CRM_DURUM'][$d]['ad'] ?? $d; }
function crm_durumRenk(string $d): string { return $GLOBALS['CRM_DURUM'][$d]['renk'] ?? 'secondary'; }

/** Arızanın kaç gündür açık olduğu (çözüldüyse çözüme kadar geçen gün). */
function crm_yas(array $r): int
{
    $bas = strtotime((string)($r['olusturma'] ?? '')) ?: time();
    $bit = $r['durum'] === 'cozuldu' && !empty($r['cozumlenme']) ? strtotime((string)$r['cozumlenme']) : time();
    return max(0, (int)floor(($bit - $bas) / 86400));
}

/** Şema garantisi (runtime migration; kurulum_crm.php de aynı şemayı kurar). */
function crm_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_arizalar (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        kayit_anahtari     CHAR(32) NOT NULL      COMMENT 'içerikten üretilen kimlik (crm_anahtar)',
        konut              VARCHAR(60) NULL       COMMENT 'KRT-B-A-7K-45',
        ada                VARCHAR(20) NULL,
        parsel             VARCHAR(20) NULL,
        blok               VARCHAR(20) NULL,
        kat                VARCHAR(40) NULL,
        kat_sira           INT NOT NULL DEFAULT 99,
        daire_no           VARCHAR(20) NULL,
        daire_tipi         VARCHAR(20) NULL       COMMENT '2+1, 3+1…',
        donem              VARCHAR(40) NULL       COMMENT 'Yaşam / Müşteri Teslim',
        kaynak             VARCHAR(60) NULL       COMMENT 'Müşteri / Satış Sonrası Hizmetleri',
        eksik_kusur        VARCHAR(20) NULL,
        olcek              VARCHAR(20) NULL       COMMENT 'Majör / Minör',
        aciliyet           VARCHAR(20) NULL,
        sikayet_turu       VARCHAR(60) NULL       COMMENT 'İnşaat / Mekanik / Elektrik',
        sikayet_konusu     VARCHAR(80) NULL       COMMENT 'Pencere, Mobilya…',
        sikayet_aciklamasi VARCHAR(80) NULL       COMMENT 'Cam, Çelik Kapı…',
        ariza_tipi         VARCHAR(180) NULL      COMMENT 'ÇİZİKLER VAR…',
        aciklama           TEXT NULL,
        sorumlu            VARCHAR(120) NULL,
        sonlandiran        VARCHAR(120) NULL,
        olusturma          DATETIME NULL,
        cozumlenme         DATETIME NULL,
        durum              ENUM('acik','cozuldu') NOT NULL DEFAULT 'acik',
        durum_aciklamasi   VARCHAR(60) NULL       COMMENT 'Excel Durum Açıklaması (Etkin…)',
        kapanis_kaynagi    ENUM('excel','otomatik','elle') NULL,
        ilk_gorulme        DATE NULL              COMMENT 'ilk hangi günlük raporda geldi',
        son_gorulme        DATE NULL              COMMENT 'en son hangi raporda açık görüldü',
        ic_not             TEXT NULL              COMMENT 'sistem içi not (Excel dışı)',
        evrak_url          VARCHAR(500) NULL,
        created            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_anahtar (kayit_anahtari),
        KEY idx_durum (durum),
        KEY idx_olusturma (olusturma),
        KEY idx_konut (konut),
        KEY idx_blok (blok),
        KEY idx_tur (sikayet_turu),
        KEY idx_konu (sikayet_konusu)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_import_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        dosya       VARCHAR(255) NULL,
        rapor_tarihi DATE NULL             COMMENT 'raporun ait olduğu gün',
        satir       INT NOT NULL DEFAULT 0,
        yeni        INT NOT NULL DEFAULT 0,
        guncellenen INT NOT NULL DEFAULT 0,
        kapanan     INT NOT NULL DEFAULT 0,
        kullanici   VARCHAR(100) NULL,
        created     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_created (created)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Dashboard/rapor KPI'ları. */
function crm_ozet(PDO $pdo): array
{
    crm_semasi_kur($pdo);
    $o = $pdo->query("SELECT
            COUNT(*) toplam,
            SUM(durum='acik')    acik,
            SUM(durum='cozuldu') cozuldu,
            SUM(durum='acik' AND olusturma < DATE_SUB(NOW(), INTERVAL 30 DAY)) eski30,
            SUM(durum='acik' AND olusturma < DATE_SUB(NOW(), INTERVAL 90 DAY)) eski90,
            SUM(durum='acik' AND olcek='Majör')  major,
            SUM(durum='acik' AND aciliyet='Acil') acil,
            SUM(olusturma >= DATE_FORMAT(NOW(),'%Y-%m-01')) buAyYeni,
            SUM(durum='cozuldu' AND cozumlenme >= DATE_FORMAT(NOW(),'%Y-%m-01')) buAyCozulen,
            MAX(olusturma) sonKayit
        FROM crm_arizalar")->fetch() ?: [];
    // Ortalama açık kalma (gün): açıklarda bugüne, çözülenlerde çözüm gününe kadar
    $o['ortAcikGun'] = (float)($pdo->query("SELECT AVG(DATEDIFF(NOW(), olusturma)) FROM crm_arizalar
                                            WHERE durum='acik' AND olusturma IS NOT NULL")->fetchColumn() ?: 0);
    $o['ortCozumGun'] = (float)($pdo->query("SELECT AVG(DATEDIFF(cozumlenme, olusturma)) FROM crm_arizalar
                                             WHERE durum='cozuldu' AND cozumlenme IS NOT NULL AND olusturma IS NOT NULL")->fetchColumn() ?: 0);
    foreach (['toplam','acik','cozuldu','eski30','eski90','major','acil','buAyYeni','buAyCozulen'] as $k) $o[$k] = (int)($o[$k] ?? 0);
    return $o;
}

/** Son içe aktarma kaydı (dashboard'da "rapor ne zaman güncellendi" bandı için). */
function crm_son_import(PDO $pdo): ?array
{
    try {
        $r = $pdo->query("SELECT * FROM crm_import_log ORDER BY id DESC LIMIT 1")->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Liste/rapor filtrelerini WHERE + parametreye çevirir (hepsi prepared).
 * @return array{0:string,1:array,2:array} [where sql, parametreler, etkin filtreler]
 */
function crm_filtre(array $g): array
{
    $w = []; $p = []; $etkin = [];
    $esit = [
        'durum' => 'durum', 'blok' => 'blok', 'kat' => 'kat', 'tur' => 'sikayet_turu',
        'konu'  => 'sikayet_konusu', 'detay' => 'sikayet_aciklamasi', 'tip' => 'ariza_tipi',
        'sorumlu' => 'sorumlu', 'daire_tipi' => 'daire_tipi', 'konut' => 'konut', 'olcek' => 'olcek',
    ];
    foreach ($esit as $par => $kolon) {
        $v = trim((string)($g[$par] ?? ''));
        if ($v === '') continue;
        if ($par === 'durum' && !isset($GLOBALS['CRM_DURUM'][$v])) continue;
        $w[] = "$kolon = ?"; $p[] = $v; $etkin[$par] = $v;
    }
    foreach (['bas' => '>=', 'bit' => '<='] as $par => $op) {
        $v = trim((string)($g[$par] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) continue;
        $w[] = "olusturma $op ?"; $p[] = $v . ($op === '<=' ? ' 23:59:59' : ' 00:00:00'); $etkin[$par] = $v;
    }
    $ara = trim((string)($g['ara'] ?? ''));
    if ($ara !== '') {
        $w[] = "(konut LIKE ? OR aciklama LIKE ? OR ariza_tipi LIKE ? OR sikayet_aciklamasi LIKE ? OR daire_no LIKE ?)";
        for ($i = 0; $i < 5; $i++) $p[] = '%' . $ara . '%';
        $etkin['ara'] = $ara;
    }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p, $etkin];
}

/** Bir kolonun benzersiz değerleri (filtre açılır menüleri için). */
function crm_secenekler(PDO $pdo, string $kolon): array
{
    $izin = ['blok','kat','sikayet_turu','sikayet_konusu','sikayet_aciklamasi','ariza_tipi','sorumlu','daire_tipi','olcek','konut'];
    if (!in_array($kolon, $izin, true)) return [];   // whitelist — asla ham input
    // Kat metin sıralaması yanlış olur ("10. Kat" < "2. Kat") — kat_sira kolonuna göre sıralanır.
    // (SELECT DISTINCT ... ORDER BY <select'te olmayan kolon> MySQL'de hata verir, bu yüzden GROUP BY.)
    $sql = $kolon === 'kat'
        ? "SELECT kat v FROM crm_arizalar WHERE kat IS NOT NULL AND kat <> '' GROUP BY kat, kat_sira ORDER BY kat_sira"
        : "SELECT DISTINCT $kolon v FROM crm_arizalar WHERE $kolon IS NOT NULL AND $kolon <> '' ORDER BY $kolon";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
}
