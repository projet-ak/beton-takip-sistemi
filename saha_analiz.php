<?php
/**
 * saha_analiz.php — Saha Hareket Analizi
 *
 * WhatsApp grubundan gelen mesajlardan AI ile çıkarılan saha olaylarını
 * (personel giriş/çıkış, yetkilendirme, araç çalışma saatleri) raporlar.
 * Kaynak tablo: saha_olaylari (includes/mesaj.php → saha_olay_kaydet)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mesaj.php';

if (!can_view_reports()) { flash('error', 'Bu sayfa için yetkiniz yok.'); redirect('index.php'); }

$pageTitle = 'Saha Analizi — Şantiye Takip Sistemi';
saha_semasi_kur($pdo);

// ── Filtreler ─────────────────────────────────────────────────────────────────
$bas = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bas'] ?? '') ? $_GET['bas'] : date('Y-m-d', strtotime('-30 days'));
$bit = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bit'] ?? '') ? $_GET['bit'] : date('Y-m-d');
$TURLER = ['personel_giris'=>'Personel Giriş','personel_cikis'=>'Personel Çıkış','yetki'=>'Yetkilendirme','arac'=>'Araç','is'=>'İş/İmalat','diger'=>'Diğer'];
$turF   = isset($_GET['tur']) && isset($TURLER[$_GET['tur']]) ? $_GET['tur'] : '';
$kisiF  = trim((string)($_GET['kisi'] ?? ''));

// tarih NULL olan olaylarda mesajın geliş tarihini kullan
$tarihIfade = "COALESCE(o.tarih, DATE(m.created_at))";
$w = ["$tarihIfade BETWEEN ? AND ?"];
$p = [$bas, $bit];
if ($turF)  { $w[] = "o.tur = ?";  $p[] = $turF; }
if ($kisiF) { $w[] = "(o.kisi LIKE ? OR o.firma LIKE ? OR o.yetkili LIKE ? OR o.arac_plaka LIKE ?)";
              array_push($p, "%$kisiF%", "%$kisiF%", "%$kisiF%", "%$kisiF%"); }
$W = 'WHERE ' . implode(' AND ', $w);

$FROM = "FROM saha_olaylari o LEFT JOIN mesaj_kuyrugu m ON m.id = o.mesaj_id";

// ── KPI ───────────────────────────────────────────────────────────────────────
$kpiSql = "SELECT
    SUM(o.tur='personel_giris') giris,
    SUM(o.tur='personel_cikis') cikis,
    SUM(o.tur='yetki')          yetki,
    COUNT(DISTINCT CASE WHEN o.tur='personel_giris' THEN o.kisi END) kisi_sayisi,
    COUNT(DISTINCT CASE WHEN o.tur='arac' THEN o.arac_plaka END)     arac_sayisi,
    COALESCE(SUM(CASE WHEN o.tur='arac' THEN o.sure_saat END),0)     arac_saat,
    COUNT(*) toplam
  $FROM $W";
$st = $pdo->prepare($kpiSql); $st->execute($p); $kpi = $st->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Günlük hareket (grafik) ───────────────────────────────────────────────────
$gSql = "SELECT $tarihIfade gun,
           SUM(o.tur='personel_giris') giris,
           SUM(o.tur='personel_cikis') cikis,
           COALESCE(SUM(CASE WHEN o.tur='arac' THEN o.sure_saat END),0) arac_saat
         $FROM $W GROUP BY gun ORDER BY gun";
$st = $pdo->prepare($gSql); $st->execute($p); $gunluk = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Kişi bazlı ────────────────────────────────────────────────────────────────
$kSql = "SELECT o.kisi, o.firma,
            SUM(o.tur='personel_giris') giris,
            SUM(o.tur='personel_cikis') cikis,
            MAX($tarihIfade) son
         $FROM $W AND o.kisi IS NOT NULL AND o.kisi <> ''
         GROUP BY o.kisi, o.firma ORDER BY giris DESC, o.kisi LIMIT 50";
$st = $pdo->prepare($kSql); $st->execute($p); $kisiler = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Araç bazlı ────────────────────────────────────────────────────────────────
$aSql = "SELECT o.arac_plaka, COUNT(*) kayit,
            COALESCE(SUM(o.sure_saat),0) saat, MAX($tarihIfade) son
         $FROM $W AND o.tur='arac' AND o.arac_plaka IS NOT NULL AND o.arac_plaka <> ''
         GROUP BY o.arac_plaka ORDER BY saat DESC LIMIT 50";
$st = $pdo->prepare($aSql); $st->execute($p); $araclar = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Yetkilendirmeler ──────────────────────────────────────────────────────────
$ySql = "SELECT o.yetkili, o.kisi, o.aciklama, $tarihIfade tarih
         $FROM $W AND o.tur='yetki' ORDER BY tarih DESC LIMIT 50";
$st = $pdo->prepare($ySql); $st->execute($p); $yetkiler = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Zaman çizelgesi ───────────────────────────────────────────────────────────
$zSql = "SELECT o.*, $tarihIfade etkin_tarih, m.gonderen, m.ham_metin
         $FROM $W ORDER BY etkin_tarih DESC, o.saat_bas DESC, o.id DESC LIMIT 200";
$st = $pdo->prepare($zSql); $st->execute($p); $olaylar = $st->fetchAll(PDO::FETCH_ASSOC);

$sayi = fn($v, $d = 1) => number_format((float)$v, $d, ',', '.');
$qs   = fn(array $ek = []) => http_build_query(array_merge(['bas'=>$bas,'bit'=>$bit,'tur'=>$turF,'kisi'=>$kisiF], $ek));

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-people text-primary me-2"></i>Saha Analizi</h4>
    <a href="mesajlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chat-dots me-1"></i>Gelen Mesajlar</a>
</div>

<form class="card mb-3"><div class="card-body py-2">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
      <label class="form-label small mb-0">Başlangıç</label>
      <input type="date" name="bas" value="<?= h($bas) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-0">Bitiş</label>
      <input type="date" name="bit" value="<?= h($bit) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small mb-0">Tür</label>
      <select name="tur" class="form-select form-select-sm">
        <option value="">Tümü</option>
        <?php foreach ($TURLER as $k=>$v): ?>
          <option value="<?= h($k) ?>" <?= $turF===$k?'selected':'' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small mb-0">Kişi / Firma / Plaka</label>
      <input name="kisi" value="<?= h($kisiF) ?>" class="form-control form-control-sm" placeholder="ara…">
    </div>
    <div class="col-12 col-md-2 d-grid">
      <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrele</button>
    </div>
  </div>
</div></form>

<?php if ((int)($kpi['toplam'] ?? 0) === 0): ?>
  <div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>
    Bu aralıkta saha olayı yok. Mesajlar <a href="mesajlar.php" class="alert-link">Gelen Mesajlar</a>
    ekranında AI ile çözümlendikçe buraya düşer.
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-5 text-success"><?= (int)($kpi['giris'] ?? 0) ?></div>
    <div class="text-muted small">Sahaya giriş</div></div></div></div>
  <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-5 text-secondary"><?= (int)($kpi['cikis'] ?? 0) ?></div>
    <div class="text-muted small">Sahadan çıkış</div></div></div></div>
  <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-5"><?= (int)($kpi['kisi_sayisi'] ?? 0) ?></div>
    <div class="text-muted small">Farklı kişi</div></div></div></div>
  <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-5 text-warning"><?= (int)($kpi['yetki'] ?? 0) ?></div>
    <div class="text-muted small">Yetkilendirme</div></div></div></div>
  <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-5"><?= (int)($kpi['arac_sayisi'] ?? 0) ?></div>
    <div class="text-muted small">Farklı araç</div></div></div></div>
  <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-5 text-info"><?= $sayi($kpi['arac_saat'] ?? 0) ?></div>
    <div class="text-muted small">Toplam araç saati</div></div></div></div>
</div>

<?php if ($gunluk): ?>
<div class="card mb-4"><div class="card-header fw-semibold"><i class="bi bi-graph-up me-1"></i>Günlük Hareket</div>
  <div class="card-body"><canvas id="chGun" height="90"></canvas></div>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-person-badge me-1"></i>Kişi Bazlı</div>
      <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light"><tr><th>Kişi</th><th>Firma</th><th class="text-end">Giriş</th><th class="text-end">Çıkış</th><th class="text-end">Son</th></tr></thead>
          <tbody>
          <?php foreach ($kisiler as $k): ?>
            <tr><td class="fw-semibold"><?= h((string)$k['kisi']) ?></td>
                <td class="small text-muted"><?= h((string)$k['firma']) ?></td>
                <td class="text-end"><?= (int)$k['giris'] ?></td>
                <td class="text-end"><?= (int)$k['cikis'] ?></td>
                <td class="text-end small text-nowrap"><?= $k['son'] ? date('d.m.Y', strtotime($k['son'])) : '—' ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$kisiler): ?><tr><td colspan="5" class="text-center text-muted py-3">Kayıt yok.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-truck me-1"></i>Araç Bazlı</div>
      <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light"><tr><th>Plaka</th><th class="text-end">Kayıt</th><th class="text-end">Saat</th><th class="text-end">Son</th></tr></thead>
          <tbody>
          <?php foreach ($araclar as $a): ?>
            <tr><td class="fw-semibold font-monospace"><?= h((string)$a['arac_plaka']) ?></td>
                <td class="text-end"><?= (int)$a['kayit'] ?></td>
                <td class="text-end fw-semibold"><?= $sayi($a['saat']) ?></td>
                <td class="text-end small text-nowrap"><?= $a['son'] ? date('d.m.Y', strtotime($a['son'])) : '—' ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$araclar): ?><tr><td colspan="4" class="text-center text-muted py-3">Kayıt yok.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div></div>
    </div>
  </div>
</div>

<?php if ($yetkiler): ?>
<div class="card mb-4"><div class="card-header fw-semibold"><i class="bi bi-shield-check me-1"></i>Yetkilendirmeler</div>
  <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead class="table-light"><tr><th>Tarih</th><th>Yetkiyi Veren</th><th>Yetki Alan</th><th>Açıklama</th></tr></thead>
      <tbody>
      <?php foreach ($yetkiler as $y): ?>
        <tr><td class="small text-nowrap"><?= $y['tarih'] ? date('d.m.Y', strtotime($y['tarih'])) : '—' ?></td>
            <td class="fw-semibold"><?= h((string)$y['yetkili']) ?: '<span class="text-muted">—</span>' ?></td>
            <td><?= h((string)$y['kisi']) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="small text-muted"><?= h((string)$y['aciklama']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header fw-semibold"><i class="bi bi-list-ul me-1"></i>Zaman Çizelgesi (<?= count($olaylar) ?>)</div>
  <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light"><tr>
        <th>Tarih</th><th>Saat</th><th>Tür</th><th>Kişi / Plaka</th><th>Firma</th>
        <th>Yer</th><th>Açıklama</th><th class="text-end">Güven</th>
      </tr></thead>
      <tbody>
      <?php
      $rozet = ['personel_giris'=>'bg-success','personel_cikis'=>'bg-secondary','yetki'=>'bg-warning text-dark',
                'arac'=>'bg-info text-dark','is'=>'bg-primary','diger'=>'bg-light text-dark border'];
      foreach ($olaylar as $o):
        $sa = $o['saat_bas'] ? substr((string)$o['saat_bas'],0,5) : '';
        if ($o['saat_bit']) $sa .= '–' . substr((string)$o['saat_bit'],0,5);
        $g  = $o['guven'] !== null ? (float)$o['guven'] : null;
      ?>
        <tr title="<?= h(mb_substr((string)$o['ham_metin'],0,300)) ?>">
          <td class="small text-nowrap"><?= $o['etkin_tarih'] ? date('d.m.Y', strtotime($o['etkin_tarih'])) : '—' ?></td>
          <td class="small text-nowrap"><?= h($sa) ?: '—' ?>
              <?php if ($o['sure_saat']): ?><span class="text-muted">(<?= $sayi($o['sure_saat']) ?>s)</span><?php endif; ?></td>
          <td><span class="badge <?= $rozet[$o['tur']] ?? 'bg-light text-dark' ?>"><?= h($TURLER[$o['tur']] ?? $o['tur']) ?></span></td>
          <td class="fw-semibold"><?= h((string)($o['kisi'] ?: $o['arac_plaka'])) ?: '—' ?></td>
          <td class="small"><?= h((string)$o['firma']) ?: '—' ?></td>
          <td class="small"><?= h((string)$o['lokasyon']) ?: '—' ?></td>
          <td class="small text-muted"><?= h(mb_substr((string)$o['aciklama'],0,110)) ?></td>
          <td class="text-end small">
            <?php if ($g !== null): ?>
              <span class="<?= $g < .6 ? 'text-danger fw-semibold' : 'text-success' ?>"><?= number_format($g*100,0) ?>%</span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$olaylar): ?><tr><td colspan="8" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div></div>
</div>

<div class="alert alert-secondary small mt-3 border-0">
  <i class="bi bi-robot me-1"></i>
  Bu sayfadaki veriler WhatsApp mesajlarından <strong>AI ile</strong> çıkarılmıştır; resmî puantaj/İSG kaydı yerine geçmez.
  Düşük güven yüzdeli satırları ham mesajla karşılaştırın (satır üzerine gelince mesaj görünür).
</div>

<?php if ($gunluk): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function(){
  var g = <?= json_encode($gunluk, JSON_UNESCAPED_UNICODE) ?>;
  var koyu = document.documentElement.getAttribute('data-dark') === '1';
  var yazi = koyu ? '#DCF0EC' : '#0D2E28', cizgi = koyu ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
  new Chart(document.getElementById('chGun'), {
    type: 'bar',
    data: {
      labels: g.map(function(r){ return r.gun ? r.gun.substring(8)+'.'+r.gun.substring(5,7) : ''; }),
      datasets: [
        { label:'Giriş', data:g.map(function(r){return +r.giris;}), backgroundColor:'#00A896' },
        { label:'Çıkış', data:g.map(function(r){return +r.cikis;}), backgroundColor:'#8AA9A3' },
        { label:'Araç saati', data:g.map(function(r){return +r.arac_saat;}), backgroundColor:'#C9A84C', type:'line',
          borderColor:'#C9A84C', yAxisID:'y1', tension:.3 }
      ]
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ labels:{ color:yazi } } },
      scales:{
        x:{ ticks:{ color:yazi }, grid:{ color:cizgi } },
        y:{ beginAtZero:true, ticks:{ color:yazi, precision:0 }, grid:{ color:cizgi } },
        y1:{ position:'right', beginAtZero:true, ticks:{ color:yazi }, grid:{ display:false } }
      }
    }
  });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
