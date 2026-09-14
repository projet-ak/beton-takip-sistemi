<?php
/**
 * pts/index.php — Personel Takip dashboard
 *
 * Tek soruya cevap verir: "şu an sahada kim var, bugün ne oldu, kart dağıtımı bitti mi".
 *
 * ⚠ Her KPI kartının bağlantısı KENDİ sayısını veren listeyi açmalı (IT dashboard'unda
 * bu kural bozulunca kart "1" derken liste boş açılıyordu — bkz. CLAUDE.md).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu','saha_sefi']);
require_once __DIR__ . '/../includes/db_pts.php';
require_once __DIR__ . '/_ortak.php';

pts_semasi_kur($pdoPts);
$pageTitle = 'Personel Takip';

$o       = pts_ozet($pdoPts);
$bugun   = date('Y-m-d');
$iceride = pts_iceridekiler($pdoPts);

// Son 14 günün giriş yapan personel sayısı — "saha ne kadar doluydu" trendi
$seri = [];
for ($i = 13; $i >= 0; $i--) $seri[date('Y-m-d', strtotime("-$i day"))] = 0;
$st = $pdoPts->prepare("SELECT zaman, personel_id FROM pts_hareketler WHERE yon='giris' AND zaman >= ?");
$st->execute([array_key_first($seri) . ' 00:00:00']);
$gunKisi = [];
foreach ($st->fetchAll() as $r) {
    $g = substr((string)$r['zaman'], 0, 10);
    if (isset($seri[$g])) $gunKisi[$g][(int)$r['personel_id']] = true;
}
foreach ($gunKisi as $g => $kume) $seri[$g] = count($kume);

// Bugünün puantajı — "kim ne kadar kaldı"
$bugunP = pts_gunluk($pdoPts, $bugun, $bugun);

$sonHareket = $pdoPts->query("SELECT h.*, p.ad, p.soyad, n.kod AS nokta_kod
                                FROM pts_hareketler h
                                JOIN pts_personel p ON p.id = h.personel_id
                                LEFT JOIN pts_noktalar n ON n.id = h.nokta_id
                               ORDER BY h.zaman DESC, h.id DESC LIMIT 10")->fetchAll();

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i>Personel Takip</h4>
  <span class="text-muted small">ArUco kartlı giriş-çıkış</span>
  <div class="ms-auto d-flex gap-2">
    <a href="puantaj.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-calendar3 me-1"></i>Puantaj</a>
    <?php if (can_edit()): ?>
    <a href="kiosk.php" target="_blank" class="btn btn-primary btn-sm"><i class="bi bi-camera-video me-1"></i>Kiosk Aç</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$o['nokta']): ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
  <i class="bi bi-exclamation-triangle"></i>
  <span><strong>Henüz geçiş noktası tanımlı değil.</strong> Kiosk cihazı kendini bir geçiş noktasının
    anahtarıyla tanıtır; tanım olmadan kart okutulamaz.</span>
  <a href="noktalar.php" class="btn btn-warning btn-sm ms-auto">Geçiş Noktası Ekle</a>
</div>
<?php endif; ?>

<?php if ($o['kartsiz']): ?>
<div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2">
  <i class="bi bi-person-vcard"></i>
  <span><strong><?= $f0($o['kartsiz']) ?> çalışan personelin ArUco kartı yok</strong> — kart dağıtımı tamamlanmadan
    puantaj eksik kalır.</span>
  <a href="kartlar.php?durum=kartsiz" class="btn btn-info btn-sm ms-auto">Kartsızları Listele</a>
</div>
<?php endif; ?>

<div class="row g-2 mb-3">
  <?php foreach ([
    ['Şu an içeride',   $o['iceride'],       'bi-door-open',     'success',   'hareketler.php?bas=' . $bugun . '&yon=giris', 'son hareketi giriş olanlar'],
    ['Bugün giriş yapan',$o['bugun_giris'],  'bi-person-check',  'primary',   'hareketler.php?bas=' . $bugun . '&bit=' . $bugun, $f0($o['bugun_hareket']) . ' hareket'],
    ['Aktif kart',      $o['kart'],          'bi-person-vcard',  'secondary', 'kartlar.php?durum=kartli',  $f0($o['kartsiz']) . ' kişide kart yok'],
    ['Geçiş noktası',   $o['nokta'],         'bi-door-closed',   'dark',      'noktalar.php',              $o['son_okuma'] ? 'son okuma ' . date('d.m H:i', strtotime($o['son_okuma'])) : 'henüz okuma yok'],
  ] as [$ad, $deger, $ikon, $renk, $link, $alt]): ?>
  <div class="col-6 col-xl-3">
    <a href="<?= h($link) ?>" class="card border-0 shadow-sm h-100 text-decoration-none">
      <div class="card-body py-2">
        <div class="small text-muted"><i class="bi <?= $ikon ?> me-1"></i><?= h($ad) ?></div>
        <div class="fs-4 fw-bold text-<?= $renk ?>"><?= $f0($deger) ?></div>
        <div class="small text-muted"><?= h($alt) ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong>Son 14 gün — giriş yapan personel</strong></div>
      <div class="card-body"><div style="height:240px"><canvas id="chTrend"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex align-items-center">
        <strong>Şu an içeride</strong>
        <span class="badge bg-success ms-2"><?= count($iceride) ?></span>
      </div>
      <div class="table-responsive" style="max-height:260px;overflow:auto">
        <table class="table table-sm mb-0" style="font-size:.84rem">
          <tbody>
          <?php if (!$iceride): ?>
            <tr><td class="text-center text-muted py-4">Şu an içeride kayıtlı kimse yok.</td></tr>
          <?php endif; ?>
          <?php foreach ($iceride as $i): ?>
            <tr>
              <td><a href="personel_form.php?id=<?= (int)$i['personel_id'] ?>" class="text-decoration-none"><?= h(trim($i['ad'] . ' ' . $i['soyad'])) ?></a>
                  <?php if (!empty($i['birim'])): ?><div class="small text-muted"><?= h($i['birim']) ?></div><?php endif; ?></td>
              <td class="text-end small text-muted text-nowrap">
                <?= h(date('H:i', strtotime($i['zaman']))) ?>'den beri
                <div><?= h(date('d.m.Y', strtotime($i['zaman']))) ?></div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex align-items-center">
        <strong>Bugünün puantajı</strong>
        <?php if ($bugunP['eksik']): ?>
          <span class="badge bg-warning text-dark ms-2"><?= (int)$bugunP['eksik'] ?> eşleşmeyen</span>
        <?php endif; ?>
        <a href="puantaj.php?bas=<?= $bugun ?>&bit=<?= $bugun ?>" class="ms-auto small">tümü →</a>
      </div>
      <div class="table-responsive" style="max-height:280px;overflow:auto">
        <table class="table table-sm mb-0" style="font-size:.84rem">
          <thead class="table-light"><tr><th>Personel</th><th>İlk giriş</th><th>Son çıkış</th><th class="text-end">Süre</th></tr></thead>
          <tbody>
          <?php if (!$bugunP['satirlar']): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">Bugün henüz kart okutulmadı.</td></tr>
          <?php endif; ?>
          <?php foreach (array_slice($bugunP['satirlar'], 0, 15) as $s): ?>
            <tr class="<?= $s['eksik'] ? 'table-warning' : '' ?>">
              <td><?= h($s['ad_soyad']) ?></td>
              <td class="font-monospace"><?= $s['ilk_giris'] ? h(substr($s['ilk_giris'], 11, 5)) : '—' ?></td>
              <td class="font-monospace"><?= $s['son_cikis'] ? h(substr($s['son_cikis'], 11, 5)) : '—' ?></td>
              <td class="text-end fw-semibold"><?= h(pts_sure((int)$s['dakika'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex align-items-center"><strong>Son hareketler</strong>
        <a href="hareketler.php" class="ms-auto small">defter →</a></div>
      <div class="table-responsive" style="max-height:280px;overflow:auto">
        <table class="table table-sm mb-0" style="font-size:.84rem">
          <tbody>
          <?php if (!$sonHareket): ?>
            <tr><td class="text-center text-muted py-4">Henüz hareket yok.</td></tr>
          <?php endif; ?>
          <?php foreach ($sonHareket as $r): ?>
            <tr>
              <td><?= pts_yonRozet($r['yon']) ?></td>
              <td><?= h(trim($r['ad'] . ' ' . $r['soyad'])) ?>
                  <?php if ($r['nokta_kod']): ?><div class="small text-muted"><?= h($r['nokta_kod']) ?></div><?php endif; ?></td>
              <td class="text-end small text-muted text-nowrap"><?= h(date('d.m H:i', strtotime($r['zaman']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var cv = document.getElementById('chTrend');
  if (!cv || typeof Chart === 'undefined') return;
  // Grafik renkleri tema değişkenlerinden okunur (sabit hex koyu temada kayboluyordu)
  var st = getComputedStyle(document.documentElement);
  var soluk = (st.getPropertyValue('--bt-text-muted') || '#6c757d').trim();
  var cizgi = (st.getPropertyValue('--bt-border-soft') || 'rgba(0,0,0,.08)').trim();
  new Chart(cv, {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_map(fn($g) => date('d.m', strtotime($g)), array_keys($seri))) ?>,
      datasets: [{ label: 'Giriş yapan personel', data: <?= json_encode(array_values($seri)) ?>,
                   backgroundColor: 'rgba(0,88,78,.75)', borderRadius: 4 }]
    },
    options: { responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { ticks: { color: soluk }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { color: soluk, precision: 0 }, grid: { color: cizgi } } } }
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
