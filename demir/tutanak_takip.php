<?php
/**
 * demir/tutanak_takip.php — Tutanak Takip defteri (Excel "TUTANAK TAKİP" sayfası)
 * Firma bazında çap-satırı hareket defteri: TESLİM ALINAN / İADE EDİLEN.
 * Görüntüleme + Excel içe aktarma (tam yenileme) + satır güncelleme + imzalı evrak yükleme.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Tutanak Takip — Demir Takip';
$canImport = has_role('admin','teknik_ofis_admin');
$canEdit   = has_role('admin','teknik_ofis_admin','teknik_ofis','saha_sefi');

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
    evrak_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Eski tablolara evrak_url kolonunu ekle
if (!$pdoDemir->query("SHOW COLUMNS FROM demir_tutanak_takip LIKE 'evrak_url'")->fetchColumn()) {
    $pdoDemir->exec("ALTER TABLE demir_tutanak_takip ADD COLUMN evrak_url VARCHAR(500) NULL");
}

function tt_c($r,$i){ return (!is_array($r)||$i>=count($r)||$r[$i]===null)?'':trim((string)$r[$i]); }
function tt_num($v){ $v=str_replace(',','.',trim((string)$v)); return $v!=='' && is_numeric($v)?(float)$v:null; }
function tt_int($v){ $v=trim((string)$v); if($v==='')return null; return ctype_digit(ltrim($v,'-'))?(int)$v:null; }
function tt_tarih($v){ $v=trim((string)$v); if($v==='')return null; $ts=strtotime($v); return $ts?date('Y-m-d',$ts):null; }

$rapor = null; $hata = '';

// ── Satır sil (paylaşılan evrak dosyasını yalnız son satırda kaldır) ───────────
if ($canEdit && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $sid = (int)$_GET['sil'];
    $ev = $pdoDemir->prepare("SELECT evrak_url FROM demir_tutanak_takip WHERE id=?"); $ev->execute([$sid]);
    $u = $ev->fetchColumn();
    $pdoDemir->prepare("DELETE FROM demir_tutanak_takip WHERE id=?")->execute([$sid]);
    if ($u) {
        $c = $pdoDemir->prepare("SELECT COUNT(*) FROM demir_tutanak_takip WHERE evrak_url=?"); $c->execute([$u]);
        if (!$c->fetchColumn()) @unlink(__DIR__ . '/../' . $u); // başka satır kullanmıyorsa dosyayı sil
    }
    flash('success', 'Satır silindi.');
    redirect('tutanak_takip.php');
}
// ── Evrak sil (ilgili tutanağın TÜM satırlarından) ────────────────────────────
if ($canEdit && isset($_GET['evrak_sil']) && ctype_digit($_GET['evrak_sil'])) {
    $sid = (int)$_GET['evrak_sil'];
    $rw = $pdoDemir->prepare("SELECT firma, tutanak_no, evrak_url FROM demir_tutanak_takip WHERE id=?");
    $rw->execute([$sid]); $rw = $rw->fetch();
    if ($rw) {
        if ($rw['evrak_url']) @unlink(__DIR__ . '/../' . $rw['evrak_url']);
        $tutNo = trim((string)$rw['tutanak_no']);
        if ($tutNo !== '') {
            $pdoDemir->prepare("UPDATE demir_tutanak_takip SET evrak_url=NULL WHERE firma=? AND tutanak_no=?")->execute([$rw['firma'], $tutNo]);
        } else {
            $pdoDemir->prepare("UPDATE demir_tutanak_takip SET evrak_url=NULL WHERE id=?")->execute([$sid]);
        }
    }
    flash('success', 'Evrak kaldırıldı.');
    redirect('tutanak_takip.php');
}

// ── Satır ekle / güncelle ─────────────────────────────────────────────────────
if ($canEdit && ($_POST['action'] ?? '') === 'kaydet') {
    $id    = ctype_digit($_POST['id'] ?? '') ? (int)$_POST['id'] : 0;
    $firma = trim($_POST['firma'] ?? '');
    $tipR  = ($_POST['tip'] ?? '')==='iade' ? 'iade' : 'teslim';
    $mik   = tt_num($_POST['miktar'] ?? '');
    if ($firma === '' || $mik === null) {
        flash('error', 'Firma ve miktar zorunludur.');
    } else {
        $d = [$firma, tt_int($_POST['sira'] ?? ''), trim($_POST['proje'] ?? '') ?: null, $tipR,
              tt_tarih($_POST['tarih'] ?? ''), trim($_POST['irsaliye_no'] ?? '') ?: null,
              trim($_POST['tutanak_no'] ?? '') ?: null, trim($_POST['cap_label'] ?? '') ?: null,
              $mik, tt_int($_POST['bag'] ?? '')];
        if ($id) {
            $d[] = $id;
            $pdoDemir->prepare("UPDATE demir_tutanak_takip SET firma=?, sira=?, proje=?, tip=?, tarih=?,
                irsaliye_no=?, tutanak_no=?, cap_label=?, miktar_ton=?, bag=? WHERE id=?")->execute($d);
            flash('success', 'Satır güncellendi.');
        } else {
            $pdoDemir->prepare("INSERT INTO demir_tutanak_takip
                (firma, sira, proje, tip, tarih, irsaliye_no, tutanak_no, cap_label, miktar_ton, bag)
                VALUES (?,?,?,?,?,?,?,?,?,?)")->execute($d);
            flash('success', 'Satır eklendi.');
        }
    }
    redirect('tutanak_takip.php');
}

// ── Evrak yükle: ilgili TUTANAK NO'nun tüm satırlarına tek seferde ────────────
if ($canEdit && ($_POST['action'] ?? '') === 'evrak' && ctype_digit($_POST['id'] ?? '')
    && isset($_FILES['evrak']) && $_FILES['evrak']['error']===UPLOAD_ERR_OK) {
    $sid = (int)$_POST['id'];
    $rw = $pdoDemir->prepare("SELECT firma, tutanak_no, evrak_url FROM demir_tutanak_takip WHERE id=?");
    $rw->execute([$sid]); $rw = $rw->fetch();
    $mime = guess_mime($_FILES['evrak']['tmp_name'], $_FILES['evrak']['name']);
    $izin = ['application/pdf','image/jpeg','image/png','image/webp'];
    if (!$rw) {
        flash('error', 'Satır bulunamadı.');
    } elseif (!in_array($mime, $izin, true)) {
        flash('error', 'Sadece PDF, JPG, PNG, WebP yüklenebilir.');
    } elseif ($_FILES['evrak']['size'] > 15*1024*1024) {
        flash('error', 'Dosya çok büyük (maks 15 MB).');
    } else {
        $tutNo  = trim((string)$rw['tutanak_no']);
        $firmaR = (string)$rw['firma'];
        // Klasör tutanak no bazlı (sabit); yoksa satır id'si
        $key = $tutNo !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '_', $tutNo) : ('satir' . $sid);
        $dir = __DIR__ . '/../uploads/demir_tutanak_takip/' . $key . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ext = pathinfo($_FILES['evrak']['name'], PATHINFO_EXTENSION) ?: ($mime==='application/pdf'?'pdf':'jpg');
        $ad  = 'evrak_' . date('Ymd_His') . '.' . strtolower($ext);
        if (move_uploaded_file($_FILES['evrak']['tmp_name'], $dir.$ad)) {
            $url = 'uploads/demir_tutanak_takip/' . $key . '/' . $ad;
            // Eski evrak dosyasını (varsa) temizle
            $eski = $rw['evrak_url'] ?: '';
            if ($tutNo !== '') {
                $upd = $pdoDemir->prepare("UPDATE demir_tutanak_takip SET evrak_url=? WHERE firma=? AND tutanak_no=?");
                $upd->execute([$url, $firmaR, $tutNo]);
                $n = $upd->rowCount();
                flash('success', "İmzalı evrak “{$tutNo}” tutanağının {$n} satırına eklendi.");
            } else {
                $pdoDemir->prepare("UPDATE demir_tutanak_takip SET evrak_url=? WHERE id=?")->execute([$url, $sid]);
                flash('success', 'Evrak yüklendi.');
            }
            if ($eski && $eski !== $url && realpath(__DIR__.'/../'.$eski) !== realpath($dir.$ad)) @unlink(__DIR__.'/../'.$eski);
        } else { flash('error', 'Dosya yüklenemedi.'); }
    }
    redirect('tutanak_takip.php');
}

// ── Excel içe aktar (tam yenileme; evrak korunur) ─────────────────────────────
if ($canImport && ($_POST['action'] ?? '') === 'import' && !empty($_FILES['dosya']['tmp_name'])) {
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
                // Yüklü evrakları koru (firma+sira doğal anahtarı)
                $snap = [];
                foreach ($pdoDemir->query("SELECT firma, sira, evrak_url FROM demir_tutanak_takip WHERE evrak_url IS NOT NULL") as $e) {
                    $snap[$e['firma'].'|'.$e['sira']] = $e['evrak_url'];
                }
                $pdoDemir->exec("DELETE FROM demir_tutanak_takip");
                $ins = $pdoDemir->prepare("INSERT INTO demir_tutanak_takip
                    (firma, sira, proje, tip, tarih, irsaliye_no, tutanak_no, cap_label, miktar_ton, bag)
                    VALUES (?,?,?,?,?,?,?,?,?,?)");
                foreach ($rows as $r) {
                    $firma = tt_c($r,0); $sira = tt_c($r,1); $tipRaw = tt_c($r,3); $mik = tt_num(tt_c($r,8));
                    if (!ctype_digit($sira)) continue;
                    if (mb_stripos($firma,'FİRMA-')!==false) continue;
                    if ($tipRaw==='' || $mik===null) continue;
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
                // Evrakları geri uygula
                $korunan = 0;
                if ($snap) {
                    $upd = $pdoDemir->prepare("UPDATE demir_tutanak_takip SET evrak_url=? WHERE firma=? AND sira=?");
                    foreach ($snap as $key=>$url) { [$fr,$sr] = explode('|',$key,2); $upd->execute([$url,$fr,$sr]); $korunan += $upd->rowCount(); }
                }
                $pdoDemir->commit();
                $rapor = ['eklenen'=>$eklenen, 'korunan'=>$korunan];
            } catch (Throwable $e) { $pdoDemir->rollBack(); $hata='İçe aktarma hatası: '.$e->getMessage(); }
        }
    }
}

// ── AJAX: tutanak detay (o tutanak no'nun tüm satırları + evrak) ──────────────
if (isset($_GET['tutanak_detay'])) {
    $tn = trim((string)$_GET['tutanak_detay']);
    $fr = trim((string)($_GET['firma'] ?? ''));
    $q = $pdoDemir->prepare("SELECT * FROM demir_tutanak_takip WHERE tutanak_no=? AND firma=? ORDER BY sira, id");
    $q->execute([$tn, $fr]);
    $satirlar = $q->fetchAll();
    $evrak = ''; $toplam = 0; $proje = ''; $tarih = ''; $firstId = 0;
    foreach ($satirlar as $s) {
        $toplam += (float)$s['miktar_ton'];
        if (!$evrak && $s['evrak_url']) $evrak = $s['evrak_url'];
        if (!$proje && $s['proje']) $proje = $s['proje'];
        if (!$tarih && $s['tarih']) $tarih = $s['tarih'];
        if (!$firstId) $firstId = (int)$s['id'];
    }
    $fmt = fn($n) => number_format((float)$n, 3, ',', '.');
    $isImg = $evrak ? !str_ends_with(strtolower($evrak), '.pdf') : false;
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <div class="d-flex flex-wrap gap-4 mb-3 small">
        <div><div class="text-muted">Firma</div><div class="fw-semibold"><?= h($fr) ?></div></div>
        <div><div class="text-muted">Tutanak No</div><div class="fw-semibold font-monospace"><?= h($tn) ?></div></div>
        <div><div class="text-muted">Proje</div><div class="fw-semibold"><?= $proje?h($proje):'—' ?></div></div>
        <div><div class="text-muted">Tarih</div><div class="fw-semibold"><?= format_date($tarih) ?></div></div>
        <div><div class="text-muted">Toplam</div><div class="fw-semibold"><?= $fmt($toplam) ?> t · <?= count($satirlar) ?> satır</div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-paperclip text-primary me-1"></i> İmzalı Evrak</div>
        <div class="card-body">
            <?php if ($evrak): ?>
                <div class="ratio ratio-16x9 mb-2 border rounded bg-light" style="max-height:420px">
                    <?php if ($isImg): ?>
                        <a href="<?= h($rootPath.$evrak) ?>" target="_blank" class="d-flex align-items-center justify-content-center"><img src="<?= h($rootPath.$evrak) ?>" class="img-fluid" style="max-height:100%;object-fit:contain" alt="Evrak"></a>
                    <?php else: ?>
                        <iframe src="<?= h($rootPath.$evrak) ?>#toolbar=1" style="border:0" title="Evrak"></iframe>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= h($rootPath.$evrak) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right me-1"></i>Yeni Sekmede Aç</a>
                    <?php if ($canEdit): ?><a href="tutanak_takip.php?evrak_sil=<?= $firstId ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('“<?= h($tn) ?>” tutanağının evrağı tüm satırlardan kaldırılsın mı?')"><i class="bi bi-trash me-1"></i>Kaldır</a><?php endif; ?>
                </div>
                <?php if ($canEdit): ?><div class="form-text mt-2">Değiştirmek için aşağıya yeni dosya sürükleyip bırakın.</div><?php endif; ?>
            <?php elseif (!$canEdit): ?>
                <div class="text-muted small">Henüz imzalı evrak yüklenmemiş.</div>
            <?php endif; ?>

            <?php if ($canEdit): ?>
            <form method="post" enctype="multipart/form-data" class="mt-2 dz-form">
                <input type="hidden" name="action" value="evrak">
                <input type="hidden" name="id" value="<?= $firstId ?>">
                <label class="dz-zone d-flex flex-column align-items-center justify-content-center text-center p-4 border border-2 border-dashed rounded" style="cursor:pointer;background:#f8f9fa;border-style:dashed!important">
                    <i class="bi bi-cloud-arrow-up fs-2 text-secondary"></i>
                    <span class="fw-semibold mt-1">Dosyayı buraya sürükleyin ya da tıklayın</span>
                    <span class="small text-muted dz-name">PDF, JPG, PNG — maks 15 MB · “<?= h($tn) ?>” tutanağının tüm satırlarına işlenir</span>
                    <input type="file" name="evrak" accept="application/pdf,image/*" class="d-none dz-input" required>
                </label>
                <div class="text-end mt-2"><button class="btn btn-success btn-sm dz-submit" disabled><i class="bi bi-upload me-1"></i>Yükle</button></div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white fw-semibold py-2"><i class="bi bi-list-check text-primary me-1"></i> Satırlar (<?= count($satirlar) ?>)</div>
        <div class="table-responsive" style="max-height:280px">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Hareket</th><th>Çap</th><th>İrsaliye</th><th class="text-end">Miktar (t)</th><th class="text-end">Bağ</th><?php if($canEdit): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($satirlar as $s): $iade=$s['tip']==='iade'; ?>
                <tr>
                    <td class="text-muted"><?= (int)$s['sira'] ?></td>
                    <td><?php if($iade): ?><span class="badge bg-danger-subtle text-danger border border-danger-subtle">İade</span><?php else: ?><span class="badge bg-success-subtle text-success border border-success-subtle">Teslim</span><?php endif; ?></td>
                    <td><?= h($s['cap_label'] ?: '—') ?></td>
                    <td class="font-monospace small"><?= h($s['irsaliye_no'] ?: '—') ?></td>
                    <td class="text-end fw-semibold <?= $iade?'text-danger':'' ?>"><?= $fmt($s['miktar_ton']) ?></td>
                    <td class="text-end"><?= $s['bag']!==null?(int)$s['bag']:'—' ?></td>
                    <?php if($canEdit): ?><td class="text-end"><button class="btn btn-xs btn-outline-primary btn-duzenle" data-json='<?= h(json_encode($s, JSON_UNESCAPED_UNICODE)) ?>' title="Düzenle"><i class="bi bi-pencil"></i></button></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if(!$satirlar): ?><tr><td colspan="<?= $canEdit?7:6 ?>" class="text-center text-muted py-3">Satır bulunamadı.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
    exit;
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
    else                       $topIade   += (float)$r['miktar_ton'];
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
    <div class="d-flex gap-2">
        <?php if ($canEdit): ?><button class="btn btn-dark" id="btnYeni"><i class="bi bi-plus-circle me-1"></i> Yeni Satır</button><?php endif; ?>
        <?php if ($canImport): ?><button class="btn btn-outline-success" data-bs-toggle="collapse" data-bs-target="#ttImport"><i class="bi bi-file-earmark-excel me-1"></i> Excel İçe Aktar</button><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>
<?php if ($hata): ?><div class="alert alert-danger"><?= h($hata) ?></div><?php endif; ?>
<?php if ($rapor): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><strong><?= $rapor['eklenen'] ?></strong> satır içe aktarıldı (tam yenileme)<?= $rapor['korunan']?', <strong>'.$rapor['korunan'].'</strong> evrak korundu':'' ?>.</div><?php endif; ?>

<?php if ($canImport): ?>
<div class="collapse mb-3" id="ttImport"><div class="card card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <input type="hidden" name="action" value="import">
        <div class="col-md-8"><label class="form-label small">Demir Takip Excel (.xlsx) — "TUTANAK TAKİP" sayfası okunur</label><input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required></div>
        <div class="col-md-4"><button class="btn btn-success btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> İçe Aktar (tam yenileme)</button></div>
    </form>
    <div class="form-text mt-1">Her içe aktarma satırları yeniler; <strong>yüklü imzalı evraklar korunur</strong> (firma + sıra eşleşmesi).</div>
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
            <th class="text-center">Evrak</th><?php if($canEdit): ?><th class="text-end">İşlem</th><?php endif; ?>
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
                <td class="font-monospace small"><?php if ($r['tutanak_no']): ?><a href="#" class="tutanak-detay text-decoration-none fw-semibold" data-tutanak="<?= h($r['tutanak_no']) ?>" data-firma="<?= h($r['firma']) ?>" title="Tutanak detayı"><?= h($r['tutanak_no']) ?></a><?php else: ?>—<?php endif; ?></td>
                <td><?= h($r['cap_label'] ?: '—') ?></td>
                <td class="text-end fw-semibold <?= $iade?'text-danger':'' ?>"><?= $fmt($r['miktar_ton']) ?></td>
                <td class="text-end"><?= $r['bag']!==null?(int)$r['bag']:'—' ?></td>
                <td class="text-center">
                    <?php if ($r['evrak_url']): ?>
                        <a href="<?= h($rootPath.$r['evrak_url']) ?>" target="_blank" class="badge bg-success text-decoration-none" title="Evrağı aç"><i class="bi bi-paperclip"></i> Var</a>
                    <?php elseif ($canEdit): ?>
                        <button class="btn btn-xs btn-outline-secondary btn-evrak" data-id="<?= $r['id'] ?>" data-tutanak="<?= h($r['tutanak_no'] ?: '') ?>" title="Evrak yükle (tutanağın tüm satırlarına)"><i class="bi bi-upload"></i></button>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <?php if ($canEdit): ?>
                <td class="text-end text-nowrap">
                    <button class="btn btn-xs btn-outline-primary btn-duzenle"
                        data-json='<?= h(json_encode($r, JSON_UNESCAPED_UNICODE)) ?>' title="Düzenle"><i class="bi bi-pencil"></i></button>
                    <?php if ($r['evrak_url']): ?><a href="tutanak_takip.php?evrak_sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-warning" title="Evrağı kaldır" onclick="return confirm('Evrak kaldırılsın mı?')"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                    <?php if (has_role('admin','teknik_ofis_admin')): ?><a href="tutanak_takip.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger" title="Sil" onclick="return confirm('Bu satır silinsin mi?')"><i class="bi bi-trash"></i></a><?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if(!$liste): ?>
            <tr><td colspan="<?= $canEdit?12:11 ?>" class="text-center text-muted py-5">
                <i class="bi bi-journal-x fs-1 d-block mb-2 opacity-50"></i>
                Kayıt yok. <?= $canImport?'Excel\'deki "TUTANAK TAKİP" sayfasını içe aktarın veya "Yeni Satır" ekleyin.':'' ?>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div></div></div>

<?php if ($canEdit): ?>
<!-- Satır ekle/düzenle modal -->
<div class="modal fade" id="satirModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <input type="hidden" name="action" value="kaydet">
      <input type="hidden" name="id" id="m_id">
      <div class="modal-header"><h5 class="modal-title" id="m_baslik">Yeni Satır</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small">Firma <span class="text-danger">*</span></label><input name="firma" id="m_firma" class="form-control form-control-sm" required></div>
          <div class="col-md-3"><label class="form-label small">Sıra</label><input name="sira" id="m_sira" type="number" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small">Proje</label><input name="proje" id="m_proje" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">Hareket</label><select name="tip" id="m_tip" class="form-select form-select-sm"><option value="teslim">Teslim Alınan</option><option value="iade">İade Edilen</option></select></div>
          <div class="col-md-4"><label class="form-label small">Tarih</label><input name="tarih" id="m_tarih" type="date" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small">Çap</label><input name="cap_label" id="m_cap" class="form-control form-control-sm" placeholder="ör. 20 / SPİRAL - Ø10"></div>
          <div class="col-md-6"><label class="form-label small">İrsaliye No</label><input name="irsaliye_no" id="m_irs" class="form-control form-control-sm"></div>
          <div class="col-md-6"><label class="form-label small">Tutanak No</label><input name="tutanak_no" id="m_tut" class="form-control form-control-sm"></div>
          <div class="col-md-6"><label class="form-label small">Miktar (t) <span class="text-danger">*</span></label><input name="miktar" id="m_mik" type="text" inputmode="decimal" class="form-control form-control-sm" required><div class="form-text">İade için negatif girebilirsiniz.</div></div>
          <div class="col-md-6"><label class="form-label small">Bağ</label><input name="bag" id="m_bag" type="number" class="form-control form-control-sm"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button><button class="btn btn-success"><i class="bi bi-save me-1"></i>Kaydet</button></div>
    </form>
  </div></div>
</div>

<!-- Evrak yükle modal (sürükle-bırak) -->
<div class="modal fade" id="evrakModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" enctype="multipart/form-data" class="dz-form">
      <input type="hidden" name="action" value="evrak">
      <input type="hidden" name="id" id="e_id">
      <div class="modal-header"><h5 class="modal-title">İmzalı Evrak Yükle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div id="e_scope" class="alert alert-info py-2 px-3 small mb-2 d-none"></div>
        <label class="dz-zone d-flex flex-column align-items-center justify-content-center text-center p-4 border border-2 border-dashed rounded" style="cursor:pointer;background:#f8f9fa;border-style:dashed!important">
            <i class="bi bi-cloud-arrow-up fs-2 text-secondary"></i>
            <span class="fw-semibold mt-1">Dosyayı buraya sürükleyin ya da tıklayın</span>
            <span class="small text-muted dz-name">PDF, JPG, PNG — maks 15 MB</span>
            <input type="file" name="evrak" accept="application/pdf,image/*" class="d-none dz-input" required>
        </label>
      </div>
      <div class="modal-footer"><button class="btn btn-primary w-100 dz-submit" disabled><i class="bi bi-upload me-1"></i>Yükle</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<!-- Tutanak detay modal (herkes görüntüleyebilir; düzenleme yetkilim ise aktif) -->
<div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-journal-text me-1"></i> Tutanak Detay</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="detayBody">
        <div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Yükleniyor…</div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    if (typeof bootstrap === 'undefined') return;
    function el(id){ return document.getElementById(id); }
    function set(id,v){ var e=el(id); if(e) e.value = (v===null||v===undefined)?'':v; }

    var satirModal = el('satirModal') ? new bootstrap.Modal(el('satirModal')) : null;
    var evrakModal = el('evrakModal') ? new bootstrap.Modal(el('evrakModal')) : null;
    var detayModal = el('detayModal') ? new bootstrap.Modal(el('detayModal')) : null;

    // Sürükle-bırak: verilen form kapsamındaki .dz-zone / .dz-input / .dz-submit
    function wireDropZone(form){
        if (!form) return;
        var zone = form.querySelector('.dz-zone'), input = form.querySelector('.dz-input'),
            name = form.querySelector('.dz-name'), submit = form.querySelector('.dz-submit');
        if (!zone || !input) return;
        function refresh(){
            if (input.files && input.files.length){
                if (name) name.textContent = input.files[0].name;
                zone.style.borderColor = '#198754'; zone.style.background = '#eafaf1';
                if (submit) submit.disabled = false;
            }
        }
        input.addEventListener('change', refresh);
        ['dragenter','dragover'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zone.style.borderColor='#0d6efd'; zone.style.background='#e7f1ff'; }); });
        ['dragleave','dragend'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zone.style.borderColor=''; zone.style.background='#f8f9fa'; }); });
        zone.addEventListener('drop', function(e){
            e.preventDefault(); e.stopPropagation();
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length){ input.files = e.dataTransfer.files; refresh(); }
        });
    }
    // Statik evrak modalı için bağla
    document.querySelectorAll('#evrakModal .dz-form').forEach(wireDropZone);

    // Olay delegasyonu (enjekte edilen detay içeriği için de çalışır)
    document.addEventListener('click', function(e){
        var d = e.target.closest('.btn-duzenle');
        if (d && satirModal){
            var r = JSON.parse(d.getAttribute('data-json'));
            el('m_baslik').textContent = 'Satır Düzenle';
            set('m_id',r.id); set('m_firma',r.firma); set('m_sira',r.sira); set('m_proje',r.proje);
            set('m_tip',r.tip==='iade'?'iade':'teslim'); set('m_tarih',r.tarih); set('m_cap',r.cap_label);
            set('m_irs',r.irsaliye_no); set('m_tut',r.tutanak_no); set('m_mik',r.miktar_ton); set('m_bag',r.bag);
            if (detayModal) detayModal.hide();
            satirModal.show();
            return;
        }
        var ev = e.target.closest('.btn-evrak');
        if (ev && evrakModal){
            set('e_id', ev.getAttribute('data-id'));
            var tn = ev.getAttribute('data-tutanak') || '';
            var scope = el('e_scope');
            if (tn){ scope.innerHTML = '<i class="bi bi-paperclip me-1"></i><strong>'+tn+'</strong> tutanağının <strong>tüm satırlarına</strong> eklenecek.'; scope.classList.remove('d-none'); }
            else { scope.classList.add('d-none'); }
            evrakModal.show();
            return;
        }
        var td = e.target.closest('.tutanak-detay');
        if (td && detayModal){
            e.preventDefault();
            var body = el('detayBody');
            body.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Yükleniyor…</div>';
            detayModal.show();
            var url = 'tutanak_takip.php?tutanak_detay=' + encodeURIComponent(td.getAttribute('data-tutanak')) + '&firma=' + encodeURIComponent(td.getAttribute('data-firma'));
            fetch(url, {headers:{'X-Requested-With':'fetch'}})
                .then(function(r){ return r.text(); })
                .then(function(html){ body.innerHTML = html; body.querySelectorAll('.dz-form').forEach(wireDropZone); })
                .catch(function(){ body.innerHTML = '<div class="alert alert-danger mb-0">İçerik yüklenemedi.</div>'; });
            return;
        }
    });

    var btnYeni = el('btnYeni');
    if (btnYeni && satirModal) btnYeni.addEventListener('click', function(){
        el('m_baslik').textContent = 'Yeni Satır';
        set('m_id',''); set('m_firma',''); set('m_sira',''); set('m_proje',''); set('m_tip','teslim');
        set('m_tarih',''); set('m_cap',''); set('m_irs',''); set('m_tut',''); set('m_mik',''); set('m_bag','');
        satirModal.show();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
