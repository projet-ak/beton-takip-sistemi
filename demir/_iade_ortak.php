<?php
/**
 * demir/_iade_ortak.php — İade Tutanakları ortak yardımcıları + şema garantisi.
 * İade tutanağı: bir taşeronun (iade eden) iş sonunda elinde kalan demiri iade etmesi.
 * Teslim alan opsiyoneldir: boşsa depoya/şirkete iade, doluysa başka taşerona aktarma.
 * Bu dosyayı çağıran her iade sayfası önce require ../includes/db_demir.php yapmış olmalı.
 */

/** İade tabloları yoksa oluşturur (kurulum_demir.php tekrar çalıştırmaya gerek kalmadan). */
function iade_semasi_kur(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS demir_iade_tutanaklar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        iade_no VARCHAR(50) NULL,
        iade_tarih DATE NULL,
        iade_eden_id INT NULL,
        teslim_alan_id INT NULL,
        proje_id INT NULL,
        arac_plaka VARCHAR(20) NULL,
        dorse_plaka VARCHAR(20) NULL,
        aciklama TEXT NULL,
        evrak_url VARCHAR(500) NULL COMMENT 'imzalı yüklenen belge',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY (iade_eden_id), KEY (teslim_alan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS demir_iade_kalemleri (
        id INT AUTO_INCREMENT PRIMARY KEY,
        iade_id INT NOT NULL,
        cap_id INT NOT NULL,
        miktar_ton DECIMAL(12,3) NOT NULL DEFAULT 0,
        bag_adeti INT NULL,
        KEY (iade_id), KEY (cap_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** "1.234,5" / "1234.5" → float; boş ise null. */
function iade_num($v): ?float {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
}

/**
 * İade no üretir: {PROJE}-{IADEEDEN_KOD}-IADE-{NNN}  (ör. U030-OSM-IADE-001)
 * Aynı önekteki en büyük sırayı bulup +1 verir.
 */
function iade_no_uret(PDO $pdo, string $projeKod, string $iadeEdenKod): string {
    $prefix = ($projeKod ?: 'GN') . '-' . ($iadeEdenKod ?: 'TSR') . '-IADE-';
    $s = $pdo->prepare("SELECT iade_no FROM demir_iade_tutanaklar WHERE iade_no LIKE ?");
    $s->execute([$prefix . '%']);
    $max = 0;
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $no) {
        if (preg_match('/-(\d+)$/', (string)$no, $m)) $max = max($max, (int)$m[1]);
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
