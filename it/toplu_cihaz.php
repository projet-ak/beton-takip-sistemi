<?php
/**
 * it/toplu_cihaz.php — TOPLU CİHAZ EKLEME (aynı modelden N adet)
 *
 * İş gerçeği (2026-09-17, kullanıcı: "50'ye yakın kullanıcıda var: usb bellek, klavye,
 * mouse, kulaklık, taşınabilir disk"): küçük donanım aynı modelden onlarca adet alınır ve
 * kişi kişi dağıtılır. Cihaz formuyla tek tek açmak 50 × 10 tıklama demekti; bu yüzden
 * envantere hiç girilmiyor ve "kimde ne var" bilinmiyordu.
 *
 * ⚠ HER CİHAZ AYRI SATIRDIR — adet kolonuna yazılmaz. Zimmet kişiye bağlanır, yaşam günlüğü
 * ve imzalı tutanak cihaz bazında tutulur; "10 mouse" tek satır olsaydı hiçbiri yapılamazdı.
 * Aynı modelden onlarca adet olması §"FARKLI IFS + FARKLI SERİ = FARKLI CİHAZ" kuralının
 * doğal sonucudur — seri no girilirse satır satır girilir, girilmezse boş kalır.
 *
 * İki mod: kişilere dağıt (seçilen her personele BİR cihaz) · depoya N adet aç.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';

it_semasi_kur($pdoIt);
$pageTitle = 'Toplu Cihaz Ekleme';
$maliGoster = it_mali_goster();

/** Bir seferde açılabilecek en fazla kayıt — yanlış girilen adet envanteri şişirmesin. */
const TC_AZAMI = 200;

$rapor = null; $hata = null;
$g = ['kategori' => $_GET['kategori'] ?? 'mouse'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && yetki_var('giris')) {
    $kategori = (string)($_POST['kategori'] ?? '');
    $ortak = [
        'ad'        => trim((string)($_POST['ad'] ?? '')),
        'marka'     => trim((string)($_POST['marka'] ?? '')),
        'model'     => trim((string)($_POST['model'] ?? '')),
        'kapasite'  => trim((string)($_POST['kapasite'] ?? '')),
        'tedarikci' => trim((string)($_POST['tedarikci'] ?? '')),
        'fatura_no' => trim((string)($_POST['fatura_no'] ?? '')),
        'notlar'    => trim((string)($_POST['notlar'] ?? '')),
    ];
    $alis   = it_tarih($_POST['alis_tarihi'] ?? '');
    $lokId  = (int)($_POST['lokasyon_id'] ?? 0) ?: null;
    $seriler = preg_split('/\r\n|\r|\n/', (string)($_POST['seriler'] ?? '')) ?: [];
    $seriler = array_values(array_filter(array_map('trim', $seriler), fn($x) => $x !== ''));

    // Fiyat künyesi (adet BAŞINA) — para birimi/kur çözümü cihaz formuyla aynı çekirdekten
    $fk = it_fiyat_coz($_POST['fiyat'] ?? '', $_POST['para_birimi'] ?? 'TRY', $_POST['kur'] ?? '', $_POST['fiyat_tl'] ?? '');

    $mod = ($_POST['mod'] ?? 'kisi') === 'depo' ? 'depo' : 'kisi';
    $kisiler = array_values(array_unique(array_map('intval', (array)($_POST['personel_id'] ?? []))));
    $kisiler = array_values(array_filter($kisiler));
    $adet = $mod === 'depo' ? max(1, (int)($_POST['adet'] ?? 1)) : count($kisiler);

    if (!isset(IT_KATEGORI[$kategori]))        $hata = 'Cihaz tipi seçilmedi.';
    elseif ($ortak['ad'] === '')               $hata = 'Cihaz adı zorunlu (ör. "Kablosuz Mouse").';
    elseif ($adet < 1)                         $hata = $mod === 'depo' ? 'Adet en az 1 olmalı.' : 'En az bir personel seçin.';
    elseif ($adet > TC_AZAMI)                  $hata = 'Tek seferde en fazla ' . TC_AZAMI . ' kayıt açılabilir (' . $adet . ' istendi).';
    elseif ($seriler && count($seriler) !== $adet)
        $hata = 'Seri no listesi ' . count($seriler) . ' satır, açılacak kayıt ' . $adet . ' adet — ya boş bırakın ya da birebir aynı sayıda olsun.';

    if (!$hata) {
        $acilan = []; $atlanan = [];
        try {
            $pdoIt->beginTransaction();
            for ($i = 0; $i < $adet; $i++) {
                $y = $ortak + [
                    'kategori'      => $kategori,
                    'envanter_no'   => it_envanter_no($pdoIt),
                    'seri_no'       => $seriler[$i] ?? null,
                    'alis_tarihi'   => $alis ?: null,
                    'lokasyon_id'   => $lokId,
                    'durum'         => 'depoda',
                    'personel_id'   => null,
                    'zimmet_tarihi' => null,
                    'fiyat'         => $fk['fiyat'], 'para_birimi' => $fk['para_birimi'],
                    'kur'           => $fk['kur'],   'fiyat_tl'    => $fk['fiyat_tl'],
                ];
                if ($mod === 'kisi') {
                    $y['personel_id']   = $kisiler[$i];
                    $y['durum']         = 'aktif';
                    $y['zimmet_tarihi'] = date('Y-m-d');
                }
                it_cihaz_bag_esitle($pdoIt, $y);   // personel_id → zimmetli / departman / lokasyon metni
                $y = array_filter($y, fn($v) => $v !== '' && $v !== null);
                $sql = "INSERT INTO it_cihazlar (" . implode(',', array_keys($y)) . ") VALUES ("
                     . implode(',', array_fill(0, count($y), '?')) . ")";
                $pdoIt->prepare($sql)->execute(array_values($y));
                $id = (int)$pdoIt->lastInsertId();

                it_hareket_ekle($pdoIt, $id, 'giris', $ortak['tedarikci'] ?: null,
                    'Toplu kayıt' . ($ortak['fatura_no'] ? ' — fatura ' . $ortak['fatura_no'] : ''), $alis ?: null);
                if ($mod === 'kisi')
                    it_hareket_ekle($pdoIt, $id, 'zimmet', $y['zimmetli'] ?? null, 'Toplu kayıtla zimmetlendi');

                $acilan[] = ['id' => $id, 'envanter_no' => $y['envanter_no'],
                             'seri_no' => $y['seri_no'] ?? '', 'kisi' => $y['zimmetli'] ?? ''];
            }
            $pdoIt->commit();
            audit_log($pdoIt, 'it_cihazlar', 0, 'TOPLU_EKLE', null,
                ['kategori' => $kategori, 'ad' => $ortak['ad'], 'adet' => count($acilan), 'mod' => $mod],
                current_user_id());
            $rapor = ['acilan' => $acilan, 'atlanan' => $atlanan, 'kategori' => $kategori, 'mod' => $mod];
        } catch (Throwable $e) {
            if ($pdoIt->inTransaction()) $pdoIt->rollBack();
            $hata = $e->getMessage() . ' — hiçbir kayıt açılmadı.';
        }
    }
    $g['kategori'] = $kategori;
}

$personeller = it_personel_liste($pdoIt, true);
// Kişi başına mevcut cihaz sayısı — "bu kişide zaten mouse var mı" görünsün
$mevcut = [];
try {
    $st = $pdoIt->query("SELECT personel_id, kategori, COUNT(*) n FROM it_cihazlar
                         WHERE personel_id IS NOT NULL AND " . it_envanterde() . "
                         GROUP BY personel_id, kategori");
    foreach ($st->fetchAll() as $r) $mevcut[(int)$r['personel_id']][$r['kategori']] = (int)$r['n'];
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-boxes text-primary me-2"></i>Toplu Cihaz Ekleme</h4>
    <div class="ms-auto d-flex gap-2">
        <a href="cihazlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul me-1"></i>Cihaz Listesi</a>
        <a href="cihaz_form.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Tek Cihaz</a>
    </div>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i><?= h($hata) ?></div>
<?php endif; ?>

<?php if ($rapor): $k = IT_KATEGORI[$rapor['kategori']] ?? ['Cihaz']; ?>
<div class="alert alert-success">
    <div class="fw-semibold"><i class="bi bi-check-circle me-1"></i>
        <?= $f0(count($rapor['acilan'])) ?> adet <?= h($k[0]) ?> envantere eklendi
        <?= $rapor['mod'] === 'kisi' ?'ve kişilere zimmetlendi' :'ve depoya alındı' ?>.</div>
    <div class="table-responsive mt-2">
    <table class="table table-sm table-bordered bg-white mb-0" style="font-size:.82rem">
        <thead class="table-light"><tr><th>#</th><th>Envanter No</th><th>Seri No</th><th>Zimmetli</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rapor['acilan'] as $i => $a): ?>
            <tr><td><?= $i + 1 ?></td><td><?= h($a['envanter_no']) ?></td>
                <td><?= h($a['seri_no']) ?: '<span class="text-muted">—</span>' ?></td>
                <td><?= h($a['kisi']) ?: '<span class="text-muted">depoda</span>' ?></td>
                <td><a href="cihaz_detay.php?id=<?= (int)$a['id'] ?>" class="small">kart</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="small mt-2">
        Zimmet tutanaklarını kişi bazında tek belgede yazdırmak için
        <a href="personel.php" class="alert-link">Personel</a> ekranından kişiyi açıp
        “Zimmet Tutanağı”nı kullanın — kişinin TÜM cihazları tek tutanakta çıkar.
    </div>
</div>
<?php endif; ?>

<form method="post" class="card border-0 shadow-sm mb-3"><div class="card-body">
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Cihaz tipi</label>
            <select name="kategori" id="tcKat" class="form-select form-select-sm" required>
                <?php foreach (it_kategori_agaci() as $grup => $kats): if (!$kats) continue; ?>
                    <optgroup label="<?= h(IT_GRUP[$grup][0] ?? $grup) ?>">
                    <?php foreach ($kats as $ka => $ad): ?>
                        <option value="<?= h($ka) ?>"<?= $g['kategori'] === $ka ? ' selected' : '' ?>><?= h($ad) ?></option>
                    <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label small fw-semibold">Cihaz adı</label>
            <input type="text" name="ad" class="form-control form-control-sm" required
                   placeholder="ör. Kablosuz Mouse · USB Bellek 32 GB" value="<?= h($_POST['ad'] ?? '') ?>">
            <div class="form-text">Her kayıtta aynı yazılır; kişiye özel bilgi seri no sütunundan gelir.</div>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Marka</label>
            <select name="marka" class="form-select form-select-sm">
                <?= it_tanim_options($pdoIt, 'uretici', $_POST['marka'] ?? null) ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Model</label>
            <input type="text" name="model" class="form-control form-control-sm" value="<?= h($_POST['model'] ?? '') ?>">
        </div>

        <div class="col-md-2">
            <label class="form-label small">Kapasite</label>
            <input type="text" name="kapasite" class="form-control form-control-sm"
                   placeholder="1 TB · 32 GB" value="<?= h($_POST['kapasite'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Alış tarihi</label>
            <input type="date" name="alis_tarihi" class="form-control form-control-sm" value="<?= h($_POST['alis_tarihi'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Tedarikçi</label>
            <input type="text" name="tedarikci" class="form-control form-control-sm" value="<?= h($_POST['tedarikci'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Fatura No</label>
            <input type="text" name="fatura_no" class="form-control form-control-sm" value="<?= h($_POST['fatura_no'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Lokasyon</label>
            <select name="lokasyon_id" class="form-select form-select-sm">
                <?= it_lokasyon_options($pdoIt, (int)($_POST['lokasyon_id'] ?? 0) ?: null) ?>
            </select>
        </div>

        <?php if ($maliGoster): ?>
        <div class="col-md-2">
            <label class="form-label small">Alış tutarı <span class="text-muted">(adet başına)</span></label>
            <input type="text" name="fiyat" class="form-control form-control-sm" value="<?= h($_POST['fiyat'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Para birimi</label>
            <select name="para_birimi" class="form-select form-select-sm">
                <?php foreach (IT_PARA as $pk => $pv): ?>
                    <option value="<?= h($pk) ?>"<?= ($_POST['para_birimi'] ?? 'TRY') === $pk ? ' selected' : '' ?>><?= h($pv[0]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Kur <span class="text-muted">(alış günü)</span></label>
            <input type="text" name="kur" class="form-control form-control-sm" value="<?= h($_POST['kur'] ?? '') ?>">
        </div>
        <?php endif; ?>
        <div class="col-md-<?= $maliGoster ? 6 : 12 ?>">
            <label class="form-label small">Not (hepsine yazılır)</label>
            <input type="text" name="notlar" class="form-control form-control-sm" value="<?= h($_POST['notlar'] ?? '') ?>">
        </div>
    </div>

    <div class="border-top mt-3 pt-3">
        <div class="btn-group btn-group-sm mb-3" role="group">
            <input type="radio" class="btn-check" name="mod" id="tcModKisi" value="kisi" checked>
            <label class="btn btn-outline-primary" for="tcModKisi"><i class="bi bi-people me-1"></i>Kişilere dağıt</label>
            <input type="radio" class="btn-check" name="mod" id="tcModDepo" value="depo">
            <label class="btn btn-outline-primary" for="tcModDepo"><i class="bi bi-box-seam me-1"></i>Depoya N adet</label>
        </div>

        <div id="tcDepo" class="d-none row g-2 align-items-end mb-2">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Adet</label>
                <input type="number" name="adet" class="form-control form-control-sm" min="1" max="<?= TC_AZAMI ?>" value="10">
                <div class="form-text">Her adet AYRI kayıt olur (kendi envanter no'su ve zimmet geçmişiyle).</div>
            </div>
        </div>

        <div id="tcKisi">
            <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                <strong class="small">Cihaz verilecek personel</strong>
                <span class="badge bg-primary" id="tcSayac">0 kişi</span>
                <input type="search" id="tcAra" class="form-control form-control-sm ms-auto" style="max-width:260px"
                       placeholder="ad, sicil, birim ara…">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="tcHepsi">Görünenleri seç</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="tcTemizle">Temizle</button>
            </div>
            <div class="border rounded" style="max-height:340px;overflow:auto">
                <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                    <tbody id="tcListe">
                    <?php foreach ($personeller as $p):
                        $ara = it_norm(($p['ad'] ?? '') . ' ' . ($p['soyad'] ?? '') . ' ' . ($p['sicil_no'] ?? '')
                                     . ' ' . ($p['unvan'] ?? '') . ' ' . ($p['birim'] ?? '')); ?>
                        <tr data-ara="<?= h($ara) ?>">
                            <td style="width:34px">
                                <input class="form-check-input tc-kisi" type="checkbox"
                                       name="personel_id[]" value="<?= (int)$p['id'] ?>">
                            </td>
                            <td>
                                <span class="fw-semibold"><?= h(it_personel_ad($p)) ?></span>
                                <?php if (!empty($p['sicil_no'])): ?><span class="text-muted ms-1">#<?= h($p['sicil_no']) ?></span><?php endif; ?>
                                <?php if (!empty($p['birim'])): ?><span class="badge bg-light text-dark border ms-1"><?= h($p['birim']) ?></span><?php endif; ?>
                            </td>
                            <td class="text-end text-muted" style="width:150px">
                                <?php $m = $mevcut[(int)$p['id']] ?? []; $t = array_sum($m); ?>
                                <span class="tc-mevcut" data-kat="<?= h(json_encode($m, JSON_UNESCAPED_UNICODE)) ?>">
                                    <?= $t ? $f0($t) . ' cihaz' : '—' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$personeller): ?>
            <div class="alert alert-warning py-2 mt-2 small mb-0">
                Personel listesi boş — önce <a href="personel.php" class="alert-link">Personel</a> ekranından kişileri ekleyin.
            </div>
            <?php endif; ?>
        </div>

        <div class="mt-3">
            <label class="form-label small">Seri numaraları <span class="text-muted">(isteğe bağlı — her satır bir cihaz, sırayla eşleşir)</span></label>
            <textarea name="seriler" rows="3" class="form-control form-control-sm"
                      placeholder="Boş bırakılabilir. Yazılacaksa açılacak kayıt sayısıyla BİREBİR aynı satır olmalı."><?= h($_POST['seriler'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="mt-3 d-flex align-items-center gap-2">
        <button class="btn btn-primary btn-sm" id="tcGonder" <?= yetki_var('giris') ? '' : 'disabled' ?>>
            <i class="bi bi-check2-circle me-1"></i>Kayıtları Aç</button>
        <span class="text-muted small">Her cihaz ayrı kayıt olur: kendi envanter no'su, yaşam günlüğü ve imzalı tutanağıyla.</span>
    </div>
</div></form>

<script>
(function () {
    var kisi = document.getElementById('tcKisi'), depo = document.getElementById('tcDepo');
    var mKisi = document.getElementById('tcModKisi'), mDepo = document.getElementById('tcModDepo');
    function mod() {
        kisi.classList.toggle('d-none', !mKisi.checked);
        depo.classList.toggle('d-none', !mDepo.checked);
    }
    mKisi.addEventListener('change', mod); mDepo.addEventListener('change', mod); mod();

    var liste = document.getElementById('tcListe'), sayac = document.getElementById('tcSayac');
    function say() {
        var n = liste.querySelectorAll('.tc-kisi:checked').length;
        sayac.textContent = n + ' kişi';
        sayac.className = 'badge bg-' + (n ? 'primary' : 'secondary');
    }
    liste.addEventListener('change', say);

    document.getElementById('tcAra').addEventListener('input', function () {
        var q = this.value.trim().toLocaleUpperCase('tr-TR');
        Array.prototype.forEach.call(liste.rows, function (tr) {
            tr.classList.toggle('d-none', q !== '' && (tr.dataset.ara || '').indexOf(q) < 0);
        });
    });
    document.getElementById('tcHepsi').addEventListener('click', function () {
        Array.prototype.forEach.call(liste.rows, function (tr) {
            if (!tr.classList.contains('d-none')) tr.querySelector('.tc-kisi').checked = true;
        });
        say();
    });
    document.getElementById('tcTemizle').addEventListener('click', function () {
        Array.prototype.forEach.call(liste.querySelectorAll('.tc-kisi'), function (c) { c.checked = false; });
        say();
    });

    // Seçilen cihaz tipine göre "bu kişide zaten N adet var" rozeti
    var kat = document.getElementById('tcKat');
    function mevcutYaz() {
        var k = kat.value;
        Array.prototype.forEach.call(liste.querySelectorAll('.tc-mevcut'), function (el) {
            var d = {};
            try { d = JSON.parse(el.dataset.kat || '{}'); } catch (e) { d = {}; }
            var n = d[k] || 0, t = 0;
            for (var x in d) if (Object.prototype.hasOwnProperty.call(d, x)) t += d[x];
            el.textContent = n ? ('bu tipten ' + n + ' adet') : (t ? (t + ' cihaz') : '—');
            el.className = 'tc-mevcut' + (n ? ' text-warning-emphasis fw-semibold' : '');
        });
    }
    kat.addEventListener('change', mevcutYaz); mevcutYaz();
    say();

    // ⚠ Çift gönderim koruması: bu bir KAYIT AÇMA formu, tekrar göndermek N kayıt daha açar.
    var form = document.getElementById('tcGonder').form, gonderildi = false;
    form.addEventListener('submit', function (e) {
        if (gonderildi) { e.preventDefault(); return; }
        if (mKisi.checked && liste.querySelectorAll('.tc-kisi:checked').length === 0) {
            e.preventDefault();
            alert('En az bir personel seçin ya da "Depoya N adet" moduna geçin.');
            return;
        }
        gonderildi = true;
        var b = document.getElementById('tcGonder');
        b.disabled = true;
        b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Kayıtlar açılıyor…';
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
