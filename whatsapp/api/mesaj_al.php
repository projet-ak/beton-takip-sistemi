<?php
/**
 * whatsapp/api/mesaj_al.php — Dış kaynaktan (WhatsApp botu vb.) mesaj alma uç noktası
 *
 * Kimlik doğrulama: config.php içindeki MESAJ_TOKEN.
 *   Authorization: Bearer <token>   ya da   ?token=<token>
 *
 * Kullanım (kaynak bağımsız, sade JSON):
 *   POST /whatsapp/api/mesaj_al.php
 *   { "kaynak":"whatsapp", "grup":"Saha Sevkiyat", "gonderen":"Ahmet",
 *     "metin":"C30 25 m3 34ABC123 irs 12345", "medya_url":"", "mesaj_id":"wamid.xxx" }
 *
 * Meta WhatsApp Cloud API webhook doğrulaması (GET hub.challenge) da desteklenir.
 *
 * NOT: Buraya düşen mesaj doğrudan irsaliye OLMAZ; kuyruğa girer,
 *      mesajlar.php ekranında insan onayından sonra kayda dönüşür.
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

// ── Token ─────────────────────────────────────────────────────────────────────
$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$tok = '';
if (preg_match('/Bearer\s+(.+)/i', $hdr, $m)) $tok = trim($m[1]);
if ($tok === '') $tok = (string)($_GET['token'] ?? '');

// ── Meta Cloud API webhook doğrulaması (GET) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode'], $_GET['hub_verify_token'])) {
    if (hash_equals(MESAJ_TOKEN, (string)$_GET['hub_verify_token'])) {
        header('Content-Type: text/plain; charset=utf-8');
        echo (string)($_GET['hub_challenge'] ?? '');
    } else {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Doğrulama tokeni geçersiz']);
    }
    exit;
}

if ($tok === '' || !hash_equals(MESAJ_TOKEN, $tok)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Yetkisiz — token geçersiz']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Yalnız POST']);
    exit;
}

require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(__DIR__) . '/_ortak.php';

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Geçersiz JSON']);
    exit;
}

// Meta Cloud API / toplu / tek mesaj — üçü de sade formata çevrilir
$mesajlar = mesaj_webhook_coz($body);

$eklenen = 0; $mukerrer = 0; $hatali = 0; $idler = [];
foreach ($mesajlar as $m) {
    if (!is_array($m)) { $hatali++; continue; }
    $r = mesaj_kuyruga_ekle($pdo, $m);
    if (!$r['ok'])            { $hatali++;  continue; }
    if (!empty($r['mukerrer'])) { $mukerrer++; continue; }
    $eklenen++; $idler[] = $r['id'];
}

// AI ayrıştırma: istek anında değil, onay ekranı açılınca yapılır (webhook hızlı dönsün).
echo json_encode([
    'ok'       => true,
    'eklenen'  => $eklenen,
    'mukerrer' => $mukerrer,
    'hatali'   => $hatali,
    'idler'    => $idler,
], JSON_UNESCAPED_UNICODE);
