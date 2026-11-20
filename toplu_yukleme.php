<?php
/**
 * toplu_yukleme.php — GÜNLÜK TOPLU YÜKLEME (tüm modüller)
 *
 * E-postayla her gün gelen takip Excel'leri tek tek modül ekranlarına yüklemek yerine hepsi
 * birden seçilir; sistem her dosyayı içeriğinden tanır ve ilgili modüle aktarır.
 * Çekirdek: includes/toplu_yukleme.php (ty_*). Dosyalar tarayıcıdan TEK TEK gönderilir
 * (sunucu boyut sınırı + zaman aşımı dosya başına kalsın); JS yoksa hepsi tek istekte gider.
 * Sonuç oturumda tutulur → ?rapor=1 (PRG).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth();
if (!toplu_yukleme_izinli()) { http_response_code(403); include __DIR__ . '/includes/403.php'; exit; }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/toplu_yukleme.php';

$pageTitle = 'Günlük Toplu Yükleme';
ty_log_kur($pdo);

/** $_FILES['dosya'] (tekli ya da çoklu) → dosya listesi */
$dosyaListesi = function (array $f): array {
    $l = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $ad) $l[] = ['name' => $ad, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i]];
    } else $l[] = $f;
    return array_values(array_filter($l, fn($d) => (int)($d['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
};
/** Aynı depo bölümü iki dosyada gelirse son işlenen esastır — kullanıcı bilsin. */
$bolumUyar = function (array &$rap): void {
    foreach ($rap['bolumler'] ?? [] as $b) {
        $onceki = $_SESSION['ty_rapor']['bolum'][$b] ?? null;
        if ($onceki !== null && $onceki !== $rap['ad'])
            $rap['uyari'][] = [(DP_BOLUM[$b][0] ?? $b) . " bölümü \"$onceki\" dosyasında da vardı — son işlenen (bu dosya) esas alındı.", []];
        $_SESSION['ty_rapor']['bolum'][$b] = $rap['ad'];
    }
    if (($rap['uyari'] ?? []) && $rap['durum'] === 'ok') $rap['durum'] = 'uyari';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = $_POST['islem'] ?? '';
    if ($islem === 'basla') {                       // JS: yeni toplu yükleme başlıyor
        $_SESSION['ty_rapor'] = ['zaman' => date('Y-m-d H:i:s'), 'secilen' => max(0, (int)($_POST['adet'] ?? 0)), 'satirlar' => [], 'bolum' => []];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]); exit;
    }
    if ($islem === 'dosya' && isset($_FILES['dosya'])) {   // JS: tek dosya
        if (!isset($_SESSION['ty_rapor'])) $_SESSION['ty_rapor'] = ['zaman' => date('Y-m-d H:i:s'), 'secilen' => 0, 'satirlar' => [], 'bolum' => []];
        $rap = ty_dosya($pdo, $dosyaListesi($_FILES['dosya'])[0] ?? ['name' => 'dosya', 'error' => UPLOAD_ERR_NO_FILE], 'toplu_yukleme_izinli');
        $bolumUyar($rap);
        $_SESSION['ty_rapor']['satirlar'][] = $rap;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'ad' => $rap['ad'], 'durum' => $rap['durum'],
                          'etiket' => $rap['tur'] ? TY_TUR[$rap['tur']][1] : 'tanınmadı',
                          'ozet' => $rap['hata'] ?? strip_tags(implode(' · ', $rap['ozet']))], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($islem === 'kontrol_hata') {                // JS: gönderilemeyen dosya rapora yazılsın
        $ad = mb_substr(basename((string)($_POST['ad'] ?? 'dosya')), 0, 255);
        $_SESSION['ty_rapor']['satirlar'][] = ['ad' => $ad, 'tur' => null, 'durum' => 'hata', 'ozet' => [], 'uyari' => [], 'farkli' => [], 'bolumler' => [],
            'hata' => 'Dosya sunucuya ulaşmadı — ' . mb_substr((string)($_POST['sebep'] ?? 'bağlantı hatası'), 0, 200) . '. Tekrar yükleyin.'];
        ty_log_yaz($pdo, $ad, null, 'hata', 'Dosya sunucuya ulaşmadı');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]); exit;
    }
    if (isset($_FILES['dosya'])) {                  // JS kapalı: hepsi tek istekte
        $liste = $dosyaListesi($_FILES['dosya']);
        usort($liste, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
        $_SESSION['ty_rapor'] = ['zaman' => date('Y-m-d H:i:s'), 'secilen' => count($liste), 'satirlar' => [], 'bolum' => []];
        foreach ($liste as $d) {
            $rap = ty_dosya($pdo, $d, 'toplu_yukleme_izinli');
            $bolumUyar($rap);
            $_SESSION['ty_rapor']['satirlar'][] = $rap;
        }
        if (!$liste) flash('warning', 'Dosya seçilmedi.');
    }
    redirect('toplu_yukleme.php?rapor=1');
}

$rapor = isset($_GET['rapor']) ? ($_SESSION['ty_rapor'] ?? null) : null;
$son   = ty_son_yuklemeler($pdo);
// Modül ekranından yapılan depo yüklemeleri de "bugün yüklendi mi" panelinde sayılsın
try {
    if (toplu_yukleme_izinli('depo')) {
        $dpTur = ['demirbas' => 'depo_demirbas', 'sarf' => 'depo_sarf', 'el_aleti' => 'depo_sarf', 'giris|depo' => 'depo_malzeme',
                  'cikis|depo' => 'depo_malzeme', 'giris|taseron' => 'depo_taseron', 'cikis|taseron' => 'depo_taseron'];
        foreach (dp_import_son(ty_pdo('depo')) as $b => $r) {
            $t = $dpTur[$b] ?? null;
            if ($t && (!isset($son[$t]) || $r['created'] > $son[$t]['created']))
                $son[$t] = ['created' => $r['created'], 'dosya' => $r['dosya'], 'kullanici' => $r['kullanici'], 'durum' => 'ok', 'kaynak' => 'Depo ekranı'];
        }
    }
} catch (Throwable $e) { /* depo DB'si yoksa yalnız toplu yükleme günlüğü */ }
$gecmis = $pdo->query("SELECT * FROM toplu_yukleme_log ORDER BY id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
$bugun = date('Y-m-d');

require_once __DIR__ . '/includes/header.php';
$DURUM = ['ok' => ['success', 'bi-check-circle-fill', 'Aktarıldı'], 'uyari' => ['warning', 'bi-exclamation-triangle-fill', 'Aktarıldı · uyarılı'],
          'yonlendir' => ['info', 'bi-box-arrow-up-right', 'Modül ekranından'], 'yetki' => ['secondary', 'bi-lock-fill', 'Yetki yok'],
          'hata' => ['danger', 'bi-x-octagon-fill', 'Aktarılmadı']];
$tarihYaz = fn(?string $t) => $t ? date('d.m.Y H:i', strtotime($t)) : '—';
?>
<style>
  .ty-drop{border:2px dashed var(--bt-border,#cbd5e1);border-radius:16px;padding:2rem 1rem;text-align:center;cursor:pointer;transition:.15s;background:var(--bt-surface-2,rgba(0,0,0,.02))}
  .ty-drop.ustunde,.ty-drop:hover{border-color:var(--ern);background:var(--bt-tint,rgba(0,88,78,.06))}
  .ty-drop i{font-size:2.4rem;color:var(--ern)}
  .ty-gun{border-radius:12px;padding:.6rem .75rem;border:1px solid var(--bt-border,#e2e8f0);height:100%}
  .ty-gun.bugun{border-color:rgba(25,135,84,.45);background:rgba(25,135,84,.07)}
  .ty-gun.eski{border-color:rgba(255,193,7,.55);background:rgba(255,193,7,.08)}
  .ty-liste li{padding:.35rem 0;border-bottom:1px solid var(--bt-border-soft,#f1f5f9)}
  .ty-satir td{vertical-align:top}
</style>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-cloud-arrow-up-fill text-primary me-2"></i>Günlük Toplu Yükleme</h4>
  <span class="text-muted small">E-postayla gelen Excel'leri hep birlikte seçin — her dosya içeriğinden tanınır ve kendi modülüne aktarılır.</span>
</div>

<?php if ($rapor): $sat = $rapor['satirlar']; $say = array_count_values(array_column($sat, 'durum')); ?>
<div class="card mb-3 border-0 shadow-sm">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <h5 class="mb-0 fw-semibold"><i class="bi bi-clipboard-check text-success me-1"></i>Yükleme Sonucu</h5>
      <span class="text-muted small"><?= h($tarihYaz($rapor['zaman'])) ?></span>
      <?php if (!empty($rapor['secilen'])): ?><span class="badge <?= $rapor['secilen'] == count($sat) ? 'bg-light text-dark border' : 'bg-danger' ?>"><?= (int)$rapor['secilen'] ?> dosya seçildi · <?= count($sat) ?> işlendi</span><?php endif; ?>
      <?php foreach ($DURUM as $k => [$renk, $ik, $et]) if (!empty($say[$k])): ?><span class="badge bg-<?= $renk ?>"><i class="bi <?= $ik ?> me-1"></i><?= (int)$say[$k] ?> <?= h($et) ?></span><?php endif; ?>
    </div>
    <?php if (!empty($rapor['secilen']) && $rapor['secilen'] != count($sat)): ?>
      <div class="alert alert-danger py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i><?= (int)$rapor['secilen'] - count($sat) ?> dosya işlenmedi (bağlantı kesilmiş olabilir) — eksik dosyaları yeniden yükleyin.</div>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th style="width:24%">Dosya</th><th style="width:20%">Tanınan</th><th>Sonuç</th><th style="width:1%"></th></tr></thead>
        <tbody>
        <?php foreach ($sat as $r): [$renk, $ik, $et] = $DURUM[$r['durum']] ?? $DURUM['hata']; $T = $r['tur'] ? TY_TUR[$r['tur']] : null; ?>
          <tr class="ty-satir">
            <td class="small text-break"><i class="bi bi-file-earmark-excel text-success me-1"></i><?= h($r['ad']) ?></td>
            <td class="small"><?php if ($T): ?><i class="bi <?= $T[2] ?> me-1"></i><?= h($T[1]) ?><div class="text-muted" style="font-size:.72rem"><?= h(function_exists('modul_ad') ? modul_ad($T[0]) : $T[0]) ?></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td class="small">
              <span class="badge bg-<?= $renk ?> mb-1"><i class="bi <?= $ik ?> me-1"></i><?= h($et) ?></span>
              <?php if (!empty($r['hata'])): ?><div class="text-danger"><?= h($r['hata']) ?></div><?php endif; ?>
              <?php foreach ($r['ozet'] as $o): ?><div><?= $o /* çekirdekte sayılar + h() ile kurulmuş */ ?></div><?php endforeach; ?>
              <?php foreach ($r['uyari'] as [$msg, $det]): ?>
                <div class="text-warning-emphasis"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i><?= h($msg) ?>
                  <?php if ($det): ?><details class="d-inline"><summary class="d-inline text-primary" style="cursor:pointer;font-size:.75rem">satırlar</summary>
                    <ul class="mb-1 ps-3" style="font-size:.75rem"><?php foreach ($det as $dl): ?><li><?= h((string)$dl) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php if ($r['farkli']): ?>
                <details><summary class="text-primary" style="cursor:pointer;font-size:.75rem">Excel ↔ sistem farklı kayıtlar (<?= count($r['farkli']) ?>)</summary>
                  <ul class="mb-1 ps-3" style="font-size:.75rem"><?php foreach ($r['farkli'] as $fk): ?><li><?= h($fk['metin']) ?> <a href="irsaliye_form.php?id=<?= (int)$fk['id'] ?>">kaydı aç</a></li><?php endforeach; ?></ul></details>
              <?php endif; ?>
            </td>
            <td class="text-nowrap"><?php if ($T): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h($T[4]) ?>" title="Modülün içe aktarma ekranı"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$sat): ?><tr><td colspan="4" class="text-muted small">Dosya işlenmedi.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Ayrıntılı veri doğrulama raporu her modülün kendi içe aktarma ekranında da durur (sağdaki düğme).</div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" id="tyForm">
          <label class="ty-drop d-block mb-3" id="tyDrop" for="tyDosya">
            <i class="bi bi-cloud-arrow-up"></i>
            <div class="fw-semibold mt-1">Excel dosyalarını buraya sürükleyin ya da tıklayıp seçin</div>
            <div class="text-muted small">Birden çok .xlsx — sırası önemli değil</div>
          </label>
          <input type="file" name="dosya[]" id="tyDosya" class="d-none" accept=".xlsx" multiple>
          <ul class="list-unstyled ty-liste small mb-3" id="tyListe"></ul>
          <button type="submit" class="btn btn-primary" id="tyGonder"><i class="bi bi-lightning-charge-fill me-1"></i>Yükle ve Aktar</button>
          <span class="small text-muted ms-2" id="tyBilgi"></span>
        </form>
        <hr>
        <div class="small text-muted">
          <div class="fw-semibold text-body mb-1">Ne olur?</div>
          <ul class="mb-0 ps-3">
            <li><b>Beton Takip</b>: yeni irsaliyeler eklenir, faturadan açılmış taslaklar güncellenir; kayıtlı irsaliye <u>değiştirilmez</u> (fark varsa listelenir). Tam yenileme yapılmaz.</li>
            <li><b>Demirbaş / Malzeme / Sarf / Taşeron</b> (Depo): dosyadaki bölümler Excel'le <u>birebir yenilenir</u>; elle girilen günlük hareketler korunur.</li>
            <li><b>CRM arıza raporu</b> ve <b>Prekast çizelgesi</b> birleştirilir; <b>Demir sipariş talepleri</b> yenilenir.</li>
            <li>Demir sevkiyat/sipariş, akaryakıt ve seramik dosyaları tanınır ama önizleme gerektirdiği için kendi ekranına yönlendirilir.</li>
            <li>Her dosya ayrı işlenir: biri hatalıysa diğerleri yine aktarılır, hatalı dosyadan hiçbir değişiklik uygulanmaz.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <h6 class="fw-semibold mb-2"><i class="bi bi-calendar-check me-1"></i>Günlük dosyalar — son yükleme</h6>
        <div class="row g-2">
          <?php foreach (TY_GUNLUK as $t): $T = TY_TUR[$t]; if (!toplu_yukleme_izinli($T[0])) continue; $sy = $son[$t] ?? null;
                $sinif = $sy ? (substr($sy['created'], 0, 10) === $bugun ? 'bugun' : 'eski') : ''; ?>
            <div class="col-sm-6"><div class="ty-gun <?= $sinif ?>">
              <div class="small fw-semibold"><i class="bi <?= $T[2] ?> me-1"></i><?= h($T[1]) ?></div>
              <div class="small <?= $sinif === 'bugun' ? 'text-success' : 'text-muted' ?>">
                <?php if ($sy): ?><?= $sinif === 'bugun' ? '<i class="bi bi-check-circle-fill me-1"></i>Bugün ' . h(date('H:i', strtotime($sy['created']))) : h($tarihYaz($sy['created'])) ?>
                  <span class="text-muted" style="font-size:.72rem">· <?= h($sy['kullanici'] ?? '') ?><?= !empty($sy['kaynak']) ? ' · ' . h($sy['kaynak']) : '' ?></span>
                <?php else: ?>Henüz yüklenmedi<?php endif; ?>
              </div>
            </div></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h6 class="fw-semibold mb-2"><i class="bi bi-list-check me-1"></i>Tanınan dosya türleri</h6>
        <table class="table table-sm small mb-0">
          <?php foreach (TY_TUR as $t => $T): if ($t === 'depo' || !toplu_yukleme_izinli($T[0])) continue; ?>
            <tr><td><i class="bi <?= $T[2] ?> me-1"></i><?= h($T[1]) ?></td>
                <td class="text-end text-nowrap"><?= $T[3] ? '<span class="badge bg-success-subtle text-success-emphasis">otomatik</span>'
                    : '<a class="badge bg-info-subtle text-info-emphasis text-decoration-none" href="' . h($T[4]) . '">kendi ekranında</a>' ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mt-3">
  <div class="card-body">
    <h6 class="fw-semibold mb-2"><i class="bi bi-clock-history me-1"></i>Son yüklemeler</h6>
    <div class="table-responsive">
      <table class="table table-sm small align-middle mb-0">
        <thead class="table-light"><tr><th>Zaman</th><th>Kullanıcı</th><th>Dosya</th><th>Tür</th><th>Durum</th><th>Özet</th></tr></thead>
        <tbody>
        <?php foreach ($gecmis as $g): [$renk, $ik, $et] = $DURUM[$g['durum']] ?? $DURUM['hata']; ?>
          <tr><td class="text-nowrap"><?= h($tarihYaz($g['created'])) ?></td><td><?= h($g['kullanici'] ?? '') ?></td>
              <td class="text-break"><?= h($g['dosya']) ?></td><td><?= h($g['tur'] && isset(TY_TUR[$g['tur']]) ? TY_TUR[$g['tur']][1] : '—') ?></td>
              <td><span class="badge bg-<?= $renk ?>"><?= h($et) ?></span></td><td class="text-muted"><?= h(mb_strimwidth((string)$g['ozet'], 0, 180, '…')) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$gecmis): ?><tr><td colspan="6" class="text-muted">Henüz toplu yükleme yapılmadı.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('tyForm'), inp = document.getElementById('tyDosya'), drop = document.getElementById('tyDrop'),
      liste = document.getElementById('tyListe'), btn = document.getElementById('tyGonder'), bilgi = document.getElementById('tyBilgi');
  var dosyalar = [];
  btn.disabled = true;   // JS varken liste dolunca açılır (JS yoksa form klasik gönderilir)
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function boyut(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  function ciz() {
    liste.innerHTML = dosyalar.map(function (f, i) {
      return '<li class="d-flex align-items-center gap-2" id="tyF' + i + '"><i class="bi bi-file-earmark-excel text-success"></i>' +
        '<span class="flex-grow-1 text-break">' + esc(f.name) + ' <span class="text-muted">· ' + boyut(f.size) + '</span>' +
        '<span class="d-block ty-durum text-muted" style="font-size:.75rem"></span></span>' +
        '<button type="button" class="btn btn-sm btn-link text-danger p-0 ty-sil" data-i="' + i + '" title="Listeden çıkar"><i class="bi bi-x-lg"></i></button></li>';
    }).join('');
    btn.disabled = !dosyalar.length;
    bilgi.textContent = dosyalar.length ? dosyalar.length + ' dosya seçildi' : '';
  }
  function ekle(fl) {
    Array.prototype.forEach.call(fl, function (f) {
      if (!/\.xlsx$/i.test(f.name)) return;
      if (!dosyalar.some(function (d) { return d.name === f.name && d.size === f.size; })) dosyalar.push(f);
    });
    dosyalar.sort(function (a, b) { return a.name.localeCompare(b.name, 'tr'); });
    ciz();
  }
  inp.addEventListener('change', function () { ekle(inp.files); });
  ['dragenter', 'dragover'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.add('ustunde'); }); });
  ['dragleave', 'drop'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.remove('ustunde'); }); });
  drop.addEventListener('drop', function (ev) { if (ev.dataTransfer && ev.dataTransfer.files) ekle(ev.dataTransfer.files); });
  liste.addEventListener('click', function (ev) {
    var b = ev.target.closest('.ty-sil'); if (!b || btn.dataset.calisiyor) return;
    dosyalar.splice(+b.dataset.i, 1); ciz();
  });
  if (!window.fetch || !window.FormData) return;     // eski tarayıcı: form hepsini tek istekte yollar
  var csrf = (form.querySelector('input[name=csrf]') || {}).value || '';
  function post(fd) {
    if (csrf) fd.append('csrf', csrf);
    return fetch('toplu_yukleme.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!dosyalar.length || btn.dataset.calisiyor) return;
    btn.dataset.calisiyor = '1'; btn.disabled = true;
    liste.querySelectorAll('.ty-sil').forEach(function (b) { b.remove(); });
    var fd0 = new FormData(); fd0.append('islem', 'basla'); fd0.append('adet', dosyalar.length);
    var i = 0;
    function sonraki() {
      if (i >= dosyalar.length) { bilgi.textContent = 'Tamamlandı — rapor açılıyor…'; location.href = 'toplu_yukleme.php?rapor=1'; return; }
      var f = dosyalar[i], li = document.getElementById('tyF' + i), d = li.querySelector('.ty-durum');
      bilgi.textContent = (i + 1) + ' / ' + dosyalar.length + ' işleniyor…';
      d.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>tanınıyor ve aktarılıyor…';
      var fd = new FormData(); fd.append('islem', 'dosya'); fd.append('dosya', f, f.name);
      post(fd).then(function (j) {
        var renk = { ok: 'text-success', uyari: 'text-warning-emphasis', yonlendir: 'text-info-emphasis', yetki: 'text-secondary', hata: 'text-danger' }[j.durum] || 'text-muted';
        d.className = 'd-block ty-durum ' + renk;
        d.textContent = (j.etiket || '') + ' — ' + (j.ozet || '');
      }).catch(function (e) {
        d.className = 'd-block ty-durum text-danger'; d.textContent = 'Gönderilemedi: ' + e.message;
        var fh = new FormData(); fh.append('islem', 'kontrol_hata'); fh.append('ad', f.name); fh.append('sebep', e.message);
        return post(fh).catch(function () {});
      }).then(function () { i++; sonraki(); });
    }
    post(fd0).then(sonraki).catch(function (e) { bilgi.textContent = 'Başlatılamadı: ' + e.message; delete btn.dataset.calisiyor; btn.disabled = false; });
  });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
