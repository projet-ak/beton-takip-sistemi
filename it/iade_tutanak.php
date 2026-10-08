<?php
/**
 * it/iade_tutanak.php — BİLGİ İŞLEM DEMİRBAŞ İADE TUTANAĞI (A4, ERN Taahhüt logolu)
 *
 * Zimmetin KAPANIŞ belgesi. Cihaz el değiştirdikçe her dönemin kendi zimmet + İADE formu olur;
 * "kim, ne zaman, hangi durumda geri verdi" sorusunun cevabı budur.
 *
 *   ?id=…       cihazın SON iade hareketi
 *   ?hareket=…  belirli bir iade hareketi (geçmiş dönemin formu yeniden yazdırılabilir)
 *
 * İmzalı kopya geri yüklenir (`it_belgeler.tur='iade'`) ve o iade hareketine bağlanır.
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

$id  = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$hid = isset($_GET['hareket']) && ctype_digit((string)$_GET['hareket']) ? (int)$_GET['hareket'] : 0;

$hrk = null;
if ($hid) {
    $hs = $pdoIt->prepare("SELECT * FROM it_hareketler WHERE id=? AND tur='iade'"); $hs->execute([$hid]);
    $hrk = $hs->fetch() ?: null;
    if (!$hrk) die('İade hareketi bulunamadı.');
    $id = (int)$hrk['cihaz_id'];
}
if (!$id) die('Cihaz belirtilmedi.');
$st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE id=?"); $st->execute([$id]);
$c = $st->fetch();
if (!$c) die('Cihaz bulunamadı.');
if (!$hrk) $hrk = it_son_hareket($pdoIt, $id, 'iade');

// Teslim eden = iadeyi yapan personel; dönem başlangıcı için o iadeden ÖNCEKİ zimmet aranır
$iadeEden = trim((string)($hrk['kisi'] ?? '')) ?: (string)($c['zimmetli'] ?? '');
$tarih    = (string)($hrk['tarih'] ?? date('Y-m-d'));
$aciklama = trim((string)($hrk['aciklama'] ?? ''));
$zimmetBas = null;
try {
    $zs = $pdoIt->prepare("SELECT * FROM it_hareketler WHERE cihaz_id=? AND tur='zimmet' AND id < ? ORDER BY id DESC LIMIT 1");
    $zs->execute([$id, (int)($hrk['id'] ?? PHP_INT_MAX)]);
    $zimmetBas = $zs->fetch() ?: null;
} catch (Throwable $e) {}
$per = null;
foreach (it_personel_liste($pdoIt, false) as $p) {
    if (it_norm(it_personel_ad($p)) === it_norm($iadeEden)) { $per = $p; break; }
}
$no   = 'IAD-' . (($c['cihaz_kodu'] ?? '') !== '' ? $c['cihaz_kodu'] : $c['envanter_no'])
      . '-' . date('Ymd', strtotime($tarih));
$geri = 'cihaz_detay.php?id=' . $id;
$teslimAlan = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? '';
$lokasyon = $c['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$c['lokasyon_id']) : (string)($c['lokasyon'] ?? '');
$gun = null;
if ($zimmetBas) { try { $gun = max(0, (int)(new DateTimeImmutable($zimmetBas['tarih']))->diff(new DateTimeImmutable($tarih))->format('%r%a')); } catch (Throwable $e) {} }

// ── İMZALI EVRAK: yazdır → imzalat → tara → geri yükle (iade dönemine bağlanır) ──
$evrakMesaj = null; $evrakHata = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'imzali' && yetki_var('giris')) {
    $kul = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
    $ok = 0; $hatalar = [];
    foreach (it_dosya_listesi($_FILES['belge'] ?? []) as $d) {
        if (($d['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        [$b, $m] = it_belge_yukle($pdoIt, $id, $d, $kul, 'iade', (int)($hrk['id'] ?? 0) ?: null);
        if ($b) { $ok++; } else { $hatalar[] = $m; }
    }
    if ($ok) {
        it_hareket_ekle($pdoIt, $id, 'not', $iadeEden ?: null, 'İmzalı iade tutanağı yüklendi (' . $no . ').');
        $evrakMesaj = 'İmzalı iade tutanağı yüklendi.';
    }
    if ($hatalar) $evrakHata = strip_tags(implode(' · ', array_unique($hatalar)));
    if (!$ok && !$hatalar) $evrakHata = 'Dosya seçilmedi.';
}
$imzaliSayi = count(array_filter(it_belgeler($pdoIt, $id),
    fn($b) => ($b['tur'] ?? '') === 'iade' && (!$hrk || (int)($b['hareket_id'] ?? 0) === (int)$hrk['id'] || empty($b['hareket_id']))));
$kunye = it_kunye($pdoIt, $c);
?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title>İade Tutanağı <?= h($no) ?></title>
<?php /* Ortak A4 katmanı (_tutanak.php): her sayfada tekrar eden antet, @page margin:0 ile
         tarayıcı üstbilgisinin kesilmesi, yazdırmada .sheet min-height sıfırlaması (yoksa tek
         cihazlık belge bile 2 sayfa çıkıyordu) ve .evrak panelinin basılmaması. */ ?>
<?php tut_stil('
  .durum { display:flex; gap:14px; font-size:11px; margin:8px 0 4px; flex-wrap:wrap; }
  .durum span { border:1px solid #999; border-radius:3px; padding:3px 9px; }
  .kutu { display:inline-block; width:11px; height:11px; border:1px solid #333; margin-right:5px; vertical-align:-1px; }
'); ?>
</head>
<body>
<div class="toolbar">
  <button class="btn-print" onclick="window.print()">🖨 Yazdır / PDF Kaydet</button>
  <a class="btn-back" href="<?= h($geri) ?>">← Cihaz kartı</a>
  <?php if ($zimmetBas): ?><a class="btn-back" href="zimmet_tutanak.php?hareket=<?= (int)$zimmetBas['id'] ?>">Bu dönemin zimmet tutanağı</a><?php endif; ?>
</div>

<?php if (!$hrk): ?>
<div class="evrak" style="border-left-color:#c47f00">
  <div class="basi" style="color:#8a5a00">⚠ Bu cihazda iade hareketi yok</div>
  <div style="color:#555">Form boş alanlarla yazdırılabilir; kayda geçmesi için cihaz kartından
    <em>Zimmet iade al</em> işlemini uygulayın — tarih, kişi ve açıklama o kayıttan gelir.</div>
</div>
<?php endif; ?>

<?php if (yetki_var('giris')): ?>
<div class="evrak">
  <div class="basi">İmzalı İade Tutanağı
    <?php if ($imzaliSayi): ?><span class="rozet var">yüklü (<?= (int)$imzaliSayi ?>)</span>
    <?php else: ?><span class="rozet yok">yüklenmedi</span><?php endif; ?></div>
  <div style="color:#555">Tutanağı yazdırıp teslim eden ve teslim alana imzalattıktan sonra taranmış
    kopyayı buradan yükleyin — belge bu iade dönemine işlenir, cihaz kartındaki el değiştirme
    zincirinde yeşil görünür.</div>
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

<?php tut_antet([
    'meta' => [
        'Tutanak No'  => '<span style="color:#00584E">' . h($no) . '</span>',
        'İade Tarihi' => format_date($tarih),
        'Düzenleme'   => date('d.m.Y'),
    ],
    'alt' => 'ERN Taahhüt — Bilgi İşlem Envanter Sistemi · ' . $no . ' · ' . date('d.m.Y H:i'),
]); ?>

  <div class="doc-title">BİLGİ İŞLEM DEMİRBAŞ İADE TUTANAĞI</div>
  <div class="doc-no">Tutanak No: <?= h($no) ?></div>

  <table class="info">
    <tr><td class="k">İade Eden (teslim eden)</td><td><strong><?= h($iadeEden ?: '—') ?></strong><?= $per && $per['unvan'] ? ' — ' . h($per['unvan']) : '' ?></td>
        <td class="k">Sicil No</td><td><?= h($per['sicil_no'] ?? '—') ?></td></tr>
    <tr><td class="k">Birim / Departman</td><td><?= h($per['birim'] ?? ($c['departman'] ?: '—')) ?></td>
        <td class="k">Lokasyon / Proje</td><td><?= h($lokasyon ?: '—') ?></td></tr>
    <tr><td class="k">Zimmet Başlangıcı</td><td><?= $zimmetBas ? format_date($zimmetBas['tarih']) : '—' ?></td>
        <td class="k">Kullanım Süresi</td><td><?= $gun !== null ? $gun . ' gün' : '—' ?></td></tr>
    <tr><td class="k">Teslim Alan (Bilgi İşlem)</td><td><?= h($teslimAlan ?: '—') ?></td>
        <td class="k">Cihazın Yeni Durumu</td><td><?= h(it_durumAd((string)$c['durum'])) ?></td></tr>
    <?php if ($aciklama !== ''): ?>
    <tr><td class="k">Açıklama</td><td colspan="3"><?= h($aciklama) ?></td></tr>
    <?php endif; ?>
  </table>

  <div class="sec">İADE EDİLEN CİHAZ</div>
  <table class="items">
    <thead><tr><th style="width:28px">S.No</th><th style="width:80px">Cihaz Kodu</th><th style="width:120px">IFS Nesne No</th>
      <th>Cihaz</th><th style="width:100px">Marka / Model</th><th style="width:110px">Seri No</th></tr></thead>
    <tbody>
      <tr>
        <td>1</td>
        <td class="mono"><?= h(($c['cihaz_kodu'] ?? '') !== '' ? $c['cihaz_kodu'] : $c['envanter_no']) ?></td>
        <td class="mono"><?= h($c['varlik_kodu'] ?: '—') ?></td>
        <?php $__kat = it_kategoriAd((string)$c['kategori']); ?>
        <td><?= h($c['ad']) ?><?= it_norm($__kat) !== it_norm((string)$c['ad']) ? '<div class="alt">' . h($__kat) . '</div>' : '' ?></td>
        <td><?= h(trim(($c['marka'] ?? '') . ' ' . ($c['model'] ?? '')) ?: '—') ?></td>
        <td class="mono"><?= h($c['seri_no'] ?: '—') ?></td>
      </tr>
    </tbody>
  </table>

  <?php if ($kunye): ?>
  <div class="blok">
    <div class="sec">ÖZELLİKLER — <?= h($c['ad']) ?></div>
    <table class="kunye">
    <?php foreach (array_chunk($kunye, 2, true) as $cift): ?>
    <tr>
      <?php foreach ($cift as $et => $dg): ?>
        <td class="k"><?= h($et) ?></td><td class="<?= in_array($et, ['ŞASİ NO / SERİ NO','IMEI','IP / MAC','CİHAZ KODU'], true) ? 'mono' : '' ?>"><?= h($dg) ?></td>
      <?php endforeach; ?>
      <?php if (count($cift) === 1): ?><td class="k"></td><td></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <?php /* Teslim alınırken cihazın fiziksel durumu sahada elle işaretlenir */ ?>
  <div class="blok">
  <div class="sec">TESLİM ALMA KONTROLÜ (Bilgi İşlem doldurur)</div>
  <div class="durum">
    <span><i class="kutu"></i>Çalışır durumda</span>
    <span><i class="kutu"></i>Arızalı</span>
    <span><i class="kutu"></i>Aksesuarları eksiksiz</span>
    <span><i class="kutu"></i>Eksik aksesuar var</span>
    <span><i class="kutu"></i>Fiziksel hasar var</span>
    <span><i class="kutu"></i>Veriler silindi / temizlendi</span>
  </div>
  <table class="info" style="margin-top:6px">
    <tr><td class="k" style="width:22%">Eksik / hasar notu</td><td style="height:30px"></td></tr>
  </table>
  </div>

  <?php /* Kapanış beyanı ve imzalar BİRLİKTE kalır — imza bloğu tek başına son sayfaya
         düşerse belge yarıda kesilmiş gibi görünür. */ ?>
  <div class="kapanis">
  <div class="note">
    Yukarıda bilgileri verilen bilgi işlem cihazı, <strong><?= h($iadeEden ?: '—') ?></strong> tarafından
    <strong><?= format_date($tarih) ?></strong> tarihinde Bilgi İşlem birimine iade edilmiş ve teslim alınmıştır.
    <ol>
      <li>Cihaz, yukarıdaki teslim alma kontrolünde işaretlenen durumda teslim alınmıştır.</li>
      <li>Teslim alma ile birlikte personelin bu cihaza ilişkin zimmet sorumluluğu SONA ERER.</li>
      <li>Eksik aksesuar / hasar tespit edilmişse not alanında belirtilir ve ayrıca değerlendirilir.</li>
      <li>Bu tutanak, cihazın zimmet döneminin kapanış belgesidir; imzalı kopyası envantere yüklenir.</li>
    </ol>
  </div>

  <div class="signs">
    <div class="sign"><div class="line">İADE EDEN</div>
      <div class="sub"><?= h($iadeEden ?: '') ?><br>Ad Soyad / Tarih / İmza</div></div>
    <div class="sign"><div class="line">TESLİM ALAN — BİLGİ İŞLEM</div>
      <div class="sub"><?= h($teslimAlan ?: '') ?><br>Ad Soyad / Tarih / İmza</div></div>
  </div>
  </div><?php /* .kapanis */ ?>

<?php tut_kapat(); ?>
<?php tut_sayfa_js(); ?>
</body></html>
