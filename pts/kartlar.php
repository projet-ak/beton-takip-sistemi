<?php
/**
 * pts/kartlar.php — ArUco kart tanımlama + kart üretme/yazdırma
 *
 * Personel listesi `it_personel`'den gelir (PTS ayrı personel listesi TUTMAZ).
 * Her satırda kişinin aktif kartı, ArUco ID'si ve kartın önizlemesi durur.
 *
 * ⚠ Marker çizimi `AR.Dictionary(...).generateSVG(id)` ile — yani KIOSKUN OKUDUĞU
 * kütüphanenin kendisi üretir. Bit sırasını elle yorumlamadığımız için basılan kart
 * ile okunan kart birebir uyumludur; sunucuda OpenCV gerekmez.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_pts.php';
require_once __DIR__ . '/_ortak.php';

it_semasi_kur($pdoPts);          // it_personel garanti (PTS ile aynı DB)
pts_semasi_kur($pdoPts);
$pageTitle  = 'ArUco Kartlar — Personel Takip';
$yazabilir  = yetki_var('duzenle') || yetki_var('giris');

// ── İşlemler ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$yazabilir) { flash('error', 'Kart tanımlamak için yetkiniz yok.'); redirect('kartlar.php'); }
    $islem = $_POST['islem'] ?? '';
    $pid   = (int)($_POST['personel_id'] ?? 0);
    try {
        if ($islem === 'ver' || $islem === 'sicilden') {
            $p = it_personel_bul($pdoPts, $pid);
            if (!$p) throw new RuntimeException('Personel bulunamadı.');
            $marker = $islem === 'sicilden'
                ? pts_marker_sicilden((string)($p['sicil_no'] ?? ''))
                : (int)($_POST['marker_id'] ?? -1);
            $k = pts_kart_ver($pdoPts, $pid, $marker, current_user_id());
            audit_log($pdoPts, 'pts_kartlar', $k['id'], 'INSERT', null,
                      ['personel_id' => $pid, 'marker_id' => $marker], current_user_id());
            flash('success', trim($p['ad'] . ' ' . $p['soyad']) . ' → ArUco ID ' . $marker . ' tanımlandı. '
                . 'Kartı yazdırmayı unutmayın.');
        } elseif ($islem === 'iptal') {
            $p = it_personel_bul($pdoPts, $pid);
            $n = pts_kart_iptal($pdoPts, $pid);
            audit_log($pdoPts, 'pts_kartlar', 0, 'UPDATE', null, ['iptal_personel' => $pid], current_user_id());
            flash($n ? 'success' : 'info', $n
                ? trim(($p['ad'] ?? '') . ' ' . ($p['soyad'] ?? '')) . ' kartı iptal edildi.'
                : 'Bu personelde aktif kart yoktu.');
        }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect('kartlar.php' . (!empty($_POST['q']) ? '?q=' . urlencode((string)$_POST['q']) : ''));
}

// ── Liste ───────────────────────────────────────────────────────────────────
$q      = trim((string)($_GET['q'] ?? ''));
$durum  = $_GET['durum'] ?? 'kartsiz';        // kartsiz | kartli | hepsi
$st = $pdoPts->query("SELECT p.*, k.id AS kart_id, k.marker_id, k.sozluk, k.verildi
                        FROM it_personel p
                        LEFT JOIN pts_kartlar k ON k.personel_id = p.id AND k.iptal IS NULL
                       WHERE p.isten_cikis IS NULL
                       ORDER BY p.ad, p.soyad");
$hepsiListe = $st->fetchAll();

// ⚠ Arama SQL LIKE ile DEĞİL it_personel_suz() ile: Türkçe 'İ' LIKE'ta 'i' ile
// eşleşmiyor ve ad+soyad birlikte yazılınca tek alanda geçmediği için sonuç çıkmıyordu.
if ($q !== '') $hepsiListe = it_personel_suz($hepsiListe, $q);

$liste = array_values(array_filter($hepsiListe, function ($r) use ($durum) {
    if ($durum === 'kartli')  return !empty($r['kart_id']);
    if ($durum === 'kartsiz') return empty($r['kart_id']);
    return true;
}));
$sayac = ['kartli' => 0, 'kartsiz' => 0];
foreach ($hepsiListe as $r) $sayac[empty($r['kart_id']) ? 'kartsiz' : 'kartli']++;

require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-vcard text-primary me-2"></i>ArUco Kartlar</h4>
  <span class="badge bg-success"><?= $sayac['kartli'] ?> kartlı</span>
  <span class="badge bg-warning text-dark"><?= $sayac['kartsiz'] ?> kartsız</span>
  <div class="ms-auto d-flex gap-2">
    <a href="kiosk.php" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-camera-video me-1"></i>Kiosk</a>
    <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Seçili Kartları Yazdır</button>
  </div>
</div>

<div class="alert alert-info py-2 small no-print">
  <i class="bi bi-info-circle me-1"></i>
  Personel listesi <strong>IT Envanter'deki personel kartlarından</strong> gelir — burada ayrı bir personel
  listesi tutulmaz. Kişi eksikse <a href="../it/personel.php">Personel</a> ekranından ekleyin.
  ArUco ID aralığı <strong>0–<?= PTS_MAX_MARKER ?></strong> (<code><?= h(PTS_SOZLUK) ?></code> sözlüğü).
</div>

<form method="get" class="card border-0 shadow-sm mb-3 no-print">
  <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
    <div><label class="form-label small mb-0">Ara</label>
      <input name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="ad soyad, sicil, birim…"></div>
    <div><label class="form-label small mb-0">Durum</label>
      <select name="durum" class="form-select form-select-sm">
        <option value="kartsiz" <?= $durum==='kartsiz'?'selected':'' ?>>Kartı olmayanlar</option>
        <option value="kartli"  <?= $durum==='kartli' ?'selected':'' ?>>Kartı olanlar</option>
        <option value="hepsi"   <?= $durum==='hepsi'  ?'selected':'' ?>>Hepsi</option>
      </select></div>
    <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filtrele</button>
    <a href="kartlar.php" class="btn btn-outline-secondary btn-sm">Temizle</a>
  </div>
</form>

<div class="card border-0 shadow-sm no-print">
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light"><tr>
        <th>Personel</th><th>Sicil</th><th>Birim</th><th>ArUco ID</th><th>Kart</th><th>Verildi</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$liste): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Kayıt yok.</td></tr>
      <?php endif; ?>
      <?php foreach ($liste as $r): $pid = (int)$r['id']; $kartli = !empty($r['kart_id']); ?>
        <tr>
          <td><a href="../it/personel_detay.php?id=<?= $pid ?>" class="fw-semibold text-decoration-none"><?= h(trim($r['ad'] . ' ' . $r['soyad'])) ?></a>
              <?php if (!empty($r['unvan'])): ?><div class="small text-muted"><?= h($r['unvan']) ?></div><?php endif; ?></td>
          <td class="font-monospace small"><?= h($r['sicil_no'] ?: '—') ?></td>
          <td class="small"><?= h($r['birim'] ?: '—') ?></td>
          <td><?= $kartli ? '<span class="badge bg-primary font-monospace">' . (int)$r['marker_id'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
          <td><?php if ($kartli): ?>
                <span class="pts-mini" data-marker="<?= (int)$r['marker_id'] ?>"></span>
              <?php else: ?><span class="badge bg-warning text-dark">kart yok</span><?php endif; ?></td>
          <td class="small text-muted"><?= $kartli ? h(format_date($r['verildi'])) : '—' ?></td>
          <td class="text-end text-nowrap">
            <?php if ($yazabilir): ?>
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#kartModal"
                      data-pid="<?= $pid ?>" data-ad="<?= h(trim($r['ad'] . ' ' . $r['soyad'])) ?>"
                      data-sicil="<?= h($r['sicil_no']) ?>" data-marker="<?= $kartli ? (int)$r['marker_id'] : '' ?>">
                <i class="bi bi-<?= $kartli ? 'pencil' : 'plus-lg' ?>"></i></button>
              <?php if ($kartli): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('<?= h(trim($r['ad'] . ' ' . $r['soyad'])) ?>’nin kartı iptal edilecek.\n\nGeçmiş giriş-çıkış kayıtları korunur. Devam?')">
                <input type="hidden" name="islem" value="iptal">
                <input type="hidden" name="personel_id" value="<?= $pid ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>">
                <button class="btn btn-sm btn-outline-danger" title="Kartı iptal et"><i class="bi bi-x-lg"></i></button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Kart tanımlama + önizleme -->
<div class="modal fade" id="kartModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content"><form method="post">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-vcard me-2"></i>ArUco Kart</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="islem" value="ver">
        <input type="hidden" name="personel_id" id="kmPid">
        <input type="hidden" name="q" value="<?= h($q) ?>">
        <p class="mb-2"><strong id="kmAd"></strong> <span class="text-muted small" id="kmSicil"></span></p>
        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label small">ArUco ID (0–<?= PTS_MAX_MARKER ?>)</label>
            <div class="input-group input-group-sm">
              <input type="number" name="marker_id" id="kmMarker" class="form-control" min="0" max="<?= PTS_MAX_MARKER ?>" required>
              <button type="submit" name="islem" value="sicilden" class="btn btn-outline-secondary"
                      title="Sicil numarasındaki rakamlardan türet (00042 → 42)">Sicilden</button>
            </div>
            <div class="form-text">Kart kaybolursa yeni ID verin; eski kart otomatik iptal olur, geçmiş kayıtlar korunur.</div>
            <div id="kmUyari" class="small text-danger mt-2"></div>
          </div>
          <div class="col-md-7 text-center">
            <div id="kmOnizleme" class="border rounded p-2 bg-white d-inline-block"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
        <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
      </div>
    </form></div>
  </div>
</div>

<!-- YAZDIRMA SAYFASI: kartı olan herkesin A4 kart tabakası -->
<div class="pts-print-only">
  <?php foreach ($liste as $r): if (empty($r['kart_id'])) continue; ?>
    <div class="pts-kart">
      <div class="pts-kart-marker" data-marker="<?= (int)$r['marker_id'] ?>"></div>
      <div class="pts-kart-alt">
        <strong><?= h(trim($r['ad'] . ' ' . $r['soyad'])) ?></strong>
        <span><?= h($r['unvan'] ?: $r['birim'] ?: '') ?></span>
        <span class="pts-kart-id">Sicil <?= h($r['sicil_no'] ?: '—') ?> · ArUco <?= (int)$r['marker_id'] ?></span>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<style>
.pts-mini svg{width:34px;height:34px;display:block}
#kmOnizleme svg{width:180px;height:180px}
.pts-print-only{display:none}
@media print{
  .no-print,.sidebar,.topbar,.app-footer,nav,.modal{display:none!important}
  .card,.table-responsive{display:none!important}
  .pts-print-only{display:flex!important;flex-wrap:wrap;gap:8mm}
  .pts-kart{width:54mm;border:1px solid #999;border-radius:3mm;padding:4mm;text-align:center;page-break-inside:avoid}
  .pts-kart-marker svg{width:40mm;height:40mm}
  .pts-kart-alt{margin-top:2mm;font-size:9pt;line-height:1.25;display:flex;flex-direction:column}
  .pts-kart-id{font-size:7pt;color:#555}
}
</style>

<script src="<?= $rootPath ?>assets/vendor/cv.js"></script>
<script src="<?= $rootPath ?>assets/vendor/aruco.js"></script>
<script>
(function(){
  // Sözlük sunucu ile AYNI olmalı; PHP sabitinden basılır ki iki yer ayrışmasın.
  var SOZLUK = <?= json_encode(PTS_SOZLUK) ?>, MAXID = <?= (int)PTS_MAX_MARKER ?>;
  var dict = null;
  try { dict = new AR.Dictionary(SOZLUK); } catch(e){ console.error('ArUco sözlüğü yüklenemedi:', e); }

  function ciz(el, id){
    if (!dict) return;
    var n = Number(id);
    if (!Number.isInteger(n) || n < 0 || n > MAXID) { el.innerHTML = ''; return; }
    try { el.innerHTML = dict.generateSVG(n); } catch(e){ el.innerHTML = ''; }
  }
  document.querySelectorAll('[data-marker]').forEach(function(el){ ciz(el, el.dataset.marker); });

  var modal = document.getElementById('kartModal');
  if (modal) {
    var inp = document.getElementById('kmMarker'), onz = document.getElementById('kmOnizleme'),
        uy  = document.getElementById('kmUyari');
    modal.addEventListener('show.bs.modal', function(ev){
      var b = ev.relatedTarget; if (!b) return;
      document.getElementById('kmPid').value   = b.dataset.pid || '';
      document.getElementById('kmAd').textContent = b.dataset.ad || '';
      document.getElementById('kmSicil').textContent = b.dataset.sicil ? '· sicil ' + b.dataset.sicil : '';
      inp.value = b.dataset.marker || '';
      guncelle();
    });
    function guncelle(){
      var n = Number(inp.value);
      uy.textContent = (inp.value !== '' && (!Number.isInteger(n) || n < 0 || n > MAXID))
        ? ('ArUco ID 0 ile ' + MAXID + ' arasında olmalı.') : '';
      ciz(onz, inp.value);
    }
    inp.addEventListener('input', guncelle);
  }
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
