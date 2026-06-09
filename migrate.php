<?php
/**
 * migrate.php — Veritabanı güncelleme (yeni kurulum değil, güncelleme için)
 * Kullanım: Tarayıcıdan bir kez çalıştırın, sonra silin.
 */
session_start();
if (!file_exists(__DIR__ . '/config.php')) {
    die('config.php bulunamadı. Önce install.php çalıştırın.');
}
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('DB bağlantısı başarısız: ' . htmlspecialchars($e->getMessage()));
}

$migrasyonlar = [
    'proje_no_alani' => [
        'kontrol' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='irsaliyeler' AND COLUMN_NAME='proje_no'",
        'sql'     => "ALTER TABLE irsaliyeler ADD COLUMN proje_no VARCHAR(50) NULL AFTER irsaliye_no",
        'aciklama'=> 'irsaliyeler tablosuna proje_no sütunu eklendi',
    ],
    'projeler_tablosu' => [
        'kontrol' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='projeler'",
        'sql'     => "CREATE TABLE IF NOT EXISTS projeler (id INT AUTO_INCREMENT PRIMARY KEY, kod VARCHAR(20) NOT NULL UNIQUE, aciklama VARCHAR(200) NOT NULL, aktif TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'aciklama'=> 'projeler tablosu oluşturuldu',
    ],
    'irsaliyeler_proje_id' => [
        'kontrol' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='irsaliyeler' AND COLUMN_NAME='proje_id'",
        'sql'     => "ALTER TABLE irsaliyeler ADD COLUMN proje_id INT NULL AFTER proje_no",
        'aciklama'=> 'irsaliyeler tablosuna proje_id sütunu eklendi',
    ],
    'tedarikciler_vkn' => [
        'kontrol' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tedarikciler' AND COLUMN_NAME='vkn'",
        'sql'     => "ALTER TABLE tedarikciler ADD COLUMN vkn VARCHAR(15) NULL AFTER ad",
        'aciklama'=> 'tedarikciler tablosuna vkn (vergi no) sütunu eklendi — QR kod eşleştirmesi için',
    ],

    // ── Onay Akışı (v3) ─────────────────────────────────────────────
    'irsaliye_durum' => [
        'kontrol' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='irsaliyeler' AND COLUMN_NAME='durum'",
        'sql'     => "ALTER TABLE irsaliyeler
                        ADD COLUMN durum ENUM('beklemede','saha_onaylandi','onaylandi','reddedildi') NOT NULL DEFAULT 'beklemede' AFTER tip,
                        ADD COLUMN saha_onaylayan_id INT NULL,
                        ADD COLUMN saha_onay_tarih  DATETIME NULL,
                        ADD COLUMN teknik_onaylayan_id INT NULL,
                        ADD COLUMN teknik_onay_tarih   DATETIME NULL,
                        ADD COLUMN red_neden VARCHAR(500) NULL,
                        ADD INDEX idx_durum (durum)",
        'aciklama'=> 'irsaliyeler tablosuna onay akışı kolonları eklendi (durum, saha/teknik onay)',
    ],

    'users_aktif' => [
        'kontrol' => "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='aktif'",
        'sql'     => "ALTER TABLE users ADD COLUMN aktif TINYINT(1) NOT NULL DEFAULT 1",
        'aciklama'=> 'users tablosuna aktif sütunu eklendi',
    ],
];

$sonuclar = [];
foreach ($migrasyonlar as $ad => $m) {
    $mevcutMu = (int)$pdo->query($m['kontrol'])->fetchColumn();
    if ($mevcutMu) {
        $sonuclar[] = ['tip'=>'info', 'msg'=> "{$ad}: Zaten mevcut, atlandı."];
    } else {
        $pdo->exec($m['sql']);
        $sonuclar[] = ['tip'=>'success', 'msg'=> "{$ad}: ✓ {$m['aciklama']}"];
    }
}

// Projeleri ekle
$pdo->exec("INSERT IGNORE INTO projeler (kod, aciklama) VALUES ('U030','BATI YAKASI 1. ETAP'),('U031','BATI YAKASI 2. ETAP'),('U039','MİLLET BAHÇESİ')");
$sonuclar[] = ['tip'=>'info', 'msg'=> 'Proje seed verileri kontrol edildi / eklendi.'];

// Mevcut irsaliyeleri "onaylandi" olarak işaretle (geçiş kolaylığı)
try {
    $guncellenen = $pdo->exec("UPDATE irsaliyeler SET durum='onaylandi' WHERE durum='beklemede' AND created_by IS NOT NULL");
    if ($guncellenen > 0) {
        $sonuclar[] = ['tip'=>'success', 'msg'=> "Mevcut $guncellenen irsaliye 'onaylandi' durumuna geçirildi (geçiş)."];
    }
} catch (Exception $e) { /* durum kolonu henüz yoksa sessiz geç */ }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Migrasyon — Beton Takip</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width:600px">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white fw-bold">Veritabanı Güncelleme</div>
        <div class="card-body">
            <?php foreach ($sonuclar as $s): ?>
            <div class="alert alert-<?= $s['tip'] ?> py-2 mb-2"><?= htmlspecialchars($s['msg']) ?></div>
            <?php endforeach; ?>
            <hr>
            <p class="text-danger small">
                <strong>Güvenlik:</strong> Bu işlem tamamlandıktan sonra <code>migrate.php</code> dosyasını silin.
            </p>
            <a href="index.php" class="btn btn-success">Sisteme Git</a>
        </div>
    </div>
</div>
</body>
</html>
