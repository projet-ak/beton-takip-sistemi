<?php
/**
 * hareket_sonuc.php — Giriş/çıkış kaydından sonraki "işlem sonu" ekranı
 *
 * Kayıt bittiğinde kullanıcının üç işi kalır ve üçü de burada:
 *   1) DEPO ÇIKIŞ FİŞİNİ yazdır (girişte teslim alma tutanağı, hurdada hurda tutanağı),
 *   2) aynı fişe başka malzeme ekle (fiş no/firma/tarih taşınır — bir fiş, birçok kalem),
 *   3) imzalanan fişi tarayıp GERİ YÜKLE (`evrak_url`; fişin tüm satırlarına bağlanır).
 *
 * Fiş = tür + kaynak + hurda + belge no + tarih + firma (bkz. dp_fis_satirlari).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';

dp_hareket_semasi_kur($pdoDepo);

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$st = $pdoDepo->prepare("SELECT * FROM depo_hareketler WHERE id=?");
$st->execute([$id]);
$hr = $st->fetch();
if (!$hr) { flash('error', 'Hareket bulunamadı.'); redirect('hareketler.php'); }

// ── İmzalı fişi geri yükle ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'evrak_yukle') {
    [$ok, $mesaj] = dp_evrak_kaydet($pdoDepo, $hr, $_FILES['evrak'] ?? []);
    flash($ok ? 'success' : 'error', $mesaj);
    redirect('hareket_sonuc.php?id=' . $id);
}
if (isset($_GET['evrak_sil']) && has_role('admin','teknik_ofis_admin')) {
    dp_evrak_bagi_kaldir($pdoDepo, $id);
    flash('success', 'Evrak bağı kaldırıldı.');
    redirect('hareket_sonuc.php?id=' . $id);
}

$g     = $hr['tur'] === 'giris';
$hurda = !empty($hr['hurda']);
$ids   = dp_fis_satirlari($pdoDepo, $hr);
$sat   = $pdoDepo->prepare("SELECT * FROM depo_hareketler WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id");
$sat->execute($ids);
$satirlar = $sat->fetchAll();

$formAd = $hurda ? 'Hurdaya Ayırma Tutanağı' : ($g ? 'Malzeme Teslim Alma Tutanağı' : 'Depo Çıkış Fişi');
$fisNo  = trim((string)($hr['belge_no'] ?? ''));
$evrak  = (string)($hr['evrak_url'] ?? '');
$fmt    = fn($n) => number_format((float)$n, abs((float)$n - round((float)$n)) < 0.0005 ? 0 : 2, ',', '.');

// "Aynı fişe malzeme ekle" — ortak bilgileri forma taşı
$devam = http_build_query(array_filter([
    'tur'          => $hr['tur'],
    'kaynak'       => $hr['kaynak'],
    'tarih'        => $hr['tarih'],
    'belge_no'     => $fisNo,
    'belge_tarihi' => $hr['belge_tarihi'],
    'firma'        => $hr['firma'],
    'teslim_alan'  => $hr['teslim_alan'],
    'onay'         => $hr['onay'],
    'lokasyon'     => $hr['lokasyon'],
], fn($v) => $v !== null && $v !== ''));

$pageTitle = 'İşlem Tamam — Depo';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="hareketler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-check-circle-fill text-success me-1"></i>İşlem tamamlandı</h4>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <!-- Kayıt özeti + fişin kalemleri -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex align-items-center gap-2">
        <i class="bi <?= $hurda ? 'bi-trash3 text-warning' : ($g ? 'bi-box-arrow-in-down text-success' : 'bi-box-arrow-up text-danger') ?>"></i>
        <strong><?= h($formAd) ?></strong>
        <span class="ms-auto small text-muted">
            <?= format_date($hr['tarih']) ?> · <?= h(dp_kaynakAd($hr['kaynak'])) ?>
            <?php if ($fisNo): ?> · <?= $g ? 'İrsaliye' : 'Fiş' ?> No: <strong><?= h($fisNo) ?></strong><?php endif; ?>
        </span>
      </div>
      <div class="card-body">
        <div class="row g-2 small mb-3">
          <div class="col-sm-6"><span class="text-muted"><?= $g ? 'Gönderen firma' : 'Çıkış yapılan firma / taşeron' ?>:</span>
              <strong><?= h($hr['firma'] ?: '—') ?></strong></div>
          <div class="col-sm-6"><span class="text-muted">Teslim alan:</span> <strong><?= h($hr['teslim_alan'] ?: '—') ?></strong></div>
          <div class="col-sm-6"><span class="text-muted">Onaylayan:</span> <strong><?= h($hr['onay'] ?: '—') ?></strong></div>
          <div class="col-sm-6"><span class="text-muted">Lokasyon:</span> <strong><?= h($hr['lokasyon'] ?: '—') ?></strong></div>
        </div>

        <div class="table-responsive">
        <table class="table table-sm mb-1">
          <thead class="table-light"><tr><th style="width:34px">#</th><th>Malzeme</th><th>Özellik</th>
              <th class="text-end">Miktar</th><th>Birim</th><th>Stok</th></tr></thead>
          <tbody>
          <?php foreach ($satirlar as $i => $r): ?>
            <tr class="<?= (int)$r['id'] === $id ? 'table-success' : '' ?>">
              <td><?= $i + 1 ?></td>
              <td><a href="malzeme_ekstre.php?m=<?= urlencode($r['malzeme']) ?>" class="text-decoration-none"><?= h($r['malzeme']) ?></a></td>
              <td class="small text-muted"><?= h($r['ozellik'] ?: '—') ?></td>
              <td class="text-end"><strong><?= $fmt($r['miktar']) ?></strong></td>
              <td class="small"><?= h($r['birim'] ?: 'Adet') ?></td>
              <td class="small">
                <?php if (!empty($r['kalem_id'])): ?>
                  <span class="badge bg-success-subtle text-success-emphasis" title="Canlı stok kartına işlendi">işlendi</span>
                <?php else: ?>
                  <span class="badge bg-light text-muted" title="Yalnız hareket defterine yazıldı">defter</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <?php if (count($satirlar) > 1): ?>
          <tfoot><tr class="fw-semibold"><td colspan="3">Fiş toplamı (<?= count($satirlar) ?> kalem)</td>
              <td class="text-end"><?= $fmt(array_sum(array_map(fn($r) => (float)$r['miktar'], $satirlar))) ?></td><td colspan="2"></td></tr></tfoot>
          <?php endif; ?>
        </table>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-2">
          <a href="hareket_tutanak.php?id=<?= $id ?>" target="_blank" class="btn btn-primary">
              <i class="bi bi-printer me-1"></i><?= $hurda ? 'Hurda Tutanağını' : ($g ? 'Teslim Tutanağını' : 'Çıkış Fişini') ?> Yazdır</a>
          <?php if ($fisNo): ?>
          <a href="hareket_form.php?<?= h($devam) ?>" class="btn btn-outline-primary">
              <i class="bi bi-plus-lg me-1"></i>Aynı fişe malzeme ekle</a>
          <?php endif; ?>
          <a href="hareket_form.php?tur=<?= h($hr['tur']) ?>" class="btn btn-outline-secondary">
              <i class="bi bi-file-earmark-plus me-1"></i>Yeni <?= $g ? 'giriş' : 'çıkış' ?></a>
          <a href="hareketler.php" class="btn btn-outline-secondary">Hareket defteri</a>
        </div>
      </div>
    </div>

    <!-- İmzalı fişi geri yükle -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white"><i class="bi bi-paperclip me-1"></i><strong>İmzalı fişi geri yükle</strong></div>
      <div class="card-body">
        <?php if ($evrak): ?>
          <div class="alert alert-success py-2 small d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-file-earmark-check-fill"></i>
            <a href="../<?= h($evrak) ?>" target="_blank" class="alert-link">İmzalı belge yüklendi — aç</a>
            <?php if (count($satirlar) > 1): ?><span class="text-muted">(fişin <?= count($satirlar) ?> satırına bağlı)</span><?php endif; ?>
            <?php if (has_role('admin','teknik_ofis_admin')): ?>
            <a href="?id=<?= $id ?>&evrak_sil=1" class="btn btn-sm btn-outline-danger py-0 ms-auto"
               onclick="return confirm('Bu satırın evrak bağı kaldırılsın mı?')">Bağı kaldır</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
          <input type="hidden" name="action" value="evrak_yukle">
          <div class="col-md-8">
            <label class="form-label small mb-1">
                Yazdırılıp imzalanan <?= h(mb_strtolower($formAd, 'UTF-8')) ?> taraması veya fotoğrafı
                <span class="text-muted">(PDF, JPG, PNG — maks 10 MB)</span></label>
            <input type="file" name="evrak" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
          </div>
          <div class="col-md-4">
            <button class="btn btn-success btn-sm w-100"><i class="bi bi-upload me-1"></i><?= $evrak ? 'Değiştir' : 'Yükle' ?></button>
          </div>
        </form>
        <div class="form-text mt-1">
          Fişi yazdır → imzalat → buradan yükle. Belge<?= count($satirlar) > 1 ? ' bu fişin tüm kalemlerine' : '' ?>
          bağlanır; hareket defterinde <i class="bi bi-file-earmark-check text-success"></i> simgesiyle görünür ve
          Excel içe aktarımında <strong>korunur</strong>.
        </div>
      </div>
    </div>
  </div>

  <!-- Fişin ön izlemesi -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex align-items-center">
        <i class="bi bi-file-earmark-text me-1"></i><strong>Fiş ön izleme</strong>
        <a href="hareket_tutanak.php?id=<?= $id ?>" target="_blank" class="ms-auto small">yeni sekmede aç</a>
      </div>
      <div class="card-body p-0">
        <iframe src="hareket_tutanak.php?id=<?= $id ?>&gomulu=1" title="Fiş ön izleme"
                style="width:100%;height:620px;border:0;background:#f0f0f0"></iframe>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
