<?php
/**
 * whatsapp/mesajlar.php — Gelen Mesajlar / Onay Kuyruğu
 *
 * Saha WhatsApp grubundan gelen mesajlar AI ile çözümlenir; çıkan
 * ARAÇ HAREKETLERİ ve EVRAK/GÖRSEL bildirimleri kullanıcı onayından geçer.
 * Bu modül beton irsaliyesi OLUŞTURMAZ — yalnız araç ve evrak takibi yapar.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_ortak.php';

if (!can_edit()) { flash('error', 'Bu sayfa için yetkiniz yok.'); redirect('saha_analiz.php'); }

$pageTitle = 'Gelen Mesajlar — Saha Takip';
mesaj_semasi_kur($pdo);
saha_semasi_kur($pdo);

$uid     = current_user_id();
$kullanici = current_user();
$adSoyad = trim((string)($kullanici['full_name'] ?? $kullanici['username'] ?? ''));

// ── Elle mesaj ekle ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mesaj_ekle'])) {
    $metin = trim((string)($_POST['ham_metin'] ?? ''));
    // ── Görselleri yükle (kantar fişi / irsaliye fotoğrafı — AI okuyacak) ────
    $gorseller = [];
    $yuklemeHatasi = [];
    if (!empty($_FILES['gorseller']['name'][0])) {
        $dizin = __DIR__ . '/../uploads/whatsapp/' . date('Y/m');
        if (!is_dir($dizin)) @mkdir($dizin, 0755, true);
        $izin = ['image/jpeg','image/png','image/webp','image/gif'];
        foreach ($_FILES['gorseller']['tmp_name'] as $i => $tmp) {
            if (($_FILES['gorseller']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            $ad   = (string)$_FILES['gorseller']['name'][$i];
            $mime = guess_mime($tmp, $ad);
            if (!in_array($mime, $izin, true))                 { $yuklemeHatasi[] = "$ad: desteklenmeyen tür"; continue; }
            if (($_FILES['gorseller']['size'][$i] ?? 0) > 8*1024*1024) { $yuklemeHatasi[] = "$ad: 8 MB'dan büyük"; continue; }
            $uzanti = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'jpg';
            $hedef  = uniqid('wa_', true) . '.' . preg_replace('/[^a-z0-9]/', '', $uzanti);
            if (move_uploaded_file($tmp, $dizin . '/' . $hedef)) {
                $gorseller[] = 'uploads/whatsapp/' . date('Y/m') . '/' . $hedef;
            }
        }
    }
    if ($yuklemeHatasi) flash('error', implode(' · ', $yuklemeHatasi));

    if ($metin === '' && !$gorseller) {
        flash('error', 'Mesaj metni veya görsel gerekli.');
    } else {
        $elleUrl = trim((string)($_POST['medya_url'] ?? ''));
        if ($elleUrl !== '') $gorseller[] = $elleUrl;
        $r = mesaj_kuyruga_ekle($pdo, [
            'kaynak'    => 'manuel',
            'gonderen'  => trim((string)($_POST['gonderen'] ?? '')) ?: 'Elle giriş',
            'metin'     => $metin !== '' ? $metin : '(yalnız görsel)',
            'medya'     => $gorseller,
        ]);
        if (!$r['ok'])                  flash('error', $r['msg'] ?? 'Eklenemedi.');
        elseif (!empty($r['mukerrer'])) flash('error', 'Bu mesaj zaten kuyrukta.');
        else {
            $ai = mesaj_isle($pdo, $r['id']);
            flash($ai['ok'] ? 'success' : 'error', $ai['ok']
                ? ('Mesaj çözümlendi: ' . (int)($ai['olay_sayisi'] ?? 0) . ' hareket, ' . (int)($ai['evrak_sayisi'] ?? 0) . ' evrak.')
                : ('Mesaj eklendi ama AI çözümleyemedi: ' . ($ai['msg'] ?? '')));
        }
    }
    redirect('mesajlar.php');
}

// ── AI ile (yeniden) çözümle ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_coz']) && ctype_digit($_POST['ai_coz'])) {
    $r = mesaj_isle($pdo, (int)$_POST['ai_coz']);
    flash($r['ok'] ? 'success' : 'error', $r['ok']
        ? ((int)($r['olay_sayisi'] ?? 0) . ' hareket, ' . (int)($r['evrak_sayisi'] ?? 0) . ' evrak bulundu.')
        : ('AI hatası: ' . ($r['msg'] ?? '')));
    redirect('mesajlar.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toplu_coz'])) {
    $bekleyen = $pdo->query("SELECT id FROM mesaj_kuyrugu WHERE durum='bekliyor' AND ai_durum='bekliyor' ORDER BY id LIMIT 20")
                    ->fetchAll(PDO::FETCH_COLUMN);
    $ok = 0; $hata = 0;
    foreach ($bekleyen as $mid) { $r = mesaj_isle($pdo, (int)$mid); $r['ok'] ? $ok++ : $hata++; }
    flash($hata ? 'error' : 'success', "$ok mesaj çözümlendi" . ($hata ? ", $hata hata." : "."));
    redirect('mesajlar.php');
}

// ── Onayla: mesajdaki araç hareketleri + evraklar kabul edilir ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['onayla']) && ctype_digit($_POST['onayla'])) {
    $mid = (int)$_POST['onayla'];
    // Evrakta onaylayan: mesajda geçen isim boşsa onaylayan kullanıcı yazılır
    $pdo->prepare("UPDATE saha_evrak
                   SET durum='onaylandi', onay_user=?, onay_at=NOW(),
                       onaylayan = COALESCE(NULLIF(onaylayan,''), ?)
                   WHERE mesaj_id=? AND durum='bekliyor'")
        ->execute([$uid, $adSoyad, $mid]);
    $pdo->prepare("UPDATE mesaj_kuyrugu SET durum='onaylandi', islenen_at=NOW(), islenen_by=? WHERE id=?")
        ->execute([$uid, $mid]);
    flash('success', 'Onaylandı.');
    redirect('mesajlar.php');
}

// ── Reddet ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reddet']) && ctype_digit($_POST['reddet'])) {
    $mid = (int)$_POST['reddet'];
    $pdo->prepare("UPDATE saha_evrak SET durum='reddedildi', onay_user=?, onay_at=NOW() WHERE mesaj_id=? AND durum='bekliyor'")
        ->execute([$uid, $mid]);
    // Reddedilen mesajın araç hareketleri raporlara girmesin
    $pdo->prepare("DELETE FROM saha_olaylari WHERE mesaj_id=?")->execute([$mid]);
    $pdo->prepare("UPDATE mesaj_kuyrugu SET durum='reddedildi', islenen_at=NOW(), islenen_by=?, not_metni=? WHERE id=?")
        ->execute([$uid, mb_substr(trim((string)($_POST['not_metni'] ?? '')), 0, 300) ?: null, $mid]);
    flash('success', 'Mesaj reddedildi.');
    redirect('mesajlar.php');
}

// ── Liste ─────────────────────────────────────────────────────────────────────
$f = in_array($_GET['d'] ?? 'bekliyor', ['bekliyor','onaylandi','reddedildi','tumu'], true) ? ($_GET['d'] ?? 'bekliyor') : 'bekliyor';
$sql = "SELECT m.*, u.full_name AS islenen_ad
        FROM mesaj_kuyrugu m LEFT JOIN users u ON u.id = m.islenen_by ";
$sql .= $f === 'tumu' ? "" : "WHERE m.durum = " . $pdo->quote($f) . " ";
$sql .= "ORDER BY m.created_at DESC LIMIT 100";
$mesajlar = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// İlgili olay/evrak kayıtlarını topluca çek
$olayMap = []; $evrakMap = [];
if ($mesajlar) {
    $ids = implode(',', array_map(fn($m) => (int)$m['id'], $mesajlar));
    foreach ($pdo->query("SELECT * FROM saha_olaylari WHERE mesaj_id IN ($ids) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $olayMap[$o['mesaj_id']][] = $o;
    }
    foreach ($pdo->query("SELECT * FROM saha_evrak WHERE mesaj_id IN ($ids) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $evrakMap[$e['mesaj_id']][] = $e;
    }
}

$say = $pdo->query("SELECT durum, COUNT(*) c FROM mesaj_kuyrugu GROUP BY durum")->fetchAll(PDO::FETCH_KEY_PAIR);
$hataliAi = (int)$pdo->query("SELECT COUNT(*) FROM mesaj_kuyrugu WHERE ai_durum='hata' AND durum='bekliyor'")->fetchColumn();
$aiHazir  = defined('AI_PROVIDER') && AI_PROVIDER !== '';

$TUR_AD = ['arac_giris'=>'Araç Giriş','arac_cikis'=>'Araç Çıkış','arac'=>'Araç',
           'personel_giris'=>'Personel Giriş','personel_cikis'=>'Personel Çıkış',
           'yetki'=>'Yetki','is'=>'İş','diger'=>'Diğer'];
$TUR_RENK = ['arac_giris'=>'bg-success','arac_cikis'=>'bg-secondary','arac'=>'bg-info text-dark',
             'personel_giris'=>'bg-success','personel_cikis'=>'bg-secondary',
             'yetki'=>'bg-warning text-dark','is'=>'bg-primary','diger'=>'bg-light text-dark border'];
$EVRAK_AD = ['irsaliye'=>'İrsaliye','tutanak'=>'Tutanak','fatura'=>'Fatura','puantaj'=>'Puantaj',
             'ruhsat'=>'Ruhsat','foto'=>'Fotoğraf','diger'=>'Diğer'];

require_once __DIR__ . '/../includes/header.php';
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
    AI sağlayıcı tanımlı değil (<code>AI_PROVIDER</code>). Mesajlar kuyruğa girer ama çözümlenemez.</div>
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
        $olaylar = $olayMap[$m['id']]  ?? [];
        $evrak   = $evrakMap[$m['id']] ?? [];
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
                <?php if ($m['islenen_ad']): ?><span class="badge bg-light text-dark border"><?= h($m['islenen_ad']) ?></span><?php endif; ?>
              <?php elseif ($m['durum']==='reddedildi'): ?>
                <span class="badge bg-secondary">Reddedildi</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">Bekliyor</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="p-2 rounded small font-monospace mb-2" style="background:var(--bt-tint);white-space:pre-wrap"><?= h($m['ham_metin']) ?></div>

          <?php $mGorseller = mesaj_gorseller($m); if ($mGorseller): ?>
            <div class="d-flex flex-wrap gap-2 mb-2">
              <?php foreach ($mGorseller as $gi => $gu):
                $src = (strpos($gu, 'http') === 0) ? $gu : '../' . ltrim($gu, '/'); ?>
                <a href="<?= h($src) ?>" target="_blank" rel="noopener">
                  <img src="<?= h($src) ?>" alt="Ek görsel <?= $gi+1 ?>" style="max-height:130px;max-width:170px;object-fit:cover"
                       class="rounded border" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'badge bg-secondary',textContent:'📎 Ek '+(<?= $gi ?>+1)}))">
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($m['ai_durum']==='hata'): ?>
            <div class="alert alert-danger py-1 px-2 small mb-2"><i class="bi bi-exclamation-octagon me-1"></i><?= h((string)$m['ai_hata']) ?></div>
          <?php endif; ?>

          <?php if ($olaylar): ?>
            <div class="small fw-semibold text-muted mb-1">Araç / saha hareketleri</div>
            <div class="table-responsive mb-2">
              <table class="table table-sm table-borderless mb-0 small">
                <tbody>
                <?php foreach ($olaylar as $o):
                  $sa = $o['saat_bas'] ? substr((string)$o['saat_bas'],0,5) : '';
                  if ($o['saat_bit']) $sa .= ($sa?'–':'') . substr((string)$o['saat_bit'],0,5);
                  $g = $o['guven'] !== null ? (float)$o['guven'] : null;
                ?>
                  <tr>
                    <td style="width:110px"><span class="badge <?= $TUR_RENK[$o['tur']] ?? 'bg-light text-dark' ?>"><?= h($TUR_AD[$o['tur']] ?? $o['tur']) ?></span></td>
                    <td class="fw-semibold font-monospace"><?= h((string)($o['arac_plaka'] ?: $o['kisi'])) ?: '—' ?></td>
                    <td><?= h((string)$o['arac_cinsi']) ?></td>
                    <td><?= h((string)$o['firma']) ?></td>
                    <td class="text-nowrap"><?= h($sa) ?><?php if ($o['sure_saat']): ?> <span class="text-muted">(<?= number_format((float)$o['sure_saat'],1,',','.') ?>s)</span><?php endif; ?></td>
                    <td class="text-end"><?php if ($g!==null): ?><span class="<?= $g<.6?'text-danger fw-semibold':'text-success' ?>"><?= number_format($g*100,0) ?>%</span><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if ($evrak): ?>
            <div class="small fw-semibold text-muted mb-1">Evraklar</div>
            <div class="d-flex flex-wrap gap-2 mb-2">
              <?php foreach ($evrak as $e): ?>
                <span class="badge bg-light text-dark border py-2">
                  <i class="bi bi-file-earmark-text me-1"></i>
                  <strong><?= h($EVRAK_AD[$e['tur']] ?? $e['tur']) ?></strong>
                  <?php if ($e['belge_no']): ?> · <?= h((string)$e['belge_no']) ?><?php endif; ?>
                  <?php if ($e['firma']): ?> · <?= h((string)$e['firma']) ?><?php endif; ?>
                  <?php if ($e['onaylayan']): ?> · <span class="text-success">onay: <?= h((string)$e['onaylayan']) ?></span><?php endif; ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($m['durum']==='bekliyor'): ?>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <?php if ($m['ai_durum']==='bekliyor' || $m['ai_durum']==='hata'): ?>
                <form method="post" class="d-inline">
                  <button name="ai_coz" value="<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-primary" <?= $aiHazir?'':'disabled' ?>>
                    <i class="bi bi-stars me-1"></i>AI ile çözümle</button>
                </form>
              <?php endif; ?>
              <?php if ($olaylar || $evrak): ?>
                <form method="post" class="d-inline">
                  <button name="onayla" value="<?= (int)$m['id'] ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-check-lg me-1"></i>Onayla</button>
                </form>
              <?php endif; ?>
              <form method="post" class="d-flex gap-2 flex-grow-1" style="max-width:420px">
                <input name="not_metni" class="form-control form-control-sm" placeholder="Ret nedeni (opsiyonel)">
                <button name="reddet" value="<?= (int)$m['id'] ?>" class="btn btn-outline-danger btn-sm text-nowrap btn-confirm"
                        data-msg="Bu mesaj reddedilecek, çıkarılan hareketler silinecek. Emin misiniz?"><i class="bi bi-x-lg me-1"></i>Reddet</button>
              </form>
            </div>
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
        <p class="small text-muted">Grup mesajını kopyalayıp yapıştırın; AI araç hareketlerini ve evrakları çıkarır.</p>
        <form method="post" enctype="multipart/form-data">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Gönderen</label>
            <input name="gonderen" class="form-control form-control-sm" placeholder="Ad Soyad (opsiyonel)">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Mesaj</label>
            <textarea name="ham_metin" rows="4" class="form-control form-control-sm"
                      placeholder="Örn: Safi beton mikser yeni kapı çıkış"></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">
              <i class="bi bi-camera me-1"></i>Görseller <span class="text-muted fw-normal">(kantar fişi, irsaliye, araç…)</span>
            </label>
            <input type="file" name="gorseller[]" class="form-control form-control-sm" accept="image/*" multiple
                   onchange="waOnizle(this)">
            <div class="form-text">Birden fazla seçebilirsiniz. AI fişteki plaka, giriş/çıkış saati ve firmayı okur.</div>
            <div id="waOnizleme" class="d-flex flex-wrap gap-2 mt-2"></div>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">…veya görsel bağlantısı</label>
            <input name="medya_url" class="form-control form-control-sm" placeholder="https://…">
          </div>
          <button name="mesaj_ekle" value="1" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-plus-lg me-1"></i> Ekle ve Çözümle</button>
        </form>
        <script>
        function waOnizle(inp){
          var k = document.getElementById('waOnizleme'); k.innerHTML = '';
          Array.prototype.slice.call(inp.files, 0, 6).forEach(function(f){
            if (!f.type.startsWith('image/')) return;
            var img = document.createElement('img');
            img.style.cssText = 'height:64px;width:64px;object-fit:cover;border-radius:6px';
            img.className = 'border';
            img.src = URL.createObjectURL(f);
            img.onload = function(){ URL.revokeObjectURL(img.src); };
            k.appendChild(img);
          });
        }
        </script>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-body small text-muted">
        <div class="fw-semibold text-body mb-2"><i class="bi bi-info-circle me-1"></i>Bu modül ne yapar?</div>
        <ul class="mb-2 ps-3">
          <li><strong>Araç takibi</strong> — hangi araç ne zaman girdi/çıktı, ne kadar kaldı.</li>
          <li><strong>Evrak takibi</strong> — paylaşılan irsaliye/tutanak görselleri ve onayı veren kişi.</li>
        </ul>
        <div class="border-top pt-2">Beton irsaliyesi burada <strong>oluşturulmaz</strong>;
          o işlem Beton modülündedir.</div>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
