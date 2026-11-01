<?php
/**
 * index.php — Prekast Takip dashboard (canlı)
 *
 * Veri kaynağı sahadan günlük gelen iş takip / hakkediş çizelgesidir. Üstteki bant
 * son yüklemeyi ve o yüklemede kaç işin ilerlediğini gösterir — "çizelge güncel mi?"
 * sorusunun cevabı burada. Grafikler her yüklemede kendiliğinden güncellenir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_prekast.php';
require_once __DIR__ . '/_ortak.php';

pk_semasi_kur($pdoPrekast);
$pageTitle = 'Prekast Dashboard';

// Arşivdeki (son çizelgede olmayan) işleri çizelgeye geri al — kayıtlar zaten silinmemişti, yalnız
// "dosyada" işareti açılır. Eski iş sayfası taşıyan kitaplar ölçülmüş işleri arşive düşürüyordu;
// kural "dosyadaki tüm veriler olsun, çizelge yalnız dolsun" (CLAUDE.md Prekast §17.09).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'arsiv_geri') {
    if (!yetki_var('duzenle')) { flash('error', 'Bu işlem için değiştirme yetkisi gerekir.'); redirect('index.php'); }
    $cz = trim((string)($_POST['cizelge'] ?? ''));
    $sql = 'UPDATE prekast_isler SET dosyada = 1 WHERE dosyada = 0' . ($cz !== '' ? ' AND cizelge = ?' : '');
    $st = $pdoPrekast->prepare($sql);
    $st->execute($cz !== '' ? [$cz] : []);
    $n = $st->rowCount();
    require_once __DIR__ . '/../includes/db.php';   // $pdo — audit_log ana veritabanında
    audit_log($pdo, 'prekast_isler', 0, 'UPDATE', ['dosyada' => 0, 'cizelge' => $cz ?: 'tümü'],
        ['dosyada' => 1, 'adet' => $n], current_user_id());
    flash('success', $n . ' iş arşivden çizelgeye geri alındı; metraj ve hakkedişleri artık toplamlara giriyor.');
    redirect('index.php' . ($cz !== '' ? '?cizelge=' . urlencode($cz) : ''));
}

$cizelgeler = pk_secenekler($pdoPrekast, 'cizelge');
$secili = trim((string)($_GET['cizelge'] ?? ''));
if ($secili !== '' && !in_array($secili, $cizelgeler, true)) $secili = '';   // whitelist

$ozet = pk_ozet($pdoPrekast, $secili);
$son  = pk_son_import($pdoPrekast);
$bos  = $ozet['toplam'] === 0 && $ozet['dusen'] === 0;

$cw = $secili !== '' ? ' AND cizelge = ?' : '';
$cp = $secili !== '' ? [$secili] : [];

// Blok bazında ilerleme
$blok = $pdoPrekast->prepare("SELECT blok ad, COUNT(*) toplam, SUM(silikon=1) tamam, SUM(kesim=1) kesim,
                                     COALESCE(SUM(metraj),0) metraj, COALESCE(SUM(hakkedis),0) hakkedis
                              FROM prekast_isler WHERE dosyada=1 $cw GROUP BY blok ORDER BY blok");
$blok->execute($cp); $blok = $blok->fetchAll();

// Silikon bekleyen işler (kesim yapılmış, silikon yok) — sahada sıradaki iş
$bekleyen = $pdoPrekast->prepare("SELECT id, blok, daire, kesim_tarih, DATEDIFF(NOW(), kesim_tarih) gun
                                  FROM prekast_isler WHERE dosyada=1 AND kesim=1 AND silikon=0 $cw
                                  ORDER BY kesim_tarih IS NULL, kesim_tarih, blok, daire_sira LIMIT 12");
$bekleyen->execute($cp); $bekleyen = $bekleyen->fetchAll();

// Son tamamlananlar
$sonTamam = $pdoPrekast->prepare("SELECT id, blok, daire, metraj, hakkedis, silikon_tarih
                                  FROM prekast_isler WHERE silikon=1 AND silikon_tarih IS NOT NULL $cw
                                  ORDER BY silikon_tarih DESC, id DESC LIMIT 12");
$sonTamam->execute($cp); $sonTamam = $sonTamam->fetchAll();

// En büyük metrajlı daireler (hakkediş ağırlığı)
$enBuyuk = $pdoPrekast->prepare("SELECT blok, daire, COUNT(*) is_adedi, COALESCE(SUM(metraj),0) metraj,
                                        COALESCE(SUM(hakkedis),0) hakkedis
                                 FROM prekast_isler WHERE dosyada=1 AND silikon=1 $cw
                                 GROUP BY blok, daire ORDER BY metraj DESC LIMIT 10");
$enBuyuk->execute($cp); $enBuyuk = $enBuyuk->fetchAll();

$seri = pk_gunluk_seri($pdoPrekast, 60);

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f1 = fn($n) => number_format((float)$n, 1, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-bricks text-primary me-2"></i>Prekast Takip</h4>
    <div class="ms-auto d-flex flex-wrap gap-2">
        <?php if (count($cizelgeler) > 1): ?>
        <form method="get" class="d-flex gap-2">
            <select name="cizelge" class="form-select form-select-sm" style="max-width:340px" onchange="this.form.submit()">
                <option value="">Tüm çizelgeler</option>
                <?php foreach ($cizelgeler as $c): ?>
                    <option value="<?= h($c) ?>" <?= $secili === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
        <a href="icmal.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-table me-1"></i>Blok İcmali</a>
        <a href="isler.php?durum=kesim" class="btn btn-outline-warning btn-sm"><i class="bi bi-hourglass-split me-1"></i>Silikon Bekleyen</a>
        <a href="raporlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart-line me-1"></i>Raporlar</a>
        <?php if (has_role('admin','teknik_ofis_admin')): ?>
        <a href="import.php" class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Günlük Çizelge</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($bos): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i><strong>Henüz veri yok.</strong>
    Sahadan gelen <em>iş takip / hakkediş çizelgesi</em> Excel'ini
    <a href="import.php" class="alert-link">Günlük Çizelge</a> ekranından yükleyin — dashboard ve raporlar
    her yüklemede kendiliğinden güncellenir.
    <div class="small mt-1">Tablolar kurulmadıysa önce <a href="kurulum_prekast.php" class="alert-link">kurulum</a> sayfasını açın.</div>
</div>
<?php else: ?>

<div class="alert <?= $son && strtotime($son['created']) > strtotime('-2 days') ? 'alert-success' : 'alert-warning' ?> py-2 small d-flex flex-wrap align-items-center gap-2">
    <i class="bi bi-clock-history"></i>
    <?php if ($son): ?>
        <span>Son çizelge <strong><?= h(date('d.m.Y H:i', strtotime($son['created']))) ?></strong> yüklendi
            (rapor tarihi <?= h(date('d.m.Y', strtotime($son['rapor_tarihi']))) ?><?= $son['dosya'] ? ' · ' . h($son['dosya']) : '' ?>)</span>
        <span class="badge bg-primary"><?= $f0($son['yeni_satir']) ?> yeni iş</span>
        <span class="badge bg-warning text-dark"><?= $f0($son['yeni_kesim']) ?> yeni kesim</span>
        <span class="badge bg-success"><?= $f0($son['yeni_silikon']) ?> yeni silikon</span>
        <?php if (strtotime($son['created']) <= strtotime('-2 days')): ?>
        <span class="text-danger fw-semibold">— 2 günden eski, yeni çizelgeyi yükleyin.</span>
        <?php endif; ?>
    <?php else: ?>
        <span>Yükleme geçmişi yok.</span>
    <?php endif; ?>
    <a href="import.php" class="ms-auto">çizelge yükle →</a>
</div>

<?php if ($ozet['dusen']): ?>
<div class="alert <?= $ozet['dusenHakkedis'] > 0 ? 'alert-warning' : 'alert-secondary' ?> py-2 small d-flex flex-wrap align-items-center gap-2">
    <span><i class="bi bi-archive me-1"></i><strong><?= $f0($ozet['dusen']) ?> iş</strong> son çizelgede yok
    (silinmedi, arşivde duruyor)<?php if ($ozet['dusenHakkedis'] > 0): ?> — içlerinde
    <strong><?= $f0($ozet['dusenTamam']) ?> tamamlanmış iş · <?= $f2($ozet['dusenMetraj']) ?> m ·
    <?= $f0($ozet['dusenHakkedis']) ?> TL hakkediş</strong> var ve aşağıdaki toplamlara <strong>girmiyor</strong>.
    Genelde sebep, yüklenen kitabın iş sayfasının eski olmasıdır<?php endif; ?>.
    <a href="isler.php?dosyada=0" class="alert-link">listele →</a></span>
    <?php if (yetki_var('duzenle')): ?>
    <form method="post" class="ms-auto" onsubmit="return confirm('Arşivdeki <?= (int)$ozet['dusen'] ?> iş çizelgeye geri alınacak ve toplamlara girecek. Devam edilsin mi?')">
        <input type="hidden" name="islem" value="arsiv_geri">
        <input type="hidden" name="cizelge" value="<?= h($secili) ?>">
        <button class="btn btn-sm btn-outline-dark"><i class="bi bi-arrow-counterclockwise me-1"></i>Çizelgeye geri al</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-2 mb-3">
<?php
$kpi = [
    ['Toplam iş', $f0($ozet['toplam']), 'secondary', 'bi-list-check', 'isler.php'],
    ['Tamamlanan', $f0($ozet['silikon']), 'success', 'bi-check-circle-fill', 'isler.php?durum=tamam',
        $ozet['olculmemis'] ? $f0($ozet['olculmemis']) . ' tanesinin metrajı ölçülmemiş' : ''],
    ['Silikon bekleyen', $f0($ozet['silikonBekleyen']), 'warning', 'bi-hourglass-split', 'isler.php?durum=kesim'],
    ['Kesim bekleyen', $f0($ozet['kesimBekleyen']), 'danger', 'bi-scissors', 'isler.php?durum=bekliyor'],
    ['Toplam metraj', $f2($ozet['metraj']) . ' m', 'info', 'bi-rulers', null],
    ['Hakkediş', $f0($ozet['hakkedis']) . ' TL', 'primary', 'bi-cash-coin', 'raporlar.php'],
];
foreach ($kpi as $__k): [$ad, $deger, $renk, $ikon, $link] = $__k; $alt = $__k[5] ?? ''; ?>
    <div class="col-6 col-md-4 col-xl-2">
        <?php if ($link): ?><a href="<?= h($link) ?>" class="text-decoration-none text-reset"><?php endif; ?>
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3 text-center">
            <i class="bi <?= $ikon ?> text-<?= $renk ?> fs-4"></i>
            <div class="fs-5 fw-bold mt-1"><?= $deger ?></div>
            <div class="small text-muted"><?= $ad ?></div>
            <?php if ($alt !== ''): ?><div class="small text-warning-emphasis" style="font-size:.72rem"><?= h($alt) ?></div><?php endif; ?>
        </div></div>
        <?php if ($link): ?></a><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body py-3">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1 small">
        <span class="fw-semibold"><i class="bi bi-graph-up me-1"></i>Genel tamamlanma</span>
        <span class="ms-auto text-muted">
            <?= $f0($ozet['silikon']) ?> / <?= $f0($ozet['toplam']) ?> iş ·
            kesim <?= $f0($ozet['kesim']) ?> · bekleyen işlerin tahmini hakkedişi
            <strong><?= $f0($ozet['tahminiKalan']) ?> TL</strong>
            (ölçülen <?= $f0($ozet['olculen']) ?> işin ort. <?= $f2($ozet['ortMetraj']) ?> m × <?= $f0($ozet['birimFiyat']) ?> TL)
        </span>
    </div>
    <div class="progress" style="height:22px">
        <div class="progress-bar bg-success" style="width:<?= (float)$ozet['oran'] ?>%">
            <?= $f1($ozet['oran']) ?>% tamamlandı</div>
    </div>
</div></div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="d-flex align-items-center mb-2">
                <span class="fw-semibold"><i class="bi bi-graph-up-arrow me-1"></i>Günlük ilerleme</span>
                <span class="ms-auto small text-muted">çubuklar: o gün tamamlanan · çizgi: toplam tamamlanan</span>
            </div>
            <div style="height:300px"><canvas id="chSeri"></canvas></div>
            <?php if (count($seri) < 2): ?>
            <div class="form-text mt-1"><i class="bi bi-info-circle me-1"></i>Trend, <strong>ikinci günlük
                çizelgeden</strong> itibaren anlam kazanır — ilk yüklemede tüm işler aynı güne damgalanır.</div>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-pie-chart me-1"></i>İş durumu</div>
            <div style="height:300px"><canvas id="chDurum"></canvas></div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-building me-1"></i>Blok bazında ilerleme</div>
            <div style="height:260px"><canvas id="chBlok"></canvas></div>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-cash-stack me-1"></i>Blok bazında hakkediş (TL)</div>
            <div style="height:260px"><canvas id="chHak"></canvas></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white small fw-semibold d-flex">
                <span><i class="bi bi-hourglass-split me-1"></i>Silikon bekleyen (kesim yapıldı)</span>
                <a href="isler.php?durum=kesim" class="ms-auto text-decoration-none">tümü</a>
            </div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <tbody>
                <?php foreach ($bekleyen as $b): ?>
                    <tr>
                        <td><a href="is_detay.php?id=<?= (int)$b['id'] ?>" class="text-decoration-none">
                            <?= h($b['blok']) ?> / <?= h($b['daire']) ?></a>
                            <div class="text-muted">kesim: <?= $b['kesim_tarih'] ? h(date('d.m.Y', strtotime($b['kesim_tarih']))) : '—' ?></div></td>
                        <td class="text-end align-middle">
                            <?php if ($b['gun'] !== null): ?>
                            <span class="badge bg-<?= (int)$b['gun'] > 14 ? 'danger' : 'warning text-dark' ?>"><?= $f0($b['gun']) ?> gün</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$bekleyen): ?><tr><td class="text-center text-success py-3">Bekleyen iş yok 🎉</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white small fw-semibold"><i class="bi bi-check2-circle me-1"></i>Son tamamlananlar</div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <thead class="table-light"><tr><th>Daire</th><th class="text-end">Metraj</th><th class="text-end">Hakkediş</th></tr></thead>
                <tbody>
                <?php foreach ($sonTamam as $t): ?>
                    <tr>
                        <td><a href="is_detay.php?id=<?= (int)$t['id'] ?>" class="text-decoration-none"><?= h($t['blok']) ?> / <?= h($t['daire']) ?></a>
                            <div class="text-muted"><?= h(date('d.m.Y', strtotime($t['silikon_tarih']))) ?></div></td>
                        <td class="text-end align-middle"><?= $f2($t['metraj']) ?></td>
                        <td class="text-end align-middle fw-semibold"><?= $f0($t['hakkedis']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sonTamam): ?><tr><td colspan="3" class="text-center text-muted py-3">Kayıt yok.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white small fw-semibold"><i class="bi bi-bar-chart-steps me-1"></i>En yüksek metrajlı daireler</div>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <thead class="table-light"><tr><th>Daire</th><th class="text-end">İş</th><th class="text-end">Metraj</th><th class="text-end">Hakkediş</th></tr></thead>
                <tbody>
                <?php foreach ($enBuyuk as $e): ?>
                    <tr>
                        <td><a href="isler.php?blok=<?= urlencode($e['blok']) ?>&daire=<?= urlencode($e['daire']) ?>" class="text-decoration-none"><?= h($e['blok']) ?> / <?= h($e['daire']) ?></a></td>
                        <td class="text-end"><?= $f0($e['is_adedi']) ?></td>
                        <td class="text-end"><?= $f2($e['metraj']) ?></td>
                        <td class="text-end fw-semibold"><?= $f0($e['hakkedis']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$enBuyuk): ?><tr><td colspan="4" class="text-center text-muted py-3">Kayıt yok.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>

<script>
(function(){
    const SERI = <?= json_encode($seri, JSON_UNESCAPED_UNICODE) ?>;
    const BLOK = <?= json_encode($blok, JSON_UNESCAPED_UNICODE) ?>;
    const OZET = <?= json_encode(['tamam'=>$ozet['silikon'], 'kesim'=>$ozet['silikonBekleyen'], 'bekliyor'=>$ozet['kesimBekleyen']], JSON_UNESCAPED_UNICODE) ?>;
    const gunEt = g => { const [y,m,d] = g.split('-'); return d + '.' + m; };

    // Çubuk = o gün tamamlanan iş, çizgi = birikmiş toplam (sağ eksen) — tek başına
    // çizgi grafik günlük hareketi, tek başına çubuk toplam ilerlemeyi göstermez.
    new Chart(document.getElementById('chSeri'), {
        data: { labels: SERI.map(s => gunEt(s.gun)), datasets: [
            { type:'bar', label:'O gün tamamlanan', data: SERI.map(s=>s.tamamlanan), backgroundColor:'#198754', yAxisID:'y' },
            { type:'bar', label:'O gün kesilen', data: SERI.map(s=>s.kesilen), backgroundColor:'#ffc107', yAxisID:'y' },
            { type:'line', label:'Toplam tamamlanan', data: SERI.map(s=>s.toplamSilikon), borderColor:'#0d6efd',
              backgroundColor:'rgba(13,110,253,.10)', borderWidth:2, pointRadius:2, tension:.3, fill:true, yAxisID:'y1' }
        ]},
        options: { responsive:true, maintainAspectRatio:false, interaction:{mode:'index', intersect:false},
                   plugins:{legend:{position:'bottom'}},
                   scales:{ y:{ beginAtZero:true, ticks:{precision:0}, title:{display:true, text:'gün içi adet'} },
                            y1:{ position:'right', beginAtZero:true, ticks:{precision:0}, grid:{drawOnChartArea:false},
                                 title:{display:true, text:'toplam tamamlanan'} } } }
    });

    new Chart(document.getElementById('chDurum'), {
        type: 'doughnut',
        data: { labels: ['Tamamlandı', 'Silikon bekliyor', 'Kesim bekliyor'],
                datasets:[{ data: [OZET.tamam, OZET.kesim, OZET.bekliyor],
                            backgroundColor:['#198754','#ffc107','#6c757d'] }] },
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
    });

    new Chart(document.getElementById('chBlok'), {
        type: 'bar',
        data: { labels: BLOK.map(b=>b.ad), datasets:[
            { label:'Tamamlanan', data: BLOK.map(b=>+b.tamam), backgroundColor:'#198754' },
            { label:'Bekleyen', data: BLOK.map(b=>b.toplam-b.tamam), backgroundColor:'#ffc107' }
        ]},
        options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}},
                   scales:{ x:{stacked:true}, y:{stacked:true, beginAtZero:true, ticks:{precision:0}} } }
    });

    new Chart(document.getElementById('chHak'), {
        type: 'bar',
        data: { labels: BLOK.map(b=>b.ad), datasets:[{ label:'Hakkediş (TL)', data: BLOK.map(b=>+b.hakkedis), backgroundColor:'#0d6efd' }] },
        options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}},
                   scales:{ x:{ beginAtZero:true } } }
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
