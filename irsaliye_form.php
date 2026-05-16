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
                    tedarikci_id=?, tarih=?, mikser_cikis_saati=?, kantar_giris_saati=?, kantar_cikis_saati=?,
                    kantar_net_yildizlar=?, kantar_net_tedarikci=?, kantar_farki=?,
                    beton_sinifi_id=?, miktar=?, birim=?, pompa_id=?, katki1_id=?, katki2_id=?,
                    firma_id=?, imalat_grup_id=?, ana_is_kalemi_id=?, parsel_id=?, blok_id=?, kot_id=?,
                    aciklama=?, updated_by=?
                    WHERE id=?");
                $stmt->execute([
                    $tipPost, $siraNo, $faturaNo, $aracPlaka, $kivamId, $irsaliyeNo,
                    $tedarikciId, $tarih, $mikserCikis, $kantarGiris, $kantarCikis,
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
                    tedarikci_id, tarih, mikser_cikis_saati, kantar_giris_saati, kantar_cikis_saati,
                    kantar_net_yildizlar, kantar_net_tedarikci, kantar_farki,
                    beton_sinifi_id, miktar, birim, pompa_id, katki1_id, katki2_id,
                    firma_id, imalat_grup_id, ana_is_kalemi_id, parsel_id, blok_id, kot_id,
                    aciklama, created_by
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $tipPost, $siraNo, $faturaNo, $aracPlaka, $kivamId, $irsaliyeNo,
                    $tedarikciId, $tarih, $mikserCikis, $kantarGiris, $kantarCikis,
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
