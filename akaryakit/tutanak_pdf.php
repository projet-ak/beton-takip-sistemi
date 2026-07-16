<?php
/** tutanak_pdf.php — Akaryakıt tutanağı A4 yazdırılabilir görünüm */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';

$sec = $_GET['donem'] ?? '';
$q=$pdoAkaryakit->prepare("SELECT * FROM akaryakit_tutanak WHERE donem=? ORDER BY sira, id");
$q->execute([$sec]); $liste=$q->fetchAll();
$top = array_sum(array_map(fn($r)=>(float)$r['miktar'],$liste));
$fmt0=fn($n)=>number_format((float)$n,0,',','.');
?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<title>Akaryakıt Tutanağı — <?= h($sec) ?></title>
<style>
    *{box-sizing:border-box;font-family:'Segoe UI',Arial,sans-serif}
    body{margin:0;padding:24px;color:#1a1a1a}
    .baslik{text-align:center;margin-bottom:6px;font-size:22px;font-weight:800;letter-spacing:6px}
    .altbaslik{text-align:center;margin-bottom:20px;font-size:13px;color:#444}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th,td{border:1px solid #333;padding:7px 9px}
    thead th{background:#00584E;color:#fff;text-align:left}
    td.num,th.num{text-align:right}
    tfoot td{font-weight:800;background:#f1f1f1}
    .imza{margin-top:60px;display:flex;justify-content:center}
    .imza-kutu{text-align:center;min-width:240px}
    .imza-cizgi{border-top:1.5px solid #333;padding-top:6px;font-weight:600}
    @media print{body{padding:0}.yazdir{display:none}}
    .yazdir{margin-bottom:16px;text-align:center}
    .yazdir button{background:#00584E;color:#fff;border:0;padding:9px 22px;border-radius:6px;font-size:14px;cursor:pointer}
</style></head><body>
<div class="yazdir"><button onclick="window.print()">🖨️ Yazdır / PDF Kaydet</button></div>
<div class="baslik">T U T A N A K</div>
<div class="altbaslik"><?= h(mb_strtoupper($sec,'UTF-8')) ?> AYINA AİT AKARYAKIT (MAZOT) TÜKETİM TUTANAĞIDIR.</div>
<table>
    <thead><tr><th style="width:60px">SIRA NO</th><th>ŞOFÖR ADI</th><th>ARAÇ DETAY</th><th>FİRMA DETAY</th><th class="num" style="width:110px">MİKTAR (Lt)</th></tr></thead>
    <tbody>
    <?php foreach($liste as $r): ?>
        <tr><td><?= (int)$r['sira'] ?></td><td><?= h($r['sofor']) ?></td><td><?= h($r['arac_detay']?:'') ?></td><td><?= h($r['firma_detay']?:'') ?></td><td class="num"><?= $fmt0($r['miktar']) ?></td></tr>
    <?php endforeach; ?>
    <?php if(!$liste): ?><tr><td colspan="5" style="text-align:center;color:#888">Kayıt yok.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td colspan="4" style="text-align:right">TOPLAM</td><td class="num"><?= $fmt0($top) ?></td></tr></tfoot>
</table>
<div class="imza"><div class="imza-kutu"><div style="margin-bottom:56px">ONAY</div><div class="imza-cizgi">PROJE MÜDÜRÜ</div></div></div>
</body></html>
