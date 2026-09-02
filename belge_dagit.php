<?php
/**
 * belge_dagit.php — Belge Oku & İrsaliyeye Dağıt
 *
 * Toplu kantar fişi / fatura / irsaliye görselini yükle → AI OKUSUN →
 * belgedeki numaradan (yoksa plaka+tarihten) İLGİLİ İRSALİYE bulunsun → belge oraya eklensin.
 *
 * Eşleşmeyen belgeler kaybolmaz: geçici klasörde bekler, elle irsaliye seçilerek bağlanır.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/belge.php';

$pageTitle = 'Belge Oku & Dağıt — Beton Takip Sistemi';
try { blg_semasi_kur($pdo); } catch (Throwable $e) { flash('error', 'Şema hatası: '.$e->getMessage()); }

$BEKLEME = __DIR__ . '/uploads/belge_bekleyen';
$sonuclar = [];   // ekranda gösterilecek rapor satırları

/**
 * Bekleme klasörüne alınmış TEK belgeyi uçtan uca işler:
 * AI okuma → irsaliye bulma → mükerrer denetimi → irsaliye klasörüne taşıma →
 * kayıt (+ istenirse kantar değerlerini boş alanlara yazma). Rapor satırı döner.
 * Hem çoklu yükleme akışı hem kontrol raporundaki "oku ve dağıt" bunu kullanır.
 */
function bd_isle(PDO $pdo, string $gecici, string $ad, string $tur, bool $uygula): array
{
    $mime = guess_mime($gecici, $ad);
    $veri = null;
    try { $veri = blg_ai_oku($gecici, $mime, $tur); } catch (Throwable $e) { $veri = null; }
    if (!$veri) {
        blg_bekleme_notu($gecici, ['ad'=>$ad, 'tur'=>$tur, 'okunan'=>null]);
        return ['ad'=>$ad,'durum'=>'okunamadi','mesaj'=>'AI belgeyi okuyamadı','gecici'=>basename($gecici),'tur'=>$tur];
    }
    $bul = blg_irsaliye_bul($pdo, $veri);
    if (!$bul['irsaliye']) {
        blg_bekleme_notu($gecici, ['ad'=>$ad, 'tur'=>$tur, 'okunan'=>$veri]);
        return ['ad'=>$ad,'durum'=>'eslesmedi','mesaj'=>'İlgili irsaliye bulunamadı',
                'veri'=>$veri,'adaylar'=>$bul['adaylar'],'gecici'=>basename($gecici),'tur'=>$tur];
    }
    $irs = $bul['irsaliye'];
    $eski = blg_mukerrer($pdo, (int)$irs['id'], $tur, $veri, $ad, $gecici, __DIR__);
    if ($eski) {
        @unlink($gecici);
        return ['ad'=>$ad,'durum'=>'mukerrer','veri'=>$veri,'irsaliye'=>$irs,
                'mesaj'=>'Bu belge ' . $irs['irsaliye_no'] . ' irsaliyesine zaten ekli ('
                       . format_date($eski['created_at']) . ') — tekrar eklenmedi'];
    }
    $tas = blg_dosya_tasi($gecici, $ad, (int)$irs['id'], __DIR__);
    if (!$tas) return ['ad'=>$ad,'durum'=>'hata','mesaj'=>'İrsaliye klasörüne taşınamadı'];
    blg_ekle($pdo, (int)$irs['id'], $ad, $tas['yol'], $tur, $veri, current_user_id());
    $yazilan = ($uygula && $tur === 'kantar') ? blg_kantar_uygula($pdo, (int)$irs['id'], $veri, current_user_id()) : [];
    return ['ad'=>$ad,'durum'=>'eslesti','irsaliye'=>$irs,'veri'=>$veri,'yontem'=>$bul['yontem'],'yazilan'=>$yazilan];
}

// ── Yükle + oku + dağıt ──────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'dagit' && !empty($_FILES['belge']['tmp_name'])) {
    @set_time_limit(600);   // AI okuma dosya başına saniyeler sürebilir
    $tur     = isset($_POST['tur']) && isset(BLG_TURLER[$_POST['tur']]) ? $_POST['tur'] : 'kantar';
    $uygula  = !empty($_POST['kantar_uygula']);
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];

    // PHP tek istekte en fazla max_file_uploads (çoğu sunucuda 20) dosya kabul eder;
    // fazlası SESSİZCE atılır. Büyük seçimler artık tarayıcıda partilere bölünür (aşağıdaki JS),
    // yine de sınıra takılan olursa açıkça söyle.
    $maxUp = (int)ini_get('max_file_uploads');
    if ($maxUp > 0 && count($_FILES['belge']['tmp_name']) >= $maxUp && ($_POST['ajax'] ?? '') !== '1') {
        $sonuclar[] = ['ad' => '⚠ SINIR', 'durum' => 'hata',
            'mesaj' => "Sunucu tek istekte en fazla {$maxUp} dosya kabul ediyor — seçimin {$maxUp} üzerindeki kısmı İŞLENMEDİ. "
                     . "Sayfayı yenileyip (Ctrl+F5) tekrar deneyin: büyük seçimler artık otomatik partilenerek gönderilir."];
    }

    foreach ($_FILES['belge']['tmp_name'] as $i => $tmp) {
        $hata = (int)$_FILES['belge']['error'][$i];
        $ad   = (string)$_FILES['belge']['name'][$i];
        if ($hata === UPLOAD_ERR_NO_FILE) continue;
        if ($hata !== UPLOAD_ERR_OK)  { $sonuclar[] = ['ad'=>$ad,'durum'=>'hata','mesaj'=>"Yükleme hatası (kod $hata)"]; continue; }

        $mime = guess_mime($tmp, $ad);
        if (!in_array($mime, $allowed, true)) { $sonuclar[] = ['ad'=>$ad,'durum'=>'hata','mesaj'=>"Desteklenmeyen tür: $mime"]; continue; }
        if ((int)$_FILES['belge']['size'][$i] > 10*1024*1024) { $sonuclar[] = ['ad'=>$ad,'durum'=>'hata','mesaj'=>'10 MB sınırı aşıldı']; continue; }

        // Önce bekleme klasörüne al — okuma/eşleşme başarısız olsa da dosya kaybolmasın
        if (!is_dir($BEKLEME)) @mkdir($BEKLEME, 0755, true);
        $gecici = $BEKLEME . '/' . uniqid('bkl_', true) . '.' . (strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'bin');
        if (!@move_uploaded_file($tmp, $gecici)) { $sonuclar[] = ['ad'=>$ad,'durum'=>'hata','mesaj'=>'Diske yazılamadı']; continue; }

        $sonuclar[] = bd_isle($pdo, $gecici, $ad, $tur, $uygula);
    }
    if (!$sonuclar) flash('error', 'Dosya seçilmedi.');

    // Parti (AJAX) modu: sonuçları oturumda biriktir, özeti JSON dön —
    // son partiden sonra sayfa ?rapor=1 ile açılır ve TÜM sonuçlar tek raporda gösterilir.
    if (($_POST['ajax'] ?? '') === '1') {
        $_SESSION['blg_sonuclar'] = array_merge($_SESSION['blg_sonuclar'] ?? [], $sonuclar);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'islenen'  => count($sonuclar),
            'eslesti'  => count(array_filter($sonuclar, fn($r) => $r['durum'] === 'eslesti')),
            'mukerrer' => count(array_filter($sonuclar, fn($r) => $r['durum'] === 'mukerrer')),
            'bekleyen' => count(array_filter($sonuclar, fn($r) => in_array($r['durum'], ['eslesmedi','okunamadi'], true))),
            'hata'     => count(array_filter($sonuclar, fn($r) => $r['durum'] === 'hata')),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Parti yüklemesi bitince: biriken tüm sonuçları tek raporda göster
if (isset($_GET['rapor']) && !empty($_SESSION['blg_sonuclar'])) {
    $sonuclar = $_SESSION['blg_sonuclar'];
    unset($_SESSION['blg_sonuclar']);
}

// ── Bekleyen bir belgeyi elle irsaliyeye bağla ───────────────────────────────
// ── TOPLU BELGE KONTROLÜ: "bu 100 belgeden hangisi zaten sistemde?" ─────────
// AI KULLANMAZ, bedavadır: yüklenen dosya kayıtlı belgelerle ÖNCE boyut, sonra
// md5 ile karşılaştırılır (aynı baytlar aynı belgedir). Eşleşmeyenler bekleme
// klasöründe tutulur; rapordaki tek tuşla AI'ya yalnız ONLAR gönderilir.
if (($_POST['action'] ?? '') === 'belge_kontrol' && !empty($_FILES['belge']['tmp_name'])) {
    @set_time_limit(300);
    if (!is_dir($BEKLEME)) @mkdir($BEKLEME, 0755, true);
    $tur = isset($_POST['tur']) && isset(BLG_TURLER[$_POST['tur']]) ? $_POST['tur'] : 'kantar';

    // Kayıtlı belgeler: boyut → [kayıt] haritası (md5 yalnız boyutu tutanlarda hesaplanır)
    $boyutIdx = [];
    foreach ($pdo->query("SELECT f.dosya_adi, f.dosya_yolu, f.tur, f.created_at, i.id irs_id, i.irsaliye_no
                          FROM irsaliye_fotolar f JOIN irsaliyeler i ON i.id = f.irsaliye_id") as $fr) {
        $tam = __DIR__ . '/' . $fr['dosya_yolu'];
        if (!is_file($tam)) continue;
        $boyutIdx[(int)filesize($tam)][] = $fr + ['tam' => $tam];
    }
    $kSonuc = [];
    foreach ($_FILES['belge']['tmp_name'] as $i => $tmp) {
        $ad = (string)$_FILES['belge']['name'][$i];
        if ((int)$_FILES['belge']['error'][$i] !== UPLOAD_ERR_OK) { $kSonuc[] = ['ad'=>$ad,'durum'=>'hata','mesaj'=>'yükleme hatası']; continue; }
        $boy  = (int)$_FILES['belge']['size'][$i];
        $bulundu = null; $hash = null;
        foreach ($boyutIdx[$boy] ?? [] as $aday) {
            $hash ??= md5_file($tmp);
            if (md5_file($aday['tam']) === $hash) { $bulundu = $aday; break; }
        }
        if ($bulundu) {
            $kSonuc[] = ['ad'=>$ad, 'durum'=>'eklenmis', 'irs_id'=>(int)$bulundu['irs_id'],
                         'irsaliye_no'=>$bulundu['irsaliye_no'], 'tur'=>$bulundu['tur'], 'tarih'=>$bulundu['created_at']];
            continue;
        }
        $ext = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'bin';
        $pid = uniqid('bkl_', true) . '.' . (preg_match('/^[a-z0-9]{1,6}$/', $ext) ? $ext : 'bin');
        if (@move_uploaded_file($tmp, $BEKLEME . '/' . $pid)) {
            blg_bekleme_notu($BEKLEME . '/' . $pid, ['ad'=>$ad, 'tur'=>$tur, 'okunan'=>null]);
            $kSonuc[] = ['ad'=>$ad, 'durum'=>'eklenmemis', 'gecici'=>$pid, 'tur'=>$tur];
        } else {
            $kSonuc[] = ['ad'=>$ad, 'durum'=>'hata', 'mesaj'=>'diske yazılamadı'];
        }
    }
    $_SESSION['blg_kontrol'] = array_merge($_SESSION['blg_kontrol'] ?? [], $kSonuc);
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'islenen'    => count($kSonuc),
            'eklenmis'   => count(array_filter($kSonuc, fn($r) => $r['durum'] === 'eklenmis')),
            'eklenmemis' => count(array_filter($kSonuc, fn($r) => $r['durum'] === 'eklenmemis')),
            'hata'       => count(array_filter($kSonuc, fn($r) => $r['durum'] === 'hata')),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirect('belge_dagit.php?kontrol=1');
}
$kontrolSonuc = null;
if (isset($_GET['kontrol']) && !empty($_SESSION['blg_kontrol'])) {
    $kontrolSonuc = $_SESSION['blg_kontrol'];
    unset($_SESSION['blg_kontrol']);
}

// ── Kontrolde "sistemde yok" çıkanları AI ile OKU ve DAĞIT (parti AJAX) ─────
if (($_POST['action'] ?? '') === 'belge_isle' && !empty($_POST['gecici'])) {
    @set_time_limit(600);
    $tur    = isset($_POST['tur']) && isset(BLG_TURLER[$_POST['tur']]) ? $_POST['tur'] : 'kantar';
    $uygula = !empty($_POST['kantar_uygula']);
    $iSonuc = [];
    foreach ((array)$_POST['gecici'] as $pid) {
        $pid = basename((string)$pid);
        if (!preg_match('/^bkl_[\w.]+$/', $pid)) continue;
        $tam = $BEKLEME . '/' . $pid;
        if (!is_file($tam)) { $iSonuc[] = ['ad'=>$pid, 'durum'=>'hata', 'mesaj'=>'dosya bulunamadı']; continue; }
        $not = blg_bekleme_notu_oku($tam);
        $ad  = (string)($not['ad'] ?? $pid);
        $iSonuc[] = bd_isle($pdo, $tam, $ad, (string)($not['tur'] ?? $tur), $uygula);
    }
    $_SESSION['blg_sonuclar'] = array_merge($_SESSION['blg_sonuclar'] ?? [], $iSonuc);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'islenen'  => count($iSonuc),
        'eslesti'  => count(array_filter($iSonuc, fn($r) => $r['durum'] === 'eslesti')),
        'mukerrer' => count(array_filter($iSonuc, fn($r) => $r['durum'] === 'mukerrer')),
        'bekleyen' => count(array_filter($iSonuc, fn($r) => in_array($r['durum'], ['eslesmedi','okunamadi'], true))),
        'hata'     => count(array_filter($iSonuc, fn($r) => $r['durum'] === 'hata')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_POST['action'] ?? '') === 'elle_bagla') {
    $dosya = basename((string)($_POST['gecici'] ?? ''));
    $irsId = (int)($_POST['irsaliye_id'] ?? 0);
    $tur   = isset(BLG_TURLER[$_POST['tur'] ?? '']) ? $_POST['tur'] : 'diger';
    $kaynak = $BEKLEME . '/' . $dosya;
    if ($dosya === '' || !is_file($kaynak)) {
        flash('error', 'Bekleyen dosya bulunamadı (süresi dolmuş olabilir).');
    } elseif (!$irsId) {
        flash('error', 'İrsaliye seçilmedi.');
    } else {
        $v = $pdo->prepare("SELECT irsaliye_no FROM irsaliyeler WHERE id = ?"); $v->execute([$irsId]);
        $irsNo = $v->fetchColumn();
        if ($irsNo === false) { flash('error', 'İrsaliye bulunamadı.'); }
        else {
            $not = blg_bekleme_notu_oku($kaynak);   // beklemeye alınırken saklanan ad/tür/okunan
            $ad2 = trim((string)($_POST['ad'] ?? '')) ?: ($not['ad'] ?? $dosya);
            if (isset($not['tur']) && !isset($_POST['tur'])) $tur = $not['tur'];
            $tas = blg_dosya_tasi($kaynak, $ad2, $irsId, __DIR__);
            if ($tas) { blg_ekle($pdo, $irsId, $ad2, $tas['yol'], $tur, $not['okunan'] ?? null, current_user_id());
                        @unlink($kaynak . '.json');
                        flash('success', "Belge \"{$irsNo}\" nolu irsaliyeye eklendi."); }
            else      { flash('error', 'Dosya taşınamadı.'); }
        }
    }
    redirect('belge_dagit.php');
}

// ── Bekleyen belgeyi sil (elle) ──────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'bekleyen_sil') {
    $dosya = basename((string)($_POST['gecici'] ?? ''));
    $kaynak = $BEKLEME . '/' . $dosya;
    if ($dosya !== '' && is_file($kaynak)) { @unlink($kaynak); @unlink($kaynak . '.json'); flash('success', 'Bekleyen belge silindi.'); }
    redirect('belge_dagit.php');
}

// ── Bekleyen belgeyi AI ile yeniden oku ve eşleştirmeyi dene ────────────────
if (($_POST['action'] ?? '') === 'yeniden_oku') {
    $dosya = basename((string)($_POST['gecici'] ?? ''));
    $kaynak = $BEKLEME . '/' . $dosya;
    if ($dosya === '' || !is_file($kaynak)) { flash('error', 'Dosya bulunamadı.'); redirect('belge_dagit.php'); }
    $not  = blg_bekleme_notu_oku($kaynak);
    $tur  = $not['tur'] ?? 'kantar';
    $mime = guess_mime($kaynak, $not['ad'] ?? $dosya);
    $veri = null;
    try { $veri = blg_ai_oku($kaynak, $mime, $tur); } catch (Throwable $e) { $veri = null; }
    if (!$veri) { flash('error', 'AI belgeyi yine okuyamadı.'); redirect('belge_dagit.php'); }
    blg_bekleme_notu($kaynak, ['ad'=>$not['ad'] ?? $dosya, 'tur'=>$tur, 'okunan'=>$veri]);
    $bul = blg_irsaliye_bul($pdo, $veri);
    if ($bul['irsaliye']) {
        $irs = $bul['irsaliye'];
        $ad2 = $not['ad'] ?? $dosya;
        $tas = blg_dosya_tasi($kaynak, $ad2, (int)$irs['id'], __DIR__);
        if ($tas) {
            blg_ekle($pdo, (int)$irs['id'], $ad2, $tas['yol'], $tur, $veri, current_user_id());
            @unlink($kaynak . '.json');
            flash('success', 'Belge okundu ve "' . $irs['irsaliye_no'] . '" nolu irsaliyeye bağlandı.');
        } else flash('error', 'Dosya taşınamadı.');
    } else {
        flash('info', 'Belge okundu ama eşleşen irsaliye bulunamadı — okunan bilgiler aşağıda, elle bağlayabilirsiniz.');
    }
    redirect('belge_dagit.php');
}


// ── Bekleyen belgeler (kalıcı liste — sayfa her açılışta gösterilir) ────────
$bekleyenler = is_dir($BEKLEME) ? blg_bekleyenler($BEKLEME) : [];
foreach ($bekleyenler as &$bk) {
    $bk['adaylar'] = [];
    if (is_array($bk['okunan'])) {
        try { $b = blg_irsaliye_bul($pdo, $bk['okunan']); $bk['adaylar'] = $b['adaylar']; }
        catch (Throwable $e) {}
    }
}
unset($bk);

require_once __DIR__ . '/includes/header.php';
$fmt = fn($n,$d=2) => number_format((float)$n, $d, ',', '.');
$YONTEM = ['irsaliye_no'=>'irsaliye no ile', 'plaka_tarih'=>'plaka + tarih ile', 'plaka'=>'plaka ile'];
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-magic text-primary me-2"></i>Belge Oku & Dağıt</h4>
        <small class="text-muted">Kantar fişlerini / faturaları toplu yükleyin — sistem okuyup ilgili irsaliyeye ekler</small>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3" id="dagitForm">
            <input type="hidden" name="action" value="dagit">
            <div class="col-md-3">
                <label class="form-label">Belge Türü</label>
                <select name="tur" class="form-select">
                    <option value="kantar">Kantar Fişi</option>
                    <option value="irsaliye">İrsaliye</option>
                    <option value="fatura">Fatura</option>
                    <option value="diger">Diğer</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Belgeler <span class="text-muted small">(çoklu seçim — JPG, PNG, PDF, maks 10 MB/dosya; 100+ dosya seçebilirsiniz, otomatik partilenir)</span></label>
                <input type="file" name="belge[]" class="form-control" multiple accept="image/*,application/pdf" required>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="kantar_uygula" value="1" id="ku" checked>
                    <label class="form-check-label small" for="ku">Kantar değerlerini boş alanlara yaz</label>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" id="dagitBtn"><i class="bi bi-cpu me-1"></i>Oku ve Dağıt</button>
                <span class="text-muted small ms-2">Eşleşme sırası: belgedeki <strong>irsaliye no</strong> → <strong>plaka + tarih</strong> → plaka</span>
            </div>
            <div class="col-12 d-none" id="partiKutu">
                <div class="progress" style="height:22px"><div class="progress-bar progress-bar-striped progress-bar-animated" id="partiBar" style="width:0%">0%</div></div>
                <div class="small text-muted mt-1" id="partiDurum">Hazırlanıyor…</div>
                <div class="small text-danger mt-1"><i class="bi bi-exclamation-circle me-1"></i>İşlem bitene kadar sayfayı kapatmayın.</div>
            </div>
        </form>
<script>
// 100 dosyalık seçim tek istekte gönderilirse PHP max_file_uploads (çoğunlukla 20) fazlasını
// sessizce atar; AI okuma da tek istekte zaman aşımına düşer. Bu yüzden büyük seçimler
// tarayıcıda 6'şarlı partilere bölünüp sırayla gönderilir; sonuçlar sunucuda biriktirilip
// son partiden sonra ?rapor=1 ile TEK rapor olarak gösterilir.
document.getElementById('dagitForm').addEventListener('submit', async function (e) {
    var form = this;
    var files = Array.from(form.querySelector('input[type=file]').files || []);
    if (files.length <= 12) return;                    // küçük seçim: normal gönderim yeterli
    e.preventDefault();
    var BATCH = 6;
    var btn = document.getElementById('dagitBtn'); btn.disabled = true;
    document.getElementById('partiKutu').classList.remove('d-none');
    var bar = document.getElementById('partiBar'), durum = document.getElementById('partiDurum');
    var toplam = files.length, islenen = 0, oz = {eslesti:0, mukerrer:0, bekleyen:0, hata:0};
    for (var i = 0; i < toplam; i += BATCH) {
        var fd = new FormData();
        fd.append('action', 'dagit'); fd.append('ajax', '1');
        fd.append('tur', form.querySelector('[name=tur]').value);
        if (form.querySelector('[name=kantar_uygula]') && form.querySelector('[name=kantar_uygula]').checked) fd.append('kantar_uygula', '1');
        var csrf = form.querySelector('input[name=csrf]'); if (csrf) fd.append('csrf', csrf.value);
        files.slice(i, i + BATCH).forEach(function (f) { fd.append('belge[]', f); });
        try {
            var r = await fetch('belge_dagit.php', { method: 'POST', body: fd });
            var j = await r.json();
            islenen += j.islenen || 0;
            oz.eslesti += j.eslesti || 0; oz.mukerrer += j.mukerrer || 0; oz.bekleyen += j.bekleyen || 0; oz.hata += j.hata || 0;
        } catch (err) {
            islenen += Math.min(BATCH, toplam - i); oz.hata += Math.min(BATCH, toplam - i);
        }
        var yuzde = Math.round(100 * Math.min(i + BATCH, toplam) / toplam);
        bar.style.width = yuzde + '%'; bar.textContent = yuzde + '%';
        durum.textContent = islenen + '/' + toplam + ' belge işlendi — eşleşen ' + oz.eslesti
                          + ', mükerrer ' + oz.mukerrer + ', bekleyen ' + oz.bekleyen + (oz.hata ? ', hata ' + oz.hata : '');
    }
    durum.textContent = 'Tamamlandı — rapor açılıyor…';
    location.href = 'belge_dagit.php?rapor=1';
});
</script>

<!-- TOPLU BELGE KONTROLÜ: AI kullanmaz — dosya içeriği (md5) ile "zaten ekli mi?" bakar -->
<div class="card mb-4 border-info">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check me-1 text-info"></i> Toplu Belge Kontrolü
        <span class="text-muted small fw-normal">— elinizdeki belgelerden hangisi sisteme eklenmiş, hangisi eksik? (AI harcamaz, saniyeler sürer)</span></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end" id="kontrolForm">
            <input type="hidden" name="action" value="belge_kontrol">
            <div class="col-md-3">
                <label class="form-label small">Belge Türü <span class="text-muted">(eksikler bu türle işlenir)</span></label>
                <select name="tur" class="form-select form-select-sm">
                    <option value="kantar">Kantar Fişi</option>
                    <option value="irsaliye">İrsaliye</option>
                    <option value="fatura">Fatura</option>
                    <option value="diger">Diğer</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label small">Belgeler <span class="text-muted">(çoklu seçim — 100+ dosya olabilir)</span></label>
                <input type="file" name="belge[]" class="form-control form-control-sm" multiple accept="image/*,application/pdf" required>
            </div>
            <div class="col-md-3">
                <button class="btn btn-info btn-sm text-white" id="kontrolBtn"><i class="bi bi-search me-1"></i>Kontrol Et</button>
            </div>
            <div class="col-12 d-none" id="kntKutu">
                <div class="progress" style="height:20px"><div class="progress-bar bg-info progress-bar-striped progress-bar-animated" id="kntBar" style="width:0%">0%</div></div>
                <div class="small text-muted mt-1" id="kntDurum"></div>
            </div>
        </form>
        <div class="form-text">Karşılaştırma <strong>dosya içeriğine</strong> (md5) bakar: aynı baytlar aynı belgedir — dosya adı değişse de yakalar.
            Sistemde bulunmayanlar bekletilir ve rapordaki tek tuşla AI'ya <strong>yalnız onlar</strong> gönderilir.</div>
    </div>
</div>

<?php if ($kontrolSonuc !== null):
    $kVar  = array_filter($kontrolSonuc, fn($r) => $r['durum'] === 'eklenmis');
    $kYok  = array_values(array_filter($kontrolSonuc, fn($r) => $r['durum'] === 'eklenmemis'));
    $kHata = array_filter($kontrolSonuc, fn($r) => $r['durum'] === 'hata'); ?>
<div class="card mb-4 border-info">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-data me-1 text-info"></i> Belge Kontrol Raporu
        <span class="badge bg-primary ms-1"><?= count($kontrolSonuc) ?> dosya</span>
        <span class="badge bg-success"><?= count($kVar) ?> zaten ekli</span>
        <span class="badge bg-danger"><?= count($kYok) ?> sistemde yok</span>
        <?php if ($kHata): ?><span class="badge bg-secondary"><?= count($kHata) ?> hata</span><?php endif; ?></div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:55vh">
        <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
            <thead class="table-light" style="position:sticky;top:0"><tr>
                <th>Dosya</th><th>Durum</th><th>Bağlı İrsaliye</th><th>Tür</th><th>Eklenme</th>
            </tr></thead>
            <tbody>
            <?php foreach ($kontrolSonuc as $r): ?>
                <tr class="<?= $r['durum'] === 'eklenmemis' ? 'table-danger' : ($r['durum'] === 'hata' ? 'table-secondary' : '') ?>">
                    <td class="small"><?= h($r['ad']) ?></td>
                    <td>
                        <?php if ($r['durum'] === 'eklenmis'): ?><span class="badge bg-success">✓ zaten ekli</span>
                        <?php elseif ($r['durum'] === 'eklenmemis'): ?><span class="badge bg-danger">✗ sistemde yok</span>
                        <?php else: ?><span class="badge bg-secondary"><?= h((string)($r['mesaj'] ?? 'hata')) ?></span><?php endif; ?>
                    </td>
                    <td class="small"><?php if ($r['durum'] === 'eklenmis'): ?>
                        <a href="irsaliye_detay.php?id=<?= (int)$r['irs_id'] ?>" target="_blank"><code><?= h((string)$r['irsaliye_no']) ?></code></a>
                    <?php endif; ?></td>
                    <td class="small text-muted"><?= h(blg_tur_ad($r['tur'] ?? null)) ?></td>
                    <td class="small text-muted"><?= !empty($r['tarih']) ? h(format_date($r['tarih'])) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    <?php if ($kYok): ?>
    <div class="card-footer bg-white small">
        <button type="button" class="btn btn-success btn-sm" id="isleBtn"
                data-gecici="<?= h(json_encode(array_column($kYok, 'gecici'))) ?>"
                data-tur="<?= h((string)($kYok[0]['tur'] ?? 'kantar')) ?>">
            <i class="bi bi-cpu me-1"></i>Sistemde olmayan <?= count($kYok) ?> belgeyi OKU ve DAĞIT
        </button>
        <span class="text-muted ms-2">AI yalnız bu belgeleri okur, irsaliyesini bulup bağlar. Eşleşmeyenler "Bekleyen Belgeler"de kalır.</span>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="isleKantar" checked>
            <label class="form-check-label" for="isleKantar">Kantar değerlerini boş alanlara yaz</label>
        </div>
        <div class="d-none mt-2" id="isleKutu">
            <div class="progress" style="height:20px"><div class="progress-bar bg-success progress-bar-striped progress-bar-animated" id="isleBar" style="width:0%">0%</div></div>
            <div class="small text-muted mt-1" id="isleDurum"></div>
            <div class="small text-danger mt-1"><i class="bi bi-exclamation-circle me-1"></i>AI okuma belge başına birkaç saniye — sayfayı kapatmayın.</div>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>
// Kontrol: 15'erli parti (md5 karşılaştırması hızlı) — PHP 20 dosya sınırına takılmaz
document.getElementById('kontrolForm').addEventListener('submit', async function (e) {
    var form = this, files = Array.from(form.querySelector('input[type=file]').files || []);
    if (files.length <= 15) return;
    e.preventDefault();
    var BATCH = 15, btn = document.getElementById('kontrolBtn');
    btn.disabled = true;
    document.getElementById('kntKutu').classList.remove('d-none');
    var bar = document.getElementById('kntBar'), durum = document.getElementById('kntDurum');
    var oz = {eklenmis:0, eklenmemis:0, hata:0}, csrf = form.querySelector('input[name=csrf]');
    for (var i = 0; i < files.length; i += BATCH) {
        var fd = new FormData();
        fd.append('action', 'belge_kontrol'); fd.append('ajax', '1');
        fd.append('tur', form.querySelector('[name=tur]').value);
        if (csrf) fd.append('csrf', csrf.value);
        files.slice(i, i + BATCH).forEach(function (f) { fd.append('belge[]', f); });
        try {
            var j = await (await fetch('belge_dagit.php', { method: 'POST', body: fd })).json();
            oz.eklenmis += j.eklenmis || 0; oz.eklenmemis += j.eklenmemis || 0; oz.hata += j.hata || 0;
        } catch (err) { oz.hata += Math.min(BATCH, files.length - i); }
        var y = Math.round(100 * Math.min(i + BATCH, files.length) / files.length);
        bar.style.width = y + '%'; bar.textContent = y + '%';
        durum.textContent = Math.min(i + BATCH, files.length) + '/' + files.length + ' kontrol edildi — zaten ekli '
                          + oz.eklenmis + ', sistemde yok ' + oz.eklenmemis + (oz.hata ? ', hata ' + oz.hata : '');
    }
    location.href = 'belge_dagit.php?kontrol=1';
});

// Eksikleri işle: 3'erli parti (AI ağır)
(function () {
    var btn = document.getElementById('isleBtn');
    if (!btn) return;
    btn.addEventListener('click', async function () {
        var liste = JSON.parse(btn.dataset.gecici || '[]');
        if (!liste.length) return;
        if (!confirm(liste.length + ' belge AI ile okunup ilgili irsaliyelere bağlanacak. Devam edilsin mi?')) return;
        btn.disabled = true;
        document.getElementById('isleKutu').classList.remove('d-none');
        var bar = document.getElementById('isleBar'), durum = document.getElementById('isleDurum');
        var BATCH = 3, oz = {eslesti:0, mukerrer:0, bekleyen:0, hata:0};
        var csrf = document.querySelector('input[name=csrf]');
        var kantar = document.getElementById('isleKantar').checked;
        for (var i = 0; i < liste.length; i += BATCH) {
            var fd = new FormData();
            fd.append('action', 'belge_isle');
            fd.append('tur', btn.dataset.tur || 'kantar');
            if (kantar) fd.append('kantar_uygula', '1');
            if (csrf) fd.append('csrf', csrf.value);
            liste.slice(i, i + BATCH).forEach(function (g) { fd.append('gecici[]', g); });
            try {
                var j = await (await fetch('belge_dagit.php', { method: 'POST', body: fd })).json();
                oz.eslesti += j.eslesti || 0; oz.mukerrer += j.mukerrer || 0; oz.bekleyen += j.bekleyen || 0; oz.hata += j.hata || 0;
            } catch (err) { oz.hata += Math.min(BATCH, liste.length - i); }
            var y = Math.round(100 * Math.min(i + BATCH, liste.length) / liste.length);
            bar.style.width = y + '%'; bar.textContent = y + '%';
            durum.textContent = Math.min(i + BATCH, liste.length) + '/' + liste.length + ' belge işlendi — bağlanan '
                              + oz.eslesti + ', bekleyen ' + oz.bekleyen + (oz.mukerrer ? ', mükerrer ' + oz.mukerrer : '') + (oz.hata ? ', hata ' + oz.hata : '');
        }
        durum.textContent = 'Tamamlandı — rapor açılıyor…';
        location.href = 'belge_dagit.php?rapor=1';
    });
})();
</script>
<?php endif; ?>
    </div>
</div>

<?php if ($sonuclar):
    $ok  = count(array_filter($sonuclar, fn($r) => $r['durum'] === 'eslesti'));
    $muk = count(array_filter($sonuclar, fn($r) => $r['durum'] === 'mukerrer'));
    $kalan = count($sonuclar) - $ok - $muk; ?>
<div class="alert alert-<?= $kalan ? 'warning' : 'success' ?>">
    <strong><?= count($sonuclar) ?></strong> belge işlendi — <strong class="text-success"><?= $ok ?></strong> irsaliyeye eklendi<?php
    if ($muk): ?>, <strong class="text-secondary"><?= $muk ?></strong> zaten ekliydi (mükerrer eklenmedi)<?php endif; ?>,
    <strong class="<?= $kalan?'text-danger':'' ?>"><?= $kalan ?></strong> beklemede.
</div>

<div class="card mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check me-1"></i> Sonuç</div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Dosya</th><th>Okunan</th><th>Sonuç</th></tr></thead>
        <tbody>
        <?php foreach ($sonuclar as $r): ?>
        <tr class="<?= $r['durum']==='eslesti' ? '' : ($r['durum']==='mukerrer' ? 'table-secondary' : 'table-warning') ?>">
            <td class="small"><?= h($r['ad']) ?></td>
            <td class="small">
                <?php $v = $r['veri'] ?? null; if ($v): $p = [];
                    if (!empty($v['fis_no']))      $p[] = 'Fiş '.h($v['fis_no']);
                    if (!empty($v['irsaliye_no'])) $p[] = 'İrs '.h($v['irsaliye_no']);
                    if (!empty($v['plaka']))       $p[] = h($v['plaka']);
                    if (!empty($v['tarih']))       $p[] = h(format_date($v['tarih']));
                    if (isset($v['net_kg']) && $v['net_kg'] !== null) $p[] = $fmt($v['net_kg']).' kg';
                    echo $p ? implode(' · ', $p) : '<span class="text-muted">—</span>';
                else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="small">
                <?php if ($r['durum'] === 'eslesti'): $i = $r['irsaliye']; ?>
                    <i class="bi bi-check-circle-fill text-success me-1"></i>
                    <a href="irsaliye_detay.php?id=<?= (int)$i['id'] ?>" target="_blank"><strong><?= h($i['irsaliye_no']) ?></strong></a>
                    <span class="text-muted">(<?= h($YONTEM[$r['yontem']] ?? $r['yontem']) ?>)</span>
                    <?php if (!empty($r['yazilan'])): ?><div class="text-success">↳ <?= h(implode(' · ', $r['yazilan'])) ?></div><?php endif; ?>
                <?php elseif ($r['durum'] === 'mukerrer'): ?>
                    <i class="bi bi-files text-secondary me-1"></i><?= h($r['mesaj']) ?>
                    <?php if (!empty($r['irsaliye'])): ?>
                    <a href="irsaliye_detay.php?id=<?= (int)$r['irsaliye']['id'] ?>" target="_blank">İrsaliyeyi aç</a>
                    <?php endif; ?>
                <?php else: ?>
                    <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i><?= h($r['mesaj']) ?>
                    <?php if (!empty($r['gecici'])): ?>
                    <form method="post" class="d-flex gap-1 mt-1 flex-wrap align-items-center">
                        <input type="hidden" name="action" value="elle_bagla">
                        <input type="hidden" name="gecici" value="<?= h($r['gecici']) ?>">
                        <input type="hidden" name="ad" value="<?= h($r['ad']) ?>">
                        <input type="hidden" name="tur" value="<?= h($r['tur'] ?? 'diger') ?>">
                        <?php if (!empty($r['adaylar'])): ?>
                        <select name="irsaliye_id" class="form-select form-select-sm" style="max-width:340px">
                            <option value="">— aday irsaliyelerden seçin —</option>
                            <?php foreach ($r['adaylar'] as $a): ?>
                            <option value="<?= (int)$a['id'] ?>"><?= h($a['irsaliye_no']) ?> · <?= h(format_date($a['tarih'])) ?> · <?= h((string)$a['arac_plaka']) ?> · <?= $fmt($a['miktar']) ?> m³</option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="number" name="irsaliye_id" class="form-control form-control-sm" style="max-width:150px" placeholder="İrsaliye ID">
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-primary py-0">Bağla</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
<?php endif; ?>

<?php if ($bekleyenler): ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-hourglass-split text-warning me-1"></i> Bekleyen Belgeler (<?= count($bekleyenler) ?>)
        <span class="text-muted small fw-normal ms-1">— irsaliyesi bulunamayan belgeler; elle bağlayın ya da yeniden okutun</span>
    </div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Belge</th><th>Okunan</th><th style="min-width:340px">İrsaliyeye Bağla</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($bekleyenler as $bk): $v = $bk['okunan']; ?>
        <tr class="table-warning">
            <td class="small">
                <a href="uploads/belge_bekleyen/<?= h($bk['dosya']) ?>" target="_blank"><i class="bi <?= h(blg_tur_ikon($bk['tur'])) ?> me-1"></i><?= h($bk['ad']) ?></a>
                <div class="text-muted"><?= h(blg_tur_ad($bk['tur'])) ?> · <?= h($bk['zaman']) ?></div>
            </td>
            <td class="small">
                <?php if (is_array($v)): $p = [];
                    if (!empty($v['fis_no']))      $p[] = 'Fiş '.h($v['fis_no']);
                    if (!empty($v['irsaliye_no'])) $p[] = 'İrs '.h($v['irsaliye_no']);
                    if (!empty($v['plaka']))       $p[] = h($v['plaka']);
                    if (!empty($v['tarih']))       $p[] = h(format_date($v['tarih']));
                    if (isset($v['net_kg']) && $v['net_kg'] !== null) $p[] = $fmt($v['net_kg']).' kg';
                    echo $p ? implode(' · ', $p) : '<span class="text-muted">okunan alan yok</span>';
                else: ?><span class="text-muted">henüz okunamadı</span><?php endif; ?>
            </td>
            <td>
                <form method="post" class="d-flex gap-1 flex-wrap align-items-center">
                    <input type="hidden" name="action" value="elle_bagla">
                    <input type="hidden" name="gecici" value="<?= h($bk['dosya']) ?>">
                    <?php if (!empty($bk['adaylar'])): ?>
                    <select name="irsaliye_id" class="form-select form-select-sm" style="max-width:280px">
                        <option value="">— aday irsaliyeler —</option>
                        <?php foreach ($bk['adaylar'] as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= h($a['irsaliye_no']) ?> · <?= h(format_date($a['tarih'])) ?> · <?= h((string)$a['arac_plaka']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="number" name="irsaliye_id" class="form-control form-control-sm" style="max-width:130px" placeholder="İrsaliye ID">
                    <?php endif; ?>
                    <button class="btn btn-sm btn-primary py-0">Bağla</button>
                </form>
            </td>
            <td class="text-end text-nowrap">
                <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="yeniden_oku">
                    <input type="hidden" name="gecici" value="<?= h($bk['dosya']) ?>">
                    <button class="btn btn-sm btn-outline-secondary py-0" title="AI ile yeniden oku ve eşleştir"><i class="bi bi-arrow-clockwise"></i> Yeniden Oku</button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Bekleyen belge silinsin mi?')">
                    <input type="hidden" name="action" value="bekleyen_sil">
                    <input type="hidden" name="gecici" value="<?= h($bk['dosya']) ?>">
                    <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        Okunan değerler irsaliyenin <strong>boş</strong> kantar alanlarına yazılır; elle girilmiş değerlerin üzerine yazılmaz.
        Eşleşmeyen belgeler <strong>silinmez</strong> — yukarıdaki "Bekleyen Belgeler" listesinde ilgili irsaliyeye bağlanana
        (ya da elle silinene) kadar bekler.
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
