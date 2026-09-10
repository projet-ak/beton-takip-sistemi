<?php
/**
 * it/personel.php — Kişi (personel) listesi: kime ne zimmetledik
 * Sicil, ad soyad, unvan, birim, lokasyon, telefon, işe giriş / çıkış + üzerindeki cihaz sayısı.
 * ⚠ İşten ayrılmış ama üzerinde zimmet duran kişiler kırmızı — zimmet iade alınmadan çıkış kapatılmaz.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';

require_once __DIR__ . '/_import.php';
it_semasi_kur($pdoIt);
$pageTitle = 'Personel — IT Envanter';

// ── Mükerrer kayıt birleştirme (aynı kişinin iki kartı) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'birlestir') {
    if (!yetki_var('duzenle')) { flash('error', 'Birleştirme için değiştirme yetkisi gerekir.'); redirect('personel.php?mukerrer=1'); }
    try {
        $son = pim_personel_birlestir($pdoIt, (int)($_POST['hedef'] ?? 0), (int)($_POST['kaynak'] ?? 0));
        flash('success', $son['kaynak'] . ' kaydı ' . $son['hedef'] . ' ile birleştirildi' . ($son['tasinan'] ? ' (' . $son['tasinan'] . ' cihaz zimmeti taşındı)' : '') . '.');
    } catch (Throwable $e) { flash('error', 'Birleştirilemedi: ' . $e->getMessage()); }
    redirect('personel.php?mukerrer=1');
}
// ── Listeyi temizle: tüm personeli sil (yeniden yüklemeden önce sıfırlama) ──
// ⚠ Üzerinde ZİMMETLİ CİHAZ olan kişi SİLİNMEZ — silinse cihazın zimmet bağı kopardı.
// Onlar korunur; yeniden yüklemede sicil/e-posta ile eşleşip güncellenirler.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'tumunu_sil') {
    if (!yetki_var('duzenle')) { flash('error', 'Personel silmek için değiştirme yetkisi gerekir.'); redirect('personel.php'); }
    if (trim((string)($_POST['onay'] ?? '')) !== 'SIL') { flash('error', 'Silme iptal edildi: onay kutusuna SIL yazılmadı.'); redirect('personel.php'); }
    $silinen = 0; $korunan = [];
    try {
        $pdoIt->beginTransaction();
        foreach ($pdoIt->query("SELECT * FROM it_personel")->fetchAll() as $kayit) {
            $z = count(it_personel_cihazlari($pdoIt, (int)$kayit['id']));
            if ($z) { $korunan[] = it_personel_ad($kayit) . " ($z cihaz)"; continue; }
            $pdoIt->prepare("DELETE FROM it_personel WHERE id=?")->execute([(int)$kayit['id']]);
            $silinen++;
        }
        if ($pdoIt->inTransaction()) $pdoIt->commit();
        flash($korunan ? 'warning' : 'success', "$silinen personel kaydı silindi."
            . ($korunan ? ' Üzerinde zimmet olan ' . count($korunan) . ' kişi KORUNDU: ' . implode(', ', array_slice($korunan, 0, 10)) . (count($korunan) > 10 ? '…' : '') . ' — cihazları iade alındıktan sonra silinebilir.' : '')
            . ' Yeni listeyi Personel İçe Aktar ekranından yükleyebilirsiniz.');
    } catch (Throwable $e) {
        if ($pdoIt->inTransaction()) $pdoIt->rollBack();
        flash('error', 'Silinemedi: ' . $e->getMessage());
    }
    redirect('personel.php');
}

$mukerrerler = pim_mukerrer_gruplar($pdoIt);
$mukerrerGoster = isset($_GET['mukerrer']);

$durum = $_GET['durum'] ?? 'aktif';               // aktif | ayrilan | hepsi
$lokId = (int)($_GET['lokasyon_id'] ?? 0);
$birim = trim((string)($_GET['birim'] ?? ''));
$q     = trim((string)($_GET['q'] ?? ''));

$w = []; $p = [];
if ($durum === 'aktif')   $w[] = "(p.isten_cikis IS NULL OR p.isten_cikis > CURDATE())";
if ($durum === 'ayrilan') $w[] = "(p.isten_cikis IS NOT NULL AND p.isten_cikis <= CURDATE())";
if ($lokId) { $ids = it_lokasyon_altlar($pdoIt, $lokId); $w[] = "p.lokasyon_id IN (" . implode(',', array_map('intval', $ids)) . ")"; }
if ($birim !== '') { $w[] = "p.birim=?"; $p[] = $birim; }
if ($q !== '') { $w[] = "(p.ad LIKE ? OR p.soyad LIKE ? OR p.sicil_no LIKE ? OR p.unvan LIKE ? OR p.telefon LIKE ? OR p.eposta LIKE ?)"; for ($i = 0; $i < 6; $i++) $p[] = "%$q%"; }
$wsql = $w ? ' WHERE ' . implode(' AND ', $w) : '';

$sirala = ['ad' => 'p.soyad, p.ad', 'sicil' => 'p.sicil_no', 'unvan' => 'p.unvan', 'birim' => 'p.birim', 'giris' => 'p.ise_giris', 'cikis' => 'p.isten_cikis', 'cihaz' => 'cihaz'];
$skA = array_key_exists($_GET['sk'] ?? '', $sirala) ? $_GET['sk'] : 'ad';
$yon = ($_GET['yon'] ?? '') === 'desc' ? 'DESC' : 'ASC';

$sql = "SELECT p.*, (SELECT COUNT(*) FROM it_cihazlar c WHERE c.personel_id=p.id AND " . it_envanterde('c') . ") cihaz,
               (SELECT COALESCE(SUM(c.fiyat),0) FROM it_cihazlar c WHERE c.personel_id=p.id AND " . it_envanterde('c') . ") mali
        FROM it_personel p $wsql ORDER BY {$sirala[$skA]} $yon, p.id";
$st = $pdoIt->prepare($sql); $st->execute($p);
$liste = $st->fetchAll();

if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new \XlsxWriter('Personel');
    $xl->header(['Sicil No','Ad','Soyad','Unvan','Birim','Lokasyon','Telefon','E-posta','İşe Giriş','İşten Çıkış','Durum','Zimmetli Cihaz','Zimmet Değeri (TL)','Notlar']);
    foreach ($liste as $r) $xl->row([
        ['v'=>$r['sicil_no']], ['v'=>$r['ad']], ['v'=>$r['soyad']], ['v'=>$r['unvan']], ['v'=>$r['birim']], ['v'=>it_lokasyon_yol($pdoIt, (int)$r['lokasyon_id'])],
        ['v'=>$r['telefon']], ['v'=>$r['eposta']], ['v'=>$r['ise_giris'],'t'=>'date'], ['v'=>$r['isten_cikis'],'t'=>'date'],
        ['v'=>it_personel_aktif($r) ? 'Çalışıyor' : 'Ayrıldı'], ['v'=>(int)$r['cihaz'],'t'=>'number'], ['v'=>(float)$r['mali'],'t'=>'number'], ['v'=>$r['notlar']],
    ]);
    $xl->download('it_personel_' . date('Ymd_Hi') . '.xlsx');
}

$birimler = [];
try { $birimler = $pdoIt->query("SELECT DISTINCT birim FROM it_personel WHERE birim IS NOT NULL AND birim<>'' ORDER BY birim")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
$toplam = count($liste); $acikZimmet = 0; $riskli = 0;
foreach ($liste as $r) { $acikZimmet += (int)$r['cihaz']; if (!it_personel_aktif($r) && (int)$r['cihaz'] > 0) $riskli++; }
$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
$srtUrl = function (string $k) use ($skA, $yon) { $g = $_GET; $g['sk'] = $k; $g['yon'] = ($skA === $k && $yon === 'ASC') ? 'desc' : 'asc'; return 'personel.php?' . http_build_query($g); };
$srtIk = fn(string $k) => $skA === $k ? '<i class="bi bi-caret-' . ($yon === 'ASC' ? 'up' : 'down') . '-fill small"></i>' : '';
$yazabilir = yetki_var('giris');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-people text-primary me-2"></i>Personel &amp; Zimmet Sahipleri</h4>
    <div class="d-flex gap-2">
        <a href="tanimlar.php?t=lokasyon" class="btn btn-outline-secondary btn-sm"><i class="bi bi-diagram-3 me-1"></i>Lokasyonlar</a>
        <a href="personel.php?<?= h(http_build_query(array_merge($_GET, ['export'=>'xlsx']))) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        <?php if ($yazabilir): ?><a href="import.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>İçe Aktar</a><a href="personel_form.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Yeni Personel</a><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($mukerrerler && !$mukerrerGoster): ?>
<div class="alert alert-warning py-2 d-flex flex-wrap align-items-center gap-2">
  <span><i class="bi bi-people me-1"></i><strong><?= count($mukerrerler) ?> mükerrer kayıt grubu</strong> bulundu — aynı kişi birden çok kez kayıtlı görünüyor (aynı sicil no, e-posta ya da ad soyad).</span>
  <a href="personel.php?mukerrer=1" class="btn btn-warning btn-sm ms-auto"><i class="bi bi-union me-1"></i>İncele ve birleştir</a>
</div>
<?php endif; ?>

<?php if ($mukerrerGoster): ?>
<div class="card border-warning shadow-sm mb-3">
  <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
    <strong><i class="bi bi-union text-warning me-1"></i>Mükerrer Kayıtlar</strong>
    <span class="text-muted small">Birleştirmede <strong>korunan</strong> kayıt kalır; diğerinin cihaz zimmetleri ona taşınır, boş alanları tamamlanır, sonra silinir.</span>
    <a href="personel.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-x-lg me-1"></i>Kapat</a>
  </div>
  <div class="card-body">
    <?php if (!$mukerrerler): ?><div class="text-success mb-0"><i class="bi bi-check-circle me-1"></i>Mükerrer kayıt yok.</div><?php endif; ?>
    <?php foreach ($mukerrerler as $gi => $g): $asil = $g['kayitlar'][0]; ?>
    <div class="border rounded p-2 mb-2">
      <div class="small text-muted mb-1">Eşleşme: <strong><?= h($g['tur']) ?></strong> — <code><?= h($g['anahtar']) ?></code></div>
      <table class="table table-sm mb-0 align-middle" style="font-size:.84rem">
        <thead class="table-light"><tr><th>Kayıt</th><th>Sicil</th><th>Unvan / Birim</th><th>Lokasyon</th><th>Telefon</th><th>E-posta</th><th class="text-end">Cihaz</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($g['kayitlar'] as $ki => $k): ?>
          <tr class="<?= $ki === 0 ? 'table-success' : '' ?>">
            <td><a href="personel_detay.php?id=<?= (int)$k['id'] ?>"><?= h(it_personel_ad($k)) ?></a> <span class="text-muted">#<?= (int)$k['id'] ?></span>
                <?= $ki === 0 ? '<span class="badge bg-success ms-1">korunacak</span>' : '' ?></td>
            <td class="font-monospace"><?= h($k['sicil_no']) ?></td>
            <td><?= h($k['unvan']) ?><?= $k['birim'] ? ' <span class="text-muted">/ ' . h($k['birim']) . '</span>' : '' ?></td>
            <td class="small"><?= h(it_lokasyon_yol($pdoIt, (int)$k['lokasyon_id'])) ?></td>
            <td class="text-nowrap"><?= h($k['telefon']) ?></td><td class="small"><?= h($k['eposta']) ?></td>
            <td class="text-end"><?= (int)$k['cihaz'] ?></td>
            <td class="text-end">
              <?php if ($ki > 0 && yetki_var('duzenle')): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('#<?= (int)$k['id'] ?> kaydı #<?= (int)$asil['id'] ?> ile birleştirilecek. Cihaz zimmetleri taşınacak ve bu kayıt SİLİNECEK. Devam?')">
                <input type="hidden" name="islem" value="birlestir">
                <input type="hidden" name="hedef" value="<?= (int)$asil['id'] ?>">
                <input type="hidden" name="kaynak" value="<?= (int)$k['id'] ?>">
                <button class="btn btn-warning btn-sm"><i class="bi bi-union me-1"></i>Bununla birleştir</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($riskli): ?>
<div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><strong><?= $riskli ?> kişi</strong> işten ayrılmış görünüyor ama üzerinde hâlâ zimmetli cihaz var. Kişi kartından zimmetleri iade alın.</div>
<?php endif; ?>

<form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body py-2"><div class="row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label small mb-0">Ara</label><input name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="ad, soyad, sicil, unvan, telefon"></div>
  <div class="col-md-2"><label class="form-label small mb-0">Durum</label>
    <select name="durum" class="form-select form-select-sm"><option value="aktif" <?= $durum==='aktif'?'selected':'' ?>>Çalışanlar</option><option value="ayrilan" <?= $durum==='ayrilan'?'selected':'' ?>>Ayrılanlar</option><option value="hepsi" <?= $durum==='hepsi'?'selected':'' ?>>Hepsi</option></select></div>
  <div class="col-md-3"><label class="form-label small mb-0">Lokasyon / Proje</label><select name="lokasyon_id" class="form-select form-select-sm"><option value="">Tümü</option><?= it_lokasyon_options($pdoIt, $lokId, false) ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Birim</label><select name="birim" class="form-select form-select-sm"><option value="">Tümü</option><?php foreach ($birimler as $b): ?><option value="<?= h($b) ?>" <?= $birim===$b?'selected':'' ?>><?= h($b) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 d-flex gap-1"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i></button><a href="personel.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a></div>
</div></div></form>

<div class="row g-2 mb-3">
  <div class="col-4"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted">Kişi</div><div class="fs-5 fw-bold"><?= $f0($toplam) ?></div></div></div></div>
  <div class="col-4"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted">Üzerlerindeki cihaz</div><div class="fs-5 fw-bold"><?= $f0($acikZimmet) ?></div></div></div></div>
  <div class="col-4"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted">Ayrılmış + açık zimmet</div><div class="fs-5 fw-bold <?= $riskli ? 'text-danger' : '' ?>"><?= $f0($riskli) ?></div></div></div></div>
</div>

<?php if ($yazabilir && yetki_var('duzenle') && $toplam): ?>
<div class="text-end mb-2">
  <button class="btn btn-outline-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#pnlTemizle"><i class="bi bi-trash me-1"></i>Listeyi Temizle</button>
</div>
<div class="collapse mb-3" id="pnlTemizle">
  <form method="post" class="card border-danger shadow-sm" onsubmit="return confirm('Personel listesi silinecek. Üzerinde zimmetli cihaz olanlar korunur. Devam?')">
    <input type="hidden" name="islem" value="tumunu_sil">
    <div class="card-body">
      <div class="fw-semibold text-danger mb-1"><i class="bi bi-exclamation-octagon me-1"></i>Tüm personel kayıtlarını sil</div>
      <div class="small text-muted mb-2">
        Yeniden yükleme öncesi listeyi sıfırlar. <strong>Üzerinde zimmetli cihaz olan kişiler silinmez</strong> (cihaz bağı kopmasın diye korunur, yeniden yüklemede sicilden eşleşip güncellenir).
        Cihazlar, hareket geçmişi ve tanımlar etkilenmez. <strong>Alternatif:</strong> yeni dosyayı <a href="import.php">Personel İçe Aktar</a> ekranından "TAM YENİLEME (sil ve ekle)" kutusuyla yüklerseniz silme ve ekleme tek adımda olur.
      </div>
      <div class="row g-2 align-items-center">
        <div class="col-md-4"><input name="onay" class="form-control form-control-sm" placeholder="Onaylamak için SIL yazın" autocomplete="off" required></div>
        <div class="col-md-4"><button class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i><?= $f0($toplam) ?> kaydı sil</button></div>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm"><div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0" style="font-size:.86rem">
  <thead class="table-light"><tr>
    <th><a href="<?= h($srtUrl('sicil')) ?>" class="text-decoration-none text-dark">Sicil <?= $srtIk('sicil') ?></a></th>
    <th><a href="<?= h($srtUrl('ad')) ?>" class="text-decoration-none text-dark">Ad Soyad <?= $srtIk('ad') ?></a></th>
    <th><a href="<?= h($srtUrl('unvan')) ?>" class="text-decoration-none text-dark">Unvan <?= $srtIk('unvan') ?></a></th>
    <th><a href="<?= h($srtUrl('birim')) ?>" class="text-decoration-none text-dark">Birim <?= $srtIk('birim') ?></a></th>
    <th>Lokasyon</th><th>Telefon</th>
    <th><a href="<?= h($srtUrl('giris')) ?>" class="text-decoration-none text-dark">İşe Giriş <?= $srtIk('giris') ?></a></th>
    <th><a href="<?= h($srtUrl('cikis')) ?>" class="text-decoration-none text-dark">Çıkış <?= $srtIk('cikis') ?></a></th>
    <th class="text-end"><a href="<?= h($srtUrl('cihaz')) ?>" class="text-decoration-none text-dark">Zimmet <?= $srtIk('cihaz') ?></a></th>
    <th></th></tr></thead>
  <tbody>
  <?php if (!$liste): ?><tr><td colspan="10" class="text-center text-muted py-4">Kayıt yok.<?php if ($yazabilir): ?> <a href="personel_form.php">İlk personeli ekleyin</a>.<?php endif; ?></td></tr><?php endif; ?>
  <?php foreach ($liste as $r): $aktif = it_personel_aktif($r); $risk = !$aktif && (int)$r['cihaz'] > 0; ?>
    <tr class="<?= $risk ? 'table-danger' : (!$aktif ? 'text-muted' : '') ?>">
      <td class="font-monospace small"><?= h($r['sicil_no'] ?: '—') ?></td>
      <td><a href="personel_detay.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none"><?= h(it_personel_ad($r)) ?></a><?= !$aktif ? ' <span class="badge bg-secondary">ayrıldı</span>' : '' ?></td>
      <td><?= h($r['unvan'] ?: '—') ?></td>
      <td><?= h($r['birim'] ?: '—') ?></td>
      <td class="small"><?= h(it_lokasyon_yol($pdoIt, (int)$r['lokasyon_id']) ?: '—') ?></td>
      <td class="small text-nowrap"><?= h($r['telefon'] ?: '—') ?></td>
      <td class="small text-nowrap"><?= $r['ise_giris'] ? format_date($r['ise_giris']) : '—' ?></td>
      <td class="small text-nowrap"><?= $r['isten_cikis'] ? format_date($r['isten_cikis']) : '—' ?></td>
      <td class="text-end"><?php if ((int)$r['cihaz']): ?><span class="badge bg-<?= $risk ? 'danger' : 'primary' ?>"><?= (int)$r['cihaz'] ?> cihaz</span><div class="small text-muted"><?= $f2($r['mali']) ?> TL</div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      <td class="text-end text-nowrap">
        <a href="personel_detay.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Kart"><i class="bi bi-eye"></i></a>
        <?php if ((int)$r['cihaz']): ?><a href="zimmet_tutanak.php?personel_id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Toplu zimmet tutanağı"><i class="bi bi-file-earmark-text"></i></a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
