<?php
/**
 * demir/sevkiyatlar.php — Demir sevkiyatları listesi (kantar farkı ile)
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Demir Sevkiyatları — Demir Takip';

// ── Sil ───────────────────────────────────────────────────────────────────────
if (can_edit() && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $pdoDemir->prepare("DELETE FROM demir_sevkiyatlar WHERE id=?")->execute([(int)$_GET['sil']]);
    flash('success', 'Sevkiyat silindi.');
    redirect('sevkiyatlar.php');
}

// ── Filtreler ─────────────────────────────────────────────────────────────────
$fProje = isset($_GET['proje']) && ctype_digit($_GET['proje']) ? (int)$_GET['proje'] : 0;
$fTed   = isset($_GET['tedarikci']) && ctype_digit($_GET['tedarikci']) ? (int)$_GET['tedarikci'] : 0;
$fAra   = trim($_GET['ara'] ?? '');

$where = []; $params = [];
if ($fProje) { $where[] = 's.proje_id = ?';     $params[] = $fProje; }
if ($fTed)   { $where[] = 's.tedarikci_id = ?'; $params[] = $fTed; }
if ($fAra)   { $where[] = '(s.irsaliye_no LIKE ? OR s.arac_plaka LIKE ? OR s.kantar_fis_no LIKE ?)';
               $params[] = "%$fAra%"; $params[] = "%$fAra%"; $params[] = "%$fAra%"; }
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$liste = $pdoDemir->prepare("
    SELECT s.*,
           t.ad AS tedarikci_adi, p.kod AS proje_kod, ta.ad AS taseron_adi,
           COALESCE(k.top_irs,0) AS top_irs,
           COALESCE(k.top_knt,0) AS top_knt,
           COALESCE(k.top_knt,0) - COALESCE(k.top_irs,0) AS fark,
           COALESCE(k.cap_sayi,0) AS cap_sayi
    FROM demir_sevkiyatlar s
    LEFT JOIN demir_tedarikciler t ON t.id = s.tedarikci_id
    LEFT JOIN demir_projeler p     ON p.id = s.proje_id
    LEFT JOIN demir_taseronlar ta  ON ta.id = s.taseron_id
    LEFT JOIN (
        SELECT sevkiyat_id,
               SUM(irsaliye_miktar) AS top_irs,
               SUM(kantar_miktar)   AS top_knt,
               COUNT(*)             AS cap_sayi
        FROM demir_sevkiyat_kalemleri GROUP BY sevkiyat_id
    ) k ON k.sevkiyat_id = s.id
    $whereSQL
    ORDER BY s.irsaliye_tarih DESC, s.id DESC
");
$liste->execute($params);
$liste = $liste->fetchAll();

$topIrs  = array_sum(array_column($liste, 'top_irs'));
$topFark = array_sum(array_column($liste, 'fark'));

$projeler     = $pdoDemir->query("SELECT id,kod FROM demir_projeler WHERE aktif=1 ORDER BY kod")->fetchAll();
$tedarikciler = $pdoDemir->query("SELECT id,ad FROM demir_tedarikciler WHERE aktif=1 ORDER BY ad")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-truck text-dark me-2"></i>Demir Sevkiyatları</h4>
        <small class="text-muted"><?= count($liste) ?> kayıt — Toplam <strong><?= number_format($topIrs,3,',','.') ?> t</strong> · Kantar farkı <strong class="<?= $topFark<0?'text-danger':($topFark>0?'text-success':'') ?>"><?= ($topFark>0?'+':'').number_format($topFark,3,',','.') ?> t</strong></small>
    </div>
    <?php if (has_role('admin','teknik_ofis_admin','saha_sefi','depo')): ?>
    <a href="sevkiyat_form.php" class="btn btn-dark"><i class="bi bi-plus-circle me-1"></i> Yeni Sevkiyat</a>
    <?php endif; ?>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-4"><label class="form-label small">Ara (irsaliye / plaka / kantar fiş)</label><input name="ara" class="form-control form-control-sm" value="<?= h($fAra) ?>"></div>
            <div class="col-md-3"><label class="form-label small">Proje</label><select name="proje" class="form-select form-select-sm"><option value="">Tümü</option><?php foreach($projeler as $p): ?><option value="<?= $p['id'] ?>" <?= $fProje==$p['id']?'selected':'' ?>><?= h($p['kod']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small">Tedarikçi</label><select name="tedarikci" class="form-select form-select-sm"><option value="">Tümü</option><?php foreach($tedarikciler as $t): ?><option value="<?= $t['id'] ?>" <?= $fTed==$t['id']?'selected':'' ?>><?= h($t['ad']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-flex gap-1"><button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i> Filtrele</button><a href="sevkiyatlar.php" class="btn btn-outline-secondary btn-sm">Temizle</a></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th><th>İrsaliye No</th><th>Tarih</th><th>Tedarikçi</th><th>Proje</th><th>Taşeron</th>
                        <th class="text-end">İrsaliye (t)</th><th class="text-end">Kantar (t)</th><th class="text-end">Fark</th><th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $r): $fark=(float)$r['fark']; ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td class="fw-semibold font-monospace"><?= h($r['irsaliye_no']) ?></td>
                        <td><?= format_date($r['irsaliye_tarih']) ?></td>
                        <td><?= h($r['tedarikci_adi'] ?: '—') ?></td>
                        <td><?= $r['proje_kod'] ? '<span class="badge bg-secondary">'.h($r['proje_kod']).'</span>' : '—' ?></td>
                        <td><?= h($r['taseron_adi'] ?: '—') ?></td>
                        <td class="text-end"><?= number_format($r['top_irs'],3,',','.') ?></td>
                        <td class="text-end"><?= $r['top_knt']>0 ? number_format($r['top_knt'],3,',','.') : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end fw-semibold <?= abs($fark)<0.0005?'text-muted':($fark<0?'text-danger':'text-success') ?>">
                            <?= abs($fark)<0.0005 ? '0' : (($fark>0?'+':'').number_format($fark,3,',','.')) ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if (has_role('admin','teknik_ofis_admin','saha_sefi','depo')): ?>
                            <a href="sevkiyat_form.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (can_edit()): ?>
                            <a href="sevkiyatlar.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Bu sevkiyatı silmek istiyor musunuz?"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$liste): ?>
                    <tr><td colspan="10" class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                        Henüz sevkiyat kaydı yok. <a href="sevkiyat_form.php">İlk sevkiyatı ekleyin</a>.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
