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
    'varlik_kodu'  => ['etiket' => 'Varlık / Seri Nesne Kodu', 'es' => ['SERI NESNE KODU','SERINESNE KODU','IFS SERI NESNE NO','IFS NESNE NO','IFS CIHAZ KODU','NESNE NO','VARLIK KODU','ZIMMET KODU','ASSET CODE','ASSET ID','BARKOD','BARKOD NO','ETIKET NO','TAG']],
    'cihaz_kodu'   => ['etiket' => 'Cihaz Kodu (demirbaş etiketi)', 'es' => ['CIHAZ KODU','CIHAZKODU','DEMIRBAS ETIKETI','ASSET TAG','DEMIRBAS KODU','DEMIRBAS NO','DEMIRBAS SIRA','ETIKET','SIRA KODU','DEVICE CODE','DEVICE ID']],
    'envanter_no'  => ['etiket' => 'Envanter No (IT-00001)',   'es' => ['ENVANTER NO','ENVANTER KODU','INVENTORY NO']],
    'ad'           => ['etiket' => 'Cihaz Adı / Cinsi',        'es' => ['SERI NESNE ADI','SERINESNE ADI','DEMIRBAS ADI','CIHAZ ADI','CIHAZ CINSI','CINSI','MALZEME ADI','URUN ADI','TANIM','ACIKLAMA ADI','ITEM','ITEM NAME','ASSET NAME','DESCRIPTION']],
    'kategori'     => ['etiket' => 'Kategori',                 'es' => ['KATEGORI','KATEGORISI','TUR','TURU','CIHAZ TURU','GRUP','GRUBU','CATEGORY','TYPE']],
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
    'fiyat'        => ['etiket' => 'Fiyat',                    'es' => ['FIYAT','SATIN ALMA UCRETI','BIRIM FIYAT','TUTAR','BEDEL','PRICE','COST','AMOUNT']],
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
    'disk'         => ['etiket' => 'Disk / HDD (birleşir)',  'es' => ['HDD','HDD MODELI','HDD BILGISI','DISK','SSD','DEPOLAMA','SABIT DISK']],
    'anakart'      => ['etiket' => 'Anakart',            'es' => ['ANAKART','MAINBOARD','MOTHERBOARD']],
    'ekran_boyutu' => ['etiket' => 'Ekran Boyutu',       'es' => ['EKRAN BOYUTU','EKRAN','COZUNURLUK','SCREEN SIZE']],
    'imei'         => ['etiket' => 'IMEI',               'es' => ['IMEI','IMEI NO','IMEI NUMARASI']],
    'kapasite'     => ['etiket' => 'Kapasite',           'es' => ['KAPASITE','KAPASITESI','CAPACITY']],
    'kiralik_firma'=> ['etiket' => 'Kiralanan Firma',    'es' => ['KIRALANAN FIRMA','KIRALIK FIRMA','KIRALAYAN FIRMA']],
    'ozellik'      => ['etiket' => 'Diğer teknik özellik (birleşir)','es' => ['TEKNIK OZELLIK','TEKNIK OZELLIKLER','OZELLIK','OZELLIKLER','SPECS','SPECIFICATION']],
    'sicil_no'     => ['etiket' => 'Zimmetli kişinin sicil no', 'es' => ['CALISAN NUMARASI','SICIL NO','SICIL','PERSONEL NO','EMPLOYEE NUMBER','EMPLOYEE NO']],
    'snipe_id'     => ['etiket' => 'Snipe-IT Kimlik (belge köprüsü)', 'es' => ['KIMLIK','SNIPE ID','SNIPE-IT ID','ASSET ID']],
    'sirket'       => ['etiket' => 'Şirket',                  'es' => ['SIRKET','SIRKETI','COMPANY','FIRMA ADI']],
    'notlar'       => ['etiket' => 'Not (nota eklenir)',       'es' => ['NOT','NOTLAR','ACIKLAMA','DESCRIPTION','REMARKS','COMMENT','INFO','DEMIRBAS DURUMU']],
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
    // ⚠ SIRA ÖNEMLİ: özel tipler önce denenir — "IP KAMERA" genel 'kamera' aksesuarına değil
    // güvenlik kategorisine, "IP TELEFON" cep telefonuna değil iletişim kategorisine düşmeli.
    $harita = [
        'ip_telefon'   => ['IP TELEFON','MASA TELEFONU','VOIP','SIP TELEFON','DAHILI TELEFON'],
        'santral'      => ['SANTRAL','PBX','IP SANTRAL','TELEFON SANTRALI'],
        'hat'          => ['TELEFON HATTI','GSM HAT','SABIT HAT','DATA HAT','HAT NUMARASI'],
        'nvr'          => ['NVR','DVR','KAYIT CIHAZI','KAMERA KAYIT'],
        'kamera'       => ['IP KAMERA','GUVENLIK KAMERASI','KAMERA SISTEMI','DOME KAMERA','BULLET KAMERA','PTZ'],
        'kartli_gecis' => ['KARTLI GECIS','GECIS KONTROL','ACCESS CONTROL','KART OKUYUCU','PDKS'],
        'turnike'      => ['TURNIKE','BARIYER','TURNSTILE'],
        'firewall'     => ['FIREWALL','GUVENLIK DUVARI','UTM','FORTIGATE','SOPHOS','PALO ALTO'],
        'switch'       => ['SWITCH','OMURGA','ANAHTAR CIHAZ','POE SWITCH'],
        'access_point' => ['ACCESS POINT','ERISIM NOKTASI','KABLOSUZ AP','WIFI AP'],
        'superbox'     => ['SUPERBOX','SUPER BOX','MOBIL MODEM','4.5G MODEM','LTE MODEM'],
        'tv'           => ['TELEVIZYON','TV','SMART TV','LED TV','EKRAN PANEL','DIGITAL SIGNAGE'],
        'projeksiyon'  => ['PROJEKSIYON','PROJEKTOR','PROJECTOR','BEAMER'],
        'drone'        => ['DRONE','IHA','QUADCOPTER','DJI'],
        'bilesen'      => ['RAM','BELLEK MODULU','ISLEMCI','CPU','GUC KAYNAGI','POWER SUPPLY','ANAKART','SSD','HARDDISK','HARD DISK','EKRAN KARTI'],
        'sarf'         => ['TONER','KARTUS','KARTUS','DRUM','SARF','KAGIT','PIL','BATARYA','ETIKET SERIT'],
        'laptop'       => ['DIZUSTU','NOTEBOOK','LAPTOP','TASINABILIR BILGISAYAR'],
        'bilgisayar'   => ['MASAUSTU','DESKTOP','KASA','PC','BILGISAYAR','IS ISTASYONU','WORKSTATION','ALL IN ONE'],
        'monitor'      => ['MONITOR','EKRAN','DISPLAY','LCD','LED EKRAN'],
        'yazici'       => ['YAZICI','PRINTER','PLOTER','PLOTTER','CIZICI','TARAYICI','SCANNER','FOTOKOPI','COK FONKSIYONLU'],
        'telefon'      => ['TELEFON','PHONE','CEP'],
        'tablet'       => ['TABLET','IPAD'],
        'ag'           => ['ROUTER','MODEM','AG CIHAZI','NETWORK'],
        'sunucu'       => ['SUNUCU','SERVER','NAS','DEPOLAMA UNITESI','STORAGE'],
        'yazilim'      => ['LISANS','LICENSE','YAZILIM','SOFTWARE','OFFICE','WINDOWS LISANS','ANTIVIRUS'],
        'fotograf'     => ['FOTOGRAF MAKINE','FOTOGRAF MAKINESI','AKSIYON KAMERA','VIDEO KAMERA','KAMERA','CAMERA'],
        'aksesuar'     => ['KLAVYE','MOUSE','FARE','KULAKLIK','DOCK','ADAPTOR','WEBCAM','HOPARLOR','CANTA','HARICI DISK','UPS','BARKOD','KABLO'],
    ];
    foreach ($harita as $anahtar => $kelimeler) foreach ($kelimeler as $k) if (str_contains($n, $k)) return $anahtar;
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
                'zimmet_tarihi','alis_tarihi','garanti_bitis','fiyat','tedarikci','fatura_no','sirket','snipe_id','ip_adresi','mac_adresi','isletim_sistemi',
                'islemci','ram','ekran_karti','disk','anakart','ekran_boyutu','kapasite','kiralik_firma','ozellikler'];

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
                'fiyat'       => cim_fiyat($v['fiyat']),
                'tedarikci'   => $v['tedarikci'] ?: null, 'fatura_no' => $v['fatura_no'] ?: null,
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
            ];
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
