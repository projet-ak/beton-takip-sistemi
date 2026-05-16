<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';
$pageTitle = 'Ana İş Kalemleri — Beton Takip Sistemi';

$filtreGrup = isset($_GET['grup_id']) && ctype_digit($_GET['grup_id']) ? (int)$_GET['grup_id'] : 0;

if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $fi = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE ana_is_kalemi_id=?"); $fi->execute([$id]);
    if ($fi->fetchColumn() > 0) { flash('warning','Bu kalem kullanımda, silinemiyor.'); }
    else { $pdo->prepare("DELETE FROM ana_is_kalemleri WHERE id=?")->execute([$id]); flash('success','Kalem silindi.'); }
    redirect('ana_is_kalemleri.php' . ($filtreGrup ? "?grup_id=$filtreGrup" : ''));
}

$duzenle = null;
if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdo->prepare("SELECT * FROM ana_is_kalemleri WHERE id=?"); $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $ad       = trim($_POST['ad'] ?? '');
    $grupId   = (int)($_POST['imalat_grup_id'] ?? 0);
    $sira     = (int)($_POST['sira'] ?? 0);
    if (!$ad || !$grupId) { $formError = 'Ad ve İmalat Grubu zorunludur.'; }
    else {
        try {
            if ($id) { $pdo->prepare("UPDATE ana_is_kalemleri SET ad=?,imalat_grup_id=?,sira=? WHERE id=?")->execute([$ad,$grupId,$sira,$id]); flash('success','Güncellendi.'); }
            else      { $pdo->prepare("INSERT INTO ana_is_kalemleri (ad,imalat_grup_id,sira) VALUES (?,?,?)")->execute([$ad,$grupId,$sira]); flash('success','Eklendi.'); }
            redirect('ana_is_kalemleri.php' . ($grupId ? "?grup_id=$grupId" : ''));
        } catch (PDOException $e) {
            $formError = h($e->getMessage());
        }
    }
}
$formAcik = isset($_GET['ekle']) || $duzenle;
$gruplar  = $pdo->query("SELECT id,ad FROM imalat_gruplari ORDER BY sira,ad")->fetchAll();

$where  = $filtreGrup ? "WHERE k.imalat_grup_id = $filtreGrup" : '';
$liste  = $pdo->query("SELECT k.*, g.ad AS grup_adi FROM ana_is_kalemleri k LEFT JOIN imalat_gruplari g ON g.id=k.imalat_grup_id $where ORDER BY g.sira, g.ad, k.sira, k.ad")->fetchAll();
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <h4 class="mb-0"><i class="bi bi-list-task text-primary me-2"></i>Ana İş Kalemleri</h4>
    <div class="d-flex gap-2 flex-wrap">
        <form class="d-flex gap-1" method="get">
            <select name="grup_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Tüm Gruplar</option>
                <?php foreach($gruplar as $g): ?><option value="<?= $g['id'] ?>" <?= $filtreGrup==$g['id']?'selected':'' ?>><?= h($g['ad']) ?></option><?php endforeach; ?>
            </select>
        </form>
        <a href="?ekle=1<?= $filtreGrup?"&grup_id=$filtreGrup":'' ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Yeni Ekle</a>
    </div>
</div>
<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?><div class="alert alert-<?= $t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle?'bg-warning text-dark':'bg-primary text-white' ?> fw-semibold">
        <?= $duzenle?'Kalem Düzenle':'Yeni Ana İş Kalemi' ?>
    </div>
    <div class="card-body">
        <?php if ($formError): ?><div class="alert alert-danger"><?= h($formError) ?></div><?php endif; ?>
        <form method="post">
            <?php if ($duzenle): ?><input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-5"><label class="form-label">Ad <span class="text-danger">*</span></label>
                    <input name="ad" class="form-control" required value="<?= h($duzenle['ad']??'') ?>"></div>
                <div class="col-md-4"><label class="form-label">İmalat Grubu <span class="text-danger">*</span></label>
                    <select name="imalat_grup_id" class="form-select" required>
                        <option value="">— Seçin —</option>
                        <?php foreach($gruplar as $g): ?><option value="<?= $g['id'] ?>" <?= ($duzenle['imalat_grup_id']??$filtreGrup)==$g['id']?'selected':'' ?>><?= h($g['ad']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-3"><label class="form-label">Sıra</label>
                    <input name="sira" type="number" class="form-control" value="<?= (int)($duzenle['sira']??0) ?>"></div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle?'Güncelle':'Kaydet' ?></button>
                <a href="ana_is_kalemleri.php<?= $filtreGrup?"?grup_id=$filtreGrup":'' ?>" class="btn btn-secondary">İptal</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
<thead class="table-light"><tr><th>Ad</th><th>İmalat Grubu</th><th class="text-center">Sıra</th><th class="text-end">İşlem</th></tr></thead>
<tbody>
<?php foreach($liste as $r): ?>
<tr>
    <td class="fw-semibold"><?= h($r['ad']) ?></td>
    <td><span class="badge bg-secondary"><?= h($r['grup_adi']??'-') ?></span></td>
    <td class="text-center"><?= (int)$r['sira'] ?></td>
    <td class="text-end text-nowrap">
        <a href="?duzenle=<?= $r['id'] ?><?= $filtreGrup?"&grup_id=$filtreGrup":'' ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
        <a href="?sil=<?= $r['id'] ?><?= $filtreGrup?"&grup_id=$filtreGrup":'' ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Bu kalemi silmek istiyor musunuz?"><i class="bi bi-trash"></i></a>
    </td>
</tr>
<?php endforeach; ?>
<?php if(!$liste): ?><tr><td colspan="4" class="text-center text-muted py-4">Kayıt yok</td></tr><?php endif; ?>
</tbody>
</table>
</div></div></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
