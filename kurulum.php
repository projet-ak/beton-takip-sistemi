<?php
/**
 * kurulum.php — Tek tıkla veritabanı kurulumu
 * Çalıştırdıktan sonra bu dosyayı silin.
 */
if (!file_exists(__DIR__ . '/config.php')) {
    die('<b>HATA:</b> config.php bulunamadı.');
}
require_once __DIR__ . '/config.php';

$log = [];
$hata = null;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Güvenlik: ilk kurulumdan sonra (users tablosu doluysa) yalnız admin çalıştırabilir.
    $__kuruldu = false;
    try { $__kuruldu = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0; } catch (Throwable $e) { $__kuruldu = false; }
    if ($__kuruldu) {
        require_once __DIR__ . '/includes/functions.php';
        require_once __DIR__ . '/includes/auth.php';
        require_auth(['admin']);
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    $tablolar = [
        'beton_siniflari' => "CREATE TABLE IF NOT EXISTS beton_siniflari (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(60) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'katki_listesi' => "CREATE TABLE IF NOT EXISTS katki_listesi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(100) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'pompa_turleri' => "CREATE TABLE IF NOT EXISTS pompa_turleri (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(100) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'firmalar' => "CREATE TABLE IF NOT EXISTS firmalar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(100) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'imalat_gruplari' => "CREATE TABLE IF NOT EXISTS imalat_gruplari (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(100) NOT NULL,
            sira INT NOT NULL DEFAULT 0,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'ana_is_kalemleri' => "CREATE TABLE IF NOT EXISTS ana_is_kalemleri (
            id INT AUTO_INCREMENT PRIMARY KEY,
            imalat_grup_id INT NOT NULL,
            ad VARCHAR(150) NOT NULL,
            sira INT NOT NULL DEFAULT 0,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            FOREIGN KEY (imalat_grup_id) REFERENCES imalat_gruplari(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'tedarikciler' => "CREATE TABLE IF NOT EXISTS tedarikciler (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(150) NOT NULL,
            vkn VARCHAR(15) NULL,
            telefon VARCHAR(30) NULL,
            adres TEXT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'kivam_siniflari' => "CREATE TABLE IF NOT EXISTS kivam_siniflari (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(20) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'parseller' => "CREATE TABLE IF NOT EXISTS parseller (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(100) NOT NULL,
            proje_id INT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'bloklar' => "CREATE TABLE IF NOT EXISTS bloklar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parsel_id INT NOT NULL,
            ad VARCHAR(100) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            FOREIGN KEY (parsel_id) REFERENCES parseller(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'kotlar' => "CREATE TABLE IF NOT EXISTS kotlar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            blok_id INT NOT NULL,
            kot_degeri VARCHAR(20) NOT NULL,
            aciklama VARCHAR(200) NULL,
            sira INT NOT NULL DEFAULT 0,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            FOREIGN KEY (blok_id) REFERENCES bloklar(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'projeler' => "CREATE TABLE IF NOT EXISTS projeler (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kod VARCHAR(20) NOT NULL UNIQUE,
            aciklama VARCHAR(200) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NOT NULL DEFAULT '',
            role ENUM('admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo') NOT NULL DEFAULT 'teknik_ofis',
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'irsaliyeler' => "CREATE TABLE IF NOT EXISTS irsaliyeler (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tip ENUM('alis','iade') NOT NULL DEFAULT 'alis',
            durum ENUM('beklemede','saha_onaylandi','onaylandi','reddedildi') NOT NULL DEFAULT 'beklemede',
            sira_no INT NULL,
            fatura_no VARCHAR(100) NULL,
            arac_plaka VARCHAR(20) NULL,
            kivam_sinifi_id INT NULL,
            irsaliye_no VARCHAR(100) NULL,
            proje_no VARCHAR(50) NULL,
            proje_id INT NULL,
            tedarikci_id INT NOT NULL,
            tarih DATE NOT NULL,
            mikser_cikis_saati TIME NULL,
            kantar_giris_saati TIME NULL,
            kantar_cikis_saati TIME NULL,
            kantar_net_yildizlar DECIMAL(8,2) NULL,
            kantar_net_tedarikci DECIMAL(8,2) NULL,
            kantar_farki DECIMAL(8,2) NULL,
            beton_sinifi_id INT NULL,
            miktar DECIMAL(10,2) NOT NULL DEFAULT 0,
            birim VARCHAR(10) NOT NULL DEFAULT 'M3',
            pompa_id INT NULL,
            katki1_id INT NULL,
            katki2_id INT NULL,
            firma_id INT NULL,
            imalat_grup_id INT NULL,
            ana_is_kalemi_id INT NULL,
            parsel_id INT NULL,
            blok_id INT NULL,
            kot_id INT NULL,
            saha_onaylayan_id INT NULL,
            saha_onay_tarih DATETIME NULL,
            teknik_onaylayan_id INT NULL,
            teknik_onay_tarih DATETIME NULL,
            red_neden VARCHAR(500) NULL,
            aciklama TEXT NULL,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_durum (durum),
            INDEX idx_tip_tarih (tip, tarih),
            FOREIGN KEY (kivam_sinifi_id)    REFERENCES kivam_siniflari(id)  ON DELETE SET NULL,
            FOREIGN KEY (proje_id)           REFERENCES projeler(id)         ON DELETE SET NULL,
            FOREIGN KEY (tedarikci_id)       REFERENCES tedarikciler(id)     ON DELETE RESTRICT,
            FOREIGN KEY (beton_sinifi_id)    REFERENCES beton_siniflari(id)  ON DELETE SET NULL,
            FOREIGN KEY (pompa_id)           REFERENCES pompa_turleri(id)    ON DELETE SET NULL,
            FOREIGN KEY (katki1_id)          REFERENCES katki_listesi(id)    ON DELETE SET NULL,
            FOREIGN KEY (katki2_id)          REFERENCES katki_listesi(id)    ON DELETE SET NULL,
            FOREIGN KEY (firma_id)           REFERENCES firmalar(id)         ON DELETE SET NULL,
            FOREIGN KEY (imalat_grup_id)     REFERENCES imalat_gruplari(id)  ON DELETE SET NULL,
            FOREIGN KEY (ana_is_kalemi_id)   REFERENCES ana_is_kalemleri(id) ON DELETE SET NULL,
            FOREIGN KEY (parsel_id)          REFERENCES parseller(id)        ON DELETE SET NULL,
            FOREIGN KEY (blok_id)            REFERENCES bloklar(id)          ON DELETE SET NULL,
            FOREIGN KEY (kot_id)             REFERENCES kotlar(id)           ON DELETE SET NULL,
            FOREIGN KEY (created_by)         REFERENCES users(id)            ON DELETE SET NULL,
            FOREIGN KEY (updated_by)         REFERENCES users(id)            ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'irsaliye_fotolar' => "CREATE TABLE IF NOT EXISTS irsaliye_fotolar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            irsaliye_id INT NOT NULL,
            dosya_adi VARCHAR(255) NOT NULL,
            dosya_yolu VARCHAR(500) NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (irsaliye_id) REFERENCES irsaliyeler(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by)  REFERENCES users(id)        ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'audit_log' => "CREATE TABLE IF NOT EXISTS audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tablo VARCHAR(80) NOT NULL,
            kayit_id INT NULL,
            islem ENUM('INSERT','UPDATE','DELETE') NOT NULL,
            eski_deger JSON NULL,
            yeni_deger JSON NULL,
            kullanici_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_tablo_kayit (tablo, kayit_id),
            FOREIGN KEY (kullanici_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'kullanici_oturum' => "CREATE TABLE IF NOT EXISTS kullanici_oturum (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kullanici_id INT NOT NULL,
            oturum_key VARCHAR(128) NOT NULL,
            giris DATETIME NOT NULL,
            son_aktivite DATETIME NOT NULL,
            sayfa_sayisi INT NOT NULL DEFAULT 0,
            ip VARCHAR(45) NULL,
            tarayici VARCHAR(255) NULL,
            UNIQUE KEY uq_oturum (oturum_key),
            INDEX (kullanici_id), INDEX (giris)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'kullanici_aktivite' => "CREATE TABLE IF NOT EXISTS kullanici_aktivite (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            kullanici_id INT NOT NULL,
            oturum_key VARCHAR(128) NULL,
            sayfa VARCHAR(120) NULL,
            modul VARCHAR(20) NULL,
            yontem VARCHAR(8) NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (kullanici_id), INDEX (created_at), INDEX (oturum_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'beton_metraj' => "CREATE TABLE IF NOT EXISTS beton_metraj (
            id INT AUTO_INCREMENT PRIMARY KEY,
            seviye ENUM('kot','blok','kalem') NOT NULL,
            firma_id INT NULL,
            imalat_grup_id INT NULL,
            ana_is_kalemi_id INT NULL,
            parsel_id INT NULL,
            blok_id INT NULL,
            kot_id INT NULL,
            teorik_m3 DECIMAL(12,3) NOT NULL DEFAULT 0,
            limit_yuzde DECIMAL(5,2) NOT NULL DEFAULT 5.00 COMMENT 'fore kazık %15, diğerleri %5',
            aciklama VARCHAR(300) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY (seviye), KEY (kot_id), KEY (blok_id), KEY (ana_is_kalemi_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tablolar as $ad => $sql) {
        $pdo->exec($sql);
        $log[] = ['ok', "$ad tablosu oluşturuldu / zaten mevcuttu"];
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    // ── Fatura eşleştirme (faturalar tablosu + irsaliyeler.fatura_id) ─────────
    require_once __DIR__ . '/includes/fatura.php';
    fat_semasi_kur($pdo);
    $log[] = ['ok', 'faturalar tablosu oluşturuldu / zaten mevcuttu (+ irsaliyeler.fatura_id)'];

    // ── İndeksleri garanti et (mevcut DB'lerde CREATE TABLE indeks eklemez) ────
    $idxEnsure = [
        ['irsaliyeler', 'idx_tip_tarih', '(tip, tarih)'],
        ['audit_log',   'idx_created',      '(created_at)'],
        ['audit_log',   'idx_tablo_kayit',  '(tablo, kayit_id)'],
    ];
    foreach ($idxEnsure as [$tbl, $idx, $cols]) {
        try {
            $var = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema=DATABASE() AND table_name='{$tbl}' AND index_name='{$idx}'")->fetchColumn();
            if ($var === 0) { $pdo->exec("ALTER TABLE {$tbl} ADD INDEX {$idx} {$cols}"); $log[] = ['ok', "{$tbl}.{$idx} indeksi eklendi"]; }
        } catch (Throwable $e) { /* tablo yoksa geç */ }
    }

    // ── Referans verileri ─────────────────────────────────────────────────────

    $beton_siniflari = ['C16','C20','C25','C30','C35','C40','KURU SHOTCRETE','YAŞ SHOTCRETE','ŞAP 200','ŞAP 300'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO beton_siniflari (ad) VALUES (?)");
    foreach ($beton_siniflari as $v) { $stmt->execute([$v]); }
    $log[] = ['ok', 'Beton sınıfları eklendi'];

    $katki_listesi = ['-','ANTİFRİZ','S4 SLUMP FARKI','KATKISIZ','SU GEÇİRİMSİZLİK','BRÜT BETON','DMAX','ARDGERME','BETON SUYU ISITMA'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO katki_listesi (ad) VALUES (?)");
    foreach ($katki_listesi as $v) { $stmt->execute([$v]); }
    $log[] = ['ok', 'Katkı listesi eklendi'];

    $pompa_turleri = ['POMPALI','MİKSERLİ','MOBİL POMPA FİYAT FARKI','HİDROLİK DAĞITICI POMPA','ÖRÜMCEK POMPA','SABİT POMPA'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO pompa_turleri (ad) VALUES (?)");
    foreach ($pompa_turleri as $v) { $stmt->execute([$v]); }
    $log[] = ['ok', 'Pompa türleri eklendi'];

    $firmalar = ['DENER','OSMAN_CAMCI','YILDIZLAR','SONGUR','KESINTI','KABA_FIRMA_1','KABA_FIRMA_2','KABA_FIRMA_3'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO firmalar (ad) VALUES (?)");
    foreach ($firmalar as $v) { $stmt->execute([$v]); }
    $log[] = ['ok', 'Firmalar eklendi'];

    $kivam_siniflari = ['S1','S2','S3','S4','S5'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO kivam_siniflari (ad) VALUES (?)");
    foreach ($kivam_siniflari as $v) { $stmt->execute([$v]); }
    $log[] = ['ok', 'Kıvam sınıfları eklendi'];

    $pdo->exec("INSERT IGNORE INTO tedarikciler (ad) VALUES ('ANADOLU BETON'), ('ANDBET')");
    $log[] = ['ok', 'Tedarikçiler eklendi'];

    $imalat_gruplari = ['Zemin','Kaba','Ince','Peyzaj','Diger'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO imalat_gruplari (ad, sira) VALUES (?, ?)");
    foreach ($imalat_gruplari as $i => $v) { $stmt->execute([$v, $i + 1]); }
    $log[] = ['ok', 'İmalat grupları eklendi'];

    $pdo->exec("INSERT IGNORE INTO projeler (kod, aciklama) VALUES
        ('U030','BATI YAKASI 1. ETAP'),
        ('U031','BATI YAKASI 2. ETAP'),
        ('U039','MİLLET BAHÇESİ')");
    $log[] = ['ok', 'Projeler eklendi'];

    // ── Admin kullanıcı ───────────────────────────────────────────────────────
    $geciciSifre = 'Beton2026!';
    $hash = password_hash($geciciSifre, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password_hash, full_name, role, aktif) VALUES (?, ?, ?, 'admin', 1)");
    $stmt->execute(['tayyar_akbulut', $hash, 'Tayyar Akbulut']);
    $stmt->execute(['admin', $hash, 'Sistem Yöneticisi']);

    $log[] = ['warn', "Admin kullanıcıları oluşturuldu — GEÇİCİ ŞİFRE: <strong>$geciciSifre</strong> (Sisteme girdikten sonra değiştirin!)"];

} catch (PDOException $e) {
    $hata = htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Kurulum — Beton Takip</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width:640px">
  <div class="card shadow">
    <div class="card-header bg-success text-white fw-bold fs-5">Beton Takip — Veritabanı Kurulumu</div>
    <div class="card-body">

      <?php if ($hata): ?>
        <div class="alert alert-danger"><strong>HATA:</strong> <?= $hata ?></div>
      <?php else: ?>
        <?php foreach ($log as [$tip, $msg]): ?>
          <?php $cls = $tip === 'ok' ? 'success' : ($tip === 'warn' ? 'warning' : 'danger'); ?>
          <div class="alert alert-<?= $cls ?> py-2 mb-2 small"><?= $msg ?></div>
        <?php endforeach; ?>

        <hr>
        <div class="alert alert-danger">
          <strong>Güvenlik:</strong> Bu dosyayı kullandıktan sonra sunucudan <strong>silin</strong>!
          <br>cPanel &rarr; File Manager &rarr; <code>kurulum.php</code> &rarr; Delete
        </div>
        <a href="login.php" class="btn btn-success btn-lg w-100">Giriş Sayfasına Git &rarr;</a>
      <?php endif; ?>

    </div>
  </div>
</div>
</body>
</html>
