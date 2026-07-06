<?php
/**
 * metraj_takip.php — İmalat / Metraj / Zayiat Sayfaları Görüntüleyici
 *
 * Excel'deki imalat sayfalarını (PRP BİNA ÜSTYAPI, İKSA KAZIK, TEMEL ALTI KAZIK,
 * İSTİNAT DENER, PRP TEMEL, KAZIK, İSTİNAT DUVAR, METRAJ, MOBİLİZASYON, İCMAL, KOT …)
 * sisteme aktarıp sekmeler halinde gösterir. Her sayfa JSON grid olarak saklanır;
 * dosya/veri tabanı şişmesin diye yalnız hücre değerleri tutulur.
 *
 * Tam yenileme mantığı: her içe aktarımda o dosyadaki sayfalar birebir yeniden yazılır.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'İmalat Sayfaları — Beton Takip Sistemi';

// ── Tablo (runtime) ───────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS metraj_sayfa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad VARCHAR(150) NOT NULL,
    sira INT NOT NULL DEFAULT 0,
    satir_sayisi INT NOT NULL DEFAULT 0,
    kolon_sayisi INT NOT NULL DEFAULT 0,
    veri LONGTEXT NOT NULL,
    guncelleme TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ad (ad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$isAdmin = is_admin();

// ── Sil ───────────────────────────────────────────────────────────────────────
if ($isAdmin && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $pdo->prepare("DELETE FROM metraj_sayfa WHERE id = ?")->execute([(int)$_GET['sil']]);
    flash('success', 'Sayfa kaldırıldı.');
    redirect('metraj_takip.php');
}

// İçe aktarma artık Araçlar → Dinamik Excel Aktarımı (import.php) üzerinden yapılır.
// Bu sayfa yalnızca görüntüler. İcmal ve KOT ayrı ekranlarda olduğundan gizlenir.
$gizli = ['İCMAL', 'ICMAL', 'KOT'];
$ph = implode(',', array_fill(0, count($gizli), '?'));
$stSayfa = $pdo->prepare("SELECT id, ad, sira, satir_sayisi, kolon_sayisi, guncelleme
    FROM metraj_sayfa WHERE UPPER(ad) NOT IN ($ph) ORDER BY sira, ad");
$stSayfa->execute($gizli);
$sayfalar = $stSayfa->fetchAll();

/** Bir hücreyi görüntüle: sayıysa Türkçe formatla, #N/A soluk, metin aynen */
function huc(string $v): string {
    $t = trim($v);
    if ($t === '') return '';
    if (strcasecmp($t, '#N/A') === 0 || strcasecmp($t, '#YOK') === 0) return '<span class="text-muted opacity-50">—</span>';
    // sayı mı? (nokta ondalık; binlik yok)
    if (preg_match('/^[+\-]?\d+(\.\d+)?$/', $t)) {
        $f = (float)$t;
        $dec = (floor($f) == $f) ? 0 : (abs($f) < 1 ? 3 : 2);
        return h(number_format($f, $dec, ',', '.'));
    }
    return h($t);
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-grid-3x3-gap text-primary me-2"></i>İmalat / Metraj Sayfaları</h4>
        <small class="text-muted">Excel'deki imalat & zayiat sayfaları (PRP Bina Üstyapı, İksa Kazık, Temel Altı Kazık, İstinat, Metraj …) — sekmeden görüntüleyin</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="prp_ustyapi.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-building-gear me-1"></i> PRP Bina Üstyapı</a>
        <a href="icmal_beton.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-clipboard-data me-1"></i> İcmal</a>
        <?php if ($isAdmin): ?>
        <a href="import.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Dinamik Excel Aktarımı</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="alert alert-light border small">
    <i class="bi bi-info-circle me-1"></i>
    İmalat/metraj/zayiat sayfaları artık <strong>Araçlar → <a href="import.php" class="alert-link">Dinamik Excel Aktarımı</a></strong>'ndan
    Excel yüklediğinizde <strong>otomatik</strong> güncellenir (ayrıca yükleme yapmanıza gerek yok). Bu ekran yalnızca görüntülemedir.
    <strong>KOT</strong> ve <strong>İcmal</strong> ayrı ekranlarda olduğundan burada gösterilmez.
</div>

<?php if (!$sayfalar): ?>
<div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
    Henüz imalat sayfası yok.
    <?php if ($isAdmin): ?><br><strong>Araçlar → <a href="import.php">Dinamik Excel Aktarımı</a></strong>'ndan Excel yükleyin; PRP Bina Üstyapı, İksa Kazık, Temel Altı Kazık ve diğer imalat sayfaları otomatik gelir.<?php endif; ?>
</div></div>
<?php else: ?>

<ul class="nav nav-tabs flex-nowrap overflow-auto pb-1" id="sayfaTab" role="tablist" style="white-space:nowrap">
    <?php foreach ($sayfalar as $k => $s): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $k===0?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab<?= (int)$s['id'] ?>" type="button" role="tab">
            <i class="bi bi-file-earmark-ruled me-1"></i><?= h($s['ad']) ?>
            <span class="badge rounded-pill ms-1" style="background:var(--bt-tint,#e9f3f0);color:var(--ern,#00584E)"><?= (int)$s['satir_sayisi'] ?>×<?= (int)$s['kolon_sayisi'] ?></span>
        </button>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content">
    <?php foreach ($sayfalar as $k => $s):
        $grid = json_decode($pdo->query("SELECT veri FROM metraj_sayfa WHERE id=".(int)$s['id'])->fetchColumn() ?: '[]', true) ?: [];
        // Başlık satırlarını tespit et: baştan itibaren, sayı içermeyen (metin) satırlar
        $baslikSon = -1;
        foreach ($grid as $ri => $row) {
            if ($ri > 6) break;
            $dolu = 0; $sayi = 0;
            foreach ($row as $c) { if (trim($c)!=='') { $dolu++; if (preg_match('/^[+\-]?\d+([.,]\d+)?$/', trim($c))) $sayi++; } }
            if ($dolu > 0 && $sayi === 0) $baslikSon = $ri;
        }
    ?>
    <div class="tab-pane fade <?= $k===0?'show active':'' ?>" id="tab<?= (int)$s['id'] ?>" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-top-0">
            <div class="card-header text-white d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:linear-gradient(90deg,var(--ern),var(--ern-light))">
                <span class="fw-bold"><i class="bi bi-grid-3x3-gap me-1"></i><?= h($s['ad']) ?></span>
                <span class="small d-flex align-items-center gap-3">
                    <span><i class="bi bi-clock-history me-1"></i><?= h($s['guncelleme']) ?> · <?= (int)$s['satir_sayisi'] ?>×<?= (int)$s['kolon_sayisi'] ?></span>
                    <?php if ($isAdmin): ?>
                    <a href="metraj_takip.php?sil=<?= (int)$s['id'] ?>" class="btn btn-xs btn-light" onclick="return confirm('<?= h($s['ad']) ?> sayfası kaldırılsın mı?')"><i class="bi bi-trash me-1"></i>Kaldır</a>
                    <?php endif; ?>
                </span>
            </div>
            <div class="table-responsive" style="max-height:72vh">
                <table class="table table-sm table-bordered mb-0 metraj-grid">
                    <tbody>
                    <?php foreach ($grid as $ri => $row):
                        $baslikGibi = ($ri <= $baslikSon);
                        $zebra = (!$baslikGibi && $ri % 2 === 0);
                    ?>
                        <tr class="<?= $baslikGibi ? 'mg-head' : ($zebra ? 'mg-zebra' : '') ?>">
                            <?php foreach ($row as $ci => $c):
                                $t = trim($c);
                                $isNum = preg_match('/^[+\-]?\d+(\.\d+)?$/', $t);
                                $cls = $baslikGibi ? 'mg-head-c' : ($isNum ? 'text-end font-monospace' : '');
                                if (!$baslikGibi && $t==='') $cls .= ' mg-bos';
                                if ($ci === 0 && !$baslikGibi) $cls .= ' mg-ilk';
                            ?>
                                <td class="<?= $cls ?>"><?= huc($c) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
#sayfaTab .nav-link { font-size:.82rem; padding:.45rem .8rem; }
.metraj-grid { font-size:.78rem; width:auto; }
.metraj-grid td { white-space:nowrap; padding:.22rem .55rem; max-width:230px; overflow:hidden; text-overflow:ellipsis; border-color:#eef1f0; }
.metraj-grid tbody tr.mg-head td { background:var(--ern,#00584E); color:#fff; font-weight:600; text-align:center; border-color:var(--ern-dark,#003D35); position:sticky; top:0; z-index:2; }
.metraj-grid tbody tr.mg-zebra td { background:#f7faf9; }
.metraj-grid td.mg-bos { background:#fbfcfc; }
.metraj-grid td.mg-ilk { position:sticky; left:0; background:#eef6f4; font-weight:600; color:var(--ern,#00584E); z-index:1; }
.metraj-grid tbody tr:not(.mg-head):hover td { background:#eaf5f2; }
.metraj-grid tbody tr:not(.mg-head):hover td.mg-ilk { background:#dcefea; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
