<?php
/**
 * it/form_import.php — CİHAZ TAHSİS FORMU ile toplu içe aktarma
 *
 * Kurumsal "TAHSİS FORMU" dosyaları tablo değil FORM'dur (bir dosya = bir cihaz).
 * Bu ekran **birden çok formu tek seferde** alır, her birini çözümler, hangi cihazla
 * eşleştiğini ÖN İZLEMEDE gösterir ve onaylanınca cihaz künyesini günceller +
 * formu cihaza belge olarak bağlar. Çekirdek: `_form_import.php` (fim_*).
 *
 * 2 adım: (1) dosyaları yükle → ön izleme  (2) aktar → rapor.
 * Yüklenen dosyalar `uploads/it_form_bekleyen/` altında geçici durur (aktarımda belge olarak kopyalanır).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/_import.php';
require_once __DIR__ . '/_cihaz_import.php';
require_once __DIR__ . '/_form_import.php';
it_semasi_kur($pdoIt);
cim_semasi_kur($pdoIt);
$pageTitle = 'Form ile İçe Aktar — IT Envanter';
$kisi  = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
$hata  = ''; $rapor = null;
$BEKLEYEN = __DIR__ . '/../uploads/it_form_bekleyen';

/** Oturumdaki bekleyen formlar (ön izleme adımı). */
$formlar = $_SESSION['it_form'] ?? [];

/** Geçici klasördeki artıkları temizler (2 günden eski). */
$temizle = function () use ($BEKLEYEN) {
    if (!is_dir($BEKLEYEN)) return;
    foreach (glob($BEKLEYEN . '/*') as $y)
        if (is_file($y) && filemtime($y) < time() - 2 * 86400) @unlink($y);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = (string)($_POST['islem'] ?? '');

    if ($islem === 'vazgec') {
        foreach ($formlar as $f) if (!empty($f['yol']) && is_file($f['yol'])) @unlink($f['yol']);
        unset($_SESSION['it_form']);
        redirect('form_import.php');
    }

    if ($islem === 'yukle') {
        if (!yetki_var('giris') && !yetki_var('duzenle')) { flash('error', 'Bu işlem için yetkiniz yok.'); redirect('cihazlar.php'); }
        $temizle();
        if (!is_dir($BEKLEYEN)) @mkdir($BEKLEYEN, 0755, true);
        $dosyalar = it_dosya_listesi($_FILES['dosya'] ?? []);
        $dosyalar = array_filter($dosyalar, fn($f) => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
        if (!$dosyalar) $hata = 'Dosya seçilmedi.';
        else {
            $yeni = [];
            foreach ($dosyalar as $f) {
                $ad = basename((string)($f['name'] ?? ''));
                if ((int)($f['error'] ?? 1) !== UPLOAD_ERR_OK) { $yeni[] = ['ad' => $ad, 'hata' => 'Yükleme hatası (kod ' . (int)$f['error'] . ').']; continue; }
                if (!preg_match('/\.(xlsx|xlsm|pdf|txt|csv)$/i', $ad)) { $yeni[] = ['ad' => $ad, 'hata' => 'Kabul edilen uzantılar: .xlsx · .pdf'];  continue; }
                // Geçici klasöre taşı — tmp dosyası istek bitince silinir, ön izlemeden sonra lazım
                $hedef = $BEKLEYEN . '/' . date('Ymd_His') . '_' . substr(md5($ad . microtime()), 0, 8) . '_' . preg_replace('/[^\w.\-]+/u', '_', $ad);
                if (!@move_uploaded_file($f['tmp_name'], $hedef) && !@rename($f['tmp_name'], $hedef)) {
                    $yeni[] = ['ad' => $ad, 'hata' => 'Dosya geçici klasöre yazılamadı.']; continue;
                }
                try {
                    $o = fim_oku($hedef, $ad);
                    $v = fim_cozumle($o['ciftler'], $o['sayfa']);
                    $yeni[] = ['ad' => $ad, 'yol' => $hedef, 'v' => $v, 'sayfa' => $o['sayfa'],
                               'bicim' => $o['bicim'],
                               // kategori her zaman dolu ('diger') — sayaç yanıltmasın diye sayılmaz
                               'alan' => count(array_filter(array_diff_key($v, ['kategori' => 1]), fn($x) => trim((string)$x) !== ''))];
                } catch (Throwable $e) {
                    @unlink($hedef);
                    $yeni[] = ['ad' => $ad, 'hata' => $e->getMessage()];
                }
            }
            $_SESSION['it_form'] = $yeni;
            redirect('form_import.php');
        }
    }

    if ($islem === 'aktar') {
        if (!yetki_var('giris') && !yetki_var('duzenle')) { flash('error', 'Bu işlem için yetkiniz yok.'); redirect('cihazlar.php'); }
        $gecerli = array_values(array_filter($formlar, fn($f) => empty($f['hata']) && !empty($f['v'])));
        if (!$gecerli) $hata = 'Aktarılacak geçerli form yok.';
        else {
            $opt = ['bos_doldur'    => !empty($_POST['bos_doldur']),
                    'personel_ekle' => !empty($_POST['personel_ekle']),
                    'belge_tur'     => ($_POST['belge_tur'] ?? 'belge') === 'zimmet' ? 'zimmet' : 'belge',
                    'kullanici'     => $kisi];
            try { $rapor = fim_import($pdoIt, $gecerli, $opt); }
            catch (Throwable $e) { $hata = 'Aktarım hatası: ' . $e->getMessage(); }

            if ($rapor) {
                // ⚠ Günlük kaydı İKİNCİLDİR: burada çıkacak bir hata aktarımı geçersiz kılmamalı ve
                // geçici dosya temizliğini engellememeli (ilk sürümde yanlış kolon adı yüzünden
                // aktarım başarılıyken "Aktarım hatası" görünüyor, dosyalar da silinmiyordu).
                try {
                    pim_log_kur($pdoIt);
                    $pdoIt->prepare("INSERT INTO it_import_log (dosya,bicim,okunan,yeni,guncellenen,degismeyen,atlanan,ayrilan,kullanici) VALUES (?,?,?,?,?,?,?,0,?)")
                          ->execute([count($gecerli) . ' form [form]', 'form', $rapor['okunan'], count($rapor['yeni']),
                                     count($rapor['guncellenen']), $rapor['degismeyen'], count($rapor['atlanan']), $kisi]);
                } catch (Throwable $e) { /* günlük yazılamadı — aktarım geçerli */ }
                foreach ($formlar as $f) if (!empty($f['yol']) && is_file($f['yol'])) @unlink($f['yol']);
                unset($_SESSION['it_form']); $formlar = [];
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
$gecerliSayi = count(array_filter($formlar, fn($f) => empty($f['hata'])));
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="cihazlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-file-earmark-text text-primary me-2"></i>Form ile İçe Aktar</h4>
  <span class="text-muted small">cihaz tahsis formundan künye güncelleme</span>
  <div class="ms-auto d-flex gap-2">
    <a href="cihaz_import.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-table me-1"></i>Liste ile İçe Aktar</a>
  </div>
</div>
<?php if ($hata): ?><div class="alert alert-danger"><?= h($hata) ?></div><?php endif; ?>

<?php if ($rapor): /* ═══════════ RAPOR ═══════════ */
  $y = count($rapor['yeni']); $g = count($rapor['guncellenen']); $a = count($rapor['atlanan']);
  $tutuyor = $rapor['okunan'] === $y + $g + $rapor['degismeyen'] + $a; ?>
  <div class="alert alert-<?= $tutuyor ? 'success' : 'warning' ?>">
    <strong><?= (int)$rapor['okunan'] ?> form okundu.</strong>
    <?= $y ?> yeni cihaz · <?= $g ?> güncellenen · <?= (int)$rapor['degismeyen'] ?> değişmeyen · <?= $a ?> atlanan ·
    <?= (int)$rapor['belge'] ?> form belge olarak eklendi.
    <div class="small mt-1">okunan = yeni + güncellenen + değişmeyen + atlanan → <?= $tutuyor ? 'tutuyor ✓' : 'TUTMUYOR — kontrol edin' ?></div>
  </div>
  <?php if ($rapor['guncellenen']): ?>
  <div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white"><strong>Güncellenen cihazlar (<?= $g ?>)</strong></div>
    <div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.85rem">
      <thead class="table-light"><tr><th>Form</th><th>Cihaz</th><th>Eşleşme</th><th>Değişen alanlar</th></tr></thead>
      <tbody><?php foreach ($rapor['guncellenen'] as $x): ?>
        <tr><td class="small"><?= h($x['dosya']) ?></td>
            <td><a href="cihaz_detay.php?id=<?= (int)$x['id'] ?>"><?= h($x['kim']) ?></a></td>
            <td class="small text-muted"><?= h($x['nasil']) ?></td>
            <td class="small"><?= h(implode(' · ', array_slice($x['degisen'], 0, 8))) ?><?= count($x['degisen']) > 8 ? ' …' : '' ?></td></tr>
      <?php endforeach; ?></tbody></table></div></div>
  <?php endif; ?>
  <?php if ($rapor['yeni']): ?>
  <div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white"><strong>Yeni açılan cihazlar (<?= $y ?>)</strong></div>
    <ul class="list-group list-group-flush"><?php foreach ($rapor['yeni'] as $x): ?>
      <li class="list-group-item small"><a href="cihaz_detay.php?id=<?= (int)$x['id'] ?>"><?= h($x['kim']) ?></a>
        <span class="text-muted">— <?= h($x['dosya']) ?></span></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($rapor['atlanan']): ?>
  <div class="card border-0 shadow-sm mb-3 border-start border-danger border-3"><div class="card-header bg-white"><strong>Atlanan formlar (<?= $a ?>)</strong></div>
    <ul class="list-group list-group-flush"><?php foreach ($rapor['atlanan'] as $x): ?>
      <li class="list-group-item small"><strong><?= h($x['dosya']) ?></strong> — <?= h($x['neden']) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($rapor['kisi_yok']): ?>
  <div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white"><strong>Personel kartı bulunamayan kişiler (<?= count($rapor['kisi_yok']) ?>)</strong></div>
    <ul class="list-group list-group-flush"><?php foreach ($rapor['kisi_yok'] as $x): ?>
      <li class="list-group-item small"><strong><?= h($x['kisi']) ?></strong> <span class="text-muted">— <?= h($x['neden'] ?? '') ?> (<?= h($x['dosya']) ?>)</span></li>
    <?php endforeach; ?></ul>
    <div class="card-footer bg-white small text-muted">Cihaz kaydedildi ama kişiye zimmetlenmedi. "Eşleşmeyen kişiler için personel kartı aç" seçeneğiyle tekrar yükleyebilirsiniz.</div></div>
  <?php endif; ?>
  <a href="form_import.php" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Yeni form yükle</a>
  <a href="cihazlar.php" class="btn btn-outline-secondary">Cihaz listesine dön</a>

<?php elseif ($formlar): /* ═══════════ ÖN İZLEME ═══════════ */ ?>
  <div class="alert alert-info">
    <strong><?= count($formlar) ?> dosya okundu</strong>, <?= $gecerliSayi ?> tanesi form olarak tanındı.
    Aşağıda her formun hangi cihazla eşleştiği ve hangi alanların dolacağı görünüyor — onaylayınca aktarılır.
  </div>
  <form method="post" class="card border-0 shadow-sm mb-3">
    <input type="hidden" name="islem" value="aktar">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4"><div class="form-check">
          <input class="form-check-input" type="checkbox" name="bos_doldur" id="bos_doldur" value="1">
          <label class="form-check-label" for="bos_doldur"><strong>Yalnız boş alanları doldur</strong>
            <div class="form-text">Cihazda DOLU olan bilgiye dokunulmaz. Eski tarihli formlar yeni veriyi ezmesin diye.</div></label>
        </div></div>
        <div class="col-md-4"><div class="form-check">
          <input class="form-check-input" type="checkbox" name="personel_ekle" id="personel_ekle" value="1" <?= yetki_var('duzenle') ? '' : 'disabled' ?>>
          <label class="form-check-label" for="personel_ekle"><strong>Eşleşmeyen kişi için personel kartı aç</strong>
            <div class="form-text">Formdaki "Ad Soyad" sistemde yoksa kart açılır ve cihaz ona zimmetlenir.</div></label>
        </div></div>
        <div class="col-md-4"><label class="form-label small mb-1">Form dosyası neyi belgeliyor?</label>
          <select name="belge_tur" class="form-select form-select-sm">
            <option value="belge">Belge (varsayılan)</option>
            <option value="zimmet">İmzalı zimmet tutanağı</option>
          </select>
          <div class="form-text">Taranmış, imzalı formu yüklüyorsanız "zimmet" seçin — cihaz kartında evrak ✓ olur.</div></div>
      </div>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
      <button class="btn btn-primary" <?= $gecerliSayi ? '' : 'disabled' ?>><i class="bi bi-check2-circle me-1"></i><?= $gecerliSayi ?> formu aktar</button>
      <button class="btn btn-outline-secondary" name="islem" value="vazgec" formnovalidate>Vazgeç</button>
    </div>
  </form>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
      <thead class="table-light"><tr><th>Dosya</th><th>Cihaz Kodu</th><th>Tip</th><th>Eşleşen cihaz</th><th>Kullanıcı</th><th>Okunan alanlar</th></tr></thead>
      <tbody>
      <?php foreach ($formlar as $f):
        if (!empty($f['hata'])): ?>
          <tr class="table-danger"><td><?= h($f['ad']) ?></td><td colspan="5" class="small"><i class="bi bi-x-circle me-1"></i><?= h($f['hata']) ?></td></tr>
        <?php continue; endif;
        $v = $f['v'];
        // Ön izlemede eşleşmeyi göster — aktarımdaki sıra ile aynı (kod → IFS → seri)
        $es = null;
        foreach ([['cihaz_kodu','cihaz_kodu'], ['varlik_kodu','varlik_kodu'], ['seri_no','seri_no']] as [$formAlan, $kol]) {
            $d = trim((string)($v[$formAlan] ?? ''));
            if ($d === '') continue;
            $st = $pdoIt->prepare("SELECT id, cihaz_kodu, ad FROM it_cihazlar WHERE `$kol`=? LIMIT 1");
            $st->execute([$d]);
            if ($es = $st->fetch()) break;
        }
        $dolu = array_filter($v, fn($x) => trim((string)$x) !== '');
        unset($dolu['kategori']); ?>
        <tr>
          <td><i class="bi bi-file-earmark-<?= ($f['bicim'] ?? '') === 'pdf' ? 'pdf' : 'excel' ?> me-1 text-muted"></i><?= h($f['ad']) ?>
            <?php if (!empty($f['sayfa'])): ?><div class="text-muted small">sayfa: <?= h($f['sayfa']) ?></div><?php endif; ?></td>
          <td class="font-monospace"><?= h($v['cihaz_kodu'] ?: '—') ?></td>
          <td><?= h(it_kategoriAd($v['kategori'] ?: 'diger')) ?></td>
          <td><?php if ($es): ?><a href="cihaz_detay.php?id=<?= (int)$es['id'] ?>"><?= h(trim(($es['cihaz_kodu'] ?? '') . ' ' . $es['ad'])) ?></a>
                <span class="badge bg-success-subtle text-success border">güncellenecek</span>
              <?php else: ?><span class="badge bg-primary-subtle text-primary border">yeni cihaz açılacak</span><?php endif; ?></td>
          <td><?= h($v['kisi'] ?: '—') ?></td>
          <td class="small text-muted"><?= h(implode(' · ', array_slice(array_keys($dolu), 0, 10))) ?><?= count($dolu) > 10 ? ' …' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>

<?php else: /* ═══════════ YÜKLEME ═══════════ */ ?>
  <div class="row g-3">
    <div class="col-lg-7">
      <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm">
        <input type="hidden" name="islem" value="yukle">
        <div class="card-body">
          <label class="form-label"><strong>Tahsis formlarını seçin</strong> <span class="text-muted small">(birden çok dosya seçebilirsiniz)</span></label>
          <input type="file" name="dosya[]" class="form-control" multiple accept=".xlsx,.xlsm,.pdf,.txt,.csv" required>
          <div class="form-text mt-2">
            Her dosya <strong>bir cihazın</strong> tahsis formudur (ör. <code>B053 - Sergender Atmaca.xlsx</code>).
            Sistem formdaki <em>Cihaz Kodu · Marka · Model · Serial · Alış Tarihi · Tedarikçi · Fatura No ·
            İşletim Sistemi · İşlemci · Ram · Harddisk · Anakart · Ekran Kartı · Ad Soyad</em> alanlarını okur.
          </div>
        </div>
        <div class="card-footer bg-white"><button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Yükle ve çözümle</button></div>
      </form>
    </div>
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm h-100"><div class="card-body small">
        <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i>Nasıl çalışır?</div>
        <ol class="ps-3 mb-3">
          <li>Formlar <strong>cihaz kodu → IFS nesne no → seri no</strong> sırasıyla mevcut cihazla eşleştirilir.</li>
          <li>Eşleşen cihazda <strong>yalnız formda dolu gelen alanlar</strong> güncellenir; boş hücre mevcut veriyi silmez.</li>
          <li>Eşleşme yoksa <strong>yeni cihaz açılır</strong> (envanter no otomatik verilir).</li>
          <li>Formun kendisi cihaza <strong>belge olarak</strong> eklenir; aynı dosya ikinci kez yüklenirse tekrar eklenmez.</li>
        </ol>
        <div class="text-muted">
          <strong>.xlsx</strong> doğrudan okunur. <strong>.pdf</strong> için sunucuda <code>pdftotext</code> ya da
          AI belge okuma gerekir; yoksa formu Excel olarak kaydedip yükleyin.
        </div>
      </div></div>
    </div>
  </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
