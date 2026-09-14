<?php
/**
 * db_pts.php — PTS (Personel Takip) modülü AYRI DB bağlantısı ($pdoPts)
 *   config.php'ye: define('PTS_DB_NAME', 'takbulut_pts');
 *   Tanımsızsa ana DB'de 'pts_' önekli tablolar kullanılır (tablo çakışması olmaz,
 *   ama modül boş açılırsa önce bu sabiti kontrol et — bkz. CLAUDE.md §6).
 *
 * ⭐ KURAL (tüm modüller): her modül KENDİ veritabanında, KENDİ uploads klasöründe ve
 * diğer modüllerden BAĞIMSIZ çalışır. PTS'nin personel listesi de kendisinindir
 * (`pts_personel`); IT Envanter'deki personel kartlarıyla yalnız istenirse, tek yönlü
 * ve SİCİL NO üzerinden "aktar" düğmesiyle eşleşir — çalışma anında IT'ye bağımlılık YOKTUR.
 */
if (!file_exists(__DIR__ . '/../config.php')) { header('Location: ../install.php'); exit; }
require_once __DIR__ . '/../config.php';

$__ptsDb   = defined('PTS_DB_NAME') && PTS_DB_NAME !== '' ? PTS_DB_NAME : DB_NAME;
$__ptsUser = defined('PTS_DB_USER') && PTS_DB_USER !== '' ? PTS_DB_USER : DB_USER;
$__ptsPass = defined('PTS_DB_PASS') ? PTS_DB_PASS : DB_PASS;

try {
    $pdoPts = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $__ptsDb . ';charset=utf8mb4',
        $__ptsUser, $__ptsPass,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
} catch (PDOException $e) {
    http_response_code(503);
    die('<div style="font-family:sans-serif;color:#842029;background:#f8d7da;padding:20px;border-radius:8px;max-width:600px;margin:40px auto">'
        . '<strong>PTS veritabanı bağlantı hatası.</strong><br>' . htmlspecialchars($e->getMessage())
        . '<br><br><small>config.php içinde <code>PTS_DB_NAME</code> tanımlı ve DB oluşturulmuş olmalı.</small></div>');
}
