<?php
/**
 * sistem_kontrol.php — Sunucu yetenek teşhisi (sadece admin)
 *
 * QR taraması (ZBar) için gerekenleri kontrol eder:
 *   - exec()/shell_exec() açık mı?
 *   - zbarimg, pdftoppm, pdfinfo kurulu ve çalışıyor mu?
 *   - GD / Imagick var mı? (alternatif render yolu)
 *
 * Sonuca göre QR çözüm stratejisini netleştiririz.
 * Teşhis bittiğinde bu dosya silinebilir.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin']);

$pageTitle = 'Sistem Kontrol — Beton Takip Sistemi';

// ── Yardımcılar ───────────────────────────────────────────────────────────
function fn_kapali($fn) {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return in_array($fn, $disabled, true) || !function_exists($fn);
}

function komut_var_mi($bin) {
    // exec kapalıysa tespit edemeyiz
    if (fn_kapali('exec')) return ['durum' => 'bilinmiyor', 'detay' => 'exec() kapalı'];
    $out = []; $ret = 1;
    @exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $ret);
    if ($ret === 0 && !empty($out[0])) {
        return ['durum' => 'var', 'detay' => $out[0]];
    }
    // bazı sistemlerde 'which'
    $out = []; $ret = 1;
    @exec('which ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $ret);
    if ($ret === 0 && !empty($out[0])) {
        return ['durum' => 'var', 'detay' => $out[0]];
    }
    return ['durum' => 'yok', 'detay' => 'PATH üzerinde bulunamadı'];
}

function surum_al($cmd) {
    if (fn_kapali('exec')) return '—';
    $out = [];
    @exec($cmd . ' 2>&1', $out);
    return !empty($out[0]) ? trim($out[0]) : '—';
}

// ── Kontroller ──────────────────────────────────────────────────────────
$execAcik       = !fn_kapali('exec');
$shellExecAcik  = !fn_kapali('shell_exec');

$zbar     = komut_var_mi('zbarimg');
$pdftoppm = komut_var_mi('pdftoppm');
$pdfinfo  = komut_var_mi('pdfinfo');

$zbarSurum     = $zbar['durum'] === 'var'     ? surum_al('zbarimg --version') : '—';
$pdftoppmSurum = $pdftoppm['durum'] === 'var' ? surum_al('pdftoppm -v')       : '—';

$gd      = extension_loaded('gd');
$imagick = extension_loaded('imagick');

// Genel sonuç
$zbarHazir = $execAcik && $zbar['durum'] === 'var' && $pdftoppm['durum'] === 'var';

require_once __DIR__ . '/includes/header.php';

function rozet($durum) {
    if ($durum === 'var' || $durum === true)  return '<span class="badge bg-success"><i class="bi bi-check-lg"></i> Var</span>';
    if ($durum === 'yok' || $durum === false) return '<span class="badge bg-danger"><i class="bi bi-x-lg"></i> Yok</span>';
    return '<span class="badge bg-secondary">Bilinmiyor</span>';
}
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h1 class="page-title h3 mb-0"><i class="bi bi-clipboard-data text-primary me-2"></i>Sistem Kontrol</h1>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Geri</a>
</div>

<!-- Genel sonuç -->
<div class="alert alert-<?= $zbarHazir ? 'success' : 'warning' ?> d-flex align-items-center gap-2">
    <i class="bi bi-<?= $zbarHazir ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> fs-4"></i>
    <div>
        <?php if ($zbarHazir): ?>
            <strong>ZBar QR taraması bu sunucuda çalışabilir.</strong>
            Mevcut çözüm (api/pdf_qr_scan.php) sorunsuz çalışacaktır.
        <?php else: ?>
            <strong>ZBar QR taraması bu sunucuda çalışmayabilir.</strong>
            Aşağıdaki eksiklere göre alternatif çözüme geçeceğiz.
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <!-- PHP fonksiyonları -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-code-square me-1"></i> PHP Yetenekleri</div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        <tr><td>PHP Sürümü</td><td class="text-end"><code><?= h(PHP_VERSION) ?></code></td></tr>
                        <tr><td><code>exec()</code> açık</td><td class="text-end"><?= rozet($execAcik) ?></td></tr>
                        <tr><td><code>shell_exec()</code> açık</td><td class="text-end"><?= rozet($shellExecAcik) ?></td></tr>
                        <tr><td>GD eklentisi</td><td class="text-end"><?= rozet($gd) ?></td></tr>
                        <tr><td>Imagick eklentisi</td><td class="text-end"><?= rozet($imagick) ?></td></tr>
                    </tbody>
                </table>
                <?php if (!$execAcik): ?>
                <div class="alert alert-danger small mt-3 mb-0">
                    <i class="bi bi-x-octagon me-1"></i>
                    <code>exec()</code> kapalı — ZBar/poppler kurulu olsa bile PHP'den çağrılamaz.
                    Saf-PHP QR çözücüye geçmemiz gerekir.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sistem araçları -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-terminal me-1"></i> Sistem Araçları</div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        <tr>
                            <td><code>zbarimg</code> (QR okuyucu)</td>
                            <td class="text-end"><?= rozet($zbar['durum']) ?></td>
                        </tr>
                        <tr><td colspan="2" class="small text-muted ps-3">
                            <?= h($zbar['detay']) ?><?= $zbarSurum !== '—' ? ' — ' . h($zbarSurum) : '' ?>
                        </td></tr>
                        <tr>
                            <td><code>pdftoppm</code> (PDF→PNG)</td>
                            <td class="text-end"><?= rozet($pdftoppm['durum']) ?></td>
                        </tr>
                        <tr><td colspan="2" class="small text-muted ps-3">
                            <?= h($pdftoppm['detay']) ?><?= $pdftoppmSurum !== '—' ? ' — ' . h($pdftoppmSurum) : '' ?>
                        </td></tr>
                        <tr>
                            <td><code>pdfinfo</code> (sayfa sayısı)</td>
                            <td class="text-end"><?= rozet($pdfinfo['durum']) ?></td>
                        </tr>
                        <tr><td colspan="2" class="small text-muted ps-3"><?= h($pdfinfo['detay']) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header fw-semibold"><i class="bi bi-signpost-2 me-1"></i> Öneri</div>
    <div class="card-body">
        <?php if ($zbarHazir): ?>
            <p class="mb-0"><i class="bi bi-check-circle text-success me-1"></i>
            Her şey hazır. <strong>Hızlı Tarama → QR Kodları Tara</strong> sayfasından PDF yükleyerek test edebilirsin.</p>
        <?php elseif (!$execAcik): ?>
            <p class="mb-0"><i class="bi bi-arrow-right-circle text-warning me-1"></i>
            <code>exec()</code> kapalı olduğu için sistem araçları kullanılamaz.
            <strong>Saf-PHP QR çözücü</strong> (Composer paketi) en uygun seçenek — root gerektirmez.</p>
        <?php else: ?>
            <p class="mb-0"><i class="bi bi-arrow-right-circle text-warning me-1"></i>
            <code>exec()</code> açık ama
            <?= $zbar['durum'] !== 'var' ? '<code>zbarimg</code> ' : '' ?>
            <?= $pdftoppm['durum'] !== 'var' ? '<code>pdftoppm</code> ' : '' ?>
            eksik. Hosting sağlayıcına kurdurabilir ya da saf-PHP çözücüye geçebiliriz.</p>
        <?php endif; ?>
        <hr>
        <p class="small text-muted mb-0">
            <i class="bi bi-info-circle me-1"></i>
            Bu teşhis sayfası güvenlik için yalnızca admin erişimine açıktır. İşin bittiğinde
            <code>v2/sistem_kontrol.php</code> dosyasını silebilirsin.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
