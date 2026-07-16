<?php
/** aylik.php — Aylık akaryakıt tüketimi (dönem seçmeli, araç bazlı + günlük detay) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoAkaryakit->query("SELECT 1 FROM akaryakit_donemler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_akaryakit.php'); }

$donemler = ak_donemler($pdoAkaryakit);
$sec = $_GET['donem'] ?? ($donemler[0]['donem'] ?? '');
$stok = null;
foreach ($donemler as $d) if ($d['donem']===$sec) { $stok=$d; break; }

$liste = [];
if ($sec!=='') {
    $q = $pdoAkaryakit->prepare("SELECT t.*, a.sinif,a.mak_no,a.lokasyon,a.firma,a.plaka,a.sofor,a.cinsi
        FROM akaryakit_tuketim t JOIN akaryakit_araclar a ON a.id=t.arac_id
        WHERE t.donem=? ORDER BY t.aylik_tuketim DESC, a.sofor");
    $q->execute([$sec]); $liste=$q->fetchAll();
}
$topTuk = array_sum(array_map(fn($r)=>(float)$r['aylik_tuketim'],$liste));
$fmt0 = fn($n)=>number_format((float)$n,0,',','.');
$fmt2 = fn($n)=>number_format((float)$n,2,',','.');
$pageTitle = 'Aylık Takip — '.$sec;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-calendar3 text-primary me-2"></i>Aylık Akaryakıt Takibi</h4>
    <form class="d-flex align-items-center gap-2">
        <label class="small text-muted mb-0">Dönem</label>
        <select name="donem" class="form-select form-select-sm" style="min-width:170px" onchange="this.form.submit()">
            <?php foreach($donemler as $d): ?>
            <option value="<?= h($d['donem']) ?>" <?= $d['donem']===$sec?'selected':'' ?>><?= h($d['donem']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if($stok): ?>
<div class="row g-2 mb-3">
    <?php
    $kpi=[['Devir','devir','bi-arrow-repeat','text-muted'],['Gelen','gelen','bi-box-arrow-in-down','text-success'],
          ['Toplam','toplam','bi-database','text-primary'],['Kullanılan','kullanilan','bi-speedometer','text-danger'],
          ['Kalan','kalan','bi-fuel-pump-fill','text-primary']];
    foreach($kpi as [$ad,$key,$ik,$cls]): ?>
    <div class="col"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3">
        <div class="text-muted small"><i class="bi <?= $ik ?> me-1"></i><?= $ad ?></div>
        <div class="h5 mb-0 fw-bold <?= $cls ?>"><?= $fmt0($stok[$key]) ?> <small class="fs-6 text-muted">Lt</small></div>
    </div></div></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive" style="max-height:70vh">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
    <thead class="table-light" style="position:sticky;top:0"><tr>
        <th>#</th><th>Sınıfı</th><th>Şoför</th><th>Cinsi</th><th>Firma</th><th>Lokasyon</th>
        <th class="text-end">Tüketim (Lt)</th><th class="text-end">Çalışma</th><th class="text-end">Ort. (lt/km-sa)</th>
        <th class="text-end">İlk Okuma</th><th class="text-end">Son Okuma</th><th class="text-center">Günlük</th>
    </tr></thead>
    <tbody>
    <?php foreach($liste as $ix=>$r): $g=json_decode($r['gunluk']?:'[]',true) ?: []; ?>
        <tr>
            <td class="text-muted"><?= $ix+1 ?></td>
            <td class="small"><?= h($r['sinif']?:'—') ?></td>
            <td class="fw-semibold small"><?= h($r['sofor']) ?></td>
            <td class="small"><?= h($r['cinsi']) ?></td>
            <td class="small text-muted"><?= h($r['firma']?:'—') ?></td>
            <td class="small text-muted"><?= h($r['lokasyon']?:'—') ?></td>
            <td class="text-end font-monospace fw-bold"><?= $fmt0($r['aylik_tuketim']) ?></td>
            <td class="text-end font-monospace"><?= $r['aylik_calisma']!==null?$fmt0($r['aylik_calisma']):'—' ?></td>
            <td class="text-end font-monospace small"><?= $r['ortalama']!==null && (float)$r['ortalama']>0?$fmt2($r['ortalama']):'—' ?></td>
            <td class="text-end font-monospace small"><?= $r['ilk_okuma']!==null && (float)$r['ilk_okuma']>0?$fmt0($r['ilk_okuma']):'—' ?></td>
            <td class="text-end font-monospace small"><?= $r['son_okuma']!==null && (float)$r['son_okuma']>0?$fmt0($r['son_okuma']):'—' ?></td>
            <td class="text-center">
                <?php if($g): ?><button class="btn btn-xs btn-outline-primary" data-gunluk='<?= h(json_encode(["s"=>$r["sofor"],"c"=>$r["cinsi"],"d"=>$sec,"g"=>$g],JSON_UNESCAPED_UNICODE)) ?>' onclick="gunlukAc(this)"><i class="bi bi-calendar-week"></i></button><?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if(!$liste): ?><tr><td colspan="12" class="text-center text-muted py-4">Bu döneme ait tüketim kaydı yok.</td></tr><?php endif; ?>
    </tbody>
    <?php if($liste): ?>
    <tfoot class="table-light" style="position:sticky;bottom:0"><tr class="fw-bold">
        <td colspan="6" class="text-end">TOPLAM</td>
        <td class="text-end font-monospace"><?= $fmt0($topTuk) ?></td>
        <td colspan="5"></td>
    </tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>

<!-- Günlük detay modal -->
<div class="modal fade" id="gunlukModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title" id="gmBaslik"><i class="bi bi-calendar-week me-2"></i>Günlük Tüketim</h6>
        <button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <table class="table table-sm table-striped align-middle mb-0" style="font-size:.85rem">
            <thead class="table-light"><tr><th>Gün</th><th class="text-end">Mazot (Lt)</th><th class="text-end">Km / Mak.Saati</th></tr></thead>
            <tbody id="gmBody"></tbody>
        </table>
    </div>
</div></div></div>
<script>
function gunlukAc(btn){
    const o=JSON.parse(btn.getAttribute('data-gunluk'));
    document.getElementById('gmBaslik').innerHTML='<i class="bi bi-calendar-week me-2"></i>'+o.s+' — '+o.c+' <span class="text-muted small">('+o.d+')</span>';
    const nf=new Intl.NumberFormat('tr-TR');
    let h='', top=0;
    o.g.forEach(function(x){ top+=x.mz; h+='<tr><td>'+x.g+'</td><td class="text-end font-monospace">'+(x.mz?nf.format(x.mz):'—')+'</td><td class="text-end font-monospace">'+(x.km?nf.format(x.km):'—')+'</td></tr>'; });
    h+='<tr class="fw-bold table-light"><td>TOPLAM</td><td class="text-end font-monospace">'+nf.format(top)+'</td><td></td></tr>';
    document.getElementById('gmBody').innerHTML=h;
    new bootstrap.Modal(document.getElementById('gunlukModal')).show();
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
