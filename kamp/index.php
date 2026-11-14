<?php
/**
 * kamp/index.php — Kamp Takip dashboard
 * "Kampta kaç kişi var, kaç yatak boş, bu ay kim geldi/gitti, hangi bina dolu, arızalı demirbaş var mı?"
 * ⚠ Her KPI kartı KENDİ sayısını veren listeyi açar (IT dashboard dersi).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$pageTitle = 'Kamp Takip';
$o = kamp_ozet($pdoKamp);
$doluluk = kamp_doluluk($pdoKamp);
$bugun = date('Y-m-d'); $ayBas = date('Y-m-01');
$ic = kamp_icmal($pdoKamp, date('Y-m'));
$zo = kamp_ziyaret_ozet($pdoKamp);
$po = kamp_periyot_ozet($pdoKamp);

// Son 30 gün: gece gece kampta kalan kişi sayısı
$seri = [];
for ($i = 29; $i >= 0; $i--) $seri[date('Y-m-d', strtotime("-$i day"))] = 0;
$st = $pdoKamp->prepare("SELECT giris, cikis FROM kamp_konaklama WHERE giris <= ? AND (cikis IS NULL OR cikis > ?)");
$st->execute([$bugun, array_key_first($seri)]);
foreach ($st->fetchAll() as $r) foreach ($seri as $g => $_) if ($r['giris'] <= $g && (!$r['cikis'] || $r['cikis'] > $g)) $seri[$g]++;

// Kategori dağılımı (şu an)
$kat = [];
foreach ($pdoKamp->query("SELECT p.kategori, COUNT(*) n FROM kamp_konaklama k JOIN kamp_kisiler p ON p.id=k.kisi_id WHERE k.cikis IS NULL GROUP BY p.kategori") as $r) $kat[$r['kategori']] = (int)$r['n'];

$son = $pdoKamp->query("SELECT k.*, p.ad, p.soyad, f.ad AS firma_ad, o.ad AS oda_ad, b.ad AS bina_ad
                          FROM kamp_konaklama k JOIN kamp_kisiler p ON p.id=k.kisi_id
                          LEFT JOIN kamp_firmalar f ON f.id=COALESCE(k.firma_id,p.firma_id)
                          LEFT JOIN kamp_odalar o ON o.id=k.oda_id LEFT JOIN kamp_binalar b ON b.id=o.bina_id
                         ORDER BY GREATEST(k.giris, COALESCE(k.cikis, k.giris)) DESC, k.id DESC LIMIT 12")->fetchAll();
$sorun = $pdoKamp->query("SELECT d.*, o.ad AS oda_ad, b.ad AS bina_ad FROM kamp_demirbas d LEFT JOIN kamp_odalar o ON o.id=d.oda_id
                           LEFT JOIN kamp_binalar b ON b.id=d.bina_id WHERE d.durum IN ('arizali','bakimda','kayip')
                          ORDER BY FIELD(d.durum,'arizali','kayip','bakimda'), d.updated_at DESC LIMIT 10")->fetchAll();
$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$oran = $o['yatak'] ? (int)round($o['dolu'] * 100 / $o['yatak']) : 0;
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-house-heart text-primary me-2"></i>Kamp Takip</h4>
  <span class="text-muted small"><?= h(kamp_tarih_yaz($bugun)) ?></span>
  <div class="ms-auto d-flex gap-2">
    <a href="hareketler.php?mod=tarihte" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar-check me-1"></i>Tarihte kim vardı?</a>
    <?php if (yetki_var('giris') || yetki_var('duzenle')): ?><a href="ziyaret_form.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-badge me-1"></i>Ziyaretçi Girişi</a><?php endif; ?>
    <a href="yerlesim.php" class="btn btn-primary btn-sm"><i class="bi bi-grid-3x3-gap me-1"></i>Yerleşim Krokisi</a>
  </div>
</div>

<?php if (!$o['bina']): ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><i class="bi bi-exclamation-triangle"></i>
  <span><strong>Kamp yapısı henüz tanımlanmadı.</strong> Önce binaları, konteynerleri, odaları ve yatakları oluşturun.</span>
  <a href="yapi.php" class="btn btn-warning btn-sm ms-auto">Kamp yapısını oluştur</a></div>
<?php endif; ?>

<?php if ($zo['uyari']): ?>
<div class="alert alert-danger mb-3">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><i class="bi bi-exclamation-octagon-fill"></i>
    <strong><?= count($zo['uyari']) ?> ziyaretçi uyarısı</strong> — gece izni olmadan kampta bulunan, izinsiz giriş kaydı ya da çıkışı yazılmamış ziyaretçi.
    <a href="ziyaretler.php?durum=uyari" class="btn btn-danger btn-sm ms-auto">Ziyaretçi defteri →</a></div>
  <ul class="mb-0 small">
    <?php foreach (array_slice($zo['uyari'], 0, 6) as $z): ?>
    <li><strong><?= h($z['ad_soyad']) ?></strong> · giriş <?= h(kamp_zaman_yaz($z['giris'])) ?> · <?= h($z['ziyaret_edilen'] ? $z['ziyaret_edilen'] . ' misafiri' : 'kimin misafiri yazılmamış') ?>
      · <?= h(kamp_ziyaret_uyari_metin($z['i'])) ?>
      <?php if ($z['i']['ihlal'] || $z['i']['izinsiz']): ?> · <a href="olay_tutanak.php?id=<?= (int)$z['id'] ?>" class="link-danger fw-semibold"><i class="bi bi-printer me-1"></i>olay tutanağı</a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="row g-2 mb-3">
  <?php foreach ([
    ['Kampta kalan',     $o['kampta'],      'bi-people-fill',       'primary',   'kisiler.php?durum=kampta',                         $f0($o['firma']) . ' firmadan'],
    ['Boş yatak',        $o['bos'],         'bi-door-open',         'success',   'yerlesim.php',                                     $f0($o['yatak']) . ' yataktan · %' . $oran . ' dolu'],
    ['Bugün giren',      $o['bugun_giris'], 'bi-box-arrow-in-right','info',      'hareketler.php?bas=' . $bugun . '&bit=' . $bugun . '&tur=giris', 'yatak değişikliği hariç'],
    ['Bu ay giren',      $o['ay_giris'],    'bi-calendar-plus',     'success',   'hareketler.php?bas=' . $ayBas . '&bit=' . $bugun . '&tur=giris', h(kamp_tarih_yaz($ayBas)) . ' → bugün'],
    ['Bu ay çıkan',      $o['ay_cikis'],    'bi-calendar-minus',    'danger',    'hareketler.php?bas=' . $ayBas . '&bit=' . $bugun . '&tur=cikis', 'kamptan ayrılan'],
    ['Bu ay kişi-gece',  $ic['toplam']['gece'], 'bi-moon-stars',    'dark',      'icmal.php',                                        'firma icmali →'],
    ['Sorunlu demirbaş', $o['db_sorun'] + $o['db_kayip'], 'bi-tools', $o['db_sorun'] + $o['db_kayip'] ? 'danger' : 'secondary', 'demirbas.php?durum=sorun', $f0($o['db_sorun']) . ' arızalı/bakımda · ' . $f0($o['db_kayip']) . ' kayıp'],
    ['Ziyaretçi (içeride)', $zo['iceride'], 'bi-person-badge',     $zo['uyari'] ? 'danger' : 'primary', 'ziyaretler.php?durum=iceride', $f0($zo['bugun']) . ' bugün geldi' . ($zo['son7'] ? ' · son 7 günde ' . $f0($zo['son7']) . ' izinsiz' : '')],
    ['Değişimi gecikmiş', $po['toplam_kirmizi'], 'bi-arrow-repeat', $po['toplam_kirmizi'] ? 'danger' : 'success', 'periyodik.php', 'nevresim / yorgan… · ' . $f0($po['toplam_sari']) . ' yaklaşıyor'],
    ['Kullanım dışı yatak', $o['disi'],     'bi-slash-circle',      'secondary', 'yapi.php',                                         'kırık / kaldırılmış'],
  ] as [$ad, $deger, $ikon, $renk, $link, $alt]): ?>
  <div class="col-6 col-xl-3">
    <a href="<?= h($link) ?>" class="card border-0 shadow-sm h-100 text-decoration-none">
      <div class="card-body py-2">
        <div class="small text-muted"><i class="bi <?= $ikon ?> me-1"></i><?= h($ad) ?></div>
        <div class="fs-4 fw-bold text-<?= $renk ?>"><?= $f0($deger) ?></div>
        <div class="small text-muted"><?= $alt /* sabit metin + h() */ ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong>Bina bazında doluluk</strong></div>
      <div class="card-body">
        <?php if (!$doluluk): ?><div class="text-muted small">Bina yok.</div><?php endif; ?>
        <?php foreach ($doluluk as $id => $d): $p = $d['yatak'] ? round($d['dolu'] * 100 / $d['yatak']) : 0; ?>
        <a href="yerlesim.php?bina=<?= $id ?>" class="d-block text-decoration-none text-reset mb-3">
          <div class="d-flex small mb-1"><span class="fw-semibold"><i class="bi <?= KAMP_BINA_TUR[$d['tur']][1] ?? 'bi-building' ?> me-1"></i><?= h($d['ad']) ?></span>
            <span class="ms-auto"><strong><?= $d['dolu'] ?></strong> / <?= $d['yatak'] ?> · <?= $d['bos'] ?> boş</span></div>
          <div class="progress" style="height:12px"><div class="progress-bar <?= $p >= 95 ? 'bg-danger' : ($p >= 75 ? 'bg-warning' : 'bg-success') ?>" style="width:<?= $p ?>%"></div></div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong>Şu an kampta — kategori</strong></div>
      <div class="card-body"><div style="height:220px"><canvas id="chKat"></canvas></div></div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong>Son 30 gün — gece gece kampta kalan</strong></div>
      <div class="card-body"><div style="height:240px"><canvas id="chTrend"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex"><strong>Bu ay firma bazında</strong><a href="icmal.php" class="ms-auto small">icmal →</a></div>
      <div class="table-responsive" style="max-height:270px;overflow:auto"><table class="table table-sm mb-0" style="font-size:.84rem">
        <thead class="table-light"><tr><th>Firma</th><th class="text-end">Kampta</th><th class="text-end">Kişi-gece</th></tr></thead><tbody>
        <?php if (!$ic['firma']): ?><tr><td colspan="3" class="text-center text-muted py-3">Bu ay konaklama yok.</td></tr><?php endif; ?>
        <?php foreach ($ic['firma'] as $f): ?><tr><td><?= h($f['firma']) ?></td><td class="text-end"><?= $f['kalan'] ?></td><td class="text-end fw-semibold"><?= $f0($f['gece']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex"><strong>Son hareketler</strong><a href="hareketler.php" class="ms-auto small">defter →</a></div>
      <div class="table-responsive" style="max-height:320px;overflow:auto"><table class="table table-sm mb-0" style="font-size:.84rem"><tbody>
        <?php if (!$son): ?><tr><td class="text-center text-muted py-4">Henüz kayıt yok.</td></tr><?php endif; ?>
        <?php foreach ($son as $r): $cik = $r['cikis'] && !str_starts_with((string)$r['cikis_sebep'], 'Yatak değişikliği'); ?>
          <tr><td><?= $cik ? '<span class="badge bg-danger">Çıkış</span>' : '<span class="badge bg-success">Giriş</span>' ?></td>
            <td><a href="kisi_detay.php?id=<?= (int)$r['kisi_id'] ?>" class="text-decoration-none"><?= h(trim($r['ad'] . ' ' . $r['soyad'])) ?></a><div class="small text-muted"><?= h($r['firma_ad'] ?: '') ?></div></td>
            <td class="small"><?= h(($r['bina_ad'] ?? '') . ' · ' . ($r['oda_ad'] ?? '')) ?></td>
            <td class="text-end small text-muted text-nowrap"><?= h(kamp_tarih_yaz($cik ? $r['cikis'] : $r['giris'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex"><strong>Arızalı / kayıp demirbaş</strong><a href="demirbas.php?durum=sorun" class="ms-auto small">tümü →</a></div>
      <div class="table-responsive" style="max-height:320px;overflow:auto"><table class="table table-sm mb-0" style="font-size:.84rem"><tbody>
        <?php if (!$sorun): ?><tr><td class="text-center text-muted py-4">Sorunlu demirbaş yok 👍</td></tr><?php endif; ?>
        <?php foreach ($sorun as $d): ?><tr><td><?= h($d['ad']) ?> <span class="text-muted small">× <?= (int)$d['adet'] ?></span><div class="small text-muted"><?= h(($d['bina_ad'] ?? '') . ' · ' . ($d['oda_ad'] ?? 'ortak')) ?></div></td>
          <td class="text-end"><?= kamp_dbRozet($d['durum']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>
</div>

<script>
(function(){
  if (typeof Chart === 'undefined') return;
  var st = getComputedStyle(document.documentElement);
  var soluk = (st.getPropertyValue('--bt-text-muted') || '#6c757d').trim(), cizgi = (st.getPropertyValue('--bt-border-soft') || 'rgba(0,0,0,.08)').trim();
  var t = document.getElementById('chTrend');
  if (t) new Chart(t, { type:'line', data:{ labels: <?= json_encode(array_map(fn($g) => date('d.m', strtotime($g)), array_keys($seri))) ?>,
      datasets:[{ label:'Kampta kalan', data: <?= json_encode(array_values($seri)) ?>, borderColor:'rgba(0,88,78,1)', backgroundColor:'rgba(0,88,78,.15)', fill:true, tension:.25, pointRadius:2 }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}},
      scales:{ x:{ticks:{color:soluk, maxRotation:0, autoSkip:true}, grid:{display:false}}, y:{beginAtZero:true, ticks:{color:soluk, precision:0}, grid:{color:cizgi}} } } });
  var k = document.getElementById('chKat');
  var kd = <?= json_encode(array_values(array_filter(array_map(fn($k, $v) => isset($kat[$k]) ? [$v[0], $kat[$k]] : null, array_keys(KAMP_KATEGORI), KAMP_KATEGORI))), JSON_UNESCAPED_UNICODE) ?>;
  if (k && kd.length) new Chart(k, { type:'doughnut', data:{ labels: kd.map(function(x){return x[0];}),
      datasets:[{ data: kd.map(function(x){return x[1];}), backgroundColor:['#0d6efd','#ffc107','#6c757d','#0dcaf0','#adb5bd'] }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'right', labels:{color:soluk}}},
      onClick: function(){ location = 'kisiler.php?durum=kampta'; } } });
  else if (k) k.parentNode.innerHTML = '<div class="text-muted small text-center pt-5">Kampta kimse yok.</div>';
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
