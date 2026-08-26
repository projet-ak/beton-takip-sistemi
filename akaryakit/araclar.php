<?php
/** araclar.php — Araç / makine kayıtları (akaryakıt) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoAkaryakit->query("SELECT 1 FROM akaryakit_araclar LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_akaryakit.php'); }

// Araç yakıt geçmişi (AJAX): ?gecmis=arac_id → dönem dönem + gün gün alım listesi
if (isset($_GET['gecmis']) && ctype_digit($_GET['gecmis'])) {
    header('Content-Type: application/json; charset=utf-8');
    $st = $pdoAkaryakit->prepare("SELECT donem, donem_sira, aylik_tuketim, aylik_calisma, gunluk
                                  FROM akaryakit_tuketim WHERE arac_id=? ORDER BY donem_sira, id");
    $st->execute([(int)$_GET['gecmis']]);
    $out = [];
    foreach ($st->fetchAll() as $t) {
        $g = json_decode((string)$t['gunluk'], true);
        $out[] = ['donem' => $t['donem'], 'aylik' => (float)$t['aylik_tuketim'],
                  'calisma' => $t['aylik_calisma'] !== null ? (float)$t['aylik_calisma'] : null,
                  'gunluk' => is_array($g) ? $g : []];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}

if (isset($_GET['sil']) && ctype_digit($_GET['sil']) && can_edit()) {
    $c=$pdoAkaryakit->prepare("SELECT COUNT(*) FROM akaryakit_tuketim WHERE arac_id=?"); $c->execute([(int)$_GET['sil']]);
    if ((int)$c->fetchColumn()>0) { flash('error','Bu araç tüketim kayıtlarında kullanılıyor, silinemez.'); }
    else { $pdoAkaryakit->prepare("DELETE FROM akaryakit_araclar WHERE id=?")->execute([(int)$_GET['sil']]); flash('success','Araç silindi.'); }
    redirect('araclar.php');
}

$ara = trim($_GET['ara'] ?? '');
$where=['aktif=1']; $par=[];
if ($ara!=='') { $where[]='(a.sofor LIKE ? OR a.cinsi LIKE ? OR a.firma LIKE ? OR a.plaka LIKE ? OR a.mak_no LIKE ?)'; for($j=0;$j<5;$j++)$par[]="%$ara%"; }
$wsql='WHERE '.implode(' AND ',$where);
$q=$pdoAkaryakit->prepare("SELECT a.*, COALESCE(SUM(t.aylik_tuketim),0) toplam_tuketim, COUNT(t.id) donem_say
    FROM akaryakit_araclar a LEFT JOIN akaryakit_tuketim t ON t.arac_id=a.id
    $wsql GROUP BY a.id ORDER BY a.sofor, a.cinsi");
$q->execute($par); $liste=$q->fetchAll();
$fmt0=fn($n)=>number_format((float)$n,0,',','.');
$pageTitle='Araçlar / Makineler — Akaryakıt';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div><h4 class="mb-0"><i class="bi bi-truck text-primary me-2"></i>Araçlar / Makineler</h4>
        <small class="text-muted"><strong><?= count($liste) ?></strong> kayıt</small></div>
    <?php if(can_edit()): ?><a href="arac_form.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Yeni Araç</a><?php endif; ?>
</div>
<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<form class="row g-2 mb-3">
    <div class="col-md-5"><input name="ara" value="<?= h($ara) ?>" class="form-control form-control-sm" placeholder="Şoför / cinsi / firma / plaka ara"></div>
    <div class="col-md-2"><button class="btn btn-outline-primary btn-sm w-100">Filtrele</button></div>
    <div class="col-md-2"><a href="araclar.php" class="btn btn-outline-secondary btn-sm w-100">Temizle</a></div>
</form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive" style="max-height:70vh">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
    <thead class="table-light" style="position:sticky;top:0"><tr>
        <th>Sınıfı</th><th>Şoför</th><th>Cinsi</th><th>Firma</th><th>Lokasyon</th><th>Plaka</th><th>Mak.No</th>
        <th class="text-end">Toplam Tüketim (Lt)</th><th class="text-center">Dönem</th><?php if(can_edit()): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach($liste as $r): ?>
        <tr>
            <td class="small"><?= h($r['sinif']?:'—') ?></td>
            <td class="fw-semibold small"><?= h($r['sofor']) ?></td>
            <td class="small"><?= h($r['cinsi']) ?></td>
            <td class="small text-muted"><?= h($r['firma']?:'—') ?></td>
            <td class="small text-muted"><?= h($r['lokasyon']?:'—') ?></td>
            <td class="small font-monospace"><?= h($r['plaka']?:'—') ?></td>
            <td class="small font-monospace"><?= h($r['mak_no']?:'—') ?></td>
            <td class="text-end font-monospace fw-bold">
                <?php if ((int)$r['donem_say'] > 0): ?>
                <a href="#" class="text-decoration-none" title="Tarih tarih yakıt alımlarını göster"
                   onclick='aracGecmis(<?= (int)$r['id'] ?>, <?= json_encode(trim($r['sofor'].' — '.$r['cinsi']), JSON_UNESCAPED_UNICODE) ?>); return false;'>
                    <?= $fmt0($r['toplam_tuketim']) ?> <i class="bi bi-calendar3 small text-muted"></i>
                </a>
                <?php else: ?><?= $fmt0($r['toplam_tuketim']) ?><?php endif; ?>
            </td>
            <td class="text-center"><span class="badge bg-light text-dark"><?= (int)$r['donem_say'] ?></span></td>
            <?php if(can_edit()): ?><td class="text-nowrap text-end">
                <a href="arac_form.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="bi bi-pencil"></i></a>
                <a href="araclar.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Silinsin mi?')"><i class="bi bi-trash"></i></a>
            </td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if(!$liste): ?><tr><td colspan="10" class="text-center text-muted py-4">Kayıt yok. <a href="import.php">Excel içe aktarın</a>.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div></div>

<!-- Araç yakıt geçmişi modali: kim, ne zaman, ne kadar aldı -->
<div class="modal fade" id="gecmisModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title" id="agBaslik">Yakıt Geçmişi</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div id="agIcerik" class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm me-2"></div>Yükleniyor…</div>
    </div>
</div></div></div>
<script>
function aracGecmis(id, ad) {
    document.getElementById('agBaslik').textContent = 'Yakıt Geçmişi — ' + ad;
    var kutu = document.getElementById('agIcerik');
    kutu.innerHTML = '<div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm me-2"></div>Yükleniyor…</div>';
    new bootstrap.Modal(document.getElementById('gecmisModal')).show();
    fetch('araclar.php?gecmis=' + id)
        .then(r => r.json())
        .then(donemler => {
            var fmt = n => (+n).toLocaleString('tr-TR', {maximumFractionDigits: 0});
            if (!donemler.length) { kutu.innerHTML = '<div class="text-muted">Bu araca ait tüketim kaydı yok.</div>'; return; }
            var html = '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0">' +
                '<thead class="table-light"><tr><th>Tarih</th><th class="text-end">Mazot (Lt)</th><th class="text-end">Km / Mak.Saati</th></tr></thead><tbody>';
            var genelToplam = 0, gunSay = 0;
            donemler.forEach(dn => {
                var ayAd = dn.donem.charAt(0) + dn.donem.slice(1).toLocaleLowerCase('tr-TR');
                html += '<tr class="table-light fw-semibold"><td colspan="3">' + ayAd +
                        ' — aylık toplam ' + fmt(dn.aylik) + ' Lt' +
                        (dn.calisma !== null ? ' · çalışma ' + fmt(dn.calisma) : '') + '</td></tr>';
                if (dn.gunluk.length) {
                    dn.gunluk.forEach(g => {
                        if (!+g.mz && !+g.km) return;
                        gunSay++;
                        html += '<tr><td>' + g.g + ' ' + ayAd + '</td>' +
                                '<td class="text-end font-monospace">' + (+g.mz ? fmt(g.mz) : '—') + '</td>' +
                                '<td class="text-end font-monospace text-muted">' + (+g.km ? fmt(g.km) : '') + '</td></tr>';
                    });
                } else {
                    html += '<tr><td colspan="3" class="text-muted small">Bu ay için günlük detay Excel\'de girilmemiş (yalnız aylık toplam var).</td></tr>';
                }
                genelToplam += +dn.aylik;
            });
            html += '</tbody><tfoot class="table-light fw-bold"><tr><td>Genel Toplam (' + gunSay + ' günlük alım)</td>' +
                    '<td class="text-end font-monospace">' + fmt(genelToplam) + ' Lt</td><td></td></tr></tfoot></table></div>';
            kutu.innerHTML = html;
        })
        .catch(() => { kutu.innerHTML = '<div class="text-danger">Geçmiş yüklenemedi.</div>'; });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
