<?php
/**
 * demir/index.php — Demir Takip Dashboard
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Demir Takip — Dashboard';

// Kurulum yapılmış mı? (tablo yoksa kuruluma yönlendir bilgisi göster)
$kurulu = true;
try {
    $capSayi   = (int)$pdoDemir->query("SELECT COUNT(*) FROM demir_caplar")->fetchColumn();
    $tedSayi   = (int)$pdoDemir->query("SELECT COUNT(*) FROM demir_tedarikciler")->fetchColumn();
    $tasSayi   = (int)$pdoDemir->query("SELECT COUNT(*) FROM demir_taseronlar")->fetchColumn();
    $sevkSayi  = (int)$pdoDemir->query("SELECT COUNT(*) FROM demir_sevkiyatlar")->fetchColumn();
    $gelenTon  = (float)$pdoDemir->query("SELECT COALESCE(SUM(irsaliye_miktar),0) FROM demir_sevkiyat_kalemleri")->fetchColumn();
    $kantarTon = (float)$pdoDemir->query("SELECT COALESCE(SUM(kantar_miktar),0) FROM demir_sevkiyat_kalemleri")->fetchColumn();
    // Çap bazında gelen (varsa)
    $capDagilim = $pdoDemir->query("
        SELECT c.ad, COALESCE(SUM(sk.irsaliye_miktar),0) AS ton
        FROM demir_caplar c
        LEFT JOIN demir_sevkiyat_kalemleri sk ON sk.cap_id = c.id
        GROUP BY c.id ORDER BY c.sira
    ")->fetchAll();
} catch (PDOException $e) {
    $kurulu = false;
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$kurulu): ?>
<div class="alert alert-warning d-flex align-items-center gap-3">
    <i class="bi bi-exclamation-triangle-fill fs-3"></i>
    <div>
        <strong>Demir veritabanı henüz kurulmamış.</strong><br>
        Tabloları oluşturmak için
        <a href="kurulum_demir.php" class="alert-link">kurulum_demir.php</a>'yi bir kez çalıştırın.
    </div>
</div>
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-rulers text-dark me-2"></i>Demir Takip — Genel Bakış</h4>
    <span class="badge bg-secondary">Faz 1 — Tanımlar hazır</span>
</div>

<div class="row g-3 mb-4">
    <?php
    $kart = function($ikon,$renk,$deger,$etiket,$link=null) {
        $ic = '<div class="card h-100 border-0 shadow-sm"><div class="card-body d-flex align-items-center gap-3">'
            . '<div style="width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:'.$renk.'22;color:'.$renk.';font-size:1.5rem;"><i class="bi '.$ikon.'"></i></div>'
            . '<div><div class="fs-4 fw-bold">'.$deger.'</div><div class="text-muted small">'.$etiket.'</div></div>'
            . '</div></div>';
        return $link ? '<a href="'.$link.'" class="text-reset text-decoration-none">'.$ic.'</a>' : $ic;
    };
    ?>
    <div class="col-6 col-lg-3"><?= $kart('bi-truck','#0d6efd', $sevkSayi, 'Sevkiyat Kaydı', 'sevkiyatlar.php') ?></div>
    <div class="col-6 col-lg-3"><?= $kart('bi-box-seam','#198754', number_format($gelenTon,2,',','.').' t', 'Toplam Gelen Demir') ?></div>
    <div class="col-6 col-lg-3"><?= $kart('bi-rulers','#6610f2', $capSayi, 'Çap Tanımı', 'caplar.php') ?></div>
    <div class="col-6 col-lg-3"><?= $kart('bi-people','#fd7e14', $tasSayi, 'Taşeron', 'taseronlar.php') ?></div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart-line text-primary me-1"></i> Çap Bazında Gelen Demir (ton)</div>
            <div class="card-body">
                <?php $veriVar = array_sum(array_column($capDagilim,'ton')) > 0; ?>
                <?php if ($veriVar): ?>
                    <canvas id="capChart" height="120"></canvas>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                        Henüz sevkiyat kaydı yok. <a href="sevkiyatlar.php">Sevkiyat girişi</a> yapıldığında burada çap bazında dağılım görünecek.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> Modül Durumu</div>
            <div class="card-body small">
                <div class="d-flex justify-content-between py-1 border-bottom"><span>Çap tanımı</span><strong><?= $capSayi ?></strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom"><span>Tedarikçi</span><strong><?= $tedSayi ?></strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom"><span>Taşeron</span><strong><?= $tasSayi ?></strong></div>
                <div class="d-flex justify-content-between py-1"><span>Kantar toplam</span><strong><?= number_format($kantarTon,2,',','.') ?> t</strong></div>
                <hr>
                <div class="text-muted">Sonraki adımlar: Sevkiyat girişi + kantar farkı, ardından İcmal raporu.</div>
            </div>
        </div>
    </div>
</div>

<?php if ($veriVar): ?>
<script>
const capData = <?= json_encode(array_values(array_filter($capDagilim, fn($r)=>$r['ton']>0)), JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('capChart'), {
    type: 'bar',
    data: {
        labels: capData.map(r=>r.ad),
        datasets: [{ label:'Ton', data: capData.map(r=>parseFloat(r.ton)), backgroundColor:'#0d6efd' }]
    },
    options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});
</script>
<?php endif; ?>

<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
