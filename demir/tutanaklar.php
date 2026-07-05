<?php
/**
 * demir/tutanaklar.php — Taşerona teslim tutanakları listesi
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Teslim Tutanakları — Demir Takip';

// sozlesme_id kolonu garanti (sozlesmeler.php açılmadan da liste çalışsın)
try {
    if (!$pdoDemir->query("SHOW COLUMNS FROM demir_tutanaklar LIKE 'sozlesme_id'")->fetchColumn()) {
        $pdoDemir->exec("ALTER TABLE demir_tutanaklar ADD COLUMN sozlesme_id INT NULL AFTER proje_id");
    }
    $pdoDemir->exec("CREATE TABLE IF NOT EXISTS demir_sozlesmeler (
        id INT AUTO_INCREMENT PRIMARY KEY, sozlesme_no VARCHAR(60) NOT NULL, taseron_id INT NOT NULL,
        proje_id INT NULL, tarih DATE NULL, konu VARCHAR(300) NULL, aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY (taseron_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

if (has_role('admin','teknik_ofis_admin') && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $pdoDemir->prepare("DELETE FROM demir_tutanaklar WHERE id=?")->execute([(int)$_GET['sil']]);
    flash('success', 'Tutanak silindi.');
    redirect('tutanaklar.php');
}

$fTas = isset($_GET['taseron']) && ctype_digit($_GET['taseron']) ? (int)$_GET['taseron'] : 0;
$where = []; $params = [];
if ($fTas) { $where[] = 'tu.taseron_id = ?'; $params[] = $fTas; }
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$liste = $pdoDemir->prepare("
    SELECT tu.*, ta.ad AS taseron_adi, p.kod AS proje_kod, sz.sozlesme_no,
           COALESCE(k.top_ton,0) AS top_ton, COALESCE(k.top_bag,0) AS top_bag, COALESCE(k.adet,0) AS kalem
    FROM demir_tutanaklar tu
    LEFT JOIN demir_taseronlar ta ON ta.id = tu.taseron_id
    LEFT JOIN demir_projeler p ON p.id = tu.proje_id
    LEFT JOIN demir_sozlesmeler sz ON sz.id = tu.sozlesme_id
    LEFT JOIN (SELECT tutanak_id, SUM(miktar_ton) top_ton, SUM(bag_adeti) top_bag, COUNT(*) adet
               FROM demir_tutanak_kalemleri GROUP BY tutanak_id) k ON k.tutanak_id = tu.id
    $whereSQL
    ORDER BY tu.tutanak_tarih DESC, tu.id DESC
");
$liste->execute($params);
$liste = $liste->fetchAll();

$taseronlar = $pdoDemir->query("SELECT id,ad FROM demir_taseronlar WHERE aktif=1 ORDER BY ad")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-file-earmark-check text-dark me-2"></i>Teslim Tutanakları</h4>
    <?php if (has_role('admin','teknik_ofis_admin','teknik_ofis','saha_sefi')): ?>
    <a href="tutanak_form.php" class="btn btn-dark"><i class="bi bi-plus-circle me-1"></i> Yeni Tutanak</a>
    <?php endif; ?>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="card mb-3"><div class="card-body">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-4"><label class="form-label small">Taşeron</label><select name="taseron" class="form-select form-select-sm"><option value="">Tümü</option><?php foreach($taseronlar as $t): ?><option value="<?= $t['id'] ?>" <?= $fTas==$t['id']?'selected':'' ?>><?= h($t['ad']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex gap-1"><button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-funnel"></i> Filtrele</button><a href="tutanaklar.php" class="btn btn-outline-secondary btn-sm">Temizle</a></div>
    </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th>Tutanak No</th><th>Sözleşme No</th><th>Tarih</th><th>Taşeron</th><th>Proje</th>
            <th class="text-end">Tonaj (t)</th><th class="text-end">Bağ</th><th class="text-center">Evrak</th><th class="text-end">İşlem</th>
        </tr></thead>
        <tbody>
            <?php foreach ($liste as $r): ?>
            <tr>
                <td class="fw-semibold font-monospace"><a href="tutanak_detay.php?id=<?= $r['id'] ?>" class="text-decoration-none"><?= h($r['tutanak_no']) ?></a></td>
                <td class="font-monospace small"><?= $r['sozlesme_no'] ? '<a href="sozlesmeler.php" class="text-decoration-none">'.h($r['sozlesme_no']).'</a>' : '<span class="text-muted">—</span>' ?></td>
                <td><?= format_date($r['tutanak_tarih']) ?></td>
                <td><?= h($r['taseron_adi'] ?: '—') ?></td>
                <td><?= $r['proje_kod'] ? '<span class="badge bg-secondary">'.h($r['proje_kod']).'</span>' : '—' ?></td>
                <td class="text-end"><?= $fmt($r['top_ton']) ?></td>
                <td class="text-end"><?= (int)$r['top_bag'] ?></td>
                <td class="text-center">
                    <?php if ($r['evrak_url']): ?><span class="badge bg-success"><i class="bi bi-check-lg"></i> Yüklü</span>
                    <?php else: ?><span class="badge bg-light text-muted border">Yok</span><?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                    <a href="tutanak_pdf.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-xs btn-outline-dark me-1" title="Yazdır / PDF"><i class="bi bi-printer"></i></a>
                    <a href="tutanak_detay.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-secondary me-1" title="Detay"><i class="bi bi-eye"></i></a>
                    <?php if (has_role('admin','teknik_ofis_admin','teknik_ofis','saha_sefi')): ?>
                    <a href="tutanak_form.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                    <?php endif; ?>
                    <?php if (has_role('admin','teknik_ofis_admin')): ?>
                    <a href="tutanaklar.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Bu tutanağı silmek istiyor musunuz?"><i class="bi bi-trash"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$liste): ?>
            <tr><td colspan="9" class="text-center text-muted py-5">
                <i class="bi bi-file-earmark-x fs-1 d-block mb-2 opacity-50"></i>
                Henüz tutanak yok. <a href="tutanak_form.php">İlk tutanağı oluşturun</a>.
            </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div></div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
