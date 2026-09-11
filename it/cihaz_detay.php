<?php
/**
 * it/cihaz_detay.php — Cihaz kartı: tüm alanlar + yaşam günlüğü (hareketler) + belgeler/fotoğraflar
 * İşlemler: zimmet ver / zimmet iade / servise gönder / servisten döndü / arıza / transfer /
 *            kayıp / hibe / hurdaya ayır / not.
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

/* ── Personel arama ucu (zimmet kutusundaki yazarak seçme) ────────────────────
   Süzme it_personel_ara() ile PHP'de yapılır (Türkçe harf duyarsız). Kişi başına cihaz
   sayısı TEK sorguyla toplanır — satır satır sayılsa 25 kişi için 25 sorgu olurdu. */
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
                $__tutanak = !empty($_POST['tutanak_ac']);
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
                $__hz = it_hareket_ekle($pdoIt, $id, 'zimmet', $kisi, $acik ?: ('Zimmet verildi' . ($dep ? ' (' . $dep . ')' : '')), $tarih);
                flash('success', $kisi . ' adına zimmetlendi. Tutanağı yazdırıp imzalatın, taranmış kopyayı cihaz kartına yükleyin.');
                if ($__tutanak) redirect('zimmet_tutanak.php?hareket=' . $__hz);
                break;
            case 'iade':
                $__iadeEden = $c['zimmetli'];
                $pdoIt->prepare("UPDATE it_cihazlar SET personel_id=NULL, zimmetli=NULL, zimmet_tarihi=NULL, durum=IF(durum='aktif','depoda',durum) WHERE id=?")->execute([$id]);
                $__hi = it_hareket_ekle($pdoIt, $id, 'iade', $__iadeEden, $acik ?: 'Zimmet iade alındı, depoya girdi', $tarih);
                flash('success', 'Zimmet iade alındı; cihaz depoda. İADE TUTANAĞINI yazdırıp imzalatın — dönemin kapanış belgesidir.');
                if (!empty($_POST['tutanak_ac'])) redirect('iade_tutanak.php?hareket=' . $__hi);
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
            // TRANSFER — cihaz bir PROJEDEN İHTİYAÇ DUYAN BAŞKA PROJEYE gönderilir (Batı Yakası → Hatay gibi).
            // Yolda geçen süre takip edilebilsin diye ayrı bir durumdur: envanterden DÜŞMEZ (hâlâ bizim),
            // zimmet düşer, depodaki kullanılabilir stok sayılmaz. Günlüğe **nereden → nereye** yazılır.
            case 'transfer':
                $hedef   = (int)($_POST['hedef_lokasyon_id'] ?? 0) ?: null;
                $hedefAd = $hedef ? it_lokasyon_yol($pdoIt, $hedef) : '';
                $kaynakAd = $c['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$c['lokasyon_id']) : (string)($c['lokasyon'] ?? '');
                $isteyen = mb_substr(trim((string)($_POST['isteyen'] ?? '')), 0, 120);
                $pdoIt->prepare("UPDATE it_cihazlar SET durum='transfer', personel_id=NULL, zimmetli=NULL, zimmet_tarihi=NULL"
                                . ($hedef ? ", lokasyon_id=?, lokasyon=?" : "") . " WHERE id=?")
                      ->execute($hedef ? [$hedef, $hedefAd, $id] : [$id]);
                // ⚠ Biçim SABİT: "Sevk: <kaynak> → <hedef> · gönderen: … · isteyen/teslim alacak: … · not"
                // Transfer tutanağı bu satırı ayrıştırır (`it_transfer_son` de 'Sevk:' önekiyle bulur).
                $__met = 'Sevk: ' . ($kaynakAd ?: '—') . ' → ' . ($hedefAd ?: '—')
                       . ($kisi ? ' · gönderen: ' . $kisi : '')
                       . ($isteyen ? ' · isteyen/teslim alacak: ' . $isteyen : '')
                       . ($acik ? ' · ' . $acik : '');
                it_hareket_ekle($pdoIt, $id, 'transfer', $kisi ?: null, $__met, $tarih);
                flash('success', 'Cihaz TRANSFER (yolda): ' . ($kaynakAd ?: '—') . ' → ' . ($hedefAd ?: '—')
                                 . '. Transfer tutanağını yazdırıp imzalatabilirsiniz.');
                break;
            case 'transfer_bitti':
                $hedef2  = (int)($_POST['hedef_lokasyon_id'] ?? 0) ?: null;
                $hedefAd2 = $hedef2 ? it_lokasyon_yol($pdoIt, $hedef2) : '';
                $pdoIt->prepare("UPDATE it_cihazlar SET durum='depoda'" . ($hedef2 ? ", lokasyon_id=?, lokasyon=?" : "") . " WHERE id=?")
                      ->execute($hedef2 ? [$hedef2, $hedefAd2, $id] : [$id]);
                it_hareket_ekle($pdoIt, $id, 'transfer', $kisi ?: null,
                    'Transfer teslim alındı — ' . ($hedefAd2 ?: ($c['lokasyon'] ?: 'hedef proje')) . ' deposuna girdi'
                    . ($kisi ? ' · teslim alan: ' . $kisi : '') . ($acik ? ' · ' . $acik : ''), $tarih);
                flash('success', 'Transfer tamamlandı; cihaz hedef projenin deposunda.');
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
                flash('success', $__ad . ' — kayıt silinmez, listede gizlenir (durum filtresiyle görüntülenir). '
                                 . 'Tutanağı yazdırıp imzalattıktan sonra taranmış kopyayı cihaz kartından yükleyin.');
                break;
            default:
                it_hareket_ekle($pdoIt, $id, 'not', $kisi, $acik ?: '—', $tarih);
                flash('success', 'Not eklendi.');
        }
    } elseif ($islem === 'belge' && $girebilir) {
        // Belge türü IT_BELGE_TUR'dan; imzalı tutanaklar ilgili HAREKETE (zimmet/iade dönemine) bağlanır
        $tur = isset(IT_BELGE_TUR[$_POST['belge_tur'] ?? '']) ? $_POST['belge_tur'] : 'belge';
        $__hb = in_array($tur, ['zimmet','iade'], true) ? (int)(it_son_hareket($pdoIt, $id, $tur)['id'] ?? 0) : 0;
        $ok = 0; $hatalar = [];
        foreach (it_dosya_listesi($_FILES['belge'] ?? []) as $d) {
            if (($d['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            [$b, $m] = it_belge_yukle($pdoIt, $id, $d, $kul, $tur, $__hb ?: null);
            if ($b) $ok++; else $hatalar[] = $m;
        }
        if ($ok) {
            $__tad = $tur !== 'belge' ? mb_strtolower(it_belge_turu($tur)[0], 'UTF-8') : '';
            flash('success', $ok . ' dosya yüklendi.' . ($__tad ? ' İmzalı ' . $__tad . ' olarak işaretlendi.' : ''));
            if ($__tad)
                it_hareket_ekle($pdoIt, $id, 'not', $c['zimmetli'] ?: null, 'İmzalı ' . $__tad . ' yüklendi (' . $ok . ' dosya).');
        }
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
$dustu      = it_durum_dustu($c['durum']);            // hurda / kayıp / hibe → tutanak = hurda tutanağı
$imzaliTur  = $dustu ? 'hurda' : 'zimmet';
$imzaliSayi = count(array_filter($belgeler, fn($b) => ($b['tur'] ?? '') === $imzaliTur));
$imzaliAd   = ['hurda'=>'hurda','kayip'=>'zayi','hibe'=>'hibe'][(string)$c['durum']] ?? '';
$tutanakUrl = $dustu ? 'hurda_tutanak.php?id=' . $id : ($c['zimmetli'] ? 'zimmet_tutanak.php?id=' . $id : '');
$tutanakAd  = $dustu ? ['hurda'=>'Hurda Tutanağı','kayip'=>'Zayi Tutanağı','hibe'=>'Hibe / Devir Tutanağı'][(string)$c['durum']]
                     : 'Zimmet Tutanağı';
// Cihazın el değiştirme zinciri: her dönemin zimmet + iade formu ve evrak durumu
$zincir  = it_zimmet_donemleri($pdoIt, $id, (string)($c['zimmetli'] ?? ''));
$donemler = $zincir['donemler'];
$faturaBelge = array_values(array_filter($belgeler, fn($b) => ($b['tur'] ?? '') === 'fatura'));
$gk = it_garanti_kalan($c['garanti_bitis']);
$maliGoster = it_mali_goster();      // garanti + fiyat gösterimi (varsayılan KAPALI)

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
    <span class="badge bg-light text-dark border font-monospace" title="envanter no"><?= h($c['envanter_no']) ?></span>
    <?php if (!empty($c['cihaz_kodu'])): ?><span class="badge bg-light text-dark border font-monospace" title="cihaz kodu (demirbaş etiketi)"><?= h($c['cihaz_kodu']) ?></span><?php endif; ?>
    <?php if (!empty($c['varlik_kodu'])): ?><span class="badge bg-light text-secondary border font-monospace" title="IFS seri nesne no"><?= h($c['varlik_kodu']) ?></span><?php endif; ?>
    <?= it_durumBadge($c['durum']) ?>
    <?php if ($c['durum'] === 'transfer' && ($__trg = it_transfer_gunleri($pdoIt, [$id])[$id] ?? null) !== null): ?>
      <span class="badge bg-<?= $__trg > 14 ? 'danger' : 'light text-dark border' ?>" title="son transfer hareketinden bu yana"><?= (int)$__trg ?> gündür yolda</span>
    <?php endif; ?>
    <?php if ($maliGoster && $gk !== null && $gk < 0): ?><span class="badge bg-light text-danger border">garanti bitti</span>
    <?php elseif ($maliGoster && $gk !== null && $gk <= 60): ?><span class="badge bg-warning text-dark">garanti <?= $gk ?> gün</span><?php endif; ?>
    <div class="ms-auto d-flex gap-2">
        <?php if ($c['zimmetli']): ?><a href="zimmet_tutanak.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Zimmet Tutanağı</a><?php endif; ?>
        <?php if ($c['durum'] === 'transfer'): ?><a href="transfer_tutanak.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-info btn-sm"><i class="bi bi-arrow-left-right me-1"></i>Transfer Tutanağı</a><?php endif; ?>
        <?php if ($dustu): ?><a href="hurda_tutanak.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-x me-1"></i><?= h($tutanakAd) ?></a><?php endif; ?>
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
            <?= $maliGoster ? $bilgi('Garanti Bitiş', $c['garanti_bitis'] ? format_date($c['garanti_bitis']) . ($gk !== null ? ($gk < 0 ? ' (bitti)' : ' (' . $gk . ' gün)') : '') : null) : '' ?>
            <?= $maliGoster ? $bilgi('Fiyat', $c['fiyat'] !== null ? $f2($c['fiyat']) . ' TL' : null) : '' ?>
            <?= $bilgi('Tedarikçi', $c['tedarikci']) ?>
            <?= $bilgi('Fatura No', $c['fatura_no']) ?>
            <?php if ($c['kategori'] === 'yazilim'): ?>
            <?= $bilgi('Lisans Anahtarı', $c['lisans_anahtari'], true) ?>
            <?= $bilgi('Lisans Adedi', $c['lisans_adet']) ?>
            <?php else: ?>
            <?= $bilgi('IFS Seri Nesne No', $c['varlik_kodu'] ?? null, true) ?>
            <?= $bilgi('Cihaz Kodu', $c['cihaz_kodu'] ?? null, true) ?>
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

    <?php /* EL DEĞİŞTİRME ZİNCİRİ — cihaz kaç kez, kimden kime geçti; her dönemin zimmet ve
              İADE tutanağı var mı? Yaşam günlüğü düz liste olduğundan bu soru okunmuyordu. */ ?>
    <?php if ($donemler): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <strong><i class="bi bi-people me-1"></i>Zimmet geçmişi — el değiştirme</strong>
        <span class="badge bg-secondary"><?= count($donemler) ?> dönem</span>
        <?php $__eksik = count(array_filter($donemler, fn($d) => !$d['zimmet_belge'] || (!$d['acik'] && !$d['kapanis_belge']))); ?>
        <?php if ($__eksik): ?><span class="badge bg-warning text-dark" title="zimmet ya da iade tutanağı yüklenmemiş dönem"><?= $__eksik ?> dönemde evrak eksik</span><?php endif; ?>
      </div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle" style="font-size:.84rem">
        <thead class="table-light"><tr><th style="width:28px">#</th><th>Kullanan</th><th>Dönem</th><th>Süre</th>
          <th class="text-center">Zimmet formu</th><th class="text-center">İade formu</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($donemler) as $d): ?>
          <tr class="<?= $d['acik'] ? ($d['uyusmaz'] ? 'table-warning' : 'table-success') : '' ?>">
            <td class="text-muted"><?= (int)$d['sira'] ?></td>
            <td><span class="fw-semibold"><?= h($d['kisi'] ?: '—') ?></span>
                <?php if ($d['acik'] && !$d['uyusmaz']): ?><span class="badge bg-success ms-1">şu an</span>
                <?php elseif ($d['acik']): ?><span class="badge bg-warning text-dark ms-1" title="cihaz kartındaki güncel zimmetli farklı — geriye dönük tarihli hareket girilmiş olabilir">açık kalmış</span><?php endif; ?></td>
            <td class="small"><?= format_date($d['bas']) ?> → <?= $d['bit'] ? format_date($d['bit']) : '<em>devam ediyor</em>' ?>
                <?php if (!$d['acik'] && $d['kapanis_tur'] && $d['kapanis_tur'] !== 'iade'): ?>
                  <div class="text-muted"><?= h(IT_HAREKET[$d['kapanis_tur']][0] ?? $d['kapanis_tur']) ?> ile kapandı</div>
                <?php endif; ?></td>
            <td class="small"><?= $d['gun'] !== null ? (int)$d['gun'] . ' gün' : '—' ?></td>
            <td class="text-center">
              <?php if ($d['zimmet_belge']): ?>
                <a href="../<?= h($d['zimmet_belge'][0]['dosya_url']) ?>" target="_blank" class="badge bg-success text-decoration-none" title="imzalı zimmet tutanağı"><i class="bi bi-file-earmark-check"></i></a>
              <?php else: ?>
                <a href="zimmet_tutanak.php?hareket=<?= (int)$d['zimmet']['id'] ?>" target="_blank" class="badge bg-light text-warning border text-decoration-none" title="imzalı zimmet tutanağı yok — formu aç"><i class="bi bi-printer"></i></a>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($d['acik']): ?><span class="text-muted">—</span>
              <?php elseif ($d['kapanis_belge']): ?>
                <a href="../<?= h($d['kapanis_belge'][0]['dosya_url']) ?>" target="_blank" class="badge bg-success text-decoration-none" title="imzalı kapanış tutanağı"><i class="bi bi-file-earmark-check"></i></a>
              <?php elseif ($d['kapanis_tur'] === 'iade'): ?>
                <a href="iade_tutanak.php?hareket=<?= (int)$d['kapanis']['id'] ?>" target="_blank" class="badge bg-light text-warning border text-decoration-none" title="imzalı iade tutanağı yok — formu aç"><i class="bi bi-printer"></i></a>
              <?php else: ?><span class="text-muted small"><?= h(IT_HAREKET[$d['kapanis_tur']][0] ?? '—') ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php if ($zincir['bagsiz']): ?>
      <div class="card-footer bg-white small text-muted">
        <i class="bi bi-info-circle me-1"></i><?= count($zincir['bagsiz']) ?> imzalı tutanak bir döneme bağlanmamış
        (bu özellikten önce yüklenmiş) — Belgeler bölümünde duruyor.
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

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
    <div class="card border-0 shadow-sm mb-3" id="islem">
      <div class="card-header bg-white"><strong><i class="bi bi-lightning-charge me-1"></i>İşlem</strong></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="action" value="hareket">
          <div class="mb-2">
            <select name="tur" id="tur" class="form-select form-select-sm">
              <?php if (!it_durum_dustu($c['durum'])): ?>
              <optgroup label="Zimmet">
                <option value="zimmet"><?= $c['zimmetli'] ? '👤 Zimmeti başkasına devret' : '👤 Zimmet ver' ?></option>
                <?php if ($c['zimmetli']): ?><option value="iade">↩ Zimmet iade al (depoya)</option><?php endif; ?>
              </optgroup>
              <optgroup label="Servis / arıza">
                <?php if ($c['durum'] !== 'serviste'): ?><option value="servis">🔧 Servise gönder</option>
                <?php else: ?><option value="donus">🔧 Servisten döndü</option><?php endif; ?>
                <option value="ariza">⚠ Arıza bildir</option>
              </optgroup>
              <optgroup label="Sevk (projeler arası)">
                <?php if ($c['durum'] === 'transfer'): ?><option value="transfer_bitti">✓ Transfer YAPILDI (karşı taraf teslim aldı → depoda)</option>
                <?php else: ?><option value="transfer">➜ Transfere çıkar (başka projeye gönder)</option><?php endif; ?>
              </optgroup>
              <optgroup label="Envanterden düş">
                <option value="kayip">❓ Kayıp / çalıntı bildir</option>
                <option value="hibe">🎁 Hibe et / devret</option>
                <option value="hurda">🗑 Hurdaya ayır</option>
              </optgroup>
              <?php endif; ?>
              <optgroup label="Diğer"><option value="not">📝 Not ekle</option></optgroup>
            </select>
          </div>

          <?php /* ARANABİLİR KİŞİ SEÇİCİ — uzun personel listesinde açılır menüde kişi bulmak
                    zordu; artık yazılıp süzülüyor (ad · sicil · unvan · birim). Seçim gizli
                    personel_id alanına yazılır; JS kapalıysa alttaki klasik select devreye girer. */ ?>
          <div class="mb-2 zimmet-ek" id="kisiKutu">
            <label class="form-label small mb-1 fw-semibold">Kime zimmetlenecek?</label>
            <div class="position-relative">
              <input type="text" id="kisiAra" class="form-control form-control-sm" autocomplete="off"
                     placeholder="Ad, soyad, sicil ya da birim yazın…">
              <input type="hidden" name="personel_id" id="personel_id" value="">
              <div id="kisiListe" class="list-group position-absolute w-100 shadow d-none"
                   style="z-index:1080;max-height:260px;overflow:auto"></div>
            </div>
            <div id="kisiSecili" class="small mt-1 d-none"></div>
            <div class="form-text d-flex justify-content-between align-items-center gap-2">
              <span>Adın bir kısmını yazmanız yeter.</span>
              <button type="button" class="btn btn-outline-primary btn-sm py-0" id="yeniKisi"><i class="bi bi-person-plus me-1"></i>Yeni personel</button>
            </div>
            <noscript><select name="personel_id" class="form-select form-select-sm mt-1"><?= it_personel_options($pdoIt, 0) ?></select></noscript>
          </div>
          <div class="mb-2 transfer-ek d-none">
            <select name="hedef_lokasyon_id" class="form-select form-select-sm mb-2">
              <option value="">Gönderilecek proje / lokasyon</option><?= it_lokasyon_options($pdoIt, 0, false) ?></select>
            <input name="isteyen" class="form-control form-control-sm transfer-yeni" placeholder="İsteyen / teslim alacak kişi (hedef projede)">
            <div class="form-text">Cihaz <strong><?= h($c['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$c['lokasyon_id']) : ($c['lokasyon'] ?: '—')) ?></strong>
              projesinden çıkar, "yolda" sayılır. Karşı taraf teslim alınca aynı menüden <em>Transfer YAPILDI</em> seçilir
              (dashboard'daki "Yolda olan cihazlar" listesinden tek tıkla da açılır).</div></div>
          <div class="mb-2 kisi"><input name="kisi" class="form-control form-control-sm" placeholder="Servis firması / kişi" value=""></div>
          <div class="row g-2 mb-2 zimmet-ek">
            <div class="col-6"><input name="departman" id="departman" class="form-control form-control-sm" placeholder="Departman (boş = kişinin birimi)" value=""></div>
            <div class="col-6"><select name="lokasyon_id" id="lokasyon_id" class="form-select form-select-sm"><option value="">Lokasyon: kişininki</option><?= it_lokasyon_options($pdoIt, 0, false) ?></select></div>
          </div>
          <div class="mb-2"><input type="date" name="tarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
          <div class="mb-2"><textarea name="aciklama" rows="2" class="form-control form-control-sm" placeholder="Açıklama"></textarea></div>
          <?php /* Zimmet ve iade İMZALI TUTANAK ister; kayıttan sonra tutanağı açmak akışı kesmesin diye
                    varsayılan açık — kutu kapatılırsa yalnız kayıt yapılır. */ ?>
          <div class="form-check form-switch small mb-2 tutanak-ek">
            <input class="form-check-input" type="checkbox" name="tutanak_ac" id="tutanak_ac" value="1" checked>
            <label class="form-check-label" for="tutanak_ac">Kaydettikten sonra <strong>tutanağı aç</strong> (yazdır → imzalat → geri yükle)</label>
          </div>
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check2 me-1"></i>Kaydet</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white" id="belgeler"><strong><i class="bi bi-paperclip me-1"></i>Belgeler &amp; Fotoğraflar</strong> <span class="badge bg-secondary ms-1"><?= count($belgeler) ?></span>
        <?php if ($imzaliSayi): ?><span class="badge bg-success ms-1" title="imzalı <?= h($tutanakAd) ?>"><i class="bi bi-file-earmark-check me-1"></i><?= $imzaliSayi ?></span><?php endif; ?></div>
      <div class="card-body">
        <?php if ($girebilir): ?>
        <?php /* İMZALI EVRAK: tutanağı yazdır → ıslak imzala → buradan geri yükle (depo fiş akışıyla aynı desen) */ ?>
        <?php if ($dustu || $c['zimmetli'] || $imzaliSayi): ?>
        <div class="border rounded p-2 mb-3 <?= $imzaliSayi ? 'border-success bg-success-subtle' : 'border-warning bg-warning-subtle' ?>">
          <div class="small fw-semibold mb-1"><i class="bi bi-file-earmark-check me-1"></i>İmzalı <?= h($tutanakAd) ?>
            <?php if ($imzaliSayi): ?><span class="badge bg-success"><?= $imzaliSayi ?> yüklü</span>
            <?php else: ?><span class="badge bg-warning text-dark">yüklenmedi</span><?php endif; ?></div>
          <div class="small text-muted mb-2">Tutanağı yazdırıp imzalattıktan sonra taranmış kopyayı buradan yükleyin — listede yeşil rozetle görünür.</div>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="belge">
            <input type="hidden" name="belge_tur" value="<?= h($imzaliTur) ?>">
            <input type="file" name="belge[]" multiple accept="image/*,application/pdf" class="form-control form-control-sm mb-2" required>
            <div class="d-flex gap-2">
              <?php if ($tutanakUrl): ?><a href="<?= h($tutanakUrl) ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Tutanak</a><?php endif; ?>
              <button class="btn btn-success btn-sm flex-grow-1"><i class="bi bi-cloud-arrow-up me-1"></i>İmzalı evrakı yükle</button>
            </div>
          </form>
        </div>
        <?php endif; ?>
        <?php /* ALIŞ FATURASI — yeni gelen cihazın faturası/irsaliyesi envanterin parçasıdır:
                  garanti, demirbaş kaydı ve devir işlemlerinde aranır. */ ?>
        <div class="border rounded p-2 mb-3 <?= $faturaBelge ? 'border-success bg-success-subtle' : 'border-secondary-subtle' ?>">
          <div class="small fw-semibold mb-1"><i class="bi bi-receipt me-1"></i>Alış Faturası / İrsaliyesi
            <?php if ($faturaBelge): ?><span class="badge bg-success"><?= count($faturaBelge) ?> yüklü</span>
            <?php else: ?><span class="badge bg-light text-secondary border">yok</span><?php endif; ?></div>
          <div class="small text-muted mb-2">
            <?php if ($c['fatura_no'] || $c['tedarikci'] || $c['alis_tarihi']): ?>
              <?= h($c['fatura_no'] ?: 'fatura no yok') ?><?= $c['tedarikci'] ? ' · ' . h($c['tedarikci']) : '' ?><?= $c['alis_tarihi'] ? ' · ' . format_date($c['alis_tarihi']) : '' ?>
            <?php else: ?>Fatura no / tedarikçi / alış tarihi girilmemiş — <a href="cihaz_form.php?id=<?= $id ?>">cihaz kartından</a> ekleyin.<?php endif; ?>
          </div>
          <?php foreach ($faturaBelge as $fb): ?>
            <a href="../<?= h($fb['dosya_url']) ?>" target="_blank" class="d-block small text-truncate"><i class="bi bi-file-earmark-text me-1"></i><?= h($fb['ad']) ?></a>
          <?php endforeach; ?>
          <form method="post" enctype="multipart/form-data" class="mt-2">
            <input type="hidden" name="action" value="belge">
            <input type="hidden" name="belge_tur" value="fatura">
            <input type="file" name="belge[]" multiple accept="image/*,application/pdf" class="form-control form-control-sm mb-2" required>
            <button class="btn btn-outline-warning btn-sm w-100"><i class="bi bi-cloud-arrow-up me-1"></i>Faturayı yükle</button>
          </form>
        </div>
        <form method="post" enctype="multipart/form-data" class="mb-3">
          <input type="hidden" name="action" value="belge">
          <select name="belge_tur" class="form-select form-select-sm mb-2">
            <?php foreach (IT_BELGE_TUR as $__bt => [$__ba]): ?>
              <option value="<?= h($__bt) ?>" <?= $__bt === 'belge' ? 'selected' : '' ?>><?= h($__ba) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="file" name="belge[]" multiple accept="image/*,application/pdf" class="form-control form-control-sm mb-2">
          <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-cloud-arrow-up me-1"></i>Belge yükle (fotoğraf, garanti, teklif…)</button>
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
              <div class="small text-truncate mt-1" title="<?= h($b['ad']) ?>">
                <?php $__bt = it_belge_turu($b['tur'] ?? ''); ?>
                <?php if (($b['tur'] ?? 'belge') !== 'belge'): ?><i class="bi <?= h($__bt[2]) ?> text-<?= h($__bt[1]) ?> me-1" title="<?= h($__bt[0]) ?>"></i><?php endif; ?><?= h($b['ad']) ?></div>
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
    // ?islem=… ile gelindiyse (dashboard "Transfer yapıldı" düğmesi) işlem hazır seçili gelsin
    var ist = <?= json_encode((string)($_GET['islem'] ?? '')) ?>;
    if (ist && [].some.call(t.options, function (o) { return o.value === ist; })) {
        t.value = ist;
        var kart = document.getElementById('islem');
        if (kart) { kart.classList.add('border', 'border-primary'); kart.scrollIntoView({ block: 'center' }); }
    }
    function uygula() {
        var z = t.value === 'zimmet', tr = t.value === 'transfer', trBit = t.value === 'transfer_bitti';
        document.querySelectorAll('.zimmet-ek').forEach(function (e) { e.classList.toggle('d-none', !z); });
        // Hedef lokasyon seçimi hem transfere çıkarmada hem teslim almada gerekebilir (yanlış hedef düzeltilir)
        document.querySelectorAll('.transfer-ek').forEach(function (e) { e.classList.toggle('d-none', !(tr || trBit)); });
        document.querySelectorAll('.transfer-yeni').forEach(function (e) { e.classList.toggle('d-none', !tr); });
        document.querySelector('.kisi').classList.toggle('d-none', z);
        var k = document.querySelector('.kisi input');
        k.placeholder = { servis: 'Servis firması',
                          hibe:   'Hibe edilen kurum / kişi',
                          kayip:  'Kaybı bildiren kişi',
                          transfer: 'Gönderen / teslim eden',
                          transfer_bitti: 'Teslim alan kişi' }[t.value] || 'Kişi / firma (isteğe bağlı)';
        // Envanterden düşüren işlemlerde sebep yazılması beklenir
        var a = document.querySelector('textarea[name="aciklama"]');
        if (a) a.placeholder = { kayip: 'Nerede/ne zaman kaybolduğu, tutanak no…',
                                 hibe:  'Hibe/devir gerekçesi, protokol no…',
                                 hurda: 'Hurdaya ayırma sebebi',
                                 transfer: 'Sevk irsaliyesi / araç / teslim eden…' }[t.value] || 'Açıklama (isteğe bağlı)';
        // ⚠ personel_id GİZLİ input: required verilirse tarayıcı "odaklanamıyorum" deyip formu kilitler,
        // bu yüzden doğrulama submit anında yapılır (aşağıda).
        var tk = document.querySelector('.tutanak-ek');
        if (tk) tk.classList.toggle('d-none', !(z || t.value === 'iade'));
    }
    t.addEventListener('change', uygula); uygula();
})();

/* ── Aranabilir personel seçici ───────────────────────────────────────────────
   Sayfanın kendi ucu (?personel_ara=) JSON döndürür; süzme sunucuda it_norm ile yapılır
   (Türkçe harf duyarsız). Ok tuşları/Enter/Esc çalışır; seçim gizli personel_id'ye yazılır. */
(function () {
    var ara = document.getElementById('kisiAra'), liste = document.getElementById('kisiListe'),
        gizli = document.getElementById('personel_id'), secili = document.getElementById('kisiSecili'),
        tur = document.getElementById('tur'), lok = document.getElementById('lokasyon_id'),
        dep = document.getElementById('departman'), form = ara && ara.closest('form');
    if (!ara || !liste || !gizli) return;
    var veri = [], sec = -1, zaman = null;

    function kapat() { liste.classList.add('d-none'); sec = -1; }
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
    function kacir(x) { return String(x == null ? '' : x).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }

    function getir() {
        var q = ara.value.trim();
        fetch('cihaz_detay.php?id=<?= $id ?>&personel_ara=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'fetch' } })
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

    if (form) form.addEventListener('submit', function (e) {
        if (tur && tur.value === 'zimmet' && !gizli.value) {
            e.preventDefault();
            alert('Zimmet için listeden bir personel seçin. Kişi kayıtlı değilse "Yeni personel" ile ekleyin.');
            ara.focus();
        }
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
