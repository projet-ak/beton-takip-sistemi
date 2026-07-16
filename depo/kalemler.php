<?php
/** kalemler.php — Depo kalem listesi (kategori: demirbas / sarf / el_aleti) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

$k = $_GET['k'] ?? 'demirbas';
if (!dp_katGecerli($k)) $k='demirbas';
$elAleti = ($k==='el_aleti');
$pageTitle = dp_katAd($k).' — Depo';

if (isset($_GET['sil']) && ctype_digit($_GET['sil']) && can_edit()) {
    $pdoDepo->prepare("DELETE FROM depo_kalemler WHERE id=?")->execute([(int)$_GET['sil']]);
    flash('success','Kayıt silindi.'); redirect('kalemler.php?k='.$k);
}
$ara = trim($_GET['ara'] ?? '');
$where = ['kategori=?','aktif=1']; $par=[$k];
if ($ara!=='') { $where[]='(ad LIKE ? OR ozellik LIKE ? OR kod LIKE ? OR alan LIKE ?)'; for($j=0;$j<4;$j++)$par[]="%$ara%"; }
$wsql = 'WHERE '.implode(' AND ',$where);
$liste = $pdoDepo->prepare("SELECT *, (sayim+gelen-giden) stok FROM depo_kalemler $wsql ORDER BY ad, id");
$liste->execute($par); $liste=$liste->fetchAll();
$topStok=0; $topDeger=0;
foreach($liste as $r){ $topStok+=(float)$r['stok']; $topDeger+=(float)$r['stok']*(float)($r['birim_fiyat']??0); }
$fmt=fn($n)=>number_format((float)$n,2,',','.'); $fmt0=fn($n)=>number_format((float)$n,0,',','.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div><h4 class="mb-0"><i class="bi <?= h($GLOBALS['DP_KATEGORI'][$k]['ikon']) ?> text-primary me-2"></i><?= h(dp_katAd($k)) ?></h4>
        <small class="text-muted"><strong><?= count($liste) ?></strong> kalem · Toplam stok <strong><?= $fmt($topStok) ?></strong><?php if(!$elAleti): ?> · Mali değer <strong><?= $fmt0($topDeger) ?> TL</strong><?php endif; ?></small></div>
    <?php if(can_edit()): ?><a href="kalem_form.php?k=<?= $k ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Yeni Kalem</a><?php endif; ?>
</div>
<ul class="nav nav-pills mb-3">
    <?php foreach($GLOBALS['DP_KATEGORI'] as $kk=>$ki): ?>
    <li class="nav-item"><a class="nav-link <?= $kk===$k?'active':'' ?>" href="kalemler.php?k=<?= $kk ?>"><i class="bi <?= h($ki['ikon']) ?> me-1"></i><?= h($ki['ad']) ?></a></li>
    <?php endforeach; ?>
</ul>
<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<form class="row g-2 mb-3">
    <input type="hidden" name="k" value="<?= h($k) ?>">
    <div class="col-md-5"><input name="ara" value="<?= h($ara) ?>" class="form-control form-control-sm" placeholder="Ad / özellik / kod / alan ara"></div>
    <div class="col-md-2"><button class="btn btn-outline-primary btn-sm w-100">Filtrele</button></div>
    <div class="col-md-2"><a href="kalemler.php?k=<?= $k ?>" class="btn btn-outline-secondary btn-sm w-100">Temizle</a></div>
</form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive" style="max-height:70vh">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
    <thead class="table-light" style="position:sticky;top:0"><tr>
        <th><?= $elAleti?'Seri No':'Kod' ?></th><th>Malzeme</th><th>Özellik</th><th>Birim</th>
        <th class="text-end">Sayım</th><th class="text-end">Gelen</th><th class="text-end">Giden</th><th class="text-end">Stok</th>
        <?php if(!$elAleti): ?><th class="text-end">B.Fiyat</th><th class="text-end">Tutar</th><th>Disiplin</th><?php endif; ?>
        <th><?= $elAleti?'Alan Kişi':'Alan' ?></th>
        <?php if(can_edit()): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach($liste as $r): $st=(float)$r['stok']; $tutar=$st*(float)($r['birim_fiyat']??0); ?>
        <tr class="<?= $st<=0?'table-warning':'' ?>">
            <td class="font-monospace small"><?= h($r['kod']?:'—') ?></td>
            <td class="fw-semibold small"><?= h($r['ad']) ?></td>
            <td class="small text-muted"><?= h($r['ozellik']?:'—') ?></td>
            <td class="small"><?= h($r['birim']) ?></td>
            <td class="text-end font-monospace"><?= $fmt($r['sayim']) ?></td>
            <td class="text-end font-monospace text-success"><?= $fmt($r['gelen']) ?></td>
            <td class="text-end font-monospace text-danger"><?= $fmt($r['giden']) ?></td>
            <td class="text-end font-monospace fw-bold <?= $st<=0?'text-danger':'' ?>"><?= $fmt($st) ?></td>
            <?php if(!$elAleti): ?>
                <td class="text-end font-monospace small"><?= $r['birim_fiyat']!==null?$fmt($r['birim_fiyat']):'—' ?></td>
                <td class="text-end font-monospace small"><?= $tutar>0?$fmt0($tutar):'—' ?></td>
                <td class="small"><?= h($r['disiplin']?:'—') ?></td>
            <?php endif; ?>
            <td class="small"><?= h(($elAleti?$r['alan_kisi']:$r['alan'])?:'—') ?></td>
            <?php if(can_edit()): ?><td class="text-nowrap text-end">
                <a href="kalem_form.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="bi bi-pencil"></i></a>
                <a href="kalemler.php?k=<?= $k ?>&sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Silinsin mi?')"><i class="bi bi-trash"></i></a>
            </td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if(!$liste): ?><tr><td colspan="13" class="text-center text-muted py-4">Kayıt yok. <a href="import.php">Excel içe aktarın</a>.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
