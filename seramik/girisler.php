<?php
/** girisler.php — Ambar Giriş listesi */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_seramik.php';
require_once __DIR__ . '/_ortak.php';

$pageTitle = 'Ambar Giriş — Seramik';

if (isset($_GET['sil']) && ctype_digit($_GET['sil']) && can_edit()) {
    $pdoSeramik->prepare("DELETE FROM seramik_giris WHERE id=?")->execute([(int)$_GET['sil']]);
    flash('success','Giriş kaydı silindi.'); redirect('girisler.php');
}

$ara = trim($_GET['ara'] ?? '');
$mal = isset($_GET['malzeme']) && ctype_digit($_GET['malzeme']) ? (int)$_GET['malzeme'] : 0;
$where = []; $par = [];
if ($ara!=='') { $where[]='(g.belge_no LIKE ? OR g.arac_plaka LIKE ?)'; $par[]="%$ara%"; $par[]="%$ara%"; }
if ($mal) { $where[]='g.malzeme_id=?'; $par[]=$mal; }
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$st = $pdoSeramik->prepare("SELECT g.*, m.ad malzeme_ad, f.ad firma_ad
    FROM seramik_giris g LEFT JOIN seramik_malzemeler m ON m.id=g.malzeme_id
    LEFT JOIN seramik_firmalar f ON f.id=g.firma_id $wsql
    ORDER BY g.gelis_tarihi DESC, g.id DESC");
$st->execute($par);
$liste = $st->fetchAll();
$toplam = array_sum(array_column($liste,'miktar'));
$malzemeler = $pdoSeramik->query("SELECT id,ad FROM seramik_malzemeler WHERE aktif=1 ORDER BY ad")->fetchAll();
$fmt = fn($n)=>number_format((float)$n,2,',','.');

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div><h4 class="mb-0"><i class="bi bi-box-arrow-in-down text-success me-2"></i>Ambar Giriş</h4>
        <small class="text-muted"><strong><?= count($liste) ?></strong> kayıt · <strong><?= $fmt($toplam) ?> m²</strong></small></div>
    <?php if (can_edit()): ?><a href="giris_form.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i> Yeni Giriş</a><?php endif; ?>
</div>
<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>

<form class="row g-2 mb-3">
    <div class="col-md-4"><input name="ara" value="<?= h($ara) ?>" class="form-control form-control-sm" placeholder="Belge no / plaka ara"></div>
    <div class="col-md-4"><select name="malzeme" class="form-select form-select-sm"><option value="0">Tüm malzemeler</option>
        <?php foreach($malzemeler as $mm): ?><option value="<?= $mm['id'] ?>" <?= $mal==$mm['id']?'selected':'' ?>><?= h($mm['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-outline-primary btn-sm w-100">Filtrele</button></div>
    <div class="col-md-2"><a href="girisler.php" class="btn btn-outline-secondary btn-sm w-100">Temizle</a></div>
</form>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive" style="max-height:70vh">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light" style="position:sticky;top:0"><tr>
            <th>Tarih</th><th>Belge No</th><th>Malzeme</th><th class="text-end">Miktar</th><th>Firma</th><th>Plaka</th><th>Palet</th><th>Teslim Alan</th><?php if(can_edit()): ?><th></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($liste as $r): ?>
            <tr>
                <td class="text-nowrap small"><?= $r['gelis_tarihi']?date('d.m.Y',strtotime($r['gelis_tarihi'])):'—' ?></td>
                <td class="font-monospace small"><?= h($r['belge_no']?:'—') ?></td>
                <td class="fw-semibold small"><?= h($r['malzeme_ad']?:'—') ?></td>
                <td class="text-end font-monospace"><?= $fmt($r['miktar']) ?> <span class="text-muted small"><?= h($r['birim']) ?></span></td>
                <td class="small"><?= h($r['firma_ad']?:'—') ?></td>
                <td class="small"><?= h($r['arac_plaka']?:'—') ?></td>
                <td class="small"><?= h($r['palet_adet']?:'—') ?></td>
                <td class="small"><?= h($r['teslim_alan']?:'—') ?></td>
                <?php if(can_edit()): ?><td class="text-nowrap text-end">
                    <a href="giris_form.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <a href="girisler.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Silinsin mi?')"><i class="bi bi-trash"></i></a>
                </td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if(!$liste): ?><tr><td colspan="9" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
