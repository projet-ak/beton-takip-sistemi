<?php
/**
 * pts/puantaj.php — Günlük puantaj: kişi × gün → ilk giriş, son çıkış, çalışılan süre
 *
 * Toplama `pts_gunluk()` içinde PHP'de yapılır (gerekçesi orada: FILTER/EXTRACT gibi
 * Postgres'e özel SQL MySQL ile SQLite arasında taşınabilir değil).
 *
 * ⚠ Bu ekran **resmî puantaj/SGK kaydı değildir** — kart okutma kayıtlarından türetilmiş
 * bir özettir. Kart okutmayı unutan personelde satır eksik/çıkışsız görünür; bu yüzden
 * eşleşmeyen hareketler "eksik" rozetiyle açıkça işaretlenir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu','saha_sefi']);
require_once __DIR__ . '/../includes/db_pts.php';
require_once __DIR__ . '/_ortak.php';

it_semasi_kur($pdoPts);
pts_semasi_kur($pdoPts);
$pageTitle = 'Puantaj — Personel Takip';

$bas = $_GET['bas'] ?? date('Y-m-01');
$bit = $_GET['bit'] ?? date('Y-m-d');
if ($bit < $bas) [$bas, $bit] = [$bit, $bas];

$f = ['personel_id' => (int)($_GET['personel_id'] ?? 0) ?: null,
      'birim'       => trim((string)($_GET['birim'] ?? '')) ?: null,
      'lokasyon_id' => (int)($_GET['lokasyon_id'] ?? 0) ?: null];
$p = pts_gunluk($pdoPts, $bas, $bit, $f);

// Kişi bazlı toplam (satırlar gün bazlı; üstte kişi özeti daha okunur)
$kisi = [];
foreach ($p['satirlar'] as $s) {
    $k = $s['personel_id'];
    if (!isset($kisi[$k])) $kisi[$k] = ['ad_soyad'=>$s['ad_soyad'],'sicil_no'=>$s['sicil_no'],
                                        'birim'=>$s['birim'],'gun'=>0,'dakika'=>0,'eksik'=>0];
    $kisi[$k]['gun']++; $kisi[$k]['dakika'] += $s['dakika']; $kisi[$k]['eksik'] += $s['eksik'];
}
uasort($kisi, fn($a, $b) => $b['dakika'] <=> $a['dakika']);

$birimler = $pdoPts->query("SELECT DISTINCT birim FROM it_personel WHERE birim<>'' AND birim IS NOT NULL ORDER BY birim")
                  ->fetchAll(PDO::FETCH_COLUMN);

require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-calendar3 text-primary me-2"></i>Puantaj</h4>
  <span class="text-muted small"><?= h(format_date($bas)) ?> → <?= h(format_date($bit)) ?></span>
  <div class="ms-auto d-flex gap-2">
    <button class="btn btn-outline-success btn-sm" onclick="ptsExcel()"><i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</button>
    <button class="btn btn-outline-danger btn-sm" onclick="ptsPdf('pdf')"><i class="bi bi-file-earmark-pdf me-1"></i>PDF İndir</button>
    <button class="btn btn-outline-secondary btn-sm" onclick="ptsPdf('print')"><i class="bi bi-printer me-1"></i>Yazdır</button>
  </div>
</div>

<div class="alert alert-warning py-2 small">
  <i class="bi bi-exclamation-triangle me-1"></i>
  <strong>Resmî puantaj / SGK kaydı değildir.</strong> Bu tablo kart okutma kayıtlarından türetilir;
  kart okutmayı unutan personelde gün <span class="badge bg-warning text-dark">eksik</span> rozetiyle işaretlenir.
</div>

<form method="get" class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label small mb-0">Başlangıç</label>
        <input type="date" name="bas" class="form-control form-control-sm" value="<?= h($bas) ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-0">Bitiş</label>
        <input type="date" name="bit" class="form-control form-control-sm" value="<?= h($bit) ?>"></div>
      <div class="col-md-3"><label class="form-label small mb-0">Birim</label>
        <select name="birim" class="form-select form-select-sm"><option value="">Tümü</option>
          <?php foreach ($birimler as $b): ?>
            <option value="<?= h($b) ?>" <?= ($f['birim']===$b)?'selected':'' ?>><?= h($b) ?></option>
          <?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label small mb-0">Lokasyon</label>
        <select name="lokasyon_id" class="form-select form-select-sm"><?= it_lokasyon_options($pdoPts, $f['lokasyon_id']) ?></select></div>
      <div class="col-md-2 d-flex gap-1">
        <button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i></button>
        <a href="puantaj.php" class="btn btn-outline-secondary btn-sm">Temizle</a></div>
    </div>
  </div>
</form>

<?php $f0 = fn($n) => number_format((float)$n, 0, ',', '.'); ?>
<div class="row g-2 mb-3">
  <?php foreach ([
    ['Personel',       count($kisi),                 'bi-people',      'primary'],
    ['Gün kaydı',      count($p['satirlar']),        'bi-calendar-check','secondary'],
    ['Toplam çalışma', pts_sure($p['toplam_dk']),    'bi-clock-history','success'],
    ['Eşleşmeyen',     $p['eksik'],                  'bi-exclamation-triangle', $p['eksik'] ? 'warning' : 'light'],
  ] as [$ad, $deger, $ikon, $renk]): ?>
  <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
    <div class="small text-muted"><i class="bi <?= $ikon ?> me-1"></i><?= $ad ?></div>
    <div class="fs-5 fw-bold text-<?= $renk === 'light' ? 'muted' : $renk ?>"><?= is_int($deger) ? $f0($deger) : h($deger) ?></div>
  </div></div></div>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white"><strong>Kişi Özeti</strong>
    <span class="text-muted small ms-2">dönem toplamı</span></div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" id="tblKisi" style="font-size:.86rem">
      <thead class="table-light"><tr>
        <th>Personel</th><th>Sicil</th><th>Birim</th><th class="text-end">Gün</th>
        <th class="text-end">Çalışma</th><th class="text-end">Eşleşmeyen</th></tr></thead>
      <tbody>
      <?php if (!$kisi): ?><tr><td colspan="6" class="text-center text-muted py-4">Bu aralıkta kart okutma kaydı yok.</td></tr><?php endif; ?>
      <?php foreach ($kisi as $pid => $k): ?>
        <tr>
          <td><a href="hareketler.php?personel_id=<?= (int)$pid ?>&bas=<?= h($bas) ?>&bit=<?= h($bit) ?>"
                 class="fw-semibold text-decoration-none"><?= h($k['ad_soyad']) ?></a></td>
          <td class="font-monospace small"><?= h($k['sicil_no'] ?: '—') ?></td>
          <td class="small"><?= h($k['birim'] ?: '—') ?></td>
          <td class="text-end"><?= (int)$k['gun'] ?></td>
          <td class="text-end fw-semibold"><?= h(pts_sure((int)$k['dakika'])) ?></td>
          <td class="text-end"><?= $k['eksik'] ? '<span class="badge bg-warning text-dark">' . (int)$k['eksik'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white"><strong>Gün Gün</strong></div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" id="tblGun" style="font-size:.86rem">
      <thead class="table-light"><tr>
        <th>Tarih</th><th>Personel</th><th>Sicil</th><th>Birim</th>
        <th>İlk giriş</th><th>Son çıkış</th><th class="text-end">Çalışma</th><th class="text-end">Hareket</th><th></th></tr></thead>
      <tbody>
      <?php if (!$p['satirlar']): ?><tr><td colspan="9" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
      <?php foreach ($p['satirlar'] as $s): ?>
        <tr class="<?= $s['eksik'] ? 'table-warning' : '' ?>">
          <td class="text-nowrap"><?= h(format_date($s['gun'])) ?></td>
          <td><?= h($s['ad_soyad']) ?></td>
          <td class="font-monospace small"><?= h($s['sicil_no'] ?: '—') ?></td>
          <td class="small"><?= h($s['birim'] ?: '—') ?></td>
          <td class="font-monospace"><?= $s['ilk_giris'] ? h(substr($s['ilk_giris'], 11, 5)) : '—' ?></td>
          <td class="font-monospace"><?= $s['son_cikis'] ? h(substr($s['son_cikis'], 11, 5)) : '—' ?></td>
          <td class="text-end fw-semibold"><?= h(pts_sure((int)$s['dakika'])) ?></td>
          <td class="text-end"><?= (int)$s['hareket'] ?></td>
          <td class="small">
            <?php if ($s['eksik']): ?>
              <span class="badge bg-warning text-dark" title="Giriş var ama eşleşen çıkış yok — kart okutulmamış olabilir">
                <i class="bi bi-exclamation-triangle me-1"></i>eksik</span>
            <?php endif; ?>
            <a href="hareketler.php?personel_id=<?= (int)$s['personel_id'] ?>&bas=<?= h($s['gun']) ?>&bit=<?= h($s['gun']) ?>"
               class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-list-ul"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>window.ERN_ROOT = '../';</script>
<script src="../assets/js/ern_rapor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ern_rapor.js') ?>"></script>
<script>
var PTS = {
  bas: <?= json_encode($bas) ?>, bit: <?= json_encode($bit) ?>,
  kisi: <?= json_encode(array_values($kisi), JSON_UNESCAPED_UNICODE) ?>,
  gun:  <?= json_encode(array_map(fn($s) => [
            'gun'=>$s['gun'],'ad'=>$s['ad_soyad'],'sicil'=>$s['sicil_no'],'birim'=>$s['birim'],
            'ilk'=>$s['ilk_giris'] ? substr($s['ilk_giris'],11,5) : '',
            'son'=>$s['son_cikis'] ? substr($s['son_cikis'],11,5) : '',
            'dk'=>$s['dakika'],'hareket'=>$s['hareket'],'eksik'=>$s['eksik'],
          ], $p['satirlar']), JSON_UNESCAPED_UNICODE) ?>
};
function ptsSure(dk){ if(!dk) return '0'; var s=Math.floor(dk/60), k=dk%60; return (s?s+'s ':'')+k+'dk'; }

function ptsPdf(mode){
  var html = '<p><strong>Dönem:</strong> ' + PTS.bas + ' → ' + PTS.bit + '</p>' + ERN_RAPOR.tbl(
      ['Tarih','Personel','Sicil','Birim','İlk giriş','Son çıkış','Çalışma','Eksik'],
      PTS.gun.map(function(r){ return [r.gun, r.ad, r.sicil||'', r.birim||'', r.ilk||'—', r.son||'—',
                                       ptsSure(r.dk), r.eksik ? String(r.eksik) : '']; }));
  ERN_RAPOR.popup({title:'PERSONEL PUANTAJI', body:html, mode:mode, filename:'ERN_Puantaj'});
}

async function ptsExcel(){
  var wb = await ERN_RAPOR.wb(), ws;
  ws = wb.addWorksheet('Kişi Özeti');
  ERN_RAPOR.title(wb, ws, 'PUANTAJ — KİŞİ ÖZETİ (' + PTS.bas + ' → ' + PTS.bit + ')', 6);
  ERN_RAPOR.hdr(ws.addRow(['Personel','Sicil','Birim','Gün','Çalışma (dk)','Eşleşmeyen']));
  PTS.kisi.forEach(function(k){ ws.addRow([k.ad_soyad, k.sicil_no||'', k.birim||'', k.gun, k.dakika, k.eksik]); });

  ws = wb.addWorksheet('Gün Gün');
  ERN_RAPOR.title(wb, ws, 'PUANTAJ — GÜN GÜN', 9);
  ERN_RAPOR.hdr(ws.addRow(['Tarih','Personel','Sicil','Birim','İlk giriş','Son çıkış','Çalışma (dk)','Hareket','Eşleşmeyen']));
  PTS.gun.forEach(function(r){ ws.addRow([r.gun, r.ad, r.sicil||'', r.birim||'', r.ilk||'', r.son||'', r.dk, r.hareket, r.eksik]); });

  await ERN_RAPOR.save(wb, 'ERN_Puantaj_' + PTS.bas + '_' + PTS.bit + '.xlsx');
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
