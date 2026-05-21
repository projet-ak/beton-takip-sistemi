<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Kotlar — Beton Takip Sistemi';

// ── Sil ──────────────────────────────────────────────────────────────────────
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $k = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE kot_id = ?");
    $k->execute([$id]);
    if ($k->fetchColumn() > 0) {
        flash('warning', 'Bu kot irsaliyelerde kullanılıyor, silinemez.');
    } else {
        $pdo->prepare("DELETE FROM kotlar WHERE id = ?")->execute([$id]);
        flash('success', 'Kot silindi.');
    }
    redirect('kotlar.php');
}

// ── Düzenle ───────────────────────────────────────────────────────────────────
$formError = '';
$duzenle   = null;

if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdo->prepare("SELECT * FROM kotlar WHERE id = ?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

// ── Kaydet / Güncelle ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id         = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $kot_degeri = trim($_POST['kot_degeri'] ?? '');
    $blok_id    = isset($_POST['blok_id']) && ctype_digit($_POST['blok_id']) ? (int)$_POST['blok_id'] : null;
    $sira       = isset($_POST['sira']) && is_numeric($_POST['sira']) ? (int)$_POST['sira'] : 0;
    if (!$kot_degeri || !$blok_id) {
        $formError = 'Kot değeri ve blok alanları zorunludur.';
    } else {
        try {
            if ($id) {
                $pdo->prepare("UPDATE kotlar SET kot_degeri = ?, blok_id = ?, sira = ? WHERE id = ?")
                    ->execute([$kot_degeri, $blok_id, $sira, $id]);
                flash('success', 'Kot güncellendi.');
            } else {
                $pdo->prepare("INSERT INTO kotlar (kot_degeri, blok_id, sira) VALUES (?, ?, ?)")
                    ->execute([$kot_degeri, $blok_id, $sira]);
                flash('success', 'Kot eklendi.');
            }
            redirect('kotlar.php');
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

// ── Bloklar dropdown (parsel adı / blok adı) ──────────────────────────────────
$bloklar = $pdo->query("
    SELECT b.id, CONCAT(p.ad, ' / ', b.ad) AS etiket
    FROM bloklar b
    JOIN parseller p ON p.id = b.parsel_id
    ORDER BY p.ad, b.ad
")->fetchAll();

// ── Liste ─────────────────────────────────────────────────────────────────────
$liste = $pdo->query("
    SELECT k.*, b.ad AS blok_adi, p.ad AS parsel_adi
    FROM kotlar k
    JOIN bloklar b ON b.id = k.blok_id
    JOIN parseller p ON p.id = b.parsel_id
    ORDER BY p.ad, b.ad, k.sira, k.kot_degeri
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-arrow-up-square text-primary me-2"></i>Kotlar</h4>
    <a href="kotlar.php?ekle=1" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i> Yeni Kot
    </a>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle ? 'bg-warning text-dark' : 'bg-primary text-white' ?> fw-semibold">
        <i class="bi bi-<?= $duzenle ? 'pencil' : 'plus-circle' ?> me-1"></i>
        <?= $duzenle ? 'Kot Düzenle' : 'Yeni Kot Ekle' ?>
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
                    <label class="form-label">Blok <span class="text-danger">*</span></label>
                    <select name="blok_id" class="form-select" required>
                        <option value="">— Seçiniz —</option>
                        <?php foreach ($bloklar as $b): ?>
                            <option value="<?= $b['id'] ?>"
                                <?= ((int)($duzenle['blok_id'] ?? 0) === (int)$b['id']) ? 'selected' : '' ?>>
                                <?= h($b['etiket']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Kot Değeri <span class="text-danger">*</span></label>
                    <input name="kot_degeri" class="form-control" required value="<?= h($duzenle['kot_degeri'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sıra</label>
                    <input name="sira" type="number" class="form-control" value="<?= (int)($duzenle['sira'] ?? 0) ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle ? 'Güncelle' : 'Kaydet' ?></button>
                    <a href="kotlar.php" class="btn btn-secondary"><i class="bi bi-x-circle me-1"></i>İptal</a>
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
                        <th>Blok</th>
                        <th>Kot Değeri</th>
                        <th class="text-center">Sıra</th>
                        <th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $r): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td><?= h($r['parsel_adi']) ?></td>
                        <td><?= h($r['blok_adi']) ?></td>
                        <td class="fw-semibold"><?= h($r['kot_degeri']) ?></td>
                        <td class="text-center text-muted"><?= (int)$r['sira'] ?></td>
                        <td class="text-end text-nowrap">
                            <a href="kotlar.php?duzenle=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="kotlar.php?sil=<?= $r['id'] ?>"
                               class="btn btn-xs btn-outline-danger btn-confirm"
                               data-msg="Bu kotu silmek istediğinize emin misiniz?">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$liste): ?>
                        <tr><td colspan="6" class="text-center text-muted py-5">Henüz kot yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
