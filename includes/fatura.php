<?php
/**
 * fatura.php — e-Fatura ↔ irsaliye eşleştirme yardımcıları
 *
 * Tedarikçi e-faturaları üzerinde "İrsaliye No/Tarih" listesi bulunur.
 * Bu katman o listeyi çıkarır, numaraları NORMALİZE eder ve sistemdeki
 * irsaliyelerle eşleştirir.
 *
 * ⚠ Neden normalizasyon şart: aynı irsaliye iki yerde farklı yazılıyor —
 *    Faturada : ANM2026-4710        (tireli, dolgusuz)
 *    Sistemde : ANM2026000004710    (tiresiz, sıfır dolgulu)
 *    Düz metin karşılaştırması bu yüzden HİÇ eşleşme bulamaz.
 */

/** İrsaliye numarasını karşılaştırılabilir tek biçime indirger: ÖNEK+YIL-SAYI */
function fat_irs_norm(?string $s): string
{
    $s = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string)$s), 'UTF-8'));
    if ($s === '') return '';
    // ÖNEK + 4 haneli yıl + (sıfır dolgulu) sıra
    if (preg_match('/^([A-Z]+)(\d{4})0*(\d+)$/', $s, $m)) {
        return $m[1] . $m[2] . '-' . ltrim($m[3], '0');
    }
    return $s;
}

/** Türkçe biçimli sayıyı float'a çevirir ("1.068.120,00" → 1068120.00) */
function fat_sayi(?string $s): ?float
{
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = preg_replace('/[^\d.,-]/', '', $s);
    if ($s === '') return null;
    // Son ayıraç ondalıktır
    $sonNokta = strrpos($s, '.'); $sonVirgul = strrpos($s, ',');
    if ($sonVirgul !== false && ($sonNokta === false || $sonVirgul > $sonNokta)) {
        $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}

/** Metindeki tarihi YYYY-AA-GG'ye çevirir (28-07-2026 / 28.07.2026 destekli) */
function fat_tarih_norm(?string $s): ?string
{
    $s = trim((string)$s);
    if (preg_match('/(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{4})/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[1], (int)$m[3])
            ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return $s;
    return null;
}

/**
 * Fatura metninden alanları çıkar.
 * @return array{fatura_no:?string, tarih:?string, tutar:?float, brut_tutar:?float, miktar:?float, ettn:?string, irsaliyeler:string[]}
 */
function fat_metinden_cikar(string $metin): array
{
    $d = ['fatura_no'=>null,'tarih'=>null,'tutar'=>null,'brut_tutar'=>null,'miktar'=>null,'ettn'=>null,
          'satici_vkn'=>null,'satici_unvan'=>null,'irsaliyeler'=>[]];
    $t = preg_replace('/[ \t]+/', ' ', $metin);

    if (preg_match('/Fatura\s*No[:\s]*([A-Z]{2,5}\d{8,20})/iu', $t, $m))  $d['fatura_no'] = strtoupper($m[1]);
    if (preg_match('/Fatura\s*Tarihi[:\s]*([\d]{1,2}\s*[.\-\/]\s*[\d]{1,2}\s*[.\-\/]\s*[\d]{4})/iu', $t, $m))
        $d['tarih'] = fat_tarih_norm($m[1]);
    // Tevkifatlı faturada iki tutar farklıdır; ikisini de sakla.
    //   Vergiler Dahil Toplam = brüt   |   Ödenecek Tutar = tevkifat düşülmüş, fiilen ödenecek
    if (preg_match('/Vergiler\s*Dahil\s*Toplam\s*Tutar[:\s]*([\d.,]+)/iu', $t, $m)) $d['brut_tutar'] = fat_sayi($m[1]);
    if (preg_match('/Ödenecek\s*Tutar[:\s]*([\d.,]+)/iu', $t, $m))                  $d['tutar']      = fat_sayi($m[1]);
    if ($d['tutar'] === null) $d['tutar'] = $d['brut_tutar'] ?? null;   // tevkifatsız fatura
    if (preg_match('/ETTN[:\s]*([A-F0-9\-]{30,40})/i', $t, $m)) $d['ettn'] = strtoupper($m[1]);

    // Toplam miktar: "387 M3" / "40 m3" satırlarının toplamı
    if (preg_match_all('/([\d.,]+)\s*(?:m3|m³)\b/iu', $t, $mm)) {
        $top = 0.0; $var = false;
        foreach ($mm[1] as $v) { $f = fat_sayi($v); if ($f !== null) { $top += $f; $var = true; } }
        if ($var) $d['miktar'] = $top;
    }

    // İrsaliye numaraları: ÖNEK+YIL(+tire)+sıra. Faturanın kendi numarası hariç.
    $bulunan = [];
    if (preg_match_all('/\b([A-Z]{2,5})(\d{4})\s*-?\s*(\d{1,10})\b/u', $t, $im, PREG_SET_ORDER)) {
        foreach ($im as $g) {
            $ham  = $g[1] . $g[2] . ($g[3] !== '' ? '-' . $g[3] : '');
            $norm = fat_irs_norm($g[1] . $g[2] . str_pad($g[3], 9, '0', STR_PAD_LEFT));
            if ($d['fatura_no'] && fat_irs_norm($d['fatura_no']) === $norm) continue;   // faturanın kendisi
            if ($norm === '') continue;
            $bulunan[$norm] = $ham;
        }
    }
    $d['irsaliyeler'] = array_values($bulunan);

    // Satıcı VKN + unvan (metinden): faturada iki VKN vardır — "SAYIN" bloğundaki
    // ALICI'nındır (bize kesilen fatura), diğeri SATICI'nındır. Karekod varsa
    // satıcı VKN'si zaten oradan gelir (fat_qr_birlestir ezer).
    $saticiOff = null;
    if (preg_match_all('/VKN[:\s]*([0-9]{10,11})/iu', $t, $vm, PREG_OFFSET_CAPTURE)) {
        $sayinOff = stripos($t, 'SAYIN');
        $aliciVkn = null;
        if ($sayinOff !== false) {
            foreach ($vm[1] as [$vkn0, $off0]) if ($off0 > $sayinOff) { $aliciVkn = $vkn0; break; }
        }
        foreach ($vm[1] as [$vkn0, $off0]) {
            if ($vkn0 !== $aliciVkn) { $d['satici_vkn'] = $vkn0; $saticiOff = $off0; break; }
        }
        if ($d['satici_vkn'] === null && $vm[1]) { $d['satici_vkn'] = $vm[1][0][0]; $saticiOff = $vm[1][0][1]; }
    }
    if ($saticiOff !== null) {
        // Unvan: satıcı VKN'sinden geriye doğru en yakın "…ŞİRKETİ / A.Ş. / LTD.ŞTİ." satırı
        $pencere = substr($t, max(0, $saticiOff - 900), min(900, $saticiOff));
        if (preg_match_all('/^[^\n]{3,100}?(?:ANON[İI]M\s+[ŞS][İI]RKET[İI]|L[İI]M[İI]TED\s+[ŞS][İI]RKET[İI]|A\.\s*[ŞS]\.?|LTD\.?\s*[ŞS]T[İI]\.?)[^\n]{0,10}$/miu', $pencere, $um)) {
            $d['satici_unvan'] = trim((string)end($um[0]));
        }
    }

    // Metne karekot içeriği de yapıştırılmış olabilir — varsa o esas alınır
    return fat_qr_birlestir($d, fat_qr_coz($metin));
}

/**
 * GİB e-Fatura KAREKODU (faturanın altındaki QR) içeriğini çözer.
 *
 * Karekod, faturanın kendi beyanıdır: fatura no, tarih, ETTN, satıcı VKN ve
 * ödenecek tutar burada BİREBİR yazar. Metin/OCR tahminine göre çok daha güvenilir,
 * bu yüzden çakışmada karekod esastır.
 *
 * Örnek: {"vkntckn":"7371227729","avkntckn":"9650496315","senaryo":"TEMELFATURA",
 *         "tip":"TEVKIFAT","tarih":"2026-08-12","no":"SGA2026000000819",
 *         "ettn":"...","parabirimi":"TRY","malhizmettoplam":"281520.00",
 *         "kdvmatrah(20)":"281520.00","hesaplanankdv(20)":"56304.00",
 *         "kdvmatrah(40)":"56304.00","hesaplanankdv(40)":"22521.60",
 *         "vergidahil":"315302.40","odenecek":"315302.40"}
 *
 * ⚠ Karekoptaki "vergidahil", faturada YAZAN "Vergiler Dahil Toplam Tutar"dan
 *   farklı olabilir: tevkifatlı faturada karekot tevkifat DÜŞÜLMÜŞ tutarı verir
 *   (315.302,40), kağıtta ise brüt (337.824,00) yazar. Bu yüzden brüt tutar
 *   karekottan DEĞİL, metinden alınır.
 *
 * @return array|null  Çözülemezse null
 */
function fat_qr_coz(?string $s): ?array
{
    $s = trim((string)$s);
    if ($s === '') return null;
    $j = null;
    if (preg_match('/\{.*\}/s', $s, $m)) $j = json_decode($m[0], true);   // metin içine gömülü JSON
    if (!is_array($j)) {
        // Bazı karekod okuyucular içeriği "anahtar : değer" satırları olarak verir
        // ("vkntckn : 2000034421" / "odenecek : 4196488.450") — bunu da tanı.
        $kv = [];
        if (preg_match_all('/^\s*([a-zçğıöşü0-9()_-]{2,30})\s*:\s*(\S[^\n]*)$/miu', $s, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $g) $kv[trim($g[1])] = trim($g[2]);
        }
        if (isset($kv['no']) || isset($kv['ettn'])) $j = $kv;
    }
    if (!is_array($j)) return null;

    // Anahtarları küçült/boşluk temizle (bazı entegratörler farklı yazıyor)
    $k = [];
    foreach ($j as $ad => $deger) $k[strtolower(str_replace(' ', '', (string)$ad))] = $deger;
    if (!isset($k['no']) && !isset($k['ettn'])) return null;  // e-Fatura karekodu değil

    $tevkifat = null;
    foreach ($k as $ad => $deger) {
        if (str_starts_with($ad, 'hesaplanankdvtevkifat') || $ad === 'hesaplanankdv(40)') {
            $tevkifat = fat_sayi((string)$deger);
        }
    }

    return [
        'fatura_no' => isset($k['no']) ? strtoupper(trim((string)$k['no'])) : null,
        'tarih'     => isset($k['tarih']) ? fat_tarih_norm((string)$k['tarih']) : null,
        'ettn'      => isset($k['ettn']) ? strtoupper(trim((string)$k['ettn'])) : null,
        'vkn'       => isset($k['vkntckn'])  ? preg_replace('/\D/', '', (string)$k['vkntckn'])  : null,
        'alici_vkn' => isset($k['avkntckn']) ? preg_replace('/\D/', '', (string)$k['avkntckn']) : null,
        'senaryo'   => isset($k['senaryo']) ? (string)$k['senaryo'] : null,
        'tip'       => isset($k['tip']) ? (string)$k['tip'] : null,
        'matrah'    => isset($k['malhizmettoplam']) ? fat_sayi((string)$k['malhizmettoplam']) : null,
        'tevkifat'  => $tevkifat,
        'vergidahil'=> isset($k['vergidahil']) ? fat_sayi((string)$k['vergidahil']) : null,
        'tutar'     => isset($k['odenecek']) ? fat_sayi((string)$k['odenecek']) : null,
    ];
}

/**
 * Karekot verisini metinden çıkarılan alanlarla birleştirir.
 * Karekot ESAS alınır; metinden gelen değer farklıysa 'uyari' listesine yazılır
 * (sessizce ezmek yerine kullanıcıya gösterilir).
 */
function fat_qr_birlestir(array $veri, ?array $qr): array
{
    $veri['qr'] = $qr;
    $veri['uyari'] = [];
    if (!$qr) return $veri;

    $karsilastir = [
        'fatura_no' => 'Fatura No',
        'tarih'     => 'Fatura Tarihi',
        'ettn'      => 'ETTN',
        'tutar'     => 'Ödenecek Tutar',
    ];
    foreach ($karsilastir as $alan => $etiket) {
        $q = $qr[$alan] ?? null;
        if ($q === null || $q === '') continue;
        $m = $veri[$alan] ?? null;
        if ($m !== null && $m !== '') {
            $ayni = ($alan === 'tutar') ? (abs((float)$m - (float)$q) < 0.01)
                                        : (mb_strtoupper((string)$m, 'UTF-8') === mb_strtoupper((string)$q, 'UTF-8'));
            if (!$ayni) $veri['uyari'][] = $etiket . ': karekot "' . $q . '" · metin "' . $m . '" — karekot esas alındı';
        }
        $veri[$alan] = $q;
    }
    // Brüt tutar metinden gelir; yoksa karekottaki vergidahil kullanılır
    if (($veri['brut_tutar'] ?? null) === null && ($qr['vergidahil'] ?? null) !== null) {
        $veri['brut_tutar'] = $qr['vergidahil'];
    }
    // Satıcı VKN'sinde karekod kesindir (metin çıkarımı sezgiseldir)
    if (($qr['vkn'] ?? null) !== null && $qr['vkn'] !== '') $veri['satici_vkn'] = $qr['vkn'];
    return $veri;
}

/** Şirket unvanını karşılaştırılabilir çekirdeğe indirger ("SAFİ BETON ÜRETİM VE TİCARET A.Ş." → "SAFI BETON"). */
function fat_unvan_norm(?string $s): string
{
    $s = mb_strtoupper(trim((string)$s), 'UTF-8');
    $s = str_replace(['İ','Ş','Ğ','Ü','Ö','Ç','.'], ['I','S','G','U','O','C',''], $s);
    $s = preg_replace('/\b(ANONIM|LIMITED|SIRKETI|STI|AS|LTD|TIC|TICARET|SAN|SANAYI|VE|URETIM|INSAAT|PAZARLAMA|NAKLIYAT|MADENCILIK)\b/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}

/** Fatura metnindeki satıcı unvanından tedarikçiyi bulur (normalize ad karşılaştırması). */
function fat_tedarikci_bul_ad(PDO $pdo, ?string $unvan): ?array
{
    $u = fat_unvan_norm($unvan);
    if ($u === '' || mb_strlen($u) < 4) return null;
    foreach ($pdo->query("SELECT id, ad, vkn FROM tedarikciler") as $r) {
        $a = fat_unvan_norm($r['ad']);
        if ($a !== '' && mb_strlen($a) >= 4 && (str_contains($u, $a) || str_contains($a, $u))) return $r;
    }
    return null;
}

/** Karekottaki satıcı VKN'sinden tedarikçiyi bulur. */
function fat_tedarikci_bul(PDO $pdo, ?string $vkn): ?array
{
    $vkn = preg_replace('/\D/', '', (string)$vkn);
    if ($vkn === '') return null;
    $st = $pdo->prepare("SELECT id, ad, vkn FROM tedarikciler WHERE REPLACE(REPLACE(vkn,' ',''),'-','') = ? LIMIT 1");
    $st->execute([$vkn]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Çıkarılan irsaliye numaralarını sistemdeki kayıtlarla eşleştirir.
 * @return array{eslesen:array, eksik:array, ozet:array}
 */
function fat_eslestir(PDO $pdo, array $numaralar): array
{
    $normHedef = [];
    foreach ($numaralar as $n) { $k = fat_irs_norm($n); if ($k !== '') $normHedef[$k] = $n; }
    if (!$normHedef) return ['eslesen'=>[], 'eksik'=>[], 'ozet'=>['adet'=>0,'miktar'=>0.0]];

    // Aday kayıtları önekle daralt (tam tarama yerine), sonra PHP'de normalize eşleştir
    $onekler = [];
    foreach (array_keys($normHedef) as $k) {
        if (preg_match('/^([A-Z]+\d{4})/', $k, $m)) $onekler[$m[1] . '%'] = true;
    }
    $sql = "SELECT i.id, i.irsaliye_no, i.tarih, i.miktar, i.fatura_no, i.durum,
                   t.ad AS tedarikci, bs.ad AS beton_sinifi, i.arac_plaka
            FROM irsaliyeler i
            LEFT JOIN tedarikciler t ON t.id = i.tedarikci_id
            LEFT JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id
            WHERE i.irsaliye_no IS NOT NULL AND i.irsaliye_no <> ''";
    $params = [];
    if ($onekler) {
        $sql .= ' AND (' . implode(' OR ', array_fill(0, count($onekler), 'i.irsaliye_no LIKE ?')) . ')';
        $params = array_keys($onekler);
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $idx = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = fat_irs_norm($r['irsaliye_no']);
        if ($k !== '' && !isset($idx[$k])) $idx[$k] = $r;
    }

    $eslesen = []; $eksik = []; $miktar = 0.0;
    foreach ($normHedef as $k => $ham) {
        if (isset($idx[$k])) {
            $r = $idx[$k]; $r['fatura_gosterim'] = $ham;
            $eslesen[] = $r;
            $miktar += (float)$r['miktar'];
        } else {
            $eksik[] = $ham;
        }
    }
    return ['eslesen'=>$eslesen, 'eksik'=>$eksik, 'ozet'=>['adet'=>count($eslesen), 'miktar'=>$miktar]];
}

/**
 * Bu fatura sistemde zaten kayıtlı mı? (fatura no VEYA ETTN ile)
 *
 * ETTN faturanın gerçek benzersiz kimliğidir; entegratör no'yu farklı biçimde
 * yazsa bile ETTN aynı kalır — bu yüzden ikisi de kontrol edilir.
 *
 * @return array|null  Kayıt varsa bilgileri (+ bagli irsaliye sayısı), yoksa null
 */
function fat_mevcut(PDO $pdo, ?string $faturaNo, ?string $ettn = null): ?array
{
    $no   = trim((string)$faturaNo);
    $ettn = trim((string)$ettn);
    if ($no === '' && $ettn === '') return null;

    $kos = []; $par = [];
    if ($no !== '')   { $kos[] = 'f.fatura_no = ?'; $par[] = $no; }
    if ($ettn !== '') { $kos[] = 'f.ettn = ?';      $par[] = $ettn; }

    $st = $pdo->prepare("SELECT f.*, t.ad AS tedarikci,
                                (SELECT COUNT(*) FROM irsaliyeler i WHERE i.fatura_id = f.id) AS bagli
                         FROM faturalar f LEFT JOIN tedarikciler t ON t.id = f.tedarikci_id
                         WHERE " . implode(' OR ', $kos) . " LIMIT 1");
    $st->execute($par);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($r) {
        // Hangi alandan yakalandı — kullanıcıya net söylemek için
        $r['eslesme_alani'] = ($no !== '' && $r['fatura_no'] === $no) ? 'fatura_no' : 'ettn';
    }
    return $r;
}

/**
 * Eşleşen irsaliyelerden BAŞKA bir faturaya bağlı olanları bulur.
 * Aynı irsaliyenin iki faturada görünmesi çift faturalandırma demektir —
 * sessizce üzerine yazmak yerine kullanıcıya gösterilir.
 *
 * @param int[] $irsIds
 * @return array<int, array{irsaliye_no:string, fatura_no:string, fatura_id:int}>  irsaliye_id => bilgi
 */
function fat_baskaya_bagli(PDO $pdo, array $irsIds, int $haric = 0): array
{
    $irsIds = array_values(array_filter(array_map('intval', $irsIds)));
    if (!$irsIds) return [];
    $yer = implode(',', array_fill(0, count($irsIds), '?'));
    $sql = "SELECT i.id, i.irsaliye_no, f.id AS fatura_id, f.fatura_no
            FROM irsaliyeler i JOIN faturalar f ON f.id = i.fatura_id
            WHERE i.id IN ($yer) AND i.fatura_id IS NOT NULL";
    $par = $irsIds;
    if ($haric > 0) { $sql .= ' AND f.id <> ?'; $par[] = $haric; }
    $st = $pdo->prepare($sql);
    $st->execute($par);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = ['irsaliye_no' => $r['irsaliye_no'], 'fatura_no' => $r['fatura_no'], 'fatura_id' => (int)$r['fatura_id']];
    }
    return $out;
}

/**
 * Bir faturaya bağlı irsaliyeleri döndürür (mutabakat ekranında listelemek için).
 * @param int[] $faturaIds  Birden çok fatura verilirse sonuç fatura_id'ye göre gruplanır.
 * @return array<int, array>  fatura_id => irsaliye satırları
 */
function fat_bagli_irsaliyeler(PDO $pdo, array $faturaIds): array
{
    $faturaIds = array_values(array_unique(array_filter(array_map('intval', $faturaIds))));
    if (!$faturaIds) return [];
    $yer = implode(',', array_fill(0, count($faturaIds), '?'));
    $st = $pdo->prepare("SELECT i.id, i.fatura_id, i.irsaliye_no, i.tarih, i.miktar, i.arac_plaka, i.durum, i.aciklama,
                                t.ad AS tedarikci, bs.ad AS beton_sinifi
                         FROM irsaliyeler i
                         LEFT JOIN tedarikciler t ON t.id = i.tedarikci_id
                         LEFT JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id
                         WHERE i.fatura_id IN ($yer)
                         ORDER BY i.tarih, i.irsaliye_no");
    $st->execute($faturaIds);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['fatura_id']][] = $r;
    return $out;
}

/** faturalar tablosunu garanti et (runtime migration). */
function fat_semasi_kur(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS faturalar (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        fatura_no    VARCHAR(50)  NOT NULL,
        tarih        DATE         DEFAULT NULL,
        tedarikci_id INT          DEFAULT NULL,
        tutar        DECIMAL(14,2) DEFAULT NULL,
        miktar_m3    DECIMAL(12,2) DEFAULT NULL,
        ettn         VARCHAR(60)  DEFAULT NULL,
        irsaliye_adet INT         NOT NULL DEFAULT 0,
        eksik_adet   INT          NOT NULL DEFAULT 0,
        dosya_url    VARCHAR(500) DEFAULT NULL,
        notlar       TEXT         DEFAULT NULL,
        created_by   INT          DEFAULT NULL,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fatura (fatura_no),
        KEY idx_tarih (tarih)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // faturalar.eksik_liste — faturada olup sistemde bulunamayan irsaliye numaraları (JSON).
    // Yalnız sayı (eksik_adet) hangi irsaliyelerin eksik olduğunu söyleyemiyordu.
    $var = $pdo->query("SHOW COLUMNS FROM faturalar LIKE 'eksik_liste'")->fetch();
    if (!$var) $pdo->exec("ALTER TABLE faturalar ADD COLUMN eksik_liste TEXT NULL AFTER eksik_adet");

    // irsaliyeler.fatura_id — faturaya bağ (fatura_no alanı taramada ETTN ile dolduğundan
    // güvenilir bağ için ayrı kolon tutulur).
    $var = $pdo->query("SHOW COLUMNS FROM irsaliyeler LIKE 'fatura_id'")->fetch();
    if (!$var) {
        $pdo->exec("ALTER TABLE irsaliyeler ADD COLUMN fatura_id INT NULL DEFAULT NULL, ADD KEY idx_fatura_id (fatura_id)");
    }
}

/**
 * PDF/görselden metin elde etmeye çalışır.
 * Sıra: yerel pdftotext (varsa) → AI (Claude/Gemini belge okuma) → null.
 * Sunucuda pdftotext yoksa AI yolu kullanılır; ikisi de yoksa kullanıcı metni yapıştırır.
 */
function fat_dosyadan_metin(string $path, string $mime, ?string &$kaynak = null, ?string $aiSistem = null): ?string
{
    // 1) pdftotext (hızlı ve ücretsiz — genelde VPS'te kurulu değil)
    if ($mime === 'application/pdf' && function_exists('exec')) {
        $out = []; $rc = 1;
        @exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null', $out, $rc);
        if ($rc === 0) {
            $t = implode("\n", $out);
            if (mb_strlen(trim($t)) > 100) { $kaynak = 'pdftotext'; return $t; }
        }
    }
    // 2) AI belge okuma ($aiSistem ile modüle özgü talimat verilebilir — demir vb.)
    $ai = fat_ai_oku($path, $mime, $aiSistem);
    if ($ai !== null) { $kaynak = 'ai'; return $ai; }
    return null;
}

/**
 * AI'ya faturayı okutup DÜZ METİN olarak geri alır (ayrıştırma yine fat_metinden_cikar ile
 * yapılır; böylece AI'nın "uydurma" alan üretme riski en aza iner).
 */
function fat_ai_oku(string $path, string $mime, ?string $sistemOverride = null): ?string
{
    if (!function_exists('ai_call')) {
        $f = __DIR__ . '/ai_call.php';
        if (!is_file($f)) return null;
        require_once $f;
    }
    $ham = @file_get_contents($path);
    if ($ham === false || $ham === '') return null;

    $parca = ($mime === 'application/pdf')
        ? ['type' => 'document', 'data' => base64_encode($ham)]
        : ['type' => 'image', 'mime' => $mime, 'data' => base64_encode($ham)];

    $sistem = $sistemOverride ?? 'Sen bir e-Fatura okuyucusun. Verilen faturadaki METNİ olduğu gibi düz metin olarak '
            . 'yaz. Hiçbir şey uydurma, yorum ekleme, özetleme. Özellikle şu alanların satırlarını '
            . 'MUTLAKA aynen aktar: Fatura No, Fatura Tarihi, ETTN, Vergiler Dahil Toplam Tutar, '
            . 'Ödenecek Tutar, kalem tablosundaki Miktar değerleri BİRİMİYLE aynen (örn. "250 M3" — '
            . 'birimi asla atlama) ve TÜM "İrsaliye No / İrsaliye Tarihi" değerleri. '
            . 'İrsaliye numaralarını tek tek, her biri ayrı satırda listele.';

    $r = ai_call($sistem, [$parca, ['type' => 'text', 'text' => 'Faturanın metnini çıkar.']], 8000);
    if (!($r['ok'] ?? false)) return null;
    $t = trim((string)($r['text'] ?? ''));
    return $t !== '' ? $t : null;
}

/**
 * Faturayı kaydeder ve eşleşen irsaliyeleri faturaya bağlar.
 * @param int[] $irsIds Bağlanacak irsaliye id'leri
 * @return array{fatura_id:int, baglanan:int}
 */
function fat_kaydet(PDO $pdo, array $fatura, array $irsIds, ?int $userId = null, ?string $dosyaUrl = null): array
{
    fat_semasi_kur($pdo);
    $no = trim((string)($fatura['fatura_no'] ?? ''));
    if ($no === '') throw new RuntimeException('Fatura no boş olamaz.');

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id FROM faturalar WHERE fatura_no = ?");
        $st->execute([$no]);
        $fid = (int)$st->fetchColumn();
        $yeniKayit = ($fid === 0);

        $alan = [
            $fatura['tarih'] ?: null,
            $fatura['tedarikci_id'] ?: null,
            is_numeric($fatura['tutar'] ?? null)  ? (float)$fatura['tutar']  : fat_sayi((string)($fatura['tutar'] ?? '')),
            is_numeric($fatura['miktar'] ?? null) ? (float)$fatura['miktar'] : fat_sayi((string)($fatura['miktar'] ?? '')),
            $fatura['ettn'] ?: null,
            count($irsIds),
            (int)($fatura['eksik_adet'] ?? 0),
            !empty($fatura['eksik_liste']) && is_array($fatura['eksik_liste'])
                ? json_encode(array_values($fatura['eksik_liste']), JSON_UNESCAPED_UNICODE) : null,
            $dosyaUrl,
            $fatura['notlar'] ?? null,
        ];
        if ($fid) {
            $u = $pdo->prepare("UPDATE faturalar SET tarih=?, tedarikci_id=?, tutar=?, miktar_m3=?, ettn=?,
                                irsaliye_adet=?, eksik_adet=?, eksik_liste=?, dosya_url=COALESCE(?, dosya_url), notlar=? WHERE id=?");
            $u->execute(array_merge($alan, [$fid]));
        } else {
            $i = $pdo->prepare("INSERT INTO faturalar (fatura_no, tarih, tedarikci_id, tutar, miktar_m3, ettn,
                                irsaliye_adet, eksik_adet, eksik_liste, dosya_url, notlar, created_by)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $i->execute(array_merge([$no], $alan, [$userId]));
            $fid = (int)$pdo->lastInsertId();
        }

        // Önceki bağları çöz (fatura yeniden eşleştirilirse artık listede olmayanlar kopsun)
        $pdo->prepare("UPDATE irsaliyeler SET fatura_id = NULL WHERE fatura_id = ?")->execute([$fid]);

        $baglanan = 0;
        if ($irsIds) {
            // fatura_no alanı taramada ETTN ile dolabildiğinden ÜZERİNE YAZILMAZ; yalnız boşsa doldurulur.
            $b = $pdo->prepare("UPDATE irsaliyeler SET fatura_id = ?,
                                fatura_no = CASE WHEN fatura_no IS NULL OR TRIM(fatura_no) = '' THEN ? ELSE fatura_no END
                                WHERE id = ?");
            foreach ($irsIds as $iid) { $b->execute([$fid, $no, (int)$iid]); $baglanan++; }
        }
        $pdo->commit();
        audit_log($pdo, 'faturalar', $fid, $yeniKayit ? 'INSERT' : 'UPDATE', null,
                  ['fatura_no' => $no, 'baglanan_irsaliye' => $baglanan, 'eksik' => (int)($fatura['eksik_adet'] ?? 0)], $userId);
        return ['fatura_id' => $fid, 'baglanan' => $baglanan];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
