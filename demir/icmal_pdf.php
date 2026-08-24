<?php
/**
 * demir/icmal_pdf.php — Demir İcmal yazdırılabilir (A4) görünüm.
 * icmal.php ile aynı filtreleri (proje, tarih) kullanır.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$fProje = isset($_GET['proje']) && ctype_digit($_GET['proje']) ? (int)$_GET['proje'] : 0;
$tBas   = isset($_GET['tarih_bas']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tarih_bas']) ? $_GET['tarih_bas'] : '';
$tBit   = isset($_GET['tarih_bit']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tarih_bit']) ? $_GET['tarih_bit'] : '';

$where = []; $params = [];
if ($fProje) { $where[] = 's.proje_id = ?';       $params[] = $fProje; }
if ($tBas)   { $where[] = 's.irsaliye_tarih >= ?'; $params[] = $tBas; }
if ($tBit)   { $where[] = 's.irsaliye_tarih <= ?'; $params[] = $tBit; }
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$cap = $pdoDemir->prepare("
    SELECT c.ad, c.tip, COALESCE(agg.irs,0) AS irs, COALESCE(agg.knt,0) AS knt
    FROM demir_caplar c
    LEFT JOIN (SELECT sk.cap_id, SUM(sk.irsaliye_miktar) irs, SUM(sk.kantar_miktar) knt
               FROM demir_sevkiyat_kalemleri sk JOIN demir_sevkiyatlar s ON s.id=sk.sevkiyat_id $whereSQL
               GROUP BY sk.cap_id) agg ON agg.cap_id=c.id
    GROUP BY c.id ORDER BY c.sira, c.ad");
$cap->execute($params);
$capRows = array_filter($cap->fetchAll(), fn($r)=>$r['irs']>0 || $r['knt']>0);

$ted = $pdoDemir->prepare("
    SELECT t.ad AS firma, COALESCE(SUM(sk.irsaliye_miktar),0) irs, COALESCE(SUM(sk.kantar_miktar),0) knt
    FROM demir_sevkiyatlar s JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id=s.id
    LEFT JOIN demir_tedarikciler t ON t.id=s.tedarikci_id $whereSQL
    GROUP BY s.tedarikci_id ORDER BY irs DESC");
$ted->execute($params);
$tedRows = $ted->fetchAll();

$totIrs = array_sum(array_column($capRows,'irs'));
$totKnt = array_sum(array_column($capRows,'knt'));
$totFark = $totKnt - $totIrs;

$projeAd = 'Tümü';
if ($fProje) { $p = $pdoDemir->prepare("SELECT kod,aciklama FROM demir_projeler WHERE id=?"); $p->execute([$fProje]); if($pr=$p->fetch()) $projeAd = $pr['kod'].' — '.$pr['aciklama']; }
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Demir İcmal</title>
<style>
  *{box-sizing:border-box;} body{font-family:'Segoe UI',Arial,sans-serif;color:#111;margin:0;background:#f0f0f0;}
  .sheet{width:210mm;min-height:297mm;margin:10px auto;background:#fff;padding:16mm;box-shadow:0 0 8px rgba(0,0,0,.15);}
  .top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #00584E;padding-bottom:10px;}
  .logo{font-size:22px;font-weight:800;color:#00584E;}.logo small{display:block;font-size:11px;font-weight:600;color:#555;letter-spacing:2px;}
  .doc-title{text-align:center;margin:16px 0 4px;font-size:17px;font-weight:800;}
  .meta{text-align:center;font-size:12px;color:#555;margin-bottom:16px;}
  .grid{display:flex;gap:16px;}.col{flex:1;}
  h3{font-size:13px;color:#00584E;border-bottom:1px solid #ddd;padding-bottom:4px;margin:0 0 6px;}
  table{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:14px;}
  th,td{border:1px solid #bbb;padding:4px 6px;}th{background:#00584E;color:#fff;}
  td.r,th.r{text-align:right;}tfoot td{background:#f5f7f7;font-weight:700;}
  .cards{display:flex;gap:10px;margin-bottom:16px;}
  .kpi{flex:1;border:1px solid #ddd;border-radius:8px;padding:8px 10px;}.kpi .l{font-size:10px;color:#777;}.kpi .v{font-size:16px;font-weight:800;}
  .foot{margin-top:20px;text-align:center;font-size:10px;color:#999;border-top:1px solid #eee;padding-top:8px;}
  .toolbar{text-align:center;padding:10px;}.toolbar button,.toolbar a{font:inherit;padding:8px 18px;border-radius:8px;border:none;cursor:pointer;text-decoration:none;margin:0 4px;}
  .btn-print{background:#00584E;color:#fff;}.btn-back{background:#e0e0e0;color:#333;}
  @media print{body{background:#fff;}.sheet{margin:0;box-shadow:none;width:auto;padding:10mm;}.toolbar{display:none;}}
</style></head><body>
<div class="toolbar"><button class="btn-print" onclick="window.print()">🖨 Yazdır / PDF Kaydet</button><a class="btn-back" href="icmal.php">← Geri</a></div>
<div class="sheet">
  <div class="top"><div><img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:46px" onerror="this.outerHTML='<div class=\\'logo\\'>ERN TAAHHÜT</div>'"><div style="font-size:10px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">DEMİR TAKİP</div></div>
    <div style="text-align:right;font-size:11px;color:#555">Yazdırma: <strong><?= date('d.m.Y H:i') ?></strong></div></div>
  <div class="doc-title">DEMİR İCMAL — GELEN DEMİR MUTABAKATI</div>
  <div class="meta">Proje: <strong><?= h($projeAd) ?></strong><?php if($tBas||$tBit): ?> &nbsp;|&nbsp; Tarih: <?= h($tBas?:'…') ?> – <?= h($tBit?:'…') ?><?php endif; ?></div>

  <div class="cards">
    <div class="kpi"><div class="l">Toplam İrsaliye</div><div class="v"><?= $fmt($totIrs) ?> t</div></div>
    <div class="kpi"><div class="l">Toplam Kantar</div><div class="v"><?= $fmt($totKnt) ?> t</div></div>
    <div class="kpi"><div class="l">Kantar Farkı</div><div class="v"><?= ($totFark>0?'+':'').$fmt($totFark) ?> t</div></div>
  </div>

  <div class="grid">
    <div class="col">
      <h3>Çap Bazında</h3>
      <table><thead><tr><th>Çap</th><th class="r">İrsaliye</th><th class="r">Kantar</th><th class="r">Fark</th></tr></thead><tbody>
        <?php foreach($capRows as $r): $f=(float)$r['knt']-(float)$r['irs']; ?>
        <tr><td><?= h($r['ad']) ?></td><td class="r"><?= $fmt($r['irs']) ?></td><td class="r"><?= $fmt($r['knt']) ?></td><td class="r"><?= ($f>0?'+':'').$fmt($f) ?></td></tr>
        <?php endforeach; ?>
      </tbody><tfoot><tr><td>TOPLAM</td><td class="r"><?= $fmt($totIrs) ?></td><td class="r"><?= $fmt($totKnt) ?></td><td class="r"><?= ($totFark>0?'+':'').$fmt($totFark) ?></td></tr></tfoot></table>
    </div>
    <div class="col">
      <h3>Tedarikçi Bazında</h3>
      <table><thead><tr><th>Tedarikçi</th><th class="r">İrsaliye</th><th class="r">Kantar</th><th class="r">Fark</th></tr></thead><tbody>
        <?php foreach($tedRows as $r): $f=(float)$r['knt']-(float)$r['irs']; ?>
        <tr><td><?= h($r['firma'] ?: '(tanımsız)') ?></td><td class="r"><?= $fmt($r['irs']) ?></td><td class="r"><?= $fmt($r['knt']) ?></td><td class="r"><?= ($f>0?'+':'').$fmt($f) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
  <div class="foot">ERN Taahhüt Demir Takip Sistemi</div>
</div></body></html>
