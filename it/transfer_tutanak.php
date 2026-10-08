<?php
/**
 * it/transfer_tutanak.php — CİHAZ TRANSFER / SEVK TUTANAĞI (A4, ERN Taahhüt logolu)
 *
 * Bir cihazın BİR PROJEDEN İHTİYAÇ DUYAN BAŞKA PROJEYE gönderilmesinin belgesi
 * (ör. Batı Yakası → Hatay Projesi). Gönderen taraf yazdırır, iki taraf imzalar,
 * taranmış kopya buradan geri yüklenir (`it_belgeler.tur='transfer'`).
 *
 *   ?id=…   tek cihaz
 *   ?lok=…  o lokasyona TRANSFERDE olan tüm cihazlar tek tutanakta (aynı sevkiyat)
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
$lok = isset($_GET['lok']) && ctype_digit((string)$_GET['lok']) ? (int)$_GET['lok'] : 0;

if ($id) {
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE id=?"); $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) die('Cihaz bulunamadı.');
    $liste = [$c];
    $no    = 'TRF-' . ($c['cihaz_kodu'] ?: $c['envanter_no']) . '-' . date('Ymd');
    $geri  = 'cihaz_detay.php?id=' . $id;
} elseif ($lok) {
    // Aynı hedefe yolda olan cihazlar tek belgede — bir sevkiyat = bir tutanak
    $ids = it_lokasyon_altlar($pdoIt, $lok);
    $st  = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE durum='transfer' AND lokasyon_id IN ("
                           . implode(',', array_map('intval', $ids)) . ") ORDER BY cihaz_kodu, envanter_no");
    $st->execute();
    $liste = $st->fetchAll();
    if (!$liste) die('Bu lokasyona transferde cihaz yok.');
    $no    = 'TRF-L' . str_pad((string)$lok, 3, '0', STR_PAD_LEFT) . '-' . date('Ymd');
    $geri  = 'cihazlar.php?durum=transfer';
} else { die('Cihaz ya da hedef lokasyon belirtilmedi.'); }

$ilk     = $liste[0];
$hedef   = $ilk['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$ilk['lokasyon_id']) : (string)($ilk['lokasyon'] ?? '');
$hareket = it_transfer_son($pdoIt, (int)$ilk['id']);
$hTarih  = $hareket['tarih'] ?? date('Y-m-d');
// Hareket açıklaması SABİT biçimdedir: "Sevk: <kaynak> → <hedef> · gönderen: X · isteyen/teslim alacak: Y · not"
$acikMetin = (string)($hareket['aciklama'] ?? '');
$kaynak = '';
if (preg_match('/^Sevk:\s*(.*?)\s*→/u', $acikMetin, $m)) $kaynak = trim($m[1]);
$gonderen = trim((string)($hareket['kisi'] ?? ''));
$isteyen  = preg_match('/isteyen\/teslim alacak:\s*([^·]+)/u', $acikMetin, $m2) ? trim($m2[1]) : '';
$notlar   = '';
foreach (array_slice(explode(' · ', $acikMetin), 1) as $__p) {
    if (str_starts_with($__p, 'gönderen:') || str_starts_with($__p, 'isteyen/teslim alacak:')) continue;
    $notlar .= ($notlar ? ' · ' : '') . $__p;
}
$teslimEden = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? '';

// ── İMZALI EVRAK: tutanağı yazdır → imzalat → tara → geri yükle ───────────────
$evrakMesaj = null; $evrakHata = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'imzali' && yetki_var('giris')) {
    $kul = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
    $ok = 0; $hatalar = []; $ilkId = (int)$liste[0]['id'];
    foreach (it_dosya_listesi($_FILES['belge'] ?? []) as $d) {
        if (($d['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        // Dosya diske BİR KEZ taşınır; tutanaktaki diğer cihazlara aynı URL ile bağ satırı eklenir
        [$b, $msj] = it_belge_yukle($pdoIt, $ilkId, $d, $kul, 'transfer');
        if (!$b) { $hatalar[] = $msj; continue; }
        $ok++;
        $son = $pdoIt->prepare("SELECT * FROM it_belgeler WHERE cihaz_id=? ORDER BY id DESC LIMIT 1");
        $son->execute([$ilkId]);
        $bg = $son->fetch();
        foreach ($liste as $__c) {
            $cid = (int)$__c['id'];
            if ($cid !== $ilkId && $bg) {
                $pdoIt->prepare("INSERT INTO it_belgeler (cihaz_id, dosya_url, ad, mime, boyut, tur, kullanici) VALUES (?,?,?,?,?, 'transfer', ?)")
                      ->execute([$cid, $bg['dosya_url'], $bg['ad'], $bg['mime'], $bg['boyut'], $kul]);
            }
            it_hareket_ekle($pdoIt, $cid, 'transfer', null, 'İmzalı transfer tutanağı yüklendi (' . $no . ').');
        }
    }
    if ($ok) $evrakMesaj = 'İmzalı tutanak yüklendi.';
    if ($hatalar) $evrakHata = strip_tags(implode(' · ', array_unique($hatalar)));
    if (!$ok && !$hatalar) $evrakHata = 'Dosya seçilmedi.';
}
$imzaliSayi = 0;
foreach ($liste as $__c) $imzaliSayi += count(array_filter(it_belgeler($pdoIt, (int)$__c['id']), fn($b) => ($b['tur'] ?? '') === 'transfer'));
?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title>Transfer Tutanağı <?= h($no) ?></title>
<?php /* Ortak A4 katmanı (_tutanak.php): her sayfada tekrar eden antet, @page margin:0 ile
         tarayıcı üstbilgisinin kesilmesi, yazdırmada .sheet min-height sıfırlaması (yoksa tek
         cihazlık belge bile 2 sayfa çıkıyordu) ve .evrak panelinin basılmaması. */ ?>
<?php tut_stil('
  .yon { display:flex; align-items:center; gap:12px; margin:0 0 10px; }
  .yon .kutu { flex:1; border:1px solid #cfcfcf; border-radius:8px; padding:7px 11px; font-size:11.5px; }
  .yon .kutu .et { font-size:9.5px; letter-spacing:1px; color:#777; font-weight:700; }
  .yon .kutu strong { font-size:12.5px; }
  .yon .ok { font-size:24px; color:#00584E; font-weight:800; }
'); ?>
</head>
<body>
<div class="toolbar">
  <button class="btn-print" onclick="window.print()">🖨 Yazdır / PDF Kaydet</button>
  <a class="btn-back" href="<?= h($geri) ?>">← Geri</a>
</div>

<?php if (yetki_var('giris')): ?>
<div class="evrak">
  <div class="basi">İmzalı Transfer Tutanağı
    <?php if ($imzaliSayi): ?><span class="rozet var">yüklü (<?= (int)$imzaliSayi ?>)</span>
    <?php else: ?><span class="rozet yok">yüklenmedi</span><?php endif; ?></div>
  <div style="color:#555">Tutanağı yazdırıp iki tarafa imzalattıktan sonra taranmış kopyayı buradan yükleyin —
    belge tutanaktaki <?= count($liste) ?> cihazın kartına işlenir ve listede yeşil rozetle görünür.</div>
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
        'Sevk Tarihi' => format_date($hTarih),
        'Düzenleme'   => date('d.m.Y'),
    ],
    'alt' => 'ERN Taahhüt — Bilgi İşlem Envanter Sistemi · ' . $no . ' · ' . date('d.m.Y H:i'),
]); ?>

  <div class="doc-title">CİHAZ TRANSFER (SEVK) TUTANAĞI</div>
  <div class="doc-no">Tutanak No: <?= h($no) ?></div>

  <div class="yon">
    <div class="kutu"><div class="et">GÖNDEREN PROJE / LOKASYON</div><strong><?= h($kaynak ?: '—') ?></strong></div>
    <div class="ok">➜</div>
    <div class="kutu"><div class="et">TESLİM EDİLECEK PROJE / LOKASYON</div><strong><?= h($hedef ?: '—') ?></strong></div>
  </div>

  <table class="info">
    <tr><td class="k">Gönderen (teslim eden)</td><td><?= h($gonderen ?: $teslimEden ?: '—') ?></td>
        <td class="k">İsteyen / teslim alacak</td><td><?= h($isteyen ?: '—') ?></td></tr>
    <tr><td class="k">Sevk Tarihi</td><td><?= format_date($hTarih) ?></td>
        <td class="k">Cihaz Adedi</td><td><?= count($liste) ?></td></tr>
    <?php if ($notlar): ?><tr><td class="k">Açıklama</td><td colspan="3"><?= h($notlar) ?></td></tr><?php endif; ?>
  </table>

  <div class="sec">SEVK EDİLEN CİHAZ<?= count($liste) > 1 ? 'LAR' : '' ?></div>
  <table class="items">
    <thead><tr><th style="width:30px">S.No</th><th style="width:80px">Cihaz Kodu</th><th style="width:120px">IFS Nesne No</th>
      <th>Cihaz</th><th>Marka / Model</th><th style="width:110px">Seri No</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td class="mono"><?= h(($r['cihaz_kodu'] ?? '') !== '' ? $r['cihaz_kodu'] : $r['envanter_no']) ?></td>
        <td class="mono"><?= h($r['varlik_kodu'] ?: '—') ?></td>
        <?php $__kat = it_kategoriAd((string)$r['kategori']); $__katAyri = it_norm($__kat) !== it_norm((string)$r['ad']); ?>
        <td><?= h($r['ad']) ?><div class="alt"><?= $__katAyri ? h($__kat) : '' ?><?= $r['ozellikler'] ? ($__katAyri ? ' · ' : '') . h($r['ozellikler']) : '' ?></div></td>
        <td><?= h(trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? '')) ?: '—') ?></td>
        <td class="mono"><?= h($r['seri_no'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php /* Kapanış beyanı ve imzalar BİRLİKTE kalır — imza bloğu tek başına son sayfaya
         düşerse belge yarıda kesilmiş gibi görünür. */ ?>
  <div class="kapanis">
  <div class="note">
    Yukarıda bilgileri verilen bilgi işlem cihaz(lar)ı, <strong><?= h($kaynak ?: 'gönderen proje') ?></strong>
    lokasyonundan ihtiyaç duyulan <strong><?= h($hedef ?: 'hedef proje') ?></strong> lokasyonuna sevk edilmek üzere
    teslim edilmiştir.
    <ol>
      <li>Cihazlar çalışır ve eksiksiz durumda, aksesuarlarıyla birlikte teslim edilmiştir.</li>
      <li>Sevk sırasında oluşabilecek hasar/kayıptan sevkiyatı üstlenen taraf sorumludur.</li>
      <li>Cihazlar hedef projede teslim alındığında bu tutanağın imzalı kopyası sisteme yüklenir ve
          envanterdeki durum <em>Transfer (yolda)</em> konumundan <em>Transfer Edilmiştir</em>e alınır.</li>
      <li>Cihazlar ERN Holding envanterinden ÇIKMAZ; yalnız bulunduğu proje/lokasyon değişir.</li>
    </ol>
  </div>

  <div class="signs">
    <div class="sign"><div class="line">TESLİM EDEN</div>
      <div class="sub"><?= h($gonderen ?: $teslimEden ?: '') ?><br><?= h($kaynak ?: '') ?><br>Tarih / İmza</div></div>
    <div class="sign"><div class="line">TESLİM ALAN</div>
      <div class="sub"><?= h($isteyen ?: '') ?><br><?= h($hedef ?: '') ?><br>Tarih / İmza</div></div>
  </div>
  </div><?php /* .kapanis */ ?>

<?php tut_kapat(); ?>
<?php tut_sayfa_js(); ?>
</body></html>
