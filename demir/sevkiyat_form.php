<?php
/**
 * demir/sevkiyat_form.php — Demir sevkiyatı (irsaliye) giriş / düzenleme
 * Çap bazında İrsaliye ve Kantar miktarı; fark otomatik hesaplanır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','saha_sefi','depo']);
require_once __DIR__ . '/../includes/db_demir.php';

// ── Sayısal ayrıştırma (virgül/nokta) ─────────────────────────────────────────
function num_parse($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    // Binlik ayıracı yok varsay; sadece virgülü noktaya çevir
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
}

$editId  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row     = null;
$kalemMap = []; // cap_id => ['irs'=>, 'knt'=>]

if ($editId) {
    $s = $pdoDemir->prepare("SELECT * FROM demir_sevkiyatlar WHERE id=?");
    $s->execute([$editId]);
    $row = $s->fetch() ?: null;
    if (!$row) { flash('error','Sevkiyat bulunamadı.'); redirect('sevkiyatlar.php'); }
    $k = $pdoDemir->prepare("SELECT * FROM demir_sevkiyat_kalemleri WHERE sevkiyat_id=?");
    $k->execute([$editId]);
    foreach ($k->fetchAll() as $kk) {
        $kalemMap[$kk['cap_id']] = ['irs'=>$kk['irsaliye_miktar'], 'knt'=>$kk['kantar_miktar']];
    }
}

$caplar       = $pdoDemir->query("SELECT * FROM demir_caplar WHERE aktif=1 ORDER BY sira, ad")->fetchAll();
$tedarikciler = $pdoDemir->query("SELECT id,ad FROM demir_tedarikciler WHERE aktif=1 ORDER BY ad")->fetchAll();
$taseronlar   = $pdoDemir->query("SELECT id,ad FROM demir_taseronlar WHERE aktif=1 ORDER BY ad")->fetchAll();
$projeler     = $pdoDemir->query("SELECT id,kod,aciklama FROM demir_projeler WHERE aktif=1 ORDER BY kod")->fetchAll();

$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'irsaliye_no'      => trim($_POST['irsaliye_no'] ?? ''),
        'irsaliye_tarih'   => $_POST['irsaliye_tarih'] ?? '',
        'gelis_tarih'      => $_POST['gelis_tarih'] ?? '',
        'arac_plaka'       => strtoupper(trim($_POST['arac_plaka'] ?? '')),
        'dorse_plaka'      => strtoupper(trim($_POST['dorse_plaka'] ?? '')),
        'kantar_fis_no'    => trim($_POST['kantar_fis_no'] ?? ''),
        'tedarikci_id'     => ctype_digit($_POST['tedarikci_id'] ?? '') ? (int)$_POST['tedarikci_id'] : null,
        'proje_id'         => ctype_digit($_POST['proje_id'] ?? '') ? (int)$_POST['proje_id'] : null,
        'taseron_id'       => ctype_digit($_POST['taseron_id'] ?? '') ? (int)$_POST['taseron_id'] : null,
        'ifs_siparis_no'   => trim($_POST['ifs_siparis_no'] ?? ''),
        'getiren_firma'    => trim($_POST['getiren_firma'] ?? ''),
        'tir_plaka'        => strtoupper(trim($_POST['tir_plaka'] ?? '')),
        'ifs_giris_durumu' => trim($_POST['ifs_giris_durumu'] ?? ''),
        'aciklama'         => trim($_POST['aciklama'] ?? ''),
    ];

    // Kalemleri topla (en az bir çapta miktar olmalı)
    $kalemler = [];
    foreach ($caplar as $c) {
        $irs = num_parse($_POST['irs'][$c['id']] ?? '');
        $knt = num_parse($_POST['knt'][$c['id']] ?? '');
        if (($irs !== null && $irs != 0) || ($knt !== null && $knt != 0)) {
            $kalemler[] = ['cap_id'=>$c['id'], 'irs'=>$irs ?? 0, 'knt'=>$knt];
        }
    }

    if ($d['irsaliye_no'] === '')          $formError = 'İrsaliye numarası zorunludur.';
    elseif (!$d['irsaliye_tarih'])         $formError = 'İrsaliye tarihi zorunludur.';
    elseif (!$kalemler)                    $formError = 'En az bir çap için miktar girmelisiniz.';

    if (!$formError) {
        $projeKod = '';
        foreach ($projeler as $p) { if ($p['id'] == $d['proje_id']) { $projeKod = $p['kod']; break; } }
        $kod = trim($projeKod . $d['irsaliye_no']);

        $scanUrl = trim($_POST['scan_image_url'] ?? '') ?: null;
        $params = [
            $kod, $d['irsaliye_no'], $d['irsaliye_tarih'] ?: null, $d['gelis_tarih'] ?: null,
            $d['arac_plaka'] ?: null, $d['dorse_plaka'] ?: null, $d['kantar_fis_no'] ?: null,
            $d['tedarikci_id'], $d['ifs_siparis_no'] ?: null, $d['getiren_firma'] ?: null,
            $d['ifs_giris_durumu'] ?: null, $d['tir_plaka'] ?: null, $d['proje_id'], $d['taseron_id'],
            $d['aciklama'] ?: null, $scanUrl,
        ];

        if ($editId) {
            $sql = "UPDATE demir_sevkiyatlar SET kod=?, irsaliye_no=?, irsaliye_tarih=?, gelis_tarih=?,
                    arac_plaka=?, dorse_plaka=?, kantar_fis_no=?, tedarikci_id=?, ifs_siparis_no=?,
                    getiren_firma=?, ifs_giris_durumu=?, tir_plaka=?, proje_id=?, taseron_id=?, aciklama=?,
                    scan_image_url=COALESCE(?, scan_image_url)
                    WHERE id=?";
            $params[] = $editId;
            $pdoDemir->prepare($sql)->execute($params);
            $pdoDemir->prepare("DELETE FROM demir_sevkiyat_kalemleri WHERE sevkiyat_id=?")->execute([$editId]);
            $sevkId = $editId;
        } else {
            $sql = "INSERT INTO demir_sevkiyatlar
                    (kod, irsaliye_no, irsaliye_tarih, gelis_tarih, arac_plaka, dorse_plaka, kantar_fis_no,
                     tedarikci_id, ifs_siparis_no, getiren_firma, ifs_giris_durumu, tir_plaka, proje_id, taseron_id, aciklama, scan_image_url, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $params[] = current_user_id();
            $pdoDemir->prepare($sql)->execute($params);
            $sevkId = (int)$pdoDemir->lastInsertId();
        }

        $ins = $pdoDemir->prepare("INSERT INTO demir_sevkiyat_kalemleri (sevkiyat_id, cap_id, irsaliye_miktar, kantar_miktar) VALUES (?,?,?,?)");
        foreach ($kalemler as $kl) { $ins->execute([$sevkId, $kl['cap_id'], $kl['irs'], $kl['knt']]); }

        flash('success', 'Sevkiyat ' . ($editId ? 'güncellendi' : 'kaydedildi') . '.');
        redirect('sevkiyatlar.php');
    } else {
        // Hata: kullanıcının girdiklerini geri doldur
        $row = array_merge((array)$row, $d);
        foreach ($caplar as $c) {
            $kalemMap[$c['id']] = ['irs'=>$_POST['irs'][$c['id']] ?? '', 'knt'=>$_POST['knt'][$c['id']] ?? ''];
        }
    }
}

$pageTitle = ($editId ? 'Sevkiyat Düzenle' : 'Yeni Sevkiyat') . ' — Demir Takip';
require_once __DIR__ . '/../includes/header.php';

$v = fn($k) => h($row[$k] ?? '');
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="sevkiyatlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-truck text-dark me-2"></i><?= $editId ? 'Sevkiyat Düzenle' : 'Yeni Demir Sevkiyatı' ?></h4>
</div>

<?php if ($formError): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($formError) ?></div><?php endif; ?>

<form method="post" id="sevkForm">
<input type="hidden" name="scan_image_url" id="scanImageUrl" value="<?= h($row['scan_image_url'] ?? '') ?>">

<!-- Karekod / Belge ile otomatik doldur -->
<div class="card mb-3 border-primary" style="border-style:dashed">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-qr-code-scan text-primary me-1"></i> Karekod / Belge ile Otomatik Doldur <span class="text-muted small">(isteğe bağlı)</span></div>
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-7">
                <label class="form-label small mb-1">İrsaliye fotoğrafı veya PDF'i yükleyin</label>
                <input type="file" id="taraDosya" class="form-control form-control-sm" accept="image/*,application/pdf">
            </div>
            <div class="col-md-5">
                <button type="button" id="btnTara" class="btn btn-primary btn-sm"><i class="bi bi-magic me-1"></i> Karekod + AI ile Oku</button>
            </div>
        </div>
        <div id="taraDurum" class="small mt-2 text-muted">Karekod başlığı (irsaliye no, tarih, plaka, tedarikçi) doldurur; AI belgeden çap/miktar/sipariş çıkarır. Sonuçları kontrol edip kaydedin.</div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> İrsaliye Bilgileri</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">İrsaliye No <span class="text-danger">*</span></label><input name="irsaliye_no" class="form-control" required value="<?= $v('irsaliye_no') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Kantar Fiş No</label><input name="kantar_fis_no" class="form-control" value="<?= $v('kantar_fis_no') ?>"></div>
                    <div class="col-md-6"><label class="form-label">İrsaliye Tarihi <span class="text-danger">*</span></label><input type="date" name="irsaliye_tarih" class="form-control" required value="<?= $v('irsaliye_tarih') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Geliş Tarihi</label><input type="date" name="gelis_tarih" class="form-control" value="<?= $v('gelis_tarih') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Araç Plaka</label><input name="arac_plaka" class="form-control text-uppercase" value="<?= $v('arac_plaka') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Dorse Plaka</label><input name="dorse_plaka" class="form-control text-uppercase" value="<?= $v('dorse_plaka') ?>"></div>
                </div>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3 text-primary me-1"></i> Firma & Proje</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Tedarikçi</label><select name="tedarikci_id" class="form-select"><option value="">—</option><?php foreach($tedarikciler as $t): ?><option value="<?= $t['id'] ?>" <?= ($row['tedarikci_id']??'')==$t['id']?'selected':'' ?>><?= h($t['ad']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Proje</label><select name="proje_id" class="form-select"><option value="">—</option><?php foreach($projeler as $p): ?><option value="<?= $p['id'] ?>" <?= ($row['proje_id']??'')==$p['id']?'selected':'' ?>><?= h($p['kod']) ?> — <?= h($p['aciklama']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Verilen Taşeron</label><select name="taseron_id" class="form-select"><option value="">—</option><?php foreach($taseronlar as $t): ?><option value="<?= $t['id'] ?>" <?= ($row['taseron_id']??'')==$t['id']?'selected':'' ?>><?= h($t['ad']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Getiren Firma</label><input name="getiren_firma" class="form-control" value="<?= $v('getiren_firma') ?>"></div>
                    <div class="col-md-6"><label class="form-label">IFS Sipariş No</label><input name="ifs_siparis_no" class="form-control" value="<?= $v('ifs_siparis_no') ?>"></div>
                    <div class="col-md-6"><label class="form-label">IFS Giriş Durumu</label><input name="ifs_giris_durumu" class="form-control" value="<?= $v('ifs_giris_durumu') ?>" placeholder="ör. TESLİM ALINDI"></div>
                    <div class="col-12"><label class="form-label">Açıklama</label><textarea name="aciklama" class="form-control" rows="2"><?= $v('aciklama') ?></textarea></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-rulers text-primary me-1"></i> Çap Bazında Miktar (ton)</span>
                <small class="text-muted">Sadece gelen çapları doldurun</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="capTablo">
                        <thead class="table-light">
                            <tr><th>Çap</th><th class="text-end" style="width:130px">İrsaliye (t)</th><th class="text-end" style="width:130px">Kantar (t)</th><th class="text-end" style="width:110px">Fark</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($caplar as $c): $km = $kalemMap[$c['id']] ?? null; ?>
                            <tr>
                                <td class="fw-semibold"><?= h($c['ad']) ?> <span class="badge bg-light text-muted border ms-1" style="font-size:.62rem"><?= h($c['tip']) ?></span></td>
                                <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-end irs-inp" data-cap="<?= $c['id'] ?>" name="irs[<?= $c['id'] ?>]" value="<?= h($km['irs'] ?? '') ?>"></td>
                                <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-end knt-inp" data-cap="<?= $c['id'] ?>" name="knt[<?= $c['id'] ?>]" value="<?= h($km['knt'] ?? '') ?>"></td>
                                <td class="text-end fark-cell" data-cap="<?= $c['id'] ?>"><span class="text-muted">—</span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr><td class="text-end">TOPLAM</td><td class="text-end" id="topIrs">0</td><td class="text-end" id="topKnt">0</td><td class="text-end" id="topFark">0</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-success btn-lg"><i class="bi bi-save me-1"></i><?= $editId ? 'Güncelle' : 'Kaydet' ?></button>
            <a href="sevkiyatlar.php" class="btn btn-outline-secondary btn-lg">İptal</a>
        </div>
    </div>
</div>
</form>

<script>
function pf(v){ v=(v||'').toString().trim().replace(',','.'); var n=parseFloat(v); return isNaN(n)?null:n; }
function fmt(n){ return n.toLocaleString('tr-TR',{minimumFractionDigits:0,maximumFractionDigits:3}); }
function hesapla(){
    var ti=0, tk=0, tf=0;
    document.querySelectorAll('#capTablo tbody tr').forEach(function(tr){
        var irs=pf(tr.querySelector('.irs-inp').value);
        var knt=pf(tr.querySelector('.knt-inp').value);
        var cell=tr.querySelector('.fark-cell');
        if(irs!==null) ti+=irs;
        if(knt!==null) tk+=knt;
        if(irs!==null || knt!==null){
            var f=(knt===null?0:knt)-(irs===null?0:irs);
            tf+=f;
            var cls = Math.abs(f)<0.0005 ? 'text-muted' : (f<0?'text-danger':'text-success');
            cell.innerHTML='<span class="'+cls+'">'+(f>0?'+':'')+fmt(f)+'</span>';
        } else { cell.innerHTML='<span class="text-muted">—</span>'; }
    });
    document.getElementById('topIrs').textContent=fmt(ti);
    document.getElementById('topKnt').textContent=fmt(tk);
    var tfEl=document.getElementById('topFark');
    tfEl.textContent=(tf>0?'+':'')+fmt(tf);
    tfEl.className='text-end '+(Math.abs(tf)<0.0005?'':(tf<0?'text-danger':'text-success'));
}
document.querySelectorAll('.irs-inp,.knt-inp').forEach(function(el){ el.addEventListener('input',hesapla); });
hesapla();
</script>

<?php
// Karekod/AI için veri: tedarikçi VKN eşlemesi + çap listesi
$tedVknMap = [];
foreach ($pdoDemir->query("SELECT id, vkn FROM demir_tedarikciler WHERE vkn IS NOT NULL AND vkn<>''")->fetchAll() as $t) {
    $tedVknMap[(string)$t['vkn']] = (int)$t['id'];
}
$capJs = array_map(function($c){
    preg_match('/(\d+)/', $c['ad'], $m);
    return ['id'=>(int)$c['id'], 'num'=>isset($m[1])?(int)$m[1]:0, 'tip'=>$c['tip']];
}, $caplar);
?>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
<script>
if (window.pdfjsLib) pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
const TED_VKN = <?= json_encode($tedVknMap, JSON_UNESCAPED_UNICODE) ?>;
const CAP_LIST = <?= json_encode($capJs, JSON_UNESCAPED_UNICODE) ?>;

function capId(mm, tip){
    mm = parseInt(mm); tip=(tip||'').toString().toLowerCase();
    for (var c of CAP_LIST) if(c.num===mm && c.tip===tip) return c.id;
    for (var c of CAP_LIST) if(c.num===mm) return c.id;
    return null;
}
function setVal(name, val){ var el=document.querySelector('[name="'+name+'"]'); if(el && val!=null && val!=='') el.value=val; }
function durum(html, cls){ var e=document.getElementById('taraDurum'); e.className='small mt-2 '+(cls||'text-muted'); e.innerHTML=html; }
function jsqrDecode(canvas){ try{ var ctx=canvas.getContext('2d'); var d=ctx.getImageData(0,0,canvas.width,canvas.height); var r=jsQR(d.data,d.width,d.height,{inversionAttempts:'attemptBoth'}); return r?r.data:null; }catch(e){ return null; } }
async function qrFromImage(file){
    return await new Promise(function(res){ var img=new Image(); img.onload=function(){ var cv=document.createElement('canvas'); cv.width=img.width; cv.height=img.height; cv.getContext('2d').drawImage(img,0,0); res({qr:jsqrDecode(cv),canvas:cv}); }; img.onerror=function(){res({qr:null,canvas:null});}; img.src=URL.createObjectURL(file); });
}
async function qrFromPdf(file){
    var buf=await file.arrayBuffer(); var pdf=await pdfjsLib.getDocument({data:buf}).promise; var page=await pdf.getPage(1);
    var vp=page.getViewport({scale:3}); var cv=document.createElement('canvas'); cv.width=vp.width; cv.height=vp.height;
    await page.render({canvasContext:cv.getContext('2d'),viewport:vp}).promise; return {qr:jsqrDecode(cv),canvas:cv};
}
function parseGib(raw){ if(!raw) return null; try{ var j=JSON.parse(raw); var o={}; for(var k in j)o[k.toLowerCase()]=j[k]; return o; }catch(e){ return null; } }
async function scanKaydet(canvas,isPdf,file){
    try{
        if(isPdf){ var fd=new FormData(); fd.append('dosya',file); var r=await fetch('../api/pdf_kaydet.php',{method:'POST',body:fd}); var j=await r.json(); return j.ok?j.url:null; }
        var th=document.createElement('canvas'); var ra=Math.min(1000/canvas.width,1); th.width=Math.round(canvas.width*ra); th.height=Math.round(canvas.height*ra);
        th.getContext('2d').drawImage(canvas,0,0,th.width,th.height);
        var fd=new FormData(); fd.append('image',th.toDataURL('image/jpeg',0.8));
        var r=await fetch('../api/scan_kaydet.php',{method:'POST',body:fd}); var j=await r.json(); return j.ok?j.url:null;
    }catch(e){ return null; }
}
document.getElementById('btnTara').addEventListener('click', async function(){
    var f=document.getElementById('taraDosya').files[0];
    if(!f){ durum('Önce dosya seçin.','text-danger'); return; }
    var btn=this; btn.disabled=true;
    var isPdf=/\.pdf$/i.test(f.name)||f.type==='application/pdf';
    durum('<span class="spinner-border spinner-border-sm me-1"></span> Karekod okunuyor...');
    try{
        var out = isPdf ? await qrFromPdf(f) : await qrFromImage(f);
        var g=parseGib(out.qr); var bulundu=[];
        if(g){
            if(g.no){ setVal('irsaliye_no',g.no); bulundu.push('irsaliye no'); }
            if(g.tarih){ setVal('irsaliye_tarih', String(g.tarih).substr(0,10)); bulundu.push('tarih'); }
            if(g.plaka){ setVal('arac_plaka', String(g.plaka).toUpperCase().replace(/\s+/g,'')); bulundu.push('plaka'); }
            if(g.vkntckn && TED_VKN[String(g.vkntckn)]){ setVal('tedarikci_id', TED_VKN[String(g.vkntckn)]); bulundu.push('tedarikçi'); }
        }
        durum('<span class="spinner-border spinner-border-sm me-1"></span> AI belgeyi okuyor (çap/miktar/sipariş)...');
        var scanUrl=await scanKaydet(out.canvas,isPdf,f);
        if(scanUrl) document.getElementById('scanImageUrl').value=scanUrl;
        var fd=new FormData(); fd.append('dosya',f);
        var r=await fetch('../api/demir_okut.php',{method:'POST',body:fd}); var j=await r.json();
        if(j.ok && j.alanlar){
            var a=j.alanlar;
            if(!g){
                if(a.irsaliye_no) setVal('irsaliye_no',a.irsaliye_no);
                if(a.tarih) setVal('irsaliye_tarih',String(a.tarih).substr(0,10));
                if(a.arac_plaka) setVal('arac_plaka',String(a.arac_plaka).toUpperCase().replace(/\s+/g,''));
                if(a.tedarikci_vkn && TED_VKN[String(a.tedarikci_vkn)]) setVal('tedarikci_id',TED_VKN[String(a.tedarikci_vkn)]);
            }
            if(a.siparis_no) setVal('ifs_siparis_no', a.siparis_no);
            var eklenen=0;
            (a.kalemler||[]).forEach(function(k){ var cid=capId(k.cap_mm,k.tip); if(cid){ var inp=document.querySelector('.irs-inp[data-cap="'+cid+'"]'); if(inp && k.miktar_ton!=null){ inp.value=k.miktar_ton; eklenen++; } } });
            if(typeof hesapla==='function') hesapla();
            durum('<i class="bi bi-check-circle-fill text-success me-1"></i> Okundu. '+(bulundu.length?('Karekod: '+bulundu.join(', ')+'. '):'')+eklenen+' çap dolduruldu. <strong>Kontrol edip kaydedin.</strong>','text-success');
        } else if(g){
            durum('<i class="bi bi-exclamation-circle text-warning me-1"></i> Karekod okundu ('+bulundu.join(', ')+') ama AI çıkaramadı'+(j.msg?': '+j.msg:'')+'. Çapları elle girin.','text-warning');
        } else {
            durum('<i class="bi bi-x-circle text-danger me-1"></i> Karekod ve AI okunamadı'+(j.msg?': '+j.msg:'')+'. Elle girin.','text-danger');
        }
    }catch(e){ durum('<i class="bi bi-x-circle text-danger me-1"></i> Hata: '+e.message,'text-danger'); }
    btn.disabled=false;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
