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

<!-- Alt Navigasyon (sadece mobil) -->
<?php
$__page = basename($_SERVER['PHP_SELF']);
$__rp   = $rootPath ?? '';
?>
<nav class="bottom-nav d-lg-none" id="bottomNav">
  <a href="<?= $__rp ?>index.php"         class="bottom-nav-item <?= $__page==='index.php'?'active':'' ?>">
    <i class="bi bi-speedometer2"></i><span>Dashboard</span>
  </a>
  <a href="<?= $__rp ?>irsaliyeler.php"   class="bottom-nav-item <?= $__page==='irsaliyeler.php'?'active':'' ?>">
    <i class="bi bi-file-earmark-text"></i><span>İrsaliye</span>
  </a>
  <a href="<?= $__rp ?>hizli_tarama.php"  class="bottom-nav-fab <?= $__page==='hizli_tarama.php'?'active':'' ?>" title="Hızlı Tara">
    <i class="bi bi-qr-code-scan"></i>
  </a>
  <a href="<?= $__rp ?>raporlar.php"      class="bottom-nav-item <?= $__page==='raporlar.php'?'active':'' ?>">
    <i class="bi bi-bar-chart-line"></i><span>Raporlar</span>
  </a>
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
