<?php
/**
 * api/demir_pdf_kaydet.php
 * Demir irsaliye PDF'ini AYRI klasöre kaydeder: uploads/demir/belgeler/
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . '/../uploads/demir/belgeler/';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }

if (empty($_FILES['dosya']) || $_FILES['dosya']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'msg'=>'Dosya yüklenemedi veya eksik']); exit;
}
$tmp = $_FILES['dosya']['tmp_name'];
$h = fopen($tmp, 'rb'); $sig = fread($h, 4); fclose($h);
if ($sig !== '%PDF') { echo json_encode(['ok'=>false,'msg'=>'Geçersiz PDF dosyası']); exit; }

$filename = 'demir_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.pdf';
if (!move_uploaded_file($tmp, $dir . $filename)) {
    echo json_encode(['ok'=>false,'msg'=>'Dosya kaydedilemedi']); exit;
}
echo json_encode([
    'ok'  => true,
    'url' => 'uploads/demir/belgeler/' . $filename,
    'filename' => $filename,
    'size_kb' => round($_FILES['dosya']['size']/1024, 1)
]);
