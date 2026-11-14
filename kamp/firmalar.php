<?php
/**
 * kamp/firmalar.php — kampta kişisi kalan firmalar (taşeronlar). Modülün KENDİ firma listesi.
 * Satırda: şu an kampta kalan kişi, toplam kişi, bu ay kişi-gece. Kullanılan firma silinmez, pasife alınır.
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
    try {
        $islem = (string)($_POST['islem'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($islem === 'kaydet') {
            if (!$yaz || ($id && !$duz)) throw new RuntimeException('Yetkiniz yok.');
            $ad = kamp_buyuk($_POST['ad'] ?? '');
            if ($ad === '') throw new RuntimeException('Firma adı boş olamaz.');
            foreach (kamp_firmalar($pdoKamp, true) as $f) if ((int)$f['id'] !== $id && kamp_norm($f['ad']) === kamp_norm($ad)) throw new RuntimeException('Bu firma zaten kayıtlı.');
            $par = [$ad, trim((string)($_POST['yetkili'] ?? '')) ?: null, trim((string)($_POST['telefon'] ?? '')) ?: null,
                    preg_replace('/\D+/', '', (string)($_POST['vergi_no'] ?? '')) ?: null, trim((string)($_POST['aciklama'] ?? '')) ?: null];
            if ($id) { $pdoKamp->prepare("UPDATE kamp_firmalar SET ad=?, yetkili=?, telefon=?, vergi_no=?, aciklama=?, aktif=? WHERE id=?")->execute([...$par, !empty($_POST['aktif']) ? 1 : 0, $id]);
                       kamp_audit('kamp_firmalar', $id, 'UPDATE', null, ['ad' => $ad]); flash('success', 'Firma güncellendi.'); }
            else { $pdoKamp->prepare("INSERT INTO kamp_firmalar (ad, yetkili, telefon, vergi_no, aciklama) VALUES (?,?,?,?,?)")->execute($par);
                   kamp_audit('kamp_firmalar', (int)$pdoKamp->lastInsertId(), 'INSERT', null, ['ad' => $ad]); flash('success', 'Firma eklendi.'); }
        } elseif ($islem === 'sil') {
            if (!$duz) throw new RuntimeException('Silmek için değiştirme yetkisi gerekir.');
            $c = $pdoKamp->prepare("SELECT (SELECT COUNT(*) FROM kamp_kisiler WHERE firma_id=?) + (SELECT COUNT(*) FROM kamp_konaklama WHERE firma_id=?)");
            $c->execute([$id, $id]);
            if ($c->fetchColumn()) throw new RuntimeException('Bu firmaya bağlı kişi / konaklama var — silinmez, pasife alın.');
            $pdoKamp->prepare("DELETE FROM kamp_firmalar WHERE id=?")->execute([$id]);
            kamp_audit('kamp_firmalar', $id, 'DELETE');
            flash('success', 'Firma silindi.');
        }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect('firmalar.php');
}

$ayBas = date('Y-m-01'); $bugun = date('Y-m-d');
$liste = $pdoKamp->query("SELECT f.*,
        (SELECT COUNT(*) FROM kamp_konaklama k WHERE k.cikis IS NULL AND k.firma_id=f.id) AS kampta,
        (SELECT COUNT(*) FROM kamp_kisiler p WHERE p.firma_id=f.id) AS kisi
      FROM kamp_firmalar f ORDER BY f.aktif DESC, f.ad")->fetchAll();
// Bu ayın kişi-gecesi (icmal ile aynı hesap)
$ic = kamp_icmal($pdoKamp, date('Y-m'));
$gece = []; foreach ($ic['firma'] as $f) $gece[(int)$f['firma_id']] = $f['gece'];
$duzRow = null;
if ($did = (int)($_GET['duzenle'] ?? 0)) foreach ($liste as $r) if ((int)$r['id'] === $did) $duzRow = $r;

$pageTitle = 'Firmalar — Kamp Takip';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3"><h4 class="mb-0"><i class="bi bi-building text-primary me-2"></i>Firmalar</h4>
  <a href="icmal.php" class="btn btn-outline-primary btn-sm ms-auto"><i class="bi bi-table me-1"></i>Aylık icmal</a></div>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light"><tr><th>Firma</th><th>Yetkili / telefon</th><th class="text-end">Şu an kampta</th><th class="text-end">Kayıtlı kişi</th><th class="text-end"><?= h(date('m.Y')) ?> kişi-gece</th><th></th></tr></thead><tbody>
      <?php if (!$liste): ?><tr><td colspan="6" class="text-center text-muted py-4">Firma yok.</td></tr><?php endif; ?>
      <?php foreach ($liste as $r): ?>
        <tr class="<?= (int)$r['aktif'] ? '' : 'opacity-50' ?>">
          <td class="fw-semibold"><?= h($r['ad']) ?><?= (int)$r['aktif'] ? '' : ' <span class="badge bg-secondary">pasif</span>' ?><?= $r['vergi_no'] ? '<div class="small text-muted fw-normal">VKN ' . h($r['vergi_no']) . '</div>' : '' ?></td>
          <td class="small"><?= h($r['yetkili'] ?: '') ?><div class="text-muted"><?= h($r['telefon'] ?: '') ?></div></td>
          <td class="text-end"><a href="kisiler.php?durum=kampta&firma=<?= (int)$r['id'] ?>" class="fw-bold text-decoration-none"><?= (int)$r['kampta'] ?></a></td>
          <td class="text-end"><a href="kisiler.php?durum=hepsi&firma=<?= (int)$r['id'] ?>" class="text-decoration-none"><?= (int)$r['kisi'] ?></a></td>
          <td class="text-end"><?= (int)($gece[(int)$r['id']] ?? 0) ?></td>
          <td class="text-end text-nowrap"><?php if ($duz): ?><a href="firmalar.php?duzenle=<?= (int)$r['id'] ?>" class="btn btn-outline-secondary btn-sm py-0"><i class="bi bi-pencil"></i></a>
            <?php if (!(int)$r['kisi']): ?><form method="post" class="d-inline" onsubmit="return confirm('Firma silinsin mi?')"><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-outline-danger btn-sm py-0"><i class="bi bi-trash"></i></button></form><?php endif; endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
  </div>
  <?php if ($yaz): ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm"><div class="card-header bg-white"><strong><?= $duzRow ? 'Firmayı düzenle' : 'Yeni firma' ?></strong></div><div class="card-body">
      <form method="post"><input type="hidden" name="islem" value="kaydet"><input type="hidden" name="id" value="<?= (int)($duzRow['id'] ?? 0) ?>">
        <div class="mb-2"><label class="form-label small">Firma adı *</label><input name="ad" class="form-control form-control-sm" required value="<?= h($duzRow['ad'] ?? '') ?>"></div>
        <div class="mb-2"><label class="form-label small">Yetkili</label><input name="yetkili" class="form-control form-control-sm" value="<?= h($duzRow['yetkili'] ?? '') ?>"></div>
        <div class="mb-2"><label class="form-label small">Telefon</label><input name="telefon" class="form-control form-control-sm" value="<?= h($duzRow['telefon'] ?? '') ?>"></div>
        <div class="mb-2"><label class="form-label small">Vergi no</label><input name="vergi_no" class="form-control form-control-sm" value="<?= h($duzRow['vergi_no'] ?? '') ?>"></div>
        <div class="mb-2"><label class="form-label small">Açıklama</label><input name="aciklama" class="form-control form-control-sm" value="<?= h($duzRow['aciklama'] ?? '') ?>"></div>
        <?php if ($duzRow): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="aktif" value="1" id="fa" <?= (int)$duzRow['aktif'] ? 'checked' : '' ?>><label class="form-check-label small" for="fa">Aktif</label></div><?php endif; ?>
        <button class="btn btn-primary btn-sm">Kaydet</button> <?php if ($duzRow): ?><a href="firmalar.php" class="btn btn-light btn-sm">Vazgeç</a><?php endif; ?>
      </form></div></div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
