<?php
/**
 * index.php — CRM (Üretim Arızaları) dashboard
 *
 * Veri kaynağı günlük CRM raporudur; sayfanın üstündeki bant son yüklemeyi ve
 * o yüklemede kaç arızanın açıldığını/kapandığını gösterir — "rapor güncel mi?"
 * sorusunun cevabı burada.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_crm.php';
require_once __DIR__ . '/_ortak.php';

crm_semasi_kur($pdoCrm);
$pageTitle = 'CRM Dashboard';

$ozet  = crm_ozet($pdoCrm);
$son   = crm_son_import($pdoCrm);
$bos   = $ozet['toplam'] === 0;

// Aylık gelen / çözülen / birikmiş açık (boş aylar dahil, son 14 ay)
$aylik = crm_aylik_seri($pdoCrm, 14);
$hicCozum = !array_filter($aylik, fn($a) => $a['cozulen'] > 0);

$tur   = $pdoCrm->query("SELECT sikayet_turu ad, COUNT(*) toplam, SUM(durum='acik') acik FROM crm_arizalar
                         WHERE sikayet_turu IS NOT NULL GROUP BY sikayet_turu ORDER BY toplam DESC")->fetchAll();
$konu  = $pdoCrm->query("SELECT sikayet_konusu ad, COUNT(*) toplam, SUM(durum='acik') acik FROM crm_arizalar
                         WHERE sikayet_konusu IS NOT NULL GROUP BY sikayet_konusu ORDER BY toplam DESC LIMIT 12")->fetchAll();
$blok  = $pdoCrm->query("SELECT blok ad, COUNT(*) toplam, SUM(durum='acik') acik FROM crm_arizalar
                         WHERE blok IS NOT NULL AND blok <> '' GROUP BY blok ORDER BY blok")->fetchAll();
$tip   = $pdoCrm->query("SELECT ariza_tipi ad, COUNT(*) toplam, SUM(durum='acik') acik FROM crm_arizalar
                         WHERE ariza_tipi IS NOT NULL AND ariza_tipi <> '' GROUP BY ariza_tipi
                         ORDER BY toplam DESC LIMIT 10")->fetchAll();
$daire = $pdoCrm->query("SELECT konut, blok, kat, daire_no, COUNT(*) toplam, SUM(durum='acik') acik
                         FROM crm_arizalar WHERE konut IS NOT NULL AND konut <> ''
                         GROUP BY konut, blok, kat, daire_no ORDER BY toplam DESC, acik DESC LIMIT 10")->fetchAll();
$sonGelen = $pdoCrm->query("SELECT id, konut, blok, sikayet_konusu, sikayet_aciklamasi, ariza_tipi, olusturma, durum
                            FROM crm_arizalar ORDER BY olusturma DESC, id DESC LIMIT 10")->fetchAll();
$enEski   = $pdoCrm->query("SELECT id, konut, sikayet_konusu, ariza_tipi, olusturma,
                                   DATEDIFF(NOW(), olusturma) gun
                            FROM crm_arizalar WHERE durum='acik' AND olusturma IS NOT NULL
                            ORDER BY olusturma LIMIT 10")->fetchAll();

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f1 = fn($n) => number_format((float)$n, 1, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-headset text-primary me-2"></i>CRM — Üretim Arızaları</h4>
    <div class="ms-auto d-flex gap-2">
        <a href="arizalar.php?durum=acik" class="btn btn-outline-danger btn-sm"><i class="bi bi-exclamation-circle me-1"></i>Açık Arızalar</a>
        <a href="raporlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart-line me-1"></i>Raporlar</a>
        <?php if (has_role('admin','teknik_ofis_admin')): ?>
        <a href="import.php" class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Günlük Rapor</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($bos): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i><strong>Henüz veri yok.</strong>
    CRM'den aldığınız <em>UretimArizalari</em> Excel raporunu
    <a href="import.php" class="alert-link">Günlük Rapor</a> ekranından yükleyin — dashboard ve raporlar
    her yüklemede kendiliğinden güncellenir.
</div>
<?php else: ?>

<!-- Rapor güncelliği bandı -->
<div class="alert <?= $son && strtotime($son['created']) > strtotime('-2 days') ? 'alert-success' : 'alert-warning' ?> py-2 small d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-clock-history"></i>
    <?php if ($son): ?>
        <span>Son rapor <strong><?= h(date('d.m.Y H:i', strtotime($son['created']))) ?></strong> yüklendi
        (<?= h($son['dosya'] ?: 'dosya adı yok') ?>)</span>
        <span class="badge bg-danger"><?= $f0($son['yeni']) ?> yeni</span>
        <span class="badge bg-success"><?= $f0($son['kapanan']) ?> kapandı</span>
        <span class="badge bg-secondary"><?= $f0($son['satir']) ?> satır</span>
        <?php if (strtotime($son['created']) <= strtotime('-2 days')): ?>
        <span class="text-danger fw-semibold">— 2 günden eski, yeni raporu yükleyin.</span>
        <?php endif; ?>
    <?php else: ?>
        <span>Yükleme geçmişi yok.</span>
    <?php endif; ?>
    <a href="import.php" class="ms-auto">rapor yükle →</a>
</div>

<div class="row g-2 mb-3">
<?php
$kpi = [
    ['Açık arıza', $f0($ozet['acik']), 'danger', 'bi-exclamation-triangle-fill', 'arizalar.php?durum=acik'],
    ['Bu ay yeni', $f0($ozet['buAyYeni']), 'primary', 'bi-plus-circle-fill', 'arizalar.php?bas=' . date('Y-m-01')],
    ['Bu ay çözülen', $f0($ozet['buAyCozulen']), 'success', 'bi-check-circle-fill', 'arizalar.php?durum=cozuldu'],
    ['Ort. açık kalma', $f1($ozet['ortAcikGun']) . ' gün', 'info', 'bi-hourglass-split', null],
    ['30+ gündür açık', $f0($ozet['eski30']), 'warning', 'bi-clock-fill', 'arizalar.php?durum=acik'],
    ['Toplam kayıt', $f0($ozet['toplam']), 'secondary', 'bi-list-check', 'arizalar.php'],
];
foreach ($kpi as [$ad, $deger, $renk, $ikon, $link]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <?php if ($link): ?><a href="<?= h($link) ?>" class="text-decoration-none text-reset"><?php endif; ?>
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3 text-center">
            <i class="bi <?= $ikon ?> text-<?= $renk ?> fs-4"></i>
            <div class="fs-4 fw-bold mt-1"><?= $deger ?></div>
            <div class="small text-muted"><?= $ad ?></div>
        </div></div>
        <?php if ($link): ?></a><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="d-flex align-items-center mb-2">
                <span class="fw-semibold"><i class="bi bi-graph-up-arrow me-1"></i>Aylık gelen / çözülen arıza</span>
                <span class="ms-auto small text-muted">çubuklar: ay içi · çizgi: ay sonunda birikmiş açık</span>
            </div>
            <div style="height:300px"><canvas id="chAylik"></canvas></div>
            <?php if ($hicCozum): ?>
            <div class="form-text mt-1"><i class="bi bi-info-circle me-1"></i>Henüz kapanan arıza yok — "çözülen"
                serisi <strong>ikinci günlük rapordan</strong> itibaren dolar (bir arıza raporda görünmez olduğunda
                kapanmış sayılır).</div>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-pie-chart me-1"></i>Şikayet türü</div>
            <div style="height:300px"><canvas id="chTur"></canvas></div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-building me-1"></i>Blok bazında arıza (açık / toplam)</div>
            <div style="height:260px"><canvas id="chBlok"></canvas></div>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-list-ol me-1"></i>En sık görülen arıza tipleri</div>
            <div style="height:260px"><canvas id="chTip"></canvas></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white small fw-semibold"><i class="bi bi-clock me-1"></i>En uzun süredir açık</div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <tbody>
                <?php foreach ($enEski as $e): ?>
                    <tr>
                        <td><a href="ariza_detay.php?id=<?= (int)$e['id'] ?>" class="text-decoration-none"><?= h($e['konut']) ?></a>
                            <div class="text-muted text-truncate" style="max-width:200px"><?= h($e['ariza_tipi']) ?></div></td>
                        <td class="text-end align-middle"><span class="badge bg-danger"><?= $f0($e['gun']) ?> gün</span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$enEski): ?><tr><td class="text-center text-success py-3">Açık arıza yok 🎉</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white small fw-semibold"><i class="bi bi-house-exclamation me-1"></i>En çok arıza kaydı olan daireler</div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <thead class="table-light"><tr><th>Konut</th><th class="text-end">Açık</th><th class="text-end">Toplam</th></tr></thead>
                <tbody>
                <?php foreach ($daire as $d): ?>
                    <tr>
                        <td><a href="arizalar.php?konut=<?= urlencode($d['konut']) ?>" class="text-decoration-none"><?= h($d['konut']) ?></a>
                            <div class="text-muted"><?= h($d['blok']) ?> · <?= h($d['kat']) ?></div></td>
                        <td class="text-end align-middle"><span class="badge bg-danger"><?= $f0($d['acik']) ?></span></td>
                        <td class="text-end align-middle fw-semibold"><?= $f0($d['toplam']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white small fw-semibold"><i class="bi bi-clock-history me-1"></i>Son gelen arızalar</div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <tbody>
                <?php foreach ($sonGelen as $s): ?>
                    <tr>
                        <td>
                            <a href="ariza_detay.php?id=<?= (int)$s['id'] ?>" class="text-decoration-none"><?= h($s['konut']) ?></a>
                            <span class="badge bg-<?= crm_durumRenk($s['durum']) ?> ms-1"><?= h(crm_durumAd($s['durum'])) ?></span>
                            <div class="text-muted text-truncate" style="max-width:210px">
                                <?= h($s['sikayet_konusu']) ?> · <?= h($s['ariza_tipi']) ?></div>
                        </td>
                        <td class="text-end align-middle text-nowrap text-muted">
                            <?= $s['olusturma'] ? h(date('d.m.y', strtotime($s['olusturma']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>

<script>
(function(){
    const AY = <?= json_encode($aylik, JSON_UNESCAPED_UNICODE) ?>;
    const TUR  = <?= json_encode($tur, JSON_UNESCAPED_UNICODE) ?>;
    const BLOK = <?= json_encode($blok, JSON_UNESCAPED_UNICODE) ?>;
    const TIP  = <?= json_encode($tip, JSON_UNESCAPED_UNICODE) ?>;
    const ayEt = a => { const [y,m] = a.split('-'); return ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'][+m-1] + ' ' + y.slice(2); };

    // Çubuklar ayın kendi hareketi, çizgi ay sonunda BİRİKMİŞ açık arıza (sağ eksen) —
    // ilk raporda hiç kapanış olmadığından yalnız iki çizgi olsa grafik boş görünüyordu.
    new Chart(document.getElementById('chAylik'), {
        data: { labels: AY.map(a => ayEt(a.ay)), datasets: [
            { type:'bar', label:'Gelen', data: AY.map(a=>a.gelen), backgroundColor:'#dc3545', yAxisID:'y' },
            { type:'bar', label:'Çözülen', data: AY.map(a=>a.cozulen), backgroundColor:'#198754', yAxisID:'y' },
            { type:'line', label:'Birikmiş açık', data: AY.map(a=>a.acik), borderColor:'#0d6efd',
              backgroundColor:'rgba(13,110,253,.10)', borderWidth:2, pointRadius:2, tension:.3, fill:true, yAxisID:'y1' }
        ]},
        options: { responsive:true, maintainAspectRatio:false, interaction:{mode:'index', intersect:false},
                   plugins:{legend:{position:'bottom'}},
                   scales:{ y:{ beginAtZero:true, ticks:{precision:0}, title:{display:true, text:'ay içi adet'} },
                            y1:{ position:'right', beginAtZero:true, ticks:{precision:0}, grid:{drawOnChartArea:false},
                                 title:{display:true, text:'birikmiş açık'} } } }
    });

    new Chart(document.getElementById('chTur'), {
        type: 'doughnut',
        data: { labels: TUR.map(t=>t.ad), datasets:[{ data: TUR.map(t=>+t.toplam),
                 backgroundColor:['#0d6efd','#fd7e14','#20c997','#6f42c1','#dc3545','#ffc107'] }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
    });

    new Chart(document.getElementById('chBlok'), {
        type: 'bar',
        data: { labels: BLOK.map(b=>b.ad), datasets:[
            { label:'Açık', data: BLOK.map(b=>+b.acik), backgroundColor:'#dc3545' },
            { label:'Çözülen', data: BLOK.map(b=>b.toplam-b.acik), backgroundColor:'#198754' }
        ]},
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}},
                   scales:{ x:{stacked:true}, y:{stacked:true, beginAtZero:true, ticks:{precision:0}} } }
    });

    new Chart(document.getElementById('chTip'), {
        type: 'bar',
        data: { labels: TIP.map(t => t.ad.length > 28 ? t.ad.slice(0,28) + '…' : t.ad),
                datasets:[{ label:'Kayıt', data: TIP.map(t=>+t.toplam), backgroundColor:'#0d6efd' }] },
        options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}},
                   scales:{ x:{ beginAtZero:true, ticks:{precision:0} } } }
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
