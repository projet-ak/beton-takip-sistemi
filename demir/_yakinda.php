<?php
/**
 * demir/_yakinda.php — Henüz geliştirilmemiş demir sayfaları için ortak şablon.
 * Kullanım: çağıran dosya $YB_BASLIK, $YB_ICON, $YB_ACIKLAMA tanımlar.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();

$pageTitle = ($YB_BASLIK ?? 'Demir Takip') . ' — Demir Takip';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi <?= h($YB_ICON ?? 'bi-hourglass-split') ?> text-dark me-2"></i><?= h($YB_BASLIK ?? '') ?></h4>
    <span class="badge bg-warning text-dark">Yakında</span>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi <?= h($YB_ICON ?? 'bi-hourglass-split') ?> text-muted" style="font-size:3rem;opacity:.4"></i>
        <h5 class="mt-3 mb-2"><?= h($YB_BASLIK ?? '') ?> — Geliştiriliyor</h5>
        <p class="text-muted mb-0"><?= h($YB_ACIKLAMA ?? 'Bu bölüm yakında eklenecek.') ?></p>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
