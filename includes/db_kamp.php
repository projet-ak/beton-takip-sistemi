<?php
/**
 * db_kamp.php — Kamp Takip modülü AYRI DB bağlantısı ($pdoKamp)
 *   config.php'ye: define('KAMP_DB_NAME', 'takbulut_kamp');
 *   Tanımsızsa ana DB'de 'kamp_' önekli tablolar kullanılır (tablo çakışması olmaz,
 *   ama modül boş açılırsa önce bu sabiti kontrol et — bkz. CLAUDE.md §6).
 *
 * ⭐ KURAL (§0, tüm modüller): Kamp modülü KENDİ veritabanında, KENDİ kişi/firma
 * listesiyle çalışır; başka modülün tablosuna JOIN atmaz, çekirdeğini require etmez.
 */
if (!file_exists(__DIR__ . '/../config.php')) { header('Location: ../install.php'); exit; }
require_once __DIR__ . '/../config.php';

$__kpDb   = defined('KAMP_DB_NAME') && KAMP_DB_NAME !== '' ? KAMP_DB_NAME : DB_NAME;
$__kpUser = defined('KAMP_DB_USER') && KAMP_DB_USER !== '' ? KAMP_DB_USER : DB_USER;
$__kpPass = defined('KAMP_DB_PASS') ? KAMP_DB_PASS : DB_PASS;

try {
    $pdoKamp = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $__kpDb . ';charset=utf8mb4',
        $__kpUser, $__kpPass,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
} catch (PDOException $e) {
    http_response_code(503);
    die('<div style="font-family:sans-serif;color:#842029;background:#f8d7da;padding:20px;border-radius:8px;max-width:600px;margin:40px auto">'
        . '<strong>Kamp veritabanı bağlantı hatası.</strong><br>' . htmlspecialchars($e->getMessage())
        . '<br><br><small>config.php içinde <code>KAMP_DB_NAME</code> tanımlı ve DB oluşturulmuş olmalı.</small></div>');
}
