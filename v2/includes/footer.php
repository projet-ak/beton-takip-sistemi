</div><!-- /container-fluid -->

<?php
// Uygulama versiyonu — büyük güncellemelerde v3, v4, vb. olarak artır
if (!defined('APP_VERSION')) define('APP_VERSION', '2.0');
?>
<footer class="border-top mt-4 py-3 text-center text-muted small bg-white">
    <span><i class="bi bi-building-fill-check text-primary"></i> Beton Takip Sistemi</span>
    &mdash; &copy; <?= date('Y') ?> Tüm hakları saklıdır.
    <span class="ms-2 opacity-50">v<?= APP_VERSION ?></span>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $rootPath ?? '' ?>assets/js/app.js"></script>
</body>
</html>
