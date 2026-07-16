<?php
/** index.php — Depo Takip dashboard (Sarf / Demirbaş / El Aletleri) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

// Tablo yoksa kuruluma yönlendir
try { $pdoDepo->query("SELECT 1 FROM depo_kalemler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_depo.php'); }

$ozet = dp_ozet($pdoDepo);
$topDeger = 0; $topKalem = 0; $topTukenen = 0;
foreach ($ozet as $o) { $topDeger += (float)$o['deger']; $topKalem += (int)$o['adet']; $topTukenen += (int)$o['tukenen']; }
$fmt0 = fn($n)=>number_format((float)$n,0,',','.');
$fmt  = fn($n)=>number_format((float)$n,2,',','.');

// Tükenen / düşük stok kalemler (kritik liste)
$kritik = $pdoDepo->query("SELECT kategori,kod,ad,ozellik,birim,(sayim+gelen-giden) stok
    FROM depo_kalemler WHERE aktif=1 AND (sayim+gelen-giden)<=0 ORDER BY ad LIMIT 60")->fetchAll();

$pageTitle = 'Depo Takip — Genel Bakış';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Depo Takip — Genel Bakış</h4>
    <div class="d-flex gap-2">
        <a href="import.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Excel İçe Aktar</a>
    </div>
</div>

<!-- Özet KPI -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-collection me-1"></i>Toplam Kalem</div>
            <div class="h3 mb-0 fw-bold"><?= $fmt0($topKalem) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-cash-stack me-1"></i>Toplam Mali Değer</div>
            <div class="h3 mb-0 fw-bold text-success"><?= $fmt0($topDeger) ?> <small class="fs-6 text-muted">TL</small></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-diagram-3 me-1"></i>Kategori</div>
            <div class="h3 mb-0 fw-bold"><?= count($GLOBALS['DP_KATEGORI']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Tükenen Kalem</div>
            <div class="h3 mb-0 fw-bold <?= $topTukenen>0?'text-danger':'' ?>"><?= $fmt0($topTukenen) ?></div>
        </div></div>
    </div>
</div>

<!-- Kategori kartları -->
<div class="row g-3 mb-4">
    <?php foreach($GLOBALS['DP_KATEGORI'] as $kk=>$ki): $o=$ozet[$kk]??null; $elAleti=($kk==='el_aleti'); ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3"
                         style="width:48px;height:48px;background:var(--ern,#00584E);color:#fff">
                        <i class="bi <?= h($ki['ikon']) ?> fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= h($ki['ad']) ?></div>
                        <div class="small text-muted"><?= $o?$fmt0($o['adet']):'0' ?> kalem</div>
                    </div>
                </div>
                <div class="row text-center g-2">
                    <div class="col-4">
                        <div class="small text-muted">Stok</div>
                        <div class="fw-bold"><?= $o?$fmt0($o['stok']):'0' ?></div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted"><?= $elAleti?'—':'Değer' ?></div>
                        <div class="fw-bold text-success"><?= (!$elAleti && $o)?$fmt0($o['deger']):'—' ?></div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted">Tükenen</div>
                        <div class="fw-bold <?= ($o && $o['tukenen']>0)?'text-danger':'' ?>"><?= $o?$fmt0($o['tukenen']):'0' ?></div>
                    </div>
                </div>
                <a href="kalemler.php?k=<?= $kk ?>" class="btn btn-outline-primary btn-sm w-100 mt-3"><i class="bi bi-list-ul me-1"></i>Kalemleri Gör</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Kritik / tükenen liste -->
<?php if($kritik): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 pt-3"><h6 class="mb-0"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Tükenen / Stok Dışı Kalemler <span class="badge bg-danger ms-1"><?= count($kritik) ?></span></h6></div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:50vh">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
            <thead class="table-light" style="position:sticky;top:0"><tr>
                <th>Kategori</th><th>Kod</th><th>Malzeme</th><th>Özellik</th><th class="text-end">Stok</th>
            </tr></thead>
            <tbody>
            <?php foreach($kritik as $r): ?>
                <tr>
                    <td><span class="badge bg-light text-dark"><?= h(dp_katAd($r['kategori'])) ?></span></td>
                    <td class="font-monospace small"><?= h($r['kod']?:'—') ?></td>
                    <td class="fw-semibold small"><?= h($r['ad']) ?></td>
                    <td class="small text-muted"><?= h($r['ozellik']?:'—') ?></td>
                    <td class="text-end font-monospace fw-bold text-danger"><?= $fmt($r['stok']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>
<?php else: ?>
<div class="alert alert-light border text-center text-muted"><i class="bi bi-check-circle text-success me-1"></i>Tükenen/stok dışı kalem yok.</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
