<?php
/**
 * api/pts_scan.php — Kiosk kart okutma ucu (PTS)
 *
 * ⚠⚠ BU UÇ OTURUM AÇMAZ. Kiosk bir tablet/kamera cihazıdır, kimse giriş yapmaz;
 * cihaz kendini `X-Checkpoint-Key` başlığındaki **cihaz anahtarıyla** tanıtır
 * (anahtar sunucuda üretilir, pts/noktalar.php'den kopyalanıp cihaza bir kez girilir).
 * Anahtar yoksa/geçersizse istek REDDEDİLİR — kart okuması kaynağı belirsiz kalmasın.
 *
 * `/api/` yolunda olduğu için CSRF ve modül denetiminden muaftır (bkz. auth.php);
 * güvenlik burada cihaz anahtarına dayanır.
 *
 * İstek  (POST JSON): {marker_id:int, sozluk?:string, yon?:'giris'|'cikis', foto?:dataURL}
 * Yanıt  (JSON)     : {ok, tekrar, ad_soyad, sicil_no, unvan, birim, yon, zaman, nokta}
 *   · `tekrar: true` → debounce penceresi içinde tekrar okundu, YENİ KAYIT AÇILMADI.
 *
 * Ayrıca `?whoami=1` + anahtar → cihaz kurulumunda anahtarı doğrulamak için
 * (yanlış yapıştırılan anahtarın ilk kart okutulana kadar fark edilmemesi kötü olurdu).
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
if (!file_exists(__DIR__ . '/../config.php')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'hata' => 'Sistem kurulu değil.']); exit;
}
require_once __DIR__ . '/../includes/db_pts.php';
require_once __DIR__ . '/../pts/_ortak.php';

/** Cihaz anahtarını başlıktan ya da gövdeden alır (bazı vekiller özel başlıkları düşürür). */
function pts_anahtar_oku(array $govde): string
{
    foreach (['HTTP_X_CHECKPOINT_KEY', 'HTTP_X_CIHAZ_ANAHTARI'] as $k) {
        if (!empty($_SERVER[$k])) return trim((string)$_SERVER[$k]);
    }
    return trim((string)($govde['anahtar'] ?? $_GET['anahtar'] ?? ''));
}

try {
    pts_semasi_kur($pdoPts);

    $ham    = file_get_contents('php://input') ?: '';
    $govde  = json_decode($ham, true);
    if (!is_array($govde)) $govde = $_POST;

    $anahtar = pts_anahtar_oku($govde);
    $nokta   = $anahtar !== '' ? pts_nokta_anahtarla($pdoPts, $anahtar) : null;
    if (!$nokta) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'hata' => $anahtar === ''
            ? 'Cihaz anahtarı gönderilmedi; kiosk kurulumunu tamamlayın.'
            : 'Cihaz anahtarı geçersiz ya da geçiş noktası pasif.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Kurulum doğrulaması: anahtar geçerli mi, hangi noktaya ait?
    if (isset($_GET['whoami'])) {
        echo json_encode(['ok' => true, 'nokta' => ['id' => (int)$nokta['id'], 'kod' => $nokta['kod'],
                          'ad' => $nokta['ad'], 'yon' => $nokta['yon']]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'hata' => 'POST bekleniyor.']); exit;
    }

    if (!isset($govde['marker_id']) || !is_numeric($govde['marker_id'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'hata' => 'marker_id gerekli.'], JSON_UNESCAPED_UNICODE); exit;
    }
    $marker = (int)$govde['marker_id'];
    $yon    = isset($govde['yon']) && isset(PTS_YON[$govde['yon']]) ? $govde['yon'] : null;
    $sozluk = !empty($govde['sozluk']) ? (string)$govde['sozluk'] : PTS_SOZLUK;

    $sonuc = pts_scan($pdoPts, $marker, $nokta, $yon, $sozluk);

    // Geçiş fotoğrafı: kaydın tamamlayıcısı, ÖN KOŞULU DEĞİL — yazılamazsa geçiş yine geçerli.
    if (!$sonuc['tekrar'] && !empty($sonuc['hareket_id']) && !empty($govde['foto'])) {
        $sonuc['foto_url'] = pts_foto_kaydet($pdoPts, (int)$sonuc['hareket_id'], (string)$govde['foto'], __DIR__ . '/..');
    }

    $sonuc['ok']    = true;
    $sonuc['nokta'] = ['kod' => $nokta['kod'], 'ad' => $nokta['ad']];
    echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    // Tanımsız kart / ayrılmış personel — kiosk ekranında kırmızı uyarı olarak gösterilir.
    http_response_code(404);
    echo json_encode(['ok' => false, 'hata' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('pts_scan: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'hata' => 'Sunucu hatası.'], JSON_UNESCAPED_UNICODE);
}
