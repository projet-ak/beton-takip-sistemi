<?php
/**
 * veritabani_tasi.php — Prekast'ı CRM veritabanından KENDİ veritabanına taşır (CLAUDE.md §0).
 * Çekirdek: _tasi.php (pkt_*). Yalnız admin. Adımlar: hedef DB → kopyala + doğrula → config.php →
 * eski kopyaları (yedek alınarak) kaldır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/../includes/db.php';          // $pdo — audit_log
require_once __DIR__ . '/../includes/yedekleme.php';
require_once __DIR__ . '/_tasi.php';
@set_time_limit(300);

$pageTitle = 'Prekast — Veritabanı Taşıma';
$kaynakDb  = pkt_kaynak_db();
$aktif     = pkt_aktif_ayri();
$hedefDb   = $aktif ? PREKAST_DB_NAME : trim((string)($_POST['hedef'] ?? $_GET['hedef'] ?? 'takbulut_prekast'));
$adHata    = null;
if (!pkt_ad_gecerli($hedefDb)) {
    $adHata = 'Veritabanı adı yalnız harf, rakam ve alt çizgi (_) içerebilir.';
} elseif (in_array($hedefDb, pkt_dolu_db_adlari(), true)) {
    $adHata = "“{$hedefDb}” başka bir modülün veritabanı. Prekast için yeni bir ad verin.";
}

$kaynak = null; $kaynakHata = null;
try { $kaynak = pkt_kaynak_pdo(); } catch (Throwable $e) { $kaynakHata = $e->getMessage(); }

$geri = 'veritabani_tasi.php' . ($aktif ? '' : '?hedef=' . urlencode($hedefDb));

// ── İŞLEMLER (PRG) ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $kaynak && !$adHata) {
    $islem = (string)($_POST['islem'] ?? '');
    $kul   = (string)($_SESSION['user']['username'] ?? '');
    $s     = ['islem' => $islem, 'ok' => false, 'mesaj' => ''];
    try {
        if ($islem === 'olustur') {
            $h = pkt_db_olustur($kaynak, $hedefDb);
            $s['ok']    = $h === null;
            $s['mesaj'] = $h === null ? "“{$hedefDb}” veritabanı oluşturuldu." : "Veritabanı oluşturulamadı: $h";
        } elseif ($islem === 'kopyala') {
            $b = pkt_hedef_baglan($hedefDb);
            if (!$b['pdo']) {
                $s['mesaj'] = 'Hedef veritabanına bağlanılamadı: ' . $b['mesaj'];
            } elseif ($neden = pkt_kopya_izni($b['pdo'], $aktif, !empty($_POST['ustune_yaz']))) {
                $s['mesaj'] = $neden;
            } else {
                $r = pkt_kopyala($kaynak, $b['pdo'], $hedefDb, $kul);
                $s['ok'] = $r['tamam']; $s['tablolar'] = $r['tablolar'];
                $s['mesaj'] = $r['tamam']
                    ? 'Kopyalandı ve doğrulandı: her tablo satır satır kaynakla birebir aynı.'
                    : 'Kopyalama tamamlanamadı: ' . $r['hata'];
                if ($r['tamam']) {
                    audit_log($pdo, 'prekast_tasima', 0, 'INSERT', ['islem' => 'kopyala', 'kaynak' => $kaynakDb],
                        ['hedef' => $hedefDb, 'tablolar' => $r['tablolar']], current_user_id());
                }
            }
        } elseif ($islem === 'eski_sil') {
            $b  = pkt_hedef_baglan($hedefDb);
            $kT = pkt_tablolar($kaynak);
            $is = $b['pdo'] ? pkt_isaret_son($b['pdo']) : null;
            if (!$aktif) {
                $s['mesaj'] = 'Önce config.php\'ye PREKAST_DB_NAME eklenmeli (3. adım).';
            } elseif (trim((string)($_POST['onay'] ?? '')) !== 'SIL') {
                $s['mesaj'] = 'Onay için kutuya SIL yazın.';
            } elseif (!$b['pdo'] || !$is) {
                $s['mesaj'] = 'Yeni veritabanında kopyalama kaydı bulunamadı; eski tablolar silinmedi.';
            } elseif (!$kT) {
                $s['ok'] = true; $s['mesaj'] = 'CRM veritabanında prekast tablosu kalmamış.';
            } elseif (!pkt_esit($is['ozet'], pkt_ozetler($kaynak, $kT)) && empty($_POST['zorla'])) {
                $s['mesaj'] = 'Eski tablolar kopyadan sonra değişmiş — içlerinde yeni veritabanında olmayan kayıt olabilir. Silinmedi.';
            } elseif (array_diff($kT, pkt_tablolar($b['pdo']))) {
                $s['mesaj'] = 'Yeni veritabanında eksik tablo var; eski tablolar silinmedi.';
            } else {
                $dir = __DIR__ . '/../backups';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $yedek = yedek_olustur($kaynak, $dir, 'manual', 'crm', $kaynakDb);
                if (!$yedek) {
                    $s['mesaj'] = 'CRM veritabanının yedeği alınamadı; güvenlik için hiçbir tablo silinmedi.';
                } else {
                    $sil = pkt_kaynak_sil($kaynak, $kT);
                    $s['ok'] = true;
                    $s['mesaj'] = count($sil) . ' tablo CRM veritabanından kaldırıldı (' . implode(', ', $sil)
                        . '). Silmeden önceki tam yedek: ' . $yedek;
                    audit_log($pdo, 'prekast_tasima', 0, 'DELETE', ['kaynak' => $kaynakDb, 'tablolar' => $sil],
                        ['yedek' => $yedek], current_user_id());
                }
            }
        }
    } catch (Throwable $e) {
        $s['ok'] = false; $s['mesaj'] = 'Hata: ' . $e->getMessage();
    }
    $_SESSION['pkt_sonuc'] = $s;
    redirect($geri);
}
$sonuc = $_SESSION['pkt_sonuc'] ?? null;
unset($_SESSION['pkt_sonuc']);

// ── DURUM ──────────────────────────────────────────────────────────────────────
$kT = $kOzet = [];
if ($kaynak) { $kT = pkt_tablolar($kaynak); $kOzet = pkt_ozetler($kaynak, $kT); }
$b = (!$adHata && $kaynak) ? pkt_hedef_baglan($hedefDb) : ['pdo' => null, 'durum' => 'hata', 'mesaj' => (string)$adHata];
$hT = $hOzet = []; $isaret = null; $kopyaNeden = null;
if ($b['pdo']) {
    $hT = pkt_tablolar($b['pdo']);
    $hOzet = pkt_ozetler($b['pdo'], $hT);
    $isaret = pkt_isaret_son($b['pdo']);
    $kopyaNeden = pkt_kopya_izni($b['pdo'], $aktif, false);
}
$kaynakDegismedi = $isaret && $kT && pkt_esit($isaret['ozet'], $kOzet);
$hedefDegismedi  = $isaret && pkt_esit($isaret['ozet'], $hOzet);
$tumTablolar = array_values(array_unique(array_merge($kT, $hT))); sort($tumTablolar);
$ustuneYazSor = $kopyaNeden !== null && !$aktif;     // kutu işaretlenirse kopyaya izin var

$adim1 = $b['durum'] === 'ok';
$adim2 = $isaret !== null && $hedefDegismedi;
$adim3 = $aktif;
$adim4 = $aktif && $isaret && !$kT;

require_once __DIR__ . '/../includes/header.php';
$rozet = fn(bool $ok, string $no) => '<span class="badge rounded-pill ' . ($ok ? 'bg-success' : 'bg-secondary') . ' me-2">'
    . ($ok ? '<i class="bi bi-check-lg"></i>' : $no) . '</span>';
?>
<h4 class="mb-1"><i class="bi bi-database-gear text-primary me-2"></i>Prekast — Kendi Veritabanına Taşıma</h4>
<p class="text-muted small mb-3">
    Prekast tabloları şu an CRM'in veritabanında (<code><?= h($kaynakDb) ?></code>) duruyor. Kuralımız gereği her modül
    kendi veritabanında olmalı. Bu ekran tabloları yeni veritabanına <strong>kopyalar, satır satır doğrular</strong> ve
    geçişten sonra eski kopyaları yedek alarak kaldırır. Kopyalama sırasında mevcut veriye dokunulmaz.
</p>

<?php if ($sonuc): ?>
<div class="alert <?= $sonuc['ok'] ? 'alert-success' : 'alert-danger' ?>">
    <i class="bi <?= $sonuc['ok'] ? 'bi-check-circle' : 'bi-x-circle' ?> me-1"></i><?= h($sonuc['mesaj']) ?>
    <?php if (!empty($sonuc['tablolar'])): ?>
    <ul class="mb-0 mt-1 small">
        <?php foreach ($sonuc['tablolar'] as $t => $r): ?>
        <li><code><?= h($t) ?></code>: <?= number_format($r['kaynak'], 0, ',', '.') ?> → <?= number_format($r['hedef'], 0, ',', '.') ?> satır
            <?= $r['esit'] ? '✓ birebir' : '✗ farklı' ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($kaynakHata): ?>
<div class="alert alert-danger">CRM veritabanına bağlanılamadı: <?= h($kaynakHata) ?></div>
<?php endif; ?>

<!-- ADIM 1 — Hedef veritabanı -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body">
    <h6 class="fw-bold"><?= $rozet($adim1, '1') ?>Yeni veritabanı</h6>
    <?php if (!$aktif): ?>
    <form method="get" class="row g-2 align-items-end mb-2" style="max-width:520px">
        <div class="col">
            <label class="form-label small mb-1">Veritabanı adı</label>
            <input type="text" name="hedef" class="form-control form-control-sm" value="<?= h($hedefDb) ?>" pattern="[A-Za-z0-9_]{1,64}">
        </div>
        <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Kontrol et</button></div>
    </form>
    <?php else: ?>
    <p class="small mb-2">config.php'de tanımlı: <code><?= h($hedefDb) ?></code></p>
    <?php endif; ?>

    <?php if ($adHata): ?>
        <div class="alert alert-danger py-2 small mb-0"><?= h($adHata) ?></div>
    <?php elseif ($b['durum'] === 'ok'): ?>
        <div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i>
            <code><?= h($hedefDb) ?></code> veritabanına bağlanıldı.</div>
    <?php else: ?>
        <div class="alert alert-warning py-2 small">
            <?= $b['durum'] === 'yok' ? 'Bu adla bir veritabanı yok.' : 'Veritabanına bağlanılamadı.' ?>
            <span class="text-muted d-block"><?= h($b['mesaj']) ?></span>
        </div>
        <?php if ($b['durum'] === 'yok'): ?>
        <form method="post" class="mb-3">
            <input type="hidden" name="islem" value="olustur"><input type="hidden" name="hedef" value="<?= h($hedefDb) ?>">
            <button class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Veritabanını oluştur</button>
            <span class="small text-muted ms-2">Sistemin veritabanı kullanıcısının yetkisi yetmezse aşağıdaki aaPanel yolunu kullanın.</span>
        </form>
        <?php endif; ?>
        <div class="small border rounded p-2 bg-body-tertiary">
            <strong>aaPanel ile:</strong>
            <ol class="mb-1 ps-3">
                <li><strong>Veritabanı</strong> → <strong>Veritabanı ekle</strong>: ad <code><?= h($hedefDb) ?></code>,
                    kullanıcı adı aaPanel'in önerdiği (genelde aynı ad), şifreyi kopyalayın, erişim <em>Yerel sunucu</em>.</li>
                <li><code>config.php</code>'ye <strong>yalnız şu iki satırı</strong> ekleyin
                    (<code>PREKAST_DB_NAME</code>'i HENÜZ eklemeyin — o 3. adım):
<pre class="mb-1 mt-1">define('PREKAST_DB_USER', '<?= h($hedefDb) ?>');
define('PREKAST_DB_PASS', 'aaPanel\'in verdiği şifre');</pre></li>
                <li>Bu sayfayı yenileyin.</li>
            </ol>
        </div>
    <?php endif; ?>
</div>
</div>

<!-- ADIM 2 — Kopyala -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body">
    <h6 class="fw-bold"><?= $rozet($adim2, '2') ?>Tabloları kopyala ve doğrula</h6>
    <table class="table table-sm small mb-2" style="max-width:640px">
        <thead class="table-light"><tr><th>Tablo</th>
            <th class="text-end">CRM'de (<?= h($kaynakDb) ?>)</th>
            <th class="text-end">Yeni DB'de</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach ($tumTablolar as $t):
            $k = $kOzet[$t] ?? null; $hh = $hOzet[$t] ?? null; ?>
            <tr>
                <td><code><?= h($t) ?></code></td>
                <td class="text-end"><?= $k ? number_format($k['satir'], 0, ',', '.') : '—' ?></td>
                <td class="text-end"><?= $hh ? number_format($hh['satir'], 0, ',', '.') : '—' ?></td>
                <td><?php
                    if ($k && $hh) echo $k === $hh ? '<span class="text-success">✓ birebir aynı</span>' : '<span class="text-warning">farklı</span>';
                    elseif ($k) echo '<span class="text-muted">henüz kopyalanmadı</span>';
                    else echo '<span class="text-muted">yalnız yeni DB\'de</span>';
                ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$tumTablolar): ?><tr><td colspan="4" class="text-muted">Prekast tablosu bulunamadı.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if ($isaret): ?>
        <p class="small text-muted mb-2">Son kopyalama: <?= h(date('d.m.Y H:i', strtotime($isaret['zaman']))) ?>
            <?= $isaret['kullanici'] ? '· ' . h($isaret['kullanici']) : '' ?></p>
    <?php endif; ?>

    <?php if (!$b['pdo']): ?>
        <p class="small text-muted mb-0">Önce 1. adım.</p>
    <?php elseif (!$kT): ?>
        <p class="small text-muted mb-0">CRM veritabanında kopyalanacak prekast tablosu yok.</p>
    <?php elseif ($kopyaNeden !== null && $aktif): ?>
        <div class="alert alert-danger py-2 small mb-0"><?= h($kopyaNeden) ?></div>
    <?php elseif (!$aktif || !$adim2 || !$kaynakDegismedi): ?>
        <form method="post" onsubmit="return confirm('Prekast tabloları yeni veritabanına kopyalanacak. Devam edilsin mi?')">
            <input type="hidden" name="islem" value="kopyala"><input type="hidden" name="hedef" value="<?= h($hedefDb) ?>">
            <?php if ($ustuneYazSor): ?>
            <div class="form-check small mb-2">
                <input class="form-check-input" type="checkbox" name="ustune_yaz" value="1" id="uy" required>
                <label class="form-check-label" for="uy">Yeni veritabanında farklı prekast verisi var — üzerine yazılsın
                    (prekast hâlâ CRM veritabanından çalıştığı için asıl veri oradadır)</label>
            </div>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm"><i class="bi bi-files me-1"></i><?= $isaret ? 'Yeniden kopyala' : 'Kopyala' ?></button>
            <span class="small text-muted ms-2">CRM'deki tablolar yalnız okunur, değişmez.</span>
        </form>
    <?php endif; ?>
</div>
</div>

<!-- ADIM 3 — config.php -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body">
    <h6 class="fw-bold"><?= $rozet($adim3, '3') ?>Modülü yeni veritabanına geçir</h6>
    <?php if ($aktif): ?>
        <div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i>
            Prekast artık <code><?= h($hedefDb) ?></code> veritabanından çalışıyor.</div>
    <?php else: ?>
        <p class="small mb-1">Kopya doğrulandıktan sonra aaPanel → Dosyalar → <code>/www/wwwroot/ernsaha.com.tr/beton/config.php</code>
            dosyasına şu satırı ekleyip kaydedin:</p>
        <pre class="small mb-1 p-2 border rounded bg-body-tertiary">define('PREKAST_DB_NAME', '<?= h($hedefDb) ?>');</pre>
        <p class="small text-warning-emphasis mb-0"><i class="bi bi-exclamation-triangle me-1"></i>
            2. adımla bu adım arasında prekast çizelgesi yüklemeyin. Yüklenirse 4. adım bunu fark eder ve yeniden kopyalamanızı ister.</p>
    <?php endif; ?>
</div>
</div>

<!-- ADIM 4 — Eski kopyaları kaldır -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body">
    <h6 class="fw-bold"><?= $rozet((bool)$adim4, '4') ?>CRM veritabanındaki eski kopyaları kaldır</h6>
    <?php if (!$aktif): ?>
        <p class="small text-muted mb-0">Önce 3. adım.</p>
    <?php elseif (!$kT): ?>
        <div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i>
            CRM veritabanında prekast tablosu kalmadı. Taşıma tamamlandı.</div>
    <?php elseif (!$isaret): ?>
        <div class="alert alert-warning py-2 small mb-0">Yeni veritabanında kopyalama kaydı yok. Önce 2. adımı yapın.</div>
    <?php elseif ($kaynakDegismedi): ?>
        <p class="small mb-2">CRM'deki prekast tabloları kopyadan beri değişmedi; içlerindeki her satır yeni veritabanında.
            Silmeden önce CRM veritabanının <strong>tam yedeği</strong> otomatik alınır (Yedekleme sayfasında görünür).</p>
        <form method="post" class="row g-2 align-items-center" style="max-width:520px"
              onsubmit="return confirm('CRM veritabanındaki prekast tabloları silinecek (önce yedek alınır). Emin misiniz?')">
            <input type="hidden" name="islem" value="eski_sil">
            <div class="col-auto"><input type="text" name="onay" class="form-control form-control-sm" placeholder="SIL yazın" autocomplete="off" required></div>
            <div class="col-auto"><button class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Eski kopyaları kaldır</button></div>
        </form>
    <?php elseif ($hedefDegismedi): ?>
        <div class="alert alert-warning py-2 small mb-0">
            Kopyadan sonra CRM veritabanındaki prekast tablolarına kayıt girilmiş (büyük ihtimalle geçişten önce bir çizelge
            yüklendi). Yeni veritabanı henüz değişmediği için güvenli: 2. adımdaki <strong>Yeniden kopyala</strong> ile eşitleyin.</div>
    <?php else: ?>
        <div class="alert alert-danger py-2 small mb-0">
            Kopyadan sonra <strong>iki taraf da</strong> değişmiş: geçişten önce eski veritabanına, sonra yeni veritabanına
            çizelge yüklenmiş. Eski veritabanına giren kayıtları yeni tarafa almak için o günkü çizelgeyi Prekast'a bir kez
            daha yükleyin (içe aktarma birleştirme yaptığı için mükerrer kayıt oluşmaz). Ardından eski kopyaları
            aşağıdan kaldırabilirsiniz; silmeden önce yine tam yedek alınır.</div>
        <form method="post" class="row g-2 align-items-center mt-2" style="max-width:640px"
              onsubmit="return confirm('CRM veritabanındaki prekast tabloları silinecek (önce yedek alınır). Emin misiniz?')">
            <input type="hidden" name="islem" value="eski_sil">
            <div class="col-12 form-check small ms-2">
                <input class="form-check-input" type="checkbox" name="zorla" value="1" id="zorla" required>
                <label class="form-check-label" for="zorla">Son çizelgeyi yeniden yükledim; eski kopyalar silinebilir</label>
            </div>
            <div class="col-auto"><input type="text" name="onay" class="form-control form-control-sm" placeholder="SIL yazın" autocomplete="off" required></div>
            <div class="col-auto"><button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Eski kopyaları kaldır</button></div>
        </form>
    <?php endif; ?>
</div>
</div>

<a href="kurulum_prekast.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Prekast Kurulum</a>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
