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
$toplam = array_sum(array_map(fn($r) => (float)($r['fiyat'] ?? 0), $liste));
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

?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title>Zimmet Tutanağı <?= h($no) ?></title>
<style>
  * { box-sizing:border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color:#111; margin:0; background:#f0f0f0; }
  .sheet { width:210mm; min-height:297mm; margin:10px auto; background:#fff; padding:18mm 16mm; box-shadow:0 0 8px rgba(0,0,0,.15); }
  .top { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #00584E; padding-bottom:10px; }
  .top .logo { font-size:22px; font-weight:800; color:#00584E; letter-spacing:-.5px; }
  .doc-title { text-align:center; margin:18px 0 6px; font-size:18px; font-weight:800; letter-spacing:1px; }
  .doc-no { text-align:center; font-size:13px; color:#00584E; font-weight:700; margin-bottom:16px; }
  .info { width:100%; border-collapse:collapse; margin-bottom:14px; font-size:12.5px; }
  .info td { border:1px solid #cfcfcf; padding:6px 9px; }
  .info td.k { background:#f5f7f7; font-weight:600; width:22%; }
  table.items { width:100%; border-collapse:collapse; font-size:12px; margin-top:4px; }
  table.items th, table.items td { border:1px solid #bbb; padding:6px 8px; vertical-align:top; }
  table.items th { background:#00584E; color:#fff; font-weight:600; }
  table.items td.r, table.items th.r { text-align:right; }
  table.items tfoot td { background:#f5f7f7; font-weight:700; }
  .mono { font-family: Consolas, monospace; font-size:11.5px; }
  table.kunye { width:100%; border-collapse:collapse; font-size:11.5px; margin:0 0 10px; }
  table.kunye td { border:1px solid #cfcfcf; padding:4px 8px; }
  table.kunye td.k { background:#f5f7f7; font-weight:600; width:23%; white-space:nowrap; }
  .kunye-basi { font-size:12px; font-weight:700; color:#00584E; margin:14px 0 5px; letter-spacing:.5px; }
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
  @media print { body { background:#fff; } .sheet { margin:0; box-shadow:none; width:auto; padding:12mm; } .toolbar { display:none; } }
</style>
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

<div class="sheet">
  <div class="top">
    <div><img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:46px" onerror="this.outerHTML='<div class=\\'logo\\'>ERN TAAHHÜT</div>'"><div style="font-size:10px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">IT ENVANTER</div></div>
    <div style="text-align:right;font-size:11px;color:#555">
      Tarih: <strong><?= date('d.m.Y') ?></strong><br>
      Proje: <strong>Batı Yakası</strong>
    </div>
  </div>

  <div class="doc-title">BİLGİ İŞLEM DEMİRBAŞ ZİMMET TUTANAĞI</div>
  <div class="doc-no">Tutanak No: <?= h($no) ?></div>

  <table class="info">
    <tr><td class="k">Zimmet Alan</td><td><strong><?= h($kisi ?: '—') ?></strong><?= $per && $per['unvan'] ? ' — ' . h($per['unvan']) : '' ?></td>
        <td class="k">Sicil No</td><td><?= h($per['sicil_no'] ?? '') ?: '—' ?></td></tr>
    <tr><td class="k">Birim / Departman</td><td><?= h($departman ?: '—') ?></td>
        <td class="k">Telefon</td><td><?= h($per['telefon'] ?? '') ?: '—' ?></td></tr>
    <tr><td class="k">Lokasyon / Proje</td><td><?= h($lokasyon ?: '—') ?></td>
        <td class="k">Zimmet Tarihi</td><td><?= $hrk ? format_date($hrk['tarih']) : ($ilk['zimmet_tarihi'] ? format_date($ilk['zimmet_tarihi']) : date('d.m.Y')) ?></td></tr>
    <?php if ($per && $per['ise_giris']): ?><tr><td class="k">İşe Giriş</td><td><?= format_date($per['ise_giris']) ?></td><td class="k">Cihaz adedi</td><td><?= count($liste) ?></td></tr><?php endif; ?>
  </table>

  <table class="items">
    <thead><tr><th style="width:30px">S.No</th><th style="width:78px">Envanter No</th><th>Cihaz</th><th>Marka / Model</th><th>Seri No</th><?php if ($maliGoster): ?><th class="r" style="width:88px">Değer (TL)</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($liste as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td class="mono"><?= h($r['envanter_no']) ?><?php if (!empty($r['cihaz_kodu'])): ?><div style="font-size:10px;color:#666"><?= h($r['cihaz_kodu']) ?></div><?php endif; ?></td>
        <td><?= h($r['ad']) ?><div style="font-size:10.5px;color:#666"><?= h(it_kategoriAd($r['kategori'])) ?><?= $r['ozellikler'] ? ' · ' . h($r['ozellikler']) : '' ?></div></td>
        <td><?= h(trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? '')) ?: '—') ?></td>
        <td class="mono"><?= h($r['seri_no'] ?: '—') ?></td>
        <?php if ($maliGoster): ?><td class="r"><?= $r['fiyat'] !== null ? $f2($r['fiyat']) : '—' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="5" class="r">TOPLAM (<?= count($liste) ?> kalem)</td><?php if ($maliGoster): ?><td class="r"><?= $f2($toplam) ?></td><?php endif; ?></tr></tfoot>
  </table>

  <?php /* Kurumsal formdaki "Özellikler" bloğu — her cihaz için nesne kimliği + donanım künyesi */ ?>
  <?php foreach ($liste as $i => $r): $ky = it_kunye($pdoIt, $r); if (!$ky) continue; ?>
  <div class="kunye-basi"><?= count($liste) > 1 ? ($i + 1) . '. ' : '' ?>ÖZELLİKLER — <?= h($r['ad']) ?>
    <span style="font-weight:600;color:#555">(<?= h($r['envanter_no']) ?><?= !empty($r['varlik_kodu']) ? ' · IFS: ' . h($r['varlik_kodu']) : '' ?>)</span></div>
  <table class="kunye">
    <tr><td class="k">NESNE AÇIKLAMA</td><td><?= h($r['ad']) ?></td>
        <td class="k">NESNE TÜRÜ / KATEGORİ</td><td><?= h(IT_GRUP[it_grup((string)$r['kategori'])][0] ?? '') ?> / <?= h(it_kategoriAd((string)$r['kategori'])) ?></td></tr>
    <?php $ck = array_chunk($ky, 2, true); foreach ($ck as $cift): ?>
    <tr>
      <?php foreach ($cift as $et => $dg): ?>
        <td class="k"><?= h($et) ?></td><td class="<?= in_array($et, ['ŞASİ NO / SERİ NO','IMEI','IP / MAC','CİHAZ KODU'], true) ? 'mono' : '' ?>"><?= h($dg) ?></td>
      <?php endforeach; ?>
      <?php if (count($cift) === 1): ?><td class="k"></td><td></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (!empty($r['notlar'])): ?><tr><td class="k">NOTLAR</td><td colspan="3" style="white-space:pre-wrap"><?= h(mb_substr((string)$r['notlar'], 0, 400)) ?></td></tr><?php endif; ?>
  </table>
  <?php endforeach; ?>

  <div class="kunye-basi">KULLANICI BİLGİLERİ</div>
  <table class="kunye">
    <tr><td class="k">AD SOYAD</td><td><strong><?= h($kisi ?: '—') ?></strong></td>
        <td class="k">SİCİL NO</td><td class="mono"><?= h($per['sicil_no'] ?? '') ?: '—' ?></td></tr>
    <tr><td class="k">MAİL ADRESİ</td><td><?= h($per['eposta'] ?? '') ?: '—' ?></td>
        <td class="k">TELEFON</td><td><?= h($per['telefon'] ?? '') ?: '—' ?></td></tr>
    <tr><td class="k">BİRİM / UNVAN</td><td><?= h(trim(($departman ?: '') . ($per && $per['unvan'] ? ' — ' . $per['unvan'] : ''), ' —')) ?: '—' ?></td>
        <td class="k">LOKASYON</td><td><?= h($lokasyon ?: '—') ?></td></tr>
  </table>

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
    <div class="sign"><div class="line">Teslim Eden — ERN Taahhüt Bilgi İşlem</div><div class="sub"><?= h($teslimEden) ?> · İmza</div></div>
    <div class="sign"><div class="line">Teslim Alan — <?= h($kisi ?: 'Personel') ?></div><div class="sub">Ad Soyad / İmza</div></div>
  </div>

  <div class="foot">ERN Taahhüt IT Envanter — <?= date('d.m.Y H:i') ?></div>
</div>
</body></html>
