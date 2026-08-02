<?php
/**
 * yedekleme.php — Çok veritabanlı yedekleme yardımcıları
 *
 * Sistem 5 ayrı veritabanı kullanır (beton/demir/seramik/depo/akaryakit).
 * Buradaki fonksiyonlar her biri için AYRI yedek dosyası üretir:
 *     {tip}_{modul}_{Y-m-d_H-i-s}.sql.gz      ör. auto_demir_2026-08-02_03-00-00.sql.gz
 *
 * Bir modülün *_DB_NAME sabiti tanımsızsa o modül ana DB'ye düşer; bu durumda
 * aynı veritabanı iki kez yedeklenmesin diye DB adına göre tekilleştirme yapılır.
 */

/** Yedeklenecek veritabanlarının listesi (DB adına göre tekilleştirilmiş). */
function yedek_db_listesi(): array
{
    $tanim = [
        ['beton',     'Beton',     'DB_NAME',           'DB_USER',           'DB_PASS'],
        ['demir',     'Demir',     'DEMIR_DB_NAME',     'DEMIR_DB_USER',     'DEMIR_DB_PASS'],
        ['seramik',   'Seramik',   'SERAMIK_DB_NAME',   'SERAMIK_DB_USER',   'SERAMIK_DB_PASS'],
        ['depo',      'Depo',      'DEPO_DB_NAME',      'DEPO_DB_USER',      'DEPO_DB_PASS'],
        ['akaryakit', 'Akaryakıt', 'AKARYAKIT_DB_NAME', 'AKARYAKIT_DB_USER', 'AKARYAKIT_DB_PASS'],
    ];

    $liste = [];
    $gorulenDb = [];
    foreach ($tanim as [$key, $label, $cDb, $cUser, $cPass]) {
        $db = (defined($cDb) && constant($cDb) !== '') ? constant($cDb) : DB_NAME;
        if (isset($gorulenDb[$db])) continue;   // ana DB'ye düşen modülü tekrar yedekleme
        $gorulenDb[$db] = true;

        $liste[$key] = [
            'key'   => $key,
            'label' => $label,
            'db'    => $db,
            'user'  => (defined($cUser) && constant($cUser) !== '') ? constant($cUser) : DB_USER,
            'pass'  => defined($cPass) ? constant($cPass) : DB_PASS,
        ];
    }
    return $liste;
}

/** Modül için PDO bağlantısı aç; başarısızsa null (yedekleme akışı çökmesin). */
function yedek_baglan(array $modul): ?PDO
{
    try {
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . $modul['db'] . ';charset=utf8mb4',
            $modul['user'],
            $modul['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Tek bir veritabanının gzip'li SQL dökümünü üretir.
 * @return string|null Oluşan dosya adı, hata olursa null
 */
function yedek_olustur(PDO $pdo, string $dir, string $tip, string $modulKey, string $dbAdi = ''): ?string
{
    try {
        $dosyaAdi = $tip . '_' . $modulKey . '_' . date('Y-m-d_H-i-s') . '.sql.gz';
        $yol      = $dir . '/' . $dosyaAdi;

        $tablolar = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $sql  = "-- Şantiye Takip Sistemi — Yedek\n";
        $sql .= "-- Veritabanı : " . ($dbAdi ?: $modulKey) . "\n";
        $sql .= "-- Tarih      : " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Tablo       : " . count($tablolar) . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tablolar as $tablo) {
            $create = $pdo->query("SHOW CREATE TABLE `$tablo`")->fetch(PDO::FETCH_NUM);
            $sql   .= "DROP TABLE IF EXISTS `$tablo`;\n" . ($create[1] ?? '') . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `$tablo`")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
                $sql .= "INSERT INTO `$tablo` ($cols) VALUES\n";
                $vals = array_map(
                    fn($r) => '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $r)) . ')',
                    $rows
                );
                $sql .= implode(",\n", $vals) . ";\n\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $gz = gzopen($yol, 'wb9');
        if (!$gz) return null;
        gzwrite($gz, $sql);
        gzclose($gz);

        return $dosyaAdi;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * TÜM veritabanlarını yedekler.
 * @return array{alinan: string[], hata: string[]}
 */
function yedek_tumunu_al(string $dir, string $tip = 'manual'): array
{
    $alinan = [];
    $hata   = [];
    foreach (yedek_db_listesi() as $m) {
        $pdo = yedek_baglan($m);
        if (!$pdo) { $hata[] = $m['label']; continue; }
        $ad = yedek_olustur($pdo, $dir, $tip, $m['key'], $m['db']);
        if ($ad) $alinan[] = $ad; else $hata[] = $m['label'];
    }
    return ['alinan' => $alinan, 'hata' => $hata];
}

/**
 * Günlük otomatik yedek — her modül için günde bir kez.
 * Eksik olan modüller tamamlanır (biri alınmışsa diğerleri atlanmaz).
 */
function yedek_otomatik_calistir(string $dir, int $saklamaGun = 30): void
{
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $bugun = date('Y-m-d');

    foreach (yedek_db_listesi() as $m) {
        if (glob($dir . '/auto_' . $m['key'] . '_' . $bugun . '*.sql.gz')) continue; // bugün alınmış
        $pdo = yedek_baglan($m);
        if (!$pdo) continue;
        yedek_olustur($pdo, $dir, 'auto', $m['key'], $m['db']);
    }

    // Eski otomatik yedekleri temizle
    $sinir = strtotime('-' . $saklamaGun . ' days');
    foreach (glob($dir . '/auto_*.sql.gz') ?: [] as $eski) {
        if (filemtime($eski) < $sinir) @unlink($eski);
    }
}

/**
 * Yedek dosya adını çözümler → ['tip' => ..., 'modul' => ...]
 * Eski format (auto_2026-08-02_...) beton kabul edilir.
 */
function yedek_ad_coz(string $dosyaAdi): array
{
    if (preg_match('/^(auto|manual|pre_restore)_([a-z]+)_\d{4}-\d{2}-\d{2}/', $dosyaAdi, $m)) {
        return ['tip' => $m[1], 'modul' => $m[2]];
    }
    if (preg_match('/^(auto|manual|pre_restore)_\d{4}-\d{2}-\d{2}/', $dosyaAdi, $m)) {
        return ['tip' => $m[1], 'modul' => 'beton'];   // eski format
    }
    return ['tip' => 'manual', 'modul' => 'beton'];
}
