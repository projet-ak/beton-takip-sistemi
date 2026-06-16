<?php
/**
 * tedarikciler.php — Tedarikçi yönetimi
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }

require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Tedarikçiler — Beton Takip Sistemi';

// ── Sil ──────────────────────────────────────────────────────────────────────
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $kullanim = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE tedarikci_id = ?");
    $kullanim->execute([$id]);
    if ($kullanim->fetchColumn() > 0) {
        flash('warning', 'Bu tedarikçiye ait irsaliyeler var, silemezsiniz. Pasif yapabilirsiniz.');
    } else {
        $pdo->prepare("DELETE FROM tedarikciler WHERE id = ?")->execute([$id]);
        flash('success', 'Tedarikçi silindi.');
    }
    redirect('tedarikciler.php');
}

// ── Pasif/Aktif Toggle ───────────────────────────────────────────────────────
if (isset($_GET['toggle']) && ctype_digit($_GET['toggle'])) {
    $pdo->prepare("UPDATE tedarikciler SET aktif = 1 - aktif WHERE id = ?")->execute([(int)$_GET['toggle']]);
    redirect('tedarikciler.php');
}

// ── Kaydet / Güncelle ────────────────────────────────────────────────────────
$formError = '';
$duzenle   = null;

if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdo->prepare("SELECT * FROM tedarikciler WHERE id = ?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id      = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $ad      = trim($_POST['ad']      ?? '');
    $vkn     = trim($_POST['vkn']     ?? '');
    $telefon = trim($_POST['telefon'] ?? '');
    $adres   = trim($_POST['adres']   ?? '');
    $aktif   = isset($_POST['aktif']) ? 1 : 0;

    // VKN: sadece rakam, 10-11 hane
    if ($vkn !== '' && !preg_match('/^\d{10,11}$/', $vkn)) {
        $formError = 'VKN 10 veya 11 haneli rakamlardan oluşmalıdır.';
    } elseif (!$ad) {
        $formError = 'Tedarikçi adı zorunludur.';
    } else {
        $dupSql  = $id
            ? "SELECT COUNT(*) FROM tedarikciler WHERE UPPER(ad) = UPPER(?) AND id != ?"
            : "SELECT COUNT(*) FROM tedarikciler WHERE UPPER(ad) = UPPER(?)";
        $dupStmt = $pdo->prepare($dupSql);
        $dupStmt->execute($id ? [$ad, $id] : [$ad]);
        if ($dupStmt->fetchColumn() > 0) {
            $formError = '"' . h($ad) . '" adında bir tedarikçi zaten mevcut. Lütfen farklı bir ad girin.';
        } else {
            if ($id) {
                $pdo->prepare("UPDATE tedarikciler SET ad=?, vkn=?, telefon=?, adres=?, aktif=? WHERE id=?")
                    ->execute([$ad, $vkn ?: null, $telefon ?: null, $adres ?: null, $aktif, $id]);
                flash('success', 'Tedarikçi güncellendi.');
            } else {
                $pdo->prepare("INSERT INTO tedarikciler (ad, vkn, telefon, adres, aktif) VALUES (?,?,?,?,?)")
                    ->execute([$ad, $vkn ?: null, $telefon ?: null, $adres ?: null, $aktif]);
                flash('success', 'Tedarikçi eklendi.');
            }
            redirect('tedarikciler.php');
        }
    }
}

$formAcik = isset($_GET['ekle']) || $duzenle;

// ── Mükerrer kontrolü ─────────────────────────────────────────────────────────
$duplar = $pdo->query(
    "SELECT UPPER(ad) FROM tedarikciler GROUP BY UPPER(ad) HAVING COUNT(*) > 1"
)->fetchAll(PDO::FETCH_COLUMN, 0);

// ── Liste ────────────────────────────────────────────────────────────────────
$liste = $pdo->query("
    SELECT t.*, COUNT(i.id) AS irsaliye_adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM tedarikciler t
    LEFT JOIN irsaliyeler i ON i.tedarikci_id = t.id
    GROUP BY t.id
    ORDER BY t.aktif DESC, t.ad
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck text-primary me-2"></i>Tedarikçiler</h4>
    <a href="tedarikciler.php?ekle=1" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i> Yeni Tedarikçi
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
        Listede aynı isimli birden fazla tedarikçi var: <strong><?= implode(', ', array_map('h', $duplar)) ?></strong>.
        Lütfen fazlalıkları silin.
    </div>
</div>
<?php endif; ?>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle ? 'bg-warning text-dark' : 'bg-primary text-white' ?> fw-semibold">
        <i class="bi bi-<?= $duzenle ? 'pencil' : 'plus-circle' ?> me-1"></i>
        <?= $duzenle ? 'Tedarikçi Düzenle' : 'Yeni Tedarikçi Ekle' ?>
    </div>
    <div class="card-body">
        <?php if ($formError): ?>
            <div class="alert alert-danger"><?= h($formError) ?></div>
        <?php endif; ?>
        <form method="post">
            <?php if ($duzenle): ?>
                <input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Ad <span class="text-danger">*</span></label>
                    <input name="ad" class="form-control" required value="<?= h($duzenle['ad'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">VKN</label>
                    <input name="vkn" class="form-control" pattern="\d{10,11}" maxlength="11"
                           placeholder="10-11 hane"
                           title="QR kod eşleştirmesi için tedarikçinin Vergi Kimlik No (10-11 hane)"
                           value="<?= h($duzenle['vkn'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Telefon</label>
                    <input name="telefon" class="form-control" value="<?= h($duzenle['telefon'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Adres</label>
                    <input name="adres" class="form-control" value="<?= h($duzenle['adres'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="aktif" id="chkAktif" value="1"
                               <?= (!$duzenle || $duzenle['aktif']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="chkAktif">Aktif</label>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle ? 'Güncelle' : 'Kaydet' ?></button>
                <a href="tedarikciler.php" class="btn btn-secondary"><i class="bi bi-x-circle me-1"></i>İptal</a>
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
                        <th>Ad</th>
                        <th>VKN</th>
                        <th>Telefon</th>
                        <th>Adres</th>
                        <th class="text-center">İrsaliye</th>
                        <th class="text-end">Toplam m³</th>
                        <th class="text-center">Durum</th>
                        <th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $r): ?>
                    <?php $isDup = in_array(strtoupper($r['ad']), $duplar); ?>
                    <tr class="<?= $isDup ? 'table-warning' : ($r['aktif'] ? '' : 'text-muted bg-light') ?>">
                        <td class="fw-semibold">
                            <?= h($r['ad']) ?>
                            <?php if ($isDup): ?>
                                <span class="badge bg-warning text-dark ms-1"><i class="bi bi-exclamation-triangle me-1"></i>Mükerrer</span>
                            <?php endif; ?>
                        </td>
                        <td class="font-monospace small"><?= h(($r['vkn'] ?? '') ?: '-') ?></td>
                        <td><?= h($r['telefon'] ?: '-') ?></td>
                        <td class="small"><?= h($r['adres'] ?: '-') ?></td>
                        <td class="text-center">
                            <?php if ($r['irsaliye_adet'] > 0): ?>
                                <a href="#" class="badge bg-primary text-white text-decoration-none btn-tanim-modal"
                                   data-tip="tedarikci" data-id="<?= $r['id'] ?>" data-ad="<?= h($r['ad']) ?>">
                                    <?= (int)$r['irsaliye_adet'] ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= format_number($r['toplam_m3'], 2) ?></td>
                        <td class="text-center">
                            <a href="tedarikciler.php?toggle=<?= $r['id'] ?>"
                               class="badge text-decoration-none <?= $r['aktif'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $r['aktif'] ? 'Aktif' : 'Pasif' ?>
                            </a>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="tedarikciler.php?duzenle=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="tedarikciler.php?sil=<?= $r['id'] ?>"
                               class="btn btn-xs btn-outline-danger btn-confirm"
                               data-msg="Bu tedarikçiyi silmek istediğinize emin misiniz?">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$liste): ?>
                        <tr><td colspan="8" class="text-center text-muted py-5">Henüz tedarikçi yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/tanim_modal.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
