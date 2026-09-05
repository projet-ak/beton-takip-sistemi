<?php
/**
 * isler.php — Prekast iş listesi
 *
 * Kayıtlar günlük çizelgeden gelir (import.php); burası filtreleme, arama, sıralama,
 * Excel dışa aktarma ekranıdır. Varsayılan görünüm çizelgede duran işlerdir;
 * çizelgeden düşenler silinmediği için `?dosyada=0` ile ayrıca listelenir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_prekast.php';
require_once __DIR__ . '/_ortak.php';

pk_semasi_kur($pdoPrekast);
$pageTitle = 'Prekast İş Listesi';

[$wsql, $par, $etkin] = pk_filtre($_GET);

// Özet (filtreye saygılı)
$oz = $pdoPrekast->prepare("SELECT COUNT(*) adet, SUM(kesim=1) kesim, SUM(silikon=1) silikon,
                                   COALESCE(SUM(metraj),0) metraj, COALESCE(SUM(hakkedis),0) hakkedis,
                                   COUNT(DISTINCT blok) blok, COUNT(DISTINCT CONCAT(blok,'|',daire)) daire
                            FROM prekast_isler $wsql");
$oz->execute($par);
$oz = $oz->fetch() ?: ['adet'=>0,'kesim'=>0,'silikon'=>0,'metraj'=>0,'hakkedis'=>0,'blok'=>0,'daire'=>0];

// Sıralama — whitelist (asla ham input)
$sirala = ['blok'=>'blok, daire_sira', 'daire'=>'daire_sira, blok', 'sira'=>'sira',
           'durum'=>'durum', 'metraj'=>'metraj', 'hakkedis'=>'hakkedis',
           'kesim'=>'kesim_tarih', 'silikon'=>'silikon_tarih'];
$skAnahtar = array_key_exists($_GET['sk'] ?? '', $sirala) ? $_GET['sk'] : 'blok';
$sk  = $sirala[$skAnahtar];
$yon = ($_GET['yon'] ?? '') === 'desc' ? 'DESC' : 'ASC';

// ── Excel dışa aktarma (filtrelere saygılı, sayfalamasız) ────────────────────
if (($_GET['disaaktar'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $st = $pdoPrekast->prepare("SELECT * FROM prekast_isler $wsql ORDER BY $sk $yon, id");
    $st->execute($par);
    $xl = new \XlsxWriter('Prekast İş Takip');
    $xl->header(['Çizelge','İş Tipi','No','Blok','Daire','Kesim','Kesim Tarihi','Silikon','Silikon Tarihi',
                 'Metraj','Birim Fiyat','Hakkediş','Durum','Çizelgede','Not']);
    foreach ($st->fetchAll() as $r) {
        $xl->row([
            ['v'=>$r['cizelge']], ['v'=>$r['is_tipi']], ['v'=>$r['sira'],'t'=>'number'],
            ['v'=>$r['blok']], ['v'=>$r['daire']],
            ['v'=>$r['kesim'] ? 'Yapıldı' : ''], ['v'=>$r['kesim_tarih'],'t'=>'date'],
            ['v'=>$r['silikon'] ? 'Yapıldı' : ''], ['v'=>$r['silikon_tarih'],'t'=>'date'],
            ['v'=>(float)$r['metraj'],'t'=>'number'], ['v'=>(float)$r['birim_fiyat'],'t'=>'number'],
            ['v'=>(float)$r['hakkedis'],'t'=>'number'],
            ['v'=>pk_durumAd($r['durum'])], ['v'=>$r['dosyada'] ? 'Evet' : 'Hayır'], ['v'=>$r['ic_not']],
        ]);
    }
    $xl->download('prekast_is_takip_' . date('Ymd_Hi') . '.xlsx');
}

// ── Liste (sayfalı) ──────────────────────────────────────────────────────────
$adet  = 200;
$sayfa = max(1, (int)($_GET['s'] ?? 1));
$sonSayfa = max(1, (int)ceil((int)$oz['adet'] / $adet));
if ($sayfa > $sonSayfa) $sayfa = $sonSayfa;
$atla  = ($sayfa - 1) * $adet;

$st = $pdoPrekast->prepare("SELECT * FROM prekast_isler $wsql ORDER BY $sk $yon, id LIMIT $adet OFFSET $atla");
$st->execute($par);
$liste = $st->fetchAll();

$sec = [
    'blok'    => pk_secenekler($pdoPrekast, 'blok'),
    'daire'   => pk_secenekler($pdoPrekast, 'daire'),
    'cizelge' => pk_secenekler($pdoPrekast, 'cizelge'),
];

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
// Sıralama bağlantısı: aynı sütuna tekrar tıklanırsa yön değişir
$srtUrl = function (string $k) use ($skAnahtar, $yon) {
    $q = $_GET; $q['sk'] = $k; $q['yon'] = ($skAnahtar === $k && $yon === 'ASC') ? 'desc' : 'asc'; unset($q['s']);
    return '?' . http_build_query($q);
};
$srtIkon = fn(string $k) => $skAnahtar === $k ? ' <i class="bi bi-caret-' . ($yon === 'ASC' ? 'up' : 'down') . '-fill"></i>' : '';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-list-check text-primary me-2"></i>Prekast İş Listesi</h4>
    <div class="ms-auto d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="?<?= h(http_build_query(array_merge($_GET, ['disaaktar'=>'xlsx']))) ?>" class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</a>
    </div>
</div>

<div class="row g-2 mb-3">
<?php
$kpi = [
    ['Listelenen iş', $f0($oz['adet']), 'secondary'],
    ['Tamamlanan', $f0($oz['silikon']), 'success'],
    ['Kesim yapılan', $f0($oz['kesim']), 'warning'],
    ['Daire', $f0($oz['daire']) . ' (' . $f0($oz['blok']) . ' blok)', 'info'],
    ['Metraj', $f2($oz['metraj']) . ' m', 'primary'],
    ['Hakkediş', $f0($oz['hakkedis']) . ' TL', 'dark'],
];
foreach ($kpi as [$ad, $deger, $renk]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 text-center">
            <div class="fs-6 fw-bold text-<?= $renk ?>"><?= $deger ?></div>
            <div class="small text-muted"><?= $ad ?></div>
        </div></div>
    </div>
<?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Durum</label>
            <select name="durum" class="form-select form-select-sm">
                <option value="">Tümü</option>
                <?php foreach ($GLOBALS['PK_DURUM'] as $k => $d): ?>
                    <option value="<?= h($k) ?>" <?= ($etkin['durum'] ?? '') === $k ? 'selected' : '' ?>><?= h($d['ad']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Blok</label>
            <select name="blok" class="form-select form-select-sm">
                <option value="">Tümü</option>
                <?php foreach ($sec['blok'] as $v): ?>
                    <option value="<?= h($v) ?>" <?= ($etkin['blok'] ?? '') === $v ? 'selected' : '' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Daire</label>
            <select name="daire" class="form-select form-select-sm">
                <option value="">Tümü</option>
                <?php foreach ($sec['daire'] as $v): ?>
                    <option value="<?= h($v) ?>" <?= ($etkin['daire'] ?? '') === $v ? 'selected' : '' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Çizelge</label>
            <select name="cizelge" class="form-select form-select-sm">
                <option value="">Tümü</option>
                <?php foreach ($sec['cizelge'] as $v): ?>
                    <option value="<?= h($v) ?>" <?= ($etkin['cizelge'] ?? '') === $v ? 'selected' : '' ?>><?= h(mb_strimwidth($v, 0, 46, '…')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Ara (blok / daire / not)</label>
            <input type="text" name="ara" class="form-control form-control-sm" value="<?= h($etkin['ara'] ?? '') ?>" placeholder="ör. F 74">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Tamamlanma başlangıç</label>
            <input type="date" name="bas" class="form-control form-control-sm" value="<?= h($etkin['bas'] ?? '') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Tamamlanma bitiş</label>
            <input type="date" name="bit" class="form-control form-control-sm" value="<?= h($etkin['bit'] ?? '') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Kapsam</label>
            <select name="dosyada" class="form-select form-select-sm">
                <option value="">Çizelgede duranlar</option>
                <option value="0" <?= ($etkin['dosyada'] ?? '') === '0' ? 'selected' : '' ?>>Çizelgeden düşenler</option>
                <option value="hepsi" <?= ($etkin['dosyada'] ?? '') === 'hepsi' ? 'selected' : '' ?>>Hepsi</option>
            </select>
        </div>
        <div class="col-md-6 d-flex gap-2">
            <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrele</button>
            <a href="isler.php" class="btn btn-outline-secondary btn-sm">Temizle</a>
        </div>
    </form>
</div></div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
        <thead class="table-light">
            <tr>
                <th><a href="<?= h($srtUrl('sira')) ?>" class="text-decoration-none text-reset">No<?= $srtIkon('sira') ?></a></th>
                <th><a href="<?= h($srtUrl('blok')) ?>" class="text-decoration-none text-reset">Blok<?= $srtIkon('blok') ?></a></th>
                <th><a href="<?= h($srtUrl('daire')) ?>" class="text-decoration-none text-reset">Daire<?= $srtIkon('daire') ?></a></th>
                <th><a href="<?= h($srtUrl('kesim')) ?>" class="text-decoration-none text-reset">Kesim<?= $srtIkon('kesim') ?></a></th>
                <th><a href="<?= h($srtUrl('silikon')) ?>" class="text-decoration-none text-reset">Silikon<?= $srtIkon('silikon') ?></a></th>
                <th class="text-end"><a href="<?= h($srtUrl('metraj')) ?>" class="text-decoration-none text-reset">Metraj<?= $srtIkon('metraj') ?></a></th>
                <th class="text-end">B. Fiyat</th>
                <th class="text-end"><a href="<?= h($srtUrl('hakkedis')) ?>" class="text-decoration-none text-reset">Hakkediş<?= $srtIkon('hakkedis') ?></a></th>
                <th><a href="<?= h($srtUrl('durum')) ?>" class="text-decoration-none text-reset">Durum<?= $srtIkon('durum') ?></a></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($liste as $r): ?>
            <tr class="<?= !$r['dosyada'] ? 'table-secondary' : '' ?>">
                <td class="text-muted"><?= $r['sira'] !== null ? (int)$r['sira'] : '—' ?></td>
                <td class="fw-semibold"><?= h($r['blok']) ?></td>
                <td><a href="is_detay.php?id=<?= (int)$r['id'] ?>" class="text-decoration-none"><?= h($r['daire']) ?></a>
                    <?php if ((int)$r['tekrar'] > 1): ?><span class="badge bg-light text-dark border ms-1" title="Aynı dairedeki <?= (int)$r['tekrar'] ?>. iş"><?= (int)$r['tekrar'] ?>. iş</span><?php endif; ?>
                    <?php if ($r['ic_not']): ?><i class="bi bi-sticky-fill text-warning ms-1" title="<?= h($r['ic_not']) ?>"></i><?php endif; ?>
                </td>
                <td><?php if ($r['kesim']): ?><i class="bi bi-check-circle-fill text-success"></i>
                        <span class="text-muted small"><?= $r['kesim_tarih'] ? h(date('d.m.y', strtotime($r['kesim_tarih']))) : '' ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                <td><?php if ($r['silikon']): ?><i class="bi bi-check-circle-fill text-success"></i>
                        <span class="text-muted small"><?= $r['silikon_tarih'] ? h(date('d.m.y', strtotime($r['silikon_tarih']))) : '' ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                <td class="text-end"><?= (float)$r['metraj'] > 0 ? $f2($r['metraj']) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end text-muted"><?= $f0($r['birim_fiyat']) ?></td>
                <td class="text-end fw-semibold"><?= (float)$r['hakkedis'] > 0 ? $f0($r['hakkedis']) : '<span class="text-muted fw-normal">—</span>' ?></td>
                <td><span class="badge bg-<?= h(pk_durumRenk($r['durum'])) ?>"><?= h(pk_durumAd($r['durum'])) ?></span>
                    <?php if (!$r['dosyada']): ?><span class="badge bg-dark ms-1" title="Son çizelgede yok">çizelgede yok</span><?php endif; ?></td>
                <td class="text-end"><a href="is_detay.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline-secondary btn-sm py-0"><i class="bi bi-eye"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$liste): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Kayıt bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if ($sonSayfa > 1): ?>
    <div class="card-footer bg-white d-flex align-items-center gap-2 small">
        <span class="text-muted">Sayfa <?= $sayfa ?> / <?= $sonSayfa ?> — toplam <?= $f0($oz['adet']) ?> kayıt</span>
        <div class="ms-auto btn-group btn-group-sm">
            <?php $q = $_GET; ?>
            <?php if ($sayfa > 1): $q['s'] = $sayfa - 1; ?>
                <a class="btn btn-outline-secondary" href="?<?= h(http_build_query($q)) ?>">← Önceki</a>
            <?php endif; ?>
            <?php if ($sayfa < $sonSayfa): $q['s'] = $sayfa + 1; ?>
                <a class="btn btn-outline-secondary" href="?<?= h(http_build_query($q)) ?>">Sonraki →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
