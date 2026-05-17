<?php
/**
 * irsaliyeler.php — İrsaliye listesi (alış + iade)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }

require_auth();
require_once __DIR__ . '/includes/db.php';

$tip       = in_array($_GET['tip'] ?? '', ['alis','iade','tum'], true) ? $_GET['tip'] : 'alis';
$pageTitle = ($tip === 'alis' ? 'Alış' : ($tip === 'iade' ? 'İade' : 'Tüm')) . ' İrsaliyeleri — Beton Takip Sistemi';

// ── Silme ────────────────────────────────────────────────────────────────────
if (can_edit() && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $chk = $pdo->prepare("SELECT id FROM irsaliyeler WHERE id=?" . ($tip !== 'tum' ? " AND tip=?" : ""));
    $tip !== 'tum' ? $chk->execute([(int)$_GET['sil'], $tip]) : $chk->execute([(int)$_GET['sil']]);
    if ($chk->fetch()) {
        $pdo->prepare("DELETE FROM irsaliyeler WHERE id=?")->execute([(int)$_GET['sil']]);
        flash('success', 'İrsaliye silindi.');
    }
    redirect("irsaliyeler.php?tip={$tip}");
}

// ── Filtreler ─────────────────────────────────────────────────────────────────
$filtreTed    = isset($_GET['tedarikci'])   && ctype_digit($_GET['tedarikci'])   ? (int)$_GET['tedarikci']   : 0;
$filtreParsel = isset($_GET['parsel'])      && ctype_digit($_GET['parsel'])      ? (int)$_GET['parsel']      : 0;
$filtreBlok   = isset($_GET['blok'])        && ctype_digit($_GET['blok'])        ? (int)$_GET['blok']        : 0;
$filtreBS     = isset($_GET['beton'])       && ctype_digit($_GET['beton'])       ? (int)$_GET['beton']       : 0;
$filtreYil    = isset($_GET['yil'])         && ctype_digit($_GET['yil'])         ? (int)$_GET['yil']         : 0;
$filtreAy     = isset($_GET['ay'])          && ctype_digit($_GET['ay'])          ? (int)$_GET['ay']          : 0;
$filtreArama  = trim($_GET['ara'] ?? '');

$where  = $tip !== 'tum' ? ['i.tip = ?'] : [];
$params = $tip !== 'tum' ? [$tip] : [];

if ($filtreTed)    { $where[] = 'i.tedarikci_id = ?';     $params[] = $filtreTed; }
if ($filtreParsel) { $where[] = 'i.parsel_id = ?';        $params[] = $filtreParsel; }
if ($filtreBlok)   { $where[] = 'i.blok_id = ?';          $params[] = $filtreBlok; }
if ($filtreBS)     { $where[] = 'i.beton_sinifi_id = ?';  $params[] = $filtreBS; }
if ($filtreYil)    { $where[] = 'YEAR(i.tarih) = ?';      $params[] = $filtreYil; }
if ($filtreAy)     { $where[] = 'MONTH(i.tarih) = ?';     $params[] = $filtreAy; }
if ($filtreArama)  { $where[] = '(i.irsaliye_no LIKE ? OR i.arac_plaka LIKE ? OR i.fatura_no LIKE ?)';
                     $params[] = "%{$filtreArama}%"; $params[] = "%{$filtreArama}%"; $params[] = "%{$filtreArama}%"; }

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("
    SELECT i.*,
           t.ad  AS tedarikci_adi,
           bs.ad AS beton_sinifi_adi,
           pt.ad AS pompa_adi,
           k1.ad AS katki1_adi,
           k2.ad AS katki2_adi,
           fr.ad AS firma_adi,
           ig.ad AS imalat_grup_adi,
           aik.ad AS ana_is_kalemi_adi,
           par.ad AS parsel_adi,
           blk.ad AS blok_adi,
           kot.kot_degeri
    FROM irsaliyeler i
    LEFT JOIN tedarikciler t      ON t.id   = i.tedarikci_id
    LEFT JOIN beton_siniflari bs  ON bs.id  = i.beton_sinifi_id
    LEFT JOIN pompa_turleri pt    ON pt.id  = i.pompa_id
    LEFT JOIN katki_listesi k1    ON k1.id  = i.katki1_id
    LEFT JOIN katki_listesi k2    ON k2.id  = i.katki2_id
    LEFT JOIN firmalar fr         ON fr.id  = i.firma_id
    LEFT JOIN imalat_gruplari ig  ON ig.id  = i.imalat_grup_id
    LEFT JOIN ana_is_kalemleri aik ON aik.id = i.ana_is_kalemi_id
    LEFT JOIN parseller par       ON par.id = i.parsel_id
    LEFT JOIN bloklar blk         ON blk.id = i.blok_id
    LEFT JOIN kotlar kot          ON kot.id = i.kot_id
    {$whereSQL}
    ORDER BY i.tarih DESC, i.sira_no DESC, i.id DESC
");
$stmt->execute($params);
$liste = $stmt->fetchAll();

$toplamM3 = array_sum(array_column($liste, 'miktar'));

// Filtre dropdownları için veriler
$tedarikciler  = $pdo->query("SELECT id,ad FROM tedarikciler WHERE aktif=1 ORDER BY ad")->fetchAll();
$parseller     = $pdo->query("SELECT id,ad FROM parseller ORDER BY ad")->fetchAll();
$betonSiniflari= $pdo->query("SELECT id,ad FROM beton_siniflari ORDER BY ad")->fetchAll();
if ($filtreParsel) {
    $blokStmt = $pdo->prepare("SELECT id,ad FROM bloklar WHERE parsel_id=? ORDER BY ad");
    $blokStmt->execute([$filtreParsel]);
    $bloklar = $blokStmt->fetchAll();
} else {
    $bloklar = [];
}
$yillar        = $pdo->query("SELECT DISTINCT YEAR(tarih) AS y FROM irsaliyeler ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h4 class="mb-0">
            <?php if ($tip === 'alis'): ?>
                <i class="bi bi-arrow-down-circle text-success me-2"></i>Alış İrsaliyeleri
            <?php elseif ($tip === 'iade'): ?>
                <i class="bi bi-arrow-up-circle text-danger me-2"></i>İade İrsaliyeleri
            <?php else: ?>
                <i class="bi bi-list-ul text-primary me-2"></i>Tüm İrsaliyeler
            <?php endif; ?>
        </h4>
        <div class="text-muted small mt-1">
            Toplam: <strong><?= count($liste) ?></strong> kayıt &mdash;
            <strong><?= format_number($toplamM3, 2) ?> m³</strong>
        </div>
    </div>
    <?php if (can_edit()): ?>
    <a href="irsaliye_form.php?tip=<?= $tip ?>" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i> Yeni İrsaliye
    </a>
    <?php endif; ?>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tip==='alis'?'active':'' ?>" href="irsaliyeler.php?tip=alis">
            <i class="bi bi-arrow-down-circle text-success me-1"></i> Alış
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tip==='iade'?'active':'' ?>" href="irsaliyeler.php?tip=iade">
            <i class="bi bi-arrow-up-circle text-danger me-1"></i> İade
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tip==='tum'?'active':'' ?>" href="irsaliyeler.php?tip=tum">
            <i class="bi bi-list-ul me-1"></i> Tüm Kayıtlar
        </a>
    </li>
</ul>

<!-- Filtre formu -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">
            <input type="hidden" name="tip" value="<?= h($tip) ?>">
            <div class="col-sm-6 col-md-3">
                <label class="form-label small mb-1">Arama</label>
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="İrsaliye/Fatura/Plaka" value="<?= h($filtreArama) ?>">
            </div>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small mb-1">Tedarikçi</label>
                <select name="tedarikci" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($tedarikciler as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $filtreTed == $t['id'] ? 'selected' : '' ?>><?= h($t['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small mb-1">Parsel</label>
                <select name="parsel" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($parseller as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filtreParsel == $p['id'] ? 'selected' : '' ?>><?= h($p['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-1">
                <label class="form-label small mb-1">Yıl</label>
                <select name="yil" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($yillar as $y): ?>
                        <option value="<?= $y ?>" <?= $filtreYil == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-1">
                <label class="form-label small mb-1">Ay</label>
                <select name="ay" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?= $i ?>" <?= $filtreAy == $i ? 'selected' : '' ?>><?= str_pad($i,2,'0',STR_PAD_LEFT) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search me-1"></i>Filtrele</button>
                <a href="irsaliyeler.php?tip=<?= $tip ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Temizle</a>
            </div>
        </form>
    </div>
</div>

<!-- Liste tablosu -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-sm">
                <thead class="table-light">
                    <tr>
                        <th class="text-center">#</th>
                        <?php if ($tip === 'tum'): ?><th>Tip</th><?php endif; ?>
                        <th>Tarih</th>
                        <th>İrsaliye No</th>
                        <th>Plaka</th>
                        <th>Tedarikçi</th>
                        <th>Beton Sınıfı</th>
                        <th>Parsel / Blok / Kot</th>
                        <th>Pompa</th>
                        <th>Firma</th>
                        <th class="text-end">Miktar</th>
                        <th>Açıklama</th>
                        <?php if (can_edit()): ?><th class="text-end">İşlem</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($liste): ?>
                        <?php foreach ($liste as $r): ?>
                        <tr>
                            <td class="text-center text-muted small"><?= h($r['sira_no'] ?: '-') ?></td>
                            <?php if ($tip === 'tum'): ?>
                            <td><?= $r['tip']==='alis' ? '<span class="badge bg-success">Alış</span>' : '<span class="badge bg-danger">İade</span>' ?></td>
                            <?php endif; ?>
                            <td class="text-nowrap"><?= format_date($r['tarih']) ?></td>
                            <td class="text-nowrap">
                                <a href="irsaliye_detay.php?id=<?= $r['id'] ?>" class="text-decoration-none fw-semibold">
                                    <?= h($r['irsaliye_no'] ?: '#'.$r['id']) ?>
                                </a>
                            </td>
                            <td class="text-nowrap small"><?= h($r['arac_plaka'] ?: '-') ?></td>
                            <td><span class="badge bg-secondary"><?= h($r['tedarikci_adi'] ?? '-') ?></span></td>
                            <td><?= h($r['beton_sinifi_adi'] ?? '-') ?></td>
                            <td class="small text-muted text-nowrap">
                                <?= h($r['parsel_adi'] ?? '') ?>
                                <?php if ($r['blok_adi']): ?><br><?= h($r['blok_adi']) ?><?php endif; ?>
                                <?php if ($r['kot_degeri']): ?> <span class="badge bg-light text-dark"><?= h($r['kot_degeri']) ?></span><?php endif; ?>
                            </td>
                            <td class="small"><?= h($r['pompa_adi'] ?? '-') ?></td>
                            <td class="small"><?= h($r['firma_adi'] ?? '-') ?></td>
                            <td class="text-end fw-semibold text-nowrap">
                                <?= format_number($r['miktar'], 2) ?> <span class="text-muted small"><?= h($r['birim']) ?></span>
                            </td>
                            <td class="text-muted small" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?= h($r['aciklama'] ?: '') ?>
                            </td>
                            <?php if (can_edit()): ?>
                            <td class="text-end text-nowrap">
                                <a href="irsaliye_form.php?id=<?= $r['id'] ?>&tip=<?= $r['tip'] ?>" class="btn btn-xs btn-outline-primary me-1" title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="irsaliyeler.php?sil=<?= $r['id'] ?>&tip=<?= $tip ?>"
                                   class="btn btn-xs btn-outline-danger btn-confirm"
                                   data-msg="Bu irsaliyeyi silmek istediğinize emin misiniz?"
                                   title="Sil">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-secondary fw-bold">
                            <td colspan="<?= $tip === 'tum' ? 10 : 9 ?>" class="text-end">TOPLAM</td>
                            <td class="text-end"><?= format_number($toplamM3, 2) ?> m³</td>
                            <td colspan="<?= can_edit() ? 2 : 1 ?>"></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= can_edit() ? ($tip === 'tum' ? 13 : 12) : ($tip === 'tum' ? 12 : 11) ?>" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                Kayıt bulunamadı.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.btn-xs { padding:.15rem .4rem; font-size:.75rem; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
