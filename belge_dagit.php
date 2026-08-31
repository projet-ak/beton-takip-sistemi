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

        $veri = null;
        try { $veri = blg_ai_oku($gecici, $mime, $tur); } catch (Throwable $e) { $veri = null; }
        if (!$veri) {
            blg_bekleme_notu($gecici, ['ad'=>$ad, 'tur'=>$tur, 'okunan'=>null]);
            $sonuclar[] = ['ad'=>$ad,'durum'=>'okunamadi','mesaj'=>'AI belgeyi okuyamadı','gecici'=>basename($gecici),'tur'=>$tur];
            continue;
        }

        $bul = blg_irsaliye_bul($pdo, $veri);
        if (!$bul['irsaliye']) {
            blg_bekleme_notu($gecici, ['ad'=>$ad, 'tur'=>$tur, 'okunan'=>$veri]);
            $sonuclar[] = ['ad'=>$ad,'durum'=>'eslesmedi','mesaj'=>'İlgili irsaliye bulunamadı',
                           'veri'=>$veri,'adaylar'=>$bul['adaylar'],'gecici'=>basename($gecici),'tur'=>$tur];
            continue;
        }

        $irs = $bul['irsaliye'];

        // Mükerrer önleme: aynı fiş/belge bu irsaliyeye zaten eklenmişse tekrar ekleme
        $eski = blg_mukerrer($pdo, (int)$irs['id'], $tur, $veri, $ad, $gecici, __DIR__);
        if ($eski) {
            @unlink($gecici);
            $sonuclar[] = ['ad'=>$ad,'durum'=>'mukerrer','veri'=>$veri,'irsaliye'=>$irs,
                           'mesaj'=>'Bu belge ' . $irs['irsaliye_no'] . ' irsaliyesine zaten ekli ('
                                  . format_date($eski['created_at']) . ') — tekrar eklenmedi'];
            continue;
        }

        $tas = blg_dosya_tasi($gecici, $ad, (int)$irs['id'], __DIR__);
        if (!$tas) { $sonuclar[] = ['ad'=>$ad,'durum'=>'hata','mesaj'=>'İrsaliye klasörüne taşınamadı']; continue; }
        blg_ekle($pdo, (int)$irs['id'], $ad, $tas['yol'], $tur, $veri, current_user_id());

        $yazilan = ($uygula && $tur === 'kantar') ? blg_kantar_uygula($pdo, (int)$irs['id'], $veri, current_user_id()) : [];
        $sonuclar[] = ['ad'=>$ad,'durum'=>'eslesti','irsaliye'=>$irs,'veri'=>$veri,
                       'yontem'=>$bul['yontem'],'yazilan'=>$yazilan];
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
