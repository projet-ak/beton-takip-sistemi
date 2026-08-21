<?php
/**
 * demir/mutabakat.php — Talep Teslimi ↔ Saha Girişi mutabakatı
 *
 * İki bağımsız kayıt aynı demiri iki uçtan izler:
 *   TALEP tarafı : IFS sipariş talepleri (demir_talep_kalemleri.teslim_kg) —
 *                  satın almanın "tedarikçi bu kadarını teslim etti" beyanı (kg).
 *   SAHA tarafı  : sevkiyatlar (demir_sevkiyat_kalemleri.irsaliye/kantar_miktar) —
 *                  şantiye kapısından fiilen giren demir (ton).
 * Bu ekran ikisini ÇAP bazında karşılaştırır; fark = kayıt eksiği ya da
 * henüz sahaya ulaşmamış teslimat demektir.
 *
 * ⚠ Tarih bazlı kıyas bilerek YOK: talep tarihi sipariş günü, sevkiyat tarihi
 *   geliş günü — aynı malın iki tarihi farklıdır, tarih filtresi yanıltır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Talep ↔ Saha Mutabakatı — Demir Takip';

// Talep tabloları runtime oluşur (talepler.php); yoksa yönlendirme mesajı göster
$talepVar = true;
try { $pdoDemir->query("SELECT 1 FROM demir_talep_kalemleri LIMIT 1"); }
catch (Throwable $e) { $talepVar = false; }

// ── Filtre: proje ────────────────────────────────────────────────────────────
$projeler = $pdoDemir->query("SELECT id, kod, aciklama FROM demir_projeler ORDER BY kod")->fetchAll();
$fProje = isset($_GET['proje']) && ctype_digit((string)$_GET['proje']) ? (int)$_GET['proje'] : 0;
$fProjeKod = '';
foreach ($projeler as $p) if ((int)$p['id'] === $fProje) $fProjeKod = $p['kod'];

// ── Talep tarafı: çap bazında sipariş/teslim (kg → ton) ─────────────────────
$talep = [];
if ($talepVar) {
    $sql = "SELECT COALESCE(c.ad, k.cap_label, '—') cl,
                   SUM(k.siparis_kg)/1000 sip, SUM(k.teslim_kg)/1000 tes
            FROM demir_talep_kalemleri k
            JOIN demir_talepler t ON t.id = k.talep_id
            LEFT JOIN demir_caplar c ON c.id = k.cap_id";
    $par = [];
    if ($fProjeKod !== '') { $sql .= " WHERE t.proje LIKE ?"; $par[] = "%{$fProjeKod}%"; }
    $st = $pdoDemir->prepare($sql . " GROUP BY cl");
    $st->execute($par);
    foreach ($st->fetchAll() as $r) $talep[$r['cl']] = $r;
}

// ── Saha tarafı: çap bazında irsaliye/kantar (ton) ──────────────────────────
$sql = "SELECT COALESCE(c.ad, '—') cl,
               SUM(sk.irsaliye_miktar) irs, SUM(COALESCE(sk.kantar_miktar, sk.irsaliye_miktar)) kantar,
               COUNT(DISTINCT s.id) sevk
        FROM demir_sevkiyat_kalemleri sk
        JOIN demir_sevkiyatlar s ON s.id = sk.sevkiyat_id
        LEFT JOIN demir_caplar c ON c.id = sk.cap_id";
$par = [];
if ($fProje) { $sql .= " WHERE s.proje_id = ?"; $par[] = $fProje; }
$st = $pdoDemir->prepare($sql . " GROUP BY cl");
$st->execute($par);
$saha = [];
foreach ($st->fetchAll() as $r) $saha[$r['cl']] = $r;

// ── Birleştir + sırala ──────────────────────────────────────────────────────
$caplar = array_unique(array_merge(array_keys($talep), array_keys($saha)));
usort($caplar, function ($a, $b) {
    $sira = function ($s) {
        $q = (int)(mb_strpos($s, 'Q') === 0);                       // hasır en sonda
        $sp = (int)(mb_stripos($s, 'SPIRAL') !== false || mb_stripos($s, 'SPİRAL') !== false);
        $n = preg_match('/(\d+)/', $s, $m) ? (int)$m[1] : 999;
        return [$q, $sp, $n];
    };
    return $sira($a) <=> $sira($b);
});

$topTalepSip = 0.0; $topTalepTes = 0.0; $topSahaIrs = 0.0; $topSahaKnt = 0.0; $sevkAdet = 0;
$satirlar = [];
foreach ($caplar as $cl) {
    $t = $talep[$cl] ?? null; $s = $saha[$cl] ?? null;
    $tes = (float)($t['tes'] ?? 0); $irs = (float)($s['irs'] ?? 0);
    $fark = $tes - $irs;
    // Tolerans: 0,5 t veya %1 (hangisi büyükse) — kantar/yuvarlama farkları uyumsuzluk sayılmasın
    $tol = max(0.5, 0.01 * max($tes, $irs));
    $satirlar[] = [
        'cl' => $cl, 'sip' => (float)($t['sip'] ?? 0), 'tes' => $tes,
        'irs' => $irs, 'kantar' => (float)($s['kantar'] ?? 0),
        'fark' => $fark, 'uyum' => abs($fark) <= $tol,
        'talepYok' => $t === null, 'sahaYok' => $s === null,
    ];
    $topTalepSip += (float)($t['sip'] ?? 0); $topTalepTes += $tes;
    $topSahaIrs += $irs; $topSahaKnt += (float)($s['kantar'] ?? 0);
    $sevkAdet += (int)($s['sevk'] ?? 0);
}
$topFark = $topTalepTes - $topSahaIrs;
$uyumsuz = count(array_filter($satirlar, fn($r) => !$r['uyum']));

$fmt = fn($n, $d = 2) => number_format((float)$n, $d, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-arrow-left-right text-primary me-2"></i>Talep ↔ Saha Mutabakatı</h4>
        <small class="text-muted">IFS taleplerinde "Teslim Alınan" ile şantiyeye fiilen giren demirin çap bazlı karşılaştırması</small>
    </div>
</div>

<?php if (!$talepVar || !$talep): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
    Talep verisi yok — önce <a href="talepler.php" class="alert-link">Sipariş Talepleri</a> ekranından
    "Demir Siparişleri Takip Tablosu"nu yükleyin.
</div>
<?php endif; ?>
<?php if (!$saha): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
    Sevkiyat verisi yok<?= $fProje ? ' (bu projede)' : '' ?> — saha tarafı
    <a href="sevkiyatlar.php" class="alert-link">Sevkiyatlar</a>'dan beslenir.
</div>
<?php endif; ?>

<form class="row g-2 mb-3">
    <div class="col-md-4">
        <select name="proje" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Tüm projeler</option>
            <?php foreach ($projeler as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $fProje===(int)$p['id']?'selected':'' ?>><?= h($p['kod']) ?><?= $p['aciklama'] !== '' ? ' — '.h($p['aciklama']) : '' ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($fProje): ?><div class="col-md-2"><a href="mutabakat.php" class="btn btn-outline-secondary btn-sm w-100">Temizle</a></div><?php endif; ?>
</form>

<?php if ($satirlar): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Talep — Sipariş</div><div class="fs-5 fw-bold"><?= $fmt($topTalepSip) ?> t</div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Talep — Teslim Alınan</div><div class="fs-5 fw-bold"><?= $fmt($topTalepTes) ?> t</div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Saha — İrsaliye (<?= $sevkAdet ?> sevkiyat)</div><div class="fs-5 fw-bold text-success"><?= $fmt($topSahaIrs) ?> t</div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Saha — Kantar</div><div class="fs-5 fw-bold"><?= $fmt($topSahaKnt) ?> t</div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100 <?= abs($topFark)>0.5?'border border-warning':'' ?>"><div class="card-body py-2">
        <div class="text-muted small">Fark (Talep − Saha)</div>
        <div class="fs-5 fw-bold <?= abs($topFark)<=0.5?'text-success':($topFark>0?'text-danger':'text-primary') ?>"><?= $fmt($topFark) ?> t</div></div></div></div>
</div>

<?php if ($uyumsuz): ?>
<div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle-fill me-1"></i>
    <strong><?= $uyumsuz ?></strong> çapta tolerans üstü fark var (tolerans: 0,5 t veya %1).
    Fark <span class="text-danger fw-semibold">kırmızıysa</span> talep tarafı fazla — teslim edilmiş görünen demir saha kaydında yok
    (sevkiyat girilmemiş ya da yolda). <span class="text-primary fw-semibold">Maviyse</span> saha fazla — sahaya giren demir talep
    çıktısında görünmüyor (IFS çıktısı eski ya da talepsiz alım).
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.84rem">
    <thead class="table-light"><tr>
        <th>Çap</th>
        <th class="text-end">Talep Sipariş (t)</th>
        <th class="text-end">Talep Teslim (t)</th>
        <th class="text-end">Saha İrsaliye (t)</th>
        <th class="text-end">Saha Kantar (t)</th>
        <th class="text-end">Fark (t)</th>
        <th>Durum</th>
    </tr></thead>
    <tbody>
    <?php foreach ($satirlar as $r): ?>
        <tr class="<?= $r['uyum'] ? '' : 'table-warning' ?>">
            <td class="fw-semibold"><?= h($r['cl']) ?></td>
            <td class="text-end text-muted"><?= $r['talepYok'] ? '—' : $fmt($r['sip']) ?></td>
            <td class="text-end"><?= $r['talepYok'] ? '—' : $fmt($r['tes']) ?></td>
            <td class="text-end"><?= $r['sahaYok'] ? '—' : $fmt($r['irs']) ?></td>
            <td class="text-end text-muted"><?= $r['sahaYok'] ? '—' : $fmt($r['kantar']) ?></td>
            <td class="text-end fw-bold <?= $r['uyum'] ? 'text-success' : ($r['fark'] > 0 ? 'text-danger' : 'text-primary') ?>"><?= $fmt($r['fark']) ?></td>
            <td>
                <?php if ($r['talepYok']): ?><span class="badge bg-primary">Yalnız sahada</span>
                <?php elseif ($r['sahaYok']): ?><span class="badge bg-danger">Yalnız talepte</span>
                <?php elseif ($r['uyum']): ?><span class="badge bg-success">Uyumlu</span>
                <?php elseif ($r['fark'] > 0): ?><span class="badge bg-danger">Saha eksik</span>
                <?php else: ?><span class="badge bg-primary">Saha fazla</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light fw-bold"><tr>
        <td>TOPLAM</td>
        <td class="text-end"><?= $fmt($topTalepSip) ?></td>
        <td class="text-end"><?= $fmt($topTalepTes) ?></td>
        <td class="text-end"><?= $fmt($topSahaIrs) ?></td>
        <td class="text-end"><?= $fmt($topSahaKnt) ?></td>
        <td class="text-end <?= abs($topFark)<=0.5?'text-success':($topFark>0?'text-danger':'text-primary') ?>"><?= $fmt($topFark) ?></td>
        <td></td>
    </tr></tfoot>
</table>
</div></div></div>

<div class="text-muted small mt-2">
    <i class="bi bi-info-circle me-1"></i>
    Talep tarafı IFS çıktısının kopyalandığı günü, saha tarafı işlenen sevkiyatları yansıtır — iki kayıt farklı
    günlerde güncellenir; küçük farklar zamanlamadan olabilir. Tarih filtresi bilerek yok: talep tarihi sipariş
    günü, sevkiyat tarihi geliş günüdür, aynı malın iki tarihi eşleşmez.
    <?php if ($fProjeKod !== ''): ?><br><i class="bi bi-funnel me-1"></i>Proje süzgeci: talep tarafında "<?= h($fProjeKod) ?>" geçen talepler
    (bir talep birden çok projeyi kapsayabilir), saha tarafında projesi <?= h($fProjeKod) ?> olan sevkiyatlar.<?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
