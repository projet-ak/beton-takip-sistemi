<?php
/**
 * it/personel_form.php — Personel ekle / düzenle
 * İşten çıkış tarihi girilirken üzerinde zimmet varsa kaydedilmez (önce iade — personel_detay "Tümünü iade al").
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
it_semasi_kur($pdoIt);

/* ── "Listede yoksa oluştur" ucu (Unvan / Birim) ───────────────────────────────
   Cihaz formundaki Marka ekleyicisiyle AYNI desen: seçenek listesi `it_tanimlar`'dan gelir,
   kullanıcı Tanımlar ekranına gidip geri dönmeden yeni unvan/birim açabilsin diye burada.
   Mükerrer engeli `it_tanim_ekle` içinde `it_norm` ile ("Saha Mühendisi" = "SAHA MÜHENDİSİ").
   ⚠ POST'tur: CSRF'i `auth.php` merkezî doğrular, istemci token'ı gövdede gönderir. */
if (($_POST['islem'] ?? '') === 'tanim_ekle') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!yetki_var('duzenle') && !yetki_var('giris')) throw new RuntimeException('Tanım ekleme yetkiniz yok.');
        $tur = (string)($_POST['tur'] ?? '');
        if (!in_array($tur, ['unvan', 'birim'], true)) throw new RuntimeException('Bu alan için tanım eklenemez.');
        $r = it_tanim_ekle($pdoIt, $tur, (string)($_POST['ad'] ?? ''));
        audit_log($pdoIt, 'it_tanimlar', 0, 'INSERT', null, ['tur' => $tur, 'ad' => $r['ad']], current_user_id());
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'hata' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ?popup=1 → ayrı pencerede açılan hızlı kayıt (cihaz kartındaki "Yeni personel"):
// kaydedince listeye dönmek yerine açan sayfaya kişiyi bildirip kapanır.
$popup = !empty($_GET['popup']) || !empty($_POST['popup']);
$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
$p  = it_personel_bul($pdoIt, $id);
if ($id && !$p) { flash('error', 'Personel bulunamadı.'); redirect('personel.php'); }
$duzenleme = (bool)$p;
if ($duzenleme ? !yetki_var('duzenle') : !yetki_var('giris')) {
    flash('error', 'Bu işlem için yetkiniz yok.'); redirect($duzenleme ? 'personel_detay.php?id=' . $id : 'personel.php');
}

$error = '';
$v = $p ?: ['sicil_no'=>'','ad'=>'','soyad'=>'','unvan'=>'','birim'=>'','lokasyon_id'=>'',
            'dahili'=>'','telefon'=>'','telefon_sahsi'=>'','eposta'=>'','eposta_sahsi'=>'',
            'ise_giris'=>'','isten_cikis'=>'','notlar'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $al = fn($k, $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $y = [
        'sicil_no'    => $al('sicil_no', 30),
        // Ad / soyad / unvan / birim TEK BİÇİM: Türkçe kurallarına göre BÜYÜK HARF
        // (kaynak dosyalar karışık geliyor; liste, tutanak ve Excel tek düzen istendi)
        'ad'          => it_buyuk($al('ad', 80))    ?: null,
        'soyad'       => it_buyuk($al('soyad', 80)) ?: null,
        'unvan'       => it_buyuk($al('unvan', 100)) ?: null,
        'birim'       => it_buyuk($al('birim', 100)) ?: null,
        'lokasyon_id' => (int)($_POST['lokasyon_id'] ?? 0) ?: null,
        // İletişim ÜÇ AYRI alandır: masa telefonunun kısa kodu (dahili) · şirket hattı · şahsi numara.
        // ⚠ `telefon`/`eposta` ŞİRKET bilgisidir — eski kayıtlar orada durur, anlamı değişmedi.
        'dahili'        => it_dahili($_POST['dahili'] ?? ''),
        'telefon'       => it_telefon($_POST['telefon'] ?? '') ?: null,
        'telefon_sahsi' => it_telefon($_POST['telefon_sahsi'] ?? '') ?: null,
        'eposta'        => mb_strtolower((string)$al('eposta', 120) ?? '', 'UTF-8') ?: null,
        'eposta_sahsi'  => mb_strtolower((string)$al('eposta_sahsi', 120) ?? '', 'UTF-8') ?: null,
        'ise_giris'   => it_tarih($_POST['ise_giris'] ?? ''),
        'isten_cikis' => it_tarih($_POST['isten_cikis'] ?? ''),
        'notlar'      => trim((string)($_POST['notlar'] ?? '')) ?: null,
    ];
    if ($y['lokasyon_id'] && !isset(it_lokasyonlar($pdoIt)[$y['lokasyon_id']])) $y['lokasyon_id'] = null;
    // Birim boşsa lokasyonun birim türündeki adı birim sayılır (Merkez binada direktörlük = birim)
    if (!$y['birim'] && $y['lokasyon_id']) { $l = it_lokasyonlar($pdoIt)[$y['lokasyon_id']]; if ($l['tur'] === 'birim') $y['birim'] = it_buyuk($l['ad']); }

    // Dahili yazılmış ama rakama dökülünce 3-6 hane çıkmıyorsa sessizce yutma — kullanıcı
    // "4 hane" bekliyor, yanlış değer kaydedilirse santralde kimse bulunamaz.
    $dahiliHam = trim((string)($_POST['dahili'] ?? ''));
    $epostaHata = '';
    foreach (['eposta' => 'Şirket e-postası', 'eposta_sahsi' => 'Şahsi e-posta'] as $k => $et)
        if ($y[$k] && !filter_var($y[$k], FILTER_VALIDATE_EMAIL)) $epostaHata = "$et geçerli görünmüyor: " . $y[$k];

    if (!$y['ad'] || !$y['soyad']) {
        $error = 'Ad ve soyad zorunludur.';
    } elseif ($dahiliHam !== '' && $y['dahili'] === null) {
        $error = 'Dahili yalnız rakamlardan oluşmalı ve 3–6 hane olmalı (şirkette 4 hanelidir).';
    } elseif ($epostaHata) {
        $error = $epostaHata;
    } elseif ($y['sicil_no'] && ($dup = (function () use ($pdoIt, $y, $id) {
            $st = $pdoIt->prepare("SELECT id FROM it_personel WHERE sicil_no=? AND id<>?"); $st->execute([$y['sicil_no'], $id]); return $st->fetchColumn(); })())) {
        $error = 'Bu sicil numarası başka bir personelde kayıtlı (#' . (int)$dup . ').';
    } elseif ($y['isten_cikis'] && $y['isten_cikis'] <= date('Y-m-d') && $duzenleme && ($acik = count(it_personel_cihazlari($pdoIt, $id)))) {
        $error = "İşten çıkış kaydedilemedi: kişinin üzerinde $acik zimmetli cihaz var. Önce personel kartından \"Tümünü iade al\" ile zimmetleri kapatın.";
    } else {
        $yol = it_lokasyon_yol($pdoIt, $y['lokasyon_id']);
        if ($duzenleme) {
            $pdoIt->prepare("UPDATE it_personel SET " . implode(', ', array_map(fn($k) => "$k=?", array_keys($y))) . " WHERE id=?")->execute([...array_values($y), $id]);
            // Üzerindeki cihazların görünen adı/lokasyonu güncel kalsın (ad değişimi, birim değişimi)
            $pdoIt->prepare("UPDATE it_cihazlar SET zimmetli=?, departman=COALESCE(NULLIF(?,''),departman) WHERE personel_id=?")
                  ->execute([trim($y['ad'] . ' ' . $y['soyad']), $y['birim'] ?? '', $id]);
            flash('success', 'Personel güncellendi.');
        } else {
            $pdoIt->prepare("INSERT INTO it_personel (" . implode(',', array_keys($y)) . ") VALUES (" . implode(',', array_fill(0, count($y), '?')) . ")")->execute(array_values($y));
            $id = (int)$pdoIt->lastInsertId();
            flash('success', 'Personel kaydedildi.');
        }
        if ($popup) {
            // Açan pencereye yeni kişiyi bildir (postMessage) ve kapan
            $__p = it_personel_bul($pdoIt, $id);
            $__v = ['id' => $id, 'ad' => it_personel_ad($__p), 'sicil' => (string)($__p['sicil_no'] ?? ''),
                    'unvan' => (string)($__p['unvan'] ?? ''), 'birim' => (string)($__p['birim'] ?? ''),
                    'lok_id' => (int)($__p['lokasyon_id'] ?? 0),
                    'lok' => $__p['lokasyon_id'] ? it_lokasyon_etiket($pdoIt, (int)$__p['lokasyon_id']) : '', 'cihaz' => 0];
            get_flash('success');   // ana sayfada tekrar görünmesin
            echo '<!doctype html><meta charset="utf-8"><title>Kaydedildi</title>'
               . '<body style="font:15px system-ui;padding:24px">Personel kaydedildi, pencere kapanıyor…'
               . '<script>try{window.opener&&window.opener.postMessage({tip:"it_personel",kisi:'
               . json_encode($__v, JSON_UNESCAPED_UNICODE) . '},"*");}catch(e){}window.close();</script>';
            exit;
        }
        redirect('personel_detay.php?id=' . $id);
    }
    $v = array_merge($v, $y);
}

$pageTitle = ($duzenleme ? 'Personel Düzenle' : 'Yeni Personel') . ' — IT Envanter';
require_once __DIR__ . '/../includes/header.php';
// Unvan ve birim artık SEÇİLİR (serbest metin değil): seçenekler `it_tanimlar` + personelde
// geçen mevcut değerler birleşiminden gelir. Mevcut veri bir kez tanım listesine taşınır ki
// select ilk açılışta DOLU gelsin (idempotent, istek başına bir kez).
try { it_tanim_kisi_seed($pdoIt); } catch (Throwable $e) {}
// Arama kutusuna yazılmış metin ad/soyada ön dolgu olarak gelsin ("erkan coş" → Ad: Erkan, Soyad: Coş)
if ($popup && !$duzenleme && $_SERVER['REQUEST_METHOD'] !== 'POST' && ($__on = trim((string)($_GET['ad'] ?? ''))) !== '') {
    $__par = preg_split('/\s+/u', $__on);
    $v['soyad'] = count($__par) > 1 ? array_pop($__par) : '';
    $v['ad']    = implode(' ', $__par);
}
$tv = fn($k) => h($v[$k] ?? '');
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= $duzenleme ? 'personel_detay.php?id=' . $id : 'personel.php' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i><?= $duzenleme ? 'Personel Düzenle' : 'Yeni Personel' ?></h4>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<form method="post" class="card border-0 shadow-sm">
<?php if ($popup): ?><input type="hidden" name="popup" value="1"><?php endif; ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="card-body"><div class="row g-3">
    <div class="col-md-2"><label class="form-label">Sicil No</label><input name="sicil_no" class="form-control font-monospace" value="<?= $tv('sicil_no') ?>" maxlength="30"></div>
    <div class="col-md-3"><label class="form-label">Ad <span class="text-danger">*</span></label><input name="ad" class="form-control" value="<?= $tv('ad') ?>" required maxlength="80"></div>
    <div class="col-md-3"><label class="form-label">Soyad <span class="text-danger">*</span></label><input name="soyad" class="form-control" value="<?= $tv('soyad') ?>" required maxlength="80"></div>
    <div class="col-md-4"><label class="form-label">Unvan</label>
      <div class="input-group">
        <select name="unvan" id="unvan" class="form-select"><?= it_tanim_options($pdoIt, 'unvan', $v['unvan'] ?? '') ?></select>
        <?php if (can_edit()): ?><button type="button" class="btn btn-outline-secondary" id="unvanEkle" title="Listede yoksa ekle"><i class="bi bi-plus-lg"></i></button><?php endif; ?>
      </div>
      <div class="form-text" id="unvanNot">Listeden seçin; yoksa <strong>+</strong> ile ekleyin.</div></div>

    <div class="col-md-6"><label class="form-label">Lokasyon / Proje</label>
      <select name="lokasyon_id" class="form-select"><?= it_lokasyon_options($pdoIt, (int)($v['lokasyon_id'] ?? 0)) ?></select>
      <div class="form-text">Kartal projesi etapları (U030 / U031 / U039) ya da Merkez binadaki direktörlük. Ağacı <a href="tanimlar.php?t=lokasyon">Lokasyonlar</a> ekranından düzenleyin.</div></div>
    <div class="col-md-6"><label class="form-label">Birim / Departman</label>
      <div class="input-group">
        <select name="birim" id="birim" class="form-select"><?= it_tanim_options($pdoIt, 'birim', $v['birim'] ?? '') ?></select>
        <?php if (can_edit()): ?><button type="button" class="btn btn-outline-secondary" id="birimEkle" title="Listede yoksa ekle"><i class="bi bi-plus-lg"></i></button><?php endif; ?>
      </div>
      <div class="form-text" id="birimNot">Boş bırakılırsa lokasyon bir birimse (direktörlük vb.) onun adı yazılır.</div></div>

    <div class="col-12"><hr class="my-1"><div class="small text-muted fw-semibold"><i class="bi bi-telephone me-1"></i>İLETİŞİM</div></div>
    <div class="col-md-2"><label class="form-label">Dahili <span class="text-muted small">(masa tel.)</span></label>
      <input name="dahili" class="form-control font-monospace text-center" value="<?= $tv('dahili') ?>"
             maxlength="6" inputmode="numeric" pattern="[0-9]*" placeholder="1234">
      <div class="form-text">4 haneli kısa kod.</div></div>
    <div class="col-md-3"><label class="form-label">Şirket Hattı</label>
      <input name="telefon" class="form-control" value="<?= $tv('telefon') ?>" maxlength="30" placeholder="05xx xxx xx xx">
      <div class="form-text">Kurumsal cep — tutanakta bu yazar.</div></div>
    <div class="col-md-3"><label class="form-label">Şahsi Numara</label>
      <input name="telefon_sahsi" class="form-control" value="<?= $tv('telefon_sahsi') ?>" maxlength="30" placeholder="05xx xxx xx xx"></div>
    <div class="col-md-4"><label class="form-label">Şirket E-postası</label>
      <input name="eposta" type="email" class="form-control" value="<?= $tv('eposta') ?>" maxlength="120" placeholder="ad.soyad@ern.com.tr"></div>
    <div class="col-md-4"><label class="form-label">Şahsi E-posta</label>
      <input name="eposta_sahsi" type="email" class="form-control" value="<?= $tv('eposta_sahsi') ?>" maxlength="120"></div>

    <div class="col-12"><hr class="my-1"><div class="small text-muted fw-semibold"><i class="bi bi-calendar-event me-1"></i>ÇALIŞMA</div></div>
    <div class="col-md-3"><label class="form-label">İşe Giriş</label><input type="date" name="ise_giris" class="form-control" value="<?= $tv('ise_giris') ?>"></div>
    <div class="col-md-3"><label class="form-label">İşten Çıkış <span class="text-muted small">(boş = çalışıyor)</span></label><input type="date" name="isten_cikis" class="form-control" value="<?= $tv('isten_cikis') ?>"></div>
    <div class="col-12"><label class="form-label">Notlar</label><textarea name="notlar" rows="2" class="form-control"><?= $tv('notlar') ?></textarea></div>
  </div></div>
  <div class="card-footer bg-white d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $duzenleme ? 'Güncelle' : 'Kaydet' ?></button>
    <a href="<?= $duzenleme ? 'personel_detay.php?id=' . $id : 'personel.php' ?>" class="btn btn-outline-secondary">İptal</a>
  </div>
</form>
<script>
/* ── "Listede yoksa ekle" (Unvan / Birim) ─────────────────────────────────────
   Cihaz formundaki Marka ekleyicisiyle aynı akış: prompt → sayfanın kendi POST ucu →
   dönen ad select'e eklenip SEÇİLİR. Mükerrer engeli sunucuda (`it_norm`), bu yüzden
   aynı unvan farklı yazımla girilse de tek satır kalır. */
(function () {
    var CSRF = <?= json_encode(csrf_token()) ?>;
    function kur(tur, dugmeId, selectId, notId, soru) {
        var dugme = document.getElementById(dugmeId), sec = document.getElementById(selectId),
            not = document.getElementById(notId);
        if (!dugme || !sec) return;                       // yetkisiz kullanıcıda düğme basılmaz
        dugme.addEventListener('click', function () {
            var ad = (window.prompt(soru) || '').trim();
            if (!ad) return;                              // vazgeçildi — istek atma
            dugme.disabled = true;
            var g = new FormData();
            g.append('islem', 'tanim_ekle'); g.append('tur', tur); g.append('ad', ad); g.append('csrf', CSRF);
            fetch('personel_form.php', { method: 'POST', body: g, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    dugme.disabled = false;
                    if (!j || !j.ok) { alert((j && j.hata) || 'Eklenemedi.'); return; }
                    var v = null;
                    for (var i = 0; i < sec.options.length; i++)
                        if (sec.options[i].value === j.ad) { v = sec.options[i]; break; }
                    if (!v) { v = new Option(j.ad, j.ad); sec.add(v); }
                    sec.value = j.ad;
                    if (not) not.innerHTML = j.yeni
                        ? '<span class="text-success"><i class="bi bi-check-lg me-1"></i><strong>'
                          + j.ad.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</strong> listeye eklendi.</span>'
                        : '<span class="text-muted">Bu kayıt listede zaten vardı, seçildi.</span>';
                })
                .catch(function () { dugme.disabled = false; alert('Eklenemedi (bağlantı hatası).'); });
        });
    }
    kur('unvan', 'unvanEkle', 'unvan', 'unvanNot', 'Yeni unvan (ör. SAHA MÜHENDİSİ):');
    kur('birim', 'birimEkle', 'birim', 'birimNot', 'Yeni birim / departman (ör. TEKNİK OFİS):');
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
