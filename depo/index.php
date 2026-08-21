<?php
/** index.php — Depo Takip dashboard (Sarf / Demirbaş / El Aletleri) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

// Tablo yoksa kuruluma yönlendir
try { $pdoDepo->query("SELECT 1 FROM depo_kalemler LIMIT 1"); }
catch (Throwable $e) { redirect('kurulum_depo.php'); }

$ozet = dp_ozet($pdoDepo);
$topDeger = 0; $topKalem = 0; $topTukenen = 0;
foreach ($ozet as $o) { $topDeger += (float)$o['deger']; $topKalem += (int)$o['adet']; $topTukenen += (int)$o['tukenen']; }
$fmt0 = fn($n)=>number_format((float)$n,0,',','.');
$fmt  = fn($n)=>number_format((float)$n,2,',','.');

// Tükenen / düşük stok kalemler (kritik liste)
$kritik = $pdoDepo->query("SELECT kategori,kod,ad,ozellik,birim,(sayim+gelen-giden) stok
    FROM depo_kalemler WHERE aktif=1 AND (sayim+gelen-giden)<=0 ORDER BY ad LIMIT 60")->fetchAll();

// ── Hareket defteri özeti (varsa) ────────────────────────────────────────────
dp_hareket_semasi_kur($pdoDepo);
$hOzet = dp_hareket_ozet($pdoDepo);
$hToplam = 0;
foreach ($hOzet as $ho) $hToplam += (int)$ho['adet'];

$sonHareket = $hToplam ? $pdoDepo->query("SELECT * FROM depo_hareketler ORDER BY tarih DESC, id DESC LIMIT 12")->fetchAll() : [];
$enCokFirma = $hToplam ? $pdoDepo->query("SELECT firma, tur, COUNT(*) adet FROM depo_hareketler
                                          WHERE firma IS NOT NULL AND firma <> ''
                                          GROUP BY firma, tur ORDER BY adet DESC LIMIT 8")->fetchAll() : [];

$pageTitle = 'Depo Takip — Genel Bakış';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Depo Takip — Genel Bakış</h4>
    <div class="d-flex gap-2">
        <a href="import.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Excel İçe Aktar</a>
    </div>
</div>

<!-- Özet KPI -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-collection me-1"></i>Toplam Kalem</div>
            <div class="h3 mb-0 fw-bold"><?= $fmt0($topKalem) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-cash-stack me-1"></i>Toplam Mali Değer</div>
            <div class="h3 mb-0 fw-bold text-success"><?= $fmt0($topDeger) ?> <small class="fs-6 text-muted">TL</small></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-diagram-3 me-1"></i>Kategori</div>
            <div class="h3 mb-0 fw-bold"><?= count($GLOBALS['DP_KATEGORI']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Tükenen Kalem</div>
            <div class="h3 mb-0 fw-bold <?= $topTukenen>0?'text-danger':'' ?>"><?= $fmt0($topTukenen) ?></div>
        </div></div>
    </div>
</div>

<!-- Kategori kartları -->
<div class="row g-3 mb-4">
    <?php foreach($GLOBALS['DP_KATEGORI'] as $kk=>$ki): $o=$ozet[$kk]??null; $elAleti=($kk==='el_aleti'); ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3"
                         style="width:48px;height:48px;background:var(--ern,#00584E);color:#fff">
                        <i class="bi <?= h($ki['ikon']) ?> fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= h($ki['ad']) ?></div>
                        <div class="small text-muted"><?= $o?$fmt0($o['adet']):'0' ?> kalem</div>
                    </div>
                </div>
                <div class="row text-center g-2">
                    <div class="col-4">
                        <div class="small text-muted">Stok</div>
                        <div class="fw-bold"><?= $o?$fmt0($o['stok']):'0' ?></div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted"><?= $elAleti?'—':'Değer' ?></div>
                        <div class="fw-bold text-success"><?= (!$elAleti && $o)?$fmt0($o['deger']):'—' ?></div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted">Tükenen</div>
                        <div class="fw-bold <?= ($o && $o['tukenen']>0)?'text-danger':'' ?>"><?= $o?$fmt0($o['tukenen']):'0' ?></div>
                    </div>
                </div>
                <a href="kalemler.php?k=<?= $kk ?>" class="btn btn-outline-primary btn-sm w-100 mt-3"><i class="bi bi-list-ul me-1"></i>Kalemleri Gör</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Hareket defteri -->
<?php if ($hToplam): ?>
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-arrow-left-right text-primary me-2"></i>Hareket Defteri</h6>
                <a href="hareketler.php" class="btn btn-outline-primary btn-sm">Tümü <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <?php foreach ($GLOBALS['DP_KAYNAK'] as $kk => $ki): ?>
                    <?php foreach ($GLOBALS['DP_HAREKET'] as $tt => $ti): $o = $hOzet[$tt.'|'.$kk] ?? null; ?>
                    <div class="col-6 col-md-3">
                        <a href="hareketler.php?tur=<?= $tt ?>&kaynak=<?= $kk ?>" class="text-decoration-none">
                        <div class="border rounded p-2 h-100">
                            <div class="small text-muted text-truncate" title="<?= h($ki['ad'].' — '.$ti['ad']) ?>">
                                <i class="bi <?= h($ki['ikon']) ?> me-1"></i><?= h($kk==='depo'?'Depo':'Taşeron') ?>
                            </div>
                            <div class="fw-bold text-<?= h($ti['renk']) ?>"><?= $o ? $fmt0($o['adet']) : '0' ?>
                                <small class="text-muted fw-normal"><?= h($ti['ad']) ?></small></div>
                        </div></a>
                    </div>
                    <?php endforeach; endforeach; ?>
                </div>
                <div class="table-responsive" style="max-height:34vh">
                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.8rem">
                    <thead class="table-light" style="position:sticky;top:0"><tr>
                        <th></th><th>Tarih</th><th>Malzeme</th><th class="text-end">Miktar</th><th>Firma</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($sonHareket as $r): $g = $r['tur']==='giris'; ?>
                        <tr>
                            <td><span class="badge bg-<?= $g?'success':'danger' ?>"><?= $g?'+':'−' ?></span></td>
                            <td class="text-nowrap small"><?= h(format_date($r['tarih'])) ?></td>
                            <td class="small text-truncate" style="max-width:220px" title="<?= h($r['malzeme']) ?>"><?= h($r['malzeme']) ?></td>
                            <td class="text-end fw-semibold <?= $g?'text-success':'text-danger' ?>"><?= $fmt($r['miktar']) ?> <small class="text-muted fw-normal"><?= h((string)$r['birim']) ?></small></td>
                            <td class="small text-muted text-truncate" style="max-width:150px"><?= h((string)$r['firma']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-3"><h6 class="mb-0"><i class="bi bi-building text-primary me-2"></i>En Çok Hareket Gören Firmalar</h6></div>
            <div class="card-body p-0"><div class="table-responsive" style="max-height:44vh">
                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
                    <thead class="table-light" style="position:sticky;top:0"><tr><th>Firma / Taşeron</th><th>Tür</th><th class="text-end">Hareket</th></tr></thead>
                    <tbody>
                    <?php foreach ($enCokFirma as $f): ?>
                        <tr>
                            <td class="small"><a href="hareketler.php?firma=<?= h(urlencode($f['firma'])) ?>" class="text-decoration-none"><?= h($f['firma']) ?></a></td>
                            <td><span class="badge bg-<?= $f['tur']==='giris'?'success':'danger' ?>"><?= h(dp_turAd($f['tur'])) ?></span></td>
                            <td class="text-end fw-bold"><?= $fmt0($f['adet']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Kritik / tükenen liste -->
<?php if($kritik): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 pt-3"><h6 class="mb-0"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Tükenen / Stok Dışı Kalemler <span class="badge bg-danger ms-1"><?= count($kritik) ?></span></h6></div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:50vh">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
            <thead class="table-light" style="position:sticky;top:0"><tr>
                <th>Kategori</th><th>Kod</th><th>Malzeme</th><th>Özellik</th><th class="text-end">Stok</th>
            </tr></thead>
            <tbody>
            <?php foreach($kritik as $r): ?>
                <tr>
                    <td><span class="badge bg-light text-dark"><?= h(dp_katAd($r['kategori'])) ?></span></td>
                    <td class="font-monospace small"><?= h($r['kod']?:'—') ?></td>
                    <td class="fw-semibold small"><?= h($r['ad']) ?></td>
                    <td class="small text-muted"><?= h($r['ozellik']?:'—') ?></td>
                    <td class="text-end font-monospace fw-bold text-danger"><?= $fmt($r['stok']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>
<?php else: ?>
<div class="alert alert-light border text-center text-muted"><i class="bi bi-check-circle text-success me-1"></i>Tükenen/stok dışı kalem yok.</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
