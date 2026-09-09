<?php
/**
 * mukerrer.php — MÜKERRER KAYIT MERKEZİ (tüm modüller)
 *
 * "Aynı tedarikçi / firma / personel / araç iki kez açılmış mı?" sorusunun tek ekrandaki cevabı.
 * Çekirdek `includes/mukerrer.php` (mk_*): kayıt defteri MK_KURAL, tespit mk_gruplar, birleştirme
 * mk_birlestir. Bu sayfa yalnız arayüzdür.
 *
 * İki görünüm: (1) tüm modüllerin özeti (hangi tabloda kaç mükerrer grup var),
 * (2) `?m=<modül>&k=<kural>` — grup grup liste, korunacak kaydı seç, birleştir.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mukerrer.php';

$pageTitle = 'Mükerrer Kayıtlar — Tüm Modüller';
$uid = current_user_id();
$hata = null; $rapor = null;

$m = (string)($_GET['m'] ?? $_POST['m'] ?? '');
$kod = (string)($_GET['k'] ?? $_POST['k'] ?? '');
$kural = ($m !== '' && $kod !== '') ? mk_kural($m, $kod) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'birlestir') {
    if (!$kural) $hata = 'Geçersiz tablo.';
    elseif (empty($kural['birlestir'])) $hata = 'Bu tabloda birleştirme kapalı — yalnız raporlanır.';
    else {
        $pdo2 = mk_pdo($m);
        $hedef = (int)($_POST['hedef'] ?? 0);
        $kaynaklar = array_map('intval', (array)($_POST['kaynak'] ?? []));
        if (!$pdo2) $hata = 'Modül veritabanına bağlanılamadı.';
        else {
            try {
                $rapor = mk_birlestir($pdo2, $kural, $hedef, $kaynaklar, $uid);
                flash('success', sprintf('%d kayıt "%s" içinde birleştirildi%s.', $rapor['silinen'], $kural['ad'],
                      $rapor['tasinan'] ? ' — ' . array_sum($rapor['tasinan']) . ' bağlı kayıt taşındı' : ''));
                redirect('mukerrer.php?m=' . urlencode($m) . '&k=' . urlencode($kod));
            } catch (Throwable $e) { $hata = 'Birleştirme yapılmadı: ' . $e->getMessage(); }
        }
    }
}

// ── Veri ────────────────────────────────────────────────────────────────────
$gruplar = []; $tabloHata = null;
if ($kural) {
    $pdo2 = mk_pdo($m);
    if (!$pdo2) $tabloHata = 'Bu modülün veritabanına bağlanılamadı (config.php\'de tanımlı mı?).';
    else { try { $gruplar = mk_gruplar($pdo2, $kural); } catch (Throwable $e) { $tabloHata = 'Tablo okunamadı: ' . $e->getMessage(); } }
} else {
    $ozet = mk_ozet();
}

$modAd = fn($x) => function_exists('modul_ad') ? modul_ad($x) : $x;
require_once __DIR__ . '/includes/header.php';
?>
<style>
.mk-grup { border-left: 4px solid var(--bs-warning); }
.mk-grup.secili { border-left-color: var(--bs-success); }
.mk-kart { font-size: .85rem; }
.mk-kart .form-check-input { margin-top: .2rem; }
.mk-asil { background: rgba(var(--bs-success-rgb), .07); }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-union text-primary me-2"></i>Mükerrer Kayıtlar
        <?php if ($kural): ?><span class="text-muted fw-normal">· <?= h($modAd($m)) ?> › <?= h($kural['ad']) ?></span><?php endif; ?></h4>
    <div class="d-flex gap-2">
        <?php if ($kural): ?><a href="mukerrer.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Tüm modüller</a><?php endif; ?>
        <a href="veri_kontrol.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clipboard-check me-1"></i>Veri Kontrol</a>
    </div>
</div>

<?php if ($hata): ?><div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= h($hata) ?></div><?php endif; ?>

<?php if (!$kural): /* ── ÖZET: tüm modüller ── */
    $toplamGrup = 0; $toplamKayit = 0; $sorunlu = [];
    foreach ($ozet as $mm => $satirlar) foreach ($satirlar as $kk => $s) {
        $toplamGrup += $s['grup']; $toplamKayit += $s['kayit'];
        if ($s['grup']) $sorunlu[] = [$mm, $kk, $s];
    }
?>
<div class="alert <?= $toplamGrup ? 'alert-warning' : 'alert-success' ?> d-flex flex-wrap align-items-center gap-2">
    <i class="bi <?= $toplamGrup ? 'bi-exclamation-triangle' : 'bi-check-circle' ?> fs-5"></i>
    <?php if ($toplamGrup): ?>
        <span><strong><?= $toplamGrup ?> mükerrer grup</strong> bulundu (toplam <?= $toplamKayit ?> kayıt).
        Aşağıdaki tablolara tıklayıp <strong>hangi kaydın kalacağını seçerek birleştirin</strong> —
        bağlı hareketler korunan kayda taşınır, hiçbir veri kaybolmaz.</span>
    <?php else: ?>
        <span>Taranan tabloların hiçbirinde mükerrer kayıt yok.</span>
    <?php endif; ?>
</div>

<?php if ($sorunlu): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>Dikkat isteyenler</div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Modül</th><th>Tablo</th><th class="text-end">Grup</th><th class="text-end">Kayıt</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sorunlu as [$mm, $kk, $s]): ?>
            <tr>
                <td><i class="bi <?= h(MODULLER[$mm][1] ?? 'bi-box') ?> me-1 text-muted"></i><?= h($modAd($mm)) ?></td>
                <td class="fw-semibold"><?= h($s['ad']) ?></td>
                <td class="text-end"><span class="badge bg-warning text-dark"><?= (int)$s['grup'] ?></span></td>
                <td class="text-end text-muted"><?= (int)$s['kayit'] ?></td>
                <td class="text-end"><a href="mukerrer.php?m=<?= h($mm) ?>&k=<?= h($kk) ?>" class="btn btn-sm btn-<?= $s['birlestir'] ? 'warning' : 'outline-secondary' ?>">
                    <i class="bi <?= $s['birlestir'] ? 'bi-union' : 'bi-eye' ?> me-1"></i><?= $s['birlestir'] ? 'İncele ve birleştir' : 'İncele' ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($ozet as $mm => $satirlar): $mGrup = array_sum(array_column($satirlar, 'grup')); ?>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex align-items-center gap-2 fw-semibold">
                <i class="bi <?= h(MODULLER[$mm][1] ?? 'bi-box') ?> text-primary"></i><?= h($modAd($mm)) ?>
                <?php if ($mGrup): ?><span class="badge bg-warning text-dark ms-auto"><?= $mGrup ?> grup</span>
                <?php else: ?><span class="badge bg-success-subtle text-success-emphasis ms-auto">temiz</span><?php endif; ?>
            </div>
            <div class="list-group list-group-flush small">
            <?php foreach ($satirlar as $kk => $s): ?>
                <a class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $s['hata'] ? 'text-muted' : '' ?>"
                   href="mukerrer.php?m=<?= h($mm) ?>&k=<?= h($kk) ?>">
                    <span class="flex-grow-1"><?= h($s['ad']) ?>
                        <?php if (!$s['birlestir']): ?><i class="bi bi-eye text-muted ms-1" title="yalnız rapor — birleştirme kapalı"></i><?php endif; ?></span>
                    <?php if ($s['hata']): ?><span class="badge bg-light text-muted border"><?= h($s['hata']) ?></span>
                    <?php elseif ($s['grup']): ?><span class="badge bg-warning text-dark"><?= (int)$s['grup'] ?></span>
                    <?php else: ?><i class="bi bi-check2 text-success"></i><?php endif; ?>
                </a>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="alert alert-light border small mt-3">
    <i class="bi bi-info-circle me-1"></i><strong>Nasıl çalışır?</strong>
    Her tablo, kendisi için tanımlı anahtarlarla (ör. tedarikçide <em>Ad</em> ve <em>VKN</em>) Türkçe harf duyarsız
    normalize edilerek taranır — "SAFİ BETON A.Ş." ile "Safi Beton AS" aynı sayılır. Bir kayıt bir anahtardan A ile,
    başka anahtardan B ile eşleşirse üçü <strong>tek grup</strong> olur.
    Birleştirmede korunan kayda bağlı hareketler taşınır, boş alanları diğerlerinden tamamlanır, fazlalıklar silinir;
    işlem tek transaction'da yapılır ve <a href="aktivite.php">denetim günlüğüne</a> yazılır.
    <span class="text-muted">Göz ikonlu satırlar (irsaliye, arıza, stok kartı…) yalnız raporlanır — onların temizliği kendi ekranlarında yapılır.</span>
</div>

<?php else: /* ── DETAY: tek tablo ── */ ?>

<?php if ($kural['not']): ?><div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1"></i><?= $kural['not'] ?></div><?php endif; ?>
<?php if ($tabloHata): ?><div class="alert alert-warning py-2"><i class="bi bi-database-x me-1"></i><?= h($tabloHata) ?></div><?php endif; ?>

<?php if (!$gruplar && !$tabloHata): ?>
<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><strong><?= h($kural['ad']) ?></strong> tablosunda mükerrer kayıt yok.</div>
<?php elseif ($gruplar): ?>
<div class="alert alert-warning py-2 d-flex flex-wrap align-items-center gap-2">
    <span><i class="bi bi-exclamation-triangle me-1"></i><strong><?= count($gruplar) ?> grup</strong> ·
    toplam <?= array_sum(array_map(fn($g) => count($g['kayitlar']), $gruplar)) ?> kayıt.
    Anahtarlar: <?php foreach ($kural['anahtar'] as [$b, $c]): ?><span class="badge bg-light text-dark border"><?= h($b) ?></span> <?php endforeach; ?></span>
    <?php if (empty($kural['birlestir'])): ?><span class="ms-auto badge bg-secondary"><i class="bi bi-eye me-1"></i>yalnız rapor</span><?php endif; ?>
</div>

<?php foreach ($gruplar as $gi => $g): ?>
<div class="card border-0 shadow-sm mb-3 mk-grup">
    <form method="post">
    <input type="hidden" name="islem" value="birlestir">
    <input type="hidden" name="m" value="<?= h($m) ?>"><input type="hidden" name="k" value="<?= h($kod) ?>">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2 py-2">
        <span class="fw-semibold">Grup <?= $gi + 1 ?></span>
        <span class="badge bg-warning text-dark"><?= count($g['kayitlar']) ?> kayıt</span>
        <?php foreach ($g['anahtarlar'] as $ab => $av): ?>
            <span class="small text-muted"><?= h($ab) ?>: <strong><?= h($av) ?></strong></span>
        <?php endforeach; ?>
        <?php if (!empty($kural['birlestir'])): ?>
        <button class="btn btn-sm btn-warning ms-auto"
                onclick="return confirm('Seçili kayıtlar, KORUNACAK olarak işaretlediğiniz kayda birleştirilecek.\n\nBağlı hareketler korunan kayda taşınır, boş alanları tamamlanır, diğer kayıtlar silinir.\n\nDevam edilsin mi?')">
            <i class="bi bi-union me-1"></i>Seçilenleri birleştir</button>
        <?php endif; ?>
    </div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0 mk-kart">
        <thead class="table-light"><tr>
            <th style="width:110px">Korunacak</th><?php if (!empty($kural['birlestir'])): ?><th style="width:90px">Birleştir</th><?php endif; ?>
            <th style="width:70px">#</th><th>Kayıt</th>
            <?php foreach ($kural['anahtar'] as [$b, $c]): ?><th><?= h($b) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($g['kayitlar'] as $r): $asil = (int)$r['id'] === $g['asil']; ?>
            <tr class="<?= $asil ? 'mk-asil' : '' ?>">
                <td><div class="form-check">
                    <input class="form-check-input" type="radio" name="hedef" value="<?= (int)$r['id'] ?>" <?= $asil ? 'checked' : '' ?>
                           id="h<?= $gi ?>_<?= (int)$r['id'] ?>" onchange="mkSenkron(this)">
                    <label class="form-check-label small" for="h<?= $gi ?>_<?= (int)$r['id'] ?>"><?= $asil ? 'önerilen' : 'bunu koru' ?></label>
                </div></td>
                <?php if (!empty($kural['birlestir'])): ?>
                <td><input class="form-check-input mk-kaynak" type="checkbox" name="kaynak[]" value="<?= (int)$r['id'] ?>"
                           <?= $asil ? 'disabled' : 'checked' ?>></td>
                <?php endif; ?>
                <td class="text-muted"><?= (int)$r['id'] ?></td>
                <td class="fw-semibold"><?= h(mk_etiket($r, $kural)) ?></td>
                <?php foreach ($kural['anahtar'] as [$b, $kols]): ?>
                    <td class="text-muted"><?= h(implode(' · ', array_filter(array_map(fn($c) => (string)($r[$c] ?? ''), $kols)))) ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </form>
</div>
<?php endforeach; ?>

<script>
/* "Korunacak" seçimi değişince o satırın birleştirme kutusu kapanır, diğerleri açılır —
   kayıt hem hedef hem kaynak olamaz. */
function mkSenkron(radio){
  var form = radio.closest('form');
  form.querySelectorAll('tbody tr').forEach(function(tr){
    var rb = tr.querySelector('input[name="hedef"]'), cb = tr.querySelector('.mk-kaynak');
    if (!rb || !cb) return;
    if (rb.checked) { cb.checked = false; cb.disabled = true; tr.classList.add('mk-asil'); }
    else { cb.disabled = false; cb.checked = true; tr.classList.remove('mk-asil'); }
  });
}
</script>
<?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
