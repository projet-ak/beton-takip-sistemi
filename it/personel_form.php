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

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
$p  = it_personel_bul($pdoIt, $id);
if ($id && !$p) { flash('error', 'Personel bulunamadı.'); redirect('personel.php'); }
$duzenleme = (bool)$p;
if ($duzenleme ? !yetki_var('duzenle') : !yetki_var('giris')) {
    flash('error', 'Bu işlem için yetkiniz yok.'); redirect($duzenleme ? 'personel_detay.php?id=' . $id : 'personel.php');
}

$error = '';
$v = $p ?: ['sicil_no'=>'','ad'=>'','soyad'=>'','unvan'=>'','birim'=>'','lokasyon_id'=>'','telefon'=>'','eposta'=>'','ise_giris'=>'','isten_cikis'=>'','notlar'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $al = fn($k, $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $y = [
        'sicil_no'    => $al('sicil_no', 30),
        'ad'          => $al('ad', 80),
        'soyad'       => $al('soyad', 80),
        'unvan'       => $al('unvan', 100),
        'birim'       => $al('birim', 100),
        'lokasyon_id' => (int)($_POST['lokasyon_id'] ?? 0) ?: null,
        'telefon'     => $al('telefon', 30),
        'eposta'      => $al('eposta', 120),
        'ise_giris'   => it_tarih($_POST['ise_giris'] ?? ''),
        'isten_cikis' => it_tarih($_POST['isten_cikis'] ?? ''),
        'notlar'      => trim((string)($_POST['notlar'] ?? '')) ?: null,
    ];
    if ($y['lokasyon_id'] && !isset(it_lokasyonlar($pdoIt)[$y['lokasyon_id']])) $y['lokasyon_id'] = null;
    // Birim boşsa lokasyonun birim türündeki adı birim sayılır (Merkez binada direktörlük = birim)
    if (!$y['birim'] && $y['lokasyon_id']) { $l = it_lokasyonlar($pdoIt)[$y['lokasyon_id']]; if ($l['tur'] === 'birim') $y['birim'] = $l['ad']; }

    if (!$y['ad'] || !$y['soyad']) {
        $error = 'Ad ve soyad zorunludur.';
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
        redirect('personel_detay.php?id=' . $id);
    }
    $v = array_merge($v, $y);
}

$pageTitle = ($duzenleme ? 'Personel Düzenle' : 'Yeni Personel') . ' — IT Envanter';
require_once __DIR__ . '/../includes/header.php';
$birimler = []; $unvanlar = [];
try { $birimler = $pdoIt->query("SELECT DISTINCT birim FROM it_personel WHERE birim IS NOT NULL AND birim<>'' ORDER BY birim")->fetchAll(PDO::FETCH_COLUMN);
      $unvanlar = $pdoIt->query("SELECT DISTINCT unvan FROM it_personel WHERE unvan IS NOT NULL AND unvan<>'' ORDER BY unvan")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
// Merkez binadaki direktörlükler de birim önerisi olsun
foreach (it_lokasyonlar($pdoIt) as $l) if ($l['tur'] === 'birim' && !in_array($l['ad'], $birimler, true)) $birimler[] = $l['ad'];
$tv = fn($k) => h($v[$k] ?? '');
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= $duzenleme ? 'personel_detay.php?id=' . $id : 'personel.php' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i><?= $duzenleme ? 'Personel Düzenle' : 'Yeni Personel' ?></h4>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<form method="post" class="card border-0 shadow-sm">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="card-body"><div class="row g-3">
    <div class="col-md-2"><label class="form-label">Sicil No</label><input name="sicil_no" class="form-control font-monospace" value="<?= $tv('sicil_no') ?>" maxlength="30"></div>
    <div class="col-md-3"><label class="form-label">Ad <span class="text-danger">*</span></label><input name="ad" class="form-control" value="<?= $tv('ad') ?>" required maxlength="80"></div>
    <div class="col-md-3"><label class="form-label">Soyad <span class="text-danger">*</span></label><input name="soyad" class="form-control" value="<?= $tv('soyad') ?>" required maxlength="80"></div>
    <div class="col-md-4"><label class="form-label">Unvan</label><input name="unvan" list="dl_unvan" class="form-control" value="<?= $tv('unvan') ?>" maxlength="100" placeholder="Proje Müdürü, Satış Uzmanı, Şantiye Şefi…">
      <datalist id="dl_unvan"><?php foreach ($unvanlar as $x): ?><option value="<?= h($x) ?>"><?php endforeach; ?></datalist></div>

    <div class="col-md-6"><label class="form-label">Lokasyon / Proje</label>
      <select name="lokasyon_id" class="form-select"><?= it_lokasyon_options($pdoIt, (int)($v['lokasyon_id'] ?? 0)) ?></select>
      <div class="form-text">Kartal projesi etapları (U030 / U031 / U039) ya da Merkez binadaki direktörlük. Ağacı <a href="tanimlar.php?t=lokasyon">Lokasyonlar</a> ekranından düzenleyin.</div></div>
    <div class="col-md-6"><label class="form-label">Birim / Departman</label><input name="birim" list="dl_birim" class="form-control" value="<?= $tv('birim') ?>" maxlength="100" placeholder="Teknik Ofis, Muhasebe, Satış Ofisi…">
      <datalist id="dl_birim"><?php foreach ($birimler as $x): ?><option value="<?= h($x) ?>"><?php endforeach; ?></datalist>
      <div class="form-text">Boş bırakılırsa lokasyon bir birimse (direktörlük vb.) onun adı yazılır.</div></div>

    <div class="col-md-3"><label class="form-label">Telefon</label><input name="telefon" class="form-control" value="<?= $tv('telefon') ?>" maxlength="30" placeholder="05xx xxx xx xx"></div>
    <div class="col-md-3"><label class="form-label">E-posta</label><input name="eposta" type="email" class="form-control" value="<?= $tv('eposta') ?>" maxlength="120"></div>
    <div class="col-md-3"><label class="form-label">İşe Giriş</label><input type="date" name="ise_giris" class="form-control" value="<?= $tv('ise_giris') ?>"></div>
    <div class="col-md-3"><label class="form-label">İşten Çıkış <span class="text-muted small">(boş = çalışıyor)</span></label><input type="date" name="isten_cikis" class="form-control" value="<?= $tv('isten_cikis') ?>"></div>
    <div class="col-12"><label class="form-label">Notlar</label><textarea name="notlar" rows="2" class="form-control"><?= $tv('notlar') ?></textarea></div>
  </div></div>
  <div class="card-footer bg-white d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $duzenleme ? 'Güncelle' : 'Kaydet' ?></button>
    <a href="<?= $duzenleme ? 'personel_detay.php?id=' . $id : 'personel.php' ?>" class="btn btn-outline-secondary">İptal</a>
  </div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
