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
}
dfat_semasi_kur($pdoDemir);

// ── Demir faturası metin çözümleme ───────────────────────────────────────────
// Tedarikçiden tedarikçiye 3 farklı irsaliye gösterimi görüldü (gerçek faturalardan):
//   Çakıroğlu : başlıkta "İrsaliye No: CKI2026000003302" (etiketle değer ayrı satıra düşebilir)
//   Ali Cangül: kalemde  "[İRS.NO:2124-08.05.2026]"  (DÜZ SAYI numara + tarih)
//   Cangül    : kalemde  "[E-IRSALIYE NO TARIH:13.08.2026-CNG2026000020738]" + "[BELGE NO:21111]"
// Gövdedeki rulo/bağ kodları (CA0217734...) BİLEREK taranmaz — yalnız etiketli alanlar okunur.
function dfat_cikar(string $metin): array {
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
    if (preg_match_all('/İrsaliye\s*No(?:su)?[:\s]*([A-Z]{2,6}\d{6,20}(?:\s*,\s*[A-Z]{2,6}\d{6,20})*)/iu', $t, $mm)) {
        foreach ($mm[1] as $grup) foreach (preg_split('/\s*,\s*/', $grup) as $no) $ekle($no);
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
    if (preg_match('/Toplam\s*M[İIiı]ktar[:\s]*([\d.,]+)/iu', $t, $m)) $d['miktar_kg'] = fat_sayi($m[1]);
    if ($d['miktar_kg'] === null && preg_match_all('/([\d.,]+,\d{2})\s*kg\.?\b/iu', $t, $mm)) {
        $top = 0.0; $var = false;
        foreach ($mm[1] as $v) { $f = fat_sayi($v); if ($f !== null) { $top += $f; $var = true; } }
        if ($var) $d['miktar_kg'] = $top;
    }
    if ($d['miktar_kg'] === null && preg_match_all('/([\d.,]+)\s*Kg\.?\b/u', $t, $mm)) {   // Çakıroğlu: "10.800\n Kg."
        $top = 0.0; $var = false;
        foreach ($mm[1] as $v) { $f = fat_sayi($v); if ($f !== null) { $top += $f; $var = true; } }
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
            // AI okumada demire özgü talimat: irsaliye/belge etiketleri tedarikçiye göre değişir
            $aiSistem = 'Sen bir e-Fatura okuyucusun. Verilen DEMİR faturasındaki METNİ olduğu gibi düz metin '
                      . 'olarak yaz. Hiçbir şey uydurma, yorum ekleme, özetleme. Şu satırları MUTLAKA aynen aktar: '
                      . 'Fatura No, Fatura Tarihi, ETTN, Sipariş No, başlıktaki "İrsaliye No" alanı, kalemlerdeki '
                      . '"[İRS.NO:...]" ve "[E-IRSALIYE NO TARIH:...]" ve "[BELGE NO:...]" köşeli parantezli etiketlerin '
                      . 'TAMAMI, kalem tablosundaki malzeme adları ve Miktar değerleri BİRİMİYLE (örn. "27.680 Kg"), '
                      . '"Toplam Miktar", "Vergiler Dahil Toplam Tutar" ve "Ödenecek Tutar" satırları, satıcı ve alıcı '
                      . 'VKN satırları. Rulo/bağ kodu listelerini (CA02..., FD03... gibi) aktarmana gerek YOK.';
            $okunan = fat_dosyadan_metin($tam, $mime, $kaynakOkuma, $aiSistem);
            if ($okunan !== null && $okunan !== '') $metin = $okunan;
            elseif ($metin === '') $hata = 'Dosyadan metin okunamadı — fatura metnini kopyalayıp kutuya yapıştırın.';
        }
    }
    if (!$hata) {
        if ($metin === '') $hata = 'Fatura dosyası yükleyin veya metnini yapıştırın.';
        else {
            $v = dfat_cikar($metin);
            $e = dfat_eslestir($pdoDemir, $v['irsaliyeler'], $v['belge_nolar']);
            $mevcut = null;
            $mq = $pdoDemir->prepare("SELECT * FROM demir_faturalar WHERE fatura_no = ? OR (ettn IS NOT NULL AND ettn <> '' AND ettn = ?) LIMIT 1");
            $mq->execute([(string)$v['fatura_no'], (string)$v['ettn']]);
            $mevcut = $mq->fetch() ?: null;
            // Tedarikçi önerisi: normalize unvan ile demir_tedarikciler
            $onerTedId = 0;
            if ($v['satici_unvan']) {
                $u = fat_unvan_norm($v['satici_unvan']);
                foreach ($pdoDemir->query("SELECT id, ad FROM demir_tedarikciler") as $td) {
                    $a = fat_unvan_norm($td['ad']);
                    if ($a !== '' && mb_strlen($a) >= 4 && (str_contains($u, $a) || str_contains($a, $u))) { $onerTedId = (int)$td['id']; break; }
                }
            }
            $sonuc = ['v'=>$v, 'e'=>$e, 'dosya_url'=>$dosyaUrl, 'mevcut'=>$mevcut, 'oner_ted'=>$onerTedId];
        }
    }
}

// ── 2) Kaydet ────────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'kaydet') {
    try {
        $faturaNo = strtoupper(trim((string)($_POST['fatura_no'] ?? '')));
        if ($faturaNo === '') throw new RuntimeException('Fatura no zorunlu.');
        $ettn = strtoupper(trim((string)($_POST['ettn'] ?? ''))) ?: null;
        $tedId = (int)($_POST['tedarikci_id'] ?? 0) ?: null;
        $tedAd = trim((string)($_POST['tedarikci'] ?? '')) ?: null;
        if ($tedId) { $tq = $pdoDemir->prepare("SELECT ad FROM demir_tedarikciler WHERE id=?"); $tq->execute([$tedId]); $tedAd = $tq->fetchColumn() ?: $tedAd; }
        $irsListe = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['irsaliye_liste'] ?? '')))));

        $mq = $pdoDemir->prepare("SELECT id FROM demir_faturalar WHERE fatura_no = ? OR (ettn IS NOT NULL AND ettn <> '' AND ? <> '' AND ettn = ?) LIMIT 1");
        $mq->execute([$faturaNo, (string)$ettn, (string)$ettn]);
        $fid = (int)($mq->fetchColumn() ?: 0);

        $par = [$ettn, fat_tarih_norm($_POST['tarih'] ?? '') ?: null, $tedAd, $tedId,
                trim((string)($_POST['siparis_no'] ?? '')) ?: null,
                $irsListe ? json_encode($irsListe, JSON_UNESCAPED_UNICODE) : null,
                fat_sayi((string)($_POST['miktar_kg'] ?? '')), fat_sayi((string)($_POST['tutar'] ?? '')),
                fat_sayi((string)($_POST['brut_tutar'] ?? '')),
                trim((string)($_POST['dosya_url'] ?? '')) ?: null,
                trim((string)($_POST['notlar'] ?? '')) ?: null];
        if ($fid) {
            $pdoDemir->prepare("UPDATE demir_faturalar SET ettn=?, tarih=?, tedarikci=?, tedarikci_id=?, siparis_no=?,
                irsaliye_liste=?, miktar_kg=?, tutar=?, brut_tutar=?, dosya_url=COALESCE(?, dosya_url), notlar=? WHERE id=?")
                ->execute([...$par, $fid]);
        } else {
            $pdoDemir->prepare("INSERT INTO demir_faturalar (fatura_no, ettn, tarih, tedarikci, tedarikci_id, siparis_no,
                irsaliye_liste, miktar_kg, tutar, brut_tutar, dosya_url, notlar, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$faturaNo, ...$par, current_user_id()]);
            $fid = (int)$pdoDemir->lastInsertId();
        }
        // Sevkiyat bağları
        $svkIds = array_values(array_filter(array_map('intval', (array)($_POST['svk_id'] ?? []))));
        $bagli = 0;
        if ($svkIds) {
            $upd = $pdoDemir->prepare("UPDATE demir_sevkiyatlar SET fatura_id = ? WHERE id = ?");
            foreach ($svkIds as $sid) { $upd->execute([$fid, $sid]); $bagli++; }
        }
        flash('success', "Fatura {$faturaNo} kaydedildi" . ($bagli ? ", {$bagli} sevkiyata bağlandı" : "") . ".");
    } catch (Throwable $eK) { flash('error', 'Kayıt hatası: ' . $eK->getMessage()); }
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

$pageTitle = 'Demir Fatura Takibi';
require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-1"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Demir Fatura Takibi</h4>
<p class="text-muted small mb-3">Tedarikçi e-faturasındaki irsaliye numarası sevkiyat kayıtlarıyla eşleştirilir; fatura tonajı kantarla karşılaştırılır.</p>
<?php foreach(['success','error','warning','info'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<?php if ($hata): ?><div class="alert alert-danger"><?= h($hata) ?></div><?php endif; ?>

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

<?php if ($sonuc): $v = $sonuc['v']; $e = $sonuc['e']; $ton = $v['miktar_kg'] !== null ? $v['miktar_kg']/1000 : null; ?>

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
            <input type="hidden" name="tedarikci" value="<?= h((string)$v['satici_unvan']) ?>"></div>
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
    <div class="card-header bg-white fw-semibold"><i class="bi bi-archive me-1"></i> Kayıtlı Faturalar</div>
    <div class="card-body p-0">
    <?php if (!$kayitli): ?>
        <div class="p-3 text-muted">Henüz kayıtlı demir faturası yok.</div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th>Fatura No</th><th>Tarih</th><th>Tedarikçi</th><th>İrsaliye</th>
                <th class="text-end">Tutar</th><th class="text-end">Fatura (t)</th><th class="text-end">Kantar (t)</th>
                <th class="text-end">Fark</th><th class="text-center">Bağlı Svk.</th><th>Dosya</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($kayitli as $f):
                $fTon = $f['miktar_kg'] !== null ? (float)$f['miktar_kg']/1000 : null;
                $kTon = (float)($f['kantar_ton'] ?: $f['irs_ton']);
                $fark = ($fTon !== null && (int)$f['bagli'] > 0) ? $kTon - $fTon : null;
                $irsL = json_decode((string)$f['irsaliye_liste'], true) ?: []; ?>
                <tr>
                    <td><code><?= h($f['fatura_no']) ?></code></td>
                    <td class="text-nowrap"><?= h(format_date($f['tarih'])) ?></td>
                    <td class="small"><?= h((string)$f['tedarikci']) ?></td>
                    <td class="small"><?php foreach ($irsL as $n): ?><code class="me-1"><?= h($n) ?></code><?php endforeach; ?></td>
                    <td class="text-end font-monospace"><?= $f['tutar']!==null ? $fmt($f['tutar']).' ₺' : '—' ?></td>
                    <td class="text-end font-monospace"><?= $fTon!==null ? $fmt($fTon) : '—' ?></td>
                    <td class="text-end font-monospace"><?= (int)$f['bagli'] ? $fmt($kTon) : '—' ?></td>
                    <td class="text-end font-monospace <?= $fark===null ? 'text-muted' : (abs($fark)<=0.05 ? 'text-success' : 'text-danger fw-bold') ?>">
                        <?= $fark===null ? '—' : $fmt($fark) ?></td>
                    <td class="text-center"><span class="badge <?= (int)$f['bagli'] ? 'bg-success' : 'bg-danger' ?>"><?= (int)$f['bagli'] ?></span></td>
                    <td><?php if ($f['dosya_url']): ?><a href="../<?= h($f['dosya_url']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                    <td class="text-end"><?php if (is_admin()): ?>
                        <a class="btn btn-sm btn-outline-danger py-0" href="?sil=<?= (int)$f['id'] ?>" onclick="return confirm('Fatura kaydı silinsin, sevkiyat bağları çözülsün mü?')"><i class="bi bi-trash"></i></a>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
