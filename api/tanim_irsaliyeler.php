<?php
/**
 * api/tanim_irsaliyeler.php
 * Tanım kaydına bağlı irsaliyeleri döner (modal AJAX)
 *
 * GET: ?tip=katki|beton|pompa|kivam|firma|tedarikci|imalat|kalem  &id=N
 */
ob_start(); // Stray PHP çıktısı JSON'u bozmasın

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Yapılandırma bulunamadı']);
    exit;
}
require_auth();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$tip = $_GET['tip'] ?? '';
$id  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;

$izinliTipler = ['katki','beton','pompa','kivam','firma','tedarikci','imalat','kalem'];
if (!$id || !in_array($tip, $izinliTipler, true)) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Geçersiz parametre']);
    exit;
}

$tipMap = [
    'katki'     => ['tablo' => 'katki_listesi',    'kolon' => null,                'ad_col' => 'ad'],
    'beton'     => ['tablo' => 'beton_siniflari',  'kolon' => 'beton_sinifi_id',   'ad_col' => 'ad'],
    'pompa'     => ['tablo' => 'pompa_turleri',    'kolon' => 'pompa_id',          'ad_col' => 'ad'],
    'kivam'     => ['tablo' => 'kivam_siniflari',  'kolon' => 'kivam_sinifi_id',   'ad_col' => 'ad'],
    'firma'     => ['tablo' => 'firmalar',         'kolon' => 'firma_id',          'ad_col' => 'ad'],
    'tedarikci' => ['tablo' => 'tedarikciler',     'kolon' => 'tedarikci_id',      'ad_col' => 'ad'],
    'imalat'    => ['tablo' => 'imalat_gruplari',  'kolon' => 'imalat_grup_id',    'ad_col' => 'ad'],
    'kalem'     => ['tablo' => 'ana_is_kalemleri', 'kolon' => 'ana_is_kalemi_id',  'ad_col' => 'ad'],
];

try {
    $meta = $tipMap[$tip];

    // Tanım adını al
    $stmt = $pdo->prepare("SELECT {$meta['ad_col']} AS tanim_adi FROM {$meta['tablo']} WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Kayıt bulunamadı']);
        exit;
    }
    $tarimAd = $row['tanim_adi'];

    // İrsaliyeleri çek
    $baseSelect = "
        SELECT i.id, i.irsaliye_no, i.tarih, i.arac_plaka, i.miktar, i.tip,
               CONCAT(p.kod, IF(p.aciklama IS NOT NULL AND p.aciklama != '', CONCAT(' — ', p.aciklama), '')) AS proje_adi,
               t.ad  AS tedarikci_adi,
               bs.ad AS beton_sinifi_adi
        FROM irsaliyeler i
        LEFT JOIN projeler        p  ON i.proje_id        = p.id
        LEFT JOIN tedarikciler    t  ON i.tedarikci_id    = t.id
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

    ob_end_clean();
    echo json_encode([
        'ok'    => true,
        'ad'    => $tarimAd,
        'tip'   => $tip,
        'sayi'  => count($liste),
        'liste' => $liste,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Sunucu hatası: ' . $e->getMessage()]);
}
