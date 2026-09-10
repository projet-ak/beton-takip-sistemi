<?php
/**
 * it/cihaz_detay.php — Cihaz kartı: tüm alanlar + yaşam günlüğü (hareketler) + belgeler/fotoğraflar
 * İşlemler: zimmet ver / zimmet iade / servise gönder / servisten döndü / arıza / hurdaya ayır / not.
 * Her işlem it_hareketler'e yazılır; cihazın durum/zimmet alanları buna göre güncellenir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';

it_semasi_kur($pdoIt);
$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE id=?");
$st->execute([$id]);
$c = $st->fetch();
if (!$c) { flash('error', 'Cihaz bulunamadı.'); redirect('cihazlar.php'); }

$duzenleyebilir = yetki_var('duzenle');
$girebilir      = yetki_var('giris');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = $_POST['action'] ?? '';
    $kul   = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
    $tarih = it_tarih($_POST['tarih'] ?? '') ?: date('Y-m-d');
    $kisi  = mb_substr(trim((string)($_POST['kisi'] ?? '')), 0, 120) ?: null;
    $acik  = trim((string)($_POST['aciklama'] ?? '')) ?: null;

    if ($islem === 'hareket' && $duzenleyebilir) {
        $tur = $_POST['tur'] ?? '';
        switch ($tur) {
            case 'zimmet':
                $pid = (int)($_POST['personel_id'] ?? 0);
                $pp  = it_personel_bul($pdoIt, $pid);
                if (!$pp) { flash('error', 'Zimmet için personel seçin (listede yoksa önce personel kaydı açın).'); break; }
                if (!it_personel_aktif($pp)) { flash('error', 'İşten ayrılmış personele zimmet verilemez.'); break; }
                $kisi = it_personel_ad($pp);
                $dep = mb_substr(trim((string)($_POST['departman'] ?? '')), 0, 80) ?: null;
                $lok = mb_substr(trim((string)($_POST['lokasyon'] ?? '')), 0, 120) ?: null;
                if ($c['zimmetli'] && $c['zimmetli'] !== $kisi)
                    it_hareket_ekle($pdoIt, $id, 'iade', $c['zimmetli'], 'Zimmet devri: yeni zimmetli ' . $kisi, $tarih);
                $lokId = (int)($_POST['lokasyon_id'] ?? 0) ?: ((int)($pp['lokasyon_id'] ?? 0) ?: null);
                $lokYol = $lokId ? it_lokasyon_yol($pdoIt, $lokId) : $lok;
                $pdoIt->prepare("UPDATE it_cihazlar SET personel_id=?, zimmetli=?, departman=COALESCE(?,departman), lokasyon_id=?, lokasyon=COALESCE(?,lokasyon), zimmet_tarihi=?, durum='aktif' WHERE id=?")
                      ->execute([$pid, $kisi, $dep ?: ($pp['birim'] ?: null), $lokId ?: null, $lokYol ?: null, $tarih, $id]);
                it_hareket_ekle($pdoIt, $id, 'zimmet', $kisi, $acik ?: ('Zimmet verildi' . ($dep ? ' (' . $dep . ')' : '')), $tarih);
                flash('success', $kisi . ' adına zimmetlendi. Tutanağı yazdırıp imzalatabilirsiniz.');
                break;
            case 'iade':
                $pdoIt->prepare("UPDATE it_cihazlar SET personel_id=NULL, zimmetli=NULL, zimmet_tarihi=NULL, durum=IF(durum='aktif','depoda',durum) WHERE id=?")->execute([$id]);
                it_hareket_ekle($pdoIt, $id, 'iade', $c['zimmetli'], $acik ?: 'Zimmet iade alındı, depoya girdi', $tarih);
                flash('success', 'Zimmet iade alındı; cihaz depoda.');
                break;
            case 'servis':
                $pdoIt->prepare("UPDATE it_cihazlar SET durum='serviste' WHERE id=?")->execute([$id]);
                it_hareket_ekle($pdoIt, $id, 'servis', $kisi, $acik ?: 'Servise gönderildi', $tarih);
                flash('success', 'Servise gönderildi olarak işaretlendi.');
                break;
            case 'donus':
                $pdoIt->prepare("UPDATE it_cihazlar SET durum=IF(zimmetli IS NULL OR zimmetli='','depoda','aktif') WHERE id=?")->execute([$id]);
                it_hareket_ekle($pdoIt, $id, 'donus', $kisi, $acik ?: 'Servisten döndü', $tarih);
                flash('success', 'Servisten döndü; durum güncellendi.');
                break;
            case 'ariza':
                $pdoIt->prepare("UPDATE it_cihazlar SET durum='arizali' WHERE id=?")->execute([$id]);
                it_hareket_ekle($pdoIt, $id, 'ariza', $kisi, $acik ?: 'Arıza bildirildi', $tarih);
                flash('success', 'Arıza kaydı eklendi.');
                break;
            // Envanterden düşüren üç işlem aynı kalıptadır: zimmet düşer, kayıt SİLİNMEZ,
            // varsayılan listelerde ve mali değerde görünmez (durum filtresiyle geri gelir).
            case 'hurda':
            case 'kayip':
            case 'hibe':
                $__ad = ['hurda'=>'Hurdaya ayrıldı', 'kayip'=>'Kayıp / çalıntı bildirildi', 'hibe'=>'Hibe / devir edildi'][$tur];
                $pdoIt->prepare("UPDATE it_cihazlar SET durum=?, personel_id=NULL, zimmetli=NULL, zimmet_tarihi=NULL WHERE id=?")
                      ->execute([$tur, $id]);
                it_hareket_ekle($pdoIt, $id, $tur, $kisi, $acik ?: $__ad, $tarih);
                flash('success', $__ad . ' — kayıt silinmez, listede gizlenir (durum filtresiyle görüntülenir).');
                break;
            default:
                it_hareket_ekle($pdoIt, $id, 'not', $kisi, $acik ?: '—', $tarih);
                flash('success', 'Not eklendi.');
        }
    } elseif ($islem === 'belge' && $girebilir) {
        $ok = 0; $hatalar = [];
        foreach (it_dosya_listesi($_FILES['belge'] ?? []) as $d) {
            if (($d['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            [$b, $m] = it_belge_yukle($pdoIt, $id, $d, $kul);
            if ($b) $ok++; else $hatalar[] = $m;
        }
        if ($ok) flash('success', $ok . ' dosya yüklendi.');
        if ($hatalar) flash('error', strip_tags(implode(' · ', $hatalar)));
        if (!$ok && !$hatalar) flash('error', 'Dosya seçilmedi.');
    } elseif ($islem === 'belge_sil' && $duzenleyebilir) {
        it_belge_sil($pdoIt, (int)($_POST['belge_id'] ?? 0));
        flash('success', 'Belge silindi.');
    } elseif ($islem === 'hareket_sil' && $duzenleyebilir) {
        $pdoIt->prepare("DELETE FROM it_hareketler WHERE id=? AND cihaz_id=?")->execute([(int)($_POST['hareket_id'] ?? 0), $id]);
        flash('success', 'Hareket satırı silindi.');
    } else {
        flash('error', 'Bu işlem için yetkiniz yok.');
    }
    redirect('cihaz_detay.php?id=' . $id);
}

$hst = $pdoIt->prepare("SELECT * FROM it_hareketler WHERE cihaz_id=? ORDER BY tarih DESC, id DESC");
$hst->execute([$id]);
$hareketler = $hst->fetchAll();
$belgeler   = it_belgeler($pdoIt, $id);
$gk = it_garanti_kalan($c['garanti_bitis']);

// Aynı kişinin diğer cihazları (zimmet tutanağında bir arada çıkar)
$digerleri = [];
if ($c['personel_id']) {
    $q = $pdoIt->prepare("SELECT id, envanter_no, ad, kategori, durum FROM it_cihazlar WHERE personel_id=? AND id<>? AND " . it_envanterde() . " ORDER BY envanter_no");
    $q->execute([(int)$c['personel_id'], $id]);
} elseif ($c['zimmetli']) {
    $q = $pdoIt->prepare("SELECT id, envanter_no, ad, kategori, durum FROM it_cihazlar WHERE zimmetli=? AND id<>? AND " . it_envanterde() . " ORDER BY envanter_no");
    $q->execute([$c['zimmetli'], $id]);
    $digerleri = $q->fetchAll();
}

$pageTitle = $c['envanter_no'] . ' — IT Envanter';
require_once __DIR__ . '/../includes/header.php';
$bilgi = fn($e, $v, $mono = false) => '<div class="col-sm-6 col-lg-4"><div class="small text-muted">' . h($e) . '</div><div class="fw-semibold' . ($mono ? ' font-monospace' : '') . '">' . ($v !== '' && $v !== null ? h((string)$v) : '—') . '</div></div>';
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a href="cihazlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi <?= h(it_kategoriIkon($c['kategori'])) ?> text-primary me-2"></i><?= h($c['ad']) ?></h4>
    <span class="badge bg-light text-dark border font-monospace"><?= h($c['envanter_no']) ?></span>
    <?= it_durumBadge($c['durum']) ?>
    <?php if ($gk !== null && $gk < 0): ?><span class="badge bg-light text-danger border">garanti bitti</span>
    <?php elseif ($gk !== null && $gk <= 60): ?><span class="badge bg-warning text-dark">garanti <?= $gk ?> gün</span><?php endif; ?>
    <div class="ms-auto d-flex gap-2">
        <?php if ($c['zimmetli']): ?><a href="zimmet_tutanak.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Zimmet Tutanağı</a><?php endif; ?>
        <?php if ($duzenleyebilir): ?><a href="cihaz_form.php?id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Düzenle</a><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="row g-3">
            <?= $bilgi('Kategori', it_kategoriAd($c['kategori'])) ?>
            <?= $bilgi('Marka / Model', trim(($c['marka'] ?? '') . ' ' . ($c['model'] ?? ''))) ?>
            <?= $bilgi('Seri No', $c['seri_no'], true) ?>
            <div class="col-sm-6 col-lg-4"><div class="small text-muted">Zimmetli</div><div class="fw-semibold"><?= $c['personel_id'] ? '<a href="personel_detay.php?id=' . (int)$c['personel_id'] . '">' . h($c['zimmetli']) . '</a>' : h($c['zimmetli'] ?: '—') ?></div></div>
            <?= $bilgi('Departman', $c['departman']) ?>
            <?= $bilgi('Lokasyon / Proje', $c['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$c['lokasyon_id']) : $c['lokasyon']) ?>
            <?= $bilgi('Zimmet Tarihi', $c['zimmet_tarihi'] ? format_date($c['zimmet_tarihi']) : null) ?>
            <?= $bilgi('Alış Tarihi', $c['alis_tarihi'] ? format_date($c['alis_tarihi']) : null) ?>
            <?= $bilgi('Garanti Bitiş', $c['garanti_bitis'] ? format_date($c['garanti_bitis']) . ($gk !== null ? ($gk < 0 ? ' (bitti)' : ' (' . $gk . ' gün)') : '') : null) ?>
            <?= $bilgi('Fiyat', $c['fiyat'] !== null ? $f2($c['fiyat']) . ' TL' : null) ?>
            <?= $bilgi('Tedarikçi', $c['tedarikci']) ?>
            <?= $bilgi('Fatura No', $c['fatura_no']) ?>
            <?php if ($c['kategori'] === 'yazilim'): ?>
            <?= $bilgi('Lisans Anahtarı', $c['lisans_anahtari'], true) ?>
            <?= $bilgi('Lisans Adedi', $c['lisans_adet']) ?>
            <?php else: ?>
            <?= $bilgi('Varlık / Nesne No (IFS)', $c['varlik_kodu'] ?? null, true) ?>
            <?= $bilgi('Şasi No / 2. Seri No', $c['sasi_no'] ?? null, true) ?>
            <?= $bilgi('IMEI', $c['imei'] ?? null, true) ?>
            <?= $bilgi('IP Adresi', $c['ip_adresi'], true) ?>
            <?= $bilgi('MAC Adresi', $c['mac_adresi'], true) ?>
            <?= $bilgi('İşletim Sistemi', $c['isletim_sistemi']) ?>
            <?= $bilgi('Özellikler', $c['ozellikler']) ?>
            <?php endif; ?>
            <?php
            // Cihaz tipine özel alanlar (IP telefon dahilisi, superbox IMEI'si, NVR disk kapasitesi,
            // kameranın bağlı olduğu NVR…) — yalnız DOLU olanlar gösterilir.
            foreach (it_ek_alanlar((string)$c['kategori']) as $__ea => [$__eEt, $__eTip, $__eIp]):
                $__ev = $c[$__ea] ?? null;
                if ($__ev === null || $__ev === '') continue;
                if ($__eTip === 'sifre') {
                    // Yönetim şifresi yalnız "değiştirme" yetkisi olana, tıklayınca açılan alanda
                    if (!yetki_var('duzenle')) continue;
                    echo '<div class="col-sm-6 col-lg-4"><div class="small text-muted">' . h($__eEt) . '</div>'
                       . '<div class="fw-semibold font-monospace"><span class="it-sifre" data-s="' . h((string)$__ev) . '">'
                       . '<a href="#" class="text-decoration-none small" onclick="this.parentNode.textContent=this.parentNode.dataset.s;return false">'
                       . '<i class="bi bi-eye me-1"></i>göster</a></span></div></div>';
                    continue;
                }
                if ($__eTip === 'cihaz') {
                    $__b = null;
                    try { $__q = $pdoIt->prepare("SELECT id, envanter_no, ad FROM it_cihazlar WHERE id=?"); $__q->execute([(int)$__ev]); $__b = $__q->fetch(); } catch (Throwable $e) {}
                    echo '<div class="col-sm-6 col-lg-4"><div class="small text-muted">' . h($__eEt) . '</div><div class="fw-semibold">'
                       . ($__b ? '<a href="cihaz_detay.php?id=' . (int)$__b['id'] . '">' . h(trim($__b['envanter_no'] . ' · ' . $__b['ad'])) . '</a>' : '—')
                       . '</div></div>';
                    continue;
                }
                echo $bilgi($__eEt, $__ev, in_array($__ea, ['imei','dahili_no','telefon_no'], true));
            endforeach; ?>
            <?= $bilgi('Kaydeden', $c['olusturan']) ?>
            <?= $bilgi('Kayıt', $c['created_at'] ? date('d.m.Y H:i', strtotime($c['created_at'])) : null) ?>
        </div>
        <?php if ($c['notlar']): ?>
        <div class="alert alert-light border mt-3 mb-0" style="white-space:pre-wrap"><?= h($c['notlar']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex align-items-center">
        <strong><i class="bi bi-clock-history me-1"></i>Yaşam Günlüğü</strong>
        <span class="badge bg-secondary ms-2"><?= count($hareketler) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.85rem">
          <thead class="table-light"><tr><th>Tarih</th><th>İşlem</th><th>Kişi / Firma</th><th>Açıklama</th><th>Kaydeden</th><th></th></tr></thead>
          <tbody>
          <?php if (!$hareketler): ?><tr><td colspan="6" class="text-center text-muted py-3">Hareket yok.</td></tr><?php endif; ?>
          <?php foreach ($hareketler as $hr): $hx = IT_HAREKET[$hr['tur']] ?? [$hr['tur'], 'secondary', 'bi-dot']; ?>
            <tr>
              <td class="text-nowrap"><?= format_date($hr['tarih']) ?></td>
              <td><span class="badge bg-<?= $hx[1] ?><?= $hx[1] === 'light' || $hx[1] === 'warning' ? ' text-dark' : '' ?>"><i class="bi <?= $hx[2] ?> me-1"></i><?= h($hx[0]) ?></span></td>
              <td><?= h($hr['kisi'] ?: '—') ?></td>
              <td style="white-space:pre-wrap"><?= h($hr['aciklama'] ?: '—') ?></td>
              <td class="small text-muted"><?= h($hr['kullanici'] ?: '—') ?></td>
              <td class="text-end">
                <?php if ($duzenleyebilir): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Bu hareket satırı silinsin mi? Cihazın durumu değişmez.')">
                  <input type="hidden" name="action" value="hareket_sil"><input type="hidden" name="hareket_id" value="<?= (int)$hr['id'] ?>">
                  <button class="btn btn-sm btn-link text-danger p-0" title="Sil"><i class="bi bi-x"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($digerleri): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white small"><i class="bi bi-person me-1"></i><strong><?= h($c['zimmetli']) ?></strong> üzerindeki diğer cihazlar <span class="badge bg-secondary ms-1"><?= count($digerleri) ?></span>
        <a href="<?= $c['personel_id'] ? 'zimmet_tutanak.php?personel_id=' . (int)$c['personel_id'] : 'zimmet_tutanak.php?kisi=' . urlencode($c['zimmetli']) ?>" target="_blank" class="ms-2"><i class="bi bi-file-earmark-text"></i> Kişi bazlı toplu zimmet tutanağı</a></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.85rem">
        <tbody><?php foreach ($digerleri as $d): ?>
          <tr><td class="font-monospace"><a href="cihaz_detay.php?id=<?= (int)$d['id'] ?>"><?= h($d['envanter_no']) ?></a></td><td><?= h($d['ad']) ?></td><td><?= h(it_kategoriAd($d['kategori'])) ?></td><td><?= it_durumBadge($d['durum']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <?php if ($duzenleyebilir): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white"><strong><i class="bi bi-lightning-charge me-1"></i>İşlem</strong></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="action" value="hareket">
          <div class="mb-2">
            <select name="tur" id="tur" class="form-select form-select-sm">
              <?php if (!it_durum_dustu($c['durum'])): ?>
              <option value="zimmet"><?= $c['zimmetli'] ? 'Zimmeti başkasına devret' : 'Zimmet ver' ?></option>
              <?php if ($c['zimmetli']): ?><option value="iade">Zimmet iade al (depoya)</option><?php endif; ?>
              <?php if ($c['durum'] !== 'serviste'): ?><option value="servis">Servise gönder</option><?php else: ?><option value="donus">Servisten döndü</option><?php endif; ?>
              <option value="ariza">Arıza bildir</option>
              <option value="kayip">Kayıp / çalıntı bildir</option>
              <option value="hibe">Hibe et / devret</option>
              <option value="hurda">Hurdaya ayır</option>
              <?php endif; ?>
              <option value="not">Not ekle</option>
            </select>
          </div>
          <div class="mb-2 zimmet-ek"><select name="personel_id" id="personel_id" class="form-select form-select-sm"><?= it_personel_options($pdoIt, 0) ?></select>
            <div class="form-text">Listede yoksa <a href="personel_form.php" target="_blank">personel ekleyin</a>.</div></div>
          <div class="mb-2 kisi"><input name="kisi" class="form-control form-control-sm" placeholder="Servis firması / kişi" value=""></div>
          <div class="row g-2 mb-2 zimmet-ek">
            <div class="col-6"><input name="departman" id="departman" class="form-control form-control-sm" placeholder="Departman (boş = kişinin birimi)" value=""></div>
            <div class="col-6"><select name="lokasyon_id" id="lokasyon_id" class="form-select form-select-sm"><option value="">Lokasyon: kişininki</option><?= it_lokasyon_options($pdoIt, 0, false) ?></select></div>
          </div>
          <div class="mb-2"><input type="date" name="tarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
          <div class="mb-2"><textarea name="aciklama" rows="2" class="form-control form-control-sm" placeholder="Açıklama"></textarea></div>
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check2 me-1"></i>Kaydet</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white"><strong><i class="bi bi-paperclip me-1"></i>Belgeler &amp; Fotoğraflar</strong> <span class="badge bg-secondary ms-1"><?= count($belgeler) ?></span></div>
      <div class="card-body">
        <?php if ($girebilir): ?>
        <form method="post" enctype="multipart/form-data" class="mb-3">
          <input type="hidden" name="action" value="belge">
          <input type="file" name="belge[]" multiple accept="image/*,application/pdf" class="form-control form-control-sm mb-2">
          <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-cloud-arrow-up me-1"></i>Yükle (fotoğraf, fatura, garanti belgesi)</button>
        </form>
        <?php endif; ?>
        <?php if (!$belgeler): ?><div class="text-muted small">Belge yok.</div><?php endif; ?>
        <div class="row g-2">
        <?php foreach ($belgeler as $b): $img = str_starts_with((string)$b['mime'], 'image/'); ?>
          <div class="col-6">
            <div class="border rounded p-1 h-100 d-flex flex-column">
              <a href="../<?= h($b['dosya_url']) ?>" target="_blank" class="d-block text-center">
                <?php if ($img): ?><img src="../<?= h($b['dosya_url']) ?>" alt="" style="width:100%;height:90px;object-fit:cover;border-radius:4px">
                <?php else: ?><div class="py-4"><i class="bi bi-file-earmark-pdf text-danger fs-2"></i></div><?php endif; ?>
              </a>
              <div class="small text-truncate mt-1" title="<?= h($b['ad']) ?>"><?= h($b['ad']) ?></div>
              <div class="d-flex justify-content-between align-items-center small text-muted">
                <span><?= format_date(substr((string)$b['created_at'], 0, 10)) ?></span>
                <?php if ($duzenleyebilir): ?>
                <form method="post" onsubmit="return confirm('Belge silinsin mi?')"><input type="hidden" name="action" value="belge_sil"><input type="hidden" name="belge_id" value="<?= (int)$b['id'] ?>">
                  <button class="btn btn-link btn-sm text-danger p-0"><i class="bi bi-trash"></i></button></form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    var t = document.getElementById('tur'); if (!t) return;
    function uygula() {
        var z = t.value === 'zimmet';
        document.querySelectorAll('.zimmet-ek').forEach(function (e) { e.classList.toggle('d-none', !z); });
        document.querySelector('.kisi').classList.toggle('d-none', z);
        var k = document.querySelector('.kisi input');
        k.placeholder = { servis: 'Servis firması',
                          hibe:   'Hibe edilen kurum / kişi',
                          kayip:  'Kaybı bildiren kişi' }[t.value] || 'Kişi / firma (isteğe bağlı)';
        // Envanterden düşüren işlemlerde sebep yazılması beklenir
        var a = document.querySelector('textarea[name="aciklama"]');
        if (a) a.placeholder = { kayip: 'Nerede/ne zaman kaybolduğu, tutanak no…',
                                 hibe:  'Hibe/devir gerekçesi, protokol no…',
                                 hurda: 'Hurdaya ayırma sebebi' }[t.value] || 'Açıklama (isteğe bağlı)';
        document.getElementById('personel_id').required = z;
    }
    t.addEventListener('change', uygula); uygula();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
