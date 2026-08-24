<?php
/**
 * demir/iade_pdf.php — İade tutanağının yazdırılabilir (A4) görünümü.
 * Tarayıcıdan Yazdır → "PDF olarak kaydet" ile PDF alınır (ek bağımlılık yok).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/_iade_ortak.php';
iade_semasi_kur($pdoDemir);

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { redirect('iade_tutanaklar.php'); }

$s = $pdoDemir->prepare("
    SELECT iu.*, ie.ad AS iade_eden_adi, ie.vkn AS iade_eden_vkn, ta.ad AS teslim_alan_adi,
           p.kod AS proje_kod, p.aciklama AS proje_ad
    FROM demir_iade_tutanaklar iu
    LEFT JOIN demir_taseronlar ie ON ie.id = iu.iade_eden_id
    LEFT JOIN demir_taseronlar ta ON ta.id = iu.teslim_alan_id
    LEFT JOIN demir_projeler p ON p.id = iu.proje_id
    WHERE iu.id=?");
$s->execute([$id]);
$iu = $s->fetch();
if (!$iu) { die('İade tutanağı bulunamadı.'); }

$kalemler = $pdoDemir->prepare("
    SELECT ik.*, c.ad AS cap_ad FROM demir_iade_kalemleri ik
    LEFT JOIN demir_caplar c ON c.id = ik.cap_id WHERE ik.iade_id=? ORDER BY ik.id");
$kalemler->execute([$id]);
$kalemler = $kalemler->fetchAll();
$topTon = array_sum(array_column($kalemler,'miktar_ton'));
$topBag = array_sum(array_column($kalemler,'bag_adeti'));
$teslimAlanAd = $iu['teslim_alan_adi'] ?: 'ERN Taahhüt (Depo / Şirket)';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title>İade <?= h($iu['iade_no']) ?></title>
<style>
  * { box-sizing:border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color:#111; margin:0; background:#f0f0f0; }
  .sheet { width:210mm; min-height:297mm; margin:10px auto; background:#fff; padding:18mm 16mm; box-shadow:0 0 8px rgba(0,0,0,.15); }
  .top { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #00584E; padding-bottom:10px; }
  .top .logo { font-size:22px; font-weight:800; color:#00584E; letter-spacing:-.5px; }
  .top .logo small { display:block; font-size:11px; font-weight:600; color:#555; letter-spacing:2px; }
  .doc-title { text-align:center; margin:18px 0 6px; font-size:18px; font-weight:800; letter-spacing:1px; }
  .doc-no { text-align:center; font-size:13px; color:#00584E; font-weight:700; margin-bottom:16px; }
  .info { width:100%; border-collapse:collapse; margin-bottom:14px; font-size:12.5px; }
  .info td { border:1px solid #cfcfcf; padding:6px 9px; }
  .info td.k { background:#f5f7f7; font-weight:600; width:26%; }
  table.items { width:100%; border-collapse:collapse; font-size:12px; margin-top:4px; }
  table.items th, table.items td { border:1px solid #bbb; padding:6px 8px; }
  table.items th { background:#00584E; color:#fff; font-weight:600; }
  table.items td.r, table.items th.r { text-align:right; }
  table.items tfoot td { background:#f5f7f7; font-weight:700; }
  .note { font-size:11px; color:#444; margin:14px 0; line-height:1.5; }
  .signs { display:flex; justify-content:space-between; margin-top:40px; }
  .sign { width:45%; text-align:center; }
  .sign .line { border-top:1px solid #333; margin-top:52px; padding-top:6px; font-size:12px; font-weight:600; }
  .sign .sub { font-size:11px; color:#666; }
  .foot { margin-top:24px; text-align:center; font-size:10px; color:#999; border-top:1px solid #eee; padding-top:8px; }
  .toolbar { text-align:center; padding:10px; }
  .toolbar button, .toolbar a { font:inherit; padding:8px 18px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; margin:0 4px; }
  .btn-print { background:#00584E; color:#fff; }
  .btn-back { background:#e0e0e0; color:#333; }
  @media print { body { background:#fff; } .sheet { margin:0; box-shadow:none; width:auto; padding:12mm; } .toolbar { display:none; } }
</style>
</head>
<body>
<div class="toolbar">
  <button class="btn-print" onclick="window.print()">🖨 Yazdır / PDF Kaydet</button>
  <a class="btn-back" href="iade_detay.php?id=<?= $id ?>">← Geri</a>
</div>

<div class="sheet">
  <div class="top">
    <div><img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:46px" onerror="this.outerHTML='<div class=\\'logo\\'>ERN TAAHHÜT</div>'"><div style="font-size:10px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">DEMİR TAKİP</div></div>
    <div style="text-align:right;font-size:11px;color:#555">
      Tarih: <strong><?= format_date($iu['iade_tarih']) ?></strong><br>
      <?php if($iu['proje_kod']): ?>Proje: <strong><?= h($iu['proje_kod']) ?></strong><?php endif; ?>
    </div>
  </div>

  <div class="doc-title">MALZEME İADE TUTANAĞI</div>
  <div class="doc-no">İade No: <?= h($iu['iade_no']) ?></div>

  <table class="info">
    <tr><td class="k">İade Eden Firma</td><td><?= h($iu['iade_eden_adi'] ?: '—') ?></td>
        <td class="k">VKN</td><td><?= h($iu['iade_eden_vkn'] ?: '—') ?></td></tr>
    <tr><td class="k">Teslim Alan</td><td><?= h($teslimAlanAd) ?></td>
        <td class="k">İade Tarihi</td><td><?= format_date($iu['iade_tarih']) ?></td></tr>
    <tr><td class="k">Proje</td><td><?= h(($iu['proje_kod']??'—').($iu['proje_ad']?' — '.$iu['proje_ad']:'')) ?></td>
        <td class="k">Araç / Dorse</td><td><?= h(($iu['arac_plaka']?:'—').' / '.($iu['dorse_plaka']?:'—')) ?></td></tr>
  </table>

  <?php if ($iu['aciklama']): ?>
  <div class="note"><strong>Açıklama:</strong> <?= nl2br(h($iu['aciklama'])) ?></div>
  <?php endif; ?>

  <table class="items">
    <thead><tr><th style="width:36px">S.No</th><th>Özellik / Çap</th><th class="r" style="width:90px">Bağ Adeti</th><th class="r" style="width:120px">Tonaj (t)</th></tr></thead>
    <tbody>
      <?php foreach ($kalemler as $i=>$k): ?>
      <tr><td><?= $i+1 ?></td><td><?= h($k['cap_ad'] ?: '—') ?></td><td class="r"><?= $k['bag_adeti']!==null?(int)$k['bag_adeti']:'—' ?></td><td class="r"><?= $fmt($k['miktar_ton']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$kalemler): ?><tr><td colspan="4" style="text-align:center;color:#999">Kalem yok.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td colspan="2" class="r">TOPLAM</td><td class="r"><?= (int)$topBag ?></td><td class="r"><?= $fmt($topTon) ?></td></tr></tfoot>
  </table>

  <div class="note">
    Yukarıda cinsi, miktarı ve özellikleri belirtilen malzemeler <strong><?= h($iu['iade_eden_adi'] ?: '') ?></strong> tarafından
    iş bitiminde iade edilmiş ve <strong><?= h($teslimAlanAd) ?></strong> tarafından eksiksiz teslim alınmıştır.
    İşbu tutanak taraflarca imza altına alınmıştır.
  </div>

  <div class="signs">
    <div class="sign"><div class="line">İade Eden</div><div class="sub"><?= h($iu['iade_eden_adi'] ?: '') ?></div></div>
    <div class="sign"><div class="line">Teslim Alan</div><div class="sub"><?= h($teslimAlanAd) ?></div></div>
  </div>

  <div class="foot">ERN Taahhüt Demir Takip Sistemi — <?= date('d.m.Y H:i') ?></div>
</div>
</body></html>
