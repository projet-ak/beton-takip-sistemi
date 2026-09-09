<?php
/**
 * it/raporlar.php — IT Envanter raporları
 * Kategori × durum matrisi, departman/lokasyon/marka kırılımları, mali değer, garanti durumu,
 * yaş dağılımı (alış tarihine göre), hareket trendi. Çıktılar ERN_RAPOR (Excel / PDF / Yazdır).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';

it_semasi_kur($pdoIt);
$pageTitle = 'IT Envanter Raporları';
$o = it_ozet($pdoIt);

// Kategori × durum matrisi
$matris = [];
foreach ($pdoIt->query("SELECT kategori, durum, COUNT(*) adet, COALESCE(SUM(fiyat),0) mali FROM it_cihazlar GROUP BY kategori, durum") as $r) {
    $k = $r['kategori'];
    $matris[$k] ??= ['ad'=>it_kategoriAd($k), 'toplam'=>0, 'mali'=>0.0] + array_fill_keys(array_keys(IT_DURUM), 0);
    $matris[$k][$r['durum']] = (int)$r['adet'];
    if ($r['durum'] !== 'hurda') { $matris[$k]['toplam'] += (int)$r['adet']; $matris[$k]['mali'] += (float)$r['mali']; }
}
uasort($matris, fn($a, $b) => $b['toplam'] <=> $a['toplam']);
$matris = array_values($matris);

$kirilim = function (string $sutun) use ($pdoIt): array {
    return $pdoIt->query("SELECT COALESCE(NULLIF($sutun,''),'(tanımsız)') ad, COUNT(*) adet, SUM(durum='aktif') aktif,
                                 COALESCE(SUM(fiyat),0) mali
                          FROM it_cihazlar WHERE durum<>'hurda' GROUP BY $sutun ORDER BY adet DESC LIMIT 40")->fetchAll();
};
$dep = $kirilim('departman'); $lok = $kirilim('lokasyon'); $marka = $kirilim('marka');

// Yaş dağılımı (alış tarihine göre)
$yas = $pdoIt->query("SELECT
        SUM(alis_tarihi IS NULL) bilinmiyor,
        SUM(alis_tarihi >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) y1,
        SUM(alis_tarihi < DATE_SUB(CURDATE(), INTERVAL 1 YEAR) AND alis_tarihi >= DATE_SUB(CURDATE(), INTERVAL 3 YEAR)) y3,
        SUM(alis_tarihi < DATE_SUB(CURDATE(), INTERVAL 3 YEAR) AND alis_tarihi >= DATE_SUB(CURDATE(), INTERVAL 5 YEAR)) y5,
        SUM(alis_tarihi < DATE_SUB(CURDATE(), INTERVAL 5 YEAR)) y5p
    FROM it_cihazlar WHERE durum<>'hurda'")->fetch() ?: [];
$garanti = $pdoIt->query("SELECT
        SUM(garanti_bitis IS NULL) yok,
        SUM(garanti_bitis < CURDATE()) bitti,
        SUM(garanti_bitis BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)) bitiyor,
        SUM(garanti_bitis > DATE_ADD(CURDATE(), INTERVAL 60 DAY)) devam
    FROM it_cihazlar WHERE durum<>'hurda'")->fetch() ?: [];

// Aylık hareket trendi (son 12 ay)
$aylik = [];
foreach ($pdoIt->query("SELECT DATE_FORMAT(tarih,'%Y-%m') ay, SUM(tur='giris') giris, SUM(tur='zimmet') zimmet, SUM(tur='iade') iade,
                               SUM(tur IN ('servis','ariza')) sorun, SUM(tur='hurda') hurda
                        FROM it_hareketler WHERE tarih >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ay ORDER BY ay") as $r) $aylik[$r['ay']] = $r;
$seri = [];
for ($i = 11; $i >= 0; $i--) { $ay = date('Y-m', strtotime("-$i month")); $seri[] = ['ay'=>$ay] + array_map('intval', ($aylik[$ay] ?? []) + ['giris'=>0,'zimmet'=>0,'iade'=>0,'sorun'=>0,'hurda'=>0]); }

// En değerli cihazlar
$degerli = $pdoIt->query("SELECT envanter_no, ad, kategori, zimmetli, fiyat FROM it_cihazlar WHERE durum<>'hurda' AND fiyat IS NOT NULL ORDER BY fiyat DESC LIMIT 20")->fetchAll();

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>IT Envanter Raporları</h4>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-success btn-sm" onclick="itExcel()"><i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</button>
        <button class="btn btn-outline-danger btn-sm" onclick="itPdf('pdf')"><i class="bi bi-file-earmark-pdf me-1"></i>PDF İndir</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="itPdf('print')"><i class="bi bi-printer me-1"></i>Yazdır</button>
    </div>
</div>

<div class="row g-2 mb-3">
  <?php foreach ([['Cihaz (hurda hariç)', $f0($o['toplam'] - $o['hurda'])], ['Kullanımda', $f0($o['aktif'])], ['Depoda', $f0($o['depoda'])],
                  ['Serviste / Arızalı', $f0($o['serviste'] + $o['arizali'])], ['Hurda', $f0($o['hurda'])], ['Mali değer', $f2($o['mali']) . ' TL']] as [$e, $d]): ?>
  <div class="col-6 col-md-2"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted"><?= $e ?></div><div class="fs-5 fw-bold"><?= $d ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Aylık hareket trendi</strong> <span class="small text-muted">(son 12 ay)</span></div>
    <div class="card-body"><div style="height:280px"><canvas id="chAy"></canvas></div></div></div></div>
  <div class="col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Yaş dağılımı</strong></div>
    <div class="card-body"><div style="height:280px"><canvas id="chYas"></canvas></div></div></div></div>
  <div class="col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Garanti durumu</strong></div>
    <div class="card-body"><div style="height:280px"><canvas id="chGar"></canvas></div></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white"><strong><i class="bi bi-grid-3x3 me-1"></i>Kategori × Durum</strong></div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.85rem">
    <thead class="table-light"><tr><th>Kategori</th><?php foreach (IT_DURUM as $d => [$ad]): ?><th class="text-end"><?= h($ad) ?></th><?php endforeach; ?><th class="text-end">Toplam*</th><th class="text-end">Mali değer (TL)</th></tr></thead>
    <tbody>
    <?php foreach ($matris as $m): ?>
      <tr><td><?= h($m['ad']) ?></td><?php foreach (array_keys(IT_DURUM) as $d): ?><td class="text-end"><?= $m[$d] ?: '<span class="text-muted">—</span>' ?></td><?php endforeach; ?>
          <td class="text-end fw-semibold"><?= $f0($m['toplam']) ?></td><td class="text-end"><?= $f2($m['mali']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$matris): ?><tr><td colspan="8" class="text-center text-muted py-3">Kayıt yok.</td></tr><?php endif; ?>
    </tbody></table></div>
  <div class="card-footer bg-white small text-muted">* Toplam ve mali değer hurdalar hariçtir.</div>
</div>

<?php
$tablo = function (string $baslik, string $ikon, array $l, string $ilk) use ($f0, $f2) {
    if (!$l) return;
    echo '<div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong><i class="bi ' . $ikon . ' me-1"></i>' . h($baslik) . '</strong></div>';
    echo '<div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.84rem"><thead class="table-light"><tr><th>' . h($ilk) . '</th><th class="text-end">Cihaz</th><th class="text-end">Kullanımda</th><th class="text-end">Mali (TL)</th></tr></thead><tbody>';
    foreach ($l as $r) echo '<tr><td>' . h($r['ad']) . '</td><td class="text-end">' . $f0($r['adet']) . '</td><td class="text-end">' . $f0($r['aktif']) . '</td><td class="text-end">' . $f2($r['mali']) . '</td></tr>';
    echo '</tbody></table></div></div></div>';
};
?>
<div class="row g-3 mb-3">
  <?php $tablo('Departman bazında', 'bi-diagram-3', $dep, 'Departman'); $tablo('Lokasyon bazında', 'bi-geo-alt', $lok, 'Lokasyon'); $tablo('Marka bazında', 'bi-tag', $marka, 'Marka'); ?>
</div>

<?php if ($degerli): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white"><strong><i class="bi bi-gem me-1"></i>En değerli cihazlar</strong></div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.84rem">
    <thead class="table-light"><tr><th>Envanter No</th><th>Cihaz</th><th>Kategori</th><th>Zimmetli</th><th class="text-end">Fiyat (TL)</th></tr></thead>
    <tbody><?php foreach ($degerli as $r): ?><tr><td class="font-monospace"><?= h($r['envanter_no']) ?></td><td><?= h($r['ad']) ?></td><td><?= h(it_kategoriAd($r['kategori'])) ?></td><td><?= h($r['zimmetli'] ?: '—') ?></td><td class="text-end"><?= $f2($r['fiyat']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>window.ERN_ROOT = '../';</script>
<script src="../assets/js/ern_rapor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ern_rapor.js') ?>"></script>
<script>
const IT = {
    kpi: <?= json_encode(['cihaz'=>$o['toplam']-$o['hurda'],'aktif'=>$o['aktif'],'depoda'=>$o['depoda'],'sorun'=>$o['serviste']+$o['arizali'],'hurda'=>$o['hurda'],'mali'=>$o['mali'],'kisi'=>$o['zimmetliKisi'],'garantiBitiyor'=>$o['garantiBitiyor']]) ?>,
    durumlar: <?= json_encode(array_map(fn($x) => $x[0], IT_DURUM), JSON_UNESCAPED_UNICODE) ?>,
    matris: <?= json_encode($matris, JSON_UNESCAPED_UNICODE) ?>,
    dep: <?= json_encode($dep, JSON_UNESCAPED_UNICODE) ?>, lok: <?= json_encode($lok, JSON_UNESCAPED_UNICODE) ?>, marka: <?= json_encode($marka, JSON_UNESCAPED_UNICODE) ?>,
    yas: <?= json_encode(array_map('intval', $yas)) ?>, garanti: <?= json_encode(array_map('intval', $garanti)) ?>,
    seri: <?= json_encode($seri) ?>, degerli: <?= json_encode($degerli, JSON_UNESCAPED_UNICODE) ?>
};
const f0 = n => Number(n || 0).toLocaleString('tr-TR');
const f2 = n => Number(n || 0).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
const ayEt = a => { const [y,m] = a.split('-'); return ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'][+m-1] + ' ' + y.slice(2); };

new Chart(document.getElementById('chAy'), { type:'bar',
  data:{ labels: IT.seri.map(s=>ayEt(s.ay)), datasets:[
    {label:'Giriş', data:IT.seri.map(s=>s.giris), backgroundColor:'#0d6efd'},
    {label:'Zimmet', data:IT.seri.map(s=>s.zimmet), backgroundColor:'#198754'},
    {label:'İade', data:IT.seri.map(s=>s.iade), backgroundColor:'#6c757d'},
    {label:'Servis / Arıza', data:IT.seri.map(s=>s.sorun), backgroundColor:'#dc3545'},
    {label:'Hurda', data:IT.seri.map(s=>s.hurda), backgroundColor:'#343a40'} ]},
  options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } }, scales:{ x:{ stacked:true }, y:{ stacked:true, beginAtZero:true, ticks:{ precision:0 } } } } });
new Chart(document.getElementById('chYas'), { type:'doughnut',
  data:{ labels:['< 1 yıl','1–3 yıl','3–5 yıl','5+ yıl','tarih yok'], datasets:[{ data:[IT.yas.y1, IT.yas.y3, IT.yas.y5, IT.yas.y5p, IT.yas.bilinmiyor], backgroundColor:['#198754','#00C9B1','#ffc107','#dc3545','#adb5bd'] }] },
  options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{ boxWidth:12, font:{size:11} } } } } });
new Chart(document.getElementById('chGar'), { type:'doughnut',
  data:{ labels:['Devam ediyor','60 günde bitiyor','Bitti','Tanımsız'], datasets:[{ data:[IT.garanti.devam, IT.garanti.bitiyor, IT.garanti.bitti, IT.garanti.yok], backgroundColor:['#198754','#ffc107','#dc3545','#adb5bd'] }] },
  options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{ boxWidth:12, font:{size:11} } } } } });

function itPdf(mode){
    const tbl = ERN_RAPOR.tbl;
    const kir = (b, l, ilk) => l.length ? '<h2>' + b + '</h2>' + tbl([ilk,'Cihaz','Kullanımda','Mali değer (TL)'], l.map(r => [r.ad, f0(r.adet), f0(r.aktif), f2(r.mali)])) : '';
    const html = '<div class="kpis">'
        + '<div><b>' + f0(IT.kpi.cihaz) + '</b>Cihaz</div><div><b>' + f0(IT.kpi.aktif) + '</b>Kullanımda</div>'
        + '<div><b>' + f0(IT.kpi.depoda) + '</b>Depoda</div><div><b>' + f0(IT.kpi.sorun) + '</b>Serviste / Arızalı</div>'
        + '<div><b>' + f2(IT.kpi.mali) + ' TL</b>Mali Değer</div></div>'
        + '<h2>Kategori × Durum</h2>' + tbl(['Kategori', ...IT.durumlar, 'Toplam', 'Mali (TL)'],
            IT.matris.map(m => [m.ad, ...Object.keys(IT.durumlar).map(d => f0(m[d])), f0(m.toplam), f2(m.mali)]))
        + kir('Departman Bazında', IT.dep, 'Departman') + kir('Lokasyon Bazında', IT.lok, 'Lokasyon') + kir('Marka Bazında', IT.marka, 'Marka')
        + (IT.degerli.length ? '<h2>En Değerli Cihazlar</h2>' + tbl(['Envanter No','Cihaz','Zimmetli','Fiyat (TL)'], IT.degerli.map(r => [r.envanter_no, r.ad, r.zimmetli || '—', f2(r.fiyat)])) : '');
    ERN_RAPOR.popup({title:'IT ENVANTER RAPORU', body:html, mode:mode, filename:'ERN_IT_Envanter'});
}
async function itExcel(){
    const wb = await ERN_RAPOR.wb();
    let ws = wb.addWorksheet('Özet'); ws.columns = [{width:34},{width:22}];
    ERN_RAPOR.title(wb, ws, 'IT ENVANTER — ÖZET', 2); ERN_RAPOR.hdr(ws.addRow(['Gösterge','Değer']));
    [['Cihaz (hurda hariç)', IT.kpi.cihaz], ['Kullanımda', IT.kpi.aktif], ['Depoda', IT.kpi.depoda], ['Serviste / Arızalı', IT.kpi.sorun],
     ['Hurda', IT.kpi.hurda], ['Zimmetli kişi', IT.kpi.kisi], ['Garantisi 60 günde bitecek', IT.kpi.garantiBitiyor], ['Mali değer (TL)', IT.kpi.mali]].forEach(r => ws.addRow(r));
    ws = wb.addWorksheet('Kategori x Durum'); ws.columns = [{width:26}, ...Object.keys(IT.durumlar).map(()=>({width:14})), {width:10}, {width:16}];
    const dk = Object.keys(IT.durumlar);
    ERN_RAPOR.title(wb, ws, 'KATEGORİ × DURUM', dk.length + 3); ERN_RAPOR.hdr(ws.addRow(['Kategori', ...dk.map(d => IT.durumlar[d]), 'Toplam', 'Mali (TL)']));
    IT.matris.forEach(m => ws.addRow([m.ad, ...dk.map(d => +m[d]), +m.toplam, +m.mali]));
    const kir = (adi, baslik, l, ilk) => { if (!l.length) return; const w = wb.addWorksheet(adi); w.columns = [{width:34},{width:10},{width:12},{width:16}];
        ERN_RAPOR.title(wb, w, baslik, 4); ERN_RAPOR.hdr(w.addRow([ilk,'Cihaz','Kullanımda','Mali (TL)'])); l.forEach(r => w.addRow([r.ad, +r.adet, +r.aktif, +r.mali])); };
    kir('Departman', 'DEPARTMAN BAZINDA', IT.dep, 'Departman'); kir('Lokasyon', 'LOKASYON BAZINDA', IT.lok, 'Lokasyon'); kir('Marka', 'MARKA BAZINDA', IT.marka, 'Marka');
    ws = wb.addWorksheet('Aylık Hareket'); ws.columns = [{width:12},{width:10},{width:10},{width:10},{width:16},{width:10}];
    ERN_RAPOR.title(wb, ws, 'AYLIK HAREKET TRENDİ', 6); ERN_RAPOR.hdr(ws.addRow(['Ay','Giriş','Zimmet','İade','Servis / Arıza','Hurda']));
    IT.seri.forEach(s => ws.addRow([ayEt(s.ay), s.giris, s.zimmet, s.iade, s.sorun, s.hurda]));
    if (IT.degerli.length) { ws = wb.addWorksheet('En Değerli'); ws.columns = [{width:14},{width:36},{width:22},{width:24},{width:16}];
        ERN_RAPOR.title(wb, ws, 'EN DEĞERLİ CİHAZLAR', 5); ERN_RAPOR.hdr(ws.addRow(['Envanter No','Cihaz','Kategori','Zimmetli','Fiyat (TL)']));
        IT.degerli.forEach(r => ws.addRow([r.envanter_no, r.ad, r.kategori, r.zimmetli || '', +r.fiyat])); }
    await ERN_RAPOR.save(wb, 'ERN_IT_Envanter_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
