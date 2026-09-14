<?php
/**
 * pts/personel_form.php — PTS personel kartı (ekle / düzenle)
 *
 * Kayıtlar tek biçim BÜYÜK HARF tutulur (`pts_buyuk` — mb_strtoupper Türkçe 'i'yi
 * 'I' yapıyor, 'İ' olmalı). Birim / lokasyon SERBEST METİNDİR: başka modülün
 * lokasyon ağacına bağlanmaz, öneriler kendi kayıtlarımızdan gelir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_pts.php';
require_once __DIR__ . '/_ortak.php';

pts_semasi_kur($pdoPts);
$id        = (int)($_GET['id'] ?? 0);
$yazabilir = yetki_var('duzenle') || yetki_var('giris');
$kayit     = $id ? pts_personel_bul($pdoPts, $id) : null;
if ($id && !$kayit) { flash('error', 'Personel bulunamadı.'); redirect('personel.php'); }
$pageTitle = ($kayit ? pts_personel_ad($kayit) : 'Yeni Personel') . ' — Personel Takip';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$yazabilir) { flash('error', 'Bu işlem için yetkiniz yok.'); redirect('personel.php'); }
    try {
        $v = [
            'sicil_no'    => trim((string)($_POST['sicil_no'] ?? '')) ?: null,
            'ad'          => pts_buyuk((string)($_POST['ad'] ?? '')),
            'soyad'       => pts_buyuk((string)($_POST['soyad'] ?? '')),
            'unvan'       => pts_buyuk((string)($_POST['unvan'] ?? '')) ?: null,
            'birim'       => pts_buyuk((string)($_POST['birim'] ?? '')) ?: null,
            'lokasyon'    => trim((string)($_POST['lokasyon'] ?? '')) ?: null,
            'telefon'     => trim((string)($_POST['telefon'] ?? '')) ?: null,
            'eposta'      => trim((string)($_POST['eposta'] ?? '')) ?: null,
            'ise_giris'   => trim((string)($_POST['ise_giris'] ?? '')) ?: null,
            'isten_cikis' => trim((string)($_POST['isten_cikis'] ?? '')) ?: null,
            'notlar'      => trim((string)($_POST['notlar'] ?? '')) ?: null,
        ];
        if ($v['ad'] === '' && $v['soyad'] === '') throw new RuntimeException('Ad ya da soyad girilmeli.');

        // Mükerrer sicil engeli — sicil kart üretiminin ve IT aktarımının eşleşme anahtarı.
        if ($v['sicil_no'] !== null) {
            $chk = $pdoPts->prepare("SELECT id FROM pts_personel WHERE sicil_no=? AND id<>?");
            $chk->execute([$v['sicil_no'], $id]);
            if ($chk->fetch()) throw new RuntimeException('"' . $v['sicil_no'] . '" sicil numarası başka bir personelde kayıtlı.');
        }

        if ($id) {
            $pdoPts->prepare("UPDATE pts_personel SET " . implode(', ', array_map(fn($k) => "$k=?", array_keys($v))) . " WHERE id=?")
                   ->execute([...array_values($v), $id]);
            audit_log($pdoPts, 'pts_personel', $id, 'UPDATE', $kayit, $v, current_user_id());
            flash('success', pts_personel_ad($v) . ' güncellendi.');
        } else {
            $pdoPts->prepare("INSERT INTO pts_personel (" . implode(', ', array_keys($v)) . ", created_at) VALUES ("
                             . implode(',', array_fill(0, count($v), '?')) . ",?)")
                   ->execute([...array_values($v), date('Y-m-d H:i:s')]);
            $id = (int)$pdoPts->lastInsertId();
            audit_log($pdoPts, 'pts_personel', $id, 'INSERT', null, $v, current_user_id());
            flash('success', pts_personel_ad($v) . ' eklendi. Kart tanımlamak için Kartlar ekranına geçin.');
        }
        redirect('personel.php');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        // Hata halinde form YENİDEN ÇİZİLİR — kullanıcının yazdıkları kaybolmasın diye
        // gönderilen değerler geri konur (11 alanı tekrar doldurtmak kabul edilemez).
        $kayit = array_merge($kayit ?? [], $v ?? []);
    }
}

$kart      = $id ? pts_aktif_kart($pdoPts, $id) : null;
$birimler  = pts_personel_secenekler($pdoPts, 'birim');
$lokler    = pts_personel_secenekler($pdoPts, 'lokasyon');
$unvanlar  = pts_personel_secenekler($pdoPts, 'unvan');
$sonHrk    = [];
if ($id) {
    $st = $pdoPts->prepare("SELECT h.*, n.kod AS nokta_kod FROM pts_hareketler h
                            LEFT JOIN pts_noktalar n ON n.id = h.nokta_id
                            WHERE h.personel_id=? ORDER BY h.zaman DESC, h.id DESC LIMIT 10");
    $st->execute([$id]);
    $sonHrk = $st->fetchAll();
}
$d = fn($k) => h((string)($kayit[$k] ?? ''));
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i><?= $kayit ? h(pts_personel_ad($kayit)) : 'Yeni Personel' ?></h4>
  <?php if ($kart): ?><span class="badge bg-primary font-monospace">ArUco <?= (int)$kart['marker_id'] ?></span><?php endif; ?>
  <a href="personel.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-arrow-left me-1"></i>Listeye dön</a>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <form method="post" class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label small">Sicil No</label>
            <input name="sicil_no" class="form-control form-control-sm font-monospace" value="<?= $d('sicil_no') ?>">
            <div class="form-text">Kart üretiminde ArUco ID sicilden türetilebilir (0–<?= PTS_MAX_MARKER ?>).</div></div>
          <div class="col-md-4"><label class="form-label small">Ad</label>
            <input name="ad" class="form-control form-control-sm" value="<?= $d('ad') ?>" required></div>
          <div class="col-md-4"><label class="form-label small">Soyad</label>
            <input name="soyad" class="form-control form-control-sm" value="<?= $d('soyad') ?>"></div>

          <div class="col-md-4"><label class="form-label small">Unvan</label>
            <input name="unvan" class="form-control form-control-sm" list="ptsUnvan" value="<?= $d('unvan') ?>">
            <datalist id="ptsUnvan"><?php foreach ($unvanlar as $x): ?><option value="<?= h($x) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-md-4"><label class="form-label small">Birim</label>
            <input name="birim" class="form-control form-control-sm" list="ptsBirim" value="<?= $d('birim') ?>">
            <datalist id="ptsBirim"><?php foreach ($birimler as $x): ?><option value="<?= h($x) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-md-4"><label class="form-label small">Lokasyon</label>
            <input name="lokasyon" class="form-control form-control-sm" list="ptsLok" value="<?= $d('lokasyon') ?>"
                   placeholder="Kartal Batı Yakası — 1. Etap">
            <datalist id="ptsLok"><?php foreach ($lokler as $x): ?><option value="<?= h($x) ?>"><?php endforeach; ?></datalist></div>

          <div class="col-md-4"><label class="form-label small">Telefon</label>
            <input name="telefon" class="form-control form-control-sm" value="<?= $d('telefon') ?>"></div>
          <div class="col-md-4"><label class="form-label small">E-posta</label>
            <input name="eposta" type="email" class="form-control form-control-sm" value="<?= $d('eposta') ?>"></div>
          <div class="col-md-2"><label class="form-label small">İşe giriş</label>
            <input name="ise_giris" type="date" class="form-control form-control-sm" value="<?= $d('ise_giris') ?>"></div>
          <div class="col-md-2"><label class="form-label small">İşten çıkış</label>
            <input name="isten_cikis" type="date" class="form-control form-control-sm" value="<?= $d('isten_cikis') ?>">
            <div class="form-text">Doluysa kart verilemez.</div></div>

          <div class="col-12"><label class="form-label small">Notlar</label>
            <textarea name="notlar" class="form-control form-control-sm" rows="2"><?= $d('notlar') ?></textarea></div>
        </div>
      </div>
      <div class="card-footer bg-white">
        <?php if ($yazabilir): ?>
          <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
        <?php else: ?>
          <span class="small text-muted">Salt okuma yetkiniz var.</span>
        <?php endif; ?>
        <a href="personel.php" class="btn btn-outline-secondary btn-sm">Vazgeç</a>
      </div>
    </form>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white"><strong>ArUco Kart</strong></div>
      <div class="card-body small">
        <?php if ($kart): ?>
          <p class="mb-1">Aktif kart: <span class="badge bg-primary font-monospace">ID <?= (int)$kart['marker_id'] ?></span>
            <span class="text-muted"><?= h($kart['sozluk']) ?></span></p>
          <p class="text-muted mb-2">Verildi: <?= h(format_date($kart['verildi'])) ?></p>
        <?php else: ?>
          <p class="text-muted mb-2">Bu personelde aktif kart yok.</p>
        <?php endif; ?>
        <a href="kartlar.php?q=<?= urlencode((string)($kayit['sicil_no'] ?? pts_personel_ad($kayit ?: []))) ?>&durum=hepsi"
           class="btn btn-outline-primary btn-sm"><i class="bi bi-person-vcard me-1"></i>Kart ekranında aç</a>
      </div>
    </div>

    <?php if ($id): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex align-items-center"><strong>Son hareketler</strong>
        <a href="hareketler.php?personel_id=<?= $id ?>" class="ms-auto small">defter →</a></div>
      <div class="table-responsive" style="max-height:260px;overflow:auto">
        <table class="table table-sm mb-0" style="font-size:.82rem"><tbody>
        <?php if (!$sonHrk): ?><tr><td class="text-muted text-center py-3">Henüz hareket yok.</td></tr><?php endif; ?>
        <?php foreach ($sonHrk as $r): ?>
          <tr><td><?= pts_yonRozet($r['yon']) ?></td>
              <td class="small text-muted"><?= h((string)$r['nokta_kod']) ?></td>
              <td class="text-end small text-nowrap"><?= h(date('d.m.Y H:i', strtotime($r['zaman']))) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
