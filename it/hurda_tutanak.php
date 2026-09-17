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
 *
 * ⚠⚠ GEREKÇE METNİ AYRIŞTIRILIR (2026-09-17, kullanıcı: "bu hurda ayırma formunu düzenliyelim
 * sıkıntılı"). Kullanıcılar hurda sebebini yazarken cihaz künyesini de metnin içine kopyalıyor
 * ("Cihaz Kodu: B037 / Marka-Model: Casper / Seri No: … / RAM: 4 GB / … arızalı"). Bu künye
 * tutanakta ZATEN basılıyor; metin olduğu gibi yazılınca aynı bilgi ÜÇ kez (gerekçe hücresi,
 * ÖZELLİKLER tablosu, kapanış beyanı) çıkıyor ve tek cihazlık belge 2 sayfaya taşıyordu.
 * `ht_gerekce_coz()` metni ikiye ayırır: **asıl gerekçe** (serbest cümleler) + **beyan edilen
 * alanlar**. Beyan edilen alan sistemdeki değerle AYNIYSA düşer (tekrar basılmaz), FARKLIYSA
 * "BEYAN ↔ SİSTEM KAYDI FARKI" tablosunda çelişki olarak gösterilir — ekrandaki "Marka Casper ama
 * kayıt OEM" çelişkisi böyle gizlenmek yerine görünür olur.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
it_semasi_kur($pdoIt);

/**
 * İşlem türüne göre belge kimliği: başlık · no öneki · beyan · imza sütunları · **kısa ad**.
 * ⚠ Kısa ad elle yazılır: `mb_strtolower('HURDAYA AYIRMA (İMHA) TUTANAĞI')` Türkçe bilmediğinden
 * "hurdaya ayirma (i̇mha) tutanaği" üretiyordu (ekranda ve hareket günlüğünde böyle görünüyordu).
 */
const HT_TUR = [
    'hurda' => ['HURDAYA AYIRMA (İMHA) TUTANAĞI', 'HRD', 'hurdaya ayrılmıştır',
                ['TESLİM EDEN / KULLANAN', 'TESPİT EDEN — BİLGİ İŞLEM', 'ONAY'], 'Hurdaya Ayırma Tutanağı'],
    'kayip' => ['KAYIP / ÇALINTI (ZAYİ) TUTANAĞI', 'ZAY', 'kayıp / çalıntı olarak kayda alınmıştır',
                ['BİLDİREN / SORUMLU', 'TESPİT EDEN — BİLGİ İŞLEM', 'ONAY'], 'Zayi Tutanağı'],
    'hibe'  => ['HİBE / DEVİR TUTANAĞI', 'HBE', 'hibe edilerek devredilmiştir',
                ['TESLİM EDEN', 'TESLİM ALAN KURUM / KİŞİ', 'ONAY'], 'Hibe / Devir Tutanağı'],
];

/** Etiket/değer karşılaştırması için: it_norm + noktalama → boşluk + boşluk sadeleştirme. */
function ht_norm(?string $s): string
{
    $s = it_norm((string)$s);
    $s = preg_replace('/[^A-Z0-9ÇĞİÖŞÜ]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', (string)$s));
}

/**
 * Gerekçe metnini ASIL SEBEP + BEYAN EDİLEN ALANLAR diye ayırır.
 *
 * "Etiket: değer" kalıbındaki parçalar alan sayılır (satır sonu, " · " ve ";" ile bölünür).
 * Etiket 40 karakteri ve 4 kelimeyi aşıyorsa cümle kabul edilir — "Cihaz arızalandı: tamiri
 * ekonomik değil" gibi serbest metinler yanlışlıkla alana dönüşmesin.
 *
 * @param array<string,string> $kayit sistemdeki değerler (künye + sanal alanlar), etiket => değer
 * @return array{ozet:string, farklar:array<int,array{et:string,dg:string,sistem:string}>}
 */
function ht_gerekce_coz(string $metin, array $kayit): array
{
    $metin = trim($metin);
    if ($metin === '') return ['ozet' => '', 'farklar' => []];

    $ozet = []; $beyan = [];
    foreach (preg_split('/\r\n|\r|\n|\s+·\s+|\s*;\s*/u', $metin) ?: [] as $p) {
        $p = trim($p);
        if ($p === '') continue;
        // ⚠ Kelime sayımı preg_split ile — str_word_count BAYT tabanlıdır, Türkçe harfleri böler
        if (preg_match('/^([^:]{2,40}?)\s*:\s*(.*)$/u', $p, $m)
            && count(preg_split('/\s+/u', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []) <= 4) {
            $dg = trim($m[2]);
            if ($dg !== '' && $dg !== '—' && $dg !== '-') $beyan[trim($m[1])] = $dg;
            continue;                                  // boş/"—" beyan hiç yazılmaz
        }
        $ozet[] = $p;
    }

    // Sistemdeki karşılığıyla aynı olan beyanlar düşer — tutanakta zaten basılıyor
    $kn = [];
    foreach ($kayit as $et => $dg) {
        $n = ht_norm($et);
        if ($n !== '' && trim((string)$dg) !== '') $kn[$n] = (string)$dg;
    }
    $farklar = [];
    foreach ($beyan as $et => $dg) {
        $n = ht_norm($et); $sistem = null;
        foreach ($kn as $k => $v) {
            if ($k === $n || ($n !== '' && (str_contains($k, $n) || str_contains($n, $k)))) { $sistem = $v; break; }
        }
        if ($sistem !== null) {
            $a = ht_norm($dg); $b = ht_norm($sistem);
            if ($a === $b || ($a !== '' && $b !== '' && (str_contains($b, $a) || str_contains($a, $b)))) continue;
        }
        $farklar[] = ['et' => $et, 'dg' => $dg, 'sistem' => (string)($sistem ?? '')];
    }
    return ['ozet' => trim(implode("\n", $ozet)), 'farklar' => $farklar];
}

/** Karşılaştırma tabanı: künye + tutanakta zaten basılan/kayıtta duran diğer alanlar. */
function ht_kayit_alanlari(PDO $pdo, array $r, bool $maliGoster): array
{
    $a = it_kunye($pdo, $r) + [
        'CİHAZ TİPİ'   => it_kategoriAd((string)$r['kategori']),
        'CİHAZ ADI'    => (string)($r['ad'] ?? ''),
        'ÖZELLİKLER'   => (string)($r['ozellikler'] ?? ''),
        'ALIŞ TARİHİ'  => !empty($r['alis_tarihi']) ? format_date($r['alis_tarihi']) : '',
        'TEDARİKÇİ'    => (string)($r['tedarikci'] ?? ''),
        'FATURA NO'    => (string)($r['fatura_no'] ?? ''),
        'NOTLAR'       => (string)($r['notlar'] ?? ''),
    ];
    if ($maliGoster && $r['fiyat'] !== null && $r['fiyat'] !== '') {
        $a['ALIŞ TUTARI'] = it_para_yaz($r['fiyat'], $r['para_birimi'] ?? 'TRY');
    }
    return array_filter($a, fn($x) => trim((string)$x) !== '');
}

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

[$baslik, $onEk, $beyan, $imzalar, $kisaAd] = HT_TUR[$tur] ?? HT_TUR['hurda'];

$ilk     = $liste[0];
$hareket = it_dusum_son($pdoIt, (int)$ilk['id'], $tur);
$hTarih  = $hareket['tarih'] ?? date('Y-m-d');
$kisi    = trim((string)($hareket['kisi'] ?? ''));
$no      = $id
    ? $onEk . '-' . ($ilk['cihaz_kodu'] ?: $ilk['envanter_no']) . '-' . date('Ymd', strtotime($hTarih))
    : $onEk . '-' . date('Ymd', strtotime($gun)) . '-' . str_pad((string)count($liste), 3, '0', STR_PAD_LEFT);
$lokasyon = $ilk['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$ilk['lokasyon_id']) : (string)($ilk['lokasyon'] ?? '');
$duzenleyen = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? '';
$maliGoster = it_mali_goster();

// Cihaz başına: künye · ayrıştırılmış gerekçe · sistemde kayıtlı görsel
$satirlar = []; $farkVar = false;
foreach ($liste as $r) {
    $h  = count($liste) > 1 ? it_dusum_son($pdoIt, (int)$r['id'], $tur) : $hareket;
    $ft = (string)($r['foto_url'] ?? '');
    $gz = ht_gerekce_coz((string)($h['aciklama'] ?? ''), ht_kayit_alanlari($pdoIt, $r, $maliGoster));
    if ($gz['farklar']) $farkVar = true;
    $satirlar[] = [
        'c'       => $r,
        'kunye'   => it_kunye($pdoIt, $r),
        'gerekce' => $gz['ozet'],
        'farklar' => $gz['farklar'],
        'tarih'   => (string)($h['tarih'] ?? $hTarih),
        'foto'    => ($ft !== '' && is_file(__DIR__ . '/../' . $ft)) ? $ft : '',
    ];
}
$gerekce    = $satirlar[0]['gerekce'];
$gerekceHam = trim((string)($hareket['aciklama'] ?? ''));
$fotoVar = (bool)array_filter(array_column($satirlar, 'foto'));
$tek     = count($liste) === 1;
// Tek cihazda görsel künyenin YANINA konur (ayrı galeri bloğu belgeyi 2. sayfaya taşırıyordu)
$fotoYanda = $tek && $satirlar[0]['foto'] !== '' && $satirlar[0]['kunye'];

// Boş sütunlar basılmaz — tek cihazlık belgede "—" dolu bir tablo yer israfıydı
$ifsVar  = (bool)array_filter($liste, fn($r) => trim((string)($r['varlik_kodu'] ?? '')) !== '');
$seriVar = (bool)array_filter($liste, fn($r) => trim((string)($r['seri_no'] ?? '')) !== '');
$degerVar = $maliGoster && array_filter($liste, fn($r) => $r['fiyat'] !== null && $r['fiyat'] !== '');

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
            it_hareket_ekle($pdoIt, $cid, 'not', null, 'İmzalı ' . $kisaAd . ' yüklendi (' . $no . ').');
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
  body { font-family: 'Segoe UI', Arial, sans-serif; color:#111; margin:0; background:#eceff0; }
  /* Ekran ölçüleri baskıyla AYNI: 210mm genişlik + @page kenar boşluklarıyla eşit iç boşluk. */
  /* ⚠ Kenar boşluğu `.sheet` padding'inde DEĞİL, tablo hücrelerinde (aşağıda): thead/tfoot her
     sayfada tekrar ettiği için üst/alt boşluğu da her sayfada onlar verir. `@page margin:0` —
     tarayıcı URL/saat/sayfa no üstbilgisini SAYFA KENAR BOŞLUĞUNA çizer, boşluk yoksa çizemez. */
  .sheet { width:210mm; min-height:297mm; margin:10px auto; background:#fff; padding:0; box-shadow:0 0 8px rgba(0,0,0,.15); }
  .antet { display:flex; justify-content:space-between; align-items:flex-start;
           border-bottom:3px solid #00584E; padding-bottom:6px; background:#fff; }
  .antet .logo { font-size:22px; font-weight:800; color:#00584E; letter-spacing:-.5px; }
  .antet .meta { text-align:right; font-size:10.5px; color:#555; line-height:1.55; }
  .antet-alt { margin-top:10px; text-align:center; font-size:9.5px; color:#999;
               border-top:1px solid #e3e3e3; padding-top:5px; }
  table.sayfa { width:100%; border-collapse:collapse; }
  table.sayfa > thead > tr > td { padding:12mm 14mm 0; border:0; }
  table.sayfa > tbody > tr > td { padding:0 14mm;      border:0; }
  table.sayfa > tfoot > tr > td { padding:0 14mm 10mm; border:0; }
  .kapanis { break-inside:avoid; page-break-inside:avoid; }
  .doc-title { text-align:center; margin:14px 0 4px; font-size:17px; font-weight:800; letter-spacing:.8px; }
  .doc-no { text-align:center; font-size:12px; color:#00584E; font-weight:700; margin-bottom:12px; }
  .info { width:100%; border-collapse:collapse; margin-bottom:10px; font-size:11.5px; }
  .info td { border:1px solid #cfcfcf; padding:5px 8px; }
  .info td.k { background:#f5f7f7; font-weight:600; width:19%; color:#444; }
  .info td.gerekce { white-space:pre-line; line-height:1.5; }
  .sec { margin:12px 0 4px; font-size:11px; font-weight:700; color:#00584E; letter-spacing:.6px;
         border-bottom:1px solid #cfcfcf; padding-bottom:3px; break-after:avoid; page-break-after:avoid; }
  table.items { width:100%; border-collapse:collapse; font-size:11.5px; }
  table.items th, table.items td { border:1px solid #bbb; padding:5px 7px; vertical-align:top; }
  table.items th { background:#00584E; color:#fff; font-weight:600; font-size:10.5px; letter-spacing:.3px; }
  table.items tr { break-inside:avoid; page-break-inside:avoid; }
  table.items .alt { font-size:10px; color:#666; }
  .mono { font-family: Consolas, monospace; font-size:11px; }
  .blok { break-inside:avoid; page-break-inside:avoid; }
  table.kunye { width:100%; border-collapse:collapse; font-size:10.5px; }
  table.kunye td { border:1px solid #d5d5d5; padding:3px 7px; }
  table.kunye td.k { background:#f7f9f9; font-weight:600; width:17%; color:#444; }
  table.fark { width:100%; border-collapse:collapse; font-size:10.5px; }
  table.fark th, table.fark td { border:1px solid #e0cfa8; padding:4px 7px; text-align:left; vertical-align:top; }
  table.fark th { background:#fdf6e3; color:#7a5a00; font-weight:600; }
  table.fark td.bos { color:#888; font-style:italic; }
  .fotolar { display:flex; flex-wrap:wrap; gap:8px; }
  .foto { width:40mm; border:1px solid #cfcfcf; border-radius:6px; padding:4px; text-align:center; break-inside:avoid; }
  .foto img { width:100%; height:26mm; object-fit:contain; }
  .foto .et { font-size:9.5px; color:#555; margin-top:3px; word-break:break-word; }
  .kunye-satir { display:flex; gap:8px; align-items:flex-start; }
  .kunye-satir table.kunye { flex:1; }
  .foto.yan { width:42mm; flex:0 0 42mm; }
  .foto.yan img { height:36mm; }
  .note { font-size:11px; color:#222; margin:10px 0 0; line-height:1.6; text-align:justify; }
  .note ol { margin:5px 0 0 16px; padding:0; }
  .note li { margin-bottom:2px; }
  .signs { display:flex; justify-content:space-between; gap:10px; margin-top:20px; }
  .sign { flex:1; text-align:center; }
  .sign .line { border-top:1px solid #333; margin-top:34px; padding-top:5px; font-size:11px; font-weight:700; }
  .sign .ad { font-size:10.5px; color:#222; min-height:13px; }
  .sign .sub { font-size:9.5px; color:#888; }
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
  @page { size:A4; margin:0; }
  @media print {
    body { background:#fff; }
    .sheet { margin:0; box-shadow:none; width:auto; min-height:0; padding:0; }
    /* thead/tfoot her sayfada yeniden basılır; gövde ikisinin arasında akar. */
    table.sayfa > thead { display:table-header-group; }
    table.sayfa > tfoot { display:table-footer-group; }
    .toolbar, .evrak { display:none; }
  }
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

<?php if ($farkVar): ?>
<?php /* Beyan ↔ kayıt çelişkisi ekranda da söylenir: belge basılmadan önce düzeltilebilsin. */ ?>
<div class="evrak" style="border-left-color:#c47f00">
  <div class="basi" style="color:#8a5a00">⚠ Tutanak metni ile cihaz kaydı arasında fark var</div>
  <div style="color:#555">Gerekçe metninde yazılan bazı bilgiler sistemdeki kayıtla uyuşmuyor; tutanağın
    altında <em>Beyan ↔ Sistem Kaydı Farkı</em> tablosunda listelendi. Doğrusu hangisiyse cihaz kartından
    düzeltip tutanağı yeniden yazdırın.</div>
</div>
<?php endif; ?>

<?php if (yetki_var('giris')): ?>
<div class="evrak">
  <div class="basi">İmzalı <?= h($kisaAd) ?>
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
<?php /* ⚠⚠ ANTET HER SAYFADA TEKRAR EDER — belge `thead`/`tfoot`'lu TEK bir tabloya sarılır:
       tarayıcı sayfa taşmasında thead'i ve tfoot'u KENDİ tekrarlar, yer de ayırır. `position:fixed`
       ile de tekrar ediyor ama akıştan çıktığı için negatif offset taşma sayılıp BOŞ bir sayfa
       açıyordu (tek cihazlık belge 2 sayfa görünüyordu). 2. sayfa artık antetsiz başlamaz. */ ?>
<table class="sayfa"><thead><tr><td>
  <div class="antet">
    <div><img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:44px" onerror="this.outerHTML='<div class=\'logo\'>ERN TAAHHÜT</div>'"><div style="font-size:9.5px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">BİLGİ İŞLEM — IT ENVANTER</div></div>
    <div class="meta">
      Tutanak No: <strong style="color:#00584E"><?= h($no) ?></strong><br>
      İşlem Tarihi: <strong><?= format_date($hTarih) ?></strong><br>
      Düzenleme: <strong><?= date('d.m.Y') ?></strong>
    </div>
  </div>
</td></tr></thead>
<tfoot><tr><td>
  <div class="antet-alt">ERN Taahhüt — Bilgi İşlem Envanter Sistemi · <?= h($no) ?> · <?= date('d.m.Y H:i') ?><span id="sayfaBilgi"></span></div>
</td></tr></tfoot>
<tbody><tr><td>
  <div class="govde">

  <div class="doc-title"><?= h($baslik) ?></div>
  <div class="doc-no"><?= h($no) ?></div>

  <table class="info">
    <tr><td class="k">İşlem</td><td><?= h(it_durumAd($tur)) ?></td>
        <td class="k">Cihaz Adedi</td><td><?= count($liste) ?> adet</td></tr>
    <tr><td class="k"><?= $tur === 'hibe' ? 'Hibe edilen kurum / kişi' : ($tur === 'kayip' ? 'Kaybı bildiren' : 'Teslim eden / kullanan') ?></td>
        <td><?= h($kisi ?: '—') ?></td>
        <td class="k">Lokasyon / Proje</td><td><?= h($lokasyon ?: '—') ?></td></tr>
    <tr><td class="k">Düzenleyen</td><td><?= h($duzenleyen ?: '—') ?></td>
        <td class="k">İşlem Tarihi</td><td><?= format_date($hTarih) ?></td></tr>
    <?php if ($tek): ?>
    <tr><td class="k">Gerekçe</td><td class="gerekce" colspan="3"><?php if ($gerekce !== ''): ?><?= h($gerekce) ?>
      <?php elseif ($gerekceHam !== ''): ?>—<span style="color:#8a5a00;font-size:10.5px"> (işlem açıklamasına yalnız cihaz künyesi yazılmış, sebep belirtilmemiş)</span>
      <?php else: ?>—<?php endif; ?></td></tr>
    <?php endif; ?>
  </table>

  <div class="sec">TUTANAĞA KONU CİHAZ<?= $tek ? '' : 'LAR' ?></div>
  <table class="items">
    <thead><tr><th style="width:26px">#</th><th style="width:76px">Cihaz Kodu</th>
      <?php if ($ifsVar): ?><th style="width:108px">IFS Nesne No</th><?php endif; ?>
      <th>Cihaz</th><th style="width:190px">Marka / Model</th>
      <?php if ($seriVar): ?><th style="width:96px">Seri No</th><?php endif; ?>
      <?php if ($degerVar): ?><th style="width:90px">Kayıtlı Değer</th><?php endif; ?>
      <?php if (!$tek): ?><th style="width:130px">Gerekçe</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($satirlar as $i => $s): $r = $s['c']; ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td class="mono"><?= h(($r['cihaz_kodu'] ?? '') !== '' ? $r['cihaz_kodu'] : $r['envanter_no']) ?></td>
        <?php if ($ifsVar): ?><td class="mono"><?= h($r['varlik_kodu'] ?: '—') ?></td><?php endif; ?>
        <?php /* Teknik künye ÖZELLİKLER bloğunda basılıyor — burada yalnız ad + tip + envanter no */ ?>
        <?php $kat = it_kategoriAd((string)$r['kategori']); $katAyri = ht_norm($kat) !== ht_norm((string)$r['ad']); ?>
        <td><?= h($r['ad']) ?><div class="alt"><?= $katAyri ? h($kat) . ' · ' : '' ?><?= h($r['envanter_no']) ?></div></td>
        <td><?= h(trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? '')) ?: '—') ?></td>
        <?php if ($seriVar): ?><td class="mono"><?= h($r['seri_no'] ?: '—') ?></td><?php endif; ?>
        <?php if ($degerVar): ?><td class="mono" style="text-align:right"><?= $r['fiyat'] !== null && $r['fiyat'] !== '' ? h(it_para_yaz($r['fiyat'], $r['para_birimi'] ?? 'TRY')) : '—' ?></td><?php endif; ?>
        <?php if (!$tek): ?><td style="font-size:10px"><?= h($s['gerekce'] ?: '—') ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php /* Cihaz künyesi — kurumsal demirbaş formundaki ÖZELLİKLER bloğuyla aynı (zimmet tutanağıyla ortak) */ ?>
  <?php foreach ($satirlar as $i => $s): $r = $s['c']; $ky = $s['kunye']; if (!$ky) continue; ?>
  <div class="blok">
    <div class="sec"><?= $tek ? '' : ($i + 1) . '. ' ?>ÖZELLİKLER — <?= h($r['ad']) ?>
      <span style="font-weight:600;color:#666">(<?= h($r['envanter_no']) ?><?= !empty($r['varlik_kodu']) ? ' · IFS: ' . h($r['varlik_kodu']) : '' ?>)</span></div>
    <div class="kunye-satir">
    <table class="kunye">
      <?php foreach (array_chunk($ky, 2, true) as $cift): ?>
      <tr>
        <?php foreach ($cift as $et => $dg): ?>
          <td class="k"><?= h($et) ?></td><td class="<?= in_array($et, ['ŞASİ NO / SERİ NO','IMEI','IP / MAC','CİHAZ KODU','IFS SERİ NESNE NO','ENVANTER NO'], true) ? 'mono' : '' ?>"><?= h($dg) ?></td>
        <?php endforeach; ?>
        <?php if (count($cift) === 1): ?><td class="k"></td><td></td><?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($fotoYanda): ?>
      <div class="foto yan"><img src="../<?= h($satirlar[0]['foto']) ?>" alt="">
        <div class="et">Sistemde kayıtlı fotoğraf</div></div>
    <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($farkVar): ?>
  <?php /* Gerekçe metninde yazılıp kayıtla çelişen alanlar — belge iki farklı bilgiyi sessizce taşımasın */ ?>
  <div class="blok">
    <div class="sec">BEYAN ↔ SİSTEM KAYDI FARKI</div>
    <table class="fark">
      <thead><tr><?php if (!$tek): ?><th style="width:26px">#</th><?php endif; ?>
        <th style="width:26%">Alan</th><th style="width:37%">Tutanak metninde beyan edilen</th><th>Sistem kaydı</th></tr></thead>
      <tbody>
      <?php foreach ($satirlar as $i => $s): foreach ($s['farklar'] as $f): ?>
        <tr><?php if (!$tek): ?><td><?= $i + 1 ?></td><?php endif; ?>
          <td><?= h($f['et']) ?></td><td><?= h($f['dg']) ?></td>
          <td class="<?= $f['sistem'] === '' ? 'bos' : '' ?>"><?= $f['sistem'] === '' ? 'kayıtta boş' : h($f['sistem']) ?></td></tr>
      <?php endforeach; endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($fotoVar && !$fotoYanda): ?>
  <?php /* Sistemde kayıtlı cihaz görseli — "hangi cihazdı" sorusunun en iyi cevabı */ ?>
  <div class="blok">
    <div class="sec">CİHAZ GÖRSELLERİ <span style="font-weight:600;color:#666">(sistemde kayıtlı fotoğraf)</span></div>
    <div class="fotolar">
      <?php foreach ($satirlar as $i => $s): if (!$s['foto']) continue; $r = $s['c']; ?>
        <div class="foto">
          <img src="../<?= h($s['foto']) ?>" alt="">
          <div class="et"><?= $tek ? '' : ($i + 1) . '. ' ?><?= h(($r['cihaz_kodu'] ?? '') !== '' ? $r['cihaz_kodu'] : $r['envanter_no']) ?> — <?= h($r['ad']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php /* Kapanış beyanı ve imzalar BİRLİKTE kalır — imza bloğu tek başına son sayfaya düşerse
         belge yarıda kesilmiş gibi görünür. */ ?>
  <div class="kapanis">
  <div class="note">
    Yukarıda künyesi çıkarılan <strong><?= count($liste) ?> adet</strong> bilgi işlem cihazı,
    <strong><?= format_date($hTarih) ?></strong> tarihinde yapılan inceleme sonucunda
    <strong><?= h($beyan) ?></strong>. İşbu tutanak taraflarca imza altına alınmıştır.
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
      <div class="ad"><?= str_contains($iz, 'BİLGİ İŞLEM') ? h($duzenleyen) : (str_contains($iz, 'ONAY') ? '' : h($kisi)) ?></div>
      <div class="sub">Adı Soyadı / Tarih / İmza</div></div>
    <?php endforeach; ?>
  </div>
  </div><?php /* .kapanis */ ?>
  </div><?php /* .govde */ ?>
</td></tr></tbody></table>
</div>
<script>
/* Belgenin KAÇ SAYFA olduğunu her sayfadaki alt bilgiye yazar — okuyan "devamı var mı" diye
   şüphelenmesin. Ekrandaki .sheet geometrisi baskıyla AYNI (210mm genişlik, aynı kenar boşlukları),
   bu yüzden ölçüm birebir taşınır. Görseller geç yüklendiğinden load + beforeprint'te yenilenir. */
(function () {
  var govde = document.querySelector('.govde'), et = document.getElementById('sayfaBilgi');
  if (!govde || !et) return;
  function mm() { var d = document.createElement('div'); d.style.cssText = 'height:100mm;position:absolute;visibility:hidden';
    document.body.appendChild(d); var h = d.getBoundingClientRect().height / 100; d.remove(); return h; }
  function yuk(sec) { var e = document.querySelector(sec); return e ? e.getBoundingClientRect().height : 0; }
  function say() {
    var birim = mm(); if (!birim) return;
    // Kullanılabilir gövde yüksekliği = A4 − her sayfada tekrar eden thead/tfoot (kenar boşlukları dahil)
    var sayfaIc = 297 * birim - yuk('table.sayfa > thead') - yuk('table.sayfa > tfoot');
    if (sayfaIc <= 0) return;
    var n = Math.max(1, Math.ceil((govde.getBoundingClientRect().height - 1) / sayfaIc));
    et.textContent = ' · ' + n + ' sayfa';
  }
  window.addEventListener('load', say);
  window.addEventListener('beforeprint', say);
  say();
})();
</script>
</body></html>
