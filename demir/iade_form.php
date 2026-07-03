<?php
/**
 * demir/iade_form.php — İade tutanağı giriş / düzenleme
 * İade no otomatik: {PROJE}-{IADEEDEN_KOD}-IADE-{NNN} (ör. U030-OSM-IADE-001)
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/_iade_ortak.php';
iade_semasi_kur($pdoDemir);

$editId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null; $lines = [];
if ($editId) {
    $s = $pdoDemir->prepare("SELECT * FROM demir_iade_tutanaklar WHERE id=?"); $s->execute([$editId]);
    $row = $s->fetch() ?: null;
    if (!$row) { flash('error','İade tutanağı bulunamadı.'); redirect('iade_tutanaklar.php'); }
    $k = $pdoDemir->prepare("SELECT * FROM demir_iade_kalemleri WHERE iade_id=? ORDER BY id"); $k->execute([$editId]);
    $lines = $k->fetchAll();
}

$caplar     = $pdoDemir->query("SELECT id,ad FROM demir_caplar WHERE aktif=1 ORDER BY sira, ad")->fetchAll();
$taseronlar = $pdoDemir->query("SELECT id,ad,kod FROM demir_taseronlar WHERE aktif=1 ORDER BY ad")->fetchAll();
$projeler   = $pdoDemir->query("SELECT id,kod,aciklama FROM demir_projeler WHERE aktif=1 ORDER BY kod")->fetchAll();

$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'iade_eden_id'   => ctype_digit($_POST['iade_eden_id'] ?? '') ? (int)$_POST['iade_eden_id'] : null,
        'teslim_alan_id' => ctype_digit($_POST['teslim_alan_id'] ?? '') ? (int)$_POST['teslim_alan_id'] : null,
        'proje_id'       => ctype_digit($_POST['proje_id'] ?? '') ? (int)$_POST['proje_id'] : null,
        'iade_tarih'     => $_POST['iade_tarih'] ?? '',
        'arac_plaka'     => strtoupper(trim($_POST['arac_plaka'] ?? '')),
        'dorse_plaka'    => strtoupper(trim($_POST['dorse_plaka'] ?? '')),
        'aciklama'       => trim($_POST['aciklama'] ?? ''),
    ];
    // Kalemler
    $capIds  = $_POST['cap_id'] ?? [];
    $miktars = $_POST['miktar'] ?? [];
    $baglar  = $_POST['bag'] ?? [];
    $kalemler = [];
    foreach ($capIds as $i => $cid) {
        if (!ctype_digit((string)$cid)) continue;
        $m = iade_num($miktars[$i] ?? '');
        $b = ctype_digit((string)($baglar[$i] ?? '')) ? (int)$baglar[$i] : null;
        if ($m === null || $m == 0) continue;
        $kalemler[] = ['cap_id'=>(int)$cid, 'm'=>$m, 'b'=>$b];
    }

    if (!$d['iade_eden_id'])                              $formError = 'İade eden taşeron zorunludur.';
    elseif (!$d['iade_tarih'])                            $formError = 'İade tarihi zorunludur.';
    elseif ($d['teslim_alan_id'] && $d['teslim_alan_id']===$d['iade_eden_id']) $formError = 'İade eden ile teslim alan aynı taşeron olamaz.';
    elseif (!$kalemler)                                   $formError = 'En az bir çap/tonaj satırı girin.';

    if (!$formError) {
        if ($editId) {
            $iadeNo = $row['iade_no'];
        } else {
            $pk = ''; foreach ($projeler as $p) if ($p['id']==$d['proje_id']) $pk=$p['kod'];
            $ek = ''; foreach ($taseronlar as $t) if ($t['id']==$d['iade_eden_id']) $ek=$t['kod'];
            $iadeNo = iade_no_uret($pdoDemir, $pk, $ek);
        }
        $params = [$iadeNo, $d['iade_tarih']?:null, $d['iade_eden_id'], $d['teslim_alan_id'], $d['proje_id'],
                   $d['arac_plaka']?:null, $d['dorse_plaka']?:null, $d['aciklama']?:null];
        if ($editId) {
            $params[] = $editId;
            $pdoDemir->prepare("UPDATE demir_iade_tutanaklar SET iade_no=?, iade_tarih=?, iade_eden_id=?, teslim_alan_id=?, proje_id=?,
                arac_plaka=?, dorse_plaka=?, aciklama=? WHERE id=?")->execute($params);
            $pdoDemir->prepare("DELETE FROM demir_iade_kalemleri WHERE iade_id=?")->execute([$editId]);
            $tid = $editId;
        } else {
            $params[] = current_user_id();
            $pdoDemir->prepare("INSERT INTO demir_iade_tutanaklar (iade_no, iade_tarih, iade_eden_id, teslim_alan_id, proje_id,
                arac_plaka, dorse_plaka, aciklama, created_by) VALUES (?,?,?,?,?,?,?,?,?)")->execute($params);
            $tid = (int)$pdoDemir->lastInsertId();
        }
        $ins = $pdoDemir->prepare("INSERT INTO demir_iade_kalemleri (iade_id, cap_id, miktar_ton, bag_adeti) VALUES (?,?,?,?)");
        foreach ($kalemler as $kl) { $ins->execute([$tid, $kl['cap_id'], $kl['m'], $kl['b']]); }
        flash('success', 'İade tutanağı ' . ($editId ? 'güncellendi' : 'oluşturuldu') . ': ' . $iadeNo);
        redirect('iade_detay.php?id=' . $tid);
    } else {
        $row = array_merge((array)$row, $d);
        $lines = [];
        foreach ($capIds as $i => $cid) {
            $lines[] = ['cap_id'=>$cid, 'miktar_ton'=>$miktars[$i]??'', 'bag_adeti'=>$baglar[$i]??''];
        }
    }
}

$pageTitle = ($editId ? 'İade Düzenle' : 'Yeni İade') . ' — Demir Takip';
require_once __DIR__ . '/../includes/header.php';
$v = fn($k) => h($row[$k] ?? '');
if (!$lines) $lines = [['cap_id'=>'','miktar_ton'=>'','bag_adeti'=>'']];
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="iade_tutanaklar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-arrow-return-left text-dark me-2"></i><?= $editId ? 'İade Düzenle ('.h($row['iade_no']).')' : 'Yeni İade Tutanağı' ?></h4>
</div>

<?php if ($formError): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($formError) ?></div><?php endif; ?>

<form method="post">
<div class="card mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> İade Bilgileri
        <?php if (!$editId): ?><span class="text-muted small ms-2">— No kaydederken otomatik üretilecek</span><?php endif; ?>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">İade Eden Taşeron <span class="text-danger">*</span></label><select name="iade_eden_id" class="form-select" required><option value="">—</option><?php foreach($taseronlar as $t): ?><option value="<?= $t['id'] ?>" <?= ($row['iade_eden_id']??'')==$t['id']?'selected':'' ?>><?= h($t['ad']) ?><?= $t['kod']?' ('.h($t['kod']).')':'' ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Teslim Alan Taşeron</label><select name="teslim_alan_id" class="form-select"><option value="">— Depoya / Şirkete İade</option><?php foreach($taseronlar as $t): ?><option value="<?= $t['id'] ?>" <?= ($row['teslim_alan_id']??'')==$t['id']?'selected':'' ?>><?= h($t['ad']) ?><?= $t['kod']?' ('.h($t['kod']).')':'' ?></option><?php endforeach; ?></select><div class="form-text">Boş bırakılırsa demir depoya/şirkete iade edilir.</div></div>
            <div class="col-md-2"><label class="form-label">Proje</label><select name="proje_id" class="form-select"><option value="">—</option><?php foreach($projeler as $p): ?><option value="<?= $p['id'] ?>" <?= ($row['proje_id']??'')==$p['id']?'selected':'' ?>><?= h($p['kod']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">İade Tarihi <span class="text-danger">*</span></label><input type="date" name="iade_tarih" class="form-control" required value="<?= $v('iade_tarih') ?>"></div>
            <div class="col-md-2"><label class="form-label">Araç Plaka</label><input name="arac_plaka" class="form-control text-uppercase" value="<?= $v('arac_plaka') ?>"></div>
            <div class="col-md-2"><label class="form-label">Dorse Plaka</label><input name="dorse_plaka" class="form-control text-uppercase" value="<?= $v('dorse_plaka') ?>"></div>
            <div class="col-md-10"><label class="form-label">Açıklama</label><input name="aciklama" class="form-control" value="<?= $v('aciklama') ?>" placeholder="Örn: İş sonu artan demir iadesi"></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check text-primary me-1"></i> İade Edilen Malzeme</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="satirEkle"><i class="bi bi-plus-lg"></i> Satır Ekle</button>
    </div>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm align-middle mb-0" id="klmTablo">
            <thead class="table-light"><tr><th style="width:40px">#</th><th style="width:220px">Çap</th><th class="text-end" style="width:160px">Tonaj (t)</th><th class="text-end" style="width:130px">Bağ Adeti</th><th style="width:44px"></th></tr></thead>
            <tbody>
            <?php foreach ($lines as $i => $ln): ?>
                <tr>
                    <td class="text-muted sno"><?= $i+1 ?></td>
                    <td><select name="cap_id[]" class="form-select form-select-sm"><option value="">—</option><?php foreach($caplar as $c): ?><option value="<?= $c['id'] ?>" <?= ($ln['cap_id']??'')==$c['id']?'selected':'' ?>><?= h($c['ad']) ?></option><?php endforeach; ?></select></td>
                    <td><input name="miktar[]" type="text" inputmode="decimal" class="form-control form-control-sm text-end mik" value="<?= h($ln['miktar_ton'] ?? '') ?>"></td>
                    <td><input name="bag[]" type="number" class="form-control form-control-sm text-end bag" value="<?= h($ln['bag_adeti'] ?? '') ?>"></td>
                    <td><button type="button" class="btn btn-xs btn-outline-danger satirSil"><i class="bi bi-x"></i></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold"><tr><td colspan="2" class="text-end">TOPLAM</td><td class="text-end" id="topMik">0</td><td class="text-end" id="topBag">0</td><td></td></tr></tfoot>
        </table>
    </div></div>
</div>

<div class="d-flex gap-2">
    <button class="btn btn-success btn-lg"><i class="bi bi-save me-1"></i><?= $editId ? 'Güncelle' : 'Oluştur' ?></button>
    <a href="iade_tutanaklar.php" class="btn btn-outline-secondary btn-lg">İptal</a>
</div>
</form>

<script>
var capOpts = '<option value="">—</option>' + <?= json_encode(implode('', array_map(fn($c)=>'<option value="'.$c['id'].'">'.htmlspecialchars($c['ad']).'</option>', $caplar))) ?>;
function yenile(){
    var i=1, tm=0, tb=0;
    document.querySelectorAll('#klmTablo tbody tr').forEach(function(tr){
        tr.querySelector('.sno').textContent=i++;
        var m=parseFloat((tr.querySelector('.mik').value||'').replace(',','.')); if(!isNaN(m)) tm+=m;
        var b=parseInt(tr.querySelector('.bag').value); if(!isNaN(b)) tb+=b;
    });
    document.getElementById('topMik').textContent=tm.toLocaleString('tr-TR',{maximumFractionDigits:3});
    document.getElementById('topBag').textContent=tb;
}
document.getElementById('satirEkle').addEventListener('click', function(){
    var tb=document.querySelector('#klmTablo tbody');
    var tr=document.createElement('tr');
    tr.innerHTML='<td class="text-muted sno"></td>'+
        '<td><select name="cap_id[]" class="form-select form-select-sm">'+capOpts+'</select></td>'+
        '<td><input name="miktar[]" type="text" inputmode="decimal" class="form-control form-control-sm text-end mik"></td>'+
        '<td><input name="bag[]" type="number" class="form-control form-control-sm text-end bag"></td>'+
        '<td><button type="button" class="btn btn-xs btn-outline-danger satirSil"><i class="bi bi-x"></i></button></td>';
    tb.appendChild(tr); yenile();
});
document.addEventListener('click', function(e){
    if(e.target.closest('.satirSil')){
        var rows=document.querySelectorAll('#klmTablo tbody tr');
        if(rows.length>1) e.target.closest('tr').remove(); else { e.target.closest('tr').querySelectorAll('input,select').forEach(el=>el.value=''); }
        yenile();
    }
});
document.addEventListener('input', function(e){ if(e.target.classList.contains('mik')||e.target.classList.contains('bag')) yenile(); });
yenile();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
