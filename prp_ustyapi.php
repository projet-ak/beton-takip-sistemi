<?php
/**
 * prp_ustyapi.php — PRP BİNA ÜSTYAPI zayiat tablosu (blok seçmeli, görsel düzen)
 *
 * Excel "PRP BİNA ÜSTYAPI" sayfası, Dinamik Excel Aktarımı (import.php) ile içe
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

// ── CANLI sahada dökülen: gerçek irsaliyelerden (blok+kot+imalat) ─────────────
// imalat_gruplari.ad = KOLON-PERDE/DÖŞEME/... (Excel "İmalat Ana Grup"); blok/kot eşleşir.
function pNormBlok($s){ return preg_replace('/[\s_\-]/u','', mb_strtoupper(trim((string)$s),'UTF-8')); }
function pNormKot($s){ $s=str_replace(['+',' '],'',trim((string)$s)); $s=str_replace(',','.',$s); return is_numeric($s)?round((float)$s,2):null; }
$SOZ_LIMIT = 0.05;                                  // Bina üstyapı sözleşme zayiat limiti %5
$DOSEME_SET = ['DÖŞEME','DOSEME','DOLGU','MERDİVEN','MERDIVEN','PARAPET','KİRİŞ','KIRIS'];
$dokum = [];
try {
    $q = $pdo->query("
        SELECT b.ad blok, k.kot_degeri kot, ig.ad imalat,
               SUM(CASE WHEN i.tip='alis' THEN i.miktar ELSE -i.miktar END) m3
        FROM irsaliyeler i
        JOIN bloklar b        ON b.id = i.blok_id
        JOIN kotlar k         ON k.id = i.kot_id
        JOIN imalat_gruplari ig ON ig.id = i.imalat_grup_id
        WHERE i.durum <> 'reddedildi'
        GROUP BY b.ad, k.kot_degeri, ig.ad");
    foreach ($q as $r) {
        $nb = pNormBlok($r['blok']); $nk = pNormKot($r['kot']);
        if ($nk === null) continue;
        $im = mb_strtoupper(trim($r['imalat']),'UTF-8');
        $dokum[$nb][(string)$nk][$im] = ($dokum[$nb][(string)$nk][$im] ?? 0) + (float)$r['m3'];
    }
} catch (Throwable $e) { $dokum = []; }

/** Bir blok+kot+imalat(set) için canlı sahada dökülen (m³) */
function dokumBul(array $dokum, string $blok, $kotFloat, $imalatSet): float {
    $nb = pNormBlok($blok); $nk = (string)$kotFloat;
    if (!isset($dokum[$nb][$nk])) return 0.0;
    $t = 0.0;
    foreach ((array)$imalatSet as $im) { $im = mb_strtoupper($im,'UTF-8'); if (isset($dokum[$nb][$nk][$im])) $t += $dokum[$nb][$nk][$im]; }
    return $t;
}

// ── Biçimlendiriciler ─────────────────────────────────────────────────────────
function na($v) { $v = trim((string)$v); return ($v === '' || strcasecmp($v,'#N/A')===0 || strcasecmp($v,'#YOK')===0); }
function sayi($v, $dec=2) { if (na($v) || !is_numeric($v)) return ''; return number_format((float)$v, $dec, ',', '.'); }
function yuzde($v) { if (na($v) || !is_numeric($v)) return ''; return rtrim(rtrim(number_format((float)$v*100, 1, ',', '.'), '0'), ',').'%'; }

/** Canlı zayiat hücreleri: Sahada / Projeye Göre(A) / Zayiat Oranı / Sözl.%5 / Sözl.Miktar / Fiili
 *  $rs = rowspan. Aşımda oran kırmızı, altında yeşil. */
function zayiatHucreler(array $s, int $rs, float $limit): string {
    $rsAttr = $rs > 1 ? ' rowspan="'.$rs.'"' : '';
    $sahada = $s['canli_sahada'] ?? null;
    $A      = $s['canli_A'] ?? null;
    $oran   = $s['canli_oran'] ?? null;
    $asim   = !empty($s['canli_asim']);
    $fiili  = $s['canli_fiili'] ?? 0;
    $sozM   = ($A !== null) ? $A * $limit : null;
    $oranTd = '';
    if ($oran === null) $oranTd = '<span class="text-muted">—</span>';
    else {
        $cls = $asim ? 'text-danger fw-bold' : (($sahada>0)?'text-success':'text-muted');
        $oranTd = '<span class="'.$cls.'">'.number_format($oran*100,1,',','.').'%'.($asim?' <i class="bi bi-exclamation-triangle-fill"></i>':'').'</span>';
    }
    $out  = '<td class="text-end'.($sahada>0?' fw-semibold':'').'"'.$rsAttr.'>'.($sahada!==null?number_format($sahada,2,',','.'):'').'</td>';
    $out .= '<td class="text-end"'.$rsAttr.'>'.($A!==null?number_format($A,2,',','.'):'').'</td>';
    $out .= '<td class="text-center"'.$rsAttr.'>'.$oranTd.'</td>';
    $out .= '<td class="text-center"'.$rsAttr.'>%'.rtrim(rtrim(number_format($limit*100,1,',','.'),'0'),',').'</td>';
    $out .= '<td class="text-end"'.$rsAttr.'>'.($sozM!==null?number_format($sozM,2,',','.'):'0,00').'</td>';
    $out .= '<td class="text-end'.($fiili>0?' text-danger fw-bold':'').'"'.$rsAttr.'>'.number_format($fiili,2,',','.').'</td>';
    return $out;
}

// ── Her kot grubunu canlı sahada dökülen + zayiat ile zenginleştir ────────────
$topMetraj = 0.0; $topSahada = 0.0; $asimSay = 0;
foreach ($gruplar as $kot => &$satirlar) {
    $kf = pNormKot($kot);
    foreach ($satirlar as $idx => &$s) {
        if (is_numeric($s['metraj'])) $topMetraj += (float)$s['metraj'];
        $imU = mb_strtoupper($s['imalat'],'UTF-8');
        // KOLON-PERDE kendi imalatı; DÖŞEME satırı döşeme grubunu (döşeme+dolgu+merdiven+parapet) kapsar
        if ($kf !== null && ($imU === 'KOLON-PERDE' || $imU === 'DÖŞEME' || $imU === 'DOSEME')) {
            $set = ($imU === 'KOLON-PERDE') ? ['KOLON-PERDE'] : $GLOBALS['DOSEME_SET'];
            $sahada = dokumBul($dokum, $aktifBlok, $kf, $set);
            $metraj = is_numeric($s['metraj']) ? (float)$s['metraj'] : 0;
            $iler   = is_numeric($s['iler']) ? (float)$s['iler'] : ($metraj>0?1:0);
            $A      = $metraj * $iler;                       // projeye göre dökülmesi gereken
            $s['canli_sahada'] = $sahada;
            $s['canli_A']      = $A;
            $s['canli_oran']   = ($A > 0) ? ($sahada - $A) / $A : null;
            $s['canli_asim']   = ($s['canli_oran'] !== null && $s['canli_oran'] > $GLOBALS['SOZ_LIMIT']);
            $s['canli_fiili']  = $s['canli_asim'] ? max(0, $sahada - $A * (1 + $GLOBALS['SOZ_LIMIT'])) : 0.0;
            $topSahada += $sahada;
            if ($s['canli_asim']) $asimSay++;
        }
    }
    unset($s);
}
unset($satirlar);

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-building-gear text-primary me-2"></i>Bina Üstyapı — Zayiat Takibi
            <span class="badge rounded-pill align-middle ms-1" style="background:var(--ern-gold,#C9A84C);color:#3a2e00;font-size:.6em">Taşeron: PRP İnşaat</span></h4>
        <small class="text-muted">Blok seçin; kot (kat) ve imalat bazında proje metrajı / dökülen / zayiat</small>
    </div>
    <a href="temel_kazik.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cone-striped me-1"></i> Temel & Kazık</a>
</div>

<?php if (!$grid): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i> PRP Bina Üstyapı verisi bulunamadı.
    Önce <a href="import.php" class="alert-link">Dinamik Excel Aktarımı</a> ile Excel'i yükleyin.
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
        <div class="prp-kpi"><div class="prp-kpi-ic" style="background:rgba(0,201,177,.12);color:var(--ern-teal)"><i class="bi bi-droplet-half"></i></div>
            <div><div class="prp-kpi-val"><?= number_format($topSahada,1,',','.') ?> <small>m³</small></div><div class="prp-kpi-lbl">Sahada Dökülen (canlı)</div></div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="prp-kpi"><div class="prp-kpi-ic" style="background:<?= $asimSay?'rgba(224,84,84,.14)':'rgba(0,88,78,.1)' ?>;color:<?= $asimSay?'#c0392b':'var(--ern)' ?>"><i class="bi bi-<?= $asimSay?'exclamation-triangle-fill':'check-circle' ?>"></i></div>
            <div><div class="prp-kpi-val" style="<?= $asimSay?'color:#c0392b':'' ?>"><?= (int)$asimSay ?></div><div class="prp-kpi-lbl">Limit Aşımı (%5)</div></div></div>
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
                <tr class="<?= !empty($ilk['canli_asim'])?'prp-asim':'' ?>">
                    <td class="text-center fw-bold prp-kot" rowspan="<?= $n ?>"><?= h($kot) ?></td>
                    <td class="fw-semibold"><?= h($ilk['imalat']) ?></td>
                    <td class="text-end font-monospace fw-bold prp-metraj-vurgu"><?= sayi($ilk['metraj']) ?></td>
                    <td class="text-center"><?= yuzde($ilk['iler']) ?></td>
                    <?= zayiatHucreler($ilk, 1, $SOZ_LIMIT) ?>
                </tr>
                <!-- DÖŞEME grubu: imalat adları ayrı, veri hücreleri birleşik (rowspan) -->
                <?php foreach ($grup as $gi => $gr): ?>
                <tr class="<?= ($gi===0 && !empty($doseme['canli_asim']))?'prp-asim':'' ?>">
                    <td class="<?= $gr['imalat']==='DÖŞEME'?'fw-semibold':'' ?>"><?= h($gr['imalat']) ?></td>
                    <?php if ($gi === 0): ?>
                        <td class="text-end font-monospace" rowspan="<?= $grupN ?>"><?= sayi($doseme['metraj']) ?></td>
                        <td class="text-center" rowspan="<?= $grupN ?>"><?= yuzde($doseme['iler']) ?></td>
                        <?= zayiatHucreler($doseme, $grupN, $SOZ_LIMIT) ?>
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
    <i class="bi bi-broadcast me-1 text-success"></i>
    <strong>CANLI hesap:</strong> <strong>SAHADA DÖKÜLEN</strong> gerçek irsaliyelerden (blok + kot + imalat) otomatik toplanır; <strong>ZAİYAT ORANI</strong> = (Sahada − Projeye Göre) ÷ Projeye Göre olarak anlık hesaplanır.
    Oran <strong>%5</strong> limitini aşarsa satır <span class="badge" style="background:#f8d7da;color:#842029">kırmızı</span> işaretlenir ve <strong>Fiili Zayiat (kesilecek)</strong> hesaplanır.
    <span class="prp-metraj-vurgu px-2 rounded">Altın</span> hücre = KOLON-PERDE proje metrajı; DÖŞEME satırı döşeme+dolgu+merdiven+parapet grubunu kapsar.
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
.prp-table tbody tr.prp-asim td:not(.prp-kot):not(.prp-metraj-vurgu) { background:#fbe9ea !important; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
