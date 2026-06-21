<?php
/**
 * deploy2.php — exec() gerektirmeyen deploy (ZipArchive + cURL)
 * Kullanım: https://takbulut.com/beton/deploy2.php?token=TOKEN
 *
 * GitHub repo private ise token parametresi ile PAT gönderin:
 *   ?token=TOKEN&ghpat=github_pat_xxxx
 */

define('DEPLOY_TOKEN', 'd4b55f9613fad96acaf7cd2b950bf00b7a483da3c38c8d1d');
define('REPO',   'projet-ak/beton-takip-sistemi');
define('BRANCH', 'claude/organize-control-panel-hUe4z');

header('Content-Type: application/json; charset=utf-8');

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
$ghpat     = trim($_GET['ghpat'] ?? '');          // isteğe bağlı GitHub PAT

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
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => $headers,
]);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);
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

// Asla üzerine yazılmayacak dosyalar
$protected = [
    'config.php',
    'deploy.php',
    'deploy2.php',
    'setup.php',
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

echo json_encode([
    'ok'      => true,
    'msg'     => "$count dosya güncellendi",
    'branch'  => $branch,
    'skipped' => $skipped,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
