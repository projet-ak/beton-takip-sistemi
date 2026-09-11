<?php
/**
 * it/_ortak.php — IT Envanter modülü ortak çekirdek
 *
 * Tablolar (`it_` önekli, ayrı DB $pdoIt):
 *   it_cihazlar   — her satır bir varlık (bilgisayar, telefon, yazıcı, lisans…); envanter no benzersiz
 *   it_hareketler — cihazın yaşam günlüğü: giriş, zimmet, iade, servis, arıza, hurda, not
 *   it_belgeler   — cihaz başına sınırsız fotoğraf/fatura/garanti belgesi (uploads/it_envanter/{id}/)
 * Görseller DOSYA olarak tutulur; DB'de yalnız göreli URL. Kayıt silinmez, `hurda` durumuna alınır.
 */

/**
 * Varlık grupları: anahtar => [ad, ikon].
 * Envanter tek tabloda (`it_cihazlar`) durur; grup yalnız KATEGORİYİ toplayan üst başlıktır —
 * menü, merkezi izleme ekranı ve raporlar bunun üzerinden çalışır (yeni cihaz tipi eklemek
 * IT_KATEGORI'ye bir satır yazmak demektir, yeni tablo/ekran gerekmez).
 */
const IT_GRUP = [
    'bt'         => ['BT Envanteri',        'bi-pc-display'],
    'network'    => ['Ağ ve Güvenlik',      'bi-diagram-3'],
    'iletisim'   => ['İletişim Sistemleri', 'bi-telephone'],
    'guvenlik'   => ['Güvenlik Sistemleri', 'bi-shield-lock'],
    'multimedya' => ['Multimedya',          'bi-tv'],
    'yazilim'    => ['Yazılım ve Lisans',   'bi-key'],
    'sarf'       => ['Sarf ve Aksesuar',    'bi-box-seam'],
    'diger'      => ['Diğer',               'bi-three-dots'],
];

/** Kategoriler: anahtar => [ad, ikon, grup]. ⚠ Anahtarlar VERİDİR — mevcutları asla yeniden adlandırma. */
const IT_KATEGORI = [
    // BT envanteri
    'bilgisayar'   => ['Masaüstü Bilgisayar', 'bi-pc-display',  'bt'],
    'laptop'       => ['Dizüstü Bilgisayar',  'bi-laptop',      'bt'],
    'monitor'      => ['Monitör',             'bi-display',     'bt'],
    'yazici'       => ['Yazıcı / Tarayıcı',   'bi-printer',     'bt'],
    'sunucu'       => ['Sunucu / Depolama',   'bi-hdd-rack',    'bt'],
    'tablet'       => ['Tablet',              'bi-tablet',      'bt'],
    'telefon'      => ['Cep Telefonu',        'bi-phone',       'bt'],
    // Ağ ve güvenlik altyapısı
    'switch'       => ['Switch (omurga/kenar)', 'bi-hdd-network', 'network'],
    'firewall'     => ['Firewall',            'bi-shield-check','network'],
    'access_point' => ['Access Point',        'bi-wifi',        'network'],
    'superbox'     => ['Superbox / Mobil Modem', 'bi-broadcast','network'],
    'ag'           => ['Ağ Cihazı (diğer)',   'bi-router',      'network'],
    // İletişim
    'ip_telefon'   => ['IP Telefon',          'bi-telephone-inbound', 'iletisim'],
    'santral'      => ['Santral',             'bi-pc-horizontal','iletisim'],
    'hat'          => ['Telefon Hattı',       'bi-telephone-plus','iletisim'],
    // Fiziksel güvenlik
    'kamera'       => ['IP Kamera',           'bi-camera-video','guvenlik'],
    'nvr'          => ['NVR Kayıt Cihazı',    'bi-record-circle','guvenlik'],
    'kartli_gecis' => ['Kartlı Geçiş Sistemi','bi-credit-card-2-front','guvenlik'],
    'turnike'      => ['Turnike',             'bi-door-open',   'guvenlik'],
    // Multimedya
    'tv'           => ['TV / Ekran',          'bi-tv',          'multimedya'],
    'projeksiyon'  => ['Projeksiyon',         'bi-projector',   'multimedya'],
    'drone'        => ['Drone / İHA',         'bi-airplane-engines', 'multimedya'],
    'fotograf'     => ['Fotoğraf / Video Kamerası', 'bi-camera',  'multimedya'],
    // Yazılım
    'yazilim'      => ['Yazılım / Lisans',    'bi-key',         'yazilim'],
    // Sarf ve aksesuar
    'aksesuar'     => ['Aksesuar',            'bi-mouse',       'sarf'],
    'sarf'         => ['Sarf Malzeme',        'bi-droplet-half','sarf'],
    'bilesen'      => ['Bileşen (RAM/disk/işlemci)', 'bi-cpu',  'sarf'],
    'diger'        => ['Diğer',               'bi-box',         'diger'],
];

/**
 * Kategoriye özel EK ALANLAR: alan => [etiket, [kategoriler], tip, ipucu].
 * Cihaz formu bu haritaya göre alan gösterir/gizler; merkezi izleme ekranı sütun seçer.
 * Kolonlar `it_semasi_kur` içinde runtime ALTER ile eklenir (eski kayıtlar etkilenmez).
 */
const IT_EK_ALAN = [
    'dahili_no'         => ['Dahili No',            ['ip_telefon','santral'], 'text', ''],
    'telefon_no'        => ['Telefon Numarası',     ['ip_telefon','santral','hat','superbox','telefon'], 'text', ''],
    'operator'          => ['Operatör',             ['superbox','hat','telefon'], 'text', 'Turkcell / Vodafone / Türk Telekom'],
    // Donanım künyesi — kurumsal zimmet formundaki "Özellikler" bloğunun karşılığı.
    // Serbest metin `ozellikler` alanı KALIR (liste/Excel özeti); boş bırakılırsa bunlardan üretilir.
    'islemci'           => ['İşlemci (marka / model)', ['bilgisayar','laptop','sunucu'], 'text', 'İntel i7 · Intel(R) Core(TM) 7 240H'],
    'ram'               => ['RAM (tip / kapasite)',    ['bilgisayar','laptop','sunucu','tablet'], 'text', 'DDR5 16 GB · Samsung'],
    'ekran_karti'       => ['Ekran Kartı',             ['bilgisayar','laptop','sunucu'], 'text', ''],
    'disk'              => ['Disk / HDD bilgisi',      ['bilgisayar','laptop','sunucu','nvr'], 'text', 'NVMe 512 GB'],
    'anakart'           => ['Anakart',                 ['bilgisayar','laptop','sunucu'], 'text', ''],
    'ekran_boyutu'      => ['Ekran Boyutu',            ['monitor','tv','laptop','tablet','projeksiyon'], 'text', '14"'],
    'kiralik_firma'     => ['Kiralanan Firma',         ['bilgisayar','laptop','monitor','yazici','sunucu','telefon','tablet','tv','ag','switch','firewall','superbox','diger'], 'text', 'cihaz kiralıksa kiralandığı firma'],
    'firmware'          => ['Firmware Sürümü',      ['firewall','switch','access_point','nvr','kamera','kartli_gecis','turnike','santral'], 'text', ''],
    'lisans_durumu'     => ['Lisans Durumu',        ['firewall','yazilim','santral','nvr'], 'text', 'ör. UTM lisansı 2027-05-01\'e kadar'],
    'yonetim_kullanici' => ['Yönetim Kullanıcısı',  ['firewall','switch','access_point','nvr','kamera','kartli_gecis','turnike','santral'], 'text', ''],
    'yonetim_sifre'     => ['Yönetim Şifresi',      ['firewall','switch','access_point','nvr','kamera','kartli_gecis','turnike','santral'], 'sifre', 'yalnız değiştirme yetkisi olanlara gösterilir'],
    'bagli_id'          => ['Bağlı olduğu cihaz',   ['kamera','turnike','ip_telefon','access_point'], 'cihaz', 'NVR / santral / geçiş kontrol ünitesi'],
    'kapasite'          => ['Disk Kapasitesi / Port', ['nvr','sunucu','switch'], 'text', 'ör. 4×4 TB · 48 port'],
    'kullanim_amaci'    => ['Kullanım Amacı',       ['tv','projeksiyon','kamera'], 'text', 'ör. toplantı odası · lobi bilgilendirme'],
    'adet'              => ['Adet (stok)',          ['sarf','aksesuar','bilesen'], 'sayi', ''],
];

/** Bir kategorinin grubu. */
function it_grup(string $kat): string { return IT_KATEGORI[$kat][2] ?? 'diger'; }

/** Bir gruptaki kategori anahtarları. */
function it_grup_kategorileri(string $grup): array
{
    $k = [];
    foreach (IT_KATEGORI as $anahtar => $t) if (($t[2] ?? 'diger') === $grup) $k[] = $anahtar;
    return $k;
}

/** Kategoriler grup grup: grup => [anahtar => ad]. */
function it_kategori_agaci(): array
{
    $a = [];
    foreach (IT_GRUP as $g => $_) $a[$g] = [];
    foreach (IT_KATEGORI as $anahtar => $t) $a[$t[2] ?? 'diger'][$anahtar] = $t[0];
    return array_filter($a);
}

/**
 * Donanım künyesinden **kısa özet** üretir (liste, Excel ve tutanak satırı için).
 * `ozellikler` serbest metni BOŞSA kayıtta bununla doldurulur — kullanıcı yazdıysa dokunulmaz.
 */
function it_ozellik_ozet(array $r): string
{
    $p = [];
    foreach (['islemci', 'ram', 'ekran_karti', 'disk', 'ekran_boyutu', 'kapasite'] as $k) {
        $v = trim((string)($r[$k] ?? ''));
        if ($v !== '') $p[] = $v;
    }
    return mb_substr(implode(' · ', $p), 0, 255);
}

/** Bir kategoride gösterilecek ek alanlar: alan => [etiket, tip, ipucu]. */
function it_ek_alanlar(string $kat): array
{
    $r = [];
    foreach (IT_EK_ALAN as $alan => [$etiket, $katlar, $tip, $ipucu])
        if (in_array($kat, $katlar, true)) $r[$alan] = [$etiket, $tip, $ipucu];
    return $r;
}

/** Durumlar: anahtar => [ad, bootstrap rengi, ikon] */
const IT_DURUM = [
    'aktif'    => ['Kullanımda (zimmetli)', 'success',          'bi-person-check'],
    'depoda'   => ['Depoda / Boşta',        'secondary',        'bi-box-seam'],
    // Başka projeye/lokasyona gönderildi, teslim alındığı teyit edilmedi — HÂLÂ ENVANTERDE
    // (bu yüzden IT_DURUM_DUSEN'e girmez), ama depodaki kullanılabilir stok da sayılmaz.
    'transfer' => ['Transfer (yolda)',      'info text-dark',   'bi-arrow-left-right'],
    'serviste' => ['Serviste',              'info',             'bi-wrench'],
    'arizali'  => ['Arızalı',               'danger',           'bi-exclamation-triangle'],
    'kayip'    => ['Kayıp / Çalıntı',       'warning text-dark','bi-question-octagon'],
    'hibe'     => ['Hibe / Devredildi',     'primary',          'bi-gift'],
    'hurda'    => ['Hurda / Kullanım dışı', 'dark',             'bi-trash'],
];

/**
 * **Envanterden DÜŞEN durumlar.** Cihaz kaydı hiçbir zaman silinmez; hurdaya ayrılan, kaybolan/çalınan
 * ve başka kuruma hibe edilen varlıklar artık "elimizde" sayılmaz: varsayılan listelerde gizlenir,
 * mali değere ve kategori/lokasyon sayımlarına girmez, zimmetleri düşer.
 * ⚠ Yeni bir "artık bizde değil" durumu eklenirse **yalnız buraya** eklemek yeterlidir —
 * SQL parçası `it_envanterde()` tüm sorgularda bu listeden ÜRETİLİR, elle yazılmaz.
 */
const IT_DURUM_DUSEN = ['hurda', 'kayip', 'hibe'];

/**
 * "Hâlâ envanterde" SQL parçası — sorgularda `durum<>'hurda'` yerine bu kullanılır ve
 * **IT_DURUM_DUSEN'den türetilir**, elle yazılmaz. $alias JOIN'lerde tablo öneki verir ("c" → c.durum).
 */
function it_envanterde(string $alias = ''): string
{
    $on = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return $on . "durum NOT IN ('" . implode("','", IT_DURUM_DUSEN) . "')";
}

/** Durum envanterden düşmüş mü (hurda / kayıp / hibe)? */
function it_durum_dustu(?string $d): bool { return in_array((string)$d, IT_DURUM_DUSEN, true); }

/** Hareket türleri: anahtar => [ad, renk, ikon] */
const IT_HAREKET = [
    'giris'   => ['Envantere giriş',   'primary',   'bi-plus-circle'],
    'zimmet'  => ['Zimmet verildi',    'success',   'bi-person-check'],
    'iade'    => ['Zimmet iadesi',     'secondary', 'bi-arrow-return-left'],
    'servis'  => ['Servise gönderildi','info',      'bi-wrench'],
    'ariza'   => ['Arıza bildirimi',   'danger',    'bi-exclamation-triangle'],
    'donus'   => ['Servisten döndü',   'success',   'bi-check2-circle'],
    'transfer'=> ['Transfer edildi',   'info',      'bi-arrow-left-right'],
    'kayip'   => ['Kayıp / çalıntı bildirildi', 'warning text-dark', 'bi-question-octagon'],
    'hibe'    => ['Hibe / devir edildi', 'primary',  'bi-gift'],
    'hurda'   => ['Hurdaya ayrıldı',   'dark',      'bi-trash'],
    'not'     => ['Not',               'warning',   'bi-chat-left-text'],
    'guncelleme' => ['Kayıt güncellendi', 'light', 'bi-pencil'],
];

/** Şema (runtime + kurulum). İdempotent. */
function it_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_cihazlar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        envanter_no VARCHAR(20) NOT NULL,
        kategori VARCHAR(20) NOT NULL DEFAULT 'diger',
        ad VARCHAR(150) NOT NULL COMMENT 'cihaz adı / tanımı',
        marka VARCHAR(80) NULL, model VARCHAR(120) NULL, seri_no VARCHAR(120) NULL,
        durum VARCHAR(20) NOT NULL DEFAULT 'depoda',
        zimmetli VARCHAR(120) NULL COMMENT 'zimmetli kişi', departman VARCHAR(80) NULL, lokasyon VARCHAR(120) NULL,
        zimmet_tarihi DATE NULL,
        alis_tarihi DATE NULL, garanti_bitis DATE NULL, fiyat DECIMAL(12,2) NULL,
        tedarikci VARCHAR(120) NULL, fatura_no VARCHAR(60) NULL,
        ip_adresi VARCHAR(45) NULL, mac_adresi VARCHAR(40) NULL, isletim_sistemi VARCHAR(80) NULL,
        ozellikler VARCHAR(255) NULL COMMENT 'işlemci / RAM / disk vb.',
        lisans_anahtari VARCHAR(160) NULL, lisans_adet INT NULL,
        foto_url VARCHAR(255) NULL COMMENT 'en yeni fotoğraf (it_belgeler)',
        notlar TEXT NULL,
        olusturan VARCHAR(80) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_env (envanter_no),
        KEY ix_kat (kategori), KEY ix_durum (durum), KEY ix_zimmet (zimmetli), KEY ix_seri (seri_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_hareketler (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cihaz_id INT NOT NULL,
        tur VARCHAR(20) NOT NULL,
        tarih DATE NOT NULL,
        kisi VARCHAR(120) NULL COMMENT 'zimmet alan / teslim eden / servis firması',
        aciklama TEXT NULL,
        kullanici VARCHAR(80) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY ix_cihaz (cihaz_id), KEY ix_tarih (tarih), KEY ix_tur (tur)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    it_personel_semasi_kur($pdo);
    it_tanim_semasi_kur($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_belgeler (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cihaz_id INT NOT NULL,
        dosya_url VARCHAR(255) NOT NULL,
        ad VARCHAR(255) NULL, mime VARCHAR(80) NULL, boyut INT NULL,
        tur VARCHAR(20) NOT NULL DEFAULT 'belge' COMMENT 'belge | zimmet (imzalı zimmet tutanağı) | transfer (imzalı sevk tutanağı)',
        kullanici VARCHAR(80) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY ix_cihaz (cihaz_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // eski kurulumlarda kolon yoksa ekle (imzalı evrak ayrımı)
    try { $pdo->query("SELECT tur FROM it_belgeler LIMIT 1"); }
    catch (Throwable $e) { try { $pdo->exec("ALTER TABLE it_belgeler ADD COLUMN tur VARCHAR(20) NOT NULL DEFAULT 'belge'"); } catch (Throwable $e2) {} }
    // Belge HANGİ harekete ait: cihaz 5-6 kez el değiştirdiğinde "bu tutanak kimin dönemine ait"
    // sorusunun cevabı. NULL = döneme bağlanmamış (eski kayıtlar, fatura, serbest belge).
    try { $pdo->query("SELECT hareket_id FROM it_belgeler LIMIT 1"); }
    catch (Throwable $e) { try { $pdo->exec("ALTER TABLE it_belgeler ADD COLUMN hareket_id INT NULL"); } catch (Throwable $e2) {} }
}

/** Türkçe duyarsız normalize (arama/karşılaştırma). */
function it_norm(string $s): string
{
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return str_replace(['İ','I','ı','Ş','Ğ','Ü','Ö','Ç'], ['I','I','I','S','G','U','O','C'], $s);
}

/**
 * Türkçe doğru BÜYÜK HARF: "Fırat Acı" → "FIRAT ACI", "İsmail Şahin" → "İSMAİL ŞAHİN".
 *
 * ⚠ `mb_strtoupper` Türkçeyi bilmez: 'i' → 'I' (İ olmalı), 'ı' → 'I' (doğru ama 'i' ile karışır).
 * Bu yüzden önce i→İ, ı→I elle değiştirilir, sonra geri kalanı mb_strtoupper yapar.
 */
function it_buyuk(?string $s): string
{
    $s = trim((string)$s);
    if ($s === '') return '';
    return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $s), 'UTF-8');
}

/** Yeni envanter no: IT-00001 (en büyük sıra + 1). */
function it_envanter_no(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT envanter_no FROM it_cihazlar WHERE envanter_no LIKE 'IT-%'")->fetchAll(PDO::FETCH_COLUMN) as $n) {
        if (preg_match('/^IT-(\d+)$/', (string)$n, $m)) $max = max($max, (int)$m[1]);
    }
    return 'IT-' . str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
}

function it_kategoriAd(string $k): string { return IT_KATEGORI[$k][0] ?? $k; }
function it_kategoriIkon(string $k): string { return IT_KATEGORI[$k][1] ?? 'bi-box'; }
function it_durumAd(string $d): string { return IT_DURUM[$d][0] ?? $d; }
function it_durumRenk(string $d): string { return IT_DURUM[$d][1] ?? 'secondary'; }
function it_durumBadge(string $d): string
{
    $x = IT_DURUM[$d] ?? [$d, 'secondary', 'bi-question-circle'];
    return '<span class="badge bg-' . $x[1] . '"><i class="bi ' . $x[2] . ' me-1"></i>' . htmlspecialchars($x[0]) . '</span>';
}

/** Garantinin bitmesine kaç gün var (negatif = geçmiş, null = tanımsız). */
function it_garanti_kalan(?string $bitis): ?int
{
    if (!$bitis) return null;
    return (int)floor((strtotime($bitis) - strtotime(date('Y-m-d'))) / 86400);
}

/** Form tarih alanı → Y-m-d veya null. */
function it_tarih(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $s, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}

/** Form sayı alanı (Türkçe virgül/binlik) → float|null. */
function it_sayi(?string $s): ?float
{
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = str_replace([' ', 'TL', '₺'], '', $s);
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : null;
}

/**
 * Hareket kaydı ekler (cihaz yaşam günlüğü) ve **eklenen satırın id'sini döndürür** —
 * imzalı tutanak o harekete (zimmet dönemine) bağlanabilsin diye.
 */
function it_hareket_ekle(PDO $pdo, int $cihazId, string $tur, ?string $kisi, ?string $aciklama, ?string $tarih = null): int
{
    if (!isset(IT_HAREKET[$tur])) $tur = 'not';
    $kul = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
    $pdo->prepare("INSERT INTO it_hareketler (cihaz_id, tur, tarih, kisi, aciklama, kullanici) VALUES (?,?,?,?,?,?)")
        ->execute([$cihazId, $tur, $tarih ?: date('Y-m-d'), $kisi ?: null, $aciklama ?: null, $kul]);
    return (int)$pdo->lastInsertId();
}

/**
 * Liste filtresi: [$whereSql, $params, $etkinFiltreler]. Serbest arama PHP tarafında değil
 * SQL LIKE ile — ad/marka/model/seri/envanter no/zimmetli/lokasyon alanlarında.
 */
function it_filtre(array $g, ?PDO $pdo = null): array
{
    $w = []; $p = []; $etkin = [];
    // Grup = kategori üst başlığı (BT / Ağ / İletişim / Güvenlik …) — kategori seçiliyse o önceliklidir
    if (!empty($g['grup']) && isset(IT_GRUP[$g['grup']]) && empty($g['kategori'])) {
        $katlar = it_grup_kategorileri($g['grup']);
        if ($katlar) { $w[] = 'kategori IN (' . implode(',', array_fill(0, count($katlar), '?')) . ')'; foreach ($katlar as $kk) $p[] = $kk; }
        $etkin['grup'] = $g['grup'];
    }
    if (!empty($g['kategori']) && isset(IT_KATEGORI[$g['kategori']])) { $w[] = 'kategori=?'; $p[] = $g['kategori']; $etkin['kategori'] = $g['kategori']; }
    $__d = (string)($g['durum'] ?? '');
    if ($__d !== '' && isset(IT_DURUM[$__d]))        { $w[] = 'durum=?'; $p[] = $__d; $etkin['durum'] = $__d; }
    elseif ($__d === 'hepsi')                        { $etkin['durum'] = 'hepsi'; }          // süzgeç yok: düşenler de listelenir
    elseif (($__k = it_durum_kume($__d)) !== [])     { $w[] = 'durum IN (' . implode(',', array_fill(0, count($__k), '?')) . ')';
                                                       foreach ($__k as $__x) $p[] = $__x; $etkin['durum'] = $__d; }
    elseif ($__d === '')                             { $w[] = it_envanterde(); }             // varsayılan: hurda/kayıp/hibe gizli
    foreach (['zimmetli', 'departman', 'lokasyon', 'marka'] as $k) {
        if (!empty($g[$k])) { $w[] = "$k=?"; $p[] = $g[$k]; $etkin[$k] = $g[$k]; }
    }
    // İmzalı zimmet tutanağı eksik olanlar — dashboard'daki "İmzalı evrakı eksik zimmet" kartının listesi
    if (($g['evrak'] ?? '') === 'eksik') {
        $w[] = "zimmetli<>'' AND NOT EXISTS (SELECT 1 FROM it_belgeler b WHERE b.cihaz_id=it_cihazlar.id AND b.tur='zimmet')";
        $etkin['evrak'] = 'eksik';
    }
    if (!empty($g['garanti'])) {
        if ($g['garanti'] === 'bitiyor')  { $w[] = 'garanti_bitis BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)'; }
        if ($g['garanti'] === 'bitti')    { $w[] = 'garanti_bitis < CURDATE()'; }
        if ($g['garanti'] === 'devam')    { $w[] = 'garanti_bitis >= CURDATE()'; }
        $etkin['garanti'] = $g['garanti'];
    }
    if (!empty($g['q'])) {
        // Tek kutudan IP / MAC / seri no / envanter no / dahili / telefon araması
        $q = '%' . trim($g['q']) . '%';
        // Kolonların hepsi it_semasi_kur() tarafından garanti edilir (yoksa runtime ALTER ile eklenir)
        $alan = ['envanter_no','ad','marka','model','seri_no','sasi_no','zimmetli','departman','lokasyon','notlar',
                 'ip_adresi','mac_adresi','varlik_kodu','cihaz_kodu','dahili_no','telefon_no','imei'];
        $sart = '(' . implode(' LIKE ? OR ', $alan) . ' LIKE ?)';
        foreach ($alan as $_) $p[] = $q;
        // KİŞİ ADIYLA ARAMA: `zimmetli` metni LIKE ile aranıyor ama (1) Türkçe 'İ' ile 'i' LIKE'ta
        // eşleşmiyor ("ismail" → "İSMAİL" bulunamıyordu), (2) kelime sırası serbest değil.
        // Bu yüzden arama metni personel kartlarıyla da (it_personel_ara, PHP tarafında it_norm ile)
        // eşleştirilip bulunan kişilerin cihazları sonuca EKLENİR.
        if ($pdo instanceof PDO) {
            try {
                $kisiler = it_personel_ara($pdo, trim($g['q']), 50, true);
                $ids = array_values(array_filter(array_map(fn($x) => (int)$x['id'], $kisiler)));
                if ($ids) $sart = '(' . $sart . ' OR personel_id IN (' . implode(',', $ids) . '))';
            } catch (Throwable $e) { /* personel tablosu yoksa yalnız LIKE ile aranır */ }
        }
        $w[] = $sart;
        $etkin['q'] = trim($g['q']);
    }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p, $etkin];
}

/**
 * **Sanal durum süzgeçleri** — KPI kartları çoğu zaman tek bir duruma değil bir KÜMEye işaret eder
 * ("Serviste + Arızalı", "Envanterden düşen"). Kart tek duruma bağlanırsa sayı ile liste tutmaz
 * (dashboard "1" derken liste boş açılırdı). `?durum=` bu anahtarları da kabul eder.
 */
const IT_DURUM_SANAL = [
    'hepsi'   => 'Tümü (düşenler dahil)',
    'sorunlu' => 'Serviste + Arızalı',
    'dusen'   => 'Envanterden düşen (hurda / kayıp / hibe)',
];

/** Sanal süzgecin kapsadığı gerçek durumlar ('hepsi' → boş: süzgeç uygulanmaz). */
function it_durum_kume(string $anahtar): array
{
    return match ($anahtar) {
        'sorunlu' => ['serviste', 'arizali'],
        'dusen'   => IT_DURUM_DUSEN,
        default   => [],
    };
}

/** Bir sütunun dolu, benzersiz değerleri (filtre menüleri). Sütun whitelist'lidir. */
function it_secenekler(PDO $pdo, string $sutun): array
{
    if (!in_array($sutun, ['zimmetli', 'departman', 'lokasyon', 'marka', 'tedarikci'], true)) return [];
    try {
        return $pdo->query("SELECT DISTINCT $sutun FROM it_cihazlar WHERE $sutun IS NOT NULL AND $sutun<>'' ORDER BY $sutun")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
}

/** Dashboard / rapor özeti. */
function it_ozet(PDO $pdo): array
{
    $o = ['toplam'=>0,'aktif'=>0,'depoda'=>0,'transfer'=>0,'serviste'=>0,'arizali'=>0,'kayip'=>0,'hibe'=>0,'hurda'=>0,'dusen'=>0,
          'mali'=>0.0,'garantiBitiyor'=>0,'garantiBitti'=>0,'zimmetliKisi'=>0,'lisans'=>0];
    try {
        $r = $pdo->query("SELECT COUNT(*) toplam,
                SUM(durum='aktif') aktif, SUM(durum='depoda') depoda, SUM(durum='transfer') transfer, SUM(durum='serviste') serviste,
                SUM(durum='arizali') arizali, SUM(durum='hurda') hurda,
                SUM(durum='kayip') kayip, SUM(durum='hibe') hibe,
                COALESCE(SUM(CASE WHEN " . it_envanterde() . " THEN fiyat END),0) mali,
                SUM(" . it_envanterde() . " AND garanti_bitis BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)) garantiBitiyor,
                SUM(" . it_envanterde() . " AND garanti_bitis < CURDATE()) garantiBitti,
                COUNT(DISTINCT CASE WHEN durum='aktif' AND zimmetli<>'' THEN zimmetli END) zimmetliKisi,
                SUM(kategori='yazilim' AND " . it_envanterde() . ") lisans
            FROM it_cihazlar")->fetch();
        foreach ($o as $k => $v) $o[$k] = is_float($v) ? (float)($r[$k] ?? 0) : (int)($r[$k] ?? 0);
        // Envanterden düşenlerin toplamı (hurda + kayıp + hibe) — "Toplam Cihaz" bundan arındırılır
        foreach (IT_DURUM_DUSEN as $d) $o['dusen'] += (int)($o[$d] ?? 0);
    } catch (Throwable $e) { /* tablo yok */ }
    return $o;
}

/** Cihazın belgeleri (en yeni önce). */
function it_belgeler(PDO $pdo, int $cihazId): array
{
    try {
        $st = $pdo->prepare("SELECT * FROM it_belgeler WHERE cihaz_id=? ORDER BY id DESC");
        $st->execute([$cihazId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Belge/fotoğraf yükler → `uploads/it_envanter/{cihaz_id}/`. Önceki belgeler SİLİNMEZ.
 * Görselse cihazın `foto_url` alanı en yeni fotoğrafı gösterir (liste küçük resmi).
 * @return array{0:bool,1:string}
 */
function it_belge_yukle(PDO $pdo, int $cihazId, array $f, ?string $kullanici = null, string $tur = 'belge', ?int $hareketId = null): array
{
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return [false, 'Dosya seçilmedi.'];
    return it_belge_kaydet($pdo, $cihazId, (string)$f['tmp_name'], (string)($f['name'] ?? ''), $kullanici, $tur, true, $hareketId);
}

/**
 * **Belge türleri** — `it_belgeler.tur`. İlk dördü ISLAK İMZALI TUTANAK (yeşil ✓ sayılır),
 * `fatura` cihazın alış belgesi, `belge` serbest ek. Yeni bir tutanak türü eklenecekse
 * buraya satır eklemek + (imzalıysa) IT_BELGE_IMZALI'ya anahtarı koymak yeterlidir.
 */
const IT_BELGE_TUR = [
    'zimmet'   => ['İmzalı Zimmet Tutanağı',       'success',   'bi-file-earmark-check'],
    'iade'     => ['İmzalı İade Tutanağı',         'primary',   'bi-arrow-return-left'],
    'transfer' => ['İmzalı Sevk (Transfer) Tutanağı', 'info',   'bi-arrow-left-right'],
    'hurda'    => ['İmzalı Hurda / Zayi / Hibe Tutanağı', 'dark','bi-file-earmark-x'],
    'fatura'   => ['Alış Faturası / İrsaliyesi',   'warning',   'bi-receipt'],
    'belge'    => ['Belge / Fotoğraf',             'secondary', 'bi-paperclip'],
];
/** Islak imzalı tutanak sayılan türler (evrak rozetleri bunlara bakar). */
const IT_BELGE_IMZALI = ['zimmet', 'iade', 'transfer', 'hurda'];

/** Belge türü etiketi/rengi/ikonu (bilinmeyen tür → 'belge'). */
function it_belge_turu(?string $t): array { return IT_BELGE_TUR[(string)$t] ?? IT_BELGE_TUR['belge']; }

/**
 * Belge olarak kabul edilen türler. PDF + görsel yanında **Office dosyaları** da vardır:
 * Snipe-IT eklerinde imzalı tutanağın taraması kadar .xlsx/.docx kayıtlar da çıkıyor.
 */
const IT_BELGE_MIME = [
    'application/pdf',
    'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/gif', 'image/bmp',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

/**
 * Dosyanın kabul edilebilir MIME'ı — kabul edilmiyorsa null.
 *
 * ⚠ İçerik sezgisi (finfo) Office dosyalarında **kapsayıcı türü** verir: .xlsx/.docx aslında bir ZIP
 * olduğundan `application/zip`, eski .xls/.doc ise `application/CDFV2` / `application/octet-stream`
 * döner. Sırf buna bakılırsa geçerli ekler "desteklenmeyen tür" diye reddedilir. Bu yüzden sezilen tür
 * listede yoksa ve **genel bir kapsayıcıysa**, UZANTIYA göre karar verilir (uzantı haritası yalnız
 * güvenli türleri içerir — .php gibi bir uzantı zaten haritada yoktur).
 */
function it_belge_mime(string $yol, string $ad): ?string
{
    $m = guess_mime($yol, $ad);
    if (in_array($m, IT_BELGE_MIME, true)) return $m;

    $genel = ['application/zip', 'application/octet-stream', 'application/CDFV2',
              'application/vnd.ms-office', 'application/x-ole-storage', 'text/plain'];
    if (!in_array($m, $genel, true)) return null;

    $ext = strtolower(pathinfo($ad !== '' ? $ad : $yol, PATHINFO_EXTENSION));
    $harita = [
        'pdf'=>'application/pdf', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png',
        'webp'=>'image/webp', 'heic'=>'image/heic', 'gif'=>'image/gif', 'bmp'=>'image/bmp',
        'doc'=>'application/msword',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'=>'application/vnd.ms-excel',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    $u = $harita[$ext] ?? null;
    return ($u !== null && in_array($u, IT_BELGE_MIME, true)) ? $u : null;
}

/**
 * Diskteki bir dosyayı cihazın belgesi olarak kaydeder — form yüklemesi (it_belge_yukle) ve
 * dış kaynaktan indirme (Snipe-IT köprüsü) aynı doğrulama/adlandırma yolundan geçsin diye ortak.
 * $tasi=true → dosya taşınır (form yüklemesi), false → kopyalanır (indirilen geçici dosya).
 * @return array{0:bool,1:string}
 */
function it_belge_kaydet(PDO $pdo, int $cihazId, string $yol, string $ad, ?string $kullanici = null,
                         string $tur = 'belge', bool $tasi = false, ?int $hareketId = null): array
{
    if ($yol === '' || !is_file($yol)) return [false, 'Dosya bulunamadı.'];
    $ad   = $ad !== '' ? $ad : basename($yol);
    $mime = it_belge_mime($yol, $ad);
    if ($mime === null)
        return [false, h($ad) . ': desteklenmeyen tür (PDF · görsel · Word/Excel) — ' . guess_mime($yol, $ad)];
    $boyut = (int)@filesize($yol);
    if ($boyut > 15 * 1024 * 1024) return [false, h($ad) . ': dosya 15 MB sınırını aşıyor.'];

    $dir = __DIR__ . '/../uploads/it_envanter/' . $cihazId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [false, 'Klasör oluşturulamadı: uploads/it_envanter/' . $cihazId];
    $ext  = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'bin';
    $yeni = 'belge_' . date('Ymd_His') . '_' . substr(md5($ad . microtime()), 0, 6) . '.' . $ext;
    $hedef = $dir . '/' . $yeni;
    $ok = $tasi ? @move_uploaded_file($yol, $hedef) : @copy($yol, $hedef);
    if (!$ok) return [false, h($ad) . ': dosya diske yazılamadı.'];

    $url = 'uploads/it_envanter/' . $cihazId . '/' . $yeni;
    $tur = isset(IT_BELGE_TUR[$tur]) ? $tur : 'belge';
    $pdo->prepare("INSERT INTO it_belgeler (cihaz_id, dosya_url, ad, mime, boyut, tur, hareket_id, kullanici) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$cihazId, $url, mb_substr($ad, 0, 255), $mime, $boyut, $tur, $hareketId ?: null, $kullanici]);
    if (str_starts_with($mime, 'image/'))
        $pdo->prepare("UPDATE it_cihazlar SET foto_url=? WHERE id=?")->execute([$url, $cihazId]);
    return [true, h($ad) . ' kaydedildi.'];
}

/**
 * Cihazın mevcut belgelerinin içerik md5'leri — dış kaynaktan tekrar tekrar indirmede
 * **aynı dosya iki kez eklenmesin** diye (ad/boyut değil, BAYT karşılaştırması).
 */
function it_belge_md5ler(PDO $pdo, int $cihazId): array
{
    $r = [];
    foreach (it_belgeler($pdo, $cihazId) as $b) {
        $y = __DIR__ . '/../' . ($b['dosya_url'] ?? '');
        if (is_file($y)) $r[md5_file($y)] = true;
    }
    return $r;
}

/** Belgeyi siler (kayıt + disk); foto_url kalan en yeni görsele döner. */
function it_belge_sil(PDO $pdo, int $belgeId): bool
{
    $st = $pdo->prepare("SELECT * FROM it_belgeler WHERE id=?");
    $st->execute([$belgeId]);
    $b = $st->fetch();
    if (!$b) return false;
    $pdo->prepare("DELETE FROM it_belgeler WHERE id=?")->execute([$belgeId]);
    $v = $pdo->prepare("SELECT COUNT(*) FROM it_belgeler WHERE dosya_url=?");
    $v->execute([$b['dosya_url']]);
    if (!(int)$v->fetchColumn() && str_starts_with((string)$b['dosya_url'], 'uploads/it_envanter/'))
        @unlink(__DIR__ . '/../' . $b['dosya_url']);
    $son = $pdo->prepare("SELECT dosya_url FROM it_belgeler WHERE cihaz_id=? AND mime LIKE 'image/%' ORDER BY id DESC LIMIT 1");
    $son->execute([(int)$b['cihaz_id']]);
    $pdo->prepare("UPDATE it_cihazlar SET foto_url=? WHERE id=?")->execute([$son->fetchColumn() ?: null, (int)$b['cihaz_id']]);
    return true;
}

/**
 * Verilen cihazlar için belge sayıları:
 * [cihaz_id => ['toplam'=>n, 'imzali'=>n, 'zimmet'=>n, 'transfer'=>n, 'hurda'=>n]].
 * 'imzali' = imzalı tutanak (`it_belgeler.tur` = 'zimmet' zimmet | 'transfer' sevk | 'hurda' hurda/zayi/hibe
 * tutanağı) — listedeki yeşil evrak rozeti bundan doğar. Tür bazındaki sayaçlar, cihazın DURUMUNA uygun
 * tutanağın yüklenip yüklenmediğini sormak içindir (hurdaya ayrılmış cihazda zimmet tutanağının olması
 * hurda tutanağının yerini tutmaz).
 */
function it_belge_sayilari(PDO $pdo, array $cihazIdler): array
{
    $cihazIdler = array_values(array_unique(array_filter(array_map('intval', $cihazIdler))));
    if (!$cihazIdler) return [];
    try {
        $ph = implode(',', array_fill(0, count($cihazIdler), '?'));
        $imz = "'" . implode("','", IT_BELGE_IMZALI) . "'";
        $st = $pdo->prepare("SELECT cihaz_id, COUNT(*) toplam, SUM(tur IN ($imz)) imzali,
                                    SUM(tur='zimmet') zimmet, SUM(tur='iade') iade,
                                    SUM(tur='transfer') transfer, SUM(tur='hurda') hurda, SUM(tur='fatura') fatura
                             FROM it_belgeler WHERE cihaz_id IN ($ph) GROUP BY cihaz_id");
        $st->execute($cihazIdler);
        $r = [];
        foreach ($st->fetchAll() as $x) $r[(int)$x['cihaz_id']] = [
            'toplam'=>(int)$x['toplam'], 'imzali'=>(int)$x['imzali'], 'zimmet'=>(int)$x['zimmet'],
            'iade'=>(int)$x['iade'], 'transfer'=>(int)$x['transfer'], 'hurda'=>(int)$x['hurda'], 'fatura'=>(int)$x['fatura']];
        return $r;
    } catch (Throwable $e) { return []; }
}

/**
 * Cihazın envanterden DÜŞÜŞ hareketi (hurda / kayıp / hibe) — hurda tutanağı gerekçeyi, tarihi ve
 * işlemi yapan kişiyi buradan okur. En son kaydedilen satır (id) esas alınır: geriye dönük tarihle
 * girilen yeni bir karar, eski tarihli kayda yenilmesin.
 */
function it_dusum_son(PDO $pdo, int $cihazId, ?string $tur = null): ?array
{
    try {
        $turler = ($tur !== null && in_array($tur, IT_DURUM_DUSEN, true)) ? [$tur] : IT_DURUM_DUSEN;
        $ph = implode(',', array_fill(0, count($turler), '?'));
        $st = $pdo->prepare("SELECT * FROM it_hareketler WHERE cihaz_id=? AND tur IN ($ph) ORDER BY id DESC LIMIT 1");
        $st->execute(array_merge([$cihazId], $turler));
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Cihazın KÜNYESİ — kurumsal demirbaş formundaki "ÖZELLİKLER" bloğu: yalnız DOLU alanlar,
 * etiket => değer. Zimmet tutanağı ile hurda tutanağı aynı künyeyi basar (tek yerde düzeltilsin).
 */
function it_kunye(PDO $pdo, array $r): array
{
    $lok = !empty($r['lokasyon_id']) ? it_lokasyon_yol($pdo, (int)$r['lokasyon_id']) : (string)($r['lokasyon'] ?? '');
    $alanlar = [
        'MARKA'                => $r['marka'] ?? '',
        'MODEL'                => $r['model'] ?? '',
        'ŞASİ NO / SERİ NO'    => trim((string)($r['seri_no'] ?? '') . (($r['sasi_no'] ?? '') && ($r['sasi_no'] !== ($r['seri_no'] ?? '')) ? ' / ' . $r['sasi_no'] : '')),
        'KULLANIM DURUMU'      => it_durumAd((string)$r['durum']),
        'ZİMMETLENEN PERSONEL' => $r['zimmetli'] ?? '',
        'LOKASYON'             => $lok,
        'KİRALANAN FİRMA'      => $r['kiralik_firma'] ?? '',
        'KAPASİTE'             => $r['kapasite'] ?? '',
        'İŞLEMCİ MARKA / MODEL'=> $r['islemci'] ?? '',
        'RAM TİPİ'             => $r['ram'] ?? '',
        'EKRAN KARTI'          => $r['ekran_karti'] ?? '',
        'HDD BİLGİSİ'          => $r['disk'] ?? '',
        'ANAKART'              => $r['anakart'] ?? '',
        'EKRAN BOYUTU'         => $r['ekran_boyutu'] ?? '',
        'IMEI'                 => $r['imei'] ?? '',
        'IP / MAC'             => trim((string)($r['ip_adresi'] ?? '') . (($r['mac_adresi'] ?? '') ? ' / ' . $r['mac_adresi'] : ''), ' /'),
        'İŞLETİM SİSTEMİ'      => $r['isletim_sistemi'] ?? '',
        'CİHAZ KODU'           => $r['cihaz_kodu'] ?? '',
        'ENVANTER NO'          => $r['envanter_no'] ?? '',
        'IFS SERİ NESNE NO'    => $r['varlik_kodu'] ?? '',
    ];
    return array_filter($alanlar, fn($x) => trim((string)$x) !== '');
}

/**
 * Cihazın son transfer ÇIKIŞ hareketi ("Sevk: <kaynak> → <hedef> · gönderen: … · isteyen: … · not").
 * ⚠ Teslim alma da `tur='transfer'` yazılır; çıkış satırı **'Sevk:' öneki** ile ayrılır — yoksa tutanakta
 * gönderen proje ve sevk tarihi teslim satırından okunup boş çıkıyordu. Sıralama **id**'ye göredir:
 * geriye dönük tarihle girilen yeni bir sevk, eski tarihli kayda yenilmesin.
 */
function it_transfer_son(PDO $pdo, int $cihazId): ?array
{
    try {
        $st = $pdo->prepare("SELECT * FROM it_hareketler WHERE cihaz_id=? AND tur='transfer' AND aciklama LIKE 'Sevk:%'
                             ORDER BY id DESC LIMIT 1");
        $st->execute([$cihazId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Verilen cihazlar için "kaç gündür yolda": [cihaz_id => gün]. Transfer durumundaki cihazın
 * son transfer hareketinin tarihinden bugüne. Uzun süredir teslim alınmayanlar böyle görünür.
 */
function it_transfer_gunleri(PDO $pdo, array $cihazIdler): array
{
    $cihazIdler = array_values(array_unique(array_filter(array_map('intval', $cihazIdler))));
    if (!$cihazIdler) return [];
    try {
        $ph = implode(',', array_fill(0, count($cihazIdler), '?'));
        // Yalnız ÇIKIŞ satırları ('Sevk:' önekli); teslim alma satırı süreyi sıfırlardı.
        // Her cihazın EN SON kaydedilen çıkışı (id) esas alınır, en büyük tarih değil.
        $st = $pdo->prepare("SELECT h.cihaz_id, h.tarih FROM it_hareketler h
                             WHERE h.tur='transfer' AND h.aciklama LIKE 'Sevk:%' AND h.cihaz_id IN ($ph)
                               AND h.id = (SELECT MAX(h2.id) FROM it_hareketler h2
                                           WHERE h2.cihaz_id=h.cihaz_id AND h2.tur='transfer' AND h2.aciklama LIKE 'Sevk:%')");
        $st->execute($cihazIdler);
        $r = [];
        $bugun = new DateTimeImmutable('today');
        foreach ($st->fetchAll() as $x) {
            $t = (string)$x['tarih'];
            if ($t === '') continue;
            try { $r[(int)$x['cihaz_id']] = (int)(new DateTimeImmutable($t))->diff($bugun)->format('%r%a'); } catch (Throwable $e) {}
        }
        return $r;
    } catch (Throwable $e) { return []; }
}

/**
 * Garanti / fiyat (mali) bilgisi gösterilsin mi?
 * Kurumsal envanterde bu iki alan çoğu cihazda boş ve mali veri paylaşıma açık olmamalı —
 * varsayılan GİZLİ. `config.php`'de `define('IT_MALI_GOSTER', true);` ile geri açılır.
 */
function it_mali_goster(): bool
{
    return defined('IT_MALI_GOSTER') ? (bool)IT_MALI_GOSTER : false;
}

/** Çoklu $_FILES dizisini tek tek dosya dizilerine ayırır. */
function it_dosya_listesi(array $f): array
{
    if (!$f) return [];
    return is_array($f['name'] ?? null)
        ? array_map(fn($i) => ['name'=>$f['name'][$i], 'tmp_name'=>$f['tmp_name'][$i], 'error'=>$f['error'][$i], 'size'=>$f['size'][$i]], array_keys($f['name']))
        : [$f];
}

/* ══════════════════════════════════════════════════════════════════════════════
 * PERSONEL + LOKASYON (2026-09-09)
 *   it_lokasyonlar — hiyerarşik yer ağacı: Proje (Kartal) → Etap/kod (U030, U031, U039) ·
 *                    Bina (ERN Holding İstanbul Merkez) → birim/alan (direktörlükler, satış ofisi…)
 *   it_personel    — kime zimmetledik: sicil, ad soyad, unvan, birim, lokasyon, telefon, işe giriş / çıkış
 *   it_cihazlar    — personel_id + lokasyon_id bağı (eski `zimmetli`/`lokasyon` metinleri eş zamanlı tutulur:
 *                    tutanak, filtre ve eski kayıtlar için)
 * ══════════════════════════════════════════════════════════════════════════════ */

/** Lokasyon türleri: anahtar => [ad, ikon] */
const IT_LOK_TUR = [
    'proje' => ['Proje / Etap',  'bi-buildings'],
    'bina'  => ['Bina',          'bi-building'],
    'birim' => ['Birim / Alan',  'bi-door-open'],
    'depo'  => ['Depo',          'bi-box-seam'],
];

/** Varsayılan lokasyon ağacı (kurulumda tablo boşsa yüklenir; lokasyonlar.php'den de tek tıkla). */
const IT_LOK_SEED = [
    ['Kartal Batı Yakası Projesi', null, 'proje', [
        ['1. Etap',                 'U030', 'proje', []],
        ['2. Etap',                 'U031', 'proje', []],
        ['Millet Bahçesi Projesi',  'U039', 'proje', []],
        ['Şantiye Teknik Ofis',     null,   'birim', []],
        ['Şantiye Depo',            null,   'depo',  []],
    ]],
    ['ERN Holding İstanbul Merkez Binası', null, 'bina', [
        ['Gayrimenkul Geliştirme Direktörlüğü', null, 'birim', []],
        ['Satış Ofisi',                          null, 'birim', []],
        ['Kurumsal İletişim Direktörlüğü',       null, 'birim', []],
        ['Yönetim Kurulu',                       null, 'birim', []],
        ['Yönetim (Patron) Ofisleri',            null, 'birim', []],
        ['Bilgi İşlem Deposu',                   null, 'depo',  []],
    ]],
];

/** Personel + lokasyon şeması (it_semasi_kur'dan da çağrılır). İdempotent. */
function it_personel_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_lokasyonlar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ust_id INT NULL COMMENT 'üst lokasyon (NULL = kök)',
        tur VARCHAR(10) NOT NULL DEFAULT 'birim',
        kod VARCHAR(20) NULL COMMENT 'proje kodu (U030) vb.',
        ad VARCHAR(120) NOT NULL,
        aciklama VARCHAR(255) NULL,
        sira INT NOT NULL DEFAULT 0,
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY ix_ust (ust_id), KEY ix_kod (kod)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_personel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sicil_no VARCHAR(30) NULL,
        ad VARCHAR(80) NOT NULL,
        soyad VARCHAR(80) NOT NULL,
        unvan VARCHAR(100) NULL,
        birim VARCHAR(100) NULL COMMENT 'departman / direktörlük',
        lokasyon_id INT NULL,
        telefon VARCHAR(30) NULL,
        eposta VARCHAR(120) NULL,
        ise_giris DATE NULL,
        isten_cikis DATE NULL COMMENT 'NULL = çalışıyor',
        notlar TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY ix_sicil (sicil_no), KEY ix_ad (soyad, ad), KEY ix_lok (lokasyon_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['personel_id' => "INT NULL", 'lokasyon_id' => "INT NULL"] as $kol => $tip) {
        try { $pdo->query("SELECT $kol FROM it_cihazlar LIMIT 1"); }
        catch (Throwable $e) { try { $pdo->exec("ALTER TABLE it_cihazlar ADD COLUMN $kol $tip"); } catch (Throwable $e2) {} }
    }
}

/** Varsayılan ağacı yükler; var olan (aynı üst + aynı ad) satırları atlar. Eklenen adedi döner. */
function it_lokasyon_seed(PDO $pdo): int
{
    $eklenen = 0;
    $ekle = function (array $liste, ?int $ust) use (&$ekle, &$eklenen, $pdo) {
        $sira = 0;
        foreach ($liste as [$ad, $kod, $tur, $alt]) {
            $sira++;
            $st = $pdo->prepare("SELECT id FROM it_lokasyonlar WHERE ad=? AND " . ($ust === null ? "ust_id IS NULL" : "ust_id=?"));
            $st->execute($ust === null ? [$ad] : [$ad, $ust]);
            $id = (int)$st->fetchColumn();
            if (!$id) {
                $pdo->prepare("INSERT INTO it_lokasyonlar (ust_id, tur, kod, ad, sira) VALUES (?,?,?,?,?)")->execute([$ust, $tur, $kod, $ad, $sira]);
                $id = (int)$pdo->lastInsertId();
                $eklenen++;
            }
            if ($alt) $ekle($alt, $id);
        }
    };
    $ekle(IT_LOK_SEED, null);
    return $eklenen;
}

/** Tüm lokasyonlar (id => satır), sira/ad sıralı. İstek başına bir kez okunur. */
function it_lokasyonlar(PDO $pdo, bool $yenile = false): array
{
    static $cache = null;
    if ($cache !== null && !$yenile) return $cache;
    $cache = [];
    try {
        foreach ($pdo->query("SELECT * FROM it_lokasyonlar ORDER BY sira, ad") as $r) $cache[(int)$r['id']] = $r;
    } catch (Throwable $e) {}
    return $cache;
}

/**
 * Ağacı derinlik sırasıyla düzleştirir: [id, satır, derinlik] listesi (select/ağaç görünümü için).
 * @param bool $aktifYalniz pasifler atlanır (formlar); yönetim ekranı hepsini görür
 */
function it_lokasyon_duz(PDO $pdo, bool $aktifYalniz = true): array
{
    $hepsi = it_lokasyonlar($pdo);
    $cocuk = [];
    foreach ($hepsi as $id => $r) $cocuk[(int)($r['ust_id'] ?? 0)][] = $id;
    $out = [];
    $gez = function (int $ust, int $d) use (&$gez, &$out, $cocuk, $hepsi, $aktifYalniz) {
        foreach ($cocuk[$ust] ?? [] as $id) {
            if ($aktifYalniz && !(int)$hepsi[$id]['aktif']) continue;
            $out[] = ['id' => $id, 'r' => $hepsi[$id], 'd' => $d];
            $gez($id, $d + 1);
        }
    };
    $gez(0, 0);
    return $out;
}

/** Lokasyonun tam yolu: "Kartal Batı Yakası Projesi › U030 1. Etap". */
function it_lokasyon_yol(PDO $pdo, ?int $id, string $ayrac = ' › '): string
{
    if (!$id) return '';
    $hepsi = it_lokasyonlar($pdo);
    $parcalar = []; $guard = 0;
    while ($id && isset($hepsi[$id]) && $guard++ < 10) {
        $r = $hepsi[$id];
        array_unshift($parcalar, trim(($r['kod'] ? $r['kod'] . ' ' : '') . $r['ad']));
        $id = (int)($r['ust_id'] ?? 0);
    }
    return implode($ayrac, $parcalar);
}

/** Kısa etiket: "U030 1. Etap" (kod + ad). */
function it_lokasyon_etiket(PDO $pdo, ?int $id): string
{
    $r = it_lokasyonlar($pdo)[$id ?? 0] ?? null;
    return $r ? trim(($r['kod'] ? $r['kod'] . ' ' : '') . $r['ad']) : '';
}

/* ═══════════ TANIMLAR (it_tanimlar): üretici / model / tedarikçi / şirket ═══════════
 * Cihaz kartındaki marka / model / tedarikçi / şirket alanları serbest METİN kalır (eski kayıtlar bozulmasın);
 * bu tablo o alanların ÖNERİ LİSTESİDİR — Tanımlar ekranından yönetilir, formda datalist olarak çıkar.
 * Kategori ve Durum ise sistem sabitidir (IT_KATEGORI / IT_DURUM), Tanımlar'da yalnız sayımlarıyla gösterilir. */
const IT_TANIM_TUR = [
    'uretici'   => ['Üreticiler',  'marka',     'bi-tags'],
    'model'     => ['Modeller',    'model',     'bi-cpu'],
    'tedarikci' => ['Tedarikçiler','tedarikci', 'bi-truck'],
    'sirket'    => ['Şirketler',   'sirket',    'bi-building'],
];

/** Tanım tablosu + lokasyon ek alanları (şehir/adres/renk) + cihazlarda şirket alanı. İdempotent. */
function it_tanim_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_tanimlar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tur VARCHAR(20) NOT NULL,
        ad VARCHAR(150) NOT NULL,
        kod VARCHAR(40) NULL,
        aciklama VARCHAR(255) NULL,
        renk VARCHAR(20) NULL,
        sira INT NOT NULL DEFAULT 0,
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY ix_tur (tur, ad)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['sehir' => "VARCHAR(60) NULL", 'adres' => "VARCHAR(255) NULL", 'renk' => "VARCHAR(20) NULL"] as $kol => $tip) {
        try { $pdo->query("SELECT $kol FROM it_lokasyonlar LIMIT 1"); }
        catch (Throwable $e) { try { $pdo->exec("ALTER TABLE it_lokasyonlar ADD COLUMN $kol $tip"); } catch (Throwable $e2) {} }
    }
    try { $pdo->query("SELECT sirket FROM it_cihazlar LIMIT 1"); }
    catch (Throwable $e) { try { $pdo->exec("ALTER TABLE it_cihazlar ADD COLUMN sirket VARCHAR(120) NULL"); } catch (Throwable $e2) {} }
    it_ek_alan_semasi_kur($pdo);
}

/**
 * Kategoriye özel ek alanların kolonları (IP telefon dahilisi, superbox IMEI'si, NVR disk kapasitesi…).
 * Runtime ALTER — kolon varsa dokunulmaz, eski kayıtlar etkilenmez. Transaction DIŞINDA çağrılmalı (DDL).
 */
function it_ek_alan_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    $kolonlar = [
        'varlik_kodu'       => 'VARCHAR(60) NULL',   // ERP/IFS "Seri Nesne No" — demirbaş kodu (içe aktarma eşleşmesi)
        // ⚠ Kurum içi kısa demirbaş etiketi (M160 / N221). envanter_no BİZİM sabit numaramızdır
        // (IT-00001, tutanaklarda geçer) ve içe aktarma onu ASLA ezmez — kurumsal kod buraya yazılır.
        'cihaz_kodu'        => 'VARCHAR(60) NULL',
        // Snipe-IT'deki varlık id'si ("Kimlik" sütunu) — belge/fotoğraf köprüsü cihazı bununla bulur
        'snipe_id'          => 'INT NULL',
        'sasi_no'           => 'VARCHAR(120) NULL',  // 2. seri numarası (şasi / servis etiketi)
        'islemci'           => 'VARCHAR(160) NULL',
        'ram'               => 'VARCHAR(120) NULL',
        'ekran_karti'       => 'VARCHAR(160) NULL',
        'disk'              => 'VARCHAR(160) NULL',
        'anakart'           => 'VARCHAR(160) NULL',
        'ekran_boyutu'      => 'VARCHAR(40) NULL',
        'kiralik_firma'     => 'VARCHAR(120) NULL',
        'dahili_no'         => 'VARCHAR(20) NULL',
        'telefon_no'        => 'VARCHAR(40) NULL',
        'imei'              => 'VARCHAR(32) NULL',
        'operator'          => 'VARCHAR(60) NULL',
        'firmware'          => 'VARCHAR(80) NULL',
        'lisans_durumu'     => 'VARCHAR(120) NULL',
        'yonetim_kullanici' => 'VARCHAR(60) NULL',
        'yonetim_sifre'     => 'VARCHAR(255) NULL',
        'bagli_id'          => 'INT NULL',
        'kapasite'          => 'VARCHAR(80) NULL',
        'kullanim_amaci'    => 'VARCHAR(150) NULL',
        'adet'              => 'INT NULL',
    ];
    foreach ($kolonlar as $kol => $tip) {
        try { $pdo->query("SELECT $kol FROM it_cihazlar LIMIT 1"); }
        catch (Throwable $e) { try { $pdo->exec("ALTER TABLE it_cihazlar ADD COLUMN $kol $tip"); } catch (Throwable $e2) {} }
    }
}

/** Bir türün tanım listesi (aktifler önce ada göre). */
function it_tanim_liste(PDO $pdo, string $tur, bool $aktifYalniz = false): array
{
    it_tanim_semasi_kur($pdo);
    try {
        $st = $pdo->prepare("SELECT * FROM it_tanimlar WHERE tur=?" . ($aktifYalniz ? " AND aktif=1" : "") . " ORDER BY sira, ad");
        $st->execute([$tur]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Cihaz kartındaki serbest metin alanında bu tanım kaç kez kullanılmış? (ad → adet) */
function it_tanim_kullanim(PDO $pdo, string $kolon): array
{
    $out = [];
    try {
        foreach ($pdo->query("SELECT `$kolon` d, COUNT(*) n FROM it_cihazlar WHERE `$kolon` IS NOT NULL AND `$kolon`<>'' AND " . it_envanterde() . " GROUP BY `$kolon`") as $r)
            $out[it_norm((string)$r['d'])] = (int)$r['n'];
    } catch (Throwable $e) {}
    return $out;
}

/** Form datalist'i: tanım tablosu + cihazlarda geçen mevcut değerler birleşik (tekrarsız, sıralı). */
function it_tanim_oneri(PDO $pdo, string $tur): array
{
    $kolon = IT_TANIM_TUR[$tur][1] ?? null;
    $ad = array_map(fn($r) => $r['ad'], it_tanim_liste($pdo, $tur, true));
    if ($kolon) {
        try { foreach ($pdo->query("SELECT DISTINCT `$kolon` d FROM it_cihazlar WHERE `$kolon` IS NOT NULL AND `$kolon`<>''") as $r) $ad[] = $r['d']; }
        catch (Throwable $e) {}
    }
    $var = []; $out = [];
    foreach ($ad as $a) { $n = it_norm((string)$a); if ($n === '' || isset($var[$n])) continue; $var[$n] = 1; $out[] = $a; }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/** Lokasyon + tüm alt lokasyon id'leri (filtrelerde "proje seçilince etapları da kapsa"). */
function it_lokasyon_altlar(PDO $pdo, int $id): array
{
    $hepsi = it_lokasyonlar($pdo);
    $out = [$id]; $kuyruk = [$id];
    while ($kuyruk) {
        $u = array_shift($kuyruk);
        foreach ($hepsi as $cid => $r) if ((int)($r['ust_id'] ?? 0) === $u && !in_array($cid, $out, true)) { $out[] = $cid; $kuyruk[] = $cid; }
    }
    return $out;
}

/** <select> için lokasyon seçenekleri (girintili). */
function it_lokasyon_options(PDO $pdo, ?int $secili, bool $bosSecenek = true): string
{
    $o = $bosSecenek ? '<option value="">— seçilmedi —</option>' : '';
    foreach (it_lokasyon_duz($pdo) as $x) {
        $r = $x['r'];
        $o .= '<option value="' . (int)$x['id'] . '"' . ((int)$secili === (int)$x['id'] ? ' selected' : '') . '>'
            . str_repeat('&nbsp;&nbsp;&nbsp;', $x['d']) . ($x['d'] ? '↳ ' : '') . h(trim(($r['kod'] ? $r['kod'] . ' — ' : '') . $r['ad'])) . '</option>';
    }
    return $o;
}

/** Personelin görünen adı. */
function it_personel_ad(?array $p): string
{
    return $p ? trim(($p['ad'] ?? '') . ' ' . ($p['soyad'] ?? '')) : '';
}

/** Personel çalışıyor mu? (isten_cikis boş ya da gelecekte) */
function it_personel_aktif(array $p): bool
{
    return empty($p['isten_cikis']) || $p['isten_cikis'] > date('Y-m-d');
}

/** Personel listesi (soyad/ad sıralı). $aktifYalniz=true → ayrılanlar hariç. */
function it_personel_liste(PDO $pdo, bool $aktifYalniz = true): array
{
    try {
        $w = $aktifYalniz ? "WHERE isten_cikis IS NULL OR isten_cikis > CURDATE()" : "";
        return $pdo->query("SELECT * FROM it_personel $w ORDER BY soyad, ad")->fetchAll();
    } catch (Throwable $e) { return []; }
}

function it_personel_bul(PDO $pdo, ?int $id): ?array
{
    if (!$id) return null;
    $st = $pdo->prepare("SELECT * FROM it_personel WHERE id=?"); $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** <select> için personel seçenekleri; data-birim / data-lok ile form otomatik dolar. */
function it_personel_options(PDO $pdo, ?int $secili, bool $ayrilanlarDahil = false): string
{
    $o = '<option value="">— zimmetsiz / seçilmedi —</option>';
    foreach (it_personel_liste($pdo, !$ayrilanlarDahil) as $p) {
        $o .= '<option value="' . (int)$p['id'] . '"' . ((int)$secili === (int)$p['id'] ? ' selected' : '')
            . ' data-birim="' . h($p['birim'] ?? '') . '" data-lok="' . (int)($p['lokasyon_id'] ?? 0) . '">'
            . h(it_personel_ad($p)) . ($p['sicil_no'] ? ' (' . h($p['sicil_no']) . ')' : '') . ($p['unvan'] ? ' — ' . h($p['unvan']) : '')
            . (!it_personel_aktif($p) ? ' [ayrıldı]' : '') . '</option>';
    }
    return $o;
}

/**
 * Cihazın kişi/lokasyon bağını metin alanlarıyla eşitler (tutanak/liste/eski filtreler için).
 * Personel seçiliyse zimmetli=Ad Soyad, departman boşsa birim, lokasyon_id boşsa personelinki.
 */
function it_cihaz_bag_esitle(PDO $pdo, array &$y): void
{
    $p = it_personel_bul($pdo, (int)($y['personel_id'] ?? 0));
    if ($p) {
        $y['zimmetli'] = it_personel_ad($p);
        if (empty($y['departman']) && $p['birim']) $y['departman'] = $p['birim'];
        if (empty($y['lokasyon_id']) && $p['lokasyon_id']) $y['lokasyon_id'] = (int)$p['lokasyon_id'];
    } else {
        $y['personel_id'] = null;
    }
    if (!empty($y['lokasyon_id'])) {
        $yol = it_lokasyon_yol($pdo, (int)$y['lokasyon_id']);
        if ($yol !== '') $y['lokasyon'] = $yol;
    } else { $y['lokasyon_id'] = null; }
}

/** Personelin üzerindeki aktif (hurda hariç, zimmetli) cihazlar. */
/** Cihazın belirli türdeki SON hareketi (tutanak o döneme bağlansın diye). */
function it_son_hareket(PDO $pdo, int $cihazId, string $tur): ?array
{
    try {
        $st = $pdo->prepare("SELECT * FROM it_hareketler WHERE cihaz_id=? AND tur=? ORDER BY id DESC LIMIT 1");
        $st->execute([$cihazId, $tur]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * **ZİMMET DÖNEMLERİ — cihazın el değiştirme zinciri.**
 *
 * Bir cihaz 5-6 kez el değiştirebilir ve HER DÖNEMİN kendi zimmet + iade tutanağı olmalıdır.
 * Yaşam günlüğü düz bir hareket listesi olduğundan "kim, ne zamandan ne zamana kadar kullandı,
 * o dönemin evrakı tam mı" sorusu okunmuyordu; bu fonksiyon hareketleri DÖNEMLERE katlar.
 *
 * Dönem `zimmet` ile açılır; `iade` · `transfer` · `hurda` · `kayip` · `hibe` ile ya da
 * (iade satırı yazılmamışsa) bir sonraki `zimmet` ile kapanır. Belgeler `hareket_id` üzerinden
 * dönemin açılış/kapanış hareketine bağlanır — bağsız (eski) tutanaklar `bagsiz` ile döner.
 *
 * @return array{donemler:array,bagsiz:array}
 */
function it_zimmet_donemleri(PDO $pdo, int $cihazId, ?string $guncelZimmetli = null): array
{
    try {
        $st = $pdo->prepare("SELECT * FROM it_hareketler WHERE cihaz_id=? ORDER BY tarih, id");
        $st->execute([$cihazId]);
        $hareketler = $st->fetchAll();
    } catch (Throwable $e) { return ['donemler' => [], 'bagsiz' => []]; }

    $harBelge = []; $bagsiz = [];
    foreach (it_belgeler($pdo, $cihazId) as $b) {
        if (!in_array($b['tur'] ?? '', IT_BELGE_IMZALI, true)) continue;
        if (!empty($b['hareket_id'])) $harBelge[(int)$b['hareket_id']][] = $b;
        else $bagsiz[] = $b;                      // eski kayıt: hangi döneme ait bilinmiyor
    }

    $kapatan = ['iade', 'transfer', 'hurda', 'kayip', 'hibe'];
    $don = []; $acik = null;
    foreach ($hareketler as $h) {
        $t = (string)$h['tur'];
        if ($t === 'zimmet') {
            // Devir: iade satırı yazılmamışsa önceki dönemi yeni zimmet kapatır
            if ($acik !== null) { $don[$acik]['bit'] = $h['tarih']; $don[$acik]['kapanis'] = $h; }
            $don[] = ['kisi' => (string)($h['kisi'] ?? ''), 'bas' => (string)$h['tarih'],
                      'bit' => null, 'zimmet' => $h, 'kapanis' => null];
            $acik = count($don) - 1;
        } elseif ($acik !== null && in_array($t, $kapatan, true)) {
            $don[$acik]['bit'] = (string)$h['tarih'];
            $don[$acik]['kapanis'] = $h;
            $acik = null;
        }
    }

    $bugun = new DateTimeImmutable('today');
    foreach ($don as $i => &$d) {
        $d['sira']  = $i + 1;
        $d['acik']  = $d['bit'] === null;
        // ⚠ Açık dönemin kişisi cihaz kartındaki güncel zimmetliyle tutmuyorsa (geriye dönük tarihli
        // hareket girilmişse olur) "şu an" demek yanıltır — satır uyarıyla işaretlenir.
        $d['uyusmaz'] = $d['acik'] && $guncelZimmetli !== null
                        && it_norm((string)$guncelZimmetli) !== it_norm((string)$d['kisi']);
        $d['gun']   = null;
        try {
            $bas = new DateTimeImmutable($d['bas']);
            $bit = $d['bit'] ? new DateTimeImmutable($d['bit']) : $bugun;
            $d['gun'] = max(0, (int)$bas->diff($bit)->format('%r%a'));
        } catch (Throwable $e) {}
        $zid = (int)($d['zimmet']['id'] ?? 0);
        $kid = (int)($d['kapanis']['id'] ?? 0);
        $d['zimmet_belge'] = array_values(array_filter($harBelge[$zid] ?? [], fn($b) => $b['tur'] === 'zimmet'));
        // Kapanış belgesi dönemin kapanış TÜRÜNE göre: iade → iade tutanağı, sevk → transfer, düşüş → hurda
        $d['kapanis_belge'] = $kid ? array_values($harBelge[$kid] ?? []) : [];
        $d['kapanis_tur']   = (string)($d['kapanis']['tur'] ?? '');
    }
    unset($d);
    return ['donemler' => $don, 'bagsiz' => $bagsiz];
}

/**
 * Personel ARAMA (zimmet ekranındaki yazarak seçme kutusu).
 *
 * ⚠ Süzme SQL LIKE ile DEĞİL PHP'de `it_norm` ile yapılır — LIKE'ta Türkçe 'İ' ile 'i' eşleşmediğinden
 * "ismail" yazınca "İSMAİL" sessizce düşüyordu (depo `dp_kalem_ara` ile aynı gerekçe). Kelime sırası
 * serbest: "coskun erkan" → "Erkan Coşkun". Sıralama: ad başı → ad içi → sicil/unvan/birim.
 */
function it_personel_ara(PDO $pdo, string $q, int $limit = 25, bool $ayrilanlarDahil = false): array
{
    $kelime = array_values(array_filter(explode(' ', it_norm($q))));
    $sonuc = [];
    foreach (it_personel_liste($pdo, !$ayrilanlarDahil) as $p) {
        $ad   = it_norm(it_personel_ad($p));
        $hepsi = $ad . ' ' . it_norm((string)($p['sicil_no'] ?? '')) . ' ' . it_norm((string)($p['unvan'] ?? ''))
               . ' ' . it_norm((string)($p['birim'] ?? ''));
        $puan = 0;
        if ($kelime) {
            foreach ($kelime as $k) if (!str_contains($hepsi, $k)) { $puan = -1; break; }
            if ($puan < 0) continue;
            $puan = str_starts_with($ad, $kelime[0]) ? 0 : (str_contains($ad, $kelime[0]) ? 1 : 2);
        }
        $p['puan'] = $puan;
        $sonuc[] = $p;
    }
    usort($sonuc, fn($a, $b) => [$a['puan'], it_norm(it_personel_ad($a))] <=> [$b['puan'], it_norm(it_personel_ad($b))]);
    return array_slice($sonuc, 0, $limit);
}

/**
 * Personel LİSTESİ süzme (personel.php arama kutusu).
 *
 * ⚠ Neden SQL LIKE değil: (1) `p.ad LIKE '%Fırat Acı%' OR p.soyad LIKE …` ad ve soyadı AYRI AYRI
 * karşılaştırdığı için "Fırat Acı" yazınca HİÇBİR kayıt bulunamıyordu — arama metni tek bir alanın
 * içinde geçmek zorundaydı; (2) LIKE'ta Türkçe 'İ' ile 'i' eşleşmez, "ismail" yazan "İSMAİL"i
 * bulamıyordu. Süzme `it_norm` ile PHP'de yapılır, **kelime sırası serbest** ("acı fırat" da bulur)
 * ve her kelime ad+soyad+sicil+unvan+birim+telefon+e-posta bütününde aranır.
 *
 * @param array $liste it_personel satırları
 */
function it_personel_suz(array $liste, string $q): array
{
    $kelime = array_values(array_filter(explode(' ', it_norm($q))));
    if (!$kelime) return $liste;
    return array_values(array_filter($liste, function ($p) use ($kelime) {
        $hepsi = it_norm(it_personel_ad($p)) . ' '
               . it_norm((string)($p['sicil_no'] ?? '')) . ' ' . it_norm((string)($p['unvan'] ?? '')) . ' '
               . it_norm((string)($p['birim'] ?? ''))    . ' ' . it_norm((string)($p['telefon'] ?? '')) . ' '
               . it_norm((string)($p['eposta'] ?? ''))   . ' ' . it_norm((string)($p['lok_ad'] ?? ''));
        foreach ($kelime as $k) if (!str_contains($hepsi, $k)) return false;
        return true;
    }));
}

/**
 * Personel kayıtlarını BÜYÜK HARFE çevirir (ad · soyad · unvan · birim) ve bağlı cihazlardaki
 * `zimmetli` / `departman` METİN alanlarını da eşitler (tutanak ve listeler oradan okur).
 *
 * @return array{kisi:int, cihaz:int} değişen kayıt sayıları
 */
function it_personel_buyuk_harf(PDO $pdo): array
{
    $kisi = 0; $cihaz = 0;
    $g = $pdo->prepare("UPDATE it_personel SET ad=?, soyad=?, unvan=?, birim=? WHERE id=?");
    foreach ($pdo->query("SELECT id, ad, soyad, unvan, birim FROM it_personel")->fetchAll() as $r) {
        $yeni = [it_buyuk($r['ad']), it_buyuk($r['soyad']), it_buyuk($r['unvan']), it_buyuk($r['birim'])];
        $eski = [(string)$r['ad'], (string)$r['soyad'], (string)$r['unvan'], (string)$r['birim']];
        // NULL alan NULL kalsın (boş string yazıp "dolu" göstermeyelim)
        foreach ($yeni as $i => $v) if ($v === '') $yeni[$i] = $eski[$i] === '' ? $eski[$i] : $v;
        if ($yeni === $eski) continue;
        $g->execute([$yeni[0], $yeni[1], $yeni[2] ?: null, $yeni[3] ?: null, (int)$r['id']]);
        $kisi++;
        $c = $pdo->prepare("UPDATE it_cihazlar SET zimmetli=?, departman=? WHERE personel_id=?");
        $c->execute([trim($yeni[0] . ' ' . $yeni[1]), $yeni[3] ?: null, (int)$r['id']]);
        $cihaz += $c->rowCount();
    }
    return ['kisi' => $kisi, 'cihaz' => $cihaz];
}

function it_personel_cihazlari(PDO $pdo, int $personelId): array
{
    $st = $pdo->prepare("SELECT * FROM it_cihazlar WHERE personel_id=? AND " . it_envanterde() . " ORDER BY envanter_no");
    $st->execute([$personelId]);
    return $st->fetchAll();
}
