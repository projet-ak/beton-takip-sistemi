<?php
/**
 * db_prekast.php — Prekast Takip modülü DB bağlantısı ($pdoPrekast)
 *
 * Prekast **CRM ile aynı veritabanını paylaşır** (tablolar `prekast_` önekli), bu yüzden
 * bağlantı db_crm.php üzerinden kurulur. İleride ayrılmak istenirse config.php'ye
 *   define('PREKAST_DB_NAME', 'takbulut_prekast');
 * eklenmesi yeterli — o zaman kendi bağlantısı açılır.
 */
require_once __DIR__ . '/db_crm.php';

if (defined('PREKAST_DB_NAME') && PREKAST_DB_NAME !== '' && PREKAST_DB_NAME !== $__crmDb) {
    $__pkUser = defined('PREKAST_DB_USER') && PREKAST_DB_USER !== '' ? PREKAST_DB_USER : DB_USER;
    $__pkPass = defined('PREKAST_DB_PASS') ? PREKAST_DB_PASS : DB_PASS;
    try {
        $pdoPrekast = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . PREKAST_DB_NAME . ';charset=utf8mb4',
            $__pkUser, $__pkPass,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
        );
    } catch (PDOException $e) {
        http_response_code(503);
        die('<div style="font-family:sans-serif;color:#842029;background:#f8d7da;padding:20px;border-radius:8px;max-width:600px;margin:40px auto">'
            . '<strong>Prekast veritabanı bağlantı hatası.</strong><br>' . htmlspecialchars($e->getMessage()) . '</div>');
    }
    $__prekastDb = PREKAST_DB_NAME;
} else {
    $pdoPrekast  = $pdoCrm;      // varsayılan: CRM veritabanı
    $__prekastDb = $__crmDb;
}
