<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin', 'teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';
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

// CSRF token
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_import'])) {
    $_SESSION['csrf_import'] = bin2hex(random_bytes(16));
}
$csrfImport = $_SESSION['csrf_import'];

// Oturum temizliği / sıfırlama
if (isset($_GET['reset'])) {
    if (isset($_SESSION['import_file']) && file_exists($_SESSION['import_file'])) {
        @unlink($_SESSION['import_file']);
    }
    unset($_SESSION['import_file'], $_SESSION['import_col_mapping'],
          $_SESSION['import_sheet_idx'], $_SESSION['import_header_row_idx']);
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

// CSRF doğrulama (tüm POST'lar için)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_import'] ?? '', $_POST['csrf'] ?? '')) {
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
                $_SESSION['import_sheet_idx'] = isset($_POST['sheet_idx']) ? (int)$_POST['sheet_idx'] : 0;
                
                // Başlık Haritalamasını Tespit Et
                if ($xlsx = SimpleXLSX::parse($tempPath)) {
                    $rows = $xlsx->rows($_SESSION['import_sheet_idx']);
                    
                    // Başlık Satırını Bul (ilk 15 satırı tara, irsaliye no veya tarih içeren satırı seç)
                    $headerRowIndex = 3; // Varsayılan: satır index 3 (4. satır)
                    for ($i = 0; $i < min(15, count($rows)); $i++) {
                        $nonEmpty = array_map('cleanHeader', $rows[$i]);
                        if (in_array('irsaliye no', $nonEmpty) || in_array('irsaliye tarihi', $nonEmpty) || in_array('tarih', $nonEmpty)) {
                            $headerRowIndex = $i;
                            break;
                        }
                    }
                    
                    // Kolon Haritası Oluştur
                    $colMapping = [];
                    $headers = $rows[$headerRowIndex];
                    foreach ($headers as $colIdx => $hText) {
                        $hText = cleanHeader($hText);
                        if ($hText === '') continue;
                        
                        if (in_array($hText, ['sira', 'sira no', 'no'])) {
                            $colMapping['sira_no'] = $colIdx;
                        } elseif (in_array($hText, ['fatura no', 'fatura'])) {
                            $colMapping['fatura_no'] = $colIdx;
                        } elseif (in_array($hText, ['arac plaka no', 'plaka', 'arac plaka'])) {
                            $colMapping['arac_plaka'] = $colIdx;
                        } elseif (in_array($hText, ['kivam sinifi', 'kivam'])) {
                            $colMapping['kivam_sinifi'] = $colIdx;
                        } elseif (in_array($hText, ['irsaliye no', 'irsaliye no.'])) {
                            $colMapping['irsaliye_no'] = $colIdx;
                        } elseif (in_array($hText, ['tedarikci'])) {
                            $colMapping['tedarikci'] = $colIdx;
                        } elseif (in_array($hText, ['irsaliye tarihi', 'tarih'])) {
                            $colMapping['tarih'] = $colIdx;
                        } elseif (in_array($hText, ['mikser cikis saati', 'mikser cikis'])) {
                            $colMapping['mikser_cikis'] = $colIdx;
                        } elseif (in_array($hText, ['yildizlar kantar giris saati', 'kantar giris saati', 'kantar giris'])) {
                            $colMapping['kantar_giris'] = $colIdx;
                        } elseif (in_array($hText, ['yildizlar kantar cikis saati', 'kantar cikis saati', 'kantar cikis'])) {
                            $colMapping['kantar_cikis'] = $colIdx;
                        } elseif (in_array($hText, ['yildizlar kantar net', 'yildizlar net'])) {
                            $colMapping['kantar_net_yildiz'] = $colIdx;
                        } elseif (in_array($hText, ['tedarikci kantar net', 'tedarikci net'])) {
                            $colMapping['kantar_net_tedarikci'] = $colIdx;
                        } elseif (in_array($hText, ['kantar farki', 'fark'])) {
                            $colMapping['kantar_farki'] = $colIdx;
                        } elseif (in_array($hText, ['beton sinifi', 'beton'])) {
                            $colMapping['beton_sinifi'] = $colIdx;
                        } elseif (in_array($hText, ['miktar', 'm', 'm3', 'metre kup', 'metrekup'])) {
                            $colMapping['miktar'] = $colIdx;
                        } elseif (in_array($hText, ['birim'])) {
                            $colMapping['birim'] = $colIdx;
                        } elseif (in_array($hText, ['pompa durumu', 'pompa'])) {
                            $colMapping['pompa'] = $colIdx;
                        } elseif (in_array($hText, ['katki 1', 'katki1'])) {
                            $colMapping['katki1'] = $colIdx;
                        } elseif (in_array($hText, ['katki 2', 'katki2'])) {
                            $colMapping['katki2'] = $colIdx;
                        } elseif (in_array($hText, ['firma'])) {
                            $colMapping['firma'] = $colIdx;
                        } elseif (in_array($hText, ['imalat ana grup', 'imalat grup', 'grup'])) {
                            $colMapping['imalat_grup'] = $colIdx;
                        } elseif (in_array($hText, ['ana is kalemi', 'is kalemi'])) {
                            $colMapping['ana_is_kalemi'] = $colIdx;
                        } elseif (in_array($hText, ['parsel'])) {
                            $colMapping['parsel'] = $colIdx;
                        } elseif (in_array($hText, ['blok'])) {
                            $colMapping['blok'] = $colIdx;
                        } elseif (in_array($hText, ['kot', 'seviye'])) {
                            $colMapping['kot'] = $colIdx;
                        } elseif (in_array($hText, ['irsaliye aciklama', 'aciklama', 'not', 'notlar'])) {
                            $colMapping['aciklama'] = $colIdx;
                        }
                    }
                    
                    // Gerekli sütunlar eksikse varsayılan indekslere dön
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
                    
                    $_SESSION['import_col_mapping'] = $colMapping;
                    $_SESSION['import_header_row_idx'] = $headerRowIndex;
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
            $colMapping = $_SESSION['import_col_mapping'];
            $sheetIdx = $_SESSION['import_sheet_idx'];
            
            if ($xlsx = SimpleXLSX::parse($tempPath)) {
                $rows = $xlsx->rows($sheetIdx);
                $added = 0; $skipped = 0; $errors = [];
                
                $pdo->beginTransaction();
                try {
                    foreach ($selectedIndices as $idx) {
                        $idx = (int)$idx;
                        if (!isset($rows[$idx])) continue;
                        $r = $rows[$idx];
                        
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
                            continue; 
                        }
                        
                        $tedarikciAd = $val('tedarikci');
                        if (!$tedarikciAd) { 
                            $skipped++; 
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
                        
                        // İrsaliye no ile mükerrer kontrolü
                        if ($irsaliyeNo) {
                            $dup = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE irsaliye_no = ?");
                            $dup->execute([$irsaliyeNo]);
                            if ($dup->fetchColumn() > 0) { 
                                $skipped++; 
                                continue; 
                            }
                        }
                        
                        $mikserCikis = $val('mikser_cikis');
                        $kantarGiris = $val('kantar_giris');
                        $kantarCikis = $val('kantar_cikis');
                        
                        $mikserCikis = ($mikserCikis && $mikserCikis !== '00:00:00') ? $mikserCikis : null;
                        $kantarGiris = ($kantarGiris && $kantarGiris !== '00:00:00') ? $kantarGiris : null;
                        $kantarCikis = ($kantarCikis && $kantarCikis !== '00:00:00') ? $kantarCikis : null;
                        
                        $miktarVal = (float)str_replace(',', '.', $val('miktar', 0));
                        
                        $stmt = $pdo->prepare("INSERT INTO irsaliyeler
                            (tip, sira_no, fatura_no, arac_plaka, kivam_sinifi_id, irsaliye_no,
                             tedarikci_id, tarih,
                             mikser_cikis_saati, kantar_giris_saati, kantar_cikis_saati,
                             kantar_net_yildizlar, kantar_net_tedarikci, kantar_farki,
                             beton_sinifi_id, miktar, birim, pompa_id,
                             katki1_id, katki2_id, firma_id,
                             imalat_grup_id, ana_is_kalemi_id,
                             parsel_id, blok_id, kot_id, aciklama, created_by)
                            VALUES ('alis',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                            
                        $stmt->execute([
                            $val('sira_no'), $val('fatura_no'), $val('arac_plaka'),
                            $kivamId, $irsaliyeNo,
                            $tedarikciId, $tarih,
                            $mikserCikis, $kantarGiris, $kantarCikis,
                            (float)str_replace(',', '.', $val('kantar_net_yildiz', 0)),
                            (float)str_replace(',', '.', $val('kantar_net_tedarikci', 0)),
                            (float)str_replace(',', '.', $val('kantar_farki', 0)),
                            $betonId, $miktarVal, $val('birim', 'M3'), $pompaId,
                            $katki1Id, $katki2Id, $firmaId,
                            $imalatGrupId, $anaKalemId,
                            $parselId, $blokId, $kotId, $val('aciklama'), current_user()['id']
                        ]);
                        $added++;
                    }
                    
                    $pdo->commit();
                    $success = "Aktarım işlemi tamamlandı! $added kayıt eklendi, $skipped mükerrer veya geçersiz kayıt atlandı.";
                    
                    // Oturum dosyalarını temizle
                    @unlink($tempPath);
                    unset($_SESSION['import_file']);
                    unset($_SESSION['import_col_mapping']);
                    unset($_SESSION['import_sheet_idx']);
                    
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = "Aktarım durduruldu. Veritabanı hatası: " . $e->getMessage();
                }
            } else {
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
                    <div class="col-md-6">
                        <label class="form-label fw-medium">Excel Dosyası (.xlsx)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Okunacak Sayfa Index</label>
                        <select name="sheet_idx" class="form-select">
                            <option value="0">Sayfa 1 (ALIŞLAR veya İlk Sayfa)</option>
                            <option value="1">Sayfa 2 (İADE vb.)</option>
                            <option value="2">Sayfa 3</option>
                            <option value="3">Sayfa 4</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-eye me-1"></i> Yükle &amp; İncele
                        </button>
                    </div>
                </div>
            </form>
            <div class="mt-4">
                <div class="alert alert-info border-0 bg-light-primary text-primary-emphasis mb-0 p-3 rounded-3">
                    <h6 class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i> İpuçları &amp; Uyumlu Şablon</h6>
                    <ul class="mb-0 small ps-3">
                        <li>Dosya boyutu sunucu yükleme sınırları dahilinde olmalıdır. Sadece <strong>.xlsx</strong> formatındaki modern Excel dosyaları kabul edilir.</li>
                        <li>Sistem, başlık satırını otomatik tespit etmek için ilk 15 satırda <em>"irsaliye no"</em>, <em>"irsaliye tarihi"</em>, <em>"miktar"</em> veya <em>"tarih"</em> kelimelerini arar.</li>
                        <li>Sütun isimleri otomatik haritalanır. Eşleşmeyen sütunlar için varsayılan şablon (Sıra, İrsaliye No, Tedarikçi, Tarih, Miktar vb.) indeksleri kullanılır.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php else: 
    // ADIM 1: Yüklenen Dosyayı Okuma ve Seçim Tablosu Oluşturma
    $xlsx = SimpleXLSX::parse($_SESSION['import_file']);
    if ($xlsx):
        $rows = $xlsx->rows($_SESSION['import_sheet_idx']);
        $headerRowIdx = $_SESSION['import_header_row_idx'];
        $colMapping = $_SESSION['import_col_mapping'];
        $dataStartIdx = $headerRowIdx + 1;
        $totalRows = count($rows);
        $totalDataRows = $totalRows - $dataStartIdx;
    ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="card-title mb-0 fw-semibold text-muted">
                    <i class="bi bi-eye text-success me-1"></i> Yüklenen Excel Dosya Önizlemesi
                </h5>
                <small class="text-muted">Toplam <strong><?= $totalDataRows ?></strong> veri satırı bulundu. Başlık Satırı Index: <?= $headerRowIdx ?></small>
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
                                <th>İrsaliye No</th>
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
                            for ($i = $dataStartIdx; $i < $totalRows; $i++):
                                $r = $rows[$i];
                                
                                // Boş satırları atla
                                if (count(array_filter($r)) === 0) continue;
                                
                                $hasValidRows = true;
                                
                                // Değer çekme yardımcısı
                                $getVal = function($key) use ($r, $colMapping) {
                                    return isset($colMapping[$key]) && isset($r[$colMapping[$key]]) ? trim($r[$colMapping[$key]]) : '';
                                };
                                
                                $siraNo = $getVal('sira_no');
                                $irsaliyeNo = $getVal('irsaliye_no');
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
                                
                                $dupColor = '';
                                $dupText = 'Hazır';
                                $canImport = true;
                                
                                // Mükerrer kontrolü
                                if ($irsaliyeNo) {
                                    $dupQ = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE irsaliye_no = ?");
                                    $dupQ->execute([$irsaliyeNo]);
                                    if ($dupQ->fetchColumn() > 0) {
                                        $dupColor = 'table-success';
                                        $dupText = 'Zaten Kayıtlı';
                                        $canImport = false;
                                    }
                                }
                                
                                // Tarih veya tedarikçi yoksa hata
                                if (!$tarih || !$tedarikci) {
                                    $dupColor = 'table-danger';
                                    $dupText = 'Hatalı (Tarih/Tedarikçi Yok)';
                                    $canImport = false;
                                }
                            ?>
                            <tr class="<?= $dupColor ?>">
                                <td class="text-center">
                                    <input type="checkbox" name="rows[]" value="<?= $i ?>" class="form-check-input row-select" <?= $canImport ? 'checked' : '' ?> <?= $canImport ? '' : 'disabled' ?>>
                                </td>
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td class="font-monospace fw-semibold"><?= h($irsaliyeNo ?: '-') ?></td>
                                <td><?= h($tedarikci ?: '-') ?></td>
                                <td class="text-nowrap"><?= h($tarihRaw ?: '-') ?><?= $tarih ? '' : ' <i class="bi bi-x-circle text-danger" title="Geçersiz tarih formatı"></i>' ?></td>
                                <td><span class="badge bg-secondary"><?= h($betonSinifi ?: '-') ?></span></td>
                                <td class="text-end fw-bold"><?= number_format((float)str_replace(',', '.', $miktar), 1) ?></td>
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
                            
                            <?php if (!$hasValidRows): ?>
                                <tr>
                                    <td colspan="11" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                        Yorumlanabilir veri satırı bulunamadı. Lütfen Excel yapısını kontrol edin.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card-footer bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center">
                <span id="selectedCount" class="text-muted fw-medium">0 / 0 satır aktarılacak</span>
                <button type="submit" name="execute_import" class="btn btn-success" id="importBtn" <?= $hasValidRows ? '' : 'disabled' ?>>
                    <i class="bi bi-cloud-download me-1"></i> Seçilen Verileri Aktar
                </button>
            </div>
        </form>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.row-select:not(:disabled)');
        const selectedCount = document.getElementById('selectedCount');
        const importBtn = document.getElementById('importBtn');
        
        function updateCounter() {
            const checkedCount = Array.from(checkboxes).filter(c => c.checked).length;
            selectedCount.textContent = `${checkedCount} / ${checkboxes.length} satır aktarılacak`;
            importBtn.disabled = checkedCount === 0;
        }
        
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(c => c.checked = selectAll.checked);
                updateCounter();
            });
        }
        
        checkboxes.forEach(c => {
            c.addEventListener('change', function () {
                updateCounter();
                if (!this.checked) selectAll.checked = false;
            });
        });
        
        updateCounter();
    });
    </script>
    <?php else: ?>
        <div class="alert alert-danger">Excel dosyası işlenirken hata oluştu. Lütfen dosyanın bozuk olmadığından emin olun.</div>
        <a href="import.php?reset=1" class="btn btn-primary">Geri Dön</a>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
