<?php
require_once 'includes/db.php';
$pageTitle = 'Raporlar - Beton Takip Sistemi';

$yil = isset($_GET['yil']) && ctype_digit($_GET['yil']) ? (int)$_GET['yil'] : (int)date('Y');

$yillar = $pdo->query("SELECT DISTINCT YEAR(tarih) AS y FROM irsaliyeler ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($yil, $yillar) && $yillar) $yil = (int)$yillar[0];

// Aylık özet
$aylikOzet = $pdo->prepare("
    SELECT
        MONTH(tarih) AS ay,
        SUM(miktar_m3) AS toplam_m3,
        SUM(toplam_tutar) AS toplam_tutar,
        COUNT(*) AS irsaliye_adet
    FROM irsaliyeler
    WHERE YEAR(tarih) = ?
    GROUP BY MONTH(tarih)
    ORDER BY ay
");
$aylikOzet->execute([$yil]);
$aylik = $aylikOzet->fetchAll();

$ayAdlari = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
$aylikMap = [];
foreach ($aylik as $a) $aylikMap[$a['ay']] = $a;

// Tedarikçi bazlı yıllık
$tedOzet = $pdo->prepare("
    SELECT t.ad, SUM(i.miktar_m3) AS toplam_m3, SUM(i.toplam_tutar) AS toplam_tutar, COUNT(*) AS adet
    FROM irsaliyeler i
    JOIN tedarikciler t ON t.id = i.tedarikci_id
    WHERE YEAR(i.tarih) = ?
    GROUP BY t.id, t.ad
    ORDER BY toplam_m3 DESC
");
$tedOzet->execute([$yil]);
$tedDetay = $tedOzet->fetchAll();

// Grafik verileri
$chartM3     = [];
$chartTutar  = [];
$chartLabels = [];
for ($i = 1; $i <= 12; $i++) {
    $chartLabels[] = $ayAdlari[$i];
    $chartM3[]     = isset($aylikMap[$i]) ? (float)$aylikMap[$i]['toplam_m3'] : 0;
    $chartTutar[]  = isset($aylikMap[$i]) ? (float)$aylikMap[$i]['toplam_tutar'] : 0;
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="bi bi-bar-chart-line"></i> Yıllık Rapor</h5>
    <form class="d-flex gap-2">
        <select name="yil" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($yillar as $y): ?>
                <option value="<?= $y ?>" <?= $yil==$y?'selected':'' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white">Aylık m³ Tüketimi (<?= $yil ?>)</div>
            <div class="card-body">
                <canvas id="chartM3" height="120"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white">Aylık Tutar ₺ (<?= $yil ?>)</div>
            <div class="card-body">
                <canvas id="chartTutar" height="120"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Aylık Detay</div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Ay</th>
                            <th class="text-center">İrsaliye</th>
                            <th class="text-end">m³</th>
                            <th class="text-end">Tutar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $totalM3 = $totalTutar = $totalAdet = 0;
                        for ($i=1; $i<=12; $i++):
                            $row = $aylikMap[$i] ?? null;
                            $m3  = $row ? (float)$row['toplam_m3'] : 0;
                            $tu  = $row ? (float)$row['toplam_tutar'] : 0;
                            $ad  = $row ? (int)$row['irsaliye_adet'] : 0;
                            $totalM3 += $m3; $totalTutar += $tu; $totalAdet += $ad;
                        ?>
                        <tr <?= !$row?'class="text-muted"':'' ?>>
                            <td><?= $ayAdlari[$i] ?></td>
                            <td class="text-center"><?= $ad ?: '-' ?></td>
                            <td class="text-end"><?= $m3 ? number_format($m3,2,',','.') : '-' ?></td>
                            <td class="text-end"><?= $tu ? number_format($tu,2,',','.').' ₺' : '-' ?></td>
                        </tr>
                        <?php endfor; ?>
                        <tr class="table-secondary fw-bold">
                            <td>TOPLAM</td>
                            <td class="text-center"><?= $totalAdet ?></td>
                            <td class="text-end"><?= number_format($totalM3,2,',','.') ?></td>
                            <td class="text-end"><?= number_format($totalTutar,2,',','.').' ₺' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Tedarikçi Bazlı Özet</div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tedarikçi</th>
                            <th class="text-end">m³</th>
                            <th class="text-end">Tutar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tedDetay as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['ad']) ?></td>
                            <td class="text-end"><?= number_format($t['toplam_m3'],2,',','.') ?></td>
                            <td class="text-end"><?= number_format($t['toplam_tutar'],2,',','.').' ₺' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$tedDetay): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">Bu yıl veri yok.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const labels  = <?= json_encode($chartLabels) ?>;
    const m3Data  = <?= json_encode($chartM3) ?>;
    const tuData  = <?= json_encode($chartTutar) ?>;

    new Chart(document.getElementById('chartM3'), {
        type: 'bar',
        data: { labels, datasets: [{ label: 'm³', data: m3Data, backgroundColor: 'rgba(13,110,253,.7)', borderRadius: 4 }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('chartTutar'), {
        type: 'line',
        data: { labels, datasets: [{ label: '₺', data: tuData, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.1)', fill: true, tension: .3, pointRadius: 4 }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
