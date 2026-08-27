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
require_once __DIR__ . '/includes/belge.php';

$pageTitle = 'Fatura Eşleştirme — Beton Takip Sistemi';
try { fat_semasi_kur($pdo); } catch (Throwable $e) { flash('error', 'Şema hatası: '.$e->getMessage()); }

$sonuc = null;      // ['veri'=>..., 'eslesme'=>..., 'kaynak'=>..., 'dosya_url'=>...]
$hata  = null;

// ── 1) Çözümleme: dosya yükle veya metin yapıştır ────────────────────────────
if (($_POST['action'] ?? '') === 'coz') {
    $metin    = trim((string)($_POST['metin'] ?? ''));
    $kaynak   = 'yapistirma';
    $dosyaUrl = null;
    // Karekot: tarayıcıda okunan (gizli alan) ya da elle yapıştırılan içerik
    $qr       = fat_qr_coz((string)($_POST['qr'] ?? ''));

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
            elseif ($metin === '' && !$qr) {
                $hata = 'Dosyadan metin okunamadı (sunucuda pdftotext yok ve AI okuma başarısız). '
                      . 'Faturayı PDF görüntüleyicide açıp metni kopyalayıp aşağıdaki kutuya yapıştırın.';
            }
        }
    }

    if (!$hata) {
        if ($metin === '' && !$qr) {
            $hata = 'Fatura dosyası yükleyin, fatura metnini yapıştırın veya karekot içeriğini girin.';
        } else {
            $veri = fat_metinden_cikar($metin);
            // Metinde karekot yoksa ayrı gelen karekot verisini uygula (karekot esastır)
            if ($qr && empty($veri['qr'])) $veri = fat_qr_birlestir($veri, $qr);
            $eslesme = fat_eslestir($pdo, $veri['irsaliyeler']);

            // Mükerrer kontrolü: fatura zaten kayıtlı mı, irsaliyeler başka faturaya bağlı mı
            $mevcut  = fat_mevcut($pdo, $veri['fatura_no'], $veri['ettn']);
            $baskasi = fat_baskaya_bagli($pdo, array_column($eslesme['eslesen'], 'id'), (int)($mevcut['id'] ?? 0));
            // Zaten kayıtlıysa hangi irsaliyelere bağlı olduğunu ekranda göster
            $mevcutIrs = $mevcut ? (fat_bagli_irsaliyeler($pdo, [(int)$mevcut['id']])[(int)$mevcut['id']] ?? []) : [];

            $sonuc = ['veri'=>$veri, 'eslesme'=>$eslesme, 'kaynak'=>$kaynak, 'dosya_url'=>$dosyaUrl,
                      'metin'=>$metin, 'mevcut'=>$mevcut, 'baskasi'=>$baskasi, 'mevcut_irs'=>$mevcutIrs];
        }
    }
}

// ── 2) Kaydet: eşleşen irsaliyeleri faturaya bağla ──────────────────────────
if (($_POST['action'] ?? '') === 'kaydet') {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['irs_id'] ?? []))));

    // Tedarikçi seçilmemişse ve faturadan satıcı bilgisi çıkarıldıysa (isteğe bağlı kutu):
    // önce VKN, sonra normalize unvan ile mevcut tedarikçi aranır; yoksa OLUŞTURULUR.
    if (!(int)($_POST['tedarikci_id'] ?? 0) && !empty($_POST['tedarikci_olustur'])) {
        $sUnvan = trim((string)($_POST['satici_unvan'] ?? ''));
        $sVkn   = preg_replace('/\D/', '', (string)($_POST['satici_vkn'] ?? ''));
        try {
            $mevTed = fat_tedarikci_bul($pdo, $sVkn) ?: fat_tedarikci_bul_ad($pdo, $sUnvan);
            if ($mevTed) {
                $_POST['tedarikci_id'] = (int)$mevTed['id'];
                if ($sVkn !== '' && trim((string)$mevTed['vkn']) === '') {   // VKN'yi tamamla — bir daha sorulmasın
                    $pdo->prepare("UPDATE tedarikciler SET vkn = ? WHERE id = ?")->execute([$sVkn, (int)$mevTed['id']]);
                }
            } elseif ($sUnvan !== '') {
                $pdo->prepare("INSERT INTO tedarikciler (ad, vkn) VALUES (?, ?)")->execute([$sUnvan, $sVkn ?: null]);
                $_POST['tedarikci_id'] = (int)$pdo->lastInsertId();
                audit_log($pdo, 'tedarikciler', (int)$_POST['tedarikci_id'], 'INSERT', null,
                          ['fatura_otomatik' => true, 'ad' => $sUnvan, 'vkn' => $sVkn], current_user_id());
                flash('info', 'Yeni tedarikçi oluşturuldu: ' . $sUnvan . ($sVkn !== '' ? " (VKN $sVkn)" : ''));
            }
        } catch (Throwable $eT) { /* tedarikçi çözülemedi — aşağıdaki uyarı akışı devreye girer */ }
    }

    // İsteğe bağlı: faturada olup sistemde bulunmayan irsaliyeleri TASLAK olarak oluştur.
    // Taslak; fatura tarihi/tedarikçisiyle, 0 m³ ve [FATURADAN] etiketiyle açılır.
    // Excel aktarımı aynı numarayı getirdiğinde taslak SİLİNMEZ — gerçek verilerle
    // güncellenir (import.php normalize eşleşme), fatura bağı ve ekli belgeler korunur.
    $taslakOlusan = 0; $taslakUyari = '';
    if (!empty($_POST['eksik_olustur'])) {
        $eksikler = is_array($el0 = json_decode((string)($_POST['eksik_liste'] ?? ''), true))
                    ? array_slice(array_map('strval', $el0), 0, 500) : [];
        $tedId = (int)($_POST['tedarikci_id'] ?? 0);
        if (!$tedId) {
            $taslakUyari = 'Eksik irsaliyeler için taslak OLUŞTURULMADI: tedarikçi seçilmedi '
                         . '(irsaliye tedarikçisiz kaydedilemez). Tedarikçiyi seçip faturayı yeniden çözümleyin.';
        } elseif ($eksikler) {
            try {
                $esl0 = fat_eslestir($pdo, $eksikler);   // çözümlemeden beri girilmiş olabilir — yeniden kontrol
                foreach ($esl0['eslesen'] as $r0) $ids[] = (int)$r0['id'];
                $tarih0 = fat_tarih_norm($_POST['tarih'] ?? '') ?: date('Y-m-d');
                $ins0 = $pdo->prepare("INSERT INTO irsaliyeler
                    (tip, durum, irsaliye_no, fatura_no, tedarikci_id, tarih, miktar, birim, aciklama, created_by)
                    VALUES ('alis', 'beklemede', ?, ?, ?, ?, 0, 'M3', ?, ?)");
                foreach ($esl0['eksik'] as $no0) {
                    $ins0->execute([$no0, trim((string)($_POST['fatura_no'] ?? '')) ?: null, $tedId, $tarih0,
                                    '[FATURADAN] Fatura eşleştirmeden otomatik oluşturuldu — Excel aktarımında gerçek verilerle güncellenir.',
                                    current_user_id()]);
                    $yeniId0 = (int)$pdo->lastInsertId();
                    $ids[] = $yeniId0;
                    audit_log($pdo, 'irsaliyeler', $yeniId0, 'INSERT', null,
                              ['fatura_taslak' => true, 'irsaliye_no' => $no0], current_user_id());
                    $taslakOlusan++;
                }
                $_POST['eksik_adet'] = 0; $_POST['eksik_liste'] = '[]';   // artık eksik değiller
            } catch (Throwable $e0) {
                $taslakUyari = 'Taslak irsaliye oluşturulamadı: ' . $e0->getMessage();
            }
        }
        $ids = array_values(array_unique($ids));
    }

    try {
        $r = fat_kaydet($pdo, [
            'fatura_no'    => trim((string)($_POST['fatura_no'] ?? '')),
            'tarih'        => fat_tarih_norm($_POST['tarih'] ?? '') ?: null,
            'tedarikci_id' => (int)($_POST['tedarikci_id'] ?? 0) ?: null,
            'tutar'        => $_POST['tutar'] ?? null,
            'miktar'       => $_POST['miktar'] ?? null,
            'ettn'         => trim((string)($_POST['ettn'] ?? '')) ?: null,
            'eksik_adet'   => (int)($_POST['eksik_adet'] ?? 0),
            'eksik_liste'  => is_array($el = json_decode((string)($_POST['eksik_liste'] ?? ''), true))
                              ? array_slice(array_map('strval', $el), 0, 500) : [],
            'notlar'       => trim((string)($_POST['notlar'] ?? '')) ?: null,
        ], $ids, current_user_id(), trim((string)($_POST['dosya_url'] ?? '')) ?: null);

        // Fatura dosyasını eşleşen her irsaliyenin belgelerine de ekle (irsaliye ekranından görünsün)
        $dosya   = trim((string)($_POST['dosya_url'] ?? ''));
        $faturaNo = trim((string)($_POST['fatura_no'] ?? ''));
        $eklenen = 0; $zaten = 0;
        if ($dosya !== '' && $ids) {
            // Mükerrer önleme fatura NUMARASINA bakar; dosya yolu her yüklemede değişir,
            // ona bakmak aynı faturayı ikinci kez eklerdi.
            $kimlik = ['fatura_no' => $faturaNo];
            foreach ($ids as $iid) {
                if (blg_mukerrer($pdo, (int)$iid, 'fatura', $kimlik)) { $zaten++; continue; }
                blg_ekle($pdo, (int)$iid, 'Fatura ' . $faturaNo, $dosya, 'fatura', $kimlik, current_user_id());
                $eklenen++;
            }
        }
        flash('success', "Fatura kaydedildi: {$r['baglanan']} irsaliye faturaya bağlandı"
                       . ($taslakOlusan ? ", {$taslakOlusan} eksik irsaliye TASLAK olarak oluşturuldu (Excel aktarımında gerçek verilerle güncellenir)" : "")
                       . ($eklenen ? ", fatura {$eklenen} irsaliyenin belgelerine eklendi" : "")
                       . ($zaten   ? ", {$zaten} irsaliyede zaten ekliydi (mükerrer eklenmedi)" : "") . ".");
        if ($taslakUyari) flash('warning', $taslakUyari);
    } catch (Throwable $e) {
        flash('error', 'Kayıt hatası: '.$e->getMessage());
    }
    redirect('fatura_eslestir.php');
}

// ── 2b) Eksik listesi kayıp eski kayıt: saklı PDF'ten yeniden tara ──────────
if (($_POST['action'] ?? '') === 'eksik_tara' && ctype_digit((string)($_POST['fatura_id'] ?? ''))) {
    $fid = (int)$_POST['fatura_id'];
    $f = $pdo->prepare("SELECT * FROM faturalar WHERE id = ?"); $f->execute([$fid]);
    $f = $f->fetch();
    if (!$f) { flash('error', 'Fatura bulunamadı.'); redirect('fatura_eslestir.php?eksik=1'); }

    $tam = $f['dosya_url'] ? __DIR__ . '/' . $f['dosya_url'] : '';
    if ($tam === '' || !is_file($tam)) {
        flash('error', 'Bu faturanın kayıtlı dosyası yok (metin yapıştırarak işlenmiş) — faturayı yukarıdan yeniden yükleyin, kaydettiğinizde eksik numaralar dolar.');
        redirect('fatura_eslestir.php?eksik=1');
    }
    $mime = guess_mime($tam, basename($tam));
    $metin = fat_dosyadan_metin($tam, $mime);
    if ($metin === null || $metin === '') {
        flash('error', 'Dosya okunamadı (AI okuma başarısız) — faturayı yukarıdan yeniden yükleyip kaydedin.');
        redirect('fatura_eslestir.php?eksik=1');
    }
    $veri = fat_metinden_cikar($metin);
    $esl  = fat_eslestir($pdo, $veri['irsaliyeler']);

    // Eksik listesi + karekod-yalnız işlenmiş faturalarda BOŞ kalan alanlar
    // (m³ karekodda yoktur, yalnız metinden okunur) yeniden taramayla doldurulur.
    // Dolu alanların üzerine YAZILMAZ.
    $set = ['eksik_adet = ?', 'eksik_liste = ?'];
    $par = [count($esl['eksik']), json_encode(array_values($esl['eksik']), JSON_UNESCAPED_UNICODE)];
    $dolan = [];
    foreach ([['miktar_m3', $veri['miktar'], 'm³'], ['tutar', $veri['tutar'], 'tutar'],
              ['tarih', $veri['tarih'], 'tarih'], ['ettn', $veri['ettn'], 'ETTN']] as [$kol, $deger, $ad2]) {
        if ($deger !== null && $deger !== '' && ($f[$kol] === null || $f[$kol] === '')) {
            $set[] = "$kol = ?"; $par[] = $deger; $dolan[] = $ad2;
        }
    }
    $par[] = $fid;
    $pdo->prepare("UPDATE faturalar SET " . implode(', ', $set) . " WHERE id = ?")->execute($par);
    audit_log($pdo, 'faturalar', $fid, 'UPDATE', null,
              ['eksik_tara' => true, 'eksik' => $esl['eksik'], 'dolan' => $dolan], current_user_id());

    $mesaj = $esl['eksik'] ? 'Fatura yeniden tarandı — eksik irsaliyeler: ' . implode(', ', $esl['eksik'])
                           : 'Fatura yeniden tarandı — faturadaki tüm irsaliyeler sistemde var, eksik yok.';
    if ($dolan) $mesaj .= ' Boş alanlar dolduruldu: ' . implode(', ', $dolan) . '.';
    $m3Yok = ($f['miktar_m3'] === null || $f['miktar_m3'] === '') && ($veri['miktar'] === null || $veri['miktar'] === '');
    if ($m3Yok) $mesaj .= ' ⚠ m³ değeri metinden OKUNAMADI (PDF görüntü tabanlı olabilir) — listedeki m³ sütunundaki kutuya elle girebilirsiniz.';
    flash(($esl['eksik'] || $m3Yok) ? 'warning' : 'success', $mesaj);
    redirect(($_POST['geri'] ?? '') === 'liste' ? 'fatura_eslestir.php' : 'fatura_eslestir.php?eksik=1');
}

// ── 2c) m³ elle düzeltme (Kayıtlı Faturalar listesinden) ────────────────────
if (($_POST['action'] ?? '') === 'm3_duzelt' && ctype_digit((string)($_POST['fatura_id'] ?? ''))) {
    $fid = (int)$_POST['fatura_id'];
    $m3  = fat_sayi((string)($_POST['m3'] ?? ''));
    if ($m3 === null || $m3 < 0) {
        flash('error', 'Geçerli bir m³ değeri girin (örn. 250 veya 250,00).');
    } else {
        $pdo->prepare("UPDATE faturalar SET miktar_m3 = ? WHERE id = ?")->execute([$m3, $fid]);
        audit_log($pdo, 'faturalar', $fid, 'UPDATE', null, ['m3_elle' => $m3], current_user_id());
        flash('success', 'Fatura m³ değeri elle kaydedildi: ' . number_format($m3, 2, ',', '.') . ' m³.');
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

// Dashboard'daki "faturada var ama sistemde yok" kartından gelinirse eksikleri özetle
$eksikOzet = [];
if (isset($_GET['eksik'])) {
    try {
        foreach ($pdo->query("SELECT id, fatura_no, tarih, eksik_adet, eksik_liste, dosya_url FROM faturalar
                              WHERE eksik_adet > 0 ORDER BY tarih DESC") as $r) {
            $liste = json_decode((string)$r['eksik_liste'], true);
            $eksikOzet[] = ['id' => (int)$r['id'], 'fatura_no' => $r['fatura_no'], 'tarih' => $r['tarih'],
                            'adet' => (int)$r['eksik_adet'], 'liste' => is_array($liste) ? $liste : [],
                            'dosya' => (string)$r['dosya_url']];
        }
    } catch (Throwable $e) { $eksikOzet = []; }
}

$tedarikciler = $pdo->query("SELECT id, ad, vkn FROM tedarikciler ORDER BY ad")->fetchAll();
$kayitli = $pdo->query("SELECT f.*, t.ad AS tedarikci,
                               (SELECT COUNT(*) FROM irsaliyeler i WHERE i.fatura_id = f.id) AS bagli
                        FROM faturalar f LEFT JOIN tedarikciler t ON t.id = f.tedarikci_id
                        ORDER BY f.tarih DESC, f.id DESC LIMIT 100")->fetchAll();
// Her faturanın bağlı irsaliyeleri — listede satır açılınca gösterilir
$kayitliIrs = $kayitli ? fat_bagli_irsaliyeler($pdo, array_column($kayitli, 'id')) : [];

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

<?php if (isset($_GET['eksik'])): ?>
<div class="card mb-4 border-danger">
    <div class="card-header bg-white fw-semibold text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Faturada Olup Sistemde Olmayan İrsaliyeler</div>
    <div class="card-body">
    <?php if (!$eksikOzet): ?>
        <div class="text-success"><i class="bi bi-check-circle me-1"></i>Eksik irsaliyesi olan fatura yok.</div>
    <?php else: foreach ($eksikOzet as $eo): ?>
        <div class="mb-3">
            <div class="fw-semibold"><code><?= h($eo['fatura_no']) ?></code>
                <span class="text-muted small"><?= h(format_date($eo['tarih'])) ?> · <?= (int)$eo['adet'] ?> eksik irsaliye</span></div>
            <?php if ($eo['liste']): ?>
            <div class="d-flex flex-wrap gap-1 mt-1">
                <?php foreach ($eo['liste'] as $n): ?>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= h($n) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="small text-muted mt-1">Bu numaralar sisteme girildikten sonra faturayı yeniden çözümleyip kaydedin — bağ kurulur, eksik düşer.</div>
            <?php else: ?>
            <form method="post" class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                <input type="hidden" name="action" value="eksik_tara">
                <input type="hidden" name="fatura_id" value="<?= (int)$eo['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-search me-1"></i>Eksik irsaliyeleri bul</button>
                <span class="small text-muted">Kayıtlı fatura dosyası yeniden okunur, eksik numaralar burada listelenir<?= $eo['dosya'] === '' ? ' — <strong>bu faturanın dosyası yok</strong>, yukarıdan yeniden yüklemek gerekir' : '' ?>.</span>
            </form>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-upload me-1"></i> Fatura Yükle / Metin Yapıştır</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="action" value="coz">
            <div class="col-md-6">
                <label class="form-label">e-Fatura dosyası <span class="text-muted small">(PDF, JPG, PNG — en fazla 20 MB)</span></label>
                <input type="file" name="dosya" id="fatDosya" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
                <div class="form-text">Faturanın altındaki <strong>karekod</strong> otomatik okunur; metin katmanı yoksa AI devreye girer.</div>
                <input type="hidden" name="qr" id="fatQr">
                <div id="fatQrDurum" class="small mt-2"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">…veya fatura metnini yapıştırın</label>
                <textarea name="metin" class="form-control" rows="4" placeholder="Fatura No: SGA2026000000819&#10;Fatura Tarihi: 12-08-2026&#10;SKB2026000011503 SKB2026000011507 …"></textarea>
                <div class="form-text">Karekod içeriğini (<code>{"vkntckn":…}</code>) de buraya yapıştırabilirsiniz — otomatik tanınır.</div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Çözümle ve Eşleştir</button>
                <span class="text-muted small ms-2">Numara biçimi farkı otomatik giderilir: <code>ANM2026-4710</code> ↔ <code>ANM2026000004710</code></span>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
<script>if (typeof pdfjsLib !== 'undefined') pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';</script>
<script>
// ── Karekod (GİB e-Fatura QR) tarayıcı ───────────────────────────────────────
// Fatura seçilir seçilmez tarayıcıda okunur; sunucuya JSON olarak gider.
(function () {
    var dosyaEl = document.getElementById('fatDosya');
    var qrEl    = document.getElementById('fatQr');
    var durumEl = document.getElementById('fatQrDurum');
    if (!dosyaEl) return;

    function durum(sinif, ikon, mesaj) {
        durumEl.className = 'small mt-2 text-' + sinif;
        durumEl.innerHTML = '<i class="bi ' + ikon + ' me-1"></i>' + mesaj;
    }

    function kontrastArtir(img) {
        var s = img.data, d = new Uint8ClampedArray(s.length);
        for (var i = 0; i < s.length; i += 4) {
            var g = 0.299 * s[i] + 0.587 * s[i + 1] + 0.114 * s[i + 2];
            var v = Math.max(0, Math.min(255, 2.2 * (g - 128) + 128));
            d[i] = d[i + 1] = d[i + 2] = v; d[i + 3] = 255;
        }
        return new ImageData(d, img.width, img.height);
    }

    // e-Fatura karekodu mu? (JSON + 'no'/'ettn' alanı)
    function faturaQrMi(metin) {
        if (!metin || metin.indexOf('{') < 0) return false;
        try {
            var j = JSON.parse(metin.substring(metin.indexOf('{'), metin.lastIndexOf('}') + 1));
            return !!(j && (j.no || j.ettn));
        } catch (e) { return false; }
    }

    async function canvasTara(canvas, ctx) {
        if ('BarcodeDetector' in window) {
            try {
                var det = new BarcodeDetector({ formats: ['qr_code'] });
                var bulunan = await det.detect(canvas);
                for (var i = 0; i < bulunan.length; i++) {
                    if (faturaQrMi(bulunan[i].rawValue)) return bulunan[i].rawValue;
                }
            } catch (e) { /* jsQR'a düş */ }
        }
        if (typeof jsQR === 'undefined') return null;
        var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var r = jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
        if (r && faturaQrMi(r.data)) return r.data;
        var k = kontrastArtir(img);
        r = jsQR(k.data, k.width, k.height, { inversionAttempts: 'attemptBoth' });
        return (r && faturaQrMi(r.data)) ? r.data : null;
    }

    async function pdfTara(buf) {
        if (typeof pdfjsLib === 'undefined') return null;
        var pdf = await pdfjsLib.getDocument({ data: buf }).promise;
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        var sayfaSayisi = Math.min(pdf.numPages, 3);
        var olcekler = [2.5, 4.0];
        for (var s = 1; s <= sayfaSayisi; s++) {
            var page = await pdf.getPage(s);
            for (var o = 0; o < olcekler.length; o++) {
                var vp = page.getViewport({ scale: olcekler[o] });
                canvas.width = vp.width; canvas.height = vp.height;
                await page.render({ canvasContext: ctx, viewport: vp }).promise;
                var v = await canvasTara(canvas, ctx);
                if (v) return v;
            }
        }
        return null;
    }

    function gorselTara(dataUrl) {
        return new Promise(function (cozumle) {
            var im = new Image();
            im.onload = async function () {
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d', { willReadFrequently: true });
                var buyut = Math.min(3, Math.max(1, 1600 / Math.max(im.width, im.height)));
                canvas.width = im.width * buyut; canvas.height = im.height * buyut;
                ctx.drawImage(im, 0, 0, canvas.width, canvas.height);
                cozumle(await canvasTara(canvas, ctx));
            };
            im.onerror = function () { cozumle(null); };
            im.src = dataUrl;
        });
    }

    dosyaEl.addEventListener('change', async function () {
        qrEl.value = '';
        var f = dosyaEl.files && dosyaEl.files[0];
        if (!f) { durumEl.innerHTML = ''; return; }
        durum('muted', 'bi-hourglass-split', 'Karekod aranıyor…');
        try {
            var deger = null;
            if (f.type === 'application/pdf' || /\.pdf$/i.test(f.name)) {
                deger = await pdfTara(await f.arrayBuffer());
            } else {
                deger = await gorselTara(URL.createObjectURL(f));
            }
            if (deger) {
                qrEl.value = deger;
                var j = JSON.parse(deger.substring(deger.indexOf('{'), deger.lastIndexOf('}') + 1));
                durum('success', 'bi-qr-code-scan',
                      'Karekod okundu: <strong>' + (j.no || '?') + '</strong> · ' + (j.tarih || '?') +
                      ' · ödenecek <strong>' + (j.odenecek || '?') + '</strong>');
            } else {
                durum('warning', 'bi-exclamation-triangle',
                      'Karekod bulunamadı — fatura yine de metinden okunacak. Karekod içeriğini elle yapıştırabilirsiniz.');
            }
        } catch (e) {
            durum('warning', 'bi-exclamation-triangle', 'Karekod taranamadı: ' + e.message);
        }
    });
})();
</script>

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
<input type="hidden" name="eksik_liste" value="<?= h(json_encode($e['eksik'], JSON_UNESCAPED_UNICODE)) ?>">

<?php
$mevcut    = $sonuc['mevcut']  ?? null;
$baskasi   = $sonuc['baskasi'] ?? [];
$mevcutIrs = $sonuc['mevcut_irs'] ?? [];
// Şimdi eşleşenlerin hangileri bu faturaya ZATEN bağlı, hangileri yeni?
$mevcutIrsId = array_map(fn($r) => (int)$r['id'], $mevcutIrs);
$yeniBag = $eskiBag = 0;
foreach ($e['eslesen'] as $r) { in_array((int)$r['id'], $mevcutIrsId, true) ? $eskiBag++ : $yeniBag++; }
?>
<?php if ($mevcut): ?>
<div class="alert alert-warning">
    <h6 class="alert-heading"><i class="bi bi-files me-1"></i>Bu fatura sistemde ZATEN İŞLENMİŞ</h6>
    <div class="small">
        <strong><?= h($mevcut['fatura_no']) ?></strong> · <?= h(format_date($mevcut['tarih'])) ?>
        <?= $mevcut['tedarikci'] ? ' · '.h($mevcut['tedarikci']) : '' ?>
        · <strong><?= (int)$mevcut['bagli'] ?></strong> irsaliye bağlı
        · <?= h(format_date($mevcut['created_at'])) ?> tarihinde kaydedilmiş
        <?php if ($mevcut['eslesme_alani'] === 'ettn'): ?>
        <div class="mt-1"><i class="bi bi-info-circle me-1"></i>Fatura numarası farklı ama <strong>ETTN aynı</strong> —
            aynı e-faturanın başka numarayla girilmiş hali. ETTN faturanın değişmez kimliğidir.</div>
        <?php endif; ?>

        <?php if ($mevcutIrs): ?>
        <div class="mt-3 fw-semibold">Bu faturaya bağlı irsaliyeler (<?= count($mevcutIrs) ?>):</div>
        <div class="table-responsive mt-1">
            <table class="table table-sm table-bordered bg-body mb-1" style="max-width:820px">
                <thead class="table-light"><tr>
                    <th>İrsaliye No</th><th>Tarih</th><th>Plaka</th><th>Beton</th>
                    <th class="text-end">m³</th><th>Durum</th><th>Şimdiki faturada</th>
                </tr></thead>
                <tbody>
                <?php $mToplam = 0.0; foreach ($mevcutIrs as $mi): $mToplam += (float)$mi['miktar'];
                      $halaVar = in_array((int)$mi['id'], array_map(fn($x)=>(int)$x['id'], $e['eslesen']), true); ?>
                    <tr class="<?= $halaVar ? '' : 'table-warning' ?>">
                        <td><a href="irsaliye_detay.php?id=<?= (int)$mi['id'] ?>" target="_blank"><code><?= h($mi['irsaliye_no']) ?></code></a></td>
                        <td><?= h(format_date($mi['tarih'])) ?></td>
                        <td><?= h((string)$mi['arac_plaka']) ?></td>
                        <td><?= h((string)$mi['beton_sinifi']) ?></td>
                        <td class="text-end"><?= $fmt($mi['miktar']) ?></td>
                        <td><?= h($mi['durum']) ?></td>
                        <td><?= $halaVar ? '<span class="text-success">✓ var</span>'
                                         : '<span class="text-danger fw-bold">✗ yok — bağ kopacak</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold"><tr>
                    <td colspan="4" class="text-end">Toplam</td><td class="text-end"><?= $fmt($mToplam) ?></td><td colspan="2"></td>
                </tr></tfoot>
            </table>
        </div>
        <?php endif; ?>

        <div class="mt-2">
            <?php if ($yeniBag === 0 && $eskiBag > 0): ?>
                <i class="bi bi-check-circle-fill text-success me-1"></i>
                <strong>Değişiklik yok</strong> — şu an eşleşen <?= $eskiBag ?> irsaliyenin hepsi zaten bu faturaya bağlı.
                Tekrar kaydetmene gerek yok.
            <?php else: ?>
                Kaydedersen <strong>yeni kayıt oluşmaz</strong>, mevcut kayıt güncellenir:
                <strong><?= $yeniBag ?></strong> yeni bağ kurulur,
                <strong><?= $eskiBag ?></strong> bağ zaten var.
            <?php endif; ?>
            <a href="irsaliyeler.php?tip=tum&fatura_id=<?= (int)$mevcut['id'] ?>" class="alert-link ms-1">İrsaliye listesinde aç</a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($baskasi): ?>
<div class="alert alert-danger">
    <h6 class="alert-heading"><i class="bi bi-exclamation-octagon-fill me-1"></i><?= count($baskasi) ?> irsaliye BAŞKA bir faturaya bağlı</h6>
    <div class="small">
        Aynı irsaliyenin iki faturada görünmesi <strong>çift faturalandırma</strong> anlamına gelebilir.
        Kaydedersen bu irsaliyeler eski faturadan kopar ve bu faturaya bağlanır.
        <ul class="mb-0 mt-1">
            <?php foreach ($baskasi as $b): ?>
            <li><code><?= h($b['irsaliye_no']) ?></code> → şu an
                <a href="irsaliyeler.php?tip=tum&fatura_id=<?= (int)$b['fatura_id'] ?>" class="alert-link"><?= h($b['fatura_no']) ?></a> faturasında</li>
            <?php endforeach; ?>
        </ul>
        <div class="mt-2">Taşınmasını istemediğin satırların işaretini aşağıdaki listeden kaldır.</div>
    </div>
</div>
<?php endif; ?>

<?php $qr = $v['qr'] ?? null; $qrTed = $qr ? fat_tedarikci_bul($pdo, $qr['vkn'] ?? null) : null; ?>
<?php if ($qr): ?>
<div class="card mb-3 border-success">
    <div class="card-header bg-white fw-semibold text-success"><i class="bi bi-qr-code-scan me-1"></i> Karekod Okundu — faturanın kendi beyanı</div>
    <div class="card-body">
        <div class="row g-2 small">
            <div class="col-md-3"><span class="text-muted">Fatura No</span><div class="fw-bold"><?= h((string)$qr['fatura_no']) ?></div></div>
            <div class="col-md-2"><span class="text-muted">Tarih</span><div class="fw-bold"><?= h(format_date($qr['tarih'])) ?></div></div>
            <div class="col-md-3"><span class="text-muted">Satıcı VKN</span>
                <div class="fw-bold"><?= h((string)$qr['vkn']) ?>
                    <?php if ($qrTed): ?><span class="badge bg-success ms-1"><?= h($qrTed['ad']) ?></span>
                    <?php else: ?><span class="badge bg-warning text-dark ms-1">tanımlı değil</span><?php endif; ?>
                </div></div>
            <div class="col-md-2"><span class="text-muted">Tip</span><div class="fw-bold"><?= h((string)$qr['tip']) ?></div></div>
            <div class="col-md-2"><span class="text-muted">Ödenecek</span><div class="fw-bold"><?= $qr['tutar']!==null?$fmt($qr['tutar']).' ₺':'—' ?></div></div>
            <?php if ($qr['matrah'] !== null): ?>
            <div class="col-md-3"><span class="text-muted">Mal/Hizmet Toplam</span><div><?= $fmt($qr['matrah']) ?> ₺</div></div>
            <?php endif; ?>
            <?php if ($qr['tevkifat'] !== null): ?>
            <div class="col-md-3"><span class="text-muted">KDV Tevkifat</span><div><?= $fmt($qr['tevkifat']) ?> ₺</div></div>
            <?php endif; ?>
            <div class="col-md-6"><span class="text-muted">ETTN</span><div class="text-break"><code><?= h((string)$qr['ettn']) ?></code></div></div>
        </div>
        <?php if (!$qrTed && !empty($qr['vkn'])): ?>
        <div class="alert alert-warning py-2 small mt-3 mb-0">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong><?= h($qr['vkn']) ?></strong> VKN'si hiçbir tedarikçide kayıtlı değil — aşağıdaki
            <strong>"otomatik oluştur"</strong> kutusunu işaretli bırakın (kaydetmede VKN'siyle açılır) ya da tedarikçiyi elle seçin.
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($v['uyari'])): ?>
<div class="alert alert-warning">
    <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Karekod ile fatura metni çelişiyor</strong>
    <ul class="mb-0 mt-1 small"><?php foreach ($v['uyari'] as $u): ?><li><?= h($u) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text me-1"></i> Fatura Bilgileri</div>
    <div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Fatura No</label>
            <input type="text" name="fatura_no" class="form-control" value="<?= h((string)$v['fatura_no']) ?>" required></div>
        <div class="col-md-2"><label class="form-label">Tarih</label>
            <input type="date" name="tarih" class="form-control" value="<?= h((string)$v['tarih']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Tedarikçi</label>
            <?php
            // Öncelik: karekot/metin VKN (kesin) → satıcı unvanı (normalize ad) → eşleşen irsaliyelerin tedarikçisi
            $saticiVkn   = preg_replace('/\D/', '', (string)($v['satici_vkn'] ?? ''));
            $saticiUnvan = trim((string)($v['satici_unvan'] ?? ''));
            $onerTed = $qrTed ?: ($saticiVkn !== '' ? fat_tedarikci_bul($pdo, $saticiVkn) : null);
            if (!$onerTed && $saticiUnvan !== '') $onerTed = fat_tedarikci_bul_ad($pdo, $saticiUnvan);
            $onerId = $onerTed ? (int)$onerTed['id'] : 0;
            if (!$onerId && $e['eslesen']) {
                $ilk = $e['eslesen'][0];
                foreach ($tedarikciler as $td) if ($td['ad'] === $ilk['tedarikci']) $onerId = (int)$td['id'];
            }
            ?>
            <select name="tedarikci_id" class="form-select">
                <option value="">— seçiniz —</option>
                <?php foreach ($tedarikciler as $td): ?>
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
        <?php if (!$onerId && ($saticiUnvan !== '' || $saticiVkn !== '')): ?>
        <input type="hidden" name="satici_unvan" value="<?= h($saticiUnvan) ?>">
        <input type="hidden" name="satici_vkn" value="<?= h($saticiVkn) ?>">
        <div class="col-12"><div class="form-check p-3 pt-2 ps-5 bg-info-subtle border border-info rounded mb-0">
            <input class="form-check-input" type="checkbox" name="tedarikci_olustur" value="1" id="tedOlustur" checked>
            <label class="form-check-label" for="tedOlustur">
                <strong>Tedarikçi kayıtlı değil — faturadan otomatik oluştur:</strong>
                <?= $saticiUnvan !== '' ? h($saticiUnvan) : '<em>unvan okunamadı</em>' ?><?= $saticiVkn !== '' ? ' (VKN '.h($saticiVkn).')' : '' ?>
                <div class="small text-muted mt-1">Kaydettiğinizde bu satıcı tedarikçi olarak açılır (VKN'siyle — bir daha sorulmaz)
                    ve fatura ona bağlanır. Yukarıdan elle tedarikçi seçerseniz bu kutu yok sayılır.</div>
            </label>
        </div></div>
        <?php endif; ?>
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
            <?php foreach ($e['eslesen'] as $r): $cakisma = isset($baskasi[(int)$r['id']]); ?>
                <tr class="<?= $cakisma ? 'table-danger' : '' ?>">
                    <td><input type="checkbox" class="form-check-input irs-chk" name="irs_id[]" value="<?= (int)$r['id'] ?>" checked></td>
                    <td><code><?= h($r['fatura_gosterim']) ?></code></td>
                    <td><code><?= h($r['irsaliye_no']) ?></code></td>
                    <td><?= h(format_date($r['tarih'])) ?></td>
                    <td><?= h((string)$r['tedarikci']) ?></td>
                    <td><?= h((string)$r['beton_sinifi']) ?></td>
                    <td><?= h((string)$r['arac_plaka']) ?></td>
                    <td class="text-end"><?= $fmt($r['miktar']) ?></td>
                    <td><span class="badge bg-<?= $r['durum']==='reddedildi'?'danger':($r['durum']==='beklemede'?'secondary':'success') ?>"><?= h($r['durum']) ?></span>
                        <?php if (isset($baskasi[(int)$r['id']])): ?>
                        <span class="badge bg-danger" title="Bu irsaliye başka bir faturaya bağlı">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= h($baskasi[(int)$r['id']]['fatura_no']) ?>
                        </span>
                        <?php endif; ?>
                    </td>
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
        <div class="form-check mt-3 p-3 pt-2 ps-5 bg-warning-subtle border border-warning rounded">
            <input class="form-check-input" type="checkbox" name="eksik_olustur" value="1" id="eksikOlustur">
            <label class="form-check-label" for="eksikOlustur">
                <strong>Bu <?= $eksikAdet ?> irsaliyeyi taslak olarak oluştur ve faturaya bağla</strong> <span class="text-muted">(isteğe bağlı)</span>
                <div class="small text-muted mt-1">
                    Taslaklar fatura tarihi ve tedarikçisiyle, <strong>0 m³</strong> ve
                    <span class="badge bg-secondary">[FATURADAN]</span> notuyla açılır; fatura dosyası belge olarak eklenir.
                    Excel aktarımı aynı numarayı getirdiğinde taslak <strong>silinmez, gerçek verilerle güncellenir</strong> —
                    fatura bağı ve ekli belgeler korunur. Bunun için <strong>tedarikçi seçili olmalıdır</strong>.
                </div>
            </label>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="mb-4">
    <button class="btn btn-<?= $baskasi ? 'warning' : 'success' ?>" <?= ($eslesenAdet || $eksikAdet) ? '' : 'disabled' ?>
            <?= $baskasi ? 'onclick="return confirm(\'İşaretli irsaliyelerden bazıları başka bir faturaya bağlı. Bu faturaya taşınsın mı?\')"' : '' ?>>
        <i class="bi bi-save me-1"></i><?= $mevcut ? 'Mevcut Faturayı Güncelle' : 'Faturayı Kaydet' ?> ve İrsaliyeleri Bağla
    </button>
    <a href="fatura_eslestir.php" class="btn btn-outline-secondary">Vazgeç</a>
</div>
</form>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-archive me-1"></i> Kayıtlı Faturalar</span>
        <span class="badge bg-primary" title="Toplam fatura sayısı"><?= count($kayitli) ?> fatura
            · <?= $fmt(array_sum(array_map(fn($x)=>(float)$x['miktar_m3'], $kayitli))) ?> m³
            · <?= $fmt(array_sum(array_map(fn($x)=>(float)$x['tutar'], $kayitli))) ?> ₺</span>
    </div>
    <div class="card-body p-0">
    <?php if (!$kayitli): ?>
        <div class="p-3 text-muted">Henüz kayıtlı fatura yok.</div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light"><tr>
                <th class="text-center">#</th>
                <th>Fatura No</th><th>Tarih</th><th>Tedarikçi</th><th class="text-end">Tutar</th>
                <th class="text-end">m³</th><th class="text-end">Bağlı İrs.</th><th class="text-end">Eksik</th><th>Dosya</th><th></th>
            </tr></thead>
            <tbody>
            <?php $sira = count($kayitli); foreach ($kayitli as $f): $fIrs = $kayitliIrs[(int)$f['id']] ?? []; ?>
                <tr>
                    <td class="text-center text-muted"><?= $sira-- ?></td>
                    <td><code><?= h($f['fatura_no']) ?></code></td>
                    <td><?= h(format_date($f['tarih'])) ?></td>
                    <td><?= h((string)$f['tedarikci']) ?></td>
                    <td class="text-end"><?= $f['tutar']!==null?$fmt($f['tutar']).' ₺':'—' ?></td>
                    <td class="text-end">
                        <?php if ($f['miktar_m3'] !== null): ?><?= $fmt($f['miktar_m3']) ?>
                        <?php else: ?>
                        <div class="d-inline-flex align-items-center gap-1">
                            <?php if ($f['dosya_url']): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="eksik_tara">
                                <input type="hidden" name="fatura_id" value="<?= (int)$f['id'] ?>">
                                <input type="hidden" name="geri" value="liste">
                                <button class="btn btn-sm btn-outline-secondary py-0" title="m³ faturada var ama kayıtta boş — PDF yeniden okunur, boş alanlar dolar">
                                    <i class="bi bi-arrow-clockwise"></i> tara</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" class="d-inline-flex align-items-center gap-1" title="Faturadaki m³ değerini elle girin">
                                <input type="hidden" name="action" value="m3_duzelt">
                                <input type="hidden" name="fatura_id" value="<?= (int)$f['id'] ?>">
                                <input type="text" name="m3" class="form-control form-control-sm py-0 px-1 text-end"
                                       style="width:64px" placeholder="m³" inputmode="decimal">
                                <button class="btn btn-sm btn-outline-success py-0"><i class="bi bi-check-lg"></i></button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($fIrs): ?>
                        <button class="btn btn-sm btn-link p-0 text-decoration-none" type="button"
                                data-bs-toggle="collapse" data-bs-target="#fi<?= (int)$f['id'] ?>">
                            <?= count($fIrs) ?> <i class="bi bi-chevron-down small"></i>
                        </button>
                        <?php else: ?><?= (int)$f['bagli'] ?><?php endif; ?>
                    </td>
                    <td class="text-end <?= (int)$f['eksik_adet']?'text-danger fw-bold':'' ?>">
                        <?php $fTaslak = count(array_filter($fIrs, 'irs_taslak_mi')); ?>
                        <?php if ((int)$f['eksik_adet'] > 0): $fel = json_decode((string)($f['eksik_liste'] ?? ''), true); ?>
                        <a href="?eksik=1" class="text-danger text-decoration-none" title="<?= h(is_array($fel) ? implode(', ', $fel) : 'numara listesi için tıklayın') ?>"><?= (int)$f['eksik_adet'] ?> <i class="bi bi-box-arrow-up-right small"></i></a>
                        <?php elseif ($fTaslak): ?>
                        <span class="badge bg-warning text-dark" title="Faturadan otomatik açılmış taslak irsaliyeler — Excel aktarımıyla gerçek veriler gelmeden EKSİK sayılır, onaylanamaz"><?= $fTaslak ?> taslak</span>
                        <?php else: ?>0<?php endif; ?>
                    </td>
                    <td><?php if ($f['dosya_url']): ?><a href="<?= h($f['dosya_url']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary py-0" href="irsaliyeler.php?fatura_id=<?= (int)$f['id'] ?>">İrsaliyeler</a>
                        <?php if (is_admin()): ?>
                        <a class="btn btn-sm btn-outline-danger py-0" href="?sil=<?= (int)$f['id'] ?>" onclick="return confirm('Fatura kaydı silinsin, irsaliye bağları çözülsün mü?')"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($fIrs): ?>
                <tr class="collapse" id="fi<?= (int)$f['id'] ?>">
                    <td colspan="10" class="bg-body-tertiary">
                        <div class="small fw-semibold mb-1">
                            <?= h($f['fatura_no']) ?> faturasına bağlı irsaliyeler (<?= count($fIrs) ?>)
                        </div>
                        <table class="table table-sm table-bordered bg-body mb-0" style="max-width:760px">
                            <thead class="table-light"><tr>
                                <th>İrsaliye No</th><th>Tarih</th><th>Plaka</th><th>Beton</th><th class="text-end">m³</th><th>Durum</th>
                            </tr></thead>
                            <tbody>
                            <?php $ft = 0.0; foreach ($fIrs as $fi): $ft += (float)$fi['miktar']; ?>
                                <tr>
                                    <td><a href="irsaliye_detay.php?id=<?= (int)$fi['id'] ?>" target="_blank"><code><?= h($fi['irsaliye_no']) ?></code></a>
                                        <?php if (irs_taslak_mi($fi)): ?><span class="badge bg-warning text-dark" title="Faturadan otomatik açıldı — Excel aktarımıyla dolana kadar onaylanamaz">TASLAK</span><?php endif; ?></td>
                                    <td><?= h(format_date($fi['tarih'])) ?></td>
                                    <td><?= h((string)$fi['arac_plaka']) ?></td>
                                    <td><?= h((string)$fi['beton_sinifi']) ?></td>
                                    <td class="text-end"><?= $fmt($fi['miktar']) ?></td>
                                    <td><?= h($fi['durum']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold"><tr>
                                <td colspan="4" class="text-end">Toplam</td>
                                <td class="text-end"><?= $fmt($ft) ?></td>
                                <td class="<?= ($f['miktar_m3']!==null && abs($ft-(float)$f['miktar_m3'])>0.01) ? 'text-danger' : 'text-success' ?>">
                                    <?php if ($f['miktar_m3'] !== null): ?>
                                        fatura <?= $fmt($f['miktar_m3']) ?> m³
                                        <?= abs($ft-(float)$f['miktar_m3'])>0.01 ? '(fark '.$fmt($ft-(float)$f['miktar_m3']).')' : '✓' ?>
                                    <?php endif; ?>
                                </td>
                            </tr></tfoot>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
