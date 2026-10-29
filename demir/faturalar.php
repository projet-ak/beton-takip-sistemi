<?php
/**
 * demir/faturalar.php — Demir Fatura Takibi (e-Fatura ↔ sevkiyat eşleştirme)
 *
 * Demir e-faturası (İDİS senaryosu, ör. Çakıroğlu) betondan FARKLI okunur:
 *  - İrsaliye no gövdede liste halinde DEĞİL, BAŞLIKTA tek alandır ("İrsaliye No: CKI2026...").
 *    Gövdedeki yüzlerce rulo/bağ kodu (CA0217734...) genel numara tarayıcısını yanıltır —
 *    bu yüzden YALNIZ başlıktaki "İrsaliye No / Fatura No / Sipariş No" alanları okunur.
 *  - Miktar KG cinsindendir ("27.680 Kg" / "Toplam Miktar 27680") → ton'a çevrilir.
 *  - Çap, malzeme açıklamasından çıkar ("10MM NERV.İNŞ DEMİRİ" → Ø10, "Q188" → hasır).
 * Eşleşme: irsaliye no (normalize, fat_irs_norm) → demir_sevkiyatlar.irsaliye_no.
 * Kayıt: demir_faturalar + demir_sevkiyatlar.fatura_id bağı (runtime şema).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/../includes/fatura.php';   // fat_sayi, fat_tarih_norm, fat_irs_norm, fat_qr_coz, fat_dosyadan_metin, fat_unvan_norm

// ── Şema (runtime) ───────────────────────────────────────────────────────────
function dfat_semasi_kur(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS demir_faturalar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fatura_no VARCHAR(50) NOT NULL,
        ettn VARCHAR(50) NULL,
        tarih DATE NULL,
        tedarikci VARCHAR(200) NULL,
        tedarikci_id INT NULL,
        siparis_no VARCHAR(50) NULL,
        irsaliye_liste TEXT NULL,
        miktar_kg DECIMAL(14,2) NULL,
        tutar DECIMAL(14,2) NULL,
        brut_tutar DECIMAL(14,2) NULL,
        dosya_url VARCHAR(500) NULL,
        notlar VARCHAR(500) NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fatura_no (fatura_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE demir_sevkiyatlar ADD COLUMN fatura_id INT NULL, ADD INDEX idx_fatura (fatura_id)"); }
    catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE demir_faturalar ADD COLUMN kalemler LONGTEXT NULL"); }
    catch (Throwable $e) {}
}
dfat_semasi_kur($pdoDemir);

/**
 * KG okuyucu. Demir miktarı ondalıksız BİNLİK yazılır ("26.400 KG" = 26400 kg) — genel
 * fat_sayi() noktayı ondalık sayıp 26,4 döndürür. Üçerli gruplar (26.400 · 1.234.567 ·
 * 26,550) binliktir; "26.400,00" / "66420.000" / "27680" fat_sayi'ye bırakılır.
 * ⚠ Formdaki "Miktar (kg)" kutusu da "26.400" biçiminde basılır — kayıtta MUTLAKA bu kullanılır.
 */
function dfat_kg(?string $s): ?float {
    $s = preg_replace('/[^\d.,]/', '', trim((string)$s));
    if ($s === '' || $s === null) return null;
    if (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $s) || preg_match('/^\d{1,3}(?:,\d{3})+$/', $s)) {
        return (float)str_replace(['.', ','], '', $s);
    }
    return fat_sayi($s);
}

/**
 * Bozuk kayıt onarımı (idempotent): eski sürüm formdaki "26.400" kg değerini fat_sayi ile
 * okuyup 26,4 kg yazıyordu → listede her fatura "0,03 t" görünüyordu. Gerçek demir faturasında
 * kg başına fiyat ~30 ₺'dir; tutar ÷ kg 1000 ₺'yi aşıyorsa değer binde bir okunmuştur → ×1000.
 * (Kutuda ondalıksız gösterildiği için ×1000 değeri birebir geri getirir.)
 */
function dfat_kg_onar(PDO $pdo): int {
    try {
        return $pdo->exec("UPDATE demir_faturalar SET miktar_kg = miktar_kg * 1000
                           WHERE miktar_kg > 0 AND miktar_kg < 1000 AND tutar IS NOT NULL AND tutar / miktar_kg > 1000") ?: 0;
    } catch (Throwable $e) { return 0; }
}
$__kgOnarilan = dfat_kg_onar($pdoDemir);

/** AI ile okumada demire özgü talimat (tekil yükleme, yeniden okuma ve toplu işleme ortak) */
function dfat_ai_sistem(): string {
    return 'Sen bir e-Fatura okuyucusun. Verilen DEMİR faturasındaki METNİ olduğu gibi düz metin '
         . 'olarak yaz. Hiçbir şey uydurma, yorum ekleme, özetleme. Şu satırları MUTLAKA aynen aktar: '
         . 'Fatura No, Fatura Tarihi, ETTN, Sipariş No, başlıktaki "İrsaliye No" / "İrsaliye No (1)" alanları, '
         . 'faturanın altındaki "İrsaliyeler:" listesi, kalemlerdeki '
         . '"[İRS.NO:...]" ve "[E-IRSALIYE NO TARIH:...]" ve "[BELGE NO:...]" köşeli parantezli etiketlerin '
         . 'TAMAMI, kalem tablosundaki malzeme adları ve Miktar değerleri BİRİMİYLE (örn. "27.680 Kg", "26.400 KG"), '
         . '"Toplam Miktar", "Vergiler Dahil Toplam Tutar" ve "Ödenecek Tutar" satırları, "Satıcı" ve "Alıcı" '
         . 'unvan satırları ile VKN / "Vergi No" satırları. Rulo/bağ kodu listelerini (CA02..., FD03... gibi) aktarmana gerek YOK.';
}

// ── Demir faturası metin çözümleme ───────────────────────────────────────────
// Tedarikçiden tedarikçiye 3 farklı irsaliye gösterimi görüldü (gerçek faturalardan):
//   Çakıroğlu : başlıkta "İrsaliye No: CKI2026000003302" (etiketle değer ayrı satıra düşebilir)
//   Ali Cangül: kalemde  "[İRS.NO:2124-08.05.2026]"  (DÜZ SAYI numara + tarih)
//   Cangül    : kalemde  "[E-IRSALIYE NO TARIH:13.08.2026-CNG2026000020738]" + "[BELGE NO:21111]"
// Gövdedeki rulo/bağ kodları (CA0217734...) BİLEREK taranmaz — yalnız etiketli alanlar okunur.
function dfat_cikar(string $metin): array {
    // AI okuması bazen markdown süsler ("**Fatura No:** CKE...") — etiket desenleri şaşmasın
    $metin = str_replace(['**', '__', '##', '`'], '', $metin);
    $t = preg_replace('/[ \t]+/', ' ', $metin);
    $d = ['fatura_no'=>null,'tarih'=>null,'ettn'=>null,'siparis_no'=>null,'irsaliyeler'=>[],'belge_nolar'=>[],
          'sevkiyat_nolar'=>[],'miktar_kg'=>null,'tutar'=>null,'brut_tutar'=>null,'satici_vkn'=>null,'satici_unvan'=>null,'caplar'=>[]];
    $ekle = function(string $no) use (&$d) {
        $no = strtoupper(trim($no));
        if ($no !== '' && !in_array($no, $d['irsaliyeler'], true)) $d['irsaliyeler'][] = $no;
    };

    // Fatura no: GİB biçimi = 3 alfanümerik önek + yıl + 9 hane (H012026000007732 gibi
    // tek harfli önekler de geçerli — [A-Z]{2,5} deseni bunları KAÇIRIYORDU)
    if (preg_match('/Fatura\s*No[:\s]*([A-Z][A-Z0-9]{2}\d{13})/iu', $t, $m)) $d['fatura_no'] = strtoupper($m[1]);
    elseif (preg_match('/Fatura\s*No[:\s]*([A-Z]{2,5}\d{8,20})/iu', $t, $m)) $d['fatura_no'] = strtoupper($m[1]);
    if (preg_match('/Fatura\s*Tarihi[:\s]*([\d]{1,2}\s*[.\-\/]\s*[\d]{1,2}\s*[.\-\/]\s*[\d]{4})/iu', $t, $m)) $d['tarih'] = fat_tarih_norm($m[1]);
    // ETTN satır sonunda bölünebiliyor ("...-8368-\nC0DB9BCF34E5") — boşlukları söküp doğrula
    if (preg_match('/ETTN[:\s]*([A-F0-9][A-F0-9\-\s]{30,60})/i', $t, $m)) {
        $ettn = strtoupper(preg_replace('/\s+/', '', $m[1]));
        $ettn = substr($ettn, 0, 36);
        if (preg_match('/^[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}$/', $ettn)) $d['ettn'] = $ettn;
    }
    if (preg_match('/Sipariş\s*No[:\s]*([A-Z0-9][A-Z0-9\-\/]{3,29})/iu', $t, $m)) $d['siparis_no'] = strtoupper($m[1]);

    // 1) Başlık alanı: "İrsaliye No: CKI2026..." (değer alt satıra düşebilir, virgüllü liste olabilir)
    //    Karadeniz Demir: "İrsaliye No (1): KRI2026000025094" — sıra numarası parantezde
    if (preg_match_all('/İrsaliye\s*No(?:su)?(?:\s*\(\d+\))?[:\s]*([A-Z]{2,6}\d{6,20}(?:\s*,\s*[A-Z]{2,6}\d{6,20})*)/iu', $t, $mm)) {
        foreach ($mm[1] as $grup) foreach (preg_split('/\s*,\s*/', $grup) as $no) $ekle($no);
    }
    // 1b) Alt bilgi listesi: "İrsaliyeler:\nKRI2026000025094, 27/10/2026" — numara + tarih çiftleri
    if (preg_match('/İrsaliyeler\s*:(.{0,800})/isu', $t, $bm)) {
        $blok = preg_split('/Notlar\s*:/iu', $bm[1])[0];
        if (preg_match_all('/\b([A-Z]{2,6}\d{6,20})\s*,\s*\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{4}/u', $blok, $mm)) {
            foreach ($mm[1] as $no) $ekle($no);
        }
    }
    // 2) Kalem içi: "[İRS.NO:2124-08.05.2026]" — düz sayı numara
    if (preg_match_all('/\[?İRS\.?\s*NO[:\s]*([0-9]{2,12})\s*-\s*[\d.\-\/]{8,10}\]?/iu', $t, $mm)) {
        foreach ($mm[1] as $no) $ekle($no);
    }
    // 3) Dipnot: "[E-IRSALIYE NO TARIH:13.08.2026-CNG2026000020738]".
    //    Satır kırıldığında ARADAKİ TİRE KAYBOLABİLİYOR ("...TARIH:13.08.2026\nCNG2026000020745")
    //    — bu yüzden tarih açıkça eşlenir, tire/boşluk ayracı OPSİYONELDİR.
    if (preg_match_all('/E-?[İI]RSAL[İI]YE\s*NO\s*TAR[İI]H[:\s]*\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{4}[\s-]*([A-Z]{2,6}\d{6,20})/iu', $t, $mm)) {
        foreach ($mm[1] as $no) $ekle($no);
    }
    // "[BELGE NO:21111]" → kantar fişi/belge numaraları (sevkiyat eşleşmesinde 2. anahtar)
    if (preg_match_all('/\[?BELGE\s*NO[:\s]*([0-9]{3,12})\]?/iu', $t, $mm)) {
        foreach (array_unique($mm[1]) as $no) $d['belge_nolar'][] = $no;
    }
    // "SEVKIYATNO: SE-3069896" — bilgi amaçlı (tedarikçinin iç sevkiyat no'su)
    if (preg_match_all('/SEVK[İI]YAT\s*NO[:\s]*((?:SE-)?[A-Z0-9\-]{5,20})/iu', $t, $mm)) {
        foreach (array_unique($mm[1]) as $no) $d['sevkiyat_nolar'][] = strtoupper($no);
    }

    // Miktar: "Toplam Miktar 27680" / "TOPLAM MİKTAR : 66420.000" → kg;
    // yoksa kalem satırlarındaki ",00 kg" değerlerinin toplamı (ondalıksız özet
    // satırındaki "26,550KG" tekrarları çift saymasın diye ondalık ŞART)
    if (preg_match('/Toplam\s*M[İIiı]ktar[:\s]*([\d.,]+)/iu', $t, $m)) $d['miktar_kg'] = dfat_kg($m[1]);
    if ($d['miktar_kg'] === null && preg_match_all('/([\d.,]+,\d{2})\s*kg\.?\b/iu', $t, $mm)) {
        $top = 0.0; $var = false;
        foreach ($mm[1] as $v) { $f = fat_sayi($v); if ($f !== null) { $top += $f; $var = true; } }
        if ($var) $d['miktar_kg'] = $top;
    }
    // Çakıroğlu: "10.800\n Kg." · Karadeniz: "26.400 KG" (ondalıksız binlik → dfat_kg).
    // Büyük harfli KG için boşluk şart: "10MM-26,550KG" özet tekrarları toplamı ikiye katlamasın.
    if ($d['miktar_kg'] === null && preg_match_all('/([\d.,]+)(?:\s*Kg\.?|\s+KG)\b/u', $t, $mm)) {
        $top = 0.0; $var = false;
        foreach ($mm[1] as $v) { $f = dfat_kg($v); if ($f !== null) { $top += $f; $var = true; } }
        if ($var) $d['miktar_kg'] = $top;
    }

    if (preg_match('/Vergiler\s*Dahil\s*Toplam\s*Tutar[:\s]*([\d.,]+)/iu', $t, $m)) $d['brut_tutar'] = fat_sayi($m[1]);
    if (preg_match('/Ödenecek\s*Tutar[:\s]*([\d.,]+)/iu', $t, $m)) $d['tutar'] = fat_sayi($m[1]);
    if ($d['tutar'] === null) $d['tutar'] = $d['brut_tutar'];

    // Çaplar (bilgi amaçlı): "12MM NERV" / "Ø 20 MM" / özet "10MM-26,550KG"; Q100+ hasır, altı kangal
    if (preg_match_all('/(?:Ø\s*)?(\d{1,2})\s*MM\b/iu', $t, $mm)) foreach ($mm[1] as $c) if ((int)$c >= 6 && (int)$c <= 50) $d['caplar']["Ø{$c}"] = true;
    if (preg_match_all('/\bQ\s?(\d{2,3})\b/u', $t, $mm)) foreach ($mm[1] as $c) {
        if ((int)$c >= 100) $d['caplar']["Q{$c} (hasır)"] = true;
        elseif (!isset($d['caplar']["Ø{$c}"])) $d['caplar']["Q{$c} (kangal)"] = true;   // aynı çap Ø olarak zaten varsa tekrar yazma
    }
    $d['caplar'] = array_keys($d['caplar']);

    // KALEMLER: çap + kg (+ hangi irsaliyeye ait) — taslak sevkiyat kalemleri buradan kurulur.
    // Kalem kg'ları ondalıklı yazılır ("26.360,00 kg"); ondalıksız özet tekrarları dışarıda kalır.
    $d['kalemler'] = [];
    $kgListe = [];
    if (preg_match_all('/([\d.,]+,\d{2})\s*kg\b/iu', $t, $km)) foreach ($km[1] as $v) { $f = fat_sayi($v); if ($f !== null) $kgListe[] = $f; }
    $capIrs = [];
    if (preg_match_all('/Ø\s*(\d{1,2})\s*MM[^\[\n]{0,80}\n?\s*\[İRS\.?\s*NO[:\s]*(\d+)/iu', $t, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $g) $capIrs[] = ['cap' => (int)$g[1], 'irs' => strtoupper($g[2])];
    }
    if ($capIrs && count($capIrs) === count($kgListe)) {
        // Ali Cangül düzeni: her kalemde Ø + [İRS.NO] + kg — birebir eşle
        foreach ($capIrs as $i => $ci) $d['kalemler'][] = ['cap' => $ci['cap'], 'kg' => $kgListe[$i], 'irs' => $ci['irs']];
    } elseif (preg_match_all('/(\d{1,2})\s*MM\s*NERV.{0,60}?([\d.,]+)\s*Kg\b/su', $t, $mm, PREG_SET_ORDER)) {
        // Çakıroğlu düzeni: kalemde çap + kg (irsaliye tek olduğundan hepsi ona ait).
        // "27.680" ondalıksız BİNLİK yazımdır (27.680 kg = 27680) — fat_sayi ondalık sanmasın.
        foreach ($mm as $g) {
            $ham = trim($g[2]);
            $f = preg_match('/^\d{1,3}(\.\d{3})+$/', $ham) ? (float)str_replace('.', '', $ham) : fat_sayi($ham);
            if ($f !== null) $d['kalemler'][] = ['cap' => (int)$g[1], 'kg' => $f, 'irs' => null];
        }
    } elseif ($kgListe && count($kgListe) === count($d['irsaliyeler'])) {
        // Cangül düzeni: kalemlerde çap yok; kalem sırası dipnottaki e-irsaliye sırasıyla eşleşir
        foreach ($kgListe as $i => $kg) $d['kalemler'][] = ['cap' => null, 'kg' => $kg, 'irs' => $d['irsaliyeler'][$i]];
    } elseif (preg_match_all('/(?:Ø\s*|\bN\s*-?\s*)?(\d{1,2})\s*MM\b[^\n]{0,100}?\s([\d.]+(?:,\d+)?)\s*KG\b/iu', $t, $mm, PREG_SET_ORDER)) {
        // Karadeniz düzeni: "N-12mm 12mt İnş.Demiri 26.400 KG" — satırda çap + kg (irsaliye tek)
        foreach ($mm as $g) {
            $c = (int)$g[1]; $f = dfat_kg($g[2]);
            if ($c >= 6 && $c <= 50 && $f !== null && $f > 0) $d['kalemler'][] = ['cap' => $c, 'kg' => $f, 'irs' => null];
        }
    }
    // Sağlama: kalem toplamı fatura toplamının binde biriyse ölçek hatası var demektir — düzelt
    if ($d['kalemler'] && $d['miktar_kg'] !== null) {
        $ktop = array_sum(array_column($d['kalemler'], 'kg'));
        if ($ktop > 0 && abs($ktop * 1000 - $d['miktar_kg']) < max(1.0, $d['miktar_kg'] * 0.001)) {
            foreach ($d['kalemler'] as &$kl0) $kl0['kg'] *= 1000;
            unset($kl0);
        }
    }

    // Satıcı VKN + unvan: SAYIN bloğundaki VKN alıcınındır, diğeri satıcının
    $saticiOff = null;
    if (preg_match_all('/VKN[:\s]*([0-9]{10,11})/iu', $t, $vm, PREG_OFFSET_CAPTURE)) {
        $sayinOff = stripos($t, 'SAYIN'); $aliciVkn = null;
        if ($sayinOff !== false) foreach ($vm[1] as [$v0,$o0]) if ($o0 > $sayinOff) { $aliciVkn = $v0; break; }
        foreach ($vm[1] as [$v0,$o0]) if ($v0 !== $aliciVkn) { $d['satici_vkn'] = $v0; $saticiOff = $o0; break; }
    }
    if ($saticiOff !== null) {
        // Fatura düzeni satıcı bloğunu SEVKIYATNO/adres satırlarıyla bölebilir — pencereyi geniş tut
        $pencere = substr($t, max(0, $saticiOff - 1200), min(1200, $saticiOff));
        if (preg_match_all('/^[^\n]{0,120}?(?:ANON[İI]M\s+[ŞS][İI]RKET[İI]|L[İI]M[İI]TED\s+[ŞS][İI]RKET[İI]|A\.\s*[ŞS]\.?|LTD\.?\s*[ŞS]T[İI]\.?)[^\n]{0,10}$/miu', $pencere, $umAll, PREG_OFFSET_CAPTURE)) {
            $um = ['0' => end($umAll[0])];   // VKN'ye EN YAKIN unvan satırı (ilk satır alıcı olabilir)
            $unvan = trim($um[0][0]);
            // Uzun unvan iki satıra bölünmüş olabilir ("ÇAKIROĞLU ... SANAYİ VE\nTİCARET ANONİM ŞİRKETİ",
            // "CANGÜL ... NAKLİYAT\nTURİZM SANAYİ VE TİCARET A.Ş."): üstteki satır da tamamen
            // BÜYÜK HARFLİ unvan parçası görünüyorsa başa eklenir.
            $oncesi = substr($pencere, 0, (int)$um[0][1]);
            $satirlar = array_values(array_filter(array_map('trim', explode("\n", $oncesi)), fn($s) => $s !== ''));
            $ust = $satirlar ? end($satirlar) : '';
            if ($ust !== '' && mb_strlen($ust) <= 60
                && preg_match('/^[A-ZÇĞİÖŞÜ0-9 .&\-]+$/u', $ust)
                && !preg_match('/SAYIN|VKN|ETTN|TEL|FAX|MAH|CAD|SOKAK|NO:|E-FATURA|SEVK/iu', $ust)) {
                $unvan = $ust . ' ' . $unvan;
            }
            $d['satici_unvan'] = $unvan;
        }
    }

    // İDİS düzeni (Karadeniz): "Satıcı (Şube): Karadeniz Demir …" + "Vergi No / Dairesi: 5090025659 / …".
    // "VKN" kelimesi hiç geçmez; satıcı bloğu "Alıcı" bloğundan ÖNCE gelir.
    if ($d['satici_vkn'] === null && preg_match_all('/Vergi\s*No(?:\s*\/\s*Dairesi)?\s*:\s*([0-9]{10,11})/iu', $t, $vm, PREG_OFFSET_CAPTURE)) {
        $aliciOff = preg_match('/Al[ıi]c[ıi](?:\s*\([^)\n]*\))?\s*:/iu', $t, $am, PREG_OFFSET_CAPTURE) ? $am[0][1] : null;
        foreach ($vm[1] as [$v0, $o0]) if ($aliciOff === null || $o0 < $aliciOff) { $d['satici_vkn'] = $v0; break; }
    }
    if ($d['satici_unvan'] === null && preg_match('/Sat[ıi]c[ıi](?:\s*\([^)\n]*\))?\s*:\s*([^\n]{3,150})/iu', $t, $sm)) {
        $d['satici_unvan'] = trim($sm[1]);
    }

    // Karekod metne yapıştırılmışsa kesin alanlar oradan gelir
    $qr = fat_qr_coz($metin);
    if ($qr) {
        foreach (['fatura_no','tarih','ettn','tutar'] as $a) if (($qr[$a] ?? null)) $d[$a] = $qr[$a];
        if (($qr['vkn'] ?? null)) $d['satici_vkn'] = $qr['vkn'];
        if ($d['brut_tutar'] === null && ($qr['vergidahil'] ?? null) !== null) $d['brut_tutar'] = $qr['vergidahil'];
    }
    return $d;
}

/**
 * Faturadaki numaraları demir sevkiyatlarıyla normalize eşleştirir.
 * İki anahtar denenir: sevkiyat İRSALİYE NO'su ve KANTAR FİŞ NO'su
 * (Cangül faturasındaki [BELGE NO:21111] kantar fişine karşılık gelir).
 * Düz sayılı numaralar da (Ali Cangül "2124") desteklenir.
 */
function dfat_eslestir(PDO $pdo, array $numaralar, array $belgeNolar = []): array {
    $hedef = [];
    foreach ($numaralar as $n) { $k = fat_irs_norm($n); if ($k !== '') $hedef[$k] = ['ham'=>$n, 'tur'=>'irsaliye']; }
    foreach ($belgeNolar as $n) { $k = fat_irs_norm($n); if ($k !== '' && !isset($hedef[$k])) $hedef[$k] = ['ham'=>$n, 'tur'=>'belge']; }
    if (!$hedef) return ['eslesen'=>[], 'eksik'=>[]];

    $sql = "SELECT s.id, s.irsaliye_no, s.kantar_fis_no, s.gelis_tarih, s.irsaliye_tarih, s.arac_plaka, s.fatura_id,
                   t.ad AS tedarikci, p.kod AS proje,
                   COALESCE(SUM(k.irsaliye_miktar),0) irs_ton, COALESCE(SUM(k.kantar_miktar),0) kantar_ton
            FROM demir_sevkiyatlar s
            LEFT JOIN demir_tedarikciler t ON t.id = s.tedarikci_id
            LEFT JOIN demir_projeler p ON p.id = s.proje_id
            LEFT JOIN demir_sevkiyat_kalemleri k ON k.sevkiyat_id = s.id
            WHERE (s.irsaliye_no IS NOT NULL AND s.irsaliye_no <> '')
               OR (s.kantar_fis_no IS NOT NULL AND s.kantar_fis_no <> '')
            GROUP BY s.id";
    $idx = [];   // norm anahtar → [sevkiyat, anahtar türü]
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $ki = fat_irs_norm((string)$r['irsaliye_no']);
        $kk = fat_irs_norm((string)$r['kantar_fis_no']);
        if ($ki !== '' && !isset($idx[$ki])) $idx[$ki] = [$r, 'irsaliye no'];
        if ($kk !== '' && !isset($idx[$kk])) $idx[$kk] = [$r, 'kantar fişi'];
    }
    $eslesen = []; $eksik = []; $gorulen = [];
    foreach ($hedef as $k => $h) {
        if (isset($idx[$k])) {
            [$r, $anahtar] = $idx[$k];
            if (isset($gorulen[(int)$r['id']])) continue;   // aynı sevkiyat iki anahtardan gelmesin
            $gorulen[(int)$r['id']] = true;
            $r['fatura_gosterim'] = $h['ham']; $r['eslesme_anahtari'] = $anahtar;
            $eslesen[] = $r;
        } elseif ($h['tur'] === 'irsaliye') {
            $eksik[] = $h['ham'];   // belge no bulunamaması "eksik" sayılmaz (yedek anahtardır)
        }
    }
    return ['eslesen'=>$eslesen, 'eksik'=>$eksik];
}

$fmt  = fn($n) => number_format((float)$n, 2, ',', '.');
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');
$sonuc = null; $hata = '';

// ── 1) Çözümle ───────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'cozumle') {
    $metin = trim((string)($_POST['metin'] ?? ''));
    $dosyaUrl = '';
    if (!empty($_FILES['dosya']['tmp_name']) && is_uploaded_file($_FILES['dosya']['tmp_name'])) {
        $tmp = $_FILES['dosya']['tmp_name']; $ad = (string)$_FILES['dosya']['name'];
        $mime = guess_mime($tmp, $ad);
        if (!in_array($mime, ['application/pdf','image/jpeg','image/png','image/webp'], true)) $hata = 'Desteklenmeyen dosya türü: '.$mime;
        elseif ((int)$_FILES['dosya']['size'] > 20*1024*1024) $hata = 'Dosya çok büyük (en fazla 20 MB).';
        else {
            $dir = __DIR__ . '/../uploads/demir_fatura/' . date('Y/m');
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $ext = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'pdf';
            $hedefAd = date('Ymd_His') . '_' . substr(md5($ad.microtime(true)), 0, 8) . '.' . $ext;
            $tam = $tmp;
            if (@move_uploaded_file($tmp, $dir.'/'.$hedefAd)) { $dosyaUrl = 'uploads/demir_fatura/'.date('Y/m').'/'.$hedefAd; $tam = $dir.'/'.$hedefAd; }
            $aiSistem = dfat_ai_sistem();   // demire özgü talimat (irsaliye/belge etiketleri tedarikçiye göre değişir)
            $okunan = fat_dosyadan_metin($tam, $mime, $kaynakOkuma, $aiSistem);
            if ($okunan !== null && $okunan !== '') $metin = $okunan;
            elseif ($metin === '') $hata = 'Dosyadan metin okunamadı — fatura metnini kopyalayıp kutuya yapıştırın.';
        }
    }
    if (!$hata) {
        if ($metin === '') $hata = 'Fatura dosyası yükleyin veya metnini yapıştırın.';
        else {
            $v = dfat_cikar($metin);
            // Metinden okunamayan başlık alanları için DOSYA ADI yedeği
            // (kullanıcı dosyayı çoğu zaman fatura numarasıyla kaydediyor: CKE2026000003934.pdf)
            if ($v['fatura_no'] === null && !empty($ad) && preg_match('/([A-Z][A-Z0-9]{2}\d{13})/i', (string)$ad, $fm)) {
                $v['fatura_no'] = strtoupper($fm[1]);
            }
            $e = dfat_eslestir($pdoDemir, $v['irsaliyeler'], $v['belge_nolar']);
            $mevcut = null;
            $mq = $pdoDemir->prepare("SELECT * FROM demir_faturalar WHERE fatura_no = ? OR (ettn IS NOT NULL AND ettn <> '' AND ettn = ?) LIMIT 1");
            $mq->execute([(string)$v['fatura_no'], (string)$v['ettn']]);
            $mevcut = $mq->fetch() ?: null;
            // Tedarikçi önerisi: VKN → normalize unvan (öneri aşamasında OLUŞTURULMAZ)
            $onerTedId = (int)(dfat_tedarikci_coz($pdoDemir, null, $v['satici_unvan'], $v['satici_vkn'], false)[0] ?? 0);
            $sonuc = ['v'=>$v, 'e'=>$e, 'dosya_url'=>$dosyaUrl, 'mevcut'=>$mevcut, 'oner_ted'=>$onerTedId,
                      'metin'=>$metin, 'kaynak'=>$kaynakOkuma ?? null];
        }
    }
}

// ── Ortak kayıt çekirdeği (tekil kayıt · yeniden okuma · toplu işleme) ─────────
/**
 * Tedarikçi çözümü: seçili id → VKN → normalize unvan → (izin varsa) oluştur.
 * VKN'siz mevcut kayda faturadaki VKN tamamlanır. Döner: [id|null, ad|null, yeni_mi]
 */
function dfat_tedarikci_coz(PDO $pdo, ?int $tedId, ?string $unvan, ?string $vkn, bool $olustur = true): array {
    if ($tedId) {
        $tq = $pdo->prepare("SELECT ad FROM demir_tedarikciler WHERE id = ?"); $tq->execute([$tedId]);
        $ad = $tq->fetchColumn();
        if ($ad !== false) return [$tedId, (string)$ad, false];
    }
    $unvan = trim((string)$unvan);
    $vkn   = preg_replace('/\D/', '', (string)$vkn) ?: null;
    $liste = $pdo->query("SELECT id, ad, vkn FROM demir_tedarikciler")->fetchAll();
    if ($vkn) foreach ($liste as $td) {
        if (preg_replace('/\D/', '', (string)$td['vkn']) === $vkn) return [(int)$td['id'], (string)$td['ad'], false];
    }
    $u = fat_unvan_norm($unvan);
    if ($u !== '' && mb_strlen($u) >= 4) {
        foreach ($liste as $td) {
            $a = fat_unvan_norm($td['ad']);
            if ($a !== '' && mb_strlen($a) >= 4 && (str_contains($u, $a) || str_contains($a, $u))) {
                if ($vkn && trim((string)$td['vkn']) === '') {
                    $pdo->prepare("UPDATE demir_tedarikciler SET vkn = ? WHERE id = ?")->execute([$vkn, (int)$td['id']]);
                }
                return [(int)$td['id'], (string)$td['ad'], false];
            }
        }
        if ($olustur) {
            $ad = mb_substr($unvan, 0, 150);
            $pdo->prepare("INSERT INTO demir_tedarikciler (ad, vkn) VALUES (?, ?)")->execute([$ad, $vkn]);
            return [(int)$pdo->lastInsertId(), $ad, true];
        }
    }
    return [null, $unvan !== '' ? $unvan : null, false];
}

/**
 * Faturayı kaydeder — no VEYA ETTN ile kayıtlıysa GÜNCELLER (mükerrer açılmaz) — ve verilen
 * sevkiyatları bağlar. $eksikOlustur: sevkiyatlarda bulunamayan irsaliyeler [FATURADAN] taslak
 * sevkiyat olarak açılır (Excel aktarımı aynı numarayı getirince silinmez, güncellenir).
 * $p: fatura_no, ettn, tarih (Y-m-d), tedarikci_id, tedarikci (unvan), satici_vkn, siparis_no,
 *     irsaliyeler[], miktar_kg (float), tutar, brut_tutar, dosya_url, notlar, kalemler[]
 */
function dfat_kaydet(PDO $pdo, array $p, array $svkIds, bool $eksikOlustur): array {
    $faturaNo = strtoupper(trim((string)($p['fatura_no'] ?? '')));
    if ($faturaNo === '') throw new RuntimeException('Fatura no zorunlu.');
    $ettn = strtoupper(trim((string)($p['ettn'] ?? ''))) ?: null;
    [$tedId, $tedAd, $tedYeni] = dfat_tedarikci_coz($pdo, (int)($p['tedarikci_id'] ?? 0) ?: null,
                                                    $p['tedarikci'] ?? null, $p['satici_vkn'] ?? null);
    $irsListe = array_values(array_unique(array_filter(array_map(fn($x) => strtoupper(trim((string)$x)), (array)($p['irsaliyeler'] ?? [])))));
    $kalemler = array_slice(array_values(array_filter((array)($p['kalemler'] ?? []), 'is_array')), 0, 200);

    $mq = $pdo->prepare("SELECT id FROM demir_faturalar WHERE fatura_no = ? OR (ettn IS NOT NULL AND ettn <> '' AND ? <> '' AND ettn = ?) LIMIT 1");
    $mq->execute([$faturaNo, (string)$ettn, (string)$ettn]);
    $fid = (int)($mq->fetchColumn() ?: 0);
    $yeni = $fid === 0;

    $par = [$ettn, fat_tarih_norm((string)($p['tarih'] ?? '')) ?: null, $tedAd, $tedId,
            trim((string)($p['siparis_no'] ?? '')) ?: null,
            $irsListe ? json_encode($irsListe, JSON_UNESCAPED_UNICODE) : null,
            isset($p['miktar_kg']) && $p['miktar_kg'] !== null ? (float)$p['miktar_kg'] : null,
            isset($p['tutar']) && $p['tutar'] !== null ? (float)$p['tutar'] : null,
            isset($p['brut_tutar']) && $p['brut_tutar'] !== null ? (float)$p['brut_tutar'] : null,
            trim((string)($p['dosya_url'] ?? '')) ?: null,
            trim((string)($p['notlar'] ?? '')) ?: null,
            $kalemler ? json_encode($kalemler, JSON_UNESCAPED_UNICODE) : null];
    if ($fid) {
        $pdo->prepare("UPDATE demir_faturalar SET ettn=?, tarih=?, tedarikci=?, tedarikci_id=?, siparis_no=?,
            irsaliye_liste=?, miktar_kg=?, tutar=?, brut_tutar=?, dosya_url=COALESCE(?, dosya_url), notlar=?,
            kalemler=COALESCE(?, kalemler) WHERE id=?")
            ->execute([...$par, $fid]);
    } else {
        $pdo->prepare("INSERT INTO demir_faturalar (fatura_no, ettn, tarih, tedarikci, tedarikci_id, siparis_no,
            irsaliye_liste, miktar_kg, tutar, brut_tutar, dosya_url, notlar, kalemler, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$faturaNo, ...$par, current_user_id()]);
        $fid = (int)$pdo->lastInsertId();
    }
    // Sevkiyat bağları
    $bagli = 0;
    $upd = $pdo->prepare("UPDATE demir_sevkiyatlar SET fatura_id = ? WHERE id = ?");
    foreach (array_unique(array_filter(array_map('intval', $svkIds))) as $sid) { $upd->execute([$fid, $sid]); $bagli++; }

    $taslakOlusan = 0; $taslakUyari = '';
    if ($eksikOlustur) {
        if (!$tedId) {
            $taslakUyari = 'Taslak sevkiyat OLUŞTURULMADI: tedarikçi seçilmedi ve faturadan da çözülemedi.';
        } else {
            // Kaydetme anında yeniden doğrula — bu arada girilmiş sevkiyat varsa taslak AÇILMAZ (mükerrer imkânsız).
            // Başka faturaya bağlı sevkiyat burada TAŞINMAZ (o karar tekil ekranda kutucukla verilir).
            $tekrar = dfat_eslestir($pdo, $irsListe, []);
            foreach ($tekrar['eslesen'] as $r0) {
                if (empty($r0['fatura_id']) || (int)$r0['fatura_id'] === $fid) $upd->execute([$fid, (int)$r0['id']]);
            }
            // Çap çözümü: demir_caplar'daki adlardan sayı çıkar (duz öncelikli)
            $capMap = [];
            foreach ($pdo->query("SELECT id, ad, tip FROM demir_caplar") as $c0) {
                if (preg_match('/(\d+)/', (string)$c0['ad'], $cm)) {
                    $n0 = (int)$cm[1];
                    if (!isset($capMap[$n0]) || $c0['tip'] === 'duz') $capMap[$n0] = (int)$c0['id'];
                }
            }
            $tarih0 = fat_tarih_norm((string)($p['tarih'] ?? '')) ?: date('Y-m-d');
            $insS = $pdo->prepare("INSERT INTO demir_sevkiyatlar
                (irsaliye_no, irsaliye_tarih, gelis_tarih, tedarikci_id, aciklama, fatura_id, created_by)
                VALUES (?,?,?,?,?,?,?)");
            $insK = $pdo->prepare("INSERT INTO demir_sevkiyat_kalemleri (sevkiyat_id, cap_id, irsaliye_miktar) VALUES (?,?,?)");
            foreach ($tekrar['eksik'] as $no0) {
                $insS->execute([$no0, $tarih0, $tarih0, $tedId,
                    '[FATURADAN] ' . $faturaNo . ' faturasından otomatik oluşturuldu — Excel aktarımında gerçek verilerle güncellenir.',
                    $fid, current_user_id()]);
                $sid0 = (int)$pdo->lastInsertId();
                // Kalemler: bu irsaliyeye ait olanlar; irsaliye tekse tümü
                $n0k = fat_irs_norm($no0);
                foreach ($kalemler as $kl) {
                    $ait = (isset($kl['irs']) && $kl['irs'] !== null && $kl['irs'] !== '')
                         ? (fat_irs_norm((string)$kl['irs']) === $n0k)
                         : (count($irsListe) === 1);
                    if (!$ait || empty($kl['kg'])) continue;
                    $capId0 = isset($kl['cap']) && $kl['cap'] !== null ? ($capMap[(int)$kl['cap']] ?? null) : null;
                    if ($capId0 === null) continue;   // çapı bilinmeyen kalem yazılamaz (cap_id zorunlu)
                    $insK->execute([$sid0, $capId0, round(((float)$kl['kg']) / 1000, 3)]);
                }
                $taslakOlusan++;
            }
        }
    }
    return ['id' => $fid, 'no' => $faturaNo, 'yeni' => $yeni, 'bagli' => $bagli, 'taslak' => $taslakOlusan,
            'uyari' => $taslakUyari, 'ted_ad' => $tedAd, 'ted_yeni' => $tedYeni];
}

/** Faturadaki eşleşen sevkiyatları ikiye ayırır: bağlanabilir (boşta / zaten bu faturada) · başka faturada. */
function dfat_bagla_ayir(array $eslesen, int $fid = 0): array {
    $ids = []; $baska = [];
    foreach ($eslesen as $r) {
        if (empty($r['fatura_id']) || ($fid && (int)$r['fatura_id'] === $fid)) $ids[] = (int)$r['id'];
        else $baska[] = (string)($r['irsaliye_no'] ?: $r['kantar_fis_no']);
    }
    return [$ids, $baska];
}

// ── 2) Kaydet ────────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'kaydet') {
    try {
        $kal = json_decode((string)($_POST['kalemler'] ?? ''), true);
        $r = dfat_kaydet($pdoDemir, [
            'fatura_no'   => (string)($_POST['fatura_no'] ?? ''),
            'ettn'        => (string)($_POST['ettn'] ?? ''),
            'tarih'       => (string)($_POST['tarih'] ?? ''),
            'tedarikci_id'=> (int)($_POST['tedarikci_id'] ?? 0),
            'tedarikci'   => trim((string)($_POST['tedarikci'] ?? '')) ?: null,
            'satici_vkn'  => (string)($_POST['satici_vkn'] ?? ''),
            'siparis_no'  => (string)($_POST['siparis_no'] ?? ''),
            'irsaliyeler' => explode(',', (string)($_POST['irsaliye_liste'] ?? '')),
            // ⚠ Kutu "26.400" biçiminde basılır — fat_sayi bunu 26,4 okuyordu; dfat_kg binliği tanır
            'miktar_kg'   => dfat_kg((string)($_POST['miktar_kg'] ?? '')),
            'tutar'       => fat_sayi((string)($_POST['tutar'] ?? '')),
            'brut_tutar'  => fat_sayi((string)($_POST['brut_tutar'] ?? '')),
            'dosya_url'   => (string)($_POST['dosya_url'] ?? ''),
            'notlar'      => (string)($_POST['notlar'] ?? ''),
            'kalemler'    => is_array($kal) ? $kal : [],
        ], (array)($_POST['svk_id'] ?? []), !empty($_POST['eksik_olustur']));
        if ($r['ted_yeni']) flash('info', 'Yeni demir tedarikçisi oluşturuldu: ' . $r['ted_ad']);
        flash('success', "Fatura {$r['no']} kaydedildi"
            . ($r['bagli'] ? ", {$r['bagli']} sevkiyata bağlandı" : "")
            . ($r['taslak'] ? ", {$r['taslak']} eksik irsaliye TASLAK sevkiyat olarak oluşturuldu (Excel aktarımında gerçek verilerle güncellenir)" : "") . ".");
        if ($r['uyari']) flash('warning', $r['uyari']);
    } catch (Throwable $eK) { flash('error', 'Kayıt hatası: ' . $eK->getMessage()); }
    redirect('faturalar.php');
}

// ── 2a) Kayıtlı faturayı DOSYASINDAN yeniden oku (eski sürümde okunamayan irsaliye/kg/tedarikçi) ──
// Dolu tutar/tarih/ETTN korunur; irsaliye listesi, kg ve kalemler yeni okumadan gelir. Eşleşen sevkiyatlar
// bağlanır — BAŞKA faturaya bağlı olanlar taşınmaz, yalnız raporlanır.
if (($_POST['action'] ?? '') === 'yeniden_oku' && ctype_digit((string)($_POST['fatura_id'] ?? ''))) {
    @set_time_limit(180);
    $fid = (int)$_POST['fatura_id'];
    $fq = $pdoDemir->prepare("SELECT * FROM demir_faturalar WHERE id = ?"); $fq->execute([$fid]);
    $f = $fq->fetch();
    $kok = realpath(__DIR__ . '/../uploads/demir_fatura');
    $tam = ($f && $f['dosya_url']) ? realpath(__DIR__ . '/../' . $f['dosya_url']) : false;
    if (!$f) flash('error', 'Fatura bulunamadı.');
    elseif (!$tam || !$kok || !str_starts_with($tam, $kok . DIRECTORY_SEPARATOR) || !is_file($tam)) {
        flash('error', 'Faturanın dosyası sunucuda bulunamadı — PDF\'i yukarıdan yeniden yükleyin (kayıt güncellenir, mükerrer açılmaz).');
    } else {
        try {
            $metin = fat_dosyadan_metin($tam, guess_mime($tam, $tam), $kyn, dfat_ai_sistem());
            if ($metin === null || trim($metin) === '') throw new RuntimeException('dosyadan metin okunamadı.');
            $v = dfat_cikar($metin);
            $e = dfat_eslestir($pdoDemir, $v['irsaliyeler'], $v['belge_nolar']);
            [$ids, $baska] = dfat_bagla_ayir($e['eslesen'], $fid);
            $eskiIrs = json_decode((string)$f['irsaliye_liste'], true) ?: [];
            $eskiKal = json_decode((string)($f['kalemler'] ?? ''), true) ?: [];
            $r = dfat_kaydet($pdoDemir, [
                'fatura_no'   => $f['fatura_no'],
                'ettn'        => $f['ettn'] ?: $v['ettn'],
                'tarih'       => $f['tarih'] ?: $v['tarih'],
                'tedarikci_id'=> (int)$f['tedarikci_id'],
                'tedarikci'   => $f['tedarikci'] ?: $v['satici_unvan'],
                'satici_vkn'  => $v['satici_vkn'],
                'siparis_no'  => $f['siparis_no'] ?: $v['siparis_no'],
                'irsaliyeler' => $v['irsaliyeler'] ?: $eskiIrs,
                'miktar_kg'   => $v['miktar_kg'] ?? ($f['miktar_kg'] !== null ? (float)$f['miktar_kg'] : null),
                'tutar'       => $f['tutar'] !== null ? (float)$f['tutar'] : $v['tutar'],
                'brut_tutar'  => $f['brut_tutar'] !== null ? (float)$f['brut_tutar'] : $v['brut_tutar'],
                'dosya_url'   => null,
                'notlar'      => $f['notlar'],
                'kalemler'    => $v['kalemler'] ?: $eskiKal,
            ], $ids, false);
            $msj = "{$f['fatura_no']} dosyasından yeniden okundu (" . ($kyn === 'ai' ? 'AI' : 'pdftotext') . "): "
                 . count($v['irsaliyeler']) . ' irsaliye'
                 . ($v['miktar_kg'] !== null ? ', ' . number_format($v['miktar_kg'] / 1000, 3, ',', '.') . ' t' : '')
                 . ($r['bagli'] ? ", {$r['bagli']} sevkiyata bağlandı" : '') . '.';
            if ($e['eksik']) $msj .= ' Sevkiyatlarda bulunamayan: ' . implode(', ', $e['eksik']) . ' — sevkiyat girilince tekrar okuyun ya da PDF\'i yükleyip taslak kutusunu kullanın.';
            if ($baska) $msj .= ' Başka faturaya bağlı olduğu için TAŞINMAYAN: ' . implode(', ', $baska) . '.';
            if ($r['ted_yeni']) $msj .= ' Yeni tedarikçi oluşturuldu: ' . $r['ted_ad'] . '.';
            flash(($e['eksik'] || $baska || !$v['irsaliyeler']) ? 'warning' : 'success', $msj);
        } catch (Throwable $eY) { flash('error', 'Yeniden okuma hatası: ' . $eY->getMessage()); }
    }
    redirect('faturalar.php' . (($_POST['fatura_ara'] ?? '') !== '' ? '?fatura_ara=' . urlencode((string)$_POST['fatura_ara']) : ''));
}

// ── 2c) TOPLU FATURA KONTROLÜ: "bu 100 dosyadan hangisi işlenmiş?" (beton deseni) ──
// Numara önce DOSYA ADINDAN (e-fatura dosyaları numarayla adlandırılır), yoksa PDF içeriğinden
// (yalnız pdftotext — AI harcanmaz) çıkarılır; kayıtlı faturalarla no VEYA ETTN üzerinden normalize
// karşılaştırılır. İşlenmemişler modülün kendi bekleme klasöründe tutulur ki rapordaki
// "ÇÖZÜMLE ve KAYDET" düğmesi yeniden yükletmeden işleyebilsin.
$DFAT_BEKLEME = __DIR__ . '/../uploads/demir_fatura_bekleyen';
if (($_POST['action'] ?? '') === 'fatura_kontrol' && !empty($_FILES['dosyalar']['tmp_name'])) {
    @set_time_limit(300);
    if (!is_dir($DFAT_BEKLEME)) @mkdir($DFAT_BEKLEME, 0755, true);
    foreach ((array)@glob($DFAT_BEKLEME . '/dfk_*') as $eskiF) {          // 2 günden eski artıklar temizlenir
        if (@filemtime($eskiF) < time() - 2*86400) @unlink($eskiF);
    }
    $noIdx = []; $ettnIdx = [];
    foreach ($pdoDemir->query("SELECT f.id, f.fatura_no, f.ettn, f.tarih, f.tedarikci, f.irsaliye_liste,
                                      (SELECT COUNT(*) FROM demir_sevkiyatlar s WHERE s.fatura_id = f.id) bagli
                               FROM demir_faturalar f") as $fr) {
        $k = fat_irs_norm((string)$fr['fatura_no']); if ($k !== '') $noIdx[$k] = $fr;
        $e0 = strtoupper(trim((string)$fr['ettn'])); if ($e0 !== '') $ettnIdx[$e0] = $fr;
    }
    $kSonuc = [];
    foreach ($_FILES['dosyalar']['tmp_name'] as $i => $tmp) {
        $ad = (string)$_FILES['dosyalar']['name'][$i];
        if ((int)$_FILES['dosyalar']['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            $kSonuc[] = ['ad'=>$ad, 'durum'=>'hata', 'mesaj'=>'yükleme hatası']; continue;
        }
        $mime = guess_mime($tmp, $ad);
        if (!in_array($mime, ['application/pdf','image/jpeg','image/png','image/webp'], true)) {
            $kSonuc[] = ['ad'=>$ad, 'durum'=>'hata', 'mesaj'=>'desteklenmeyen tür (' . $mime . ')']; continue;
        }
        $no = null; $ettn = null; $kaynak = 'dosya adı';
        if (preg_match('/([A-Z][A-Z0-9]{2}\d{13})/i', $ad, $m)) $no = strtoupper($m[1]);
        if ($no === null && $mime === 'application/pdf') {
            $t0 = fat_pdftotext($tmp);
            if ($t0 !== null && $t0 !== '') {
                $v0 = dfat_cikar($t0);
                $no = $v0['fatura_no']; $ettn = $v0['ettn']; $kaynak = 'PDF içeriği';
            }
        }
        $fr = $no !== null ? ($noIdx[fat_irs_norm($no)] ?? null) : null;
        if (!$fr && $ettn !== null) $fr = $ettnIdx[$ettn] ?? null;
        if ($fr) {
            $kSonuc[] = ['ad'=>$ad, 'no'=>$no ?: $fr['fatura_no'], 'durum'=>'islenmis', 'kaynak'=>$kaynak, 'id'=>(int)$fr['id'],
                         'tarih'=>$fr['tarih'], 'ted'=>$fr['tedarikci'], 'bagli'=>(int)$fr['bagli'],
                         'irs'=>count(json_decode((string)$fr['irsaliye_liste'], true) ?: [])];
            continue;
        }
        // Numara okunamasa da dosya bekletilir — "ÇÖZÜMLE ve KAYDET" AI ile içeriği okuyabilir
        $satir = ['ad'=>$ad, 'no'=>$no, 'durum'=>($no === null && $ettn === null) ? 'okunamadi' : 'islenmemis', 'kaynak'=>$no ? $kaynak : null];
        $ext = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'pdf';
        if (preg_match('/^[a-z0-9]{1,6}$/', $ext)) {
            $pid = uniqid('dfk_', true) . '.' . $ext;
            if (@move_uploaded_file($tmp, $DFAT_BEKLEME . '/' . $pid)) $satir['dosya'] = $pid;
        }
        $kSonuc[] = $satir;
    }
    $_SESSION['dfat_kontrol'] = array_merge($_SESSION['dfat_kontrol'] ?? [], $kSonuc);
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'islenen'    => count($kSonuc),
            'islenmis'   => count(array_filter($kSonuc, fn($r) => $r['durum'] === 'islenmis')),
            'islenmemis' => count(array_filter($kSonuc, fn($r) => $r['durum'] === 'islenmemis')),
            'diger'      => count(array_filter($kSonuc, fn($r) => !in_array($r['durum'], ['islenmis','islenmemis'], true))),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirect('faturalar.php?kontrol=1');
}
$kontrolSonuc = null;
if (isset($_GET['kontrol']) && !empty($_SESSION['dfat_kontrol'])) {
    $kontrolSonuc = $_SESSION['dfat_kontrol'];
    unset($_SESSION['dfat_kontrol']);
}

// ── 2d) İŞLENMEMİŞLERİ ÇÖZÜMLE ve KAYDET (kontrol raporundan, 2'şerli parti AJAX) ──
// metin (pdftotext→AI) → dfat_cikar → tedarikçi VKN/unvandan bul-yoksa oluştur → eşleşen
// sevkiyatları bağla (başka faturadakiler TAŞINMAZ) → isteğe bağlı eksikleri taslak sevkiyat aç.
if (($_POST['action'] ?? '') === 'fatura_isle' && !empty($_POST['dosya'])) {
    @set_time_limit(600);
    $taslakAc = !empty($_POST['taslak']);
    $iSonuc = [];
    $adlar = (array)($_POST['ad'] ?? []);
    foreach (array_values((array)$_POST['dosya']) as $i => $pid) {
        $pid = basename((string)$pid);
        if (!preg_match('/^dfk_[\w.]+$/', $pid)) continue;
        $orjAd = basename((string)($adlar[$i] ?? $pid));
        $tam = $DFAT_BEKLEME . '/' . $pid;
        if (!is_file($tam)) { $iSonuc[] = ['ad'=>$orjAd, 'durum'=>'hata', 'mesaj'=>'dosya bulunamadı (süresi dolmuş olabilir — kontrolü yeniden çalıştırın)']; continue; }
        try {
            $mime  = guess_mime($tam, $orjAd);
            $metin = fat_dosyadan_metin($tam, $mime, $kyn, dfat_ai_sistem());
            $v = ($metin !== null && trim($metin) !== '') ? dfat_cikar($metin) : null;
            if ($v && $v['fatura_no'] === null && preg_match('/([A-Z][A-Z0-9]{2}\d{13})/i', $orjAd, $fm)) $v['fatura_no'] = strtoupper($fm[1]);
            if (!$v || !$v['fatura_no']) {
                $iSonuc[] = ['ad'=>$orjAd, 'durum'=>'hata', 'mesaj'=>'fatura no çözülemedi — yukarıdaki "Fatura Yükle" ile tekil işleyin']; continue;
            }
            $mq = $pdoDemir->prepare("SELECT id FROM demir_faturalar WHERE fatura_no = ? OR (ettn IS NOT NULL AND ettn <> '' AND ? <> '' AND ettn = ?) LIMIT 1");
            $mq->execute([$v['fatura_no'], (string)$v['ettn'], (string)$v['ettn']]);
            if ($mq->fetchColumn()) {
                @unlink($tam);
                $iSonuc[] = ['ad'=>$orjAd, 'no'=>$v['fatura_no'], 'durum'=>'zaten', 'mesaj'=>'zaten işlenmiş']; continue;
            }
            $e = dfat_eslestir($pdoDemir, $v['irsaliyeler'], $v['belge_nolar']);
            [$ids, $baska] = dfat_bagla_ayir($e['eslesen']);
            $dir = __DIR__ . '/../uploads/demir_fatura/' . date('Y/m');
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $hedefAd = date('Ymd_His') . '_' . substr(md5($pid . microtime(true)), 0, 8) . '.' . (pathinfo($pid, PATHINFO_EXTENSION) ?: 'pdf');
            $dosyaUrl = @rename($tam, $dir . '/' . $hedefAd) ? 'uploads/demir_fatura/' . date('Y/m') . '/' . $hedefAd : null;
            $r = dfat_kaydet($pdoDemir, [
                'fatura_no' => $v['fatura_no'], 'ettn' => $v['ettn'], 'tarih' => $v['tarih'],
                'tedarikci' => $v['satici_unvan'], 'satici_vkn' => $v['satici_vkn'], 'siparis_no' => $v['siparis_no'],
                'irsaliyeler' => $v['irsaliyeler'], 'miktar_kg' => $v['miktar_kg'], 'tutar' => $v['tutar'],
                'brut_tutar' => $v['brut_tutar'], 'dosya_url' => $dosyaUrl, 'notlar' => 'Toplu kontrolden otomatik işlendi',
                'kalemler' => $v['kalemler'],
            ], $ids, $taslakAc);
            $iSonuc[] = ['ad'=>$orjAd, 'no'=>$r['no'], 'durum'=>'kaydedildi', 'kaynak'=>$kyn,
                         'ted'=>$r['ted_ad'], 'ted_yeni'=>$r['ted_yeni'],
                         'ton'=>$v['miktar_kg'] !== null ? $v['miktar_kg'] / 1000 : null, 'tutar'=>$v['tutar'],
                         'irs'=>count($v['irsaliyeler']), 'bagli'=>$r['bagli'], 'taslak'=>$r['taslak'],
                         'eksik'=>$taslakAc ? [] : $e['eksik'], 'baska'=>$baska, 'uyari'=>$r['uyari']];
        } catch (Throwable $eI) {
            $iSonuc[] = ['ad'=>$orjAd, 'durum'=>'hata', 'mesaj'=>$eI->getMessage()];
        }
    }
    $_SESSION['dfat_islem'] = array_merge($_SESSION['dfat_islem'] ?? [], $iSonuc);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'islenen'    => count($iSonuc),
        'kaydedilen' => count(array_filter($iSonuc, fn($r) => $r['durum'] === 'kaydedildi')),
        'zaten'      => count(array_filter($iSonuc, fn($r) => $r['durum'] === 'zaten')),
        'hata'       => count(array_filter($iSonuc, fn($r) => $r['durum'] === 'hata')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$islemSonuc = null;
if (isset($_GET['islem']) && !empty($_SESSION['dfat_islem'])) {
    $islemSonuc = $_SESSION['dfat_islem'];
    unset($_SESSION['dfat_islem']);
}

// ── 2b) Eksik alanı elle düzelt (Kayıtlı Faturalar listesinden) ──────────────
if (($_POST['action'] ?? '') === 'alan_duzelt' && ctype_digit((string)($_POST['fatura_id'] ?? ''))) {
    $fid = (int)$_POST['fatura_id'];
    $alan = (string)($_POST['alan'] ?? '');
    if (!in_array($alan, ['miktar_kg', 'tutar'], true)) { flash('error', 'Geçersiz alan.'); redirect('faturalar.php'); }
    // kg kutusuna "27.680" yazılır (binlik) — fat_sayi onu 27,68 okurdu
    $deger = $alan === 'miktar_kg' ? dfat_kg((string)($_POST['deger'] ?? '')) : fat_sayi((string)($_POST['deger'] ?? ''));
    if ($deger === null || $deger < 0) {
        flash('error', 'Geçerli bir değer girin (örn. 27680 veya 27.680).');
    } else {
        $pdoDemir->prepare("UPDATE demir_faturalar SET {$alan} = ? WHERE id = ?")->execute([$deger, $fid]);
        flash('success', ($alan === 'miktar_kg' ? 'Miktar (kg)' : 'Tutar') . ' elle kaydedildi: ' . number_format($deger, 2, ',', '.'));
    }
    redirect('faturalar.php');
}

// ── 3) Sil ───────────────────────────────────────────────────────────────────
if (is_admin() && isset($_GET['sil']) && ctype_digit((string)$_GET['sil'])) {
    $fid = (int)$_GET['sil'];
    $pdoDemir->prepare("UPDATE demir_sevkiyatlar SET fatura_id = NULL WHERE fatura_id = ?")->execute([$fid]);
    $pdoDemir->prepare("DELETE FROM demir_faturalar WHERE id = ?")->execute([$fid]);
    flash('success', 'Fatura kaydı silindi, sevkiyat bağları çözüldü.');
    redirect('faturalar.php');
}

// ── Liste verisi ─────────────────────────────────────────────────────────────
$kayitli = $pdoDemir->query("SELECT f.*,
        (SELECT COUNT(*) FROM demir_sevkiyatlar s WHERE s.fatura_id = f.id) bagli,
        (SELECT COALESCE(SUM(k.kantar_miktar),0) FROM demir_sevkiyatlar s
            JOIN demir_sevkiyat_kalemleri k ON k.sevkiyat_id = s.id WHERE s.fatura_id = f.id) kantar_ton,
        (SELECT COALESCE(SUM(k.irsaliye_miktar),0) FROM demir_sevkiyatlar s
            JOIN demir_sevkiyat_kalemleri k ON k.sevkiyat_id = s.id WHERE s.fatura_id = f.id) irs_ton
    FROM demir_faturalar f ORDER BY f.tarih DESC, f.id DESC")->fetchAll();
$tedarikciler = $pdoDemir->query("SELECT id, ad FROM demir_tedarikciler ORDER BY ad")->fetchAll();

// Bağlı sevkiyat detayları (açılır içerik) + çap kırılımı + IFS sipariş bağlantısı
$bagliSvk = []; $svkKalem = []; $sipMap = [];
try {
    foreach ($pdoDemir->query("SELECT s.id, s.fatura_id, s.irsaliye_no, s.gelis_tarih, s.irsaliye_tarih, s.arac_plaka,
                                      s.ifs_siparis_no, s.aciklama, p.kod AS proje, t.ad AS tedarikci
                               FROM demir_sevkiyatlar s
                               LEFT JOIN demir_projeler p ON p.id = s.proje_id
                               LEFT JOIN demir_tedarikciler t ON t.id = s.tedarikci_id
                               WHERE s.fatura_id IS NOT NULL") as $r) $bagliSvk[(int)$r['fatura_id']][] = $r;
    foreach ($pdoDemir->query("SELECT k.sevkiyat_id, c.ad AS cap, k.irsaliye_miktar, k.kantar_miktar
                               FROM demir_sevkiyat_kalemleri k JOIN demir_caplar c ON c.id = k.cap_id
                               WHERE k.sevkiyat_id IN (SELECT id FROM demir_sevkiyatlar WHERE fatura_id IS NOT NULL)") as $r)
        $svkKalem[(int)$r['sevkiyat_id']][] = $r;
    foreach ($pdoDemir->query("SELECT id, ifs_siparis_no FROM demir_siparisler WHERE ifs_siparis_no IS NOT NULL AND ifs_siparis_no <> ''") as $r)
        $sipMap[trim((string)$r['ifs_siparis_no'])] = (int)$r['id'];
} catch (Throwable $e0) {}
// İrsaliye no (normalize) → bağlı sevkiyat id (kayıtlı listede rozetleri tıklanır yapmak için)
$irsSvkMap = [];
foreach ($bagliSvk as $fidX => $liste) foreach ($liste as $r) {
    $k = fat_irs_norm((string)$r['irsaliye_no']);
    if ($k !== '') $irsSvkMap[$fidX . '|' . $k] = (int)$r['id'];
}

// ── Fatura arama: no / ETTN / tedarikçi / sipariş no / irsaliye no (NORMALİZE —
//    "3302" ya da "CKI2026-3302" yazınca CKI2026000003302'li faturayı da bulur) ─────────
$fatAra = trim((string)($_GET['fatura_ara'] ?? ''));
if ($fatAra !== '') {
    $araU = mb_strtoupper($fatAra, 'UTF-8');
    $araN = fat_irs_norm($fatAra);
    $araS = preg_replace('/\D/', '', $fatAra);
    $kayitli = array_values(array_filter($kayitli, function ($f) use ($bagliSvk, $araU, $araN, $araS) {
        foreach ([$f['fatura_no'], $f['ettn'], $f['tedarikci'], $f['siparis_no']] as $alan) {
            if ($alan !== null && mb_stripos((string)$alan, $araU) !== false) return true;
        }
        if ($araN !== '' && fat_irs_norm((string)$f['fatura_no']) === $araN) return true;
        $adaylar = (array)(json_decode((string)($f['irsaliye_liste'] ?? ''), true) ?: []);
        foreach ($bagliSvk[(int)$f['id']] ?? [] as $sv) $adaylar[] = (string)$sv['irsaliye_no'];
        foreach ($adaylar as $no) {
            $no = (string)$no;
            if (mb_stripos($no, $araU) !== false) return true;
            if ($araN !== '' && fat_irs_norm($no) === $araN) return true;
            if ($araS !== '' && strlen($araS) >= 3 && str_contains(preg_replace('/\D/', '', $no), $araS)) return true;
        }
        return false;
    }));
}

$pageTitle = 'Demir Fatura Takibi';
require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-1"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Demir Fatura Takibi</h4>
<p class="text-muted small mb-3">Tedarikçi e-faturasındaki irsaliye numarası sevkiyat kayıtlarıyla eşleştirilir; fatura tonajı kantarla karşılaştırılır.</p>
<?php foreach(['success','error','warning','info'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<?php if ($hata): ?><div class="alert alert-danger"><?= h($hata) ?></div><?php endif; ?>
<?php if ($__kgOnarilan): ?><div class="alert alert-info"><i class="bi bi-wrench-adjustable me-1"></i>
    <strong><?= (int)$__kgOnarilan ?> faturanın miktarı düzeltildi</strong> — eski sürüm "26.400 kg" değerini binde bir okuyup 26,4 kg kaydediyordu (listede "0,03 t" görünüyordu).</div><?php endif; ?>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-upload me-1"></i> Fatura Yükle / Metin Yapıştır</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="action" value="cozumle">
            <div class="col-md-5">
                <label class="form-label">e-Fatura dosyası <span class="text-muted">(PDF, JPG — en fazla 20 MB)</span></label>
                <input type="file" name="dosya" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
                <div class="form-text">Demir faturasında irsaliye no <strong>başlıkta</strong> yazar ("İrsaliye No: CKI2026...") — oradan okunur.</div>
            </div>
            <div class="col-md-7">
                <label class="form-label">…veya fatura metnini yapıştırın</label>
                <textarea name="metin" class="form-control" rows="4" placeholder="Fatura No: CKE2026000003032&#10;İrsaliye No: CKI2026000003302&#10;Toplam Miktar 27680 ..."><?= h((string)($_POST['metin'] ?? '')) ?></textarea>
            </div>
            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-search me-1"></i>Çözümle ve Eşleştir</button></div>
        </form>
    </div>
</div>

<!-- TOPLU FATURA KONTROLÜ: dosyalar kaydedilmez, yalnız "işlenmiş mi?" raporu üretilir -->
<div class="card mb-3 border-info">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check me-1 text-info"></i> Toplu Fatura Kontrolü
        <span class="text-muted small fw-normal">— elinize çok sayıda demir faturası mı geldi? Hepsini seçin, hangisi işlenmiş hangisi işlenmemiş tek raporda görün</span></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end" id="kontrolForm">
            <input type="hidden" name="action" value="fatura_kontrol">
            <div class="col-md-7">
                <label class="form-label small">Fatura dosyaları <span class="text-muted">(çoklu seçim — PDF önerilir; işlenmiş olanlar sisteme KAYDEDİLMEZ, yalnız kontrol edilir)</span></label>
                <input type="file" name="dosyalar[]" class="form-control form-control-sm" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" required>
            </div>
            <div class="col-md-3">
                <button class="btn btn-info btn-sm text-white" id="kontrolBtn"><i class="bi bi-search me-1"></i>Kontrol Et</button>
            </div>
            <div class="col-12 d-none" id="kntKutu">
                <div class="progress" style="height:20px"><div class="progress-bar bg-info progress-bar-striped progress-bar-animated" id="kntBar" style="width:0%">0%</div></div>
                <div class="small text-muted mt-1" id="kntDurum"></div>
            </div>
        </form>
        <div class="form-text">Numara önce <strong>dosya adından</strong> okunur (e-fatura dosyaları numarayla adlandırılır: <code>KRD2026000019728.pdf</code>), bulunamazsa PDF içeriğinden çıkarılır; kayıtlı faturalarla <strong>no VEYA ETTN</strong> üzerinden normalize karşılaştırılır. Bu adımda AI kullanılmaz.</div>
    </div>
</div>

<?php if ($kontrolSonuc !== null):
    $kIslenmis   = array_filter($kontrolSonuc, fn($r) => $r['durum'] === 'islenmis');
    $kBagsiz     = array_filter($kIslenmis, fn($r) => (int)$r['bagli'] === 0);
    $kIslenmemis = array_filter($kontrolSonuc, fn($r) => in_array($r['durum'], ['islenmemis','okunamadi'], true));
    $kHata       = array_filter($kontrolSonuc, fn($r) => $r['durum'] === 'hata'); ?>
<div class="card mb-4 border-info">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-data me-1 text-info"></i> Kontrol Raporu
        <span class="badge bg-primary ms-1"><?= count($kontrolSonuc) ?> dosya</span>
        <span class="badge bg-success"><?= count($kIslenmis) ?> işlenmiş</span>
        <?php if ($kBagsiz): ?><span class="badge bg-warning text-dark" title="Kayıtlı ama hiçbir sevkiyata bağlı değil"><?= count($kBagsiz) ?> bağsız</span><?php endif; ?>
        <span class="badge bg-danger"><?= count($kIslenmemis) ?> işlenmemiş</span>
        <?php if ($kHata): ?><span class="badge bg-secondary"><?= count($kHata) ?> hata</span><?php endif; ?></div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:60vh">
        <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
            <thead class="table-light" style="position:sticky;top:0"><tr>
                <th>Dosya</th><th>Fatura No</th><th>Durum</th><th>Sistemdeki Kayıt</th><th>Numara Kaynağı</th>
            </tr></thead>
            <tbody>
            <?php foreach ($kontrolSonuc as $r): ?>
                <tr class="<?= in_array($r['durum'], ['islenmemis','okunamadi'], true) ? 'table-danger' : ($r['durum'] === 'islenmis' ? ((int)$r['bagli'] === 0 ? 'table-warning' : '') : 'table-secondary') ?>">
                    <td class="small"><?= h($r['ad']) ?></td>
                    <td class="font-monospace small"><?= h((string)($r['no'] ?? '—')) ?></td>
                    <td>
                        <?php if ($r['durum'] === 'islenmis'): ?><span class="badge bg-success">✓ işlenmiş</span>
                        <?php elseif ($r['durum'] === 'islenmemis'): ?><span class="badge bg-danger">✗ işlenmemiş</span>
                        <?php elseif ($r['durum'] === 'okunamadi'): ?><span class="badge bg-danger" title="Dosya adında ve PDF metninde numara bulunamadı — ÇÖZÜMLE ve KAYDET içeriği AI ile okur">✗ numara okunamadı</span>
                        <?php else: ?><span class="badge bg-secondary"><?= h($r['mesaj'] ?? 'hata') ?></span><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($r['durum'] === 'islenmis'): ?>
                            <?= h(format_date($r['tarih'])) ?><?= $r['ted'] ? ' · ' . h($r['ted']) : '' ?> ·
                            <?php if ((int)$r['bagli'] > 0): ?><?= (int)$r['bagli'] ?> sevkiyat bağlı
                            <?php else: ?><span class="text-warning-emphasis fw-semibold"><?= (int)$r['irs'] ? 'irsaliyesi sevkiyatlarda yok' : 'irsaliye no okunamamış' ?> — listede <i class="bi bi-arrow-repeat"></i> ile yeniden okuyun</span><?php endif; ?>
                            · <a href="faturalar.php?fatura_ara=<?= urlencode((string)$r['no']) ?>">kaydı aç</a>
                        <?php elseif (in_array($r['durum'], ['islenmemis','okunamadi'], true)): ?>
                            <span class="text-danger">sistemde yok</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= h((string)($r['kaynak'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    <?php if ($kIslenmemis):
        $islenebilir = array_values(array_filter($kIslenmemis, fn($r) => !empty($r['dosya']))); ?>
    <div class="card-footer bg-white small">
        <strong class="text-danger">İşlenmemiş (kopyalanabilir):</strong>
        <code><?= h(implode(', ', array_map(fn($r) => (string)($r['no'] ?? $r['ad']), $kIslenmemis))) ?></code>
        <?php if ($islenebilir): ?>
        <div class="mt-2 pt-2 border-top">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="isleTaslak">
                <label class="form-check-label" for="isleTaslak">Sevkiyatlarda bulunamayan irsaliyeleri <span class="badge bg-secondary">[FATURADAN]</span> <strong>taslak sevkiyat</strong> olarak aç
                    <span class="text-muted">(isteğe bağlı — Excel aktarımı aynı numarayı getirince taslak silinmez, güncellenir)</span></label>
            </div>
            <button type="button" class="btn btn-success btn-sm" id="isleBtn"
                    data-dosyalar="<?= h(json_encode(array_map(fn($r) => ['d' => $r['dosya'], 'a' => $r['ad']], $islenebilir), JSON_UNESCAPED_UNICODE)) ?>">
                <i class="bi bi-cpu me-1"></i>İşlenmemiş <?= count($islenebilir) ?> faturayı ÇÖZÜMLE ve KAYDET
            </button>
            <span class="text-muted ms-2">Her fatura okunur, tedarikçisi VKN/unvandan bulunur (yoksa oluşturulur), irsaliyeleri sevkiyatlara bağlanır. Başka faturaya bağlı sevkiyat taşınmaz — raporlanır.</span>
            <div class="d-none mt-2" id="isleKutu">
                <div class="progress" style="height:20px"><div class="progress-bar bg-success progress-bar-striped progress-bar-animated" id="isleBar" style="width:0%">0%</div></div>
                <div class="small text-muted mt-1" id="isleDurum"></div>
                <div class="small text-danger mt-1"><i class="bi bi-exclamation-circle me-1"></i>PDF metni yoksa AI okur — fatura başına birkaç saniye sürer, sayfayı kapatmayın.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<script>
// İşlenmemişleri otomatik işle: dosyalar sunucuda bekliyor, 2'şerli partilerle çözümlenir (AI ağır iş)
(function () {
    var btn = document.getElementById('isleBtn');
    if (!btn) return;
    btn.addEventListener('click', async function () {
        var dosyalar = JSON.parse(btn.dataset.dosyalar || '[]');
        if (!dosyalar.length) return;
        var taslak = document.getElementById('isleTaslak').checked;
        if (!confirm(dosyalar.length + ' fatura otomatik çözümlenip KAYDEDİLECEK' + (taslak ? ' (eksik irsaliyeler taslak sevkiyat olarak açılacak)' : '') + '. Devam edilsin mi?')) return;
        btn.disabled = true;
        document.getElementById('isleKutu').classList.remove('d-none');
        var bar = document.getElementById('isleBar'), durum = document.getElementById('isleDurum');
        var BATCH = 2, oz = {kaydedilen:0, zaten:0, hata:0};
        var csrfEl = document.querySelector('input[name=csrf]');
        for (var i = 0; i < dosyalar.length; i += BATCH) {
            var fd = new FormData();
            fd.append('action', 'fatura_isle');
            if (taslak) fd.append('taslak', '1');
            if (csrfEl) fd.append('csrf', csrfEl.value);
            dosyalar.slice(i, i + BATCH).forEach(function (d) { fd.append('dosya[]', d.d); fd.append('ad[]', d.a); });
            try {
                var j = await (await fetch('faturalar.php', { method: 'POST', body: fd })).json();
                oz.kaydedilen += j.kaydedilen || 0; oz.zaten += j.zaten || 0; oz.hata += j.hata || 0;
            } catch (err) { oz.hata += Math.min(BATCH, dosyalar.length - i); }
            var y = Math.round(100 * Math.min(i + BATCH, dosyalar.length) / dosyalar.length);
            bar.style.width = y + '%'; bar.textContent = y + '%';
            durum.textContent = Math.min(i + BATCH, dosyalar.length) + '/' + dosyalar.length + ' fatura işlendi — kaydedilen '
                              + oz.kaydedilen + (oz.zaten ? ', zaten işlenmiş ' + oz.zaten : '') + (oz.hata ? ', hata ' + oz.hata : '');
        }
        durum.textContent = 'Tamamlandı — işlem raporu açılıyor…';
        location.href = 'faturalar.php?islem=1';
    });
})();
</script>
<?php endif; ?>

<?php if ($islemSonuc !== null):
    $iKay = array_filter($islemSonuc, fn($r) => $r['durum'] === 'kaydedildi');
    $iZat = array_filter($islemSonuc, fn($r) => $r['durum'] === 'zaten');
    $iHat = array_filter($islemSonuc, fn($r) => $r['durum'] === 'hata');
    $iEksikli = array_filter($iKay, fn($r) => !empty($r['eksik']) || !empty($r['baska']) || (int)($r['irs'] ?? 0) === 0); ?>
<div class="card mb-4 border-success">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-cpu me-1 text-success"></i> Otomatik İşleme Raporu
        <span class="badge bg-success"><?= count($iKay) ?> kaydedildi</span>
        <?php if ($iZat): ?><span class="badge bg-secondary"><?= count($iZat) ?> zaten işlenmişti</span><?php endif; ?>
        <?php if ($iHat): ?><span class="badge bg-danger"><?= count($iHat) ?> işlenemedi</span><?php endif; ?></div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:60vh">
        <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
            <thead class="table-light" style="position:sticky;top:0"><tr>
                <th>Fatura No</th><th>Durum</th><th>Tedarikçi</th><th class="text-end">Ton</th><th class="text-end">Tutar</th>
                <th class="text-end">İrsaliye</th><th class="text-end">Bağlanan Svk.</th><th>Bulunamayan / not</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($islemSonuc as $r): ?>
                <tr class="<?= $r['durum'] === 'hata' ? 'table-danger' : ($r['durum'] === 'zaten' ? 'table-secondary' : '') ?>">
                    <td class="font-monospace small"><?= h((string)($r['no'] ?? $r['ad'])) ?></td>
                    <td>
                        <?php if ($r['durum'] === 'kaydedildi'): ?><span class="badge bg-success">✓ kaydedildi</span>
                        <?php elseif ($r['durum'] === 'zaten'): ?><span class="badge bg-secondary">zaten işlenmiş</span>
                        <?php else: ?><span class="badge bg-danger">✗ işlenemedi</span> <span class="small text-danger"><?= h((string)($r['mesaj'] ?? '')) ?></span><?php endif; ?>
                    </td>
                    <td class="small"><?= h((string)($r['ted'] ?? '')) ?><?= !empty($r['ted_yeni']) ? ' <span class="badge bg-info text-dark">yeni oluşturuldu</span>' : '' ?></td>
                    <td class="text-end font-monospace"><?= isset($r['ton']) && $r['ton'] !== null ? number_format($r['ton'], 3, ',', '.') : '—' ?></td>
                    <td class="text-end font-monospace"><?= isset($r['tutar']) && $r['tutar'] !== null ? $fmt($r['tutar']) . ' ₺' : '—' ?></td>
                    <td class="text-end"><?= isset($r['irs']) ? (int)$r['irs'] : '—' ?></td>
                    <td class="text-end <?= isset($r['bagli']) && (int)$r['bagli'] === 0 ? 'text-danger fw-bold' : '' ?>"><?= isset($r['bagli']) ? (int)$r['bagli'] : '—' ?>
                        <?= !empty($r['taslak']) ? ' <span class="badge bg-warning text-dark" title="taslak sevkiyat açıldı">+' . (int)$r['taslak'] . ' taslak</span>' : '' ?></td>
                    <td class="small">
                        <?php foreach ((array)($r['eksik'] ?? []) as $n): ?><span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1" title="sevkiyatlarda yok"><?= h($n) ?></span><?php endforeach; ?>
                        <?php foreach ((array)($r['baska'] ?? []) as $n): ?><span class="badge bg-warning-subtle text-warning-emphasis border me-1" title="başka faturaya bağlı — taşınmadı"><?= h($n) ?> · başka faturada</span><?php endforeach; ?>
                        <?php if ($r['durum'] === 'kaydedildi' && (int)($r['irs'] ?? 0) === 0): ?><span class="text-danger">irsaliye no okunamadı</span><?php endif; ?>
                        <?php if (!empty($r['uyari'])): ?><div class="text-warning-emphasis"><?= h($r['uyari']) ?></div><?php endif; ?>
                    </td>
                    <td><?php if (!empty($r['no'])): ?><a href="faturalar.php?fatura_ara=<?= urlencode((string)$r['no']) ?>" class="small">kaydı aç</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    <?php if ($iEksikli): ?>
    <div class="card-footer bg-white small">
        <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
        <strong><?= count($iEksikli) ?> faturanın</strong> irsaliyesi sevkiyata bağlanamadı. Sevkiyat henüz girilmemişse girildikten sonra
        listede <i class="bi bi-arrow-repeat"></i> <strong>yeniden oku</strong> ile bağlayın, ya da faturayı tekil yükleyip taslak sevkiyat kutusunu kullanın.
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Toplu kontrol: büyük seçimler 15'erli partilerle gönderilir (PHP max_file_uploads sınırına takılmasın)
document.getElementById('kontrolForm').addEventListener('submit', async function (e) {
    var form = this;
    var files = Array.from(form.querySelector('input[type=file]').files || []);
    if (files.length <= 15) return;
    e.preventDefault();
    var BATCH = 15;
    document.getElementById('kontrolBtn').disabled = true;
    document.getElementById('kntKutu').classList.remove('d-none');
    var bar = document.getElementById('kntBar'), durum = document.getElementById('kntDurum');
    var toplam = files.length, oz = {islenmis:0, islenmemis:0, diger:0};
    for (var i = 0; i < toplam; i += BATCH) {
        var fd = new FormData();
        fd.append('action', 'fatura_kontrol'); fd.append('ajax', '1');
        var csrf = form.querySelector('input[name=csrf]'); if (csrf) fd.append('csrf', csrf.value);
        files.slice(i, i + BATCH).forEach(function (f) { fd.append('dosyalar[]', f); });
        try {
            var j = await (await fetch('faturalar.php', { method: 'POST', body: fd })).json();
            oz.islenmis += j.islenmis || 0; oz.islenmemis += j.islenmemis || 0; oz.diger += j.diger || 0;
        } catch (err) { oz.diger += Math.min(BATCH, toplam - i); }
        var y = Math.round(100 * Math.min(i + BATCH, toplam) / toplam);
        bar.style.width = y + '%'; bar.textContent = y + '%';
        durum.textContent = Math.min(i + BATCH, toplam) + '/' + toplam + ' kontrol edildi — işlenmiş ' + oz.islenmis + ', işlenmemiş ' + oz.islenmemis + (oz.diger ? ', diğer ' + oz.diger : '');
    }
    location.href = 'faturalar.php?kontrol=1';
});
</script>

<?php if ($sonuc): $v = $sonuc['v']; $e = $sonuc['e']; $ton = $v['miktar_kg'] !== null ? $v['miktar_kg']/1000 : null; ?>

<?php $bosAlan = array_keys(array_filter(['Fatura No'=>$v['fatura_no']===null, 'Tarih'=>$v['tarih']===null,
      'ETTN'=>$v['ettn']===null, 'Tutar'=>$v['tutar']===null, 'Miktar'=>$v['miktar_kg']===null, 'İrsaliye'=>!$v['irsaliyeler']])); ?>
<?php if ($bosAlan): ?>
<div class="alert alert-warning py-2 small">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    Şu alanlar metinden okunamadı: <strong><?= h(implode(', ', $bosAlan)) ?></strong>
    <?= ($sonuc['kaynak'] ?? '') === 'ai' ? ' (belge AI ile okundu — aşağıdaki "okunan ham metin"e bakıp eksikleri elle tamamlayın)' : '' ?>.
</div>
<?php endif; ?>
<details class="mb-3">
    <summary class="small text-muted"><i class="bi bi-bug me-1"></i>Okunan ham metin
        (<?= ($sonuc['kaynak'] ?? null) === 'ai' ? 'AI ile okundu' : (($sonuc['kaynak'] ?? null) === 'pdftotext' ? 'pdftotext' : 'yapıştırılan metin') ?>) — sorun ayıklama</summary>
    <pre class="small bg-body-tertiary p-2 border rounded mt-1" style="max-height:280px;overflow:auto;white-space:pre-wrap"><?= h(mb_substr((string)$sonuc['metin'], 0, 8000)) ?></pre>
</details>

<?php if ($sonuc['mevcut']): ?>
<div class="alert alert-warning"><i class="bi bi-files me-1"></i>
    <strong>Bu fatura sistemde zaten kayıtlı</strong> (<?= h($sonuc['mevcut']['fatura_no']) ?> · <?= h(format_date($sonuc['mevcut']['tarih'])) ?>).
    Kaydederseniz mevcut kayıt <strong>güncellenir</strong>, yeni kayıt açılmaz.
</div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="action" value="kaydet">
<input type="hidden" name="dosya_url" value="<?= h((string)$sonuc['dosya_url']) ?>">
<input type="hidden" name="irsaliye_liste" value="<?= h(implode(',', $v['irsaliyeler'])) ?>">
<input type="hidden" name="brut_tutar" value="<?= $v['brut_tutar'] !== null ? h((string)$v['brut_tutar']) : '' ?>">
<input type="hidden" name="kalemler" value="<?= h(json_encode($v['kalemler'] ?? [], JSON_UNESCAPED_UNICODE)) ?>">

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text me-1"></i> Fatura Bilgileri</div>
    <div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Fatura No</label>
            <input name="fatura_no" class="form-control" required value="<?= h((string)$v['fatura_no']) ?>"></div>
        <div class="col-md-2"><label class="form-label">Tarih</label>
            <input type="date" name="tarih" class="form-control" value="<?= h((string)$v['tarih']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Tedarikçi</label>
            <select name="tedarikci_id" class="form-select">
                <option value="">— seçiniz —</option>
                <?php foreach ($tedarikciler as $td): ?>
                <option value="<?= (int)$td['id'] ?>" <?= (int)$sonuc['oner_ted']===(int)$td['id']?'selected':'' ?>><?= h($td['ad']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($v['satici_unvan']): ?><div class="form-text">Faturadaki satıcı: <?= h($v['satici_unvan']) ?><?= $v['satici_vkn'] ? ' (VKN '.h($v['satici_vkn']).')' : '' ?></div><?php endif; ?>
            <input type="hidden" name="tedarikci" value="<?= h((string)$v['satici_unvan']) ?>">
            <input type="hidden" name="satici_vkn" value="<?= h((string)$v['satici_vkn']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Sipariş No <span class="text-muted small">(tedarikçinin)</span></label>
            <input name="siparis_no" class="form-control" value="<?= h((string)$v['siparis_no']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Miktar (kg)</label>
            <input name="miktar_kg" class="form-control" value="<?= $v['miktar_kg']!==null ? $fmt0($v['miktar_kg']) : '' ?>">
            <?php if ($ton !== null): ?><div class="form-text">= <?= $fmt($ton) ?> ton</div><?php endif; ?></div>
        <div class="col-md-3"><label class="form-label">Ödenecek Tutar</label>
            <input name="tutar" class="form-control" value="<?= $v['tutar']!==null ? $fmt($v['tutar']) : '' ?>"></div>
        <div class="col-md-6"><label class="form-label">ETTN</label>
            <input name="ettn" class="form-control" value="<?= h((string)$v['ettn']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Not</label>
            <input name="notlar" class="form-control" placeholder="opsiyonel"></div>
        <?php if ($v['caplar']): ?>
        <div class="col-md-4"><label class="form-label">Faturadaki Çaplar</label><div>
            <?php foreach ($v['caplar'] as $c): ?><span class="badge bg-secondary me-1"><?= h($c) ?></span><?php endforeach; ?>
        </div></div>
        <?php endif; ?>
        <?php if ($v['belge_nolar']): ?>
        <div class="col-md-4"><label class="form-label">Belge / Kantar Fiş No</label><div>
            <?php foreach ($v['belge_nolar'] as $c): ?><span class="badge bg-info-subtle text-info-emphasis border me-1"><?= h($c) ?></span><?php endforeach; ?>
        </div></div>
        <?php endif; ?>
        <?php if ($v['sevkiyat_nolar']): ?>
        <div class="col-md-4"><label class="form-label">Tedarikçi Sevkiyat No</label><div class="small text-muted">
            <?= h(implode(', ', $v['sevkiyat_nolar'])) ?>
        </div></div>
        <?php endif; ?>
        <?php if (!empty($v['kalemler'])): ?>
        <div class="col-md-6"><label class="form-label">Fatura Kalemleri <span class="text-muted small">(çözülen — taslak sevkiyata işlenir)</span></label>
            <table class="table table-sm table-bordered mb-0" style="max-width:420px">
                <thead class="table-light"><tr><th>Çap</th><th class="text-end">Miktar (t)</th><th>İrsaliye</th></tr></thead>
                <tbody>
                <?php foreach ($v['kalemler'] as $kl): ?>
                    <tr><td><?= $kl['cap'] !== null ? 'Ø'.(int)$kl['cap'] : '<span class="text-muted">?</span>' ?></td>
                        <td class="text-end font-monospace"><?= $fmt(((float)$kl['kg'])/1000) ?></td>
                        <td class="small"><code><?= h((string)($kl['irs'] ?? '')) ?></code></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold"><tr><td>Toplam</td>
                    <td class="text-end font-monospace"><?= $fmt(array_sum(array_column($v['kalemler'],'kg'))/1000) ?></td><td></td></tr></tfoot>
            </table>
        </div>
        <?php endif; ?>
        <?php if ($v['brut_tutar'] !== null && $v['tutar'] !== null && abs($v['brut_tutar']-$v['tutar'])>0.01): ?>
        <div class="col-12"><div class="alert alert-secondary py-2 small mb-0">
            Tevkifatlı fatura: Vergiler Dahil <strong><?= $fmt($v['brut_tutar']) ?> ₺</strong>, Ödenecek <strong><?= $fmt($v['tutar']) ?> ₺</strong>.
        </div></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3 <?= $e['eslesen'] ? 'border-success' : '' ?>">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-link-45deg me-1"></i> Eşleşen Sevkiyatlar (<?= count($e['eslesen']) ?>)</div>
    <div class="card-body p-0">
    <?php if (!$e['eslesen'] && !$e['eksik']): ?>
        <div class="p-3 text-muted">Faturada başlıkta "İrsaliye No" alanı bulunamadı.</div>
    <?php elseif (!$e['eslesen']): ?>
        <div class="p-3 text-muted">Faturadaki irsaliye numarası sevkiyat kayıtlarında bulunamadı.</div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th></th><th>İrsaliye No</th><th>Geliş</th><th>Tedarikçi</th><th>Proje</th><th>Plaka</th>
                <th class="text-end">İrsaliye (t)</th><th class="text-end">Kantar (t)</th><th class="text-end">Fatura ile Fark</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($e['eslesen'] as $r):
                $svkTon = (float)($r['kantar_ton'] ?: $r['irs_ton']);
                $fark = ($ton !== null && count($e['eslesen']) === 1) ? $svkTon - $ton : null; ?>
                <tr class="<?= !empty($r['fatura_id']) ? 'table-warning' : '' ?>">
                    <td><input type="checkbox" name="svk_id[]" value="<?= (int)$r['id'] ?>" checked class="form-check-input"></td>
                    <td><code><?= h($r['irsaliye_no'] ?: $r['kantar_fis_no']) ?></code>
                        <?php if (($r['eslesme_anahtari'] ?? '') === 'kantar fişi'): ?><span class="badge bg-info text-dark" title="Faturadaki [BELGE NO] sevkiyatın kantar fiş numarasıyla eşleşti">kantar fişi</span><?php endif; ?>
                        <?php if (!empty($r['fatura_id'])): ?><span class="badge bg-warning text-dark" title="Bu sevkiyat zaten bir faturaya bağlı — kaydederseniz bu faturaya taşınır">bağlı</span><?php endif; ?></td>
                    <td><?= h(format_date($r['gelis_tarih'] ?: $r['irsaliye_tarih'])) ?></td>
                    <td><?= h((string)$r['tedarikci']) ?></td>
                    <td><?= h((string)$r['proje']) ?></td>
                    <td class="font-monospace small"><?= h((string)$r['arac_plaka']) ?></td>
                    <td class="text-end font-monospace"><?= $fmt($r['irs_ton']) ?></td>
                    <td class="text-end font-monospace"><?= $fmt($r['kantar_ton']) ?></td>
                    <td class="text-end font-monospace <?= $fark===null ? 'text-muted' : (abs($fark) <= 0.05 ? 'text-success' : 'text-danger fw-bold') ?>">
                        <?= $fark === null ? '—' : $fmt($fark) . ' t' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary py-0" target="_blank" href="sevkiyat_form.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-box-arrow-up-right"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <?php if ($e['eksik']): ?>
        <div class="p-3 border-top">
            <div class="text-danger fw-semibold small mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Sevkiyatlarda bulunamayan irsaliye numaraları:</div>
            <?php foreach ($e['eksik'] as $n): ?><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= h($n) ?></span> <?php endforeach; ?>
            <div class="small text-muted mt-1">Sevkiyat henüz girilmemiş olabilir — girildikten sonra faturayı yeniden çözümleyin.</div>
            <div class="form-check mt-3 p-3 pt-2 ps-5 bg-warning-subtle border border-warning rounded">
                <input class="form-check-input" type="checkbox" name="eksik_olustur" value="1" id="eksikOlustur">
                <label class="form-check-label" for="eksikOlustur">
                    <strong>Bu <?= count($e['eksik']) ?> irsaliyeyi taslak sevkiyat olarak oluştur ve faturaya bağla</strong> <span class="text-muted">(isteğe bağlı)</span>
                    <div class="small text-muted mt-1">
                        Taslaklar fatura tarihi + tedarikçisiyle ve <span class="badge bg-secondary">[FATURADAN]</span> notuyla açılır;
                        faturadan çözülen çap/kg kalemleri işlenir. Excel aktarımı aynı numarayı getirdiğinde taslak
                        <strong>silinmez, gerçek verilerle güncellenir</strong> — mükerrer kayıt oluşmaz. Tedarikçi seçili değilse
                        faturadaki satıcıyla otomatik oluşturulur.
                    </div>
                </label>
            </div>
        </div>
    <?php endif; ?>
    </div>
</div>

<div class="mb-4">
    <button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $sonuc['mevcut'] ? 'Faturayı Güncelle' : 'Faturayı Kaydet' ?> ve Sevkiyatları Bağla</button>
    <a href="faturalar.php" class="btn btn-outline-secondary">Vazgeç</a>
</div>
</form>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-archive me-1"></i> Kayıtlı Faturalar</span>
        <form method="get" class="d-flex align-items-center gap-1">
            <input type="text" name="fatura_ara" value="<?= h($fatAra) ?>" class="form-control form-control-sm" style="width:230px"
                   placeholder="Fatura no / irsaliye no / tedarikçi">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
            <?php if ($fatAra !== ''): ?><a href="faturalar.php" class="btn btn-sm btn-outline-secondary" title="Aramayı temizle"><i class="bi bi-x-lg"></i></a><?php endif; ?>
        </form>
        <span class="badge bg-primary" title="<?= $fatAra !== '' ? 'Arama sonucu' : 'Toplam fatura sayısı' ?>"><?= count($kayitli) ?> fatura
            <?php $topKg = array_sum(array_map(fn($x)=>(float)$x['miktar_kg'], $kayitli)); ?>
            · <?= $fmt($topKg/1000) ?> t
            · <?= $fmt(array_sum(array_map(fn($x)=>(float)$x['tutar'], $kayitli))) ?> ₺</span>
    </div>
    <div class="card-body p-0">
    <?php if (!$kayitli): ?>
        <div class="p-3 text-muted"><?= $fatAra !== '' ? '"' . h($fatAra) . '" ile eşleşen fatura bulunamadı.' : 'Henüz kayıtlı demir faturası yok.' ?></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th class="text-center">#</th>
                <th>Fatura No</th><th>Tarih</th><th>Tedarikçi</th><th>İrsaliye</th>
                <th class="text-end">Tutar</th><th class="text-end">Fatura (t)</th><th class="text-end">Kantar (t)</th>
                <th class="text-end">Fark</th><th class="text-center">Bağlı Svk.</th><th>Dosya</th><th></th>
            </tr></thead>
            <tbody>
            <?php $sira = count($kayitli); foreach ($kayitli as $f):
                $fTon = $f['miktar_kg'] !== null ? (float)$f['miktar_kg']/1000 : null;
                $kTon = (float)($f['kantar_ton'] ?: $f['irs_ton']);
                $fark = ($fTon !== null && (int)$f['bagli'] > 0) ? $kTon - $fTon : null;
                $irsL = json_decode((string)$f['irsaliye_liste'], true) ?: [];
                $fKalem = json_decode((string)($f['kalemler'] ?? ''), true) ?: [];
                $fSvk = $bagliSvk[(int)$f['id']] ?? []; ?>
                <tr>
                    <td class="text-center text-muted"><?= $sira-- ?></td>
                    <td><code><?= h($f['fatura_no']) ?></code></td>
                    <td class="text-nowrap"><?= h(format_date($f['tarih'])) ?></td>
                    <td class="small"><?= h((string)$f['tedarikci']) ?></td>
                    <td class="small">
                        <?php foreach ($irsL as $n): $svkId = $irsSvkMap[(int)$f['id'] . '|' . fat_irs_norm($n)] ?? 0; ?>
                            <?php if ($svkId): ?>
                            <a href="sevkiyat_form.php?id=<?= $svkId ?>" target="_blank" class="text-decoration-none" title="Sevkiyatı aç"><code class="me-1"><?= h($n) ?></code></a>
                            <?php else: ?><code class="me-1 text-muted" title="Sevkiyat bağlı değil"><?= h($n) ?></code><?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($fSvk || $fKalem): ?>
                        <button class="btn btn-sm btn-link p-0 text-decoration-none align-baseline" type="button"
                                data-bs-toggle="collapse" data-bs-target="#df<?= (int)$f['id'] ?>" title="Fatura içeriği + bağlı sevkiyatlar">
                            <i class="bi bi-chevron-down small"></i> içerik
                        </button>
                        <?php endif; ?>
                    </td>
                    <td class="text-end font-monospace">
                        <?php if ($f['tutar'] !== null): ?><?= $fmt($f['tutar']) ?> ₺
                        <?php else: ?>
                        <form method="post" class="d-inline-flex align-items-center gap-1" title="Ödenecek tutarı elle girin">
                            <input type="hidden" name="action" value="alan_duzelt"><input type="hidden" name="alan" value="tutar">
                            <input type="hidden" name="fatura_id" value="<?= (int)$f['id'] ?>">
                            <input type="text" name="deger" class="form-control form-control-sm py-0 px-1 text-end" style="width:90px" placeholder="₺" inputmode="decimal">
                            <button class="btn btn-sm btn-outline-success py-0"><i class="bi bi-check-lg"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td class="text-end font-monospace">
                        <?php if ($fTon !== null): ?><?= $fmt($fTon) ?>
                        <?php else: ?>
                        <form method="post" class="d-inline-flex align-items-center gap-1" title="Fatura miktarını KG olarak elle girin">
                            <input type="hidden" name="action" value="alan_duzelt"><input type="hidden" name="alan" value="miktar_kg">
                            <input type="hidden" name="fatura_id" value="<?= (int)$f['id'] ?>">
                            <input type="text" name="deger" class="form-control form-control-sm py-0 px-1 text-end" style="width:80px" placeholder="kg" inputmode="decimal">
                            <button class="btn btn-sm btn-outline-success py-0"><i class="bi bi-check-lg"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td class="text-end font-monospace"><?= (int)$f['bagli'] ? $fmt($kTon) : '—' ?></td>
                    <td class="text-end font-monospace <?= $fark===null ? 'text-muted' : (abs($fark)<=0.05 ? 'text-success' : 'text-danger fw-bold') ?>">
                        <?= $fark===null ? '—' : $fmt($fark) ?></td>
                    <td class="text-center"><span class="badge <?= (int)$f['bagli'] ? 'bg-success' : 'bg-danger' ?>"><?= (int)$f['bagli'] ?></span></td>
                    <td><?php if ($f['dosya_url']): ?><a href="../<?= h($f['dosya_url']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($f['dosya_url']): ?>
                        <form method="post" class="d-inline" onsubmit="this.querySelector('button').disabled=true">
                            <input type="hidden" name="action" value="yeniden_oku"><input type="hidden" name="fatura_id" value="<?= (int)$f['id'] ?>">
                            <input type="hidden" name="fatura_ara" value="<?= h($fatAra) ?>">
                            <button class="btn btn-sm <?= (int)$f['bagli'] ? 'btn-outline-secondary' : 'btn-outline-primary' ?> py-0" title="Dosyadan yeniden oku: irsaliye no, kg ve tedarikçi yeniden çözülür, eşleşen sevkiyatlar bağlanır"><i class="bi bi-arrow-repeat"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php if (is_admin()): ?>
                        <a class="btn btn-sm btn-outline-danger py-0" href="?sil=<?= (int)$f['id'] ?>" onclick="return confirm('Fatura kaydı silinsin, sevkiyat bağları çözülsün mü?')"><i class="bi bi-trash"></i></a>
                    <?php endif; ?></td>
                </tr>
                <?php if ($fSvk || $fKalem): ?>
                <tr class="collapse" id="df<?= (int)$f['id'] ?>">
                    <td colspan="12" class="bg-body-tertiary">
                        <div class="row g-3 p-2">
                            <?php if ($fKalem): ?>
                            <div class="col-lg-4">
                                <div class="small fw-semibold mb-1"><i class="bi bi-list-ul me-1"></i>Fatura kalemleri</div>
                                <table class="table table-sm table-bordered bg-body mb-0">
                                    <thead class="table-light"><tr><th>Çap</th><th class="text-end">Miktar (t)</th><th>İrsaliye</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($fKalem as $kl): ?>
                                        <tr><td><?= $kl['cap'] !== null ? 'Ø'.(int)$kl['cap'] : '<span class="text-muted">—</span>' ?></td>
                                            <td class="text-end font-monospace"><?= $fmt(((float)($kl['kg'] ?? 0))/1000) ?></td>
                                            <td class="small"><code><?= h((string)($kl['irs'] ?? '')) ?></code></td></tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            <?php if ($fSvk): ?>
                            <div class="col-lg-8">
                                <div class="small fw-semibold mb-1"><i class="bi bi-truck me-1"></i>Bağlı sevkiyatlar</div>
                                <table class="table table-sm table-bordered bg-body mb-0">
                                    <thead class="table-light"><tr>
                                        <th>İrsaliye No</th><th>Geliş</th><th>Proje</th><th>IFS Sipariş</th><th>Çap kırılımı (irsaliye / kantar t)</th><th></th>
                                    </tr></thead>
                                    <tbody>
                                    <?php foreach ($fSvk as $sv): $taslakMi = str_contains((string)$sv['aciklama'], '[FATURADAN]'); ?>
                                        <tr>
                                            <td><code><?= h($sv['irsaliye_no']) ?></code>
                                                <?php if ($taslakMi): ?><span class="badge bg-warning text-dark" title="Faturadan açılmış taslak — Excel aktarımında gerçek verilerle güncellenir">TASLAK</span><?php endif; ?></td>
                                            <td class="text-nowrap"><?= h(format_date($sv['gelis_tarih'] ?: $sv['irsaliye_tarih'])) ?></td>
                                            <td><?= h((string)$sv['proje']) ?></td>
                                            <td>
                                                <?php $sipId = $sipMap[trim((string)$sv['ifs_siparis_no'])] ?? 0; ?>
                                                <?php if ($sipId): ?><a href="siparis_detay.php?id=<?= $sipId ?>" target="_blank"><?= h($sv['ifs_siparis_no']) ?></a>
                                                <?php else: ?><span class="text-muted"><?= h((string)$sv['ifs_siparis_no'] ?: '—') ?></span><?php endif; ?>
                                            </td>
                                            <td class="small">
                                                <?php foreach ($svkKalem[(int)$sv['id']] ?? [] as $sk): ?>
                                                    <span class="badge bg-secondary me-1"><?= h($sk['cap']) ?>: <?= $fmt($sk['irsaliye_miktar']) ?><?= $sk['kantar_miktar'] !== null ? ' / '.$fmt($sk['kantar_miktar']) : '' ?></span>
                                                <?php endforeach; ?>
                                            </td>
                                            <td class="text-end"><a class="btn btn-sm btn-outline-secondary py-0" target="_blank" href="sevkiyat_form.php?id=<?= (int)$sv['id'] ?>"><i class="bi bi-box-arrow-up-right"></i> aç</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
