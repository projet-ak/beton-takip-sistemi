<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin', 'teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fatura.php';   // fat_irs_norm: ANM2026-4710 ↔ ANM2026000004710 aynı belgedir
require_once __DIR__ . '/vendor/autoload.php';

use Shuchkin\SimpleXLSX;

$pageTitle = 'Dinamik Excel Aktarımı — Beton Takip Sistemi';

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

$error = null;
$success = null;

// CSRF token (küresel — merkezi doğrulama auth.php'de)
$csrfImport = csrf_token();

// Oturum temizliği / sıfırlama
if (isset($_GET['reset'])) {
    if (isset($_SESSION['import_file']) && file_exists($_SESSION['import_file'])) {
        @unlink($_SESSION['import_file']);
    }
    unset($_SESSION['import_file'], $_SESSION['import_col_mapping'],
          $_SESSION['import_sheet_idx'], $_SESSION['import_header_row_idx'], $_SESSION['import_sheets']);
    redirect('import.php');
}

// 24 saatten eski geçici import dosyalarını temizle
foreach (glob(__DIR__ . '/uploads/tmp_import_*.xlsx') ?: [] as $oldTmp) {
    if (filemtime($oldTmp) < time() - 86400) @unlink($oldTmp);
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

// CSRF doğrulama (tüm POST'lar için)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'Güvenlik hatası. Sayfayı yenileyip tekrar deneyin.';
    }
}

// ADIM 1: Dosya Yükleme ve Önizleme Oluşturma
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Dosya yüklenirken bir hata oluştu.";
    } else {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (strtolower($ext) !== 'xlsx') {
            $error = "Yalnızca Excel (.xlsx) dosyaları desteklenmektedir.";
        } else {
            $tempPath = __DIR__ . '/uploads/tmp_import_' . current_user()['id'] . '.xlsx';
            if (move_uploaded_file($file['tmp_name'], $tempPath)) {
                $_SESSION['import_file'] = $tempPath;

                // TÜM SAYFALARI otomatik tara: başlığı algılanan her sayfa veri sayfasıdır.
                // Sayfa adında İADE geçiyorsa kayıtlar 'iade' tipiyle aktarılır.
                if ($xlsx = SimpleXLSX::parse($tempPath)) {
                    // İmalat/metraj/zayiat sayfalarını (PRP Bina Üstyapı, İcmal, Metraj …)
                    // aynı yüklemede metraj_sayfa'ya kaydet — ayrı ekranlardan görüntülenir.
                    try { $_SESSION['import_imalat_sayi'] = storeImalatSheets($pdo, $xlsx); } catch (Throwable $e) { $_SESSION['import_imalat_sayi'] = 0; }
                    $sheets = [];
                    foreach ($xlsx->sheetNames() as $si => $sName) {
                        $rows = $xlsx->rows($si, 20000);
                        if (!$rows) continue;
                        $det = detectSheetMapping($rows);
                        if ($det === null) continue; // VERİ / KOT / Kaşe gibi sayfalar atlanır
                        $adNorm = cleanHeader($sName);
                        $sheets[$si] = [
                            'name'       => $sName,
                            'tip'        => (strpos($adNorm, 'iade') !== false) ? 'iade' : 'alis',
                            'header_row' => $det['header_row'],
                            'mapping'    => $det['mapping'],
                        ];
                    }
                    if (!$sheets) {
                        $error = "Bu dosyada irsaliye veri sayfası bulunamadı (başlık satırı algılanamadı).";
                        @unlink($tempPath);
                        unset($_SESSION['import_file']);
                    } else {
                        $_SESSION['import_sheets'] = $sheets;
                    }
                } else {
                    $error = "Excel dosyası okunamadı: " . SimpleXLSX::parseError();
                    @unlink($tempPath);
                    unset($_SESSION['import_file']);
                }
            } else {
                $error = "Dosya sunucuya kaydedilemedi.";
            }
        }
    }
}

// ADIM 2: Seçilen Satırları Aktarma
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_import'])) {
    if (!isset($_SESSION['import_file']) || !file_exists($_SESSION['import_file'])) {
        $error = "Yüklü Excel dosyası bulunamadı. Lütfen tekrar yükleyin.";
    } else {
        $selectedIndices = isset($_POST['rows']) ? $_POST['rows'] : [];
        if (empty($selectedIndices)) {
            $error = "Aktarmak için en az bir satır seçmelisiniz.";
        } else {
            $tempPath = $_SESSION['import_file'];
            $sheets = $_SESSION['import_sheets'] ?? [];
            if (!$sheets) { $error = "Sayfa bilgisi bulunamadı — dosyayı yeniden yükleyin."; }

            if (!$error && ($xlsx = SimpleXLSX::parse($tempPath))) {
                // Seçimleri sayfa bazında grupla ("si:rowIdx" formatı)
                $secimler = [];
                foreach ($selectedIndices as $sel) {
                    if (!preg_match('/^(\d+):(\d+)$/', (string)$sel, $m)) continue;
                    $secimler[(int)$m[1]][] = (int)$m[2];
                }
                $rowsCache = [];
                $added = 0; $skipped = 0; $guncellenen = 0; $errors = []; $silinenTum = 0;
                $fotoSnapshot = []; $fotoReattach = 0; $fotoOrphan = 0;
                $fatSnapshot = []; $fatReattach = 0; $fatOrphan = 0;
                $atlananlar = []; // atlanan satırların nedenleriyle raporu
                $resetAll = isset($_POST['reset_all']) && is_admin();

                $pdo->beginTransaction();
                try {
                    if ($resetAll) {
                        // Fotoğraf/belgeleri koru: silmeden önce irsaliye_no ile snapshot al
                        // (CASCADE ile DB kaydı silinir; dosyalar diskte kalır → import sonrası
                        //  aynı irsaliye_no'ya sahip yeni kayda yeniden bağlanır).
                        try {   // tur/okunan runtime kolonları (blg_semasi_kur) — yoksa sade sürüm
                            $fotoSnapshot = $pdo->query("
                                SELECT i.irsaliye_no, f.dosya_adi, f.dosya_yolu, f.created_by, f.tur, f.okunan
                                FROM irsaliye_fotolar f JOIN irsaliyeler i ON i.id = f.irsaliye_id
                                WHERE i.irsaliye_no IS NOT NULL AND TRIM(i.irsaliye_no) <> ''")->fetchAll();
                        } catch (Throwable $eSnap) {
                            $fotoSnapshot = $pdo->query("
                                SELECT i.irsaliye_no, f.dosya_adi, f.dosya_yolu, f.created_by
                                FROM irsaliye_fotolar f JOIN irsaliyeler i ON i.id = f.irsaliye_id
                                WHERE i.irsaliye_no IS NOT NULL AND TRIM(i.irsaliye_no) <> ''")->fetchAll();
                        }
                        // Fatura bağlarını koru (irsaliyeler.fatura_id runtime kolonu — yoksa atla)
                        try {
                            $fatSnapshot = $pdo->query("
                                SELECT irsaliye_no, fatura_id FROM irsaliyeler
                                WHERE fatura_id IS NOT NULL AND irsaliye_no IS NOT NULL AND TRIM(irsaliye_no) <> ''")->fetchAll();
                        } catch (Throwable $eSnap) { $fatSnapshot = []; }
                        // TAM YENİLEME: tüm mevcut irsaliyeler silinir
                        $silinenTum = (int)$pdo->query("SELECT COUNT(*) FROM irsaliyeler")->fetchColumn();
                        $pdo->exec("DELETE FROM irsaliyeler");
                        audit_log($pdo, 'irsaliyeler', 0, 'DELETE', null, ['tam_yenileme'=>true, 'silinen'=>$silinenTum]);
                    }

                    // Mevcut numaraları NORMALİZE indeksle: biçim farkı (tire/sıfır dolgu) mükerrer
                    // kontrolünü atlatamaz. Fatura eşleştirmeden açılan [FATURADAN] taslakları
                    // Excel satırı SİLMEDEN GÜNCELLER — fatura bağı ve ekli belgeler korunur.
                    $mevcutNo = [];   // norm no => ['id'=>int, 'taslak'=>bool]
                    foreach ($pdo->query("SELECT id, irsaliye_no, aciklama FROM irsaliyeler
                                          WHERE irsaliye_no IS NOT NULL AND TRIM(irsaliye_no) <> ''") as $mr) {
                        $mk = fat_irs_norm((string)$mr['irsaliye_no']);
                        if ($mk !== '' && !isset($mevcutNo[$mk]))
                            $mevcutNo[$mk] = ['id' => (int)$mr['id'],
                                              'taslak' => str_contains((string)$mr['aciklama'], '[FATURADAN]')];
                    }
                    foreach ($secimler as $sheetIdx => $idxler) {
                    if (!isset($sheets[$sheetIdx])) continue;
                    $colMapping = $sheets[$sheetIdx]['mapping'];
                    $rowTip     = $sheets[$sheetIdx]['tip'];
                    if (!isset($rowsCache[$sheetIdx])) $rowsCache[$sheetIdx] = $xlsx->rows($sheetIdx, 20000);
                    $rows = $rowsCache[$sheetIdx];
                    foreach ($idxler as $idx) {
                        if (!isset($rows[$idx])) continue;
                        $r = $rows[$idx];
                        // Formülle uzatılmış sahte satır seçilmişse sessizce atla (rapora girmesin)
                        if (!satirVeriMi($r, $colMapping)) continue;

                        // Sütun verilerini eşle
                        $val = function($key, $default = null) use ($r, $colMapping) {
                            if (isset($colMapping[$key]) && isset($r[$colMapping[$key]])) {
                                $v = trim($r[$colMapping[$key]]);
                                return $v === '' ? $default : $v;
                            }
                            return $default;
                        };
                        
                        $tarihRaw = $val('tarih');
                        $tarih = parseTarih($tarihRaw ?? '');
                        if (!$tarih) {
                            $skipped++;
                            $atlananlar[] = "Satır ".($idx+1)." (".h($val('irsaliye_no','no?')).", ".h($val('miktar','0'))." m³): geçersiz/boş tarih \"".h((string)$tarihRaw)."\"";
                            continue;
                        }

                        $tedarikciAd = $val('tedarikci');
                        if (!$tedarikciAd) {
                            $skipped++;
                            $atlananlar[] = "Satır ".($idx+1)." (".h($val('irsaliye_no','no?')).", ".h($val('miktar','0'))." m³): tedarikçi boş";
                            continue;
                        }
                        $tedarikciId = getOrCreate($pdo, 'tedarikciler', 'ad', $tedarikciAd);
                        
                        $betonId    = getOrCreate($pdo, 'beton_siniflari', 'ad',  $val('beton_sinifi'));
                        $pompaId    = getOrCreate($pdo, 'pompa_turleri',   'ad',  $val('pompa'));
                        $katki1Id   = getOrCreate($pdo, 'katki_listesi',   'ad',  $val('katki1'));
                        $katki2Id   = getOrCreate($pdo, 'katki_listesi',   'ad',  $val('katki2'));
                        $firmaId    = getOrCreate($pdo, 'firmalar',        'ad',  $val('firma'));
                        $kivamId    = getOrCreate($pdo, 'kivam_siniflari', 'ad',  $val('kivam_sinifi'));
                        
                        $imalatGrupId  = getOrCreateImalat($pdo, $val('imalat_grup'));
                        $anaKalemId    = $imalatGrupId ? getOrCreateAnaKalem($pdo, $imalatGrupId, $val('ana_is_kalemi')) : null;
                        $parselId      = getOrCreateParsel($pdo, $val('parsel'));
                        $blokId        = $parselId ? getOrCreateBlok($pdo, $parselId, $val('blok')) : null;
                        $kotId         = $blokId   ? getOrCreateKot($pdo, $blokId, $val('kot'))   : null;
                        
                        $irsaliyeNo = $val('irsaliye_no');

                        // İrsaliye no ile mükerrer kontrolü (normalize: SKB2026-12047 ↔ SKB2026000012047).
                        // [FATURADAN] taslağıysa atlamak yerine GÜNCELLENİR (bağlar korunur).
                        $irsNorm  = $irsaliyeNo ? fat_irs_norm((string)$irsaliyeNo) : '';
                        $taslakId = null;
                        if ($irsNorm !== '' && isset($mevcutNo[$irsNorm])) {
                            if ($mevcutNo[$irsNorm]['taslak']) {
                                $taslakId = $mevcutNo[$irsNorm]['id'];
                            } else {
                                $skipped++;
                                $atlananlar[] = "Satır ".($idx+1)." (".h($irsaliyeNo).", ".h($val('miktar','0'))." m³): mükerrer — bu irsaliye no zaten kayıtlı";
                                continue;
                            }
                        }

                        $projeId = getOrCreateProje($pdo, $val('proje_kodu'));
                        
                        $mikserCikis = $val('mikser_cikis');
                        $kantarGiris = $val('kantar_giris');
                        $kantarCikis = $val('kantar_cikis');
                        
                        $mikserCikis = ($mikserCikis && $mikserCikis !== '00:00:00') ? $mikserCikis : null;
                        $kantarGiris = ($kantarGiris && $kantarGiris !== '00:00:00') ? $kantarGiris : null;
                        $kantarCikis = ($kantarCikis && $kantarCikis !== '00:00:00') ? $kantarCikis : null;
                        
                        $miktarVal = parseMiktar($val('miktar', 0));

                        $ortak = [
                            $rowTip,
                            $val('sira_no'), $val('fatura_no'), $val('arac_plaka'),
                            $kivamId, $irsaliyeNo,
                            $val('proje_kodu'), $projeId,
                            $tedarikciId, $tarih,
                            $mikserCikis, $kantarGiris, $kantarCikis,
                            parseMiktar($val('kantar_net_yildiz', 0)),
                            parseMiktar($val('kantar_net_tedarikci', 0)),
                            parseMiktar($val('kantar_farki', 0)),
                            $betonId, $miktarVal, $val('birim', 'M3'), $pompaId,
                            $katki1Id, $katki2Id, $firmaId,
                            $imalatGrupId, $anaKalemId,
                            $parselId, $blokId, $kotId, $val('aciklama'),
                        ];

                        if ($taslakId !== null) {
                            // [FATURADAN] taslağı Excel verisiyle güncelle: id sabit kalır,
                            // fatura_id bağı ve irsaliye_fotolar ekleri olduğu gibi korunur.
                            // Excel'de fatura no boşsa taslağın fatura no'su ezilmez.
                            $stmt = $pdo->prepare("UPDATE irsaliyeler SET
                                tip=?, sira_no=?, fatura_no=COALESCE(?, fatura_no), arac_plaka=?, kivam_sinifi_id=?, irsaliye_no=?,
                                proje_no=?, proje_id=?,
                                tedarikci_id=?, tarih=?,
                                mikser_cikis_saati=?, kantar_giris_saati=?, kantar_cikis_saati=?,
                                kantar_net_yildizlar=?, kantar_net_tedarikci=?, kantar_farki=?,
                                beton_sinifi_id=?, miktar=?, birim=?, pompa_id=?,
                                katki1_id=?, katki2_id=?, firma_id=?,
                                imalat_grup_id=?, ana_is_kalemi_id=?,
                                parsel_id=?, blok_id=?, kot_id=?, aciklama=?, updated_by=?
                                WHERE id = ?");
                            $stmt->execute([...$ortak, current_user()['id'], $taslakId]);
                            $mevcutNo[$irsNorm]['taslak'] = false;   // artık gerçek kayıt
                            $guncellenen++;
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO irsaliyeler
                                (tip, sira_no, fatura_no, arac_plaka, kivam_sinifi_id, irsaliye_no,
                                 proje_no, proje_id,
                                 tedarikci_id, tarih,
                                 mikser_cikis_saati, kantar_giris_saati, kantar_cikis_saati,
                                 kantar_net_yildizlar, kantar_net_tedarikci, kantar_farki,
                                 beton_sinifi_id, miktar, birim, pompa_id,
                                 katki1_id, katki2_id, firma_id,
                                 imalat_grup_id, ana_is_kalemi_id,
                                 parsel_id, blok_id, kot_id, aciklama, created_by)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                            $stmt->execute([...$ortak, current_user()['id']]);
                            if ($irsNorm !== '')
                                $mevcutNo[$irsNorm] = ['id' => (int)$pdo->lastInsertId(), 'taslak' => false];
                            $added++;
                        }
                    }
                    } // sayfa döngüsü sonu

                    // Fotoğraf/belge ve fatura bağlarını yeniden bağla: snapshot'takiler, aynı
                    // (NORMALİZE) irsaliye no'ya sahip yeni kayda eklenir — biçim farkı
                    // (SKB2026-12047 ↔ SKB2026000012047) bağın kopmasına yol açmaz.
                    if ($resetAll && ($fotoSnapshot || $fatSnapshot)) {
                        $noMap = [];
                        foreach ($pdo->query("SELECT id, irsaliye_no FROM irsaliyeler WHERE irsaliye_no IS NOT NULL AND TRIM(irsaliye_no)<>''") as $ir) {
                            $key = fat_irs_norm((string)$ir['irsaliye_no']);
                            if ($key !== '' && !isset($noMap[$key])) $noMap[$key] = (int)$ir['id']; // ilk eşleşme
                        }
                        if ($fotoSnapshot) {
                            $blgVar  = array_key_exists('tur', $fotoSnapshot[0] ?? []);   // tur/okunan kolonları var mı
                            $insFoto = $blgVar
                                ? $pdo->prepare("INSERT INTO irsaliye_fotolar (irsaliye_id, dosya_adi, dosya_yolu, created_by, tur, okunan) VALUES (?,?,?,?,?,?)")
                                : $pdo->prepare("INSERT INTO irsaliye_fotolar (irsaliye_id, dosya_adi, dosya_yolu, created_by) VALUES (?,?,?,?)");
                            foreach ($fotoSnapshot as $f) {
                                $key = fat_irs_norm((string)$f['irsaliye_no']);
                                if (isset($noMap[$key])) {
                                    $insFoto->execute($blgVar
                                        ? [$noMap[$key], $f['dosya_adi'], $f['dosya_yolu'], $f['created_by'], $f['tur'], $f['okunan']]
                                        : [$noMap[$key], $f['dosya_adi'], $f['dosya_yolu'], $f['created_by']]);
                                    $fotoReattach++;
                                } else $fotoOrphan++;
                            }
                        }
                        if ($fatSnapshot) {
                            $updFat = $pdo->prepare("UPDATE irsaliyeler SET fatura_id = ? WHERE id = ?");
                            foreach ($fatSnapshot as $f) {
                                $key = fat_irs_norm((string)$f['irsaliye_no']);
                                if (isset($noMap[$key])) { $updFat->execute([(int)$f['fatura_id'], $noMap[$key]]); $fatReattach++; }
                                else $fatOrphan++;
                            }
                        }
                    }

                    $pdo->commit();
                    $success = ($resetAll ? "TAM YENİLEME: önce {$silinenTum} mevcut kayıt silindi. " : '')
                             . "Aktarım işlemi tamamlandı! $added kayıt eklendi"
                             . ($guncellenen ? ", $guncellenen faturadan açılmış taslak gerçek verilerle güncellendi (bağlar korundu)" : '')
                             . ", $skipped mükerrer veya geçersiz kayıt atlandı."
                             . (($resetAll && ($fotoReattach || $fotoOrphan)) ? " {$fotoReattach} fotoğraf/belge yeniden bağlandı" . ($fotoOrphan ? ", {$fotoOrphan} eşleşmedi (irsaliye artık Excel'de yok)" : '') . "." : '')
                             . (($resetAll && ($fatReattach || $fatOrphan)) ? " {$fatReattach} fatura bağı korundu" . ($fatOrphan ? ", {$fatOrphan} fatura bağı eşleşmedi" : '') . "." : '');

                    // Oturum dosyalarını temizle
                    @unlink($tempPath);
                    unset($_SESSION['import_file']);
                    unset($_SESSION['import_col_mapping']);
                    unset($_SESSION['import_sheet_idx']);
                    unset($_SESSION['import_sheets']);

                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = "Aktarım durduruldu. Veritabanı hatası: " . $e->getMessage();
                }
            } elseif (!$error) {
                $error = "Excel dosyası okunamadı: " . SimpleXLSX::parseError();
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="page-title h3 mb-0"><i class="bi bi-file-earmark-spreadsheet text-primary me-2"></i>Dinamik Excel Aktarımı</h1>
    <a href="irsaliyeler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>İrsaliyelere Dön</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div><?= h($error) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div><?= h($success) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php if (!empty($atlananlar)): ?>
    <div class="alert alert-warning" role="alert">
        <h6 class="fw-semibold mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Atlanan Satırlar (<?= count($atlananlar) ?>) — Excel toplamı ile fark bunlardan kaynaklanır:</h6>
        <ul class="mb-0 small">
            <?php foreach (array_slice($atlananlar, 0, 60) as $a): ?><li><?= $a ?></li><?php endforeach; ?>
            <?php if (count($atlananlar) > 60): ?><li>… ve <?= count($atlananlar)-60 ?> satır daha</li><?php endif; ?>
        </ul>
        <hr class="my-2"><div class="small">Tam mutabakat için: <a href="veri_kontrol.php" class="alert-link">Veri Kontrol → Excel Mutabakatı</a></div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (!isset($_SESSION['import_file'])): ?>
    <!-- ADIM 0: Dosya Yükleme Formu -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="card-title mb-0 fw-semibold text-muted">
                <i class="bi bi-cloud-upload text-primary me-1"></i> Excel Dosyası Yükle
            </h5>
        </div>
        <div class="card-body p-4">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= h($csrfImport) ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-9">
                        <label class="form-label fw-medium">Excel Dosyası (.xlsx)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-eye me-1"></i> Yükle &amp; İncele
                        </button>
                    </div>
                </div>
            </form>
            <div class="mt-4">
                <div class="alert alert-info border-0 bg-light-primary text-primary-emphasis mb-0 p-3 rounded-3">
                    <h6 class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i> Nasıl Çalışır?</h6>
                    <ul class="mb-0 small ps-3">
                        <li><strong>Tüm sayfalar otomatik taranır</strong> — sayfa seçmenize gerek yok. Başlık satırı algılanan her sayfa (ALIŞLAR, Sayfa1, İADE...) veri sayfası olarak okunur; VERİ/KOT/Kaşe gibi tanım sayfaları atlanır.</li>
                        <li>Adında <strong>İADE</strong> geçen sayfadaki kayıtlar otomatik <strong>iade</strong> tipiyle aktarılır, diğerleri <strong>alış</strong>.</li>
                        <li>Sütun isimleri otomatik haritalanır; bilinen şablonda eşleşmeyen kolonlar için varsayılan indeksler kullanılır.</li>
                        <li>Mükerrer irsaliye no'lar (büyük/küçük harf ve boşluk farkı gözetmeksizin) atlanır — aynı dosyayı tekrar yüklemek güvenlidir.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php else:
    // ADIM 1: Yüklenen dosyanın TÜM veri sayfalarını önizle
    $xlsx = SimpleXLSX::parse($_SESSION['import_file']);
    $sheets = $_SESSION['import_sheets'] ?? [];
    if ($xlsx && $sheets):
        $dupQ = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE UPPER(TRIM(irsaliye_no)) = UPPER(TRIM(?))");
        $sayfaOzet = [];
        foreach ($sheets as $si => $S) { $sayfaOzet[] = h($S['name']) . ($S['tip']==='iade' ? ' (iade)' : ''); }
    ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="card-title mb-0 fw-semibold text-muted">
                    <i class="bi bi-eye text-success me-1"></i> Excel Önizlemesi — Tüm Sayfalar
                </h5>
                <small class="text-muted">Algılanan veri sayfaları: <strong><?= implode(' · ', $sayfaOzet) ?></strong></small>
                <?php if (!empty($_SESSION['import_imalat_sayi'])): ?>
                <div class="small text-success mt-1"><i class="bi bi-check-circle me-1"></i><strong><?= (int)$_SESSION['import_imalat_sayi'] ?></strong> imalat/metraj sayfası sisteme kaydedildi — <a href="prp_ustyapi.php">Bina Üstyapı</a> · <a href="temel_kazik.php">Temel & Kazık</a> · <a href="istinat.php">İstinat</a> · <a href="icmal_beton.php">İcmal</a> · <a href="metraj_sayfasi.php">Metraj</a> · <a href="mobilizasyon.php">Mobilizasyon</a> ekranlarından görüntüleyin.</div>
                <?php endif; ?>
            </div>
            <div>
                <a href="import.php?reset=1" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-x-circle me-1"></i> Dosyayı İptal Et
                </a>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($csrfImport) ?>">
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 550px; overflow-y: auto;">
                    <table class="table table-striped table-hover align-middle mb-0 small">
                        <thead class="table-light sticky-top" style="z-index: 10;">
                            <tr>
                                <th width="40" class="text-center">
                                    <input type="checkbox" class="form-check-input" id="selectAll" checked>
                                </th>
                                <th width="60">Satır</th>
                                <th>Tip</th>
                                <th>İrsaliye No</th>
                                <th>Proje</th>
                                <th>Tedarikçi</th>
                                <th>Tarih</th>
                                <th>Beton Sınıfı</th>
                                <th class="text-end">Miktar (m³)</th>
                                <th>Pompa</th>
                                <th>Parsel / Blok / Kot</th>
                                <th>Açıklama</th>
                                <th>Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $hasValidRows = false;
                            foreach ($sheets as $si => $S):
                                $rows = $xlsx->rows($si, 20000);
                                $colMapping = $S['mapping'];
                                $dataStartIdx = $S['header_row'] + 1;
                                $totalRows = count($rows);
                            ?>
                            <tr class="table-secondary">
                                <td colspan="13" class="fw-semibold py-2">
                                    <i class="bi bi-file-earmark-spreadsheet me-1"></i><?= h($S['name']) ?>
                                    <?php if ($S['tip']==='iade'): ?><span class="badge bg-danger ms-1">İADE olarak aktarılır</span>
                                    <?php else: ?><span class="badge bg-success ms-1">ALIŞ</span><?php endif; ?>
                                </td>
                            </tr>
                            <?php for ($i = $dataStartIdx; $i < $totalRows; $i++):
                                $r = $rows[$i];
                                // Formülle uzatılmış sahte satırları ele (sıra no / 0 dolu ama veri yok)
                                if (!satirVeriMi($r, $colMapping)) continue;
                                $hasValidRows = true;

                                $getVal = function($key) use ($r, $colMapping) {
                                    return isset($colMapping[$key]) && isset($r[$colMapping[$key]]) ? trim($r[$colMapping[$key]]) : '';
                                };
                                $irsaliyeNo = $getVal('irsaliye_no');
                                $projeKodu = $getVal('proje_kodu');
                                $tedarikci = $getVal('tedarikci');
                                $tarihRaw = $getVal('tarih');
                                $tarih = parseTarih($tarihRaw ?? '');
                                $betonSinifi = $getVal('beton_sinifi');
                                $miktar = $getVal('miktar');
                                $pompa = $getVal('pompa');
                                $parsel = $getVal('parsel');
                                $blok = $getVal('blok');
                                $kot = $getVal('kot');
                                $aciklama = $getVal('aciklama');

                                $dupColor = ''; $dupText = 'Hazır'; $canImport = true;
                                if ($irsaliyeNo) {
                                    $dupQ->execute([$irsaliyeNo]);
                                    if ($dupQ->fetchColumn() > 0) {
                                        $dupColor = 'table-success'; $dupText = 'Zaten Kayıtlı'; $canImport = false;
                                    }
                                }
                                if (!$tarih || !$tedarikci) {
                                    $dupColor = 'table-danger'; $dupText = 'Hatalı (Tarih/Tedarikçi Yok)'; $canImport = false;
                                }
                            ?>
                            <tr class="<?= $dupColor ?>">
                                <td class="text-center">
                                    <input type="checkbox" name="rows[]" value="<?= $si ?>:<?= $i ?>" class="form-check-input row-select"
                                           data-reason="<?= $canImport ? 'ok' : ($dupText === 'Zaten Kayıtlı' ? 'dup' : 'err') ?>"
                                           <?= $canImport ? 'checked' : '' ?> <?= $canImport ? '' : 'disabled' ?>>
                                </td>
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td><?= $S['tip']==='iade' ? '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">İade</span>' : '<span class="badge bg-success-subtle text-success border border-success-subtle">Alış</span>' ?></td>
                                <td class="font-monospace fw-semibold"><?= h($irsaliyeNo ?: '-') ?></td>
                                <td><?= $projeKodu ? '<span class="badge bg-dark">'.h($projeKodu).'</span>' : '<span class="text-muted">-</span>' ?></td>
                                <td><?= h($tedarikci ?: '-') ?></td>
                                <td class="text-nowrap"><?= h($tarihRaw ?: '-') ?><?= $tarih ? '' : ' <i class="bi bi-x-circle text-danger" title="Geçersiz tarih formatı"></i>' ?></td>
                                <td><span class="badge bg-secondary"><?= h($betonSinifi ?: '-') ?></span></td>
                                <td class="text-end fw-bold"><?= number_format(parseMiktar($miktar), 1, ',', '.') ?></td>
                                <td><?= h($pompa ?: '-') ?></td>
                                <td>
                                    <span class="text-muted small">
                                        <?= h($parsel ?: '-') ?> / <?= h($blok ?: '-') ?> / <?= h($kot ?: '-') ?>
                                    </span>
                                </td>
                                <td class="text-truncate" style="max-width: 150px;" title="<?= h($aciklama) ?>"><?= h($aciklama ?: '-') ?></td>
                                <td>
                                    <?php if ($dupText === 'Hazır'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Hazır</span>
                                    <?php elseif ($dupText === 'Zaten Kayıtlı'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check2-circle me-1"></i> Zaten Kayıtlı</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Hatalı</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endfor; ?>
                            <?php endforeach; ?>

                            <?php if (!$hasValidRows): ?>
                                <tr>
                                    <td colspan="13" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                        Yorumlanabilir veri satırı bulunamadı. Lütfen Excel yapısını kontrol edin.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-footer bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span id="selectedCount" class="text-muted fw-medium">0 / 0 satır aktarılacak</span>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <?php if (is_admin()): ?>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="reset_all" value="1" id="resetAllChk">
                        <label class="form-check-label text-danger fw-semibold" for="resetAllChk">
                            Önce TÜM mevcut irsaliyeleri sil (tam yenileme — Excel ile birebir eşitler)
                        </label>
                    </div>
                    <?php endif; ?>
                    <button type="submit" name="execute_import" class="btn btn-success" id="importBtn" <?= $hasValidRows ? '' : 'disabled' ?>>
                        <i class="bi bi-cloud-download me-1"></i> Seçilen Verileri Aktar
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectAll = document.getElementById('selectAll');
        const allBoxes = Array.from(document.querySelectorAll('.row-select'));
        const selectedCount = document.getElementById('selectedCount');
        const importBtn = document.getElementById('importBtn');
        const resetChk = document.getElementById('resetAllChk');
        const form = importBtn ? importBtn.closest('form') : null;

        function aktifler() { return allBoxes.filter(c => !c.disabled); }
        function updateCounter() {
            const act = aktifler();
            const checkedCount = act.filter(c => c.checked).length;
            selectedCount.textContent = checkedCount + ' / ' + act.length + ' satır aktarılacak';
            importBtn.disabled = checkedCount === 0;
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                aktifler().forEach(c => c.checked = selectAll.checked);
                updateCounter();
            });
        }
        allBoxes.forEach(c => {
            c.addEventListener('change', function () {
                updateCounter();
                if (!this.checked) selectAll.checked = false;
            });
        });

        // Tam yenileme: "Zaten Kayıtlı" satırlar da aktarılabilir hale gelir (silme sonrası gerekli)
        if (resetChk) {
            resetChk.addEventListener('change', function () {
                allBoxes.forEach(c => {
                    if (c.dataset.reason === 'dup') {
                        c.disabled = !resetChk.checked;
                        c.checked = resetChk.checked;
                    }
                });
                updateCounter();
            });
            if (form) form.addEventListener('submit', function (e) {
                if (resetChk.checked && !confirm('DİKKAT — TAM YENİLEME:\nVeritabanındaki TÜM irsaliyeler (elle girilenler ve taramalar dahil) silinecek, ardından seçilen Excel satırları aktarılacak.\nBu işlem geri alınamaz. Önce yedek almanız önerilir.\n\nDevam edilsin mi?')) {
                    e.preventDefault();
                }
            });
        }

        updateCounter();
    });
    </script>
    <?php else: ?>
        <div class="alert alert-danger">Excel dosyası işlenirken hata oluştu. Lütfen dosyanın bozuk olmadığından emin olun.</div>
        <a href="import.php?reset=1" class="btn btn-primary">Geri Dön</a>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
