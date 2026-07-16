<?php
/**
 * _ortak.php — Seramik modülü ortak yardımcıları (tarih/sayı parse, get-or-create).
 * $pdoSeramik gerektirir.
 */

/** Türkçe malzeme adı normalize: I/İ katla, * → X, boşlukları sadeleştir. */
function sr_norm(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = str_replace(['İ','I','ı','i','*'], ['I','I','I','I','X'], $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/** "17.10.2025" / "2025-10-17" / Excel serial → Y-m-d (null olabilir) */
function sr_tarih($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $v, $m))
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
    if (is_numeric($v)) { // Excel serial
        $ts = ((int)$v - 25569) * 86400;
        if ($ts > 0) return gmdate('Y-m-d', $ts);
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : null;
}

/** Türkçe sayı: "1.451,52" → 1451.52 ; "302.4" → 302.4 */
function sr_sayi($v): float {
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    $v = str_replace([' ', "\xc2\xa0"], '', $v);
    if (strpos($v, ',') !== false) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
    return is_numeric($v) ? (float)$v : 0.0;
}

/** Malzeme get-or-create (ad_norm ile eşleşir) */
function sr_malzemeId(PDO $pdo, ?string $ad, string $tur = 'SERAMİK', string $birim = 'M2'): ?int {
    $ad = trim((string)$ad);
    if ($ad === '') return null;
    $norm = sr_norm($ad);
    $s = $pdo->prepare("SELECT id FROM seramik_malzemeler WHERE ad_norm = ? LIMIT 1");
    $s->execute([$norm]);
    if ($id = $s->fetchColumn()) return (int)$id;
    $pdo->prepare("INSERT INTO seramik_malzemeler (ad, ad_norm, tur, birim) VALUES (?,?,?,?)")
        ->execute([$ad, $norm, $tur ?: null, $birim ?: 'M2']);
    return (int)$pdo->lastInsertId();
}

/** Basit ad tablosu get-or-create (firmalar/taseronlar) */
function sr_adId(PDO $pdo, string $tablo, ?string $ad): ?int {
    $ad = trim((string)$ad);
    if ($ad === '') return null;
    $s = $pdo->prepare("SELECT id FROM {$tablo} WHERE UPPER(TRIM(ad)) = UPPER(TRIM(?)) LIMIT 1");
    $s->execute([$ad]);
    if ($id = $s->fetchColumn()) return (int)$id;
    $pdo->prepare("INSERT INTO {$tablo} (ad) VALUES (?)")->execute([$ad]);
    return (int)$pdo->lastInsertId();
}

/**
 * Stok listesi (CANLI): Excel "AMBAR MEVCUT"a sabitlenir (Excel = doğru kaynak) ve
 * elle eklenen hareketlerle güncellenir:
 *   Giriş  = SAYIM (Mevcut fiziksel sayım) + elle giriş kayıtları
 *   Çıkış  = GİDEN (Mevcut, Excel) + elle çıkış kayıtları
 *   Stok   = STOK (Mevcut = SAYIM−GİDEN) + elle giriş − elle çıkış
 * Not: Excel'den içe aktarılan giriş/çıkış LOG'ları (kaynak='excel') SAYIM/GİDEN
 * içinde zaten sayıldığından stok hesabına EKLENMEZ; yalnız hareket geçmişi olarak tutulur.
 */
function sr_stok(PDO $pdo): array {
    $sql = "
      SELECT m.id, m.ad, m.tur, m.birim,
             COALESCE(sy.sayim,0)       + COALESCE(gm.giren,0) AS giren,
             COALESCE(sy.giden_excel,0) + COALESCE(cm.cikan,0) AS cikan,
             COALESCE(sy.sayim,0) + COALESCE(gm.giren,0)
               - COALESCE(sy.giden_excel,0) - COALESCE(cm.cikan,0) AS stok
      FROM seramik_malzemeler m
      LEFT JOIN seramik_sayim sy ON sy.malzeme_id = m.id
      LEFT JOIN (SELECT malzeme_id, SUM(miktar) giren FROM seramik_giris WHERE kaynak='manuel' GROUP BY malzeme_id) gm ON gm.malzeme_id = m.id
      LEFT JOIN (SELECT malzeme_id, SUM(miktar) cikan FROM seramik_cikis  WHERE kaynak='manuel' GROUP BY malzeme_id) cm ON cm.malzeme_id = m.id
      WHERE m.aktif=1
      ORDER BY m.ad";
    return $pdo->query($sql)->fetchAll();
}
