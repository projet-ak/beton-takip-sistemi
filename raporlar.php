<?php
/**
 * raporlar.php — Raporlar ve istatistikler
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }

require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Raporlar — Beton Takip Sistemi';

// ── Filtreler ─────────────────────────────────────────────────────────────────
$yil        = isset($_GET['yil'])      && ctype_digit($_GET['yil'])      ? (int)$_GET['yil']      : (int)date('Y');
$ay         = isset($_GET['ay'])       && ctype_digit($_GET['ay'])       ? (int)$_GET['ay']       : 0;
$parselId   = isset($_GET['parsel'])   && ctype_digit($_GET['parsel'])   ? (int)$_GET['parsel']   : 0;
$tedarikciId= isset($_GET['tedarikci'])&& ctype_digit($_GET['tedarikci'])? (int)$_GET['tedarikci']: 0;

$where  = ["i.tip = 'alis'", "YEAR(i.tarih) = ?"];
$params = [$yil];
if ($ay)         { $where[] = 'MONTH(i.tarih) = ?'; $params[] = $ay; }
if ($parselId)   { $where[] = 'i.parsel_id = ?';    $params[] = $parselId; }
if ($tedarikciId){ $where[] = 'i.tedarikci_id = ?'; $params[] = $tedarikciId; }
$whereSQL = implode(' AND ', $where);

// ── Aylık özet ───────────────────────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT MONTH(i.tarih) AS ay, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i WHERE {$whereSQL} GROUP BY MONTH(i.tarih) ORDER BY ay
");
$stm->execute($params);
$aylikRaw = $stm->fetchAll();
$ayAdlari = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
$aylikMap = [];
foreach ($aylikRaw as $a) { $aylikMap[(int)$a['ay']] = $a; }

// ── Beton sınıfı dağılımı ─────────────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT bs.ad AS sinif, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i LEFT JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id
    WHERE {$whereSQL} GROUP BY i.beton_sinifi_id, bs.ad ORDER BY toplam_m3 DESC
");
$stm->execute($params);
$betonOzet = $stm->fetchAll();

// ── Tedarikçi dağılımı ───────────────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT t.ad, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i LEFT JOIN tedarikciler t ON t.id = i.tedarikci_id
    WHERE {$whereSQL} GROUP BY i.tedarikci_id, t.ad ORDER BY toplam_m3 DESC
");
$stm->execute($params);
$tedarikciOzet = $stm->fetchAll();

// ── Parsel / Blok dağılımı ───────────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT par.ad AS parsel, blk.ad AS blok, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i
    LEFT JOIN parseller par ON par.id = i.parsel_id
    LEFT JOIN bloklar blk   ON blk.id = i.blok_id
    WHERE {$whereSQL} GROUP BY i.parsel_id, i.blok_id, par.ad, blk.ad ORDER BY par.ad, toplam_m3 DESC
");
$stm->execute($params);
$parselOzet = $stm->fetchAll();

// ── İmalat grubu / ana iş kalemi ─────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT ig.ad AS grup, aik.ad AS kalem, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i
    LEFT JOIN imalat_gruplari ig   ON ig.id  = i.imalat_grup_id
    LEFT JOIN ana_is_kalemleri aik ON aik.id = i.ana_is_kalemi_id
    WHERE {$whereSQL} GROUP BY i.imalat_grup_id, i.ana_is_kalemi_id, ig.ad, aik.ad ORDER BY ig.ad, toplam_m3 DESC
");
$stm->execute($params);
$isKalemiOzet = $stm->fetchAll();

// ── Genel toplamlar ───────────────────────────────────────────────────────────
$stm = $pdo->prepare("SELECT COUNT(*) AS adet, COALESCE(SUM(miktar),0) AS toplam_m3 FROM irsaliyeler i WHERE {$whereSQL}");
$stm->execute($params);
$genelToplam = $stm->fetch();

// Filtre dropdownları
$yillar      = $pdo->query("SELECT DISTINCT YEAR(tarih) AS y FROM irsaliyeler ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($yil, array_map('intval', $yillar))) { $yillar[] = $yil; }
$parseller   = $pdo->query("SELECT id,ad FROM parseller ORDER BY ad")->fetchAll();
$tedarikciler= $pdo->query("SELECT id,ad FROM tedarikciler WHERE aktif=1 ORDER BY ad")->fetchAll();

// Grafik verileri
$chartLabels = []; $chartM3 = [];
for ($i = 1; $i <= 12; $i++) {
    $chartLabels[] = $ayAdlari[$i];
    $chartM3[]     = isset($aylikMap[$i]) ? (float)$aylikMap[$i]['toplam_m3'] : 0;
}

$betonLabels  = json_encode(array_column($betonOzet, 'sinif'));
$betonValues  = json_encode(array_map(fn($r) => (float)$r['toplam_m3'], $betonOzet));
$tedLabels    = json_encode(array_column($tedarikciOzet, 'ad'));
$tedValues    = json_encode(array_map(fn($r) => (float)$r['toplam_m3'], $tedarikciOzet));

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Raporlar</h4>
    <a href="raporlar.php?<?= http_build_query(['yil'=>$yil,'ay'=>$ay,'parsel'=>$parselId,'tedarikci'=>$tedarikciId]) ?>" class="btn btn-sm btn-outline-secondary" onclick="window.print();return false;">
        <i class="bi bi-printer me-1"></i> Yazdır
    </a>
</div>

<!-- Filtreler -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-sm-3 col-md-2">
                <label class="form-label small mb-1">Yıl <span class="text-danger">*</span></label>
                <select name="yil" class="form-select form-select-sm">
                    <?php foreach ($yillar as $y): ?>
                        <option value="<?= $y ?>" <?= $yil == (int)$y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2 col-md-1">
                <label class="form-label small mb-1">Ay</label>
                <select name="ay" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $ay == $m ? 'selected' : '' ?>><?= $ayAdlari[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-sm-4 col-md-2">
                <label class="form-label small mb-1">Parsel</label>
                <select name="parsel" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($parseller as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $parselId == $p['id'] ? 'selected' : '' ?>><?= h($p['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-4 col-md-2">
                <label class="form-label small mb-1">Tedarikçi</label>
                <select name="tedarikci" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($tedarikciler as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $tedarikciId == $t['id'] ? 'selected' : '' ?>><?= h($t['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filtrele</button>
                <a href="raporlar.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Temizle</a>
            </div>
        </form>
    </div>
</div>

<!-- Özet kartları -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3 border-start border-primary border-4">
            <div class="fs-4 fw-bold text-primary"><?= format_number($genelToplam['toplam_m3'], 2) ?></div>
            <div class="text-muted small">Toplam m³ (<?= $yil ?><?= $ay ? ' / '.$ayAdlari[$ay] : '' ?>)</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 border-start border-success border-4">
            <div class="fs-4 fw-bold text-success"><?= (int)$genelToplam['adet'] ?></div>
            <div class="text-muted small">Toplam İrsaliye</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 border-start border-warning border-4">
            <div class="fs-4 fw-bold text-warning"><?= count($betonOzet) ?></div>
            <div class="text-muted small">Beton Sınıfı</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 border-start border-info border-4">
            <div class="fs-4 fw-bold text-info"><?= count($parselOzet) ?></div>
            <div class="text-muted small">Parsel / Blok</div>
        </div>
    </div>
</div>

<!-- Grafikler -->
<div class="row g-4 mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-white">Aylık m³ Dağılımı (<?= $yil ?>)</div>
            <div class="card-body">
                <canvas id="chartAylik" height="110"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white">Tedarikçi Dağılımı</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="chartTed" style="max-height:220px"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Aylık detay & Tedarikçi özet -->
<div class="row g-4 mb-4">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Aylık Detay (<?= $yil ?>)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Ay</th><th class="text-end">Adet</th><th class="text-end">m³</th></tr></thead>
                    <tbody>
                    <?php
                    $totalM3 = $totalAdet = 0;
                    for ($i = 1; $i <= 12; $i++):
                        $r  = $aylikMap[$i] ?? null;
                        $m3 = $r ? (float)$r['toplam_m3'] : 0;
                        $ad = $r ? (int)$r['adet'] : 0;
                        $totalM3 += $m3; $totalAdet += $ad;
                    ?>
                        <tr <?= !$r ? 'class="text-muted"' : '' ?>>
                            <td><?= $ayAdlari[$i] ?></td>
                            <td class="text-end"><?= $ad ?: '-' ?></td>
                            <td class="text-end"><?= $m3 ? format_number($m3, 2) : '-' ?></td>
                        </tr>
                    <?php endfor; ?>
                        <tr class="table-secondary fw-bold">
                            <td>TOPLAM</td>
                            <td class="text-end"><?= $totalAdet ?></td>
                            <td class="text-end"><?= format_number($totalM3, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Tedarikçi Özeti</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Tedarikçi</th><th class="text-end">Adet</th><th class="text-end">m³</th></tr></thead>
                    <tbody>
                    <?php foreach ($tedarikciOzet as $t): ?>
                        <tr>
                            <td><?= h($t['ad'] ?: '(Bilinmiyor)') ?></td>
                            <td class="text-end"><?= (int)$t['adet'] ?></td>
                            <td class="text-end fw-semibold"><?= format_number($t['toplam_m3'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tedarikciOzet)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">Veri yok</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Beton sınıfı -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">Beton Sınıfı Dağılımı</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Beton Sınıfı</th><th class="text-end">Adet</th><th class="text-end">m³</th><th class="text-end">%</th></tr></thead>
            <tbody>
            <?php
            $gtop = (float)$genelToplam['toplam_m3'];
            foreach ($betonOzet as $b):
                $pct = $gtop > 0 ? round(($b['toplam_m3'] / $gtop) * 100, 1) : 0;
            ?>
                <tr>
                    <td><?= h($b['sinif'] ?: '(Belirtilmemiş)') ?></td>
                    <td class="text-end"><?= (int)$b['adet'] ?></td>
                    <td class="text-end fw-semibold"><?= format_number($b['toplam_m3'], 2) ?></td>
                    <td class="text-end text-muted"><?= $pct ?>%</td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($betonOzet)): ?><tr><td colspan="4" class="text-center text-muted py-3">Veri yok</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Parsel / Blok -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">Parsel / Blok Dağılımı</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Parsel</th><th>Blok</th><th class="text-end">Adet</th><th class="text-end">m³</th></tr></thead>
                <tbody>
                <?php foreach ($parselOzet as $p): ?>
                    <tr>
                        <td><?= h($p['parsel'] ?: '—') ?></td>
                        <td><?= h($p['blok'] ?: '—') ?></td>
                        <td class="text-end"><?= (int)$p['adet'] ?></td>
                        <td class="text-end fw-semibold"><?= format_number($p['toplam_m3'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($parselOzet)): ?><tr><td colspan="4" class="text-center text-muted py-3">Veri yok</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- İş kalemi -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">İmalat Grubu / İş Kalemi Dağılımı</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>İmalat Grubu</th><th>Ana İş Kalemi</th><th class="text-end">Adet</th><th class="text-end">m³</th></tr></thead>
                <tbody>
                <?php foreach ($isKalemiOzet as $k): ?>
                    <tr>
                        <td><?= h($k['grup'] ?: '—') ?></td>
                        <td><?= h($k['kalem'] ?: '—') ?></td>
                        <td class="text-end"><?= (int)$k['adet'] ?></td>
                        <td class="text-end fw-semibold"><?= format_number($k['toplam_m3'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($isKalemiOzet)): ?><tr><td colspan="4" class="text-center text-muted py-3">Veri yok</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const COLORS = ['#0d6efd','#198754','#fd7e14','#dc3545','#6610f2','#20c997','#ffc107','#0dcaf0','#6f42c1','#d63384'];
    const ayAdlari = <?= json_encode(array_values($ayAdlari)) ?>;

    new Chart(document.getElementById('chartAylik'), {
        type: 'bar',
        data: {
            labels: ayAdlari.slice(1),
            datasets: [{ label: 'm³', data: <?= json_encode(array_values($chartM3)) ?>, backgroundColor: 'rgba(13,110,253,.75)', borderRadius: 5 }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, title: { display: true, text: 'm³' } } }
        }
    });

    new Chart(document.getElementById('chartTed'), {
        type: 'doughnut',
        data: {
            labels: <?= $tedLabels ?>,
            datasets: [{ data: <?= $tedValues ?>, backgroundColor: COLORS }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
