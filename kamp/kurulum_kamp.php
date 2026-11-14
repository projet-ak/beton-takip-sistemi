<?php
/**
 * kamp/kurulum_kamp.php — Kamp Takip şema kurulumu + DB rozeti (rol bazlı: admin / teknik ofis yöneticisi)
 * ⭐ Modül KENDİ veritabanında çalışır (KAMP_DB_NAME, önerilen takbulut_kamp) — §0.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';

$mesaj = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'kur') {
    try {
        kamp_semasi_kur($pdoKamp);
        $up = __DIR__ . '/../uploads/kamp/bekleyen';
        if (!is_dir($up)) @mkdir($up, 0775, true);
        $mesaj[] = ['success', 'Şema kuruldu / güncellendi.'];
        $mesaj[] = is_dir($up) && is_writable($up) ? ['success', 'uploads/kamp/ hazır.'] : ['warning', 'uploads/kamp/ yazılabilir değil — Excel aktarımı çalışmaz.'];
        kamp_audit('kamp_kurulum', 0, 'INSERT', null, ['sema' => 'kuruldu']);
    } catch (Throwable $e) { $mesaj[] = ['danger', 'Kurulum hatası: ' . $e->getMessage()]; }
}
$ayri = defined('KAMP_DB_NAME') && KAMP_DB_NAME !== '';
$tablo = [];
foreach (['kamp_binalar' => 'Binalar', 'kamp_odalar' => 'Odalar', 'kamp_yataklar' => 'Yataklar', 'kamp_firmalar' => 'Firmalar',
          'kamp_kisiler' => 'Kişiler', 'kamp_konaklama' => 'Konaklama defteri', 'kamp_demirbas' => 'Demirbaşlar', 'kamp_demirbas_hareket' => 'Demirbaş durum geçmişi', 'kamp_ziyaretler' => 'Ziyaretçi defteri'] as $t => $ad) {
    try { $n = (int)$pdoKamp->query("SELECT COUNT(*) FROM $t")->fetchColumn(); $var = true; } catch (Throwable $e) { $n = 0; $var = false; }
    $tablo[$t] = [$ad, $var, $n];
}
$pageTitle = 'Kurulum — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-gear text-primary me-2"></i>Kamp Takip — Kurulum</h4>
<?php foreach ($mesaj as [$t, $m]): ?><div class="alert alert-<?= $t ?> py-2"><?= h($m) ?></div><?php endforeach; ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
  <div class="mb-2">Veritabanı: <span class="badge bg-<?= $ayri ? 'success' : 'warning text-dark' ?>"><?= h($ayri ? KAMP_DB_NAME . ' (ayrı DB)' : DB_NAME . ' (ana DB — KAMP_DB_NAME tanımsız)') ?></span></div>
  <?php if (!$ayri): ?><div class="alert alert-warning small">Kural gereği her modül kendi veritabanında çalışır. aaPanel'de <code>takbulut_kamp</code> veritabanını açıp
    <code>config.php</code>'ye <code>define('KAMP_DB_NAME', 'takbulut_kamp');</code> ekleyin, sonra bu sayfada "Şemayı kur"a basın.</div><?php endif; ?>
  <table class="table table-sm mb-3" style="max-width:520px"><thead><tr><th>Tablo</th><th>Durum</th><th class="text-end">Kayıt</th></tr></thead><tbody>
    <?php foreach ($tablo as $t => [$ad, $var, $n]): ?><tr><td><?= h($ad) ?> <code class="small"><?= $t ?></code></td><td><?= $var ? '<span class="text-success">✓</span>' : '<span class="text-danger">yok</span>' ?></td><td class="text-end"><?= $n ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <form method="post"><input type="hidden" name="islem" value="kur"><button class="btn btn-primary"><i class="bi bi-database-gear me-1"></i>Şemayı kur / güncelle</button></form>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
