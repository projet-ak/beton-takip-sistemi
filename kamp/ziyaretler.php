<?php
/**
 * kamp/ziyaretler.php — ZİYARETÇİ / MİSAFİR DEFTERİ
 *
 * Kampa dışarıdan gelen herkes saatiyle: kim · kimlik · kimin misafiri · kim onayladı · giriş-çıkış.
 *  Sekmeler: şu an içeride · bugün · uyarılar (gece izinsiz / izinsiz giriş / çıkışı yazılmamış) · gece · hepsi
 *  Olay sorgusu: ?gece=Y-m-d → o GECE (gece penceresi) kampta bulunan ziyaretçiler ·
 *                ?an=Y-m-dTH:i → o ANDA kampta olanlar ("gece 03:00'te kampta kim vardı?")
 *  POST: cikis (giriş VEYA değiştirme) · sil (değiştirme — yalnız yanlış kayıt)
 *  ?foto=<id> → kimlik görüntüsü · ?foto=<id>&tur=tutanak → imzalı olay tutanağı (yalnız oturum açmış
 *  kullanıcıya; dosya tahmin edilemeyen adla saklanır) · satırdaki yazıcı → olay_tutanak.php
 *  Excel: ?export=xlsx
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$yaz = yetki_var('giris') || yetki_var('duzenle');
$duz = yetki_var('duzenle');

// Kimlik / kişi görüntüsü — oturum açmış kullanıcıya akıtılır
if (isset($_GET['foto'])) {
    $z = kamp_ziyaret_bul($pdoKamp, (int)$_GET['foto']);
    $tut = ($_GET['tur'] ?? '') === 'tutanak';
    $url = (string)($z[$tut ? 'tutanak_url' : 'foto_url'] ?? '');
    $yol = __DIR__ . '/../' . $url;
    if (!$z || !preg_match('#^uploads/kamp/ziyaret/\d+/[a-f0-9]{24}\.[a-z]{3,4}$#', $url) || !is_file($yol)) { http_response_code(404); exit('Görsel bulunamadı.'); }
    $mime = guess_mime($yol, basename($yol));
    if (!preg_match('#^(image/|application/pdf)#', $mime)) $mime = 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($yol));
    header('Content-Disposition: inline; filename="' . ($tut ? kamp_olay_no((int)$z['id']) : 'ziyaretci_' . (int)$z['id']) . '.' . pathinfo($yol, PATHINFO_EXTENSION) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    readfile($yol);
    exit;
}

$geri = kamp_geri($_POST['geri'] ?? '', 'ziyaretler.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        switch ($_POST['islem'] ?? '') {
            case 'cikis':
                if (!$yaz) throw new RuntimeException('Çıkış yazmak için yetkiniz yok.');
                $z = kamp_ziyaret_cikis($pdoKamp, $id, (string)($_POST['cikis'] ?? ''), current_user_id());
                kamp_audit('kamp_ziyaretler', $id, 'UPDATE', ['cikis' => null], ['cikis' => $z['cikis']]);
                flash('success', $z['ad_soyad'] . ' — çıkış ' . kamp_zaman_yaz($z['cikis']) . ' olarak yazıldı.');
                break;
            case 'sil':
                if (!$duz) throw new RuntimeException('Kayıt silmek için değiştirme yetkisi gerekir.');
                $z = kamp_ziyaret_bul($pdoKamp, $id) ?: throw new RuntimeException('Kayıt bulunamadı.');
                $pdoKamp->prepare("DELETE FROM kamp_ziyaretler WHERE id=?")->execute([$id]);
                kamp_ziyaret_dosya_sil($z['foto_url']);
                kamp_ziyaret_dosya_sil($z['tutanak_url']);
                unset($z['kisi_ad'], $z['kisi_soyad'], $z['bina_ad']);
                kamp_audit('kamp_ziyaretler', $id, 'DELETE', $z, null);
                flash('success', 'Ziyaret kaydı silindi (yanlış giriş).');
                break;
            default: throw new RuntimeException('Bilinmeyen işlem.');
        }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect($geri);
}

// ─── süzgeçler
$DURUM = ['iceride' => 'Şu an içeride', 'bugun' => 'Bugün gelen', 'uyari' => 'Uyarılar', 'gece' => 'Gece ziyaretleri', 'hepsi' => 'Hepsi'];
$durum = isset($DURUM[$_GET['durum'] ?? '']) ? $_GET['durum'] : 'iceride';
$bas   = kamp_tarih($_GET['bas'] ?? '') ?? date('Y-m-d', strtotime('-30 day'));
$bit   = kamp_tarih($_GET['bit'] ?? '') ?? date('Y-m-d');
if ($bit < $bas) [$bas, $bit] = [$bit, $bas];
$geceQ = kamp_tarih($_GET['gece'] ?? '');
$anQ   = kamp_zaman($_GET['an'] ?? '');
$q     = trim((string)($_GET['q'] ?? ''));
$olay  = $geceQ || $anQ;                                         // olay sorgusu: durum/tarih aralığı devre dışı

if ($olay) $liste = kamp_ziyaret_liste($pdoKamp, $anQ ? ['an' => $anQ] : ['gece' => $geceQ]);
else {
    // Aralık + çıkışı yazılmamış TÜM ziyaretler ("şu an içeride" tarih aralığından bağımsızdır)
    $liste = kamp_ziyaret_liste($pdoKamp, ['bas' => $bas, 'bit' => $bit]);
    $var = array_flip(array_column($liste, 'id'));
    foreach (kamp_ziyaret_liste($pdoKamp, ['acik' => 1]) as $z) if (!isset($var[$z['id']])) $liste[] = $z;
    usort($liste, fn($a, $b) => strcmp($b['giris'], $a['giris']) ?: ($b['id'] <=> $a['id']));
}
if ($q !== '') $liste = kamp_ziyaret_suz($liste, $q);

$bugun0 = date('Y-m-d 00:00:00');
$filtre = [
    'iceride' => fn($z) => $z['i']['acik'],
    'bugun'   => fn($z) => $z['giris'] >= $bugun0,
    'uyari'   => fn($z) => $z['i']['uyari'],
    'gece'    => fn($z) => $z['i']['gece'],
    'hepsi'   => fn($z) => true,
];
$sayac = [];
foreach ($filtre as $k => $fn) $sayac[$k] = count(array_filter($liste, $fn));
if (!$olay) $liste = array_values(array_filter($liste, $filtre[$durum]));
$ihlalSay = count(array_filter($liste, fn($z) => $z['i']['ihlal'] || $z['i']['izinsiz']));

$sure = function (array $z): string {
    $dk = (int)round(((($z['cikis'] ? strtotime($z['cikis']) : time())) - strtotime($z['giris'])) / 60);
    if ($dk < 60) return max(0, $dk) . ' dk';
    if ($dk < 1440) return intdiv($dk, 60) . ' sa ' . ($dk % 60) . ' dk';
    return intdiv($dk, 1440) . ' gün ' . intdiv($dk % 1440, 60) . ' sa';
};

if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new \XlsxWriter('Ziyaretci Defteri');
    $xl->header(['Giriş','Çıkış','Süre','Ad Soyad','Belge Türü','Kimlik / Belge No','Uyruk','Telefon','Kurum / Firma','Plaka',
                 'Ziyaret Edilen','Bina','Amaç','Gece İzni','Onaylayan','İzinsiz Giriş','Uyarı','Açıklama','Olay Tutanağı','İmzalı Tutanak']);
    foreach ($liste as $z) $xl->row([
        ['v'=>kamp_zaman_yaz($z['giris'])], ['v'=>$z['cikis'] ? kamp_zaman_yaz($z['cikis']) : 'İÇERİDE'], ['v'=>$sure($z)],
        ['v'=>$z['ad_soyad']], ['v'=>KAMP_KIMLIK_TUR[$z['kimlik_tur']] ?? ''], ['v'=>$z['kimlik_no']], ['v'=>$z['uyruk']], ['v'=>$z['telefon']],
        ['v'=>$z['kurum']], ['v'=>$z['plaka']], ['v'=>$z['ziyaret_edilen']], ['v'=>$z['bina_ad']], ['v'=>$z['amac']],
        ['v'=>$z['gece_izin'] ? 'Evet' : ''], ['v'=>$z['onaylayan']], ['v'=>$z['ihlal'] ? 'EVET' : ''],
        ['v'=>kamp_ziyaret_uyari_metin($z['i'])], ['v'=>$z['aciklama']],
        ['v'=>$z['i']['ihlal'] || $z['i']['izinsiz'] || kamp_olay_dolu(kamp_olay($z)) ? kamp_olay_no((int)$z['id']) : ''], ['v'=>$z['tutanak_url'] ? 'Var' : ''],
    ]);
    $xl->download('kamp_ziyaretci_' . ($anQ ? date('Ymd_Hi', strtotime($anQ)) : ($geceQ ?: $bas . '_' . $bit)) . '.xlsx');
}

$par = fn(array $ek) => 'ziyaretler.php?' . http_build_query(array_filter(array_merge(
    ['durum' => $durum, 'bas' => $bas, 'bit' => $bit, 'gece' => $geceQ, 'an' => $anQ ? kamp_zaman_input($anQ) : null, 'q' => $q ?: null], $ek),
    fn($v) => $v !== null && $v !== ''));
$buSayfa = $olay ? $par([]) : 'ziyaretler.php?' . http_build_query(['durum' => $durum, 'bas' => $bas, 'bit' => $bit]);
$pageTitle = 'Ziyaretçi Defteri — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i>Ziyaretçi Defteri</h4>
  <span class="badge bg-dark"><i class="bi bi-moon-stars me-1"></i>gece: <?= h(kamp_gece_etiket()) ?></span>
  <div class="ms-auto d-flex gap-2">
    <a href="<?= h($par(['export' => 'xlsx'])) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <?php if ($yaz): ?><a href="ziyaret_form.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Ziyaretçi Girişi</a><?php endif; ?>
  </div>
</div>

<div class="row g-2 mb-3">
  <?php foreach ([
    ['Şu an içeride', $sayac['iceride'], 'bi-door-open', 'primary', 'iceride'],
    ['Bugün gelen',   $sayac['bugun'],   'bi-calendar-day', 'info', 'bugun'],
    ['Uyarı',         $sayac['uyari'],   'bi-exclamation-triangle', $sayac['uyari'] ? 'danger' : 'secondary', 'uyari'],
    ['Gece ziyareti', $sayac['gece'],    'bi-moon-stars', 'dark', 'gece'],
  ] as [$ad, $n, $ik, $renk, $k]): ?>
  <div class="col-6 col-lg-3"><a href="<?= h($par(['durum' => $k, 'gece' => null, 'an' => null])) ?>" class="card border-0 shadow-sm h-100 text-decoration-none">
    <div class="card-body py-2"><div class="small text-muted"><i class="bi <?= $ik ?> me-1"></i><?= $ad ?></div>
      <div class="fs-4 fw-bold text-<?= $renk ?>"><?= (int)$n ?></div>
      <div class="small text-muted"><?= $olay ? 'sorgulanan anda / gecede' : ($k === 'iceride' ? 'çıkışı yazılmamış — tarihten bağımsız' : h(kamp_tarih_yaz($bas) . ' → ' . kamp_tarih_yaz($bit))) ?></div></div></a></div>
  <?php endforeach; ?>
</div>

<div class="row g-2 mb-3">
  <div class="col-lg-7">
    <form class="card border-0 shadow-sm h-100"><div class="card-body py-2 row g-2 align-items-end">
      <input type="hidden" name="durum" value="<?= h($durum) ?>">
      <div class="col-sm-3"><label class="form-label small mb-0">Başlangıç</label><input type="date" name="bas" class="form-control form-control-sm" value="<?= h($bas) ?>"></div>
      <div class="col-sm-3"><label class="form-label small mb-0">Bitiş</label><input type="date" name="bit" class="form-control form-control-sm" value="<?= h($bit) ?>"></div>
      <div class="col-sm-4"><label class="form-label small mb-0">Ara</label><input name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="ad, kimlik no, plaka, kimin misafiri…"></div>
      <div class="col-sm-2 d-flex gap-1"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button><a href="ziyaretler.php" class="btn btn-light btn-sm" title="Temizle"><i class="bi bi-x"></i></a></div>
    </div></form>
  </div>
  <div class="col-lg-5">
    <form class="card border-0 shadow-sm h-100 border-start border-dark border-4"><div class="card-body py-2 row g-2 align-items-end">
      <div class="col-12 small fw-semibold"><i class="bi bi-search-heart me-1"></i>Olay sorgusu — o sırada kampta hangi ziyaretçi vardı?</div>
      <div class="col-5"><label class="form-label small mb-0">Gece</label><input type="date" name="gece" class="form-control form-control-sm" value="<?= h($geceQ ?? '') ?>" max="<?= date('Y-m-d') ?>"></div>
      <div class="col-5"><label class="form-label small mb-0">ya da tam an</label><input type="datetime-local" name="an" class="form-control form-control-sm" value="<?= h($anQ ? kamp_zaman_input($anQ) : '') ?>"></div>
      <div class="col-2"><button class="btn btn-dark btn-sm w-100"><i class="bi bi-arrow-right"></i></button></div>
    </div></form>
  </div>
</div>

<?php if ($olay): ?>
<div class="alert alert-dark d-flex flex-wrap align-items-center gap-2 py-2">
  <i class="bi bi-moon-stars"></i>
  <span><strong><?= count($liste) ?> ziyaretçi</strong>
    <?= $anQ ? h(kamp_zaman_yaz($anQ)) . ' anında kamptaydı' : h(kamp_tarih_yaz($geceQ)) . ' gecesi (' . h(kamp_gece_etiket()) . ') kampta bulundu' ?>
    <?= $ihlalSay ? ' · <strong class="text-danger">' . $ihlalSay . ' tanesi izinsiz</strong>' : '' ?>.</span>
  <a class="ms-auto btn btn-sm btn-outline-light" href="hareketler.php?mod=tarihte&tarih=<?= h($anQ ? kamp_gece_tarihi($anQ) : $geceQ) ?>">
    O gece kampta yatanlar →</a>
  <a class="btn btn-sm btn-light" href="ziyaretler.php">Sorguyu kapat</a>
</div>
<?php else: ?>
<ul class="nav nav-pills mb-3 gap-1">
  <?php foreach ($DURUM as $k => $ad): ?>
  <li class="nav-item"><a class="nav-link py-1 <?= $durum === $k ? 'active' : '' ?>" href="<?= h($par(['durum' => $k])) ?>"><?= $ad ?>
    <span class="badge <?= $durum === $k ? 'bg-light text-dark' : ($k === 'uyari' && $sayac[$k] ? 'bg-danger' : 'bg-secondary') ?>"><?= $sayac[$k] ?></span></a></li>
  <?php endforeach; ?>
</ul>
<?php if ($durum === 'iceride'): ?><div class="small text-muted mb-2">Çıkışı yazılmamış tüm ziyaretçiler (tarih aralığından bağımsız).</div><?php endif; ?>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:.86rem">
    <thead class="table-light"><tr><th>Giriş</th><th>Çıkış</th><th>Ziyaretçi</th><th>Kurum / Plaka</th><th>Kimin misafiri</th><th>Onay</th><th></th></tr></thead><tbody>
    <?php if (!$liste): ?><tr><td colspan="7" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
    <?php foreach ($liste as $z): $i = $z['i']; ?>
      <tr class="<?= $i['ihlal'] || $i['izinsiz'] ? 'table-danger' : ($i['uzun'] ? 'table-warning' : '') ?>">
        <td class="text-nowrap"><?= h(kamp_zaman_yaz($z['giris'])) ?><?= $i['gece_giris'] ? ' <i class="bi bi-moon-fill text-dark" title="gece girişi"></i>' : '' ?></td>
        <td class="text-nowrap"><?php if ($z['cikis']): ?><?= h(kamp_zaman_yaz($z['cikis'])) ?><div class="small text-muted"><?= $sure($z) ?></div>
          <?php else: ?><span class="badge bg-primary">içeride</span><div class="small text-muted"><?= $sure($z) ?></div><?php endif; ?></td>
        <td><span class="fw-semibold"><?= h($z['ad_soyad']) ?></span>
          <?php if ($z['foto_url']): ?><a href="ziyaretler.php?foto=<?= (int)$z['id'] ?>" target="_blank" class="ms-1" title="Kimlik / kişi görüntüsü"><i class="bi bi-image"></i></a><?php endif; ?>
          <div class="small text-muted"><?= h(trim(($z['kimlik_no'] ? (KAMP_KIMLIK_TUR[$z['kimlik_tur']] ?? '') . ' ' . $z['kimlik_no'] : 'kimlik yazılmamış') . ($z['uyruk'] ? ' · ' . $z['uyruk'] : '') . ($z['telefon'] ? ' · ' . $z['telefon'] : ''))) ?></div>
          <div><?= kamp_ziyaret_rozet($i) ?></div></td>
        <td class="small"><?= h($z['kurum'] ?: '—') ?><?= $z['plaka'] ? '<div><span class="badge bg-light text-dark border">' . h($z['plaka']) . '</span></div>' : '' ?></td>
        <td class="small"><?php if ($z['kisi_id']): ?><a href="kisi_detay.php?id=<?= (int)$z['kisi_id'] ?>" class="text-decoration-none fw-semibold"><?= h($z['ziyaret_edilen']) ?></a>
          <?php else: ?><?= h($z['ziyaret_edilen'] ?: '—') ?><?php endif; ?>
          <div class="text-muted"><?= h(trim(($z['bina_ad'] ?? '') . ($z['amac'] ? ' · ' . $z['amac'] : ''), ' ·')) ?></div>
          <?= $z['aciklama'] ? '<div class="text-muted fst-italic">' . h(mb_strimwidth($z['aciklama'], 0, 90, '…', 'UTF-8')) . '</div>' : '' ?></td>
        <td class="small"><?= $z['gece_izin'] ? '<span class="badge bg-dark">gece izni</span> ' : '' ?><?= h($z['onaylayan'] ?: '—') ?></td>
        <td class="text-end text-nowrap">
          <?php $olayli = $i['ihlal'] || $i['izinsiz'] || kamp_olay_dolu(kamp_olay($z)); ?>
          <a href="olay_tutanak.php?id=<?= (int)$z['id'] ?>" class="btn btn-sm py-0 <?= $olayli ? ($z['tutanak_url'] ? 'btn-outline-success' : 'btn-outline-danger') : 'btn-outline-secondary' ?>"
             title="<?= $olayli ? 'Olay tutanağı ' . kamp_olay_no((int)$z['id']) . ($z['tutanak_url'] ? ' — imzalı kopya yüklü' : ' — imzalı kopya yüklenmedi') : 'Tutanak yazdır' ?>"><i class="bi <?= $z['tutanak_url'] ? 'bi-file-earmark-check' : 'bi-printer' ?>"></i><?= $olayli ? '<span class="ms-1 small">Tutanak</span>' : '' ?></a>
          <?php if ($yaz && !$z['cikis']): ?><button type="button" class="btn btn-danger btn-sm py-0 js-cikis" data-id="<?= (int)$z['id'] ?>" data-ad="<?= h($z['ad_soyad']) ?>" data-giris="<?= h(kamp_zaman_input($z['giris'])) ?>"><i class="bi bi-box-arrow-right me-1"></i>Çıkış</button><?php endif; ?>
          <?php if ($duz): ?><a href="ziyaret_form.php?id=<?= (int)$z['id'] ?>" class="btn btn-outline-secondary btn-sm py-0" title="Düzenle"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" onsubmit="return confirm('Bu ziyaret kaydı SİLİNSİN mi? Yalnız yanlış girilen kayıt için kullanın.')">
            <input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><input type="hidden" name="geri" value="<?= h($buSayfa) ?>">
            <button class="btn btn-outline-danger btn-sm py-0" title="Sil"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Kırmızı satır: gece penceresinde (<?= h(kamp_gece_etiket()) ?>) izinsiz bulunma ya da izinsiz giriş kaydı.
  Sarı satır: <?= KAMP_ZIYARET_UZUN_SAAT ?> saattir çıkışı yazılmamış — ziyaretçi gerçekten çıktıysa çıkış saatini yazın.</div>

<?php if ($yaz): ?>
<div class="modal fade" id="mCikis" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><form method="post" class="modal-content">
  <input type="hidden" name="islem" value="cikis"><input type="hidden" name="id" id="cikisId"><input type="hidden" name="geri" value="<?= h($buSayfa) ?>">
  <div class="modal-header py-2"><h6 class="modal-title">Ziyaretçi çıkışı</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="fw-semibold mb-2" id="cikisAd"></div>
    <label class="form-label small mb-0">Çıkış saati</label><input type="datetime-local" name="cikis" id="cikisZaman" class="form-control" required></div>
  <div class="modal-footer py-2"><button class="btn btn-danger btn-sm">Çıkışı yaz</button></div>
</form></div></div>
<script>
(function(){
  function simdi(){ var d=new Date(); d.setMinutes(d.getMinutes()-d.getTimezoneOffset()); return d.toISOString().slice(0,16); }
  document.querySelectorAll('.js-cikis').forEach(function(b){
    b.addEventListener('click', function(){
      document.getElementById('cikisId').value = b.dataset.id;
      document.getElementById('cikisAd').textContent = b.dataset.ad;
      var z = document.getElementById('cikisZaman'); z.value = simdi(); z.min = b.dataset.giris; z.max = simdi();
      if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(document.getElementById('mCikis')).show();
      else { var t = prompt('Çıkış saati (YYYY-AA-GGTSS:DD)', z.value); if (t) { z.value = t; z.form.submit(); } }
    });
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
