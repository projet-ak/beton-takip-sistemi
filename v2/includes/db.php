<?php
/**
 * db.php — PDO veritabanı bağlantısı
 *
 * config.php yoksa install.php'ye yönlendirir.
 * Başarılı bağlantıda $pdo değişkenini sağlar.
 */
if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(503);
    die(
        '<div style="font-family:sans-serif;color:#842029;background:#f8d7da;padding:20px;border-radius:8px;max-width:600px;margin:40px auto">'
        . '<strong>Veritabanı bağlantı hatası.</strong><br>'
        . htmlspecialchars($e->getMessage())
        . '</div>'
    );
}
