<?php
/**
 * demir/tutanak_form.php — Taşerona teslim tutanağı giriş / düzenleme
 * Tutanak no otomatik üretilir: {PROJE}-{TASERON_KOD}-{SIRA} (ör. U030-DNR-005)
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_demir.php';

function num_parse($v){ $v=trim((string)$v); if($v==='')return null; $v=str_replace(',','.',$v); return is_numeric($v)?(float)$v:null; }

function tutanak_no_uret(PDO $pdo, string $projeKod, string $taseronKod): string {
    $prefix = ($projeKod ?: 'GN') . '-' . ($taseronKod ?: 'TSR') . '-';
    $s = $pdo->prepare("SELECT tutanak_no FROM demir_tutanaklar WHERE tutanak_no LIKE ?");
    $s->execute([$prefix . '%']);
    $max = 0;
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $no) {
        if (preg_match('/-(\d+)$/', (string)$no, $m)) $max = max($max, (int)$m[1]);
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

$editId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null; $lines = [];
if ($editId) {
    $s = $pdoDemir->prepare("SELECT * FROM demir_tutanaklar WHERE id=?"); $s->execute([$editId]);
    $row = $s->fetch() ?: null;
    if (!$row) { flash('error','Tutanak bulunamadı.'); redirect('tutanaklar.php'); }
    $k = $pdoDemir->prepare("SELECT * FROM demir_tutanak_kalemleri WHERE tutanak_id=? ORDER BY id"); $k->execute([$editId]);
    $lines = $k->fetchAll();
}

$caplar     = $pdoDemir->query("SELECT id,ad FROM demir_caplar WHERE aktif=1 ORDER BY sira, ad")->fetchAll();
$taseronlar = $pdoDemir->query("SELECT id,ad,kod FROM demir_taseronlar WHERE aktif=1 ORDER BY ad")->fetchAll();
$projeler   = $pdoDemir->query("SELECT id,kod,aciklama FROM demir_projeler WHERE aktif=1 ORDER BY kod")->fetchAll();

$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'taseron_id'       => ctype_digit($_POST['taseron_id'] ?? '') ? (int)$_POST['taseron_id'] : null,
        'proje_id'         => ctype_digit($_POST['proje_id'] ?? '') ? (int)$_POST['proje_id'] : null,
        'tutanak_tarih'    => $_POST['tutanak_tarih'] ?? '',
        'arac_plaka'       => strtoupper(trim($_POST['arac_plaka'] ?? '')),
        'dorse_plaka'      => strtoupper(trim($_POST['dorse_plaka'] ?? '')),
        'sozlesme_kapsami' => trim($_POST['sozlesme_kapsami'] ?? ''),
        'aciklama'         => trim($_POST['aciklama'] ?? ''),
    ];
    // Kalemler
    $capIds = $_POST['cap_id'] ?? []; $irsNos = $_POST['irsaliye_no'] ?? [];
    $miktars = $_POST['miktar'] ?? []; $baglar = $_POST['bag'] ?? [];
    $kalemler = [];
    foreach ($capIds as $i => $cid) {
        if (!ctype_digit((string)$cid)) continue;
        $m = num_parse($miktars[$i] ?? '');
        $b = ctype_digit((string)($baglar[$i] ?? '')) ? (int)$baglar[$i] : null;
        $irs = trim((string)($irsNos[$i] ?? ''));
        if ($m === null && !$irs) continue;
        $kalemler[] = ['cap_id'=>(int)$cid, 'irs'=>$irs?:null, 'm'=>$m ?? 0, 'b'=>$b];
    }

    if (!$d['taseron_id'])       $formError = 'Taşeron zorunludur.';
    elseif (!$d['tutanak_tarih']) $formError = 'Tutanak tarihi zorunludur.';
    elseif (!$kalemler)          $formError = 'En az bir teslim kalemi girin.';

    if (!$formError) {
        // Tutanak no (yeni ise üret)
        if ($editId) {
            $tutNo = $row['tutanak_no'];
        } else {
            $pk = ''; foreach ($projeler as $p) if ($p['id']==$d['proje_id']) $pk=$p['kod'];
            $tk = ''; foreach ($taseronlar as $t) if ($t['id']==$d['taseron_id']) $tk=$t['kod'];
            $tutNo = tutanak_no_uret($pdoDemir, $pk, $tk);
        }
        $params = [$tutNo, $d['tutanak_tarih']?:null, $d['taseron_id'], $d['proje_id'],
                   $d['arac_plaka']?:null, $d['dorse_plaka']?:null, $d['sozlesme_kapsami']?:null, $d['aciklama']?:null];
        if ($editId) {
            $params[] = $editId;
            $pdoDemir->prepare("UPDATE demir_tutanaklar SET tutanak_no=?, tutanak_tarih=?, taseron_id=?, proje_id=?,
                arac_plaka=?, dorse_plaka=?, sozlesme_kapsami=?, aciklama=? WHERE id=?")->execute($params);
            $pdoDemir->prepare("DELETE FROM demir_tutanak_kalemleri WHERE tutanak_id=?")->execute([$editId]);
            $tid = $editId;
        } else {
            $params[] = current_user_id();
            $pdoDemir->prepare("INSERT INTO demir_tutanaklar (tutanak_no, tutanak_tarih, taseron_id, proje_id,
                arac_plaka, dorse_plaka, sozlesme_kapsami, aciklama, created_by) VALUES (?,?,?,?,?,?,?,?,?)")->execute($params);
            $tid = (int)$pdoDemir->lastInsertId();
        }
        $ins = $pdoDemir->prepare("INSERT INTO demir_tutanak_kalemleri (tutanak_id, cap_id, irsaliye_no, miktar_ton, bag_adeti) VALUES (?,?,?,?,?)");
        foreach ($kalemler as $kl) { $ins->execute([$tid, $kl['cap_id'], $kl['irs'], $kl['m'], $kl['b']]); }
        flash('success', 'Tutanak ' . ($editId ? 'güncellendi' : 'oluşturuldu') . ': ' . $tutNo);
        redirect('tutanak_detay.php?id=' . $tid);
    } else {
        $row = array_merge((array)$row, $d);
        // Girilen kalemleri geri doldur
        $lines = [];
        foreach ($capIds as $i => $cid) {
            $lines[] = ['cap_id'=>$cid, 'irsaliye_no'=>$irsNos[$i]??'', 'miktar_ton'=>$miktars[$i]??'', 'bag_adeti'=>$baglar[$i]??''];
        }
    }
}

$pageTitle = ($editId ? 'Tutanak Düzenle' : 'Yeni Tutanak') . ' — Demir Takip';
require_once __DIR__ . '/../includes/header.php';
$v = fn($k) => h($row[$k] ?? '');
if (!$lines) $lines = [['cap_id'=>'','irsaliye_no'=>'','miktar_ton'=>'','bag_adeti'=>'']]; // en az 1 boş satır
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="tutanaklar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-file-earmark-check text-dark me-2"></i><?= $editId ? 'Tutanak Düzenle ('.h($row['tutanak_no']).')' : 'Yeni Teslim Tutanağı' ?></h4>
</div>

<?php if ($formError): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($formError) ?></div><?php endif; ?>

<form method="post">
<div class="card mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> Tutanak Bilgileri
        <?php if (!$editId): ?><span class="text-muted small ms-2">— No kaydederken otomatik üretilecek</span><?php endif; ?>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Zimmet Yapılan Taşeron <span class="text-danger">*</span></label><select name="taseron_id" class="form-select" required><option value="">—</option><?php foreach($taseronlar as $t): ?><option value="<?= $t['id'] ?>" <?= ($row['taseron_id']??'')==$t['id']?'selected':'' ?>><?= h($t['ad']) ?><?= $t['kod']?' ('.h($t['kod']).')':'' ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Proje</label><select name="proje_id" class="form-select"><option value="">—</option><?php foreach($projeler as $p): ?><option value="<?= $p['id'] ?>" <?= ($row['proje_id']??'')==$p['id']?'selected':'' ?>><?= h($p['kod']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Tutanak Tarihi <span class="text-danger">*</span></label><input type="date" name="tutanak_tarih" class="form-control" required value="<?= $v('tutanak_tarih') ?>"></div>
            <div class="col-md-2"><label class="form-label">Araç Plaka</label><input name="arac_plaka" class="form-control text-uppercase" value="<?= $v('arac_plaka') ?>"></div>
            <div class="col-md-2"><label class="form-label">Dorse Plaka</label><input name="dorse_plaka" class="form-control text-uppercase" value="<?= $v('dorse_plaka') ?>"></div>
            <div class="col-12"><label class="form-label">Sözleşme Kapsamı / Açıklama</label><textarea name="sozlesme_kapsami" class="form-control" rows="2"><?= $v('sozlesme_kapsami') ?></textarea></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check text-primary me-1"></i> Teslim Edilen Malzeme</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="satirEkle"><i class="bi bi-plus-lg"></i> Satır Ekle</button>
    </div>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm align-middle mb-0" id="klmTablo">
            <thead class="table-light"><tr><th style="width:40px">#</th><th>İrsaliye No</th><th style="width:180px">Çap</th><th class="text-end" style="width:140px">Tonaj (t)</th><th class="text-end" style="width:110px">Bağ Adeti</th><th style="width:44px"></th></tr></thead>
            <tbody>
            <?php foreach ($lines as $i => $ln): ?>
                <tr>
                    <td class="text-muted sno"><?= $i+1 ?></td>
                    <td><input name="irsaliye_no[]" class="form-control form-control-sm" value="<?= h($ln['irsaliye_no'] ?? '') ?>"></td>
                    <td><select name="cap_id[]" class="form-select form-select-sm"><option value="">—</option><?php foreach($caplar as $c): ?><option value="<?= $c['id'] ?>" <?= ($ln['cap_id']??'')==$c['id']?'selected':'' ?>><?= h($c['ad']) ?></option><?php endforeach; ?></select></td>
                    <td><input name="miktar[]" type="text" inputmode="decimal" class="form-control form-control-sm text-end mik" value="<?= h($ln['miktar_ton'] ?? '') ?>"></td>
                    <td><input name="bag[]" type="number" class="form-control form-control-sm text-end bag" value="<?= h($ln['bag_adeti'] ?? '') ?>"></td>
                    <td><button type="button" class="btn btn-xs btn-outline-danger satirSil"><i class="bi bi-x"></i></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold"><tr><td colspan="3" class="text-end">TOPLAM</td><td class="text-end" id="topMik">0</td><td class="text-end" id="topBag">0</td><td></td></tr></tfoot>
        </table>
    </div></div>
</div>

<div class="d-flex gap-2">
    <button class="btn btn-success btn-lg"><i class="bi bi-save me-1"></i><?= $editId ? 'Güncelle' : 'Oluştur' ?></button>
    <a href="tutanaklar.php" class="btn btn-outline-secondary btn-lg">İptal</a>
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
        '<td><input name="irsaliye_no[]" class="form-control form-control-sm"></td>'+
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
