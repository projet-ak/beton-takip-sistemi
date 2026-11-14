<?php
/**
 * kamp/import.php — Excel'den kamp kadrosu aktarma (3 adım: yükle → sütun eşleme ön izlemesi → aktar + rapor)
 * Çekirdek: kamp/_import.php (kim_*). Dosya geçici olarak uploads/kamp/bekleyen/ altında bekler.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_import.php';
kamp_semasi_kur($pdoKamp);
if (!(yetki_var('giris') || yetki_var('duzenle'))) { flash('error', 'Aktarım için veri girişi yetkisi gerekir.'); redirect('kisiler.php'); }

$klasor = __DIR__ . '/../uploads/kamp/bekleyen';
$oturum = $_SESSION['kamp_imp'] ?? null;
$dosyaYol = $oturum && preg_match('/^kim_[a-f0-9]{16}\.(xlsx|xls)$/', (string)$oturum['dosya']) ? $klasor . '/' . $oturum['dosya'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = (string)($_POST['islem'] ?? '');
    try {
        if ($islem === 'yukle') {
            $f = $_FILES['dosya'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Dosya yüklenemedi.');
            $uz = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if (!in_array($uz, ['xlsx', 'xls'], true)) throw new RuntimeException('Yalnız .xlsx / .xls kabul edilir.');
            if (!is_dir($klasor)) @mkdir($klasor, 0775, true);
            foreach (glob($klasor . '/kim_*') ?: [] as $eski) if (filemtime($eski) < time() - 2 * 86400) @unlink($eski);
            $ad = 'kim_' . bin2hex(random_bytes(8)) . '.' . $uz;
            if (!move_uploaded_file($f['tmp_name'], $klasor . '/' . $ad)) throw new RuntimeException('Dosya kaydedilemedi (uploads/kamp/ yazılabilir mi?).');
            kim_excel_ac($klasor . '/' . $ad);   // okunabiliyor mu
            if ($dosyaYol && is_file($dosyaYol)) @unlink($dosyaYol);
            $_SESSION['kamp_imp'] = ['dosya' => $ad, 'ad' => (string)$f['name']];
            redirect('import.php?adim=2');
        }
        if ($islem === 'aktar') {
            if (!$dosyaYol || !is_file($dosyaYol)) throw new RuntimeException('Yüklenen dosya bulunamadı — yeniden yükleyin.');
            $x = kim_excel_ac($dosyaYol);
            $grid = kim_grid($x, (int)($_POST['sayfa'] ?? 0));
            $harita = [];
            foreach ((array)($_POST['map'] ?? []) as $i => $a) if (isset(KIM_ALAN[$a])) $harita[(int)$i] = $a;
            if (!array_intersect($harita, ['ad', 'ad_soyad'])) throw new RuntimeException('Ad (ya da Ad Soyad) sütununu eşleyin.');
            $rap = kim_import($pdoKamp, $grid, (int)($_POST['baslik'] ?? 0), $harita,
                              ['firma_ac' => !empty($_POST['firma_ac']), 'yerlestir' => !empty($_POST['yerlestir'])]);
            $rap['dosya'] = $oturum['ad'];
            kamp_audit('kamp_kisiler', 0, 'INSERT', null, ['excel' => $oturum['ad'], 'yeni' => $rap['yeni'], 'guncellenen' => $rap['guncellenen'], 'yerlesen' => $rap['yerlesen']]);
            @unlink($dosyaYol);
            unset($_SESSION['kamp_imp']);
            $_SESSION['kamp_imp_rapor'] = $rap;
            redirect('import.php?rapor=1');
        }
        if ($islem === 'iptal') { if ($dosyaYol) @unlink($dosyaYol); unset($_SESSION['kamp_imp']); redirect('import.php'); }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect('import.php' . ($dosyaYol ? '?adim=2' : ''));
}

$rapor = isset($_GET['rapor']) ? ($_SESSION['kamp_imp_rapor'] ?? null) : null;
$adim2 = isset($_GET['adim']) && $dosyaYol && is_file($dosyaYol);
$on = null;
if ($adim2) {
    try {
        $x = kim_excel_ac($dosyaYol);
        $sayfalar = $x->sheetNames();
        $sayfa = isset($_GET['sayfa']) ? (int)$_GET['sayfa'] : 0;
        if (!isset($_GET['sayfa'])) {   // en dolu sayfa
            $en = -1; foreach ($sayfalar as $i => $_) { $n = count(kim_grid($x, $i, 3000)); if ($n > $en) { $en = $n; $sayfa = $i; } }
        }
        $grid = kim_grid($x, $sayfa);
        $baslik = isset($_GET['baslik']) ? max(0, min(count($grid) - 1, (int)$_GET['baslik'])) : kim_baslik($grid);
        $bas = $grid[$baslik][1] ?? [];
        $harita = kim_harita($bas);
        $onb = []; $ornek = [];
        foreach (array_slice($grid, $baslik + 1, 12) as [$no, $r]) {
            $v = kim_satir($r, $harita);
            if ($v['ad'] === '' && $v['soyad'] === '') continue;
            [$kisi, $nasil] = kim_kisi_bul($pdoKamp, $v, $onb);
            [$y, $o, $yer, $hata] = kim_yer($pdoKamp, $v, $onb);
            $ornek[] = ['no' => $no, 'v' => $v, 'kisi' => $kisi ? 'mevcut (' . $nasil . ')' : ($nasil === 'cok' ? 'aynı adlı birden çok' : 'yeni'), 'yer' => $hata ? '⚠ ' . $hata : ($yer ?: '—')];
        }
        $on = compact('sayfalar', 'sayfa', 'grid', 'baslik', 'bas', 'harita', 'ornek');
    } catch (Throwable $e) { flash('error', $e->getMessage()); $adim2 = false; }
}

$pageTitle = 'Excel\'den Aktar — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3"><h4 class="mb-0"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Excel'den Kamp Kadrosu Aktar</h4>
  <a href="kisiler.php" class="btn btn-light btn-sm ms-auto">Kişiler</a></div>

<?php if ($rapor): ?>
<div class="card border-0 shadow-sm mb-3 border-start border-4 border-success">
  <div class="card-header bg-white"><strong>Aktarım sonucu — <?= h($rapor['dosya']) ?></strong></div>
  <div class="card-body">
    <div class="d-flex flex-wrap gap-2 mb-2">
      <span class="badge bg-secondary fs-6">Okunan <?= $rapor['okunan'] ?></span>
      <span class="badge bg-success fs-6">Yeni kişi <?= $rapor['yeni'] ?></span>
      <span class="badge bg-info text-dark fs-6">Güncellenen <?= $rapor['guncellenen'] ?></span>
      <span class="badge bg-light text-dark border fs-6">Değişmeyen <?= $rapor['degismeyen'] ?></span>
      <span class="badge bg-warning text-dark fs-6">Atlanan <?= $rapor['atlanan'] ?></span>
    </div>
    <div class="small text-muted mb-2">Sağlama: okunan <?= $rapor['okunan'] ?> = <?= $rapor['yeni'] ?> + <?= $rapor['guncellenen'] ?> + <?= $rapor['degismeyen'] ?> + <?= $rapor['atlanan'] ?>
      <?= $rapor['okunan'] === $rapor['yeni'] + $rapor['guncellenen'] + $rapor['degismeyen'] + $rapor['atlanan'] ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗ tutmuyor</span>' ?></div>
    <div class="d-flex flex-wrap gap-2">
      <span class="badge bg-primary">Yerleştirilen <?= $rapor['yerlesen'] ?></span>
      <span class="badge bg-primary-subtle text-primary-emphasis">Taşınan <?= $rapor['tasinan'] ?></span>
      <span class="badge bg-light text-dark border">Zaten aynı yerde <?= $rapor['yer_ayni'] ?></span>
      <span class="badge bg-light text-dark border">Geçmiş konaklama <?= $rapor['gecmis'] ?></span>
      <?php if ($rapor['firma_acilan']): ?><span class="badge bg-light text-dark border">Açılan firma <?= $rapor['firma_acilan'] ?></span><?php endif; ?>
    </div>
    <?php foreach (['liste_yer' => ['Yerleştirilemeyen satırlar', 'warning'], 'liste_atlanan' => ['Atlanan satırlar', 'secondary']] as $k => [$bas, $renk]): if (!$rapor[$k]) continue; ?>
    <details class="mt-3" open><summary class="fw-semibold text-<?= $renk ?>"><?= $bas ?> (<?= count($rapor[$k]) ?>)</summary>
      <ul class="small mb-0 mt-1"><?php foreach (array_slice($rapor[$k], 0, 300) as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul></details>
    <?php endforeach; ?>
    <div class="mt-3"><a href="yerlesim.php" class="btn btn-primary btn-sm">Krokide gör</a> <a href="kisiler.php?durum=hepsi" class="btn btn-outline-secondary btn-sm">Kişi listesi</a></div>
  </div>
</div>
<?php endif; ?>

<?php if ($adim2 && $on): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2"><strong>2. Sütunları eşle — <?= h($oturum['ad']) ?></strong>
    <form method="post" class="ms-auto"><input type="hidden" name="islem" value="iptal"><button class="btn btn-light btn-sm">Vazgeç</button></form></div>
  <div class="card-body">
    <form class="row g-2 mb-3" method="get"><input type="hidden" name="adim" value="2">
      <div class="col-md-4"><label class="form-label small mb-0">Sayfa</label><select name="sayfa" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($on['sayfalar'] as $i => $s): ?><option value="<?= $i ?>"<?= $i === $on['sayfa'] ? ' selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label small mb-0">Başlık satırı</label><select name="baslik" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach (array_slice($on['grid'], 0, 15) as $i => [$no, $r]): ?><option value="<?= $i ?>"<?= $i === $on['baslik'] ? ' selected' : '' ?>>Satır <?= $no ?>: <?= h(mb_substr(implode(' | ', array_filter(array_map('strval', $r))), 0, 70)) ?></option><?php endforeach; ?></select></div>
    </form>
    <form method="post">
      <input type="hidden" name="islem" value="aktar"><input type="hidden" name="sayfa" value="<?= $on['sayfa'] ?>"><input type="hidden" name="baslik" value="<?= $on['baslik'] ?>">
      <div class="table-responsive mb-3"><table class="table table-sm table-bordered mb-0" style="font-size:.8rem">
        <thead><tr><?php foreach ($on['bas'] as $i => $b): ?><th class="bg-body-tertiary" style="min-width:120px"><div class="small text-muted"><?= h((string)$b) ?></div>
          <select name="map[<?= $i ?>]" class="form-select form-select-sm mt-1"><option value="">— alma —</option>
            <?php foreach (KIM_ALAN as $k => [$ad]): ?><option value="<?= $k ?>"<?= ($on['harita'][$i] ?? '') === $k ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach (array_slice($on['grid'], $on['baslik'] + 1, 5) as [, $r]): ?><tr><?php foreach ($on['bas'] as $i => $_): ?><td><?= h(is_scalar($r[$i] ?? '') ? (string)($r[$i] ?? '') : '') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
      </table></div>
      <div class="d-flex flex-wrap gap-3 mb-3">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="firma_ac" value="1" id="fa" checked><label class="form-check-label" for="fa">Listede olmayan firmaları aç</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="yerlestir" value="1" id="yr" checked><label class="form-check-label" for="yr">Bina / oda / yatak sütunlarına göre yerleştir (çıkış tarihi doluysa geçmiş kayıt olarak yaz)</label></div>
      </div>
      <h6 class="fw-semibold">Ön izleme (ilk satırlar, şu anki eşlemeyle)</h6>
      <div class="table-responsive mb-3"><table class="table table-sm mb-0" style="font-size:.82rem">
        <thead class="table-light"><tr><th>Satır</th><th>Ad Soyad</th><th>Firma</th><th>Kategori</th><th>Kişi</th><th>Yer</th><th>Giriş</th><th>Çıkış</th></tr></thead><tbody>
        <?php foreach ($on['ornek'] as $o): $v = $o['v']; ?><tr><td><?= $o['no'] ?></td><td><?= h(kamp_buyuk($v['ad'] . ' ' . $v['soyad'])) ?></td><td><?= h($v['firma']) ?></td>
          <td><?= kamp_katRozet($v['kategori_k']) ?></td><td><?= h($o['kisi']) ?></td><td class="<?= str_starts_with($o['yer'], '⚠') ? 'text-danger' : '' ?>"><?= h($o['yer']) ?></td>
          <td><?= h(kamp_tarih_yaz($v['giris_t'])) ?></td><td><?= h(kamp_tarih_yaz($v['cikis_t'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <button class="btn btn-primary"><i class="bi bi-check2-all me-1"></i>Aktar</button>
      <div class="form-text">Mevcut kişide yalnız BOŞ alanlar dolar; aynı dosya tekrar yüklenirse mükerrer kişi / konaklama açılmaz.</div>
    </form>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white"><strong>1. Dosya yükle</strong></div><div class="card-body">
  <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end">
    <input type="hidden" name="islem" value="yukle">
    <div><input type="file" name="dosya" accept=".xlsx,.xls" class="form-control" required></div>
    <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Yükle ve ön izle</button>
  </form>
  <div class="small text-muted mt-3">Tanınan sütunlar: <?php foreach (KIM_ALAN as [$ad]): ?><span class="badge bg-light text-dark border me-1"><?= h($ad) ?></span><?php endforeach; ?><br>
    Bina / oda adları sistemdekiyle aynı yazılmalıdır (büyük-küçük harf ve Türkçe karakter farkı önemsizdir: "z-01a" = "Z-01A"). Yatak boşsa odanın ilk boş yatağı verilir.</div>
</div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
