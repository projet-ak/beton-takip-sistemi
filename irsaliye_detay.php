<?php
/**
 * irsaliye_detay.php — İrsaliye detay görüntüleme + fotoğraf yükleme
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }

require_auth();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/belge.php';
try { blg_semasi_kur($pdo); } catch (Throwable $e) { /* kolonlar zaten varsa geç */ }

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { flash('error', 'Geçersiz istek.'); redirect('irsaliyeler.php'); }

$stmt = $pdo->prepare("
    SELECT i.*,
           t.ad   AS tedarikci_adi,
           bs.ad  AS beton_sinifi_adi,
           ks.ad  AS kivam_sinifi_adi,
           pt.ad  AS pompa_adi,
           k1.ad  AS katki1_adi,
           k2.ad  AS katki2_adi,
           fr.ad  AS firma_adi,
           ig.ad  AS imalat_grup_adi,
           aik.ad AS ana_is_kalemi_adi,
           par.ad AS parsel_adi,
           blk.ad AS blok_adi,
           kot.kot_degeri,
           cb.username AS created_by_user,
           ub.username AS updated_by_user
    FROM irsaliyeler i
    LEFT JOIN tedarikciler t       ON t.id   = i.tedarikci_id
    LEFT JOIN beton_siniflari bs   ON bs.id  = i.beton_sinifi_id
    LEFT JOIN kivam_siniflari ks   ON ks.id  = i.kivam_sinifi_id
    LEFT JOIN pompa_turleri pt     ON pt.id  = i.pompa_id
    LEFT JOIN katki_listesi k1     ON k1.id  = i.katki1_id
    LEFT JOIN katki_listesi k2     ON k2.id  = i.katki2_id
    LEFT JOIN firmalar fr          ON fr.id  = i.firma_id
    LEFT JOIN imalat_gruplari ig   ON ig.id  = i.imalat_grup_id
    LEFT JOIN ana_is_kalemleri aik ON aik.id = i.ana_is_kalemi_id
    LEFT JOIN parseller par        ON par.id = i.parsel_id
    LEFT JOIN bloklar blk          ON blk.id = i.blok_id
    LEFT JOIN kotlar kot           ON kot.id = i.kot_id
    LEFT JOIN users cb             ON cb.id  = i.created_by
    LEFT JOIN users ub             ON ub.id  = i.updated_by
    WHERE i.id = ?
");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) { flash('error', 'İrsaliye bulunamadı.'); redirect('irsaliyeler.php'); }

$pageTitle = 'İrsaliye Detay #' . $id . ' — Beton Takip Sistemi';

// Belge / fotoğraf yükleme (kantar fişi ve fatura AI ile okunur)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit() && isset($_FILES['foto'])) {
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];
    $maxSize = 10 * 1024 * 1024; // 10 MB
    $tur     = isset($_POST['tur']) && isset(BLG_TURLER[$_POST['tur']]) ? $_POST['tur'] : 'foto';
    $oku     = !empty($_POST['oku']) && in_array($tur, ['kantar','fatura','irsaliye'], true);

    $yuklenen = 0;
    $atlanan  = [];   // dosya adı → neden
    $okundu   = 0;
    $uygulanan = [];

    foreach ($_FILES['foto']['tmp_name'] as $i => $tmpName) {
        $hata = (int)$_FILES['foto']['error'][$i];
        $ad   = (string)$_FILES['foto']['name'][$i];
        if ($hata === UPLOAD_ERR_NO_FILE) continue;                    // hiç dosya seçilmemiş
        if ($hata !== UPLOAD_ERR_OK) { $atlanan[] = "$ad: yükleme hatası (kod $hata)"; continue; }

        $mime = guess_mime($tmpName, $ad);
        if (!in_array($mime, $allowed)) { $atlanan[] = "$ad: desteklenmeyen tür"; continue; }
        if ($_FILES['foto']['size'][$i] > $maxSize) { $atlanan[] = "$ad: 10 MB sınırı aşıldı"; continue; }

        $dosya = blg_dosya_tasi($tmpName, $ad, $id, __DIR__);
        if (!$dosya) { $atlanan[] = "$ad: diske yazılamadı"; continue; }

        // AI okuma — dosya diske yazıldıktan SONRA (okuma başarısız olsa da belge kaybolmaz)
        $veri = null;
        if ($oku) {
            try { $veri = blg_ai_oku($dosya['tam'], $mime, $tur); } catch (Throwable $e) { $veri = null; }
            if ($veri) $okundu++;
        }
        blg_ekle($pdo, $id, $ad, $dosya['yol'], $tur, $veri, current_user_id());
        $yuklenen++;

        // Kantar fişi okunduysa boş kantar alanlarını doldur
        if ($veri && $tur === 'kantar' && !empty($_POST['kantar_uygula'])) {
            $uygulanan = array_merge($uygulanan, blg_kantar_uygula($pdo, $id, $veri, current_user_id()));
        }
    }

    $turAd = blg_tur_ad($tur);
    if ($yuklenen === 0 && !$atlanan) {
        flash('error', 'Dosya seçilmedi — önce dosya seçin.');
    } else {
        $mesaj = [];
        if ($yuklenen > 0) $mesaj[] = "$yuklenen $turAd yüklendi";
        if ($oku)          $mesaj[] = ($okundu > 0 ? "$okundu tanesi AI ile okundu" : 'AI okuma başarısız (belge yine de kaydedildi)');
        if ($uygulanan)    $mesaj[] = 'İrsaliyeye yazıldı: ' . implode(' · ', $uygulanan);
        if ($atlanan)      $mesaj[] = count($atlanan) . ' atlandı: ' . implode(' · ', $atlanan);
        flash($yuklenen > 0 ? 'success' : 'error', implode(' — ', $mesaj));
    }
    redirect("irsaliye_detay.php?id={$id}");
}

// Okunan kantar değerlerini sonradan uygula
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit() && ($_POST['action'] ?? '') === 'kantar_uygula') {
    $bid = (int)($_POST['belge_id'] ?? 0);
    $b = $pdo->prepare("SELECT okunan FROM irsaliye_fotolar WHERE id=? AND irsaliye_id=?");
    $b->execute([$bid, $id]);
    $ok = json_decode((string)$b->fetchColumn(), true);
    if (is_array($ok)) {
        $u = blg_kantar_uygula($pdo, $id, $ok, current_user_id());
        flash($u ? 'success' : 'info', $u ? 'İrsaliyeye yazıldı: ' . implode(' · ', $u)
                                         : 'Yazılacak boş alan kalmadı — mevcut değerler korundu.');
    } else { flash('error', 'Bu belgede okunmuş veri yok.'); }
    redirect("irsaliye_detay.php?id={$id}");
}

// Foto silme
if (can_edit() && isset($_GET['foto_sil']) && ctype_digit($_GET['foto_sil'])) {
    $fid  = (int)$_GET['foto_sil'];
    $frow = $pdo->prepare("SELECT * FROM irsaliye_fotolar WHERE id=? AND irsaliye_id=?");
    $frow->execute([$fid, $id]);
    $frow = $frow->fetch();
    if ($frow) {
        $pdo->prepare("DELETE FROM irsaliye_fotolar WHERE id=?")->execute([$fid]);
        // Aynı dosya birden çok irsaliyeye bağlı olabilir (ör. tek fatura → 9 irsaliye);
        // diskten yalnız son bağ da koptuğunda sil.
        $kalan = $pdo->prepare("SELECT COUNT(*) FROM irsaliye_fotolar WHERE dosya_yolu=?");
        $kalan->execute([$frow['dosya_yolu']]);
        if ((int)$kalan->fetchColumn() === 0) @unlink(__DIR__ . '/' . $frow['dosya_yolu']);
        flash('success', 'Belge silindi.');
    }
    redirect("irsaliye_detay.php?id={$id}");
}

// Fotoğraflar listesi
$fotolar = $pdo->prepare("SELECT * FROM irsaliye_fotolar WHERE irsaliye_id=? ORDER BY created_at");
$fotolar->execute([$id]);
$fotolar = $fotolar->fetchAll();

// Bağlı fatura (fatura_eslestir.php ile kurulan bağ)
$bagliFatura = null;
if (!empty($row['fatura_id'])) {
    $f = $pdo->prepare("SELECT f.*, t.ad AS tedarikci FROM faturalar f
                        LEFT JOIN tedarikciler t ON t.id = f.tedarikci_id WHERE f.id = ?");
    $f->execute([(int)$row['fatura_id']]);
    $bagliFatura = $f->fetch() ?: null;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="irsaliyeler.php?tip=<?= h($row['tip']) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="mb-0">
        <?php if ($row['tip'] === 'alis'): ?>
            <i class="bi bi-arrow-down-circle text-success me-2"></i>
        <?php else: ?>
            <i class="bi bi-arrow-up-circle text-danger me-2"></i>
        <?php endif; ?>
        İrsaliye Detay
        <?php if ($row['irsaliye_no']): ?>
            <span class="badge bg-secondary ms-1"><?= h($row['irsaliye_no']) ?></span>
        <?php endif; ?>
    </h4>
    <?php if (can_edit()): ?>
    <a href="irsaliye_form.php?id=<?= $id ?>&tip=<?= h($row['tip']) ?>" class="btn btn-outline-warning btn-sm ms-auto">
        <i class="bi bi-pencil me-1"></i> Düzenle
    </a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-lg-8">

        <!-- Genel Bilgiler -->
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> Genel Bilgiler</div>
            <div class="card-body">
                <div class="row g-2">
                    <?php
                    $fields = [
                        'Tip'           => $row['tip'] === 'alis' ? '<span class="badge bg-success">Alış</span>' : '<span class="badge bg-danger">İade</span>',
                        'Tarih'         => format_date($row['tarih']),
                        'Sıra No'       => h($row['sira_no'] ?: '-'),
                        'Tedarikçi'     => h($row['tedarikci_adi'] ?: '-'),
                        'İrsaliye No'   => '<code>' . h($row['irsaliye_no'] ?: '-') . '</code>',
                        'Fatura No'     => h($row['fatura_no'] ?: '-'),
                        'Araç Plaka'    => h($row['arac_plaka'] ?: '-'),
                        'Kıvam Sınıfı' => h($row['kivam_sinifi_adi'] ?: '-'),
                    ];
                    foreach ($fields as $lbl => $val):
                    ?>
                    <div class="col-sm-6 col-md-4">
                        <div class="text-muted small"><?= $lbl ?></div>
                        <div class="fw-semibold"><?= $val ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Beton & Miktar -->
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-layers text-primary me-1"></i> Beton & Miktar</div>
            <div class="card-body">
                <div class="row g-2">
                    <?php
                    $fields = [
                        'Beton Sınıfı' => h($row['beton_sinifi_adi'] ?: '-'),
                        'Miktar'        => '<strong>' . format_number($row['miktar'], 2) . ' ' . h($row['birim']) . '</strong>',
                        'Pompa Türü'   => h($row['pompa_adi'] ?: '-'),
                        'Katkı 1'      => h($row['katki1_adi'] ?: '-'),
                        'Katkı 2'      => h($row['katki2_adi'] ?: '-'),
                    ];
                    foreach ($fields as $lbl => $val):
                    ?>
                    <div class="col-sm-6 col-md-4">
                        <div class="text-muted small"><?= $lbl ?></div>
                        <div class="fw-semibold"><?= $val ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Kantar & Saatler -->
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-speedometer text-primary me-1"></i> Kantar & Saat</div>
            <div class="card-body">
                <div class="row g-2">
                    <?php
                    $fields = [
                        'Mikser Çıkış'        => h($row['mikser_cikis_saati'] ?: '-'),
                        'Kantar Giriş'        => h($row['kantar_giris_saati'] ?: '-'),
                        'Kantar Çıkış'        => h($row['kantar_cikis_saati'] ?: '-'),
                        'Kantar Net (Yıldızlar)' => $row['kantar_net_yildizlar'] !== null ? format_number($row['kantar_net_yildizlar'], 2) . ' kg' : '-',
                        'Kantar Net (Tedarikçi)' => $row['kantar_net_tedarikci'] !== null ? format_number($row['kantar_net_tedarikci'], 2) . ' kg' : '-',
                        'Kantar Farkı'         => $row['kantar_farki'] !== null
                            ? '<span class="' . ($row['kantar_farki'] > 0 ? 'text-danger' : ($row['kantar_farki'] < 0 ? 'text-success' : '')) . '">'
                              . format_number($row['kantar_farki'], 2) . ' kg</span>'
                            : '-',
                    ];
                    foreach ($fields as $lbl => $val):
                    ?>
                    <div class="col-sm-6 col-md-4">
                        <div class="text-muted small"><?= $lbl ?></div>
                        <div class="fw-semibold"><?= $val ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($row['aciklama']): ?>
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-chat-text text-primary me-1"></i> Açıklama</div>
            <div class="card-body"><?= nl2br(h($row['aciklama'])) ?></div>
        </div>
        <?php endif; ?>

    </div>

    <div class="col-lg-4">

        <?php if (!empty($row['scan_image_url'])): ?>
        <?php $isImg = !str_ends_with(strtolower($row['scan_image_url']), '.pdf'); ?>
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold small">
                <i class="bi bi-<?= $isImg ? 'camera' : 'file-pdf text-danger' ?> me-1"></i>
                Tarama <?= $isImg ? 'Fotoğrafı' : 'Belgesi' ?>
            </div>
            <div class="card-body p-2 text-center">
                <?php if ($isImg): ?>
                    <a href="<?= h($row['scan_image_url']) ?>" target="_blank">
                        <img src="<?= h($row['scan_image_url']) ?>" class="img-fluid rounded" style="max-height:200px;object-fit:contain;" alt="Tarama">
                    </a>
                <?php else: ?>
                    <a href="<?= h($row['scan_image_url']) ?>" target="_blank" class="btn btn-outline-danger">
                        <i class="bi bi-file-pdf me-1"></i> PDF Belgeyi Görüntüle
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Konum -->
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-map text-primary me-1"></i> Konum</div>
            <div class="card-body">
                <div class="mb-2">
                    <div class="text-muted small">Parsel</div>
                    <div class="fw-semibold"><?= h($row['parsel_adi'] ?: '-') ?></div>
                </div>
                <div class="mb-2">
                    <div class="text-muted small">Blok</div>
                    <div class="fw-semibold"><?= h($row['blok_adi'] ?: '-') ?></div>
                </div>
                <div>
                    <div class="text-muted small">Kot</div>
                    <div class="fw-semibold"><?= h($row['kot_degeri'] ?: '-') ?></div>
                </div>
            </div>
        </div>

        <!-- İş Kalemi -->
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-task text-primary me-1"></i> İş Kalemi</div>
            <div class="card-body">
                <div class="mb-2">
                    <div class="text-muted small">Firma</div>
                    <div class="fw-semibold"><?= h($row['firma_adi'] ?: '-') ?></div>
                </div>
                <div class="mb-2">
                    <div class="text-muted small">İmalat Grubu</div>
                    <div class="fw-semibold"><?= h($row['imalat_grup_adi'] ?: '-') ?></div>
                </div>
                <div>
                    <div class="text-muted small">Ana İş Kalemi</div>
                    <div class="fw-semibold"><?= h($row['ana_is_kalemi_adi'] ?: '-') ?></div>
                </div>
            </div>
        </div>

        <!-- Meta -->
        <div class="card mb-3">
            <div class="card-body small text-muted">
                <div><i class="bi bi-person me-1"></i>Oluşturan: <?= h($row['created_by_user'] ?: '-') ?></div>
                <div><i class="bi bi-clock me-1"></i>Oluşturma: <?= format_date($row['created_at']) ?></div>
                <?php if ($row['updated_by_user']): ?>
                <div><i class="bi bi-pencil me-1"></i>Düzenleyen: <?= h($row['updated_by_user']) ?></div>
                <div><i class="bi bi-clock-history me-1"></i>Güncelleme: <?= format_date($row['updated_at']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($bagliFatura): ?>
        <!-- Bağlı fatura -->
        <div class="card mb-3 border-success">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-receipt-cutoff text-success me-1"></i> Bağlı Fatura</div>
            <div class="card-body small">
                <div class="fw-bold"><?= h($bagliFatura['fatura_no']) ?></div>
                <div class="text-muted"><?= h(format_date($bagliFatura['tarih'])) ?><?= $bagliFatura['tedarikci'] ? ' · '.h($bagliFatura['tedarikci']) : '' ?></div>
                <?php if ($bagliFatura['tutar'] !== null): ?>
                <div class="mt-1">Tutar: <strong><?= format_number($bagliFatura['tutar'], 2) ?> ₺</strong></div>
                <?php endif; ?>
                <div class="mt-2 d-flex gap-1 flex-wrap">
                    <?php if ($bagliFatura['dosya_url']): ?>
                    <a href="<?= h($bagliFatura['dosya_url']) ?>" target="_blank" class="btn btn-sm btn-outline-success py-0"><i class="bi bi-file-earmark-pdf me-1"></i>Faturayı Aç</a>
                    <?php endif; ?>
                    <a href="irsaliyeler.php?tip=tum&fatura_id=<?= (int)$bagliFatura['id'] ?>" class="btn btn-sm btn-outline-secondary py-0">Aynı Faturadakiler</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Belgeler -->
        <div class="card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-images text-primary me-1"></i> Belgeler & Fotoğraflar</span>
                <span class="badge bg-secondary"><?= count($fotolar) ?></span>
            </div>
            <div class="card-body">
                <?php if (can_edit()): ?>
                <form method="post" enctype="multipart/form-data" class="mb-3">
                    <label class="form-label small">Belge Türü</label>
                    <select name="tur" id="blgTur" class="form-select form-select-sm mb-2" onchange="blgTurDegis()">
                        <?php foreach (BLG_TURLER as $k => $bt): ?>
                        <option value="<?= h($k) ?>"><?= h($bt['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="file" name="foto[]" class="form-control form-control-sm" multiple accept="image/*,application/pdf" required>
                    <div class="form-text">JPG, PNG, WebP, PDF — maks 10 MB</div>
                    <div id="blgOkuKutu" class="mt-2 d-none">
                        <div class="form-check form-check-sm">
                            <input class="form-check-input" type="checkbox" name="oku" value="1" id="blgOku" checked>
                            <label class="form-check-label small" for="blgOku">Belgeyi <strong>AI ile oku</strong> (fiş/fatura alanlarını çıkarsın)</label>
                        </div>
                        <div class="form-check form-check-sm" id="blgUygulaKutu">
                            <input class="form-check-input" type="checkbox" name="kantar_uygula" value="1" id="blgUygula" checked>
                            <label class="form-check-label small" for="blgUygula">Okunan kantar değerlerini <strong>boş alanlara yaz</strong></label>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-primary mt-2"><i class="bi bi-upload me-1"></i>Yükle</button>
                </form>
                <script>
                function blgTurDegis(){
                    var t = document.getElementById('blgTur').value;
                    document.getElementById('blgOkuKutu').classList.toggle('d-none', !['kantar','fatura','irsaliye'].includes(t));
                    document.getElementById('blgUygulaKutu').classList.toggle('d-none', t !== 'kantar');
                }
                blgTurDegis();
                </script>
                <?php endif; ?>

                <?php if ($fotolar): ?>
                <div class="row g-2">
                    <?php foreach ($fotolar as $f): ?>
                    <div class="col-6">
                        <div class="position-relative">
                            <?php
                            $ext = strtolower(pathinfo($f['dosya_yolu'], PATHINFO_EXTENSION));
                            $imgExts = ['jpg','jpeg','png','webp','gif'];
                            $fTur = $f['tur'] ?? 'foto';
                            $fOku = !empty($f['okunan']) ? json_decode((string)$f['okunan'], true) : null;
                            ?>
                            <?php if ($fTur !== 'foto'): ?>
                            <span class="badge bg-dark position-absolute top-0 start-0 m-1" style="z-index:2">
                                <i class="bi <?= h(blg_tur_ikon($fTur)) ?> me-1"></i><?= h(blg_tur_ad($fTur)) ?>
                            </span>
                            <?php endif; ?>
                            <?php if (in_array($ext, $imgExts)): ?>
                                <a href="<?= h($f['dosya_yolu']) ?>" target="_blank">
                                    <img src="<?= h($f['dosya_yolu']) ?>" class="img-thumbnail w-100" style="height:80px;object-fit:cover" alt="Fotoğraf">
                                </a>
                            <?php else: ?>
                                <a href="<?= h($f['dosya_yolu']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary w-100">
                                    <i class="bi bi-file-pdf me-1"></i><?= h($f['dosya_adi']) ?>
                                </a>
                            <?php endif; ?>
                            <?php if (can_edit()): ?>
                            <a href="irsaliye_detay.php?id=<?= $id ?>&foto_sil=<?= $f['id'] ?>"
                               class="btn btn-xs btn-danger position-absolute top-0 end-0 m-1 btn-confirm"
                               data-msg="Bu dosyayı silmek istiyor musunuz?"
                               title="Sil">
                                <i class="bi bi-x"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php if (is_array($fOku)): ?>
                        <div class="border rounded p-2 mt-1 small bg-body-tertiary">
                            <?php
                            $satir = [];
                            if (!empty($fOku['fis_no']))      $satir[] = 'Fiş No: <strong>'.h($fOku['fis_no']).'</strong>';
                            if (!empty($fOku['irsaliye_no'])) $satir[] = 'İrsaliye: <strong>'.h($fOku['irsaliye_no']).'</strong>';
                            if (!empty($fOku['plaka']))       $satir[] = 'Plaka: <strong>'.h($fOku['plaka']).'</strong>';
                            if (isset($fOku['net_kg']) && $fOku['net_kg'] !== null) $satir[] = 'Net: <strong>'.format_number($fOku['net_kg'], 2).' kg</strong>';
                            if (!empty($fOku['giris_saati']) || !empty($fOku['cikis_saati']))
                                $satir[] = 'Saat: <strong>'.h($fOku['giris_saati'] ?: '?').' → '.h($fOku['cikis_saati'] ?: '?').'</strong>';
                            if (!empty($fOku['firma']))       $satir[] = 'Firma: '.h($fOku['firma']);
                            echo $satir ? implode('<br>', $satir) : '<span class="text-muted">Okunabilir alan bulunamadı.</span>';
                            $guven = (float)($fOku['guven'] ?? 0);
                            ?>
                            <?php if ($guven > 0 && $guven < 0.8): ?>
                            <div class="text-warning mt-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Düşük güven (<?= number_format($guven*100, 0) ?>%) — kontrol edin.</div>
                            <?php endif; ?>
                            <?php if (can_edit() && $fTur === 'kantar'): ?>
                            <form method="post" class="mt-1">
                                <input type="hidden" name="action" value="kantar_uygula">
                                <input type="hidden" name="belge_id" value="<?= (int)$f['id'] ?>">
                                <button class="btn btn-xs btn-outline-primary py-0"><i class="bi bi-arrow-down-square me-1"></i>İrsaliyeye yaz</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="text-muted small text-center py-3">Henüz dosya yüklenmemiş.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
