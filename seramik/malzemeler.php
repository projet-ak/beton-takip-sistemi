<?php
/** malzemeler.php — Seramik malzeme tanımları (ad/birim + stok özeti) */
$rootPath='../';
require_once __DIR__.'/../includes/functions.php'; require_once __DIR__.'/../includes/auth.php';
if(!file_exists(__DIR__.'/../config.php')){redirect('../install.php');}
require_auth(['admin','teknik_ofis_admin']); require_once __DIR__.'/../includes/db_seramik.php';
require_once __DIR__.'/_ortak.php';
$pageTitle='Malzemeler — Seramik';

if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    try { $pdoSeramik->prepare("DELETE FROM seramik_malzemeler WHERE id=?")->execute([(int)$_GET['sil']]); flash('success','Silindi.'); }
    catch(Throwable $e){ flash('error','Giriş/çıkışta kullanılıyor, silinemedi.'); }
    redirect('malzemeler.php');
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $ad=trim($_POST['ad']??''); $birim=trim($_POST['birim']??'M2')?:'M2'; $id=isset($_POST['id'])&&ctype_digit($_POST['id'])?(int)$_POST['id']:0;
    if($ad!==''){ try {
        if($id) $pdoSeramik->prepare("UPDATE seramik_malzemeler SET ad=?, ad_norm=?, birim=? WHERE id=?")->execute([$ad,sr_norm($ad),$birim,$id]);
        else    $pdoSeramik->prepare("INSERT INTO seramik_malzemeler (ad,ad_norm,tur,birim) VALUES (?,?,?,?)")->execute([$ad,sr_norm($ad),'SERAMİK',$birim]);
        flash('success','Kaydedildi.');
    } catch(Throwable $e){ flash('error','Bu malzeme zaten var (benzer ad).'); } }
    redirect('malzemeler.php');
}
$stok = sr_stok($pdoSeramik);
$fmt=fn($n)=>number_format((float)$n,2,',','.');
require_once __DIR__.'/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-grid-3x3 text-primary me-2"></i>Malzemeler <span class="text-muted small">(<?= count($stok) ?>)</span></h4>
<?php foreach(['success','error'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':'success' ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <form method="post" class="row g-2 align-items-end">
        <div class="col-md-7"><label class="form-label small">Malzeme Adı / Özelliği</label><input name="ad" class="form-control form-control-sm" required placeholder="ör. 60X120 VİSTA ANTRASİT"></div>
        <div class="col-md-2"><label class="form-label small">Birim</label><input name="birim" class="form-control form-control-sm" value="M2"></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-circle me-1"></i>Ekle</button></div>
    </form>
    <div class="form-text mt-1">Benzer adlar (I/İ, 60X120 vs 60*120) otomatik aynı malzeme sayılır.</div>
</div></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive" style="max-height:66vh">
<table class="table table-sm table-hover align-middle mb-0"><thead class="table-light" style="position:sticky;top:0"><tr>
    <th>Malzeme</th><th>Birim</th><th class="text-end">Giriş</th><th class="text-end">Çıkış</th><th class="text-end">Stok</th><th class="text-end">İşlem</th>
</tr></thead><tbody>
<?php foreach($stok as $r): $st=(float)$r['stok']; ?><tr class="<?= $st<=0?'table-danger':'' ?>">
    <td class="fw-semibold small"><?= h($r['ad']) ?></td>
    <td class="small text-muted"><?= h($r['birim']) ?></td>
    <td class="text-end font-monospace text-success"><?= $fmt($r['giren']) ?></td>
    <td class="text-end font-monospace text-danger"><?= $fmt($r['cikan']) ?></td>
    <td class="text-end font-monospace fw-bold"><?= $fmt($st) ?></td>
    <td class="text-end"><a href="malzemeler.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Silinsin mi? (giriş/çıkış varsa silinemez)')"><i class="bi bi-trash"></i></a></td>
</tr><?php endforeach; ?>
<?php if(!$stok): ?><tr><td colspan="6" class="text-center text-muted py-3">Malzeme yok.</td></tr><?php endif; ?>
</tbody></table>
</div></div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
