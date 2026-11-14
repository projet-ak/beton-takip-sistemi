<?php
/**
 * kamp/hareketler.php — GİRİŞ / ÇIKIŞ DEFTERİ + "ŞU TARİHTE KİMLER KALIYORDU"
 *
 *  Defter: seçilen tarih aralığındaki olaylar — kampa giriş, kamptan çıkış, yatak değişikliği
 *  (taşıma: eski kaydın "Yatak değişikliği →" ile kapanıp aynı gün yenisinin açılması; giriş/çıkış
 *  sayılmaz, ayrı tür olarak gösterilir).
 *  Tarihte: ?mod=tarihte&tarih=… → o GECE kampta yatan herkes (giriş ≤ tarih < çıkış).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$mod   = ($_GET['mod'] ?? '') === 'tarihte' ? 'tarihte' : 'defter';
$bas   = kamp_tarih($_GET['bas'] ?? '') ?? date('Y-m-01');
$bit   = kamp_tarih($_GET['bit'] ?? '') ?? date('Y-m-d');
if ($bit < $bas) [$bas, $bit] = [$bit, $bas];
$tarih = kamp_tarih($_GET['tarih'] ?? '') ?? date('Y-m-d');
$tur   = in_array($_GET['tur'] ?? '', ['giris','cikis','tasima'], true) ? $_GET['tur'] : '';
$firma = (int)($_GET['firma'] ?? 0);
$bina  = (int)($_GET['bina'] ?? 0);
$q     = trim((string)($_GET['q'] ?? ''));

$satir = [];
if ($mod === 'defter') {
    $st = $pdoKamp->prepare("SELECT k.*, p.ad, p.soyad, p.kategori, p.gorev, f.ad AS firma_ad, COALESCE(k.firma_id, p.firma_id) AS fid,
                                    y.no AS yatak_no, o.ad AS oda_ad, o.kat, b.ad AS bina_ad, b.id AS bina_id
                               FROM kamp_konaklama k JOIN kamp_kisiler p ON p.id=k.kisi_id
                               LEFT JOIN kamp_firmalar f ON f.id=COALESCE(k.firma_id,p.firma_id)
                               LEFT JOIN kamp_yataklar y ON y.id=k.yatak_id
                               LEFT JOIN kamp_odalar o ON o.id=COALESCE(y.oda_id,k.oda_id)
                               LEFT JOIN kamp_binalar b ON b.id=o.bina_id
                              WHERE (k.giris BETWEEN ? AND ?) OR (k.cikis BETWEEN ? AND ?)");
    $st->execute([$bas, $bit, $bas, $bit]);
    $kayit = $st->fetchAll();
    // Taşıma çiftleri: kişi + gün → eski kayıt "Yatak değişikliği" ile kapandı
    $tasima = [];
    $s2 = $pdoKamp->prepare("SELECT kisi_id, cikis FROM kamp_konaklama WHERE cikis BETWEEN ? AND ? AND cikis_sebep LIKE 'Yatak değişikliği%'");
    $s2->execute([$bas, $bit]);
    foreach ($s2->fetchAll() as $r) $tasima[(int)$r['kisi_id'] . '|' . $r['cikis']] = true;
    foreach ($kayit as $r) {
        if ($r['giris'] >= $bas && $r['giris'] <= $bit)
            $satir[] = $r + ['olay' => isset($tasima[(int)$r['kisi_id'] . '|' . $r['giris']]) ? 'tasima' : 'giris', 'olay_tarih' => $r['giris']];
        if ($r['cikis'] && $r['cikis'] >= $bas && $r['cikis'] <= $bit && !str_starts_with((string)$r['cikis_sebep'], 'Yatak değişikliği'))
            $satir[] = $r + ['olay' => 'cikis', 'olay_tarih' => $r['cikis']];
    }
    usort($satir, fn($a, $b) => strcmp($b['olay_tarih'], $a['olay_tarih']) ?: ($b['id'] <=> $a['id']));
} else {
    foreach (kamp_tarihte($pdoKamp, $tarih) as $r) { $satir[] = $r + ['olay' => 'kaliyor', 'olay_tarih' => $tarih]; }
    $geceZiyaret = kamp_ziyaret_liste($pdoKamp, ['gece' => $tarih]);   // o gecenin penceresinde kampta bulunan ziyaretçiler
}
// süzgeçler
if ($firma) $satir = array_values(array_filter($satir, fn($r) => (int)$r['fid'] === $firma));
if ($bina)  $satir = array_values(array_filter($satir, fn($r) => (int)$r['bina_id'] === $bina));
if ($q !== '') { $kel = array_filter(explode(' ', kamp_norm($q)));
    $satir = array_values(array_filter($satir, function ($r) use ($kel) { $h = kamp_norm($r['ad'] . ' ' . $r['soyad'] . ' ' . $r['firma_ad'] . ' ' . $r['oda_ad'] . ' ' . $r['gorev']);
        foreach ($kel as $k) if (!str_contains($h, $k)) return false; return true; })); }
$sayac = ['giris' => 0, 'cikis' => 0, 'tasima' => 0];
foreach ($satir as $r) if (isset($sayac[$r['olay']])) $sayac[$r['olay']]++;
if ($tur) $satir = array_values(array_filter($satir, fn($r) => $r['olay'] === $tur));

$OLAY = ['giris' => ['Giriş','success','bi-box-arrow-in-right'], 'cikis' => ['Çıkış','danger','bi-box-arrow-right'],
         'tasima' => ['Yatak değişikliği','info','bi-arrow-left-right'], 'kaliyor' => ['Kalıyor','primary','bi-moon']];

if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new \XlsxWriter($mod === 'tarihte' ? 'Kamptakiler ' . $tarih : 'Giris Cikis');
    $xl->header(['Tarih','Olay','Ad Soyad','Firma','Görev','Kategori','Bina','Kat','Oda','Yatak','Giriş','Çıkış','Çıkış Sebebi','Gece']);
    foreach ($satir as $r) $xl->row([['v'=>$r['olay_tarih'],'t'=>'date'], ['v'=>$OLAY[$r['olay']][0]], ['v'=>trim($r['ad'].' '.$r['soyad'])], ['v'=>$r['firma_ad']],
        ['v'=>$r['gorev']], ['v'=>KAMP_KATEGORI[$r['kategori']][0] ?? ''], ['v'=>$r['bina_ad']], ['v'=>$r['kat'] !== null ? kamp_kat_ad((int)$r['kat']) : ''],
        ['v'=>$r['oda_ad']], ['v'=>$r['yatak_no']], ['v'=>$r['giris'],'t'=>'date'], ['v'=>$r['cikis'],'t'=>'date'], ['v'=>$r['cikis_sebep']],
        ['v'=>kamp_sure($r['giris'], $r['cikis']),'t'=>'number']]);
    $xl->download('kamp_' . ($mod === 'tarihte' ? 'kamptakiler_' . $tarih : 'giris_cikis_' . $bas . '_' . $bit) . '.xlsx');
}

$par = fn(array $ek) => 'hareketler.php?' . http_build_query(array_filter(array_merge(
    ['mod'=>$mod === 'tarihte' ? 'tarihte' : null, 'bas'=>$bas, 'bit'=>$bit, 'tarih'=>$mod === 'tarihte' ? $tarih : null, 'tur'=>$tur ?: null,
     'firma'=>$firma ?: null, 'bina'=>$bina ?: null, 'q'=>$q ?: null], $ek), fn($v) => $v !== null && $v !== ''));
$pageTitle = 'Giriş / Çıkış Defteri — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-journal-text text-primary me-2"></i>Giriş / Çıkış Defteri</h4>
  <a href="<?= h($par(['export'=>'xlsx'])) ?>" class="btn btn-outline-success btn-sm ms-auto"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
</div>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $mod === 'defter' ? 'active' : '' ?>" href="hareketler.php"><i class="bi bi-arrow-left-right me-1"></i>Kim geldi, kim çıktı</a></li>
  <li class="nav-item"><a class="nav-link <?= $mod === 'tarihte' ? 'active' : '' ?>" href="hareketler.php?mod=tarihte"><i class="bi bi-calendar-check me-1"></i>Şu tarihte kimler kalıyordu</a></li>
</ul>

<form class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 align-items-end">
  <?php if ($mod === 'tarihte'): ?>
    <input type="hidden" name="mod" value="tarihte">
    <div class="col-md-3"><label class="form-label small mb-0">Gece (tarih)</label><input type="date" name="tarih" class="form-control form-control-sm" value="<?= h($tarih) ?>" max="<?= date('Y-m-d') ?>"></div>
  <?php else: ?>
    <div class="col-md-2"><label class="form-label small mb-0">Başlangıç</label><input type="date" name="bas" class="form-control form-control-sm" value="<?= h($bas) ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Bitiş</label><input type="date" name="bit" class="form-control form-control-sm" value="<?= h($bit) ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Olay</label><select name="tur" class="form-select form-select-sm"><option value="">Tümü</option>
      <?php foreach (['giris','cikis','tasima'] as $k): ?><option value="<?= $k ?>"<?= $tur === $k ? ' selected' : '' ?>><?= $OLAY[$k][0] ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div class="col-md-2"><label class="form-label small mb-0">Firma</label><select name="firma" class="form-select form-select-sm" data-ara><?= kamp_firma_options($pdoKamp, $firma, 'Tümü') ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Bina</label><select name="bina" class="form-select form-select-sm"><option value="">Tümü</option>
    <?php foreach (kamp_binalar($pdoKamp) as $id => $b): ?><option value="<?= $id ?>"<?= $bina === $id ? ' selected' : '' ?>><?= h($b['ad']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-<?= $mod === 'tarihte' ? 3 : 1 ?>"><label class="form-label small mb-0">Ara</label><input name="q" class="form-control form-control-sm" value="<?= h($q) ?>"></div>
  <div class="col-md-1"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button></div>
</div></form>

<?php if ($mod === 'defter'): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach (['giris','cikis','tasima'] as $k): ?>
  <a href="<?= h($par(['tur' => $tur === $k ? null : $k])) ?>" class="btn btn-sm <?= $tur === $k ? 'btn-' . $OLAY[$k][1] : 'btn-outline-' . $OLAY[$k][1] ?>">
    <i class="bi <?= $OLAY[$k][2] ?> me-1"></i><?= $OLAY[$k][0] ?> <span class="badge bg-light text-dark ms-1"><?= $sayac[$k] ?></span></a>
  <?php endforeach; ?>
  <span class="text-muted small align-self-center ms-2"><?= h(kamp_tarih_yaz($bas)) ?> → <?= h(kamp_tarih_yaz($bit)) ?></span>
</div>
<?php else: ?>
<div class="alert alert-primary py-2"><strong><?= count($satir) ?> kişi</strong> <?= h(kamp_tarih_yaz($tarih)) ?> gecesi kampta kalıyordu
  <span class="small text-muted">(giriş ≤ tarih ve çıkış tarihten sonra ya da hâlâ kalıyor)</span>.</div>
<div class="card border-0 shadow-sm mb-3 border-start border-4 <?= array_filter($geceZiyaret, fn($z) => $z['i']['ihlal'] || $z['i']['izinsiz']) ? 'border-danger' : 'border-dark' ?>">
  <div class="card-header bg-white d-flex align-items-center"><strong><i class="bi bi-person-badge me-1"></i>O gece kampta bulunan ziyaretçiler</strong>
    <span class="small text-muted ms-2"><?= h(kamp_tarih_yaz($tarih)) ?> <?= h(kamp_gece_saat()[0]) ?> → ertesi gün <?= h(kamp_gece_saat()[1]) ?></span>
    <a href="ziyaretler.php?gece=<?= h($tarih) ?>" class="ms-auto small">defterde aç →</a></div>
  <?php if (!$geceZiyaret): ?><div class="card-body py-2 small text-muted">Bu gecede ziyaretçi kaydı yok.</div>
  <?php else: ?><div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.85rem"><tbody>
    <?php foreach ($geceZiyaret as $z): ?><tr class="<?= $z['i']['ihlal'] || $z['i']['izinsiz'] ? 'table-danger' : '' ?>">
      <td><strong><?= h($z['ad_soyad']) ?></strong> <?= kamp_ziyaret_rozet($z['i']) ?></td>
      <td class="small"><?= h($z['ziyaret_edilen'] ? $z['ziyaret_edilen'] . ' misafiri' : '—') ?></td>
      <td class="small text-nowrap"><?= h(kamp_zaman_yaz($z['giris'])) ?> → <?= $z['cikis'] ? h(kamp_zaman_yaz($z['cikis'])) : '<span class="badge bg-primary">içeride</span>' ?></td>
      <td class="small"><?= h($z['onaylayan'] ?: '') ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:.86rem">
    <thead class="table-light"><tr><th>Tarih</th><th>Olay</th><th>Ad Soyad</th><th>Firma</th><th>Yer</th><th>Giriş → Çıkış</th><th class="text-end">Gece</th></tr></thead><tbody>
    <?php if (!$satir): ?><tr><td colspan="7" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
    <?php foreach ($satir as $r): $o = $OLAY[$r['olay']]; ?>
      <tr>
        <td class="text-nowrap"><?= h(kamp_tarih_yaz($r['olay_tarih'])) ?></td>
        <td><span class="badge bg-<?= $o[1] ?>"><i class="bi <?= $o[2] ?> me-1"></i><?= $o[0] ?></span></td>
        <td><a href="kisi_detay.php?id=<?= (int)$r['kisi_id'] ?>" class="fw-semibold text-decoration-none"><?= h(trim($r['ad'] . ' ' . $r['soyad'])) ?></a> <?= kamp_katRozet($r['kategori']) ?>
          <?= $r['gorev'] ? '<div class="small text-muted">' . h($r['gorev']) . '</div>' : '' ?></td>
        <td class="small"><?= h($r['firma_ad'] ?: '—') ?></td>
        <td class="small"><?= h($r['bina_ad'] ?? '') ?><div><?= h(($r['kat'] !== null ? kamp_kat_kisa((int)$r['kat']) . ' · ' : '') . ($r['oda_ad'] ?? '') . ' › ' . ($r['yatak_no'] ?? '')) ?></div></td>
        <td class="small text-nowrap"><?= h(kamp_tarih_yaz($r['giris'])) ?> → <?= $r['cikis'] ? h(kamp_tarih_yaz($r['cikis'])) : '<span class="badge bg-success">kalıyor</span>' ?>
          <?= $r['olay'] === 'cikis' && $r['cikis_sebep'] ? '<div class="text-muted">' . h($r['cikis_sebep']) . '</div>' : '' ?></td>
        <td class="text-end"><?= kamp_sure($r['giris'], $r['cikis']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<script src="../assets/js/secici.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
