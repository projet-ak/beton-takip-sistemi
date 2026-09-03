<?php
/**
 * demir/_talep_import.php — IFS Sipariş Talepleri içe aktarma çekirdeği (paylaşılan)
 *
 * "Demir Siparişleri Takip Tablosu" (her sayfa bir talep) buradan okunur. Üç demir içe
 * aktarma ekranı da (talepler.php, import_siparis.php, import.php) dosya türünü
 * tlp_dosya_turu() ile tanır: yanlış ekrana atılan talep dosyası sessizce doğru
 * aktarıcıya yönlenir — "Sipariş Takip sayfası bulunamadı" kafa karışıklığı biter.
 */
require_once __DIR__ . '/../vendor/autoload.php';

// ── Yardımcılar ──────────────────────────────────────────────────────────────
/** Türkçe harfleri ASCII'ye katlayan normalizasyon (başlık eşleme için). */
function tlpNorm(string $s): string {
    $s = str_replace(['İ','I','ı','i','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'],
                     ['I','I','I','I','S','S','G','G','U','U','O','O','C','C'], $s);
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return preg_replace('/\s+/', ' ', $s);
}
/** Başlıktan kolon bul: önce TAM eşitlik, sonra "ile başlar" (SITE ≠ SITE TANIMI için). */
function tlpKol(array $hdr, array $adaylar): ?int {
    foreach ($adaylar as $a) { $a = tlpNorm($a);
        foreach ($hdr as $i => $h) if ($h === $a) return (int)$i; }
    foreach ($adaylar as $a) { $a = tlpNorm($a);
        foreach ($hdr as $i => $h) if ($h !== '' && strncmp($h, $a, strlen($a)) === 0) return (int)$i; }
    return null;
}
function tlpSayi($v): float {
    $v = str_replace([' ', "\xc2\xa0"], '', trim((string)$v));
    if ($v === '' || $v[0] === '#') return 0.0;
    if (strpos($v, ',') !== false) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
    return is_numeric($v) ? (float)$v : 0.0;
}
/**
 * Malzeme açıklamasından çap çıkarır ve demir_caplar ile eşleştirir.
 * "İnşaat Demiri Nervürlü 26 Mm" → Ø26 (duz) · "Çelik Hasır Q188/188 …" → Q188/188 (hasir)
 * @return array{0:?int,1:string} [cap_id, label]
 */
function tlpCap(array $caplar, string $aciklama): array {
    $u = tlpNorm($aciklama);
    if (preg_match('/Q\s*(\d{3})/', $u, $m)) { $tip = 'hasir'; $sayi = (int)$m[1]; $label = "Q{$m[1]}/{$m[1]}"; }
    elseif (strpos($u, 'SPIRAL') !== false && preg_match('/(\d{1,2})/', $u, $m)) { $tip = 'spiral'; $sayi = (int)$m[1]; $label = "Spiral Ø{$sayi}"; }
    elseif (strpos($u, 'KANGAL') !== false && preg_match('/(\d{1,2})\s*MM/', $u, $m)) { $tip = 'kangal'; $sayi = (int)$m[1]; $label = "Q{$sayi} Kangal"; }
    elseif (preg_match('/(\d{1,2})\s*MM/', $u, $m)) { $tip = 'duz'; $sayi = (int)$m[1]; $label = "Ø{$sayi}"; }
    else return [null, mb_substr(trim($aciklama), 0, 40)];

    foreach ($caplar as $c) {                       // önce sayı + tip
        if ((int)$c['sayi'] === $sayi && $c['tip'] === $tip) return [(int)$c['id'], $c['ad']];
    }
    foreach ($caplar as $c) {                       // sonra yalnız sayı
        if ((int)$c['sayi'] === $sayi) return [(int)$c['id'], $c['ad']];
    }
    return [null, $label];
}


/**
 * Yüklenen demir Excel'inin türünü sayfa adı/başlıklarından tanır.
 * @return 'talep' | 'siparis' | 'sevkiyat' | null
 */
function tlp_dosya_turu(\Shuchkin\SimpleXLSX $x): ?string {
    $adlar = $x->sheetNames();
    foreach ($adlar as $n) {
        if (mb_stripos($n, 'DEMİR') !== false && mb_stripos($n, 'TAKİP') !== false) return 'sevkiyat';
    }
    foreach ($adlar as $n) {
        if ((mb_stripos($n, 'SİPARİŞ') !== false || mb_stripos($n, 'SIPARIŞ') !== false) && mb_stripos($n, 'TAKİP') !== false) return 'siparis';
    }
    foreach ($adlar as $si => $n) {                       // talep dosyası: ilk 3 satırda "Talep No" başlığı
        foreach (array_slice($x->rows($si, 3), 0, 3) as $row) {
            foreach ($row as $c) if (tlpNorm((string)$c) === 'TALEP NO') return 'talep';
        }
    }
    return null;
}

/** Türe göre doğru içe aktarma ekranı + insan okunur ad. */
function tlp_dosya_hedef(?string $tur): array {
    return [
        'talep'    => ['talepler.php',       'Sipariş Talepleri (Demir Siparişleri Takip Tablosu)'],
        'siparis'  => ['import_siparis.php', 'Sipariş İçe Aktar ("Sipariş Takip" sayfası)'],
        'sevkiyat' => ['import.php',         'Sevkiyat İçe Aktar ("İNŞAAT DEMİRİ TAKİP" sayfası)'],
    ][$tur] ?? ['', ''];
}

/**
 * Talep dosyasını TAM YENİLEME ile aktarır (dosya kümülatiftir; tüm talepler her dosyada).
 * @return array{talep:int, kalem:int, atlanan:string[]}
 * @throws Throwable  hata durumunda geri alınmış olarak fırlatır
 */
function tlp_import(PDO $pdoDemir, \Shuchkin\SimpleXLSX $x): array {
$caplar = [];
foreach ($pdoDemir->query("SELECT id, ad, tip FROM demir_caplar")->fetchAll() as $c) {
    $caplar[] = ['id' => $c['id'], 'ad' => $c['ad'], 'tip' => $c['tip'],
                 'sayi' => preg_match('/(\d+)/', $c['ad'], $m) ? (int)$m[1] : 0];
}
$pdoDemir->beginTransaction();
try {
    $pdoDemir->exec("DELETE FROM demir_talep_kalemleri");
    $pdoDemir->exec("DELETE FROM demir_talepler");
    $insT = $pdoDemir->prepare("INSERT INTO demir_talepler (talep_no,tarih,sayfa_adi,firma,proje,parsel,statu,excel_siparis_kg,excel_teslim_kg,excel_fark_kg) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $insK = $pdoDemir->prepare("INSERT INTO demir_talep_kalemleri (talep_id,kod,aciklama,cap_id,cap_label,firma,siparis_kg,teslim_kg,kalan_kg,notlar) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $talepAdet = 0; $kalemAdet = 0; $atlanan = [];

    foreach ($x->sheetNames() as $si => $sayfaAdi) {
        $rows = $x->rows($si, 500);
        if (!$rows) continue;
        // Başlık satırı: ilk 3 satırda "Talep No" ara
        $hr = -1;
        foreach (array_slice($rows, 0, 3, true) as $ri => $row) {
            foreach ($row as $c) if (tlpNorm((string)$c) === 'TALEP NO') { $hr = $ri; break 2; }
        }
        if ($hr < 0) { $atlanan[] = $sayfaAdi; continue; }

        $hdr = array_map(fn($c) => tlpNorm((string)$c), $rows[$hr]);
        $iTal = tlpKol($hdr, ['TALEP NO']);
        $iKod = tlpKol($hdr, ['MALZEME NO']);
        $iAck = tlpKol($hdr, ['MALZEME ACIKLAMASI']);
        $iSit = tlpKol($hdr, ['SITE']);              // tam eşitlik önce: SITE TANIMI'na düşmez
        $iSta = tlpKol($hdr, ['STATU']);
        $iMik = tlpKol($hdr, ['TOPLAM', 'MIKTAR']);  // birleşik talepte "Toplam" esas
        $iTes = tlpKol($hdr, ['TESLIM ALINAN', 'TESLIM']);
        $iFir = tlpKol($hdr, ['FIRMA']);
        $iPar = tlpKol($hdr, ['PARSEL']);
        $iKal = tlpKol($hdr, ['KALAN']);
        if ($iTal === null || $iKod === null || $iMik === null) { $atlanan[] = $sayfaAdi; continue; }

        // Sayfa adından tarih: "PRP iNŞAAT 24.06.2026-D"
        $tarih = null;
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $sayfaAdi, $m)
            && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            $tarih = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        $al = fn(array $r, ?int $i) => $i !== null ? trim((string)($r[$i] ?? '')) : '';
        $kalemler = []; $talepNo = ''; $firmalar = []; $projeler = []; $parseller = []; $statu = '';
        $exSip = null; $exTes = null; $exFark = null;   // Excel'in kendi özet satırı (sağlama)
        // Not kolonları: bilinen son kolondan (Parsel/Firma) SONRAKİ dolu hücreler
        $notBas = max(array_filter([$iPar, $iFir, $iKal, $iTes, $iMik], fn($x) => $x !== null)) + 1;
        for ($ri = $hr + 1; $ri < count($rows); $ri++) {
            $r = $rows[$ri];
            $tn = $al($r, $iTal); $kod = $al($r, $iKod);
            if ($tn === '' || $kod === '' || !preg_match('/^[\d\-\s]+$/', $tn)) {
                // Kalem değil — Excel'in özet satırı olabilir: "Sipariş 182000 Teslim alınan 186800" / "Fark 4800".
                // Etiketin SAĞINDAKİ ilk dolu hücre değeridir. Kutsal kitap sağlaması burada saklanır.
                foreach ($r as $ci => $c) {
                    $cn = tlpNorm((string)$c);
                    if (!in_array($cn, ['SIPARIS', 'TESLIM ALINAN', 'FARK'], true)) continue;
                    for ($cj = $ci + 1; $cj < count($r); $cj++) {
                        $vv = trim((string)($r[$cj] ?? ''));
                        if ($vv === '') continue;
                        $num = tlpSayi($vv);
                        if ($cn === 'SIPARIS') $exSip = $num;
                        elseif ($cn === 'TESLIM ALINAN') $exTes = $num;
                        else $exFark = $num;
                        break;
                    }
                }
                continue;
            }
            if ($talepNo === '') $talepNo = preg_replace('/\s+/', '', $tn);
            $ack = $al($r, $iAck);
            [$capId, $capLabel] = tlpCap($caplar, $ack);
            $fir = $al($r, $iFir);
            // Parsel'den sonraki serbest hücreler = kalem notu ("25460 OSMAN CAMCI VERİLDİ" gibi devirler)
            $notlar = [];
            for ($ci = $notBas; $ci < count($r); $ci++) {
                $vv = trim((string)($r[$ci] ?? ''));
                if ($vv !== '') $notlar[] = $vv;
            }
            $kalemler[] = [$kod, mb_substr($ack, 0, 200), $capId, $capLabel, mb_substr($fir, 0, 120) ?: null,
                           tlpSayi($al($r, $iMik)), tlpSayi($al($r, $iTes)),
                           $iKal !== null && $al($r, $iKal) !== '' ? tlpSayi($al($r, $iKal)) : null,
                           $notlar ? mb_substr(implode(' · ', $notlar), 0, 300) : null];
            if ($fir !== '') $firmalar[trim($fir)] = true;
            if (($p = $al($r, $iSit)) !== '') $projeler[$p] = true;
            if (($p = $al($r, $iPar)) !== '') $parseller[$p] = true;
            if ($statu === '') $statu = $al($r, $iSta);
        }
        if ($talepNo === '' || !$kalemler) { $atlanan[] = $sayfaAdi; continue; }

        $insT->execute([$talepNo, $tarih, mb_substr(trim($sayfaAdi), 0, 150),
                        implode(', ', array_keys($firmalar)) ?: null,
                        implode(', ', array_keys($projeler)) ?: null,
                        implode(', ', array_keys($parseller)) ?: null,
                        $statu ?: null, $exSip, $exTes, $exFark]);
        $tid = (int)$pdoDemir->lastInsertId();
        foreach ($kalemler as $k) { $insK->execute(array_merge([$tid], $k)); $kalemAdet++; }
        $talepAdet++;
    }
    $pdoDemir->commit();
    return ['talep' => $talepAdet, 'kalem' => $kalemAdet, 'atlanan' => $atlanan];
    } catch (Throwable $e) {
        if ($pdoDemir->inTransaction()) $pdoDemir->rollBack();
        throw $e;
    }
}
