<?php
/**
 * it/_form_import.php — CİHAZ TAHSİS FORMU okuyucu (fim_*)
 *
 * Kurumsal "TAHSİS FORMU" dosyaları **tablo değil FORM**dur: bir dosya = BİR cihaz, alanlar
 * alt alta "etiket | değer" olarak durur (Cihaz Kodu · Marka · Serial · İşlemci · Ram …).
 * `_cihaz_import.php` (cim_*) satır-sütun ızgarası bekler, bu düzeni okuyamaz — bu yüzden ayrı okuyucu.
 *
 * Dosya okuma / normalize / lokasyon-personel eşleştirme yine ORTAK katmandan gelir
 * (`_import.php`: pim_norm/pim_tarih/pim_lokasyon_bul · `_cihaz_import.php`: cim_kategori/cim_marka/cim_fiyat),
 * böylece üç içe aktarma aynı davranır ve tek yerde düzeltilir.
 *
 * Akış: `fim_oku()` (dosya → etiket-değer çiftleri) → `fim_cozumle()` (çiftler → cihaz alanları)
 *       → `fim_import()` (eşleştir + güncelle/ekle + formu belge olarak bağla).
 */

/**
 * Form etiketi → cihaz alanı. Anahtarlar **NORMALİZE** yazılır (`pim_norm`: Türkçe harf ASCII'ye
 * katlanır, noktalama boşluğa döner) — "Harddisk Kapasitesi" → `HARDDISK KAPASITESI`.
 * ⚠ Tireli/parantezli yazım eş anlamlı olarak İŞE YARAMAZ, normalize hâli yazılmalı.
 * Birden çok etiket aynı alana bakabilir; `FIM_COKLU` alanlarda değerler " · " ile BİRLEŞİR.
 */
const FIM_ALAN = [
    'cihaz_kodu'   => ['CIHAZ KODU', 'DEMIRBAS KODU', 'DEMIRBAS NO', 'CIHAZ NO', 'ETIKET NO'],
    'varlik_kodu'  => ['IFS KOD', 'IFS KODU', 'NESNE NO', 'SERI NESNE NO', 'VARLIK KODU'],
    'envanter_no'  => ['ENVANTER NO'],
    'kategori'     => ['TIP', 'TIPI', 'CIHAZ TIPI', 'CIHAZ TURU', 'KATEGORI'],
    'marka'        => ['MARKA', 'MARKASI'],
    'model'        => ['MODEL', 'MODELI'],
    'seri_no'      => ['SERIAL', 'SERI NO', 'SERI NUMARASI', 'SERIAL NO', 'SERIAL NUMBER'],
    'sasi_no'      => ['SASI NO', 'SERVIS ETIKETI', 'SERVICE TAG'],
    'imei'         => ['IMEI', 'IMEI NO'],
    'alis_tarihi'  => ['ALIS TARIHI', 'SATIN ALMA TARIHI', 'FATURA TARIHI'],
    'tedarikci'    => ['TEDARIKCI', 'SATICI', 'FIRMA'],
    'fatura_no'    => ['FATURA NO', 'FATURANO'],
    'fiyat'        => ['FIYAT', 'TUTAR', 'BEDEL'],
    'lokasyon'     => ['LOKASYON', 'BULUNDUGU YER', 'KONUM', 'PROJE'],
    'departman'    => ['BIRIM', 'DEPARTMAN', 'BOLUM', 'KULLANILAN BIRIM'],
    'isletim'      => ['ISLETIM SISTEMI', 'ISLETIM SISTEMI SURUMU'],
    'islemci'      => ['ISLEMCI', 'CPU'],
    'ram'          => ['RAM', 'BELLEK'],
    'ekran_karti'  => ['EKRAN KARTI', 'GPU', 'GRAFIK KARTI'],
    'anakart'      => ['ANAKART', 'ANA KART', 'MAINBOARD'],
    'disk'         => ['HARDDISK', 'HARDDISK MARKASI', 'HARDDISK MODELI', 'HARDDISK KAPASITESI', 'DISK', 'SABIT DISK'],
    'disk_seri'    => ['HARDDISK SERIAL', 'DISK SERI NO', 'HDD SERIAL'],
    'ekran_boyutu' => ['EBAT', 'EKRAN BOYUTU'],
    'cozunurluk'   => ['COZUNURLUK', 'EKRAN COZUNURLUGU'],
    'ip_adresi'    => ['IP', 'IP ADRESI'],
    'mac_adresi'   => ['MAC', 'MAC ADRESI'],
    // Kişi: formun "Kullanıcı Bilgileri" bloğu — cihaz bu kişiye zimmetlidir
    'kisi'         => ['AD SOYAD', 'ADI SOYADI', 'KULLANICI', 'KULLANICI ADI', 'ZIMMETLI', 'PERSONEL'],
    'eposta'       => ['MAIL ADRESI', 'E POSTA', 'EPOSTA', 'MAIL'],
    'sicil_no'     => ['SICIL NO', 'SICIL', 'PERSONEL NO'],
    // Serbest/teknik notlar — birden çok etiketten birikir
    'ozellik'      => ['ETHERNET', 'KART OKUYUCU', 'YUKLU YAZILIMLAR', 'TURU', 'TUR', 'OZELLIK', 'TEKNIK OZELLIK'],
    'notlar'       => ['ACIKLAMA', 'NOT', 'NOTLAR'],
];

/** Değerleri " · " ile BİRLEŞEN alanlar (disk = marka + model + kapasite). */
const FIM_COKLU = ['disk', 'ozellik', 'notlar'];

/**
 * Formdaki tip KISALTMALARI (sağdaki efsane sütunundan): "B - Bilgisayar", "N - Notebook"…
 * Değer bu kısaltmalardan biriyse IT_KATEGORI anahtarına çevrilir; değilse `cim_kategori()`
 * metinden çıkarır ("Bilgisayar" → bilgisayar).
 */
const FIM_TIP_KOD = ['B' => 'bilgisayar', 'N' => 'laptop', 'M' => 'monitor', 'YF' => 'yazici', 'D' => 'diger'];

/** Bir formun DOLU sayılması için gereken en az etiket eşleşmesi (yabancı sayfa ayıklansın). */
const FIM_MIN_ESLESME = 3;

/**
 * Hücredeki etiketi alan anahtarına çevirir. Etiket sonundaki ':' ve boşluk atılır.
 * ⚠ Yalnız BİREBİR eşleşme — gevşek eşleşme "Harddisk Serial"ı `disk`e düşürürdü.
 */
function fim_etiket(string $hucre): ?string
{
    $n = pim_norm(rtrim(trim($hucre), ':'));
    if ($n === '') return null;
    foreach (FIM_ALAN as $alan => $etiketler) if (in_array($n, $etiketler, true)) return $alan;
    return null;
}

/** Metin bir form etiketi mi? (değer hücresinin yanlışlıkla etiket olmasını engeller) */
function fim_etiket_mi(string $hucre): bool { return fim_etiket($hucre) !== null; }

/**
 * Bir sayfadan etiket→değer çiftlerini toplar.
 *
 * ⚠⚠ **DEĞER YALNIZ SONRAKİ BİRKAÇ SÜTUNDA ARANIR** (`$pencere`, varsayılan 3): gerçek formda
 * sağda bir de TİP EFSANESİ sütunu var ("B - Bilgisayar", "D - Diğer"…). "Marka" satırının
 * değeri BOŞKEN sınırsız sağa bakan bir okuyucu efsaneyi değer sanıp **marka = "D - Diğer"**
 * yazıyordu. Pencere, etiketin hemen yanındaki değer alanıyla sınırlı tutar.
 *
 * @return array{ciftler: array<int,array{alan:string,etiket:string,deger:string}>, puan:int}
 */
function fim_sayfa_ciftleri(array $satirlar, int $pencere = 3): array
{
    $ciftler = []; $puan = 0;
    foreach ($satirlar as $satir) {
        if (!is_array($satir)) continue;
        $anahtarlar = array_keys($satir);
        foreach ($anahtarlar as $ci) {
            $etiket = trim((string)($satir[$ci] ?? ''));
            if ($etiket === '') continue;
            $alan = fim_etiket($etiket);
            if ($alan === null) continue;
            $deger = '';
            for ($k = 1; $k <= $pencere; $k++) {
                $v = trim((string)($satir[$ci + $k] ?? ''));
                if ($v === '') continue;
                // Yandaki hücre başka bir ETİKETSE bu satırda değer yok (yatay başlık satırı)
                if (fim_etiket_mi($v)) break;
                $deger = $v; break;
            }
            $puan++;                                   // etiket bulundu (değeri boş olsa da form belirtisi)
            if ($deger === '') continue;
            $ciftler[] = ['alan' => $alan, 'etiket' => trim($etiket), 'deger' => $deger];
        }
    }
    return ['ciftler' => $ciftler, 'puan' => $puan];
}

/**
 * Dosyadan form çiftlerini okur (.xlsx · .pdf · düz metin).
 *
 * ⚠ Kurumsal form dosyasında çoğu zaman **birden çok sayfa** olur: asıl form + Excel'in kendi
 * İngilizce envanter şablonu ("Home Contents Inventory List" — Insurance policy number…).
 * Bu yüzden sayfalar PUANLANIR ve en çok form etiketi taşıyan seçilir; "TAHSİS FORMU" başlığı
 * taşıyan sayfa her hâlükârda tercih edilir.
 *
 * @return array{ciftler:array, sayfa:?string, bicim:string, kaynak:?string}
 * @throws RuntimeException okunamayan dosya
 */
function fim_oku(string $yol, string $ad): array
{
    $uz = strtolower(pathinfo($ad, PATHINFO_EXTENSION));

    if ($uz === 'pdf') {
        // pdftotext (varsa) → AI belge okuma; ikisi de yoksa anlaşılır hata
        if (!function_exists('fat_dosyadan_metin')) {
            $fat = __DIR__ . '/../includes/fatura.php';
            if (is_file($fat)) require_once $fat;
        }
        if (!function_exists('fat_dosyadan_metin')) throw new RuntimeException('PDF okuma katmanı yüklenemedi.');
        $kaynak = null;
        $metin = fat_dosyadan_metin($yol, 'application/pdf', $kaynak);
        if ($metin === null || trim($metin) === '') {
            throw new RuntimeException('PDF okunamadı (sunucuda pdftotext yok ve AI belge okuma kapalı). Formu .xlsx olarak kaydedip yükleyin.');
        }
        $s = fim_metin_ciftleri($metin);
        if ($s['puan'] < FIM_MIN_ESLESME) throw new RuntimeException('PDF okundu ama cihaz tahsis formu alanları bulunamadı.');
        return ['ciftler' => $s['ciftler'], 'sayfa' => null, 'bicim' => 'pdf', 'kaynak' => $kaynak];
    }

    if (in_array($uz, ['txt', 'csv'], true)) {
        $metin = (string)file_get_contents($yol);
        $s = fim_metin_ciftleri($metin);
        if ($s['puan'] < FIM_MIN_ESLESME) throw new RuntimeException('Bu dosya bir cihaz tahsis formuna benzemiyor (tanınan alan bulunamadı).');
        return ['ciftler' => $s['ciftler'], 'sayfa' => null, 'bicim' => $uz, 'kaynak' => null];
    }

    if (!in_array($uz, ['xlsx', 'xlsm'], true)) {
        throw new RuntimeException('Desteklenmeyen dosya türü (.' . $uz . '). Form .xlsx ya da .pdf olmalı.');
    }
    if (!class_exists('Shuchkin\\SimpleXLSX')) require_once __DIR__ . '/../vendor/autoload.php';
    $x = Shuchkin\SimpleXLSX::parse($yol);
    if (!$x) throw new RuntimeException('Excel açılamadı: ' . Shuchkin\SimpleXLSX::parseError());

    $enIyi = null;
    foreach ($x->sheetNames() as $i => $sayfaAd) {
        $satirlar = $x->rows($i);
        if (!$satirlar) continue;
        $s = fim_sayfa_ciftleri($satirlar);
        // "TAHSİS FORMU" başlığı taşıyan sayfa her zaman tercih edilir
        $formMu = false;
        foreach ($satirlar as $r) foreach ((array)$r as $v)
            if (str_contains(pim_norm((string)$v), 'TAHSIS FORMU')) { $formMu = true; break 2; }
        $s['puan'] += $formMu ? 100 : 0;
        $s['sayfa'] = (string)$sayfaAd;
        if ($enIyi === null || $s['puan'] > $enIyi['puan']) $enIyi = $s;
    }
    if ($enIyi === null || $enIyi['puan'] < FIM_MIN_ESLESME) {
        throw new RuntimeException('Bu dosya bir cihaz tahsis formuna benzemiyor (tanınan alan bulunamadı).');
    }
    return ['ciftler' => $enIyi['ciftler'], 'sayfa' => $enIyi['sayfa'], 'bicim' => 'xlsx', 'kaynak' => null];
}

/** Düz metinden (PDF çıktısı) "Etiket : Değer" / "Etiket   Değer" satırlarını çıkarır. */
function fim_metin_ciftleri(string $metin): array
{
    $ciftler = []; $puan = 0;
    foreach (preg_split('/\R/u', $metin) as $satir) {
        $satir = trim($satir);
        if ($satir === '') continue;
        // "Etiket: değer" ya da "Etiket<2+ boşluk>değer"
        if (!preg_match('/^(.{2,40}?)\s*(?::|\s{2,})\s*(.+)$/u', $satir, $m)) continue;
        $alan = fim_etiket($m[1]);
        if ($alan === null) continue;
        $puan++;
        $deger = trim($m[2]);
        if ($deger === '' || fim_etiket_mi($deger)) continue;
        $ciftler[] = ['alan' => $alan, 'etiket' => trim($m[1]), 'deger' => $deger];
    }
    return ['ciftler' => $ciftler, 'puan' => $puan];
}

/**
 * Etiket-değer çiftlerini cihaz alanlarına çevirir.
 * Tek sütunlu alanlarda İLK dolu değer kalır (form aynı etiketi iki kez taşıyabilir);
 * `FIM_COKLU` alanlarda değerler "Başlık: değer" olarak birikir (disk markası + modeli + kapasitesi).
 *
 * @param ?string $sayfaAd Sayfa adı — kategori ipucu ("Bilgisayar", "Notebook")
 * @return array ham alanlar (kategori çözülmüş, tarih/fiyat normalize)
 */
function fim_cozumle(array $ciftler, ?string $sayfaAd = null): array
{
    $v = array_fill_keys(array_keys(FIM_ALAN), '');
    foreach (FIM_COKLU as $k) $v[$k] = [];

    foreach ($ciftler as $c) {
        $alan = $c['alan']; $deger = trim((string)$c['deger']);
        if ($deger === '') continue;
        if (in_array($alan, FIM_COKLU, true)) {
            // "Harddisk Markası: Seagate" gibi — hangi etiketten geldiği görünsün
            $v[$alan][] = trim($c['etiket']) . ': ' . $deger;
            continue;
        }
        if ($v[$alan] === '') $v[$alan] = $deger;
    }
    foreach (FIM_COKLU as $k) $v[$k] = $v[$k] ? implode(' · ', array_unique($v[$k])) : '';

    // ── Kategori: önce form kısaltması (B/N/M/YF/D), sonra metin, sonra SAYFA ADI
    $kat = '';
    $ham = trim((string)$v['kategori']);
    if ($ham !== '') {
        $kod = strtoupper(trim(explode('-', str_replace('–', '-', $ham))[0]));
        if (isset(FIM_TIP_KOD[$kod])) $kat = FIM_TIP_KOD[$kod];
        elseif (function_exists('cim_kategori')) $kat = cim_kategori($ham);
    }
    if (($kat === '' || $kat === 'diger') && $sayfaAd && function_exists('cim_kategori')) {
        $s = cim_kategori($sayfaAd);
        if ($s !== 'diger') $kat = $s;
    }
    $v['kategori'] = $kat ?: 'diger';

    $v['alis_tarihi'] = pim_tarih($v['alis_tarihi']);
    if (function_exists('cim_marka') && $v['marka'] !== '') $v['marka'] = cim_marka($v['marka']);
    if (function_exists('cim_fiyat')) $v['fiyat'] = cim_fiyat((string)$v['fiyat']);
    $v['eposta'] = mb_strtolower(trim((string)$v['eposta']), 'UTF-8');
    if ($v['eposta'] !== '' && !filter_var($v['eposta'], FILTER_VALIDATE_EMAIL)) $v['eposta'] = '';
    if (function_exists('cim_kisi_ad') && $v['kisi'] !== '') $v['kisi'] = cim_kisi_ad($v['kisi']);
    return $v;
}

/**
 * Çözümlenmiş formu `it_cihazlar` alanlarına eşler (ham metin → kolon değerleri).
 * Kişi/lokasyon bağı `fim_import` içinde kurulur (DB gerektirir).
 */
function fim_cihaz_alanlari(array $v): array
{
    $bos = fn($s) => trim((string)$s) !== '' ? trim((string)$s) : null;
    $y = [
        'kategori'        => $v['kategori'] ?: 'diger',
        'cihaz_kodu'      => $bos($v['cihaz_kodu']),
        'varlik_kodu'     => $bos($v['varlik_kodu']),
        'marka'           => $bos($v['marka']),
        'model'           => $bos($v['model']),
        'seri_no'         => $bos($v['seri_no']),
        'sasi_no'         => $bos($v['sasi_no']),
        'imei'            => $bos($v['imei']),
        'alis_tarihi'     => $v['alis_tarihi'] ?: null,
        'tedarikci'       => $bos($v['tedarikci']),
        'fatura_no'       => $bos($v['fatura_no']),
        'isletim_sistemi' => $bos($v['isletim']),
        'islemci'         => $bos($v['islemci']),
        'ram'             => $bos($v['ram']),
        'ekran_karti'     => $bos($v['ekran_karti']),
        'anakart'         => $bos($v['anakart']),
        'disk'            => $bos($v['disk']),
        'disk_seri'       => $bos($v['disk_seri']),
        'ekran_boyutu'    => $bos($v['ekran_boyutu']),
        'cozunurluk'      => $bos($v['cozunurluk']),
        'ip_adresi'       => $bos($v['ip_adresi']),
        'mac_adresi'      => $bos($v['mac_adresi']),
    ];
    if ($v['fiyat'] !== null && $v['fiyat'] !== '') $y += it_fiyat_coz($v['fiyat'], 'TRY', 1);
    // Seri no boşsa şasi seri sayılır (aramada bulunsun) — cihaz içe aktarmayla aynı kural
    if (!$y['seri_no'] && $y['sasi_no']) $y['seri_no'] = $y['sasi_no'];
    foreach ($y as $k => $x) if ($x === null) unset($y[$k]);
    return $y;
}

/**
 * Çözümlenmiş formları `it_cihazlar`'a işler.
 *
 * **Eşleşme sırası** cihaz kodu → IFS nesne no → seri no → envanter no (cihaz içe aktarmayla aynı).
 * Eşleşen cihazda **yalnız formda DOLU gelen alanlar** güncellenir; boş hücre mevcut veriyi SİLMEZ.
 * `$opt['bos_doldur']` açıksa dolu alanlara hiç dokunulmaz (eski tarihli form yeni veriyi ezmesin).
 * Form dosyası cihaza **belge olarak bağlanır** ve yaşam günlüğüne satır yazılır.
 *
 * @param array $dosyalar [['ad'=>, 'yol'=>, 'v'=>fim_cozumle çıktısı, 'sayfa'=>], …]
 * @param array $opt bos_doldur · personel_ekle · belge_tur ('belge'|'zimmet') · kullanici
 */
function fim_import(PDO $pdo, array $dosyalar, array $opt = []): array
{
    $r = ['okunan' => 0, 'yeni' => [], 'guncellenen' => [], 'degismeyen' => 0, 'atlanan' => [],
          'kisi_yok' => [], 'belge' => 0, 'kisi_eklenen' => []];
    $bosDoldur  = !empty($opt['bos_doldur']);
    $belgeTur   = ($opt['belge_tur'] ?? 'belge') === 'zimmet' ? 'zimmet' : 'belge';
    $kullanici  = $opt['kullanici'] ?? null;

    cim_semasi_kur($pdo);                    // ⚠ DDL transaction'ı örtük commit eder → ÖNCE
    it_ek_alan_semasi_kur($pdo);

    $mevcut = $pdo->query("SELECT * FROM it_cihazlar")->fetchAll();
    $ix = ['kod' => [], 'ifs' => [], 'seri' => [], 'env' => []];
    foreach ($mevcut as $m) {
        foreach ([['kod', 'cihaz_kodu'], ['ifs', 'varlik_kodu'], ['seri', 'seri_no'], ['env', 'envanter_no']] as [$a, $kol]) {
            $k = pim_norm((string)($m[$kol] ?? ''));
            if ($k !== '' && !isset($ix[$a][$k])) $ix[$a][$k] = $m;
        }
    }

    foreach ($dosyalar as $d) {
        $r['okunan']++;
        $per = null; $nasilKisi = ''; $cihazId = 0;      // ⚠ önceki dosyadan sızmasın
        $v   = $d['v'];
        $ad  = (string)($d['ad'] ?? 'form');
        $yy  = fim_cihaz_alanlari($v);

        // ── Eşleşme
        $m = null; $nasil = '';
        foreach ([['cihaz_kodu', 'kod', 'cihaz kodu'], ['varlik_kodu', 'ifs', 'IFS nesne no'],
                  ['seri_no', 'seri', 'seri no'], ['envanter_no', 'env', 'envanter no']] as [$alan, $a, $et]) {
            $k = pim_norm((string)($yy[$alan] ?? ''));
            if ($k !== '' && isset($ix[$a][$k])) { $m = $ix[$a][$k]; $nasil = $et; break; }
        }
        if (!$m && !array_filter([$yy['cihaz_kodu'] ?? '', $yy['varlik_kodu'] ?? '', $yy['seri_no'] ?? ''])) {
            $r['atlanan'][] = ['dosya' => $ad, 'neden' => 'formda cihaz kodu / IFS no / seri no yok — hangi cihaz olduğu belirlenemedi'];
            continue;
        }

        // ── Kişi ve lokasyon bağı
        $personelId = null; $kisiAd = trim((string)($v['kisi'] ?? ''));
        if ($kisiAd !== '') {
            // Önce SİCİL (aynı adlı kişiler ayrışır), sonra normalize ad+soyad
            $per = null;
            $sicil = trim((string)($v['sicil_no'] ?? ''));
            if ($sicil !== '') $per = cim_personel_sicil($pdo, $sicil);
            if (!$per) { [$per, $nasilKisi] = cim_personel_bul($pdo, $kisiAd); }
            if (!$per && !empty($opt['personel_ekle'])) {
                // Kartı aç ve önbelleği tazeleyerek yeniden bul
                if (cim_personel_ekle($pdo, [['ad' => $kisiAd, 'sicil' => $sicil]])) {
                    $r['kisi_eklenen'][] = $kisiAd;
                    [$per, ] = cim_personel_bul($pdo, $kisiAd, true);
                }
            }
            if ($per) $personelId = (int)$per['id'];
            else $r['kisi_yok'][] = ['dosya' => $ad, 'kisi' => $kisiAd,
                                     'neden' => ($nasilKisi ?? '') === 'coklu' ? 'aynı adlı birden çok personel — elle bağlayın' : 'personel kartı yok'];
        }
        $lokId = null;
        if (trim((string)($v['lokasyon'] ?? '')) !== '' && function_exists('pim_lokasyon_bul')) {
            $lokId = pim_lokasyon_bul($pdo, (string)$v['lokasyon']) ?: null;
        }
        if ($personelId && !empty($per)) {
            $yy['personel_id'] = $personelId;
            $yy['zimmetli']    = it_buyuk(it_personel_ad($per));
            $yy['durum']       = 'aktif';
            if (trim((string)($per['birim'] ?? '')) !== '') $yy['departman'] = (string)$per['birim'];
        }
        if ($lokId) $yy['lokasyon_id'] = $lokId;
        if (trim((string)($v['departman'] ?? '')) !== '') $yy['departman'] = it_buyuk((string)$v['departman']);

        if ($m) {
            // ── GÜNCELLE: yalnız formda dolu gelen alanlar
            $set = []; $par = []; $degisen = [];
            foreach ($yy as $kol => $yeni) {
                if ($kol === 'kategori' && ($m['kategori'] ?? '') !== 'diger' && $yeni === 'diger') continue;
                $eski = $m[$kol] ?? null;
                if ($bosDoldur && trim((string)$eski) !== '') continue;      // dolu alana dokunma
                if (trim((string)$eski) === trim((string)$yeni)) continue;
                $set[] = "`$kol`=?"; $par[] = $yeni;
                $degisen[] = $kol . ': ' . (trim((string)$eski) === '' ? '(boş)' : mb_substr((string)$eski, 0, 30))
                           . ' → ' . mb_substr((string)$yeni, 0, 30);
            }
            $cihazId = (int)$m['id'];
            if ($set) {
                $par[] = $cihazId;
                $pdo->prepare("UPDATE it_cihazlar SET " . implode(', ', $set) . " WHERE id=?")->execute($par);
                $r['guncellenen'][] = ['dosya' => $ad, 'id' => $cihazId, 'kim' => trim(($m['cihaz_kodu'] ?? '') . ' ' . ($m['ad'] ?? '')),
                                       'nasil' => $nasil, 'degisen' => $degisen];
                it_hareket_ekle($pdo, $cihazId, 'guncelleme', $kisiAd ?: null,
                    'Tahsis formundan güncellendi (' . $ad . ')', null);
            } else $r['degismeyen']++;
        } else {
            // ── YENİ CİHAZ
            $yy['envanter_no'] = it_envanter_no($pdo);
            $yy['ad'] = $yy['ad'] ?? trim((string)($yy['marka'] ?? '') . ' ' . (string)($yy['model'] ?? ''));
            if (trim((string)$yy['ad']) === '') $yy['ad'] = it_kategoriAd($yy['kategori']);
            $yy['durum'] = $yy['durum'] ?? 'depoda';
            $kolonlar = array_keys($yy);
            $pdo->prepare("INSERT INTO it_cihazlar (" . implode(',', array_map(fn($k) => "`$k`", $kolonlar)) . ") VALUES ("
                          . implode(',', array_fill(0, count($kolonlar), '?')) . ")")->execute(array_values($yy));
            $cihazId = (int)$pdo->lastInsertId();
            $r['yeni'][] = ['dosya' => $ad, 'id' => $cihazId, 'kim' => trim(($yy['cihaz_kodu'] ?? '') . ' ' . $yy['ad'])];
            it_hareket_ekle($pdo, $cihazId, 'giris', $yy['tedarikci'] ?? null,
                'Tahsis formuyla envantere eklendi (' . $ad . ')', $yy['alis_tarihi'] ?? null);
            if ($personelId) it_hareket_ekle($pdo, $cihazId, 'zimmet', $yy['zimmetli'] ?? null, 'Tahsis formundaki kullanıcı', null);
            // Sonraki dosyalar bu cihazı bulabilsin
            foreach ([['kod', 'cihaz_kodu'], ['ifs', 'varlik_kodu'], ['seri', 'seri_no'], ['env', 'envanter_no']] as [$a, $kol]) {
                $k = pim_norm((string)($yy[$kol] ?? ''));
                if ($k !== '') $ix[$a][$k] = ['id' => $cihazId] + $yy;
            }
        }

        // ── Formun kendisi cihaza belge olarak bağlanır (aynı bayt ikinci kez eklenmez)
        if (!empty($d['yol']) && is_file($d['yol']) && !empty($cihazId)) {
            try {
                // ⚠ Aynı form ikinci kez yüklenirse belge MÜKERRER eklenmesin (bayt karşılaştırması)
                $md5 = md5_file($d['yol']);
                if (!isset(it_belge_md5ler($pdo, (int)$cihazId)[$md5])) {
                    [$ok, ] = it_belge_kaydet($pdo, (int)$cihazId, $d['yol'], $ad, $kullanici, $belgeTur, false, null);
                    if ($ok) $r['belge']++;
                }
            } catch (Throwable $e) {}
        }
    }
    return $r;
}
