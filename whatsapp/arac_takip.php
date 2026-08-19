<?php
/**
 * whatsapp/arac_takip.php — Araç Giriş/Çıkış Takibi
 *
 * saha_olaylari tablosundaki araç hareketlerinden gün bazında
 * "kaç araç girdi, ne kadar kaldı" tablosunu üretir.
 *
 * Süre hesabı 3 kaynaktan (öncelik sırasıyla):
 *   1) sure_saat doğrudan yazılmışsa
 *   2) aynı kayıtta saat_bas + saat_bit varsa (tur='arac')
 *   3) arac_giris ile aynı gün/plakadaki ilk arac_cikis eşleştirilerek
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_ortak.php';

if (!can_view_reports()) { flash('error', 'Bu sayfa için yetkiniz yok.'); redirect('../index.php'); }

$pageTitle = 'Araç Takibi — Saha Takip';
saha_semasi_kur($pdo);

$bas    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bas'] ?? '') ? $_GET['bas'] : date('Y-m-d', strtotime('-14 days'));
$bit    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bit'] ?? '') ? $_GET['bit'] : date('Y-m-d');
$plakaF = saha_plaka_norm($_GET['plaka'] ?? '') ?? '';
$bekDahil = ($_GET['bekleyen'] ?? '') === '1';   // onaylanmamış mesajları da dahil et

$tarihIfade = "COALESCE(o.tarih, DATE(m.created_at))";
$w = ["$tarihIfade BETWEEN ? AND ?", "o.tur IN ('arac_giris','arac_cikis','arac')"];
// İnsan onayı ilkesi: varsayılan yalnız ONAYLANMIŞ mesajların hareketleri raporlanır
$w[] = $bekDahil ? "COALESCE(m.durum,'bekliyor') <> 'reddedildi'" : "m.durum = 'onaylandi'";
$p = [$bas, $bit];
if ($plakaF !== '') { $w[] = "o.arac_plaka LIKE ?"; $p[] = "%$plakaF%"; }

$sql = "SELECT o.*, $tarihIfade AS gun, m.gonderen, m.ham_metin
        FROM saha_olaylari o LEFT JOIN mesaj_kuyrugu m ON m.id = o.mesaj_id
        WHERE " . implode(' AND ', $w) . "
        ORDER BY gun, o.arac_plaka, COALESCE(o.saat_bas, o.saat_bit), o.id";
$st = $pdo->prepare($sql); $st->execute($p);
$ham = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Ziyaretleri oluştur: giriş–çıkış eşleştirme ───────────────────────────────
$acik = [];        // "gun|plaka" => açık ziyaret indeksi
$ziyaretler = [];
$eslesmeyen = 0;

$dk = function (?string $t): ?int {                       // "08:30:00" → 510
    if (!$t) return null;
    [$h, $i] = array_map('intval', explode(':', $t) + [0, 0]);
    return $h * 60 + $i;
};

foreach ($ham as $o) {
    $gun   = (string)$o['gun'];
    $plaka = (string)($o['arac_plaka'] ?? '');
    $anahtar = $gun . '|' . $plaka;

    if ($o['tur'] === 'arac' || ($o['saat_bas'] && $o['saat_bit']) || $o['sure_saat'] !== null) {
        // Tek kayıtta tamamlanmış ziyaret
        $sure = $o['sure_saat'] !== null ? (float)$o['sure_saat'] : null;
        if ($sure === null && $o['saat_bas'] && $o['saat_bit']) {
            $d = $dk($o['saat_bit']) - $dk($o['saat_bas']);
            if ($d < 0) $d += 24 * 60;                    // gece yarısını aşan
            $sure = round($d / 60, 2);
        }
        $ziyaretler[] = ['gun'=>$gun,'plaka'=>$plaka,'cinsi'=>$o['arac_cinsi'],'firma'=>$o['firma'],
                         'giris'=>$o['saat_bas'],'cikis'=>$o['saat_bit'],'sure'=>$sure,
                         'tam'=>true,'guven'=>$o['guven'],'aciklama'=>$o['aciklama']];
        continue;
    }

    if ($o['tur'] === 'arac_giris') {
        if (isset($acik[$anahtar])) $eslesmeyen++;         // önceki giriş çıkışsız kaldı
        $ziyaretler[] = ['gun'=>$gun,'plaka'=>$plaka,'cinsi'=>$o['arac_cinsi'],'firma'=>$o['firma'],
                         'giris'=>$o['saat_bas'],'cikis'=>null,'sure'=>null,
                         'tam'=>false,'guven'=>$o['guven'],'aciklama'=>$o['aciklama']];
        $acik[$anahtar] = array_key_last($ziyaretler);
        continue;
    }

    if ($o['tur'] === 'arac_cikis') {
        if (isset($acik[$anahtar])) {                     // açık girişi kapat
            $i = $acik[$anahtar];
            $ziyaretler[$i]['cikis'] = $o['saat_bit'] ?: $o['saat_bas'];
            $g = $dk($ziyaretler[$i]['giris']); $c = $dk($ziyaretler[$i]['cikis']);
            if ($g !== null && $c !== null) {
                $d = $c - $g; if ($d < 0) $d += 24 * 60;
                $ziyaretler[$i]['sure'] = round($d / 60, 2);
                $ziyaretler[$i]['tam']  = true;
            }
            if (!$ziyaretler[$i]['cinsi'] && $o['arac_cinsi']) $ziyaretler[$i]['cinsi'] = $o['arac_cinsi'];
            unset($acik[$anahtar]);
        } else {                                          // girişi görülmemiş çıkış
            $eslesmeyen++;
            $ziyaretler[] = ['gun'=>$gun,'plaka'=>$plaka,'cinsi'=>$o['arac_cinsi'],'firma'=>$o['firma'],
                             'giris'=>null,'cikis'=>$o['saat_bit'] ?: $o['saat_bas'],'sure'=>null,
                             'tam'=>false,'guven'=>$o['guven'],'aciklama'=>$o['aciklama']];
        }
    }
}
$eslesmeyen += count($acik);   // hâlâ açık kalan girişler

// ── Özetler ───────────────────────────────────────────────────────────────────
$toplamZiyaret = count($ziyaretler);
$farkliArac    = count(array_unique(array_filter(array_column($ziyaretler, 'plaka'))));
$toplamSaat    = array_sum(array_map(fn($z) => (float)($z['sure'] ?? 0), $ziyaretler));
$sureliAdet    = count(array_filter($ziyaretler, fn($z) => $z['sure'] !== null));
$ortSaat       = $sureliAdet ? $toplamSaat / $sureliAdet : 0;

$gunluk = [];   // gün => ['ziyaret'=>n,'saat'=>x]
$plakaOzet = []; // plaka => [...]
foreach ($ziyaretler as $z) {
    $g = $z['gun'];
    $gunluk[$g] ??= ['ziyaret'=>0,'saat'=>0.0];
    $gunluk[$g]['ziyaret']++; $gunluk[$g]['saat'] += (float)($z['sure'] ?? 0);

    $pl = $z['plaka'] ?: '(plakasız)';
    $plakaOzet[$pl] ??= ['ziyaret'=>0,'saat'=>0.0,'cinsi'=>$z['cinsi'],'firma'=>$z['firma'],'son'=>''];
    $plakaOzet[$pl]['ziyaret']++; $plakaOzet[$pl]['saat'] += (float)($z['sure'] ?? 0);
    if (!$plakaOzet[$pl]['cinsi'] && $z['cinsi']) $plakaOzet[$pl]['cinsi'] = $z['cinsi'];
    if ($g > $plakaOzet[$pl]['son']) $plakaOzet[$pl]['son'] = $g;
}
ksort($gunluk);
uasort($plakaOzet, fn($a, $b) => $b['saat'] <=> $a['saat'] ?: $b['ziyaret'] <=> $a['ziyaret']);

$sayi = fn($v, $d = 1) => number_format((float)$v, $d, ',', '.');
$ss   = fn(?string $t) => $t ? substr($t, 0, 5) : '—';

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-truck text-primary me-2"></i>Araç Takibi</h4>
</div>

<form class="card mb-3"><div class="card-body py-2">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label small mb-0">Başlangıç</label>
      <input type="date" name="bas" value="<?= h($bas) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small mb-0">Bitiş</label>
      <input type="date" name="bit" value="<?= h($bit) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-8 col-md-3">
      <label class="form-label small mb-0">Plaka</label>
      <input name="plaka" value="<?= h($plakaF) ?>" class="form-control form-control-sm" placeholder="34ABC123">
    </div>
    <div class="col-8 col-md-2">
      <div class="form-check form-switch small mt-3">
        <input class="form-check-input" type="checkbox" name="bekleyen" value="1" id="bekSw" <?= $bekDahil?'checked':'' ?>>
        <label class="form-check-label" for="bekSw" title="Varsayılan: yalnız onaylanmış mesajlar sayılır">Bekleyenler dahil</label>
      </div>
    </div>
    <div class="col-4 col-md-1 d-grid">
      <button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
    </div>
  </div>
</div></form>

<?php if (!$toplamZiyaret): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
    Bu aralıkta araç hareketi yok. Mesajlar <a href="mesajlar.php" class="alert-link">Gelen Mesajlar</a>
    ekranında çözümlenip <strong>onaylandıkça</strong> buraya düşer.
    <?php if (!$bekDahil): ?>(Henüz onaylanmamışları görmek için "Bekleyenler dahil" seçeneğini açın.)<?php endif; ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-4 text-success"><?= $toplamZiyaret ?></div>
    <div class="text-muted small">Toplam araç girişi</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-4"><?= $farkliArac ?></div>
    <div class="text-muted small">Farklı araç</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-4 text-info"><?= $sayi($toplamSaat) ?></div>
    <div class="text-muted small">Toplam sahada kalma (saat)</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-4"><?= $sayi($ortSaat) ?></div>
    <div class="text-muted small">Ortalama kalış (saat)</div></div></div></div>
</div>

<?php if ($eslesmeyen): ?>
<div class="alert alert-warning py-2 small">
  <i class="bi bi-exclamation-triangle me-1"></i>
  <strong><?= $eslesmeyen ?></strong> hareket eşleşmedi (girişi olup çıkışı bildirilmemiş ya da tersi).
  Bu kayıtların süresi hesaplanamadı — aşağıda <span class="badge bg-warning text-dark">açık</span> olarak işaretli.
</div>
<?php endif; ?>

<?php if ($gunluk): ?>
<div class="card mb-4"><div class="card-header fw-semibold"><i class="bi bi-graph-up me-1"></i>Günlük Araç Girişi</div>
  <div class="card-body"><canvas id="chGun" height="90"></canvas></div>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-list-ol me-1"></i>Araç Bazlı Özet</div>
      <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light"><tr><th>Plaka</th><th>Cinsi</th><th class="text-end">Giriş</th><th class="text-end">Saat</th><th class="text-end">Son</th></tr></thead>
          <tbody>
          <?php foreach ($plakaOzet as $pl => $v): ?>
            <tr>
              <td class="fw-semibold font-monospace"><?= h($pl) ?></td>
              <td class="small"><?= h((string)$v['cinsi']) ?></td>
              <td class="text-end"><?= (int)$v['ziyaret'] ?></td>
              <td class="text-end fw-semibold"><?= $sayi($v['saat']) ?></td>
              <td class="text-end small text-nowrap"><?= $v['son'] ? date('d.m', strtotime($v['son'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$plakaOzet): ?><tr><td colspan="5" class="text-center text-muted py-3">Kayıt yok.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div></div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i>Giriş / Çıkış Dökümü (<?= $toplamZiyaret ?>)</div>
      <div class="card-body p-0"><div class="table-responsive" style="max-height:520px">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light"><tr>
            <th>Tarih</th><th>Plaka</th><th>Cinsi</th><th>Firma</th>
            <th class="text-center">Giriş</th><th class="text-center">Çıkış</th><th class="text-end">Süre</th>
          </tr></thead>
          <tbody>
          <?php foreach (array_reverse($ziyaretler) as $z): ?>
            <tr title="<?= h(mb_substr((string)$z['aciklama'], 0, 200)) ?>">
              <td class="small text-nowrap"><?= $z['gun'] ? date('d.m.Y', strtotime($z['gun'])) : '—' ?></td>
              <td class="fw-semibold font-monospace"><?= h((string)$z['plaka']) ?: '—' ?></td>
              <td class="small"><?= h((string)$z['cinsi']) ?></td>
              <td class="small"><?= h((string)$z['firma']) ?></td>
              <td class="text-center small"><?= h($ss($z['giris'])) ?></td>
              <td class="text-center small"><?= h($ss($z['cikis'])) ?></td>
              <td class="text-end">
                <?php if ($z['sure'] !== null): ?>
                  <span class="fw-semibold"><?= $sayi($z['sure']) ?> s</span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark">açık</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$ziyaretler): ?><tr><td colspan="7" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div></div>
    </div>
  </div>
</div>

<div class="alert alert-secondary small mt-3 border-0">
  <i class="bi bi-robot me-1"></i>
  Veriler WhatsApp mesajlarından <strong>AI ile</strong> çıkarılmıştır; resmî giriş-çıkış kaydı yerine geçmez.
  Süre, çıkış bildirimi yapılmayan araçlarda hesaplanamaz.
</div>

<?php if ($gunluk): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function(){
  var g = <?= json_encode(array_map(fn($k, $v) => ['gun'=>$k] + $v, array_keys($gunluk), $gunluk), JSON_UNESCAPED_UNICODE) ?>;
  var koyu = document.documentElement.getAttribute('data-dark') === '1';
  var yazi = koyu ? '#DCF0EC' : '#0D2E28', cizgi = koyu ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
  new Chart(document.getElementById('chGun'), {
    type: 'bar',
    data: {
      labels: g.map(function(r){ return r.gun ? r.gun.substring(8)+'.'+r.gun.substring(5,7) : ''; }),
      datasets: [
        { label:'Araç girişi', data:g.map(function(r){return r.ziyaret;}), backgroundColor:'#00A896' },
        { label:'Toplam saat', data:g.map(function(r){return r.saat;}), type:'line',
          borderColor:'#C9A84C', backgroundColor:'#C9A84C', yAxisID:'y1', tension:.3 }
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
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
