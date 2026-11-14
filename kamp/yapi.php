<?php
/**
 * kamp/yapi.php — KAMP YAPISI: binalar, katlar, konteynerler, odalar, yataklar
 *
 *  • Bina ekle / düzenle / pasife al (odası olan bina silinmez).
 *  • HIZLI YAPI ÜRETİCİ: "2 kat × 8 konteyner × 2 oda (sol/sağ) + ortak banyo/WC" ya da
 *    "3 kat × 6 koğuş × 16 ranza" gibi düzenleri tek seferde açar; aynı adlı oda atlanır.
 *  • ODA TABLOSU: her odanın adı, türü (yatak odası / çamaşırhane / depo …) ve yatak sayısı
 *    tek ekranda değiştirilir — konteynerdeki bir oda çamaşırhane yapıldıysa türünü
 *    değiştirmek yeter, boş yatakları kaldırılır (dolu yatak varsa engellenir).
 * Yetki: değiştirme.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$pageTitle = 'Kamp Yapısı — Kamp Takip';
$duz = yetki_var('duzenle');
$binaSec = (int)($_GET['bina'] ?? $_POST['bina_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$duz) { flash('error', 'Kamp yapısını değiştirmek için değiştirme yetkisi gerekir.'); redirect('yapi.php'); }
    $islem = (string)($_POST['islem'] ?? '');
    try {
        if ($islem === 'bina_kaydet') {
            $id  = (int)($_POST['id'] ?? 0);
            $ad  = trim(preg_replace('/\s+/u', ' ', (string)($_POST['ad'] ?? '')));
            $tur = isset(KAMP_BINA_TUR[$_POST['tur'] ?? '']) ? $_POST['tur'] : 'diger';
            if ($ad === '') throw new RuntimeException('Bina adı boş olamaz.');
            foreach (kamp_binalar($pdoKamp, true) as $b)
                if ((int)$b['id'] !== $id && kamp_norm($b['ad']) === kamp_norm($ad)) throw new RuntimeException('Bu adla bir bina zaten var.');
            $v = [$ad, trim((string)($_POST['kod'] ?? '')) ?: null, $tur, trim((string)($_POST['aciklama'] ?? '')) ?: null,
                  (int)($_POST['sira'] ?? 0), !empty($_POST['aktif']) || !$id ? 1 : 0];
            if ($id) {
                $pdoKamp->prepare("UPDATE kamp_binalar SET ad=?, kod=?, tur=?, aciklama=?, sira=?, aktif=? WHERE id=?")->execute([...$v, $id]);
                kamp_audit('kamp_binalar', $id, 'UPDATE', null, ['ad' => $ad, 'tur' => $tur]);
                flash('success', 'Bina güncellendi.');
            } else {
                $pdoKamp->prepare("INSERT INTO kamp_binalar (ad, kod, tur, aciklama, sira, aktif) VALUES (?,?,?,?,?,?)")->execute($v);
                $id = (int)$pdoKamp->lastInsertId();
                kamp_audit('kamp_binalar', $id, 'INSERT', null, ['ad' => $ad, 'tur' => $tur]);
                flash('success', 'Bina eklendi — şimdi aşağıdaki üreticiyle odalarını açın.');
            }
            redirect('yapi.php?bina=' . $id);
        }
        if ($islem === 'bina_sil') {
            $id = (int)$_POST['id'];
            $n = (int)$pdoKamp->query("SELECT COUNT(*) FROM kamp_odalar WHERE bina_id=$id")->fetchColumn();
            if ($n) throw new RuntimeException("Binada $n oda var — silinmez. Kullanılmıyorsa düzenleyip pasife alın.");
            $pdoKamp->prepare("DELETE FROM kamp_binalar WHERE id=?")->execute([$id]);
            kamp_audit('kamp_binalar', $id, 'DELETE');
            flash('success', 'Bina silindi.');
            redirect('yapi.php');
        }
        if ($islem === 'uret') {
            if (!$binaSec) throw new RuntimeException('Bina seçin.');
            $katlar = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($_POST['katlar'] ?? ''))), fn($x) => $x !== '' && is_numeric($x)));
            $duzen = ($_POST['duzen'] ?? 'konteyner') === 'konteyner';
            $s = kamp_yapi_uret($pdoKamp, $binaSec, [
                'katlar' => array_map('intval', $katlar),
                'unite' => $duzen ? (int)($_POST['unite'] ?? 0) : 0,
                'unite_oda' => (int)($_POST['unite_oda'] ?? 2),
                'ortak' => !empty($_POST['ortak']),
                'oda' => $duzen ? 0 : (int)($_POST['oda'] ?? 0),
                'yatak' => (int)($_POST['yatak'] ?? 1),
                'yatak_tur' => $_POST['yatak_tur'] ?? 'tek',
                'oda_tur' => $duzen ? 'yatak' : ($_POST['oda_tur'] ?? 'kogus'),
                'bas' => (int)($_POST['bas'] ?? 1),
            ]);
            kamp_audit('kamp_odalar', $binaSec, 'INSERT', null, ['uretici' => $s]);
            flash($s['oda'] ? 'success' : 'warning', $s['oda'] . ' oda ve ' . $s['yatak'] . ' yatak açıldı'
                . ($s['atlanan'] ? ', ' . $s['atlanan'] . ' oda zaten vardı (atlandı)' : '') . '.');
            redirect('yapi.php?bina=' . $binaSec);
        }
        if ($islem === 'oda_ekle') {
            if (!$binaSec) throw new RuntimeException('Bina seçin.');
            $ad = trim((string)($_POST['ad'] ?? ''));
            if ($ad === '') throw new RuntimeException('Oda adı boş olamaz.');
            $tur = isset(KAMP_ODA_TUR[$_POST['tur'] ?? '']) ? $_POST['tur'] : 'yatak';
            $st = $pdoKamp->prepare("SELECT ad FROM kamp_odalar WHERE bina_id=?"); $st->execute([$binaSec]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) if (kamp_norm($a) === kamp_norm($ad)) throw new RuntimeException('Bu binada aynı adlı oda var.');
            $konum = in_array($_POST['konum'] ?? '', ['sol','orta','sag'], true) ? $_POST['konum'] : null;
            $sira = (int)$pdoKamp->query("SELECT COALESCE(MAX(sira),0)+1 FROM kamp_odalar WHERE bina_id=$binaSec")->fetchColumn();
            $pdoKamp->beginTransaction();
            $pdoKamp->prepare("INSERT INTO kamp_odalar (bina_id, kat, unite, konum, ad, tur, aciklama, sira) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$binaSec, (int)($_POST['kat'] ?? 0), trim((string)($_POST['unite'] ?? '')) ?: null, $konum, $ad, $tur,
                           trim((string)($_POST['aciklama'] ?? '')) ?: null, $sira]);
            $oid = (int)$pdoKamp->lastInsertId();
            $n = kamp_oda_yatakli($tur) ? kamp_yatak_ekle($pdoKamp, $oid, (int)($_POST['yatak'] ?? 0), $_POST['yatak_tur'] ?? 'tek') : 0;
            $pdoKamp->commit();
            kamp_audit('kamp_odalar', $oid, 'INSERT', null, ['ad' => $ad, 'tur' => $tur, 'yatak' => $n]);
            flash('success', "Oda eklendi ($n yatak).");
            redirect('yapi.php?bina=' . $binaSec . '#oda-' . $oid);
        }
        if ($islem === 'odalar_kaydet') {
            $degisen = 0; $hata = [];
            foreach ((array)($_POST['oda'] ?? []) as $oid => $v) {
                $oid = (int)$oid;
                $st = $pdoKamp->prepare("SELECT o.*, (SELECT COUNT(*) FROM kamp_yataklar y WHERE y.oda_id=o.id AND y.aktif=1) AS yatak
                                           FROM kamp_odalar o WHERE o.id=? AND o.bina_id=?");
                $st->execute([$oid, $binaSec]);
                $o = $st->fetch(); if (!$o) continue;
                $ad = trim((string)($v['ad'] ?? $o['ad'])); $tur = isset(KAMP_ODA_TUR[$v['tur'] ?? '']) ? $v['tur'] : $o['tur'];
                $yatak = kamp_oda_yatakli($tur) ? max(0, (int)($v['yatak'] ?? $o['yatak'])) : 0;
                $aktif = !empty($v['aktif']) ? 1 : 0;
                $unite = trim((string)($v['unite'] ?? $o['unite']));
                if ($ad === $o['ad'] && $tur === $o['tur'] && $yatak === (int)$o['yatak'] && $aktif === (int)$o['aktif'] && $unite === (string)$o['unite']) continue;
                try {
                    $pdoKamp->beginTransaction();
                    if ($ad === '') throw new RuntimeException('ad boş');
                    if (kamp_norm($ad) !== kamp_norm($o['ad'])) {
                        $c = $pdoKamp->prepare("SELECT COUNT(*) FROM kamp_odalar WHERE bina_id=? AND id<>? AND ad=?");
                        $c->execute([$binaSec, $oid, $ad]);
                        if ($c->fetchColumn()) throw new RuntimeException('aynı adlı oda var');
                    }
                    if (!$aktif || !kamp_oda_yatakli($tur)) {
                        $c = $pdoKamp->prepare("SELECT COUNT(*) FROM kamp_konaklama k JOIN kamp_yataklar y ON y.id=k.yatak_id WHERE y.oda_id=? AND k.cikis IS NULL");
                        $c->execute([$oid]);
                        if ($n = (int)$c->fetchColumn()) throw new RuntimeException("odada $n kişi kalıyor — önce taşıyın / çıkış yapın");
                    }
                    $pdoKamp->prepare("UPDATE kamp_odalar SET ad=?, tur=?, aktif=?, unite=? WHERE id=?")->execute([$ad, $tur, $aktif, $unite ?: null, $oid]);
                    if ($yatak !== (int)$o['yatak']) kamp_yatak_sayisi_ayarla($pdoKamp, $oid, $yatak, $v['yatak_tur'] ?? 'tek');
                    $pdoKamp->commit();
                    kamp_audit('kamp_odalar', $oid, 'UPDATE', ['ad'=>$o['ad'],'tur'=>$o['tur'],'yatak'=>(int)$o['yatak'],'aktif'=>(int)$o['aktif']],
                               ['ad'=>$ad,'tur'=>$tur,'yatak'=>$yatak,'aktif'=>$aktif]);
                    $degisen++;
                } catch (Throwable $e) {
                    if ($pdoKamp->inTransaction()) $pdoKamp->rollBack();
                    $hata[] = $o['ad'] . ': ' . $e->getMessage();
                }
            }
            if ($degisen) flash('success', "$degisen oda güncellendi.");
            if ($hata) flash('error', 'Kaydedilemeyen: ' . implode(' · ', $hata));
            if (!$degisen && !$hata) flash('info', 'Değişiklik yok.');
            redirect('yapi.php?bina=' . $binaSec);
        }
        if ($islem === 'oda_sil') {
            $oid = (int)$_POST['oda_id'];
            $c = $pdoKamp->prepare("SELECT (SELECT COUNT(*) FROM kamp_konaklama WHERE oda_id=?) + (SELECT COUNT(*) FROM kamp_konaklama k JOIN kamp_yataklar y ON y.id=k.yatak_id WHERE y.oda_id=?) AS kon,
                                           (SELECT COUNT(*) FROM kamp_demirbas WHERE oda_id=?) AS db");
            $c->execute([$oid, $oid, $oid]); $c = $c->fetch();
            if ((int)$c['kon']) throw new RuntimeException('Bu odada konaklama geçmişi var — silinmez, pasife alın (geçmiş kopmasın).');
            if ((int)$c['db'])  throw new RuntimeException('Odada ' . (int)$c['db'] . ' demirbaş kayıtlı — önce demirbaşları taşıyın ya da silin.');
            $pdoKamp->beginTransaction();
            $pdoKamp->prepare("DELETE FROM kamp_yataklar WHERE oda_id=?")->execute([$oid]);
            $pdoKamp->prepare("DELETE FROM kamp_odalar WHERE id=?")->execute([$oid]);
            $pdoKamp->commit();
            kamp_audit('kamp_odalar', $oid, 'DELETE');
            flash('success', 'Oda silindi.');
            redirect('yapi.php?bina=' . $binaSec);
        }
    } catch (Throwable $e) {
        if ($pdoKamp->inTransaction()) $pdoKamp->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('yapi.php' . ($binaSec ? '?bina=' . $binaSec : ''));
}

$binalar = kamp_binalar($pdoKamp, true);
$doluluk = kamp_doluluk($pdoKamp);
if ($binaSec && !isset($binalar[$binaSec])) $binaSec = 0;
$bina = $binaSec ? $binalar[$binaSec] : null;
$odalar = [];
if ($bina) {
    $st = $pdoKamp->prepare("SELECT o.*,
              (SELECT COUNT(*) FROM kamp_yataklar y WHERE y.oda_id=o.id AND y.aktif=1) AS yatak,
              (SELECT COUNT(*) FROM kamp_yataklar y WHERE y.oda_id=o.id AND y.aktif=0) AS disi,
              (SELECT COUNT(*) FROM kamp_konaklama k JOIN kamp_yataklar y ON y.id=k.yatak_id WHERE y.oda_id=o.id AND k.cikis IS NULL) AS dolu,
              (SELECT MIN(y.tur) FROM kamp_yataklar y WHERE y.oda_id=o.id AND y.aktif=1) AS ytur,
              (SELECT COUNT(*) FROM kamp_demirbas d WHERE d.oda_id=o.id) AS db
         FROM kamp_odalar o WHERE o.bina_id=? ORDER BY o.kat, o.sira, o.ad");
    $st->execute([$binaSec]);
    foreach ($st->fetchAll() as $o) $odalar[(int)$o['kat']][] = $o;
}

require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-building-gear text-primary me-2"></i>Kamp Yapısı</h4>
  <span class="text-muted small">bina → kat → konteyner → oda → yatak</span>
  <a href="yerlesim.php<?= $binaSec ? '?bina=' . $binaSec : '' ?>" class="btn btn-outline-primary btn-sm ms-auto"><i class="bi bi-grid-3x3-gap me-1"></i>Krokiye dön</a>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex align-items-center"><strong>Binalar</strong><span class="badge bg-secondary ms-2"><?= count($binalar) ?></span>
        <?php if ($duz): ?><a href="yapi.php?yeni=1" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-plus-lg"></i> Bina</a><?php endif; ?></div>
      <div class="list-group list-group-flush">
        <?php if (!$binalar): ?><div class="list-group-item text-muted small">Henüz bina yok — sağ üstten ekleyin.</div><?php endif; ?>
        <?php foreach ($binalar as $id => $b): $d = $doluluk[$id] ?? ['yatak'=>0,'dolu'=>0,'oda'=>0]; ?>
        <a href="yapi.php?bina=<?= $id ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $binaSec === $id ? 'active' : '' ?> <?= (int)$b['aktif'] ? '' : 'opacity-50' ?>">
          <i class="bi <?= KAMP_BINA_TUR[$b['tur']][1] ?? 'bi-building' ?>"></i>
          <div class="me-auto"><div class="fw-semibold"><?= h($b['ad']) ?><?= (int)$b['aktif'] ? '' : ' <span class="badge bg-secondary">pasif</span>' ?></div>
            <div class="small <?= $binaSec === $id ? '' : 'text-muted' ?>"><?= h(KAMP_BINA_TUR[$b['tur']][0] ?? $b['tur']) ?></div></div>
          <span class="badge <?= $binaSec === $id ? 'bg-light text-dark' : 'bg-secondary' ?>"><?= (int)($d['dolu'] ?? 0) ?>/<?= (int)($d['yatak'] ?? 0) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($duz && ($bina || isset($_GET['yeni']) || !$binalar)): $fb = isset($_GET['yeni']) || !$bina ? null : $bina; ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white"><strong><?= $fb ? 'Binayı düzenle' : 'Yeni bina' ?></strong></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="islem" value="bina_kaydet"><input type="hidden" name="id" value="<?= (int)($fb['id'] ?? 0) ?>">
          <div class="mb-2"><label class="form-label small">Bina adı *</label><input name="ad" class="form-control" required value="<?= h($fb['ad'] ?? '') ?>" placeholder="ör. Staff Binası / A Blok / Formen Yatakhanesi"></div>
          <div class="mb-2"><label class="form-label small">Tür</label>
            <select name="tur" class="form-select"><?php foreach (KAMP_BINA_TUR as $k => [$ad]): ?><option value="<?= $k ?>"<?= ($fb['tur'] ?? 'kogus') === $k ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small">Kısa kod</label><input name="kod" class="form-control" value="<?= h($fb['kod'] ?? '') ?>" maxlength="20" placeholder="ör. STF"></div>
            <div class="col-6"><label class="form-label small">Sıra</label><input type="number" name="sira" class="form-control" value="<?= (int)($fb['sira'] ?? 0) ?>"></div>
          </div>
          <div class="mb-2"><label class="form-label small">Açıklama</label><input name="aciklama" class="form-control" value="<?= h($fb['aciklama'] ?? '') ?>"></div>
          <?php if ($fb): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="aktif" value="1" id="bAktif" <?= (int)$fb['aktif'] ? 'checked' : '' ?>><label class="form-check-label small" for="bAktif">Aktif (pasif bina krokide ve sayımlarda görünmez)</label></div><?php endif; ?>
          <button class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Kaydet</button>
        </form>
        <?php if ($fb && !$odalar): ?>
        <form method="post" class="mt-2" onsubmit="return confirm('Bina silinsin mi?')"><input type="hidden" name="islem" value="bina_sil"><input type="hidden" name="id" value="<?= (int)$fb['id'] ?>"><input type="hidden" name="bina_id" value="<?= (int)$fb['id'] ?>">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Binayı sil</button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
  <?php if (!$bina): ?>
    <div class="alert alert-info">Soldan bir bina seçin ya da yeni bina ekleyin. Örnek düzen:
      <ul class="mb-0 mt-1 small">
        <li><strong>Staff Binası</strong> (mühendis / teknik ofis) — 2 kat, katta 8 konteyner, her konteyner ikiye bölünmüş: sol oda · ortak banyo/WC · sağ oda</li>
        <li><strong>A / B / C Blok</strong> — 3 katlı, 16 kişilik ranzalı koğuşlar</li>
        <li><strong>Formen Yatakhanesi</strong> · <strong>Sosyal alan</strong> (çamaşırhane, yemekhane, depo)</li>
      </ul></div>
  <?php else: ?>
    <?php if ($duz): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white"><strong><i class="bi bi-magic me-1"></i>Hızlı yapı üretici — <?= h($bina['ad']) ?></strong></div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3 small">
          <span class="text-muted">Hazır düzen:</span>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-hazir='{"duzen":"konteyner","katlar":"0,1","unite":8,"unite_oda":2,"ortak":1,"yatak":1,"yatak_tur":"tek"}'>Staff: 2 kat × 8 konteyner × 2 oda + ortak banyo/WC</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-hazir='{"duzen":"kogus","katlar":"0,1,2","oda":4,"yatak":16,"yatak_tur":"ranza2","oda_tur":"kogus"}'>Ranzalı blok: 3 kat × 16 kişilik koğuş</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-hazir='{"duzen":"kogus","katlar":"0","oda":1,"yatak":20,"yatak_tur":"ranza2","oda_tur":"kogus"}'>Formen yatakhanesi</button>
        </div>
        <form method="post" id="fUret">
          <input type="hidden" name="islem" value="uret"><input type="hidden" name="bina_id" value="<?= $binaSec ?>">
          <div class="mb-2">
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="duzen" value="konteyner" id="dzK" checked><label class="form-check-label" for="dzK">Konteyner düzeni (ünite → sol/sağ oda)</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="duzen" value="kogus" id="dzO"><label class="form-check-label" for="dzO">Oda / koğuş düzeni</label></div>
          </div>
          <div class="row g-2">
            <div class="col-md-3"><label class="form-label small">Katlar</label><input name="katlar" class="form-control" value="0,1" placeholder="0,1,2"><div class="form-text">0 = zemin, 1 = 1. kat…</div></div>
            <div class="col-md-3 dz-k"><label class="form-label small">Katta konteyner</label><input type="number" name="unite" class="form-control" value="8" min="1" max="60"></div>
            <div class="col-md-3 dz-k"><label class="form-label small">Konteynerde oda</label>
              <select name="unite_oda" class="form-select"><option value="2" selected>2 (sol A · sağ B)</option><option value="1">1 (bölünmemiş)</option></select></div>
            <div class="col-md-3 dz-k d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="ortak" value="1" id="ortak" checked><label class="form-check-label small" for="ortak">Ortak banyo / WC alanı aç</label></div></div>
            <div class="col-md-3 dz-o d-none"><label class="form-label small">Katta oda / koğuş</label><input type="number" name="oda" class="form-control" value="4" min="1" max="120"></div>
            <div class="col-md-3 dz-o d-none"><label class="form-label small">Oda türü</label>
              <select name="oda_tur" class="form-select"><option value="kogus">Koğuş</option><option value="yatak">Yatak odası</option></select></div>
            <div class="col-md-3"><label class="form-label small">Oda başına yatak</label><input type="number" name="yatak" class="form-control" value="1" min="0" max="40"></div>
            <div class="col-md-3"><label class="form-label small">Yatak türü</label>
              <select name="yatak_tur" class="form-select"><option value="tek">Tek kişilik</option><option value="ranza2">Ranza (alt / üst)</option><option value="ranza3">Üç katlı ranza</option></select></div>
            <div class="col-md-3"><label class="form-label small">İlk numara</label><input type="number" name="bas" class="form-control" value="1" min="1"></div>
          </div>
          <div class="form-text mb-2">Odalar <code>Z-01A</code>, <code>Z-01B</code>, <code>1-03A</code> (konteyner) ya da <code>Z-01</code>, <code>2-04</code> (koğuş) diye adlanır.
            Aynı adlı oda varsa atlanır — üreticiyi tekrar çalıştırmak mükerrer açmaz. Üretimden sonra çamaşırhane / depo olan odaların türünü aşağıdaki tablodan değiştirin.</div>
          <button class="btn btn-primary btn-sm"><i class="bi bi-magic me-1"></i>Odaları ve yatakları oluştur</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="islem" value="odalar_kaydet"><input type="hidden" name="bina_id" value="<?= $binaSec ?>">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex align-items-center gap-2"><strong>Odalar — <?= h($bina['ad']) ?></strong>
        <?php if ($duz && $odalar): ?><button class="btn btn-success btn-sm ms-auto"><i class="bi bi-check2-all me-1"></i>Değişiklikleri kaydet</button><?php endif; ?></div>
      <?php if (!$odalar): ?><div class="card-body text-muted small">Bu binada henüz oda yok — yukarıdaki üreticiyi kullanın ya da aşağıdan tek tek ekleyin.</div><?php endif; ?>
      <?php foreach ($odalar as $kat => $liste): ?>
      <div class="px-3 pt-2 fw-semibold small text-uppercase text-muted"><?= h(kamp_kat_ad((int)$kat)) ?></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.85rem">
        <thead class="table-light"><tr><th>Oda</th><th>Konteyner</th><th>Tür</th><th style="width:90px">Yatak</th><th>Dolu</th><th>Demirbaş</th><th>Aktif</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($liste as $o): $ad = "oda[{$o['id']}]"; ?>
          <tr id="oda-<?= (int)$o['id'] ?>" class="<?= (int)$o['aktif'] ? '' : 'opacity-50' ?>">
            <td><?php if ($duz): ?><input name="<?= $ad ?>[ad]" class="form-control form-control-sm" value="<?= h($o['ad']) ?>" style="width:120px"><?php else: ?><?= h($o['ad']) ?><?php endif; ?></td>
            <td><?php if ($duz): ?><input name="<?= $ad ?>[unite]" class="form-control form-control-sm" value="<?= h($o['unite']) ?>" style="width:80px"><?php else: ?><?= h($o['unite'] ?: '—') ?><?php endif; ?>
                <?php if ($o['konum']): ?><span class="small text-muted"><?= ['sol'=>'sol','orta'=>'ortak','sag'=>'sağ'][$o['konum']] ?? '' ?></span><?php endif; ?></td>
            <td><?php if ($duz): ?><select name="<?= $ad ?>[tur]" class="form-select form-select-sm" style="width:150px">
                  <?php foreach (KAMP_ODA_TUR as $k => [$tad]): ?><option value="<?= $k ?>"<?= $o['tur'] === $k ? ' selected' : '' ?>><?= h($tad) ?></option><?php endforeach; ?></select>
                <?php else: ?><?= h(KAMP_ODA_TUR[$o['tur']][0] ?? $o['tur']) ?><?php endif; ?></td>
            <td><?php if ($duz): ?><input type="number" min="0" max="60" name="<?= $ad ?>[yatak]" class="form-control form-control-sm" value="<?= (int)$o['yatak'] ?>">
                <input type="hidden" name="<?= $ad ?>[yatak_tur]" value="<?= str_starts_with((string)$o['ytur'], 'ranza') ? 'ranza2' : 'tek' ?>"><?php else: ?><?= (int)$o['yatak'] ?><?php endif; ?>
                <?php if ((int)$o['disi']): ?><div class="small text-muted">+<?= (int)$o['disi'] ?> kullanım dışı</div><?php endif; ?></td>
            <td><?= (int)$o['dolu'] ? '<span class="badge bg-primary">' . (int)$o['dolu'] . '</span>' : '<span class="text-muted">—</span>' ?></td>
            <td><a href="demirbas.php?oda=<?= (int)$o['id'] ?>" class="text-decoration-none"><?= (int)$o['db'] ?: '—' ?></a></td>
            <td><?php if ($duz): ?><input type="checkbox" class="form-check-input" name="<?= $ad ?>[aktif]" value="1" <?= (int)$o['aktif'] ? 'checked' : '' ?>><?php else: ?><?= (int)$o['aktif'] ? '✓' : '—' ?><?php endif; ?></td>
            <td class="text-end text-nowrap"><a href="oda.php?id=<?= (int)$o['id'] ?>" class="btn btn-outline-secondary btn-sm py-0" title="Oda kartı"><i class="bi bi-box-arrow-up-right"></i></a>
              <?php if ($duz && !(int)$o['dolu']): ?><button type="submit" form="fSil" name="oda_id" value="<?= (int)$o['id'] ?>" class="btn btn-outline-danger btn-sm py-0" title="Odayı sil" onclick="return confirm('<?= h($o['ad']) ?> silinsin mi? (Geçmişi varsa silinmez, pasife alın)')"><i class="bi bi-trash"></i></button><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endforeach; ?>
      <?php if ($duz && $odalar): ?><div class="card-footer bg-white small text-muted">Tür "Çamaşırhane / Depo / Ortak…" yapılan odanın boş yatakları kaldırılır (geçmişi olan yatak "kullanım dışı" olur);
        yatak sayısı azaltılırsa yalnız BOŞ yataklar kaldırılır. İçinde kalan olan odada bu işlemler engellenir.</div><?php endif; ?>
    </div>
    </form>
    <form method="post" id="fSil"><input type="hidden" name="islem" value="oda_sil"><input type="hidden" name="bina_id" value="<?= $binaSec ?>"></form>

    <?php if ($duz): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white"><strong><i class="bi bi-plus-lg me-1"></i>Tek oda ekle</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="islem" value="oda_ekle"><input type="hidden" name="bina_id" value="<?= $binaSec ?>">
          <div class="col-md-2"><label class="form-label small">Kat</label><input type="number" name="kat" class="form-control form-control-sm" value="0"></div>
          <div class="col-md-2"><label class="form-label small">Oda adı *</label><input name="ad" class="form-control form-control-sm" required placeholder="ör. ÇAMAŞIRHANE"></div>
          <div class="col-md-2"><label class="form-label small">Konteyner</label><input name="unite" class="form-control form-control-sm" placeholder="ör. Z-05"></div>
          <div class="col-md-2"><label class="form-label small">Konum</label><select name="konum" class="form-select form-select-sm"><option value="">—</option><option value="sol">Sol</option><option value="orta">Orta (ortak)</option><option value="sag">Sağ</option></select></div>
          <div class="col-md-2"><label class="form-label small">Tür</label><select name="tur" class="form-select form-select-sm"><?php foreach (KAMP_ODA_TUR as $k => [$tad]): ?><option value="<?= $k ?>"><?= h($tad) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-1"><label class="form-label small">Yatak</label><input type="number" name="yatak" class="form-control form-control-sm" value="1" min="0" max="40"></div>
          <div class="col-md-1"><select name="yatak_tur" class="form-select form-select-sm" title="Yatak türü"><option value="tek">Tek</option><option value="ranza2">Ranza</option><option value="ranza3">3'lü</option></select></div>
          <div class="col-12"><button class="btn btn-primary btn-sm">Ekle</button></div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var f = document.getElementById('fUret'); if (!f) return;
  function duzen(){ var k = f.querySelector('[name=duzen]:checked').value === 'konteyner';
    f.querySelectorAll('.dz-k').forEach(function(e){ e.classList.toggle('d-none', !k); });
    f.querySelectorAll('.dz-o').forEach(function(e){ e.classList.toggle('d-none', k); }); }
  f.querySelectorAll('[name=duzen]').forEach(function(r){ r.addEventListener('change', duzen); });
  document.querySelectorAll('[data-hazir]').forEach(function(b){ b.addEventListener('click', function(){
    var v = JSON.parse(b.getAttribute('data-hazir'));
    Object.keys(v).forEach(function(k){
      if (k === 'duzen') { f.querySelector('[name=duzen][value="' + v[k] + '"]').checked = true; return; }
      var el = f.querySelector('[name="' + k + '"]'); if (!el) return;
      if (el.type === 'checkbox') el.checked = !!v[k]; else el.value = v[k];
    });
    if (v.ortak === undefined) { var o = f.querySelector('[name=ortak]'); if (o) o.checked = false; }
    duzen(); f.scrollIntoView({behavior:'smooth', block:'center'});
  }); });
  duzen();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
