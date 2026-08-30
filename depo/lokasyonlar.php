<?php
/**
 * lokasyonlar.php — "Hangi rafta/alanda ne var?" + "Sahaya nereye ne verildi?"
 *   Bölüm 1: stok kartları BULUNDUĞU ALAN bazında gruplanır (raf/konteyner/depo görünümü).
 *   Bölüm 2: çıkış hareketleri lokasyon bazında toplanır (A PARSEL, B PARSEL, YENİ OFİSLER…)
 *            — sahada duran/kullanılan malzemenin nereye gittiğinin özetidir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoDepo->query("SELECT 1 FROM depo_kalemler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_depo.php'); }

$fmt  = fn($n) => number_format((float)$n, 2, ',', '.');
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');

// ── Bölüm 1: alan bazlı stok ────────────────────────────────────────────────
$ara = trim((string)($_GET['ara'] ?? ''));
$alanlar = [];   // alan → ['kalem'=>n, 'stok'=>x, 'tutar'=>y, 'liste'=>[]]
foreach ($pdoDepo->query("SELECT * FROM depo_kalemler ORDER BY ad") as $k) {
    $stok = (float)$k['sayim'] + (float)$k['gelen'] - (float)$k['giden'];
    if (abs($stok) < 0.001) continue;                       // tükenenler raf görünümünü şişirmesin
    if ($ara !== '' && mb_stripos((string)$k['ad'] . ' ' . (string)$k['ozellik'] . ' ' . (string)$k['alan'], $ara, 0, 'UTF-8') === false) continue;
    $alan = trim((string)($k['alan'] ?? '')) ?: 'Alan girilmemiş';
    $alanlar[$alan]['kalem'] = ($alanlar[$alan]['kalem'] ?? 0) + 1;
    $alanlar[$alan]['stok']  = ($alanlar[$alan]['stok'] ?? 0) + $stok;
    $alanlar[$alan]['tutar'] = ($alanlar[$alan]['tutar'] ?? 0) + $stok * (float)$k['birim_fiyat'];
    $alanlar[$alan]['liste'][] = $k + ['stok_h' => $stok];
}
uksort($alanlar, fn($a, $b) => [$a === 'Alan girilmemiş', mb_strtoupper($a, 'UTF-8')] <=> [$b === 'Alan girilmemiş', mb_strtoupper($b, 'UTF-8')]);

// ── Bölüm 2: sahaya çıkışların lokasyon özeti ───────────────────────────────
$sahalar = [];
try {
    foreach ($pdoDepo->query("SELECT COALESCE(NULLIF(TRIM(lokasyon),''),'Lokasyon girilmemiş') alan,
                                     COUNT(*) adet, COALESCE(SUM(miktar),0) miktar,
                                     COUNT(DISTINCT malzeme) cesit, MAX(tarih) son
                              FROM depo_hareketler WHERE tur='cikis'
                              GROUP BY alan ORDER BY adet DESC") as $r) $sahalar[] = $r;
} catch (Throwable $e) {}

$pageTitle = 'Lokasyonlar — Depo';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div><h4 class="mb-0"><i class="bi bi-geo-alt text-primary me-2"></i>Lokasyonlar</h4>
        <small class="text-muted">Hangi rafta/alanda ne var · sahaya nereye ne verildi</small></div>
    <form class="d-flex gap-1">
        <input name="ara" value="<?= h($ara) ?>" class="form-control form-control-sm" style="width:230px" placeholder="Malzeme / alan ara">
        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($ara !== ''): ?><a href="lokasyonlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a><?php endif; ?>
    </form>
</div>

<h6 class="text-muted mt-2"><i class="bi bi-box-seam me-1"></i>Depoda duranlar — alan/raf bazında (<?= count($alanlar) ?> alan)</h6>
<div class="accordion mb-4" id="alanAcc">
<?php $ai = 0; foreach ($alanlar as $alan => $a): $ai++; ?>
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#al<?= $ai ?>">
                <i class="bi bi-geo-alt-fill text-primary me-2"></i>
                <strong><?= h($alan) ?></strong>
                <span class="ms-2 text-muted small"><?= (int)$a['kalem'] ?> kalem · stok <?= $fmt0($a['stok']) ?><?= $a['tutar'] > 0 ? ' · ' . $fmt($a['tutar']) . ' ₺' : '' ?></span>
            </button>
        </h2>
        <div id="al<?= $ai ?>" class="accordion-collapse collapse" data-bs-parent="#alanAcc">
            <div class="accordion-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                    <thead class="table-light"><tr>
                        <th>Kategori</th><th>Malzeme</th><th>Özellik</th><th class="text-end">Stok</th><th>Birim</th><th>Zimmet</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($a['liste'] as $k): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?= h(dp_katAd($k['kategori'])) ?></span></td>
                            <td class="fw-semibold"><?= h($k['ad']) ?></td>
                            <td class="small text-muted"><?= h((string)$k['ozellik']) ?></td>
                            <td class="text-end fw-bold"><?= $fmt($k['stok_h']) ?></td>
                            <td class="small"><?= h((string)$k['birim']) ?></td>
                            <td class="small"><?= h($k['alan_kisi'] ?: '—') ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-secondary py-0" href="malzeme_ekstre.php?m=<?= urlencode($k['ad']) ?>" title="Ekstre"><i class="bi bi-journal-text"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$alanlar): ?><div class="alert alert-light border">Eşleşen stok yok.</div><?php endif; ?>
</div>

<h6 class="text-muted"><i class="bi bi-truck me-1"></i>Sahaya verilenler — çıkışların lokasyon özeti</h6>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover mb-0" style="font-size:.84rem">
    <thead class="table-light"><tr>
        <th>Lokasyon</th><th class="text-end">Çıkış Kaydı</th><th class="text-end">Toplam Miktar</th>
        <th class="text-end">Farklı Malzeme</th><th>Son Çıkış</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($sahalar as $r): ?>
        <tr>
            <td class="fw-semibold"><?= h($r['alan']) ?></td>
            <td class="text-end"><?= $fmt0($r['adet']) ?></td>
            <td class="text-end text-danger fw-semibold"><?= $fmt($r['miktar']) ?></td>
            <td class="text-end"><?= $fmt0($r['cesit']) ?></td>
            <td class="text-nowrap"><?= h(format_date($r['son'])) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary py-0"
                href="hareketler.php?tur=cikis&ara=<?= urlencode($r['alan'] === 'Lokasyon girilmemiş' ? '' : $r['alan']) ?>">hareketleri gör</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$sahalar): ?><tr><td colspan="6" class="text-center text-muted py-4">Çıkış kaydı yok.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div></div>
<div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>
    Depoda duranlar stok kartlarının "Bulunduğu Alan" bilgisinden, sahaya verilenler çıkış hareketlerinin lokasyonundan gelir.
    Alan bilgisi Excel'de boşsa "girilmemiş" altında toplanır.</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
