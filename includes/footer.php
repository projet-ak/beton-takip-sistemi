  </div><!-- /ern-content -->

  <?php if (!defined('APP_VERSION')) define('APP_VERSION', '3.0'); ?>
  <footer class="ern-footer">
    <span>
      <i class="bi bi-building-fill-check" style="color:var(--ern)"></i>
      &nbsp;Beton Takip Sistemi &mdash; &copy; <?= date('Y') ?> ERN Holding
    </span>
    <span class="dev-credit">
      <i class="bi bi-code-slash me-1"></i>Geliştirici: <strong>Tayyar Akbulut</strong>
    </span>
    <span style="opacity:.4;font-size:.7rem;">v<?= APP_VERSION ?></span>
  </footer>

</div><!-- /ern-main -->
</div><!-- /ern-wrapper -->

<!-- Alt Navigasyon (sadece mobil) — modüle duyarlı -->
<?php
$__page = basename($_SERVER['PHP_SELF']);
$__rp   = $rootPath ?? '';
// Aktif modülü belirle (header.php'deki $__module varsa onu kullan, yoksa yolla hesapla)
$__self2 = $_SERVER['PHP_SELF'] ?? '';
$__navMod = $__module ?? (
    (strpos($__self2,'/demir/')!==false) ? 'demir'
  : ((strpos($__self2,'/seramik/')!==false) ? 'seramik'
  : ((strpos($__self2,'/depo/')!==false) ? 'depo'
  : ((strpos($__self2,'/akaryakit/')!==false) ? 'akaryakit' : 'beton'))));
$__navKlasor = ['beton'=>'','demir'=>'demir/','seramik'=>'seramik/','depo'=>'depo/','akaryakit'=>'akaryakit/'][$__navMod] ?? '';
// Her modül için alt menü öğeleri: [sayfa, etiket, ikon]
$__navSetler = [
  'beton'     => [['index.php','Dashboard','bi-speedometer2'],['irsaliyeler.php','İrsaliye','bi-file-earmark-text'],['raporlar.php','Raporlar','bi-bar-chart-line']],
  'demir'     => [['index.php','Dashboard','bi-speedometer2'],['sevkiyatlar.php','Sevkiyat','bi-truck'],['tutanaklar.php','Tutanak','bi-file-earmark-check'],['raporlar.php','Rapor','bi-bar-chart-line']],
  'seramik'   => [['index.php','Dashboard','bi-speedometer2'],['girisler.php','Giriş','bi-box-arrow-in-down'],['cikislar.php','Çıkış','bi-box-arrow-up'],['stok.php','Stok','bi-boxes']],
  'depo'      => [['index.php','Dashboard','bi-speedometer2'],['kalemler.php','Demirbaş','bi-hdd-stack'],['import.php','Aktar','bi-cloud-arrow-up']],
  'akaryakit' => [['index.php','Dashboard','bi-speedometer2'],['aylik.php','Aylık','bi-calendar3'],['stok.php','Stok','bi-fuel-pump'],['tutanaklar.php','Tutanak','bi-file-earmark-text']],
];
$__navItems = $__navSetler[$__navMod] ?? $__navSetler['beton'];
?>
<nav class="bottom-nav d-lg-none" id="bottomNav">
<?php if($__navMod==='beton'): ?>
  <a href="<?= $__rp ?>index.php" class="bottom-nav-item <?= $__page==='index.php'?'active':'' ?>">
    <i class="bi bi-speedometer2"></i><span>Dashboard</span>
  </a>
  <a href="<?= $__rp ?>irsaliyeler.php" class="bottom-nav-item <?= $__page==='irsaliyeler.php'?'active':'' ?>">
    <i class="bi bi-file-earmark-text"></i><span>İrsaliye</span>
  </a>
  <a href="<?= $__rp ?>hizli_tarama.php" class="bottom-nav-fab <?= $__page==='hizli_tarama.php'?'active':'' ?>" title="Hızlı Tara">
    <i class="bi bi-qr-code-scan"></i>
  </a>
  <a href="<?= $__rp ?>raporlar.php" class="bottom-nav-item <?= $__page==='raporlar.php'?'active':'' ?>">
    <i class="bi bi-bar-chart-line"></i><span>Raporlar</span>
  </a>
<?php else: foreach($__navItems as [$sf,$et,$ik]): ?>
  <a href="<?= $__rp . $__navKlasor . $sf ?>" class="bottom-nav-item <?= $__page===$sf?'active':'' ?>">
    <i class="bi <?= $ik ?>"></i><span><?= $et ?></span>
  </a>
<?php endforeach; endif; ?>
  <a href="#" class="bottom-nav-item" onclick="document.getElementById('ernSidebar').classList.toggle('open');document.getElementById('sidebarOverlay').classList.toggle('active');return false;">
    <i class="bi bi-grid-3x3-gap"></i><span>Menü</span>
  </a>
</nav>

<?php if (function_exists('current_user') && current_user()): ?>
<?php require_once __DIR__ . '/ai_chat_widget.php'; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $rootPath ?? '' ?>assets/js/app.js?v=<?= time() ?>"></script>
<script>
/* PWA service worker'ı KALDIR + önbelleği temizle.
 * Eski service worker dashboard'un eski HTML kopyasını dondurup gösteriyordu.
 * Artık kaydetmiyoruz; mevcut olan varsa kaldırıyoruz ve tüm PWA önbelleğini
 * siliyoruz. Böylece her sayfa daima sunucudan taze gelir, donma yaşanmaz. */
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(function(regs){
        regs.forEach(function(r){ r.unregister(); });
    }).catch(function(){});
}
if (window.caches && caches.keys) {
    caches.keys().then(function(keys){
        keys.forEach(function(k){ caches.delete(k); });
    }).catch(function(){});
}
</script>
</body>
</html>
