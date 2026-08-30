<?php
/**
 * malzeme_ekstre.php — Malzeme kartı / ekstre: "bu ürün nereden geldi, kime verildi, elde ne var?"
 *
 * "Olmayan ürünü nereden verdiniz?" sorusunun CEVABI bu ekrandır:
 *   Başlangıç = stok kartındaki SAYIM (fiili sayımla tespit edilen mevcut).
 *   Ekstre    = sayım + tarih sırasıyla girişler (−çıkışlar) → yürüyen bakiye.
 * Bakiye eksiye düşüyorsa iki ihtimal vardır ve ekran bunu AÇIKÇA söyler:
 * ya malzeme sayım öncesi stoktan verilmiştir ya da giriş kaydı işlenmemiştir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

try { $pdoDepo->query("SELECT 1 FROM depo_hareketler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_depo.php'); }

$fmt  = fn($n) => number_format((float)$n, 2, ',', '.');
$m    = trim((string)($_GET['m'] ?? ''));
$norm = dp_mal_norm($m);

$kalemler = []; $hareketler = []; $benzer = [];
if ($norm !== '') {
    // Stok kartları: normalize ad birebir VEYA biri diğerini içeriyor
    foreach ($pdoDepo->query("SELECT * FROM depo_kalemler ORDER BY kategori, ad") as $k) {
        $ka = dp_mal_norm((string)$k['ad']);
        if ($ka === '') continue;
        if ($ka === $norm || str_contains($ka, $norm) || str_contains($norm, $ka)) $kalemler[] = $k;
    }
    // Hareketler: normalize ad birebir eşit olanlar (ekstrenin kendisi)
    $st = $pdoDepo->query("SELECT * FROM depo_hareketler ORDER BY tarih IS NULL, tarih ASC, id ASC");
    foreach ($st as $r) {
        $ra = dp_mal_norm((string)$r['malzeme']);
        if ($ra === $norm) $hareketler[] = $r;
        elseif ($ra !== '' && (str_contains($ra, $norm) || str_contains($norm, $ra))) $benzer[$r['malzeme']] = true;
    }
    $benzer = array_slice(array_keys($benzer), 0, 20);
}

// Başlangıç bakiyesi = stok kartlarındaki SAYIM toplamı (fiili tespit)
$sayimBaz = 0.0; $kartStok = 0.0;
foreach ($kalemler as $k) {
    $sayimBaz += (float)$k['sayim'];
    $kartStok += (float)$k['sayim'] + (float)$k['gelen'] - (float)$k['giden'];
}
$girisT = 0.0; $cikisT = 0.0;
foreach ($hareketler as $r) { $r['tur'] === 'giris' ? $girisT += (float)$r['miktar'] : $cikisT += (float)$r['miktar']; }
$sonBakiye = $sayimBaz + $girisT - $cikisT;

// Yürüyen bakiye + eksiye düşüş tespiti
$enDusuk = $sayimBaz; $eksiVar = false;
$bak = $sayimBaz; $satirBakiye = [];
foreach ($hareketler as $i => $r) {
    $bak += ($r['tur'] === 'giris' ? 1 : -1) * (float)$r['miktar'];
    $satirBakiye[$i] = $bak;
    if ($bak < -0.001) $eksiVar = true;
    $enDusuk = min($enDusuk, $bak);
}

$pageTitle = 'Malzeme Ekstresi — Depo';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="hareketler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-journal-text text-primary me-2"></i>Malzeme Ekstresi</h4>
</div>

<form class="row g-2 mb-3">
    <div class="col-md-6"><input name="m" value="<?= h($m) ?>" class="form-control form-control-sm" placeholder="Malzeme adı (ör. ELDİVEN, KÜREK, TOTAL 36 W LED ARMATÜR)"></div>
    <div class="col-6 col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Getir</button></div>
</form>

<?php if ($m === ''): ?>
<div class="alert alert-light border">Malzeme adı yazın ya da <a href="hareketler.php">Hareketler</a> listesinde bir malzemeye tıklayın.</div>
<?php else: ?>

<h5 class="mb-3"><?= h($m) ?></h5>

<?php if ($eksiVar || $sonBakiye < -0.001): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    <strong>Bu malzemenin çıkışları, kayıtlı girişlerini + sayımını aşıyor</strong>
    (en düşük bakiye: <?= $fmt($enDusuk) ?>). Bunun iki izahı olabilir:
    <ol class="mb-1 mt-1">
        <li>Malzeme <strong>sayım yapılmadan önce</strong> depoda vardı ve verilen, sayım öncesi stoktan çıktı;</li>
        <li>ya da <strong>giriş kaydı deftere işlenmedi</strong> (irsaliye girilmemiş).</li>
    </ol>
    Aşağıdaki ekstrede eksiye düşülen satırlar kırmızıdır — o tarihten önceki girişleri kontrol edin.
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Sayım (baz)</div><div class="fs-5 fw-bold"><?= $fmt($sayimBaz) ?></div></div></div></div>
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Girişler</div><div class="fs-5 fw-bold text-success">+<?= $fmt($girisT) ?></div></div></div></div>
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Çıkışlar</div><div class="fs-5 fw-bold text-danger">−<?= $fmt($cikisT) ?></div></div></div></div>
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100 <?= $sonBakiye < -0.001 ? 'border border-danger' : '' ?>"><div class="card-body py-2">
        <div class="text-muted small">Defter Bakiyesi</div><div class="fs-5 fw-bold <?= $sonBakiye < -0.001 ? 'text-danger' : 'text-primary' ?>"><?= $fmt($sonBakiye) ?></div></div></div></div>
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Stok Kartı</div><div class="fs-5 fw-bold"><?= $kalemler ? $fmt($kartStok) : '—' ?></div></div></div></div>
    <div class="col-6 col-lg-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Hareket</div><div class="fs-5 fw-bold"><?= count($hareketler) ?></div></div></div></div>
</div>

<?php if ($kalemler): ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-semibold py-2"><i class="bi bi-box-seam me-1"></i>Eşleşen stok kartları (nerede duruyor?)</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover mb-0" style="font-size:.82rem">
    <thead class="table-light"><tr>
        <th>Kategori</th><th>Malzeme</th><th>Özellik</th><th class="text-end">Sayım</th><th class="text-end">Gelen</th>
        <th class="text-end">Giden</th><th class="text-end">Stok</th><th>Birim</th><th>Bulunduğu Alan / Raf</th><th>Zimmet</th>
    </tr></thead>
    <tbody>
    <?php foreach ($kalemler as $k): $ks = (float)$k['sayim'] + (float)$k['gelen'] - (float)$k['giden']; ?>
        <tr>
            <td><span class="badge bg-secondary"><?= h($GLOBALS['DP_KATEGORI'][$k['kategori']]['ad'] ?? $k['kategori']) ?></span></td>
            <td class="fw-semibold"><a href="kalem_form.php?id=<?= (int)$k['id'] ?>" class="text-reset"><?= h($k['ad']) ?></a></td>
            <td class="small text-muted"><?= h((string)$k['ozellik']) ?></td>
            <td class="text-end"><?= $fmt($k['sayim']) ?></td>
            <td class="text-end text-success"><?= $fmt($k['gelen']) ?></td>
            <td class="text-end text-danger"><?= $fmt($k['giden']) ?></td>
            <td class="text-end fw-bold <?= $ks <= 0 ? 'text-danger' : '' ?>"><?= $fmt($ks) ?></td>
            <td class="small"><?= h((string)$k['birim']) ?></td>
            <td class="small"><?= h($k['alan'] ?: '—') ?></td>
            <td class="small"><?= h($k['alan_kisi'] ?: '—') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div></div>
<?php else: ?>
<div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>
    Bu adla eşleşen <strong>stok kartı yok</strong> — malzeme sayım listelerinde (demirbaş/sarf/el aleti) kayıtlı değil.
    Hareketleri varsa aşağıdadır; bakiye yalnız defterden hesaplanır.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold py-2"><i class="bi bi-arrow-left-right me-1"></i>Hareket ekstresi (tarih sırasıyla, yürüyen bakiye)</div>
<div class="card-body p-0"><div class="table-responsive" style="max-height:60vh">
<table class="table table-sm table-hover mb-0" style="font-size:.82rem">
    <thead class="table-light" style="position:sticky;top:0;z-index:1"><tr>
        <th>Tarih</th><th>Tür</th><th>Belge No</th><th class="text-end">Miktar</th>
        <th class="text-end">Bakiye</th><th>Firma / Taşeron</th><th>Teslim Alan</th><th>Lokasyon / Açıklama</th><th>Belge</th>
    </tr></thead>
    <tbody>
    <?php if ($sayimBaz != 0.0): ?>
    <tr class="table-light fw-semibold">
        <td colspan="3">Sayım (stok kartı bazı) — fiili sayımla tespit edilen mevcut</td>
        <td class="text-end">+<?= $fmt($sayimBaz) ?></td>
        <td class="text-end"><?= $fmt($sayimBaz) ?></td><td colspan="4" class="text-muted small">Bu miktar sayım öncesi girişlerin sonucudur; o dönemin tek tek belgeleri defterde olmayabilir.</td>
    </tr>
    <?php endif; ?>
    <?php foreach ($hareketler as $i => $r): $g = $r['tur'] === 'giris'; $bk = $satirBakiye[$i]; ?>
        <tr class="<?= $bk < -0.001 ? 'table-danger' : '' ?>">
            <td class="text-nowrap"><?= h(format_date($r['tarih'])) ?></td>
            <td><span class="badge bg-<?= $g ? 'success' : 'danger' ?>"><?= $g ? 'Giriş' : 'Çıkış' ?></span>
                <?php if ($r['kaynak'] === 'taseron'): ?><span class="badge bg-secondary">T</span><?php endif; ?>
                <?php if (!empty($r['hurda'])): ?><span class="badge bg-warning text-dark">hurda</span><?php endif; ?></td>
            <td class="font-monospace small"><?= h($r['belge_no'] ?: '—') ?></td>
            <td class="text-end fw-semibold <?= $g ? 'text-success' : 'text-danger' ?>"><?= $g ? '+' : '−' ?><?= $fmt($r['miktar']) ?></td>
            <td class="text-end fw-bold <?= $bk < -0.001 ? 'text-danger' : '' ?>"><?= $fmt($bk) ?></td>
            <td class="small"><?= h((string)$r['firma']) ?></td>
            <td class="small"><?= h((string)$r['teslim_alan']) ?></td>
            <td class="small text-muted"><?= h(trim(($r['lokasyon'] ?: '') . ' ' . ($r['aciklama'] ?: ''))) ?></td>
            <td><?php if (!empty($r['evrak_url'])): ?><a href="../<?= h($r['evrak_url']) ?>" target="_blank" title="Ekli belge"><i class="bi bi-file-earmark-check text-success"></i></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$hareketler): ?><tr><td colspan="9" class="text-center text-muted py-4">Bu adla birebir eşleşen hareket yok.</td></tr><?php endif; ?>
    </tbody>
    <tfoot class="table-light fw-bold"><tr>
        <td colspan="3" class="text-end">Sonuç</td>
        <td class="text-end"><span class="text-success">+<?= $fmt($sayimBaz + $girisT) ?></span> / <span class="text-danger">−<?= $fmt($cikisT) ?></span></td>
        <td class="text-end <?= $sonBakiye < -0.001 ? 'text-danger' : '' ?>"><?= $fmt($sonBakiye) ?></td>
        <td colspan="4"></td>
    </tr></tfoot>
</table>
</div></div></div>

<?php if ($benzer): ?>
<div class="mt-3 small text-muted">
    <i class="bi bi-lightbulb me-1"></i>Benzer adlı kayıtlar (aynı ürün farklı yazılmış olabilir):
    <?php foreach ($benzer as $b): ?>
        <a href="?m=<?= urlencode($b) ?>" class="badge bg-light text-dark border text-decoration-none"><?= h($b) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
