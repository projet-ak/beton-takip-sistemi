<?php
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
if (!file_exists(dirname(__DIR__) . '/config.php')) { http_response_code(403); die('{}'); }
require_auth();
require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'msg'=>'POST gerekli']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['kayitlar']) || !is_array($body['kayitlar'])) {
    echo json_encode(['ok'=>false,'msg'=>'Geçersiz veri']); exit;
}

$uid = current_user_id();
$eklenen = 0; $atlanan = 0; $hatalar = []; $cakismalar = [];

foreach ($body['kayitlar'] as $k) {
    try {
        $irsaliyeNo   = trim($k['irsaliye_no']  ?? '') ?: null;
        $tarih        = trim($k['tarih']         ?? '');
        $aracPlaka    = strtoupper(trim($k['arac_plaka']  ?? '')) ?: null;
        $mikserCikis  = trim($k['mikser_cikis_saati'] ?? '') ?: null;
        $faturaNo     = trim($k['fatura_no']     ?? '') ?: null;  // ettn
        $miktar       = (float)str_replace(',','.',($k['miktar'] ?? '0'));
        $tedarikciId  = ($k['tedarikci_id'] ?? '') !== '' ? (int)$k['tedarikci_id'] : null;
        $betonId      = ($k['beton_sinifi_id'] ?? '') !== '' ? (int)$k['beton_sinifi_id'] : null;
        $projeId      = ($k['proje_id'] ?? '') !== '' ? (int)$k['proje_id'] : null;
        $kivamId      = ($k['kivam_sinifi_id'] ?? '') !== '' ? (int)$k['kivam_sinifi_id'] : null;
        $kantarYildiz = ($k['kantar_net_yildizlar'] ?? '') !== '' ? (float)str_replace(',','.',$k['kantar_net_yildizlar']) : null;
        $kantarTed    = ($k['kantar_net_tedarikci'] ?? '') !== '' ? (float)str_replace(',','.',$k['kantar_net_tedarikci']) : null;
        $kantarFark   = ($kantarYildiz !== null && $kantarTed !== null) ? round($kantarYildiz - $kantarTed, 2) : null;
        $scanImageUrl = trim($k['docUrl'] ?? '') ?: (trim($k['scanImageUrl'] ?? '') ?: null);

        if (!$tarih) { $atlanan++; $hatalar[] = ($irsaliyeNo ?: '?') . ': Tarih eksik'; continue; }
        if ($miktar <= 0) $miktar = 0;

        if (!$tedarikciId) { $atlanan++; $hatalar[] = ($irsaliyeNo ?: '?') . ': Tedarikçi seçilmedi'; continue; }

        // Duplicate check — çakışma varsa atla değil, karşılaştırma için döndür
        if ($irsaliyeNo) {
            $dup = $pdo->prepare("
                SELECT i.id, i.irsaliye_no, i.tarih, i.arac_plaka, i.mikser_cikis_saati,
                       i.fatura_no, i.miktar, i.tedarikci_id, i.beton_sinifi_id,
                       i.kivam_sinifi_id, i.proje_id, i.kantar_net_yildizlar, i.kantar_net_tedarikci,
                       t.ad AS tedarikci_ad, bs.ad AS beton_sinifi_ad,
                       ks.ad AS kivam_ad, p.kod AS proje_kod
                FROM irsaliyeler i
                LEFT JOIN tedarikciler    t  ON t.id  = i.tedarikci_id
                LEFT JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id
                LEFT JOIN kivam_siniflari ks ON ks.id = i.kivam_sinifi_id
                LEFT JOIN projeler        p  ON p.id  = i.proje_id
                WHERE i.irsaliye_no = ? LIMIT 1
            ");
            $dup->execute([$irsaliyeNo]);
            $mevcut = $dup->fetch(PDO::FETCH_ASSOC);
            if ($mevcut) {
                $cakismalar[] = ['mevcut' => $mevcut, 'yeni' => $k];
                continue;
            }
        }

        $pdo->prepare("INSERT INTO irsaliyeler
            (tip, irsaliye_no, fatura_no, arac_plaka, tedarikci_id, tarih,
             mikser_cikis_saati, miktar, birim, beton_sinifi_id, proje_id,
             kivam_sinifi_id, kantar_net_yildizlar, kantar_net_tedarikci, kantar_farki, created_by,
             scan_image_url)
            VALUES ('alis',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$irsaliyeNo, $faturaNo, $aracPlaka, $tedarikciId, $tarih,
                       $mikserCikis, $miktar ?: 0, 'M3', $betonId, $projeId,
                       $kivamId, $kantarYildiz, $kantarTed, $kantarFark, $uid,
                       $scanImageUrl]);
        $eklenen++;
    } catch (PDOException $e) {
        $atlanan++;
        $hatalar[] = ($k['irsaliye_no'] ?? '?') . ': ' . $e->getMessage();
    }
}

echo json_encode(['ok'=>true, 'eklenen'=>$eklenen, 'atlanan'=>$atlanan, 'hatalar'=>$hatalar, 'cakismalar'=>$cakismalar]);
