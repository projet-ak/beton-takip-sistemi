<?php
/**
 * whatsapp/api/telegram_al.php — Telegram bot webhook'u
 *
 * WhatsApp grubundaki mesajlar "Paylaş → Telegram → bot" ile (ya da botun üye
 * olduğu bir Telegram grubundan otomatik) buraya düşer; kuyruk → AI → onay
 * akışı WhatsApp elle yapıştırma ile birebir aynıdır.
 *
 * Kurulum (bir kez):
 *   1) @BotFather → /newbot → bot tokenini al
 *   2) config.php:  define('TELEGRAM_BOT_TOKEN', '123456:ABC...');
 *   3) Tarayıcıda webhook'u tanıt:
 *      https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://ernsaha.com.tr/beton/whatsapp/api/telegram_al.php&secret_token=<MESAJ_TOKEN>
 *   4) Bot bir GRUBA eklenecekse @BotFather → /setprivacy → Disable
 *      (yoksa bot grupta yalnız kendisine yazılanları görür)
 *
 * Kimlik: Telegram her isteğe X-Telegram-Bot-Api-Secret-Token başlığı ekler;
 * MESAJ_TOKEN ile birebir eşleşmeli. (Kurulum testi için ?token= de kabul edilir.)
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

if (!file_exists(dirname(dirname(__DIR__)) . '/config.php')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'config.php yok']);
    exit;
}
require_once dirname(dirname(__DIR__)) . '/config.php';

if (!defined('MESAJ_TOKEN') || MESAJ_TOKEN === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'config.php içinde MESAJ_TOKEN tanımlı değil']);
    exit;
}

$tok = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($tok === '') $tok = (string)($_GET['token'] ?? '');
if (!hash_equals(MESAJ_TOKEN, $tok)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Yetkisiz — secret token geçersiz']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Kurulum kontrolü için GET'e kısa durum cevabı
    echo json_encode(['ok' => true, 'msg' => 'Telegram webhook hazır — POST bekleniyor',
                      'bot_token_tanimli' => defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== '']);
    exit;
}

require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(__DIR__) . '/_ortak.php';

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { echo json_encode(['ok' => true, 'msg' => 'boş gövde']); exit; }

// Yalnız yeni mesajlar işlenir (düzenleme/servis olayları sessizce geçilir)
$msg = $body['message'] ?? ($body['channel_post'] ?? null);
if (!is_array($msg)) { echo json_encode(['ok' => true, 'msg' => 'mesaj değil, yok sayıldı']); exit; }

// ── Gönderen / grup ───────────────────────────────────────────────────────────
$from     = $msg['from'] ?? [];
$gonderen = trim(((string)($from['first_name'] ?? '')) . ' ' . ((string)($from['last_name'] ?? '')));
if ($gonderen === '') $gonderen = (string)($from['username'] ?? 'Telegram');

// Telegram içi yönlendirmede asıl kaynak kişiyi koru
$fo = $msg['forward_origin'] ?? null;
if (is_array($fo)) {
    $asil = trim((string)($fo['sender_user']['first_name'] ?? '') . ' ' . (string)($fo['sender_user']['last_name'] ?? ''));
    if ($asil === '') $asil = (string)($fo['sender_user_name'] ?? '');
    if ($asil !== '') $gonderen = $asil . ' (ileten: ' . $gonderen . ')';
}

$grup  = (string)($msg['chat']['title'] ?? 'Telegram');
$metin = (string)($msg['text'] ?? ($msg['caption'] ?? ''));

// ── Medya (fotoğraf / görsel-PDF belge) ───────────────────────────────────────
$botToken = defined('TELEGRAM_BOT_TOKEN') ? (string)TELEGRAM_BOT_TOKEN : '';
$fileId   = null;
if (!empty($msg['photo']) && is_array($msg['photo'])) {
    $enBuyuk = end($msg['photo']);                    // son eleman = en yüksek çözünürlük
    $fileId  = (string)($enBuyuk['file_id'] ?? '');
} elseif (!empty($msg['document']['file_id'])) {
    $dMime = (string)($msg['document']['mime_type'] ?? '');
    if (strpos($dMime, 'image/') === 0 || $dMime === 'application/pdf') {
        $fileId = (string)$msg['document']['file_id'];
    }
}

$medya = [];
$medyaNotu = null;
if ($fileId) {
    if ($botToken === '') {
        $medyaNotu = 'görsel var ama TELEGRAM_BOT_TOKEN tanımsız — indirilemedi';
    } else {
        $yol = telegram_medya_indir($fileId, $botToken);
        if ($yol !== null) $medya[] = $yol; else $medyaNotu = 'görsel indirilemedi';
    }
}

// ── Kuyruğa ekle ──────────────────────────────────────────────────────────────
// Albüm (çoklu fotoğraf) parça parça gelir; media_group_id ile tek mesajda birleşir.
$harici = !empty($msg['media_group_id'])
    ? 'tgalbum_' . (string)($msg['chat']['id'] ?? '0') . '_' . (string)$msg['media_group_id']
    : 'tg_' . (string)($msg['chat']['id'] ?? '0') . '_' . (string)($msg['message_id'] ?? '0');

$r = mesaj_kuyruga_ekle($pdo, [
    'kaynak'   => 'telegram',
    'grup'     => $grup,
    'gonderen' => $gonderen,
    'metin'    => $metin,
    'medya'    => $medya,
    'mesaj_id' => $harici,
]);

// Albümün sonraki parçası: görseli (ve varsa asıl caption'ı) ilk mesaja iliştir
if (!empty($r['mukerrer']) && ($medya || $metin !== '')) {
    mesaj_medya_ekle($pdo, (int)$r['id'], $medya, $metin);
}

echo json_encode([
    'ok'   => true,
    'id'   => $r['id'] ?? null,
    'not'  => $medyaNotu,
], JSON_UNESCAPED_UNICODE);
