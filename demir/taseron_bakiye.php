<?php
/**
 * demir/taseron_bakiye.php — Taşeron demir bakiyesi
 * Net Elinde = Teslim Alınan (tutanak) + Devraldı (başka taşeronun iadesi) − İade Etti
 * İade tutanakları bakiyeye bu sayfada yansır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/_iade_ortak.php';
iade_semasi_kur($pdoDemir);

$pageTitle = 'Taşeron Bakiye — Demir Takip';

$taseronlar = $pdoDemir->query("SELECT id, ad, kod FROM demir_taseronlar ORDER BY ad")->fetchAll();
$caplar     = $pdoDemir->query("SELECT id, ad FROM demir_caplar ORDER BY sira, ad")->fetchAll();
$capAd = []; foreach ($caplar as $c) $capAd[$c['id']] = $c['ad'];

// bak[taseron_id][cap_id] = ['teslim'=>,'iade'=>,'devir'=>]
$bak = [];
$ekle = function($tid,$cid,$alan,$ton) use (&$bak){
    if (!$tid || !$cid) return;
    if (!isset($bak[$tid][$cid])) $bak[$tid][$cid] = ['teslim'=>0.0,'iade'=>0.0,'devir'=>0.0];
    $bak[$tid][$cid][$alan] += (float)$ton;
};

// Teslim alınan (taşerona teslim tutanakları)
foreach ($pdoDemir->query("
    SELECT tu.taseron_id AS tid, tk.cap_id AS cid, SUM(tk.miktar_ton) AS ton
    FROM demir_tutanak_kalemleri tk JOIN demir_tutanaklar tu ON tu.id = tk.tutanak_id
    WHERE tu.taseron_id IS NOT NULL GROUP BY tu.taseron_id, tk.cap_id") as $r) {
    $ekle($r['tid'], $r['cid'], 'teslim', $r['ton']);
}
// İade ettiği (iade eden olarak)
foreach ($pdoDemir->query("
    SELECT iu.iade_eden_id AS tid, ik.cap_id AS cid, SUM(ik.miktar_ton) AS ton
    FROM demir_iade_kalemleri ik JOIN demir_iade_tutanaklar iu ON iu.id = ik.iade_id
    WHERE iu.iade_eden_id IS NOT NULL GROUP BY iu.iade_eden_id, ik.cap_id") as $r) {
    $ekle($r['tid'], $r['cid'], 'iade', $r['ton']);
}
// Devraldığı (başka taşeronun iadesini teslim alan olarak)
foreach ($pdoDemir->query("
    SELECT iu.teslim_alan_id AS tid, ik.cap_id AS cid, SUM(ik.miktar_ton) AS ton
    FROM demir_iade_kalemleri ik JOIN demir_iade_tutanaklar iu ON iu.id = ik.iade_id
    WHERE iu.teslim_alan_id IS NOT NULL GROUP BY iu.teslim_alan_id, ik.cap_id") as $r) {
    $ekle($r['tid'], $r['cid'], 'devir', $r['ton']);
}

// Taşeron başına toplamlar
$satirlar = [];
foreach ($taseronlar as $t) {
    $caps = $bak[$t['id']] ?? [];
    if (!$caps) continue;
    $tT=0;$tI=0;$tD=0;
    foreach ($caps as $v){ $tT+=$v['teslim']; $tI+=$v['iade']; $tD+=$v['devir']; }
    $satirlar[] = ['t'=>$t, 'teslim'=>$tT, 'iade'=>$tI, 'devir'=>$tD, 'net'=>$tT+$tD-$tI, 'caps'=>$caps];
}
$gT=array_sum(array_column($satirlar,'teslim'));
$gI=array_sum(array_column($satirlar,'iade'));
$gD=array_sum(array_column($satirlar,'devir'));
$gN=array_sum(array_column($satirlar,'net'));

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-wallet2 text-dark me-2"></i>Taşeron Bakiye</h4>
        <small class="text-muted">Net Elinde = Teslim Alınan + Devraldığı − İade Ettiği</small>
    </div>
    <a href="iade_tutanaklar.php" class="btn btn-outline-dark"><i class="bi bi-arrow-return-left me-1"></i> İade Tutanakları</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam Teslim Alınan</div><div class="fs-4 fw-bold"><?= $fmt($gT) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Taşeronlar Arası Devir</div><div class="fs-4 fw-bold"><?= $fmt($gD) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam İade</div><div class="fs-4 fw-bold text-danger"><?= $fmt($gI) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Net Taşeronlarda</div><div class="fs-4 fw-bold text-success"><?= $fmt($gN) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th style="width:40px"></th><th>Taşeron</th>
            <th class="text-end">Teslim Alınan (t)</th><th class="text-end">Devraldığı (t)</th>
            <th class="text-end">İade Ettiği (t)</th><th class="text-end">Net Elinde (t)</th>
        </tr></thead>
        <tbody>
            <?php foreach ($satirlar as $idx=>$s): ?>
            <tr>
                <td><button class="btn btn-xs btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#d<?= $idx ?>" title="Çap dağılımı"><i class="bi bi-chevron-down"></i></button></td>
                <td class="fw-semibold"><?= h($s['t']['ad']) ?><?= $s['t']['kod']?' <span class="text-muted small">('.h($s['t']['kod']).')</span>':'' ?></td>
                <td class="text-end"><?= $fmt($s['teslim']) ?></td>
                <td class="text-end"><?= $s['devir']>0?'<span class="text-success">+'.$fmt($s['devir']).'</span>':'—' ?></td>
                <td class="text-end"><?= $s['iade']>0?'<span class="text-danger">−'.$fmt($s['iade']).'</span>':'—' ?></td>
                <td class="text-end fw-bold"><?= $fmt($s['net']) ?></td>
            </tr>
            <tr class="collapse" id="d<?= $idx ?>">
                <td></td>
                <td colspan="5" class="p-0">
                    <table class="table table-sm mb-0 bg-light">
                        <thead><tr class="small text-muted"><th>Çap</th><th class="text-end">Teslim</th><th class="text-end">Devir</th><th class="text-end">İade</th><th class="text-end">Net</th></tr></thead>
                        <tbody>
                        <?php foreach ($s['caps'] as $cid=>$v): $net=$v['teslim']+$v['devir']-$v['iade']; ?>
                            <tr>
                                <td><?= h($capAd[$cid] ?? ('#'.$cid)) ?></td>
                                <td class="text-end"><?= $fmt($v['teslim']) ?></td>
                                <td class="text-end"><?= $v['devir']>0?$fmt($v['devir']):'—' ?></td>
                                <td class="text-end"><?= $v['iade']>0?$fmt($v['iade']):'—' ?></td>
                                <td class="text-end fw-semibold"><?= $fmt($net) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$satirlar): ?>
            <tr><td colspan="6" class="text-center text-muted py-5">
                <i class="bi bi-wallet2 fs-1 d-block mb-2 opacity-50"></i>
                Henüz taşerona teslim tutanağı veya iade kaydı yok.
            </td></tr>
            <?php endif; ?>
        </tbody>
        <?php if ($satirlar): ?>
        <tfoot class="table-light fw-bold"><tr>
            <td></td><td class="text-end">TOPLAM</td>
            <td class="text-end"><?= $fmt($gT) ?></td><td class="text-end"><?= $fmt($gD) ?></td>
            <td class="text-end text-danger"><?= $fmt($gI) ?></td><td class="text-end"><?= $fmt($gN) ?></td>
        </tr></tfoot>
        <?php endif; ?>
    </table>
</div></div></div>

<p class="text-muted small mt-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Teslim Alınan</strong>: taşerona kesilen teslim tutanakları. <strong>Devraldığı</strong>: başka bir taşeronun
    iade edip bu taşerona aktardığı demir. <strong>İade Ettiği</strong>: bu taşeronun depoya/başka taşerona iade ettiği demir.
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
