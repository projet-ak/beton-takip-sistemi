<?php
/** cikislar.php — Ambar Çıkış listesi */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_seramik.php';
require_once __DIR__ . '/_ortak.php';

$pageTitle = 'Ambar Çıkış — Seramik';
if (isset($_GET['sil']) && ctype_digit($_GET['sil']) && can_edit()) {
    $pdoSeramik->prepare("DELETE FROM seramik_cikis WHERE id=?")->execute([(int)$_GET['sil']]);
    flash('success','Çıkış kaydı silindi.'); redirect('cikislar.php');
}
$ara=trim($_GET['ara']??''); $mal=isset($_GET['malzeme'])&&ctype_digit($_GET['malzeme'])?(int)$_GET['malzeme']:0;
$tas=isset($_GET['taseron'])&&ctype_digit($_GET['taseron'])?(int)$_GET['taseron']:0;
$where=[];$par=[];
if($ara!==''){ $where[]='(c.fis_no LIKE ? OR c.cikis_yeri LIKE ?)'; $par[]="%$ara%"; $par[]="%$ara%"; }
if($mal){ $where[]='c.malzeme_id=?'; $par[]=$mal; }
if($tas){ $where[]='c.taseron_id=?'; $par[]=$tas; }
$wsql=$where?('WHERE '.implode(' AND ',$where)):'';
$st=$pdoSeramik->prepare("SELECT c.*, m.ad malzeme_ad, t.ad taseron_ad FROM seramik_cikis c
    LEFT JOIN seramik_malzemeler m ON m.id=c.malzeme_id LEFT JOIN seramik_taseronlar t ON t.id=c.taseron_id $wsql
    ORDER BY c.cikis_tarihi DESC, c.id DESC");
$st->execute($par); $liste=$st->fetchAll();
$toplam=array_sum(array_column($liste,'miktar'));
$malzemeler=$pdoSeramik->query("SELECT id,ad FROM seramik_malzemeler WHERE aktif=1 ORDER BY ad")->fetchAll();
$taseronlar=$pdoSeramik->query("SELECT id,ad FROM seramik_taseronlar WHERE aktif=1 ORDER BY ad")->fetchAll();
$fmt=fn($n)=>number_format((float)$n,2,',','.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div><h4 class="mb-0"><i class="bi bi-box-arrow-up text-danger me-2"></i>Ambar Çıkış</h4>
        <small class="text-muted"><strong><?= count($liste) ?></strong> kayıt · <strong><?= $fmt($toplam) ?> m²</strong></small></div>
    <?php if(can_edit()): ?><a href="cikis_form.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i> Yeni Çıkış</a><?php endif; ?>
</div>
<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<form class="row g-2 mb-3">
    <div class="col-md-3"><input name="ara" value="<?= h($ara) ?>" class="form-control form-control-sm" placeholder="Fiş no / çıkış yeri"></div>
    <div class="col-md-3"><select name="malzeme" class="form-select form-select-sm"><option value="0">Tüm malzemeler</option><?php foreach($malzemeler as $mm): ?><option value="<?= $mm['id'] ?>" <?= $mal==$mm['id']?'selected':'' ?>><?= h($mm['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><select name="taseron" class="form-select form-select-sm"><option value="0">Tüm taşeronlar</option><?php foreach($taseronlar as $tt): ?><option value="<?= $tt['id'] ?>" <?= $tas==$tt['id']?'selected':'' ?>><?= h($tt['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-1"><button class="btn btn-outline-primary btn-sm w-100">Filtre</button></div>
    <div class="col-md-2"><a href="cikislar.php" class="btn btn-outline-secondary btn-sm w-100">Temizle</a></div>
</form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive" style="max-height:70vh">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light" style="position:sticky;top:0"><tr>
            <th>Tarih</th><th>Fiş No</th><th>Taşeron</th><th>Malzeme</th><th class="text-end">Miktar</th><th>Çıkış Yeri</th><th>Palet</th><th>Teslim Alan</th><?php if(can_edit()): ?><th></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach($liste as $r): ?>
            <tr>
                <td class="text-nowrap small"><?= $r['cikis_tarihi']?date('d.m.Y',strtotime($r['cikis_tarihi'])):'—' ?></td>
                <td class="font-monospace small"><?= h($r['fis_no']?:'—') ?></td>
                <td class="small"><?= h($r['taseron_ad']?:'—') ?></td>
                <td class="fw-semibold small"><?= h($r['malzeme_ad']?:'—') ?></td>
                <td class="text-end font-monospace"><?= $fmt($r['miktar']) ?> <span class="text-muted small"><?= h($r['birim']) ?></span></td>
                <td class="small"><?= h($r['cikis_yeri']?:'—') ?></td>
                <td class="small"><?= h($r['palet_adet']?:'—') ?></td>
                <td class="small"><?= h($r['teslim_alan_firma']?:'—') ?></td>
                <?php if(can_edit()): ?><td class="text-nowrap text-end">
                    <a href="cikis_form.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <a href="cikislar.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Silinsin mi?')"><i class="bi bi-trash"></i></a>
                </td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if(!$liste): ?><tr><td colspan="9" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
