<?php
/**
 * arizalar.php — Üretim arızaları listesi
 *
 * Kayıtlar günlük CRM raporundan gelir (import.php); burası filtreleme, arama,
 * sıralama, Excel dışa aktarma ve toplu kapatma ekranıdır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_crm.php';
require_once __DIR__ . '/_ortak.php';

crm_semasi_kur($pdoCrm);
$pageTitle = 'Üretim Arızaları — CRM';
$yetkili = has_role('admin','teknik_ofis_admin');

// ── Toplu kapatma / açma ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toplu' && $yetkili) {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['sec'] ?? []))));
    $hedef = ($_POST['hedef'] ?? '') === 'acik' ? 'acik' : 'cozuldu';
    if ($ids) {
        $yer = implode(',', array_fill(0, count($ids), '?'));
        if ($hedef === 'cozuldu') {
            $pdoCrm->prepare("UPDATE crm_arizalar SET durum='cozuldu', cozumlenme=NOW(), kapanis_kaynagi='elle'
                              WHERE id IN ($yer) AND durum='acik'")->execute($ids);
        } else {
            $pdoCrm->prepare("UPDATE crm_arizalar SET durum='acik', cozumlenme=NULL, kapanis_kaynagi=NULL
                              WHERE id IN ($yer)")->execute($ids);
        }
        flash('success', count($ids) . ' arıza ' . ($hedef === 'cozuldu' ? 'çözüldü olarak işaretlendi.' : 'yeniden açıldı.'));
    } else flash('error', 'Kayıt seçilmedi.');
    redirect('arizalar.php?' . http_build_query(array_diff_key($_GET, ['s'=>1])));
}

[$wsql, $par, $etkin] = crm_filtre($_GET);

// Özet (filtreye saygılı)
$oz = $pdoCrm->prepare("SELECT COUNT(*) adet, SUM(durum='acik') acik, SUM(durum='cozuldu') cozuldu,
                               COUNT(DISTINCT konut) daire, MIN(olusturma) ilk, MAX(olusturma) son
                        FROM crm_arizalar $wsql");
$oz->execute($par);
$oz = $oz->fetch() ?: ['adet'=>0,'acik'=>0,'cozuldu'=>0,'daire'=>0,'ilk'=>null,'son'=>null];

// Sıralama — whitelist (asla ham input)
$sirala = ['tarih'=>'olusturma','konut'=>'konut','blok'=>'blok','kat'=>'kat_sira','tur'=>'sikayet_turu',
           'konu'=>'sikayet_konusu','tip'=>'ariza_tipi','durum'=>'durum','sorumlu'=>'sorumlu'];
$sk  = $sirala[$_GET['sk'] ?? ''] ?? 'olusturma';
$yon = ($_GET['yon'] ?? '') === 'asc' ? 'ASC' : 'DESC';

// ── Excel dışa aktarma (filtrelere saygılı, sayfalamasız) ────────────────────
if (($_GET['disaaktar'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $st = $pdoCrm->prepare("SELECT * FROM crm_arizalar $wsql ORDER BY $sk $yon, id DESC");
    $st->execute($par);
    $xl = new \XlsxWriter('Üretim Arızaları');
    $xl->header(['Konut','Blok','Kat','Daire No','Daire Tipi','Şikayet Türü','Şikayet Konusu','Detay',
                 'Arıza Tipi','Açıklama','Ölçek','Aciliyet','Sorumlu','Açılış','Çözüm','Durum','Gün']);
    foreach ($st->fetchAll() as $r) {
        $xl->row([
            ['v'=>$r['konut']], ['v'=>$r['blok']], ['v'=>$r['kat']], ['v'=>$r['daire_no']], ['v'=>$r['daire_tipi']],
            ['v'=>$r['sikayet_turu']], ['v'=>$r['sikayet_konusu']], ['v'=>$r['sikayet_aciklamasi']],
            ['v'=>$r['ariza_tipi']], ['v'=>$r['aciklama']], ['v'=>$r['olcek']], ['v'=>$r['aciliyet']],
            ['v'=>$r['sorumlu']],
            ['v'=>$r['olusturma'] ? substr($r['olusturma'],0,10) : null,'t'=>'date'],
            ['v'=>$r['cozumlenme'] ? substr($r['cozumlenme'],0,10) : null,'t'=>'date'],
            ['v'=>crm_durumAd($r['durum'])], ['v'=>crm_yas($r),'t'=>'number'],
        ]);
    }
    $xl->download('crm_uretim_arizalari_' . date('Ymd_Hi') . '.xlsx');
}

// ── Liste (sayfalı) ──────────────────────────────────────────────────────────
$adet  = 100;
$sayfa = max(1, (int)($_GET['s'] ?? 1));
$sonSayfa = max(1, (int)ceil((int)$oz['adet'] / $adet));
if ($sayfa > $sonSayfa) $sayfa = $sonSayfa;
$atla  = ($sayfa - 1) * $adet;

$st = $pdoCrm->prepare("SELECT * FROM crm_arizalar $wsql ORDER BY $sk $yon, id DESC LIMIT $adet OFFSET $atla");
$st->execute($par);
$liste = $st->fetchAll();

$sec = [
    'blok'    => crm_secenekler($pdoCrm, 'blok'),
    'kat'     => crm_secenekler($pdoCrm, 'kat'),
    'tur'     => crm_secenekler($pdoCrm, 'sikayet_turu'),
    'konu'    => crm_secenekler($pdoCrm, 'sikayet_konusu'),
    'detay'   => crm_secenekler($pdoCrm, 'sikayet_aciklamasi'),
    'sorumlu' => crm_secenekler($pdoCrm, 'sorumlu'),
];

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$qs = function (array $ek = []) {
    $p = array_merge($_GET, $ek);
    unset($p['disaaktar']);
    return 'arizalar.php?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};
$bas = function (string $anahtar, string $etiket) use ($qs) {
    $aktif = ($_GET['sk'] ?? 'tarih') === $anahtar;
    $yeni  = ($aktif && ($_GET['yon'] ?? 'desc') === 'desc') ? 'asc' : 'desc';
    $ok    = $aktif ? (($_GET['yon'] ?? 'desc') === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>') : '';
    return '<a href="' . h($qs(['sk'=>$anahtar,'yon'=>$yeni,'s'=>1])) . '" class="text-decoration-none text-reset">' . h($etiket) . $ok . '</a>';
};

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-tools text-primary me-2"></i>Üretim Arızaları</h4>
    <div class="ms-auto d-flex gap-2">
        <a href="<?= h($qs(['disaaktar'=>'xlsx'])) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        <?php if ($yetkili): ?><a href="import.php" class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Günlük Rapor</a><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="row g-2 mb-3">
    <?php
    $kpi = [
        ['Kayıt', $oz['adet'], 'secondary', 'bi-list-ul'],
        ['Açık', $oz['acik'], 'danger', 'bi-exclamation-circle'],
        ['Çözülen', $oz['cozuldu'], 'success', 'bi-check-circle'],
        ['Daire', $oz['daire'], 'primary', 'bi-house-door'],
    ];
    foreach ($kpi as [$ad, $deger, $renk, $ikon]): ?>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 d-flex align-items-center gap-2">
            <i class="bi <?= $ikon ?> text-<?= $renk ?> fs-4"></i>
            <div><div class="fs-5 fw-bold"><?= $f0($deger) ?></div><div class="small text-muted"><?= $ad ?></div></div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end small">
        <div class="col-6 col-md-2">
            <label class="form-label mb-1">Durum</label>
            <select name="durum" class="form-select form-select-sm">
                <option value="">Tümü</option>
                <?php foreach ($GLOBALS['CRM_DURUM'] as $k => $d): ?>
                <option value="<?= $k ?>" <?= ($etkin['durum'] ?? '') === $k ? 'selected' : '' ?>><?= h($d['ad']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach ([['blok','Blok'],['kat','Kat'],['tur','Şikayet Türü'],['konu','Konu'],['detay','Detay'],['sorumlu','Sorumlu']] as [$par2,$et]): ?>
        <div class="col-6 col-md-2">
            <label class="form-label mb-1"><?= $et ?></label>
            <select name="<?= $par2 ?>" class="form-select form-select-sm">
                <option value="">Tümü</option>
                <?php foreach ($sec[$par2] as $v): ?>
                <option value="<?= h($v) ?>" <?= ($etkin[$par2] ?? '') === $v ? 'selected' : '' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endforeach; ?>
        <div class="col-6 col-md-2">
            <label class="form-label mb-1">Açılış (baş.)</label>
            <input type="date" name="bas" class="form-control form-control-sm" value="<?= h($etkin['bas'] ?? '') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label mb-1">Açılış (bitiş)</label>
            <input type="date" name="bit" class="form-control form-control-sm" value="<?= h($etkin['bit'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label mb-1">Ara <span class="text-muted">(konut, daire no, açıklama, arıza tipi)</span></label>
            <input type="text" name="ara" class="form-control form-control-sm" value="<?= h($etkin['ara'] ?? '') ?>" placeholder="ör. cam çizik">
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-funnel"></i> Filtrele</button>
            <a href="arizalar.php" class="btn btn-outline-secondary btn-sm">Sıfırla</a>
        </div>
    </form>
</div></div>

<form method="post" id="topluForm">
<input type="hidden" name="action" value="toplu">
<input type="hidden" name="hedef" id="topluHedef" value="cozuldu">
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
        <thead class="table-light">
            <tr>
                <?php if ($yetkili): ?><th style="width:28px"><input type="checkbox" id="hepsi" class="form-check-input"></th><?php endif; ?>
                <th><?= $bas('konut','Konut') ?></th>
                <th><?= $bas('blok','Blok') ?></th>
                <th><?= $bas('kat','Kat') ?></th>
                <th><?= $bas('tur','Tür') ?></th>
                <th><?= $bas('konu','Konu') ?></th>
                <th>Detay</th>
                <th><?= $bas('tip','Arıza Tipi') ?></th>
                <th>Açıklama</th>
                <th><?= $bas('tarih','Açılış') ?></th>
                <th class="text-end">Gün</th>
                <th><?= $bas('durum','Durum') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($liste as $r): $yas = crm_yas($r); ?>
            <tr class="<?= $r['durum'] === 'acik' && $yas > 90 ? 'table-warning' : '' ?>">
                <?php if ($yetkili): ?><td><input type="checkbox" name="sec[]" value="<?= (int)$r['id'] ?>" class="form-check-input satir"></td><?php endif; ?>
                <td><a href="ariza_detay.php?id=<?= (int)$r['id'] ?>" class="text-decoration-none fw-semibold"><?= h($r['konut']) ?></a></td>
                <td><?= h($r['blok']) ?></td>
                <td class="text-nowrap"><?= h($r['kat']) ?></td>
                <td><?= h($r['sikayet_turu']) ?></td>
                <td><?= h($r['sikayet_konusu']) ?></td>
                <td class="text-muted"><?= h($r['sikayet_aciklamasi']) ?></td>
                <td class="text-truncate" style="max-width:190px" title="<?= h($r['ariza_tipi']) ?>"><?= h($r['ariza_tipi']) ?></td>
                <td class="text-truncate text-muted" style="max-width:230px" title="<?= h($r['aciklama']) ?>"><?= h($r['aciklama']) ?></td>
                <td class="text-nowrap"><?= $r['olusturma'] ? h(date('d.m.Y', strtotime($r['olusturma']))) : '—' ?></td>
                <td class="text-end <?= $r['durum'] === 'acik' && $yas > 90 ? 'text-danger fw-bold' : '' ?>"><?= $f0($yas) ?></td>
                <td><span class="badge bg-<?= crm_durumRenk($r['durum']) ?>"><?= h(crm_durumAd($r['durum'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$liste): ?>
            <tr><td colspan="12" class="text-center text-muted py-4">
                <i class="bi bi-inbox me-1"></i>Kayıt bulunamadı.
                <?php if (!(int)$oz['adet'] && !$etkin): ?><br><a href="import.php">Günlük CRM raporunu yükleyerek</a> başlayın.<?php endif; ?>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php if ($liste): ?>
<div class="card-footer d-flex flex-wrap align-items-center gap-2 py-2 small">
    <?php if ($yetkili): ?>
    <button type="submit" class="btn btn-success btn-sm" onclick="document.getElementById('topluHedef').value='cozuldu'">
        <i class="bi bi-check2-all me-1"></i>Seçilenleri Çözüldü yap</button>
    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="document.getElementById('topluHedef').value='acik'">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Yeniden aç</button>
    <?php endif; ?>
    <span class="text-muted ms-auto">
        <?= $f0(($sayfa-1)*$adet + 1) ?>–<?= $f0(min($sayfa*$adet, (int)$oz['adet'])) ?> / <?= $f0($oz['adet']) ?> kayıt
    </span>
    <?php if ($sonSayfa > 1): ?>
    <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary <?= $sayfa<=1?'disabled':'' ?>" href="<?= h($qs(['s'=>$sayfa-1])) ?>">‹</a>
        <span class="btn btn-outline-secondary disabled"><?= $sayfa ?> / <?= $sonSayfa ?></span>
        <a class="btn btn-outline-secondary <?= $sayfa>=$sonSayfa?'disabled':'' ?>" href="<?= h($qs(['s'=>$sayfa+1])) ?>">›</a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>
</form>

<script>
(function(){
    var hepsi = document.getElementById('hepsi');
    if (!hepsi) return;
    hepsi.addEventListener('change', function(){
        document.querySelectorAll('.satir').forEach(function(c){ c.checked = hepsi.checked; });
    });
    document.getElementById('topluForm').addEventListener('submit', function(e){
        if (!document.querySelectorAll('.satir:checked').length) { e.preventDefault(); alert('Önce kayıt seçin.'); }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
