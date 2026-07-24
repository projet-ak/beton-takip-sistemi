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
$LOGO_TAAHHUT = 'uploads/' . rawurlencode('ERN Taahhut_Logo_Beyaz.png');
$LOGO_HOLDING = 'uploads/' . rawurlencode('ERN Holding_Logo_Beyaz.png');

$moduller = [
    ['bi-truck',      'Beton & İrsaliye', 'QR + DataMatrix okuma · iki aşamalı onay · canlı zayiat'],
    ['bi-rulers',     'İnşaat Demiri',    'Sipariş → sevkiyat → kantar → tutanak → bakiye'],
    ['bi-grid-1x2',   'Seramik Ambarı',   'Giriş / çıkış · canlı stok · palet takibi'],
    ['bi-box-seam',   'Depo Yönetimi',    'Sarf · demirbaş · el aletleri — zimmet & mali değer'],
    ['bi-fuel-pump',  'Akaryakıt',        'Mazot stok + araç/makine bazında aylık tüketim'],
];
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
/* ══ ERN Login — v4 (minimalist / editorial) ═══════════════════════════════ */
:root{
    --ern:#00584E; --ern-2:#0A7D6C; --ern-deep:#042F29;
    --teal:#00C9B1; --gold:#C9A84C;
    --ink:#0C1F1B; --muted:#657B76; --line:#E8EEEC; --line-2:#F1F5F4;
    --surface:#FFFFFF; --paper:#FBFCFB;
    --r:14px;
    --ease:cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{ box-sizing:border-box; }
html,body{ height:100%; }
body{
    margin:0; min-height:100vh; display:flex;
    font-family:'Outfit',system-ui,-apple-system,sans-serif;
    color:var(--ink); background:var(--paper);
    -webkit-font-smoothing:antialiased;
}
a{ color:inherit; }

/* ── Sol: marka paneli ───────────────────────────────────────────────────── */
.brand{
    flex:1 1 0; min-width:0; position:relative; overflow:hidden;
    display:flex; flex-direction:column; justify-content:center;
    padding:clamp(2.5rem,5vw,5rem);
    background:
        radial-gradient(120% 90% at 88% 8%, rgba(0,201,177,.20), transparent 46%),
        radial-gradient(90% 80% at 0% 100%, rgba(201,168,76,.10), transparent 50%),
        linear-gradient(155deg, var(--ern-deep) 0%, var(--ern) 72%, var(--ern-2) 100%);
    color:#fff;
}
/* İnce çizgi dokusu (grid) — çok düşük opaklık */
.brand::before{
    content:''; position:absolute; inset:0; pointer-events:none;
    background-image:
        linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px);
    background-size:44px 44px;
    mask-image:radial-gradient(120% 100% at 70% 20%, #000 30%, transparent 78%);
    -webkit-mask-image:radial-gradient(120% 100% at 70% 20%, #000 30%, transparent 78%);
}
.brand-inner{ position:relative; z-index:1; width:100%; max-width:480px; }

.logo-lockup{ display:flex; align-items:center; gap:1.4rem; margin-bottom:clamp(2rem,5vh,3.25rem); }
.logo-lockup img{ height:58px; width:auto; max-width:190px; object-fit:contain; display:block; }
.logo-sep{ width:1px; height:46px; background:linear-gradient(180deg,transparent,rgba(255,255,255,.4),transparent); }

.eyebrow{
    display:inline-flex; align-items:center; gap:.5rem;
    font-size:.7rem; font-weight:700; letter-spacing:.2em; text-transform:uppercase;
    color:var(--teal); margin-bottom:1.1rem;
}
.eyebrow::before{ content:''; width:22px; height:1px; background:var(--teal); opacity:.7; }

.headline{
    font-size:clamp(1.9rem,3vw,2.7rem); font-weight:800; line-height:1.08;
    letter-spacing:-.035em; margin:0 0 1rem;
}
.headline em{ font-style:normal; color:var(--teal); }
.lede{
    font-size:1rem; line-height:1.6; color:rgba(255,255,255,.6);
    max-width:34ch; margin:0 0 clamp(1.75rem,4vh,2.75rem);
}

/* Modül listesi — sakin, ince ayraçlı */
.mods{ display:flex; flex-direction:column; }
.mod{
    display:flex; align-items:center; gap:1rem; padding:.85rem 0;
    border-top:1px solid rgba(255,255,255,.09);
}
.mod:last-child{ border-bottom:1px solid rgba(255,255,255,.09); }
.mod-ic{
    flex-shrink:0; width:40px; height:40px; border-radius:11px;
    display:flex; align-items:center; justify-content:center; font-size:1.05rem;
    color:var(--teal); background:rgba(0,201,177,.12);
    border:1px solid rgba(0,201,177,.18);
    transition:transform .3s var(--ease), background .3s, color .3s;
}
.mod:hover .mod-ic{ transform:translateY(-2px); background:var(--teal); color:var(--ern-deep); }
.mod-tx strong{ display:block; font-size:.92rem; font-weight:600; color:#fff; letter-spacing:-.01em; }
.mod-tx span{ display:block; font-size:.78rem; color:rgba(255,255,255,.5); margin-top:.1rem; }

.brand-foot{
    display:flex; align-items:center; gap:.8rem; margin-top:clamp(1.75rem,4vh,2.75rem);
    padding:.7rem .95rem; width:fit-content; max-width:100%;
    background:rgba(255,255,255,.055); border:1px solid rgba(255,255,255,.1);
    border-radius:12px; backdrop-filter:blur(6px);
}
.brand-foot img{ height:26px; width:auto; max-width:130px; object-fit:contain; filter:brightness(0) invert(1); opacity:.92; }
.brand-foot .bf-sep{ width:1px; height:24px; background:rgba(255,255,255,.18); }
.brand-foot small{ display:block; font-size:.58rem; letter-spacing:.16em; font-weight:700; text-transform:uppercase; color:var(--teal); }
.brand-foot strong{ display:block; font-size:.82rem; font-weight:600; color:#fff; }

/* ── Sağ: form paneli ────────────────────────────────────────────────────── */
.auth{
    flex:0 0 clamp(400px,34vw,520px); background:var(--surface);
    display:flex; flex-direction:column; justify-content:center;
    padding:clamp(2.25rem,4vw,3.5rem);
    border-left:1px solid var(--line);
}
.auth-inner{ width:100%; max-width:360px; margin:0 auto; }

.mark{ display:flex; align-items:center; gap:.7rem; margin-bottom:clamp(2rem,6vh,3rem); }
.mark-ic{
    width:40px; height:40px; border-radius:11px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:1.1rem;
    background:linear-gradient(135deg,var(--ern-2),var(--ern));
    box-shadow:0 6px 16px rgba(0,88,78,.28);
}
.mark-tx strong{ display:block; font-size:.86rem; font-weight:700; letter-spacing:-.01em; }
.mark-tx span{ display:block; font-size:.72rem; color:var(--muted); }

.title{ font-size:1.6rem; font-weight:800; letter-spacing:-.03em; margin:0 0 .3rem; }
.subtitle{ font-size:.9rem; color:var(--muted); margin:0 0 1.9rem; }

.alert{
    padding:.72rem .9rem; border-radius:11px; font-size:.84rem; font-weight:500;
    display:flex; align-items:flex-start; gap:.55rem; margin-bottom:1.25rem; line-height:1.4;
}
.alert i{ margin-top:.08rem; }
.alert.err{ background:#FEF2F2; color:#9F1239; border:1px solid #FBD5D5; }
.alert.err{ animation:shake .4s var(--ease); }
.alert.ok{ background:#F0FDF9; color:#065F46; border:1px solid #B7EBDC; }
@keyframes shake{ 0%,100%{transform:translateX(0)} 25%{transform:translateX(-4px)} 75%{transform:translateX(4px)} }

.field{ margin-bottom:1.05rem; }
.field label{ display:block; font-size:.78rem; font-weight:600; color:var(--ink); margin-bottom:.4rem; }
.control{
    display:flex; align-items:center; gap:.1rem;
    border:1.5px solid var(--line); border-radius:12px; background:var(--paper);
    transition:border-color .18s, background .18s, box-shadow .18s;
}
.control:hover{ border-color:#CFE0DC; }
.control:focus-within{ border-color:var(--ern); background:#fff; box-shadow:0 0 0 4px rgba(0,88,78,.10); }
.control > i{ width:44px; text-align:center; color:#93ACA6; font-size:1rem; transition:color .18s; flex-shrink:0; }
.control:focus-within > i{ color:var(--ern); }
.control input{
    flex:1; min-width:0; border:none; background:transparent; outline:none;
    padding:.78rem .9rem .78rem 0; font-family:inherit; font-size:.94rem; color:var(--ink);
}
.control input::placeholder{ color:#AEC4BF; }
.control .peek{
    background:none; border:none; cursor:pointer; color:#93ACA6; font-size:.95rem;
    padding:0 .85rem; height:100%; display:flex; align-items:center; transition:color .18s;
}
.control .peek:hover{ color:var(--ern); }

.submit{
    width:100%; height:50px; margin-top:.5rem; border:none; border-radius:13px;
    font-family:inherit; font-size:.97rem; font-weight:700; color:#fff; cursor:pointer;
    background:linear-gradient(135deg,var(--ern-2) 0%, var(--ern) 100%);
    box-shadow:0 8px 22px rgba(0,88,78,.28);
    display:flex; align-items:center; justify-content:center; gap:.5rem;
    position:relative; overflow:hidden; transition:transform .2s var(--ease), box-shadow .2s;
}
.submit .tx i{ transition:transform .2s var(--ease); }
.submit:hover{ transform:translateY(-2px); box-shadow:0 12px 30px rgba(0,88,78,.38); }
.submit:hover .tx i{ transform:translateX(4px); }
.submit:active{ transform:translateY(0); }
.submit.loading{ pointer-events:none; }
.submit.loading .tx{ opacity:0; }
.submit.loading::after{
    content:''; position:absolute; width:21px; height:21px; border-radius:50%;
    border:2.5px solid rgba(255,255,255,.35); border-top-color:#fff; animation:spin .7s linear infinite;
}
@keyframes spin{ to{ transform:rotate(360deg); } }

.foot{ margin-top:2.25rem; text-align:center; font-size:.74rem; color:#94ABA5; line-height:1.7; }
.foot strong{ color:var(--ern); font-weight:600; }

:focus-visible{ outline:2px solid var(--ern); outline-offset:2px; border-radius:6px; }

/* Giriş animasyonu */
@keyframes rise{ from{opacity:0; transform:translateY(14px);} to{opacity:1; transform:none;} }
.brand-inner > *, .auth-inner > *{ animation:rise .6s var(--ease) backwards; }
.auth-inner > *:nth-child(1){animation-delay:.04s} .auth-inner > *:nth-child(2){animation-delay:.10s}
.auth-inner > *:nth-child(3){animation-delay:.16s} .auth-inner > *:nth-child(4){animation-delay:.22s}
.auth-inner > *:nth-child(5){animation-delay:.28s} .auth-inner > *:nth-child(6){animation-delay:.34s}
.brand-inner > *:nth-child(2){animation-delay:.10s} .brand-inner > *:nth-child(3){animation-delay:.16s}
.brand-inner > *:nth-child(4){animation-delay:.22s} .brand-inner > *:nth-child(5){animation-delay:.28s}
.brand-inner > *:nth-child(6){animation-delay:.34s}

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width:920px){
    body{ flex-direction:column; }
    .brand{ display:none; }
    .auth{ flex:1 1 auto; border-left:none; }
}
@media (prefers-reduced-motion:reduce){
    *{ animation:none !important; transition:none !important; }
}
</style>
</head>
<body>

<!-- ══ Sol: Marka ═══════════════════════════════════════════════════════════ -->
<section class="brand">
    <div class="brand-inner">
        <div class="logo-lockup">
            <img src="<?= h($LOGO_TAAHHUT) ?>" alt="ERN Taahhüt" onerror="this.style.display='none'">
            <span class="logo-sep"></span>
            <img src="<?= h($LOGO_HOLDING) ?>" alt="ERN Holding" onerror="this.style.display='none'">
        </div>

        <span class="eyebrow">Şantiye İş Takip Sistemi</span>
        <h1 class="headline">Sahadan ofise,<br><em>tek platform.</em></h1>
        <p class="lede">Beton, demir, seramik, depo ve akaryakıt; QR/AI okuma, canlı stok &amp; zayiat ve anlık raporlama — hepsi bir arada.</p>

        <div class="mods">
            <?php foreach ($moduller as [$ic,$ad,$aciklama]): ?>
            <div class="mod">
                <div class="mod-ic"><i class="bi <?= $ic ?>"></i></div>
                <div class="mod-tx"><strong><?= h($ad) ?></strong><span><?= h($aciklama) ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="brand-foot">
            <img src="https://batiyakasi.com/wp-content/uploads/2024/05/logo-orj2.svg" alt="Batı Yakası"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='none'">
            <span class="bf-sep"></span>
            <div><small>Aktif Proje</small><strong>Batı Yakası Projesi</strong></div>
        </div>
    </div>
</section>

<!-- ══ Sağ: Form ════════════════════════════════════════════════════════════ -->
<main class="auth">
    <div class="auth-inner">
        <div class="mark">
            <div class="mark-ic"><i class="bi bi-building-fill-check"></i></div>
            <div class="mark-tx"><strong>ERN Holding</strong><span>Şantiye İş Takip Sistemi</span></div>
        </div>

        <h2 class="title">Hoş geldiniz</h2>
        <p class="subtitle">Devam etmek için hesabınıza giriş yapın.</p>

        <?php if ($error): ?>
        <div class="alert err"><i class="bi bi-exclamation-triangle-fill"></i><span><?= h($error) ?></span></div>
        <?php endif; ?>
        <?php if ($flashInfo): ?>
        <div class="alert ok"><i class="bi bi-info-circle-fill"></i><span><?= h($flashInfo) ?></span></div>
        <?php endif; ?>

        <form method="post" novalidate id="loginForm">
            <?php if (!empty($redirect) && $redirect !== 'index.php'): ?>
                <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
            <?php endif; ?>

            <div class="field">
                <label for="username">Kullanıcı Adı</label>
                <div class="control">
                    <i class="bi bi-person"></i>
                    <input type="text" id="username" name="username" value="<?= h($_POST['username'] ?? '') ?>"
                           autocomplete="username" autofocus required placeholder="kullanici_adi">
                </div>
            </div>

            <div class="field">
                <label for="password">Şifre</label>
                <div class="control">
                    <i class="bi bi-lock"></i>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" required placeholder="••••••••">
                    <button type="button" class="peek" id="togglePwd" tabindex="-1" aria-label="Şifreyi göster/gizle">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="submit" id="loginBtn">
                <span class="tx"><i class="bi bi-box-arrow-in-right"></i> Giriş Yap</span>
            </button>
        </form>

        <div class="foot">
            ERN Holding &copy; <?= date('Y') ?> — Şantiye İş Takip Sistemi<br>
            <span style="opacity:.75">Geliştirici: <strong>Tayyar Akbulut</strong></span>
        </div>
    </div>
</main>

<script>
document.getElementById('togglePwd').addEventListener('click', function () {
    var pwd = document.getElementById('password'), icon = document.getElementById('eyeIcon');
    var show = pwd.type === 'password';
    pwd.type = show ? 'text' : 'password';
    icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
});
document.getElementById('loginForm').addEventListener('submit', function () {
    document.getElementById('loginBtn').classList.add('loading');
});
</script>
</body>
</html>
