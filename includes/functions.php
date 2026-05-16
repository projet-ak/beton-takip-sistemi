<?php
/**
 * functions.php — Genel yardımcı fonksiyonlar
 */

/**
 * Tarihi Türkçe formatla: d.m.Y
 */
function format_date(?string $d): string
{
    if (empty($d)) {
        return '-';
    }
    $ts = strtotime($d);
    return $ts !== false ? date('d.m.Y', $ts) : htmlspecialchars($d);
}

/**
 * Sayıyı Türkçe formatla (virgül ondalık, nokta binler)
 */
function format_number($n, int $decimals = 2): string
{
    return number_format((float)$n, $decimals, ',', '.');
}

/**
 * Rol adını Türkçe karşılığıyla döner
 */
function role_label(string $role): string
{
    $map = [
        'admin'              => 'Yönetici',
        'teknik_ofis_admin'  => 'Teknik Ofis Yöneticisi',
        'teknik_ofis'        => 'Teknik Ofis',
        'saha_sefi'          => 'Saha Şefi',
        'depo'               => 'Depo',
    ];
    return $map[$role] ?? htmlspecialchars($role);
}

/**
 * HTTP yönlendirmesi + çıkış
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Flash mesajı oturuma yaz (mesaj yoksa okuma modu)
 */
function flash(string $key, ?string $msg = null): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($msg !== null) {
        $_SESSION['_flash'][$key] = $msg;
    }
}

/**
 * Flash mesajı oku ve sil; yoksa null döner
 */
function get_flash(string $key): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $msg = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $msg;
}

/**
 * Güvenli HTML çıktısı için kısaltma
 */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
