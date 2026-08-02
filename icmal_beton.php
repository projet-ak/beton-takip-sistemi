<?php
/**
 * icmal_beton.php — Beton İcmali (ayrı, anlaşılır ekran)
 *
 * Excel "İCMAL" sayfası, Dinamik Excel Aktarımı ile metraj_sayfa'ya kaydedilir.
 * Bu sayfa onu iki anlaşılır bölümde gösterir:
 *  1) Beton İcmali özeti (imalat kalemi → miktar; TOPLAM / KALAN)
 *  2) Tamamlanan İmalatlar (firma bazlı: imalat, güncel metraj, ilerleme %)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Beton İcmali — Beton Takip Sistemi';

$grid = []; $guncelleme = null;
try {
    $row = $pdo->query("SELECT veri, guncelleme FROM metraj_sayfa WHERE UPPER(ad) IN ('İCMAL','ICMAL') ORDER BY id LIMIT 1")->fetch();
    if ($row) { $grid = json_decode($row['veri'], true) ?: []; $guncelleme = $row['guncelleme']; }
} catch (Throwable $e) { $grid = []; }

function ic($grid, $r, $c) { return isset($grid[$r][$c]) ? trim((string)$grid[$r][$c]) : ''; }
function icNum($v) { $v=trim((string)$v); if ($v===''||strcasecmp($v,'#N/A')===0) return null; return is_numeric($v)?(float)$v:null; }
function icFmt($v,$d=2){ $n=icNum($v); return $n===null?'':number_format($n,$d,',','.'); }
function icYuzde($v){ $n=icNum($v); if($n===null)return ''; return number_format($n*100,1,',','.').'%'; }

// ── Sol blok: Beton İcmali özeti (col4 = kalem, col5 = miktar) ─────────────────
$ozet = [];
if ($grid) {
    for ($r = 0; $r < count($grid); $r++) {
        $kalem = ic($grid, $r, 4); $mik = ic($grid, $r, 5);
        if ($kalem === '' || $mik === '') continue;
        if (mb_strtoupper($kalem,'UTF-8') === 'BETON İCMALİ') continue;
        $ozet[] = ['kalem'=>$kalem, 'miktar'=>$mik];
    }
}

// ── Sağ blok: Tamamlanan İmalatlar (firma carry, col11/12/14/15) ──────────────
$imalatlar = [];
if ($grid) {
    $firma = '';
    for ($r = 5; $r < count($grid); $r++) {
        $f = ic($grid, $r, 11); if ($f !== '') $firma = $f;
        $im = ic($grid, $r, 12);
        if ($im === '') continue;
        $imalatlar[] = [
            'firma'   => $firma,
            'imalat'  => $im,
            'metraj'  => ic($grid, $r, 14),
            'ilerleme'=> ic($grid, $r, 15),
        ];
    }
}
// firma bazlı grupla
$firmaGrup = [];
foreach ($imalatlar as $im) { $firmaGrup[$im['firma']][] = $im; }

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-clipboard-data text-primary me-2"></i>Beton İcmali</h4>
        <small class="text-muted">Beton icmal özeti ve firma bazlı tamamlanan imalatlar (Excel "İCMAL" sayfası)</small>
    </div>
    <a href="import.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Dinamik Excel Aktarımı</a>
</div>

<?php if (!$grid): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i> İcmal verisi bulunamadı.
    Önce <a href="import.php" class="alert-link">Dinamik Excel Aktarımı</a> ile Excel'i yükleyin (İcmal sayfası otomatik kaydedilir).
</div>
<?php else: ?>

<div class="row g-3">
    <!-- Beton İcmali özeti -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-primary text-white fw-semibold"><i class="bi bi-list-check me-1"></i> Beton İcmali (Özet)</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>İmalat Kalemi</th><th class="text-end">Miktar (m³)</th></tr></thead>
                    <tbody>
                    <?php foreach ($ozet as $o):
                        $isTotal = preg_match('/TOPLAM|KALAN/i', $o['kalem']);
                        $val = icNum($o['miktar']);
                    ?>
                        <tr class="<?= $isTotal ? 'table-warning fw-bold' : '' ?>">
                            <td><?= h($o['kalem']) ?></td>
                            <td class="text-end font-monospace <?= ($val!==null && $val<0)?'text-danger':'' ?>"><?= $val===null ? h($o['miktar']) : number_format($val,2,',','.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$ozet): ?><tr><td colspan="2" class="text-center text-muted py-3">Özet verisi yok.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>

    <!-- Tamamlanan İmalatlar (firma bazlı) -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-buildings me-1"></i> Tamamlanan İmalatlar (Firma Bazlı)</div>
            <div class="card-body p-0"><div class="table-responsive" style="max-height:70vh">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light" style="position:sticky;top:0"><tr>
                        <th>Firma</th><th>İmalat</th><th class="text-end">Güncel Metraj</th><th class="text-end">İlerleme</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($firmaGrup as $firma => $satirlar): $ilk = true; foreach ($satirlar as $im):
                        $ilerN = icNum($im['ilerleme']);
                        $bar = $ilerN !== null ? max(0,min(100, round($ilerN*100))) : null;
                    ?>
                        <tr>
                            <?php if ($ilk): ?>
                                <td rowspan="<?= count($satirlar) ?>" class="fw-semibold align-middle hucre-firma"><?= h($firma) ?></td>
                            <?php endif; ?>
                            <td><?= h($im['imalat']) ?></td>
                            <td class="text-end font-monospace"><?= icFmt($im['metraj']) ?></td>
                            <td class="text-end" style="min-width:120px">
                                <?php if ($bar !== null): ?>
                                    <div class="d-flex align-items-center gap-2 justify-content-end">
                                        <div class="progress flex-grow-1" style="height:8px;max-width:90px"><div class="progress-bar bg-success" style="width:<?= $bar ?>%"></div></div>
                                        <span class="small"><?= icYuzde($im['ilerleme']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php $ilk = false; endforeach; endforeach; ?>
                    <?php if (!$firmaGrup): ?><tr><td colspan="4" class="text-center text-muted py-3">İmalat verisi yok.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
</div>

<?php if ($guncelleme): ?>
<div class="text-muted small mt-2"><i class="bi bi-clock-history me-1"></i>Kaynak: Excel "İCMAL" sayfası · Güncelleme: <?= h($guncelleme) ?></div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
