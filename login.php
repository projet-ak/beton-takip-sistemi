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

// ── Logolar (beyaz versiyonlar — koyu yeşil panel üzerinde) ───────────────────
// Dosya adlarında boşluk olduğu için rawurlencode ile güvenli URL üretiyoruz.
$LOGO_TAAHHUT = 'uploads/logo/' . rawurlencode('ERN Taahhut_Logo_Beyaz.png');
$LOGO_HOLDING = 'uploads/logo/' . rawurlencode('ERN Holding_Logo_Beyaz.png');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Giriş — Şantiye İş Takip Sistemi | Batı Yakası</title>
<link rel="icon" type="image/png" href="https://ern.com.tr/favicon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap">
<style>
/* ── ERN Login Page ─────────────────────────────────────── */
:root {
    --ern:       #00584E;
    --ern-dark:  #003D35;
    --ern-light: #007A6A;
    --ern-teal:  #00C9B1;
    --ern-gold:  #C9A84C;
}

*, *::before, *::after { box-sizing: border-box; }

body {
    font-family: 'Outfit', system-ui, -apple-system, sans-serif;
    margin: 0;
    min-height: 100vh;
    display: flex;
    overflow: hidden;
    background: var(--ern-dark);
}

/* ── Sol taraf — marka paneli ─── */
.login-left {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 3rem;
    position: relative;
    overflow: hidden;
    background: linear-gradient(145deg, var(--ern-dark) 0%, var(--ern) 60%, var(--ern-light) 100%);
}

/* Geometrik arka plan şekilleri */
.login-left::before {
    content: '';
    position: absolute;
    width: 600px; height: 600px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(0,200,180,.18) 0%, transparent 70%);
    top: -200px; right: -200px;
    animation: pulse-bg 8s ease-in-out infinite;
}
.login-left::after {
    content: '';
    position: absolute;
    width: 400px; height: 400px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(201,168,76,.12) 0%, transparent 70%);
    bottom: -150px; left: -100px;
    animation: pulse-bg 10s ease-in-out infinite reverse;
}
@keyframes pulse-bg {
    0%,100% { transform: scale(1); }
    50%      { transform: scale(1.15); }
}

/* Yüzen hexagon şekilleri */
.hex {
    position: absolute;
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 18px;
    animation: hexFloat linear infinite;
    transform-origin: center;
}
@keyframes hexFloat {
    0%   { transform: translateY(0) rotate(0deg); opacity: .6; }
    50%  { opacity: .15; }
    100% { transform: translateY(-100px) rotate(180deg); opacity: .6; }
}

.login-left-content {
    position: relative;
    z-index: 2;
    text-align: center;
    max-width: 540px;
    width: 100%;
}

.brand-logo {
    display: block;
    margin: 0 auto 2rem;
    height: 44px;
    filter: brightness(0) invert(1);
}

.brand-tagline {
    font-size: 1.95rem;
    font-weight: 800;
    color: #fff;
    line-height: 1.15;
    letter-spacing: -.03em;
    margin-bottom: .75rem;
    white-space: nowrap;
}
@media (max-width: 980px){ .brand-tagline{ font-size: 1.7rem; } }
.brand-tagline span {
    background: linear-gradient(90deg, var(--ern-teal), #a8f0e8);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.brand-sub {
    font-size: .95rem;
    color: rgba(255,255,255,.55);
    font-weight: 400;
    margin-bottom: 2.5rem;
    line-height: 1.6;
}

/* Feature pills — 2×3 grid */
.feature-pills { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; text-align: left; }
.feature-pill {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .6rem .8rem;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 12px;
    backdrop-filter: blur(8px);
    animation: slideInLeft .5s ease backwards;
    transition: transform .2s cubic-bezier(.4,0,.2,1), background .2s, border-color .2s, box-shadow .2s;
    cursor: default;
}
.feature-pill:hover {
    transform: translateY(-3px);
    background: rgba(255,255,255,.12);
    border-color: rgba(0,201,177,.45);
    box-shadow: 0 10px 24px rgba(0,0,0,.22);
}
.feature-pill:hover .feature-pill-icon { transform: scale(1.1) rotate(-4deg); background: rgba(0,200,180,.28); }
/* "Pek yakında" kutusu */
.feature-pill.soon { border-style: dashed; border-color: rgba(255,255,255,.22); background: rgba(255,255,255,.035); }
.feature-pill.soon .feature-pill-icon { background: rgba(201,168,76,.16); color: var(--ern-gold); }
.pill-soon-badge {
    display:inline-block; margin-top:.15rem; font-size:.6rem; font-weight:700; letter-spacing:.08em;
    text-transform:uppercase; color:#0D2E28; background:var(--ern-gold);
    padding:.05rem .4rem; border-radius:20px;
}
.feature-pill:nth-child(1) { animation-delay: .1s; }
.feature-pill:nth-child(2) { animation-delay: .18s; }
.feature-pill:nth-child(3) { animation-delay: .26s; }
.feature-pill:nth-child(4) { animation-delay: .34s; }
.feature-pill:nth-child(5) { animation-delay: .42s; }
@keyframes slideInLeft {
    from { opacity: 0; transform: translateX(-20px); }
    to   { opacity: 1; transform: none; }
}

/* ── Aktif proje rozeti (Batı Yakası) ─────────────────────── */
.proje-badge{
    display:flex; align-items:center; justify-content:center; gap:.7rem;
    width:fit-content; max-width:100%;
    background:#fff; border-radius:14px;
    padding:.55rem 1.1rem; margin:0 auto 1.4rem;
    box-shadow:0 8px 24px rgba(0,0,0,.22);
    animation:slideInLeft .55s ease backwards; animation-delay:.05s;
}
.proje-badge img{ height:30px; width:auto; max-width:150px; object-fit:contain; display:block; }
.proje-badge .pb-sep{ width:1px; height:26px; background:rgba(0,88,78,.18); }
.proje-badge .pb-text{ text-align:left; line-height:1.15; }
.proje-badge .pb-text small{ display:block; font-size:.6rem; letter-spacing:.14em; font-weight:700; color:var(--ern-light); text-transform:uppercase; }
.proje-badge .pb-text strong{ display:block; font-size:.86rem; font-weight:700; color:#0D2E28; letter-spacing:-.01em; }
.feature-pill-icon {
    width: 32px; height: 32px;
    background: rgba(0,200,180,.15);
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    color: var(--ern-teal);
    font-size: .95rem;
    flex-shrink: 0;
    transition: transform .25s cubic-bezier(.34,1.56,.64,1), background .2s;
}
.feature-pill-text { font-size: .74rem; color: rgba(255,255,255,.7); font-weight: 500; line-height: 1.28; }
.feature-pill-text strong { display: block; color: #fff; font-size: .82rem; margin-bottom: .05rem; }

/* ── Sağ taraf — form ─── */
.login-right {
    width: 480px;
    flex-shrink: 0;
    background: #fff;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 3rem 3.5rem;
    position: relative;
    box-shadow: -20px 0 60px rgba(0,0,0,.2);
}

.login-right-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 2.5rem;
}
.login-right-logo-icon {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, var(--ern-light), var(--ern));
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(0,88,78,.3);
}
.login-right-logo-icon i { color: #fff; font-size: 1.2rem; }
.login-right-logo-text strong { display: block; font-size: .9rem; font-weight: 700; color: #0D2E28; letter-spacing: -.01em; }
.login-right-logo-text span { font-size: .72rem; color: #5C7872; font-weight: 500; }

.login-title { font-size: 1.65rem; font-weight: 800; color: #0D2E28; letter-spacing: -.03em; margin-bottom: .35rem; }
.login-sub { font-size: .88rem; color: #5C7872; margin-bottom: 2rem; }

/* ── Sağ panel giriş animasyonu + mikro etkileşimler ───────── */
@keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: none; } }
.login-right > * { animation: fadeUp .55s cubic-bezier(.4,0,.2,1) backwards; }
.login-right-logo { animation-delay: .05s; }
.login-title      { animation-delay: .13s; }
.login-sub        { animation-delay: .19s; }
#loginForm        { animation-delay: .25s; }
.login-footer     { animation-delay: .33s; }

/* Sağ üst logo — hover'da canlan */
.login-right-logo-icon { transition: transform .3s cubic-bezier(.34,1.56,.64,1), box-shadow .3s; }
.login-right-logo:hover .login-right-logo-icon { transform: rotate(-6deg) scale(1.08); box-shadow: 0 6px 20px rgba(0,88,78,.45); }

/* Input alanları — hover & focus mikro hareketi */
.input-wrap { transition: border-color .2s, background .2s, box-shadow .2s, transform .2s; }
.input-wrap:hover { border-color: #B7D3CE; }
.input-wrap:focus-within { transform: translateY(-1px); }
.input-wrap:focus-within .input-wrap-icon { transform: scale(1.12); }
.input-wrap-icon { transition: color .2s, transform .2s; }

/* Giriş butonu — ikon kayması */
.btn-login .btn-text i { transition: transform .2s; }
.btn-login:hover .btn-text i { transform: translateX(4px); }

/* Form elemanları */
.lbl { font-size: .8rem; font-weight: 700; color: #0D2E28; margin-bottom: .4rem; display: block; }

.input-wrap {
    display: flex;
    align-items: center;
    border: 1.5px solid #DCE8E6;
    border-radius: 12px;
    background: #F5F9F8;
    overflow: hidden;
    transition: .2s;
}
.input-wrap:focus-within {
    border-color: var(--ern);
    background: #fff;
    box-shadow: 0 0 0 4px rgba(0,88,78,.12);
}
.input-wrap-icon {
    width: 44px;
    display: flex; align-items: center; justify-content: center;
    color: #8AA9A3;
    font-size: 1rem;
    flex-shrink: 0;
    transition: .2s;
}
.input-wrap:focus-within .input-wrap-icon { color: var(--ern); }
.input-wrap input {
    flex: 1;
    border: none;
    background: transparent;
    padding: .72rem .3rem .72rem 0;
    font-size: .93rem;
    color: #0D2E28;
    font-family: inherit;
    outline: none;
}
.input-wrap input::placeholder { color: #B0C8C4; }
.input-wrap-btn {
    background: none; border: none; padding: 0 .75rem;
    color: #8AA9A3; font-size: .95rem; cursor: pointer;
    transition: .2s; height: 100%;
    display: flex; align-items: center;
}
.input-wrap-btn:hover { color: var(--ern); }

.btn-login {
    width: 100%;
    height: 50px;
    border: none;
    border-radius: 13px;
    background: linear-gradient(135deg, var(--ern-light) 0%, var(--ern) 100%);
    color: #fff;
    font-family: inherit;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: .1px;
    cursor: pointer;
    box-shadow: 0 6px 20px rgba(0,88,78,.35);
    transition: .22s cubic-bezier(.4,0,.2,1);
    display: flex; align-items: center; justify-content: center; gap: .5rem;
    position: relative;
    overflow: hidden;
}
.btn-login::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,.15), transparent);
    opacity: 0;
    transition: opacity .2s;
}
.btn-login:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,88,78,.45); }
.btn-login:hover::after { opacity: 1; }
.btn-login:active { transform: translateY(0); }

/* Loading state */
.btn-login.loading { pointer-events: none; }
.btn-login.loading .btn-text { opacity: 0; }
.btn-login.loading::before {
    content: '';
    position: absolute;
    width: 22px; height: 22px;
    border: 2.5px solid rgba(255,255,255,.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.login-alert {
    padding: .72rem 1rem;
    border-radius: 11px;
    font-size: .85rem;
    font-weight: 500;
    display: flex; align-items: center; gap: .6rem;
    margin-bottom: 1.25rem;
    animation: shake .4s ease;
}
@keyframes shake {
    0%,100% { transform: translateX(0); }
    20%,60%  { transform: translateX(-5px); }
    40%,80%  { transform: translateX(5px); }
}
.login-alert.error { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
.login-alert.info  { background: #F0FDF9; color: #065F46; border: 1px solid #A7F3D0; }

.login-footer {
    margin-top: auto;
    padding-top: 1.5rem;
    font-size: .75rem;
    color: #8AA9A3;
    text-align: center;
}

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 860px) {
    .login-left { display: none; }
    .login-right { width: 100%; padding: 2.5rem 2rem; }
}
@media (max-width: 420px) {
    .login-right { padding: 2rem 1.25rem; }
}

/* ── İki beyaz logo — koyu yeşil panel üzerinde ─────────────── */
.logo-lockup{
    display:flex; align-items:center; justify-content:center; gap:1.6rem;
    width:fit-content; max-width:100%;
    background:transparent; padding:0; margin:0 auto 1.6rem;
    animation:slideInLeft .6s ease backwards;
}
.logo-lockup img{ height:72px; width:auto; max-width:210px; object-fit:contain; display:block; }
.logo-sep{ width:1px; height:58px; align-self:center; background:linear-gradient(180deg,transparent,rgba(255,255,255,.35),transparent); }

/* ── Dalgalanma efekti — tüm yeşil paneli kaplar ────────────── */
.waves{ position:absolute; inset:0; z-index:1; overflow:hidden; pointer-events:none; }
.waves svg{ width:100%; height:100%; }
.wave-parallax > use{ animation:waveMove 12s cubic-bezier(.55,.5,.45,.5) infinite; }
.wave-parallax > use:nth-child(1){ animation-delay:-2s;  animation-duration:9s;  }
.wave-parallax > use:nth-child(2){ animation-delay:-4s;  animation-duration:13s; }
.wave-parallax > use:nth-child(3){ animation-delay:-3s;  animation-duration:17s; }
.wave-parallax > use:nth-child(4){ animation-delay:-5s;  animation-duration:21s; }
@keyframes waveMove{
    0%   { transform:translate3d(-90px,0,0); }
    100% { transform:translate3d(85px,0,0); }
}
</style>
</head>
<body>

<!-- Sol Marka Paneli -->
<div class="login-left">
    <!-- Floating hex shapes -->
    <div class="hex" style="width:80px;height:80px;bottom:20%;left:8%;animation-duration:12s;"></div>
    <div class="hex" style="width:50px;height:50px;bottom:35%;left:20%;animation-duration:9s;animation-delay:-3s;"></div>
    <div class="hex" style="width:110px;height:110px;top:15%;right:12%;animation-duration:15s;animation-delay:-5s;"></div>
    <div class="hex" style="width:40px;height:40px;top:40%;left:5%;animation-duration:8s;animation-delay:-2s;"></div>

    <div class="login-left-content">
        <!-- İki kurumsal logo — beyaz kart üzerinde renkleri korunur -->
        <div class="logo-lockup">
            <img src="<?= h($LOGO_TAAHHUT) ?>" alt="ERN Taahhüt"
                 onerror="this.style.display='none'">
            <span class="logo-sep"></span>
            <img src="<?= h($LOGO_HOLDING) ?>" alt="ERN Holding"
                 onerror="this.style.display='none'">
        </div>

        <!-- Aktif proje rozeti — Batı Yakası -->
        <div class="proje-badge">
            <img src="https://batiyakasi.com/wp-content/uploads/2024/05/logo-orj2.svg" alt="Batı Yakası"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='none'">
            <span class="pb-sep"></span>
            <div class="pb-text">
                <small>Aktif Proje</small>
                <strong>Batı Yakası Projesi</strong>
            </div>
        </div>

        <div class="brand-tagline">
            Şantiye İş Takip <span>Sistemi</span>
        </div>
        <p class="brand-sub">
            Sahadan ofise <strong style="color:rgba(255,255,255,.8)">tek platform</strong> — beton, demir, seramik, depo ve akaryakıt.<br>
            QR/AI okuma, canlı stok &amp; zayiat, anlık raporlama ve rol tabanlı erişim.
        </p>

        <div class="feature-pills">
            <div class="feature-pill">
                <div class="feature-pill-icon"><i class="bi bi-truck"></i></div>
                <div class="feature-pill-text">
                    <strong>Beton &amp; İrsaliye</strong>
                    QR + KGS/THBB DataMatrix okuma, iki aşamalı onay, canlı zayiat
                </div>
            </div>
            <div class="feature-pill">
                <div class="feature-pill-icon"><i class="bi bi-rulers"></i></div>
                <div class="feature-pill-text">
                    <strong>İnşaat Demiri</strong>
                    Sipariş → sevkiyat → kantar → tutanak → taşeron bakiye zinciri
                </div>
            </div>
            <div class="feature-pill">
                <div class="feature-pill-icon"><i class="bi bi-grid-1x2"></i></div>
                <div class="feature-pill-text">
                    <strong>Seramik Ambarı</strong>
                    Giriş / çıkış, canlı stok ve palet takibi
                </div>
            </div>
            <div class="feature-pill">
                <div class="feature-pill-icon"><i class="bi bi-box-seam"></i></div>
                <div class="feature-pill-text">
                    <strong>Depo Yönetimi</strong>
                    Sarf, demirbaş &amp; el aletleri — zimmet ve mali değer
                </div>
            </div>
            <div class="feature-pill">
                <div class="feature-pill-icon"><i class="bi bi-fuel-pump"></i></div>
                <div class="feature-pill-text">
                    <strong>Akaryakıt Takibi</strong>
                    Mazot stok + araç/makine bazında aylık tüketim
                </div>
            </div>
            <div class="feature-pill soon">
                <div class="feature-pill-icon"><i class="bi bi-stars"></i></div>
                <div class="feature-pill-text">
                    <strong>Yeni Modül</strong>
                    Mobil uygulama &amp; daha fazlası
                    <span class="pill-soon-badge">Pek Yakında</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Dalgalanma efekti — tüm yeşil paneli kaplayan katmanlı dalgalar -->
    <div class="waves">
        <svg viewBox="0 0 150 80" preserveAspectRatio="none">
            <defs>
                <path id="wv" d="M-160 16c30 0 58-12 88-12s 58 12 88 12 58-12 88-12 58 12 88 12 v90h-352z"></path>
            </defs>
            <g class="wave-parallax">
                <use href="#wv" x="48" y="6"  fill="rgba(0,201,177,.12)"></use>
                <use href="#wv" x="48" y="20" fill="rgba(255,255,255,.07)"></use>
                <use href="#wv" x="48" y="34" fill="rgba(0,201,177,.10)"></use>
                <use href="#wv" x="48" y="48" fill="rgba(0,61,53,.34)"></use>
            </g>
        </svg>
    </div>
</div>

<!-- Sağ Form Paneli -->
<div class="login-right">

    <div class="login-right-logo">
        <div class="login-right-logo-icon">
            <i class="bi bi-building-fill-check"></i>
        </div>
        <div class="login-right-logo-text">
            <strong>ERN Holding</strong>
            <span>Şantiye İş Takip Sistemi</span>
        </div>
    </div>

    <div class="login-title">Hoş Geldiniz</div>
    <p class="login-sub">Hesabınıza giriş yapın</p>

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

        <div style="margin-bottom:1.5rem">
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
        ERN Holding &copy; <?= date('Y') ?> &nbsp;&mdash;&nbsp; Şantiye İş Takip Sistemi
        <br>
        <a href="tanitim.php" style="font-size:.75rem;color:var(--ern);text-decoration:none;font-weight:600"><i class="bi bi-stars"></i> Sistemi Tanıyın</a>
        <br>
        <span style="font-size:.7rem;opacity:.6">Geliştirici: <strong style="color:var(--ern);opacity:1">Tayyar Akbulut</strong></span>
    </div>
</div>

<script>
// Şifre göster/gizle
document.getElementById('togglePwd').addEventListener('click', function () {
    var pwd  = document.getElementById('password');
    var icon = document.getElementById('eyeIcon');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        pwd.type = 'password';
        icon.className = 'bi bi-eye';
    }
});

// Submit loading state
document.getElementById('loginForm').addEventListener('submit', function () {
    document.getElementById('loginBtn').classList.add('loading');
});
</script>
</body>
</html>
