<?php
/**
 * kamp/ziyaret_form.php — ZİYARETÇİ GİRİŞİ (yeni) / ziyaret kaydını düzelt (?id=)
 *
 *  • Ziyaretçi adı yazılınca daha önce gelmiş ziyaretçiler önerilir (?ziyaretci_ara=) — seçilince kimlik,
 *    telefon, kurum, plaka dolar. Önceki ziyaretlerinden birinde izinsiz giriş kaydı varsa uyarı çıkar.
 *  • "Kimin misafiri": kamp kişisi aranır (?kisi_ara=, Türkçe harf duyarsız) ya da serbest metin yazılır.
 *  • Gece penceresinde giriş: gece izni + onaylayan zorunlu; izinsiz bir giriş TESPİT edildiyse
 *    "İzinsiz giriş tespit edildi" ile yine kaydedilir (olay kaydı, kırmızı işaretli).
 *  • Kural denetimi çekirdekte (kamp_ziyaret_kaydet) — form yalnız yardımcıdır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

if (isset($_GET['kisi_ara'])) {
    header('Content-Type: application/json; charset=utf-8');
    $o = array_values(array_filter(kamp_kisi_ara($pdoKamp, (string)$_GET['kisi_ara']), fn($r) => $r['id'] > 0));
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}
if (isset($_GET['ziyaretci_ara'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(kamp_ziyaretci_ara($pdoKamp, (string)$_GET['ziyaretci_ara']), JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$z  = $id ? kamp_ziyaret_bul($pdoKamp, $id) : null;
if ($id && !$z) { flash('error', 'Ziyaret kaydı bulunamadı.'); redirect('ziyaretler.php'); }
if ($id ? !yetki_var('duzenle') : !(yetki_var('giris') || yetki_var('duzenle'))) {
    flash('error', $id ? 'Kaydı düzeltmek için değiştirme yetkisi gerekir.' : 'Ziyaretçi girişi için yetkiniz yok.');
    redirect('ziyaretler.php');
}

$hata = '';
$v = $z ?: ['ad_soyad' => '', 'kimlik_tur' => 'tc', 'kimlik_no' => '', 'uyruk' => '', 'telefon' => '', 'kurum' => '', 'plaka' => '',
            'kisi_id' => (int)($_GET['kisi_id'] ?? 0) ?: null, 'ziyaret_edilen' => '', 'bina_id' => null, 'amac' => '',
            'giris' => date('Y-m-d H:i:00'), 'cikis' => null, 'gece_izin' => 0, 'onaylayan' => '', 'ihlal' => 0, 'aciklama' => '', 'foto_url' => null];
if (!$z && $v['kisi_id'] && ($k = kamp_kisi_bul($pdoKamp, (int)$v['kisi_id']))) $v['ziyaret_edilen'] = kamp_kisi_ad($k);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $kayit = kamp_ziyaret_kaydet($pdoKamp, $_POST, $id, current_user_id());
        $fotoNot = '';
        if (!empty($_FILES['foto']) && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try { kamp_ziyaret_foto_kaydet($pdoKamp, $kayit, $_FILES['foto']); }
            catch (Throwable $e) { $fotoNot = ' — ⚠ görsel kaydedilemedi: ' . $e->getMessage(); }
        }
        $yeni = kamp_ziyaret_bul($pdoKamp, $kayit);
        foreach (['kisi_ad', 'kisi_soyad', 'bina_ad'] as $x) { unset($yeni[$x]); if ($z) unset($z[$x]); }
        kamp_audit('kamp_ziyaretler', $kayit, $id ? 'UPDATE' : 'INSERT', $z, $yeni);
        $i = kamp_ziyaret_isaret($yeni);
        flash($i['uyari'] ? 'warning' : 'success', $yeni['ad_soyad'] . ($id ? ' — kayıt düzeltildi.' : ' — giriş kaydedildi (' . kamp_zaman_yaz($yeni['giris']) . ').')
              . ($i['ihlal'] ? ' İZİNSİZ GİRİŞ olarak işaretlendi.' : '') . $fotoNot);
        redirect(($_POST['sonra'] ?? '') === 'yeni' ? 'ziyaret_form.php' : ($id ? 'ziyaretler.php?durum=hepsi' : 'ziyaretler.php?durum=iceride'));
    } catch (Throwable $e) {
        $hata = $e->getMessage();
        $v = array_merge($v, array_intersect_key($_POST, $v));
        $v['gece_izin'] = !empty($_POST['gece_izin']); $v['ihlal'] = !empty($_POST['ihlal']);
        $v['giris'] = kamp_zaman($_POST['giris'] ?? '') ?? $v['giris'];
        $v['cikis'] = kamp_zaman($_POST['cikis'] ?? '');
    }
}

[$geceBas, $geceBit] = kamp_gece_saat();
$pageTitle = ($id ? 'Ziyaret Kaydı' : 'Ziyaretçi Girişi') . ' — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<style>
.zy-sonuc{position:absolute;z-index:1060;left:0;right:0;max-height:260px;overflow:auto}
.zy-sonuc .list-group-item{cursor:pointer}
.zy-bolum{font-size:.78rem;letter-spacing:.04em;text-transform:uppercase;color:var(--bs-primary);font-weight:700;border-left:3px solid var(--bs-primary);padding-left:.5rem;margin:.25rem 0 .75rem}
</style>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i><?= $id ? 'Ziyaret Kaydını Düzelt' : 'Ziyaretçi Girişi' ?></h4>
  <a href="ziyaretler.php" class="btn btn-light btn-sm ms-auto">Deftere dön</a>
</div>
<?php if ($hata): ?><div class="alert alert-danger"><i class="bi bi-x-octagon me-1"></i><?= h($hata) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="row g-3" id="zyForm" autocomplete="off">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
      <div class="zy-bolum">Ziyaretçi</div>
      <div class="row g-2">
        <div class="col-md-8 position-relative"><label class="form-label small mb-0">Ad Soyad <span class="text-danger">*</span></label>
          <input name="ad_soyad" id="adSoyad" class="form-control" required maxlength="160" value="<?= h($v['ad_soyad']) ?>" placeholder="yazarken daha önce gelenler önerilir">
          <div class="list-group zy-sonuc shadow d-none" id="zySonuc"></div>
          <div class="small mt-1 d-none" id="zyOnceki"></div></div>
        <div class="col-md-4"><label class="form-label small mb-0">Uyruk</label>
          <input name="uyruk" class="form-control" maxlength="60" value="<?= h($v['uyruk']) ?>" placeholder="T.C. dışıysa"></div>
        <div class="col-md-4"><label class="form-label small mb-0">Belge türü</label>
          <select name="kimlik_tur" id="kimlikTur" class="form-select"><?php foreach (KAMP_KIMLIK_TUR as $k => $ad): ?><option value="<?= $k ?>"<?= $v['kimlik_tur'] === $k ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small mb-0">Kimlik / belge no</label>
          <input name="kimlik_no" id="kimlikNo" class="form-control" maxlength="40" value="<?= h($v['kimlik_no']) ?>"></div>
        <div class="col-md-4"><label class="form-label small mb-0">Telefon</label>
          <input name="telefon" class="form-control" maxlength="30" value="<?= h($v['telefon']) ?>"></div>
        <div class="col-md-8"><label class="form-label small mb-0">Geldiği firma / kurum</label>
          <input name="kurum" class="form-control" maxlength="150" value="<?= h($v['kurum']) ?>"></div>
        <div class="col-md-4"><label class="form-label small mb-0">Araç plakası</label>
          <input name="plaka" class="form-control text-uppercase" maxlength="20" value="<?= h($v['plaka']) ?>"></div>
        <div class="col-12"><label class="form-label small mb-0">Kimlik / kişi fotoğrafı <span class="text-muted">(isteğe bağlı)</span></label>
          <input type="file" name="foto" class="form-control form-control-sm" accept="image/*,application/pdf">
          <div class="form-text">Görsel yalnız sisteme giriş yapmış kullanıcılara gösterilir. <?= $v['foto_url'] ? '<a href="ziyaretler.php?foto=' . (int)$id . '" target="_blank">Mevcut görsel</a> — yeni dosya seçilirse değiştirilir.' : '' ?></div></div>
      </div>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-body">
      <div class="zy-bolum">Ziyaret</div>
      <div class="row g-2">
        <div class="col-md-8 position-relative"><label class="form-label small mb-0">Kimin misafiri <span class="text-danger">*</span></label>
          <input type="hidden" name="kisi_id" id="kisiId" value="<?= (int)$v['kisi_id'] ?: '' ?>">
          <div class="input-group"><input name="ziyaret_edilen" id="zyEdilen" class="form-control" maxlength="160" value="<?= h($v['ziyaret_edilen']) ?>" placeholder="kamp kişisini arayın ya da adını yazın">
            <span class="input-group-text <?= $v['kisi_id'] ? 'text-success' : 'text-muted' ?>" id="kisiDurum" title="kamp kişisine bağlı"><i class="bi <?= $v['kisi_id'] ? 'bi-link-45deg' : 'bi-pencil' ?>"></i></span></div>
          <div class="list-group zy-sonuc shadow d-none" id="kisiSonuc"></div>
          <div class="form-text" id="kisiNot"><?= $v['kisi_id'] ? 'Kamp kişisine bağlı — kişi kartında ziyaretçileri listelenir.' : 'Listeden seçilirse kişi kartına bağlanır; serbest metin de olur.' ?></div></div>
        <div class="col-md-4"><label class="form-label small mb-0">Gideceği bina</label>
          <select name="bina_id" class="form-select"><option value="">—</option>
            <?php foreach (kamp_binalar($pdoKamp) as $bid => $b): ?><option value="<?= $bid ?>"<?= (int)$v['bina_id'] === $bid ? ' selected' : '' ?>><?= h($b['ad']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-0">Ziyaret amacı</label>
          <input name="amac" class="form-control" list="dlAmac" maxlength="150" value="<?= h($v['amac']) ?>"></div>
      </div>
    </div></div>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
      <div class="zy-bolum">Saat & onay</div>
      <div class="row g-2">
        <div class="col-6"><label class="form-label small mb-0">Giriş <span class="text-danger">*</span></label>
          <input type="datetime-local" name="giris" id="giris" class="form-control" required value="<?= h(kamp_zaman_input($v['giris'])) ?>"></div>
        <div class="col-6"><label class="form-label small mb-0">Çıkış <span class="text-muted">(boş = içeride)</span></label>
          <input type="datetime-local" name="cikis" id="cikis" class="form-control" value="<?= h(kamp_zaman_input($v['cikis'])) ?>"></div>
      </div>
      <div class="alert alert-dark py-2 small mt-2 mb-2 d-none" id="geceUyari"><i class="bi bi-moon-stars me-1"></i>Giriş gece saatinde (<?= h(kamp_gece_etiket()) ?>): gece izni ve onaylayan zorunlu.</div>
      <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="gece_izin" value="1" id="geceIzin"<?= $v['gece_izin'] ? ' checked' : '' ?>>
        <label class="form-check-label" for="geceIzin">Gece kampta kalabilir / gece girişine izin verildi</label></div>
      <label class="form-label small mb-0 mt-2">Onaylayan (izni veren)</label>
      <input name="onaylayan" id="onaylayan" class="form-control" maxlength="120" value="<?= h($v['onaylayan']) ?>" placeholder="ad soyad — kamp sorumlusu, şantiye şefi…">
      <hr>
      <div class="form-check p-2 rounded border border-danger-subtle bg-danger-subtle"><input class="form-check-input ms-0 me-2" type="checkbox" name="ihlal" value="1" id="ihlal"<?= $v['ihlal'] ? ' checked' : '' ?>>
        <label class="form-check-label fw-semibold text-danger-emphasis" for="ihlal">İzinsiz giriş tespit edildi (olay kaydı)</label>
        <div class="small text-body-secondary ms-4">İzin verilmeden kampa giren bir kişi bulunduysa işaretleyin: kayıt onaysız açılır, kalıcı olarak kırmızı işaretlenir.
          Kimin içeri aldığını ve olayı açıklamaya yazın.</div></div>
      <label class="form-label small mb-0 mt-2">Açıklama / olay notu</label>
      <textarea name="aciklama" class="form-control" rows="3"><?= h($v['aciklama']) ?></textarea>
    </div></div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary flex-fill" name="sonra" value="liste"><i class="bi bi-check2 me-1"></i>Kaydet</button>
      <?php if (!$id): ?><button class="btn btn-outline-primary" name="sonra" value="yeni" title="Kaydet ve bir sonraki ziyaretçiyi gir"><i class="bi bi-plus-lg me-1"></i>Kaydet + yeni</button><?php endif; ?>
    </div>
  </div>
</form>
<datalist id="dlAmac"><?php foreach (KAMP_ZIYARET_AMAC as $a): ?><option value="<?= h($a) ?>"><?php endforeach; ?></datalist>

<script>
(function(){
  var GB = <?= json_encode($geceBas) ?>, GS = <?= json_encode($geceBit) ?>;
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function geceMi(v){ if (!v || v.length < 16) return false; var h = v.slice(11,16); return GB > GS ? (h >= GB || h < GS) : (h >= GB && h < GS); }
  var giris = document.getElementById('giris'), izin = document.getElementById('geceIzin'), onay = document.getElementById('onaylayan'), ihlal = document.getElementById('ihlal');
  function geceGuncelle(){
    var g = geceMi(giris.value) && !ihlal.checked;
    document.getElementById('geceUyari').classList.toggle('d-none', !g);
    onay.required = (izin.checked || g) && !ihlal.checked;
    izin.disabled = ihlal.checked;
  }
  [giris, izin, ihlal].forEach(function(e){ e.addEventListener('change', geceGuncelle); e.addEventListener('input', geceGuncelle); });
  geceGuncelle();

  // Arama kutusu yardımcısı: kutu, sonuç listesi, JSON adresi, satır çizici, seçim
  function arama(kutu, liste, url, ciz, sec, kosul){
    var t = null, sira = 0, veri = [], aktif = -1;
    function kapat(){ liste.classList.add('d-none'); liste.innerHTML = ''; aktif = -1; }
    function isaretle(){ Array.prototype.forEach.call(liste.children, function(c, i){ c.classList.toggle('active', i === aktif); }); }
    kutu.addEventListener('input', function(){
      clearTimeout(t); var q = kutu.value.trim();
      if (kosul && !kosul()) return kapat();
      if (q.length < 2) return kapat();
      t = setTimeout(function(){
        var no = ++sira;
        fetch(url + encodeURIComponent(q), {credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(js){
          if (no !== sira) return;
          veri = js || []; aktif = -1;
          if (!veri.length) return kapat();
          liste.innerHTML = veri.map(function(r, i){ return '<button type="button" class="list-group-item list-group-item-action py-1" data-i="' + i + '">' + ciz(r) + '</button>'; }).join('');
          liste.classList.remove('d-none');
        }).catch(kapat);
      }, 200);
    });
    liste.addEventListener('mousedown', function(e){ var b = e.target.closest('[data-i]'); if (!b) return; e.preventDefault(); sec(veri[+b.dataset.i]); kapat(); });
    kutu.addEventListener('keydown', function(e){
      if (liste.classList.contains('d-none')) return;
      if (e.key === 'ArrowDown') { aktif = Math.min(aktif + 1, veri.length - 1); isaretle(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { aktif = Math.max(aktif - 1, 0); isaretle(); e.preventDefault(); }
      else if (e.key === 'Enter' && aktif >= 0) { sec(veri[aktif]); kapat(); e.preventDefault(); }
      else if (e.key === 'Escape') kapat();
    });
    kutu.addEventListener('blur', function(){ setTimeout(kapat, 150); });
  }

  var f = document.getElementById('zyForm');
  arama(document.getElementById('adSoyad'), document.getElementById('zySonuc'), 'ziyaret_form.php?ziyaretci_ara=',
    function(r){ return '<div class="d-flex"><strong>' + esc(r.ad_soyad) + '</strong><span class="ms-auto small text-muted">' + r.adet + ' ziyaret · son ' + esc(r.son) + '</span></div>'
      + '<div class="small text-muted">' + esc([r.kimlik_no, r.uyruk, r.kurum, r.plaka].filter(Boolean).join(' · ')) + '</div>'
      + (r.ihlal ? '<span class="badge bg-danger">önceki ziyaretinde izinsiz giriş kaydı var</span>' : ''); },
    function(r){
      f.ad_soyad.value = r.ad_soyad; f.kimlik_tur.value = r.kimlik_tur || 'tc';
      ['kimlik_no','uyruk','telefon','kurum','plaka'].forEach(function(k){ if (!f[k].value) f[k].value = r[k] || ''; });
      var n = document.getElementById('zyOnceki');
      n.innerHTML = r.ihlal ? '<span class="text-danger fw-semibold"><i class="bi bi-exclamation-octagon me-1"></i>Bu kişinin önceki bir ziyaretinde İZİNSİZ GİRİŞ kaydı var.</span>'
                            : '<span class="text-muted"><i class="bi bi-clock-history me-1"></i>Daha önce ' + r.adet + ' kez geldi (son: ' + esc(r.son) + ').</span>';
      n.classList.remove('d-none');
    }, <?= $id ? 'function(){ return false; }' : 'null' ?>);

  var kisiId = document.getElementById('kisiId'), zyEdilen = document.getElementById('zyEdilen'), kd = document.getElementById('kisiDurum'), kn = document.getElementById('kisiNot');
  function bagGoster(bagli){ kd.className = 'input-group-text ' + (bagli ? 'text-success' : 'text-muted'); kd.innerHTML = '<i class="bi ' + (bagli ? 'bi-link-45deg' : 'bi-pencil') + '"></i>';
    kn.textContent = bagli ? 'Kamp kişisine bağlı — kişi kartında ziyaretçileri listelenir.' : 'Listeden seçilirse kişi kartına bağlanır; serbest metin de olur.'; }
  zyEdilen.addEventListener('input', function(){ if (kisiId.value) { kisiId.value = ''; bagGoster(false); } });
  arama(zyEdilen, document.getElementById('kisiSonuc'), 'ziyaret_form.php?kisi_ara=',
    function(r){ return '<div class="d-flex"><strong>' + esc(r.ad) + '</strong><span class="ms-auto small text-muted">' + esc(r.kat) + '</span></div>'
      + '<div class="small text-muted">' + esc([r.firma, r.gorev].filter(Boolean).join(' · ')) + (r.yer ? ' · <span class="text-success">' + esc(r.yer) + '</span>' : ' · <span class="text-warning-emphasis">kampta kalmıyor</span>') + '</div>'; },
    function(r){ zyEdilen.value = r.ad; kisiId.value = r.id; bagGoster(true); });

  // Giriş/çıkış ileri zaman olamaz
  function simdi(){ var d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0,16); }
  giris.max = simdi(); document.getElementById('cikis').max = simdi();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
