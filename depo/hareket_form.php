<?php
/**
 * hareket_form.php — Günlük depo giriş/çıkış kaydı (elle)
 *
 * Excel içe aktarması geçmişi getirir; bu form GÜNLÜK işleyişi tutar:
 * malzeme geldi → giriş, taşerona/birine verildi → çıkış.
 * Elle kayıtlar (elle=1) Excel tam yenilemesinde SİLİNMEZ.
 *
 * "Stok kalemine işle" seçilirse hareket depo_kalemler'in GELEN/GİDEN'ine de
 * yazılır → canlı stok anında güncellenir; kayıt silinir/düzenlenirse geri alınır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

dp_hareket_semasi_kur($pdoDepo);

// ── Malzeme açılır menüsü için arama ucu (JSON) ──────────────────────────────
if (isset($_GET['kalem_ara'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(dp_kalem_ara($pdoDepo, (string)$_GET['kalem_ara'], null, 25), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { echo json_encode([]); }
    exit;
}

$id  = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;
if ($id) {
    $st = $pdoDepo->prepare("SELECT * FROM depo_hareketler WHERE id=? AND elle=1");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { flash('error', 'Kayıt bulunamadı (Excel kayıtları buradan düzenlenmez — Excel esastır).'); redirect('hareketler.php'); }
}
$tur = $row['tur'] ?? (($_GET['tur'] ?? '') === 'cikis' ? 'cikis' : 'giris');
$pageTitle = ($id ? 'Hareket Düzenle' : ($tur === 'giris' ? 'Yeni Giriş' : 'Yeni Çıkış')) . ' — Depo';

// ── Kaydet ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tur     = ($_POST['tur'] ?? 'giris') === 'cikis' ? 'cikis' : 'giris';
    $kaynak  = ($_POST['kaynak'] ?? 'depo') === 'taseron' ? 'taseron' : 'depo';
    $malzeme = trim((string)($_POST['malzeme'] ?? ''));
    $miktar  = dp_sayi($_POST['miktar'] ?? '');
    $tarih   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['tarih'] ?? '') ? $_POST['tarih'] : date('Y-m-d');
    $kalemId = isset($_POST['kalem_id']) && ctype_digit((string)$_POST['kalem_id']) ? (int)$_POST['kalem_id'] : null;
    if (empty($_POST['stok_isle'])) $kalemId = null;   // kutu kapalıysa yalnız deftere yazılır
    $hurda   = ($tur === 'cikis' && !empty($_POST['hurda'])) ? 1 : 0;   // hurda yalnız çıkışta

    if ($malzeme === '' || $miktar <= 0) {
        flash('error', 'Malzeme adı ve sıfırdan büyük miktar zorunludur.');
    } else {
        if ($kalemId) {   // kalem gerçekten var mı
            $v = $pdoDepo->prepare("SELECT id FROM depo_kalemler WHERE id=?"); $v->execute([$kalemId]);
            if (!$v->fetchColumn()) $kalemId = null;
        }
        $alanlar = [
            $tur, $kaynak, $tarih,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['belge_tarihi'] ?? '') ? $_POST['belge_tarihi'] : null,
            trim((string)($_POST['belge_no'] ?? '')) ?: null,
            mb_substr($malzeme, 0, 255),
            trim((string)($_POST['ozellik'] ?? '')) ?: null,
            trim((string)($_POST['birim'] ?? '')) ?: 'Adet',
            $miktar,
            trim((string)($_POST['firma'] ?? '')) ?: null,
            trim((string)($_POST['teslim_alan'] ?? '')) ?: null,
            trim((string)($_POST['onay'] ?? '')) ?: null,
            trim((string)($_POST['lokasyon'] ?? '')) ?: null,
            trim((string)($_POST['aciklama'] ?? '')) ?: null,
            $kalemId,
            $hurda,
        ];
        try {
            $pdoDepo->beginTransaction();
            if ($id && $row) {
                dp_stok_islet($pdoDepo, $row['kalem_id'] ? (int)$row['kalem_id'] : null, $row['tur'], (float)$row['miktar'], -1);
                $u = $pdoDepo->prepare("UPDATE depo_hareketler SET tur=?, kaynak=?, tarih=?, belge_tarihi=?, belge_no=?,
                        malzeme=?, ozellik=?, birim=?, miktar=?, firma=?, teslim_alan=?, onay=?, lokasyon=?, aciklama=?, kalem_id=?, hurda=?
                        WHERE id=? AND elle=1");
                $u->execute(array_merge($alanlar, [$id]));
            } else {
                $i = $pdoDepo->prepare("INSERT INTO depo_hareketler
                        (tur,kaynak,tarih,belge_tarihi,belge_no,malzeme,ozellik,birim,miktar,firma,teslim_alan,onay,lokasyon,aciklama,kalem_id,hurda,elle)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
                $i->execute($alanlar);
                $id = (int)$pdoDepo->lastInsertId();
            }
            dp_stok_islet($pdoDepo, $kalemId, $tur, $miktar, 1);
            $pdoDepo->commit();
            flash('success', ($hurda ? 'Hurdaya ayırma' : ($tur === 'giris' ? 'Giriş' : 'Çıkış')) . ' kaydedildi'
                           . ($kalemId ? ' ve stok kalemine işlendi.' : '.'));
            redirect('hareket_sonuc.php?id=' . $id);
        } catch (Throwable $e) {
            if ($pdoDepo->inTransaction()) $pdoDepo->rollBack();
            flash('error', 'Kayıt hatası: ' . $e->getMessage());
        }
    }
}

// Düzenlemede/POST hatasında seçili stok kalemi (açılır menü etiketini doldurmak için)
$seciliKalem = null;
$seciliId = (int)($row['kalem_id'] ?? ($_POST['kalem_id'] ?? 0));
if ($seciliId) {
    $sk = $pdoDepo->prepare("SELECT id, kategori, ad, COALESCE(ozellik,'') ozellik, COALESCE(birim,'') birim,
                                    COALESCE(alan,'') alan, (sayim+gelen-giden) stok
                             FROM depo_kalemler WHERE id=?");
    $sk->execute([$seciliId]);
    $seciliKalem = $sk->fetch() ?: null;
}

$g = $tur === 'giris';
// Değer sırası: kayıt → gönderilen form → URL (aynı fişe satır eklerken taşınan bilgiler) → varsayılan
$val = fn($k, $d = '') => h((string)($row[$k] ?? $_POST[$k] ?? $_GET[$k] ?? $d));
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="hareketler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">
        <i class="bi <?= $g ? 'bi-box-arrow-in-down text-success' : 'bi-box-arrow-up text-danger' ?> me-1"></i>
        <?= $id ? 'Hareket Düzenle' : ($g ? 'Yeni Giriş (malzeme geldi)' : 'Yeni Çıkış (malzeme verildi)') ?>
    </h4>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>

<?php // İşlem sonu ekranındaki "aynı fişe malzeme ekle" ile gelindiyse fiş bilgileri doludur
if (!$id && !empty($_GET['belge_no'])): ?>
<div class="alert alert-info py-2 small">
    <i class="bi bi-collection me-1"></i><strong><?= h((string)$_GET['belge_no']) ?></strong> numaralı
    <?= $g ? 'irsaliyeye' : 'fişe' ?> yeni kalem ekliyorsunuz — ortak bilgiler dolduruldu, yalnız
    <strong>malzeme ve miktar</strong> girin. Fişin tüm kalemleri tek belgede yazdırılır.
</div>
<?php endif; ?>

<form method="post" class="card border-0 shadow-sm">
    <div class="card-body row g-3">
        <div class="col-6 col-md-2">
            <label class="form-label">Tür <span class="text-danger">*</span></label>
            <select name="tur" class="form-select" onchange="trDegis(this.value)">
                <option value="giris" <?= $g?'selected':'' ?>>Giriş</option>
                <option value="cikis" <?= !$g?'selected':'' ?>>Çıkış</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Kaynak</label>
            <select name="kaynak" class="form-select" id="kaynakSec">
                <option value="depo"    <?= ($row['kaynak'] ?? 'depo')==='depo'?'selected':'' ?>>Depo malzemesi</option>
                <option value="taseron" <?= ($row['kaynak'] ?? '')==='taseron'?'selected':'' ?>>Taşeron malzemesi</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Tarih <span class="text-danger">*</span></label>
            <input type="date" name="tarih" class="form-control" value="<?= $val('tarih', date('Y-m-d')) ?>" required>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" id="lblBelge"><?= $g ? 'İrsaliye No' : 'Fiş No' ?></label>
            <input type="text" name="belge_no" class="form-control" value="<?= $val('belge_no') ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Belge Tarihi</label>
            <input type="date" name="belge_tarihi" class="form-control" value="<?= $val('belge_tarihi') ?>">
        </div>

        <div class="col-md-5 position-relative">
            <label class="form-label">Malzeme <span class="text-danger">*</span>
                <span class="text-muted small fw-normal">— yazarak listeden seçin</span></label>
            <div class="input-group">
                <input type="text" name="malzeme" id="malzemeAd" class="form-control"
                       value="<?= $val('malzeme') ?>" required autocomplete="off" role="combobox"
                       aria-expanded="false" aria-controls="kalemOneri"
                       placeholder="Malzeme adı yazın (ör. ampul) — stok listesi açılır">
                <button class="btn btn-outline-secondary" type="button" id="listeBtn" title="Stok listesini aç">
                    <i class="bi bi-caret-down-fill"></i></button>
            </div>
            <input type="hidden" name="kalem_id" id="kalemId" value="<?= $seciliKalem ? (int)$seciliKalem['id'] : '' ?>">
            <div id="kalemOneri" class="list-group shadow position-absolute w-100"
                 style="z-index:1060;max-height:320px;overflow-y:auto;display:none"></div>
            <div id="kalemBilgi" class="form-text"></div>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Özellik / Marka</label>
            <input type="text" name="ozellik" class="form-control" value="<?= $val('ozellik') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Miktar <span class="text-danger">*</span></label>
            <input type="text" name="miktar" class="form-control" value="<?= $val('miktar') ?>" required inputmode="decimal">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Birim</label>
            <input type="text" name="birim" class="form-control" value="<?= $val('birim', 'Adet') ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label" id="lblFirma"><?= $g ? 'Gönderen Firma' : 'Çıkış Yapılan Firma / Taşeron' ?></label>
            <input type="text" name="firma" class="form-control" value="<?= $val('firma') ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Teslim Alan</label>
            <input type="text" name="teslim_alan" class="form-control" value="<?= $val('teslim_alan') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Onaylayan</label>
            <input type="text" name="onay" class="form-control" value="<?= $val('onay') ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Lokasyon</label>
            <input type="text" name="lokasyon" class="form-control" value="<?= $val('lokasyon') ?>">
        </div>

        <div class="col-md-7">
            <label class="form-label">Açıklama</label>
            <input type="text" name="aciklama" class="form-control" value="<?= $val('aciklama') ?>">
        </div>
        <div class="col-md-5">
            <label class="form-label">Stok bağı</label>
            <div class="border rounded p-2 h-100" id="stokKutu">
                <div class="form-check mb-1">
                    <input class="form-check-input" type="checkbox" name="stok_isle" value="1" id="stokIsle"
                           <?= $seciliKalem ? 'checked' : '' ?> <?= $seciliKalem ? '' : 'disabled' ?>>
                    <label class="form-check-label" for="stokIsle">
                        <strong>Stok kalemine işle</strong>
                        <span class="text-muted small">— canlı stoğun <?= $g ? 'GELEN' : 'GİDEN' ?>'i güncellenir</span>
                    </label>
                </div>
                <div class="small" id="stokDurum">
                    <?php if ($seciliKalem): ?>
                        <span class="badge bg-primary-subtle text-primary-emphasis"><?= h($seciliKalem['ad']) ?></span>
                        mevcut stok: <strong><?= number_format((float)$seciliKalem['stok'], 0, ',', '.') ?></strong>
                        <?= h($seciliKalem['birim']) ?>
                    <?php else: ?>
                        <span class="text-muted">Yukarıdan bir stok kalemi seçilirse burada mevcut stok görünür.
                        Listede olmayan malzeme de yazılabilir — o zaman yalnız deftere kaydedilir.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12" id="hurdaKutu" style="<?= $g ? 'display:none' : '' ?>">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="hurda" value="1" id="hurdaChk"
                       <?= !empty($row['hurda']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="hurdaChk">
                    <strong>Hurdaya ayırma</strong> <span class="text-muted small">— malzeme kullanım dışı; hurda listesinde ayrıca izlenir, stoktan düşer</span>
                </label>
            </div>
        </div>

        <div class="col-12">
            <button class="btn btn-<?= $g ? 'success' : 'danger' ?>"><i class="bi bi-save me-1"></i><?= $id ? 'Güncelle' : 'Kaydet' ?></button>
            <a href="hareketler.php" class="btn btn-outline-secondary">Vazgeç</a>
        </div>
    </div>
</form>

<script>
// Düzenlemede seçili olan stok kalemi (miktar değişince "bu çıkıştan sonra kalan" hesabı için)
var DP_SECILI = <?= $seciliKalem ? json_encode([
    'id'      => (int)$seciliKalem['id'],
    'ad'      => $seciliKalem['ad'],
    'ozellik' => $seciliKalem['ozellik'],
    'birim'   => $seciliKalem['birim'] ?: 'Adet',
    'alan'    => $seciliKalem['alan'],
    'stok'    => (float)$seciliKalem['stok'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) : 'null' ?>;
(function(){
  var inp   = document.getElementById('malzemeAd'),
      liste = document.getElementById('kalemOneri'),
      bilgi = document.getElementById('kalemBilgi'),
      gizli = document.getElementById('kalemId'),
      isle  = document.getElementById('stokIsle'),
      durum = document.getElementById('stokDurum'),
      btn   = document.getElementById('listeBtn'),
      mik   = document.querySelector('[name="miktar"]'),
      oz    = document.querySelector('[name="ozellik"]'),
      br    = document.querySelector('[name="birim"]'),
      lok   = document.querySelector('[name="lokasyon"]');
  var veri = [], sec = -1, zaman = null, sonAranan = null, secili = null;
  var fmt = function(n){ return (Math.round(n * 100) / 100).toLocaleString('tr-TR'); };

  function kapat(){ liste.style.display = 'none'; inp.setAttribute('aria-expanded','false'); sec = -1; }

  function ciz(){
    if (!veri.length) {
      liste.innerHTML = '<div class="list-group-item small text-muted">Eşleşen stok kalemi yok — bu ad yalnız deftere yazılır.</div>';
      liste.style.display = 'block'; inp.setAttribute('aria-expanded','true'); return;
    }
    liste.innerHTML = veri.map(function(k, i){
      var az = k.stok <= 0;
      return '<button type="button" class="list-group-item list-group-item-action py-1 px-2' + (i === sec ? ' active' : '') + '" data-i="' + i + '">'
        + '<div class="d-flex justify-content-between align-items-center gap-2">'
        + '<span class="text-truncate"><strong>' + esc(k.ad) + '</strong>'
        + (k.ozellik ? ' <span class="small' + (i === sec ? '' : ' text-muted') + '">' + esc(k.ozellik) + '</span>' : '') + '</span>'
        + '<span class="badge ' + (az ? 'bg-danger' : 'bg-success') + ' flex-shrink-0">' + fmt(k.stok) + ' ' + esc(k.birim) + '</span>'
        + '</div>'
        + '<div class="small' + (i === sec ? '' : ' text-muted') + '">' + esc(k.kategori) + (k.alan ? ' · ' + esc(k.alan) : '') + '</div>'
        + '</button>';
    }).join('');
    liste.style.display = 'block'; inp.setAttribute('aria-expanded','true');
  }

  function esc(t){ var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }

  function ara(q){
    if (sonAranan === q) { ciz(); return; }
    sonAranan = q;
    fetch('hareket_form.php?kalem_ara=' + encodeURIComponent(q), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){ veri = Array.isArray(j) ? j : []; sec = veri.length ? 0 : -1; ciz(); })
      .catch(function(){ veri = []; kapat(); });
  }

  function gecikmeliAra(){
    var q = inp.value.trim();
    clearTimeout(zaman);
    zaman = setTimeout(function(){ ara(q); }, 180);
  }

  function stokYaz(){
    if (!secili) {
      gizli.value = ''; isle.checked = false; isle.disabled = true;
      durum.innerHTML = '<span class="text-muted">Listede olmayan malzeme — yalnız hareket defterine yazılacak, stok kartı güncellenmez.</span>';
      bilgi.textContent = '';
      return;
    }
    gizli.value = secili.id; isle.disabled = false; isle.checked = true;
    var kalan = secili.stok - (parseFloat(String(mik.value).replace(',', '.')) || 0);
    var cikis = document.querySelector('[name="tur"]').value === 'cikis';
    durum.innerHTML = '<span class="badge bg-primary-subtle text-primary-emphasis">' + esc(secili.ad) + '</span> '
      + 'mevcut stok: <strong>' + fmt(secili.stok) + '</strong> ' + esc(secili.birim)
      + (secili.alan ? ' · ' + esc(secili.alan) : '')
      + (cikis ? '<br>bu çıkıştan sonra: <strong class="' + (kalan < 0 ? 'text-danger' : '') + '">' + fmt(kalan) + '</strong> ' + esc(secili.birim)
                 + (kalan < 0 ? ' <span class="text-danger">— stokta bu kadar yok, kayıt eksiye düşer</span>' : '') : '');
    bilgi.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> stok kalemi seçildi';
  }

  function uygula(k){
    secili = k;
    inp.value = k.ad;
    if (k.ozellik && !oz.value.trim()) oz.value = k.ozellik;
    if (k.birim) br.value = k.birim;
    if (k.alan && !lok.value.trim()) lok.value = k.alan;
    kapat(); stokYaz();
  }

  if (DP_SECILI) { var isaretli = isle.checked; secili = DP_SECILI; stokYaz(); isle.checked = isaretli; }

  inp.addEventListener('input', function(){ secili = null; stokYaz(); gecikmeliAra(); });
  inp.addEventListener('focus', function(){ if (inp.value.trim() === '') { sonAranan = null; ara(''); } });
  btn.addEventListener('click', function(){
    if (liste.style.display === 'block') { kapat(); return; }
    sonAranan = null; ara(inp.value.trim()); inp.focus();
  });
  mik.addEventListener('input', function(){ if (secili) stokYaz(); });

  inp.addEventListener('keydown', function(e){
    if (liste.style.display !== 'block') {
      if (e.key === 'ArrowDown') { sonAranan = null; ara(inp.value.trim()); e.preventDefault(); }
      return;
    }
    if (e.key === 'ArrowDown')      { sec = Math.min(sec + 1, veri.length - 1); ciz(); e.preventDefault(); }
    else if (e.key === 'ArrowUp')   { sec = Math.max(sec - 1, 0); ciz(); e.preventDefault(); }
    else if (e.key === 'Enter')     { if (veri[sec]) { uygula(veri[sec]); e.preventDefault(); } }
    else if (e.key === 'Escape')    { kapat(); }
    else if (e.key === 'Tab')       { if (veri[sec]) uygula(veri[sec]); }
  });
  liste.addEventListener('mousedown', function(e){
    var el = e.target.closest('[data-i]');
    if (el) { e.preventDefault(); uygula(veri[+el.dataset.i]); }
  });
  document.addEventListener('click', function(e){
    if (!liste.contains(e.target) && e.target !== inp && !btn.contains(e.target)) kapat();
  });
})();

function trDegis(t){
    var g = t === 'giris';
    document.getElementById('lblBelge').textContent = g ? 'İrsaliye No' : 'Fiş No';
    document.getElementById('lblFirma').textContent = g ? 'Gönderen Firma' : 'Çıkış Yapılan Firma / Taşeron';
    document.getElementById('hurdaKutu').style.display = g ? 'none' : '';
    if (g) document.getElementById('hurdaChk').checked = false;
    document.querySelector('[name="miktar"]').dispatchEvent(new Event('input'));
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
