<?php
/**
 * whatsapp/evraklar.php — Gelen Evrak Takibi
 *
 * Grupta paylaşılan belge/görseller (irsaliye fotoğrafı, tutanak, fatura…)
 * türüne göre gruplanır; görseliyle birlikte ve ONAYI VEREN kişiyle listelenir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_ortak.php';

if (!can_view_reports() && !can_edit()) { flash('error', 'Bu sayfa için yetkiniz yok.'); redirect('../index.php'); }

$pageTitle = 'Evraklar — Saha Takip';
saha_semasi_kur($pdo);

$uid       = current_user_id();
$kullanici = current_user();
$adSoyad   = trim((string)($kullanici['full_name'] ?? $kullanici['username'] ?? ''));

$EVRAK_AD = ['kantar'=>'Kantar Fişi','irsaliye'=>'İrsaliye','tutanak'=>'Tutanak','fatura'=>'Fatura','puantaj'=>'Puantaj',
             'ruhsat'=>'Ruhsat','foto'=>'Fotoğraf','diger'=>'Diğer'];
$EVRAK_IK = ['kantar'=>'bi-speedometer2','irsaliye'=>'bi-receipt','tutanak'=>'bi-file-earmark-check','fatura'=>'bi-cash-coin',
             'puantaj'=>'bi-calendar-week','ruhsat'=>'bi-card-heading','foto'=>'bi-image','diger'=>'bi-file-earmark'];

// ── Tek evrak onayı / reddi ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit() && isset($_POST['ev_onay']) && ctype_digit($_POST['ev_onay'])) {
    $pdo->prepare("UPDATE saha_evrak
                   SET durum='onaylandi', onay_user=?, onay_at=NOW(),
                       onaylayan = COALESCE(NULLIF(onaylayan,''), ?)
                   WHERE id=?")
        ->execute([$uid, $adSoyad, (int)$_POST['ev_onay']]);
    flash('success', 'Evrak onaylandı.');
    redirect('evraklar.php' . (isset($_POST['q']) ? '?' . preg_replace('/[^\w=&%.\-]/', '', (string)$_POST['q']) : ''));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit() && isset($_POST['ev_red']) && ctype_digit($_POST['ev_red'])) {
    $pdo->prepare("UPDATE saha_evrak SET durum='reddedildi', onay_user=?, onay_at=NOW() WHERE id=?")
        ->execute([$uid, (int)$_POST['ev_red']]);
    flash('success', 'Evrak reddedildi.');
    redirect('evraklar.php' . (isset($_POST['q']) ? '?' . preg_replace('/[^\w=&%.\-]/', '', (string)$_POST['q']) : ''));
}

// ── Filtreler ─────────────────────────────────────────────────────────────────
$bas   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bas'] ?? '') ? $_GET['bas'] : date('Y-m-d', strtotime('-30 days'));
$bit   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bit'] ?? '') ? $_GET['bit'] : date('Y-m-d');
$turF  = isset($_GET['tur']) && isset($EVRAK_AD[$_GET['tur']]) ? $_GET['tur'] : '';
$durF  = in_array($_GET['durum'] ?? '', ['bekliyor','onaylandi','reddedildi'], true) ? $_GET['durum'] : '';
$araF  = trim((string)($_GET['ara'] ?? ''));

$tarihIfade = "COALESCE(e.tarih, DATE(m.created_at))";
$w = ["$tarihIfade BETWEEN ? AND ?"];
$p = [$bas, $bit];
if ($turF) { $w[] = "e.tur = ?";   $p[] = $turF; }
if ($durF) { $w[] = "e.durum = ?"; $p[] = $durF; }
if ($araF) { $w[] = "(e.baslik LIKE ? OR e.belge_no LIKE ? OR e.firma LIKE ? OR e.arac_plaka LIKE ? OR e.onaylayan LIKE ?)";
             array_push($p, "%$araF%", "%$araF%", "%$araF%", "%$araF%", "%$araF%"); }
$W = 'WHERE ' . implode(' AND ', $w);

$sql = "SELECT e.*, $tarihIfade AS gun, m.ham_metin, m.medya_url AS mesaj_medya, m.medya_json AS mesaj_medya_json, u.full_name AS onay_ad
        FROM saha_evrak e
        LEFT JOIN mesaj_kuyrugu m ON m.id = e.mesaj_id
        LEFT JOIN users u ON u.id = e.onay_user
        $W ORDER BY gun DESC, e.id DESC LIMIT 300";
$st = $pdo->prepare($sql); $st->execute($p);
$evraklar = $st->fetchAll(PDO::FETCH_ASSOC);

// Türe göre grupla
$gruplar = [];
foreach ($evraklar as $e) { $gruplar[$e['tur']][] = $e; }

// Özet sayılar (filtreden bağımsız değil — aynı aralık)
$ozet = ['bekliyor'=>0,'onaylandi'=>0,'reddedildi'=>0,'gorselli'=>0];
foreach ($evraklar as $e) {
    $ozet[$e['durum']] = ($ozet[$e['durum']] ?? 0) + 1;
    if ($e['dosya_url'] || $e['mesaj_medya']) $ozet['gorselli']++;
}
$qs = http_build_query(['bas'=>$bas,'bit'=>$bit,'tur'=>$turF,'durum'=>$durF,'ara'=>$araF]);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-folder2-open text-primary me-2"></i>Evraklar</h4>
</div>

<form class="card mb-3"><div class="card-body py-2">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
      <label class="form-label small mb-0">Başlangıç</label>
      <input type="date" name="bas" value="<?= h($bas) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-0">Bitiş</label>
      <input type="date" name="bit" value="<?= h($bit) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-0">Tür</label>
      <select name="tur" class="form-select form-select-sm">
        <option value="">Tümü</option>
        <?php foreach ($EVRAK_AD as $k=>$v): ?>
          <option value="<?= h($k) ?>" <?= $turF===$k?'selected':'' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-0">Durum</label>
      <select name="durum" class="form-select form-select-sm">
        <option value="">Tümü</option>
        <option value="bekliyor"   <?= $durF==='bekliyor'?'selected':'' ?>>Bekleyen</option>
        <option value="onaylandi"  <?= $durF==='onaylandi'?'selected':'' ?>>Onaylanan</option>
        <option value="reddedildi" <?= $durF==='reddedildi'?'selected':'' ?>>Reddedilen</option>
      </select>
    </div>
    <div class="col-8 col-md-3">
      <label class="form-label small mb-0">Ara (belge no / firma / plaka / onaylayan)</label>
      <input name="ara" value="<?= h($araF) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-4 col-md-1 d-grid">
      <button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
    </div>
  </div>
</div></form>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="card border-warning h-100"><div class="card-body py-3">
    <div class="fw-bold fs-4"><?= (int)$ozet['bekliyor'] ?></div>
    <div class="text-muted small">Onay bekleyen</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card border-success h-100"><div class="card-body py-3">
    <div class="fw-bold fs-4"><?= (int)$ozet['onaylandi'] ?></div>
    <div class="text-muted small">Onaylanan</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-4"><?= count($evraklar) ?></div>
    <div class="text-muted small">Toplam evrak</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">
    <div class="fw-bold fs-4 text-info"><?= (int)$ozet['gorselli'] ?></div>
    <div class="text-muted small">Görselli</div></div></div></div>
</div>

<?php if (!$evraklar): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
    Bu filtrede evrak yok. Mesajlar <a href="mesajlar.php" class="alert-link">Gelen Mesajlar</a>
    ekranında çözümlendikçe buraya düşer.</div>
<?php endif; ?>

<?php foreach ($gruplar as $tur => $liste): ?>
<div class="card mb-4">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi <?= h($EVRAK_IK[$tur] ?? 'bi-file-earmark') ?> me-1"></i><?= h($EVRAK_AD[$tur] ?? $tur) ?></span>
    <span class="badge bg-secondary"><?= count($liste) ?></span>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <?php foreach ($liste as $e):
        // Galeri: evrağın kendi dosyası + mesajın TÜM görselleri (mükerrersiz)
        $galeri = array_values(array_unique(array_filter(array_merge(
            [$e['dosya_url']],
            mesaj_gorseller(['medya_json' => $e['mesaj_medya_json'] ?? null, 'medya_url' => $e['mesaj_medya'] ?? null])
        ))));
        $srcOf  = fn(string $u) => (strpos($u, 'http') === 0) ? $u : '../' . ltrim($u, '/');
        $rozet  = ['bekliyor'=>'bg-warning text-dark','onaylandi'=>'bg-success','reddedildi'=>'bg-secondary'][$e['durum']] ?? 'bg-light text-dark';
        $g      = $e['guven'] !== null ? (float)$e['guven'] : null;
      ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card h-100">
          <?php if ($galeri): ?>
            <a href="<?= h($srcOf($galeri[0])) ?>" target="_blank" rel="noopener">
              <img src="<?= h($srcOf($galeri[0])) ?>" class="card-img-top" alt="Evrak görseli"
                   style="height:150px;object-fit:cover"
                   onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'p-3 text-center text-muted small',innerHTML:'📎 Ek dosya — açmak için tıklayın'}))">
            </a>
            <?php if (count($galeri) > 1): ?>
              <div class="d-flex gap-1 px-2 pt-2 flex-wrap">
                <?php foreach (array_slice($galeri, 1, 5) as $gi => $gu): ?>
                  <a href="<?= h($srcOf($gu)) ?>" target="_blank" rel="noopener">
                    <img src="<?= h($srcOf($gu)) ?>" alt="Ek <?= $gi+2 ?>" class="rounded border"
                         style="width:44px;height:44px;object-fit:cover"
                         onerror="this.style.display='none'">
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-start gap-1 mb-1">
              <span class="badge <?= $rozet ?>"><?= h(ucfirst($e['durum'])) ?></span>
              <?php if ($g !== null): ?>
                <small class="<?= $g < .6 ? 'text-danger fw-semibold' : 'text-success' ?>"><?= number_format($g*100,0) ?>%</small>
              <?php endif; ?>
            </div>
            <?php if ($e['baslik']): ?><div class="fw-semibold small"><?= h((string)$e['baslik']) ?></div><?php endif; ?>
            <div class="small text-muted">
              <?php if ($e['belge_no']): ?><div>No: <span class="font-monospace"><?= h((string)$e['belge_no']) ?></span></div><?php endif; ?>
              <?php if ($e['firma']): ?><div><?= h((string)$e['firma']) ?></div><?php endif; ?>
              <?php if ($e['arac_plaka']): ?><div class="font-monospace"><?= h((string)$e['arac_plaka']) ?></div><?php endif; ?>
              <?php if (!empty($e['net_kg'])): ?><div><strong>Net:</strong> <?= number_format((float)$e['net_kg'],0,',','.') ?> kg</div><?php endif; ?>
              <div><?= $e['gun'] ? date('d.m.Y', strtotime($e['gun'])) : '—' ?>
                   <?php if ($e['gonderen']): ?>· <?= h((string)$e['gonderen']) ?><?php endif; ?></div>
            </div>
            <?php if ($e['onaylayan']): ?>
              <div class="small mt-1"><i class="bi bi-person-check text-success me-1"></i>
                <strong>Onay:</strong> <?= h((string)$e['onaylayan']) ?>
                <?php if ($e['onay_at']): ?><span class="text-muted">· <?= date('d.m.Y H:i', strtotime($e['onay_at'])) ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($e['onay_ad'] && $e['onay_ad'] !== $e['onaylayan']): ?>
              <div class="small text-muted"><i class="bi bi-check2-square me-1"></i>Sistemde işleyen: <?= h((string)$e['onay_ad']) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($e['durum']==='bekliyor' && can_edit()): ?>
          <div class="card-footer py-2 d-flex gap-2">
            <form method="post" class="flex-grow-1 d-grid">
              <input type="hidden" name="q" value="<?= h($qs) ?>">
              <button name="ev_onay" value="<?= (int)$e['id'] ?>" class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Onayla</button>
            </form>
            <form method="post">
              <input type="hidden" name="q" value="<?= h($qs) ?>">
              <button name="ev_red" value="<?= (int)$e['id'] ?>" class="btn btn-outline-danger btn-sm btn-confirm"
                      data-msg="Evrak reddedilecek. Emin misiniz?"><i class="bi bi-x-lg"></i></button>
            </form>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<div class="alert alert-secondary small border-0">
  <i class="bi bi-robot me-1"></i>
  Evrak bilgileri mesajlardan <strong>AI ile</strong> çıkarılmıştır. "Onay" alanı mesajda geçen kişiyi,
  yoksa sistemde onaylayan kullanıcıyı gösterir. Resmî belge kaydı yerine geçmez.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
