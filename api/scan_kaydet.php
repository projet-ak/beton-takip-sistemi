<?php
/**
 * api/scan_kaydet.php
 * Taranan sayfa görüntüsünü uploads/images/ klasörüne kaydeder.
 * Veritabanına hiçbir şey yazmaz — disk üzerinde kalıcı olarak tutar.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

$scanDir = __DIR__ . '/../uploads/images/';

// Klasör yoksa oluştur
if (!is_dir($scanDir)) {
    mkdir($scanDir, 0755, true);
}

$imageData = $_POST['image'] ?? '';
if (!$imageData) {
    echo json_encode(['ok' => false, 'msg' => 'Görüntü verisi eksik']);
    exit;
}

// data:image/jpeg;base64,... → saf base64
$base64 = preg_replace('#^data:image/\w+;base64,#i', '', $imageData);
$bytes  = base64_decode($base64, true);

if (!$bytes || strlen($bytes) < 100) {
    echo json_encode(['ok' => false, 'msg' => 'Geçersiz görüntü']);
    exit;
}

$filename = 'scan_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.jpg';
$fullPath = $scanDir . $filename;

if (file_put_contents($fullPath, $bytes) === false) {
    echo json_encode(['ok' => false, 'msg' => 'Dosya kaydedilemedi']);
    exit;
}

echo json_encode([
    'ok'       => true,
    'url'      => 'uploads/images/' . $filename,
    'filename' => $filename,
    'size_kb'  => round(strlen($bytes) / 1024, 1)
]);
