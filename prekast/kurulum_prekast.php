<?php
/**
 * kurulum_prekast.php — Prekast Takip modülü şeması + DB durum rozeti
 * Şema `pk_semasi_kur()` içindedir (sayfalar da runtime çağırır); burası tek tıkla kurar.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_prekast.php';
require_once __DIR__ . '/_ortak.php';

$pageTitle = 'Prekast Kurulum';
$hata = null; $log = [];
try {
    pk_semasi_kur($pdoPrekast);
    $log[] = 'prekast_isler';
    $log[] = 'prekast_gunluk';
} catch (Throwable $e) { $hata = $e->getMessage(); }

$durum = [];
foreach (['prekast_isler','prekast_gunluk'] as $t) {
    try { $durum[$t] = (int)$pdoPrekast->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
    catch (Throwable $e) { $durum[$t] = '—'; }
}

require_once __DIR__ . '/../includes/header.php';
$__pkDb    = $__prekastDb ?? DB_NAME;
$__pkAyri  = $__pkDb !== DB_NAME;
?>
<h4 class="mb-3"><i class="bi bi-bricks text-primary me-2"></i>Prekast Takip Kurulum</h4>

<div class="alert <?= $__pkAyri ? 'alert-success' : 'alert-warning' ?> py-2 mb-3 small">
    <strong>Aktif veritabanı:</strong> <code><?= h($__pkDb) ?></code>
    <?php if ($__pkAyri): ?>
        &mdash; &#10003; Prekast, <strong>CRM veritabanını paylaşır</strong> (tablolar <code>prekast_</code> önekli).
    <?php else: ?>
        &mdash; &#9888; Beton ile <strong>aynı</strong> veritabanı. Ayırmak için config.php'ye
        <code>define('CRM_DB_NAME','takbulut_crm');</code> ekleyin.
    <?php endif; ?>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= h($hata) ?></div>
<?php else: ?>
<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Şema hazır: <?= h(implode(', ', $log)) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <table class="table table-sm mb-3" style="max-width:520px">
            <thead class="table-light"><tr><th>Tablo</th><th class="text-end">Kayıt</th></tr></thead>
            <tbody>
            <?php foreach ($durum as $t => $n): ?>
                <tr><td><code><?= h($t) ?></code></td><td class="text-end"><?= is_int($n) ? number_format($n, 0, ',', '.') : $n ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="small text-muted">
            <strong>prekast_isler</strong> — çizelgedeki her iş kalemi (blok + daire için kesim ve silikon işi).
            Kimlik <code>kayit_anahtari</code> = çizelge + blok + daire + tekrar sırası (UNIQUE), bu yüzden
            aynı çizelge her gün yüklense de mükerrer kayıt oluşmaz; bir satır ilk kez "Yapıldı" olduğunda
            o günün tarihi damgalanır.<br>
            <strong>prekast_gunluk</strong> — her yüklemenin anlık toplamı (satır / kesim / silikon / metraj / hakkediş).
            Günlük ilerleme grafiği bundan çizilir.
        </div>
        <a href="import.php" class="btn btn-primary btn-sm mt-3"><i class="bi bi-cloud-arrow-up me-1"></i>Günlük Çizelgeyi Yükle</a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm mt-3">Dashboard</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
