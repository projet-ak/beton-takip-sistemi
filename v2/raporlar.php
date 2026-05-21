<?php
/**
 * raporlar.php — Raporlar ve istatistikler
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }

require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Raporlar — Beton Takip Sistemi';

// ── Filtreler ─────────────────────────────────────────────────────────────────
$yil           = isset($_GET['yil'])             && ctype_digit($_GET['yil'])             ? (int)$_GET['yil']             : 0;
$ay            = isset($_GET['ay'])              && ctype_digit($_GET['ay'])              ? (int)$_GET['ay']              : 0;
$parselId      = isset($_GET['parsel'])          && ctype_digit($_GET['parsel'])          ? (int)$_GET['parsel']          : 0;
$tedarikciId   = isset($_GET['tedarikci'])       && ctype_digit($_GET['tedarikci'])       ? (int)$_GET['tedarikci']       : 0;
$projeId       = isset($_GET['proje_id'])        && ctype_digit($_GET['proje_id'])        ? (int)$_GET['proje_id']        : 0;
$betonSinifiId = isset($_GET['beton_sinifi_id']) && ctype_digit($_GET['beton_sinifi_id']) ? (int)$_GET['beton_sinifi_id'] : 0;
$imalatGrupId  = isset($_GET['imalat_grup_id'])  && ctype_digit($_GET['imalat_grup_id'])  ? (int)$_GET['imalat_grup_id']  : 0;
$tip           = isset($_GET['tip']) && in_array($_GET['tip'], ['alis','iade','tum'], true) ? $_GET['tip'] : 'alis';

// Tarih aralığı (yıl seçilmediğinde uygulanır; hızlı seçim ile birlikte)
$tarihBas = isset($_GET['tarih_bas']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tarih_bas']) ? $_GET['tarih_bas'] : '';
$tarihBit = isset($_GET['tarih_bit']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tarih_bit']) ? $_GET['tarih_bit'] : '';

// Hızlı tarih ön ayarları (bugün, bu hafta, bu ay, bu yıl, son 7/30 gün)
$tarihHizli = $_GET['tarih_hizli'] ?? '';
if ($tarihHizli && $yil === 0) {
    $bugun = date('Y-m-d');
    switch ($tarihHizli) {
        case 'bugun':    $tarihBas = $tarihBit = $bugun; break;
        case 'dun':      $tarihBas = $tarihBit = date('Y-m-d', strtotime('-1 day')); break;
        case 'son7':     $tarihBas = date('Y-m-d', strtotime('-6 days')); $tarihBit = $bugun; break;
        case 'son30':    $tarihBas = date('Y-m-d', strtotime('-29 days')); $tarihBit = $bugun; break;
        case 'bu_ay':    $tarihBas = date('Y-m-01'); $tarihBit = $bugun; break;
        case 'gecen_ay': $tarihBas = date('Y-m-01', strtotime('first day of last month')); $tarihBit = date('Y-m-t', strtotime('last day of last month')); break;
        case 'bu_yil':   $tarihBas = date('Y-01-01'); $tarihBit = $bugun; break;
    }
}
// Yıl seçilirse tarih aralığını yok say
if ($yil > 0) { $tarihBas = ''; $tarihBit = ''; }

// Yıl seçilmemişse ve tarih aralığı da yoksa varsayılan yılı kullan
$yilDefault = ($yil === 0 && $tarihBas === '') ? (int)date('Y') : $yil;

// ── WHERE inşa et ─────────────────────────────────────────────────────────────
$where  = [];
$params = [];

// Tip filtresi
if ($tip === 'alis') {
    $where[] = "i.tip = 'alis'";
} elseif ($tip === 'iade') {
    $where[] = "i.tip = 'iade'";
}
// 'tum' ise koşul yok

// Tarih filtresi
if ($yilDefault > 0) {
    $where[] = 'YEAR(i.tarih) = ?';
    $params[] = $yilDefault;
    if ($ay > 0) {
        $where[] = 'MONTH(i.tarih) = ?';
        $params[] = $ay;
    }
} elseif ($tarihBas !== '' && $tarihBit !== '') {
    $where[] = 'i.tarih BETWEEN ? AND ?';
    $params[] = $tarihBas;
    $params[] = $tarihBit;
} elseif ($tarihBas !== '') {
    $where[] = 'i.tarih >= ?';
    $params[] = $tarihBas;
} elseif ($tarihBit !== '') {
    $where[] = 'i.tarih <= ?';
    $params[] = $tarihBit;
}

// İlave filtreler
if ($parselId)      { $where[] = 'i.parsel_id = ?';       $params[] = $parselId; }
if ($tedarikciId)   { $where[] = 'i.tedarikci_id = ?';    $params[] = $tedarikciId; }
if ($projeId)       { $where[] = 'i.proje_id = ?';        $params[] = $projeId; }
if ($betonSinifiId) { $where[] = 'i.beton_sinifi_id = ?'; $params[] = $betonSinifiId; }
if ($imalatGrupId)  { $where[] = 'i.imalat_grup_id = ?';  $params[] = $imalatGrupId; }

$whereSQL = $where ? implode(' AND ', $where) : '1=1';

// ── Aylık özet ────────────────────────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT MONTH(i.tarih) AS ay, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i
    WHERE {$whereSQL}
    GROUP BY MONTH(i.tarih)
    ORDER BY ay
");
$stm->execute($params);
$aylikRaw = $stm->fetchAll();

$ayAdlari = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
$aylikMap = [];
foreach ($aylikRaw as $a) { $aylikMap[(int)$a['ay']] = $a; }

// ── Beton sınıfı dağılımı ─────────────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT bs.ad AS sinif, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i
    LEFT JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id
    WHERE {$whereSQL}
    GROUP BY i.beton_sinifi_id, bs.ad
    ORDER BY toplam_m3 DESC
");
$stm->execute($params);
$betonOzet = $stm->fetchAll();

// ── Tedarikçi dağılımı ────────────────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT t.ad, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i
    LEFT JOIN tedarikciler t ON t.id = i.tedarikci_id
    WHERE {$whereSQL}
    GROUP BY i.tedarikci_id, t.ad
    ORDER BY toplam_m3 DESC
");
$stm->execute($params);
$tedarikciOzet = $stm->fetchAll();

// ── Parsel / Blok dağılımı ────────────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT par.ad AS parsel, blk.ad AS blok, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i
    LEFT JOIN parseller par ON par.id = i.parsel_id
    LEFT JOIN bloklar blk   ON blk.id = i.blok_id
    WHERE {$whereSQL}
    GROUP BY i.parsel_id, i.blok_id, par.ad, blk.ad
    ORDER BY par.ad, toplam_m3 DESC
");
$stm->execute($params);
$parselOzet = $stm->fetchAll();

// ── Parsel bazlı özet (sadece parsel) ─────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT par.ad AS parsel, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3,
           COUNT(DISTINCT i.blok_id) AS blok_sayisi
    FROM irsaliyeler i
    LEFT JOIN parseller par ON par.id = i.parsel_id
    WHERE {$whereSQL}
    GROUP BY i.parsel_id, par.ad
    ORDER BY toplam_m3 DESC
");
$stm->execute($params);
$parselBazli = $stm->fetchAll();

// ── Blok bazlı özet (sadece blok) ─────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT blk.ad AS blok, par.ad AS parsel, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i
    LEFT JOIN bloklar blk   ON blk.id = i.blok_id
    LEFT JOIN parseller par ON par.id = i.parsel_id
    WHERE {$whereSQL} AND i.blok_id IS NOT NULL
    GROUP BY i.blok_id, blk.ad, par.ad
    ORDER BY toplam_m3 DESC
");
$stm->execute($params);
$blokBazli = $stm->fetchAll();

// ── İmalat grubu / ana iş kalemi dağılımı ────────────────────────────────────
$stm = $pdo->prepare("
    SELECT ig.ad AS grup, aik.ad AS kalem, COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3
    FROM irsaliyeler i
    LEFT JOIN imalat_gruplari ig   ON ig.id  = i.imalat_grup_id
    LEFT JOIN ana_is_kalemleri aik ON aik.id = i.ana_is_kalemi_id
    WHERE {$whereSQL}
    GROUP BY i.imalat_grup_id, i.ana_is_kalemi_id, ig.ad, aik.ad
    ORDER BY ig.ad, toplam_m3 DESC
");
$stm->execute($params);
$isKalemiOzet = $stm->fetchAll();

// ── Genel toplamlar ───────────────────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT COUNT(*) AS adet, COALESCE(SUM(miktar),0) AS toplam_m3
    FROM irsaliyeler i
    WHERE {$whereSQL}
");
$stm->execute($params);
$genelToplam = $stm->fetch();

// ── Detaylı irsaliye tablosu ──────────────────────────────────────────────────
$stm = $pdo->prepare("
    SELECT i.id, i.tarih, i.irsaliye_no, i.tip,
           t.ad AS tedarikci, bs.ad AS beton_sinifi, i.miktar, i.birim,
           pt.ad AS pompa, ig.ad AS imalat_grup, aik.ad AS ana_is_kalemi,
           par.ad AS parsel, blk.ad AS blok, ko.kot_degeri AS kot,
           f.ad AS firma, i.arac_plaka, i.aciklama, p.kod AS proje_kod
    FROM irsaliyeler i
    LEFT JOIN tedarikciler t       ON t.id   = i.tedarikci_id
    LEFT JOIN beton_siniflari bs   ON bs.id  = i.beton_sinifi_id
    LEFT JOIN pompa_turleri pt     ON pt.id  = i.pompa_id
    LEFT JOIN imalat_gruplari ig   ON ig.id  = i.imalat_grup_id
    LEFT JOIN ana_is_kalemleri aik ON aik.id = i.ana_is_kalemi_id
    LEFT JOIN parseller par        ON par.id = i.parsel_id
    LEFT JOIN bloklar blk          ON blk.id = i.blok_id
    LEFT JOIN kotlar ko            ON ko.id  = i.kot_id
    LEFT JOIN firmalar f           ON f.id   = i.firma_id
    LEFT JOIN projeler p           ON p.id   = i.proje_id
    WHERE {$whereSQL}
    ORDER BY i.tarih DESC, i.id DESC
");
$stm->execute($params);
$detayRows = $stm->fetchAll();

// ── Filtre dropdown verileri ──────────────────────────────────────────────────
$yillar = $pdo->query("SELECT DISTINCT YEAR(tarih) AS y FROM irsaliyeler ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if ($yilDefault > 0 && !in_array($yilDefault, array_map('intval', $yillar))) {
    $yillar[] = $yilDefault;
}
$parseller      = $pdo->query("SELECT id, ad FROM parseller ORDER BY ad")->fetchAll();
$tedarikciler   = $pdo->query("SELECT id, ad FROM tedarikciler WHERE aktif=1 ORDER BY ad")->fetchAll();
$betonSiniflari = $pdo->query("SELECT id, ad FROM beton_siniflari ORDER BY ad")->fetchAll();
$imalatGruplari = $pdo->query("SELECT id, ad FROM imalat_gruplari ORDER BY ad")->fetchAll();

// Projeler tablosu yoksa boş dizi döndür
try {
    $projeler = $pdo->query("SELECT id, COALESCE(kod, ad, CONCAT('Proje #',id)) AS etiket FROM projeler ORDER BY etiket")->fetchAll();
} catch (Exception $e) {
    $projeler = [];
}

// ── Grafik verileri ───────────────────────────────────────────────────────────
$chartLabels = [];
$chartM3     = [];
for ($i = 1; $i <= 12; $i++) {
    $chartLabels[] = $ayAdlari[$i];
    $chartM3[]     = isset($aylikMap[$i]) ? (float)$aylikMap[$i]['toplam_m3'] : 0;
}

$betonLabels = json_encode(array_column($betonOzet, 'sinif'));
$betonValues = json_encode(array_map(fn($r) => (float)$r['toplam_m3'], $betonOzet));
$tedLabels   = json_encode(array_column($tedarikciOzet, 'ad'));
$tedValues   = json_encode(array_map(fn($r) => (float)$r['toplam_m3'], $tedarikciOzet));

// JS'e aktarılacak özet değerleri
$jsToplamM3   = (float)$genelToplam['toplam_m3'];
$jsToplamAdet = (int)$genelToplam['adet'];

// Dönem etiketi
$donemEtiketi = '';
if ($yilDefault > 0) {
    $donemEtiketi = $yilDefault . ($ay > 0 ? ' / ' . $ayAdlari[$ay] : '');
} elseif ($tarihBas || $tarihBit) {
    $donemEtiketi = ($tarihBas ?: '...') . ' – ' . ($tarihBit ?: '...');
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .card { break-inside: avoid; }
    .navbar { display: none !important; }
    body { padding: 0; }
    .container-fluid { padding: 0.5rem !important; }
}
</style>

<!-- Başlık & Aksiyon Butonları -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Raporlar</h4>
    <div class="d-flex gap-2 flex-wrap no-print">
        <button onclick="exportExcel()" class="btn btn-sm btn-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel'e Aktar
        </button>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer me-1"></i> PDF / Yazdır
        </button>
    </div>
</div>

<!-- Filtreler -->
<div class="card mb-4 no-print">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get">

            <!-- Tip -->
            <div class="col-sm-4 col-md-2">
                <label class="form-label small mb-1">Tip</label>
                <select name="tip" class="form-select form-select-sm">
                    <option value="alis" <?= $tip === 'alis' ? 'selected' : '' ?>>Alış</option>
                    <option value="iade" <?= $tip === 'iade' ? 'selected' : '' ?>>İade</option>
                    <option value="tum"  <?= $tip === 'tum'  ? 'selected' : '' ?>>Tümü</option>
                </select>
            </div>

            <!-- Yıl -->
            <div class="col-sm-3 col-md-1">
                <label class="form-label small mb-1">Yıl</label>
                <select name="yil" class="form-select form-select-sm" id="selYil">
                    <option value="0" <?= $yilDefault === 0 ? 'selected' : '' ?>>—</option>
                    <?php foreach ($yillar as $y): ?>
                        <option value="<?= $y ?>" <?= $yilDefault == (int)$y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Ay -->
            <div class="col-sm-3 col-md-1">
                <label class="form-label small mb-1">Ay</label>
                <select name="ay" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $ay == $m ? 'selected' : '' ?>><?= $ayAdlari[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Tarih Aralığı (yıl seçilmemişse görünür) -->
            <div class="col-sm-4 col-md-2" id="tarihBasDiv">
                <label class="form-label small mb-1">Başlangıç Tarihi</label>
                <input type="date" name="tarih_bas" class="form-control form-control-sm" value="<?= h($tarihBas) ?>">
            </div>
            <div class="col-sm-4 col-md-2" id="tarihBitDiv">
                <label class="form-label small mb-1">Bitiş Tarihi</label>
                <input type="date" name="tarih_bit" class="form-control form-control-sm" value="<?= h($tarihBit) ?>">
            </div>
            <!-- Hızlı tarih seçim -->
            <div class="col-12" id="hizliTarihDiv">
                <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Hızlı tarih">
                    <?php
                    $hizliSec = [
                        'bugun' => 'Bugün', 'dun' => 'Dün', 'son7' => 'Son 7 Gün',
                        'son30' => 'Son 30 Gün', 'bu_ay' => 'Bu Ay',
                        'gecen_ay' => 'Geçen Ay', 'bu_yil' => 'Bu Yıl',
                    ];
                    foreach ($hizliSec as $k => $lbl):
                    ?>
                        <button type="submit" name="tarih_hizli" value="<?= $k ?>"
                                class="btn btn-outline-secondary <?= $tarihHizli === $k ? 'active' : '' ?>"
                                onclick="document.querySelector('[name=yil]').value='0';">
                            <?= h($lbl) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Parsel -->
            <div class="col-sm-4 col-md-2">
                <label class="form-label small mb-1">Parsel</label>
                <select name="parsel" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($parseller as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $parselId == $p['id'] ? 'selected' : '' ?>><?= h($p['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Tedarikçi -->
            <div class="col-sm-4 col-md-2">
                <label class="form-label small mb-1">Tedarikçi</label>
                <select name="tedarikci" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($tedarikciler as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $tedarikciId == $t['id'] ? 'selected' : '' ?>><?= h($t['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Proje (tablo varsa göster) -->
            <?php if (!empty($projeler)): ?>
            <div class="col-sm-4 col-md-2">
                <label class="form-label small mb-1">Proje</label>
                <select name="proje_id" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($projeler as $pr): ?>
                        <option value="<?= $pr['id'] ?>" <?= $projeId == $pr['id'] ? 'selected' : '' ?>><?= h($pr['etiket']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Beton Sınıfı -->
            <div class="col-sm-4 col-md-2">
                <label class="form-label small mb-1">Beton Sınıfı</label>
                <select name="beton_sinifi_id" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($betonSiniflari as $bs): ?>
                        <option value="<?= $bs['id'] ?>" <?= $betonSinifiId == $bs['id'] ? 'selected' : '' ?>><?= h($bs['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- İmalat Grubu -->
            <div class="col-sm-4 col-md-2">
                <label class="form-label small mb-1">İmalat Grubu</label>
                <select name="imalat_grup_id" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($imalatGruplari as $ig): ?>
                        <option value="<?= $ig['id'] ?>" <?= $imalatGrupId == $ig['id'] ? 'selected' : '' ?>><?= h($ig['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filtrele</button>
                <a href="raporlar.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Temizle</a>
            </div>

        </form>
    </div>
</div>

<!-- Özet Kartları -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3 border-start border-primary border-4">
            <div class="fs-4 fw-bold text-primary"><?= format_number($genelToplam['toplam_m3'], 2) ?></div>
            <div class="text-muted small">Toplam m³<?= $donemEtiketi ? ' (' . h($donemEtiketi) . ')' : '' ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 border-start border-success border-4">
            <div class="fs-4 fw-bold text-success"><?= $jsToplamAdet ?></div>
            <div class="text-muted small">Toplam İrsaliye</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 border-start border-warning border-4">
            <div class="fs-4 fw-bold text-warning"><?= count($betonOzet) ?></div>
            <div class="text-muted small">Beton Sınıfı</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 border-start border-info border-4">
            <div class="fs-4 fw-bold text-info"><?= count($parselOzet) ?></div>
            <div class="text-muted small">Parsel / Blok</div>
        </div>
    </div>
</div>

<!-- Grafikler -->
<div class="row g-4 mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-white">Aylık m³ Dağılımı<?= $yilDefault > 0 ? ' (' . $yilDefault . ')' : '' ?></div>
            <div class="card-body">
                <canvas id="chartAylik" height="110"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white">Tedarikçi Dağılımı</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="chartTed" style="max-height:220px"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Aylık Detay & Tedarikçi Özeti -->
<div class="row g-4 mb-4">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Aylık Detay<?= $yilDefault > 0 ? ' (' . $yilDefault . ')' : '' ?></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Ay</th><th class="text-end">Adet</th><th class="text-end">m³</th></tr>
                    </thead>
                    <tbody>
                    <?php
                    $totalM3 = $totalAdet = 0;
                    for ($i = 1; $i <= 12; $i++):
                        $r  = $aylikMap[$i] ?? null;
                        $m3 = $r ? (float)$r['toplam_m3'] : 0;
                        $ad = $r ? (int)$r['adet'] : 0;
                        $totalM3 += $m3; $totalAdet += $ad;
                    ?>
                        <tr <?= !$r ? 'class="text-muted"' : '' ?>>
                            <td><?= $ayAdlari[$i] ?></td>
                            <td class="text-end"><?= $ad ?: '-' ?></td>
                            <td class="text-end"><?= $m3 ? format_number($m3, 2) : '-' ?></td>
                        </tr>
                    <?php endfor; ?>
                        <tr class="table-secondary fw-bold">
                            <td>TOPLAM</td>
                            <td class="text-end"><?= $totalAdet ?></td>
                            <td class="text-end"><?= format_number($totalM3, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Tedarikçi Özeti</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Tedarikçi</th><th class="text-end">Adet</th><th class="text-end">m³</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tedarikciOzet as $t): ?>
                        <tr>
                            <td><?= h($t['ad'] ?: '(Bilinmiyor)') ?></td>
                            <td class="text-end"><?= (int)$t['adet'] ?></td>
                            <td class="text-end fw-semibold"><?= format_number($t['toplam_m3'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tedarikciOzet)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">Veri yok</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Beton Sınıfı Dağılımı -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">Beton Sınıfı Dağılımı</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>Beton Sınıfı</th><th class="text-end">Adet</th><th class="text-end">m³</th><th class="text-end">%</th></tr>
            </thead>
            <tbody>
            <?php
            $gtop = (float)$genelToplam['toplam_m3'];
            foreach ($betonOzet as $b):
                $pct = $gtop > 0 ? round(($b['toplam_m3'] / $gtop) * 100, 1) : 0;
            ?>
                <tr>
                    <td><?= h($b['sinif'] ?: '(Belirtilmemiş)') ?></td>
                    <td class="text-end"><?= (int)$b['adet'] ?></td>
                    <td class="text-end fw-semibold"><?= format_number($b['toplam_m3'], 2) ?></td>
                    <td class="text-end text-muted"><?= $pct ?>%</td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($betonOzet)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">Veri yok</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Parsel Bazlı Rapor -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-map text-primary me-1"></i> Parsel Bazlı Rapor
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Parsel</th>
                                <th class="text-end">Blok</th>
                                <th class="text-end">Adet</th>
                                <th class="text-end">m³</th>
                                <th class="text-end">%</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $gtopP = (float)$genelToplam['toplam_m3'];
                        $sumP = 0;
                        foreach ($parselBazli as $pp):
                            $pct = $gtopP > 0 ? round(($pp['toplam_m3'] / $gtopP) * 100, 1) : 0;
                            $sumP += (float)$pp['toplam_m3'];
                        ?>
                            <tr>
                                <td><?= h($pp['parsel'] ?: '(Belirtilmemiş)') ?></td>
                                <td class="text-end"><?= (int)$pp['blok_sayisi'] ?></td>
                                <td class="text-end"><?= (int)$pp['adet'] ?></td>
                                <td class="text-end fw-semibold"><?= format_number($pp['toplam_m3'], 2) ?></td>
                                <td class="text-end text-muted"><?= $pct ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($parselBazli)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">Veri yok</td></tr>
                        <?php else: ?>
                            <tr class="table-secondary fw-bold">
                                <td colspan="3" class="text-end">TOPLAM</td>
                                <td class="text-end"><?= format_number($sumP, 2) ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-buildings text-primary me-1"></i> Blok Bazlı Rapor
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Blok</th>
                                <th>Parsel</th>
                                <th class="text-end">Adet</th>
                                <th class="text-end">m³</th>
                                <th class="text-end">%</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $sumB = 0;
                        foreach ($blokBazli as $bb):
                            $pct = $gtopP > 0 ? round(($bb['toplam_m3'] / $gtopP) * 100, 1) : 0;
                            $sumB += (float)$bb['toplam_m3'];
                        ?>
                            <tr>
                                <td><?= h($bb['blok'] ?: '(Belirtilmemiş)') ?></td>
                                <td class="small text-muted"><?= h($bb['parsel'] ?: '—') ?></td>
                                <td class="text-end"><?= (int)$bb['adet'] ?></td>
                                <td class="text-end fw-semibold"><?= format_number($bb['toplam_m3'], 2) ?></td>
                                <td class="text-end text-muted"><?= $pct ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($blokBazli)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">Veri yok</td></tr>
                        <?php else: ?>
                            <tr class="table-secondary fw-bold">
                                <td colspan="3" class="text-end">TOPLAM</td>
                                <td class="text-end"><?= format_number($sumB, 2) ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Parsel / Blok Dağılımı (kombine) -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">Parsel / Blok Dağılımı (Kombine)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Parsel</th><th>Blok</th><th class="text-end">Adet</th><th class="text-end">m³</th></tr>
                </thead>
                <tbody>
                <?php foreach ($parselOzet as $p): ?>
                    <tr>
                        <td><?= h($p['parsel'] ?: '—') ?></td>
                        <td><?= h($p['blok'] ?: '—') ?></td>
                        <td class="text-end"><?= (int)$p['adet'] ?></td>
                        <td class="text-end fw-semibold"><?= format_number($p['toplam_m3'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($parselOzet)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Veri yok</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- İmalat Grubu / İş Kalemi Dağılımı -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">İmalat Grubu / İş Kalemi Dağılımı</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>İmalat Grubu</th><th>Ana İş Kalemi</th><th class="text-end">Adet</th><th class="text-end">m³</th></tr>
                </thead>
                <tbody>
                <?php foreach ($isKalemiOzet as $k): ?>
                    <tr>
                        <td><?= h($k['grup'] ?: '—') ?></td>
                        <td><?= h($k['kalem'] ?: '—') ?></td>
                        <td class="text-end"><?= (int)$k['adet'] ?></td>
                        <td class="text-end fw-semibold"><?= format_number($k['toplam_m3'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($isKalemiOzet)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">Veri yok</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detaylı Rapor -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-1"></i>Detaylı Rapor</span>
        <small class="text-muted"><?= count($detayRows) ?> kayıt</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="detayTablosu">
                <thead class="table-light">
                    <tr>
                        <th>Tarih</th>
                        <th>İrs.No</th>
                        <th>Tip</th>
                        <th>Tedarikçi</th>
                        <th>Beton</th>
                        <th class="text-end">Miktar</th>
                        <th>Pompa</th>
                        <th>İmalat Grubu</th>
                        <th>İş Kalemi</th>
                        <th>Parsel</th>
                        <th>Blok</th>
                        <th>Kot</th>
                        <th>Firma</th>
                        <th>Plaka</th>
                        <th>Proje</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($detayRows)): ?>
                    <tr><td colspan="15" class="text-center text-muted py-3">Veri yok</td></tr>
                <?php else: ?>
                    <?php foreach ($detayRows as $d): ?>
                    <tr>
                        <td><?= format_date($d['tarih']) ?></td>
                        <td><?= h($d['irsaliye_no'] ?: '—') ?></td>
                        <td>
                            <?php if ($d['tip'] === 'alis'): ?>
                                <span class="badge bg-success">Alış</span>
                            <?php else: ?>
                                <span class="badge bg-danger">İade</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($d['tedarikci'] ?: '—') ?></td>
                        <td><?= h($d['beton_sinifi'] ?: '—') ?></td>
                        <td class="text-end fw-semibold"><?= format_number($d['miktar'], 2) ?> <?= h($d['birim'] ?: 'm³') ?></td>
                        <td><?= h($d['pompa'] ?: '—') ?></td>
                        <td><?= h($d['imalat_grup'] ?: '—') ?></td>
                        <td><?= h($d['ana_is_kalemi'] ?: '—') ?></td>
                        <td><?= h($d['parsel'] ?: '—') ?></td>
                        <td><?= h($d['blok'] ?: '—') ?></td>
                        <td><?= h($d['kot'] !== null ? $d['kot'] : '—') ?></td>
                        <td><?= h($d['firma'] ?: '—') ?></td>
                        <td><?= h($d['arac_plaka'] ?: '—') ?></td>
                        <td><?= h($d['proje_kod'] ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
(function () {
    const COLORS = ['#0d6efd','#198754','#fd7e14','#dc3545','#6610f2','#20c997','#ffc107','#0dcaf0','#6f42c1','#d63384'];
    const ayAdlari = <?= json_encode(array_values($ayAdlari)) ?>;

    // Aylık bar grafik
    new Chart(document.getElementById('chartAylik'), {
        type: 'bar',
        data: {
            labels: ayAdlari.slice(1),
            datasets: [{
                label: 'm³',
                data: <?= json_encode(array_values($chartM3)) ?>,
                backgroundColor: 'rgba(13,110,253,.75)',
                borderRadius: 5
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, title: { display: true, text: 'm³' } } }
        }
    });

    // Tedarikçi doughnut grafik
    new Chart(document.getElementById('chartTed'), {
        type: 'doughnut',
        data: {
            labels: <?= $tedLabels ?>,
            datasets: [{ data: <?= $tedValues ?>, backgroundColor: COLORS }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });

    // Yıl seçilince tarih aralığı alanlarını gizle/göster
    const selYil     = document.getElementById('selYil');
    const tarihBasDiv = document.getElementById('tarihBasDiv');
    const tarihBitDiv = document.getElementById('tarihBitDiv');

    function toggleTarih() {
        const yilSecildi = selYil && selYil.value !== '0';
        if (tarihBasDiv) tarihBasDiv.style.display = yilSecildi ? 'none' : '';
        if (tarihBitDiv) tarihBitDiv.style.display = yilSecildi ? 'none' : '';
    }

    if (selYil) {
        selYil.addEventListener('change', toggleTarih);
        toggleTarih();
    }
})();

// PHP'den JS'e özet değerleri
const toplamM3   = <?= json_encode($jsToplamM3) ?>;
const toplamAdet = <?= json_encode($jsToplamAdet) ?>;

function exportExcel() {
    const wb = XLSX.utils.book_new();

    // Detay sayfası — detayTablosu HTML tablosundan
    const ws = XLSX.utils.table_to_sheet(document.getElementById('detayTablosu'));
    XLSX.utils.book_append_sheet(wb, ws, 'Beton Rapor');

    // Özet sayfası
    const ozet = [
        ['Toplam m³',      toplamM3],
        ['Toplam İrsaliye', toplamAdet],
        ['Tarih',           new Date().toLocaleDateString('tr-TR')],
    ];
    const ws2 = XLSX.utils.aoa_to_sheet(ozet);
    XLSX.utils.book_append_sheet(wb, ws2, 'Özet');

    XLSX.writeFile(wb, 'beton_rapor_' + new Date().toISOString().slice(0, 10) + '.xlsx');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
