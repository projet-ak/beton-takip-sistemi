<?php
/**
 * pts/personel.php — PTS'nin KENDİ personel listesi
 *
 * ⭐ Modül bağımsızlığı kuralı: personel kaydı bu modülündür (`pts_personel`).
 * IT Envanter kurulu olmasa da PTS eksiksiz çalışır.
 *
 * IT Envanter'de zaten bir liste varsa "IT Envanter'den aktar" düğmesi onu TEK YÖNLÜ
 * kopyalar (eşleşme sicil no ile). Kopyalama anlıktır; sonrasında iki liste bağımsız
 * yaşar — burada yapılan düzeltme IT'ye, IT'deki düzeltme buraya geçmez.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_pts.php';
require_once __DIR__ . '/_ortak.php';

pts_semasi_kur($pdoPts);
$pageTitle = 'Personel — Personel Takip';
$yazabilir = yetki_var('duzenle') || yetki_var('giris');

// IT köprüsü yalnız IT modülü kuruluysa görünür — yokluğu PTS'yi etkilemez.
$itVar = defined('IT_DB_NAME') && IT_DB_NAME !== '';

// ── İşlemler ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$yazabilir) { flash('error', 'Bu işlem için yetkiniz yok.'); redirect('personel.php'); }
    $islem = $_POST['islem'] ?? '';
    try {
        if ($islem === 'it_aktar') {
            if (!$itVar) throw new RuntimeException('IT Envanter modülü kurulu değil (IT_DB_NAME tanımsız).');
            require_once __DIR__ . '/../includes/db_it.php';
            $r = pts_it_aktar($pdoPts, $pdoIt);
            audit_log($pdoPts, 'pts_personel', 0, 'INSERT', null,
                      ['it_aktarim' => $r['yeni'] . ' yeni / ' . $r['guncellenen'] . ' güncellenen'], current_user_id());
            $m = $r['yeni'] . ' yeni kişi eklendi, ' . $r['guncellenen'] . ' kayıt tamamlandı, '
               . $r['degismeyen'] . ' kayıt zaten günceldi.';
            if ($r['atlanan']) $m .= ' Atlanan: ' . count($r['atlanan']) . ' (' . implode('; ', array_slice($r['atlanan'], 0, 3))
                                   . (count($r['atlanan']) > 3 ? '…' : '') . ')';
            flash($r['yeni'] || $r['guncellenen'] ? 'success' : 'info', $m);
        } elseif ($islem === 'sil') {
            $id = (int)($_POST['id'] ?? 0);
            // Kart ya da geçiş kaydı olan kişi SİLİNMEZ — puantaj geçmişi kopmasın.
            $st = $pdoPts->prepare("SELECT (SELECT COUNT(*) FROM pts_hareketler WHERE personel_id=?) h,
                                           (SELECT COUNT(*) FROM pts_kartlar    WHERE personel_id=?) k");
            $st->execute([$id, $id]);
            $c = $st->fetch() ?: ['h'=>0,'k'=>0];
            if ((int)$c['h'] > 0 || (int)$c['k'] > 0) {
                throw new RuntimeException('Bu personelin ' . (int)$c['h'] . ' geçiş kaydı ve ' . (int)$c['k']
                    . ' kartı var — silinmez. Ayrıldıysa "işten çıkış" tarihi girin.');
            }
            $p = pts_personel_bul($pdoPts, $id);
            $pdoPts->prepare("DELETE FROM pts_personel WHERE id=?")->execute([$id]);
            audit_log($pdoPts, 'pts_personel', $id, 'DELETE', $p, null, current_user_id());
            flash('success', 'Personel silindi.');
        }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect('personel.php');
}

// ── Liste ───────────────────────────────────────────────────────────────────
$q     = trim((string)($_GET['q'] ?? ''));
$durum = $_GET['durum'] ?? 'calisan';                 // calisan | ayrilan | hepsi

// ⚠ Süzme SQL LIKE ile DEĞİL pts_personel_suz() ile: Türkçe 'İ' LIKE'ta 'i' ile
// eşleşmiyor ve ad+soyad birlikte yazılınca tek alanda geçmediği için sonuç çıkmıyordu.
$hepsi = $pdoPts->query("SELECT p.*, k.marker_id,
                                (SELECT COUNT(*) FROM pts_hareketler h WHERE h.personel_id=p.id) AS gecis
                           FROM pts_personel p
                           LEFT JOIN pts_kartlar k ON k.personel_id = p.id AND k.iptal IS NULL
                          ORDER BY p.ad, p.soyad")->fetchAll();
if ($q !== '') $hepsi = pts_personel_suz($hepsi, $q);

$sayac = ['calisan' => 0, 'ayrilan' => 0];
foreach ($hepsi as $r) $sayac[empty($r['isten_cikis']) ? 'calisan' : 'ayrilan']++;

$liste = array_values(array_filter($hepsi, function ($r) use ($durum) {
    if ($durum === 'calisan') return empty($r['isten_cikis']);
    if ($durum === 'ayrilan') return !empty($r['isten_cikis']);
    return true;
}));

require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-people text-primary me-2"></i>Personel</h4>
  <span class="badge bg-success"><?= $sayac['calisan'] ?> çalışan</span>
  <?php if ($sayac['ayrilan']): ?><span class="badge bg-secondary"><?= $sayac['ayrilan'] ?> ayrılan</span><?php endif; ?>
  <div class="ms-auto d-flex gap-2">
    <?php if ($yazabilir && $itVar): ?>
    <form method="post" onsubmit="return confirm('IT Envanter\'deki personel listesi buraya KOPYALANACAK.\n\nEşleşme sicil no ile yapılır; burada elle girdiğiniz bilgiler EZİLMEZ (yalnız boş alanlar dolar).\nKopyalama tek yönlüdür — sonrasında iki liste bağımsız yaşar.\n\nDevam?')">
      <input type="hidden" name="islem" value="it_aktar">
      <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-in-down me-1"></i>IT Envanter'den Aktar</button>
    </form>
    <?php endif; ?>
    <a href="kartlar.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-vcard me-1"></i>Kartlar</a>
    <?php if ($yazabilir): ?>
    <a href="personel_form.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Yeni Personel</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$hepsi && $q === ''): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-1"></i>
  <strong>Personel listesi boş.</strong> Bu modül kendi personel listesini tutar.
  <?php if ($itVar): ?>IT Envanter'de kayıtlı personel varsa yukarıdaki
  <strong>“IT Envanter'den Aktar”</strong> düğmesiyle tek seferde kopyalayabilirsiniz.<?php endif; ?>
</div>
<?php endif; ?>

<form method="get" class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
    <div><label class="form-label small mb-0">Ara</label>
      <input name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="ad soyad, sicil, birim…"></div>
    <div><label class="form-label small mb-0">Durum</label>
      <select name="durum" class="form-select form-select-sm">
        <option value="calisan" <?= $durum==='calisan'?'selected':'' ?>>Çalışanlar</option>
        <option value="ayrilan" <?= $durum==='ayrilan'?'selected':'' ?>>Ayrılanlar</option>
        <option value="hepsi"   <?= $durum==='hepsi'  ?'selected':'' ?>>Hepsi</option>
      </select></div>
    <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filtrele</button>
    <a href="personel.php" class="btn btn-outline-secondary btn-sm">Temizle</a>
  </div>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light"><tr>
        <th>Sicil</th><th>Ad Soyad</th><th>Unvan</th><th>Birim</th><th>Lokasyon</th>
        <th>ArUco</th><th class="text-end">Geçiş</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$liste): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Kayıt yok.</td></tr>
      <?php endif; ?>
      <?php foreach ($liste as $r): $ayrildi = !empty($r['isten_cikis']); ?>
        <tr class="<?= $ayrildi ? 'text-muted' : '' ?>">
          <td class="font-monospace small"><?= h($r['sicil_no'] ?: '—') ?></td>
          <td><a href="personel_form.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none"><?= h(pts_personel_ad($r)) ?></a>
              <?= $ayrildi ? '<span class="badge bg-secondary ms-1">ayrıldı</span>' : '' ?></td>
          <td class="small"><?= h($r['unvan'] ?: '—') ?></td>
          <td class="small"><?= h($r['birim'] ?: '—') ?></td>
          <td class="small"><?= h($r['lokasyon'] ?: '—') ?></td>
          <td><?= $r['marker_id'] !== null
                ? '<span class="badge bg-primary font-monospace">' . (int)$r['marker_id'] . '</span>'
                : '<span class="badge bg-warning text-dark">kart yok</span>' ?></td>
          <td class="text-end"><?= (int)$r['gecis'] ?></td>
          <td class="text-end text-nowrap">
            <a href="personel_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
            <?php if ($yazabilir && !(int)$r['gecis'] && $r['marker_id'] === null): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('<?= h(pts_personel_ad($r)) ?> silinecek. Devam?')">
              <input type="hidden" name="islem" value="sil">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
