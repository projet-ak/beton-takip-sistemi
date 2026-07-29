<?php
/**
 * api/demir_okut.php — Demir irsaliyesi AI OCR
 * POST: multipart/form-data, 'dosya' = PDF veya görsel
 * Response: {ok:true, alanlar:{irsaliye_no,tarih,arac_plaka,tedarikci_vkn,siparis_no,kalemler:[...]}}
 */
error_reporting(0);
ini_set('display_errors', '0');
if (ob_get_level()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!file_exists(__DIR__ . '/../config.php')) {
    ob_end_clean(); http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'msg'=>'Kurulum yapılmamış']); exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ai_call.php';
require_auth();
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['dosya'])) {
    echo json_encode(['ok'=>false,'msg'=>'Dosya gerekli (alan adı: dosya)']); exit;
}
$file = $_FILES['dosya'];
if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'msg'=>'Yükleme hatası: '.$file['error']]); exit; }
if ($file['size'] > 20*1024*1024) { echo json_encode(['ok'=>false,'msg'=>'Dosya çok büyük (maks 20 MB)']); exit; }

$mime = guess_mime($file['tmp_name'], $file['name']);
$allow = ['application/pdf','image/jpeg','image/png','image/webp','image/gif'];
if (!in_array($mime, $allow, true)) { echo json_encode(['ok'=>false,'msg'=>'Desteklenmeyen tür: '.$mime]); exit; }

$b64 = base64_encode(file_get_contents($file['tmp_name']));
$part = $mime === 'application/pdf'
    ? ['type'=>'document','data'=>$b64]
    : ['type'=>'image','mime'=>$mime,'data'=>$b64];

$prompt = <<<'PROMPT'
Bu belge bir İNŞAAT DEMİRİ (nervürlü/düz demir, kangal, çelik hasır) e-İrsaliyesidir.
Belgeden aşağıdaki alanları çıkar ve YALNIZCA geçerli bir JSON nesnesi döndür (markdown/açıklama yazma):

{
  "irsaliye_no": "irsaliye numarası (ör: TVA2026000004493)",
  "tarih": "YYYY-MM-DD",
  "arac_plaka": "araç plakası büyük harf boşluksuz",
  "tedarikci_vkn": "satıcı/üretici firmanın 10 haneli VKN'si (alıcının değil)",
  "siparis_no": "sipariş no varsa (ör: 1200046828), yoksa null",
  "kalemler": [
    {"cap_mm": 20, "tip": "duz", "miktar_ton": 25.66, "bag_adeti": 12}
  ]
}

Kurallar:
- "Mal" tablosundaki her satır bir kalemdir. Satır biçimi genelde: "20,00MM/12,000M/B_420C/... (Bağ Adt = 12)" ve Miktar sütununda "25,660 Ton".
- cap_mm: demir çapı mm cinsinden sayı (ör. 8, 10, 12, ... 32).
- tip: nervürlü/düz çubuk ise "duz", kangal ise "kangal", çelik hasır ise "hasir", spiral ise "spiral".
- miktar_ton: ton cinsinden sayısal değer (virgül yerine nokta).
- bag_adeti: varsa tam sayı, yoksa null.
- Türkçe sayı biçimi: "25,660" = 25.66 (virgül ondalık ayıracı).
- Bulunamayan alan için null kullan. Yalnızca JSON döndür.
PROMPT;

$result = ai_call('', [$part, ['type'=>'text','text'=>$prompt]], 1200);
if (!$result['ok']) { echo json_encode(['ok'=>false,'msg'=>$result['msg']]); exit; }

$text = trim($result['text']);
$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
$text = preg_replace('/\s*```$/i', '', trim($text));
$alan = json_decode($text, true);
if (!is_array($alan)) { echo json_encode(['ok'=>false,'msg'=>'Model geçersiz JSON döndürdü','raw'=>mb_substr($text,0,400)]); exit; }

echo json_encode(['ok'=>true,'alanlar'=>$alan], JSON_UNESCAPED_UNICODE);
