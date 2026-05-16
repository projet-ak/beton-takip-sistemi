<?php
/**
 * header.php — Sayfa başlığı ve rol tabanlı navigasyon çubuğu
 *
 * Bu dosya include edilmeden önce includes/auth.php require edilmiş olmalıdır.
 * Değişkenler:
 *   $pageTitle  (string) — sekme başlığı
 *   $rootPath   (string) — alt dizinlerde çalışırken '../' gibi prefix
 */
if (!function_exists('current_user')) {
    require_once __DIR__ . '/auth.php';
}
if (!function_exists('role_label')) {
    require_once __DIR__ . '/functions.php';
}

$__user     = current_user();
$__page     = basename($_SERVER['PHP_SELF']);
$__rootPath = $rootPath ?? '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'Beton Takip Sistemi') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="<?= $__rootPath ?>assets/css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= $__rootPath ?>index.php">
            <i class="bi bi-building-fill-check fs-5"></i>
            <span>Beton Takip</span>
        </a>
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#navMenu"
                aria-controls="navMenu" aria-expanded="false" aria-label="Menüyü aç">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                <!-- Dashboard: herkese -->
                <li class="nav-item">
                    <a class="nav-link <?= $__page === 'index.php' ? 'active' : '' ?>"
                       href="<?= $__rootPath ?>index.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>

                <!-- İrsaliyeler: herkese -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($__page, ['irsaliyeler.php','irsaliye_yeni.php','irsaliye_iade.php','irsaliye_detay.php'], true) ? 'active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-file-earmark-text"></i> İrsaliyeler
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="<?= $__rootPath ?>irsaliyeler.php?tip=alis">
                                <i class="bi bi-arrow-down-circle text-success me-1"></i> Alış İrsaliyeleri
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?= $__rootPath ?>irsaliyeler.php?tip=iade">
                                <i class="bi bi-arrow-up-circle text-danger me-1"></i> İade İrsaliyeleri
                            </a>
                        </li>
                        <?php if (can_edit()): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?= $__rootPath ?>irsaliye_form.php">
                                <i class="bi bi-plus-circle text-primary me-1"></i> Yeni İrsaliye Ekle
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>

                <!-- Raporlar: admin + teknik_ofis_admin + teknik_ofis -->
                <?php if (can_view_reports()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $__page === 'raporlar.php' ? 'active' : '' ?>"
                       href="<?= $__rootPath ?>raporlar.php">
                        <i class="bi bi-bar-chart-line"></i> Raporlar
                    </a>
                </li>
                <?php endif; ?>

                <!-- Tanımlar: admin + teknik_ofis_admin -->
                <?php if (can_manage_definitions()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($__page, [
                        'tedarikciler.php','beton_siniflari.php','katki_listesi.php',
                        'pompa_turleri.php','firmalar.php','imalat_gruplari.php',
                        'ana_is_kalemleri.php','parseller.php','bloklar.php','kotlar.php',
                        'kivam_siniflari.php','projeler.php',
                    ], true) ? 'active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-sliders"></i> Tanımlar
                    </a>
                    <ul class="dropdown-menu">
                        <li><h6 class="dropdown-header">Beton &amp; Malzeme</h6></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>beton_siniflari.php">
                            <i class="bi bi-layers me-1"></i> Beton Sınıfları
                        </a></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>katki_listesi.php">
                            <i class="bi bi-flask me-1"></i> Katkı Listesi
                        </a></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>pompa_turleri.php">
                            <i class="bi bi-tools me-1"></i> Pompa Türleri
                        </a></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>kivam_siniflari.php">
                            <i class="bi bi-speedometer me-1"></i> Kıvam Sınıfları
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Projeler</h6></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>projeler.php"><i class="bi bi-diagram-3 me-1"></i> Projeler</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Lokasyon</h6></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>parseller.php">
                            <i class="bi bi-map me-1"></i> Parseller
                        </a></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>bloklar.php">
                            <i class="bi bi-buildings me-1"></i> Bloklar
                        </a></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>kotlar.php">
                            <i class="bi bi-arrow-up-square me-1"></i> Kotlar
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">İş Kalemleri</h6></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>imalat_gruplari.php">
                            <i class="bi bi-collection me-1"></i> İmalat Grupları
                        </a></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>ana_is_kalemleri.php">
                            <i class="bi bi-list-task me-1"></i> Ana İş Kalemleri
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Firmalar</h6></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>firmalar.php">
                            <i class="bi bi-briefcase me-1"></i> Firmalar
                        </a></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>tedarikciler.php">
                            <i class="bi bi-truck me-1"></i> Tedarikçiler
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Kullanıcılar: sadece admin -->
                <?php if (can_manage_users()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $__page === 'kullanicilar.php' ? 'active' : '' ?>"
                       href="<?= $__rootPath ?>kullanicilar.php">
                        <i class="bi bi-people"></i> Kullanıcılar
                    </a>
                </li>
                <?php endif; ?>

                <!-- Yedekleme + Aktarım: sadece admin -->
                <?php if (is_admin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($__page, ['yedek.php','import.php'], true) ? 'active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-tools"></i> Araçlar
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>yedek.php">
                            <i class="bi bi-shield-check me-1"></i> Yedekleme
                        </a></li>
                        <li><a class="dropdown-item" href="<?= $__rootPath ?>import.php">
                            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel Aktarımı
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>

            </ul>

            <!-- Sağ: kullanıcı bilgisi + çıkış -->
            <?php if ($__user): ?>
            <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle fs-5"></i>
                        <span class="d-none d-md-inline"><?= h($__user['full_name'] ?: $__user['username']) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <span class="dropdown-item-text small text-muted">
                                <i class="bi bi-person-badge me-1"></i><?= h($__user['username']) ?>
                            </span>
                        </li>
                        <li>
                            <span class="dropdown-item-text small text-muted">
                                <i class="bi bi-shield-check me-1"></i><?= role_label($__user['role']) ?>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?= $__rootPath ?>logout.php">
                                <i class="bi bi-box-arrow-right me-1"></i> Çıkış Yap
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-4">

<?php
// Flash mesajlarını göster
if (!function_exists('get_flash')) {
    require_once __DIR__ . '/functions.php';
}
$__flashTypes = ['success' => 'check-circle', 'error' => 'exclamation-triangle', 'warning' => 'exclamation-circle', 'info' => 'info-circle'];
foreach ($__flashTypes as $__fkey => $__icon) {
    $__fmsg = get_flash($__fkey);
    if ($__fmsg) {
        $__bsType = $__fkey === 'error' ? 'danger' : $__fkey;
        echo '<div class="alert alert-' . $__bsType . ' alert-dismissible fade show d-flex align-items-center gap-2" role="alert">'
           . '<i class="bi bi-' . $__icon . '-fill"></i>'
           . '<span>' . h($__fmsg) . '</span>'
           . '<button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Kapat"></button>'
           . '</div>';
    }
}
?>
