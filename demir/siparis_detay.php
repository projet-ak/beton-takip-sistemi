<?php
/**
 * demir/siparis_detay.php — Sipariş detayı + çap bazında bakiye (sipariş/gelen/kalan)
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { redirect('siparisler.php'); }

$sozlesmeNo = null;
try {
    $q = $pdoDemir->prepare("SELECT sz.sozlesme_no FROM demir_siparisler sp JOIN demir_sozlesmeler sz ON sz.id = sp.sozlesme_id WHERE sp.id=?");
    $q->execute([$id]); $sozlesmeNo = $q->fetchColumn() ?: null;
} catch (Throwable $e) {}
$s = $pdoDemir->prepare("
    SELECT sp.*, ta.ad AS taseron_adi, p.kod AS proje_kod, p.aciklama AS proje_ad
    FROM demir_siparisler sp
    LEFT JOIN demir_taseronlar ta ON ta.id = sp.taseron_id
    LEFT JOIN demir_projeler p ON p.id = sp.proje_id
    WHERE sp.id=?");
$s->execute([$id]);
$sip = $s->fetch();
if (!$sip) { flash('error','Sipariş bulunamadı.'); redirect('siparisler.php'); }

$pageTitle = 'Sipariş ' . $sip['ifs_siparis_no'] . ' — Demir Takip';

// ── Çap bazında sipariş / gelen / kalan ───────────────────────────────────────
$bakiye = $pdoDemir->prepare("
    SELECT c.id, c.ad, c.tip,
           COALESCE(o.m,0)  AS sip,
           COALESCE(g.m,0)  AS gel
    FROM demir_caplar c
    LEFT JOIN (SELECT cap_id, SUM(miktar_ton) AS m FROM demir_siparis_kalemleri WHERE siparis_id=? GROUP BY cap_id) o
           ON o.cap_id = c.id
    LEFT JOIN (
        SELECT sk.cap_id, SUM(sk.irsaliye_miktar) AS m
        FROM demir_sevkiyat_kalemleri sk JOIN demir_sevkiyatlar s ON s.id = sk.sevkiyat_id
        WHERE s.ifs_siparis_no = ?
        GROUP BY sk.cap_id
    ) g ON g.cap_id = c.id
    WHERE o.m IS NOT NULL OR g.m IS NOT NULL
    ORDER BY c.sira, c.ad
");
$bakiye->execute([$id, $sip['ifs_siparis_no']]);
$bakiyeRows = $bakiye->fetchAll();

$topSip = array_sum(array_column($bakiyeRows,'sip'));
$topGel = array_sum(array_column($bakiyeRows,'gel'));
$topKalan = $topSip - $topGel;

// ── Bu siparişe ait sevkiyatlar ───────────────────────────────────────────────
$sevkler = $pdoDemir->prepare("
    SELECT s.id, s.irsaliye_no, s.irsaliye_tarih, t.ad AS tedarikci_adi,
           COALESCE(SUM(sk.irsaliye_miktar),0) AS top
    FROM demir_sevkiyatlar s
    LEFT JOIN demir_tedarikciler t ON t.id = s.tedarikci_id
    LEFT JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id = s.id
    WHERE s.ifs_siparis_no = ?
    GROUP BY s.id ORDER BY s.irsaliye_tarih DESC, s.id DESC
");
$sevkler->execute([$sip['ifs_siparis_no']]);
$sevkler = $sevkler->fetchAll();

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="siparisler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-cart-check text-dark me-2"></i>Sipariş <span class="font-monospace"><?= h($sip['ifs_siparis_no']) ?></span></h4>
    <?php if (has_role('admin','teknik_ofis_admin','teknik_ofis')): ?>
    <a href="siparis_form.php?id=<?= $id ?>" class="btn btn-outline-warning btn-sm ms-auto"><i class="bi bi-pencil me-1"></i>Düzenle</a>
    <?php endif; ?>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam Sipariş</div><div class="fs-4 fw-bold"><?= $fmt($topSip) ?> t</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Gelen</div><div class="fs-4 fw-bold text-primary"><?= $fmt($topGel) ?> t</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Kalan</div><div class="fs-4 fw-bold <?= $topKalan<=0.0005?'text-success':'text-danger' ?>"><?= $topKalan<=0.0005?'0 ✓':$fmt($topKalan) ?> t</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Gerçekleşme</div><div class="fs-4 fw-bold"><?= $topSip>0?min(100,round($topGel/$topSip*100)):0 ?>%</div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> Sipariş Bilgileri</div>
            <div class="card-body small">
                <?php
                $bilgi = [
                    'IFS Talep No' => $sip['ifs_talep_no'] ?: '—',
                    'Sözleşme No' => $sozlesmeNo ?: '—',
                    'Proje' => ($sip['proje_kod'] ?? '—') . ($sip['proje_ad'] ? ' — '.$sip['proje_ad'] : ''),
                    'Taşeron' => $sip['taseron_adi'] ?: '—',
                    'İmalat' => $sip['imalat'] ?: '—',
                    'Gönderen Firma' => $sip['gonderen_firma'] ?: '—',
                    'Siparişi Oluşturan' => $sip['siparisi_olusturan'] ?: '—',
                    'Tarih' => format_date($sip['tarih']),
                    'Durum' => ['acik'=>'Açık','kismi'=>'Kısmi','tamam'=>'Tamamlandı','iptal'=>'İptal'][$sip['durum']] ?? $sip['durum'],
                ];
                foreach ($bilgi as $lbl=>$val): ?>
                <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted"><?= $lbl ?></span><strong class="text-end"><?= h($val) ?></strong></div>
                <?php endforeach; ?>
                <?php if ($sip['aciklama']): ?><div class="pt-2 text-muted"><?= nl2br(h($sip['aciklama'])) ?></div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-rulers text-primary me-1"></i> Çap Bazında Bakiye</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Çap</th><th class="text-end">Sipariş (t)</th><th class="text-end">Gelen (t)</th><th class="text-end">Kalan (t)</th></tr></thead>
                    <tbody>
                    <?php foreach ($bakiyeRows as $r): $kalan=(float)$r['sip']-(float)$r['gel']; ?>
                        <tr>
                            <td class="fw-semibold"><?= h($r['ad']) ?></td>
                            <td class="text-end"><?= $fmt($r['sip']) ?></td>
                            <td class="text-end text-primary"><?= $fmt($r['gel']) ?></td>
                            <td class="text-end fw-semibold <?= $kalan<=0.0005?'text-success':($kalan<0?'text-warning':'text-danger') ?>">
                                <?= $kalan<=0.0005 && $kalan>=-0.0005 ? '0 ✓' : ($kalan<0 ? ('+'.$fmt(-$kalan).' fazla') : $fmt($kalan)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$bakiyeRows): ?><tr><td colspan="4" class="text-center text-muted py-4">Bu siparişte çap kalemi yok.</td></tr><?php endif; ?>
                    </tbody>
                    <?php if ($bakiyeRows): ?>
                    <tfoot class="table-light fw-bold"><tr><td>TOPLAM</td><td class="text-end"><?= $fmt($topSip) ?></td><td class="text-end"><?= $fmt($topGel) ?></td><td class="text-end <?= $topKalan<=0.0005?'text-success':'text-danger' ?>"><?= $fmt($topKalan) ?></td></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div></div>
        </div>

        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-truck text-primary me-1"></i> Bu Siparişe Gelen Sevkiyatlar (<?= count($sevkler) ?>)</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>İrsaliye No</th><th>Tarih</th><th>Tedarikçi</th><th class="text-end">Miktar (t)</th></tr></thead>
                    <tbody>
                    <?php foreach ($sevkler as $sv): ?>
                        <tr>
                            <td class="font-monospace"><a href="sevkiyat_form.php?id=<?= $sv['id'] ?>" class="text-decoration-none"><?= h($sv['irsaliye_no']) ?></a></td>
                            <td><?= format_date($sv['irsaliye_tarih']) ?></td>
                            <td><?= h($sv['tedarikci_adi'] ?: '—') ?></td>
                            <td class="text-end"><?= $fmt($sv['top']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sevkler): ?><tr><td colspan="4" class="text-center text-muted py-4">Bu IFS Sipariş No ile eşleşen sevkiyat yok.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
