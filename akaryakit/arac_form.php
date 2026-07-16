<?php
/** arac_form.php — Araç / makine ekle-düzenle (akaryakıt) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';
if (!can_edit()) { flash('error','Yetkiniz yok.'); redirect('araclar.php'); }

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id) {
    $q=$pdoAkaryakit->prepare("SELECT * FROM akaryakit_araclar WHERE id=?"); $q->execute([$id]); $row=$q->fetch();
    if (!$row) { flash('error','Kayıt bulunamadı.'); redirect('araclar.php'); }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $sofor=trim($_POST['sofor']??''); $cinsi=trim($_POST['cinsi']??'');
    if ($sofor==='' || $cinsi==='') { flash('error','Şoför ve Cinsi zorunludur.'); }
    else {
        $anahtar = ak_norm($sofor.'|'.$cinsi);
        // Mükerrer anahtar kontrolü (kendisi hariç)
        $chk=$pdoAkaryakit->prepare("SELECT id FROM akaryakit_araclar WHERE anahtar=? AND id<>?");
        $chk->execute([$anahtar,$id]);
        if ($chk->fetchColumn()) { flash('error','Aynı Şoför + Cinsi kombinasyonu zaten kayıtlı.'); }
        else {
            $d=[trim($_POST['sinif']??'')?:null, trim($_POST['mak_no']??'')?:null, trim($_POST['lokasyon']??'')?:null,
                trim($_POST['firma']??'')?:null, trim($_POST['plaka']??'')?:null, $sofor, $cinsi, $anahtar];
            if ($id) {
                $pdoAkaryakit->prepare("UPDATE akaryakit_araclar SET sinif=?,mak_no=?,lokasyon=?,firma=?,plaka=?,sofor=?,cinsi=?,anahtar=? WHERE id=?")
                    ->execute([...$d,$id]);
                flash('success','Araç güncellendi.');
            } else {
                $pdoAkaryakit->prepare("INSERT INTO akaryakit_araclar (sinif,mak_no,lokasyon,firma,plaka,sofor,cinsi,anahtar) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute($d);
                flash('success','Araç eklendi.');
            }
            redirect('araclar.php');
        }
    }
}
$v = fn($f) => h($row[$f] ?? ($_POST[$f] ?? ''));
$pageTitle = ($id?'Araç Düzenle':'Yeni Araç').' — Akaryakıt';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-truck text-primary me-2"></i><?= $id?'Araç Düzenle':'Yeni Araç / Makine' ?></h4>
    <a href="araclar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Listeye Dön</a>
</div>
<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<div class="card border-0 shadow-sm"><div class="card-body">
<form method="post" class="row g-3">
    <div class="col-md-6"><label class="form-label small fw-semibold">Şoför <span class="text-danger">*</span></label>
        <input name="sofor" value="<?= $v('sofor') ?>" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label small fw-semibold">Cinsi (araç/makine tipi) <span class="text-danger">*</span></label>
        <input name="cinsi" value="<?= $v('cinsi') ?>" class="form-control" placeholder="LASTİKLİ EKSKAVATÖR / KAMYON…" required></div>
    <div class="col-md-4"><label class="form-label small fw-semibold">Sınıfı</label>
        <input name="sinif" value="<?= $v('sinif') ?>" class="form-control" placeholder="MAKİNA / KAMYON / TRAKTÖR"></div>
    <div class="col-md-4"><label class="form-label small fw-semibold">Firma</label>
        <input name="firma" value="<?= $v('firma') ?>" class="form-control"></div>
    <div class="col-md-4"><label class="form-label small fw-semibold">Lokasyon</label>
        <input name="lokasyon" value="<?= $v('lokasyon') ?>" class="form-control" placeholder="SAHA"></div>
    <div class="col-md-4"><label class="form-label small fw-semibold">Plaka</label>
        <input name="plaka" value="<?= $v('plaka') ?>" class="form-control"></div>
    <div class="col-md-4"><label class="form-label small fw-semibold">Makine No</label>
        <input name="mak_no" value="<?= $v('mak_no') ?>" class="form-control"></div>
    <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $id?'Güncelle':'Kaydet' ?></button>
        <a href="araclar.php" class="btn btn-outline-secondary">İptal</a>
    </div>
</form>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
