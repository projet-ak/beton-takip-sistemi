<?php
/**
 * functions.php — Genel yardımcı fonksiyonlar
 */

/**
 * Tarihi Türkçe formatla: d.m.Y
 */
function format_date(?string $d): string
{
    if (empty($d)) {
        return '-';
    }
    $ts = strtotime($d);
    return $ts !== false ? date('d.m.Y', $ts) : htmlspecialchars($d);
}

/**
 * Sayıyı Türkçe formatla (virgül ondalık, nokta binler ayracı)
 */
function format_number($n, int $decimals = 2): string
{
    return number_format((float)$n, $decimals, ',', '.');
}

/**
 * Rol adını Türkçe karşılığıyla döner
 */
function role_label(string $role): string
{
    $map = [
        'admin'             => 'Yönetici',
        'teknik_ofis_admin' => 'Teknik Ofis Yöneticisi',
        'teknik_ofis'       => 'Teknik Ofis',
        'saha_sefi'         => 'Saha Şefi',
        'depo'              => 'Depo',
    ];
    return $map[$role] ?? htmlspecialchars($role);
}

/**
 * HTTP yönlendirmesi + çıkış
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Flash mesajı oturuma yaz.
 * $msg null ise yazma yapılmaz (sadece ayarlamak için kullanılır).
 *
 * Kullanım: flash('success', 'İşlem başarılı.')
 */
function flash(string $key, ?string $msg = null): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($msg !== null) {
        $_SESSION['_flash'][$key] = $msg;
    }
}

/**
 * Flash mesajı kaydet.
 *
 * Kullanım: set_flash('success', 'Kaydedildi.')
 */
function set_flash(string $key, string $msg): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['_flash'][$key] = $msg;
}

/**
 * Flash mesajı oku ve sil; yoksa null döner.
 *
 * Kullanım: $msg = get_flash('success')
 */
function get_flash(string $key): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $msg = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $msg;
}

/**
 * XSS korumalı HTML çıkışı için kısaltma
 */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Audit log kaydı yaz
 *
 * @param PDO    $pdo
 * @param string $tablo      Tablo adı
 * @param int    $kayitId    Kayıt ID
 * @param string $islem      INSERT | UPDATE | DELETE
 * @param mixed  $eskiDeger  Eski değer (dizi veya null)
 * @param mixed  $yeniDeger  Yeni değer (dizi veya null)
 * @param int|null $kullaniciId
 */
function audit_log(PDO $pdo, string $tablo, int $kayitId, string $islem, $eskiDeger = null, $yeniDeger = null, ?int $kullaniciId = null): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (tablo, kayit_id, islem, eski_deger, yeni_deger, kullanici_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $tablo,
            $kayitId,
            $islem,
            $eskiDeger !== null ? json_encode($eskiDeger, JSON_UNESCAPED_UNICODE) : null,
            $yeniDeger !== null ? json_encode($yeniDeger, JSON_UNESCAPED_UNICODE) : null,
            $kullaniciId,
        ]);
    } catch (PDOException $e) {
        // Audit log başarısız olsa bile ana işlemi bozmayalım
        error_log('audit_log error: ' . $e->getMessage());
    }
}

/**
 * Aktivite izleme şeması (oturum + sayfa gezinme). Ana (beton) DB'de tutulur.
 */
function aktivite_semasi_kur(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS kullanici_oturum (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kullanici_id INT NOT NULL,
        oturum_key VARCHAR(128) NOT NULL,
        giris DATETIME NOT NULL,
        son_aktivite DATETIME NOT NULL,
        sayfa_sayisi INT NOT NULL DEFAULT 0,
        ip VARCHAR(45) NULL,
        tarayici VARCHAR(255) NULL,
        UNIQUE KEY uq_oturum (oturum_key),
        INDEX (kullanici_id), INDEX (giris)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kullanici_aktivite (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        kullanici_id INT NOT NULL,
        oturum_key VARCHAR(128) NULL,
        sayfa VARCHAR(120) NULL,
        modul VARCHAR(20) NULL,
        yontem VARCHAR(8) NULL,
        ip VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (kullanici_id), INDEX (created_at), INDEX (oturum_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Oturum + sayfa aktivitesini yazar (aktivite_izle içinden çağrılır) */
function aktivite_yaz(PDO $pdo, string $modul): void
{
    $uid    = (int)($_SESSION['user']['id'] ?? 0);
    $sid    = session_id() ?: '';
    if ($uid === 0 || $sid === '') return;
    $sayfa  = basename($_SERVER['PHP_SELF'] ?? '');
    $yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $ip     = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ua     = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $pdo->prepare("INSERT INTO kullanici_oturum (kullanici_id, oturum_key, giris, son_aktivite, sayfa_sayisi, ip, tarayici)
        VALUES (?, ?, NOW(), NOW(), 1, ?, ?)
        ON DUPLICATE KEY UPDATE son_aktivite = NOW(), sayfa_sayisi = sayfa_sayisi + 1")
        ->execute([$uid, $sid, $ip, $ua]);
    $pdo->prepare("INSERT INTO kullanici_aktivite (kullanici_id, oturum_key, sayfa, modul, yontem, ip)
        VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$uid, $sid, $sayfa, $modul, $yontem, $ip]);
}

/**
 * Her sayfa render'ında çağrılır (header.php). Giriş yapan kullanıcının oturum
 * süresini ve sayfa gezinmesini kaydeder. Tablo yoksa oluşturup bir kez daha dener.
 * $pdo verilmezse ana (beton) DB'ye kendi bağlantısını açar (alt modül sayfaları için).
 */
function aktivite_izle(?PDO $pdo, string $modul = ''): void
{
    if (empty($_SESSION['user']) || !defined('DB_HOST')) return;
    static $done = false; if ($done) return; $done = true;
    try {
        if (!$pdo instanceof PDO) {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        aktivite_yaz($pdo, $modul);
    } catch (Throwable $e) {
        // Tablo yoksa oluştur ve bir kez daha dene
        try { if ($pdo instanceof PDO) { aktivite_semasi_kur($pdo); aktivite_yaz($pdo, $modul); } }
        catch (Throwable $e2) { error_log('aktivite_izle: ' . $e2->getMessage()); }
    }
}

/**
 * Aktif kullanıcı ID'sini döner (oturumdan)
 */
function current_user_id(): ?int
{
    return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
}
