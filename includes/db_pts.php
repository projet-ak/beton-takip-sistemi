<?php
/**
 * db_pts.php — PTS (Personel Takip Sistemi) modülü bağlantısı ($pdoPts)
 *
 * ⚠⚠ PTS, **IT Envanter ile AYNI veritabanını paylaşır** (`IT_DB_NAME`, tablolar
 * `pts_` önekli) — Prekast'ın CRM ile paylaşmasıyla aynı desen. Sebebi keyfî değil:
 * PTS'nin personeli `it_personel` tablosudur (ayrı bir personel listesi AÇILMAZ) ve
 * her kart okutmada / her puantaj satırında o tabloya JOIN atılır. Ayrı DB'ye
 * konsaydı bu JOIN'ler veritabanı-ötesi olur, `IT_DB_NAME` tanımsız kalınca da
 * sessizce kırılırdı.
 *
 * Bu yüzden `PTS_DB_NAME` gibi bir ayırma sabiti bilerek YOKTUR. PTS'yi ileride
 * ayırmak isterseniz önce personel kaynağını çözmeniz gerekir.
 *
 * config.php'ye: define('IT_DB_NAME', 'takbulut_it');
 */
if (!file_exists(__DIR__ . '/../config.php')) { header('Location: ../install.php'); exit; }
require_once __DIR__ . '/../config.php';

$__ptsDb   = defined('IT_DB_NAME') && IT_DB_NAME !== '' ? IT_DB_NAME : DB_NAME;
$__ptsUser = defined('IT_DB_USER') && IT_DB_USER !== '' ? IT_DB_USER : DB_USER;
$__ptsPass = defined('IT_DB_PASS') ? IT_DB_PASS : DB_PASS;

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
        . '<br><br><small>PTS, IT Envanter ile aynı DB\'yi kullanır: config.php içinde <code>IT_DB_NAME</code> tanımlı olmalı.</small></div>');
}
