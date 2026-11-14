<?php
/**
 * kamp/oda.php — ODA KARTI: yataklar (kim yatıyor), demirbaşlar (durumlarıyla), oda geçmişi
 * ("bu odada kim, hangi tarihler arasında kaldı"). Yatak kullanım dışı yapma / geri açma burada.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$id = (int)($_GET['id'] ?? $_POST['oda_id'] ?? 0);
$st = $pdoKamp->prepare("SELECT o.*, b.ad AS bina_ad, b.tur AS bina_tur FROM kamp_odalar o JOIN kamp_binalar b ON b.id=o.bina_id WHERE o.id=?");
$st->execute([$id]);
$oda = $st->fetch();
if (!$oda) { flash('error', 'Oda bulunamadı.'); redirect('yerlesim.php'); }
$duz = yetki_var('duzenle');
$yaz = $duz || yetki_var('giris');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $islem = (string)($_POST['islem'] ?? '');
        if (!$duz) throw new RuntimeException('Bu işlem için değiştirme yetkisi gerekir.');
        if ($islem === 'yatak_durum') {
            $yid = (int)$_POST['yatak_id']; $aktif = (int)$_POST['aktif'] ? 1 : 0;
            if (!$aktif) {
                $c = $pdoKamp->prepare("SELECT COUNT(*) FROM kamp_konaklama WHERE yatak_id=? AND cikis IS NULL"); $c->execute([$yid]);
                if ($c->fetchColumn()) throw new RuntimeException('Yatak dolu — önce kalanı taşıyın.');
            }
            $pdoKamp->prepare("UPDATE kamp_yataklar SET aktif=?, aciklama=? WHERE id=? AND oda_id=?")
                ->execute([$aktif, trim((string)($_POST['aciklama'] ?? '')) ?: null, $yid, $id]);
            kamp_audit('kamp_yataklar', $yid, 'UPDATE', null, ['aktif' => $aktif, 'aciklama' => $_POST['aciklama'] ?? '']);
            flash('success', $aktif ? 'Yatak kullanıma açıldı.' : 'Yatak kullanım dışı yapıldı.');
        } elseif ($islem === 'yatak_ekle') {
            if (!kamp_oda_yatakli($oda['tur'])) throw new RuntimeException('Bu oda yatak odası değil — önce türünü değiştirin (Kamp Yapısı).');
            $n = kamp_yatak_ekle($pdoKamp, $id, max(1, min(20, (int)($_POST['adet'] ?? 1))), $_POST['yatak_tur'] ?? 'tek');
            kamp_audit('kamp_yataklar', $id, 'INSERT', null, ['oda' => $oda['ad'], 'eklenen' => $n]);
            flash('success', "$n yatak eklendi.");
        } elseif ($islem === 'oda_not') {
            $pdoKamp->prepare("UPDATE kamp_odalar SET aciklama=? WHERE id=?")->execute([trim((string)($_POST['aciklama'] ?? '')) ?: null, $id]);
            flash('success', 'Oda notu kaydedildi.');
        }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect('oda.php?id=' . $id);
}

$agac = kamp_yapi($pdoKamp, (int)$oda['bina_id'], true);
$odaY = $agac[(int)$oda['bina_id']][(int)$oda['kat']][$id] ?? ($oda + ['yataklar' => []]);
$say  = kamp_oda_sayac($odaY);
$demirbas = kamp_oda_demirbas($pdoKamp, $id);
$perler = kamp_periyotlar($pdoKamp);
$perDur = [];   // [periyot_id][yatak_id | 'oda'] => satır
foreach (kamp_periyot_durum($pdoKamp, null, null, $id) as $r) $perDur[(int)$r['periyot_id']][$r['kapsam'] === 'oda' ? 'oda' : (int)$r['yatak_id']] = $r;
$perSon = $pdoKamp->prepare("SELECT k.tarih, k.yapan, pr.ad, y.no FROM kamp_periyot_kayit k JOIN kamp_periyot pr ON pr.id=k.periyot_id
                               LEFT JOIN kamp_yataklar y ON y.id=k.yatak_id WHERE k.oda_id=? ORDER BY k.tarih DESC, k.id DESC LIMIT 15");
$perSon->execute([$id]); $perSon = $perSon->fetchAll();
$gecmis = $pdoKamp->prepare("SELECT k.*, p.ad, p.soyad, f.ad AS firma_ad, y.no AS yatak_no
                               FROM kamp_konaklama k JOIN kamp_kisiler p ON p.id=k.kisi_id
                               LEFT JOIN kamp_firmalar f ON f.id=COALESCE(k.firma_id,p.firma_id)
                               LEFT JOIN kamp_yataklar y ON y.id=k.yatak_id
                              WHERE k.oda_id=? OR y.oda_id=?
                              ORDER BY k.giris DESC, k.id DESC LIMIT 200");
$gecmis->execute([$id, $id]);
$gecmis = $gecmis->fetchAll();

// Aynı konteynerdeki diğer odalar (ortak alan demirbaşı orada)
$komsu = [];
if ($oda['unite']) {
    $s = $pdoKamp->prepare("SELECT id, ad, tur FROM kamp_odalar WHERE bina_id=? AND unite=? AND id<>? ORDER BY sira");
    $s->execute([$oda['bina_id'], $oda['unite'], $id]); $komsu = $s->fetchAll();
}

$pageTitle = $oda['ad'] . ' — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi <?= KAMP_ODA_TUR[$oda['tur']][1] ?? 'bi-door-closed' ?> text-primary me-2"></i><?= h($oda['ad']) ?></h4>
  <span class="text-muted"><?= h($oda['bina_ad']) ?> › <?= h(kamp_kat_ad((int)$oda['kat'])) ?><?= $oda['unite'] ? ' › Konteyner ' . h($oda['unite']) : '' ?></span>
  <span class="badge bg-secondary"><?= h(KAMP_ODA_TUR[$oda['tur']][0] ?? $oda['tur']) ?></span>
  <?php if (!(int)$oda['aktif']): ?><span class="badge bg-dark">pasif</span><?php endif; ?>
  <div class="ms-auto d-flex gap-2">
    <a href="yerlesim.php?bina=<?= (int)$oda['bina_id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-grid-3x3-gap me-1"></i>Krokide gör</a>
    <?php if ($duz): ?><a href="yapi.php?bina=<?= (int)$oda['bina_id'] ?>#oda-<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Ad / tür</a><?php endif; ?>
  </div>
</div>
<?php if ($komsu): ?><div class="small mb-3"><span class="text-muted">Aynı konteyner:</span>
  <?php foreach ($komsu as $k): ?><a href="oda.php?id=<?= (int)$k['id'] ?>" class="badge bg-light text-dark border text-decoration-none me-1"><i class="bi <?= KAMP_ODA_TUR[$k['tur']][1] ?? '' ?> me-1"></i><?= h($k['ad']) ?></a><?php endforeach; ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex align-items-center"><strong>Yataklar</strong>
        <span class="badge bg-primary ms-2"><?= $say['dolu'] ?> / <?= $say['yatak'] ?> dolu</span>
        <?php if ($say['disi']): ?><span class="badge bg-secondary ms-1"><?= $say['disi'] ?> kullanım dışı</span><?php endif; ?></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.86rem">
        <thead class="table-light"><tr><th>Yatak</th><th>Kalan</th><th>Giriş</th><th></th></tr></thead><tbody>
        <?php if (!$odaY['yataklar']): ?><tr><td colspan="4" class="text-muted text-center py-3"><?= kamp_oda_yatakli($oda['tur']) ? 'Yatak tanımlı değil.' : 'Bu oda yatak odası değil.' ?></td></tr><?php endif; ?>
        <?php foreach ($odaY['yataklar'] as $y): ?>
          <tr class="<?= (int)$y['aktif'] ? '' : 'table-secondary' ?>">
            <td class="fw-semibold"><?= h($y['no']) ?><div class="small text-muted fw-normal"><?= h(KAMP_YATAK_TUR[$y['tur']] ?? '') ?></div></td>
            <td><?php if ($y['konaklama_id']): ?><a href="kisi_detay.php?id=<?= (int)$y['kisi_id'] ?>"><?= h(trim($y['kisi_ad'] . ' ' . $y['kisi_soyad'])) ?></a>
                  <div class="small text-muted"><?= h($y['firma_ad'] ?: '—') ?></div>
                <?php elseif (!(int)$y['aktif']): ?><span class="text-muted">kullanım dışı<?= $y['aciklama'] ? ' — ' . h($y['aciklama']) : '' ?></span>
                <?php else: ?><span class="text-success">boş</span><?php endif; ?></td>
            <td class="small"><?= $y['konaklama_id'] ? h(kamp_tarih_yaz($y['giris'])) . '<div class="text-muted">' . kamp_sure($y['giris'], null) . ' gece</div>' : '' ?></td>
            <td class="text-end text-nowrap">
              <?php if ($y['konaklama_id']): ?><a href="tutanak.php?konaklama=<?= (int)$y['konaklama_id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-0" title="Oda teslim tutanağı"><i class="bi bi-printer"></i></a><?php endif; ?>
              <?php if ($duz && !$y['konaklama_id']): ?>
              <form method="post" class="d-inline" onsubmit="<?= (int)$y['aktif'] ? "var a=prompt('Neden kullanım dışı? (kırık, kaldırıldı…)','');if(a===null)return false;this.aciklama.value=a;" : '' ?>">
                <input type="hidden" name="islem" value="yatak_durum"><input type="hidden" name="yatak_id" value="<?= (int)$y['id'] ?>">
                <input type="hidden" name="aktif" value="<?= (int)$y['aktif'] ? 0 : 1 ?>"><input type="hidden" name="aciklama" value="">
                <button class="btn btn-outline-<?= (int)$y['aktif'] ? 'warning' : 'success' ?> btn-sm py-0" title="<?= (int)$y['aktif'] ? 'Kullanım dışı yap' : 'Kullanıma aç' ?>"><i class="bi bi-<?= (int)$y['aktif'] ? 'slash-circle' : 'check-circle' ?>"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php if ($duz && kamp_oda_yatakli($oda['tur'])): ?>
      <div class="card-footer bg-white"><form method="post" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="islem" value="yatak_ekle"><span class="small text-muted">Yatak ekle:</span>
        <input type="number" name="adet" value="1" min="1" max="20" class="form-control form-control-sm" style="width:70px">
        <select name="yatak_tur" class="form-select form-select-sm" style="width:140px"><option value="tek">Tek kişilik</option><option value="ranza2">Ranza alt/üst</option><option value="ranza3">Üç katlı ranza</option></select>
        <button class="btn btn-outline-primary btn-sm">Ekle</button></form></div>
      <?php endif; ?>
    </div>

    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white"><strong>Oda notu</strong></div>
      <div class="card-body">
        <?php if ($duz): ?><form method="post"><input type="hidden" name="islem" value="oda_not">
          <textarea name="aciklama" rows="2" class="form-control form-control-sm mb-2" maxlength="250"><?= h($oda['aciklama']) ?></textarea>
          <button class="btn btn-outline-secondary btn-sm">Kaydet</button></form>
        <?php else: ?><?= $oda['aciklama'] ? h($oda['aciklama']) : '<span class="text-muted">—</span>' ?><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex align-items-center"><strong>Demirbaşlar</strong><span class="badge bg-secondary ms-2"><?= count($demirbas) ?></span>
        <?php if ($yaz): ?><a href="demirbas.php?oda=<?= $id ?>#ekle" class="btn btn-outline-primary btn-sm ms-auto"><i class="bi bi-plus-lg"></i> Ekle / düzenle</a><?php endif; ?></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.86rem">
        <thead class="table-light"><tr><th>Demirbaş</th><th class="text-end">Adet</th><th>Durum</th><th>Not</th></tr></thead><tbody>
        <?php if (!$demirbas): ?><tr><td colspan="4" class="text-muted text-center py-3">Demirbaş kaydı yok.</td></tr><?php endif; ?>
        <?php foreach ($demirbas as $d): ?>
          <tr><td><?= h($d['ad']) ?><?= $d['marka'] ? '<div class="small text-muted">' . h($d['marka']) . ($d['seri_no'] ? ' · ' . h($d['seri_no']) : '') . '</div>' : '' ?></td>
            <td class="text-end"><?= (int)$d['adet'] ?></td><td><?= kamp_dbRozet($d['durum']) ?></td><td class="small text-muted"><?= h($d['aciklama']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>

    <?php if ($perDur): $yp = array_filter($perler, fn($x) => $x['kapsam'] !== 'oda' && isset($perDur[$x['id']])); ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex align-items-center"><strong><i class="bi bi-arrow-repeat me-1"></i>Periyodik değişim</strong>
        <a href="periyodik.php" class="ms-auto small">tümü →</a></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.82rem">
        <?php if ($yp): ?>
        <thead class="table-light"><tr><th>Yatak</th><?php foreach ($yp as $pr): ?><th><?= h($pr['ad']) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($odaY['yataklar'] as $y): if (!(int)$y['aktif']) continue; ?>
          <tr><td class="text-nowrap"><strong><?= h($y['no']) ?></strong></td>
            <?php foreach ($yp as $pr): $r = $perDur[$pr['id']][(int)$y['id']] ?? null; ?>
            <td title="<?= h($r && $r['son'] ? 'son değişim ' . kamp_tarih_yaz($r['son']) : 'kayıt yok') ?>"><?= $r ? kamp_per_rozet($r['durum'], $r['kalan']) : '—' ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?></tbody>
        <?php endif; ?>
        <?php foreach ($perler as $pr): if ($pr['kapsam'] !== 'oda' || !isset($perDur[$pr['id']]['oda'])) continue; $r = $perDur[$pr['id']]['oda']; ?>
          <tr><td colspan="<?= count($yp) + 1 ?>"><?= h($pr['ad']) ?> <span class="float-end"><?= kamp_per_rozet($r['durum'], $r['kalan']) ?></span></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php if ($yaz): ?>
      <form method="post" action="periyodik.php" class="card-body border-top py-2 row g-2 align-items-end">
        <input type="hidden" name="islem" value="kaydet"><input type="hidden" name="geri" value="oda.php?id=<?= $id ?>">
        <div class="col-12 small fw-semibold">Değişim yapıldı olarak kaydet</div>
        <div class="col-md-5"><select name="periyot_id" class="form-select form-select-sm js-oper"><?php foreach ($perler as $pr): if (!isset($perDur[$pr['id']])) continue; ?>
          <option value="<?= (int)$pr['id'] ?>" data-kapsam="<?= h($pr['kapsam']) ?>"><?= h($pr['ad']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><input type="date" name="tarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
        <div class="col-md-4"><input name="yapan" class="form-control form-control-sm" placeholder="yapan" maxlength="120"></div>
        <div class="col-12 js-oyatak"><?php foreach ($odaY['yataklar'] as $y): if (!(int)$y['aktif']) continue; ?>
          <label class="form-check form-check-inline small mb-0"><input class="form-check-input" type="checkbox" name="hedef[]" value="<?= (int)$y['id'] ?>" checked><?= h($y['no']) ?></label><?php endforeach; ?></div>
        <input type="hidden" name="hedef[]" value="<?= $id ?>" class="js-ooda" disabled>
        <div class="col-12"><button class="btn btn-success btn-sm"><i class="bi bi-check2 me-1"></i>Kaydet</button></div>
      </form>
      <?php endif; ?>
      <?php if ($perSon): ?><div class="card-body border-top py-2 small"><div class="text-muted mb-1">Son değişimler</div>
        <?php foreach ($perSon as $k): ?><div><?= h(kamp_tarih_yaz($k['tarih'])) ?> · <?= h($k['ad']) ?><?= $k['no'] ? ' · ' . h($k['no']) : '' ?><?= $k['yapan'] ? ' <span class="text-muted">(' . h($k['yapan']) . ')</span>' : '' ?></div><?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white"><strong>Bu odada kalanlar (geçmiş)</strong></div>
      <div class="table-responsive" style="max-height:420px;overflow:auto"><table class="table table-sm mb-0" style="font-size:.84rem">
        <thead class="table-light"><tr><th>Kişi</th><th>Yatak</th><th>Giriş</th><th>Çıkış</th><th class="text-end">Gece</th></tr></thead><tbody>
        <?php if (!$gecmis): ?><tr><td colspan="5" class="text-muted text-center py-3">Kayıt yok.</td></tr><?php endif; ?>
        <?php foreach ($gecmis as $g): ?>
          <tr class="<?= $g['cikis'] ? '' : 'table-primary' ?>"><td><a href="kisi_detay.php?id=<?= (int)$g['kisi_id'] ?>"><?= h(trim($g['ad'] . ' ' . $g['soyad'])) ?></a><div class="small text-muted"><?= h($g['firma_ad'] ?: '') ?></div></td>
            <td><?= h($g['yatak_no'] ?: '—') ?></td><td><?= h(kamp_tarih_yaz($g['giris'])) ?></td>
            <td><?= $g['cikis'] ? h(kamp_tarih_yaz($g['cikis'])) . ($g['cikis_sebep'] ? '<div class="small text-muted">' . h($g['cikis_sebep']) . '</div>' : '') : '<span class="badge bg-primary">kalıyor</span>' ?></td>
            <td class="text-end"><?= kamp_sure($g['giris'], $g['cikis']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('.js-oper').forEach(function(s){
  function f(){ var oda = s.options[s.selectedIndex] && s.options[s.selectedIndex].dataset.kapsam === 'oda', fm = s.form;
    fm.querySelector('.js-oyatak').classList.toggle('d-none', oda);
    fm.querySelectorAll('.js-oyatak input').forEach(function(c){ c.disabled = oda; });
    fm.querySelector('.js-ooda').disabled = !oda; }
  s.addEventListener('change', f); f();
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
