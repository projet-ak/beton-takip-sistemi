<?php
/**
 * demir/proje_detay.php — Proje detayı: kullanılan demir (çap bazında) + siparişler + sevkiyatlar
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { redirect('projeler.php'); }

$s = $pdoDemir->prepare("SELECT * FROM demir_projeler WHERE id=?"); $s->execute([$id]);
$prj = $s->fetch();
if (!$prj) { flash('error','Proje bulunamadı.'); redirect('projeler.php'); }
$pageTitle = 'Proje ' . $prj['kod'] . ' — Demir Takip';

// ── Çap bazında gelen demir ───────────────────────────────────────────────────
$cap = $pdoDemir->prepare("
    SELECT c.ad, c.tip, COALESCE(SUM(sk.irsaliye_miktar),0) irs, COALESCE(SUM(sk.kantar_miktar),0) knt
    FROM demir_caplar c
    JOIN demir_sevkiyat_kalemleri sk ON sk.cap_id=c.id
    JOIN demir_sevkiyatlar s ON s.id=sk.sevkiyat_id
    WHERE s.proje_id=? GROUP BY c.id HAVING irs>0 OR knt>0 ORDER BY c.sira, c.ad");
$cap->execute([$id]);
$capRows = $cap->fetchAll();

// ── Siparişler (bakiye ile) ───────────────────────────────────────────────────
$sip = $pdoDemir->prepare("
    SELECT sp.*, ta.ad taseron_adi,
        COALESCE(o.top,0) top_sip,
        COALESCE(g.top,0) top_gel
    FROM demir_siparisler sp
    LEFT JOIN demir_taseronlar ta ON ta.id=sp.taseron_id
    LEFT JOIN (SELECT siparis_id, SUM(miktar_ton) top FROM demir_siparis_kalemleri GROUP BY siparis_id) o ON o.siparis_id=sp.id
    LEFT JOIN (SELECT s.ifs_siparis_no, SUM(sk.irsaliye_miktar) top FROM demir_sevkiyatlar s JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id=s.id WHERE s.ifs_siparis_no<>'' GROUP BY s.ifs_siparis_no) g ON g.ifs_siparis_no=sp.ifs_siparis_no
    WHERE sp.proje_id=? ORDER BY sp.tarih DESC, sp.id DESC");
$sip->execute([$id]);
$sipRows = $sip->fetchAll();

// ── Sevkiyatlar ───────────────────────────────────────────────────────────────
$sevk = $pdoDemir->prepare("
    SELECT s.id, s.irsaliye_no, s.irsaliye_tarih, t.ad tedarikci, ta.ad taseron,
        COALESCE(SUM(sk.irsaliye_miktar),0) ton
    FROM demir_sevkiyatlar s
    LEFT JOIN demir_tedarikciler t ON t.id=s.tedarikci_id
    LEFT JOIN demir_taseronlar ta ON ta.id=s.taseron_id
    LEFT JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id=s.id
    WHERE s.proje_id=? GROUP BY s.id ORDER BY s.irsaliye_tarih DESC, s.id DESC");
$sevk->execute([$id]);
$sevkRows = $sevk->fetchAll();

$totGelen = array_sum(array_column($capRows,'irs'));
$totSipMik = 0; foreach ($sipRows as $r) $totSipMik += (float)$r['top_sip'];

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="projeler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-diagram-3 text-dark me-2"></i><span class="badge bg-dark me-1"><?= h($prj['kod']) ?></span> <?= h($prj['aciklama']) ?></h4>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam Gelen Demir</div><div class="fs-4 fw-bold"><?= $fmt($totGelen) ?> t</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Sipariş Sayısı</div><div class="fs-4 fw-bold"><?= count($sipRows) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam Sipariş</div><div class="fs-4 fw-bold"><?= $fmt($totSipMik) ?> t</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Sevkiyat Sayısı</div><div class="fs-4 fw-bold"><?= count($sevkRows) ?></div></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-rulers text-primary me-1"></i> Çap Bazında Gelen Demir</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Çap</th><th class="text-end">İrsaliye (t)</th><th class="text-end">Kantar (t)</th></tr></thead>
                    <tbody>
                    <?php foreach($capRows as $r): ?>
                        <tr><td class="fw-semibold"><?= h($r['ad']) ?></td><td class="text-end"><?= $fmt($r['irs']) ?></td><td class="text-end"><?= $fmt($r['knt']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if(!$capRows): ?><tr><td colspan="3" class="text-center text-muted py-4">Bu projeye demir gelmemiş.</td></tr><?php endif; ?>
                    </tbody>
                    <?php if($capRows): ?><tfoot class="table-light fw-bold"><tr><td>TOPLAM</td><td class="text-end"><?= $fmt($totGelen) ?></td><td class="text-end"><?= $fmt(array_sum(array_column($capRows,'knt'))) ?></td></tr></tfoot><?php endif; ?>
                </table>
            </div></div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-cart-check text-primary me-1"></i> Siparişler & Bakiye (<?= count($sipRows) ?>)</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>IFS Sipariş</th><th>Taşeron</th><th class="text-end">Sipariş</th><th class="text-end">Gelen</th><th class="text-end">Kalan</th></tr></thead>
                    <tbody>
                    <?php foreach($sipRows as $r): $sp=(float)$r['top_sip']; $gl=(float)$r['top_gel']; $kl=$sp-$gl; ?>
                        <tr>
                            <td class="font-monospace"><a href="siparis_detay.php?id=<?= $r['id'] ?>" class="text-decoration-none"><?= h($r['ifs_siparis_no']) ?></a></td>
                            <td class="small"><?= h($r['taseron_adi'] ?: '—') ?></td>
                            <td class="text-end"><?= $fmt($sp) ?></td>
                            <td class="text-end"><?= $fmt($gl) ?></td>
                            <td class="text-end fw-semibold <?= $kl<=0.0005?'text-success':'text-danger' ?>"><?= $kl<=0.0005?'0 ✓':$fmt($kl) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if(!$sipRows): ?><tr><td colspan="5" class="text-center text-muted py-4">Bu projeye ait sipariş yok.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-truck text-primary me-1"></i> Sevkiyatlar (<?= count($sevkRows) ?>)</div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:420px">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>İrsaliye No</th><th>Tarih</th><th>Tedarikçi</th><th>Taşeron</th><th class="text-end">Miktar (t)</th></tr></thead>
            <tbody>
            <?php foreach($sevkRows as $r): ?>
                <tr>
                    <td class="font-monospace"><a href="sevkiyat_form.php?id=<?= $r['id'] ?>" class="text-decoration-none"><?= h($r['irsaliye_no']) ?></a></td>
                    <td><?= format_date($r['irsaliye_tarih']) ?></td>
                    <td><?= h($r['tedarikci'] ?: '—') ?></td>
                    <td><?= h($r['taseron'] ?: '—') ?></td>
                    <td class="text-end"><?= $fmt($r['ton']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if(!$sevkRows): ?><tr><td colspan="5" class="text-center text-muted py-4">Sevkiyat yok.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
