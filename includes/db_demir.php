<?php
/**
 * db_demir.php — Demir modülü için AYRI veritabanı bağlantısı ($pdoDemir)
 *
 * Demir verileri beton verileriyle karışmaz. İki seçenek:
 *   1) Tamamen ayrı MySQL veritabanı (önerilen):
 *      cPanel'de yeni bir DB oluşturup kullanıcıya yetki verin, sonra config.php'ye:
 *          define('DEMIR_DB_NAME', 'takbulut_demir');
 *      (kullanıcı/şifre aynı ise DEMIR_DB_USER / DEMIR_DB_PASS'a gerek yok)
 *   2) DEMIR_DB_NAME tanımlı değilse: ana veritabanı kullanılır; tüm tablolar
 *      'demir_' önekli olduğundan beton tablolarıyla karışmaz.
 */
if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: ../install.php');
    exit;
}
require_once __DIR__ . '/../config.php';

$__demirDb   = defined('DEMIR_DB_NAME') && DEMIR_DB_NAME !== '' ? DEMIR_DB_NAME : DB_NAME;
$__demirUser = defined('DEMIR_DB_USER') && DEMIR_DB_USER !== '' ? DEMIR_DB_USER : DB_USER;
$__demirPass = defined('DEMIR_DB_PASS') ? DEMIR_DB_PASS : DB_PASS;

try {
    $pdoDemir = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $__demirDb . ';charset=utf8mb4',
        $__demirUser,
        $__demirPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(503);
    die(
        '<div style="font-family:sans-serif;color:#842029;background:#f8d7da;padding:20px;border-radius:8px;max-width:600px;margin:40px auto">'
        . '<strong>Demir veritabanı bağlantı hatası.</strong><br>'
        . htmlspecialchars($e->getMessage())
        . '<br><br><small>Ayrı bir DB kullanıyorsanız cPanel\'de oluşturup config.php içinde '
        . '<code>DEMIR_DB_NAME</code> tanımladığınızdan emin olun.</small>'
        . '</div>'
    );
}
