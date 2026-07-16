<?php
/** giris_form.php — Ambar Giriş ekle/düzenle */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_seramik.php';
require_once __DIR__ . '/_ortak.php';

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row = ['gelis_tarihi'=>date('Y-m-d'),'belge_no'=>'','malzeme_ad'=>'','miktar'=>'','birim'=>'M2','firma_ad'=>'','arac_plaka'=>'','geldigi_birim'=>'','teslim_alan'=>'','palet_adet'=>'','aciklama'=>''];
if ($id) {
    $s=$pdoSeramik->prepare("SELECT g.*, m.ad malzeme_ad, f.ad firma_ad FROM seramik_giris g LEFT JOIN seramik_malzemeler m ON m.id=g.malzeme_id LEFT JOIN seramik_firmalar f ON f.id=g.firma_id WHERE g.id=?");
    $s->execute([$id]); $row=$s->fetch(); if(!$row){ flash('error','Kayıt yok.'); redirect('girisler.php'); }
}
$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $d=$_POST;
    $malAd=trim($d['malzeme_ad']??''); $mik=sr_sayi($d['miktar']??'');
    if ($malAd==='' || $mik<=0) { $err='Malzeme ve miktar zorunludur.'; }
    else {
        $mid=sr_malzemeId($pdoSeramik,$malAd,'SERAMİK',trim($d['birim']??'M2')?:'M2');
        $fid=sr_adId($pdoSeramik,'seramik_firmalar',$d['firma_ad']??'');
        $vals=[sr_tarih($d['gelis_tarihi']??''), trim($d['belge_no']??'')?:null, $mid, $mik, trim($d['birim']??'M2')?:'M2',
               $fid, trim($d['arac_plaka']??'')?:null, trim($d['geldigi_birim']??'')?:null, trim($d['teslim_alan']??'')?:null,
               trim($d['palet_adet']??'')?:null, trim($d['aciklama']??'')?:null];
        if ($id) { $pdoSeramik->prepare("UPDATE seramik_giris SET gelis_tarihi=?,belge_no=?,malzeme_id=?,miktar=?,birim=?,firma_id=?,arac_plaka=?,geldigi_birim=?,teslim_alan=?,palet_adet=?,aciklama=? WHERE id=?")->execute([...$vals,$id]); flash('success','Giriş güncellendi.'); }
        else { $pdoSeramik->prepare("INSERT INTO seramik_giris (gelis_tarihi,belge_no,malzeme_id,miktar,birim,firma_id,arac_plaka,geldigi_birim,teslim_alan,palet_adet,aciklama,kaynak) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'manuel')")->execute($vals); flash('success','Giriş eklendi.'); }
        redirect('girisler.php');
    }
}
$malzemeler=$pdoSeramik->query("SELECT ad FROM seramik_malzemeler WHERE aktif=1 ORDER BY ad")->fetchAll(PDO::FETCH_COLUMN);
$firmalar=$pdoSeramik->query("SELECT ad FROM seramik_firmalar WHERE aktif=1 ORDER BY ad")->fetchAll(PDO::FETCH_COLUMN);
$pageTitle=($id?'Giriş Düzenle':'Yeni Giriş').' — Seramik';
require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-box-arrow-in-down text-success me-2"></i><?= $id?'Giriş Düzenle':'Yeni Ambar Giriş' ?></h4>
<?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<div class="card border-0 shadow-sm"><div class="card-body">
<form method="post" class="row g-3">
    <div class="col-md-3"><label class="form-label">Geliş Tarihi</label><input type="date" name="gelis_tarihi" class="form-control" value="<?= h(substr($row['gelis_tarihi']??'',0,10)) ?>"></div>
    <div class="col-md-3"><label class="form-label">Belge No (İrsaliye/Fatura)</label><input name="belge_no" class="form-control" value="<?= h($row['belge_no']??'') ?>"></div>
    <div class="col-md-6"><label class="form-label">Malzeme Cinsi <span class="text-danger">*</span></label>
        <input name="malzeme_ad" class="form-control" list="malzListe" required value="<?= h($row['malzeme_ad']??'') ?>" placeholder="ör. 60X120 VİSTA ANTRASİT">
        <datalist id="malzListe"><?php foreach($malzemeler as $m): ?><option value="<?= h($m) ?>"><?php endforeach; ?></datalist></div>
    <div class="col-md-3"><label class="form-label">Miktar <span class="text-danger">*</span></label><input name="miktar" class="form-control" required value="<?= h($row['miktar']??'') ?>"></div>
    <div class="col-md-2"><label class="form-label">Birim</label><input name="birim" class="form-control" value="<?= h($row['birim']??'M2') ?>"></div>
    <div class="col-md-4"><label class="form-label">Gönderen Firma</label><input name="firma_ad" class="form-control" list="firmaListe" value="<?= h($row['firma_ad']??'') ?>">
        <datalist id="firmaListe"><?php foreach($firmalar as $f): ?><option value="<?= h($f) ?>"><?php endforeach; ?></datalist></div>
    <div class="col-md-3"><label class="form-label">Araç Plaka</label><input name="arac_plaka" class="form-control" value="<?= h($row['arac_plaka']??'') ?>"></div>
    <div class="col-md-3"><label class="form-label">Geldiği Birim</label><input name="geldigi_birim" class="form-control" value="<?= h($row['geldigi_birim']??'') ?>"></div>
    <div class="col-md-3"><label class="form-label">Palet</label><input name="palet_adet" class="form-control" value="<?= h($row['palet_adet']??'') ?>" placeholder="ör. 24 PALET"></div>
    <div class="col-md-3"><label class="form-label">Teslim Alan</label><input name="teslim_alan" class="form-control" value="<?= h($row['teslim_alan']??'') ?>"></div>
    <div class="col-md-6"><label class="form-label">Açıklama</label><input name="aciklama" class="form-control" value="<?= h($row['aciklama']??'') ?>"></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $id?'Güncelle':'Kaydet' ?></button><a href="girisler.php" class="btn btn-outline-secondary">İptal</a></div>
</form>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
