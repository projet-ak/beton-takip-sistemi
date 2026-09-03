<?php
/**
 * auth.php — Kimlik doğrulama ve yetkilendirme
 * session_start() bu dosyada yapılır.
 */
/*
 * ── Oturum süresi (idle timeout) ─────────────────────────────────────────────
 * Varsayılan oturum ömrü buradan ayarlanır. Sunucunun (cPanel) PHP varsayılanı
 * olan session.gc_maxlifetime (genelde 24 dk) yerine bu değer kullanılır; böylece
 * uzun taramalarda (örn. 30 sayfalık PDF) oturum düşmez.
 *
 * Süreyi değiştirmek için: aşağıdaki SESSION_LIFETIME değerini düzenleyin
 * (saniye cinsinden) — ya da config.php içinde define('SESSION_LIFETIME', ...).
 *   1 saat = 3600 | 2 saat = 7200 | 4 saat = 14400 | 8 saat = 28800
 *
 * Bu, "boşta kalma" süresidir: her sayfa açılışı veya AJAX isteği (tarama,
 * kaydet vb.) sayacı sıfırlar. Hareket olmadan SESSION_LIFETIME kadar geçerse
 * oturum sonlandırılır ve kullanıcı login.php'ye yönlendirilir.
 */
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 3600); // saniye — varsayılan 1 saat
}

if (session_status() === PHP_SESSION_NONE) {
    // Oturum ömrünü sunucu varsayılanından bağımsız hale getir.
    // gc_maxlifetime'ı bir miktar tamponla büyük tutuyoruz ki çöp toplayıcı
    // bizim kendi idle kontrolümüzden önce oturumu silmesin.
    ini_set('session.gc_maxlifetime', (string)(SESSION_LIFETIME + 300));

    $__cp = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0, // tarayıcı kapanınca çerez silinsin (oturum çerezi)
        'path'     => $__cp['path'],
        'domain'   => $__cp['domain'],
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// ── Sayfa önbelleğini engelle (LiteSpeed/proxy/tarayıcı) ─────────────────────
// Kimlik doğrulamalı sayfalar canlı DB verisi gösterir; asla önbelleğe alınmamalı.
// Aksi halde dashboard gibi sık ziyaret edilen sayfalar, veri güncellendikten
// sonra bile önbellekteki ESKİ HTML kopyasıyla sunulur (ör. dashboard 5.247,9
// gösterirken DB 5.253,00 olması). LiteSpeed için özel başlık da gönderilir.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-LiteSpeed-Cache-Control: no-cache'); // LiteSpeed sunucu önbelleği
}

// ── Boşta kalma zaman aşımı kontrolü ─────────────────────────────────────────
if (!empty($_SESSION['user'])) {
    $__now  = time();
    $__last = $_SESSION['last_activity'] ?? $__now;

    if ($__now - $__last > SESSION_LIFETIME) {
        // Süre doldu: oturumu tamamen temizle
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', $__now - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        session_start(); // flash mesajı yazabilmek için yeni boş oturum
        if (function_exists('set_flash')) {
            set_flash('warning', 'Oturum süreniz doldu, lütfen tekrar giriş yapın.');
        }
    } else {
        // Hareket var: sayacı yenile
        $_SESSION['last_activity'] = $__now;
        if (empty($_SESSION['login_time'])) {
            $_SESSION['login_time'] = $__now;
        }
    }
}

// ── CSRF doğrulama: giriş yapmış kullanıcının POST istekleri ──────────────────
// Token, header.php'de çıktı tamponu ile tüm POST formlarına otomatik eklenir.
// AJAX/JSON API yolları muaftır (SameSite=Lax + require_auth korur). Giriş
// (login.php) henüz oturum olmadığından bu kontrole takılmaz.
if (!function_exists('csrf_ok')) { @require_once __DIR__ . '/functions.php'; }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && !empty($_SESSION['user'])
    && function_exists('csrf_ok') && function_exists('csrf_muaf')
    && !csrf_muaf() && !csrf_ok()) {
    http_response_code(419);
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Güvenlik</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:60px auto;padding:24px;'
       . 'border:1px solid #f5c2c7;background:#f8d7da;color:#842029;border-radius:10px">'
       . '<h3 style="margin:0 0 8px">Oturum güvenlik doğrulaması başarısız</h3>'
       . '<p>Güvenlik anahtarı (CSRF) eşleşmedi. Sayfa uzun süre açık kaldıysa oturumunuz '
       . 'yenilenmiş olabilir. Lütfen geri dönüp sayfayı yenileyin ve işlemi tekrar deneyin.</p>'
       . '<p><a href="javascript:history.back()">← Geri dön</a></p></div>';
    exit;
}

/**
 * Sistemdeki modüller: anahtar => [ad, ikon, giriş sayfası (kökten göreli)].
 * Kullanıcı bazlı erişim (`users.modul_erisim`) ve topbar modül şeridi bunu kullanır.
 */
const MODULLER = [
    'beton'     => ['Beton Takip',              'bi-buildings',  'index.php'],
    'demir'     => ['Demir Takip',              'bi-rulers',     'demir/index.php'],
    'seramik'   => ['Seramik Takip',            'bi-grid-1x2',   'seramik/index.php'],
    'depo'      => ['Depo Takip',               'bi-box-seam',   'depo/index.php'],
    'akaryakit' => ['Akaryakıt Takip',          'bi-fuel-pump',  'akaryakit/index.php'],
    'crm'       => ['CRM — Üretim Arızaları',   'bi-headset',    'crm/index.php'],
    'whatsapp'  => ['Saha Takip',               'bi-chat-dots',  'whatsapp/mesajlar.php'],
];

/** Modül erişim denetiminden MUAF kök sayfalar (giriş/çıkış, kurulum, yönetim, tanıtım). */
const MODUL_MUAF = [
    'login.php','logout.php','install.php','tanitim.php','deploy.php','deploy2.php','migrate.php',
    'migrate_scan_url.php','onbellek_temizle.php','sistem_kontrol.php','kurulum.php',
    'kullanicilar.php','ai_ayarlar.php','yedek.php','aktivite.php','veri_kontrol.php',
];

/** İstenen sayfanın hangi modüle ait olduğu (PHP_SELF klasöründen). */
function aktif_modul(): string
{
    $self = $_SERVER['PHP_SELF'] ?? '';
    foreach (array_keys(MODULLER) as $m) {
        if ($m !== 'beton' && strpos($self, '/' . $m . '/') !== false) return $m;
    }
    return 'beton';
}

/**
 * `users.modul_erisim` kolonunu garanti eder (runtime migration).
 * Boş/NULL = SINIRSIZ (eski kullanıcılar etkilenmez); "beton,depo" gibi virgüllü liste = yalnız onlar.
 */
function modul_erisim_semasi(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    try {
        $var = false;
        foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c)
            if ($c['Field'] === 'modul_erisim') { $var = true; break; }
        if (!$var) $pdo->exec("ALTER TABLE users ADD COLUMN modul_erisim VARCHAR(255) NULL
                               COMMENT 'izinli modüller (virgüllü); boş = tümü'");
    } catch (Throwable $e) { /* yetki yoksa modül sınırsız çalışmaya devam eder */ }
}

/**
 * Oturumdaki kullanıcının izinli modülleri. null = sınırsız (admin ya da tanımsız).
 * Değer her istekte DB'den okunur (static önbellekli) — admin erişimi değiştirdiğinde
 * kullanıcının yeniden giriş yapmasını beklemeye gerek kalmasın.
 */
function modul_erisimi(): ?array
{
    static $izin = false;                     // false = henüz okunmadı
    if ($izin !== false) return $izin;
    $izin = null;
    $u = $_SESSION['user'] ?? null;
    if (!$u) return $izin;
    if (($u['role'] ?? '') === 'admin') return $izin;   // admin her modülü görür

    // require_auth() config.php yüklenmeden de çalışabiliyor; DB sabitleri yoksa önce onu al
    if (!defined('DB_HOST') && file_exists(__DIR__ . '/../config.php')) require_once __DIR__ . '/../config.php';

    $ham = null;
    if (function_exists('aktivite_pdo') && ($pdo = aktivite_pdo(null))) {
        try {
            $st = $pdo->prepare("SELECT modul_erisim FROM users WHERE id=?");
            $st->execute([(int)($u['id'] ?? 0)]);
            $ham = $st->fetchColumn();
            $_SESSION['user']['modul_erisim'] = $ham;    // DB'ye ulaşılamazsa yedek
        } catch (Throwable $e) { $ham = $u['modul_erisim'] ?? null; }
    } else {
        $ham = $u['modul_erisim'] ?? null;
    }
    $liste = array_values(array_filter(array_map('trim', explode(',', (string)$ham))));
    $liste = array_values(array_intersect($liste, array_keys(MODULLER)));
    $izin  = $liste ?: null;                  // boşsa sınırsız
    return $izin;
}

/** Kullanıcı bu modüle girebilir mi? */
function can_module(string $mod): bool
{
    $izin = modul_erisimi();
    return $izin === null || in_array($mod, $izin, true);
}

/** Kullanıcının açabileceği ilk modülün giriş sayfası (kökten göreli). */
function ilk_modul_sayfasi(): string
{
    $izin = modul_erisimi();
    if ($izin === null) return 'index.php';
    foreach (MODULLER as $k => [$ad, $ikon, $sayfa]) {
        if (!in_array($k, $izin, true)) continue;
        // Saha Takip'te onay kuyruğu yetkisi yoksa analiz sayfası açılır (mesajlar.php 403 verirdi)
        if ($k === 'whatsapp' && function_exists('can_edit') && !can_edit()) return 'whatsapp/saha_analiz.php';
        return $sayfa;
    }
    return 'index.php';
}

/**
 * Oturum yoksa login.php'ye, rol uyumsuzsa 403'e yönlendir.
 * Ayrıca **kullanıcı bazlı modül erişimi** denetlenir (users.modul_erisim).
 *
 * @param array $roller İzin verilen roller (boş = herkese açık, giriş şart)
 */
function require_auth(array $roller = []): void
{
    if (empty($_SESSION['user'])) {
        $current  = $_SERVER['REQUEST_URI'] ?? '';
        $base     = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/';
        // Alt klasörlerden (ör. demir/) kök dizindeki login.php'ye ulaş: $rootPath ('../')
        $root     = $base . ($GLOBALS['rootPath'] ?? '');
        header('Location: ' . $root . 'login.php?redirect=' . urlencode($current));
        exit;
    }
    if (!empty($roller) && !in_array($_SESSION['user']['role'], $roller, true)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }

    // ── Modül erişimi (kullanıcı bazlı) ──────────────────────────────────────
    $sayfa = basename($_SERVER['PHP_SELF'] ?? '');
    $mod   = aktif_modul();
    if (in_array($sayfa, MODUL_MUAF, true) || strpos($_SERVER['PHP_SELF'] ?? '', '/api/') !== false) return;
    if (can_module($mod)) return;

    $kok = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/' . ($GLOBALS['rootPath'] ?? '');
    // Ana sayfaya düşen kullanıcı 403 duvarına toslamasın: izinli ilk modüle götür
    if ($mod === 'beton' && $sayfa === 'index.php') { header('Location: ' . $kok . ilk_modul_sayfasi()); exit; }

    $GLOBALS['__403_mesaj'] = (MODULLER[$mod][0] ?? $mod) . ' modülüne erişim yetkiniz yok.';
    $GLOBALS['__403_kok']   = $kok;
    http_response_code(403);
    include __DIR__ . '/403.php';
    exit;
}

/**
 * Oturumdaki kullanıcı dizisini döner; yoksa null.
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Belirtilen rollerden herhangi biriyse true döner.
 */
function has_role(string ...$roller): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    return in_array($user['role'], $roller, true);
}

// ── Yardımcı kısa fonksiyonlar ───────────────────────────────────────────────

/** Sadece admin */
function is_admin(): bool
{
    return has_role('admin');
}

/**
 * Yeni irsaliye oluşturabilenler:
 * admin, teknik_ofis_admin, saha_sefi, depo
 */
function can_create_irsaliye(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'saha_sefi', 'depo');
}

/**
 * İrsaliye düzenleyebilecekler (genel can_edit):
 * admin, teknik_ofis_admin → her zaman
 * teknik_ofis, saha_sefi  → sadece belirli durumlarda (durum bazlı kontrol için can_edit_irsaliye() kullanın)
 */
function can_edit(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'teknik_ofis', 'saha_sefi');
}

/**
 * Belirli bir irsaliyeyi düzenleyip düzenleyemeyeceği:
 * - admin / teknik_ofis_admin: her zaman
 * - teknik_ofis: saha_onaylandi veya beklemede ise
 * - saha_sefi: beklemede ise
 * - depo: hiçbir zaman (sadece oluşturur)
 */
function can_edit_irsaliye(array $irsaliye): bool
{
    if (has_role('admin', 'teknik_ofis_admin')) return true;
    if (has_role('teknik_ofis')) return in_array($irsaliye['durum'] ?? 'beklemede', ['beklemede','saha_onaylandi']);
    if (has_role('saha_sefi'))   return ($irsaliye['durum'] ?? 'beklemede') === 'beklemede';
    return false;
}

/**
 * Saha onayı verebilecekler (1. aşama):
 * admin, teknik_ofis_admin, saha_sefi
 */
function can_approve_saha(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'saha_sefi');
}

/**
 * Teknik ofis onayı verebilecekler (2. aşama / final):
 * admin, teknik_ofis_admin, teknik_ofis
 */
function can_approve_teknik(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'teknik_ofis');
}

/**
 * Raporları görüntüleyebilecek roller:
 * admin, teknik_ofis_admin, teknik_ofis
 */
function can_view_reports(): bool
{
    return has_role('admin', 'teknik_ofis_admin', 'teknik_ofis');
}

/**
 * Referans tanım yönetimi (beton sınıfı, blok, parsel vb.):
 * admin, teknik_ofis_admin
 */
function can_manage_definitions(): bool
{
    return has_role('admin', 'teknik_ofis_admin');
}

/**
 * Kullanıcı yönetimi: sadece admin
 */
function can_manage_users(): bool
{
    return has_role('admin');
}

/**
 * Durum rozet HTML'i döner
 */
function durum_badge(string $durum): string
{
    $map = [
        'beklemede'      => ['warning', 'clock',              'Beklemede'],
        'saha_onaylandi' => ['info',    'check-circle',       'Saha Onayı'],
        'onaylandi'      => ['success', 'check-circle-fill',  'Onaylandı'],
        'reddedildi'     => ['danger',  'x-circle-fill',      'Reddedildi'],
    ];
    $d = $map[$durum] ?? ['secondary', 'question-circle', $durum];
    return '<span class="badge bg-' . $d[0] . '"><i class="bi bi-' . $d[1] . ' me-1"></i>' . htmlspecialchars($d[2]) . '</span>';
}
