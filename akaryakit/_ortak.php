<?php
/** _ortak.php — Akaryakıt modülü ortak yardımcıları */

/** Türkçe/Excel sayı parse: "1.451,52" → 1451.52 ; "#N/A"/boş → 0 */
function ak_sayi($v): float {
    $v = trim((string)$v);
    if ($v==='' || strncmp($v,'#',1)===0) return 0.0;
    $v = str_replace([' ',"\xc2\xa0"],'',$v);
    if (strpos($v,',')!==false) { $v=str_replace('.','',$v); $v=str_replace(',','.',$v); }
    return is_numeric($v) ? (float)$v : 0.0;
}

/**
 * FORM alanından gelen sayı ("6.000" → 6000). `ak_sayi()` Excel içindir ve orada
 * "6.000" ondalık demektir; ELLE yazılan alanda ise Türkçe binlik ayracıdır —
 * "6.000 Lt" 6 litre olarak kaydediliyordu. Nokta yalnız **üçerli gruplar**
 * biçimindeyse (1.234, 1.234.567) binlik sayılır, "6.5" ondalık kalır.
 */
function ak_sayi_form($v): float {
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    $s = str_replace([' ', "\xc2\xa0"], '', $s);
    if (strpos($s, ',') === false && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
        return (float)str_replace('.', '', $s);
    }
    return ak_sayi($s);
}

/** Metni normalize et (İ/I katlama, boşluk sadeleştirme) — eşleştirme için */
function ak_norm(string $s): string {
    $s = trim($s);
    $s = str_replace(['İ','I','ı','i','İ'], 'I', $s);
    $s = mb_strtoupper($s, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

$GLOBALS['AK_AYLAR'] = [
    'OCAK'=>1,'ŞUBAT'=>2,'SUBAT'=>2,'MART'=>3,'NİSAN'=>4,'NISAN'=>4,'MAYIS'=>5,'HAZİRAN'=>6,'HAZIRAN'=>6,
    'TEMMUZ'=>7,'AĞUSTOS'=>8,'AGUSTOS'=>8,'EYLÜL'=>9,'EYLUL'=>9,'EKİM'=>10,'EKIM'=>10,'KASIM'=>11,'ARALIK'=>12,
];

/** "OCAK 2026" → 202601 (sıralama anahtarı); bulunamazsa 0 */
function ak_donemSira(string $donem): int {
    $u = mb_strtoupper(trim($donem), 'UTF-8');
    $yil = 0; if (preg_match('/(\d{4})/', $u, $m)) $yil = (int)$m[1];
    $ay = 0;
    foreach ($GLOBALS['AK_AYLAR'] as $ad=>$no) { if (mb_strpos($u, $ad)!==false) { $ay=$no; break; } }
    if (!$yil) return 0;
    return $yil*100 + $ay;
}

/** Dönem adını sadeleştir (fazla boşluk temizle) */
function ak_donemAd(string $donem): string { return trim(preg_replace('/\s+/u',' ',$donem)); }

/** Araç get-or-create (anahtar = ŞOFÖR + CİNSİ) */
function ak_aracId(PDO $pdo, array $d): int {
    $sofor = trim((string)($d['sofor']??''));
    $cinsi = trim((string)($d['cinsi']??''));
    $anahtar = ak_norm($sofor.'|'.$cinsi);
    $q = $pdo->prepare("SELECT id FROM akaryakit_araclar WHERE anahtar=?");
    $q->execute([$anahtar]);
    if ($id = $q->fetchColumn()) {
        // Eksik alanları güncelle (firma/lokasyon/plaka/mak_no/sinif dolabilir)
        $pdo->prepare("UPDATE akaryakit_araclar SET
            sinif=COALESCE(NULLIF(?,''),sinif), mak_no=COALESCE(NULLIF(?,''),mak_no),
            lokasyon=COALESCE(NULLIF(?,''),lokasyon), firma=COALESCE(NULLIF(?,''),firma),
            plaka=COALESCE(NULLIF(?,''),plaka) WHERE id=?")
            ->execute([$d['sinif']??'', $d['mak_no']??'', $d['lokasyon']??'', $d['firma']??'', $d['plaka']??'', $id]);
        return (int)$id;
    }
    $pdo->prepare("INSERT INTO akaryakit_araclar (sinif,mak_no,lokasyon,firma,plaka,sofor,cinsi,anahtar)
        VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$d['sinif']??null, $d['mak_no']??null, $d['lokasyon']??null, $d['firma']??null,
                   $d['plaka']??null, $sofor, $cinsi, $anahtar]);
    return (int)$pdo->lastInsertId();
}

/** Dönemleri sıralı getir (en yeni önce) */
function ak_donemler(PDO $pdo): array {
    try { return $pdo->query("SELECT * FROM akaryakit_donemler ORDER BY donem_sira DESC, id DESC")->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/**
 * Ay sayfalarının altındaki imza bloğu satırı mı? (İMZA:/TARİH:/…ŞEFİ:/MÜDÜRÜ/SORUMLUSU)
 * Bu satırlar araç verisi değildir; içe aktarmada atlanır.
 */
function ak_imza_satiri(array $row): bool {
    foreach (array_slice($row, 0, 10) as $c) {
        $u = mb_strtoupper(trim((string)$c), 'UTF-8');
        if ($u === '') continue;
        foreach (['İMZA', 'IMZA', 'TARİH:', 'TARIH:', 'ŞEFİ', 'SEFI', 'MÜDÜR', 'MUDUR', 'SORUMLUSU'] as $y) {
            if (mb_strpos($u, $y) !== false) return true;
        }
    }
    return false;
}

/**
 * Daha önce imza bloğundan araç sanılıp kaydedilmiş çöp kayıtları temizler
 * (araç + tüketim + tutanak satırları). İçe aktarma sonunda çağrılır.
 * @return int silinen araç sayısı
 */
function ak_imza_temizle(PDO $pdo): int {
    $silinen = 0;
    foreach ($pdo->query("SELECT id, sofor, cinsi, firma FROM akaryakit_araclar") as $a) {
        if (ak_imza_satiri([$a['sofor'], $a['cinsi'], $a['firma']])) {
            $pdo->prepare("DELETE FROM akaryakit_tuketim WHERE arac_id=?")->execute([(int)$a['id']]);
            $pdo->prepare("DELETE FROM akaryakit_araclar WHERE id=?")->execute([(int)$a['id']]);
            $silinen++;
        }
    }
    try {
        $st = $pdo->query("SELECT id, sofor, arac_detay, firma_detay FROM akaryakit_tutanak");
        foreach ($st as $t) {
            if (ak_imza_satiri([$t['sofor'], $t['arac_detay'], $t['firma_detay']])) {
                $pdo->prepare("DELETE FROM akaryakit_tutanak WHERE id=?")->execute([(int)$t['id']]);
                $silinen++;
            }
        }
    } catch (Throwable $e) {}
    return $silinen;
}

/* ══════════════════════════════════════════════════════════════════════════
   GÜNLÜK HAREKET DEFTERİ  (hareketler.php)
   --------------------------------------------------------------------------
   Defter iki kaynaktan birleşir:
     GİRİŞ  → `akaryakit_girisler`  (tanka gelen mazot; bu modülde yeni)
     ÇIKIŞ  → `akaryakit_cikislar`  (araca verilen mazot; cikislar.php'nin tablosu)
   Çıkış verisi KOPYALANMAZ — tek kaynak `akaryakit_cikislar`'dır, defter onu
   olduğu yerden okur. Böylece iki ekran arasında veri ikilemi oluşmaz.

   ⚠ Stok zinciri (akaryakit_donemler) EXCEL'den kurulur; defter onun yerine
   geçmez, günlük işleyişi + imzalı evrakı tutar ve ay sonunda Excel ile
   karşılaştırılır (hareketler.php'deki mutabakat bandı).
   ══════════════════════════════════════════════════════════════════════════ */

/** Giriş tablosu şeması (runtime; kurulum_akaryakit.php de kurar). */
function ak_giris_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS akaryakit_girisler (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        tarih       DATE NOT NULL,
        belge_no    VARCHAR(60) NULL   COMMENT 'irsaliye / fiş no',
        tedarikci   VARCHAR(160) NULL  COMMENT 'mazotu getiren firma',
        plaka       VARCHAR(40) NULL   COMMENT 'tanker plakası',
        miktar_lt   DECIMAL(12,2) NOT NULL DEFAULT 0,
        birim_fiyat DECIMAL(12,4) NULL,
        tutar       DECIMAL(14,2) NULL,
        teslim_alan VARCHAR(120) NULL,
        aciklama    VARCHAR(255) NULL,
        evrak_url   VARCHAR(500) NULL  COMMENT 'irsaliye/fatura taraması',
        created_by  INT NULL,
        created     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated     TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_tarih (tarih)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Defter filtrelerini normalize eder (hepsi prepared sorguda kullanılır).
 * @return array{bas:string,bit:string,tur:string,arac_id:int,ara:string,ay:string}
 */
function ak_defter_filtre(array $g): array
{
    $ay = preg_match('/^\d{4}-\d{2}$/', (string)($g['ay'] ?? '')) ? $g['ay'] : '';
    $bas = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['bas'] ?? '')) ? $g['bas'] : '';
    $bit = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['bit'] ?? '')) ? $g['bit'] : '';
    // Ay seçiliyse ve elle tarih verilmemişse ayın ilk/son gününe çevir
    if ($ay !== '' && $bas === '' && $bit === '') {
        $bas = $ay . '-01';
        $bit = date('Y-m-t', strtotime($bas));
    }
    $tur = in_array($g['tur'] ?? '', ['giris','cikis'], true) ? $g['tur'] : '';
    return ['bas'=>$bas, 'bit'=>$bit, 'tur'=>$tur,
            'arac_id'=>(int)($g['arac_id'] ?? 0), 'ara'=>trim((string)($g['ara'] ?? '')), 'ay'=>$ay];
}

/**
 * Excel'den gelen GÜNLÜK hareketler (import.php'nin yazdığı JSON'lardan):
 *   ÇIKIŞ → akaryakit_tuketim.gunluk  [{g:gün, mz:Lt, km:sayaç}] × araç
 *   GİRİŞ → akaryakit_donemler.gunluk {gelen:{gün:Lt}}
 * Tarih = dönemin yılı-ayı + gün. km=0 Excel'de "girilmemiş" demektir → null.
 * [$bas,$bit] aralığına giren dönemler okunur (boşsa hepsi).
 */
function ak_excel_gunluk(PDO $pdo, string $bas, string $bit): array
{
    $satir = [];
    $siraBas = $bas !== '' ? (int)substr($bas, 0, 4) * 100 + (int)substr($bas, 5, 2) : 0;
    $siraBit = $bit !== '' ? (int)substr($bit, 0, 4) * 100 + (int)substr($bit, 5, 2) : 999912;
    $tarihUygun = function (string $t) use ($bas, $bit): bool {
        return ($bas === '' || $t >= $bas) && ($bit === '' || $t <= $bit);
    };
    try {
        $dq = $pdo->prepare("SELECT id, donem, donem_sira, gunluk FROM akaryakit_donemler
                             WHERE donem_sira BETWEEN ? AND ? ORDER BY donem_sira");
        $dq->execute([$siraBas, $siraBit]);
        $donemler = $dq->fetchAll();
    } catch (Throwable $e) { return []; }
    if (!$donemler) return [];

    $tq = $pdo->prepare("SELECT t.id, t.arac_id, t.gunluk, a.sofor, a.cinsi, a.firma, a.plaka
                         FROM akaryakit_tuketim t LEFT JOIN akaryakit_araclar a ON a.id = t.arac_id
                         WHERE t.donem_sira = ? AND t.gunluk IS NOT NULL");
    foreach ($donemler as $dn) {
        $sira = (int)$dn['donem_sira'];
        if ($sira < 190001) continue;                       // ay çözülemeyen dönem
        $yil = intdiv($sira, 100); $ay = $sira % 100;
        if ($ay < 1 || $ay > 12) continue;
        $gunSay = (int)date('t', mktime(0, 0, 0, $ay, 1, $yil));

        // Tanka giriş günleri
        $g = json_decode((string)($dn['gunluk'] ?? ''), true);
        foreach ((array)($g['gelen'] ?? []) as $gun => $lt) {
            $gun = (int)$gun; $lt = (float)$lt;
            if ($gun < 1 || $gun > $gunSay || $lt <= 0) continue;
            $t = sprintf('%04d-%02d-%02d', $yil, $ay, $gun);
            if (!$tarihUygun($t)) continue;
            $satir[] = ['tur'=>'giris', 'kaynak'=>'excel', 'id'=>(int)$dn['id'] * 100 + $gun, 'tarih'=>$t,
                        'belge_no'=>null, 'taraf'=>'Excel — YENİ GELEN', 'detay'=>'Tanka giriş (' . $dn['donem'] . ')',
                        'plaka'=>null, 'giris'=>$lt, 'cikis'=>0.0, 'sayac'=>null, 'evrak_url'=>null,
                        'aciklama'=>null, 'arac_id'=>null, 'tutar'=>null, 'teslim_alan'=>null, 'sayilir'=>true];
        }
        $gunlukGelen = 0.0;
        foreach ((array)($g['gelen'] ?? []) as $lt) $gunlukGelen += (float)$lt;

        // Araç bazında günlük alımlar
        $gunlukKull = 0.0;
        $tq->execute([$sira]);
        foreach ($tq->fetchAll() as $t) {
            $gl = json_decode((string)$t['gunluk'], true);
            if (!is_array($gl)) continue;
            foreach ($gl as $x) {
                $gun = (int)($x['g'] ?? 0); $mz = (float)($x['mz'] ?? 0); $km = (float)($x['km'] ?? 0);
                if ($gun < 1 || $gun > $gunSay || $mz <= 0) continue;
                $tr = sprintf('%04d-%02d-%02d', $yil, $ay, $gun);
                $gunlukKull += $mz;
                if (!$tarihUygun($tr)) continue;
                $detay = trim(implode(' · ', array_filter([$t['cinsi'] ?? '', $t['firma'] ?? ''])));
                $satir[] = ['tur'=>'cikis', 'kaynak'=>'excel', 'id'=>(int)$t['id'] * 100 + $gun, 'tarih'=>$tr,
                            'belge_no'=>null, 'taraf'=>$t['sofor'] ?: '—', 'detay'=>$detay ?: '—',
                            'plaka'=>$t['plaka'], 'giris'=>0.0, 'cikis'=>$mz,
                            'sayac'=>$km > 0 ? rtrim(rtrim(number_format($km, 2, '.', ''), '0'), '.') : null,
                            'evrak_url'=>null, 'aciklama'=>null,
                            'arac_id'=>$t['arac_id'] !== null ? (int)$t['arac_id'] : null,
                            'tutar'=>null, 'teslim_alan'=>null, 'sayilir'=>true];
            }
        }

        // ── Aylık özet ile günlük hücreler arasındaki fark: SENTETİK satır ─────
        // Excel'de çoğu ay "YENİ GELEN"in geliş günü yazılmaz (yalnız aylık toplam);
        // araç satırlarında da detayı girilmemiş tüketim olabilir (TAŞERON VE DİĞERLER).
        // Fark satır olarak eklenmezse ay sonu bakiyesi Excel'in KALAN'ından sapar
        // (Ağustos'ta −689 görünüyordu, Excel 9.272 diyor). Geliş ayın 1'ine, açıklanamayan
        // tüketim ayın sonuna yazılır; ikisi de `sentetik=1` ile işaretlidir.
        $ozet = $pdo->prepare("SELECT gelen, kullanilan FROM akaryakit_donemler WHERE id=?");
        $ozet->execute([(int)$dn['id']]);
        $oz = $ozet->fetch() ?: ['gelen'=>0, 'kullanilan'=>0];
        $gelenFark = (float)$oz['gelen'] - $gunlukGelen;
        if ($gelenFark > 0.5) {
            $t1 = sprintf('%04d-%02d-01', $yil, $ay);
            if ($tarihUygun($t1)) $satir[] = ['tur'=>'giris', 'kaynak'=>'excel', 'sentetik'=>true,
                'id'=>(int)$dn['id'] * 100 + 99, 'tarih'=>$t1, 'belge_no'=>null,
                'taraf'=>'Excel — YENİ GELEN (aylık özet)', 'detay'=>'Geliş günü Excel\'de girilmemiş; ayın 1\'ine yazıldı (' . $dn['donem'] . ')',
                'plaka'=>null, 'giris'=>round($gelenFark, 2), 'cikis'=>0.0, 'sayac'=>null, 'evrak_url'=>null,
                'aciklama'=>null, 'arac_id'=>null, 'tutar'=>null, 'teslim_alan'=>null, 'sayilir'=>true];
        }
        $kullFark = (float)$oz['kullanilan'] - $gunlukKull;
        if ($kullFark > 0.5) {
            $tSon = sprintf('%04d-%02d-%02d', $yil, $ay, $gunSay);
            if ($tarihUygun($tSon)) $satir[] = ['tur'=>'cikis', 'kaynak'=>'excel', 'sentetik'=>true,
                'id'=>(int)$dn['id'] * 100 + 98, 'tarih'=>$tSon, 'belge_no'=>null,
                'taraf'=>'Excel — KULLANILAN (araç detayı yok)', 'detay'=>'Aylık özet ile araç satırları arasındaki fark (taşeron/diğer) — ' . $dn['donem'],
                'plaka'=>null, 'giris'=>0.0, 'cikis'=>round($kullFark, 2), 'sayac'=>null, 'evrak_url'=>null,
                'aciklama'=>null, 'arac_id'=>null, 'tutar'=>null, 'teslim_alan'=>null, 'sayilir'=>true];
        }
    }
    return $satir;
}

/**
 * Birleşik hareket defteri — ÜÇ kaynak tek listede, tarih sırasında:
 *   excel  → Excel'in günlük satırları (ak_excel_gunluk; aylık sayfadan içe aktarılan)
 *   elle   → akaryakit_girisler (giriş) + akaryakit_cikislar (çıkış; cikislar.php)
 *
 * ⚠ Excel tek doğru kaynaktır. Elle yazılan bir hareket Excel'e de işlenmişse aynı
 *   litre iki kez görünürdü; bu yüzden elle satır, aynı tarih + aynı araç (girişte aynı
 *   tarih) + aynı miktardaki Excel satırıyla EŞLEŞTİRİLİR: eşleşen elle satır listede
 *   kalır ama `sayilir=false` olur (toplama/bakiyeye girmez, "Excel'e işlendi" rozeti).
 *   Eşleşmeyen elle satır sayılır ve "Excel'de yok" rozetiyle işaretlenir — ay sonunda
 *   Excel'e aktarılması gerekenlerin listesi budur.
 *
 * Satır: tur/kaynak/id/tarih/belge_no/taraf/detay/plaka/giris/cikis/sayac/evrak_url/
 *        aciklama/arac_id/tutar/teslim_alan/sayilir/eslesen
 */
function ak_defter(PDO $pdo, array $f): array
{
    ak_giris_semasi_kur($pdo);
    $satir = [];
    $ara = $f['ara'] !== '' ? '%' . $f['ara'] . '%' : '';
    $araNorm = $f['ara'] !== '' ? ak_norm($f['ara']) : '';

    // ── EXCEL günlük satırları ──────────────────────────────────────────────
    $excel = ak_excel_gunluk($pdo, $f['bas'], $f['bit']);
    foreach ($excel as $r) {
        if ($f['tur'] !== '' && $r['tur'] !== $f['tur']) continue;
        if ($f['arac_id'] > 0 && $r['arac_id'] !== $f['arac_id']) continue;
        if ($araNorm !== '' && mb_strpos(ak_norm(implode(' ', [$r['taraf'], $r['detay'], (string)$r['plaka']])), $araNorm) === false) continue;
        $satir[] = $r;
    }

    // ── ELLE girişler ───────────────────────────────────────────────────────
    if ($f['tur'] !== 'cikis' && $f['arac_id'] === 0) {   // giriş satırının aracı yoktur
        $w = []; $p = [];
        if ($f['bas'] !== '') { $w[] = 'tarih >= ?'; $p[] = $f['bas']; }
        if ($f['bit'] !== '') { $w[] = 'tarih <= ?'; $p[] = $f['bit']; }
        if ($ara !== '')      { $w[] = '(tedarikci LIKE ? OR belge_no LIKE ? OR plaka LIKE ? OR aciklama LIKE ? OR teslim_alan LIKE ?)';
                                for ($i = 0; $i < 5; $i++) $p[] = $ara; }
        $st = $pdo->prepare("SELECT * FROM akaryakit_girisler" . ($w ? ' WHERE ' . implode(' AND ', $w) : ''));
        $st->execute($p);
        foreach ($st->fetchAll() as $r) {
            $satir[] = ['tur'=>'giris', 'kaynak'=>'elle', 'id'=>(int)$r['id'], 'tarih'=>$r['tarih'],
                        'belge_no'=>$r['belge_no'], 'taraf'=>$r['tedarikci'], 'detay'=>'Tanka giriş',
                        'plaka'=>$r['plaka'], 'giris'=>(float)$r['miktar_lt'], 'cikis'=>0.0,
                        'sayac'=>null, 'evrak_url'=>$r['evrak_url'], 'aciklama'=>$r['aciklama'],
                        'arac_id'=>null, 'tutar'=>$r['tutar'] !== null ? (float)$r['tutar'] : null,
                        'teslim_alan'=>$r['teslim_alan'], 'sayilir'=>true];
        }
    }

    // ── ELLE çıkışlar (cikislar.php'nin tablosu — kopyalanmaz, oradan okunur) ─
    if ($f['tur'] !== 'giris') {
        $w = []; $p = [];
        if ($f['bas'] !== '')      { $w[] = 'tarih >= ?'; $p[] = $f['bas']; }
        if ($f['bit'] !== '')      { $w[] = 'tarih <= ?'; $p[] = $f['bit']; }
        if ($f['arac_id'] > 0)     { $w[] = 'arac_id = ?'; $p[] = $f['arac_id']; }
        if ($ara !== '')           { $w[] = '(sofor LIKE ? OR cinsi LIKE ? OR firma LIKE ? OR plaka LIKE ? OR aciklama LIKE ? OR teslim_alan LIKE ?)';
                                     for ($i = 0; $i < 6; $i++) $p[] = $ara; }
        try {
            $st = $pdo->prepare("SELECT * FROM akaryakit_cikislar" . ($w ? ' WHERE ' . implode(' AND ', $w) : ''));
            $st->execute($p);
            foreach ($st->fetchAll() as $r) {
                $detay = trim(implode(' · ', array_filter([$r['cinsi'] ?? '', $r['firma'] ?? ''])));
                $sayac = trim((string)($r['sayac'] ?? ''));
                $satir[] = ['tur'=>'cikis', 'kaynak'=>'elle', 'id'=>(int)$r['id'], 'tarih'=>$r['tarih'],
                            'belge_no'=>null, 'taraf'=>$r['sofor'], 'detay'=>$detay ?: '—',
                            'plaka'=>$r['plaka'], 'giris'=>0.0, 'cikis'=>(float)$r['miktar_lt'],
                            'sayac'=>($sayac === '' || $sayac === '0') ? null : $sayac,
                            'evrak_url'=>$r['evrak_url'] ?? null, 'aciklama'=>$r['aciklama'],
                            'arac_id'=>$r['arac_id'] !== null ? (int)$r['arac_id'] : null, 'tutar'=>null,
                            'teslim_alan'=>$r['teslim_alan'], 'sayilir'=>true];
            }
        } catch (Throwable $e) { /* çıkış tablosu henüz yoksa */ }
    }

    // ── Elle ↔ Excel eşleştirme (aynı litre iki kez sayılmasın) ─────────────
    $excelKullanilan = [];
    foreach ($satir as $i => $r) if ($r['kaynak'] === 'excel') $excelKullanilan[$i] = false;
    foreach ($satir as $i => &$r) {
        if ($r['kaynak'] !== 'elle') continue;
        $miktar = $r['giris'] ?: $r['cikis'];
        foreach ($excelKullanilan as $j => $kul) {
            if ($kul) continue;
            $e = $satir[$j];
            if ($e['tur'] !== $r['tur'] || $e['tarih'] !== $r['tarih']) continue;
            if ($r['tur'] === 'cikis' && $e['arac_id'] !== $r['arac_id']) continue;
            if (abs(($e['giris'] ?: $e['cikis']) - $miktar) > 0.5) continue;
            $excelKullanilan[$j] = true;
            $r['sayilir'] = false; $r['eslesen'] = $e['id'];
            break;
        }
    }
    unset($r);

    // Tarih artan (yürüyen bakiye için şart); aynı gün önce girişler, Excel önce
    usort($satir, function ($a, $b) {
        return [$a['tarih'], $a['cikis'] > 0 ? 1 : 0, $a['kaynak'] === 'excel' ? 0 : 1, $a['id']]
           <=> [$b['tarih'], $b['cikis'] > 0 ? 1 : 0, $b['kaynak'] === 'excel' ? 0 : 1, $b['id']];
    });
    return $satir;
}

/**
 * Defterin açılış bakiyesi ve kaynağı.
 *   Ay seçili + Excel dönemi varsa  → Excel'deki DEVİR (tek doğru kaynak)
 *   Serbest tarih aralığında        → defterin kendi önceki hareketleri toplamı
 * Aksi halde 0. İkisi karıştırılırsa bakiye çift sayardı, bu yüzden ayrık.
 * @return array{0:float,1:string}  [açılış, kaynak açıklaması]
 */
function ak_defter_acilis(PDO $pdo, array $f, ?array $donem): array
{
    if ($donem) return [(float)$donem['devir'], 'Excel dönem devri (' . $donem['donem'] . ')'];
    if ($f['bas'] === '') return [0.0, ''];
    $dun = date('Y-m-d', strtotime($f['bas'] . ' -1 day'));
    // Başlangıç ayının Excel dönemi varsa zincir oradan kurulur: o ayın DEVRİ + ay başından
    // başlangıca kadarki (Excel + elle, eşleşenler düşülmüş) hareketler. Aksi halde defterin
    // tamamı baştan toplanır (Excel dönemi hiç yoksa yalnız elle kayıtlar).
    $ayDonem = ak_donem_ay($pdo, substr($f['bas'], 0, 7));
    $bak = $ayDonem ? (float)$ayDonem['devir'] : 0.0;
    $ilk = $ayDonem ? substr($f['bas'], 0, 7) . '-01' : '';
    if ($ilk === '' || $ilk <= $dun) {
        $onceki = ak_defter($pdo, ['bas'=>$ilk, 'bit'=>$dun, 'tur'=>'', 'arac_id'=>0, 'ara'=>'', 'ay'=>'']);
        foreach ($onceki as $r) if ($r['sayilir']) $bak += $r['giris'] - $r['cikis'];
    }
    $kaynak = $ayDonem ? 'Excel devri ' . $ayDonem['donem'] . ' + ' . date('d.m.Y', strtotime($dun)) . ' sonuna kadarki hareketler'
                       : 'defterin önceki hareketleri (' . date('d.m.Y', strtotime($dun)) . ' sonu)';
    return [$bak, $kaynak];
}

/**
 * Seçilen ayın Excel dönem satırı (mutabakat bandı için).
 * "2026-01" → donem_sira 202601 ile eşleşen `akaryakit_donemler` kaydı.
 */
function ak_donem_ay(PDO $pdo, string $ay): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ay, $m)) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM akaryakit_donemler WHERE donem_sira=? LIMIT 1");
        $st->execute([(int)$m[1] * 100 + (int)$m[2]]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}
