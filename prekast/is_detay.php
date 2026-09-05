<?php
/**
 * is_detay.php — Prekast iş kalemi detayı
 *
 * Çizelgeden gelen tüm alanlar + ilerleme takvimi (kesim/silikon damgaları) +
 * aynı dairedeki diğer işler. `ic_not` sistem içi nottur, Excel aktarımında KORUNUR.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi']);
require_once __DIR__ . '/../includes/db_prekast.php';
require_once __DIR__ . '/_ortak.php';

pk_semasi_kur($pdoPrekast);
$id = (int)($_GET['id'] ?? 0);
$yetkili = has_role('admin','teknik_ofis_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'not' && $yetkili) {
    $pdoPrekast->prepare("UPDATE prekast_isler SET ic_not=? WHERE id=?")
               ->execute([trim((string)($_POST['ic_not'] ?? '')) ?: null, $id]);
    flash('success', 'Not kaydedildi.');
    redirect('is_detay.php?id=' . $id);
}

$st = $pdoPrekast->prepare("SELECT * FROM prekast_isler WHERE id=?");
$st->execute([$id]);
$is = $st->fetch();
if (!$is) { flash('error', 'İş kaydı bulunamadı.'); redirect('isler.php'); }

$kardes = $pdoPrekast->prepare("SELECT * FROM prekast_isler WHERE blok=? AND daire=? AND id<>? ORDER BY tekrar");
$kardes->execute([$is['blok'], $is['daire'], $id]);
$kardes = $kardes->fetchAll();

$pageTitle = 'İş Detayı — ' . $is['blok'] . '/' . $is['daire'];
$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
$tarih = fn($d) => $d ? date('d.m.Y', strtotime($d)) : '—';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-bricks text-primary me-2"></i><?= h($is['blok']) ?> Blok / Daire <?= h($is['daire']) ?></h4>
    <span class="badge bg-<?= h(pk_durumRenk($is['durum'])) ?> fs-6"><?= h(pk_durumAd($is['durum'])) ?></span>
    <?php if (!$is['dosyada']): ?><span class="badge bg-dark">Son çizelgede yok</span><?php endif; ?>
    <?php if ((int)$is['tekrar'] > 1): ?><span class="badge bg-light text-dark border">aynı dairedeki <?= (int)$is['tekrar'] ?>. iş</span><?php endif; ?>
    <a href="isler.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-arrow-left me-1"></i>Listeye dön</a>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-card-list me-1"></i>Çizelge bilgileri</div>
        <table class="table table-sm mb-0">
            <tbody>
                <tr><th style="width:180px">Çizelge</th><td><?= h($is['cizelge'] ?: '—') ?></td></tr>
                <tr><th>İş tipi</th><td><?= h($is['is_tipi'] ?: '—') ?></td></tr>
                <tr><th>Çizelge sıra no</th><td><?= $is['sira'] !== null ? (int)$is['sira'] : '—' ?></td></tr>
                <tr><th>Blok / Daire</th><td><?= h($is['blok']) ?> / <?= h($is['daire']) ?></td></tr>
                <tr><th>Metraj</th><td><?= (float)$is['metraj'] > 0 ? $f2($is['metraj']) . ' m' : '<span class="text-muted">girilmemiş</span>' ?></td></tr>
                <tr><th>Birim fiyat</th><td><?= $f2($is['birim_fiyat']) ?> TL</td></tr>
                <tr class="table-light"><th>Hakkediş</th>
                    <td class="fw-bold"><?= $f2($is['hakkedis']) ?> TL
                        <?php if ((float)$is['birim_fiyat'] > 0 && abs((float)$is['metraj'] * (float)$is['birim_fiyat'] - (float)$is['hakkedis']) > 0.5): ?>
                        <span class="badge bg-warning text-dark ms-1" title="Çizelgedeki değer esas alınır">metraj × birim fiyat ile tutmuyor
                            (<?= $f2((float)$is['metraj'] * (float)$is['birim_fiyat']) ?>)</span>
                        <?php endif; ?>
                    </td></tr>
            </tbody>
        </table>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-calendar-check me-1"></i>İlerleme takvimi</div>
        <div class="small text-muted mb-2">Tarihler, işin çizelgede <strong>ilk kez "Yapıldı"</strong> göründüğü
            günün rapor tarihidir.</div>
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex align-items-center gap-2 px-0">
                <i class="bi bi-<?= $is['kesim'] ? 'check-circle-fill text-success' : 'circle text-muted' ?>"></i>
                <span>Kesim<?= $is['kesim_metin'] ? ' <span class="text-muted small">(' . h($is['kesim_metin']) . ')</span>' : '' ?></span>
                <span class="ms-auto text-muted"><?= $tarih($is['kesim_tarih']) ?></span>
            </li>
            <li class="list-group-item d-flex align-items-center gap-2 px-0">
                <i class="bi bi-<?= $is['silikon'] ? 'check-circle-fill text-success' : 'circle text-muted' ?>"></i>
                <span>Silikon<?= $is['silikon_metin'] ? ' <span class="text-muted small">(' . h($is['silikon_metin']) . ')</span>' : '' ?></span>
                <span class="ms-auto text-muted"><?= $tarih($is['silikon_tarih']) ?></span>
            </li>
        </ul>
        <div class="small text-muted mt-2">
            İlk görülme: <strong><?= $tarih($is['ilk_gorulme']) ?></strong> ·
            Son görülme: <strong><?= $tarih($is['son_gorulme']) ?></strong>
            <?php if ($is['kesim_tarih'] && $is['silikon_tarih']): ?>
                · Kesimden silikona: <strong><?= (int)((strtotime($is['silikon_tarih']) - strtotime($is['kesim_tarih'])) / 86400) ?> gün</strong>
            <?php elseif ($is['kesim_tarih'] && !$is['silikon']): ?>
                · Kesimden bu yana: <strong><?= (int)((time() - strtotime($is['kesim_tarih'])) / 86400) ?> gün</strong>
            <?php endif; ?>
        </div>
    </div></div>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-sticky me-1"></i>İç not</div>
        <div class="small text-muted mb-2">Sisteme özel nottur; Excel aktarımlarında <strong>silinmez</strong>.</div>
        <?php if ($yetkili): ?>
        <form method="post">
            <input type="hidden" name="action" value="not">
            <textarea name="ic_not" class="form-control form-control-sm mb-2" rows="4" placeholder="ör. imalat ölçüsü teyit edilecek"><?= h($is['ic_not'] ?? '') ?></textarea>
            <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Kaydet</button>
        </form>
        <?php else: ?>
            <div class="border rounded p-2 bg-light small"><?= nl2br(h($is['ic_not'] ?: '—')) ?></div>
        <?php endif; ?>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="fw-semibold mb-2"><i class="bi bi-diagram-3 me-1"></i>Aynı dairedeki diğer işler</div>
        <?php if ($kardes): ?>
        <table class="table table-sm mb-0" style="font-size:.85rem">
            <thead class="table-light"><tr><th>Sıra</th><th>Durum</th><th class="text-end">Metraj</th><th class="text-end">Hakkediş</th></tr></thead>
            <tbody>
            <?php foreach ($kardes as $k): ?>
                <tr>
                    <td><a href="is_detay.php?id=<?= (int)$k['id'] ?>" class="text-decoration-none"><?= (int)$k['tekrar'] ?>. iş</a></td>
                    <td><span class="badge bg-<?= h(pk_durumRenk($k['durum'])) ?>"><?= h(pk_durumAd($k['durum'])) ?></span></td>
                    <td class="text-end"><?= $f2($k['metraj']) ?></td>
                    <td class="text-end"><?= $f0($k['hakkedis']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="text-muted small">Bu dairede başka iş kalemi yok.</div>
        <?php endif; ?>
    </div></div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
