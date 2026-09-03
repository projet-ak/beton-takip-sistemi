<?php
/** kurulum_depo.php — Depo modülü şeması (ayrı DB: takbulut_depo) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

$pageTitle = 'Depo Kurulum';

$tablolar = [
    'depo_kalemler' => "CREATE TABLE IF NOT EXISTS depo_kalemler (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kategori ENUM('demirbas','sarf','el_aleti') NOT NULL DEFAULT 'sarf',
        sira INT NULL,
        kod VARCHAR(80) NULL,
        ad VARCHAR(200) NOT NULL,
        ozellik VARCHAR(255) NULL,
        birim VARCHAR(20) NOT NULL DEFAULT 'Ad',
        sayim DECIMAL(14,2) NOT NULL DEFAULT 0,
        gelen DECIMAL(14,2) NOT NULL DEFAULT 0,
        giden DECIMAL(14,2) NOT NULL DEFAULT 0,
        birim_fiyat DECIMAL(16,2) NULL,
        disiplin VARCHAR(80) NULL,
        alan VARCHAR(150) NULL,
        alan_kisi VARCHAR(120) NULL,
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (kategori), INDEX (kod), INDEX (ad)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$hata = null; $log = [];
try {
    foreach ($tablolar as $ad=>$sql){ $pdoDepo->exec($sql); $log[]=$ad; }
    dp_hareket_semasi_kur($pdoDepo);           // hareket defteri (giriş/çıkış)
    $log[] = 'depo_hareketler';
    dp_import_log_kur($pdoDepo);              // bölüm bazında son Excel yükleme günlüğü
    $log[] = 'depo_import_log';
}
catch (Throwable $e) { $hata = $e->getMessage(); }

$durum = [];
foreach (array_keys($tablolar) as $t) { try { $durum[$t]=(int)$pdoDepo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); } catch(Throwable $e){ $durum[$t]='—'; } }

require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-box-seam text-primary me-2"></i>Depo Modülü Kurulum</h4>
<?php
$__depoAktifDb = defined('DEPO_DB_NAME') && DEPO_DB_NAME !== '' ? DEPO_DB_NAME : DB_NAME;
$__depoAyriMi  = defined('DEPO_DB_NAME') && DEPO_DB_NAME !== '' && DEPO_DB_NAME !== DB_NAME;
?>
<div class="alert <?= $__depoAyriMi ? 'alert-success' : 'alert-warning' ?> py-2 mb-3 small">
    <strong>Aktif veritabanı:</strong> <code><?= h($__depoAktifDb) ?></code>
    <?php if ($__depoAyriMi): ?>
        &mdash; &#10003; Beton'dan ayrı veritabanı kullanılıyor.
    <?php else: ?>
        &mdash; &#9888; Beton ile <strong>aynı</strong> veritabanı (tablolar <code>depo_</code> önekli).
        Ayırmak için config.php'ye <code>define('DEPO_DB_NAME','takbulut_depo');</code> ekleyin.
    <?php endif; ?>
</div>
<?php if ($hata): ?><div class="alert alert-danger"><strong>Hata:</strong> <?= h($hata) ?></div>
<?php else: ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Şema hazır.</div><?php endif; ?>
<div class="card"><div class="card-body"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Tablo</th><th class="text-end">Kayıt</th></tr></thead><tbody>
<?php foreach($durum as $t=>$c): ?><tr><td class="font-monospace"><?= h($t) ?></td><td class="text-end"><?= h((string)$c) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="mt-3 d-flex gap-2"><a href="import.php" class="btn btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i>Excel'den İçe Aktar</a><a href="index.php" class="btn btn-outline-secondary">Dashboard</a></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
