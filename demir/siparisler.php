<?php
/**
 * demir/siparisler.php — Demir siparişleri + çap bazında bakiye
 * Gelen miktar, sevkiyatların IFS Sipariş No'su ile eşleştirilerek hesaplanır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Demir Siparişleri — Demir Takip';

// sozlesme_id kolonu garanti (siparis_form açılmadan da liste çalışsın)
try {
    if (!$pdoDemir->query("SHOW COLUMNS FROM demir_siparisler LIKE 'sozlesme_id'")->fetchColumn()) {
        $pdoDemir->exec("ALTER TABLE demir_siparisler ADD COLUMN sozlesme_id INT NULL AFTER proje_id");
    }
    $pdoDemir->exec("CREATE TABLE IF NOT EXISTS demir_sozlesmeler (
        id INT AUTO_INCREMENT PRIMARY KEY, sozlesme_no VARCHAR(60) NOT NULL, taseron_id INT NOT NULL,
        proje_id INT NULL, tarih DATE NULL, konu VARCHAR(300) NULL, aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY (taseron_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

if (has_role('admin','teknik_ofis_admin') && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $pdoDemir->prepare("DELETE FROM demir_siparisler WHERE id=?")->execute([(int)$_GET['sil']]);
    flash('success', 'Sipariş silindi.');
    redirect('siparisler.php');
}

$liste = $pdoDemir->query("
    SELECT sp.*, ta.ad AS taseron_adi, p.kod AS proje_kod, sz.sozlesme_no,
           COALESCE(o.top_sip,0) AS top_sip,
           COALESCE(g.top_gel,0) AS top_gel
    FROM demir_siparisler sp
    LEFT JOIN demir_taseronlar ta ON ta.id = sp.taseron_id
    LEFT JOIN demir_projeler p    ON p.id = sp.proje_id
    LEFT JOIN demir_sozlesmeler sz ON sz.id = sp.sozlesme_id
    LEFT JOIN (SELECT siparis_id, SUM(miktar_ton) AS top_sip FROM demir_siparis_kalemleri GROUP BY siparis_id) o
           ON o.siparis_id = sp.id
    LEFT JOIN (
        SELECT s.ifs_siparis_no, SUM(sk.irsaliye_miktar) AS top_gel
        FROM demir_sevkiyatlar s JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id = s.id
        WHERE s.ifs_siparis_no IS NOT NULL AND s.ifs_siparis_no <> ''
        GROUP BY s.ifs_siparis_no
    ) g ON g.ifs_siparis_no = sp.ifs_siparis_no
    ORDER BY sp.tarih DESC, sp.id DESC
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-cart-check text-dark me-2"></i>Demir Siparişleri</h4>
    <div class="d-flex gap-2">
        <?php if (has_role('admin','teknik_ofis_admin')): ?>
        <a href="import_siparis.php" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i> Sipariş İçe Aktar</a>
        <?php endif; ?>
        <?php if (has_role('admin','teknik_ofis_admin','teknik_ofis')): ?>
        <a href="siparis_form.php" class="btn btn-dark"><i class="bi bi-plus-circle me-1"></i> Yeni Sipariş</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>IFS Sipariş</th><th>Sözleşme No</th><th>Tarih</th><th>Proje</th><th>Taşeron</th><th>İmalat</th>
                        <th class="text-end">Sipariş (t)</th><th class="text-end">Gelen (t)</th><th class="text-end">Kalan (t)</th>
                        <th class="text-center">Durum</th><th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $r):
                        $sip=(float)$r['top_sip']; $gel=(float)$r['top_gel']; $kalan=$sip-$gel;
                        $yuzde = $sip>0 ? min(100, round($gel/$sip*100)) : 0;
                    ?>
                    <tr>
                        <td class="fw-semibold font-monospace"><a href="siparis_detay.php?id=<?= $r['id'] ?>" class="text-decoration-none"><?= h($r['ifs_siparis_no']) ?></a></td>
                        <td class="font-monospace small"><?= $r['sozlesme_no'] ? '<a href="sozlesmeler.php" class="text-decoration-none">'.h($r['sozlesme_no']).'</a>' : '<span class="text-muted">—</span>' ?></td>
                        <td><?= format_date($r['tarih']) ?></td>
                        <td><?= $r['proje_kod'] ? '<span class="badge bg-secondary">'.h($r['proje_kod']).'</span>' : '—' ?></td>
                        <td><?= h($r['taseron_adi'] ?: '—') ?></td>
                        <td class="small"><?= h($r['imalat'] ?: '—') ?></td>
                        <td class="text-end"><?= $fmt($sip) ?></td>
                        <td class="text-end"><?= $fmt($gel) ?></td>
                        <td class="text-end fw-semibold <?= $kalan<=0.0005?'text-success':'text-danger' ?>"><?= $kalan<=0.0005?'0 ✓':$fmt($kalan) ?></td>
                        <td class="text-center" style="min-width:110px">
                            <div class="progress" style="height:6px"><div class="progress-bar bg-<?= $yuzde>=100?'success':'primary' ?>" style="width:<?= $yuzde ?>%"></div></div>
                            <small class="text-muted">%<?= $yuzde ?></small>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="siparis_detay.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-secondary me-1" title="Detay"><i class="bi bi-eye"></i></a>
                            <?php if (has_role('admin','teknik_ofis_admin','teknik_ofis')): ?>
                            <a href="siparis_form.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (has_role('admin','teknik_ofis_admin')): ?>
                            <a href="siparisler.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Bu siparişi silmek istiyor musunuz?"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$liste): ?>
                    <tr><td colspan="11" class="text-center text-muted py-5">
                        <i class="bi bi-cart-x fs-1 d-block mb-2 opacity-50"></i>
                        Henüz sipariş yok. <a href="siparis_form.php">İlk siparişi ekleyin</a>.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
