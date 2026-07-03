<?php
/**
 * demir/tutanak_pdf.php — Tutanağın yazdırılabilir (A4) görünümü.
 * Tarayıcıdan Yazdır → "PDF olarak kaydet" ile PDF alınır (ek bağımlılık yok).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { redirect('tutanaklar.php'); }

$s = $pdoDemir->prepare("
    SELECT tu.*, ta.ad AS taseron_adi, ta.vkn AS taseron_vkn, p.kod AS proje_kod, p.aciklama AS proje_ad
    FROM demir_tutanaklar tu
    LEFT JOIN demir_taseronlar ta ON ta.id = tu.taseron_id
    LEFT JOIN demir_projeler p ON p.id = tu.proje_id
    WHERE tu.id=?");
$s->execute([$id]);
$tu = $s->fetch();
if (!$tu) { die('Tutanak bulunamadı.'); }

$kalemler = $pdoDemir->prepare("
    SELECT tk.*, c.ad AS cap_ad FROM demir_tutanak_kalemleri tk
    LEFT JOIN demir_caplar c ON c.id = tk.cap_id WHERE tk.tutanak_id=? ORDER BY tk.id");
$kalemler->execute([$id]);
$kalemler = $kalemler->fetchAll();
$topTon = array_sum(array_column($kalemler,'miktar_ton'));
$topBag = array_sum(array_column($kalemler,'bag_adeti'));
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title>Tutanak <?= h($tu['tutanak_no']) ?></title>
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
  <a class="btn-back" href="tutanak_detay.php?id=<?= $id ?>">← Geri</a>
</div>

<div class="sheet">
  <div class="top">
    <div class="logo">ERN HOLDİNG<small>DEMİR TAKİP</small></div>
    <div style="text-align:right;font-size:11px;color:#555">
      Tarih: <strong><?= format_date($tu['tutanak_tarih']) ?></strong><br>
      <?php if($tu['proje_kod']): ?>Proje: <strong><?= h($tu['proje_kod']) ?></strong><?php endif; ?>
    </div>
  </div>

  <div class="doc-title">MALZEME TESLİM TUTANAĞI</div>
  <div class="doc-no">Tutanak No: <?= h($tu['tutanak_no']) ?></div>

  <table class="info">
    <tr><td class="k">Zimmet Yapılan Firma</td><td><?= h($tu['taseron_adi'] ?: '—') ?></td>
        <td class="k">VKN</td><td><?= h($tu['taseron_vkn'] ?: '—') ?></td></tr>
    <tr><td class="k">Proje</td><td><?= h(($tu['proje_kod']??'—').($tu['proje_ad']?' — '.$tu['proje_ad']:'')) ?></td>
        <td class="k">Tutanak Tarihi</td><td><?= format_date($tu['tutanak_tarih']) ?></td></tr>
    <tr><td class="k">Araç Plaka</td><td><?= h($tu['arac_plaka'] ?: '—') ?></td>
        <td class="k">Dorse Plaka</td><td><?= h($tu['dorse_plaka'] ?: '—') ?></td></tr>
  </table>

  <?php if ($tu['sozlesme_kapsami']): ?>
  <div class="note"><strong>Sözleşme Kapsamı:</strong> <?= nl2br(h($tu['sozlesme_kapsami'])) ?></div>
  <?php endif; ?>

  <table class="items">
    <thead><tr><th style="width:36px">S.No</th><th>İrsaliye No</th><th>Özellik / Çap</th><th class="r" style="width:90px">Bağ Adeti</th><th class="r" style="width:110px">Tonaj (t)</th></tr></thead>
    <tbody>
      <?php foreach ($kalemler as $i=>$k): ?>
      <tr><td><?= $i+1 ?></td><td><?= h($k['irsaliye_no'] ?: '—') ?></td><td><?= h($k['cap_ad'] ?: '—') ?></td><td class="r"><?= $k['bag_adeti']!==null?(int)$k['bag_adeti']:'—' ?></td><td class="r"><?= $fmt($k['miktar_ton']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$kalemler): ?><tr><td colspan="5" style="text-align:center;color:#999">Kalem yok.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td colspan="3" class="r">TOPLAM</td><td class="r"><?= (int)$topBag ?></td><td class="r"><?= $fmt($topTon) ?></td></tr></tfoot>
  </table>

  <div class="note">
    Yukarıda cinsi, miktarı ve özellikleri belirtilen malzemeler eksiksiz olarak teslim alınmıştır.
    İşbu tutanak taraflarca imza altına alınmıştır.
  </div>

  <div class="signs">
    <div class="sign"><div class="line">Teslim Eden</div><div class="sub">ERN Holding</div></div>
    <div class="sign"><div class="line">Teslim Alan</div><div class="sub"><?= h($tu['taseron_adi'] ?: '') ?></div></div>
  </div>

  <div class="foot">ERN Holding Demir Takip Sistemi — <?= date('d.m.Y H:i') ?></div>
</div>

<script>window.addEventListener('load',function(){ /* Otomatik yazdırma isteğe bağlı: window.print(); */ });</script>
</body></html>
