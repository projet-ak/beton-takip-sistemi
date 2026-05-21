<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';
$pageTitle = 'İmalat Grupları — Beton Takip Sistemi';

if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $fk = $pdo->prepare("SELECT COUNT(*) FROM ana_is_kalemleri WHERE imalat_grup_id=?"); $fk->execute([$id]);
    $fi = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE imalat_grup_id=?"); $fi->execute([$id]);
    if ($fk->fetchColumn() + $fi->fetchColumn() > 0) {
        flash('warning','Bu grup kullanımda, silinemiyor.');
    } else {
        $pdo->prepare("DELETE FROM imalat_gruplari WHERE id=?")->execute([$id]);
        flash('success','Grup silindi.');
    }
    redirect('imalat_gruplari.php');
}

$duzenle = null;
if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdo->prepare("SELECT * FROM imalat_gruplari WHERE id=?"); $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $ad   = trim($_POST['ad'] ?? '');
    $sira = (int)($_POST['sira'] ?? 0);
    if (!$ad) { $formError = 'Ad zorunludur.'; }
    else {
        try {
            if ($id) { $pdo->prepare("UPDATE imalat_gruplari SET ad=?,sira=? WHERE id=?")->execute([$ad,$sira,$id]); flash('success','Güncellendi.'); }
            else      { $pdo->prepare("INSERT INTO imalat_gruplari (ad,sira) VALUES (?,?)")->execute([$ad,$sira]); flash('success','Eklendi.'); }
            redirect('imalat_gruplari.php');
        } catch (PDOException $e) {
            $formError = $e->getCode()==23000 ? 'Bu isim zaten kayıtlı.' : h($e->getMessage());
        }
    }
}
$formAcik = isset($_GET['ekle']) || $duzenle;
$liste = $pdo->query("SELECT g.*, COUNT(k.id) AS kalem_adet FROM imalat_gruplari g LEFT JOIN ana_is_kalemleri k ON k.imalat_grup_id=g.id GROUP BY g.id ORDER BY g.sira,g.ad")->fetchAll();
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-collection text-primary me-2"></i>İmalat Grupları</h4>
    <a href="?ekle=1" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Yeni Ekle</a>
</div>
<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>
<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle?'bg-warning text-dark':'bg-primary text-white' ?> fw-semibold">
        <?= $duzenle?'Düzenle':'Yeni İmalat Grubu' ?>
    </div>
    <div class="card-body">
        <?php if ($formError): ?><div class="alert alert-danger"><?= h($formError) ?></div><?php endif; ?>
        <form method="post">
            <?php if ($duzenle): ?><input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Ad <span class="text-danger">*</span></label>
                    <input name="ad" class="form-control" required value="<?= h($duzenle['ad']??'') ?>"></div>
                <div class="col-md-3"><label class="form-label">Sıra</label>
                    <input name="sira" type="number" class="form-control" value="<?= (int)($duzenle['sira']??0) ?>"></div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle?'Güncelle':'Kaydet' ?></button>
                <a href="imalat_gruplari.php" class="btn btn-secondary">İptal</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
<thead class="table-light"><tr><th>Ad</th><th class="text-center">Sıra</th><th class="text-center">İş Kalemi</th><th class="text-end">İşlem</th></tr></thead>
<tbody>
<?php foreach($liste as $r): ?>
<tr>
    <td class="fw-semibold"><?= h($r['ad']) ?></td>
    <td class="text-center"><?= (int)$r['sira'] ?></td>
    <td class="text-center"><?= (int)$r['kalem_adet'] ?></td>
    <td class="text-end text-nowrap">
        <a href="?duzenle=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <a href="?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Grubu silmek istediğinize emin misiniz?"><i class="bi bi-trash"></i></a>
    </td>
</tr>
<?php endforeach; ?>
<?php if(!$liste): ?><tr><td colspan="4" class="text-center text-muted py-4">Kayıt yok</td></tr><?php endif; ?>
</tbody>
</table>
</div></div></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
