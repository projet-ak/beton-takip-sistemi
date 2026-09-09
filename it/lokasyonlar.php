<?php
/**
 * it/lokasyonlar.php — Lokasyon / proje ağacı yönetimi
 * Kök: proje (Kartal Batı Yakası) veya bina (ERN Holding İstanbul Merkez); altında etap kodları (U030, U031, U039)
 * ya da birimler (direktörlükler, satış ofisi, yönetim kurulu…). Her düğümde kaç cihaz / kaç personel var görünür.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
it_semasi_kur($pdoIt);
$duzenleyebilir = yetki_var('duzenle');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $duzenleyebilir) {
    $islem = $_POST['action'] ?? '';
    $lid = (int)($_POST['id'] ?? 0);
    if ($islem === 'seed') {
        $n = it_lokasyon_seed($pdoIt);
        flash('success', $n ? "$n lokasyon eklendi (Kartal Batı Yakası + ERN Holding Merkez Binası)." : 'Varsayılan yapı zaten mevcut.');
    } elseif ($islem === 'kaydet') {
        $ad  = mb_substr(trim((string)($_POST['ad'] ?? '')), 0, 120);
        $kod = mb_substr(trim((string)($_POST['kod'] ?? '')), 0, 20) ?: null;
        $tur = isset(IT_LOK_TUR[$_POST['tur'] ?? '']) ? $_POST['tur'] : 'birim';
        $ust = (int)($_POST['ust_id'] ?? 0) ?: null;
        $acik = mb_substr(trim((string)($_POST['aciklama'] ?? '')), 0, 255) ?: null;
        $sira = (int)($_POST['sira'] ?? 0);
        $aktif = isset($_POST['aktif']) ? 1 : 0;
        if ($ad === '') { $error = 'Lokasyon adı zorunludur.'; }
        elseif ($lid && $ust && in_array($ust, it_lokasyon_altlar($pdoIt, $lid), true)) { $error = 'Bir lokasyon kendi altına taşınamaz.'; }
        else {
            if ($lid) {
                $pdoIt->prepare("UPDATE it_lokasyonlar SET ad=?, kod=?, tur=?, ust_id=?, aciklama=?, sira=?, aktif=? WHERE id=?")->execute([$ad, $kod, $tur, $ust, $acik, $sira, $aktif, $lid]);
                // Bağlı cihazların görünen lokasyon yolu güncel kalsın
                it_lokasyonlar($pdoIt, true);
                foreach (it_lokasyon_altlar($pdoIt, $lid) as $aid)
                    $pdoIt->prepare("UPDATE it_cihazlar SET lokasyon=? WHERE lokasyon_id=?")->execute([it_lokasyon_yol($pdoIt, $aid), $aid]);
                flash('success', 'Lokasyon güncellendi.');
            } else {
                $pdoIt->prepare("INSERT INTO it_lokasyonlar (ad, kod, tur, ust_id, aciklama, sira, aktif) VALUES (?,?,?,?,?,?,?)")->execute([$ad, $kod, $tur, $ust, $acik, $sira, $aktif]);
                flash('success', 'Lokasyon eklendi.');
            }
            redirect('lokasyonlar.php');
        }
    } elseif ($islem === 'sil' && $lid) {
        $c = $pdoIt->prepare("SELECT (SELECT COUNT(*) FROM it_cihazlar WHERE lokasyon_id=?) + (SELECT COUNT(*) FROM it_personel WHERE lokasyon_id=?) + (SELECT COUNT(*) FROM it_lokasyonlar WHERE ust_id=?)");
        $c->execute([$lid, $lid, $lid]);
        if ((int)$c->fetchColumn()) flash('error', 'Bu lokasyona bağlı cihaz, personel ya da alt lokasyon var — silinemez, pasife alın.');
        else { $pdoIt->prepare("DELETE FROM it_lokasyonlar WHERE id=?")->execute([$lid]); flash('success', 'Lokasyon silindi.'); }
        redirect('lokasyonlar.php');
    }
}

$duz = it_lokasyon_duz($pdoIt, false);
$cihazSay = []; $persSay = [];
try {
    foreach ($pdoIt->query("SELECT lokasyon_id, COUNT(*) n, SUM(durum='aktif') aktif FROM it_cihazlar WHERE durum<>'hurda' AND lokasyon_id IS NOT NULL GROUP BY lokasyon_id") as $r) $cihazSay[(int)$r['lokasyon_id']] = $r;
    foreach ($pdoIt->query("SELECT lokasyon_id, COUNT(*) n FROM it_personel WHERE lokasyon_id IS NOT NULL AND (isten_cikis IS NULL OR isten_cikis > CURDATE()) GROUP BY lokasyon_id") as $r) $persSay[(int)$r['lokasyon_id']] = (int)$r['n'];
} catch (Throwable $e) {}
// Alt lokasyonlar dahil toplam (proje seçilince etapları kapsar)
$toplamC = function (int $id) use ($pdoIt, $cihazSay) { $t = 0; foreach (it_lokasyon_altlar($pdoIt, $id) as $a) $t += (int)($cihazSay[$a]['n'] ?? 0); return $t; };
$toplamP = function (int $id) use ($pdoIt, $persSay) { $t = 0; foreach (it_lokasyon_altlar($pdoIt, $id) as $a) $t += $persSay[$a] ?? 0; return $t; };

$edit = null;
if (isset($_GET['edit'])) $edit = it_lokasyonlar($pdoIt)[(int)$_GET['edit']] ?? null;
$yeniUst = (int)($_GET['ust'] ?? 0);
$pageTitle = 'Lokasyonlar — IT Envanter';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-diagram-3 text-primary me-2"></i>Lokasyonlar &amp; Projeler</h4>
    <div class="d-flex gap-2">
        <?php if ($duzenleyebilir && !$duz): ?>
        <form method="post"><input type="hidden" name="action" value="seed"><button class="btn btn-outline-success btn-sm"><i class="bi bi-magic me-1"></i>Varsayılan yapıyı yükle</button></form>
        <?php endif; ?>
        <?php if ($duzenleyebilir): ?><a href="lokasyonlar.php?yeni=1" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Yeni Lokasyon</a><?php endif; ?>
    </div>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-<?= ($edit || isset($_GET['yeni']) || $yeniUst) ? '7' : '12' ?>">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white small text-muted">Proje → etap (U030 / U031 / U039) · Bina → direktörlük / ofis. Sayılar alt lokasyonları da kapsar.</div>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:.86rem">
        <thead class="table-light"><tr><th>Lokasyon</th><th>Tür</th><th>Kod</th><th class="text-end">Cihaz</th><th class="text-end">Kullanımda</th><th class="text-end">Personel</th><th></th></tr></thead>
        <tbody>
        <?php if (!$duz): ?><tr><td colspan="7" class="text-center text-muted py-4">Lokasyon yok. "Varsayılan yapıyı yükle" ile Kartal projesi etapları ve Merkez bina birimleri tek tıkla gelir.</td></tr><?php endif; ?>
        <?php foreach ($duz as $x): $r = $x['r']; $tk = IT_LOK_TUR[$r['tur']] ?? ['?', 'bi-geo']; ?>
          <tr class="<?= (int)$r['aktif'] ? '' : 'text-muted' ?>">
            <td style="padding-left:<?= 8 + $x['d'] * 22 ?>px"><i class="bi <?= h($tk[1]) ?> me-1 text-muted"></i><?= $x['d'] === 0 ? '<strong>' . h($r['ad']) . '</strong>' : h($r['ad']) ?><?= (int)$r['aktif'] ? '' : ' <span class="badge bg-secondary">pasif</span>' ?>
              <?php if ($r['aciklama']): ?><div class="small text-muted"><?= h($r['aciklama']) ?></div><?php endif; ?></td>
            <td class="small"><?= h($tk[0]) ?></td>
            <td class="font-monospace"><?= h($r['kod'] ?: '—') ?></td>
            <td class="text-end"><?php $tc = $toplamC((int)$x['id']); echo $tc ? '<a href="cihazlar.php?lokasyon_id=' . (int)$x['id'] . '" class="text-decoration-none">' . $tc . '</a>' : '<span class="text-muted">—</span>'; ?></td>
            <td class="text-end"><?= (int)($cihazSay[(int)$x['id']]['aktif'] ?? 0) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-end"><?php $tp = $toplamP((int)$x['id']); echo $tp ? '<a href="personel.php?lokasyon_id=' . (int)$x['id'] . '" class="text-decoration-none">' . $tp . '</a>' : '<span class="text-muted">—</span>'; ?></td>
            <td class="text-end text-nowrap">
              <?php if ($duzenleyebilir): ?>
              <a href="lokasyonlar.php?ust=<?= (int)$x['id'] ?>" class="btn btn-sm btn-outline-secondary py-0" title="Alt lokasyon ekle"><i class="bi bi-plus"></i></a>
              <a href="lokasyonlar.php?edit=<?= (int)$x['id'] ?>" class="btn btn-sm btn-outline-primary py-0" title="Düzenle"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Silinsin mi? Bağlı kayıt varsa engellenir.')"><input type="hidden" name="action" value="sil"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>

  <?php if ($duzenleyebilir && ($edit || isset($_GET['yeni']) || $yeniUst)): $ustSec = $edit ? (int)($edit['ust_id'] ?? 0) : $yeniUst; ?>
  <div class="col-lg-5">
    <form method="post" class="card border-0 shadow-sm">
      <input type="hidden" name="action" value="kaydet"><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="card-header bg-white"><strong><?= $edit ? 'Lokasyon Düzenle' : 'Yeni Lokasyon' ?></strong></div>
      <div class="card-body"><div class="row g-2">
        <div class="col-12"><label class="form-label small mb-0">Üst lokasyon</label>
          <select name="ust_id" class="form-select form-select-sm"><option value="">— kök (proje / bina) —</option>
            <?php foreach ($duz as $x): if ($edit && (int)$x['id'] === (int)$edit['id']) continue; ?>
            <option value="<?= (int)$x['id'] ?>" <?= $ustSec === (int)$x['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $x['d']) . h(trim(($x['r']['kod'] ? $x['r']['kod'] . ' ' : '') . $x['r']['ad'])) ?></option>
            <?php endforeach; ?></select></div>
        <div class="col-8"><label class="form-label small mb-0">Ad <span class="text-danger">*</span></label><input name="ad" class="form-control form-control-sm" required maxlength="120" value="<?= h($edit['ad'] ?? '') ?>" placeholder="1. Etap / Satış Ofisi / Merkez Bina"></div>
        <div class="col-4"><label class="form-label small mb-0">Kod</label><input name="kod" class="form-control form-control-sm font-monospace" maxlength="20" value="<?= h($edit['kod'] ?? '') ?>" placeholder="U030"></div>
        <div class="col-6"><label class="form-label small mb-0">Tür</label><select name="tur" class="form-select form-select-sm"><?php foreach (IT_LOK_TUR as $k => [$ad]): ?><option value="<?= $k ?>" <?= ($edit['tur'] ?? ($yeniUst ? 'birim' : 'proje')) === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
        <div class="col-3"><label class="form-label small mb-0">Sıra</label><input type="number" name="sira" class="form-control form-control-sm" value="<?= (int)($edit['sira'] ?? 0) ?>"></div>
        <div class="col-3 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="aktif" id="lokAktif" <?= !$edit || (int)$edit['aktif'] ? 'checked' : '' ?>><label class="form-check-label small" for="lokAktif">Aktif</label></div></div>
        <div class="col-12"><label class="form-label small mb-0">Açıklama</label><input name="aciklama" class="form-control form-control-sm" maxlength="255" value="<?= h($edit['aciklama'] ?? '') ?>"></div>
      </div></div>
      <div class="card-footer bg-white d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Kaydet</button><a href="lokasyonlar.php" class="btn btn-outline-secondary btn-sm">İptal</a></div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
