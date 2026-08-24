<?php
/**
 * seramik/zayiat.php — Seramik Zayiat Takibi
 *
 * Zayiat = Kullanılan (ambar çıkışı) − Teorik Metraj (projedeki döşenecek m²)
 * Teorik metraj malzeme bazında elle girilir; kullanılan, çıkış kayıtlarından
 * (SAYIM'daki GİDEN + manuel çıkışlar) canlı toplanır. Limit % (varsayılan 7 —
 * seramikte kesim/fire payı betondan yüksektir) satır bazında değiştirilebilir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_seramik.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoSeramik->query("SELECT 1 FROM seramik_malzemeler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_seramik.php'); }

$pageTitle = 'Zayiat Takibi — Seramik';

$pdoSeramik->exec("CREATE TABLE IF NOT EXISTS seramik_metraj (
    id INT AUTO_INCREMENT PRIMARY KEY,
    malzeme_id INT NOT NULL,
    teorik_m2 DECIMAL(12,2) NOT NULL DEFAULT 0,
    limit_yuzde DECIMAL(5,2) NOT NULL DEFAULT 7.00,
    aciklama VARCHAR(200) NULL,
    created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_malzeme (malzeme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$duzenleyebilir = has_role('admin', 'teknik_ofis_admin', 'teknik_ofis');

$sayi = function ($v): float {
    $v = trim((string)$v);
    if (strpos($v, ',') !== false) $v = str_replace(',', '.', str_replace('.', '', $v));
    return is_numeric($v) ? (float)$v : 0.0;
};

// ── Metraj CRUD ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $duzenleyebilir && ($_POST['action'] ?? '') === 'metraj_kaydet') {
    $malzemeId = (int)($_POST['malzeme_id'] ?? 0);
    $teorik = $sayi($_POST['teorik'] ?? '0');
    $limit  = max(0, $sayi($_POST['limit'] ?? '7'));
    if (!$malzemeId || $teorik <= 0) { flash('error', 'Malzeme seçin ve sıfırdan büyük teorik m² girin.'); }
    else {
        $pdoSeramik->prepare("INSERT INTO seramik_metraj (malzeme_id, teorik_m2, limit_yuzde, aciklama)
                              VALUES (?,?,?,?)
                              ON DUPLICATE KEY UPDATE teorik_m2=VALUES(teorik_m2), limit_yuzde=VALUES(limit_yuzde), aciklama=VALUES(aciklama)")
            ->execute([$malzemeId, $teorik, $limit, trim((string)($_POST['aciklama'] ?? '')) ?: null]);
        flash('success', 'Metraj kaydedildi.');
    }
    redirect('zayiat.php');
}
if ($duzenleyebilir && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $pdoSeramik->prepare("DELETE FROM seramik_metraj WHERE id=?")->execute([(int)$_GET['sil']]);
    flash('success', 'Metraj satırı silindi.');
    redirect('zayiat.php');
}

// ── Kullanılan = malzeme bazlı toplam çıkış (sr_stok'un 'cikan' değeri) ─────
$stok = sr_stok($pdoSeramik);
$cikanByMalzeme = []; $malzemeler = [];
foreach ($stok as $s) { $cikanByMalzeme[(int)$s['id']] = (float)$s['cikan']; $malzemeler[] = $s; }

$metraj = $pdoSeramik->query("
    SELECT z.*, m.ad malzeme, m.tur, m.birim
    FROM seramik_metraj z JOIN seramik_malzemeler m ON m.id = z.malzeme_id
    ORDER BY m.ad")->fetchAll();

$satirlar = []; $asim = 0; $topTeorik = 0.0; $topKull = 0.0;
foreach ($metraj as $m) {
    $kull = $cikanByMalzeme[(int)$m['malzeme_id']] ?? 0.0;
    $teorik = (float)$m['teorik_m2'];
    $zayiat = $kull - $teorik;
    $oran = $teorik > 0 ? $zayiat / $teorik * 100 : 0;
    $lim = (float)$m['limit_yuzde'];
    $durum = $kull < $teorik ? 'devam' : ($oran > $lim ? 'asim' : ($oran > $lim * 0.8 ? 'yaklasti' : 'normal'));
    if ($durum === 'asim') $asim++;
    $topTeorik += $teorik; $topKull += $kull;
    $satirlar[] = ['m'=>$m, 'kull'=>$kull, 'zayiat'=>$zayiat, 'oran'=>$oran, 'durum'=>$durum];
}

$fmt = fn($n, $d=2) => number_format((float)$n, $d, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-graph-down-arrow text-primary me-2"></i>Seramik Zayiat Takibi</h4>
        <small class="text-muted">Zayiat = Ambar Çıkışı − Teorik Metraj (döşenecek m²) · malzeme bazında</small>
    </div>
    <?php if ($duzenleyebilir): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#metrajModal">
        <i class="bi bi-plus-circle me-1"></i>Teorik Metraj Ekle</button>
    <?php endif; ?>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>

<?php if (!$metraj): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
    Henüz teorik metraj girilmemiş. Malzeme bazında projede döşenecek m²'yi girin —
    ambar çıkışlarıyla karşılaştırılıp fire/zayiat canlı hesaplanır.
</div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Teorik Metraj</div><div class="fs-5 fw-bold"><?= $fmt($topTeorik) ?> m²</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Ambar Çıkışı</div><div class="fs-5 fw-bold text-success"><?= $fmt($topKull) ?> m²</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Zayiat</div>
        <div class="fs-5 fw-bold <?= ($topKull-$topTeorik)>0?'text-danger':'text-muted' ?>"><?= $fmt($topKull-$topTeorik) ?> m²</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100 <?= $asim?'border border-danger':'' ?>"><div class="card-body py-2">
        <div class="text-muted small">Limit Aşımı</div><div class="fs-5 fw-bold <?= $asim?'text-danger':'text-success' ?>"><?= $asim ?> malzeme</div></div></div></div>
</div>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.84rem">
    <thead class="table-light"><tr>
        <th>Malzeme</th><th>Tür</th><th>Açıklama</th>
        <th class="text-end">Teorik (m²)</th><th class="text-end">Çıkış (m²)</th>
        <th class="text-end">Zayiat (m²)</th><th class="text-end">Oran</th><th class="text-end">Limit</th><th>Durum</th>
        <?php if ($duzenleyebilir): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($satirlar as $sr): $m = $sr['m']; ?>
        <tr class="<?= $sr['durum']==='asim'?'table-danger':($sr['durum']==='yaklasti'?'table-warning':'') ?>">
            <td class="fw-semibold small"><?= h($m['malzeme']) ?></td>
            <td class="small text-muted"><?= h($m['tur'] ?: '—') ?></td>
            <td class="small text-muted"><?= h((string)$m['aciklama']) ?></td>
            <td class="text-end"><?= $fmt($m['teorik_m2']) ?></td>
            <td class="text-end"><?= $fmt($sr['kull']) ?></td>
            <td class="text-end fw-bold <?= $sr['zayiat']>0?'text-danger':'text-muted' ?>"><?= $fmt($sr['zayiat']) ?></td>
            <td class="text-end fw-bold"><?= $sr['kull']<$m['teorik_m2'] ? '—' : '%'.$fmt($sr['oran'], 1) ?></td>
            <td class="text-end text-muted">%<?= $fmt($m['limit_yuzde'], 1) ?></td>
            <td>
                <?php if ($sr['durum']==='asim'): ?><span class="badge bg-danger">LİMİT AŞIMI</span>
                <?php elseif ($sr['durum']==='yaklasti'): ?><span class="badge bg-warning text-dark">Yaklaşıyor</span>
                <?php elseif ($sr['durum']==='devam'): ?><span class="badge bg-secondary">Devam ediyor</span>
                <?php else: ?><span class="badge bg-success">Normal</span><?php endif; ?>
            </td>
            <?php if ($duzenleyebilir): ?>
            <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="modal" data-bs-target="#metrajModal"
                        onclick='metrajAc(<?= json_encode(['malzeme_id'=>(int)$m['malzeme_id'],'teorik'=>(float)$m['teorik_m2'],'limit'=>(float)$m['limit_yuzde'],'aciklama'=>(string)$m['aciklama']], JSON_HEX_APOS) ?>)'><i class="bi bi-pencil"></i></button>
                <a class="btn btn-sm btn-outline-danger py-0" href="?sil=<?= (int)$m['id'] ?>" onclick="return confirm('Metraj satırı silinsin mi?')"><i class="bi bi-trash"></i></a>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light fw-bold"><tr>
        <td colspan="3" class="text-end">TOPLAM</td>
        <td class="text-end"><?= $fmt($topTeorik) ?></td>
        <td class="text-end"><?= $fmt($topKull) ?></td>
        <td class="text-end <?= ($topKull-$topTeorik)>0?'text-danger':'' ?>"><?= $fmt($topKull-$topTeorik) ?></td>
        <td colspan="<?= $duzenleyebilir ? 4 : 3 ?>"></td>
    </tr></tfoot>
</table>
</div></div></div>
<?php endif; ?>

<?php if ($duzenleyebilir): ?>
<div class="modal fade" id="metrajModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <input type="hidden" name="action" value="metraj_kaydet">
        <div class="modal-header"><h6 class="modal-title">Teorik Metraj (malzeme başına bir kayıt)</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-3">
            <div class="col-12">
                <label class="form-label">Malzeme</label>
                <select name="malzeme_id" id="zMalzeme" class="form-select" required>
                    <option value="">— seçin —</option>
                    <?php foreach ($malzemeler as $mz): ?>
                    <option value="<?= (int)$mz['id'] ?>"><?= h($mz['ad']) ?> (çıkış: <?= $fmt($mz['cikan']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6"><label class="form-label">Teorik Metraj (m²)</label>
                <input type="text" name="teorik" id="zTeorik" class="form-control" required inputmode="decimal"></div>
            <div class="col-6"><label class="form-label">Zayiat Limiti (%)</label>
                <input type="text" name="limit" id="zLimit" class="form-control" value="7"></div>
            <div class="col-12"><label class="form-label">Açıklama <span class="text-muted small">(parsel/blok notu vb.)</span></label>
                <input type="text" name="aciklama" id="zAciklama" class="form-control"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
            <button class="btn btn-primary btn-sm">Kaydet</button>
        </div>
    </form>
</div></div></div>
<script>
function metrajAc(v){
    v = v || {malzeme_id:'', teorik:'', limit:7, aciklama:''};
    document.getElementById('zMalzeme').value = v.malzeme_id;
    document.getElementById('zTeorik').value = v.teorik;
    document.getElementById('zLimit').value = v.limit;
    document.getElementById('zAciklama').value = v.aciklama;
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
