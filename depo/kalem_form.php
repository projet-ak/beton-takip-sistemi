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

// ── Hurdaya ayırma: kalemden hurda çıkışı üret (hareket defteri + stok düşümü) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hurdaya_ayir' && $id && $row) {
    dp_hareket_semasi_kur($pdoDepo);
    $miktar = dp_sayi($_POST['h_miktar'] ?? '');
    $sebep  = trim((string)($_POST['h_sebep'] ?? ''));
    $stok   = (float)$row['sayim'] + (float)$row['gelen'] - (float)$row['giden'];
    if ($miktar <= 0) { flash('error', 'Hurdaya ayrılacak miktar sıfırdan büyük olmalı.'); redirect("kalem_form.php?id=$id"); }
    if ($sebep === '') { flash('error', 'Hurdaya ayrılma sebebi zorunludur.'); redirect("kalem_form.php?id=$id"); }
    if ($miktar > $stok + 0.001) { flash('error', 'Miktar stoğu aşıyor (stok: ' . number_format($stok, 2, ',', '.') . ' ' . $row['birim'] . ').'); redirect("kalem_form.php?id=$id"); }
    try {
        $pdoDepo->beginTransaction();
        $ins = $pdoDepo->prepare("INSERT INTO depo_hareketler
            (tur,kaynak,tarih,malzeme,ozellik,birim,miktar,firma,teslim_alan,onay,lokasyon,aciklama,kalem_id,hurda,elle)
            VALUES ('cikis','depo',?,?,?,?,?,NULL,?,?,?,?,?,1,1)");
        $ins->execute([
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['h_tarih'] ?? '') ? $_POST['h_tarih'] : date('Y-m-d'),
            $row['ad'], $row['ozellik'], $row['birim'], $miktar,
            trim((string)($_POST['h_teslim_alan'] ?? '')) ?: null,
            trim((string)($_POST['h_onay'] ?? '')) ?: null,
            $row['alan'] ?: null,
            $sebep,
            $id,
        ]);
        $hid = (int)$pdoDepo->lastInsertId();
        dp_stok_islet($pdoDepo, $id, 'cikis', $miktar, 1);   // GİDEN artar → stok düşer
        $pdoDepo->commit();
        flash('success', number_format($miktar, 2, ',', '.') . ' ' . $row['birim'] . ' "' . $row['ad'] . '" hurdaya ayrıldı, stoktan düşüldü.');
        redirect('hareketler.php?hurda=1&tutanak=' . $hid);
    } catch (Throwable $e) {
        if ($pdoDepo->inTransaction()) $pdoDepo->rollBack();
        flash('error', 'Hurdaya ayırma hatası: ' . $e->getMessage());
        redirect("kalem_form.php?id=$id");
    }
}

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
        <?php if ($id): $mevcutStok = (float)$row['sayim'] + (float)$row['gelen'] - (float)$row['giden']; ?>
        <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#hurdaModal">
            <i class="bi bi-trash3 me-1"></i>Hurdaya Ayır</button>
        <?php endif; ?>
        <a href="kalemler.php?k=<?= $k ?>" class="btn btn-outline-secondary">İptal</a>
    </div>
</form>

<?php if ($id): ?>
<!-- Hurdaya ayırma formu -->
<div class="modal fade" id="hurdaModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <input type="hidden" name="action" value="hurdaya_ayir">
        <div class="modal-header bg-warning-subtle">
            <h6 class="modal-title"><i class="bi bi-trash3 me-1"></i>Hurdaya Ayırma — <?= h($row['ad']) ?></h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-3">
            <div class="col-12">
                <div class="alert alert-warning py-2 small mb-0">
                    Mevcut stok: <strong><?= number_format($mevcutStok, 2, ',', '.') ?> <?= h($row['birim']) ?></strong>.
                    Kayıt hurda çıkışı olarak hareket defterine yazılır, stoktan düşer ve
                    <strong>HURDAYA AYIRMA TUTANAĞI</strong> yazdırılır.
                </div>
            </div>
            <div class="col-6"><label class="form-label">Tarih</label>
                <input type="date" name="h_tarih" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-6"><label class="form-label">Miktar (<?= h($row['birim']) ?>) <span class="text-danger">*</span></label>
                <input type="text" name="h_miktar" class="form-control" value="<?= $mevcutStok == (int)$mevcutStok ? (int)$mevcutStok : number_format($mevcutStok,2,'.','') ?>" required inputmode="decimal"></div>
            <div class="col-12"><label class="form-label">Hurdaya Ayrılma Sebebi <span class="text-danger">*</span></label>
                <textarea name="h_sebep" class="form-control" rows="2" required
                          placeholder="Arızalı / tamiri ekonomik değil / kırık / ömrünü doldurdu…"></textarea></div>
            <div class="col-6"><label class="form-label">Teslim Alan <span class="text-muted small">(hurda sahası/kişi)</span></label>
                <input type="text" name="h_teslim_alan" class="form-control"></div>
            <div class="col-6"><label class="form-label">Onaylayan</label>
                <input type="text" name="h_onay" class="form-control"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
            <button class="btn btn-warning btn-sm"><i class="bi bi-trash3 me-1"></i>Hurdaya Ayır</button>
        </div>
    </form>
</div></div></div>
<?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
