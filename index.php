<?php
require_once 'includes/db.php';
$pageTitle = 'Dashboard - Beton Takip Sistemi';

// Özet istatistikler
$toplamM3   = $pdo->query("SELECT COALESCE(SUM(miktar_m3),0) FROM irsaliyeler")->fetchColumn();
$toplamTutar= $pdo->query("SELECT COALESCE(SUM(toplam_tutar),0) FROM irsaliyeler")->fetchColumn();
$irsaliyeSay= $pdo->query("SELECT COUNT(*) FROM irsaliyeler")->fetchColumn();
$tedarikciSay=$pdo->query("SELECT COUNT(*) FROM tedarikciler WHERE aktif=1")->fetchColumn();

// Bu ay
$buAyM3   = $pdo->query("SELECT COALESCE(SUM(miktar_m3),0) FROM irsaliyeler WHERE DATE_FORMAT(tarih,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')")->fetchColumn();

// Son 5 irsaliye
$sonIrsaliyeler = $pdo->query("
    SELECT i.*, t.ad AS tedarikci_adi
    FROM irsaliyeler i
    JOIN tedarikciler t ON t.id = i.tedarikci_id
    ORDER BY i.tarih DESC, i.id DESC
    LIMIT 5
")->fetchAll();

// Aylık m3 (son 12 ay) – grafik verisi
$aylikData = $pdo->query("
    SELECT DATE_FORMAT(tarih,'%Y-%m') AS ay, SUM(miktar_m3) AS toplam
    FROM irsaliyeler
    WHERE tarih >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ay
    ORDER BY ay
")->fetchAll();

$chartLabels = json_encode(array_column($aylikData, 'ay'));
$chartValues = json_encode(array_map('floatval', array_column($aylikData, 'toplam')));

// Tedarikçi bazlı dağılım
$dagılım = $pdo->query("
    SELECT t.ad, COALESCE(SUM(i.miktar_m3),0) AS toplam
    FROM tedarikciler t
    LEFT JOIN irsaliyeler i ON i.tedarikci_id = t.id
    WHERE t.aktif = 1
    GROUP BY t.id, t.ad
")->fetchAll();

$pieLabels = json_encode(array_column($dagılım, 'ad'));
$pieValues = json_encode(array_map('floatval', array_column($dagılım, 'toplam')));

require_once 'includes/header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card blue p-3">
            <div class="stat-value text-primary"><?= number_format($toplamM3, 1, ',', '.') ?></div>
            <div class="stat-label"><i class="bi bi-box-seam"></i> Toplam m³</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card green p-3">
            <div class="stat-value text-success"><?= number_format($toplamTutar, 0, ',', '.') ?> ₺</div>
            <div class="stat-label"><i class="bi bi-cash-coin"></i> Toplam Tutar</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card orange p-3">
            <div class="stat-value text-warning"><?= number_format($buAyM3, 1, ',', '.') ?></div>
            <div class="stat-label"><i class="bi bi-calendar-month"></i> Bu Ay m³</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card red p-3">
            <div class="stat-value text-danger"><?= (int)$irsaliyeSay ?></div>
            <div class="stat-label"><i class="bi bi-file-earmark-text"></i> İrsaliye Sayısı</div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-white">Aylık m³ Tüketimi (Son 12 Ay)</div>
            <div class="card-body">
                <canvas id="chartAylik" height="100"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white">Tedarikçi Dağılımı (m³)</div>
            <div class="card-body">
                <canvas id="chartPie" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span>Son İrsaliyeler</span>
        <a href="irsaliyeler.php" class="btn btn-sm btn-primary">Tümünü Gör</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tarih</th>
                        <th>İrsaliye No</th>
                        <th>Tedarikçi</th>
                        <th class="text-end">m³</th>
                        <th class="text-end">Birim Fiyat</th>
                        <th class="text-end">Tutar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sonIrsaliyeler): ?>
                        <?php foreach ($sonIrsaliyeler as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d.m.Y', strtotime($r['tarih']))) ?></td>
                            <td><?= htmlspecialchars($r['irsaliye_no'] ?: '-') ?></td>
                            <td><span class="badge bg-secondary badge-tedarikci"><?= htmlspecialchars($r['tedarikci_adi']) ?></span></td>
                            <td class="text-end"><?= number_format($r['miktar_m3'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($r['birim_fiyat'], 2, ',', '.') ?> ₺</td>
                            <td class="text-end fw-bold"><?= number_format($r['toplam_tutar'], 2, ',', '.') ?> ₺</td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Henüz irsaliye yok. <a href="irsaliyeler.php?ekle=1">İlk irsaliyeyi ekle</a></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function() {
    const labels = <?= $chartLabels ?>;
    const values = <?= $chartValues ?>;

    new Chart(document.getElementById('chartAylik'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'm³',
                data: values,
                backgroundColor: 'rgba(13,110,253,.7)',
                borderRadius: 4,
            }]
        },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('chartPie'), {
        type: 'doughnut',
        data: {
            labels: <?= $pieLabels ?>,
            datasets: [{
                data: <?= $pieValues ?>,
                backgroundColor: ['#0d6efd','#198754','#fd7e14','#dc3545','#6610f2'],
            }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
