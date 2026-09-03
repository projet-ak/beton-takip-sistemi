<?php
/**
 * raporlar.php — CRM (Üretim Arızaları) raporları
 *
 * Tüm kırılımlar günlük rapordan gelen canlı veriden hesaplanır; her yükleme sonrası
 * kendiliğinden güncellenir. Çıktılar ortak katmandan (assets/js/ern_rapor.js):
 * Excel'e Aktar (logolu, çok sayfalı) + PDF İndir + Yazdır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_crm.php';
require_once __DIR__ . '/_ortak.php';

crm_semasi_kur($pdoCrm);
$pageTitle = 'CRM Raporları';

// Tarih aralığı filtresi (boşsa tüm zamanlar)
$bas = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bas'] ?? '') ? $_GET['bas'] : '';
$bit = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bit'] ?? '') ? $_GET['bit'] : '';
$w = []; $p = [];
if ($bas) { $w[] = 'olusturma >= ?'; $p[] = $bas . ' 00:00:00'; }
if ($bit) { $w[] = 'olusturma <= ?'; $p[] = $bit . ' 23:59:59'; }
$wsql = $w ? ' WHERE ' . implode(' AND ', $w) : '';

/** Filtreye saygılı gruplama sorgusu. */
$grup = function (string $kolon, string $sira = 'toplam DESC', int $limit = 0) use ($pdoCrm, $wsql, $p) {
    $ek = $wsql ? $wsql . " AND $kolon IS NOT NULL AND $kolon <> ''" : " WHERE $kolon IS NOT NULL AND $kolon <> ''";
    $sql = "SELECT $kolon ad, COUNT(*) toplam, SUM(durum='acik') acik, SUM(durum='cozuldu') cozuldu,
                   AVG(CASE WHEN durum='cozuldu' AND cozumlenme IS NOT NULL THEN DATEDIFF(cozumlenme, olusturma) END) ortGun
            FROM crm_arizalar $ek GROUP BY $kolon ORDER BY $sira" . ($limit ? " LIMIT $limit" : '');
    $st = $pdoCrm->prepare($sql); $st->execute($p);
    return $st->fetchAll();
};

$ozet = crm_ozet($pdoCrm);
$tur   = $grup('sikayet_turu');
$konu  = $grup('sikayet_konusu');
$detay = $grup('sikayet_aciklamasi', 'toplam DESC', 20);
$tip   = $grup('ariza_tipi', 'toplam DESC', 20);
$blok  = $grup('blok', 'ad ASC');
$kat   = $grup('kat', 'MIN(kat_sira) ASC');
$dtip  = $grup('daire_tipi', 'ad ASC');
$sorum = $grup('sorumlu');

// Aylık gelen / çözülen / birikmiş açık — trend HER ZAMAN tüm zamanları kapsar:
// tarih filtresi yalnız "gelen" tarafını kesip çözülenle uyumsuz bir grafik üretiyordu.
$aylik = crm_aylik_seri($pdoCrm);

// Açık arızaların yaş dağılımı
$yasSql = "SELECT
    SUM(DATEDIFF(NOW(),olusturma) <= 7) a1,
    SUM(DATEDIFF(NOW(),olusturma) BETWEEN 8 AND 30) a2,
    SUM(DATEDIFF(NOW(),olusturma) BETWEEN 31 AND 90) a3,
    SUM(DATEDIFF(NOW(),olusturma) > 90) a4
  FROM crm_arizalar WHERE durum='acik' AND olusturma IS NOT NULL";
$yas = $pdoCrm->query($yasSql)->fetch() ?: ['a1'=>0,'a2'=>0,'a3'=>0,'a4'=>0];

// En çok arıza kaydı olan daireler
$st = $pdoCrm->prepare("SELECT konut, blok, kat, daire_tipi, COUNT(*) toplam, SUM(durum='acik') acik
                        FROM crm_arizalar" . ($wsql ?: '') . ($wsql ? ' AND' : ' WHERE') . " konut IS NOT NULL AND konut <> ''
                        GROUP BY konut, blok, kat, daire_tipi ORDER BY toplam DESC, acik DESC LIMIT 25");
$st->execute($p);
$daire = $st->fetchAll();

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f1 = fn($n) => $n === null ? '—' : number_format((float)$n, 1, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>CRM Raporları</h4>
    <div class="ms-auto d-flex gap-2">
        <button class="btn btn-outline-success btn-sm" onclick="crmExcel()"><i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</button>
        <button class="btn btn-outline-danger btn-sm" onclick="crmPdf('pdf')"><i class="bi bi-file-earmark-pdf me-1"></i>PDF İndir</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="crmPdf('print')"><i class="bi bi-printer me-1"></i>Yazdır</button>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end small">
        <div class="col-6 col-md-3">
            <label class="form-label mb-1">Açılış tarihi (baş.)</label>
            <input type="date" name="bas" class="form-control form-control-sm" value="<?= h($bas) ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label mb-1">Açılış tarihi (bitiş)</label>
            <input type="date" name="bit" class="form-control form-control-sm" value="<?= h($bit) ?>">
        </div>
        <div class="col-md-3 d-flex gap-1">
            <button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-funnel"></i> Uygula</button>
            <a href="raporlar.php" class="btn btn-outline-secondary btn-sm">Sıfırla</a>
        </div>
        <div class="col-md-3 text-md-end text-muted">
            <?= $bas || $bit ? 'Filtre etkin' : 'Tüm zamanlar' ?> · <?= $f0($ozet['toplam']) ?> kayıt
        </div>
    </form>
</div></div>

<div class="row g-2 mb-3">
<?php
$kpi = [
    ['Toplam kayıt', $f0($ozet['toplam']), 'secondary'],
    ['Açık', $f0($ozet['acik']), 'danger'],
    ['Çözülen', $f0($ozet['cozuldu']), 'success'],
    ['Ort. açık kalma', $f1($ozet['ortAcikGun']) . ' gün', 'info'],
    ['Ort. çözüm süresi', $f1($ozet['ortCozumGun']) . ' gün', 'primary'],
    ['90+ gündür açık', $f0($ozet['eski90']), 'warning'],
];
foreach ($kpi as [$ad, $deger, $renk]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 text-center">
            <div class="fs-5 fw-bold text-<?= $renk ?>"><?= $deger ?></div>
            <div class="small text-muted"><?= $ad ?></div>
        </div></div>
    </div>
<?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="d-flex align-items-center mb-2">
            <span class="fw-semibold">Aylık gelen / çözülen</span>
            <span class="ms-auto small text-muted">tüm zamanlar · çizgi: birikmiş açık</span>
        </div>
        <div style="height:300px"><canvas id="chAylik"></canvas></div>
    </div></div></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="fw-semibold mb-2">Açık arızaların yaşı</div>
        <div style="height:300px"><canvas id="chYas"></canvas></div>
    </div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="fw-semibold mb-2">Şikayet konusu (ilk 12)</div>
        <div style="height:320px"><canvas id="chKonu"></canvas></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="fw-semibold mb-2">Blok × durum</div>
        <div style="height:320px"><canvas id="chBlok"></canvas></div>
    </div></div></div>
</div>

<?php
/** Tekrar eden tablo bloğu. */
$tablo = function (string $baslik, array $satirlar, string $ilkKolon, bool $ortGun = true) use ($f0, $f1) {
    ?>
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white small fw-semibold"><?= h($baslik) ?></div>
        <div class="table-responsive" style="max-height:420px">
        <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
            <thead class="table-light sticky-top"><tr>
                <th><?= h($ilkKolon) ?></th><th class="text-end">Toplam</th>
                <th class="text-end">Açık</th><th class="text-end">Çözülen</th>
                <?php if ($ortGun): ?><th class="text-end">Ort. çözüm (gün)</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($satirlar as $s): ?>
                <tr>
                    <td class="text-truncate" style="max-width:230px" title="<?= h($s['ad']) ?>"><?= h($s['ad']) ?></td>
                    <td class="text-end fw-semibold"><?= $f0($s['toplam']) ?></td>
                    <td class="text-end text-danger"><?= $f0($s['acik']) ?></td>
                    <td class="text-end text-success"><?= $f0($s['cozuldu']) ?></td>
                    <?php if ($ortGun): ?><td class="text-end text-muted"><?= $f1($s['ortGun']) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$satirlar): ?><tr><td colspan="5" class="text-center text-muted py-3">Kayıt yok.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
      </div>
    </div>
    <?php
};
?>

<div class="row g-3 mb-3">
    <?php $tablo('Şikayet türü', $tur, 'Tür'); ?>
    <?php $tablo('Şikayet konusu', $konu, 'Konu'); ?>
    <?php $tablo('Şikayet detayı (ilk 20)', $detay, 'Detay'); ?>
    <?php $tablo('Arıza tipi (ilk 20)', $tip, 'Arıza Tipi'); ?>
    <?php $tablo('Blok', $blok, 'Blok'); ?>
    <?php $tablo('Kat', $kat, 'Kat'); ?>
    <?php $tablo('Daire tipi', $dtip, 'Daire Tipi'); ?>
    <?php $tablo('Sorumlu', $sorum, 'Sorumlu'); ?>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white small fw-semibold"><i class="bi bi-house-exclamation me-1"></i>En çok arıza kaydı olan daireler (ilk 25)</div>
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
        <thead class="table-light"><tr><th>Konut</th><th>Blok</th><th>Kat</th><th>Tip</th>
            <th class="text-end">Toplam</th><th class="text-end">Açık</th></tr></thead>
        <tbody>
        <?php foreach ($daire as $d): ?>
            <tr>
                <td><a href="arizalar.php?konut=<?= urlencode($d['konut']) ?>" class="text-decoration-none"><?= h($d['konut']) ?></a></td>
                <td><?= h($d['blok']) ?></td><td><?= h($d['kat']) ?></td><td><?= h($d['daire_tipi']) ?></td>
                <td class="text-end fw-semibold"><?= $f0($d['toplam']) ?></td>
                <td class="text-end text-danger"><?= $f0($d['acik']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>window.ERN_ROOT = '../';</script>
<script src="../assets/js/ern_rapor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ern_rapor.js') ?>"></script>
<script>
const CRM = {
    kpi:   <?= json_encode(['toplam'=>$ozet['toplam'],'acik'=>$ozet['acik'],'cozuldu'=>$ozet['cozuldu'],
                            'ortAcik'=>round($ozet['ortAcikGun'],1),'ortCozum'=>round($ozet['ortCozumGun'],1),
                            'eski90'=>$ozet['eski90']]) ?>,
    aylik: <?= json_encode($aylik, JSON_UNESCAPED_UNICODE) ?>,
    yas:   <?= json_encode(array_map('intval', $yas)) ?>,
    tur:   <?= json_encode($tur, JSON_UNESCAPED_UNICODE) ?>,
    konu:  <?= json_encode($konu, JSON_UNESCAPED_UNICODE) ?>,
    detay: <?= json_encode($detay, JSON_UNESCAPED_UNICODE) ?>,
    tip:   <?= json_encode($tip, JSON_UNESCAPED_UNICODE) ?>,
    blok:  <?= json_encode($blok, JSON_UNESCAPED_UNICODE) ?>,
    kat:   <?= json_encode($kat, JSON_UNESCAPED_UNICODE) ?>,
    dtip:  <?= json_encode($dtip, JSON_UNESCAPED_UNICODE) ?>,
    sorum: <?= json_encode($sorum, JSON_UNESCAPED_UNICODE) ?>,
    daire: <?= json_encode($daire, JSON_UNESCAPED_UNICODE) ?>,
    donem: <?= json_encode(($bas || $bit) ? (($bas ?: '…') . ' – ' . ($bit ?: '…')) : 'Tüm zamanlar', JSON_UNESCAPED_UNICODE) ?>
};
const f0 = n => Number(n || 0).toLocaleString('tr-TR');
const f1 = n => (n === null || n === undefined || n === '') ? '—' : Number(n).toLocaleString('tr-TR', {maximumFractionDigits:1});
const ayEt = a => { const [y,m] = a.split('-'); return ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'][+m-1] + ' ' + y.slice(2); };

new Chart(document.getElementById('chAylik'), {
    data:{ labels: CRM.aylik.map(a=>ayEt(a.ay)), datasets:[
        {type:'bar', label:'Gelen', data:CRM.aylik.map(a=>a.gelen), backgroundColor:'#dc3545', yAxisID:'y'},
        {type:'bar', label:'Çözülen', data:CRM.aylik.map(a=>a.cozulen), backgroundColor:'#198754', yAxisID:'y'},
        {type:'line', label:'Birikmiş açık', data:CRM.aylik.map(a=>a.acik), borderColor:'#0d6efd',
         backgroundColor:'rgba(13,110,253,.10)', borderWidth:2, pointRadius:2, tension:.3, fill:true, yAxisID:'y1'}
    ]},
    options:{responsive:true, maintainAspectRatio:false, interaction:{mode:'index', intersect:false},
             plugins:{legend:{position:'bottom'}},
             scales:{ y:{beginAtZero:true, ticks:{precision:0}, title:{display:true, text:'ay içi adet'}},
                      y1:{position:'right', beginAtZero:true, ticks:{precision:0}, grid:{drawOnChartArea:false},
                          title:{display:true, text:'birikmiş açık'}} }}
});
new Chart(document.getElementById('chYas'), {
    type:'doughnut',
    data:{ labels:['0-7 gün','8-30 gün','31-90 gün','90+ gün'],
           datasets:[{ data:[CRM.yas.a1, CRM.yas.a2, CRM.yas.a3, CRM.yas.a4],
                       backgroundColor:['#198754','#0d6efd','#ffc107','#dc3545'] }]},
    options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}}
});
new Chart(document.getElementById('chKonu'), {
    type:'bar',
    data:{ labels: CRM.konu.slice(0,12).map(k=>k.ad),
           datasets:[{label:'Toplam', data:CRM.konu.slice(0,12).map(k=>+k.toplam), backgroundColor:'#0d6efd'}]},
    options:{indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true, ticks:{precision:0}}}}
});
new Chart(document.getElementById('chBlok'), {
    type:'bar',
    data:{ labels: CRM.blok.map(b=>b.ad), datasets:[
        {label:'Açık', data:CRM.blok.map(b=>+b.acik), backgroundColor:'#dc3545'},
        {label:'Çözülen', data:CRM.blok.map(b=>+b.cozuldu), backgroundColor:'#198754'}
    ]},
    options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}},
             scales:{x:{stacked:true}, y:{stacked:true, beginAtZero:true, ticks:{precision:0}}}}
});

// ── PDF / Yazdır ────────────────────────────────────────────────────────────
function crmPdf(mode){
    const tbl = ERN_RAPOR.tbl;
    const kir = (b, l, ilk) => '<h2>' + b + '</h2>' + tbl([ilk,'Toplam','Açık','Çözülen','Ort. çözüm (gün)'],
        l.map(r => [r.ad, f0(r.toplam), f0(r.acik), f0(r.cozuldu), f1(r.ortGun)]));
    const html = '<div class="kpis">'
        + '<div><b>' + f0(CRM.kpi.toplam) + '</b>Toplam Kayıt</div>'
        + '<div><b>' + f0(CRM.kpi.acik) + '</b>Açık</div>'
        + '<div><b>' + f0(CRM.kpi.cozuldu) + '</b>Çözülen</div>'
        + '<div><b>' + f1(CRM.kpi.ortAcik) + ' gün</b>Ort. Açık Kalma</div>'
        + '<div><b>' + f0(CRM.kpi.eski90) + '</b>90+ Gündür Açık</div></div>'
        + '<p><b>Dönem:</b> ' + ERN_RAPOR.esc(CRM.donem) + '</p>'
        + '<h2>Aylık Gelen / Çözülen</h2>' + tbl(['Ay','Gelen','Çözülen','Birikmiş açık'],
            CRM.aylik.map(a => [ayEt(a.ay), f0(a.gelen), f0(a.cozulen), f0(a.acik)]))
        + '<h2>Açık Arızaların Yaşı</h2>' + tbl(['Aralık','Adet'],
            [['0-7 gün', f0(CRM.yas.a1)], ['8-30 gün', f0(CRM.yas.a2)], ['31-90 gün', f0(CRM.yas.a3)], ['90+ gün', f0(CRM.yas.a4)]])
        + kir('Şikayet Türü', CRM.tur, 'Tür')
        + kir('Şikayet Konusu', CRM.konu, 'Konu')
        + kir('Arıza Tipi (ilk 20)', CRM.tip, 'Arıza Tipi')
        + kir('Blok', CRM.blok, 'Blok')
        + kir('Kat', CRM.kat, 'Kat')
        + '<h2>En Çok Arıza Kaydı Olan Daireler</h2>' + tbl(['Konut','Blok','Kat','Tip','Toplam','Açık'],
            CRM.daire.map(d => [d.konut, d.blok, d.kat, d.daire_tipi, f0(d.toplam), f0(d.acik)]));
    ERN_RAPOR.popup({title:'ÜRETİM ARIZALARI RAPORU', body:html, mode:mode, filename:'ERN_CRM_Uretim_Arizalari'});
}

// ── Excel'e Aktar (logolu, çok sayfalı) ────────────────────────────────────
async function crmExcel(){
    const wb = await ERN_RAPOR.wb();
    const kir = (adi, baslik, l, ilk) => {
        const ws = wb.addWorksheet(adi);
        ws.columns = [{width:34},{width:12},{width:12},{width:12},{width:18}];
        ERN_RAPOR.title(wb, ws, baslik, 5);
        ERN_RAPOR.hdr(ws.addRow([ilk,'Toplam','Açık','Çözülen','Ort. çözüm (gün)']));
        l.forEach(r => ws.addRow([r.ad, +r.toplam, +r.acik, +r.cozuldu, r.ortGun === null ? '' : Math.round(r.ortGun*10)/10]));
        return ws;
    };
    let ws = wb.addWorksheet('Özet');
    ws.columns = [{width:30},{width:20}];
    ERN_RAPOR.title(wb, ws, 'ÜRETİM ARIZALARI — ÖZET', 2);
    ERN_RAPOR.hdr(ws.addRow(['Gösterge','Değer']));
    [['Dönem', CRM.donem], ['Toplam kayıt', CRM.kpi.toplam], ['Açık', CRM.kpi.acik], ['Çözülen', CRM.kpi.cozuldu],
     ['Ortalama açık kalma (gün)', CRM.kpi.ortAcik], ['Ortalama çözüm süresi (gün)', CRM.kpi.ortCozum],
     ['90+ gündür açık', CRM.kpi.eski90]].forEach(r => ws.addRow(r));

    ws = wb.addWorksheet('Aylık');
    ws.columns = [{width:14},{width:12},{width:12},{width:16}];
    ERN_RAPOR.title(wb, ws, 'AYLIK GELEN / ÇÖZÜLEN', 4);
    ERN_RAPOR.hdr(ws.addRow(['Ay','Gelen','Çözülen','Birikmiş açık']));
    CRM.aylik.forEach(a => ws.addRow([ayEt(a.ay), a.gelen, a.cozulen, a.acik]));

    kir('Şikayet Türü', 'ŞİKAYET TÜRÜ', CRM.tur, 'Tür');
    kir('Konu', 'ŞİKAYET KONUSU', CRM.konu, 'Konu');
    kir('Detay', 'ŞİKAYET DETAYI', CRM.detay, 'Detay');
    kir('Arıza Tipi', 'ARIZA TİPİ', CRM.tip, 'Arıza Tipi');
    kir('Blok', 'BLOK', CRM.blok, 'Blok');
    kir('Kat', 'KAT', CRM.kat, 'Kat');
    kir('Daire Tipi', 'DAİRE TİPİ', CRM.dtip, 'Daire Tipi');
    kir('Sorumlu', 'SORUMLU', CRM.sorum, 'Sorumlu');

    ws = wb.addWorksheet('Daireler');
    ws.columns = [{width:22},{width:10},{width:16},{width:10},{width:12},{width:10}];
    ERN_RAPOR.title(wb, ws, 'EN ÇOK ARIZA KAYDI OLAN DAİRELER', 6);
    ERN_RAPOR.hdr(ws.addRow(['Konut','Blok','Kat','Tip','Toplam','Açık']));
    CRM.daire.forEach(d => ws.addRow([d.konut, d.blok, d.kat, d.daire_tipi, +d.toplam, +d.acik]));

    await ERN_RAPOR.save(wb, 'ERN_CRM_Uretim_Arizalari_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
