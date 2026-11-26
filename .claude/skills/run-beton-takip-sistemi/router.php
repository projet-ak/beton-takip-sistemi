<?php
// ⚠ YALNIZ yerel `php -S` için. Web sunucusunda (canlı) çalışmaz: her isteğe yönetici oturumu verirdi.
// deploy2.php .claude/ klasörünü canlıya zaten yazmaz (dp_sunucuda_tutulmaz); bu ikinci kilittir.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
// php -S yönlendiricisi: her isteğe oturum açmış kullanıcı + sabit CSRF ('tok') verir.
// Rol 'X-Rol' başlığıyla değişir (varsayılan admin).
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$kok = $_SERVER['DOCUMENT_ROOT'];
$f = $kok . $uri;
if (is_dir($f)) $f = rtrim($f, '/') . '/index.php';
if (is_file($f) && !str_ends_with($f, '.php')) return false;   // statik dosya
if (!is_file($f)) { http_response_code(404); echo "404 $uri"; return true; }
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user'] = ['id' => 1, 'username' => 'tayyar_akbulut', 'full_name' => 'Sistem Yöneticisi',
                     'role' => $_SERVER['HTTP_X_ROL'] ?? 'admin', 'modul_erisim' => null];
$_SESSION['last_activity'] = time();
$_SESSION['csrf'] = 'tok';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = substr($f, strlen($kok));
chdir(dirname($f));
require $f;
