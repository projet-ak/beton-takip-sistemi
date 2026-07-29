<?php
/**
 * demir/iade_detay.php — İade tutanağı görüntüleme + imzalı evrak yükleme
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/_iade_ortak.php';
iade_semasi_kur($pdoDemir);

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { redirect('iade_tutanaklar.php'); }

$s = $pdoDemir->prepare("
    SELECT iu.*, ie.ad AS iade_eden_adi, ta.ad AS teslim_alan_adi, p.kod AS proje_kod, p.aciklama AS proje_ad
    FROM demir_iade_tutanaklar iu
    LEFT JOIN demir_taseronlar ie ON ie.id = iu.iade_eden_id
    LEFT JOIN demir_taseronlar ta ON ta.id = iu.teslim_alan_id
    LEFT JOIN demir_projeler p ON p.id = iu.proje_id
    WHERE iu.id=?");
$s->execute([$id]);
$iu = $s->fetch();
if (!$iu) { flash('error','İade tutanağı bulunamadı.'); redirect('iade_tutanaklar.php'); }

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
        $dir = __DIR__ . '/../uploads/demir_iade/' . $id . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ext = pathinfo($_FILES['evrak']['name'], PATHINFO_EXTENSION) ?: ($mime==='application/pdf'?'pdf':'jpg');
        $ad  = 'imzali_' . date('Ymd_His') . '.' . strtolower($ext);
        if (move_uploaded_file($_FILES['evrak']['tmp_name'], $dir.$ad)) {
            $url = 'uploads/demir_iade/' . $id . '/' . $ad;
            $pdoDemir->prepare("UPDATE demir_iade_tutanaklar SET evrak_url=? WHERE id=?")->execute([$url, $id]);
            flash('success', 'İmzalı evrak yüklendi.');
        } else {
            flash('error', 'Dosya yüklenemedi.');
        }
    }
    redirect('iade_detay.php?id='.$id);
}
// Evrak sil
if ($canEdit && isset($_GET['evrak_sil'])) {
    if ($iu['evrak_url']) @unlink(__DIR__ . '/../' . $iu['evrak_url']);
    $pdoDemir->prepare("UPDATE demir_iade_tutanaklar SET evrak_url=NULL WHERE id=?")->execute([$id]);
    flash('success', 'Evrak kaldırıldı.');
    redirect('iade_detay.php?id='.$id);
}

$kalemler = $pdoDemir->prepare("
    SELECT ik.*, c.ad AS cap_ad FROM demir_iade_kalemleri ik
    LEFT JOIN demir_caplar c ON c.id = ik.cap_id WHERE ik.iade_id=? ORDER BY ik.id");
$kalemler->execute([$id]);
$kalemler = $kalemler->fetchAll();
$topTon = array_sum(array_column($kalemler,'miktar_ton'));
$topBag = array_sum(array_column($kalemler,'bag_adeti'));

$pageTitle = 'İade ' . $iu['iade_no'] . ' — Demir Takip';
require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
$imgExt = $iu['evrak_url'] ? !str_ends_with(strtolower($iu['evrak_url']), '.pdf') : false;
?>
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="iade_tutanaklar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-arrow-return-left text-dark me-2"></i>İade <span class="font-monospace"><?= h($iu['iade_no']) ?></span></h4>
    <div class="ms-auto d-flex gap-2">
        <a href="iade_pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-dark btn-sm"><i class="bi bi-printer me-1"></i>Yazdır / PDF</a>
        <?php if ($canEdit): ?><a href="iade_form.php?id=<?= $id ?>" class="btn btn-outline-warning btn-sm"><i class="bi bi-pencil me-1"></i>Düzenle</a><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> İade Bilgileri</div>
            <div class="card-body">
                <div class="row g-2 small">
                    <?php
                    $bilgi = [
                        'İade No' => $iu['iade_no'],
                        'Tarih' => format_date($iu['iade_tarih']),
                        'İade Eden Taşeron' => $iu['iade_eden_adi'] ?: '—',
                        'Teslim Alan' => $iu['teslim_alan_adi'] ?: 'Depo / Şirket',
                        'Proje' => ($iu['proje_kod'] ?? '—').($iu['proje_ad']?' — '.$iu['proje_ad']:''),
                        'Araç Plaka' => $iu['arac_plaka'] ?: '—',
                        'Dorse Plaka' => $iu['dorse_plaka'] ?: '—',
                    ];
                    foreach ($bilgi as $lbl=>$val): ?>
                    <div class="col-md-6"><div class="text-muted"><?= $lbl ?></div><div class="fw-semibold"><?= h($val) ?></div></div>
                    <?php endforeach; ?>
                    <?php if ($iu['aciklama']): ?><div class="col-12 mt-2"><div class="text-muted">Açıklama</div><div><?= nl2br(h($iu['aciklama'])) ?></div></div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-primary me-1"></i> İade Edilen Malzeme (<?= count($kalemler) ?>)</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Çap</th><th class="text-end">Tonaj (t)</th><th class="text-end">Bağ</th></tr></thead>
                    <tbody>
                    <?php foreach ($kalemler as $i=>$k): ?>
                        <tr><td class="text-muted"><?= $i+1 ?></td><td class="fw-semibold"><?= h($k['cap_ad'] ?: '—') ?></td><td class="text-end"><?= $fmt($k['miktar_ton']) ?></td><td class="text-end"><?= $k['bag_adeti']!==null?(int)$k['bag_adeti']:'—' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr><td colspan="2" class="text-end">TOPLAM</td><td class="text-end"><?= $fmt($topTon) ?></td><td class="text-end"><?= (int)$topBag ?></td></tr></tfoot>
                </table>
            </div></div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-arrow-up text-primary me-1"></i> İmzalı Evrak</div>
            <div class="card-body">
                <?php if ($iu['evrak_url']): ?>
                    <?php if ($imgExt): ?>
                        <a href="<?= h($rootPath.$iu['evrak_url']) ?>" target="_blank"><img src="<?= h($rootPath.$iu['evrak_url']) ?>" class="img-fluid rounded border mb-2" alt="İmzalı evrak"></a>
                    <?php else: ?>
                        <a href="<?= h($rootPath.$iu['evrak_url']) ?>" target="_blank" class="btn btn-outline-danger w-100 mb-2"><i class="bi bi-file-pdf me-1"></i>İmzalı PDF'i Aç</a>
                    <?php endif; ?>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Yüklü</span>
                        <?php if ($canEdit): ?><a href="iade_detay.php?id=<?= $id ?>&evrak_sil=1" class="btn btn-xs btn-outline-danger btn-confirm ms-auto" data-msg="İmzalı evrak kaldırılsın mı?">Kaldır</a><?php endif; ?>
                    </div>
                <?php elseif ($canEdit): ?>
                    <p class="text-muted small">İade tutanağını yazdır, imzalat, sonra imzalı halini buraya yükle.</p>
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
