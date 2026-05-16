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
        $current = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: /login.php?redirect=' . urlencode($current));
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
 * Veri girişi yapabilenler (irsaliye ekle/düzenle):
 * admin, teknik_ofis_admin, saha_sefi
 */
function can_edit(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'saha_sefi');
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
