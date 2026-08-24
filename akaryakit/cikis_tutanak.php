<?php
/**
 * cikis_tutanak.php — AKARYAKIT ÇIKIŞ FİŞİ (A4, ERN Taahhüt logolu)
 *
 * Düzen, sahada kullanılan basılı fişin (EYS.ABR.01.FR.05) birebir kopyasıdır:
 * Projesi satırı · Tarih/Ambar No/Fiş No · makina adı/plaka/yakıt cinsi/verilen
 * miktar · şirket-kiralık-taşeron kutucukları · kilometre-ç.saati-firma adı ·
 * Teslim Eden / Teslim Alan imza blokları · 2 nüsha dipnotu.
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

$no   = 'AKY-C-' . str_pad((string)$c['id'], 5, '0', STR_PAD_LEFT);
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');
$teslimAlan = $c['teslim_alan'] ?: $c['sofor'];
$tipi = $c['arac_tipi'] ?? null;
// Sayaç: plakalı araçta KİLOMETRE, plakasız makinede Ç.SAATİ satırına yazılır
$km = ''; $csaat = '';
if (trim((string)$c['sayac']) !== '') {
    if (trim((string)$c['plaka']) !== '') $km = $c['sayac']; else $csaat = $c['sayac'];
}
$kutu = fn(bool $isaretli) => '<span class="kutu">' . ($isaretli ? '✕' : '&nbsp;') . '</span>';
?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title>Akaryakıt Çıkış Fişi <?= h($no) ?></title>
<style>
  * { box-sizing:border-box; }
  body { font-family:'Segoe UI', Arial, sans-serif; color:#111; margin:0; background:#f0f0f0; }
  .sheet { width:210mm; margin:10px auto; background:#fff; padding:14mm 14mm 10mm; box-shadow:0 0 8px rgba(0,0,0,.15); }
  .logo-satir { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
  .fis { border:2px solid #333; }
  .fis table { width:100%; border-collapse:collapse; font-size:12.5px; }
  .fis td { border:1px solid #555; padding:7px 9px; vertical-align:middle; }
  .fis td.k { font-weight:700; width:34%; background:#fafafa; }
  .fis td.deger { font-size:14px; font-weight:600; }
  .baslik { text-align:center; font-weight:800; font-size:15px; letter-spacing:.5px; padding:9px !important; }
  .ust-blok td { border:none; padding:5px 9px; }
  .proje-satir { border-bottom:1px dotted #555; min-width:230px; display:inline-block; font-weight:600; }
  .sag-etiket { font-size:11.5px; white-space:nowrap; }
  .sag-etiket strong { font-weight:700; }
  .kutu { display:inline-block; width:18px; height:18px; border:1.5px solid #333; text-align:center;
          line-height:16px; font-weight:800; font-size:14px; vertical-align:middle; }
  .imza-alan { height:74px; vertical-align:bottom !important; }
  .imza-baslik { font-weight:700; text-align:center; }
  .imza-alt { font-size:10.5px; color:#555; text-align:center; }
  .dipnot { font-size:10px; color:#444; padding:6px 9px !important; border-top:2px solid #333 !important; }
  .toolbar { text-align:center; padding:10px; }
  .toolbar button, .toolbar a { font:inherit; padding:8px 18px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; margin:0 4px; }
  .btn-print { background:#00584E; color:#fff; } .btn-back { background:#e0e0e0; color:#333; }
  @media print { body { background:#fff; } .sheet { margin:0; box-shadow:none; width:auto; padding:10mm; } .toolbar { display:none; } }
</style>
</head>
<body>
<div class="toolbar">
  <button class="btn-print" onclick="window.print()">🖨 Yazdır / PDF Kaydet</button>
  <a class="btn-back" href="cikislar.php">← Geri</a>
</div>

<div class="sheet">
  <div class="logo-satir">
    <div>
      <img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:42px"
           onerror="this.outerHTML='<b style=\'color:#00584E;font-size:20px\'>ERN TAAHHÜT</b>'">
    </div>
    <div style="font-size:10px;color:#777">Fiş No: <strong style="color:#00584E"><?= h($no) ?></strong></div>
  </div>

  <div class="fis">
    <table>
      <!-- Başlık -->
      <tr><td colspan="4" class="baslik">AKARYAKIT ÇIKIŞ FİŞİ</td></tr>

      <!-- Projesi + Tarih/Ambar/Fiş No -->
      <tr>
        <td colspan="2" style="border-right:none">
          <span class="proje-satir"><?= h($c['aciklama'] ?: '') ?>&nbsp;</span>
          <span style="font-size:11px;color:#555">&nbsp;Projesi</span>
        </td>
        <td colspan="2" style="border-left:none;text-align:right">
          <div class="sag-etiket">Tarih : <strong><?= format_date($c['tarih']) ?></strong></div>
          <div class="sag-etiket">Ambar No : ..............</div>
          <div class="sag-etiket">Fiş No : <strong><?= h($no) ?></strong></div>
        </td>
      </tr>

      <!-- Araç bilgileri -->
      <tr><td class="k">MAKİNA / ARACIN ADI</td><td colspan="3" class="deger"><?= h($c['cinsi'] ?: '') ?><?= $c['sofor'] ? ' <span style="font-weight:400;font-size:11.5px;color:#555">(Şoför/Operatör: '.h($c['sofor']).')</span>' : '' ?></td></tr>
      <tr><td class="k">MAKİNA / ARACIN PLAKASI/SERİ NO</td><td colspan="3" class="deger"><?= h($c['plaka'] ?: '') ?></td></tr>
      <tr><td class="k">YAKIT CİNSİ</td><td colspan="3" class="deger">MAZOT (MOTORİN)</td></tr>
      <tr><td class="k">VERİLEN MİKTAR (LT/KG)</td><td colspan="3" class="deger" style="font-size:17px;font-weight:800"><?= $fmt0($c['miktar_lt']) ?> Lt</td></tr>

      <!-- Kutucuklar + kilometre/saat/firma -->
      <tr>
        <td class="k">ŞİRKET MAKİNA/ARACI</td><td style="text-align:center;width:12%"><?= $kutu($tipi==='sirket') ?></td>
        <td class="k" style="width:20%">KİLOMETRE</td><td class="deger"><?= h($km) ?></td>
      </tr>
      <tr>
        <td class="k">KİRALIK MAKİNA/ARAÇ</td><td style="text-align:center"><?= $kutu($tipi==='kiralik') ?></td>
        <td class="k">Ç.SAATİ</td><td class="deger"><?= h($csaat) ?></td>
      </tr>
      <tr>
        <td class="k">TAŞERON MAKİNA/ARAÇ</td><td style="text-align:center"><?= $kutu($tipi==='taseron') ?></td>
        <td class="k">FİRMA ADI</td><td class="deger"><?= h($c['firma'] ?: '') ?></td>
      </tr>

      <!-- İmzalar -->
      <tr>
        <td colspan="2" class="imza-alan">
          <div class="imza-baslik">Teslim Eden</div>
          <div class="imza-alt">Ad-Soyad-İmza</div>
          <div style="text-align:center;margin-top:26px;font-weight:600"><?= h($c['teslim_eden'] ?: '') ?></div>
        </td>
        <td colspan="2" class="imza-alan">
          <div class="imza-baslik">Teslim Alan</div>
          <div class="imza-alt">Ad-Soyad-İmza</div>
          <div style="text-align:center;margin-top:26px;font-weight:600"><?= h($teslimAlan ?: '') ?></div>
        </td>
      </tr>

      <!-- Dipnot -->
      <tr><td colspan="4" class="dipnot">*Bu form 2 nüsha hazırlanır, biri depo sorumlusunda kalır, biri muhasebeye teslim edilir. (EYS.ABR.01.FR.05)</td></tr>
    </table>
  </div>

  <div style="margin-top:8px;text-align:center;font-size:10px;color:#999">
    ERN Taahhüt Akaryakıt Takip Sistemi — <?= date('d.m.Y H:i') ?>
  </div>
</div>
</body>
</html>
