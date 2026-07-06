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

// Bu sayfalar RAW veri olduğundan (zaten sistemde) içe aktarmadan hariç tutulur
$haric = ['SAYFA1', 'VERİ', 'VERI'];

// ── Sil ───────────────────────────────────────────────────────────────────────
if ($isAdmin && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $pdo->prepare("DELETE FROM metraj_sayfa WHERE id = ?")->execute([(int)$_GET['sil']]);
    flash('success', 'Sayfa kaldırıldı.');
    redirect('metraj_takip.php');
}

// ── İçe aktar (tüm imalat sayfaları) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'yukle' && !empty($_FILES['dosya']['tmp_name'])) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (!($x = \Shuchkin\SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) {
        flash('error', 'Excel okunamadı: ' . \Shuchkin\SimpleXLSX::parseError());
        redirect('metraj_takip.php');
    }
    $ins = $pdo->prepare("INSERT INTO metraj_sayfa (ad, sira, satir_sayisi, kolon_sayisi, veri)
                          VALUES (?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE sira=VALUES(sira), satir_sayisi=VALUES(satir_sayisi),
                          kolon_sayisi=VALUES(kolon_sayisi), veri=VALUES(veri)");
    $aktarilan = 0; $atlanan = [];
    foreach ($x->sheetNames() as $i => $ad) {
        $adT = trim($ad);
        $adU = mb_strtoupper($adT, 'UTF-8');
        if (in_array($adU, $haric, true)) { $atlanan[] = $adT; continue; }
        $rows = $x->rows($i, 2000);
        // Sondaki tamamen boş satır/sütunları kırp
        // önce max dolu kolon
        $maxCol = 0; $lastRow = -1;
        foreach ($rows as $ri => $r) {
            $dolu = false;
            foreach ($r as $ci => $c) {
                if (trim((string)$c) !== '') { $dolu = true; if ($ci + 1 > $maxCol) $maxCol = $ci + 1; }
            }
            if ($dolu) $lastRow = $ri;
        }
        if ($lastRow < 0) { $atlanan[] = $adT.' (boş)'; continue; }
        $grid = [];
        for ($ri = 0; $ri <= $lastRow; $ri++) {
            $satir = [];
            for ($ci = 0; $ci < $maxCol; $ci++) {
                $satir[] = isset($rows[$ri][$ci]) ? trim((string)$rows[$ri][$ci]) : '';
            }
            $grid[] = $satir;
        }
        $json = json_encode($grid, JSON_UNESCAPED_UNICODE);
        $ins->execute([$adT, $i, $lastRow + 1, $maxCol, $json]);
        $aktarilan++;
    }
    flash('success', "{$aktarilan} sayfa içe aktarıldı".($atlanan ? " (atlanan: ".implode(', ', $atlanan).")" : "")."." );
    redirect('metraj_takip.php');
}

// ── Kayıtlı sayfalar ──────────────────────────────────────────────────────────
$sayfalar = $pdo->query("SELECT id, ad, sira, satir_sayisi, kolon_sayisi, guncelleme FROM metraj_sayfa ORDER BY sira, ad")->fetchAll();

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
    <?php if ($isAdmin): ?>
    <button class="btn btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#yukleBox">
        <i class="bi bi-cloud-arrow-up me-1"></i> Excel'den Sayfaları Aktar
    </button>
    <?php endif; ?>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($isAdmin): ?>
<div class="collapse mb-3" id="yukleBox"><div class="card card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <input type="hidden" name="action" value="yukle">
        <div class="col-md-9">
            <label class="form-label small">Beton Takip Excel (.xlsx) — tüm imalat/metraj/zayiat sayfaları içe aktarılır (Sayfa1 ve VERİ hariç)</label>
            <input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required>
        </div>
        <div class="col-md-3"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-arrow-repeat me-1"></i> Sayfaları Aktar / Eşitle</button></div>
    </form>
    <div class="form-text mt-1">Her sayfa olduğu gibi (grid) saklanır ve aşağıda sekme olarak gösterilir. Tekrar aktarınca güncellenir (üzerine yazar).</div>
</div></div>
<?php endif; ?>

<?php if (!$sayfalar): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
    Henüz sayfa yok.
    <?php if ($isAdmin): ?>Yukarıdan <strong>Excel'den Sayfaları Aktar</strong> ile PRP Bina Üstyapı, İksa Kazık, Temel Altı Kazık ve diğer imalat sayfalarını yükleyin.<?php endif; ?>
</div></div>
<?php else: ?>

<ul class="nav nav-tabs flex-nowrap overflow-auto pb-1" id="sayfaTab" role="tablist" style="white-space:nowrap">
    <?php foreach ($sayfalar as $k => $s): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $k===0?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab<?= (int)$s['id'] ?>" type="button" role="tab">
            <?= h($s['ad']) ?> <span class="badge bg-light text-muted border ms-1"><?= (int)$s['satir_sayisi'] ?>×<?= (int)$s['kolon_sayisi'] ?></span>
        </button>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content border border-top-0 rounded-bottom bg-white">
    <?php foreach ($sayfalar as $k => $s):
        $grid = json_decode($pdo->query("SELECT veri FROM metraj_sayfa WHERE id=".(int)$s['id'])->fetchColumn() ?: '[]', true) ?: [];
    ?>
    <div class="tab-pane fade <?= $k===0?'show active':'' ?>" id="tab<?= (int)$s['id'] ?>" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom flex-wrap gap-2">
            <div class="small text-muted"><i class="bi bi-clock-history me-1"></i>Güncelleme: <?= h($s['guncelleme']) ?> · <?= (int)$s['satir_sayisi'] ?> satır × <?= (int)$s['kolon_sayisi'] ?> sütun</div>
            <?php if ($isAdmin): ?>
            <a href="metraj_takip.php?sil=<?= (int)$s['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('<?= h($s['ad']) ?> sayfası kaldırılsın mı?')"><i class="bi bi-trash me-1"></i>Kaldır</a>
            <?php endif; ?>
        </div>
        <div class="table-responsive" style="max-height:70vh">
            <table class="table table-sm table-bordered mb-0 metraj-grid" style="width:auto;font-size:.78rem">
                <tbody>
                <?php foreach ($grid as $ri => $row):
                    // Satır tipi: tamamı metin (sayı yok) ve en az 1 dolu → başlık gibi
                    $doluSay = 0; $sayiSay = 0;
                    foreach ($row as $c) { if (trim($c)!=='') { $doluSay++; if (preg_match('/^[+\-]?\d+(\.\d+)?$/', trim($c))) $sayiSay++; } }
                    $baslikGibi = ($doluSay > 0 && $sayiSay === 0 && $ri < 6);
                ?>
                    <tr class="<?= $baslikGibi ? 'table-light fw-semibold' : '' ?>">
                        <?php foreach ($row as $ci => $c):
                            $isNum = preg_match('/^[+\-]?\d+(\.\d+)?$/', trim($c));
                        ?>
                            <td class="<?= $isNum ? 'text-end font-monospace' : '' ?>" style="<?= trim($c)===''?'background:#fafafa':'' ?>"><?= huc($c) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.metraj-grid td { white-space:nowrap; padding:.2rem .45rem; max-width:220px; overflow:hidden; text-overflow:ellipsis; }
.metraj-grid tr:hover td { background:#f1f8f6 !important; }
#sayfaTab .nav-link { font-size:.82rem; padding:.4rem .7rem; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
