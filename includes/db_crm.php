<?php
/**
 * db_crm.php — CRM (Üretim Arızaları) modülü AYRI DB bağlantısı ($pdoCrm)
 *   config.php'ye: define('CRM_DB_NAME', 'takbulut_crm');
 *   Tanımsızsa ana DB'de 'crm_' önekli tablolar kullanılır (tablo çakışması olmaz,
 *   ama modül boş açılırsa önce bu sabiti kontrol et — bkz. CLAUDE.md §6).
 */
if (!file_exists(__DIR__ . '/../config.php')) { header('Location: ../install.php'); exit; }
require_once __DIR__ . '/../config.php';

$__crmDb   = defined('CRM_DB_NAME') && CRM_DB_NAME !== '' ? CRM_DB_NAME : DB_NAME;
$__crmUser = defined('CRM_DB_USER') && CRM_DB_USER !== '' ? CRM_DB_USER : DB_USER;
$__crmPass = defined('CRM_DB_PASS') ? CRM_DB_PASS : DB_PASS;

try {
    $pdoCrm = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $__crmDb . ';charset=utf8mb4',
        $__crmUser, $__crmPass,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
} catch (PDOException $e) {
    http_response_code(503);
    die('<div style="font-family:sans-serif;color:#842029;background:#f8d7da;padding:20px;border-radius:8px;max-width:600px;margin:40px auto">'
        . '<strong>CRM veritabanı bağlantı hatası.</strong><br>' . htmlspecialchars($e->getMessage())
        . '<br><br><small>config.php içinde <code>CRM_DB_NAME</code> tanımlı ve DB oluşturulmuş olmalı.</small></div>');
}
