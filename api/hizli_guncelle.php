<?php
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
if (!file_exists(dirname(__DIR__) . '/config.php')) { http_response_code(403); die('{}'); }
require_auth();
require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'msg'=>'POST gerekli']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
$id   = (int)($body['id']     ?? 0);
$k    = $body['alanlar']      ?? [];

if (!$id || !$k) { echo json_encode(['ok'=>false,'msg'=>'Geçersiz veri']); exit; }

// Yetki: düzenleme rolü + durum bazlı kontrol (onaylanmış irsaliye ezilemesin)
if (!can_edit()) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Bu işlem için yetkiniz yok.']); exit; }
$__mevcut = $pdo->prepare("SELECT durum FROM irsaliyeler WHERE id=?");
$__mevcut->execute([$id]);
$__row = $__mevcut->fetch();
if (!$__row) { echo json_encode(['ok'=>false,'msg'=>'Kayıt bulunamadı.']); exit; }
if (!can_edit_irsaliye($__row)) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Bu irsaliye mevcut durumunda ('.h($__row['durum']).') düzenlenemez.']); exit; }

try {
    $tarih       = trim($k['tarih']               ?? '');
    $aracPlaka   = strtoupper(trim($k['arac_plaka'] ?? '')) ?: null;
    $mikserCikis = trim($k['mikser_cikis_saati']  ?? '') ?: null;
    $faturaNo    = trim($k['fatura_no']           ?? '') ?: null;
    $miktar      = (float)str_replace(',', '.', ($k['miktar'] ?? '0'));
    $tedarikciId = ($k['tedarikci_id']    ?? '') !== '' ? (int)$k['tedarikci_id']    : null;
    $betonId     = ($k['beton_sinifi_id'] ?? '') !== '' ? (int)$k['beton_sinifi_id'] : null;
    $projeId     = ($k['proje_id']        ?? '') !== '' ? (int)$k['proje_id']        : null;
    $kivamId     = ($k['kivam_sinifi_id'] ?? '') !== '' ? (int)$k['kivam_sinifi_id'] : null;
    $kantarYild  = ($k['kantar_net_yildizlar']  ?? '') !== '' ? (float)str_replace(',', '.', $k['kantar_net_yildizlar'])  : null;
    $kantarTed   = ($k['kantar_net_tedarikci']  ?? '') !== '' ? (float)str_replace(',', '.', $k['kantar_net_tedarikci'])  : null;
    $kantarFark  = ($kantarYild !== null && $kantarTed !== null) ? round($kantarYild - $kantarTed, 2) : null;
    $scanImageUrl = trim($k['docUrl'] ?? '') ?: (trim($k['scanImageUrl'] ?? '') ?: null);

    if (!$tarih)       { echo json_encode(['ok'=>false,'msg'=>'Tarih eksik']);        exit; }
    if (!$tedarikciId) { echo json_encode(['ok'=>false,'msg'=>'Tedarikçi seçilmedi']); exit; }

    $pdo->prepare("UPDATE irsaliyeler SET
        tarih=?, arac_plaka=?, mikser_cikis_saati=?, fatura_no=?,
        miktar=?, tedarikci_id=?, beton_sinifi_id=?, kivam_sinifi_id=?,
        proje_id=?, kantar_net_yildizlar=?, kantar_net_tedarikci=?, kantar_farki=?,
        scan_image_url=?
        WHERE id=?")
        ->execute([$tarih, $aracPlaka, $mikserCikis, $faturaNo,
                   $miktar ?: 0, $tedarikciId, $betonId, $kivamId,
                   $projeId, $kantarYild, $kantarTed, $kantarFark,
                   $scanImageUrl, $id]);

    echo json_encode(['ok'=>true]);
} catch (PDOException $e) {
    echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
}
