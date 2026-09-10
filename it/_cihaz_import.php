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
    'varlik_kodu'  => ['etiket' => 'Varlık / Seri Nesne Kodu', 'es' => ['SERI NESNE KODU','SERINESNE KODU','VARLIK KODU','DEMIRBAS KODU','DEMIRBAS NO','ZIMMET KODU','ASSET CODE','ASSET TAG','ASSET ID','BARKOD','BARKOD NO','ETIKET NO','TAG']],
    'envanter_no'  => ['etiket' => 'Envanter / Cihaz Kodu',    'es' => ['CIHAZ KODU','CIHAZKODU','ENVANTER NO','ENVANTER KODU','DEMIRBAS SIRA','KOD','SIRA KODU','INVENTORY NO','DEVICE CODE','DEVICE ID','ID']],
    'ad'           => ['etiket' => 'Cihaz Adı / Cinsi',        'es' => ['SERI NESNE ADI','SERINESNE ADI','CIHAZ ADI','CIHAZ CINSI','CINSI','MALZEME ADI','DEMIRBAS ADI','URUN ADI','TANIM','ACIKLAMA ADI','ITEM','ITEM NAME','ASSET NAME','DESCRIPTION']],
    'kategori'     => ['etiket' => 'Kategori',                 'es' => ['KATEGORI','KATEGORISI','TUR','TURU','CIHAZ TURU','GRUP','GRUBU','CATEGORY','TYPE']],
    'marka'        => ['etiket' => 'Marka',                    'es' => ['MARKA','MARKASI','BRAND','MANUFACTURER','URETICI']],
    'model'        => ['etiket' => 'Model',                    'es' => ['MODEL','MODELI','MODEL NO','MODEL ADI']],
    'seri_no'      => ['etiket' => 'Seri No',                  'es' => ['SERI NO','SERINO','SERI NUMARASI','SERIAL','SERIAL NO','SERIAL NUMBER','SN']],
    'sasi_no'      => ['etiket' => 'Şasi No (2. seri)',        'es' => ['SASI NO','SASINO','SASE NO','CHASSIS','CHASSIS NO','SERVICE TAG','SERVIS ETIKETI']],
    'lokasyon'     => ['etiket' => 'Lokasyon / Mevcut Proje',  'es' => ['MEVCUT PROJE','MEVCUTPROJE','PROJE','PROJESI','PROJE KODU','LOKASYON','KONUM','SANTIYE','OFIS','LOCATION','SITE','OFFICE','BULUNDUGU YER']],
    'ilk_lokasyon' => ['etiket' => 'İlk Proje (nota yazılır)', 'es' => ['ILK PROJE','ILKPROJE','ILK LOKASYON','ONCEKI PROJE','ESKI PROJE','ORIGINAL PROJECT']],
    'kisi'         => ['etiket' => 'Zimmetli Kişi',            'es' => ['KISI','KISI ADI','ZIMMETLI','ZIMMETLI KISI','ZIMMET','KULLANICI','KULLANAN','PERSONEL','PERSONEL ADI','AD SOYAD','ADI SOYADI','SORUMLU','ASSIGNED TO','USER','OWNER','EMPLOYEE']],
    'departman'    => ['etiket' => 'Departman / Birim',        'es' => ['DEPARTMAN','BIRIM','BOLUM','DEPARTMENT','UNIT']],
    'durum'        => ['etiket' => 'Durum',                    'es' => ['DURUM','DURUMU','STATUS','CIHAZ DURUMU','KULLANIM DURUMU']],
    'alis_tarihi'  => ['etiket' => 'Alış Tarihi',              'es' => ['ALIS TARIHI','SATIN ALMA TARIHI','ALIM TARIHI','FATURA TARIHI','PURCHASE DATE','BUY DATE']],
    'garanti_bitis'=> ['etiket' => 'Garanti Bitiş',            'es' => ['GARANTI BITIS','GARANTI BITIS TARIHI','GARANTI','WARRANTY','WARRANTY END']],
    'fiyat'        => ['etiket' => 'Fiyat',                    'es' => ['FIYAT','BIRIM FIYAT','TUTAR','BEDEL','DEGER','PRICE','COST','AMOUNT']],
    'tedarikci'    => ['etiket' => 'Tedarikçi',                'es' => ['TEDARIKCI','SATICI','FIRMA','VENDOR','SUPPLIER']],
    'fatura_no'    => ['etiket' => 'Fatura No',                'es' => ['FATURA NO','FATURANO','INVOICE','INVOICE NO']],
    'ip_adresi'    => ['etiket' => 'IP Adresi',                'es' => ['IP','IP ADRESI','IP ADDRESS']],
    'mac_adresi'   => ['etiket' => 'MAC Adresi',               'es' => ['MAC','MAC ADRESI','MAC ADDRESS']],
    'isletim'      => ['etiket' => 'İşletim Sistemi',          'es' => ['ISLETIM SISTEMI','ISLETIM','OS','OPERATING SYSTEM','WINDOWS']],
    'ozellik'      => ['etiket' => 'Teknik özellik (birleşir)','es' => ['TEKNIK OZELLIK','TEKNIK OZELLIKLER','OZELLIK','OZELLIKLER','ISLEMCI MARKA','ISLEMCI MODEL','ISLEMCI','CPU','PROCESSOR','RAM','RAM TIPI','BELLEK','EKRAN KARTI','EKRAN KARTI MODELI','GPU','HDD','HDD MODELI','DISK','SSD','DEPOLAMA','EKRAN','COZUNURLUK','SPECS','SPECIFICATION']],
    'notlar'       => ['etiket' => 'Not (nota eklenir)',       'es' => ['NOT','NOTLAR','ACIKLAMA','DESCRIPTION','REMARKS','COMMENT','INFO','DEMIRBAS DURUMU','ZIMMET TARIHI']],
];

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
        'projeksiyon'  => ['PROJEKSIYON','PROJECTOR','BEAMER'],
        'bilesen'      => ['RAM','BELLEK MODULU','ISLEMCI','CPU','GUC KAYNAGI','POWER SUPPLY','ANAKART','SSD','HARDDISK','HARD DISK','EKRAN KARTI'],
        'sarf'         => ['TONER','KARTUS','KARTUS','DRUM','SARF','KAGIT','PIL','BATARYA','ETIKET SERIT'],
        'laptop'       => ['DIZUSTU','NOTEBOOK','LAPTOP','TASINABILIR BILGISAYAR'],
        'bilgisayar'   => ['MASAUSTU','DESKTOP','KASA','PC','BILGISAYAR','IS ISTASYONU','WORKSTATION','ALL IN ONE'],
        'monitor'      => ['MONITOR','EKRAN','DISPLAY','LCD','LED EKRAN'],
        'yazici'       => ['YAZICI','PRINTER','TARAYICI','SCANNER','FOTOKOPI','COK FONKSIYONLU'],
        'telefon'      => ['TELEFON','PHONE','CEP'],
        'tablet'       => ['TABLET','IPAD'],
        'ag'           => ['ROUTER','MODEM','AG CIHAZI','NETWORK'],
        'sunucu'       => ['SUNUCU','SERVER','NAS','DEPOLAMA UNITESI','STORAGE'],
        'yazilim'      => ['LISANS','LICENSE','YAZILIM','SOFTWARE','OFFICE','WINDOWS LISANS','ANTIVIRUS'],
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
    try { $pdo->query("SELECT varlik_kodu FROM it_cihazlar LIMIT 1"); }
    catch (Throwable $e) {
        try { $pdo->exec("ALTER TABLE it_cihazlar ADD COLUMN varlik_kodu VARCHAR(60) NULL"); } catch (Throwable $e2) {}
        try { $pdo->exec("CREATE INDEX ix_varlik ON it_cihazlar (varlik_kodu)"); } catch (Throwable $e2) {}
    }
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
        if (!in_array($bul, ['ozellik','notlar'], true) && isset($kul[$bul])) { $h[$i] = 'ozellik'; continue; }
        $h[$i] = $bul; $kul[$bul] = $i;
    }
    return $h;
}

/** Grid satırı → cihaz alanları (ham). 'ozellik'/'notlar' sütunları "Başlık: değer" olarak birikir. */
function cim_satir_cozumle(array $satir, array $harita, array $baslik): array
{
    $v = array_fill_keys(array_keys(CIM_ALAN), '');
    $v['ozellik'] = []; $v['notlar'] = [];
    foreach ($harita as $i => $k) {
        if ($k === '' || $k === null) continue;
        $c = trim((string)($satir[$i] ?? ''));
        if ($c === '') continue;
        if ($k === 'ozellik' || $k === 'notlar') { $v[$k][] = trim((string)($baslik[$i] ?? '')) . ': ' . $c; continue; }
        if ($v[$k] === '') $v[$k] = $c;
    }
    $v['marka'] = cim_marka($v['marka']);
    // Seri no boşsa şasi no seri sayılır; ikisi de doluysa şasi teknik nota gider
    if ($v['seri_no'] === '' && $v['sasi_no'] !== '') { $v['seri_no'] = $v['sasi_no']; $v['sasi_no'] = ''; }
    if ($v['sasi_no'] !== '') $v['ozellik'][] = 'Şasi No: ' . $v['sasi_no'];
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

/**
 * Cihaz içe aktarma (birleştirme). $opt: harita, baslik_idx, satir_no, kisi_ekle, kisi_durum, kullanici, dosya, bicim.
 * Dönüş: okunan/yeni/guncellenen/degismeyen/atlanan/kisi_yok/lokasyon_yok/kisi_eklenen listeleri.
 */
function cim_import(PDO $pdo, array $satirlar, array $opt): array
{
    $harita = $opt['harita']; $bIdx = (int)$opt['baslik_idx']; $baslik = $satirlar[$bIdx] ?? [];
    $r = ['okunan'=>0, 'yeni'=>[], 'guncellenen'=>[], 'degismeyen'=>0, 'atlanan'=>[], 'kisi_yok'=>[], 'lokasyon_yok'=>[], 'kisi_eklenen'=>[]];
    $alanlar = ['varlik_kodu','envanter_no','kategori','ad','marka','model','seri_no','durum','zimmetli','personel_id','departman','lokasyon','lokasyon_id',
                'zimmet_tarihi','alis_tarihi','garanti_bitis','fiyat','tedarikci','fatura_no','ip_adresi','mac_adresi','isletim_sistemi','ozellikler'];

    cim_semasi_kur($pdo);                       // ⚠ DDL transaction'ı örtük commit eder → ÖNCE
    $mevcut = $pdo->query("SELECT * FROM it_cihazlar")->fetchAll();
    $byVarlik = []; $byEnv = []; $bySeri = [];
    foreach ($mevcut as $m) {
        if (trim((string)($m['varlik_kodu'] ?? '')) !== '') $byVarlik[pim_norm($m['varlik_kodu'])] = $m;
        if (trim((string)$m['envanter_no']) !== '')         $byEnv[pim_norm($m['envanter_no'])] = $m;
        if (trim((string)($m['seri_no'] ?? '')) !== '')     $bySeri[pim_norm($m['seri_no'])] = $m;
    }
    // İstenirse dosyadaki eşleşmeyen kişiler için personel kartı açılır (sonra cihazlar bunlara bağlanır)
    if (!empty($opt['kisi_ekle'])) {
        $adlar = [];
        foreach ($satirlar as $i => $sat) {
            if ($i <= $bIdx) continue;
            $v = cim_satir_cozumle($sat, $harita, $baslik);
            if (trim($v['kisi']) !== '') $adlar[pim_norm($v['kisi'])] = trim($v['kisi']);
        }
        $n = cim_personel_ekle($pdo, $adlar);
        if ($n) { cim_personel_bul($pdo, '', true); $r['kisi_eklenen'] = ['adet' => $n]; }
    }

    $dosyaIci = [];
    $pdo->beginTransaction();
    try {
        foreach ($satirlar as $i => $sat) {
            if ($i <= $bIdx) continue;
            if (!array_filter($sat, fn($c) => trim((string)$c) !== '')) continue;
            $r['okunan']++;
            $exNo = (int)($opt['satir_no'][$i] ?? ($i + 1));
            $v = cim_satir_cozumle($sat, $harita, $baslik);
            $etiket = trim($v['envanter_no'] . ' ' . $v['ad']) ?: ($v['varlik_kodu'] ?: "satır $exNo");

            // Kimliksiz satır cihaz değildir
            if ($v['varlik_kodu'] === '' && $v['envanter_no'] === '' && $v['seri_no'] === '' && $v['ad'] === '') {
                $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>'kod / seri no / cihaz adı yok — cihaz satırı değil']; continue;
            }
            $dk = $v['varlik_kodu'] !== '' ? 'V:' . pim_norm($v['varlik_kodu'])
                : ($v['envanter_no'] !== '' ? 'E:' . pim_norm($v['envanter_no'])
                : ($v['seri_no'] !== '' ? 'S:' . pim_norm($v['seri_no']) : 'A:' . pim_norm($v['ad']) . $exNo));
            if (isset($dosyaIci[$dk])) { $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>'aynı dosyada tekrar (satır ' . $dosyaIci[$dk] . ')']; continue; }
            $dosyaIci[$dk] = $exNo;

            // Lokasyon: "U030" gibi proje kodu ya da lokasyon adı
            $lokId = null; $lokAd = '';
            if ($v['lokasyon'] !== '') {
                $lokId = pim_lokasyon_bul($pdo, $v['lokasyon']);
                if ($lokId) $lokAd = it_lokasyon_yol($pdo, $lokId);
                else { $r['lokasyon_yok'][$v['lokasyon']] = ($r['lokasyon_yok'][$v['lokasyon']] ?? 0) + 1; $lokAd = $v['lokasyon']; $v['notlar'][] = 'Lokasyon (dosyadan): ' . $v['lokasyon']; }
            }
            // Zimmetli kişi
            $personelId = null; $kisiAd = trim($v['kisi']);
            if ($kisiAd !== '') {
                [$p, $nasil] = cim_personel_bul($pdo, $kisiAd);
                if ($p) { $personelId = (int)$p['id']; $kisiAd = it_personel_ad($p); if (!$v['departman'] && $p['birim']) $v['departman'] = $p['birim']; }
                elseif ($nasil === 'coklu') $r['kisi_yok'][] = ['satir'=>$exNo, 'kisi'=>$kisiAd, 'neden'=>'aynı ad soyadlı birden çok personel'];
                else $r['kisi_yok'][] = ['satir'=>$exNo, 'kisi'=>$kisiAd, 'neden'=>'personel kartı yok'];
            }

            $durum = pim_norm($v['durum']) !== '' ? cim_durum($v['durum']) : null;
            if ($durum === null) $durum = $kisiAd !== '' ? 'aktif' : 'depoda';
            $yeni = [
                'varlik_kodu' => $v['varlik_kodu'] ?: null,
                'envanter_no' => $v['envanter_no'] ?: null,
                'kategori'    => $v['kategori'] !== '' ? cim_kategori($v['kategori']) : cim_kategori($v['ad']),
                'ad'          => $v['ad'] ?: ($v['model'] ?: ($v['marka'] ?: 'Cihaz')),
                'marka'       => $v['marka'] ?: null, 'model' => $v['model'] ?: null, 'seri_no' => $v['seri_no'] ?: null,
                'durum'       => $durum,
                'zimmetli'    => $kisiAd ?: null, 'personel_id' => $personelId,
                'departman'   => $v['departman'] ?: null,
                'lokasyon'    => $lokAd ?: null, 'lokasyon_id' => $lokId,
                'zimmet_tarihi'=> null,
                'alis_tarihi' => pim_tarih($v['alis_tarihi']), 'garanti_bitis' => pim_tarih($v['garanti_bitis']),
                'fiyat'       => $v['fiyat'] !== '' ? it_sayi($v['fiyat']) : null,
                'tedarikci'   => $v['tedarikci'] ?: null, 'fatura_no' => $v['fatura_no'] ?: null,
                'ip_adresi'   => $v['ip_adresi'] ?: null, 'mac_adresi' => $v['mac_adresi'] ?: null,
                'isletim_sistemi' => $v['isletim'] ?: null,
                'ozellikler'  => $v['ozellik'] ? mb_substr(implode(' · ', array_unique($v['ozellik'])), 0, 255) : null,
            ];
            $notSatiri = $v['notlar'] ? implode("\n", array_unique($v['notlar'])) : '';

            // Eşleşme: varlık kodu → envanter no → seri no
            $m = null; $nasil = '';
            if ($v['varlik_kodu'] !== '' && isset($byVarlik[pim_norm($v['varlik_kodu'])])) { $m = $byVarlik[pim_norm($v['varlik_kodu'])]; $nasil = 'varlık kodu'; }
            elseif ($v['envanter_no'] !== '' && isset($byEnv[pim_norm($v['envanter_no'])])) { $m = $byEnv[pim_norm($v['envanter_no'])]; $nasil = 'envanter no'; }
            elseif ($v['seri_no'] !== '' && isset($bySeri[pim_norm($v['seri_no'])])) { $m = $bySeri[pim_norm($v['seri_no'])]; $nasil = 'seri no'; }

            if ($m) {
                $set = []; $par = []; $degisen = []; $eskiKisi = trim((string)($m['zimmetli'] ?? ''));
                foreach ($alanlar as $k) {
                    $y = $yeni[$k] ?? null;
                    if ($y === null || $y === '') continue;                      // dosyada boş → mevcut korunur
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
                if ($yeni['zimmetli']) $yeni['zimmet_tarihi'] = date('Y-m-d');
                $kol = array_keys($yeni);
                $sql = "INSERT INTO it_cihazlar (" . implode(',', $kol) . ",notlar,olusturan) VALUES ("
                     . implode(',', array_fill(0, count($kol), '?')) . ",?,?)";
                $pdo->prepare($sql)->execute([...array_values($yeni), $notSatiri ?: null, $opt['kullanici'] ?? null]);
                $id = (int)$pdo->lastInsertId();
                it_hareket_ekle($pdo, $id, 'giris', $yeni['zimmetli'], 'Excel aktarımı ile envantere eklendi' . ($lokAd ? ' — ' . $lokAd : ''));
                if ($yeni['zimmetli']) it_hareket_ekle($pdo, $id, 'zimmet', $yeni['zimmetli'], 'Excel aktarımı ile zimmetlendi');
                $kayit = $yeni + ['id'=>$id];
                if ($yeni['varlik_kodu']) $byVarlik[pim_norm($yeni['varlik_kodu'])] = $kayit;
                $byEnv[pim_norm($yeni['envanter_no'])] = $kayit;
                if ($yeni['seri_no']) $bySeri[pim_norm($yeni['seri_no'])] = $kayit;
                $r['yeni'][] = ['id'=>$id, 'env'=>$yeni['envanter_no'], 'ad'=>$yeni['ad'], 'kategori'=>$yeni['kategori'],
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
    if (str_contains($n, 'DEPO') || str_contains($n, 'BOSTA') || str_contains($n, 'STOK')) return 'depoda';
    if (str_contains($n, 'KULLAN') || str_contains($n, 'ZIMMET') || str_contains($n, 'AKTIF')) return 'aktif';
    return null;
}

/** Dosyadaki eşleşmeyen kişileri personel olarak açar (rapordan tek tıkla). Dönüş: eklenen sayısı. */
function cim_personel_ekle(PDO $pdo, array $adlar): int
{
    $n = 0;
    foreach ($adlar as $ad) {
        $ad = trim((string)$ad);
        if ($ad === '') continue;
        [$p, ] = cim_personel_bul($pdo, $ad);
        if ($p) continue;
        [$adKisim, $soyKisim] = pim_ad_ayir(pim_bas_harf($ad));
        if ($adKisim === '') continue;
        $pdo->prepare("INSERT INTO it_personel (ad, soyad, notlar) VALUES (?,?,?)")
            ->execute([$adKisim, $soyKisim, 'Cihaz listesi aktarımından açıldı']);
        $n++;
    }
    return $n;
}
