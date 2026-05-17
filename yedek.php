<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Veritabanı Yedekleme — Beton Takip Sistemi';

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) { mkdir($backupDir, 0755, true); }

$htFile = $backupDir . '/.htaccess';
if (!file_exists($htFile)) {
    file_put_contents($htFile, "Options -Indexes\nDeny from all\n");
}

function createBackup(PDO $pdo, string $dir, string $prefix = ''): string {
    $filename = ($prefix ?: 'manual') . '_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $dir . '/' . $filename;
    $tables   = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $sql      = "-- Beton Takip Yedek\n-- " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $sql   .= "DROP TABLE IF EXISTS `$table`;\n" . ($create[1] ?? '') . ";\n\n";
        $rows   = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $cols  = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $sql  .= "INSERT INTO `$table` ($cols) VALUES\n";
            $vals  = [];
            foreach ($rows as $row) {
                $vals[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $row)) . ')';
            }
            $sql .= implode(",\n", $vals) . ";\n\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($filepath, $sql);
    return $filename;
}

// Günlük otomatik yedek
$todayPattern = $backupDir . '/auto_' . date('Y-m-d') . '*.sql';
if (!glob($todayPattern)) {
    createBackup($pdo, $backupDir, 'auto');
    foreach (glob($backupDir . '/auto_*.sql') ?: [] as $f) {
        if (filemtime($f) < strtotime('-30 days')) { unlink($f); }
    }
}

// Manuel yedek al
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['yedek_al'])) {
    $fname = createBackup($pdo, $backupDir, 'manual');
    flash('success', "Yedek alındı: $fname");
    redirect('yedek.php');
}

// Yedek indir
if (isset($_GET['indir']) && preg_match('/^[\w\-\.]+\.sql$/', $_GET['indir'])) {
    $file = $backupDir . '/' . basename($_GET['indir']);
    if (file_exists($file) && is_file($file)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    flash('error', 'Dosya bulunamadı.');
    redirect('yedek.php');
}

// Yedek sil
if (isset($_GET['sil']) && preg_match('/^[\w\-\.]+\.sql$/', $_GET['sil'])) {
    $file = $backupDir . '/' . basename($_GET['sil']);
    if (file_exists($file)) { unlink($file); flash('success', 'Yedek silindi.'); }
    redirect('yedek.php');
}

// Geri yükle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sql_dosya'])) {
    $f = $_FILES['sql_dosya'];
    if ($f['error'] !== UPLOAD_ERR_OK) { flash('error', 'Dosya yükleme hatası.'); redirect('yedek.php'); }
    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'sql') {
        flash('error', 'Sadece .sql dosyası yüklenebilir.'); redirect('yedek.php');
    }
    createBackup($pdo, $backupDir, 'pre_restore'); // Geri yüklemeden önce yedek al
    $sqlContent = file_get_contents($f['tmp_name']);
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        // Line-by-line parser: multi-line CREATE TABLE/INSERT düzgün ayrıştırılır
        $lines = explode("\n", $sqlContent);
        $stmt = ''; $count = 0;
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with($line, '--')) continue;
            $stmt .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $s = trim($stmt);
                if ($s && strlen($s) > 1) { $pdo->exec($s); $count++; }
                $stmt = '';
            }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        flash('success', "Veritabanı geri yüklendi. ($count sorgu)");
        // Oturumu temizle — yeni verilerle yeniden giriş yapılsın
        session_destroy();
        redirect('login.php');
    } catch (PDOException $e) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        flash('error', 'Geri yükleme hatası: ' . h($e->getMessage()));
        redirect('yedek.php');
    }
}

// Yedek listesi
$yedekler = [];
foreach (array_reverse(glob($backupDir . '/*.sql') ?: []) as $f) {
    $name = basename($f);
    if ($name === '.htaccess') continue;
    $yedekler[] = ['name' => $name, 'size' => filesize($f), 'mtime' => filemtime($f), 'is_auto' => str_starts_with($name, 'auto_')];
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-shield-check text-primary me-2"></i>Veritabanı Yedekleme</h4>
    <form method="post" class="d-inline">
        <button name="yedek_al" value="1" class="btn btn-primary">
            <i class="bi bi-cloud-download me-1"></i> Hemen Yedek Al
        </button>
    </form>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-success"><div class="card-body d-flex align-items-center gap-3">
        <i class="bi bi-database-fill-check text-success fs-3"></i>
        <div><div class="fw-bold"><?= count($yedekler) ?> Yedek</div><div class="text-muted small">Sunucuda saklanan</div></div>
    </div></div></div>
    <div class="col-md-4"><div class="card border-info"><div class="card-body d-flex align-items-center gap-3">
        <i class="bi bi-clock-history text-info fs-3"></i>
        <div><div class="fw-bold">Günlük Otomatik</div><div class="text-muted small">Her gün otomatik alınır</div></div>
    </div></div></div>
    <div class="col-md-4"><div class="card border-warning"><div class="card-body d-flex align-items-center gap-3">
        <i class="bi bi-calendar-x text-warning fs-3"></i>
        <div><div class="fw-bold">30 Gün Saklama</div><div class="text-muted small">Otomatik yedekler temizlenir</div></div>
    </div></div></div>
</div>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card"><div class="card-header fw-semibold"><i class="bi bi-archive me-1"></i> Mevcut Yedekler</div>
        <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Dosya Adı</th><th class="text-center">Tür</th><th class="text-end">Boyut</th><th class="text-center">Tarih</th><th class="text-end">İşlem</th></tr></thead>
        <tbody>
        <?php foreach ($yedekler as $y): ?>
        <tr>
            <td class="font-monospace small"><?= h($y['name']) ?></td>
            <td class="text-center"><span class="badge <?= $y['is_auto'] ? 'bg-info' : 'bg-primary' ?>"><?= $y['is_auto'] ? 'Otomatik' : 'Manuel' ?></span></td>
            <td class="text-end text-nowrap"><?= round($y['size'] / 1024, 1) ?> KB</td>
            <td class="text-center text-nowrap small"><?= date('d.m.Y H:i', $y['mtime']) ?></td>
            <td class="text-end text-nowrap">
                <a href="yedek.php?indir=<?= urlencode($y['name']) ?>" class="btn btn-xs btn-outline-success me-1" title="İndir"><i class="bi bi-download"></i></a>
                <a href="yedek.php?sil=<?= urlencode($y['name']) ?>" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Bu yedeği silmek istediğinize emin misiniz?" title="Sil"><i class="bi bi-trash"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$yedekler): ?><tr><td colspan="5" class="text-center text-muted py-4">Henüz yedek yok.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    </div>
    <div class="col-lg-4">
        <div class="card border-danger"><div class="card-header bg-danger text-white fw-semibold"><i class="bi bi-cloud-upload me-1"></i> Yedekten Geri Yükle</div>
        <div class="card-body">
            <div class="alert alert-warning py-2 small mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Dikkat!</strong> Geri yükleme mevcut verilerin üzerine yazar. İşlem öncesi otomatik yedek alınır.
            </div>
            <form method="post" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-semibold">SQL Dosyası Seç</label>
                    <input type="file" name="sql_dosya" class="form-control" accept=".sql" required>
                    <div class="form-text">Sadece .sql yedek dosyaları</div>
                </div>
                <button class="btn btn-danger w-100 btn-confirm" data-msg="VERİTABANI GERİ YÜKLENECEKTİR! Tüm mevcut veriler silinip yedekteki veriler yüklenir. Emin misiniz?">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Geri Yükle
                </button>
            </form>
        </div></div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
