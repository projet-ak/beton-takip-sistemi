<?php
/**
 * demir/tutanak_detay.php — Tutanak görüntüleme + imzalı evrak yükleme
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { redirect('tutanaklar.php'); }

// Tutanak
try {
    if (!$pdoDemir->query("SHOW COLUMNS FROM demir_tutanaklar LIKE 'sozlesme_id'")->fetchColumn()) {
        $pdoDemir->exec("ALTER TABLE demir_tutanaklar ADD COLUMN sozlesme_id INT NULL AFTER proje_id");
    }
    $pdoDemir->exec("CREATE TABLE IF NOT EXISTS demir_sozlesmeler (
        id INT AUTO_INCREMENT PRIMARY KEY, sozlesme_no VARCHAR(60) NOT NULL, taseron_id INT NOT NULL,
        proje_id INT NULL, tarih DATE NULL, konu VARCHAR(300) NULL, aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY (taseron_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}
$s = $pdoDemir->prepare("
    SELECT tu.*, ta.ad AS taseron_adi, ta.kod AS taseron_kod, p.kod AS proje_kod, p.aciklama AS proje_ad,
           (SELECT sz.sozlesme_no FROM demir_sozlesmeler sz WHERE sz.id = tu.sozlesme_id) AS sozlesme_no
    FROM demir_tutanaklar tu
    LEFT JOIN demir_taseronlar ta ON ta.id = tu.taseron_id
    LEFT JOIN demir_projeler p ON p.id = tu.proje_id
    WHERE tu.id=?");
$s->execute([$id]);
$tu = $s->fetch();
if (!$tu) { flash('error','Tutanak bulunamadı.'); redirect('tutanaklar.php'); }

$canEdit = has_role('admin','teknik_ofis_admin','teknik_ofis','saha_sefi');

// ── İmzalı evrak yükleme ──────────────────────────────────────────────────────
if ($canEdit && $_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['evrak']) && $_FILES['evrak']['error']===UPLOAD_ERR_OK) {
    $mime = guess_mime($_FILES['evrak']['tmp_name'], $_FILES['evrak']['name']);
    $izin = ['application/pdf','image/jpeg','image/png','image/webp'];
    if (!in_array($mime, $izin, true)) {
        flash('error', 'Sadece PDF, JPG, PNG, WebP yüklenebilir.');
    } elseif ($_FILES['evrak']['size'] > 15*1024*1024) {
        flash('error', 'Dosya çok büyük (maks 15 MB).');
    } else {
        $dir = __DIR__ . '/../uploads/demir_tutanak/' . $id . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ext = pathinfo($_FILES['evrak']['name'], PATHINFO_EXTENSION) ?: ($mime==='application/pdf'?'pdf':'jpg');
        $ad  = 'imzali_' . date('Ymd_His') . '.' . strtolower($ext);
        if (move_uploaded_file($_FILES['evrak']['tmp_name'], $dir.$ad)) {
            $url = 'uploads/demir_tutanak/' . $id . '/' . $ad;
            $pdoDemir->prepare("UPDATE demir_tutanaklar SET evrak_url=? WHERE id=?")->execute([$url, $id]);
            flash('success', 'İmzalı evrak yüklendi.');
        } else {
            flash('error', 'Dosya yüklenemedi.');
        }
    }
    redirect('tutanak_detay.php?id='.$id);
}
// Evrak sil
if ($canEdit && isset($_GET['evrak_sil'])) {
    if ($tu['evrak_url']) @unlink(__DIR__ . '/../' . $tu['evrak_url']);
    $pdoDemir->prepare("UPDATE demir_tutanaklar SET evrak_url=NULL WHERE id=?")->execute([$id]);
    flash('success', 'Evrak kaldırıldı.');
    redirect('tutanak_detay.php?id='.$id);
}

$kalemler = $pdoDemir->prepare("
    SELECT tk.*, c.ad AS cap_ad FROM demir_tutanak_kalemleri tk
    LEFT JOIN demir_caplar c ON c.id = tk.cap_id WHERE tk.tutanak_id=? ORDER BY tk.id");
$kalemler->execute([$id]);
$kalemler = $kalemler->fetchAll();
$topTon = array_sum(array_column($kalemler,'miktar_ton'));
$topBag = array_sum(array_column($kalemler,'bag_adeti'));

$pageTitle = 'Tutanak ' . $tu['tutanak_no'] . ' — Demir Takip';
require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
$imgExt = $tu['evrak_url'] ? !str_ends_with(strtolower($tu['evrak_url']), '.pdf') : false;
?>
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="tutanaklar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-file-earmark-check text-dark me-2"></i>Tutanak <span class="font-monospace"><?= h($tu['tutanak_no']) ?></span></h4>
    <div class="ms-auto d-flex gap-2">
        <a href="tutanak_pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-dark btn-sm"><i class="bi bi-printer me-1"></i>Yazdır / PDF</a>
        <?php if ($canEdit): ?><a href="tutanak_form.php?id=<?= $id ?>" class="btn btn-outline-warning btn-sm"><i class="bi bi-pencil me-1"></i>Düzenle</a><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> Tutanak Bilgileri</div>
            <div class="card-body">
                <div class="row g-2 small">
                    <?php
                    $bilgi = [
                        'Tutanak No' => $tu['tutanak_no'],
                        'Sözleşme No' => $tu['sozlesme_no'] ?: '—',
                        'Tarih' => format_date($tu['tutanak_tarih']),
                        'Zimmet Yapılan Taşeron' => $tu['taseron_adi'] ?: '—',
                        'Proje' => ($tu['proje_kod'] ?? '—').($tu['proje_ad']?' — '.$tu['proje_ad']:''),
                        'Araç Plaka' => $tu['arac_plaka'] ?: '—',
                        'Dorse Plaka' => $tu['dorse_plaka'] ?: '—',
                    ];
                    foreach ($bilgi as $lbl=>$val): ?>
                    <div class="col-md-6"><div class="text-muted"><?= $lbl ?></div><div class="fw-semibold"><?= h($val) ?></div></div>
                    <?php endforeach; ?>
                    <?php if ($tu['sozlesme_kapsami']): ?><div class="col-12 mt-2"><div class="text-muted">Sözleşme Kapsamı</div><div><?= nl2br(h($tu['sozlesme_kapsami'])) ?></div></div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-primary me-1"></i> Teslim Edilen Malzeme (<?= count($kalemler) ?>)</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>#</th><th>İrsaliye No</th><th>Çap</th><th class="text-end">Tonaj (t)</th><th class="text-end">Bağ</th></tr></thead>
                    <tbody>
                    <?php foreach ($kalemler as $i=>$k): ?>
                        <tr><td class="text-muted"><?= $i+1 ?></td><td class="font-monospace"><?= h($k['irsaliye_no'] ?: '—') ?></td><td class="fw-semibold"><?= h($k['cap_ad'] ?: '—') ?></td><td class="text-end"><?= $fmt($k['miktar_ton']) ?></td><td class="text-end"><?= $k['bag_adeti']!==null?(int)$k['bag_adeti']:'—' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr><td colspan="3" class="text-end">TOPLAM</td><td class="text-end"><?= $fmt($topTon) ?></td><td class="text-end"><?= (int)$topBag ?></td></tr></tfoot>
                </table>
            </div></div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-arrow-up text-primary me-1"></i> İmzalı Evrak</div>
            <div class="card-body">
                <?php if ($tu['evrak_url']): ?>
                    <?php if ($imgExt): ?>
                        <a href="<?= h($rootPath.$tu['evrak_url']) ?>" target="_blank"><img src="<?= h($rootPath.$tu['evrak_url']) ?>" class="img-fluid rounded border mb-2" alt="İmzalı evrak"></a>
                    <?php else: ?>
                        <a href="<?= h($rootPath.$tu['evrak_url']) ?>" target="_blank" class="btn btn-outline-danger w-100 mb-2"><i class="bi bi-file-pdf me-1"></i>İmzalı PDF'i Aç</a>
                    <?php endif; ?>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Yüklü</span>
                        <?php if ($canEdit): ?><a href="tutanak_detay.php?id=<?= $id ?>&evrak_sil=1" class="btn btn-xs btn-outline-danger btn-confirm ms-auto" data-msg="İmzalı evrak kaldırılsın mı?">Kaldır</a><?php endif; ?>
                    </div>
                <?php elseif ($canEdit): ?>
                    <p class="text-muted small">Tutanağı yazdır, imzalat, sonra imzalı halini buraya yükle.</p>
                    <form method="post" enctype="multipart/form-data">
                        <input type="file" name="evrak" class="form-control form-control-sm mb-2" accept="application/pdf,image/*" required>
                        <div class="form-text mb-2">PDF, JPG, PNG — maks 15 MB</div>
                        <button class="btn btn-sm btn-primary w-100"><i class="bi bi-upload me-1"></i>İmzalı Evrağı Yükle</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted small mb-0">Henüz imzalı evrak yüklenmemiş.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
