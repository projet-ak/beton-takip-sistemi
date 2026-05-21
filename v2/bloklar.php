<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Bloklar — Beton Takip Sistemi';

// ── Sil ──────────────────────────────────────────────────────────────────────
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $ki = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE blok_id = ?");
    $ki->execute([$id]);
    $kk = $pdo->prepare("SELECT COUNT(*) FROM kotlar WHERE blok_id = ?");
    $kk->execute([$id]);
    if ($ki->fetchColumn() > 0 || $kk->fetchColumn() > 0) {
        flash('warning', 'Bu blok kot veya irsaliyelerde kullanılıyor, silinemez.');
    } else {
        $pdo->prepare("DELETE FROM bloklar WHERE id = ?")->execute([$id]);
        flash('success', 'Blok silindi.');
    }
    $back = isset($_GET['parsel_id']) && ctype_digit($_GET['parsel_id']) ? 'bloklar.php?parsel_id=' . (int)$_GET['parsel_id'] : 'bloklar.php';
    redirect($back);
}

// ── Filtre ────────────────────────────────────────────────────────────────────
$filterParselId = (isset($_GET['parsel_id']) && ctype_digit($_GET['parsel_id'])) ? (int)$_GET['parsel_id'] : null;

// ── Düzenle ───────────────────────────────────────────────────────────────────
$formError = '';
$duzenle   = null;

if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdo->prepare("SELECT * FROM bloklar WHERE id = ?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

// ── Kaydet / Güncelle ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id        = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $ad        = trim($_POST['ad'] ?? '');
    $parsel_id = isset($_POST['parsel_id']) && ctype_digit($_POST['parsel_id']) ? (int)$_POST['parsel_id'] : null;
    if (!$ad || !$parsel_id) {
        $formError = 'Ad ve parsel alanları zorunludur.';
    } else {
        try {
            if ($id) {
                $pdo->prepare("UPDATE bloklar SET ad = ?, parsel_id = ? WHERE id = ?")->execute([$ad, $parsel_id, $id]);
                flash('success', 'Blok güncellendi.');
            } else {
                $pdo->prepare("INSERT INTO bloklar (ad, parsel_id) VALUES (?, ?)")->execute([$ad, $parsel_id]);
                flash('success', 'Blok eklendi.');
            }
            redirect('bloklar.php' . ($filterParselId ? '?parsel_id=' . $filterParselId : ''));
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

// ── Parseller dropdown için ───────────────────────────────────────────────────
$parseller = $pdo->query("SELECT id, ad FROM parseller ORDER BY ad")->fetchAll();

// ── Liste ─────────────────────────────────────────────────────────────────────
if ($filterParselId) {
    $stmt = $pdo->prepare("
        SELECT b.*, p.ad AS parsel_adi
        FROM bloklar b
        JOIN parseller p ON p.id = b.parsel_id
        WHERE b.parsel_id = ?
        ORDER BY b.ad
    ");
    $stmt->execute([$filterParselId]);
} else {
    $stmt = $pdo->query("
        SELECT b.*, p.ad AS parsel_adi
        FROM bloklar b
        JOIN parseller p ON p.id = b.parsel_id
        ORDER BY p.ad, b.ad
    ");
}
$liste = $stmt->fetchAll();

// Filtre parsel adı
$filterParselAd = '';
if ($filterParselId) {
    $sp = $pdo->prepare("SELECT ad FROM parseller WHERE id = ?");
    $sp->execute([$filterParselId]);
    $filterParselAd = $sp->fetchColumn() ?: '';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bi bi-buildings text-primary me-2"></i>Bloklar
        <?php if ($filterParselAd): ?>
            <small class="text-muted fs-6 ms-2">— <?= h($filterParselAd) ?> parseli</small>
        <?php endif; ?>
    </h4>
    <div class="d-flex gap-2">
        <?php if ($filterParselId): ?>
            <a href="bloklar.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-x me-1"></i>Filtreyi Kaldır
            </a>
        <?php endif; ?>
        <a href="bloklar.php?ekle=1<?= $filterParselId ? '&parsel_id=' . $filterParselId : '' ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Yeni Blok
        </a>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle ? 'bg-warning text-dark' : 'bg-primary text-white' ?> fw-semibold">
        <i class="bi bi-<?= $duzenle ? 'pencil' : 'plus-circle' ?> me-1"></i>
        <?= $duzenle ? 'Blok Düzenle' : 'Yeni Blok Ekle' ?>
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
                <div class="col-md-4">
                    <label class="form-label">Parsel <span class="text-danger">*</span></label>
                    <select name="parsel_id" class="form-select" required>
                        <option value="">— Seçiniz —</option>
                        <?php foreach ($parseller as $p): ?>
                            <option value="<?= $p['id'] ?>"
                                <?= ((int)($duzenle['parsel_id'] ?? $filterParselId) === (int)$p['id']) ? 'selected' : '' ?>>
                                <?= h($p['ad']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Blok Adı <span class="text-danger">*</span></label>
                    <input name="ad" class="form-control" required value="<?= h($duzenle['ad'] ?? '') ?>">
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle ? 'Güncelle' : 'Kaydet' ?></button>
                    <a href="bloklar.php<?= $filterParselId ? '?parsel_id=' . $filterParselId : '' ?>" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-1"></i>İptal
                    </a>
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
                        <th>Parsel</th>
                        <th>Blok Adı</th>
                        <th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $r): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td><?= h($r['parsel_adi']) ?></td>
                        <td class="fw-semibold"><?= h($r['ad']) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="bloklar.php?duzenle=<?= $r['id'] ?><?= $filterParselId ? '&parsel_id=' . $filterParselId : '' ?>"
                               class="btn btn-xs btn-outline-primary me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="bloklar.php?sil=<?= $r['id'] ?><?= $filterParselId ? '&parsel_id=' . $filterParselId : '' ?>"
                               class="btn btn-xs btn-outline-danger btn-confirm"
                               data-msg="Bu bloğu silmek istediğinize emin misiniz?">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$liste): ?>
                        <tr><td colspan="4" class="text-center text-muted py-5">Henüz blok yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
