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

$maliGoster = it_mali_goster();      // garanti + fiyat gösterimi (varsayılan KAPALI)
// İmzalı zimmet tutanağı olmayan zimmetli cihazlar — mali KPI'ın yerini alan takip göstergesi
$evraksiz = 0;
try {
    $evraksiz = (int)$pdoIt->query("SELECT COUNT(*) FROM it_cihazlar c WHERE " . it_envanterde('c') . " AND c.zimmetli<>''
                                    AND NOT EXISTS (SELECT 1 FROM it_belgeler b WHERE b.cihaz_id=c.id AND b.tur='zimmet')")->fetchColumn();
} catch (Throwable $e) {}
// İmzalı zimmet tutanağı eksik cihazlar — garanti/mali gizliyken dashboard'un takip listesi
$evrakListe = [];
try {
    $evrakListe = $pdoIt->query("SELECT c.id, c.envanter_no, c.cihaz_kodu, c.ad, c.zimmetli, c.personel_id, c.zimmet_tarihi
                                 FROM it_cihazlar c WHERE " . it_envanterde('c') . " AND c.zimmetli<>''
                                   AND NOT EXISTS (SELECT 1 FROM it_belgeler b WHERE b.cihaz_id=c.id AND b.tur='zimmet')
                                 ORDER BY c.zimmet_tarihi IS NULL, c.zimmet_tarihi LIMIT 12")->fetchAll();
} catch (Throwable $e) {}
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
        $lok[$ad] ??= ['id' => $k, 'n' => 0];
        $lok[$ad]['n'] += (int)$r['n'];
    }
    uasort($lok, fn($a, $b) => $b['n'] <=> $a['n']);
} catch (Throwable $e) {}
// Yolda olan cihazlar — "transfer yapıldı" işlemi tek tıkla buradan açılır; 14 günü aşan kırmızı
$yolda = []; $yoldaEnUzun = 0;
try {
    $yolda = $pdoIt->query("SELECT id, envanter_no, cihaz_kodu, ad, kategori, lokasyon, lokasyon_id
                            FROM it_cihazlar WHERE durum='transfer' ORDER BY id DESC LIMIT 12")->fetchAll();
    $__tg = it_transfer_gunleri($pdoIt, array_column($yolda, 'id'));
    foreach ($yolda as &$__y) { $__y['gun'] = $__tg[(int)$__y['id']] ?? null; $yoldaEnUzun = max($yoldaEnUzun, (int)($__y['gun'] ?? 0)); }
    unset($__y);
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

<?php /* Sahadan gelen en sık soru: "şu seri no kimde, nerede?" — tek kutudan cevap */ ?>
<form method="get" action="varliklar.php" class="card border-0 shadow-sm mb-3">
  <div class="card-body py-3">
    <div class="row g-2 align-items-center">
      <div class="col-lg-8">
        <div class="input-group input-group-lg">
          <span class="input-group-text bg-white"><i class="bi bi-search text-primary"></i></span>
          <input name="q" class="form-control" autofocus
                 placeholder="Seri no · IFS nesne no · IMEI · envanter no · MAC · IP · dahili · ad soyad…">
          <button class="btn btn-primary px-4">Bul</button>
        </div>
        <div class="form-text">Cihazı, <strong>kimde olduğunu</strong>, lokasyonunu ve durumunu tek ekranda gösterir.</div>
      </div>
      <div class="col-lg-4 small text-muted">
        <i class="bi bi-lightbulb me-1"></i>Kişi adı yazarsanız o kişideki tüm cihazlar listelenir;
        <a href="varliklar.php">merkezi varlık izleme</a> ekranında lokasyon ve cihaz tipine göre süzebilirsiniz.
      </div>
    </div>
  </div>
</form>

<div class="row g-3 mb-3">
  <?php
  /* Kart = [etiket, değer, ikon, renk, link, alt açıklama].
     ⚠ Her kartın linki KENDİ sayısını veren listeyi açmalı — "Serviste + Arızalı" tek duruma
     bağlıyken kart 1 derken liste boş açılıyordu; artık sanal `?durum=sorunlu` süzgecine gider. */
  $kpi = [
    ['Toplam Cihaz', $f0($o['toplam']), 'bi-pc-display', 'primary', 'cihazlar.php?durum=hepsi',
     $f0($o['toplam'] - $o['dusen']) . ' envanterde · ' . $f0($o['dusen']) . ' düşen (hurda dahil)'],
    ['Kullanımda', $f0($o['aktif']), 'bi-person-check', 'success', 'cihazlar.php?durum=aktif',
     $f0($o['zimmetliKisi']) . ' kişide zimmetli'],
    ['Depoda / Boşta', $f0($o['depoda']), 'bi-box-seam', 'secondary', 'cihazlar.php?durum=depoda',
     'kullanıma hazır stok'],
    ['Transfer (yolda)', $f0($o['transfer']), 'bi-arrow-left-right', 'info', 'cihazlar.php?durum=transfer',
     $o['transfer'] ? ('en uzun ' . $f0($yoldaEnUzun) . ' gündür yolda') : 'yolda cihaz yok'],
    ['Serviste + Arızalı', $f0($o['serviste'] + $o['arizali']), 'bi-wrench', 'danger', 'cihazlar.php?durum=sorunlu',
     $f0($o['serviste']) . ' serviste · ' . $f0($o['arizali']) . ' arızalı'],
    ['Envanterden düşen', $f0($o['dusen']), 'bi-trash', 'dark', 'cihazlar.php?durum=dusen',
     'hurda ' . $f0($o['hurda']) . ' · kayıp ' . $f0($o['kayip']) . ' · hibe ' . $f0($o['hibe'])],
  ];
  if ($maliGoster) {
      $kpi[] = ['Garanti 60 gün içinde bitiyor', $f0($o['garantiBitiyor']), 'bi-shield-exclamation', 'warning', 'cihazlar.php?garanti=bitiyor',
                $f0($o['garantiBitti']) . ' cihazın garantisi bitti'];
      $kpi[] = ['Mali Değer', $f2($o['mali']) . ' <small>TL</small>', 'bi-cash-stack', 'info', 'raporlar.php', 'envanterdeki cihazların toplamı'];
  } else {
      $kpi[] = ['İmzalı evrakı eksik zimmet', $f0($evraksiz), 'bi-file-earmark-excel', $evraksiz ? 'warning' : 'success', 'cihazlar.php?evrak=eksik',
                $evraksiz ? 'tutanağı yazdır → imzalat → yükle' : 'tüm zimmetlerin evrakı tam'];
  }
  foreach ($kpi as [$et, $deg, $ik, $renk, $href, $alt]): ?>
  <div class="col-6 col-md-4 col-xl-3">
    <a href="<?= $href ?>" class="card border-0 shadow-sm h-100 text-decoration-none">
      <div class="card-body py-3 d-flex align-items-center gap-3">
        <div class="rounded-3 bg-<?= $renk ?> bg-opacity-10 text-<?= $renk ?> d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px"><i class="bi <?= $ik ?> fs-4"></i></div>
        <div class="min-w-0"><div class="small text-muted"><?= $et ?></div>
          <div class="fs-5 fw-bold text-dark lh-1"><?= $deg ?></div>
          <div class="text-muted text-truncate" style="font-size:.72rem"><?= h($alt) ?></div></div>
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

<?php if ($yolda): ?>
<?php /* Transfer = projeler arası sevk. Karşı taraf teslim alınca "Transfer YAPILDI" işlenmeli;
         yoksa cihaz sonsuza dek "yolda" görünür. Bu panel işlemi tek tıkla açar. */ ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
    <strong><i class="bi bi-arrow-left-right text-info me-1"></i>Yolda olan cihazlar</strong>
    <span class="badge bg-info text-dark"><?= $f0($o['transfer']) ?></span>
    <span class="small text-muted">teslim alındıysa <strong>Transfer yapıldı</strong> ile kapatın</span>
    <a href="cihazlar.php?durum=transfer" class="btn btn-sm btn-outline-secondary ms-auto">Tümü</a>
  </div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.85rem">
    <thead class="table-light"><tr><th>Cihaz</th><th>Gönderildiği proje / lokasyon</th><th>Süre</th><th class="text-end">İşlem</th></tr></thead>
    <tbody>
    <?php foreach ($yolda as $y): $gun = $y['gun']; ?>
      <tr>
        <td><a href="cihaz_detay.php?id=<?= (int)$y['id'] ?>" class="text-decoration-none fw-semibold"><?= h($y['ad']) ?></a>
            <div class="small text-muted font-monospace"><?= h(($y['cihaz_kodu'] ?? '') !== '' ? $y['cihaz_kodu'] : $y['envanter_no']) ?> · <?= h(it_kategoriAd($y['kategori'])) ?></div></td>
        <td class="small"><?= h($y['lokasyon_id'] ? it_lokasyon_etiket($pdoIt, (int)$y['lokasyon_id']) : ($y['lokasyon'] ?: '—')) ?></td>
        <td><?php if ($gun === null): ?><span class="text-muted small">—</span>
            <?php else: ?><span class="badge bg-<?= $gun > 14 ? 'danger' : 'light text-dark border' ?>"><?= (int)$gun ?> gündür yolda</span><?php endif; ?></td>
        <td class="text-end text-nowrap">
          <a href="transfer_tutanak.php?id=<?= (int)$y['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Sevk tutanağı"><i class="bi bi-file-earmark-text"></i></a>
          <?php if ($yazabilir): ?><a href="cihaz_detay.php?id=<?= (int)$y['id'] ?>&amp;islem=transfer_bitti#islem" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i>Transfer yapıldı</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Kategori dağılımı</strong> <span class="small text-muted">(envanterdekiler)</span></div>
      <div class="card-body"><div style="height:260px"><canvas id="chKat"></canvas></div></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Durum dağılımı</strong>
        <span class="small text-muted">(tüm kayıtlar — düşenler dahil)</span></div>
      <div class="card-body"><div style="height:260px"><canvas id="chDurum"></canvas></div></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white"><strong>Proje / bina bazında cihaz</strong> <a href="tanimlar.php?t=lokasyon" class="small ms-1">lokasyonlar</a></div>
      <div class="card-body"><div style="height:260px"><canvas id="chLok"></canvas></div></div></div>
  </div>
</div>

<div class="row g-3">
  <?php if ($maliGoster): ?>
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
  <?php endif; ?>
  <?php if (!$maliGoster): ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex align-items-center gap-2">
        <strong><i class="bi bi-file-earmark-excel text-warning me-1"></i>İmzalı evrakı eksik zimmet</strong>
        <?php if ($evraksiz): ?><span class="badge bg-warning text-dark"><?= $f0($evraksiz) ?></span><?php endif; ?>
        <a href="cihazlar.php?evrak=eksik" class="small ms-auto">tümü</a>
      </div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.84rem">
        <tbody>
        <?php if (!$evrakListe): ?><tr><td class="text-muted text-center py-3">Tüm zimmetlerin imzalı tutanağı var. 👍</td></tr><?php endif; ?>
        <?php foreach ($evrakListe as $e): ?>
          <tr><td><a href="cihaz_detay.php?id=<?= (int)$e['id'] ?>" class="text-decoration-none fw-semibold"><?= h($e['ad']) ?></a>
                  <div class="small text-muted font-monospace"><?= h(($e['cihaz_kodu'] ?? '') !== '' ? $e['cihaz_kodu'] : $e['envanter_no']) ?></div></td>
              <td class="small"><?= h($e['zimmetli']) ?><?php if ($e['zimmet_tarihi']): ?><div class="text-muted"><?= format_date($e['zimmet_tarihi']) ?>'ten beri</div><?php endif; ?></td>
              <td class="text-end"><a href="<?= $e['personel_id'] ? 'zimmet_tutanak.php?personel_id=' . (int)$e['personel_id'] : 'zimmet_tutanak.php?id=' . (int)$e['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0" title="Zimmet tutanağını yazdır"><i class="bi bi-printer"></i></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>
  <?php endif; ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong><i class="bi bi-wrench text-danger me-1"></i>Serviste / arızalı</strong>
        <a href="cihazlar.php?durum=sorunlu" class="small ms-2">tümü</a></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.84rem">
        <tbody>
        <?php if (!$sorunlu): ?><tr><td class="text-muted text-center py-3">Serviste ya da arızalı cihaz yok.</td></tr><?php endif; ?>
        <?php foreach ($sorunlu as $s): ?>
          <tr><td><a href="cihaz_detay.php?id=<?= (int)$s['id'] ?>" class="text-decoration-none fw-semibold"><?= h($s['ad']) ?></a>
                  <div class="small text-muted font-monospace"><?= h($s['envanter_no']) ?></div></td>
              <td class="small text-muted"><?= h($s['zimmetli'] ?: '—') ?>
                  <?php if ($s['updated_at']): ?><div><?= format_date(substr((string)$s['updated_at'], 0, 10)) ?>'ten beri</div><?php endif; ?></td>
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
              <?php if ($maliGoster): ?><td class="text-end small text-muted"><?= $f2($k['mali']) ?> TL</td><?php endif; ?>
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
  kat: <?= json_encode(array_map(fn($r) => ['k'=>$r['kategori'], 'ad'=>it_kategoriAd($r['kategori']), 'adet'=>(int)$r['adet']], $kat), JSON_UNESCAPED_UNICODE) ?>,
  durum: <?= json_encode(array_map(fn($k) => ['k'=>$k, 'ad'=>IT_DURUM[$k][0], 'adet'=>(int)$o[$k], 'renk'=>IT_DURUM[$k][1]], array_keys(IT_DURUM)), JSON_UNESCAPED_UNICODE) ?>,
  dep: <?= json_encode(array_map(fn($r) => ['ad'=>$r['departman'], 'adet'=>(int)$r['adet']], $dep), JSON_UNESCAPED_UNICODE) ?>,
  lok: <?= json_encode(array_map(fn($k, $v) => ['ad'=>$k, 'id'=>(int)$v['id'], 'adet'=>(int)$v['n']], array_keys($lok), $lok), JSON_UNESCAPED_UNICODE) ?>
};
const PAL = ['#00584E','#007A6A','#00C9B1','#198754','#0d6efd','#6f42c1','#fd7e14','#ffc107','#dc3545','#6c757d','#20c997'];
// Durum renkleri listedeki rozetlerle AYNI olsun (grafik ile tablo aynı dili konuşsun)
const DRENK = { success:'#198754', secondary:'#6c757d', 'info text-dark':'#0dcaf0', info:'#0dcaf0',
                danger:'#dc3545', 'warning text-dark':'#ffc107', primary:'#0d6efd', dark:'#343a40' };
// Grafikte bir dilime/çubuğa tıklayınca o süzgeçle liste açılır (Chart.js tıklanan öğeyi 2. argümanda verir)
function chGit(els, url) { if (!els || !els.length) return; const u = url(els[0].index); if (u) location.href = u; }

// Kategori: ilk 8 + kalanlar "Diğer" (26 kategoride efsane okunmuyordu)
const katTop = IT.kat.slice(0, 8);
const katKalan = IT.kat.slice(8).reduce((t, k) => t + k.adet, 0);
const katVeri = katKalan ? katTop.concat([{ k:'', ad:'Diğer (' + IT.kat.slice(8).length + ' tip)', adet:katKalan }]) : katTop;
new Chart(document.getElementById('chKat'), { type:'doughnut',
  data:{ labels: katVeri.map(k=>k.ad), datasets:[{ data: katVeri.map(k=>k.adet), backgroundColor: PAL }] },
  options:{ responsive:true, maintainAspectRatio:false, onClick:(e,els)=>chGit(els,i=>katVeri[i].k ? 'cihazlar.php?kategori='+katVeri[i].k : 'varliklar.php'),
            plugins:{ legend:{ position:'right', labels:{ boxWidth:12, font:{size:11} } } } } });

new Chart(document.getElementById('chDurum'), { type:'bar',
  data:{ labels: IT.durum.map(d=>d.ad), datasets:[{ label:'Cihaz', data: IT.durum.map(d=>d.adet),
         backgroundColor: IT.durum.map(d=>DRENK[d.renk] || '#6c757d') }] },
  options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
            onClick:(e,els)=>chGit(els,i=>'cihazlar.php?durum='+IT.durum[i].k),
            plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:(c)=>c.raw + ' cihaz' } } },
            scales:{ x:{ beginAtZero:true, ticks:{ precision:0 } } } } });

new Chart(document.getElementById('chLok'), { type:'bar',
  data:{ labels: IT.lok.map(d=>d.ad), datasets:[{ label:'Cihaz', data: IT.lok.map(d=>d.adet), backgroundColor:'#00584E' }] },
  options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
            onClick:(e,els)=>chGit(els,i=>IT.lok[i].id ? 'cihazlar.php?lokasyon_id='+IT.lok[i].id : ''),
            plugins:{ legend:{ display:false } }, scales:{ x:{ beginAtZero:true, ticks:{ precision:0 } } } } });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
