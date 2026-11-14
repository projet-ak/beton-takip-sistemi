<?php
/**
 * kamp/raporlar.php — Kamp raporları
 *  • Son 12 ay: ay ay giren / çıkan / kişi-gece / ay sonu kalan (kamp_icmal ile — icmal ekranıyla birebir)
 *  • Firma × ay kişi-gece matrisi (firmaya yansıtma / hak ediş kesintisi için)
 *  • Bina × kategori anlık doluluk · kalış süresi dağılımı
 *  ERN_RAPOR ile Excel / PDF / Yazdır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$TR_AY = ['','Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
$aylar = [];
for ($i = 11; $i >= 0; $i--) $aylar[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
$trend = []; $matris = []; $firmaAd = [];
foreach ($aylar as $ay) {
    $ic = kamp_icmal($pdoKamp, $ay);
    $trend[$ay] = $ic['toplam'];
    foreach ($ic['firma'] as $f) { $matris[$f['firma_id']][$ay] = $f['gece']; $firmaAd[$f['firma_id']] = $f['firma']; }
}
uksort($matris, fn($a, $b) => array_sum($matris[$b]) <=> array_sum($matris[$a]));
$ayEt = fn($ay) => $TR_AY[(int)substr($ay, 5, 2)] . ' ' . substr($ay, 2, 2);

// Bina × kategori (şu an)
$bk = []; $katlar = [];
foreach ($pdoKamp->query("SELECT b.ad AS bina, p.kategori, COUNT(*) n FROM kamp_konaklama k JOIN kamp_kisiler p ON p.id=k.kisi_id
                            LEFT JOIN kamp_odalar o ON o.id=k.oda_id LEFT JOIN kamp_binalar b ON b.id=o.bina_id
                           WHERE k.cikis IS NULL GROUP BY b.ad, p.kategori") as $r) { $bk[$r['bina'] ?? '—'][$r['kategori']] = (int)$r['n']; $katlar[$r['kategori']] = true; }
$doluluk = kamp_doluluk($pdoKamp);

// Kalış süresi dağılımı (kapanmış + açık, kişi bazında değil konaklama bazında — taşımalar birleşik sayılmaz)
$dilim = ['0-7 gece' => 0, '8-30 gece' => 0, '31-90 gece' => 0, '91-180 gece' => 0, '180+ gece' => 0];
$topG = 0; $adet = 0;
foreach ($pdoKamp->query("SELECT giris, cikis FROM kamp_konaklama WHERE cikis IS NULL OR cikis_sebep IS NULL OR cikis_sebep NOT LIKE 'Yatak değişikliği%'") as $r) {
    $g = kamp_sure($r['giris'], $r['cikis']); $topG += $g; $adet++;
    $dilim[$g <= 7 ? '0-7 gece' : ($g <= 30 ? '8-30 gece' : ($g <= 90 ? '31-90 gece' : ($g <= 180 ? '91-180 gece' : '180+ gece')))]++;
}

$pageTitle = 'Raporlar — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Kamp Raporları</h4>
  <span class="text-muted small">son 12 ay</span>
  <div class="ms-auto d-flex gap-2">
    <button class="btn btn-outline-success btn-sm" onclick="rpExcel()"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
    <button class="btn btn-outline-danger btn-sm" onclick="rpPdf('pdf')"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
    <button class="btn btn-outline-secondary btn-sm" onclick="rpPdf('print')"><i class="bi bi-printer me-1"></i>Yazdır</button>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Aylık giriş / çıkış ve kişi-gece</strong></div>
    <div class="card-body"><div style="height:280px"><canvas id="chAy"></canvas></div></div></div></div>
  <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Kalış süresi</strong>
      <span class="text-muted small ms-1">ort. <?= $adet ? round($topG / $adet) : 0 ?> gece</span></div>
    <div class="card-body"><div style="height:280px"><canvas id="chSure"></canvas></div></div></div></div>

  <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-header bg-white"><strong>Firma × ay — kişi-gece</strong></div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.82rem">
      <thead class="table-light"><tr><th>Firma</th><?php foreach ($aylar as $ay): ?><th class="text-end"><a href="icmal.php?ay=<?= $ay ?>" class="text-reset"><?= h($ayEt($ay)) ?></a></th><?php endforeach; ?><th class="text-end">Toplam</th></tr></thead><tbody>
      <?php if (!$matris): ?><tr><td colspan="14" class="text-center text-muted py-3">Kayıt yok.</td></tr><?php endif; ?>
      <?php foreach ($matris as $fid => $s): ?><tr><td class="fw-semibold"><?= h($firmaAd[$fid]) ?></td>
        <?php foreach ($aylar as $ay): ?><td class="text-end"><?= !empty($s[$ay]) ? number_format($s[$ay], 0, ',', '.') : '<span class="text-muted">·</span>' ?></td><?php endforeach; ?>
        <td class="text-end fw-bold"><?= number_format(array_sum($s), 0, ',', '.') ?></td></tr><?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold"><tr><td>TOPLAM</td><?php foreach ($aylar as $ay): ?><td class="text-end"><?= number_format($trend[$ay]['gece'], 0, ',', '.') ?></td><?php endforeach; ?>
        <td class="text-end"><?= number_format(array_sum(array_column($trend, 'gece')), 0, ',', '.') ?></td></tr></tfoot>
    </table></div></div></div>

  <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Bina doluluğu (şu an)</strong></div>
    <div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.85rem">
      <thead class="table-light"><tr><th>Bina</th><th class="text-end">Oda</th><th class="text-end">Yatak</th><th class="text-end">Dolu</th><th class="text-end">Boş</th><th class="text-end">%</th></tr></thead><tbody>
      <?php foreach ($doluluk as $d): ?><tr><td><?= h($d['ad']) ?></td><td class="text-end"><?= $d['oda'] ?></td><td class="text-end"><?= $d['yatak'] ?></td><td class="text-end"><?= $d['dolu'] ?></td><td class="text-end"><?= $d['bos'] ?></td>
        <td class="text-end"><?= $d['yatak'] ? round($d['dolu'] * 100 / $d['yatak']) : 0 ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div></div>
  <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Bina × kategori (şu an kampta)</strong></div>
    <div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.85rem">
      <thead class="table-light"><tr><th>Bina</th><?php foreach (KAMP_KATEGORI as $k => [$ad]) if (isset($katlar[$k])): ?><th class="text-end"><?= h($ad) ?></th><?php endif; ?><th class="text-end">Toplam</th></tr></thead><tbody>
      <?php if (!$bk): ?><tr><td colspan="6" class="text-center text-muted py-3">Kampta kimse yok.</td></tr><?php endif; ?>
      <?php foreach ($bk as $b => $s): ?><tr><td><?= h($b) ?></td><?php foreach (KAMP_KATEGORI as $k => $_) if (isset($katlar[$k])): ?><td class="text-end"><?= $s[$k] ?? '' ?></td><?php endif; ?><td class="text-end fw-bold"><?= array_sum($s) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div></div>
</div>

<script>window.ERN_ROOT = '../';</script>
<script src="../assets/js/ern_rapor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ern_rapor.js') ?>"></script>
<script>
var RP = <?= json_encode([
    'ay' => array_map($ayEt, $aylar),
    'trend' => array_values(array_map(fn($t) => [$t['giren'], $t['cikan'], $t['gece'], $t['kalan']], $trend)),
    'firma' => array_values(array_map(fn($fid) => array_merge([$firmaAd[$fid]], array_map(fn($ay) => (int)($matris[$fid][$ay] ?? 0), $aylar), [array_sum($matris[$fid])]), array_keys($matris))),
    'bina' => array_values(array_map(fn($d) => [$d['ad'], $d['oda'], $d['yatak'], $d['dolu'], $d['bos']], $doluluk)),
    'dilim' => $dilim,
], JSON_UNESCAPED_UNICODE) ?>;
(function(){
  if (typeof Chart === 'undefined') return;
  var st = getComputedStyle(document.documentElement), soluk = (st.getPropertyValue('--bt-text-muted') || '#6c757d').trim();
  new Chart(document.getElementById('chAy'), { data:{ labels: RP.ay, datasets:[
      {type:'bar', label:'Giren', data: RP.trend.map(function(t){return t[0];}), backgroundColor:'rgba(25,135,84,.75)', yAxisID:'y'},
      {type:'bar', label:'Çıkan', data: RP.trend.map(function(t){return t[1];}), backgroundColor:'rgba(220,53,69,.7)', yAxisID:'y'},
      {type:'line', label:'Kişi-gece', data: RP.trend.map(function(t){return t[2];}), borderColor:'#00584E', yAxisID:'y2', tension:.25}] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{labels:{color:soluk}}},
      scales:{ x:{ticks:{color:soluk}}, y:{beginAtZero:true, ticks:{color:soluk, precision:0}, title:{display:true,text:'kişi',color:soluk}},
               y2:{position:'right', beginAtZero:true, grid:{display:false}, ticks:{color:soluk}, title:{display:true,text:'kişi-gece',color:soluk}} } } });
  new Chart(document.getElementById('chSure'), { type:'bar', data:{ labels:Object.keys(RP.dilim), datasets:[{ data:Object.values(RP.dilim), backgroundColor:'rgba(13,110,253,.7)' }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ x:{ticks:{color:soluk}}, y:{beginAtZero:true, ticks:{color:soluk, precision:0}} } } });
})();
function rpPdf(mode){
  var h = '<h3>Aylık giriş / çıkış</h3>' + ERN_RAPOR.tbl(['Ay','Giren','Çıkan','Kişi-gece','Ay sonu kalan'], RP.trend.map(function(t,i){ return [RP.ay[i]].concat(t); }))
        + '<h3>Firma × ay kişi-gece</h3>' + ERN_RAPOR.tbl(['Firma'].concat(RP.ay, ['Toplam']), RP.firma)
        + '<h3>Bina doluluğu</h3>' + ERN_RAPOR.tbl(['Bina','Oda','Yatak','Dolu','Boş'], RP.bina);
  ERN_RAPOR.popup({title:'KAMP RAPORU — SON 12 AY', body:h, mode:mode, filename:'ERN_Kamp_Rapor'});
}
async function rpExcel(){
  var wb = await ERN_RAPOR.wb(), ws;
  ws = wb.addWorksheet('Aylık'); ERN_RAPOR.title(wb, ws, 'KAMP — AYLIK GİRİŞ / ÇIKIŞ', 5);
  ERN_RAPOR.hdr(ws.addRow(['Ay','Giren','Çıkan','Kişi-gece','Ay sonu kalan'])); RP.trend.forEach(function(t,i){ ws.addRow([RP.ay[i]].concat(t)); });
  ws = wb.addWorksheet('Firma x Ay'); ERN_RAPOR.title(wb, ws, 'KAMP — FİRMA × AY KİŞİ-GECE', RP.ay.length + 2);
  ERN_RAPOR.hdr(ws.addRow(['Firma'].concat(RP.ay, ['Toplam']))); RP.firma.forEach(function(r){ ws.addRow(r); });
  ws.getColumn(1).width = 36;
  ws = wb.addWorksheet('Bina'); ERN_RAPOR.title(wb, ws, 'KAMP — BİNA DOLULUĞU', 5);
  ERN_RAPOR.hdr(ws.addRow(['Bina','Oda','Yatak','Dolu','Boş'])); RP.bina.forEach(function(r){ ws.addRow(r); });
  await ERN_RAPOR.save(wb, 'ERN_Kamp_Rapor_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
