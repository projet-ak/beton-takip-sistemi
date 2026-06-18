<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo->exec("ALTER TABLE irsaliyeler ADD COLUMN IF NOT EXISTS scan_image_url VARCHAR(500) DEFAULT NULL");
    echo "OK: scan_image_url kolonu eklendi (veya zaten mevcuttu).\n";
} catch (Exception $e) {
    echo "HATA: " . $e->getMessage() . "\n";
}
