<?php
session_start();

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host     = trim($_POST['host'] ?? '');
    $dbname   = trim($_POST['dbname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$host || !$dbname || !$username) {
        $error = 'Lütfen tüm zorunlu alanları doldurun.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $_SESSION['install'] = compact('host', 'dbname', 'username', 'password');
            header('Location: install.php?step=2');
            exit;
        } catch (PDOException $e) {
            $error = 'Veritabanı bağlantısı kurulamadı: ' . htmlspecialchars($e->getMessage());
        }
    }
}

if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['install'])) {
        header('Location: install.php?step=1');
        exit;
    }
    $cfg = $_SESSION['install'];
    try {
        $pdo = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4",
            $cfg['username'],
            $cfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tedarikciler (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ad VARCHAR(150) NOT NULL,
                telefon VARCHAR(30),
                adres TEXT,
                aktif TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS irsaliyeler (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tedarikci_id INT NOT NULL,
                irsaliye_no VARCHAR(100),
                tarih DATE NOT NULL,
                miktar_m3 DECIMAL(10,2) NOT NULL DEFAULT 0,
                birim_fiyat DECIMAL(10,2) NOT NULL DEFAULT 0,
                toplam_tutar DECIMAL(12,2) GENERATED ALWAYS AS (miktar_m3 * birim_fiyat) STORED,
                aciklama TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (tedarikci_id) REFERENCES tedarikciler(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Örnek tedarikçiler
        $pdo->exec("
            INSERT IGNORE INTO tedarikciler (id, ad, telefon) VALUES
            (1, 'Anadolu Beton', ''),
            (2, 'ANDBET', '');
        ");

        // config.php yaz
        $configContent = "<?php\ndefine('DB_HOST', " . var_export($cfg['host'], true) . ");\ndefine('DB_NAME', " . var_export($cfg['dbname'], true) . ");\ndefine('DB_USER', " . var_export($cfg['username'], true) . ");\ndefine('DB_PASS', " . var_export($cfg['password'], true) . ");\n";
        file_put_contents(__DIR__ . '/config.php', $configContent);

        unset($_SESSION['install']);
        header('Location: install.php?step=3');
        exit;
    } catch (PDOException $e) {
        $error = 'Tablolar oluşturulamadı: ' . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kurulum - Beton Takip Sistemi</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width:560px">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Beton Takip Sistemi &mdash; Kurulum</h5>
        </div>
        <div class="card-body">
            <ul class="nav nav-pills mb-4">
                <li class="nav-item"><span class="nav-link <?= $step===1?'active':'' ?>">1. Veritabanı</span></li>
                <li class="nav-item"><span class="nav-link <?= $step===2?'active':'' ?>">2. Tablolar</span></li>
                <li class="nav-item"><span class="nav-link <?= $step===3?'active':'' ?>">3. Tamamlandı</span></li>
            </ul>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Sunucu <span class="text-danger">*</span></label>
                        <input name="host" class="form-control" value="localhost" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Veritabanı adı <span class="text-danger">*</span></label>
                        <input name="dbname" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kullanıcı adı <span class="text-danger">*</span></label>
                        <input name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Şifre</label>
                        <input name="password" type="password" class="form-control">
                    </div>
                    <button class="btn btn-primary w-100">Bağlantıyı Test Et &amp; Devam Et</button>
                </form>

            <?php elseif ($step === 2): ?>
                <p>Veritabanı bağlantısı başarılı. Tablolar oluşturulacak ve örnek tedarikçiler eklenecek.</p>
                <form method="post">
                    <button class="btn btn-success w-100">Tabloları Oluştur &amp; Kurulumu Tamamla</button>
                </form>

            <?php elseif ($step === 3): ?>
                <div class="text-center py-3">
                    <div class="display-1 text-success">&#10003;</div>
                    <h4 class="mt-3">Kurulum Tamamlandı!</h4>
                    <p class="text-muted">Güvenlik için <code>install.php</code> dosyasını silin veya yeniden adlandırın.</p>
                    <a href="index.php" class="btn btn-primary mt-2">Sisteme Gir</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
