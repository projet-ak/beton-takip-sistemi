<?php
/**
 * mesajlar.php — Gelen Mesajlar / Onay Kuyruğu
 *
 * WhatsApp (veya elle yapıştırma) ile gelen serbest metinler AI ile ayrıştırılır,
 * kullanıcı kontrol edip düzelttikten SONRA irsaliyeye dönüşür.
 * Otomatik kayıt yoktur — her satır insan onayından geçer.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mesaj.php';

if (!can_edit()) { flash('error', 'Bu sayfa için yetkiniz yok.'); redirect('index.php'); }

$pageTitle = 'Gelen Mesajlar — Şantiye Takip Sistemi';
mesaj_semasi_kur($pdo);

$uid = current_user_id();

// ── Elle mesaj ekle ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mesaj_ekle'])) {
    $metin = trim((string)($_POST['ham_metin'] ?? ''));
    if ($metin === '') {
        flash('error', 'Mesaj metni boş olamaz.');
    } else {
        $r = mesaj_kuyruga_ekle($pdo, [
            'kaynak'   => 'manuel',
            'gonderen' => trim((string)($_POST['gonderen'] ?? '')) ?: 'Elle giriş',
            'metin'    => $metin,
        ]);
        if (!$r['ok'])                 flash('error', $r['msg'] ?? 'Eklenemedi.');
        elseif (!empty($r['mukerrer'])) flash('error', 'Bu mesaj zaten kuyrukta.');
        else {
            $ai = mesaj_isle($pdo, $r['id']);
            flash($ai['ok'] ? 'success' : 'error',
                  $ai['ok'] ? 'Mesaj eklendi ve çözümlendi.' : ('Mesaj eklendi ama AI çözümleyemedi: ' . ($ai['msg'] ?? '')));
        }
    }
    redirect('mesajlar.php');
}

// ── Tek mesajı AI ile (yeniden) çözümle ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_coz']) && ctype_digit($_POST['ai_coz'])) {
    $r = mesaj_isle($pdo, (int)$_POST['ai_coz']);
    flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Çözümlendi.' : ('AI hatası: ' . ($r['msg'] ?? '')));
    redirect('mesajlar.php');
}

// ── Bekleyen tüm mesajları çözümle ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toplu_coz'])) {
    $bekleyen = $pdo->query("SELECT id FROM mesaj_kuyrugu WHERE durum='bekliyor' AND ai_durum='bekliyor' ORDER BY id LIMIT 20")
                    ->fetchAll(PDO::FETCH_COLUMN);
    $ok = 0; $hata = 0;
    foreach ($bekleyen as $mid) { $r = mesaj_isle($pdo, (int)$mid); $r['ok'] ? $ok++ : $hata++; }
    flash($hata ? 'error' : 'success', "$ok mesaj çözümlendi" . ($hata ? ", $hata hata." : "."));
    redirect('mesajlar.php');
}

// ── Reddet ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reddet']) && ctype_digit($_POST['reddet'])) {
    $pdo->prepare("UPDATE mesaj_kuyrugu SET durum='reddedildi', islenen_at=NOW(), islenen_by=?, not_metni=? WHERE id=?")
        ->execute([$uid, mb_substr(trim((string)($_POST['not_metni'] ?? '')), 0, 300) ?: null, (int)$_POST['reddet']]);
    flash('success', 'Mesaj reddedildi.');
    redirect('mesajlar.php');
}

// ── Onayla → irsaliye oluştur ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['onayla']) && ctype_digit($_POST['onayla'])) {
    $mid = (int)$_POST['onayla'];
    $tip          = ($_POST['tip'] ?? 'alis') === 'iade' ? 'iade' : 'alis';
    $irsaliyeNo   = trim((string)($_POST['irsaliye_no'] ?? '')) ?: null;
    $tarih        = trim((string)($_POST['tarih'] ?? ''));
    $plaka        = strtoupper(trim((string)($_POST['arac_plaka'] ?? ''))) ?: null;
    $miktar       = (float)str_replace(',', '.', (string)($_POST['miktar'] ?? '0'));
    $tedarikciId  = ctype_digit((string)($_POST['tedarikci_id']    ?? '')) ? (int)$_POST['tedarikci_id']    : null;
    $betonId      = ctype_digit((string)($_POST['beton_sinifi_id'] ?? '')) ? (int)$_POST['beton_sinifi_id'] : null;
    $projeId      = ctype_digit((string)($_POST['proje_id']        ?? '')) ? (int)$_POST['proje_id']        : null;
    $kivamId      = ctype_digit((string)($_POST['kivam_sinifi_id'] ?? '')) ? (int)$_POST['kivam_sinifi_id'] : null;
    $aciklama     = trim((string)($_POST['aciklama'] ?? '')) ?: null;

    if ($tarih === '' || !$tedarikciId) {
        flash('error', 'Tarih ve Tedarikçi zorunlu.');
        redirect('mesajlar.php');
    }
    // Mükerrer irsaliye no kontrolü
    if ($irsaliyeNo) {
        $d = $pdo->prepare("SELECT id FROM irsaliyeler WHERE UPPER(TRIM(irsaliye_no)) = UPPER(TRIM(?)) LIMIT 1");
        $d->execute([$irsaliyeNo]);
        if ($varId = $d->fetchColumn()) {
            flash('error', "Bu irsaliye no zaten kayıtlı (#$varId) — kayıt oluşturulmadı.");
            redirect('mesajlar.php');
        }
    }
    try {
        $pdo->prepare("INSERT INTO irsaliyeler
            (tip, irsaliye_no, arac_plaka, tedarikci_id, tarih, miktar, birim,
             beton_sinifi_id, proje_id, kivam_sinifi_id, aciklama, created_by)
            VALUES (?,?,?,?,?,?,'M3',?,?,?,?,?)")
            ->execute([$tip, $irsaliyeNo, $plaka, $tedarikciId, $tarih, $miktar ?: 0,
                       $betonId, $projeId, $kivamId, $aciklama, $uid]);
        $yeniId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE mesaj_kuyrugu SET durum='onaylandi', irsaliye_id=?, islenen_at=NOW(), islenen_by=? WHERE id=?")
            ->execute([$yeniId, $uid, $mid]);

        if (function_exists('audit_log')) {
            audit_log($pdo, 'irsaliyeler', $yeniId, 'INSERT', null, ['kaynak' => 'mesaj_kuyrugu#' . $mid]);
        }
        flash('success', 'İrsaliye oluşturuldu (#' . $yeniId . ').');
    } catch (PDOException $e) {
        flash('error', 'Kayıt hatası: ' . h($e->getMessage()));
    }
    redirect('mesajlar.php');
}

// ── Liste ─────────────────────────────────────────────────────────────────────
$f = in_array($_GET['d'] ?? 'bekliyor', ['bekliyor','onaylandi','reddedildi','tumu'], true) ? ($_GET['d'] ?? 'bekliyor') : 'bekliyor';
$sql = "SELECT m.*, u.full_name AS islenen_ad
        FROM mesaj_kuyrugu m LEFT JOIN users u ON u.id = m.islenen_by ";
$sql .= $f === 'tumu' ? "" : "WHERE m.durum = " . $pdo->quote($f) . " ";
$sql .= "ORDER BY m.created_at DESC LIMIT 100";
$mesajlar = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$say = $pdo->query("SELECT durum, COUNT(*) c FROM mesaj_kuyrugu GROUP BY durum")->fetchAll(PDO::FETCH_KEY_PAIR);
$hataliAi = (int)$pdo->query("SELECT COUNT(*) FROM mesaj_kuyrugu WHERE ai_durum='hata' AND durum='bekliyor'")->fetchColumn();

$t = mesaj_tanimlar($pdo);
$aiHazir = defined('AI_PROVIDER') && AI_PROVIDER !== '';

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-chat-dots text-primary me-2"></i>Gelen Mesajlar</h4>
    <form method="post" class="d-inline">
        <button name="toplu_coz" value="1" class="btn btn-outline-primary btn-sm" <?= $aiHazir?'':'disabled' ?>>
            <i class="bi bi-stars me-1"></i> Bekleyenleri AI ile Çözümle
        </button>
    </form>
</div>

<?php if (!$aiHazir): ?>
<div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>
    AI sağlayıcı tanımlı değil (<code>AI_PROVIDER</code>). Mesajlar kuyruğa girer ama otomatik çözümlenemez —
    alanları elle doldurup onaylayabilirsiniz.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card border-warning h-100"><div class="card-body py-3">
        <div class="fw-bold fs-5"><?= (int)($say['bekliyor'] ?? 0) ?></div>
        <div class="text-muted small">Onay bekleyen</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-success h-100"><div class="card-body py-3">
        <div class="fw-bold fs-5"><?= (int)($say['onaylandi'] ?? 0) ?></div>
        <div class="text-muted small">Onaylanan</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-secondary h-100"><div class="card-body py-3">
        <div class="fw-bold fs-5"><?= (int)($say['reddedildi'] ?? 0) ?></div>
        <div class="text-muted small">Reddedilen</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card <?= $hataliAi?'border-danger':'' ?> h-100"><div class="card-body py-3">
        <div class="fw-bold fs-5 <?= $hataliAi?'text-danger':'' ?>"><?= $hataliAi ?></div>
        <div class="text-muted small">AI çözümleyemedi</div></div></div></div>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-inbox me-1"></i> Kuyruk (<?= count($mesajlar) ?>)</span>
        <div class="btn-group btn-group-sm">
          <?php foreach (['bekliyor'=>'Bekleyen','onaylandi'=>'Onaylanan','reddedildi'=>'Reddedilen','tumu'=>'Tümü'] as $k=>$v): ?>
            <a href="mesajlar.php?d=<?= $k ?>" class="btn btn-outline-secondary <?= $f===$k?'active':'' ?>"><?= $v ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card-body p-0">
      <?php if (!$mesajlar): ?>
        <div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>Bu filtrede mesaj yok.</div>
      <?php endif; ?>

      <?php foreach ($mesajlar as $m):
        $kayitlar = json_decode((string)$m['ai_json'], true);
        $kayitlar = is_array($kayitlar) ? $kayitlar : [];
        $ilk = $kayitlar[0] ?? [];
      ?>
        <div class="border-bottom p-3">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-2 flex-wrap">
            <div class="small">
              <span class="badge bg-dark"><?= h($m['kaynak']) ?></span>
              <?php if ($m['gonderen']): ?><strong class="ms-1"><?= h($m['gonderen']) ?></strong><?php endif; ?>
              <?php if ($m['grup_adi']): ?><span class="text-muted">· <?= h($m['grup_adi']) ?></span><?php endif; ?>
              <span class="text-muted">· <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></span>
            </div>
            <div>
              <?php if ($m['durum']==='onaylandi'): ?>
                <span class="badge bg-success">Onaylandı</span>
                <?php if ($m['irsaliye_id']): ?>
                  <a href="irsaliye_detay.php?id=<?= (int)$m['irsaliye_id'] ?>" class="badge bg-primary text-decoration-none">İrsaliye #<?= (int)$m['irsaliye_id'] ?></a>
                <?php endif; ?>
              <?php elseif ($m['durum']==='reddedildi'): ?>
                <span class="badge bg-secondary">Reddedildi</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">Bekliyor</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="p-2 rounded small font-monospace mb-2" style="background:var(--bt-tint);white-space:pre-wrap"><?= h($m['ham_metin']) ?></div>

          <?php if ($m['ai_durum']==='hata'): ?>
            <div class="alert alert-danger py-1 px-2 small mb-2"><i class="bi bi-exclamation-octagon me-1"></i><?= h((string)$m['ai_hata']) ?></div>
          <?php endif; ?>

          <?php if ($m['durum']==='bekliyor'): ?>
            <?php if ($m['ai_durum']==='bekliyor'): ?>
              <form method="post" class="d-inline">
                <button name="ai_coz" value="<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-primary" <?= $aiHazir?'':'disabled' ?>>
                  <i class="bi bi-stars me-1"></i>AI ile çözümle</button>
              </form>
            <?php endif; ?>

            <?php if ($kayitlar && !empty($ilk)): ?>
              <div class="small text-muted mb-1">
                AI önerisi<?= count($kayitlar)>1 ? ' (bu mesajda '.count($kayitlar).' sevkiyat bulundu — ilki gösteriliyor)' : '' ?>
                <?php if (isset($ilk['guven'])): ?>
                  · güven: <strong class="<?= ((float)$ilk['guven'] < .6)?'text-danger':'text-success' ?>"><?= number_format((float)$ilk['guven']*100,0) ?>%</strong>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <form method="post" class="row g-2 align-items-end">
              <input type="hidden" name="onayla" value="<?= (int)$m['id'] ?>">
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Tip</label>
                <select name="tip" class="form-select form-select-sm">
                  <option value="alis" <?= (($ilk['tip'] ?? 'alis')==='alis')?'selected':'' ?>>Alış</option>
                  <option value="iade" <?= (($ilk['tip'] ?? '')==='iade')?'selected':'' ?>>İade</option>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0">İrsaliye No</label>
                <input name="irsaliye_no" class="form-control form-control-sm" value="<?= h((string)($ilk['irsaliye_no'] ?? '')) ?>">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Tarih *</label>
                <input type="date" name="tarih" class="form-control form-control-sm" required
                       value="<?= h((string)($ilk['tarih'] ?? date('Y-m-d'))) ?>">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Plaka</label>
                <input name="arac_plaka" class="form-control form-control-sm" value="<?= h((string)($ilk['arac_plaka'] ?? '')) ?>">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Miktar (m³)</label>
                <input name="miktar" class="form-control form-control-sm" value="<?= h((string)($ilk['miktar'] ?? '')) ?>">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Tedarikçi *</label>
                <select name="tedarikci_id" class="form-select form-select-sm" required>
                  <option value="">— seç —</option>
                  <?php foreach ($t['tedarikciler'] as $x): ?>
                    <option value="<?= (int)$x['id'] ?>" <?= ((int)($ilk['tedarikci_id'] ?? 0)===(int)$x['id'])?'selected':'' ?>><?= h($x['ad']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Beton Sınıfı</label>
                <select name="beton_sinifi_id" class="form-select form-select-sm">
                  <option value="">—</option>
                  <?php foreach ($t['beton'] as $x): ?>
                    <option value="<?= (int)$x['id'] ?>" <?= ((int)($ilk['beton_sinifi_id'] ?? 0)===(int)$x['id'])?'selected':'' ?>><?= h($x['ad']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Proje</label>
                <select name="proje_id" class="form-select form-select-sm">
                  <option value="">—</option>
                  <?php foreach ($t['projeler'] as $x): ?>
                    <option value="<?= (int)$x['id'] ?>" <?= ((int)($ilk['proje_id'] ?? 0)===(int)$x['id'])?'selected':'' ?>><?= h($x['kod'] ?: $x['ad']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0">Kıvam</label>
                <select name="kivam_sinifi_id" class="form-select form-select-sm">
                  <option value="">—</option>
                  <?php foreach ($t['kivam'] as $x): ?>
                    <option value="<?= (int)$x['id'] ?>" <?= ((int)($ilk['kivam_sinifi_id'] ?? 0)===(int)$x['id'])?'selected':'' ?>><?= h($x['ad']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label small mb-0">Açıklama</label>
                <input name="aciklama" class="form-control form-control-sm" value="<?= h((string)($ilk['aciklama'] ?? '')) ?>">
              </div>
              <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Onayla</button>
              </div>
            </form>

            <form method="post" class="mt-2 d-flex gap-2">
              <input name="not_metni" class="form-control form-control-sm" placeholder="Ret nedeni (opsiyonel)">
              <button name="reddet" value="<?= (int)$m['id'] ?>" class="btn btn-outline-danger btn-sm text-nowrap btn-confirm"
                      data-msg="Bu mesaj reddedilecek. Emin misiniz?"><i class="bi bi-x-lg me-1"></i>Reddet</button>
            </form>
          <?php elseif ($m['not_metni']): ?>
            <div class="small text-muted"><i class="bi bi-sticky me-1"></i><?= h((string)$m['not_metni']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card">
      <div class="card-header fw-semibold"><i class="bi bi-pencil-square me-1"></i> Elle Mesaj Ekle</div>
      <div class="card-body">
        <p class="small text-muted">WhatsApp mesajını kopyalayıp buraya yapıştırın; AI ayrıştırıp yukarıdaki kuyruğa düşürür.</p>
        <form method="post">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Gönderen</label>
            <input name="gonderen" class="form-control form-control-sm" placeholder="Ad Soyad (opsiyonel)">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Mesaj</label>
            <textarea name="ham_metin" rows="5" class="form-control form-control-sm" required
                      placeholder="Örn: 15.03 C30/37 28 m3 34 ABC 123 irsaliye 456789 Çakıroğlu"></textarea>
          </div>
          <button name="mesaj_ekle" value="1" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-plus-lg me-1"></i> Ekle ve Çözümle</button>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-body small text-muted">
        <div class="fw-semibold text-body mb-2"><i class="bi bi-info-circle me-1"></i>Nasıl çalışır?</div>
        <ol class="mb-2 ps-3">
          <li>Mesaj kuyruğa düşer (WhatsApp botu ya da elle).</li>
          <li>AI metinden sevkiyat bilgilerini çıkarır.</li>
          <li><strong>Siz kontrol edip onaylarsınız</strong> — ancak o zaman irsaliye oluşur.</li>
        </ol>
        <div class="border-top pt-2">
          <div class="fw-semibold text-body mb-1">Dış kaynak bağlantısı</div>
          <code class="d-block" style="font-size:.7rem">POST /api/mesaj_al.php</code>
          <span>Token <code>MESAJ_TOKEN</code> (config.php) ile korunur.</span>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
