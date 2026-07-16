<?php
/** cikis_form.php — Ambar Çıkış ekle/düzenle (stok kontrolü ile) */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_seramik.php';
require_once __DIR__ . '/_ortak.php';

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row = ['cikis_tarihi'=>date('Y-m-d'),'fis_no'=>'','taseron_ad'=>'','cikis_yeri'=>'','malzeme_ad'=>'','miktar'=>'','birim'=>'M2','palet_adet'=>'','teslim_alan_firma'=>'','onay'=>'','aciklama'=>''];
if ($id) { $s=$pdoSeramik->prepare("SELECT c.*, m.ad malzeme_ad, t.ad taseron_ad FROM seramik_cikis c LEFT JOIN seramik_malzemeler m ON m.id=c.malzeme_id LEFT JOIN seramik_taseronlar t ON t.id=c.taseron_id WHERE c.id=?"); $s->execute([$id]); $row=$s->fetch(); if(!$row){ flash('error','Kayıt yok.'); redirect('cikislar.php'); } }
$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $d=$_POST; $malAd=trim($d['malzeme_ad']??''); $mik=sr_sayi($d['miktar']??'');
    if ($malAd==='' || $mik<=0) { $err='Malzeme ve miktar zorunludur.'; }
    else {
        $mid=sr_malzemeId($pdoSeramik,$malAd,'SERAMİK',trim($d['birim']??'M2')?:'M2');
        $tid=sr_adId($pdoSeramik,'seramik_taseronlar',$d['taseron_ad']??'');
        $vals=[sr_tarih($d['cikis_tarihi']??''), trim($d['fis_no']??'')?:null, $tid, trim($d['cikis_yeri']??'')?:null,
               $mid, $mik, trim($d['birim']??'M2')?:'M2', trim($d['palet_adet']??'')?:null, trim($d['teslim_alan_firma']??'')?:null,
               trim($d['onay']??'')?:null, trim($d['aciklama']??'')?:null];
        if($id){ $pdoSeramik->prepare("UPDATE seramik_cikis SET cikis_tarihi=?,fis_no=?,taseron_id=?,cikis_yeri=?,malzeme_id=?,miktar=?,birim=?,palet_adet=?,teslim_alan_firma=?,onay=?,aciklama=? WHERE id=?")->execute([...$vals,$id]); flash('success','Çıkış güncellendi.'); }
        else { $pdoSeramik->prepare("INSERT INTO seramik_cikis (cikis_tarihi,fis_no,taseron_id,cikis_yeri,malzeme_id,miktar,birim,palet_adet,teslim_alan_firma,onay,aciklama,kaynak) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'manuel')")->execute($vals); flash('success','Çıkış eklendi.'); }
        redirect('cikislar.php');
    }
}
$malzemeler=$pdoSeramik->query("SELECT ad FROM seramik_malzemeler WHERE aktif=1 ORDER BY ad")->fetchAll(PDO::FETCH_COLUMN);
$taseronlar=$pdoSeramik->query("SELECT ad FROM seramik_taseronlar WHERE aktif=1 ORDER BY ad")->fetchAll(PDO::FETCH_COLUMN);
$pageTitle=($id?'Çıkış Düzenle':'Yeni Çıkış').' — Seramik';
require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-box-arrow-up text-danger me-2"></i><?= $id?'Çıkış Düzenle':'Yeni Ambar Çıkış' ?></h4>
<?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<div class="card border-0 shadow-sm"><div class="card-body">
<form method="post" class="row g-3">
    <div class="col-md-3"><label class="form-label">Çıkış Tarihi</label><input type="date" name="cikis_tarihi" class="form-control" value="<?= h(substr($row['cikis_tarihi']??'',0,10)) ?>"></div>
    <div class="col-md-3"><label class="form-label">Çıkış Fiş No</label><input name="fis_no" class="form-control" value="<?= h($row['fis_no']??'') ?>"></div>
    <div class="col-md-3"><label class="form-label">Çıkış Yapılan Taşeron</label><input name="taseron_ad" class="form-control" list="tasListe" value="<?= h($row['taseron_ad']??'') ?>">
        <datalist id="tasListe"><?php foreach($taseronlar as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?></datalist></div>
    <div class="col-md-3"><label class="form-label">Çıkış Yeri</label><input name="cikis_yeri" class="form-control" value="<?= h($row['cikis_yeri']??'') ?>" placeholder="ör. A PARSEL"></div>
    <div class="col-md-6"><label class="form-label">Malzeme Cinsi <span class="text-danger">*</span></label>
        <input name="malzeme_ad" class="form-control" list="malzListe" required value="<?= h($row['malzeme_ad']??'') ?>">
        <datalist id="malzListe"><?php foreach($malzemeler as $m): ?><option value="<?= h($m) ?>"><?php endforeach; ?></datalist></div>
    <div class="col-md-3"><label class="form-label">Miktar <span class="text-danger">*</span></label><input name="miktar" class="form-control" required value="<?= h($row['miktar']??'') ?>"></div>
    <div class="col-md-2"><label class="form-label">Birim</label><input name="birim" class="form-control" value="<?= h($row['birim']??'M2') ?>"></div>
    <div class="col-md-3"><label class="form-label">Palet</label><input name="palet_adet" class="form-control" value="<?= h($row['palet_adet']??'') ?>"></div>
    <div class="col-md-3"><label class="form-label">Onay</label><input name="onay" class="form-control" value="<?= h($row['onay']??'') ?>"></div>
    <div class="col-md-3"><label class="form-label">Teslim Alan Firma</label><input name="teslim_alan_firma" class="form-control" value="<?= h($row['teslim_alan_firma']??'') ?>"></div>
    <div class="col-md-9"><label class="form-label">Açıklama</label><input name="aciklama" class="form-control" value="<?= h($row['aciklama']??'') ?>"></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $id?'Güncelle':'Kaydet' ?></button><a href="cikislar.php" class="btn btn-outline-secondary">İptal</a></div>
</form>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
