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
// ── Sil (bağlı tutanak varsa engelle) ─────────────────────────────────────────
if (has_role('admin','teknik_ofis_admin') && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $sid = (int)$_GET['sil'];
    $c = $pdoDemir->prepare("SELECT COUNT(*) FROM demir_tutanaklar WHERE sozlesme_id=?"); $c->execute([$sid]);
    if ($c->fetchColumn() > 0) {
        flash('error', 'Bu sözleşmeye bağlı tutanak var — silinemez. Önce tutanaklardaki bağı kaldırın.');
    } else {
        $pdoDemir->prepare("DELETE FROM demir_sozlesmeler WHERE id=?")->execute([$sid]);
        flash('success', 'Sözleşme silindi.');
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
            <th class="text-center">Tutanak</th><th class="text-end">Teslim (t)</th><?php if($canEdit): ?><th class="text-end">İşlem</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($liste as $idx=>$r): $caps = $capKirilim[(int)$r['id']] ?? []; ?>
            <tr>
                <td><?php if($caps): ?><button class="btn btn-xs btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#sz<?= $idx ?>" title="Çap kırılımı"><i class="bi bi-chevron-down"></i></button><?php endif; ?></td>
                <td class="fw-semibold font-monospace"><?= h($r['sozlesme_no']) ?></td>
                <td><?= h($r['taseron_adi'] ?: '—') ?><?= $r['taseron_kod']?' <span class="text-muted small">('.h($r['taseron_kod']).')</span>':'' ?></td>
                <td><?= $r['proje_kod'] ? '<span class="badge bg-secondary">'.h($r['proje_kod']).'</span>' : '—' ?></td>
                <td class="text-nowrap"><?= format_date($r['tarih']) ?></td>
                <td class="small"><?= h($r['konu'] ?: '—') ?></td>
                <td class="text-center"><?= (int)$r['tutanak_sayi'] ?: '—' ?></td>
                <td class="text-end fw-bold"><?= $r['ton']>0 ? $fmt($r['ton']) : '—' ?></td>
                <?php if ($canEdit): ?>
                <td class="text-end text-nowrap">
                    <a href="tutanaklar.php" class="btn btn-xs btn-outline-secondary" title="Tutanaklar"><i class="bi bi-file-earmark-check"></i></a>
                    <button class="btn btn-xs btn-outline-primary btn-duzenle" data-json='<?= h(json_encode($r, JSON_UNESCAPED_UNICODE)) ?>' title="Düzenle"><i class="bi bi-pencil"></i></button>
                    <?php if (has_role('admin','teknik_ofis_admin')): ?>
                    <a href="sozlesmeler.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Bu sözleşme silinsin mi?')"><i class="bi bi-trash"></i></a>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php if ($caps): ?>
            <tr class="collapse" id="sz<?= $idx ?>">
                <td></td>
                <td colspan="<?= $canEdit?8:7 ?>" class="p-0">
                    <table class="table table-sm mb-0 bg-light">
                        <thead><tr class="small text-muted"><th>Çap</th><th class="text-end">Teslim (t)</th></tr></thead>
                        <tbody>
                        <?php foreach ($caps as $ck): ?>
                            <tr><td><?= h($ck['cap'] ?: '(çap belirsiz)') ?></td><td class="text-end fw-semibold"><?= $fmt($ck['ton']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$liste): ?>
            <tr><td colspan="<?= $canEdit?9:8 ?>" class="text-center text-muted py-5">
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

<script>
document.addEventListener('DOMContentLoaded', function(){
    if (typeof bootstrap === 'undefined') return;
    var modal = new bootstrap.Modal(document.getElementById('szModal'));
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
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
