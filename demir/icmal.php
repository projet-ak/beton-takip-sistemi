<?php
/**
 * demir/icmal.php — Gelen demir icmali (mutabakat)
 * Çap ve tedarikçi bazında irsaliye/kantar/fark özeti.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Demir İcmal — Demir Takip';

// ── Filtreler ─────────────────────────────────────────────────────────────────
$fProje  = isset($_GET['proje']) && ctype_digit($_GET['proje']) ? (int)$_GET['proje'] : 0;
$tBas    = isset($_GET['tarih_bas']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tarih_bas']) ? $_GET['tarih_bas'] : '';
$tBit    = isset($_GET['tarih_bit']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tarih_bit']) ? $_GET['tarih_bit'] : '';

$where = []; $params = [];
if ($fProje) { $where[] = 's.proje_id = ?';       $params[] = $fProje; }
if ($tBas)   { $where[] = 's.irsaliye_tarih >= ?'; $params[] = $tBas; }
if ($tBit)   { $where[] = 's.irsaliye_tarih <= ?'; $params[] = $tBit; }
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Çap bazında (tüm çaplar; filtre alt sorguda) ──────────────────────────────
$capIcmal = $pdoDemir->prepare("
    SELECT c.id, c.ad, c.tip,
           COALESCE(agg.irs,0) AS irs,
           COALESCE(agg.knt,0) AS knt
    FROM demir_caplar c
    LEFT JOIN (
        SELECT sk.cap_id,
               SUM(sk.irsaliye_miktar) AS irs,
               SUM(sk.kantar_miktar)   AS knt
        FROM demir_sevkiyat_kalemleri sk
        JOIN demir_sevkiyatlar s ON s.id = sk.sevkiyat_id
        $whereSQL
        GROUP BY sk.cap_id
    ) agg ON agg.cap_id = c.id
    GROUP BY c.id ORDER BY c.sira, c.ad
");
$capIcmal->execute($params);
$capRows = $capIcmal->fetchAll();

// ── Tedarikçi bazında ─────────────────────────────────────────────────────────
$tedSQL = "
    SELECT t.ad AS firma,
           COALESCE(SUM(sk.irsaliye_miktar),0) AS irs,
           COALESCE(SUM(sk.kantar_miktar),0)   AS knt
    FROM demir_sevkiyatlar s
    JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id = s.id
    LEFT JOIN demir_tedarikciler t ON t.id = s.tedarikci_id
    $whereSQL
    GROUP BY s.tedarikci_id ORDER BY irs DESC";
$tedIcmal = $pdoDemir->prepare($tedSQL);
$tedIcmal->execute($params);
$tedRows = $tedIcmal->fetchAll();

$totIrs = array_sum(array_column($capRows,'irs'));
$totKnt = array_sum(array_column($capRows,'knt'));
$totFark= $totKnt - $totIrs;

$projeler = $pdoDemir->query("SELECT id,kod,aciklama FROM demir_projeler WHERE aktif=1 ORDER BY kod")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-clipboard-data text-dark me-2"></i>Demir İcmal — Gelen Demir Mutabakatı</h4>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3"><label class="form-label small">Proje</label><select name="proje" class="form-select form-select-sm"><option value="">Tümü</option><?php foreach($projeler as $p): ?><option value="<?= $p['id'] ?>" <?= $fProje==$p['id']?'selected':'' ?>><?= h($p['kod']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small">Başlangıç</label><input type="date" name="tarih_bas" class="form-control form-control-sm" value="<?= h($tBas) ?>"></div>
            <div class="col-md-3"><label class="form-label small">Bitiş</label><input type="date" name="tarih_bit" class="form-control form-control-sm" value="<?= h($tBit) ?>"></div>
            <div class="col-md-3 d-flex gap-1"><button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-funnel"></i> Filtrele</button><a href="icmal.php" class="btn btn-outline-secondary btn-sm">Temizle</a></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam İrsaliye</div><div class="fs-4 fw-bold"><?= $fmt($totIrs) ?> t</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam Kantar</div><div class="fs-4 fw-bold"><?= $fmt($totKnt) ?> t</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Kantar Farkı</div><div class="fs-4 fw-bold <?= abs($totFark)<0.0005?'':($totFark<0?'text-danger':'text-success') ?>"><?= ($totFark>0?'+':'').$fmt($totFark) ?> t</div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-rulers text-primary me-1"></i> Çap Bazında</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Çap</th><th>Tür</th><th class="text-end">İrsaliye (t)</th><th class="text-end">Kantar (t)</th><th class="text-end">Fark</th></tr></thead>
                    <tbody>
                    <?php foreach ($capRows as $r): $f=(float)$r['knt']-(float)$r['irs']; if($r['irs']==0 && $r['knt']==0) continue; ?>
                        <tr>
                            <td class="fw-semibold"><?= h($r['ad']) ?></td>
                            <td><span class="badge bg-light text-muted border"><?= h($r['tip']) ?></span></td>
                            <td class="text-end"><?= $fmt($r['irs']) ?></td>
                            <td class="text-end"><?= $fmt($r['knt']) ?></td>
                            <td class="text-end <?= abs($f)<0.0005?'text-muted':($f<0?'text-danger':'text-success') ?>"><?= abs($f)<0.0005?'0':(($f>0?'+':'').$fmt($f)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($totIrs==0): ?><tr><td colspan="5" class="text-center text-muted py-4">Bu filtrede sevkiyat yok.</td></tr><?php endif; ?>
                    </tbody>
                    <?php if ($totIrs>0): ?>
                    <tfoot class="table-light fw-bold"><tr><td colspan="2">TOPLAM</td><td class="text-end"><?= $fmt($totIrs) ?></td><td class="text-end"><?= $fmt($totKnt) ?></td><td class="text-end <?= abs($totFark)<0.0005?'':($totFark<0?'text-danger':'text-success') ?>"><?= ($totFark>0?'+':'').$fmt($totFark) ?></td></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-shop text-primary me-1"></i> Tedarikçi Bazında</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Tedarikçi</th><th class="text-end">İrsaliye (t)</th><th class="text-end">Kantar (t)</th><th class="text-end">Fark</th></tr></thead>
                    <tbody>
                    <?php foreach ($tedRows as $r): $f=(float)$r['knt']-(float)$r['irs']; ?>
                        <tr>
                            <td class="fw-semibold"><?= h($r['firma'] ?: '—') ?></td>
                            <td class="text-end"><?= $fmt($r['irs']) ?></td>
                            <td class="text-end"><?= $fmt($r['knt']) ?></td>
                            <td class="text-end <?= abs($f)<0.0005?'text-muted':($f<0?'text-danger':'text-success') ?>"><?= abs($f)<0.0005?'0':(($f>0?'+':'').$fmt($f)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$tedRows): ?><tr><td colspan="4" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
        <div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>Sipariş/teslim mutabakatı, sipariş ve tutanak modülleri eklendiğinde bu ekrana katılacak.</div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
