<?php
/**
 * hareket_tutanak.php — Depo giriş/çıkış hareketi için A4 yazdırılabilir tutanak
 *
 * Giriş → MALZEME TESLİM ALMA TUTANAĞI (firma → ERN Taahhüt depo)
 * Çıkış → MALZEME TESLİM TUTANAĞI      (ERN Taahhüt depo → firma/taşeron)
 *
 * ERN Taahhüt, ERN Holding'in inşaat koludur — saha evrakları Taahhüt adına düzenlenir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

dp_hareket_semasi_kur($pdoDepo);

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdoDepo->prepare("SELECT * FROM depo_hareketler WHERE id=?");
$st->execute([$id]);
$hr = $st->fetch();
if (!$hr) { flash('error', 'Hareket bulunamadı.'); redirect('hareketler.php'); }

$g   = $hr['tur'] === 'giris';
$no  = 'DEP-' . ($g ? 'G' : 'C') . '-' . str_pad((string)$hr['id'], 5, '0', STR_PAD_LEFT);
$fmt = fn($n) => number_format((float)$n, 2, ',', '.');
$baslik = !empty($hr['hurda']) ? 'MALZEME HURDAYA AYIRMA TUTANAĞI'
        : ($g ? 'MALZEME TESLİM ALMA TUTANAĞI' : 'MALZEME TESLİM TUTANAĞI');
?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title>Tutanak <?= h($no) ?></title>
<style>
  * { box-sizing:border-box; }
  body { font-family:'Segoe UI', Arial, sans-serif; color:#111; margin:0; background:#f0f0f0; }
  .sheet { width:210mm; min-height:297mm; margin:10px auto; background:#fff; padding:18mm 16mm; box-shadow:0 0 8px rgba(0,0,0,.15); }
  .top { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #00584E; padding-bottom:10px; }
  .logo { font-size:22px; font-weight:800; color:#00584E; letter-spacing:-.5px; }
  .doc-title { text-align:center; margin:18px 0 6px; font-size:18px; font-weight:800; letter-spacing:1px; }
  .doc-no { text-align:center; font-size:13px; color:#00584E; font-weight:700; margin-bottom:16px; }
  .info { width:100%; border-collapse:collapse; margin-bottom:14px; font-size:12.5px; }
  .info td { border:1px solid #cfcfcf; padding:6px 9px; }
  .info td.k { background:#f5f7f7; font-weight:600; width:26%; }
  table.items { width:100%; border-collapse:collapse; font-size:12px; margin-top:4px; }
  table.items th, table.items td { border:1px solid #bbb; padding:6px 8px; }
  table.items th { background:#00584E; color:#fff; font-weight:600; }
  table.items td.r, table.items th.r { text-align:right; }
  .note { font-size:11px; color:#444; margin:14px 0; line-height:1.5; }
  .signs { display:flex; justify-content:space-between; margin-top:44px; }
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
  <a class="btn-back" href="hareketler.php">← Geri</a>
</div>

<div class="sheet">
  <div class="top">
    <div>
      <img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:46px"
           onerror="this.outerHTML='<div class=\'logo\'>ERN TAAHHÜT</div>'">
      <div style="font-size:10px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">DEPO TAKİP</div>
    </div>
    <div style="text-align:right;font-size:11px;color:#555">
      Tarih: <strong><?= format_date($hr['tarih']) ?></strong><br>
      <?= h(dp_kaynakAd($hr['kaynak'])) ?>
    </div>
  </div>

  <div class="doc-title"><?= h($baslik) ?></div>
  <div class="doc-no">Tutanak No: <?= h($no) ?></div>

  <table class="info">
    <tr><td class="k"><?= $g ? 'Gönderen Firma' : 'Teslim Edilen Firma / Taşeron' ?></td><td><?= h($hr['firma'] ?: '—') ?></td>
        <td class="k">Tarih</td><td><?= format_date($hr['tarih']) ?></td></tr>
    <tr><td class="k"><?= $g ? 'İrsaliye No' : 'Fiş No' ?></td><td><?= h($hr['belge_no'] ?: '—') ?></td>
        <td class="k">Belge Tarihi</td><td><?= $hr['belge_tarihi'] ? format_date($hr['belge_tarihi']) : '—' ?></td></tr>
    <tr><td class="k">Teslim Alan</td><td><?= h($hr['teslim_alan'] ?: '—') ?></td>
        <td class="k">Onaylayan</td><td><?= h($hr['onay'] ?: '—') ?></td></tr>
    <tr><td class="k">Lokasyon</td><td><?= h($hr['lokasyon'] ?: '—') ?></td>
        <td class="k">Açıklama</td><td><?= h($hr['aciklama'] ?: '—') ?></td></tr>
  </table>

  <table class="items">
    <thead><tr><th style="width:36px">S.No</th><th>Malzeme Adı ve Tanımı</th><th>Özellik / Marka</th>
               <th class="r" style="width:100px">Miktar</th><th style="width:70px">Birim</th></tr></thead>
    <tbody>
      <tr><td>1</td><td><?= h($hr['malzeme']) ?></td><td><?= h($hr['ozellik'] ?: '—') ?></td>
          <td class="r"><?= $fmt($hr['miktar']) ?></td><td><?= h($hr['birim'] ?: 'Adet') ?></td></tr>
    </tbody>
  </table>

  <div class="note">
    Yukarıda cinsi, miktarı ve özellikleri belirtilen malzeme<?= !empty($hr['hurda'])
        ? ' kullanım dışı kalması nedeniyle HURDAYA AYRILMIŞTIR'
        : ($g ? ' eksiksiz ve hasarsız olarak teslim alınmıştır'
              : ', belirtilen firmaya/kişiye eksiksiz olarak teslim edilmiştir') ?>.
    İşbu tutanak iki nüsha düzenlenmiş olup taraflarca imza altına alınmıştır.
  </div>

  <div class="signs">
    <div class="sign"><div class="line">Teslim Eden</div>
        <div class="sub"><?= $g ? h($hr['firma'] ?: '') : 'ERN Taahhüt (Depo)' ?></div></div>
    <div class="sign"><div class="line">Teslim Alan</div>
        <div class="sub"><?= $g ? 'ERN Taahhüt (Depo)' : h(trim(($hr['firma'] ?: '') . ($hr['teslim_alan'] ? ' — ' . $hr['teslim_alan'] : ''))) ?></div></div>
  </div>

  <div class="foot">ERN Taahhüt Depo Takip Sistemi — <?= date('d.m.Y H:i') ?></div>
</div>
</body>
</html>
