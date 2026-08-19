<?php
ob_start();
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

// ── Tekil silme ──────────────────────────────────────────────────────────────
if (can_edit() && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $chk = $pdo->prepare("SELECT id FROM irsaliyeler WHERE id=?" . ($tip !== 'tum' ? " AND tip=?" : ""));
    $tip !== 'tum' ? $chk->execute([(int)$_GET['sil'], $tip]) : $chk->execute([(int)$_GET['sil']]);
    if ($chk->fetch()) {
        $pdo->prepare("DELETE FROM irsaliyeler WHERE id=?")->execute([(int)$_GET['sil']]);
        flash('success', 'İrsaliye silindi.');
    }
    redirect("irsaliyeler.php?tip={$tip}");
}

// ── Toplu işlemler (POST) ─────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['toplu_islem'])) {
    $topluIslem = $_POST['toplu_islem'];
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['secim'] ?? [])), fn($v) => $v > 0));
    $uid = current_user_id();

    if (!$ids) {
        flash('warning', 'İşlem için kayıt seçmediniz.');
        redirect("irsaliyeler.php?tip={$tip}");
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));

    if ($topluIslem === 'sil' && can_edit()) {
        $pdo->prepare("DELETE FROM irsaliyeler WHERE id IN ($ph)")->execute($ids);
        flash('success', count($ids) . ' irsaliye silindi.');

    } elseif ($topluIslem === 'saha_onayla' && can_approve_saha()) {
        // Sadece beklemede olanları, projesi dolu olanları onayla
        $rows = $pdo->prepare("SELECT id, proje_id FROM irsaliyeler WHERE id IN ($ph) AND durum='beklemede'");
        $rows->execute($ids);
        $onaylandi = 0; $projeSiz = 0;
        foreach ($rows->fetchAll() as $r) {
            if (empty($r['proje_id'])) { $projeSiz++; continue; }
            $pdo->prepare("UPDATE irsaliyeler SET durum='saha_onaylandi', saha_onaylayan_id=?, saha_onay_tarih=NOW() WHERE id=?")->execute([$uid, $r['id']]);
            $onaylandi++;
        }
        $msg = $onaylandi . ' irsaliye saha onayı verildi.';
        if ($projeSiz) $msg .= " {$projeSiz} irsaliye atlandı (proje seçilmemiş).";
        flash($onaylandi ? 'success' : 'warning', $msg);

    } elseif ($topluIslem === 'teknik_onayla' && can_approve_teknik()) {
        // beklemede veya saha_onaylandi olanları, projesi dolu olanları onayla
        $rows = $pdo->prepare("SELECT id, proje_id FROM irsaliyeler WHERE id IN ($ph) AND durum IN ('beklemede','saha_onaylandi')");
        $rows->execute($ids);
        $onaylandi = 0; $projeSiz = 0;
        foreach ($rows->fetchAll() as $r) {
            if (empty($r['proje_id'])) { $projeSiz++; continue; }
            $pdo->prepare("UPDATE irsaliyeler SET durum='onaylandi', teknik_onaylayan_id=?, teknik_onay_tarih=NOW() WHERE id=?")->execute([$uid, $r['id']]);
            $onaylandi++;
        }
        $msg = $onaylandi . ' irsaliye teknik onay verildi.';
        if ($projeSiz) $msg .= " {$projeSiz} irsaliye atlandı (proje seçilmemiş).";
        flash($onaylandi ? 'success' : 'warning', $msg);

    } elseif ($topluIslem === 'guncelle' && can_edit()) {
        // Toplu güncelleme — yalnız doldurulan (seçilen) alanlar değişir.
        // Proje/Parsel/Blok/Kot: değer varsa set; '' ise dokunma.
        // Açıklama: yalnız "aciklama_guncelle" işaretliyse set (boşa da çekilebilir).
        $sets = []; $vals = [];
        $bpr = ($_POST['bulk_proje_id']  ?? '') !== '' && ctype_digit((string)$_POST['bulk_proje_id'])  ? (int)$_POST['bulk_proje_id']  : null;
        $bpa = ($_POST['bulk_parsel_id'] ?? '') !== '' && ctype_digit((string)$_POST['bulk_parsel_id']) ? (int)$_POST['bulk_parsel_id'] : null;
        $bbl = ($_POST['bulk_blok_id']   ?? '') !== '' && ctype_digit((string)$_POST['bulk_blok_id'])   ? (int)$_POST['bulk_blok_id']   : null;
        $bko = ($_POST['bulk_kot_id']    ?? '') !== '' && ctype_digit((string)$_POST['bulk_kot_id'])    ? (int)$_POST['bulk_kot_id']    : null;
        if ($bpr !== null) { $sets[]='proje_id=?';  $vals[]=$bpr; }
        if ($bpa !== null) { $sets[]='parsel_id=?'; $vals[]=$bpa; }
        if ($bbl !== null) { $sets[]='blok_id=?';   $vals[]=$bbl; }
        if ($bko !== null) { $sets[]='kot_id=?';    $vals[]=$bko; }
        if (isset($_POST['aciklama_guncelle'])) { $sets[]='aciklama=?'; $vals[]=trim((string)($_POST['bulk_aciklama'] ?? '')) ?: null; }
        if (!$sets) {
            flash('warning', 'Güncellenecek alan seçmediniz.');
        } else {
            $sql = "UPDATE irsaliyeler SET ".implode(', ', $sets)." WHERE id IN ($ph)";
            $pdo->prepare($sql)->execute(array_merge($vals, $ids));
            flash('success', count($ids).' irsaliye güncellendi ('.count($sets).' alan).');
        }

    } else {
        flash('warning', 'Yetkisiz işlem.');
    }
    redirect("irsaliyeler.php?tip={$tip}");
}

// ── Filtreler ─────────────────────────────────────────────────────────────────
$filtreTed    = isset($_GET['tedarikci'])   && ctype_digit($_GET['tedarikci'])   ? (int)$_GET['tedarikci']   : 0;
$filtreParsel = isset($_GET['parsel'])      && ctype_digit($_GET['parsel'])      ? (int)$_GET['parsel']      : 0;
$filtreBlok   = isset($_GET['blok'])        && ctype_digit($_GET['blok'])        ? (int)$_GET['blok']        : 0;
$filtreBS     = isset($_GET['beton'])       && ctype_digit($_GET['beton'])       ? (int)$_GET['beton']       : 0;
$filtreProje  = isset($_GET['proje_id'])    && ctype_digit($_GET['proje_id'])    ? (int)$_GET['proje_id']    : 0;
$filtreYil    = isset($_GET['yil'])         && ctype_digit($_GET['yil'])         ? (int)$_GET['yil']         : 0;
$filtreAy     = isset($_GET['ay'])          && ctype_digit($_GET['ay'])          ? (int)$_GET['ay']          : 0;
$filtreArama  = trim($_GET['ara'] ?? '');
$DURUMLAR     = ['beklemede'=>'Beklemede','saha_onaylandi'=>'Saha Onaylı','onaylandi'=>'Teknik Onaylı','reddedildi'=>'Reddedildi'];
$filtreDurum  = isset($_GET['durum']) && isset($DURUMLAR[$_GET['durum']]) ? $_GET['durum'] : '';
$tarihBas     = isset($_GET['tarih_bas']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tarih_bas']) ? $_GET['tarih_bas'] : '';
$tarihBit     = isset($_GET['tarih_bit']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tarih_bit']) ? $_GET['tarih_bit'] : '';
$ihrac        = isset($_GET['export']) && in_array($_GET['export'], ['csv','xlsx'], true) ? $_GET['export'] : '';
// Fatura Eşleştirme ekranından gelen bağ (irsaliyeler.fatura_id kolonu runtime eklenir)
$filtreFatura = isset($_GET['fatura_id']) && ctype_digit((string)$_GET['fatura_id']) ? (int)$_GET['fatura_id'] : 0;
if ($filtreFatura && !$pdo->query("SHOW COLUMNS FROM irsaliyeler LIKE 'fatura_id'")->fetch()) $filtreFatura = 0;

$where  = $tip !== 'tum' ? ['i.tip = ?'] : [];
$params = $tip !== 'tum' ? [$tip] : [];

if ($filtreTed)    { $where[] = 'i.tedarikci_id = ?';     $params[] = $filtreTed; }
if ($filtreParsel) { $where[] = 'i.parsel_id = ?';        $params[] = $filtreParsel; }
if ($filtreBlok)   { $where[] = 'i.blok_id = ?';          $params[] = $filtreBlok; }
if ($filtreBS)     { $where[] = 'i.beton_sinifi_id = ?';  $params[] = $filtreBS; }
if ($filtreProje)  { $where[] = 'i.proje_id = ?';         $params[] = $filtreProje; }
if ($filtreDurum)  { $where[] = 'i.durum = ?';            $params[] = $filtreDurum; }
if ($filtreFatura) { $where[] = 'i.fatura_id = ?';       $params[] = $filtreFatura; }
if ($filtreYil)    { $where[] = 'YEAR(i.tarih) = ?';      $params[] = $filtreYil; }
if ($filtreAy)     { $where[] = 'MONTH(i.tarih) = ?';     $params[] = $filtreAy; }
if ($tarihBas !== '') { $where[] = 'i.tarih >= ?';        $params[] = $tarihBas; }
if ($tarihBit !== '') { $where[] = 'i.tarih <= ?';        $params[] = $tarihBit; }
if ($filtreArama)  { $where[] = '(i.irsaliye_no LIKE ? OR i.arac_plaka LIKE ? OR i.fatura_no LIKE ?)';
                     $params[] = "%{$filtreArama}%"; $params[] = "%{$filtreArama}%"; $params[] = "%{$filtreArama}%"; }

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Sıralama (whitelist — SQL injection güvenli) ──────────────────────────────
$sortMap = [
    'durum'     => 'i.durum',
    'tarih'     => 'i.tarih',
    'irsaliye'  => 'i.irsaliye_no',
    'plaka'     => 'i.arac_plaka',
    'tedarikci' => 't.ad',
    'beton'     => 'bs.ad',
    'parsel'    => 'par.ad',
    'pompa'     => 'pt.ad',
    'firma'     => 'fr.ad',
    'miktar'    => 'i.miktar',
];
$sort = isset($_GET['sort']) && isset($sortMap[$_GET['sort']]) ? $_GET['sort'] : '';
$dir  = (($_GET['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';
$orderSQL = $sort
    ? "ORDER BY {$sortMap[$sort]} {$dir}, i.id DESC"
    : "ORDER BY i.tarih DESC, i.sira_no DESC, i.id DESC";

// Sıralama linkleri için korunacak query parametreleri (sort/dir hariç)
$qsKeep = $_GET;
unset($qsKeep['sort'], $qsKeep['dir'], $qsKeep['export'], $qsKeep['sil']);

/** Sıralanabilir tablo başlığı (<a>) üretir */
function sortBaslik(string $key, string $label): string {
    global $sort, $dir, $qsKeep;
    $aktif   = ($sort === $key);
    $nextDir = ($aktif && $dir === 'ASC') ? 'desc' : 'asc';
    $icon    = !$aktif ? 'bi-chevron-expand opacity-25'
             : ($dir === 'ASC' ? 'bi-caret-up-fill text-primary' : 'bi-caret-down-fill text-primary');
    $url = '?' . http_build_query(array_merge($qsKeep, ['sort' => $key, 'dir' => $nextDir]));
    return '<a href="' . h($url) . '" class="text-reset text-decoration-none d-inline-flex align-items-center gap-1" style="white-space:nowrap">'
         . h($label) . ' <i class="bi ' . $icon . '" style="font-size:.7rem"></i></a>';
}

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
           kot.kot_degeri,
           pr.kod AS proje_kod
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
    LEFT JOIN projeler pr         ON pr.id  = i.proje_id
    {$whereSQL}
    {$orderSQL}
");
$stmt->execute($params);
$liste = $stmt->fetchAll();

$toplamM3 = array_sum(array_column($liste, 'miktar'));

// ── Excel (.xlsx) dışa aktarma ───────────────────────────────────────────────
if ($ihrac === 'xlsx') {
    require_once __DIR__ . '/includes/XlsxWriter.php';

    $tipLabel = $tip === 'alis' ? 'Alis' : ($tip === 'iade' ? 'Iade' : 'Tum');
    $fname    = 'irsaliyeler_' . $tipLabel . '_' . date('Ymd_His') . '.xlsx';

    $durumLabel = [
        'beklemede'      => 'Beklemede',
        'saha_onaylandi' => 'Saha Onayı',
        'onaylandi'      => 'Onaylandı',
        'reddedildi'     => 'Reddedildi',
    ];

    $xl = new XlsxWriter($tip === 'alis' ? 'Alış İrsaliyeleri' : ($tip === 'iade' ? 'İade İrsaliyeleri' : 'Tüm İrsaliyeler'));

    $xl->header([
        'Sıra No', 'Tip', 'Tarih', 'İrsaliye No', 'Araç Plaka',
        'Tedarikçi', 'Beton Sınıfı', 'Parsel', 'Blok', 'Kot',
        'Pompa', 'Firma', 'Proje', 'Miktar (m³)', 'Birim',
        'Durum', 'Saha Onay Tarihi', 'Teknik Onay Tarihi', 'Açıklama',
    ]);

    foreach ($liste as $r) {
        $xl->row([
            ['v' => $r['sira_no'] ?? '',                                   't' => 'text'],
            ['v' => $r['tip'] === 'alis' ? 'Alış' : 'İade',               't' => 'text'],
            ['v' => $r['tarih'] ?? '',                                      't' => 'date'],
            ['v' => $r['irsaliye_no'] ?? '',                               't' => 'text'],
            ['v' => $r['arac_plaka'] ?? '',                                 't' => 'text'],
            ['v' => $r['tedarikci_adi'] ?? '',                              't' => 'text'],
            ['v' => $r['beton_sinifi_adi'] ?? '',                           't' => 'text'],
            ['v' => $r['parsel_adi'] ?? '',                                 't' => 'text'],
            ['v' => $r['blok_adi'] ?? '',                                   't' => 'text'],
            ['v' => $r['kot_degeri'] ?? '',                                 't' => 'text'],
            ['v' => $r['pompa_adi'] ?? '',                                  't' => 'text'],
            ['v' => $r['firma_adi'] ?? '',                                  't' => 'text'],
            ['v' => $r['proje_kod'] ?? '',                                  't' => 'text'],
            ['v' => $r['miktar'] ?? 0,                                      't' => 'number'],
            ['v' => $r['birim'] ?? 'm³',                                   't' => 'text'],
            ['v' => $durumLabel[$r['durum'] ?? ''] ?? ($r['durum'] ?? ''), 't' => 'text'],
            ['v' => $r['saha_onay_tarih'] ?? '',                            't' => 'date'],
            ['v' => $r['teknik_onay_tarih'] ?? '',                          't' => 'date'],
            ['v' => $r['aciklama'] ?? '',                                   't' => 'text'],
        ]);
    }

    $toplamM3 = array_sum(array_column($liste, 'miktar'));
    $xl->total([
        ['v' => 'TOPLAM',      't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => $toplamM3,     't' => 'number'],
        ['v' => 'm³',          't' => 'text'],
        ['v' => count($liste) . ' kayıt', 't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
        ['v' => '',            't' => 'text'],
    ]);

    $xl->download($fname);
}

// ── CSV dışa aktarma (yedek, kaldırılabilir) ─────────────────────────────────
if ($ihrac === 'csv') {
    $fname = 'irsaliyeler_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Sıra','Tip','Tarih','İrsaliye No','Plaka','Tedarikçi','Beton Sınıfı',
        'Parsel','Blok','Kot','Pompa','Firma','Proje','Miktar','Birim','Açıklama'
    ], ';');
    foreach ($liste as $r) {
        fputcsv($out, [
            $r['sira_no'] ?? '',
            $r['tip'] === 'alis' ? 'Alış' : 'İade',
            $r['tarih'] ?? '',
            $r['irsaliye_no'] ?? '',
            $r['arac_plaka'] ?? '',
            $r['tedarikci_adi'] ?? '',
            $r['beton_sinifi_adi'] ?? '',
            $r['parsel_adi'] ?? '',
            $r['blok_adi'] ?? '',
            $r['kot_degeri'] ?? '',
            $r['pompa_adi'] ?? '',
            $r['firma_adi'] ?? '',
            $r['proje_kod'] ?? '',
            number_format((float)$r['miktar'], 2, ',', ''),
            $r['birim'] ?? '',
            $r['aciklama'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// Filtre dropdownları için veriler
$tedarikciler  = $pdo->query("SELECT id,ad FROM tedarikciler WHERE aktif=1 ORDER BY ad")->fetchAll();
$parseller     = $pdo->query("SELECT id,ad FROM parseller ORDER BY ad")->fetchAll();
$betonSiniflari= $pdo->query("SELECT id,ad FROM beton_siniflari ORDER BY ad")->fetchAll();
try {
    $projelerList = $pdo->query("SELECT id, COALESCE(NULLIF(kod,''), CONCAT('Proje #',id)) AS kod, COALESCE(aciklama,'') AS aciklama FROM projeler ORDER BY kod")->fetchAll();
} catch (Exception $e) {
    $projelerList = [];
}
if ($filtreParsel) {
    $blokStmt = $pdo->prepare("SELECT id,ad FROM bloklar WHERE parsel_id=? ORDER BY ad");
    $blokStmt->execute([$filtreParsel]);
    $bloklar = $blokStmt->fetchAll();
} else {
    $bloklar = [];
}

// ── Toplu güncelleme modalı için tüm konum zinciri (istemci tarafı kademeli seçim) ──
try { $tgParseller = $pdo->query("SELECT id,ad,proje_id FROM parseller ORDER BY ad")->fetchAll(); }
catch (Throwable $e) { $tgParseller = $pdo->query("SELECT id,ad,NULL AS proje_id FROM parseller ORDER BY ad")->fetchAll(); }
$tgBloklar = $pdo->query("SELECT id,ad,parsel_id FROM bloklar ORDER BY ad")->fetchAll();
$tgKotlar  = $pdo->query("SELECT id,kot_degeri,blok_id FROM kotlar ORDER BY sira, kot_degeri")->fetchAll();
$yillar = $pdo->query("SELECT DISTINCT YEAR(tarih) AS y FROM irsaliyeler ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);

// Mevcut GET parametrelerini koru
$qs = $_GET;
unset($qs['export'], $qs['sil']);
$xlsxHref = 'irsaliyeler.php?' . http_build_query(array_merge($qs, ['export' => 'xlsx']));

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
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= h($xlsxHref) ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i> Excel İndir
        </a>
        <?php if (can_edit()): ?>
        <a href="irsaliye_form.php?tip=<?= h($tip) ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i> Yeni İrsaliye
        </a>
        <?php endif; ?>
    </div>
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
                <input type="text" name="ara" class="form-control form-control-sm" placeholder="İrsaliye / Fatura / Plaka" value="<?= h($filtreArama) ?>">
            </div>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small mb-1">Tedarikçi</label>
                <select name="tedarikci" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($tedarikciler as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $filtreTed == $t['id'] ? 'selected' : '' ?>><?= h($t['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small mb-1">Beton Sınıfı</label>
                <select name="beton" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($betonSiniflari as $bs): ?>
                        <option value="<?= (int)$bs['id'] ?>" <?= $filtreBS == $bs['id'] ? 'selected' : '' ?>><?= h($bs['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small mb-1">Parsel</label>
                <select name="parsel" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($parseller as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $filtreParsel == $p['id'] ? 'selected' : '' ?>><?= h($p['ad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($projelerList)): ?>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small mb-1">Proje</label>
                <select name="proje_id" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($projelerList as $pr): ?>
                        <option value="<?= (int)$pr['id'] ?>" <?= $filtreProje == $pr['id'] ? 'selected' : '' ?>><?= h(trim($pr['kod'] . ' ' . $pr['aciklama'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small mb-1">Onay Durumu</label>
                <select name="durum" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($DURUMLAR as $dk => $dv): ?>
                        <option value="<?= h($dk) ?>" <?= $filtreDurum === $dk ? 'selected' : '' ?>><?= h($dv) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small mb-1">Başlangıç Tarihi</label>
                <input type="date" name="tarih_bas" class="form-control form-control-sm" value="<?= h($tarihBas) ?>">
            </div>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small mb-1">Bitiş Tarihi</label>
                <input type="date" name="tarih_bit" class="form-control form-control-sm" value="<?= h($tarihBit) ?>">
            </div>
            <div class="col-sm-6 col-md-1">
                <label class="form-label small mb-1">Yıl</label>
                <select name="yil" class="form-select form-select-sm">
                    <option value="">Tümü</option>
                    <?php foreach ($yillar as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $filtreYil == $y ? 'selected' : '' ?>><?= (int)$y ?></option>
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
                <a href="irsaliyeler.php?tip=<?= h($tip) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Temizle</a>
            </div>
        </form>
    </div>
</div>

<!-- Liste tablosu -->
<form method="post" id="topluForm" onsubmit="return onTopluSubmit(event);">
    <input type="hidden" name="toplu_islem" id="topluIslemField" value="sil">

    <?php if (can_edit()): ?>
    <!-- Hızlı seçim: 1800 kayıtta tek tek işaretlemeyi ve "tümü"nün onaylıları da
         kapmasını önler. Yalnız bu sayfadaki (filtrelenmiş) satırlara uygulanır. -->
    <div class="d-flex justify-content-end mb-2">
        <div class="dropdown">
            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-check2-square me-1"></i>Hızlı Seçim
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><h6 class="dropdown-header">Bu sayfadaki kayıtlardan</h6></li>
                <li><button type="button" class="dropdown-item" onclick="secDurum('beklemede')">
                    <i class="bi bi-clock text-warning me-2"></i>Beklemede olanlar
                    <span class="badge bg-secondary ms-1" data-say="beklemede">0</span></button></li>
                <li><button type="button" class="dropdown-item" onclick="secDurum('saha_onaylandi')">
                    <i class="bi bi-check-circle text-success me-2"></i>Saha onaylı olanlar
                    <span class="badge bg-secondary ms-1" data-say="saha_onaylandi">0</span></button></li>
                <li><button type="button" class="dropdown-item" onclick="secDurum('onaylandi')">
                    <i class="bi bi-patch-check text-primary me-2"></i>Teknik onaylı olanlar
                    <span class="badge bg-secondary ms-1" data-say="onaylandi">0</span></button></li>
                <li><button type="button" class="dropdown-item" onclick="secDurum('reddedildi')">
                    <i class="bi bi-x-circle text-danger me-2"></i>Reddedilenler
                    <span class="badge bg-secondary ms-1" data-say="reddedildi">0</span></button></li>
                <li><hr class="dropdown-divider"></li>
                <li><button type="button" class="dropdown-item" onclick="secDurum('*')">
                    <i class="bi bi-list-check me-2"></i>Tümü
                    <span class="badge bg-secondary ms-1" data-say="*">0</span></button></li>
                <li><button type="button" class="dropdown-item text-muted" onclick="secimTemizle()">
                    <i class="bi bi-x-lg me-2"></i>Seçimi temizle</button></li>
            </ul>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center mb-2 gap-2" id="topluBar" style="display:none !important;">
        <span class="text-muted small fw-semibold"><span id="secimSay">0</span> kayıt seçili</span>

        <?php if (can_approve_saha()): ?>
        <button type="button" class="btn btn-sm btn-success" onclick="topluOnay('saha_onayla','Seçili irsaliyelere saha onayı verilsin mi?')">
            <i class="bi bi-check-circle me-1"></i>Toplu Saha Onayla
        </button>
        <?php endif; ?>

        <?php if (can_approve_teknik()): ?>
        <button type="button" class="btn btn-sm btn-primary" onclick="topluOnay('teknik_onayla','Seçili irsaliyelere teknik onay verilsin mi?')">
            <i class="bi bi-patch-check me-1"></i>Toplu Teknik Onayla
        </button>
        <?php endif; ?>

        <?php if (has_role('admin','teknik_ofis_admin')): ?>
        <button type="button" class="btn btn-sm btn-danger" onclick="topluOnay('sil','Seçili irsaliyeler silinsin mi? Bu işlem geri alınamaz.')">
            <i class="bi bi-trash me-1"></i>Seçilileri Sil
        </button>
        <?php endif; ?>

        <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#topluGuncelleModal">
            <i class="bi bi-pencil-square me-1"></i>Toplu Güncelle
        </button>

        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="secimTemizle()">
            <i class="bi bi-x-lg me-1"></i>Seçimi Temizle
        </button>
    </div>
    <?php endif; ?>

    <!-- Toplu güncelleme için gizli alanlar (topluForm ile gönderilir) -->
    <input type="hidden" name="bulk_proje_id"  id="bulkProjeId">
    <input type="hidden" name="bulk_parsel_id" id="bulkParselId">
    <input type="hidden" name="bulk_blok_id"   id="bulkBlokId">
    <input type="hidden" name="bulk_kot_id"    id="bulkKotId">
    <input type="hidden" name="bulk_aciklama"  id="bulkAciklama">
    <input type="hidden" name="aciklama_guncelle" id="bulkAciklamaFlag" disabled>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-sm">
                    <thead class="table-light">
                        <tr>
                            <?php if (can_edit()): ?>
                            <th class="text-center tbl-hide-mobile" style="width:34px;">
                                <input type="checkbox" id="secTumu" class="form-check-input" title="Tümünü seç">
                            </th>
                            <?php endif; ?>
                            <th class="text-center tbl-hide-mobile">#</th>
                            <?php if ($tip === 'tum'): ?><th>Tip</th><?php endif; ?>
                            <th><?= sortBaslik('durum', 'Durum') ?></th>
                            <th><?= sortBaslik('tarih', 'Tarih') ?></th>
                            <th><?= sortBaslik('irsaliye', 'İrsaliye No') ?></th>
                            <th class="tbl-hide-tablet"><?= sortBaslik('plaka', 'Plaka') ?></th>
                            <th><?= sortBaslik('tedarikci', 'Tedarikçi') ?></th>
                            <th><?= sortBaslik('beton', 'Beton Sınıfı') ?></th>
                            <th class="tbl-hide-tablet"><?= sortBaslik('parsel', 'Parsel / Blok / Kot') ?></th>
                            <th class="tbl-hide-mobile"><?= sortBaslik('pompa', 'Pompa') ?></th>
                            <th class="tbl-hide-mobile"><?= sortBaslik('firma', 'Firma') ?></th>
                            <th class="text-end"><?= sortBaslik('miktar', 'Miktar') ?></th>
                            <th class="tbl-hide-mobile">Açıklama</th>
                            <?php if (can_edit()): ?><th class="text-end">İşlem</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($liste): ?>
                            <?php foreach ($liste as $r): ?>
                            <tr>
                                <?php if (can_edit()): ?>
                                <td class="text-center tbl-hide-mobile">
                                    <input type="checkbox" name="secim[]" value="<?= (int)$r['id'] ?>"
                                           class="form-check-input secSatir" data-durum="<?= h($r['durum']) ?>">
                                </td>
                                <?php endif; ?>
                                <td class="text-center text-muted small tbl-hide-mobile"><?= h($r['sira_no'] ?: '-') ?></td>
                                <?php if ($tip === 'tum'): ?>
                                <td><?= $r['tip']==='alis' ? '<span class="badge bg-success">Alış</span>' : '<span class="badge bg-danger">İade</span>' ?></td>
                                <?php endif; ?>
                                <td><?= durum_badge($r['durum'] ?? 'onaylandi') ?></td>
                                <td class="text-nowrap"><?= format_date($r['tarih']) ?></td>
                                <td class="text-nowrap">
                                    <a href="irsaliye_detay.php?id=<?= (int)$r['id'] ?>" class="text-decoration-none fw-semibold">
                                        <?= h($r['irsaliye_no'] ?: '#'.$r['id']) ?>
                                    </a>
                                </td>
                                <td class="text-nowrap small tbl-hide-tablet"><?= h($r['arac_plaka'] ?: '-') ?></td>
                                <td><span class="badge bg-secondary"><?= h($r['tedarikci_adi'] ?? '-') ?></span></td>
                                <td><?= h($r['beton_sinifi_adi'] ?? '-') ?></td>
                                <td class="small text-muted tbl-hide-tablet">
                                    <?= h($r['parsel_adi'] ?? '') ?>
                                    <?php if ($r['blok_adi']): ?> / <?= h($r['blok_adi']) ?><?php endif; ?>
                                    <?php if ($r['kot_degeri']): ?> <span class="badge bg-light text-dark"><?= h($r['kot_degeri']) ?></span><?php endif; ?>
                                </td>
                                <td class="small tbl-hide-mobile"><?= h($r['pompa_adi'] ?? '-') ?></td>
                                <td class="small tbl-hide-mobile"><?= h($r['firma_adi'] ?? '-') ?></td>
                                <td class="text-end fw-semibold text-nowrap">
                                    <?= format_number($r['miktar'], 2) ?> <span class="text-muted small"><?= h($r['birim']) ?></span>
                                </td>
                                <td class="text-muted small tbl-hide-mobile" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                    <?= h($r['aciklama'] ?: '') ?>
                                </td>
                                <?php if (can_edit()): ?>
                                <td class="text-end text-nowrap">
                                    <?php
                                    $rd = $r['durum'] ?? 'onaylandi';
                                    // Hızlı Saha Onay butonu
                                    if ($rd === 'beklemede' && can_approve_saha()):
                                    ?>
                                    <a href="irsaliye_form.php?id=<?= (int)$r['id'] ?>&tip=<?= h($r['tip']) ?>"
                                       class="btn btn-xs btn-success me-1" title="İncele &amp; Onayla">
                                        <i class="bi bi-check-circle"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (in_array($rd, ['beklemede','saha_onaylandi']) && can_approve_teknik()): ?>
                                    <a href="irsaliye_form.php?id=<?= (int)$r['id'] ?>&tip=<?= h($r['tip']) ?>"
                                       class="btn btn-xs btn-primary me-1" title="Teknik Onayla">
                                        <i class="bi bi-patch-check"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (can_edit_irsaliye($r)): ?>
                                    <a href="irsaliye_form.php?id=<?= (int)$r['id'] ?>&tip=<?= h($r['tip']) ?>" class="btn btn-xs btn-outline-primary me-1" title="Düzenle">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (has_role('admin','teknik_ofis_admin')): ?>
                                    <a href="irsaliyeler.php?sil=<?= (int)$r['id'] ?>&tip=<?= h($tip) ?>"
                                       class="btn btn-xs btn-outline-danger btn-confirm"
                                       data-msg="Bu irsaliyeyi silmek istediğinize emin misiniz?"
                                       title="Sil">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-secondary fw-bold">
                                <?php
                                $preCols = ($tip === 'tum' ? 10 : 9) + (can_edit() ? 1 : 0);
                                ?>
                                <td colspan="<?= $preCols ?>" class="text-end">TOPLAM</td>
                                <td class="text-end"><?= format_number($toplamM3, 2) ?> m³</td>
                                <td colspan="<?= can_edit() ? 2 : 1 ?>"></td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $baseCols = $tip === 'tum' ? 12 : 11;
                            $colspan = $baseCols + (can_edit() ? 2 : 0);
                            ?>
                            <tr>
                                <td colspan="<?= $colspan ?>" class="text-center text-muted py-5">
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
</form>

<style>
.btn-xs { padding:.15rem .4rem; font-size:.75rem; }
</style>

<script>
(function () {
    const secTumu = document.getElementById('secTumu');
    const satirlar = document.querySelectorAll('.secSatir');
    const bar = document.getElementById('topluBar');
    const sayEl = document.getElementById('secimSay');

    function guncelle() {
        const sec = document.querySelectorAll('.secSatir:checked');
        if (sayEl) sayEl.textContent = sec.length;
        if (bar) bar.style.display = sec.length > 0 ? 'flex' : 'none';
        if (secTumu) {
            secTumu.checked = sec.length > 0 && sec.length === satirlar.length;
            secTumu.indeterminate = sec.length > 0 && sec.length < satirlar.length;
        }
    }

    if (secTumu) {
        secTumu.addEventListener('change', () => {
            satirlar.forEach(c => c.checked = secTumu.checked);
            guncelle();
        });
    }
    satirlar.forEach(c => c.addEventListener('change', guncelle));

    window.secimTemizle = function () {
        satirlar.forEach(c => c.checked = false);
        if (secTumu) secTumu.checked = false;
        guncelle();
    };

    // Duruma göre hızlı seçim ('*' = tümü). Mevcut seçimin yerine geçer.
    window.secDurum = function (durum) {
        satirlar.forEach(c => c.checked = (durum === '*') || c.dataset.durum === durum);
        guncelle();
    };

    // Menüdeki rozetlere bu sayfadaki durum sayılarını yaz
    (function () {
        const say = {};
        satirlar.forEach(c => { const d = c.dataset.durum || ''; say[d] = (say[d] || 0) + 1; });
        document.querySelectorAll('[data-say]').forEach(el => {
            const k = el.dataset.say;
            const n = (k === '*') ? satirlar.length : (say[k] || 0);
            el.textContent = n;
            // Kaydı olmayan seçeneği pasifleştir (boşuna tıklanmasın)
            const btn = el.closest('.dropdown-item');
            if (btn && n === 0) { btn.classList.add('disabled'); btn.setAttribute('aria-disabled', 'true'); }
        });
    })();

    window.topluOnay = function (islem, msg) {
        const sec = document.querySelectorAll('.secSatir:checked');
        if (sec.length === 0) { alert('Lütfen en az bir kayıt seçin.'); return; }
        if (!confirm(sec.length + ' kayıt seçili. ' + msg)) return;
        document.getElementById('topluIslemField').value = islem;
        document.getElementById('topluForm').submit();
    };

    window.onTopluSubmit = function (e) {
        e.preventDefault();
        return false;
    };
})();
</script>

<?php if (can_edit()): ?>
<!-- Toplu Güncelleme Modalı -->
<div class="modal fade" id="topluGuncelleModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-pencil-square text-warning me-2"></i>Toplu Güncelle — <span id="tgSecimSay">0</span> kayıt</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="alert alert-light border small mb-3"><i class="bi bi-info-circle text-primary me-1"></i>Konum zinciri: <strong>Proje → Parsel → Blok → Kot</strong>. <u>Boş bırakılan alanlar değiştirilmez.</u> Parsel seçilince proje otomatik gelir.</div>
        <div class="mb-2">
            <label class="form-label small fw-semibold">Proje</label>
            <select id="bulkProjeSel" class="form-select form-select-sm">
                <option value="">— değiştirme —</option>
                <?php foreach ($projelerList as $pr): ?><option value="<?= $pr['id'] ?>"><?= h($pr['kod'].($pr['aciklama']?' — '.$pr['aciklama']:'')) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="mb-2">
            <label class="form-label small fw-semibold">Parsel</label>
            <select id="bulkParselSel" class="form-select form-select-sm">
                <option value="">— değiştirme —</option>
                <?php foreach ($tgParseller as $p): ?><option value="<?= $p['id'] ?>" data-proje="<?= (int)($p['proje_id'] ?? 0) ?>"><?= h($p['ad']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="row g-2 mb-2">
            <div class="col-6">
                <label class="form-label small fw-semibold">Blok</label>
                <select id="bulkBlokSel" class="form-select form-select-sm"><option value="">— değiştirme —</option></select>
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold">Kot</label>
                <select id="bulkKotSel" class="form-select form-select-sm"><option value="">— değiştirme —</option></select>
            </div>
        </div>
        <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" id="bulkAcikChk">
            <label class="form-check-label small fw-semibold" for="bulkAcikChk">Açıklamayı da güncelle</label>
        </div>
        <textarea id="bulkAcikText" class="form-control form-control-sm mt-1" rows="2" placeholder="Açıklama metni (boş bırakılırsa açıklama temizlenir)" disabled></textarea>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
        <button type="button" class="btn btn-warning btn-sm" onclick="topluGuncelleUygula()"><i class="bi bi-check-lg me-1"></i>Seçili İrsaliyeleri Güncelle</button>
    </div>
</div></div></div>

<script>
(function(){
    const TG_BLOK = <?= json_encode(array_map(fn($b)=>['id'=>(int)$b['id'],'ad'=>$b['ad'],'p'=>(int)$b['parsel_id']], $tgBloklar), JSON_UNESCAPED_UNICODE) ?>;
    const TG_KOT  = <?= json_encode(array_map(fn($k)=>['id'=>(int)$k['id'],'ad'=>$k['kot_degeri'],'b'=>(int)$k['blok_id']], $tgKotlar), JSON_UNESCAPED_UNICODE) ?>;
    const projeSel=document.getElementById('bulkProjeSel'), parselSel=document.getElementById('bulkParselSel'),
          blokSel=document.getElementById('bulkBlokSel'), kotSel=document.getElementById('bulkKotSel'),
          acikChk=document.getElementById('bulkAcikChk'), acikText=document.getElementById('bulkAcikText');

    function opt(v,t){ const o=document.createElement('option'); o.value=v; o.textContent=t; return o; }
    function fillBlok(pid){
        blokSel.innerHTML=''; blokSel.appendChild(opt('','— değiştirme —'));
        TG_BLOK.filter(b=>b.p==pid).forEach(b=>blokSel.appendChild(opt(b.id,b.ad)));
        fillKot('');
    }
    function fillKot(bid){
        kotSel.innerHTML=''; kotSel.appendChild(opt('','— değiştirme —'));
        if(bid) TG_KOT.filter(k=>k.b==bid).forEach(k=>kotSel.appendChild(opt(k.id,k.ad)));
    }
    parselSel.addEventListener('change', function(){
        const pr=this.options[this.selectedIndex]?.dataset.proje||'0';
        if(pr!=='0') projeSel.value=pr;
        fillBlok(this.value);
    });
    blokSel.addEventListener('change', function(){ fillKot(this.value); });
    acikChk.addEventListener('change', function(){ acikText.disabled=!this.checked; });

    // Modal açılınca seçim sayısını yaz
    document.getElementById('topluGuncelleModal').addEventListener('show.bs.modal', function(){
        document.getElementById('tgSecimSay').textContent = document.querySelectorAll('.secSatir:checked').length;
    });

    window.topluGuncelleUygula = function(){
        const sec=document.querySelectorAll('.secSatir:checked');
        if(sec.length===0){ alert('Lütfen en az bir kayıt seçin.'); return; }
        const proje=projeSel.value, parsel=parselSel.value, blok=blokSel.value, kot=kotSel.value, ac=acikChk.checked;
        if(!proje && !parsel && !blok && !kot && !ac){ alert('En az bir alan seçin veya açıklamayı işaretleyin.'); return; }
        if(!confirm(sec.length+' irsaliye güncellenecek. Onaylıyor musunuz?')) return;
        document.getElementById('bulkProjeId').value=proje;
        document.getElementById('bulkParselId').value=parsel;
        document.getElementById('bulkBlokId').value=blok;
        document.getElementById('bulkKotId').value=kot;
        document.getElementById('bulkAciklama').value=acikText.value;
        document.getElementById('bulkAciklamaFlag').disabled=!ac; // yalnız işaretliyse gönderilir
        document.getElementById('topluIslemField').value='guncelle';
        document.getElementById('topluForm').submit();
    };
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
