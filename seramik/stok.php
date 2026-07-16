<?php
/**
 * stok.php — Ambar Mevcut (Stok) : malzeme bazında Giriş − Çıkış = Stok (CANLI)
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_seramik.php';
require_once __DIR__ . '/_ortak.php';

$pageTitle = 'Stok (Ambar Mevcut) — Seramik';
$stok = sr_stok($pdoSeramik);
$topGiren = 0; $topCikan = 0; $topStok = 0; $tukenen = 0;
foreach ($stok as $s) { $topGiren+=(float)$s['giren']; $topCikan+=(float)$s['cikan']; $topStok+=(float)$s['stok']; if ((float)$s['stok']<=0) $tukenen++; }
$fmt = fn($n)=>number_format((float)$n,2,',','.');

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div><h4 class="mb-0"><i class="bi bi-boxes text-primary me-2"></i>Stok — Ambar Mevcut</h4>
        <small class="text-muted">Stok = Sayım (Mevcut) + elle giriş − çıkışlar (canlı). Giriş sütunu = fiziksel sayım + elle girişler.</small></div>
    <a href="import.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Excel İçe Aktar</a>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Toplam Giriş</div><div class="fs-5 fw-bold text-success"><?= $fmt($topGiren) ?> m²</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Toplam Çıkış</div><div class="fs-5 fw-bold text-danger"><?= $fmt($topCikan) ?> m²</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Toplam Stok</div><div class="fs-5 fw-bold" style="color:var(--ern)"><?= $fmt($topStok) ?> m²</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Malzeme / Tükenen</div><div class="fs-5 fw-bold"><?= count($stok) ?> <span class="text-danger small">/ <?= $tukenen ?></span></div></div></div></div>
</div>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive" style="max-height:72vh">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light" style="position:sticky;top:0"><tr>
            <th>Malzeme</th><th>Birim</th><th class="text-end">Giriş</th><th class="text-end">Çıkış</th><th class="text-end">Stok</th><th style="width:20%">Durum</th>
        </tr></thead>
        <tbody>
        <?php foreach ($stok as $s): $st=(float)$s['stok']; $gir=(float)$s['giren']; $yuzde=$gir>0?max(0,min(100,round($st/$gir*100))):0; ?>
            <tr class="<?= $st<=0?'table-danger':'' ?>">
                <td class="fw-semibold"><?= h($s['ad']) ?></td>
                <td class="small text-muted"><?= h($s['birim']) ?></td>
                <td class="text-end font-monospace text-success"><?= $fmt($s['giren']) ?></td>
                <td class="text-end font-monospace text-danger"><?= $fmt($s['cikan']) ?></td>
                <td class="text-end font-monospace fw-bold <?= $st<=0?'text-danger':'' ?>"><?= $fmt($st) ?></td>
                <td>
                    <?php if ($st<=0): ?><span class="badge bg-danger">Tükendi</span>
                    <?php else: ?>
                        <div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:7px;max-width:120px"><div class="progress-bar" style="width:<?= $yuzde ?>%;background:var(--ern)"></div></div><span class="small text-muted"><?= $yuzde ?>%</span></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$stok): ?><tr><td colspan="6" class="text-center text-muted py-4">Henüz malzeme yok. <a href="import.php">Excel içe aktarın</a>.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
