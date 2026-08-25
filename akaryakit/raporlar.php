<?php
/** raporlar.php — Akaryakıt raporları (Chart.js + Excel dışa aktarma) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoAkaryakit->query("SELECT 1 FROM akaryakit_donemler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_akaryakit.php'); }

$donemler = ak_donemler($pdoAkaryakit); // en yeni önce
$guncel = $donemler[0] ?? null;
$topGelen=0; $topKull=0;
foreach ($donemler as $d) { $topGelen+=(float)$d['gelen']; $topKull+=(float)$d['kullanilan']; }
$aracSay = (int)$pdoAkaryakit->query("SELECT COUNT(*) FROM akaryakit_araclar WHERE aktif=1")->fetchColumn();

$seri = array_reverse($donemler);
// Firma bazlı toplam tüketim
$firma = $pdoAkaryakit->query("SELECT COALESCE(NULLIF(a.firma,''),'—') firma, SUM(t.aylik_tuketim) top
    FROM akaryakit_tuketim t JOIN akaryakit_araclar a ON a.id=t.arac_id GROUP BY firma ORDER BY top DESC")->fetchAll();
// Araç bazlı toplam tüketim
$araclar = $pdoAkaryakit->query("SELECT a.sofor, a.cinsi, a.firma, SUM(t.aylik_tuketim) top, COUNT(*) donem
    FROM akaryakit_tuketim t JOIN akaryakit_araclar a ON a.id=t.arac_id GROUP BY t.arac_id ORDER BY top DESC")->fetchAll();

$fmt0 = fn($n)=>number_format((float)$n,0,',','.');

// ── Excel dışa aktarma (araç bazlı toplam tüketim) ──
if (($_GET['disaaktar'] ?? '')==='xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new \XlsxWriter('Akaryakit Rapor');
    $xl->header(['Şoför','Cinsi','Firma','Dönem','Toplam Tüketim (Lt)']);
    foreach ($araclar as $a) {
        $xl->row([['v'=>$a['sofor'],'t'=>'text'],['v'=>$a['cinsi'],'t'=>'text'],['v'=>$a['firma']?:'—','t'=>'text'],
            ['v'=>(int)$a['donem'],'t'=>'number'],['v'=>(float)$a['top'],'t'=>'number']]);
    }
    $xl->total([['v'=>'TOPLAM','t'=>'text'],['v'=>'','t'=>'text'],['v'=>'','t'=>'text'],['v'=>'','t'=>'text'],['v'=>$topKull,'t'=>'number']]);
    $xl->download('akaryakit_rapor_'.date('Ymd_Hi').'.xlsx');
}

$pageTitle = 'Akaryakıt Raporları';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Akaryakıt Raporları</h4>
    <div class="d-flex gap-2">
        <button type="button" onclick="akExcel()" class="btn btn-success btn-sm" id="btnXls"><i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</button>
        <button type="button" onclick="akPdf('pdf')" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF İndir</button>
        <button type="button" onclick="akPdf('print')" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer me-1"></i>Yazdır</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-fuel-pump-fill me-1"></i>Güncel Stok</div><div class="h4 mb-0 fw-bold text-primary"><?= $fmt0($guncel['kalan']??0) ?> <small class="fs-6 text-muted">Lt</small></div><div class="small text-muted mt-1"><?= h($guncel['donem']??'') ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-box-arrow-in-down me-1"></i>Toplam Gelen</div><div class="h4 mb-0 fw-bold text-success"><?= $fmt0($topGelen) ?> <small class="fs-6 text-muted">Lt</small></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-speedometer me-1"></i>Toplam Tüketim</div><div class="h4 mb-0 fw-bold text-danger"><?= $fmt0($topKull) ?> <small class="fs-6 text-muted">Lt</small></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-truck me-1"></i>Araç / Makine</div><div class="h3 mb-0 fw-bold"><?= $aracSay ?></div><div class="small text-muted mt-1"><?= count($donemler) ?> dönem</div></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6 class="mb-3"><i class="bi bi-graph-up text-primary me-2"></i>Aylık Tüketim & Kalan Stok</h6>
        <canvas id="chAylik" height="110"></canvas>
    </div></div></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6 class="mb-3"><i class="bi bi-pie-chart text-primary me-2"></i>Firma Bazlı Tüketim</h6>
        <canvas id="chFirma" height="200"></canvas>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-7"><div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white fw-semibold">Dönem Stok Özeti</div>
        <div class="card-body p-0"><div class="table-responsive" style="max-height:52vh">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
                <thead class="table-light" style="position:sticky;top:0"><tr><th>Dönem</th><th class="text-end">Devir</th><th class="text-end">Gelen</th><th class="text-end">Kullanılan</th><th class="text-end">Kalan</th></tr></thead>
                <tbody>
                <?php foreach ($seri as $d): ?>
                    <tr><td class="fw-semibold"><?= h($d['donem']) ?></td>
                        <td class="text-end font-monospace"><?= $fmt0($d['devir']) ?></td>
                        <td class="text-end font-monospace text-success"><?= $fmt0($d['gelen']) ?></td>
                        <td class="text-end font-monospace text-danger"><?= $fmt0($d['kullanilan']) ?></td>
                        <td class="text-end font-monospace fw-bold text-primary"><?= $fmt0($d['kalan']) ?></td></tr>
                <?php endforeach; ?>
                <?php if(!$seri): ?><tr><td colspan="5" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div></div>
    <div class="col-lg-5"><div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-trophy text-warning me-2"></i>En Çok Tüketen Araçlar</div>
        <div class="card-body p-0"><div class="table-responsive" style="max-height:52vh">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
                <thead class="table-light" style="position:sticky;top:0"><tr><th>#</th><th>Şoför</th><th>Cinsi</th><th class="text-end">Tüketim (Lt)</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($araclar,0,15) as $ix=>$a): ?>
                    <tr><td class="text-muted"><?= $ix+1 ?></td><td class="fw-semibold small"><?= h($a['sofor']) ?></td>
                        <td class="small"><?= h($a['cinsi']) ?></td><td class="text-end font-monospace fw-bold"><?= $fmt0($a['top']) ?></td></tr>
                <?php endforeach; ?>
                <?php if(!$araclar): ?><tr><td colspan="4" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>window.ERN_ROOT = '../';</script>
<script src="../assets/js/ern_rapor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ern_rapor.js') ?>"></script>
<script>
// ── Rapor verisi (Excel + PDF ortak) ─────────────────────────────────────────
const AK_R = {
    kpi: { stok: <?= json_encode((float)($guncel['kalan'] ?? 0)) ?>, donem: <?= json_encode((string)($guncel['donem'] ?? '')) ?>,
           gelen: <?= json_encode(round($topGelen)) ?>, kullanilan: <?= json_encode(round($topKull)) ?>, arac: <?= (int)$aracSay ?> },
    donemler: <?= json_encode(array_map(fn($d)=>['donem'=>$d['donem'],'devir'=>round((float)$d['devir']),'gelen'=>round((float)$d['gelen']),
        'kullanilan'=>round((float)$d['kullanilan']),'kalan'=>round((float)$d['kalan'])], $seri), JSON_UNESCAPED_UNICODE) ?>,
    firma: <?= json_encode(array_map(fn($f)=>['firma'=>$f['firma'],'top'=>round((float)$f['top'])], $firma), JSON_UNESCAPED_UNICODE) ?>,
    araclar: <?= json_encode(array_map(fn($a)=>['sofor'=>$a['sofor'],'cinsi'=>$a['cinsi'],'firma'=>(string)$a['firma'],
        'donem'=>(int)$a['donem'],'top'=>round((float)$a['top'])], $araclar), JSON_UNESCAPED_UNICODE) ?>
};

function akPdf(mode){
    const f0 = n => Number(n).toLocaleString('tr-TR');
    const tbl = ERN_RAPOR.tbl;
    let html = '<div class="kpis"><div><b>'+f0(AK_R.kpi.stok)+' Lt</b>Güncel Stok ('+ERN_RAPOR.esc(AK_R.kpi.donem)+')</div>'
        + '<div><b>'+f0(AK_R.kpi.gelen)+' Lt</b>Toplam Gelen</div><div><b>'+f0(AK_R.kpi.kullanilan)+' Lt</b>Toplam Tüketim</div>'
        + '<div><b>'+AK_R.kpi.arac+'</b>Araç / Makine</div></div>'
        + '<h2>Dönem Zinciri (Devir + Gelen − Kullanılan = Kalan)</h2>'
        + tbl(['Dönem','Devir','Gelen','Kullanılan','Kalan'],
            AK_R.donemler.map(d=>[d.donem, f0(d.devir), f0(d.gelen), f0(d.kullanilan), f0(d.kalan)]))
        + '<h2>Firma Bazlı Toplam Tüketim</h2>'
        + tbl(['Firma','Tüketim (Lt)'], AK_R.firma.map(x=>[x.firma, f0(x.top)]))
        + '<h2>Araç / Makine Bazlı Toplam Tüketim</h2>'
        + tbl(['Şoför','Cinsi','Firma','Dönem','Tüketim (Lt)'],
            AK_R.araclar.map(a=>[a.sofor, a.cinsi, a.firma||'—', a.donem, f0(a.top)]));
    ERN_RAPOR.popup({title:'AKARYAKIT RAPORU', body:html, mode:mode, filename:'ERN_Akaryakit_Rapor'});
}

async function akExcel(){
    const btn = document.getElementById('btnXls');
    btn.disabled = true; const o = btn.innerHTML; btn.innerHTML = 'Hazırlanıyor...';
    try {
        const wb = await ERN_RAPOR.wb();

        let ws = wb.addWorksheet('Stok Zinciri');
        ERN_RAPOR.title(wb, ws, 'AKARYAKIT RAPORU — STOK ZİNCİRİ', 5,
            'Güncel stok: ' + AK_R.kpi.stok + ' Lt (' + AK_R.kpi.donem + ')');
        let h = ws.addRow(['Dönem','Devir (Lt)','Gelen (Lt)','Kullanılan (Lt)','Kalan (Lt)']); ERN_RAPOR.hdr(h);
        AK_R.donemler.forEach(d => ws.addRow([d.donem, d.devir, d.gelen, d.kullanilan, d.kalan]));
        ws.columns.forEach(c => c.width = 16); ws.getColumn(1).width = 20;

        ws = wb.addWorksheet('Firma');
        ERN_RAPOR.title(wb, ws, 'FİRMA BAZLI TÜKETİM', 2);
        h = ws.addRow(['Firma','Tüketim (Lt)']); ERN_RAPOR.hdr(h);
        AK_R.firma.forEach(x => ws.addRow([x.firma, x.top]));
        ws.getColumn(1).width = 28; ws.getColumn(2).width = 16;

        ws = wb.addWorksheet('Araçlar');
        ERN_RAPOR.title(wb, ws, 'ARAÇ / MAKİNE BAZLI TÜKETİM', 5);
        h = ws.addRow(['Şoför','Cinsi','Firma','Dönem Sayısı','Toplam Tüketim (Lt)']); ERN_RAPOR.hdr(h);
        AK_R.araclar.forEach(a => ws.addRow([a.sofor, a.cinsi, a.firma||'—', a.donem, a.top]));
        ws.columns.forEach(c => c.width = 18); ws.getColumn(2).width = 26;

        await ERN_RAPOR.save(wb, 'ERN_Akaryakit_Rapor_' + new Date().toISOString().slice(0,10) + '.xlsx');
    } catch (e) { alert('Excel oluşturulamadı: ' + e.message); }
    btn.disabled = false; btn.innerHTML = o;
}
</script>
<script>
(function(){
    const palette=['#00584E','#00C9B1','#C9A84C','#007A6A','#6f42c1','#0d6efd','#fd7e14','#20c997','#d63384','#6610f2','#198754','#adb5bd'];
    new Chart(document.getElementById('chAylik'),{
        data:{labels:<?= json_encode(array_map(fn($d)=>$d['donem'],$seri),JSON_UNESCAPED_UNICODE) ?>,datasets:[
            {type:'bar',label:'Tüketim (Lt)',data:<?= json_encode(array_map(fn($d)=>(float)$d['kullanilan'],$seri)) ?>,backgroundColor:'#00584E',borderRadius:4},
            {type:'line',label:'Kalan Stok (Lt)',data:<?= json_encode(array_map(fn($d)=>(float)$d['kalan'],$seri)) ?>,borderColor:'#C9A84C',backgroundColor:'rgba(201,168,76,.15)',tension:.3,fill:true}
        ]},
        options:{plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true}}}
    });
    new Chart(document.getElementById('chFirma'),{
        type:'doughnut',
        data:{labels:<?= json_encode(array_column($firma,'firma'),JSON_UNESCAPED_UNICODE) ?>,datasets:[{data:<?= json_encode(array_map(fn($f)=>(float)$f['top'],$firma)) ?>,backgroundColor:palette,borderWidth:0}]},
        options:{plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}},cutout:'60%'}
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
