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
