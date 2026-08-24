<?php
/** raporlar.php — Seramik raporları (Chart.js + Excel dışa aktarma) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_seramik.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoSeramik->query("SELECT 1 FROM seramik_malzemeler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_seramik.php'); }

// Malzeme bazlı stok (giren/çıkan/stok)
$stok = sr_stok($pdoSeramik);
// Tür bazlı özet
$turOzet = [];
foreach ($stok as $s) {
    $t = $s['tur'] ?: '—';
    if (!isset($turOzet[$t])) $turOzet[$t] = ['adet'=>0,'stok'=>0,'giren'=>0,'cikan'=>0];
    $turOzet[$t]['adet']++; $turOzet[$t]['stok']+=(float)$s['stok'];
    $turOzet[$t]['giren']+=(float)$s['giren']; $turOzet[$t]['cikan']+=(float)$s['cikan'];
}
arsort($turOzet);
$topStok = array_sum(array_map(fn($s)=>(float)$s['stok'],$stok));
$topGiren = array_sum(array_map(fn($s)=>(float)$s['giren'],$stok));
$topCikan = array_sum(array_map(fn($s)=>(float)$s['cikan'],$stok));

// Aylık hareket (tüm giriş/çıkış)
$aylik = [];
foreach ($pdoSeramik->query("SELECT DATE_FORMAT(gelis_tarihi,'%Y-%m') ay, SUM(miktar) t FROM seramik_giris WHERE gelis_tarihi IS NOT NULL GROUP BY ay") as $r) $aylik[$r['ay']]['g']=(float)$r['t'];
foreach ($pdoSeramik->query("SELECT DATE_FORMAT(cikis_tarihi,'%Y-%m') ay, SUM(miktar) t FROM seramik_cikis WHERE cikis_tarihi IS NOT NULL GROUP BY ay") as $r) $aylik[$r['ay']]['c']=(float)$r['t'];
ksort($aylik);

$fmt = fn($n)=>number_format((float)$n,2,',','.');

// ── Excel dışa aktarma ──
if (($_GET['disaaktar'] ?? '')==='xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new \XlsxWriter('Seramik Stok');
    $xl->header(['Malzeme','Tür','Birim','Giren','Çıkan','Stok']);
    foreach ($stok as $s) {
        $xl->row([['v'=>$s['ad'],'t'=>'text'],['v'=>$s['tur']?:'—','t'=>'text'],['v'=>$s['birim'],'t'=>'text'],
            ['v'=>(float)$s['giren'],'t'=>'number'],['v'=>(float)$s['cikan'],'t'=>'number'],['v'=>(float)$s['stok'],'t'=>'number']]);
    }
    $xl->total([['v'=>'TOPLAM','t'=>'text'],['v'=>'','t'=>'text'],['v'=>'','t'=>'text'],
        ['v'=>$topGiren,'t'=>'number'],['v'=>$topCikan,'t'=>'number'],['v'=>$topStok,'t'=>'number']]);
    $xl->download('seramik_rapor_'.date('Ymd_Hi').'.xlsx');
}

$pageTitle = 'Seramik Raporları';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Seramik Raporları</h4>
    <div class="d-flex gap-2">
        <a href="raporlar.php?disaaktar=xlsx" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</a>
        <button type="button" onclick="srPdf()" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF / Yazdır</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-grid-3x3 me-1"></i>Malzeme Çeşidi</div><div class="h3 mb-0 fw-bold"><?= count($stok) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-boxes me-1"></i>Toplam Stok</div><div class="h4 mb-0 fw-bold text-primary"><?= $fmt($topStok) ?> <small class="fs-6 text-muted">m²</small></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-box-arrow-in-down me-1"></i>Toplam Giren</div><div class="h4 mb-0 fw-bold text-success"><?= $fmt($topGiren) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-box-arrow-up me-1"></i>Toplam Çıkan</div><div class="h4 mb-0 fw-bold text-danger"><?= $fmt($topCikan) ?></div></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6 class="mb-3"><i class="bi bi-graph-up text-primary me-2"></i>Aylık Giriş / Çıkış (m²)</h6>
        <canvas id="chAylik" height="120"></canvas>
    </div></div></div>
    <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6 class="mb-3"><i class="bi bi-pie-chart text-primary me-2"></i>Tür Bazlı Stok</h6>
        <canvas id="chTur" height="150"></canvas>
    </div></div></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Tür Bazlı Özet</div>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Tür</th><th class="text-end">Çeşit</th><th class="text-end">Giren</th><th class="text-end">Çıkan</th><th class="text-end">Stok (m²)</th></tr></thead>
            <tbody>
            <?php foreach ($turOzet as $tur=>$o): ?>
                <tr><td class="fw-semibold"><?= h($tur) ?></td><td class="text-end"><?= (int)$o['adet'] ?></td>
                    <td class="text-end font-monospace text-success"><?= $fmt($o['giren']) ?></td>
                    <td class="text-end font-monospace text-danger"><?= $fmt($o['cikan']) ?></td>
                    <td class="text-end font-monospace fw-bold"><?= $fmt($o['stok']) ?></td></tr>
            <?php endforeach; ?>
            <?php if(!$turOzet): ?><tr><td colspan="5" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>

<script>
// ── PDF / Yazdır: ERN Taahhüt logolu A4 penceresi ────────────────────────────
const SR_PDF = {
    kpi: { cesit: <?= (int)count($stok) ?>, stok: <?= json_encode(round($topStok,2)) ?>,
           giren: <?= json_encode(round($topGiren,2)) ?>, cikan: <?= json_encode(round($topCikan,2)) ?> },
    tur: <?= json_encode(array_map(fn($t,$o)=>['tur'=>$t,'adet'=>(int)$o['adet'],'giren'=>round($o['giren'],2),'cikan'=>round($o['cikan'],2),'stok'=>round($o['stok'],2)], array_keys($turOzet), $turOzet), JSON_UNESCAPED_UNICODE) ?>,
    aylik: <?= json_encode(array_map(fn($ay,$v)=>['ay'=>$ay,'g'=>round($v['g']??0,2),'c'=>round($v['c']??0,2)], array_keys($aylik), $aylik), JSON_UNESCAPED_UNICODE) ?>,
    stokListe: <?= json_encode(array_map(fn($x)=>['ad'=>$x['ad'],'tur'=>$x['tur']?:'—','giren'=>round((float)$x['giren'],2),'cikan'=>round((float)$x['cikan'],2),'stok'=>round((float)$x['stok'],2)], array_slice($stok,0,400)), JSON_UNESCAPED_UNICODE) ?>
};
function srPdf(){
    const f = n => Number(n).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
    const esc = t => String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;');
    const tbl = (hdr, rows) => '<table><thead><tr>'+hdr.map(x=>'<th>'+x+'</th>').join('')+'</tr></thead><tbody>'
        + rows.map(r=>'<tr>'+r.map((x,i)=>'<td'+(i>0?' class="r"':'')+'>'+esc(x)+'</td>').join('')+'</tr>').join('')+'</tbody></table>';
    const logoUrl = new URL('../uploads/logo/ern_taahhut_export.png', location.href).href;
    let html = '<div style="display:flex;align-items:center;gap:12px;border-bottom:3px solid #00584E;padding-bottom:8px;margin-bottom:6px">'
        + '<img src="'+logoUrl+'" style="height:44px" onerror="this.remove()">'
        + '<h1 style="border:none">ERN TAAHHÜT — SERAMİK RAPORU</h1></div>'
        + '<div class="meta">Yazdırma: '+new Date().toLocaleString('tr-TR')+'</div>'
        + '<div class="kpis"><div><b>'+SR_PDF.kpi.cesit+'</b>Malzeme Çeşidi</div><div><b>'+f(SR_PDF.kpi.stok)+' m²</b>Toplam Stok</div>'
        + '<div><b>'+f(SR_PDF.kpi.giren)+'</b>Toplam Giren</div><div><b>'+f(SR_PDF.kpi.cikan)+'</b>Toplam Çıkan</div></div>'
        + '<h2>Tür Bazlı Özet</h2>' + tbl(['Tür','Çeşit','Giren','Çıkan','Stok (m²)'],
            SR_PDF.tur.map(t=>[t.tur, t.adet, f(t.giren), f(t.cikan), f(t.stok)]))
        + '<h2>Aylık Giriş / Çıkış (m²)</h2>' + tbl(['Ay','Giriş','Çıkış'],
            SR_PDF.aylik.map(a=>[a.ay, f(a.g), f(a.c)]))
        + '<h2>Malzeme Bazlı Stok</h2>' + tbl(['Malzeme','Tür','Giren','Çıkan','Stok'],
            SR_PDF.stokListe.map(x=>[x.ad, x.tur, f(x.giren), f(x.cikan), f(x.stok)]));
    const w = window.open('', '_blank');
    if (!w) { alert('Pop-up engellendi.'); return; }
    w.document.write('<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Seramik Raporu</title><style>'
        + 'body{font-family:Segoe UI,Arial,sans-serif;color:#111;padding:24px;} h1{color:#00584E;font-size:20px;margin:0;} h2{color:#00584E;font-size:14px;border-bottom:1px solid #ddd;padding-bottom:4px;margin:20px 0 6px;} .meta{color:#666;font-size:12px;margin-bottom:14px;}'
        + 'table{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:8px;} th,td{border:1px solid #bbb;padding:4px 7px;} th{background:#00584E;color:#fff;} td.r{text-align:right;}'
        + '.kpis{display:flex;gap:10px;margin:10px 0;} .kpis div{flex:1;border:1px solid #ddd;border-radius:8px;padding:8px;text-align:center;font-size:11px;color:#666;} .kpis b{display:block;font-size:17px;color:#111;}'
        + '@media print{body{padding:6mm;}}</style></head><body>'+html
        + '<script>window.onload=function(){setTimeout(function(){window.print();},450);}<\/script></body></html>');
    w.document.close();
}

(function(){
    const palette=['#00584E','#00C9B1','#C9A84C','#007A6A','#6f42c1','#0d6efd','#fd7e14','#20c997','#d63384','#198754'];
    new Chart(document.getElementById('chAylik'),{
        type:'bar',
        data:{labels:<?= json_encode(array_keys($aylik)) ?>,datasets:[
            {label:'Giren',data:<?= json_encode(array_map(fn($a)=>$a['g']??0,$aylik)) ?>,backgroundColor:'#00C9B1',borderRadius:4},
            {label:'Çıkan',data:<?= json_encode(array_map(fn($a)=>$a['c']??0,$aylik)) ?>,backgroundColor:'#dc3545',borderRadius:4}
        ]},
        options:{plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true}}}
    });
    new Chart(document.getElementById('chTur'),{
        type:'doughnut',
        data:{labels:<?= json_encode(array_keys($turOzet),JSON_UNESCAPED_UNICODE) ?>,datasets:[{data:<?= json_encode(array_map(fn($o)=>round($o['stok'],2),$turOzet)) ?>,backgroundColor:palette,borderWidth:0}]},
        options:{plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}},cutout:'60%'}
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
