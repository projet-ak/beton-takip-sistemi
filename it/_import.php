<?php
/**
 * it/_import.php — Personel listesi içe aktarma çekirdeği (IT Envanter)
 *
 * Kaynak: İK / Active Directory / Microsoft 365 "export users" dosyaları. Üç biçim okunur:
 *   • .xlsx  (SimpleXLSX)
 *   • .csv   (; , veya sekme ayraçlı; UTF-8 / Windows-1254 otomatik)
 *   • .xls / .htm / .html = Excel "Web Sayfası" biçimi (HTML <table>). ⚠ Excel bu biçimde iki parça
 *     üretir: çerçeve dosyası (yalnız frameset) + `…_dosyalar/sheet001.htm` (asıl tablo). Yalnız
 *     çerçeve yüklenirse `pim_oku()` bunu yakalar ve sheet001.htm'i istemesini söyler.
 *   • Gerçek ikili .xls (BIFF, D0CF11E0 imzası) desteklenmez → "Farklı Kaydet → .xlsx" mesajı.
 *
 * Akış: pim_oku() → grid · pim_baslik_satiri() → başlık satırı · pim_harita() → sütun→alan otomatik
 * eşleme (PIM_ALAN eş anlamlıları; kullanıcı ön izlemede değiştirir) · pim_satir_cozumle() → alanlar ·
 * pim_import() → BİRLEŞTİRME: eşleşme sırası sicil no → e-posta → normalize ad+soyad; yeni → INSERT,
 * mevcut → yalnız dosyada DOLU gelen alanlar güncellenir (boş hücre mevcut veriyi silmez),
 * `notlar` kullanıcı verisi olarak KORUNUR (dosyadan gelen not satırı yalnız eklenir).
 * "Dosyada olmayan çalışanları ayrılmış say" isteğe bağlıdır ve üzerinde zimmet olan kişi ATLANIR.
 */

const PIM_ALAN = [
    'sicil_no'    => ['etiket' => 'Sicil No',        'es' => ['SICIL','SICIL NO','SICILNO','SICIL NUMARASI','PERSONEL NO','PERSONELNO','PERSONEL ID','EMPLOYEE ID','EMPLOYEEID','EMPLOYEE NUMBER','EMPLOYEENUMBER','EMPLOYEE NO','STAFF NO','STAFF ID','KAYIT NO','REG NO','USER ID','USERID']],
    'ad'          => ['etiket' => 'Ad',              'es' => ['AD','ADI','ISIM','ISMI','FIRST NAME','FIRSTNAME','GIVEN NAME','GIVENNAME','FORENAME']],
    'soyad'       => ['etiket' => 'Soyad',           'es' => ['SOYAD','SOYADI','SOY AD','SOYISIM','LAST NAME','LASTNAME','SURNAME','SN','FAMILY NAME']],
    'ad_soyad'    => ['etiket' => 'Ad Soyad (tek sütun)', 'es' => ['AD SOYAD','ADI SOYADI','AD-SOYAD','ADSOYAD','AD VE SOYAD','ADI VE SOYADI','ISIM SOYISIM','DISPLAY NAME','DISPLAYNAME','FULL NAME','FULLNAME','CN','PERSONEL','PERSONEL ADI','CALISAN','KISI','KULLANICI','NAME']],
    'unvan'       => ['etiket' => 'Unvan',           'es' => ['UNVAN','UNVANI','TITLE','JOB TITLE','JOBTITLE','GOREV','GOREVI','POZISYON','POSITION','ROLE','ROL']],
    'birim'       => ['etiket' => 'Birim / Departman','es' => ['BIRIM','BIRIMI','DEPARTMAN','DEPARTMANI','DEPARTMENT','BOLUM','BOLUMU','DIREKTORLUK','MUDURLUK','DIVISION','UNIT','ORGANIZATIONAL UNIT','OU']],
    'lokasyon'    => ['etiket' => 'Lokasyon / Proje', 'es' => ['LOKASYON','LOKASYONU','KONUM','OFIS','OFFICE','OFFICE LOCATION','OFFICELOCATION','LOCATION','PHYSICALDELIVERYOFFICENAME','PROJE','PROJESI','PROJE KODU','SANTIYE','SITE','ISYERI','IS YERI','CALISTIGI YER','LOKASYON/PROJE']],
    'telefon'     => ['etiket' => 'Telefon',         'es' => ['TELEFON','TELEFONU','TEL','TEL NO','TELEFON NO','TELEFON NUMARASI','CEP','CEP TEL','CEP TELEFONU','GSM','MOBILE','MOBILE PHONE','MOBILEPHONE','MOBIL','PHONE','PHONE NUMBER','TELEPHONE','TELEPHONE NUMBER','TELEPHONENUMBER','OFFICE PHONE','BUSINESS PHONE','IS TELEFONU']],
    'eposta'      => ['etiket' => 'E-posta',         'es' => ['EPOSTA','E-POSTA','E POSTA','EMAIL','E-MAIL','E MAIL','MAIL','MAIL ADRESI','EMAIL ADDRESS','EMAILADDRESS','USER PRINCIPAL NAME','USERPRINCIPALNAME','UPN','PRIMARY SMTP','KURUMSAL MAIL']],
    'ise_giris'   => ['etiket' => 'İşe Giriş',       'es' => ['ISE GIRIS','ISE GIRIS TARIHI','ISE BASLAMA','ISE BASLAMA TARIHI','GIRIS TARIHI','GIRIS','BASLANGIC','BASLANGIC TARIHI','BASLAMA TARIHI','HIRE DATE','HIREDATE','START DATE','STARTDATE','DATE OF JOINING']],
    'isten_cikis' => ['etiket' => 'İşten Çıkış',     'es' => ['ISTEN CIKIS','ISTEN CIKIS TARIHI','ISTEN AYRILMA','AYRILMA TARIHI','CIKIS TARIHI','CIKIS','BITIS TARIHI','END DATE','ENDDATE','TERMINATION DATE','LEAVE DATE','LEAVING DATE']],
    'durum'       => ['etiket' => 'Durum (aktif/pasif)', 'es' => ['DURUM','DURUMU','STATUS','AKTIF','AKTIF MI','ENABLED','ACCOUNT ENABLED','ACCOUNTENABLED','ACTIVE','HESAP DURUMU','ACCOUNT STATUS','CALISMA DURUMU','BLOCK CREDENTIAL','BLOCKCREDENTIAL']],
    'notlar'      => ['etiket' => 'Not (nota eklenir)','es' => ['NOT','NOTLAR','ACIKLAMA','DESCRIPTION','REMARKS','COMMENT','COMMENTS','INFO','KULLANICI ADI','USERNAME','USER NAME','SAMACCOUNTNAME','LOGIN','LOGIN NAME','MANAGER','YONETICI','SIRKET','COMPANY','ADRES','ADDRESS','CITY','SEHIR']],
];

/** Başlık/eşleme için normalize: Türkçe harfler ASCII'ye katlanır, noktalama boşluğa, boşluklar teke. */
function pim_norm(string $s): string
{
    $s = str_replace(['İ','I','ı','i','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'],
                     ['I','I','I','I','S','S','G','G','U','U','O','O','C','C'], trim($s));
    $s = mb_strtoupper($s, 'UTF-8');
    $s = preg_replace('/[^A-Z0-9@+]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Dosyayı satır dizisine (grid) çevirir. Dönüş: ['satirlar'=>[[...]], 'bicim'=>'xlsx|csv|html', 'sayfa'=>?string] — hata → RuntimeException. */
function pim_oku(string $yol, string $ad): array
{
    $uz  = strtolower(pathinfo($ad, PATHINFO_EXTENSION));
    $bas = (string)file_get_contents($yol, false, null, 0, 2048);
    $magic = substr($bas, 0, 4);

    if ($magic === "PK\x03\x04") { // xlsx (zip)
        if (!class_exists('Shuchkin\\SimpleXLSX')) require_once __DIR__ . '/../vendor/autoload.php';
        $x = \Shuchkin\SimpleXLSX::parse($yol);
        if (!$x) throw new RuntimeException('Excel okunamadı: ' . \Shuchkin\SimpleXLSX::parseError());
        // En çok dolu satırı olan sayfa seçilir (ilk 20 satıra bakılarak)
        $enIyi = 0; $enIyiSkor = -1; $adlar = $x->sheetNames();
        foreach ($adlar as $i => $sad) {
            $skor = 0;
            foreach ($x->rows($i, 20) as $r) { $skor += count(array_filter($r, fn($c) => trim((string)$c) !== '')); }
            if ($skor > $enIyiSkor) { $enIyiSkor = $skor; $enIyi = $i; }
        }
        $satirlar = [];
        foreach ($x->rows($enIyi, 5000) as $r) $satirlar[] = array_map(fn($c) => trim((string)$c), $r);
        return ['satirlar' => $satirlar, 'bicim' => 'xlsx', 'sayfa' => $adlar[$enIyi] ?? null];
    }
    if ($magic === "\xD0\xCF\x11\xE0") { // BIFF ikili xls
        throw new RuntimeException('Bu dosya eski ikili Excel (.xls) biçiminde. Excel\'de açıp "Farklı Kaydet → Excel Çalışma Kitabı (.xlsx)" olarak kaydedin ve tekrar yükleyin.');
    }
    $icerik = (string)file_get_contents($yol);
    if (stripos($icerik, '<table') !== false || stripos($icerik, '<html') !== false || in_array($uz, ['htm','html','xls'], true) && stripos($icerik, '<') !== false) {
        return pim_html_oku($icerik);
    }
    return pim_csv_oku($icerik);
}

/** Excel "Web Sayfası" (HTML tablo) → grid. Frameset-yalnız dosyayı ayırt eder. */
function pim_html_oku(string $html): array
{
    // Karakter seti: meta charset / xml encoding; UTF-8 değilse dönüştür
    $cs = 'UTF-8';
    if (preg_match('/charset=["\']?([\w-]+)/i', $html, $m)) $cs = strtoupper($m[1]);
    if ($cs !== 'UTF-8' && $cs !== 'UTF8') {
        $don = @iconv($cs === 'WINDOWS-1254' || $cs === 'ISO-8859-9' ? 'WINDOWS-1254' : $cs, 'UTF-8//IGNORE', $html);
        if ($don !== false) $html = $don;
    } elseif (!mb_check_encoding($html, 'UTF-8')) {
        $html = (string)@iconv('WINDOWS-1254', 'UTF-8//IGNORE', $html);
    }
    $html = preg_replace('/charset=["\']?[\w-]+/i', 'charset=UTF-8', $html); // libxml meta charset'e bakar — dönüştürülmüş metin ikinci kez çözülmesin
    // Script/yorum blokları atılır — Excel çerçeve dosyasının JS'i içinde "<table" metni geçer, tablo sanılmasın
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('#<!--.*?-->#s', '', $html);
    if (stripos($html, '<table') === false) {
        if (stripos($html, '<frameset') !== false || stripos($html, 'sheet001.htm') !== false) {
            throw new RuntimeException('Bu dosya Excel "Web Sayfası" kaydının yalnız ÇERÇEVE parçasıdır, içinde veri yok. '
                . 'Asıl tablo yanındaki "…_dosyalar" klasöründeki sheet001.htm dosyasındadır — onu yükleyin, '
                . 'ya da Excel\'de "Farklı Kaydet → Excel Çalışma Kitabı (.xlsx)" ile kaydedip yükleyin.');
        }
        throw new RuntimeException('Dosyada tablo bulunamadı (HTML içinde <table> yok).');
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_use_internal_errors($prev);
    $enIyi = null; $enIyiN = 0;
    foreach ($doc->getElementsByTagName('table') as $t) {
        $n = $t->getElementsByTagName('tr')->length;
        if ($n > $enIyiN) { $enIyiN = $n; $enIyi = $t; }
    }
    if (!$enIyi) throw new RuntimeException('Dosyada tablo bulunamadı.');
    $satirlar = [];
    foreach ($enIyi->getElementsByTagName('tr') as $tr) {
        $row = [];
        foreach ($tr->childNodes as $c) {
            if (!($c instanceof DOMElement) || !in_array(strtolower($c->tagName), ['td','th'], true)) continue;
            $v = html_entity_decode($c->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $v = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $v));
            $row[] = $v;
            $span = (int)$c->getAttribute('colspan');
            for ($i = 1; $i < $span; $i++) $row[] = '';
        }
        $satirlar[] = $row;
    }
    return ['satirlar' => $satirlar, 'bicim' => 'html', 'sayfa' => null];
}

/** CSV → grid: BOM temizlenir, ayraç ilk satırdan sezilir, Windows-1254 ise UTF-8'e çevrilir. */
function pim_csv_oku(string $icerik): array
{
    if (substr($icerik, 0, 3) === "\xEF\xBB\xBF") $icerik = substr($icerik, 3);
    if (!mb_check_encoding($icerik, 'UTF-8')) $icerik = (string)@iconv('WINDOWS-1254', 'UTF-8//IGNORE', $icerik);
    $ilk = strtok($icerik, "\r\n") ?: '';
    $ayrac = ','; $enCok = substr_count($ilk, ',');
    foreach ([';', "\t", '|'] as $a) { if (substr_count($ilk, $a) > $enCok) { $enCok = substr_count($ilk, $a); $ayrac = $a; } }
    $satirlar = [];
    $fh = fopen('php://memory', 'r+'); fwrite($fh, $icerik); rewind($fh);
    while (($r = fgetcsv($fh, 0, $ayrac, '"', '\\')) !== false) {
        if ($r === [null]) continue;
        $satirlar[] = array_map(fn($c) => trim((string)$c), $r);
        if (count($satirlar) > 5000) break;
    }
    fclose($fh);
    if (!$satirlar) throw new RuntimeException('Dosya boş ya da okunamadı.');
    return ['satirlar' => $satirlar, 'bicim' => 'csv', 'sayfa' => null];
}

/** Başlık satırı: ilk 15 satırda bilinen alan adlarıyla en çok eşleşen satır (eşleşme yoksa en çok dolu hücreli). */
function pim_baslik_satiri(array $satirlar): int
{
    $enIyi = 0; $enIyiSkor = -1;
    foreach (array_slice($satirlar, 0, 15, true) as $i => $r) {
        $dolu = count(array_filter($r, fn($c) => $c !== ''));
        if ($dolu < 2) continue;
        $es = 0;
        foreach ($r as $c) { if ($c !== '' && pim_alan_bul($c)) $es++; }
        $skor = $es * 100 + $dolu;
        if ($skor > $enIyiSkor) { $enIyiSkor = $skor; $enIyi = $i; }
    }
    return $enIyi;
}

/** Başlık metni → alan anahtarı (eş anlamlı listesinden; yoksa null). */
function pim_alan_bul(string $baslik): ?string
{
    $n = pim_norm($baslik);
    if ($n === '') return null;
    foreach (PIM_ALAN as $k => $t) if (in_array($n, $t['es'], true)) return $k;
    // Gevşek: başlık eş anlamlıyla başlıyor / içeriyor (uzun eşleşme öncelikli)
    $enIyi = null; $enUzun = 0;
    foreach (PIM_ALAN as $k => $t) foreach ($t['es'] as $e) {
        if (strlen($e) >= 4 && strlen($e) > $enUzun && (str_starts_with($n, $e . ' ') || str_ends_with($n, ' ' . $e) || str_contains($n, ' ' . $e . ' '))) { $enIyi = $k; $enUzun = strlen($e); }
    }
    return $enIyi;
}

/**
 * Sütun → alan haritası. Kurallar: her alan (notlar hariç) tek sütuna bağlanır; "NAME" yalnız SURNAME
 * sütunu yoksa ad_soyad sayılır (varsa ad); telefonda cep/mobile tercih edilir; e-postada '@' içeren
 * örnek değer aranır (UPN gibi başlıklar boşsa atlanır).
 */
function pim_harita(array $baslik, array $ornekler = []): array
{
    $h = []; $kul = [];
    $soyadVar = false;
    foreach ($baslik as $c) if (in_array(pim_norm($c), PIM_ALAN['soyad']['es'], true)) $soyadVar = true;
    foreach ($baslik as $i => $c) {
        $k = pim_alan_bul($c);
        $n = pim_norm($c);
        if ($k === 'ad_soyad' && $n === 'NAME' && $soyadVar) $k = 'ad';
        if ($k === null) { $h[$i] = ''; continue; }
        if ($k !== 'notlar' && isset($kul[$k])) {
            // Telefon: cep/mobile önceki sabit hattı ezer
            if ($k === 'telefon' && preg_match('/CEP|GSM|MOBIL/', $n) && !preg_match('/CEP|GSM|MOBIL/', pim_norm($baslik[$kul[$k]]))) { $h[$kul[$k]] = 'notlar'; }
            else { $h[$i] = 'notlar'; continue; }
        }
        if ($k === 'eposta' && $ornekler) {
            $var = false; foreach ($ornekler as $r) if (str_contains((string)($r[$i] ?? ''), '@')) { $var = true; break; }
            if (!$var) { $h[$i] = 'notlar'; continue; }
        }
        $h[$i] = $k; $kul[$k] = $i;
    }
    if (isset($kul['ad'], $kul['soyad'], $kul['ad_soyad'])) $h[$kul['ad_soyad']] = ''; // ayrı Ad + Soyad varken Display Name gereksiz
    return $h;
}

/** Tarih: Y-m-d[ H:i:s] · d.m.Y · d/m/Y · Excel seri no · "1.01.0001"/"01.01.1900" boş sayılır. */
function pim_tarih(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) $t = "$m[1]-$m[2]-$m[3]";
    elseif (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/', $s, $m)) $t = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    elseif (preg_match('/^\d{4,6}(\.\d+)?$/', $s) && (float)$s > 20000 && (float)$s < 80000) $t = gmdate('Y-m-d', (int)(((float)$s - 25569) * 86400));
    else return null;
    if ($t < '1950-01-01') return null; // 1.01.0001 / 1900 → boş
    return checkdate((int)substr($t, 5, 2), (int)substr($t, 8, 2), (int)substr($t, 0, 4)) ? $t : null;
}

/** "1.01.0001" / "01.01.1900" / "0001-01-01" gibi 'tarih yok' sabitleri ve boş değer → true (not düşülmez). */
function pim_tarih_bos(string $s): bool
{
    $s = trim($s);
    return $s === '' || preg_match('/^(0?1[.\/-]0?1[.\/-](0001|1900)|(0001|1900)-01-01)/', $s) === 1;
}

/** Durum değeri → true (çalışıyor/aktif) · false (pasif/ayrılmış) · null (anlaşılmadı). */
function pim_durum(string $s): ?bool
{
    $n = pim_norm($s);
    if ($n === '') return null;
    if (in_array($n, ['1','TRUE','EVET','AKTIF','ACTIVE','ENABLED','CALISIYOR','ACIK','OK','YES','E','ACTIF'], true)) return true;
    if (in_array($n, ['0','FALSE','HAYIR','PASIF','PASSIVE','INACTIVE','DISABLED','AYRILDI','AYRILMIS','KAPALI','NO','H','ISTEN AYRILDI','TERMINATED','LEFT'], true)) return false;
    if (str_contains($n, 'PASIF') || str_contains($n, 'AYRIL') || str_contains($n, 'DISABLE') || str_contains($n, 'INACTIVE')) return false;
    if (str_contains($n, 'AKTIF') || str_contains($n, 'ACTIVE') || str_contains($n, 'ENABLE')) return true;
    return null;
}

/** Türkçe baş harf büyütme: "AHMET YILMAZ" → "Ahmet Yılmaz", "İSMAİL" → "İsmail" (mb_strtolower 'İ'yi bozar, elle yapılır). */
function pim_bas_harf(string $s): string
{
    $s = trim($s);
    if ($s === '' || $s !== mb_strtoupper($s, 'UTF-8')) return $s; // zaten karışık yazılmışsa dokunma
    $kucuk = str_replace(['İ', 'I'], ['i', 'ı'], $s);
    $kucuk = mb_strtolower($kucuk, 'UTF-8');
    return preg_replace_callback('/(^|[\s\-.\'])(\p{L})/u', fn($m) => $m[1] . ($m[2] === 'i' ? 'İ' : mb_strtoupper($m[2], 'UTF-8')), $kucuk);
}

/** Telefon: boşluk/parantez sadeleştirilir; 10 haneli 5xx… başına 0 eklenir; +90 korunur. */
function pim_telefon(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    $d = preg_replace('/[^\d+]/', '', $s);
    if (preg_match('/^\+?90(\d{10})$/', $d, $m)) $d = '0' . $m[1];
    elseif (preg_match('/^5\d{9}$/', $d)) $d = '0' . $d;
    if (preg_match('/^0(\d{3})(\d{3})(\d{2})(\d{2})$/', $d, $m)) return "0$m[1] $m[2] $m[3] $m[4]";
    return $s;
}

/** Ad Soyad tek sütun → [ad, soyad]: son kelime soyad ("Ayşe Nur Kaya" → Ayşe Nur / Kaya); "Kaya, Ayşe" biçimi de tanınır. */
function pim_ad_ayir(string $s): array
{
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if ($s === '') return ['', ''];
    if (str_contains($s, ',')) { [$soy, $ad] = array_map('trim', explode(',', $s, 2)); return [$ad, $soy]; }
    $p = explode(' ', $s);
    if (count($p) === 1) return [$s, ''];
    $soy = array_pop($p);
    return [implode(' ', $p), $soy];
}

/** Lokasyon metni → it_lokasyonlar id (kod eşleşmesi → ad eşit → ad içerir). */
function pim_lokasyon_bul(PDO $pdo, string $s): ?int
{
    static $cache = [];
    $n = pim_norm($s);
    if ($n === '') return null;
    if (array_key_exists($n, $cache)) return $cache[$n];
    $bul = null;
    $lok = it_lokasyonlar($pdo);
    $derinlik = function (int $id) use ($lok): int { $d = 0; $g = 0; while (!empty($lok[$id]['ust_id']) && $g++ < 10) { $id = (int)$lok[$id]['ust_id']; $d++; } return $d; };
    // Kod eşleşmesi: metinde U031 geçiyorsa etap kazanır — "Kartal U031 2. Etap" hem KARTAL hem U031 içerir, en DERİN düğüm alınır
    $enIyi = -1;
    foreach ($lok as $l) {
        if (!$l['kod'] || !preg_match('/(^| )' . preg_quote(pim_norm($l['kod']), '/') . '( |$)/', $n)) continue;
        $skor = $derinlik((int)$l['id']) * 100 + strlen($l['kod']);
        if ($skor > $enIyi) { $enIyi = $skor; $bul = (int)$l['id']; }
    }
    if (!$bul) foreach ($lok as $l) { if (pim_norm($l['ad']) === $n) { $bul = (int)$l['id']; break; } }
    if (!$bul) { $enIyi = -1; foreach ($lok as $l) { $la = pim_norm($l['ad']); if (strlen($la) >= 4 && (str_contains($n, $la) || str_contains($la, $n) && strlen($n) >= 5)) { $skor = $derinlik((int)$l['id']) * 100 + strlen($la); if ($skor > $enIyi) { $enIyi = $skor; $bul = (int)$l['id']; } } } }
    return $cache[$n] = $bul;
}

/** Bir grid satırını harita ile alanlara çözer. Dönüş: alanlar + 'ham_lokasyon' + 'durum' (bool|null). */
function pim_satir_cozumle(array $satir, array $harita, array $baslik, array $opt = []): array
{
    $v = ['sicil_no'=>'', 'ad'=>'', 'soyad'=>'', 'ad_soyad'=>'', 'unvan'=>'', 'birim'=>'', 'lokasyon'=>'', 'telefon'=>'', 'eposta'=>'', 'ise_giris'=>'', 'isten_cikis'=>'', 'durum'=>'', 'notlar'=>[]];
    foreach ($harita as $i => $k) {
        if ($k === '' || $k === null) continue;
        $c = trim((string)($satir[$i] ?? ''));
        if ($c === '') continue;
        if ($k === 'notlar') { $v['notlar'][] = trim((string)($baslik[$i] ?? 'Not')) . ': ' . $c; continue; }
        if ($v[$k] === '') $v[$k] = $c;
    }
    if ($v['ad'] === '' && $v['soyad'] === '' && $v['ad_soyad'] !== '') [$v['ad'], $v['soyad']] = pim_ad_ayir($v['ad_soyad']);
    if (!empty($opt['bas_harf'])) { $v['ad'] = pim_bas_harf($v['ad']); $v['soyad'] = pim_bas_harf($v['soyad']); $v['unvan'] = pim_bas_harf($v['unvan']); $v['birim'] = pim_bas_harf($v['birim']); }
    $v['telefon'] = pim_telefon($v['telefon']);
    $v['eposta']  = mb_strtolower($v['eposta'], 'UTF-8');
    if ($v['eposta'] !== '' && !filter_var($v['eposta'], FILTER_VALIDATE_EMAIL)) { $v['notlar'][] = 'E-posta (okunamadı): ' . $v['eposta']; $v['eposta'] = ''; }
    $v['ise_giris_ham'] = $v['ise_giris'];     $v['ise_giris']   = pim_tarih($v['ise_giris']);
    $v['isten_cikis_ham'] = $v['isten_cikis']; $v['isten_cikis'] = pim_tarih($v['isten_cikis']);
    $v['durum_ham'] = $v['durum']; $v['durum'] = pim_durum($v['durum']);
    return $v;
}

function pim_log_kur(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS it_import_log (
            id INT AUTO_INCREMENT PRIMARY KEY, dosya VARCHAR(255) NULL, bicim VARCHAR(10) NULL,
            okunan INT NOT NULL DEFAULT 0, yeni INT NOT NULL DEFAULT 0, guncellenen INT NOT NULL DEFAULT 0,
            degismeyen INT NOT NULL DEFAULT 0, atlanan INT NOT NULL DEFAULT 0, ayrilan INT NOT NULL DEFAULT 0,
            kullanici VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}

/**
 * Birleştirme. $opt: harita (sütun→alan), baslik_idx, bas_harf, pasif_ayrilmis (durum=pasif → çıkış tarihi bugün),
 * dosyada_olmayan_ayrilmis, dosya, bicim, kullanici, rapor_tarihi (Y-m-d).
 * Dönüş: sayaçlar + listeler (yeni/guncellenen/degismeyen/atlanan/ayrilan/lokasyon_yok).
 */
function pim_import(PDO $pdo, array $satirlar, array $opt): array
{
    $harita = $opt['harita']; $bIdx = (int)$opt['baslik_idx']; $baslik = $satirlar[$bIdx] ?? [];
    $bugun = $opt['rapor_tarihi'] ?? date('Y-m-d');
    $r = ['okunan'=>0, 'yeni'=>[], 'guncellenen'=>[], 'degismeyen'=>0, 'atlanan'=>[], 'ayrilan'=>[], 'lokasyon_yok'=>[], 'gorulen'=>[]];
    $alanlar = ['sicil_no','ad','soyad','unvan','birim','lokasyon_id','telefon','eposta','ise_giris','isten_cikis'];

    $mevcut = $pdo->query("SELECT * FROM it_personel")->fetchAll();
    $bySicil = []; $byMail = []; $byAd = [];
    $adAnahtar = fn($a, $s) => pim_norm($a) . '|' . pim_norm($s);
    foreach ($mevcut as $m) {
        if ($m['sicil_no'] !== null && trim($m['sicil_no']) !== '') $bySicil[pim_norm($m['sicil_no'])] = $m;
        if ($m['eposta']) $byMail[mb_strtolower(trim($m['eposta']), 'UTF-8')] = $m;
        $byAd[$adAnahtar($m['ad'], $m['soyad'])][] = $m;
    }
    $dosyadaGorulen = []; $dosyaIci = [];

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO it_personel (sicil_no,ad,soyad,unvan,birim,lokasyon_id,telefon,eposta,ise_giris,isten_cikis,notlar) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($satirlar as $i => $sat) {
            if ($i <= $bIdx) continue;
            if (!array_filter($sat, fn($c) => trim((string)$c) !== '')) continue;
            $r['okunan']++;
            $v = pim_satir_cozumle($sat, $harita, $baslik, $opt);
            $exNo = $i + 1;
            $etiket = trim($v['ad'] . ' ' . $v['soyad']) ?: ($v['sicil_no'] ?: ($v['eposta'] ?: "satır $exNo"));
            if ($v['ad'] === '' && $v['soyad'] === '') { $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>'ad / soyad boş']; continue; }
            if ($v['soyad'] === '') { $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>'soyad okunamadı (tek kelimelik ad)']; continue; }
            // Dosya içi tekrar
            $dk = $v['sicil_no'] !== '' ? 'S:' . pim_norm($v['sicil_no']) : ($v['eposta'] !== '' ? 'M:' . $v['eposta'] : 'A:' . $adAnahtar($v['ad'], $v['soyad']));
            if (isset($dosyaIci[$dk])) { $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>'aynı dosyada tekrar (satır ' . $dosyaIci[$dk] . ')']; continue; }
            $dosyaIci[$dk] = $exNo;

            // Lokasyon
            $lokId = null;
            if ($v['lokasyon'] !== '') {
                $lokId = pim_lokasyon_bul($pdo, $v['lokasyon']);
                if (!$lokId) { $r['lokasyon_yok'][$v['lokasyon']] = ($r['lokasyon_yok'][$v['lokasyon']] ?? 0) + 1; $v['notlar'][] = 'Lokasyon (dosyadan): ' . $v['lokasyon']; }
            }
            // Birim adı lokasyon ağacında bir düğümse (Merkez › Satış Ofisi gibi) ve seçili lokasyonun altındaysa, o daha özgül düğüm alınır
            if ($v['birim'] !== '' && ($bl = pim_lokasyon_bul($pdo, $v['birim'])) && $bl !== $lokId && ($lokId === null || in_array($bl, it_lokasyon_altlar($pdo, $lokId), true))) $lokId = $bl;
            if ($v['birim'] === '' && $lokId) { $l = it_lokasyonlar($pdo)[$lokId] ?? null; if ($l && $l['tur'] === 'birim') $v['birim'] = $l['ad']; }
            // Pasif hesap → ayrılmış
            $cikis = $v['isten_cikis'];
            if ($cikis === null && $v['durum'] === false && !empty($opt['pasif_ayrilmis'])) $cikis = $bugun;
            if ($v['ise_giris'] === null && !pim_tarih_bos($v['ise_giris_ham'])) $v['notlar'][] = 'İşe giriş (okunamadı): ' . $v['ise_giris_ham'];
            if ($v['isten_cikis'] === null && !pim_tarih_bos($v['isten_cikis_ham'])) $v['notlar'][] = 'İşten çıkış (okunamadı): ' . $v['isten_cikis_ham'];

            $yeni = ['sicil_no'=>$v['sicil_no'] ?: null, 'ad'=>$v['ad'], 'soyad'=>$v['soyad'], 'unvan'=>$v['unvan'] ?: null, 'birim'=>$v['birim'] ?: null,
                     'lokasyon_id'=>$lokId, 'telefon'=>$v['telefon'] ?: null, 'eposta'=>$v['eposta'] ?: null, 'ise_giris'=>$v['ise_giris'], 'isten_cikis'=>$cikis];
            $notSatiri = $v['notlar'] ? implode("\n", array_unique($v['notlar'])) : '';

            // Eşleşme: sicil → e-posta → ad+soyad
            $m = null; $nasil = '';
            if ($v['sicil_no'] !== '' && isset($bySicil[pim_norm($v['sicil_no'])])) { $m = $bySicil[pim_norm($v['sicil_no'])]; $nasil = 'sicil'; }
            elseif ($v['eposta'] !== '' && isset($byMail[$v['eposta']])) { $m = $byMail[$v['eposta']]; $nasil = 'e-posta'; }
            elseif (isset($byAd[$adAnahtar($v['ad'], $v['soyad'])])) {
                $adaylar = $byAd[$adAnahtar($v['ad'], $v['soyad'])];
                if (count($adaylar) === 1) { $m = $adaylar[0]; $nasil = 'ad soyad'; }
                else { foreach ($adaylar as $a) $dosyadaGorulen[(int)$a['id']] = true; $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>'aynı ad soyadlı ' . count($adaylar) . ' kayıt var, sicil/e-posta yok — elle eşleyin']; continue; }
            }

            // Çakışma denetimi: sicil aynı ama ad VE soyad bambaşkaysa, ya da e-posta/ad ile bulunup sicil farklıysa
            // kayıt EZİLMEZ — satır atlanır, kullanıcı elle karar verir (sicil yeniden kullanılmış / yanlış e-posta olabilir)
            if ($m) {
                $dosyadaGorulen[(int)$m['id']] = true; // çakışsa bile kişi dosyada var sayılır — "dosyada yok → ayrıldı" kuralına düşmesin
                $adFark = pim_norm($m['ad']) !== pim_norm($v['ad']) && pim_norm($m['soyad']) !== pim_norm($v['soyad']);
                if ($nasil === 'sicil' && $adFark) { $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>'sicil ' . $v['sicil_no'] . ' sistemde başka kişiye kayıtlı (' . it_personel_ad($m) . ') — kontrol edin']; continue; }
                if ($nasil !== 'sicil' && $v['sicil_no'] !== '' && trim((string)$m['sicil_no']) !== '' && pim_norm($m['sicil_no']) !== pim_norm($v['sicil_no'])) {
                    $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>"sicil çakışması: sistemde {$m['sicil_no']}, dosyada {$v['sicil_no']} (" . it_personel_ad($m) . ', ' . $nasil . ' ile eşleşti) — kontrol edin']; continue;
                }
                if ($nasil === 'e-posta' && $adFark) { $r['atlanan'][] = ['satir'=>$exNo, 'kim'=>$etiket, 'neden'=>'e-posta ' . $v['eposta'] . ' sistemde başka kişiye kayıtlı (' . it_personel_ad($m) . ') — kontrol edin']; continue; }
            }

            if ($m) {
                $dosyadaGorulen[(int)$m['id']] = true;
                $set = []; $par = []; $degisen = [];
                foreach ($alanlar as $k) {
                    $y = $yeni[$k];
                    if ($y === null || $y === '') continue;                       // dosyada boş → mevcut korunur
                    if ($k === 'isten_cikis' && !empty($m['isten_cikis']) && $m['isten_cikis'] <= $bugun) continue; // zaten ayrılmış, tarihi ezme
                    if ((string)$m[$k] === (string)$y) continue;
                    $set[] = "$k=?"; $par[] = $y; $degisen[] = $k . ': ' . ($m[$k] === null || $m[$k] === '' ? '—' : $m[$k]) . ' → ' . $y;
                }
                // Not: mevcut nota yalnız yeni satırlar eklenir
                if ($notSatiri !== '') {
                    $eskiNot = (string)($m['notlar'] ?? '');
                    $ek = array_filter(explode("\n", $notSatiri), fn($s) => $s !== '' && !str_contains($eskiNot, $s));
                    if ($ek) { $set[] = "notlar=?"; $par[] = trim($eskiNot . "\n" . implode("\n", $ek)); }
                }
                if ($set) {
                    $par[] = (int)$m['id'];
                    $pdo->prepare("UPDATE it_personel SET " . implode(', ', $set) . " WHERE id=?")->execute($par);
                    if (array_intersect(['ad','soyad','birim'], array_map(fn($s) => explode(':', $s, 2)[0], $degisen))) {
                        $pdo->prepare("UPDATE it_cihazlar SET zimmetli=?, departman=COALESCE(NULLIF(?,''),departman) WHERE personel_id=?")
                            ->execute([trim($yeni['ad'] . ' ' . $yeni['soyad']), $yeni['birim'] ?? '', (int)$m['id']]);
                    }
                    if ($degisen) $r['guncellenen'][] = ['id'=>(int)$m['id'], 'kim'=>it_personel_ad($m), 'nasil'=>$nasil, 'degisen'=>$degisen];
                    else $r['degismeyen']++;
                } else $r['degismeyen']++;
            } else {
                $ins->execute([$yeni['sicil_no'], $yeni['ad'], $yeni['soyad'], $yeni['unvan'], $yeni['birim'], $yeni['lokasyon_id'], $yeni['telefon'], $yeni['eposta'], $yeni['ise_giris'], $yeni['isten_cikis'], $notSatiri ?: null]);
                $id = (int)$pdo->lastInsertId();
                $dosyadaGorulen[$id] = true;
                $kayit = $yeni + ['id'=>$id, 'notlar'=>$notSatiri];
                if ($yeni['sicil_no']) $bySicil[pim_norm($yeni['sicil_no'])] = $kayit;
                if ($yeni['eposta']) $byMail[$yeni['eposta']] = $kayit;
                $byAd[$adAnahtar($yeni['ad'], $yeni['soyad'])][] = $kayit;
                $r['yeni'][] = ['id'=>$id, 'kim'=>trim($yeni['ad'] . ' ' . $yeni['soyad']), 'sicil'=>$yeni['sicil_no'], 'unvan'=>$yeni['unvan'], 'birim'=>$yeni['birim'], 'lok'=>$lokId ? it_lokasyon_yol($pdo, $lokId) : $v['lokasyon'], 'ayrildi'=>$cikis !== null];
            }
        }

        // Dosyada olmayan çalışanlar → ayrılmış (zimmeti olan ATLANIR)
        if (!empty($opt['dosyada_olmayan_ayrilmis']) && $r['okunan'] > 0) {
            foreach ($mevcut as $m) {
                if (isset($dosyadaGorulen[(int)$m['id']]) || !it_personel_aktif($m)) continue;
                $z = count(it_personel_cihazlari($pdo, (int)$m['id']));
                if ($z) { $r['ayrilan'][] = ['id'=>(int)$m['id'], 'kim'=>it_personel_ad($m), 'ok'=>false, 'not'=>"üzerinde $z zimmetli cihaz var — önce iade alın"]; continue; }
                $pdo->prepare("UPDATE it_personel SET isten_cikis=? WHERE id=?")->execute([$bugun, (int)$m['id']]);
                $r['ayrilan'][] = ['id'=>(int)$m['id'], 'kim'=>it_personel_ad($m), 'ok'=>true, 'not'=>"çıkış $bugun"];
            }
        }
        pim_log_kur($pdo);
        try {
            $pdo->prepare("INSERT INTO it_import_log (dosya,bicim,okunan,yeni,guncellenen,degismeyen,atlanan,ayrilan,kullanici) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$opt['dosya'] ?? null, $opt['bicim'] ?? null, $r['okunan'], count($r['yeni']), count($r['guncellenen']), $r['degismeyen'], count($r['atlanan']), count(array_filter($r['ayrilan'], fn($a) => $a['ok'])), $opt['kullanici'] ?? null]);
        } catch (Throwable $e) {}
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $r;
}
