<?php
/**
 * kurulum_crm.php — CRM (Üretim Arızaları) modülü şeması + DB durum rozeti
 * Şema `crm_semasi_kur()` içindedir (sayfalar da runtime çağırır); burası tek tıkla kurar.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_crm.php';
require_once __DIR__ . '/_ortak.php';

$pageTitle = 'CRM Kurulum';
$hata = null; $log = [];
try {
    crm_semasi_kur($pdoCrm);
    $log[] = 'crm_arizalar';
    $log[] = 'crm_import_log';
} catch (Throwable $e) { $hata = $e->getMessage(); }

$durum = [];
foreach (['crm_arizalar','crm_import_log'] as $t) {
    try { $durum[$t] = (int)$pdoCrm->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
    catch (Throwable $e) { $durum[$t] = '—'; }
}

require_once __DIR__ . '/../includes/header.php';
$__crmAktifDb = defined('CRM_DB_NAME') && CRM_DB_NAME !== '' ? CRM_DB_NAME : DB_NAME;
$__crmAyriMi  = defined('CRM_DB_NAME') && CRM_DB_NAME !== '' && CRM_DB_NAME !== DB_NAME;
?>
<h4 class="mb-3"><i class="bi bi-headset text-primary me-2"></i>CRM Modülü Kurulum</h4>

<div class="alert <?= $__crmAyriMi ? 'alert-success' : 'alert-warning' ?> py-2 mb-3 small">
    <strong>Aktif veritabanı:</strong> <code><?= h($__crmAktifDb) ?></code>
    <?php if ($__crmAyriMi): ?>
        &mdash; &#10003; Beton'dan ayrı veritabanı kullanılıyor.
    <?php else: ?>
        &mdash; &#9888; Beton ile <strong>aynı</strong> veritabanı (tablolar <code>crm_</code> önekli).
        Ayırmak için config.php'ye <code>define('CRM_DB_NAME','takbulut_crm');</code> ekleyin.
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
            <strong>crm_arizalar</strong> — üretim arızalarının tek kaydı. Kimlik <code>kayit_anahtari</code>
            (içerikten üretilir, UNIQUE) olduğundan aynı günlük rapor defalarca yüklense de mükerrer kayıt oluşmaz.<br>
            <strong>crm_import_log</strong> — her günlük rapor yüklemesi: satır / yeni / güncellenen / kapanan sayıları.
        </div>
        <a href="import.php" class="btn btn-primary btn-sm mt-3"><i class="bi bi-cloud-arrow-up me-1"></i>Günlük Raporu Yükle</a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm mt-3">Dashboard</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
