<?php
/**
 * icmal.php — Blok bazında işlem icmali (Excel "İCMAL" sayfasının canlı karşılığı)
 *
 * Kaynak kitabın İCMAL sayfası HESAPLAMA'daki ELLE YAZILMIŞ sayaç/metraj sütunlarından
 * beslendiğinden bayat kalabiliyor; burası aynı mantığı (benzersiz daire + ölçülmemiş
 * satırlara ölçülenlerin ortalaması) güncel iş satırlarına uygular — bkz. pk_icmal().
 * Tahmini metraj ölçülenden AYRI gösterilir; hakkediş yalnız ölçülen metrajdan doğar.
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
$ic = pk_icmal($pdoPrekast, $secili);
$son = pk_son_import($pdoPrekast);

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f1 = fn($n) => number_format((float)$n, 1, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
$yuzde = fn($o) => number_format((float)$o * 100, 1, ',', '.') . '%';
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
        </select></form>
        <?php endif; ?>
        <button class="btn btn-success btn-sm" onclick="icExcel()"><i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</button>
        <button class="btn btn-danger btn-sm" onclick="icPdf('pdf')"><i class="bi bi-file-earmark-pdf me-1"></i>PDF İndir</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="icPdf('print')"><i class="bi bi-printer me-1"></i>Yazdır</button>
    </div>
</div>

<?php if (!$ic['blok']): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Henüz iş satırı yok — önce <a href="import.php" class="alert-link">günlük çizelgeyi yükleyin</a>.</div>
<?php else: $t = $ic['toplam']; ?>

<div class="alert alert-secondary py-2 small">
    <i class="bi bi-calculator me-1"></i>
    Excel'in İCMAL mantığı canlı uygulanır: daire sayıları <strong>benzersiz blok/daire</strong> üzerinden
    (aynı dairedeki ikinci iş ayrı daire sayılmaz), metrajı ölçülmemiş satırlara
    <strong>ölçülenlerin ortalaması</strong> yazılır (<?= $f2($ic['ortMetraj']) ?> m = <?= $f2($ic['olculenToplam']) ?> m
    / <?= $f0($ic['olculenAdet']) ?> ölçülen satır). Tahmini kısım tabloda <em>ayrı</em> gösterilir;
    <strong>hakkediş yalnız ölçülen metrajdan</strong> doğar.
    <?php if ($son): ?><span class="text-muted">· Son çizelge: <?= h(date('d.m.Y', strtotime($son['rapor_tarihi']))) ?></span><?php endif; ?>
</div>

<div class="row g-2 mb-3">
<?php foreach ([
    ['Kesim yapılan daire', $f0($t['kesimDaire']), 'warning', 'bi-scissors'],
    ['Kesim (mt)', $f2($t['kesimMt']) . ($t['kesimTahmini'] > 0 ? ' <span class="small text-muted fw-normal">(' . $f2($t['kesimTahmini']) . ' tahmini)</span>' : ''), 'warning', 'bi-rulers'],
    ['Silikon yapılan daire', $f0($t['silikonDaire']), 'success', 'bi-check-circle-fill'],
    ['Silikon (mt)', $f2($t['silikonMt']) . ($t['silikonTahmini'] > 0 ? ' <span class="small text-muted fw-normal">(' . $f2($t['silikonTahmini']) . ' tahmini)</span>' : ''), 'success', 'bi-rulers'],
    ['Silikon / Kesim', $yuzde($t['oran']), 'primary', 'bi-percent'],
    ['Ort. metraj', $f2($ic['ortMetraj']) . ' m', 'info', 'bi-calculator'],
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
            <div class="card-header bg-white small fw-semibold"><i class="bi bi-building me-1"></i>Blok bazında</div>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" style="font-size:.85rem">
                <thead class="table-light"><tr>
                    <th>Blok</th>
                    <th class="text-end">Kesim Yapılan Daire</th><th class="text-end">Kesim (mt)</th>
                    <th class="text-end">Silikon Yapılan Daire</th><th class="text-end">Silikon (mt)</th>
                    <th class="text-end">Silikon / Kesim</th>
                </tr></thead>
                <tbody>
                <?php foreach ($ic['blok'] as $b => $g): ?>
                    <tr>
                        <td class="fw-semibold"><a href="isler.php?blok=<?= urlencode($b) ?>" class="text-decoration-none"><?= h($b) ?></a>
                            <?php if ($g['tahminiSatir']): ?><span class="badge bg-light text-dark border ms-1" title="Metrajı ölçülmemiş satır sayısı — ortalama ile tahmin edildi"><?= (int)$g['tahminiSatir'] ?> tahmini</span><?php endif; ?></td>
                        <td class="text-end"><?= $f0($g['kesimDaire']) ?></td>
                        <td class="text-end"><?= $f2($g['kesimMt']) ?>
                            <?php if ($g['kesimTahmini'] > 0): ?><div class="text-muted" style="font-size:.72rem">~<?= $f2($g['kesimTahmini']) ?> tahmini</div><?php endif; ?></td>
                        <td class="text-end text-success fw-semibold"><?= $f0($g['silikonDaire']) ?></td>
                        <td class="text-end"><?= $f2($g['silikonMt']) ?>
                            <?php if ($g['silikonTahmini'] > 0): ?><div class="text-muted" style="font-size:.72rem">~<?= $f2($g['silikonTahmini']) ?> tahmini</div><?php endif; ?></td>
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

<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>window.ERN_ROOT = '../';</script>
<script src="../assets/js/ern_rapor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ern_rapor.js') ?>"></script>
<script>
const IC = <?= json_encode(['blok'=>$ic['blok'], 'toplam'=>$t, 'ort'=>$ic['ortMetraj'], 'cizelge'=>$secili !== '' ? $secili : 'Tüm çizelgeler'], JSON_UNESCAPED_UNICODE) ?>;
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
    const html = '<p><b>Çizelge:</b> ' + ERN_RAPOR.esc(IC.cizelge) + ' &nbsp; <b>Ortalama metraj (tahmin için):</b> ' + f2(IC.ort) + ' m</p>'
        + '<h2>Blok Bazında İşlem İcmali</h2>'
        + ERN_RAPOR.tbl(['Blok','Kesim Yapılan Daire','Kesim (mt)','Silikon Yapılan Daire','Silikon (mt)','Silikon / Kesim'], icSatirlar())
        + '<p style="font-size:11px;color:#555">Daire sayıları benzersiz blok/daire üzerinden; ölçülmemiş metrajlar ölçülenlerin ortalamasıyla tahmin edilmiştir.</p>';
    ERN_RAPOR.popup({ title: 'PREKAST BLOK BAZINDA İŞLEM İCMALİ', body: html, mode: mode, filename: 'ERN_Prekast_Icmal' });
}
async function icExcel() {
    const wb = await ERN_RAPOR.wb();
    const ws = wb.addWorksheet('İcmal');
    ws.columns = [{width:10},{width:20},{width:14},{width:22},{width:14},{width:16}];
    ERN_RAPOR.title(wb, ws, 'BLOK BAZINDA İŞLEM İCMALİ', 6, IC.cizelge);
    ERN_RAPOR.hdr(ws.addRow(['Blok','Kesim Yapılan Daire','Kesim (mt)','Silikon Yapılan Daire','Silikon (mt)','Silikon / Kesim']));
    bloklar.forEach(b => { const g = IC.blok[b]; ws.addRow([b, g.kesimDaire, +g.kesimMt.toFixed(2), g.silikonDaire, +g.silikonMt.toFixed(2), +(g.oran).toFixed(4)]); });
    const t = IC.toplam; ws.addRow(['TOPLAM', t.kesimDaire, +t.kesimMt.toFixed(2), t.silikonDaire, +t.silikonMt.toFixed(2), +(t.oran).toFixed(4)]).font = { bold: true };
    await ERN_RAPOR.save(wb, 'ERN_Prekast_Icmal_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
