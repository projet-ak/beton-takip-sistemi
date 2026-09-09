<?php
/**
 * it/kurulum_it.php — IT Envanter modülü şeması + DB durum rozeti
 * Şema `it_semasi_kur()` içindedir (sayfalar da runtime çağırır); burası tek tıkla kurar.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/_import.php';

$pageTitle = 'IT Envanter Kurulum';
$hata = null; $log = [];
try {
    it_semasi_kur($pdoIt);
    pim_log_kur($pdoIt);
    $log = ['it_cihazlar', 'it_hareketler', 'it_belgeler', 'it_lokasyonlar', 'it_personel', 'it_import_log'];
    if (!(int)$pdoIt->query("SELECT COUNT(*) FROM it_lokasyonlar")->fetchColumn()) { $n = it_lokasyon_seed($pdoIt); $log[] = "varsayılan lokasyon ağacı ($n satır: Kartal Batı Yakası U030/U031/U039 + ERN Holding Merkez)"; }
    $dir = __DIR__ . '/../uploads/it_envanter';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $log[] = is_dir($dir) ? 'uploads/it_envanter/ klasörü' : 'uploads/it_envanter/ OLUŞTURULAMADI (izin?)';
} catch (Throwable $e) { $hata = $e->getMessage(); }

$durum = [];
foreach (['it_cihazlar','it_hareketler','it_belgeler','it_lokasyonlar','it_personel','it_import_log'] as $t) {
    try { $durum[$t] = (int)$pdoIt->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
    catch (Throwable $e) { $durum[$t] = '—'; }
}

require_once __DIR__ . '/../includes/header.php';
$__aktifDb = defined('IT_DB_NAME') && IT_DB_NAME !== '' ? IT_DB_NAME : DB_NAME;
$__ayriMi  = defined('IT_DB_NAME') && IT_DB_NAME !== '' && IT_DB_NAME !== DB_NAME;
?>
<h4 class="mb-3"><i class="bi bi-pc-display text-primary me-2"></i>IT Envanter Modülü Kurulum</h4>

<div class="alert <?= $__ayriMi ? 'alert-success' : 'alert-warning' ?> py-2 mb-3 small">
    <strong>Aktif veritabanı:</strong> <code><?= h($__aktifDb) ?></code>
    <?php if ($__ayriMi): ?>
        &mdash; &#10003; Beton'dan ayrı veritabanı kullanılıyor.
    <?php else: ?>
        &mdash; &#9888; Beton ile <strong>aynı</strong> veritabanı (tablolar <code>it_</code> önekli).
        Ayırmak için config.php'ye <code>define('IT_DB_NAME','takbulut_it');</code> ekleyin (DB önce oluşturulmalı).
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
            <p class="mb-1"><strong>it_cihazlar</strong>: her satır bir varlık (bilgisayar, telefon, yazıcı, ağ cihazı, yazılım lisansı…); envanter no otomatik <code>IT-00001</code>. Kayıt silinmez, "hurda" durumuna alınır.</p>
            <p class="mb-1"><strong>it_hareketler</strong>: cihazın yaşam günlüğü — giriş, zimmet, iade, servis, arıza, hurda, not.</p>
            <p class="mb-1"><strong>it_belgeler</strong>: fotoğraf / fatura / garanti belgesi; dosyalar <code>uploads/it_envanter/{cihaz_id}/</code> altında, DB'de yalnız göreli URL.</p>
            <p class="mb-0">Yetki: Kullanıcılar ekranında <em>IT Envanter</em> satırı (okuma / veri girişi / değiştirme / rapor). "IT Sorumlusu" rolü şablon olarak bu modülde tam yetkilidir.</p>
        </div>
        <a href="index.php" class="btn btn-primary btn-sm mt-3"><i class="bi bi-speedometer2 me-1"></i>Dashboard'a git</a>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
