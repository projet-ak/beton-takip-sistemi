<?php
/**
 * auth.php — Kimlik doğrulama ve yetkilendirme
 * session_start() bu dosyada yapılır.
 */
/*
 * ── Oturum süresi (idle timeout) ─────────────────────────────────────────────
 * Varsayılan oturum ömrü buradan ayarlanır. Sunucunun (cPanel) PHP varsayılanı
 * olan session.gc_maxlifetime (genelde 24 dk) yerine bu değer kullanılır; böylece
 * uzun taramalarda (örn. 30 sayfalık PDF) oturum düşmez.
 *
 * Süreyi değiştirmek için: aşağıdaki SESSION_LIFETIME değerini düzenleyin
 * (saniye cinsinden) — ya da config.php içinde define('SESSION_LIFETIME', ...).
 *   1 saat = 3600 | 2 saat = 7200 | 4 saat = 14400 | 8 saat = 28800
 *
 * Bu, "boşta kalma" süresidir: her sayfa açılışı veya AJAX isteği (tarama,
 * kaydet vb.) sayacı sıfırlar. Hareket olmadan SESSION_LIFETIME kadar geçerse
 * oturum sonlandırılır ve kullanıcı login.php'ye yönlendirilir.
 */
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 3600); // saniye — varsayılan 1 saat
}

if (session_status() === PHP_SESSION_NONE) {
    // Oturum ömrünü sunucu varsayılanından bağımsız hale getir.
    // gc_maxlifetime'ı bir miktar tamponla büyük tutuyoruz ki çöp toplayıcı
    // bizim kendi idle kontrolümüzden önce oturumu silmesin.
    ini_set('session.gc_maxlifetime', (string)(SESSION_LIFETIME + 300));

    $__cp = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0, // tarayıcı kapanınca çerez silinsin (oturum çerezi)
        'path'     => $__cp['path'],
        'domain'   => $__cp['domain'],
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// ── Boşta kalma zaman aşımı kontrolü ─────────────────────────────────────────
if (!empty($_SESSION['user'])) {
    $__now  = time();
    $__last = $_SESSION['last_activity'] ?? $__now;

    if ($__now - $__last > SESSION_LIFETIME) {
        // Süre doldu: oturumu tamamen temizle
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', $__now - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        session_start(); // flash mesajı yazabilmek için yeni boş oturum
        if (function_exists('set_flash')) {
            set_flash('warning', 'Oturum süreniz doldu, lütfen tekrar giriş yapın.');
        }
    } else {
        // Hareket var: sayacı yenile
        $_SESSION['last_activity'] = $__now;
        if (empty($_SESSION['login_time'])) {
            $_SESSION['login_time'] = $__now;
        }
    }
}

/**
 * Oturum yoksa login.php'ye, rol uyumsuzsa 403'e yönlendir.
 *
 * @param array $roller İzin verilen roller (boş = herkese açık, giriş şart)
 */
function require_auth(array $roller = []): void
{
    if (empty($_SESSION['user'])) {
        $current  = $_SERVER['REQUEST_URI'] ?? '';
        $base     = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/';
        // Alt klasörlerden (ör. demir/) kök dizindeki login.php'ye ulaş: $rootPath ('../')
        $root     = $base . ($GLOBALS['rootPath'] ?? '');
        header('Location: ' . $root . 'login.php?redirect=' . urlencode($current));
        exit;
    }
    if (!empty($roller) && !in_array($_SESSION['user']['role'], $roller, true)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

/**
 * Oturumdaki kullanıcı dizisini döner; yoksa null.
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Belirtilen rollerden herhangi biriyse true döner.
 */
function has_role(string ...$roller): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    return in_array($user['role'], $roller, true);
}

// ── Yardımcı kısa fonksiyonlar ───────────────────────────────────────────────

/** Sadece admin */
function is_admin(): bool
{
    return has_role('admin');
}

/**
 * Yeni irsaliye oluşturabilenler:
 * admin, teknik_ofis_admin, saha_sefi, depo
 */
function can_create_irsaliye(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'saha_sefi', 'depo');
}

/**
 * İrsaliye düzenleyebilecekler (genel can_edit):
 * admin, teknik_ofis_admin → her zaman
 * teknik_ofis, saha_sefi  → sadece belirli durumlarda (durum bazlı kontrol için can_edit_irsaliye() kullanın)
 */
function can_edit(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'teknik_ofis', 'saha_sefi');
}

/**
 * Belirli bir irsaliyeyi düzenleyip düzenleyemeyeceği:
 * - admin / teknik_ofis_admin: her zaman
 * - teknik_ofis: saha_onaylandi veya beklemede ise
 * - saha_sefi: beklemede ise
 * - depo: hiçbir zaman (sadece oluşturur)
 */
function can_edit_irsaliye(array $irsaliye): bool
{
    if (has_role('admin', 'teknik_ofis_admin')) return true;
    if (has_role('teknik_ofis')) return in_array($irsaliye['durum'] ?? 'beklemede', ['beklemede','saha_onaylandi']);
    if (has_role('saha_sefi'))   return ($irsaliye['durum'] ?? 'beklemede') === 'beklemede';
    return false;
}

/**
 * Saha onayı verebilecekler (1. aşama):
 * admin, teknik_ofis_admin, saha_sefi
 */
function can_approve_saha(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'saha_sefi');
}

/**
 * Teknik ofis onayı verebilecekler (2. aşama / final):
 * admin, teknik_ofis_admin, teknik_ofis
 */
function can_approve_teknik(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'teknik_ofis');
}

/**
 * Raporları görüntüleyebilecek roller:
 * admin, teknik_ofis_admin, teknik_ofis
 */
function can_view_reports(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'teknik_ofis');
}

/**
 * Referans tanım yönetimi (beton sınıfı, blok, parsel vb.):
 * admin, teknik_ofis_admin
 */
function can_manage_definitions(): bool
{
    return has_role('admin', 'teknik_ofis_admin');
}

/**
 * Kullanıcı yönetimi: sadece admin
 */
function can_manage_users(): bool
{
    return has_role('admin');
}

/**
 * Durum rozet HTML'i döner
 */
function durum_badge(string $durum): string
{
    $map = [
        'beklemede'      => ['warning', 'clock',              'Beklemede'],
        'saha_onaylandi' => ['info',    'check-circle',       'Saha Onayı'],
        'onaylandi'      => ['success', 'check-circle-fill',  'Onaylandı'],
        'reddedildi'     => ['danger',  'x-circle-fill',      'Reddedildi'],
    ];
    $d = $map[$durum] ?? ['secondary', 'question-circle', $durum];
    return '<span class="badge bg-' . $d[0] . '"><i class="bi bi-' . $d[1] . ' me-1"></i>' . htmlspecialchars($d[2]) . '</span>';
}
