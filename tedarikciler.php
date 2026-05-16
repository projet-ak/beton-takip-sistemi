<?php
require_once 'includes/db.php';
$pageTitle = 'Tedarikçiler - Beton Takip Sistemi';
$msg = '';

// Sil (soft delete)
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $kullanım = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE tedarikci_id = ?");
    $kullanım->execute([$id]);
    if ($kullanım->fetchColumn() > 0) {
        $msg = '<div class="alert alert-warning">Bu tedarikçiye ait irsaliyeler var, silemezsiniz. Pasif yapabilirsiniz.</div>';
    } else {
        $pdo->prepare("DELETE FROM tedarikciler WHERE id = ?")->execute([$id]);
        $msg = '<div class="alert alert-success">Tedarikçi silindi.</div>';
    }
}

// Pasif/Aktif toggle
if (isset($_GET['toggle']) && ctype_digit($_GET['toggle'])) {
    $pdo->prepare("UPDATE tedarikciler SET aktif = 1 - aktif WHERE id = ?")->execute([(int)$_GET['toggle']]);
}

// Kaydet / Güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id      = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $ad      = trim($_POST['ad'] ?? '');
    $telefon = trim($_POST['telefon'] ?? '');
    $adres   = trim($_POST['adres'] ?? '');

    if ($ad) {
        if ($id) {
            $pdo->prepare("UPDATE tedarikciler SET ad=?, telefon=?, adres=? WHERE id=?")->execute([$ad, $telefon, $adres, $id]);
            $msg = '<div class="alert alert-success">Tedarikçi güncellendi.</div>';
        } else {
            $pdo->prepare("INSERT INTO tedarikciler (ad, telefon, adres) VALUES (?,?,?)")->execute([$ad, $telefon, $adres]);
            $msg = '<div class="alert alert-success">Tedarikçi eklendi.</div>';
        }
    } else {
        $msg = '<div class="alert alert-danger">Tedarikçi adı zorunlu.</div>';
    }
}

$duzenle = null;
if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdo->prepare("SELECT * FROM tedarikciler WHERE id = ?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch();
}

$formAcik = isset($_GET['ekle']) || $duzenle;

$liste = $pdo->query("
    SELECT t.*, COUNT(i.id) AS irsaliye_adet, COALESCE(SUM(i.miktar_m3),0) AS toplam_m3
    FROM tedarikciler t
    LEFT JOIN irsaliyeler i ON i.tedarikci_id = t.id
    GROUP BY t.id
    ORDER BY t.ad
")->fetchAll();

require_once 'includes/header.php';
?>

<?= $msg ?>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header bg-primary text-white"><?= $duzenle ? 'Tedarikçi Düzenle' : 'Yeni Tedarikçi Ekle' ?></div>
    <div class="card-body">
        <form method="post">
            <?php if ($duzenle): ?><input type="hidden" name="id" value="<?= $duzenle['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Ad <span class="text-danger">*</span></label>
                    <input name="ad" class="form-control" required value="<?= htmlspecialchars($duzenle['ad'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Telefon</label>
                    <input name="telefon" class="form-control" value="<?= htmlspecialchars($duzenle['telefon'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Adres</label>
                    <input name="adres" class="form-control" value="<?= htmlspecialchars($duzenle['adres'] ?? '') ?>">
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-success"><?= $duzenle ? 'Güncelle' : 'Kaydet' ?></button>
                <a href="tedarikciler.php" class="btn btn-secondary">İptal</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-truck"></i> Tedarikçiler</span>
        <a href="tedarikciler.php?ekle=1" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Yeni Tedarikçi</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Ad</th>
                        <th>Telefon</th>
                        <th>Adres</th>
                        <th class="text-center">İrsaliye</th>
                        <th class="text-end">Toplam m³</th>
                        <th class="text-center">Durum</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $r): ?>
                    <tr class="<?= $r['aktif'] ? '' : 'text-muted' ?>">
                        <td class="fw-semibold"><?= htmlspecialchars($r['ad']) ?></td>
                        <td><?= htmlspecialchars($r['telefon'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($r['adres'] ?: '-') ?></td>
                        <td class="text-center"><?= (int)$r['irsaliye_adet'] ?></td>
                        <td class="text-end"><?= number_format($r['toplam_m3'], 2, ',', '.') ?></td>
                        <td class="text-center">
                            <a href="tedarikciler.php?toggle=<?= $r['id'] ?>" class="badge <?= $r['aktif'] ? 'bg-success' : 'bg-secondary' ?> text-decoration-none">
                                <?= $r['aktif'] ? 'Aktif' : 'Pasif' ?>
                            </a>
                        </td>
                        <td class="text-nowrap">
                            <a href="tedarikciler.php?duzenle=<?= $r['id'] ?>" class="btn btn-action btn-outline-primary btn-sm"><i class="bi bi-pencil"></i></a>
                            <a href="tedarikciler.php?sil=<?= $r['id'] ?>" class="btn btn-action btn-outline-danger btn-sm btn-delete"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$liste): ?>
                        <tr><td colspan="7" class="text-center text-muted py-5">Henüz tedarikçi yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
