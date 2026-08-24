<?php
/**
 * cikis_tutanak.php — Mazot teslim tutanağı (A4, ERN Taahhüt logolu)
 * Bir çıkış kaydının imzalı evrakı: kim, hangi araca, kaç litre, kim teslim etti/aldı.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdoAkaryakit->prepare("SELECT * FROM akaryakit_cikislar WHERE id=?");
$st->execute([$id]);
$c = $st->fetch();
if (!$c) { flash('error', 'Çıkış kaydı bulunamadı.'); redirect('cikislar.php'); }

$no = 'AKY-C-' . str_pad((string)$c['id'], 5, '0', STR_PAD_LEFT);
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');
$teslimAlan = $c['teslim_alan'] ?: $c['sofor'];
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
  .logo { font-size:22px; font-weight:800; color:#00584E; }
  .doc-title { text-align:center; margin:18px 0 6px; font-size:18px; font-weight:800; letter-spacing:1px; }
  .doc-no { text-align:center; font-size:13px; color:#00584E; font-weight:700; margin-bottom:16px; }
  .info { width:100%; border-collapse:collapse; margin-bottom:14px; font-size:12.5px; }
  .info td { border:1px solid #cfcfcf; padding:6px 9px; }
  .info td.k { background:#f5f7f7; font-weight:600; width:26%; }
  .miktar-kutu { text-align:center; border:2px solid #00584E; border-radius:8px; padding:14px; margin:18px auto; width:60%; }
  .miktar-kutu .deger { font-size:30px; font-weight:800; color:#00584E; }
  .miktar-kutu .etiket { font-size:11px; color:#555; letter-spacing:2px; }
  .note { font-size:11px; color:#444; margin:14px 0; line-height:1.5; }
  .signs { display:flex; justify-content:space-between; margin-top:44px; }
  .sign { width:45%; text-align:center; }
  .sign .line { border-top:1px solid #333; margin-top:52px; padding-top:6px; font-size:12px; font-weight:600; }
  .sign .sub { font-size:11px; color:#666; }
  .foot { margin-top:24px; text-align:center; font-size:10px; color:#999; border-top:1px solid #eee; padding-top:8px; }
  .toolbar { text-align:center; padding:10px; }
  .toolbar button, .toolbar a { font:inherit; padding:8px 18px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; margin:0 4px; }
  .btn-print { background:#00584E; color:#fff; } .btn-back { background:#e0e0e0; color:#333; }
  @media print { body { background:#fff; } .sheet { margin:0; box-shadow:none; width:auto; padding:12mm; } .toolbar { display:none; } }
</style>
</head>
<body>
<div class="toolbar">
  <button class="btn-print" onclick="window.print()">🖨 Yazdır / PDF Kaydet</button>
  <a class="btn-back" href="cikislar.php">← Geri</a>
</div>

<div class="sheet">
  <div class="top">
    <div>
      <img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:46px"
           onerror="this.outerHTML='<div class=\'logo\'>ERN TAAHHÜT</div>'">
      <div style="font-size:10px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">AKARYAKIT TAKİP</div>
    </div>
    <div style="text-align:right;font-size:11px;color:#555">
      Tarih: <strong><?= format_date($c['tarih']) ?></strong>
    </div>
  </div>

  <div class="doc-title">AKARYAKIT (MAZOT) TESLİM TUTANAĞI</div>
  <div class="doc-no">Tutanak No: <?= h($no) ?></div>

  <table class="info">
    <tr><td class="k">Şoför / Operatör</td><td><?= h($c['sofor'] ?: '—') ?></td>
        <td class="k">Araç / Makine Cinsi</td><td><?= h($c['cinsi'] ?: '—') ?></td></tr>
    <tr><td class="k">Firma</td><td><?= h($c['firma'] ?: '—') ?></td>
        <td class="k">Plaka / Mak. No</td><td><?= h($c['plaka'] ?: '—') ?></td></tr>
    <tr><td class="k">Sayaç (km / mak. saati)</td><td><?= h($c['sayac'] ?: '—') ?></td>
        <td class="k">Açıklama</td><td><?= h($c['aciklama'] ?: '—') ?></td></tr>
  </table>

  <div class="miktar-kutu">
    <div class="etiket">TESLİM EDİLEN MAZOT</div>
    <div class="deger"><?= $fmt0($c['miktar_lt']) ?> Lt</div>
  </div>

  <div class="note">
    Yukarıda belirtilen miktardaki motorin (mazot), belirtilen araca/makineye ikmal edilmek üzere eksiksiz olarak
    teslim edilmiştir. İşbu tutanak iki nüsha düzenlenmiş olup taraflarca imza altına alınmıştır.
  </div>

  <div class="signs">
    <div class="sign"><div class="line">Teslim Eden</div>
        <div class="sub"><?= h($c['teslim_eden'] ?: 'ERN Taahhüt (Akaryakıt Depo)') ?></div></div>
    <div class="sign"><div class="line">Teslim Alan</div>
        <div class="sub"><?= h($teslimAlan ?: '') ?><?= $c['firma'] ? ' — ' . h($c['firma']) : '' ?></div></div>
  </div>

  <div class="foot">ERN Taahhüt Akaryakıt Takip Sistemi — <?= date('d.m.Y H:i') ?></div>
</div>
</body>
</html>
