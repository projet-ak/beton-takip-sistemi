<?php
/**
 * index.php — Dashboard
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// config.php yoksa kuruluma yönlendir
if (!file_exists(__DIR__ . '/config.php')) {
    redirect('install.php');
}

require_auth(); // Herkes giriş yapmak zorunda

require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Dashboard — Beton Takip Sistemi';

// ── Proje filtresi ────────────────────────────────────────────────────────────
$projeId = isset($_GET['proje_id']) && ctype_digit($_GET['proje_id']) ? (int)$_GET['proje_id'] : 0;

// Projeler listesi (filtre seçici için)
try {
    $projeler = $pdo->query("
        SELECT id, COALESCE(NULLIF(kod,''), CONCAT('Proje #',id)) AS kod, COALESCE(aciklama,'') AS aciklama, aktif
        FROM projeler
        ORDER BY aktif DESC, kod
    ")->fetchAll();
} catch (Exception $e) {
    $projeler = [];
}

// Aktif proje sayısı
$aktifProjeSay = 0;
try {
    $aktifProjeSay = (int)$pdo->query("SELECT COUNT(*) FROM projeler WHERE aktif=1")->fetchColumn();
} catch (Exception $e) {
    $aktifProjeSay = 0;
}

// Proje filtresine göre WHERE
$projeFilter = '';
$projeParams = [];
if ($projeId > 0) {
    $projeFilter = ' AND i.proje_id = ?';
    $projeParams = [$projeId];
}

// ── İstatistikler ─────────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT COALESCE(SUM(miktar),0) FROM irsaliyeler i WHERE i.tip='alis'$projeFilter");
$st->execute($projeParams);
$toplamM3 = $st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler i WHERE i.tip='alis'$projeFilter");
$st->execute($projeParams);
$irsaliyeSay = $st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler i WHERE i.tip='iade'$projeFilter");
$st->execute($projeParams);
$iadeSay = $st->fetchColumn();

$tedarikciSay = $pdo->query("SELECT COUNT(*) FROM tedarikciler WHERE aktif=1")->fetchColumn();

// Bu ay
$st = $pdo->prepare(
    "SELECT COALESCE(SUM(miktar),0) FROM irsaliyeler i
     WHERE i.tip='alis' AND DATE_FORMAT(i.tarih,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')$projeFilter"
);
$st->execute($projeParams);
$buAyM3 = $st->fetchColumn();

// Son 10 irsaliye
$st = $pdo->prepare("
    SELECT i.id, i.tip, i.irsaliye_no, i.tarih, i.miktar, i.birim, i.arac_plaka,
           t.ad AS tedarikci_adi,
           bs.ad AS beton_sinifi,
           p.ad AS parsel_adi,
           b.ad AS blok_adi,
           pr.kod AS proje_kod
    FROM irsaliyeler i
    LEFT JOIN tedarikciler t  ON t.id  = i.tedarikci_id
    LEFT JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id
    LEFT JOIN parseller p     ON p.id  = i.parsel_id
    LEFT JOIN bloklar b       ON b.id  = i.blok_id
    LEFT JOIN projeler pr     ON pr.id = i.proje_id
    WHERE 1=1 $projeFilter
    ORDER BY i.tarih DESC, i.id DESC
    LIMIT 10
");
$st->execute($projeParams);
$sonIrsaliyeler = $st->fetchAll();

// Aylık m³ (son 12 ay) – grafik
$st = $pdo->prepare("
    SELECT DATE_FORMAT(tarih,'%Y-%m') AS ay, SUM(miktar) AS toplam
    FROM irsaliyeler i
    WHERE i.tip='alis' AND i.tarih >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $projeFilter
    GROUP BY ay
    ORDER BY ay
");
$st->execute($projeParams);
$aylikData = $st->fetchAll();

$chartLabels = json_encode(array_column($aylikData, 'ay'));
$chartValues = json_encode(array_map('floatval', array_column($aylikData, 'toplam')));

// Tedarikçi dağılımı
$st = $pdo->prepare("
    SELECT t.ad, COALESCE(SUM(i.miktar),0) AS toplam
    FROM tedarikciler t
    LEFT JOIN irsaliyeler i ON i.tedarikci_id = t.id AND i.tip='alis'" . ($projeId > 0 ? " AND i.proje_id = ?" : "") . "
    WHERE t.aktif = 1
    GROUP BY t.id, t.ad
    ORDER BY toplam DESC
");
$st->execute($projeParams);
$dagılım = $st->fetchAll();

$pieLabels = json_encode(array_column($dagılım, 'ad'));
$pieValues = json_encode(array_map('floatval', array_column($dagılım, 'toplam')));

// Beton sınıfı dağılımı
$st = $pdo->prepare("
    SELECT bs.ad, COALESCE(SUM(i.miktar),0) AS toplam
    FROM beton_siniflari bs
    LEFT JOIN irsaliyeler i ON i.beton_sinifi_id = bs.id AND i.tip='alis'" . ($projeId > 0 ? " AND i.proje_id = ?" : "") . "
    GROUP BY bs.id, bs.ad
    HAVING toplam > 0
    ORDER BY toplam DESC
    LIMIT 8
");
$st->execute($projeParams);
$betonDagilim = $st->fetchAll();

$betonLabels = json_encode(array_column($betonDagilim, 'ad'));
$betonValues = json_encode(array_map('floatval', array_column($betonDagilim, 'toplam')));

// Seçili projenin etiketi
$seciliProjeEtiket = '';
if ($projeId > 0) {
    foreach ($projeler as $p) {
        if ((int)$p['id'] === $projeId) {
            $seciliProjeEtiket = trim($p['kod'] . ' ' . $p['aciklama']);
            break;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Başlık + Proje filtresi -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-speedometer2 text-primary me-2"></i>Kontrol Paneli</h4>
        <?php if ($seciliProjeEtiket): ?>
            <div class="text-muted small mt-1">
                <i class="bi bi-funnel-fill me-1"></i>Filtre: <strong><?= h($seciliProjeEtiket) ?></strong>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($projeler)): ?>
    <form method="get" class="d-flex gap-2 align-items-center">
        <label class="small text-muted mb-0 text-nowrap"><i class="bi bi-diagram-3 me-1"></i>Proje:</label>
        <select name="proje_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:200px;">
            <option value="0">Tüm Projeler</option>
            <?php foreach ($projeler as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $projeId === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= h(trim($p['kod'] . ' — ' . $p['aciklama'])) ?><?= $p['aktif'] ? '' : ' (Pasif)' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($projeId > 0): ?>
            <a href="index.php" class="btn btn-sm btn-outline-secondary" title="Filtreyi temizle">
                <i class="bi bi-x-lg"></i>
            </a>
        <?php endif; ?>
    </form>
    <?php endif; ?>
</div>

<!-- İstatistik kartları -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card stat-card p-3 h-100 border-start border-primary border-4">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-3">
                    <i class="bi bi-box-seam fs-3"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-primary"><?= format_number($toplamM3, 1) ?></div>
                    <div class="text-muted small">Toplam m³ (Alış)</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card stat-card p-3 h-100 border-start border-success border-4">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success rounded-3">
                    <i class="bi bi-calendar-month fs-3"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-success"><?= format_number($buAyM3, 1) ?></div>
                    <div class="text-muted small">Bu Ay m³</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card stat-card p-3 h-100 border-start border-warning border-4">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning rounded-3">
                    <i class="bi bi-file-earmark-text fs-3"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-warning"><?= (int)$irsaliyeSay ?></div>
                    <div class="text-muted small">Alış İrsaliyesi</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card stat-card p-3 h-100 border-start border-danger border-4">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger rounded-3">
                    <i class="bi bi-arrow-up-circle fs-3"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-danger"><?= (int)$iadeSay ?></div>
                    <div class="text-muted small">İade İrsaliyesi</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card stat-card p-3 h-100 border-start border-info border-4">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info rounded-3">
                    <i class="bi bi-diagram-3 fs-3"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-info"><?= $aktifProjeSay ?></div>
                    <div class="text-muted small">Aktif Proje</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card stat-card p-3 h-100 border-start border-secondary border-4">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon bg-secondary bg-opacity-10 text-secondary rounded-3">
                    <i class="bi bi-truck fs-3"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-secondary"><?= (int)$tedarikciSay ?></div>
                    <div class="text-muted small">Aktif Tedarikçi</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Grafikler -->
<div class="row g-4 mb-4">
    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-bar-chart-line text-primary me-1"></i> Aylık m³ Tüketimi (Son 12 Ay)
            </div>
            <div class="card-body">
                <canvas id="chartAylik" height="120"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-pie-chart text-primary me-1"></i> Tedarikçi Dağılımı (m³)
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="chartPie" style="max-height:220px"></canvas>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($betonDagilim)): ?>
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-layers text-primary me-1"></i> Beton Sınıfı Dağılımı (m³)
            </div>
            <div class="card-body">
                <canvas id="chartBeton" height="140"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-list-ol text-primary me-1"></i> Beton Sınıfı Özeti
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Beton Sınıfı</th><th class="text-end">m³</th></tr></thead>
                    <tbody>
                    <?php foreach ($betonDagilim as $bd): ?>
                        <tr>
                            <td><?= h($bd['ad']) ?></td>
                            <td class="text-end fw-semibold"><?= format_number($bd['toplam'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Son irsaliyeler -->
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-clock-history text-primary me-1"></i> Son İrsaliyeler</span>
        <a href="irsaliyeler.php<?= $projeId ? '?proje_id=' . $projeId : '' ?>" class="btn btn-sm btn-outline-primary">Tümünü Gör</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="sonIrsaliyelerTbl">
                <thead class="table-light">
                    <tr>
                        <th class="sortable" data-col="0" data-type="text">Tip <span class="sort-ind">&#x21C5;</span></th>
                        <th class="sortable" data-col="1" data-type="date">Tarih <span class="sort-ind">&#x21C5;</span></th>
                        <th class="sortable" data-col="2" data-type="text">İrsaliye No <span class="sort-ind">&#x21C5;</span></th>
                        <th class="sortable" data-col="3" data-type="text">Tedarikçi <span class="sort-ind">&#x21C5;</span></th>
                        <th class="sortable" data-col="4" data-type="text">Beton Sınıfı <span class="sort-ind">&#x21C5;</span></th>
                        <th>Parsel / Blok</th>
                        <th class="text-end sortable" data-col="6" data-type="num">Miktar <span class="sort-ind">&#x21C5;</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sonIrsaliyeler): ?>
                        <?php foreach ($sonIrsaliyeler as $r): ?>
                        <tr>
                            <td data-sort="<?= h($r['tip']) ?>">
                                <?php if ($r['tip'] === 'alis'): ?>
                                    <span class="badge bg-success"><i class="bi bi-arrow-down-circle me-1"></i>Alış</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-arrow-up-circle me-1"></i>İade</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap" data-sort="<?= h($r['tarih']) ?>"><?= format_date($r['tarih']) ?></td>
                            <td class="text-nowrap"><code><?= h($r['irsaliye_no'] ?: '-') ?></code></td>
                            <td><span class="badge bg-secondary"><?= h($r['tedarikci_adi'] ?? '-') ?></span></td>
                            <td><?= h($r['beton_sinifi'] ?? '-') ?></td>
                            <td class="small text-muted">
                                <?= h($r['parsel_adi'] ?? '') ?>
                                <?php if ($r['blok_adi']): ?> / <?= h($r['blok_adi']) ?><?php endif; ?>
                                <?php if ($r['proje_kod']): ?> <span class="badge bg-light text-dark border"><?= h($r['proje_kod']) ?></span><?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold text-nowrap" data-sort="<?= (float)$r['miktar'] ?>"><?= format_number($r['miktar'], 2) ?> <?= h($r['birim']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                Henüz irsaliye yok.
                                <?php if (can_edit()): ?>
                                <a href="irsaliye_form.php" class="btn btn-sm btn-primary ms-2">
                                    <i class="bi bi-plus-circle me-1"></i>İlk İrsaliyeyi Ekle
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const COLORS = ['#0d6efd','#198754','#fd7e14','#dc3545','#6610f2','#20c997','#ffc107','#0dcaf0'];

    // Aylık çubuk grafik
    new Chart(document.getElementById('chartAylik'), {
        type: 'bar',
        data: {
            labels: <?= $chartLabels ?>,
            datasets: [{
                label: 'm³',
                data: <?= $chartValues ?>,
                backgroundColor: 'rgba(13,110,253,.75)',
                borderRadius: 5,
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, title: { display: true, text: 'm³' } } }
        }
    });

    // Tedarikçi halka grafik
    new Chart(document.getElementById('chartPie'), {
        type: 'doughnut',
        data: {
            labels: <?= $pieLabels ?>,
            datasets: [{ data: <?= $pieValues ?>, backgroundColor: COLORS }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });

    <?php if (!empty($betonDagilim)): ?>
    // Beton sınıfı yatay çubuk grafik
    new Chart(document.getElementById('chartBeton'), {
        type: 'bar',
        data: {
            labels: <?= $betonLabels ?>,
            datasets: [{
                label: 'm³',
                data: <?= $betonValues ?>,
                backgroundColor: COLORS,
                borderRadius: 4,
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true } }
        }
    });
    <?php endif; ?>

    // ── Tablo sıralama ─────────────────────────────────────────────────────
    const tbl = document.getElementById('sonIrsaliyelerTbl');
    if (tbl) {
        const tbody = tbl.tBodies[0];
        const headers = tbl.querySelectorAll('th.sortable');
        headers.forEach(th => {
            th.addEventListener('click', () => {
                const col = +th.dataset.col;
                const type = th.dataset.type || 'text';
                const asc = !th.classList.contains('asc');
                headers.forEach(x => x.classList.remove('asc', 'desc'));
                th.classList.add(asc ? 'asc' : 'desc');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort((a, b) => {
                    const av = (a.cells[col]?.dataset.sort ?? a.cells[col]?.innerText ?? '').trim();
                    const bv = (b.cells[col]?.dataset.sort ?? b.cells[col]?.innerText ?? '').trim();
                    let cmp;
                    if (type === 'num') {
                        cmp = parseFloat(av.replace(',', '.')) - parseFloat(bv.replace(',', '.'));
                    } else if (type === 'date') {
                        cmp = new Date(av) - new Date(bv);
                    } else {
                        cmp = av.localeCompare(bv, 'tr');
                    }
                    return asc ? cmp : -cmp;
                });
                rows.forEach(r => tbody.appendChild(r));
            });
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
