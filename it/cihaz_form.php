<?php
/**
 * it/cihaz_form.php — Cihaz / lisans ekle-düzenle
 * Yeni kayıtta envanter no otomatik (IT-00001). Kayıt silinmez; durum "hurda" yapılır.
 * Fotoğraf/fatura yüklemesi kayıt sonrası detay ekranından (çoklu) ya da buradan tek dosya.
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
$c  = null;
if ($id) {
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE id=?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) { flash('error', 'Cihaz bulunamadı.'); redirect('cihazlar.php'); }
}
$duzenleme = (bool)$c;
if ($duzenleme ? !yetki_var('duzenle') : !yetki_var('giris')) {
    flash('error', $duzenleme ? 'Kayıt değiştirme yetkiniz yok.' : 'Veri girişi yetkiniz yok.');
    redirect($duzenleme ? 'cihaz_detay.php?id=' . $id : 'cihazlar.php');
}

$error = '';
$v = $c ?: ['envanter_no'=>'', 'kategori'=>'laptop', 'ad'=>'', 'marka'=>'', 'model'=>'', 'seri_no'=>'', 'durum'=>'depoda',
            'zimmetli'=>'', 'departman'=>'', 'lokasyon'=>'', 'zimmet_tarihi'=>'', 'alis_tarihi'=>'', 'garanti_bitis'=>'',
            'fiyat'=>'', 'tedarikci'=>'', 'fatura_no'=>'', 'ip_adresi'=>'', 'mac_adresi'=>'', 'isletim_sistemi'=>'',
            'ozellikler'=>'', 'lisans_anahtari'=>'', 'lisans_adet'=>'', 'notlar'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $al = fn($k, $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $y = [
        'kategori'        => isset(IT_KATEGORI[$_POST['kategori'] ?? '']) ? $_POST['kategori'] : 'diger',
        'ad'              => $al('ad', 150),
        'marka'           => $al('marka', 80),
        'model'           => $al('model', 120),
        'seri_no'         => $al('seri_no', 120),
        'durum'           => isset(IT_DURUM[$_POST['durum'] ?? '']) ? $_POST['durum'] : 'depoda',
        'zimmetli'        => $al('zimmetli', 120),
        'departman'       => $al('departman', 80),
        'lokasyon'        => $al('lokasyon', 120),
        'zimmet_tarihi'   => it_tarih($_POST['zimmet_tarihi'] ?? ''),
        'alis_tarihi'     => it_tarih($_POST['alis_tarihi'] ?? ''),
        'garanti_bitis'   => it_tarih($_POST['garanti_bitis'] ?? ''),
        'fiyat'           => it_sayi($_POST['fiyat'] ?? ''),
        'tedarikci'       => $al('tedarikci', 120),
        'fatura_no'       => $al('fatura_no', 60),
        'ip_adresi'       => $al('ip_adresi', 45),
        'mac_adresi'      => $al('mac_adresi', 40),
        'isletim_sistemi' => $al('isletim_sistemi', 80),
        'ozellikler'      => $al('ozellikler', 255),
        'lisans_anahtari' => $al('lisans_anahtari', 160),
        'lisans_adet'     => ($_POST['lisans_adet'] ?? '') !== '' ? max(0, (int)$_POST['lisans_adet']) : null,
        'notlar'          => trim((string)($_POST['notlar'] ?? '')) ?: null,
    ];
    // Zimmetli kişi doluysa durum otomatik "kullanımda", boşsa "kullanımda" olamaz
    if ($y['zimmetli'] && in_array($y['durum'], ['depoda'], true)) $y['durum'] = 'aktif';
    if (!$y['zimmetli'] && $y['durum'] === 'aktif' && $y['kategori'] !== 'yazilim') $y['durum'] = 'depoda';   // lisans kişisiz de kullanımda olabilir
    if ($y['zimmetli'] && !$y['zimmet_tarihi']) $y['zimmet_tarihi'] = date('Y-m-d');
    if (!$y['zimmetli']) $y['zimmet_tarihi'] = null;

    if (!$y['ad']) {
        $error = 'Cihaz adı zorunludur.';
    } else {
        try {
            $kul = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
            if ($duzenleme) {
                $sql = "UPDATE it_cihazlar SET " . implode(', ', array_map(fn($k) => "$k=?", array_keys($y))) . " WHERE id=?";
                $pdoIt->prepare($sql)->execute([...array_values($y), $id]);
                // Değişen kritik alanları günlüğe yaz
                $deg = [];
                foreach (['durum', 'zimmetli', 'departman', 'lokasyon', 'seri_no', 'garanti_bitis'] as $k)
                    if ((string)($c[$k] ?? '') !== (string)($y[$k] ?? '')) $deg[] = $k . ': ' . (($c[$k] ?? '') ?: '—') . ' → ' . (($y[$k] ?? '') ?: '—');
                if ($c['zimmetli'] !== $y['zimmetli']) {
                    if ($y['zimmetli']) it_hareket_ekle($pdoIt, $id, 'zimmet', $y['zimmetli'], 'Formdan zimmet verildi' . ($y['departman'] ? ' (' . $y['departman'] . ')' : ''), $y['zimmet_tarihi']);
                    else it_hareket_ekle($pdoIt, $id, 'iade', $c['zimmetli'], 'Formdan zimmet kaldırıldı');
                } elseif ($deg) {
                    it_hareket_ekle($pdoIt, $id, 'guncelleme', null, implode(' · ', $deg));
                }
                flash('success', 'Cihaz güncellendi.');
            } else {
                $y['envanter_no'] = it_envanter_no($pdoIt);
                $y['olusturan']   = $kul;
                $sql = "INSERT INTO it_cihazlar (" . implode(',', array_keys($y)) . ") VALUES (" . implode(',', array_fill(0, count($y), '?')) . ")";
                $pdoIt->prepare($sql)->execute(array_values($y));
                $id = (int)$pdoIt->lastInsertId();
                it_hareket_ekle($pdoIt, $id, 'giris', $y['tedarikci'], 'Envantere kaydedildi' . ($y['fatura_no'] ? ' — fatura ' . $y['fatura_no'] : ''), $y['alis_tarihi'] ?: null);
                if ($y['zimmetli']) it_hareket_ekle($pdoIt, $id, 'zimmet', $y['zimmetli'], 'Kayıtla birlikte zimmetlendi', $y['zimmet_tarihi']);
                flash('success', $y['envanter_no'] . ' envanter numarasıyla kaydedildi.');
            }
            // İsteğe bağlı tek fotoğraf / belge
            if (!empty($_FILES['foto']['name'])) {
                [$ok, $msg] = it_belge_yukle($pdoIt, $id, $_FILES['foto'], $kul);
                if (!$ok) flash('warning', 'Belge yüklenemedi: ' . strip_tags($msg));
            }
            redirect('cihaz_detay.php?id=' . $id);
        } catch (PDOException $e) {
            $error = 'Veritabanı hatası: ' . $e->getMessage();
        }
    }
    $v = array_merge($v, $y);
}

$pageTitle = ($duzenleme ? 'Cihaz Düzenle — ' . $c['envanter_no'] : 'Yeni Cihaz') . ' — IT Envanter';
require_once __DIR__ . '/../includes/header.php';
$sec = ['zimmetli' => it_secenekler($pdoIt, 'zimmetli'), 'departman' => it_secenekler($pdoIt, 'departman'),
        'lokasyon' => it_secenekler($pdoIt, 'lokasyon'), 'marka' => it_secenekler($pdoIt, 'marka'), 'tedarikci' => it_secenekler($pdoIt, 'tedarikci')];
$dl = function (string $k) use ($sec) {
    if (empty($sec[$k])) return '';
    $o = '<datalist id="dl_' . $k . '">';
    foreach ($sec[$k] as $x) $o .= '<option value="' . h($x) . '">';
    return $o . '</datalist>';
};
$tv = fn($k) => h($v[$k] ?? '');
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= $duzenleme ? 'cihaz_detay.php?id=' . $id : 'cihazlar.php' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-pc-display text-primary me-2"></i><?= $duzenleme ? 'Cihaz Düzenle' : 'Yeni Cihaz / Lisans' ?></h4>
    <?php if ($duzenleme): ?><span class="badge bg-light text-dark border font-monospace"><?= h($c['envanter_no']) ?></span><?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Kategori <span class="text-danger">*</span></label>
        <select name="kategori" id="kategori" class="form-select" required>
          <?php foreach (IT_KATEGORI as $k => [$ad, $ik]): ?>
          <option value="<?= $k ?>" <?= ($v['kategori'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Cihaz Adı / Tanım <span class="text-danger">*</span></label>
        <input name="ad" class="form-control" value="<?= $tv('ad') ?>" required maxlength="150" placeholder="ör. Şantiye ofisi dizüstü, Muhasebe yazıcısı">
      </div>
      <div class="col-md-4">
        <label class="form-label">Durum</label>
        <select name="durum" class="form-select">
          <?php foreach (IT_DURUM as $k => [$ad, $r, $ik]): ?>
          <option value="<?= $k ?>" <?= ($v['durum'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Zimmetli kişi girilirse durum otomatik "Kullanımda" olur.</div>
      </div>

      <div class="col-md-3"><label class="form-label">Marka</label><input name="marka" list="dl_marka" class="form-control" value="<?= $tv('marka') ?>" maxlength="80"><?= $dl('marka') ?></div>
      <div class="col-md-4"><label class="form-label">Model</label><input name="model" class="form-control" value="<?= $tv('model') ?>" maxlength="120"></div>
      <div class="col-md-5"><label class="form-label">Seri No</label><input name="seri_no" class="form-control font-monospace" value="<?= $tv('seri_no') ?>" maxlength="120"></div>

      <div class="col-12"><hr class="my-1"><div class="small text-muted fw-semibold"><i class="bi bi-person-check me-1"></i>ZİMMET</div></div>
      <div class="col-md-4"><label class="form-label">Zimmetli Kişi</label><input name="zimmetli" list="dl_zimmetli" class="form-control" value="<?= $tv('zimmetli') ?>" maxlength="120" placeholder="Ad Soyad"><?= $dl('zimmetli') ?></div>
      <div class="col-md-3"><label class="form-label">Departman</label><input name="departman" list="dl_departman" class="form-control" value="<?= $tv('departman') ?>" maxlength="80"><?= $dl('departman') ?></div>
      <div class="col-md-3"><label class="form-label">Lokasyon</label><input name="lokasyon" list="dl_lokasyon" class="form-control" value="<?= $tv('lokasyon') ?>" maxlength="120" placeholder="Şantiye ofisi / Merkez"><?= $dl('lokasyon') ?></div>
      <div class="col-md-2"><label class="form-label">Zimmet Tarihi</label><input type="date" name="zimmet_tarihi" class="form-control" value="<?= $tv('zimmet_tarihi') ?>"></div>

      <div class="col-12"><hr class="my-1"><div class="small text-muted fw-semibold"><i class="bi bi-receipt me-1"></i>SATIN ALMA &amp; GARANTİ</div></div>
      <div class="col-md-2"><label class="form-label">Alış Tarihi</label><input type="date" name="alis_tarihi" class="form-control" value="<?= $tv('alis_tarihi') ?>"></div>
      <div class="col-md-2"><label class="form-label">Garanti Bitiş</label><input type="date" name="garanti_bitis" class="form-control" value="<?= $tv('garanti_bitis') ?>"></div>
      <div class="col-md-2"><label class="form-label">Fiyat (TL)</label><input name="fiyat" class="form-control text-end" value="<?= $v['fiyat'] !== null && $v['fiyat'] !== '' ? number_format((float)$v['fiyat'], 2, ',', '.') : '' ?>" placeholder="0,00"></div>
      <div class="col-md-3"><label class="form-label">Tedarikçi</label><input name="tedarikci" list="dl_tedarikci" class="form-control" value="<?= $tv('tedarikci') ?>" maxlength="120"><?= $dl('tedarikci') ?></div>
      <div class="col-md-3"><label class="form-label">Fatura No</label><input name="fatura_no" class="form-control" value="<?= $tv('fatura_no') ?>" maxlength="60"></div>

      <div class="col-12 teknik"><hr class="my-1"><div class="small text-muted fw-semibold"><i class="bi bi-cpu me-1"></i>TEKNİK</div></div>
      <div class="col-md-3 teknik"><label class="form-label">IP Adresi</label><input name="ip_adresi" class="form-control font-monospace" value="<?= $tv('ip_adresi') ?>" maxlength="45"></div>
      <div class="col-md-3 teknik"><label class="form-label">MAC Adresi</label><input name="mac_adresi" class="form-control font-monospace" value="<?= $tv('mac_adresi') ?>" maxlength="40"></div>
      <div class="col-md-3 teknik"><label class="form-label">İşletim Sistemi</label><input name="isletim_sistemi" class="form-control" value="<?= $tv('isletim_sistemi') ?>" maxlength="80" placeholder="Windows 11 Pro"></div>
      <div class="col-md-3 teknik"><label class="form-label">Özellikler</label><input name="ozellikler" class="form-control" value="<?= $tv('ozellikler') ?>" maxlength="255" placeholder="i7 / 16 GB / 512 SSD"></div>

      <div class="col-12 lisans"><hr class="my-1"><div class="small text-muted fw-semibold"><i class="bi bi-key me-1"></i>LİSANS</div></div>
      <div class="col-md-8 lisans"><label class="form-label">Lisans Anahtarı</label><input name="lisans_anahtari" class="form-control font-monospace" value="<?= $tv('lisans_anahtari') ?>" maxlength="160"></div>
      <div class="col-md-4 lisans"><label class="form-label">Lisans Adedi (kullanıcı/cihaz)</label><input type="number" min="0" name="lisans_adet" class="form-control" value="<?= $tv('lisans_adet') ?>"></div>

      <div class="col-12"><label class="form-label">Notlar</label><textarea name="notlar" rows="3" class="form-control"><?= $tv('notlar') ?></textarea></div>
      <div class="col-md-6">
        <label class="form-label"><i class="bi bi-camera me-1"></i>Fotoğraf / Fatura / Garanti belgesi <span class="text-muted small">(isteğe bağlı; detay ekranından çoklu yüklenir)</span></label>
        <input type="file" name="foto" class="form-control" accept="image/*,application/pdf">
      </div>
    </div>
  </div>
  <div class="card-footer bg-white d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $duzenleme ? 'Güncelle' : 'Kaydet' ?></button>
    <a href="<?= $duzenleme ? 'cihaz_detay.php?id=' . $id : 'cihazlar.php' ?>" class="btn btn-outline-secondary">İptal</a>
    <?php if (!$duzenleme): ?><span class="ms-auto small text-muted align-self-center">Envanter no otomatik verilir (IT-00001…)</span><?php endif; ?>
  </div>
</form>

<script>
// Kategoriye göre alan grupları: lisans alanları yalnız yazılımda, teknik alanlar lisansta gizli
(function () {
    var sel = document.getElementById('kategori');
    function uygula() {
        var yaz = sel.value === 'yazilim';
        document.querySelectorAll('.lisans').forEach(function (e) { e.classList.toggle('d-none', !yaz); });
        document.querySelectorAll('.teknik').forEach(function (e) { e.classList.toggle('d-none', yaz || sel.value === 'aksesuar'); });
    }
    sel.addEventListener('change', uygula); uygula();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
