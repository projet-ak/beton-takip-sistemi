<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/yedekleme.php';

$pageTitle = 'Veritabanı Yedekleme — Şantiye Takip Sistemi';

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) { mkdir($backupDir, 0755, true); }

$htFile = $backupDir . '/.htaccess';
if (!file_exists($htFile)) {
    file_put_contents($htFile, "Options -Indexes\nDeny from all\n");
}

$moduller = yedek_db_listesi();

// Günlük otomatik yedek (tüm veritabanları)
yedek_otomatik_calistir($backupDir);

// CSRF token (küresel — merkezi doğrulama auth.php'de)
$csrfToken = csrf_token();

function verifyCsrf(): void {
    if (!csrf_ok()) {
        http_response_code(403);
        die('Güvenlik hatası. Sayfayı yenileyip tekrar deneyin.');
    }
}

// ── Manuel yedek al ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['yedek_al'])) {
    verifyCsrf();
    $hedef = (string)($_POST['hedef'] ?? 'tumu');

    if ($hedef === 'tumu') {
        $sonuc = yedek_tumunu_al($backupDir, 'manual');
        if ($sonuc['alinan']) {
            flash('success', count($sonuc['alinan']) . ' veritabanı yedeklendi: ' . implode(', ', $sonuc['alinan']));
        }
        if ($sonuc['hata']) {
            flash('error', 'Yedeklenemedi: ' . implode(', ', $sonuc['hata']));
        }
    } elseif (isset($moduller[$hedef])) {
        $m   = $moduller[$hedef];
        $cnn = yedek_baglan($m);
        $ad  = $cnn ? yedek_olustur($cnn, $backupDir, 'manual', $m['key'], $m['db']) : null;
        if ($ad) flash('success', $m['label'] . " yedeği alındı: $ad");
        else     flash('error', $m['label'] . ' yedeği alınamadı.');
    } else {
        flash('error', 'Geçersiz hedef veritabanı.');
    }
    redirect('yedek.php');
}

// ── Yedek indir ───────────────────────────────────────────────────────────────
if (isset($_GET['indir']) && preg_match('/^[\w\-\.]+\.sql(\.gz)?$/', $_GET['indir'])) {
    $file = $backupDir . '/' . basename($_GET['indir']);
    if (file_exists($file) && is_file($file)) {
        $isGzip = str_ends_with($file, '.gz');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        if ($isGzip) header('Content-Encoding: identity'); // tarayıcı açmasın
        readfile($file);
        exit;
    }
    flash('error', 'Dosya bulunamadı.');
    redirect('yedek.php');
}

// ── Yedek sil ─────────────────────────────────────────────────────────────────
if (isset($_GET['sil']) && preg_match('/^[\w\-\.]+\.sql(\.gz)?$/', $_GET['sil'])) {
    $file = $backupDir . '/' . basename($_GET['sil']);
    if (file_exists($file)) { unlink($file); flash('success', 'Yedek silindi.'); }
    redirect('yedek.php');
}

// ── Geri yükle ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sql_dosya'])) {
    verifyCsrf();
    $f = $_FILES['sql_dosya'];
    if ($f['error'] !== UPLOAD_ERR_OK) { flash('error', 'Dosya yükleme hatası.'); redirect('yedek.php'); }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['sql', 'gz'])) {
        flash('error', 'Sadece .sql veya .sql.gz dosyası yüklenebilir.'); redirect('yedek.php');
    }

    // Hedef veritabanı — yanlış DB'ye yükleme yapılmasın diye açıkça seçilir
    $hedefKey = (string)($_POST['geri_hedef'] ?? '');
    if (!isset($moduller[$hedefKey])) {
        flash('error', 'Geri yüklenecek veritabanını seçin.'); redirect('yedek.php');
    }
    $m       = $moduller[$hedefKey];
    $hedefDb = yedek_baglan($m);
    if (!$hedefDb) { flash('error', $m['label'] . ' veritabanına bağlanılamadı.'); redirect('yedek.php'); }

    // Üzerine yazmadan önce o veritabanının güvenlik yedeği
    yedek_olustur($hedefDb, $backupDir, 'pre_restore', $m['key'], $m['db']);

    $sqlContent = ($ext === 'gz') ? gzdecode(file_get_contents($f['tmp_name'])) : file_get_contents($f['tmp_name']);
    try {
        $hedefDb->exec("SET FOREIGN_KEY_CHECKS=0");
        // Line-by-line parser: multi-line CREATE TABLE/INSERT düzgün ayrıştırılır
        $lines = explode("\n", $sqlContent);
        $stmt = ''; $count = 0;
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with($line, '--')) continue;
            $stmt .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $s = trim($stmt);
                if ($s && strlen($s) > 1) { $hedefDb->exec($s); $count++; }
                $stmt = '';
            }
        }
        $hedefDb->exec("SET FOREIGN_KEY_CHECKS=1");
        flash('success', $m['label'] . " veritabanı geri yüklendi. ($count sorgu)");

        // Kullanıcı tablosu beton DB'sinde — oturum ancak o geri yüklenince geçersizleşir
        if ($m['key'] === 'beton') {
            session_destroy();
            redirect('login.php');
        }
        redirect('yedek.php');
    } catch (PDOException $e) {
        $hedefDb->exec("SET FOREIGN_KEY_CHECKS=1");
        flash('error', 'Geri yükleme hatası: ' . h($e->getMessage()));
        redirect('yedek.php');
    }
}

// ── Yedek listesi (.sql ve .sql.gz) ───────────────────────────────────────────
$yedekler = [];
$allFiles = array_merge(
    glob($backupDir . '/*.sql.gz') ?: [],
    glob($backupDir . '/*.sql')    ?: []
);
usort($allFiles, fn($a,$b) => filemtime($b) - filemtime($a));

$modulOzet = [];   // modül başına: adet + son yedek zamanı
foreach ($allFiles as $f) {
    $name = basename($f);
    if ($name === '.htaccess') continue;
    $coz   = yedek_ad_coz($name);
    $mKey  = $coz['modul'];
    $mtime = filemtime($f);

    if (!isset($modulOzet[$mKey])) $modulOzet[$mKey] = ['adet' => 0, 'son' => 0];
    $modulOzet[$mKey]['adet']++;
    if ($mtime > $modulOzet[$mKey]['son']) $modulOzet[$mKey]['son'] = $mtime;

    $yedekler[] = [
        'name'    => $name,
        'size_kb' => round(filesize($f) / 1024, 1),
        'mtime'   => $mtime,
        'tip'     => $coz['tip'],
        'modul'   => $mKey,
        'is_gz'   => str_ends_with($name, '.gz'),
    ];
}

// Görüntülenecek modül filtresi
$filtre = isset($_GET['m']) && isset($moduller[$_GET['m']]) ? $_GET['m'] : '';
$gosterilecek = $filtre ? array_values(array_filter($yedekler, fn($y) => $y['modul'] === $filtre)) : $yedekler;

$tipRozet = ['auto' => ['Otomatik','bg-info'], 'manual' => ['Manuel','bg-primary'], 'pre_restore' => ['Geri yükleme öncesi','bg-warning text-dark']];

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-shield-check text-primary me-2"></i>Veritabanı Yedekleme</h4>
    <form method="post" class="d-flex gap-2">
        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
        <select name="hedef" class="form-select form-select-sm" style="min-width:190px">
            <option value="tumu">Tüm veritabanları (<?= count($moduller) ?>)</option>
            <?php foreach ($moduller as $m): ?>
                <option value="<?= h($m['key']) ?>"><?= h($m['label']) ?> — <?= h($m['db']) ?></option>
            <?php endforeach; ?>
        </select>
        <button name="yedek_al" value="1" class="btn btn-primary btn-sm text-nowrap">
            <i class="bi bi-cloud-download me-1"></i> Hemen Yedek Al
        </button>
    </form>
</div>

<!-- Modül bazlı yedek durumu -->
<div class="row g-3 mb-4">
    <?php foreach ($moduller as $m):
        $oz  = $modulOzet[$m['key']] ?? ['adet' => 0, 'son' => 0];
        $bugunMu = $oz['son'] && date('Y-m-d', $oz['son']) === date('Y-m-d');
        $renk = $oz['adet'] === 0 ? 'border-danger' : ($bugunMu ? 'border-success' : 'border-warning');
    ?>
    <div class="col-6 col-lg">
        <div class="card <?= $renk ?> h-100">
            <div class="card-body py-3">
                <div class="fw-bold d-flex align-items-center gap-2">
                    <?php if ($oz['adet'] === 0): ?>
                        <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                    <?php elseif ($bugunMu): ?>
                        <i class="bi bi-check-circle-fill text-success"></i>
                    <?php else: ?>
                        <i class="bi bi-clock-history text-warning"></i>
                    <?php endif; ?>
                    <?= h($m['label']) ?>
                </div>
                <div class="text-muted small font-monospace"><?= h($m['db']) ?></div>
                <div class="small mt-1">
                    <?= (int)$oz['adet'] ?> yedek
                    <?php if ($oz['son']): ?>
                        <br><span class="text-muted">Son: <?= date('d.m.Y H:i', $oz['son']) ?></span>
                    <?php else: ?>
                        <br><span class="text-danger">Hiç yedek yok</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold"><i class="bi bi-archive me-1"></i> Mevcut Yedekler (<?= count($gosterilecek) ?>)</span>
                <div class="btn-group btn-group-sm">
                    <a href="yedek.php" class="btn btn-outline-secondary <?= $filtre === '' ? 'active' : '' ?>">Tümü</a>
                    <?php foreach ($moduller as $m): ?>
                        <a href="yedek.php?m=<?= h($m['key']) ?>" class="btn btn-outline-secondary <?= $filtre === $m['key'] ? 'active' : '' ?>"><?= h($m['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th>Dosya Adı</th><th class="text-center">Veritabanı</th><th class="text-center">Tür</th>
                <th class="text-end">Boyut</th><th class="text-center">Tarih</th><th class="text-end">İşlem</th>
            </tr></thead>
            <tbody>
            <?php foreach ($gosterilecek as $y):
                [$tipAd, $tipRenk] = $tipRozet[$y['tip']] ?? ['Manuel','bg-primary'];
            ?>
            <tr>
                <td class="font-monospace small">
                    <?= h($y['name']) ?>
                    <?php if ($y['is_gz']): ?><span class="badge bg-secondary ms-1" style="font-size:.6rem">gz</span><?php endif; ?>
                </td>
                <td class="text-center"><span class="badge bg-dark"><?= h($moduller[$y['modul']]['label'] ?? $y['modul']) ?></span></td>
                <td class="text-center"><span class="badge <?= $tipRenk ?>"><?= h($tipAd) ?></span></td>
                <td class="text-end text-nowrap"><?= $y['size_kb'] ?> KB</td>
                <td class="text-center text-nowrap small"><?= date('d.m.Y H:i', $y['mtime']) ?></td>
                <td class="text-end text-nowrap">
                    <a href="yedek.php?indir=<?= urlencode($y['name']) ?>" class="btn btn-xs btn-outline-success me-1" title="İndir"><i class="bi bi-download"></i></a>
                    <a href="yedek.php?sil=<?= urlencode($y['name']) ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Bu yedeği silmek istediğinize emin misiniz?" title="Sil"><i class="bi bi-trash"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$gosterilecek): ?><tr><td colspan="6" class="text-center text-muted py-4">Henüz yedek yok.</td></tr><?php endif; ?>
            </tbody></table></div></div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white fw-semibold"><i class="bi bi-cloud-upload me-1"></i> Yedekten Geri Yükle</div>
            <div class="card-body">
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Dikkat!</strong> Geri yükleme seçtiğiniz veritabanının üzerine yazar.
                    İşlem öncesi o veritabanının güvenlik yedeği otomatik alınır.
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Hedef Veritabanı</label>
                        <select name="geri_hedef" class="form-select" required>
                            <option value="">— Seçiniz —</option>
                            <?php foreach ($moduller as $m): ?>
                                <option value="<?= h($m['key']) ?>"><?= h($m['label']) ?> — <?= h($m['db']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Yedek dosyası hangi modüle aitse onu seçin.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">SQL Dosyası Seç</label>
                        <input type="file" name="sql_dosya" class="form-control" accept=".sql,.gz" required>
                        <div class="form-text">.sql veya .sql.gz</div>
                    </div>
                    <button class="btn btn-danger w-100 btn-confirm" data-msg="SEÇTİĞİNİZ VERİTABANI GERİ YÜKLENECEK! Mevcut veriler silinip yedekteki veriler yüklenir. Emin misiniz?">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Geri Yükle
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body small text-muted">
                <div class="fw-semibold text-body mb-2"><i class="bi bi-info-circle me-1"></i>Nasıl çalışır?</div>
                <ul class="mb-0 ps-3">
                    <li>Her veritabanı <strong>ayrı dosyaya</strong> yedeklenir.</li>
                    <li>Yönetici panele girdiğinde günde bir kez <strong>otomatik</strong> alınır.</li>
                    <li>Otomatik yedekler <strong>30 gün</strong> sonra silinir.</li>
                    <li>Sunucu yedeği için ayrıca aaPanel zamanlanmış görevleri kullanılır.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
