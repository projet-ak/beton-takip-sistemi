<?php
/**
 * kamp/demirbas.php — KAMP DEMİRBAŞLARI (oda / bina bazında): baza, yatak, dolap, masa,
 * buzdolabı, klima, çamaşır makinesi…
 *  • Durum: sağlam · arızalı · bakımda · kayıp · hurda — her değişiklik kamp_demirbas_hareket'e
 *    yazılır ("ne zaman arızalandı, kim düzeltti" geçmişi).
 *  • TOPLU EKLE: seçilen binadaki / kattaki / türdeki TÜM odalara aynı demirbaşı ekler,
 *    "oda başına" ya da "yatak başına" adetle. Aynı adlı demirbaşı olan oda atlanır →
 *    tekrar çalıştırmak mükerrer açmaz.
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
$odaF  = (int)($_GET['oda'] ?? 0);
$binaF = (int)($_GET['bina'] ?? 0);
$durF  = isset(KAMP_DB_DURUM[$_GET['durum'] ?? '']) || in_array($_GET['durum'] ?? '', ['sorun'], true) ? $_GET['durum'] : '';
$q     = trim((string)($_GET['q'] ?? ''));
$geri  = 'demirbas.php?' . http_build_query(array_filter(['oda'=>$odaF ?: null,'bina'=>$binaF ?: null,'durum'=>$durF ?: null,'q'=>$q ?: null]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $geriP = kamp_geri($_POST['geri'] ?? '', 'demirbas.php');
    try {
        if (!$yaz) throw new RuntimeException('Yetkiniz yok.');
        $islem = (string)($_POST['islem'] ?? '');
        if ($islem === 'kaydet') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id && !$duz) throw new RuntimeException('Düzenlemek için değiştirme yetkisi gerekir.');
            $ad = trim(preg_replace('/\s+/u', ' ', (string)($_POST['ad'] ?? '')));
            if ($ad === '') throw new RuntimeException('Demirbaş adı boş olamaz.');
            $odaId = (int)($_POST['oda_id'] ?? 0) ?: null; $binaId = (int)($_POST['bina_id'] ?? 0) ?: null;
            if ($odaId) { $s = $pdoKamp->prepare("SELECT bina_id FROM kamp_odalar WHERE id=?"); $s->execute([$odaId]); $binaId = (int)$s->fetchColumn() ?: null; }
            if (!$odaId && !$binaId) throw new RuntimeException('Oda ya da bina seçin.');
            $durum = isset(KAMP_DB_DURUM[$_POST['durum'] ?? '']) ? $_POST['durum'] : 'saglam';
            $par = [$binaId, $odaId, $ad, max(1, (int)($_POST['adet'] ?? 1)), trim((string)($_POST['marka'] ?? '')) ?: null,
                    trim((string)($_POST['seri_no'] ?? '')) ?: null, trim((string)($_POST['aciklama'] ?? '')) ?: null];
            if ($id) {
                $pdoKamp->prepare("UPDATE kamp_demirbas SET bina_id=?, oda_id=?, ad=?, adet=?, marka=?, seri_no=?, aciklama=?, updated_at=NOW() WHERE id=?")->execute([...$par, $id]);
                kamp_demirbas_durum($pdoKamp, $id, $durum, '', current_user_id());
                kamp_audit('kamp_demirbas', $id, 'UPDATE', null, ['ad' => $ad]);
                flash('success', 'Demirbaş güncellendi.');
            } else {
                $pdoKamp->prepare("INSERT INTO kamp_demirbas (bina_id, oda_id, ad, adet, marka, seri_no, aciklama, durum) VALUES (?,?,?,?,?,?,?,?)")->execute([...$par, $durum]);
                $nid = (int)$pdoKamp->lastInsertId();
                $pdoKamp->prepare("INSERT INTO kamp_demirbas_hareket (demirbas_id, yeni_durum, aciklama, kullanici_id) VALUES (?,?,?,?)")->execute([$nid, $durum, 'Kayıt açıldı', current_user_id()]);
                kamp_audit('kamp_demirbas', $nid, 'INSERT', null, ['ad' => $ad, 'oda_id' => $odaId]);
                flash('success', 'Demirbaş eklendi.');
            }
        } elseif ($islem === 'durum') {
            kamp_demirbas_durum($pdoKamp, (int)$_POST['id'], (string)$_POST['durum'], (string)($_POST['aciklama'] ?? ''), current_user_id());
            kamp_audit('kamp_demirbas', (int)$_POST['id'], 'UPDATE', null, ['durum' => $_POST['durum'], 'aciklama' => $_POST['aciklama'] ?? '']);
            flash('success', 'Durum güncellendi: ' . (KAMP_DB_DURUM[$_POST['durum']][0] ?? ''));
        } elseif ($islem === 'sil') {
            if (!$duz) throw new RuntimeException('Silmek için değiştirme yetkisi gerekir.');
            $id = (int)$_POST['id'];
            $pdoKamp->prepare("DELETE FROM kamp_demirbas_hareket WHERE demirbas_id=?")->execute([$id]);
            $pdoKamp->prepare("DELETE FROM kamp_demirbas WHERE id=?")->execute([$id]);
            kamp_audit('kamp_demirbas', $id, 'DELETE');
            flash('success', 'Demirbaş kaydı silindi.');
        } elseif ($islem === 'toplu') {
            $ad = trim(preg_replace('/\s+/u', ' ', (string)($_POST['ad'] ?? '')));
            if ($ad === '') throw new RuntimeException('Demirbaş adı boş olamaz.');
            $w = ['o.aktif=1']; $p = [];
            if ($b = (int)($_POST['t_bina'] ?? 0)) { $w[] = 'o.bina_id=?'; $p[] = $b; }
            if (($_POST['t_kat'] ?? '') !== '') { $w[] = 'o.kat=?'; $p[] = (int)$_POST['t_kat']; }
            $turler = array_values(array_intersect((array)($_POST['t_tur'] ?? []), array_keys(KAMP_ODA_TUR)));
            if (!$turler) throw new RuntimeException('En az bir oda türü seçin.');
            $w[] = 'o.tur IN (' . implode(',', array_fill(0, count($turler), '?')) . ')'; $p = array_merge($p, $turler);
            $st = $pdoKamp->prepare("SELECT o.id, o.bina_id, (SELECT COUNT(*) FROM kamp_yataklar y WHERE y.oda_id=o.id AND y.aktif=1) AS yatak
                                       FROM kamp_odalar o WHERE " . implode(' AND ', $w));
            $st->execute($p);
            $adet = max(1, (int)($_POST['adet'] ?? 1)); $yatakBasi = ($_POST['mod'] ?? '') === 'yatak';
            $var = [];
            foreach ($pdoKamp->query("SELECT oda_id, ad FROM kamp_demirbas WHERE oda_id IS NOT NULL") as $r) $var[(int)$r['oda_id'] . '|' . kamp_norm($r['ad'])] = true;
            $ins = $pdoKamp->prepare("INSERT INTO kamp_demirbas (bina_id, oda_id, ad, adet, durum, marka) VALUES (?,?,?,?, 'saglam', ?)");
            $s = ['eklenen' => 0, 'atlanan' => 0, 'yataksiz' => 0];
            $pdoKamp->beginTransaction();
            foreach ($st->fetchAll() as $o) {
                if (isset($var[(int)$o['id'] . '|' . kamp_norm($ad)])) { $s['atlanan']++; continue; }
                $n = $yatakBasi ? $adet * (int)$o['yatak'] : $adet;
                if ($n <= 0) { $s['yataksiz']++; continue; }
                $ins->execute([(int)$o['bina_id'], (int)$o['id'], $ad, $n, trim((string)($_POST['marka'] ?? '')) ?: null]);
                $s['eklenen']++;
            }
            $pdoKamp->commit();
            kamp_audit('kamp_demirbas', 0, 'INSERT', null, ['toplu' => $ad] + $s);
            flash($s['eklenen'] ? 'success' : 'warning', "\"$ad\" {$s['eklenen']} odaya eklendi"
                . ($s['atlanan'] ? ", {$s['atlanan']} odada zaten vardı (atlandı)" : '') . ($s['yataksiz'] ? ", {$s['yataksiz']} odada yatak yok" : '') . '.');
        }
    } catch (Throwable $e) { if ($pdoKamp->inTransaction()) $pdoKamp->rollBack(); flash('error', $e->getMessage()); }
    redirect($geriP);
}

// ── Liste ──
$w = []; $p = [];
if ($odaF)  { $w[] = 'd.oda_id=?'; $p[] = $odaF; }
if ($binaF) { $w[] = 'd.bina_id=?'; $p[] = $binaF; }
$tum = $pdoKamp->prepare("SELECT d.*, o.ad AS oda_ad, o.kat, o.tur AS oda_tur, b.ad AS bina_ad,
                                 (SELECT MAX(h.tarih) FROM kamp_demirbas_hareket h WHERE h.demirbas_id=d.id) AS son_hareket
                            FROM kamp_demirbas d LEFT JOIN kamp_odalar o ON o.id=d.oda_id LEFT JOIN kamp_binalar b ON b.id=d.bina_id"
                        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY b.sira, b.ad, o.kat, o.sira, o.ad, d.ad");
$tum->execute($p);
$tum = $tum->fetchAll();
if ($q !== '') { $nq = array_filter(explode(' ', kamp_norm($q)));
    $tum = array_values(array_filter($tum, function ($r) use ($nq) { $h = kamp_norm($r['ad'] . ' ' . $r['marka'] . ' ' . $r['seri_no'] . ' ' . $r['oda_ad'] . ' ' . $r['aciklama']);
        foreach ($nq as $k) if (!str_contains($h, $k)) return false; return true; })); }
$sayac = array_fill_keys(array_keys(KAMP_DB_DURUM), 0); $adetTop = 0;
foreach ($tum as $r) { $sayac[$r['durum']] = ($sayac[$r['durum']] ?? 0) + (int)$r['adet']; $adetTop += (int)$r['adet']; }
$liste = $durF === 'sorun' ? array_values(array_filter($tum, fn($r) => in_array($r['durum'], ['arizali','bakimda','kayip'], true)))
       : ($durF ? array_values(array_filter($tum, fn($r) => $r['durum'] === $durF)) : $tum);

if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new \XlsxWriter('Kamp Demirbaş');
    $xl->header(['Bina','Kat','Oda','Oda Türü','Demirbaş','Adet','Durum','Marka / Model','Seri No','Açıklama','Son Hareket']);
    foreach ($liste as $r) $xl->row([['v'=>$r['bina_ad']], ['v'=>$r['kat'] !== null ? kamp_kat_ad((int)$r['kat']) : ''], ['v'=>$r['oda_ad'] ?: '(bina ortak)'],
        ['v'=>KAMP_ODA_TUR[$r['oda_tur']][0] ?? ''], ['v'=>$r['ad']], ['v'=>(int)$r['adet'],'t'=>'number'], ['v'=>KAMP_DB_DURUM[$r['durum']][0] ?? $r['durum']],
        ['v'=>$r['marka']], ['v'=>$r['seri_no']], ['v'=>$r['aciklama']], ['v'=>$r['son_hareket'] ? substr($r['son_hareket'], 0, 10) : '','t'=>'date']]);
    $xl->download('kamp_demirbas_' . date('Ymd_Hi') . '.xlsx');
}

$duzRow = null;
if ($duz && ($did = (int)($_GET['duzenle'] ?? 0))) { $s = $pdoKamp->prepare("SELECT * FROM kamp_demirbas WHERE id=?"); $s->execute([$did]); $duzRow = $s->fetch() ?: null; }
$gecmis = [];
if ($gid = (int)($_GET['gecmis'] ?? 0)) {
    $s = $pdoKamp->prepare("SELECT h.*, d.ad FROM kamp_demirbas_hareket h JOIN kamp_demirbas d ON d.id=h.demirbas_id WHERE h.demirbas_id=? ORDER BY h.tarih DESC, h.id DESC");
    $s->execute([$gid]); $gecmis = $s->fetchAll();
}
$odalar = $pdoKamp->query("SELECT o.id, o.ad, o.kat, o.tur, b.ad AS bina_ad, b.id AS bina_id FROM kamp_odalar o JOIN kamp_binalar b ON b.id=o.bina_id
                            WHERE o.aktif=1 ORDER BY b.sira, b.ad, o.kat, o.sira, o.ad")->fetchAll();
$odaAd = null; foreach ($odalar as $o) if ((int)$o['id'] === $odaF) $odaAd = $o['bina_ad'] . ' › ' . $o['ad'];
$adlar = array_unique(array_merge(KAMP_DEMIRBAS_ONERI, $pdoKamp->query("SELECT DISTINCT ad FROM kamp_demirbas ORDER BY ad")->fetchAll(PDO::FETCH_COLUMN)));
$binalar = kamp_binalar($pdoKamp);
$katlar = $pdoKamp->query("SELECT DISTINCT kat FROM kamp_odalar ORDER BY kat")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Demirbaş — Kamp Takip';
require __DIR__ . '/../includes/header.php';
$odaSelect = function ($secili) use ($odalar) {
    $h = '<option value="">— oda seçin —</option>'; $og = null;
    foreach ($odalar as $o) { $g = $o['bina_ad'] . ' · ' . kamp_kat_ad((int)$o['kat']);
        if ($g !== $og) { if ($og !== null) $h .= '</optgroup>'; $h .= '<optgroup label="' . htmlspecialchars($g) . '">'; $og = $g; }
        $h .= '<option value="' . (int)$o['id'] . '"' . ((int)$secili === (int)$o['id'] ? ' selected' : '') . '>' . htmlspecialchars($o['ad'] . ' (' . (KAMP_ODA_TUR[$o['tur']][0] ?? '') . ')') . '</option>'; }
    return $h . ($og !== null ? '</optgroup>' : '');
};
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-lamp text-primary me-2"></i>Kamp Demirbaşları</h4>
  <?php if ($odaAd): ?><span class="badge bg-primary-subtle text-primary-emphasis fs-6"><?= h($odaAd) ?> <a href="demirbas.php" class="text-reset ms-1">✕</a></span><?php endif; ?>
  <div class="ms-auto d-flex gap-2">
    <a href="<?= h($geri . '&export=xlsx') ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <?php if ($odaF): ?><a href="oda.php?id=<?= $odaF ?>" class="btn btn-outline-secondary btn-sm">Oda kartı</a><?php endif; ?>
  </div>
</div>

<div class="row g-2 mb-3">
  <?php foreach (['' => ['Toplam adet', 'secondary', $adetTop]] + array_map(fn($x, $k) => [$x[0], explode(' ', $x[1])[0], $sayac[$k]], KAMP_DB_DURUM, array_keys(KAMP_DB_DURUM)) as $k => [$ad, $renk, $n]):
      $anahtar = is_int($k) ? array_keys(KAMP_DB_DURUM)[$k] : $k; ?>
  <div class="col-6 col-md">
    <a href="<?= h('demirbas.php?' . http_build_query(array_filter(['oda'=>$odaF ?: null,'bina'=>$binaF ?: null,'durum'=>$anahtar ?: null]))) ?>" class="card border-0 shadow-sm text-decoration-none h-100 <?= $durF === $anahtar ? 'border border-2 border-' . $renk : '' ?>">
      <div class="card-body py-2"><div class="small text-muted"><?= h($ad) ?></div><div class="fs-5 fw-bold text-<?= $renk ?>"><?= (int)$n ?></div></div></a>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($gecmis): ?>
<div class="card border-0 shadow-sm mb-3 border-start border-4 border-info">
  <div class="card-header bg-white d-flex"><strong>Durum geçmişi — <?= h($gecmis[0]['ad']) ?></strong><a href="<?= h($geri) ?>" class="ms-auto small">kapat</a></div>
  <div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.85rem"><thead class="table-light"><tr><th>Tarih</th><th>Eski</th><th>Yeni</th><th>Açıklama</th></tr></thead><tbody>
  <?php foreach ($gecmis as $g): ?><tr><td><?= h(date('d.m.Y H:i', strtotime($g['tarih']))) ?></td><td><?= $g['eski_durum'] ? kamp_dbRozet($g['eski_durum']) : '—' ?></td><td><?= kamp_dbRozet($g['yeni_durum']) ?></td><td><?= h($g['aciklama']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<form class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 align-items-end">
  <?php if ($odaF): ?><input type="hidden" name="oda" value="<?= $odaF ?>"><?php endif; ?>
  <div class="col-md-4"><label class="form-label small mb-0">Ara</label><input name="q" class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="demirbaş, marka, seri no, oda…"></div>
  <div class="col-md-3"><label class="form-label small mb-0">Bina</label><select name="bina" class="form-select form-select-sm"><option value="">Tümü</option>
    <?php foreach ($binalar as $id => $b): ?><option value="<?= $id ?>"<?= $binaF === $id ? ' selected' : '' ?>><?= h($b['ad']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-0">Durum</label><select name="durum" class="form-select form-select-sm"><option value="">Tümü</option><option value="sorun"<?= $durF === 'sorun' ? ' selected' : '' ?>>Sorunlu (arızalı + bakımda + kayıp)</option>
    <?php foreach (KAMP_DB_DURUM as $k => [$ad]): ?><option value="<?= $k ?>"<?= $durF === $k ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Süz</button></div>
</div></form>

<div class="card border-0 shadow-sm mb-3">
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:.86rem">
    <thead class="table-light"><tr><th>Yer</th><th>Demirbaş</th><th class="text-end">Adet</th><th>Durum</th><th>Açıklama</th><th></th></tr></thead><tbody>
    <?php if (!$liste): ?><tr><td colspan="6" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
    <?php foreach ($liste as $r): ?>
      <tr class="<?= in_array($r['durum'], ['arizali','kayip'], true) ? 'table-danger' : ($r['durum'] === 'bakimda' ? 'table-warning' : '') ?>">
        <td class="small"><?= h($r['bina_ad']) ?><div><?php if ($r['oda_id']): ?><a href="oda.php?id=<?= (int)$r['oda_id'] ?>"><?= h(kamp_kat_kisa((int)$r['kat']) . ' · ' . $r['oda_ad']) ?></a><?php else: ?><span class="text-muted">bina ortak</span><?php endif; ?></div></td>
        <td class="fw-semibold"><?= h($r['ad']) ?><?= $r['marka'] || $r['seri_no'] ? '<div class="small text-muted fw-normal">' . h(trim($r['marka'] . ($r['seri_no'] ? ' · SN ' . $r['seri_no'] : ''))) . '</div>' : '' ?></td>
        <td class="text-end"><?= (int)$r['adet'] ?></td>
        <td><?php if ($yaz): ?>
          <form method="post" class="d-flex gap-1" onsubmit="var a=prompt('Açıklama (arıza nedeni, tamir eden…) — boş bırakılabilir','');if(a===null)return false;this.aciklama.value=a;">
            <input type="hidden" name="islem" value="durum"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="aciklama" value=""><input type="hidden" name="geri" value="<?= h($geri) ?>">
            <select name="durum" class="form-select form-select-sm" style="width:auto" onchange="this.form.requestSubmit()">
              <?php foreach (KAMP_DB_DURUM as $k => [$ad]): ?><option value="<?= $k ?>"<?= $r['durum'] === $k ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select>
          </form>
          <?php else: ?><?= kamp_dbRozet($r['durum']) ?><?php endif; ?></td>
        <td class="small text-muted"><?= h($r['aciklama']) ?></td>
        <td class="text-end text-nowrap">
          <a href="<?= h($geri . '&gecmis=' . (int)$r['id']) ?>" class="btn btn-outline-info btn-sm py-0" title="Durum geçmişi"><i class="bi bi-clock-history"></i></a>
          <?php if ($duz): ?><a href="<?= h($geri . '&duzenle=' . (int)$r['id']) ?>#ekle" class="btn btn-outline-secondary btn-sm py-0"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" onsubmit="return confirm('Demirbaş kaydı silinsin mi? (Hurdaya ayrıldıysa durumunu Hurda yapın, silmeyin.)')"><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="geri" value="<?= h($geri) ?>">
            <button class="btn btn-outline-danger btn-sm py-0"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<?php if ($yaz): ?>
<div class="row g-3" id="ekle">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong><?= $duzRow ? 'Demirbaşı düzenle' : 'Demirbaş ekle' ?></strong></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <input type="hidden" name="islem" value="kaydet"><input type="hidden" name="id" value="<?= (int)($duzRow['id'] ?? 0) ?>"><input type="hidden" name="geri" value="<?= h($geri) ?>">
          <div class="col-md-8"><label class="form-label small">Oda</label><select name="oda_id" class="form-select form-select-sm" data-ara><?= $odaSelect($duzRow['oda_id'] ?? $odaF) ?></select></div>
          <div class="col-md-4"><label class="form-label small">… ya da bina ortak</label><select name="bina_id" class="form-select form-select-sm"><option value="">—</option>
            <?php foreach ($binalar as $id => $b): ?><option value="<?= $id ?>"<?= (!($duzRow['oda_id'] ?? 0) && (int)($duzRow['bina_id'] ?? 0) === $id) ? ' selected' : '' ?>><?= h($b['ad']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label small">Demirbaş *</label><input name="ad" class="form-control form-control-sm" list="dlDb" required value="<?= h($duzRow['ad'] ?? '') ?>"></div>
          <div class="col-md-2"><label class="form-label small">Adet</label><input type="number" name="adet" min="1" class="form-control form-control-sm" value="<?= (int)($duzRow['adet'] ?? 1) ?>"></div>
          <div class="col-md-4"><label class="form-label small">Durum</label><select name="durum" class="form-select form-select-sm">
            <?php foreach (KAMP_DB_DURUM as $k => [$ad]): ?><option value="<?= $k ?>"<?= ($duzRow['durum'] ?? 'saglam') === $k ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label small">Marka / model</label><input name="marka" class="form-control form-control-sm" value="<?= h($duzRow['marka'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label small">Seri no</label><input name="seri_no" class="form-control form-control-sm" value="<?= h($duzRow['seri_no'] ?? '') ?>"></div>
          <div class="col-12"><label class="form-label small">Açıklama</label><input name="aciklama" class="form-control form-control-sm" value="<?= h($duzRow['aciklama'] ?? '') ?>"></div>
          <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Kaydet</button>
            <?php if ($duzRow): ?><a href="<?= h($geri) ?>" class="btn btn-light btn-sm">Vazgeç</a><?php endif; ?></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white"><strong><i class="bi bi-collection me-1"></i>Toplu ekle (birden çok odaya)</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2" onsubmit="return confirm('Seçilen tüm odalara eklensin mi?')">
          <input type="hidden" name="islem" value="toplu"><input type="hidden" name="geri" value="<?= h($geri) ?>">
          <div class="col-md-6"><label class="form-label small">Bina</label><select name="t_bina" class="form-select form-select-sm"><option value="">Tüm binalar</option>
            <?php foreach ($binalar as $id => $b): ?><option value="<?= $id ?>"><?= h($b['ad']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label small">Kat</label><select name="t_kat" class="form-select form-select-sm"><option value="">Tüm katlar</option>
            <?php foreach ($katlar as $k): ?><option value="<?= (int)$k ?>"><?= h(kamp_kat_ad((int)$k)) ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="form-label small d-block">Oda türü</label>
            <?php foreach (KAMP_ODA_TUR as $k => [$ad]): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="t_tur[]" value="<?= $k ?>" id="tt<?= $k ?>" <?= in_array($k, ['yatak','kogus'], true) ? 'checked' : '' ?>><label class="form-check-label small" for="tt<?= $k ?>"><?= h($ad) ?></label></div><?php endforeach; ?></div>
          <div class="col-md-5"><label class="form-label small">Demirbaş *</label><input name="ad" class="form-control form-control-sm" list="dlDb" required placeholder="ör. Elbise dolabı"></div>
          <div class="col-md-2"><label class="form-label small">Adet</label><input type="number" name="adet" min="1" value="1" class="form-control form-control-sm"></div>
          <div class="col-md-5"><label class="form-label small">Adet nasıl?</label><select name="mod" class="form-select form-select-sm"><option value="oda">oda başına</option><option value="yatak">yatak başına (yatak sayısı × adet)</option></select></div>
          <div class="col-12"><label class="form-label small">Marka / model (isteğe bağlı)</label><input name="marka" class="form-control form-control-sm"></div>
          <div class="col-12"><button class="btn btn-outline-primary btn-sm">Odalara ekle</button>
            <div class="form-text">Örn. tüm yatak odalarına "Baza" yatak başına 1 · ortak alanlara "Buzdolabı" oda başına 1. Aynı adlı demirbaşı olan oda atlanır.</div></div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<datalist id="dlDb"><?php foreach ($adlar as $a): ?><option value="<?= h($a) ?>"><?php endforeach; ?></datalist>
<script src="../assets/js/secici.js"></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
