<?php
/**
 * onbellek_temizle.php — PHP OPcache temizleme (admin)
 * Deploy sonrası güncellenen dosyalar eski derlenmiş haliyle sunuluyorsa
 * (canlı deploy2.php opcache_reset içermiyorsa) bu sayfa açılır.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Önbellek Temizle — Beton Takip Sistemi';

$sonuc = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── LiteSpeed / proxy TAM-SAYFA önbelleğini zorla temizle ────────────────
    // Asıl sorun bu: sunucu, dashboard gibi sayfaların ESKİ HTML kopyasını
    // saklıyor. no-cache başlıkları yalnız yeni önbelleklemeyi engeller; zaten
    // kayıtlı kopyayı bu PURGE başlığı siler. Bu istek POST olduğundan asla
    // önbellekten sunulmaz — PHP mutlaka çalışır ve LiteSpeed purge'ü uygular.
    if (!headers_sent()) {
        header('X-LiteSpeed-Purge: *');            // tüm LiteSpeed önbelleğini boşalt
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    $op = 'OPcache bu sunucuda yok/kapalı';
    if (function_exists('opcache_reset')) {
        $op = @opcache_reset() ? 'OPcache sıfırlandı ✓' : 'OPcache sıfırlanamadı (restrict ayarı olabilir)';
    }
    clearstatcache(true);
    if (function_exists('apcu_clear_cache')) { @apcu_clear_cache(); }
    $sonuc = $op . ' — dosya durum önbelleği + LiteSpeed sayfa önbelleği (purge) temizlendi.';
}

$opcacheDurum = 'yüklü değil';
$opcacheDolu  = null;
if (function_exists('opcache_get_status')) {
    $st = @opcache_get_status(false);
    if (is_array($st)) {
        $opcacheDurum = !empty($st['opcache_enabled']) ? 'AKTİF' : 'pasif';
        $opcacheDolu  = $st['memory_usage']['used_memory'] ?? null;
    }
}
$validate = ini_get('opcache.validate_timestamps');

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0"><i class="bi bi-arrow-clockwise text-primary me-2"></i>PHP Önbellek Temizle</h4>
</div>

<?php if ($sonuc): ?>
<div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><?= h($sonuc) ?>
    <div class="small mt-1">Şimdi <a href="index.php?_ts=<?= time() ?>" class="alert-link">Dashboard'a dönüp</a> rakamların güncellendiğini kontrol edin (bağlantı önbelleği atlatmak için işaretli).</div>
</div>
<?php endif; ?>

<div class="alert alert-info">
    <div class="fw-semibold mb-1"><i class="bi bi-lightbulb me-1"></i> Dashboard hâlâ eski rakamı (5.247,9 / 589) gösteriyorsa — kesin test</div>
    <div class="small">
        Sorun <strong>sunucunun sayfa önbelleği</strong> olabilir: dashboard'un ESKİ HTML kopyası saklanıyor, DB doğru olsa bile o kopya sunuluyor.
        <b>Ctrl+F5 bunu çözmez</b> (o yalnız tarayıcı önbelleğini temizler). Test edin:
        <a href="index.php?_ts=<?= time() ?>" class="alert-link" target="_blank">Dashboard'u önbelleksiz aç</a>.
        Bu bağlantıda <strong>doğru</strong> rakam (5.253,0 / 590) çıkıyorsa sorun %100 sayfa önbelleğidir →
        aşağıdaki <b>"Önbelleği Şimdi Temizle"</b> butonu LiteSpeed önbelleğini <code>purge</code> eder ve düz <code>index.php</code> de düzelir.
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2 small mb-3">
            <div class="col-md-4"><div class="text-muted">OPcache durumu</div><div class="fw-bold"><?= h($opcacheDurum) ?></div></div>
            <div class="col-md-4"><div class="text-muted">validate_timestamps</div><div class="fw-bold"><?= $validate==='' ? '—' : ($validate ? 'açık (dosya değişince yeniler)' : 'KAPALI — deploy sonrası sıfırlama ŞART') ?></div></div>
            <div class="col-md-4"><div class="text-muted">Kullanılan bellek</div><div class="fw-bold"><?= $opcacheDolu !== null ? number_format($opcacheDolu/1048576, 1, ',', '.').' MB' : '—' ?></div></div>
        </div>
        <p class="text-muted small">
            <i class="bi bi-info-circle me-1"></i>Deploy sonrası bazı sayfalar <strong>eski haliyle</strong> görünüyorsa
            (ör. dashboard rakamları güncellenmiyor ama yeni eklenen sayfalar doğru çalışıyorsa) neden PHP OPcache'tir:
            sunucu, güncellenen dosyanın eski derlenmiş kopyasını bellekten sunar. Aşağıdaki buton önbelleği sıfırlar.
        </p>
        <form method="post">
            <button class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i> Önbelleği Şimdi Temizle</button>
        </form>
    </div>
</div>
<p class="text-muted small">
    Not: Canlıdaki <code>deploy2.php</code> korumalı olduğundan otomatik güncellenmez. Repodaki yeni sürümü
    (deploy sonunda kendiliğinden <code>opcache_reset</code> yapar) cPanel dosya yöneticisiyle bir kez elle
    kopyalarsanız bu sayfaya bir daha gerek kalmaz.
</p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
