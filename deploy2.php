<?php
/**
 * deploy2.php — exec() gerektirmeyen deploy (ZipArchive + cURL)
 * Kullanım: https://ernsaha.com.tr/beton/deploy2.php?token=TOKEN
 *
 * GitHub repo private ise token parametresi ile PAT gönderin:
 *   ?token=TOKEN&ghpat=github_pat_xxxx
 */

// DEPLOY_TOKEN artık config.php'den okunur (git-ignored) — koda sır gömülmez.
define('REPO',   'projet-ak/beton-takip-sistemi');
define('BRANCH', 'claude/organize-control-panel-hUe4z');

header('Content-Type: application/json; charset=utf-8');

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'config.php bulunamadı.']);
    exit;
}
require_once __DIR__ . '/config.php';

if (!defined('DEPLOY_TOKEN') || DEPLOY_TOKEN === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'config.php içinde DEPLOY_TOKEN tanımlı değil.']);
    exit;
}

if (!hash_equals(DEPLOY_TOKEN, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized — geçersiz veya eksik token']);
    exit;
}

// ZipArchive zorunlu
if (!class_exists('ZipArchive')) {
    echo json_encode(['ok' => false, 'msg' => 'ZipArchive PHP eklentisi kapalı']);
    exit;
}

// ── GitHub zip URL ────────────────────────────────────────────────────────
$branch    = BRANCH;
$encodedBr = str_replace('/', '%2F', $branch);   // forward slash encode
$zipUrl    = 'https://github.com/' . REPO . '/archive/refs/heads/' . rawurlencode($branch) . '.zip';
// GitHub PAT: önce URL parametresi, yoksa config.php'deki GITHUB_PAT (URL'de sır taşımamak için)
$ghpat     = trim($_GET['ghpat'] ?? '') ?: (defined('GITHUB_PAT') ? GITHUB_PAT : '');

// ── cURL ile indir ────────────────────────────────────────────────────────
$zipFile = sys_get_temp_dir() . '/beton_deploy_' . time() . '.zip';
$fh      = fopen($zipFile, 'wb');

if (!$fh) {
    echo json_encode(['ok' => false, 'msg' => 'Geçici dosya oluşturulamadı: ' . sys_get_temp_dir()]);
    exit;
}

$headers = ['User-Agent: BetaDeployer/1.0'];
if ($ghpat !== '') {
    $headers[] = 'Authorization: token ' . $ghpat;
}

$ch = curl_init($zipUrl);
curl_setopt_array($ch, [
    CURLOPT_FILE           => $fh,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 600,           // büyük zip'te 120 sn yetmiyordu (indirme yarıda kesilip 502 oluyordu)
    CURLOPT_LOW_SPEED_LIMIT=> 1024,          // 60 sn boyunca <1 KB/s akarsa gerçek takılma say, bekletme
    CURLOPT_LOW_SPEED_TIME => 60,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => $headers,
]);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
unset($ch); // PHP 8.0+ curl_close() no-op (8.5'te deprecated)
fclose($fh);

if ($curlErr) {
    @unlink($zipFile);
    echo json_encode(['ok' => false, 'msg' => 'cURL hatası: ' . $curlErr]);
    exit;
}

if ($httpCode !== 200) {
    $size = filesize($zipFile);
    @unlink($zipFile);
    $hint = match (true) {
        $httpCode === 401 => 'GitHub PAT geçersiz/süresi dolmuş — &ghpat=TOKEN değerini güncelleyin.',
        $httpCode === 404 => 'Branch veya repo bulunamadı; repo private ise &ghpat=TOKEN ekleyin (token "repo" yetkisine sahip olmalı).',
        $httpCode === 403 => 'GitHub erişimi reddetti — PAT yetkisi yetersiz veya rate limit.',
        default           => 'GitHub zip indirilemedi.',
    };
    echo json_encode([
        'ok'     => false,
        'msg'    => 'GitHub HTTP ' . $httpCode . ' — ' . $hint,
        'branch' => $branch,
        'url'    => $zipUrl,
        'size'   => $size,
    ]);
    exit;
}

// ── Zip aç ────────────────────────────────────────────────────────────────
$zip = new ZipArchive();
$res = $zip->open($zipFile);
if ($res !== true) {
    @unlink($zipFile);
    echo json_encode(['ok' => false, 'msg' => 'Zip açılamadı (kod: ' . $res . ')']);
    exit;
}

$destDir = __DIR__;
$count   = 0;
$skipped = [];

// Asla üzerine yazılmayacak dosyalar (config.php sırları taşır; token artık
// koda gömülü olmadığından deploy dosyaları normal güncellenebilir)
$protected = [
    'config.php',
];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name  = $zip->getNameIndex($i);
    $parts = explode('/', $name, 2);

    // Zip içindeki ilk klasör = repo adı + branch, atla
    if (count($parts) < 2 || $parts[1] === '') continue;

    $rel  = $parts[1];
    $dest = $destDir . '/' . $rel;

    // Koruma: config, deploy, backups
    if (in_array($rel, $protected, true) || str_starts_with($rel, 'backups/')) {
        $skipped[] = $rel;
        continue;
    }

    if (str_ends_with($name, '/')) {
        @mkdir($dest, 0755, true);
    } else {
        @mkdir(dirname($dest), 0755, true);
        $bytes = file_put_contents($dest, $zip->getFromIndex($i));
        if ($bytes !== false) $count++;
    }
}

$zip->close();
@unlink($zipFile);

// ── Kalıntı temizliği ─────────────────────────────────────────────────────
// Repodan taşınan/kaldırılan dosyalar: zip yalnız EKLER, silmez — eskiler
// sunucuda çalışır halde kalır (ör. kökteki eski mesajlar.php irsaliye
// oluşturabilirdi). Taşıma/kaldırma yapıldığında bu listeye ekle.
$obsolete = [
    'mesajlar.php',                       // → whatsapp/mesajlar.php
    'saha_analiz.php',                    // → whatsapp/saha_analiz.php
    'includes/mesaj.php',                 // → whatsapp/_ortak.php
    'api/mesaj_al.php',                   // → whatsapp/api/mesaj_al.php
    'setup.php',                          // kaldırıldı (güvenlik)
    'metraj_takip.php',                   // kaldırıldı (sayfalar ayrıştı)
    'uploads/ERN Holding_Logo_Beyaz.jpg.jpeg',   // → uploads/logo/
    'uploads/ERN Holding_Logo_Beyaz.png',
    'uploads/ERN Holding_Logo_Renkli (1).png',
    'uploads/ERN Holding_Logo_Renkli.jpg.jpeg',
    'uploads/ERN Holding_Logo_Renkli.png',
    'uploads/ERN Taahhut_Logo_Beyaz.jpg.jpeg',
    'uploads/ERN Taahhut_Logo_Beyaz.png',
    'uploads/ERN Taahhut_Logo_Renkli.jpg.jpeg',
    'uploads/ERN Taahhut_Logo_Renkli.png',
];
$deleted = [];
foreach ($obsolete as $obs) {
    $p = $destDir . '/' . $obs;
    if (is_file($p) && @unlink($p)) $deleted[] = $obs;
}

// OPcache'i sıfırla — yoksa güncellenen dosyalar eski derlenmiş haliyle sunulmaya devam eder
$opcache = 'yok';
if (function_exists('opcache_reset')) {
    $opcache = @opcache_reset() ? 'sıfırlandı' : 'sıfırlanamadı';
}
clearstatcache(true);

echo json_encode([
    'ok'      => true,
    'msg'     => "$count dosya güncellendi" . ($deleted ? ', ' . count($deleted) . ' kalıntı silindi' : ''),
    'branch'  => $branch,
    'opcache' => $opcache,
    'skipped' => $skipped,
    'silinen' => $deleted,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
