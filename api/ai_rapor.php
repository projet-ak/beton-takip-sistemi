<?php
/**
 * ai_rapor.php — Anomali tespiti ve doğal dil Q&A (Claude veya Gemini)
 *
 * POST action=anomali → SQL tabanlı anomali listesi (AI kullanmaz)
 * POST action=soru    → DB özeti + kullanıcı sorusu → AI yanıtı
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
require_auth(['admin', 'teknik_ofis_admin', 'teknik_ofis']);
require_once __DIR__ . '/../includes/db.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'POST gerekli']);
    exit;
}

$action = $_POST['action'] ?? '';

// ── Anomali Tespiti (SQL — AI kullanmaz) ────────────────────────────────────
if ($action === 'anomali') {
    $anomaliler = [];

    // 1. Aynı gün aynı plaka 3'ten fazla
    $rows = $pdo->query("
        SELECT DATE(tarih) as gun, arac_plaka, COUNT(*) as sayi
        FROM irsaliyeler
        WHERE tip='alis' AND arac_plaka IS NOT NULL AND arac_plaka != ''
        GROUP BY DATE(tarih), arac_plaka
        HAVING sayi > 3
        ORDER BY sayi DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $anomaliler[] = [
            'tip'    => 'Aynı Gün Aynı Plaka (3\'ten fazla teslimat)',
            'renk'   => 'warning',
            'satirlar' => array_map(fn($r) => $r['gun'] . ' — ' . $r['arac_plaka'] . ' — ' . $r['sayi'] . ' sefer', $rows),
        ];
    }

    // 2. Anormal miktar
    $rows = $pdo->query("
        SELECT irsaliye_no, DATE(tarih) as tarih, arac_plaka, miktar
        FROM irsaliyeler
        WHERE tip='alis' AND (miktar < 1 OR miktar > 12)
        ORDER BY tarih DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $anomaliler[] = [
            'tip'    => 'Anormal Miktar (< 1 veya > 12 m³)',
            'renk'   => 'danger',
            'satirlar' => array_map(fn($r) => ($r['tarih'] ?: '—') . ' — ' . ($r['irsaliye_no'] ?: 'no yok') . ' — ' . $r['miktar'] . ' m³', $rows),
        ];
    }

    // 3. Mükerrer irsaliye no
    $rows = $pdo->query("
        SELECT irsaliye_no, COUNT(*) as sayi
        FROM irsaliyeler
        WHERE irsaliye_no IS NOT NULL AND irsaliye_no != ''
        GROUP BY irsaliye_no
        HAVING sayi > 1
        ORDER BY sayi DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $anomaliler[] = [
            'tip'    => 'Mükerrer İrsaliye Numarası',
            'renk'   => 'danger',
            'satirlar' => array_map(fn($r) => $r['irsaliye_no'] . ' — ' . $r['sayi'] . ' kayıt', $rows),
        ];
    }

    // 4. Son 30 günde proje atanmamış
    $sayi = (int)$pdo->query("
        SELECT COUNT(*) FROM irsaliyeler
        WHERE proje_id IS NULL AND tip='alis'
          AND tarih >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetchColumn();
    if ($sayi > 0) {
        $anomaliler[] = [
            'tip'    => 'Proje Atanmamış (son 30 gün)',
            'renk'   => 'warning',
            'satirlar' => [$sayi . ' irsaliyede proje bilgisi eksik'],
        ];
    }

    echo json_encode(['ok' => true, 'anomaliler' => $anomaliler, 'temiz' => empty($anomaliler)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Soru-Cevap (AI) ─────────────────────────────────────────────────────────
if ($action === 'soru') {
    $soru = trim($_POST['soru'] ?? '');
    if ($soru === '') {
        echo json_encode(['ok' => false, 'msg' => 'Soru boş olamaz']);
        exit;
    }
    if (mb_strlen($soru) > 600) {
        echo json_encode(['ok' => false, 'msg' => 'Soru çok uzun (maks. 600 karakter)']);
        exit;
    }

    // DB özeti
    $ozet = [];
    $row  = $pdo->query("SELECT COUNT(*) as toplam, SUM(miktar) as m3 FROM irsaliyeler WHERE tip='alis'")->fetch(PDO::FETCH_ASSOC);
    $ozet['toplam_irsaliye'] = (int)($row['toplam'] ?? 0);
    $ozet['toplam_m3']       = round((float)($row['m3'] ?? 0), 2);

    $ozet['beton_siniflari'] = $pdo->query("
        SELECT b.sinif, ROUND(SUM(i.miktar),2) as m3
        FROM irsaliyeler i LEFT JOIN beton_siniflari b ON b.id=i.beton_sinifi_id
        WHERE i.tip='alis' GROUP BY b.sinif ORDER BY m3 DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    $ozet['tedarikciler'] = $pdo->query("
        SELECT t.ad, ROUND(SUM(i.miktar),2) as m3
        FROM irsaliyeler i LEFT JOIN tedarikciler t ON t.id=i.tedarikci_id
        WHERE i.tip='alis' GROUP BY t.ad ORDER BY m3 DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    $ozet['aylik_son6ay'] = $pdo->query("
        SELECT DATE_FORMAT(tarih,'%Y-%m') as ay, ROUND(SUM(miktar),2) as m3, COUNT(*) as adet
        FROM irsaliyeler
        WHERE tip='alis' AND tarih >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY ay ORDER BY ay
    ")->fetchAll(PDO::FETCH_ASSOC);

    $ozet['projeler'] = $pdo->query("
        SELECT p.kod, ROUND(SUM(i.miktar),2) as m3
        FROM irsaliyeler i LEFT JOIN projeler p ON p.id=i.proje_id
        WHERE i.tip='alis' GROUP BY p.kod ORDER BY m3 DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    $contextJson = json_encode($ozet, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $systemPrompt = 'Sen ERN Holding\'e ait beton teslimat takip sisteminin veri asistanısın. '
        . 'Kullanıcı sana sistemden çekilmiş özet istatistikler verecek. Türkçe, kısa ve net yanıt ver. '
        . 'Yalnızca verilen veriye dayanarak yanıt ver; veri yoksa "Bu bilgi sistemde bulunamadı" de.';

    $userText = "Sistem istatistikleri:\n```json\n{$contextJson}\n```\n\nSorum: {$soru}";

    $result = ai_call($systemPrompt, [['type' => 'text', 'text' => $userText]], 512);

    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'msg' => $result['msg']]);
        exit;
    }

    echo json_encode(['ok' => true, 'cevap' => $result['text']], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Geçersiz action']);
