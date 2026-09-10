<?php
/**
 * it/cihazlar.php — Cihaz / lisans listesi: filtre, arama, whitelist sıralama, sayfalama, Excel.
 * Varsayılan görünümde envanterden düşenler (hurda / kayıp / hibe) gizlidir — durum filtresiyle listelenir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';

it_semasi_kur($pdoIt);
$pageTitle = 'Cihazlar — IT Envanter';

[$wsql, $par, $etkin] = it_filtre($_GET);
$lokId = (int)($_GET['lokasyon_id'] ?? 0); $perId = (int)($_GET['personel_id'] ?? 0);
if ($lokId || $perId) {
    $ek = [];
    if ($lokId) { $ek[] = 'lokasyon_id IN (' . implode(',', array_map('intval', it_lokasyon_altlar($pdoIt, $lokId))) . ')'; $etkin['lokasyon_id'] = $lokId; }
    if ($perId) { $ek[] = 'personel_id=' . $perId; $etkin['personel_id'] = $perId; }
    $wsql = ($wsql ? $wsql . ' AND ' : ' WHERE ') . implode(' AND ', $ek);
}

$oz = $pdoIt->prepare("SELECT COUNT(*) adet, COALESCE(SUM(fiyat),0) mali, SUM(durum='aktif') aktif,
                              COUNT(DISTINCT CASE WHEN zimmetli<>'' THEN zimmetli END) kisi FROM it_cihazlar $wsql");
$oz->execute($par);
$oz = $oz->fetch() ?: ['adet'=>0,'mali'=>0,'aktif'=>0,'kisi'=>0];

$sirala = ['no'=>'envanter_no', 'ad'=>'ad', 'kategori'=>'kategori, ad', 'durum'=>'durum, ad', 'zimmetli'=>'zimmetli, ad',
           'lokasyon'=>'lokasyon, ad', 'garanti'=>'garanti_bitis', 'fiyat'=>'fiyat', 'alis'=>'alis_tarihi', 'guncel'=>'updated_at'];
$skAnahtar = array_key_exists($_GET['sk'] ?? '', $sirala) ? $_GET['sk'] : 'no';
$sk  = $sirala[$skAnahtar];
$yon = ($_GET['yon'] ?? '') === 'desc' ? 'DESC' : 'ASC';

// ── Excel dışa aktarma (filtrelere saygılı) ─────────────────────────────────
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar $wsql ORDER BY $sk $yon, id");
    $st->execute($par);
    $xl = new \XlsxWriter('IT Envanter');
    $xl->header(['Envanter No','Varlık / Nesne No','Kategori','Cihaz','Marka','Model','Seri No','Şasi No','IMEI','Durum','Zimmetli','Departman','Lokasyon',
                 'Zimmet Tarihi','Alış Tarihi','Garanti Bitiş','Fiyat (TL)','Tedarikçi','Fatura No','IP','MAC','İşletim Sistemi','Özellikler','Lisans Adet','Notlar']);
    foreach ($st->fetchAll() as $r) {
        $xl->row([
            ['v'=>$r['envanter_no']], ['v'=>$r['varlik_kodu'] ?? ''], ['v'=>it_kategoriAd($r['kategori'])], ['v'=>$r['ad']], ['v'=>$r['marka']], ['v'=>$r['model']],
            ['v'=>$r['seri_no']], ['v'=>$r['sasi_no'] ?? ''], ['v'=>$r['imei'] ?? ''], ['v'=>it_durumAd($r['durum'])], ['v'=>$r['zimmetli']], ['v'=>$r['departman']], ['v'=>$r['lokasyon']],
            ['v'=>$r['zimmet_tarihi'],'t'=>'date'], ['v'=>$r['alis_tarihi'],'t'=>'date'], ['v'=>$r['garanti_bitis'],'t'=>'date'],
            ['v'=>(float)$r['fiyat'],'t'=>'number'], ['v'=>$r['tedarikci']], ['v'=>$r['fatura_no']], ['v'=>$r['ip_adresi']],
            ['v'=>$r['mac_adresi']], ['v'=>$r['isletim_sistemi']], ['v'=>$r['ozellikler']], ['v'=>$r['lisans_adet'],'t'=>'number'], ['v'=>$r['notlar']],
        ]);
    }
    $xl->download('it_envanter_' . date('Ymd_Hi') . '.xlsx');
}

// ── Liste (sayfalı) ──────────────────────────────────────────────────────────
$adet  = 100;
$sayfa = max(1, (int)($_GET['s'] ?? 1));
$sonSayfa = max(1, (int)ceil((int)$oz['adet'] / $adet));
if ($sayfa > $sonSayfa) $sayfa = $sonSayfa;
$atla  = ($sayfa - 1) * $adet;
$st = $pdoIt->prepare("SELECT * FROM it_cihazlar $wsql ORDER BY $sk $yon, id LIMIT $adet OFFSET $atla");
$st->execute($par);
$liste = $st->fetchAll();

$sec = ['zimmetli'=>it_secenekler($pdoIt,'zimmetli'), 'departman'=>it_secenekler($pdoIt,'departman'),
        'lokasyon'=>it_secenekler($pdoIt,'lokasyon'), 'marka'=>it_secenekler($pdoIt,'marka')];
$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
$srtUrl = function (string $k) use ($skAnahtar, $yon) {
    $q = $_GET; $q['sk'] = $k; $q['yon'] = ($skAnahtar === $k && $yon === 'ASC') ? 'desc' : 'asc'; unset($q['s']);
    return 'cihazlar.php?' . http_build_query($q);
};
$srtIk = fn(string $k) => $skAnahtar === $k ? '<i class="bi bi-caret-' . ($yon === 'ASC' ? 'up' : 'down') . '-fill small"></i>' : '';
$yazabilir = yetki_var('giris');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-pc-display text-primary me-2"></i>Cihazlar &amp; Lisanslar</h4>
    <div class="d-flex gap-2">
        <a href="cihazlar.php?<?= h(http_build_query(array_merge($_GET, ['export'=>'xlsx']))) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        <?php if ($yazabilir): ?><a href="cihaz_import.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-in-down me-1"></i>İçe Aktar</a>
        <a href="cihaz_form.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Yeni Cihaz</a><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<form method="get" class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-2"><label class="form-label small mb-0">Ara</label>
        <input name="q" class="form-control form-control-sm" value="<?= h($etkin['q'] ?? '') ?>" placeholder="envanter no, ad, seri, kişi, IP…"></div>
      <div class="col-md-2"><label class="form-label small mb-0">Kategori</label>
        <select name="kategori" class="form-select form-select-sm"><option value="">Tümü</option>
          <?php foreach (IT_KATEGORI as $k => [$ad]): ?><option value="<?= $k ?>" <?= ($etkin['kategori'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label small mb-0">Durum</label>
        <select name="durum" class="form-select form-select-sm"><option value="">Hurda hariç tümü</option>
          <?php foreach (IT_DURUM as $k => [$ad]): ?><option value="<?= $k ?>" <?= ($etkin['durum'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label small mb-0">Zimmetli</label>
        <select name="zimmetli" class="form-select form-select-sm"><option value="">Tümü</option>
          <?php foreach ($sec['zimmetli'] as $x): ?><option value="<?= h($x) ?>" <?= ($etkin['zimmetli'] ?? '') === $x ? 'selected' : '' ?>><?= h($x) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label small mb-0">Lokasyon / Proje</label>
        <select name="lokasyon_id" class="form-select form-select-sm"><option value="">Tümü</option><?= it_lokasyon_options($pdoIt, $lokId, false) ?></select></div>
      <div class="col-md-1"><label class="form-label small mb-0">Departman</label>
        <select name="departman" class="form-select form-select-sm"><option value="">Tümü</option>
          <?php foreach ($sec['departman'] as $x): ?><option value="<?= h($x) ?>" <?= ($etkin['departman'] ?? '') === $x ? 'selected' : '' ?>><?= h($x) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-1"><label class="form-label small mb-0">Garanti</label>
        <select name="garanti" class="form-select form-select-sm"><option value="">—</option>
          <option value="bitiyor" <?= ($etkin['garanti'] ?? '') === 'bitiyor' ? 'selected' : '' ?>>60 günde bitiyor</option>
          <option value="bitti" <?= ($etkin['garanti'] ?? '') === 'bitti' ? 'selected' : '' ?>>Bitti</option>
          <option value="devam" <?= ($etkin['garanti'] ?? '') === 'devam' ? 'selected' : '' ?>>Devam ediyor</option></select></div>
      <div class="col-md-1 d-flex gap-1">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i></button>
        <?php if ($etkin): ?><a href="cihazlar.php" class="btn btn-outline-secondary btn-sm" title="Temizle"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      </div>
    </div>
  </div>
</form>

<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted">Kayıt</div><div class="fs-5 fw-bold"><?= $f0($oz['adet']) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted">Kullanımda</div><div class="fs-5 fw-bold text-success"><?= $f0($oz['aktif']) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted">Zimmetli kişi</div><div class="fs-5 fw-bold"><?= $f0($oz['kisi']) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted">Mali değer</div><div class="fs-5 fw-bold"><?= $f2($oz['mali']) ?> <small class="text-muted">TL</small></div></div></div></div>
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light">
        <tr>
          <th style="width:52px"></th>
          <th><a href="<?= h($srtUrl('no')) ?>" class="text-decoration-none text-dark">Envanter No <?= $srtIk('no') ?></a></th>
          <th><a href="<?= h($srtUrl('ad')) ?>" class="text-decoration-none text-dark">Cihaz <?= $srtIk('ad') ?></a></th>
          <th><a href="<?= h($srtUrl('kategori')) ?>" class="text-decoration-none text-dark">Kategori <?= $srtIk('kategori') ?></a></th>
          <th>Seri No</th>
          <th><a href="<?= h($srtUrl('durum')) ?>" class="text-decoration-none text-dark">Durum <?= $srtIk('durum') ?></a></th>
          <th><a href="<?= h($srtUrl('zimmetli')) ?>" class="text-decoration-none text-dark">Zimmetli <?= $srtIk('zimmetli') ?></a></th>
          <th><a href="<?= h($srtUrl('lokasyon')) ?>" class="text-decoration-none text-dark">Lokasyon <?= $srtIk('lokasyon') ?></a></th>
          <th><a href="<?= h($srtUrl('garanti')) ?>" class="text-decoration-none text-dark">Garanti <?= $srtIk('garanti') ?></a></th>
          <th class="text-end"><a href="<?= h($srtUrl('fiyat')) ?>" class="text-decoration-none text-dark">Fiyat <?= $srtIk('fiyat') ?></a></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$liste): ?>
        <tr><td colspan="11" class="text-center text-muted py-4">Kayıt yok.<?php if ($yazabilir && !$etkin): ?> <a href="cihaz_form.php">İlk cihazı ekleyin</a>.<?php endif; ?></td></tr>
      <?php endif; ?>
      <?php foreach ($liste as $r): $gk = it_garanti_kalan($r['garanti_bitis']); ?>
        <tr class="<?= it_durum_dustu($r['durum']) ? 'text-muted' : '' ?>">
          <td>
            <?php if ($r['foto_url']): ?>
              <a href="cihaz_detay.php?id=<?= (int)$r['id'] ?>"><img src="../<?= h($r['foto_url']) ?>" alt="" style="width:44px;height:36px;object-fit:cover;border-radius:6px"></a>
            <?php else: ?>
              <div class="d-flex align-items-center justify-content-center bg-light rounded" style="width:44px;height:36px"><i class="bi <?= h(it_kategoriIkon($r['kategori'])) ?> text-muted"></i></div>
            <?php endif; ?>
          </td>
          <td><a href="cihaz_detay.php?id=<?= (int)$r['id'] ?>" class="font-monospace fw-semibold text-decoration-none"><?= h($r['envanter_no']) ?></a></td>
          <td><div class="fw-semibold"><?= h($r['ad']) ?></div><div class="small text-muted"><?= h(trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? ''))) ?></div></td>
          <td><i class="bi <?= h(it_kategoriIkon($r['kategori'])) ?> me-1 text-muted"></i><?= h(it_kategoriAd($r['kategori'])) ?></td>
          <td class="font-monospace small"><?= h($r['seri_no'] ?: '—') ?></td>
          <td><?= it_durumBadge($r['durum']) ?></td>
          <td><?= $r['zimmetli'] ? '<i class="bi bi-person me-1 text-muted"></i>' . ($r['personel_id'] ? '<a href="personel_detay.php?id=' . (int)$r['personel_id'] . '" class="text-decoration-none">' . h($r['zimmetli']) . '</a>' : h($r['zimmetli'])) . ($r['departman'] ? '<div class="small text-muted">' . h($r['departman']) . '</div>' : '') : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= h($r['lokasyon_id'] ? it_lokasyon_etiket($pdoIt, (int)$r['lokasyon_id']) : ($r['lokasyon'] ?: '—')) ?></td>
          <td>
            <?php if ($gk === null): ?><span class="text-muted">—</span>
            <?php elseif ($gk < 0): ?><span class="badge bg-light text-danger border">bitti</span>
            <?php elseif ($gk <= 60): ?><span class="badge bg-warning text-dark"><?= $gk ?> gün</span>
            <?php else: ?><span class="small"><?= format_date($r['garanti_bitis']) ?></span><?php endif; ?>
          </td>
          <td class="text-end"><?= $r['fiyat'] !== null ? $f2($r['fiyat']) : '—' ?></td>
          <td class="text-end text-nowrap">
            <a href="cihaz_detay.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Detay"><i class="bi bi-eye"></i></a>
            <?php if ($r['zimmetli']): ?><a href="zimmet_tutanak.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Zimmet tutanağı"><i class="bi bi-file-earmark-text"></i></a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($sonSayfa > 1): ?>
  <div class="card-footer bg-white d-flex justify-content-between align-items-center small">
    <span>Sayfa <?= $sayfa ?> / <?= $sonSayfa ?> · <?= $f0($oz['adet']) ?> kayıt</span>
    <div class="btn-group">
      <?php $q = $_GET; ?>
      <a class="btn btn-sm btn-outline-secondary <?= $sayfa <= 1 ? 'disabled' : '' ?>" href="cihazlar.php?<?= h(http_build_query(array_merge($q, ['s'=>$sayfa-1]))) ?>">‹</a>
      <a class="btn btn-sm btn-outline-secondary <?= $sayfa >= $sonSayfa ? 'disabled' : '' ?>" href="cihazlar.php?<?= h(http_build_query(array_merge($q, ['s'=>$sayfa+1]))) ?>">›</a>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
