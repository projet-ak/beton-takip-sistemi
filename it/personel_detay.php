<?php
/**
 * it/personel_detay.php — Personel kartı: bilgiler + üzerindeki cihazlar + zimmet geçmişi
 * İşlemler: "Tümünü iade al" (işten ayrılırken), "İşten çıkış" (açık zimmet varsa engellenir).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
it_semasi_kur($pdoIt);

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$p  = it_personel_bul($pdoIt, $id);
if (!$p) { flash('error', 'Personel bulunamadı.'); redirect('personel.php'); }
$duzenleyebilir = yetki_var('duzenle');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $duzenleyebilir) {
    $islem = $_POST['action'] ?? '';
    $tarih = it_tarih($_POST['tarih'] ?? '') ?: date('Y-m-d');
    $cihazlar = it_personel_cihazlari($pdoIt, $id);
    if ($islem === 'tumunu_iade') {
        foreach ($cihazlar as $c) {
            $pdoIt->prepare("UPDATE it_cihazlar SET personel_id=NULL, zimmetli=NULL, zimmet_tarihi=NULL, durum=IF(durum='aktif','depoda',durum) WHERE id=?")->execute([(int)$c['id']]);
            it_hareket_ekle($pdoIt, (int)$c['id'], 'iade', it_personel_ad($p), trim((string)($_POST['aciklama'] ?? '')) ?: 'Toplu iade (personel kartından)', $tarih);
        }
        flash('success', count($cihazlar) . ' cihaz iade alındı, depoya girdi.');
    } elseif ($islem === 'cikis') {
        if ($cihazlar) {
            flash('error', 'Üzerinde ' . count($cihazlar) . ' zimmetli cihaz varken işten çıkış kapatılamaz. Önce "Tümünü iade al".');
        } else {
            $pdoIt->prepare("UPDATE it_personel SET isten_cikis=? WHERE id=?")->execute([$tarih, $id]);
            flash('success', 'İşten çıkış ' . format_date($tarih) . ' olarak kaydedildi.');
        }
    } elseif ($islem === 'geri_al') {
        $pdoIt->prepare("UPDATE it_personel SET isten_cikis=NULL WHERE id=?")->execute([$id]);
        flash('success', 'İşten çıkış kaldırıldı; personel yeniden çalışıyor.');
    }
    redirect('personel_detay.php?id=' . $id);
}

$cihazlar = it_personel_cihazlari($pdoIt, $id);
$aktif = it_personel_aktif($p);
$adSoyad = it_personel_ad($p);
// Zimmet geçmişi: personel_id bağlı cihazların hareketleri + eski metin eşleşmesi (kisi = ad soyad)
$hst = $pdoIt->prepare("SELECT h.*, c.envanter_no, c.ad cihaz_ad FROM it_hareketler h JOIN it_cihazlar c ON c.id=h.cihaz_id
                        WHERE (c.personel_id=? OR h.kisi=?) AND h.tur IN ('zimmet','iade','servis','ariza','donus','hurda')
                        ORDER BY h.tarih DESC, h.id DESC LIMIT 100");
$hst->execute([$id, $adSoyad]);
$gecmis = $hst->fetchAll();
$mali = array_sum(array_map(fn($c) => (float)($c['fiyat'] ?? 0), $cihazlar));
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
$pageTitle = $adSoyad . ' — Personel';
require_once __DIR__ . '/../includes/header.php';
$bilgi = fn($e, $v) => '<div class="col-sm-6 col-lg-4"><div class="small text-muted">' . h($e) . '</div><div class="fw-semibold">' . ($v !== '' && $v !== null ? h((string)$v) : '—') . '</div></div>';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a href="personel.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i><?= h($adSoyad) ?></h4>
    <?php if ($p['sicil_no']): ?><span class="badge bg-light text-dark border font-monospace"><?= h($p['sicil_no']) ?></span><?php endif; ?>
    <?= $aktif ? '<span class="badge bg-success">Çalışıyor</span>' : '<span class="badge bg-secondary">Ayrıldı ' . format_date($p['isten_cikis']) . '</span>' ?>
    <?php if (!$aktif && $cihazlar): ?><span class="badge bg-danger">Açık zimmet var!</span><?php endif; ?>
    <div class="ms-auto d-flex gap-2">
        <?php if ($cihazlar): ?><a href="zimmet_tutanak.php?personel_id=<?= $id ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Toplu Zimmet Tutanağı</a><?php endif; ?>
        <?php if ($duzenleyebilir): ?><a href="personel_form.php?id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Düzenle</a><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><div class="row g-3">
        <?= $bilgi('Unvan', $p['unvan']) ?>
        <?= $bilgi('Birim / Departman', $p['birim']) ?>
        <?= $bilgi('Lokasyon / Proje', it_lokasyon_yol($pdoIt, (int)$p['lokasyon_id'])) ?>
        <?= $bilgi('Telefon', $p['telefon']) ?>
        <?= $bilgi('E-posta', $p['eposta']) ?>
        <?= $bilgi('İşe Giriş', $p['ise_giris'] ? format_date($p['ise_giris']) : null) ?>
        <?= $bilgi('İşten Çıkış', $p['isten_cikis'] ? format_date($p['isten_cikis']) : null) ?>
        <?= $bilgi('Zimmetli cihaz', count($cihazlar) . ' adet') ?>
        <?= $bilgi('Zimmet değeri', $f2($mali) . ' TL') ?>
    </div><?php if ($p['notlar']): ?><div class="alert alert-light border mt-3 mb-0" style="white-space:pre-wrap"><?= h($p['notlar']) ?></div><?php endif; ?></div></div>

    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex align-items-center"><strong><i class="bi bi-pc-display me-1"></i>Üzerindeki cihazlar</strong><span class="badge bg-primary ms-2"><?= count($cihazlar) ?></span>
        <?php if ($cihazlar && $duzenleyebilir): ?>
        <form method="post" class="ms-auto d-flex gap-1" onsubmit="return confirm('Kişinin üzerindeki TÜM cihazlar iade alınıp depoya girecek. Devam?')">
          <input type="hidden" name="action" value="tumunu_iade"><input type="date" name="tarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" style="width:150px">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-return-left me-1"></i>Tümünü iade al</button></form>
        <?php endif; ?>
      </div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.85rem">
        <thead class="table-light"><tr><th>Envanter No</th><th>Cihaz</th><th>Kategori</th><th>Seri No</th><th>Zimmet Tarihi</th><th>Durum</th><th class="text-end">Değer</th><th></th></tr></thead>
        <tbody>
        <?php if (!$cihazlar): ?><tr><td colspan="8" class="text-center text-muted py-3">Zimmetli cihaz yok.</td></tr><?php endif; ?>
        <?php foreach ($cihazlar as $c): ?>
          <tr><td class="font-monospace"><a href="cihaz_detay.php?id=<?= (int)$c['id'] ?>" class="text-decoration-none"><?= h($c['envanter_no']) ?></a></td><td><?= h($c['ad']) ?><div class="small text-muted"><?= h(trim(($c['marka'] ?? '') . ' ' . ($c['model'] ?? ''))) ?></div></td>
              <td><?= h(it_kategoriAd($c['kategori'])) ?></td><td class="font-monospace small"><?= h($c['seri_no'] ?: '—') ?></td><td><?= $c['zimmet_tarihi'] ? format_date($c['zimmet_tarihi']) : '—' ?></td>
              <td><?= it_durumBadge($c['durum']) ?></td><td class="text-end"><?= $c['fiyat'] !== null ? $f2($c['fiyat']) : '—' ?></td>
              <td class="text-end"><a href="zimmet_tutanak.php?id=<?= (int)$c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="Tutanak"><i class="bi bi-file-earmark-text"></i></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white"><strong><i class="bi bi-clock-history me-1"></i>Zimmet geçmişi</strong></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.85rem">
        <thead class="table-light"><tr><th>Tarih</th><th>Cihaz</th><th>İşlem</th><th>Açıklama</th><th>Kaydeden</th></tr></thead>
        <tbody>
        <?php if (!$gecmis): ?><tr><td colspan="5" class="text-center text-muted py-3">Hareket yok.</td></tr><?php endif; ?>
        <?php foreach ($gecmis as $hr): $hx = IT_HAREKET[$hr['tur']] ?? [$hr['tur'], 'secondary', 'bi-dot']; ?>
          <tr><td class="text-nowrap"><?= format_date($hr['tarih']) ?></td><td><a href="cihaz_detay.php?id=<?= (int)$hr['cihaz_id'] ?>" class="font-monospace text-decoration-none"><?= h($hr['envanter_no']) ?></a> <span class="small text-muted"><?= h($hr['cihaz_ad']) ?></span></td>
              <td><span class="badge bg-<?= $hx[1] ?><?= in_array($hx[1], ['light','warning'], true) ? ' text-dark' : '' ?>"><?= h($hx[0]) ?></span></td><td class="small"><?= h($hr['aciklama'] ?: '—') ?></td><td class="small text-muted"><?= h($hr['kullanici'] ?: '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>

  <div class="col-lg-4">
    <?php if ($duzenleyebilir): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white"><strong><i class="bi bi-door-closed me-1"></i>İşten çıkış</strong></div>
      <div class="card-body">
        <?php if ($aktif): ?>
        <form method="post" onsubmit="return confirm('İşten çıkış kaydedilsin mi?')">
          <input type="hidden" name="action" value="cikis">
          <div class="mb-2"><input type="date" name="tarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
          <?php if ($cihazlar): ?><div class="alert alert-warning py-1 px-2 small mb-2">Üzerinde <?= count($cihazlar) ?> cihaz var — önce "Tümünü iade al".</div><?php endif; ?>
          <button class="btn btn-outline-dark btn-sm w-100" <?= $cihazlar ? 'disabled' : '' ?>><i class="bi bi-box-arrow-right me-1"></i>İşten çıkışı kaydet</button>
        </form>
        <?php else: ?>
        <form method="post"><input type="hidden" name="action" value="geri_al"><button class="btn btn-outline-success btn-sm w-100"><i class="bi bi-arrow-counterclockwise me-1"></i>Çıkışı geri al (yeniden çalışıyor)</button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white"><strong><i class="bi bi-info-circle me-1"></i>Zimmet kuralı</strong></div>
      <div class="card-body small text-muted">
        Cihazlar kişiye <strong>cihaz kartındaki "Zimmet ver"</strong> ile bağlanır. İşten ayrılan personelin zimmeti kapatılmadan çıkış kaydedilmez; böylece "cihaz kimde kaldı" sorusu doğmaz.
        Toplu zimmet tutanağı kişinin üzerindeki tüm cihazları tek belgede listeler.
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
