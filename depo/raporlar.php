<?php
/** raporlar.php — Depo raporları (Chart.js + Excel dışa aktarma) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoDepo->query("SELECT 1 FROM depo_kalemler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_depo.php'); }

$ozet = dp_ozet($pdoDepo);
$topDeger=0; $topKalem=0; $topTukenen=0;
foreach ($ozet as $o) { $topDeger+=(float)$o['deger']; $topKalem+=(int)$o['adet']; $topTukenen+=(int)$o['tukenen']; }

// Disiplin bazlı mali değer (demirbaş + sarf; el aletleri fiyatsız)
$disiplin = $pdoDepo->query("SELECT COALESCE(NULLIF(disiplin,''),'—') disiplin,
    SUM((sayim+gelen-giden)*COALESCE(birim_fiyat,0)) deger, COUNT(*) adet
    FROM depo_kalemler WHERE aktif=1 AND kategori<>'el_aleti'
    GROUP BY disiplin HAVING deger>0 ORDER BY deger DESC")->fetchAll();

// En değerli 15 kalem
$topKalemler = $pdoDepo->query("SELECT kategori, kod, ad, (sayim+gelen-giden) stok, birim_fiyat,
    (sayim+gelen-giden)*COALESCE(birim_fiyat,0) deger
    FROM depo_kalemler WHERE aktif=1 AND kategori<>'el_aleti' AND birim_fiyat>0
    ORDER BY deger DESC LIMIT 15")->fetchAll();

$fmt0 = fn($n)=>number_format((float)$n,0,',','.');
$fmt2 = fn($n)=>number_format((float)$n,2,',','.');

// ── Excel dışa aktarma ──
if (($_GET['disaaktar'] ?? '')==='xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new \XlsxWriter('Depo Rapor');
    $xl->header(['Kategori','Kalem','Stok','Mali Değer (TL)','Tükenen']);
    foreach ($ozet as $kat=>$o) {
        $xl->row([['v'=>dp_katAd($kat),'t'=>'text'],['v'=>(int)$o['adet'],'t'=>'number'],
            ['v'=>(float)$o['stok'],'t'=>'number'],['v'=>(float)$o['deger'],'t'=>'number'],['v'=>(int)$o['tukenen'],'t'=>'number']]);
    }
    $xl->total([['v'=>'TOPLAM','t'=>'text'],['v'=>$topKalem,'t'=>'number'],['v'=>'','t'=>'text'],['v'=>$topDeger,'t'=>'number'],['v'=>$topTukenen,'t'=>'number']]);
    $xl->download('depo_rapor_'.date('Ymd_Hi').'.xlsx');
}

$pageTitle = 'Depo Raporları';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Depo Raporları</h4>
    <a href="raporlar.php?disaaktar=xlsx" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-collection me-1"></i>Toplam Kalem</div><div class="h3 mb-0 fw-bold"><?= $fmt0($topKalem) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-cash-stack me-1"></i>Toplam Mali Değer</div><div class="h4 mb-0 fw-bold text-success"><?= $fmt0($topDeger) ?> <small class="fs-6 text-muted">TL</small></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-diagram-3 me-1"></i>Kategori</div><div class="h3 mb-0 fw-bold"><?= count($ozet) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Tükenen</div><div class="h3 mb-0 fw-bold <?= $topTukenen>0?'text-danger':'' ?>"><?= $fmt0($topTukenen) ?></div></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6 class="mb-3"><i class="bi bi-pie-chart text-primary me-2"></i>Kategori Bazlı Mali Değer</h6>
        <canvas id="chKat" height="150"></canvas>
    </div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6 class="mb-3"><i class="bi bi-bar-chart text-primary me-2"></i>Disiplin Bazlı Mali Değer</h6>
        <canvas id="chDis" height="150"></canvas>
    </div></div></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-trophy text-warning me-2"></i>En Değerli Kalemler</div>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
            <thead class="table-light"><tr><th>#</th><th>Kategori</th><th>Kod</th><th>Malzeme</th><th class="text-end">Stok</th><th class="text-end">B.Fiyat</th><th class="text-end">Mali Değer (TL)</th></tr></thead>
            <tbody>
            <?php foreach ($topKalemler as $ix=>$r): ?>
                <tr><td class="text-muted"><?= $ix+1 ?></td><td><span class="badge bg-light text-dark"><?= h(dp_katAd($r['kategori'])) ?></span></td>
                    <td class="font-monospace small"><?= h($r['kod']?:'—') ?></td><td class="fw-semibold small"><?= h($r['ad']) ?></td>
                    <td class="text-end font-monospace"><?= $fmt2($r['stok']) ?></td><td class="text-end font-monospace small"><?= $fmt2($r['birim_fiyat']) ?></td>
                    <td class="text-end font-monospace fw-bold text-success"><?= $fmt0($r['deger']) ?></td></tr>
            <?php endforeach; ?>
            <?php if(!$topKalemler): ?><tr><td colspan="7" class="text-center text-muted py-4">Fiyatlı kalem yok.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>

<script>
(function(){
    const palette=['#00584E','#00C9B1','#C9A84C','#007A6A','#6f42c1','#0d6efd','#fd7e14','#20c997','#d63384','#198754'];
    new Chart(document.getElementById('chKat'),{
        type:'doughnut',
        data:{labels:<?= json_encode(array_map(fn($k)=>dp_katAd($k),array_keys($ozet)),JSON_UNESCAPED_UNICODE) ?>,
            datasets:[{data:<?= json_encode(array_map(fn($o)=>round((float)$o['deger'],2),array_values($ozet))) ?>,backgroundColor:palette,borderWidth:0}]},
        options:{plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}},cutout:'60%'}
    });
    new Chart(document.getElementById('chDis'),{
        type:'bar',
        data:{labels:<?= json_encode(array_column($disiplin,'disiplin'),JSON_UNESCAPED_UNICODE) ?>,
            datasets:[{label:'Mali Değer (TL)',data:<?= json_encode(array_map(fn($d)=>round((float)$d['deger'],2),$disiplin)) ?>,backgroundColor:'#00584E',borderRadius:4}]},
        options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
