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
    'prekast'   => ['Prekast Takip',            'bi-bricks',     'prekast/index.php'],
    'whatsapp'  => ['Saha Takip',               'bi-chat-dots',  'whatsapp/mesajlar.php'],
    'it'        => ['IT Envanter',              'bi-pc-display', 'it/index.php'],
];

/** Modül erişim denetiminden MUAF kök sayfalar (giriş/çıkış, kurulum, yönetim, tanıtım). */
const MODUL_MUAF = [
    'login.php','logout.php','install.php','tanitim.php','deploy.php','deploy2.php','migrate.php',
    'migrate_scan_url.php','onbellek_temizle.php','sistem_kontrol.php','kurulum.php',
    'kullanicilar.php','ai_ayarlar.php','yedek.php','aktivite.php','veri_kontrol.php','moduller.php',
];

/**
 * ── GELİŞMİŞ KULLANICI YETKİLERİ (2026-09) ─────────────────────────────────────
 * Rol artık bir ETİKET + ŞABLONDUR; gerçek yetki kullanıcı bazlı **modül × işlem**
 * matrisidir (`users.yetkiler`, JSON: {"beton":["oku","giris"],"crm":["oku","rapor"]}).
 *   oku      → modülü açma, liste/detay görüntüleme
 *   giris    → yeni kayıt / içe aktarma / tarama (veri girişi)
 *   duzenle  → mevcut kaydı değiştirme, silme, tanımlar (veri değiştirme)
 *   onay     → saha / teknik ofis onayı, toplu onay
 *   rapor    → raporlar, icmal/zayiat ekranları, Excel/PDF dışa aktarma
 * Matris NULL olan (eski) kullanıcılar için ROL BAZLI eski davranış aynen sürer.
 * Matrisi olan kullanıcıda `has_role()` ve tüm `can_*()` fonksiyonları matrise bakar;
 * admin her zaman sınırsızdır (matris kaydedilse de dikkate alınmaz).
 */
const ROLLER = [
    'admin'             => ['Yönetici',                 'danger',              'Sistem yöneticisi — her şeye yetkili, kullanıcıları yönetir'],
    'teknik_ofis_admin' => ['Teknik Ofis Yöneticisi',   'warning text-dark',   'Tüm modüllerde tam yetki (tanımlar, içe aktarma, onay)'],
    'teknik_ofis'       => ['Teknik Ofis',              'info text-dark',      'Veri girişi, düzenleme, teknik onay ve raporlar'],
    'saha_sefi'         => ['Saha Şefi',                'primary',             'Sahadan veri girişi ve saha onayı'],
    'depo'              => ['Depo',                     'secondary',           'Depo / seramik / akaryakıt giriş-çıkış, beton irsaliyesi açma'],
    'kalite'            => ['Kalite Birimi',            'success',             'Üretim arızaları takibi (CRM) + raporlar; diğer modüllerde görüntüleme'],
    'proje_muduru'      => ['Proje Müdürü',             'dark',                'Tüm modülleri görür, raporları alır, onay verir; veri girmez'],
    'direktor'          => ['Direktör / Üst Yönetim',   'dark',                'Tüm modüllerde görüntüleme + rapor (veri değiştirmez)'],
    'izleyici'          => ['Görüntüleyici',            'light text-dark border', 'Yalnız okuma — hiçbir veri değiştiremez, rapor alamaz'],
    'it_sorumlusu'      => ['IT Sorumlusu',             'primary',             'IT Envanter\'de tam yetki (cihaz, zimmet, servis, rapor); diğer modüllerde görüntüleme'],
];

/** İşlem türleri: anahtar => [ad, ikon, açıklama]. Sıra matris ekranındaki sütun sırasıdır. */
const YETKI_ISLEMLER = [
    'oku'     => ['Okuma',          'bi-eye',            'Modülü açar, listeleri ve detayları görür'],
    'giris'   => ['Veri Girişi',    'bi-plus-circle',    'Yeni kayıt açar, Excel/tarama ile veri aktarır'],
    'duzenle' => ['Değiştirme',     'bi-pencil-square',  'Mevcut kayıtları düzenler/siler, tanımları yönetir'],
    'onay'    => ['Onay',           'bi-check2-circle',  'Saha / teknik ofis onayı verir (toplu onay dahil)'],
    'rapor'   => ['Rapor',          'bi-bar-chart-line', 'Raporlar, icmal/zayiat ekranları, Excel/PDF dışa aktarma'],
];

/**
 * Rol şablonu: rol seçildiğinde matrisin varsayılan doluşu (yönetici sonra tek tek değiştirir).
 * Eski roller eski davranışa yakın; yeni roller (kalite / proje müdürü / direktör / izleyici) okuma+rapor ağırlıklı.
 */
function yetki_sablon(string $rol): array
{
    $tum   = array_keys(MODULLER);
    $hepsi = array_keys(YETKI_ISLEMLER);
    $doldur = function (array $modKume, array $islem) { $o = []; foreach ($modKume as $m) $o[$m] = $islem; return $o; };
    switch ($rol) {
        case 'admin':
        case 'teknik_ofis_admin':
        case 'teknik_ofis':
            return $doldur($tum, $hepsi);
        case 'saha_sefi':
            return $doldur(['beton','demir','depo','crm','prekast','whatsapp'], ['oku','giris','onay']);
        case 'it_sorumlusu':
            return ['it' => $hepsi] + $doldur(['beton','depo'], ['oku']);
        case 'depo':
            return ['beton' => ['oku','giris']]
                 + $doldur(['seramik','depo','akaryakit'], ['oku','giris','duzenle','rapor'])
                 + ['it' => ['oku','giris']];
        case 'kalite':
            return ['crm' => ['oku','giris','duzenle','rapor']]
                 + $doldur(['beton','demir','seramik','prekast'], ['oku','rapor']);
        case 'proje_muduru':
            return $doldur($tum, ['oku','onay','rapor']);
        case 'direktor':
            return $doldur($tum, ['oku','rapor']);
        case 'izleyici':
        default:
            return $doldur($tum, ['oku']);
    }
}

/**
 * Ham matrisi (JSON/dizi) temizler: yalnız bilinen modül/işlem kalır, oku dışındaki
 * her işlem `oku`yu da getirir (okuyamadığı modülde giriş yapamaz). Boş modül düşer.
 */
function yetki_normalize($ham): array
{
    if (is_string($ham)) { $ham = json_decode($ham, true); }
    if (!is_array($ham)) return [];
    $out = [];
    foreach ($ham as $mod => $islemler) {
        if (!isset(MODULLER[$mod])) continue;
        if (is_string($islemler)) $islemler = explode(',', $islemler);
        $set = array_values(array_intersect(array_keys(YETKI_ISLEMLER), array_map('trim', (array)$islemler)));
        if (!$set) continue;
        if (!in_array('oku', $set, true)) array_unshift($set, 'oku');
        $out[$mod] = $set;
    }
    return $out;
}

/**
 * `users.yetkiler` (JSON matris) + `users.unvan` kolonlarını garanti eder; `role` kolonu
 * yeni roller için ENUM'dan VARCHAR'a genişletilir (mevcut değerler korunur).
 */
function yetki_semasi(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    try {
        $kolon = [];
        foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) $kolon[$c['Field']] = $c['Type'];
        if (!isset($kolon['yetkiler'])) $pdo->exec("ALTER TABLE users ADD COLUMN yetkiler TEXT NULL
                                                   COMMENT 'modül × işlem yetki matrisi (JSON); NULL = rol bazlı eski davranış'");
        if (!isset($kolon['unvan']))    $pdo->exec("ALTER TABLE users ADD COLUMN unvan VARCHAR(80) NULL COMMENT 'görev / unvan (serbest metin)'");
        if (isset($kolon['role']) && stripos($kolon['role'], 'enum') === 0)
            $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(40) NOT NULL DEFAULT 'teknik_ofis'");
    } catch (Throwable $e) { /* yetki yoksa rol bazlı çalışmaya devam eder */ }
}

/**
 * Oturumdaki kullanıcının DB satırındaki yetki alanları (modul_erisim + yetkiler).
 * Her istekte bir kez okunur — admin değişikliği anında geçerli olur, yeniden giriş gerekmez.
 * DB'ye ulaşılamazsa oturumdaki kopya kullanılır.
 */
function kullanici_yetki_satiri(): array
{
    static $satir = null;
    if ($satir !== null) return $satir;
    $u = $_SESSION['user'] ?? [];
    $satir = ['modul_erisim' => $u['modul_erisim'] ?? null, 'yetkiler' => $u['yetkiler'] ?? null];
    if (!$u) return $satir;
    if (!defined('DB_HOST') && file_exists(__DIR__ . '/../config.php')) require_once __DIR__ . '/../config.php';
    if (function_exists('aktivite_pdo') && ($pdo = aktivite_pdo(null))) {
        try {
            $st = $pdo->prepare("SELECT modul_erisim, yetkiler FROM users WHERE id=?");
            $st->execute([(int)($u['id'] ?? 0)]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $satir = ['modul_erisim' => $r['modul_erisim'], 'yetkiler' => $r['yetkiler']];
                $_SESSION['user']['modul_erisim'] = $r['modul_erisim'];
                $_SESSION['user']['yetkiler']     = $r['yetkiler'];
            }
        } catch (Throwable $e) { /* kolon henüz yoksa oturumdaki kopya */ }
    }
    return $satir;
}

/**
 * Kullanıcının yetki matrisi. null = matris tanımlı değil (admin ya da eski kullanıcı → rol bazlı).
 */
function yetki_matris(): ?array
{
    static $m = false;
    if ($m !== false) return $m;
    $m = null;
    $u = $_SESSION['user'] ?? null;
    if (!$u || ($u['role'] ?? '') === 'admin') return $m;
    $ham = kullanici_yetki_satiri()['yetkiler'];
    if ($ham === null || $ham === '') return $m;
    $m = yetki_normalize($ham);
    return $m;
}

/**
 * Kullanıcı bu modülde bu işlemi yapabilir mi? (admin → her zaman evet.)
 * Matrisi olmayan (eski) kullanıcıda rol bazlı eşdeğer kural uygulanır — eski davranış korunur.
 */
function yetki_var(string $islem, ?string $mod = null): bool
{
    $u = $_SESSION['user'] ?? null;
    if (!$u) return false;
    if (($u['role'] ?? '') === 'admin') return true;
    $mod = $mod ?: aktif_modul();
    $m = yetki_matris();
    if ($m !== null) return in_array($islem, $m[$mod] ?? [], true);
    // Rol bazlı eski eşdeğerler; klasik olmayan roller (kalite, proje_muduru, it_sorumlusu…) matrissiz de olsa şablonuyla çalışır
    $rol = $u['role'] ?? '';
    if (!in_array($rol, ['teknik_ofis_admin','teknik_ofis','saha_sefi','depo'], true))
        return in_array($islem, yetki_sablon($rol)[$mod] ?? [], true);
    switch ($islem) {
        case 'oku':     return true;
        case 'giris':   return in_array($rol, ['teknik_ofis_admin','teknik_ofis','saha_sefi','depo'], true);
        case 'duzenle': return in_array($rol, ['teknik_ofis_admin','teknik_ofis','saha_sefi'], true);
        case 'onay':    return in_array($rol, ['teknik_ofis_admin','teknik_ofis','saha_sefi'], true);
        case 'rapor':   return in_array($rol, ['teknik_ofis_admin','teknik_ofis'], true);
    }
    return false;
}

/** Yazma yetkisi (giriş VEYA değiştirme VEYA onay) — POST istekleri için asgari kapı. */
function yetki_yazma(?string $mod = null): bool
{
    return yetki_var('giris', $mod) || yetki_var('duzenle', $mod) || yetki_var('onay', $mod);
}

/**
 * Bu istek hangi işlemi gerektirir? (matrisli kullanıcılar için sayfa bazlı kapı)
 *   rapor   → raporlar/icmal/zayiat/mutabakat… sayfaları, ?export= / ?indir= dışa aktarma
 *   giris   → import*, *_form (id'siz), toplu giriş, tarama, belge dağıt, fatura eşleştirme
 *   duzenle → *_form?id= / ?edit= (mevcut kaydı açma)
 *   yaz     → diğer sayfalarda POST (giriş VEYA değiştirme VEYA onay yeter; ayrıntı can_*() ile)
 *   oku     → geri kalan her GET
 */
function sayfa_islemi(): string
{
    $s    = basename($_SERVER['PHP_SELF'] ?? '');
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $raporSayfa = ['raporlar.php','icmal.php','icmal_beton.php','icmal_pdf.php','zayiat.php','zayiat_takip.php',
                   'mutabakat.php','prp_ustyapi.php','istinat.php','temel_kazik.php','metraj_sayfasi.php',
                   'mobilizasyon.php','taseron_bakiye.php','arac_takip.php','saha_analiz.php','ai_rapor.php'];
    if (!$post && (in_array($s, $raporSayfa, true) || isset($_GET['export']) || isset($_GET['indir']))) return 'rapor';
    $girisSayfa = preg_match('/^(import\d*|cihaz_import|toplu_irsaliye|hizli_tarama|belge_dagit|fatura_eslestir|faturalar|hizli_kaydet|hizli_guncelle|ai_okut|demir_okut|demir_scan_kaydet|demir_pdf_kaydet|pdf_kaydet|foto_yukle)\.php$/', $s)
               || str_ends_with($s, '_form.php');
    $kayitAcik = !empty($_GET['edit']) || (str_ends_with($s, '_form.php') && (!empty($_GET['id']) || (int)($_POST['id'] ?? 0) > 0));
    if ($kayitAcik) return 'duzenle';
    if ($girisSayfa) return 'giris';
    if ($post) return in_array($s, $raporSayfa, true) ? 'rapor' : 'yaz';
    return 'oku';
}
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
 * `modul_ayarlar` tablosunu garanti eder (runtime migration; kurulum.php da kurar).
 * Modül ADI ve GİZLİLİĞİ yönetici tarafından değiştirilebilsin diye MODULLER sabiti
 * varsayılan kalır, bu tablo yalnız **üzerine yazar** — tablo yoksa/erişilemezse
 * sistem varsayılanlarla sorunsuz çalışır.
 */
function modul_ayar_semasi(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS modul_ayarlar (
            anahtar VARCHAR(32) NOT NULL PRIMARY KEY COMMENT 'MODULLER anahtarı',
            ad      VARCHAR(60) NULL   COMMENT 'yöneticinin verdiği ad; boş = varsayılan',
            gizli   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = menülerde hiç görünmez',
            sira    INT NOT NULL DEFAULT 0 COMMENT 'menü sırası (küçük önce)',
            updated TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* yetki yoksa varsayılanlarla devam */ }
}

/**
 * Yönetici modül ayarları: anahtar => [ad, gizli, sira]. İstek başına bir kez okunur.
 * Tablo yoksa boş döner (varsayılanlar geçerli olur).
 */
function modul_ayarlari(): array
{
    static $ayar = null;
    if ($ayar !== null) return $ayar;
    $ayar = [];
    if (!defined('DB_HOST') && file_exists(__DIR__ . '/../config.php')) require_once __DIR__ . '/../config.php';
    if (!function_exists('aktivite_pdo') || !($pdo = aktivite_pdo(null))) return $ayar;
    try {
        foreach ($pdo->query("SELECT anahtar, ad, gizli, sira FROM modul_ayarlar")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $ayar[$r['anahtar']] = ['ad' => (string)($r['ad'] ?? ''), 'gizli' => (int)$r['gizli'], 'sira' => (int)$r['sira']];
    } catch (Throwable $e) { /* tablo henüz yok */ }
    return $ayar;
}

/**
 * MODULLER + yönetici ayarları birleşimi: anahtar => [ad, ikon, sayfa, gizli, sira].
 * Sıralama `sira` (0 = MODULLER'deki doğal sıra), sonra doğal sıra.
 *
 * @param bool $gizliDahil true ise gizlenen modüller de döner (yönetim ekranları için)
 */
function modul_listesi(bool $gizliDahil = false): array
{
    $ayar = modul_ayarlari();
    $liste = []; $i = 0;
    foreach (MODULLER as $k => [$ad, $ikon, $sayfa]) {
        $i++;
        $a = $ayar[$k] ?? ['ad' => '', 'gizli' => 0, 'sira' => 0];
        if (!$gizliDahil && $a['gizli']) continue;
        $liste[$k] = ['ad' => $a['ad'] !== '' ? $a['ad'] : $ad, 'ikon' => $ikon, 'sayfa' => $sayfa,
                      'gizli' => (bool)$a['gizli'], 'sira' => $a['sira'] ?: $i, 'dogal' => $i, 'varsayilan_ad' => $ad];
    }
    uasort($liste, fn($x, $y) => [$x['sira'], $x['dogal']] <=> [$y['sira'], $y['dogal']]);
    return $liste;
}

/** Modülün görünen adı (yönetici verdiyse o, yoksa varsayılan). */
function modul_ad(string $k): string
{
    $a = modul_ayarlari()[$k]['ad'] ?? '';
    return $a !== '' ? $a : (MODULLER[$k][0] ?? $k);
}

/** Modül yönetici tarafından gizlendi mi? */
function modul_gizli(string $k): bool
{
    return (bool)(modul_ayarlari()[$k]['gizli'] ?? 0);
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

    // Yetki matrisi tanımlıysa modül listesi = matriste 'oku' olan modüller (tek doğru kaynak)
    $m = yetki_matris();
    if ($m !== null) { $izin = array_keys($m); return $izin; }

    $ham   = kullanici_yetki_satiri()['modul_erisim'];
    $liste = array_values(array_filter(array_map('trim', explode(',', (string)$ham))));
    $liste = array_values(array_intersect($liste, array_keys(MODULLER)));
    $izin  = $liste ?: null;                  // boşsa sınırsız
    return $izin;
}

/**
 * Kullanıcı bu modüle girebilir mi?
 * İki kapı var: (1) yöneticinin **gizlediği** modül kimsede görünmez/açılmaz — admin
 * hariç, yoksa gizlenen modülü geri açacak kimse kalmaz; (2) kullanıcı bazlı izin listesi.
 */
function can_module(string $mod): bool
{
    if (modul_gizli($mod) && ($_SESSION['user']['role'] ?? '') !== 'admin') return false;
    $izin = modul_erisimi();
    return $izin === null || in_array($mod, $izin, true);
}

/** Kullanıcının açabileceği ilk modülün giriş sayfası (kökten göreli). */
function ilk_modul_sayfasi(): string
{
    $izin = modul_erisimi();
    // Gizlenmiş modüle yönlendirme yapılmaz; beton gizliyse de ilk görünür modüle düşülür
    $gorunur = modul_listesi();
    if ($izin === null) return isset($gorunur['beton']) ? 'index.php' : (reset($gorunur)['sayfa'] ?? 'index.php');
    foreach ($gorunur as $k => $m) {
        if (!in_array($k, $izin, true)) continue;
        // Saha Takip'te onay kuyruğu yetkisi yoksa analiz sayfası açılır (mesajlar.php 403 verirdi)
        if ($k === 'whatsapp' && !(yetki_matris() !== null ? yetki_yazma('whatsapp') : (function_exists('can_edit') && can_edit()))) return 'whatsapp/saha_analiz.php';
        return $m['sayfa'];
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
    $sayfa  = basename($_SERVER['PHP_SELF'] ?? '');
    $api    = strpos($_SERVER['PHP_SELF'] ?? '', '/api/') !== false;
    $mod    = aktif_modul();
    if ($api && str_starts_with($sayfa, 'demir_')) $mod = 'demir';   // kök api/demir_*.php demir modülünündür
    $kok    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/' . ($GLOBALS['rootPath'] ?? '');
    $matris = yetki_matris();
    $rol    = $_SESSION['user']['role'] ?? '';

    // Yalnız admin'e açık sayfalar ve kurulum sayfaları her zaman rol bazlıdır (matris burayı açamaz)
    $adminSayfa = ($roller === ['admin']) || str_starts_with($sayfa, 'kurulum');
    if ($matris === null || $adminSayfa || $rol === 'admin') {
        if (!empty($roller) && !in_array($rol, $roller, true)) {
            http_response_code(403);
            include __DIR__ . '/403.php';
            exit;
        }
    }

    // ── Modül erişimi (kullanıcı bazlı) ──────────────────────────────────────
    if (in_array($sayfa, MODUL_MUAF, true)) return;
    if ($api || can_module($mod)) {
        // ── İşlem yetkisi (matrisli kullanıcı): sayfanın gerektirdiği işlem matriste var mı? ──
        if ($matris !== null && $rol !== 'admin' && !$adminSayfa) {
            $islem = sayfa_islemi();
            $ok = $islem === 'yaz' ? yetki_yazma($mod) : yetki_var($islem, $mod);
            if (!$ok) {
                $adlar = ['oku' => 'okuma', 'giris' => 'veri girişi', 'duzenle' => 'değiştirme', 'onay' => 'onay',
                          'rapor' => 'rapor', 'yaz' => 'veri girişi / değiştirme'];
                if ($api || (($_SERVER['HTTP_ACCEPT'] ?? '') && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json') && !str_contains($_SERVER['HTTP_ACCEPT'], 'text/html'))) {
                    http_response_code(403);
                    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'error' => 'Bu işlem için yetkiniz yok (' . ($adlar[$islem] ?? $islem) . ').'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $GLOBALS['__403_mesaj'] = modul_ad($mod) . ' modülünde "' . ($adlar[$islem] ?? $islem) . '" yetkiniz yok.';
                $GLOBALS['__403_kok']   = $kok;
                http_response_code(403);
                include __DIR__ . '/403.php';
                exit;
            }
        }
        return;
    }

    // Ana sayfaya düşen kullanıcı 403 duvarına toslamasın: izinli ilk modüle götür
    if ($mod === 'beton' && $sayfa === 'index.php') { header('Location: ' . $kok . ilk_modul_sayfasi()); exit; }

    $GLOBALS['__403_mesaj'] = modul_gizli($mod)
        ? modul_ad($mod) . ' modülü yönetici tarafından kapatılmış.'
        : modul_ad($mod) . ' modülüne erişim yetkiniz yok.';
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
    $rol = $user['role'];
    if ($rol === 'admin') return in_array('admin', $roller, true);
    $m = yetki_matris();
    if ($m === null) return in_array($rol, $roller, true);

    // ── Matrisli kullanıcı: rol listesi bir YETKİ SEVİYESİ sorusudur ─────────────
    // Sayfalar "has_role('admin','teknik_ofis_admin')" ile "değiştirme yetkisi var mı" diye sorar;
    // listedeki EN ZAYIF klasik rolün ima ettiği işlem matriste aranır. Tek rol sorulursa
    // (ör. has_role('depo') = depo kullanıcısına özel davranış) gerçek rol karşılaştırılır.
    if (count($roller) === 1) return in_array($rol, $roller, true);
    $sira = ['admin' => 5, 'teknik_ofis_admin' => 4, 'teknik_ofis' => 3, 'saha_sefi' => 2, 'depo' => 1];
    $min  = 99;
    foreach ($roller as $r) { if (isset($sira[$r])) $min = min($min, $sira[$r]); elseif ($r === $rol) return true; }
    switch ($min) {
        case 5:  return false;                                               // yalnız admin
        case 4:  return yetki_var('duzenle');                                // yönetim / değiştirme
        case 3:  return yetki_var('duzenle') || yetki_var('giris');          // teknik ofis = düzenleme
        case 2:  return yetki_var('giris') || yetki_var('duzenle') || yetki_var('onay');
        case 1:  return yetki_var('giris') || yetki_var('duzenle');
        default: return in_array($rol, $roller, true);
    }
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
    if (yetki_matris() !== null && !is_admin()) return yetki_var('giris');
    return has_role('admin', 'teknik_ofis_admin', 'saha_sefi', 'depo');
}

/**
 * İrsaliye düzenleyebilecekler (genel can_edit):
 * admin, teknik_ofis_admin → her zaman
 * teknik_ofis, saha_sefi  → sadece belirli durumlarda (durum bazlı kontrol için can_edit_irsaliye() kullanın)
 */
function can_edit(): bool
{
    if (yetki_matris() !== null && !is_admin()) return yetki_var('giris') || yetki_var('duzenle');
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
    if (yetki_matris() !== null && !is_admin()) {
        // Matrisli kullanıcı: değiştirme yetkisi → her durumda; yalnız giriş yetkisi → henüz onaylanmamışsa
        if (yetki_var('duzenle')) return true;
        if (yetki_var('giris'))   return ($irsaliye['durum'] ?? 'beklemede') === 'beklemede';
        return false;
    }
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
    if (yetki_matris() !== null && !is_admin()) return yetki_var('onay');
    return has_role('admin', 'teknik_ofis_admin', 'saha_sefi');
}

/**
 * Teknik ofis onayı verebilecekler (2. aşama / final):
 * admin, teknik_ofis_admin, teknik_ofis
 */
function can_approve_teknik(): bool
{
    if (yetki_matris() !== null && !is_admin()) return yetki_var('onay');
    return has_role('admin', 'teknik_ofis_admin', 'teknik_ofis');
}

/**
 * Raporları görüntüleyebilecek roller:
 * admin, teknik_ofis_admin, teknik_ofis
 */
function can_view_reports(): bool
{
    return yetki_var('rapor');
}

/**
 * Referans tanım yönetimi (beton sınıfı, blok, parsel vb.):
 * admin, teknik_ofis_admin
 */
function can_manage_definitions(): bool
{
    if (yetki_matris() !== null && !is_admin()) return yetki_var('duzenle');
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
