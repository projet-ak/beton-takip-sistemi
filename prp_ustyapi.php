<?php
/**
 * prp_ustyapi.php — PRP BİNA ÜSTYAPI zayiat tablosu (blok seçmeli, görsel düzen)
 *
 * Excel "PRP BİNA ÜSTYAPI" sayfası, İmalat Sayfaları (metraj_takip.php) ile içe
 * aktarılıp `metraj_sayfa` içinde grid (JSON) olarak saklanır. Bu sayfa o grid'i
 * okuyup blok bazında (A_2, B_4, C_1, C_2, D_3, E_BLOK) KOT × İMALAT YERİ tablosu
 * olarak, Excel'deki görünümle (KOT birleşik, KOLON-PERDE ayrı, DÖŞEME grubu
 * birleşik) gösterir.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Bina Üstyapı — Beton Takip Sistemi';

// Blok kolon haritası: [KOT kolonu, İMALAT kolonu, veri başlangıç kolonu]
// veri: base+0 PROJE METRAJI, +1 İLERLEME, +2 SAHADA DÖKÜLEN, +3 PROJEYE GÖRE,
//       +4 ZAİYAT ORANI, +5 SÖZLEŞME ZAYİAT(B), +6 SÖZLEŞME ZAYİATLI MİKTAR, +7 FİİLİ
$BLOKLAR = [
    'A_2_BLOK' => [6, 7, 9],
    'B_4_BLOK' => [6, 7, 18],
    'C_1_BLOK' => [6, 7, 28],
    'C_2_BLOK' => [6, 7, 37],
    'D_3_BLOK' => [6, 7, 46],
    'E_BLOK'   => [55, 56, 57],
];

// Grid'i bul (metraj_sayfa)
$grid = [];
$guncelleme = null;
try {
    $row = $pdo->query("SELECT veri, guncelleme FROM metraj_sayfa WHERE UPPER(ad) LIKE 'PRP BİNA%' OR UPPER(ad) LIKE 'PRP BINA%' ORDER BY id LIMIT 1")->fetch();
    if ($row) { $grid = json_decode($row['veri'], true) ?: []; $guncelleme = $row['guncelleme']; }
} catch (Throwable $e) { $grid = []; }

$aktifBlok = $_GET['blok'] ?? 'A_2_BLOK';
if (!isset($BLOKLAR[$aktifBlok])) $aktifBlok = 'A_2_BLOK';

// ── Seçili bloğu KOT gruplarına ayır ──────────────────────────────────────────
function hucre($grid, $r, $c) { return isset($grid[$r][$c]) ? trim((string)$grid[$r][$c]) : ''; }

$gruplar = [];
if ($grid) {
    [$kc, $ic, $base] = $BLOKLAR[$aktifBlok];
    $curKot = '';
    for ($r = 5; $r < count($grid); $r++) {
        $k = hucre($grid, $r, $kc);
        if ($k !== '') $curKot = $k;
        $im = hucre($grid, $r, $ic);
        $m  = hucre($grid, $r, $base);
        if ($im === '' && $m === '') continue;
        if ($curKot === '') continue;
        $gruplar[$curKot][] = [
            'imalat' => $im,
            'metraj' => hucre($grid, $r, $base),
            'iler'   => hucre($grid, $r, $base+1),
            'sahada' => hucre($grid, $r, $base+2),
            'projeye'=> hucre($grid, $r, $base+3),
            'zoran'  => hucre($grid, $r, $base+4),
            'sozB'   => hucre($grid, $r, $base+5),
            'sozM'   => hucre($grid, $r, $base+6),
            'fiili'  => hucre($grid, $r, $base+7),
        ];
    }
}

// ── Biçimlendiriciler ─────────────────────────────────────────────────────────
function na($v) { $v = trim((string)$v); return ($v === '' || strcasecmp($v,'#N/A')===0 || strcasecmp($v,'#YOK')===0); }
function sayi($v, $dec=2) { if (na($v) || !is_numeric($v)) return ''; return number_format((float)$v, $dec, ',', '.'); }
function yuzde($v) { if (na($v) || !is_numeric($v)) return ''; return rtrim(rtrim(number_format((float)$v*100, 1, ',', '.'), '0'), ',').'%'; }

// Toplam proje metrajı (blok)
$topMetraj = 0.0;
foreach ($gruplar as $g) foreach ($g as $r) if (is_numeric($r['metraj'])) $topMetraj += (float)$r['metraj'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-building-gear text-primary me-2"></i>Bina Üstyapı — Zayiat Takibi
            <span class="badge rounded-pill align-middle ms-1" style="background:var(--ern-gold,#C9A84C);color:#3a2e00;font-size:.6em">Taşeron: PRP İnşaat</span></h4>
        <small class="text-muted">Blok seçin; kot (kat) ve imalat bazında proje metrajı / dökülen / zayiat</small>
    </div>
    <a href="metraj_takip.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-grid-3x3-gap me-1"></i> Tüm İmalat Sayfaları</a>
</div>

<?php if (!$grid): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i> PRP Bina Üstyapı verisi bulunamadı.
    Önce <a href="metraj_takip.php" class="alert-link">İmalat Sayfaları</a> ekranından Excel'i içe aktarın.
</div>
<?php else: ?>

<!-- Blok seçici -->
<div class="d-flex gap-2 flex-wrap mb-3 blok-secici">
    <?php foreach (array_keys($BLOKLAR) as $b): ?>
    <a href="prp_ustyapi.php?blok=<?= urlencode($b) ?>"
       class="btn btn-sm <?= $b===$aktifBlok ? 'btn-primary' : 'btn-outline-primary' ?> px-3">
        <i class="bi bi-building me-1"></i><?= h($b) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- KPI özet -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="prp-kpi"><div class="prp-kpi-ic" style="background:rgba(0,88,78,.1);color:var(--ern)"><i class="bi bi-building-fill"></i></div>
            <div><div class="prp-kpi-val"><?= h($aktifBlok) ?></div><div class="prp-kpi-lbl">Seçili Blok</div></div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="prp-kpi"><div class="prp-kpi-ic" style="background:rgba(0,201,177,.12);color:var(--ern-teal)"><i class="bi bi-rulers"></i></div>
            <div><div class="prp-kpi-val"><?= number_format($topMetraj,1,',','.') ?> <small>m³</small></div><div class="prp-kpi-lbl">Toplam Proje Metrajı</div></div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="prp-kpi"><div class="prp-kpi-ic" style="background:rgba(201,168,76,.14);color:var(--ern-gold-dark)"><i class="bi bi-layers"></i></div>
            <div><div class="prp-kpi-val"><?= count($gruplar) ?></div><div class="prp-kpi-lbl">Kot (Kat) Sayısı</div></div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="prp-kpi"><div class="prp-kpi-ic" style="background:rgba(0,88,78,.1);color:var(--ern)"><i class="bi bi-percent"></i></div>
            <div><div class="prp-kpi-val">%5</div><div class="prp-kpi-lbl">Sözleşme Zayiat Limiti</div></div></div>
    </div>
</div>

<div class="card mb-3 shadow-sm border-0">
    <div class="card-header text-white fw-bold d-flex justify-content-between flex-wrap gap-2" style="background:linear-gradient(90deg,var(--ern),var(--ern-light))">
        <span><i class="bi bi-building me-1"></i><?= h($aktifBlok) ?> — Bina Üstyapı Zayiat Tablosu</span>
        <span class="small fw-normal">Toplam Proje Metrajı: <strong><?= number_format($topMetraj,2,',','.') ?> m³</strong> · <?= count($gruplar) ?> kot</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0 prp-table" style="font-size:.82rem">
            <thead>
                <tr class="text-center align-middle prp-head">
                    <th style="width:70px">KOT</th>
                    <th style="width:120px">İMALAT YERİ</th>
                    <th style="width:90px">PROJE<br>METRAJI</th>
                    <th style="width:70px">İLERLEME</th>
                    <th style="width:110px">SAHADA DÖKÜLEN<br>BETON MİKTARI</th>
                    <th style="width:120px">PROJEYE GÖRE<br>DÖKÜLMESİ GEREKEN (A)</th>
                    <th style="width:80px" class="prp-head-uyari">ZAİYAT<br>ORANI</th>
                    <th style="width:90px">SÖZLEŞMEYE<br>GÖRE ZAYİAT (B)</th>
                    <th style="width:100px">SÖZLEŞMEYE GÖRE<br>ZAYİATLI MİKTAR</th>
                    <th style="width:100px">FİİLİ ZAYİAT<br>MİKTARI (KESİLECEK)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($gruplar as $kot => $satirlar):
                $n = count($satirlar);
                $ilk = $satirlar[0];                       // KOLON-PERDE
                $grup = array_slice($satirlar, 1);         // DÖŞEME + DOLGU/MERDİVEN/PARAPET
                $doseme = $grup[0] ?? null;                // DÖŞEME satırı (grup değerleri)
                $grupN = count($grup);
            ?>
                <!-- KOLON-PERDE satırı -->
                <tr>
                    <td class="text-center fw-bold prp-kot" rowspan="<?= $n ?>"><?= h($kot) ?></td>
                    <td class="fw-semibold"><?= h($ilk['imalat']) ?></td>
                    <td class="text-end font-monospace fw-bold prp-metraj-vurgu"><?= sayi($ilk['metraj']) ?></td>
                    <td class="text-center"><?= yuzde($ilk['iler']) ?></td>
                    <td class="text-end"><?= sayi($ilk['sahada']) ?></td>
                    <td class="text-end"><?= sayi($ilk['projeye']) ?></td>
                    <td class="text-center text-danger"><?= yuzde($ilk['zoran']) ?></td>
                    <td class="text-center"><?= yuzde($ilk['sozB']) ?></td>
                    <td class="text-end"><?= na($ilk['sozM']) ? '' : sayi($ilk['sozM']) ?></td>
                    <td class="text-end"><?= na($ilk['fiili']) ? '0' : sayi($ilk['fiili'],0) ?></td>
                </tr>
                <!-- DÖŞEME grubu: imalat adları ayrı, veri hücreleri birleşik (rowspan) -->
                <?php foreach ($grup as $gi => $gr): ?>
                <tr>
                    <td class="<?= $gr['imalat']==='DÖŞEME'?'fw-semibold':'' ?>"><?= h($gr['imalat']) ?></td>
                    <?php if ($gi === 0): ?>
                        <td class="text-end font-monospace" rowspan="<?= $grupN ?>"><?= sayi($doseme['metraj']) ?></td>
                        <td class="text-center" rowspan="<?= $grupN ?>"><?= yuzde($doseme['iler']) ?></td>
                        <td class="text-end" rowspan="<?= $grupN ?>"><?= sayi($doseme['sahada']) ?></td>
                        <td class="text-end" rowspan="<?= $grupN ?>"><?= sayi($doseme['projeye']) ?></td>
                        <td class="text-center text-danger" rowspan="<?= $grupN ?>"><?= yuzde($doseme['zoran']) ?></td>
                        <td class="text-center" rowspan="<?= $grupN ?>"><?= yuzde($doseme['sozB']) ?></td>
                        <td class="text-end" rowspan="<?= $grupN ?>"><?= na($doseme['sozM']) ? '0,00' : sayi($doseme['sozM']) ?></td>
                        <td class="text-end" rowspan="<?= $grupN ?>"><?= na($doseme['fiili']) ? '0' : sayi($doseme['fiili'],0) ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (!$gruplar): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">Bu blokta veri yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if ($guncelleme): ?>
    <div class="card-footer small text-muted"><i class="bi bi-clock-history me-1"></i>Kaynak: PRP Bina Üstyapı (İmalat Sayfaları) · Güncelleme: <?= h($guncelleme) ?></div>
    <?php endif; ?>
</div>

<div class="alert small border-0" style="background:var(--bt-tint,#eef6f4)">
    <i class="bi bi-info-circle me-1 text-success"></i>
    <span class="prp-metraj-vurgu px-2 rounded">Altın</span> hücre = KOLON-PERDE proje metrajı. DÖŞEME satırındaki metraj, o kotun döşeme+dolgu+merdiven+parapet grubunu kapsar (Excel'deki birleşik hücre).
    <strong>SAHADA DÖKÜLEN</strong> ve <strong>ZAİYAT ORANI</strong> alanları saha verisi girildikçe dolar; <strong>Sözleşmeye göre zayiat</strong> limiti %5.
</div>
<?php endif; ?>

<style>
.blok-secici .btn { font-weight:600; }
.prp-kpi { display:flex; align-items:center; gap:.6rem; background:#fff; border:1px solid var(--bt-border,#e6e9ee); border-radius:12px; padding:.6rem .8rem; height:100%; }
.prp-kpi-ic { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
.prp-kpi-val { font-weight:700; font-size:1rem; line-height:1.1; color:var(--ern,#00584E); }
.prp-kpi-val small { font-size:.65rem; font-weight:500; color:var(--bt-text-muted,#7a8690); }
.prp-kpi-lbl { font-size:.72rem; color:var(--bt-text-muted,#7a8690); }
.prp-table th { vertical-align:middle; text-align:center; font-size:.72rem; line-height:1.15; }
.prp-table td { padding:.3rem .5rem; }
.prp-head th { background:var(--ern,#00584E); color:#fff; border-color:var(--ern-dark,#003D35) !important; }
.prp-head-uyari { background:var(--ern-gold,#C9A84C) !important; color:#3a2e00 !important; }
.prp-kot { background:#eef6f4; color:var(--ern,#00584E); vertical-align:middle; font-size:.95rem; }
.prp-metraj-vurgu { background:#f6eccf !important; color:#6b5411 !important; }
.prp-table tbody tr:hover td:not(.prp-kot):not(.prp-metraj-vurgu) { background:#f1f8f6; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
