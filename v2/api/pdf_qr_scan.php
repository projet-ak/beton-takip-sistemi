<?php
/**
 * pdf_qr_scan.php — ZBar + pdftoppm ile PDF'deki tüm QR kodlarını tarar.
 *
 * POST: multipart/form-data, 'pdf' alanı = PDF dosyası
 * Response: JSON
 *   {ok:true,  pages:int, results:[{page:int, qrs:[string, ...]}, ...]}
 *   {ok:false, msg:string}
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!file_exists(__DIR__ . '/../config.php')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'msg' => 'Kurulum yapılmamış']);
    exit;
}

require_auth();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['pdf'])) {
    echo json_encode(['ok' => false, 'msg' => 'PDF dosyası gerekli']);
    exit;
}

$file = $_FILES['pdf'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'msg' => 'Yükleme hatası: ' . $file['error']]);
    exit;
}

// MIME kontrolü
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if ($mime !== 'application/pdf') {
    echo json_encode(['ok' => false, 'msg' => 'Yalnızca PDF dosyası kabul edilir']);
    exit;
}

// 50 MB üst sınır
if ($file['size'] > 50 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'msg' => 'Dosya çok büyük (maks. 50 MB)']);
    exit;
}

// Geçici PDF'i kaydet (.pdf uzantısıyla — bazı araçlar uzantıya bakar)
$tmpPdf = tempnam(sys_get_temp_dir(), 'bts_') . '.pdf';
if (!move_uploaded_file($file['tmp_name'], $tmpPdf)) {
    echo json_encode(['ok' => false, 'msg' => 'Geçici dosya yazılamadı']);
    exit;
}

$tmpDir = sys_get_temp_dir() . '/bts_qr_' . bin2hex(random_bytes(8));
mkdir($tmpDir, 0700);

try {
    // ── Sayfa sayısını bul ────────────────────────────────────────────
    $infoLines = [];
    exec('pdfinfo ' . escapeshellarg($tmpPdf) . ' 2>/dev/null', $infoLines);
    $pages = 0;
    foreach ($infoLines as $line) {
        if (preg_match('/^Pages:\s+(\d+)/i', $line, $m)) {
            $pages = (int)$m[1];
            break;
        }
    }
    if ($pages < 1) {
        echo json_encode(['ok' => false, 'msg' => 'PDF sayfa sayısı okunamadı']);
        exit;
    }

    // ── Tüm sayfaları PNG olarak render et (200 DPI) ─────────────────
    $prefix = $tmpDir . '/pg';
    exec('pdftoppm -r 200 -png ' . escapeshellarg($tmpPdf) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null');

    // Dosyaları doğal sırayla al (pdftoppm sıfır-pad yapabilir; glob + natsort güvenli)
    $pngs = glob($tmpDir . '/pg-*.png');
    natsort($pngs);
    $pngs = array_values($pngs);

    // ── Her sayfa için ZBar ile QR oku ────────────────────────────────
    $results = [];
    for ($p = 1; $p <= $pages; $p++) {
        $qrs     = [];
        $pngFile = $pngs[$p - 1] ?? null;

        if ($pngFile && file_exists($pngFile)) {
            $zbarOut = [];
            exec('zbarimg --raw -q ' . escapeshellarg($pngFile) . ' 2>/dev/null', $zbarOut);
            foreach ($zbarOut as $line) {
                $line = trim($line);
                if ($line !== '') $qrs[] = $line;
            }
        }

        $results[] = ['page' => $p, 'qrs' => $qrs];
    }

    echo json_encode(['ok' => true, 'pages' => $pages, 'results' => $results]);

} finally {
    @unlink($tmpPdf);
    $tmpFiles = glob($tmpDir . '/*');
    if ($tmpFiles) {
        foreach ($tmpFiles as $f) @unlink($f);
    }
    @rmdir($tmpDir);
}
