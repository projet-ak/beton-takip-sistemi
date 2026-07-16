<?php
/**
 * db_seramik.php — Seramik modülü için AYRI veritabanı bağlantısı ($pdoSeramik)
 *
 * Seramik ambar (giriş/çıkış/stok) verileri beton/demir'den ayrı DB'de tutulur.
 *   1) Ayrı MySQL veritabanı (önerilen): cPanel'de DB oluştur, kullanıcıya yetki ver,
 *      sonra config.php'ye:
 *          define('SERAMIK_DB_NAME', 'takbulut_seramik');
 *      (kullanıcı/şifre beton ile aynıysa SERAMIK_DB_USER / SERAMIK_DB_PASS gerekmez)
 *   2) SERAMIK_DB_NAME tanımlı değilse: ana veritabanı kullanılır; tüm tablolar
 *      'seramik_' önekli olduğundan diğer tablolarla karışmaz.
 */
if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: ../install.php');
    exit;
}
require_once __DIR__ . '/../config.php';

$__serDb   = defined('SERAMIK_DB_NAME') && SERAMIK_DB_NAME !== '' ? SERAMIK_DB_NAME : DB_NAME;
$__serUser = defined('SERAMIK_DB_USER') && SERAMIK_DB_USER !== '' ? SERAMIK_DB_USER : DB_USER;
$__serPass = defined('SERAMIK_DB_PASS') ? SERAMIK_DB_PASS : DB_PASS;

try {
    $pdoSeramik = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $__serDb . ';charset=utf8mb4',
        $__serUser,
        $__serPass,
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
        . '<strong>Seramik veritabanı bağlantı hatası.</strong><br>'
        . htmlspecialchars($e->getMessage())
        . '<br><br><small>Ayrı bir DB kullanıyorsanız cPanel\'de oluşturup config.php içinde '
        . '<code>SERAMIK_DB_NAME</code> tanımladığınızdan emin olun.</small>'
        . '</div>'
    );
}
