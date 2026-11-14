<?php
/**
 * kamp/periyodik.php — PERİYODİK DEĞİŞİM (nevresim, yorgan, yastık, şilte…)
 *
 *  Sekmeler: ?s=durum (varsayılan) · gecmis · tanim
 *   durum  — seçili değişim türü için her yatağın (ya da odanın) son değişimi, sonraki tarihi ve durumu;
 *            işaretlenen yataklara TOPLU "değişim yapıldı" kaydı ("bugün A blokta tüm nevresimler değişti").
 *   gecmis — yapılan değişimler defteri (tarih aralığı, tür, bina, arama; yanlış kayıt silinir; Excel).
 *   tanim  — değişim türleri: kaç günde bir, kaç gün kala uyarı, yeni gelen kişiye de yenilensin mi.
 *  POST: kaydet (giriş VEYA değiştirme — krokiden ve oda kartından da gelir) · kayit_sil · tanim_kaydet · tanim_sil (değiştirme)
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$yaz = yetki_var('giris') || yetki_var('duzenle');
$duz = yetki_var('duzenle');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $geri = kamp_geri($_POST['geri'] ?? '', 'periyodik.php');
    try {
        switch ($_POST['islem'] ?? '') {
            case 'kaydet':
                if (!$yaz) throw new RuntimeException('Değişim kaydı için yetkiniz yok.');
                $pid = (int)($_POST['periyot_id'] ?? 0);
                [$e, $a] = kamp_periyot_kaydet($pdoKamp, $pid, (array)($_POST['hedef'] ?? []), (string)($_POST['tarih'] ?? ''),
                                               (string)($_POST['yapan'] ?? ''), (string)($_POST['aciklama'] ?? ''), current_user_id());
                $per = kamp_periyotlar($pdoKamp, true)[$pid];
                kamp_audit('kamp_periyot_kayit', $pid, 'INSERT', null, ['tur' => $per['ad'], 'tarih' => $_POST['tarih'] ?? '', 'adet' => $e,
                                                                       'hedef' => array_map('intval', (array)($_POST['hedef'] ?? []))]);
                flash($e ? 'success' : 'warning', $per['ad'] . ': ' . $e . ' ' . ($per['kapsam'] === 'oda' ? 'oda' : 'yatak') . ' için ' . kamp_tarih_yaz(kamp_tarih($_POST['tarih'])) . ' tarihli değişim kaydedildi'
                      . ($a ? " · $a tanesi o tarihte zaten kayıtlıydı, atlandı" : '') . '.');
                break;
            case 'kayit_sil':
                if (!$duz) throw new RuntimeException('Kayıt silmek için değiştirme yetkisi gerekir.');
                $id = (int)($_POST['id'] ?? 0);
                $st = $pdoKamp->prepare("SELECT * FROM kamp_periyot_kayit WHERE id=?"); $st->execute([$id]);
                $eski = $st->fetch() ?: throw new RuntimeException('Kayıt bulunamadı.');
                $pdoKamp->prepare("DELETE FROM kamp_periyot_kayit WHERE id=?")->execute([$id]);
                kamp_audit('kamp_periyot_kayit', $id, 'DELETE', $eski, null);
                flash('success', 'Değişim kaydı silindi (yanlış giriş).');
                break;
            case 'tanim_kaydet':
                if (!$duz) throw new RuntimeException('Tanım değiştirmek için değiştirme yetkisi gerekir.');
                $id  = (int)($_POST['id'] ?? 0); $yeniTur = !$id;
                $ad  = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['ad'] ?? ''))), 0, 120, 'UTF-8');
                if ($ad === '') throw new RuntimeException('Değişim türünün adı boş olamaz.');
                $kap = isset(KAMP_PERIYOT_KAPSAM[$_POST['kapsam'] ?? '']) ? $_POST['kapsam'] : 'yatak';
                $gun = (int)($_POST['gun'] ?? 0);
                if ($gun < 1 || $gun > 3650) throw new RuntimeException('Periyot 1–3650 gün arasında olmalı.');
                $uy  = max(0, min($gun, (int)($_POST['uyari_gun'] ?? 0)));
                $tur = $kap === 'oda' ? implode(',', array_intersect(array_keys(KAMP_ODA_TUR), (array)($_POST['oda_tur'] ?? []))) : '';
                $st = $pdoKamp->prepare("SELECT id FROM kamp_periyot WHERE ad=? AND id<>?"); $st->execute([$ad, $id]);
                if ($st->fetchColumn()) throw new RuntimeException('Bu adla bir değişim türü zaten var.');
                $v = [$ad, $kap, $gun, $uy, $kap === 'yatak' && !empty($_POST['yeni_giriste']) ? 1 : 0, $tur ?: null,
                      trim((string)($_POST['aciklama'] ?? '')) ?: null, (int)($_POST['sira'] ?? 0), isset($_POST['aktif']) || !$id ? 1 : 0];
                if ($id) {
                    $pdoKamp->prepare("UPDATE kamp_periyot SET ad=?, kapsam=?, gun=?, uyari_gun=?, yeni_giriste=?, oda_tur=?, aciklama=?, sira=?, aktif=? WHERE id=?")->execute(array_merge($v, [$id]));
                } else {
                    $pdoKamp->prepare("INSERT INTO kamp_periyot (ad, kapsam, gun, uyari_gun, yeni_giriste, oda_tur, aciklama, sira, aktif) VALUES (?,?,?,?,?,?,?,?,?)")->execute($v);
                    $id = (int)$pdoKamp->lastInsertId();
                }
                kamp_audit('kamp_periyot', $id, $yeniTur ? 'INSERT' : 'UPDATE', null, ['ad' => $ad, 'gun' => $gun, 'kapsam' => $kap]);
                flash('success', '"' . $ad . '" kaydedildi.');
                $geri = 'periyodik.php?s=tanim';
                break;
            case 'tanim_sil':
                if (!$duz) throw new RuntimeException('Tanım silmek için değiştirme yetkisi gerekir.');
                $id = (int)($_POST['id'] ?? 0);
                $c = $pdoKamp->prepare("SELECT COUNT(*) FROM kamp_periyot_kayit WHERE periyot_id=?"); $c->execute([$id]);
                if ($n = (int)$c->fetchColumn()) {
                    $pdoKamp->prepare("UPDATE kamp_periyot SET aktif=0 WHERE id=?")->execute([$id]);
                    flash('info', "Bu türün $n değişim kaydı var — silinmedi, pasife alındı (geçmiş korunur).");
                } else {
                    $pdoKamp->prepare("DELETE FROM kamp_periyot WHERE id=?")->execute([$id]);
                    kamp_audit('kamp_periyot', $id, 'DELETE');
                    flash('success', 'Değişim türü silindi.');
                }
                $geri = 'periyodik.php?s=tanim';
                break;
            default: throw new RuntimeException('Bilinmeyen işlem.');
        }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect($geri);
}

$sek  = in_array($_GET['s'] ?? '', ['durum', 'gecmis', 'tanim'], true) ? $_GET['s'] : 'durum';
$pers = kamp_periyotlar($pdoKamp);
$tum  = kamp_periyotlar($pdoKamp, true);
$bina = (int)($_GET['bina'] ?? 0);
$binalar = kamp_binalar($pdoKamp);
if ($bina && !isset($binalar[$bina])) $bina = 0;
$pid  = (int)($_GET['p'] ?? 0);
if ($sek === 'durum' && !isset($pers[$pid])) $pid = (int)array_key_first($pers);
$yapanlar = array_column($pdoKamp->query("SELECT DISTINCT yapan FROM kamp_periyot_kayit WHERE yapan IS NOT NULL ORDER BY yapan LIMIT 100")->fetchAll(), 'yapan');

// ─── DURUM
$DGRUP = ['kirmizi' => ['Gecikmiş / yeni gelen', ['gecikti', 'yeni']], 'yaklasiyor' => ['Yaklaşıyor', ['yaklasiyor']],
          'yok' => ['Kaydı yok', ['yok']], 'tamam' => ['Zamanında', ['tamam']], 'bos' => ['Boş yatak', ['bos']], 'hepsi' => ['Hepsi', []]];
$satir = []; $ozet = ['per' => []]; $dsec = 'hepsi'; $dsay = [];
if ($sek === 'durum') {
    $tumDurum = kamp_periyot_durum($pdoKamp, $bina ?: null);
    $ozet = kamp_periyot_ozet($pdoKamp, $tumDurum);
    $satir = array_values(array_filter($tumDurum, fn($r) => (int)$r['periyot_id'] === $pid));
    foreach ($DGRUP as $k => [, $ds]) $dsay[$k] = $k === 'hepsi' ? count($satir) : count(array_filter($satir, fn($r) => in_array($r['durum'], $ds, true)));
    $dsec = isset($DGRUP[$_GET['d'] ?? '']) ? $_GET['d'] : ($dsay['kirmizi'] ? 'kirmizi' : 'hepsi');
    if ($dsec !== 'hepsi') $satir = array_values(array_filter($satir, fn($r) => in_array($r['durum'], $DGRUP[$dsec][1], true)));

    if (($_GET['export'] ?? '') === 'xlsx') {
        require_once __DIR__ . '/../includes/XlsxWriter.php';
        $xl = new \XlsxWriter('Periyodik Degisim');
        $xl->header(['Değişim','Bina','Kat','Oda','Yatak','Kalan kişi','Son değişim','Sonraki','Kalan gün','Durum','Yapıldı (✓)','Not']);
        foreach ($satir as $r) $xl->row([['v'=>$pers[$pid]['ad']], ['v'=>$r['bina_ad']], ['v'=>kamp_kat_ad((int)$r['kat'])], ['v'=>$r['oda_ad']], ['v'=>$r['yatak_no']],
            ['v'=>$r['kisi_ad']], ['v'=>$r['son'],'t'=>'date'], ['v'=>$r['sonraki'],'t'=>'date'], ['v'=>$r['kalan'],'t'=>'number'],
            ['v'=>KAMP_PER_DURUM[$r['durum']][0]], ['v'=>''], ['v'=>'']]);
        $xl->download('kamp_periyodik_' . date('Ymd') . '.xlsx');
    }
}

// ─── GEÇMİŞ
$gec = [];
$bas = kamp_tarih($_GET['bas'] ?? '') ?? date('Y-m-d', strtotime('-30 day'));
$bit = kamp_tarih($_GET['bit'] ?? '') ?? date('Y-m-d');
if ($bit < $bas) [$bas, $bit] = [$bit, $bas];
$q = trim((string)($_GET['q'] ?? ''));
if ($sek === 'gecmis') {
    $w = ['k.tarih BETWEEN ? AND ?']; $p = [$bas, $bit];
    if ($pid) { $w[] = 'k.periyot_id=?'; $p[] = $pid; }
    if ($bina) { $w[] = 'o.bina_id=?'; $p[] = $bina; }
    $st = $pdoKamp->prepare("SELECT k.*, pr.ad AS per_ad, pr.kapsam, y.no AS yatak_no, o.ad AS oda_ad, o.kat, b.ad AS bina_ad,
                                    TRIM(CONCAT(COALESCE(p.ad,''),' ',COALESCE(p.soyad,''))) AS kisi_ad
                               FROM kamp_periyot_kayit k JOIN kamp_periyot pr ON pr.id=k.periyot_id
                               LEFT JOIN kamp_yataklar y ON y.id=k.yatak_id
                               LEFT JOIN kamp_odalar o ON o.id=COALESCE(y.oda_id, k.oda_id)
                               LEFT JOIN kamp_binalar b ON b.id=o.bina_id
                               LEFT JOIN kamp_kisiler p ON p.id=k.kisi_id
                              WHERE " . implode(' AND ', $w) . " ORDER BY k.tarih DESC, k.id DESC");
    $st->execute($p); $gec = $st->fetchAll();
    if ($q !== '') { $kel = array_filter(explode(' ', kamp_norm($q)));
        $gec = array_values(array_filter($gec, function ($r) use ($kel) { $h = kamp_norm($r['per_ad'] . ' ' . $r['oda_ad'] . ' ' . $r['yatak_no'] . ' ' . $r['kisi_ad'] . ' ' . $r['yapan'] . ' ' . $r['aciklama']);
            foreach ($kel as $k) if (!str_contains($h, $k)) return false; return true; })); }
    if (($_GET['export'] ?? '') === 'xlsx') {
        require_once __DIR__ . '/../includes/XlsxWriter.php';
        $xl = new \XlsxWriter('Degisim Gecmisi');
        $xl->header(['Tarih','Değişim','Bina','Kat','Oda','Yatak','O gün kalan kişi','Yapan','Açıklama']);
        foreach ($gec as $r) $xl->row([['v'=>$r['tarih'],'t'=>'date'], ['v'=>$r['per_ad']], ['v'=>$r['bina_ad']], ['v'=>$r['kat'] !== null ? kamp_kat_ad((int)$r['kat']) : ''],
            ['v'=>$r['oda_ad']], ['v'=>$r['yatak_no'] ?? ($r['kapsam'] === 'oda' ? '(oda)' : '(silinmiş yatak)')], ['v'=>$r['kisi_ad']], ['v'=>$r['yapan']], ['v'=>$r['aciklama']]]);
        $xl->download('kamp_degisim_gecmisi_' . $bas . '_' . $bit . '.xlsx');
    }
}

$par = fn(array $ek) => 'periyodik.php?' . http_build_query(array_filter(array_merge(['s' => $sek, 'p' => $pid ?: null, 'bina' => $bina ?: null], $ek), fn($v) => $v !== null && $v !== ''));
$pageTitle = 'Periyodik Değişim — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<style>
.pd-oda td{background:var(--bs-tertiary-bg)!important;font-weight:600}
.pd-kart{cursor:pointer;transition:transform .1s}
.pd-kart.aktif{outline:3px solid var(--bs-primary);outline-offset:-1px}
.pd-form{position:sticky;bottom:0;z-index:5}
@media print{.ern-sidebar,.sidebar,.ern-topbar,.pd-arac,.pd-form,.nav,.ern-footer{display:none!important}}
</style>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3 pd-arac">
  <h4 class="mb-0"><i class="bi bi-arrow-repeat text-primary me-2"></i>Periyodik Değişim</h4>
  <span class="text-muted small">nevresim · yorgan · yastık · şilte … ne zaman değişti, ne zaman değişecek</span>
</div>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $sek === 'durum' ? 'active' : '' ?>" href="periyodik.php"><i class="bi bi-clipboard-check me-1"></i>Durum & toplu kayıt</a></li>
  <li class="nav-item"><a class="nav-link <?= $sek === 'gecmis' ? 'active' : '' ?>" href="periyodik.php?s=gecmis"><i class="bi bi-clock-history me-1"></i>Değişim geçmişi</a></li>
  <li class="nav-item"><a class="nav-link <?= $sek === 'tanim' ? 'active' : '' ?>" href="periyodik.php?s=tanim"><i class="bi bi-sliders me-1"></i>Değişim türleri</a></li>
</ul>

<?php if ($sek === 'durum'): ?>
<?php if (!$pers): ?>
  <div class="alert alert-warning">Aktif değişim türü yok. <a href="periyodik.php?s=tanim">Değişim türü ekleyin</a>.</div>
<?php else: ?>
<div class="row g-2 mb-3 pd-arac">
  <?php foreach ($pers as $id => $pr): $o = $ozet['per'][$id] ?? array_fill_keys(array_keys(KAMP_PER_DURUM), 0); $kir = $o['gecikti'] + $o['yeni']; ?>
  <div class="col-sm-6 col-xl-3"><a href="<?= h($par(['p' => $id, 'd' => null])) ?>" class="card border-0 shadow-sm h-100 text-decoration-none pd-kart <?= $id === $pid ? 'aktif' : '' ?>">
    <div class="card-body py-2">
      <div class="small fw-semibold text-body"><?= h($pr['ad']) ?></div>
      <div class="small text-muted"><?= h(KAMP_PERIYOT_KAPSAM[$pr['kapsam']] ?? '') ?> · <?= (int)$pr['gun'] ?> günde bir<?= $pr['yeni_giriste'] ? ' · yeni gelene' : '' ?></div>
      <div class="mt-1 d-flex flex-wrap gap-1">
        <span class="badge bg-<?= $kir ? 'danger' : 'light text-muted border' ?>"><?= $kir ?> gecikmiş</span>
        <span class="badge bg-<?= $o['yaklasiyor'] ? 'warning text-dark' : 'light text-muted border' ?>"><?= $o['yaklasiyor'] ?> yaklaşıyor</span>
        <?php if ($o['yok']): ?><span class="badge bg-secondary"><?= $o['yok'] ?> kaydı yok</span><?php endif; ?>
        <span class="badge bg-success-subtle text-success-emphasis"><?= $o['tamam'] ?> tamam</span>
      </div>
    </div></a></div>
  <?php endforeach; ?>
</div>

<div class="d-flex flex-wrap align-items-center gap-2 mb-2 pd-arac">
  <form class="d-flex gap-2"><input type="hidden" name="p" value="<?= $pid ?>"><input type="hidden" name="d" value="<?= h($dsec) ?>">
    <select name="bina" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto"><option value="">Tüm binalar</option>
      <?php foreach ($binalar as $id => $b): ?><option value="<?= $id ?>"<?= $bina === $id ? ' selected' : '' ?>><?= h($b['ad']) ?></option><?php endforeach; ?></select></form>
  <?php foreach ($DGRUP as $k => [$ad]): if ($k !== 'hepsi' && !$dsay[$k]) continue; ?>
  <a href="<?= h($par(['d' => $k])) ?>" class="btn btn-sm <?= $dsec === $k ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $ad ?> <span class="badge bg-light text-dark"><?= $dsay[$k] ?></span></a>
  <?php endforeach; ?>
  <div class="ms-auto d-flex gap-2">
    <a href="<?= h($par(['d' => $dsec, 'export' => 'xlsx'])) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Kontrol listesi (Excel)</a>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i></button>
  </div>
</div>
<?php if ($dsay['yok'] && $dsec !== 'yok'): ?>
<div class="alert alert-secondary py-2 small pd-arac"><i class="bi bi-info-circle me-1"></i><?= $dsay['yok'] ?> <?= $pers[$pid]['kapsam'] === 'oda' ? 'oda' : 'yatak' ?> için bu değişimin hiç kaydı yok.
  Sistemi ilk kullanırken son değişim tarihini bir kez toplu işaretleyin — sonraki tarihler oradan hesaplanır. <a href="<?= h($par(['d' => 'yok'])) ?>">Kaydı olmayanları göster</a></div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="islem" value="kaydet"><input type="hidden" name="periyot_id" value="<?= $pid ?>">
  <input type="hidden" name="geri" value="<?= h($par(['d' => $dsec])) ?>">
  <div class="card border-0 shadow-sm">
    <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.86rem" id="pdTablo">
      <thead class="table-light"><tr>
        <th style="width:32px"><?php if ($yaz && $satir): ?><input type="checkbox" class="form-check-input" id="pdHepsi" title="Listedekilerin tümü"><?php endif; ?></th>
        <th><?= $pers[$pid]['kapsam'] === 'oda' ? 'Oda' : 'Yatak' ?></th><th>Kalan kişi</th><th>Son değişim</th><th>Sonraki</th><th>Durum</th></tr></thead><tbody>
      <?php if (!$satir): ?><tr><td colspan="6" class="text-center text-muted py-4">Bu süzgeçte kayıt yok.</td></tr><?php endif; ?>
      <?php $og = null; foreach ($satir as $r):
          $grp = $r['bina_ad'] . ' · ' . kamp_kat_ad((int)$r['kat']) . ($r['kapsam'] === 'yatak' ? ' · ' . $r['oda_ad'] : '');
          if ($grp !== $og): $og = $grp; ?>
        <tr class="pd-oda"><td><?php if ($yaz): ?><input type="checkbox" class="form-check-input pd-grup" title="Bu gruptakilerin tümü"><?php endif; ?></td>
          <td colspan="5"><?php if ($r['kapsam'] === 'yatak'): ?><a href="oda.php?id=<?= (int)$r['oda_id'] ?>" class="text-decoration-none text-reset"><?= h($grp) ?></a><?php else: ?><?= h($grp) ?><?php endif; ?></td></tr>
      <?php endif; ?>
        <tr class="<?= in_array($r['durum'], ['gecikti','yeni'], true) ? 'table-danger' : ($r['durum'] === 'yaklasiyor' ? 'table-warning' : '') ?>">
          <td><?php if ($yaz): ?><input type="checkbox" class="form-check-input pd-sec" name="hedef[]" value="<?= (int)($r['kapsam'] === 'oda' ? $r['oda_id'] : $r['yatak_id']) ?>"><?php endif; ?></td>
          <td><?= h($r['kapsam'] === 'oda' ? $r['oda_ad'] : $r['oda_ad'] . ' › ' . $r['yatak_no']) ?></td>
          <td class="small"><?= $r['kisi_ad'] ? '<a href="kisi_detay.php?id=' . (int)$r['kisi_id'] . '" class="text-decoration-none">' . h($r['kisi_ad']) . '</a> <span class="text-muted">' . h(kamp_tarih_yaz($r['giris'])) . "'den beri</span>" : '<span class="text-muted">' . ($r['kapsam'] === 'oda' ? '' : 'boş') . '</span>' ?></td>
          <td><?= h(kamp_tarih_yaz($r['son'])) ?></td>
          <td><?= h(kamp_tarih_yaz($r['sonraki'])) ?></td>
          <td><?= kamp_per_rozet($r['durum'], $r['kalan']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
  <?php if ($yaz && $satir): ?>
  <div class="card border-0 shadow pd-form mt-2"><div class="card-body py-2 row g-2 align-items-end">
    <div class="col-md-2"><label class="form-label small mb-0">Değişim tarihi</label><input type="date" name="tarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-3"><label class="form-label small mb-0">Yapan (kişi / temizlik firması)</label><input name="yapan" class="form-control form-control-sm" list="dlYapan" maxlength="120"></div>
    <div class="col-md-4"><label class="form-label small mb-0">Açıklama</label><input name="aciklama" class="form-control form-control-sm" maxlength="255" placeholder="ör. yeni yorganlar dağıtıldı"></div>
    <div class="col-md-3"><button class="btn btn-success btn-sm w-100" id="pdKaydet" disabled><i class="bi bi-check2-all me-1"></i>Seçilenlere değişim yapıldı (<span id="pdSay">0</span>)</button></div>
  </div></div>
  <?php endif; ?>
</form>
<?php endif; ?>

<?php elseif ($sek === 'gecmis'): ?>
<form class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 align-items-end">
  <input type="hidden" name="s" value="gecmis">
  <div class="col-md-2"><label class="form-label small mb-0">Başlangıç</label><input type="date" name="bas" class="form-control form-control-sm" value="<?= h($bas) ?>"></div>
  <div class="col-md-2"><label class="form-label small mb-0">Bitiş</label><input type="date" name="bit" class="form-control form-control-sm" value="<?= h($bit) ?>"></div>
  <div class="col-md-3"><label class="form-label small mb-0">Değişim</label><select name="p" class="form-select form-select-sm"><option value="">Tümü</option>
    <?php foreach ($tum as $id => $pr): ?><option value="<?= $id ?>"<?= $pid === $id ? ' selected' : '' ?>><?= h($pr['ad']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Bina</label><select name="bina" class="form-select form-select-sm"><option value="">Tümü</option>
    <?php foreach ($binalar as $id => $b): ?><option value="<?= $id ?>"<?= $bina === $id ? ' selected' : '' ?>><?= h($b['ad']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Ara</label><input name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="oda, kişi, yapan…"></div>
  <div class="col-md-1"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button></div>
</div></form>
<div class="d-flex align-items-center mb-2"><span class="small text-muted"><strong><?= count($gec) ?></strong> kayıt · <?= h(kamp_tarih_yaz($bas)) ?> → <?= h(kamp_tarih_yaz($bit)) ?></span>
  <a href="<?= h($par(['bas' => $bas, 'bit' => $bit, 'q' => $q, 'export' => 'xlsx'])) ?>" class="btn btn-outline-success btn-sm ms-auto"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a></div>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.86rem">
  <thead class="table-light"><tr><th>Tarih</th><th>Değişim</th><th>Yer</th><th>O gün kalan</th><th>Yapan</th><th>Açıklama</th><th></th></tr></thead><tbody>
  <?php if (!$gec): ?><tr><td colspan="7" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
  <?php foreach ($gec as $r): ?><tr>
    <td class="text-nowrap"><?= h(kamp_tarih_yaz($r['tarih'])) ?></td>
    <td class="small"><?= h($r['per_ad']) ?></td>
    <td class="small"><?= h(trim(($r['bina_ad'] ?? '') . ' · ' . ($r['oda_ad'] ?? '?') . ($r['kapsam'] === 'yatak' ? ' › ' . ($r['yatak_no'] ?? '(silinmiş yatak)') : ''), ' ·')) ?></td>
    <td class="small"><?= $r['kisi_id'] ? '<a href="kisi_detay.php?id=' . (int)$r['kisi_id'] . '" class="text-decoration-none">' . h($r['kisi_ad']) . '</a>' : '<span class="text-muted">—</span>' ?></td>
    <td class="small"><?= h($r['yapan'] ?: '—') ?></td>
    <td class="small"><?= h($r['aciklama'] ?: '') ?></td>
    <td class="text-end"><?php if ($duz): ?><form method="post" class="d-inline" onsubmit="return confirm('Bu değişim kaydı silinsin mi? (yalnız yanlış girilen kayıt)')">
      <input type="hidden" name="islem" value="kayit_sil"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="geri" value="<?= h($par(['bas' => $bas, 'bit' => $bit, 'q' => $q])) ?>">
      <button class="btn btn-outline-danger btn-sm py-0"><i class="bi bi-trash"></i></button></form><?php endif; ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div></div>

<?php else: /* tanım */ ?>
<div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>Her tür için "kaç günde bir" yapılacağını yazın. <strong>Yeni gelene de yenilensin</strong> işaretliyse
  yatağa biri yerleştiğinde, gelişinden sonra değişim yazılana kadar o yatak kırmızı görünür (önceki kişinin nevresimi kalmasın). Kaydı olan tür silinmez, pasife alınır.</div>
<div class="card border-0 shadow-sm mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.88rem">
  <thead class="table-light"><tr><th>Değişim türü</th><th>Kapsam</th><th class="text-end">Kaç günde bir</th><th class="text-end">Uyarı</th><th>Yeni gelene</th><th>Durum</th><th></th></tr></thead><tbody>
  <?php foreach ($tum as $id => $pr): ?>
  <tr class="<?= $pr['aktif'] ? '' : 'text-muted' ?>">
    <td class="fw-semibold"><?= h($pr['ad']) ?><?= $pr['aciklama'] ? '<div class="small text-muted fw-normal">' . h($pr['aciklama']) . '</div>' : '' ?></td>
    <td class="small"><?= h(KAMP_PERIYOT_KAPSAM[$pr['kapsam']] ?? '') ?><?= $pr['kapsam'] === 'oda' ? '<div class="text-muted">' . h(implode(', ', array_map(fn($t) => KAMP_ODA_TUR[$t][0], kamp_per_oda_turleri($pr)))) . '</div>' : '' ?></td>
    <td class="text-end"><?= (int)$pr['gun'] ?> gün</td><td class="text-end"><?= (int)$pr['uyari_gun'] ?> gün kala</td>
    <td><?= $pr['yeni_giriste'] ? '<span class="badge bg-primary">evet</span>' : '—' ?></td>
    <td><?= $pr['aktif'] ? '<span class="badge bg-success">aktif</span>' : '<span class="badge bg-secondary">pasif</span>' ?></td>
    <td class="text-end text-nowrap"><?php if ($duz): ?>
      <button type="button" class="btn btn-outline-secondary btn-sm py-0" data-bs-toggle="collapse" data-bs-target="#td<?= $id ?>"><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline" onsubmit="return confirm('Değişim türü silinsin mi? (kaydı varsa pasife alınır)')"><input type="hidden" name="islem" value="tanim_sil"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-outline-danger btn-sm py-0"><i class="bi bi-trash"></i></button></form><?php endif; ?></td>
  </tr>
  <?php if ($duz): ?><tr class="collapse" id="td<?= $id ?>"><td colspan="7" class="bg-body-tertiary"><?php $T = $pr; include __DIR__ . '/_periyot_form.php'; ?></td></tr><?php endif; ?>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php if ($duz): ?>
<div class="card border-0 shadow-sm"><div class="card-header bg-white"><strong><i class="bi bi-plus-lg me-1"></i>Yeni değişim türü</strong></div>
  <div class="card-body"><?php $T = ['id' => 0, 'ad' => '', 'kapsam' => 'yatak', 'gun' => 30, 'uyari_gun' => 3, 'yeni_giriste' => 0, 'oda_tur' => '', 'aciklama' => '', 'sira' => count($tum) + 1, 'aktif' => 1]; include __DIR__ . '/_periyot_form.php'; ?></div></div>
<?php endif; ?>
<?php endif; ?>

<datalist id="dlYapan"><?php foreach ($yapanlar as $y): ?><option value="<?= h($y) ?>"><?php endforeach; ?></datalist>
<script>
(function(){
  var t = document.getElementById('pdTablo'); if (!t) return;
  var btn = document.getElementById('pdKaydet'), say = document.getElementById('pdSay');
  function guncelle(){ var n = t.querySelectorAll('.pd-sec:checked').length; if (say) say.textContent = n; if (btn) btn.disabled = !n; }
  var hep = document.getElementById('pdHepsi');
  if (hep) hep.addEventListener('change', function(){ t.querySelectorAll('.pd-sec,.pd-grup').forEach(function(c){ c.checked = hep.checked; }); guncelle(); });
  t.querySelectorAll('.pd-grup').forEach(function(g){ g.addEventListener('change', function(){
    var tr = g.closest('tr').nextElementSibling;
    while (tr && !tr.classList.contains('pd-oda')) { var c = tr.querySelector('.pd-sec'); if (c) c.checked = g.checked; tr = tr.nextElementSibling; }
    guncelle(); }); });
  t.addEventListener('change', function(e){ if (e.target.classList.contains('pd-sec')) guncelle(); });
  guncelle();
})();
document.querySelectorAll('.js-kapsam').forEach(function(s){
  function f(){ var fm = s.form; fm.querySelectorAll('.js-oda-tur').forEach(function(x){ x.classList.toggle('d-none', s.value !== 'oda'); });
                fm.querySelectorAll('.js-yeni').forEach(function(x){ x.classList.toggle('d-none', s.value === 'oda'); }); }
  s.addEventListener('change', f); f();
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
