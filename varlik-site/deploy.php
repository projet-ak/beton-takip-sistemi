<?php
/**
 * deploy.php — ERN Varlık Yönetim sitesi tek-tık güncelleme
 * Kullanım: https://ernsahaoperasyon.com.tr/deploy.php?token=TOKEN
 *
 * Repo'nun deploy branch'indeki `varlik-site/` klasörünü bu dizine (site köküne) açar.
 * DEPLOY_TOKEN ve (private repo için) GITHUB_PAT config.php'de tanımlanır (git-ignored).
 * Not: config.php üzerine yazılmaz; kendi dizinindeki diğer dosyalar güncellenir.
 */
define('REPO',   'projet-ak/beton-takip-sistemi');
define('BRANCH', 'claude/organize-control-panel-hUe4z');
define('SUBDIR', 'varlik-site/');

header('Content-Type: application/json; charset=utf-8');

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'config.php bulunamadı — DEPLOY_TOKEN tanımlayın.']);
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
if (!class_exists('ZipArchive')) {
    echo json_encode(['ok' => false, 'msg' => 'ZipArchive PHP eklentisi kapalı']);
    exit;
}

$zipUrl  = 'https://github.com/' . REPO . '/archive/refs/heads/' . rawurlencode(BRANCH) . '.zip';
$ghpat   = defined('GITHUB_PAT') ? GITHUB_PAT : '';
$zipFile = sys_get_temp_dir() . '/ernsite_' . time() . '.zip';

$fh = fopen($zipFile, 'wb');
if (!$fh) { echo json_encode(['ok' => false, 'msg' => 'Geçici dosya oluşturulamadı']); exit; }

$headers = ['User-Agent: ErnSiteDeployer/1.0'];
if ($ghpat !== '') $headers[] = 'Authorization: token ' . $ghpat;

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

if ($curlErr)      { @unlink($zipFile); echo json_encode(['ok' => false, 'msg' => 'cURL hatası: ' . $curlErr]); exit; }
if ($httpCode !== 200) {
    @unlink($zipFile);
    $hint = match (true) {
        $httpCode === 401, $httpCode === 403 => 'GitHub PAT geçersiz/yetersiz — config.php GITHUB_PAT değerini kontrol edin.',
        $httpCode === 404 => 'Branch/repo bulunamadı; private repo ise GITHUB_PAT gerekli.',
        default           => 'GitHub zip indirilemedi.',
    };
    echo json_encode(['ok' => false, 'msg' => 'GitHub HTTP ' . $httpCode . ' — ' . $hint]);
    exit;
}

$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) { @unlink($zipFile); echo json_encode(['ok' => false, 'msg' => 'Zip açılamadı']); exit; }

$dest      = __DIR__;
$count     = 0;
$protected = ['config.php']; // asla üzerine yazma
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name  = $zip->getNameIndex($i);
    $parts = explode('/', $name, 2);          // zip kökündeki repo-branch klasörünü at
    if (count($parts) < 2 || $parts[1] === '') continue;
    $rel = $parts[1];
    if (strpos($rel, SUBDIR) !== 0) continue;  // yalnız varlik-site/ altını al
    $inner = substr($rel, strlen(SUBDIR));
    if ($inner === '' || in_array($inner, $protected, true)) continue;
    $target = $dest . '/' . $inner;
    if (substr($name, -1) === '/') {
        @mkdir($target, 0755, true);
    } else {
        @mkdir(dirname($target), 0755, true);
        if (file_put_contents($target, $zip->getFromIndex($i)) !== false) $count++;
    }
}
$zip->close();
@unlink($zipFile);

if (function_exists('opcache_reset')) @opcache_reset();
clearstatcache(true);

echo json_encode([
    'ok'     => true,
    'msg'    => $count . ' dosya güncellendi',
    'branch' => BRANCH,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
