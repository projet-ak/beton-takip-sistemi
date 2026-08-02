<?php
/**
 * demir/sozlesmeler.php — Taşeron Sözleşmeleri (Sözleşme No paneli)
 * Sözleşme tanımları (no + taşeron + proje + konu) ve sözleşme bazında
 * teslim edilen demir özeti (bağlı teslim tutanaklarından, çap kırılımlı).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Sözleşmeler — Demir Takip';
$canEdit = has_role('admin','teknik_ofis_admin','teknik_ofis');

// Şema: sözleşme tablosu + tutanaklara sozlesme_id bağı
$pdoDemir->exec("CREATE TABLE IF NOT EXISTS demir_sozlesmeler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sozlesme_no VARCHAR(60) NOT NULL,
    taseron_id INT NOT NULL,
    proje_id INT NULL,
    tarih DATE NULL,
    konu VARCHAR(300) NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (taseron_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!$pdoDemir->query("SHOW COLUMNS FROM demir_tutanaklar LIKE 'sozlesme_id'")->fetchColumn()) {
    $pdoDemir->exec("ALTER TABLE demir_tutanaklar ADD COLUMN sozlesme_id INT NULL AFTER proje_id");
}
// Islak imzalı sözleşme dosyası: yalnız göreli URL tutulur, dosya diskte (uploads/demir_sozlesme/{id}/)
if (!$pdoDemir->query("SHOW COLUMNS FROM demir_sozlesmeler LIKE 'evrak_url'")->fetchColumn()) {
    $pdoDemir->exec("ALTER TABLE demir_sozlesmeler ADD COLUMN evrak_url VARCHAR(500) NULL");
}

// ── Kaydet (ekle/düzenle) ─────────────────────────────────────────────────────
if ($canEdit && ($_POST['action'] ?? '') === 'kaydet') {
    $sid = ctype_digit($_POST['id'] ?? '') ? (int)$_POST['id'] : 0;
    $no  = trim($_POST['sozlesme_no'] ?? '');
    $tas = ctype_digit($_POST['taseron_id'] ?? '') ? (int)$_POST['taseron_id'] : 0;
    $prj = ctype_digit($_POST['proje_id'] ?? '') ? (int)$_POST['proje_id'] : null;
    if ($no === '' || !$tas) {
        flash('error', 'Sözleşme no ve taşeron zorunludur.');
    } else {
        // Mükerrer sözleşme no engeli
        $q = $pdoDemir->prepare("SELECT id FROM demir_sozlesmeler WHERE UPPER(sozlesme_no)=UPPER(?) AND id<>? LIMIT 1");
        $q->execute([$no, $sid]);
        if ($q->fetchColumn()) {
            flash('error', "\"{$no}\" numaralı sözleşme zaten kayıtlı.");
        } else {
            $d = [$no, $tas, $prj, ($_POST['tarih'] ?? '') ?: null, trim($_POST['konu'] ?? '') ?: null];
            if ($sid) {
                $d[] = $sid;
                $pdoDemir->prepare("UPDATE demir_sozlesmeler SET sozlesme_no=?, taseron_id=?, proje_id=?, tarih=?, konu=? WHERE id=?")->execute($d);
                flash('success', 'Sözleşme güncellendi.');
            } else {
                $d[] = current_user_id();
                $pdoDemir->prepare("INSERT INTO demir_sozlesmeler (sozlesme_no, taseron_id, proje_id, tarih, konu, created_by) VALUES (?,?,?,?,?,?)")->execute($d);
                flash('success', "Sözleşme eklendi: {$no}");
            }
        }
    }
    redirect('sozlesmeler.php');
}
// ── Sil (bağlı tutanak varsa engelle; dosyayı da temizle) ─────────────────────
if (has_role('admin','teknik_ofis_admin') && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $sid = (int)$_GET['sil'];
    $c = $pdoDemir->prepare("SELECT COUNT(*) FROM demir_tutanaklar WHERE sozlesme_id=?"); $c->execute([$sid]);
    if ($c->fetchColumn() > 0) {
        flash('error', 'Bu sözleşmeye bağlı tutanak var — silinemez. Önce tutanaklardaki bağı kaldırın.');
    } else {
        $ev = $pdoDemir->prepare("SELECT evrak_url FROM demir_sozlesmeler WHERE id=?"); $ev->execute([$sid]);
        if ($u = $ev->fetchColumn()) @unlink(__DIR__ . '/../' . $u);
        $pdoDemir->prepare("DELETE FROM demir_sozlesmeler WHERE id=?")->execute([$sid]);
        flash('success', 'Sözleşme silindi.');
    }
    redirect('sozlesmeler.php');
}
// ── Islak imzalı sözleşme dosyası sil ─────────────────────────────────────────
if ($canEdit && isset($_GET['evrak_sil']) && ctype_digit($_GET['evrak_sil'])) {
    $sid = (int)$_GET['evrak_sil'];
    $ev = $pdoDemir->prepare("SELECT evrak_url FROM demir_sozlesmeler WHERE id=?"); $ev->execute([$sid]);
    if ($u = $ev->fetchColumn()) @unlink(__DIR__ . '/../' . $u);
    $pdoDemir->prepare("UPDATE demir_sozlesmeler SET evrak_url=NULL WHERE id=?")->execute([$sid]);
    flash('success', 'Sözleşme dosyası kaldırıldı.');
    redirect('sozlesmeler.php');
}
// ── Islak imzalı sözleşme dosyası yükle (PDF / DOCX / görsel) ─────────────────
// Dosya VERİTABANINA YAZILMAZ: diskte uploads/demir_sozlesme/{id}/ altında tutulur, DB'ye yalnız göreli URL.
if ($canEdit && ($_POST['action'] ?? '') === 'evrak' && ctype_digit($_POST['id'] ?? '')
    && isset($_FILES['evrak']) && $_FILES['evrak']['error']===UPLOAD_ERR_OK) {
    $sid = (int)$_POST['id'];
    $rw = $pdoDemir->prepare("SELECT sozlesme_no, evrak_url FROM demir_sozlesmeler WHERE id=?"); $rw->execute([$sid]); $rw = $rw->fetch();
    $mime = guess_mime($_FILES['evrak']['tmp_name'], $_FILES['evrak']['name']);
    $ext  = strtolower(pathinfo($_FILES['evrak']['name'], PATHINFO_EXTENSION));
    $izinMime = ['application/pdf','image/jpeg','image/png','image/webp',
                 'application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/msword'];
    // DOCX bazı sunucularda application/zip olarak algılanır — uzantıyla birlikte kabul et
    $gecerli = in_array($mime, $izinMime, true)
            || ($ext === 'docx' && in_array($mime, ['application/zip','application/octet-stream'], true));
    if (!$rw) {
        flash('error', 'Sözleşme bulunamadı.');
    } elseif (!$gecerli) {
        flash('error', 'Sadece PDF, DOCX, DOC, JPG, PNG, WebP yüklenebilir.');
    } elseif ($_FILES['evrak']['size'] > 20*1024*1024) {
        flash('error', 'Dosya çok büyük (maks 20 MB).');
    } else {
        $dir = __DIR__ . '/../uploads/demir_sozlesme/' . $sid . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!in_array($ext, ['pdf','docx','doc','jpg','jpeg','png','webp'], true)) {
            $ext = $mime==='application/pdf' ? 'pdf' : 'jpg';
        }
        $ad = 'sozlesme_' . date('Ymd_His') . '.' . $ext;
        if (move_uploaded_file($_FILES['evrak']['tmp_name'], $dir.$ad)) {
            if ($rw['evrak_url']) @unlink(__DIR__ . '/../' . $rw['evrak_url']); // eskiyi temizle
            $url = 'uploads/demir_sozlesme/' . $sid . '/' . $ad;
            $pdoDemir->prepare("UPDATE demir_sozlesmeler SET evrak_url=? WHERE id=?")->execute([$url, $sid]);
            flash('success', 'Islak imzalı sözleşme yüklendi (' . strtoupper($ext) . ')' . ($rw['sozlesme_no'] ? ' — '.$rw['sozlesme_no'] : '') . '.');
        } else { flash('error', 'Dosya yüklenemedi.'); }
    }
    redirect('sozlesmeler.php');
}

$taseronlar = $pdoDemir->query("SELECT id,ad,kod FROM demir_taseronlar WHERE aktif=1 ORDER BY ad")->fetchAll();
$projeler   = $pdoDemir->query("SELECT id,kod FROM demir_projeler WHERE aktif=1 ORDER BY kod")->fetchAll();

// Sözleşme listesi + bağlı tutanak özeti
$liste = $pdoDemir->query("
    SELECT sz.*, t.ad AS taseron_adi, t.kod AS taseron_kod, p.kod AS proje_kod,
           COALESCE(agg.tutanak_sayi,0) AS tutanak_sayi, COALESCE(agg.ton,0) AS ton
    FROM demir_sozlesmeler sz
    LEFT JOIN demir_taseronlar t ON t.id = sz.taseron_id
    LEFT JOIN demir_projeler p ON p.id = sz.proje_id
    LEFT JOIN (
        SELECT tu.sozlesme_id, COUNT(DISTINCT tu.id) tutanak_sayi, COALESCE(SUM(tk.miktar_ton),0) ton
        FROM demir_tutanaklar tu
        LEFT JOIN demir_tutanak_kalemleri tk ON tk.tutanak_id = tu.id
        WHERE tu.sozlesme_id IS NOT NULL GROUP BY tu.sozlesme_id
    ) agg ON agg.sozlesme_id = sz.id
    ORDER BY sz.sozlesme_no")->fetchAll();

// Sözleşme → çap kırılımı
$capKirilim = [];
foreach ($pdoDemir->query("
    SELECT tu.sozlesme_id sid, c.ad cap, SUM(tk.miktar_ton) ton
    FROM demir_tutanak_kalemleri tk
    JOIN demir_tutanaklar tu ON tu.id = tk.tutanak_id
    LEFT JOIN demir_caplar c ON c.id = tk.cap_id
    WHERE tu.sozlesme_id IS NOT NULL
    GROUP BY tu.sozlesme_id, tk.cap_id") as $r) {
    $capKirilim[(int)$r['sid']][] = $r;
}
// Sözleşme → bağlı tutanaklar (imzalı evrak dahil)
$tutBySz = [];
foreach ($pdoDemir->query("
    SELECT tu.sozlesme_id sid, tu.id, tu.tutanak_no, tu.tutanak_tarih, tu.evrak_url,
           COALESCE(SUM(tk.miktar_ton),0) ton
    FROM demir_tutanaklar tu
    LEFT JOIN demir_tutanak_kalemleri tk ON tk.tutanak_id = tu.id
    WHERE tu.sozlesme_id IS NOT NULL
    GROUP BY tu.id ORDER BY tu.tutanak_tarih DESC, tu.id DESC") as $r) {
    $tutBySz[(int)$r['sid']][] = $r;
}
$topTon = array_sum(array_column($liste,'ton'));

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-journal-bookmark text-dark me-2"></i>Sözleşmeler</h4>
        <small class="text-muted">Taşeron sözleşme numaraları — sözleşme bazında teslim edilen demir takibi</small>
    </div>
    <?php if ($canEdit): ?><button class="btn btn-dark" id="btnYeni"><i class="bi bi-plus-circle me-1"></i> Yeni Sözleşme</button><?php endif; ?>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Sözleşme</div><div class="fs-4 fw-bold"><?= count($liste) ?></div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Bağlı Tutanak</div><div class="fs-4 fw-bold"><?= array_sum(array_column($liste,'tutanak_sayi')) ?></div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Sözleşmeler Üzerinden Teslim</div><div class="fs-4 fw-bold"><?= $fmt($topTon) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
</div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th style="width:40px"></th><th>Sözleşme No</th><th>Taşeron</th><th>Proje</th><th>Tarih</th><th>Konu</th>
            <th class="text-center">İmzalı Sözleşme</th>
            <th class="text-center">Tutanak</th><th class="text-end">Teslim (t)</th><?php if($canEdit): ?><th class="text-end">İşlem</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($liste as $idx=>$r): $caps = $capKirilim[(int)$r['id']] ?? []; $tuts = $tutBySz[(int)$r['id']] ?? []; ?>
            <tr>
                <td><?php if($caps || $tuts): ?><button class="btn btn-xs btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#sz<?= $idx ?>" title="Detay: tutanaklar + çap kırılımı"><i class="bi bi-chevron-down"></i></button><?php endif; ?></td>
                <td class="fw-semibold font-monospace"><?= h($r['sozlesme_no']) ?></td>
                <td><?= h($r['taseron_adi'] ?: '—') ?><?= $r['taseron_kod']?' <span class="text-muted small">('.h($r['taseron_kod']).')</span>':'' ?></td>
                <td><?= $r['proje_kod'] ? '<span class="badge bg-secondary">'.h($r['proje_kod']).'</span>' : '—' ?></td>
                <td class="text-nowrap"><?= format_date($r['tarih']) ?></td>
                <td class="small"><?= h($r['konu'] ?: '—') ?></td>
                <td class="text-center">
                    <?php if ($r['evrak_url']): $sozExt = strtoupper(pathinfo($r['evrak_url'], PATHINFO_EXTENSION)); ?>
                        <a href="<?= h($rootPath.$r['evrak_url']) ?>" target="_blank" class="badge bg-success text-decoration-none" title="Islak imzalı sözleşmeyi aç (<?= h($sozExt) ?>)"><i class="bi bi-paperclip"></i> <?= h($sozExt) ?></a>
                    <?php elseif ($canEdit): ?>
                        <button class="btn btn-xs btn-outline-secondary btn-soz-evrak" data-id="<?= $r['id'] ?>" data-no="<?= h($r['sozlesme_no']) ?>" title="Islak imzalı sözleşme yükle"><i class="bi bi-upload"></i></button>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td class="text-center"><?= (int)$r['tutanak_sayi'] ?: '—' ?></td>
                <td class="text-end fw-bold"><?= $r['ton']>0 ? $fmt($r['ton']) : '—' ?></td>
                <?php if ($canEdit): ?>
                <td class="text-end text-nowrap">
                    <a href="tutanaklar.php" class="btn btn-xs btn-outline-secondary" title="Tutanaklar"><i class="bi bi-file-earmark-check"></i></a>
                    <button class="btn btn-xs btn-outline-primary btn-duzenle" data-json='<?= h(json_encode($r, JSON_UNESCAPED_UNICODE)) ?>' title="Düzenle"><i class="bi bi-pencil"></i></button>
                    <?php if ($r['evrak_url']): ?><a href="sozlesmeler.php?evrak_sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-warning" title="Sözleşme dosyasını kaldır" onclick="return confirm('Islak imzalı sözleşme dosyası kaldırılsın mı?')"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                    <?php if (has_role('admin','teknik_ofis_admin')): ?>
                    <a href="sozlesmeler.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Bu sözleşme silinsin mi?')"><i class="bi bi-trash"></i></a>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php if ($caps || $tuts): ?>
            <tr class="collapse" id="sz<?= $idx ?>">
                <td></td>
                <td colspan="<?= $canEdit?9:8 ?>" class="p-2 bg-light">
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <div class="small fw-semibold text-muted mb-1"><i class="bi bi-file-earmark-check me-1"></i>Bağlı Tutanaklar (<?= count($tuts) ?>)</div>
                            <table class="table table-sm mb-0 bg-white border">
                                <thead><tr class="small text-muted"><th>Tutanak No</th><th>Tarih</th><th class="text-end">Tonaj (t)</th><th class="text-center">İmzalı Evrak</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($tuts as $tt): ?>
                                    <tr>
                                        <td class="font-monospace small fw-semibold"><?= h($tt['tutanak_no']) ?></td>
                                        <td class="text-nowrap small"><?= format_date($tt['tutanak_tarih']) ?></td>
                                        <td class="text-end fw-semibold"><?= $fmt($tt['ton']) ?></td>
                                        <td class="text-center">
                                            <?php if ($tt['evrak_url']): ?><a href="<?= h($rootPath.$tt['evrak_url']) ?>" target="_blank" class="badge bg-success text-decoration-none"><i class="bi bi-paperclip"></i> Aç</a>
                                            <?php else: ?><span class="badge bg-light text-muted border">Yok</span><?php endif; ?>
                                        </td>
                                        <td class="text-end"><a href="tutanak_detay.php?id=<?= (int)$tt['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Tutanak detayı"><i class="bi bi-box-arrow-up-right"></i></a></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$tuts): ?><tr><td colspan="5" class="text-center text-muted small py-2">Bağlı tutanak yok.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-lg-5">
                            <div class="small fw-semibold text-muted mb-1"><i class="bi bi-rulers me-1"></i>Çap Kırılımı</div>
                            <table class="table table-sm mb-0 bg-white border">
                                <thead><tr class="small text-muted"><th>Çap</th><th class="text-end">Teslim (t)</th></tr></thead>
                                <tbody>
                                <?php foreach ($caps as $ck): ?>
                                    <tr><td><?= h($ck['cap'] ?: '(çap belirsiz)') ?></td><td class="text-end fw-semibold"><?= $fmt($ck['ton']) ?></td></tr>
                                <?php endforeach; ?>
                                <?php if (!$caps): ?><tr><td colspan="2" class="text-center text-muted small py-2">Kalem yok.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$liste): ?>
            <tr><td colspan="<?= $canEdit?10:9 ?>" class="text-center text-muted py-5">
                <i class="bi bi-journal-bookmark fs-1 d-block mb-2 opacity-50"></i>
                Henüz sözleşme yok.<?= $canEdit?' "Yeni Sözleşme" ile ekleyin.':'' ?>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div></div></div>

<p class="text-muted small mt-3">
    <i class="bi bi-info-circle me-1"></i>
    Teslim tutanağı oluştururken/düzenlerken <strong>Sözleşme</strong> seçilirse, o tutanağın tonajı burada
    ilgili sözleşme numarasının altında toplanır (çap kırılımıyla).
</p>

<?php if ($canEdit): ?>
<!-- Sözleşme ekle/düzenle modal -->
<div class="modal fade" id="szModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <input type="hidden" name="action" value="kaydet">
      <input type="hidden" name="id" id="s_id">
      <div class="modal-header"><h5 class="modal-title" id="s_baslik">Yeni Sözleşme</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small">Sözleşme No <span class="text-danger">*</span></label><input name="sozlesme_no" id="s_no" class="form-control form-control-sm" required placeholder="ör. SZL-2026-014"></div>
          <div class="col-md-6"><label class="form-label small">Taşeron <span class="text-danger">*</span></label><select name="taseron_id" id="s_tas" class="form-select form-select-sm" required><option value="">—</option><?php foreach($taseronlar as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['ad']) ?><?= $t['kod']?' ('.h($t['kod']).')':'' ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label small">Proje</label><select name="proje_id" id="s_prj" class="form-select form-select-sm"><option value="">—</option><?php foreach($projeler as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['kod']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label small">Sözleşme Tarihi</label><input name="tarih" id="s_tarih" type="date" class="form-control form-control-sm"></div>
          <div class="col-12"><label class="form-label small">Konu / Kapsam</label><input name="konu" id="s_konu" class="form-control form-control-sm" placeholder="ör. A Parsel kaba inşaat demir işçiliği"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button><button class="btn btn-success"><i class="bi bi-save me-1"></i>Kaydet</button></div>
    </form>
  </div></div>
</div>

<!-- Islak imzalı sözleşme yükle modal (sürükle-bırak) -->
<div class="modal fade" id="szEvrakModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" enctype="multipart/form-data" class="dz-form">
      <input type="hidden" name="action" value="evrak">
      <input type="hidden" name="id" id="se_id">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-paperclip me-1"></i>Islak İmzalı Sözleşme Yükle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div id="se_scope" class="alert alert-info py-2 px-3 small mb-2 d-none"></div>
        <label class="dz-zone d-flex flex-column align-items-center justify-content-center text-center p-4 border border-2 rounded" style="cursor:pointer;border-style:dashed!important">
            <i class="bi bi-cloud-arrow-up fs-2 text-secondary"></i>
            <span class="fw-semibold mt-1">Taranmış sözleşmeyi buraya sürükleyin ya da tıklayın</span>
            <span class="small text-muted dz-name">PDF, DOCX, DOC, JPG, PNG — maks 20 MB</span>
            <input type="file" name="evrak" accept="application/pdf,.doc,.docx,image/*" class="d-none dz-input" required>
        </label>
        <div class="form-text mt-2"><i class="bi bi-hdd me-1"></i>Dosya veritabanına yazılmaz — sunucuda ayrı klasörde saklanır.</div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary w-100 dz-submit" disabled><i class="bi bi-upload me-1"></i>Yükle</button></div>
    </form>
  </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    if (typeof bootstrap === 'undefined') return;
    var modal = new bootstrap.Modal(document.getElementById('szModal'));
    var evrakModal = new bootstrap.Modal(document.getElementById('szEvrakModal'));
    function set(id,v){ document.getElementById(id).value = (v===null||v===undefined)?'':v; }
    var btn = document.getElementById('btnYeni');
    if (btn) btn.addEventListener('click', function(){
        document.getElementById('s_baslik').textContent = 'Yeni Sözleşme';
        set('s_id',''); set('s_no',''); set('s_tas',''); set('s_prj',''); set('s_tarih',''); set('s_konu','');
        modal.show();
    });
    document.querySelectorAll('.btn-duzenle').forEach(function(b){
        b.addEventListener('click', function(){
            var r = JSON.parse(this.getAttribute('data-json'));
            document.getElementById('s_baslik').textContent = 'Sözleşme Düzenle';
            set('s_id',r.id); set('s_no',r.sozlesme_no); set('s_tas',r.taseron_id); set('s_prj',r.proje_id); set('s_tarih',r.tarih); set('s_konu',r.konu);
            modal.show();
        });
    });
    // Islak imzalı sözleşme yükleme (sürükle-bırak)
    document.querySelectorAll('.btn-soz-evrak').forEach(function(b){
        b.addEventListener('click', function(){
            set('se_id', this.getAttribute('data-id'));
            var no = this.getAttribute('data-no') || '';
            var scope = document.getElementById('se_scope');
            if (no) { scope.innerHTML = '<i class="bi bi-journal-bookmark me-1"></i><strong>' + no + '</strong> sözleşmesinin ıslak imzalı dosyası yüklenecek.'; scope.classList.remove('d-none'); }
            else { scope.classList.add('d-none'); }
            evrakModal.show();
        });
    });
    document.querySelectorAll('#szEvrakModal .dz-form').forEach(function(form){
        var zone = form.querySelector('.dz-zone'), input = form.querySelector('.dz-input'),
            name = form.querySelector('.dz-name'), submit = form.querySelector('.dz-submit');
        if (!zone || !input) return;
        function refresh(){
            if (input.files && input.files.length){
                if (name) name.textContent = input.files[0].name;
                zone.style.borderColor = '#198754'; zone.style.background = '#eafaf1';
                if (submit) submit.disabled = false;
            }
        }
        input.addEventListener('change', refresh);
        ['dragenter','dragover'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zone.style.borderColor='#0d6efd'; zone.style.background='#e7f1ff'; }); });
        ['dragleave','dragend'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zone.style.borderColor=''; zone.style.background='#f8f9fa'; }); });
        zone.addEventListener('drop', function(e){
            e.preventDefault(); e.stopPropagation();
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length){ input.files = e.dataTransfer.files; refresh(); }
        });
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
