<?php
/**
 * belge.php — İrsaliye belgeleri (kantar fişi / fatura / foto) okuma ve otomatik bağlama
 *
 * Amaç: sahadan gelen belgeyi yükle → AI OKUSUN → belgedeki irsaliye numarasından
 * (yoksa plaka+tarihten) İLGİLİ İRSALİYE BULUNSUN → belge o irsaliyeye eklensin.
 *
 * Depolama: dosyalar `uploads/irsaliye_{id}/` altında; DB'de yalnız göreli yol
 * (`irsaliye_fotolar` tablosu, `tur` + `okunan` kolonlarıyla genişletildi).
 */
require_once __DIR__ . '/fatura.php';

const BLG_TURLER = [
    'foto'     => ['ad' => 'Fotoğraf',    'ikon' => 'bi-image'],
    'kantar'   => ['ad' => 'Kantar Fişi', 'ikon' => 'bi-truck'],
    'fatura'   => ['ad' => 'Fatura',      'ikon' => 'bi-receipt'],
    'irsaliye' => ['ad' => 'İrsaliye',    'ikon' => 'bi-file-earmark-text'],
    'diger'    => ['ad' => 'Diğer',       'ikon' => 'bi-paperclip'],
];

/** irsaliye_fotolar tablosunu belge alanlarıyla genişlet (runtime migration). */
function blg_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;                 // istek başına bir kez (toplu yüklemede SHOW COLUMNS tekrarlanmasın)
    $yapildi = true;

    $kolonlar = [];
    foreach ($pdo->query("SHOW COLUMNS FROM irsaliye_fotolar")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $kolonlar[$c['Field']] = true;
    }
    $ekle = [];
    if (!isset($kolonlar['tur']))    $ekle[] = "ADD COLUMN tur VARCHAR(20) NOT NULL DEFAULT 'foto'";
    if (!isset($kolonlar['okunan'])) $ekle[] = "ADD COLUMN okunan TEXT NULL COMMENT 'AI ile okunan alanlar (JSON)'";
    if ($ekle) $pdo->exec("ALTER TABLE irsaliye_fotolar " . implode(', ', $ekle));
}

/** Belge türü etiketi */
function blg_tur_ad(?string $t): string { return BLG_TURLER[$t ?? 'foto']['ad'] ?? 'Belge'; }
function blg_tur_ikon(?string $t): string { return BLG_TURLER[$t ?? 'foto']['ikon'] ?? 'bi-paperclip'; }

/**
 * "1.234,50" / "1234.5" / "24.560" → float (kantar fişleri her iki biçimi de kullanır).
 * Nokta ile ÜÇERLİ gruplanmış sayı (24.560 / 1.234.567) binlik ayıracı sayılır —
 * kg değerlerinde bu ayrım şart: 24.560 kg = 24560, 24,56 değil.
 */
function blg_sayi($v): ?float
{
    $s = is_string($v) ? trim($v) : (is_numeric($v) ? (string)$v : '');
    if ($s === '') return null;
    $t = preg_replace('/[^\d.,-]/', '', $s);
    if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $t)) return (float)str_replace('.', '', $t);
    return fat_sayi($t);
}

/** "07:42" / "7.42" / "07:42:11" → HH:MM */
function blg_saat($v): ?string
{
    if (!preg_match('/(\d{1,2})[:.\s](\d{2})/', (string)$v, $m)) return null;
    $s = (int)$m[1]; $d = (int)$m[2];
    return ($s <= 23 && $d <= 59) ? sprintf('%02d:%02d', $s, $d) : null;
}

/**
 * Belgeyi AI ile okuyup yapılandırılmış alanları döndürür.
 * @return array|null  null = okunamadı (AI kapalı / hata)
 */
function blg_ai_oku(string $path, string $mime, string $tur = 'kantar'): ?array
{
    if (!function_exists('ai_call')) require_once __DIR__ . '/ai_call.php';
    $ham = @file_get_contents($path);
    if ($ham === false || $ham === '') return null;

    $parca = ($mime === 'application/pdf')
        ? ['type' => 'document', 'data' => base64_encode($ham)]
        : ['type' => 'image', 'mime' => $mime, 'data' => base64_encode($ham)];

    $system = "Sen bir şantiye belge okuyucususun. Verilen belgeyi oku ve SADECE JSON döndür. "
        . "Açıklama yazma. Okuyamadığın alanı null bırak — ASLA tahmin etme, uydurma.\n\n"
        . "Format:\n"
        . "{\"belge_turu\":\"kantar|irsaliye|fatura|diger\",\"irsaliye_no\":null,\"fis_no\":null,"
        . "\"tarih\":\"YYYY-AA-GG\",\"plaka\":null,\"firma\":null,\"malzeme\":null,"
        . "\"giris_saati\":\"HH:MM\",\"cikis_saati\":\"HH:MM\","
        . "\"tartim1_kg\":null,\"tartim2_kg\":null,\"net_kg\":null,\"miktar_m3\":null,\"guven\":0.0}\n\n"
        . "KANTAR FİŞİ alanları: FİŞ NO, PLAKA, OPERATÖR, GİRİŞ TARİHİ/SAATİ, ÇIKIŞ TARİHİ/SAATİ, "
        . "FİRMA, MALZEME, 1. TARTIM, 2. TARTIM, NET.\n"
        . "- net_kg: NET satırındaki değer, KİLOGRAM olarak sayı (ör. '24.560 kg' → 24560).\n"
        . "- tartim1_kg / tartim2_kg: dolu ve boş tartım değerleri (kg).\n"
        . "- giris_saati / cikis_saati: fişteki giriş ve çıkış saatleri (HH:MM).\n"
        . "- Fişte İRSALİYE NO yazıyorsa irsaliye_no alanına AYNEN yaz (biçimini değiştirme).\n"
        . "- plaka: Türk plakası biçimine normalize et (ör. '34 abc 123' → '34ABC123').\n"
        . "- Belge bir kantar/tartı fişi ise belge_turu=\"kantar\".\n\n"
        . "İRSALİYE / FATURA ise: irsaliye_no, tarih, plaka, firma, miktar_m3 (beton m³) alanlarını doldur.\n"
        . "guven: okuduğuna ne kadar eminsin (0.0–1.0).";

    $r = ai_call($system, [$parca, ['type' => 'text', 'text' => 'Belgeyi oku ve JSON döndür.']], 1200);
    if (!($r['ok'] ?? false)) return null;

    $t = trim((string)($r['text'] ?? ''));
    if (preg_match('/\{.*\}/s', $t, $m)) $t = $m[0];
    $j = json_decode($t, true);
    if (!is_array($j)) return null;

    return [
        'belge_turu'  => in_array($j['belge_turu'] ?? '', ['kantar','irsaliye','fatura','diger'], true) ? $j['belge_turu'] : $tur,
        'irsaliye_no' => trim((string)($j['irsaliye_no'] ?? '')) ?: null,
        'fis_no'      => trim((string)($j['fis_no'] ?? '')) ?: null,
        'tarih'       => fat_tarih_norm((string)($j['tarih'] ?? '')),
        'plaka'       => ($p = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string)($j['plaka'] ?? ''), 'UTF-8'))) !== '' ? $p : null,
        'firma'       => trim((string)($j['firma'] ?? '')) ?: null,
        'malzeme'     => trim((string)($j['malzeme'] ?? '')) ?: null,
        'giris_saati' => blg_saat($j['giris_saati'] ?? ''),
        'cikis_saati' => blg_saat($j['cikis_saati'] ?? ''),
        'tartim1_kg'  => blg_sayi($j['tartim1_kg'] ?? null),
        'tartim2_kg'  => blg_sayi($j['tartim2_kg'] ?? null),
        'net_kg'      => blg_sayi($j['net_kg'] ?? null),
        'miktar_m3'   => blg_sayi($j['miktar_m3'] ?? null),
        'guven'       => (float)($j['guven'] ?? 0),
    ];
}

/**
 * Okunan alanlardan İLGİLİ İRSALİYEYİ bulur.
 * Sıra: irsaliye_no (normalize) → plaka + tarih → plaka (tek aday kaldıysa).
 * @return array{irsaliye:?array, yontem:string, adaylar:array}
 */
function blg_irsaliye_bul(PDO $pdo, array $v): array
{
    $sec = "SELECT i.id, i.irsaliye_no, i.tarih, i.miktar, i.arac_plaka, i.durum,
                   i.kantar_net_tedarikci, i.kantar_net_yildizlar, i.kantar_giris_saati, i.kantar_cikis_saati,
                   t.ad AS tedarikci, bs.ad AS beton_sinifi
            FROM irsaliyeler i
            LEFT JOIN tedarikciler t ON t.id = i.tedarikci_id
            LEFT JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id";

    // 1) İrsaliye numarası — biçim farkı normalize edilerek
    $no = fat_irs_norm($v['irsaliye_no'] ?? '');
    if ($no !== '') {
        $onek = preg_match('/^([A-Z]+\d{4})/', $no, $m) ? $m[1] . '%' : '%';
        $st = $pdo->prepare("$sec WHERE i.irsaliye_no LIKE ?");
        $st->execute([$onek]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (fat_irs_norm($r['irsaliye_no']) === $no) return ['irsaliye' => $r, 'yontem' => 'irsaliye_no', 'adaylar' => []];
        }
    }

    // 2) Plaka + tarih
    $plaka = $v['plaka'] ?? null;
    $tarih = $v['tarih'] ?? null;
    if ($plaka && $tarih) {
        $st = $pdo->prepare("$sec WHERE REPLACE(REPLACE(UPPER(i.arac_plaka),' ',''),'-','') = ? AND i.tarih = ?");
        $st->execute([$plaka, $tarih]);
        $ad = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($ad) === 1) return ['irsaliye' => $ad[0], 'yontem' => 'plaka_tarih', 'adaylar' => []];
        if (count($ad) > 1)   return ['irsaliye' => null,   'yontem' => 'plaka_tarih_coklu', 'adaylar' => $ad];
    }

    // 3) Yalnız plaka — son 7 gün içinde tek kayıt varsa
    if ($plaka) {
        $st = $pdo->prepare("$sec WHERE REPLACE(REPLACE(UPPER(i.arac_plaka),' ',''),'-','') = ?
                             ORDER BY i.tarih DESC LIMIT 10");
        $st->execute([$plaka]);
        $ad = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($ad) return ['irsaliye' => null, 'yontem' => 'plaka', 'adaylar' => $ad];
    }

    return ['irsaliye' => null, 'yontem' => 'bulunamadi', 'adaylar' => []];
}

/**
 * Bu belge bu irsaliyeye zaten eklenmiş mi? (mükerrer önleme)
 *
 * Dosya adı/yolu her yüklemede değiştiği için ona bakmak yetmez; belgenin
 * KİMLİĞİNE bakılır: kantar fişinde fiş no, fatura/irsaliyede belge numarası.
 * Kimlik yoksa aynı tür + aynı dosya adı tekrarı mükerrer sayılır.
 */
function blg_mukerrer(PDO $pdo, int $irsId, string $tur, ?array $okunan,
                      string $dosyaAdi = '', ?string $yeniDosya = null, string $kokDizin = ''): ?array
{
    blg_semasi_kur($pdo);
    $st = $pdo->prepare("SELECT id, dosya_adi, dosya_yolu, okunan, created_at
                         FROM irsaliye_fotolar WHERE irsaliye_id = ? AND tur = ?");
    $st->execute([$irsId, $tur]);
    $mevcutlar = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$mevcutlar) return null;

    // 1) Belge kimliği (fiş no / irsaliye no / fatura no) — en güvenilir ölçüt
    $kimlik = blg_kimlik($okunan);
    if ($kimlik !== null) {
        foreach ($mevcutlar as $m) {
            if (blg_kimlik(json_decode((string)$m['okunan'], true)) === $kimlik) return $m;
        }
    }

    // 2) Dosya içeriği aynı mı — aynı baytlar aynı belgedir (fotoğraflar için de geçerli)
    if ($yeniDosya !== null && is_file($yeniDosya)) {
        $kok  = rtrim($kokDizin !== '' ? $kokDizin : dirname(__DIR__), '/') . '/';
        $boy  = filesize($yeniDosya);
        $hash = null;
        foreach ($mevcutlar as $m) {
            $eski = $kok . $m['dosya_yolu'];
            if (!is_file($eski) || filesize($eski) !== $boy) continue;   // boyut farklıysa okumaya gerek yok
            $hash ??= md5_file($yeniDosya);
            if (md5_file($eski) === $hash) return $m;
        }
    }

    // 3) Kimlik taşıyan belgelerde dosya adı tekrarı da mükerrer sayılır.
    //    Fotoğraflarda SAYILMAZ: telefonlar farklı fotoğraflara aynı adı verir (IMG_0001.jpg).
    if ($kimlik === null && $dosyaAdi !== '' && in_array($tur, ['kantar','fatura','irsaliye'], true)) {
        foreach ($mevcutlar as $m) {
            if (mb_strtolower($m['dosya_adi']) === mb_strtolower($dosyaAdi)) return $m;
        }
    }
    return null;
}

/** Okunan alanlardan belgenin kimliğini üretir (fiş no > irsaliye no); yoksa null. */
function blg_kimlik(?array $okunan): ?string
{
    if (!is_array($okunan)) return null;
    foreach (['fis_no', 'irsaliye_no', 'fatura_no'] as $alan) {
        $v = trim((string)($okunan[$alan] ?? ''));
        if ($v !== '') return $alan . ':' . fat_irs_norm($v);
    }
    return null;
}

/** Belgeyi irsaliyeye ekler (dosya zaten diskte). @return int belge id */
function blg_ekle(PDO $pdo, int $irsId, string $dosyaAdi, string $relPath, string $tur, ?array $okunan, ?int $uid): int
{
    blg_semasi_kur($pdo);
    $st = $pdo->prepare("INSERT INTO irsaliye_fotolar (irsaliye_id, dosya_adi, dosya_yolu, tur, okunan, created_by)
                         VALUES (?,?,?,?,?,?)");
    $st->execute([$irsId, $dosyaAdi, $relPath, $tur,
                  $okunan ? json_encode($okunan, JSON_UNESCAPED_UNICODE) : null, $uid]);
    return (int)$pdo->lastInsertId();
}

/** Yüklenen dosyayı irsaliye klasörüne taşır. @return array{ad:string, yol:string, tam:string}|null */
function blg_dosya_tasi(string $tmp, string $ad, int $irsId, string $kokDizin): ?array
{
    $dir = rtrim($kokDizin, '/') . '/uploads/irsaliye_' . $irsId . '/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    $ext  = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'bin';
    $yeni = uniqid('blg_', true) . '.' . $ext;
    $tam  = $dir . $yeni;
    $ok   = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $tam) : @rename($tmp, $tam);
    if (!$ok) return null;
    return ['ad' => $ad, 'yol' => 'uploads/irsaliye_' . $irsId . '/' . $yeni, 'tam' => $tam];
}

/**
 * Kantar fişinden okunan değerleri irsaliyeye yazar.
 * Yalnız BOŞ alanlar doldurulur; dolu alanlar korunur (elle girilen veri ezilmez).
 * @return string[] Uygulanan alanların insan okunur listesi
 */
function blg_kantar_uygula(PDO $pdo, int $irsId, array $v, ?int $uid = null): array
{
    $st = $pdo->prepare("SELECT kantar_net_tedarikci, kantar_net_yildizlar, kantar_farki,
                                kantar_giris_saati, kantar_cikis_saati FROM irsaliyeler WHERE id = ?");
    $st->execute([$irsId]);
    $mev = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mev) return [];

    $set = []; $par = []; $yapilan = [];
    $bos = fn($k) => $mev[$k] === null || $mev[$k] === '' || $mev[$k] === '0.00';

    if ($v['net_kg'] !== null && $bos('kantar_net_tedarikci')) {
        $set[] = 'kantar_net_tedarikci = ?'; $par[] = $v['net_kg'];
        $yapilan[] = 'Kantar Net (Tedarikçi) = ' . number_format($v['net_kg'], 2, ',', '.') . ' kg';
    }
    if ($v['giris_saati'] && $bos('kantar_giris_saati')) {
        $set[] = 'kantar_giris_saati = ?'; $par[] = $v['giris_saati'];
        $yapilan[] = 'Kantar Giriş = ' . $v['giris_saati'];
    }
    if ($v['cikis_saati'] && $bos('kantar_cikis_saati')) {
        $set[] = 'kantar_cikis_saati = ?'; $par[] = $v['cikis_saati'];
        $yapilan[] = 'Kantar Çıkış = ' . $v['cikis_saati'];
    }
    if (!$set) return [];

    // Kantar farkı = Yıldızlar − Tedarikçi (ikisi de biliniyorsa yeniden hesapla)
    $yeniTed = $v['net_kg'] !== null && $bos('kantar_net_tedarikci') ? $v['net_kg'] : ($mev['kantar_net_tedarikci'] !== null ? (float)$mev['kantar_net_tedarikci'] : null);
    if ($mev['kantar_net_yildizlar'] !== null && $yeniTed !== null) {
        $set[] = 'kantar_farki = ?'; $par[] = round((float)$mev['kantar_net_yildizlar'] - $yeniTed, 2);
        $yapilan[] = 'Kantar Farkı yeniden hesaplandı';
    }

    $par[] = $irsId;
    $pdo->prepare("UPDATE irsaliyeler SET " . implode(', ', $set) . " WHERE id = ?")->execute($par);
    audit_log($pdo, 'irsaliyeler', $irsId, 'UPDATE', $mev, ['kantar_fisinden' => $yapilan], $uid);
    return $yapilan;
}
