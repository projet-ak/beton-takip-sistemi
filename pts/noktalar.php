<?php
/**
 * pts/noktalar.php — Geçiş noktaları (kapı / turnike / kiosk tableti)
 *
 * ⚠ Kiosk cihazı OTURUM AÇMAZ. Her cihaz bir geçiş noktasına bağlanır ve
 * isteklerinde `X-Checkpoint-Key` başlığını gönderir. Anahtar SUNUCUDA üretilir,
 * buradan kopyalanıp cihaza bir kez girilir. Böylece hangi geçişin hangi kapıda
 * olduğu da kayda düşer.
 *
 * Yön: 'giris' / 'cikis' sabit yönlü kapılar içindir (giriş ve çıkış için ayrı
 * kamera kurulduğunda her cihaz kendi yönünü bildirir). 'otomatik' seçilirse yön
 * personelin son hareketinin tersi olur.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_pts.php';
require_once __DIR__ . '/_ortak.php';

it_semasi_kur($pdoPts);
pts_semasi_kur($pdoPts);
$pageTitle = 'Geçiş Noktaları — Personel Takip';
$yazabilir = yetki_var('duzenle') || yetki_var('giris');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$yazabilir) { flash('error', 'Bu işlem için yetkiniz yok.'); redirect('noktalar.php'); }
    $islem = $_POST['islem'] ?? '';
    $id    = (int)($_POST['id'] ?? 0);
    try {
        if ($islem === 'kaydet') {
            $kod = strtoupper(trim((string)($_POST['kod'] ?? '')));
            $ad  = trim((string)($_POST['ad'] ?? ''));
            $yon = $_POST['yon'] ?? 'otomatik';
            $lok = (int)($_POST['lokasyon_id'] ?? 0) ?: null;
            if ($kod === '' || $ad === '') throw new RuntimeException('Kod ve ad zorunludur.');
            if (!isset(PTS_NOKTA_YON[$yon])) $yon = 'otomatik';

            // Mükerrer kod engeli (tanım sayfası deseni: UPPER karşılaştırma)
            $chk = $pdoPts->prepare("SELECT id FROM pts_noktalar WHERE UPPER(kod)=? AND id<>?");
            $chk->execute([$kod, $id]);
            if ($chk->fetch()) throw new RuntimeException('"' . $kod . '" kodlu bir geçiş noktası zaten var.');

            if ($id > 0) {
                $pdoPts->prepare("UPDATE pts_noktalar SET kod=?, ad=?, yon=?, lokasyon_id=?, aktif=? WHERE id=?")
                       ->execute([$kod, $ad, $yon, $lok, isset($_POST['aktif']) ? 1 : 0, $id]);
                audit_log($pdoPts, 'pts_noktalar', $id, 'UPDATE', null, ['kod'=>$kod,'ad'=>$ad,'yon'=>$yon], current_user_id());
                flash('success', '"' . $ad . '" güncellendi.');
            } else {
                $pdoPts->prepare("INSERT INTO pts_noktalar (kod, ad, cihaz_anahtari, yon, lokasyon_id, aktif, created_at)
                                  VALUES (?,?,?,?,?,1,?)")
                       ->execute([$kod, $ad, pts_anahtar_uret(), $yon, $lok, date('Y-m-d H:i:s')]);
                $yeni = (int)$pdoPts->lastInsertId();
                audit_log($pdoPts, 'pts_noktalar', $yeni, 'INSERT', null, ['kod'=>$kod,'ad'=>$ad,'yon'=>$yon], current_user_id());
                flash('success', '"' . $ad . '" eklendi. Cihaz anahtarını kiosk tabletine girin.');
            }
        } elseif ($islem === 'anahtar_yenile') {
            // Anahtar sızdıysa / cihaz değiştiyse yenilenir — ESKİ CİHAZ ANINDA DEVRE DIŞI KALIR.
            $pdoPts->prepare("UPDATE pts_noktalar SET cihaz_anahtari=? WHERE id=?")
                   ->execute([pts_anahtar_uret(), $id]);
            audit_log($pdoPts, 'pts_noktalar', $id, 'UPDATE', null, ['anahtar_yenilendi' => 1], current_user_id());
            flash('warning', 'Cihaz anahtarı yenilendi — o noktadaki kiosk cihazına yeni anahtarı girmeden kart okutamaz.');
        } elseif ($islem === 'sil') {
            // Geçmiş hareketler noktaya bağlı olabilir; kayıt SİLİNMEZ, pasife alınır.
            $st = $pdoPts->prepare("SELECT COUNT(*) FROM pts_hareketler WHERE nokta_id=?");
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                $pdoPts->prepare("UPDATE pts_noktalar SET aktif=0 WHERE id=?")->execute([$id]);
                flash('info', 'Bu noktada geçiş kaydı olduğu için silinmedi, PASİFE alındı (geçmiş korunur).');
            } else {
                $pdoPts->prepare("DELETE FROM pts_noktalar WHERE id=?")->execute([$id]);
                flash('success', 'Geçiş noktası silindi.');
            }
            audit_log($pdoPts, 'pts_noktalar', $id, 'DELETE', null, null, current_user_id());
        }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect('noktalar.php');
}

$liste = $pdoPts->query("SELECT n.*, (SELECT COUNT(*) FROM pts_hareketler h WHERE h.nokta_id=n.id) AS gecis
                           FROM pts_noktalar n ORDER BY n.aktif DESC, n.kod")->fetchAll();
$kioskUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://'
          . ($_SERVER['HTTP_HOST'] ?? 'localhost')
          . rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/\\') . '/kiosk.php';

require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-door-open text-primary me-2"></i>Geçiş Noktaları</h4>
  <span class="badge bg-secondary"><?= count($liste) ?></span>
  <?php if ($yazabilir): ?>
  <button class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#noktaModal" data-id="0">
    <i class="bi bi-plus-lg me-1"></i>Yeni Geçiş Noktası</button>
  <?php endif; ?>
</div>

<div class="alert alert-info py-2 small">
  <i class="bi bi-info-circle me-1"></i>
  Kiosk cihazı oturum açmaz; kendini <strong>cihaz anahtarıyla</strong> tanıtır. Anahtarı kopyalayıp tabletteki
  <code>kiosk.php</code> ekranına bir kez girin — sunucuya doğrulatılır, yanlış yapıştırılan anahtar ilk kart
  okutulana kadar fark edilmesin diye.
  <br>⚠ Tarayıcılar kameraya yalnız <strong>HTTPS</strong> ya da <code>localhost</code> üzerinden izin verir.
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light"><tr>
        <th>Kod</th><th>Ad</th><th>Yön</th><th>Lokasyon</th><th class="text-end">Geçiş</th>
        <th>Son görülme</th><th>Cihaz anahtarı</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$liste): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Henüz geçiş noktası yok.
          <?php if ($yazabilir): ?>Yukarıdan ekleyin.<?php endif; ?></td></tr>
      <?php endif; ?>
      <?php foreach ($liste as $n): ?>
        <tr class="<?= $n['aktif'] ? '' : 'text-muted' ?>">
          <td class="font-monospace fw-semibold"><?= h($n['kod']) ?>
              <?= $n['aktif'] ? '' : '<span class="badge bg-secondary ms-1">pasif</span>' ?></td>
          <td><?= h($n['ad']) ?></td>
          <td><span class="badge bg-<?= $n['yon']==='otomatik' ? 'light text-dark border' : ($n['yon']==='giris'?'success':'secondary') ?>">
              <?= h(PTS_NOKTA_YON[$n['yon']] ?? $n['yon']) ?></span></td>
          <td class="small"><?= h($n['lokasyon_id'] ? it_lokasyon_etiket($pdoPts, (int)$n['lokasyon_id']) : '—') ?></td>
          <td class="text-end"><?= (int)$n['gecis'] ?></td>
          <td class="small text-muted"><?= $n['son_gorulme'] ? h(date('d.m.Y H:i', strtotime($n['son_gorulme']))) : '—' ?></td>
          <td>
            <?php if ($yazabilir): ?>
            <div class="input-group input-group-sm" style="max-width:260px">
              <input class="form-control font-monospace" style="font-size:.72rem" readonly
                     value="<?= h($n['cihaz_anahtari']) ?>" onclick="this.select()">
              <button class="btn btn-outline-secondary pts-kopya" type="button" title="Kopyala"><i class="bi bi-clipboard"></i></button>
            </div>
            <?php else: ?><span class="text-muted small">gizli</span><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <?php if ($yazabilir): ?>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#noktaModal"
                    data-id="<?= (int)$n['id'] ?>" data-kod="<?= h($n['kod']) ?>" data-ad="<?= h($n['ad']) ?>"
                    data-yon="<?= h($n['yon']) ?>" data-lok="<?= (int)$n['lokasyon_id'] ?>" data-aktif="<?= (int)$n['aktif'] ?>">
              <i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline" onsubmit="return confirm('Cihaz anahtarı YENİLENECEK.\n\nBu noktadaki kiosk cihazı yeni anahtar girilene kadar kart okutamaz. Devam?')">
              <input type="hidden" name="islem" value="anahtar_yenile"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
              <button class="btn btn-sm btn-outline-warning" title="Cihaz anahtarını yenile"><i class="bi bi-arrow-repeat"></i></button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('<?= h($n['ad']) ?> silinecek.\n\nGeçiş kaydı varsa silinmez, pasife alınır. Devam?')">
              <input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Sil"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white small text-muted">
    Kiosk adresi: <code><?= h($kioskUrl) ?></code>
  </div>
</div>

<div class="modal fade" id="noktaModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content"><form method="post">
    <div class="modal-header"><h5 class="modal-title">Geçiş Noktası</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="islem" value="kaydet">
      <input type="hidden" name="id" id="nmId" value="0">
      <div class="mb-2"><label class="form-label small">Kod</label>
        <input name="kod" id="nmKod" class="form-control form-control-sm font-monospace" required
               placeholder="ANA-GIRIS" style="text-transform:uppercase"></div>
      <div class="mb-2"><label class="form-label small">Ad</label>
        <input name="ad" id="nmAd" class="form-control form-control-sm" required placeholder="Ana Giriş Turnikesi"></div>
      <div class="mb-2"><label class="form-label small">Yön</label>
        <select name="yon" id="nmYon" class="form-select form-select-sm">
          <?php foreach (PTS_NOKTA_YON as $k => $v): ?>
            <option value="<?= h($k) ?>"><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Tek kamerayla hem giriş hem çıkış alınıyorsa <strong>Otomatik</strong> seçin.</div></div>
      <div class="mb-2"><label class="form-label small">Lokasyon</label>
        <select name="lokasyon_id" id="nmLok" class="form-select form-select-sm">
          <?= it_lokasyon_options($pdoPts, null) ?>
        </select></div>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="aktif" id="nmAktif" checked>
        <label class="form-check-label small" for="nmAktif">Aktif</label></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
      <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
    </div>
  </form></div></div>
</div>

<script>
document.querySelectorAll('.pts-kopya').forEach(function(b){
  b.addEventListener('click', function(){
    var inp = b.parentElement.querySelector('input');
    inp.select();
    // navigator.clipboard yalnız güvenli bağlamda var; yoksa execCommand'a düşeriz.
    var bitti = function(){ b.innerHTML = '<i class="bi bi-check-lg"></i>';
                            setTimeout(function(){ b.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(inp.value).then(bitti, function(){});
    else { try { document.execCommand('copy'); bitti(); } catch(e){} }
  });
});
var nm = document.getElementById('noktaModal');
if (nm) nm.addEventListener('show.bs.modal', function(ev){
  var b = ev.relatedTarget; if (!b) return;
  var yeni = !b.dataset.id || b.dataset.id === '0';
  document.getElementById('nmId').value    = yeni ? '0' : b.dataset.id;
  document.getElementById('nmKod').value   = yeni ? '' : (b.dataset.kod || '');
  document.getElementById('nmAd').value    = yeni ? '' : (b.dataset.ad || '');
  document.getElementById('nmYon').value   = yeni ? 'otomatik' : (b.dataset.yon || 'otomatik');
  document.getElementById('nmLok').value   = yeni ? '' : (b.dataset.lok !== '0' ? b.dataset.lok : '');
  document.getElementById('nmAktif').checked = yeni ? true : b.dataset.aktif === '1';
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
