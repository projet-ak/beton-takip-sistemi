<?php
/**
 * kamp/icmal.php — AYLIK İCMAL / AY SONU DÖKÜMÜ
 *  Firma bazında: kampta kalan kişi · KİŞİ-GECE (konaklama günü) · ay içinde giren · çıkan · ay sonu kalan
 *  + kişi bazında satırlar (kim, nerede, hangi tarihler, bu ay kaç gece).
 *  Gece sayımı: giriş günü gecesi sayılır, çıkış günü sayılmaz; içinde bulunulan ay bugüne kadar sayılır.
 *  Yatak değişikliği (taşıma) yeni giriş / çıkış sayılmaz. Excel / PDF / Yazdır: ERN_RAPOR.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$ay = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['ay'] ?? '')) ? $_GET['ay'] : date('Y-m');
$firmaF = (int)($_GET['firma'] ?? 0);
$ic = kamp_icmal($pdoKamp, $ay);
$kisi = $firmaF ? array_values(array_filter($ic['kisi'], fn($r) => (int)$r['fid'] === $firmaF)) : $ic['kisi'];
$onceki = date('Y-m', strtotime($ay . '-01 -1 month'));
$sonraki = date('Y-m', strtotime($ay . '-01 +1 month'));
$TR_AY = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
$ayAd = $TR_AY[(int)substr($ay, 5, 2)] . ' ' . substr($ay, 0, 4);
$devam = $ic['son_gun'] < $ic['bit'];

$pageTitle = 'Aylık İcmal — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-table text-primary me-2"></i>Aylık İcmal</h4>
  <div class="btn-group ms-2">
    <a href="icmal.php?ay=<?= $onceki ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></a>
    <span class="btn btn-primary btn-sm fw-bold" style="min-width:140px"><?= h(mb_strtoupper($ayAd, 'UTF-8')) ?></span>
    <a href="icmal.php?ay=<?= $sonraki ?>" class="btn btn-outline-secondary btn-sm <?= $sonraki > date('Y-m') ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
  </div>
  <input type="month" class="form-control form-control-sm" style="width:160px" value="<?= h($ay) ?>" max="<?= date('Y-m') ?>" onchange="location='icmal.php?ay='+this.value">
  <div class="ms-auto d-flex gap-2">
    <button class="btn btn-outline-success btn-sm" onclick="icExcel()"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
    <button class="btn btn-outline-danger btn-sm" onclick="icPdf('pdf')"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
    <button class="btn btn-outline-secondary btn-sm" onclick="icPdf('print')"><i class="bi bi-printer me-1"></i>Yazdır</button>
  </div>
</div>
<?php if ($devam): ?><div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1"></i>Ay devam ediyor — geceler bugüne (<?= h(kamp_tarih_yaz($ic['son_gun'])) ?>) kadar sayıldı; "ay sonu kalan" şu an kampta olanlardır.</div><?php endif; ?>

<div class="row g-2 mb-3">
  <?php foreach ([['Kampta kalan kişi', $ic['toplam']['kisi'], 'primary', 'bu ay en az bir gün'], ['Kişi-gece', $ic['toplam']['gece'], 'dark', 'toplam konaklama günü'],
                  ['Giren', $ic['toplam']['giren'], 'success', 'ay içinde kampa giriş'], ['Çıkan', $ic['toplam']['cikan'], 'danger', 'ay içinde kamptan çıkış'],
                  ['Ay sonu kalan', $ic['toplam']['kalan'], 'secondary', $devam ? 'şu an kampta' : kamp_tarih_yaz($ic['bit']) . ' itibarıyla']] as [$ad, $n, $renk, $alt]): ?>
  <div class="col-6 col-md"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
    <div class="small text-muted"><?= h($ad) ?></div><div class="fs-4 fw-bold text-<?= $renk ?>"><?= number_format((int)$n, 0, ',', '.') ?></div><div class="small text-muted"><?= h($alt) ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white"><strong>Firma bazında</strong> <span class="text-muted small">— satıra tıklayınca kişiler süzülür</span></div>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:.88rem">
    <thead class="table-light"><tr><th>Firma</th><th class="text-end">Kişi</th><th class="text-end">Kişi-gece</th><th class="text-end">Giren</th><th class="text-end">Çıkan</th><th class="text-end">Ay sonu kalan</th></tr></thead><tbody>
    <?php if (!$ic['firma']): ?><tr><td colspan="6" class="text-center text-muted py-4">Bu ay konaklama yok.</td></tr><?php endif; ?>
    <?php foreach ($ic['firma'] as $f): ?>
      <tr class="<?= $firmaF === (int)$f['firma_id'] ? 'table-primary' : '' ?>" style="cursor:pointer" onclick="location='icmal.php?ay=<?= h($ay) ?>&firma=<?= $firmaF === (int)$f['firma_id'] ? 0 : (int)$f['firma_id'] ?>#kisiler'">
        <td class="fw-semibold"><?= h($f['firma']) ?></td><td class="text-end"><?= $f['kisi'] ?></td><td class="text-end fw-bold"><?= number_format($f['gece'], 0, ',', '.') ?></td>
        <td class="text-end text-success"><?= $f['giren'] ?: '' ?></td><td class="text-end text-danger"><?= $f['cikan'] ?: '' ?></td><td class="text-end"><?= $f['kalan'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($ic['firma']): ?><tfoot class="table-light fw-bold"><tr><td>TOPLAM</td><td class="text-end"><?= $ic['toplam']['kisi'] ?></td><td class="text-end"><?= number_format($ic['toplam']['gece'], 0, ',', '.') ?></td>
      <td class="text-end"><?= $ic['toplam']['giren'] ?></td><td class="text-end"><?= $ic['toplam']['cikan'] ?></td><td class="text-end"><?= $ic['toplam']['kalan'] ?></td></tr></tfoot><?php endif; ?>
  </table></div>
</div>

<div class="card border-0 shadow-sm" id="kisiler">
  <div class="card-header bg-white d-flex"><strong>Ay sonu dökümü — kişi bazında</strong>
    <?php if ($firmaF): ?><a href="icmal.php?ay=<?= h($ay) ?>" class="ms-auto small">süzgeci kaldır ✕</a><?php endif; ?></div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.85rem">
    <thead class="table-light"><tr><th>#</th><th>Ad Soyad</th><th>Firma</th><th>Yer</th><th>Giriş</th><th>Çıkış</th><th class="text-end">Bu ay gece</th></tr></thead><tbody>
    <?php if (!$kisi): ?><tr><td colspan="7" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
    <?php foreach ($kisi as $i => $r): ?>
      <tr><td class="text-muted small"><?= $i + 1 ?></td>
        <td><a href="kisi_detay.php?id=<?= (int)$r['kisi_id'] ?>" class="text-decoration-none"><?= h(trim($r['ad'] . ' ' . $r['soyad'])) ?></a>
          <?= $r['giren'] ? '<span class="badge bg-success-subtle text-success-emphasis">giriş</span>' : '' ?><?= $r['cikan'] ? '<span class="badge bg-danger-subtle text-danger-emphasis">çıkış</span>' : '' ?></td>
        <td class="small"><?= h($r['firma_ad'] ?: '—') ?></td>
        <td class="small"><?= h(($r['bina_ad'] ?? '') . ' · ' . ($r['oda_ad'] ?? '') . ' › ' . ($r['yatak_no'] ?? '')) ?></td>
        <td class="small"><?= h(kamp_tarih_yaz($r['giris'])) ?></td>
        <td class="small"><?= $r['cikis'] ? h(kamp_tarih_yaz($r['cikis'])) . ($r['cikis_sebep'] ? '<div class="text-muted">' . h($r['cikis_sebep']) . '</div>' : '') : '<span class="badge bg-success">kalıyor</span>' ?></td>
        <td class="text-end fw-semibold"><?= $r['gece'] ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<script>window.ERN_ROOT = '../';</script>
<script src="../assets/js/ern_rapor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ern_rapor.js') ?>"></script>
<script>
var IC = <?= json_encode([
    'ay' => $ayAd, 'aykod' => $ay, 'devam' => $devam, 'son' => kamp_tarih_yaz($ic['son_gun']), 'top' => $ic['toplam'],
    'firma' => array_map(fn($f) => [$f['firma'], $f['kisi'], $f['gece'], $f['giren'], $f['cikan'], $f['kalan']], $ic['firma']),
    'kisi' => array_map(fn($r) => [trim($r['ad'] . ' ' . $r['soyad']), (string)$r['tc_no'], (string)($r['firma_ad'] ?: ''), (string)$r['gorev'],
                                   trim(($r['bina_ad'] ?? '') . ' · ' . ($r['oda_ad'] ?? '') . ' › ' . ($r['yatak_no'] ?? '')),
                                   kamp_tarih_yaz($r['giris']), $r['cikis'] ? kamp_tarih_yaz($r['cikis']) : 'kalıyor', (string)($r['cikis_sebep'] ?? ''), $r['gece']], $kisi),
], JSON_UNESCAPED_UNICODE) ?>;
function icBaslik(){ return 'KAMP AYLIK İCMAL — ' + IC.ay.toLocaleUpperCase('tr-TR') + (IC.devam ? ' (' + IC.son + ' itibarıyla)' : ''); }
function icPdf(mode){
  var t = IC.top;
  var html = '<p><strong>Kişi:</strong> ' + t.kisi + ' · <strong>Kişi-gece:</strong> ' + t.gece + ' · <strong>Giren:</strong> ' + t.giren
           + ' · <strong>Çıkan:</strong> ' + t.cikan + ' · <strong>Ay sonu kalan:</strong> ' + t.kalan + '</p><h3>Firma bazında</h3>'
           + ERN_RAPOR.tbl(['Firma','Kişi','Kişi-gece','Giren','Çıkan','Ay sonu kalan'], IC.firma.concat([['TOPLAM', t.kisi, t.gece, t.giren, t.cikan, t.kalan]]))
           + '<h3>Ay sonu dökümü</h3>' + ERN_RAPOR.tbl(['Ad Soyad','Firma','Yer','Giriş','Çıkış','Gece'], IC.kisi.map(function(r){ return [r[0], r[2], r[4], r[5], r[6], r[8]]; }));
  ERN_RAPOR.popup({title: icBaslik(), body: html, mode: mode, filename: 'ERN_Kamp_Icmal_' + IC.aykod});
}
async function icExcel(){
  var wb = await ERN_RAPOR.wb(), ws;
  ws = wb.addWorksheet('Firma İcmali');
  ERN_RAPOR.title(wb, ws, icBaslik(), 6);
  ERN_RAPOR.hdr(ws.addRow(['Firma','Kişi','Kişi-gece','Giren','Çıkan','Ay sonu kalan']));
  IC.firma.forEach(function(r){ ws.addRow(r); });
  var t = IC.top, tr = ws.addRow(['TOPLAM', t.kisi, t.gece, t.giren, t.cikan, t.kalan]); tr.font = {bold:true};
  ws.columns.forEach(function(c, i){ c.width = i ? 14 : 40; });
  ws = wb.addWorksheet('Ay Sonu Dökümü');
  ERN_RAPOR.title(wb, ws, 'AY SONU DÖKÜMÜ — ' + IC.ay.toLocaleUpperCase('tr-TR'), 9);
  ERN_RAPOR.hdr(ws.addRow(['Ad Soyad','TC No','Firma','Görev','Yer','Giriş','Çıkış','Çıkış Sebebi','Bu ay gece']));
  IC.kisi.forEach(function(r){ ws.addRow(r); });
  ws.columns.forEach(function(c, i){ c.width = [28,14,30,18,34,12,12,24,12][i] || 14; });
  await ERN_RAPOR.save(wb, 'ERN_Kamp_Icmal_' + IC.aykod + '.xlsx');
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
