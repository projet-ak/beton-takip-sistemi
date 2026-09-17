<?php
/**
 * it/cihaz_form.php — Cihaz / lisans ekle-düzenle
 * Yeni kayıtta envanter no otomatik (IT-00001). Kayıt silinmez; durum "hurda" yapılır.
 * Fotoğraf/fatura yüklemesi kayıt sonrası detay ekranından (çoklu) ya da buradan tek dosya.
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
 * Formda LİSTEDEN seçilen alanlar — hepsi `IT_TANIM_TUR` türüdür ve "+ ekle" ucu yalnız
 * bunları kabul eder (beyaz liste; başka tür Tanımlar ekranından yönetilir).
 * ⚠ Serbest metin bırakılan alanlar bilerek dışarıda: seri no · IFS kodu · IMEI · IP/MAC ·
 * fatura no · SAS ref · disk seri no — bunlar CİHAZA ÖZGÜdür, listelenecek bir şey değil.
 */
const IT_FORM_TANIM = ['uretici','model','tedarikci','sirket','isletim_sistemi','islemci','ram',
                       'ekran_karti','disk','anakart','ekran_boyutu','kiralik_firma','transfer_birim'];

/** `IT_EK_ALAN` içinde listeden seçilenler: alan => tanım türü (gerisi serbest metin kalır). */
const IT_FORM_EK_TANIM = ['islemci' => 'islemci', 'ram' => 'ram', 'ekran_karti' => 'ekran_karti',
                          'disk' => 'disk', 'anakart' => 'anakart', 'ekran_boyutu' => 'ekran_boyutu',
                          'kiralik_firma' => 'kiralik_firma'];

/* ── "Listede yoksa oluştur" ucu ──────────────────────────────────────────────
   Bu alanlar serbest METİN değil LİSTEDEN seçilir; sahada olmayan bir değer çıkarsa
   kullanıcı formdan ayrılmadan ekleyebilsin diye burada açılır (Tanımlar ekranına gidip
   geri dönmek akışı kesiyordu). Mükerrer engeli `it_tanim_ekle` içinde `it_norm` ile.
   ⚠ POST'tur: `auth.php` CSRF'i merkezî doğrular, istemci token'ı gövdede gönderir. */
if (($_POST['islem'] ?? '') === 'tanim_ekle') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!yetki_var('duzenle') && !yetki_var('giris')) throw new RuntimeException('Tanım ekleme yetkiniz yok.');
        $tur = (string)($_POST['tur'] ?? '');
        if (!in_array($tur, IT_FORM_TANIM, true)) throw new RuntimeException('Bu alan için tanım eklenemez.');
        $r = it_tanim_ekle($pdoIt, $tur, (string)($_POST['ad'] ?? ''));
        audit_log($pdoIt, 'it_tanimlar', 0, 'INSERT', null, ['tur' => $tur, 'ad' => $r['ad']], current_user_id());
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'hata' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ── Personel arama ucu (zimmet kutusundaki yazarak seçme) ────────────────────
   Süzme it_personel_ara() ile PHP'de yapılır — SQL LIKE'ta Türkçe 'İ' ile 'i' eşleşmediğinden
   "ismail" yazınca "İSMAİL" sessizce düşüyordu; kelime sırası da serbest ("ince fatih").
   Kişi başına cihaz sayısı TEK sorguyla toplanır (satır satır sayılsa 25 kişi = 25 sorgu). */
if (isset($_GET['personel_ara'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sayac = [];
    try {
        foreach ($pdoIt->query("SELECT personel_id, COUNT(*) n FROM it_cihazlar
                                WHERE personel_id IS NOT NULL AND " . it_envanterde() . " GROUP BY personel_id") as $r)
            $sayac[(int)$r['personel_id']] = (int)$r['n'];
    } catch (Throwable $e) {}
    $out = [];
    foreach (it_personel_ara($pdoIt, (string)$_GET['personel_ara'], 25) as $p) {
        $out[] = [
            'id'     => (int)$p['id'],
            'ad'     => it_personel_ad($p),
            'sicil'  => (string)($p['sicil_no'] ?? ''),
            'unvan'  => (string)($p['unvan'] ?? ''),
            'birim'  => (string)($p['birim'] ?? ''),
            'lok_id' => (int)($p['lokasyon_id'] ?? 0),
            'lok'    => $p['lokasyon_id'] ? it_lokasyon_etiket($pdoIt, (int)$p['lokasyon_id']) : '',
            'cihaz'  => $sayac[(int)$p['id']] ?? 0,
        ];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
$c  = null;
if ($id) {
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE id=?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) { flash('error', 'Cihaz bulunamadı.'); redirect('cihazlar.php'); }
}
$duzenleme = (bool)$c;
if ($duzenleme ? !yetki_var('duzenle') : !yetki_var('giris')) {
    flash('error', $duzenleme ? 'Kayıt değiştirme yetkiniz yok.' : 'Veri girişi yetkiniz yok.');
    redirect($duzenleme ? 'cihaz_detay.php?id=' . $id : 'cihazlar.php');
}

$error = '';
$v = $c ?: ['envanter_no'=>'', 'kategori'=>'laptop', 'ad'=>'', 'marka'=>'', 'model'=>'', 'seri_no'=>'',
            'varlik_kodu'=>'', 'cihaz_kodu'=>'', 'sasi_no'=>'', 'imei'=>'', 'sirket'=>'', 'durum'=>'depoda',
            'personel_id'=>'', 'zimmetli'=>'', 'departman'=>'', 'lokasyon_id'=>'', 'lokasyon'=>'', 'zimmet_tarihi'=>'', 'alis_tarihi'=>'', 'garanti_bitis'=>'',
            'fiyat'=>'', 'para_birimi'=>'TRY', 'kur'=>'', 'fiyat_tl'=>'',
            'tedarikci'=>'', 'fatura_no'=>'', 'ip_adresi'=>'', 'mac_adresi'=>'', 'isletim_sistemi'=>'',
            'ozellikler'=>'', 'lisans_anahtari'=>'', 'lisans_adet'=>'', 'notlar'=>'']
     + array_fill_keys(array_keys(IT_EK_ALAN), '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $al = fn($k, $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $y = [
        'kategori'        => isset(IT_KATEGORI[$_POST['kategori'] ?? '']) ? $_POST['kategori'] : 'diger',
        'ad'              => $al('ad', 150),
        'marka'           => $al('marka', 80),
        'sirket'          => $al('sirket', 120),
        'model'           => $al('model', 120),
        'seri_no'         => $al('seri_no', 120),
        // Kimlik alanları kategoriden BAĞIMSIZ: kurumsal envanterde her cihazın IFS "Nesne No"su,
        // ikinci bir seri (şasi / servis etiketi) ve giderek her cihazda bir IMEI'si olabiliyor.
        'varlik_kodu'     => $al('varlik_kodu', 60),
        'cihaz_kodu'      => $al('cihaz_kodu', 60),
        'sasi_no'         => $al('sasi_no', 120),
        'imei'            => $al('imei', 32),
        'durum'           => isset(IT_DURUM[$_POST['durum'] ?? '']) ? $_POST['durum'] : 'depoda',
        'personel_id'     => (int)($_POST['personel_id'] ?? 0) ?: null,
        'zimmetli'        => $al('zimmetli', 120),
        'departman'       => $al('departman', 80),
        'lokasyon_id'     => (int)($_POST['lokasyon_id'] ?? 0) ?: null,
        'lokasyon'        => $al('lokasyon', 120),
        'zimmet_tarihi'   => it_tarih($_POST['zimmet_tarihi'] ?? ''),
        'alis_tarihi'     => it_tarih($_POST['alis_tarihi'] ?? ''),
        'garanti_bitis'   => it_tarih($_POST['garanti_bitis'] ?? ''),
        // Alış tutarı / para birimi / kur birlikte anlamlıdır: TL karşılığı `it_fiyat_coz` ile
        // türetilip `fiyat_tl`'ye yazılır — mali değer toplamları o sütundan alınır.
        'tedarikci'       => $al('tedarikci', 120),
        'fatura_no'       => $al('fatura_no', 60),
        'ip_adresi'       => $al('ip_adresi', 45),
        'mac_adresi'      => $al('mac_adresi', 40),
        'isletim_sistemi' => $al('isletim_sistemi', 80),
        'ozellikler'      => $al('ozellikler', 255),
        'lisans_anahtari' => $al('lisans_anahtari', 160),
        'lisans_adet'     => ($_POST['lisans_adet'] ?? '') !== '' ? max(0, (int)$_POST['lisans_adet']) : null,
        'sas_ref'         => $al('sas_ref', 40),
        'transfer_birim'  => $al('transfer_birim', 120),
        'transfer_tarihi' => it_tarih($_POST['transfer_tarihi'] ?? ''),
        'notlar'          => trim((string)($_POST['notlar'] ?? '')) ?: null,
    ];
    // ── Alış tutarı / para birimi / kur ──────────────────────────────────────────
    // Üçü tek künyedir: TL karşılığı (`fiyat_tl`) buradan TÜRETİLİR ve saklanır; mali değer
    // toplamları hep o sütundan alınır (farklı para birimlerindeki tutarlar toplanamaz).
    // Mali gösterim kapalıyken alanlar gizli input'la geldiğinden mevcut değer yine korunur.
    $y += it_fiyat_coz($_POST['fiyat'] ?? '', $_POST['para_birimi'] ?? 'TRY', $_POST['kur'] ?? '');

    // Kategoriye özel alanlar (IP telefon dahilisi, superbox IMEI'si, NVR disk kapasitesi…).
    // Yalnız o kategoride GÖSTERİLEN alanlar yazılır; kategori değişirse eskiler temizlenir —
    // aksi halde monitöre dönüşen bir kayıtta "dahili no" hayalet veri olarak kalırdı.
    $gorunen = it_ek_alanlar($y['kategori']);
    foreach (IT_EK_ALAN as $alan => [$_e, $_k, $tip, $_i]) {
        if (!isset($gorunen[$alan])) { $y[$alan] = null; continue; }
        $ham = trim((string)($_POST[$alan] ?? ''));
        $y[$alan] = match ($tip) {
            'sayi'  => $ham !== '' ? max(0, (int)$ham) : null,
            'cihaz' => (int)$ham ?: null,
            default => mb_substr($ham, 0, 250) ?: null,
        };
    }
    // Yönetim şifresi yalnız "değiştirme" yetkisiyle güncellenir; boş bırakılırsa mevcut şifre KORUNUR
    if (array_key_exists('yonetim_sifre', $y)) {
        if (!yetki_var('duzenle') || (trim((string)($_POST['yonetim_sifre'] ?? '')) === '' && $duzenleme && isset($gorunen['yonetim_sifre'])))
            $y['yonetim_sifre'] = $c['yonetim_sifre'] ?? null;
    }
    // Serbest "Özellikler" metni boşsa donanım künyesinden özet üretilir (liste/Excel/tutanak için)
    if (!$y['ozellikler']) { $ozet = it_ozellik_ozet($y); if ($ozet !== '') $y['ozellikler'] = $ozet; }
    it_cihaz_bag_esitle($pdoIt, $y);   // personel seçildiyse zimmetli/departman, lokasyon seçildiyse yol metni dolar
    // ⚠ "Zimmeti kaldır" ile kişi çıkarıldıysa ESKİ AD METNİ de silinmeli — gizli `zimmetli` alanı
    // eski değeri taşıdığı için cihaz kimsede değilken listede hâlâ o kişide görünüyordu.
    // Kişi kartına hiç bağlanmamış ESKİ kayıtların metnine dokunulmaz (personel_id zaten yoktu).
    if (empty($y['personel_id']) && !empty($c['personel_id'])) { $y['zimmetli'] = null; $y['departman'] = $y['departman'] ?: null; }
    // Zimmetli kişi doluysa durum otomatik "kullanımda", boşsa "kullanımda" olamaz
    if ($y['zimmetli'] && in_array($y['durum'], ['depoda'], true)) $y['durum'] = 'aktif';
    if (!$y['zimmetli'] && $y['durum'] === 'aktif' && $y['kategori'] !== 'yazilim') $y['durum'] = 'depoda';   // lisans kişisiz de kullanımda olabilir
    if ($y['zimmetli'] && !$y['zimmet_tarihi']) $y['zimmet_tarihi'] = date('Y-m-d');
    if (!$y['zimmetli']) $y['zimmet_tarihi'] = null;

    if (!$y['ad']) {
        $error = 'Cihaz adı zorunludur.';
    } else {
        try {
            $kul = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
            if ($duzenleme) {
                $sql = "UPDATE it_cihazlar SET " . implode(', ', array_map(fn($k) => "$k=?", array_keys($y))) . " WHERE id=?";
                $pdoIt->prepare($sql)->execute([...array_values($y), $id]);
                // Değişen kritik alanları günlüğe yaz
                $deg = [];
                foreach (['durum', 'zimmetli', 'departman', 'lokasyon', 'seri_no', 'garanti_bitis'] as $k)
                    if ((string)($c[$k] ?? '') !== (string)($y[$k] ?? '')) $deg[] = $k . ': ' . (($c[$k] ?? '') ?: '—') . ' → ' . (($y[$k] ?? '') ?: '—');
                if ($c['zimmetli'] !== $y['zimmetli']) {
                    if ($y['zimmetli']) it_hareket_ekle($pdoIt, $id, 'zimmet', $y['zimmetli'], 'Formdan zimmet verildi' . ($y['departman'] ? ' (' . $y['departman'] . ')' : ''), $y['zimmet_tarihi']);
                    else it_hareket_ekle($pdoIt, $id, 'iade', $c['zimmetli'], 'Formdan zimmet kaldırıldı');
                } elseif ($deg) {
                    it_hareket_ekle($pdoIt, $id, 'guncelleme', null, implode(' · ', $deg));
                }
                flash('success', 'Cihaz güncellendi.');
            } else {
                $y['envanter_no'] = it_envanter_no($pdoIt);
                $y['olusturan']   = $kul;
                $sql = "INSERT INTO it_cihazlar (" . implode(',', array_keys($y)) . ") VALUES (" . implode(',', array_fill(0, count($y), '?')) . ")";
                $pdoIt->prepare($sql)->execute(array_values($y));
                $id = (int)$pdoIt->lastInsertId();
                it_hareket_ekle($pdoIt, $id, 'giris', $y['tedarikci'], 'Envantere kaydedildi' . ($y['fatura_no'] ? ' — fatura ' . $y['fatura_no'] : ''), $y['alis_tarihi'] ?: null);
                if ($y['zimmetli']) it_hareket_ekle($pdoIt, $id, 'zimmet', $y['zimmetli'], 'Kayıtla birlikte zimmetlendi', $y['zimmet_tarihi']);
                flash('success', $y['envanter_no'] . ' envanter numarasıyla kaydedildi.');
            }
            // İsteğe bağlı tek fotoğraf / belge
            if (!empty($_FILES['foto']['name'])) {
                [$ok, $msg] = it_belge_yukle($pdoIt, $id, $_FILES['foto'], $kul);
                if (!$ok) flash('warning', 'Belge yüklenemedi: ' . strip_tags($msg));
            }
            redirect('cihaz_detay.php?id=' . $id);
        } catch (PDOException $e) {
            $error = 'Veritabanı hatası: ' . $e->getMessage();
        }
    }
    $v = array_merge($v, $y);
}

$pageTitle = ($duzenleme ? 'Cihaz Düzenle — ' . $c['envanter_no'] : 'Yeni Cihaz') . ' — IT Envanter';
// "Bağlı olduğu cihaz" adayları: kamera→NVR, turnike→geçiş ünitesi, IP telefon→santral, AP→switch
$bagliAdaylar = [];
try {
    $bagliAdaylar = $pdoIt->query("SELECT id, envanter_no, ad, kategori FROM it_cihazlar
        WHERE kategori IN ('nvr','santral','kartli_gecis','switch','firewall') AND " . it_envanterde() . "
        ORDER BY kategori, envanter_no")->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
// Öneri listeleri (datalist): marka/model/şirket/tedarikçi ve donanım künyesi artık SELECT
// (it_tanim_options) — burada yalnız serbest metin kalan alanlar var.
$sec = ['zimmetli' => it_secenekler($pdoIt, 'zimmetli'), 'departman' => it_secenekler($pdoIt, 'departman'),
        'lokasyon' => it_secenekler($pdoIt, 'lokasyon')];
$dl = function (string $k) use ($sec) {
    if (empty($sec[$k])) return '';
    $o = '<datalist id="dl_' . $k . '">';
    foreach ($sec[$k] as $x) $o .= '<option value="' . h($x) . '">';
    return $o . '</datalist>';
};
$tv = fn($k) => h($v[$k] ?? '');

/**
 * LİSTEDEN SEÇ + "listede yoksa ekle" alanı — Marka'daki desen tek yere toplandı
 * (dokuz alanı elle kopyalamak yerine). `it_tanim_options` kayıtta duran ama listede
 * olmayan değeri "kayıtta duran (listede yok)" optgroup'unda KORUR; "+" düğmesi
 * sayfanın kendi POST ucuna gider ve dönen adı select'e ekleyip seçer.
 */
$tanimSec = function (string $alan, string $tur, string $etiket, string $not = '', string $sinif = '', string $attr = '')
            use ($pdoIt, $v) {
    $id = 'ts_' . $alan;
    ob_start(); ?>
    <div class="col-md-3 tanim-alan<?= trim((string)($v[$alan] ?? '')) !== '' ? ' dolu' : '' ?> <?= h($sinif) ?>" <?= $attr ?>>
      <label class="form-label" for="<?= h($id) ?>"><?= h($etiket) ?></label>
      <div class="input-group">
        <select name="<?= h($alan) ?>" id="<?= h($id) ?>" class="form-select"><?= it_tanim_options($pdoIt, $tur, (string)($v[$alan] ?? '')) ?></select>
        <?php if (can_edit()): ?>
        <button type="button" class="btn btn-outline-secondary tanim-ekle" data-tur="<?= h($tur) ?>" data-hedef="<?= h($id) ?>"
                title="<?= h($etiket) ?> listesinde yoksa ekle"><i class="bi bi-plus-lg"></i></button>
        <?php endif; ?>
      </div>
      <?php /* "+" ipucu HER alanda görünür — bazı alanlarda örnek metin, bazılarında ipucu
               yazsaydı kullanıcı ekleyebileceğini fark etmezdi. */ ?>
      <div class="form-text" id="<?= h($id) ?>_not"><?= $not !== '' ? h($not) . (can_edit() ? ' · yoksa <strong>+</strong> ile ekleyin.' : '') : 'Listeden seçin' . (can_edit() ? '; yoksa <strong>+</strong> ile ekleyin.' : '.') ?></div>
    </div>
    <?php return ob_get_clean();
};

// Zimmet kutusu açılışta DOLU gelsin: seçili kişinin künyesi (düzenleme ya da hatalı POST sonrası)
$zKisi = null;
if (!empty($v['personel_id'])) {
    $__p = it_personel_bul($pdoIt, (int)$v['personel_id']);
    if ($__p) $zKisi = [
        'id'     => (int)$__p['id'],
        'ad'     => it_personel_ad($__p),
        'sicil'  => (string)($__p['sicil_no'] ?? ''),
        'unvan'  => (string)($__p['unvan'] ?? ''),
        'birim'  => (string)($__p['birim'] ?? ''),
        'lok_id' => (int)($__p['lokasyon_id'] ?? 0),
        'lok'    => $__p['lokasyon_id'] ? it_lokasyon_etiket($pdoIt, (int)$__p['lokasyon_id']) : '',
        'ayrildi'=> !empty($__p['isten_cikis']),
    ];
}
?>
<style>
  /* Bölüm başlıkları: gri "hr + soluk yazı" yerine marka renkli, sol aksanlı şerit —
     uzun form hangi bölümde olduğunu bir bakışta göstersin. Renkler tema değişkenlerinden
     gelir (açık/koyu/yüksek kontrast + renk paleti otomatik izlenir). */
  .bolum-basi {
    display:flex; align-items:center; gap:.4rem;
    margin:1.1rem 0 .15rem; padding:.42rem .7rem;
    font-size:.78rem; font-weight:700; letter-spacing:.6px; text-transform:uppercase;
    color:var(--bs-primary);
    background:rgba(var(--bs-primary-rgb), .07);
    border-left:3px solid var(--bs-primary); border-radius:.35rem;
  }
  .bolum-basi .fw-normal { text-transform:none; letter-spacing:0; opacity:.75; }
  /* Listeden seçilen alanlar: dolu olan yeşil imli, boş olan nötr — "neyi doldurdum" görünür */
  .tanim-alan.dolu > .input-group > .form-select { border-color:rgba(var(--bs-success-rgb), .55); }
  .tanim-alan.dolu > label::after {
    content:"\F26E"; font-family:"bootstrap-icons"; font-size:.7rem;
    color:var(--bs-success); margin-left:.3rem; vertical-align:middle;
  }
  .tanim-alan .btn { --bs-btn-padding-x:.6rem; }
</style>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= $duzenleme ? 'cihaz_detay.php?id=' . $id : 'cihazlar.php' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-pc-display text-primary me-2"></i><?= $duzenleme ? 'Cihaz Düzenle' : 'Yeni Cihaz / Lisans' ?></h4>
    <?php if ($duzenleme): ?><span class="badge bg-light text-dark border font-monospace"><?= h($c['envanter_no']) ?></span><?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Kategori <span class="text-danger">*</span></label>
        <select name="kategori" id="kategori" class="form-select" required>
          <?php foreach (it_kategori_agaci() as $__g => $__kats): ?>
          <optgroup label="<?= h(IT_GRUP[$__g][0] ?? $__g) ?>">
            <?php foreach ($__kats as $k => $ad): ?>
            <option value="<?= $k ?>" <?= ($v['kategori'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Cihaz Adı / Tanım <span class="text-danger">*</span></label>
        <input name="ad" class="form-control" value="<?= $tv('ad') ?>" required maxlength="150" placeholder="ör. Şantiye ofisi dizüstü, Muhasebe yazıcısı">
      </div>
      <div class="col-md-4">
        <label class="form-label">Durum</label>
        <select name="durum" id="durum" class="form-select">
          <?php foreach (IT_DURUM as $k => [$ad, $r, $ik]): ?>
          <option value="<?= $k ?>" <?= ($v['durum'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Zimmetli kişi girilirse durum otomatik "Kullanımda" olur.</div>
      </div>

      <?php /* Marka · Model · Şirket LİSTEDEN seçilir (elle yazım "LENOVO"/"Lenovo"/"lenova" gibi
                üç ayrı marka üretiyordu). Listede olmayan değer "+" ile formdan ayrılmadan eklenir.
                ⚠ Seri No serbest metindir — cihaza özgüdür, listelenecek bir şey değil. */ ?>
      <?= $tanimSec('marka',  'uretici', 'Marka') ?>
      <?= $tanimSec('model',  'model',   'Model') ?>
      <div class="col-md-3"><label class="form-label">Seri No</label><input name="seri_no" class="form-control font-monospace" value="<?= $tv('seri_no') ?>" maxlength="120"></div>
      <?= $tanimSec('sirket', 'sirket',  'Şirket', 'ERN Holding / ERN Taahhüt…') ?>

      <?php /* Kimlik alanları: kurumsal envanterin (IFS) nesne kodu, ikinci seri ve IMEI — her kategoride görünür */ ?>
      <div class="col-12"><div class="bolum-basi"><i class="bi bi-upc-scan me-1"></i>KİMLİK KODLARI
        <span class="fw-normal">— envanter no yanında cihazı bulmayı sağlayan numaralar; hepsi aramada taranır</span></div></div>
      <div class="col-md-3"><label class="form-label">IFS Seri Nesne No</label>
        <input name="varlik_kodu" class="form-control font-monospace" value="<?= $tv('varlik_kodu') ?>" maxlength="60" placeholder="FRM-0002-82026-2552600167">
        <div class="form-text">Kurumsal envanterdeki (IFS) demirbaş kodu — içe aktarmada eşleşme önce bundan yapılır.</div></div>
      <div class="col-md-3"><label class="form-label">Cihaz Kodu</label>
        <input name="cihaz_kodu" class="form-control font-monospace" value="<?= $tv('cihaz_kodu') ?>" maxlength="60" placeholder="N221 / M160">
        <div class="form-text">Kurum içi demirbaş etiketi. Envanter no (IT-…) bizim sabit numaramızdır, değişmez.</div></div>
      <div class="col-md-3"><label class="form-label">Şasi No / 2. Seri No</label>
        <input name="sasi_no" class="form-control font-monospace" value="<?= $tv('sasi_no') ?>" maxlength="120" placeholder="servis etiketi / şasi"></div>
      <div class="col-md-3"><label class="form-label">IMEI</label>
        <input name="imei" class="form-control font-monospace" value="<?= $tv('imei') ?>" maxlength="32" placeholder="356938035643809"></div>

      <div class="col-12"><div class="bolum-basi"><i class="bi bi-person-check me-1"></i>ZİMMET</div></div>
      <?php /* ⚠ ARANABİLİR PERSONEL SEÇİCİ — 180 kişilik açılır menüde doğru kişiyi bulmak zordu.
                Cihaz kartındaki (cihaz_detay.php) seçiciyle AYNI desen: süzme sayfanın kendi JSON ucunda
                (?personel_ara=) it_norm ile yapılır, SQL LIKE ile DEĞİL — Türkçe 'İ' LIKE'ta 'i' ile
                eşleşmiyor ve ad+soyad birlikte yazılınca tek alanda geçmediği için sonuç çıkmıyordu.
                JS kapalıysa <noscript> klasik select devreye girer. */ ?>
      <div class="col-md-4" id="kisiKutu"><label class="form-label">Zimmetli Personel</label>
        <div class="position-relative">
          <input type="text" id="kisiAra" class="form-control" autocomplete="off"
                 placeholder="Ad, soyad, sicil ya da birim yazın…"
                 value="<?= $zKisi ? h($zKisi['ad']) : '' ?>">
          <input type="hidden" name="personel_id" id="personel_id" value="<?= (int)($v['personel_id'] ?? 0) ?: '' ?>">
          <div id="kisiListe" class="list-group position-absolute w-100 shadow d-none"
               style="z-index:1080;max-height:280px;overflow:auto"></div>
        </div>
        <div id="kisiSecili" class="small mt-1 <?= $zKisi ? '' : 'd-none' ?>">
          <?php if ($zKisi): ?>
            <span class="badge bg-<?= $zKisi['ayrildi'] ? 'danger' : 'success' ?>"><i class="bi bi-person-check me-1"></i><?= h($zKisi['ad']) ?></span>
            <span class="text-muted"><?= h(implode(' · ', array_filter([$zKisi['sicil'], $zKisi['birim'], $zKisi['lok']]))) ?></span>
            <?php if ($zKisi['ayrildi']): ?><span class="text-danger">· işten ayrılmış</span><?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="form-text d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span>Adın bir kısmını yazmanız yeter.</span>
          <span class="d-flex gap-1">
            <button type="button" class="btn btn-outline-secondary btn-sm py-0" id="kisiTemizle"><i class="bi bi-x-lg me-1"></i>Zimmeti kaldır</button>
            <button type="button" class="btn btn-outline-primary btn-sm py-0" id="yeniKisi"><i class="bi bi-person-plus me-1"></i>Yeni personel</button>
          </span>
        </div>
        <?php if (!empty($v['zimmetli']) && empty($v['personel_id'])): ?>
        <div class="form-text text-warning-emphasis">Eski kayıt: <strong><?= $tv('zimmetli') ?></strong> — kişi kartına bağlı değil, yukarıdan seçerek bağlayın.</div>
        <?php endif; ?>
        <noscript><select name="personel_id" class="form-select mt-1"><?= it_personel_options($pdoIt, (int)($v['personel_id'] ?? 0), !empty($v['personel_id'])) ?></select></noscript>
        <input type="hidden" name="zimmetli" value="<?= $tv('zimmetli') ?>"></div>
      <div class="col-md-3"><label class="form-label">Departman</label><input name="departman" id="departman" list="dl_departman" class="form-control" value="<?= $tv('departman') ?>" maxlength="80"><?= $dl('departman') ?></div>
      <div class="col-md-3"><label class="form-label">Lokasyon / Proje</label>
        <select name="lokasyon_id" id="lokasyon_id" class="form-select"><?= it_lokasyon_options($pdoIt, (int)($v['lokasyon_id'] ?? 0)) ?></select>
        <input type="hidden" name="lokasyon" value="<?= $tv('lokasyon') ?>"><?php if (!empty($v['lokasyon']) && empty($v['lokasyon_id'])): ?><div class="form-text">Eski kayıt: <?= $tv('lokasyon') ?></div><?php endif; ?></div>
      <div class="col-md-2"><label class="form-label">Zimmet Tarihi</label><input type="date" name="zimmet_tarihi" class="form-control" value="<?= $tv('zimmet_tarihi') ?>"></div>

      <div class="col-12"><div class="bolum-basi"><i class="bi bi-receipt me-1"></i>SATIN ALMA<?= it_mali_goster() ? ' &amp; FİYAT / KUR' : '' ?></div></div>
      <div class="col-md-2"><label class="form-label">Alış Tarihi</label><input type="date" name="alis_tarihi" class="form-control" value="<?= $tv('alis_tarihi') ?>"></div>
      <?= $tanimSec('tedarikci', 'tedarikci', 'Tedarikçi') ?>
      <div class="col-md-3"><label class="form-label">Fatura No</label><input name="fatura_no" class="form-control" value="<?= $tv('fatura_no') ?>" maxlength="60"></div>
      <div class="col-md-2"><label class="form-label">SAS Ref</label><input name="sas_ref" class="form-control" value="<?= $tv('sas_ref') ?>" maxlength="40" placeholder="satın alma ref.">
        <div class="form-text">Satın alma talep/sipariş referansı.</div></div>
      <?php if (it_mali_goster()): ?>
      <div class="col-md-2"><label class="form-label">Garanti Bitiş</label><input type="date" name="garanti_bitis" class="form-control" value="<?= $tv('garanti_bitis') ?>"></div>

      <?php
        // Alış tutarı ALINDIĞI para biriminde girilir; TL karşılığı alış günündeki kurla hesaplanır
        // ve `fiyat_tl`'ye yazılır — mali değer toplamları HEP TL karşılığından alınır.
        $paraSecili = it_para_norm($v['para_birimi'] ?? 'TRY');
        $nf = fn($x, $d = 2) => ($x !== null && $x !== '') ? number_format((float)$x, $d, ',', '.') : '';
      ?>
      <div class="col-md-2"><label class="form-label">Alış Tutarı</label>
        <input name="fiyat" id="fiyat" class="form-control text-end" value="<?= h($nf($v['fiyat'] ?? null)) ?>" placeholder="0,00" inputmode="decimal"></div>
      <div class="col-md-2"><label class="form-label">Para Birimi</label>
        <select name="para_birimi" id="para_birimi" class="form-select">
          <?php foreach (IT_PARA as $pk => $pt): ?>
            <option value="<?= h($pk) ?>"<?= $pk === $paraSecili ? ' selected' : '' ?>><?= h($pk . ' — ' . $pt[1]) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-md-2" id="kurKutu"><label class="form-label">Kur (alış günü)</label>
        <input name="kur" id="kur" class="form-control text-end" value="<?= h($nf($v['kur'] ?? null, 4)) ?>" placeholder="0,0000" inputmode="decimal">
        <div class="form-text" id="kurNot">1 birim = ? ₺</div></div>
      <div class="col-md-2" id="tlKutu"><label class="form-label">TL Karşılığı</label>
        <input id="fiyat_tl_gos" class="form-control text-end bg-body-secondary" value="<?= h($nf($v['fiyat_tl'] ?? null)) ?>" readonly tabindex="-1">
        <div class="form-text">Tutar × kur (otomatik).</div></div>
      <?php else: /* garanti + fiyat gizli — mevcut değerler kaybolmasın diye gizli alanla taşınır */ ?>
      <input type="hidden" name="garanti_bitis" value="<?= $tv('garanti_bitis') ?>">
      <input type="hidden" name="fiyat" value="<?= $v['fiyat'] !== null && $v['fiyat'] !== '' ? number_format((float)$v['fiyat'], 2, ',', '.') : '' ?>">
      <input type="hidden" name="para_birimi" value="<?= h(it_para_norm($v['para_birimi'] ?? 'TRY')) ?>">
      <input type="hidden" name="kur" value="<?= $v['kur'] !== null && $v['kur'] !== '' ? number_format((float)$v['kur'], 4, ',', '.') : '' ?>">
      <?php endif; ?>
      <div class="col-md-3"><label class="form-label">Alınan Şirket</label><input name="sirket_alis" class="form-control" value="<?= $tv('sirket') ?>" maxlength="120" disabled>
        <div class="form-text">Üstteki <strong>Şirket</strong> alanından gelir.</div></div>
      <?= $tanimSec('transfer_birim', 'transfer_birim', 'Transfer Geldiği Birim', 'Cihaz başka birimden geldiyse kaynağı.') ?>
      <div class="col-md-2"><label class="form-label">Transfer Tarihi</label><input type="date" name="transfer_tarihi" class="form-control" value="<?= $tv('transfer_tarihi') ?>"></div>

      <div class="col-12 teknik"><div class="bolum-basi"><i class="bi bi-cpu me-1"></i>TEKNİK</div></div>
      <div class="col-md-3 teknik"><label class="form-label">IP Adresi</label><input name="ip_adresi" class="form-control font-monospace" value="<?= $tv('ip_adresi') ?>" maxlength="45"></div>
      <div class="col-md-3 teknik"><label class="form-label">MAC Adresi</label><input name="mac_adresi" class="form-control font-monospace" value="<?= $tv('mac_adresi') ?>" maxlength="40"></div>
      <?= $tanimSec('isletim_sistemi', 'isletim_sistemi', 'İşletim Sistemi', 'Windows 11 Pro · Windows 10 Pro…', 'teknik') ?>
      <div class="col-md-3 teknik"><label class="form-label">Özellik özeti</label><input name="ozellikler" class="form-control" value="<?= $tv('ozellikler') ?>" maxlength="255" placeholder="boş bırakılırsa künyeden üretilir">
        <div class="form-text">Listelerde ve Excel'de görünen kısa satır.</div></div>

      <?php /* Kategoriye özel alanlar: her biri kendi kategorilerinde görünür (JS ile), diğerlerinde gizlenir */ ?>
      <div class="col-12 ekalan"><div class="bolum-basi"><i class="bi bi-sliders me-1"></i>CİHAZ TİPİNE ÖZEL / DONANIM KÜNYESİ
        <span class="fw-normal">— zimmet tutanağındaki "Özellikler" bloğu buradan doldurulur</span></div></div>
      <?php foreach (IT_EK_ALAN as $__ea => [$__eEt, $__eKat, $__eTip, $__eIp]):
          if ($__eTip === 'sifre' && !yetki_var('duzenle')) continue;   // şifreyi yalnız yetkili görür/yazar ?>
      <?php /* Donanım künyesi alanları LİSTEDEN seçilir — serbest metinde aynı işlemci
                "Intel(R) Core(TM) i5-4570" / "intel i5 4570" diye ikiye bölünüyordu. */
        if (isset(IT_FORM_EK_TANIM[$__ea])) {
            echo $tanimSec($__ea, IT_FORM_EK_TANIM[$__ea], $__eEt, $__eIp,
                           'ekalan ek-' . $__ea, 'data-kat="' . h(implode(',', $__eKat)) . '"');
            continue;
        } ?>
      <div class="col-md-3 ekalan ek-<?= h($__ea) ?>" data-kat="<?= h(implode(',', $__eKat)) ?>">
        <label class="form-label"><?= h($__eEt) ?></label>
        <?php if ($__eTip === 'cihaz'): ?>
          <select name="<?= h($__ea) ?>" class="form-select">
            <option value="">— seçilmedi —</option>
            <?php foreach ($bagliAdaylar as $__b): ?>
              <option value="<?= (int)$__b['id'] ?>" <?= (int)($v[$__ea] ?? 0) === (int)$__b['id'] ? 'selected' : '' ?>>
                <?= h(trim(($__b['envanter_no'] ?? '') . ' · ' . ($__b['ad'] ?? ''))) ?></option>
            <?php endforeach; ?>
          </select>
        <?php elseif ($__eTip === 'sifre'): ?>
          <input name="<?= h($__ea) ?>" class="form-control font-monospace" value="<?= $tv($__ea) ?>" maxlength="250" autocomplete="new-password">
        <?php elseif ($__eTip === 'sayi'): ?>
          <input type="number" min="0" name="<?= h($__ea) ?>" class="form-control" value="<?= $tv($__ea) ?>">
        <?php else: ?>
          <input name="<?= h($__ea) ?>" class="form-control<?= in_array($__ea, ['imei','dahili_no','telefon_no'], true) ? ' font-monospace' : '' ?>" value="<?= $tv($__ea) ?>" maxlength="250" placeholder="<?= h($__eIp) ?>">
        <?php endif; ?>
        <?php if ($__eIp && $__eTip !== 'text'): ?><div class="form-text"><?= h($__eIp) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>

      <div class="col-12 lisans"><div class="bolum-basi"><i class="bi bi-key me-1"></i>LİSANS</div></div>
      <div class="col-md-8 lisans"><label class="form-label">Lisans Anahtarı</label><input name="lisans_anahtari" class="form-control font-monospace" value="<?= $tv('lisans_anahtari') ?>" maxlength="160"></div>
      <div class="col-md-4 lisans"><label class="form-label">Lisans Adedi (kullanıcı/cihaz)</label><input type="number" min="0" name="lisans_adet" class="form-control" value="<?= $tv('lisans_adet') ?>"></div>

      <div class="col-12"><label class="form-label">Notlar</label><textarea name="notlar" rows="3" class="form-control"><?= $tv('notlar') ?></textarea></div>
      <div class="col-md-6">
        <label class="form-label"><i class="bi bi-camera me-1"></i>Fotoğraf / Fatura / Garanti belgesi <span class="text-muted small">(isteğe bağlı; detay ekranından çoklu yüklenir)</span></label>
        <input type="file" name="foto" class="form-control" accept="image/*,application/pdf">
      </div>
    </div>
  </div>
  <div class="card-footer bg-white d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $duzenleme ? 'Güncelle' : 'Kaydet' ?></button>
    <a href="<?= $duzenleme ? 'cihaz_detay.php?id=' . $id : 'cihazlar.php' ?>" class="btn btn-outline-secondary">İptal</a>
    <?php if (!$duzenleme): ?><span class="ms-auto small text-muted align-self-center">Envanter no otomatik verilir (IT-00001…)</span><?php endif; ?>
  </div>
</form>

<script>
// Kategoriye göre alan grupları: lisans alanları yalnız yazılımda, teknik alanlar lisansta gizli
(function () {
    var sel = document.getElementById('kategori');
    function uygula() {
        var yaz = sel.value === 'yazilim';
        document.querySelectorAll('.lisans').forEach(function (e) { e.classList.toggle('d-none', !yaz); });
        document.querySelectorAll('.teknik').forEach(function (e) { e.classList.toggle('d-none', yaz || sel.value === 'aksesuar'); });
        // Cihaz tipine özel alanlar: data-kat listesinde bu kategori varsa görünür
        var acik = 0;
        document.querySelectorAll('.ekalan[data-kat]').forEach(function (e) {
            var goster = e.dataset.kat.split(',').indexOf(sel.value) >= 0;
            e.classList.toggle('d-none', !goster);
            if (goster) acik++;
        });
        document.querySelectorAll('.ekalan:not([data-kat])').forEach(function (e) { e.classList.toggle('d-none', acik === 0); });
    }
    sel.addEventListener('change', uygula); uygula();
})();

/* ── Aranabilir personel seçici ───────────────────────────────────────────────
   Sayfanın kendi ucu (?personel_ara=) JSON döndürür; süzme sunucuda it_norm ile yapılır
   (Türkçe harf duyarsız, kelime sırası serbest). Ok tuşları/Enter/Esc çalışır; seçim gizli
   personel_id'ye yazılır. Kişi seçilince boş olan departman/lokasyon kartından dolar. */
(function () {
    var ara = document.getElementById('kisiAra'), liste = document.getElementById('kisiListe'),
        gizli = document.getElementById('personel_id'), secili = document.getElementById('kisiSecili'),
        durum = document.getElementById('durum'), lok = document.getElementById('lokasyon_id'),
        dep = document.getElementById('departman');
    if (!ara || !liste || !gizli) return;
    var veri = [], sec = -1, zaman = null;

    function kapat() { liste.classList.add('d-none'); sec = -1; }
    function kacir(x) { return String(x == null ? '' : x).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
    function ciz() {
        if (!veri.length) { liste.innerHTML = '<div class="list-group-item small text-muted">Eşleşen personel yok — <strong>Yeni personel</strong> ile ekleyebilirsiniz.</div>'; liste.classList.remove('d-none'); return; }
        liste.innerHTML = veri.map(function (p, i) {
            return '<button type="button" class="list-group-item list-group-item-action py-1' + (i === sec ? ' active' : '') + '" data-i="' + i + '">'
                 + '<div class="d-flex justify-content-between gap-2"><span class="fw-semibold">' + kacir(p.ad) + '</span>'
                 + (p.cihaz ? '<span class="badge bg-secondary">' + p.cihaz + ' cihaz</span>' : '') + '</div>'
                 + '<div class="small text-muted">' + [p.sicil, p.unvan, p.birim, p.lok].filter(Boolean).map(kacir).join(' · ') + '</div></button>';
        }).join('');
        liste.classList.remove('d-none');
    }
    function getir() {
        fetch('cihaz_form.php?personel_ara=' + encodeURIComponent(ara.value.trim()), { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { veri = j || []; sec = -1; ciz(); })
            .catch(function () { kapat(); });
    }
    function secKisi(p) {
        gizli.value = p.id;
        ara.value = p.ad;
        secili.innerHTML = '<span class="badge bg-success"><i class="bi bi-person-check me-1"></i>' + kacir(p.ad) + '</span> '
            + '<span class="text-muted">' + [p.sicil, p.birim, p.lok].filter(Boolean).map(kacir).join(' · ') + '</span>';
        secili.classList.remove('d-none');
        if (dep && !dep.value && p.birim) dep.value = p.birim;
        if (lok && !lok.value && p.lok_id) lok.value = p.lok_id;
        // Kişi seçildiyse cihaz kullanımdadır — durum "depoda" kalmasın (kaydetmede de aynı kural var)
        if (durum && (durum.value === 'depoda' || durum.value === '')) durum.value = 'aktif';
        kapat();
    }
    ara.addEventListener('input', function () { gizli.value = ''; secili.classList.add('d-none'); clearTimeout(zaman); zaman = setTimeout(getir, 180); });
    ara.addEventListener('focus', getir);
    ara.addEventListener('keydown', function (e) {
        if (liste.classList.contains('d-none')) return;
        if (e.key === 'ArrowDown') { sec = Math.min(sec + 1, veri.length - 1); ciz(); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { sec = Math.max(sec - 1, 0); ciz(); e.preventDefault(); }
        else if (e.key === 'Enter' && sec >= 0) { secKisi(veri[sec]); e.preventDefault(); }
        else if (e.key === 'Escape') kapat();
    });
    liste.addEventListener('click', function (e) {
        var b = e.target.closest('[data-i]'); if (b) secKisi(veri[+b.getAttribute('data-i')]);
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('#kisiKutu')) kapat(); });

    // Zimmeti kaldır: kutu boşalır, kayıt "kimsede değil" olur (eski select'teki boş seçeneğin karşılığı)
    var temizle = document.getElementById('kisiTemizle');
    if (temizle) temizle.addEventListener('click', function () {
        gizli.value = ''; ara.value = ''; secili.classList.add('d-none'); kapat(); ara.focus();
    });

    // Yeni personel → AYRI PENCERE; kaydedince pencere kendini kapatır ve kişiyi buraya bildirir
    var dugme = document.getElementById('yeniKisi');
    if (dugme) dugme.addEventListener('click', function () {
        window.open('personel_form.php?popup=1&ad=' + encodeURIComponent(ara.value.trim()),
                    'ernYeniPersonel', 'width=940,height=800,scrollbars=yes,resizable=yes');
    });
    window.addEventListener('message', function (e) {
        if (!e.data || e.data.tip !== 'it_personel' || !e.data.kisi) return;
        secKisi(e.data.kisi);
        ara.focus();
    });

    // ⚠ Kutuda ad yazılı ama listeden SEÇİLMEMİŞSE kaydetme — sessizce zimmetsiz kaydedilirdi
    var form = ara.closest('form');
    if (form) form.addEventListener('submit', function (e) {
        if (ara.value.trim() !== '' && !gizli.value) {
            e.preventDefault();
            alert('"' + ara.value.trim() + '" için listeden bir personel seçilmedi.\n\n'
                + 'Listeden seçin, "Yeni personel" ile ekleyin ya da "Zimmeti kaldır" ile kutuyu boşaltın.');
            ara.focus();
        }
    });
})();

/* ── "Listede yoksa oluştur" (marka · model · şirket · tedarikçi · donanım künyesi) ───
   Her alan için ayrı dinleyici yerine TEK delege: düğmeler `.tanim-ekle` sınıfı +
   data-tur / data-hedef taşır. Sayfanın kendi POST ucuna (islem=tanim_ekle) gider; dönen ad
   select'e eklenip seçilir — kullanıcı formu terk etmeden devam eder. Mükerrer engeli
   SUNUCUDA (it_norm ile): "LENOVO" yazılsa da mevcut "Lenovo" kaydı döner. */
(function () {
    var TOKEN = <?= json_encode(csrf_token()) ?>;
    function kacir(x) { return String(x).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }

    /* Dolu/boş imi seçim değişince canlı güncellenir (sunucudaki ilk hâl `dolu` sınıfıyla gelir) */
    document.addEventListener('change', function (e) {
        var kutu = e.target.closest ? e.target.closest('.tanim-alan') : null;
        if (kutu && e.target.tagName === 'SELECT') kutu.classList.toggle('dolu', e.target.value !== '');
    });

    document.addEventListener('click', function (e) {
        var d = e.target.closest ? e.target.closest('.tanim-ekle') : null;
        if (!d) return;
        var sel = document.getElementById(d.dataset.hedef);
        if (!sel) return;
        var not = document.getElementById(d.dataset.hedef + '_not');
        var etiket = (d.getAttribute('title') || 'değer').replace(' listesinde yoksa ekle', '');
        var ad = (window.prompt('Eklenecek ' + etiket.toLocaleLowerCase('tr') + ':', '') || '').trim();
        if (!ad) return;

        d.disabled = true;
        var g = new FormData();
        g.append('islem', 'tanim_ekle'); g.append('tur', d.dataset.tur); g.append('ad', ad);
        g.append('csrf', TOKEN);
        fetch('cihaz_form.php', { method: 'POST', body: g, headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                d.disabled = false;
                if (!j || !j.ok) { alert(j && j.hata ? j.hata : 'Eklenemedi.'); return; }
                var o = null;
                for (var i = 0; i < sel.options.length; i++)
                    if (sel.options[i].value === j.ad) { o = sel.options[i]; break; }
                if (!o) { o = new Option(j.ad, j.ad); sel.add(o); }
                sel.value = j.ad;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                if (not) not.innerHTML = j.yeni
                    ? '<span class="text-success"><i class="bi bi-check-lg me-1"></i><strong>' + kacir(j.ad) + '</strong> listeye eklendi.</span>'
                    : '<span class="text-muted">Listede zaten vardı, seçildi.</span>';
            })
            .catch(function () { d.disabled = false; alert('Eklenemedi (bağlantı hatası).'); });
    });
})();

/* ── Alış tutarı × kur = TL karşılığı (canlı) ─────────────────────────────────
   Sunucuda `it_fiyat_coz()` aynı hesabı yapar; buradaki yalnız ÖN İZLEMEdir.
   Para birimi TL ise kur 1'dir ve kur/TL kutuları gizlenir (tekrar yazdırmaya gerek yok). */
(function () {
    var fi = document.getElementById('fiyat'), pb = document.getElementById('para_birimi'),
        ku = document.getElementById('kur'), tl = document.getElementById('fiyat_tl_gos'),
        kk = document.getElementById('kurKutu'), tk = document.getElementById('tlKutu'),
        kn = document.getElementById('kurNot');
    if (!fi || !pb) return;                                  // mali gösterim kapalı

    /* "1.040,76" → 1040.76 · "6.5" → 6.5  (nokta yalnız ÜÇERLİ gruplardaysa binlik ayracıdır) */
    function say(v) {
        v = String(v == null ? '' : v).replace(/[\s₺$€£]/g, '');
        if (v === '') return null;
        if (/^\d{1,3}(\.\d{3})+(,\d+)?$/.test(v)) v = v.replace(/\./g, '');
        v = v.replace(',', '.');
        var n = parseFloat(v);
        return isFinite(n) ? n : null;
    }
    function yaz(n, d) {
        return n == null ? '' : n.toLocaleString('tr-TR', { minimumFractionDigits: d, maximumFractionDigits: d });
    }
    function guncelle() {
        var tryMi = pb.value === 'TRY';
        if (kk) kk.classList.toggle('d-none', tryMi);
        if (tk) tk.classList.toggle('d-none', tryMi);
        if (kn) kn.textContent = '1 ' + pb.value + ' = ? ₺ (alış günü)';
        var f = say(fi.value), k = tryMi ? 1 : say(ku ? ku.value : '');
        if (tl) tl.value = (f != null && k != null) ? yaz(Math.round(f * k * 100) / 100, 2) : '';
    }
    [fi, pb, ku].forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', guncelle);
        el.addEventListener('change', guncelle);
    });
    guncelle();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
