<?php
/**
 * demir/proje_disi.php — Proje Dışı İşler (A.2 Kantar Farkı, A.3 Transfer, A.4 Laboratuvar)
 * Görüntüleme + Excel içe aktarma (Sipariş kitabındaki "PROJE DIŞI İŞLER" sayfalarından).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Proje Dışı İşler — Demir Takip';
$canImport = has_role('admin','teknik_ofis_admin');

// Tablo (yoksa oluştur)
$pdoDemir->exec("CREATE TABLE IF NOT EXISTS demir_proje_disi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tip VARCHAR(10) NOT NULL,
    isin_adi VARCHAR(200) NULL, aciklama VARCHAR(300) NULL,
    proje VARCHAR(30) NULL, hedef_proje VARCHAR(30) NULL,
    firma VARCHAR(150) NULL, hedef_firma VARCHAR(150) NULL,
    ifs_talep_no VARCHAR(50) NULL, tarih DATE NULL,
    cap_mm INT NULL, adet INT NULL, boy DECIMAL(10,2) NULL,
    metraj_ton DECIMAL(14,4) NULL, kantar_farki DECIMAL(14,4) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$rapor = null; $hata = '';

function pd_c($r,$i){ return (!is_array($r)||$i>=count($r)||$r[$i]===null)?'':trim((string)$r[$i]); }
function pd_num($v){ $v=str_replace(',','.',trim((string)$v)); return $v!=='' && is_numeric($v)?(float)$v:null; }
function pd_int($v){ $v=trim((string)$v); return ctype_digit($v)?(int)$v:(is_numeric($v)?(int)round((float)$v):null); }
function pd_tarih($v){ $v=trim((string)$v); if($v==='')return null; $ts=strtotime($v); return $ts?date('Y-m-d',$ts):null; }
function pd_hdr($rows,$ara){ foreach($rows as $ri=>$r){ foreach($r as $v){ if($v!==null && mb_stripos((string)$v,$ara)!==false) return $ri; } if($ri>14)break; } return -1; }

if ($canImport && $_SERVER['REQUEST_METHOD']==='POST' && !empty($_FILES['dosya']['tmp_name'])) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (!($xlsx = \Shuchkin\SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) {
        $hata = 'Excel okunamadı: ' . \Shuchkin\SimpleXLSX::parseError();
    } else {
        $a2i = $a34i = null;
        foreach ($xlsx->sheetNames() as $i=>$n) {
            if (mb_stripos($n,'PROJE')!==false && mb_stripos($n,'A.2')!==false) $a2i=$i;
            if (mb_stripos($n,'PROJE')!==false && (mb_stripos($n,'A.3')!==false || mb_stripos($n,'A.4')!==false)) $a34i=$i;
        }
        $eklenen = 0;
        $pdoDemir->beginTransaction();
        try {
            $pdoDemir->exec("DELETE FROM demir_proje_disi"); // tam yenileme
            $ins = $pdoDemir->prepare("INSERT INTO demir_proje_disi
                (tip,isin_adi,aciklama,proje,hedef_proje,firma,hedef_firma,ifs_talep_no,tarih,cap_mm,adet,boy,metraj_ton,kantar_farki)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            if ($a2i !== null) {
                $rows = $xlsx->rows($a2i, 3000);
                $hr = pd_hdr($rows, 'İŞİN ADI');
                for ($ri=$hr+1; $ri<count($rows); $ri++) {
                    $r=$rows[$ri]; if (pd_c($r,0)==='' || pd_c($r,2)==='') continue;
                    $ins->execute(['A.2', pd_c($r,2)?:null, null, pd_c($r,3)?:null, null, pd_c($r,4)?:null, null, pd_c($r,5)?:null, pd_tarih(pd_c($r,6)), null, null, null, null, pd_num(pd_c($r,7))]);
                    $eklenen++;
                }
            }
            if ($a34i !== null) {
                $rows = $xlsx->rows($a34i, 4000);
                $hr = pd_hdr($rows, 'ESKİ FİRMA'); if ($hr<0) $hr = pd_hdr($rows, 'AÇIKLAMA');
                for ($ri=$hr+1; $ri<count($rows); $ri++) {
                    $r=$rows[$ri]; if (pd_c($r,0)==='' || pd_c($r,2)==='') continue; // boş şablon satırlarını atla (S.NO dolu ama içerik boş)
                    $poz = pd_c($r,1) ?: 'A.3';
                    $ins->execute([$poz, pd_c($r,2)?:null, pd_c($r,3)?:null, pd_c($r,5)?:null, pd_c($r,7)?:null, pd_c($r,4)?:null, pd_c($r,6)?:null, null, pd_tarih(pd_c($r,8)), pd_int(pd_c($r,11)), pd_int(pd_c($r,12)), pd_num(pd_c($r,13)), pd_num(pd_c($r,14)), null]);
                    $eklenen++;
                }
            }
            $pdoDemir->commit();
            $rapor = ['eklenen'=>$eklenen, 'a2'=>($a2i!==null), 'a34'=>($a34i!==null)];
        } catch (Throwable $e) { $pdoDemir->rollBack(); $hata='İçe aktarma hatası: '.$e->getMessage(); }
    }
}

// ── Veri ──────────────────────────────────────────────────────────────────────
$a2   = $pdoDemir->query("SELECT * FROM demir_proje_disi WHERE tip='A.2' ORDER BY tarih, id")->fetchAll();
$a3   = $pdoDemir->query("SELECT * FROM demir_proje_disi WHERE tip='A.3' ORDER BY tarih, id")->fetchAll();
$a4   = $pdoDemir->query("SELECT * FROM demir_proje_disi WHERE tip='A.4' ORDER BY tarih, id")->fetchAll();
$totFark   = array_sum(array_map(fn($r)=>(float)$r['kantar_farki'], $a2));
$totA3Ton  = array_sum(array_map(fn($r)=>(float)$r['metraj_ton'], $a3));
$totA4Ton  = array_sum(array_map(fn($r)=>(float)$r['metraj_ton'], $a4));

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n,$d=3) => number_format((float)$n, $d, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-box-arrow-up-right text-dark me-2"></i>Proje Dışı İşler</h4>
        <small class="text-muted">Proje harici demir hareketleri: kantar farkı, firmalar/bölgeler arası transfer, laboratuvar</small>
    </div>
    <?php if ($canImport): ?>
    <button class="btn btn-outline-success" data-bs-toggle="collapse" data-bs-target="#pdImport"><i class="bi bi-file-earmark-excel me-1"></i> Excel İçe Aktar</button>
    <?php endif; ?>
</div>

<?php if ($hata): ?><div class="alert alert-danger"><?= h($hata) ?></div><?php endif; ?>
<?php if ($rapor): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><strong><?= $rapor['eklenen'] ?></strong> proje dışı iş kaydı içe aktarıldı (tam yenileme).</div><?php endif; ?>

<?php if ($canImport): ?>
<div class="collapse mb-3" id="pdImport"><div class="card card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <div class="col-md-8"><label class="form-label small">Demir Takip Excel (.xlsx) — "PROJE DIŞI İŞLER" sayfaları okunur</label><input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required></div>
        <div class="col-md-4"><button class="btn btn-success btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> İçe Aktar (tam yenileme)</button></div>
    </form>
    <div class="form-text mt-1">Her içe aktarma mevcut proje dışı iş kayıtlarını siler ve yeniden yükler.</div>
</div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">A.2 — Kantar Farkı Kayıtları</div><div class="fs-4 fw-bold"><?= count($a2) ?> <span class="fs-6 text-muted">kayıt</span></div><div class="small">Toplam fark: <strong class="<?= $totFark<0?'text-danger':($totFark>0?'text-success':'') ?>"><?= ($totFark>0?'+':'').$fmt($totFark) ?> t</strong></div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">A.3 — Transfer (firma/bölge arası)</div><div class="fs-4 fw-bold"><?= count($a3) ?> <span class="fs-6 text-muted">kayıt</span></div><div class="small">Toplam: <strong><?= $fmt($totA3Ton) ?> t</strong></div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">A.4 — Laboratuvara Giden</div><div class="fs-4 fw-bold"><?= count($a4) ?> <span class="fs-6 text-muted">kayıt</span></div><div class="small">Toplam: <strong><?= $fmt($totA4Ton) ?> t</strong></div></div></div></div>
</div>

<!-- A.2 -->
<div class="card mb-3">
    <div class="card-header bg-white fw-semibold"><span class="badge bg-secondary me-1">A.2</span> Tedarikçi-Kantar Farkı</div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:360px">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Proje</th><th>Firma</th><th>IFS Talep No</th><th>Tarih</th><th class="text-end">Kantar Farkı (t)</th></tr></thead>
            <tbody>
            <?php foreach($a2 as $i=>$r): $f=(float)$r['kantar_farki']; ?>
                <tr><td class="text-muted"><?= $i+1 ?></td><td><span class="badge bg-dark"><?= h($r['proje']) ?></span></td><td><?= h($r['firma']) ?></td><td class="font-monospace"><?= h($r['ifs_talep_no'] ?: '—') ?></td><td><?= format_date($r['tarih']) ?></td><td class="text-end <?= abs($f)<0.0005?'text-muted':($f<0?'text-danger':'text-success') ?>"><?= abs($f)<0.0005?'0':(($f>0?'+':'').$fmt($f)) ?></td></tr>
            <?php endforeach; ?>
            <?php if(!$a2): ?><tr><td colspan="6" class="text-center text-muted py-4">Kayıt yok. <?= $canImport?'Excel içe aktarın.':'' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>

<!-- A.3 -->
<div class="card mb-3">
    <div class="card-header bg-white fw-semibold"><span class="badge bg-secondary me-1">A.3</span> Firmalar/Bölgeler Arası Transfer</div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:400px">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Açıklama</th><th>Eski Firma/Bölge</th><th>Yeni Firma/Bölge</th><th>Tarih</th><th class="text-end">Çap</th><th class="text-end">Adet</th><th class="text-end">Boy (m)</th><th class="text-end">Metraj (t)</th></tr></thead>
            <tbody>
            <?php foreach($a3 as $i=>$r): ?>
                <tr>
                    <td class="text-muted"><?= $i+1 ?></td>
                    <td class="small"><?= h($r['aciklama'] ?: $r['isin_adi']) ?></td>
                    <td><?= h($r['firma']) ?> <span class="badge bg-light text-dark border"><?= h($r['proje']) ?></span></td>
                    <td><?= h($r['hedef_firma']) ?> <span class="badge bg-light text-dark border"><?= h($r['hedef_proje']) ?></span></td>
                    <td><?= format_date($r['tarih']) ?></td>
                    <td class="text-end"><?= $r['cap_mm']?'Ø'.(int)$r['cap_mm']:'—' ?></td>
                    <td class="text-end"><?= $r['adet']!==null?(int)$r['adet']:'—' ?></td>
                    <td class="text-end"><?= $r['boy']!==null?$fmt($r['boy'],2):'—' ?></td>
                    <td class="text-end fw-semibold"><?= $fmt($r['metraj_ton']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if(!$a3): ?><tr><td colspan="9" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
            </tbody>
            <?php if($a3): ?><tfoot class="table-light fw-bold"><tr><td colspan="8" class="text-end">TOPLAM</td><td class="text-end"><?= $fmt($totA3Ton) ?></td></tr></tfoot><?php endif; ?>
        </table>
    </div></div>
</div>

<!-- A.4 -->
<?php if($a4): ?>
<div class="card mb-3">
    <div class="card-header bg-white fw-semibold"><span class="badge bg-secondary me-1">A.4</span> Laboratuvara Giden</div>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Açıklama</th><th>Firma/Bölge</th><th>Tarih</th><th class="text-end">Çap</th><th class="text-end">Adet</th><th class="text-end">Metraj (t)</th></tr></thead>
            <tbody>
            <?php foreach($a4 as $i=>$r): ?>
                <tr><td class="text-muted"><?= $i+1 ?></td><td class="small"><?= h($r['aciklama'] ?: $r['isin_adi']) ?></td><td><?= h($r['firma']) ?> <span class="badge bg-light text-dark border"><?= h($r['proje']) ?></span></td><td><?= format_date($r['tarih']) ?></td><td class="text-end"><?= $r['cap_mm']?'Ø'.(int)$r['cap_mm']:'—' ?></td><td class="text-end"><?= $r['adet']!==null?(int)$r['adet']:'—' ?></td><td class="text-end fw-semibold"><?= $fmt($r['metraj_ton']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
