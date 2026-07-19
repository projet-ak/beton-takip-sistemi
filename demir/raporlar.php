<?php
/**
 * demir/raporlar.php — Demir raporları (grafikler + Excel/PDF dışa aktarma)
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/_firma_teslim.php';

$pageTitle = 'Demir Raporları — Demir Takip';

// ── Filtreler ─────────────────────────────────────────────────────────────────
$fProje = isset($_GET['proje']) && ctype_digit($_GET['proje']) ? (int)$_GET['proje'] : 0;
$yillar = $pdoDemir->query("SELECT DISTINCT YEAR(irsaliye_tarih) y FROM demir_sevkiyatlar WHERE irsaliye_tarih IS NOT NULL ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
$fYil   = isset($_GET['yil']) && ctype_digit($_GET['yil']) ? (int)$_GET['yil'] : (int)($yillar[0] ?? date('Y'));

$w = ['YEAR(s.irsaliye_tarih) = ?']; $p = [$fYil];
if ($fProje) { $w[] = 's.proje_id = ?'; $p[] = $fProje; }
$wSQL = 'WHERE ' . implode(' AND ', $w);

// ── Aylık (ton + adet) ────────────────────────────────────────────────────────
$aylikRaw = $pdoDemir->prepare("
    SELECT MONTH(s.irsaliye_tarih) ay, COUNT(DISTINCT s.id) adet, COALESCE(SUM(sk.irsaliye_miktar),0) ton
    FROM demir_sevkiyatlar s JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id=s.id
    $wSQL GROUP BY MONTH(s.irsaliye_tarih)");
$aylikRaw->execute($p);
$aylik = array_fill(1, 12, ['adet'=>0,'ton'=>0.0]);
foreach ($aylikRaw->fetchAll() as $r) { $aylik[(int)$r['ay']] = ['adet'=>(int)$r['adet'],'ton'=>(float)$r['ton']]; }

// ── Çap özeti ─────────────────────────────────────────────────────────────────
$capOzet = $pdoDemir->prepare("
    SELECT c.ad, c.tip, COALESCE(SUM(sk.irsaliye_miktar),0) irs, COALESCE(SUM(sk.kantar_miktar),0) knt
    FROM demir_caplar c
    JOIN demir_sevkiyat_kalemleri sk ON sk.cap_id=c.id
    JOIN demir_sevkiyatlar s ON s.id=sk.sevkiyat_id
    $wSQL GROUP BY c.id HAVING irs>0 OR knt>0 ORDER BY c.sira, c.ad");
$capOzet->execute($p);
$capRows = $capOzet->fetchAll();

// ── Tedarikçi özeti ───────────────────────────────────────────────────────────
$tedOzet = $pdoDemir->prepare("
    SELECT COALESCE(t.ad,'(tanımsız)') firma, COUNT(DISTINCT s.id) adet,
           COALESCE(SUM(sk.irsaliye_miktar),0) irs, COALESCE(SUM(sk.kantar_miktar),0) knt
    FROM demir_sevkiyatlar s JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id=s.id
    LEFT JOIN demir_tedarikciler t ON t.id=s.tedarikci_id
    $wSQL GROUP BY s.tedarikci_id ORDER BY irs DESC");
$tedOzet->execute($p);
$tedRows = $tedOzet->fetchAll();

// ── Proje özeti ───────────────────────────────────────────────────────────────
$prjOzet = $pdoDemir->prepare("
    SELECT COALESCE(pr.kod,'(proje yok)') kod, COUNT(DISTINCT s.id) adet, COALESCE(SUM(sk.irsaliye_miktar),0) ton
    FROM demir_sevkiyatlar s JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id=s.id
    LEFT JOIN demir_projeler pr ON pr.id=s.proje_id
    $wSQL GROUP BY s.proje_id ORDER BY ton DESC");
$prjOzet->execute($p);
$prjRows = $prjOzet->fetchAll();

// ── Proje × Çap kırılımı (matris) ─────────────────────────────────────────────
$prjCap = $pdoDemir->prepare("
    SELECT COALESCE(pr.kod,'(proje yok)') proje, c.ad cap, c.sira sira,
           COALESCE(SUM(sk.irsaliye_miktar),0) ton
    FROM demir_sevkiyatlar s JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id=s.id
    JOIN demir_caplar c ON c.id=sk.cap_id
    LEFT JOIN demir_projeler pr ON pr.id=s.proje_id
    $wSQL GROUP BY s.proje_id, c.id HAVING ton>0 ORDER BY pr.kod, c.sira, c.ad");
$prjCap->execute($p);
$prjCapMatris = []; $prjCapCaps = []; $prjCapTop = [];
foreach ($prjCap->fetchAll() as $r) {
    $pk = $r['proje']; $cp = $r['cap']; $tn = (float)$r['ton'];
    $prjCapMatris[$pk][$cp] = ($prjCapMatris[$pk][$cp] ?? 0) + $tn;
    $prjCapTop[$pk] = ($prjCapTop[$pk] ?? 0) + $tn;
    if (!isset($prjCapCaps[$cp])) $prjCapCaps[$cp] = (int)$r['sira'];
}
asort($prjCapCaps); // çap kolonlarını sıra no'ya göre sırala
$prjCapCaps = array_keys($prjCapCaps);

// ── Detay ─────────────────────────────────────────────────────────────────────
$detay = $pdoDemir->prepare("
    SELECT s.irsaliye_no, s.irsaliye_tarih, t.ad tedarikci, pr.kod proje, ta.ad taseron, s.arac_plaka, s.kantar_fis_no,
           COALESCE(SUM(sk.irsaliye_miktar),0) irs, COALESCE(SUM(sk.kantar_miktar),0) knt
    FROM demir_sevkiyatlar s JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id=s.id
    LEFT JOIN demir_tedarikciler t ON t.id=s.tedarikci_id
    LEFT JOIN demir_projeler pr ON pr.id=s.proje_id
    LEFT JOIN demir_taseronlar ta ON ta.id=s.taseron_id
    $wSQL GROUP BY s.id ORDER BY s.irsaliye_tarih DESC, s.id DESC");
$detay->execute($p);
$detayRows = $detay->fetchAll();

$totTon  = array_sum(array_column($capRows,'irs'));
$totKnt  = array_sum(array_column($capRows,'knt'));
$totFark = $totKnt - $totTon;
$totSevk = count($detayRows);

// ── Firma bazlı teslim matrisi (tüm dönem; tutanak verisi) ────────────────────
$ftm = firma_teslim_matrisi($pdoDemir);
$firmaFlat = []; // Excel/PDF için düz liste
foreach ($ftm['matris'] as $f=>$prjler) {
    foreach ($prjler as $prj=>$caps) {
        foreach (ftm_cap_sirala(array_keys($caps)) as $c) {
            if (abs($caps[$c]) < 0.0005) continue;
            $firmaFlat[] = ['firma'=>$f, 'proje'=>$prj, 'cap'=>$c, 'ton'=>round($caps[$c],3)];
        }
    }
}

$projeler = $pdoDemir->query("SELECT id,kod FROM demir_projeler WHERE aktif=1 ORDER BY kod")->fetchAll();
$ayAdlari = [1=>'Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
$donem = $fYil . ($fProje ? (' — ' . (function($projeler,$fProje){foreach($projeler as $p)if($p['id']==$fProje)return $p['kod'];return '';})($projeler,$fProje)) : '');

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-bar-chart-line text-dark me-2"></i>Demir Raporları</h4>
    <div class="d-flex gap-2">
        <button onclick="exportExcel()" class="btn btn-sm btn-success" id="btnXls"><i class="bi bi-file-earmark-excel me-1"></i> Excel</button>
        <button onclick="exportPDF()" class="btn btn-sm btn-outline-dark"><i class="bi bi-printer me-1"></i> PDF</button>
    </div>
</div>

<div class="card mb-3"><div class="card-body">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-3"><label class="form-label small">Yıl</label><select name="yil" class="form-select form-select-sm"><?php foreach(($yillar?:[date('Y')]) as $y): ?><option value="<?= $y ?>" <?= $fYil==$y?'selected':'' ?>><?= $y ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small">Proje</label><select name="proje" class="form-select form-select-sm"><option value="">Tümü</option><?php foreach($projeler as $pr): ?><option value="<?= $pr['id'] ?>" <?= $fProje==$pr['id']?'selected':'' ?>><?= h($pr['kod']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex gap-1"><button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-funnel"></i> Uygula</button><a href="raporlar.php" class="btn btn-outline-secondary btn-sm">Temizle</a></div>
    </form>
</div></div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Sevkiyat</div><div class="fs-4 fw-bold"><?= $totSevk ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam Gelen</div><div class="fs-4 fw-bold"><?= $fmt($totTon) ?> t</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam Kantar</div><div class="fs-4 fw-bold"><?= $fmt($totKnt) ?> t</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Kantar Farkı</div><div class="fs-4 fw-bold <?= abs($totFark)<0.0005?'':($totFark<0?'text-danger':'text-success') ?>"><?= ($totFark>0?'+':'').$fmt($totFark) ?> t</div></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8"><div class="card"><div class="card-header bg-white fw-semibold">Aylık Gelen Demir (ton) — <?= h((string)$fYil) ?></div><div class="card-body"><canvas id="chAylik" height="110"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card"><div class="card-header bg-white fw-semibold">Tedarikçi Dağılımı</div><div class="card-body"><canvas id="chTed" style="max-height:240px"></canvas></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><div class="card h-100"><div class="card-header bg-white fw-semibold">Çap Bazında (ton)</div><div class="card-body"><canvas id="chCap" height="130"></canvas></div></div></div>
    <div class="col-lg-5"><div class="card h-100">
        <div class="card-header bg-white fw-semibold">Çap Özeti</div>
        <div class="card-body p-0"><div class="table-responsive" style="max-height:340px">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Çap</th><th class="text-end">İrsaliye</th><th class="text-end">Kantar</th><th class="text-end">Fark</th></tr></thead>
                <tbody>
                <?php foreach($capRows as $r): $f=(float)$r['knt']-(float)$r['irs']; ?>
                    <tr><td class="fw-semibold"><?= h($r['ad']) ?></td><td class="text-end"><?= $fmt($r['irs']) ?></td><td class="text-end"><?= $fmt($r['knt']) ?></td><td class="text-end <?= abs($f)<0.0005?'text-muted':($f<0?'text-danger':'text-success') ?>"><?= abs($f)<0.0005?'0':(($f>0?'+':'').$fmt($f)) ?></td></tr>
                <?php endforeach; ?>
                <?php if(!$capRows): ?><tr><td colspan="4" class="text-center text-muted py-4">Veri yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div></div>
</div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-header bg-white fw-semibold">Tedarikçi Özeti</div><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Tedarikçi</th><th class="text-center">Sevkiyat</th><th class="text-end">İrsaliye (t)</th><th class="text-end">Fark</th></tr></thead><tbody>
        <?php foreach($tedRows as $r): $f=(float)$r['knt']-(float)$r['irs']; ?>
            <tr><td class="fw-semibold"><?= h($r['firma']) ?></td><td class="text-center"><?= (int)$r['adet'] ?></td><td class="text-end"><?= $fmt($r['irs']) ?></td><td class="text-end <?= abs($f)<0.0005?'text-muted':($f<0?'text-danger':'text-success') ?>"><?= abs($f)<0.0005?'0':(($f>0?'+':'').$fmt($f)) ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$tedRows): ?><tr><td colspan="4" class="text-center text-muted py-4">Veri yok.</td></tr><?php endif; ?>
        </tbody></table>
    </div></div></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-header bg-white fw-semibold">Proje Özeti</div><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Proje</th><th class="text-center">Sevkiyat</th><th class="text-end">Gelen (t)</th></tr></thead><tbody>
        <?php foreach($prjRows as $r): ?>
            <tr><td class="fw-semibold"><?= h($r['kod']) ?></td><td class="text-center"><?= (int)$r['adet'] ?></td><td class="text-end"><?= $fmt($r['ton']) ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$prjRows): ?><tr><td colspan="3" class="text-center text-muted py-4">Veri yok.</td></tr><?php endif; ?>
        </tbody></table>
    </div></div></div></div>
</div>

<!-- Proje kırılımı: doughnut + Proje × Çap matris -->
<div class="row g-3 mt-1">
    <div class="col-lg-4"><div class="card h-100">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3 text-primary me-1"></i>Proje Bazlı Gelen (ton)</div>
        <div class="card-body d-flex align-items-center justify-content-center"><canvas id="chProje" style="max-height:240px"></canvas></div>
    </div></div>
    <div class="col-lg-8"><div class="card h-100">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-grid-3x3 text-primary me-1"></i>Proje × Çap Kırılımı <span class="text-muted small fw-normal">(gelen ton)</span></div>
        <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
                <thead class="table-light"><tr><th>Proje</th><?php foreach($prjCapCaps as $cp): ?><th class="text-end"><?= h($cp) ?></th><?php endforeach; ?><th class="text-end">Toplam</th></tr></thead>
                <tbody>
                <?php foreach($prjCapMatris as $pk=>$caps): ?>
                    <tr>
                        <td class="fw-semibold"><span class="badge bg-dark"><?= h($pk) ?></span></td>
                        <?php foreach($prjCapCaps as $cp): $v=$caps[$cp]??0; ?><td class="text-end font-monospace <?= $v<=0?'text-muted':'' ?>"><?= $v>0?$fmt($v):'—' ?></td><?php endforeach; ?>
                        <td class="text-end font-monospace fw-bold text-primary"><?= $fmt($prjCapTop[$pk]??0) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(!$prjCapMatris): ?><tr><td colspan="<?= count($prjCapCaps)+2 ?>" class="text-center text-muted py-4">Veri yok.</td></tr><?php endif; ?>
                </tbody>
                <?php if($prjCapMatris): ?>
                <tfoot class="table-light fw-bold"><tr>
                    <td class="text-end">TOPLAM</td>
                    <?php foreach($prjCapCaps as $cp): $ct=0; foreach($prjCapMatris as $caps) $ct+=$caps[$cp]??0; ?><td class="text-end font-monospace"><?= $fmt($ct) ?></td><?php endforeach; ?>
                    <td class="text-end font-monospace text-primary"><?= $fmt(array_sum($prjCapTop)) ?></td>
                </tr></tfoot>
                <?php endif; ?>
            </table>
        </div></div>
    </div></div>
</div>

<!-- Firma bazlı teslim (Proje × Çap) -->
<?php if (!empty($ftm['matris'])): ?>
<div class="card mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-people text-primary me-1"></i> Firma Bazlı Teslim Edilen Demir <span class="text-muted small fw-normal">(Proje × Çap — net, tüm dönem)</span></div>
    <div class="card-body">
        <?php foreach ($ftm['matris'] as $f=>$prjler): ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-dark fs-6"><?= h($f) ?></span>
                <span class="text-muted small">Toplam: <strong><?= $fmt($ftm['firmaToplam'][$f] ?? 0) ?> t</strong></span>
            </div>
            <?= ftm_tablo_html($f, $prjler, $fmt) ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ExcelJS: formatlı xlsx (beton raporlarındaki gibi) -->
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>
const R = {
    donem: <?= json_encode($donem, JSON_UNESCAPED_UNICODE) ?>,
    kpi: { sevkiyat: <?= $totSevk ?>, ton: <?= json_encode($totTon) ?>, kantar: <?= json_encode($totKnt) ?>, fark: <?= json_encode($totFark) ?> },
    aylik: <?= json_encode(array_map(fn($i)=>['ay'=>$ayAdlari[$i],'adet'=>$aylik[$i]['adet'],'ton'=>$aylik[$i]['ton']], range(1,12)), JSON_UNESCAPED_UNICODE) ?>,
    cap: <?= json_encode(array_map(fn($r)=>['ad'=>$r['ad'],'tip'=>$r['tip'],'irs'=>(float)$r['irs'],'knt'=>(float)$r['knt']], $capRows), JSON_UNESCAPED_UNICODE) ?>,
    ted: <?= json_encode(array_map(fn($r)=>['firma'=>$r['firma'],'adet'=>(int)$r['adet'],'irs'=>(float)$r['irs'],'knt'=>(float)$r['knt']], $tedRows), JSON_UNESCAPED_UNICODE) ?>,
    proje: <?= json_encode(array_map(fn($r)=>['kod'=>$r['kod'],'adet'=>(int)$r['adet'],'ton'=>(float)$r['ton']], $prjRows), JSON_UNESCAPED_UNICODE) ?>,
    projeCaps: <?= json_encode($prjCapCaps, JSON_UNESCAPED_UNICODE) ?>,
    projeCap: <?= json_encode(array_map(fn($pk,$caps)=>['proje'=>$pk,'caps'=>$caps,'top'=>round($prjCapTop[$pk]??0,3)], array_keys($prjCapMatris), array_values($prjCapMatris)), JSON_UNESCAPED_UNICODE) ?>,
    detay: <?= json_encode(array_map(fn($r)=>['tarih'=>$r['irsaliye_tarih'],'irsaliye'=>$r['irsaliye_no'],'tedarikci'=>$r['tedarikci'],'proje'=>$r['proje'],'taseron'=>$r['taseron'],'plaka'=>$r['arac_plaka'],'kantar_fis'=>$r['kantar_fis_no'],'irs'=>(float)$r['irs'],'knt'=>(float)$r['knt']], $detayRows), JSON_UNESCAPED_UNICODE) ?>,
    firmaTeslim: <?= json_encode($firmaFlat, JSON_UNESCAPED_UNICODE) ?>
};

// ── Grafikler ─────────────────────────────────────────────────────────────────
(function(){
    const green='#00584E', teal='#00C9B1', pal=['#00584E','#00C9B1','#C9A84C','#007A6A','#3B82F6','#8B5CF6','#F59E0B','#E05454','#10B981','#6366F1'];
    new Chart(document.getElementById('chAylik'), { type:'bar',
        data:{ labels:R.aylik.map(a=>a.ay), datasets:[{label:'Ton', data:R.aylik.map(a=>a.ton), backgroundColor:green, borderRadius:4}] },
        options:{ plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} } });
    if(R.ted.length) new Chart(document.getElementById('chTed'), { type:'doughnut',
        data:{ labels:R.ted.map(t=>t.firma), datasets:[{data:R.ted.map(t=>t.irs), backgroundColor:pal}] },
        options:{ plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:10}}}} } });
    if(R.cap.length) new Chart(document.getElementById('chCap'), { type:'bar',
        data:{ labels:R.cap.map(c=>c.ad), datasets:[{label:'İrsaliye (t)', data:R.cap.map(c=>c.irs), backgroundColor:teal, borderRadius:3}] },
        options:{ plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} } });
    var elP=document.getElementById('chProje');
    if(elP && R.proje.length) new Chart(elP, { type:'doughnut',
        data:{ labels:R.proje.map(p=>p.kod), datasets:[{data:R.proje.map(p=>p.ton), backgroundColor:pal, borderWidth:0}] },
        options:{ plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}}, cutout:'60%' } });
})();

// ── Excel (ExcelJS) ───────────────────────────────────────────────────────────
async function exportExcel(){
    const btn=document.getElementById('btnXls'); btn.disabled=true; const o=btn.innerHTML; btn.innerHTML='Hazırlanıyor...';
    try{
        const wb=new ExcelJS.Workbook(); wb.creator='ERN Demir Takip';
        const HDR={fill:{type:'pattern',pattern:'solid',fgColor:{argb:'FF00584E'}},font:{color:{argb:'FFFFFFFF'},bold:true},alignment:{vertical:'middle'}};
        const title=(ws,t)=>{ ws.mergeCells('A1:E1'); const c=ws.getCell('A1'); c.value=t; c.font={bold:true,size:14,color:{argb:'FF00584E'}}; ws.getCell('A2').value='Dönem: '+R.donem; ws.addRow([]); };
        const styleHdr=(row)=>row.eachCell(c=>{c.fill=HDR.fill;c.font=HDR.font;c.alignment=HDR.alignment;});

        // Özet
        let ws=wb.addWorksheet('Özet'); title(ws,'DEMİR RAPORU — ÖZET');
        ws.addRow(['Sevkiyat', R.kpi.sevkiyat]); ws.addRow(['Toplam Gelen (t)', R.kpi.ton]); ws.addRow(['Toplam Kantar (t)', R.kpi.kantar]); ws.addRow(['Kantar Farkı (t)', R.kpi.fark]); ws.addRow([]);
        let h=ws.addRow(['Çap','Tür','İrsaliye (t)','Kantar (t)','Fark (t)']); styleHdr(h);
        R.cap.forEach(c=>ws.addRow([c.ad,c.tip,c.irs,c.knt,c.knt-c.irs]));
        ws.columns.forEach(col=>col.width=16); ws.getColumn(1).width=22;

        // Aylık
        ws=wb.addWorksheet('Aylık'); title(ws,'AYLIK DAĞILIM');
        h=ws.addRow(['Ay','Sevkiyat','Ton']); styleHdr(h);
        R.aylik.forEach(a=>ws.addRow([a.ay,a.adet,a.ton])); ws.columns.forEach(c=>c.width=16);

        // Tedarikçi
        ws=wb.addWorksheet('Tedarikçi'); title(ws,'TEDARİKÇİ ÖZETİ');
        h=ws.addRow(['Tedarikçi','Sevkiyat','İrsaliye (t)','Kantar (t)','Fark (t)']); styleHdr(h);
        R.ted.forEach(t=>ws.addRow([t.firma,t.adet,t.irs,t.knt,t.knt-t.irs])); ws.columns.forEach(c=>c.width=18); ws.getColumn(1).width=24;

        // Proje (kırılım + çap matrisi)
        ws=wb.addWorksheet('Proje'); title(ws,'PROJE KIRILIMI');
        h=ws.addRow(['Proje','Sevkiyat','Gelen (t)']); styleHdr(h);
        R.proje.forEach(pp=>ws.addRow([pp.kod,pp.adet,pp.ton])); ws.addRow([]);
        if (R.projeCap.length) {
            let hc=ws.addRow(['Proje × Çap'].concat(R.projeCaps).concat(['Toplam'])); styleHdr(hc);
            R.projeCap.forEach(row=>{
                const cells=[row.proje].concat(R.projeCaps.map(c=>row.caps[c]||0)).concat([row.top]);
                ws.addRow(cells);
            });
        }
        ws.columns.forEach(c=>c.width=14); ws.getColumn(1).width=22;

        // Firma bazlı teslim
        if (R.firmaTeslim.length) {
            ws=wb.addWorksheet('Firma Bazlı'); title(ws,'FİRMA BAZLI TESLİM (Proje × Çap — net, tüm dönem)');
            h=ws.addRow(['Firma','Proje','Çap','Ton']); styleHdr(h);
            R.firmaTeslim.forEach(x=>ws.addRow([x.firma,x.proje,x.cap,x.ton]));
            ws.columns.forEach(c=>c.width=18); ws.getColumn(1).width=24;
        }

        // Detay
        ws=wb.addWorksheet('Detay'); title(ws,'SEVKİYAT DETAYI');
        h=ws.addRow(['Tarih','İrsaliye No','Tedarikçi','Proje','Taşeron','Araç Plaka','Kantar Fiş','İrsaliye (t)','Kantar (t)']); styleHdr(h);
        R.detay.forEach(d=>ws.addRow([d.tarih,d.irsaliye,d.tedarikci,d.proje,d.taseron,d.plaka,d.kantar_fis,d.irs,d.knt])); ws.columns.forEach(c=>c.width=16); ws.getColumn(2).width=22;

        const buf=await wb.xlsx.writeBuffer();
        const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([buf],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
        a.download='ERN_Demir_Rapor_'+new Date().toISOString().slice(0,10)+'.xlsx'; a.click();
    }catch(e){ alert('Excel oluşturulamadı: '+e.message); }
    btn.disabled=false; btn.innerHTML=o;
}

// ── PDF (yazdırma penceresi) ──────────────────────────────────────────────────
function exportPDF(){
    const f=n=>Number(n).toLocaleString('tr-TR',{minimumFractionDigits:3,maximumFractionDigits:3});
    const tbl=(head,rows)=>'<table><thead><tr>'+head.map(h=>'<th>'+h+'</th>').join('')+'</tr></thead><tbody>'+rows.map(r=>'<tr>'+r.map((c,i)=>'<td'+(i>0?' class="r"':'')+'>'+c+'</td>').join('')+'</tr>').join('')+'</tbody></table>';
    let html='<h1>DEMİR RAPORU</h1><div class="meta">Dönem: '+R.donem+' — Yazdırma: '+new Date().toLocaleString('tr-TR')+'</div>';
    html+='<div class="kpis"><div><b>'+R.kpi.sevkiyat+'</b>Sevkiyat</div><div><b>'+f(R.kpi.ton)+' t</b>Toplam Gelen</div><div><b>'+f(R.kpi.kantar)+' t</b>Kantar</div><div><b>'+(R.kpi.fark>0?'+':'')+f(R.kpi.fark)+' t</b>Fark</div></div>';
    html+='<h2>Çap Bazında</h2>'+tbl(['Çap','İrsaliye','Kantar','Fark'], R.cap.map(c=>[c.ad,f(c.irs),f(c.knt),(c.knt-c.irs>0?'+':'')+f(c.knt-c.irs)]));
    html+='<h2>Tedarikçi Özeti</h2>'+tbl(['Tedarikçi','Sevkiyat','İrsaliye','Fark'], R.ted.map(t=>[t.firma,t.adet,f(t.irs),(t.knt-t.irs>0?'+':'')+f(t.knt-t.irs)]));
    html+='<h2>Proje Özeti</h2>'+tbl(['Proje','Sevkiyat','Gelen'], R.proje.map(p=>[p.kod,p.adet,f(p.ton)]));
    if(R.firmaTeslim.length) html+='<h2>Firma Bazlı Teslim (Proje × Çap — net)</h2>'+tbl(['Firma','Proje','Çap','Ton'], R.firmaTeslim.map(x=>[x.firma,x.proje,x.cap,f(x.ton)]));
    const w=window.open('','_blank');
    w.document.write('<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Demir Raporu</title><style>'
        +'body{font-family:Segoe UI,Arial,sans-serif;color:#111;padding:24px;} h1{color:#00584E;font-size:20px;margin:0;} h2{color:#00584E;font-size:14px;border-bottom:1px solid #ddd;padding-bottom:4px;margin:20px 0 6px;} .meta{color:#666;font-size:12px;margin-bottom:14px;}'
        +'table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px;} th,td{border:1px solid #bbb;padding:5px 8px;} th{background:#00584E;color:#fff;} td.r{text-align:right;}'
        +'.kpis{display:flex;gap:10px;margin:10px 0;} .kpis div{flex:1;border:1px solid #ddd;border-radius:8px;padding:8px;text-align:center;font-size:11px;color:#666;} .kpis b{display:block;font-size:17px;color:#111;}'
        +'@media print{body{padding:6mm;}}</style></head><body>'+html
        +'<script>window.onload=function(){setTimeout(function(){window.print();},300);}<\/script></body></html>');
    w.document.close();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
