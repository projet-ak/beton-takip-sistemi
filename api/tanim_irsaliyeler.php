<?php
/**
 * api/tanim_irsaliyeler.php
 * Tanım kaydına bağlı irsaliyeleri döner (modal için AJAX)
 *
 * GET: ?tip=katki|beton|pompa|kivam&id=N
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { http_response_code(403); exit; }
require_auth();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$tip = $_GET['tip'] ?? '';
$id  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id || !in_array($tip, ['katki','beton','pompa','kivam'], true)) {
    echo json_encode(['ok' => false, 'msg' => 'Geçersiz parametre']);
    exit;
}

$adMap = [
    'katki' => ['tablo' => 'katki_listesi',  'kolon' => null],
    'beton' => ['tablo' => 'beton_siniflari', 'kolon' => 'beton_sinifi_id'],
    'pompa' => ['tablo' => 'pompa_turleri',   'kolon' => 'pompa_id'],
    'kivam' => ['tablo' => 'kivam_siniflari', 'kolon' => 'kivam_sinifi_id'],
];

$meta = $adMap[$tip];

// Tanım adını al
$stmt = $pdo->prepare("SELECT ad FROM {$meta['tablo']} WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Kayıt bulunamadı']);
    exit;
}
$tarimAd = $row['ad'];

// İrsaliyeleri çek
$baseSelect = "
    SELECT i.id, i.irsaliye_no, i.tarih, i.arac_plaka, i.miktar, i.tip,
           p.ad AS proje_adi, t.ad AS tedarikci_adi, bs.ad AS beton_sinifi_adi
    FROM irsaliyeler i
    LEFT JOIN projeler    p  ON i.proje_id       = p.id
    LEFT JOIN tedarikciler t ON i.tedarikci_id   = t.id
    LEFT JOIN beton_siniflari bs ON i.beton_sinifi_id = bs.id
";

if ($tip === 'katki') {
    $sql = $baseSelect . " WHERE i.katki1_id = ? OR i.katki2_id = ? ORDER BY i.tarih DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id, $id]);
} else {
    $sql = $baseSelect . " WHERE i.{$meta['kolon']} = ? ORDER BY i.tarih DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
}

$liste = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'ok'    => true,
    'ad'    => $tarimAd,
    'tip'   => $tip,
    'sayi'  => count($liste),
    'liste' => $liste,
], JSON_UNESCAPED_UNICODE);
