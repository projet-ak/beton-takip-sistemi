<?php
/**
 * it/hurda_tutanak.php — HURDAYA AYIRMA / ZAYİ / HİBE TUTANAĞI (A4, ERN Taahhüt logolu)
 *
 * Envanterden DÜŞEN üç durumun (hurda · kayıp/çalıntı · hibe/devir) resmî belgesi. Cihaz kaydı
 * silinmediği için "neden düştü, kim karar verdi, kim teslim etti" sorusunun cevabı bu tutanaktır.
 * Yazdır → komisyon imzalar → taranmış kopya buradan geri yüklenir (`it_belgeler.tur='hurda'`).
 *
 *   ?id=…                    tek cihaz
 *   ?gun=YYYY-MM-DD[&tur=…]  o gün aynı işlemle düşen TÜM cihazlar tek tutanakta (bir karar = bir belge)
 *
 * ⚠ Cihazın sistemde kayıtlı GÖRSELİ varsa (`foto_url`) tutanağa basılır — hurda/zayi belgesinde
 * "hangi cihazdı" sorusuna en iyi cevap fotoğraftır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
it_semasi_kur($pdoIt);

/** İşlem türüne göre belge kimliği: başlık · no öneki · beyan · imza sütunları. */
const HT_TUR = [
    'hurda' => ['HURDAYA AYIRMA (İMHA) TUTANAĞI', 'HRD', 'hurdaya ayrılmıştır',
                ['TESLİM EDEN / KULLANAN', 'TESPİT EDEN — BİLGİ İŞLEM', 'ONAY']],
    'kayip' => ['KAYIP / ÇALINTI (ZAYİ) TUTANAĞI', 'ZAY', 'kayıp / çalıntı olarak kayda alınmıştır',
                ['BİLDİREN / SORUMLU', 'TESPİT EDEN — BİLGİ İŞLEM', 'ONAY']],
    'hibe'  => ['HİBE / DEVİR TUTANAĞI', 'HBE', 'hibe edilerek devredilmiştir',
                ['TESLİM EDEN', 'TESLİM ALAN KURUM / KİŞİ', 'ONAY']],
];

$id  = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$gun = trim((string)($_GET['gun'] ?? ''));
$gun = preg_match('/^\d{4}-\d{2}-\d{2}$/', $gun) ? $gun : '';
$turG = in_array($_GET['tur'] ?? '', IT_DURUM_DUSEN, true) ? $_GET['tur'] : '';

if ($id) {
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE id=?"); $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) die('Cihaz bulunamadı.');
    $tur = it_durum_dustu($c['durum']) ? (string)$c['durum'] : ($turG ?: 'hurda');
    $liste = [$c];
    $geri  = 'cihaz_detay.php?id=' . $id;
} elseif ($gun) {
    // Aynı gün aynı kararla düşen cihazlar tek belgede — bir hurda kararı = bir tutanak
    $tur = $turG ?: 'hurda';
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE durum=? AND id IN
                           (SELECT cihaz_id FROM it_hareketler WHERE tur=? AND tarih=?)
                           ORDER BY cihaz_kodu, envanter_no");
    $st->execute([$tur, $tur, $gun]);
    $liste = $st->fetchAll();
    if (!$liste) die('Bu tarihte bu işlemle düşen cihaz yok.');
    $geri  = 'cihazlar.php?durum=' . $tur;
} else { die('Cihaz ya da işlem günü belirtilmedi.'); }

[$baslik, $onEk, $beyan, $imzalar] = HT_TUR[$tur] ?? HT_TUR['hurda'];

$ilk     = $liste[0];
$hareket = it_dusum_son($pdoIt, (int)$ilk['id'], $tur);
$hTarih  = $hareket['tarih'] ?? date('Y-m-d');
$kisi    = trim((string)($hareket['kisi'] ?? ''));
$gerekce = trim((string)($hareket['aciklama'] ?? ''));
$no      = $id
    ? $onEk . '-' . ($ilk['cihaz_kodu'] ?: $ilk['envanter_no']) . '-' . date('Ymd', strtotime($hTarih))
    : $onEk . '-' . date('Ymd', strtotime($gun)) . '-' . str_pad((string)count($liste), 3, '0', STR_PAD_LEFT);
$lokasyon = $ilk['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$ilk['lokasyon_id']) : (string)($ilk['lokasyon'] ?? '');
$duzenleyen = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? '';
$maliGoster = it_mali_goster();

// Cihaz başına gerekçe + sistemde kayıtlı görsel (varsa tutanağa basılır)
$satirlar = [];
foreach ($liste as $r) {
    $h  = count($liste) > 1 ? it_dusum_son($pdoIt, (int)$r['id'], $tur) : $hareket;
    $ft = (string)($r['foto_url'] ?? '');
    $satirlar[] = [
        'c'       => $r,
        'gerekce' => trim((string)($h['aciklama'] ?? '')),
        'tarih'   => (string)($h['tarih'] ?? $hTarih),
        'foto'    => ($ft !== '' && is_file(__DIR__ . '/../' . $ft)) ? $ft : '',
    ];
}
$fotoVar = (bool)array_filter(array_column($satirlar, 'foto'));

// ── İMZALI EVRAK: tutanağı yazdır → imzalat → tara → geri yükle ───────────────
$evrakMesaj = null; $evrakHata = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'imzali' && yetki_var('giris')) {
    $kul = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
    $ok = 0; $hatalar = []; $ilkId = (int)$liste[0]['id'];
    foreach (it_dosya_listesi($_FILES['belge'] ?? []) as $d) {
        if (($d['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        // Dosya diske BİR KEZ taşınır; tutanaktaki diğer cihazlara aynı URL ile bağ satırı eklenir
        [$b, $msj] = it_belge_yukle($pdoIt, $ilkId, $d, $kul, 'hurda');
        if (!$b) { $hatalar[] = $msj; continue; }
        $ok++;
        $son = $pdoIt->prepare("SELECT * FROM it_belgeler WHERE cihaz_id=? ORDER BY id DESC LIMIT 1");
        $son->execute([$ilkId]);
        $bg = $son->fetch();
        foreach ($liste as $__c) {
            $cid = (int)$__c['id'];
            if ($cid !== $ilkId && $bg) {
                $pdoIt->prepare("INSERT INTO it_belgeler (cihaz_id, dosya_url, ad, mime, boyut, tur, kullanici) VALUES (?,?,?,?,?, 'hurda', ?)")
                      ->execute([$cid, $bg['dosya_url'], $bg['ad'], $bg['mime'], $bg['boyut'], $kul]);
            }
            it_hareket_ekle($pdoIt, $cid, 'not', null, 'İmzalı ' . mb_strtolower($baslik, 'UTF-8') . ' yüklendi (' . $no . ').');
        }
    }
    if ($ok) $evrakMesaj = 'İmzalı tutanak yüklendi.';
    if ($hatalar) $evrakHata = strip_tags(implode(' · ', array_unique($hatalar)));
    if (!$ok && !$hatalar) $evrakHata = 'Dosya seçilmedi.';
}
// Aynı gün aynı kararla düşen başka cihaz var mı? (bir karar = bir tutanak; toplu belgeye geçiş)
$ayniGun = 0;
try {
    $ag = $pdoIt->prepare("SELECT COUNT(*) FROM it_cihazlar WHERE durum=? AND id IN
                           (SELECT cihaz_id FROM it_hareketler WHERE tur=? AND tarih=?)");
    $ag->execute([$tur, $tur, $hTarih]);
    $ayniGun = (int)$ag->fetchColumn();
} catch (Throwable $e) {}

$imzaliSayi = 0;
foreach ($liste as $__c) $imzaliSayi += count(array_filter(it_belgeler($pdoIt, (int)$__c['id']), fn($b) => ($b['tur'] ?? '') === 'hurda'));
?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title><?= h($baslik) ?> <?= h($no) ?></title>
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
  .mono { font-family: Consolas, monospace; font-size:11.5px; }
  .blok-basi { margin:16px 0 4px; font-size:12px; font-weight:700; color:#00584E; letter-spacing:.5px;
               border-bottom:1px solid #cfcfcf; padding-bottom:3px; }
  table.kunye { width:100%; border-collapse:collapse; font-size:11px; }
  table.kunye td { border:1px solid #d5d5d5; padding:4px 7px; }
  table.kunye td.k { background:#f7f9f9; font-weight:600; width:17%; color:#444; }
  .fotolar { display:flex; flex-wrap:wrap; gap:10px; }
  .foto { width:48mm; border:1px solid #cfcfcf; border-radius:6px; padding:5px; text-align:center; }
  .foto img { width:100%; height:34mm; object-fit:contain; }
  .foto .et { font-size:10px; color:#555; margin-top:3px; word-break:break-word; }
  .note { font-size:11.5px; color:#333; margin:16px 0; line-height:1.65; text-align:justify; }
  .note ol { margin:6px 0 0 18px; padding:0; }
  .signs { display:flex; justify-content:space-between; gap:10px; margin-top:36px; }
  .sign { flex:1; text-align:center; }
  .sign .line { border-top:1px solid #333; margin-top:52px; padding-top:6px; font-size:11.5px; font-weight:600; }
  .sign .sub { font-size:10.5px; color:#666; }
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
  <?php if ($id && $ayniGun > 1): ?>
    <a class="btn-back" href="hurda_tutanak.php?gun=<?= h(date('Y-m-d', strtotime($hTarih))) ?>&amp;tur=<?= h($tur) ?>"
       title="Aynı gün aynı işlemle düşen cihazları tek tutanakta yazdır">📄 Aynı gün düşen <?= (int)$ayniGun ?> cihaz tek tutanakta</a>
  <?php elseif (!$id): ?>
    <a class="btn-back" href="cihazlar.php?durum=<?= h($tur) ?>">Listeye dön</a>
  <?php endif; ?>
</div>

<?php if ($id && !it_durum_dustu($ilk['durum'])): ?>
<?php /* Tutanak durumu değiştirmez; cihaz hâlâ envanterde ise belge fiilî durumla çelişmesin diye uyarılır. */ ?>
<div class="evrak" style="border-left-color:#c47f00">
  <div class="basi" style="color:#8a5a00">⚠ Bu cihaz henüz envanterden düşülmedi</div>
  <div style="color:#555">Cihazın durumu şu an <strong><?= h(it_durumAd((string)$ilk['durum'])) ?></strong>.
    Form önceden yazdırılabilir, ancak işlemi kesinleştirmek için cihaz kartındaki
    <em>Hurdaya ayır / Kayıp bildir / Hibe et</em> işlemini uygulayın —
    tutanaktaki gerekçe ve tarih o kayıttan gelir.</div>
</div>
<?php endif; ?>

<?php if (yetki_var('giris')): ?>
<div class="evrak">
  <div class="basi">İmzalı <?= h(ucfirst(mb_strtolower($baslik, 'UTF-8'))) ?>
    <?php if ($imzaliSayi): ?><span class="rozet var">yüklü (<?= (int)$imzaliSayi ?>)</span>
    <?php else: ?><span class="rozet yok">yüklenmedi</span><?php endif; ?></div>
  <div style="color:#555">Tutanağı yazdırıp imzalattıktan sonra taranmış kopyayı buradan yükleyin —
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
      İşlem Tarihi: <strong><?= format_date($hTarih) ?></strong>
    </div>
  </div>

  <div class="doc-title"><?= h($baslik) ?></div>
  <div class="doc-no">Tutanak No: <?= h($no) ?></div>

  <table class="info">
    <tr><td class="k">İşlem</td><td><?= h(it_durumAd($tur)) ?></td>
        <td class="k">İşlem Tarihi</td><td><?= format_date($hTarih) ?></td></tr>
    <tr><td class="k"><?= $tur === 'hibe' ? 'Hibe edilen kurum / kişi' : ($tur === 'kayip' ? 'Kaybı bildiren' : 'Teslim eden / kullanan') ?></td>
        <td><?= h($kisi ?: '—') ?></td>
        <td class="k">Cihaz Adedi</td><td><?= count($liste) ?></td></tr>
    <tr><td class="k">Lokasyon / Proje</td><td><?= h($lokasyon ?: '—') ?></td>
        <td class="k">Düzenleyen</td><td><?= h($duzenleyen ?: '—') ?></td></tr>
    <?php if ($gerekce !== '' && count($liste) === 1): ?>
    <tr><td class="k">Gerekçe</td><td colspan="3"><?= h($gerekce) ?></td></tr>
    <?php endif; ?>
  </table>

  <table class="items">
    <thead><tr><th style="width:28px">S.No</th><th style="width:72px">Cihaz Kodu</th><th style="width:112px">IFS Nesne No</th>
      <th>Cihaz</th><th style="width:95px">Marka / Model</th><th style="width:100px">Seri No</th>
      <?php if ($maliGoster): ?><th style="width:70px" class="mono">Kayıtlı Değer</th><?php endif; ?>
      <?php if (count($liste) > 1): ?><th style="width:120px">Gerekçe</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($satirlar as $i => $s): $r = $s['c']; ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td class="mono"><?= h(($r['cihaz_kodu'] ?? '') !== '' ? $r['cihaz_kodu'] : $r['envanter_no']) ?></td>
        <td class="mono"><?= h($r['varlik_kodu'] ?: '—') ?></td>
        <td><?= h($r['ad']) ?><div style="font-size:10.5px;color:#666"><?= h(it_kategoriAd($r['kategori'])) ?><?= $r['ozellikler'] ? ' · ' . h($r['ozellikler']) : '' ?></div></td>
        <td><?= h(trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? '')) ?: '—') ?></td>
        <td class="mono"><?= h($r['seri_no'] ?: '—') ?></td>
        <?php if ($maliGoster): ?><td class="mono" style="text-align:right"><?= $r['fiyat'] !== null ? number_format((float)$r['fiyat'], 2, ',', '.') : '—' ?></td><?php endif; ?>
        <?php if (count($liste) > 1): ?><td style="font-size:10.5px"><?= h($s['gerekce'] ?: '—') ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php /* Cihaz künyesi — kurumsal demirbaş formundaki ÖZELLİKLER bloğuyla aynı (zimmet tutanağıyla ortak) */ ?>
  <?php foreach ($satirlar as $i => $s): $r = $s['c']; $ky = it_kunye($pdoIt, $r); if (!$ky) continue; ?>
  <div class="blok-basi"><?= count($liste) > 1 ? ($i + 1) . '. ' : '' ?>ÖZELLİKLER — <?= h($r['ad']) ?>
    <span style="font-weight:600;color:#555">(<?= h($r['envanter_no']) ?><?= !empty($r['varlik_kodu']) ? ' · IFS: ' . h($r['varlik_kodu']) : '' ?>)</span></div>
  <table class="kunye">
    <?php $ck = array_chunk($ky, 2, true); foreach ($ck as $cift): ?>
    <tr>
      <?php foreach ($cift as $et => $dg): ?>
        <td class="k"><?= h($et) ?></td><td class="<?= in_array($et, ['ŞASİ NO / SERİ NO','IMEI','IP / MAC','CİHAZ KODU'], true) ? 'mono' : '' ?>"><?= h($dg) ?></td>
      <?php endforeach; ?>
      <?php if (count($cift) === 1): ?><td class="k"></td><td></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endforeach; ?>

  <?php if ($fotoVar): ?>
  <?php /* Sistemde kayıtlı cihaz görseli — "hangi cihazdı" sorusunun en iyi cevabı */ ?>
  <div class="blok-basi">CİHAZ GÖRSELLERİ <span style="font-weight:600;color:#555">(sistemde kayıtlı fotoğraf)</span></div>
  <div class="fotolar">
    <?php foreach ($satirlar as $i => $s): if (!$s['foto']) continue; $r = $s['c']; ?>
      <div class="foto">
        <img src="../<?= h($s['foto']) ?>" alt="">
        <div class="et"><?= count($liste) > 1 ? ($i + 1) . '. ' : '' ?><?= h(($r['cihaz_kodu'] ?? '') !== '' ? $r['cihaz_kodu'] : $r['envanter_no']) ?> — <?= h($r['ad']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="note">
    Yukarıda bilgileri ve künyesi verilen <strong><?= count($liste) ?> adet</strong> bilgi işlem cihazı,
    <strong><?= format_date($hTarih) ?></strong> tarihinde yapılan inceleme sonucunda
    <strong><?= h($beyan) ?></strong>.
    <?php if ($gerekce !== '' && count($liste) === 1): ?> Gerekçe: <strong><?= h($gerekce) ?></strong>.<?php endif; ?>
    <ol>
      <?php if ($tur === 'hurda'): ?>
        <li>Cihaz(lar) ekonomik ömrünü tamamlamış / onarımı ekonomik olmadığından kullanım dışı bırakılmıştır.</li>
        <li>Veri taşıyan birimler (disk, hafıza kartı, SIM) imha öncesi silinmiş/sökülmüştür.</li>
        <li>Cihaz(lar) üzerindeki zimmet kaldırılmış, envanterde <em>Hurda</em> durumuna alınmıştır.</li>
      <?php elseif ($tur === 'kayip'): ?>
        <li>Cihaz(lar)ın kayıp/çalıntı olduğu yukarıdaki gerekçeyle beyan edilmiştir.</li>
        <li>Gerekli görülmesi hâlinde kolluk birimlerine bildirim yapılacak, tutanak eki olarak saklanacaktır.</li>
        <li>Cihaz(lar) üzerindeki zimmet kaldırılmış, envanterde <em>Kayıp / Çalıntı</em> durumuna alınmıştır.</li>
      <?php else: ?>
        <li>Cihaz(lar) çalışır durumda, aksesuarlarıyla birlikte teslim edilmiştir.</li>
        <li>Devir sonrası cihaz(lar)a ilişkin sorumluluk teslim alan tarafa geçmiştir.</li>
        <li>Cihaz(lar) üzerindeki zimmet kaldırılmış, envanterde <em>Hibe / Devredildi</em> durumuna alınmıştır.</li>
      <?php endif; ?>
      <li>Kayıt sistemden SİLİNMEZ; geçmişi, belgeleri ve bu tutanak envanterde saklanır.</li>
      <li>Bu tutanak imzalandıktan sonra taranmış kopyası cihaz kartına yüklenir.</li>
    </ol>
  </div>

  <div class="signs">
    <?php foreach ($imzalar as $iz): ?>
    <div class="sign"><div class="line"><?= h($iz) ?></div>
      <div class="sub"><?= str_contains($iz, 'BİLGİ İŞLEM') ? h($duzenleyen) : (str_contains($iz, 'ONAY') ? '' : h($kisi)) ?><br>Adı Soyadı / Tarih / İmza</div></div>
    <?php endforeach; ?>
  </div>

  <div class="foot">ERN Taahhüt — Bilgi İşlem Envanter Sistemi · <?= h($no) ?> · <?= date('d.m.Y H:i') ?></div>
</div>
</body></html>
