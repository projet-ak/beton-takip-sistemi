<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Projeler — Beton Takip Sistemi';

// ── Sil ──────────────────────────────────────────────────────────────────────
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $ki = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE proje_id = ?");
    $ki->execute([$id]);
    if ($ki->fetchColumn() > 0) {
        flash('warning', 'Bu proje irsaliyelerde kullanılıyor, silinemez.');
    } else {
        $pdo->prepare("DELETE FROM projeler WHERE id = ?")->execute([$id]);
        flash('success', 'Proje silindi.');
    }
    redirect('projeler.php');
}

// ── Aktif/Pasif toggle ────────────────────────────────────────────────────────
if (isset($_GET['toggle']) && ctype_digit($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $pdo->prepare("UPDATE projeler SET aktif = 1 - aktif WHERE id = ?")->execute([$id]);
    flash('success', 'Proje durumu güncellendi.');
    redirect('projeler.php');
}

// ── Düzenle ───────────────────────────────────────────────────────────────────
$formError = '';
$duzenle   = null;

if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdo->prepare("SELECT * FROM projeler WHERE id = ?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

// ── Kaydet / Güncelle ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $kod      = trim($_POST['kod']      ?? '');
    $aciklama = trim($_POST['aciklama'] ?? '');

    if (!$kod || !$aciklama) {
        $formError = 'Kod ve Açıklama alanları zorunludur.';
    } else {
        try {
            if ($id) {
                $pdo->prepare("UPDATE projeler SET kod = ?, aciklama = ? WHERE id = ?")
                    ->execute([$kod, $aciklama, $id]);
                flash('success', 'Proje güncellendi.');
            } else {
                $pdo->prepare("INSERT INTO projeler (kod, aciklama) VALUES (?, ?)")
                    ->execute([$kod, $aciklama]);
                flash('success', 'Proje eklendi.');
            }
            redirect('projeler.php');
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $formError = 'Bu proje kodu zaten kayıtlı.';
            } else {
                throw $e;
            }
        }
    }
}

$formAcik = isset($_GET['ekle']) || $duzenle;

// ── Liste (irsaliye sayısı dahil) ─────────────────────────────────────────────
$liste = $pdo->query("
    SELECT p.*, COUNT(i.id) AS irsaliye_sayisi
    FROM projeler p
    LEFT JOIN irsaliyeler i ON i.proje_id = p.id
    GROUP BY p.id
    ORDER BY p.kod
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-diagram-3 text-primary me-2"></i>Projeler</h4>
    <a href="projeler.php?ekle=1" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i> Yeni Proje
    </a>
</div>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle ? 'bg-warning text-dark' : 'bg-primary text-white' ?> fw-semibold">
        <i class="bi bi-<?= $duzenle ? 'pencil' : 'plus-circle' ?> me-1"></i>
        <?= $duzenle ? 'Proje Düzenle' : 'Yeni Proje Ekle' ?>
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
                <div class="col-md-3">
                    <label class="form-label">Kod <span class="text-danger">*</span></label>
                    <input name="kod" class="form-control" required maxlength="20"
                           value="<?= h($duzenle['kod'] ?? '') ?>" placeholder="U030">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Açıklama <span class="text-danger">*</span></label>
                    <input name="aciklama" class="form-control" required maxlength="200"
                           value="<?= h($duzenle['aciklama'] ?? '') ?>" placeholder="Proje adı / açıklaması">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-success">
                        <i class="bi bi-save me-1"></i><?= $duzenle ? 'Güncelle' : 'Kaydet' ?>
                    </button>
                    <a href="projeler.php" class="btn btn-secondary">
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
                        <th>Kod</th>
                        <th>Açıklama</th>
                        <th class="text-center">Durum</th>
                        <th class="text-center">İrsaliye Sayısı</th>
                        <th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $r): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td class="fw-bold font-monospace"><?= h($r['kod']) ?></td>
                        <td><?= h($r['aciklama']) ?></td>
                        <td class="text-center">
                            <a href="projeler.php?toggle=<?= $r['id'] ?>"
                               class="badge <?= $r['aktif'] ? 'bg-success' : 'bg-secondary' ?> text-decoration-none"
                               title="Durumu değiştirmek için tıklayın">
                                <?= $r['aktif'] ? 'Aktif' : 'Pasif' ?>
                            </a>
                        </td>
                        <td class="text-center">
                            <?php if ($r['irsaliye_sayisi'] > 0): ?>
                                <span class="badge bg-primary"><?= (int)$r['irsaliye_sayisi'] ?></span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="projeler.php?duzenle=<?= $r['id'] ?>"
                               class="btn btn-xs btn-outline-primary me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="projeler.php?sil=<?= $r['id'] ?>"
                               class="btn btn-xs btn-outline-danger btn-confirm"
                               data-msg="Bu projeyi silmek istediğinize emin misiniz?">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$liste): ?>
                        <tr><td colspan="6" class="text-center text-muted py-5">Henüz proje yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
