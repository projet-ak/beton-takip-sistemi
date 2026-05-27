<?php
/**
 * install.php — Beton Takip Sistemi Kurulum Sihirbazı (3 Adım)
 */
session_start();

$step  = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';

// ─── ADIM 1: DB bağlantı testi + admin bilgileri ─────────────────────────────
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host      = trim($_POST['db_host']     ?? '');
    $dbname    = trim($_POST['db_name']     ?? '');
    $dbuser    = trim($_POST['db_user']     ?? '');
    $dbpass    = $_POST['db_pass']          ?? '';
    $adminUser = trim($_POST['admin_user']  ?? '');
    $adminPass = $_POST['admin_pass']       ?? '';
    $adminPass2= $_POST['admin_pass2']      ?? '';
    $adminName = trim($_POST['admin_name']  ?? '');

    if (!$host || !$dbname || !$dbuser || !$adminUser || !$adminPass) {
        $error = 'Lütfen tüm zorunlu alanları doldurun.';
    } elseif (strlen($adminPass) < 6) {
        $error = 'Admin şifresi en az 6 karakter olmalıdır.';
    } elseif ($adminPass !== $adminPass2) {
        $error = 'Admin şifreleri eşleşmiyor.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $dbuser,
                $dbpass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $_SESSION['install'] = compact('host', 'dbname', 'dbuser', 'dbpass', 'adminUser', 'adminPass', 'adminName');
            header('Location: install.php?step=2');
            exit;
        } catch (PDOException $e) {
            $error = 'Veritabanı bağlantısı kurulamadı: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ─── ADIM 2: Tabloları oluştur + verileri ekle ───────────────────────────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['install'])) {
        header('Location: install.php?step=1');
        exit;
    }
    $cfg = $_SESSION['install'];

    try {
        $pdo = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4",
            $cfg['dbuser'],
            $cfg['dbpass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

        // ── Referans tablolar ────────────────────────────────────────────────

        $pdo->exec("CREATE TABLE IF NOT EXISTS beton_siniflari (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(60) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS katki_listesi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(100) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS pompa_turleri (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(100) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS firmalar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(100) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS imalat_gruplari (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(100) NOT NULL,
            sira INT NOT NULL DEFAULT 0,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ana_is_kalemleri (
            id INT AUTO_INCREMENT PRIMARY KEY,
            imalat_grup_id INT NOT NULL,
            ad VARCHAR(150) NOT NULL,
            sira INT NOT NULL DEFAULT 0,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            FOREIGN KEY (imalat_grup_id) REFERENCES imalat_gruplari(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tedarikciler (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(150) NOT NULL,
            vkn VARCHAR(15) NULL,
            telefon VARCHAR(30) NULL,
            adres TEXT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS kivam_siniflari (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(20) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS parseller (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(100) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bloklar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parsel_id INT NOT NULL,
            ad VARCHAR(100) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            FOREIGN KEY (parsel_id) REFERENCES parseller(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS kotlar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            blok_id INT NOT NULL,
            kot_degeri VARCHAR(20) NOT NULL,
            sira INT NOT NULL DEFAULT 0,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            FOREIGN KEY (blok_id) REFERENCES bloklar(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // ── Kullanıcılar ─────────────────────────────────────────────────────

        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NOT NULL DEFAULT '',
            role ENUM('admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo') NOT NULL DEFAULT 'teknik_ofis',
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // ── Projeler ─────────────────────────────────────────────────────────

        $pdo->exec("CREATE TABLE IF NOT EXISTS projeler (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kod VARCHAR(20) NOT NULL UNIQUE,
            aciklama VARCHAR(200) NOT NULL,
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // ── Ana irsaliye tablosu ──────────────────────────────────────────────

        $pdo->exec("CREATE TABLE IF NOT EXISTS irsaliyeler (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tip ENUM('alis','iade') NOT NULL DEFAULT 'alis',
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
            aciklama TEXT NULL,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (kivam_sinifi_id)   REFERENCES kivam_siniflari(id)  ON DELETE SET NULL,
            FOREIGN KEY (proje_id)          REFERENCES projeler(id)         ON DELETE SET NULL,
            FOREIGN KEY (tedarikci_id)      REFERENCES tedarikciler(id)     ON DELETE RESTRICT,
            FOREIGN KEY (beton_sinifi_id)   REFERENCES beton_siniflari(id)  ON DELETE SET NULL,
            FOREIGN KEY (pompa_id)          REFERENCES pompa_turleri(id)    ON DELETE SET NULL,
            FOREIGN KEY (katki1_id)         REFERENCES katki_listesi(id)    ON DELETE SET NULL,
            FOREIGN KEY (katki2_id)         REFERENCES katki_listesi(id)    ON DELETE SET NULL,
            FOREIGN KEY (firma_id)          REFERENCES firmalar(id)         ON DELETE SET NULL,
            FOREIGN KEY (imalat_grup_id)    REFERENCES imalat_gruplari(id)  ON DELETE SET NULL,
            FOREIGN KEY (ana_is_kalemi_id)  REFERENCES ana_is_kalemleri(id) ON DELETE SET NULL,
            FOREIGN KEY (parsel_id)         REFERENCES parseller(id)        ON DELETE SET NULL,
            FOREIGN KEY (blok_id)           REFERENCES bloklar(id)          ON DELETE SET NULL,
            FOREIGN KEY (kot_id)            REFERENCES kotlar(id)           ON DELETE SET NULL,
            FOREIGN KEY (created_by)        REFERENCES users(id)            ON DELETE SET NULL,
            FOREIGN KEY (updated_by)        REFERENCES users(id)            ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // ── Fotoğraflar ──────────────────────────────────────────────────────

        $pdo->exec("CREATE TABLE IF NOT EXISTS irsaliye_fotolar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            irsaliye_id INT NOT NULL,
            dosya_adi VARCHAR(255) NOT NULL,
            dosya_yolu VARCHAR(500) NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (irsaliye_id) REFERENCES irsaliyeler(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by)  REFERENCES users(id)        ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // ── Audit log ────────────────────────────────────────────────────────

        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tablo VARCHAR(80) NOT NULL,
            kayit_id INT NULL,
            islem ENUM('INSERT','UPDATE','DELETE') NOT NULL,
            eski_deger JSON NULL,
            yeni_deger JSON NULL,
            kullanici_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (kullanici_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

        // ════════════════════════════════════════════════════════════════════
        // REFERANS VERİLERİ
        // ════════════════════════════════════════════════════════════════════

        // Projeler
        $pdo->exec("INSERT IGNORE INTO projeler (kod, aciklama) VALUES ('U030','BATI YAKASI 1. ETAP'),('U031','BATI YAKASI 2. ETAP'),('U039','MİLLET BAHÇESİ')");

        // Beton sınıfları
        $beton_siniflari = ['C16','C20','C25','C30','C35','C40','KURU SHOTCRETE','YAŞ SHOTCRETE','ŞAP 200','ŞAP 300'];
        $stmtBS = $pdo->prepare("INSERT IGNORE INTO beton_siniflari (ad) VALUES (?)");
        foreach ($beton_siniflari as $bs) { $stmtBS->execute([$bs]); }

        // Katkı listesi
        $katki_listesi = ['-','ANTİFRİZ','S4 SLUMP FARKI','KATKISIZ','SU GEÇİRİMSİZLİK','BRÜT BETON','DMAX','ARDGERME','BETON SUYU ISITMA'];
        $stmtKL = $pdo->prepare("INSERT IGNORE INTO katki_listesi (ad) VALUES (?)");
        foreach ($katki_listesi as $kl) { $stmtKL->execute([$kl]); }

        // Pompa türleri
        $pompa_turleri = ['POMPALI','MİKSERLİ','MOBİL POMPA FİYAT FARKI','HİDROLİK DAĞITICI POMPA','ÖRÜMCEK POMPA','SABİT POMPA'];
        $stmtPT = $pdo->prepare("INSERT IGNORE INTO pompa_turleri (ad) VALUES (?)");
        foreach ($pompa_turleri as $pt) { $stmtPT->execute([$pt]); }

        // Firmalar
        $firmalar = ['DENER','OSMAN_CAMCI','YILDIZLAR','SONGUR','KESINTI','KABA_FIRMA_1','KABA_FIRMA_2','KABA_FIRMA_3'];
        $stmtFR = $pdo->prepare("INSERT IGNORE INTO firmalar (ad) VALUES (?)");
        foreach ($firmalar as $fr) { $stmtFR->execute([$fr]); }

        // Kıvam sınıfları
        $kivam_siniflari = ['S1','S2','S3','S4','S5'];
        $stmtKS = $pdo->prepare("INSERT IGNORE INTO kivam_siniflari (ad) VALUES (?)");
        foreach ($kivam_siniflari as $ks) { $stmtKS->execute([$ks]); }

        // Tedarikçiler
        $pdo->exec("INSERT IGNORE INTO tedarikciler (ad, aktif) VALUES ('ANADOLU BETON', 1), ('ANDBET', 1)");

        // İmalat grupları
        $imalat_gruplari = ['Zemin','Kaba','Ince','Peyzaj','Diger'];
        $stmtIG = $pdo->prepare("INSERT IGNORE INTO imalat_gruplari (ad, sira) VALUES (?, ?)");
        foreach ($imalat_gruplari as $sira => $ig) { $stmtIG->execute([$ig, $sira + 1]); }

        // Ana iş kalemleri
        $grupIdMap = [];
        $rows = $pdo->query("SELECT id, ad FROM imalat_gruplari")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) { $grupIdMap[$row['ad']] = $row['id']; }

        $ana_is_kalemleri = [
            'Zemin'  => ['FORE KAZIK','SHOTCRETE','KESİNTİ'],
            'Kaba'   => ['GROBETON','TEMEL','KADEME PERDESİ','KOLON-PERDE','DÖŞEME','MERDİVEN','PARAPET','KULE VİNÇ TEMEL','DOLGU','ASANSÖR KUYUSU DOLGU','KESİNTİ'],
            'Ince'   => ['KÜPTAŞ','HATIL','TOPUK','LENTO','KESİNTİ'],
            'Peyzaj' => ['GROBETON','YASTIK BETONU','SAHA BETONU','PARAPET','MERDİVEN','ÇEVRE DUVARI','İSTİNAT DUVARI','KESİNTİ'],
            'Diger'  => ['MOBİLİZASYON','SAHA BETONU','DOLGU BETONU','KESİNTİ'],
        ];
        $stmtAIK = $pdo->prepare("INSERT IGNORE INTO ana_is_kalemleri (imalat_grup_id, ad, sira) VALUES (?, ?, ?)");
        foreach ($ana_is_kalemleri as $grup => $kalemler) {
            $gid = $grupIdMap[$grup] ?? null;
            if (!$gid) continue;
            foreach ($kalemler as $sira => $kalem) {
                $stmtAIK->execute([$gid, $kalem, $sira + 1]);
            }
        }

        // Parseller
        $parseller = ['A_PARSEL','D_PARSEL_1','D_PARSEL_2','D_PARSEL_3','MILLET_BAHCESI','GENEL'];
        $stmtPAR = $pdo->prepare("INSERT IGNORE INTO parseller (ad) VALUES (?)");
        foreach ($parseller as $p) { $stmtPAR->execute([$p]); }

        // Parsel ID map
        $parselIdMap = [];
        $rows = $pdo->query("SELECT id, ad FROM parseller")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) { $parselIdMap[$row['ad']] = $row['id']; }

        // Bloklar
        $bloklar = [
            'A_PARSEL'       => ['MOBILIZASYON','ISTINAT_CEVRE_DUVARI','A_BLOK','B_BLOK','PEYZAJ_2','GENEL_SAHA'],
            'D_PARSEL_1'     => ['F_2_BLOK','A_2_BLOK','C_1_BLOK','D_3_BLOK','E_BLOK','B_4_BLOK','D_2_BLOK','PEYZAJ_2','GENEL_SAHA','MOBILIZASYON','ISTINAT_CEVRE_DUVARI'],
            'D_PARSEL_2'     => ['D_2_BLOK','D_1_BLOK','G_BLOK','F_1_BLOK','A_1_BLOK','PEYZAJ_2','GENEL_SAHA','MOBILIZASYON','ISTINAT_CEVRE_DUVARI'],
            'D_PARSEL_3'     => ['B_1_BLOK','B_2_BLOK','B_3_BLOK','B_4_BLOK','MOBILIZASYON','ISTINAT_CEVRE_DUVARI','GENEL_SAHA'],
            'MILLET_BAHCESI' => ['GENEL','ISTINAT_CEVRE_DUVARI'],
            'GENEL'          => ['GENEL'],
        ];
        $stmtBL = $pdo->prepare("INSERT IGNORE INTO bloklar (parsel_id, ad) VALUES (?, ?)");
        foreach ($bloklar as $parsel => $bloklist) {
            $pid = $parselIdMap[$parsel] ?? null;
            if (!$pid) continue;
            foreach ($bloklist as $blok) { $stmtBL->execute([$pid, $blok]); }
        }

        // Blok ID map
        $blokIdMap = [];
        $rows = $pdo->query("SELECT id, parsel_id, ad FROM bloklar")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $blokIdMap[$row['parsel_id'] . '_' . $row['ad']] = $row['id'];
        }

        // Kotlar (sadece gerçek kotları olan bloklar)
        $kotlar = [
            // A_PARSEL > A_BLOK
            'A_PARSEL|A_BLOK' => ['+67,60','+71,00','+74,40','+77,80','+81,20','+85,20','+88,60','+93,10','+96,50','+99,90','+103,30','+106,70','+110,10','+113,50','+116,90','+118,50'],
            // A_PARSEL > B_BLOK (tahmini; gerçek veri girilene kadar)
            'A_PARSEL|B_BLOK' => ['+67,60','+71,00','+74,40','+77,80','+81,20','+85,20','+88,60','+93,10'],
            // D_PARSEL_1 > E_BLOK
            'D_PARSEL_1|E_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40','+83,80','+87,20','+90,60','+94,00','+97,40','+100,80','+104,20','+107,60','+111,00','+114,40','+117,80','+121,20','+124,60','+128,00'],
            'D_PARSEL_1|F_2_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40','+83,80','+87,20','+90,60'],
            'D_PARSEL_1|A_2_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40','+83,80'],
            'D_PARSEL_1|C_1_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40','+83,80','+87,20'],
            'D_PARSEL_1|D_3_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40','+83,80'],
            'D_PARSEL_1|B_4_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40','+83,80'],
            'D_PARSEL_1|D_2_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00'],
            'D_PARSEL_2|D_2_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00'],
            'D_PARSEL_2|D_1_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00'],
            'D_PARSEL_2|G_BLOK'   => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40'],
            'D_PARSEL_2|F_1_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40','+83,80'],
            'D_PARSEL_2|A_1_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40','+83,80'],
            'D_PARSEL_3|B_1_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40'],
            'D_PARSEL_3|B_2_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40'],
            'D_PARSEL_3|B_3_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40'],
            'D_PARSEL_3|B_4_BLOK' => ['+58,40','+61,40','+64,80','+68,00','+72,50','+77,00','+80,40'],
        ];

        $stmtKOT = $pdo->prepare("INSERT IGNORE INTO kotlar (blok_id, kot_degeri, sira) VALUES (?, ?, ?)");
        foreach ($kotlar as $key => $kotList) {
            [$parselAd, $blokAd] = explode('|', $key);
            $pid = $parselIdMap[$parselAd] ?? null;
            if (!$pid) continue;
            $bid = $blokIdMap[$pid . '_' . $blokAd] ?? null;
            if (!$bid) continue;
            foreach ($kotList as $sira => $kot) {
                $stmtKOT->execute([$bid, $kot, $sira + 1]);
            }
        }

        // ── Admin kullanıcısı ─────────────────────────────────────────────────
        $hash = password_hash($cfg['adminPass'], PASSWORD_BCRYPT);
        $stmtU = $pdo->prepare(
            "INSERT INTO users (username, password_hash, full_name, role, aktif) VALUES (?, ?, ?, 'admin', 1)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name), role = 'admin', aktif = 1"
        );
        $stmtU->execute([$cfg['adminUser'], $hash, $cfg['adminName'] ?: $cfg['adminUser']]);

        // ── config.php yaz ────────────────────────────────────────────────────
        $configContent = '<?php' . PHP_EOL
            . "define('DB_HOST', " . var_export($cfg['host'],   true) . ");" . PHP_EOL
            . "define('DB_NAME', " . var_export($cfg['dbname'], true) . ");" . PHP_EOL
            . "define('DB_USER', " . var_export($cfg['dbuser'], true) . ");" . PHP_EOL
            . "define('DB_PASS', " . var_export($cfg['dbpass'], true) . ");" . PHP_EOL;
        file_put_contents(__DIR__ . '/config.php', $configContent);

        // Uploads dizini oluştur
        if (!is_dir(__DIR__ . '/uploads')) {
            mkdir(__DIR__ . '/uploads', 0755, true);
        }
        // uploads/.htaccess — PHP engine kapatma
        file_put_contents(__DIR__ . '/uploads/.htaccess', "php_flag engine off\n<FilesMatch \"\\.ph(p[0-9]?|tml)$\">\n    Order allow,deny\n    Deny from all\n</FilesMatch>\n");

        unset($_SESSION['install']);
        header('Location: install.php?step=3');
        exit;

    } catch (PDOException $e) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        $error = 'Kurulum sırasında hata oluştu: ' . htmlspecialchars($e->getMessage());
    }
}

// ─── ADIM 3: Silme butonu ─────────────────────────────────────────────────────
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_install'])) {
    @unlink(__FILE__);
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kurulum — Beton Takip Sistemi</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background: linear-gradient(135deg,#0d6efd 0%,#072d77 100%); min-height:100vh; }
  .wizard-card { max-width:580px; margin:3rem auto; border-radius:16px; box-shadow:0 12px 40px rgba(0,0,0,.3); }
  .step-badge { width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem; }
</style>
</head>
<body>
<div class="container px-3">
<div class="card wizard-card">
  <div class="card-header bg-primary text-white py-3">
    <h5 class="mb-0"><i class="bi bi-gear-wide-connected me-2"></i>Beton Takip Sistemi &mdash; Kurulum Sihirbazı</h5>
  </div>
  <div class="card-body p-4">

    <!-- Adım indikatörü -->
    <div class="d-flex align-items-center mb-4 gap-2">
      <?php
        $steps = ['Veritabanı & Admin','Tablolar & Veriler','Tamamlandı'];
        foreach ($steps as $i => $sname):
            $n = $i + 1;
            $active = ($step === $n);
            $done   = ($step > $n);
            $bg     = $done ? 'bg-success text-white' : ($active ? 'bg-primary text-white' : 'bg-light text-muted border');
      ?>
      <span class="step-badge <?= $bg ?>"><?= $done ? '<i class="bi bi-check"></i>' : $n ?></span>
      <span class="<?= $active ? 'fw-bold' : 'text-muted' ?> flex-grow-1"><?= $sname ?></span>
      <?php if ($n < count($steps)): ?><i class="bi bi-chevron-right text-muted"></i><?php endif; ?>
      <?php endforeach; ?>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger d-flex gap-2 align-items-center">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <span><?= $error ?></span>
      </div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
    <form method="post" novalidate>
      <h6 class="text-muted mb-3 text-uppercase small fw-bold">Veritabanı Bilgileri</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label class="form-label">Sunucu <span class="text-danger">*</span></label>
          <input name="db_host" class="form-control" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Veritabanı Adı <span class="text-danger">*</span></label>
          <input name="db_name" class="form-control" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" placeholder="beton_takip" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">DB Kullanıcı Adı <span class="text-danger">*</span></label>
          <input name="db_user" class="form-control" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">DB Şifresi</label>
          <input name="db_pass" type="password" class="form-control">
        </div>
      </div>
      <h6 class="text-muted mb-3 text-uppercase small fw-bold">Admin Hesabı</h6>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Admin Kullanıcı Adı <span class="text-danger">*</span></label>
          <input name="admin_user" class="form-control" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Ad Soyad</label>
          <input name="admin_name" class="form-control" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" placeholder="Sistem Yöneticisi">
        </div>
        <div class="col-md-6">
          <label class="form-label">Admin Şifresi <span class="text-danger">*</span></label>
          <input name="admin_pass" type="password" class="form-control" required minlength="6">
          <div class="form-text">En az 6 karakter</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Şifre Tekrar <span class="text-danger">*</span></label>
          <input name="admin_pass2" type="password" class="form-control" required>
        </div>
      </div>
      <div class="mt-4">
        <button class="btn btn-primary w-100 py-2">
          <i class="bi bi-arrow-right-circle me-1"></i> Bağlantıyı Test Et &amp; Devam Et
        </button>
      </div>
    </form>

    <?php elseif ($step === 2): ?>
    <div class="alert alert-success d-flex gap-2 align-items-center mb-4">
      <i class="bi bi-check-circle-fill fs-5"></i>
      <span>Veritabanı bağlantısı başarılı! Kurulum hazır.</span>
    </div>
    <p class="text-muted">Aşağıdaki işlemler gerçekleştirilecek:</p>
    <ul class="list-group list-group-flush mb-4">
      <li class="list-group-item"><i class="bi bi-table text-primary me-2"></i>Tüm veritabanı tabloları oluşturulacak</li>
      <li class="list-group-item"><i class="bi bi-collection text-primary me-2"></i>Beton sınıfları, katkılar, pompalar vb. eklenecek</li>
      <li class="list-group-item"><i class="bi bi-map text-primary me-2"></i>Parseller, bloklar ve kotlar eklenecek</li>
      <li class="list-group-item"><i class="bi bi-truck text-primary me-2"></i>Tedarikçiler eklenecek</li>
      <li class="list-group-item"><i class="bi bi-person-check text-primary me-2"></i>Admin kullanıcısı oluşturulacak</li>
      <li class="list-group-item"><i class="bi bi-file-earmark-code text-primary me-2"></i>config.php dosyası yazılacak</li>
    </ul>
    <form method="post">
      <button class="btn btn-success w-100 py-2">
        <i class="bi bi-play-circle me-1"></i> Kurulumu Başlat
      </button>
    </form>

    <?php elseif ($step === 3): ?>
    <div class="text-center py-4">
      <div class="display-1 text-success mb-3"><i class="bi bi-check-circle-fill"></i></div>
      <h4 class="fw-bold">Kurulum Tamamlandı!</h4>
      <p class="text-muted mb-4">Sistem başarıyla kuruldu. Güvenlik için <code>install.php</code> dosyasını silmeniz önerilir.</p>
      <div class="d-flex gap-3 justify-content-center flex-wrap">
        <a href="login.php" class="btn btn-primary px-4">
          <i class="bi bi-box-arrow-in-right me-1"></i> Giriş Yap
        </a>
        <form method="post" onsubmit="return confirm('install.php silinsin mi?');">
          <input type="hidden" name="delete_install" value="1">
          <button class="btn btn-outline-danger px-4">
            <i class="bi bi-trash me-1"></i> install.php Sil
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
