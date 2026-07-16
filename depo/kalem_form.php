<?php
/** kalem_form.php — Depo kalem ekle/düzenle */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';
if (!can_edit()) { flash('error','Yetkiniz yok.'); redirect('kalemler.php'); }

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id) {
    $q = $pdoDepo->prepare("SELECT * FROM depo_kalemler WHERE id=?");
    $q->execute([$id]); $row = $q->fetch();
    if (!$row) { flash('error','Kayıt bulunamadı.'); redirect('kalemler.php'); }
    $k = $row['kategori'];
} else {
    $k = $_GET['k'] ?? 'demirbas';
    if (!dp_katGecerli($k)) $k = 'demirbas';
}
$elAleti = ($k === 'el_aleti');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad = trim($_POST['ad'] ?? '');
    if ($ad === '') { flash('error','Malzeme adı zorunludur.'); }
    else {
        $data = [
            'kategori'    => $k,
            'kod'         => trim($_POST['kod'] ?? '') ?: null,
            'ad'          => $ad,
            'ozellik'     => trim($_POST['ozellik'] ?? '') ?: null,
            'birim'       => trim($_POST['birim'] ?? '') ?: ($elAleti?'Adet':'Ad'),
            'sayim'       => dp_sayi($_POST['sayim'] ?? ''),
            'gelen'       => dp_sayi($_POST['gelen'] ?? ''),
            'giden'       => dp_sayi($_POST['giden'] ?? ''),
            'birim_fiyat' => $elAleti ? null : (($_POST['birim_fiyat']??'')!=='' ? dp_sayi($_POST['birim_fiyat']) : null),
            'disiplin'    => $elAleti ? null : (trim($_POST['disiplin'] ?? '') ?: null),
            'alan'        => trim($_POST['alan'] ?? '') ?: null,
            'alan_kisi'   => $elAleti ? (trim($_POST['alan_kisi'] ?? '') ?: null) : null,
        ];
        if ($id) {
            $sql = "UPDATE depo_kalemler SET kod=?,ad=?,ozellik=?,birim=?,sayim=?,gelen=?,giden=?,birim_fiyat=?,disiplin=?,alan=?,alan_kisi=? WHERE id=?";
            $pdoDepo->prepare($sql)->execute([$data['kod'],$data['ad'],$data['ozellik'],$data['birim'],$data['sayim'],$data['gelen'],$data['giden'],$data['birim_fiyat'],$data['disiplin'],$data['alan'],$data['alan_kisi'],$id]);
            flash('success','Kalem güncellendi.');
        } else {
            $sql = "INSERT INTO depo_kalemler (kategori,kod,ad,ozellik,birim,sayim,gelen,giden,birim_fiyat,disiplin,alan,alan_kisi) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
            $pdoDepo->prepare($sql)->execute([$data['kategori'],$data['kod'],$data['ad'],$data['ozellik'],$data['birim'],$data['sayim'],$data['gelen'],$data['giden'],$data['birim_fiyat'],$data['disiplin'],$data['alan'],$data['alan_kisi']]);
            flash('success','Kalem eklendi.');
        }
        redirect('kalemler.php?k='.$k);
    }
}

$v = fn($f) => h($row[$f] ?? ($_POST[$f] ?? ''));
$pageTitle = ($id?'Kalem Düzenle':'Yeni Kalem').' — '.dp_katAd($k);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi <?= h($GLOBALS['DP_KATEGORI'][$k]['ikon']) ?> text-primary me-2"></i><?= $id?'Kalem Düzenle':'Yeni Kalem' ?>
        <small class="text-muted fs-6">· <?= h(dp_katAd($k)) ?></small></h4>
    <a href="kalemler.php?k=<?= $k ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Listeye Dön</a>
</div>
<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<div class="card border-0 shadow-sm"><div class="card-body">
<form method="post" class="row g-3">
    <div class="col-md-3">
        <label class="form-label small fw-semibold"><?= $elAleti?'Seri Numarası':'Malzeme Kodu' ?></label>
        <input name="kod" value="<?= $v('kod') ?>" class="form-control">
    </div>
    <div class="col-md-6">
        <label class="form-label small fw-semibold">Malzeme Adı <span class="text-danger">*</span></label>
        <input name="ad" value="<?= $v('ad') ?>" class="form-control" required>
    </div>
    <div class="col-md-3">
        <label class="form-label small fw-semibold">Birim</label>
        <input name="birim" value="<?= $row?h($row['birim']):($elAleti?'Adet':'Ad') ?>" class="form-control">
    </div>
    <div class="col-md-12">
        <label class="form-label small fw-semibold">Özellik / Açıklama</label>
        <input name="ozellik" value="<?= $v('ozellik') ?>" class="form-control">
    </div>
    <div class="col-md-3">
        <label class="form-label small fw-semibold">Sayım</label>
        <input name="sayim" value="<?= $row?rtrim(rtrim(number_format((float)$row['sayim'],2,'.',''),'0'),'.'):h($_POST['sayim']??'') ?>" class="form-control text-end" inputmode="decimal">
    </div>
    <div class="col-md-3">
        <label class="form-label small fw-semibold text-success">Gelen (+)</label>
        <input name="gelen" value="<?= $row?rtrim(rtrim(number_format((float)$row['gelen'],2,'.',''),'0'),'.'):h($_POST['gelen']??'') ?>" class="form-control text-end" inputmode="decimal">
    </div>
    <div class="col-md-3">
        <label class="form-label small fw-semibold text-danger">Giden (−)</label>
        <input name="giden" value="<?= $row?rtrim(rtrim(number_format((float)$row['giden'],2,'.',''),'0'),'.'):h($_POST['giden']??'') ?>" class="form-control text-end" inputmode="decimal">
    </div>
    <div class="col-md-3">
        <label class="form-label small fw-semibold">Stok (hesaplanan)</label>
        <input class="form-control text-end fw-bold bg-light" value="<?= $row?number_format((float)$row['sayim']+(float)$row['gelen']-(float)$row['giden'],2,',','.'):'—' ?>" readonly>
        <div class="form-text">Stok = Sayım + Gelen − Giden</div>
    </div>
    <?php if(!$elAleti): ?>
    <div class="col-md-4">
        <label class="form-label small fw-semibold">Birim Fiyat (TL)</label>
        <input name="birim_fiyat" value="<?= $row&&$row['birim_fiyat']!==null?rtrim(rtrim(number_format((float)$row['birim_fiyat'],2,'.',''),'0'),'.'):h($_POST['birim_fiyat']??'') ?>" class="form-control text-end" inputmode="decimal">
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold">Disiplin</label>
        <input name="disiplin" value="<?= $v('disiplin') ?>" class="form-control" placeholder="İnşaat / Mekanik / Elektrik…">
    </div>
    <div class="col-md-4">
        <label class="form-label small fw-semibold">Bulunduğu Alan</label>
        <input name="alan" value="<?= $v('alan') ?>" class="form-control">
    </div>
    <?php else: ?>
    <div class="col-md-6">
        <label class="form-label small fw-semibold">Bulunduğu Alan</label>
        <input name="alan" value="<?= $v('alan') ?>" class="form-control">
    </div>
    <div class="col-md-6">
        <label class="form-label small fw-semibold">Zimmetli Kişi / Alan Kişi</label>
        <input name="alan_kisi" value="<?= $v('alan_kisi') ?>" class="form-control">
    </div>
    <?php endif; ?>
    <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $id?'Güncelle':'Kaydet' ?></button>
        <a href="kalemler.php?k=<?= $k ?>" class="btn btn-outline-secondary">İptal</a>
    </div>
</form>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
