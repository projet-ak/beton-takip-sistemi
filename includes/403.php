<?php
/**
 * 403.php — Yetkisiz erişim sayfası
 *
 * `require_auth()` iki durumda buraya düşer: rol uyumsuzluğu ve **modül erişimi**
 * (users.modul_erisim). İkinci durumda $__403_mesaj doldurulur ve kullanıcıya
 * girebileceği modüller listelenir — çıkmaz sokakta kalmasın.
 */
$__mesaj = $GLOBALS['__403_mesaj'] ?? 'Bu sayfaya erişim yetkiniz yok.';
$__kok   = $GLOBALS['__403_kok']   ?? '/';
$__izin  = function_exists('modul_erisimi') ? modul_erisimi() : null;
$__liste = [];
if (defined('MODULLER')) {
    foreach (MODULLER as $k => [$ad, $ikon, $sayfa]) {
        if ($__izin === null || in_array($k, $__izin, true)) $__liste[] = [$ad, $ikon, $sayfa];
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Yetkisiz Erişim</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="text-center p-4" style="max-width:560px">
    <div class="display-1 text-danger mb-2">403</div>
    <h5 class="mb-3"><?= htmlspecialchars($__mesaj, ENT_QUOTES, 'UTF-8') ?></h5>
    <?php if ($__liste): ?>
        <p class="text-muted small mb-2">Erişebildiğiniz modüller:</p>
        <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
        <?php foreach ($__liste as [$ad, $ikon, $sayfa]): ?>
            <a href="<?= htmlspecialchars($__kok . $sayfa, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi <?= htmlspecialchars($ikon, ENT_QUOTES, 'UTF-8') ?> me-1"></i><?= htmlspecialchars($ad, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="text-muted small">Hiçbir modüle erişiminiz tanımlanmamış. Yöneticinize başvurun.</p>
    <?php endif; ?>
    <a href="<?= htmlspecialchars($__kok . 'logout.php', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-link btn-sm text-muted">Çıkış yap</a>
</div>
</body>
</html>
