<?php
/**
 * demir/caplar.php — Demir çapları (Ø8…Ø32, kangal, hasır, spiral)
 * Her çap için teorik birim ağırlık (kg/m) ve 1 bağ ortalama kg tutulur.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Demir Çapları — Demir Takip';
$tipler = ['duz'=>'Düz Demir','kangal'=>'Kangal','hasir'=>'Çelik Hasır','spiral'=>'Spiral'];

// ── Sil ───────────────────────────────────────────────────────────────────────
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $c1 = $pdoDemir->prepare("SELECT COUNT(*) FROM demir_sevkiyat_kalemleri WHERE cap_id=?"); $c1->execute([$id]);
    if ($c1->fetchColumn() > 0) {
        flash('warning', 'Bu çap sevkiyatlarda kullanılıyor, silinemez.');
    } else {
        $pdoDemir->prepare("DELETE FROM demir_caplar WHERE id=?")->execute([$id]);
        flash('success', 'Çap silindi.');
    }
    redirect('caplar.php');
}

// ── Düzenle verisi ─────────────────────────────────────────────────────────────
$duzenle = null;
if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdoDemir->prepare("SELECT * FROM demir_caplar WHERE id=?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

// ── Kaydet / Güncelle ──────────────────────────────────────────────────────────
$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $ad  = trim($_POST['ad'] ?? '');
    $tip = array_key_exists($_POST['tip'] ?? '', $tipler) ? $_POST['tip'] : 'duz';
    $bag = trim($_POST['birim_agirlik'] ?? '') === '' ? null : (float)str_replace(',', '.', $_POST['birim_agirlik']);
    $bkg = trim($_POST['bag_kg'] ?? '') === '' ? null : (float)str_replace(',', '.', $_POST['bag_kg']);
    $sira= (int)($_POST['sira'] ?? 0);
    if (!$ad) {
        $formError = 'Ad zorunludur.';
    } else {
        if ($id) {
            $pdoDemir->prepare("UPDATE demir_caplar SET ad=?, tip=?, birim_agirlik=?, bag_kg=?, sira=? WHERE id=?")
                     ->execute([$ad,$tip,$bag,$bkg,$sira,$id]);
            flash('success', 'Çap güncellendi.');
        } else {
            $pdoDemir->prepare("INSERT INTO demir_caplar (ad,tip,birim_agirlik,bag_kg,sira) VALUES (?,?,?,?,?)")
                     ->execute([$ad,$tip,$bag,$bkg,$sira]);
            flash('success', 'Çap eklendi.');
        }
        redirect('caplar.php');
    }
}

$formAcik = isset($_GET['ekle']) || $duzenle;
$liste = $pdoDemir->query("SELECT * FROM demir_caplar ORDER BY sira, ad")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-rulers text-dark me-2"></i>Demir Çapları</h4>
    <a href="caplar.php?ekle=1" class="btn btn-dark"><i class="bi bi-plus-circle me-1"></i> Yeni Çap</a>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle?'bg-warning text-dark':'bg-dark text-white' ?> fw-semibold">
        <?= $duzenle ? 'Çap Düzenle' : 'Yeni Çap' ?>
    </div>
    <div class="card-body">
        <?php if ($formError): ?><div class="alert alert-danger"><?= h($formError) ?></div><?php endif; ?>
        <form method="post">
            <?php if ($duzenle): ?><input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>"><?php endif; ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Ad <span class="text-danger">*</span></label>
                    <input name="ad" class="form-control" required value="<?= h($duzenle['ad'] ?? '') ?>" placeholder="Ø16">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tür</label>
                    <select name="tip" class="form-select">
                        <?php foreach($tipler as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= ($duzenle['tip']??'duz')===$k?'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Birim Ağırlık <small class="text-muted">(kg/m)</small></label>
                    <input name="birim_agirlik" class="form-control" value="<?= h($duzenle['birim_agirlik'] ?? '') ?>" placeholder="1.578">
                </div>
                <div class="col-md-2">
                    <label class="form-label">1 Bağ <small class="text-muted">(kg)</small></label>
                    <input name="bag_kg" class="form-control" value="<?= h($duzenle['bag_kg'] ?? '') ?>" placeholder="opsiyonel">
                </div>
                <div class="col-md-1">
                    <label class="form-label">Sıra</label>
                    <input name="sira" type="number" class="form-control" value="<?= h($duzenle['sira'] ?? 0) ?>">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle?'Güncelle':'Kaydet' ?></button>
                    <a href="caplar.php" class="btn btn-secondary">İptal</a>
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
                        <th>#</th><th>Ad</th><th>Tür</th>
                        <th class="text-end">Birim Ağırlık (kg/m)</th>
                        <th class="text-end">1 Bağ (kg)</th>
                        <th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $r): ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td class="fw-semibold"><?= h($r['ad']) ?></td>
                        <td><span class="badge bg-secondary"><?= $tipler[$r['tip']] ?? $r['tip'] ?></span></td>
                        <td class="text-end"><?= $r['birim_agirlik']!==null ? h(rtrim(rtrim(number_format($r['birim_agirlik'],4,',','.'),'0'),',')) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end"><?= $r['bag_kg']!==null ? h(number_format($r['bag_kg'],2,',','.')) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <a href="caplar.php?duzenle=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                            <a href="caplar.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Bu çapı silmek istiyor musunuz?"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$liste): ?><tr><td colspan="6" class="text-center text-muted py-5">Henüz çap yok. <a href="kurulum_demir.php">Kurulumu çalıştırın</a>.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
