<?php
/**
 * icmal.php — Blok bazında işlem icmali
 *
 * ÜÇ GÖRÜNÜM (`?g=`), üçü de aynı tabloyu çizer ama farklı kaynaktan:
 *  • **excel**   — kitabın İCMAL sayfasının BİREBİR fotoğrafı (`prekast_icmal`, her içe aktarımda
 *                  saklanır). Sahanın ekranda gördüğü tablo budur; snapshot varsa VARSAYILAN.
 *  • **olculen** — sistemin kendi hesabı, yalnız GERÇEKTEN ölçülmüş metrajla (çizelgedeki
 *                  Metraj sütununun toplamıyla birebir).
 *  • **tahmin**  — sistemin kendi hesabı, Excel'in mantığıyla: ölçülmemiş satırlara ölçülenlerin
 *                  ortalaması yazılır. ⚠ Bu toplam çizelgede hiçbir yerde geçmez.
 *
 * ⚠⚠ Excel ile sistem NEDEN tutmaz: kitabın İCMAL sayfası HESAPLAMA'nın ELLE YAZILMIŞ
 * "Kesim/Silikon Sayacı" sütunlarından SUMIFS ile beslenir. Aynı daire iki satırda geçtiğinde
 * sayaç ikisinde de 1 kalabiliyor (2026-09-21 kitabında 16 daire böyle çift sayılmış: İCMAL
 * 117 daire diyor, HESAPLAMA'da 101 benzersiz daire var) ve bazı hücreler (A 2,00 · E 127,00)
 * doğrudan elle yazılmış — hiçbir satır kümesinden doğmuyor. Sistem satırları sayar, uydurmaz;
 * fark gizlenmez, aşağıdaki karşılaştırma tablosunda satır satır gösterilir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_prekast.php';
require_once __DIR__ . '/_ortak.php';

pk_semasi_kur($pdoPrekast);
$pageTitle = 'Prekast Blok İcmali';

$cizelgeler = pk_secenekler($pdoPrekast, 'cizelge');
$secili = trim((string)($_GET['cizelge'] ?? ''));
if ($secili !== '' && !in_array($secili, $cizelgeler, true)) $secili = '';
$ic   = pk_icmal($pdoPrekast, $secili);
$son  = pk_son_import($pdoPrekast);
$xIc  = pk_excel_icmal_son($pdoPrekast, $secili);

$gorunum = (string)($_GET['g'] ?? '');
if (!in_array($gorunum, ['excel','olculen','tahmin'], true)) $gorunum = $xIc ? 'excel' : 'olculen';
if ($gorunum === 'excel' && !$xIc) $gorunum = 'olculen';
$tahmin = $gorunum === 'tahmin';
$mtK = $tahmin ? 'kesimMt'   : 'kesimOlculen';
$mtS = $tahmin ? 'silikonMt' : 'silikonOlculen';
$tahminVar = ($ic['toplam']['kesimTahmini'] ?? 0) > 0;

// ——— Gösterilecek tablo tek yapıda toplanır (tablo · KPI · grafik · Excel/PDF hepsi bunu okur) ———
$bos = ['kesimDaire'=>0, 'kesimMt'=>0.0, 'silikonDaire'=>0, 'silikonMt'=>0.0, 'oran'=>0.0,
        'tahminiSatir'=>0, 'kesimTahmini'=>0.0, 'silikonTahmini'=>0.0];
$satirlar = []; $toplam = $bos;
if ($gorunum === 'excel') {
    foreach ($xIc['blok'] as $b => $v) $satirlar[$b] = $v + $bos;
    $toplam = ($xIc['toplam'] ?? null) ? $xIc['toplam'] + $bos : $bos;
    if (!($xIc['toplam'] ?? null)) {
        foreach ($satirlar as $g) foreach (['kesimDaire','kesimMt','silikonDaire','silikonMt'] as $k) $toplam[$k] += $g[$k];
        $toplam['oran'] = $toplam['kesimDaire'] ? $toplam['silikonDaire'] / $toplam['kesimDaire'] : 0.0;
    }
} else {
    foreach ($ic['blok'] as $b => $g) {
        $satirlar[$b] = $g;
        $satirlar[$b]['kesimMt']   = (float)$g[$mtK];
        $satirlar[$b]['silikonMt'] = (float)$g[$mtS];
    }
    $toplam = $ic['toplam'];
    $toplam['kesimMt']   = (float)$ic['toplam'][$mtK];
    $toplam['silikonMt'] = (float)$ic['toplam'][$mtS];
}

// ——— Excel ↔ sistem farkı (yalnız Excel görünümünde gösterilir; gizlenmez) ———
$farklar = [];
if ($xIc) {
    $bloklar = array_unique(array_merge(array_keys($xIc['blok']), array_keys($ic['blok'])));
    foreach ($bloklar as $b) {
        $e = $xIc['blok'][$b] ?? null;
        $g = $ic['blok'][$b]  ?? null;
        $eKD = $e ? (int)$e['kesimDaire'] : null;    $gKD = $g ? (int)$g['kesimDaire'] : null;
        $eSD = $e ? (int)$e['silikonDaire'] : null;  $gSD = $g ? (int)$g['silikonDaire'] : null;
        $eKM = $e ? (float)$e['kesimMt'] : null;     $gKM = $g ? (float)$g['kesimMt'] : null;
        if ($e && $g && $eKD === $gKD && $eSD === $gSD && abs($eKM - $gKM) < 0.05) continue;
        $farklar[] = ['blok'=>$b, 'eKD'=>$eKD, 'gKD'=>$gKD, 'eSD'=>$eSD, 'gSD'=>$gSD, 'eKM'=>$eKM, 'gKM'=>$gKM];
    }
}

$qs = fn(array $ek = []) => '?' . http_build_query(array_filter(
        ['cizelge' => $secili] + $ek, fn($v) => $v !== '' && $v !== null));

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
// Excel görünümünde oran, sayfadaki gibi ONDALIKSIZ yazılır (kitapta biçim "0%")
$yuzde = fn($o) => number_format((float)$o * 100, $gorunum === 'excel' ? 0 : 1, ',', '.') . '%';
$GORUNUM = ['excel'=>['Excel (dosyadan)','bi-file-earmark-excel','success'],
            'olculen'=>['Sistem — ölçülen metraj','bi-rulers','secondary'],
            'tahmin'=>['Sistem — tahmin dahil','bi-magic','warning']];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-table text-primary me-2"></i>Blok Bazında İşlem İcmali</h4>
    <div class="ms-auto d-flex flex-wrap gap-2">
        <?php if (count($cizelgeler) > 1): ?>
        <form method="get"><select name="cizelge" class="form-select form-select-sm" style="max-width:320px" onchange="this.form.submit()">
            <option value="">Tüm çizelgeler</option>
            <?php foreach ($cizelgeler as $c): ?>
                <option value="<?= h($c) ?>" <?= $secili === $c ? 'selected' : '' ?>><?= h(mb_strimwidth($c, 0, 60, '…')) ?></option>
            <?php endforeach; ?>
        </select><input type="hidden" name="g" value="<?= h($gorunum) ?>"></form>
        <?php endif; ?>
        <button class="btn btn-success btn-sm" onclick="icExcel()"><i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</button>
        <button class="btn btn-danger btn-sm" onclick="icPdf('pdf')"><i class="bi bi-file-earmark-pdf me-1"></i>PDF İndir</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="icPdf('print')"><i class="bi bi-printer me-1"></i>Yazdır</button>
    </div>
</div>

<?php if (!$satirlar): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Henüz iş satırı yok — önce <a href="import.php" class="alert-link">günlük çizelgeyi yükleyin</a>.</div>
<?php else: $t = $toplam; ?>

<div class="btn-group btn-group-sm mb-3" role="group">
<?php foreach ($GORUNUM as $k => [$ad, $ikon, $renk]):
        if ($k === 'excel' && !$xIc) continue;
        $aktif = $gorunum === $k; ?>
    <a href="<?= h($qs(['g' => $k])) ?>" class="btn btn-<?= $aktif ? '' : 'outline-' ?><?= $renk ?>">
        <i class="bi <?= $ikon ?> me-1"></i><?= h($ad) ?></a>
<?php endforeach; ?>
</div>

<?php if ($gorunum === 'excel'): ?>
<div class="alert alert-success py-2 small">
    <i class="bi bi-file-earmark-excel me-1"></i>
    <strong>Kitabın İCMAL sayfası — birebir.</strong> Aşağıdaki tablo hesaplanmadı, yüklediğiniz dosyanın
    İCMAL sayfasından olduğu gibi alındı
    (<?= h(date('d.m.Y', strtotime($xIc['rapor_tarihi']))) ?><?= $xIc['dosya'] !== '' ? ' · ' . h(mb_strimwidth($xIc['dosya'], 0, 60, '…')) : '' ?>).
    <?php if ($farklar): ?>
        <span class="text-danger fw-semibold">Sistemin kendi sayımıyla <?= count($farklar) ?> blokta tutmuyor</span> —
        sebebini ve farkı aşağıdaki tabloda görebilirsiniz.
    <?php else: ?>
        Sistemin kendi sayımıyla <strong>birebir tutuyor</strong>.
    <?php endif; ?>
</div>
<?php elseif ($tahmin): ?>
<div class="alert alert-warning py-2 small">
    <i class="bi bi-magic me-1"></i>
    <strong>Sistem hesabı — TAHMİN DAHİL (Excel'in mantığı).</strong> Metrajı ölçülmemiş
    <?= $f0($ic['toplam']['tahminiSatir']) ?> satıra ölçülenlerin ortalaması
    (<?= $f2($ic['ortMetraj']) ?> m = <?= $f2($ic['olculenToplam']) ?> m / <?= $f0($ic['olculenAdet']) ?> ölçülen satır)
    yazılır. ⚠ Bu toplam <strong>çizelgede geçmez</strong>; hakkediş yalnız ölçülen metrajdan doğar.
</div>
<?php else: ?>
<div class="alert alert-secondary py-2 small">
    <i class="bi bi-rulers me-1"></i>
    <strong>Sistem hesabı — ÖLÇÜLEN metraj.</strong> Çizelgedeki <em>Metraj</em> sütununun toplamıyla birebir
    aynıdır (<?= $f2($ic['olculenToplam']) ?> m / <?= $f0($ic['olculenAdet']) ?> ölçülen satır).
    Daire sayıları <strong>benzersiz blok/daire</strong> üzerinden sayılır (aynı dairedeki ikinci iş ayrı
    daire sayılmaz).<?php if ($tahminVar): ?> Metrajı henüz ölçülmemiş
    <?= $f0($ic['toplam']['tahminiSatir']) ?> satır <strong>0 sayılır</strong>.<?php endif; ?>
    <?php if ($son): ?><span class="text-muted">· Son çizelge: <?= h(date('d.m.Y', strtotime($son['rapor_tarihi']))) ?></span><?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-2 mb-3">
<?php foreach ([
    ['Kesim yapılan daire', $f0($t['kesimDaire']), 'warning', 'bi-scissors'],
    ['Kesim (mt)', $f2($t['kesimMt']) . ($tahmin && $t['kesimTahmini'] > 0 ? ' <span class="small text-muted fw-normal">(' . $f2($t['kesimTahmini']) . ' tahmini)</span>' : ''), 'warning', 'bi-rulers'],
    ['Silikon yapılan daire', $f0($t['silikonDaire']), 'success', 'bi-check-circle-fill'],
    ['Silikon (mt)', $f2($t['silikonMt']) . ($tahmin && $t['silikonTahmini'] > 0 ? ' <span class="small text-muted fw-normal">(' . $f2($t['silikonTahmini']) . ' tahmini)</span>' : ''), 'success', 'bi-rulers'],
    ['Silikon / Kesim', $yuzde($t['oran']), 'primary', 'bi-percent'],
    $gorunum === 'excel'
        ? ['Blok', $f0(count($satirlar)), 'info', 'bi-building']
        : ['Ort. metraj', $f2($ic['ortMetraj']) . ' m', 'info', 'bi-calculator'],
] as [$ad, $deger, $renk, $ikon]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3 text-center">
            <i class="bi <?= $ikon ?> text-<?= $renk ?> fs-5"></i>
            <div class="fs-6 fw-bold mt-1"><?= $deger ?></div>
            <div class="small text-muted"><?= $ad ?></div>
        </div></div>
    </div>
<?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white small fw-semibold d-flex align-items-center gap-2">
                <i class="bi bi-building me-1"></i>Blok bazında
                <span class="badge bg-<?= $GORUNUM[$gorunum][2] ?>-subtle text-<?= $GORUNUM[$gorunum][2] ?>-emphasis border ms-auto"><?= h($GORUNUM[$gorunum][0]) ?></span>
            </div>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.85rem">
                <thead class="table-light"><tr>
                    <th>Blok</th>
                    <th class="text-end">Kesim Yapılan Daire</th><th class="text-end">Kesim (mt)</th>
                    <th class="text-end">Silikon Yapılan Daire</th><th class="text-end">Silikon (mt)</th>
                    <th class="text-end">Silikon / Kesim</th>
                </tr></thead>
                <tbody>
                <?php foreach ($satirlar as $b => $g): ?>
                    <tr>
                        <td class="fw-semibold"><a href="isler.php?blok=<?= urlencode($b) ?>" class="text-decoration-none"><?= h($b) ?></a>
                            <?php if ($gorunum !== 'excel' && $g['tahminiSatir']): ?><span class="badge bg-light text-dark border ms-1" title="Metrajı henüz ölçülmemiş satır sayısı"><?= (int)$g['tahminiSatir'] ?> ölçülmemiş</span><?php endif; ?></td>
                        <td class="text-end"><?= $f0($g['kesimDaire']) ?></td>
                        <td class="text-end"><?= $f2($g['kesimMt']) ?>
                            <?php if ($tahmin && $g['kesimTahmini'] > 0): ?><div class="text-muted" style="font-size:.72rem">~<?= $f2($g['kesimTahmini']) ?> tahmini</div><?php endif; ?></td>
                        <td class="text-end text-success fw-semibold"><?= $f0($g['silikonDaire']) ?></td>
                        <td class="text-end"><?= $f2($g['silikonMt']) ?>
                            <?php if ($tahmin && $g['silikonTahmini'] > 0): ?><div class="text-muted" style="font-size:.72rem">~<?= $f2($g['silikonTahmini']) ?> tahmini</div><?php endif; ?></td>
                        <td class="text-end">
                            <div class="d-flex align-items-center justify-content-end gap-2">
                                <div class="progress flex-grow-1" style="height:8px;max-width:90px"><div class="progress-bar bg-success" style="width:<?= min(100, $g['oran'] * 100) ?>%"></div></div>
                                <span><?= $yuzde($g['oran']) ?></span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold"><tr>
                    <td>TOPLAM</td>
                    <td class="text-end"><?= $f0($t['kesimDaire']) ?></td><td class="text-end"><?= $f2($t['kesimMt']) ?></td>
                    <td class="text-end text-success"><?= $f0($t['silikonDaire']) ?></td><td class="text-end"><?= $f2($t['silikonMt']) ?></td>
                    <td class="text-end"><?= $yuzde($t['oran']) ?></td>
                </tr></tfoot>
            </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="fw-semibold mb-2"><i class="bi bi-bar-chart me-1"></i>Kesim / silikon yapılan daire</div>
            <div style="height:300px"><canvas id="chIcmal"></canvas></div>
        </div></div>
    </div>
</div>

<?php if ($xIc && $farklar): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white small fw-semibold"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Excel İCMAL ↔ sistem sayımı farkı</div>
    <div class="card-body pb-2">
        <p class="small text-muted mb-2">
            Excel'in İCMAL sayfası, HESAPLAMA sayfasındaki <strong>elle yazılmış</strong> sayaç sütunlarından
            SUMIFS ile beslenir. Aynı daire iki satırda geçtiğinde sayaç ikisinde de 1 kalabildiği için
            daire sayısı şişebiliyor; bazı hücreler ise doğrudan elle yazılmış ve hiçbir satırdan doğmuyor.
            Sistem <strong>benzersiz blok/daire</strong> sayar. Aşağıdaki fark bir hata değil, iki farklı
            sayma biçimidir — hangisine göre ilerleyeceğinize siz karar verin.
        </p>
        <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0" style="font-size:.82rem">
            <thead class="table-light"><tr>
                <th rowspan="2" class="align-middle">Blok</th>
                <th colspan="3" class="text-center">Kesim yapılan daire</th>
                <th colspan="3" class="text-center">Silikon yapılan daire</th>
                <th colspan="3" class="text-center">Kesim (mt)</th>
            </tr><tr>
                <th class="text-end">Excel</th><th class="text-end">Sistem</th><th class="text-end">Fark</th>
                <th class="text-end">Excel</th><th class="text-end">Sistem</th><th class="text-end">Fark</th>
                <th class="text-end">Excel</th><th class="text-end">Sistem</th><th class="text-end">Fark</th>
            </tr></thead>
            <tbody>
            <?php foreach ($farklar as $fk):
                $cift = fn($e, $g, $ond = 0) => $e === null ? '<td class="text-end text-muted">—</td><td class="text-end">' . ($ond ? $f2($g) : $f0($g)) . '</td><td class="text-end text-primary">yalnız sistemde</td>'
                      : ($g === null ? '<td class="text-end">' . ($ond ? $f2($e) : $f0($e)) . '</td><td class="text-end text-muted">—</td><td class="text-end text-danger">çizelgede yok</td>'
                      : '<td class="text-end">' . ($ond ? $f2($e) : $f0($e)) . '</td><td class="text-end">' . ($ond ? $f2($g) : $f0($g)) . '</td>'
                        . '<td class="text-end fw-semibold ' . (abs($e - $g) < ($ond ? 0.05 : 0.5) ? 'text-success' : 'text-danger') . '">'
                        . ($e - $g > 0 ? '+' : '') . ($ond ? $f2($e - $g) : $f0($e - $g)) . '</td>'); ?>
                <tr>
                    <td class="fw-semibold"><?= h($fk['blok']) ?></td>
                    <?= $cift($fk['eKD'], $fk['gKD']) ?>
                    <?= $cift($fk['eSD'], $fk['gSD']) ?>
                    <?= $cift($fk['eKM'], $fk['gKM'], 1) ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>window.ERN_ROOT = '../';</script>
<script src="../assets/js/ern_rapor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ern_rapor.js') ?>"></script>
<script>
const IC = <?= json_encode(['blok'=>$satirlar, 'toplam'=>$t, 'ort'=>$ic['ortMetraj'],
    'cizelge'=>$secili !== '' ? $secili : 'Tüm çizelgeler',
    'gorunum'=>$GORUNUM[$gorunum][0], 'tahmin'=>$tahmin,
    'kaynak'=>$gorunum === 'excel'
        ? 'Kitabın İCMAL sayfasından birebir alınmıştır (' . date('d.m.Y', strtotime($xIc['rapor_tarihi'])) . ').'
        : 'Sistemin iş satırlarından hesaplanmıştır; daire sayıları benzersiz blok/daire üzerindendir'
          . ($tahmin ? ', ölçülmemiş metrajlar ölçülenlerin ortalamasıyla tahmin edilmiştir.' : ', ölçülmemiş metraj 0 sayılmıştır.'),
    ], JSON_UNESCAPED_UNICODE) ?>;
const f0 = n => Number(n || 0).toLocaleString('tr-TR');
const f2 = n => Number(n || 0).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
const pct = o => (Number(o || 0) * 100).toLocaleString('tr-TR', {maximumFractionDigits:1}) + '%';
const bloklar = Object.keys(IC.blok);

new Chart(document.getElementById('chIcmal'), {
    type: 'bar',
    data: { labels: bloklar, datasets: [
        { label: 'Kesim yapılan daire', data: bloklar.map(b => IC.blok[b].kesimDaire), backgroundColor: '#ffc107' },
        { label: 'Silikon yapılan daire', data: bloklar.map(b => IC.blok[b].silikonDaire), backgroundColor: '#198754' }
    ]},
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } },
               scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

function icSatirlar() {
    const r = bloklar.map(b => { const g = IC.blok[b]; return [b, f0(g.kesimDaire), f2(g.kesimMt), f0(g.silikonDaire), f2(g.silikonMt), pct(g.oran)]; });
    const t = IC.toplam; r.push(['TOPLAM', f0(t.kesimDaire), f2(t.kesimMt), f0(t.silikonDaire), f2(t.silikonMt), pct(t.oran)]);
    return r;
}
function icPdf(mode) {
    const html = '<p><b>Çizelge:</b> ' + ERN_RAPOR.esc(IC.cizelge) + ' &nbsp; <b>Görünüm:</b> ' + ERN_RAPOR.esc(IC.gorunum) + '</p>'
        + '<h2>Blok Bazında İşlem İcmali</h2>'
        + ERN_RAPOR.tbl(['Blok','Kesim Yapılan Daire','Kesim (mt)','Silikon Yapılan Daire','Silikon (mt)','Silikon / Kesim'], icSatirlar())
        + '<p style="font-size:11px;color:#555">' + ERN_RAPOR.esc(IC.kaynak) + '</p>';
    ERN_RAPOR.popup({ title: 'PREKAST BLOK BAZINDA İŞLEM İCMALİ', body: html, mode: mode, filename: 'ERN_Prekast_Icmal' });
}
async function icExcel() {
    const wb = await ERN_RAPOR.wb();
    const ws = wb.addWorksheet('İcmal');
    ws.columns = [{width:10},{width:20},{width:14},{width:22},{width:14},{width:16}];
    ERN_RAPOR.title(wb, ws, 'BLOK BAZINDA İŞLEM İCMALİ', 6, IC.cizelge + ' — ' + IC.gorunum);
    ERN_RAPOR.hdr(ws.addRow(['Blok','Kesim Yapılan Daire','Kesim (mt)','Silikon Yapılan Daire','Silikon (mt)','Silikon / Kesim']));
    bloklar.forEach(b => { const g = IC.blok[b]; ws.addRow([b, g.kesimDaire, +Number(g.kesimMt).toFixed(2), g.silikonDaire, +Number(g.silikonMt).toFixed(2), +Number(g.oran).toFixed(4)]); });
    const t = IC.toplam; ws.addRow(['TOPLAM', t.kesimDaire, +Number(t.kesimMt).toFixed(2), t.silikonDaire, +Number(t.silikonMt).toFixed(2), +Number(t.oran).toFixed(4)]).font = { bold: true };
    await ERN_RAPOR.save(wb, 'ERN_Prekast_Icmal_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
