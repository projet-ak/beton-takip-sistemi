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
                // Güvenli yönlendirme: // ve http(s):// yasak, / ile başlayan mutlak yollar geçerli
                $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/';
                $safeRedirect = $base . 'index.php';
                if (
                    !empty($redirect)
                    && !str_starts_with($redirect, '//')
                    && !preg_match('#^https?://#i', $redirect)
                ) {
                    $safeRedirect = $redirect ?: $base . 'index.php';
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

// ── Logolar ───────────────────────────────────────────────────────────────────
// Renkli ERN Holding logosu. Yerel dosya kullanmak isterseniz buraya
// 'assets/img/ern-holding.png' gibi bir yol yazın (logoyu sunucuya yükledikten sonra).
$ERN_LOGO        = 'https://portal.ern.com.tr/assets/assets/images/ern_holding.613de732dd156fc8c966aeb8159822be.png';
$ERN_LOGO_WHITE  = $ERN_LOGO; // koyu zeminde beyaza çevrilerek kullanılır (CSS filter)
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#00584E">
<title>Giriş — ERN Holding Beton Takip Sistemi</title>
<link rel="icon" type="image/png" href="https://ern.com.tr/favicon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap">
<style>
:root{
    --ern:#00584E; --ern-dark:#003D35; --ern-light:#007A6A;
    --ern-teal:#00C9B1; --ern-gold:#C9A84C;
    --ink:#0D2E28; --muted:#5C7872;
}
*,*::before,*::after{box-sizing:border-box;}
html,body{height:100%;}
body{
    font-family:'Outfit',system-ui,-apple-system,sans-serif;
    margin:0; min-height:100vh;
    display:flex; align-items:center; justify-content:center;
    padding:1.5rem;
    position:relative; overflow:hidden;
    background:radial-gradient(1200px 600px at 15% 10%, var(--ern-light) 0%, transparent 55%),
               radial-gradient(1000px 700px at 90% 90%, #00352e 0%, transparent 50%),
               linear-gradient(145deg, var(--ern-dark) 0%, var(--ern) 100%);
}
/* Animasyonlu arka plan baloncukları */
.blob{position:absolute; border-radius:50%; filter:blur(8px); opacity:.5; pointer-events:none; animation:floaty 14s ease-in-out infinite;}
.blob.b1{width:520px;height:520px;top:-180px;right:-120px;background:radial-gradient(circle,rgba(0,201,177,.22),transparent 70%);}
.blob.b2{width:420px;height:420px;bottom:-160px;left:-120px;background:radial-gradient(circle,rgba(201,168,76,.16),transparent 70%);animation-duration:18s;animation-direction:reverse;}
.blob.b3{width:300px;height:300px;top:40%;left:55%;background:radial-gradient(circle,rgba(255,255,255,.07),transparent 70%);animation-duration:22s;}
@keyframes floaty{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-30px) scale(1.08);}}
/* İnce ışık ızgarası */
body::before{
    content:''; position:absolute; inset:0; pointer-events:none;
    background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);
    background-size:46px 46px;
    mask-image:radial-gradient(circle at 50% 40%, #000 0%, transparent 75%);
}

/* ── Kart ─────────────────────────────────────────────── */
.card-login{
    position:relative; z-index:2;
    width:100%; max-width:430px;
    background:rgba(255,255,255,.98);
    border:1px solid rgba(255,255,255,.6);
    border-radius:26px;
    box-shadow:0 30px 80px rgba(0,40,35,.45), 0 2px 0 rgba(255,255,255,.4) inset;
    padding:2.6rem 2.4rem 1.8rem;
    animation:rise .55s cubic-bezier(.2,.7,.2,1);
}
@keyframes rise{from{opacity:0;transform:translateY(22px);}to{opacity:1;transform:none;}}

/* Logo başlığı */
.brand-head{text-align:center; margin-bottom:1.6rem;}
.brand-logo{height:62px; width:auto; max-width:80%; object-fit:contain; margin-bottom:1rem;}
.brand-chip{
    display:inline-flex; align-items:center; gap:.45rem;
    padding:.35rem .85rem; border-radius:999px;
    background:rgba(0,88,78,.08); color:var(--ern);
    font-size:.74rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
}
.brand-chip i{font-size:.9rem;}

.divider{height:1px; background:linear-gradient(90deg,transparent,rgba(0,88,78,.18),transparent); margin:1.4rem 0;}

.login-title{font-size:1.5rem; font-weight:800; color:var(--ink); letter-spacing:-.03em; text-align:center; margin:0 0 .25rem;}
.login-sub{font-size:.86rem; color:var(--muted); text-align:center; margin:0 0 1.6rem;}

/* Form */
.lbl{font-size:.78rem; font-weight:700; color:var(--ink); margin-bottom:.4rem; display:block;}
.input-wrap{display:flex; align-items:center; border:1.5px solid #DCE8E6; border-radius:13px; background:#F5F9F8; overflow:hidden; transition:.2s;}
.input-wrap:focus-within{border-color:var(--ern); background:#fff; box-shadow:0 0 0 4px rgba(0,88,78,.12);}
.input-wrap-icon{width:46px; display:flex; align-items:center; justify-content:center; color:#8AA9A3; font-size:1rem; flex-shrink:0; transition:.2s;}
.input-wrap:focus-within .input-wrap-icon{color:var(--ern);}
.input-wrap input{flex:1; border:none; background:transparent; padding:.78rem .3rem .78rem 0; font-size:.94rem; color:var(--ink); font-family:inherit; outline:none;}
.input-wrap input::placeholder{color:#B0C8C4;}
.input-wrap-btn{background:none; border:none; padding:0 .8rem; color:#8AA9A3; font-size:.95rem; cursor:pointer; transition:.2s; display:flex; align-items:center;}
.input-wrap-btn:hover{color:var(--ern);}

.btn-login{
    width:100%; height:51px; border:none; border-radius:14px;
    background:linear-gradient(135deg,var(--ern-light) 0%,var(--ern) 100%);
    color:#fff; font-family:inherit; font-size:1rem; font-weight:700;
    cursor:pointer; box-shadow:0 8px 22px rgba(0,88,78,.35);
    transition:.22s cubic-bezier(.4,0,.2,1);
    display:flex; align-items:center; justify-content:center; gap:.5rem;
    position:relative; overflow:hidden;
}
.btn-login:hover{transform:translateY(-2px); box-shadow:0 12px 30px rgba(0,88,78,.45);}
.btn-login:active{transform:translateY(0);}
.btn-login.loading{pointer-events:none;}
.btn-login.loading .btn-text{opacity:0;}
.btn-login.loading::before{content:''; position:absolute; width:22px; height:22px; border:2.5px solid rgba(255,255,255,.3); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}

.login-alert{padding:.72rem 1rem; border-radius:12px; font-size:.84rem; font-weight:500; display:flex; align-items:center; gap:.6rem; margin-bottom:1.2rem; animation:shake .4s ease;}
@keyframes shake{0%,100%{transform:translateX(0);}20%,60%{transform:translateX(-5px);}40%,80%{transform:translateX(5px);}}
.login-alert.error{background:#FEF2F2; color:#991B1B; border:1px solid #FECACA;}
.login-alert.info{background:#F0FDF9; color:#065F46; border:1px solid #A7F3D0;}

/* Footer / geliştirici */
.login-footer{margin-top:1.8rem; text-align:center;}
.login-footer .copy{font-size:.74rem; color:var(--muted);}
.dev-credit{
    margin-top:.55rem; display:inline-flex; align-items:center; gap:.4rem;
    font-size:.72rem; color:var(--muted);
    padding:.3rem .7rem; border-radius:999px; background:rgba(0,88,78,.06);
}
.dev-credit strong{color:var(--ern);}
/* Sayfa altı holding imzası */
.page-sign{
    position:absolute; bottom:1.1rem; left:0; right:0; z-index:2;
    text-align:center; font-size:.72rem; color:rgba(255,255,255,.55);
    display:flex; align-items:center; justify-content:center; gap:.5rem;
}
.page-sign img{height:16px; filter:brightness(0) invert(1); opacity:.8;}

@media (max-width:480px){
    .card-login{padding:2.1rem 1.5rem 1.5rem; border-radius:22px;}
    .brand-logo{height:52px;}
    .page-sign{display:none;}
}
</style>
</head>
<body>

<div class="blob b1"></div>
<div class="blob b2"></div>
<div class="blob b3"></div>

<div class="card-login">

    <div class="brand-head">
        <img src="<?= h($ERN_LOGO) ?>" alt="ERN Holding" class="brand-logo"
             onerror="this.style.display='none'">
        <div class="brand-chip"><i class="bi bi-building-fill-check"></i> Beton Takip Sistemi</div>
    </div>

    <div class="divider"></div>

    <h1 class="login-title">Hoş Geldiniz</h1>
    <p class="login-sub">Devam etmek için hesabınıza giriş yapın</p>

    <?php if ($error): ?>
    <div class="login-alert error">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?= h($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($flashInfo): ?>
    <div class="login-alert info">
        <i class="bi bi-info-circle-fill"></i>
        <?= h($flashInfo) ?>
    </div>
    <?php endif; ?>

    <form method="post" novalidate id="loginForm">
        <?php if (!empty($redirect) && $redirect !== 'index.php'): ?>
            <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
        <?php endif; ?>

        <div style="margin-bottom:1.1rem">
            <label class="lbl" for="username">Kullanıcı Adı</label>
            <div class="input-wrap">
                <div class="input-wrap-icon"><i class="bi bi-person"></i></div>
                <input type="text" id="username" name="username"
                       value="<?= h($_POST['username'] ?? '') ?>"
                       autocomplete="username" autofocus required
                       placeholder="kullanici_adi">
            </div>
        </div>

        <div style="margin-bottom:1.6rem">
            <label class="lbl" for="password">Şifre</label>
            <div class="input-wrap">
                <div class="input-wrap-icon"><i class="bi bi-lock"></i></div>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required
                       placeholder="••••••••">
                <button type="button" class="input-wrap-btn" id="togglePwd" tabindex="-1">
                    <i class="bi bi-eye" id="eyeIcon"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-login" id="loginBtn">
            <span class="btn-text"><i class="bi bi-box-arrow-in-right"></i> Giriş Yap</span>
        </button>
    </form>

    <div class="login-footer">
        <div class="copy">ERN Holding &copy; <?= date('Y') ?> — Tüm hakları saklıdır</div>
        <div class="dev-credit">
            <i class="bi bi-code-slash"></i> Geliştirici: <strong>Tayyar Akbulut</strong>
        </div>
    </div>
</div>

<div class="page-sign">
    <img src="<?= h($ERN_LOGO_WHITE) ?>" alt="ERN Holding" onerror="this.style.display='none'">
    <span>ERN Holding • Beton Takip Sistemi</span>
</div>

<script>
// Şifre göster/gizle
document.getElementById('togglePwd').addEventListener('click', function () {
    var pwd  = document.getElementById('password');
    var icon = document.getElementById('eyeIcon');
    if (pwd.type === 'password') { pwd.type = 'text';  icon.className = 'bi bi-eye-slash'; }
    else                         { pwd.type = 'password'; icon.className = 'bi bi-eye'; }
});

// Submit loading state
document.getElementById('loginForm').addEventListener('submit', function () {
    document.getElementById('loginBtn').classList.add('loading');
});
</script>
</body>
</html>
