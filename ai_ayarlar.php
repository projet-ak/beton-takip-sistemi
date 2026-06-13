<?php
/**
 * ai_ayarlar.php — AI entegrasyon ayarları (sadece admin)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin']);

$configFile = __DIR__ . '/config.php';
require_once $configFile;

$pageTitle = 'AI Ayarları — Beton Takip Sistemi';
$hata      = '';

function maskKey(string $key): string {
    if (strlen($key) <= 8) return str_repeat('*', strlen($key));
    return substr($key, 0, 6) . str_repeat('*', min(strlen($key) - 10, 20)) . substr($key, -4);
}

// ── Kaydet ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        $hata = 'Geçersiz istek (CSRF).';
    } else {
        $provider  = in_array($_POST['ai_provider'] ?? '', ['claude', 'gemini'], true)
                     ? $_POST['ai_provider'] : 'claude';
        $claudeKey = trim($_POST['claude_api_key'] ?? '');
        $geminiKey = trim($_POST['gemini_api_key'] ?? '');

        if ($claudeKey !== '' && !str_starts_with($claudeKey, 'sk-ant-')) {
            $hata = 'Claude API anahtarı "sk-ant-" ile başlamalıdır.';
        } elseif ($geminiKey !== '' && strlen($geminiKey) < 20) {
            $hata = 'Gemini API anahtarı çok kısa görünüyor.';
        } else {
            // Boş bırakılırsa mevcut değeri koru
            if ($claudeKey === '') $claudeKey = defined('CLAUDE_API_KEY') ? CLAUDE_API_KEY : '';
            if ($geminiKey === '') $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';

            $config = file_get_contents($configFile);
            if ($config === false) {
                $hata = 'config.php okunamadı.';
            } else {
                $esc = fn($s) => str_replace(["\\", "'"], ["\\\\", "\\'"], $s);

                $config = preg_replace(
                    "/define\s*\(\s*'AI_PROVIDER'\s*,\s*'[^']*'\s*\);/",
                    "define('AI_PROVIDER',   '" . $esc($provider) . "');",
                    $config
                );
                $config = preg_replace(
                    "/define\s*\(\s*'CLAUDE_API_KEY'\s*,\s*'[^']*'\s*\);/",
                    "define('CLAUDE_API_KEY', '" . $esc($claudeKey) . "');",
                    $config
                );
                $config = preg_replace(
                    "/define\s*\(\s*'GEMINI_API_KEY'\s*,\s*'[^']*'\s*\);/",
                    "define('GEMINI_API_KEY', '" . $esc($geminiKey) . "');",
                    $config
                );

                if (file_put_contents($configFile, $config) === false) {
                    $hata = 'config.php yazılamadı. Sunucu dosya izinlerini kontrol edin.';
                } else {
                    set_flash('success', 'AI ayarları kaydedildi.');
                    redirect('ai_ayarlar.php');
                }
            }
        }
    }
}

// Aktif değerleri oku
$curProvider = defined('AI_PROVIDER')    ? AI_PROVIDER    : 'claude';
$curClaude   = defined('CLAUDE_API_KEY') ? CLAUDE_API_KEY : '';
$curGemini   = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-stars fs-4 text-primary"></i>
    <div>
        <h5 class="mb-0 fw-bold">AI Ayarları</h5>
        <div class="text-muted small">Claude veya Gemini API yapılandırması</div>
    </div>
</div>

<?php if ($hata): ?>
<div class="alert alert-danger d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i> <?= h($hata) ?>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- Ayar Formu -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

                    <!-- Provider -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Aktif AI Sağlayıcısı</label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="ai_provider"
                                       id="provClaude" value="claude"
                                       <?= $curProvider === 'claude' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="provClaude">
                                    <strong>Claude</strong>
                                    <span class="text-muted small ms-1">(Anthropic Haiku 4.5)</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="ai_provider"
                                       id="provGemini" value="gemini"
                                       <?= $curProvider === 'gemini' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="provGemini">
                                    <strong>Gemini</strong>
                                    <span class="text-muted small ms-1">(Google 1.5 Flash — ücretsiz quota)</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-3">

                    <!-- Claude Key -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-key me-1 text-secondary"></i> Claude API Anahtarı
                        </label>
                        <div class="input-group">
                            <input type="password" id="claudeKeyInput" name="claude_api_key"
                                   class="form-control font-monospace"
                                   placeholder="<?= $curClaude ? 'Değiştirmek için yeni anahtarı yazın' : 'sk-ant-api03-...' ?>"
                                   autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="toggleVis('claudeKeyInput',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">
                            <?php if ($curClaude): ?>
                                <i class="bi bi-check-circle-fill text-success"></i>
                                Kayıtlı: <code><?= h(maskKey($curClaude)) ?></code>
                            <?php else: ?>
                                <i class="bi bi-x-circle text-danger"></i> Girilmedi.
                            <?php endif; ?>
                            — <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>
                        </div>
                    </div>

                    <!-- Gemini Key -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-key me-1 text-secondary"></i> Gemini API Anahtarı
                        </label>
                        <div class="input-group">
                            <input type="password" id="geminiKeyInput" name="gemini_api_key"
                                   class="form-control font-monospace"
                                   placeholder="<?= $curGemini ? 'Değiştirmek için yeni anahtarı yazın' : 'AIzaSy...' ?>"
                                   autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="toggleVis('geminiKeyInput',this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">
                            <?php if ($curGemini): ?>
                                <i class="bi bi-check-circle-fill text-success"></i>
                                Kayıtlı: <code><?= h(maskKey($curGemini)) ?></code>
                            <?php else: ?>
                                <i class="bi bi-x-circle text-danger"></i> Girilmedi.
                            <?php endif; ?>
                            — <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com</a>
                            <span class="badge bg-success-subtle text-success fw-normal">Ücretsiz</span>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> Kaydet
                        </button>
                        <span class="text-muted small">
                            Boş bırakılan anahtar alanları mevcut değeri korur.
                        </span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bilgi Paneli -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <p class="fw-semibold mb-2">
                    <i class="bi bi-info-circle text-primary me-1"></i> AI Ne İşe Yarıyor?
                </p>
                <ul class="small text-muted mb-0 ps-3">
                    <li class="mb-1">
                        <strong>İrsaliye Formu → "AI ile Oku" sekmesi:</strong>
                        Fotoğraf veya PDF'den irsaliye alanlarını otomatik doldurur.
                    </li>
                    <li class="mb-1">
                        <strong>Raporlar → Rapor Asistanı:</strong>
                        Veri hakkında Türkçe sorular sorabilirsiniz.
                    </li>
                    <li>
                        <strong>Raporlar → Anomali Tara:</strong>
                        SQL tabanlı, AI kullanmaz — her zaman ücretsiz çalışır.
                    </li>
                </ul>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="fw-semibold mb-2">
                    <i class="bi bi-bar-chart text-success me-1"></i> Maliyet
                </p>
                <table class="table table-sm small mb-1">
                    <thead class="table-light">
                        <tr>
                            <th></th>
                            <th>Claude Haiku</th>
                            <th>Gemini Flash</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Ücret</td>
                            <td>$1 / 1M token</td>
                            <td class="text-success fw-semibold">Ücretsiz*</td>
                        </tr>
                        <tr>
                            <td>PDF</td>
                            <td><i class="bi bi-check-circle-fill text-success"></i></td>
                            <td><i class="bi bi-check-circle-fill text-success"></i></td>
                        </tr>
                        <tr>
                            <td>Görsel OCR</td>
                            <td><i class="bi bi-check-circle-fill text-success"></i></td>
                            <td><i class="bi bi-check-circle-fill text-success"></i></td>
                        </tr>
                    </tbody>
                </table>
                <p class="text-muted small mb-0">* Günde 1.500 istek, dakikada 15 istek.</p>
            </div>
        </div>
    </div>
</div>

<script>
function toggleVis(id, btn) {
    const el = document.getElementById(id);
    const isPass = el.type === 'password';
    el.type = isPass ? 'text' : 'password';
    btn.querySelector('i').className = isPass ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
