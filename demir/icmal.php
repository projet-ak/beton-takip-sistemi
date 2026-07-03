<?php
/**
 * demir/icmal.php — Gelen demir icmali (mutabakat)
 * Çap ve tedarikçi bazında irsaliye/kantar/fark özeti.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Demir İcmal — Demir Takip';

// ── Filtreler ─────────────────────────────────────────────────────────────────
$fProje  = isset($_GET['proje']) && ctype_digit($_GET['proje']) ? (int)$_GET['proje'] : 0;
$tBas    = isset($_GET['tarih_bas']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tarih_bas']) ? $_GET['tarih_bas'] : '';
$tBit    = isset($_GET['tarih_bit']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tarih_bit']) ? $_GET['tarih_bit'] : '';

$where = []; $params = [];
if ($fProje) { $where[] = 's.proje_id = ?';       $params[] = $fProje; }
if ($tBas)   { $where[] = 's.irsaliye_tarih >= ?'; $params[] = $tBas; }
if ($tBit)   { $where[] = 's.irsaliye_tarih <= ?'; $params[] = $tBit; }
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── AJAX: bir çapa ait sevkiyatlar (irsaliyeler) + siparişler (popup içeriği) ──
if (isset($_GET['cap_detay']) && ctype_digit($_GET['cap_detay'])) {
    $capId = (int)$_GET['cap_detay'];
    $capAd = $pdoDemir->prepare("SELECT ad FROM demir_caplar WHERE id=?"); $capAd->execute([$capId]);
    $capAd = $capAd->fetchColumn() ?: ('#'.$capId);

    // Sevkiyatlar (bu çapı içeren) — mevcut filtrelerle
    $sw = ['sk.cap_id = ?']; $sp = [$capId];
    if ($fProje) { $sw[] = 's.proje_id = ?';        $sp[] = $fProje; }
    if ($tBas)   { $sw[] = 's.irsaliye_tarih >= ?'; $sp[] = $tBas; }
    if ($tBit)   { $sw[] = 's.irsaliye_tarih <= ?'; $sp[] = $tBit; }
    $sq = $pdoDemir->prepare("
        SELECT s.id, s.irsaliye_no, s.irsaliye_tarih, s.ifs_siparis_no,
               t.ad AS tedarikci, p.kod AS proje,
               sk.irsaliye_miktar AS irs, sk.kantar_miktar AS knt
        FROM demir_sevkiyat_kalemleri sk
        JOIN demir_sevkiyatlar s ON s.id = sk.sevkiyat_id
        LEFT JOIN demir_tedarikciler t ON t.id = s.tedarikci_id
        LEFT JOIN demir_projeler p ON p.id = s.proje_id
        WHERE " . implode(' AND ', $sw) . "
        ORDER BY s.irsaliye_tarih DESC, s.id DESC");
    $sq->execute($sp);
    $sevkler = $sq->fetchAll();

    // Bu çapı içeren siparişler
    $qp = $pdoDemir->prepare("
        SELECT sp.id, sp.ifs_siparis_no, ta.ad AS taseron, pr.kod AS proje,
               sk.miktar_ton AS miktar
        FROM demir_siparis_kalemleri sk
        JOIN demir_siparisler sp ON sp.id = sk.siparis_id
        LEFT JOIN demir_taseronlar ta ON ta.id = sp.taseron_id
        LEFT JOIN demir_projeler pr ON pr.id = sp.proje_id
        WHERE sk.cap_id = ?
        ORDER BY sp.ifs_siparis_no");
    $qp->execute([$capId]);
    $sipler = $qp->fetchAll();

    $fmt = fn($n) => number_format((float)$n, 3, ',', '.');
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <div class="mb-3">
        <h6 class="fw-bold mb-2"><i class="bi bi-truck text-primary me-1"></i> Bu çaptaki sevkiyatlar (irsaliyeler) — <?= count($sevkler) ?></h6>
        <div class="table-responsive" style="max-height:300px">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>İrsaliye No</th><th>Tarih</th><th>Tedarikçi</th><th>Proje</th><th class="text-end">İrsaliye (t)</th><th class="text-end">Kantar (t)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sevkler as $s): ?>
                <tr>
                    <td class="font-monospace small"><?= h($s['irsaliye_no'] ?: '—') ?></td>
                    <td class="text-nowrap"><?= format_date($s['irsaliye_tarih']) ?></td>
                    <td><?= h($s['tedarikci'] ?: '—') ?></td>
                    <td><?= $s['proje'] ? '<span class="badge bg-secondary">'.h($s['proje']).'</span>' : '—' ?></td>
                    <td class="text-end"><?= $fmt($s['irs']) ?></td>
                    <td class="text-end"><?= $fmt($s['knt']) ?></td>
                    <td class="text-end"><a href="sevkiyat_form.php?id=<?= (int)$s['id'] ?>" class="btn btn-xs btn-outline-primary" title="İrsaliyeye git"><i class="bi bi-box-arrow-up-right"></i></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sevkler): ?><tr><td colspan="7" class="text-center text-muted py-3">Bu çapta sevkiyat yok.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <div>
        <h6 class="fw-bold mb-2"><i class="bi bi-cart-check text-primary me-1"></i> Bu çapı içeren siparişler — <?= count($sipler) ?></h6>
        <div class="table-responsive" style="max-height:220px">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>IFS Sipariş No</th><th>Taşeron</th><th>Proje</th><th class="text-end">Sipariş (t)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($sipler as $sp2): ?>
                <tr>
                    <td class="font-monospace small fw-semibold"><?= h($sp2['ifs_siparis_no'] ?: '—') ?></td>
                    <td><?= h($sp2['taseron'] ?: '—') ?></td>
                    <td><?= $sp2['proje'] ? '<span class="badge bg-secondary">'.h($sp2['proje']).'</span>' : '—' ?></td>
                    <td class="text-end"><?= $fmt($sp2['miktar']) ?></td>
                    <td class="text-end"><a href="siparis_detay.php?id=<?= (int)$sp2['id'] ?>" class="btn btn-xs btn-outline-dark" title="Sipariş detayı"><i class="bi bi-box-arrow-up-right"></i></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sipler): ?><tr><td colspan="5" class="text-center text-muted py-3">Bu çapı içeren sipariş yok.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
    exit;
}

// ── Çap bazında (tüm çaplar; filtre alt sorguda) ──────────────────────────────
$capIcmal = $pdoDemir->prepare("
    SELECT c.id, c.ad, c.tip,
           COALESCE(agg.irs,0) AS irs,
           COALESCE(agg.knt,0) AS knt
    FROM demir_caplar c
    LEFT JOIN (
        SELECT sk.cap_id,
               SUM(sk.irsaliye_miktar) AS irs,
               SUM(sk.kantar_miktar)   AS knt
        FROM demir_sevkiyat_kalemleri sk
        JOIN demir_sevkiyatlar s ON s.id = sk.sevkiyat_id
        $whereSQL
        GROUP BY sk.cap_id
    ) agg ON agg.cap_id = c.id
    GROUP BY c.id ORDER BY c.sira, c.ad
");
$capIcmal->execute($params);
$capRows = $capIcmal->fetchAll();

// ── Tedarikçi bazında ─────────────────────────────────────────────────────────
$tedSQL = "
    SELECT t.ad AS firma,
           COALESCE(SUM(sk.irsaliye_miktar),0) AS irs,
           COALESCE(SUM(sk.kantar_miktar),0)   AS knt
    FROM demir_sevkiyatlar s
    JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id = s.id
    LEFT JOIN demir_tedarikciler t ON t.id = s.tedarikci_id
    $whereSQL
    GROUP BY s.tedarikci_id ORDER BY irs DESC";
$tedIcmal = $pdoDemir->prepare($tedSQL);
$tedIcmal->execute($params);
$tedRows = $tedIcmal->fetchAll();

$totIrs = array_sum(array_column($capRows,'irs'));
$totKnt = array_sum(array_column($capRows,'knt'));
$totFark= $totKnt - $totIrs;

$projeler = $pdoDemir->query("SELECT id,kod,aciklama FROM demir_projeler WHERE aktif=1 ORDER BY kod")->fetchAll();

// ── Excel dışa aktarma ────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new XlsxWriter('Demir İcmal');
    $xl->header(['Çap', 'Tür', 'İrsaliye (t)', 'Kantar (t)', 'Fark (t)']);
    foreach ($capRows as $r) {
        if ($r['irs'] == 0 && $r['knt'] == 0) continue;
        $xl->row([
            ['v'=>$r['ad'],'t'=>'text'], ['v'=>$r['tip'],'t'=>'text'],
            ['v'=>(float)$r['irs'],'t'=>'number'], ['v'=>(float)$r['knt'],'t'=>'number'],
            ['v'=>(float)$r['knt']-(float)$r['irs'],'t'=>'number'],
        ]);
    }
    $xl->row([['v'=>'TOPLAM','t'=>'text'],['v'=>'','t'=>'text'],['v'=>$totIrs,'t'=>'number'],['v'=>$totKnt,'t'=>'number'],['v'=>$totFark,'t'=>'number']]);
    $xl->row([['v'=>'','t'=>'text']]);
    $xl->row([['v'=>'TEDARİKÇİ BAZINDA','t'=>'text']]);
    $xl->row([['v'=>'Tedarikçi','t'=>'text'],['v'=>'','t'=>'text'],['v'=>'İrsaliye (t)','t'=>'text'],['v'=>'Kantar (t)','t'=>'text'],['v'=>'Fark (t)','t'=>'text']]);
    foreach ($tedRows as $r) {
        $xl->row([
            ['v'=>$r['firma'] ?: '(tanımsız)','t'=>'text'], ['v'=>'','t'=>'text'],
            ['v'=>(float)$r['irs'],'t'=>'number'], ['v'=>(float)$r['knt'],'t'=>'number'],
            ['v'=>(float)$r['knt']-(float)$r['irs'],'t'=>'number'],
        ]);
    }
    $xl->download('demir_icmal_' . date('Ymd_His') . '.xlsx');
}

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>

<?php $__qs = http_build_query(array_filter(['proje'=>$fProje,'tarih_bas'=>$tBas,'tarih_bit'=>$tBit])); ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-clipboard-data text-dark me-2"></i>Demir İcmal — Gelen Demir Mutabakatı</h4>
    <div class="d-flex gap-2">
        <a href="icmal.php?<?= $__qs ? $__qs.'&' : '' ?>export=xlsx" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i> Excel</a>
        <a href="icmal_pdf.php?<?= h($__qs) ?>" target="_blank" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer me-1"></i> PDF</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3"><label class="form-label small">Proje</label><select name="proje" class="form-select form-select-sm"><option value="">Tümü</option><?php foreach($projeler as $p): ?><option value="<?= $p['id'] ?>" <?= $fProje==$p['id']?'selected':'' ?>><?= h($p['kod']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small">Başlangıç</label><input type="date" name="tarih_bas" class="form-control form-control-sm" value="<?= h($tBas) ?>"></div>
            <div class="col-md-3"><label class="form-label small">Bitiş</label><input type="date" name="tarih_bit" class="form-control form-control-sm" value="<?= h($tBit) ?>"></div>
            <div class="col-md-3 d-flex gap-1"><button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-funnel"></i> Filtrele</button><a href="icmal.php" class="btn btn-outline-secondary btn-sm">Temizle</a></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam İrsaliye</div><div class="fs-4 fw-bold"><?= $fmt($totIrs) ?> t</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Toplam Kantar</div><div class="fs-4 fw-bold"><?= $fmt($totKnt) ?> t</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Kantar Farkı</div><div class="fs-4 fw-bold <?= abs($totFark)<0.0005?'':($totFark<0?'text-danger':'text-success') ?>"><?= ($totFark>0?'+':'').$fmt($totFark) ?> t</div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-rulers text-primary me-1"></i> Çap Bazında</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Çap</th><th>Tür</th><th class="text-end">İrsaliye (t)</th><th class="text-end">Kantar (t)</th><th class="text-end">Fark</th></tr></thead>
                    <tbody>
                    <?php foreach ($capRows as $r): $f=(float)$r['knt']-(float)$r['irs']; if($r['irs']==0 && $r['knt']==0) continue; ?>
                        <tr>
                            <td><a href="#" class="fw-semibold text-decoration-none cap-detay" data-cap="<?= (int)$r['id'] ?>" data-ad="<?= h($r['ad']) ?>"><?= h($r['ad']) ?> <i class="bi bi-search small opacity-50"></i></a></td>
                            <td><span class="badge bg-light text-muted border"><?= h($r['tip']) ?></span></td>
                            <td class="text-end"><a href="#" class="text-decoration-none cap-detay" data-cap="<?= (int)$r['id'] ?>" data-ad="<?= h($r['ad']) ?>"><?= $fmt($r['irs']) ?></a></td>
                            <td class="text-end"><?= $fmt($r['knt']) ?></td>
                            <td class="text-end <?= abs($f)<0.0005?'text-muted':($f<0?'text-danger':'text-success') ?>"><?= abs($f)<0.0005?'0':(($f>0?'+':'').$fmt($f)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($totIrs==0): ?><tr><td colspan="5" class="text-center text-muted py-4">Bu filtrede sevkiyat yok.</td></tr><?php endif; ?>
                    </tbody>
                    <?php if ($totIrs>0): ?>
                    <tfoot class="table-light fw-bold"><tr><td colspan="2">TOPLAM</td><td class="text-end"><?= $fmt($totIrs) ?></td><td class="text-end"><?= $fmt($totKnt) ?></td><td class="text-end <?= abs($totFark)<0.0005?'':($totFark<0?'text-danger':'text-success') ?>"><?= ($totFark>0?'+':'').$fmt($totFark) ?></td></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-shop text-primary me-1"></i> Tedarikçi Bazında</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Tedarikçi</th><th class="text-end">İrsaliye (t)</th><th class="text-end">Kantar (t)</th><th class="text-end">Fark</th></tr></thead>
                    <tbody>
                    <?php foreach ($tedRows as $r): $f=(float)$r['knt']-(float)$r['irs']; ?>
                        <tr>
                            <td class="fw-semibold"><?= h($r['firma'] ?: '—') ?></td>
                            <td class="text-end"><?= $fmt($r['irs']) ?></td>
                            <td class="text-end"><?= $fmt($r['knt']) ?></td>
                            <td class="text-end <?= abs($f)<0.0005?'text-muted':($f<0?'text-danger':'text-success') ?>"><?= abs($f)<0.0005?'0':(($f>0?'+':'').$fmt($f)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$tedRows): ?><tr><td colspan="4" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
        <div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>Sipariş/teslim mutabakatı, sipariş ve tutanak modülleri eklendiğinde bu ekrana katılacak.</div>
    </div>
</div>

<!-- Çap detay popup -->
<div class="modal fade" id="capModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-rulers me-1"></i> <span id="capModalAd">Çap</span> — Sevkiyat & Sipariş</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>
      <div class="modal-body" id="capModalBody">
        <div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Yükleniyor…</div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
    var filtreQS = <?= json_encode($__qs, JSON_UNESCAPED_UNICODE) ?>;
    var modalEl = document.getElementById('capModal');
    var modal = new bootstrap.Modal(modalEl);
    var body = document.getElementById('capModalBody');
    var adEl = document.getElementById('capModalAd');
    document.querySelectorAll('.cap-detay').forEach(function(a){
        a.addEventListener('click', function(e){
            e.preventDefault();
            var cap = this.getAttribute('data-cap');
            adEl.textContent = this.getAttribute('data-ad') || 'Çap';
            body.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Yükleniyor…</div>';
            modal.show();
            var url = 'icmal.php?cap_detay=' + encodeURIComponent(cap) + (filtreQS ? '&' + filtreQS : '');
            fetch(url, {headers:{'X-Requested-With':'fetch'}})
                .then(function(r){ return r.text(); })
                .then(function(html){ body.innerHTML = html; })
                .catch(function(){ body.innerHTML = '<div class="alert alert-danger mb-0">İçerik yüklenemedi.</div>'; });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
