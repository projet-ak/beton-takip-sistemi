<?php
/**
 * pts/kurulum_pts.php — PTS şema kurulumu + sağlık kontrolü
 *
 * ⭐ PTS **kendi veritabanında** çalışır (`PTS_DB_NAME`, varsayılan `takbulut_pts`),
 * kendi personel listesini (`pts_personel`) ve kendi uploads klasörünü (`uploads/pts/`)
 * kullanır — başka modüle bağımlı değildir (bkz. includes/db_pts.php).
 *
 * Kurulum sayfaları HER ZAMAN rol bazlıdır (yetki matrisi burada atlanmaz).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_pts.php';
require_once __DIR__ . '/_ortak.php';

$pageTitle = 'Kurulum — Personel Takip';
$mesaj = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'kur') {
    try {
        pts_semasi_kur($pdoPts);
        // Geçiş fotoğrafları için klasör — yazılamazsa geçiş yine kaydedilir, uyarırız.
        $up = __DIR__ . '/../uploads/pts/gecis';
        if (!is_dir($up)) @mkdir($up, 0775, true);
        $mesaj[] = ['success', 'Şema kuruldu / güncellendi.'];
        $mesaj[] = is_dir($up) && is_writable($up)
            ? ['success', 'Geçiş fotoğrafı klasörü hazır: uploads/pts/gecis/']
            : ['warning', 'uploads/pts/gecis/ oluşturulamadı ya da yazılabilir değil — geçişler kaydedilir ama fotoğraf tutulmaz.'];
        audit_log($pdoPts, 'pts_kurulum', 0, 'INSERT', null, ['sema' => 'kuruldu'], current_user_id());
    } catch (Throwable $e) { $mesaj[] = ['danger', 'Kurulum hatası: ' . $e->getMessage()]; }
}

// ── Durum ───────────────────────────────────────────────────────────────────
$ayriDb = defined('PTS_DB_NAME') && PTS_DB_NAME !== '';
$dbAdi  = $ayriDb ? PTS_DB_NAME : (defined('DB_NAME') ? DB_NAME : '?');

$tablo = [];
foreach (['pts_kartlar' => 'ArUco kartlar', 'pts_noktalar' => 'Geçiş noktaları',
          'pts_hareketler' => 'Giriş/çıkış hareketleri', 'pts_personel' => 'Personel (bu modülün kendi listesi)'] as $t => $ad) {
    try { $n = (int)$pdoPts->query("SELECT COUNT(*) FROM $t")->fetchColumn(); $var = true; }
    catch (Throwable $e) { $n = 0; $var = false; }
    $tablo[$t] = ['ad' => $ad, 'var' => $var, 'adet' => $n];
}

$celiski = [];
$upDir   = __DIR__ . '/../uploads/pts/gecis';
try { if ($tablo['pts_kartlar']['var']) $celiski = pts_kart_celiskileri($pdoPts); } catch (Throwable $e) {}

// ArUco kütüphanesi yerinde mi? (kiosk ve kart üretimi buna bağlı)
$vendor = ['assets/vendor/cv.js', 'assets/vendor/aruco.js'];
$vendorEksik = array_values(array_filter($vendor, fn($f) => !is_file(__DIR__ . '/../' . $f)));

require __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-gear text-primary me-2"></i>Personel Takip — Kurulum</h4>

<?php foreach ($mesaj as [$tip, $m]): ?>
  <div class="alert alert-<?= $tip ?> py-2"><?= h($m) ?></div>
<?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong>Veritabanı</strong></div>
      <div class="card-body">
        <p class="mb-2">
          <span class="badge bg-<?= $ayriDb ? 'success' : 'warning text-dark' ?>">
            <?= $ayriDb ? 'Ayrı DB' : 'Ana DB' ?></span>
          <code><?= h($dbAdi) ?></code>
        </p>
        <div class="alert alert-info py-2 small mb-3">
          <i class="bi bi-info-circle me-1"></i>
          <strong>Her modül kendi veritabanında, kendi görselleriyle, diğerlerinden bağımsız çalışır.</strong>
          PTS de kendi personel listesini (<code>pts_personel</code>) tutar; IT Envanter kurulu olmasa da
          eksiksiz çalışır. config.php'ye: <code>define('PTS_DB_NAME', 'takbulut_pts');</code>
          <?php if (!$ayriDb): ?>
          <br>⚠ <code>PTS_DB_NAME</code> tanımsız — modül ana veritabanına düştü. Tablolar önekli olduğu için
          çakışma olmaz ama beklediğiniz veriyi göremezsiniz.
          <?php endif; ?>
        </div>
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Tablo</th><th>Durum</th><th class="text-end">Kayıt</th></tr></thead>
          <tbody>
          <?php foreach ($tablo as $t => $x): ?>
            <tr>
              <td><code><?= h($t) ?></code><div class="small text-muted"><?= h($x['ad']) ?></div></td>
              <td><?= $x['var'] ? '<span class="badge bg-success">var</span>' : '<span class="badge bg-danger">yok</span>' ?></td>
              <td class="text-end"><?= $x['var'] ? number_format($x['adet'], 0, ',', '.') : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer bg-white">
        <form method="post"><input type="hidden" name="islem" value="kur">
          <button class="btn btn-primary btn-sm"><i class="bi bi-hammer me-1"></i>Şemayı Kur / Güncelle</button>
          <span class="small text-muted ms-2">İdempotenttir; mevcut veriye dokunmaz.</span>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong>Sağlık Kontrolü</strong></div>
      <div class="card-body">
        <ul class="list-unstyled mb-0 small">
          <li class="mb-2">
            <?= $vendorEksik
              ? '<i class="bi bi-x-circle text-danger me-1"></i><strong>ArUco kütüphanesi EKSİK:</strong> '
                . h(implode(', ', $vendorEksik)) . ' — kiosk okuma ve kart üretimi çalışmaz.'
              : '<i class="bi bi-check-circle text-success me-1"></i>ArUco kütüphanesi yerinde (<code>assets/vendor/</code>).' ?>
          </li>
          <li class="mb-2">
            <?= is_dir($upDir) && is_writable($upDir)
              ? '<i class="bi bi-check-circle text-success me-1"></i>Geçiş fotoğrafı klasörü yazılabilir.'
              : '<i class="bi bi-exclamation-triangle text-warning me-1"></i><code>uploads/pts/gecis/</code> yok ya da yazılamıyor — geçişler kaydedilir, fotoğraf tutulmaz.' ?>
          </li>
          <li class="mb-2">
            <?php if ($celiski): ?>
              <i class="bi bi-x-circle text-danger me-1"></i>
              <strong>Kart çelişkisi:</strong> aynı anda birden çok aktif kart var —
              <?php foreach ($celiski as $c): ?>
                <span class="badge bg-danger"><?= h($c['tur']) ?> <?= h($c['deger']) ?> → <?= (int)$c['adet'] ?></span>
              <?php endforeach; ?>
              <div class="text-muted mt-1">MySQL'de kısmi UNIQUE index olmadığı için bu kural uygulamada tutulur;
                doğrudan SQL ile bozulmuşsa burada görünür. Kartlar ekranından fazlalıkları iptal edin.</div>
            <?php else: ?>
              <i class="bi bi-check-circle text-success me-1"></i>Kart çelişkisi yok
              (kişi başına tek aktif kart, marker başına tek aktif kişi).
            <?php endif; ?>
          </li>
          <li class="mb-2">
            <i class="bi bi-<?= $tablo['pts_noktalar']['adet'] ? 'check-circle text-success' : 'exclamation-triangle text-warning' ?> me-1"></i>
            <?= $tablo['pts_noktalar']['adet']
              ? number_format($tablo['pts_noktalar']['adet']) . ' geçiş noktası tanımlı.'
              : 'Geçiş noktası yok — kiosk cihazı kendini tanıtamaz, kart okutulamaz.' ?>
            <a href="noktalar.php" class="ms-1">yönet →</a>
          </li>
          <li>
            <i class="bi bi-info-circle text-primary me-1"></i>
            ArUco sözlüğü <code><?= h(PTS_SOZLUK) ?></code>, geçerli ID aralığı <strong>0–<?= PTS_MAX_MARKER ?></strong>.
            Sicil numaraları bunu aşıyorsa 1000 markerlı bir sözlüğe geçilmeli
            (<code>PTS_SOZLUK</code> + <code>PTS_MAX_MARKER</code> + <code>assets/vendor/aruco.js</code> birlikte).
          </li>
        </ul>
      </div>
      <div class="card-footer bg-white small text-muted">
        Kiosk yalnız <strong>HTTPS</strong> ya da <code>localhost</code> üzerinden kamera açabilir —
        tableti ağdan açacaksanız sertifika şarttır.
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
