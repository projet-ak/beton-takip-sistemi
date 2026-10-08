<?php
/**
 * it/zimmet_tutanak.php — ZİMMET TUTANAĞI (A4, ERN Taahhüt logolu)
 *   ?id=…    tek cihaz
 *   ?kisi=…  kişinin üzerindeki TÜM aktif cihazlar tek tutanakta
 * Tarayıcıdan Yazdır → "PDF olarak kaydet"; imzalı kopya cihaz kartına belge olarak yüklenir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/_tutanak.php';   // ortak A4 yazdırma katmanı (antet/sayfa/ölçüler)
it_semasi_kur($pdoIt);

$id   = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$kisi = trim((string)($_GET['kisi'] ?? ''));
// ?hareket= → GEÇMİŞ bir zimmet dönemi için tutanak (cihaz 5-6 kez el değiştirdiğinde her dönemin
// kendi formu yazdırılabilsin). Kişi/tarih o hareketten gelir, cihazın GÜNCEL zimmetlisinden değil.
$hid = isset($_GET['hareket']) && ctype_digit((string)$_GET['hareket']) ? (int)$_GET['hareket'] : 0;
$hrk = null;
if ($hid) {
    $hs = $pdoIt->prepare("SELECT * FROM it_hareketler WHERE id=? AND tur='zimmet'"); $hs->execute([$hid]);
    $hrk = $hs->fetch() ?: null;
    if (!$hrk) die('Zimmet hareketi bulunamadı.');
    $id = (int)$hrk['cihaz_id'];
}
$pid  = (int)($_GET['personel_id'] ?? 0);
$per  = null;
if ($pid) {
    $per = it_personel_bul($pdoIt, $pid);
    if (!$per) die('Personel bulunamadı.');
    $liste = it_personel_cihazlari($pdoIt, $pid);
    if (!$liste) die('Bu personele zimmetli cihaz yok.');
    $kisi = it_personel_ad($per);
    $no = 'ZMT-P' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT) . '-' . date('Ymd');
    $geri = 'personel_detay.php?id=' . $pid;
} elseif ($id) {
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE id=?"); $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) die('Cihaz bulunamadı.');
    $kisi = $hrk ? (string)$hrk['kisi'] : (string)$c['zimmetli'];
    $per  = it_personel_bul($pdoIt, (int)($c['personel_id'] ?? 0));
    if ($hrk && $per && it_norm(it_personel_ad($per)) !== it_norm($kisi)) $per = null;  // geçmiş dönem: kişi kartı başkasınınki olabilir
    $liste = [$c];
    $no = 'ZMT-' . $c['envanter_no'] . ($hrk ? '-D' . (int)$hrk['id'] : '');
    $geri = 'cihaz_detay.php?id=' . $id;
} elseif ($kisi !== '') {
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE zimmetli=? AND " . it_envanterde() . " ORDER BY envanter_no"); $st->execute([$kisi]);
    $liste = $st->fetchAll();
    if (!$liste) die('Bu kişiye zimmetli cihaz yok.');
    $no = 'ZMT-' . strtoupper(substr(md5(it_norm($kisi)), 0, 6)) . '-' . date('Ymd');
    $geri = 'cihazlar.php?zimmetli=' . urlencode($kisi);
} else { die('Cihaz ya da kişi belirtilmedi.'); }

$ilk = $liste[0];
$departman = $per['birim'] ?? ($ilk['departman'] ?: '');
$lokasyon  = $per && $per['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$per['lokasyon_id']) : ($ilk['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$ilk['lokasyon_id']) : ($ilk['lokasyon'] ?: ''));
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
$toplam = array_sum(array_map('it_mali_deger', $liste));   // TL karşılığı — döviz tutarları toplanamaz
$teslimEden = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? '';
$maliGoster = it_mali_goster();      // "Değer (TL)" sütunu (varsayılan KAPALI)

// ── İMZALI EVRAK: tutanağı yazdır → ıslak imzala → buradan geri yükle ──────────
// Belge tutanaktaki BÜTÜN cihazlara bağlanır (kişi bazlı tutanakta tek dosya = tüm cihazlar),
// depo modülündeki "imzalı fişi geri yükle" deseniyle aynı.
$evrakMesaj = null; $evrakHata = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'imzali' && yetki_var('giris')) {
    $kul = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
    $ok = 0; $hatalar = [];
    $ilkId = (int)$liste[0]['id'];
    foreach (it_dosya_listesi($_FILES['belge'] ?? []) as $d) {
        if (($d['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        // ⚠ Yüklenen dosya diske BİR KEZ taşınır (ilk cihazın klasörüne); tutanaktaki diğer cihazlara
        // aynı dosya URL'siyle bağ satırı eklenir. it_belge_sil() dosyayı yalnız SON bağ koptuğunda siler.
        // Belge, cihazın İLGİLİ ZİMMET DÖNEMİNE bağlanır (hareket_id) — el değiştirme zincirinde
        // hangi dönemin tutanağı olduğu böyle bilinir.
        $__hz = $hrk ?: it_son_hareket($pdoIt, $ilkId, 'zimmet');
        [$b, $m] = it_belge_yukle($pdoIt, $ilkId, $d, $kul, 'zimmet', (int)($__hz['id'] ?? 0) ?: null);
        if (!$b) { $hatalar[] = $m; continue; }
        $ok++;
        $son = $pdoIt->prepare("SELECT * FROM it_belgeler WHERE cihaz_id=? ORDER BY id DESC LIMIT 1");
        $son->execute([$ilkId]);
        $bg = $son->fetch();
        foreach ($liste as $__c) {
            $cid = (int)$__c['id'];
            if ($cid !== $ilkId && $bg) {
                $__hzc = it_son_hareket($pdoIt, $cid, 'zimmet');
                $pdoIt->prepare("INSERT INTO it_belgeler (cihaz_id, dosya_url, ad, mime, boyut, tur, hareket_id, kullanici) VALUES (?,?,?,?,?, 'zimmet', ?, ?)")
                      ->execute([$cid, $bg['dosya_url'], $bg['ad'], $bg['mime'], $bg['boyut'], (int)($__hzc['id'] ?? 0) ?: null, $kul]);
            }
            it_hareket_ekle($pdoIt, $cid, 'not', $__c['zimmetli'] ?: null, 'İmzalı zimmet tutanağı yüklendi (' . $no . ').');
        }
    }
    if ($ok) $evrakMesaj = 'İmzalı tutanak yüklendi.';
    if ($hatalar) $evrakHata = strip_tags(implode(' · ', array_unique($hatalar)));
    if (!$ok && !$hatalar) $evrakHata = 'Dosya seçilmedi.';
}
$imzaliSayi = 0;
foreach ($liste as $__c) $imzaliSayi += count(array_filter(it_belgeler($pdoIt, (int)$__c['id']), fn($b) => ($b['tur'] ?? '') === 'zimmet'));
$tek = count($liste) === 1;
?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title>Zimmet Tutanağı <?= h($no) ?></title>
<?php /* Ortak A4 katmanı (_tutanak.php): antet her sayfada tekrar eder, @page margin:0 tarayıcının
         kendi üstbilgisini keser, yazdırmada .sheet min-height sıfırlanır (yoksa tek cihazlık belge
         bile 2 sayfa çıkıyordu) ve .evrak paneli basılmaz. Aşağısı yalnız bu sayfaya özel. */ ?>
<?php tut_stil('
  .kunye td.k { width:23%; white-space:nowrap; }
'); ?>
</head>
<body>
<div class="toolbar">
  <button class="btn-print" onclick="window.print()">🖨 Yazdır / PDF Kaydet</button>
  <a class="btn-back" href="<?= h($geri) ?>">← Geri</a>
</div>

<?php if ($hrk): ?>
<div class="evrak" style="border-left-color:#0d6efd">
  <div class="basi" style="color:#0d6efd">Geçmiş zimmet dönemi</div>
  <div style="color:#555">Bu tutanak <strong><?= h($kisi) ?></strong> adlı personelin
    <strong><?= format_date($hrk['tarih']) ?></strong> tarihli zimmet dönemi içindir
    (cihazın güncel zimmetlisi farklı olabilir). Yüklenecek imzalı kopya bu döneme işlenir.</div>
</div>
<?php endif; ?>

<?php if (yetki_var('giris')): ?>
<div class="evrak">
  <div class="basi">İmzalı Evrak
    <?php if ($imzaliSayi): ?><span class="rozet var">yüklü (<?= (int)$imzaliSayi ?>)</span>
    <?php else: ?><span class="rozet yok">yüklenmedi</span><?php endif; ?></div>
  <div style="color:#555">Tutanağı yazdırıp imzalattıktan sonra taranmış kopyayı buradan yükleyin —
    belge tutanaktaki <?= count($liste) ?> cihazın kartına da işlenir ve listede yeşil rozetle görünür.</div>
  <?php if ($evrakMesaj): ?><div class="tamam"><?= h($evrakMesaj) ?></div><?php endif; ?>
  <?php if ($evrakHata): ?><div class="uyari"><?= h($evrakHata) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="imzali">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="file" name="belge[]" multiple accept="image/*,application/pdf" required>
    <button type="submit">İmzalı tutanağı yükle</button>
  </form>
</div>
<?php endif; ?>

<?php
$__zTarih = $hrk ? format_date($hrk['tarih']) : ($ilk['zimmet_tarihi'] ? format_date($ilk['zimmet_tarihi']) : date('d.m.Y'));
tut_antet([
    'meta' => [
        'Tutanak No'    => '<span style="color:#00584E">' . h($no) . '</span>',
        'Zimmet Tarihi' => h($__zTarih),
        'Düzenleme'     => date('d.m.Y'),
    ],
    'alt' => 'ERN Taahhüt — Bilgi İşlem Envanter Sistemi · ' . $no . ' · ' . date('d.m.Y H:i'),
]);
?>

  <div class="doc-title">BİLGİ İŞLEM DEMİRBAŞ ZİMMET TUTANAĞI</div>
  <div class="doc-no"><?= h($no) ?></div>

  <table class="info">
    <tr><td class="k">Zimmet Alan</td><td><strong><?= h($kisi ?: '—') ?></strong><?= $per && $per['unvan'] ? ' — ' . h($per['unvan']) : '' ?></td>
        <td class="k">Sicil No</td><td><?= h($per['sicil_no'] ?? '') ?: '—' ?></td></tr>
    <tr><td class="k">Birim / Departman</td><td><?= h($departman ?: '—') ?></td>
        <td class="k">Telefon</td><td><?= h($per['telefon'] ?? '') ?: '—' ?></td></tr>
    <tr><td class="k">Lokasyon / Proje</td><td><?= h($lokasyon ?: '—') ?></td>
        <td class="k">Zimmet Tarihi</td><td><?= h($__zTarih) ?></td></tr>
    <?php if ($per && $per['ise_giris']): ?><tr><td class="k">İşe Giriş</td><td><?= format_date($per['ise_giris']) ?></td><td class="k">Cihaz adedi</td><td><?= count($liste) ?></td></tr><?php endif; ?>
  </table>

  <div class="sec">ZİMMETLE TESLİM EDİLEN CİHAZ<?= $tek ? '' : 'LAR' ?></div>
  <table class="items">
    <thead><tr><th style="width:26px">S.No</th><th style="width:78px">Envanter No</th><th>Cihaz</th><th>Marka / Model</th><th style="width:100px">Seri No</th><?php if ($maliGoster): ?><th class="r" style="width:88px">Değer (TL)</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($liste as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td class="mono"><?= h($r['envanter_no']) ?><?php if (!empty($r['cihaz_kodu'])): ?><div class="alt"><?= h($r['cihaz_kodu']) ?></div><?php endif; ?></td>
        <?php /* Kategori adı cihaz adıyla aynıysa tekrar yazılmaz ("Dizüstü BilgisayarDizüstü Bilgisayar") */ ?>
        <?php $__kat = it_kategoriAd((string)$r['kategori']); $__katAyri = it_norm($__kat) !== it_norm((string)$r['ad']); ?>
        <td><?= h($r['ad']) ?><div class="alt"><?= $__katAyri ? h($__kat) : '' ?><?= $r['ozellikler'] ? ($__katAyri ? ' · ' : '') . h($r['ozellikler']) : '' ?></div></td>
        <td><?= h(trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? '')) ?: '—') ?></td>
        <td class="mono"><?= h($r['seri_no'] ?: '—') ?></td>
        <?php if ($maliGoster): ?><td class="r"><?= $r['fiyat'] !== null ? h(it_para_yaz($r['fiyat'], $r['para_birimi'] ?? 'TRY')) : '—' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php /* Tek cihazda "TOPLAM (1 kalem)" satırı yer israfı — yalnız çok kalemde ya da mali
             sütun açıkken basılır. */ ?>
    <?php if (!$tek || $maliGoster): ?>
    <tfoot><tr><td colspan="5" class="r">TOPLAM (<?= count($liste) ?> kalem)</td><?php if ($maliGoster): ?><td class="r"><?= $f2($toplam) ?></td><?php endif; ?></tr></tfoot>
    <?php endif; ?>
  </table>

  <?php /* Kurumsal formdaki "Özellikler" bloğu — her cihaz için nesne kimliği + donanım künyesi */ ?>
  <?php foreach ($liste as $i => $r): $ky = it_kunye($pdoIt, $r); if (!$ky) continue; ?>
  <div class="blok">
    <div class="sec"><?= $tek ? '' : ($i + 1) . '. ' ?>ÖZELLİKLER — <?= h($r['ad']) ?>
      <span style="font-weight:600;color:#666">(<?= h($r['envanter_no']) ?><?= !empty($r['varlik_kodu']) ? ' · IFS: ' . h($r['varlik_kodu']) : '' ?>)</span></div>
    <table class="kunye">
      <tr><td class="k">NESNE AÇIKLAMA</td><td><?= h($r['ad']) ?></td>
          <td class="k">NESNE TÜRÜ / KATEGORİ</td><td><?= h(IT_GRUP[it_grup((string)$r['kategori'])][0] ?? '') ?> / <?= h(it_kategoriAd((string)$r['kategori'])) ?></td></tr>
      <?php foreach (array_chunk($ky, 2, true) as $cift): ?>
      <tr>
        <?php foreach ($cift as $et => $dg): ?>
          <td class="k"><?= h($et) ?></td><td class="<?= in_array($et, ['ŞASİ NO / SERİ NO','IMEI','IP / MAC','CİHAZ KODU','IFS SERİ NESNE NO','ENVANTER NO'], true) ? 'mono' : '' ?>"><?= h($dg) ?></td>
        <?php endforeach; ?>
        <?php if (count($cift) === 1): ?><td class="k"></td><td></td><?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php if (!empty($r['notlar'])): ?><tr><td class="k">NOTLAR</td><td colspan="3" style="white-space:pre-wrap"><?= h(mb_substr((string)$r['notlar'], 0, 400)) ?></td></tr><?php endif; ?>
    </table>
  </div>
  <?php endforeach; ?>

  <div class="blok">
    <div class="sec">KULLANICI BİLGİLERİ</div>
    <table class="kunye">
      <tr><td class="k">AD SOYAD</td><td><strong><?= h($kisi ?: '—') ?></strong></td>
          <td class="k">SİCİL NO</td><td class="mono"><?= h($per['sicil_no'] ?? '') ?: '—' ?></td></tr>
      <tr><td class="k">MAİL ADRESİ</td><td><?= h($per['eposta'] ?? '') ?: '—' ?></td>
          <?php /* Şirket hattı + masa telefonunun kısa kodu: tutanaktan kişiye ulaşılabilsin */ ?>
          <td class="k">TELEFON</td><td><?= h($per['telefon'] ?? '') ?: '—' ?><?php
              $__dh = trim((string)($per['dahili'] ?? '')); echo $__dh !== '' ? ' · dahili ' . h($__dh) : ''; ?></td></tr>
      <tr><td class="k">BİRİM / UNVAN</td><td><?= h(trim(($departman ?: '') . ($per && $per['unvan'] ? ' — ' . $per['unvan'] : ''), ' —')) ?: '—' ?></td>
          <td class="k">LOKASYON</td><td><?= h($lokasyon ?: '—') ?></td></tr>
    </table>
  </div>

  <?php /* Kapanış beyanı ve imzalar BİRLİKTE kalır — imza bloğu tek başına son sayfaya düşerse
           belge yarıda kesilmiş gibi görünür. */ ?>
  <div class="kapanis">
  <div class="note">
    Yukarıda envanter numarası, tanımı ve seri numarası belirtilen bilgi işlem demirbaş(lar)ı, çalışır ve eksiksiz
    durumda <strong><?= h($kisi ?: '—') ?></strong> adlı personele iş amaçlı kullanılmak üzere zimmetle teslim edilmiştir.
    Zimmet alan personel;
    <ol>
      <li>Cihazı yalnız işle ilgili amaçlarla, özenle ve kurumsal bilgi güvenliği kurallarına uygun kullanmayı,</li>
      <li>Arıza, kayıp veya hasar durumunda derhal bilgi işlem birimine bildirmeyi,</li>
      <li>Kurumsal veri, hesap ve lisansları üçüncü kişilerle paylaşmamayı,</li>
      <li>Görevden ayrılma veya talep halinde cihazı aksesuarları ve verileriyle birlikte eksiksiz iade etmeyi</li>
    </ol>
    kabul ve taahhüt eder. İşbu tutanak iki nüsha düzenlenmiş; bir nüshası personele verilmiş, diğeri bilgi işlem arşivine alınmıştır.
  </div>

  <div class="signs">
    <div class="sign"><div class="line">TESLİM EDEN — ERN TAAHHÜT BİLGİ İŞLEM</div>
      <div class="ad"><?= h($teslimEden) ?></div><div class="sub">Adı Soyadı / Tarih / İmza</div></div>
    <div class="sign"><div class="line">TESLİM ALAN</div>
      <div class="ad"><?= h($kisi ?: '') ?></div><div class="sub">Adı Soyadı / Tarih / İmza</div></div>
  </div>
  </div><?php /* .kapanis */ ?>
<?php tut_kapat(); ?>
<?php tut_sayfa_js(); ?>
</body></html>
