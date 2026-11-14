<?php
/**
 * kamp/kisiler.php — kamp kişi listesi (modülün KENDİ listesi: kamp_kisiler)
 *  durum: kampta (açık konaklama) · ayrildi (geçmişi var, şu an yok) · kayitsiz (hiç yerleşmemiş) · hepsi
 *  Süzme Türkçe harf duyarsız, kelime sırası serbest (kamp_kisi_suz). Excel: ?export=xlsx
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'sil') {
    try {
        if (!yetki_var('duzenle')) throw new RuntimeException('Silmek için değiştirme yetkisi gerekir.');
        $id = (int)$_POST['id'];
        $c = $pdoKamp->prepare("SELECT COUNT(*) FROM kamp_konaklama WHERE kisi_id=?"); $c->execute([$id]);
        if ($n = (int)$c->fetchColumn()) throw new RuntimeException("Bu kişinin $n konaklama kaydı var — silinmez (kamp geçmişi kopmasın).");
        $k = kamp_kisi_bul($pdoKamp, $id);
        $pdoKamp->prepare("DELETE FROM kamp_kisiler WHERE id=?")->execute([$id]);
        $pdoKamp->prepare("UPDATE kamp_ziyaretler SET kisi_id=NULL WHERE kisi_id=?")->execute([$id]);   // ad kopyası ziyaret kaydında kalır
        kamp_audit('kamp_kisiler', $id, 'DELETE', $k);
        flash('success', 'Kişi silindi.');
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect('kisiler.php');
}

$q      = trim((string)($_GET['q'] ?? ''));
$durum  = in_array($_GET['durum'] ?? '', ['kampta','ayrildi','kayitsiz','hepsi'], true) ? $_GET['durum'] : 'kampta';
$firma  = (int)($_GET['firma'] ?? 0);
$bina   = (int)($_GET['bina'] ?? 0);
$kat    = isset(KAMP_KATEGORI[$_GET['kategori'] ?? '']) ? $_GET['kategori'] : '';

$hepsi = kamp_kisi_liste($pdoKamp);
if ($q !== '') $hepsi = kamp_kisi_suz($hepsi, $q);
if ($firma) $hepsi = array_values(array_filter($hepsi, fn($r) => (int)$r['firma_id'] === $firma));
if ($kat)   $hepsi = array_values(array_filter($hepsi, fn($r) => $r['kategori'] === $kat));
if ($bina)  $hepsi = array_values(array_filter($hepsi, fn($r) => (int)$r['bina_id'] === $bina));
$sayac = ['kampta' => 0, 'ayrildi' => 0, 'kayitsiz' => 0, 'hepsi' => count($hepsi)];
foreach ($hepsi as $r) $sayac[$r['durum']]++;
$liste = $durum === 'hepsi' ? $hepsi : array_values(array_filter($hepsi, fn($r) => $r['durum'] === $durum));

// Sıralama (whitelist)
$sirala = in_array($_GET['s'] ?? '', ['ad','firma','yer','giris','gorev'], true) ? $_GET['s'] : 'ad';
$yon = ($_GET['y'] ?? '') === 'd' ? -1 : 1;
usort($liste, function ($a, $b) use ($sirala, $yon) {
    $v = fn($r) => match ($sirala) {
        'firma' => (string)$r['firma_ad'], 'gorev' => (string)$r['gorev'],
        'yer'   => ($r['bina_ad'] ?? 'ZZZ') . sprintf('%03d', (int)$r['kat'] + 10) . ($r['oda_ad'] ?? '') . ($r['yatak_no'] ?? ''),
        'giris' => (string)($r['acik_giris'] ?? $r['son_cikis'] ?? ''),
        default => kamp_kisi_ad($r),
    };
    return $yon * strnatcasecmp($v($a), $v($b));
});

if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new \XlsxWriter('Kamp Kişileri');
    $xl->header(['Ad','Soyad','TC No','Sicil','Firma','Görev','Kategori','Telefon','Durum','Bina','Kat','Oda','Yatak','Giriş','Gece','Son Çıkış','Acil Durum Kişisi','Acil Tel','Notlar']);
    foreach ($liste as $r) $xl->row([
        ['v'=>$r['ad']], ['v'=>$r['soyad']], ['v'=>$r['tc_no']], ['v'=>$r['sicil_no']], ['v'=>$r['firma_ad']], ['v'=>$r['gorev']],
        ['v'=>KAMP_KATEGORI[$r['kategori']][0] ?? $r['kategori']], ['v'=>$r['telefon']],
        ['v'=>['kampta'=>'Kampta','ayrildi'=>'Ayrıldı','kayitsiz'=>'Yerleşmedi'][$r['durum']]],
        ['v'=>$r['bina_ad']], ['v'=>$r['kat'] !== null ? kamp_kat_ad((int)$r['kat']) : ''], ['v'=>$r['oda_ad']], ['v'=>$r['yatak_no']],
        ['v'=>$r['acik_giris'],'t'=>'date'], ['v'=>$r['acik_giris'] ? kamp_sure($r['acik_giris'], null) : '','t'=>'number'],
        ['v'=>$r['son_cikis'],'t'=>'date'], ['v'=>$r['acil_kisi']], ['v'=>$r['acil_tel']], ['v'=>$r['notlar']],
    ]);
    $xl->download('kamp_kisiler_' . date('Ymd_Hi') . '.xlsx');
}

$pageTitle = 'Kişiler — Kamp Takip';
$qs = fn(array $ek) => '?' . http_build_query(array_filter(array_merge(['q'=>$q,'durum'=>$durum,'firma'=>$firma ?: null,'bina'=>$bina ?: null,'kategori'=>$kat ?: null,'s'=>$sirala,'y'=>$yon < 0 ? 'd' : null], $ek), fn($v) => $v !== null && $v !== ''));
$th = function (string $k, string $ad) use ($sirala, $yon, $qs) {
    $ok = $sirala === $k ? ($yon > 0 ? ' ▲' : ' ▼') : '';
    return '<a class="text-decoration-none text-reset" href="' . htmlspecialchars($qs(['s'=>$k, 'y'=>$sirala === $k && $yon > 0 ? 'd' : null])) . '">' . $ad . $ok . '</a>';
};
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-people text-primary me-2"></i>Kamp Kişileri</h4>
  <div class="ms-auto d-flex gap-2">
    <a href="<?= h($qs(['export'=>'xlsx'])) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <?php if (yetki_var('giris') || yetki_var('duzenle')): ?>
    <?php if (kamp_pts_var()): ?><a href="personel_aktar.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-badge me-1"></i>Personel Takip'ten Al</a><?php endif; ?>
    <a href="import.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Excel'den Aktar</a>
    <a href="kisi_form.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Yeni Kişi</a>
    <?php endif; ?>
  </div>
</div>

<ul class="nav nav-pills mb-3 gap-1">
  <?php foreach (['kampta'=>'Kampta kalan','ayrildi'=>'Ayrılanlar','kayitsiz'=>'Yerleşmemiş','hepsi'=>'Hepsi'] as $k => $ad): ?>
  <li class="nav-item"><a class="nav-link py-1 <?= $durum === $k ? 'active' : '' ?>" href="<?= h($qs(['durum'=>$k])) ?>"><?= $ad ?> <span class="badge <?= $durum === $k ? 'bg-light text-dark' : 'bg-secondary' ?>"><?= $sayac[$k] ?></span></a></li>
  <?php endforeach; ?>
</ul>

<form class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 align-items-end">
  <input type="hidden" name="durum" value="<?= h($durum) ?>">
  <div class="col-md-4"><label class="form-label small mb-0">Ara</label><input name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="ad soyad, TC, firma, oda…"></div>
  <div class="col-md-3"><label class="form-label small mb-0">Firma</label><select name="firma" class="form-select form-select-sm" data-ara><?= kamp_firma_options($pdoKamp, $firma, 'Tümü') ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Bina</label><select name="bina" class="form-select form-select-sm"><option value="">Tümü</option>
    <?php foreach (kamp_binalar($pdoKamp) as $id => $b): ?><option value="<?= $id ?>"<?= $bina === $id ? ' selected' : '' ?>><?= h($b['ad']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Kategori</label><select name="kategori" class="form-select form-select-sm"><option value="">Tümü</option>
    <?php foreach (KAMP_KATEGORI as $k => [$ad]): ?><option value="<?= $k ?>"<?= $kat === $k ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-1 d-flex gap-1"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button><a href="kisiler.php?durum=<?= h($durum) ?>" class="btn btn-light btn-sm" title="Temizle"><i class="bi bi-x"></i></a></div>
</div></form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:.86rem">
    <thead class="table-light"><tr><th>#</th><th><?= $th('ad','Ad Soyad') ?></th><th><?= $th('firma','Firma') ?></th><th><?= $th('gorev','Görev') ?></th><th>Kategori</th>
      <th><?= $th('yer','Kaldığı yer') ?></th><th><?= $th('giris', $durum === 'ayrildi' ? 'Çıkış' : 'Giriş') ?></th><th class="text-end">Gece</th><th>Telefon</th><th></th></tr></thead>
    <tbody>
    <?php if (!$liste): ?><tr><td colspan="10" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
    <?php foreach ($liste as $i => $r): ?>
      <tr>
        <td class="text-muted small"><?= $i + 1 ?></td>
        <td><a href="kisi_detay.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none"><?= h(kamp_kisi_ad($r)) ?></a>
          <?php if ($r['tc_no']): ?><div class="small text-muted">TC <?= h($r['tc_no']) ?></div><?php endif; ?></td>
        <td><?= h($r['firma_ad'] ?: '—') ?></td>
        <td class="small"><?= h($r['gorev'] ?: '') ?></td>
        <td><?= kamp_katRozet($r['kategori']) ?></td>
        <td class="small"><?php if ($r['konaklama_id']): ?><a href="yerlesim.php?bina=<?= (int)$r['bina_id'] ?>" class="text-decoration-none"><?= h($r['bina_ad']) ?></a><div><?= h(kamp_kat_kisa((int)$r['kat']) . ' · ' . $r['oda_ad'] . ' › ' . $r['yatak_no']) ?></div>
            <?php elseif ($r['durum'] === 'ayrildi'): ?><span class="text-muted">ayrıldı</span><?php else: ?><span class="text-warning-emphasis">yerleşmedi</span><?php endif; ?></td>
        <td class="small"><?= $r['konaklama_id'] ? h(kamp_tarih_yaz($r['acik_giris'])) : h(kamp_tarih_yaz($r['son_cikis'])) ?></td>
        <td class="text-end"><?= $r['konaklama_id'] ? kamp_sure($r['acik_giris'], null) : '' ?></td>
        <td class="small text-nowrap"><?= h($r['telefon']) ?></td>
        <td class="text-end text-nowrap">
          <?php if (yetki_var('duzenle')): ?><a href="kisi_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline-secondary btn-sm py-0"><i class="bi bi-pencil"></i></a><?php endif; ?>
          <?php if (yetki_var('duzenle') && !(int)$r['konaklama_sayi']): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Kişi silinsin mi?')"><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-outline-danger btn-sm py-0"><i class="bi bi-trash"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<script src="../assets/js/secici.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
