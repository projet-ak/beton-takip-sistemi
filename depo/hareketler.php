<?php
/**
 * hareketler.php — Depo hareket defteri
 *
 * `depo_kalemler` stoğun FOTOĞRAFI; burası o stoğu oluşturan TEK TEK HAREKETLER:
 * hangi malzeme, ne zaman, hangi irsaliye/fiş ile, kimden geldi / kime gitti,
 * kim teslim aldı, kim onayladı.
 *
 * Kaynak Excel sayfaları: MALZEME GİRİŞ · MALZEME ÇIKIŞ ·
 *                         TAŞERON MALZEME GİRİŞ · TAŞERON MALZEME TESLİMAT
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

dp_hareket_semasi_kur($pdoDepo);
$pageTitle = 'Hareketler — Depo';

// ── Filtreler (hepsi whitelist / prepared) ───────────────────────────────────
$tur     = isset($GLOBALS['DP_HAREKET'][$_GET['tur'] ?? ''])  ? $_GET['tur']    : '';
$kaynak  = isset($GLOBALS['DP_KAYNAK'][$_GET['kaynak'] ?? '']) ? $_GET['kaynak'] : '';
$ara     = trim($_GET['ara'] ?? '');
$firma   = trim($_GET['firma'] ?? '');
$bas     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bas'] ?? '') ? $_GET['bas'] : '';
$bit     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bit'] ?? '') ? $_GET['bit'] : '';
$sayfa   = max(1, (int)($_GET['s'] ?? 1));
$adet    = 100;

$where = []; $par = [];
if ($tur)    { $where[] = 'tur = ?';    $par[] = $tur; }
if ($kaynak) { $where[] = 'kaynak = ?'; $par[] = $kaynak; }
if ($firma)  { $where[] = 'firma = ?';  $par[] = $firma; }
if ($bas)    { $where[] = 'tarih >= ?'; $par[] = $bas; }
if ($bit)    { $where[] = 'tarih <= ?'; $par[] = $bit; }
if ($ara !== '') {
    $where[] = '(malzeme LIKE ? OR ozellik LIKE ? OR belge_no LIKE ? OR teslim_alan LIKE ? OR onay LIKE ? OR lokasyon LIKE ? OR aciklama LIKE ?)';
    for ($j = 0; $j < 7; $j++) $par[] = "%$ara%";
}
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Filtreye uyan özet (sayfalamadan bağımsız)
$oz = $pdoDepo->prepare("SELECT COUNT(*) adet, SUM(miktar) miktar,
                                SUM(CASE WHEN tur='giris' THEN miktar ELSE 0 END) giris,
                                SUM(CASE WHEN tur='cikis' THEN miktar ELSE 0 END) cikis,
                                COUNT(DISTINCT firma) firma_adet, COUNT(DISTINCT malzeme) malzeme_adet,
                                MIN(tarih) ilk, MAX(tarih) son
                         FROM depo_hareketler $wsql");
$oz->execute($par);
$oz = $oz->fetch() ?: ['adet'=>0,'miktar'=>0,'giris'=>0,'cikis'=>0,'firma_adet'=>0,'malzeme_adet'=>0,'ilk'=>null,'son'=>null];

// ── Excel dışa aktarma (filtrelere saygılı, sayfalamasız) ────────────────────
if (($_GET['disaaktar'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $st = $pdoDepo->prepare("SELECT * FROM depo_hareketler $wsql ORDER BY tarih, id");
    $st->execute($par);
    $xl = new \XlsxWriter('Depo Hareketleri');
    $xl->header(['Tür','Kaynak','Tarih','Belge Tarihi','Belge No','Malzeme','Özellik','Birim','Miktar',
                 'Firma / Taşeron','Teslim Alan','Onay','Lokasyon','Açıklama']);
    $top = 0.0;
    foreach ($st->fetchAll() as $r) {
        $top += (float)$r['miktar'];
        $xl->row([
            ['v'=>dp_turAd($r['tur'])], ['v'=>dp_kaynakAd($r['kaynak'])],
            ['v'=>$r['tarih'],'t'=>'date'], ['v'=>$r['belge_tarihi'],'t'=>'date'],
            ['v'=>$r['belge_no']], ['v'=>$r['malzeme']], ['v'=>$r['ozellik']], ['v'=>$r['birim']],
            ['v'=>$r['miktar'],'t'=>'number'],
            ['v'=>$r['firma']], ['v'=>$r['teslim_alan']], ['v'=>$r['onay']],
            ['v'=>$r['lokasyon']], ['v'=>$r['aciklama']],
        ]);
    }
    $xl->total([['v'=>'TOPLAM'],['v'=>''],['v'=>''],['v'=>''],['v'=>''],['v'=>''],['v'=>''],['v'=>''],
                ['v'=>$top,'t'=>'number']]);
    $xl->download('depo_hareketler_' . date('Ymd_Hi') . '.xlsx');
}

// ── Liste (sayfalı) ──────────────────────────────────────────────────────────
$toplamSatir = (int)$oz['adet'];
$sonSayfa    = max(1, (int)ceil($toplamSatir / $adet));
if ($sayfa > $sonSayfa) $sayfa = $sonSayfa;
$atla = ($sayfa - 1) * $adet;

$st = $pdoDepo->prepare("SELECT * FROM depo_hareketler $wsql ORDER BY tarih DESC, id DESC LIMIT $adet OFFSET $atla");
$st->execute($par);
$liste = $st->fetchAll();

// Filtre açılır listeleri
$firmalar = $pdoDepo->query("SELECT firma, COUNT(*) n FROM depo_hareketler
                             WHERE firma IS NOT NULL AND firma <> ''
                             GROUP BY firma ORDER BY n DESC, firma")->fetchAll();

$fmt  = fn($n) => number_format((float)$n, 2, ',', '.');
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');
$qs = function(array $ek = []) {
    $p = array_merge(['tur'=>$_GET['tur']??'','kaynak'=>$_GET['kaynak']??'','ara'=>$_GET['ara']??'',
                      'firma'=>$_GET['firma']??'','bas'=>$_GET['bas']??'','bit'=>$_GET['bit']??''], $ek);
    return 'hareketler.php?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-arrow-left-right text-primary me-2"></i>Depo Hareketleri</h4>
        <small class="text-muted">Giriş / çıkış defteri — hangi malzeme, kimden, kime, hangi belgeyle</small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= h($qs(['disaaktar'=>'xlsx'])) ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</a>
        <a href="import.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>İçe Aktar</a>
    </div>
</div>

<?php if (!$toplamSatir && !$where): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>Henüz hareket kaydı yok.
    <a href="import.php" class="alert-link">Excel İçe Aktar</a> ekranından
    <strong>MALZEME GİRİŞ/ÇIKIŞ</strong> ve <strong>TAŞERON MALZEME GİRİŞ/TESLİMAT</strong> sayfalarını yükleyin.
</div>
<?php endif; ?>

<!-- Özet -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Hareket</div><div class="fs-5 fw-bold"><?= $fmt0($oz['adet']) ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Giriş Miktarı</div><div class="fs-5 fw-bold text-success"><?= $fmt($oz['giris']) ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Çıkış Miktarı</div><div class="fs-5 fw-bold text-danger"><?= $fmt($oz['cikis']) ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Farklı Malzeme</div><div class="fs-5 fw-bold"><?= $fmt0($oz['malzeme_adet']) ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Firma / Taşeron</div><div class="fs-5 fw-bold"><?= $fmt0($oz['firma_adet']) ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Tarih Aralığı</div>
        <div class="fw-bold small"><?= $oz['ilk'] ? h(format_date($oz['ilk'])).' — '.h(format_date($oz['son'])) : '—' ?></div></div></div></div>
</div>

<!-- Filtreler -->
<form class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <select name="tur" class="form-select form-select-sm">
            <option value="">Tüm türler</option>
            <?php foreach ($GLOBALS['DP_HAREKET'] as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $tur===$k?'selected':'' ?>><?= h($v['ad']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <select name="kaynak" class="form-select form-select-sm">
            <option value="">Depo + Taşeron</option>
            <?php foreach ($GLOBALS['DP_KAYNAK'] as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $kaynak===$k?'selected':'' ?>><?= h($v['ad']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <select name="firma" class="form-select form-select-sm">
            <option value="">Tüm firmalar / taşeronlar</option>
            <?php foreach ($firmalar as $f): ?>
            <option value="<?= h($f['firma']) ?>" <?= $firma===$f['firma']?'selected':'' ?>><?= h($f['firma']) ?> (<?= (int)$f['n'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2"><input type="date" name="bas" value="<?= h($bas) ?>" class="form-control form-control-sm" title="Başlangıç"></div>
    <div class="col-6 col-md-2"><input type="date" name="bit" value="<?= h($bit) ?>" class="form-control form-control-sm" title="Bitiş"></div>
    <div class="col-12 col-md-5"><input name="ara" value="<?= h($ara) ?>" class="form-control form-control-sm"
        placeholder="Malzeme · belge no · teslim alan · onaylayan · lokasyon ara"></div>
    <div class="col-6 col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrele</button></div>
    <div class="col-6 col-md-2"><a href="hareketler.php" class="btn btn-outline-secondary btn-sm w-100">Temizle</a></div>
</form>

<div class="card border-0 shadow-sm"><div class="card-body p-0">
<div class="table-responsive" style="max-height:68vh">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
    <thead class="table-light" style="position:sticky;top:0;z-index:1"><tr>
        <th>Tür</th><th>Tarih</th><th>Belge No</th><th>Malzeme</th><th>Özellik</th>
        <th class="text-end">Miktar</th><th>Birim</th><th>Firma / Taşeron</th>
        <th>Teslim Alan</th><th>Onay</th><th>Lokasyon / Açıklama</th>
    </tr></thead>
    <tbody>
    <?php foreach ($liste as $r): $g = $r['tur']==='giris'; ?>
        <tr>
            <td class="text-nowrap">
                <span class="badge bg-<?= $g?'success':'danger' ?>"><i class="bi <?= h($GLOBALS['DP_HAREKET'][$r['tur']]['ikon']) ?>"></i></span>
                <?php if ($r['kaynak']==='taseron'): ?><span class="badge bg-secondary" title="Taşeron malzemesi">T</span><?php endif; ?>
            </td>
            <td class="text-nowrap"><?= h(format_date($r['tarih'])) ?></td>
            <td class="font-monospace small"><?= h($r['belge_no'] ?: '—') ?></td>
            <td><?= h($r['malzeme']) ?></td>
            <td class="text-muted small"><?= h((string)$r['ozellik']) ?></td>
            <td class="text-end fw-semibold <?= $g?'text-success':'text-danger' ?>"><?= $g?'+':'−' ?><?= $fmt($r['miktar']) ?></td>
            <td class="small"><?= h((string)$r['birim']) ?></td>
            <td><?= h((string)$r['firma']) ?></td>
            <td class="small"><?= h((string)$r['teslim_alan']) ?></td>
            <td class="small text-muted"><?= h((string)$r['onay']) ?></td>
            <td class="small text-muted"><?= h(trim(($r['lokasyon'] ?: '') . ' ' . ($r['aciklama'] ?: ''))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$liste): ?>
        <tr><td colspan="11" class="text-center text-muted py-4">Filtreye uyan hareket yok.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
</div></div>

<?php if ($sonSayfa > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center flex-wrap">
    <?php
    $bas1 = max(1, $sayfa-3); $son1 = min($sonSayfa, $sayfa+3);
    if ($sayfa > 1): ?><li class="page-item"><a class="page-link" href="<?= h($qs(['s'=>$sayfa-1])) ?>">&laquo;</a></li><?php endif;
    if ($bas1 > 1): ?><li class="page-item"><a class="page-link" href="<?= h($qs(['s'=>1])) ?>">1</a></li>
        <li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
    for ($i = $bas1; $i <= $son1; $i++): ?>
        <li class="page-item <?= $i===$sayfa?'active':'' ?>"><a class="page-link" href="<?= h($qs(['s'=>$i])) ?>"><?= $i ?></a></li>
    <?php endfor;
    if ($son1 < $sonSayfa): ?><li class="page-item disabled"><span class="page-link">…</span></li>
        <li class="page-item"><a class="page-link" href="<?= h($qs(['s'=>$sonSayfa])) ?>"><?= $sonSayfa ?></a></li><?php endif;
    if ($sayfa < $sonSayfa): ?><li class="page-item"><a class="page-link" href="<?= h($qs(['s'=>$sayfa+1])) ?>">&raquo;</a></li><?php endif; ?>
</ul>
<div class="text-center text-muted small"><?= $fmt0($toplamSatir) ?> hareket · sayfa <?= $sayfa ?>/<?= $sonSayfa ?></div>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
