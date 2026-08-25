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
 * Dosyanın MIME türünü güvenli tespit et.
 * fileinfo eklentisi (finfo/mime_content_type) varsa onu kullanır; yoksa
 * dosya uzantısından tahmin eder. Böylece sunucuda fileinfo kapalı olsa bile
 * yükleme doğrulaması çökmeden çalışır.
 *
 * @param string $path     Geçici/gerçek dosya yolu
 * @param string $filename Orijinal dosya adı (uzantı yedeği için)
 */
function guess_mime(string $path, string $filename = ''): string
{
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $m = @finfo_file($fi, $path);
            @finfo_close($fi);
            if (is_string($m) && $m !== '' && $m !== 'application/octet-stream') return $m;
        }
    }
    if (function_exists('mime_content_type')) {
        $m = @mime_content_type($path);
        if (is_string($m) && $m !== '') return $m;
    }
    // Eklenti yoksa uzantıdan tahmin
    $ext = strtolower(pathinfo($filename !== '' ? $filename : $path, PATHINFO_EXTENSION));
    $map = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',  'jpeg' => 'image/jpeg',
        'png'  => 'image/png',   'webp' => 'image/webp',
        'gif'  => 'image/gif',   'bmp'  => 'image/bmp',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * Faturadan otomatik açılmış TASLAK irsaliye mi?
 * (fatura_eslestir.php eksik numaralardan taslak açarken açıklamaya [FATURADAN]
 * etiketi yazar; Excel aktarımı gerçek verileri getirince etiket kalkar.)
 * Taslaklar onaylanmaz — 0 m³ ile onaylanmış kayıt oluşmasın.
 */
function irs_taslak_mi($rowOrAciklama): bool
{
    $a = is_array($rowOrAciklama) ? ($rowOrAciklama['aciklama'] ?? '') : $rowOrAciklama;
    return str_contains((string)$a, '[FATURADAN]');
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

/** Aktivite yazımı için ana (beton) DB bağlantısı — istek başına bir kez açılır/önbelleklenir.
 *  Beton sayfalarında hazır $pdo geçilir; alt modül sayfalarında (demir/seramik…) ise
 *  ana DB'ye tek bir bağlantı açılıp static önbellekte tutulur. */
function aktivite_pdo(?PDO $pdo): ?PDO
{
    static $conn = null;
    if ($pdo instanceof PDO) return $pdo;
    if ($conn instanceof PDO) return $conn;
    if (!defined('DB_HOST')) return null;
    try {
        $conn = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return $conn;
    } catch (Throwable $e) { return null; }
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
    // Oturum upsert — her render (süre + toplam sayfa sayısı için)
    $pdo->prepare("INSERT INTO kullanici_oturum (kullanici_id, oturum_key, giris, son_aktivite, sayfa_sayisi, ip, tarayici)
        VALUES (?, ?, NOW(), NOW(), 1, ?, ?)
        ON DUPLICATE KEY UPDATE son_aktivite = NOW(), sayfa_sayisi = sayfa_sayisi + 1")
        ->execute([$uid, $sid, $ip, $ua]);
    // Detay timeline — yalnız sayfa/modül değiştiğinde yaz (aynı sayfanın peş peşe
    // yenilenmesi tabloyu şişirmesin; gezinme geçişleri korunur).
    $anahtar = $modul . '|' . $sayfa;
    if (($_SESSION['__akt_sonSayfa'] ?? null) !== $anahtar) {
        $pdo->prepare("INSERT INTO kullanici_aktivite (kullanici_id, oturum_key, sayfa, modul, yontem, ip)
            VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$uid, $sid, $sayfa, $modul, $yontem, $ip]);
        $_SESSION['__akt_sonSayfa'] = $anahtar;
    }
}

/** Olasılıksal otomatik temizlik (cron gerektirmeden): eski aktivite/oturum kayıtları.
 *  Saklama süresi config'de AKTIVITE_SAKLAMA_GUN ile override edilebilir (varsayılan 90 gün). */
function aktivite_temizle(PDO $pdo): void
{
    if (mt_rand(1, 400) !== 1) return; // ~%0.25 istek bakım yapar
    $gun = defined('AKTIVITE_SAKLAMA_GUN') ? max(7, (int)AKTIVITE_SAKLAMA_GUN) : 90;
    try {
        $pdo->prepare("DELETE FROM kullanici_aktivite WHERE created_at < (NOW() - INTERVAL ? DAY)")->execute([$gun]);
        $pdo->prepare("DELETE FROM kullanici_oturum   WHERE son_aktivite < (NOW() - INTERVAL ? DAY)")->execute([$gun]);
    } catch (Throwable $e) { /* sessiz */ }
}

/**
 * Her sayfa render'ında çağrılır (header.php). Giriş yapan kullanıcının oturum
 * süresini ve sayfa gezinmesini kaydeder. Tablo yoksa oluşturup bir kez daha dener.
 * $pdo verilmezse ana (beton) DB'ye kendi (önbellekli) bağlantısını açar.
 */
function aktivite_izle(?PDO $pdo, string $modul = ''): void
{
    if (empty($_SESSION['user']) || !defined('DB_HOST')) return;
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo = aktivite_pdo($pdo);
        if (!$pdo) return;
        aktivite_yaz($pdo, $modul);
        aktivite_temizle($pdo);
    } catch (Throwable $e) {
        // Tablo yoksa oluştur ve bir kez daha dene
        try { $pdo = aktivite_pdo($pdo); if ($pdo) { aktivite_semasi_kur($pdo); aktivite_yaz($pdo, $modul); } }
        catch (Throwable $e2) { error_log('aktivite_izle: ' . $e2->getMessage()); }
    }
}

/* ── CSRF koruması (tüm POST formları için tek ortak token) ─────────────────── */

/** Oturuma bağlı CSRF token'ı döner (yoksa üretir) */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}

/** Gizli CSRF input alanı (elle eklemek isterseniz) */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Gönderilen token doğru mu? */
function csrf_ok(): bool
{
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
}

/** Çıktı tamponu callback'i: her <form ...method=post...> etiketinden hemen sonra
 *  gizli csrf alanını otomatik enjekte eder — formları tek tek düzenlemeye gerek yok. */
function csrf_ob_inject(string $html): string
{
    if (stripos($html, '<form') === false) return $html;
    $field = csrf_field();
    return preg_replace_callback('/<form\b[^>]*>/i', function ($m) use ($field) {
        return preg_match('/\bmethod\s*=\s*["\']?\s*post/i', $m[0]) ? $m[0] . $field : $m[0];
    }, $html);
}

/** CSRF kontrolünden muaf yollar: AJAX/JSON API (SameSite=Lax + require_auth korur). */
function csrf_muaf(): bool
{
    $self = (string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
    return strpos($self, '/api/') !== false;
}

/**
 * Aktif kullanıcı ID'sini döner (oturumdan)
 */
function current_user_id(): ?int
{
    return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
}
