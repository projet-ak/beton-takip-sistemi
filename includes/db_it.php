<?php
/**
 * db_it.php — IT Envanter modülü AYRI DB bağlantısı ($pdoIt)
 *   config.php'ye: define('IT_DB_NAME', 'takbulut_it');
 *   Tanımsızsa ana DB'de 'it_' önekli tablolar kullanılır (tablo çakışması olmaz,
 *   ama modül boş açılırsa önce bu sabiti kontrol et — bkz. CLAUDE.md §6).
 */
if (!file_exists(__DIR__ . '/../config.php')) { header('Location: ../install.php'); exit; }
require_once __DIR__ . '/../config.php';

$__itDb   = defined('IT_DB_NAME') && IT_DB_NAME !== '' ? IT_DB_NAME : DB_NAME;
$__itUser = defined('IT_DB_USER') && IT_DB_USER !== '' ? IT_DB_USER : DB_USER;
$__itPass = defined('IT_DB_PASS') ? IT_DB_PASS : DB_PASS;

try {
    $pdoIt = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $__itDb . ';charset=utf8mb4',
        $__itUser, $__itPass,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
} catch (PDOException $e) {
    http_response_code(503);
    die('<div style="font-family:sans-serif;color:#842029;background:#f8d7da;padding:20px;border-radius:8px;max-width:600px;margin:40px auto">'
        . '<strong>IT Envanter veritabanı bağlantı hatası.</strong><br>' . htmlspecialchars($e->getMessage())
        . '<br><br><small>config.php içinde <code>IT_DB_NAME</code> tanımlı ve DB oluşturulmuş olmalı.</small></div>');
}
