<?php
/**
 * pts/hareketler.php — Giriş / çıkış defteri (ham hareketler)
 *
 * Puantaj özettir; burası "kim, ne zaman, hangi kapıdan, hangi kartla" sorusunun
 * ham cevabıdır. Her satırda geçiş anındaki kamera görüntüsü de vardır.
 *
 * Kart okutmayı unutan personel için **elle hareket ekleme/silme** buradadır
 * (`elle=1` işaretlenir ki hangi kaydın makineden hangisinin insandan geldiği belli olsun).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu','saha_sefi']);
require_once __DIR__ . '/../includes/db_pts.php';
require_once __DIR__ . '/_ortak.php';

it_semasi_kur($pdoPts);
pts_semasi_kur($pdoPts);
$pageTitle = 'Giriş / Çıkış Defteri — Personel Takip';
$yazabilir = yetki_var('duzenle') || yetki_var('giris');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$yazabilir) { flash('error', 'Bu işlem için yetkiniz yok.'); redirect('hareketler.php'); }
    $islem = $_POST['islem'] ?? '';
    try {
        if ($islem === 'elle_ekle') {
            $pid   = (int)($_POST['personel_id'] ?? 0);
            $yon   = $_POST['yon'] ?? '';
            $zaman = trim((string)($_POST['zaman'] ?? ''));
            if (!$pid || !isset(PTS_YON[$yon])) throw new RuntimeException('Personel ve yön zorunludur.');
            $ts = strtotime(str_replace('T', ' ', $zaman));
            if (!$ts) throw new RuntimeException('Geçerli bir tarih/saat girin.');
            if (!it_personel_bul($pdoPts, $pid)) throw new RuntimeException('Personel bulunamadı.');
            $pdoPts->prepare("INSERT INTO pts_hareketler (personel_id, yon, zaman, elle, aciklama, kullanici_id, created_at)
                              VALUES (?,?,?,1,?,?,?)")
                   ->execute([$pid, $yon, date('Y-m-d H:i:s', $ts),
                              trim((string)($_POST['aciklama'] ?? '')) ?: 'Elle eklendi',
                              current_user_id(), date('Y-m-d H:i:s')]);
            audit_log($pdoPts, 'pts_hareketler', (int)$pdoPts->lastInsertId(), 'INSERT', null,
                      ['personel_id'=>$pid,'yon'=>$yon,'elle'=>1], current_user_id());
            flash('success', 'Hareket elle eklendi (defterde "elle" olarak işaretlendi).');
        } elseif ($islem === 'sil') {
            // ⚠ Yalnız ELLE eklenen kayıt silinebilir: kart okutma kaydı kanıttır,
            // yanlışsa karşı yönde elle kayıt açılır, geçmiş silinmez.
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdoPts->prepare("SELECT * FROM pts_hareketler WHERE id=?");
            $st->execute([$id]); $h = $st->fetch();
            if (!$h) throw new RuntimeException('Kayıt bulunamadı.');
            if ((int)$h['elle'] !== 1) throw new RuntimeException('Kart okutma kaydı silinemez — düzeltme için karşı yönde elle kayıt açın.');
            $pdoPts->prepare("DELETE FROM pts_hareketler WHERE id=?")->execute([$id]);
            audit_log($pdoPts, 'pts_hareketler', $id, 'DELETE', $h, null, current_user_id());
            flash('success', 'Elle eklenen hareket silindi.');
        }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect('hareketler.php?' . http_build_query(array_filter($_GET)));
}

[$where, $parametre, $etkin] = pts_filtre($_GET);
$sayfa   = max(1, (int)($_GET['sayfa'] ?? 1));
$limit   = 100;
$sayacSt = $pdoPts->prepare("SELECT COUNT(*) FROM pts_hareketler h $where");
$sayacSt->execute($parametre);
$toplam  = (int)$sayacSt->fetchColumn();
$sonSayfa = max(1, (int)ceil($toplam / $limit));
$sayfa    = min($sayfa, $sonSayfa);

$st = $pdoPts->prepare("SELECT h.*, p.ad, p.soyad, p.sicil_no, p.birim, n.kod AS nokta_kod, n.ad AS nokta_ad
                          FROM pts_hareketler h
                          JOIN it_personel p ON p.id = h.personel_id
                          LEFT JOIN pts_noktalar n ON n.id = h.nokta_id
                          $where
                         ORDER BY h.zaman DESC, h.id DESC
                         LIMIT $limit OFFSET " . (($sayfa - 1) * $limit));
$st->execute($parametre);
$liste = $st->fetchAll();

$noktalar = $pdoPts->query("SELECT id, kod, ad FROM pts_noktalar ORDER BY kod")->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-arrow-left-right text-primary me-2"></i>Giriş / Çıkış Defteri</h4>
  <span class="badge bg-secondary"><?= number_format($toplam, 0, ',', '.') ?> hareket</span>
  <?php if ($yazabilir): ?>
  <button class="btn btn-outline-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#elleModal">
    <i class="bi bi-plus-lg me-1"></i>Elle Hareket Ekle</button>
  <?php endif; ?>
</div>

<form method="get" class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label small mb-0">Başlangıç</label>
        <input type="date" name="bas" class="form-control form-control-sm" value="<?= h($etkin['bas'] ?? '') ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-0">Bitiş</label>
        <input type="date" name="bit" class="form-control form-control-sm" value="<?= h($etkin['bit'] ?? '') ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-0">Yön</label>
        <select name="yon" class="form-select form-select-sm"><option value="">Tümü</option>
          <?php foreach (PTS_YON as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= ($etkin['yon']??'')===$k?'selected':'' ?>><?= h($v[0]) ?></option>
          <?php endforeach; ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-0">Geçiş noktası</label>
        <select name="nokta_id" class="form-select form-select-sm"><option value="">Tümü</option>
          <?php foreach ($noktalar as $n): ?>
            <option value="<?= (int)$n['id'] ?>" <?= (int)($etkin['nokta_id']??0)===(int)$n['id']?'selected':'' ?>>
              <?= h($n['kod'] . ' — ' . $n['ad']) ?></option>
          <?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-0">Fotoğraf</label>
        <select name="foto" class="form-select form-select-sm"><option value="">Tümü</option>
          <option value="var" <?= ($etkin['foto']??'')==='var'?'selected':'' ?>>Görüntüsü olanlar</option></select></div>
      <div class="col-md-1 d-flex gap-1">
        <button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i></button></div>
    </div>
    <?php if (!empty($etkin['personel_id'])):
        $kp = it_personel_bul($pdoPts, (int)$etkin['personel_id']); ?>
      <input type="hidden" name="personel_id" value="<?= (int)$etkin['personel_id'] ?>">
      <div class="mt-2 small">Süzgeç: <span class="badge bg-primary"><?= h(trim(($kp['ad']??'') . ' ' . ($kp['soyad']??''))) ?></span>
        <a href="hareketler.php" class="ms-1">temizle</a></div>
    <?php endif; ?>
  </div>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light"><tr>
        <th>Zaman</th><th>Personel</th><th>Sicil</th><th>Yön</th><th>Geçiş noktası</th>
        <th>ArUco</th><th>Kaynak</th><th class="text-center">Görüntü</th><th></th></tr></thead>
      <tbody>
      <?php if (!$liste): ?><tr><td colspan="9" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
      <?php foreach ($liste as $r): ?>
        <tr>
          <td class="text-nowrap font-monospace small"><?= h(date('d.m.Y H:i', strtotime($r['zaman']))) ?></td>
          <td><a href="../it/personel_detay.php?id=<?= (int)$r['personel_id'] ?>" class="text-decoration-none"><?= h(trim($r['ad'] . ' ' . $r['soyad'])) ?></a>
              <?php if (!empty($r['birim'])): ?><div class="small text-muted"><?= h($r['birim']) ?></div><?php endif; ?></td>
          <td class="font-monospace small"><?= h($r['sicil_no'] ?: '—') ?></td>
          <td><?= pts_yonRozet($r['yon']) ?></td>
          <td class="small"><?= $r['nokta_kod'] ? h($r['nokta_kod'] . ' — ' . $r['nokta_ad']) : '<span class="text-muted">—</span>' ?></td>
          <td class="font-monospace small"><?= $r['marker_id'] !== null ? (int)$r['marker_id'] : '—' ?></td>
          <td><?= $r['elle']
                ? '<span class="badge bg-warning text-dark" title="' . h($r['aciklama'] ?? '') . '">elle</span>'
                : '<span class="badge bg-light text-dark border">kart</span>' ?></td>
          <td class="text-center">
            <?php if (!empty($r['foto_url'])): ?>
              <a href="<?= h($rootPath . $r['foto_url']) ?>" target="_blank" title="Geçiş anındaki görüntü">
                <img src="<?= h($rootPath . $r['foto_url']) ?>" alt="" style="height:34px;border-radius:4px"></a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($yazabilir && (int)$r['elle'] === 1): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Elle eklenen bu hareket silinecek. Devam?')">
              <input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0" title="Sil"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($sonSayfa > 1): ?>
  <div class="card-footer bg-white d-flex align-items-center gap-2 small">
    <span>Sayfa <?= $sayfa ?> / <?= $sonSayfa ?></span>
    <div class="ms-auto btn-group btn-group-sm">
      <?php $q = $_GET; ?>
      <?php if ($sayfa > 1): $q['sayfa'] = $sayfa - 1; ?>
        <a class="btn btn-outline-secondary" href="?<?= h(http_build_query($q)) ?>">← Önceki</a><?php endif; ?>
      <?php if ($sayfa < $sonSayfa): $q['sayfa'] = $sayfa + 1; ?>
        <a class="btn btn-outline-secondary" href="?<?= h(http_build_query($q)) ?>">Sonraki →</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($yazabilir): ?>
<div class="modal fade" id="elleModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content"><form method="post">
    <div class="modal-header"><h5 class="modal-title">Elle Hareket Ekle</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="islem" value="elle_ekle">
      <div class="alert alert-info py-2 small mb-3"><i class="bi bi-info-circle me-1"></i>
        Kart okutmayı unutan personel için. Kayıt defterde <strong>"elle"</strong> rozetiyle görünür,
        makine kaydından ayırt edilir.</div>
      <div class="mb-2"><label class="form-label small">Personel</label>
        <select name="personel_id" class="form-select form-select-sm" required>
          <option value="">— seçin —</option>
          <?= it_personel_options($pdoPts, null) ?>
        </select></div>
      <div class="row g-2">
        <div class="col-6"><label class="form-label small">Yön</label>
          <select name="yon" class="form-select form-select-sm" required>
            <?php foreach (PTS_YON as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v[0]) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-6"><label class="form-label small">Tarih / saat</label>
          <input type="datetime-local" name="zaman" class="form-control form-control-sm"
                 value="<?= h(date('Y-m-d\TH:i')) ?>" required></div>
      </div>
      <div class="mt-2"><label class="form-label small">Açıklama</label>
        <input name="aciklama" class="form-control form-control-sm" placeholder="Kartını unuttu, kapıdan giriş yaptı…"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
      <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Ekle</button>
    </div>
  </form></div></div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
