<?php
/**
 * demir/taseronlar.php — Demir teslim edilen taşeronlar (DENER, OSMAN CAMCI…)
 * "kod" alanı tutanak numarası önekinde kullanılır (ör. U030-DNR-005).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Demir Taşeronları — Demir Takip';

if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $c = $pdoDemir->prepare("SELECT COUNT(*) FROM demir_sevkiyatlar WHERE taseron_id=?"); $c->execute([$id]);
    $c2 = $pdoDemir->prepare("SELECT COUNT(*) FROM demir_tutanaklar WHERE taseron_id=?"); $c2->execute([$id]);
    if ($c->fetchColumn() > 0 || $c2->fetchColumn() > 0) {
        flash('warning', 'Bu taşeron kayıtlarda kullanılıyor, silinemez.');
    } else {
        $pdoDemir->prepare("DELETE FROM demir_taseronlar WHERE id=?")->execute([$id]);
        flash('success', 'Taşeron silindi.');
    }
    redirect('taseronlar.php');
}

$duzenle = null;
if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdoDemir->prepare("SELECT * FROM demir_taseronlar WHERE id=?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $ad  = trim($_POST['ad'] ?? '');
    $kod = strtoupper(trim($_POST['kod'] ?? ''));
    $vkn = trim($_POST['vkn'] ?? '');
    $tel = trim($_POST['telefon'] ?? '');
    if (!$ad) {
        $formError = 'Ad zorunludur.';
    } else {
        $dup = $pdoDemir->prepare($id
            ? "SELECT COUNT(*) FROM demir_taseronlar WHERE UPPER(ad)=UPPER(?) AND id!=?"
            : "SELECT COUNT(*) FROM demir_taseronlar WHERE UPPER(ad)=UPPER(?)");
        $dup->execute($id ? [$ad,$id] : [$ad]);
        if ($dup->fetchColumn() > 0) {
            $formError = '"'.h($ad).'" zaten kayıtlı.';
        } else {
            if ($id) {
                $pdoDemir->prepare("UPDATE demir_taseronlar SET ad=?, kod=?, vkn=?, telefon=? WHERE id=?")->execute([$ad,$kod?:null,$vkn?:null,$tel?:null,$id]);
                flash('success','Taşeron güncellendi.');
            } else {
                $pdoDemir->prepare("INSERT INTO demir_taseronlar (ad,kod,vkn,telefon) VALUES (?,?,?,?)")->execute([$ad,$kod?:null,$vkn?:null,$tel?:null]);
                flash('success','Taşeron eklendi.');
            }
            redirect('taseronlar.php');
        }
    }
}

$formAcik = isset($_GET['ekle']) || $duzenle;
$liste = $pdoDemir->query("SELECT * FROM demir_taseronlar ORDER BY ad")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-people text-dark me-2"></i>Demir Taşeronları</h4>
    <a href="taseronlar.php?ekle=1" class="btn btn-dark"><i class="bi bi-plus-circle me-1"></i> Yeni Taşeron</a>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle?'bg-warning text-dark':'bg-dark text-white' ?> fw-semibold"><?= $duzenle?'Taşeron Düzenle':'Yeni Taşeron' ?></div>
    <div class="card-body">
        <?php if ($formError): ?><div class="alert alert-danger"><?= h($formError) ?></div><?php endif; ?>
        <form method="post">
            <?php if ($duzenle): ?><input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>"><?php endif; ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-4"><label class="form-label">Ad <span class="text-danger">*</span></label><input name="ad" class="form-control" required value="<?= h($duzenle['ad'] ?? '') ?>"></div>
                <div class="col-md-2"><label class="form-label">Kod <small class="text-muted">(tutanak öneki)</small></label><input name="kod" class="form-control text-uppercase" value="<?= h($duzenle['kod'] ?? '') ?>" placeholder="DNR"></div>
                <div class="col-md-2"><label class="form-label">VKN</label><input name="vkn" class="form-control" value="<?= h($duzenle['vkn'] ?? '') ?>"></div>
                <div class="col-md-2"><label class="form-label">Telefon</label><input name="telefon" class="form-control" value="<?= h($duzenle['telefon'] ?? '') ?>"></div>
                <div class="col-md-2 d-flex gap-2"><button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle?'Güncelle':'Kaydet' ?></button><a href="taseronlar.php" class="btn btn-secondary">İptal</a></div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>#</th><th>Ad</th><th>Kod</th><th>VKN</th><th>Telefon</th><th class="text-end">İşlem</th></tr></thead>
    <tbody>
        <?php foreach ($liste as $r): ?>
        <tr>
            <td class="text-muted small"><?= (int)$r['id'] ?></td>
            <td class="fw-semibold"><?= h($r['ad']) ?></td>
            <td><?= $r['kod'] ? '<span class="badge bg-secondary">'.h($r['kod']).'</span>' : '—' ?></td>
            <td><?= h($r['vkn'] ?: '—') ?></td>
            <td><?= h($r['telefon'] ?: '—') ?></td>
            <td class="text-end text-nowrap">
                <a href="taseronlar.php?duzenle=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                <a href="taseronlar.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Silinsin mi?"><i class="bi bi-trash"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$liste): ?><tr><td colspan="6" class="text-center text-muted py-5">Henüz taşeron yok.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
