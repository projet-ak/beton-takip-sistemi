<?php
/**
 * demir/siparis_form.php — Demir siparişi giriş / düzenleme
 * Çap bazında sipariş miktarı; gelen sevkiyatlar IFS Sipariş No ile eşleşir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/../includes/db_demir.php';

function num_parse($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
}

$editId  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null; $kalemMap = [];
if ($editId) {
    $s = $pdoDemir->prepare("SELECT * FROM demir_siparisler WHERE id=?"); $s->execute([$editId]);
    $row = $s->fetch() ?: null;
    if (!$row) { flash('error','Sipariş bulunamadı.'); redirect('siparisler.php'); }
    $k = $pdoDemir->prepare("SELECT * FROM demir_siparis_kalemleri WHERE siparis_id=?"); $k->execute([$editId]);
    foreach ($k->fetchAll() as $kk) { $kalemMap[$kk['cap_id']] = $kk['miktar_ton']; }
}

$caplar     = $pdoDemir->query("SELECT * FROM demir_caplar WHERE aktif=1 ORDER BY sira, ad")->fetchAll();
$taseronlar = $pdoDemir->query("SELECT id,ad FROM demir_taseronlar WHERE aktif=1 ORDER BY ad")->fetchAll();
$projeler   = $pdoDemir->query("SELECT id,kod,aciklama FROM demir_projeler WHERE aktif=1 ORDER BY kod")->fetchAll();
$durumlar   = ['acik'=>'Açık','kismi'=>'Kısmi','tamam'=>'Tamamlandı','iptal'=>'İptal'];

$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'ifs_talep_no'       => trim($_POST['ifs_talep_no'] ?? ''),
        'ifs_siparis_no'     => trim($_POST['ifs_siparis_no'] ?? ''),
        'gonderen_firma'     => trim($_POST['gonderen_firma'] ?? ''),
        'siparisi_olusturan' => trim($_POST['siparisi_olusturan'] ?? ''),
        'imalat'             => trim($_POST['imalat'] ?? ''),
        'taseron_id'         => ctype_digit($_POST['taseron_id'] ?? '') ? (int)$_POST['taseron_id'] : null,
        'proje_id'           => ctype_digit($_POST['proje_id'] ?? '') ? (int)$_POST['proje_id'] : null,
        'tarih'              => $_POST['tarih'] ?? '',
        'durum'              => array_key_exists($_POST['durum'] ?? '', $durumlar) ? $_POST['durum'] : 'acik',
        'aciklama'           => trim($_POST['aciklama'] ?? ''),
    ];
    $kalemler = [];
    foreach ($caplar as $c) {
        $m = num_parse($_POST['sip'][$c['id']] ?? '');
        if ($m !== null && $m != 0) { $kalemler[] = ['cap_id'=>$c['id'], 'm'=>$m]; }
    }

    if ($d['ifs_siparis_no'] === '') $formError = 'IFS Sipariş No zorunludur (sevkiyatlarla eşleşme bunun üzerinden yapılır).';
    elseif (!$kalemler)              $formError = 'En az bir çap için sipariş miktarı girin.';

    if (!$formError) {
        $params = [$d['ifs_talep_no']?:null, $d['ifs_siparis_no'], $d['gonderen_firma']?:null,
                   $d['siparisi_olusturan']?:null, $d['imalat']?:null, $d['taseron_id'], $d['proje_id'],
                   $d['tarih']?:null, $d['durum'], $d['aciklama']?:null];
        if ($editId) {
            $params[] = $editId;
            $pdoDemir->prepare("UPDATE demir_siparisler SET ifs_talep_no=?, ifs_siparis_no=?, gonderen_firma=?,
                siparisi_olusturan=?, imalat=?, taseron_id=?, proje_id=?, tarih=?, durum=?, aciklama=? WHERE id=?")->execute($params);
            $pdoDemir->prepare("DELETE FROM demir_siparis_kalemleri WHERE siparis_id=?")->execute([$editId]);
            $sipId = $editId;
        } else {
            $params[] = current_user_id();
            $pdoDemir->prepare("INSERT INTO demir_siparisler (ifs_talep_no, ifs_siparis_no, gonderen_firma,
                siparisi_olusturan, imalat, taseron_id, proje_id, tarih, durum, aciklama, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute($params);
            $sipId = (int)$pdoDemir->lastInsertId();
        }
        $ins = $pdoDemir->prepare("INSERT INTO demir_siparis_kalemleri (siparis_id, cap_id, miktar_ton) VALUES (?,?,?)");
        foreach ($kalemler as $kl) { $ins->execute([$sipId, $kl['cap_id'], $kl['m']]); }
        flash('success', 'Sipariş ' . ($editId ? 'güncellendi' : 'kaydedildi') . '.');
        redirect('siparis_detay.php?id=' . $sipId);
    } else {
        $row = array_merge((array)$row, $d);
        foreach ($caplar as $c) { $kalemMap[$c['id']] = $_POST['sip'][$c['id']] ?? ''; }
    }
}

$pageTitle = ($editId ? 'Sipariş Düzenle' : 'Yeni Sipariş') . ' — Demir Takip';
require_once __DIR__ . '/../includes/header.php';
$v = fn($k) => h($row[$k] ?? '');
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="siparisler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-cart-check text-dark me-2"></i><?= $editId ? 'Sipariş Düzenle' : 'Yeni Demir Siparişi' ?></h4>
</div>

<?php if ($formError): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($formError) ?></div><?php endif; ?>

<form method="post">
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> Sipariş Bilgileri</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">IFS Sipariş No <span class="text-danger">*</span></label><input name="ifs_siparis_no" class="form-control" required value="<?= $v('ifs_siparis_no') ?>"></div>
                    <div class="col-md-6"><label class="form-label">IFS Talep No</label><input name="ifs_talep_no" class="form-control" value="<?= $v('ifs_talep_no') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Proje</label><select name="proje_id" class="form-select"><option value="">—</option><?php foreach($projeler as $p): ?><option value="<?= $p['id'] ?>" <?= ($row['proje_id']??'')==$p['id']?'selected':'' ?>><?= h($p['kod']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Taşeron</label><select name="taseron_id" class="form-select"><option value="">—</option><?php foreach($taseronlar as $t): ?><option value="<?= $t['id'] ?>" <?= ($row['taseron_id']??'')==$t['id']?'selected':'' ?>><?= h($t['ad']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label">İmalat</label><input name="imalat" class="form-control" value="<?= $v('imalat') ?>" placeholder="ör. İSTİNAT PERDELERİ"></div>
                    <div class="col-md-6"><label class="form-label">Gönderen Firma</label><input name="gonderen_firma" class="form-control" value="<?= $v('gonderen_firma') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Siparişi Oluşturan</label><input name="siparisi_olusturan" class="form-control" value="<?= $v('siparisi_olusturan') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Tarih</label><input type="date" name="tarih" class="form-control" value="<?= $v('tarih') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Durum</label><select name="durum" class="form-select"><?php foreach($durumlar as $k=>$lbl): ?><option value="<?= $k ?>" <?= ($row['durum']??'acik')===$k?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label">Açıklama</label><textarea name="aciklama" class="form-control" rows="2"><?= $v('aciklama') ?></textarea></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-rulers text-primary me-1"></i> Çap Bazında Sipariş Miktarı (ton)</div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Çap</th><th class="text-end" style="width:160px">Sipariş (t)</th></tr></thead>
                    <tbody>
                    <?php foreach ($caplar as $c): ?>
                        <tr>
                            <td class="fw-semibold"><?= h($c['ad']) ?> <span class="badge bg-light text-muted border ms-1" style="font-size:.62rem"><?= h($c['tip']) ?></span></td>
                            <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-end sip-inp" name="sip[<?= $c['id'] ?>]" value="<?= h($kalemMap[$c['id']] ?? '') ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr><td class="text-end">TOPLAM</td><td class="text-end" id="topSip">0</td></tr></tfoot>
                </table>
            </div></div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success btn-lg"><i class="bi bi-save me-1"></i><?= $editId ? 'Güncelle' : 'Kaydet' ?></button>
            <a href="siparisler.php" class="btn btn-outline-secondary btn-lg">İptal</a>
        </div>
    </div>
</div>
</form>
<script>
function pf(v){v=(v||'').toString().trim().replace(',','.');var n=parseFloat(v);return isNaN(n)?0:n;}
function hesapla(){var t=0;document.querySelectorAll('.sip-inp').forEach(function(el){t+=pf(el.value);});document.getElementById('topSip').textContent=t.toLocaleString('tr-TR',{maximumFractionDigits:3});}
document.querySelectorAll('.sip-inp').forEach(function(el){el.addEventListener('input',hesapla);});hesapla();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
