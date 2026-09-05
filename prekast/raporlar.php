<?php
/**
 * raporlar.php — Prekast raporları & hakkediş özeti
 *
 * Kaynak günlük çizelgedir; sayfa her yüklemede kendiliğinden güncellenir.
 * Çıktılar ortak katmandan (assets/js/ern_rapor.js): Excel'e Aktar + PDF İndir + Yazdır,
 * hepsi ERN Taahhüt logolu.
 *
 * Tarih filtresi **tamamlanma (silikon) tarihine** uygulanır — "bu ay ne kadar hakkediş
 * doğdu" sorusunun cevabı budur. Günlük trend filtreden BAĞIMSIZDIR (kesilen tarafını da
 * gösterir, filtre uygulansa uyumsuz bir grafik çıkardı).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_prekast.php';
require_once __DIR__ . '/_ortak.php';

pk_semasi_kur($pdoPrekast);
$pageTitle = 'Prekast Raporlar';

$bas = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bas'] ?? '') ? $_GET['bas'] : '';
$bit = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bit'] ?? '') ? $_GET['bit'] : '';
$cizelgeler = pk_secenekler($pdoPrekast, 'cizelge');
$secili = trim((string)($_GET['cizelge'] ?? ''));
if ($secili !== '' && !in_array($secili, $cizelgeler, true)) $secili = '';   // whitelist

// Tarih filtresi yalnız TAMAMLANMA tarafını keser; toplam iş sayısı hep tam çizelgedir.
$w = ['dosyada = 1']; $p = [];
if ($secili !== '') { $w[] = 'cizelge = ?'; $p[] = $secili; }
$wsql = ' WHERE ' . implode(' AND ', $w);
// Tamamlanma koşulu (SUM içinde kullanılacak ifade + parametreleri)
$tk = 'silikon=1';
$tp = [];
if ($bas !== '') { $tk .= ' AND silikon_tarih >= ?'; $tp[] = $bas; }
if ($bit !== '') { $tk .= ' AND silikon_tarih <= ?'; $tp[] = $bit; }

/** Bir kırılım sorgusu: kolon bazında toplam / tamamlanan / metraj / hakkediş. */
$kirilim = function (string $kolon, string $ek = '', int $limit = 0) use ($pdoPrekast, $wsql, $p, $tk, $tp) {
    $lim = $limit > 0 ? " LIMIT $limit" : '';
    $sql = "SELECT $kolon ad, COUNT(*) toplam, SUM($tk) tamam, SUM(kesim=1) kesim,
                   COALESCE(SUM(CASE WHEN $tk THEN metraj ELSE 0 END),0) metraj,
                   COALESCE(SUM(CASE WHEN $tk THEN hakkedis ELSE 0 END),0) hakkedis
            FROM prekast_isler $wsql AND $kolon IS NOT NULL AND $kolon <> ''
            GROUP BY $kolon $ek$lim";
    // Parametre sırası: SELECT içindeki $tk (2 kez SUM/CASE ile 3 kez) → WHERE
    $st = $pdoPrekast->prepare($sql);
    $st->execute(array_merge($tp, $tp, $tp, $p));
    return $st->fetchAll();
};

$blok  = $kirilim('blok', 'ORDER BY blok');
$tipi  = $kirilim('is_tipi', 'ORDER BY hakkedis DESC');
$ciz   = $kirilim('cizelge', 'ORDER BY hakkedis DESC');
// Daire kırılımı blok + daire birlikte gruplanır — daire numaraları bloklar arasında tekrar eder (B/19, F/19)
$dst = $pdoPrekast->prepare("SELECT CONCAT(blok,' / ',daire) ad, COUNT(*) toplam, SUM($tk) tamam, SUM(kesim=1) kesim,
               COALESCE(SUM(CASE WHEN $tk THEN metraj ELSE 0 END),0) metraj,
               COALESCE(SUM(CASE WHEN $tk THEN hakkedis ELSE 0 END),0) hakkedis
        FROM prekast_isler $wsql AND blok <> '' AND daire <> ''
        GROUP BY blok, daire ORDER BY hakkedis DESC, metraj DESC LIMIT 25");
$dst->execute(array_merge($tp, $tp, $tp, $p));
$daire = $dst->fetchAll();

// KPI (tarih filtresine saygılı hakkediş + filtresiz iş sayıları)
$k = $pdoPrekast->prepare("SELECT COUNT(*) toplam, SUM(kesim=1) kesim, SUM(silikon=1) silikon,
                                  SUM($tk) donemTamam,
                                  COALESCE(SUM(CASE WHEN $tk THEN metraj ELSE 0 END),0) donemMetraj,
                                  COALESCE(SUM(CASE WHEN $tk THEN hakkedis ELSE 0 END),0) donemHakkedis,
                                  COALESCE(SUM(metraj),0) tumMetraj, COALESCE(SUM(hakkedis),0) tumHakkedis
                           FROM prekast_isler $wsql");
$k->execute(array_merge($tp, $tp, $tp, $p));
$kpi = $k->fetch() ?: [];
foreach (['toplam','kesim','silikon','donemTamam'] as $x) $kpi[$x] = (int)($kpi[$x] ?? 0);
foreach (['donemMetraj','donemHakkedis','tumMetraj','tumHakkedis'] as $x) $kpi[$x] = (float)($kpi[$x] ?? 0);
$kpi['oran'] = $kpi['toplam'] ? round($kpi['silikon'] * 100 / $kpi['toplam'], 1) : 0.0;

// Aylık tamamlanma (hakkediş takvimi) — tarih filtresinden bağımsız
$ay = $pdoPrekast->prepare("SELECT DATE_FORMAT(silikon_tarih,'%Y-%m') ay, COUNT(*) adet,
                                   COALESCE(SUM(metraj),0) metraj, COALESCE(SUM(hakkedis),0) hakkedis
                            FROM prekast_isler $wsql AND silikon_tarih IS NOT NULL
                            GROUP BY ay ORDER BY ay");
$ay->execute($p); $aylik = $ay->fetchAll();

$seri = pk_gunluk_seri($pdoPrekast, 90);

// Silikon bekleyenlerin yaş dağılımı (kesimden bu yana geçen gün)
$yas = $pdoPrekast->prepare("SELECT
        SUM(kesim_tarih IS NULL) bilinmiyor,
        SUM(DATEDIFF(NOW(), kesim_tarih) <= 7) a1,
        SUM(DATEDIFF(NOW(), kesim_tarih) BETWEEN 8 AND 30) a2,
        SUM(DATEDIFF(NOW(), kesim_tarih) > 30) a3
    FROM prekast_isler $wsql AND kesim=1 AND silikon=0");
$yas->execute($p); $yas = $yas->fetch() ?: ['bilinmiyor'=>0,'a1'=>0,'a2'=>0,'a3'=>0];

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f1 = fn($n) => number_format((float)$n, 1, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Prekast Raporlar &amp; Hakkediş</h4>
    <div class="ms-auto d-flex gap-2">
        <button class="btn btn-success btn-sm" onclick="pkExcel()"><i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</button>
        <button class="btn btn-danger btn-sm" onclick="pkPdf('pdf')"><i class="bi bi-file-earmark-pdf me-1"></i>PDF İndir</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="pkPdf('print')"><i class="bi bi-printer me-1"></i>Yazdır</button>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Tamamlanma başlangıç</label>
            <input type="date" name="bas" class="form-control form-control-sm" value="<?= h($bas) ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Tamamlanma bitiş</label>
            <input type="date" name="bit" class="form-control form-control-sm" value="<?= h($bit) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label small mb-1">Çizelge</label>
            <select name="cizelge" class="form-select form-select-sm">
                <option value="">Tümü</option>
                <?php foreach ($cizelgeler as $c): ?>
                    <option value="<?= h($c) ?>" <?= $secili === $c ? 'selected' : '' ?>><?= h(mb_strimwidth($c, 0, 60, '…')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Uygula</button>
            <a href="raporlar.php" class="btn btn-outline-secondary btn-sm">Sıfırla</a>
        </div>
        <div class="col-12"><div class="form-text">Tarih aralığı <strong>tamamlanma (silikon) tarihine</strong> uygulanır —
            dönem hakkedişi bu aralıkta doğan işlerin toplamıdır. İş sayıları hep tam çizelgeyi gösterir.</div></div>
    </form>
</div></div>

<div class="row g-2 mb-3">
<?php
$kartlar = [
    ['Toplam iş', $f0($kpi['toplam']), 'secondary'],
    ['Tamamlanan', $f0($kpi['silikon']) . ' (%' . $f1($kpi['oran']) . ')', 'success'],
    ['Dönem tamamlanan', $f0($kpi['donemTamam']), 'primary'],
    ['Dönem metrajı', $f2($kpi['donemMetraj']) . ' m', 'info'],
    ['Dönem hakkedişi', $f0($kpi['donemHakkedis']) . ' TL', 'dark'],
    ['Toplam hakkediş', $f0($kpi['tumHakkedis']) . ' TL', 'warning'],
];
foreach ($kartlar as [$ad, $deger, $renk]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3 text-center">
            <div class="fs-5 fw-bold text-<?= $renk ?>"><?= $deger ?></div>
            <div class="small text-muted"><?= $ad ?></div>
        </div></div>
    </div>
<?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-calendar3 me-1"></i>Aylık tamamlanan iş &amp; hakkediş</div>
            <div style="height:300px"><canvas id="chAy"></canvas></div>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-hourglass me-1"></i>Silikon bekleyenler (kesimden bu yana)</div>
            <div style="height:300px"><canvas id="chYas"></canvas></div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-building me-1"></i>Blok bazında tamamlanma</div>
            <div style="height:280px"><canvas id="chBlok"></canvas></div>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-graph-up-arrow me-1"></i>Günlük ilerleme (son 90 gün)</div>
            <div style="height:280px"><canvas id="chSeri"></canvas></div>
        </div></div>
    </div>
</div>

<?php
$tablo = function (string $baslik, string $ikon, array $satirlar, string $ilk) use ($f0, $f2) {
    if (!$satirlar) return;
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white small fw-semibold"><i class="bi <?= $ikon ?> me-1"></i><?= h($baslik) ?></div>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
            <thead class="table-light"><tr><th><?= h($ilk) ?></th><th class="text-end">İş</th><th class="text-end">Kesim</th>
                <th class="text-end">Tamamlanan</th><th class="text-end">%</th><th class="text-end">Metraj</th><th class="text-end">Hakkediş (TL)</th></tr></thead>
            <tbody>
            <?php $tt=0; $tkes=0; $ta=0; $tm=0.0; $th=0.0; foreach ($satirlar as $r):
                $tt+=(int)$r['toplam']; $tkes+=(int)$r['kesim']; $ta+=(int)$r['tamam'];
                $tm+=(float)$r['metraj']; $th+=(float)$r['hakkedis']; ?>
                <tr>
                    <td><?= h(mb_strimwidth((string)$r['ad'], 0, 60, '…')) ?></td>
                    <td class="text-end"><?= $f0($r['toplam']) ?></td>
                    <td class="text-end"><?= $f0($r['kesim']) ?></td>
                    <td class="text-end text-success fw-semibold"><?= $f0($r['tamam']) ?></td>
                    <td class="text-end"><?= $r['toplam'] ? number_format($r['tamam'] * 100 / $r['toplam'], 1, ',', '.') : '0,0' ?></td>
                    <td class="text-end"><?= $f2($r['metraj']) ?></td>
                    <td class="text-end fw-semibold"><?= $f0($r['hakkedis']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-semibold"><tr>
                <td>TOPLAM</td><td class="text-end"><?= $f0($tt) ?></td><td class="text-end"><?= $f0($tkes) ?></td>
                <td class="text-end"><?= $f0($ta) ?></td><td class="text-end"><?= $tt ? number_format($ta*100/$tt,1,',','.') : '0,0' ?></td>
                <td class="text-end"><?= $f2($tm) ?></td><td class="text-end"><?= $f0($th) ?></td>
            </tr></tfoot>
        </table>
        </div>
    </div>
    <?php
};
$tablo('Blok bazında', 'bi-building', $blok, 'Blok');
if (count($ciz) > 1) $tablo('Çizelge bazında', 'bi-journal-text', $ciz, 'Çizelge');
if (count($tipi) > 1) $tablo('İş tipi bazında', 'bi-tools', $tipi, 'İş Tipi');
$tablo('En yüksek hakkedişli daireler (ilk 25)', 'bi-house-gear', $daire, 'Daire');
?>

<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>window.ERN_ROOT = '../';</script>
<script src="../assets/js/ern_rapor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ern_rapor.js') ?>"></script>
<script>
const PK = {
    kpi:   <?= json_encode(['toplam'=>$kpi['toplam'],'kesim'=>$kpi['kesim'],'silikon'=>$kpi['silikon'],
                            'oran'=>$kpi['oran'],'donemTamam'=>$kpi['donemTamam'],'donemMetraj'=>$kpi['donemMetraj'],
                            'donemHakkedis'=>$kpi['donemHakkedis'],'tumMetraj'=>$kpi['tumMetraj'],'tumHakkedis'=>$kpi['tumHakkedis']]) ?>,
    aylik: <?= json_encode($aylik, JSON_UNESCAPED_UNICODE) ?>,
    seri:  <?= json_encode($seri, JSON_UNESCAPED_UNICODE) ?>,
    yas:   <?= json_encode(array_map('intval', $yas)) ?>,
    blok:  <?= json_encode($blok, JSON_UNESCAPED_UNICODE) ?>,
    daire: <?= json_encode($daire, JSON_UNESCAPED_UNICODE) ?>,
    tipi:  <?= json_encode($tipi, JSON_UNESCAPED_UNICODE) ?>,
    ciz:   <?= json_encode($ciz, JSON_UNESCAPED_UNICODE) ?>,
    donem: <?= json_encode(($bas || $bit) ? (($bas ?: '…') . ' – ' . ($bit ?: '…')) : 'Tüm zamanlar', JSON_UNESCAPED_UNICODE) ?>,
    cizelge: <?= json_encode($secili !== '' ? $secili : 'Tüm çizelgeler', JSON_UNESCAPED_UNICODE) ?>
};
const f0 = n => Number(n || 0).toLocaleString('tr-TR');
const f2 = n => Number(n || 0).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
const ayEt = a => { const [y,m] = a.split('-'); return ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'][+m-1] + ' ' + y.slice(2); };
const gunEt = g => { const [y,m,d] = g.split('-'); return d + '.' + m; };
const yuzde = r => r.toplam ? (r.tamam * 100 / r.toplam).toFixed(1) : '0.0';

new Chart(document.getElementById('chAy'), {
    data:{ labels: PK.aylik.map(a=>ayEt(a.ay)), datasets:[
        {type:'bar', label:'Tamamlanan iş', data:PK.aylik.map(a=>+a.adet), backgroundColor:'#198754', yAxisID:'y'},
        {type:'line', label:'Hakkediş (TL)', data:PK.aylik.map(a=>+a.hakkedis), borderColor:'#0d6efd',
         backgroundColor:'rgba(13,110,253,.10)', borderWidth:2, pointRadius:3, tension:.3, fill:true, yAxisID:'y1'}
    ]},
    options:{responsive:true, maintainAspectRatio:false, interaction:{mode:'index', intersect:false},
             plugins:{legend:{position:'bottom'}},
             scales:{ y:{beginAtZero:true, ticks:{precision:0}, title:{display:true, text:'iş adedi'}},
                      y1:{position:'right', beginAtZero:true, grid:{drawOnChartArea:false}, title:{display:true, text:'hakkediş (TL)'}} }}
});
new Chart(document.getElementById('chYas'), {
    type:'doughnut',
    data:{ labels:['0-7 gün','8-30 gün','30+ gün','kesim tarihi yok'],
           datasets:[{ data:[PK.yas.a1, PK.yas.a2, PK.yas.a3, PK.yas.bilinmiyor],
                       backgroundColor:['#198754','#ffc107','#dc3545','#adb5bd'] }]},
    options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}}
});
new Chart(document.getElementById('chBlok'), {
    type:'bar',
    data:{ labels: PK.blok.map(b=>b.ad), datasets:[
        {label:'Tamamlanan', data:PK.blok.map(b=>+b.tamam), backgroundColor:'#198754'},
        {label:'Bekleyen', data:PK.blok.map(b=>b.toplam-b.tamam), backgroundColor:'#ffc107'}
    ]},
    options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}},
             scales:{x:{stacked:true}, y:{stacked:true, beginAtZero:true, ticks:{precision:0}}}}
});
new Chart(document.getElementById('chSeri'), {
    data:{ labels: PK.seri.map(s=>gunEt(s.gun)), datasets:[
        {type:'bar', label:'O gün tamamlanan', data:PK.seri.map(s=>+s.tamamlanan), backgroundColor:'#198754', yAxisID:'y'},
        {type:'line', label:'Toplam tamamlanan', data:PK.seri.map(s=>+s.toplamSilikon), borderColor:'#0d6efd',
         borderWidth:2, pointRadius:0, tension:.3, yAxisID:'y1'}
    ]},
    options:{responsive:true, maintainAspectRatio:false, interaction:{mode:'index', intersect:false},
             plugins:{legend:{position:'bottom'}},
             scales:{ y:{beginAtZero:true, ticks:{precision:0}},
                      y1:{position:'right', beginAtZero:true, ticks:{precision:0}, grid:{drawOnChartArea:false}} }}
});

// ── PDF / Yazdır ────────────────────────────────────────────────────────────
function pkPdf(mode){
    const tbl = ERN_RAPOR.tbl;
    const kir = (b, l, ilk) => l.length ? '<h2>' + b + '</h2>' + tbl([ilk,'İş','Kesim','Tamamlanan','%','Metraj','Hakkediş (TL)'],
        l.map(r => [r.ad, f0(r.toplam), f0(r.kesim), f0(r.tamam), yuzde(r), f2(r.metraj), f0(r.hakkedis)])) : '';
    const html = '<div class="kpis">'
        + '<div><b>' + f0(PK.kpi.toplam) + '</b>Toplam İş</div>'
        + '<div><b>' + f0(PK.kpi.silikon) + '</b>Tamamlanan</div>'
        + '<div><b>%' + f0(PK.kpi.oran) + '</b>Tamamlanma</div>'
        + '<div><b>' + f2(PK.kpi.donemMetraj) + ' m</b>Dönem Metrajı</div>'
        + '<div><b>' + f0(PK.kpi.donemHakkedis) + ' TL</b>Dönem Hakkedişi</div></div>'
        + '<p><b>Çizelge:</b> ' + ERN_RAPOR.esc(PK.cizelge) + ' &nbsp; <b>Dönem:</b> ' + ERN_RAPOR.esc(PK.donem) + '</p>'
        + '<h2>Aylık Tamamlanma &amp; Hakkediş</h2>' + tbl(['Ay','Tamamlanan iş','Metraj','Hakkediş (TL)'],
            PK.aylik.map(a => [ayEt(a.ay), f0(a.adet), f2(a.metraj), f0(a.hakkedis)]))
        + kir('Blok Bazında', PK.blok, 'Blok')
        + (PK.ciz.length > 1 ? kir('Çizelge Bazında', PK.ciz, 'Çizelge') : '')
        + (PK.tipi.length > 1 ? kir('İş Tipi Bazında', PK.tipi, 'İş Tipi') : '')
        + kir('En Yüksek Hakkedişli Daireler', PK.daire, 'Daire');
    ERN_RAPOR.popup({title:'PREKAST İŞ TAKİP & HAKKEDİŞ RAPORU', body:html, mode:mode, filename:'ERN_Prekast_Hakkedis'});
}

// ── Excel'e Aktar (logolu, çok sayfalı) ────────────────────────────────────
async function pkExcel(){
    const wb = await ERN_RAPOR.wb();
    const kir = (adi, baslik, l, ilk) => {
        if (!l.length) return;
        const ws = wb.addWorksheet(adi);
        ws.columns = [{width:40},{width:10},{width:10},{width:14},{width:10},{width:14},{width:18}];
        ERN_RAPOR.title(wb, ws, baslik, 7);
        ERN_RAPOR.hdr(ws.addRow([ilk,'İş','Kesim','Tamamlanan','%','Metraj','Hakkediş (TL)']));
        l.forEach(r => ws.addRow([r.ad, +r.toplam, +r.kesim, +r.tamam, +yuzde(r), +r.metraj, +r.hakkedis]));
    };
    let ws = wb.addWorksheet('Özet');
    ws.columns = [{width:34},{width:22}];
    ERN_RAPOR.title(wb, ws, 'PREKAST İŞ TAKİP — ÖZET', 2);
    ERN_RAPOR.hdr(ws.addRow(['Gösterge','Değer']));
    [['Çizelge', PK.cizelge], ['Dönem', PK.donem], ['Toplam iş', PK.kpi.toplam],
     ['Kesim yapılan', PK.kpi.kesim], ['Tamamlanan (silikon)', PK.kpi.silikon],
     ['Tamamlanma oranı (%)', PK.kpi.oran], ['Dönem tamamlanan', PK.kpi.donemTamam],
     ['Dönem metrajı (m)', PK.kpi.donemMetraj], ['Dönem hakkedişi (TL)', PK.kpi.donemHakkedis],
     ['Toplam metraj (m)', PK.kpi.tumMetraj], ['Toplam hakkediş (TL)', PK.kpi.tumHakkedis]].forEach(r => ws.addRow(r));

    ws = wb.addWorksheet('Aylık');
    ws.columns = [{width:14},{width:16},{width:14},{width:18}];
    ERN_RAPOR.title(wb, ws, 'AYLIK TAMAMLANMA VE HAKKEDİŞ', 4);
    ERN_RAPOR.hdr(ws.addRow(['Ay','Tamamlanan iş','Metraj','Hakkediş (TL)']));
    PK.aylik.forEach(a => ws.addRow([ayEt(a.ay), +a.adet, +a.metraj, +a.hakkedis]));

    ws = wb.addWorksheet('Günlük');
    ws.columns = [{width:14},{width:16},{width:14},{width:18},{width:18}];
    ERN_RAPOR.title(wb, ws, 'GÜNLÜK İLERLEME', 5);
    ERN_RAPOR.hdr(ws.addRow(['Gün','O gün tamamlanan','O gün kesilen','Toplam tamamlanan','Toplam hakkediş (TL)']));
    PK.seri.forEach(s => ws.addRow([s.gun, +s.tamamlanan, +s.kesilen, +s.toplamSilikon, +s.toplamHakkedis]));

    kir('Blok', 'BLOK BAZINDA', PK.blok, 'Blok');
    kir('Çizelge', 'ÇİZELGE BAZINDA', PK.ciz, 'Çizelge');
    kir('İş Tipi', 'İŞ TİPİ BAZINDA', PK.tipi, 'İş Tipi');
    kir('Daireler', 'EN YÜKSEK HAKKEDİŞLİ DAİRELER', PK.daire, 'Daire');

    await ERN_RAPOR.save(wb, 'ERN_Prekast_Hakkedis_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
