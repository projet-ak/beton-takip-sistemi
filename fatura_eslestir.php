<?php
/**
 * fatura_eslestir.php — Fatura ↔ İrsaliye Eşleştirme (Mutabakat)
 *
 * Tedarikçi e-faturasındaki irsaliye listesini çıkarır, numaraları normalize edip
 * sistemdeki irsaliyelerle eşleştirir ve mutabakat raporu üretir.
 * Onaylandığında eşleşen irsaliyeler faturaya bağlanır (irsaliyeler.fatura_id).
 *
 * Okuma yolu: PDF/görsel yükle → pdftotext (varsa) → AI belge okuma → yoksa metni yapıştır.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fatura.php';

$pageTitle = 'Fatura Eşleştirme — Beton Takip Sistemi';
try { fat_semasi_kur($pdo); } catch (Throwable $e) { flash('error', 'Şema hatası: '.$e->getMessage()); }

$sonuc = null;      // ['veri'=>..., 'eslesme'=>..., 'kaynak'=>..., 'dosya_url'=>...]
$hata  = null;

// ── 1) Çözümleme: dosya yükle veya metin yapıştır ────────────────────────────
if (($_POST['action'] ?? '') === 'coz') {
    $metin    = trim((string)($_POST['metin'] ?? ''));
    $kaynak   = 'yapistirma';
    $dosyaUrl = null;

    if (!empty($_FILES['dosya']['tmp_name']) && is_uploaded_file($_FILES['dosya']['tmp_name'])) {
        $tmp  = $_FILES['dosya']['tmp_name'];
        $ad   = (string)$_FILES['dosya']['name'];
        $mime = guess_mime($tmp, $ad);
        $izin = ['application/pdf','image/jpeg','image/png','image/webp'];
        if (!in_array($mime, $izin, true)) {
            $hata = 'Desteklenmeyen dosya türü: '.$mime.' (PDF, JPG, PNG, WEBP)';
        } elseif ((int)$_FILES['dosya']['size'] > 20*1024*1024) {
            $hata = 'Dosya çok büyük (en fazla 20 MB).';
        } else {
            // Dosyayı sakla (fatura arşivi)
            $dir = __DIR__ . '/uploads/faturalar/' . date('Y/m');
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $ext  = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: ($mime === 'application/pdf' ? 'pdf' : 'jpg');
            $hedefAd = date('Ymd_His') . '_' . substr(md5($ad . microtime(true)), 0, 8) . '.' . $ext;
            if (@move_uploaded_file($tmp, $dir . '/' . $hedefAd)) {
                $dosyaUrl = 'uploads/faturalar/' . date('Y/m') . '/' . $hedefAd;
                $tam = $dir . '/' . $hedefAd;
            } else { $tam = $tmp; }

            $k = null;
            $okunan = fat_dosyadan_metin($tam, $mime, $k);
            if ($okunan !== null && $okunan !== '') { $metin = $okunan; $kaynak = $k ?: 'dosya'; }
            elseif ($metin === '') {
                $hata = 'Dosyadan metin okunamadı (sunucuda pdftotext yok ve AI okuma başarısız). '
                      . 'Faturayı PDF görüntüleyicide açıp metni kopyalayıp aşağıdaki kutuya yapıştırın.';
            }
        }
    }

    if (!$hata) {
        if ($metin === '') {
            $hata = 'Fatura dosyası yükleyin veya fatura metnini yapıştırın.';
        } else {
            $veri    = fat_metinden_cikar($metin);
            $eslesme = fat_eslestir($pdo, $veri['irsaliyeler']);
            $sonuc   = ['veri'=>$veri, 'eslesme'=>$eslesme, 'kaynak'=>$kaynak, 'dosya_url'=>$dosyaUrl, 'metin'=>$metin];
        }
    }
}

// ── 2) Kaydet: eşleşen irsaliyeleri faturaya bağla ──────────────────────────
if (($_POST['action'] ?? '') === 'kaydet') {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['irs_id'] ?? []))));
    try {
        $r = fat_kaydet($pdo, [
            'fatura_no'    => trim((string)($_POST['fatura_no'] ?? '')),
            'tarih'        => fat_tarih_norm($_POST['tarih'] ?? '') ?: null,
            'tedarikci_id' => (int)($_POST['tedarikci_id'] ?? 0) ?: null,
            'tutar'        => $_POST['tutar'] ?? null,
            'miktar'       => $_POST['miktar'] ?? null,
            'ettn'         => trim((string)($_POST['ettn'] ?? '')) ?: null,
            'eksik_adet'   => (int)($_POST['eksik_adet'] ?? 0),
            'notlar'       => trim((string)($_POST['notlar'] ?? '')) ?: null,
        ], $ids, current_user_id(), trim((string)($_POST['dosya_url'] ?? '')) ?: null);
        flash('success', "Fatura kaydedildi: {$r['baglanan']} irsaliye faturaya bağlandı.");
    } catch (Throwable $e) {
        flash('error', 'Kayıt hatası: '.$e->getMessage());
    }
    redirect('fatura_eslestir.php');
}

// ── 3) Fatura sil (bağları çöz) ─────────────────────────────────────────────
if (is_admin() && isset($_GET['sil']) && ctype_digit((string)$_GET['sil'])) {
    $fid = (int)$_GET['sil'];
    try {
        $pdo->prepare("UPDATE irsaliyeler SET fatura_id = NULL WHERE fatura_id = ?")->execute([$fid]);
        $pdo->prepare("DELETE FROM faturalar WHERE id = ?")->execute([$fid]);
        audit_log($pdo, 'faturalar', $fid, 'DELETE');
        flash('success', 'Fatura kaydı silindi, irsaliye bağları çözüldü.');
    } catch (Throwable $e) { flash('error', 'Silme hatası: '.$e->getMessage()); }
    redirect('fatura_eslestir.php');
}

$tedarikciler = $pdo->query("SELECT id, ad, vkn FROM tedarikciler ORDER BY ad")->fetchAll();
$kayitli = $pdo->query("SELECT f.*, t.ad AS tedarikci,
                               (SELECT COUNT(*) FROM irsaliyeler i WHERE i.fatura_id = f.id) AS bagli
                        FROM faturalar f LEFT JOIN tedarikciler t ON t.id = f.tedarikci_id
                        ORDER BY f.tarih DESC, f.id DESC LIMIT 100")->fetchAll();

require_once __DIR__ . '/includes/header.php';
$fmt = fn($n,$d=2) => number_format((float)$n, $d, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Fatura Eşleştirme</h4>
        <small class="text-muted">Tedarikçi faturasındaki irsaliye listesini sistemdeki kayıtlarla karşılaştırın</small>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>
<?php if ($hata): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= h($hata) ?></div><?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-upload me-1"></i> Fatura Yükle / Metin Yapıştır</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="action" value="coz">
            <div class="col-md-6">
                <label class="form-label">e-Fatura dosyası <span class="text-muted small">(PDF, JPG, PNG — en fazla 20 MB)</span></label>
                <input type="file" name="dosya" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
                <div class="form-text">Metin katmanı olmayan (taranmış) faturalar AI ile okunur.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">…veya fatura metnini yapıştırın</label>
                <textarea name="metin" class="form-control" rows="4" placeholder="Fatura No: ANM2026000004710&#10;Fatura Tarihi: 28-07-2026&#10;İrsaliye No: ANM2026-4710 …"></textarea>
            </div>
            <div class="col-12">
                <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Çözümle ve Eşleştir</button>
                <span class="text-muted small ms-2">Numara biçimi farkı otomatik giderilir: <code>ANM2026-4710</code> ↔ <code>ANM2026000004710</code></span>
            </div>
        </form>
    </div>
</div>

<?php if ($sonuc):
    $v = $sonuc['veri']; $e = $sonuc['eslesme'];
    $toplamIrs = count($v['irsaliyeler']);
    $eslesenAdet = count($e['eslesen']); $eksikAdet = count($e['eksik']);
    $farkM3 = ($v['miktar'] !== null) ? ($e['ozet']['miktar'] - (float)$v['miktar']) : null;
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Faturadaki İrsaliye</div><div class="fs-5 fw-bold"><?= $toplamIrs ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Eşleşen</div><div class="fs-5 fw-bold text-success"><?= $eslesenAdet ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100 <?= $eksikAdet?'border border-danger':'' ?>"><div class="card-body py-2"><div class="text-muted small">Sistemde Yok</div><div class="fs-5 fw-bold <?= $eksikAdet?'text-danger':'text-success' ?>"><?= $eksikAdet ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Sistem m³ (eşleşen)</div><div class="fs-5 fw-bold"><?= $fmt($e['ozet']['miktar']) ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Fatura m³</div><div class="fs-5 fw-bold"><?= $v['miktar']!==null ? $fmt($v['miktar']) : '—' ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Fark</div>
        <div class="fs-5 fw-bold <?= ($farkM3===null)?'text-muted':(abs($farkM3)<0.01?'text-success':'text-danger') ?>"><?= $farkM3===null?'—':$fmt($farkM3) ?></div></div></div></div>
</div>

<?php if ($sonuc['kaynak'] === 'ai'): ?>
<div class="alert alert-info py-2 small"><i class="bi bi-stars me-1"></i>
    Fatura <strong>AI ile okundu</strong>. Aşağıdaki alanları faturayla karşılaştırıp gerekirse düzeltin.
</div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="action" value="kaydet">
<input type="hidden" name="dosya_url" value="<?= h((string)$sonuc['dosya_url']) ?>">
<input type="hidden" name="eksik_adet" value="<?= $eksikAdet ?>">

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text me-1"></i> Fatura Bilgileri</div>
    <div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Fatura No</label>
            <input type="text" name="fatura_no" class="form-control" value="<?= h((string)$v['fatura_no']) ?>" required></div>
        <div class="col-md-2"><label class="form-label">Tarih</label>
            <input type="date" name="tarih" class="form-control" value="<?= h((string)$v['tarih']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Tedarikçi</label>
            <select name="tedarikci_id" class="form-select">
                <option value="">— seçiniz —</option>
                <?php
                $onerId = 0;
                if ($e['eslesen']) { // eşleşen irsaliyelerin tedarikçisini öner
                    $ilk = $e['eslesen'][0];
                    foreach ($tedarikciler as $td) if ($td['ad'] === $ilk['tedarikci']) $onerId = (int)$td['id'];
                }
                foreach ($tedarikciler as $td): ?>
                    <option value="<?= (int)$td['id'] ?>" <?= $onerId===(int)$td['id']?'selected':'' ?>><?= h($td['ad']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-md-2"><label class="form-label">Ödenecek Tutar</label>
            <input type="text" name="tutar" class="form-control" value="<?= $v['tutar']!==null?$fmt($v['tutar']):'' ?>"></div>
        <div class="col-md-2"><label class="form-label">Miktar (m³)</label>
            <input type="text" name="miktar" class="form-control" value="<?= $v['miktar']!==null?$fmt($v['miktar']):'' ?>"></div>
        <div class="col-md-6"><label class="form-label">ETTN</label>
            <input type="text" name="ettn" class="form-control" value="<?= h((string)$v['ettn']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Not</label>
            <input type="text" name="notlar" class="form-control" placeholder="opsiyonel"></div>
        <?php if ($v['brut_tutar'] !== null && $v['tutar'] !== null && abs($v['brut_tutar']-$v['tutar'])>0.01): ?>
        <div class="col-12"><div class="alert alert-secondary py-2 small mb-0">
            Tevkifatlı fatura: Vergiler Dahil Toplam <strong><?= $fmt($v['brut_tutar']) ?> ₺</strong>,
            Ödenecek <strong><?= $fmt($v['tutar']) ?> ₺</strong>.
        </div></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-check2-circle text-success me-1"></i> Eşleşen İrsaliyeler (<?= $eslesenAdet ?>)</span>
        <?php if ($eslesenAdet): ?><small class="text-muted fw-normal">İşaretli olanlar faturaya bağlanır</small><?php endif; ?>
    </div>
    <div class="card-body p-0">
    <?php if (!$eslesenAdet): ?>
        <div class="p-3 text-muted">Faturadaki hiçbir irsaliye sistemde bulunamadı.</div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light"><tr>
                <th style="width:36px"><input type="checkbox" class="form-check-input" checked onclick="document.querySelectorAll('.irs-chk').forEach(c=>c.checked=this.checked)"></th>
                <th>Faturada</th><th>Sistemde</th><th>Tarih</th><th>Tedarikçi</th><th>Beton</th><th>Plaka</th>
                <th class="text-end">m³</th><th>Durum</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($e['eslesen'] as $r): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input irs-chk" name="irs_id[]" value="<?= (int)$r['id'] ?>" checked></td>
                    <td><code><?= h($r['fatura_gosterim']) ?></code></td>
                    <td><code><?= h($r['irsaliye_no']) ?></code></td>
                    <td><?= h(format_date($r['tarih'])) ?></td>
                    <td><?= h((string)$r['tedarikci']) ?></td>
                    <td><?= h((string)$r['beton_sinifi']) ?></td>
                    <td><?= h((string)$r['arac_plaka']) ?></td>
                    <td class="text-end"><?= $fmt($r['miktar']) ?></td>
                    <td><span class="badge bg-<?= $r['durum']==='reddedildi'?'danger':($r['durum']==='beklemede'?'secondary':'success') ?>"><?= h($r['durum']) ?></span></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary py-0" href="irsaliye_detay.php?id=<?= (int)$r['id'] ?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold"><tr>
                <td colspan="7" class="text-end">Toplam</td>
                <td class="text-end"><?= $fmt($e['ozet']['miktar']) ?></td><td colspan="2"></td>
            </tr></tfoot>
        </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php if ($eksikAdet): ?>
<div class="card mb-3 border-danger">
    <div class="card-header bg-white fw-semibold text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Sistemde Bulunamayan İrsaliyeler (<?= $eksikAdet ?>)</div>
    <div class="card-body">
        <p class="small text-muted">Bu numaralar faturada var ama sistemde kayıtlı değil. Genelde <strong>henüz girilmemiş</strong>
           irsaliyelerdir — Excel aktarımı/hızlı tarama ile girildikten sonra bu sayfayı tekrar çalıştırın.</p>
        <div class="d-flex flex-wrap gap-1">
            <?php foreach ($e['eksik'] as $n): ?><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= h($n) ?></span><?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="mb-4">
    <button class="btn btn-success" <?= $eslesenAdet?'':'disabled' ?>><i class="bi bi-save me-1"></i>Faturayı Kaydet ve İrsaliyeleri Bağla</button>
    <a href="fatura_eslestir.php" class="btn btn-outline-secondary">Vazgeç</a>
</div>
</form>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-archive me-1"></i> Kayıtlı Faturalar</div>
    <div class="card-body p-0">
    <?php if (!$kayitli): ?>
        <div class="p-3 text-muted">Henüz kayıtlı fatura yok.</div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light"><tr>
                <th>Fatura No</th><th>Tarih</th><th>Tedarikçi</th><th class="text-end">Tutar</th>
                <th class="text-end">m³</th><th class="text-end">Bağlı İrs.</th><th class="text-end">Eksik</th><th>Dosya</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($kayitli as $f): ?>
                <tr>
                    <td><code><?= h($f['fatura_no']) ?></code></td>
                    <td><?= h(format_date($f['tarih'])) ?></td>
                    <td><?= h((string)$f['tedarikci']) ?></td>
                    <td class="text-end"><?= $f['tutar']!==null?$fmt($f['tutar']).' ₺':'—' ?></td>
                    <td class="text-end"><?= $f['miktar_m3']!==null?$fmt($f['miktar_m3']):'—' ?></td>
                    <td class="text-end"><?= (int)$f['bagli'] ?></td>
                    <td class="text-end <?= (int)$f['eksik_adet']?'text-danger fw-bold':'' ?>"><?= (int)$f['eksik_adet'] ?></td>
                    <td><?php if ($f['dosya_url']): ?><a href="<?= h($f['dosya_url']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary py-0" href="irsaliyeler.php?fatura_id=<?= (int)$f['id'] ?>">İrsaliyeler</a>
                        <?php if (is_admin()): ?>
                        <a class="btn btn-sm btn-outline-danger py-0" href="?sil=<?= (int)$f['id'] ?>" onclick="return confirm('Fatura kaydı silinsin, irsaliye bağları çözülsün mü?')"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
