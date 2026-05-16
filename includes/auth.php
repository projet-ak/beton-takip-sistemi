<?php
/**
 * auth.php — Kimlik doğrulama ve yetkilendirme
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Oturum yoksa login.php'ye, rol uyumsuzsa 403'e yönlendir.
 * @param array $roller İzin verilen roller (boş = herkese açık ama giriş şart)
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
 * Belirtilen rollerden biri ise true döner.
 */
function has_role(string ...$roller): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    return in_array($user['role'], $roller, true);
}

/* --- Yardımcı kısa fonksiyonlar --- */

function is_admin(): bool
{
    return has_role('admin');
}

/** Veri girişi ve tanım yönetimi yapabilenler */
function can_edit(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'saha_sefi');
}

/** Raporları görebilecek roller */
function can_view_reports(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'teknik_ofis');
}

/** Referans tanım yönetimi (beton sınıfı, blok vb.) */
function can_manage_definitions(): bool
{
    return has_role('admin', 'teknik_ofis_admin');
}

/** Kullanıcı yönetimi */
function can_manage_users(): bool
{
    return has_role('admin');
}
