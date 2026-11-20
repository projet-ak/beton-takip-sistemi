<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin', 'teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';
beton_onay_gocu($pdo);   // onay akışı kapalı: bekleyenler geçerli sayılır
require_once __DIR__ . '/includes/fatura.php';   // fat_irs_norm: ANM2026-4710 ↔ ANM2026000004710 aynı belgedir
require_once __DIR__ . '/includes/beton_import.php';   // çekirdek: toplu_yukleme.php ile ORTAK (satır kaydı, sayfa tespiti, ön analiz)
require_once __DIR__ . '/vendor/autoload.php';

use Shuchkin\SimpleXLSX;

$pageTitle = 'Dinamik Excel Aktarımı — Beton Takip Sistemi';


$error = null;
$success = null;
$bosRapor = null;   // dosyada yeni irsaliye yoksa önizleme yerine gösterilen sonuç raporu

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
                    $sheets = beton_sayfalar($xlsx);   // VERİ / KOT / Kaşe gibi sayfalar atlanır
                    if (!$sheets) {
                        $error = "Bu dosyada irsaliye veri sayfası bulunamadı (başlık satırı algılanamadı).";
                        @unlink($tempPath);
                        unset($_SESSION['import_file']);
                    } else {
                        $_SESSION['import_sheets'] = $sheets;
                        // Dosyada aktarılacak YENİ satır yoksa önizlemede "0 / 0" ile bekletme —
                        // diğer modüllerdeki gibi sonuç raporu göster ve dosyayı kapat.
                        // (Yönetici "önizlemeyi yine de aç" derse — tam yenileme için — önizleme açılır.)
                        $an = importOnAnaliz($pdo, $xlsx, $sheets);
                        if ($an['say']['yeni'] + $an['say']['taslak'] === 0 && !(is_admin() && !empty($_POST['onizle_zorla']))) {
                            $bosRapor = $an + ['dosya' => (string)$file['name'], 'imalat' => (int)($_SESSION['import_imalat_sayi'] ?? 0)];
                            @unlink($tempPath);
                            unset($_SESSION['import_file'], $_SESSION['import_sheets'], $_SESSION['import_imalat_sayi']);
                        }
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

                    // Mevcut numaraları NORMALİZE indeksle (biçim farkı mükerrer kontrolünü atlatamaz);
                    // [FATURADAN] taslakları Excel satırıyla SİLMEDEN GÜNCELLENİR — çekirdek: includes/beton_import.php
                    $mevcutNo = beton_mevcut_no($pdo);
                    $uid = (int)(current_user()['id'] ?? 0) ?: null;
                    foreach ($secimler as $sheetIdx => $idxler) {
                    if (!isset($sheets[$sheetIdx])) continue;
                    $colMapping = $sheets[$sheetIdx]['mapping'];
                    $rowTip     = $sheets[$sheetIdx]['tip'];
                    if (!isset($rowsCache[$sheetIdx])) $rowsCache[$sheetIdx] = $xlsx->rows($sheetIdx, 20000);
                    $rows = $rowsCache[$sheetIdx];
                    foreach ($idxler as $idx) {
                        if (!isset($rows[$idx])) continue;
                        $sn = beton_satir_kaydet($pdo, $rows[$idx], $colMapping, $rowTip, $mevcutNo, $uid, 'Satır ' . ($idx + 1));
                        foreach ($sn['not'] ?? [] as $nt) $atlananlar[] = h($nt) . ' (satır aktarıldı)';
                        if ($sn['sonuc'] === 'eklendi') $added++;
                        elseif ($sn['sonuc'] === 'guncellendi') $guncellenen++;
                        elseif ($sn['sonuc'] === 'atlandi') { $skipped++; $atlananlar[] = h($sn['neden']); }
                        // 'bos' = formülle uzatılmış sahte satır — rapora girmez
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
    <div class="d-flex gap-2">
        <a href="toplu_yukleme.php" class="btn btn-outline-primary btn-sm" title="Beton + Depo + CRM + Prekast dosyalarını tek seferde yükle"><i class="bi bi-cloud-arrow-up-fill me-1"></i>Günlük Toplu Yükleme</a>
        <a href="irsaliyeler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>İrsaliyelere Dön</a>
    </div>
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

<?php if (!empty($bosRapor)): $bs = $bosRapor['say']; ?>
    <!-- Dosyada yeni irsaliye yok: önizleme yerine sonuç raporu -->
    <div class="card shadow-sm border-0 mb-4 border-start border-4 border-success">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="card-title mb-1 fw-semibold"><i class="bi bi-clipboard-check text-success me-1"></i>Aktarım Sonucu — <span class="text-muted fw-normal"><?= h($bosRapor['dosya']) ?></span></h5>
            <div class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Dosyada yeni irsaliye yok — <strong>0 kayıt aktarıldı</strong>, sistem bu dosyayla zaten güncel.</div>
        </div>
        <div class="card-body pt-0">
            <div class="d-flex flex-wrap gap-2 mb-2">
                <span class="badge bg-primary fs-6 fw-normal">Okunan satır <strong><?= number_format($bs['okunan'], 0, ',', '.') ?></strong></span>
                <span class="badge bg-success fs-6 fw-normal">Aktarılan <strong>0</strong></span>
                <span class="badge bg-success-subtle text-success border border-success-subtle fs-6 fw-normal">Zaten kayıtlı <strong><?= number_format($bs['kayitli'], 0, ',', '.') ?></strong></span>
                <?php if ($bs['tekrar']): ?><span class="badge bg-info-subtle text-info-emphasis border fs-6 fw-normal">Dosyada tekrar eden no <strong><?= $bs['tekrar'] ?></strong></span><?php endif; ?>
                <?php if ($bs['hatali']): ?><span class="badge bg-danger fs-6 fw-normal">Hatalı (tarih/tedarikçi yok) <strong><?= $bs['hatali'] ?></strong></span><?php endif; ?>
            </div>
            <div class="small text-muted">Hesap: okunan = aktarılan + zaten kayıtlı + dosyada tekrar + hatalı
                (<?= $bs['okunan'] ?> = 0 + <?= $bs['kayitli'] ?> + <?= $bs['tekrar'] ?> + <?= $bs['hatali'] ?>)</div>
            <?php if (!empty($bosRapor['imalat'])): ?>
            <div class="small text-success mt-2"><i class="bi bi-check-circle me-1"></i><strong><?= (int)$bosRapor['imalat'] ?></strong> imalat/metraj sayfası güncellendi — <a href="prp_ustyapi.php">Bina Üstyapı</a> · <a href="temel_kazik.php">Temel & Kazık</a> · <a href="istinat.php">İstinat</a> · <a href="icmal_beton.php">İcmal</a> · <a href="metraj_sayfasi.php">Metraj</a> · <a href="mobilizasyon.php">Mobilizasyon</a></div>
            <?php endif; ?>
            <?php if ($bosRapor['farkli']): ?>
            <details class="mt-3"><summary class="text-warning-emphasis fw-semibold"><i class="bi bi-exclamation-triangle me-1"></i><?= count($bosRapor['farkli']) ?> kayıtlı irsaliyede Excel ile sistem farklı (miktar/tarih) — kayıtlar değiştirilmedi</summary>
                <ul class="small mt-2 mb-0">
                <?php foreach (array_slice($bosRapor['farkli'], 0, 100) as $f): ?><li><?= h($f['metin']) ?> · <a href="irsaliye_form.php?id=<?= (int)$f['id'] ?>">kaydı aç</a></li><?php endforeach; ?>
                <?php if (count($bosRapor['farkli']) > 100): ?><li>… ve <?= count($bosRapor['farkli']) - 100 ?> satır daha</li><?php endif; ?>
                </ul></details>
            <?php endif; ?>
            <?php if ($bosRapor['hatali']): ?>
            <details class="mt-2"><summary class="text-danger fw-semibold"><i class="bi bi-x-circle me-1"></i>Hatalı satırlar (<?= count($bosRapor['hatali']) ?>)</summary>
                <ul class="small mt-2 mb-0">
                <?php foreach (array_slice($bosRapor['hatali'], 0, 100) as $hs): ?><li><?= h($hs) ?></li><?php endforeach; ?>
                <?php if (count($bosRapor['hatali']) > 100): ?><li>… ve <?= count($bosRapor['hatali']) - 100 ?> satır daha</li><?php endif; ?>
                </ul></details>
            <?php endif; ?>
            <div class="mt-3"><a href="irsaliyeler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul me-1"></i>İrsaliyelere Git</a></div>
        </div>
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
                    <div class="col-md-9">
                        <label class="form-label fw-medium">Excel Dosyası (.xlsx)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-eye me-1"></i> Yükle &amp; İncele
                        </button>
                    </div>
                    <?php if (is_admin()): ?>
                    <div class="col-12">
                        <div class="form-check small">
                            <input class="form-check-input" type="checkbox" name="onizle_zorla" value="1" id="onizleZorla">
                            <label class="form-check-label text-muted" for="onizleZorla">Yeni irsaliye olmasa da önizlemeyi aç (yalnız <strong>tam yenileme</strong> yapacaksanız gerekir)</label>
                        </div>
                    </div>
                    <?php endif; ?>
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
        $onAn = importOnAnaliz($pdo, $xlsx, $sheets);   // aktarımla AYNI kural (normalize no + taslak)
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

                                $onD = $onAn['durum']["$si:$i"] ?? 'yeni';
                                $dupColor = ''; $dupText = 'Hazır'; $canImport = true;
                                if ($onD === 'taslak') { $dupText = 'Taslak'; }
                                elseif ($onD === 'kayitli' || $onD === 'tekrar') { $dupColor = 'table-success'; $dupText = 'Zaten Kayıtlı'; $canImport = false; }
                                elseif ($onD === 'hatali') { $dupColor = 'table-danger'; $dupText = 'Hatalı (Tarih/Tedarikçi Yok)'; $canImport = false; }
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
                                    <?php elseif ($dupText === 'Taslak'): ?>
                                        <span class="badge bg-warning text-dark" title="Faturadan açılmış [FATURADAN] taslak kayıt Excel verisiyle güncellenecek (fatura bağı ve belgeler korunur)"><i class="bi bi-arrow-repeat me-1"></i> Taslağı günceller</span>
                                    <?php elseif ($dupText === 'Zaten Kayıtlı'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check2-circle me-1"></i> <?= $onD === 'tekrar' ? 'Dosyada tekrar' : 'Zaten Kayıtlı' ?></span>
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
