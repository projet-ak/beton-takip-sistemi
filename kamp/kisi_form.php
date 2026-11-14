<?php
/**
 * kamp/kisi_form.php — kamp kişisi ekle / düzenle
 *  • Ad / soyad / görev BÜYÜK HARF (kamp_buyuk — Türkçe İ doğru).
 *  • Mükerrer engeli: TC no ve sicil no başka kişide olamaz; aynı ad soyad + aynı firma
 *    varsa uyarı verilir ("yine de kaydet" ile geçilir — aynı adlı iki işçi olabilir).
 *  • Yeni kişi kaydedilince isteğe bağlı olarak doğrudan boş bir yatağa yerleştirilir.
 *  • Kişinin firması değişirse AÇIK konaklamasının firması da güncellenir (icmal güncel firmayı görsün).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$k  = $id ? kamp_kisi_bul($pdoKamp, $id) : null;
if ($id && !$k) { flash('error', 'Kişi bulunamadı.'); redirect('kisiler.php'); }
$hata = [];
$v = $k ?: ['ad'=>'','soyad'=>'','tc_no'=>'','sicil_no'=>'','firma_id'=>null,'gorev'=>'','kategori'=>'isci','telefon'=>'','acil_kisi'=>'','acil_tel'=>'','notlar'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['ad','soyad','tc_no','sicil_no','gorev','kategori','telefon','acil_kisi','acil_tel','notlar'] as $f) $v[$f] = trim((string)($_POST[$f] ?? ''));
    $v['firma_id'] = (int)($_POST['firma_id'] ?? 0) ?: null;
    $yeniFirma = trim((string)($_POST['firma_yeni'] ?? ''));
    $v['ad'] = kamp_buyuk($v['ad']); $v['soyad'] = kamp_buyuk($v['soyad']); $v['gorev'] = kamp_buyuk($v['gorev']); $v['acil_kisi'] = kamp_buyuk($v['acil_kisi']);
    $v['tc_no'] = preg_replace('/\D+/', '', $v['tc_no']);
    if (!isset(KAMP_KATEGORI[$v['kategori']])) $v['kategori'] = 'isci';
    if ($v['ad'] === '') $hata[] = 'Ad zorunlu.';
    if ($v['tc_no'] !== '' && strlen($v['tc_no']) !== 11) $hata[] = 'TC kimlik no 11 hane olmalı.';
    if ($v['tc_no'] !== '') { $s = $pdoKamp->prepare("SELECT CONCAT(ad,' ',soyad) FROM kamp_kisiler WHERE tc_no=? AND id<>?"); $s->execute([$v['tc_no'], $id]);
        if ($x = $s->fetchColumn()) $hata[] = "Bu TC no başka bir kişide kayıtlı: " . h($x) . "."; }
    if ($v['sicil_no'] !== '') { $s = $pdoKamp->prepare("SELECT CONCAT(ad,' ',soyad) FROM kamp_kisiler WHERE sicil_no=? AND id<>?"); $s->execute([$v['sicil_no'], $id]);
        if ($x = $s->fetchColumn()) $hata[] = "Bu sicil no başka bir kişide kayıtlı: " . h($x) . "."; }
    if (!$hata && empty($_POST['ayni_ad_onay'])) {
        $nAd = kamp_norm($v['ad'] . ' ' . $v['soyad']);
        foreach ($pdoKamp->query("SELECT id, ad, soyad, firma_id FROM kamp_kisiler") as $r)
            if ((int)$r['id'] !== $id && kamp_norm($r['ad'] . ' ' . $r['soyad']) === $nAd && (int)$r['firma_id'] === (int)$v['firma_id']) {
                $hata[] = 'Aynı ad soyad ve firmayla kayıtlı bir kişi var (<a href="kisi_detay.php?id=' . (int)$r['id'] . '">kartı aç</a>). Farklı kişiyse aşağıdaki "yine de kaydet" kutusunu işaretleyin.';
                $v['_ayni'] = true; break;
            }
    }
    if (!$hata) {
        try {
            if ($yeniFirma !== '' && !$v['firma_id']) $v['firma_id'] = kamp_firma_bul($pdoKamp, $yeniFirma, true);
            $par = [$v['ad'], $v['soyad'], $v['tc_no'] ?: null, $v['sicil_no'] ?: null, $v['firma_id'], $v['gorev'] ?: null, $v['kategori'],
                    $v['telefon'] ?: null, $v['acil_kisi'] ?: null, $v['acil_tel'] ?: null, $v['notlar'] ?: null];
            if ($id) {
                $pdoKamp->prepare("UPDATE kamp_kisiler SET ad=?, soyad=?, tc_no=?, sicil_no=?, firma_id=?, gorev=?, kategori=?, telefon=?, acil_kisi=?, acil_tel=?, notlar=?, updated_at=NOW() WHERE id=?")
                    ->execute([...$par, $id]);
                if ((int)$k['firma_id'] !== (int)$v['firma_id'])
                    $pdoKamp->prepare("UPDATE kamp_konaklama SET firma_id=? WHERE kisi_id=? AND cikis IS NULL")->execute([$v['firma_id'], $id]);
                kamp_audit('kamp_kisiler', $id, 'UPDATE', $k, $v);
                flash('success', 'Kişi güncellendi.');
            } else {
                $pdoKamp->prepare("INSERT INTO kamp_kisiler (ad, soyad, tc_no, sicil_no, firma_id, gorev, kategori, telefon, acil_kisi, acil_tel, notlar) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute($par);
                $id = (int)$pdoKamp->lastInsertId();
                kamp_audit('kamp_kisiler', $id, 'INSERT', null, $v);
                $yatak = (int)($_POST['yatak_id'] ?? 0);
                if ($yatak) {
                    try { $r = kamp_yerlestir($pdoKamp, $id, $yatak, (string)($_POST['giris'] ?? date('Y-m-d')), '', current_user_id());
                          flash('success', 'Kişi eklendi ve yerleştirildi → ' . $r['yer']); }
                    catch (Throwable $e) { flash('warning', 'Kişi eklendi ama yerleştirilemedi: ' . $e->getMessage()); }
                } else flash('success', 'Kişi eklendi.');
            }
            redirect('kisi_detay.php?id=' . $id);
        } catch (Throwable $e) { $hata[] = h($e->getMessage()); }
    }
}

$bos = $id ? [] : kamp_bos_yataklar($pdoKamp);
$pageTitle = ($id ? 'Kişi Düzenle' : 'Yeni Kişi') . ' — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-<?= $id ? 'gear' : 'plus' ?> text-primary me-2"></i><?= $id ? h(kamp_kisi_ad($k)) : 'Yeni Kişi' ?></h4>
  <a href="<?= $id ? 'kisi_detay.php?id=' . $id : 'kisiler.php' ?>" class="btn btn-light btn-sm ms-auto">Geri</a>
</div>
<?php if ($hata): ?><div class="alert alert-danger"><?= implode('<br>', $hata) /* iletiler sabit metin + h() */ ?></div><?php endif; ?>

<form method="post" class="card border-0 shadow-sm"><div class="card-body">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label">Ad *</label><input name="ad" class="form-control" required value="<?= h($v['ad']) ?>" autofocus></div>
    <div class="col-md-4"><label class="form-label">Soyad</label><input name="soyad" class="form-control" value="<?= h($v['soyad']) ?>"></div>
    <div class="col-md-4"><label class="form-label">Kategori</label><select name="kategori" class="form-select">
      <?php foreach (KAMP_KATEGORI as $kk => [$ad]): ?><option value="<?= $kk ?>"<?= $v['kategori'] === $kk ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Firma</label><select name="firma_id" class="form-select" data-ara><?= kamp_firma_options($pdoKamp, $v['firma_id']) ?></select></div>
    <div class="col-md-4"><label class="form-label">… listede yoksa yeni firma</label><input name="firma_yeni" class="form-control" placeholder="firma adı"></div>
    <div class="col-md-4"><label class="form-label">Görev</label><input name="gorev" class="form-control" value="<?= h($v['gorev']) ?>" placeholder="ör. İNŞAAT MÜHENDİSİ, KALIPÇI"></div>
    <div class="col-md-4"><label class="form-label">TC Kimlik No</label><input name="tc_no" class="form-control" inputmode="numeric" maxlength="11" value="<?= h($v['tc_no']) ?>"></div>
    <div class="col-md-4"><label class="form-label">Sicil No</label><input name="sicil_no" class="form-control" value="<?= h($v['sicil_no']) ?>"></div>
    <div class="col-md-4"><label class="form-label">Telefon</label><input name="telefon" class="form-control" value="<?= h($v['telefon']) ?>"></div>
    <div class="col-md-6"><label class="form-label">Acil durumda aranacak kişi</label><input name="acil_kisi" class="form-control" value="<?= h($v['acil_kisi']) ?>"></div>
    <div class="col-md-6"><label class="form-label">Acil durum telefonu</label><input name="acil_tel" class="form-control" value="<?= h($v['acil_tel']) ?>"></div>
    <div class="col-12"><label class="form-label">Notlar</label><textarea name="notlar" rows="2" class="form-control"><?= h($v['notlar']) ?></textarea></div>
    <?php if (!$id && $bos): ?>
    <div class="col-md-8"><label class="form-label">Hemen yerleştir (isteğe bağlı)</label>
      <select name="yatak_id" class="form-select" data-ara><option value="">— şimdi yerleştirme —</option>
      <?php $og = null; foreach ($bos as $y): $g = $y['bina_ad'] . ' · ' . kamp_kat_ad((int)$y['kat']);
        if ($g !== $og) { if ($og !== null) echo '</optgroup>'; echo '<optgroup label="' . h($g) . '">'; $og = $g; } ?>
        <option value="<?= (int)$y['id'] ?>"><?= h($y['oda_ad'] . ' › ' . $y['no']) ?></option>
      <?php endforeach; if ($og !== null) echo '</optgroup>'; ?></select></div>
    <div class="col-md-4"><label class="form-label">Giriş tarihi</label><input type="date" name="giris" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
    <?php endif; ?>
    <?php if (!empty($v['_ayni'])): ?>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="ayni_ad_onay" value="1" id="ayni"><label class="form-check-label" for="ayni">Aynı adlı farklı bir kişi — yine de kaydet</label></div></div>
    <?php endif; ?>
  </div>
</div>
<div class="card-footer bg-white"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Kaydet</button></div></form>
<script src="../assets/js/secici.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
