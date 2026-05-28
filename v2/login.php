<?php
/**
 * login.php — Giriş sayfası
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// config.php yoksa kuruluma yönlendir
if (!file_exists(__DIR__ . '/config.php')) {
    redirect('install.php');
}

// Zaten giriş yaptıysa ana sayfaya yönlendir
if (!empty($_SESSION['user'])) {
    redirect('index.php');
}

require_once __DIR__ . '/config.php';

$error    = '';
$redirect = trim($_GET['redirect'] ?? 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Kullanıcı adı ve şifre zorunludur.';
    } else {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

            $stmt = $pdo->prepare(
                "SELECT id, username, password_hash, full_name, role, aktif
                 FROM users
                 WHERE username = ?
                 LIMIT 1"
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && $user['aktif'] == 1 && password_verify($password, $user['password_hash'])) {
                // Session fixation koruması
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'        => (int)$user['id'],
                    'username'  => $user['username'],
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                ];
                // Güvenli yönlendirme: sadece göreceli yollar kabul et
                $safeRedirect = 'index.php';
                if (
                    !empty($redirect)
                    && !str_starts_with($redirect, '//')
                    && !preg_match('#^https?://#i', $redirect)
                ) {
                    $safeRedirect = ltrim($redirect, '/');
                }
                redirect($safeRedirect);
            } elseif ($user && $user['aktif'] == 0) {
                $error = 'Hesabınız devre dışı bırakılmış. Lütfen yönetici ile iletişime geçin.';
            } else {
                $error = 'Kullanıcı adı veya şifre hatalı.';
            }
        } catch (PDOException $e) {
            $error = 'Veritabanı bağlantı hatası. Lütfen yönetici ile iletişime geçin.';
            error_log('Login PDO error: ' . $e->getMessage());
        }
    }
}

$flashInfo = get_flash('login_info');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Giriş — Beton Takip Sistemi</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<style>
:root { --pri: #0d6efd; --pri-dark: #0a4db0; --pri-deep: #072d77; }
* { font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif; }
body {
    background: linear-gradient(135deg, var(--pri) 0%, var(--pri-dark) 55%, var(--pri-deep) 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
}
/* Dekoratif arka plan blob'ları */
body::before, body::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    filter: blur(60px);
    opacity: .35;
}
body::before { width: 460px; height: 460px; background: #4dabff; top: -140px; left: -120px; animation: float1 14s ease-in-out infinite; }
body::after  { width: 380px; height: 380px; background: #1c4fd6; bottom: -120px; right: -100px; animation: float2 17s ease-in-out infinite; }
@keyframes float1 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(40px,30px)} }
@keyframes float2 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-30px,-40px)} }

.login-card {
    width: 100%;
    max-width: 430px;
    border: 1px solid rgba(255,255,255,.5);
    border-radius: 22px;
    box-shadow: 0 24px 64px rgba(0,0,0,.32);
    background: rgba(255,255,255,.97);
    backdrop-filter: blur(12px);
    position: relative;
    z-index: 1;
    animation: cardIn .5s cubic-bezier(.2,.8,.2,1) backwards;
}
@keyframes cardIn { from { opacity: 0; transform: translateY(18px) scale(.97); } to { opacity: 1; transform: none; } }

.login-logo {
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, var(--pri), var(--pri-deep));
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.1rem;
    box-shadow: 0 8px 24px rgba(13,110,253,.45);
    transform: rotate(-4deg);
}
.login-logo i { font-size: 2.3rem; color: #fff; transform: rotate(4deg); }

.login-card h4 { font-weight: 750; letter-spacing: -.02em; }
.form-label { font-weight: 600; font-size: .85rem; }
.form-control { border-radius: 11px; padding: .6rem .8rem; }
.input-group-text { background: #f6f8fb; border-right: 0; border-radius: 11px 0 0 11px; color: #6b7685; }
.input-group .form-control { border-left: 0; }
.input-group:focus-within .input-group-text { border-color: var(--pri); color: var(--pri); }
.input-group:focus-within .form-control { border-color: var(--pri); }
.form-control:focus { box-shadow: none; }
.btn-login {
    height: 48px;
    font-size: 1rem;
    font-weight: 650;
    letter-spacing: .2px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--pri), var(--pri-dark));
    border: none;
    box-shadow: 0 6px 18px rgba(13,110,253,.4);
    transition: .2s;
}
.btn-login:hover { transform: translateY(-1px); box-shadow: 0 10px 26px rgba(13,110,253,.5); }
.btn-login:active { transform: translateY(0); }
.alert { border-radius: 12px; }
</style>
</head>
<body>
<div class="login-card card p-4 p-md-5 mx-3">
    <div class="login-logo">
        <i class="bi bi-building-fill-check"></i>
    </div>
    <h4 class="text-center mb-1">Beton Takip Sistemi</h4>
    <p class="text-center text-muted small mb-4">Devam etmek için hesabınızla giriş yapın</p>

    <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= h($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($flashInfo): ?>
        <div class="alert alert-info d-flex align-items-center gap-2 py-2">
            <i class="bi bi-info-circle-fill"></i>
            <span><?= h($flashInfo) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <?php if (!empty($redirect) && $redirect !== 'index.php'): ?>
            <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
        <?php endif; ?>

        <div class="mb-3">
            <label for="username" class="form-label fw-semibold">Kullanıcı Adı</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person"></i></span>
                <input
                    type="text"
                    id="username"
                    name="username"
                    class="form-control"
                    value="<?= h($_POST['username'] ?? '') ?>"
                    autocomplete="username"
                    autofocus
                    required
                    placeholder="kullanici_adi"
                >
            </div>
        </div>

        <div class="mb-4">
            <label for="password" class="form-label fw-semibold">Şifre</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    autocomplete="current-password"
                    required
                    placeholder="••••••••"
                >
                <button type="button" class="btn btn-outline-secondary" id="togglePwd" tabindex="-1" title="Şifreyi göster/gizle">
                    <i class="bi bi-eye" id="eyeIcon"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 btn-login fw-semibold">
            <i class="bi bi-box-arrow-in-right me-1"></i> Giriş Yap
        </button>
    </form>

    <p class="text-center text-muted small mt-4 mb-0">
        Beton Takip &copy; <?= date('Y') ?>
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('togglePwd').addEventListener('click', function () {
    const pwd  = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    if (pwd.type === 'password') {
        pwd.type       = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        pwd.type       = 'password';
        icon.className = 'bi bi-eye';
    }
});
</script>
</body>
</html>
