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
