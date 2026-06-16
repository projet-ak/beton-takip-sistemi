<?php
/**
 * ai_okut.php — AI ile irsaliye belgesi OCR (Claude veya Gemini)
 *
 * POST: multipart/form-data, 'dosya' alanı = PDF veya görsel
 * Response: {ok:true, alanlar:{...}} | {ok:false, msg:string}
 */

error_reporting(0);
ini_set('display_errors', '0');
if (ob_get_level()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!file_exists(__DIR__ . '/../config.php')) {
    ob_end_clean();
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Kurulum yapılmamış']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ai_call.php';
require_auth();
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['dosya'])) {
    echo json_encode(['ok' => false, 'msg' => 'Dosya gerekli (alan adı: dosya)']);
    exit;
}

$file = $_FILES['dosya'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'msg' => 'Yükleme hatası: ' . $file['error']]);
    exit;
}

if ($file['size'] > 20 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'msg' => 'Dosya çok büyük (maks. 20 MB)']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($mime, $allowedMimes, true)) {
    echo json_encode(['ok' => false, 'msg' => 'Desteklenmeyen dosya türü: ' . $mime]);
    exit;
}

$b64 = base64_encode(file_get_contents($file['tmp_name']));

$contentPart = $mime === 'application/pdf'
    ? ['type' => 'document', 'data' => $b64]
    : ['type' => 'image',    'mime' => $mime, 'data' => $b64];

$prompt = <<<'PROMPT'
Bu belge bir beton irsaliyesidir. Belgeden aşağıdaki alanları çıkar ve YALNIZCA geçerli bir JSON nesnesi döndür (başka açıklama, markdown ya da ek metin yazma):

{
  "irsaliye_no":        "irsaliye/sevk irsaliyesi numarası (örn: SAM2024000024921)",
  "tarih":              "YYYY-MM-DD formatında tarih",
  "arac_plaka":         "araç plakası büyük harf boşluksuz (örn: 34ABC123)",
  "miktar":             sayısal m³ değeri (string değil, örn: 7.5),
  "tedarikci_vkn":      "tedarikçi/üretici firmanın 10 haneli vergi kimlik numarası",
  "beton_sinifi":       "beton basınç dayanım sınıfı (örn: C30, C37, C25/30)",
  "kivam":              "kıvam/slump sınıfı (örn: S3, S4)",
  "ettn":               "ETTN veya e-irsaliye UUID kodu (örn: 2AFA1679-EDA8-420C-822F-...)",
  "fatura_no":          "ayrı bir fatura numarası varsa, yoksa null",
  "mikser_cikis_saati": "mikser/üretim çıkış saati HH:MM formatında",
  "katki1":             "belgede geçen 1. katkı madde adı (örn: DMAX, BRÜT BETON, SU GEÇİRİMSİZLİK, ANTİFRİZ)",
  "katki2":             "belgede geçen 2. katkı madde adı, yoksa null"
}

Bulunamayan alan için null kullan. Yalnızca JSON döndür.
PROMPT;

$result = ai_call('', [$contentPart, ['type' => 'text', 'text' => $prompt]], 800);

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'msg' => $result['msg']]);
    exit;
}

$text = trim($result['text']);
$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
$text = preg_replace('/\s*```$/i', '', trim($text));

$alanlar = json_decode($text, true);
if (!is_array($alanlar)) {
    echo json_encode(['ok' => false, 'msg' => 'Model geçersiz JSON döndürdü', 'raw' => mb_substr($text, 0, 500)]);
    exit;
}

echo json_encode(['ok' => true, 'alanlar' => $alanlar], JSON_UNESCAPED_UNICODE);
