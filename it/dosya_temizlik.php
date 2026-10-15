<?php
/**
 * it/dosya_temizlik.php — SAHİPSİZ DOSYA TEMİZLİĞİ
 * Sunucudaki IT dosyalarından hiçbir kayda bağlı olmayanları ve kapladıkları alanı listeler, onayla siler.
 * Ayrıca kaydı olup dosyası diskte olmayan ("kırık") belgeleri gösterir. Çekirdek: _temizlik.php (itt_*).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/_temizlik.php';

it_semasi_kur($pdoIt);
it_ek_alan_semasi_kur($pdoIt);
$pageTitle = 'Dosya Temizliği — IT Envanter';
$silebilir = yetki_var('duzenle');

// ── Silme (PRG: sonuç oturuma yazılır, sayfa kendine döner) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['islem'] ?? '', ['sec_sil', 'hepsini_sil'], true)) {
    if (!$silebilir) { flash('error', 'Dosya silmek için değiştirme yetkisi gerekir.'); redirect('dosya_temizlik.php'); }
    // "Tümünü sil" listeyi formdan TAŞIMAZ, sunucuda yeniden tarar — binlerce dosyada
    // max_input_vars sınırına takılmasın ve ekran açıkken değişen durumu yakalasın.
    $urller = ($_POST['islem'] === 'hepsini_sil')
        ? array_column(itt_tara($pdoIt)['sahipsiz'], 'url')
        : array_map('strval', (array)($_POST['dosya'] ?? []));
    if (!$urller) {
        flash('warning', 'Silinecek dosya seçilmedi.');
    } else {
        $s = itt_sil($pdoIt, $urller);
        $_SESSION['itt_sonuc'] = ['silinen'=>count($s['silinen']), 'boyut'=>$s['boyut'], 'bosalan'=>$s['bosalan'],
                                  'hata'=>array_slice($s['hata'], 0, 200, true), 'hata_say'=>count($s['hata'])];
        audit_log($pdoIt, 'it_belgeler', 0, 'DELETE', null,
                  ['dosya_temizligi' => count($s['silinen']), 'acilan_alan' => $s['boyut'], 'hata' => count($s['hata']),
                   'dosyalar' => array_slice(array_keys($s['silinen']), 0, 100)], current_user_id());
    }
    redirect('dosya_temizlik.php');
}
$sonuc = $_SESSION['itt_sonuc'] ?? null;
unset($_SESSION['itt_sonuc']);

$tara = itt_tara($pdoIt);
$o = $tara['ozet'];
$GOSTER = 500;      // ekranda en fazla bu kadar satır; "Tümünü sil" hepsini kapsar

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-trash3 text-primary me-2"></i>Dosya Temizliği</h4>
  <span class="text-muted small">sunucudaki IT dosyaları · kayda bağlı olmayanlar</span>
  <a href="dosya_temizlik.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-arrow-clockwise me-1"></i>Yeniden tara</a>
</div>

<?php if ($sonuc): ?>
<div class="alert <?= $sonuc['hata_say'] ? 'alert-warning' : 'alert-success' ?>">
  <div class="fw-semibold"><i class="bi bi-check2-circle me-1"></i><?= (int)$sonuc['silinen'] ?> dosya silindi —
    <?= h(itt_boyut((int)$sonuc['boyut'])) ?> alan açıldı<?= $sonuc['bosalan'] ? ' · ' . (int)$sonuc['bosalan'] . ' boş klasör kaldırıldı' : '' ?>.</div>
  <?php if ($sonuc['hata_say']): ?>
    <div class="mt-1"><strong><?= (int)$sonuc['hata_say'] ?> dosya silinmedi:</strong></div>
    <ul class="small mb-0 mt-1">
      <?php foreach ($sonuc['hata'] as $u => $neden): ?>
        <li><code><?= h($u) ?></code> — <?= h($neden) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (array_filter($sonuc['hata'], fn($n) => str_contains($n, 'izin'))): ?>
      <div class="small mt-2">⚠ "Yazma izni yok" çıkan dosyalar web sunucusunun silme yetkisi olmadığını gösterir —
        aaPanel'den <code>uploads/it_envanter</code> klasörünün sahibi/izinleri web kullanıcısına (www) verilmelidir.
        Bu durumda cihaz kartından silinen belgelerin dosyaları da diskte kalıyordu.</div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="alert alert-light border small">
  <i class="bi bi-info-circle me-1"></i>
  Belgeyi cihaz kartından sildiğinizde dosyası da sunucudan silinir. Burada listelenenler <strong>hiçbir kayda bağlı
  olmayan</strong> artık dosyalardır: sunucu izni yüzünden silinemeyenler, yarıda kalan yüklemeler, eski sürümlerden
  kalanlar ve tahsis formu aktarımının geçici dosyaları. <strong>Bir kayda bağlı hiçbir dosya burada çıkmaz ve silinemez.</strong>
  Son <?= (int)(IT_TEMIZLIK_YENI_SN / 3600) ?> saatte yüklenen dosyalar, yükleme sürüyor olabileceği için korunur.
</div>

<div class="row g-3 mb-3">
  <?php foreach ([
      ['Toplam dosya',      $o['dosya'],      itt_boyut($o['boyut']),            'bi-folder2-open', 'secondary'],
      ['Kayda bağlı',       $o['kullanilan'], itt_boyut($o['kullanilan_boyut']), 'bi-link-45deg',   'success'],
      ['Sahipsiz (silinebilir)', $o['sahipsiz'], itt_boyut($o['sahipsiz_boyut']), 'bi-trash3',   $o['sahipsiz'] ? 'danger' : 'success'],
      ['Yeni — korunuyor',  $o['korunan'],    itt_boyut($o['korunan_boyut']),    'bi-hourglass-split', 'info'],
      ['Kırık bağlantı',    $o['kirik'],      'kaydı var, dosyası yok',           'bi-link-45deg',  $o['kirik'] ? 'warning' : 'success'],
    ] as [$et, $say, $alt, $ik, $renk]): ?>
  <div class="col-6 col-lg">
    <div class="card h-100"><div class="card-body py-2">
      <div class="small text-muted"><i class="bi <?= $ik ?> me-1 text-<?= $renk ?>"></i><?= h($et) ?></div>
      <div class="fs-4 fw-bold"><?= number_format((int)$say, 0, ',', '.') ?></div>
      <div class="small text-muted"><?= h($alt) ?></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <strong><i class="bi bi-trash3 me-1"></i>Sahipsiz dosyalar</strong>
    <span class="badge bg-light text-dark border"><?= (int)$o['sahipsiz'] ?> dosya · <?= h(itt_boyut($o['sahipsiz_boyut'])) ?></span>
    <span class="small text-muted">büyükten küçüğe</span>
  </div>
  <?php if (!$tara['sahipsiz']): ?>
    <div class="card-body text-success"><i class="bi bi-check-circle me-1"></i>Sahipsiz dosya yok — sunucudaki her IT dosyası bir kayda bağlı.</div>
  <?php else: ?>
  <form method="post" id="temizlikForm">
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light"><tr>
        <?php if ($silebilir): ?><th style="width:32px"><input type="checkbox" class="form-check-input" id="hepsiSec" title="Görünenlerin hepsini seç"></th><?php endif; ?>
        <th>Dosya</th><th class="text-end">Boyut</th><th>Tarih</th><th>Klasör / cihaz</th><th>Neden sahipsiz</th>
      </tr></thead>
      <tbody>
      <?php foreach (array_slice($tara['sahipsiz'], 0, $GOSTER) as $r): ?>
        <tr>
          <?php if ($silebilir): ?><td><input type="checkbox" class="form-check-input sec" name="dosya[]" value="<?= h($r['url']) ?>" data-boyut="<?= (int)$r['boyut'] ?>"></td><?php endif; ?>
          <td class="small"><a href="../<?= h($r['url']) ?>" target="_blank" rel="noopener" title="Silmeden önce açıp bakın"><?= h(basename($r['url'])) ?></a></td>
          <td class="text-end small text-nowrap"><?= h(itt_boyut($r['boyut'])) ?></td>
          <td class="small text-nowrap"><?= date('d.m.Y H:i', $r['zaman']) ?></td>
          <td class="small">
            <?php if ($r['kok'] === 'bekleyen'): ?><span class="text-muted">form aktarımı (geçici)</span>
            <?php elseif ($r['cihaz_var']): ?><a href="cihaz_detay.php?id=<?= (int)$r['cihaz_id'] ?>">cihaz #<?= (int)$r['cihaz_id'] ?></a>
            <?php else: ?><span class="text-muted">#<?= (int)$r['cihaz_id'] ?> (kayıt yok)</span><?php endif; ?>
          </td>
          <td class="small"><?= h($r['neden']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if (count($tara['sahipsiz']) > $GOSTER): ?>
      <div class="small text-muted px-3 py-2">İlk <?= $GOSTER ?> dosya gösteriliyor (toplam <?= count($tara['sahipsiz']) ?>). "Tümünü sil" hepsini kapsar.</div>
    <?php endif; ?>
    <?php if ($silebilir): ?>
    <div class="card-footer d-flex flex-wrap align-items-center gap-2">
      <button type="submit" class="btn btn-outline-danger btn-sm" id="secSilBtn" name="islem" value="sec_sil" disabled
              onclick="return confirm('Seçilen dosyalar sunucudan KALICI olarak silinecek. Devam edilsin mi?');">
        <i class="bi bi-trash3 me-1"></i>Seçilenleri sil <span id="secBilgi"></span></button>
      <button type="submit" class="btn btn-danger btn-sm" name="islem" value="hepsini_sil"
              onclick="return confirm('Kayda bağlı OLMAYAN <?= (int)$o['sahipsiz'] ?> dosya (<?= h(itt_boyut($o['sahipsiz_boyut'])) ?>) sunucudan KALICI olarak silinecek. Kayda bağlı dosyalara dokunulmaz. Devam edilsin mi?');">
        <i class="bi bi-trash3-fill me-1"></i>Tümünü sil (<?= (int)$o['sahipsiz'] ?> dosya · <?= h(itt_boyut($o['sahipsiz_boyut'])) ?>)</button>
      <span class="small text-muted">Silme geri alınamaz; her dosya silinmeden hemen önce yeniden kontrol edilir.</span>
    </div>
    <?php else: ?>
    <div class="card-footer small text-muted">Silmek için değiştirme yetkisi gerekir.</div>
    <?php endif; ?>
  </form>
  <?php endif; ?>
</div>

<?php if ($tara['kirik']): ?>
<div class="card mb-3 border-warning">
  <div class="card-header"><strong><i class="bi bi-exclamation-triangle text-warning me-1"></i>Kırık bağlantılar</strong>
    <span class="small text-muted">— belge kartta görünüyor ama dosyası sunucuda yok (açılmaz). Yer kaplamaz; cihaz kartından silinebilir.</span></div>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Belge</th><th>Tür</th><th>Cihaz</th><th>Kayıtlı yol</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($tara['kirik'], 0, $GOSTER) as $r): ?>
      <tr>
        <td class="small"><?= h($r['ad'] ?: basename($r['url'])) ?></td>
        <td class="small"><?= h(IT_BELGE_TUR[$r['tur'] ?? 'belge'][0] ?? ($r['tur'] ?? '')) ?></td>
        <td class="small"><?php if ($r['envanter_no'] !== null): ?><a href="cihaz_detay.php?id=<?= (int)$r['cihaz_id'] ?>"><?= h(($r['cihaz_kodu'] ?: $r['envanter_no']) . ' — ' . $r['cihaz_ad']) ?></a>
          <?php else: ?><span class="text-muted">#<?= (int)$r['cihaz_id'] ?> (kayıt yok)</span><?php endif; ?></td>
        <td class="small"><code><?= h($r['url']) ?></code></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($tara['korunan']): ?>
<details class="mb-3">
  <summary class="small text-muted">Yeni olduğu için korunan <?= count($tara['korunan']) ?> dosya (<?= h(itt_boyut($o['korunan_boyut'])) ?>)</summary>
  <ul class="small mt-2">
    <?php foreach (array_slice($tara['korunan'], 0, 100) as $r): ?>
      <li><code><?= h($r['url']) ?></code> · <?= h(itt_boyut($r['boyut'])) ?> · <?= date('d.m.Y H:i', $r['zaman']) ?> — <?= h($r['neden']) ?></li>
    <?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>

<script>
(function () {
  var f = document.getElementById('temizlikForm'); if (!f) return;
  var hepsi = document.getElementById('hepsiSec'), btn = document.getElementById('secSilBtn'), bilgi = document.getElementById('secBilgi');
  function boyut(b) { var u = ['B','KB','MB','GB']; var i = 0; while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i ? b.toFixed(b < 10 ? 1 : 0).replace('.', ',') : b) + ' ' + u[i]; }
  function guncelle() {
    var s = f.querySelectorAll('.sec:checked'), t = 0;
    for (var i = 0; i < s.length; i++) t += parseInt(s[i].dataset.boyut, 10) || 0;
    if (btn) btn.disabled = !s.length;
    if (bilgi) bilgi.textContent = s.length ? '(' + s.length + ' dosya · ' + boyut(t) + ')' : '';
  }
  if (hepsi) hepsi.addEventListener('change', function () {
    var k = f.querySelectorAll('.sec'); for (var i = 0; i < k.length; i++) k[i].checked = hepsi.checked; guncelle();
  });
  f.addEventListener('change', function (e) { if (e.target.classList.contains('sec')) guncelle(); });
  guncelle();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
