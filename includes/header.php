<?php
/**
 * header.php — ERN Holding Beton Takip Sistemi | Sidebar Layout v3
 */
if (!function_exists('current_user'))  require_once __DIR__ . '/auth.php';
if (!function_exists('role_label'))    require_once __DIR__ . '/functions.php';

$__user     = current_user();
$__page     = basename($_SERVER['PHP_SELF']);
$__rootPath = $rootPath ?? '';
// Aktif modül: demir/ → "demir", seramik/ → "seramik", diğerleri "beton"
$__module   = (strpos($_SERVER['PHP_SELF'] ?? '', '/demir/') !== false) ? 'demir'
            : ((strpos($_SERVER['PHP_SELF'] ?? '', '/seramik/') !== false) ? 'seramik' : 'beton');
$__modAd    = ['beton'=>'Beton Takip','demir'=>'Demir Takip','seramik'=>'Seramik Takip'][$__module] ?? 'Beton Takip';

function __isActive(string $page): string {
    global $__page; return $__page === $page ? 'active' : '';
}
$__initials = '';
if ($__user) {
    $parts = explode(' ', trim($__user['full_name'] ?? $__user['username'] ?? 'U'));
    foreach (array_slice($parts, 0, 2) as $p) $__initials .= mb_strtoupper(mb_substr($p, 0, 1));
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#00584E">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Beton Takip">
<title><?= h($pageTitle ?? 'Beton Takip Sistemi') ?></title>
<link rel="icon" type="image/png" href="https://ern.com.tr/favicon.png">
<link rel="apple-touch-icon" href="https://ern.com.tr/favicon.png">
<link rel="manifest" href="<?= $__rootPath ?>manifest.json">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap">
<script>(function(){var d=localStorage.getItem('beton_dark')||(window.matchMedia('(prefers-color-scheme:dark)').matches?'1':'0');document.documentElement.setAttribute('data-dark',d);})();</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="<?= $__rootPath ?>assets/css/style.css?v=<?= time() ?>">
</head>
<body>
<div class="ern-wrapper">

<!-- SIDEBAR -->
<aside class="ern-sidebar" id="ernSidebar">

  <div class="sidebar-brand-wrap">
    <a href="<?= $__rootPath ?><?= $__module==='demir' ? 'demir/index.php' : ($__module==='seramik' ? 'seramik/index.php' : 'index.php') ?>" class="sidebar-brand">
      <!-- Tam logo (expanded modda) -->
      <img class="logo-full"
           src="https://portal.ern.com.tr/assets/assets/images/ern_holding.613de732dd156fc8c966aeb8159822be.png"
           alt="ERN Holding" style="height:32px;">
      <!-- Favicon (collapsed modda) -->
      <img class="logo-icon"
           src="https://ern.com.tr/favicon.png"
           alt="ERN" style="display:none;width:32px;height:32px;object-fit:contain;filter:brightness(0) invert(1);">
      <div class="sidebar-brand-label"><strong><?= h($__modAd) ?></strong>Sistemi</div>
    </a>
    <button class="sidebar-collapse-btn d-none d-lg-flex" id="sidebarCollapseBtn" title="Menüyü daralt">
      <i class="bi bi-layout-sidebar-reverse" id="sidebarCollapseIcon"></i>
    </button>
  </div>

  <div class="sidebar-scroll">
    <?php if($__module==='beton'): ?>
    <ul class="list-unstyled mb-0">

      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('index.php') ?>" href="<?= $__rootPath ?>index.php" data-label="Dashboard">
          <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
      </li>

      <?php $__irsA = in_array($__page,['irsaliyeler.php','irsaliye_form.php','irsaliye_detay.php'],true); ?>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= $__irsA?'active':'' ?>" href="#subIrs" data-bs-toggle="collapse" role="button" aria-expanded="<?= $__irsA?'true':'false' ?>" data-label="İrsaliyeler">
          <i class="bi bi-file-earmark-text"></i><span>İrsaliyeler</span><i class="bi bi-chevron-right chev"></i>
        </a>
        <div class="collapse <?= $__irsA?'show':'' ?>" id="subIrs">
          <ul class="list-unstyled sidebar-sub">
            <li><a class="sidebar-sub-link <?= (__isActive('irsaliyeler.php')&&(($_GET['tip']??'')==='alis'))?'active':'' ?>" href="<?= $__rootPath ?>irsaliyeler.php?tip=alis">Alış İrsaliyeleri</a></li>
            <li><a class="sidebar-sub-link <?= (__isActive('irsaliyeler.php')&&(($_GET['tip']??'')==='iade'))?'active':'' ?>" href="<?= $__rootPath ?>irsaliyeler.php?tip=iade">İade İrsaliyeleri</a></li>
            <?php if(can_edit()): ?><li><a class="sidebar-sub-link <?= __isActive('irsaliye_form.php') ?>" href="<?= $__rootPath ?>irsaliye_form.php">Yeni İrsaliye Ekle</a></li><?php endif; ?>
          </ul>
        </div>
      </li>

      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('hizli_tarama.php') ?>" href="<?= $__rootPath ?>hizli_tarama.php" data-label="Hızlı Tarama">
          <i class="bi bi-qr-code-scan"></i><span>Hızlı Tarama</span>
        </a>
      </li>

      <?php if(can_view_reports()): ?>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('zayiat_takip.php') ?>" href="<?= $__rootPath ?>zayiat_takip.php" data-label="Zayiat Takip">
          <i class="bi bi-graph-down-arrow"></i><span>Zayiat Takip</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('prp_ustyapi.php') ?>" href="<?= $__rootPath ?>prp_ustyapi.php" data-label="Bina Üstyapı Zayiat (PRP İnşaat)">
          <i class="bi bi-building-gear"></i><span>Bina Üstyapı</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('temel_kazik.php') ?>" href="<?= $__rootPath ?>temel_kazik.php" data-label="Temel & Kazık İmalatları">
          <i class="bi bi-cone-striped"></i><span>Temel &amp; Kazık</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('istinat.php') ?>" href="<?= $__rootPath ?>istinat.php" data-label="İstinat Duvarları">
          <i class="bi bi-bricks"></i><span>İstinat Duvarları</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('icmal_beton.php') ?>" href="<?= $__rootPath ?>icmal_beton.php" data-label="Beton İcmali">
          <i class="bi bi-clipboard-data"></i><span>Beton İcmali</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('metraj_sayfasi.php') ?>" href="<?= $__rootPath ?>metraj_sayfasi.php" data-label="Metraj">
          <i class="bi bi-rulers"></i><span>Metraj</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('mobilizasyon.php') ?>" href="<?= $__rootPath ?>mobilizasyon.php" data-label="Mobilizasyon">
          <i class="bi bi-truck"></i><span>Mobilizasyon</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('raporlar.php') ?>" href="<?= $__rootPath ?>raporlar.php" data-label="Raporlar">
          <i class="bi bi-bar-chart-line"></i><span>Raporlar</span>
        </a>
      </li>
      <?php endif; ?>

      <?php if(can_manage_definitions()): ?>
      <li class="sidebar-nav-item mt-1"><div class="nav-section">Yönetim</div></li>

      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('projeler.php') ?>" href="<?= $__rootPath ?>projeler.php" data-label="Projeler">
          <i class="bi bi-diagram-3"></i><span>Projeler</span>
        </a>
      </li>

      <?php $__tanA = in_array($__page,['tedarikciler.php','beton_siniflari.php','katki_listesi.php','pompa_turleri.php','firmalar.php','imalat_gruplari.php','ana_is_kalemleri.php','parseller.php','bloklar.php','kotlar.php','kivam_siniflari.php'],true); ?>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= $__tanA?'active':'' ?>" href="#subTan" data-bs-toggle="collapse" role="button" aria-expanded="<?= $__tanA?'true':'false' ?>" data-label="Tanımlar">
          <i class="bi bi-sliders"></i><span>Tanımlar</span><i class="bi bi-chevron-right chev"></i>
        </a>
        <div class="collapse <?= $__tanA?'show':'' ?>" id="subTan">
          <ul class="list-unstyled sidebar-sub">
            <li><a class="sidebar-sub-link <?= __isActive('beton_siniflari.php') ?>" href="<?= $__rootPath ?>beton_siniflari.php">Beton Sınıfları</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('katki_listesi.php') ?>"  href="<?= $__rootPath ?>katki_listesi.php">Katkı Listesi</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('pompa_turleri.php') ?>"  href="<?= $__rootPath ?>pompa_turleri.php">Pompa Türleri</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('kivam_siniflari.php') ?>" href="<?= $__rootPath ?>kivam_siniflari.php">Kıvam Sınıfları</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('parseller.php') ?>"  href="<?= $__rootPath ?>parseller.php">Parseller</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('bloklar.php') ?>"    href="<?= $__rootPath ?>bloklar.php">Bloklar</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('kotlar.php') ?>"     href="<?= $__rootPath ?>kotlar.php">Kotlar</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('imalat_gruplari.php') ?>"  href="<?= $__rootPath ?>imalat_gruplari.php">İmalat Grupları</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('ana_is_kalemleri.php') ?>" href="<?= $__rootPath ?>ana_is_kalemleri.php">Ana İş Kalemleri</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('firmalar.php') ?>"     href="<?= $__rootPath ?>firmalar.php">Firmalar</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('tedarikciler.php') ?>" href="<?= $__rootPath ?>tedarikciler.php">Tedarikçiler</a></li>
          </ul>
        </div>
      </li>
      <?php endif; ?>

      <?php if(can_manage_users()): ?>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('kullanicilar.php') ?>" href="<?= $__rootPath ?>kullanicilar.php" data-label="Kullanıcılar">
          <i class="bi bi-people"></i><span>Kullanıcılar</span>
        </a>
      </li>
      <?php endif; ?>

      <?php if(is_admin()): ?>
      <?php $__araA = in_array($__page,['yedek.php','import.php','ai_ayarlar.php','veri_kontrol.php'],true); ?>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= $__araA?'active':'' ?>" href="#subAra" data-bs-toggle="collapse" role="button" aria-expanded="<?= $__araA?'true':'false' ?>">
          <i class="bi bi-tools"></i><span>Araçlar</span><i class="bi bi-chevron-right chev"></i>
        </a>
        <div class="collapse <?= $__araA?'show':'' ?>" id="subAra">
          <ul class="list-unstyled sidebar-sub">
            <li><a class="sidebar-sub-link <?= __isActive('yedek.php') ?>"      href="<?= $__rootPath ?>yedek.php">Yedekleme</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('import.php') ?>"     href="<?= $__rootPath ?>import.php">Excel Aktarımı</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('veri_kontrol.php') ?>" href="<?= $__rootPath ?>veri_kontrol.php"><i class="bi bi-shield-check me-1"></i>Veri Kontrol</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('onbellek_temizle.php') ?>" href="<?= $__rootPath ?>onbellek_temizle.php"><i class="bi bi-arrow-clockwise me-1"></i>Önbellek Temizle</a></li>
            <li><a class="sidebar-sub-link <?= __isActive('ai_ayarlar.php') ?>" href="<?= $__rootPath ?>ai_ayarlar.php"><i class="bi bi-stars me-1"></i>AI Ayarları</a></li>
          </ul>
        </div>
      </li>
      <?php endif; ?>

    </ul>
    <?php else: /* ── DEMİR MODÜLÜ MENÜSÜ ── */ ?>
    <ul class="list-unstyled mb-0">
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('index.php') ?>" href="<?= $__rootPath ?>demir/index.php" data-label="Dashboard">
          <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('sevkiyatlar.php') ?>" href="<?= $__rootPath ?>demir/sevkiyatlar.php" data-label="Sevkiyatlar">
          <i class="bi bi-truck"></i><span>Sevkiyatlar</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('siparisler.php') ?>" href="<?= $__rootPath ?>demir/siparisler.php" data-label="Siparişler">
          <i class="bi bi-cart-check"></i><span>Siparişler</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('tutanaklar.php') ?>" href="<?= $__rootPath ?>demir/tutanaklar.php" data-label="Tutanaklar">
          <i class="bi bi-file-earmark-check"></i><span>Tutanaklar</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('tutanak_takip.php') ?>" href="<?= $__rootPath ?>demir/tutanak_takip.php" data-label="Tutanak Takip">
          <i class="bi bi-journal-text"></i><span>Tutanak Takip</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('iade_tutanaklar.php').__isActive('iade_form.php').__isActive('iade_detay.php') ?>" href="<?= $__rootPath ?>demir/iade_tutanaklar.php" data-label="İade Tutanakları">
          <i class="bi bi-arrow-return-left"></i><span>İade Tutanakları</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('taseron_bakiye.php') ?>" href="<?= $__rootPath ?>demir/taseron_bakiye.php" data-label="Taşeron Bakiye">
          <i class="bi bi-wallet2"></i><span>Taşeron Bakiye</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('sozlesmeler.php') ?>" href="<?= $__rootPath ?>demir/sozlesmeler.php" data-label="Sözleşmeler">
          <i class="bi bi-journal-bookmark"></i><span>Sözleşmeler</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('icmal.php') ?>" href="<?= $__rootPath ?>demir/icmal.php" data-label="İcmal">
          <i class="bi bi-clipboard-data"></i><span>İcmal</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('raporlar.php') ?>" href="<?= $__rootPath ?>demir/raporlar.php" data-label="Raporlar">
          <i class="bi bi-bar-chart-line"></i><span>Raporlar</span>
        </a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('proje_disi.php') ?>" href="<?= $__rootPath ?>demir/proje_disi.php" data-label="Proje Dışı İşler">
          <i class="bi bi-box-arrow-up-right"></i><span>Proje Dışı İşler</span>
        </a>
      </li>
      <?php if(can_manage_definitions()): ?>
      <li class="sidebar-nav-item mt-1"><div class="nav-section">Tanımlar</div></li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('projeler.php') ?>" href="<?= $__rootPath ?>demir/projeler.php"><i class="bi bi-diagram-3"></i><span>Projeler</span></a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('caplar.php') ?>" href="<?= $__rootPath ?>demir/caplar.php"><i class="bi bi-rulers"></i><span>Çaplar</span></a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('tedarikciler.php') ?>" href="<?= $__rootPath ?>demir/tedarikciler.php"><i class="bi bi-shop"></i><span>Tedarikçiler</span></a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('taseronlar.php') ?>" href="<?= $__rootPath ?>demir/taseronlar.php"><i class="bi bi-people"></i><span>Taşeronlar</span></a>
      </li>
      <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if($__module==='seramik'): ?>
    <ul class="list-unstyled mb-0">
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('index.php') ?>" href="<?= $__rootPath ?>seramik/index.php" data-label="Dashboard">
          <i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('girisler.php').__isActive('giris_form.php') ?>" href="<?= $__rootPath ?>seramik/girisler.php" data-label="Ambar Giriş">
          <i class="bi bi-box-arrow-in-down"></i><span>Ambar Giriş</span></a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('cikislar.php').__isActive('cikis_form.php') ?>" href="<?= $__rootPath ?>seramik/cikislar.php" data-label="Ambar Çıkış">
          <i class="bi bi-box-arrow-up"></i><span>Ambar Çıkış</span></a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('stok.php') ?>" href="<?= $__rootPath ?>seramik/stok.php" data-label="Stok / Ambar Mevcut">
          <i class="bi bi-boxes"></i><span>Stok (Mevcut)</span></a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('paletler.php') ?>" href="<?= $__rootPath ?>seramik/paletler.php" data-label="Palet Durumu">
          <i class="bi bi-pallet"></i><span>Palet Durumu</span></a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('import.php') ?>" href="<?= $__rootPath ?>seramik/import.php" data-label="Excel İçe Aktar">
          <i class="bi bi-cloud-arrow-up"></i><span>Excel İçe Aktar</span></a>
      </li>
      <li class="sidebar-heading">Tanımlar</li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('malzemeler.php') ?>" href="<?= $__rootPath ?>seramik/malzemeler.php"><i class="bi bi-grid-3x3"></i><span>Malzemeler</span></a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('firmalar.php') ?>" href="<?= $__rootPath ?>seramik/firmalar.php"><i class="bi bi-shop"></i><span>Firmalar</span></a>
      </li>
      <li class="sidebar-nav-item">
        <a class="sidebar-nav-link <?= __isActive('taseronlar.php') ?>" href="<?= $__rootPath ?>seramik/taseronlar.php"><i class="bi bi-people"></i><span>Taşeronlar</span></a>
      </li>
    </ul>
    <?php endif; ?>
  </div>

  <?php if($__user): ?>
  <div class="sidebar-user">

    <!-- Hover popup -->
    <div class="sidebar-user-popup">
      <div class="popup-user-info">
        <div class="popup-avatar"><?= h($__initials?:'U') ?></div>
        <div>
          <div class="popup-name"><?= h($__user['full_name']?:$__user['username']) ?></div>
          <div class="popup-role"><?= role_label($__user['role']) ?></div>
        </div>
      </div>
      <a href="<?= $__rootPath ?>logout.php" class="popup-logout">
        <i class="bi bi-box-arrow-right"></i> Çıkış Yap
      </a>
    </div>

    <div class="sidebar-avatar"><?= h($__initials?:'U') ?></div>
    <div class="sidebar-user-info">
      <div class="sidebar-user-name"><?= h($__user['full_name']?:$__user['username']) ?></div>
      <div class="sidebar-user-role"><?= role_label($__user['role']) ?></div>
    </div>
    <a href="<?= $__rootPath ?>logout.php" class="sidebar-logout" title="Çıkış"><i class="bi bi-box-arrow-right"></i></a>
  </div>
  <?php endif; ?>

</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- MAIN -->
<div class="ern-main" id="ernMain">

  <header class="ern-topbar">
    <button class="topbar-menu-btn" id="sidebarToggleBtn"><i class="bi bi-list fs-5"></i></button>

    <?php if($__user): ?>
    <!-- Modül geçişi: Beton / Demir -->
    <style>
    .module-switch{display:inline-flex;gap:.2rem;background:var(--bt-surface-2,rgba(0,0,0,.05));border:1px solid var(--bt-border,rgba(0,0,0,.08));border-radius:999px;padding:.2rem;}
    .module-switch-item{display:inline-flex;align-items:center;gap:.4rem;padding:.34rem .8rem;border-radius:999px;font-size:.8rem;font-weight:600;color:var(--bt-text-soft,#64748b);text-decoration:none;white-space:nowrap;transition:.15s;}
    .module-switch-item.active{background:var(--ern,#00584E);color:#fff;box-shadow:0 2px 6px rgba(0,88,78,.3);}
    .module-switch-item:not(.active):hover{color:var(--ern,#00584E);}
    @media (max-width:575.98px){.module-switch-item span{display:none;}.module-switch-item{padding:.34rem .55rem;}}
    </style>
    <div class="module-switch">
      <a href="<?= $__rootPath ?>index.php" class="module-switch-item <?= $__module==='beton'?'active':'' ?>">
        <i class="bi bi-buildings"></i><span>Beton Takip</span>
      </a>
      <a href="<?= $__rootPath ?>demir/index.php" class="module-switch-item <?= $__module==='demir'?'active':'' ?>">
        <i class="bi bi-rulers"></i><span>Demir Takip</span>
      </a>
      <a href="<?= $__rootPath ?>seramik/index.php" class="module-switch-item <?= $__module==='seramik'?'active':'' ?>">
        <i class="bi bi-grid-1x2"></i><span>Seramik Takip</span>
      </a>
    </div>
    <?php endif; ?>

    <div class="topbar-title d-none d-lg-block">
      <?php
      $__bc=['index.php'=>'Dashboard','irsaliyeler.php'=>'İrsaliyeler','irsaliye_form.php'=>'İrsaliye Formu','irsaliye_detay.php'=>'İrsaliye Detayı','hizli_tarama.php'=>'Hızlı Tarama','raporlar.php'=>'Raporlar','projeler.php'=>'Projeler','kullanicilar.php'=>'Kullanıcılar','tedarikciler.php'=>'Tedarikçiler','beton_siniflari.php'=>'Beton Sınıfları','yedek.php'=>'Yedekleme','import.php'=>'Excel Aktarımı','ai_ayarlar.php'=>'AI Ayarları'];
      echo '<span style="color:var(--bt-text-soft);font-size:.78rem;">ERN Holding</span><span style="color:var(--bt-border);margin:0 .4rem;">/</span><span style="font-weight:600;font-size:.85rem;">'.h($__bc[$__page]??ucfirst(basename($__page,'.php'))).'</span>';
      ?>
    </div>
    <div class="topbar-actions ms-auto">
      <?php if($__user): ?>
      <?php $__sessRemain = max(0, SESSION_LIFETIME - (time() - ($_SESSION['last_activity'] ?? time()))); ?>
      <div class="session-timer" id="sessionTimer" title="Oturum süresi — her işlemde yenilenir. Bitince otomatik çıkış yapılır.">
        <i class="bi bi-clock-history"></i>
        <span id="sessionTimerText">--:--</span>
      </div>
      <?php endif; ?>
      <button class="btn-topbar" id="darkToggleBtn" title="Tema"><i class="bi bi-moon-stars-fill" id="darkToggleIcon"></i></button>
      <?php if($__user): ?>
      <div class="dropdown">
        <div class="topbar-user" data-bs-toggle="dropdown">
          <div class="topbar-user-avatar"><?= h($__initials?:'U') ?></div>
          <div class="d-none d-sm-block">
            <div class="topbar-user-name"><?= h($__user['full_name']?:$__user['username']) ?></div>
            <div class="topbar-user-role"><?= role_label($__user['role']) ?></div>
          </div>
          <i class="bi bi-chevron-down ms-1" style="font-size:.7rem;color:var(--bt-text-soft)"></i>
        </div>
        <ul class="dropdown-menu dropdown-menu-end mt-1" style="min-width:190px">
          <li><span class="dropdown-item-text small py-2 d-flex align-items-center gap-2">
            <div class="topbar-user-avatar" style="width:26px;height:26px;font-size:.7rem;"><?= h($__initials?:'U') ?></div>
            <div><div style="font-weight:600;font-size:.82rem"><?= h($__user['username']) ?></div><div style="font-size:.7rem;color:var(--bt-text-muted)"><?= role_label($__user['role']) ?></div></div>
          </span></li>
          <li><hr class="dropdown-divider my-1"></li>
          <li><a class="dropdown-item text-danger" href="<?= $__rootPath ?>logout.php"><i class="bi bi-box-arrow-right me-2"></i>Çıkış Yap</a></li>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </header>

  <div class="ern-content">
<?php
if(!function_exists('get_flash')) require_once __DIR__.'/functions.php';
foreach(['success'=>'check-circle-fill','error'=>'exclamation-triangle-fill','warning'=>'exclamation-circle-fill','info'=>'info-circle-fill'] as $__fk=>$__fi){
    $__fm=get_flash($__fk);
    if($__fm) echo '<div class="alert alert-'.($__fk==='error'?'danger':$__fk).' alert-dismissible fade show d-flex align-items-center gap-2 mb-3"><i class="bi bi-'.$__fi.'"></i><span>'.h($__fm).'</span><button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button></div>';
}
?>
<?php if($__user): ?>
<style>
.session-timer{display:inline-flex;align-items:center;gap:.35rem;font-size:.78rem;font-weight:600;
  font-variant-numeric:tabular-nums;padding:.28rem .6rem;border-radius:999px;line-height:1;
  color:var(--bt-text-soft,#64748b);background:var(--bt-surface-2,rgba(0,0,0,.04));
  border:1px solid var(--bt-border,rgba(0,0,0,.08));white-space:nowrap;transition:color .2s,background .2s,border-color .2s;}
.session-timer i{font-size:.85rem;}
.session-timer.warn{color:#b54708;background:rgba(251,189,35,.16);border-color:rgba(251,189,35,.5);}
.session-timer.danger{color:#fff;background:#dc3545;border-color:#dc3545;animation:sessionPulse 1s ease-in-out infinite;}
@keyframes sessionPulse{0%,100%{opacity:1}50%{opacity:.55}}
@media (max-width:575.98px){.session-timer span{display:none;}.session-timer{padding:.28rem .45rem;}}
</style>
<script>
(function(){
  var el = document.getElementById('sessionTimer');
  if(!el) return;
  var txt = document.getElementById('sessionTimerText');
  var TOTAL = <?= (int)SESSION_LIFETIME ?>;
  var remaining = <?= (int)$__sessRemain ?>;
  var logoutUrl = '<?= $__rootPath ?>logout.php';

  function reset(){ remaining = TOTAL; }
  function pad(n){ return (n < 10 ? '0' : '') + n; }
  function fmt(s){
    var h = Math.floor(s/3600), m = Math.floor((s%3600)/60), sec = s%60;
    return h > 0 ? h + ':' + pad(m) + ':' + pad(sec) : pad(m) + ':' + pad(sec);
  }
  function render(){
    txt.textContent = fmt(Math.max(0, remaining));
    el.classList.toggle('warn', remaining <= 300 && remaining > 60);
    el.classList.toggle('danger', remaining <= 60);
  }
  function tick(){
    remaining--;
    if(remaining <= 0){ window.location.href = logoutUrl; return; }
    render();
  }
  render();
  setInterval(tick, 1000);

  // Sunucuya giden her istek (tarama, kaydet, AI vb.) sayacı sıfırlasın —
  // çünkü sunucu tarafında da last_activity her istekte yenileniyor.
  if(window.fetch){
    var _fetch = window.fetch;
    window.fetch = function(){ reset(); render(); return _fetch.apply(this, arguments); };
  }
  if(window.XMLHttpRequest){
    var _send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function(){ reset(); render(); return _send.apply(this, arguments); };
  }
})();
</script>
<?php endif; ?>
