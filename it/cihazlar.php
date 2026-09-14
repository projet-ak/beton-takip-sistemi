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
require_once __DIR__ . '/_cihaz_import.php';      // cim_cihaz_mukerrer / cim_cihaz_birlestir
$pageTitle = 'Cihazlar — IT Envanter';

// ── Mükerrer cihaz birleştirme ──────────────────────────────────────────────
// Aynı cihaz iki kaynaktan (cihaz kodu / IFS nesne no) ayrı kayıt olarak düşebiliyor;
// korunan karta geçmiş + belgeler taşınır, diğeri silinir.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'birlestir') {
    if (!yetki_var('duzenle')) { flash('error', 'Cihaz birleştirmek için değiştirme yetkisi gerekir.'); redirect('cihazlar.php?mukerrer=1'); }
    try {
        $son = cim_cihaz_birlestir($pdoIt, (int)($_POST['hedef'] ?? 0), (int)($_POST['kaynak'] ?? 0));
        audit_log($pdoIt, 'it_cihazlar', (int)($_POST['hedef'] ?? 0), 'UPDATE', null,
                  ['birlestirilen' => (int)($_POST['kaynak'] ?? 0)] + $son, current_user_id());
        flash('success', '“' . $son['kaynak'] . '” kaydı “' . $son['hedef'] . '” ile birleştirildi — '
            . $son['hareket'] . ' hareket, ' . $son['belge'] . ' belge taşındı'
            . ($son['mukerrer_belge'] ? ', ' . $son['mukerrer_belge'] . ' mükerrer belge atlandı' : '')
            . ($son['bagli'] ? ', ' . $son['bagli'] . ' bağlı cihaz yönlendirildi' : '')
            . ($son['tamamlanan'] ? ', ' . count($son['tamamlanan']) . ' boş alan tamamlandı' : '') . '.');
    } catch (Throwable $e) { flash('error', 'Birleştirilemedi: ' . $e->getMessage()); }
    redirect('cihazlar.php?mukerrer=1');
}
// ── Model numarası demirbaş etiketi alanına yazılmış → cihaz kodunu temizle ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'kod_temizle') {
    if (!yetki_var('duzenle')) { flash('error', 'Bu işlem için değiştirme yetkisi gerekir.'); redirect('cihazlar.php?mukerrer=1'); }
    try {
        $kod = trim((string)($_POST['kod'] ?? ''));
        $son = cim_model_kodu_temizle($pdoIt, $kod);
        audit_log($pdoIt, 'it_cihazlar', 0, 'UPDATE', null, ['model_kodu_temizle' => $kod] + $son, current_user_id());
        flash($son['temizlenen'] ? 'success' : 'info',
            $son['temizlenen'] . ' cihazda “' . h($kod) . '” cihaz kodu temizlendi (model numarasıydı, Model alanında duruyor)'
            . ($son['atlanan'] ? ' — ' . $son['atlanan'] . ' cihazda kod modelle aynı olmadığı için DOKUNULMADI' : '') . '.');
    } catch (Throwable $e) { flash('error', 'Temizlenemedi: ' . $e->getMessage()); }
    redirect('cihazlar.php?mukerrer=1');
}

// ⚠ Çelişkili gruplar (farklı IFS / seri no) MÜKERRER DEĞİLDİR — ayrı cihazlardır.
// Birleştirme listesinden ayrılır; aşağıda "aynı kodu taşıyan farklı cihazlar" olarak gösterilir.
$tumGruplar    = cim_cihaz_mukerrer($pdoIt);
$mukerrerler   = array_values(array_filter($tumGruplar, fn($g) => empty($g['ayri'])));
$ayriCihazlar  = array_values(array_filter($tumGruplar, fn($g) => !empty($g['ayri'])));
$mukerrerGoster = isset($_GET['mukerrer']);

[$wsql, $par, $etkin] = it_filtre($_GET, $pdoIt);
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
// Tamamlanmış sevkler: durum 'depoda'ya döndüğü için ekranda "Depoda / Boşta" yazıyordu ve
// başka projeye gönderilmiş cihaz BİZDE BOŞTA sanılıyordu → "Transfer edilmiştir" rozeti.
$trBitti = it_transfer_edilenler($pdoIt, array_column(array_filter($liste, fn($r) => $r['durum'] !== 'transfer'), 'id'));
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
        <?php if ($mukerrerler || $ayriCihazlar): ?>
        <a href="cihazlar.php?mukerrer=1" class="btn btn-outline-<?= $mukerrerler ? 'warning' : 'secondary' ?> btn-sm"
           title="<?= $mukerrerler ? count($mukerrerler) . ' mükerrer grup' : 'Mükerrer yok' ?><?= $ayriCihazlar ? ' · ' . count($ayriCihazlar) . ' grupta aynı kod farklı cihazlarda (birleştirilmez)' : '' ?>">
            <i class="bi bi-union me-1"></i>Mükerrer
            <?php if ($mukerrerler): ?><span class="badge bg-warning text-dark"><?= count($mukerrerler) ?></span><?php endif; ?>
            <?php if ($ayriCihazlar): ?><span class="badge bg-secondary" title="Aynı kodu taşıyan farklı cihazlar — birleştirme değil kod düzeltmesi"><?= count($ayriCihazlar) ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <a href="cihazlar.php?<?= h(http_build_query(array_merge($_GET, ['export'=>'xlsx']))) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        <?php if ($yazabilir): ?><a href="cihaz_import.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-in-down me-1"></i>İçe Aktar</a>
        <a href="cihaz_form.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Yeni Cihaz</a><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($mukerrerler && !$mukerrerGoster): ?>
<div class="alert alert-warning py-2 d-flex flex-wrap align-items-center gap-2">
  <span><i class="bi bi-union me-1"></i><strong><?= count($mukerrerler) ?> mükerrer cihaz grubu</strong> bulundu —
    aynı cihaz birden çok kez kayıtlı görünüyor (kimlik alanları çelişmiyor).</span>
  <a href="cihazlar.php?mukerrer=1" class="btn btn-warning btn-sm ms-auto"><i class="bi bi-union me-1"></i>İncele ve birleştir</a>
</div>
<?php endif; ?>

<?php if ($mukerrerGoster): ?>
<div class="card border-warning shadow-sm mb-3">
  <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
    <strong><i class="bi bi-union text-warning me-1"></i>Mükerrer Cihazlar</strong>
    <span class="text-muted small">Birleştirmede <strong>korunan</strong> kart kalır; diğerinin
      <strong>yaşam günlüğü ve belgeleri ona taşınır</strong>, boş alanları tamamlanır, sonra silinir.
      Dolu alanlar asla ezilmez; aynı dosya iki kartta da varsa ikinci kopya eklenmez.</span>
    <a href="cihazlar.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-x-lg me-1"></i>Kapat</a>
  </div>
  <div class="card-body">
    <?php if (!$mukerrerler): ?>
      <div class="text-success mb-0"><i class="bi bi-check-circle me-1"></i>Mükerrer cihaz kaydı yok.</div>
    <?php endif; ?>
    <?php foreach ($mukerrerler as $g): $asil = $g['kayitlar'][0]; ?>
    <div class="border rounded p-2 mb-2">
      <div class="small text-muted mb-1">Eşleşme: <strong><?= h($g['tur']) ?></strong> — <code><?= h($g['anahtar']) ?></code></div>
      <?php if (!empty($g['etiket_farki'])): ?>
      <div class="small text-muted mb-2">
        <i class="bi bi-info-circle me-1"></i>Kurum içi etiketler kartlarda farklı (cihaz kimliği aynı, birleştirmeye engel değil —
        korunan kartın dolu değeri kalır):
        <?php foreach ($g['etiket_farki'] as $alan => $degerler): ?>
          <span class="d-block">· <strong><?= h($alan) ?>:</strong> <code><?= h($degerler) ?></code></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle" style="font-size:.84rem">
        <thead class="table-light"><tr>
          <th>Kayıt</th><th>Cihaz Kodu</th><th>IFS Seri Nesne No</th><th>Seri No</th>
          <th>Durum</th><th>Zimmetli</th><th class="text-end">Belge</th><th class="text-end">Hareket</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($g['kayitlar'] as $ki => $c): ?>
          <tr class="<?= $ki === 0 ? 'table-success' : '' ?>">
            <td>
              <a href="cihaz_detay.php?id=<?= (int)$c['id'] ?>"><?= h($c['ad'] ?: 'Cihaz') ?></a>
              <span class="text-muted">#<?= (int)$c['id'] ?></span>
              <?= $ki === 0 ? '<span class="badge bg-success ms-1">korunacak</span>' : '' ?>
              <div class="small text-muted"><?= h(trim(($c['marka'] ?? '') . ' ' . ($c['model'] ?? ''))) ?></div>
            </td>
            <td class="font-monospace"><?= h($c['cihaz_kodu'] ?: $c['envanter_no']) ?></td>
            <td class="font-monospace small"><?= h($c['varlik_kodu']) ?></td>
            <td class="font-monospace small"><?= h($c['seri_no']) ?></td>
            <td><?php $d = IT_DURUM[$c['durum']] ?? null; ?>
                <?php if ($d): ?><span class="badge bg-<?= h($d[1]) ?>"><?= h($d[0]) ?></span><?php endif; ?></td>
            <td class="small"><?= h($c['zimmetli']) ?></td>
            <td class="text-end"><?= (int)$c['belge'] ?></td>
            <td class="text-end"><?= (int)$c['hareket'] ?></td>
            <td class="text-end">
              <?php if ($ki > 0 && yetki_var('duzenle')): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('#<?= (int)$c['id'] ?> kaydı #<?= (int)$asil['id'] ?> ile BİRLEŞTİRİLECEK.\n\nYaşam günlüğü ve belgeleri korunan karta taşınacak, bu kayıt SİLİNECEK. Devam?')">
                <input type="hidden" name="islem" value="birlestir">
                <input type="hidden" name="hedef" value="<?= (int)$asil['id'] ?>">
                <input type="hidden" name="kaynak" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm text-nowrap btn-warning">
                  <i class="bi bi-union me-1"></i>Korunanla birleştir</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="small text-muted mt-1">
        Yanlış kartın korunacağını düşünüyorsanız önce doğru karttaki eksikleri tamamlayın ya da
        <a href="cihaz_detay.php?id=<?= (int)$asil['id'] ?>">korunacak kaydı</a> açıp inceleyin.
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($ayriCihazlar): ?>
<div class="card border-secondary shadow-sm mb-3">
  <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
    <strong><i class="bi bi-exclamation-diamond text-secondary me-1"></i>Aynı kodu taşıyan FARKLI cihazlar</strong>
    <span class="badge bg-secondary"><?= count($ayriCihazlar) ?></span>
    <span class="text-muted small">Bunlar mükerrer DEĞİLDİR: <strong>IFS seri nesne no ve seri no farklıysa cihazlar ayrıdır</strong>
      (aynı modelden onlarca adet olabilir). Birleştirme yapılmaz, <strong>kod düzeltilir</strong>.</span>
  </div>
  <div class="card-body">
    <?php foreach ($ayriCihazlar as $g):
        // Anahtar normalize edilmiştir ("SM T577"); ekranda ve formda cihazın GERÇEK kodunu göster.
        $hamKod = '';
        foreach ($g['kayitlar'] as $c) { $hamKod = trim((string)($c[$g['kolon']] ?? '')); if ($hamKod !== '') break; }
        if ($hamKod === '') $hamKod = $g['anahtar'];
    ?>
    <div class="border rounded p-2 mb-2">
      <div class="small mb-1">
        Ortak <strong><?= h($g['tur']) ?></strong>: <code><?= h($hamKod) ?></code>
        <span class="badge bg-light text-dark border ms-1"><?= count($g['kayitlar']) ?> cihaz</span>
        <?php if (!empty($g['model_kodu'])): ?>
          <span class="badge bg-warning text-dark ms-1"><i class="bi bi-tag me-1"></i>model numarası</span>
        <?php endif; ?>
      </div>
      <div class="small text-muted mb-2">
        Farklı olan kimlik alanları:
        <?php foreach ($g['celiski'] as $alan => $degerler): ?>
          <span class="d-block">· <strong><?= h($alan) ?>:</strong> <code><?= h($degerler) ?></code></span>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($g['model_kodu'])): ?>
      <div class="alert alert-warning py-2 small mb-2">
        <i class="bi bi-info-circle me-1"></i>
        <code><?= h($hamKod) ?></code> bu kayıtlarda <strong>model numarası</strong> olarak görünüyor
        (Model alanı da aynı) — demirbaş etiketi değil. Cihaz kodu alanını boşaltırsanız bu cihazlar
        birbirine karışmaz; kimlikleri IFS seri nesne no ve seri no üzerinden sürer.
        <?php if (yetki_var('duzenle')): ?>
        <form method="post" class="d-inline ms-1"
              onsubmit="return confirm('<?= h($hamKod) ?> kodu, Model alanı da aynı olan cihazlardan TEMİZLENECEK.\n\nBaşka verilere dokunulmaz. Devam?')">
          <input type="hidden" name="islem" value="kod_temizle">
          <input type="hidden" name="kod" value="<?= h($hamKod) ?>">
          <button class="btn btn-warning btn-sm"><i class="bi bi-eraser me-1"></i>Cihaz kodunu temizle</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle" style="font-size:.84rem">
        <thead class="table-light"><tr>
          <th>Kayıt</th><th>Cihaz Kodu</th><th>IFS Seri Nesne No</th><th>Seri No</th>
          <th>Durum</th><th>Zimmetli</th>
        </tr></thead>
        <tbody>
        <?php foreach ($g['kayitlar'] as $c): ?>
          <tr>
            <td>
              <a href="cihaz_detay.php?id=<?= (int)$c['id'] ?>"><?= h($c['ad'] ?: 'Cihaz') ?></a>
              <span class="text-muted">#<?= (int)$c['id'] ?></span>
              <div class="small text-muted"><?= h(trim(($c['marka'] ?? '') . ' ' . ($c['model'] ?? ''))) ?></div>
            </td>
            <td class="font-monospace"><?= h($c['cihaz_kodu'] ?: $c['envanter_no']) ?></td>
            <td class="font-monospace small"><?= h($c['varlik_kodu']) ?></td>
            <td class="font-monospace small"><?= h($c['seri_no']) ?></td>
            <td><?php $d = IT_DURUM[$c['durum']] ?? null; ?>
                <?php if ($d): ?><span class="badge bg-<?= h($d[1]) ?>"><?= h($d[0]) ?></span><?php endif; ?></td>
            <td class="small"><?= h($c['zimmetli']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

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

<?php if (!empty($etkin['dusen_dahil'])): ?>
<div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2 mb-3">
  <i class="bi bi-info-circle"></i>
  <span><strong>Arama sonucuna envanterden düşenler de dâhil</strong> (hurda · kayıp/çalıntı · hibe) —
    aradığınız kayıt girilmemiş sanılmasın diye. Bu satırlar <span class="text-muted">soluk</span> gösterilir;
    yalnız envanterdekileri görmek için durum süzgecinden seçim yapın.</span>
</div>
<?php endif; ?>

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
            <?php elseif (isset($trBitti[(int)$r['id']])): ?>
              <div class="mt-1"><?= it_transfer_rozet($trBitti[(int)$r['id']]) ?></div>
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
