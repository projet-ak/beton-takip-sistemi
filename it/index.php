<?php
/**
 * it/index.php — IT Envanter dashboard
 * KPI'lar + kategori/durum grafikleri + garantisi yaklaşanlar + son hareketler + en çok cihazı olan kişiler.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';

it_semasi_kur($pdoIt);
$pageTitle = 'IT Envanter Dashboard';
$o = it_ozet($pdoIt);

$kat = $pdoIt->query("SELECT kategori, COUNT(*) adet, SUM(durum='aktif') aktif, COALESCE(SUM(fiyat),0) mali
                      FROM it_cihazlar WHERE " . it_envanterde() . " GROUP BY kategori ORDER BY adet DESC")->fetchAll();
$dep = $pdoIt->query("SELECT COALESCE(NULLIF(departman,''),'(tanımsız)') departman, COUNT(*) adet
                      FROM it_cihazlar WHERE durum='aktif' GROUP BY departman ORDER BY adet DESC LIMIT 12")->fetchAll();
$kisiler = $pdoIt->query("SELECT zimmetli, MAX(personel_id) personel_id, COUNT(*) adet, COALESCE(SUM(fiyat),0) mali, MAX(departman) departman
                          FROM it_cihazlar WHERE durum='aktif' AND zimmetli<>'' GROUP BY zimmetli ORDER BY adet DESC, mali DESC LIMIT 10")->fetchAll();
// Varlık grupları (BT / ağ / iletişim / güvenlik / multimedya / yazılım / sarf) — kategori
// sayımından türetilir, ek sorgu gerekmez; merkezi izleme ekranına giriş kapısıdır.
$grupSayim = [];
foreach ($kat as $r) {
    $g = it_grup((string)$r['kategori']);
    $grupSayim[$g] ??= ['adet'=>0, 'aktif'=>0, 'mali'=>0.0];
    $grupSayim[$g]['adet']  += (int)$r['adet'];
    $grupSayim[$g]['aktif'] += (int)$r['aktif'];
    $grupSayim[$g]['mali']  += (float)$r['mali'];
}
uasort($grupSayim, fn($a, $b) => $b['adet'] <=> $a['adet']);

$garanti = $pdoIt->query("SELECT id, envanter_no, ad, kategori, zimmetli, garanti_bitis FROM it_cihazlar
                          WHERE " . it_envanterde() . " AND garanti_bitis IS NOT NULL AND garanti_bitis <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                          ORDER BY garanti_bitis LIMIT 12")->fetchAll();
$sorunlu = $pdoIt->query("SELECT id, envanter_no, ad, kategori, durum, zimmetli, updated_at FROM it_cihazlar
                          WHERE durum IN ('serviste','arizali') ORDER BY updated_at DESC LIMIT 10")->fetchAll();
$riskli = []; $lok = [];
try {
    $riskli = $pdoIt->query("SELECT p.id, p.ad, p.soyad, p.isten_cikis, COUNT(c.id) adet FROM it_personel p JOIN it_cihazlar c ON c.personel_id=p.id AND " . it_envanterde('c') . "
                             WHERE p.isten_cikis IS NOT NULL AND p.isten_cikis <= CURDATE() GROUP BY p.id ORDER BY p.isten_cikis")->fetchAll();
    // Kök lokasyon (proje / bina) bazında cihaz dağılımı — alt lokasyonlar köke toplanır
    $hepsi = it_lokasyonlar($pdoIt); $kokOf = function (int $id) use ($hepsi) { $g = 0; while ($id && isset($hepsi[$id]) && (int)$hepsi[$id]['ust_id'] && $g++ < 10) $id = (int)$hepsi[$id]['ust_id']; return $id; };
    foreach ($pdoIt->query("SELECT lokasyon_id, COUNT(*) n FROM it_cihazlar WHERE " . it_envanterde() . " GROUP BY lokasyon_id") as $r) {
        $k = $r['lokasyon_id'] ? $kokOf((int)$r['lokasyon_id']) : 0;
        $ad = $k ? it_lokasyon_etiket($pdoIt, $k) : '(lokasyonsuz)';
        $lok[$ad] = ($lok[$ad] ?? 0) + (int)$r['n'];
    }
    arsort($lok);
} catch (Throwable $e) {}
$son = $pdoIt->query("SELECT h.*, c.envanter_no, c.ad FROM it_hareketler h JOIN it_cihazlar c ON c.id=h.cihaz_id
                      ORDER BY h.id DESC LIMIT 12")->fetchAll();
$bos = $o['toplam'] === 0;
$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
$yazabilir = yetki_var('giris');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-pc-display text-primary me-2"></i>IT Envanter</h4>
    <div class="d-flex gap-2">
        <a href="cihazlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul me-1"></i>Cihazlar</a>
        <a href="raporlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart-line me-1"></i>Raporlar</a>
        <?php if ($yazabilir): ?><a href="cihaz_form.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Yeni Cihaz</a><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($riskli): ?>
<div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><strong>İşten ayrılmış ama üzerinde zimmet duran personel:</strong>
  <?php foreach ($riskli as $rk): ?><a href="personel_detay.php?id=<?= (int)$rk['id'] ?>" class="alert-link ms-2"><?= h($rk['ad'] . ' ' . $rk['soyad']) ?> (<?= (int)$rk['adet'] ?> cihaz, çıkış <?= format_date($rk['isten_cikis']) ?>)</a><?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($bos): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Henüz cihaz kaydı yok. <?php if ($yazabilir): ?><a href="cihaz_form.php" class="alert-link">İlk cihazı ekleyin</a> — envanter numarası otomatik verilir.<?php endif; ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <?php
  $kpi = [
    ['Toplam Cihaz', $f0($o['toplam'] - $o['dusen']), 'bi-pc-display', 'primary', 'cihazlar.php'],
    ['Kullanımda', $f0($o['aktif']), 'bi-person-check', 'success', 'cihazlar.php?durum=aktif'],
    ['Depoda / Boşta', $f0($o['depoda']), 'bi-box-seam', 'secondary', 'cihazlar.php?durum=depoda'],
    ['Serviste + Arızalı', $f0($o['serviste'] + $o['arizali']), 'bi-wrench', 'danger', 'cihazlar.php?durum=arizali'],
    ['Garanti 60 gün içinde bitiyor', $f0($o['garantiBitiyor']), 'bi-shield-exclamation', 'warning', 'cihazlar.php?garanti=bitiyor'],
    ['Mali Değer', $f2($o['mali']) . ' <small>TL</small>', 'bi-cash-stack', 'info', 'raporlar.php'],
  ];
  foreach ($kpi as [$et, $deg, $ik, $renk, $href]): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= $href ?>" class="card border-0 shadow-sm h-100 text-decoration-none">
      <div class="card-body py-3 d-flex align-items-center gap-3">
        <div class="rounded-3 bg-<?= $renk ?> bg-opacity-10 text-<?= $renk ?> d-flex align-items-center justify-content-center" style="width:44px;height:44px"><i class="bi <?= $ik ?> fs-4"></i></div>
        <div><div class="small text-muted"><?= $et ?></div><div class="fs-5 fw-bold text-dark"><?= $deg ?></div></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($grupSayim): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
    <strong><i class="bi bi-diagram-3 me-1"></i>Varlık grupları</strong>
    <span class="small text-muted">BT envanteri, ağ ve güvenlik, iletişim, kamera sistemleri, multimedya, sarf…</span>
    <a href="varliklar.php" class="btn btn-sm btn-outline-primary ms-auto">Merkezi varlık izleme <i class="bi bi-arrow-right ms-1"></i></a>
  </div>
  <div class="card-body py-2"><div class="row g-2">
    <?php foreach ($grupSayim as $__g => $__gs): ?>
    <div class="col-6 col-md-4 col-xl-3">
      <a href="varliklar.php?grup=<?= h($__g) ?>" class="d-flex align-items-center gap-2 p-2 rounded text-decoration-none border">
        <i class="bi <?= h(IT_GRUP[$__g][1] ?? 'bi-box') ?> fs-5 text-primary"></i>
        <div class="flex-grow-1">
          <div class="small fw-semibold text-dark"><?= h(IT_GRUP[$__g][0] ?? $__g) ?></div>
          <div class="small text-muted"><?= $f0($__gs['adet']) ?> varlık · <?= $f0($__gs['aktif']) ?> kullanımda</div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Kategori dağılımı</strong> <span class="small text-muted">(envanterdekiler)</span></div>
      <div class="card-body"><div style="height:260px"><canvas id="chKat"></canvas></div></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Durum dağılımı</strong></div>
      <div class="card-body"><div style="height:260px"><canvas id="chDurum"></canvas></div></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Proje / bina bazında cihaz</strong> <a href="tanimlar.php?t=lokasyon" class="small ms-1">lokasyonlar</a></div>
      <div class="card-body"><div style="height:260px"><canvas id="chLok"></canvas></div></div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong><i class="bi bi-shield-exclamation text-warning me-1"></i>Garantisi biten / bitmek üzere</strong></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.84rem">
        <tbody>
        <?php if (!$garanti): ?><tr><td class="text-muted text-center py-3">Yakın tarihte biten garanti yok.</td></tr><?php endif; ?>
        <?php foreach ($garanti as $g): $gk = it_garanti_kalan($g['garanti_bitis']); ?>
          <tr><td><a href="cihaz_detay.php?id=<?= (int)$g['id'] ?>" class="font-monospace text-decoration-none"><?= h($g['envanter_no']) ?></a><div class="small"><?= h($g['ad']) ?></div></td>
              <td class="small text-muted"><?= h($g['zimmetli'] ?: '—') ?></td>
              <td class="text-end"><?= $gk < 0 ? '<span class="badge bg-light text-danger border">bitti</span>' : '<span class="badge bg-warning text-dark">' . $gk . ' gün</span>' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong><i class="bi bi-wrench text-danger me-1"></i>Serviste / arızalı</strong></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.84rem">
        <tbody>
        <?php if (!$sorunlu): ?><tr><td class="text-muted text-center py-3">Serviste ya da arızalı cihaz yok.</td></tr><?php endif; ?>
        <?php foreach ($sorunlu as $s): ?>
          <tr><td><a href="cihaz_detay.php?id=<?= (int)$s['id'] ?>" class="font-monospace text-decoration-none"><?= h($s['envanter_no']) ?></a><div class="small"><?= h($s['ad']) ?></div></td>
              <td class="small text-muted"><?= h($s['zimmetli'] ?: '—') ?></td>
              <td class="text-end"><?= it_durumBadge($s['durum']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong><i class="bi bi-people me-1"></i>En çok cihaz zimmetli kişiler</strong></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.84rem">
        <tbody>
        <?php if (!$kisiler): ?><tr><td class="text-muted text-center py-3">Zimmet yok.</td></tr><?php endif; ?>
        <?php foreach ($kisiler as $k): ?>
          <tr><td><a href="<?= $k['personel_id'] ? 'personel_detay.php?id=' . (int)$k['personel_id'] : 'cihazlar.php?zimmetli=' . urlencode($k['zimmetli']) ?>" class="text-decoration-none fw-semibold"><?= h($k['zimmetli']) ?></a><div class="small text-muted"><?= h($k['departman'] ?: '') ?></div></td>
              <td class="text-end"><span class="badge bg-primary"><?= (int)$k['adet'] ?> cihaz</span></td>
              <td class="text-end small text-muted"><?= $f2($k['mali']) ?> TL</td>
              <td class="text-end"><a href="<?= $k['personel_id'] ? 'zimmet_tutanak.php?personel_id=' . (int)$k['personel_id'] : 'zimmet_tutanak.php?kisi=' . urlencode($k['zimmetli']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="Toplu zimmet tutanağı"><i class="bi bi-file-earmark-text"></i></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>
  <div class="col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white"><strong><i class="bi bi-clock-history me-1"></i>Son hareketler</strong></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.84rem">
        <thead class="table-light"><tr><th>Tarih</th><th>Cihaz</th><th>İşlem</th><th>Kişi</th><th>Açıklama</th><th>Kaydeden</th></tr></thead>
        <tbody>
        <?php if (!$son): ?><tr><td colspan="6" class="text-muted text-center py-3">Hareket yok.</td></tr><?php endif; ?>
        <?php foreach ($son as $hr): $hx = IT_HAREKET[$hr['tur']] ?? [$hr['tur'], 'secondary', 'bi-dot']; ?>
          <tr><td class="text-nowrap"><?= format_date($hr['tarih']) ?></td>
              <td><a href="cihaz_detay.php?id=<?= (int)$hr['cihaz_id'] ?>" class="font-monospace text-decoration-none"><?= h($hr['envanter_no']) ?></a> <span class="small text-muted"><?= h($hr['ad']) ?></span></td>
              <td><span class="badge bg-<?= $hx[1] ?><?= in_array($hx[1], ['light','warning'], true) ? ' text-dark' : '' ?>"><?= h($hx[0]) ?></span></td>
              <td><?= h($hr['kisi'] ?: '—') ?></td><td class="small"><?= h(mb_strimwidth((string)$hr['aciklama'], 0, 90, '…')) ?></td>
              <td class="small text-muted"><?= h($hr['kullanici'] ?: '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>
</div>

<script>
const IT = {
  kat: <?= json_encode(array_map(fn($r) => ['ad'=>it_kategoriAd($r['kategori']), 'adet'=>(int)$r['adet']], $kat), JSON_UNESCAPED_UNICODE) ?>,
  durum: <?= json_encode(array_map(fn($k) => ['ad'=>IT_DURUM[$k][0], 'adet'=>(int)$o[$k]], array_keys(IT_DURUM)), JSON_UNESCAPED_UNICODE) ?>,
  dep: <?= json_encode(array_map(fn($r) => ['ad'=>$r['departman'], 'adet'=>(int)$r['adet']], $dep), JSON_UNESCAPED_UNICODE) ?>,
  lok: <?= json_encode(array_map(fn($k, $v) => ['ad'=>$k, 'adet'=>$v], array_keys($lok), $lok), JSON_UNESCAPED_UNICODE) ?>
};
const PAL = ['#00584E','#007A6A','#00C9B1','#198754','#0d6efd','#6f42c1','#fd7e14','#ffc107','#dc3545','#6c757d','#20c997'];
new Chart(document.getElementById('chKat'), { type:'doughnut',
  data:{ labels: IT.kat.map(k=>k.ad), datasets:[{ data: IT.kat.map(k=>k.adet), backgroundColor: PAL }] },
  options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'right', labels:{ boxWidth:12, font:{size:11} } } } } });
new Chart(document.getElementById('chDurum'), { type:'bar',
  data:{ labels: IT.durum.map(d=>d.ad), datasets:[{ label:'Cihaz', data: IT.durum.map(d=>d.adet), backgroundColor:['#198754','#6c757d','#0dcaf0','#dc3545','#343a40'] }] },
  options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } } });
new Chart(document.getElementById('chLok'), { type:'bar',
  data:{ labels: IT.lok.map(d=>d.ad), datasets:[{ label:'Cihaz', data: IT.lok.map(d=>d.adet), backgroundColor:'#00584E' }] },
  options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ x:{ beginAtZero:true, ticks:{ precision:0 } } } } });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
