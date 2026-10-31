<?php
/**
 * deploy2.php — GitHub'dan canlıya dağıtım (exec() gerektirmez)
 * Kullanım: https://ernsaha.com.tr/beton/deploy2.php?token=TOKEN
 *
 * ⚠ 2026-10-31: eski yöntem dalın TAMAMINI tek zip (~3,4 MB) olarak tek istekte indiriyordu.
 * VPS'ten GitHub'a indirme yavaşlayınca/takılınca PHP-FPM işçiyi istek süresi sınırında
 * öldürüyor, Nginx 502 veriyordu — ve zip açılmadan öldüğü için HİÇBİR dosya yazılmıyordu.
 *
 * Yeni yöntem ARTIMLIDIR:
 *  1. GitHub API'den dalın dosya ağacı alınır (her dosyanın git blob SHA'sı + boyutu).
 *  2. Sunucudaki her dosyanın SHA'sı hesaplanır; yalnız FARKLI olanlar indirilir
 *     (tipik deploy'da birkaç dosya — saniyeler sürer).
 *  3. Her istek SÜRE BÜTÇESİYLE çalışır (DEPLOY_BUTCE, varsayılan 25 sn). Bitmezse sayfa
 *     kendini yeniler ve devam eder; durum saklanmaz — güncellenmiş dosyalar SHA'dan
 *     tanınıp atlanır. Böylece hiçbir istek FPM/Nginx sınırına yaklaşmaz.
 *  4. İnen her dosyanın SHA'sı doğrulanır ve geçici adla yazılıp rename edilir (yarım dosya
 *     canlıda hiç görünmez).
 *
 *   ?mod=zip  → eski tam zip yöntemi (yedek yol; bağlantı süre sınırı + IPv4 ile)
 *   ?ip=any   → IPv4 zorlamasını kapat (varsayılan: IPv4 — VPS'te IPv6 rotası takılabiliyor)
 *   ?json=1   → yanıt JSON (betikler için; varsayılan insan okunur sayfa)
 *
 * GitHub repo private ise config.php'de GITHUB_PAT tanımlanır (ya da &ghpat=…).
 */


$__basla = microtime(true);
$jsonCikti = isset($_GET['json']);

/** Yanıtı bas ve çık. $devam=true ise sayfa kendini yeniler. */
function dp_bitir(array $v, bool $devam = false): void
{
    global $jsonCikti;
    if ($jsonCikti) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($v + ['devam' => $devam], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $ok = !empty($v['ok']);
    $renk = $devam ? '#0d6efd' : ($ok ? '#198754' : '#dc3545');
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    if ($devam) echo '<meta http-equiv="refresh" content="1">';
    echo '<title>Deploy</title><style>body{font:15px/1.5 system-ui,sans-serif;max-width:860px;margin:24px auto;padding:0 16px;color:#222}'
       . 'h1{font-size:20px;color:' . $renk . '}pre{background:#f5f5f5;padding:12px;border-radius:6px;overflow:auto;font-size:12.5px}'
       . 'li{margin:2px 0}</style></head><body>';
    echo '<h1>' . htmlspecialchars((string)($v['msg'] ?? '')) . '</h1>';
    if ($devam) echo '<p>Devam ediyor — sayfa kendiliğinden yenilenecek, kapatmayın.</p>';
    foreach (['yazilan' => 'Güncellenen dosyalar', 'silinen' => 'Sunucudan silinenler', 'hatalar' => 'Hatalar'] as $k => $baslik) {
        if (empty($v[$k])) continue;
        echo '<h3>' . $baslik . ' (' . count($v[$k]) . ')</h3><ul>';
        foreach (array_slice($v[$k], 0, 200) as $s) echo '<li><code>' . htmlspecialchars((string)$s) . '</code></li>';
        echo '</ul>';
    }
    $ozet = $v; unset($ozet['yazilan'], $ozet['silinen'], $ozet['hatalar'], $ozet['msg']);
    echo '<pre>' . htmlspecialchars(json_encode($ozet, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre></body></html>';
    exit;
}

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    dp_bitir(['ok' => false, 'msg' => 'config.php bulunamadı.']);
}
require_once __DIR__ . '/config.php';
// DEPLOY_TOKEN config.php'den okunur (git-ignored) — koda sır gömülmez.
// Dal/repo config.php'de DEPLOY_BRANCH / DEPLOY_REPO ile değiştirilebilir (test ortamı için).
define('REPO',   defined('DEPLOY_REPO')   ? DEPLOY_REPO   : 'projet-ak/beton-takip-sistemi');
define('BRANCH', defined('DEPLOY_BRANCH') ? DEPLOY_BRANCH : 'claude/organize-control-panel-hUe4z');

if (!defined('DEPLOY_TOKEN') || DEPLOY_TOKEN === '') {
    http_response_code(500);
    dp_bitir(['ok' => false, 'msg' => 'config.php içinde DEPLOY_TOKEN tanımlı değil.']);
}
if (!hash_equals(DEPLOY_TOKEN, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    dp_bitir(['ok' => false, 'msg' => 'Unauthorized — geçersiz veya eksik token']);
}
if (!function_exists('curl_init')) {
    dp_bitir(['ok' => false, 'msg' => 'cURL PHP eklentisi kapalı']);
}

@set_time_limit(120);
ignore_user_abort(true);   // tarayıcı kapansa da başlanan dosya yarım kalmasın

$BUTCE  = defined('DEPLOY_BUTCE') ? max(5, (int)DEPLOY_BUTCE) : 25;   // yeni indirme başlatma süresi (sn)
$SINIR  = $BUTCE + 20;                                                 // tek isteğin mutlak üst sınırı
$IPV4   = ($_GET['ip'] ?? '') !== 'any';
$ghpat  = trim((string)($_GET['ghpat'] ?? '')) ?: (defined('GITHUB_PAT') ? GITHUB_PAT : '');
$destDir = __DIR__;

/** Asla üzerine yazılmayacak yollar (config.php sırları taşır; backups/ canlı yedeklerdir). */
function dp_korunan(string $rel): bool
{
    return $rel === 'config.php' || str_starts_with($rel, 'backups/');
}

/**
 * Sunucuya HİÇ yazılmayan, varsa SİLİNEN dosyalar: kökteki belgeler, örnek irsaliyeler,
 * Excel şablonları, geliştirici notları. Canlı sunucu Nginx olduğundan .htaccess çalışmaz;
 * bu dosyalar web kökünde durdukça herkese açıktı (CLAUDE.md'de sistemin tüm iç yapısı,
 * PDF'lerde gerçek irsaliyeler). Uygulama hiçbirini okumaz. Yalnız GIT'TE İZLENEN adlar
 * silinir — sunucuya elle konmuş başka bir dosyaya dokunulmaz.
 */
function dp_sunucuda_tutulmaz(string $rel): bool
{
    if (in_array($rel, ['composer.json', 'composer.lock', '.dockerignore', '.gitattributes', '.gitignore'], true)) return true;
    if (str_contains($rel, '/')) return false;   // yalnız kök dizin
    return (bool)preg_match('/\.(md|pdf|xlsx?|csv|sql)$/i', $rel);
}

/** Git blob SHA-1: sha1("blob <boyut>\0<içerik>") — GitHub ağacındaki SHA ile birebir. */
function dp_blob_sha(string $icerik): string
{
    return sha1('blob ' . strlen($icerik) . "\0" . $icerik);
}

/** Ortak cURL seçenekleri: kısa bağlantı süresi + IPv4 + takılma algılama. */
function dp_curl_ayar($ch, array $basliklar, int $sure): void
{
    global $IPV4;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_CONNECTTIMEOUT  => 8,             // bağlantı kurulamıyorsa 8 sn'de vazgeç (eskiden sınırsız → 502); dp_api 3 kez dener
        CURLOPT_TIMEOUT         => max(5, $sure),
        CURLOPT_LOW_SPEED_LIMIT => 256,
        CURLOPT_LOW_SPEED_TIME  => 15,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_HTTPHEADER      => $basliklar,
        CURLOPT_ENCODING        => '',            // gzip kabul
    ]);
    if ($IPV4 && defined('CURL_IPRESOLVE_V4')) curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
}

function dp_basliklar(bool $api): array
{
    global $ghpat;
    $h = ['User-Agent: ERN-Deploy/2.0'];
    if ($api) { $h[] = 'Accept: application/vnd.github+json'; $h[] = 'X-GitHub-Api-Version: 2022-11-28'; }
    if ($ghpat !== '') $h[] = 'Authorization: token ' . $ghpat;
    return $h;
}

/** GitHub API GET → [kod, gövde, hata] */
function dp_api(string $yol, int $sure = 20): array
{
    // ⚠ VPS → GitHub bağlantısı ARA SIRA kuruluyor/kurulamıyor (2026-11-01: bir deploy 3,5 sn'de
    // bitti, hemen sonrakinde "Connection timed out after 10002 ms"). Bağlantı KURULAMAZSA
    // (HTTP 0) iki kez daha denenir: önce yine IPv4, sonra serbest IP (IPv6 rotası açıksa o).
    // Çalışan kip $IPV4'e yazılır — aynı istekteki raw indirmeleri de onu kullanır.
    global $IPV4;
    $denemeler = $IPV4 ? [true, true, false] : [false, false];
    $sonuc = [0, '', ''];
    foreach ($denemeler as $i => $v4) {
        $IPV4 = $v4;
        $ch = curl_init('https://api.github.com/repos/' . REPO . $yol);
        dp_curl_ayar($ch, dp_basliklar(true), $sure);
        $govde = curl_exec($ch);
        $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hata  = curl_error($ch);
        unset($ch); // PHP 8.0+ curl_close() no-op (8.5'te deprecated)
        $sonuc = [$kod, is_string($govde) ? $govde : '', $hata];
        if ($kod !== 0) break;                       // sunucu yanıt verdi (hata kodu olsa bile) — tekrar anlamsız
        if ($i < count($denemeler) - 1) usleep(500000);
    }
    if ($sonuc[0] !== 0) $GLOBALS['dp_deneme'] = $i + 1;
    return $sonuc;
}

function dp_api_hata(string $adim, int $kod, string $govde, string $hata): void
{
    $ipucu = match (true) {
        $hata !== ''  => 'Bağlantı hatası: ' . $hata . ' — sunucu GitHub\'a 3 denemede de (IPv4 ×2, serbest IP ×1) ulaşamadı. Bu VPS\'te ARA SIRA oluyor: 1-2 dakika sonra sayfayı yenileyin. Sürerse aaPanel Terminal\'de "curl -4 -sS -o /dev/null -w %{http_code} https://api.github.com" ile bağlantıyı deneyin.',
        $kod === 401  => 'GitHub PAT geçersiz/süresi dolmuş.',
        $kod === 403  => 'GitHub erişimi reddetti — PAT yetkisi yetersiz ya da saatlik API sınırı doldu (PAT tanımlanırsa sınır 5000/saat).',
        $kod === 404  => 'Dal veya repo bulunamadı; repo private ise config.php\'de GITHUB_PAT tanımlayın.',
        default       => 'Beklenmeyen yanıt.',
    };
    $msj = json_decode($govde, true)['message'] ?? '';
    dp_bitir(['ok' => false, 'msg' => "$adim başarısız (HTTP $kod)", 'ipucu' => $ipucu, 'github' => $msj]);
}

// ═════════════════════════════════════════════════════════════════════════
// YEDEK YOL: tam zip (eski davranış, ama artık bağlantı süre sınırı + IPv4 ile)
// ═════════════════════════════════════════════════════════════════════════
if (($_GET['mod'] ?? '') === 'zip') {
    if (!class_exists('ZipArchive')) dp_bitir(['ok' => false, 'msg' => 'ZipArchive PHP eklentisi kapalı']);
    $zipUrl  = 'https://github.com/' . REPO . '/archive/refs/heads/' . rawurlencode(BRANCH) . '.zip';
    $zipFile = sys_get_temp_dir() . '/beton_deploy_' . time() . '.zip';
    $ch = curl_init($zipUrl);
    dp_curl_ayar($ch, dp_basliklar(false), $SINIR);
    $veri  = curl_exec($ch);
    $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hata  = curl_error($ch);
    $hiz   = (float)curl_getinfo($ch, CURLINFO_SPEED_DOWNLOAD);
    unset($ch);
    if ($hata !== '' || $kod !== 200 || !is_string($veri)) {
        dp_bitir(['ok' => false, 'msg' => 'Zip indirilemedi (HTTP ' . $kod . ')', 'hata' => $hata,
                  'hiz_kb_sn' => round($hiz / 1024, 1), 'ipucu' => 'Artımlı yöntemi (mod parametresi olmadan) deneyin.']);
    }
    file_put_contents($zipFile, $veri);
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) { @unlink($zipFile); dp_bitir(['ok' => false, 'msg' => 'Zip açılamadı']); }
    $yazilan = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts = explode('/', $zip->getNameIndex($i), 2);
        if (count($parts) < 2 || $parts[1] === '') continue;
        $rel = $parts[1];
        if (dp_korunan($rel) || dp_sunucuda_tutulmaz($rel) || str_contains($rel, '..')) continue;
        $dest = $destDir . '/' . $rel;
        if (str_ends_with($rel, '/')) { @mkdir($dest, 0755, true); continue; }
        @mkdir(dirname($dest), 0755, true);
        if (file_put_contents($dest, $zip->getFromIndex($i)) !== false) $yazilan[] = $rel;
    }
    $zip->close();
    @unlink($zipFile);
    $silinen = dp_temizlik($destDir, []);
    $opc = dp_opcache();
    dp_bitir(['ok' => true, 'msg' => count($yazilan) . ' dosya yazıldı (zip yöntemi)' . ($silinen ? ', ' . count($silinen) . ' dosya silindi' : ''),
              'branch' => BRANCH, 'opcache' => $opc, 'silinen' => $silinen, 'sure_sn' => round(microtime(true) - $__basla, 1)]);
}

// ═════════════════════════════════════════════════════════════════════════
// ARTIMLI DEPLOY
// ═════════════════════════════════════════════════════════════════════════

// 1) Dalın son commit'i → ağaç
[$kod, $govde, $hata] = dp_api('/git/ref/heads/' . BRANCH);
if ($kod !== 200) dp_api_hata('Dal okuma', $kod, $govde, $hata);
$commit = json_decode($govde, true)['object']['sha'] ?? '';
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) dp_bitir(['ok' => false, 'msg' => 'Dalın commit SHA\'sı okunamadı']);

[$kod, $govde, $hata] = dp_api('/git/commits/' . $commit);
if ($kod !== 200) dp_api_hata('Commit okuma', $kod, $govde, $hata);
$agacSha = json_decode($govde, true)['tree']['sha'] ?? '';

[$kod, $govde, $hata] = dp_api('/git/trees/' . $agacSha . '?recursive=1', 30);
if ($kod !== 200) dp_api_hata('Dosya ağacı okuma', $kod, $govde, $hata);
$agac = json_decode($govde, true);
if (!empty($agac['truncated'])) {
    dp_bitir(['ok' => false, 'msg' => 'Dosya ağacı GitHub tarafından kısaltıldı (çok büyük) — "&mod=zip" kullanın.']);
}
$agacSure = round(microtime(true) - $__basla, 1);

// 2) Değişenleri bul (boyut farklıysa SHA hesaplamaya gerek yok)
$degisen = []; $tutulmazlar = []; $toplam = 0; $baytToplam = 0;
foreach ($agac['tree'] ?? [] as $e) {
    if (($e['type'] ?? '') !== 'blob' || ($e['mode'] ?? '') === '120000') continue;   // klasör, symlink, submodule
    $rel = (string)$e['path'];
    if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || dp_korunan($rel)) continue;
    if (dp_sunucuda_tutulmaz($rel)) { $tutulmazlar[] = $rel; continue; }
    $toplam++;
    $yol = $destDir . '/' . $rel;
    if (is_file($yol) && filesize($yol) === (int)$e['size']) {
        $icerik = @file_get_contents($yol);
        if ($icerik !== false && dp_blob_sha($icerik) === $e['sha']) continue;
    }
    $degisen[] = ['rel' => $rel, 'sha' => $e['sha'], 'size' => (int)$e['size']];
    $baytToplam += (int)$e['size'];
}

// 3) İndir — paralel (6'şar), SHA doğrulamalı, geçici adla yaz + rename
$yazilan = []; $hatalar = [];
$rawTaban = 'https://raw.githubusercontent.com/' . REPO . '/' . $commit . '/';
$kalan = $degisen;
while ($kalan && (microtime(true) - $__basla) < $BUTCE) {
    $parti = array_splice($kalan, 0, 6);
    $mh = curl_multi_init();
    $kollar = [];
    $sure = (int)max(5, $SINIR - (microtime(true) - $__basla));
    foreach ($parti as $i => $d) {
        $url = $rawTaban . implode('/', array_map('rawurlencode', explode('/', $d['rel'])));
        $ch = curl_init($url);
        dp_curl_ayar($ch, dp_basliklar(false), $sure);
        curl_multi_add_handle($mh, $ch);
        $kollar[$i] = $ch;
    }
    do {
        $st = curl_multi_exec($mh, $aktif);
        if ($aktif) curl_multi_select($mh, 1.0);
    } while ($aktif && $st === CURLM_OK);

    foreach ($parti as $i => $d) {
        $ch = $kollar[$i];
        $icerik = curl_multi_getcontent($ch);
        $kod    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $chHata = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        unset($kollar[$i], $ch);

        // raw başarısızsa / içerik tutmuyorsa: blob API (private repo + PAT'te de çalışır)
        if ($kod !== 200 || !is_string($icerik) || dp_blob_sha($icerik) !== $d['sha']) {
            [$k2, $g2] = dp_api('/git/blobs/' . $d['sha'], (int)max(5, $SINIR - (microtime(true) - $__basla)));
            $b = $k2 === 200 ? json_decode($g2, true) : null;
            $icerik = ($b && ($b['encoding'] ?? '') === 'base64') ? base64_decode((string)$b['content']) : null;
            if (!is_string($icerik) || dp_blob_sha($icerik) !== $d['sha']) {
                $hatalar[] = $d['rel'] . ' — indirilemedi (raw HTTP ' . $kod . ($chHata ? ', ' . $chHata : '') . ', blob HTTP ' . $k2 . ')';
                continue;
            }
        }
        $hedef = $destDir . '/' . $d['rel'];
        if (!is_dir(dirname($hedef))) @mkdir(dirname($hedef), 0755, true);
        $gecici = $hedef . '.deploy-' . bin2hex(random_bytes(4));
        if (@file_put_contents($gecici, $icerik) === false || !@rename($gecici, $hedef)) {
            @unlink($gecici);
            $hatalar[] = $d['rel'] . ' — yazılamadı (klasör izni?)';
            continue;
        }
        $yazilan[] = $d['rel'];
    }
    curl_multi_close($mh);
}

$opc = $yazilan ? dp_opcache() : 'gerek yok';
$sure = round(microtime(true) - $__basla, 1);
$ortak = ['branch' => BRANCH, 'commit' => substr($commit, 0, 7), 'repo_dosya' => $toplam,
          'degisen' => count($degisen), 'agac_sn' => $agacSure, 'sure_sn' => $sure, 'opcache' => $opc,
          'ipv4' => $IPV4, 'baglanti_deneme' => $GLOBALS['dp_deneme'] ?? 1, 'yazilan' => $yazilan, 'hatalar' => $hatalar];

// Bütçe doldu, indirilecek dosya kaldı → sayfa yenilenip kaldığı yerden devam eder
if ($kalan) {
    dp_bitir(['ok' => true, 'msg' => count($yazilan) . ' dosya yazıldı, ' . count($kalan) . ' dosya kaldı'] + $ortak, true);
}
if ($hatalar) {
    dp_bitir(['ok' => false, 'msg' => count($hatalar) . ' dosya güncellenemedi — sayfayı yenileyip tekrar deneyin'] + $ortak);
}

// 4) Tamamlandı → kalıntı ve yayınlanmayan dosyaların temizliği
$silinen = dp_temizlik($destDir, $tutulmazlar);
dp_bitir(['ok' => true,
          'msg' => ($yazilan ? count($yazilan) . ' dosya güncellendi' : 'Sunucu zaten güncel (' . substr($commit, 0, 7) . ')')
                 . ($silinen ? ', ' . count($silinen) . ' dosya silindi' : ''),
          'silinen' => $silinen] + $ortak);

// ── Yardımcılar ──────────────────────────────────────────────────────────

/**
 * Kalıntı temizliği: repodan taşınan/kaldırılan dosyalar sunucuda çalışır hâlde kalmasın
 * (deploy yalnız YAZAR, silmez). Taşıma/kaldırma yapıldığında $obsolete listesine ekle.
 * + Repoda izlenen ama canlıda tutulmayan belgeler (dp_sunucuda_tutulmaz).
 */
function dp_temizlik(string $destDir, array $tutulmazlar): array
{
    $obsolete = [
        'mesajlar.php',                       // → whatsapp/mesajlar.php
        'saha_analiz.php',                    // → whatsapp/saha_analiz.php
        'includes/mesaj.php',                 // → whatsapp/_ortak.php
        'api/mesaj_al.php',                   // → whatsapp/api/mesaj_al.php
        'setup.php',                          // kaldırıldı (güvenlik)
        'metraj_takip.php',                   // kaldırıldı (sayfalar ayrıştı)
        'deploy.php',                         // eski cPanel deploy'u — deploy2.php kullanılıyor
        'import2.php',                        // eski tek seferlik U030/U031 aktarımı — import.php kullanılıyor
        'migrate.php',                        // tek seferlik migrasyon — kurulum.php kapsıyor
        'migrate_scan_url.php',               // tek seferlik — kolonu api/hizli_kaydet.php oluşturuyor
        'Dockerfile',                         // Docker kullanılmıyor (aaPanel VPS)
        'docker-compose.yml',
        'docker/apache.conf',
        'docker/deploy-vps.sh',
        'docker/init.sql',
        'beton takip sistrmi.slnx',           // Visual Studio dosyası
        'doc04399020260208211108.pdf',        // eski kök belgesi (repodan çıkmıştı)
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
    $silinen = [];
    foreach (array_merge($obsolete, $tutulmazlar) as $obs) {
        $p = $destDir . '/' . $obs;
        if (is_file($p) && @unlink($p)) $silinen[] = $obs;
    }
    // Yarıda kalmış geçici dosyalar (.deploy-xxxx) — yalnız 10 dakikadan eskiler
    foreach (['', '*/', '*/*/', '*/*/*/'] as $d) {
        foreach (glob($destDir . '/' . $d . '*.deploy-*') ?: [] as $g) {
            if (is_file($g) && filemtime($g) < time() - 600 && @unlink($g)) $silinen[] = substr($g, strlen($destDir) + 1);
        }
    }
    if (is_dir($destDir . '/docker') && count(scandir($destDir . '/docker')) === 2) @rmdir($destDir . '/docker');
    return $silinen;
}

/** OPcache'i sıfırla — yoksa güncellenen dosyalar eski derlenmiş hâliyle sunulmaya devam eder */
function dp_opcache(): string
{
    clearstatcache(true);
    if (!function_exists('opcache_reset')) return 'yok';
    return @opcache_reset() ? 'sıfırlandı' : 'sıfırlanamadı';
}
