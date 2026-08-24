<?php
/**
 * cikislar.php — Günlük mazot çıkışı (elle kayıt) + teslim tutanağı
 *
 * Depodan bir araca/kişiye mazot verildiğinde buradan kaydedilir ve
 * ERN Taahhüt logolu A4 teslim tutanağı yazdırılır (cikis_tutanak.php).
 *
 * ⚠ Stok dönem zinciri EXCEL'den beslenir (Excel tek doğru kaynak) — buradaki
 *   kayıtlar Excel'in yerine geçmez; günlük işleyişi ve imzalı evrakı sağlar.
 *   Ay sonunda değerler Excel'e işlenir, içe aktarma stok zincirini kurar.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';

$pageTitle = 'Mazot Çıkışları — Akaryakıt';

$pdoAkaryakit->exec("CREATE TABLE IF NOT EXISTS akaryakit_cikislar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tarih DATE NOT NULL,
    arac_id INT NULL,
    sofor VARCHAR(120) NULL,
    cinsi VARCHAR(120) NULL,
    firma VARCHAR(120) NULL,
    plaka VARCHAR(40) NULL,
    miktar_lt DECIMAL(10,2) NOT NULL DEFAULT 0,
    sayac VARCHAR(40) NULL COMMENT 'km / makine saati okuması',
    teslim_eden VARCHAR(120) NULL,
    teslim_alan VARCHAR(120) NULL,
    aciklama VARCHAR(255) NULL,
    created_by INT NULL,
    created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (tarih), KEY (arac_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Kaydet / güncelle ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kaydet') {
    $id     = (int)($_POST['id'] ?? 0);
    $miktar = ak_sayi($_POST['miktar'] ?? '');
    $tarih  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['tarih'] ?? '') ? $_POST['tarih'] : date('Y-m-d');
    $aracId = isset($_POST['arac_id']) && ctype_digit((string)$_POST['arac_id']) && (int)$_POST['arac_id'] > 0 ? (int)$_POST['arac_id'] : null;
    $sofor  = trim((string)($_POST['sofor'] ?? ''));
    if ($miktar <= 0 || ($sofor === '' && !$aracId)) {
        flash('error', 'Miktar sıfırdan büyük olmalı; araç seçin ya da şoför adı yazın.');
    } else {
        // Araç seçildiyse boş alanları araç kartından doldur
        if ($aracId) {
            $a = $pdoAkaryakit->prepare("SELECT sofor,cinsi,firma,plaka FROM akaryakit_araclar WHERE id=?");
            $a->execute([$aracId]);
            if ($ar = $a->fetch()) {
                if ($sofor === '') $sofor = $ar['sofor'];
                foreach (['cinsi','firma','plaka'] as $k) if (trim((string)($_POST[$k] ?? '')) === '') $_POST[$k] = $ar[$k];
            } else $aracId = null;
        }
        $alan = [$tarih, $aracId, $sofor ?: null,
                 trim((string)($_POST['cinsi'] ?? '')) ?: null, trim((string)($_POST['firma'] ?? '')) ?: null,
                 trim((string)($_POST['plaka'] ?? '')) ?: null, $miktar,
                 trim((string)($_POST['sayac'] ?? '')) ?: null,
                 trim((string)($_POST['teslim_eden'] ?? '')) ?: null, trim((string)($_POST['teslim_alan'] ?? '')) ?: null,
                 trim((string)($_POST['aciklama'] ?? '')) ?: null];
        if ($id) {
            $st = $pdoAkaryakit->prepare("UPDATE akaryakit_cikislar SET tarih=?, arac_id=?, sofor=?, cinsi=?, firma=?,
                plaka=?, miktar_lt=?, sayac=?, teslim_eden=?, teslim_alan=?, aciklama=? WHERE id=?");
            $st->execute(array_merge($alan, [$id]));
        } else {
            $st = $pdoAkaryakit->prepare("INSERT INTO akaryakit_cikislar
                (tarih,arac_id,sofor,cinsi,firma,plaka,miktar_lt,sayac,teslim_eden,teslim_alan,aciklama,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute(array_merge($alan, [current_user_id()]));
            $id = (int)$pdoAkaryakit->lastInsertId();
        }
        flash('success', number_format($miktar, 0, ',', '.') . ' Lt mazot çıkışı kaydedildi.');
        redirect('cikislar.php?tutanak=' . $id);
    }
    redirect('cikislar.php');
}
if (isset($_GET['sil']) && ctype_digit($_GET['sil']) && has_role('admin','teknik_ofis_admin')) {
    $pdoAkaryakit->prepare("DELETE FROM akaryakit_cikislar WHERE id=?")->execute([(int)$_GET['sil']]);
    flash('success', 'Çıkış kaydı silindi.');
    redirect('cikislar.php');
}

// ── Liste + filtre ───────────────────────────────────────────────────────────
$fAy = preg_match('/^\d{4}-\d{2}$/', $_GET['ay'] ?? '') ? $_GET['ay'] : date('Y-m');
$where = "WHERE DATE_FORMAT(tarih,'%Y-%m') = ?";
$st = $pdoAkaryakit->prepare("SELECT * FROM akaryakit_cikislar $where ORDER BY tarih DESC, id DESC");
$st->execute([$fAy]);
$liste = $st->fetchAll();
$oz = $pdoAkaryakit->prepare("SELECT COUNT(*) adet, COALESCE(SUM(miktar_lt),0) lt FROM akaryakit_cikislar $where");
$oz->execute([$fAy]);
$oz = $oz->fetch();

$araclar = $pdoAkaryakit->query("SELECT id, sofor, cinsi, firma, plaka FROM akaryakit_araclar WHERE aktif=1 ORDER BY sofor")->fetchAll();
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-fuel-pump-diesel text-primary me-2"></i>Mazot Çıkışları</h4>
        <small class="text-muted">Günlük çıkış kaydı + imzalı teslim tutanağı · stok zinciri ay sonunda Excel'le eşitlenir</small>
    </div>
    <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cikisModal" onclick="cikisAc()">
        <i class="bi bi-plus-circle me-1"></i>Yeni Çıkış</button>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?>
<?php if ($t==='success' && isset($_GET['tutanak']) && ctype_digit($_GET['tutanak'])): ?>
 <a href="cikis_tutanak.php?id=<?= (int)$_GET['tutanak'] ?>" target="_blank" class="alert-link"><i class="bi bi-printer me-1"></i>Teslim tutanağını yazdır</a>
<?php endif; ?></div><?php endif; endforeach; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Ay</div>
        <form><input type="month" name="ay" value="<?= h($fAy) ?>" class="form-control form-control-sm" onchange="this.form.submit()"></form>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Çıkış Kaydı</div><div class="fs-5 fw-bold"><?= (int)$oz['adet'] ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Toplam Çıkış</div><div class="fs-5 fw-bold text-danger"><?= $fmt0($oz['lt']) ?> Lt</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Not</div>
        <div class="small text-muted">Excel aylık sayfasına da işlenmeli — stok zinciri Excel'den kurulur.</div></div></div></div>
</div>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.84rem">
    <thead class="table-light"><tr>
        <th>Tarih</th><th>Şoför</th><th>Cinsi</th><th>Firma</th><th>Plaka</th>
        <th class="text-end">Miktar (Lt)</th><th>Sayaç</th><th>Teslim Alan</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($liste as $r): ?>
        <tr>
            <td class="text-nowrap"><?= format_date($r['tarih']) ?></td>
            <td class="fw-semibold"><?= h($r['sofor'] ?: '—') ?></td>
            <td class="small"><?= h($r['cinsi'] ?: '—') ?></td>
            <td class="small"><?= h($r['firma'] ?: '—') ?></td>
            <td class="small font-monospace"><?= h($r['plaka'] ?: '—') ?></td>
            <td class="text-end fw-bold text-danger"><?= $fmt0($r['miktar_lt']) ?></td>
            <td class="small text-muted"><?= h($r['sayac'] ?: '—') ?></td>
            <td class="small"><?= h($r['teslim_alan'] ?: '—') ?></td>
            <td class="text-end text-nowrap">
                <a href="cikis_tutanak.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0" title="Teslim tutanağı"><i class="bi bi-printer"></i></a>
                <button class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="modal" data-bs-target="#cikisModal" title="Düzenle"
                        onclick='cikisAc(<?= json_encode(['id'=>(int)$r['id'],'tarih'=>$r['tarih'],'arac_id'=>(int)($r['arac_id']??0),'sofor'=>(string)$r['sofor'],'cinsi'=>(string)$r['cinsi'],'firma'=>(string)$r['firma'],'plaka'=>(string)$r['plaka'],'miktar'=>(float)$r['miktar_lt'],'sayac'=>(string)$r['sayac'],'teslim_eden'=>(string)$r['teslim_eden'],'teslim_alan'=>(string)$r['teslim_alan'],'aciklama'=>(string)$r['aciklama']], JSON_HEX_APOS|JSON_UNESCAPED_UNICODE) ?>)'><i class="bi bi-pencil"></i></button>
                <?php if (has_role('admin','teknik_ofis_admin')): ?>
                <a href="?sil=<?= (int)$r['id'] ?>&ay=<?= h($fAy) ?>" class="btn btn-sm btn-outline-danger py-0" onclick="return confirm('Çıkış kaydı silinsin mi?')"><i class="bi bi-trash"></i></a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$liste): ?><tr><td colspan="9" class="text-center text-muted py-4">Bu ayda çıkış kaydı yok — "Yeni Çıkış" ile ekleyin.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($liste): ?>
    <tfoot class="table-light fw-bold"><tr>
        <td colspan="5" class="text-end">TOPLAM</td><td class="text-end text-danger"><?= $fmt0($oz['lt']) ?></td><td colspan="3"></td>
    </tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>

<!-- Çıkış modal -->
<div class="modal fade" id="cikisModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post">
        <input type="hidden" name="action" value="kaydet">
        <input type="hidden" name="id" id="cId" value="0">
        <div class="modal-header"><h6 class="modal-title"><i class="bi bi-fuel-pump-diesel me-1"></i>Mazot Çıkışı</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-3">
            <div class="col-md-4"><label class="form-label">Tarih <span class="text-danger">*</span></label>
                <input type="date" name="tarih" id="cTarih" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-8">
                <label class="form-label">Araç / Makine <span class="text-muted small">(seçilirse alanlar otomatik dolar)</span></label>
                <select name="arac_id" id="cArac" class="form-select" onchange="aracSecildi()">
                    <option value="0">— listede yok / elle gireceğim —</option>
                    <?php foreach ($araclar as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" data-sofor="<?= h($a['sofor']) ?>" data-cinsi="<?= h($a['cinsi']) ?>"
                            data-firma="<?= h((string)$a['firma']) ?>" data-plaka="<?= h((string)$a['plaka']) ?>">
                        <?= h($a['sofor']) ?> — <?= h($a['cinsi']) ?><?= $a['firma'] ? ' ('.h($a['firma']).')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4"><label class="form-label">Şoför</label><input type="text" name="sofor" id="cSofor" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Cinsi</label><input type="text" name="cinsi" id="cCinsi" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Firma</label><input type="text" name="firma" id="cFirma" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Plaka</label><input type="text" name="plaka" id="cPlaka" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Miktar (Lt) <span class="text-danger">*</span></label>
                <input type="text" name="miktar" id="cMiktar" class="form-control" required inputmode="decimal"></div>
            <div class="col-md-4"><label class="form-label">Sayaç <span class="text-muted small">(km / mak. saati)</span></label>
                <input type="text" name="sayac" id="cSayac" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Teslim Eden</label><input type="text" name="teslim_eden" id="cTeslimEden" class="form-control" placeholder="Depo görevlisi"></div>
            <div class="col-md-4"><label class="form-label">Teslim Alan</label><input type="text" name="teslim_alan" id="cTeslimAlan" class="form-control" placeholder="boşsa şoför"></div>
            <div class="col-md-4"><label class="form-label">Açıklama</label><input type="text" name="aciklama" id="cAciklama" class="form-control"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
            <button class="btn btn-danger btn-sm"><i class="bi bi-save me-1"></i>Kaydet</button>
        </div>
    </form>
</div></div></div>

<script>
function aracSecildi(){
    var o = document.getElementById('cArac').selectedOptions[0];
    if (!o || o.value === '0') return;
    document.getElementById('cSofor').value = o.dataset.sofor || '';
    document.getElementById('cCinsi').value = o.dataset.cinsi || '';
    document.getElementById('cFirma').value = o.dataset.firma || '';
    document.getElementById('cPlaka').value = o.dataset.plaka || '';
}
function cikisAc(v){
    v = v || {id:0, tarih:'<?= date('Y-m-d') ?>', arac_id:0, sofor:'', cinsi:'', firma:'', plaka:'', miktar:'', sayac:'', teslim_eden:'', teslim_alan:'', aciklama:''};
    document.getElementById('cId').value = v.id;
    document.getElementById('cTarih').value = v.tarih;
    document.getElementById('cArac').value = v.arac_id;
    document.getElementById('cSofor').value = v.sofor;
    document.getElementById('cCinsi').value = v.cinsi;
    document.getElementById('cFirma').value = v.firma;
    document.getElementById('cPlaka').value = v.plaka;
    document.getElementById('cMiktar').value = v.miktar;
    document.getElementById('cSayac').value = v.sayac;
    document.getElementById('cTeslimEden').value = v.teslim_eden;
    document.getElementById('cTeslimAlan').value = v.teslim_alan;
    document.getElementById('cAciklama').value = v.aciklama;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
