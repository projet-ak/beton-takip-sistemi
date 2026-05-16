<?php
require_once 'includes/db.php';
$pageTitle = 'İrsaliyeler - Beton Takip Sistemi';

$msg = '';

// --- SİL ---
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $stmt = $pdo->prepare("DELETE FROM irsaliyeler WHERE id = ?");
    $stmt->execute([(int)$_GET['sil']]);
    $msg = '<div class="alert alert-success">İrsaliye silindi.</div>';
}

// --- KAYDET / GÜNCELLE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $tedarikci   = (int)($_POST['tedarikci_id'] ?? 0);
    $irsNo       = trim($_POST['irsaliye_no'] ?? '');
    $tarih       = $_POST['tarih'] ?? '';
    $miktar      = str_replace(',', '.', $_POST['miktar_m3'] ?? '0');
    $birimFiyat  = str_replace(',', '.', $_POST['birim_fiyat'] ?? '0');
    $aciklama    = trim($_POST['aciklama'] ?? '');

    if ($tedarikci && $tarih && is_numeric($miktar) && is_numeric($birimFiyat)) {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE irsaliyeler SET tedarikci_id=?, irsaliye_no=?, tarih=?, miktar_m3=?, birim_fiyat=?, aciklama=? WHERE id=?");
            $stmt->execute([$tedarikci, $irsNo, $tarih, $miktar, $birimFiyat, $aciklama, $id]);
            $msg = '<div class="alert alert-success">İrsaliye güncellendi.</div>';
        } else {
            $stmt = $pdo->prepare("INSERT INTO irsaliyeler (tedarikci_id, irsaliye_no, tarih, miktar_m3, birim_fiyat, aciklama) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$tedarikci, $irsNo, $tarih, $miktar, $birimFiyat, $aciklama]);
            $msg = '<div class="alert alert-success">İrsaliye eklendi.</div>';
        }
    } else {
        $msg = '<div class="alert alert-danger">Lütfen zorunlu alanları doldurun.</div>';
    }
}

// Düzenleme için veri çek
$duzenle = null;
if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $duzenle = $pdo->prepare("SELECT * FROM irsaliyeler WHERE id = ?");
    $duzenle->execute([(int)$_GET['duzenle']]);
    $duzenle = $duzenle->fetch();
}

$formAcik = isset($_GET['ekle']) || $duzenle;

// Tedarikçiler
$tedarikciler = $pdo->query("SELECT id, ad FROM tedarikciler WHERE aktif=1 ORDER BY ad")->fetchAll();

// Filtre
$filtreTed = isset($_GET['tedarikci']) && ctype_digit($_GET['tedarikci']) ? (int)$_GET['tedarikci'] : 0;
$filtreYil = isset($_GET['yil']) && ctype_digit($_GET['yil']) ? (int)$_GET['yil'] : 0;
$filtreAy  = isset($_GET['ay']) && ctype_digit($_GET['ay']) ? (int)$_GET['ay'] : 0;

$where = ['1=1'];
$params = [];
if ($filtreTed) { $where[] = 'i.tedarikci_id = ?'; $params[] = $filtreTed; }
if ($filtreYil) { $where[] = 'YEAR(i.tarih) = ?'; $params[] = $filtreYil; }
if ($filtreAy)  { $where[] = 'MONTH(i.tarih) = ?'; $params[] = $filtreAy; }
$whereSQL = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT i.*, t.ad AS tedarikci_adi
    FROM irsaliyeler i
    JOIN tedarikciler t ON t.id = i.tedarikci_id
    WHERE {$whereSQL}
    ORDER BY i.tarih DESC, i.id DESC
");
$stmt->execute($params);
$liste = $stmt->fetchAll();

$toplamM3    = array_sum(array_column($liste, 'miktar_m3'));
$toplamTutar = array_sum(array_column($liste, 'toplam_tutar'));

$yillar = $pdo->query("SELECT DISTINCT YEAR(tarih) AS y FROM irsaliyeler ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);

require_once 'includes/header.php';
?>

<?= $msg ?>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <?= $duzenle ? 'İrsaliye Düzenle' : 'Yeni İrsaliye Ekle' ?>
    </div>
    <div class="card-body">
        <form method="post">
            <?php if ($duzenle): ?>
                <input type="hidden" name="id" value="<?= $duzenle['id'] ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Tedarikçi <span class="text-danger">*</span></label>
                    <select name="tedarikci_id" class="form-select" required>
                        <option value="">Seçin</option>
                        <?php foreach ($tedarikciler as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($duzenle && $duzenle['tedarikci_id']==$t['id'])?'selected':'' ?>>
                                <?= htmlspecialchars($t['ad']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tarih <span class="text-danger">*</span></label>
                    <input type="date" name="tarih" class="form-control" required value="<?= htmlspecialchars($duzenle['tarih'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">İrsaliye No</label>
                    <input type="text" name="irsaliye_no" class="form-control" value="<?= htmlspecialchars($duzenle['irsaliye_no'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Miktar (m³) <span class="text-danger">*</span></label>
                    <input type="number" name="miktar_m3" class="form-control" step="0.01" min="0" required value="<?= $duzenle['miktar_m3'] ?? '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Birim Fiyat (₺) <span class="text-danger">*</span></label>
                    <input type="number" name="birim_fiyat" class="form-control" step="0.01" min="0" required value="<?= $duzenle['birim_fiyat'] ?? '' ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Açıklama</label>
                    <input type="text" name="aciklama" class="form-control" value="<?= htmlspecialchars($duzenle['aciklama'] ?? '') ?>">
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-success"><?= $duzenle ? 'Güncelle' : 'Kaydet' ?></button>
                <a href="irsaliyeler.php" class="btn btn-secondary">İptal</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span class="fw-semibold"><i class="bi bi-file-earmark-text"></i> İrsaliye Listesi</span>
        <a href="irsaliyeler.php?ekle=1" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg"></i> Yeni İrsaliye
        </a>
    </div>

    <div class="card-body border-bottom pb-3">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-sm-4 col-md-3">
                <label class="form-label small mb-1">Tedarikçi</label>
                <select name="tedarikci" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($tedarikciler as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $filtreTed==$t['id']?'selected':'' ?>>
                            <?= htmlspecialchars($t['ad']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3 col-md-2">
                <label class="form-label small mb-1">Yıl</label>
                <select name="yil" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($yillar as $y): ?>
                        <option value="<?= $y ?>" <?= $filtreYil==$y?'selected':'' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3 col-md-2">
                <label class="form-label small mb-1">Ay</label>
                <select name="ay" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php for ($i=1;$i<=12;$i++): ?>
                        <option value="<?= $i ?>" <?= $filtreAy==$i?'selected':'' ?>><?= str_pad($i,2,'0',STR_PAD_LEFT) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-primary">Filtrele</button>
                <a href="irsaliyeler.php" class="btn btn-sm btn-outline-secondary">Temizle</a>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tarih</th>
                        <th>İrsaliye No</th>
                        <th>Tedarikçi</th>
                        <th class="text-end">m³</th>
                        <th class="text-end">Birim Fiyat</th>
                        <th class="text-end">Tutar</th>
                        <th>Açıklama</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($liste): ?>
                        <?php foreach ($liste as $r): ?>
                        <tr>
                            <td><?= date('d.m.Y', strtotime($r['tarih'])) ?></td>
                            <td><?= htmlspecialchars($r['irsaliye_no'] ?: '-') ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($r['tedarikci_adi']) ?></span></td>
                            <td class="text-end"><?= number_format($r['miktar_m3'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($r['birim_fiyat'], 2, ',', '.') ?> ₺</td>
                            <td class="text-end fw-bold"><?= number_format($r['toplam_tutar'], 2, ',', '.') ?> ₺</td>
                            <td class="text-muted small"><?= htmlspecialchars($r['aciklama'] ?: '') ?></td>
                            <td class="text-nowrap">
                                <a href="irsaliyeler.php?duzenle=<?= $r['id'] ?>" class="btn btn-action btn-outline-primary btn-sm"><i class="bi bi-pencil"></i></a>
                                <a href="irsaliyeler.php?sil=<?= $r['id'] ?>" class="btn btn-action btn-outline-danger btn-sm btn-delete"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-secondary fw-bold">
                            <td colspan="3">TOPLAM</td>
                            <td class="text-end"><?= number_format($toplamM3, 2, ',', '.') ?></td>
                            <td></td>
                            <td class="text-end"><?= number_format($toplamTutar, 2, ',', '.') ?> ₺</td>
                            <td colspan="2"></td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-5">Kayıt bulunamadı.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
