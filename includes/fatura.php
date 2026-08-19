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
    $d = ['fatura_no'=>null,'tarih'=>null,'tutar'=>null,'brut_tutar'=>null,'miktar'=>null,'ettn'=>null,'irsaliyeler'=>[]];
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
    return $d;
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
function fat_dosyadan_metin(string $path, string $mime, ?string &$kaynak = null): ?string
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
    // 2) AI belge okuma
    $ai = fat_ai_oku($path, $mime);
    if ($ai !== null) { $kaynak = 'ai'; return $ai; }
    return null;
}

/**
 * AI'ya faturayı okutup DÜZ METİN olarak geri alır (ayrıştırma yine fat_metinden_cikar ile
 * yapılır; böylece AI'nın "uydurma" alan üretme riski en aza iner).
 */
function fat_ai_oku(string $path, string $mime): ?string
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

    $sistem = 'Sen bir e-Fatura okuyucusun. Verilen faturadaki METNİ olduğu gibi düz metin olarak '
            . 'yaz. Hiçbir şey uydurma, yorum ekleme, özetleme. Özellikle şu alanların satırlarını '
            . 'MUTLAKA aynen aktar: Fatura No, Fatura Tarihi, ETTN, Vergiler Dahil Toplam Tutar, '
            . 'Ödenecek Tutar ve kalem tablosundaki TÜM "İrsaliye No / İrsaliye Tarihi" değerleri. '
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
            $dosyaUrl,
            $fatura['notlar'] ?? null,
        ];
        if ($fid) {
            $u = $pdo->prepare("UPDATE faturalar SET tarih=?, tedarikci_id=?, tutar=?, miktar_m3=?, ettn=?,
                                irsaliye_adet=?, eksik_adet=?, dosya_url=COALESCE(?, dosya_url), notlar=? WHERE id=?");
            $u->execute(array_merge($alan, [$fid]));
        } else {
            $i = $pdo->prepare("INSERT INTO faturalar (fatura_no, tarih, tedarikci_id, tutar, miktar_m3, ettn,
                                irsaliye_adet, eksik_adet, dosya_url, notlar, created_by)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
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
