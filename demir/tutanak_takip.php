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

// ── Satır sil ─────────────────────────────────────────────────────────────────
if ($canEdit && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $sid = (int)$_GET['sil'];
    $ev = $pdoDemir->prepare("SELECT evrak_url FROM demir_tutanak_takip WHERE id=?"); $ev->execute([$sid]);
    if ($u = $ev->fetchColumn()) @unlink(__DIR__ . '/../' . $u);
    $pdoDemir->prepare("DELETE FROM demir_tutanak_takip WHERE id=?")->execute([$sid]);
    flash('success', 'Satır silindi.');
    redirect('tutanak_takip.php');
}
// ── Evrak sil ─────────────────────────────────────────────────────────────────
if ($canEdit && isset($_GET['evrak_sil']) && ctype_digit($_GET['evrak_sil'])) {
    $sid = (int)$_GET['evrak_sil'];
    $ev = $pdoDemir->prepare("SELECT evrak_url FROM demir_tutanak_takip WHERE id=?"); $ev->execute([$sid]);
    if ($u = $ev->fetchColumn()) @unlink(__DIR__ . '/../' . $u);
    $pdoDemir->prepare("UPDATE demir_tutanak_takip SET evrak_url=NULL WHERE id=?")->execute([$sid]);
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

// ── Evrak yükle (satıra) ──────────────────────────────────────────────────────
if ($canEdit && ($_POST['action'] ?? '') === 'evrak' && ctype_digit($_POST['id'] ?? '')
    && isset($_FILES['evrak']) && $_FILES['evrak']['error']===UPLOAD_ERR_OK) {
    $sid = (int)$_POST['id'];
    $mime = mime_content_type($_FILES['evrak']['tmp_name']);
    $izin = ['application/pdf','image/jpeg','image/png','image/webp'];
    if (!in_array($mime, $izin, true)) {
        flash('error', 'Sadece PDF, JPG, PNG, WebP yüklenebilir.');
    } elseif ($_FILES['evrak']['size'] > 15*1024*1024) {
        flash('error', 'Dosya çok büyük (maks 15 MB).');
    } else {
        $dir = __DIR__ . '/../uploads/demir_tutanak_takip/' . $sid . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ext = pathinfo($_FILES['evrak']['name'], PATHINFO_EXTENSION) ?: ($mime==='application/pdf'?'pdf':'jpg');
        $ad  = 'evrak_' . date('Ymd_His') . '.' . strtolower($ext);
        if (move_uploaded_file($_FILES['evrak']['tmp_name'], $dir.$ad)) {
            $url = 'uploads/demir_tutanak_takip/' . $sid . '/' . $ad;
            $pdoDemir->prepare("UPDATE demir_tutanak_takip SET evrak_url=? WHERE id=?")->execute([$url, $sid]);
            flash('success', 'Evrak yüklendi.');
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
                <td class="font-monospace small"><?= h($r['tutanak_no'] ?: '—') ?></td>
                <td><?= h($r['cap_label'] ?: '—') ?></td>
                <td class="text-end fw-semibold <?= $iade?'text-danger':'' ?>"><?= $fmt($r['miktar_ton']) ?></td>
                <td class="text-end"><?= $r['bag']!==null?(int)$r['bag']:'—' ?></td>
                <td class="text-center">
                    <?php if ($r['evrak_url']): ?>
                        <a href="<?= h($rootPath.$r['evrak_url']) ?>" target="_blank" class="badge bg-success text-decoration-none" title="Evrağı aç"><i class="bi bi-paperclip"></i> Var</a>
                    <?php elseif ($canEdit): ?>
                        <button class="btn btn-xs btn-outline-secondary btn-evrak" data-id="<?= $r['id'] ?>" title="Evrak yükle"><i class="bi bi-upload"></i></button>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <?php if ($canEdit): ?>
                <td class="text-end text-nowrap">
                    <button class="btn btn-xs btn-outline-primary btn-duzenle"
                        data-json='<?= h(json_encode($r, JSON_UNESCAPED_UNICODE)) ?>' title="Düzenle"><i class="bi bi-pencil"></i></button>
                    <?php if ($r['evrak_url']): ?><a href="tutanak_takip.php?evrak_sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-warning btn-confirm" data-msg="Evrak kaldırılsın mı?" title="Evrağı kaldır"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                    <?php if (has_role('admin','teknik_ofis_admin')): ?><a href="tutanak_takip.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Bu satır silinsin mi?"><i class="bi bi-trash"></i></a><?php endif; ?>
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

<!-- Evrak yükle modal -->
<div class="modal fade" id="evrakModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm"><div class="modal-content">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="evrak">
      <input type="hidden" name="id" id="e_id">
      <div class="modal-header"><h5 class="modal-title">İmzalı Evrak Yükle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="file" name="evrak" class="form-control form-control-sm" accept="application/pdf,image/*" required>
        <div class="form-text">PDF, JPG, PNG — maks 15 MB</div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary w-100"><i class="bi bi-upload me-1"></i>Yükle</button></div>
    </form>
  </div></div>
</div>

<script>
(function(){
    var satirModal = new bootstrap.Modal(document.getElementById('satirModal'));
    var evrakModal = new bootstrap.Modal(document.getElementById('evrakModal'));
    function set(id,v){ document.getElementById(id).value = (v===null||v===undefined)?'':v; }
    document.getElementById('btnYeni').addEventListener('click', function(){
        document.getElementById('m_baslik').textContent = 'Yeni Satır';
        set('m_id',''); set('m_firma',''); set('m_sira',''); set('m_proje',''); set('m_tip','teslim');
        set('m_tarih',''); set('m_cap',''); set('m_irs',''); set('m_tut',''); set('m_mik',''); set('m_bag','');
        satirModal.show();
    });
    document.querySelectorAll('.btn-duzenle').forEach(function(b){
        b.addEventListener('click', function(){
            var r = JSON.parse(this.getAttribute('data-json'));
            document.getElementById('m_baslik').textContent = 'Satır Düzenle';
            set('m_id',r.id); set('m_firma',r.firma); set('m_sira',r.sira); set('m_proje',r.proje);
            set('m_tip',r.tip==='iade'?'iade':'teslim'); set('m_tarih',r.tarih); set('m_cap',r.cap_label);
            set('m_irs',r.irsaliye_no); set('m_tut',r.tutanak_no); set('m_mik',r.miktar_ton); set('m_bag',r.bag);
            satirModal.show();
        });
    });
    document.querySelectorAll('.btn-evrak').forEach(function(b){
        b.addEventListener('click', function(){ set('e_id', this.getAttribute('data-id')); evrakModal.show(); });
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
