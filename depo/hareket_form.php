<?php
/**
 * hareket_form.php — Günlük depo giriş/çıkış kaydı (elle)
 *
 * Excel içe aktarması geçmişi getirir; bu form GÜNLÜK işleyişi tutar:
 * malzeme geldi → giriş, taşerona/birine verildi → çıkış.
 * Elle kayıtlar (elle=1) Excel tam yenilemesinde SİLİNMEZ.
 *
 * "Stok kalemine işle" seçilirse hareket depo_kalemler'in GELEN/GİDEN'ine de
 * yazılır → canlı stok anında güncellenir; kayıt silinir/düzenlenirse geri alınır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

dp_hareket_semasi_kur($pdoDepo);

$id  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id) {
    $st = $pdoDepo->prepare("SELECT * FROM depo_hareketler WHERE id=? AND elle=1");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { flash('error', 'Kayıt bulunamadı (Excel kayıtları buradan düzenlenmez — Excel esastır).'); redirect('hareketler.php'); }
}
$tur = $row['tur'] ?? (($_GET['tur'] ?? '') === 'cikis' ? 'cikis' : 'giris');
$pageTitle = ($id ? 'Hareket Düzenle' : ($tur === 'giris' ? 'Yeni Giriş' : 'Yeni Çıkış')) . ' — Depo';

// ── Kaydet ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tur     = ($_POST['tur'] ?? 'giris') === 'cikis' ? 'cikis' : 'giris';
    $kaynak  = ($_POST['kaynak'] ?? 'depo') === 'taseron' ? 'taseron' : 'depo';
    $malzeme = trim((string)($_POST['malzeme'] ?? ''));
    $miktar  = dp_sayi($_POST['miktar'] ?? '');
    $tarih   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['tarih'] ?? '') ? $_POST['tarih'] : date('Y-m-d');
    $kalemId = isset($_POST['kalem_id']) && ctype_digit((string)$_POST['kalem_id']) ? (int)$_POST['kalem_id'] : null;

    if ($malzeme === '' || $miktar <= 0) {
        flash('error', 'Malzeme adı ve sıfırdan büyük miktar zorunludur.');
    } else {
        if ($kalemId) {   // kalem gerçekten var mı
            $v = $pdoDepo->prepare("SELECT id FROM depo_kalemler WHERE id=?"); $v->execute([$kalemId]);
            if (!$v->fetchColumn()) $kalemId = null;
        }
        $alanlar = [
            $tur, $kaynak, $tarih,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['belge_tarihi'] ?? '') ? $_POST['belge_tarihi'] : null,
            trim((string)($_POST['belge_no'] ?? '')) ?: null,
            mb_substr($malzeme, 0, 255),
            trim((string)($_POST['ozellik'] ?? '')) ?: null,
            trim((string)($_POST['birim'] ?? '')) ?: 'Adet',
            $miktar,
            trim((string)($_POST['firma'] ?? '')) ?: null,
            trim((string)($_POST['teslim_alan'] ?? '')) ?: null,
            trim((string)($_POST['onay'] ?? '')) ?: null,
            trim((string)($_POST['lokasyon'] ?? '')) ?: null,
            trim((string)($_POST['aciklama'] ?? '')) ?: null,
            $kalemId,
        ];
        try {
            $pdoDepo->beginTransaction();
            if ($id && $row) {
                dp_stok_islet($pdoDepo, $row['kalem_id'] ? (int)$row['kalem_id'] : null, $row['tur'], (float)$row['miktar'], -1);
                $u = $pdoDepo->prepare("UPDATE depo_hareketler SET tur=?, kaynak=?, tarih=?, belge_tarihi=?, belge_no=?,
                        malzeme=?, ozellik=?, birim=?, miktar=?, firma=?, teslim_alan=?, onay=?, lokasyon=?, aciklama=?, kalem_id=?
                        WHERE id=? AND elle=1");
                $u->execute(array_merge($alanlar, [$id]));
            } else {
                $i = $pdoDepo->prepare("INSERT INTO depo_hareketler
                        (tur,kaynak,tarih,belge_tarihi,belge_no,malzeme,ozellik,birim,miktar,firma,teslim_alan,onay,lokasyon,aciklama,kalem_id,elle)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
                $i->execute($alanlar);
                $id = (int)$pdoDepo->lastInsertId();
            }
            dp_stok_islet($pdoDepo, $kalemId, $tur, $miktar, 1);
            $pdoDepo->commit();
            flash('success', ($tur === 'giris' ? 'Giriş' : 'Çıkış') . ' kaydedildi'
                           . ($kalemId ? ' ve stok kalemine işlendi.' : '.'));
            redirect('hareketler.php');
        } catch (Throwable $e) {
            if ($pdoDepo->inTransaction()) $pdoDepo->rollBack();
            flash('error', 'Kayıt hatası: ' . $e->getMessage());
        }
    }
}

// Stok kalemleri (datalist için — ad + özellik + kategori)
$kalemler = $pdoDepo->query("SELECT id, kategori, ad, ozellik, birim, (sayim+gelen-giden) stok
                             FROM depo_kalemler WHERE aktif=1 ORDER BY ad LIMIT 4000")->fetchAll();

$g = $tur === 'giris';
$val = fn($k, $d = '') => h((string)(($row[$k] ?? null) ?? ($_POST[$k] ?? $d)));
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="hareketler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">
        <i class="bi <?= $g ? 'bi-box-arrow-in-down text-success' : 'bi-box-arrow-up text-danger' ?> me-1"></i>
        <?= $id ? 'Hareket Düzenle' : ($g ? 'Yeni Giriş (malzeme geldi)' : 'Yeni Çıkış (malzeme verildi)') ?>
    </h4>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>

<form method="post" class="card border-0 shadow-sm">
    <div class="card-body row g-3">
        <div class="col-6 col-md-2">
            <label class="form-label">Tür <span class="text-danger">*</span></label>
            <select name="tur" class="form-select" onchange="trDegis(this.value)">
                <option value="giris" <?= $g?'selected':'' ?>>Giriş</option>
                <option value="cikis" <?= !$g?'selected':'' ?>>Çıkış</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Kaynak</label>
            <select name="kaynak" class="form-select">
                <option value="depo"    <?= ($row['kaynak'] ?? 'depo')==='depo'?'selected':'' ?>>Depo malzemesi</option>
                <option value="taseron" <?= ($row['kaynak'] ?? '')==='taseron'?'selected':'' ?>>Taşeron malzemesi</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Tarih <span class="text-danger">*</span></label>
            <input type="date" name="tarih" class="form-control" value="<?= $val('tarih', date('Y-m-d')) ?>" required>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" id="lblBelge"><?= $g ? 'İrsaliye No' : 'Fiş No' ?></label>
            <input type="text" name="belge_no" class="form-control" value="<?= $val('belge_no') ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Belge Tarihi</label>
            <input type="date" name="belge_tarihi" class="form-control" value="<?= $val('belge_tarihi') ?>">
        </div>

        <div class="col-md-5">
            <label class="form-label">Malzeme <span class="text-danger">*</span></label>
            <input type="text" name="malzeme" id="malzemeAd" class="form-control" list="kalemList"
                   value="<?= $val('malzeme') ?>" required autocomplete="off"
                   placeholder="Yazmaya başlayın — stok kalemlerinden öneri gelir">
            <datalist id="kalemList">
                <?php foreach ($kalemler as $k): ?>
                <option value="<?= h($k['ad']) ?>" data-id="<?= (int)$k['id'] ?>"><?= h(dp_katAd($k['kategori'])) ?><?= $k['ozellik']?' · '.h(mb_substr($k['ozellik'],0,40)):'' ?></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Özellik / Marka</label>
            <input type="text" name="ozellik" class="form-control" value="<?= $val('ozellik') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Miktar <span class="text-danger">*</span></label>
            <input type="text" name="miktar" class="form-control" value="<?= $val('miktar') ?>" required inputmode="decimal">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Birim</label>
            <input type="text" name="birim" class="form-control" value="<?= $val('birim', 'Adet') ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label" id="lblFirma"><?= $g ? 'Gönderen Firma' : 'Çıkış Yapılan Firma / Taşeron' ?></label>
            <input type="text" name="firma" class="form-control" value="<?= $val('firma') ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Teslim Alan</label>
            <input type="text" name="teslim_alan" class="form-control" value="<?= $val('teslim_alan') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Onaylayan</label>
            <input type="text" name="onay" class="form-control" value="<?= $val('onay') ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Lokasyon</label>
            <input type="text" name="lokasyon" class="form-control" value="<?= $val('lokasyon') ?>">
        </div>

        <div class="col-md-7">
            <label class="form-label">Açıklama</label>
            <input type="text" name="aciklama" class="form-control" value="<?= $val('aciklama') ?>">
        </div>
        <div class="col-md-5">
            <label class="form-label">Stok kalemine işle <span class="text-muted small">(opsiyonel — canlı stoğu günceller)</span></label>
            <select name="kalem_id" id="kalemSec" class="form-select">
                <option value="">— stoğa işleme, yalnız deftere yaz —</option>
                <?php foreach ($kalemler as $k): ?>
                <option value="<?= (int)$k['id'] ?>" data-ad="<?= h(mb_strtolower($k['ad'],'UTF-8')) ?>"
                        <?= ((int)($row['kalem_id'] ?? 0) === (int)$k['id']) ? 'selected' : '' ?>>
                    <?= h($k['ad']) ?><?= $k['ozellik']?' — '.h(mb_substr($k['ozellik'],0,30)):'' ?> (stok: <?= number_format((float)$k['stok'],0,',','.') ?> <?= h($k['birim']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Seçilirse <?= $g ? 'GELEN' : 'GİDEN' ?> miktarı artar; kayıt silinirse geri alınır.</div>
        </div>

        <div class="col-12">
            <button class="btn btn-<?= $g ? 'success' : 'danger' ?>"><i class="bi bi-save me-1"></i><?= $id ? 'Güncelle' : 'Kaydet' ?></button>
            <a href="hareketler.php" class="btn btn-outline-secondary">Vazgeç</a>
        </div>
    </div>
</form>

<script>
function trDegis(t){
    var g = t === 'giris';
    document.getElementById('lblBelge').textContent = g ? 'İrsaliye No' : 'Fiş No';
    document.getElementById('lblFirma').textContent = g ? 'Gönderen Firma' : 'Çıkış Yapılan Firma / Taşeron';
}
// Malzeme adı yazılınca aynı adlı stok kalemini otomatik seç
document.getElementById('malzemeAd').addEventListener('change', function(){
    var ad = this.value.trim().toLocaleLowerCase('tr');
    var sec = document.getElementById('kalemSec');
    for (var i = 0; i < sec.options.length; i++) {
        if ((sec.options[i].dataset.ad || '') === ad) { sec.selectedIndex = i; return; }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
