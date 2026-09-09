<?php
/**
 * it/tanimlar.php — IT Envanter TANIMLAR ekranı (tek sayfa, sekmeli)
 *
 * Sekmeler: Lokasyonlar · Kategoriler · Üreticiler · Modeller · Tedarikçiler · Şirketler · Durumlar · Personel
 *  • Lokasyonlar  → `it_lokasyonlar` (hiyerarşik: proje → etap/birim; kod, şehir, adres, renk)
 *  • Üretici/Model/Tedarikçi/Şirket → `it_tanimlar` (tur bazlı). Cihaz kartındaki alanlar serbest METİN
 *    kalır; bu liste formdaki öneri (datalist) kaynağıdır — eski kayıtlar bozulmaz, "kullanım" sütunu
 *    tanımın kaç cihazda geçtiğini gösterir.
 *  • Kategoriler / Durumlar → sistem sabiti (IT_KATEGORI / IT_DURUM): eklenmez, sayımıyla listelenir.
 *  • Personel → kişi tanımları; tam ekran personel.php (buradan özet + hızlı erişim).
 * Yazma işlemleri `yetki_var('duzenle')` ister; silme, bağlı kayıt varsa engellenir (pasife alınır).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
it_semasi_kur($pdoIt);
it_tanim_semasi_kur($pdoIt);

const IT_RENK = ['' => 'Renksiz', 'success' => 'Yeşil', 'primary' => 'Mavi', 'warning' => 'Sarı', 'danger' => 'Kırmızı', 'info' => 'Turkuaz', 'dark' => 'Koyu', 'secondary' => 'Gri'];
const IT_LOK_TUR_AD = ['proje' => 'Proje / Şantiye', 'bina' => 'Bina / Ofis', 'birim' => 'Birim / Departman', 'depo' => 'Depo'];

$sekmeler = ['lokasyon' => ['Lokasyonlar', 'bi-geo-alt'], 'kategori' => ['Kategoriler', 'bi-grid'], 'uretici' => ['Üreticiler', 'bi-tags'],
             'model' => ['Modeller', 'bi-cpu'], 'tedarikci' => ['Tedarikçiler', 'bi-truck'], 'sirket' => ['Şirketler', 'bi-building'],
             'durum' => ['Durumlar', 'bi-toggles'], 'personel' => ['Personel', 'bi-people']];
$t = array_key_exists($_GET['t'] ?? '', $sekmeler) ? $_GET['t'] : 'lokasyon';
$duzenleId = isset($_GET['duzenle']) && ctype_digit((string)$_GET['duzenle']) ? (int)$_GET['duzenle'] : 0;
$yazabilir = yetki_var('duzenle');
$pageTitle = 'Tanımlar — IT Envanter';

// ───────────────────────── İŞLEMLER ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = $_POST['islem'] ?? '';
    $don = 'tanimlar.php?t=' . urlencode((string)($_POST['t'] ?? $t));
    if (!$yazabilir) { flash('error', 'Tanımları değiştirme yetkiniz yok.'); redirect($don); }
    $al = fn($k, $max = 150) => (mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null);

    try {
        if ($islem === 'lok_kaydet') {
            $id  = (int)($_POST['id'] ?? 0);
            $ad  = $al('ad', 120);
            if (!$ad) throw new RuntimeException('Lokasyon adı zorunludur.');
            $ust = (int)($_POST['ust_id'] ?? 0) ?: null;
            if ($ust && !isset(it_lokasyonlar($pdoIt)[$ust])) $ust = null;
            if ($id && $ust && in_array($ust, it_lokasyon_altlar($pdoIt, $id), true)) throw new RuntimeException('Bir lokasyon kendi altına taşınamaz.');
            $tur = array_key_exists($_POST['tur'] ?? '', IT_LOK_TUR_AD) ? $_POST['tur'] : 'birim';
            $rk = (string)($_POST['renk'] ?? '');
            $renk = array_key_exists($rk, IT_RENK) ? ($rk ?: null) : null;
            $v = ['ust_id'=>$ust, 'tur'=>$tur, 'kod'=>$al('kod', 20), 'ad'=>$ad, 'sehir'=>$al('sehir', 60), 'adres'=>$al('adres', 255),
                  'renk'=>$renk, 'sira'=>(int)($_POST['sira'] ?? 0), 'aktif'=>empty($_POST['pasif']) ? 1 : 0];
            // Aynı üst altında aynı ad iki kez olmasın (mükerrer tanım engeli)
            $st = $pdoIt->prepare("SELECT id, ad FROM it_lokasyonlar WHERE " . ($ust ? "ust_id=?" : "ust_id IS NULL") . " AND id<>?");
            $st->execute($ust ? [$ust, $id] : [$id]);
            foreach ($st->fetchAll() as $x) if (it_norm($x['ad']) === it_norm($ad)) throw new RuntimeException('Bu isimde bir lokasyon zaten var: ' . $x['ad']);
            if ($id) {
                $pdoIt->prepare("UPDATE it_lokasyonlar SET " . implode(', ', array_map(fn($k) => "$k=?", array_keys($v))) . " WHERE id=?")->execute([...array_values($v), $id]);
                it_lokasyonlar($pdoIt, true);
                // Cihazlardaki görünen lokasyon metni de tazelensin
                $pdoIt->prepare("UPDATE it_cihazlar SET lokasyon=? WHERE lokasyon_id=?")->execute([it_lokasyon_yol($pdoIt, $id), $id]);
                flash('success', 'Lokasyon güncellendi.');
            } else {
                $pdoIt->prepare("INSERT INTO it_lokasyonlar (" . implode(',', array_keys($v)) . ") VALUES (" . implode(',', array_fill(0, count($v), '?')) . ")")->execute(array_values($v));
                flash('success', 'Lokasyon eklendi.');
            }
            it_lokasyonlar($pdoIt, true);
        } elseif ($islem === 'lok_sil') {
            $id = (int)($_POST['id'] ?? 0);
            $cihaz = (int)$pdoIt->query("SELECT COUNT(*) FROM it_cihazlar WHERE lokasyon_id=" . $id)->fetchColumn();
            $kisi  = (int)$pdoIt->query("SELECT COUNT(*) FROM it_personel WHERE lokasyon_id=" . $id)->fetchColumn();
            $alt   = count(it_lokasyon_altlar($pdoIt, $id)) - 1;
            if ($cihaz || $kisi || $alt > 0) {
                $pdoIt->prepare("UPDATE it_lokasyonlar SET aktif=0 WHERE id=?")->execute([$id]);
                flash('warning', "Bağlı kayıt var (cihaz $cihaz · kişi $kisi · alt lokasyon $alt) — lokasyon silinmedi, PASİFE alındı.");
            } else {
                $pdoIt->prepare("DELETE FROM it_lokasyonlar WHERE id=?")->execute([$id]);
                flash('success', 'Lokasyon silindi.');
            }
            it_lokasyonlar($pdoIt, true);
        } elseif ($islem === 'lok_seed') {
            $n = it_lokasyon_seed($pdoIt);
            it_lokasyonlar($pdoIt, true);
            flash($n ? 'success' : 'warning', $n ? "Varsayılan yapıdan $n lokasyon eklendi." : 'Varsayılan lokasyonların hepsi zaten kayıtlı.');
        } elseif ($islem === 'tanim_kaydet') {
            $tur = array_key_exists($_POST['tur'] ?? '', IT_TANIM_TUR) ? $_POST['tur'] : null;
            if (!$tur) throw new RuntimeException('Geçersiz tanım türü.');
            $ad = $al('ad');
            if (!$ad) throw new RuntimeException('Ad zorunludur.');
            $id = (int)($_POST['id'] ?? 0);
            foreach (it_tanim_liste($pdoIt, $tur) as $x) if ((int)$x['id'] !== $id && it_norm($x['ad']) === it_norm($ad)) throw new RuntimeException('Bu tanım zaten var: ' . $x['ad']);
            $v = ['tur'=>$tur, 'ad'=>$ad, 'kod'=>$al('kod', 40), 'aciklama'=>$al('aciklama', 255),
                  'renk'=>(fn($rk) => array_key_exists($rk, IT_RENK) ? ($rk ?: null) : null)((string)($_POST['renk'] ?? '')),
                  'sira'=>(int)($_POST['sira'] ?? 0), 'aktif'=>empty($_POST['pasif']) ? 1 : 0];
            if ($id) {
                $pdoIt->prepare("UPDATE it_tanimlar SET " . implode(', ', array_map(fn($k) => "$k=?", array_keys($v))) . " WHERE id=?")->execute([...array_values($v), $id]);
                flash('success', 'Tanım güncellendi.');
            } else {
                $pdoIt->prepare("INSERT INTO it_tanimlar (" . implode(',', array_keys($v)) . ") VALUES (" . implode(',', array_fill(0, count($v), '?')) . ")")->execute(array_values($v));
                flash('success', 'Tanım eklendi.');
            }
        } elseif ($islem === 'tanim_sil') {
            $pdoIt->prepare("DELETE FROM it_tanimlar WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            flash('success', 'Tanım silindi. (Cihaz kayıtlarındaki metin değişmez.)');
        }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect($don);
}

// ───────────────────────── VERİ ─────────────────────────
$lokCihaz = []; $lokKisi = [];
try { foreach ($pdoIt->query("SELECT lokasyon_id, COUNT(*) n FROM it_cihazlar WHERE lokasyon_id IS NOT NULL AND durum<>'hurda' GROUP BY lokasyon_id") as $r) $lokCihaz[(int)$r['lokasyon_id']] = (int)$r['n']; } catch (Throwable $e) {}
try { foreach ($pdoIt->query("SELECT lokasyon_id, COUNT(*) n FROM it_personel WHERE lokasyon_id IS NOT NULL GROUP BY lokasyon_id") as $r) $lokKisi[(int)$r['lokasyon_id']] = (int)$r['n']; } catch (Throwable $e) {}
$lokAgac = it_lokasyon_duz($pdoIt, false);
// Alt lokasyonlar dahil toplam (proje satırında etapların cihazları da görünsün)
$topla = function (int $id, array $harita) use ($pdoIt) { $n = 0; foreach (it_lokasyon_altlar($pdoIt, $id) as $x) $n += $harita[$x] ?? 0; return $n; };

$duzenlenen = null;
if ($duzenleId) {
    if ($t === 'lokasyon') { $duzenlenen = it_lokasyonlar($pdoIt)[$duzenleId] ?? null; }
    elseif (isset(IT_TANIM_TUR[$t])) { $st = $pdoIt->prepare("SELECT * FROM it_tanimlar WHERE id=? AND tur=?"); $st->execute([$duzenleId, $t]); $duzenlenen = $st->fetch() ?: null; }
}

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$nokta = fn(?string $renk) => '<span class="d-inline-block rounded-circle align-middle me-2" style="width:9px;height:9px;background:var(--bs-' . ($renk ?: 'secondary') . ')"></span>';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-sliders text-primary me-2"></i>Tanımlar</h4>
    <div class="d-flex gap-2">
        <a href="cihazlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pc-display me-1"></i>Cihazlar</a>
        <a href="personel.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-people me-1"></i>Personel</a>
    </div>
</div>

<?php foreach (['success','error','warning'] as $ft): if ($m = get_flash($ft)): ?>
<div class="alert alert-<?= $ft === 'error' ? 'danger' : $ft ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="card border-0 shadow-sm mb-3"><div class="card-body py-3">
  <div class="text-uppercase text-muted small fw-semibold mb-2" style="letter-spacing:.06em">Tanım tablosu seç</div>
  <div class="d-flex flex-wrap gap-2">
  <?php foreach ($sekmeler as $k => [$ad, $ik]): ?>
    <a href="tanimlar.php?t=<?= $k ?>" class="btn btn-sm <?= $t === $k ? 'btn-primary' : 'btn-outline-secondary' ?>"><i class="bi <?= $ik ?> me-1"></i><?= h($ad) ?></a>
  <?php endforeach; ?>
  </div>
</div></div>

<?php if ($t === 'lokasyon'): $d = $duzenlenen; ?>
<?php if ($yazabilir): ?>
<form method="post" class="card border-0 shadow-sm mb-3">
  <input type="hidden" name="islem" value="lok_kaydet"><input type="hidden" name="t" value="lokasyon"><input type="hidden" name="id" value="<?= (int)$duzenleId ?>">
  <div class="card-body py-3">
    <div class="text-uppercase text-muted small fw-semibold mb-2" style="letter-spacing:.06em">Lokasyonlar — <?= $d ? 'DÜZENLE' : 'EKLE' ?></div>
    <div class="row g-2 align-items-end">
      <div class="col-md-3"><input name="ad" class="form-control" placeholder="Ad" required maxlength="120" value="<?= h($d['ad'] ?? '') ?>"></div>
      <div class="col-md-1"><input name="kod" class="form-control" placeholder="Proje Kodu" maxlength="20" value="<?= h($d['kod'] ?? '') ?>"></div>
      <div class="col-md-1"><input name="sehir" class="form-control" placeholder="Şehir" maxlength="60" value="<?= h($d['sehir'] ?? '') ?>"></div>
      <div class="col-md-2"><input name="adres" class="form-control" placeholder="Adres" maxlength="255" value="<?= h($d['adres'] ?? '') ?>"></div>
      <div class="col-md-2"><select name="ust_id" class="form-select"><option value="">Üst lokasyon seçilmedi</option><?= it_lokasyon_options($pdoIt, (int)($d['ust_id'] ?? 0), false) ?></select></div>
      <div class="col-md-1"><select name="tur" class="form-select"><?php foreach (IT_LOK_TUR_AD as $k => $ad): ?><option value="<?= $k ?>" <?= ($d['tur'] ?? 'birim') === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-1"><select name="renk" class="form-select"><?php foreach (IT_RENK as $k => $ad): ?><option value="<?= $k ?>" <?= ($d['renk'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-1 d-grid"><button class="btn btn-primary"><?= $d ? 'Kaydet' : 'Ekle' ?></button></div>
    </div>
    <?php if ($d): ?>
    <div class="mt-2 small">
      <label class="me-3"><input type="checkbox" name="pasif" value="1" <?= (int)($d['aktif'] ?? 1) ? '' : 'checked' ?>> Pasif (listelerde çıkmaz)</label>
      <label>Sıra <input type="number" name="sira" class="form-control form-control-sm d-inline-block" style="width:80px" value="<?= (int)($d['sira'] ?? 0) ?>"></label>
      <a href="tanimlar.php?t=lokasyon" class="ms-3">Vazgeç</a>
    </div>
    <?php endif; ?>
  </div>
</form>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
    <span class="text-uppercase text-muted small fw-semibold" style="letter-spacing:.06em">Lokasyonlar (<?= count($lokAgac) ?>)</span>
    <?php if ($yazabilir && !$lokAgac): ?>
    <form method="post" class="ms-auto"><input type="hidden" name="islem" value="lok_seed"><input type="hidden" name="t" value="lokasyon">
      <button class="btn btn-outline-primary btn-sm"><i class="bi bi-magic me-1"></i>Varsayılan yapıyı yükle</button></form>
    <?php endif; ?>
  </div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0" style="font-size:.88rem">
    <thead class="table-light"><tr><th>Ad</th><th>Proje Kodu</th><th>Şehir</th><th>Adres</th><th>Tür</th><th class="text-end">Cihaz</th><th class="text-end">Kişi</th><th></th></tr></thead>
    <tbody>
    <?php if (!$lokAgac): ?><tr><td colspan="8" class="text-center text-muted py-4">Lokasyon tanımı yok.</td></tr><?php endif; ?>
    <?php foreach ($lokAgac as $x): $r = $x['r']; $id = (int)$x['id']; $c = $topla($id, $lokCihaz); $k = $topla($id, $lokKisi); ?>
      <tr class="<?= (int)$r['aktif'] ? '' : 'text-muted' ?>">
        <td><span style="padding-left:<?= $x['d'] * 18 ?>px"><?= $x['d'] ? '<span class="text-muted">└</span> ' : '' ?><?= $nokta($r['renk'] ?? null) ?><strong><?= h($r['ad']) ?></strong><?= (int)$r['aktif'] ? '' : ' <span class="badge bg-secondary">pasif</span>' ?></span></td>
        <td class="font-monospace"><?= $r['kod'] ? h($r['kod']) : '<span class="text-muted">—</span>' ?></td>
        <td><?= $r['sehir'] ? h($r['sehir']) : '<span class="text-muted">—</span>' ?></td>
        <td class="small"><?= $r['adres'] ? h($r['adres']) : '<span class="text-muted">—</span>' ?></td>
        <td class="small text-muted"><?= h(IT_LOK_TUR_AD[$r['tur']] ?? $r['tur']) ?></td>
        <td class="text-end"><?= $c ? '<a href="cihazlar.php?lokasyon_id=' . $id . '">' . $f0($c) . ' cihaz</a>' : '<span class="text-muted">—</span>' ?></td>
        <td class="text-end"><?= $k ? '<a href="personel.php?lokasyon_id=' . $id . '" class="badge bg-success-subtle text-success-emphasis text-decoration-none">' . $f0($k) . ' kişi</a>' : '<span class="text-muted">—</span>' ?></td>
        <td class="text-end text-nowrap">
          <?php if ($yazabilir): ?>
            <a href="tanimlar.php?t=lokasyon&duzenle=<?= $id ?>" class="btn btn-link btn-sm p-0 me-2" title="Düzenle"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" onsubmit="return confirm('<?= h(addslashes($r['ad'])) ?> silinsin mi? Bağlı cihaz/kişi varsa silinmez, pasife alınır.')">
              <input type="hidden" name="islem" value="lok_sil"><input type="hidden" name="t" value="lokasyon"><input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn btn-link btn-sm p-0 text-danger" title="Sil"><i class="bi bi-trash"></i></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php elseif (isset(IT_TANIM_TUR[$t])): [$baslik, $kolon] = IT_TANIM_TUR[$t]; $liste = it_tanim_liste($pdoIt, $t); $kullanim = it_tanim_kullanim($pdoIt, $kolon); $d = $duzenlenen; ?>
<?php if ($yazabilir): ?>
<form method="post" class="card border-0 shadow-sm mb-3">
  <input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="t" value="<?= h($t) ?>"><input type="hidden" name="tur" value="<?= h($t) ?>"><input type="hidden" name="id" value="<?= (int)$duzenleId ?>">
  <div class="card-body py-3">
    <div class="text-uppercase text-muted small fw-semibold mb-2" style="letter-spacing:.06em"><?= h($baslik) ?> — <?= $d ? 'düzenle' : 'ekle' ?></div>
    <div class="row g-2 align-items-end">
      <div class="col-md-4"><input name="ad" class="form-control" placeholder="Ad" required maxlength="150" value="<?= h($d['ad'] ?? '') ?>"></div>
      <div class="col-md-2"><input name="kod" class="form-control" placeholder="Kod (isteğe bağlı)" maxlength="40" value="<?= h($d['kod'] ?? '') ?>"></div>
      <div class="col-md-4"><input name="aciklama" class="form-control" placeholder="Açıklama" maxlength="255" value="<?= h($d['aciklama'] ?? '') ?>"></div>
      <div class="col-md-1"><select name="renk" class="form-select"><?php foreach (IT_RENK as $k => $ad): ?><option value="<?= $k ?>" <?= ($d['renk'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-1 d-grid"><button class="btn btn-primary"><?= $d ? 'Kaydet' : 'Ekle' ?></button></div>
    </div>
    <?php if ($d): ?><div class="mt-2 small"><label class="me-3"><input type="checkbox" name="pasif" value="1" <?= (int)$d['aktif'] ? '' : 'checked' ?>> Pasif</label><a href="tanimlar.php?t=<?= h($t) ?>">Vazgeç</a></div><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white text-uppercase text-muted small fw-semibold" style="letter-spacing:.06em"><?= h($baslik) ?> (<?= count($liste) ?>)</div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0" style="font-size:.88rem">
    <thead class="table-light"><tr><th>Ad</th><th>Kod</th><th>Açıklama</th><th class="text-end">Kullanım</th><th></th></tr></thead>
    <tbody>
    <?php if (!$liste): ?><tr><td colspan="5" class="text-center text-muted py-4">Tanım yok. Cihaz kartlarında yazılan değerler zaten öneri olarak çıkar; buraya eklerseniz liste standartlaşır.</td></tr><?php endif; ?>
    <?php foreach ($liste as $r): $n = $kullanim[it_norm($r['ad'])] ?? 0; ?>
      <tr class="<?= (int)$r['aktif'] ? '' : 'text-muted' ?>">
        <td><?= $nokta($r['renk']) ?><strong><?= h($r['ad']) ?></strong><?= (int)$r['aktif'] ? '' : ' <span class="badge bg-secondary">pasif</span>' ?></td>
        <td class="font-monospace"><?= $r['kod'] ? h($r['kod']) : '<span class="text-muted">—</span>' ?></td>
        <td class="small"><?= $r['aciklama'] ? h($r['aciklama']) : '<span class="text-muted">—</span>' ?></td>
        <td class="text-end"><?= $n ? '<a href="cihazlar.php?q=' . urlencode($r['ad']) . '">' . $f0($n) . ' cihaz</a>' : '<span class="text-muted">—</span>' ?></td>
        <td class="text-end text-nowrap">
          <?php if ($yazabilir): ?>
            <a href="tanimlar.php?t=<?= h($t) ?>&duzenle=<?= (int)$r['id'] ?>" class="btn btn-link btn-sm p-0 me-2" title="Düzenle"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" onsubmit="return confirm('Tanım silinsin mi? Cihaz kayıtlarındaki yazı değişmez.')">
              <input type="hidden" name="islem" value="tanim_sil"><input type="hidden" name="t" value="<?= h($t) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-link btn-sm p-0 text-danger" title="Sil"><i class="bi bi-trash"></i></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Bu liste cihaz formundaki <strong><?= h($baslik) ?></strong> alanının öneri kaynağıdır. Cihaz kartındaki değer serbest metin olduğundan eski kayıtlar etkilenmez; "Kullanım" sütunu tanımın kaç cihazda geçtiğini gösterir.</div>

<?php elseif ($t === 'kategori' || $t === 'durum'): $sistem = $t === 'kategori' ? IT_KATEGORI : IT_DURUM; $kolon = $t === 'kategori' ? 'kategori' : 'durum';
      $say = []; try { foreach ($pdoIt->query("SELECT `$kolon` d, COUNT(*) n FROM it_cihazlar GROUP BY `$kolon`") as $r) $say[(string)$r['d']] = (int)$r['n']; } catch (Throwable $e) {} ?>
<div class="alert alert-secondary py-2 small"><i class="bi bi-lock me-1"></i><strong>Sistem tanımı:</strong> <?= $t === 'kategori' ? 'Kategoriler' : 'Durumlar' ?> uygulamanın çekirdeğine gömülüdür (ikon, renk ve iş kuralları buna bağlı) — eklenip silinmez, aşağıda kullanım sayılarıyla listelenir.</div>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0" style="font-size:.88rem">
  <thead class="table-light"><tr><th>Ad</th><th>Anahtar</th><th class="text-end">Cihaz</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($sistem as $k => $bilgi): $ad = $bilgi[0]; $n = $say[$k] ?? 0; ?>
    <tr>
      <td><i class="bi <?= h($t === 'kategori' ? ($bilgi[1] ?? 'bi-box') : ($bilgi[2] ?? 'bi-circle')) ?> me-2 text-<?= $t === 'durum' ? h($bilgi[1] ?? 'secondary') : 'primary' ?>"></i><strong><?= h($ad) ?></strong></td>
      <td class="font-monospace text-muted"><?= h($k) ?></td>
      <td class="text-end"><?= $n ? $f0($n) : '<span class="text-muted">—</span>' ?></td>
      <td class="text-end"><a href="cihazlar.php?<?= $kolon ?>=<?= urlencode($k) ?>" class="btn btn-link btn-sm p-0">Cihazları gör</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<?php else: /* personel */
  $pAktif = 0; $pAyrilan = 0; $pZimmet = 0; $son = [];
  try {
      foreach ($pdoIt->query("SELECT * FROM it_personel") as $r) { it_personel_aktif($r) ? $pAktif++ : $pAyrilan++; }
      $pZimmet = (int)$pdoIt->query("SELECT COUNT(DISTINCT personel_id) FROM it_cihazlar WHERE personel_id IS NOT NULL AND durum<>'hurda'")->fetchColumn();
      $son = $pdoIt->query("SELECT * FROM it_personel ORDER BY id DESC LIMIT 10")->fetchAll();
  } catch (Throwable $e) {}
?>
<div class="row g-2 mb-3">
  <?php foreach ([['Çalışan', $pAktif, 'text-success'], ['Ayrılan', $pAyrilan, 'text-muted'], ['Zimmeti olan kişi', $pZimmet, 'text-primary']] as [$et, $n, $cls]): ?>
  <div class="col-4"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted"><?= $et ?></div><div class="fs-5 fw-bold <?= $cls ?>"><?= $f0($n) ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
    <span class="text-uppercase text-muted small fw-semibold" style="letter-spacing:.06em">Personel</span>
    <div class="ms-auto d-flex gap-2">
      <a href="personel.php" class="btn btn-primary btn-sm"><i class="bi bi-people me-1"></i>Tam liste &amp; filtreler</a>
      <?php if (yetki_var('giris')): ?>
      <a href="personel_form.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Yeni Personel</a>
      <a href="import.php" class="btn btn-outline-success btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Excel'den İçe Aktar</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0" style="font-size:.88rem">
    <thead class="table-light"><tr><th>Sicil</th><th>Ad Soyad</th><th>Unvan</th><th>Birim</th><th>Lokasyon</th><th>Durum</th></tr></thead>
    <tbody>
    <?php if (!$son): ?><tr><td colspan="6" class="text-center text-muted py-4">Personel kaydı yok.</td></tr><?php endif; ?>
    <?php foreach ($son as $r): ?>
      <tr>
        <td class="font-monospace"><?= h($r['sicil_no']) ?></td>
        <td><a href="personel_detay.php?id=<?= (int)$r['id'] ?>"><?= h(it_personel_ad($r)) ?></a></td>
        <td><?= h($r['unvan']) ?></td><td><?= h($r['birim']) ?></td>
        <td class="small"><?= h(it_lokasyon_yol($pdoIt, (int)$r['lokasyon_id'])) ?></td>
        <td><?= it_personel_aktif($r) ? '<span class="badge bg-success-subtle text-success-emphasis">çalışıyor</span>' : '<span class="badge bg-secondary">ayrıldı</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card-footer bg-white small text-muted">Son eklenen 10 kişi gösteriliyor — arama, filtre, Excel ve zimmet işlemleri için <a href="personel.php">tam listeye</a> geçin.</div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
