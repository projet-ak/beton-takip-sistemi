<?php
/**
 * raporlar.php — Depo raporları
 *
 * Kaynaklar: depo_kalemler (stok fotoğrafı) + depo_hareketler (giriş/çıkış defteri).
 * Bölümler: KPI · kategori/disiplin mali değer · aylık giriş-çıkış trendi ·
 * firma bazlı çıkış · en değerli kalemler · en çok çıkan malzemeler ·
 * el aletleri zimmet · tükenenler · hurda özeti.
 * Dışa aktarma: 4 ayrı Excel (ERN Taahhüt logolu, XlsxWriter otomatik) + PDF/Yazdır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoDepo->query("SELECT 1 FROM depo_kalemler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_depo.php'); }
dp_hareket_semasi_kur($pdoDepo);

// ── Stok özeti ───────────────────────────────────────────────────────────────
$ozet = dp_ozet($pdoDepo);
$topDeger=0; $topKalem=0; $topTukenen=0;
foreach ($ozet as $o) { $topDeger+=(float)$o['deger']; $topKalem+=(int)$o['adet']; $topTukenen+=(int)$o['tukenen']; }

$disiplin = $pdoDepo->query("SELECT COALESCE(NULLIF(disiplin,''),'Disiplin girilmemiş') disiplin,
    SUM((sayim+gelen-giden)*COALESCE(birim_fiyat,0)) deger, COUNT(*) adet
    FROM depo_kalemler WHERE aktif=1 AND kategori<>'el_aleti'
    GROUP BY disiplin HAVING deger>0 ORDER BY deger DESC")->fetchAll();

$topKalemler = $pdoDepo->query("SELECT kategori, kod, ad, birim, (sayim+gelen-giden) stok, birim_fiyat,
    (sayim+gelen-giden)*COALESCE(birim_fiyat,0) deger
    FROM depo_kalemler WHERE aktif=1 AND kategori<>'el_aleti' AND birim_fiyat>0
    ORDER BY deger DESC LIMIT 15")->fetchAll();

// ── Hareket defteri analizleri ───────────────────────────────────────────────
$hVar = (int)$pdoDepo->query("SELECT COUNT(*) FROM depo_hareketler")->fetchColumn() > 0;

$aylik = [];      // ay => ['g'=>giriş hareket, 'c'=>çıkış hareket]
$firmaCikis = []; $cokCikan = []; $hurdaOzet = ['adet'=>0,'miktar'=>0];
if ($hVar) {
    foreach ($pdoDepo->query("SELECT DATE_FORMAT(tarih,'%Y-%m') ay, tur, COUNT(*) adet
        FROM depo_hareketler WHERE tarih IS NOT NULL
        GROUP BY ay, tur ORDER BY ay DESC LIMIT 48") as $r) {
        $aylik[$r['ay']][$r['tur']==='giris'?'g':'c'] = (int)$r['adet'];
    }
    $aylik = array_slice($aylik, 0, 12, true); ksort($aylik);

    $firmaCikis = $pdoDepo->query("SELECT firma, COUNT(*) adet
        FROM depo_hareketler WHERE tur='cikis' AND firma IS NOT NULL AND firma<>''
        GROUP BY firma ORDER BY adet DESC LIMIT 10")->fetchAll();

    // En çok çıkış yapılan malzemeler (aynı birimde toplanır; hareket sayısıyla birlikte)
    $cokCikan = $pdoDepo->query("SELECT malzeme, COALESCE(NULLIF(birim,''),'Adet') birim,
        COUNT(*) hareket, SUM(miktar) miktar
        FROM depo_hareketler WHERE tur='cikis' AND hurda=0
        GROUP BY malzeme, birim ORDER BY hareket DESC, miktar DESC LIMIT 15")->fetchAll();

    $hurdaOzet = $pdoDepo->query("SELECT COUNT(*) adet, COALESCE(SUM(miktar),0) miktar
        FROM depo_hareketler WHERE hurda=1")->fetch();
}

// ── El aletleri zimmet özeti (kişi bazında) ─────────────────────────────────
$zimmet = $pdoDepo->query("SELECT COALESCE(NULLIF(alan_kisi,''),'— zimmetsiz —') kisi,
    COUNT(*) adet, SUM(sayim+gelen-giden) stok
    FROM depo_kalemler WHERE aktif=1 AND kategori='el_aleti'
    GROUP BY kisi ORDER BY adet DESC")->fetchAll();

// ── Tükenenler ───────────────────────────────────────────────────────────────
$tukenenler = $pdoDepo->query("SELECT kategori, kod, ad, ozellik, birim, (sayim+gelen-giden) stok
    FROM depo_kalemler WHERE aktif=1 AND (sayim+gelen-giden)<=0 ORDER BY kategori, ad")->fetchAll();

$fmt0 = fn($n)=>number_format((float)$n,0,',','.');
$fmt2 = fn($n)=>number_format((float)$n,2,',','.');

// ── Excel dışa aktarmalar (?disaaktar=xlsx&rapor=…) ─────────────────────────
if (($_GET['disaaktar'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $rapor = $_GET['rapor'] ?? 'ozet';
    if ($rapor === 'degerli') {
        $xl = new \XlsxWriter('En Değerli Kalemler');
        $xl->header(['Kategori','Kod','Malzeme','Birim','Stok','Birim Fiyat (TL)','Mali Değer (TL)']);
        foreach ($topKalemler as $r) $xl->row([
            ['v'=>dp_katAd($r['kategori'])],['v'=>$r['kod']],['v'=>$r['ad']],['v'=>$r['birim']],
            ['v'=>(float)$r['stok'],'t'=>'number'],['v'=>(float)$r['birim_fiyat'],'t'=>'number'],['v'=>(float)$r['deger'],'t'=>'number']]);
        $xl->download('depo_degerli_kalemler_'.date('Ymd_Hi').'.xlsx');
    } elseif ($rapor === 'tukenen') {
        $xl = new \XlsxWriter('Tükenen Kalemler');
        $xl->header(['Kategori','Kod','Malzeme','Özellik','Birim','Stok']);
        foreach ($tukenenler as $r) $xl->row([
            ['v'=>dp_katAd($r['kategori'])],['v'=>$r['kod']],['v'=>$r['ad']],['v'=>$r['ozellik']],['v'=>$r['birim']],
            ['v'=>(float)$r['stok'],'t'=>'number']]);
        $xl->download('depo_tukenenler_'.date('Ymd_Hi').'.xlsx');
    } elseif ($rapor === 'cikis') {
        $xl = new \XlsxWriter('En Çok Çıkan Malzemeler');
        $xl->header(['Malzeme','Birim','Çıkış Hareketi','Toplam Miktar']);
        foreach ($cokCikan as $r) $xl->row([
            ['v'=>$r['malzeme']],['v'=>$r['birim']],['v'=>(int)$r['hareket'],'t'=>'number'],['v'=>(float)$r['miktar'],'t'=>'number']]);
        $xl->download('depo_cok_cikanlar_'.date('Ymd_Hi').'.xlsx');
    } else {
        $xl = new \XlsxWriter('Depo Stok Özeti');
        $xl->header(['Kategori','Kalem','Stok','Mali Değer (TL)','Tükenen']);
        foreach ($ozet as $kat=>$o) $xl->row([
            ['v'=>dp_katAd($kat)],['v'=>(int)$o['adet'],'t'=>'number'],
            ['v'=>(float)$o['stok'],'t'=>'number'],['v'=>(float)$o['deger'],'t'=>'number'],['v'=>(int)$o['tukenen'],'t'=>'number']]);
        $xl->total([['v'=>'TOPLAM'],['v'=>$topKalem,'t'=>'number'],['v'=>''],['v'=>$topDeger,'t'=>'number'],['v'=>$topTukenen,'t'=>'number']]);
        $xl->download('depo_stok_ozeti_'.date('Ymd_Hi').'.xlsx');
    }
}

$pageTitle = 'Depo Raporları';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-bar-chart-line text-primary me-2"></i>Depo Raporları</h4>
    <div class="d-flex gap-2">
        <div class="dropdown">
            <button class="btn btn-outline-success btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="?disaaktar=xlsx&rapor=ozet"><i class="bi bi-boxes me-2"></i>Stok Özeti</a></li>
                <li><a class="dropdown-item" href="?disaaktar=xlsx&rapor=degerli"><i class="bi bi-trophy me-2"></i>En Değerli Kalemler</a></li>
                <li><a class="dropdown-item" href="?disaaktar=xlsx&rapor=cikis"><i class="bi bi-box-arrow-up me-2"></i>En Çok Çıkan Malzemeler</a></li>
                <li><a class="dropdown-item" href="?disaaktar=xlsx&rapor=tukenen"><i class="bi bi-exclamation-triangle me-2"></i>Tükenen Kalemler</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="hareketler.php?disaaktar=xlsx"><i class="bi bi-arrow-left-right me-2"></i>Tüm Hareketler</a></li>
            </ul>
        </div>
        <button type="button" onclick="dpPdf()" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF / Yazdır</button>
    </div>
</div>

<!-- KPI -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-collection me-1"></i>Kalem</div>
        <div class="h4 mb-0 fw-bold"><?= $fmt0($topKalem) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-cash-stack me-1"></i>Mali Değer</div>
        <div class="h5 mb-0 fw-bold text-success"><?= $fmt0($topDeger) ?> <small class="fs-6 text-muted">TL</small></div></div></div></div>
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Tükenen</div>
        <div class="h4 mb-0 fw-bold <?= $topTukenen>0?'text-danger':'' ?>"><?= $fmt0($topTukenen) ?></div></div></div></div>
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-trash3 me-1"></i>Hurda</div>
        <div class="h4 mb-0 fw-bold text-warning-emphasis">
            <a href="hareketler.php?hurda=1" class="text-decoration-none"><?= $fmt0($hurdaOzet['adet']) ?></a>
            <small class="fs-6 text-muted">kayıt</small></div></div></div></div>
    <div class="col-12 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-muted small mb-1"><i class="bi bi-tools me-1"></i>El Aleti Zimmeti</div>
        <div class="h5 mb-0 fw-bold"><?= count(array_filter($zimmet, fn($z)=>$z['kisi']!=='— zimmetsiz —')) ?> <small class="fs-6 text-muted fw-normal">kişide</small></div>
    </div></div></div>
</div>

<!-- Mali değer grafikleri -->
<div class="row g-3 mb-4">
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-1">
            <h6 class="mb-0"><i class="bi bi-pie-chart text-primary me-2"></i>Kategori Dağılımı</h6>
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-primary active" id="kBtnDeger" onclick="katMod('deger')">Mali Değer</button>
                <button type="button" class="btn btn-outline-primary" id="kBtnAdet" onclick="katMod('adet')">Kalem</button>
                <button type="button" class="btn btn-outline-primary" id="kBtnStok" onclick="katMod('stok')">Stok</button>
            </div>
        </div>
        <canvas id="chKat" height="180"></canvas>
        <div class="small text-muted mt-2" id="katNot"></div>
    </div></div></div>
    <div class="col-lg-8"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6 class="mb-3"><i class="bi bi-bar-chart text-primary me-2"></i>Disiplin Bazlı Mali Değer</h6>
        <canvas id="chDis" height="90"></canvas>
    </div></div></div>
</div>

<?php if ($hVar): ?>
<!-- Hareket analizleri -->
<div class="row g-3 mb-4">
    <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6 class="mb-3"><i class="bi bi-graph-up text-primary me-2"></i>Aylık Giriş / Çıkış Hareketi (son 12 ay)</h6>
        <canvas id="chAylik" height="100"></canvas>
    </div></div></div>
    <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6 class="mb-3"><i class="bi bi-building text-primary me-2"></i>En Çok Çıkış Yapılan Firmalar</h6>
        <canvas id="chFirma" height="140"></canvas>
    </div></div></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <!-- En değerli kalemler -->
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-trophy text-warning me-2"></i>En Değerli Kalemler</div>
        <div class="card-body p-0"><div class="table-responsive" style="max-height:46vh">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0"><tr><th>#</th><th>Malzeme</th><th class="text-end">Stok</th><th class="text-end">B.Fiyat</th><th class="text-end">Değer (TL)</th></tr></thead>
                <tbody>
                <?php foreach ($topKalemler as $ix=>$r): ?>
                    <tr><td class="text-muted"><?= $ix+1 ?></td>
                        <td class="fw-semibold small"><?= h($r['ad']) ?> <span class="badge bg-light text-dark"><?= h(dp_katAd($r['kategori'])) ?></span></td>
                        <td class="text-end font-monospace"><?= $fmt2($r['stok']) ?></td>
                        <td class="text-end font-monospace small"><?= $fmt2($r['birim_fiyat']) ?></td>
                        <td class="text-end font-monospace fw-bold text-success"><?= $fmt0($r['deger']) ?></td></tr>
                <?php endforeach; ?>
                <?php if(!$topKalemler): ?><tr><td colspan="5" class="text-center text-muted py-4">Fiyatlı kalem yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div></div>

    <!-- En çok çıkan malzemeler -->
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-box-arrow-up text-danger me-2"></i>En Çok Çıkış Yapılan Malzemeler</div>
        <div class="card-body p-0"><div class="table-responsive" style="max-height:46vh">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0"><tr><th>#</th><th>Malzeme</th><th class="text-end">Hareket</th><th class="text-end">Toplam Miktar</th></tr></thead>
                <tbody>
                <?php foreach ($cokCikan as $ix=>$r): ?>
                    <tr><td class="text-muted"><?= $ix+1 ?></td>
                        <td class="small"><a href="hareketler.php?tur=cikis&ara=<?= h(urlencode($r['malzeme'])) ?>" class="text-decoration-none"><?= h($r['malzeme']) ?></a></td>
                        <td class="text-end fw-semibold"><?= (int)$r['hareket'] ?></td>
                        <td class="text-end font-monospace"><?= $fmt2($r['miktar']) ?> <small class="text-muted"><?= h($r['birim']) ?></small></td></tr>
                <?php endforeach; ?>
                <?php if(!$cokCikan): ?><tr><td colspan="4" class="text-center text-muted py-4">Hareket verisi yok — <a href="import.php">Excel içe aktarın</a>.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div></div>
</div>

<div class="row g-3 mb-4">
    <!-- El aleti zimmetleri -->
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-tools text-primary me-2"></i>El Aletleri — Zimmet Dağılımı</div>
        <div class="card-body p-0"><div class="table-responsive" style="max-height:40vh">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.84rem">
                <thead class="table-light" style="position:sticky;top:0"><tr><th>Zimmetli Kişi</th><th class="text-end">Kalem</th><th class="text-end">Adet</th></tr></thead>
                <tbody>
                <?php foreach ($zimmet as $z): ?>
                    <tr class="<?= $z['kisi']==='— zimmetsiz —'?'table-warning':'' ?>">
                        <td class="fw-semibold small"><?= h($z['kisi']) ?></td>
                        <td class="text-end"><?= (int)$z['adet'] ?></td>
                        <td class="text-end font-monospace"><?= $fmt0($z['stok']) ?></td></tr>
                <?php endforeach; ?>
                <?php if(!$zimmet): ?><tr><td colspan="3" class="text-center text-muted py-4">El aleti kaydı yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div></div>

    <!-- Tükenenler -->
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-exclamation-triangle text-danger me-2"></i>Tükenen / Stok Dışı Kalemler</span>
            <span class="badge bg-danger"><?= count($tukenenler) ?></span>
        </div>
        <div class="card-body p-0"><div class="table-responsive" style="max-height:40vh">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0"><tr><th>Kategori</th><th>Malzeme</th><th class="text-end">Stok</th></tr></thead>
                <tbody>
                <?php foreach ($tukenenler as $r): ?>
                    <tr><td><span class="badge bg-light text-dark"><?= h(dp_katAd($r['kategori'])) ?></span></td>
                        <td class="small"><?= h($r['ad']) ?></td>
                        <td class="text-end font-monospace text-danger fw-bold"><?= $fmt2($r['stok']) ?></td></tr>
                <?php endforeach; ?>
                <?php if(!$tukenenler): ?><tr><td colspan="3" class="text-center text-success py-4"><i class="bi bi-check-circle me-1"></i>Tükenen kalem yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div></div>
</div>

<script>
// ── PDF / Yazdır: ERN Taahhüt logolu A4 penceresi ───────────────────────────
const DP_PDF = {
    kpi: { kalem: <?= (int)$topKalem ?>, deger: <?= json_encode(round($topDeger)) ?>,
           tukenen: <?= (int)$topTukenen ?>, hurda: <?= (int)$hurdaOzet['adet'] ?>, hurdaMiktar: <?= json_encode(round((float)$hurdaOzet['miktar'],2)) ?> },
    kat: <?= json_encode(array_map(fn($k,$o)=>['ad'=>dp_katAd($k),'adet'=>(int)$o['adet'],'stok'=>round((float)$o['stok'],2),'deger'=>round((float)$o['deger'])], array_keys($ozet), $ozet), JSON_UNESCAPED_UNICODE) ?>,
    disiplin: <?= json_encode(array_map(fn($d)=>['ad'=>$d['disiplin'],'adet'=>(int)$d['adet'],'deger'=>round((float)$d['deger'])], $disiplin), JSON_UNESCAPED_UNICODE) ?>,
    degerli: <?= json_encode(array_map(fn($r)=>['ad'=>$r['ad'],'kat'=>dp_katAd($r['kategori']),'stok'=>round((float)$r['stok'],2),'deger'=>round((float)$r['deger'])], $topKalemler), JSON_UNESCAPED_UNICODE) ?>,
    cokCikan: <?= json_encode(array_map(fn($r)=>['ad'=>$r['malzeme'],'hareket'=>(int)$r['hareket'],'miktar'=>round((float)$r['miktar'],2),'birim'=>$r['birim']], $cokCikan), JSON_UNESCAPED_UNICODE) ?>,
    zimmet: <?= json_encode(array_map(fn($z)=>['kisi'=>$z['kisi'],'adet'=>(int)$z['adet'],'stok'=>round((float)$z['stok'])], $zimmet), JSON_UNESCAPED_UNICODE) ?>,
    tukenen: <?= json_encode(array_map(fn($r)=>['kat'=>dp_katAd($r['kategori']),'ad'=>$r['ad']], array_slice($tukenenler,0,120)), JSON_UNESCAPED_UNICODE) ?>
};
function dpPdf(){
    const f0 = n => Number(n).toLocaleString('tr-TR');
    const esc = t => String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;');
    const tbl = (hdr, rows) => '<table><thead><tr>'+hdr.map(x=>'<th>'+x+'</th>').join('')+'</tr></thead><tbody>'
        + rows.map(r=>'<tr>'+r.map((x,i)=>'<td'+(i>0?' class="r"':'')+'>'+esc(x)+'</td>').join('')+'</tr>').join('')+'</tbody></table>';
    const logoUrl = new URL('../uploads/logo/ern_taahhut_export.png', location.href).href;
    let html = '<div style="display:flex;align-items:center;gap:12px;border-bottom:3px solid #00584E;padding-bottom:8px;margin-bottom:6px">'
        + '<img src="'+logoUrl+'" style="height:44px" onerror="this.remove()">'
        + '<h1 style="border:none">ERN TAAHHÜT — DEPO RAPORU</h1></div>'
        + '<div class="meta">Yazdırma: '+new Date().toLocaleString('tr-TR')+'</div>'
        + '<div class="kpis"><div><b>'+f0(DP_PDF.kpi.kalem)+'</b>Kalem</div><div><b>'+f0(DP_PDF.kpi.deger)+' TL</b>Mali Değer</div>'
        + '<div><b>'+f0(DP_PDF.kpi.tukenen)+'</b>Tükenen</div><div><b>'+f0(DP_PDF.kpi.hurda)+'</b>Hurda Kaydı</div></div>'
        + '<h2>Kategori Özeti</h2>' + tbl(['Kategori','Kalem','Stok','Mali Değer (TL)'],
            DP_PDF.kat.map(k=>[k.ad, f0(k.adet), f0(k.stok), f0(k.deger)]))
        + '<h2>Disiplin Bazlı Mali Değer</h2>' + tbl(['Disiplin','Kalem','Mali Değer (TL)'],
            DP_PDF.disiplin.map(d=>[d.ad, f0(d.adet), f0(d.deger)]))
        + '<h2>En Değerli Kalemler</h2>' + tbl(['Malzeme','Kategori','Stok','Değer (TL)'],
            DP_PDF.degerli.map(r=>[r.ad, r.kat, f0(r.stok), f0(r.deger)]))
        + (DP_PDF.cokCikan.length ? '<h2>En Çok Çıkış Yapılan Malzemeler</h2>' + tbl(['Malzeme','Hareket','Toplam Miktar'],
            DP_PDF.cokCikan.map(r=>[r.ad, f0(r.hareket), f0(r.miktar)+' '+r.birim])) : '')
        + '<h2>El Aletleri Zimmet Dağılımı</h2>' + tbl(['Zimmetli Kişi','Kalem','Adet'],
            DP_PDF.zimmet.map(z=>[z.kisi, f0(z.adet), f0(z.stok)]))
        + (DP_PDF.tukenen.length ? '<h2>Tükenen Kalemler</h2>' + tbl(['Kategori','Malzeme'],
            DP_PDF.tukenen.map(r=>[r.kat, r.ad])) : '');
    const w = window.open('', '_blank');
    if (!w) { alert('Pop-up engellendi.'); return; }
    w.document.write('<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Depo Raporu</title><style>'
        + 'body{font-family:Segoe UI,Arial,sans-serif;color:#111;padding:24px;} h1{color:#00584E;font-size:20px;margin:0;} h2{color:#00584E;font-size:14px;border-bottom:1px solid #ddd;padding-bottom:4px;margin:18px 0 6px;} .meta{color:#666;font-size:12px;margin-bottom:12px;}'
        + 'table{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:8px;} th,td{border:1px solid #bbb;padding:4px 7px;} th{background:#00584E;color:#fff;} td.r{text-align:right;}'
        + '.kpis{display:flex;gap:10px;margin:10px 0;} .kpis div{flex:1;border:1px solid #ddd;border-radius:8px;padding:8px;text-align:center;font-size:11px;color:#666;} .kpis b{display:block;font-size:16px;color:#111;}'
        + '@media print{body{padding:6mm;}}</style></head><body>'+html
        + '<script>window.onload=function(){setTimeout(function(){window.print();},450);}<\\/script></body></html>');
    w.document.close();
}

(function(){
    const palette=['#00584E','#00C9B1','#C9A84C','#007A6A','#6f42c1','#0d6efd','#fd7e14','#20c997','#d63384','#198754'];
    const KAT = {
        labels: <?= json_encode(array_map(fn($k)=>dp_katAd($k),array_keys($ozet)),JSON_UNESCAPED_UNICODE) ?>,
        deger:  <?= json_encode(array_map(fn($o)=>round((float)$o['deger'],2),array_values($ozet))) ?>,
        adet:   <?= json_encode(array_map(fn($o)=>(int)$o['adet'],array_values($ozet))) ?>,
        stok:   <?= json_encode(array_map(fn($o)=>round((float)$o['stok'],2),array_values($ozet))) ?>
    };
    const katChart = new Chart(document.getElementById('chKat'),{
        type:'doughnut',
        data:{labels:KAT.labels, datasets:[{data:KAT.deger, backgroundColor:palette, borderWidth:0}]},
        options:{plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}},cutout:'60%'}
    });
    window.katMod = function(m){
        katChart.data.datasets[0].data = KAT[m];
        katChart.update();
        ['Deger','Adet','Stok'].forEach(x=>document.getElementById('kBtn'+x).classList.remove('active'));
        document.getElementById('kBtn'+(m==='deger'?'Deger':m==='adet'?'Adet':'Stok')).classList.add('active');
        const sifir = KAT.labels.filter((_,i)=>!KAT[m][i]);
        document.getElementById('katNot').textContent = (m==='deger' && sifir.length)
            ? 'Mali değerde görünmeyenler: ' + sifir.join(', ') + ' — birim fiyat girilmemiş (el aletleri fiyat tutmaz).'
            : '';
    };
    katMod('deger');
    new Chart(document.getElementById('chDis'),{
        type:'bar',
        data:{labels:<?= json_encode(array_column($disiplin,'disiplin'),JSON_UNESCAPED_UNICODE) ?>,
            datasets:[{label:'Mali Değer (TL)',data:<?= json_encode(array_map(fn($d)=>round((float)$d['deger'],2),$disiplin)) ?>,backgroundColor:'#00584E',borderRadius:4}]},
        options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}
    });
    <?php if ($hVar): ?>
    new Chart(document.getElementById('chAylik'),{
        type:'bar',
        data:{labels:<?= json_encode(array_keys($aylik)) ?>,
            datasets:[
                {label:'Giriş', data:<?= json_encode(array_map(fn($a)=>(int)($a['g']??0), array_values($aylik))) ?>, backgroundColor:'rgba(25,135,84,.7)', borderRadius:3},
                {label:'Çıkış', data:<?= json_encode(array_map(fn($a)=>(int)($a['c']??0), array_values($aylik))) ?>, backgroundColor:'rgba(220,53,69,.65)', borderRadius:3}
            ]},
        options:{plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true}}}
    });
    new Chart(document.getElementById('chFirma'),{
        type:'bar',
        data:{labels:<?= json_encode(array_column($firmaCikis,'firma'),JSON_UNESCAPED_UNICODE) ?>,
            datasets:[{label:'Çıkış hareketi',data:<?= json_encode(array_map(fn($x)=>(int)$x['adet'],$firmaCikis)) ?>,backgroundColor:'#C9A84C',borderRadius:4}]},
        options:{indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}
    });
    <?php endif; ?>
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
