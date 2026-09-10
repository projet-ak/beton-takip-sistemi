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
<style>
  * { box-sizing:border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color:#111; margin:0; background:#f0f0f0; }
  .sheet { width:210mm; min-height:297mm; margin:10px auto; background:#fff; padding:18mm 16mm; box-shadow:0 0 8px rgba(0,0,0,.15); }
  .top { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #00584E; padding-bottom:10px; }
  .top .logo { font-size:22px; font-weight:800; color:#00584E; letter-spacing:-.5px; }
  .doc-title { text-align:center; margin:18px 0 6px; font-size:18px; font-weight:800; letter-spacing:1px; }
  .doc-no { text-align:center; font-size:13px; color:#00584E; font-weight:700; margin-bottom:16px; }
  .yon { display:flex; align-items:center; gap:14px; margin:0 0 14px; }
  .yon .kutu { flex:1; border:1px solid #cfcfcf; border-radius:8px; padding:8px 12px; font-size:12.5px; }
  .yon .kutu .et { font-size:10px; letter-spacing:1px; color:#777; font-weight:700; }
  .yon .kutu strong { font-size:13px; }
  .yon .ok { font-size:26px; color:#00584E; font-weight:800; }
  .info { width:100%; border-collapse:collapse; margin-bottom:14px; font-size:12.5px; }
  .info td { border:1px solid #cfcfcf; padding:6px 9px; }
  .info td.k { background:#f5f7f7; font-weight:600; width:22%; }
  table.items { width:100%; border-collapse:collapse; font-size:12px; margin-top:4px; }
  table.items th, table.items td { border:1px solid #bbb; padding:6px 8px; vertical-align:top; }
  table.items th { background:#00584E; color:#fff; font-weight:600; }
  .mono { font-family: Consolas, monospace; font-size:11.5px; }
  .note { font-size:11.5px; color:#333; margin:16px 0; line-height:1.65; text-align:justify; }
  .note ol { margin:6px 0 0 18px; padding:0; }
  .signs { display:flex; justify-content:space-between; margin-top:40px; }
  .sign { width:45%; text-align:center; }
  .sign .line { border-top:1px solid #333; margin-top:56px; padding-top:6px; font-size:12px; font-weight:600; }
  .sign .sub { font-size:11px; color:#666; }
  .foot { margin-top:24px; text-align:center; font-size:10px; color:#999; border-top:1px solid #eee; padding-top:8px; }
  .toolbar { text-align:center; padding:10px; }
  .toolbar button, .toolbar a { font:inherit; padding:8px 18px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; margin:0 4px; }
  .btn-print { background:#00584E; color:#fff; }
  .btn-back { background:#e0e0e0; color:#333; }
  .evrak { max-width:210mm; margin:0 auto 10px; background:#fff; border:1px solid #d5d5d5; border-left:5px solid #00584E; border-radius:8px; padding:12px 16px; font-size:13px; }
  .evrak .basi { font-weight:700; color:#00584E; margin-bottom:4px; }
  .evrak .rozet { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; }
  .evrak .var { background:#e6f4ea; color:#1b6b3a; } .evrak .yok { background:#fff4e0; color:#8a5a00; }
  .evrak form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:8px; }
  .evrak input[type=file] { flex:1 1 240px; font:inherit; }
  .evrak button { background:#1b6b3a; color:#fff; border:none; border-radius:8px; padding:8px 16px; font:inherit; cursor:pointer; }
  .evrak .uyari { color:#b00; } .evrak .tamam { color:#1b6b3a; }
  @media print { body { background:#fff; } .sheet { margin:0; box-shadow:none; width:auto; padding:12mm; } .toolbar, .evrak { display:none; } }
</style>
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

<div class="sheet">
  <div class="top">
    <div><img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:46px" onerror="this.outerHTML='<div class=\'logo\'>ERN TAAHHÜT</div>'"><div style="font-size:10px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">IT ENVANTER</div></div>
    <div style="text-align:right;font-size:11px;color:#555">
      Düzenleme: <strong><?= date('d.m.Y') ?></strong><br>
      Sevk Tarihi: <strong><?= format_date($hTarih) ?></strong>
    </div>
  </div>

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

  <table class="items">
    <thead><tr><th style="width:30px">S.No</th><th style="width:80px">Cihaz Kodu</th><th style="width:120px">IFS Nesne No</th>
      <th>Cihaz</th><th>Marka / Model</th><th style="width:110px">Seri No</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td class="mono"><?= h(($r['cihaz_kodu'] ?? '') !== '' ? $r['cihaz_kodu'] : $r['envanter_no']) ?></td>
        <td class="mono"><?= h($r['varlik_kodu'] ?: '—') ?></td>
        <td><?= h($r['ad']) ?><div style="font-size:10.5px;color:#666"><?= h(it_kategoriAd($r['kategori'])) ?><?= $r['ozellikler'] ? ' · ' . h($r['ozellikler']) : '' ?></div></td>
        <td><?= h(trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? '')) ?: '—') ?></td>
        <td class="mono"><?= h($r['seri_no'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="note">
    Yukarıda bilgileri verilen bilgi işlem cihaz(lar)ı, <strong><?= h($kaynak ?: 'gönderen proje') ?></strong>
    lokasyonundan ihtiyaç duyulan <strong><?= h($hedef ?: 'hedef proje') ?></strong> lokasyonuna sevk edilmek üzere
    teslim edilmiştir.
    <ol>
      <li>Cihazlar çalışır ve eksiksiz durumda, aksesuarlarıyla birlikte teslim edilmiştir.</li>
      <li>Sevk sırasında oluşabilecek hasar/kayıptan sevkiyatı üstlenen taraf sorumludur.</li>
      <li>Cihazlar hedef projede teslim alındığında bu tutanağın imzalı kopyası sisteme yüklenir ve
          envanterdeki durum <em>Transfer (yolda)</em> konumundan <em>Depoda</em>ya alınır.</li>
      <li>Cihazlar ERN Holding envanterinden ÇIKMAZ; yalnız bulunduğu proje/lokasyon değişir.</li>
    </ol>
  </div>

  <div class="signs">
    <div class="sign"><div class="line">TESLİM EDEN</div>
      <div class="sub"><?= h($gonderen ?: $teslimEden ?: '') ?><br><?= h($kaynak ?: '') ?><br>Tarih / İmza</div></div>
    <div class="sign"><div class="line">TESLİM ALAN</div>
      <div class="sub"><?= h($isteyen ?: '') ?><br><?= h($hedef ?: '') ?><br>Tarih / İmza</div></div>
  </div>

  <div class="foot">ERN Taahhüt — Bilgi İşlem Envanter Sistemi · <?= h($no) ?> · <?= date('d.m.Y H:i') ?></div>
</div>
</body></html>
