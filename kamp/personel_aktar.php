<?php
/**
 * kamp/personel_aktar.php — PERSONEL TAKİP'TEN KİŞİ AL (tek yönlü köprü, §0)
 *
 * Personel Takip modülündeki personel listesi okunur; seçilenler (ya da süzülen listenin tamamı)
 * kamp kişi listesine KOPYALANIR. Eşleşme pts_id → sicil no → ad soyad; kamptaki mevcut kişide yalnız
 * boş alanlar dolar. Personel Takip'e hiçbir şey YAZILMAZ. Yerleştirme ayrı adımdır (krokiden).
 * Ayrıca: Personel Takip'te işten ayrılmış görünen ama kampta hâlâ kalan kişiler uyarı olarak listelenir
 * (çıkış OTOMATİK yapılmaz — kamptan ayrılış tarihi farklı olabilir, karar kamp sorumlusunundur).
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'aktar') {
    $geri = kamp_geri($_POST['geri'] ?? '', 'personel_aktar.php');
    try {
        if (!$yaz) throw new RuntimeException('Aktarım için veri girişi yetkisi gerekir.');
        $ids = !empty($_POST['hepsi']) ? array_map('intval', array_filter(explode(',', (string)($_POST['liste'] ?? ''))))
                                       : array_map('intval', (array)($_POST['sec'] ?? []));
        if (!$ids) throw new RuntimeException('Aktarılacak personel seçin.');
        $r = kamp_pts_aktar($pdoKamp, $ids, ['firma_id' => (int)($_POST['firma_id'] ?? 0), 'kategori' => (string)($_POST['kategori'] ?? '')]);
        kamp_audit('kamp_kisiler', 0, 'INSERT', null, ['personel_takip_aktarimi' => $r['yeni'] . ' yeni / ' . $r['guncellenen'] . ' güncellenen']);
        unset($r['esle']);
        $_SESSION['kamp_pts_rapor'] = $r;
        $geri .= (str_contains($geri, '?') ? '&' : '?') . 'rapor=1';
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect($geri);
}

$var    = kamp_pts_var();
$durum  = in_array($_GET['durum'] ?? '', ['yok','var','hepsi','ayrilan'], true) ? $_GET['durum'] : 'yok';
$q      = trim((string)($_GET['q'] ?? ''));
$birim  = trim((string)($_GET['birim'] ?? ''));
$rapor  = isset($_GET['rapor']) ? ($_SESSION['kamp_pts_rapor'] ?? null) : null;

$satir = []; $sayac = ['yok' => 0, 'var' => 0, 'hepsi' => 0, 'ayrilan' => 0]; $birimler = []; $uyari = [];
if ($var) {
    $d = kamp_pts_dizin($pdoKamp);
    $acik = [];   // kamp kişisi → açık konaklama yeri
    foreach (kamp_kisi_liste($pdoKamp) as $k) if ($k['konaklama_id']) $acik[(int)$k['id']] = $k;
    $nq = array_values(array_filter(explode(' ', kamp_norm($q))));
    foreach (kamp_pts_liste(true) as $p) {
        [$kid, $nasil] = kamp_pts_esle($p, $d);
        $p['kid'] = $kid; $p['nasil'] = $nasil; $p['kamp'] = $kid && isset($acik[$kid]) ? $acik[$kid] : null;
        $ayrildi = !empty($p['isten_cikis']);
        if ($ayrildi && $p['kamp']) $uyari[] = $p;
        if ($p['birim']) $birimler[$p['birim']] = true;
        if ($birim !== '' && $p['birim'] !== $birim) continue;
        if ($nq) { $hay = kamp_norm($p['ad'] . ' ' . $p['soyad'] . ' ' . $p['sicil_no'] . ' ' . $p['unvan'] . ' ' . $p['birim'] . ' ' . $p['lokasyon']);
                   foreach ($nq as $k) if (!str_contains($hay, $k)) continue 2; }
        if ($ayrildi) $sayac['ayrilan']++;
        else { $sayac['hepsi']++; $sayac[$kid ? 'var' : 'yok']++; }
        $ok = match ($durum) { 'yok' => !$ayrildi && !$kid, 'var' => !$ayrildi && $kid, 'ayrilan' => $ayrildi, default => !$ayrildi };
        if ($ok) $satir[] = $p;
    }
    ksort($birimler);
}
$aktarilabilir = array_values(array_filter($satir, fn($p) => !$p['kid'] && $p['nasil'] !== 'cok' && empty($p['isten_cikis'])));
$qs = fn(array $e) => 'personel_aktar.php?' . http_build_query(array_filter(array_merge(['durum'=>$durum,'q'=>$q ?: null,'birim'=>$birim ?: null], $e), fn($v) => $v !== null && $v !== ''));

$pageTitle = 'Personel Takip\'ten Al — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i>Personel Takip'ten Kişi Al</h4>
  <a href="kisiler.php" class="btn btn-light btn-sm ms-auto">Kamp kişileri</a>
</div>

<?php if (!$var): ?>
<div class="alert alert-warning"><i class="bi bi-plug me-1"></i><strong>Personel Takip modülüne erişilemiyor.</strong>
  Modül kurulu değil ya da veritabanı bağlantısı yok (<code>PTS_DB_NAME</code>). Kamp kişilerini elle ya da Excel'den ekleyebilirsiniz.</div>
<?php else: ?>

<div class="alert alert-info small py-2"><i class="bi bi-info-circle me-1"></i>
  Personel Takip'teki personel kamp listesine <strong>kopyalanır</strong> (sicil no ile eşleşir; kampta zaten olan kişide yalnız boş alanlar dolar).
  Personel Takip'e hiçbir şey yazılmaz; aktarımdan sonra iki liste bağımsızdır. Yerleştirme krokiden yapılır —
  krokideki yerleştirme penceresinde de Personel Takip çalışanları aranabilir.</div>

<?php if ($rapor): ?>
<div class="card border-0 shadow-sm mb-3 border-start border-4 border-success"><div class="card-body">
  <div class="d-flex flex-wrap gap-2 mb-2">
    <span class="badge bg-secondary fs-6">Seçilen <?= (int)$rapor['okunan'] ?></span>
    <span class="badge bg-success fs-6">Yeni kişi <?= (int)$rapor['yeni'] ?></span>
    <span class="badge bg-info text-dark fs-6">Güncellenen <?= (int)$rapor['guncellenen'] ?></span>
    <span class="badge bg-light text-dark border fs-6">Değişmeyen <?= (int)$rapor['degismeyen'] ?></span>
    <span class="badge bg-warning text-dark fs-6">Atlanan <?= (int)$rapor['atlanan'] ?></span>
  </div>
  <?php foreach (['liste_yeni' => 'Kamp listesine eklenenler', 'liste_guncellenen' => 'Tamamlanan kişiler', 'liste_atlanan' => 'Atlananlar'] as $k => $bas): if (!$rapor[$k]) continue; ?>
  <details class="mt-2" <?= $k === 'liste_atlanan' ? 'open' : '' ?>><summary class="fw-semibold small"><?= $bas ?> (<?= count($rapor[$k]) ?>)</summary>
    <ul class="small mb-0"><?php foreach (array_slice($rapor[$k], 0, 300) as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul></details>
  <?php endforeach; ?>
  <div class="mt-2"><a href="yerlesim.php" class="btn btn-primary btn-sm">Krokiye git ve yerleştir</a></div>
</div></div>
<?php endif; ?>

<?php if ($uyari): ?>
<div class="alert alert-danger">
  <strong><i class="bi bi-exclamation-triangle me-1"></i><?= count($uyari) ?> kişi Personel Takip'te işten ayrılmış görünüyor ama kampta hâlâ kalıyor.</strong>
  Kamptan çıkışları otomatik yapılmaz — kontrol edip kişi kartından çıkış verin.
  <ul class="mb-0 mt-1 small"><?php foreach ($uyari as $p): ?>
    <li><a href="kisi_detay.php?id=<?= (int)$p['kid'] ?>" class="alert-link"><?= h(kamp_buyuk($p['ad'] . ' ' . $p['soyad'])) ?></a> — işten çıkış <?= h(kamp_tarih_yaz($p['isten_cikis'])) ?> ·
      kampta <?= h(kamp_yatak_etiket($p['kamp'])) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<ul class="nav nav-pills mb-3 gap-1">
  <?php foreach (['yok' => 'Kamp listesinde olmayan', 'var' => 'Kamp listesinde olan', 'hepsi' => 'Tüm çalışanlar', 'ayrilan' => 'İşten ayrılmış'] as $k => $ad): ?>
  <li class="nav-item"><a class="nav-link py-1 <?= $durum === $k ? 'active' : '' ?>" href="<?= h($qs(['durum' => $k])) ?>"><?= $ad ?> <span class="badge <?= $durum === $k ? 'bg-light text-dark' : 'bg-secondary' ?>"><?= $sayac[$k] ?></span></a></li>
  <?php endforeach; ?>
</ul>

<form class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 align-items-end">
  <input type="hidden" name="durum" value="<?= h($durum) ?>">
  <div class="col-md-5"><label class="form-label small mb-0">Ara</label><input name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="ad soyad, sicil, unvan, birim…"></div>
  <div class="col-md-5"><label class="form-label small mb-0">Birim</label><select name="birim" class="form-select form-select-sm" data-ara><option value="">Tümü</option>
    <?php foreach (array_keys($birimler) as $b): ?><option value="<?= h($b) ?>"<?= $birim === $b ? ' selected' : '' ?>><?= h($b) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Süz</button></div>
</div></form>

<form method="post" id="fAktar">
  <input type="hidden" name="islem" value="aktar">
  <input type="hidden" name="geri" value="<?= h($qs([])) ?>">
  <input type="hidden" name="liste" value="<?= h(implode(',', array_map(fn($p) => (int)$p['id'], $aktarilabilir))) ?>">
  <?php if ($yaz): ?>
  <div class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small mb-0">Firma (yeni kişilere ve firması boş olanlara)</label>
      <select name="firma_id" class="form-select form-select-sm" data-ara><?= kamp_firma_options($pdoKamp, null, '— boş bırak —') ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0">Kategori</label>
      <select name="kategori" class="form-select form-select-sm"><option value="">Unvandan sez (mühendis/şef → staff…)</option>
        <?php foreach (KAMP_KATEGORI as $k => [$ad]): ?><option value="<?= $k ?>"><?= h($ad) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-5 d-flex gap-2 flex-wrap">
      <button class="btn btn-primary btn-sm" id="btnSecili" disabled><i class="bi bi-check2-square me-1"></i>Seçilenleri al (<span id="nSec">0</span>)</button>
      <?php if ($aktarilabilir): ?><button class="btn btn-outline-primary btn-sm" name="hepsi" value="1" onclick="return confirm('Listedeki <?= count($aktarilabilir) ?> kişinin tamamı kamp listesine aktarılsın mı?')">Listedekilerin tümünü al (<?= count($aktarilabilir) ?>)</button><?php endif; ?>
    </div>
  </div></div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light"><tr>
        <th style="width:32px"><?php if ($yaz && $aktarilabilir): ?><input type="checkbox" class="form-check-input" id="secHepsi" title="Tümünü seç"><?php endif; ?></th>
        <th>Ad Soyad</th><th>Sicil</th><th>Unvan</th><th>Birim</th><th>Lokasyon</th><th>Kamp listesinde</th></tr></thead><tbody>
      <?php if (!$satir): ?><tr><td colspan="7" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
      <?php foreach ($satir as $p): ?>
        <tr class="<?= !empty($p['isten_cikis']) ? 'text-muted' : '' ?>">
          <td><?php if ($yaz && !$p['kid'] && $p['nasil'] !== 'cok' && empty($p['isten_cikis'])): ?><input type="checkbox" class="form-check-input js-sec" name="sec[]" value="<?= (int)$p['id'] ?>"><?php endif; ?></td>
          <td class="fw-semibold"><?= h(kamp_buyuk($p['ad'] . ' ' . $p['soyad'])) ?><?= !empty($p['isten_cikis']) ? ' <span class="badge bg-secondary">ayrıldı ' . h(kamp_tarih_yaz($p['isten_cikis'])) . '</span>' : '' ?></td>
          <td class="small"><?= h($p['sicil_no'] ?: '—') ?></td>
          <td class="small"><?= h($p['unvan'] ?: '') ?></td>
          <td class="small"><?= h($p['birim'] ?: '') ?></td>
          <td class="small"><?= h($p['lokasyon'] ?: '') ?></td>
          <td class="small">
            <?php if ($p['kid']): ?><a href="kisi_detay.php?id=<?= (int)$p['kid'] ?>" class="badge bg-success text-decoration-none">var · <?= h($p['nasil']) ?></a>
              <?= $p['kamp'] ? '<div class="text-muted">' . h(kamp_yatak_etiket($p['kamp'])) . '</div>' : '<div class="text-muted">yerleşmemiş</div>' ?>
            <?php elseif ($p['nasil'] === 'cok'): ?><span class="badge bg-warning text-dark">aynı adlı birden çok kişi</span>
            <?php else: ?><span class="badge bg-light text-dark border">yok</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
</form>
<?php endif; ?>
<script src="../assets/js/secici.js"></script>
<script>
(function(){
  var f = document.getElementById('fAktar'); if (!f) return;
  var say = function(){ var n = f.querySelectorAll('.js-sec:checked').length; var e = document.getElementById('nSec'); if (e) e.textContent = n;
    var b = document.getElementById('btnSecili'); if (b) b.disabled = !n; };
  f.addEventListener('change', function(e){
    if (e.target.id === 'secHepsi') f.querySelectorAll('.js-sec').forEach(function(c){ c.checked = e.target.checked; });
    say();
  });
  say();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
