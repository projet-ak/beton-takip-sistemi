<?php
/**
 * logout.php — Oturumu güvenli şekilde sonlandır
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Tüm oturum verilerini temizle
$_SESSION = [];

// Oturum çerezini tarayıcıdan sil
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: login.php');
exit;
