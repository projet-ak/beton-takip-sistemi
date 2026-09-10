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

$maliGoster = it_mali_goster();      // garanti + fiyat gösterimi (varsayılan KAPALI)
$sirala = ['kod'=>'cihaz_kodu, envanter_no', 'ifs'=>'varlik_kodu, envanter_no', 'no'=>'envanter_no',
           'ad'=>'ad', 'kategori'=>'kategori, ad', 'seri'=>'seri_no, ad', 'durum'=>'durum, ad', 'zimmetli'=>'zimmetli, ad',
           'lokasyon'=>'lokasyon, ad', 'garanti'=>'garanti_bitis', 'fiyat'=>'fiyat', 'alis'=>'alis_tarihi', 'guncel'=>'updated_at'];
$skAnahtar = array_key_exists($_GET['sk'] ?? '', $sirala) ? $_GET['sk'] : 'kod';
$sk  = $sirala[$skAnahtar];
$yon = ($_GET['yon'] ?? '') === 'desc' ? 'DESC' : 'ASC';

// ── Excel dışa aktarma (filtrelere saygılı) ─────────────────────────────────
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar $wsql ORDER BY $sk $yon, id");
    $st->execute($par);
    $xl = new \XlsxWriter('IT Envanter');
    $xl->header(array_merge(
        ['Envanter No','Cihaz Kodu','IFS Seri Nesne No','Kategori','Cihaz','Marka','Model','Seri No','Şasi No','IMEI','Durum','Zimmetli','Departman','Lokasyon','Zimmet Tarihi','Alış Tarihi'],
        $maliGoster ? ['Garanti Bitiş','Fiyat (TL)'] : [],
        ['Tedarikçi','Fatura No','IP','MAC','İşletim Sistemi','Özellikler','Lisans Adet','Notlar']));
    foreach ($st->fetchAll() as $r) {
        $xl->row(array_merge([
            ['v'=>$r['envanter_no']], ['v'=>$r['cihaz_kodu'] ?? ''], ['v'=>$r['varlik_kodu'] ?? ''], ['v'=>it_kategoriAd($r['kategori'])], ['v'=>$r['ad']], ['v'=>$r['marka']], ['v'=>$r['model']],
            ['v'=>$r['seri_no']], ['v'=>$r['sasi_no'] ?? ''], ['v'=>$r['imei'] ?? ''], ['v'=>it_durumAd($r['durum'])], ['v'=>$r['zimmetli']], ['v'=>$r['departman']], ['v'=>$r['lokasyon']],
            ['v'=>$r['zimmet_tarihi'],'t'=>'date'], ['v'=>$r['alis_tarihi'],'t'=>'date'],
        ], $maliGoster ? [['v'=>$r['garanti_bitis'],'t'=>'date'], ['v'=>(float)$r['fiyat'],'t'=>'number']] : [], [
            ['v'=>$r['tedarikci']], ['v'=>$r['fatura_no']], ['v'=>$r['ip_adresi']],
            ['v'=>$r['mac_adresi']], ['v'=>$r['isletim_sistemi']], ['v'=>$r['ozellikler']], ['v'=>$r['lisans_adet'],'t'=>'number'], ['v'=>$r['notlar']],
        ]));
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

$belgeSay = it_belge_sayilari($pdoIt, array_column($liste, 'id'));
// Transferdeki cihazlar için "kaç gündür yolda" (teslim alınmayan sevkiyat gözden kaçmasın)
$trGun = it_transfer_gunleri($pdoIt, array_column(array_filter($liste, fn($r) => $r['durum'] === 'transfer'), 'id'));
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
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" title="Sütunları gizle / göster">
                <i class="bi bi-layout-three-columns me-1"></i>Sütunlar<span class="badge bg-secondary ms-1 d-none" id="kolonRozet"></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end p-0 shadow" style="min-width:240px" id="kolonMenu"></div>
        </div>
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
          <?php foreach (IT_DURUM as $k => [$ad]): ?><option value="<?= $k ?>" <?= ($etkin['durum'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?>
          <optgroup label="Gruplu">
            <?php foreach (IT_DURUM_SANAL as $k => $ad): ?><option value="<?= h($k) ?>" <?= ($etkin['durum'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?>
          </optgroup></select></div>
      <div class="col-md-2"><label class="form-label small mb-0">Zimmetli</label>
        <select name="zimmetli" class="form-select form-select-sm"><option value="">Tümü</option>
          <?php foreach ($sec['zimmetli'] as $x): ?><option value="<?= h($x) ?>" <?= ($etkin['zimmetli'] ?? '') === $x ? 'selected' : '' ?>><?= h($x) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label small mb-0">Lokasyon / Proje</label>
        <select name="lokasyon_id" class="form-select form-select-sm"><option value="">Tümü</option><?= it_lokasyon_options($pdoIt, $lokId, false) ?></select></div>
      <div class="col-md-1"><label class="form-label small mb-0">Departman</label>
        <select name="departman" class="form-select form-select-sm"><option value="">Tümü</option>
          <?php foreach ($sec['departman'] as $x): ?><option value="<?= h($x) ?>" <?= ($etkin['departman'] ?? '') === $x ? 'selected' : '' ?>><?= h($x) ?></option><?php endforeach; ?></select></div>
      <?php if ($maliGoster): ?>
      <div class="col-md-1"><label class="form-label small mb-0">Garanti</label>
        <select name="garanti" class="form-select form-select-sm"><option value="">—</option>
          <option value="bitiyor" <?= ($etkin['garanti'] ?? '') === 'bitiyor' ? 'selected' : '' ?>>60 günde bitiyor</option>
          <option value="bitti" <?= ($etkin['garanti'] ?? '') === 'bitti' ? 'selected' : '' ?>>Bitti</option>
          <option value="devam" <?= ($etkin['garanti'] ?? '') === 'devam' ? 'selected' : '' ?>>Devam ediyor</option></select></div>
      <?php endif; ?>
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
  <?php if ($maliGoster): ?>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted">Mali değer</div><div class="fs-5 fw-bold"><?= $f2($oz['mali']) ?> <small class="text-muted">TL</small></div></div></div></div>
  <?php else: ?>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted">Bu sayfada imzalı evrak</div><div class="fs-5 fw-bold text-primary"><?= $f0(array_sum(array_column($belgeSay, 'imzali'))) ?></div></div></div></div>
  <?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table id="cihazTablo" class="table table-hover table-sm align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light">
        <tr>
          <th style="width:52px" data-kol="foto" data-kol-ad="Fotoğraf"></th>
          <th data-kol="kod" data-kol-ad="Cihaz Kodu"><a href="<?= h($srtUrl('kod')) ?>" class="text-decoration-none text-dark">Cihaz Kodu <?= $srtIk('kod') ?></a></th>
          <th data-kol="ifs" data-kol-ad="IFS Seri Nesne No"><a href="<?= h($srtUrl('ifs')) ?>" class="text-decoration-none text-dark">IFS Seri Nesne No <?= $srtIk('ifs') ?></a></th>
          <th data-kol="ad" data-kol-ad="Cihaz"><a href="<?= h($srtUrl('ad')) ?>" class="text-decoration-none text-dark">Cihaz <?= $srtIk('ad') ?></a></th>
          <th data-kol="kategori" data-kol-ad="Kategori"><a href="<?= h($srtUrl('kategori')) ?>" class="text-decoration-none text-dark">Kategori <?= $srtIk('kategori') ?></a></th>
          <th data-kol="seri" data-kol-ad="Seri No"><a href="<?= h($srtUrl('seri')) ?>" class="text-decoration-none text-dark">Seri No <?= $srtIk('seri') ?></a></th>
          <th data-kol="durum" data-kol-ad="Durum"><a href="<?= h($srtUrl('durum')) ?>" class="text-decoration-none text-dark">Durum <?= $srtIk('durum') ?></a></th>
          <th data-kol="zimmetli" data-kol-ad="Zimmetli"><a href="<?= h($srtUrl('zimmetli')) ?>" class="text-decoration-none text-dark">Zimmetli <?= $srtIk('zimmetli') ?></a></th>
          <th data-kol="lokasyon" data-kol-ad="Lokasyon"><a href="<?= h($srtUrl('lokasyon')) ?>" class="text-decoration-none text-dark">Lokasyon <?= $srtIk('lokasyon') ?></a></th>
          <?php if ($maliGoster): ?>
          <th data-kol="garanti" data-kol-ad="Garanti"><a href="<?= h($srtUrl('garanti')) ?>" class="text-decoration-none text-dark">Garanti <?= $srtIk('garanti') ?></a></th>
          <th class="text-end" data-kol="fiyat" data-kol-ad="Fiyat"><a href="<?= h($srtUrl('fiyat')) ?>" class="text-decoration-none text-dark">Fiyat <?= $srtIk('fiyat') ?></a></th>
          <?php endif; ?>
          <th class="text-center" data-kol="evrak" data-kol-ad="Evrak" title="İmzalı zimmet tutanağı / belge">Evrak</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$liste): ?>
        <tr><td colspan="<?= $maliGoster ? 13 : 11 ?>" class="text-center text-muted py-4">Kayıt yok.<?php if ($yazabilir && !$etkin): ?> <a href="cihaz_form.php">İlk cihazı ekleyin</a>.<?php endif; ?></td></tr>
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
          <?php /* Envanter no sütunu kaldırıldı (karışıklık yapıyordu); cihaz kartına giriş artık
                    CİHAZ KODU · IFS NO · CİHAZ ADI üzerinden. Kod boşsa hücrede envanter no gösterilir
                    ki satırın her zaman tıklanabilir bir kimliği olsun. */ ?>
          <?php $__kod = trim((string)($r['cihaz_kodu'] ?? '')); ?>
          <td><a href="cihaz_detay.php?id=<?= (int)$r['id'] ?>" class="font-monospace fw-semibold text-decoration-none"
                 title="<?= $__kod === '' ? 'envanter no' : 'cihaz kodu (demirbaş etiketi)' ?>"><?= h($__kod !== '' ? $__kod : $r['envanter_no']) ?></a></td>
          <td class="font-monospace small" style="max-width:190px">
            <?= ($r['varlik_kodu'] ?? '') !== ''
                ? '<a href="cihaz_detay.php?id=' . (int)$r['id'] . '" class="text-decoration-none text-muted" title="IFS seri nesne no">' . h($r['varlik_kodu']) . '</a>'
                : '<span class="text-muted">—</span>' ?></td>
          <td><a href="cihaz_detay.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none"><?= h($r['ad']) ?></a>
              <div class="small text-muted"><?= h(trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? ''))) ?></div></td>
          <td><i class="bi <?= h(it_kategoriIkon($r['kategori'])) ?> me-1 text-muted"></i><?= h(it_kategoriAd($r['kategori'])) ?></td>
          <td class="font-monospace small"><?= h($r['seri_no'] ?: '—') ?></td>
          <td><?= it_durumBadge($r['durum']) ?>
            <?php if ($r['durum'] === 'transfer' && isset($trGun[(int)$r['id']])): ?>
              <div class="small <?= $trGun[(int)$r['id']] > 14 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= (int)$trGun[(int)$r['id']] ?> gündür yolda</div>
            <?php endif; ?></td>
          <td><?= $r['zimmetli'] ? '<i class="bi bi-person me-1 text-muted"></i>' . ($r['personel_id'] ? '<a href="personel_detay.php?id=' . (int)$r['personel_id'] . '" class="text-decoration-none">' . h($r['zimmetli']) . '</a>' : h($r['zimmetli'])) . ($r['departman'] ? '<div class="small text-muted">' . h($r['departman']) . '</div>' : '') : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= h($r['lokasyon_id'] ? it_lokasyon_etiket($pdoIt, (int)$r['lokasyon_id']) : ($r['lokasyon'] ?: '—')) ?></td>
          <?php if ($maliGoster): ?>
          <td>
            <?php if ($gk === null): ?><span class="text-muted">—</span>
            <?php elseif ($gk < 0): ?><span class="badge bg-light text-danger border">bitti</span>
            <?php elseif ($gk <= 60): ?><span class="badge bg-warning text-dark"><?= $gk ?> gün</span>
            <?php else: ?><span class="small"><?= format_date($r['garanti_bitis']) ?></span><?php endif; ?>
          </td>
          <td class="text-end"><?= $r['fiyat'] !== null ? $f2($r['fiyat']) : '—' ?></td>
          <?php endif; ?>
          <td class="text-center">
            <?php
              $bs = $belgeSay[(int)$r['id']] ?? ['toplam'=>0,'imzali'=>0,'zimmet'=>0,'transfer'=>0,'hurda'=>0];
              // Cihazın DURUMUNA uygun tutanak: envanterden düşende hurda/zayi/hibe, yoldakinde sevk,
              // zimmetlide zimmet tutanağı. Başka türde belge olması gerekeni karşılamaz.
              $__gerek = it_durum_dustu($r['durum'])
                  ? ['hurda', 'hurda_tutanak.php?id=' . (int)$r['id'], 'hurda / zayi / hibe tutanağı']
                  : ($r['durum'] === 'transfer'
                      ? ['transfer', 'transfer_tutanak.php?id=' . (int)$r['id'], 'sevk tutanağı']
                      : ($r['zimmetli'] ? ['zimmet', 'zimmet_tutanak.php?id=' . (int)$r['id'], 'zimmet tutanağı'] : null));
            ?>
            <?php if ($__gerek && (int)($bs[$__gerek[0]] ?? 0)): ?>
              <a href="cihaz_detay.php?id=<?= (int)$r['id'] ?>#belgeler" class="badge bg-success text-decoration-none" title="İmzalı <?= h($__gerek[2]) ?> yüklü"><i class="bi bi-file-earmark-check me-1"></i><?= (int)$bs[$__gerek[0]] ?></a>
            <?php elseif ($__gerek): ?>
              <a href="<?= h($__gerek[1]) ?>" target="_blank" class="badge bg-light text-warning border text-decoration-none" title="İmzalı <?= h($__gerek[2]) ?> yok<?= $bs['toplam'] ? ' (' . (int)$bs['toplam'] . ' başka belge var)' : '' ?> — tutanağı aç"><i class="bi bi-exclamation-triangle"></i></a>
            <?php elseif ($bs['imzali']): ?>
              <a href="cihaz_detay.php?id=<?= (int)$r['id'] ?>#belgeler" class="badge bg-success text-decoration-none" title="İmzalı tutanak yüklü"><i class="bi bi-file-earmark-check me-1"></i><?= (int)$bs['imzali'] ?></a>
            <?php elseif ($bs['toplam']): ?>
              <a href="cihaz_detay.php?id=<?= (int)$r['id'] ?>#belgeler" class="badge bg-light text-secondary border text-decoration-none" title="<?= (int)$bs['toplam'] ?> belge — imzalı tutanak yok"><i class="bi bi-paperclip me-1"></i><?= (int)$bs['toplam'] ?></a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <a href="cihaz_detay.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Detay"><i class="bi bi-eye"></i></a>
            <?php if (it_durum_dustu($r['durum'])): ?><a href="hurda_tutanak.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="<?= h(it_durumAd($r['durum'])) ?> tutanağı"><i class="bi bi-file-earmark-x"></i></a>
            <?php elseif ($r['durum'] === 'transfer'): ?><a href="transfer_tutanak.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Transfer tutanağı"><i class="bi bi-arrow-left-right"></i></a>
            <?php elseif ($r['zimmetli']): ?><a href="zimmet_tutanak.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Zimmet tutanağı"><i class="bi bi-file-earmark-text"></i></a><?php endif; ?>
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
<script src="<?= $rootPath ?>assets/js/kolon_sec.js"></script>
<script>
ERN_KOLON.kur({ tablo: '#cihazTablo', menu: '#kolonMenu', anahtar: 'it_cihazlar', dugme: '#kolonRozet' });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
