<?php
/** index.php — Seramik Takip Dashboard */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_seramik.php';
require_once __DIR__ . '/_ortak.php';

$pageTitle = 'Seramik Takip — Dashboard';
$kurulu = true; $stok = []; $sonGiris = []; $sonCikis = [];
$topGiren=0;$topCikan=0;$topStok=0;$malSay=0;$tukenen=0;$paletSay=0;
try {
    $stok = sr_stok($pdoSeramik);
    foreach($stok as $s){ $topGiren+=(float)$s['giren']; $topCikan+=(float)$s['cikan']; $topStok+=(float)$s['stok']; if((float)$s['stok']<=0)$tukenen++; }
    $malSay=count($stok);
    $sonGiris=$pdoSeramik->query("SELECT g.gelis_tarihi,g.belge_no,g.miktar,g.birim,m.ad malzeme,f.ad firma FROM seramik_giris g LEFT JOIN seramik_malzemeler m ON m.id=g.malzeme_id LEFT JOIN seramik_firmalar f ON f.id=g.firma_id ORDER BY g.gelis_tarihi DESC, g.id DESC LIMIT 8")->fetchAll();
    $sonCikis=$pdoSeramik->query("SELECT c.cikis_tarihi,c.fis_no,c.miktar,c.birim,m.ad malzeme,t.ad taseron FROM seramik_cikis c LEFT JOIN seramik_malzemeler m ON m.id=c.malzeme_id LEFT JOIN seramik_taseronlar t ON t.id=c.taseron_id ORDER BY c.cikis_tarihi DESC, c.id DESC LIMIT 8")->fetchAll();
    $paletSay=(int)$pdoSeramik->query("SELECT COUNT(*) FROM seramik_palet")->fetchColumn();
} catch (Throwable $e) { $kurulu = false; }

$fmt=fn($n)=>number_format((float)$n,2,',','.');
require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-1"><i class="bi bi-grid-1x2 text-primary me-2"></i>Seramik Takip — Genel Bakış</h4>
<p class="text-muted small mb-3">Ambar giriş/çıkış ve canlı stok (giriş − çıkış)</p>

<?php if (!$kurulu): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>Seramik veritabanı henüz kurulmamış.
    <a href="kurulum_seramik.php" class="alert-link">Kurulumu çalıştırın</a>, sonra <a href="import.php" class="alert-link">Excel içe aktarın</a>.</div>
<?php else: ?>

<div class="row g-2 mb-4">
    <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3"><div class="text-muted small">Toplam Giriş</div><div class="fs-4 fw-bold text-success"><?= $fmt($topGiren) ?></div><div class="small text-muted">m²</div></div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3"><div class="text-muted small">Toplam Çıkış</div><div class="fs-4 fw-bold text-danger"><?= $fmt($topCikan) ?></div><div class="small text-muted">m²</div></div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3"><div class="text-muted small">Toplam Stok</div><div class="fs-4 fw-bold" style="color:var(--ern)"><?= $fmt($topStok) ?></div><div class="small text-muted">m²</div></div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3"><div class="text-muted small">Malzeme Çeşidi</div><div class="fs-4 fw-bold"><?= $malSay ?></div></div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3"><div class="text-muted small">Tükenen</div><div class="fs-4 fw-bold text-danger"><?= $tukenen ?></div></div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3"><div class="text-muted small">Palet Kaydı</div><div class="fs-4 fw-bold"><?= $paletSay ?></div></div></div></div>
</div>

<div class="d-flex gap-2 flex-wrap mb-4">
    <a href="giris_form.php" class="btn btn-success btn-sm"><i class="bi bi-plus-circle me-1"></i>Yeni Giriş</a>
    <a href="cikis_form.php" class="btn btn-danger btn-sm"><i class="bi bi-dash-circle me-1"></i>Yeni Çıkış</a>
    <a href="stok.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-boxes me-1"></i>Stok</a>
    <a href="import.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Excel İçe Aktar</a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-semibold"><i class="bi bi-boxes me-1"></i>Stok Özeti (en yüksek)</div>
        <div class="table-responsive" style="max-height:44vh"><table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light" style="position:sticky;top:0"><tr><th>Malzeme</th><th class="text-end">Stok (m²)</th></tr></thead><tbody>
            <?php usort($stok, fn($a,$b)=>(float)$b['stok']<=>(float)$a['stok']); foreach(array_slice($stok,0,12) as $s): ?>
                <tr class="<?= (float)$s['stok']<=0?'table-danger':'' ?>"><td class="small fw-semibold"><?= h($s['ad']) ?></td><td class="text-end font-monospace"><?= $fmt($s['stok']) ?></td></tr>
            <?php endforeach; ?>
            <?php if(!$stok): ?><tr><td colspan="2" class="text-center text-muted py-3">Veri yok</td></tr><?php endif; ?>
        </tbody></table></div></div>
    </div>
    <div class="col-lg-7">
        <div class="row g-3">
            <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold"><i class="bi bi-box-arrow-in-down text-success me-1"></i>Son Girişler</div>
            <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><tbody>
            <?php foreach($sonGiris as $g): ?><tr><td class="small text-nowrap"><?= $g['gelis_tarihi']?date('d.m.Y',strtotime($g['gelis_tarihi'])):'' ?></td><td class="small"><?= h($g['malzeme']) ?></td><td class="small text-muted"><?= h($g['firma']) ?></td><td class="text-end font-monospace small"><?= $fmt($g['miktar']) ?></td></tr><?php endforeach; ?>
            <?php if(!$sonGiris): ?><tr><td class="text-center text-muted py-2">—</td></tr><?php endif; ?>
            </tbody></table></div></div></div>
            <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold"><i class="bi bi-box-arrow-up text-danger me-1"></i>Son Çıkışlar</div>
            <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><tbody>
            <?php foreach($sonCikis as $c): ?><tr><td class="small text-nowrap"><?= $c['cikis_tarihi']?date('d.m.Y',strtotime($c['cikis_tarihi'])):'' ?></td><td class="small"><?= h($c['malzeme']) ?></td><td class="small text-muted"><?= h($c['taseron']) ?></td><td class="text-end font-monospace small"><?= $fmt($c['miktar']) ?></td></tr><?php endforeach; ?>
            <?php if(!$sonCikis): ?><tr><td class="text-center text-muted py-2">—</td></tr><?php endif; ?>
            </tbody></table></div></div></div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
