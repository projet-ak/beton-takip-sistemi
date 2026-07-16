<?php
/**
 * db_depo.php — Depo (Sarf/Demirbaş/El Aletleri) modülü AYRI DB bağlantısı ($pdoDepo)
 *   config.php'ye: define('SERAMIK...') gibi define('DEPO_DB_NAME', 'takbulut_depo');
 *   Tanımsızsa ana DB'de 'depo_' önekli tablolar kullanılır.
 */
if (!file_exists(__DIR__ . '/../config.php')) { header('Location: ../install.php'); exit; }
require_once __DIR__ . '/../config.php';

$__depoDb   = defined('DEPO_DB_NAME') && DEPO_DB_NAME !== '' ? DEPO_DB_NAME : DB_NAME;
$__depoUser = defined('DEPO_DB_USER') && DEPO_DB_USER !== '' ? DEPO_DB_USER : DB_USER;
$__depoPass = defined('DEPO_DB_PASS') ? DEPO_DB_PASS : DB_PASS;

try {
    $pdoDepo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $__depoDb . ';charset=utf8mb4',
        $__depoUser, $__depoPass,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
} catch (PDOException $e) {
    http_response_code(503);
    die('<div style="font-family:sans-serif;color:#842029;background:#f8d7da;padding:20px;border-radius:8px;max-width:600px;margin:40px auto">'
        . '<strong>Depo veritabanı bağlantı hatası.</strong><br>' . htmlspecialchars($e->getMessage())
        . '<br><br><small>config.php içinde <code>DEPO_DB_NAME</code> tanımlı ve DB oluşturulmuş olmalı.</small></div>');
}
