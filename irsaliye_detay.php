<?php
/**
 * irsaliye_detay.php — İrsaliye detay görüntüleme + fotoğraf yükleme
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }

require_auth();
require_once __DIR__ . '/includes/db.php';

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

// Fotoğraf yükleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit() && isset($_FILES['foto'])) {
    $uploadDir = __DIR__ . '/uploads/irsaliye_' . $id . '/';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }

    $allowed = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];
    $maxSize = 10 * 1024 * 1024; // 10 MB

    foreach ($_FILES['foto']['tmp_name'] as $i => $tmpName) {
        if ($_FILES['foto']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $mime = mime_content_type($tmpName);
        if (!in_array($mime, $allowed)) continue;
        if ($_FILES['foto']['size'][$i] > $maxSize) continue;

        $ext       = pathinfo($_FILES['foto']['name'][$i], PATHINFO_EXTENSION);
        $safeName  = uniqid('foto_', true) . '.' . strtolower($ext);
        $fullPath  = $uploadDir . $safeName;
        $relPath   = 'uploads/irsaliye_' . $id . '/' . $safeName;

        if (move_uploaded_file($tmpName, $fullPath)) {
            $pdo->prepare("INSERT INTO irsaliye_fotolar (irsaliye_id, dosya_adi, dosya_yolu, created_by) VALUES (?,?,?,?)")
                ->execute([$id, $_FILES['foto']['name'][$i], $relPath, current_user_id()]);
        }
    }
    flash('success', 'Fotoğraf(lar) yüklendi.');
    redirect("irsaliye_detay.php?id={$id}");
}

// Foto silme
if (can_edit() && isset($_GET['foto_sil']) && ctype_digit($_GET['foto_sil'])) {
    $fid  = (int)$_GET['foto_sil'];
    $frow = $pdo->prepare("SELECT * FROM irsaliye_fotolar WHERE id=? AND irsaliye_id=?");
    $frow->execute([$fid, $id]);
    $frow = $frow->fetch();
    if ($frow) {
        @unlink(__DIR__ . '/' . $frow['dosya_yolu']);
        $pdo->prepare("DELETE FROM irsaliye_fotolar WHERE id=?")->execute([$fid]);
        flash('success', 'Fotoğraf silindi.');
    }
    redirect("irsaliye_detay.php?id={$id}");
}

// Fotoğraflar listesi
$fotolar = $pdo->prepare("SELECT * FROM irsaliye_fotolar WHERE irsaliye_id=? ORDER BY created_at");
$fotolar->execute([$id]);
$fotolar = $fotolar->fetchAll();

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

        <!-- Fotoğraflar -->
        <div class="card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-images text-primary me-1"></i> Fotoğraflar</span>
                <span class="badge bg-secondary"><?= count($fotolar) ?></span>
            </div>
            <div class="card-body">
                <?php if (can_edit()): ?>
                <form method="post" enctype="multipart/form-data" class="mb-3">
                    <label class="form-label small">Fotoğraf / Belge Yükle</label>
                    <input type="file" name="foto[]" class="form-control form-control-sm" multiple accept="image/*,application/pdf">
                    <div class="form-text">JPG, PNG, WebP, PDF — maks 10 MB</div>
                    <button class="btn btn-sm btn-primary mt-2"><i class="bi bi-upload me-1"></i>Yükle</button>
                </form>
                <?php endif; ?>

                <?php if ($fotolar): ?>
                <div class="row g-2">
                    <?php foreach ($fotolar as $f): ?>
                    <div class="col-6">
                        <div class="position-relative">
                            <?php
                            $ext = strtolower(pathinfo($f['dosya_yolu'], PATHINFO_EXTENSION));
                            $imgExts = ['jpg','jpeg','png','webp','gif'];
                            ?>
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
