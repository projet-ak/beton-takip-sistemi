<?php
/**
 * irsaliye_form.php — İrsaliye ekle / düzenle formu
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }

require_auth(['admin','teknik_ofis_admin','saha_sefi']);
require_once __DIR__ . '/includes/db.php';

$tip    = in_array($_GET['tip'] ?? '', ['alis','iade'], true) ? $_GET['tip'] : 'alis';
$editId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$row    = null;

if ($editId) {
    $s = $pdo->prepare("SELECT * FROM irsaliyeler WHERE id=?");
    $s->execute([$editId]);
    $row = $s->fetch();
    if (!$row) { flash('error', 'İrsaliye bulunamadı.'); redirect("irsaliyeler.php?tip={$tip}"); }
    $tip = $row['tip'];
}

$pageTitle = ($editId ? 'İrsaliye Düzenle' : 'Yeni İrsaliye Ekle') . ' — Beton Takip Sistemi';
$error     = '';

// ── Referans veriler ─────────────────────────────────────────────────────────
$tedarikciler   = $pdo->query("SELECT id,ad FROM tedarikciler WHERE aktif=1 ORDER BY ad")->fetchAll();
$betonSiniflari = $pdo->query("SELECT id,ad FROM beton_siniflari ORDER BY ad")->fetchAll();
$katkiListesi   = $pdo->query("SELECT id,ad FROM katki_listesi ORDER BY ad")->fetchAll();
$pompaTurleri   = $pdo->query("SELECT id,ad FROM pompa_turleri ORDER BY ad")->fetchAll();
$firmalar       = $pdo->query("SELECT id,ad FROM firmalar ORDER BY ad")->fetchAll();
$kivamSiniflari = $pdo->query("SELECT id,ad FROM kivam_siniflari ORDER BY ad")->fetchAll();
$imalatGruplari = $pdo->query("SELECT id,ad FROM imalat_gruplari ORDER BY sira,ad")->fetchAll();
$parseller      = $pdo->query("SELECT id,ad FROM parseller ORDER BY ad")->fetchAll();
$projeler       = $pdo->query("SELECT id,kod,aciklama FROM projeler WHERE aktif=1 ORDER BY kod")->fetchAll();

// ── Kaydet ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;

    $tedarikciId   = (int)($d['tedarikci_id']    ?? 0);
    $tarih         = $d['tarih']                  ?? '';
    $miktar        = (float)str_replace(',', '.', $d['miktar'] ?? '0');
    $birim         = strtoupper(trim($d['birim']  ?? 'M3'));
    $tipPost       = in_array($d['tip'] ?? '', ['alis','iade'], true) ? $d['tip'] : 'alis';
    $siraNo        = ($d['sira_no'] ?? '') !== '' ? (int)$d['sira_no'] : null;
    $faturaNo      = trim($d['fatura_no']         ?? '') ?: null;
    $aracPlaka     = strtoupper(trim($d['arac_plaka'] ?? '')) ?: null;
    $irsaliyeNo    = trim($d['irsaliye_no']       ?? '') ?: null;
    $projeId = ($d['proje_id'] ?? '') !== '' ? (int)$d['proje_id'] : null;
    // proje_no'yu seçilen projenin kodundan al
    $projeNo = null;
    if ($projeId) {
        foreach ($projeler as $p) {
            if ((int)$p['id'] === $projeId) { $projeNo = $p['kod']; break; }
        }
    }
    $kivamId       = ($d['kivam_sinifi_id'] ?? '') !== '' ? (int)$d['kivam_sinifi_id'] : null;
    $betonId       = ($d['beton_sinifi_id'] ?? '') !== '' ? (int)$d['beton_sinifi_id'] : null;
    $pompaId       = ($d['pompa_id']        ?? '') !== '' ? (int)$d['pompa_id']        : null;
    $katki1Id      = ($d['katki1_id']       ?? '') !== '' ? (int)$d['katki1_id']       : null;
    $katki2Id      = ($d['katki2_id']       ?? '') !== '' ? (int)$d['katki2_id']       : null;
    $firmaId       = ($d['firma_id']        ?? '') !== '' ? (int)$d['firma_id']        : null;
    $imalatGrupId  = ($d['imalat_grup_id']  ?? '') !== '' ? (int)$d['imalat_grup_id']  : null;
    $anaIsKalemId  = ($d['ana_is_kalemi_id']?? '') !== '' ? (int)$d['ana_is_kalemi_id']: null;
    $parselId      = ($d['parsel_id']       ?? '') !== '' ? (int)$d['parsel_id']        : null;
    $blokId        = ($d['blok_id']         ?? '') !== '' ? (int)$d['blok_id']          : null;
    $kotId         = ($d['kot_id']          ?? '') !== '' ? (int)$d['kot_id']           : null;
    $mikserCikis   = ($d['mikser_cikis_saati'] ?? '') ?: null;
    $kantarGiris   = ($d['kantar_giris_saati'] ?? '') ?: null;
    $kantarCikis   = ($d['kantar_cikis_saati'] ?? '') ?: null;
    $kantarYildiz  = ($d['kantar_net_yildizlar'] ?? '') !== '' ? (float)str_replace(',','.',$d['kantar_net_yildizlar']) : null;
    $kantarTed     = ($d['kantar_net_tedarikci'] ?? '') !== '' ? (float)str_replace(',','.',$d['kantar_net_tedarikci']) : null;
    $kantarFarki   = ($kantarYildiz !== null && $kantarTed !== null) ? round($kantarYildiz - $kantarTed, 2) : null;
    $aciklama      = trim($d['aciklama'] ?? '') ?: null;
    $uid           = current_user_id();

    if (!$tedarikciId || !$tarih || $miktar <= 0) {
        $error = 'Tedarikçi, tarih ve miktar zorunludur. Miktar sıfırdan büyük olmalıdır.';
    } else {
        try {
            if ($editId) {
                $stmt = $pdo->prepare("UPDATE irsaliyeler SET
                    tip=?, sira_no=?, fatura_no=?, arac_plaka=?, kivam_sinifi_id=?, irsaliye_no=?,
                    proje_no=?, proje_id=?, tedarikci_id=?, tarih=?, mikser_cikis_saati=?, kantar_giris_saati=?,
                    kantar_cikis_saati=?, kantar_net_yildizlar=?, kantar_net_tedarikci=?, kantar_farki=?,
                    beton_sinifi_id=?, miktar=?, birim=?, pompa_id=?, katki1_id=?, katki2_id=?,
                    firma_id=?, imalat_grup_id=?, ana_is_kalemi_id=?, parsel_id=?, blok_id=?, kot_id=?,
                    aciklama=?, updated_by=?
                    WHERE id=?");
                $stmt->execute([
                    $tipPost, $siraNo, $faturaNo, $aracPlaka, $kivamId, $irsaliyeNo,
                    $projeNo, $projeId, $tedarikciId, $tarih, $mikserCikis, $kantarGiris, $kantarCikis,
                    $kantarYildiz, $kantarTed, $kantarFarki,
                    $betonId, $miktar, $birim, $pompaId, $katki1Id, $katki2Id,
                    $firmaId, $imalatGrupId, $anaIsKalemId, $parselId, $blokId, $kotId,
                    $aciklama, $uid,
                    $editId,
                ]);
                flash('success', 'İrsaliye güncellendi.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO irsaliyeler (
                    tip, sira_no, fatura_no, arac_plaka, kivam_sinifi_id, irsaliye_no,
                    proje_no, proje_id, tedarikci_id, tarih, mikser_cikis_saati, kantar_giris_saati,
                    kantar_cikis_saati, kantar_net_yildizlar, kantar_net_tedarikci, kantar_farki,
                    beton_sinifi_id, miktar, birim, pompa_id, katki1_id, katki2_id,
                    firma_id, imalat_grup_id, ana_is_kalemi_id, parsel_id, blok_id, kot_id,
                    aciklama, created_by
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $tipPost, $siraNo, $faturaNo, $aracPlaka, $kivamId, $irsaliyeNo,
                    $projeNo, $projeId, $tedarikciId, $tarih, $mikserCikis, $kantarGiris, $kantarCikis,
                    $kantarYildiz, $kantarTed, $kantarFarki,
                    $betonId, $miktar, $birim, $pompaId, $katki1Id, $katki2Id,
                    $firmaId, $imalatGrupId, $anaIsKalemId, $parselId, $blokId, $kotId,
                    $aciklama, $uid,
                ]);
                flash('success', 'İrsaliye eklendi.');
            }
            redirect("irsaliyeler.php?tip={$tipPost}");
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = 'Bu irsaliye numarası zaten kayıtlı.';
            } else {
                $error = 'Kayıt hatası: ' . h($e->getMessage());
            }
        }
    }
}

// Seçili parsel varsa blok ve kalemleri dinamik yükle (AJAX ya da sayfa yükleme)
$selParselId     = $row['parsel_id']       ?? (int)($_POST['parsel_id']       ?? 0);
$selBlokId       = $row['blok_id']         ?? (int)($_POST['blok_id']         ?? 0);
$selImalatGrupId = $row['imalat_grup_id']  ?? (int)($_POST['imalat_grup_id']  ?? 0);

$bloklarData    = $selParselId    ? $pdo->query("SELECT id,ad FROM bloklar WHERE parsel_id={$selParselId} ORDER BY ad")->fetchAll()     : [];
$kotlarData     = $selBlokId     ? $pdo->query("SELECT id,kot_degeri FROM kotlar WHERE blok_id={$selBlokId} ORDER BY sira")->fetchAll() : [];
$anaKalemData   = $selImalatGrupId ? $pdo->query("SELECT id,ad FROM ana_is_kalemleri WHERE imalat_grup_id={$selImalatGrupId} ORDER BY sira,ad")->fetchAll() : [];

// AJAX handler — bloklar/kotlar/kalemler
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $type = $_GET['ajax'];
    if ($type === 'bloklar' && isset($_GET['parsel_id'])) {
        $s = $pdo->prepare("SELECT id,ad FROM bloklar WHERE parsel_id=? ORDER BY ad");
        $s->execute([(int)$_GET['parsel_id']]);
        echo json_encode($s->fetchAll());
    } elseif ($type === 'kotlar' && isset($_GET['blok_id'])) {
        $s = $pdo->prepare("SELECT id,kot_degeri FROM kotlar WHERE blok_id=? ORDER BY sira");
        $s->execute([(int)$_GET['blok_id']]);
        echo json_encode($s->fetchAll());
    } elseif ($type === 'kalemler' && isset($_GET['grup_id'])) {
        $s = $pdo->prepare("SELECT id,ad FROM ana_is_kalemleri WHERE imalat_grup_id=? ORDER BY sira,ad");
        $s->execute([(int)$_GET['grup_id']]);
        echo json_encode($s->fetchAll());
    } else {
        echo '[]';
    }
    exit;
}

// Helper: seçili değer mi?
function sel($val, $check): string {
    return ((string)$val === (string)$check) ? 'selected' : '';
}
function val(array $row = null, string $key, $default = ''): string {
    return h((string)(($row[$key] ?? null) ?? $default));
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="irsaliyeler.php?tip=<?= h($tip) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="mb-0">
        <?= $editId ? '<i class="bi bi-pencil text-warning me-2"></i>İrsaliye Düzenle' : '<i class="bi bi-plus-circle text-success me-2"></i>Yeni İrsaliye Ekle' ?>
    </h4>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?></div>
<?php endif; ?>

<form method="post" id="irsaliyeForm">
<div class="row g-4">

    <!-- Sol kolon -->
    <div class="col-lg-8">

        <!-- Genel Bilgiler -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">
                <i class="bi bi-info-circle text-primary me-1"></i> Genel Bilgiler
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Tip <span class="text-danger">*</span></label>
                        <select name="tip" class="form-select" required>
                            <option value="alis" <?= sel($row['tip'] ?? $tip, 'alis') ?>>Alış</option>
                            <option value="iade" <?= sel($row['tip'] ?? $tip, 'iade') ?>>İade</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tarih <span class="text-danger">*</span></label>
                        <input type="date" name="tarih" class="form-control" required value="<?= val($row, 'tarih', date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Sıra No</label>
                        <input type="number" name="sira_no" class="form-control" min="1" value="<?= val($row, 'sira_no') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tedarikçi <span class="text-danger">*</span></label>
                        <select name="tedarikci_id" class="form-select" required>
                            <option value="">— Seçin —</option>
                            <?php foreach ($tedarikciler as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= sel($row['tedarikci_id'] ?? '', $t['id']) ?>><?= h($t['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">İrsaliye No</label>
                        <input type="text" name="irsaliye_no" class="form-control" value="<?= val($row, 'irsaliye_no') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Fatura No</label>
                        <input type="text" name="fatura_no" class="form-control" value="<?= val($row, 'fatura_no') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Araç Plaka</label>
                        <input type="text" name="arac_plaka" class="form-control text-uppercase" value="<?= val($row, 'arac_plaka') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Proje</label>
                        <select name="proje_id" class="form-select">
                            <option value="">— Seçin —</option>
                            <?php foreach ($projeler as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= sel($row['proje_id'] ?? '', $p['id']) ?>>
                                    <?= h($p['kod']) ?> — <?= h($p['aciklama']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Kıvam Sınıfı</label>
                        <select name="kivam_sinifi_id" class="form-select">
                            <option value="">—</option>
                            <?php foreach ($kivamSiniflari as $ks): ?>
                                <option value="<?= $ks['id'] ?>" <?= sel($row['kivam_sinifi_id'] ?? '', $ks['id']) ?>><?= h($ks['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Beton & Miktar -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">
                <i class="bi bi-layers text-primary me-1"></i> Beton & Miktar
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Beton Sınıfı</label>
                        <select name="beton_sinifi_id" class="form-select">
                            <option value="">—</option>
                            <?php foreach ($betonSiniflari as $bs): ?>
                                <option value="<?= $bs['id'] ?>" <?= sel($row['beton_sinifi_id'] ?? '', $bs['id']) ?>><?= h($bs['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Miktar <span class="text-danger">*</span></label>
                        <input type="number" name="miktar" class="form-control" step="0.01" min="0.01" required value="<?= val($row, 'miktar') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Birim</label>
                        <input type="text" name="birim" class="form-control text-uppercase" value="<?= val($row, 'birim', 'M3') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Pompa Türü</label>
                        <select name="pompa_id" class="form-select">
                            <option value="">—</option>
                            <?php foreach ($pompaTurleri as $pt): ?>
                                <option value="<?= $pt['id'] ?>" <?= sel($row['pompa_id'] ?? '', $pt['id']) ?>><?= h($pt['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Katkı 1</label>
                        <select name="katki1_id" class="form-select">
                            <option value="">—</option>
                            <?php foreach ($katkiListesi as $kl): ?>
                                <option value="<?= $kl['id'] ?>" <?= sel($row['katki1_id'] ?? '', $kl['id']) ?>><?= h($kl['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Katkı 2</label>
                        <select name="katki2_id" class="form-select">
                            <option value="">—</option>
                            <?php foreach ($katkiListesi as $kl): ?>
                                <option value="<?= $kl['id'] ?>" <?= sel($row['katki2_id'] ?? '', $kl['id']) ?>><?= h($kl['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kantar Bilgileri -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">
                <i class="bi bi-speedometer text-primary me-1"></i> Kantar & Saat Bilgileri
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Mikser Çıkış Saati</label>
                        <input type="time" name="mikser_cikis_saati" class="form-control" value="<?= val($row, 'mikser_cikis_saati') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Kantar Giriş Saati</label>
                        <input type="time" name="kantar_giris_saati" class="form-control" value="<?= val($row, 'kantar_giris_saati') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Kantar Çıkış Saati</label>
                        <input type="time" name="kantar_cikis_saati" class="form-control" value="<?= val($row, 'kantar_cikis_saati') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Kantar Net — Yıldızlar (kg)</label>
                        <input type="number" name="kantar_net_yildizlar" id="kantar_yildiz" class="form-control" step="0.01" value="<?= val($row, 'kantar_net_yildizlar') ?>" oninput="hesaplaFark()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Kantar Net — Tedarikçi (kg)</label>
                        <input type="number" name="kantar_net_tedarikci" id="kantar_tedarikci" class="form-control" step="0.01" value="<?= val($row, 'kantar_net_tedarikci') ?>" oninput="hesaplaFark()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Kantar Farkı (kg)</label>
                        <input type="number" id="kantar_farki_display" class="form-control bg-light" step="0.01" readonly value="<?= val($row, 'kantar_farki') ?>">
                        <div class="form-text">Otomatik hesaplanır</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Sağ kolon -->
    <div class="col-lg-4">

        <!-- Konum -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">
                <i class="bi bi-map text-primary me-1"></i> Konum
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Parsel</label>
                    <select name="parsel_id" id="selParsel" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($parseller as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= sel($row['parsel_id'] ?? '', $p['id']) ?>><?= h($p['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Blok</label>
                    <select name="blok_id" id="selBlok" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($bloklarData as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= sel($row['blok_id'] ?? '', $b['id']) ?>><?= h($b['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Kot</label>
                    <select name="kot_id" id="selKot" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($kotlarData as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= sel($row['kot_id'] ?? '', $k['id']) ?>><?= h($k['kot_degeri']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- İş Kalemi -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">
                <i class="bi bi-list-task text-primary me-1"></i> İş Kalemi
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Firma</label>
                    <select name="firma_id" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($firmalar as $f): ?>
                            <option value="<?= $f['id'] ?>" <?= sel($row['firma_id'] ?? '', $f['id']) ?>><?= h($f['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">İmalat Grubu</label>
                    <select name="imalat_grup_id" id="selGrup" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($imalatGruplari as $ig): ?>
                            <option value="<?= $ig['id'] ?>" <?= sel($row['imalat_grup_id'] ?? '', $ig['id']) ?>><?= h($ig['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Ana İş Kalemi</label>
                    <select name="ana_is_kalemi_id" id="selKalem" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($anaKalemData as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= sel($row['ana_is_kalemi_id'] ?? '', $k['id']) ?>><?= h($k['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Açıklama -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">
                <i class="bi bi-chat-text text-primary me-1"></i> Açıklama
            </div>
            <div class="card-body">
                <textarea name="aciklama" class="form-control" rows="3" placeholder="İsteğe bağlı not..."><?= val($row, 'aciklama') ?></textarea>
            </div>
        </div>

        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-success btn-lg">
                <i class="bi bi-save me-1"></i> <?= $editId ? 'Güncelle' : 'Kaydet' ?>
            </button>
            <a href="irsaliyeler.php?tip=<?= h($tip) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle me-1"></i> İptal
            </a>
        </div>
    </div>

</div>
</form>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- BELGE / KAMERA / QR PANEL (kayıttan bağımsız çalışır)                -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div class="card mt-4" id="belgePanel">
    <div class="card-header bg-white fw-semibold p-0">
        <ul class="nav nav-tabs border-0" id="belgeTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-0 px-4" id="tab-yukleme-btn"
                        data-bs-toggle="tab" data-bs-target="#tab-yukleme" type="button">
                    <i class="bi bi-paperclip me-1"></i> Belge / Fotoğraf Yükle
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-0 px-4" id="tab-kamera-btn"
                        data-bs-toggle="tab" data-bs-target="#tab-kamera" type="button">
                    <i class="bi bi-camera-video me-1"></i> Kamera
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-0 px-4" id="tab-qr-btn"
                        data-bs-toggle="tab" data-bs-target="#tab-qr" type="button">
                    <i class="bi bi-qr-code-scan me-1"></i> QR / Barkod Tara
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content" id="belgeTabContent">

            <!-- ── Belge Yükle Tab ─────────────────────────────────────────── -->
            <div class="tab-pane fade show active" id="tab-yukleme" role="tabpanel">
                <?php if ($editId): ?>
                <form id="belgeForm" enctype="multipart/form-data">
                    <input type="hidden" name="irsaliye_id" value="<?= $editId ?>">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                    <div class="mb-3">
                        <label class="form-label">Dosya Seç <span class="text-muted small">(JPG, JPEG, PNG, PDF — çoklu seçim desteklenir)</span></label>
                        <input type="file" name="dosyalar[]" id="dosyaInput" class="form-control"
                               accept=".jpg,.jpeg,.png,.pdf" multiple>
                        <div class="form-text">Çok sayfalı PDF yükleyebilirsiniz. Her sayfa ayrı görüntülenecektir.</div>
                    </div>

                    <!-- PDF Önizleme -->
                    <div id="pdfOnizleme" class="d-none mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold small">PDF Sayfaları</span>
                            <div class="btn-group btn-group-sm">
                                <button type="button" id="pdfPrev" class="btn btn-outline-secondary"><i class="bi bi-chevron-left"></i></button>
                                <span id="pdfSayac" class="btn btn-outline-secondary disabled">1 / 1</span>
                                <button type="button" id="pdfNext" class="btn btn-outline-secondary"><i class="bi bi-chevron-right"></i></button>
                            </div>
                        </div>
                        <canvas id="pdfCanvas" class="border rounded w-100" style="max-height:500px;object-fit:contain;"></canvas>
                    </div>

                    <!-- Görsel Önizleme -->
                    <div id="gorselOnizleme" class="row g-2 mb-3"></div>

                    <button type="button" id="btnBelgeYukle" class="btn btn-primary">
                        <i class="bi bi-cloud-upload me-1"></i> Yükle
                    </button>
                    <span id="yukleSpinner" class="ms-2 d-none">
                        <span class="spinner-border spinner-border-sm"></span> Yükleniyor...
                    </span>
                </form>
                <?php else: ?>
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Belge yüklemek için önce irsaliyeyi <strong>kaydedin</strong>, ardından düzenleme ekranından yükleyebilirsiniz.
                </div>
                <?php endif; ?>

                <!-- Mevcut Belgeler -->
                <?php if ($editId):
                    $mevcutBelgeler = $pdo->prepare("SELECT * FROM irsaliye_fotolar WHERE irsaliye_id=? ORDER BY created_at DESC");
                    $mevcutBelgeler->execute([$editId]);
                    $belgeler = $mevcutBelgeler->fetchAll();
                    if ($belgeler): ?>
                <hr>
                <p class="fw-semibold small text-muted mb-2">Mevcut Belgeler (<?= count($belgeler) ?>)</p>
                <div class="row g-2">
                    <?php foreach ($belgeler as $b): ?>
                    <div class="col-6 col-md-3 col-lg-2" id="belge-<?= $b['id'] ?>">
                        <div class="card h-100">
                            <?php $ext = strtolower(pathinfo($b['dosya_adi'], PATHINFO_EXTENSION)); ?>
                            <?php if (in_array($ext, ['jpg','jpeg','png'])): ?>
                                <img src="uploads/irsaliye_fotolar/<?= h($b['dosya_adi']) ?>"
                                     class="card-img-top" style="height:80px;object-fit:cover;">
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center" style="height:80px;background:#f8f9fa">
                                    <i class="bi bi-file-earmark-pdf text-danger fs-3"></i>
                                </div>
                            <?php endif; ?>
                            <div class="card-body p-1 text-center">
                                <a href="uploads/irsaliye_fotolar/<?= h($b['dosya_adi']) ?>"
                                   target="_blank" class="btn btn-xs btn-outline-primary w-100 mb-1">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <button type="button" class="btn btn-xs btn-outline-danger w-100 btn-belge-sil"
                                        data-id="<?= $b['id'] ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; endif; ?>
            </div>

            <!-- ── Kamera Tab ──────────────────────────────────────────────── -->
            <div class="tab-pane fade" id="tab-kamera" role="tabpanel">
                <div class="row g-3">
                    <div class="col-md-7">
                        <div class="d-flex gap-2 mb-2 flex-wrap">
                            <button type="button" id="btnKameraAc" class="btn btn-primary btn-sm">
                                <i class="bi bi-camera-video me-1"></i> Kamerayı Aç
                            </button>
                            <button type="button" id="btnKameraCevir" class="btn btn-outline-secondary btn-sm d-none">
                                <i class="bi bi-arrow-repeat me-1"></i> Çevir
                            </button>
                            <button type="button" id="btnKameraQrTara" class="btn btn-outline-success btn-sm d-none" title="Kamerada QR kod tara">
                                <i class="bi bi-qr-code-scan me-1"></i> QR Tara
                            </button>
                            <button type="button" id="btnKameraKapat" class="btn btn-outline-danger btn-sm d-none">
                                <i class="bi bi-stop-circle me-1"></i> Kapat
                            </button>
                        </div>
                        <div class="position-relative border rounded overflow-hidden bg-dark" style="min-height:200px">
                            <video id="kameraVideo" autoplay playsinline muted
                                   class="w-100 d-none" style="max-height:400px;object-fit:cover;"></video>
                            <div id="kameraPlaceholder" class="d-flex align-items-center justify-content-center" style="height:200px">
                                <div class="text-center text-muted">
                                    <i class="bi bi-camera-video-off fs-1"></i>
                                    <p class="mt-2 small">Kamerayı açmak için butona tıklayın</p>
                                </div>
                            </div>
                        </div>
                        <canvas id="kameraCanvas" class="d-none"></canvas>
                        <button type="button" id="btnFotoCek" class="btn btn-success mt-2 w-100 d-none">
                            <i class="bi bi-camera me-1"></i> Fotoğraf Çek
                        </button>
                    </div>
                    <div class="col-md-5">
                        <p class="small fw-semibold text-muted mb-2">Çekilen Fotoğraflar</p>
                        <div id="kameraFotolar" class="row g-2"></div>
                        <?php if ($editId): ?>
                        <button type="button" id="btnKameraKaydet" class="btn btn-primary btn-sm mt-2 d-none">
                            <i class="bi bi-cloud-upload me-1"></i> Seçilenleri Yükle
                        </button>
                        <?php else: ?>
                        <div class="alert alert-warning mt-2 small py-2 mb-0">
                            <i class="bi bi-info-circle me-1"></i> Kaydettikten sonra yükleme yapabilirsiniz.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── QR / Barkod Tab ────────────────────────────────────────── -->
            <div class="tab-pane fade" id="tab-qr" role="tabpanel">
                <div class="row g-3">
                    <div class="col-md-7">
                        <div class="d-flex gap-2 mb-2">
                            <button type="button" id="btnQrAc" class="btn btn-primary btn-sm">
                                <i class="bi bi-qr-code-scan me-1"></i> Taramayı Başlat
                            </button>
                            <button type="button" id="btnQrKapat" class="btn btn-outline-danger btn-sm d-none">
                                <i class="bi bi-stop-circle me-1"></i> Durdur
                            </button>
                        </div>
                        <div class="position-relative border rounded overflow-hidden bg-dark" style="min-height:200px">
                            <video id="qrVideo" autoplay playsinline muted
                                   class="w-100 d-none" style="max-height:350px;object-fit:cover;"></video>
                            <canvas id="qrScanCanvas" class="d-none"></canvas>
                            <div id="qrPlaceholder" class="d-flex align-items-center justify-content-center" style="height:200px">
                                <div class="text-center text-muted">
                                    <i class="bi bi-qr-code fs-1"></i>
                                    <p class="mt-2 small">QR kod veya barkodu kameraya tutun</p>
                                </div>
                            </div>
                            <div id="qrAimBox" class="d-none position-absolute border border-success border-2 rounded"
                                 style="width:60%;height:60%;top:20%;left:20%;pointer-events:none;"></div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <p class="small fw-semibold text-muted mb-2">Taranan Kod</p>
                        <div id="qrSonuc" class="alert alert-light border mb-2" style="min-height:50px;word-break:break-all;">
                            <span class="text-muted small">Henüz taranmadı</span>
                        </div>
                        <div id="qrEslestir" class="d-none">
                            <p class="small fw-semibold text-muted mb-1">Hangi alana atansın?</p>
                            <div class="input-group mb-2">
                                <select id="qrHedefAlan" class="form-select form-select-sm">
                                    <option value="irsaliye_no">İrsaliye No</option>
                                    <option value="fatura_no">Fatura No</option>
                                    <option value="arac_plaka">Araç Plaka</option>
                                </select>
                                <button type="button" id="btnQrAta" class="btn btn-success btn-sm">
                                    <i class="bi bi-arrow-left-circle me-1"></i> Forma Aktar
                                </button>
                            </div>
                        </div>
                        <div class="alert alert-info small py-2 mb-0">
                            <i class="bi bi-lightbulb me-1"></i>
                            E-İrsaliye QR kodlarında irsaliye no, tarih, plaka, sevk saati ve ETTN otomatik aktarılır. Diğer kodlarda irsaliye no alanı doldurulur.
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- jsQR kütüphanesi (CDN) -->
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<!-- PDF.js (CDN) -->
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
<script>
if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
}
</script>

<script>
// ═══════════════════════════════════════════════════════════════════════════
// Belge / Kamera / QR JavaScript
// ═══════════════════════════════════════════════════════════════════════════

// ── PDF Önizleme ─────────────────────────────────────────────────────────
let pdfDoc = null, pdfSayfa = 1;

document.getElementById('dosyaInput')?.addEventListener('change', async function () {
    const dosyaOnizleme = document.getElementById('gorselOnizleme');
    const pdfOnizleme   = document.getElementById('pdfOnizleme');
    dosyaOnizleme.innerHTML = '';
    pdfOnizleme?.classList.add('d-none');

    const files = this.files;
    if (!files.length) return;

    for (const file of files) {
        if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
            // PDF.js ile önizle
            const ab = await file.arrayBuffer();
            pdfDoc = await pdfjsLib.getDocument({ data: ab }).promise;
            pdfSayfa = 1;
            pdfOnizleme?.classList.remove('d-none');
            renderPdfSayfa(pdfSayfa);
        } else {
            // Görsel önizleme
            const reader = new FileReader();
            reader.onload = e => {
                dosyaOnizleme.innerHTML += `
                    <div class="col-4 col-md-3">
                        <img src="${e.target.result}" class="img-thumbnail w-100" style="height:80px;object-fit:cover;">
                    </div>`;
            };
            reader.readAsDataURL(file);
        }
    }
});

async function renderPdfSayfa(no) {
    if (!pdfDoc) return;
    const page   = await pdfDoc.getPage(no);
    const canvas = document.getElementById('pdfCanvas');
    const ctx    = canvas.getContext('2d');
    const vp     = page.getViewport({ scale: 1.5 });
    canvas.width  = vp.width;
    canvas.height = vp.height;
    await page.render({ canvasContext: ctx, viewport: vp }).promise;
    document.getElementById('pdfSayac').textContent = `${no} / ${pdfDoc.numPages}`;
}

document.getElementById('pdfPrev')?.addEventListener('click', () => {
    if (pdfDoc && pdfSayfa > 1) renderPdfSayfa(--pdfSayfa);
});
document.getElementById('pdfNext')?.addEventListener('click', () => {
    if (pdfDoc && pdfSayfa < pdfDoc.numPages) renderPdfSayfa(++pdfSayfa);
});

// ── Belge AJAX Yükleme ───────────────────────────────────────────────────
document.getElementById('btnBelgeYukle')?.addEventListener('click', async function () {
    const form    = document.getElementById('belgeForm');
    const spinner = document.getElementById('yukleSpinner');
    const input   = document.getElementById('dosyaInput');
    if (!input.files.length) { alert('Lütfen dosya seçin.'); return; }

    const fd = new FormData(form);
    spinner.classList.remove('d-none');
    this.disabled = true;

    try {
        const res  = await fetch('api/foto_yukle.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            location.reload();
        } else {
            alert('Hata: ' + (data.msg || 'Bilinmeyen hata'));
        }
    } catch (e) {
        alert('Yükleme hatası: ' + e.message);
    } finally {
        spinner.classList.add('d-none');
        this.disabled = false;
    }
});

// ── Belge Silme ──────────────────────────────────────────────────────────
document.querySelectorAll('.btn-belge-sil').forEach(btn => {
    btn.addEventListener('click', async function () {
        if (!confirm('Bu belgeyi silmek istediğinize emin misiniz?')) return;
        const id  = this.dataset.id;
        const res = await fetch(`api/foto_yukle.php?sil=${id}`);
        const data = await res.json();
        if (data.ok) {
            document.getElementById('belge-' + id)?.remove();
        } else {
            alert('Silinemedi: ' + (data.msg || ''));
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Kamera
// ═══════════════════════════════════════════════════════════════════════════
let kameraStream = null, kameraFaceUser = false;
const kameraVideo    = document.getElementById('kameraVideo');
const kameraCanvas   = document.getElementById('kameraCanvas');
const kameraFotolar  = document.getElementById('kameraFotolar');
const btnKameraAc    = document.getElementById('btnKameraAc');
const btnKameraCevir = document.getElementById('btnKameraCevir');
const btnKameraKapat = document.getElementById('btnKameraKapat');
const btnFotoCek     = document.getElementById('btnFotoCek');
const kameraPlaceholder = document.getElementById('kameraPlaceholder');
let kameraFotograflari = []; // data URL array

const btnKameraQrTara = document.getElementById('btnKameraQrTara');
let kameraQrAnimId = null, kameraQrAktif = false;

async function kameraAc(faceUser = false) {
    if (kameraStream) kameraKapat();
    try {
        kameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: faceUser ? 'user' : { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }
        });
        kameraVideo.srcObject = kameraStream;
        kameraVideo.classList.remove('d-none');
        kameraPlaceholder.classList.add('d-none');
        btnKameraCevir.classList.remove('d-none');
        btnKameraKapat.classList.remove('d-none');
        btnFotoCek.classList.remove('d-none');
        btnKameraQrTara?.classList.remove('d-none');
        btnKameraAc.classList.add('d-none');
    } catch (e) {
        alert('Kamera açılamadı: ' + e.message + '\nTarayıcı kamera iznini kontrol edin.');
    }
}

function kameraKapat() {
    kameraQrDurdur();
    kameraStream?.getTracks().forEach(t => t.stop());
    kameraStream = null;
    kameraVideo.classList.add('d-none');
    kameraPlaceholder.classList.remove('d-none');
    btnKameraCevir.classList.add('d-none');
    btnKameraKapat.classList.add('d-none');
    btnFotoCek.classList.add('d-none');
    btnKameraQrTara?.classList.add('d-none');
    btnKameraAc.classList.remove('d-none');
}

function kameraQrDurdur() {
    cancelAnimationFrame(kameraQrAnimId);
    kameraQrAktif = false;
    if (btnKameraQrTara) {
        btnKameraQrTara.innerHTML = '<i class="bi bi-qr-code-scan me-1"></i> QR Tara';
        btnKameraQrTara.classList.replace('btn-warning', 'btn-outline-success');
    }
}

function kameraQrLoop() {
    if (!kameraQrAktif || !kameraStream) return;
    const ctx = kameraCanvas.getContext('2d');
    kameraCanvas.width  = kameraVideo.videoWidth;
    kameraCanvas.height = kameraVideo.videoHeight;
    ctx.drawImage(kameraVideo, 0, 0);
    const imgData = ctx.getImageData(0, 0, kameraCanvas.width, kameraCanvas.height);
    if (typeof jsQR !== 'undefined' && imgData.width > 0) {
        const kod = jsQR(imgData.data, imgData.width, imgData.height, { inversionAttempts: 'dontInvert' });
        if (kod) {
            kameraQrDurdur();
            // Aynı JSON parse mantığını kullan
            const rawData = kod.data.trim();
            try {
                const json = JSON.parse(rawData);
                let doldurulan = 0;
                if (json.no)         { const el = document.querySelector('[name="irsaliye_no"]');        if (el) { el.value = json.no;         doldurulan++; } }
                if (json.tarih)      { const el = document.querySelector('[name="tarih"]');              if (el) { el.value = json.tarih;      doldurulan++; } }
                if (json.plaka)      { const el = document.querySelector('[name="arac_plaka"]');         if (el) { el.value = json.plaka;      doldurulan++; } }
                if (json.sevkzamani) { const el = document.querySelector('[name="mikser_cikis_saati"]'); if (el) { el.value = json.sevkzamani; doldurulan++; } }
                if (json.ettn)       { const el = document.querySelector('[name="fatura_no"]');          if (el) { el.value = json.ettn;       doldurulan++; } }
                showToast(`E-İrsaliye QR okundu — ${doldurulan} alan dolduruldu`, 'success');
            } catch(e) {
                if (/^[A-Z]{2,5}\d{10,}/.test(rawData)) {
                    const el = document.querySelector('[name="irsaliye_no"]');
                    if (el) { el.value = rawData; showToast('İrsaliye No aktarıldı!', 'success'); }
                } else {
                    showToast('QR tarandı: ' + rawData.substring(0, 40), 'info');
                }
            }
            return;
        }
    }
    kameraQrAnimId = requestAnimationFrame(kameraQrLoop);
}

btnKameraQrTara?.addEventListener('click', function () {
    if (kameraQrAktif) {
        kameraQrDurdur();
    } else {
        kameraQrAktif = true;
        this.innerHTML = '<i class="bi bi-stop-circle me-1"></i> QR Duraksın';
        this.classList.replace('btn-outline-success', 'btn-warning');
        kameraCanvas.width = kameraVideo.videoWidth;
        kameraCanvas.height = kameraVideo.videoHeight;
        kameraQrLoop();
    }
});

btnKameraAc?.addEventListener('click', () => kameraAc(kameraFaceUser));
btnKameraCevir?.addEventListener('click', () => { kameraFaceUser = !kameraFaceUser; kameraAc(kameraFaceUser); });
btnKameraKapat?.addEventListener('click', kameraKapat);

btnFotoCek?.addEventListener('click', function () {
    const ctx = kameraCanvas.getContext('2d');
    kameraCanvas.width  = kameraVideo.videoWidth;
    kameraCanvas.height = kameraVideo.videoHeight;
    ctx.drawImage(kameraVideo, 0, 0);
    const dataUrl = kameraCanvas.toDataURL('image/jpeg', 0.9);
    kameraFotograflari.push(dataUrl);

    const idx = kameraFotograflari.length - 1;
    kameraFotolar.innerHTML += `
        <div class="col-6" id="kfoto-${idx}">
            <img src="${dataUrl}" class="img-thumbnail w-100" style="height:70px;object-fit:cover;">
            <button type="button" class="btn btn-xs btn-outline-danger w-100 mt-1"
                    onclick="kameraFotograflari.splice(${idx},1,null);document.getElementById('kfoto-${idx}').remove();updateKameraKaydetBtn()">
                <i class="bi bi-trash"></i>
            </button>
        </div>`;
    updateKameraKaydetBtn();
});

function updateKameraKaydetBtn() {
    const btn = document.getElementById('btnKameraKaydet');
    if (!btn) return;
    const aktif = kameraFotograflari.filter(Boolean).length > 0;
    btn.classList.toggle('d-none', !aktif);
}

document.getElementById('btnKameraKaydet')?.addEventListener('click', async function () {
    const irsaliyeId = <?= $editId ?: 'null' ?>;
    if (!irsaliyeId) return;
    this.disabled = true;
    const aktifFotolar = kameraFotograflari.filter(Boolean);
    for (const dataUrl of aktifFotolar) {
        const blob = await fetch(dataUrl).then(r => r.blob());
        const fd   = new FormData();
        fd.append('irsaliye_id', irsaliyeId);
        fd.append('dosyalar[]', blob, `kamera_${Date.now()}.jpg`);
        await fetch('api/foto_yukle.php', { method: 'POST', body: fd });
    }
    location.reload();
});

// Sekme değiştiğinde kamerayı kapat
document.querySelectorAll('#belgeTabs button').forEach(btn => {
    btn.addEventListener('shown.bs.tab', function (e) {
        if (e.relatedTarget?.id === 'tab-kamera-btn') kameraKapat();
        if (e.relatedTarget?.id === 'tab-qr-btn') qrKapat();
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// QR / Barkod Tarama
// ═══════════════════════════════════════════════════════════════════════════
let qrStream = null, qrAnimId = null;
const qrVideo       = document.getElementById('qrVideo');
const qrScanCanvas  = document.getElementById('qrScanCanvas');
const qrSonuc       = document.getElementById('qrSonuc');
const qrEslestir    = document.getElementById('qrEslestir');
const qrPlaceholder = document.getElementById('qrPlaceholder');
const qrAimBox      = document.getElementById('qrAimBox');

async function qrAc() {
    try {
        qrStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' } }
        });
        qrVideo.srcObject = qrStream;
        qrVideo.classList.remove('d-none');
        qrPlaceholder.classList.add('d-none');
        qrAimBox.classList.remove('d-none');
        document.getElementById('btnQrAc').classList.add('d-none');
        document.getElementById('btnQrKapat').classList.remove('d-none');
        qrVideo.onloadedmetadata = () => qrTara();
    } catch (e) {
        alert('Kamera açılamadı: ' + e.message);
    }
}

function qrKapat() {
    cancelAnimationFrame(qrAnimId);
    qrStream?.getTracks().forEach(t => t.stop());
    qrStream = null;
    qrVideo.classList.add('d-none');
    qrPlaceholder.classList.remove('d-none');
    qrAimBox.classList.add('d-none');
    document.getElementById('btnQrAc').classList.remove('d-none');
    document.getElementById('btnQrKapat').classList.add('d-none');
}

let _sonQrJson = null; // Son taranan QR JSON verisi (varsa)

function qrTara() {
    if (!qrStream) return;
    const ctx = qrScanCanvas.getContext('2d');
    qrScanCanvas.width  = qrVideo.videoWidth;
    qrScanCanvas.height = qrVideo.videoHeight;
    ctx.drawImage(qrVideo, 0, 0);
    const imgData = ctx.getImageData(0, 0, qrScanCanvas.width, qrScanCanvas.height);
    const kod = jsQR(imgData.data, imgData.width, imgData.height, { inversionAttempts: 'dontInvert' });
    if (kod) {
        _sonQrJson = null;
        // Önce JSON parse dene (e-irsaliye formatı)
        try {
            const json = JSON.parse(kod.data);
            _sonQrJson = json;
            // Form alanlarını doldur
            let doldurulan = 0;
            if (json.no)         { const el = document.querySelector('[name="irsaliye_no"]');        if (el) { el.value = json.no;         doldurulan++; } }
            if (json.tarih)      { const el = document.querySelector('[name="tarih"]');              if (el) { el.value = json.tarih;      doldurulan++; } }
            if (json.plaka)      { const el = document.querySelector('[name="arac_plaka"]');         if (el) { el.value = json.plaka;      doldurulan++; } }
            if (json.sevkzamani) { const el = document.querySelector('[name="mikser_cikis_saati"]'); if (el) { el.value = json.sevkzamani; doldurulan++; } }
            if (json.ettn)       { const el = document.querySelector('[name="fatura_no"]');          if (el) { el.value = json.ettn;       doldurulan++; } }

            // Formatlanmış tablo göster
            qrSonuc.innerHTML = `
                <div class="small">
                    <div class="fw-semibold text-success mb-1"><i class="bi bi-check-circle me-1"></i>E-İrsaliye QR Okundu</div>
                    <table class="table table-xs mb-0">
                        <tr><td class="text-muted">İrsaliye No</td><td class="font-monospace">${json.no || '—'}</td></tr>
                        <tr><td class="text-muted">Tarih</td><td>${json.tarih || '—'}</td></tr>
                        <tr><td class="text-muted">Araç Plaka</td><td>${json.plaka || '—'}</td></tr>
                        <tr><td class="text-muted">Sevk Saati</td><td>${json.sevkzamani || '—'}</td></tr>
                        <tr><td class="text-muted">ETTN</td><td class="font-monospace small">${json.ettn || '—'}</td></tr>
                    </table>
                </div>`;
            // JSON'dan doldurulamayan alanlar için eşleştir panelini göster
            qrEslestir.classList.remove('d-none');
            showToast(`E-İrsaliye QR okundu — ${doldurulan} alan dolduruldu`, 'success');
        } catch (e) {
            // JSON değil — eski regex ile irsaliye no kontrolü
            qrSonuc.innerHTML = `<strong class="text-success"><i class="bi bi-check-circle me-1"></i>Tarındı:</strong><br><code>${kod.data}</code>`;
            qrEslestir.classList.remove('d-none');
            if (/^[A-Z]{2,5}\d{10,}/.test(kod.data.trim())) {
                document.querySelector('[name="irsaliye_no"]').value = kod.data.trim();
                showToast('İrsaliye No forma aktarıldı!', 'success');
            }
        }
        qrKapat();
        return;
    }
    qrAnimId = requestAnimationFrame(qrTara);
}

document.getElementById('btnQrAc')?.addEventListener('click', qrAc);
document.getElementById('btnQrKapat')?.addEventListener('click', qrKapat);

document.getElementById('btnQrAta')?.addEventListener('click', function () {
    const hedef = document.getElementById('qrHedefAlan').value;
    let deger = null;

    if (_sonQrJson) {
        // JSON QR: hedefe göre uygun JSON alanını seç, yoksa raw text kullan
        const jsonAlanMap = {
            irsaliye_no: _sonQrJson.no,
            fatura_no:   _sonQrJson.ettn,
            arac_plaka:  _sonQrJson.plaka,
        };
        deger = jsonAlanMap[hedef] ?? null;
        // Haritada karşılık yoksa raw JSON metnini koy
        if (deger === null || deger === undefined) {
            deger = JSON.stringify(_sonQrJson);
        }
    } else {
        deger = qrSonuc.querySelector('code')?.textContent ?? null;
    }

    if (deger && hedef) {
        const input = document.querySelector(`[name="${hedef}"]`);
        if (input) { input.value = deger; showToast(`${hedef} alanına aktarıldı`, 'success'); }
    }
});

// Toast bildirim
function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = `toast align-items-center text-bg-${type} border-0 position-fixed bottom-0 end-0 m-3`;
    t.setAttribute('role', 'alert');
    t.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    document.body.appendChild(t);
    new bootstrap.Toast(t, { delay: 3000 }).show();
    t.addEventListener('hidden.bs.toast', () => t.remove());
}
</script>

<script>
const BASE_URL = '<?= h(basename($_SERVER['PHP_SELF'])) ?>';

// Kantar farkı otomatik hesap
function hesaplaFark() {
    const y = parseFloat(document.getElementById('kantar_yildiz').value) || 0;
    const t = parseFloat(document.getElementById('kantar_tedarikci').value) || 0;
    document.getElementById('kantar_farki_display').value = (y && t) ? (y - t).toFixed(2) : '';
}

// Dinamik dropdown: Parsel → Blok
document.getElementById('selParsel').addEventListener('change', function () {
    const parselId = this.value;
    const blokSel  = document.getElementById('selBlok');
    const kotSel   = document.getElementById('selKot');
    blokSel.innerHTML = '<option value="">—</option>';
    kotSel.innerHTML  = '<option value="">—</option>';
    if (!parselId) return;
    fetch(BASE_URL + '?ajax=bloklar&parsel_id=' + parselId)
        .then(r => r.json())
        .then(data => {
            data.forEach(b => {
                blokSel.innerHTML += `<option value="${b.id}">${b.ad}</option>`;
            });
        });
});

// Blok → Kot
document.getElementById('selBlok').addEventListener('change', function () {
    const blokId = this.value;
    const kotSel = document.getElementById('selKot');
    kotSel.innerHTML = '<option value="">—</option>';
    if (!blokId) return;
    fetch(BASE_URL + '?ajax=kotlar&blok_id=' + blokId)
        .then(r => r.json())
        .then(data => {
            data.forEach(k => {
                kotSel.innerHTML += `<option value="${k.id}">${k.kot_degeri}</option>`;
            });
        });
});

// İmalat grubu → Ana iş kalemi
document.getElementById('selGrup').addEventListener('change', function () {
    const grupId  = this.value;
    const kalemSel = document.getElementById('selKalem');
    kalemSel.innerHTML = '<option value="">—</option>';
    if (!grupId) return;
    fetch(BASE_URL + '?ajax=kalemler&grup_id=' + grupId)
        .then(r => r.json())
        .then(data => {
            data.forEach(k => {
                kalemSel.innerHTML += `<option value="${k.id}">${k.ad}</option>`;
            });
        });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
