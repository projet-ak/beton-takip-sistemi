<?php
/**
 * demir/kurulum_demir.php — Demir modülü veritabanı kurulumu
 * Tek sefer çalıştırın; tabloları oluşturur ve başlangıç verilerini ekler.
 * Çalıştırdıktan sonra silmeniz önerilir.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_auth(['admin']);
require_once __DIR__ . '/../includes/db_demir.php';

$log = [];
$hata = null;

try {
    $pdoDemir->exec("SET FOREIGN_KEY_CHECKS=0");

    $tablolar = [
        'demir_caplar' => "CREATE TABLE IF NOT EXISTS demir_caplar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(40) NOT NULL,
            tip ENUM('duz','kangal','hasir','spiral') NOT NULL DEFAULT 'duz',
            birim_agirlik DECIMAL(8,4) NULL COMMENT 'kg/m (teorik)',
            bag_kg DECIMAL(10,2) NULL COMMENT '1 bağ ortalama kg (çapa göre değişir)',
            sira INT NOT NULL DEFAULT 0,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_tedarikciler' => "CREATE TABLE IF NOT EXISTS demir_tedarikciler (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(150) NOT NULL,
            vkn VARCHAR(15) NULL,
            telefon VARCHAR(30) NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_taseronlar' => "CREATE TABLE IF NOT EXISTS demir_taseronlar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(150) NOT NULL,
            kod VARCHAR(20) NULL COMMENT 'tutanak no öneki (ör. DNR)',
            vkn VARCHAR(15) NULL,
            telefon VARCHAR(30) NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_projeler' => "CREATE TABLE IF NOT EXISTS demir_projeler (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kod VARCHAR(20) NOT NULL UNIQUE,
            aciklama VARCHAR(200) NOT NULL DEFAULT '',
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_siparisler' => "CREATE TABLE IF NOT EXISTS demir_siparisler (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ifs_talep_no VARCHAR(50) NULL,
            ifs_siparis_no VARCHAR(50) NULL,
            gonderen_firma VARCHAR(150) NULL,
            siparisi_olusturan VARCHAR(150) NULL,
            imalat VARCHAR(200) NULL,
            taseron_id INT NULL,
            proje_id INT NULL,
            sozlesme_id INT NULL,
            tarih DATE NULL,
            durum ENUM('acik','kismi','tamam','iptal') NOT NULL DEFAULT 'acik',
            aciklama TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (taseron_id) REFERENCES demir_taseronlar(id) ON DELETE SET NULL,
            FOREIGN KEY (proje_id)   REFERENCES demir_projeler(id)   ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_siparis_kalemleri' => "CREATE TABLE IF NOT EXISTS demir_siparis_kalemleri (
            id INT AUTO_INCREMENT PRIMARY KEY,
            siparis_id INT NOT NULL,
            cap_id INT NOT NULL,
            miktar_ton DECIMAL(12,3) NOT NULL DEFAULT 0,
            FOREIGN KEY (siparis_id) REFERENCES demir_siparisler(id) ON DELETE CASCADE,
            FOREIGN KEY (cap_id)     REFERENCES demir_caplar(id)     ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_sevkiyatlar' => "CREATE TABLE IF NOT EXISTS demir_sevkiyatlar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kod VARCHAR(120) NULL,
            irsaliye_no VARCHAR(100) NULL,
            irsaliye_tarih DATE NULL,
            gelis_tarih DATE NULL,
            arac_plaka VARCHAR(20) NULL,
            dorse_plaka VARCHAR(20) NULL,
            kantar_fis_no VARCHAR(50) NULL,
            tedarikci_id INT NULL,
            ifs_siparis_no VARCHAR(50) NULL,
            siparis_id INT NULL,
            getiren_firma VARCHAR(150) NULL,
            ifs_giris_durumu VARCHAR(50) NULL,
            tir_plaka VARCHAR(20) NULL,
            proje_id INT NULL,
            taseron_id INT NULL,
            scan_image_url VARCHAR(500) NULL,
            aciklama TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_irsaliye (irsaliye_no),
            INDEX idx_tarih (irsaliye_tarih),
            FOREIGN KEY (tedarikci_id) REFERENCES demir_tedarikciler(id) ON DELETE SET NULL,
            FOREIGN KEY (siparis_id)   REFERENCES demir_siparisler(id)   ON DELETE SET NULL,
            FOREIGN KEY (proje_id)     REFERENCES demir_projeler(id)     ON DELETE SET NULL,
            FOREIGN KEY (taseron_id)   REFERENCES demir_taseronlar(id)   ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_sevkiyat_kalemleri' => "CREATE TABLE IF NOT EXISTS demir_sevkiyat_kalemleri (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sevkiyat_id INT NOT NULL,
            cap_id INT NOT NULL,
            irsaliye_miktar DECIMAL(12,3) NOT NULL DEFAULT 0,
            kantar_miktar DECIMAL(12,3) NULL,
            FOREIGN KEY (sevkiyat_id) REFERENCES demir_sevkiyatlar(id) ON DELETE CASCADE,
            FOREIGN KEY (cap_id)      REFERENCES demir_caplar(id)      ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_tutanaklar' => "CREATE TABLE IF NOT EXISTS demir_tutanaklar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tutanak_no VARCHAR(50) NULL,
            tutanak_tarih DATE NULL,
            taseron_id INT NULL,
            proje_id INT NULL,
            sozlesme_id INT NULL,
            arac_plaka VARCHAR(20) NULL,
            dorse_plaka VARCHAR(20) NULL,
            sozlesme_kapsami TEXT NULL,
            evrak_url VARCHAR(500) NULL COMMENT 'imzalı yüklenen belge',
            aciklama TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (taseron_id) REFERENCES demir_taseronlar(id) ON DELETE SET NULL,
            FOREIGN KEY (proje_id)   REFERENCES demir_projeler(id)   ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_tutanak_kalemleri' => "CREATE TABLE IF NOT EXISTS demir_tutanak_kalemleri (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tutanak_id INT NOT NULL,
            sevkiyat_id INT NULL,
            irsaliye_no VARCHAR(100) NULL,
            cap_id INT NOT NULL,
            miktar_ton DECIMAL(12,3) NOT NULL DEFAULT 0,
            bag_adeti INT NULL,
            FOREIGN KEY (tutanak_id)  REFERENCES demir_tutanaklar(id) ON DELETE CASCADE,
            FOREIGN KEY (sevkiyat_id) REFERENCES demir_sevkiyatlar(id) ON DELETE SET NULL,
            FOREIGN KEY (cap_id)      REFERENCES demir_caplar(id)      ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_iade_tutanaklar' => "CREATE TABLE IF NOT EXISTS demir_iade_tutanaklar (
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
            FOREIGN KEY (iade_eden_id)   REFERENCES demir_taseronlar(id) ON DELETE SET NULL,
            FOREIGN KEY (teslim_alan_id) REFERENCES demir_taseronlar(id) ON DELETE SET NULL,
            FOREIGN KEY (proje_id)       REFERENCES demir_projeler(id)   ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_iade_kalemleri' => "CREATE TABLE IF NOT EXISTS demir_iade_kalemleri (
            id INT AUTO_INCREMENT PRIMARY KEY,
            iade_id INT NOT NULL,
            cap_id INT NOT NULL,
            miktar_ton DECIMAL(12,3) NOT NULL DEFAULT 0,
            bag_adeti INT NULL,
            FOREIGN KEY (iade_id) REFERENCES demir_iade_tutanaklar(id) ON DELETE CASCADE,
            FOREIGN KEY (cap_id)  REFERENCES demir_caplar(id)          ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_sozlesmeler' => "CREATE TABLE IF NOT EXISTS demir_sozlesmeler (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sozlesme_no VARCHAR(60) NOT NULL,
            taseron_id INT NOT NULL,
            proje_id INT NULL,
            tarih DATE NULL,
            konu VARCHAR(300) NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (taseron_id) REFERENCES demir_taseronlar(id) ON DELETE CASCADE,
            FOREIGN KEY (proje_id)   REFERENCES demir_projeler(id)   ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'demir_hurda' => "CREATE TABLE IF NOT EXISTS demir_hurda (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hurda_no VARCHAR(50) NULL,
            taseron_id INT NOT NULL,
            tarih DATE NULL,
            miktar_ton DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'çaptan bağımsız hurda satışı',
            aciklama VARCHAR(300) NULL,
            evrak_url VARCHAR(500) NULL COMMENT 'imzalı hurda tutanağı',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (taseron_id) REFERENCES demir_taseronlar(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tablolar as $ad => $sql) {
        $pdoDemir->exec($sql);
        $log[] = ['ok', "$ad tablosu oluşturuldu / mevcuttu"];
    }
    // Mevcut kurulumlara sonradan eklenen kolonlar
    foreach ([['demir_siparisler','sozlesme_id','INT NULL AFTER proje_id'],
              ['demir_tutanaklar','sozlesme_id','INT NULL AFTER proje_id']] as $col) {
        try {
            if (!$pdoDemir->query("SHOW COLUMNS FROM {$col[0]} LIKE '{$col[1]}'")->fetchColumn()) {
                $pdoDemir->exec("ALTER TABLE {$col[0]} ADD COLUMN {$col[1]} {$col[2]}");
                $log[] = ['ok', "{$col[0]}.{$col[1]} kolonu eklendi"];
            }
        } catch (Throwable $e) {}
    }
    $pdoDemir->exec("SET FOREIGN_KEY_CHECKS=1");

    // ── Çaplar (teorik ağırlık kg/m ≈ 0.006165 × d²) ──────────────────────────
    // [ad, tip, birim_agirlik(kg/m), sıra]
    $caplar = [
        ['Ø8','duz',0.395], ['Ø10','duz',0.617], ['Ø12','duz',0.888], ['Ø14','duz',1.208],
        ['Ø16','duz',1.578], ['Ø18','duz',1.998], ['Ø20','duz',2.466], ['Ø22','duz',2.984],
        ['Ø24','duz',3.551], ['Ø26','duz',4.168], ['Ø28','duz',4.834], ['Ø30','duz',5.549],
        ['Ø32','duz',6.313],
        ['Q10 Kangal','kangal',0.617], ['Q12 Kangal','kangal',0.888], ['Q14 Kangal','kangal',1.208],
        ['Spiral Ø10','spiral',0.617], ['Spiral Ø12','spiral',0.888],
        ['Çelik Hasır Q188','hasir',null], ['Çelik Hasır Q257','hasir',null],
    ];
    $st = $pdoDemir->prepare("INSERT INTO demir_caplar (ad, tip, birim_agirlik, sira) VALUES (?,?,?,?)");
    $mevcut = $pdoDemir->query("SELECT COUNT(*) FROM demir_caplar")->fetchColumn();
    if ($mevcut == 0) {
        foreach ($caplar as $i => $c) { $st->execute([$c[0], $c[1], $c[2], $i + 1]); }
        $log[] = ['ok', count($caplar) . ' çap eklendi'];
    } else {
        $log[] = ['ok', 'Çaplar zaten mevcut (atlandı)'];
    }

    // ── Tedarikçiler / Taşeronlar / Projeler ──────────────────────────────────
    foreach (['ÇAKIROĞLU','ALİCANGÜL'] as $t) {
        $pdoDemir->prepare("INSERT INTO demir_tedarikciler (ad) SELECT ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM demir_tedarikciler WHERE ad=?)")->execute([$t,$t]);
    }
    $log[] = ['ok', 'Tedarikçiler eklendi'];

    $taseronlar = [['DENER','DNR'],['OSMAN CAMCI','OSM'],['PRP İNŞAAT','PRP'],['YILDIZLAR','YLD']];
    foreach ($taseronlar as $t) {
        $pdoDemir->prepare("INSERT INTO demir_taseronlar (ad, kod) SELECT ?,? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM demir_taseronlar WHERE ad=?)")->execute([$t[0],$t[1],$t[0]]);
    }
    $log[] = ['ok', 'Taşeronlar eklendi'];

    $projeler = [['U030','BATI YAKASI 1. ETAP'],['U031','BATI YAKASI 2. ETAP'],['U039','MİLLET BAHÇESİ']];
    foreach ($projeler as $p) {
        $pdoDemir->prepare("INSERT IGNORE INTO demir_projeler (kod, aciklama) VALUES (?,?)")->execute([$p[0],$p[1]]);
    }
    $log[] = ['ok', 'Projeler eklendi'];

} catch (PDOException $e) {
    $hata = htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="tr"><head><meta charset="UTF-8"><title>Demir Kurulum</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"></head>
<body class="bg-light"><div class="container mt-5" style="max-width:640px">
<div class="card shadow"><div class="card-header bg-dark text-white fw-bold fs-5">Demir Takip — Veritabanı Kurulumu</div>
<div class="card-body">
<?php
$__aktifDb = defined('DEMIR_DB_NAME') && DEMIR_DB_NAME !== '' ? DEMIR_DB_NAME : DB_NAME;
$__ayriMi  = defined('DEMIR_DB_NAME') && DEMIR_DB_NAME !== '' && DEMIR_DB_NAME !== DB_NAME;
?>
<div class="alert <?= $__ayriMi ? 'alert-success' : 'alert-warning' ?> py-2 mb-3 small">
    <strong>Aktif veritabanı:</strong> <code><?= h($__aktifDb) ?></code>
    <?php if ($__ayriMi): ?>
        — ✓ Beton'dan ayrı veritabanı kullanılıyor.
    <?php else: ?>
        — ⚠ Beton ile <strong>aynı</strong> veritabanı. Ayırmak için config.php'ye
        <code>define('DEMIR_DB_NAME','takbulut_demir');</code> ekleyip bu sayfayı tekrar açın.
    <?php endif; ?>
</div>
<?php if ($hata): ?>
    <div class="alert alert-danger"><strong>HATA:</strong> <?= $hata ?></div>
<?php else: ?>
    <?php foreach ($log as [$tip,$msg]): ?>
        <div class="alert alert-<?= $tip==='ok'?'success':'warning' ?> py-2 mb-2 small"><?= $msg ?></div>
    <?php endforeach; ?>
    <hr>
    <a href="index.php" class="btn btn-dark btn-lg w-100">Demir Takip'e Git &rarr;</a>
<?php endif; ?>
</div></div></div></body></html>
