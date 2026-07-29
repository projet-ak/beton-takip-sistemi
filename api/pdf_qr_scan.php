<?php
/**
 * pdf_qr_scan.php — ZBar + pdftoppm ile PDF'deki tüm QR kodlarını tarar.
 *
 * POST: multipart/form-data, 'pdf' alanı = PDF dosyası
 * Response: JSON
 *   {ok:true,  pages:int, results:[{page:int, qrs:[string, ...]}, ...]}
 *   {ok:false, msg:string, code:'exec_disabled'|'tool_missing'|...}
 */

// ── PHP hatalarının JSON'u bozmasını engelle ───────────────────────────────
error_reporting(0);
ini_set('display_errors', '0');
// Herhangi bir önceki çıktıyı temizle (BOM, notice vb.)
if (ob_get_level()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!file_exists(__DIR__ . '/../config.php')) {
    ob_end_clean();
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Kurulum yapılmamış', 'code' => 'not_installed']);
    exit;
}

require_auth();
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// ── exec() kapalı mı? ────────────────────────────────────────────────────
$disabledFns = array_map('trim', explode(',', (string)ini_get('disable_functions')));
if (in_array('exec', $disabledFns, true) || !function_exists('exec')) {
    echo json_encode([
        'ok'   => false,
        'msg'  => 'Bu sunucuda exec() fonksiyonu kapalı — ZBar kullanılamıyor.',
        'code' => 'exec_disabled',
    ]);
    exit;
}

// ── Araçlar kurulu mu? ───────────────────────────────────────────────────
function tool_exists(string $bin): bool {
    $out = []; $ret = 1;
    @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $ret);
    if ($ret === 0 && !empty($out[0])) return true;
    $out = []; $ret = 1;
    @exec('which ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $ret);
    return $ret === 0 && !empty($out[0]);
}

$missing = [];
foreach (['zbarimg', 'pdftoppm', 'pdfinfo'] as $tool) {
    if (!tool_exists($tool)) $missing[] = $tool;
}
if (!empty($missing)) {
    echo json_encode([
        'ok'      => false,
        'msg'     => 'Gerekli araçlar bulunamadı: ' . implode(', ', $missing),
        'code'    => 'tool_missing',
        'missing' => $missing,
    ]);
    exit;
}

// ── İstek doğrulama ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['pdf'])) {
    echo json_encode(['ok' => false, 'msg' => 'PDF dosyası gerekli', 'code' => 'no_file']);
    exit;
}

$file = $_FILES['pdf'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'msg' => 'Yükleme hatası: ' . $file['error'], 'code' => 'upload_error']);
    exit;
}

// MIME kontrolü
$mime  = guess_mime($file['tmp_name'], $file['name']);
if ($mime !== 'application/pdf') {
    echo json_encode(['ok' => false, 'msg' => 'Yalnızca PDF dosyası kabul edilir (alınan: ' . $mime . ')', 'code' => 'invalid_mime']);
    exit;
}

if ($file['size'] > 50 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'msg' => 'Dosya çok büyük (maks. 50 MB)', 'code' => 'too_large']);
    exit;
}

// ── Geçici PDF ───────────────────────────────────────────────────────────
$tmpPdf = tempnam(sys_get_temp_dir(), 'bts_') . '.pdf';
if (!move_uploaded_file($file['tmp_name'], $tmpPdf)) {
    echo json_encode(['ok' => false, 'msg' => 'Geçici dosya yazılamadı', 'code' => 'tmp_write']);
    exit;
}

$tmpDir = sys_get_temp_dir() . '/bts_qr_' . bin2hex(random_bytes(8));
mkdir($tmpDir, 0700);

try {
    // ── Sayfa sayısı ─────────────────────────────────────────────────
    $infoOut = []; $infoRet = 0;
    exec('pdfinfo ' . escapeshellarg($tmpPdf) . ' 2>/dev/null', $infoOut, $infoRet);
    $pages = 0;
    foreach ($infoOut as $line) {
        if (preg_match('/^Pages:\s+(\d+)/i', $line, $m)) { $pages = (int)$m[1]; break; }
    }
    if ($pages < 1) {
        echo json_encode(['ok' => false, 'msg' => 'PDF sayfa sayısı okunamadı (pdfinfo çıktısı boş)', 'code' => 'pdfinfo_failed']);
        exit;
    }

    // ── PDF → PNG (200 DPI) ───────────────────────────────────────────
    $prefix = $tmpDir . '/pg';
    exec('pdftoppm -r 200 -png ' . escapeshellarg($tmpPdf) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null');

    $pngs = glob($tmpDir . '/pg-*.png') ?: [];
    natsort($pngs);
    $pngs = array_values($pngs);

    // ── ZBar ile QR oku ───────────────────────────────────────────────
    $results = [];
    for ($p = 1; $p <= $pages; $p++) {
        $qrs     = [];
        $pngFile = $pngs[$p - 1] ?? null;

        if ($pngFile && file_exists($pngFile)) {
            $zbarOut = []; $zbarRet = 0;
            exec('zbarimg --raw -q ' . escapeshellarg($pngFile) . ' 2>/dev/null', $zbarOut, $zbarRet);
            foreach ($zbarOut as $line) {
                $line = trim($line);
                if ($line !== '') $qrs[] = $line;
            }
        }

        $results[] = ['page' => $p, 'qrs' => $qrs];
    }

    echo json_encode(['ok' => true, 'pages' => $pages, 'results' => $results]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'Sunucu hatası: ' . $e->getMessage(), 'code' => 'exception']);
} finally {
    @unlink($tmpPdf);
    $tmpFiles = glob($tmpDir . '/*') ?: [];
    foreach ($tmpFiles as $f) @unlink($f);
    @rmdir($tmpDir);
}
