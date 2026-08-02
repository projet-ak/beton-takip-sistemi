<?php
/**
 * kurulum_seramik.php — Seramik modülü şeması (ayrı DB: takbulut_seramik)
 * Tablolar: seramik_malzemeler / seramik_firmalar / seramik_taseronlar /
 *           seramik_giris / seramik_cikis / seramik_palet
 * Stok CANLI hesaplanır (giriş − çıkış), ayrı tablo tutulmaz.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/../includes/db_seramik.php';

$pageTitle = 'Seramik Kurulum';
$log = [];

$tablolar = [
    'seramik_malzemeler' => "CREATE TABLE IF NOT EXISTS seramik_malzemeler (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ad VARCHAR(200) NOT NULL,
        ad_norm VARCHAR(200) NOT NULL,
        tur VARCHAR(80) NULL,
        birim VARCHAR(20) NOT NULL DEFAULT 'M2',
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_norm (ad_norm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'seramik_firmalar' => "CREATE TABLE IF NOT EXISTS seramik_firmalar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ad VARCHAR(150) NOT NULL,
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_ad (ad)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'seramik_taseronlar' => "CREATE TABLE IF NOT EXISTS seramik_taseronlar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ad VARCHAR(150) NOT NULL,
        kod VARCHAR(40) NULL,
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_ad (ad)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'seramik_giris' => "CREATE TABLE IF NOT EXISTS seramik_giris (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sira INT NULL,
        gelis_tarihi DATE NULL,
        belge_tarihi DATE NULL,
        belge_no VARCHAR(80) NULL,
        malzeme_id INT NULL,
        miktar DECIMAL(14,2) NOT NULL DEFAULT 0,
        birim VARCHAR(20) NOT NULL DEFAULT 'M2',
        birim_fiyat DECIMAL(14,2) NULL,
        toplam DECIMAL(16,2) NULL,
        firma_id INT NULL,
        arac_plaka VARCHAR(30) NULL,
        geldigi_birim VARCHAR(80) NULL,
        teslim_alan VARCHAR(120) NULL,
        palet_adet VARCHAR(40) NULL,
        kdv_oran VARCHAR(20) NULL,
        aciklama VARCHAR(255) NULL,
        kaynak VARCHAR(20) NOT NULL DEFAULT 'manuel',
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (malzeme_id), INDEX (gelis_tarihi), INDEX (belge_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'seramik_cikis' => "CREATE TABLE IF NOT EXISTS seramik_cikis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sira INT NULL,
        cikis_tarihi DATE NULL,
        fis_no VARCHAR(80) NULL,
        taseron_id INT NULL,
        cikis_yeri VARCHAR(120) NULL,
        cikis_kodu VARCHAR(80) NULL,
        malzeme_id INT NULL,
        miktar DECIMAL(14,2) NOT NULL DEFAULT 0,
        birim VARCHAR(20) NOT NULL DEFAULT 'M2',
        birim_fiyat DECIMAL(14,2) NULL,
        toplam DECIMAL(16,2) NULL,
        onay VARCHAR(120) NULL,
        palet_adet VARCHAR(40) NULL,
        teslim_alan_firma VARCHAR(150) NULL,
        aciklama VARCHAR(255) NULL,
        kaynak VARCHAR(20) NOT NULL DEFAULT 'manuel',
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (malzeme_id), INDEX (cikis_tarihi), INDEX (fis_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'seramik_sayim' => "CREATE TABLE IF NOT EXISTS seramik_sayim (
        malzeme_id INT PRIMARY KEY,
        sayim DECIMAL(14,2) NOT NULL DEFAULT 0,
        giden_excel DECIMAL(14,2) NULL,
        stok_excel DECIMAL(14,2) NULL,
        updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'seramik_palet' => "CREATE TABLE IF NOT EXISTS seramik_palet (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sira INT NULL,
        tarih DATE NULL,
        aciklama VARCHAR(200) NULL,
        palet_adet VARCHAR(40) NULL,
        durum VARCHAR(150) NULL,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$hata = null;
try {
    foreach ($tablolar as $ad => $sql) {
        $pdoSeramik->exec($sql);
        $log[] = "✓ {$ad}";
    }
} catch (Throwable $e) { $hata = $e->getMessage(); }

// Durum
$durum = [];
foreach (array_keys($tablolar) as $t) {
    try { $durum[$t] = (int)$pdoSeramik->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
    catch (Throwable $e) { $durum[$t] = '—'; }
}

require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-grid-1x2 text-primary me-2"></i>Seramik Modülü Kurulum</h4>

<?php
$__serAktifDb = defined('SERAMIK_DB_NAME') && SERAMIK_DB_NAME !== '' ? SERAMIK_DB_NAME : DB_NAME;
$__serAyriMi  = defined('SERAMIK_DB_NAME') && SERAMIK_DB_NAME !== '' && SERAMIK_DB_NAME !== DB_NAME;
?>
<div class="alert <?= $__serAyriMi ? 'alert-success' : 'alert-warning' ?> py-2 mb-3 small">
    <strong>Aktif veritabanı:</strong> <code><?= h($__serAktifDb) ?></code>
    <?php if ($__serAyriMi): ?>
        &mdash; &#10003; Beton'dan ayrı veritabanı kullanılıyor.
    <?php else: ?>
        &mdash; &#9888; Beton ile <strong>aynı</strong> veritabanı (tablolar <code>seramik_</code> önekli).
        Ayırmak için config.php'ye <code>define('SERAMIK_DB_NAME','takbulut_seramik');</code> ekleyin.
    <?php endif; ?>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger"><strong>Hata:</strong> <?= h($hata) ?></div>
<?php else: ?>
<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Şema hazır. Tablolar oluşturuldu/güncel.</div>
<?php endif; ?>

<div class="card"><div class="card-body">
    <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Tablo</th><th class="text-end">Kayıt</th></tr></thead>
        <tbody>
        <?php foreach ($durum as $t => $c): ?>
            <tr><td class="font-monospace"><?= h($t) ?></td><td class="text-end"><?= h((string)$c) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="mt-3 d-flex gap-2">
    <a href="import.php" class="btn btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i> Excel'den İçe Aktar</a>
    <a href="index.php" class="btn btn-outline-secondary">Dashboard</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
