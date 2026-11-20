<?php
/**
 * beton_import.php — Beton Excel ("Beton Takip Tablosu") içe aktarma ÇEKİRDEĞİ.
 *
 * Hem import.php (önizlemeli, satır seçmeli) hem toplu_yukleme.php (günlük toplu yükleme,
 * önizlemesiz) bu fonksiyonları kullanır — iki ekran AYNI kuralla çalışır:
 *   · mükerrer kontrolü fat_irs_norm ile normalize (SKB2026-12047 ↔ SKB2026000012047)
 *   · [FATURADAN] taslağı Excel satırıyla GÜNCELLENİR (fatura bağı + ekler korunur)
 *   · kayıtlı irsaliye DEĞİŞTİRİLMEZ (Excel ↔ sistem farkı yalnız raporlanır)
 * Gerektirir: includes/functions.php, includes/fatura.php (fat_irs_norm), vendor/autoload.php.
 */
require_once __DIR__ . '/fatura.php';

// Yardımcı Fonksiyonlar — ?string (nullable) ile PHP 8 uyumlu
function getOrCreate(PDO $pdo, string $tablo, string $sutun, ?string $deger): ?int {
    if ($deger === null || trim($deger) === '') return null;
    $deger = trim($deger);
    $s = $pdo->prepare("SELECT id FROM $tablo WHERE $sutun = ?");
    $s->execute([$deger]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO $tablo ($sutun) VALUES (?)")->execute([$deger]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateImalat(PDO $pdo, ?string $ad): ?int {
    if ($ad === null || trim($ad) === '') return null;
    $ad = trim($ad);
    $s = $pdo->prepare("SELECT id FROM imalat_gruplari WHERE ad = ?");
    $s->execute([$ad]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO imalat_gruplari (ad, sira) VALUES (?, 99)")->execute([$ad]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateAnaKalem(PDO $pdo, ?int $grupId, ?string $ad): ?int {
    if ($grupId === null || $ad === null || trim($ad) === '') return null;
    $ad = trim($ad);
    $s = $pdo->prepare("SELECT id FROM ana_is_kalemleri WHERE imalat_grup_id = ? AND ad = ?");
    $s->execute([$grupId, $ad]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO ana_is_kalemleri (imalat_grup_id, ad, sira) VALUES (?, ?, 99)")->execute([$grupId, $ad]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateParsel(PDO $pdo, ?string $ad): ?int {
    if ($ad === null || trim($ad) === '') return null;
    $ad = trim($ad);
    $s = $pdo->prepare("SELECT id FROM parseller WHERE ad = ?");
    $s->execute([$ad]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO parseller (ad) VALUES (?)")->execute([$ad]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateBlok(PDO $pdo, ?int $parselId, ?string $ad): ?int {
    if ($parselId === null || $ad === null || trim($ad) === '') return null;
    $ad = trim($ad);
    $s = $pdo->prepare("SELECT id FROM bloklar WHERE parsel_id = ? AND ad = ?");
    $s->execute([$parselId, $ad]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO bloklar (parsel_id, ad) VALUES (?, ?)")->execute([$parselId, $ad]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateKot(PDO $pdo, ?int $blokId, ?string $ad): ?int {
    if ($blokId === null || $ad === null || trim($ad) === '') return null;
    $ad = trim($ad);
    $s = $pdo->prepare("SELECT id FROM kotlar WHERE blok_id = ? AND kot_degeri = ?");
    $s->execute([$blokId, $ad]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO kotlar (blok_id, kot_degeri) VALUES (?, ?)")->execute([$blokId, $ad]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateProje(PDO $pdo, ?string $kod): ?int {
    $kod = trim((string)$kod);
    if ($kod === '') return null;
    $s = $pdo->prepare("SELECT id FROM projeler WHERE UPPER(kod) = UPPER(?)");
    $s->execute([$kod]);
    if ($id = $s->fetchColumn()) return (int)$id;
    $pdo->prepare("INSERT INTO projeler (kod, aciklama) VALUES (?, ?)")->execute([$kod, $kod]);
    return (int)$pdo->lastInsertId();
}

/**
 * Satır GERÇEK bir irsaliye verisi mi?
 *
 * Excel şablonunda "Sıra" sütunu formülle binlerce satır aşağı uzatılmış ve
 * toplam hücreleri 0 üretiyor; bu satırlar boş görünse de teknik olarak dolu.
 * Bu yüzden "hiçbir hücre dolu değil" testi yetmez — satırı ancak KİLİT
 * alanlardan (irsaliye no / tarih / tedarikçi / plaka / miktar) en az biri
 * doluysa veri sayarız. Sıra numarası veya sıfır tek başına satır yapmaz.
 */
function satirVeriMi(array $r, array $colMapping): bool {
    foreach (['irsaliye_no', 'tarih', 'tedarikci', 'arac_plaka', 'miktar'] as $k) {
        if (!isset($colMapping[$k])) continue;
        $v = trim((string)($r[$colMapping[$k]] ?? ''));
        if ($v === '' || $v === '0') continue;
        return true;
    }
    return false;
}

/**
 * İmalat/metraj/zayiat sayfalarını (PRP Bina Üstyapı, İksa Kazık, İCMAL, Metraj …)
 * metraj_sayfa tablosuna grid (JSON) olarak kaydeder — İmalat Sayfaları / PRP / İcmal
 * ekranları buradan okur. Sayfa1, VERİ ve KOT hariç (KOT Kotlar sayfasında yönetilir).
 */
function storeImalatSheets(PDO $pdo, $xlsx): int {
    $pdo->exec("CREATE TABLE IF NOT EXISTS metraj_sayfa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ad VARCHAR(150) NOT NULL,
        sira INT NOT NULL DEFAULT 0,
        satir_sayisi INT NOT NULL DEFAULT 0,
        kolon_sayisi INT NOT NULL DEFAULT 0,
        veri LONGTEXT NOT NULL,
        guncelleme TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ad (ad)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $haric = ['SAYFA1', 'VERİ', 'VERI', 'KOT'];
    $ins = $pdo->prepare("INSERT INTO metraj_sayfa (ad, sira, satir_sayisi, kolon_sayisi, veri)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE sira=VALUES(sira), satir_sayisi=VALUES(satir_sayisi),
        kolon_sayisi=VALUES(kolon_sayisi), veri=VALUES(veri)");
    $n = 0;
    foreach ($xlsx->sheetNames() as $i => $ad) {
        if (in_array(mb_strtoupper(trim($ad), 'UTF-8'), $haric, true)) continue;
        $rows = $xlsx->rows($i, 2000);
        $maxCol = 0; $lastRow = -1;
        foreach ($rows as $ri => $r) {
            $dolu = false;
            foreach ($r as $ci => $c) { if (trim((string)$c) !== '') { $dolu = true; if ($ci + 1 > $maxCol) $maxCol = $ci + 1; } }
            if ($dolu) $lastRow = $ri;
        }
        if ($lastRow < 0) continue;
        $grid = [];
        for ($ri = 0; $ri <= $lastRow; $ri++) {
            $s = [];
            for ($ci = 0; $ci < $maxCol; $ci++) $s[] = isset($rows[$ri][$ci]) ? trim((string)$rows[$ri][$ci]) : '';
            $grid[] = $s;
        }
        $ins->execute([trim($ad), $i, $lastRow + 1, $maxCol, json_encode($grid, JSON_UNESCAPED_UNICODE)]);
        $n++;
    }
    return $n;
}

/** Türkçe sayı: "1.234,56" → 1234.56, "8,5" → 8.5, "8.5" → 8.5 */
function parseMiktar($v): float {
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    if (strpos($v, ',') !== false) $v = str_replace(['.', ','], ['', '.'], $v); // binlik nokta + ondalık virgül
    return is_numeric($v) ? (float)$v : 0.0;
}

function parseTarih(?string $tarih): ?string {
    if ($tarih === null || trim($tarih) === '') return null;
    $tarih = trim($tarih);

    // Format: YYYY-MM-DD (exact)
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tarih)) {
        return $tarih;
    }
    // Format: YYYY-MM-DD HH:MM:SS  veya  YYYY-MM-DDThh:mm:ss  (saat bileşeni varsa at)
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T\s]/', $tarih, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
    // Format: DD.MM.YYYY
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $tarih, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    // Format: D.M.YYYY veya DD/MM/YYYY
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $tarih, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    // Excel serial date (sayısal): Excel, tarihleri 1900-01-01'den gün sayısı olarak saklar
    // Örn: 45678 → 2025-01-10
    if (preg_match('/^\d{4,6}$/', $tarih)) {
        $serial = (int)$tarih;
        if ($serial > 1000 && $serial < 100000) {
            // Excel'in 1900 yılını hatalı artık yıl saydığı düzeltme (+1)
            $date = (new DateTime('1899-12-30'))->modify("+{$serial} days");
            $result = $date->format('Y-m-d');
            // Makul tarih aralığı: 2000-2050
            if ($result >= '2000-01-01' && $result <= '2050-12-31') {
                return $result;
            }
        }
    }
    // Float serial (bazen ondalıklı gelir: 45678.5 → saat bilgisi var, yalnızca tarih al)
    if (preg_match('/^(\d{4,6})\.\d+$/', $tarih, $m)) {
        $serial = (int)$m[1];
        if ($serial > 1000 && $serial < 100000) {
            $date = (new DateTime('1899-12-30'))->modify("+{$serial} days");
            $result = $date->format('Y-m-d');
            if ($result >= '2000-01-01' && $result <= '2050-12-31') {
                return $result;
            }
        }
    }
    return null;
}

function cleanHeader($text) {
    $text = str_replace(
        ['İ', 'ı', 'I', 'Ş', 'ş', 'Ğ', 'ğ', 'Ü', 'ü', 'Ö', 'ö', 'Ç', 'ç'],
        ['i', 'i', 'i', 's', 's', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'],
        $text
    );
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]/', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Bir sayfada başlık satırını ve kolon haritasını tespit eder.
 * Başlık bulunamazsa null döner (VERİ/KOT/Kaşe gibi sayfalar böylece atlanır).
 */
function detectSheetMapping(array $rows): ?array {
    $headerRowIndex = -1;
    for ($i = 0; $i < min(15, count($rows)); $i++) {
        $nonEmpty = array_map('cleanHeader', $rows[$i]);
        if (in_array('irsaliye no', $nonEmpty) || in_array('irsaliye tarihi', $nonEmpty)
            || (in_array('tarih', $nonEmpty) && in_array('miktar', $nonEmpty))) {
            $headerRowIndex = $i;
            break;
        }
    }
    if ($headerRowIndex < 0) return null; // veri sayfası değil

    $colMapping = [];
    foreach ($rows[$headerRowIndex] as $colIdx => $hText) {
        $hText = cleanHeader($hText);
        if ($hText === '') continue;
        if (in_array($hText, ['sira', 'sira no', 'no']))                                   $colMapping['sira_no'] = $colIdx;
        elseif (in_array($hText, ['proje kodu', 'proje no', 'proje']))                     $colMapping['proje_kodu'] = $colIdx;
        elseif (in_array($hText, ['fatura no', 'fatura']))                                 $colMapping['fatura_no'] = $colIdx;
        elseif (in_array($hText, ['arac plaka no', 'plaka', 'arac plaka']))                $colMapping['arac_plaka'] = $colIdx;
        elseif (in_array($hText, ['kivam sinifi', 'kivam']))                               $colMapping['kivam_sinifi'] = $colIdx;
        elseif (in_array($hText, ['irsaliye no', 'irsaliye no.']))                         $colMapping['irsaliye_no'] = $colIdx;
        elseif (in_array($hText, ['tedarikci']))                                           $colMapping['tedarikci'] = $colIdx;
        elseif (in_array($hText, ['irsaliye tarihi', 'tarih']))                            $colMapping['tarih'] = $colIdx;
        elseif (in_array($hText, ['mikser cikis saati', 'mikser cikis']))                  $colMapping['mikser_cikis'] = $colIdx;
        elseif (in_array($hText, ['yildizlar kantar giris saati', 'kantar giris saati', 'kantar giris'])) $colMapping['kantar_giris'] = $colIdx;
        elseif (in_array($hText, ['yildizlar kantar cikis saati', 'kantar cikis saati', 'kantar cikis'])) $colMapping['kantar_cikis'] = $colIdx;
        elseif (in_array($hText, ['yildizlar kantar net', 'yildizlar net']))               $colMapping['kantar_net_yildiz'] = $colIdx;
        elseif (in_array($hText, ['tedarikci kantar net', 'tedarikci net']))               $colMapping['kantar_net_tedarikci'] = $colIdx;
        elseif (in_array($hText, ['kantar farki', 'fark']))                                $colMapping['kantar_farki'] = $colIdx;
        elseif (in_array($hText, ['beton sinifi', 'beton']))                               $colMapping['beton_sinifi'] = $colIdx;
        elseif (in_array($hText, ['miktar', 'm', 'm3', 'metre kup', 'metrekup']))          $colMapping['miktar'] = $colIdx;
        elseif (in_array($hText, ['birim']))                                               $colMapping['birim'] = $colIdx;
        elseif (in_array($hText, ['pompa durumu', 'pompa']))                               $colMapping['pompa'] = $colIdx;
        elseif (in_array($hText, ['katki 1', 'katki1']))                                   $colMapping['katki1'] = $colIdx;
        elseif (in_array($hText, ['katki 2', 'katki2']))                                   $colMapping['katki2'] = $colIdx;
        elseif (in_array($hText, ['firma']))                                               $colMapping['firma'] = $colIdx;
        elseif (in_array($hText, ['imalat ana grup', 'imalat grup', 'grup']))              $colMapping['imalat_grup'] = $colIdx;
        elseif (in_array($hText, ['ana is kalemi', 'is kalemi']))                          $colMapping['ana_is_kalemi'] = $colIdx;
        elseif (in_array($hText, ['parsel']))                                              $colMapping['parsel'] = $colIdx;
        elseif (in_array($hText, ['blok']))                                                $colMapping['blok'] = $colIdx;
        elseif (in_array($hText, ['kot', 'seviye']))                                       $colMapping['kot'] = $colIdx;
        elseif (in_array($hText, ['irsaliye aciklama', 'aciklama', 'not', 'notlar']))      $colMapping['aciklama'] = $colIdx;
    }
    // Şablon bilinen düzende: eksik eşleşmede varsayılan indekslere dön
    if (!isset($colMapping['irsaliye_no']) || !isset($colMapping['tarih'])) {
        $colMapping = [
            'sira_no' => 1, 'fatura_no' => 2, 'arac_plaka' => 3, 'kivam_sinifi' => 4,
            'irsaliye_no' => 5, 'tedarikci' => 6, 'tarih' => 8, 'mikser_cikis' => 9,
            'kantar_giris' => 10, 'kantar_cikis' => 11, 'kantar_net_yildiz' => 12,
            'kantar_net_tedarikci' => 13, 'kantar_farki' => 14, 'beton_sinifi' => 15,
            'miktar' => 16, 'birim' => 17, 'pompa' => 18, 'katki1' => 19, 'katki2' => 20,
            'firma' => 21, 'imalat_grup' => 22, 'ana_is_kalemi' => 23, 'parsel' => 24,
            'blok' => 25, 'kot' => 26, 'aciklama' => 27
        ];
    }
    return ['header_row' => $headerRowIndex, 'mapping' => $colMapping];
}

/**
 * Önizleme ÖN ANALİZİ — her veri satırının durumu AKTARIMLA AYNI kuralla belirlenir
 * (fat_irs_norm normalize: SKB2026-12047 ↔ SKB2026000012047; [FATURADAN] taslağı güncellenir;
 * aynı dosyada ikinci kez geçen no atlanır). Durumlar: yeni · taslak · kayitli · tekrar · hatali.
 * Eskiden önizleme UPPER(TRIM()) ile ayrı bir sorgu yapıyordu: biçim farklı no "Hazır" görünüp
 * aktarımda mükerrer düşüyor, taslağı güncelleyecek satır ise "Zaten Kayıtlı" diye kilitleniyordu.
 * Kayıtlı satırlarda Excel ↔ sistem miktar/tarih farkı bilgi olarak toplanır (kayıt DEĞİŞTİRİLMEZ).
 */
function importOnAnaliz(PDO $pdo, $xlsx, array $sheets): array {
    $mevcut = [];
    foreach ($pdo->query("SELECT id, irsaliye_no, aciklama, miktar, tarih FROM irsaliyeler
                          WHERE irsaliye_no IS NOT NULL AND TRIM(irsaliye_no) <> ''") as $mr) {
        $k = fat_irs_norm((string)$mr['irsaliye_no']);
        if ($k !== '' && !isset($mevcut[$k])) $mevcut[$k] = $mr;
    }
    $a = ['durum' => [], 'say' => ['okunan' => 0, 'yeni' => 0, 'taslak' => 0, 'kayitli' => 0, 'tekrar' => 0, 'hatali' => 0],
          'hatali' => [], 'farkli' => []];
    $dosyada = [];
    foreach ($sheets as $si => $S) {
        $rows = $xlsx->rows($si, 20000);
        $cm = $S['mapping'];
        for ($i = $S['header_row'] + 1, $n = count($rows); $i < $n; $i++) {
            $r = $rows[$i];
            if (!satirVeriMi($r, $cm)) continue;
            $g = fn($k) => isset($cm[$k], $r[$cm[$k]]) ? trim((string)$r[$cm[$k]]) : '';
            $a['say']['okunan']++;
            $no = $g('irsaliye_no'); $tarih = parseTarih($g('tarih'));
            $etiket = 'Satır ' . ($i + 1) . ($S['name'] ? ' · ' . $S['name'] : '') . ' (' . ($no ?: 'no yok') . ', ' . ($g('miktar') ?: '0') . ' m³)';
            if (!$tarih || $g('tedarikci') === '') {
                $d = 'hatali';
                $a['hatali'][] = $etiket . ': ' . (!$tarih ? 'geçersiz/boş tarih "' . $g('tarih') . '"' : 'tedarikçi boş');
            } else {
                $k = $no !== '' ? fat_irs_norm($no) : '';
                if ($k !== '' && isset($dosyada[$k])) {
                    $d = 'tekrar';
                } elseif ($k !== '' && isset($mevcut[$k])) {
                    $m = $mevcut[$k];
                    $d = str_contains((string)$m['aciklama'], '[FATURADAN]') ? 'taslak' : 'kayitli';
                    if ($d === 'kayitli') {
                        $mEx = parseMiktar($g('miktar')); $mDb = (float)$m['miktar'];
                        $fark = [];
                        if (abs($mEx - $mDb) > 0.001) $fark[] = 'miktar Excel ' . number_format($mEx, 2, ',', '.') . ' · sistem ' . number_format($mDb, 2, ',', '.');
                        if (substr((string)$tarih, 0, 10) !== substr((string)$m['tarih'], 0, 10)) $fark[] = 'tarih Excel ' . substr((string)$tarih, 0, 10) . ' · sistem ' . substr((string)$m['tarih'], 0, 10);
                        if ($fark) $a['farkli'][] = ['id' => (int)$m['id'], 'metin' => $etiket . ': ' . implode(' — ', $fark)];
                    }
                } else {
                    $d = 'yeni';
                }
                if ($k !== '') $dosyada[$k] = true;
            }
            $a['durum']["$si:$i"] = $d;
            $a['say'][$d]++;
        }
    }
    return $a;
}

/**
 * Dosyadaki irsaliye veri sayfaları: başlığı algılanan her sayfa (VERİ/KOT/Kaşe atlanır),
 * adında İADE geçen sayfa 'iade' tipiyle aktarılır.
 * @return array<int, array{name:string, tip:string, header_row:int, mapping:array}>
 */
function beton_sayfalar($xlsx): array {
    $sheets = [];
    foreach ($xlsx->sheetNames() as $si => $sName) {
        $rows = $xlsx->rows($si, 20000);
        if (!$rows) continue;
        $det = detectSheetMapping($rows);
        if ($det === null) continue;
        $sheets[$si] = [
            'name'       => $sName,
            'tip'        => (strpos(cleanHeader($sName), 'iade') !== false) ? 'iade' : 'alis',
            'header_row' => $det['header_row'],
            'mapping'    => $det['mapping'],
        ];
    }
    return $sheets;
}

/**
 * Excel saat hücresi → 'HH:MM:SS' (MySQL TIME). Okunamazsa null.
 * Elle yazımda ayraç karışabiliyor ("14;03", "14.03", "14,03") — gerçek dosyada tek bir
 * "14;03" hücresi MySQL'in "Incorrect time value" hatasıyla TÜM beton aktarımını geri aldırıyordu.
 * Excel'in kesirli gün değeri (0,5854 = 14:03) ve tarih-saat ("2026-11-12 14:03:00") de okunur.
 */
function beton_saat($v): ?string {
    $v = trim((string)$v);
    if ($v === '' || $v === '00:00:00' || $v === '00:00') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}[T ](\d{1,2}:\d{2}(?::\d{2})?)/', $v, $m)) $v = $m[1];
    if (is_numeric($v) && (float)$v > 0 && (float)$v < 1) {           // Excel kesirli gün
        $sn = (int)round((float)$v * 86400);
        return sprintf('%02d:%02d:%02d', intdiv($sn, 3600) % 24, intdiv($sn % 3600, 60), $sn % 60);
    }
    if (preg_match('/^(\d{1,2})\s*[:;.,]\s*(\d{2})(?:\s*[:;.,]\s*(\d{2}))?$/', $v, $m)
        && (int)$m[1] < 24 && (int)$m[2] < 60 && (int)($m[3] ?? 0) < 60) {
        if ((int)$m[1] === 0 && (int)$m[2] === 0 && (int)($m[3] ?? 0) === 0) return null;
        return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
    }
    return null;
}

/** Kayıtlı irsaliye numaraları — NORMALİZE no => ['id'=>int, 'taslak'=>bool] */
function beton_mevcut_no(PDO $pdo): array {
    $mevcutNo = [];
    foreach ($pdo->query("SELECT id, irsaliye_no, aciklama FROM irsaliyeler
                          WHERE irsaliye_no IS NOT NULL AND TRIM(irsaliye_no) <> ''") as $mr) {
        $mk = fat_irs_norm((string)$mr['irsaliye_no']);
        if ($mk !== '' && !isset($mevcutNo[$mk]))
            $mevcutNo[$mk] = ['id' => (int)$mr['id'], 'taslak' => str_contains((string)$mr['aciklama'], '[FATURADAN]')];
    }
    return $mevcutNo;
}

/**
 * Tek bir Excel satırını kaydeder (transaction ÇAĞIRANDADIR).
 * Dönüş: ['sonuc' => 'eklendi'|'guncellendi'|'atlandi'|'bos', 'neden' => düz metin]
 * $mevcutNo yerinde güncellenir (aynı dosyada ikinci kez geçen no mükerrer sayılır).
 */
function beton_satir_kaydet(PDO $pdo, array $r, array $colMapping, string $rowTip, array &$mevcutNo, ?int $uid, string $etiket = ''): array {
    if (!satirVeriMi($r, $colMapping)) return ['sonuc' => 'bos', 'neden' => ''];
    $val = function ($key, $default = null) use ($r, $colMapping) {
        if (isset($colMapping[$key]) && isset($r[$colMapping[$key]])) {
            $v = trim((string)$r[$colMapping[$key]]);
            return $v === '' ? $default : $v;
        }
        return $default;
    };
    $bas = $etiket . ' (' . $val('irsaliye_no', 'no?') . ', ' . $val('miktar', '0') . ' m³): ';

    $tarihRaw = $val('tarih');
    $tarih = parseTarih($tarihRaw ?? '');
    if (!$tarih) return ['sonuc' => 'atlandi', 'neden' => $bas . 'geçersiz/boş tarih "' . (string)$tarihRaw . '"'];
    $tedarikciAd = $val('tedarikci');
    if (!$tedarikciAd) return ['sonuc' => 'atlandi', 'neden' => $bas . 'tedarikçi boş'];

    // Mükerrer kontrolü (normalize). [FATURADAN] taslağıysa atlamak yerine GÜNCELLENİR.
    $irsaliyeNo = $val('irsaliye_no');
    $irsNorm  = $irsaliyeNo ? fat_irs_norm((string)$irsaliyeNo) : '';
    $taslakId = null;
    if ($irsNorm !== '' && isset($mevcutNo[$irsNorm])) {
        if ($mevcutNo[$irsNorm]['taslak']) $taslakId = $mevcutNo[$irsNorm]['id'];
        else return ['sonuc' => 'atlandi', 'neden' => $bas . 'mükerrer — bu irsaliye no zaten kayıtlı'];
    }

    $tedarikciId  = getOrCreate($pdo, 'tedarikciler', 'ad', $tedarikciAd);
    $betonId      = getOrCreate($pdo, 'beton_siniflari', 'ad', $val('beton_sinifi'));
    $pompaId      = getOrCreate($pdo, 'pompa_turleri',   'ad', $val('pompa'));
    $katki1Id     = getOrCreate($pdo, 'katki_listesi',   'ad', $val('katki1'));
    $katki2Id     = getOrCreate($pdo, 'katki_listesi',   'ad', $val('katki2'));
    $firmaId      = getOrCreate($pdo, 'firmalar',        'ad', $val('firma'));
    $kivamId      = getOrCreate($pdo, 'kivam_siniflari', 'ad', $val('kivam_sinifi'));
    $imalatGrupId = getOrCreateImalat($pdo, $val('imalat_grup'));
    $anaKalemId   = $imalatGrupId ? getOrCreateAnaKalem($pdo, $imalatGrupId, $val('ana_is_kalemi')) : null;
    $parselId     = getOrCreateParsel($pdo, $val('parsel'));
    $blokId       = $parselId ? getOrCreateBlok($pdo, $parselId, $val('blok')) : null;
    $kotId        = $blokId   ? getOrCreateKot($pdo, $blokId, $val('kot'))     : null;
    $projeId      = getOrCreateProje($pdo, $val('proje_kodu'));

    // Saat okunamazsa satır yine aktarılır, saat boş kalır ve not düşülür (aktarım geri alınmasın)
    $notlar = [];
    $saat = function (string $alan, string $ad) use ($val, &$notlar, $bas) {
        $ham = $val($alan);
        $s = beton_saat($ham);
        if ($s === null && $ham !== null && !preg_match('/(^|[T ])0{1,2}[:;.]00([:;.]00)?$|^0$/', trim((string)$ham)))   // gece yarısı = boş
            $notlar[] = $bas . "$ad \"$ham\" okunamadı — boş bırakıldı";
        return $s;
    };
    $ortak = [
        $rowTip,
        $val('sira_no'), $val('fatura_no'), $val('arac_plaka'),
        $kivamId, $irsaliyeNo,
        $val('proje_kodu'), $projeId,
        $tedarikciId, $tarih,
        $saat('mikser_cikis', 'mikser çıkış saati'), $saat('kantar_giris', 'kantar giriş saati'), $saat('kantar_cikis', 'kantar çıkış saati'),
        parseMiktar($val('kantar_net_yildiz', 0)),
        parseMiktar($val('kantar_net_tedarikci', 0)),
        parseMiktar($val('kantar_farki', 0)),
        $betonId, parseMiktar($val('miktar', 0)), $val('birim', 'M3'), $pompaId,
        $katki1Id, $katki2Id, $firmaId,
        $imalatGrupId, $anaKalemId,
        $parselId, $blokId, $kotId, $val('aciklama'),
    ];

    if ($taslakId !== null) {
        // [FATURADAN] taslağı Excel verisiyle güncelle: id sabit kalır, fatura bağı ve ekler korunur.
        // Excel'de fatura no boşsa taslağın fatura no'su ezilmez.
        $pdo->prepare("UPDATE irsaliyeler SET
            tip=?, sira_no=?, fatura_no=COALESCE(?, fatura_no), arac_plaka=?, kivam_sinifi_id=?, irsaliye_no=?,
            proje_no=?, proje_id=?, tedarikci_id=?, tarih=?,
            mikser_cikis_saati=?, kantar_giris_saati=?, kantar_cikis_saati=?,
            kantar_net_yildizlar=?, kantar_net_tedarikci=?, kantar_farki=?,
            beton_sinifi_id=?, miktar=?, birim=?, pompa_id=?,
            katki1_id=?, katki2_id=?, firma_id=?, imalat_grup_id=?, ana_is_kalemi_id=?,
            parsel_id=?, blok_id=?, kot_id=?, aciklama=?, updated_by=?
            WHERE id = ?")->execute([...$ortak, $uid, $taslakId]);
        $mevcutNo[$irsNorm]['taslak'] = false;   // artık gerçek kayıt
        return ['sonuc' => 'guncellendi', 'neden' => '', 'not' => $notlar];
    }
    $pdo->prepare("INSERT INTO irsaliyeler
        (tip, sira_no, fatura_no, arac_plaka, kivam_sinifi_id, irsaliye_no,
         proje_no, proje_id, tedarikci_id, tarih,
         mikser_cikis_saati, kantar_giris_saati, kantar_cikis_saati,
         kantar_net_yildizlar, kantar_net_tedarikci, kantar_farki,
         beton_sinifi_id, miktar, birim, pompa_id,
         katki1_id, katki2_id, firma_id, imalat_grup_id, ana_is_kalemi_id,
         parsel_id, blok_id, kot_id, aciklama, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([...$ortak, $uid]);
    if ($irsNorm !== '') $mevcutNo[$irsNorm] = ['id' => (int)$pdo->lastInsertId(), 'taslak' => false];
    return ['sonuc' => 'eklendi', 'neden' => '', 'not' => $notlar];
}

/**
 * ÖNİZLEMESİZ aktarım (günlük toplu yükleme): dosyadaki YENİ irsaliyeler eklenir,
 * [FATURADAN] taslakları güncellenir; kayıtlı olanlar DEĞİŞTİRİLMEZ (fark raporlanır).
 * import.php'de "tümünü seç → aktar" ile birebir aynı sonuçtur. Tam yenileme YAPMAZ.
 * @throws Throwable  hata olursa transaction geri alınmış olarak fırlatır
 */
function beton_import_otomatik(PDO $pdo, $xlsx, ?int $uid): array {
    $imalat = 0;
    try { $imalat = storeImalatSheets($pdo, $xlsx); } catch (Throwable $e) { $imalat = 0; }
    $sheets = beton_sayfalar($xlsx);
    $s = ['sayfa' => array_column($sheets, 'name'), 'imalat' => $imalat,
          'okunan' => 0, 'eklendi' => 0, 'guncellendi' => 0, 'kayitli' => 0, 'tekrar' => 0, 'hatali' => 0,
          'atlanan' => [], 'farkli' => [], 'not' => [], 'm3' => 0.0];
    if (!$sheets) return $s;
    $an = importOnAnaliz($pdo, $xlsx, $sheets);
    foreach (['okunan', 'kayitli', 'tekrar'] as $k) $s[$k] = $an['say'][$k];
    $s['farkli'] = $an['farkli'];
    $s['atlanan'] = $an['hatali'];
    $s['hatali'] = $an['say']['hatali'];
    if ($an['say']['yeni'] + $an['say']['taslak'] === 0) return $s;

    $pdo->beginTransaction();
    try {
        $mevcutNo = beton_mevcut_no($pdo);
        foreach ($sheets as $si => $S) {
            $rows = $xlsx->rows($si, 20000);
            foreach ($rows as $i => $r) {
                $d = $an['durum']["$si:$i"] ?? null;
                if ($d !== 'yeni' && $d !== 'taslak') continue;
                $sn = beton_satir_kaydet($pdo, $r, $S['mapping'], $S['tip'], $mevcutNo, $uid, 'Satır ' . ($i + 1) . ' · ' . $S['name']);
                if ($sn['sonuc'] === 'eklendi' || $sn['sonuc'] === 'guncellendi') {
                    $s[$sn['sonuc']]++;
                    $s['m3'] += parseMiktar($r[$S['mapping']['miktar'] ?? -1] ?? 0);
                    foreach ($sn['not'] ?? [] as $nt) $s['not'][] = $nt;
                } elseif ($sn['sonuc'] === 'atlandi') {
                    $s['hatali']++; $s['atlanan'][] = $sn['neden'];
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $s;
}
