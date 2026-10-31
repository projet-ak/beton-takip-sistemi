<?php
// ⚠ Yalnız KOMUT SATIRI aracı — web'den çalıştırılamaz.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Bu dosya yalnız komut satırından çalışır.'); }
/**
 * yedek_cron.php — sunucudaki gece görevinin (tools/drive_yedek.sh) PHP tarafı.
 *
 *   php tools/yedek_cron.php yedek
 *       Günlük otomatik yedeği alır (tüm modül veritabanları, günde bir kez — o gün alınmışsa atlar).
 *       Eskiden otomatik yedek YALNIZ bir yönetici panele girdiğinde alınıyordu; hafta sonu kimse
 *       girmezse yedek de alınmıyordu.
 *
 *   php tools/yedek_cron.php drive <rclone_kodu> <kopyalanan> <drive_toplam|-> <silinen> <log_dosyasi|mesaj>
 *       rclone sonucunu yedek_drive_log tablosuna yazar (yedek.php "Google Drive kopyası" kartı okur).
 */
$kok = dirname(__DIR__);
if (!is_file($kok . '/config.php')) { fwrite(STDERR, "config.php bulunamadı: $kok\n"); exit(1); }
require $kok . '/config.php';
require $kok . '/includes/yedekleme.php';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Veritabanına bağlanılamadı: ' . $e->getMessage() . "\n");
    exit(1);
}

$komut = $argv[1] ?? 'yedek';

if ($komut === 'yedek') {
    $dir = $kok . '/backups';
    $once = glob($dir . '/*.sql.gz') ?: [];
    yedek_otomatik_calistir($dir);
    $yeni = array_diff(glob($dir . '/*.sql.gz') ?: [], $once);
    echo $yeni ? count($yeni) . " yeni yedek:\n  " . implode("\n  ", array_map('basename', $yeni)) . "\n"
               : "Bugünün yedekleri zaten alınmış.\n";
    exit(0);
}

if ($komut === 'drive') {
    $kod     = (int)($argv[2] ?? 1);
    $kopya   = (int)($argv[3] ?? 0);
    $toplam  = isset($argv[4]) && ctype_digit($argv[4]) ? (int)$argv[4] : null;
    $silinen = (int)($argv[5] ?? 0);
    $kaynak  = (string)($argv[6] ?? '');
    $mesaj   = '';
    if ($kod !== 0) {
        if ($kaynak !== '' && is_file($kaynak)) {
            // rclone günlüğünden yalnız hata/uyarı satırları (son 15)
            $satirlar = preg_grep('/ERROR|NOTICE|Failed|failed|error/', file($kaynak, FILE_IGNORE_NEW_LINES) ?: []);
            $mesaj = implode("\n", array_slice($satirlar, -15)) ?: 'rclone çıkış kodu ' . $kod;
        } else {
            $mesaj = $kaynak !== '' ? $kaynak : 'rclone çıkış kodu ' . $kod;
        }
    }
    yedek_drive_kaydet($pdo, $kod === 0, $kopya, $silinen, $toplam, $mesaj);
    echo ($kod === 0 ? 'Drive kopyası kaydedildi' : 'Drive HATASI kaydedildi') . ": $kopya yeni, $silinen silinen, Drive'da " . ($toplam ?? '?') . " dosya\n";
    exit(0);
}

fwrite(STDERR, "Bilinmeyen komut: $komut (yedek | drive)\n");
exit(1);
