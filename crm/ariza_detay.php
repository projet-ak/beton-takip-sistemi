<?php
/**
 * ariza_detay.php — Tek arıza kartı
 *
 * Excel'den gelen alanlar SALT OKUNUR (kaynak CRM raporu — sonraki yüklemede ezilir).
 * Sistem içinde eklenen: durum (elle çöz/yeniden aç), iç not ve belge/fotoğraf.
 * Bu üç alan içe aktarmada KORUNUR.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_crm.php';
require_once __DIR__ . '/_ortak.php';

crm_semasi_kur($pdoCrm);
$yetkili = has_role('admin','teknik_ofis_admin','teknik_ofis');

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdoCrm->prepare("SELECT * FROM crm_arizalar WHERE id=?");
$st->execute([$id]);
$r = $st->fetch();
if (!$r) { flash('error', 'Arıza kaydı bulunamadı.'); redirect('arizalar.php'); }

// ── İşlemler ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $yetkili) {
    $islem = $_POST['action'] ?? '';
    if ($islem === 'durum') {
        if (($_POST['hedef'] ?? '') === 'acik') {
            $pdoCrm->prepare("UPDATE crm_arizalar SET durum='acik', cozumlenme=NULL, kapanis_kaynagi=NULL WHERE id=?")->execute([$id]);
            flash('success', 'Arıza yeniden açıldı.');
        } else {
            $tarih = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['cozum_tarihi'] ?? '') ? $_POST['cozum_tarihi'] . ' 00:00:00' : date('Y-m-d H:i:s');
            $pdoCrm->prepare("UPDATE crm_arizalar SET durum='cozuldu', cozumlenme=?, kapanis_kaynagi='elle' WHERE id=?")->execute([$tarih, $id]);
            flash('success', 'Arıza çözüldü olarak işaretlendi.');
        }
    } elseif ($islem === 'not') {
        $pdoCrm->prepare("UPDATE crm_arizalar SET ic_not=? WHERE id=?")
               ->execute([trim((string)($_POST['ic_not'] ?? '')) ?: null, $id]);
        flash('success', 'Not kaydedildi.');
    } elseif ($islem === 'evrak') {
        $f = $_FILES['evrak'] ?? [];
        if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
            flash('error', 'Dosya seçilmedi.');
        } else {
            $ad   = (string)$f['name'];
            $mime = guess_mime($f['tmp_name'], $ad);
            if (!in_array($mime, ['application/pdf','image/jpeg','image/png','image/webp'], true)) {
                flash('error', 'Desteklenmeyen tür (PDF, JPG, PNG, WEBP): ' . $mime);
            } elseif ((int)$f['size'] > 10*1024*1024) {
                flash('error', 'Dosya 10 MB sınırını aşıyor.');
            } else {
                $dir = __DIR__ . '/../uploads/crm_ariza/' . $id;
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $ext  = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'pdf';
                $yeni = 'belge_' . date('Ymd_His') . '.' . $ext;
                if (@move_uploaded_file($f['tmp_name'], $dir . '/' . $yeni)) {
                    if ($r['evrak_url']) @unlink(__DIR__ . '/../' . $r['evrak_url']);
                    $pdoCrm->prepare("UPDATE crm_arizalar SET evrak_url=? WHERE id=?")
                           ->execute(['uploads/crm_ariza/' . $id . '/' . $yeni, $id]);
                    flash('success', 'Belge yüklendi.');
                } else flash('error', 'Dosya diske yazılamadı.');
            }
        }
    }
    redirect('ariza_detay.php?id=' . $id);
}

// Aynı dairenin diğer arızaları
$digerleri = [];
if ($r['konut']) {
    $q = $pdoCrm->prepare("SELECT id, sikayet_konusu, sikayet_aciklamasi, ariza_tipi, olusturma, durum
                           FROM crm_arizalar WHERE konut=? AND id<>? ORDER BY olusturma DESC LIMIT 30");
    $q->execute([$r['konut'], $id]);
    $digerleri = $q->fetchAll();
}

$yas = crm_yas($r);
$pageTitle = 'Arıza #' . $id . ' — CRM';
require_once __DIR__ . '/../includes/header.php';
$bilgi = fn($e, $v) => '<div class="col-sm-6 col-lg-4"><div class="small text-muted">' . h($e) . '</div><div class="fw-semibold">' . ($v !== '' && $v !== null ? h((string)$v) : '—') . '</div></div>';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a href="arizalar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-tools text-primary me-2"></i><?= h($r['konut'] ?: 'Arıza #' . $id) ?></h4>
    <span class="badge bg-<?= crm_durumRenk($r['durum']) ?> fs-6"><?= h(crm_durumAd($r['durum'])) ?></span>
    <?php if ($r['durum'] === 'acik' && $yas > 90): ?>
    <span class="badge bg-warning text-dark"><?= $yas ?> gündür açık</span>
    <?php endif; ?>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white"><strong><?= h($r['sikayet_konusu']) ?> — <?= h($r['sikayet_aciklamasi']) ?></strong></div>
      <div class="card-body">
        <div class="alert alert-light border mb-3">
            <div class="fw-semibold"><?= h($r['ariza_tipi'] ?: '—') ?></div>
            <div class="text-muted mt-1" style="white-space:pre-wrap"><?= h($r['aciklama'] ?: '—') ?></div>
        </div>
        <div class="row g-3">
            <?= $bilgi('Konut', $r['konut']) ?>
            <?= $bilgi('Ada / Parsel', trim(($r['ada'] ?? '') . ' / ' . ($r['parsel'] ?? ''), ' /')) ?>
            <?= $bilgi('Blok', $r['blok']) ?>
            <?= $bilgi('Kat', $r['kat']) ?>
            <?= $bilgi('Daire No', $r['daire_no']) ?>
            <?= $bilgi('Daire Tipi', $r['daire_tipi']) ?>
            <?= $bilgi('Şikayet Türü', $r['sikayet_turu']) ?>
            <?= $bilgi('Dönem', $r['donem']) ?>
            <?= $bilgi('Kaynak', $r['kaynak']) ?>
            <?= $bilgi('Eksik / Kusur', $r['eksik_kusur']) ?>
            <?= $bilgi('Ölçek', $r['olcek']) ?>
            <?= $bilgi('Aciliyet', $r['aciliyet']) ?>
            <?= $bilgi('Sorumlu', $r['sorumlu']) ?>
            <?= $bilgi('Sonlandıran Yetkili', $r['sonlandiran']) ?>
            <?= $bilgi('CRM Durumu', $r['durum_aciklamasi']) ?>
            <?= $bilgi('Açılış', $r['olusturma'] ? date('d.m.Y H:i', strtotime($r['olusturma'])) : null) ?>
            <?= $bilgi('Çözüm', $r['cozumlenme'] ? date('d.m.Y H:i', strtotime($r['cozumlenme'])) : null) ?>
            <?= $bilgi($r['durum'] === 'acik' ? 'Açık kalma süresi' : 'Çözüm süresi', $yas . ' gün') ?>
            <?= $bilgi('İlk görüldüğü rapor', $r['ilk_gorulme'] ? format_date($r['ilk_gorulme']) : null) ?>
            <?= $bilgi('Son görüldüğü rapor', $r['son_gorulme'] ? format_date($r['son_gorulme']) : null) ?>
            <?= $bilgi('Kapanış kaynağı', $r['kapanis_kaynagi'] ? ['excel'=>'CRM raporu','otomatik'=>'Rapordan düştü (otomatik)','elle'=>'Elle kapatıldı'][$r['kapanis_kaynagi']] : null) ?>
        </div>
      </div>
    </div>

    <?php if ($digerleri): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white small">
        <i class="bi bi-house-door me-1"></i><strong><?= h($r['konut']) ?></strong> dairesinin diğer arızaları
        <span class="badge bg-secondary ms-1"><?= count($digerleri) ?></span>
      </div>
      <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" style="font-size:.85rem">
        <thead class="table-light"><tr><th>Konu</th><th>Detay</th><th>Arıza Tipi</th><th>Açılış</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach ($digerleri as $d): ?>
            <tr>
                <td><a href="ariza_detay.php?id=<?= (int)$d['id'] ?>" class="text-decoration-none"><?= h($d['sikayet_konusu']) ?></a></td>
                <td class="text-muted"><?= h($d['sikayet_aciklamasi']) ?></td>
                <td class="text-truncate" style="max-width:200px" title="<?= h($d['ariza_tipi']) ?>"><?= h($d['ariza_tipi']) ?></td>
                <td class="text-nowrap"><?= $d['olusturma'] ? h(date('d.m.Y', strtotime($d['olusturma']))) : '—' ?></td>
                <td><span class="badge bg-<?= crm_durumRenk($d['durum']) ?>"><?= h(crm_durumAd($d['durum'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <?php if ($yetkili): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white small"><i class="bi bi-toggle2-on me-1"></i><strong>Durum</strong></div>
      <div class="card-body">
        <?php if ($r['durum'] === 'acik'): ?>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="durum"><input type="hidden" name="hedef" value="cozuldu">
            <div class="col-7">
                <label class="form-label small mb-1">Çözüm tarihi</label>
                <input type="date" name="cozum_tarihi" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-5"><button class="btn btn-success btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Çözüldü</button></div>
        </form>
        <div class="form-text mt-1">Günlük raporda bu arıza hâlâ görünüyorsa bir sonraki aktarımda yeniden açılır.</div>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="durum"><input type="hidden" name="hedef" value="acik">
            <button class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-arrow-counterclockwise me-1"></i>Yeniden aç</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white small"><i class="bi bi-journal-text me-1"></i><strong>İç not</strong>
          <span class="text-muted">(CRM'e gitmez, aktarımda korunur)</span></div>
      <div class="card-body">
        <?php if ($yetkili): ?>
        <form method="post">
            <input type="hidden" name="action" value="not">
            <textarea name="ic_not" class="form-control form-control-sm" rows="4" placeholder="Saha notu, kim ilgileniyor, planlanan tarih…"><?= h($r['ic_not'] ?? '') ?></textarea>
            <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-save me-1"></i>Kaydet</button>
        </form>
        <?php else: ?>
        <div class="small" style="white-space:pre-wrap"><?= h($r['ic_not'] ?: '—') ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white small"><i class="bi bi-paperclip me-1"></i><strong>Belge / fotoğraf</strong></div>
      <div class="card-body">
        <?php if ($r['evrak_url']): ?>
        <a href="../<?= h($r['evrak_url']) ?>" target="_blank" class="btn btn-success btn-sm w-100 mb-2">
            <i class="bi bi-file-earmark-check me-1"></i>Ekli belgeyi aç</a>
        <?php endif; ?>
        <?php if ($yetkili): ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="evrak">
            <input type="file" name="evrak" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
            <button class="btn btn-outline-primary btn-sm mt-2 w-100"><i class="bi bi-upload me-1"></i><?= $r['evrak_url'] ? 'Değiştir' : 'Yükle' ?></button>
        </form>
        <div class="form-text">PDF, JPG, PNG — maks 10 MB.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
