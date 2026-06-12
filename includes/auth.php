<?php
/**
 * auth.php — Kimlik doğrulama ve yetkilendirme
 * session_start() bu dosyada yapılır.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
        header('Location: ' . $base . 'login.php?redirect=' . urlencode($current));
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
