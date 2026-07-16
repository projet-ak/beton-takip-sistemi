<?php
/**
 * db_akaryakit.php — Akaryakıt (Mazot) Takip modülü AYRI DB bağlantısı ($pdoAkaryakit)
 *   config.php'ye: define('AKARYAKIT_DB_NAME', 'takbulut_akaryakit');
 *   Tanımsızsa ana DB'de 'akaryakit_' önekli tablolar kullanılır.
 */
if (!file_exists(__DIR__ . '/../config.php')) { header('Location: ../install.php'); exit; }
require_once __DIR__ . '/../config.php';

$__akDb   = defined('AKARYAKIT_DB_NAME') && AKARYAKIT_DB_NAME !== '' ? AKARYAKIT_DB_NAME : DB_NAME;
$__akUser = defined('AKARYAKIT_DB_USER') && AKARYAKIT_DB_USER !== '' ? AKARYAKIT_DB_USER : DB_USER;
$__akPass = defined('AKARYAKIT_DB_PASS') ? AKARYAKIT_DB_PASS : DB_PASS;

try {
    $pdoAkaryakit = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $__akDb . ';charset=utf8mb4',
        $__akUser, $__akPass,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
} catch (PDOException $e) {
    http_response_code(503);
    die('<div style="font-family:sans-serif;color:#842029;background:#f8d7da;padding:20px;border-radius:8px;max-width:600px;margin:40px auto">'
        . '<strong>Akaryakıt veritabanı bağlantı hatası.</strong><br>' . htmlspecialchars($e->getMessage())
        . '<br><br><small>config.php içinde <code>AKARYAKIT_DB_NAME</code> tanımlı ve DB oluşturulmuş olmalı.</small></div>');
}
