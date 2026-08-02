<?php
/** paletler.php — Palet Durumu */
$rootPath='../';
require_once __DIR__.'/../includes/functions.php'; require_once __DIR__.'/../includes/auth.php';
if(!file_exists(__DIR__.'/../config.php')){redirect('../install.php');}
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']); require_once __DIR__.'/../includes/db_seramik.php';
$pageTitle='Palet Durumu — Seramik';
$liste=[]; try{ $liste=$pdoSeramik->query("SELECT * FROM seramik_palet ORDER BY tarih DESC, id DESC")->fetchAll(); }catch(Throwable $e){}
require_once __DIR__.'/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-layers text-primary me-2"></i>Palet Durumu</h4>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>#</th><th>Tarih</th><th>Açıklama</th><th>Palet</th><th>Durum</th></tr></thead><tbody>
<?php foreach($liste as $r): ?><tr>
    <td><?= (int)$r['sira'] ?></td>
    <td class="small"><?= $r['tarih']?date('d.m.Y',strtotime($r['tarih'])):'—' ?></td>
    <td><?= h($r['aciklama']?:'—') ?></td>
    <td class="fw-semibold"><?= h($r['palet_adet']?:'—') ?></td>
    <td><?= h($r['durum']?:'—') ?></td>
</tr><?php endforeach; ?>
<?php if(!$liste): ?><tr><td colspan="5" class="text-center text-muted py-4">Palet kaydı yok. <a href="import.php">Excel içe aktarın</a>.</td></tr><?php endif; ?>
</tbody></table>
</div></div></div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
