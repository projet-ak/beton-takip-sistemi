<?php
// ─── Beton Takip — Auto Deploy Webhook ───────────────────────────────────────
// Kullanım: https://takbulut.com/deploy.php?token=TOKEN
// ─────────────────────────────────────────────────────────────────────────────

define('DEPLOY_TOKEN',  '9fe41d55f7324123586fd5a2be2fee6b3f5355ff4993fa1f');
define('REPO_URL',      'https://github.com/projet-ak/beton-takip-sistemi.git');
define('BRANCH',        'claude/organize-control-panel-hUe4z');
define('DEPLOY_DIR',    __DIR__);
define('LOG_FILE',      __DIR__ . '/deploy.log');

header('Content-Type: application/json; charset=utf-8');

// Token kontrolü
if (($_GET['token'] ?? '') !== DEPLOY_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit;
}

// exec() kontrolü
if (!function_exists('exec') && !function_exists('shell_exec')) {
    echo json_encode(['ok' => false, 'msg' => 'exec() disabled on this host']);
    exit;
}

function run(string $cmd): array {
    $output = []; $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    return ['cmd' => $cmd, 'out' => implode("\n", $output), 'code' => $code];
}

$log   = [];
$start = microtime(true);
$dir   = escapeshellarg(DEPLOY_DIR);
$repo  = escapeshellarg(REPO_URL);
$branch = escapeshellarg(BRANCH);

// Git kurulu mu?
$gitCheck = run('git --version');
if ($gitCheck['code'] !== 0) {
    echo json_encode(['ok' => false, 'msg' => 'git not found on server', 'detail' => $gitCheck['out']]);
    exit;
}
$log[] = $gitCheck;

if (!is_dir(DEPLOY_DIR . '/.git')) {
    // İlk kurulum: mevcut dizinde git init + remote + fetch + checkout
    $log[] = run("cd $dir && git init");
    $log[] = run("cd $dir && git remote add origin " . escapeshellarg(REPO_URL));
    $log[] = run("cd $dir && git fetch origin " . BRANCH);
    $log[] = run("cd $dir && git checkout -f -b " . BRANCH . " origin/" . BRANCH);
} else {
    // Güncelleme: fetch + reset (yerel değişiklikleri ezmeden güvenli)
    $log[] = run("cd $dir && git fetch origin " . BRANCH);
    $log[] = run("cd $dir && git checkout -f " . BRANCH);
    $log[] = run("cd $dir && git reset --hard origin/" . BRANCH);
}

// Son commit bilgisi
$commitLog = run("cd $dir && git log -1 --format='%h %s (%ci)'");
$log[] = $commitLog;

$elapsed  = round(microtime(true) - $start, 2);
$allOk    = empty(array_filter($log, fn($l) => $l['code'] !== 0));
$lastCommit = trim($commitLog['out']);

// Loglama
$entry = date('Y-m-d H:i:s') . " | ok=$allOk | {$elapsed}s | $lastCommit\n";
file_put_contents(LOG_FILE, $entry, FILE_APPEND);

echo json_encode([
    'ok'      => $allOk,
    'branch'  => BRANCH,
    'commit'  => $lastCommit,
    'elapsed' => $elapsed . 's',
    'log'     => array_map(fn($l) => ['cmd' => $l['cmd'], 'out' => $l['out']], $log),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
