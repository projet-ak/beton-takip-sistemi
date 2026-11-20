<?php
/**
 * toplu_yukleme.php (çekirdek) — GÜNLÜK TOPLU YÜKLEME
 *
 * Her gün e-postayla gelen takip Excel'leri (Beton Takip · Demirbaş Takip · Malzeme Takip ·
 * Sarf Malzeme Stok · Sarf Taşeron Teslimat …) TEK EKRANDAN birlikte yüklenir. Sistem her
 * dosyayı **içeriğinden** tanır (dosya adına güvenilmez) ve ilgili modülün KENDİ içe aktarma
 * çekirdeğini çağırır — kurallar modül ekranıyla birebir aynıdır, kopya mantık yoktur:
 *   beton        → includes/beton_import.php  beton_import_otomatik (yeni irsaliye eklenir,
 *                  [FATURADAN] taslağı güncellenir, kayıtlı irsaliye DEĞİŞMEZ)
 *   depo         → depo/_import.php           dp_import_dosya (bölüm bazında tam yenileme)
 *   crm          → crm/_import.php            crm_import (birleştirme)
 *   prekast      → prekast/_import.php        pk_import (birleştirme)
 *   demir talep  → demir/_talep_import.php    tlp_import (tam yenileme)
 * Önizleme / satır seçimi gerektiren dosyalar (demir sevkiyat & sipariş, akaryakıt, seramik)
 * TANINIR ama aktarılmaz; rapor ilgili modül ekranına yönlendirir.
 *
 * §0: modül veritabanlarına kendi bağlantısıyla bağlanır (ty_pdo — db_*.php bağlantı hatasında
 * die() ettiğinden yüklenmez; hata dosya satırında raporlanır). Yükleme günlüğü ana DB'de
 * `toplu_yukleme_log` tablosundadır (audit_log gibi kök altyapı).
 */
use Shuchkin\SimpleXLSX;

require_once __DIR__ . '/beton_import.php';
require_once __DIR__ . '/../depo/_ortak.php';
require_once __DIR__ . '/../depo/_import.php';
require_once __DIR__ . '/../crm/_ortak.php';
require_once __DIR__ . '/../crm/_import.php';
require_once __DIR__ . '/../prekast/_ortak.php';
require_once __DIR__ . '/../prekast/_import.php';
require_once __DIR__ . '/../demir/_talep_import.php';

/**
 * Dosya türleri: anahtar => [modül, etiket, ikon, otomatik mi, modül ekranı (kökten göreli)]
 * ⚠ Anahtarlar VERİDİR (toplu_yukleme_log.tur) — yeniden adlandırma.
 */
const TY_TUR = [
    'beton'          => ['beton',     'Beton Takip Tablosu',                  'bi-truck',            true,  'import.php'],
    'depo_demirbas'  => ['depo',      'Demirbaş Takip Tablosu',               'bi-archive',          true,  'depo/import.php'],
    'depo_malzeme'   => ['depo',      'Malzeme Takip Tablosu (giriş-çıkış)',  'bi-arrow-left-right', true,  'depo/import.php'],
    'depo_sarf'      => ['depo',      'Sarf Malzeme Stok Tablosu',            'bi-box-seam',         true,  'depo/import.php'],
    'depo_taseron'   => ['depo',      'Sarf Taşeron Malzeme Teslimat Tablosu','bi-people',           true,  'depo/import.php'],
    'depo'           => ['depo',      'Depo Excel (birden çok bölüm)',        'bi-boxes',            true,  'depo/import.php'],
    'crm'            => ['crm',       'CRM Üretim Arızaları raporu',          'bi-headset',          true,  'crm/import.php'],
    'prekast'        => ['prekast',   'Prekast İş Takip / Hakkediş Çizelgesi','bi-bricks',           true,  'prekast/import.php'],
    'demir_talep'    => ['demir',     'Demir Sipariş Talepleri (IFS)',        'bi-clipboard-check',  true,  'demir/talepler.php'],
    'demir_sevkiyat' => ['demir',     'İnşaat Demiri Takip (sevkiyat)',       'bi-rulers',           false, 'demir/import.php'],
    'demir_siparis'  => ['demir',     'Demir Sipariş Takip',                  'bi-rulers',           false, 'demir/import_siparis.php'],
    'akaryakit'      => ['akaryakit', 'Akaryakıt aylık tüketim tablosu',      'bi-fuel-pump',        false, 'akaryakit/import.php'],
    'seramik'        => ['seramik',   'Seramik ambar tablosu',                'bi-grid-3x3',         false, 'seramik/import.php'],
];

/** Her gün beklenen dosyalar (e-postayla gelen takip tabloları) — "bugün yüklendi mi" paneli. */
const TY_GUNLUK = ['beton', 'depo_demirbas', 'depo_malzeme', 'depo_sarf', 'depo_taseron'];

/** Modül veritabanı bağlantısı (db_*.php ile AYNI sabit kuralı; hata die() etmez, fırlatır). */
function ty_pdo(string $mod): PDO
{
    static $c = [];
    if (isset($c[$mod])) return $c[$mod];
    if ($mod === 'beton') return $c[$mod] = $GLOBALS['pdo'];
    $on = ['demir' => 'DEMIR', 'depo' => 'DEPO', 'crm' => 'CRM', 'prekast' => 'PREKAST',
           'akaryakit' => 'AKARYAKIT', 'seramik' => 'SERAMIK'][$mod] ?? null;
    if (!$on) throw new RuntimeException("Bilinmeyen modül: $mod");
    $sabit = fn(string $k) => defined($on . '_' . $k) ? constant($on . '_' . $k) : null;
    $db = (string)($sabit('DB_NAME') ?? '');
    if ($mod === 'prekast') {   // PREKAST_DB_NAME tanımsız ya da CRM ile aynıysa CRM bağlantısı
        $crmDb = defined('CRM_DB_NAME') && CRM_DB_NAME !== '' ? CRM_DB_NAME : DB_NAME;
        if ($db === '' || $db === $crmDb) return $c[$mod] = ty_pdo('crm');
    }
    if ($db === '') $db = DB_NAME;
    $user = (string)($sabit('DB_USER') ?? '') ?: DB_USER;
    $pass = $sabit('DB_PASS') ?? DB_PASS;
    return $c[$mod] = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $db . ';charset=utf8mb4', $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}

/** Depo dosyasında bulunan bölümler (dp_import_dosya ile AYNI sayfa kuralı; salt okuma). */
function ty_depo_bolumler(SimpleXLSX $x): array
{
    $b = []; $kul = [];
    foreach (['demirbas' => ['DEMIRBAS'], 'sarf' => ['SARF MALZ'], 'el_aleti' => ['EL ALETLERI']] as $k => $p) {
        $i = dpSheet($x, $p, $kul);
        if ($i !== null) { $b[] = $k; $kul[] = $i; }
    }
    foreach ([['giris|taseron', ['TASERON MALZEME GIRIS']], ['cikis|taseron', ['TASERON MALZEME TESLIMAT', 'TASERON MALZEME CIKIS']],
              ['giris|depo', ['MALZEME GIRIS']], ['cikis|depo', ['MALZEME CIKIS']]] as [$k, $p]) {
        $i = dpSheet($x, $p, $kul);
        if ($i !== null) { $b[] = $k; $kul[] = $i; }
    }
    return $b;
}

/** Beton Takip dosyası mı? İrsaliye başlığı + beton işareti (VERİ/KOT sayfası ya da "Beton Sınıfı" sütunu). */
function ty_beton_mu(SimpleXLSX $x): bool
{
    $sayfalar = beton_sayfalar($x);
    if (!$sayfalar) return false;
    foreach ($x->sheetNames() as $n) {
        if (in_array(cleanHeader($n), ['veri', 'kot'], true)) return true;
    }
    foreach ($sayfalar as $si => $S) {
        $baslik = array_map('cleanHeader', $x->rows($si, $S['header_row'] + 1)[$S['header_row']] ?? []);
        if (in_array('beton sinifi', $baslik, true) || in_array('beton', $baslik, true)) return true;
    }
    return false;
}

/**
 * Dosyayı İÇERİĞİNDEN tanır. Sıra önemli: depo hareket sayfalarında da "İrsaliye No" başlığı
 * var (betona düşmesin), demirbaş sayfası adında "DEMİR" geçer (demir sevkiyatına düşmesin).
 * @return array{tur:?string, bolumler:array}
 */
function ty_tani(SimpleXLSX $x): array
{
    $bol = ty_depo_bolumler($x);
    if ($bol) {
        $grup = array_unique(array_map(fn($b) => match ($b) {
            'demirbas' => 'depo_demirbas', 'sarf', 'el_aleti' => 'depo_sarf',
            'giris|depo', 'cikis|depo' => 'depo_malzeme', default => 'depo_taseron' }, $bol));
        return ['tur' => count($grup) === 1 ? reset($grup) : 'depo', 'bolumler' => $bol];
    }
    $adlar = array_map(fn($n) => dpNorm((string)$n), $x->sheetNames());
    foreach ($adlar as $n) {
        if (preg_match('/\bAMBAR (GIRIS|CIKIS|MEVCUT)\b|\bPALET DURUMU\b/', $n)) return ['tur' => 'seramik', 'bolumler' => []];
    }
    $dt = tlp_dosya_turu($x);
    if ($dt) return ['tur' => 'demir_' . $dt, 'bolumler' => []];
    if (crm_sayfa($x) !== null) return ['tur' => 'crm', 'bolumler' => []];
    if (pk_sayfa($x) !== null)  return ['tur' => 'prekast', 'bolumler' => []];
    foreach ($adlar as $n) {
        if (preg_match('/^(OCAK|SUBAT|MART|NISAN|MAYIS|HAZIRAN|TEMMUZ|AGUSTOS|EYLUL|EKIM|KASIM|ARALIK)\s*20\d{2}$/', trim($n)))
            return ['tur' => 'akaryakit', 'bolumler' => []];
    }
    if (ty_beton_mu($x)) return ['tur' => 'beton', 'bolumler' => []];
    return ['tur' => null, 'bolumler' => []];
}

/** Dosya adındaki tarih (…2026-09-01… / …01.09.2026…) — CRM/Prekast rapor tarihi. */
function ty_dosya_tarihi(string $ad): ?string
{
    if (preg_match('/(20\d{2})[-_.](\d{2})[-_.](\d{2})/', $ad, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/(\d{2})[-_.](\d{2})[-_.](20\d{2})/', $ad, $m)) return "$m[3]-$m[2]-$m[1]";
    return null;
}

/**
 * Tanınmış dosyayı ilgili modüle aktarır.
 * Dönüş: ['durum'=>ok|uyari|yonlendir, 'ozet'=>[metin…], 'uyari'=>[[metin, detay[]]…], 'farkli'=>[], 'bolumler'=>[]]
 * @throws Throwable  modül çekirdeği hata verirse (o dosyanın değişiklikleri geri alınmıştır)
 */
function ty_isle(string $tur, SimpleXLSX $x, string $ad, array $bilgi = []): array
{
    $kisi = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
    $uid  = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $n0   = fn($n) => number_format((float)$n, 0, ',', '.');
    $r    = ['durum' => 'ok', 'ozet' => [], 'uyari' => [], 'farkli' => [], 'bolumler' => []];
    [$mod, , , $oto] = TY_TUR[$tur];
    if (!$oto) {
        $r['durum'] = 'yonlendir';
        $r['ozet'][] = 'Bu dosya önizleme / seçim gerektirir — modülün kendi içe aktarma ekranından yüklenmeli. Hiçbir şey değiştirilmedi.';
        return $r;
    }
    $pdoM = ty_pdo($mod);

    if ($tur === 'beton') {
        beton_onay_gocu($pdoM);
        $s = beton_import_otomatik($pdoM, $x, $uid);
        $r['ozet'][] = $s['eklendi'] ? "<b>{$n0($s['eklendi'])}</b> yeni irsaliye eklendi (" . number_format($s['m3'], 2, ',', '.') . ' m³)' : 'Yeni irsaliye yok';
        if ($s['guncellendi']) $r['ozet'][] = "{$n0($s['guncellendi'])} faturadan açılmış taslak gerçek verilerle güncellendi";
        $r['ozet'][] = "{$n0($s['kayitli'])} zaten kayıtlı" . ($s['tekrar'] ? " · {$n0($s['tekrar'])} dosyada tekrar" : '');
        $r['ozet'][] = "okunan {$n0($s['okunan'])} = " . ($s['eklendi'] + $s['guncellendi']) . ' aktarılan + ' . $s['kayitli'] . ' kayıtlı + ' . $s['tekrar'] . ' tekrar + ' . $s['hatali'] . ' hatalı';
        if ($s['imalat']) $r['ozet'][] = "{$s['imalat']} imalat / metraj sayfası güncellendi";
        if ($s['atlanan']) $r['uyari'][] = [count($s['atlanan']) . ' satır aktarılamadı (tarih / tedarikçi boş)', array_slice($s['atlanan'], 0, 40)];
        if ($s['not'])     $r['uyari'][] = [count($s['not']) . ' satırda saat okunamadı — satır aktarıldı, saat boş bırakıldı', array_slice($s['not'], 0, 20)];
        if ($s['farkli'])  $r['farkli'] = array_slice($s['farkli'], 0, 60);
        if ($s['farkli'])  $r['uyari'][] = [count($s['farkli']) . ' kayıtlı irsaliyede Excel ile sistem farklı (kayıt değiştirilmedi)', []];
    } elseif (str_starts_with($tur, 'depo')) {
        dp_hareket_semasi_kur($pdoM);
        dp_import_log_kur($pdoM);
        $s = dp_import_dosya($pdoM, $x, $ad);
        if (!$s['bolumler']) throw new RuntimeException('Tanınan depo sayfası bulunamadı.');
        foreach ($s['bolumler'] as $b => $adet) {
            $r['bolumler'][] = $b;
            $r['ozet'][] = h(DP_BOLUM[$b][1] ?? $b) . ": <b>{$n0($adet)}</b> " . (str_contains($b, '|') ? 'hareket' : 'kalem') . ' yenilendi';
        }
        foreach ($s['kontrol'] as [$sev, $msg, $det]) {
            if ($sev === 'uyari') $r['uyari'][] = [$msg, array_slice($det ?? [], 0, 20)];
        }
        $okSay = count(array_filter($s['kontrol'], fn($k) => $k[0] === 'ok'));
        if ($okSay) $r['ozet'][] = "$okSay sağlama Excel ile birebir tuttu";
    } elseif ($tur === 'crm') {
        $s = crm_import($pdoM, $x, ['kapat' => true, 'rapor_tarihi' => ty_dosya_tarihi($ad) ?? date('Y-m-d'), 'dosya' => $ad, 'kullanici' => $kisi]);
        $r['ozet'][] = "<b>{$n0($s['yeni'])}</b> yeni arıza · {$n0($s['guncellenen'])} güncellenen · <b>{$n0($s['kapanan'])}</b> kapandı"
                     . ($s['yenidenAcilan'] ? " · {$n0($s['yenidenAcilan'])} yeniden açıldı" : '');
        $r['ozet'][] = "okunan {$n0($s['okunan'])} · rapor tarihi " . date('d.m.Y', strtotime($s['rapor_tarihi']));
        if ($s['atlanan']) $r['uyari'][] = [count($s['atlanan']) . ' satır atlandı', array_map(fn($a) => 'Satır ' . $a['satir'] . ': ' . $a['sebep'], array_slice($s['atlanan'], 0, 20))];
        foreach ($s['uyari'] as $u) $r['uyari'][] = [is_array($u) ? implode(' ', $u) : (string)$u, []];
    } elseif ($tur === 'prekast') {
        $s = pk_import($pdoM, $x, ['rapor_tarihi' => pk_dosya_tarihi($ad) ?: date('Y-m-d'), 'dosya' => $ad, 'kullanici' => $kisi,
                                   'dusen_koru' => true, 'ilerleme_koru' => true, 'hesap_ekle' => true]);
        $r['ozet'][] = "<b>{$n0($s['yeni'])}</b> yeni iş · {$n0($s['guncellenen'])} güncellenen · {$n0($s['degismeyen'])} değişmeyen"
                     . " · {$n0($s['yeniKesim'])} yeni kesim · {$n0($s['yeniSilikon'])} yeni silikon";
        $r['ozet'][] = 'çizelge toplamı ' . number_format($s['toplamMetraj'], 2, ',', '.') . ' m · ' . number_format($s['toplamHakkedis'], 2, ',', '.') . ' TL';
        if (!empty($s['geri'])) $r['uyari'][] = ['ÇİZELGE GERİYE GİTTİ — bu dosyanın iş sayfası eski olabilir (hiçbir kayıt silinmedi; ayrıntı Prekast içe aktarma ekranında)', []];
        foreach ($s['uyari'] as $u) $r['uyari'][] = [is_array($u) ? implode(' ', $u) : (string)$u, []];
    } elseif ($tur === 'demir_talep') {
        $s = tlp_import($pdoM, $x);
        $r['ozet'][] = "<b>{$n0($s['talep'])}</b> talep · {$n0($s['kalem'])} kalem yenilendi (tam yenileme)";
        if ($s['atlanan']) $r['uyari'][] = [count($s['atlanan']) . ' sayfa talep sayfası sayılmadı (başlık / talep no bulunamadı)', array_slice(array_map('strval', $s['atlanan']), 0, 20)];
    }
    if ($r['uyari']) $r['durum'] = 'uyari';
    return $r;
}

/** Günlük tablosu (ana DB). Transaction DIŞINDA çağrılmalı (DDL örtük commit yapar). */
function ty_log_kur(PDO $pdo): void
{
    static $tamam = false;
    if ($tamam) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS toplu_yukleme_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        user_id INT NULL,
        kullanici VARCHAR(120) NULL,
        dosya VARCHAR(255) NOT NULL,
        tur VARCHAR(30) NULL,
        modul VARCHAR(20) NULL,
        durum VARCHAR(12) NOT NULL,
        ozet TEXT NULL,
        KEY ix_tur (tur, created)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $tamam = true;
}

function ty_log_yaz(PDO $pdo, string $dosya, ?string $tur, string $durum, string $ozet): void
{
    try {
        ty_log_kur($pdo);
        $pdo->prepare("INSERT INTO toplu_yukleme_log (user_id, kullanici, dosya, tur, modul, durum, ozet) VALUES (?,?,?,?,?,?,?)")
            ->execute([$_SESSION['user']['id'] ?? null, $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null,
                       mb_substr($dosya, 0, 255), $tur, $tur ? TY_TUR[$tur][0] : null, $durum, mb_substr($ozet, 0, 4000)]);
    } catch (Throwable $e) { /* günlük ikincildir — aktarım geçerli */ }
}

/** Tür başına son BAŞARILI yükleme (ok|uyari). */
function ty_son_yuklemeler(PDO $pdo): array
{
    $son = [];
    try {
        ty_log_kur($pdo);
        $q = $pdo->query("SELECT l.* FROM toplu_yukleme_log l JOIN (SELECT tur, MAX(id) mid FROM toplu_yukleme_log
                          WHERE durum IN ('ok','uyari') AND tur IS NOT NULL GROUP BY tur) m ON m.mid = l.id");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $son[$r['tur']] = $r;
    } catch (Throwable $e) {}
    return $son;
}

/**
 * Tek dosyayı baştan sona işler (yükleme hatası → tanıma → yetki → aktarım → günlük).
 * $d: $_FILES biçiminde tek dosya. Dönüş: rapor satırı.
 */
function ty_dosya(PDO $pdo, array $d, callable $izinli): array
{
    $ad  = basename((string)($d['name'] ?? 'dosya'));
    $rap = ['ad' => $ad, 'tur' => null, 'durum' => 'hata', 'ozet' => [], 'uyari' => [], 'farkli' => [], 'bolumler' => [], 'hata' => null];
    $err = (int)($d['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $rap['hata'] = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya sunucu boyut sınırını aşıyor (upload_max_filesize ' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_PARTIAL => 'Dosya eksik yüklendi, tekrar deneyin.',
            default => 'Yükleme hatası (kod ' . $err . ').',
        };
    } elseif (!preg_match('/\.xlsx$/i', $ad)) {
        $rap['hata'] = 'Yalnız .xlsx dosyası kabul edilir.';
    } elseif (!($x = SimpleXLSX::parse($d['tmp_name']))) {
        $rap['hata'] = 'Excel okunamadı: ' . SimpleXLSX::parseError();
    } else {
        try {
            $t = ty_tani($x);
            $rap['tur'] = $t['tur'];
            if (!$t['tur']) {
                $rap['hata'] = 'Dosya tanınmadı — hiçbir modülün şablonuna uymuyor (sayfalar: ' . implode(', ', $x->sheetNames()) . '). Hiçbir şey değiştirilmedi.';
            } elseif (!$izinli(TY_TUR[$t['tur']][0])) {
                $rap['durum'] = 'yetki';
                $rap['hata'] = TY_TUR[$t['tur']][1] . ' tanındı ama ' . (function_exists('modul_ad') ? modul_ad(TY_TUR[$t['tur']][0]) : TY_TUR[$t['tur']][0]) . ' modülünde veri girişi yetkiniz yok. Hiçbir şey değiştirilmedi.';
            } else {
                @set_time_limit(300);
                $rap = array_merge($rap, ty_isle($t['tur'], $x, $ad, $t));
            }
        } catch (Throwable $e) {
            $rap['durum'] = 'hata';
            $rap['hata'] = 'İçe aktarma hatası: ' . $e->getMessage() . ' — bu dosyadan hiçbir değişiklik uygulanmadı.';
        }
    }
    ty_log_yaz($pdo, $ad, $rap['tur'], $rap['durum'],
        $rap['hata'] ?? strip_tags(implode(' · ', $rap['ozet']) . ($rap['uyari'] ? ' · ' . count($rap['uyari']) . ' uyarı' : '')));
    return $rap;
}
