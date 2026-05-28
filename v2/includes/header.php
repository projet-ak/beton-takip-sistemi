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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<!-- Tema rengi + dark mode CSS değişkenleri -->
<style id="themeStyle"></style>
<script>
(function(){
  // --- Tema rengi ---
  var t = localStorage.getItem('beton_tema') || 'mavi';
  var renkler = {
    mavi:    {pri:'#0d6efd', dark:'#0a58ca'},
    yesil:   {pri:'#198754', dark:'#146c43'},
    kirmizi: {pri:'#dc3545', dark:'#b02a37'},
    mor:     {pri:'#6f42c1', dark:'#59359a'},
    turuncu: {pri:'#fd7e14', dark:'#ca6510'},
    petrol:  {pri:'#0dcaf0', dark:'#0aa2c0'},
    koyu:    {pri:'#343a40', dark:'#212529'},
  };
  var r = renkler[t] || renkler.mavi;
  document.getElementById('themeStyle').textContent =
    ':root{--bs-primary:'+r.pri+';--bs-primary-rgb:'+hexToRgb(r.pri)+';--bs-link-color:'+r.pri+'}'+
    '.bg-primary{background-color:'+r.pri+'!important}'+
    '.btn-primary{background-color:'+r.pri+';border-color:'+r.pri+'}'+
    '.btn-primary:hover{background-color:'+r.dark+';border-color:'+r.dark+'}'+
    '.text-primary{color:'+r.pri+'!important}'+
    '.border-primary{border-color:'+r.pri+'!important}'+
    '.nav-link.active{color:'+r.pri+'!important}';
  function hexToRgb(h){var r=parseInt(h.slice(1,3),16),g=parseInt(h.slice(3,5),16),b=parseInt(h.slice(5,7),16);return r+','+g+','+b;}
  // --- Dark mode (render öncesi uygula, flash yok) ---
  if (localStorage.getItem('beton_dark') === '1') {
    document.documentElement.setAttribute('data-dark', '1');
  }
})();
</script>
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

                <!-- Hızlı Tarama: herkese -->
                <li class="nav-item">
                    <a class="nav-link <?= $__page === 'hizli_tarama.php' ? 'active' : '' ?>"
                       href="<?= $__rootPath ?>hizli_tarama.php">
                        <i class="bi bi-qr-code-scan"></i> Hızlı Tarama
                    </a>
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

                <!-- Projeler: admin + teknik_ofis_admin (ana menüde) -->
                <?php if (can_manage_definitions()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $__page === 'projeler.php' ? 'active' : '' ?>"
                       href="<?= $__rootPath ?>projeler.php">
                        <i class="bi bi-diagram-3"></i> Projeler
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
                        'kivam_siniflari.php',
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

            <!-- Sağ: dark mode butonu + kullanıcı bilgisi + çıkış -->
            <?php if ($__user): ?>
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item">
                    <button class="btn btn-link nav-link px-2 py-1" id="darkBtn"
                            onclick="toggleDark()" title="Karanlık / Aydınlık Mod"
                            style="color:rgba(255,255,255,.82);font-size:1.1rem;">
                        <i id="darkIcon" class="bi bi-moon-stars-fill"></i>
                    </button>
                </li>
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
                            <a class="dropdown-item" href="#" onclick="document.getElementById('temaPaneli').classList.toggle('d-none');return false;">
                                <i class="bi bi-palette me-1"></i> Tema Rengi
                            </a>
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
<!-- Tema Paneli -->
<div id="temaPaneli" class="d-none position-fixed top-0 end-0 mt-5 me-2 card shadow-lg" style="z-index:9999;min-width:220px;">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center py-2">
        <span><i class="bi bi-palette me-1"></i>Tema Rengi</span>
        <button onclick="document.getElementById('temaPaneli').classList.add('d-none')" class="btn-close btn-sm"></button>
    </div>
    <div class="card-body p-3">
        <div class="d-flex flex-wrap gap-2">
            <?php
            $temalar = [
                'mavi'    => ['renk'=>'#0d6efd','etiket'=>'Mavi'],
                'yesil'   => ['renk'=>'#198754','etiket'=>'Yeşil'],
                'kirmizi' => ['renk'=>'#dc3545','etiket'=>'Kırmızı'],
                'mor'     => ['renk'=>'#6f42c1','etiket'=>'Mor'],
                'turuncu' => ['renk'=>'#fd7e14','etiket'=>'Turuncu'],
                'petrol'  => ['renk'=>'#0dcaf0','etiket'=>'Petrol'],
                'koyu'    => ['renk'=>'#343a40','etiket'=>'Koyu'],
            ];
            foreach ($temalar as $key => $t): ?>
            <button onclick="temaDegistir('<?= $key ?>')"
                    class="btn btn-sm rounded-circle p-0"
                    style="width:36px;height:36px;background:<?= $t['renk'] ?>;border:3px solid rgba(0,0,0,.1);"
                    title="<?= $t['etiket'] ?>">
            </button>
            <?php endforeach; ?>
        </div>
        <div class="mt-3 small text-muted">Seçim tarayıcıda kaydedilir.</div>
    </div>
</div>
<script>
function temaDegistir(tema) {
    localStorage.setItem('beton_tema', tema);
    location.reload();
}
function toggleDark() {
    var isDark = document.documentElement.getAttribute('data-dark') === '1';
    var next = isDark ? '0' : '1';
    document.documentElement.setAttribute('data-dark', next);
    localStorage.setItem('beton_dark', next);
    var icon = document.getElementById('darkIcon');
    if (icon) icon.className = next === '1' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
}
// İkon durumunu sayfa yüklenmesinde güncelle
document.addEventListener('DOMContentLoaded', function() {
    var icon = document.getElementById('darkIcon');
    if (icon && document.documentElement.getAttribute('data-dark') === '1') {
        icon.className = 'bi bi-sun-fill';
    }
});
</script>
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
