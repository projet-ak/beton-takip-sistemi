<?php
/** _tanim.php — Basit ad-tabanlı tanım CRUD (firmalar/taseronlar). $tablo,$baslik,$ikon,$pdoSeramik gerekir. */
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    try { $pdoSeramik->prepare("DELETE FROM {$tablo} WHERE id=?")->execute([(int)$_GET['sil']]); flash('success','Silindi.'); }
    catch(Throwable $e){ flash('error','Kullanımda olabilir, silinemedi.'); }
    redirect(basename($_SERVER['PHP_SELF']));
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $ad=trim($_POST['ad']??''); $id=isset($_POST['id'])&&ctype_digit($_POST['id'])?(int)$_POST['id']:0;
    if ($ad!=='') {
        try {
            if($id) $pdoSeramik->prepare("UPDATE {$tablo} SET ad=? WHERE id=?")->execute([$ad,$id]);
            else    $pdoSeramik->prepare("INSERT INTO {$tablo} (ad) VALUES (?)")->execute([$ad]);
            flash('success','Kaydedildi.');
        } catch(Throwable $e){ flash('error','Bu ad zaten kayıtlı.'); }
    }
    redirect(basename($_SERVER['PHP_SELF']));
}
$liste=$pdoSeramik->query("SELECT * FROM {$tablo} ORDER BY ad")->fetchAll();
require_once __DIR__.'/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi <?= h($ikon) ?> text-primary me-2"></i><?= h($baslik) ?></h4>
<?php foreach(['success','error'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':'success' ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <form method="post" class="row g-2 align-items-end"><div class="col-md-8"><label class="form-label small">Yeni / Düzenle</label><input name="ad" class="form-control form-control-sm" required></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-circle me-1"></i>Ekle</button></div></form>
</div></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Ad</th><th class="text-end">İşlem</th></tr></thead><tbody>
<?php foreach($liste as $r): ?><tr>
    <td><?= h($r['ad']) ?></td>
    <td class="text-end"><a href="?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Silinsin mi?')"><i class="bi bi-trash"></i></a></td>
</tr><?php endforeach; ?>
<?php if(!$liste): ?><tr><td colspan="2" class="text-center text-muted py-3">Kayıt yok.</td></tr><?php endif; ?>
</tbody></table>
</div></div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
