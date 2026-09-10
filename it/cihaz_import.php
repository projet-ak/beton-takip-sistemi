<?php
/**
 * it/cihaz_import.php — CİHAZ (demirbaş) listesi içe aktarma
 * 3 adım: (1) dosya yükle → (2) sütun eşleme ön izlemesi → (3) BİRLEŞTİRME + rapor.
 * Çekirdek `_cihaz_import.php` (cim_*), dosya okuma katmanı personel aktarımıyla ORTAK (`_import.php`).
 * Ara veri oturumda tutulur; tam yenileme YOKTUR (cihazın geçmişi/belgeleri silinmemeli).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/_cihaz_import.php';
it_semasi_kur($pdoIt);
cim_semasi_kur($pdoIt);
$pageTitle = 'Cihaz İçe Aktar — IT Envanter';
$kisi = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;

/** Şablonun sütun düzeni = içe aktarmanın tanıdığı başlıklar (cim_harita bu adlarla eşleştirir). */
const CIM_SABLON_BASLIK = ['Envanter No','Cihaz Kodu','IFS Seri Nesne No','Seri Nesne Adı','Kategori','Marka','Model','Seri No','Şasi No','IMEI',
                           'Durum','Kişi','Departman','Mevcut Proje','Kiralanan Firma','Alış Tarihi','Garanti Bitiş','Fiyat',
                           'Tedarikçi','Fatura No','IP Adresi','MAC Adresi','İşletim Sistemi',
                           'İşlemci','Ram','Ekran Kartı','Hdd','Anakart','Ekran Boyutu','Kapasite','Teknik Özellik','Not'];

/** Bir cihaz kaydını şablon düzenine çevirir. */
function cim_sablon_satiri(PDO $pdo, array $r): array
{
    $lok = (int)($r['lokasyon_id'] ?? 0);
    $lokAd = (string)($r['lokasyon'] ?? '');
    if ($lok && ($l = it_lokasyonlar($pdo)[$lok] ?? null)) $lokAd = trim(($l['kod'] ?? '') !== '' ? $l['kod'] . ' — ' . $l['ad'] : $l['ad']);
    return [
        ['v'=>(string)($r['envanter_no'] ?? '')], ['v'=>(string)($r['cihaz_kodu'] ?? '')],
        ['v'=>(string)($r['varlik_kodu'] ?? '')], ['v'=>(string)($r['ad'] ?? '')],
        ['v'=>IT_KATEGORI[$r['kategori'] ?? 'diger'][0] ?? ''],
        ['v'=>(string)($r['marka'] ?? '')], ['v'=>(string)($r['model'] ?? '')], ['v'=>(string)($r['seri_no'] ?? '')],
        ['v'=>(string)($r['sasi_no'] ?? '')], ['v'=>(string)($r['imei'] ?? '')],
        ['v'=>IT_DURUM[$r['durum'] ?? 'depoda'][0] ?? ''],
        ['v'=>(string)($r['zimmetli'] ?? '')], ['v'=>(string)($r['departman'] ?? '')], ['v'=>$lokAd],
        ['v'=>(string)($r['kiralik_firma'] ?? '')],
        ['v'=>(string)($r['alis_tarihi'] ?? ''), 't'=>'date'], ['v'=>(string)($r['garanti_bitis'] ?? ''), 't'=>'date'],
        ['v'=>($r['fiyat'] !== null && $r['fiyat'] !== '') ? (float)$r['fiyat'] : '', 't'=>'number'],
        ['v'=>(string)($r['tedarikci'] ?? '')], ['v'=>(string)($r['fatura_no'] ?? '')],
        ['v'=>(string)($r['ip_adresi'] ?? '')], ['v'=>(string)($r['mac_adresi'] ?? '')], ['v'=>(string)($r['isletim_sistemi'] ?? '')],
        ['v'=>(string)($r['islemci'] ?? '')], ['v'=>(string)($r['ram'] ?? '')], ['v'=>(string)($r['ekran_karti'] ?? '')],
        ['v'=>(string)($r['disk'] ?? '')], ['v'=>(string)($r['anakart'] ?? '')], ['v'=>(string)($r['ekran_boyutu'] ?? '')],
        ['v'=>(string)($r['kapasite'] ?? '')],
        ['v'=>(string)($r['ozellikler'] ?? '')], ['v'=>mb_substr((string)($r['notlar'] ?? ''), 0, 400)],
    ];
}

// ── Örnek şablon (?sablon=1) / mevcut envanterin şablon biçimi (?sablon=mevcut) ──
// Örnek satırlar SİSTEMDEKİ gerçek lokasyon, kategori, durum ve personel değerleriyle üretilir.
if (isset($_GET['sablon'])) {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $mevcutMu = ($_GET['sablon'] === 'mevcut');
    $xl = new \XlsxWriter($mevcutMu ? 'Cihaz Listesi' : 'Cihaz Şablonu');
    $xl->header(CIM_SABLON_BASLIK);

    if ($mevcutMu) {
        $n = 0;
        foreach ($pdoIt->query("SELECT * FROM it_cihazlar ORDER BY envanter_no") as $r) { $xl->row(cim_sablon_satiri($pdoIt, $r)); $n++; }
        if (!$n) $xl->row(array_fill(0, count(CIM_SABLON_BASLIK), ['v'=>'']));
        $xl->download('it_cihaz_listesi_' . date('Ymd') . '.xlsx');
    }

    $lokAdlar = [];
    foreach (it_lokasyon_duz($pdoIt) as $x) { $r0 = $x['r']; $lokAdlar[] = ($r0['kod'] ?? '') !== '' ? $r0['kod'] . ' — ' . $r0['ad'] : $r0['ad']; }
    if (!$lokAdlar) $lokAdlar = ['U030 — 1. Etap', 'ERN Holding İstanbul Merkez Binası'];
    $kisiler = [];
    foreach (array_slice(it_personel_liste($pdoIt), 0, 3) as $p) $kisiler[] = it_personel_ad($p);
    while (count($kisiler) < 3) $kisiler[] = ['Ahmet Yılmaz', 'Ayşe Kaya', ''][count($kisiler)];

    // Örnek satırlar başlık sırasıyla birebir eşleşir (CIM_SABLON_BASLIK); kişi/lokasyon sistemden gelir
    $ornek = [
        ['IT-00001','N221','FRM-0002-82026-2552600167','Dizüstü Bilgisayar','Dizüstü','Lenovo','ThinkPad E14','PF3ABCDE','TCNXCV00V025494','356938035643809',
         'Kullanımda',0,'','2025-02-10','2027-02-10',28500,'Bilgi İşlem A.Ş.','FTR2025000123','','','Windows 11 Pro',
         'İntel i7 · Intel(R) Core(TM) 7 240H','DDR5 16 GB · Samsung','Intel Raptor Lake-H','NVMe 512 GB','ThinkPad E14 · Lenovo','14"','512 GB','',''],
        ['IT-00002','M160','FRM-0002-MON-2551900855','Monitör','Monitör','AOC','24B2XH','FUAE1HA035655','','',
         'Kullanımda',1,'','2025-02-10','2027-02-10',3250,'Bilgi İşlem A.Ş.','FTR2025000123','','','',
         '','','','','','24"','','IPS Full HD',''],
        ['IT-00003','Y031','FRM-0002-YZC-2551901004','Yazıcı','Yazıcı','HP','LaserJet M404dn','VNC3K12345','','',
         'Depoda',2,'','2024-11-05','2026-11-05',9750,'Ofis Market','FTR2024000987','192.168.1.45','A4:BB:6D:11:22:33','',
         '','','','','','','','Mono lazer, ağ bağlantılı','Depoda yedek olarak bekliyor'],
    ];
    foreach ($ornek as $i => $o) {
        $xl->row([
            ['v'=>$o[0]], ['v'=>$o[1]], ['v'=>$o[2]], ['v'=>$o[3]], ['v'=>$o[4]], ['v'=>$o[5]], ['v'=>$o[6]], ['v'=>$o[7]], ['v'=>$o[8]], ['v'=>$o[9]],
            ['v'=>$o[10]], ['v'=>(string)($kisiler[(int)$o[11]] ?? '')], ['v'=>$o[12]],
            ['v'=>(string)($lokAdlar[$i % count($lokAdlar)] ?? '')], ['v'=>''],
            ['v'=>$o[13], 't'=>'date'], ['v'=>$o[14], 't'=>'date'], ['v'=>(float)$o[15], 't'=>'number'],
            ['v'=>$o[16]], ['v'=>$o[17]], ['v'=>$o[18]], ['v'=>$o[19]], ['v'=>$o[20]],
            ['v'=>$o[21]], ['v'=>$o[22]], ['v'=>$o[23]], ['v'=>$o[24]], ['v'=>$o[25]], ['v'=>$o[26]], ['v'=>$o[27]],
            ['v'=>$o[28]], ['v'=>$o[29]],
        ]);
    }
    for ($i = 0; $i < 5; $i++) $xl->row(array_fill(0, count(CIM_SABLON_BASLIK), ['v'=>'']));
    $xl->download('it_cihaz_sablon.xlsx');
}

if (isset($_GET['iptal'])) { unset($_SESSION['it_cim'], $_SESSION['it_cim_rapor']); redirect('cihaz_import.php'); }

$hata = null;
$alanSecenek = ['' => '— atla —'] + array_map(fn($t) => $t['etiket'], CIM_ALAN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yetki_var('giris')) { flash('error', 'Bu işlem için yetkiniz yok.'); redirect('cihazlar.php'); }
    $islem = $_POST['islem'] ?? '';

    if ($islem === 'lok_ekle') {
        if (!yetki_var('duzenle')) { flash('error', 'Lokasyon eklemek için değiştirme yetkisi gerekir.'); redirect('cihaz_import.php?rapor=1'); }
        $eklendi = 0;
        foreach ((array)($_POST['lok'] ?? []) as $ad) {
            $ad = mb_substr(trim((string)$ad), 0, 120);
            if ($ad === '' || pim_lokasyon_bul($pdoIt, $ad)) continue;
            $pdoIt->prepare("INSERT INTO it_lokasyonlar (ust_id, tur, ad, aktif) VALUES (NULL, 'proje', ?, 1)")->execute([$ad]);
            $eklendi++;
        }
        it_lokasyonlar($pdoIt, true);
        flash($eklendi ? 'success' : 'warning', $eklendi ? "$eklendi lokasyon eklendi. Cihazlara bağlanması için dosyayı tekrar yükleyin (mükerrer cihaz oluşmaz)." : 'Eklenecek yeni lokasyon bulunamadı.');
        redirect('tanimlar.php?t=lokasyon');
    }

    if ($islem === 'kisi_ekle') {
        if (!yetki_var('duzenle')) { flash('error', 'Personel eklemek için değiştirme yetkisi gerekir.'); redirect('cihaz_import.php?rapor=1'); }
        $n = cim_personel_ekle($pdoIt, (array)($_POST['kisi'] ?? []));
        flash($n ? 'success' : 'warning', $n ? "$n personel kartı açıldı. Cihazların zimmetlenmesi için dosyayı tekrar yükleyin." : 'Eklenecek yeni kişi bulunamadı.');
        redirect('personel.php');
    }

    if ($islem === 'yukle') {
        $f = $_FILES['dosya'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) $hata = 'Dosya seçilmedi.';
        elseif ($f['error'] !== UPLOAD_ERR_OK) $hata = match ((int)$f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya sunucu boyut sınırını aşıyor.',
            default => 'Yükleme hatası (kod ' . (int)$f['error'] . ').',
        };
        else {
            $ad = basename((string)$f['name']);
            if (!preg_match('/\.(xlsx|xls|csv|txt|htm|html)$/i', $ad)) $hata = 'Kabul edilen uzantılar: .xlsx, .xls (web sayfası), .csv, .htm/.html';
            else {
                try {
                    $g = pim_oku($f['tmp_name'], $ad);
                    $satirlar = []; $satirNo = [];
                    foreach ($g['satirlar'] as $no => $rw) {
                        if (!array_filter($rw, fn($c) => trim((string)$c) !== '')) continue;
                        $satirNo[count($satirlar)] = $no + 1;
                        $satirlar[] = $rw;
                    }
                    if (count($satirlar) < 2) throw new RuntimeException('Dosyada başlık dışında veri satırı yok.');
                    $bIdx = pim_baslik_satiri($satirlar);
                    $_SESSION['it_cim'] = ['dosya'=>$ad, 'bicim'=>$g['bicim'], 'sayfa'=>$g['sayfa'], 'satirlar'=>$satirlar, 'satir_no'=>$satirNo,
                                           'baslik_idx'=>$bIdx, 'harita'=>cim_harita($satirlar[$bIdx]), 'opt'=>['kisi_ekle'=>0]];
                    unset($_SESSION['it_cim_rapor']);
                    redirect('cihaz_import.php?adim=2');
                } catch (Throwable $e) { $hata = $e->getMessage(); }
            }
        }
    } elseif (($islem === 'onizle' || $islem === 'aktar') && !empty($_SESSION['it_cim'])) {
        $s = &$_SESSION['it_cim'];
        $bIdx = (int)($_POST['baslik_idx'] ?? $s['baslik_idx']);
        if ($bIdx < 0 || $bIdx >= count($s['satirlar']) - 1) $bIdx = $s['baslik_idx'];
        if ($bIdx !== $s['baslik_idx']) { $s['baslik_idx'] = $bIdx; $s['harita'] = cim_harita($s['satirlar'][$bIdx]); }
        else {
            $h = [];
            foreach ($s['satirlar'][$bIdx] as $i => $_) { $k = (string)($_POST['m'][$i] ?? ''); $h[$i] = array_key_exists($k, $alanSecenek) ? $k : ''; }
            // Aynı alan iki sütuna verildiyse ilk sütun kalır (CIM_COKLU alanları birleşir — hepsi kalır)
            $kul = [];
            foreach ($h as $i => $k) { if ($k === '' || in_array($k, CIM_COKLU, true)) continue; if (isset($kul[$k])) $h[$i] = ''; else $kul[$k] = $i; }
            $s['harita'] = $h;
        }
        $s['opt'] = ['kisi_ekle' => (!empty($_POST['kisi_ekle']) && yetki_var('duzenle')) ? 1 : 0];
        unset($s);
        if ($islem === 'aktar') {
            $s = $_SESSION['it_cim'];
            $var = array_filter($s['harita']);
            if (!array_intersect(['varlik_kodu','cihaz_kodu','envanter_no','seri_no','ad'], $var)) {
                $hata = 'Cihazı tanımlayan en az bir sütun (IFS nesne no / cihaz kodu / envanter no / seri no / cihaz adı) eşlenmeden aktarım yapılamaz.';
            } else {
                $rap = null;
                try {
                    $rap = cim_import($pdoIt, $s['satirlar'], $s['opt'] + ['harita'=>$s['harita'], 'baslik_idx'=>$s['baslik_idx'], 'satir_no'=>($s['satir_no'] ?? []),
                                                                          'dosya'=>$s['dosya'], 'bicim'=>$s['bicim'], 'kullanici'=>$kisi]);
                } catch (Throwable $e) { $hata = 'Aktarım geri alındı: ' . $e->getMessage(); }
                if ($rap !== null) {
                    $_SESSION['it_cim_rapor'] = $rap + ['dosya'=>$s['dosya']];
                    unset($_SESSION['it_cim']);
                    redirect('cihaz_import.php?rapor=1');
                }
            }
        }
        if (!$hata) redirect('cihaz_import.php?adim=2');
    }
}

$adim = 1; $s = null; $rap = null;
if (isset($_GET['rapor']) && !empty($_SESSION['it_cim_rapor'])) { $adim = 3; $rap = $_SESSION['it_cim_rapor']; }
elseif (!empty($_SESSION['it_cim'])) { $adim = 2; $s = $_SESSION['it_cim']; }

$gecmis = [];
try { $gecmis = $pdoIt->query("SELECT * FROM it_import_log WHERE dosya LIKE '%[cihaz]' ORDER BY id DESC LIMIT 10")->fetchAll(); } catch (Throwable $e) {}
$yazabilir = yetki_var('giris');
$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-box-arrow-in-down text-primary me-2"></i>Cihaz Listesi İçe Aktar</h4>
    <div class="d-flex gap-2">
        <a href="cihazlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pc-display me-1"></i>Cihaz Listesi</a>
        <a href="cihaz_import.php?sablon=1" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Örnek Şablon</a>
        <a href="cihaz_import.php?sablon=mevcut" class="btn btn-outline-secondary btn-sm" title="Kayıtlı cihazları şablon biçiminde indirir; düzenleyip tekrar yükleyebilirsiniz"><i class="bi bi-download me-1"></i>Mevcut Liste</a>
    </div>
</div>

<?php if ($hata): ?><div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= h($hata) ?></div><?php endif; ?>
<?php if (!$yazabilir): ?><div class="alert alert-warning py-2">Bu modülde veri girişi yetkiniz olmadığından dosya yükleyemezsiniz.</div><?php endif; ?>

<ul class="nav nav-pills nav-sm mb-3 small">
  <li class="nav-item"><span class="nav-link <?= $adim===1?'active':'disabled' ?>">1. Dosya</span></li>
  <li class="nav-item"><span class="nav-link <?= $adim===2?'active':'disabled' ?>">2. Sütun eşleme &amp; ön izleme</span></li>
  <li class="nav-item"><span class="nav-link <?= $adim===3?'active':'disabled' ?>">3. Sonuç</span></li>
</ul>

<?php if ($adim === 1): ?>
<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm h-100">
      <input type="hidden" name="islem" value="yukle">
      <div class="card-body">
        <label class="form-label fw-semibold">Demirbaş / zimmet listesi dosyası</label>
        <input type="file" name="dosya" class="form-control" accept=".xlsx,.xls,.csv,.txt,.htm,.html" required <?= $yazabilir ? '' : 'disabled' ?>>
        <div class="form-text">
          Kurumsal envanter çıktısı (ör. <em>"Hızlı Rapor — Zimmet Edilen Demirbaş Listesi"</em>), <strong>Snipe-IT "Export Assets"</strong> dosyası,
          kendi Excel'iniz ya da .csv olabilir. Sütun başlıkları Türkçe veya İngilizce tanınır (Demirbaş Etiketi / Cihaz Kodu / IFS Seri Nesne No /
          Model / Model No. / Seri No / Çıkış Yapılmış Olan Kişi / Çalışan Numarası / Konum, Asset Tag, Serial, Assigned To, Location…).
          Eşleme bir sonraki adımda gösterilir, dilerseniz düzeltirsiniz.
        </div>
        <?php
          $lokEtiket = [];
          foreach (it_lokasyon_duz($pdoIt) as $x) { $r0 = $x['r']; $lokEtiket[] = trim(($r0['kod'] ?? '') !== '' ? $r0['kod'] . ' — ' . $r0['ad'] : $r0['ad']); }
        ?>
        <div class="alert alert-success small py-2 mt-3 mb-0">
          <i class="bi bi-file-earmark-excel me-1"></i><strong>Şablon sisteme göre üretilir.</strong>
          <strong>Örnek Şablon</strong> içe aktarmanın tanıdığı <?= count(CIM_SABLON_BASLIK) ?> sütunu ve sizdeki gerçek lokasyon / personel değerleriyle örnek satırlar içerir.
          <strong>Mevcut Liste</strong> ise kayıtlı cihazları aynı düzende indirir: Excel'de düzeltip geri yüklersiniz, eşleşme kodlardan yapılır, mükerrer cihaz oluşmaz.
          <?php if ($lokEtiket): ?>
          <div class="mt-1">Lokasyon / proje sütununa yazabilecekleriniz:
            <?php foreach (array_slice($lokEtiket, 0, 12) as $le): ?><span class="badge bg-light text-dark border ms-1"><?= h($le) ?></span><?php endforeach; ?>
            <?php if (count($lokEtiket) > 12): ?><span class="text-muted"> +<?= count($lokEtiket) - 12 ?> lokasyon</span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-footer bg-white"><button class="btn btn-primary" <?= $yazabilir ? '' : 'disabled' ?>><i class="bi bi-upload me-1"></i>Yükle ve Ön İzle</button></div>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100"><div class="card-body small">
      <h6 class="fw-semibold"><i class="bi bi-info-circle me-1 text-primary"></i>Nasıl çalışır?</h6>
      <ul class="mb-2 ps-3">
        <li><strong>Birleştirme</strong>, tam yenileme değil: cihaz <em>varlık kodu → envanter no → seri no</em> sırasıyla aranır; bulunursa yalnız dosyada <em>dolu</em> gelen alanlar güncellenir, bulunmazsa yeni cihaz açılır. <strong>Hiçbir cihaz silinmez</strong> — geçmişi, belgeleri ve fotoğrafları korunur.</li>
        <li>Envanter no yoksa (ya da başka cihazda kullanılıyorsa) sistem otomatik <code>IT-00001</code> biçiminde üretir.</li>
        <li>Cihaz türü <em>adından</em> anlaşılır ("Monitör" → Monitör, "Dizüstü" → Laptop); Kategori sütunu varsa o esas alınır.</li>
        <li><strong>Kişi</strong> sütunu <a href="personel.php">personel kartlarıyla</a> ad soyad üzerinden eşleşir; zimmet değişirse cihazın yaşam günlüğüne <em>iade + zimmet</em> satırı yazılır. Eşleşmeyen kişiler raporda listelenir, tek tıkla personel kartı açabilirsiniz.</li>
        <li>Proje / lokasyon metni kod (U030…) ya da adla <a href="tanimlar.php?t=lokasyon">lokasyon ağacına</a> bağlanır; eşleşmeyen metin cihazın notuna yazılır.</li>
        <li>İşlemci / RAM / Ekran kartı / HDD gibi sütunlar tek <em>teknik özellik</em> alanında birleştirilir.</li>
      </ul>
      <div class="text-muted">Aynı dosya ikinci kez yüklendiğinde mükerrer cihaz oluşmaz; yalnız değişen alanlar güncellenir.</div>
    </div></div>
  </div>
</div>

<?php elseif ($adim === 2 && $s): $baslik = $s['satirlar'][$s['baslik_idx']]; $veri = array_slice($s['satirlar'], $s['baslik_idx'] + 1); $nSutun = max(array_map('count', array_slice($s['satirlar'], 0, 50)) ?: [count($baslik)]);
      $kullanilan = array_filter($s['harita']); $kimlikVar = (bool)array_intersect(['varlik_kodu','cihaz_kodu','envanter_no','seri_no','ad'], $kullanilan); ?>
<form method="post" id="fmEsle">
<input type="hidden" name="islem" id="islem" value="onizle">
<div class="card border-0 shadow-sm mb-3"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-3 small">
  <div><i class="bi bi-file-earmark-text me-1"></i><strong><?= h($s['dosya']) ?></strong> <span class="badge bg-secondary"><?= h(strtoupper($s['bicim'])) ?></span><?php if ($s['sayfa']): ?> sayfa: <em><?= h($s['sayfa']) ?></em><?php endif; ?></div>
  <div><strong><?= $f0(count($veri)) ?></strong> veri satırı · <strong><?= (int)$nSutun ?></strong> sütun</div>
  <div class="d-flex align-items-center gap-1">Başlık satırı:
    <select name="baslik_idx" class="form-select form-select-sm" style="width:auto" onchange="document.getElementById('fmEsle').submit()">
      <?php foreach (array_slice($s['satirlar'], 0, 15, true) as $i => $r): ?><option value="<?= $i ?>" <?= $i===$s['baslik_idx']?'selected':'' ?>>Satır <?= $i+1 ?>: <?= h(mb_substr(implode(' | ', array_filter($r)), 0, 60)) ?></option><?php endforeach; ?>
    </select></div>
  <a href="cihaz_import.php?iptal=1" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-x-lg me-1"></i>Vazgeç</a>
</div></div>

<?php if (!$kimlikVar): ?><div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>Cihazı tanımlayan sütun bulunamadı (IFS nesne no / cihaz kodu / envanter no / seri no / cihaz adı) — aşağıda elle eşleyin.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold small"><i class="bi bi-arrow-left-right me-1"></i>Sütun eşleme <span class="text-muted fw-normal">(otomatik önerildi — değiştirip "Ön izlemeyi yenile")</span></div>
    <div class="table-responsive" style="max-height:520px"><table class="table table-sm align-middle mb-0" style="font-size:.82rem">
      <thead class="table-light"><tr><th>Dosya sütunu</th><th>Örnek</th><th style="width:190px">Sistem alanı</th></tr></thead><tbody>
      <?php for ($i = 0; $i < $nSutun; $i++): $ornek = []; foreach (array_slice($veri, 0, 30) as $r) { if (trim((string)($r[$i] ?? '')) !== '') { $ornek[] = $r[$i]; if (count($ornek) >= 2) break; } } $sec = $s['harita'][$i] ?? ''; ?>
      <tr class="<?= $sec === '' ? 'text-muted' : '' ?>">
        <td class="fw-semibold"><?= h((string)($baslik[$i] ?? '')) ?: '<em class="fw-normal">(başlıksız ' . ($i+1) . ')</em>' ?></td>
        <td class="text-truncate" style="max-width:150px" title="<?= h(implode(' | ', $ornek)) ?>"><?= h(mb_substr(implode(' | ', $ornek), 0, 40)) ?></td>
        <td><select name="m[<?= $i ?>]" class="form-select form-select-sm <?= $sec ? 'border-success' : '' ?>"><?php foreach ($alanSecenek as $k => $et): ?><option value="<?= h($k) ?>" <?= $sec===$k?'selected':'' ?>><?= h($et) ?></option><?php endforeach; ?></select></td>
      </tr>
      <?php endfor; ?>
      </tbody></table></div></div>
  </div>
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
      <div class="fw-semibold mb-2"><i class="bi bi-sliders me-1"></i>Seçenekler</div>
      <?php if (yetki_var('duzenle')): ?>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="kisi_ekle" id="kisi_ekle" value="1" <?= !empty($s['opt']['kisi_ekle'])?'checked':'' ?>>
        <label class="form-check-label" for="kisi_ekle">Dosyadaki <strong>eşleşmeyen kişiler için personel kartı aç</strong> ve cihazları onlara zimmetle
        <span class="text-muted">(kapalıysa cihaz kaydedilir, zimmetli adı metin olarak yazılır, kişi raporda listelenir)</span></label></div>
      <?php else: ?><div class="text-muted">Ek seçenekler için "değiştirme" yetkisi gerekir.</div><?php endif; ?>
      <div class="alert alert-info py-2 mt-2 mb-0 small"><i class="bi bi-shield-check me-1"></i>Cihaz aktarımında <strong>tam yenileme yoktur</strong>: mevcut cihazlar silinmez, yalnız güncellenir. Dosyada olmayan cihaz olduğu gibi kalır.</div>
    </div></div>

    <?php $onizle = []; foreach (array_slice($veri, 0, 12) as $r) $onizle[] = cim_satir_cozumle($r, $s['harita'], $baslik); ?>
    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold small"><i class="bi bi-eye me-1"></i>Ön izleme — sistem böyle okuyacak (ilk <?= count($onizle) ?> satır)</div>
    <div class="table-responsive"><table class="table table-sm table-striped mb-0" style="font-size:.8rem">
      <thead class="table-light"><tr><th>Env. no</th><th>Varlık kodu</th><th>Cihaz</th><th>Kategori</th><th>Marka / Model</th><th>Seri no</th><th>Kişi</th><th>Lokasyon</th><th>Özellik</th></tr></thead><tbody>
      <?php foreach ($onizle as $v):
        $lokId = $v['lokasyon'] !== '' ? pim_lokasyon_bul($pdoIt, $v['lokasyon']) : null;
        $kat = $v['kategori'] !== '' ? cim_kategori($v['kategori']) : cim_kategori($v['ad']);
        [$pp, $pnasil] = $v['kisi'] !== '' ? cim_personel_bul($pdoIt, $v['kisi']) : [null, ''];
      ?>
      <tr class="<?= ($v['varlik_kodu']==='' && $v['envanter_no']==='' && $v['seri_no']==='' && $v['ad']==='') ? 'table-danger' : '' ?>">
        <td><?= h($v['envanter_no']) ?></td><td class="text-truncate" style="max-width:130px" title="<?= h($v['varlik_kodu']) ?>"><?= h($v['varlik_kodu']) ?></td>
        <td><?= h($v['ad']) ?></td>
        <td><span class="badge bg-light text-dark border"><i class="bi <?= h(IT_KATEGORI[$kat][1] ?? 'bi-box') ?> me-1"></i><?= h(IT_KATEGORI[$kat][0] ?? $kat) ?></span></td>
        <td><?= h(trim($v['marka'] . ' ' . $v['model'])) ?></td><td><?= h($v['seri_no']) ?></td>
        <td><?php if ($v['kisi'] !== ''): ?><?= $pp ? '<span class="text-success">' . h(it_personel_ad($pp)) . '</span>' : '<span class="text-warning" title="' . ($pnasil === 'coklu' ? 'aynı adlı birden çok personel' : 'personel kartı yok') . '">' . h($v['kisi']) . ' <i class="bi bi-question-circle"></i></span>' ?><?php endif; ?></td>
        <td><?php if ($v['lokasyon'] !== ''): ?><?= $lokId ? '<span class="text-success">' . h(it_lokasyon_yol($pdoIt, $lokId)) . '</span>' : '<span class="text-warning">' . h($v['lokasyon']) . ' <i class="bi bi-question-circle"></i></span>' ?><?php endif; ?></td>
        <?php
          // Donanım künyesi: çoklu sütunlar birleşmiş haliyle
          $tek = [];
          foreach (['islemci','ram','ekran_karti','disk'] as $__k) if (!empty($v[$__k])) $tek[] = implode(' · ', $v[$__k]);
          foreach (['anakart','ekran_boyutu','kapasite'] as $__k) if (!empty($v[$__k])) $tek[] = $v[$__k];
          if (!empty($v['ozellik'])) $tek[] = implode(' · ', $v['ozellik']);
          $tekMetin = implode(' · ', $tek);
        ?>
        <td class="text-truncate text-muted" style="max-width:180px" title="<?= h($tekMetin) ?>"><?= h(mb_substr($tekMetin, 0, 50)) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <div class="card-footer bg-white d-flex flex-wrap gap-2">
      <button class="btn btn-outline-primary" onclick="document.getElementById('islem').value='onizle'"><i class="bi bi-arrow-repeat me-1"></i>Ön izlemeyi yenile</button>
      <button class="btn btn-success" onclick="document.getElementById('islem').value='aktar'; return confirm('<?= $f0(count($veri)) ?> satır mevcut cihaz envanteriyle birleştirilecek. Devam?')" <?= $kimlikVar ? '' : 'disabled' ?>><i class="bi bi-check2-circle me-1"></i>İçe Aktar (<?= $f0(count($veri)) ?> satır)</button>
    </div></div>
  </div>
</div>
</form>

<?php elseif ($adim === 3 && $rap): $nY = count($rap['yeni']); $nG = count($rap['guncellenen']); $nA = count($rap['atlanan']);
      $kisiYok = []; foreach ($rap['kisi_yok'] as $k) $kisiYok[$k['kisi']] = $k['neden']; ?>
<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><strong><?= h($rap['dosya']) ?></strong> aktarıldı:
  okunan <?= $f0($rap['okunan']) ?> = yeni <?= $nY ?> + güncellenen <?= $nG ?> + değişmeyen <?= $rap['degismeyen'] ?> + atlanan <?= $nA ?>
  <?php if ($rap['okunan'] !== $nY + $nG + $rap['degismeyen'] + $nA): ?><span class="text-danger ms-2">(hesap tutmuyor!)</span><?php endif; ?>
</div>
<div class="row g-2 mb-3">
  <?php foreach ([['Okunan', $rap['okunan'], ''], ['Yeni cihaz', $nY, 'text-success'], ['Güncellenen', $nG, 'text-primary'], ['Değişmeyen', $rap['degismeyen'], ''], ['Atlanan', $nA, $nA ? 'text-danger' : ''], ['Eşleşmeyen kişi', count($kisiYok), $kisiYok ? 'text-warning' : '']] as [$et, $n, $cls]): ?>
  <div class="col-6 col-md-2"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted"><?= $et ?></div><div class="fs-5 fw-bold <?= $cls ?>"><?= $f0($n) ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<?php if (!empty($rap['kisi_eklenen']['adet'])): ?>
<div class="alert alert-info py-2 small"><i class="bi bi-person-plus me-1"></i><strong><?= (int)$rap['kisi_eklenen']['adet'] ?> personel kartı</strong> dosyadaki adlardan açıldı ve cihazlar bunlara zimmetlendi.
  <a href="personel.php" class="ms-1">Personel listesi</a></div>
<?php endif; ?>

<?php if ($kisiYok): ?>
<div class="alert alert-warning py-2 small"><i class="bi bi-person-exclamation me-1"></i><strong><?= count($kisiYok) ?> kişi personel kartıyla eşleşmedi</strong> (cihazlar kaydedildi, zimmetli adı metin olarak yazıldı):
  <?php foreach (array_slice($kisiYok, 0, 25, true) as $ad => $neden): ?><span class="badge bg-warning text-dark ms-1" title="<?= h($neden) ?>"><?= h($ad) ?></span><?php endforeach; ?>
  <?php if (count($kisiYok) > 25): ?><span class="text-muted"> +<?= count($kisiYok) - 25 ?> kişi</span><?php endif; ?>
  <?php if (yetki_var('duzenle')): ?>
  <form method="post" class="d-inline ms-2" onsubmit="return confirm('Bu adlar için personel kartı açılacak. Cihazların zimmetlenmesi için dosyayı tekrar yükleyin. Devam?')">
    <input type="hidden" name="islem" value="kisi_ekle">
    <?php foreach (array_keys($kisiYok) as $ad): ?><input type="hidden" name="kisi[]" value="<?= h($ad) ?>"><?php endforeach; ?>
    <button class="btn btn-warning btn-sm"><i class="bi bi-person-plus me-1"></i>Hepsi için personel kartı aç</button>
  </form>
  <?php endif; ?></div>
<?php endif; ?>

<?php if ($rap['lokasyon_yok']): ?>
<div class="alert alert-warning py-2 small"><i class="bi bi-geo-alt me-1"></i><strong>Lokasyon ağacında bulunamayan <?= count($rap['lokasyon_yok']) ?> ad</strong> (cihazın notuna yazıldı):
  <?php foreach ($rap['lokasyon_yok'] as $ad => $n): ?><span class="badge bg-warning text-dark ms-1"><?= h($ad) ?> ×<?= $n ?></span><?php endforeach; ?>
  <?php if (yetki_var('duzenle')): ?>
  <form method="post" class="d-inline ms-2" onsubmit="return confirm('Bu adlar yeni lokasyon olarak eklenecek. Devam?')">
    <input type="hidden" name="islem" value="lok_ekle">
    <?php foreach (array_keys($rap['lokasyon_yok']) as $ad): ?><input type="hidden" name="lok[]" value="<?= h($ad) ?>"><?php endforeach; ?>
    <button class="btn btn-warning btn-sm"><i class="bi bi-plus-circle me-1"></i>Hepsini lokasyon olarak ekle</button>
  </form>
  <?php endif; ?></div>
<?php endif; ?>

<?php if ($rap['atlanan']): ?>
<details class="card border-0 shadow-sm mb-3" open><summary class="card-header bg-white fw-semibold small text-danger">Atlanan satırlar (<?= $nA ?>)</summary>
<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.82rem"><thead class="table-light"><tr><th>Excel satırı</th><th>Kim</th><th>Sebep</th></tr></thead><tbody>
<?php foreach ($rap['atlanan'] as $a): ?><tr><td><?= (int)$a['satir'] ?></td><td><?= h($a['kim']) ?></td><td><?= h($a['neden']) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
<?php endif; ?>

<?php if ($rap['yeni']): ?>
<details class="card border-0 shadow-sm mb-3" <?= $nY <= 30 ? 'open' : '' ?>><summary class="card-header bg-white fw-semibold small text-success">Yeni eklenen cihazlar (<?= $nY ?>)</summary>
<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.82rem"><thead class="table-light"><tr><th>Envanter no</th><th>Cihaz</th><th>Kategori</th><th>Marka / Model</th><th>Zimmetli</th><th>Lokasyon</th></tr></thead><tbody>
<?php foreach ($rap['yeni'] as $y): ?><tr><td><a href="cihaz_detay.php?id=<?= (int)$y['id'] ?>"><?= h($y['env']) ?></a></td><td><?= h($y['ad']) ?></td><td><?= h(IT_KATEGORI[$y['kategori']][0] ?? $y['kategori']) ?></td><td><?= h($y['marka']) ?></td><td><?= h((string)$y['kisi']) ?></td><td><?= h((string)$y['lok']) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
<?php endif; ?>

<?php if ($rap['guncellenen']): ?>
<details class="card border-0 shadow-sm mb-3" <?= $nG <= 30 ? 'open' : '' ?>><summary class="card-header bg-white fw-semibold small text-primary">Güncellenen cihazlar (<?= $nG ?>)</summary>
<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.82rem"><thead class="table-light"><tr><th>Cihaz</th><th>Eşleşme</th><th>Değişen alanlar</th></tr></thead><tbody>
<?php foreach ($rap['guncellenen'] as $g): ?><tr><td><a href="cihaz_detay.php?id=<?= (int)$g['id'] ?>"><?= h($g['kim']) ?></a></td><td><span class="badge bg-light text-dark border"><?= h($g['nasil']) ?></span></td><td><?= h(implode(' · ', $g['degisen'])) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
<?php endif; ?>

<div class="d-flex gap-2"><a href="cihazlar.php" class="btn btn-primary"><i class="bi bi-pc-display me-1"></i>Cihaz listesine git</a><a href="cihaz_import.php?iptal=1" class="btn btn-outline-secondary"><i class="bi bi-cloud-arrow-up me-1"></i>Yeni dosya yükle</a></div>
<?php endif; ?>

<?php if ($gecmis && $adim !== 2): ?>
<div class="card border-0 shadow-sm mt-4"><div class="card-header bg-white fw-semibold small"><i class="bi bi-clock-history me-1"></i>Son cihaz yüklemeleri</div>
<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.82rem"><thead class="table-light"><tr><th>Tarih</th><th>Dosya</th><th class="text-end">Okunan</th><th class="text-end">Yeni</th><th class="text-end">Güncellenen</th><th class="text-end">Değişmeyen</th><th class="text-end">Atlanan</th><th>Kullanıcı</th></tr></thead><tbody>
<?php foreach ($gecmis as $g): ?><tr><td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime($g['created_at']))) ?></td><td><?= h(str_replace(' [cihaz]', '', (string)$g['dosya'])) ?> <span class="badge bg-light text-dark border"><?= h($g['bicim']) ?></span></td><td class="text-end"><?= (int)$g['okunan'] ?></td><td class="text-end"><?= (int)$g['yeni'] ?></td><td class="text-end"><?= (int)$g['guncellenen'] ?></td><td class="text-end"><?= (int)$g['degismeyen'] ?></td><td class="text-end"><?= (int)$g['atlanan'] ?></td><td><?= h($g['kullanici']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
