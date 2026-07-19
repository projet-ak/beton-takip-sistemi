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

// Reddedilen irsaliyeler hiçbir toplama dahil edilmez (dashboard/zayıat ile tutarlı)
$where[] = "i.durum <> 'reddedildi'";

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
if ($projeId)       { $where[] = 'COALESCE(i.proje_id, (SELECT p2.proje_id FROM parseller p2 WHERE p2.id = i.parsel_id)) = ?'; $params[] = $projeId; }
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

// ── BETON SINIFI DAGILIMI ─────────────────────────────────────────────────────
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

// ── TEDARIKCI DAGILIMI ────────────────────────────────────────────────────────
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

// ── Proje bazlı özet ──────────────────────────────────────────────────────────
// Etkin proje = irsaliyenin proje_id'si; yoksa parselin proje_id'si (parsel→proje bağı).
try {
    $stm = $pdo->prepare("
        SELECT COALESCE(pr.kod, '—') AS proje_kod,
               COALESCE(pr.aciklama, 'Proje atanmamış') AS proje_ad,
               COUNT(*) AS adet,
               COALESCE(SUM(eff.miktar),0) AS toplam_m3,
               COUNT(DISTINCT eff.parsel_id) AS parsel_sayisi
        FROM (
            SELECT i.miktar, i.parsel_id, COALESCE(i.proje_id, par.proje_id) AS eff_proje_id
            FROM irsaliyeler i
            LEFT JOIN parseller par ON par.id = i.parsel_id
            WHERE {$whereSQL}
        ) eff
        LEFT JOIN projeler pr ON pr.id = eff.eff_proje_id
        GROUP BY eff.eff_proje_id, pr.kod, pr.aciklama
        ORDER BY toplam_m3 DESC
    ");
    $stm->execute($params);
    $projeBazli = $stm->fetchAll();
} catch (Throwable $e) {
    // parseller.proje_id yoksa yalnız i.proje_id ile grupla
    $stm = $pdo->prepare("
        SELECT COALESCE(pr.kod,'—') AS proje_kod, COALESCE(pr.aciklama,'Proje atanmamış') AS proje_ad,
               COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS toplam_m3, COUNT(DISTINCT i.parsel_id) AS parsel_sayisi
        FROM irsaliyeler i LEFT JOIN projeler pr ON pr.id = i.proje_id
        WHERE {$whereSQL} GROUP BY i.proje_id, pr.kod, pr.aciklama ORDER BY toplam_m3 DESC
    ");
    $stm->execute($params);
    $projeBazli = $stm->fetchAll();
}

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
           f.ad AS firma, i.arac_plaka, i.aciklama, p.kod AS proje_kod, p.aciklama AS proje_ad
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
$projeLabels = json_encode(array_column($projeBazli, 'proje_kod'));
$projeValues = json_encode(array_map(fn($r) => (float)$r['toplam_m3'], $projeBazli));

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
        <button onclick="exportPDF()" class="btn btn-sm btn-outline-secondary" id="btnPdf">
            <i class="bi bi-file-earmark-pdf me-1"></i> PDF İndir
        </button>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print">
            <i class="bi bi-printer me-1"></i> Yazdır
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
            <div class="card-header bg-white">TEDARIKCI DAGILIMI</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="chartTed" style="max-height:220px"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Proje Bazlı Özet -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3 text-primary me-1"></i>Proje Bazlı m³</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="chartProje" style="max-height:230px"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3 text-primary me-1"></i>Proje Bazlı Özet</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Proje</th><th>Açıklama</th><th class="text-end">Parsel</th><th class="text-end">İrsaliye</th><th class="text-end">Toplam m³</th></tr></thead>
                    <tbody>
                    <?php $pToplamM3=0; $pToplamAdet=0; foreach ($projeBazli as $pr): $pToplamM3+=(float)$pr['toplam_m3']; $pToplamAdet+=(int)$pr['adet']; ?>
                        <tr>
                            <td><span class="badge bg-dark"><?= h($pr['proje_kod']) ?></span></td>
                            <td class="small text-muted"><?= h($pr['proje_ad']) ?></td>
                            <td class="text-end"><?= (int)$pr['parsel_sayisi'] ?></td>
                            <td class="text-end"><?= (int)$pr['adet'] ?></td>
                            <td class="text-end fw-semibold"><?= format_number($pr['toplam_m3'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$projeBazli): ?><tr><td colspan="5" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
                    </tbody>
                    <?php if ($projeBazli): ?><tfoot class="table-light fw-bold"><tr><td colspan="2" class="text-end">TOPLAM</td><td class="text-end"><?= array_sum(array_map(fn($r)=>(int)$r['parsel_sayisi'],$projeBazli)) ?></td><td class="text-end"><?= $pToplamAdet ?></td><td class="text-end"><?= format_number($pToplamM3,2) ?></td></tr></tfoot><?php endif; ?>
                </table>
            </div></div>
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

<!-- BETON SINIFI DAGILIMI -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold">BETON SINIFI DAGILIMI</div>
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

<!-- ExcelJS: tam formatlı xlsx üretimi -->
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<!-- PDF: CDN'siz, yeni pencere + print dialog -->
<!-- (pdfmake kaldırıldı — 5MB font dosyası yükleme sorunu) -->
<script>
// PHP'den gelen tam detay verisi
const RAPOR_DETAY = <?= json_encode(array_map(function($d) {
    return [
        'tarih'        => $d['tarih'] ?? '',
        'irsaliye_no'  => $d['irsaliye_no'] ?? '',
        'tip'          => $d['tip'] ?? '',
        'tedarikci'    => $d['tedarikci'] ?? '',
        'beton_sinifi' => $d['beton_sinifi'] ?? '',
        'miktar'       => (float)($d['miktar'] ?? 0),
        'birim'        => $d['birim'] ?? 'M3',
        'pompa'        => $d['pompa'] ?? '',
        'imalat_grup'  => $d['imalat_grup'] ?? '',
        'ana_is_kalemi'=> $d['ana_is_kalemi'] ?? '',
        'parsel'       => $d['parsel'] ?? '',
        'blok'         => $d['blok'] ?? '',
        'kot'          => $d['kot'] !== null ? $d['kot'] : '',
        'firma'        => $d['firma'] ?? '',
        'arac_plaka'   => $d['arac_plaka'] ?? '',
        'proje_kod'    => $d['proje_kod'] ?? '',
        'proje_ad'     => $d['proje_ad']  ?? '',
        'aciklama'     => $d['aciklama'] ?? '',
    ];
}, $detayRows), JSON_UNESCAPED_UNICODE) ?>;

const RAPOR_AYLIK   = <?= json_encode($aylikOzet ?? array_map(fn($i,$v)=>['ay'=>$ayAdlari[$i+1],'toplam_m3'=>$v,'adet'=>0], array_keys($chartM3), $chartM3), JSON_UNESCAPED_UNICODE) ?>;
const RAPOR_TED     = <?= json_encode($tedarikciOzet, JSON_UNESCAPED_UNICODE) ?>;
const RAPOR_BETON   = <?= json_encode($betonOzet, JSON_UNESCAPED_UNICODE) ?>;
const RAPOR_PROJE   = <?= json_encode($projeBazli, JSON_UNESCAPED_UNICODE) ?>;
const DONEM_ETIKETI = <?= json_encode($donemEtiketi) ?>;
</script>
<script>
(function () {
    const COLORS = ['#00584E','#00C9B1','#C9A84C','#007A6A','#00A896','#E05454','#3B82F6','#8B5CF6','#F59E0B','#10B981'];
    const dk = () => document.documentElement.getAttribute('data-dark') === '1';
    const gridColor  = () => dk() ? 'rgba(255,255,255,.07)' : 'rgba(0,88,78,.08)';
    const labelColor = () => dk() ? '#6EA89E' : '#4E7068';
    const ayAdlari   = <?= json_encode(array_values($ayAdlari)) ?>;

    // Aylık bar grafik
    new Chart(document.getElementById('chartAylik'), {
        type: 'bar',
        data: {
            labels: ayAdlari.slice(1),
            datasets: [{
                label: 'm³',
                data: <?= json_encode(array_values($chartM3)) ?>,
                backgroundColor: 'rgba(0,88,78,.75)',
                hoverBackgroundColor: 'rgba(0,168,150,.9)',
                borderRadius: 6,
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'm³', color: labelColor() },
                     grid: { color: gridColor() }, ticks: { color: labelColor() } },
                x: { grid: { color: gridColor() }, ticks: { color: labelColor() } }
            }
        }
    });

    // Tedarikçi doughnut grafik
    new Chart(document.getElementById('chartTed'), {
        type: 'doughnut',
        data: {
            labels: <?= $tedLabels ?>,
            datasets: [{ data: <?= $tedValues ?>, backgroundColor: COLORS, borderWidth: 0 }]
        },
        options: {
            plugins: { legend: { position: 'bottom', labels: { color: labelColor(), padding: 14, font: { size: 12 } } } },
            cutout: '62%'
        }
    });

    // Proje bazlı doughnut grafik
    const cvProje = document.getElementById('chartProje');
    if (cvProje) {
        new Chart(cvProje, {
            type: 'doughnut',
            data: {
                labels: <?= $projeLabels ?>,
                datasets: [{ data: <?= $projeValues ?>, backgroundColor: COLORS, borderWidth: 0 }]
            },
            options: {
                plugins: { legend: { position: 'bottom', labels: { color: labelColor(), padding: 14, font: { size: 12 } } } },
                cutout: '62%'
            }
        });
    }

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

// ── ERN Excel Renk Paleti ─────────────────────────────────────────────
const XL = {
    ernDark:    'FF003D35',
    ernGreen:   'FF00584E',
    ernLight:   'FF007A6A',
    ernTeal:    'FF00C9B1',
    ernTealBg:  'FFE8F5F3',
    ernGold:    'FFC9A84C',
    white:      'FFFFFFFF',
    lightGray:  'FFF5F9F8',
    midGray:    'FFE2EBE8',
    darkText:   'FF0D2E28',
    mutedText:  'FF4E7068',
    rowEven:    'FFF0F6F5',
    rowOdd:     'FFFFFFFF',
    totalBg:    'FFCCE8E4',
    red:        'FFE05454',
    blue:       'FF3B82F6',
};

function xlFill(argb) {
    return { type: 'pattern', pattern: 'solid', fgColor: { argb } };
}
function xlFont(opts) {
    return { name: 'Calibri', ...opts };
}
function xlBorder(style = 'thin', argb = 'FFD0DFDC') {
    const s = { style, color: { argb } };
    return { top: s, left: s, bottom: s, right: s };
}
function xlAlign(h = 'left', v = 'middle', wrap = false) {
    return { horizontal: h, vertical: v, wrapText: wrap };
}

async function exportExcel() {
    const btn = document.querySelector('[onclick="exportExcel()"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Oluşturuluyor...'; }

    try {
        const wb = new ExcelJS.Workbook();
        wb.creator   = 'ERN Holding — Beton Takip Sistemi';
        wb.company   = 'ERN Holding';
        wb.created   = new Date();
        wb.modified  = new Date();

        // ── SAYFA 1: Detay Rapor ─────────────────────────────────────────────
        await buildDetaySheet(wb);

        // ── SAYFA 2: Aylık Özet ──────────────────────────────────────────────
        await buildAylikSheet(wb);

        // ── SAYFA 3: Tedarikçi & Beton ───────────────────────────────────────
        await buildOzetSheet(wb);

        // İndir
        const buf  = await wb.xlsx.writeBuffer();
        const blob = new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = 'ERN_Beton_Rapor_' + new Date().toISOString().slice(0,10) + '.xlsx';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel\'e Aktar'; }
    }
}

// ── Detay Sayfası ────────────────────────────────────────────────────────────
async function buildDetaySheet(wb) {
    const ws = wb.addWorksheet('📋 Detay Rapor', {
        views: [{ state: 'frozen', ySplit: 6, xSplit: 0 }],
        pageSetup: { orientation: 'landscape', fitToPage: true, fitToWidth: 1 }
    });

    const cols = [
        { key: 'proje_kod',    header: 'Proje Kodu',   width: 12 },
        { key: 'proje_ad',     header: 'Proje Adı',    width: 28 },
        { key: 'tarih',        header: 'Tarih',        width: 13 },
        { key: 'irsaliye_no',  header: 'İrsaliye No',  width: 26 },
        { key: 'tip',          header: 'Tip',           width: 8  },
        { key: 'tedarikci',    header: 'Tedarikci',    width: 28 },
        { key: 'beton_sinifi', header: 'Beton Sinifi', width: 14 },
        { key: 'miktar',       header: 'Miktar (m3)',  width: 13 },
        { key: 'pompa',        header: 'Pompa',         width: 12 },
        { key: 'imalat_grup',  header: 'İmalat Grubu', width: 16 },
        { key: 'ana_is_kalemi',header: 'İş Kalemi',    width: 18 },
        { key: 'parsel',       header: 'Parsel',        width: 14 },
        { key: 'blok',         header: 'Blok',          width: 20 },
        { key: 'kot',          header: 'Kot',           width: 12 },
        { key: 'firma',        header: 'Firma',         width: 14 },
        { key: 'arac_plaka',   header: 'Araç Plaka',   width: 13 },
        { key: 'aciklama',     header: 'Açıklama',      width: 22 },
    ];
    ws.columns = cols;

    // ── Satır 1: ERN Logo Başlık ──────────────────────────────────────────────
    ws.mergeCells('A1:Q1');
    const r1 = ws.getRow(1);
    r1.height = 36;
    const c1 = ws.getCell('A1');
    c1.value     = 'ERN HOLDİNG  —  BETON TAKİP SİSTEMİ';
    c1.font      = xlFont({ bold: true, size: 16, color: { argb: XL.white } });
    c1.fill      = xlFill(XL.ernDark);
    c1.alignment = xlAlign('center');

    // ── Satır 2: Rapor Dönemi ─────────────────────────────────────────────────
    ws.mergeCells('A2:Q2');
    const r2 = ws.getRow(2);
    r2.height = 22;
    const c2 = ws.getCell('A2');
    const donem = DONEM_ETIKETI || 'Tum Donem';
    c2.value     = `Dönem: ${donem}   |   Toplam: ${toplamM3.toLocaleString('tr-TR',{minimumFractionDigits:2})} m³   |   ${toplamAdet} İrsaliye   |   Oluşturma: ${new Date().toLocaleDateString('tr-TR',{day:'2-digit',month:'long',year:'numeric'})}`;
    c2.font      = xlFont({ size: 10, color: { argb: XL.white } });
    c2.fill      = xlFill(XL.ernGreen);
    c2.alignment = xlAlign('center');

    // ── Satır 3: Boş ayırıcı ─────────────────────────────────────────────────
    ws.mergeCells('A3:Q3');
    ws.getCell('A3').fill = xlFill(XL.ernTealBg);
    ws.getRow(3).height = 6;

    // ── Satır 4-5: Boş (dondurulan alanı doldurmak için) ─────────────────────
    ws.getRow(4).height = 4;

    // ── Satır 5: Kolon Başlıkları ─────────────────────────────────────────────
    ws.getRow(5).height = 22;
    cols.forEach((col, ci) => {
        const cell = ws.getCell(5, ci + 1);
        cell.value     = col.header;
        cell.font      = xlFont({ bold: true, size: 10, color: { argb: XL.white } });
        cell.fill      = xlFill(XL.ernLight);
        cell.alignment = xlAlign(ci === 5 ? 'right' : 'center');
        cell.border    = xlBorder('thin', XL.ernDark);
    });

    // AutoFilter başlık satırında
    ws.autoFilter = { from: { row: 5, column: 1 }, to: { row: 5, column: cols.length } };

    // ── Veri Satırları ────────────────────────────────────────────────────────
    RAPOR_DETAY.forEach((d, i) => {
        const rowIdx = i + 6;
        const row    = ws.getRow(rowIdx);
        row.height   = 18;
        const even   = i % 2 === 0;
        const bgArgb = even ? XL.rowEven : XL.rowOdd;

        const vals = [
            d.proje_kod,
            d.proje_ad,
            d.tarih ? d.tarih.split('-').reverse().join('.') : '',
            d.irsaliye_no,
            d.tip === 'alis' ? 'Alış' : 'İade',
            d.tedarikci,
            d.beton_sinifi,
            d.miktar,
            d.pompa,
            d.imalat_grup,
            d.ana_is_kalemi,
            d.parsel,
            d.blok,
            d.kot,
            d.firma,
            d.arac_plaka,
            d.aciklama,
        ];
        vals.forEach((v, ci) => {
            const cell = ws.getCell(rowIdx, ci + 1);
            cell.value     = v !== '' ? v : null;
            cell.fill      = xlFill(bgArgb);
            cell.font      = xlFont({ size: 9.5, color: { argb: XL.darkText } });
            cell.border    = xlBorder('hair', XL.midGray);
            if (ci === 7) {
                // Miktar: sağa hizalı, sayı formatı
                cell.numFmt    = '#,##0.00';
                cell.alignment = xlAlign('right');
                cell.font      = xlFont({ bold: true, size: 9.5, color: { argb: XL.ernGreen } });
            } else {
                cell.alignment = xlAlign('left');
            }
            // Tip renklendirme
            if (ci === 4) {
                cell.font = xlFont({ size: 9.5, bold: true,
                    color: { argb: d.tip === 'alis' ? 'FF198754' : XL.red } });
            }
        });
    });

    // ── Toplam Satırı ─────────────────────────────────────────────────────────
    const totalRow = RAPOR_DETAY.length + 6;
    ws.getRow(totalRow).height = 22;
    ws.mergeCells(`A${totalRow}:G${totalRow}`);
    const tc = ws.getCell(`A${totalRow}`);
    tc.value     = `TOPLAM  (${RAPOR_DETAY.length} Kayıt)`;
    tc.font      = xlFont({ bold: true, size: 10, color: { argb: XL.ernDark } });
    tc.fill      = xlFill(XL.totalBg);
    tc.alignment = xlAlign('right');
    tc.border    = xlBorder('medium', XL.ernGreen);

    const miktarCell = ws.getCell(totalRow, 8);
    const toplamHesap = RAPOR_DETAY.reduce((s, d) => s + (d.miktar || 0), 0);
    miktarCell.value     = toplamHesap;
    miktarCell.numFmt    = '#,##0.00';
    miktarCell.font      = xlFont({ bold: true, size: 11, color: { argb: XL.ernDark } });
    miktarCell.fill      = xlFill(XL.totalBg);
    miktarCell.alignment = xlAlign('right');
    miktarCell.border    = xlBorder('medium', XL.ernGreen);

    for (let ci = 9; ci <= cols.length; ci++) {
        const c = ws.getCell(totalRow, ci);
        c.fill   = xlFill(XL.totalBg);
        c.border = xlBorder('medium', XL.ernGreen);
    }
}

// ── Aylık Özet Sayfası ───────────────────────────────────────────────────────
async function buildAylikSheet(wb) {
    const ws = wb.addWorksheet('📊 Aylık Özet');
    ws.columns = [
        { key: 'ay',     header: 'Ay',            width: 14 },
        { key: 'adet',   header: 'Irsaliye Adedi', width: 18 },
        { key: 'toplam', header: 'Miktar (m3)',    width: 18 },
    ];

    // Başlık
    ws.mergeCells('A1:C1');
    const h = ws.getCell('A1');
    h.value     = 'AYLIK m3 TUKETIM OZETI';
    h.font      = xlFont({ bold: true, size: 13, color: { argb: XL.white } });
    h.fill      = xlFill(XL.ernDark);
    h.alignment = xlAlign('center');
    ws.getRow(1).height = 28;

    ws.mergeCells('A2:C2');
    const h2 = ws.getCell('A2');
    h2.value  = DONEM_ETIKETI || 'Tum Donem';
    h2.font   = xlFont({ size: 10, color: { argb: XL.white } });
    h2.fill   = xlFill(XL.ernGreen);
    h2.alignment = xlAlign('center');
    ws.getRow(2).height = 18;

    // Kolon başlıkları
    ['Ay', 'Irsaliye Adedi', 'Miktar (m3)'].forEach((h, ci) => {
        const cell = ws.getCell(4, ci + 1);
        cell.value     = h;
        cell.font      = xlFont({ bold: true, size: 10, color: { argb: XL.white } });
        cell.fill      = xlFill(XL.ernLight);
        cell.alignment = xlAlign('center');
        cell.border    = xlBorder();
    });
    ws.getRow(4).height = 20;
    ws.autoFilter = { from: { row: 4, column: 1 }, to: { row: 4, column: 3 } };

    // Veri
    const ayAdlariList = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    let grandTotal = 0;
    RAPOR_AYLIK.forEach((a, i) => {
        const r = ws.getRow(i + 5);
        r.height = 18;
        const m3 = parseFloat(a.toplam_m3 || 0);
        grandTotal += m3;
        [a.ay, parseInt(a.adet||0), m3].forEach((v, ci) => {
            const cell = r.getCell(ci + 1);
            cell.value     = v;
            cell.fill      = xlFill(i % 2 === 0 ? XL.rowEven : XL.rowOdd);
            cell.font      = xlFont({ size: 10, color: { argb: XL.darkText } });
            cell.border    = xlBorder('hair', XL.midGray);
            if (ci === 2) { cell.numFmt = '#,##0.00'; cell.alignment = xlAlign('right'); }
            else cell.alignment = xlAlign('center');
        });
    });

    // Toplam
    const tr = RAPOR_AYLIK.length + 5;
    ws.mergeCells(`A${tr}:B${tr}`);
    const tc = ws.getCell(`A${tr}`);
    tc.value     = 'TOPLAM';
    tc.font      = xlFont({ bold: true, size: 10, color: { argb: XL.ernDark } });
    tc.fill      = xlFill(XL.totalBg);
    tc.alignment = xlAlign('right');
    tc.border    = xlBorder('medium', XL.ernGreen);
    const mc = ws.getCell(tr, 3);
    mc.value     = grandTotal;
    mc.numFmt    = '#,##0.00';
    mc.font      = xlFont({ bold: true, size: 11 });
    mc.fill      = xlFill(XL.totalBg);
    mc.alignment = xlAlign('right');
    mc.border    = xlBorder('medium', XL.ernGreen);
    ws.getRow(tr).height = 22;
}

// ════════════════════════════════════════════════════════════════════════════
// PDF DIŞA AKTARMA — jsPDF + AutoTable
// ════════════════════════════════════════════════════════════════════════════
// Türkçe → PDF-safe Latin normalizer (helvetica Türkçe desteklemez)
function pdfStr(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/İ/g,'I').replace(/ı/g,'i')
        .replace(/Ş/g,'S').replace(/ş/g,'s')
        .replace(/Ğ/g,'G').replace(/ğ/g,'g')
        .replace(/Ü/g,'U').replace(/ü/g,'u')
        .replace(/Ö/g,'O').replace(/ö/g,'o')
        .replace(/Ç/g,'C').replace(/ç/g,'c')
        .replace(/â/g,'a').replace(/î/g,'i').replace(/û/g,'u')
        .replace(/—/g,'-').replace(/–/g,'-')
        .replace(/â/g,'a');
}
function pdfArr(arr) {
    // Dizi veya dizi-of-dizisi içindeki tüm stringleri normalize eder
    if (!arr) return arr;
    return arr.map(row => Array.isArray(row)
        ? row.map(cell => {
            if (cell && typeof cell === 'object' && cell.content !== undefined) {
                return { ...cell, content: pdfStr(cell.content) };
            }
            return typeof cell === 'string' ? pdfStr(cell) : cell;
          })
        : (typeof row === 'string' ? pdfStr(row) : row)
    );
}

// ═══════════════════════════════════════════════════════════════
// PDF — Yeni pencere + print dialog (CDN yok, anında, tam Türkçe)
// ═══════════════════════════════════════════════════════════════
function exportPDF() {
    var btn = document.getElementById('btnPdf');
    if (btn) { btn.disabled = true; btn.textContent = 'Oluşturuluyor...'; }

    function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function fmt(n){ return parseFloat(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    var donem = DONEM_ETIKETI || 'Tüm Dönem';
    var tarih = new Date().toLocaleDateString('tr-TR',{day:'2-digit',month:'long',year:'numeric'});
    var toplam = RAPOR_DETAY.reduce(function(s,d){ return s+(d.miktar||0); },0);

    var detayRows = RAPOR_DETAY.map(function(d,i){
        var bg  = i%2===0?'#F0F6F5':'#FFFFFF';
        var tc  = d.tip==='alis'?'#198754':'#dc3545';
        var pb  = [d.parsel,d.blok].filter(Boolean).join(' / ');
        return '<tr style="background:'+bg+'">'
            +'<td style="text-align:center;font-size:7pt;font-weight:700">'+escH(d.proje_kod)+'</td>'
            +'<td style="font-size:7pt">'+escH(d.proje_ad)+'</td>'
            +'<td style="text-align:center">'+(d.tarih?d.tarih.split('-').reverse().join('.'):'')+'</td>'
            +'<td style="font-size:7pt;font-family:monospace">'+escH(d.irsaliye_no)+'</td>'
            +'<td style="text-align:center;font-weight:700;color:'+tc+'">'+(d.tip==='alis'?'Alış':'İade')+'</td>'
            +'<td>'+escH(d.tedarikci)+'</td>'
            +'<td style="text-align:center">'+escH(d.beton_sinifi)+'</td>'
            +'<td style="text-align:right;font-weight:700;color:#00584E">'+fmt(d.miktar)+'</td>'
            +'<td style="text-align:center;font-size:7pt">'+escH(d.pompa)+'</td>'
            +'<td style="font-size:7pt">'+escH(d.imalat_grup)+'</td>'
            +'<td style="font-size:7pt">'+escH(pb)+'</td>'
            +'<td style="text-align:center;font-size:7pt">'+escH(d.arac_plaka)+'</td></tr>';
    }).join('');

    var aylikRows = RAPOR_AYLIK.map(function(a,i){
        var bg=i%2===0?'#F0F6F5':'#FFFFFF';
        return '<tr style="background:'+bg+'"><td>'+escH(a.ay)+'</td>'
            +'<td style="text-align:right">'+parseInt(a.adet||0)+'</td>'
            +'<td style="text-align:right;font-weight:700;color:#00584E">'+fmt(a.toplam_m3)+'</td></tr>';
    }).join('');

    var tedRows = RAPOR_TED.map(function(t,i){
        var bg=i%2===0?'#F0F6F5':'#FFFFFF';
        return '<tr style="background:'+bg+'"><td>'+escH(t.ad||'—')+'</td>'
            +'<td style="text-align:right;font-weight:700;color:#00584E">'+fmt(t.toplam_m3)+'</td></tr>';
    }).join('');

    var betonRows = RAPOR_BETON.map(function(b,i){
        var bg=i%2===0?'#F0F6F5':'#FFFFFF';
        var pct = toplamM3>0?((parseFloat(b.toplam_m3||0)/toplamM3)*100).toFixed(1):'0.0';
        return '<tr style="background:'+bg+'"><td>'+escH(b.sinif||'—')+'</td>'
            +'<td style="text-align:right;font-weight:700;color:#00584E">'+fmt(b.toplam_m3)+'</td>'
            +'<td style="text-align:center;color:#4E7068">%'+pct+'</td></tr>';
    }).join('');

    var css = '<style>'
        +'*{margin:0;padding:0;box-sizing:border-box}'
        +'body{font-family:Arial,sans-serif;font-size:8pt;color:#0D2E28;background:#fff}'
        +'.page{padding:8mm 8mm 14mm}'
        +'.hdr{background:#003D35;color:#fff;text-align:center;padding:7px;font-size:13pt;font-weight:700;border-radius:4px 4px 0 0}'
        +'.sub{background:#00584E;color:#fff;text-align:center;padding:4px;font-size:8pt;border-radius:0 0 4px 4px;margin-bottom:8px}'
        +'.sec{font-size:10pt;font-weight:700;color:#003D35;margin:10px 0 4px;border-bottom:1.5px solid #00C9B1;padding-bottom:3px}'
        +'table{width:100%;border-collapse:collapse;font-size:7.5pt}'
        +'th{background:#007A6A;color:#fff;font-weight:700;padding:4px 5px;text-align:left}'
        +'td{padding:3px 5px;border-bottom:0.3px solid #C8DDD9;vertical-align:middle}'
        +'.tot{background:#CCE8E4;font-weight:700;font-size:8.5pt;color:#003D35}'
        +'.cols{display:flex;gap:10px;margin-top:8px}'
        +'.cols>div{flex:1;min-width:0}'
        // Toolbar stilleri
        +'.toolbar{position:sticky;top:0;z-index:999;background:#003D35;padding:10px 16px;display:flex;align-items:center;gap:10px;box-shadow:0 2px 8px rgba(0,0,0,.3)}'
        +'.toolbar-title{color:rgba(255,255,255,.7);font-size:9pt;flex:1}'
        +'.tbtn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border:none;border-radius:8px;font-size:9pt;font-weight:700;cursor:pointer;transition:.2s;text-decoration:none}'
        +'.tbtn:hover{transform:translateY(-1px);opacity:.9}'
        +'.tbtn-print{background:#00C9B1;color:#003D35}'
        +'.tbtn-save{background:#fff;color:#003D35}'
        +'.tbtn-close{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25)}'
        +'.tbtn-close:hover{background:rgba(255,80,80,.25);color:#fff}'
        +'@media print{'
        +'body{-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        +'.toolbar{display:none!important}'
        +'.p2{page-break-before:always}'
        +'}'
        +'</style>';

    var h = '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>ERN Beton Raporu</title>'+css+'</head><body>'
        // Toolbar — yazdır/kaydet/kapat butonları
        +'<div class="toolbar">'
        +'<div class="toolbar-title">ERN Holding — Beton Takip Raporu &nbsp;|&nbsp; '+escH(donem)+'</div>'
        +'<button class="tbtn tbtn-print" onclick="window.print()">'
        +'<svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M5 1a2 2 0 0 0-2 2v1h10V3a2 2 0 0 0-2-2H5zm6 8H5a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1z"/><path d="M0 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-1v-2a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2H2a2 2 0 0 1-2-2V7zm2.5 1a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/></svg>'
        +' Yazdır</button>'
        +'<button id="savePdfBtn" class="tbtn tbtn-save" onclick="savePDF()">'
        +'<svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>'
        +' PDF Kaydet</button>'
        +'<button class="tbtn tbtn-close" onclick="window.close()">'
        +'<svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8 2.146 2.854z"/></svg>'
        +' Kapat</button>'
        +'</div>'
        // Sayfa 1
        +'<div class="page">'
        +'<div class="hdr">ERN HOLDİNG — BETON TAKİP SİSTEMİ</div>'
        +'<div class="sub">Dönem: '+escH(donem)+'  |  Toplam: '+fmt(toplam)+' m³  |  '+RAPOR_DETAY.length+' İrsaliye  |  '+escH(tarih)+'</div>'
        +'<div class="sec">📋  Detay Rapor</div>'
        +'<table><thead><tr>'
        +'<th style="width:22px;text-align:center">Proje Kodu</th>'
        +'<th style="width:70px">Proje Adı</th>'
        +'<th style="width:32px;text-align:center">Tarih</th>'
        +'<th style="width:80px">İrsaliye No</th>'
        +'<th style="width:20px;text-align:center">Tip</th>'
        +'<th style="width:70px">Tedarikçi</th>'
        +'<th style="width:26px;text-align:center">Beton</th>'
        +'<th style="width:26px;text-align:right">m³</th>'
        +'<th style="width:36px;text-align:center">Pompa</th>'
        +'<th style="width:55px">İmalat</th>'
        +'<th style="width:70px">Parsel/Blok</th>'
        +'<th style="width:34px;text-align:center">Plaka</th>'
        +'</tr></thead><tbody>'+detayRows+'</tbody>'
        +'<tfoot><tr class="tot">'
        +'<td colspan="7" style="text-align:right">TOPLAM ('+RAPOR_DETAY.length+' Kayıt)</td>'
        +'<td style="text-align:right">'+fmt(toplam)+'</td>'
        +'<td colspan="4"></td></tr></tfoot></table></div>'
        // Sayfa 2
        +'<div class="page p2">'
        +'<div class="hdr">ERN HOLDİNG — BETON TAKİP SİSTEMİ</div>'
        +'<div class="sub">Özet Rapor  |  Dönem: '+escH(donem)+'  |  '+escH(tarih)+'</div>'
        +'<div class="cols">'
        +'<div><div class="sec">📊 Aylık m³</div>'
        +'<table><thead><tr><th>Ay</th><th style="text-align:right">Adet</th><th style="text-align:right">m³</th></tr></thead>'
        +'<tbody>'+aylikRows+'</tbody>'
        +'<tfoot><tr class="tot"><td colspan="2" style="text-align:right">Toplam</td><td style="text-align:right">'+fmt(toplamM3)+'</td></tr></tfoot></table></div>'
        +'<div><div class="sec">🚛 Tedarikçi</div>'
        +'<table><thead><tr><th>Tedarikçi</th><th style="text-align:right">m³</th></tr></thead>'
        +'<tbody>'+tedRows+'</tbody></table></div>'
        +'<div><div class="sec">🏗️ Beton Sınıfı</div>'
        +'<table><thead><tr><th>Sınıf</th><th style="text-align:right">m³</th><th style="text-align:center">%</th></tr></thead>'
        +'<tbody>'+betonRows+'</tbody></table></div>'
        +'</div>'
        +'<div style="position:fixed;bottom:5mm;left:8mm;right:8mm;display:flex;justify-content:space-between;font-size:7pt;color:#4E7068;border-top:0.5px solid #C8DDD9;padding-top:2px">'
        +'<span>ERN Holding — Beton Takip Sistemi</span><span>'+escH(tarih)+'</span><span>Geliştirici: Tayyar Akbulut</span>'
        +'</div></div>'
        // html2canvas + jsPDF: direkt PDF kaydet (dialog yok)
        +'<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"><\/script>'
        +'<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"><\/script>'
        +'<script>'
        +'async function savePDF(){'
        +'  var btn=document.getElementById("savePdfBtn");'
        +'  if(btn){btn.disabled=true;btn.textContent="Kaydediliyor...";}'
        +'  try{'
        +'    var jsPDF=window.jspdf.jsPDF;'
        +'    var doc=new jsPDF({orientation:"landscape",unit:"mm",format:"a4"});'
        +'    var pages=document.querySelectorAll(".page, .page2");'
        +'    for(var i=0;i<pages.length;i++){'
        +'      if(i>0) doc.addPage();'
        +'      var canvas=await html2canvas(pages[i],{'
        +'        scale:2, useCORS:true, logging:false,'
        +'        backgroundColor:"#ffffff",'
        +'        windowWidth:1100'
        +'      });'
        +'      var img=canvas.toDataURL("image/jpeg",0.92);'
        +'      var pw=doc.internal.pageSize.getWidth();'
        +'      var ph=doc.internal.pageSize.getHeight();'
        +'      doc.addImage(img,"JPEG",0,0,pw,ph);'
        +'    }'
        +'    doc.save("ERN_Beton_Rapor_"+new Date().toISOString().slice(0,10)+".pdf");'
        +'  } catch(e){'
        +'    alert("PDF kaydedilemedi: "+e.message);'
        +'  } finally {'
        +'    if(btn){btn.disabled=false;btn.innerHTML=\'<svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg> PDF Kaydet\';}'
        +'  }'
        +'}'
        +'<\/script>'
        +'</body></html>';

    var win = window.open('','_blank','width=1200,height=900,scrollbars=yes');
    if (!win) {
        alert('Pop-up engellendi. Tarayıcı adres çubuğundaki izin ikonuna tıklayın.');
        if (btn){ btn.disabled=false; btn.innerHTML='<i class="bi bi-file-earmark-pdf me-1"></i> PDF İndir'; }
        return;
    }
    win.document.open();
    win.document.write(h);
    win.document.close();
    win.focus();
    // Otomatik print kaldırıldı — kullanıcı kendi butonuyla başlatır
    if (btn){ btn.disabled=false; btn.innerHTML='<i class="bi bi-file-earmark-pdf me-1"></i> PDF İndir'; }
}

async function buildOzetSheet(wb) {
    const ws = wb.addWorksheet('📈 Tedarikçi & Beton');
    ws.columns = [
        { key: 'a', width: 32 },
        { key: 'b', width: 16 },
        { key: 'c', width: 6 },
        { key: 'd', width: 32 },
        { key: 'e', width: 16 },
    ];

    function sectionHeader(row, col, text, span, bgArgb) {
        const endCol = String.fromCharCode(64 + col + span - 1);
        const startCell = String.fromCharCode(64 + col);
        ws.mergeCells(`${startCell}${row}:${endCol}${row}`);
        const c = ws.getCell(`${startCell}${row}`);
        c.value     = text;
        c.font      = xlFont({ bold: true, size: 11, color: { argb: XL.white } });
        c.fill      = xlFill(bgArgb);
        c.alignment = xlAlign('center');
        ws.getRow(row).height = 22;
    }

    function colHeaders(row, col, headers, bgArgb) {
        headers.forEach((h, i) => {
            const c = ws.getCell(row, col + i);
            c.value     = h;
            c.font      = xlFont({ bold: true, size: 10, color: { argb: XL.white } });
            c.fill      = xlFill(bgArgb);
            c.alignment = xlAlign('center');
            c.border    = xlBorder();
        });
        ws.getRow(row).height = 18;
    }

    // Tedarikçi (sol)
    sectionHeader(1, 1, 'TEDARIKCI DAGILIMI', 2, XL.ernDark);
    colHeaders(2, 1, ['Tedarikci', 'Miktar (m3)'], XL.ernLight);
    RAPOR_TED.forEach((t, i) => {
        const r = ws.getRow(i + 3);
        r.height = 18;
        [t.ad || '—', parseFloat(t.toplam_m3||0)].forEach((v, ci) => {
            const c = r.getCell(1 + ci);
            c.value     = v;
            c.fill      = xlFill(i % 2 === 0 ? XL.rowEven : XL.rowOdd);
            c.font      = xlFont({ size: 10 });
            c.border    = xlBorder('hair', XL.midGray);
            if (ci === 1) { c.numFmt = '#,##0.00'; c.alignment = xlAlign('right'); c.font = xlFont({ bold: true, size: 10, color: { argb: XL.ernGreen } }); }
        });
    });

    // Beton Sınıfı (sağ — 5. kolundan başla)
    sectionHeader(1, 4, 'BETON SINIFI DAGILIMI', 2, XL.ernGreen);
    colHeaders(2, 4, ['Beton Sinifi', 'Miktar (m3)'], XL.ernLight);
    RAPOR_BETON.forEach((b, i) => {
        const r = ws.getRow(i + 3);
        r.height = 18;
        [b.sinif || '—', parseFloat(b.toplam_m3||0)].forEach((v, ci) => {
            const c = r.getCell(4 + ci);
            c.value  = v;
            c.fill   = xlFill(i % 2 === 0 ? XL.rowEven : XL.rowOdd);
            c.font   = xlFont({ size: 10 });
            c.border = xlBorder('hair', XL.midGray);
            if (ci === 1) { c.numFmt = '#,##0.00'; c.alignment = xlAlign('right'); c.font = xlFont({ bold: true, size: 10, color: { argb: XL.ernGreen } }); }
        });
    });

    // Proje bazlı özet (altta, tam genişlik)
    const pBas = Math.max(RAPOR_TED.length, RAPOR_BETON.length) + 4;
    sectionHeader(pBas, 1, 'PROJE BAZLI OZET', 5, XL.ernTeal);
    colHeaders(pBas + 1, 1, ['Proje', 'Aciklama', 'Parsel', 'Irsaliye', 'Toplam m3'], XL.ernLight);
    RAPOR_PROJE.forEach((p, i) => {
        const r = ws.getRow(pBas + 2 + i);
        r.height = 18;
        [p.proje_kod || '—', p.proje_ad || '', parseInt(p.parsel_sayisi||0), parseInt(p.adet||0), parseFloat(p.toplam_m3||0)].forEach((v, ci) => {
            const c = r.getCell(1 + ci);
            c.value  = v;
            c.fill   = xlFill(i % 2 === 0 ? XL.rowEven : XL.rowOdd);
            c.font   = xlFont({ size: 10 });
            c.border = xlBorder('hair', XL.midGray);
            if (ci >= 2) c.alignment = xlAlign('right');
            if (ci === 4) { c.numFmt = '#,##0.00'; c.font = xlFont({ bold: true, size: 10, color: { argb: XL.ernGreen } }); }
        });
    });
}
</script>

<!-- ── AI Asistan ─────────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-stars text-primary"></i> AI Asistan
        <span class="badge bg-primary-subtle text-primary fw-normal small">beta</span>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <!-- Anomali Tespiti -->
            <div class="col-md-5">
                <p class="small fw-semibold mb-1">Anomali Tespiti</p>
                <p class="small text-muted mb-2">Mükerrer kayıtlar, anormal miktarlar ve eksik proje bilgilerini otomatik tarar.</p>
                <button type="button" id="btnAnomaliTara" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-bug me-1"></i> Anomali Tara
                </button>
                <div id="anomaliYukleniyor" class="d-none mt-2 small text-muted">
                    <span class="spinner-border spinner-border-sm me-1"></span> Taranıyor...
                </div>
                <div id="anomaliSonuc" class="mt-3"></div>
            </div>
            <!-- Rapor Asistanı -->
            <div class="col-md-7 border-start ps-md-4">
                <p class="small fw-semibold mb-1">Rapor Asistanı</p>
                <div id="aiSohbet" class="border rounded p-2 mb-2 overflow-auto"
                     style="min-height:90px;max-height:260px;background:#f8f9fa;font-size:.85rem;">
                    <span class="text-muted">Veri hakkında bir soru sorun. Örn: <em>Hangi tedarikçiden en çok beton aldık?</em></span>
                </div>
                <div class="input-group input-group-sm">
                    <input type="text" id="aiSoruInput" class="form-control"
                           placeholder="Sorunuzu yazın...">
                    <button type="button" id="btnAiSor" class="btn btn-primary">
                        <i class="bi bi-send"></i>
                    </button>
                </div>
                <div id="aiSorYukleniyor" class="d-none mt-1 small text-muted">
                    <span class="spinner-border spinner-border-sm me-1"></span> Yanıt hazırlanıyor...
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ── Anomali Tespiti ────────────────────────────────────────────────────────
document.getElementById('btnAnomaliTara').addEventListener('click', async function () {
    const sonucDiv   = document.getElementById('anomaliSonuc');
    const yukleniyor = document.getElementById('anomaliYukleniyor');
    sonucDiv.innerHTML = '';
    yukleniyor.classList.remove('d-none');
    this.disabled = true;
    try {
        const fd = new FormData();
        fd.append('action', 'anomali');
        const res  = await fetch('api/ai_rapor.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (!json.ok) {
            sonucDiv.innerHTML = `<div class="alert alert-danger small py-2">${json.msg}</div>`;
            return;
        }
        if (json.temiz) {
            sonucDiv.innerHTML = '<div class="alert alert-success small py-2"><i class="bi bi-check-circle me-1"></i> Anomali tespit edilmedi.</div>';
            return;
        }
        let html = '';
        for (const a of json.anomaliler) {
            html += `<div class="alert alert-${a.renk} small py-2 mb-2">
                <strong>${a.tip}</strong>
                <ul class="mb-0 mt-1 ps-3">${a.satirlar.map(s => `<li>${s}</li>`).join('')}</ul>
            </div>`;
        }
        sonucDiv.innerHTML = html;
    } catch (e) {
        sonucDiv.innerHTML = `<div class="alert alert-danger small py-2">Bağlantı hatası: ${e.message}</div>`;
    } finally {
        yukleniyor.classList.add('d-none');
        this.disabled = false;
    }
});

// ── Rapor Asistanı ─────────────────────────────────────────────────────────
async function aiSoruGonder() {
    const input      = document.getElementById('aiSoruInput');
    const sohbet     = document.getElementById('aiSohbet');
    const yukleniyor = document.getElementById('aiSorYukleniyor');
    const btn        = document.getElementById('btnAiSor');
    const soru       = input.value.trim();
    if (!soru) return;

    // İlk mesajda placeholder'ı temizle
    if (sohbet.querySelector('span.text-muted')) sohbet.innerHTML = '';

    sohbet.innerHTML += `<div class="text-end mb-1"><span class="badge bg-primary-subtle text-primary">${soru}</span></div>`;
    input.value = '';
    yukleniyor.classList.remove('d-none');
    btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('action', 'soru');
        fd.append('soru', soru);
        const res  = await fetch('api/ai_rapor.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (!json.ok) {
            sohbet.innerHTML += `<div class="mb-2 text-danger"><i class="bi bi-x-circle me-1"></i>${json.msg}</div>`;
        } else {
            sohbet.innerHTML += `<div class="mb-2"><i class="bi bi-stars text-primary me-1"></i>${json.cevap.replace(/\n/g,'<br>')}</div>`;
        }
    } catch (e) {
        sohbet.innerHTML += `<div class="mb-2 text-danger">Bağlantı hatası</div>`;
    } finally {
        yukleniyor.classList.add('d-none');
        btn.disabled = false;
        sohbet.scrollTop = sohbet.scrollHeight;
    }
}

document.getElementById('btnAiSor').addEventListener('click', aiSoruGonder);
document.getElementById('aiSoruInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); aiSoruGonder(); }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
