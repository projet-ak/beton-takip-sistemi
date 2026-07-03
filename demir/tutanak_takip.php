<?php
/**
 * demir/tutanak_takip.php — Tutanak Takip defteri (Excel "TUTANAK TAKİP" sayfası)
 * Firma bazında çap-satırı hareket defteri: TESLİM ALINAN / İADE EDİLEN.
 * Görüntüleme + Excel içe aktarma (tam yenileme), proje_disi.php deseniyle.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Tutanak Takip — Demir Takip';
$canImport = has_role('admin','teknik_ofis_admin');

$pdoDemir->exec("CREATE TABLE IF NOT EXISTS demir_tutanak_takip (
    id INT AUTO_INCREMENT PRIMARY KEY,
    firma VARCHAR(120) NULL,
    sira INT NULL,
    proje VARCHAR(30) NULL,
    tip VARCHAR(10) NULL,
    tarih DATE NULL,
    irsaliye_no VARCHAR(100) NULL,
    tutanak_no VARCHAR(60) NULL,
    cap_label VARCHAR(40) NULL,
    miktar_ton DECIMAL(14,4) NULL,
    bag INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$rapor = null; $hata = '';

function tt_c($r,$i){ return (!is_array($r)||$i>=count($r)||$r[$i]===null)?'':trim((string)$r[$i]); }
function tt_num($v){ $v=str_replace(',','.',trim((string)$v)); return $v!=='' && is_numeric($v)?(float)$v:null; }
function tt_int($v){ $v=trim((string)$v); return ctype_digit($v)?(int)$v:null; }
function tt_tarih($v){ $v=trim((string)$v); if($v==='')return null; $ts=strtotime($v); return $ts?date('Y-m-d',$ts):null; }

if ($canImport && $_SERVER['REQUEST_METHOD']==='POST' && !empty($_FILES['dosya']['tmp_name'])) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (!($xlsx = \Shuchkin\SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) {
        $hata = 'Excel okunamadı: ' . \Shuchkin\SimpleXLSX::parseError();
    } else {
        $si = null;
        foreach ($xlsx->sheetNames() as $i=>$n) {
            if (mb_stripos($n,'TUTANAK')!==false && mb_stripos($n,'TAKİP')!==false) { $si=$i; break; }
        }
        if ($si === null) { $hata = '"TUTANAK TAKİP" sayfası bulunamadı.'; }
        else {
            $rows = $xlsx->rows($si, 3000);
            $eklenen = 0;
            $pdoDemir->beginTransaction();
            try {
                $pdoDemir->exec("DELETE FROM demir_tutanak_takip"); // tam yenileme
                $ins = $pdoDemir->prepare("INSERT INTO demir_tutanak_takip
                    (firma, sira, proje, tip, tarih, irsaliye_no, tutanak_no, cap_label, miktar_ton, bag)
                    VALUES (?,?,?,?,?,?,?,?,?,?)");
                foreach ($rows as $r) {
                    $firma = tt_c($r,0); $sira = tt_c($r,1); $tipRaw = tt_c($r,3); $mik = tt_num(tt_c($r,8));
                    if (!ctype_digit($sira)) continue;                 // veri satırı değil (başlık/boş)
                    if (mb_stripos($firma,'FİRMA-')!==false) continue;  // firma başlık satırı
                    if ($tipRaw==='' || $mik===null) continue;          // eksik satır
                    $tip = mb_stripos($tipRaw,'TESLİM')!==false ? 'teslim'
                         : (mb_stripos($tipRaw,'İADE')!==false ? 'iade' : null);
                    if ($tip === null) continue;
                    $ins->execute([
                        $firma ?: null, (int)$sira, tt_c($r,2) ?: null, $tip,
                        tt_tarih(tt_c($r,4)), tt_c($r,5) ?: null, tt_c($r,6) ?: null,
                        tt_c($r,7) ?: null, $mik, tt_int(tt_c($r,9)),
                    ]);
                    $eklenen++;
                }
                $pdoDemir->commit();
                $rapor = ['eklenen'=>$eklenen];
            } catch (Throwable $e) { $pdoDemir->rollBack(); $hata='İçe aktarma hatası: '.$e->getMessage(); }
        }
    }
}

// ── Filtreler ──────────────────────────────────────────────────────────────────
$fFirma = trim($_GET['firma'] ?? '');
$fProje = trim($_GET['proje'] ?? '');
$fTip   = trim($_GET['tip'] ?? '');
$where = []; $params = [];
if ($fFirma !== '') { $where[] = 'firma = ?'; $params[] = $fFirma; }
if ($fProje !== '') { $where[] = 'proje = ?'; $params[] = $fProje; }
if ($fTip === 'teslim' || $fTip === 'iade') { $where[] = 'tip = ?'; $params[] = $fTip; }
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$liste = $pdoDemir->prepare("SELECT * FROM demir_tutanak_takip $whereSQL ORDER BY firma, sira");
$liste->execute($params);
$liste = $liste->fetchAll();

$firmalar = $pdoDemir->query("SELECT DISTINCT firma FROM demir_tutanak_takip WHERE firma IS NOT NULL ORDER BY firma")->fetchAll(PDO::FETCH_COLUMN);
$projeler = $pdoDemir->query("SELECT DISTINCT proje FROM demir_tutanak_takip WHERE proje IS NOT NULL AND proje<>'' ORDER BY proje")->fetchAll(PDO::FETCH_COLUMN);

$topTeslim = 0; $topIade = 0;
foreach ($liste as $r) {
    if ($r['tip']==='teslim') $topTeslim += (float)$r['miktar_ton'];
    else                       $topIade   += (float)$r['miktar_ton']; // iade negatiftir
}
$net = $topTeslim + $topIade;

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n,$d=3) => number_format((float)$n, $d, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-journal-text text-dark me-2"></i>Tutanak Takip</h4>
        <small class="text-muted">Firma bazında çap satırı hareket defteri (teslim alınan / iade edilen)</small>
    </div>
    <?php if ($canImport): ?>
    <button class="btn btn-outline-success" data-bs-toggle="collapse" data-bs-target="#ttImport"><i class="bi bi-file-earmark-excel me-1"></i> Excel İçe Aktar</button>
    <?php endif; ?>
</div>

<?php if ($hata): ?><div class="alert alert-danger"><?= h($hata) ?></div><?php endif; ?>
<?php if ($rapor): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><strong><?= $rapor['eklenen'] ?></strong> tutanak takip satırı içe aktarıldı (tam yenileme).</div><?php endif; ?>

<?php if ($canImport): ?>
<div class="collapse mb-3" id="ttImport"><div class="card card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <div class="col-md-8"><label class="form-label small">Demir Takip Excel (.xlsx) — "TUTANAK TAKİP" sayfası okunur</label><input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required></div>
        <div class="col-md-4"><button class="btn btn-success btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> İçe Aktar (tam yenileme)</button></div>
    </form>
    <div class="form-text mt-1">Her içe aktarma mevcut tutanak takip kayıtlarını siler ve yeniden yükler.</div>
</div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Satır</div><div class="fs-4 fw-bold"><?= count($liste) ?></div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Teslim Alınan</div><div class="fs-4 fw-bold text-success"><?= $fmt($topTeslim) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">İade Edilen</div><div class="fs-4 fw-bold text-danger"><?= $fmt(abs($topIade)) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Net</div><div class="fs-4 fw-bold"><?= $fmt($net) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
</div>

<div class="card mb-3"><div class="card-body">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-3"><label class="form-label small">Firma</label><select name="firma" class="form-select form-select-sm"><option value="">Tümü</option><?php foreach($firmalar as $f): ?><option value="<?= h($f) ?>" <?= $fFirma===$f?'selected':'' ?>><?= h($f) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small">Proje</label><select name="proje" class="form-select form-select-sm"><option value="">Tümü</option><?php foreach($projeler as $p): ?><option value="<?= h($p) ?>" <?= $fProje===$p?'selected':'' ?>><?= h($p) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small">Hareket</label><select name="tip" class="form-select form-select-sm"><option value="">Tümü</option><option value="teslim" <?= $fTip==='teslim'?'selected':'' ?>>Teslim Alınan</option><option value="iade" <?= $fTip==='iade'?'selected':'' ?>>İade Edilen</option></select></div>
        <div class="col-md-3 d-flex gap-1"><button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-funnel"></i> Filtrele</button><a href="tutanak_takip.php" class="btn btn-outline-secondary btn-sm">Temizle</a></div>
    </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive" style="max-height:640px">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light" style="position:sticky;top:0;z-index:1"><tr>
            <th>Firma</th><th class="text-end">#</th><th>Proje</th><th>Hareket</th><th>Tarih</th>
            <th>İrsaliye No</th><th>Tutanak No</th><th>Çap</th><th class="text-end">Miktar (t)</th><th class="text-end">Bağ</th>
        </tr></thead>
        <tbody>
        <?php foreach ($liste as $r): $iade = $r['tip']==='iade'; ?>
            <tr>
                <td class="fw-semibold"><?= h($r['firma']) ?></td>
                <td class="text-end text-muted"><?= (int)$r['sira'] ?></td>
                <td><?= $r['proje'] ? '<span class="badge bg-secondary">'.h($r['proje']).'</span>' : '—' ?></td>
                <td><?php if($iade): ?><span class="badge bg-danger-subtle text-danger border border-danger-subtle">İade</span><?php else: ?><span class="badge bg-success-subtle text-success border border-success-subtle">Teslim</span><?php endif; ?></td>
                <td class="text-nowrap"><?= format_date($r['tarih']) ?></td>
                <td class="font-monospace small"><?= h($r['irsaliye_no'] ?: '—') ?></td>
                <td class="font-monospace small"><?= h($r['tutanak_no'] ?: '—') ?></td>
                <td><?= h($r['cap_label'] ?: '—') ?></td>
                <td class="text-end fw-semibold <?= $iade?'text-danger':'' ?>"><?= $fmt($r['miktar_ton']) ?></td>
                <td class="text-end"><?= $r['bag']!==null?(int)$r['bag']:'—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if(!$liste): ?>
            <tr><td colspan="10" class="text-center text-muted py-5">
                <i class="bi bi-journal-x fs-1 d-block mb-2 opacity-50"></i>
                Kayıt yok. <?= $canImport?'Excel\'deki "TUTANAK TAKİP" sayfasını içe aktarın.':'' ?>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div></div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
