<?php
/**
 * irsaliye_form.php — İrsaliye ekle / düzenle formu
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }

// Depo dahil tüm oluşturma/görüntüleme rolleri
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo']);
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

    // Yetki kontrolü: bu irsaliyeyi düzenleyebilir mi?
    if (!can_edit_irsaliye($row)) {
        flash('error', 'Bu irsaliyeyi düzenleme yetkiniz yok (durum: ' . ($row['durum'] ?? '?') . ').');
        redirect("irsaliyeler.php?tip={$tip}");
    }
}

// Depo: sadece oluşturma, düzenleme yok
if (!$editId && !can_create_irsaliye()) {
    flash('error', 'İrsaliye oluşturma yetkiniz yok.');
    redirect('irsaliyeler.php');
}

$pageTitle = ($editId ? 'İrsaliye Düzenle' : 'Yeni İrsaliye Ekle') . ' — Beton Takip Sistemi';
$error     = '';
$irsaliyeDurum = $row['durum'] ?? 'beklemede'; // mevcut durum

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

    // Onay aksiyonları (kaydet + onayla)
    $onayAksiyonu = $_POST['onay_aksiyonu'] ?? '';

    // ── Reddet işlemleri (modal'dan gelir, ayrı form — redirekt yeter) ──────────
    if ($editId && in_array($onayAksiyonu, ['saha_reddet','teknik_reddet'])) {
        $redNeden = trim($_POST['red_neden'] ?? '');
        if (!$redNeden) {
            $error = 'Red nedeni zorunludur.';
        } else {
            switch ($onayAksiyonu) {
                case 'saha_reddet':
                    if (!can_approve_saha()) { $error = 'Yetkiniz yok.'; break; }
                    $pdo->prepare("UPDATE irsaliyeler SET durum='reddedildi', red_neden=? WHERE id=?")->execute([$redNeden, $editId]);
                    flash('warning', 'İrsaliye reddedildi.'); redirect("irsaliyeler.php?tip={$tip}");
                    break;
                case 'teknik_reddet':
                    if (!can_approve_teknik()) { $error = 'Yetkiniz yok.'; break; }
                    $pdo->prepare("UPDATE irsaliyeler SET durum='reddedildi', red_neden=? WHERE id=?")->execute([$redNeden, $editId]);
                    flash('warning', 'İrsaliye teknik ofis tarafından reddedildi.'); redirect("irsaliyeler.php?tip={$tip}");
                    break;
            }
        }
    }

    // ── Form kayıt validasyonu (onayla butonları da ana formu gönderir) ─────────
    if (!$error && in_array($onayAksiyonu, ['saha_reddet','teknik_reddet'])) {
        // Reddet POST'u: kayıt gerekmez, sadece hata varsa göster
    } elseif (!$tedarikciId || !$tarih || $miktar <= 0) {
        $error = 'Tedarikçi, tarih ve miktar zorunludur. Miktar sıfırdan büyük olmalıdır.';
    } elseif (!$error) {
        $yeniDurum = has_role('admin','teknik_ofis_admin') ? 'onaylandi' : 'beklemede';

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
                    $aciklama, $uid, $editId,
                ]);

                // ── Onayla işlemi: form kaydedildikten sonra uygula ─────────
                if ($editId && in_array($onayAksiyonu, ['saha_onayla','teknik_onayla'])) {
                    // Proje kontrolü: az önce kaydedilen proje_id üzerinden
                    if (empty($projeId) && !has_role('depo')) {
                        $error = 'Onay verebilmek için proje seçimi zorunludur.';
                    } else {
                        switch ($onayAksiyonu) {
                            case 'saha_onayla':
                                if (!can_approve_saha()) { $error = 'Bu işlem için yetkiniz yok.'; break; }
                                $pdo->prepare("UPDATE irsaliyeler SET durum='saha_onaylandi', saha_onaylayan_id=?, saha_onay_tarih=NOW() WHERE id=?")->execute([$uid, $editId]);
                                flash('success', 'Saha onayı verildi.'); redirect("irsaliyeler.php?tip={$tip}");
                                break;
                            case 'teknik_onayla':
                                if (!can_approve_teknik()) { $error = 'Yetkiniz yok.'; break; }
                                $pdo->prepare("UPDATE irsaliyeler SET durum='onaylandi', teknik_onaylayan_id=?, teknik_onay_tarih=NOW() WHERE id=?")->execute([$uid, $editId]);
                                flash('success', 'Teknik ofis onayı verildi — İrsaliye kaydedildi.'); redirect("irsaliyeler.php?tip={$tip}");
                                break;
                        }
                    }
                } else {
                    flash('success', 'İrsaliye güncellendi.');
                    redirect("irsaliyeler.php?tip={$tipPost}");
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO irsaliyeler (
                    tip, durum, sira_no, fatura_no, arac_plaka, kivam_sinifi_id, irsaliye_no,
                    proje_no, proje_id, tedarikci_id, tarih, mikser_cikis_saati, kantar_giris_saati,
                    kantar_cikis_saati, kantar_net_yildizlar, kantar_net_tedarikci, kantar_farki,
                    beton_sinifi_id, miktar, birim, pompa_id, katki1_id, katki2_id,
                    firma_id, imalat_grup_id, ana_is_kalemi_id, parsel_id, blok_id, kot_id,
                    aciklama, created_by
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $tipPost, $yeniDurum, $siraNo, $faturaNo, $aracPlaka, $kivamId, $irsaliyeNo,
                    $projeNo, $projeId, $tedarikciId, $tarih, $mikserCikis, $kantarGiris, $kantarCikis,
                    $kantarYildiz, $kantarTed, $kantarFarki,
                    $betonId, $miktar, $birim, $pompaId, $katki1Id, $katki2Id,
                    $firmaId, $imalatGrupId, $anaIsKalemId, $parselId, $blokId, $kotId,
                    $aciklama, $uid,
                ]);
                flash('success', 'İrsaliye eklendi' . ($yeniDurum === 'beklemede' ? ' — Onay bekleniyor.' : '.'));
                redirect("irsaliyeler.php?tip={$tipPost}");
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = 'Bu irsaliye numarası zaten kayıtlı.';
            } else {
                $error = 'Kayıt hatası: ' . h($e->getMessage());
            }
        }
    } // end elseif(!$error)
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

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <a href="irsaliyeler.php?tip=<?= h($tip) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="mb-0 me-auto">
        <?= $editId ? '<i class="bi bi-pencil text-warning me-2"></i>İrsaliye Düzenle' : '<i class="bi bi-plus-circle text-success me-2"></i>Yeni İrsaliye Ekle' ?>
    </h4>
    <?php if ($editId && isset($row['durum'])): ?>
        <?= durum_badge($row['durum']) ?>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?></div>
<?php endif; ?>

<?php
// Onay akışı bilgi paneli
if ($editId && isset($row['durum'])):
    $rd = $row['durum'];
?>
<div class="card mb-4 border-0" style="background:var(--bt-bg-soft)">
    <div class="card-body py-3 px-4">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div>
                <div class="fw-semibold small mb-1">Onay Durumu</div>
                <?= durum_badge($rd) ?>
            </div>

            <?php if ($row['saha_onaylayan_id']): ?>
            <div class="small text-muted">
                <i class="bi bi-person-check me-1"></i>Saha Onayı:
                <strong><?= format_date($row['saha_onay_tarih']) ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($row['teknik_onaylayan_id']): ?>
            <div class="small text-muted">
                <i class="bi bi-person-check-fill me-1"></i>Teknik Onay:
                <strong><?= format_date($row['teknik_onay_tarih']) ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($rd === 'reddedildi' && $row['red_neden']): ?>
            <div class="small text-danger">
                <i class="bi bi-x-circle me-1"></i>Red nedeni: <em><?= h($row['red_neden']) ?></em>
            </div>
            <?php endif; ?>

            <!-- Onay Aksiyon Butonları — ana formu gönderir (proje dahil tüm alanlar kaydedilir) -->
            <div class="ms-auto d-flex gap-2 flex-wrap align-items-center">
                <?php
                $formAction = '?id=' . $editId . '&tip=' . h($tip);
                ?>
                <?php if ($rd === 'beklemede' && can_approve_saha()): ?>
                    <button type="button" class="btn btn-sm btn-success"
                            onclick="submitOnay('saha_onayla')">
                        <i class="bi bi-check-circle me-1"></i>Saha Onayla
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            data-bs-toggle="modal" data-bs-target="#redModal"
                            data-aksiyon="saha_reddet" data-action="<?= $formAction ?>">
                        <i class="bi bi-x-circle me-1"></i>Reddet
                    </button>
                <?php endif; ?>

                <?php if (in_array($rd, ['beklemede','saha_onaylandi']) && can_approve_teknik()): ?>
                    <button type="button" class="btn btn-sm btn-primary"
                            onclick="submitOnay('teknik_onayla')">
                        <i class="bi bi-patch-check me-1"></i>Teknik Onayla &amp; Kaydet
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            data-bs-toggle="modal" data-bs-target="#redModal"
                            data-aksiyon="teknik_reddet" data-action="<?= $formAction ?>">
                        <i class="bi bi-x-circle me-1"></i>Reddet
                    </button>
                <?php endif; ?>

                <?php if ($rd === 'reddedildi' && can_approve_saha()): ?>
                    <button type="button" class="btn btn-sm btn-warning"
                            onclick="submitOnay('saha_onayla')">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Yeniden Onayla
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<form method="post" id="irsaliyeForm" action="?id=<?= $editId ?>&tip=<?= h($tip) ?>">
<input type="hidden" name="onay_aksiyonu" id="mainOnayAksiyonu" value="">
<div class="row g-4">

    <!-- Sol kolon -->
    <div class="col-lg-8">

        <!-- Genel Bilgiler -->
        <div class="card mb-4 border-0">
            <div class="card-header fw-bold bg-transparent border-0 pt-4 px-4 pb-0 fs-5 text-dark d-flex align-items-center justify-content-between"
                 role="button" data-bs-toggle="collapse" data-bs-target="#accGenel" aria-expanded="true">
                <span><i class="bi bi-info-circle text-primary me-2"></i> Genel Bilgiler</span>
                <i class="bi bi-chevron-down text-muted d-md-none" style="transition:.2s" id="chevGenel"></i>
            </div>
            <div class="collapse show" id="accGenel">
            <div class="card-body px-4 pb-4 pt-3">
                <div class="row g-4">
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
                                <option value="<?= $t['id'] ?>" data-vkn="<?= h($t['vkn'] ?? '') ?>" <?= sel($row['tedarikci_id'] ?? '', $t['id']) ?>><?= h($t['ad']) ?></option>
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
            </div><!-- /accGenel -->
        </div>

        <!-- Beton & Miktar -->
        <div class="card mb-4 border-0">
            <div class="card-header fw-bold bg-transparent border-0 pt-4 px-4 pb-0 fs-5 text-dark d-flex align-items-center justify-content-between"
                 role="button" data-bs-toggle="collapse" data-bs-target="#accBeton" aria-expanded="true">
                <span><i class="bi bi-layers text-primary me-2"></i> Beton & Miktar</span>
                <i class="bi bi-chevron-down text-muted d-md-none"></i>
            </div>
            <div class="collapse show" id="accBeton">
            <div class="card-body px-4 pb-4 pt-3">
                <div class="row g-4">
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
            </div><!-- /accBeton -->
        </div>

        <!-- Kantar Bilgileri -->
        <div class="card mb-4 border-0">
            <div class="card-header fw-bold bg-transparent border-0 pt-4 px-4 pb-0 fs-5 text-dark d-flex align-items-center justify-content-between"
                 role="button" data-bs-toggle="collapse" data-bs-target="#accKantar" aria-expanded="true">
                <span><i class="bi bi-speedometer text-primary me-2"></i> Kantar & Saat Bilgileri</span>
                <i class="bi bi-chevron-down text-muted d-md-none"></i>
            </div>
            <div class="collapse show" id="accKantar">
            <div class="card-body px-4 pb-4 pt-3">
                <div class="row g-4">
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
            </div><!-- /accKantar -->
        </div>

    </div>

    <!-- Sağ kolon -->
    <div class="col-lg-4">

        <!-- Konum -->
        <div class="card mb-4 border-0">
            <div class="card-header fw-bold bg-transparent border-0 pt-4 px-4 pb-0 fs-5 text-dark">
                <i class="bi bi-map text-primary me-2"></i> Konum
            </div>
            <div class="card-body px-4 pb-4 pt-3">
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
        <div class="card mb-4 border-0">
            <div class="card-header fw-bold bg-transparent border-0 pt-4 px-4 pb-0 fs-5 text-dark">
                <i class="bi bi-list-task text-primary me-2"></i> İş Kalemi
            </div>
            <div class="card-body px-4 pb-4 pt-3">
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
        <div class="card mb-4 border-0">
            <div class="card-header fw-bold bg-transparent border-0 pt-4 px-4 pb-0 fs-5 text-dark">
                <i class="bi bi-chat-text text-primary me-2"></i> Açıklama
            </div>
            <div class="card-body px-4 pb-4 pt-3">
                <textarea name="aciklama" class="form-control" rows="3" placeholder="İsteğe bağlı not..."><?= val($row, 'aciklama') ?></textarea>
            </div>
        </div>

        <div class="d-grid gap-3">
            <button type="submit" class="btn btn-primary btn-lg shadow-sm">
                <i class="bi bi-save me-1"></i> <?= $editId ? 'Güncelle' : 'Kaydet' ?>
            </button>
            <a href="irsaliyeler.php?tip=<?= h($tip) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle me-1"></i> İptal
            </a>
        </div>
    </div>

</div>
</form>

<!-- heic2any: iPhone HEIC → JPEG dönüşümü (fotoğraf yükleme için) -->
<script src="https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js"></script>

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

<script>
function onayGonder(aksiyon) {
    if (!confirm('Bu işlemi onaylamak istediğinizden emin misiniz?')) return;
    var form = document.getElementById('onayForm');
    document.getElementById('onayAksiyon').value = aksiyon;
    document.getElementById('redNedenInput').value = '';
    form.action = window.location.href;
    form.method = 'post';
    form.submit();
}
function redGonder(aksiyon) {
    var neden = prompt('Red nedeni girin:');
    if (neden === null) return;
    if (!neden.trim()) { alert('Red nedeni boş olamaz.'); return; }
    var form = document.getElementById('onayForm');
    document.getElementById('onayAksiyon').value = aksiyon;
    document.getElementById('redNedenInput').value = neden.trim();
    form.action = window.location.href;
    form.method = 'post';
    form.submit();
}
</script>
<script>
// ── AI ile Oku ───────────────────────────────────────────────────────────────
(function () {
    let aiAlanlar = {};

    const ETIKETLER = {
        irsaliye_no:        'İrsaliye No',
        tarih:              'Tarih',
        arac_plaka:         'Araç Plaka',
        miktar:             'Miktar (m³)',
        tedarikci_vkn:      'Tedarikçi VKN',
        beton_sinifi:       'Beton Sınıfı',
        kivam:              'Kıvam',
        ettn:               'ETTN',
        fatura_no:          'Fatura No',
        mikser_cikis_saati: 'Mikser Çıkış Saati',
        katki1:             'Katkı 1',
        katki2:             'Katkı 2',
    };

    document.getElementById('btnAiOku')?.addEventListener('click', async function () {
        const input      = document.getElementById('aiDosyaInput');
        const hataDiv    = document.getElementById('aiHata');
        const yukleniyor = document.getElementById('aiYukleniyor');
        const sonucPanel = document.getElementById('aiSonucPanel');
        const aktarMesaj = document.getElementById('aiAktarMesaj');

        hataDiv.classList.add('d-none');
        sonucPanel.classList.add('d-none');
        aktarMesaj?.classList.add('d-none');

        if (!input?.files?.length) {
            hataDiv.textContent = 'Lütfen bir dosya seçin.';
            hataDiv.classList.remove('d-none');
            return;
        }

        let dosya = input.files[0];

        // iPhone HEIC/HEIF → JPEG dönüşümü
        if (/heic|heif/i.test(dosya.type) || /\.heic$/i.test(dosya.name)) {
            yukleniyor.classList.remove('d-none');
            this.disabled = true;
            try {
                const blob = await heic2any({ blob: dosya, toType: 'image/jpeg', quality: 0.85 });
                dosya = new File([blob], dosya.name.replace(/\.heic$/i, '.jpg'), { type: 'image/jpeg' });
            } catch (convErr) {
                yukleniyor.classList.add('d-none');
                this.disabled = false;
                hataDiv.textContent = 'HEIC dönüşümü başarısız: ' + convErr.message;
                hataDiv.classList.remove('d-none');
                return;
            }
        }

        const fd = new FormData();
        fd.append('dosya', dosya);

        yukleniyor.classList.remove('d-none');
        this.disabled = true;

        try {
            const res  = await fetch('api/ai_okut.php', { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.ok) {
                hataDiv.textContent = json.msg || 'Bilinmeyen hata';
                hataDiv.classList.remove('d-none');
                return;
            }

            aiAlanlar = json.alanlar || {};

            let rows = '';
            for (const [key, label] of Object.entries(ETIKETLER)) {
                const val = aiAlanlar[key];
                const goster = (val !== null && val !== undefined) ? String(val) : '<span class="text-muted">—</span>';
                rows += `<tr><td class="text-muted" style="width:45%">${label}</td><td class="fw-semibold">${goster}</td></tr>`;
            }
            document.getElementById('aiSonucTablosu').innerHTML = rows;
            sonucPanel.classList.remove('d-none');

        } catch (e) {
            hataDiv.textContent = 'Bağlantı hatası: ' + e.message;
            hataDiv.classList.remove('d-none');
        } finally {
            yukleniyor.classList.add('d-none');
            this.disabled = false;
        }
    });

    document.getElementById('btnAiAktar')?.addEventListener('click', function () {
        let doldurulan = 0;

        // Düz metin alanları
        ['irsaliye_no', 'tarih', 'miktar', 'fatura_no', 'mikser_cikis_saati'].forEach(function (key) {
            const val = aiAlanlar[key];
            if (val === null || val === undefined) return;
            const el = document.querySelector('[name="' + key + '"]');
            if (el) { el.value = val; doldurulan++; }
        });

        // Araç plaka (büyük harfe çevir)
        if (aiAlanlar.arac_plaka) {
            const el = document.querySelector('[name="arac_plaka"]');
            if (el) { el.value = String(aiAlanlar.arac_plaka).toUpperCase(); doldurulan++; }
        }

        // Beton sınıfı — select option metni ile eşleştir
        if (aiAlanlar.beton_sinifi) {
            const bn  = String(aiAlanlar.beton_sinifi).toUpperCase().replace(/\s+/g, '');
            const sel = document.querySelector('[name="beton_sinifi_id"]');
            if (sel) for (const opt of sel.options) {
                const t = opt.textContent.replace(/\s+/g, '').toUpperCase();
                if (t === bn || t.startsWith(bn + '/') || t.startsWith(bn + '-')) {
                    sel.value = opt.value; doldurulan++; break;
                }
            }
        }

        // Kıvam sınıfı
        if (aiAlanlar.kivam) {
            const kn  = String(aiAlanlar.kivam).toUpperCase().replace(/\s+/g, '');
            const sel = document.querySelector('[name="kivam_sinifi_id"]');
            if (sel) for (const opt of sel.options) {
                if (opt.textContent.replace(/\s+/g, '').toUpperCase() === kn) {
                    sel.value = opt.value; doldurulan++; break;
                }
            }
        }

        // Tedarikçi — data-vkn ile eşleştir
        if (aiAlanlar.tedarikci_vkn) {
            const vkn = String(aiAlanlar.tedarikci_vkn).trim();
            const sel = document.querySelector('[name="tedarikci_id"]');
            if (sel) for (const opt of sel.options) {
                if (String(opt.getAttribute('data-vkn') || '').trim() === vkn) {
                    sel.value = opt.value; doldurulan++; break;
                }
            }
        }

        // ETTN → fatura_no (sadece fatura_no boşsa veya ETTN ayrıca belirtilmişse)
        if (aiAlanlar.ettn && !aiAlanlar.fatura_no) {
            const el = document.querySelector('[name="fatura_no"]');
            if (el) { el.value = String(aiAlanlar.ettn); doldurulan++; }
        }

        // Katkı 1 — select option metni ile eşleştir
        if (aiAlanlar.katki1) {
            const kn  = String(aiAlanlar.katki1).toUpperCase().replace(/\s+/g, '');
            const sel = document.querySelector('[name="katki1_id"]');
            if (sel) for (const opt of sel.options) {
                if (opt.value && opt.textContent.replace(/\s+/g, '').toUpperCase().includes(kn)) {
                    sel.value = opt.value; doldurulan++; break;
                }
            }
        }

        // Katkı 2 — select option metni ile eşleştir
        if (aiAlanlar.katki2) {
            const kn  = String(aiAlanlar.katki2).toUpperCase().replace(/\s+/g, '');
            const sel = document.querySelector('[name="katki2_id"]');
            if (sel) for (const opt of sel.options) {
                if (opt.value && opt.textContent.replace(/\s+/g, '').toUpperCase().includes(kn)) {
                    sel.value = opt.value; doldurulan++; break;
                }
            }
        }

        const aktarMesaj = document.getElementById('aiAktarMesaj');
        if (aktarMesaj) {
            aktarMesaj.textContent = doldurulan + ' alan forma aktarıldı.';
            aktarMesaj.innerHTML   = '<i class="bi bi-check-circle me-1"></i>' + aktarMesaj.textContent;
            aktarMesaj.classList.remove('d-none');
            setTimeout(() => aktarMesaj.classList.add('d-none'), 4000);
        }
    });
}());
</script>
<script>
function submitOnay(aksiyon) {
    document.getElementById('mainOnayAksiyonu').value = aksiyon;
    document.getElementById('irsaliyeForm').requestSubmit();
}
</script>

<!-- Red Nedeni Modalı -->
<div class="modal fade" id="redModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Red Nedeni</h6>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="redForm">
        <div class="modal-body">
          <input type="hidden" name="onay_aksiyonu" id="redAksiyon">
          <label class="form-label small fw-semibold">Red nedeni girin <span class="text-danger">*</span></label>
          <textarea name="red_neden" id="redNeden" class="form-control" rows="3" required
                    placeholder="Red nedenini açıklayın..."></textarea>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">İptal</button>
          <button type="submit" class="btn btn-sm btn-danger">Reddet</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('redModal').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('redAksiyon').value = btn.dataset.aksiyon;
    document.getElementById('redForm').action   = btn.dataset.action;
    document.getElementById('redNeden').value   = '';
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
