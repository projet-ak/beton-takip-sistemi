<?php
/**
 * it/_cihaz_import.php — CİHAZ (demirbaş) listesi içe aktarma çekirdeği
 *
 * Kaynak: kurumsal envanter/ERP çıktısı — ör. "Hızlı Rapor 192 Zimmet Edilen Demirbaş Listesi"
 * (Cıhaz Kodu · Serı Nesne Kodu · Serı Nesne Adı · Ilk Proje · Mevcut Proje · Kısı · Marka · Model ·
 *  Sası No · Serı No · İşlemci/RAM/Ekran kartı/HDD sütunları).
 *
 * Dosya okuma, başlık satırı bulma ve normalize işleri PERSONEL aktarımıyla ORTAK (`_import.php`:
 * pim_oku / pim_baslik_satiri / pim_norm / pim_tarih / pim_lokasyon_bul) — iki içe aktarma aynı
 * davranır, tek yerde düzeltilir.
 *
 * BİRLEŞTİRME (tam yenileme YOK — cihazın geçmişi/belgesi silinmemeli):
 *   eşleşme sırası **varlık kodu → envanter no → seri no**; yeni satır → INSERT (+ "giris" hareketi),
 *   mevcut → yalnız dosyada DOLU gelen alanlar güncellenir (boş hücre mevcut veriyi silmez),
 *   zimmet değişirse `it_hareketler`'e zimmet/iade satırı yazılır. Aynı dosya iki kez yüklense de
 *   mükerrer cihaz oluşmaz.
 */

require_once __DIR__ . '/_import.php';   // pim_* yardımcıları (ortak okuma/normalize katmanı)

const CIM_ALAN = [
    'varlik_kodu'  => ['etiket' => 'Varlık / Seri Nesne Kodu', 'es' => ['SERI NESNE KODU','SERINESNE KODU','IFS SERI NESNE NO','IFS NESNE NO','IFS CIHAZ KODU','IFS KOD','IFS KODU','NESNE NO','VARLIK KODU','ZIMMET KODU','ASSET CODE','ASSET ID','BARKOD','BARKOD NO','ETIKET NO','TAG']],
    'cihaz_kodu'   => ['etiket' => 'Cihaz Kodu (demirbaş etiketi)', 'es' => ['CIHAZ KODU','CIHAZKODU','CIHAZ NO','DEMIRBAS ETIKETI','ASSET TAG','DEMIRBAS KODU','DEMIRBAS NO','DEMIRBAS SIRA','ETIKET','SIRA KODU','DEVICE CODE','DEVICE ID']],
    'envanter_no'  => ['etiket' => 'Envanter No (IT-00001)',   'es' => ['ENVANTER NO','ENVANTER KODU','INVENTORY NO']],
    'ad'           => ['etiket' => 'Cihaz Adı / Cinsi',        'es' => ['SERI NESNE ADI','SERINESNE ADI','DEMIRBAS ADI','CIHAZ ADI','CIHAZ CINSI','CINSI','MALZEME ADI','URUN ADI','TANIM','ACIKLAMA ADI','ITEM','ITEM NAME','ASSET NAME','DESCRIPTION']],
    'kategori'     => ['etiket' => 'Kategori',                 'es' => ['KATEGORI','KATEGORISI','TUR','TURU','CIHAZ TURU','CIHAZ TIPI','CIHAZ CINSI TIPI','GRUP','GRUBU','CATEGORY','TYPE']],
    'marka'        => ['etiket' => 'Marka',                    'es' => ['MARKA','MARKASI','BRAND','MANUFACTURER','URETICI']],
    'model'        => ['etiket' => 'Model',                    'es' => ['MODEL','MODELI','MODEL ADI','MODEL ISMI']],
    'model_no'     => ['etiket' => 'Model No (gerçek model)',  'es' => ['MODEL NO','MODEL NO.','MODEL KODU','MODEL NUMARASI','MODEL NUMBER']],
    'seri_no'      => ['etiket' => 'Seri No',                  'es' => ['SERI NO','SERINO','SERI NUMARASI','SERIAL','SERIAL NO','SERIAL NUMBER','SN']],
    'sasi_no'      => ['etiket' => 'Şasi No (2. seri)',        'es' => ['SASI NO','SASINO','SASE NO','CHASSIS','CHASSIS NO','SERVICE TAG','SERVIS ETIKETI']],
    'lokasyon'     => ['etiket' => 'Lokasyon / Mevcut Proje',  'es' => ['MEVCUT PROJE','MEVCUTPROJE','KONUM','PROJE','PROJESI','PROJE KODU','LOKASYON','SANTIYE','OFIS','LOCATION','SITE','OFFICE','BULUNDUGU YER']],
    'ilk_lokasyon' => ['etiket' => 'İlk Proje (nota yazılır)', 'es' => ['ILK PROJE','ILKPROJE','VARSAYILAN KONUM','ILK LOKASYON','ONCEKI PROJE','ESKI PROJE','ORIGINAL PROJECT']],
    'kisi'         => ['etiket' => 'Zimmetli Kişi',            'es' => ['KISI','KISI ADI','CIKIS YAPILMIS OLAN KISI','ZIMMETLI','ZIMMETLI KISI','KULLANICI','KULLANAN','PERSONEL','PERSONEL ADI','AD SOYAD','ADI SOYADI','SORUMLU','ASSIGNED TO','USER','OWNER','EMPLOYEE']],
    'departman'    => ['etiket' => 'Departman / Birim',        'es' => ['DEPARTMAN','BIRIM','BOLUM','DEPARTMENT','UNIT']],
    'unvan'        => ['etiket' => 'Görev / Unvan (personele işlenir)', 'es' => ['BASLIK','UNVAN','GOREV','TITLE','JOB TITLE','POZISYON']],
    'zimmet_tarihi'=> ['etiket' => 'Zimmet / Çıkış Tarihi',    'es' => ['CIKIS TARIHI','ZIMMET TARIHI','CHECKOUT DATE']],
    'durum'        => ['etiket' => 'Durum',                    'es' => ['DURUM','DURUMU','STATUS','CIHAZ DURUMU','KULLANIM DURUMU']],
    'alis_tarihi'  => ['etiket' => 'Alış Tarihi',              'es' => ['ALIS TARIHI','SATIN ALMA TARIHI','SATIN ALMA','ALIM TARIHI','FATURA TARIHI','PURCHASE DATE','BUY DATE']],
    'garanti_bitis'=> ['etiket' => 'Garanti Bitiş',            'es' => ['GARANTI BITIS','GARANTI BITIS TARIHI','GARANTI SURESI SONA ERDI','WARRANTY','WARRANTY END']],
    // ⚠ Fiyat ÜÇ sütundan gelebilir: döviz tutarı · TL tutarı · kur. `it_fiyat_coz()` üçünü
    // tek künyeye indirir (eksik olanı türetir) — eş anlamlılarda DÖVİZ olanlar önce denenir,
    // yoksa yalnız 'FIYAT' yazan sütun TL sayılır.
    'fiyat_usd'    => ['etiket' => 'Fiyat (döviz)',            'es' => ['FIYAT USD','USD FIYAT','FIYAT DOLAR','FIYAT EUR','EUR FIYAT','DOVIZ TUTARI','PRICE USD','USD','USD PRICE']],
    'fiyat'        => ['etiket' => 'Fiyat (TL)',               'es' => ['FIYAT TL','TL FIYAT','TUTAR TL','FIYAT','SATIN ALMA UCRETI','BIRIM FIYAT','TUTAR','BEDEL','PRICE','COST','AMOUNT']],
    'kur'          => ['etiket' => 'Kur (alış günü)',          'es' => ['KUR','DOVIZ KURU','EXCHANGE RATE','RATE']],
    'para_birimi'  => ['etiket' => 'Para Birimi',              'es' => ['PARA BIRIMI','DOVIZ','DOVIZ CINSI','CURRENCY']],
    'sas_ref'      => ['etiket' => 'SAS Ref (satın alma)',     'es' => ['SAS REF','SAS REFERANS','SAS','SATIN ALMA REF','TALEP NO','PURCHASE REF']],
    'transfer_birim'  => ['etiket' => 'Transfer Geldiği Birim','es' => ['TRANSFER GELDIGI BIRIM','GELDIGI BIRIM','TRANSFER BIRIM','TRANSFER GELDIGI YER']],
    'transfer_tarihi' => ['etiket' => 'Transfer Tarihi',       'es' => ['TRANSFER TARIHI','TRANSFER TARIH']],
    'cozunurluk'   => ['etiket' => 'Ekran Çözünürlüğü',        'es' => ['COZUNURLUK','EKRAN COZUNURLUK','EKRAN COZUNURLUGU','1 EKRAN COZUNURLUGU','DIZUSTU EKRAN COZUNURLUK','RESOLUTION']],
    'disk_seri'    => ['etiket' => 'Disk Seri No',             'es' => ['HARDDISK SERIAL','DISK SERI NO','HDD SERIAL','HDD SERI NO']],
    'tedarikci'    => ['etiket' => 'Tedarikçi',                'es' => ['TEDARIKCI','SATICI','FIRMA','VENDOR','SUPPLIER']],
    'fatura_no'    => ['etiket' => 'Fatura No',                'es' => ['FATURA NO','FATURANO','SIPARIS NUMARASI','SIPARIS NO','INVOICE','INVOICE NO','ORDER NUMBER']],
    'ip_adresi'    => ['etiket' => 'IP Adresi',                'es' => ['IP','IP ADRESI','IP ADDRESS']],
    'mac_adresi'   => ['etiket' => 'MAC Adresi',               'es' => ['MAC','MAC ADRESI','MAC ADDRESS']],
    'isletim'      => ['etiket' => 'İşletim Sistemi',          'es' => ['ISLETIM SISTEMI','ISLETIM','OS','OPERATING SYSTEM','WINDOWS']],
    // ⚠ Donanım sütunları AYRI alanlara gider (zimmet formundaki "Özellikler" bloğu); her biri
    // ÇOK sütuna bağlanabilir — "Islemcı Marka" + "Islemcı Model" tek alanda " · " ile birleşir.
    'islemci'      => ['etiket' => 'İşlemci (birleşir)', 'es' => ['ISLEMCI MARKA','ISLEMCI MODEL','ISLEMCI','CPU','PROCESSOR']],
    'ram'          => ['etiket' => 'RAM (birleşir)',     'es' => ['RAM','RAM TIPI','RAM MARKA','BELLEK','BELLEK TIPI']],
    'ekran_karti'  => ['etiket' => 'Ekran Kartı (birleşir)', 'es' => ['EKRAN KARTI','EKRAN KARTI MODELI','GPU','GRAFIK KARTI']],
    'disk'         => ['etiket' => 'Disk / HDD (birleşir)',  'es' => ['HDD','HDD MODELI','HDD BILGISI','DISK','SSD','DEPOLAMA','SABIT DISK','HARDDISK','HARDDISK TIPI','HARDDISK MARKASI','HARDDISK MODELI','HARDDISK KAPASITESI','RPM']],
    'anakart'      => ['etiket' => 'Anakart',            'es' => ['ANAKART','ANA KART','MAINBOARD','MOTHERBOARD']],
    'ekran_boyutu' => ['etiket' => 'Ekran Boyutu',       'es' => ['EKRAN BOYUTU','EKRAN','COZUNURLUK','SCREEN SIZE']],
    'imei'         => ['etiket' => 'IMEI',               'es' => ['IMEI','IMEI NO','IMEI NUMARASI']],
    'kapasite'     => ['etiket' => 'Kapasite',           'es' => ['KAPASITE','KAPASITESI','CAPACITY']],
    'kiralik_firma'=> ['etiket' => 'Kiralanan Firma',    'es' => ['KIRALANAN FIRMA','KIRALIK FIRMA','KIRALAYAN FIRMA']],
    'ozellik'      => ['etiket' => 'Diğer teknik özellik (birleşir)','es' => ['TEKNIK OZELLIK','TEKNIK OZELLIKLER','OZELLIK','OZELLIKLER','SPECS','SPECIFICATION']],
    'sicil_no'     => ['etiket' => 'Zimmetli kişinin sicil no', 'es' => ['CALISAN NUMARASI','SICIL NO','SICIL','PERSONEL NO','EMPLOYEE NUMBER','EMPLOYEE NO']],
    'snipe_id'     => ['etiket' => 'Snipe-IT Kimlik (belge köprüsü)', 'es' => ['KIMLIK','SNIPE ID','SNIPE-IT ID','ASSET ID']],
    'sirket'       => ['etiket' => 'Şirket',                  'es' => ['SIRKET','SIRKETI','COMPANY','FIRMA ADI']],
    'notlar'       => ['etiket' => 'Not (nota eklenir)',       'es' => ['NOT','NOTLAR','ACIKLAMA','DESCRIPTION','REMARKS','COMMENT','INFO','DEMIRBAS DURUMU','VERSIYON','SURUM','VERSION']],
];

/** Birden çok sütundan beslenebilen alanlar (değerler " · " ile birleşir). */
const CIM_COKLU = ['ozellik', 'notlar', 'islemci', 'ram', 'ekran_karti', 'disk'];

/** Alanın KENDİSİNİ taşıyan genel başlıklar — bunlarda "Başlık: değer" öneki kullanılmaz. */
const CIM_GENEL_BASLIK = ['NOT', 'NOTLAR', 'ACIKLAMA', 'ACIKLAMALAR', 'DESCRIPTION', 'REMARKS', 'COMMENT',
                          'TEKNIK OZELLIK', 'TEKNIK OZELLIKLER', 'OZELLIK', 'OZELLIKLER', 'SPECS', 'SPECIFICATION'];

/** "Serı Nesne Adı" / kategori metni → IT_KATEGORI anahtarı (bulunamazsa 'diger'). */
function cim_kategori(string $s): string
{
    $n = pim_norm($s);
    if ($n === '') return 'diger';
    // ⚠ ÖNCE BİREBİR: metin bir kategori ANAHTARI ya da ETİKETİ ise doğrudan o kategoridir.
    // Şablon "Kategori" sütununa etiketi yazdığından (`?sablon=mevcut`), bu olmadan geri yüklemede
    // kategori kayıyordu ("Aksesuar" hiçbir kelimeyle eşleşmeyip 'diger'e düşüyordu).
    foreach (IT_KATEGORI as $anahtar => $t)
        if ($n === pim_norm($anahtar) || $n === pim_norm($t[0])) return $anahtar;
    // ⚠ TAŞIYICI/KILIF sözcüğü cihazın KENDİSİ değildir: "Laptop Çantası" laptop DEĞİL aksesuardır
    // (düz sıra 'LAPTOP'ı önce yakalayıp çantayı dizüstü sayıyor, cihaz sayısını ve mali değeri şişiriyordu).
    foreach (['CANTA', 'KILIF', 'TASIMA KUTUSU'] as $k) if (str_contains($n, $k)) return 'aksesuar';
    // ⚠ SIRA ÖNEMLİ: özel tipler önce denenir — "IP KAMERA" genel 'kamera' aksesuarına değil
    // güvenlik kategorisine, "IP TELEFON" cep telefonuna değil iletişim kategorisine düşmeli.
    $harita = [
        'ip_telefon'   => ['IP TELEFON','MASA TELEFONU','VOIP','SIP TELEFON','DAHILI TELEFON','DECK TELEFON','DECT TELEFON','DECT'],
        'santral'      => ['SANTRAL','PBX','IP SANTRAL','TELEFON SANTRALI'],
        'hat'          => ['TELEFON HATTI','GSM HAT','SABIT HAT','DATA HAT','HAT NUMARASI'],
        'nvr'          => ['NVR','DVR','KAYIT CIHAZI','KAMERA KAYIT'],
        'kamera'       => ['IP KAMERA','GUVENLIK KAMERASI','KAMERA SISTEMI','DOME KAMERA','BULLET KAMERA','PTZ'],
        'kartli_gecis' => ['KARTLI GECIS','GECIS KONTROL','ACCESS CONTROL','KART OKUYUCU','PDKS'],
        'turnike'      => ['TURNIKE','BARIYER','TURNSTILE'],
        'ups'          => ['UPS','KESINTISIZ GUC','KGK','GUC KAYNAGI UPS'],
        'kabinet'      => ['KABINET','RACK KABIN','RACK DOLAP','RACK KABINET'],
        'firewall'     => ['FIREWALL','GUVENLIK DUVARI','UTM','FORTIGATE','SOPHOS','PALO ALTO'],
        'switch'       => ['SWITCH','OMURGA','ANAHTAR CIHAZ','POE SWITCH'],
        'access_point' => ['ACCESS POINT','ERISIM NOKTASI','KABLOSUZ AP','WIFI AP'],
        'superbox'     => ['SUPERBOX','SUPER BOX','MOBIL MODEM','4.5G MODEM','LTE MODEM'],
        'tv'           => ['TELEVIZYON','TV','SMART TV','LED TV','EKRAN PANEL','DIGITAL SIGNAGE'],
        'projeksiyon'  => ['PROJEKSIYON','PROJEKTOR','PROJECTOR','BEAMER'],
        'drone'        => ['DRONE','IHA','QUADCOPTER','DJI'],
        // ⚠ Kullanıcıya dağıtılan küçük donanım — 'bilesen' ve 'aksesuar'dan ÖNCE denenir:
        // 'SSD'/'HARDDISK' bileşen listesinde geçtiğinden "HARİCİ SSD" oraya düşüyordu.
        'harici_disk'  => ['HARICI DISK','HARICI HDD','HARICI SSD','TASINABILIR DISK','TASINABILIR HDD','TASINABILIR SSD','PORTABLE HDD','PORTABLE SSD','EXTERNAL HDD','EXTERNAL SSD','EXTERNAL DISK'],
        'usb_bellek'   => ['USB BELLEK','FLASH BELLEK','FLASH DISK','USB DISK','USB FLASH','MEMORY STICK','TASINABILIR BELLEK'],
        'klavye'       => ['KLAVYE','KEYBOARD'],
        'mouse'        => ['MOUSE','FARE'],
        'kulaklik'     => ['KULAKLIK','HEADSET','HEADPHONE','KULAKLIK SETI'],
        'bilesen'      => ['RAM','BELLEK MODULU','ISLEMCI','CPU','GUC KAYNAGI','POWER SUPPLY','ANAKART','SSD','HARDDISK','HARD DISK','EKRAN KARTI'],
        'sarf'         => ['TONER','KARTUS','KARTUS','DRUM','SARF','KAGIT','PIL','BATARYA','ETIKET SERIT'],
        'laptop'       => ['DIZUSTU','NOTEBOOK','LAPTOP','TASINABILIR BILGISAYAR'],
        'bilgisayar'   => ['MASAUSTU','DESKTOP','KASA','PC','BILGISAYAR','IS ISTASYONU','WORKSTATION','ALL IN ONE'],
        'monitor'      => ['MONITOR','EKRAN','DISPLAY','LCD','LED EKRAN'],
        'yazici'       => ['YAZICI','PRINTER','PLOTER','PLOTTER','CIZICI','TARAYICI','SCANNER','FOTOKOPI','COK FONKSIYONLU'],
        'telefon'      => ['TELEFON','PHONE','CEP'],
        'tablet'       => ['TABLET','IPAD'],
        'ag'           => ['ROUTER','MODEM','AG CIHAZI','NETWORK','BAZ ISTASYONU','BAZ ISTASYON'],
        'sunucu'       => ['SUNUCU','SERVER','NAS','DEPOLAMA UNITESI','STORAGE'],
        'yazilim'      => ['LISANS','LICENSE','YAZILIM','SOFTWARE','OFFICE','WINDOWS LISANS','ANTIVIRUS'],
        'fotograf'     => ['FOTOGRAF MAKINE','FOTOGRAF MAKINESI','AKSIYON KAMERA','VIDEO KAMERA','KAMERA','CAMERA'],
        'aksesuar'     => ['DOCK','ADAPTOR','WEBCAM','HOPARLOR','CANTA','BARKOD','KABLO','HDD STATION','DOCKING STATION','KONFERANS'],
    ];
    // ⚠ KISA anahtar kelimeler (≤4 harf) KELİME SINIRIYLA aranır: düz `str_contains` ile
    // 'IHA' (drone) "AĞ CİHAZI"nın içinde geçiyor ve her ağ cihazı drone sayılıyordu.
    // Uzun kelimelerde substring kalır — Türkçe ekleri yakalasın ("MONİTÖRÜ" → MONITOR).
    foreach ($harita as $anahtar => $kelimeler) foreach ($kelimeler as $k) {
        $var = strlen($k) <= 4
            ? (bool)preg_match('/(?:^|\s)' . preg_quote($k, '/') . '(?:\s|$)/', $n)
            : str_contains($n, $k);
        if ($var) return $anahtar;
    }
    return 'diger';
}

/** Marka hücresindeki kurum kodunu atar: "M0026-AOC" → "AOC" (kod tek başınaysa dokunmaz). */
function cim_marka(string $s): string
{
    $s = trim($s);
    if (preg_match('/^[A-Za-z]?\d{3,6}\s*[-–]\s*(.+)$/u', $s, $m) && trim($m[1]) !== '') return trim($m[1]);
    return $s;
}

/** Cihaz şeması eki: varlık kodu (ERP'nin benzersiz demirbaş kodu) + import log. Transaction DIŞINDA çağır. */
function cim_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    it_ek_alan_semasi_kur($pdo);            // varlik_kodu + cihaz_kodu kolonları
    try { $pdo->exec("CREATE INDEX ix_varlik ON it_cihazlar (varlik_kodu)"); } catch (Throwable $e) {}
    // Geçiş: eski aktarımlar kurumsal demirbaş etiketini `envanter_no`ya yazıyordu; bizim numaramız
    // IT-00001 biçimindedir, ondan farklı olan değerler kurumsal CİHAZ KODU'dur → kendi kolonuna taşınır
    // (envanter_no yerinde kalır, tutanaklardaki numara değişmesin). Idempotent.
    try {
        $pdo->exec("UPDATE it_cihazlar SET cihaz_kodu = envanter_no
                    WHERE (cihaz_kodu IS NULL OR cihaz_kodu = '')
                      AND envanter_no IS NOT NULL AND envanter_no <> '' AND envanter_no NOT LIKE 'IT-%'");
    } catch (Throwable $e) {}
    pim_log_kur($pdo);
}

/** Sütun → alan haritası. 'ozellik' ve 'notlar' ÇOK sütuna bağlanabilir (birleştirilir), diğerleri tek sütun. */
function cim_harita(array $baslik): array
{
    $h = []; $kul = [];
    foreach ($baslik as $i => $c) {
        $n = pim_norm((string)$c);
        if ($n === '') { $h[$i] = ''; continue; }
        $bul = null;
        foreach (CIM_ALAN as $k => $t) if (in_array($n, $t['es'], true)) { $bul = $k; break; }
        if ($bul === null) {   // gevşek: başlık eş anlamlıyı içeriyor mu (en uzun eşleşme)
            $enUzun = 0;
            foreach (CIM_ALAN as $k => $t) foreach ($t['es'] as $e) {
                if (strlen($e) >= 3 && strlen($e) > $enUzun && (str_starts_with($n, $e) || str_contains($n, ' ' . $e) || str_contains($n, $e . ' '))) { $bul = $k; $enUzun = strlen($e); }
            }
        }
        if ($bul === null) { $h[$i] = ''; continue; }
        if (!in_array($bul, CIM_COKLU, true) && isset($kul[$bul])) { $h[$i] = 'ozellik'; continue; }
        $h[$i] = $bul; $kul[$bul] = $i;
    }
    return $h;
}

/** IFS / ERP nesne kodu mu? — "FRM-0002-82026-2552600167", "ORT-U021-14063-2552000181", "ZFRM-999-…". */
function cim_ifs_kodu(string $s): bool
{
    return (bool)preg_match('/^[A-Z]{2,6}-[A-Z0-9]{2,8}-[A-Z0-9]{2,10}-\d{6,16}$/u', strtoupper(trim($s)));
}

/** Metin bir cihaz ADI gibi mi okunuyor? ("Apple iPad Pro" evet, "VS16217" hayır — o model kodudur.) */
function cim_ad_mi(string $s): bool
{
    $s = trim($s);
    if ($s === '' || cim_kod_mu($s) || cim_ifs_kodu($s)) return false;
    return str_contains($s, ' ') || (mb_strlen($s) > 8 && preg_match('/\p{L}{4,}/u', $s));
}

/** Kurum içi kısa cihaz kodu mu? — "M160", "N221", "B060" (model adı değil, demirbaş etiketi). */
function cim_kod_mu(string $s): bool
{
    return (bool)preg_match('/^[A-Z]{1,3}[- ]?\d{2,6}$/u', strtoupper(trim($s)));
}

/**
 * Zimmetli kişi hücresinden kullanıcı adını atar:
 * "TUĞBA AKYAZI KUBLAY (TUĞBAAKYAZI)" → "TUĞBA AKYAZI KUBLAY" (Snipe-IT dışa aktarımı böyle yazar).
 */
function cim_kisi_ad(string $s): string
{
    $s = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', trim($s)) ?? '');
    return trim($s, " \t\-–,;");
}

/** Fiyat hücresi: "8,598,960.00" (ABD) ve "8.598.960,00" (TR) biçimlerinin ikisini de okur. */
function cim_fiyat(string $s): ?float
{
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) return (float)str_replace(',', '', $s);
    return it_sayi($s);
}

/**
 * Dosya satırındaki fiyat sütunlarını tek künyeye indirir: fiyat · para_birimi · kur · fiyat_tl.
 * Kaynak dosyalarda üçü birden gelmez — `it_fiyat_coz()` eksik olanı türetir
 * (ör. Zekeriyaköy dosyasında Kur sütunu çoğu satırda boş, TL tutarından hesaplanır).
 * Para birimi sütunu yoksa: döviz tutarı dolu → USD, değilse TL.
 */
function cim_fiyat_kunyesi(array $v): array
{
    $doviz = cim_fiyat((string)($v['fiyat_usd'] ?? ''));
    $tl    = cim_fiyat((string)($v['fiyat'] ?? ''));
    $kur   = cim_kur((string)($v['kur'] ?? ''));
    $para  = trim((string)($v['para_birimi'] ?? ''));
    if ($para === '') $para = $doviz !== null && $doviz > 0 ? 'USD' : 'TRY';
    return $doviz !== null && $doviz > 0
        ? it_fiyat_coz($doviz, $para, $kur, $tl)      // döviz alışı: TL karşılığı kur ya da TL sütunundan
        : it_fiyat_coz($tl, 'TRY', 1, $tl);           // yalnız TL tutarı
}

/** Kur ayrıştırma çekirdekte (`it_kur`): kurun noktası ondalıktır, binlik ayracı DEĞİL. */
function cim_kur(string $s): ?float { return it_kur($s); }

/** Grid satırı → cihaz alanları (ham). 'ozellik'/'notlar' sütunları "Başlık: değer" olarak birikir. */
function cim_satir_cozumle(array $satir, array $harita, array $baslik): array
{
    $v = array_fill_keys(array_keys(CIM_ALAN), '');
    foreach (CIM_COKLU as $k) $v[$k] = [];
    foreach ($harita as $i => $k) {
        if ($k === '' || $k === null) continue;
        $c = trim((string)($satir[$i] ?? ''));
        if ($c === '') continue;
        if (in_array($k, CIM_COKLU, true)) {
            // ozellik/notlar birden çok sütundan beslenebildiği için "Başlık: değer" olarak birikir —
            // ⚠ ama sütunun kendisi zaten "Not"/"Açıklama"/"Teknik Özellik" ise BAŞLIK EKLENMEZ:
            // aksi halde `?sablon=mevcut` ile indirilip geri yüklenen dosyada not her turda
            // "Not: <eski not>" diye kendi üstüne sarılıyordu.
            $bas = trim((string)($baslik[$i] ?? ''));
            $v[$k][] = (in_array($k, ['ozellik', 'notlar'], true) && $bas !== '' && !in_array(pim_norm($bas), CIM_GENEL_BASLIK, true))
                ? $bas . ': ' . $c : $c;
            continue;
        }
        if ($v[$k] === '') $v[$k] = $c;
    }
    $v['marka'] = cim_marka($v['marka']);
    $v['kisi']  = cim_kisi_ad($v['kisi']);

    // ── Kimlik kodları: IFS nesne no ile kurum içi cihaz kodu farklı sütunlardan gelebilir ──
    // Snipe-IT'de "Demirbaş Etiketi" bazen kısa kod (M160), bazen IFS nesne no (FRM-…) taşır;
    // IFS biçimindeyse ENVANTER değil VARLIK kodudur — yoksa iki sistemdeki aynı cihaz eşleşmez.
    $kodTasindi = false;    // etiket sütunu IFS kodu taşıyordu → kurum içi kısa kod başka sütunda
    if ($v['varlik_kodu'] === '' && $v['cihaz_kodu'] !== '' && cim_ifs_kodu($v['cihaz_kodu'])) {
        $v['varlik_kodu'] = $v['cihaz_kodu'];
        $v['cihaz_kodu']  = '';
        $kodTasindi = true;
    }
    // Varlık kodu başka sütundan geldiyse etiket yine IFS biçiminde kalabilir — cihaz kodu değildir
    if ($v['cihaz_kodu'] !== '' && cim_ifs_kodu($v['cihaz_kodu'])) { $v['cihaz_kodu'] = ''; $kodTasindi = true; }
    if ($v['varlik_kodu'] === '' && $v['ad'] !== '' && cim_ifs_kodu($v['ad'])) { $v['varlik_kodu'] = $v['ad']; $v['ad'] = ''; }
    if ($v['ad'] !== '' && cim_ifs_kodu($v['ad'])) $v['ad'] = '';

    // ── Model: "Model" sütunu kurum içi kodu (N221) taşıyorsa gerçek model "Model No"dadır ──
    $modelKod = $v['model'];
    if ($v['model_no'] !== '')            $v['model'] = $v['model_no'];
    elseif (cim_kod_mu($modelKod))        $v['model'] = '';
    // ⚠ Model'i cihaz kodu saymak YALNIZ etiket sütunu IFS kodu taşıdığında doğrudur (Snipe-IT düzeni).
    // Aksi halde "A2604" gibi gerçek model numaraları demirbaş etiketi sanılıp koda yazılıyordu.
    if ($kodTasindi && $modelKod !== '' && cim_kod_mu($modelKod) && $v['cihaz_kodu'] === '') $v['cihaz_kodu'] = $modelKod;
    if ($v['ad'] !== '' && cim_kod_mu($v['ad'])) {                       // "Demirbaş Adı = M012" → ad değil kod
        if ($v['cihaz_kodu'] === '') $v['cihaz_kodu'] = $v['ad'];
        $v['ad'] = '';
    }

    // ⚠ Şasi no artık KENDİ kolonunda durur (eskiden seri boşsa seriye taşınıp yoksa nota gömülüyordu).
    // Seri no boşken şasi varsa seri olarak da kullanılır — cihaz seri numarasıyla aranabilsin.
    if ($v['seri_no'] === '' && $v['sasi_no'] !== '') $v['seri_no'] = $v['sasi_no'];
    if ($v['ilk_lokasyon'] !== '') $v['notlar'][] = 'İlk proje: ' . $v['ilk_lokasyon'];
    return $v;
}

/** Kişi adını `it_personel` kaydına bağlar (normalize ad+soyad). Dönüş: [personel|null, 'benzersiz|coklu|yok']. */
function cim_personel_bul(PDO $pdo, string $adSoyad, bool $yenile = false): array
{
    static $harita = null;
    if ($yenile) $harita = null;
    if ($harita === null) {
        $harita = [];
        foreach (it_personel_liste($pdo, false) as $p) $harita[pim_norm($p['ad'] . ' ' . $p['soyad'])][] = $p;
    }
    $n = pim_norm($adSoyad);
    if ($n === '' || !isset($harita[$n])) return [null, 'yok'];
    return count($harita[$n]) === 1 ? [$harita[$n][0], 'benzersiz'] : [null, 'coklu'];
}

/** Sicil no ile personel bul (ad soyaddan güvenilir — aynı adlı kişiler ayrışır). */
function cim_personel_sicil(PDO $pdo, string $sicil, bool $yenile = false): ?array
{
    static $harita = null;
    if ($yenile) $harita = null;
    if ($harita === null) {
        $harita = [];
        foreach (it_personel_liste($pdo, false) as $p) {
            $sn = pim_norm((string)($p['sicil_no'] ?? ''));
            if ($sn !== '' && !isset($harita[$sn])) $harita[$sn] = $p;
        }
    }
    $n = pim_norm($sicil);
    return $n !== '' ? ($harita[$n] ?? null) : null;
}

/**
 * Cihaz içe aktarma (birleştirme). $opt: harita, baslik_idx, satir_no, kisi_ekle, kisi_durum, kullanici, dosya, bicim.
 * Dönüş: okunan/yeni/guncellenen/degismeyen/atlanan/kisi_yok/lokasyon_yok/kisi_eklenen listeleri.
 */
function cim_import(PDO $pdo, array $satirlar, array $opt): array
{
    $harita = $opt['harita']; $bIdx = (int)$opt['baslik_idx']; $baslik = $satirlar[$bIdx] ?? [];
    $r = ['okunan'=>0, 'yeni'=>[], 'guncellenen'=>[], 'degismeyen'=>0, 'atlanan'=>[], 'kisi_yok'=>[], 'lokasyon_yok'=>[], 'kisi_eklenen'=>[]];
    // ⚠ 'envanter_no' BİLEREK YOK: bizim sabit numaramızdır (tutanaklarda geçer), dosya onu ezmez.
    $alanlar = ['varlik_kodu','cihaz_kodu','kategori','ad','marka','model','seri_no','sasi_no','imei','durum','zimmetli','personel_id','departman','lokasyon','lokasyon_id',
                'zimmet_tarihi','alis_tarihi','garanti_bitis','fiyat','para_birimi','kur','fiyat_tl','sas_ref',
                'transfer_birim','transfer_tarihi','tedarikci','fatura_no','sirket','snipe_id','ip_adresi','mac_adresi','isletim_sistemi',
                'islemci','ram','ekran_karti','disk','disk_seri','anakart','ekran_boyutu','cozunurluk','kapasite','kiralik_firma','ozellikler'];

    cim_semasi_kur($pdo);                       // ⚠ DDL transaction'ı örtük commit eder → ÖNCE
    $mevcut = $pdo->query("SELECT * FROM it_cihazlar")->fetchAll();
    $byVarlik = []; $byKod = []; $byEnv = []; $bySeri = [];
    foreach ($mevcut as $m) {
        if (trim((string)($m['varlik_kodu'] ?? '')) !== '') $byVarlik[pim_norm($m['varlik_kodu'])] = $m;
        if (trim((string)($m['cihaz_kodu'] ?? '')) !== '')  $byKod[pim_norm($m['cihaz_kodu'])] = $m;
        if (trim((string)$m['envanter_no']) !== '')         $byEnv[pim_norm($m['envanter_no'])] = $m;
        if (trim((string)($m['seri_no'] ?? '')) !== '')     $bySeri[pim_norm($m['seri_no'])] = $m;
    }
    // İstenirse dosyadaki eşleşmeyen kişiler için personel kartı açılır (sonra cihazlar bunlara bağlanır)
    if (!empty($opt['kisi_ekle'])) {
        $adlar = [];
        foreach ($satirlar as $i => $sat) {
            if ($i <= $bIdx) continue;
            $v = cim_satir_cozumle($sat, $harita, $baslik);
            if (trim($v['kisi']) === '') continue;
            $adlar[pim_norm($v['kisi'])] = ['ad'=>trim($v['kisi']), 'sicil'=>trim($v['sicil_no']), 'unvan'=>trim($v['unvan'])];
        }
        $n = cim_personel_ekle($pdo, $adlar);
        if ($n) { cim_personel_bul($pdo, '', true); cim_personel_sicil($pdo, '', true); $r['kisi_eklenen'] = ['adet' => $n]; }
    }

    $dosyaIci = []; $eslesen = [];
    $pdo->beginTransaction();
    try {
        foreach ($satirlar as $i => $sat) {
            if ($i <= $bIdx) continue;
            if (!array_filter($sat, fn($c) => trim((string)$c) !== '')) continue;
            $r['okunan']++;
            $exNo = (int)($opt['satir_no'][$i] ?? ($i + 1));
            $v = cim_satir_cozumle($sat, $harita, $baslik);
            $etiket = trim(($v['cihaz_kodu'] ?: $v['envanter_no']) . ' ' . $v['ad']) ?: ($v['varlik_kodu'] ?: "satır $exNo");

            // Kimliksiz satır cihaz değildir
            if ($v['varlik_kodu'] === '' && $v['cihaz_kodu'] === '' && $v['envanter_no'] === '' && $v['seri_no'] === '' && $v['ad'] === '') {
                $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>'kod / seri no / cihaz adı yok — cihaz satırı değil']; continue;
            }
            $dk = $v['varlik_kodu'] !== '' ? 'V:' . pim_norm($v['varlik_kodu'])
                : ($v['cihaz_kodu'] !== '' ? 'K:' . pim_norm($v['cihaz_kodu'])
                : ($v['envanter_no'] !== '' ? 'E:' . pim_norm($v['envanter_no'])
                : ($v['seri_no'] !== '' ? 'S:' . pim_norm($v['seri_no']) : 'A:' . pim_norm($v['ad']) . $exNo)));
            if (isset($dosyaIci[$dk])) { $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>'aynı dosyada tekrar (satır ' . $dosyaIci[$dk] . ')']; continue; }
            $dosyaIci[$dk] = $exNo;

            // Lokasyon: "U030" gibi proje kodu ya da lokasyon adı
            $lokId = null; $lokAd = '';
            if ($v['lokasyon'] !== '') {
                $lokId = pim_lokasyon_bul($pdo, $v['lokasyon']);
                if ($lokId) $lokAd = it_lokasyon_yol($pdo, $lokId);
                else { $r['lokasyon_yok'][$v['lokasyon']] = ($r['lokasyon_yok'][$v['lokasyon']] ?? 0) + 1; $lokAd = $v['lokasyon']; $v['notlar'][] = 'Lokasyon (dosyadan): ' . $v['lokasyon']; }
            }
            // Zimmetli kişi — ⚠ ÖNCE SİCİL: aynı ad soyadlı iki kişi sicille ayrışır, ad ile ayrışmaz
            $personelId = null; $kisiAd = it_buyuk($v["kisi"]);
            if ($kisiAd !== '' || $v['sicil_no'] !== '') {
                $p = $v['sicil_no'] !== '' ? cim_personel_sicil($pdo, $v['sicil_no']) : null;
                $nasil = $p ? 'benzersiz' : 'yok';
                if (!$p && $kisiAd !== '') [$p, $nasil] = cim_personel_bul($pdo, $kisiAd);
                if ($p) {
                    $personelId = (int)$p['id']; $kisiAd = it_personel_ad($p);
                    if (!$v['departman'] && $p['birim']) $v['departman'] = $p['birim'];
                    // Dosyadaki görev/unvan personel kartında boşsa tamamlanır (mevcut unvan EZİLMEZ)
                    if ($v['unvan'] !== '' && trim((string)($p['unvan'] ?? '')) === '') {
                        try { $pdo->prepare("UPDATE it_personel SET unvan=? WHERE id=?")->execute([mb_substr($v['unvan'], 0, 120), (int)$p['id']]); } catch (Throwable $e) {}
                    }
                } elseif ($kisiAd !== '') {
                    $r['kisi_yok'][] = ['satir'=>$exNo, 'kisi'=>$kisiAd . ($v['sicil_no'] !== '' ? ' (sicil ' . $v['sicil_no'] . ')' : ''),
                                        'neden'=>$nasil === 'coklu' ? 'aynı ad soyadlı birden çok personel' : 'personel kartı yok'];
                }
            }

            $birlesik = fn(string $k, int $max) => ($v[$k] ?? []) ? mb_substr(implode(' · ', array_unique(array_filter($v[$k]))), 0, $max) : null;
            $durum = pim_norm($v['durum']) !== '' ? cim_durum($v['durum']) : null;
            if ($durum === null) $durum = $kisiAd !== '' ? 'aktif' : 'depoda';
            $kat = $v['kategori'] !== '' ? cim_kategori($v['kategori']) : cim_kategori($v['ad'] . ' ' . $v['model']);
            // Cihaz adı: yalnız gerçek ad sütunu MEVCUT kaydı günceller. Ad boşsa modelden ya da
            // kategori+markadan bir ad TÜRETİLİR — ama türetilmiş ad yalnız YENİ kayda yazılır,
            // aksi halde "Dizüstü Bilgisayar" gibi elle verilmiş adlar her aktarımda bozuluyordu.
            $adDosyadan = $v['ad'] !== '';
            $adDus = $v['ad'];
            if ($adDus === '' && cim_ad_mi($v['model'])) $adDus = $v['model'];
            if ($adDus === '') {
                $adDus = ($v['kategori'] !== '' ? trim($v['kategori']) : it_kategoriAd($kat));
                if ($v['marka'] !== '') $adDus .= ' — ' . $v['marka'];
            }
            $yeni = [
                'varlik_kodu' => $v['varlik_kodu'] ?: null,
                'cihaz_kodu'  => $v['cihaz_kodu'] ?: null,
                'envanter_no' => $v['envanter_no'] ?: null,
                'kategori'    => $kat,
                'ad'          => $adDus ?: 'Cihaz',
                'marka'       => $v['marka'] ?: null, 'model' => $v['model'] ?: null, 'seri_no' => $v['seri_no'] ?: null,
                'durum'       => $durum,
                'zimmetli'    => $kisiAd ?: null, 'personel_id' => $personelId,
                'departman'   => $v['departman'] ?: null,
                'lokasyon'    => $lokAd ?: null, 'lokasyon_id' => $lokId,
                'zimmet_tarihi'=> $kisiAd !== '' ? pim_tarih($v['zimmet_tarihi']) : null,
                'alis_tarihi' => pim_tarih($v['alis_tarihi']), 'garanti_bitis' => pim_tarih($v['garanti_bitis']),
                // Fiyat künyesi: döviz tutarı · TL tutarı · kur birlikte çözülür (eksik olan türetilir).
                // Para birimi sütunu yoksa döviz sütunu doluysa USD, değilse TL sayılır.
                'tedarikci'   => $v['tedarikci'] ?: null, 'fatura_no' => $v['fatura_no'] ?: null,
                'sas_ref'     => $v['sas_ref'] ?: null,
                'transfer_birim'  => $v['transfer_birim'] ?: null,
                'transfer_tarihi' => pim_tarih($v['transfer_tarihi']),
                'cozunurluk'  => $v['cozunurluk'] ?: null, 'disk_seri' => $v['disk_seri'] ?: null,
                'sirket'      => $v['sirket'] ?: null,
                'snipe_id'    => ctype_digit($v['snipe_id']) ? (int)$v['snipe_id'] : null,
                'ip_adresi'   => $v['ip_adresi'] ?: null, 'mac_adresi' => $v['mac_adresi'] ?: null,
                'isletim_sistemi' => $v['isletim'] ?: null,
                'sasi_no'     => $v['sasi_no'] ?: null, 'imei' => $v['imei'] ?: null,
                'islemci'     => $birlesik('islemci', 160), 'ram' => $birlesik('ram', 120),
                'ekran_karti' => $birlesik('ekran_karti', 160), 'disk' => $birlesik('disk', 160),
                'anakart'     => $v['anakart'] ?: null, 'ekran_boyutu' => $v['ekran_boyutu'] ?: null,
                'kapasite'    => $v['kapasite'] ?: null, 'kiralik_firma' => $v['kiralik_firma'] ?: null,
                'ozellikler'  => null,   // aşağıda künyeden ya da serbest sütunlardan doldurulur
            ] + cim_fiyat_kunyesi($v);
            // Serbest "özellik" sütunları varsa onlar, yoksa donanım künyesinin özeti
            $yeni['ozellikler'] = $v['ozellik']
                ? mb_substr(implode(' · ', array_unique($v['ozellik'])), 0, 255)
                : (it_ozellik_ozet($yeni) ?: null);
            $notSatiri = $v['notlar'] ? implode("\n", array_unique($v['notlar'])) : '';

            // Eşleşme: IFS nesne no → cihaz kodu → envanter no → seri no
            $m = null; $nasil = '';
            if ($v['varlik_kodu'] !== '' && isset($byVarlik[pim_norm($v['varlik_kodu'])])) { $m = $byVarlik[pim_norm($v['varlik_kodu'])]; $nasil = 'IFS nesne no'; }
            elseif ($v['cihaz_kodu'] !== '' && isset($byKod[pim_norm($v['cihaz_kodu'])]))  { $m = $byKod[pim_norm($v['cihaz_kodu'])]; $nasil = 'cihaz kodu'; }
            elseif ($v['cihaz_kodu'] !== '' && isset($byEnv[pim_norm($v['cihaz_kodu'])]))  { $m = $byEnv[pim_norm($v['cihaz_kodu'])]; $nasil = 'envanter no'; }
            elseif ($v['envanter_no'] !== '' && isset($byEnv[pim_norm($v['envanter_no'])])) { $m = $byEnv[pim_norm($v['envanter_no'])]; $nasil = 'envanter no'; }
            elseif ($v['seri_no'] !== '' && isset($bySeri[pim_norm($v['seri_no'])])) { $m = $bySeri[pim_norm($v['seri_no'])]; $nasil = 'seri no'; }

            // ⚠ Aynı cihaz kaydına iki dosya satırı denk gelirse (ör. bizdeki IFS kodu dosyada başka
            // cihaz koduyla eşleşiyorsa) satırlar birbirini ezip her aktarımda gidip gelirdi — ikincisi
            // aktarılmaz, çelişki raporlanır.
            if ($m && isset($eslesen[(int)$m['id']])) {
                $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket,
                                   'neden'=>'bu cihaz kaydı dosyadaki ' . $eslesen[(int)$m['id']] . '. satırla eşleşti — kimlik kodları çakışıyor, elle ayırın'];
                continue;
            }
            if ($m) {
                $eslesen[(int)$m['id']] = $exNo;
                $set = []; $par = []; $degisen = []; $eskiKisi = trim((string)($m['zimmetli'] ?? ''));
                foreach ($alanlar as $k) {
                    $y = $yeni[$k] ?? null;
                    if ($y === null || $y === '') continue;                      // dosyada boş → mevcut korunur
                    if ($k === 'ad' && !$adDosyadan) continue;                    // türetilmiş ad mevcut adı ezmez
                    if ((string)($m[$k] ?? '') === (string)$y) continue;
                    $set[] = "$k=?"; $par[] = $y;
                    if (!in_array($k, ['ozellikler','lokasyon','personel_id','lokasyon_id'], true))
                        $degisen[] = $k . ': ' . (($m[$k] ?? '') === '' || $m[$k] === null ? '—' : $m[$k]) . ' → ' . $y;
                }
                if ($notSatiri !== '') {
                    $eskiNot = (string)($m['notlar'] ?? '');
                    $ek = array_filter(explode("\n", $notSatiri), fn($s) => $s !== '' && !str_contains($eskiNot, $s));
                    if ($ek) { $set[] = "notlar=?"; $par[] = trim($eskiNot . "\n" . implode("\n", $ek)); }
                }
                if ($set) {
                    $par[] = (int)$m['id'];
                    $pdo->prepare("UPDATE it_cihazlar SET " . implode(', ', $set) . " WHERE id=?")->execute($par);
                    // Zimmet değiştiyse cihazın yaşam günlüğüne yaz
                    if ($kisiAd !== '' && pim_norm($eskiKisi) !== pim_norm($kisiAd)) {
                        if ($eskiKisi !== '') it_hareket_ekle($pdo, (int)$m['id'], 'iade', $eskiKisi, 'Excel aktarımı: zimmet devredildi → ' . $kisiAd);
                        it_hareket_ekle($pdo, (int)$m['id'], 'zimmet', $kisiAd, 'Excel aktarımı ile zimmetlendi' . ($lokAd ? ' — ' . $lokAd : ''));
                    }
                    if ($degisen) $r['guncellenen'][] = ['id'=>(int)$m['id'], 'kim'=>trim(($m['envanter_no'] ?? '') . ' ' . ($m['ad'] ?? '')), 'nasil'=>$nasil, 'degisen'=>$degisen];
                    else $r['degismeyen']++;
                } else $r['degismeyen']++;
            } else {
                if (!$yeni['envanter_no']) $yeni['envanter_no'] = it_envanter_no($pdo);
                elseif (isset($byEnv[pim_norm($yeni['envanter_no'])])) $yeni['envanter_no'] = it_envanter_no($pdo);   // çakışma → otomatik no
                if ($yeni['zimmetli'] && !$yeni['zimmet_tarihi']) $yeni['zimmet_tarihi'] = date('Y-m-d');
                $kol = array_keys($yeni);
                $sql = "INSERT INTO it_cihazlar (" . implode(',', $kol) . ",notlar,olusturan) VALUES ("
                     . implode(',', array_fill(0, count($kol), '?')) . ",?,?)";
                $pdo->prepare($sql)->execute([...array_values($yeni), $notSatiri ?: null, $opt['kullanici'] ?? null]);
                $id = (int)$pdo->lastInsertId();
                $eslesen[$id] = $exNo;
                it_hareket_ekle($pdo, $id, 'giris', $yeni['zimmetli'], 'Excel aktarımı ile envantere eklendi' . ($lokAd ? ' — ' . $lokAd : ''));
                if ($yeni['zimmetli']) it_hareket_ekle($pdo, $id, 'zimmet', $yeni['zimmetli'], 'Excel aktarımı ile zimmetlendi');
                $kayit = $yeni + ['id'=>$id];
                if ($yeni['varlik_kodu']) $byVarlik[pim_norm($yeni['varlik_kodu'])] = $kayit;
                if ($yeni['cihaz_kodu'])  $byKod[pim_norm($yeni['cihaz_kodu'])] = $kayit;
                $byEnv[pim_norm($yeni['envanter_no'])] = $kayit;
                if ($yeni['seri_no']) $bySeri[pim_norm($yeni['seri_no'])] = $kayit;
                $r['yeni'][] = ['id'=>$id, 'env'=>trim($yeni['envanter_no'] . ' ' . ($yeni['cihaz_kodu'] ?? '')), 'ad'=>$yeni['ad'], 'kategori'=>$yeni['kategori'],
                                'marka'=>trim(($yeni['marka'] ?? '') . ' ' . ($yeni['model'] ?? '')), 'kisi'=>$yeni['zimmetli'], 'lok'=>$lokAd];
            }
        }
        try {
            $pdo->prepare("INSERT INTO it_import_log (dosya,bicim,okunan,yeni,guncellenen,degismeyen,atlanan,ayrilan,kullanici) VALUES (?,?,?,?,?,?,?,0,?)")
                ->execute([($opt['dosya'] ?? null) . ' [cihaz]', $opt['bicim'] ?? null, $r['okunan'], count($r['yeni']), count($r['guncellenen']), $r['degismeyen'], count($r['atlanan']), $opt['kullanici'] ?? null]);
        } catch (Throwable $e) {}
        if ($pdo->inTransaction()) $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $r;
}

/** Durum metni → IT_DURUM anahtarı (bulunamazsa null → çağıran karar verir). */
function cim_durum(string $s): ?string
{
    $n = pim_norm($s);
    if ($n === '') return null;
    if (str_contains($n, 'HURDA') || str_contains($n, 'IMHA')) return 'hurda';
    if (str_contains($n, 'KAYIP') || str_contains($n, 'CALINTI') || str_contains($n, 'ZAYI')) return 'kayip';
    if (str_contains($n, 'HIBE') || str_contains($n, 'DEVIR') || str_contains($n, 'DEVRED')) return 'hibe';
    if (str_contains($n, 'ARIZA')) return 'arizali';
    if (str_contains($n, 'SERVIS') || str_contains($n, 'TAMIR')) return 'serviste';
    // ⚠ Snipe-IT sözlüğü: "… Atanmış" = bir kişide (kullanımda); atanmamış tüm hâller depodadır.
    // 'ATANMIS' kontrolü depo kelimelerinden ÖNCE gelmeli — "Boş / Yedek Atanmış" kullanımdadır.
    if (str_contains($n, 'ATANMIS') || str_contains($n, 'ATANDI')) return 'aktif';
    if (str_contains($n, 'TRANSFER') || str_contains($n, 'SEVK')) return 'transfer';
    if (str_contains($n, 'DEPO') || str_contains($n, 'BOSTA') || str_contains($n, 'STOK')
        || str_contains($n, 'BOS') || str_contains($n, 'YEDEK')
        || str_contains($n, 'BEKLIYOR') || str_contains($n, 'DAGITILABILIR')) return 'depoda';
    if (str_contains($n, 'KULLAN') || str_contains($n, 'ZIMMET') || str_contains($n, 'AKTIF')) return 'aktif';
    return null;
}

/** Dosyadaki eşleşmeyen kişileri personel olarak açar (rapordan tek tıkla). Dönüş: eklenen sayısı. */
function cim_personel_ekle(PDO $pdo, array $adlar): int
{
    $n = 0;
    foreach ($adlar as $kayit) {
        // Geriye uyumlu: ya düz ad metni ya da ['ad','sicil','unvan'] dizisi gelir
        $ad    = trim((string)(is_array($kayit) ? ($kayit['ad'] ?? '') : $kayit));
        $sicil = is_array($kayit) ? trim((string)($kayit['sicil'] ?? '')) : '';
        $unvan = is_array($kayit) ? trim((string)($kayit['unvan'] ?? '')) : '';
        if ($ad === '') continue;
        if ($sicil !== '' && cim_personel_sicil($pdo, $sicil)) continue;   // sicil zaten kayıtlı
        [$p, ] = cim_personel_bul($pdo, $ad);
        if ($p) continue;
        // Kişi adları sistemde tek biçim: Türkçe kurallarına göre BÜYÜK HARF
        [$adKisim, $soyKisim] = pim_ad_ayir(it_buyuk($ad));
        if ($adKisim === '') continue;
        $pdo->prepare("INSERT INTO it_personel (sicil_no, ad, soyad, unvan, notlar) VALUES (?,?,?,?,?)")
            ->execute([$sicil ?: null, $adKisim, $soyKisim, it_buyuk(mb_substr($unvan, 0, 120)) ?: null, 'Cihaz listesi aktarımından açıldı']);
        $n++;
    }
    return $n;
}

/* ─────────────────────────────────────────────────────────────────────────────
 * MÜKERRER CİHAZ TESPİTİ + BİRLEŞTİRME
 *
 * Aynı cihaz iki kez açılabiliyor: bir kaynaktan **cihaz kodu** ile (N411), başka bir
 * kaynaktan **IFS nesne no / seri no** ile gelen satır ayrı kayıt olarak düşüyor. Sonuç:
 * bir kartta yaşam günlüğü + imzalı evrak, diğerinde künye — ikisi de yarım.
 * Birleştirme ikisini TEK karta indirir: geçmiş ve belgeler korunan karta TAŞINIR.
 * ───────────────────────────────────────────────────────────────────────────── */

/** Mükerrer cihaz araması yapılan kimlik alanları: kolon => ekranda görünen ad. */
const CIM_MUKERRER_ANAHTAR = [
    'cihaz_kodu'  => 'cihaz kodu',
    'varlik_kodu' => 'IFS seri nesne no',
    'seri_no'     => 'seri no',
    'envanter_no' => 'envanter no',
    'mac_adresi'  => 'MAC adresi',
    'imei'        => 'IMEI',
];

/**
 * ⚠⚠ CİHAZIN KENDİ KİMLİĞİ — "bu iki kayıt farklı cihaz mı?" sorusuna YALNIZ bunlar cevap verir.
 * Üreticiden / IFS'ten gelirler, elle uydurulmazlar: iki kayıtta İKİSİ DE DOLU ve FARKLI ise
 * ortada mükerrer değil iki ayrı demirbaş vardır.
 *
 * `cihaz_kodu` ve `envanter_no` BİLEREK DIŞARIDA: ikisi de BİZİM kendi etiketimizdir.
 * envanter_no bizim sayacımız (IT-00001), mükerrer iki kartta zaten hep farklıdır; cihaz_kodu ise
 * elle yazılır ve içine sık sık MODEL NUMARASI (SM-T577) ya da SERİ NO yazılır. Bunlar çelişki
 * sayılırsa aynı cihazın iki kaydı "farklı cihaz" diye işaretlenip birleştirilemez hale gelir
 * (gerçek vaka: seri no'su aynı olan iki Galaxy Tab kaydı, biri kod alanına seri no yazıldığı için
 * ayrı sanılıyordu). Etiket farkları çelişki değil, birleştirme panelinde BİLGİ olarak gösterilir.
 */
const CIM_KIMLIK_ALAN = ['varlik_kodu', 'seri_no', 'mac_adresi', 'imei'];

/**
 * Aynı kimliği taşıyan cihaz gruplarını bulur.
 * Grup içindeki İLK kayıt ASIL (korunacak) adaydır: künyesi en dolu, belgesi/geçmişi
 * en çok olan kart kazanır — birleştirmede veri kaybı en aza insin diye.
 *
 * @return array<int, array{tur:string, anahtar:string, kayitlar:array}>
 */
function cim_cihaz_mukerrer(PDO $pdo): array
{
    try { $liste = $pdo->query("SELECT * FROM it_cihazlar")->fetchAll(); } catch (Throwable $e) { return []; }
    if (!$liste) return [];

    // Belge / hareket sayıları tek sorguda (satır başına sorgu N+1 olurdu)
    $belge = []; $hareket = [];
    try { foreach ($pdo->query("SELECT cihaz_id, COUNT(*) n FROM it_belgeler GROUP BY cihaz_id") as $b) $belge[(int)$b['cihaz_id']] = (int)$b['n']; } catch (Throwable $e) {}
    try { foreach ($pdo->query("SELECT cihaz_id, COUNT(*) n FROM it_hareketler GROUP BY cihaz_id") as $h) $hareket[(int)$h['cihaz_id']] = (int)$h['n']; } catch (Throwable $e) {}

    // Doluluk puanı: kimlik alanları ağır basar, künye/evrak/geçmiş ekler
    $puan = function (array $c) use ($belge, $hareket): int {
        $p = 0;
        foreach (['varlik_kodu'=>40, 'seri_no'=>30, 'cihaz_kodu'=>20, 'envanter_no'=>10, 'snipe_id'=>5] as $kol => $ag)
            if (trim((string)($c[$kol] ?? '')) !== '') $p += $ag;
        foreach (['marka','model','model_no','sasi_no','imei','islemci','ram','disk','ekran_karti',
                  'ip_adresi','mac_adresi','personel_id','lokasyon_id','notlar','ozellikler','foto_url'] as $kol)
            if (trim((string)($c[$kol] ?? '')) !== '') $p += 3;
        return $p + ($belge[(int)$c['id']] ?? 0) * 6 + ($hareket[(int)$c['id']] ?? 0) * 2;
    };

    $gruplar = [];
    foreach (CIM_MUKERRER_ANAHTAR as $kol => $etiket) {
        $kova = [];
        foreach ($liste as $c) {
            $v = pim_norm((string)($c[$kol] ?? ''));
            if ($v === '' || $v === '-') continue;
            $kova[$v][] = $c;
        }
        foreach ($kova as $anahtar => $kayitlar) {
            if (count($kayitlar) < 2) continue;
            $ids = array_map(fn($c) => (int)$c['id'], $kayitlar); sort($ids);
            $imza = implode('-', $ids);
            if (isset($gruplar[$imza])) continue;              // aynı çift iki anahtardan da eşleşmiş
            foreach ($kayitlar as &$c) { $c['belge'] = $belge[(int)$c['id']] ?? 0; $c['hareket'] = $hareket[(int)$c['id']] ?? 0; }
            unset($c);
            usort($kayitlar, fn($a, $b) => ($puan($b) <=> $puan($a)) ?: ((int)$a['id'] <=> (int)$b['id']));
            $celiski = cim_kimlik_celiskisi($kayitlar, $kol);
            $gruplar[$imza] = [
                'etiket_farki' => cim_etiket_farki($kayitlar, $kol),
                'tur'      => $etiket,
                'kolon'    => $kol,
                'anahtar'  => (string)$anahtar,
                'kayitlar' => $kayitlar,
                'celiski'  => $celiski,
                // ⚠ Çelişki varsa bunlar MÜKERRER DEĞİL, ayrı cihazlardır (aşağıdaki açıklamaya bak)
                'ayri'     => $celiski !== [],
                'model_kodu' => $kol === 'cihaz_kodu' && cim_model_numarasi_mi($kayitlar, (string)$anahtar),
            ];
        }
    }
    return array_values($gruplar);
}

/**
 * ⚠⚠ MÜKERRER Mİ, FARKLI CİHAZ MI? — **İŞ KURALI: farklı IFS seri nesne no + farklı seri no =
 * FARKLI CİHAZ.** Aynı modelden onlarca adet olabilir (10 Samsung Galaxy Tab Active 3), hepsi
 * aynı model numarasını taşır ama her biri ayrı bir demirbaştır. Bu yüzden çelişkili gruplar
 * mükerrer SAYILMAZ (`ayri=true`), birleştirme listesine girmez; ekranda ayrı bir
 * "aynı kodu taşıyan farklı cihazlar" bölümünde veri hatası olarak gösterilir.
 *
 * Gruptaki kayıtlar BAŞKA bir kimlik alanında birbirinden
 * FARKLI dolu değer taşıyorsa bu büyük ihtimalle mükerrer değil, **veri hatasıdır**: aynı cihaz
 * koduna yanlışlıkla iki ayrı cihaz yazılmıştır (ör. N405 kodunda bir Lenovo + bir Acer, seri
 * numaraları ve IFS kodları apayrı). Bunlar birleştirilirse ikinci cihaz envanterden SİLİNMİŞ olur.
 * Bu yüzden çelişen alanlar bulunup ekranda kırmızı uyarı olarak gösterilir.
 *
 * @param string $eslesenKolon grubu oluşturan kolon (kendisi çelişki sayılmaz)
 * @return array<string,string> çelişen alan adı => "değer | değer"
 */
/**
 * Etiket farkları — çelişki DEĞİL, birleştirme panelinde gösterilecek BİLGİ.
 * Kurum içi etiketlerimiz (cihaz kodu · envanter no) iki kartta farklı olabilir; birleştirmede
 * hedefin dolu değeri korunur. Etiket alanına yanlışlıkla seri no / model yazılmışsa söylenir —
 * kullanıcı hangi kodun gerçek demirbaş etiketi olduğunu görsün.
 *
 * @return array<string,string> etiket => "deger1 | deger2 (açıklama)"
 */
function cim_etiket_farki(array $kayitlar, string $eslesenKolon): array
{
    $fark = [];
    foreach (['cihaz_kodu' => 'cihaz kodu', 'envanter_no' => 'envanter no'] as $kol => $etiket) {
        if ($kol === $eslesenKolon) continue;
        $degerler = [];
        foreach ($kayitlar as $c) {
            $v = trim((string)($c[$kol] ?? ''));
            if ($v === '' || $v === '-') continue;
            $n = pim_norm($v);
            $not = '';
            if ($kol === 'cihaz_kodu') {
                if ($n !== '' && $n === pim_norm((string)($c['seri_no'] ?? ''))) $not = ' (seri no yazılmış)';
                elseif ($n !== '' && $n === pim_norm((string)($c['model'] ?? ''))) $not = ' (model no yazılmış)';
            }
            $degerler[$n] = $v . $not;
        }
        if (count($degerler) > 1) $fark[$etiket] = implode(' | ', $degerler);
    }
    return $fark;
}

function cim_kimlik_celiskisi(array $kayitlar, string $eslesenKolon): array
{
    // ⚠ Yalnız CİHAZIN KENDİ kimliğine bakılır (CIM_KIMLIK_ALAN) — bizim etiketlerimiz
    // (cihaz_kodu · envanter_no) çelişki SAYILMAZ, gerekçesi sabitin başında.
    $celiski = [];
    foreach (CIM_MUKERRER_ANAHTAR as $kol => $etiket) {
        if ($kol === $eslesenKolon || !in_array($kol, CIM_KIMLIK_ALAN, true)) continue;
        $degerler = [];
        foreach ($kayitlar as $c) {
            $v = trim((string)($c[$kol] ?? ''));
            if ($v !== '' && $v !== '-') $degerler[pim_norm($v)] = $v;
        }
        if (count($degerler) > 1) $celiski[$etiket] = implode(' | ', $degerler);
    }
    return $celiski;
}

/**
 * Gruptaki cihaz kodu aslında bir **MODEL NUMARASI mı**? (SM-T577 gibi)
 *
 * ⚠ Sahada en sık görülen veri hatası: demirbaş etiketi alanına (`cihaz_kodu`) cihazın MODELİ
 * yazılmış. O zaman aynı modelden kaç adet varsa hepsi aynı "kodu" taşır — 10 Samsung Galaxy
 * Tab Active 3 hepsi SM-T577 olur. Bunlar mükerrer değildir; yalnız kod alanı yanlış doldurulmuştur.
 * Gruptaki HER kaydın `model` alanı o kodla aynıysa bunu kesin biliriz ve tek tıkla temizlenebilir
 * (kod zaten Model alanında duruyor, bilgi kaybı olmaz).
 */
function cim_model_numarasi_mi(array $kayitlar, string $anahtar): bool
{
    if ($anahtar === '') return false;
    foreach ($kayitlar as $c) {
        if (pim_norm((string)($c['model'] ?? '')) !== $anahtar) return false;
    }
    return true;
}

/**
 * İki cihaz kartını birleştirir: $hedefId KORUNUR, $kaynakId silinir.
 *
 * • Hedefte BOŞ olan alanlar kaynaktan tamamlanır — dolu alan ASLA ezilmez.
 * • `notlar` birleştirilir (kaynağın notu kaybolmasın).
 * • Yaşam günlüğü (`it_hareketler`) ve belgeler (`it_belgeler`) hedefe TAŞINIR.
 *   ⚠ Aynı dosya iki karta da yüklenmişse (md5 aynı) ikinci kayıt eklenmez, silinir —
 *   yoksa birleşmiş kartta aynı tutanak iki kez görünürdü.
 * • Kameranın bağlı olduğu NVR gibi `bagli_id` bağları hedefe yönlendirilir.
 * • ⚠ `envanter_no` UNIQUE olduğundan kaynağınki hedefe YAZILAMAZ (hedefinki doluysa);
 *   silinip kaybolmasın diye birleştirme notuna işlenir.
 * • Hedefin günlüğüne, neyin nereden geldiğini yazan bir "not" satırı eklenir.
 *
 * @return array{hedef:string, kaynak:string, hareket:int, belge:int, mukerrer_belge:int, tamamlanan:array, bagli:int}
 */
function cim_cihaz_birlestir(PDO $pdo, int $hedefId, int $kaynakId): array
{
    if ($hedefId === $kaynakId) throw new RuntimeException('Bir cihaz kendisiyle birleştirilemez.');
    $al = function (int $id) use ($pdo) {
        $st = $pdo->prepare("SELECT * FROM it_cihazlar WHERE id=?"); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $h = $al($hedefId); $k = $al($kaynakId);
    if (!$h || !$k) throw new RuntimeException('Cihaz kaydı bulunamadı.');

    $kunye = fn(array $c) => trim(($c['cihaz_kodu'] ?: $c['envanter_no'] ?: ('#' . $c['id'])) . ' — ' . ($c['ad'] ?? ''));
    // Hedefte zaten dosyası olan belgelerin md5'i (mükerrer belge taşımamak için)
    $mevcutMd5 = it_belge_md5ler($pdo, $hedefId);

    $pdo->beginTransaction();
    try {
        // 1) Hedefte BOŞ olan alanları kaynaktan tamamla
        $atla = ['id', 'created_at', 'updated_at', 'notlar'];
        $set = []; $par = []; $tamamlanan = [];
        foreach ($h as $kol => $v) {
            if (in_array($kol, $atla, true)) continue;
            if ($v !== null && trim((string)$v) !== '') continue;
            $y = $k[$kol] ?? null;
            if ($y === null || trim((string)$y) === '') continue;
            $set[] = "$kol=?"; $par[] = $y; $tamamlanan[$kol] = $y;
        }
        // 2) Notlar birleşir (üzerine yazılmaz)
        $nH = trim((string)($h['notlar'] ?? '')); $nK = trim((string)($k['notlar'] ?? ''));
        if ($nK !== '' && !str_contains($nH, $nK)) { $set[] = 'notlar=?'; $par[] = trim($nH . "\n" . $nK); }
        if ($set) { $par[] = $hedefId; $pdo->prepare("UPDATE it_cihazlar SET " . implode(', ', $set) . " WHERE id=?")->execute($par); }

        // 3) Yaşam günlüğü hedefe taşınır
        $u = $pdo->prepare("UPDATE it_hareketler SET cihaz_id=? WHERE cihaz_id=?");
        $u->execute([$hedefId, $kaynakId]);
        $hareket = $u->rowCount();

        // 4) Belgeler taşınır — aynı dosya hedefte varsa (md5) ikinci kayıt eklenmez
        $belge = 0; $mukerrerBelge = 0;
        $bst = $pdo->prepare("SELECT * FROM it_belgeler WHERE cihaz_id=? ORDER BY id");
        $bst->execute([$kaynakId]);
        foreach ($bst->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $yol = __DIR__ . '/../' . (string)($b['dosya_url'] ?? '');
            $md5 = is_file($yol) ? md5_file($yol) : null;
            if ($md5 !== null && isset($mevcutMd5[$md5])) {
                // Aynı bayt hedefte zaten var → kaynak satırı sil (dosya son bağ koparsa diskten gider)
                it_belge_sil($pdo, (int)$b['id']);
                $mukerrerBelge++;
                continue;
            }
            $pdo->prepare("UPDATE it_belgeler SET cihaz_id=? WHERE id=?")->execute([$hedefId, (int)$b['id']]);
            if ($md5 !== null) $mevcutMd5[$md5] = true;
            $belge++;
        }

        // 5) Bu cihaza BAĞLI cihazlar (kamera → NVR) hedefe yönlendirilir
        $bagli = 0;
        try {
            $bu = $pdo->prepare("UPDATE it_cihazlar SET bagli_id=? WHERE bagli_id=?");
            $bu->execute([$hedefId, $kaynakId]);
            $bagli = $bu->rowCount();
        } catch (Throwable $e) { /* bagli_id kolonu yoksa atla */ }

        // 6) Kaynağı sil ve hedefin günlüğüne izini bırak
        $pdo->prepare("DELETE FROM it_cihazlar WHERE id=?")->execute([$kaynakId]);

        $iz = [];
        foreach (['cihaz_kodu'=>'Cihaz kodu', 'varlik_kodu'=>'IFS nesne no', 'envanter_no'=>'Envanter no',
                  'seri_no'=>'Seri no', 'sasi_no'=>'Şasi no', 'imei'=>'IMEI'] as $kol => $et) {
            $v = trim((string)($k[$kol] ?? ''));
            if ($v !== '' && $v !== trim((string)($h[$kol] ?? ''))) $iz[] = "$et: $v";
        }
        it_hareket_ekle($pdo, $hedefId, 'not', null,
            'Mükerrer kayıt birleştirildi — "' . $kunye($k) . '" (#' . $kaynakId . ') bu karta katıldı'
            . ($hareket ? ", $hareket hareket" : '') . ($belge ? ", $belge belge" : '')
            . ($mukerrerBelge ? ", $mukerrerBelge mükerrer belge atlandı" : '') . '.'
            . ($iz ? ' Silinen kayıttaki bilgiler → ' . implode(' · ', $iz) : ''));

        if ($pdo->inTransaction()) $pdo->commit();
        return ['hedef' => $kunye($h), 'kaynak' => $kunye($k), 'hareket' => $hareket, 'belge' => $belge,
                'mukerrer_belge' => $mukerrerBelge, 'tamamlanan' => $tamamlanan, 'bagli' => $bagli];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Model numarası yanlışlıkla **demirbaş etiketi** alanına yazılmış cihazlarda `cihaz_kodu`yu temizler.
 *
 * ⚠ Yalnız `cim_model_numarasi_mi()` doğrulanmış gruplarda çağrılmalı: kod, o cihazların `model`
 * alanıyla birebir aynı olmalı — yoksa gerçek demirbaş etiketi silinir. Bilgi kaybı yoktur
 * (kod zaten Model alanında duruyor); cihazlar bundan sonra mükerrer sanılmaz.
 *
 * @return array{temizlenen:int, atlanan:int}
 */
function cim_model_kodu_temizle(PDO $pdo, string $kod): array
{
    $n = pim_norm($kod);
    if ($n === '') throw new RuntimeException('Kod boş olamaz.');
    $st = $pdo->prepare("SELECT id, cihaz_kodu, model FROM it_cihazlar WHERE cihaz_kodu IS NOT NULL AND cihaz_kodu <> ''");
    $st->execute();
    $temizlenen = 0; $atlanan = 0;
    $u = $pdo->prepare("UPDATE it_cihazlar SET cihaz_kodu = NULL WHERE id = ?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (pim_norm((string)$c['cihaz_kodu']) !== $n) continue;
        // Güvenlik: kod gerçekten o cihazın MODELİ mi? Değilse dokunma (gerçek etiket olabilir)
        if (pim_norm((string)($c['model'] ?? '')) !== $n) { $atlanan++; continue; }
        $u->execute([(int)$c['id']]);
        $temizlenen++;
    }
    return ['temizlenen' => $temizlenen, 'atlanan' => $atlanan];
}

/**
 * Mevcut kayıtları YENİ küçük donanım kategorilerine ayıklar (2026-09-17).
 *
 * "Klavye" · "Mouse" · "Kulaklık" · "USB Bellek" · "Taşınabilir Disk" eskiden tek bir
 * `aksesuar` kovasındaydı; kategori eklendikten sonra eski kayıtlar orada kalır ve
 * "kimde kaç taşınabilir disk var" sorusu yine cevapsız olurdu.
 *
 * ⚠ YALNIZ bu beş kategoriye taşır ve yalnız `aksesuar`/`diger`den alır — başka bir
 * kategori kaymasın (bir cihazın kategorisi elle düzeltilmiş olabilir). Cihaz ADI,
 * yoksa modeli okunur; eşleşmeyen satıra dokunulmaz.
 *
 * @param bool $uygula false ise yalnız SAYAR (ekranda "N kayıt ayıklanabilir" bandı için).
 * @return array ['tasinan'=>int, 'kirilim'=>[kategori=>adet], 'ornek'=>[satır listesi]]
 */
function cim_kategori_ayikla(PDO $pdo, bool $uygula = true): array
{
    $hedef  = ['harici_disk', 'usb_bellek', 'klavye', 'mouse', 'kulaklik'];
    $sonuc  = ['tasinan' => 0, 'kirilim' => [], 'ornek' => []];
    $st = $pdo->query("SELECT id, ad, model, envanter_no, cihaz_kodu, kategori
                       FROM it_cihazlar WHERE kategori IN ('aksesuar','diger')");
    $upd = $pdo->prepare("UPDATE it_cihazlar SET kategori=? WHERE id=?");
    foreach ($st->fetchAll() as $r) {
        $metin = trim((string)$r['ad']) !== '' ? (string)$r['ad'] : (string)$r['model'];
        $yeni  = cim_kategori($metin);
        if (!in_array($yeni, $hedef, true) || $yeni === $r['kategori']) continue;
        if ($uygula) $upd->execute([$yeni, (int)$r['id']]);
        $sonuc['tasinan']++;
        $sonuc['kirilim'][$yeni] = ($sonuc['kirilim'][$yeni] ?? 0) + 1;
        if (count($sonuc['ornek']) < 40)
            $sonuc['ornek'][] = trim(($r['cihaz_kodu'] ?: $r['envanter_no']) . ' ' . $metin)
                              . ' → ' . (IT_KATEGORI[$yeni][0] ?? $yeni);
    }
    return $sonuc;
}
