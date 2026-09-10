<?php
/**
 * it/snipe_cek.php — SNIPE-IT'DEN BELGE & FOTOĞRAF ÇEKME
 *
 * Snipe-IT'nin Excel çıktısında görsel/belge yoktur; bu ekran REST API ile bağlanıp
 * cihaz FOTOĞRAFLARINI ve varlığa yüklenmiş DOSYALARI (imzalı zimmet tutanağı, fatura,
 * garanti belgesi) indirir ve envanterimizdeki cihaz kartına ekler.
 *
 * 3 adım: (1) bağlantı + eşleşme önizlemesi → (2) parti parti indirme → (3) rapor.
 * Uzun listede zaman aşımına düşmemek için cihazlar PARTİ PARTİ işlenir; sayfa kendini
 * bir sonraki parti için yeniler, sonuçlar oturumda birikir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_snipe.php';

it_semasi_kur($pdoIt);
it_ek_alan_semasi_kur($pdoIt);          // snipe_id kolonu
$pageTitle  = 'Snipe-IT Belge Çekme — IT Envanter';
$yazabilir  = yetki_var('giris');
const SN_PARTI = 15;                     // bir istekte işlenecek cihaz sayısı

[$snUrl, $snToken, $configden] = sn_ayar();
$hata = null; $mesaj = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $yazabilir) {
    $islem = $_POST['islem'] ?? '';

    if ($islem === 'iptal') { unset($_SESSION['it_snipe']); redirect('snipe_cek.php'); }

    if ($islem === 'baglan') {
        // ⚠ Token oturumda tutulur, DB'ye/diske yazılmaz
        if (!$configden) {
            $_SESSION['it_snipe'] = ['url' => trim((string)($_POST['url'] ?? '')), 'token' => trim((string)($_POST['token'] ?? ''))];
        }
        [$ok, $toplam, $h] = sn_test();
        if (!$ok) { $hata = $h; }
        else {
            $liste = sn_cihazlar();
            [$eslesen, $yok] = sn_eslestir($pdoIt, $liste);
            $_SESSION['it_snipe']['toplam']  = $toplam;
            $_SESSION['it_snipe']['eslesen'] = $eslesen;
            $_SESSION['it_snipe']['yok']     = array_slice($yok, 0, 200);
            $_SESSION['it_snipe']['yok_adet']= count($yok);
            $_SESSION['it_snipe']['sira']    = array_keys($eslesen);
            $_SESSION['it_snipe']['imlec']   = 0;
            $_SESSION['it_snipe']['sonuc']   = ['foto'=>0,'belge'=>0,'atlanan'=>0,'hata'=>[],'satir'=>[]];
            unset($_SESSION['it_snipe']['bitti']);
            $mesaj = 'Bağlantı kuruldu: Snipe-IT\'de ' . $toplam . ' varlık var, ' . count($eslesen) . ' tanesi envanterimizle eşleşti.';
        }
    }

    if ($islem === 'cek' && !empty($_SESSION['it_snipe']['sira'])) {
        $s      = &$_SESSION['it_snipe'];
        $imlec  = (int)($s['imlec'] ?? 0);
        $parca  = array_slice($s['sira'], $imlec, SN_PARTI);
        $opt    = ['kullanici' => ($_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null),
                   'foto'  => !empty($_POST['foto']) || !empty($s['opt']['foto']),
                   'belge' => !empty($_POST['belge']) || !empty($s['opt']['belge'])];
        if (!isset($s['opt'])) $s['opt'] = ['foto' => $opt['foto'], 'belge' => $opt['belge']];
        if ($parca) {
            $p = sn_parti_isle($pdoIt, $s['eslesen'], $parca, $opt);
            $s['sonuc']['foto']    += $p['foto'];
            $s['sonuc']['belge']   += $p['belge'];
            $s['sonuc']['atlanan'] += $p['atlanan'];
            $s['sonuc']['hata']     = array_slice(array_merge($s['sonuc']['hata'], $p['hata']), 0, 200);
            $s['sonuc']['satir']    = array_merge($s['sonuc']['satir'], $p['satir']);
            $s['imlec'] = $imlec + count($parca);
        }
        if ($s['imlec'] >= count($s['sira'])) $s['bitti'] = 1;
        unset($s);
        redirect('snipe_cek.php');
    }
}

$s       = $_SESSION['it_snipe'] ?? null;
$hazir   = $s && !empty($s['sira']);
$imlec   = (int)($s['imlec'] ?? 0);
$toplamC = $hazir ? count($s['sira']) : 0;
$devam   = $hazir && $imlec < $toplamC && !empty($s['opt']);        // yarım kalmış çekim
$f0      = fn($n) => number_format((float)$n, 0, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-cloud-arrow-down text-primary me-2"></i>Snipe-IT'den Belge &amp; Fotoğraf Çek</h4>
  <div class="d-flex gap-2">
    <a href="cihazlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul me-1"></i>Cihazlar</a>
    <a href="cihaz_import.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-in-down me-1"></i>Cihaz İçe Aktar</a>
  </div>
</div>

<?php if ($hata): ?><div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i><?= h($hata) ?></div><?php endif; ?>
<?php if ($mesaj): ?><div class="alert alert-success py-2"><i class="bi bi-check2-circle me-1"></i><?= h($mesaj) ?></div><?php endif; ?>
<?php if (!$yazabilir): ?><div class="alert alert-warning py-2">Bu ekranda çalışmak için <strong>veri girişi</strong> yetkisi gerekir.</div><?php endif; ?>

<?php if (!$hazir): ?>
<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" class="card border-0 shadow-sm h-100">
      <input type="hidden" name="islem" value="baglan">
      <div class="card-body">
        <h6 class="fw-semibold"><i class="bi bi-plug me-1"></i>1. Snipe-IT bağlantısı</h6>
        <?php if ($configden): ?>
          <div class="alert alert-success py-2 small mb-3">
            Adres ve API anahtarı <code>config.php</code>'den okunuyor: <strong><?= h($snUrl) ?></strong>
          </div>
        <?php else: ?>
          <div class="mb-2">
            <label class="form-label small mb-1">Snipe-IT adresi</label>
            <input name="url" class="form-control" placeholder="https://envanter.sirketiniz.com" value="<?= h($snUrl) ?>" required>
            <div class="form-text">Sonunda <code>/api/v1</code> olmadan, yalnız kök adres.</div>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">API anahtarı (token)</label>
            <input name="token" type="password" class="form-control font-monospace" placeholder="eyJ0eXAiOiJKV1Qi…" required autocomplete="off">
            <div class="form-text">
              Snipe-IT'de <strong>sağ üst kullanıcı menüsü → Manage API Keys → Create New Token</strong> ile üretilir.
              Anahtar yalnız bu oturumda tutulur, veritabanına yazılmaz. Kalıcı olsun isterseniz
              <code>config.php</code>'ye <code>define('SNIPE_URL', '…'); define('SNIPE_TOKEN', '…');</code> ekleyin.
            </div>
          </div>
        <?php endif; ?>
        <button class="btn btn-primary" <?= $yazabilir ? '' : 'disabled' ?>><i class="bi bi-arrow-repeat me-1"></i>Bağlan ve eşleşmeyi göster</button>
      </div>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100"><div class="card-body small">
      <h6 class="fw-semibold"><i class="bi bi-info-circle me-1"></i>Ne yapar?</h6>
      <p class="text-muted mb-2">Snipe-IT'nin Excel çıktısında <strong>görsel ve belge yoktur</strong> — dosyalar sunucunun
        <code>uploads/assets</code> ve <code>private_uploads/assets</code> klasörlerinde durur. Bu ekran API ile bağlanıp
        ikisini de çeker:</p>
      <ul class="mb-2">
        <li><strong>Cihaz fotoğrafı</strong> → cihaz kartına belge olarak eklenir, listedeki küçük resim olur.</li>
        <li><strong>Varlığa yüklenmiş dosyalar</strong> (imzalı zimmet tutanağı, fatura, garanti) → cihaz kartındaki
            Belgeler bölümüne iner. Adında <em>zimmet / tutanak / teslim</em> geçen dosya <strong>imzalı tutanak</strong>
            olarak işaretlenir (listede yeşil rozet).</li>
      </ul>
      <p class="text-muted mb-0">Eşleşme <strong>cihaz kodu (asset tag) → IFS nesne no → seri no → envanter no</strong>
        sırasıyla yapılır; eşleşen cihazın Snipe id'si saklanır, sonraki çekimler birebir olur.
        <strong>Aynı dosya iki kez eklenmez</strong> (içerik karşılaştırılır), istediğiniz kadar tekrar çalıştırabilirsiniz.</p>
    </div></div>
  </div>
</div>

<?php else: $bitti = !empty($s['bitti']); ?>
<div class="row g-2 mb-3">
  <?php foreach ([['Snipe-IT varlığı', $f0($s['toplam'] ?? 0), ''],
                  ['Eşleşen cihaz', $f0($toplamC), 'text-success'],
                  ['Eşleşmeyen', $f0($s['yok_adet'] ?? 0), ($s['yok_adet'] ?? 0) ? 'text-warning' : ''],
                  ['İnen fotoğraf', $f0($s['sonuc']['foto'] ?? 0), 'text-primary'],
                  ['İnen belge', $f0($s['sonuc']['belge'] ?? 0), 'text-primary'],
                  ['Zaten vardı', $f0($s['sonuc']['atlanan'] ?? 0), 'text-muted']] as [$et, $dg, $cls]): ?>
  <div class="col-6 col-md-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
    <div class="small text-muted"><?= $et ?></div><div class="fs-5 fw-bold <?= $cls ?>"><?= $dg ?></div>
  </div></div></div>
  <?php endforeach; ?>
</div>

<?php if (!$bitti && !$devam): ?>
<form method="post" class="card border-0 shadow-sm mb-3">
  <input type="hidden" name="islem" value="cek">
  <div class="card-body">
    <h6 class="fw-semibold">2. Ne indirilsin?</h6>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="foto" id="foto" value="1" checked>
      <label class="form-check-label" for="foto">Cihaz fotoğrafları</label></div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="belge" id="belge" value="1" checked>
      <label class="form-check-label" for="belge">Varlığa yüklenmiş dosyalar (zimmet tutanağı, fatura, garanti…)</label></div>
    <div class="alert alert-info py-2 small">
      <?= $f0($toplamC) ?> cihaz <?= SN_PARTI ?>'erli partiler hâlinde işlenecek; sayfa kendini yenileyerek ilerler,
      <strong>tarayıcıyı kapatmayın</strong>. Yarıda kalırsa kaldığı yerden devam eder.
    </div>
    <button class="btn btn-success" <?= $yazabilir ? '' : 'disabled' ?>><i class="bi bi-cloud-arrow-down me-1"></i>Belgeleri çek</button>
    <button class="btn btn-outline-secondary ms-1" name="islem" value="iptal" formnovalidate>Vazgeç</button>
  </div>
</form>
<?php elseif (!$bitti): $yuzde = $toplamC ? round($imlec * 100 / $toplamC) : 100; ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
  <div class="d-flex justify-content-between small mb-1">
    <span><i class="bi bi-hourglass-split me-1"></i>İndiriliyor… <?= $f0($imlec) ?> / <?= $f0($toplamC) ?> cihaz</span>
    <span><?= $yuzde ?>%</span>
  </div>
  <div class="progress" style="height:10px"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:<?= $yuzde ?>%"></div></div>
  <form method="post" id="devamForm" class="mt-3">
    <input type="hidden" name="islem" value="cek">
    <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-right me-1"></i>Sonraki parti</button>
    <button class="btn btn-outline-secondary btn-sm ms-1" name="islem" value="iptal">Durdur</button>
    <span class="small text-muted ms-2">Sayfa kendiliğinden ilerler.</span>
  </form>
</div></div>
<script>setTimeout(function () { document.getElementById('devamForm').submit(); }, 400);</script>
<?php else: ?>
<div class="alert alert-success py-2"><i class="bi bi-check2-circle me-1"></i><strong>Tamamlandı.</strong>
  <?= $f0($s['sonuc']['foto']) ?> fotoğraf, <?= $f0($s['sonuc']['belge']) ?> belge indirildi;
  <?= $f0($s['sonuc']['atlanan']) ?> dosya zaten sistemdeydi (tekrar eklenmedi).
  <form method="post" class="d-inline ms-2"><button class="btn btn-sm btn-outline-secondary" name="islem" value="iptal">Yeni çekim</button></form>
</div>
<?php endif; ?>

<?php if (!empty($s['sonuc']['satir'])): ?>
<details class="card border-0 shadow-sm mb-3" open>
  <summary class="card-header bg-white fw-semibold small">Belge inen cihazlar (<?= count($s['sonuc']['satir']) ?>)</summary>
  <div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.83rem">
    <thead class="table-light"><tr><th>Cihaz</th><th class="text-end">Fotoğraf</th><th class="text-end">Belge</th><th class="text-end">Zaten vardı</th></tr></thead>
    <tbody><?php foreach (array_reverse($s['sonuc']['satir']) as $x): ?>
      <tr><td><?= h($x['kim']) ?></td><td class="text-end"><?= $x['foto'] ?: '—' ?></td>
          <td class="text-end"><?= $x['belge'] ?: '—' ?></td><td class="text-end text-muted"><?= $x['atlanan'] ?: '—' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</details>
<?php endif; ?>

<?php if (!empty($s['sonuc']['hata'])): ?>
<details class="card border-0 shadow-sm mb-3">
  <summary class="card-header bg-white fw-semibold small text-danger">Sorunlar (<?= count($s['sonuc']['hata']) ?>)</summary>
  <div class="card-body small"><?php foreach ($s['sonuc']['hata'] as $x): ?><div><?= h($x) ?></div><?php endforeach; ?></div>
</details>
<?php endif; ?>

<?php if (!empty($s['yok'])): ?>
<details class="card border-0 shadow-sm">
  <summary class="card-header bg-white fw-semibold small text-warning-emphasis">Envanterimizde karşılığı bulunamayan Snipe-IT varlıkları (<?= $f0($s['yok_adet']) ?>)</summary>
  <div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.83rem">
    <thead class="table-light"><tr><th>Snipe ID</th><th>Demirbaş etiketi</th><th>Seri No</th><th>Ad / Model</th></tr></thead>
    <tbody><?php foreach ($s['yok'] as $x): ?>
      <tr><td class="font-monospace"><?= (int)$x['id'] ?></td><td class="font-monospace"><?= h($x['etiket'] ?: '—') ?></td>
          <td class="font-monospace"><?= h($x['seri'] ?: '—') ?></td><td><?= h($x['ad'] ?: '—') ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <div class="card-footer bg-white small text-muted">Bu varlıklar önce <a href="cihaz_import.php">Cihaz İçe Aktar</a> ile
    envantere alınmalı; sonra bu ekranı tekrar çalıştırın.</div>
</details>
<?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
