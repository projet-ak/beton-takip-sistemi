<?php
/**
 * demir/iade_tutanaklar.php — İade tutanakları listesi
 * Bir taşeronun elinde kalan demiri iade etmesi (depoya veya başka taşerona).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/_iade_ortak.php';
iade_semasi_kur($pdoDemir);

$pageTitle = 'İade Tutanakları — Demir Takip';

if (has_role('admin','teknik_ofis_admin') && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $sid = (int)$_GET['sil'];
    $pdoDemir->prepare("DELETE FROM demir_iade_kalemleri WHERE iade_id=?")->execute([$sid]);
    $pdoDemir->prepare("DELETE FROM demir_iade_tutanaklar WHERE id=?")->execute([$sid]);
    flash('success', 'İade tutanağı silindi.');
    redirect('iade_tutanaklar.php');
}

$fTas = isset($_GET['taseron']) && ctype_digit($_GET['taseron']) ? (int)$_GET['taseron'] : 0;
$where = []; $params = [];
if ($fTas) { $where[] = '(iu.iade_eden_id = ? OR iu.teslim_alan_id = ?)'; $params[] = $fTas; $params[] = $fTas; }
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$liste = $pdoDemir->prepare("
    SELECT iu.*, ie.ad AS iade_eden_adi, ta.ad AS teslim_alan_adi, p.kod AS proje_kod,
           COALESCE(k.top_ton,0) AS top_ton, COALESCE(k.top_bag,0) AS top_bag, COALESCE(k.adet,0) AS kalem
    FROM demir_iade_tutanaklar iu
    LEFT JOIN demir_taseronlar ie ON ie.id = iu.iade_eden_id
    LEFT JOIN demir_taseronlar ta ON ta.id = iu.teslim_alan_id
    LEFT JOIN demir_projeler p ON p.id = iu.proje_id
    LEFT JOIN (SELECT iade_id, SUM(miktar_ton) top_ton, SUM(bag_adeti) top_bag, COUNT(*) adet
               FROM demir_iade_kalemleri GROUP BY iade_id) k ON k.iade_id = iu.id
    $whereSQL
    ORDER BY iu.iade_tarih DESC, iu.id DESC
");
$liste->execute($params);
$liste = $liste->fetchAll();
$topGenel = array_sum(array_map(fn($r)=>(float)$r['top_ton'], $liste));

$taseronlar = $pdoDemir->query("SELECT id,ad FROM demir_taseronlar WHERE aktif=1 ORDER BY ad")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-arrow-return-left text-dark me-2"></i>İade Tutanakları</h4>
        <small class="text-muted">İş sonunda taşeronun elinde kalan demirin iadesi (depoya veya başka taşerona)</small>
    </div>
    <div class="d-flex gap-2">
        <a href="taseron_bakiye.php" class="btn btn-outline-dark"><i class="bi bi-wallet2 me-1"></i> Taşeron Bakiye</a>
        <?php if (has_role('admin','teknik_ofis_admin','teknik_ofis','saha_sefi')): ?>
        <a href="iade_form.php" class="btn btn-dark"><i class="bi bi-plus-circle me-1"></i> Yeni İade</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="card mb-3"><div class="card-body">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-4"><label class="form-label small">Taşeron (iade eden veya teslim alan)</label><select name="taseron" class="form-select form-select-sm"><option value="">Tümü</option><?php foreach($taseronlar as $t): ?><option value="<?= $t['id'] ?>" <?= $fTas==$t['id']?'selected':'' ?>><?= h($t['ad']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex gap-1"><button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-funnel"></i> Filtrele</button><a href="iade_tutanaklar.php" class="btn btn-outline-secondary btn-sm">Temizle</a></div>
        <div class="col-md-5 text-md-end"><span class="text-muted small">Toplam iade:</span> <strong><?= $fmt($topGenel) ?> t</strong> · <?= count($liste) ?> tutanak</div>
    </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th>İade No</th><th>Tarih</th><th>İade Eden</th><th>Teslim Alan</th><th>Proje</th>
            <th class="text-end">Tonaj (t)</th><th class="text-end">Bağ</th><th class="text-center">Evrak</th><th class="text-end">İşlem</th>
        </tr></thead>
        <tbody>
            <?php foreach ($liste as $r): ?>
            <tr>
                <td class="fw-semibold font-monospace"><a href="iade_detay.php?id=<?= $r['id'] ?>" class="text-decoration-none"><?= h($r['iade_no']) ?></a></td>
                <td><?= format_date($r['iade_tarih']) ?></td>
                <td><?= h($r['iade_eden_adi'] ?: '—') ?></td>
                <td><?= $r['teslim_alan_adi'] ? h($r['teslim_alan_adi']) : '<span class="badge bg-light text-muted border">Depo / Şirket</span>' ?></td>
                <td><?= $r['proje_kod'] ? '<span class="badge bg-secondary">'.h($r['proje_kod']).'</span>' : '—' ?></td>
                <td class="text-end fw-semibold"><?= $fmt($r['top_ton']) ?></td>
                <td class="text-end"><?= (int)$r['top_bag'] ?></td>
                <td class="text-center">
                    <?php if ($r['evrak_url']): ?><span class="badge bg-success"><i class="bi bi-check-lg"></i> Yüklü</span>
                    <?php else: ?><span class="badge bg-light text-muted border">Yok</span><?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                    <a href="iade_pdf.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-xs btn-outline-dark me-1" title="Yazdır / PDF"><i class="bi bi-printer"></i></a>
                    <a href="iade_detay.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-secondary me-1" title="Detay"><i class="bi bi-eye"></i></a>
                    <?php if (has_role('admin','teknik_ofis_admin','teknik_ofis','saha_sefi')): ?>
                    <a href="iade_form.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                    <?php endif; ?>
                    <?php if (has_role('admin','teknik_ofis_admin')): ?>
                    <a href="iade_tutanaklar.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Bu iade tutanağını silmek istiyor musunuz?"><i class="bi bi-trash"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$liste): ?>
            <tr><td colspan="9" class="text-center text-muted py-5">
                <i class="bi bi-arrow-return-left fs-1 d-block mb-2 opacity-50"></i>
                Henüz iade tutanağı yok. <a href="iade_form.php">İlk iadeyi oluşturun</a>.
            </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div></div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
