<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Katkı Listesi — Beton Takip Sistemi';

// ── Sil ──────────────────────────────────────────────────────────────────────
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $k1 = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE katki1_id = ?");
    $k1->execute([$id]);
    $k2 = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE katki2_id = ?");
    $k2->execute([$id]);
    if ($k1->fetchColumn() > 0 || $k2->fetchColumn() > 0) {
        flash('warning', 'Bu katkı irsaliyelerde kullanılıyor, silinemez.');
    } else {
        $pdo->prepare("DELETE FROM katki_listesi WHERE id = ?")->execute([$id]);
        flash('success', 'Katkı silindi.');
    }
    redirect('katki_listesi.php');
}

// ── Düzenle ───────────────────────────────────────────────────────────────────
$formError = '';
$duzenle   = null;

if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdo->prepare("SELECT * FROM katki_listesi WHERE id = ?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

// ── Kaydet / Güncelle ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $ad = trim($_POST['ad'] ?? '');
    if (!$ad) {
        $formError = 'Ad alanı zorunludur.';
    } else {
        // Aynı isim var mı kontrol et (büyük/küçük harf duyarsız)
        $dupSql = $id
            ? "SELECT COUNT(*) FROM katki_listesi WHERE UPPER(ad) = UPPER(?) AND id != ?"
            : "SELECT COUNT(*) FROM katki_listesi WHERE UPPER(ad) = UPPER(?)";
        $dupStmt = $pdo->prepare($dupSql);
        $dupStmt->execute($id ? [$ad, $id] : [$ad]);
        if ($dupStmt->fetchColumn() > 0) {
            $formError = '"' . h($ad) . '" adında bir katkı zaten mevcut. Lütfen farklı bir ad girin.';
        } else {
            if ($id) {
                $pdo->prepare("UPDATE katki_listesi SET ad = ? WHERE id = ?")->execute([$ad, $id]);
                flash('success', 'Katkı güncellendi.');
            } else {
                $pdo->prepare("INSERT INTO katki_listesi (ad) VALUES (?)")->execute([$ad]);
                flash('success', 'Katkı eklendi.');
            }
            redirect('katki_listesi.php');
        }
    }
}

$formAcik = isset($_GET['ekle']) || $duzenle;

// ── Liste ─────────────────────────────────────────────────────────────────────
$liste = $pdo->query("
    SELECT k.*,
           (SELECT COUNT(*) FROM irsaliyeler WHERE katki1_id = k.id OR katki2_id = k.id) AS irsaliye_sayisi
    FROM katki_listesi k ORDER BY k.ad
")->fetchAll();

// Mevcut duplikatları tespit et
$duplar = $pdo->query(
    "SELECT UPPER(ad) as ad_upper, COUNT(*) as adet FROM katki_listesi GROUP BY UPPER(ad) HAVING COUNT(*) > 1"
)->fetchAll(PDO::FETCH_COLUMN, 0);

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-flask text-primary me-2"></i>Katkı Listesi</h4>
    <a href="katki_listesi.php?ekle=1" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i> Yeni Katkı
    </a>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($duplar): ?>
<div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
    <div>
        <strong>Mükerrer kayıtlar tespit edildi!</strong>
        Listede aynı isimli birden fazla katkı var: <strong><?= implode(', ', array_map('h', $duplar)) ?></strong>.
        Lütfen fazlalıkları silin.
    </div>
</div>
<?php endif; ?>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle ? 'bg-warning text-dark' : 'bg-primary text-white' ?> fw-semibold">
        <i class="bi bi-<?= $duzenle ? 'pencil' : 'plus-circle' ?> me-1"></i>
        <?= $duzenle ? 'Katkı Düzenle' : 'Yeni Katkı Ekle' ?>
    </div>
    <div class="card-body">
        <?php if ($formError): ?>
            <div class="alert alert-danger"><?= h($formError) ?></div>
        <?php endif; ?>
        <form method="post">
            <?php if ($duzenle): ?>
                <input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>">
            <?php endif; ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Ad <span class="text-danger">*</span></label>
                    <input name="ad" class="form-control" required value="<?= h($duzenle['ad'] ?? '') ?>">
                </div>
                <div class="col-md-6 d-flex gap-2">
                    <button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle ? 'Güncelle' : 'Kaydet' ?></button>
                    <a href="katki_listesi.php" class="btn btn-secondary"><i class="bi bi-x-circle me-1"></i>İptal</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Ad</th>
                        <th class="text-center">İrsaliye</th>
                        <th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $r): ?>
                    <?php $isDup = in_array(strtoupper($r['ad']), $duplar); ?>
                    <tr <?= $isDup ? 'class="table-warning"' : '' ?>>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td class="fw-semibold">
                            <?= h($r['ad']) ?>
                            <?php if ($isDup): ?>
                                <span class="badge bg-warning text-dark ms-1"><i class="bi bi-exclamation-triangle me-1"></i>Mükerrer</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($r['irsaliye_sayisi'] > 0): ?>
                                <a href="#" class="badge bg-primary text-white text-decoration-none btn-tanim-modal"
                                   data-tip="katki" data-id="<?= $r['id'] ?>" data-ad="<?= h($r['ad']) ?>">
                                    <?= (int)$r['irsaliye_sayisi'] ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="katki_listesi.php?duzenle=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="katki_listesi.php?sil=<?= $r['id'] ?>"
                               class="btn btn-xs btn-outline-danger btn-confirm"
                               data-msg="Bu katkıyı silmek istediğinize emin misiniz?">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$liste): ?>
                        <tr><td colspan="3" class="text-center text-muted py-5">Henüz katkı yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- İrsaliye Listesi Modal -->
<div class="modal fade" id="irsaliyeModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    <span id="modalBaslik">İrsaliyeler</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="modalYukleniyor" class="text-center py-5">
                    <div class="spinner-border text-primary"></div>
                    <div class="mt-2 text-muted small">Yükleniyor…</div>
                </div>
                <div id="modalIcerik" class="d-none">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>İrsaliye No</th>
                                <th>Tarih</th>
                                <th>Proje</th>
                                <th>Beton Sınıfı</th>
                                <th>Araç Plaka</th>
                                <th class="text-end">Miktar (m³)</th>
                                <th class="text-center">Tür</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="modalTbody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <small class="text-muted me-auto" id="modalSayi"></small>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-tanim-modal').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const tip = this.dataset.tip;
        const id  = this.dataset.id;
        const ad  = this.dataset.ad;

        document.getElementById('modalBaslik').textContent = ad + ' — İrsaliyeler';
        document.getElementById('modalYukleniyor').classList.remove('d-none');
        document.getElementById('modalIcerik').classList.add('d-none');
        document.getElementById('modalSayi').textContent = '';

        new bootstrap.Modal(document.getElementById('irsaliyeModal')).show();

        fetch(`api/tanim_irsaliyeler.php?tip=${tip}&id=${id}`)
            .then(r => r.json())
            .then(data => {
                document.getElementById('modalYukleniyor').classList.add('d-none');
                if (!data.ok) {
                    document.getElementById('modalIcerik').innerHTML = `<div class="alert alert-danger m-3">${data.msg}</div>`;
                    document.getElementById('modalIcerik').classList.remove('d-none');
                    return;
                }
                const tbody = document.getElementById('modalTbody');
                tbody.innerHTML = '';
                if (data.liste.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Kayıt bulunamadı.</td></tr>';
                } else {
                    data.liste.forEach(r => {
                        const tipBadge = r.tip === 'alis'
                            ? '<span class="badge bg-success-subtle text-success">Alış</span>'
                            : '<span class="badge bg-danger-subtle text-danger">İade</span>';
                        tbody.innerHTML += `<tr>
                            <td class="font-monospace small">${r.irsaliye_no ?? '—'}</td>
                            <td class="small">${r.tarih ? r.tarih.substring(0,10).split('-').reverse().join('.') : '—'}</td>
                            <td class="small">${r.proje_adi ?? '<span class="text-muted">—</span>'}</td>
                            <td class="small">${r.beton_sinifi_adi ?? '—'}</td>
                            <td class="small">${r.arac_plaka ?? '—'}</td>
                            <td class="text-end small">${r.miktar ? parseFloat(r.miktar).toLocaleString('tr-TR', {minimumFractionDigits:2}) : '—'}</td>
                            <td class="text-center">${tipBadge}</td>
                            <td><a href="irsaliye_goruntule.php?id=${r.id}" target="_blank" class="btn btn-xs btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                        </tr>`;
                    });
                }
                document.getElementById('modalIcerik').classList.remove('d-none');
                document.getElementById('modalSayi').textContent = `Toplam ${data.sayi} irsaliye`;
            })
            .catch(() => {
                document.getElementById('modalYukleniyor').classList.add('d-none');
                document.getElementById('modalIcerik').innerHTML = '<div class="alert alert-danger m-3">Bağlantı hatası.</div>';
                document.getElementById('modalIcerik').classList.remove('d-none');
            });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
