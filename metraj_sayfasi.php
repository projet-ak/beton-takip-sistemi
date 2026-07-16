<?php
/**
 * metraj_sayfasi.php — Metraj (blok/bölüm/kot bazlı beton metrajı)
 * Excel "METRAJ" sayfasını (BLOK/BÖLÜM/KOT/DÖŞEME/KOLON-PERDE/TEMEL/GENEL TOPLAM)
 * anlaşılır tablo olarak gösterir. Veri Dinamik Excel Aktarımı ile gelir.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Metraj — Beton Takip Sistemi';

$st = $pdo->prepare("SELECT veri FROM metraj_sayfa WHERE UPPER(TRIM(ad)) = 'METRAJ' ORDER BY id LIMIT 1");
$st->execute();
$grid = ($v = $st->fetchColumn()) ? (json_decode($v, true) ?: []) : [];

function mhc($g,$r,$c){ return isset($g[$r][$c]) ? trim((string)$g[$r][$c]) : ''; }
function mnum($v,$d=2){ $v=trim((string)$v); if($v===''||strncmp($v,'#',1)===0) return ''; return is_numeric($v)?number_format((float)$v,$d,',','.'):h($v); }

// Veri satırlarını blok bazında topla (başlık R2-R3, veri R4+)
$satirlar = []; $blok = ''; $topGenel = 0;
for ($r = 4; $r < count($grid); $r++) {
    $b = mhc($grid,$r,1); if ($b !== '') $blok = $b;
    $bolum = mhc($grid,$r,2); $kot = mhc($grid,$r,3);
    $dos = mhc($grid,$r,4); $kp = mhc($grid,$r,5); $tem = mhc($grid,$r,6); $gt = mhc($grid,$r,7);
    if ($bolum==='' && $kot==='' && $dos==='' && $kp==='' && $tem==='' && $gt==='') continue;
    if (is_numeric($gt)) $topGenel += (float)$gt;
    $satirlar[] = compact('blok','bolum','kot','dos','kp','tem','gt');
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-rulers text-primary me-2"></i>Metraj</h4>
        <small class="text-muted">Blok / bölüm / kot bazında beton metrajı (döşeme · kolon-perde · temel)</small>
    </div>
    <a href="import.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Dinamik Excel Aktarımı</a>
</div>

<?php if (!$satirlar): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> Metraj verisi yok. Önce <a href="import.php" class="alert-link">Dinamik Excel Aktarımı</a> ile Excel'i yükleyin.</div>
<?php else: ?>
<div class="mb-2"><span class="badge bg-primary">Genel Toplam: <?= number_format($topGenel,2,',','.') ?> m³</span></div>
<div class="card border-0 shadow-sm">
<div class="table-responsive" style="max-height:74vh">
    <table class="table table-sm table-hover table-bordered align-middle mb-0" style="font-size:.82rem">
        <thead class="table-light" style="position:sticky;top:0;z-index:2">
            <tr>
                <th>Blok</th><th>Bölüm</th><th>Kot</th>
                <th class="text-end">Döşeme</th><th class="text-end">Kolon-Perde</th><th class="text-end">Temel</th>
                <th class="text-end">Genel Toplam</th>
            </tr>
        </thead>
        <tbody>
        <?php $oncekiBlok = null; foreach ($satirlar as $s):
            $yeniBlok = ($s['blok'] !== $oncekiBlok); $oncekiBlok = $s['blok'];
        ?>
            <?php if ($yeniBlok): ?>
            <tr><td colspan="7" style="background:var(--ern);color:#fff;font-weight:700"><i class="bi bi-building me-1"></i><?= h($s['blok']) ?></td></tr>
            <?php endif; ?>
            <tr>
                <td class="text-muted small"><?= h($s['blok']) ?></td>
                <td class="fw-semibold"><?= h($s['bolum']) ?></td>
                <td class="font-monospace"><?= h($s['kot']) ?></td>
                <td class="text-end"><?= mnum($s['dos']) ?></td>
                <td class="text-end"><?= mnum($s['kp']) ?></td>
                <td class="text-end"><?= mnum($s['tem']) ?></td>
                <td class="text-end fw-semibold"><?= mnum($s['gt']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
