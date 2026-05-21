<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Firmalar — Beton Takip Sistemi';

// ── Sil ──────────────────────────────────────────────────────────────────────
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $k = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE firma_id = ?");
    $k->execute([$id]);
    if ($k->fetchColumn() > 0) {
        flash('warning', 'Bu firma irsaliyelerde kullanılıyor, silinemez.');
    } else {
        $pdo->prepare("DELETE FROM firmalar WHERE id = ?")->execute([$id]);
        flash('success', 'Firma silindi.');
    }
    redirect('firmalar.php');
}

// ── Düzenle ───────────────────────────────────────────────────────────────────
$formError = '';
$duzenle   = null;

if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdo->prepare("SELECT * FROM firmalar WHERE id = ?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

// ── Kaydet / Güncelle ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $ad = trim($_POST['ad'] ?? '');
    if (!$ad) {
        $formError = 'Ad alanı zorunludur.';
    } else {
        try {
            if ($id) {
                $pdo->prepare("UPDATE firmalar SET ad = ? WHERE id = ?")->execute([$ad, $id]);
                flash('success', 'Firma güncellendi.');
            } else {
                $pdo->prepare("INSERT INTO firmalar (ad) VALUES (?)")->execute([$ad]);
                flash('success', 'Firma eklendi.');
            }
            redirect('firmalar.php');
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $formError = 'Bu isim zaten kayıtlı.';
            } else {
                throw $e;
            }
        }
    }
}

$formAcik = isset($_GET['ekle']) || $duzenle;

// ── Liste ─────────────────────────────────────────────────────────────────────
$liste = $pdo->query("SELECT * FROM firmalar ORDER BY ad")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-briefcase text-primary me-2"></i>Firmalar</h4>
    <a href="firmalar.php?ekle=1" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i> Yeni Firma
    </a>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle ? 'bg-warning text-dark' : 'bg-primary text-white' ?> fw-semibold">
        <i class="bi bi-<?= $duzenle ? 'pencil' : 'plus-circle' ?> me-1"></i>
        <?= $duzenle ? 'Firma Düzenle' : 'Yeni Firma Ekle' ?>
    </div>
    <div class="card-body">
        <?php if ($formError): ?>
            <div class="alert alert-danger"><?= h($formError) ?></div>
        <?php endif; ?>
        <form method="post">
            <?php if ($duzenle): ?>
                <input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>">
            <?php endif; ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Ad <span class="text-danger">*</span></label>
                    <input name="ad" class="form-control" required value="<?= h($duzenle['ad'] ?? '') ?>">
                </div>
                <div class="col-md-6 d-flex gap-2">
                    <button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle ? 'Güncelle' : 'Kaydet' ?></button>
                    <a href="firmalar.php" class="btn btn-secondary"><i class="bi bi-x-circle me-1"></i>İptal</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Ad</th>
                        <th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $r): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td class="fw-semibold"><?= h($r['ad']) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="firmalar.php?duzenle=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="firmalar.php?sil=<?= $r['id'] ?>"
                               class="btn btn-xs btn-outline-danger btn-confirm"
                               data-msg="Bu firmayı silmek istediğinize emin misiniz?">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$liste): ?>
                        <tr><td colspan="3" class="text-center text-muted py-5">Henüz firma yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
