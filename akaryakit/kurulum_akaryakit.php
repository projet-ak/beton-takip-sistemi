<?php
/** kurulum_akaryakit.php — Akaryakıt modülü şeması (ayrı DB: takbulut_akaryakit) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/../includes/db_akaryakit.php';

$pageTitle = 'Akaryakıt Kurulum';

$tablolar = [
    'akaryakit_araclar' => "CREATE TABLE IF NOT EXISTS akaryakit_araclar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sinif VARCHAR(60) NULL,
        mak_no VARCHAR(60) NULL,
        lokasyon VARCHAR(120) NULL,
        firma VARCHAR(120) NULL,
        plaka VARCHAR(40) NULL,
        sofor VARCHAR(120) NOT NULL,
        cinsi VARCHAR(120) NOT NULL,
        anahtar VARCHAR(255) NOT NULL,
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_anahtar (anahtar),
        INDEX (sofor), INDEX (firma)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'akaryakit_donemler' => "CREATE TABLE IF NOT EXISTS akaryakit_donemler (
        id INT AUTO_INCREMENT PRIMARY KEY,
        donem VARCHAR(40) NOT NULL,
        donem_sira INT NOT NULL DEFAULT 0,
        devir DECIMAL(14,2) NOT NULL DEFAULT 0,
        gelen DECIMAL(14,2) NOT NULL DEFAULT 0,
        toplam DECIMAL(14,2) NOT NULL DEFAULT 0,
        kullanilan DECIMAL(14,2) NOT NULL DEFAULT 0,
        kalan DECIMAL(14,2) NOT NULL DEFAULT 0,
        gunluk LONGTEXT NULL,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_donem (donem),
        INDEX (donem_sira)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'akaryakit_tuketim' => "CREATE TABLE IF NOT EXISTS akaryakit_tuketim (
        id INT AUTO_INCREMENT PRIMARY KEY,
        donem VARCHAR(40) NOT NULL,
        donem_sira INT NOT NULL DEFAULT 0,
        arac_id INT NOT NULL,
        aylik_tuketim DECIMAL(14,2) NOT NULL DEFAULT 0,
        aylik_calisma DECIMAL(14,2) NULL,
        ortalama DECIMAL(14,4) NULL,
        onceki_okuma DECIMAL(14,2) NULL,
        ilk_okuma DECIMAL(14,2) NULL,
        son_okuma DECIMAL(14,2) NULL,
        not1 VARCHAR(255) NULL,
        not2 VARCHAR(255) NULL,
        gunluk LONGTEXT NULL,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_donem_arac (donem, arac_id),
        INDEX (donem_sira), INDEX (arac_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'akaryakit_cikislar' => "CREATE TABLE IF NOT EXISTS akaryakit_cikislar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tarih DATE NOT NULL,
        arac_id INT NULL,
        sofor VARCHAR(120) NULL,
        cinsi VARCHAR(120) NULL,
        firma VARCHAR(120) NULL,
        plaka VARCHAR(40) NULL,
        miktar_lt DECIMAL(10,2) NOT NULL DEFAULT 0,
        sayac VARCHAR(40) NULL,
        teslim_eden VARCHAR(120) NULL,
        teslim_alan VARCHAR(120) NULL,
        aciklama VARCHAR(255) NULL,
        created_by INT NULL,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY (tarih), KEY (arac_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'akaryakit_tutanak' => "CREATE TABLE IF NOT EXISTS akaryakit_tutanak (
        id INT AUTO_INCREMENT PRIMARY KEY,
        donem VARCHAR(40) NOT NULL,
        donem_sira INT NOT NULL DEFAULT 0,
        sira INT NULL,
        sofor VARCHAR(120) NULL,
        arac_detay VARCHAR(150) NULL,
        firma_detay VARCHAR(120) NULL,
        miktar DECIMAL(14,2) NOT NULL DEFAULT 0,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (donem), INDEX (donem_sira)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$hata = null; $log = [];
try { foreach ($tablolar as $ad=>$sql){ $pdoAkaryakit->exec($sql); $log[]=$ad; } }
catch (Throwable $e) { $hata = $e->getMessage(); }

$durum = [];
foreach (array_keys($tablolar) as $t) { try { $durum[$t]=(int)$pdoAkaryakit->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); } catch(Throwable $e){ $durum[$t]='—'; } }

require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-fuel-pump text-primary me-2"></i>Akaryakıt Modülü Kurulum</h4>
<?php
$__akAktifDb = defined('AKARYAKIT_DB_NAME') && AKARYAKIT_DB_NAME !== '' ? AKARYAKIT_DB_NAME : DB_NAME;
$__akAyriMi  = defined('AKARYAKIT_DB_NAME') && AKARYAKIT_DB_NAME !== '' && AKARYAKIT_DB_NAME !== DB_NAME;
?>
<div class="alert <?= $__akAyriMi ? 'alert-success' : 'alert-warning' ?> py-2 mb-3 small">
    <strong>Aktif veritabanı:</strong> <code><?= h($__akAktifDb) ?></code>
    <?php if ($__akAyriMi): ?>
        &mdash; &#10003; Beton'dan ayrı veritabanı kullanılıyor.
    <?php else: ?>
        &mdash; &#9888; Beton ile <strong>aynı</strong> veritabanı (tablolar <code>akaryakit_</code> önekli).
        Ayırmak için config.php'ye <code>define('AKARYAKIT_DB_NAME','takbulut_akaryakit');</code> ekleyin.
    <?php endif; ?>
</div>
<?php if ($hata): ?><div class="alert alert-danger"><strong>Hata:</strong> <?= h($hata) ?></div>
<?php else: ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Şema hazır.</div><?php endif; ?>
<div class="card"><div class="card-body"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Tablo</th><th class="text-end">Kayıt</th></tr></thead><tbody>
<?php foreach($durum as $t=>$c): ?><tr><td class="font-monospace"><?= h($t) ?></td><td class="text-end"><?= h((string)$c) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="mt-3 d-flex gap-2"><a href="import.php" class="btn btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i>Excel'den İçe Aktar</a><a href="index.php" class="btn btn-outline-secondary">Dashboard</a></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
