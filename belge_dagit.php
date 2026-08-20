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
    $tur     = isset($_POST['tur']) && isset(BLG_TURLER[$_POST['tur']]) ? $_POST['tur'] : 'kantar';
    $uygula  = !empty($_POST['kantar_uygula']);
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];

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
            $sonuclar[] = ['ad'=>$ad,'durum'=>'okunamadi','mesaj'=>'AI belgeyi okuyamadı','gecici'=>basename($gecici),'tur'=>$tur];
            continue;
        }

        $bul = blg_irsaliye_bul($pdo, $veri);
        if (!$bul['irsaliye']) {
            $sonuclar[] = ['ad'=>$ad,'durum'=>'eslesmedi','mesaj'=>'İlgili irsaliye bulunamadı',
                           'veri'=>$veri,'adaylar'=>$bul['adaylar'],'gecici'=>basename($gecici),'tur'=>$tur];
            continue;
        }

        $irs = $bul['irsaliye'];
        $tas = blg_dosya_tasi($gecici, $ad, (int)$irs['id'], __DIR__);
        if (!$tas) { $sonuclar[] = ['ad'=>$ad,'durum'=>'hata','mesaj'=>'İrsaliye klasörüne taşınamadı']; continue; }
        blg_ekle($pdo, (int)$irs['id'], $ad, $tas['yol'], $tur, $veri, current_user_id());

        $yazilan = ($uygula && $tur === 'kantar') ? blg_kantar_uygula($pdo, (int)$irs['id'], $veri, current_user_id()) : [];
        $sonuclar[] = ['ad'=>$ad,'durum'=>'eslesti','irsaliye'=>$irs,'veri'=>$veri,
                       'yontem'=>$bul['yontem'],'yazilan'=>$yazilan];
    }
    if (!$sonuclar) flash('error', 'Dosya seçilmedi.');
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
        $v = $pdo->prepare("SELECT id FROM irsaliyeler WHERE id = ?"); $v->execute([$irsId]);
        if (!$v->fetchColumn()) { flash('error', 'İrsaliye bulunamadı.'); }
        else {
            $tas = blg_dosya_tasi($kaynak, (string)($_POST['ad'] ?? $dosya), $irsId, __DIR__);
            if ($tas) { blg_ekle($pdo, $irsId, (string)($_POST['ad'] ?? $dosya), $tas['yol'], $tur, null, current_user_id());
                        flash('success', "Belge #{$irsId} nolu irsaliyeye eklendi."); }
            else      { flash('error', 'Dosya taşınamadı.'); }
        }
    }
    redirect('belge_dagit.php');
}

// Bekleyen dosyaları 7 günden eskiyse temizle
if (is_dir($BEKLEME)) {
    foreach ((array)glob($BEKLEME . '/*') as $g) {
        if (is_file($g) && filemtime($g) < time() - 7*86400) @unlink($g);
    }
}

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
        <form method="post" enctype="multipart/form-data" class="row g-3">
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
                <label class="form-label">Belgeler <span class="text-muted small">(çoklu seçim — JPG, PNG, PDF, maks 10 MB)</span></label>
                <input type="file" name="belge[]" class="form-control" multiple accept="image/*,application/pdf" required>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="kantar_uygula" value="1" id="ku" checked>
                    <label class="form-check-label small" for="ku">Kantar değerlerini boş alanlara yaz</label>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary"><i class="bi bi-cpu me-1"></i>Oku ve Dağıt</button>
                <span class="text-muted small ms-2">Eşleşme sırası: belgedeki <strong>irsaliye no</strong> → <strong>plaka + tarih</strong> → plaka</span>
            </div>
        </form>
    </div>
</div>

<?php if ($sonuclar):
    $ok = count(array_filter($sonuclar, fn($r) => $r['durum'] === 'eslesti'));
    $kalan = count($sonuclar) - $ok; ?>
<div class="alert alert-<?= $kalan ? 'warning' : 'success' ?>">
    <strong><?= count($sonuclar) ?></strong> belge işlendi — <strong class="text-success"><?= $ok ?></strong> irsaliyeye eklendi,
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
        <tr class="<?= $r['durum']==='eslesti'?'':'table-warning' ?>">
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

<div class="card">
    <div class="card-body small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        Okunan değerler irsaliyenin <strong>boş</strong> kantar alanlarına yazılır; elle girilmiş değerlerin üzerine yazılmaz.
        Eşleşmeyen belgeler <code>uploads/belge_bekleyen/</code> altında <strong>7 gün</strong> bekler, sonra silinir.
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
