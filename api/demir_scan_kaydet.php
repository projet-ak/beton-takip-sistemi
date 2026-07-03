<?php
/**
 * api/demir_scan_kaydet.php
 * Demir tarama görüntüsünü AYRI klasöre kaydeder: uploads/demir/gorseller/
 * (Beton görselleriyle karışmaz; veritabanına yalnızca URL yazılır.)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . '/../uploads/demir/gorseller/';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }

$imageData = $_POST['image'] ?? '';
if (!$imageData) { echo json_encode(['ok'=>false,'msg'=>'Görüntü verisi eksik']); exit; }

$base64 = preg_replace('#^data:image/\w+;base64,#i', '', $imageData);
$bytes  = base64_decode($base64, true);
if (!$bytes || strlen($bytes) < 100) { echo json_encode(['ok'=>false,'msg'=>'Geçersiz görüntü']); exit; }

$filename = 'demir_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.jpg';
if (file_put_contents($dir . $filename, $bytes) === false) {
    echo json_encode(['ok'=>false,'msg'=>'Dosya kaydedilemedi']); exit;
}
echo json_encode([
    'ok'  => true,
    'url' => 'uploads/demir/gorseller/' . $filename,
    'filename' => $filename,
    'size_kb' => round(strlen($bytes)/1024, 1)
]);
