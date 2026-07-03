<?php
/**
 * demir/projeler.php — Demir projeleri (Proje Kodu + Proje Adı), mükerrer önlemeli
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Demir Projeleri — Demir Takip';

// ── Sil ───────────────────────────────────────────────────────────────────────
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $c = $pdoDemir->prepare("SELECT COUNT(*) FROM demir_sevkiyatlar WHERE proje_id=?"); $c->execute([$id]);
    $c2 = $pdoDemir->prepare("SELECT COUNT(*) FROM demir_siparisler WHERE proje_id=?"); $c2->execute([$id]);
    if ($c->fetchColumn() > 0 || $c2->fetchColumn() > 0) {
        flash('warning', 'Bu proje sevkiyat/siparişlerde kullanılıyor, silinemez.');
    } else {
        $pdoDemir->prepare("DELETE FROM demir_projeler WHERE id=?")->execute([$id]);
        flash('success', 'Proje silindi.');
    }
    redirect('projeler.php');
}

$duzenle = null;
if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdoDemir->prepare("SELECT * FROM demir_projeler WHERE id=?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $kod = strtoupper(trim($_POST['kod'] ?? ''));
    $ad  = trim($_POST['aciklama'] ?? '');
    if (!$kod)      $formError = 'Proje kodu zorunludur.';
    elseif (!$ad)   $formError = 'Proje adı zorunludur.';
    else {
        // Mükerrer: aynı kod VEYA aynı ad
        $dupK = $pdoDemir->prepare($id ? "SELECT COUNT(*) FROM demir_projeler WHERE UPPER(kod)=UPPER(?) AND id!=?" : "SELECT COUNT(*) FROM demir_projeler WHERE UPPER(kod)=UPPER(?)");
        $dupK->execute($id ? [$kod,$id] : [$kod]);
        $dupA = $pdoDemir->prepare($id ? "SELECT COUNT(*) FROM demir_projeler WHERE UPPER(aciklama)=UPPER(?) AND id!=?" : "SELECT COUNT(*) FROM demir_projeler WHERE UPPER(aciklama)=UPPER(?)");
        $dupA->execute($id ? [$ad,$id] : [$ad]);
        if ($dupK->fetchColumn() > 0)      $formError = '"'.h($kod).'" kodlu proje zaten var.';
        elseif ($dupA->fetchColumn() > 0)  $formError = '"'.h($ad).'" adlı proje zaten var.';
        else {
            if ($id) {
                $pdoDemir->prepare("UPDATE demir_projeler SET kod=?, aciklama=? WHERE id=?")->execute([$kod,$ad,$id]);
                flash('success','Proje güncellendi.');
            } else {
                $pdoDemir->prepare("INSERT INTO demir_projeler (kod, aciklama) VALUES (?,?)")->execute([$kod,$ad]);
                flash('success','Proje eklendi.');
            }
            redirect('projeler.php');
        }
    }
}

$formAcik = isset($_GET['ekle']) || $duzenle;
$liste = $pdoDemir->query("
    SELECT p.*,
        (SELECT COUNT(*) FROM demir_sevkiyatlar WHERE proje_id=p.id) sevk,
        (SELECT COUNT(*) FROM demir_siparisler WHERE proje_id=p.id) sip,
        (SELECT COALESCE(SUM(sk.irsaliye_miktar),0) FROM demir_sevkiyatlar s JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id=s.id WHERE s.proje_id=p.id) ton
    FROM demir_projeler p ORDER BY p.kod")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-diagram-3 text-dark me-2"></i>Demir Projeleri</h4>
    <a href="projeler.php?ekle=1" class="btn btn-dark"><i class="bi bi-plus-circle me-1"></i> Yeni Proje</a>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle?'bg-warning text-dark':'bg-dark text-white' ?> fw-semibold"><?= $duzenle?'Proje Düzenle':'Yeni Proje' ?></div>
    <div class="card-body">
        <?php if ($formError): ?><div class="alert alert-danger"><?= h($formError) ?></div><?php endif; ?>
        <form method="post">
            <?php if ($duzenle): ?><input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>"><?php endif; ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-3"><label class="form-label">Proje Kodu <span class="text-danger">*</span></label><input name="kod" class="form-control text-uppercase" required value="<?= h($duzenle['kod'] ?? '') ?>" placeholder="U030"></div>
                <div class="col-md-6"><label class="form-label">Proje Adı <span class="text-danger">*</span></label><input name="aciklama" class="form-control" required value="<?= h($duzenle['aciklama'] ?? '') ?>" placeholder="BATI YAKASI 1. ETAP"></div>
                <div class="col-md-3 d-flex gap-2"><button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle?'Güncelle':'Kaydet' ?></button><a href="projeler.php" class="btn btn-secondary">İptal</a></div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Kod</th><th>Proje Adı</th><th class="text-center">Sipariş</th><th class="text-center">Sevkiyat</th><th class="text-end">Gelen (t)</th><th class="text-end">İşlem</th></tr></thead>
    <tbody>
        <?php foreach ($liste as $r): ?>
        <tr>
            <td class="fw-semibold"><a href="proje_detay.php?id=<?= $r['id'] ?>" class="text-decoration-none"><span class="badge bg-dark"><?= h($r['kod']) ?></span></a></td>
            <td><a href="proje_detay.php?id=<?= $r['id'] ?>" class="text-decoration-none text-reset fw-semibold"><?= h($r['aciklama']) ?></a></td>
            <td class="text-center"><?= (int)$r['sip'] ?></td>
            <td class="text-center"><?= (int)$r['sevk'] ?></td>
            <td class="text-end fw-semibold"><?= $fmt($r['ton']) ?></td>
            <td class="text-end text-nowrap">
                <a href="proje_detay.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-secondary me-1" title="Detay"><i class="bi bi-eye"></i></a>
                <a href="projeler.php?duzenle=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                <a href="projeler.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Bu projeyi silmek istiyor musunuz?"><i class="bi bi-trash"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$liste): ?><tr><td colspan="6" class="text-center text-muted py-5">Henüz proje yok.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
