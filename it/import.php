<?php
/**
 * it/import.php — Personel listesi içe aktarma (İK / AD / M365 "export users" dosyaları)
 * 3 adım: (1) dosya yükle → (2) sütun eşleme ön izlemesi (otomatik eşleme, kullanıcı düzeltir) →
 * (3) BİRLEŞTİRME + rapor. Çekirdek `_import.php` (pim_*). Ara veri oturumda tutulur (en fazla 5000 satır).
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
$pageTitle = 'Personel İçe Aktar — IT Envanter';
$kisi = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;

// Örnek şablon
if (isset($_GET['sablon'])) {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new \XlsxWriter('Personel');
    $xl->header(['Sicil No','Ad','Soyad','Unvan','Birim','Lokasyon','Telefon','E-posta','İşe Giriş','İşten Çıkış','Durum','Notlar']);
    $xl->row([['v'=>'1001'],['v'=>'Ahmet'],['v'=>'Yılmaz'],['v'=>'Şantiye Şefi'],['v'=>'Teknik Ofis'],['v'=>'U030'],['v'=>'0532 123 45 67'],['v'=>'ahmet.yilmaz@ernholding.com'],['v'=>'2024-03-01','t'=>'date'],['v'=>''],['v'=>'Aktif'],['v'=>'']]);
    $xl->row([['v'=>'1002'],['v'=>'Ayşe'],['v'=>'Kaya'],['v'=>'Satış Uzmanı'],['v'=>'Satış Ofisi'],['v'=>'ERN Holding İstanbul Merkez Binası'],['v'=>'0533 987 65 43'],['v'=>'ayse.kaya@ernholding.com'],['v'=>'2025-01-15','t'=>'date'],['v'=>''],['v'=>'Aktif'],['v'=>'']]);
    $xl->download('it_personel_sablon.xlsx');
}

if (isset($_GET['iptal'])) { unset($_SESSION['it_pim'], $_SESSION['it_pim_rapor']); redirect('import.php'); }

$hata = null;
$alanSecenek = ['' => '— atla —'] + array_map(fn($t) => $t['etiket'], PIM_ALAN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yetki_var('giris')) { flash('error', 'Bu işlem için yetkiniz yok.'); redirect('personel.php'); }
    $islem = $_POST['islem'] ?? '';

    if ($islem === 'yukle') {
        $f = $_FILES['dosya'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) $hata = 'Dosya seçilmedi.';
        elseif ($f['error'] !== UPLOAD_ERR_OK) $hata = match ((int)$f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya sunucu boyut sınırını aşıyor.',
            default => 'Yükleme hatası (kod ' . (int)$f['error'] . ').',
        };
        else {
            $ad = basename((string)$f['name']);
            if (!preg_match('/\.(xlsx|xls|csv|txt|htm|html)$/i', $ad)) $hata = 'Kabul edilen uzantılar: .xlsx, .xls (web sayfası), .csv, .htm/.html';
            else {
                try {
                    $g = pim_oku($f['tmp_name'], $ad);
                    $satirlar = array_values(array_filter($g['satirlar'], fn($r) => (bool)array_filter($r, fn($c) => trim((string)$c) !== '')));
                    if (count($satirlar) < 2) throw new RuntimeException('Dosyada başlık dışında veri satırı yok.');
                    $bIdx = pim_baslik_satiri($satirlar);
                    $ornek = array_slice($satirlar, $bIdx + 1, 20);
                    $_SESSION['it_pim'] = ['dosya'=>$ad, 'bicim'=>$g['bicim'], 'sayfa'=>$g['sayfa'], 'satirlar'=>$satirlar, 'baslik_idx'=>$bIdx,
                                           'harita'=>pim_harita($satirlar[$bIdx], $ornek), 'opt'=>['bas_harf'=>1, 'pasif_ayrilmis'=>1, 'dosyada_olmayan_ayrilmis'=>0]];
                    unset($_SESSION['it_pim_rapor']);
                    redirect('import.php?adim=2');
                } catch (Throwable $e) { $hata = $e->getMessage(); }
            }
        }
    } elseif (($islem === 'onizle' || $islem === 'aktar') && !empty($_SESSION['it_pim'])) {
        $s = &$_SESSION['it_pim'];
        $bIdx = (int)($_POST['baslik_idx'] ?? $s['baslik_idx']);
        if ($bIdx < 0 || $bIdx >= count($s['satirlar']) - 1) $bIdx = $s['baslik_idx'];
        if ($bIdx !== $s['baslik_idx']) { // başlık satırı değişti → harita yeniden
            $s['baslik_idx'] = $bIdx;
            $s['harita'] = pim_harita($s['satirlar'][$bIdx], array_slice($s['satirlar'], $bIdx + 1, 20));
        } else {
            $h = [];
            foreach ($s['satirlar'][$bIdx] as $i => $_) { $k = (string)($_POST['m'][$i] ?? ''); $h[$i] = array_key_exists($k, $alanSecenek) ? $k : ''; }
            // Aynı alan iki sütuna verildiyse ilk sütun kalır (notlar hariç)
            $kul = [];
            foreach ($h as $i => $k) { if ($k === '' || $k === 'notlar') continue; if (isset($kul[$k])) $h[$i] = ''; else $kul[$k] = $i; }
            $s['harita'] = $h;
        }
        $s['opt'] = ['bas_harf'=>!empty($_POST['bas_harf']) ? 1 : 0, 'pasif_ayrilmis'=>!empty($_POST['pasif_ayrilmis']) ? 1 : 0, 'dosyada_olmayan_ayrilmis'=>!empty($_POST['dosyada_olmayan_ayrilmis']) ? 1 : 0];
        unset($s);
        if ($islem === 'aktar') {
            $s = $_SESSION['it_pim'];
            $var = array_filter($s['harita']);
            if (!in_array('ad_soyad', $var, true) && !(in_array('ad', $var, true) && in_array('soyad', $var, true))) {
                $hata = 'Ad ve Soyad (ya da tek sütun Ad Soyad) eşlenmeden aktarım yapılamaz.';
            } else {
                $rap = null;
                try {
                    $rap = pim_import($pdoIt, $s['satirlar'], $s['opt'] + ['harita'=>$s['harita'], 'baslik_idx'=>$s['baslik_idx'], 'dosya'=>$s['dosya'], 'bicim'=>$s['bicim'], 'kullanici'=>$kisi, 'rapor_tarihi'=>date('Y-m-d')]);
                } catch (Throwable $e) { $hata = 'Aktarım geri alındı: ' . $e->getMessage(); }
                if ($rap !== null) {
                    $_SESSION['it_pim_rapor'] = $rap + ['dosya'=>$s['dosya']];
                    unset($_SESSION['it_pim']);
                    redirect('import.php?rapor=1');
                }
            }
        }
        if (!$hata) redirect('import.php?adim=2');
    }
}

$adim = 1; $s = null; $rap = null;
if (isset($_GET['rapor']) && !empty($_SESSION['it_pim_rapor'])) { $adim = 3; $rap = $_SESSION['it_pim_rapor']; }
elseif (!empty($_SESSION['it_pim'])) { $adim = 2; $s = $_SESSION['it_pim']; }

$gecmis = [];
try { pim_log_kur($pdoIt); $gecmis = $pdoIt->query("SELECT * FROM it_import_log ORDER BY id DESC LIMIT 10")->fetchAll(); } catch (Throwable $e) {}
$yazabilir = yetki_var('giris');
$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Personel Listesi İçe Aktar</h4>
    <div class="d-flex gap-2">
        <a href="personel.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-people me-1"></i>Personel Listesi</a>
        <a href="import.php?sablon=1" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Örnek Şablon</a>
    </div>
</div>

<?php if ($hata): ?><div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= h($hata) ?></div><?php endif; ?>
<?php if (!$yazabilir): ?><div class="alert alert-warning py-2">Bu modülde veri girişi yetkiniz olmadığından dosya yükleyemezsiniz.</div><?php endif; ?>

<ul class="nav nav-pills nav-sm mb-3 small">
  <li class="nav-item"><span class="nav-link <?= $adim===1?'active':'disabled' ?>">1. Dosya</span></li>
  <li class="nav-item"><span class="nav-link <?= $adim===2?'active':'disabled' ?>">2. Sütun eşleme &amp; ön izleme</span></li>
  <li class="nav-item"><span class="nav-link <?= $adim===3?'active':'disabled' ?>">3. Sonuç</span></li>
</ul>

<?php if ($adim === 1): ?>
<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm h-100">
      <input type="hidden" name="islem" value="yukle">
      <div class="card-body">
        <label class="form-label fw-semibold">İK / Active Directory / Microsoft 365 kullanıcı dışa aktarma dosyası</label>
        <input type="file" name="dosya" class="form-control" accept=".xlsx,.xls,.csv,.txt,.htm,.html" required <?= $yazabilir ? '' : 'disabled' ?>>
        <div class="form-text">
          Kabul edilen biçimler: <strong>.xlsx</strong> · <strong>.csv</strong> (; veya , ayraçlı) · Excel <strong>"Web Sayfası" .xls/.htm</strong> (HTML tablo).
          Sütun başlıkları Türkçe ya da İngilizce olabilir (Ad / First Name, Soyad / Last Name, Display Name, Title, Department, Mobile, Email, Office…);
          eşleme bir sonraki adımda gösterilir, dilerseniz düzeltirsiniz.
        </div>
        <div class="alert alert-warning small py-2 mt-3 mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i><strong>"Web Sayfası" (.xls/.htm) dikkat:</strong> Excel bu biçimde iki parça üretir — kısa bir çerçeve dosyası ve
          yanında <code>…_dosyalar/sheet001.htm</code>. Veri <em>sheet001.htm</em> içindedir; yalnız çerçeve yüklenirse sistem uyarır. En sağlıklısı dosyayı Excel'de açıp
          <em>Farklı Kaydet → Excel Çalışma Kitabı (.xlsx)</em> ile kaydedip yüklemektir.
        </div>
      </div>
      <div class="card-footer bg-white"><button class="btn btn-primary" <?= $yazabilir ? '' : 'disabled' ?>><i class="bi bi-upload me-1"></i>Yükle ve Ön İzle</button></div>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100"><div class="card-body small">
      <h6 class="fw-semibold"><i class="bi bi-info-circle me-1 text-primary"></i>Nasıl çalışır?</h6>
      <ul class="mb-2 ps-3">
        <li><strong>Birleştirme</strong>, tam yenileme değil: kişi <em>sicil no → e-posta → ad soyad</em> sırasıyla aranır; bulunursa yalnız dosyada <em>dolu</em> gelen alanlar güncellenir, bulunmazsa yeni personel açılır.</li>
        <li>Dosyadaki boş hücre mevcut veriyi <strong>silmez</strong>; sistemde yazdığınız notlar korunur.</li>
        <li>"Ad Soyad" tek sütunsa son kelime soyad sayılır ("Ayşe Nur Kaya" → Ayşe Nur / Kaya).</li>
        <li>Lokasyon metni <em>proje kodu</em> (U030…) ya da lokasyon adıyla <a href="lokasyonlar.php">lokasyon ağacına</a> bağlanır; eşleşmeyenler raporda listelenir, kişinin notuna yazılır.</li>
        <li>Durum sütunu <em>pasif / disabled</em> ise kişi işten ayrılmış sayılabilir (seçenek). Üzerinde zimmet olan kişi hiçbir koşulda otomatik ayrılmış işaretlenmez.</li>
        <li>Mevcut zimmetler etkilenmez; ad/birim değişirse bağlı cihazların görünen adı güncellenir.</li>
      </ul>
      <div class="text-muted">Excel'den yüklenen ilk liste sonrası günlük iş: değişen personeli aynı dosyayla tekrar yüklemek yeterlidir, mükerrer kayıt oluşmaz.</div>
    </div></div>
  </div>
</div>

<?php elseif ($adim === 2 && $s): $baslik = $s['satirlar'][$s['baslik_idx']]; $veri = array_slice($s['satirlar'], $s['baslik_idx'] + 1); $nSutun = max(array_map('count', array_slice($s['satirlar'], 0, 50)) ?: [count($baslik)]);
      $kullanilan = array_filter($s['harita']); $adTamam = in_array('ad_soyad', $kullanilan, true) || (in_array('ad', $kullanilan, true) && in_array('soyad', $kullanilan, true)); ?>
<form method="post" id="fmEsle">
<input type="hidden" name="islem" id="islem" value="onizle">
<div class="card border-0 shadow-sm mb-3"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-3 small">
  <div><i class="bi bi-file-earmark-text me-1"></i><strong><?= h($s['dosya']) ?></strong> <span class="badge bg-secondary"><?= h(strtoupper($s['bicim'])) ?></span><?php if ($s['sayfa']): ?> sayfa: <em><?= h($s['sayfa']) ?></em><?php endif; ?></div>
  <div><strong><?= $f0(count($veri)) ?></strong> veri satırı · <strong><?= (int)$nSutun ?></strong> sütun</div>
  <div class="d-flex align-items-center gap-1">Başlık satırı:
    <select name="baslik_idx" class="form-select form-select-sm" style="width:auto" onchange="document.getElementById('fmEsle').submit()">
      <?php foreach (array_slice($s['satirlar'], 0, 15, true) as $i => $r): ?><option value="<?= $i ?>" <?= $i===$s['baslik_idx']?'selected':'' ?>>Satır <?= $i+1 ?>: <?= h(mb_substr(implode(' | ', array_filter($r)), 0, 60)) ?></option><?php endforeach; ?>
    </select></div>
  <a href="import.php?iptal=1" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-x-lg me-1"></i>Vazgeç</a>
</div></div>

<?php if (!$adTamam): ?><div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>Ad ve Soyad (ya da tek sütun "Ad Soyad") sütunları bulunamadı — aşağıda elle eşleyin.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold small"><i class="bi bi-arrow-left-right me-1"></i>Sütun eşleme <span class="text-muted fw-normal">(otomatik önerildi — değiştirip "Ön izlemeyi yenile")</span></div>
    <div class="table-responsive" style="max-height:520px"><table class="table table-sm align-middle mb-0" style="font-size:.82rem">
      <thead class="table-light"><tr><th>Dosya sütunu</th><th>Örnek</th><th style="width:190px">Sistem alanı</th></tr></thead><tbody>
      <?php for ($i = 0; $i < $nSutun; $i++): $ornek = []; foreach (array_slice($veri, 0, 30) as $r) { if (trim((string)($r[$i] ?? '')) !== '') { $ornek[] = $r[$i]; if (count($ornek) >= 2) break; } } $sec = $s['harita'][$i] ?? ''; ?>
      <tr class="<?= $sec === '' ? 'text-muted' : '' ?>">
        <td class="fw-semibold"><?= h((string)($baslik[$i] ?? '')) ?: '<em class="fw-normal">(başlıksız ' . ($i+1) . ')</em>' ?></td>
        <td class="text-truncate" style="max-width:150px" title="<?= h(implode(' | ', $ornek)) ?>"><?= h(mb_substr(implode(' | ', $ornek), 0, 40)) ?></td>
        <td><select name="m[<?= $i ?>]" class="form-select form-select-sm <?= $sec ? 'border-success' : '' ?>"><?php foreach ($alanSecenek as $k => $et): ?><option value="<?= h($k) ?>" <?= $sec===$k?'selected':'' ?>><?= h($et) ?></option><?php endforeach; ?></select></td>
      </tr>
      <?php endfor; ?>
      </tbody></table></div></div>
  </div>
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
      <div class="fw-semibold mb-2"><i class="bi bi-sliders me-1"></i>Seçenekler</div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="bas_harf" id="bas_harf" value="1" <?= !empty($s['opt']['bas_harf'])?'checked':'' ?>><label class="form-check-label" for="bas_harf">BÜYÜK HARFLİ ad / soyad / unvan / birimi baş harfi büyük yaz (AHMET YILMAZ → Ahmet Yılmaz)</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="pasif_ayrilmis" id="pasif_ayrilmis" value="1" <?= !empty($s['opt']['pasif_ayrilmis'])?'checked':'' ?>><label class="form-check-label" for="pasif_ayrilmis">Durum sütunu <em>pasif / disabled</em> olan kişileri işten ayrılmış say (çıkış tarihi bugün; çıkış tarihi sütunu doluysa o esas)</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="dosyada_olmayan_ayrilmis" id="dosyada_olmayan_ayrilmis" value="1" <?= !empty($s['opt']['dosyada_olmayan_ayrilmis'])?'checked':'' ?>><label class="form-check-label" for="dosyada_olmayan_ayrilmis"><strong>Dosyada olmayan</strong> çalışanları işten ayrılmış say <span class="text-danger">(yalnız dosya TÜM personeli içeriyorsa işaretleyin; üzerinde zimmet olanlar atlanır)</span></label></div>
    </div></div>

    <?php $onizle = []; foreach (array_slice($veri, 0, 12) as $r) $onizle[] = pim_satir_cozumle($r, $s['harita'], $baslik, $s['opt']); ?>
    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold small"><i class="bi bi-eye me-1"></i>Ön izleme — sistem böyle okuyacak (ilk <?= count($onizle) ?> satır)</div>
    <div class="table-responsive"><table class="table table-sm table-striped mb-0" style="font-size:.8rem">
      <thead class="table-light"><tr><th>Sicil</th><th>Ad</th><th>Soyad</th><th>Unvan</th><th>Birim</th><th>Lokasyon</th><th>Telefon</th><th>E-posta</th><th>Giriş</th><th>Çıkış</th><th>Durum</th></tr></thead><tbody>
      <?php foreach ($onizle as $v): $lokId = $v['lokasyon'] !== '' ? pim_lokasyon_bul($pdoIt, $v['lokasyon']) : null; ?>
      <tr class="<?= $v['ad']==='' || $v['soyad']==='' ? 'table-danger' : '' ?>">
        <td><?= h($v['sicil_no']) ?></td><td><?= h($v['ad']) ?></td><td><?= h($v['soyad']) ?></td><td><?= h($v['unvan']) ?></td><td><?= h($v['birim']) ?></td>
        <td><?php if ($v['lokasyon'] !== ''): ?><?= $lokId ? '<span class="text-success">' . h(it_lokasyon_yol($pdoIt, $lokId)) . '</span>' : '<span class="text-warning" title="lokasyon ağacında bulunamadı">' . h($v['lokasyon']) . ' <i class="bi bi-question-circle"></i></span>' ?><?php endif; ?></td>
        <td class="text-nowrap"><?= h($v['telefon']) ?></td><td><?= h($v['eposta']) ?></td>
        <td class="text-nowrap"><?= $v['ise_giris'] ? h(date('d.m.Y', strtotime($v['ise_giris']))) : (!pim_tarih_bos($v['ise_giris_ham']) ? '<span class="text-danger" title="okunamadı">' . h($v['ise_giris_ham']) . '</span>' : '') ?></td>
        <td class="text-nowrap"><?= $v['isten_cikis'] ? h(date('d.m.Y', strtotime($v['isten_cikis']))) : (!pim_tarih_bos($v['isten_cikis_ham']) ? '<span class="text-danger" title="okunamadı">' . h($v['isten_cikis_ham']) . '</span>' : '') ?></td>
        <td><?= $v['durum'] === false ? '<span class="badge bg-secondary">pasif</span>' : ($v['durum'] === true ? '<span class="badge bg-success">aktif</span>' : ($v['durum_ham'] !== '' ? '<span class="text-muted">' . h($v['durum_ham']) . '</span>' : '')) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <div class="card-footer bg-white d-flex flex-wrap gap-2">
      <button class="btn btn-outline-primary" onclick="document.getElementById('islem').value='onizle'"><i class="bi bi-arrow-repeat me-1"></i>Ön izlemeyi yenile</button>
      <button class="btn btn-success" onclick="document.getElementById('islem').value='aktar'; return confirm('<?= $f0(count($veri)) ?> satır personel listesiyle birleştirilecek. Devam?')" <?= $adTamam ? '' : 'disabled' ?>><i class="bi bi-check2-circle me-1"></i>İçe Aktar (<?= $f0(count($veri)) ?> satır)</button>
    </div></div>
  </div>
</div>
</form>

<?php elseif ($adim === 3 && $rap): $nY = count($rap['yeni']); $nG = count($rap['guncellenen']); $nA = count($rap['atlanan']); $nAy = count(array_filter($rap['ayrilan'], fn($a) => $a['ok'])); ?>
<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><strong><?= h($rap['dosya']) ?></strong> aktarıldı:
  okunan <?= $f0($rap['okunan']) ?> = yeni <?= $nY ?> + güncellenen <?= $nG ?> + değişmeyen <?= $rap['degismeyen'] ?> + atlanan <?= $nA ?>
  <?php if ($rap['okunan'] !== $nY + $nG + $rap['degismeyen'] + $nA): ?><span class="text-danger ms-2">(hesap tutmuyor!)</span><?php endif; ?>
</div>
<div class="row g-2 mb-3">
  <?php foreach ([['Okunan', $rap['okunan'], ''], ['Yeni personel', $nY, 'text-success'], ['Güncellenen', $nG, 'text-primary'], ['Değişmeyen', $rap['degismeyen'], ''], ['Atlanan', $nA, $nA ? 'text-danger' : ''], ['Ayrılmış işaretlenen', $nAy, $nAy ? 'text-warning' : '']] as [$et, $n, $cls]): ?>
  <div class="col-6 col-md-2"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted"><?= $et ?></div><div class="fs-5 fw-bold <?= $cls ?>"><?= $f0($n) ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<?php if ($rap['lokasyon_yok']): ?>
<div class="alert alert-warning py-2 small"><i class="bi bi-geo-alt me-1"></i><strong>Lokasyon ağacında bulunamayan <?= count($rap['lokasyon_yok']) ?> ad</strong> (kişinin notuna yazıldı; <a href="lokasyonlar.php">Lokasyonlar</a> ekranında ekleyip kişileri düzenleyebilirsiniz):
  <?php foreach ($rap['lokasyon_yok'] as $ad => $n): ?><span class="badge bg-warning text-dark ms-1"><?= h($ad) ?> ×<?= $n ?></span><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($rap['atlanan']): ?>
<details class="card border-0 shadow-sm mb-3" open><summary class="card-header bg-white fw-semibold small text-danger">Atlanan satırlar (<?= $nA ?>)</summary>
<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.82rem"><thead class="table-light"><tr><th>Excel satırı</th><th>Kim</th><th>Sebep</th></tr></thead><tbody>
<?php foreach ($rap['atlanan'] as $a): ?><tr><td><?= (int)$a['satir'] ?></td><td><?= h($a['kim']) ?></td><td><?= h($a['neden']) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
<?php endif; ?>

<?php if ($rap['yeni']): ?>
<details class="card border-0 shadow-sm mb-3" <?= $nY <= 30 ? 'open' : '' ?>><summary class="card-header bg-white fw-semibold small text-success">Yeni açılan personel (<?= $nY ?>)</summary>
<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.82rem"><thead class="table-light"><tr><th>Sicil</th><th>Ad Soyad</th><th>Unvan</th><th>Birim</th><th>Lokasyon</th><th></th></tr></thead><tbody>
<?php foreach ($rap['yeni'] as $y): ?><tr><td><?= h($y['sicil']) ?></td><td><a href="personel_detay.php?id=<?= (int)$y['id'] ?>"><?= h($y['kim']) ?></a></td><td><?= h($y['unvan']) ?></td><td><?= h($y['birim']) ?></td><td><?= h($y['lok']) ?></td><td><?= $y['ayrildi'] ? '<span class="badge bg-secondary">ayrılmış</span>' : '' ?></td></tr><?php endforeach; ?></tbody></table></div></details>
<?php endif; ?>

<?php if ($rap['guncellenen']): ?>
<details class="card border-0 shadow-sm mb-3" <?= $nG <= 30 ? 'open' : '' ?>><summary class="card-header bg-white fw-semibold small text-primary">Güncellenen personel (<?= $nG ?>)</summary>
<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.82rem"><thead class="table-light"><tr><th>Kim</th><th>Eşleşme</th><th>Değişen alanlar</th></tr></thead><tbody>
<?php foreach ($rap['guncellenen'] as $g): ?><tr><td><a href="personel_detay.php?id=<?= (int)$g['id'] ?>"><?= h($g['kim']) ?></a></td><td><span class="badge bg-light text-dark border"><?= h($g['nasil']) ?></span></td><td><?= h(implode(' · ', $g['degisen'])) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
<?php endif; ?>

<?php if ($rap['ayrilan']): ?>
<details class="card border-0 shadow-sm mb-3" open><summary class="card-header bg-white fw-semibold small text-warning">Dosyada olmayan çalışanlar (<?= count($rap['ayrilan']) ?>)</summary>
<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.82rem"><thead class="table-light"><tr><th>Kim</th><th>Sonuç</th></tr></thead><tbody>
<?php foreach ($rap['ayrilan'] as $a): ?><tr class="<?= $a['ok'] ? '' : 'table-danger' ?>"><td><a href="personel_detay.php?id=<?= (int)$a['id'] ?>"><?= h($a['kim']) ?></a></td><td><?= $a['ok'] ? '<i class="bi bi-check text-success"></i> ayrılmış işaretlendi, ' : '<i class="bi bi-x text-danger"></i> ATLANDI: ' ?><?= h($a['not']) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
<?php endif; ?>

<div class="d-flex gap-2"><a href="personel.php" class="btn btn-primary"><i class="bi bi-people me-1"></i>Personel listesine git</a><a href="import.php?iptal=1" class="btn btn-outline-secondary"><i class="bi bi-cloud-arrow-up me-1"></i>Yeni dosya yükle</a></div>
<?php endif; ?>

<?php if ($gecmis && $adim !== 2): ?>
<div class="card border-0 shadow-sm mt-4"><div class="card-header bg-white fw-semibold small"><i class="bi bi-clock-history me-1"></i>Son yüklemeler</div>
<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.82rem"><thead class="table-light"><tr><th>Tarih</th><th>Dosya</th><th class="text-end">Okunan</th><th class="text-end">Yeni</th><th class="text-end">Güncellenen</th><th class="text-end">Atlanan</th><th class="text-end">Ayrılan</th><th>Kullanıcı</th></tr></thead><tbody>
<?php foreach ($gecmis as $g): ?><tr><td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime($g['created_at']))) ?></td><td><?= h($g['dosya']) ?> <span class="badge bg-light text-dark border"><?= h($g['bicim']) ?></span></td><td class="text-end"><?= (int)$g['okunan'] ?></td><td class="text-end"><?= (int)$g['yeni'] ?></td><td class="text-end"><?= (int)$g['guncellenen'] ?></td><td class="text-end"><?= (int)$g['atlanan'] ?></td><td class="text-end"><?= (int)$g['ayrilan'] ?></td><td><?= h($g['kullanici']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
