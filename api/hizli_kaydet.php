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
$eklenen = 0; $atlanan = 0; $hatalar = [];

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

        if (!$tarih) { $atlanan++; $hatalar[] = ($irsaliyeNo ?: '?') . ': Tarih eksik'; continue; }
        if ($miktar <= 0) $miktar = 0;

        // Tedarikçi zorunlu değilse, null bırak — ama INSERT NOT NULL hatası verebilir
        // tedarikci_id NOT NULL ise en azından dummy bir değer gerekli,
        // bu yüzden tedarikciId yoksa atlıyoruz
        if (!$tedarikciId) { $atlanan++; $hatalar[] = ($irsaliyeNo ?: '?') . ': Tedarikçi seçilmedi'; continue; }

        // Duplicate check
        if ($irsaliyeNo) {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE irsaliye_no=?");
            $dup->execute([$irsaliyeNo]);
            if ($dup->fetchColumn() > 0) { $atlanan++; $hatalar[] = $irsaliyeNo . ': Zaten mevcut'; continue; }
        }

        $pdo->prepare("INSERT INTO irsaliyeler
            (tip, irsaliye_no, fatura_no, arac_plaka, tedarikci_id, tarih,
             mikser_cikis_saati, miktar, birim, beton_sinifi_id, proje_id,
             kivam_sinifi_id, kantar_net_yildizlar, kantar_net_tedarikci, kantar_farki, created_by)
            VALUES ('alis',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$irsaliyeNo, $faturaNo, $aracPlaka, $tedarikciId, $tarih,
                       $mikserCikis, $miktar ?: 0, 'M3', $betonId, $projeId,
                       $kivamId, $kantarYildiz, $kantarTed, $kantarFark, $uid]);
        $eklenen++;
    } catch (PDOException $e) {
        $atlanan++;
        $hatalar[] = ($k['irsaliye_no'] ?? '?') . ': ' . $e->getMessage();
    }
}

echo json_encode(['ok'=>true, 'eklenen'=>$eklenen, 'atlanan'=>$atlanan, 'hatalar'=>$hatalar]);
