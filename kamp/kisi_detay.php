<?php
/**
 * kamp/kisi_detay.php — KİŞİ KARTI: künye, şu anki yatağı, tüm konaklama geçmişi
 * ("hangi tarihte geldi, nerede kaldı, ne zaman çıktı"), yerleştir / taşı / çıkış,
 * tarih düzeltme ve yanlış kaydı silme (değiştirme yetkisi), oda teslim tutanağı.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$id = (int)($_GET['id'] ?? 0);
$k  = kamp_kisi_bul($pdoKamp, $id);
if (!$k) { flash('error', 'Kişi bulunamadı.'); redirect('kisiler.php'); }
$yaz = yetki_var('giris') || yetki_var('duzenle');
$duz = yetki_var('duzenle');
$acik = kamp_acik_konaklama($pdoKamp, $id);
$st = $pdoKamp->prepare("SELECT k.*, y.no AS yatak_no, o.ad AS oda_ad, o.kat, b.ad AS bina_ad, b.id AS bina_id, f.ad AS firma_ad
                           FROM kamp_konaklama k
                           LEFT JOIN kamp_yataklar y ON y.id=k.yatak_id
                           LEFT JOIN kamp_odalar o ON o.id=COALESCE(y.oda_id,k.oda_id)
                           LEFT JOIN kamp_binalar b ON b.id=o.bina_id
                           LEFT JOIN kamp_firmalar f ON f.id=k.firma_id
                          WHERE k.kisi_id=? ORDER BY k.giris DESC, k.id DESC");
$st->execute([$id]);
$gecmis = $st->fetchAll();
$topGece = 0; foreach ($gecmis as $g) $topGece += kamp_sure($g['giris'], $g['cikis']);
$bos = $yaz ? kamp_bos_yataklar($pdoKamp) : [];
$geri = 'kisi_detay.php?id=' . $id;
$ziyaret = kamp_ziyaret_liste($pdoKamp, ['kisi_id' => $id]);

$pageTitle = kamp_kisi_ad($k) . ' — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-vcard text-primary me-2"></i><?= h(kamp_kisi_ad($k)) ?></h4>
  <?= kamp_katRozet($k['kategori']) ?>
  <?= $acik ? '<span class="badge bg-success">kampta</span>' : ($gecmis ? '<span class="badge bg-secondary">ayrıldı</span>' : '<span class="badge bg-warning text-dark">yerleşmedi</span>') ?>
  <div class="ms-auto d-flex gap-2">
    <?php if ($duz): ?><a href="kisi_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Düzenle</a><?php endif; ?>
    <a href="kisiler.php" class="btn btn-light btn-sm">Liste</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
      <table class="table table-sm mb-0" style="font-size:.88rem">
        <?php foreach ([['Firma',$k['firma_ad']],['Görev',$k['gorev']],['TC No',$k['tc_no']],['Sicil No',$k['sicil_no']],['Telefon',$k['telefon']],
                        ['Acil durum',trim(($k['acil_kisi'] ?? '') . ' ' . ($k['acil_tel'] ?? ''))],['Kayıt',kamp_tarih_yaz(substr((string)$k['created_at'],0,10))]] as [$e,$d]): ?>
        <tr><th class="text-muted fw-normal" style="width:40%"><?= $e ?></th><td><?= h($d ?: '—') ?></td></tr>
        <?php endforeach; ?>
        <tr><th class="text-muted fw-normal">Toplam konaklama</th><td><?= count($gecmis) ?> kayıt · <strong><?= $topGece ?></strong> gece</td></tr>
      </table>
      <?php if ($k['notlar']): ?><div class="small mt-2 p-2 bg-body-tertiary rounded"><?= nl2br(h($k['notlar'])) ?></div><?php endif; ?>
    </div></div>

    <div class="card border-0 shadow-sm mb-3 <?= $acik ? 'border-start border-success border-4' : '' ?>">
      <div class="card-header bg-white"><strong><?= $acik ? 'Şu an kaldığı yer' : 'Kampa yerleştir' ?></strong></div>
      <div class="card-body">
      <?php if ($acik): ?>
        <div class="fs-6 fw-semibold"><?= h(kamp_yatak_etiket($acik)) ?></div>
        <div class="small text-muted mb-2"><?= h(kamp_tarih_yaz($acik['giris'])) ?>'den beri · <?= kamp_sure($acik['giris'], null) ?> gece</div>
        <a href="tutanak.php?konaklama=<?= (int)$acik['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-printer me-1"></i>Oda teslim tutanağı</a>
        <a href="yerlesim.php?bina=<?= (int)$acik['bina_id'] ?>" class="btn btn-outline-primary btn-sm mb-2"><i class="bi bi-grid-3x3-gap me-1"></i>Krokide</a>
        <?php if ($yaz): ?>
        <hr>
        <form method="post" action="konaklama.php" class="mb-3">
          <input type="hidden" name="islem" value="tasi"><input type="hidden" name="kisi_id" value="<?= $id ?>"><input type="hidden" name="geri" value="<?= h($geri) ?>">
          <label class="form-label small fw-semibold">Başka yatağa taşı</label>
          <select name="yatak_id" class="form-select form-select-sm mb-1" required data-ara><option value="">— boş yatak seçin —</option>
            <?php $og = null; foreach ($bos as $y): $g = $y['bina_ad'] . ' · ' . kamp_kat_ad((int)$y['kat']);
              if ($g !== $og) { if ($og !== null) echo '</optgroup>'; echo '<optgroup label="' . h($g) . '">'; $og = $g; } ?>
              <option value="<?= (int)$y['id'] ?>"><?= h($y['oda_ad'] . ' › ' . $y['no']) ?></option>
            <?php endforeach; if ($og !== null) echo '</optgroup>'; ?></select>
          <div class="d-flex gap-2"><input type="date" name="tarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
            <button class="btn btn-primary btn-sm">Taşı</button></div>
        </form>
        <form method="post" action="konaklama.php" onsubmit="return confirm('Çıkış kaydedilsin mi?')">
          <input type="hidden" name="islem" value="cikis"><input type="hidden" name="konaklama_id" value="<?= (int)$acik['id'] ?>"><input type="hidden" name="geri" value="<?= h($geri) ?>">
          <label class="form-label small fw-semibold">Kamptan çıkış</label>
          <div class="d-flex gap-2 mb-1"><input type="date" name="tarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
            <input name="sebep" class="form-control form-control-sm" list="dlSebep" placeholder="sebep"></div>
          <input name="aciklama" class="form-control form-control-sm mb-1" placeholder="açıklama (demirbaş teslim durumu vb.)">
          <button class="btn btn-danger btn-sm">Çıkışı kaydet</button>
        </form>
        <?php endif; ?>
      <?php elseif ($yaz): ?>
        <form method="post" action="konaklama.php">
          <input type="hidden" name="islem" value="yerlestir"><input type="hidden" name="kisi_id" value="<?= $id ?>"><input type="hidden" name="geri" value="<?= h($geri) ?>">
          <select name="yatak_id" class="form-select form-select-sm mb-2" required data-ara><option value="">— boş yatak seçin —</option>
            <?php $og = null; foreach ($bos as $y): $g = $y['bina_ad'] . ' · ' . kamp_kat_ad((int)$y['kat']);
              if ($g !== $og) { if ($og !== null) echo '</optgroup>'; echo '<optgroup label="' . h($g) . '">'; $og = $g; } ?>
              <option value="<?= (int)$y['id'] ?>"><?= h($y['oda_ad'] . ' › ' . $y['no']) ?></option>
            <?php endforeach; if ($og !== null) echo '</optgroup>'; ?></select>
          <div class="d-flex gap-2"><input type="date" name="tarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
            <button class="btn btn-primary btn-sm">Yerleştir</button></div>
          <?php if (!$bos): ?><div class="small text-danger mt-2">Kampta boş yatak yok.</div><?php endif; ?>
        </form>
      <?php else: ?><span class="text-muted">Kampta değil.</span><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white"><strong>Konaklama geçmişi</strong></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.86rem">
        <thead class="table-light"><tr><th>Yer</th><th>Firma</th><th>Giriş</th><th>Çıkış</th><th class="text-end">Gece</th><th></th></tr></thead><tbody>
        <?php if (!$gecmis): ?><tr><td colspan="6" class="text-muted text-center py-4">Konaklama kaydı yok.</td></tr><?php endif; ?>
        <?php foreach ($gecmis as $g): ?>
          <tr class="<?= $g['cikis'] ? '' : 'table-success' ?>">
            <td><?= h(kamp_yatak_etiket($g)) ?><?= $g['aciklama'] ? '<div class="small text-muted">' . h($g['aciklama']) . '</div>' : '' ?></td>
            <td class="small"><?= h($g['firma_ad'] ?: '—') ?></td>
            <td><?= h(kamp_tarih_yaz($g['giris'])) ?></td>
            <td><?= $g['cikis'] ? h(kamp_tarih_yaz($g['cikis'])) . ($g['cikis_sebep'] ? '<div class="small text-muted">' . h($g['cikis_sebep']) . '</div>' : '') : '<span class="badge bg-success">kalıyor</span>' ?></td>
            <td class="text-end"><?= kamp_sure($g['giris'], $g['cikis']) ?></td>
            <td class="text-end text-nowrap">
              <a href="tutanak.php?konaklama=<?= (int)$g['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-0" title="Tutanak"><i class="bi bi-printer"></i></a>
              <?php if ($duz): ?><button type="button" class="btn btn-outline-primary btn-sm py-0" data-bs-toggle="collapse" data-bs-target="#dz<?= (int)$g['id'] ?>" title="Tarih düzelt"><i class="bi bi-pencil"></i></button><?php endif; ?>
            </td>
          </tr>
          <?php if ($duz): ?>
          <tr class="collapse" id="dz<?= (int)$g['id'] ?>"><td colspan="6" class="bg-body-tertiary">
            <form method="post" action="konaklama.php" class="row g-2 align-items-end">
              <input type="hidden" name="islem" value="duzelt"><input type="hidden" name="konaklama_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="geri" value="<?= h($geri) ?>">
              <div class="col-md-3"><label class="form-label small mb-0">Giriş</label><input type="date" name="giris" class="form-control form-control-sm" value="<?= h($g['giris']) ?>" max="<?= date('Y-m-d') ?>" required></div>
              <div class="col-md-3"><label class="form-label small mb-0">Çıkış (boş = kalıyor)</label><input type="date" name="cikis" class="form-control form-control-sm" value="<?= h($g['cikis']) ?>" max="<?= date('Y-m-d') ?>"></div>
              <div class="col-md-3"><label class="form-label small mb-0">Çıkış sebebi</label><input name="cikis_sebep" class="form-control form-control-sm" list="dlSebep" value="<?= h($g['cikis_sebep']) ?>"></div>
              <div class="col-md-3 d-flex gap-1"><button class="btn btn-primary btn-sm">Düzelt</button>
                <button class="btn btn-outline-danger btn-sm" name="islem" value="sil" onclick="return confirm('Bu konaklama kaydı SİLİNSİN mi? Yalnız yanlış girilen kayıt için kullanın — gerçek ayrılışta çıkış kaydedin.')">Kaydı sil</button></div>
            </form>
          </td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
      <div class="card-header bg-white d-flex align-items-center"><strong><i class="bi bi-person-badge me-1"></i>Ziyaretçileri</strong>
        <span class="badge bg-secondary ms-2"><?= count($ziyaret) ?></span>
        <?php if ($yaz): ?><a href="ziyaret_form.php?kisi_id=<?= $id ?>" class="btn btn-outline-primary btn-sm ms-auto py-0"><i class="bi bi-plus-lg me-1"></i>Misafir girişi</a><?php endif; ?></div>
      <div class="table-responsive" style="max-height:320px;overflow:auto"><table class="table table-sm align-middle mb-0" style="font-size:.86rem">
        <thead class="table-light"><tr><th>Ziyaretçi</th><th>Giriş</th><th>Çıkış</th><th>Onay</th></tr></thead><tbody>
        <?php if (!$ziyaret): ?><tr><td colspan="4" class="text-muted text-center py-3">Bu kişiye kayıtlı ziyaretçi yok.</td></tr><?php endif; ?>
        <?php foreach ($ziyaret as $zz): ?>
          <tr class="<?= $zz['i']['ihlal'] || $zz['i']['izinsiz'] ? 'table-danger' : '' ?>">
            <td><strong><?= h($zz['ad_soyad']) ?></strong> <?= kamp_ziyaret_rozet($zz['i']) ?><div class="small text-muted"><?= h(trim(($zz['kurum'] ?? '') . ($zz['amac'] ? ' · ' . $zz['amac'] : ''), ' ·')) ?></div>
              <?php if ($zz['i']['ihlal'] || $zz['i']['izinsiz'] || $zz['tutanak_url'] || kamp_olay_dolu(kamp_olay($zz))): ?><a href="olay_tutanak.php?id=<?= (int)$zz['id'] ?>" class="small text-danger"><i class="bi <?= $zz['tutanak_url'] ? 'bi-file-earmark-check' : 'bi-printer' ?> me-1"></i>Olay tutanağı <?= h(kamp_olay_no((int)$zz['id'])) ?></a><?php endif; ?></td>
            <td class="text-nowrap small"><?= h(kamp_zaman_yaz($zz['giris'])) ?></td>
            <td class="text-nowrap small"><?= $zz['cikis'] ? h(kamp_zaman_yaz($zz['cikis'])) : '<span class="badge bg-primary">içeride</span>' ?></td>
            <td class="small"><?= $zz['gece_izin'] ? '<span class="badge bg-dark">gece izni</span> ' : '' ?><?= h($zz['onaylayan'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>
</div>
<datalist id="dlSebep"><?php foreach (KAMP_CIKIS_SEBEP as $s): ?><option value="<?= h($s) ?>"><?php endforeach; ?></datalist>
<script src="../assets/js/secici.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
