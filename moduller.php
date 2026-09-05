<?php
/**
 * moduller.php — Modül Yönetimi (admin): modül ADINI değiştirme + GİZLEME + SIRALAMA
 *
 * `MODULLER` sabiti (includes/auth.php) sistemin varsayılanıdır ve koda gömülüdür;
 * bu ekran yalnız **üzerine yazar** (`modul_ayarlar` tablosu). Tablo silinse bile
 * sistem varsayılan adlarla çalışmaya devam eder.
 *
 * Gizlenen modül menülerde/şeritte hiç görünmez ve açılmaya çalışılırsa 403 verir —
 * **admin hariç**; yoksa gizlenen modülü geri açacak kimse kalmazdı.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/includes/db.php';

modul_ayar_semasi($pdo);
$pageTitle = 'Modül Yönetimi';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kaydet') {
    $ad    = (array)($_POST['ad'] ?? []);
    $gizli = (array)($_POST['gizli'] ?? []);
    $sira  = (array)($_POST['sira'] ?? []);

    // En az bir modül açık kalmalı — hepsi gizlenirse sistem kullanılamaz hale gelir
    $acik = 0;
    foreach (array_keys(MODULLER) as $k) if (empty($gizli[$k])) $acik++;
    if ($acik === 0) {
        flash('error', 'Tüm modüller gizlenemez — en az bir modül açık kalmalı.');
        redirect('moduller.php');
    }

    $st = $pdo->prepare("INSERT INTO modul_ayarlar (anahtar, ad, gizli, sira) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE ad=VALUES(ad), gizli=VALUES(gizli), sira=VALUES(sira)");
    $degisen = [];
    $onceki  = modul_ayarlari();
    foreach (MODULLER as $k => [$vAd, $vIkon, $vSayfa]) {
        $yeniAd = trim(mb_substr((string)($ad[$k] ?? ''), 0, 60));
        if ($yeniAd === $vAd) $yeniAd = '';                       // varsayılanla aynıysa özel ad tutma
        $yeniGizli = !empty($gizli[$k]) ? 1 : 0;
        $yeniSira  = max(0, min(99, (int)($sira[$k] ?? 0)));
        $st->execute([$k, $yeniAd !== '' ? $yeniAd : null, $yeniGizli, $yeniSira]);

        $eskiAd = (string)($onceki[$k]['ad'] ?? '');
        if ($eskiAd !== $yeniAd)                            $degisen[] = ($eskiAd ?: $vAd) . ' → ' . ($yeniAd ?: $vAd);
        if ((int)($onceki[$k]['gizli'] ?? 0) !== $yeniGizli) $degisen[] = ($yeniAd ?: $vAd) . ($yeniGizli ? ' gizlendi' : ' açıldı');
    }
    audit_log($pdo, 'modul_ayarlar', 0, 'UPDATE', null, ['degisiklikler' => $degisen], current_user_id());
    flash('success', $degisen ? 'Modül ayarları kaydedildi: ' . implode(' · ', $degisen)
                              : 'Modül ayarları kaydedildi (değişiklik yok).');
    redirect('moduller.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sifirla') {
    $pdo->exec("DELETE FROM modul_ayarlar");
    audit_log($pdo, 'modul_ayarlar', 0, 'DELETE', null, ['islem' => 'varsayılana döndürüldü'], current_user_id());
    flash('success', 'Tüm modüller varsayılan adlarına döndürüldü ve görünür yapıldı.');
    redirect('moduller.php');
}

$liste = modul_listesi(true);   // gizliler dahil
// Hangi modülde kaç kullanıcıya özel izin verilmiş (gizlemenin etkisini görmek için)
$izinli = [];
try {
    foreach ($pdo->query("SELECT modul_erisim FROM users WHERE aktif=1 AND modul_erisim IS NOT NULL AND modul_erisim <> ''")->fetchAll(PDO::FETCH_COLUMN) as $ham)
        foreach (array_filter(array_map('trim', explode(',', (string)$ham))) as $m) $izinli[$m] = ($izinli[$m] ?? 0) + 1;
} catch (Throwable $e) { /* kolon yoksa boş kalır */ }

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-grid-3x3-gap text-primary me-2"></i>Modül Yönetimi</h4>
    <span class="text-muted small">adlandırma · gizleme · sıralama</span>
</div>

<div class="alert alert-info py-2 small">
    <i class="bi bi-info-circle me-1"></i>
    Burada verdiğiniz ad <strong>tüm sistemde</strong> geçerlidir (üst şerit, menüler, yetki ekranı, 403 sayfası).
    <strong>Gizlenen modül</strong> hiç kimsenin menüsünde görünmez ve adresi elle yazılsa da açılmaz —
    yalnız <strong>admin</strong> erişebilir (yoksa modülü geri açacak kimse kalmazdı).
    Gizlemek veriyi <strong>silmez</strong>; modül geri açıldığında her şey yerinde durur.
</div>

<form method="post">
<input type="hidden" name="action" value="kaydet">
<div class="card border-0 shadow-sm mb-3">
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th style="width:70px">Sıra</th>
                <th style="width:200px">Modül (varsayılan)</th>
                <th>Görünen ad</th>
                <th style="width:150px" class="text-center">Gizle</th>
                <th style="width:190px">Durum</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($liste as $k => $m): ?>
            <tr class="<?= $m['gizli'] ? 'table-secondary' : '' ?>">
                <td>
                    <input type="number" class="form-control form-control-sm" name="sira[<?= h($k) ?>]"
                           value="<?= (int)$m['sira'] ?>" min="0" max="99" style="width:70px">
                </td>
                <td>
                    <i class="bi <?= h($m['ikon']) ?> me-1 text-primary"></i>
                    <span class="fw-semibold"><?= h($m['varsayilan_ad']) ?></span>
                    <div class="text-muted" style="font-size:.75rem"><code><?= h($k) ?></code> · <?= h($m['sayfa']) ?></div>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm" name="ad[<?= h($k) ?>]"
                           value="<?= h($m['ad']) ?>" maxlength="60"
                           placeholder="<?= h($m['varsayilan_ad']) ?>">
                    <div class="form-text" style="font-size:.72rem">Boş bırakırsanız varsayılan ad kullanılır.</div>
                </td>
                <td class="text-center">
                    <div class="form-check form-switch d-inline-block">
                        <input class="form-check-input" type="checkbox" role="switch"
                               name="gizli[<?= h($k) ?>]" value="1" id="gz_<?= h($k) ?>"
                               <?= $m['gizli'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="gz_<?= h($k) ?>">gizle</label>
                    </div>
                </td>
                <td class="small">
                    <?php if ($m['gizli']): ?>
                        <span class="badge bg-secondary"><i class="bi bi-eye-slash me-1"></i>Gizli</span>
                        <div class="text-muted">yalnız admin açabilir</div>
                    <?php else: ?>
                        <span class="badge bg-success"><i class="bi bi-eye me-1"></i>Görünür</span>
                    <?php endif; ?>
                    <?php if (!empty($izinli[$k])): ?>
                        <div class="text-muted"><?= (int)$izinli[$k] ?> kullanıcıya özel izinli</div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="card-footer bg-white d-flex flex-wrap gap-2 align-items-center">
        <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Kaydet</button>
        <a href="kullanicilar.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-people me-1"></i>Kullanıcı bazlı erişim</a>
        <span class="text-muted small ms-auto">Sıra: küçük sayı önce gelir. 0 = doğal sıra.</span>
    </div>
</div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <div class="small text-muted flex-grow-1">
            <strong>Varsayılana dön:</strong> tüm özel adlar silinir, gizlenen modüller geri açılır.
            Kullanıcı bazlı erişim izinleri (<code>users.modul_erisim</code>) bundan etkilenmez.
        </div>
        <form method="post" onsubmit="return confirm('Tüm modül adları ve gizleme ayarları varsayılana dönecek. Onaylıyor musunuz?')">
            <input type="hidden" name="action" value="sifirla">
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Varsayılana Dön</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
