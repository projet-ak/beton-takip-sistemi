<?php
/**
 * api/ai_asistan.php — AI Asistan API endpoint
 * Claude ile tool-use döngüsü: SQL sorguları çalıştırarak veri döner
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

if (!file_exists(__DIR__ . '/../config.php')) { http_response_code(500); exit; }

require_auth();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['hata' => 'POST gerekli']); exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$mesaj  = trim($body['mesaj'] ?? '');
$gecmis = $body['gecmis'] ?? [];

if ($mesaj === '') {
    echo json_encode(['hata' => 'Mesaj boş olamaz']); exit;
}

// ── Güvenli SQL çalıştırıcı ───────────────────────────────────────────────────
function calistirSorgu(PDO $pdo, string $sorgu): array {
    $sorgu = trim($sorgu);
    // Sadece SELECT
    if (!preg_match('/^\s*SELECT\b/i', $sorgu)) {
        return ['hata' => 'Yalnızca SELECT sorguları desteklenmektedir.'];
    }
    // Tehlikeli anahtar kelimeler
    if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE|GRANT|REVOKE|CALL)\b/i', $sorgu)) {
        return ['hata' => 'Bu sorgu türüne izin verilmiyor.'];
    }
    try {
        $stmt = $pdo->query($sorgu);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['satirlar' => $rows, 'adet' => count($rows)];
    } catch (Exception $e) {
        return ['hata' => 'Sorgu hatası: ' . $e->getMessage()];
    }
}

// ── Sistem promptu ────────────────────────────────────────────────────────────
$sistemPrompt = <<<'SYS'
Sen ERN Holding Beton Takip Sistemi için bir yapay zeka asistanısın. Türkçe konuşuyorsun.

Kullanıcıların beton irsaliyeleri, projeler, tedarikçiler ve üretim verileri hakkındaki sorularını yanıtlıyorsun.
Gerektiğinde sql_sorgu aracını kullanarak veritabanından gerçek veri çekebilirsin.
Sonuçları anlaşılır, özlü Türkçe ile özetle. Tablo yerine madde madde veya kısa paragraflarla anlat.
Eğer veri yoksa "Kayıt bulunamadı" de.

── Veritabanı Şeması ──────────────────────────────────────────────────────────

irsaliyeler: id, tip(alis/iade), irsaliye_no, fatura_no, tarih(DATE), tedarikci_id,
  proje_id, beton_sinifi_id, miktar(m³ DECIMAL), kivam_sinifi_id, arac_plaka,
  firma_id(pompa), parsel_id, blok_id, kot_id, imalat_grup_id, ana_is_kalemi_id,
  pompa_id, katki1_id, katki2_id, aciklama, created_at

projeler: id, kod(varchar), aciklama(proje adı/açıklaması), aktif(1/0)

tedarikciler: id, ad, vkn, telefon, aktif(1/0)

beton_siniflari: id, ad  -- C16,C20,C25,C30,C35,C40,KURU SHOTCRETE,YAŞ SHOTCRETE,ŞAP 200,ŞAP 300

kivam_siniflari: id, ad  -- S1,S2,S3,S4

firmalar: id, ad, aktif  -- pompa firmaları

parseller: id, proje_id, ad

bloklar: id, parsel_id, ad

kotlar: id, blok_id, ad

imalat_gruplari: id, ad  -- İKSA,BETON,DUVAR,ALTYAPI,DİĞER

ana_is_kalemleri: id, imalat_grup_id, ad  -- FORE KAZIK,TEMEL,KOLON-PERDE,DÖŞEME vb.

katki_listesi: id, ad  -- ANTİFRİZ,SU GEÇİRİMSİZLİK,BRÜT BETON vb.

pompa_turleri: id, ad

── JOIN İpuçları ──────────────────────────────────────────────────────────────
- Tedarikçi adı: JOIN tedarikciler t ON t.id = i.tedarikci_id → t.ad
- Proje: JOIN projeler p ON p.id = i.proje_id → p.kod, p.aciklama
- Beton sınıfı: JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id → bs.ad
- Kıvam: JOIN kivam_siniflari ks ON ks.id = i.kivam_sinifi_id → ks.ad
- Güncel ay: MONTH(i.tarih) = MONTH(CURDATE()) AND YEAR(i.tarih) = YEAR(CURDATE())
- Sorgu LIMIT: Uzun listeler için LIMIT 20 ekle.
SYS;

// ── Araç tanımı ───────────────────────────────────────────────────────────────
$tools = [[
    'name'        => 'sql_sorgu',
    'description' => 'Veritabanında SELECT sorgusu çalıştırır ve sonuçları döner. Sadece okuma amaçlıdır.',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'sorgu' => [
                'type'        => 'string',
                'description' => 'Çalıştırılacak SQL SELECT sorgusu',
            ],
        ],
        'required' => ['sorgu'],
    ],
]];

// ── Mesaj geçmişini hazırla ───────────────────────────────────────────────────
$messages = [];
foreach ($gecmis as $m) {
    $rol = ($m['rol'] ?? '') === 'asistan' ? 'assistant' : 'user';
    $messages[] = ['role' => $rol, 'content' => (string)($m['icerik'] ?? '')];
}
$messages[] = ['role' => 'user', 'content' => $mesaj];

// ── Claude API döngüsü (tool-use) ─────────────────────────────────────────────
$apiKey = defined('CLAUDE_API_KEY') ? CLAUDE_API_KEY : '';
if (!$apiKey) {
    echo json_encode(['hata' => 'API anahtarı tanımlı değil.']); exit;
}

$sonYanit = '';
$maxTur   = 6;

for ($tur = 0; $tur < $maxTur; $tur++) {
    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1024,
        'system'     => $sistemPrompt,
        'tools'      => $tools,
        'messages'   => $messages,
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); // PHP 8.0+ curl_close() no-op (8.5'te deprecated)

    if ($resp === false || $httpCode !== 200) {
        $err = json_decode($resp, true);
        echo json_encode(['hata' => 'API hatası: ' . ($err['error']['message'] ?? $httpCode)]); exit;
    }

    $data      = json_decode($resp, true);
    $stopReason = $data['stop_reason'] ?? '';
    $content    = $data['content'] ?? [];

    // Asistan yanıtını geçmişe ekle
    $messages[] = ['role' => 'assistant', 'content' => $content];

    if ($stopReason === 'end_turn') {
        // Son metin yanıtını topla
        foreach ($content as $blok) {
            if (($blok['type'] ?? '') === 'text') {
                $sonYanit .= $blok['text'];
            }
        }
        break;
    }

    if ($stopReason === 'tool_use') {
        // Araç çağrılarını işle
        $toolResults = [];
        foreach ($content as $blok) {
            if (($blok['type'] ?? '') !== 'tool_use') continue;
            $toolId = $blok['id'];
            $input  = $blok['input'] ?? [];

            if ($blok['name'] === 'sql_sorgu') {
                $sonuc = calistirSorgu($pdo, $input['sorgu'] ?? '');
            } else {
                $sonuc = ['hata' => 'Bilinmeyen araç'];
            }

            $toolResults[] = [
                'type'        => 'tool_result',
                'tool_use_id' => $toolId,
                'content'     => json_encode($sonuc, JSON_UNESCAPED_UNICODE),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $toolResults];
        continue;
    }

    // Beklenmeyen stop_reason
    foreach ($content as $blok) {
        if (($blok['type'] ?? '') === 'text') $sonYanit .= $blok['text'];
    }
    break;
}

if ($sonYanit === '') {
    $sonYanit = 'Yanıt alınamadı, lütfen tekrar deneyin.';
}

echo json_encode(['yanit' => $sonYanit], JSON_UNESCAPED_UNICODE);
