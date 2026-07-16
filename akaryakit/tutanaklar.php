<?php
/** tutanaklar.php — Akaryakıt tutanakları (dönem bazlı imzalı tüketim raporu) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoAkaryakit->query("SELECT 1 FROM akaryakit_tutanak LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_akaryakit.php'); }

$donemler = $pdoAkaryakit->query("SELECT donem, MAX(donem_sira) ds, COUNT(*) adet, SUM(miktar) top
    FROM akaryakit_tutanak GROUP BY donem ORDER BY ds DESC")->fetchAll();
$sec = $_GET['donem'] ?? ($donemler[0]['donem'] ?? '');

$liste=[];
if ($sec!=='') {
    $q=$pdoAkaryakit->prepare("SELECT * FROM akaryakit_tutanak WHERE donem=? ORDER BY sira, id");
    $q->execute([$sec]); $liste=$q->fetchAll();
}
$top = array_sum(array_map(fn($r)=>(float)$r['miktar'],$liste));
$fmt0=fn($n)=>number_format((float)$n,0,',','.');
$pageTitle='Akaryakıt Tutanakları';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-file-earmark-text text-primary me-2"></i>Akaryakıt Tutanakları</h4>
    <div class="d-flex align-items-center gap-2">
        <form class="d-flex align-items-center gap-2">
            <label class="small text-muted mb-0">Dönem</label>
            <select name="donem" class="form-select form-select-sm" style="min-width:170px" onchange="this.form.submit()">
                <?php foreach($donemler as $d): ?>
                <option value="<?= h($d['donem']) ?>" <?= $d['donem']===$sec?'selected':'' ?>><?= h($d['donem']) ?> (<?= (int)$d['adet'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if($sec!==''): ?><a href="tutanak_pdf.php?donem=<?= urlencode($sec) ?>" target="_blank" class="btn btn-outline-danger btn-sm"><i class="bi bi-printer me-1"></i>Yazdır / PDF</a><?php endif; ?>
    </div>
</div>

<?php if(!$donemler): ?>
<div class="alert alert-light border text-center text-muted py-4">Tutanak kaydı yok. <a href="import.php" class="alert-link">Excel içe aktarın</a> (TUTANAK sayfası).</div>
<?php else: ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr>
        <th>Sıra</th><th>Şoför Adı</th><th>Araç Detay</th><th>Firma Detay</th><th class="text-end">Miktar (Lt)</th>
    </tr></thead>
    <tbody>
    <?php foreach($liste as $r): ?>
        <tr>
            <td class="text-muted"><?= (int)$r['sira'] ?></td>
            <td class="fw-semibold small"><?= h($r['sofor']) ?></td>
            <td class="small"><?= h($r['arac_detay']?:'—') ?></td>
            <td class="small text-muted"><?= h($r['firma_detay']?:'—') ?></td>
            <td class="text-end font-monospace fw-bold"><?= $fmt0($r['miktar']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light"><tr class="fw-bold"><td colspan="4" class="text-end">TOPLAM</td><td class="text-end font-monospace"><?= $fmt0($top) ?></td></tr></tfoot>
</table>
</div></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
