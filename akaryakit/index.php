<?php
/** index.php — Akaryakıt Takip dashboard */
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
$guncel = $donemler[0] ?? null;          // en güncel dönem
$topGelen = 0; $topKull = 0;
foreach ($donemler as $d) { $topGelen += (float)$d['gelen']; $topKull += (float)$d['kullanilan']; }
$aracSay = (int)$pdoAkaryakit->query("SELECT COUNT(*) FROM akaryakit_araclar WHERE aktif=1")->fetchColumn();

// Aylık tüketim serisi (eskiden yeniye)
$seri = array_reverse($donemler);
$labels = array_map(fn($d)=>$d['donem'], $seri);
$kullSeri = array_map(fn($d)=>(float)$d['kullanilan'], $seri);
$kalanSeri = array_map(fn($d)=>(float)$d['kalan'], $seri);

// Firma bazlı toplam tüketim
$firma = $pdoAkaryakit->query("SELECT COALESCE(NULLIF(a.firma,''),'—') firma, SUM(t.aylik_tuketim) top
    FROM akaryakit_tuketim t JOIN akaryakit_araclar a ON a.id=t.arac_id
    GROUP BY firma ORDER BY top DESC")->fetchAll();

// En çok tüketen araçlar (top 12)
$topArac = $pdoAkaryakit->query("SELECT a.sofor, a.cinsi, a.firma, SUM(t.aylik_tuketim) top
    FROM akaryakit_tuketim t JOIN akaryakit_araclar a ON a.id=t.arac_id
    GROUP BY t.arac_id ORDER BY top DESC LIMIT 12")->fetchAll();

$fmt0 = fn($n)=>number_format((float)$n,0,',','.');
$pageTitle = 'Akaryakıt Takip — Genel Bakış';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-fuel-pump text-primary me-2"></i>Akaryakıt Takip — Genel Bakış</h4>
    <a href="import.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Excel İçe Aktar</a>
</div>

<?php if(!$donemler): ?>
<div class="alert alert-light border text-center text-muted py-4">
    Henüz veri yok. <a href="import.php" class="alert-link">Excel içe aktarın</a> veya önce <a href="kurulum_akaryakit.php" class="alert-link">kurulumu</a> çalıştırın.
</div>
<?php else: ?>

<!-- KPI -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-fuel-pump-fill me-1"></i>Güncel Stok (Kalan)</div>
            <div class="h3 mb-0 fw-bold text-primary"><?= $fmt0($guncel['kalan']??0) ?> <small class="fs-6 text-muted">Lt</small></div>
            <div class="small text-muted mt-1"><?= h($guncel['donem']??'') ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-box-arrow-in-down me-1"></i>Toplam Gelen Mazot</div>
            <div class="h3 mb-0 fw-bold text-success"><?= $fmt0($topGelen) ?> <small class="fs-6 text-muted">Lt</small></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-speedometer me-1"></i>Toplam Tüketim</div>
            <div class="h3 mb-0 fw-bold text-danger"><?= $fmt0($topKull) ?> <small class="fs-6 text-muted">Lt</small></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-truck me-1"></i>Araç / Makine</div>
            <div class="h3 mb-0 fw-bold"><?= $fmt0($aracSay) ?></div>
            <div class="small text-muted mt-1"><?= count($donemler) ?> dönem</div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h6 class="mb-3"><i class="bi bi-graph-up text-primary me-2"></i>Aylık Tüketim & Kalan Stok</h6>
            <canvas id="chAylik" height="110"></canvas>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h6 class="mb-3"><i class="bi bi-pie-chart text-primary me-2"></i>Firma Bazlı Tüketim</h6>
            <canvas id="chFirma" height="200"></canvas>
        </div></div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 pt-3"><h6 class="mb-0"><i class="bi bi-trophy text-warning me-2"></i>En Çok Tüketen Araçlar / Makineler</h6></div>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
            <thead class="table-light"><tr><th>#</th><th>Şoför</th><th>Cinsi</th><th>Firma</th><th class="text-end">Toplam Tüketim (Lt)</th></tr></thead>
            <tbody>
            <?php foreach($topArac as $ix=>$r): ?>
                <tr>
                    <td class="text-muted"><?= $ix+1 ?></td>
                    <td class="fw-semibold"><?= h($r['sofor']) ?></td>
                    <td class="small"><?= h($r['cinsi']) ?></td>
                    <td class="small text-muted"><?= h($r['firma']?:'—') ?></td>
                    <td class="text-end font-monospace fw-bold"><?= $fmt0($r['top']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>

<script>
(function(){
    const ern='#00584E', ernTeal='#00C9B1', gold='#C9A84C', red='#dc3545';
    const palette=['#00584E','#00C9B1','#C9A84C','#007A6A','#6f42c1','#0d6efd','#fd7e14','#20c997','#d63384','#6610f2','#198754','#adb5bd'];
    new Chart(document.getElementById('chAylik'),{
        type:'bar',
        data:{labels:<?= json_encode($labels,JSON_UNESCAPED_UNICODE) ?>,
            datasets:[
                {type:'bar',label:'Tüketim (Lt)',data:<?= json_encode($kullSeri) ?>,backgroundColor:ern,borderRadius:4},
                {type:'line',label:'Kalan Stok (Lt)',data:<?= json_encode($kalanSeri) ?>,borderColor:gold,backgroundColor:'rgba(201,168,76,.15)',tension:.3,fill:true,yAxisID:'y'}
            ]},
        options:{responsive:true,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true}}}
    });
    new Chart(document.getElementById('chFirma'),{
        type:'doughnut',
        data:{labels:<?= json_encode(array_column($firma,'firma'),JSON_UNESCAPED_UNICODE) ?>,
            datasets:[{data:<?= json_encode(array_map(fn($f)=>(float)$f['top'],$firma)) ?>,backgroundColor:palette}]},
        options:{responsive:true,plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}}}
    });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
