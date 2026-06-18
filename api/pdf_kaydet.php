<?php
/**
 * api/pdf_kaydet.php
 * Yüklenen PDF dosyasını uploads/pdf/ klasörüne kaydeder.
 * Veritabanına hiçbir şey yazmaz — disk üzerinde kalıcı olarak tutar.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

$pdfDir = __DIR__ . '/../uploads/pdf/';

// Klasör yoksa oluştur
if (!is_dir($pdfDir)) {
    mkdir($pdfDir, 0755, true);
}

if (empty($_FILES['dosya']) || $_FILES['dosya']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'msg' => 'Dosya yüklenemedi veya eksik']);
    exit;
}

$tmpPath = $_FILES['dosya']['tmp_name'];
$origSize = $_FILES['dosya']['size'];

// PDF doğrulama — dosya %PDF ile başlamalı
$handle = fopen($tmpPath, 'rb');
$header = fread($handle, 4);
fclose($handle);

if ($header !== '%PDF') {
    echo json_encode(['ok' => false, 'msg' => 'Geçersiz PDF dosyası']);
    exit;
}

$filename = 'pdf_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.pdf';
$fullPath = $pdfDir . $filename;

if (!move_uploaded_file($tmpPath, $fullPath)) {
    echo json_encode(['ok' => false, 'msg' => 'Dosya kaydedilemedi']);
    exit;
}

echo json_encode([
    'ok'       => true,
    'url'      => 'uploads/pdf/' . $filename,
    'filename' => $filename,
    'size_kb'  => round($origSize / 1024, 1)
]);
