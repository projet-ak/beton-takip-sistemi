<?php
/**
 * firma_ekstre.php — Taşeron / firma ekstresi: "bu firmaya ne verildi, bu firmadan ne geldi?"
 *   Giriş  = firmadan/tedarikçiden depoya GELEN malzeme (gönderen firma).
 *   Çıkış  = firmaya/taşerona VERİLEN malzeme (çıkış yapılan firma / taşeron).
 *   Malzeme bazlı net tablo + tarihli hareket dökümü. Kaynağı Excel defterleridir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoDepo->query("SELECT 1 FROM depo_hareketler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_depo.php'); }

$fmt  = fn($n) => number_format((float)$n, 2, ',', '.');
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');

$firmalar = $pdoDepo->query("SELECT DISTINCT TRIM(firma) f FROM depo_hareketler
                             WHERE firma IS NOT NULL AND TRIM(firma) <> '' ORDER BY f")->fetchAll(PDO::FETCH_COLUMN);
$firma = trim((string)($_GET['firma'] ?? ''));

$hareketler = []; $girisT = 0.0; $cikisT = 0.0; $girisAdet = 0; $cikisAdet = 0; $malzemeler = [];
if ($firma !== '') {
    $st = $pdoDepo->prepare("SELECT * FROM depo_hareketler WHERE TRIM(firma) = ? ORDER BY tarih IS NULL, tarih DESC, id DESC");
    $st->execute([$firma]);
    $hareketler = $st->fetchAll();
    foreach ($hareketler as $r) {
        $mk = dp_mal_norm((string)$r['malzeme']);
        if (!isset($malzemeler[$mk])) $malzemeler[$mk] = ['ad'=>$r['malzeme'], 'birim'=>$r['birim'], 'giris'=>0.0, 'cikis'=>0.0];
        if ($r['tur'] === 'giris') { $girisT += (float)$r['miktar']; $girisAdet++; $malzemeler[$mk]['giris'] += (float)$r['miktar']; }
        else                       { $cikisT += (float)$r['miktar']; $cikisAdet++; $malzemeler[$mk]['cikis'] += (float)$r['miktar']; }
    }
    uasort($malzemeler, fn($a, $b) => ($b['giris'] + $b['cikis']) <=> ($a['giris'] + $a['cikis']));
}

$pageTitle = 'Firma / Taşeron Ekstresi — Depo';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="hareketler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-people text-primary me-2"></i>Firma / Taşeron Ekstresi</h4>
</div>

<form class="row g-2 mb-3">
    <div class="col-md-5">
        <select name="firma" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">— firma / taşeron seçin (<?= count($firmalar) ?>) —</option>
            <?php foreach ($firmalar as $f): ?>
            <option value="<?= h($f) ?>" <?= $f === $firma ? 'selected' : '' ?>><?= h($f) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Getir</button></div>
</form>

<?php if ($firma === ''): ?>
<div class="alert alert-light border">Firma seçin: firmadan gelen malzeme (girişler) ile firmaya/taşerona verilen malzeme (çıkışlar) malzeme bazında listelenir.</div>
<?php else: ?>

<h5 class="mb-3"><?= h($firma) ?></h5>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Firmadan Gelen (giriş)</div>
        <div class="fs-5 fw-bold text-success">+<?= $fmt($girisT) ?></div>
        <div class="text-muted small"><?= $fmt0($girisAdet) ?> kayıt</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Firmaya Verilen (çıkış)</div>
        <div class="fs-5 fw-bold text-danger">−<?= $fmt($cikisT) ?></div>
        <div class="text-muted small"><?= $fmt0($cikisAdet) ?> kayıt</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Farklı Malzeme</div>
        <div class="fs-5 fw-bold"><?= $fmt0(count($malzemeler)) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Toplam Hareket</div>
        <div class="fs-5 fw-bold"><?= $fmt0(count($hareketler)) ?></div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-semibold py-2">
            <i class="bi bi-list-ul me-1"></i>Malzeme bazında özet</div>
        <div class="card-body p-0"><div class="table-responsive" style="max-height:62vh">
            <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
                <thead class="table-light" style="position:sticky;top:0"><tr>
                    <th>Malzeme</th><th>Birim</th><th class="text-end">Gelen</th><th class="text-end">Verilen</th>
                </tr></thead>
                <tbody>
                <?php foreach ($malzemeler as $mo): ?>
                    <tr>
                        <td><a href="malzeme_ekstre.php?m=<?= urlencode($mo['ad']) ?>" class="text-reset text-decoration-none" title="Malzeme ekstresi"><?= h($mo['ad']) ?></a></td>
                        <td class="small text-muted"><?= h((string)$mo['birim']) ?></td>
                        <td class="text-end text-success"><?= $mo['giris'] > 0 ? '+' . $fmt($mo['giris']) : '' ?></td>
                        <td class="text-end text-danger"><?= $mo['cikis'] > 0 ? '−' . $fmt($mo['cikis']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div></div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-semibold py-2">
            <i class="bi bi-arrow-left-right me-1"></i>Hareket dökümü (yeniden eskiye)</div>
        <div class="card-body p-0"><div class="table-responsive" style="max-height:62vh">
            <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
                <thead class="table-light" style="position:sticky;top:0"><tr>
                    <th>Tarih</th><th>Tür</th><th>Belge</th><th>Malzeme</th>
                    <th class="text-end">Miktar</th><th>Teslim Alan</th><th>Lokasyon</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($hareketler as $r): $g = $r['tur'] === 'giris'; ?>
                    <tr>
                        <td class="text-nowrap"><?= h(format_date($r['tarih'])) ?></td>
                        <td><span class="badge bg-<?= $g ? 'success' : 'danger' ?>"><?= $g ? 'Gelen' : 'Verilen' ?></span></td>
                        <td class="font-monospace small"><?= h($r['belge_no'] ?: '—') ?></td>
                        <td class="small"><?= h($r['malzeme']) ?></td>
                        <td class="text-end fw-semibold <?= $g ? 'text-success' : 'text-danger' ?>"><?= $g ? '+' : '−' ?><?= $fmt($r['miktar']) ?> <?= h((string)$r['birim']) ?></td>
                        <td class="small"><?= h((string)$r['teslim_alan']) ?></td>
                        <td class="small text-muted"><?= h((string)$r['lokasyon']) ?></td>
                        <td><?php if (!empty($r['evrak_url'])): ?><a href="../<?= h($r['evrak_url']) ?>" target="_blank" title="Ekli belge"><i class="bi bi-file-earmark-check text-success"></i></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div></div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
