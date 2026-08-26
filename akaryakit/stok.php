<?php
/** stok.php — Akaryakıt stok hareketi (dönem bazlı devir→gelen→kullanılan→kalan zinciri) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoAkaryakit->query("SELECT 1 FROM akaryakit_donemler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_akaryakit.php'); }

// Elle düzenleme (gelen/devir düzeltme) — admin
if ($_SERVER['REQUEST_METHOD']==='POST' && can_edit() && isset($_POST['donem_id'])) {
    $id=(int)$_POST['donem_id'];
    $devir=ak_sayi($_POST['devir']??''); $gelen=ak_sayi($_POST['gelen']??''); $kullanilan=ak_sayi($_POST['kullanilan']??'');
    $toplam=$devir+$gelen; $kalan=$toplam-$kullanilan;
    $pdoAkaryakit->prepare("UPDATE akaryakit_donemler SET devir=?,gelen=?,toplam=?,kullanilan=?,kalan=? WHERE id=?")
        ->execute([$devir,$gelen,$toplam,$kullanilan,$kalan,$id]);
    flash('success','Stok kaydı güncellendi.'); redirect('stok.php');
}

$rows = $pdoAkaryakit->query("SELECT * FROM akaryakit_donemler ORDER BY donem_sira ASC, id ASC")->fetchAll();
$fmt0 = fn($n)=>number_format((float)$n,0,',','.');
$pageTitle = 'Akaryakıt Stok Hareketi';
require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-fuel-pump text-primary me-2"></i>Akaryakıt Stok Hareketi</h4>
<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<div class="alert alert-light border small"><i class="bi bi-info-circle me-1 text-primary"></i>
    <strong>Stok = Devir + Gelen − Kullanılan.</strong> Her ayın <strong>Kalan</strong> değeri bir sonraki ayın <strong>Devir</strong>'i olmalıdır (zincir). Uyuşmayan geçişler <span class="badge bg-danger">kırmızı</span> gösterilir.</div>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr>
        <th>Dönem</th><th class="text-end">Devir</th><th class="text-end">Gelen (+)</th>
        <th class="text-end">Toplam</th><th class="text-end">Kullanılan (−)</th><th class="text-end">Kalan</th>
        <th class="text-center">Günlük Akış</th>
        <th class="text-center">Zincir</th><?php if(can_edit()): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php $oncekiKalan=null; foreach($rows as $r):
        $devir=(float)$r['devir']; $zincirOk = ($oncekiKalan===null) || (abs($oncekiKalan-$devir)<0.5);
    ?>
        <tr>
            <td class="fw-semibold"><?= h($r['donem']) ?></td>
            <td class="text-end font-monospace <?= (!$zincirOk)?'text-danger fw-bold':'' ?>"><?= $fmt0($devir) ?></td>
            <td class="text-end font-monospace text-success"><?= $fmt0($r['gelen']) ?></td>
            <td class="text-end font-monospace"><?= $fmt0($r['toplam']) ?></td>
            <td class="text-end font-monospace text-danger"><?= $fmt0($r['kullanilan']) ?></td>
            <td class="text-end font-monospace fw-bold text-primary"><?= $fmt0($r['kalan']) ?></td>
            <td class="text-center">
                <?php $gj = json_decode((string)($r['gunluk'] ?? ''), true);
                      $gVar = is_array($gj) && (!empty($gj['gelen']) || !empty($gj['kullanilan'])); ?>
                <?php if ($gVar): ?>
                <button class="btn btn-xs btn-outline-secondary" title="Gün gün gelen / kullanılan / kalan"
                        onclick='gunlukGoster(<?= json_encode(["donem"=>$r["donem"],"devir"=>(float)$r["devir"],"gelen"=>(float)$r["gelen"],"kullanilan"=>(float)$r["kullanilan"],"g"=>$gj],JSON_UNESCAPED_UNICODE) ?>)'>
                    <i class="bi bi-calendar3"></i> <?= count($gj['kullanilan'] ?? []) ?> gün
                </button>
                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <td class="text-center">
                <?php if($oncekiKalan===null): ?><span class="text-muted small">başlangıç</span>
                <?php elseif($zincirOk): ?><i class="bi bi-check-circle-fill text-success"></i>
                <?php else: ?><span class="badge bg-danger" title="Önceki kalan: <?= $fmt0($oncekiKalan) ?>">Δ <?= $fmt0($devir-$oncekiKalan) ?></span><?php endif; ?>
            </td>
            <?php if(can_edit()): ?><td class="text-end">
                <button class="btn btn-xs btn-outline-primary" onclick='stokDuzenle(<?= json_encode(["id"=>(int)$r["id"],"donem"=>$r["donem"],"devir"=>(float)$r["devir"],"gelen"=>(float)$r["gelen"],"kullanilan"=>(float)$r["kullanilan"]],JSON_UNESCAPED_UNICODE) ?>)'><i class="bi bi-pencil"></i></button>
            </td><?php endif; ?>
        </tr>
    <?php $oncekiKalan=(float)$r['kalan']; endforeach; ?>
    <?php if(!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">Kayıt yok. <a href="import.php">Excel içe aktarın</a>.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div></div>

<!-- Günlük stok akışı modali -->
<div class="modal fade" id="gunlukModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title" id="gmBaslik">Günlük Stok Akışı</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div id="gmUyari"></div>
        <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light"><tr>
                <th>Tarih</th><th class="text-end">Gelen (+)</th><th class="text-end">Kullanılan (−)</th><th class="text-end">Gün Sonu Kalan</th>
            </tr></thead>
            <tbody id="gmGovde"></tbody>
        </table></div>
        <div class="form-text mt-2">Excel'in üst bloğundaki gün hücrelerinden okunur: YENİ GELEN satırı = mazotun geldiği gün,
            KULLANILAN satırı = o günün toplam tüketimi. Yalnız hareket olan günler listelenir.</div>
    </div>
</div></div></div>
<script>
function gunlukGoster(o){
    document.getElementById('gmBaslik').textContent = 'Günlük Stok Akışı — ' + o.donem;
    var fmt = n => n.toLocaleString('tr-TR', {maximumFractionDigits: 0});
    var ge = o.g.gelen || {}, ku = o.g.kullanilan || {};
    var geTop = Object.values(ge).reduce((a,b)=>a+ +b, 0);
    var kuTop = Object.values(ku).reduce((a,b)=>a+ +b, 0);
    var ayAd = o.donem.charAt(0) + o.donem.slice(1).toLocaleLowerCase('tr-TR');

    var uy = [];
    if (Math.abs(geTop - o.gelen) > 0.5)
        uy.push('Ay içinde <strong>' + fmt(o.gelen) + ' Lt</strong> mazot gelmiş ama gün hücrelerine yalnız <strong>' +
                fmt(geTop) + ' Lt</strong> yazılmış — <strong>' + fmt(o.gelen - geTop) +
                ' Lt</strong>\'nin geliş günü Excel\'de girilmemiş (günlük kalan bu yüzden yaklaşıktır).');
    if (Math.abs(kuTop - o.kullanilan) > 0.5)
        uy.push('Günlük kullanılan toplamı (' + fmt(kuTop) + ' Lt) aylık KULLANILAN (' + fmt(o.kullanilan) + ' Lt) ile uyuşmuyor.');
    document.getElementById('gmUyari').innerHTML = uy.length
        ? '<div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + uy.join('<br>') + '</div>' : '';

    var html = '<tr class="table-light"><td class="fw-semibold">Devir (ay başı)</td><td></td><td></td>' +
               '<td class="text-end font-monospace fw-bold">' + fmt(o.devir) + ' Lt</td></tr>';
    var kalan = o.devir;
    for (var d = 1; d <= 31; d++) {
        var g = +(ge[d] || ge[String(d)] || 0), k = +(ku[d] || ku[String(d)] || 0);
        if (!g && !k) continue;
        kalan += g - k;
        html += '<tr><td>' + d + ' ' + ayAd + '</td>' +
                '<td class="text-end font-monospace text-success">' + (g ? '+' + fmt(g) : '') + '</td>' +
                '<td class="text-end font-monospace text-danger">' + (k ? '−' + fmt(k) : '') + '</td>' +
                '<td class="text-end font-monospace">' + fmt(kalan) + ' Lt</td></tr>';
    }
    html += '<tr class="table-light fw-bold"><td>Ay sonu (Excel)</td>' +
            '<td class="text-end font-monospace text-success">+' + fmt(o.gelen) + '</td>' +
            '<td class="text-end font-monospace text-danger">−' + fmt(o.kullanilan) + '</td>' +
            '<td class="text-end font-monospace">' + fmt(o.devir + o.gelen - o.kullanilan) + ' Lt</td></tr>';
    document.getElementById('gmGovde').innerHTML = html;
    new bootstrap.Modal(document.getElementById('gunlukModal')).show();
}
</script>

<?php if(can_edit()): ?>
<div class="modal fade" id="stokModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
    <div class="modal-header"><h6 class="modal-title" id="smBaslik">Stok Düzenle</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="donem_id" id="smId">
        <div class="row g-3">
            <div class="col-4"><label class="form-label small">Devir</label><input name="devir" id="smDevir" class="form-control text-end" inputmode="decimal"></div>
            <div class="col-4"><label class="form-label small text-success">Gelen (+)</label><input name="gelen" id="smGelen" class="form-control text-end" inputmode="decimal"></div>
            <div class="col-4"><label class="form-label small text-danger">Kullanılan (−)</label><input name="kullanilan" id="smKull" class="form-control text-end" inputmode="decimal"></div>
        </div>
        <div class="form-text mt-2">Toplam ve Kalan otomatik hesaplanır.</div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Kaydet</button></div>
    </form>
</div></div></div>
<script>
function stokDuzenle(o){
    document.getElementById('smBaslik').textContent='Stok Düzenle — '+o.donem;
    document.getElementById('smId').value=o.id;
    document.getElementById('smDevir').value=o.devir;
    document.getElementById('smGelen').value=o.gelen;
    document.getElementById('smKull').value=o.kullanilan;
    new bootstrap.Modal(document.getElementById('stokModal')).show();
}
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
