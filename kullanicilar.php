<?php
/**
 * kullanicilar.php — Kullanıcı yönetimi (SADECE admin)
 *
 * Gelişmiş yetkilendirme (2026-09): rol bir ETİKET + ŞABLONDUR; gerçek yetki kullanıcı bazlı
 * **modül × işlem** matrisidir (`users.yetkiler` JSON — okuma / veri girişi / değiştirme / onay / rapor).
 * Matris kaydedilen kullanıcıda tüm `can_*()` ve `has_role()` kontrolleri matrise bakar;
 * matrisi olmayan eski kullanıcılar rol bazlı eski davranışla çalışmaya devam eder.
 * Yönetici (admin) rolü her zaman sınırsızdır.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }

require_auth(['admin']);
require_once __DIR__ . '/includes/db.php';

modul_erisim_semasi($pdo);   // users.modul_erisim (eski liste — matrisle senkron tutulur)
yetki_semasi($pdo);          // users.yetkiler + unvan, role VARCHAR

$pageTitle  = 'Kullanıcı Yönetimi — Beton Takip Sistemi';
$currentUid = current_user_id();
$error      = '';
$modulTum   = modul_listesi(true);          // gizli modüller de (rozetle) — izin şimdiden verilebilsin
$islemler   = YETKI_ISLEMLER;

/** Kayıttaki matrisi ekrana hazırlar; matris yoksa rol şablonu (+ eski modül listesi süzgeci). */
$matrisHazirla = function (?array $u): array {
    if (!$u) return [];
    $m = yetki_normalize($u['yetkiler'] ?? null);
    if ($m) return $m;
    $m = yetki_sablon($u['role'] ?? 'izleyici');
    $liste = array_values(array_filter(array_map('trim', explode(',', (string)($u['modul_erisim'] ?? '')))));
    if ($liste) $m = array_intersect_key($m, array_flip($liste));
    return $m;
};

// ── Kaydet / güncelle / sil ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']    ?? '';
    $editId   = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $unvan    = trim($_POST['unvan'] ?? '');
    $role     = $_POST['role']      ?? '';
    $password = $_POST['password']  ?? '';
    $aktif    = isset($_POST['aktif']) ? 1 : 0;

    // Yetki matrisi: y[modül][] = işlem. Normalize: bilinmeyen modül/işlem düşer, oku otomatik gelir.
    $matris = yetki_normalize((array)($_POST['y'] ?? []));
    // Eski modul_erisim listesi matrisle senkron (eski ekranlar/yedek): tüm modüller ise NULL
    $modulStr = ($matris && count($matris) < count(MODULLER)) ? implode(',', array_keys($matris)) : null;
    $yetkiJson = $role === 'admin' ? null : ($matris ? json_encode($matris, JSON_UNESCAPED_UNICODE) : null);

    // Listeden tek tıkla aktif/pasif (hesabı silmeden erişimi kapatmanın yolu)
    if ($action === 'durum' && $editId) {
        if ($editId === $currentUid) {
            flash('error', 'Kendi hesabınızı pasife alamazsınız.');
        } else {
            $st = $pdo->prepare("SELECT username, aktif FROM users WHERE id=?"); $st->execute([$editId]);
            $eski = $st->fetch();
            if (!$eski) flash('error', 'Kullanıcı bulunamadı.');
            else {
                $yeniDurum = (int)!$eski['aktif'];
                $pdo->prepare("UPDATE users SET aktif=? WHERE id=?")->execute([$yeniDurum, $editId]);
                audit_log($pdo, 'users', $editId, 'UPDATE', ['aktif' => (int)$eski['aktif']], ['aktif' => $yeniDurum], $currentUid);
                flash('success', $eski['username'] . ($yeniDurum ? ' aktif edildi — sisteme giriş yapabilir.' : ' pasife alındı — artık giriş yapamaz.'));
            }
        }
        redirect('kullanicilar.php' . (($_POST['q'] ?? '') !== '' || ($_POST['durum'] ?? '') !== '' ? '?' . http_build_query(array_filter(['durum' => $_POST['durum'] ?? '', 'q' => $_POST['q'] ?? ''])) : ''));
    }

    if ($action === 'delete' && $editId) {
        if ($editId === $currentUid) {
            flash('error', 'Kendi hesabınızı silemezsiniz.');
        } else {
            $eski = $pdo->prepare("SELECT username, role FROM users WHERE id=?"); $eski->execute([$editId]);
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$editId]);
            audit_log($pdo, 'users', $editId, 'DELETE', $eski->fetch() ?: null, null, $currentUid);
            flash('success', 'Kullanıcı silindi.');
        }
        redirect('kullanicilar.php');
    }

    if (!$username || !$role || !isset(ROLLER[$role])) {
        $error = 'Kullanıcı adı ve rol zorunludur.';
    } elseif ($role !== 'admin' && !$matris) {
        $error = 'En az bir modülde "Okuma" yetkisi seçilmeli — aksi halde kullanıcı hiçbir sayfayı açamaz.';
    } elseif ($editId && $editId === $currentUid && $role !== 'admin') {
        $error = 'Kendi hesabınızın yönetici rolünü kaldıramazsınız.';
    } elseif ($editId && $editId === $currentUid && !$aktif) {
        $error = 'Kendi hesabınızı pasife alamazsınız.';
    } else {
        try {
            if ($editId) {
                $eskiSt = $pdo->prepare("SELECT username, full_name, unvan, role, aktif, modul_erisim, yetkiler FROM users WHERE id=?");
                $eskiSt->execute([$editId]); $eski = $eskiSt->fetch() ?: null;
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET username=?,full_name=?,unvan=?,role=?,aktif=?,modul_erisim=?,yetkiler=?,password_hash=? WHERE id=?");
                    $stmt->execute([$username, $fullName, $unvan ?: null, $role, $aktif, $modulStr, $yetkiJson, $hash, $editId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username=?,full_name=?,unvan=?,role=?,aktif=?,modul_erisim=?,yetkiler=? WHERE id=?");
                    $stmt->execute([$username, $fullName, $unvan ?: null, $role, $aktif, $modulStr, $yetkiJson, $editId]);
                }
                audit_log($pdo, 'users', $editId, 'UPDATE', $eski,
                    ['username' => $username, 'full_name' => $fullName, 'unvan' => $unvan, 'role' => $role, 'aktif' => $aktif,
                     'modul_erisim' => $modulStr, 'yetkiler' => $yetkiJson, 'sifre' => $password !== '' ? 'değişti' : 'aynı'], $currentUid);
                flash('success', 'Kullanıcı güncellendi. Yetki değişikliği anında geçerlidir (yeniden giriş gerekmez).');
            } else {
                if (strlen($password) < 6) {
                    $error = 'Şifre en az 6 karakter olmalıdır.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (username,password_hash,full_name,unvan,role,aktif,modul_erisim,yetkiler) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->execute([$username, $hash, $fullName, $unvan ?: null, $role, $aktif, $modulStr, $yetkiJson]);
                    $yeniId = (int)$pdo->lastInsertId();
                    audit_log($pdo, 'users', $yeniId, 'INSERT', null,
                        ['username' => $username, 'full_name' => $fullName, 'unvan' => $unvan, 'role' => $role, 'aktif' => $aktif,
                         'modul_erisim' => $modulStr, 'yetkiler' => $yetkiJson], $currentUid);
                    flash('success', 'Yeni kullanıcı oluşturuldu.');
                    redirect('kullanicilar.php');
                }
            }
            if (!$error) { redirect('kullanicilar.php'); }
        } catch (PDOException $e) {
            $error = ($e->getCode() == 23000) ? 'Bu kullanıcı adı zaten kullanımda.' : 'Veritabanı hatası: ' . h($e->getMessage());
        }
    }
}

// ── Düzenlenecek kullanıcıyı çek ────────────────────────────────────────────
$editUser = null;
if (isset($_GET['edit'])) {
    $editUser = $pdo->prepare("SELECT id,username,full_name,unvan,role,aktif,modul_erisim,yetkiler FROM users WHERE id=?");
    $editUser->execute([(int)$_GET['edit']]);
    $editUser = $editUser->fetch() ?: null;
}
// Hata sonrası form değerleri kaybolmasın
if ($error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $editUser = ['id' => $editId, 'username' => $username, 'full_name' => $fullName, 'unvan' => $unvan, 'role' => $role,
                 'aktif' => $aktif, 'modul_erisim' => $modulStr, 'yetkiler' => $yetkiJson];
}

// ── Kullanıcı listesi (durum süzgeci + arama) ───────────────────────────────
// Süzgeç yalnız GÖRÜNÜMÜ daraltır; sayaçlar her zaman TÜM kullanıcılar üzerinden verilir.
$fDurum = $_GET['durum'] ?? '';
if (!in_array($fDurum, ['aktif', 'pasif'], true)) $fDurum = '';
$fQ = trim((string)($_GET['q'] ?? ''));

$tumUsers = $pdo->query("SELECT id,username,full_name,unvan,role,aktif,modul_erisim,yetkiler,created_at FROM users ORDER BY id")->fetchAll();
$nAktif = 0; $nPasif = 0;
foreach ($tumUsers as $u) { if ($u['aktif']) $nAktif++; else $nPasif++; }

$users = array_values(array_filter($tumUsers, function ($u) use ($fDurum, $fQ) {
    if ($fDurum === 'aktif' && !$u['aktif']) return false;
    if ($fDurum === 'pasif' && $u['aktif'])  return false;
    if ($fQ !== '') {
        $hedef = mb_strtolower($u['username'] . ' ' . ($u['full_name'] ?? '') . ' ' . ($u['unvan'] ?? '') . ' ' . role_label($u['role']));
        if (!str_contains($hedef, mb_strtolower($fQ))) return false;
    }
    return true;
}));
$eskiDuzen = 0;
foreach ($tumUsers as $u) if ($u['role'] !== 'admin' && !yetki_normalize($u['yetkiler'] ?? null)) $eskiDuzen++;

$seciliMatris = $matrisHazirla($editUser);
$sablonJson   = [];
foreach (array_keys(ROLLER) as $r) $sablonJson[$r] = yetki_sablon($r);
$kisaHarf = ['oku' => 'O', 'giris' => 'G', 'duzenle' => 'D', 'onay' => 'N', 'rapor' => 'R'];

require_once __DIR__ . '/includes/header.php';
?>
<style>
.ymatris td, .ymatris th { vertical-align: middle; text-align: center; padding: .35rem .4rem; }
.ymatris td:first-child, .ymatris th:first-child { text-align: left; white-space: nowrap; }
.ymatris .form-check-input { margin: 0; cursor: pointer; }
.ymatris tr.pasif td:not(:first-child) { opacity: .45; }
.yozet .badge { font-weight: 500; }
/* Pasif kullanıcı satırı: silinmedi, yalnız erişimi kapalı — listede soluk görünür */
tr.urow-pasif td { opacity: .58; }
tr.urow-pasif td:last-child, tr.urow-pasif td:nth-last-child(3) { opacity: 1; }
.yharf { display:inline-block; min-width:1.15em; padding:0 .2em; border-radius:.3em; font-size:.66rem; font-weight:700; line-height:1.5;
         background: rgba(var(--ern-rgb), .12); color: var(--ern-dark); margin-left:1px; }
.yharf.yok { background: transparent; color: var(--bt-text-muted); opacity:.35; text-decoration: line-through; }
/* Modal: gövde kendi içinde kayar, başlık ve Kaydet düğmesi her zaman görünür kalır
   (matris 8 satır + form alanları kısa ekranlarda footer'ı ekran dışına itiyordu) */
/* ⚠ Başlık/gövde/alt bilgi <form> içinde olduğundan Bootstrap'in modal-dialog-scrollable'ı işlemez
   (flex zinciri form'da kopar) — bu yüzden flex sütunu FORM'a uygulanır. */
#modalKullanici .modal-content { max-height: calc(100vh - 3.5rem); }
#modalKullanici .modal-content > form { display: flex; flex-direction: column; min-height: 0; max-height: calc(100vh - 3.5rem); }
#modalKullanici .modal-body { overflow-y: auto; flex: 1 1 auto; min-height: 0; }
#modalKullanici .modal-header, #modalKullanici .modal-footer { flex: 0 0 auto; }
@media (max-width: 767.98px) { #modalKullanici .modal-content, #modalKullanici .modal-content > form { max-height: 100vh; } }
.ymatris thead th { position: sticky; top: 0; z-index: 1; background: var(--bt-surface, #f8f9fa); }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-people text-primary me-2"></i>Kullanıcı Yönetimi</h4>
    <div class="d-flex gap-2">
        <a href="moduller.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-grid-3x3-gap me-1"></i>Modüller</a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalKullanici">
            <i class="bi bi-person-plus me-1"></i> Yeni Kullanıcı
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="alert alert-light border small mb-3">
    <div class="d-flex flex-wrap gap-3 align-items-center">
        <strong><i class="bi bi-shield-lock me-1"></i>Yetki düzeni:</strong>
        <?php foreach ($islemler as $k => [$ad, $ikon, $acik]): ?>
            <span title="<?= h($acik) ?>"><span class="yharf"><?= $kisaHarf[$k] ?></span> <i class="bi <?= h($ikon) ?> me-1"></i><?= h($ad) ?></span>
        <?php endforeach; ?>
        <span class="text-muted ms-auto">Rol = etiket + başlangıç şablonu; gerçek yetki her kullanıcının <strong>modül × işlem</strong> matrisidir. Yönetici her şeye yetkilidir.</span>
    </div>
    <?php if ($eskiDuzen): ?>
        <div class="mt-2 text-warning-emphasis"><i class="bi bi-info-circle me-1"></i><?= $eskiDuzen ?> kullanıcı henüz <strong>eski rol düzeninde</strong> (matris tanımlı değil) — eski davranışla çalışır; düzenleyip kaydettiğinizde matris devreye girer.</div>
    <?php endif; ?>
</div>

<form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-2 small">
    <div class="btn-group btn-group-sm" role="group">
        <a href="kullanicilar.php<?= $fQ !== '' ? '?q=' . urlencode($fQ) : '' ?>" class="btn btn-outline-secondary <?= $fDurum === '' ? 'active' : '' ?>">Hepsi <span class="badge bg-secondary ms-1"><?= count($tumUsers) ?></span></a>
        <a href="kullanicilar.php?<?= h(http_build_query(array_filter(['durum' => 'aktif', 'q' => $fQ]))) ?>" class="btn btn-outline-success <?= $fDurum === 'aktif' ? 'active' : '' ?>">Aktif <span class="badge bg-success ms-1"><?= $nAktif ?></span></a>
        <a href="kullanicilar.php?<?= h(http_build_query(array_filter(['durum' => 'pasif', 'q' => $fQ]))) ?>" class="btn btn-outline-secondary <?= $fDurum === 'pasif' ? 'active' : '' ?>">Pasif <span class="badge bg-secondary ms-1"><?= $nPasif ?></span></a>
    </div>
    <?php if ($fDurum !== ''): ?><input type="hidden" name="durum" value="<?= h($fDurum) ?>"><?php endif; ?>
    <div class="input-group input-group-sm" style="width:280px">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Kullanıcı adı, ad soyad, görev…" value="<?= h($fQ) ?>">
        <button class="btn btn-outline-primary">Ara</button>
    </div>
    <?php if ($fDurum !== '' || $fQ !== ''): ?>
        <a href="kullanicilar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg me-1"></i>Süzgeci temizle</a>
        <span class="text-muted"><?= count($users) ?> / <?= count($tumUsers) ?> kullanıcı gösteriliyor</span>
    <?php endif; ?>
    <span class="text-muted ms-auto"><i class="bi bi-info-circle me-1"></i>Pasif kullanıcı sisteme <strong>giriş yapamaz</strong>; kayıtları ve geçmişi korunur.</span>
</div></form>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Kullanıcı</th>
                        <th>Rol / Görev</th>
                        <th>Yetkiler (modül × işlem)</th>
                        <th>Durum</th>
                        <th>Kayıt</th>
                        <th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Süzgece uyan kullanıcı yok.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u):
                    $rc  = ROLLER[$u['role']][1] ?? 'secondary';
                    $uM  = yetki_normalize($u['yetkiler'] ?? null);
                    $uL  = array_values(array_filter(array_map('trim', explode(',', (string)($u['modul_erisim'] ?? '')))));
                ?>
                    <tr class="<?= $u['aktif'] ? '' : 'urow-pasif' ?>">
                        <td class="text-muted"><?= (int)$u['id'] ?></td>
                        <td>
                            <div class="fw-semibold"><i class="bi bi-person me-1 text-muted"></i><?= h($u['username']) ?>
                                <?php if ((int)$u['id'] === $currentUid): ?><span class="badge bg-light text-dark border ms-1" style="font-size:.62rem">siz</span><?php endif; ?></div>
                            <div class="small text-muted"><?= h($u['full_name'] ?: '-') ?></div>
                        </td>
                        <td>
                            <span class="badge bg-<?= $rc ?>"><?= h(role_label($u['role'])) ?></span>
                            <?php if (!empty($u['unvan'])): ?><div class="small text-muted mt-1"><i class="bi bi-briefcase me-1"></i><?= h($u['unvan']) ?></div><?php endif; ?>
                        </td>
                        <td class="yozet small">
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="text-danger"><i class="bi bi-shield-fill-check me-1"></i>Tüm modüller, tüm işlemler</span>
                            <?php elseif (!$uM): ?>
                                <span class="text-warning-emphasis"><i class="bi bi-hourglass-split me-1"></i>Eski rol düzeni</span>
                                <span class="text-muted">— <?= $uL ? 'modüller: ' . h(implode(', ', array_map('modul_ad', $uL))) : 'tüm modüller' ?></span>
                            <?php else: foreach ($uM as $mk => $set): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1<?= modul_gizli($mk) ? ' opacity-50' : '' ?>"
                                      title="<?= h(implode(', ', array_map(fn($i) => $islemler[$i][0], $set))) ?>">
                                    <i class="bi <?= h(MODULLER[$mk][1]) ?> me-1"></i><?= h(modul_ad($mk)) ?><?= modul_gizli($mk) ? ' (gizli)' : '' ?>
                                    <?php foreach ($islemler as $ik => $iv): ?><span class="yharf<?= in_array($ik, $set, true) ? '' : ' yok' ?>"><?= $kisaHarf[$ik] ?></span><?php endforeach; ?>
                                </span>
                            <?php endforeach; endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <?php if ($u['aktif']): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Aktif</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Pasif</span>
                            <?php endif; ?>
                            <?php if ((int)$u['id'] !== $currentUid): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('<?= h($u['username']) ?> <?= $u['aktif'] ? 'PASİFE alınacak — artık sisteme giriş yapamaz' : 'AKTİF edilecek — sisteme giriş yapabilir' ?>. Devam?')">
                                <input type="hidden" name="action" value="durum">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="durum" value="<?= h($fDurum) ?>">
                                <input type="hidden" name="q" value="<?= h($fQ) ?>">
                                <button class="btn btn-sm btn-link p-0 ms-1 text-decoration-none" title="<?= $u['aktif'] ? 'Pasife al' : 'Aktif et' ?>">
                                    <i class="bi <?= $u['aktif'] ? 'bi-toggle-on text-success' : 'bi-toggle-off text-muted' ?>" style="font-size:1.15rem"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= format_date($u['created_at']) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="kullanicilar.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary me-1" title="Düzenle / yetkileri ayarla">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if ((int)$u['id'] !== $currentUid): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Bu kullanıcıyı silmek istediğinize emin misiniz?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id"     value="<?= $u['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Yeni / Düzenle -->
<div class="modal fade" id="modalKullanici" tabindex="-1" aria-labelledby="modalKullaniciLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-fullscreen-md-down">
        <div class="modal-content">
            <form method="post" id="frmKullanici">
                <input type="hidden" name="id" value="<?= $editUser ? (int)$editUser['id'] : 0 ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalKullaniciLabel">
                        <i class="bi bi-person-gear me-1"></i><?= $editUser && !empty($editUser['id']) ? 'Kullanıcıyı Düzenle' : 'Yeni Kullanıcı' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kullanıcı Adı <span class="text-danger">*</span></label>
                            <input name="username" class="form-control" value="<?= h($editUser['username'] ?? '') ?>" required autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ad Soyad</label>
                            <input name="full_name" class="form-control" value="<?= h($editUser['full_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Rol (şablon) <span class="text-danger">*</span></label>
                            <select name="role" id="selRol" class="form-select" required>
                                <?php foreach (ROLLER as $r => [$rAd, $rRenk, $rAcik]):
                                    $sel = ($editUser && ($editUser['role'] ?? '') === $r) ? 'selected' : ''; ?>
                                <option value="<?= $r ?>" <?= $sel ?> data-acik="<?= h($rAcik) ?>"><?= h($rAd) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="rolAcik"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Görev / Unvan <span class="text-muted small">(serbest)</span></label>
                            <input name="unvan" class="form-control" value="<?= h($editUser['unvan'] ?? '') ?>"
                                   placeholder="ör. Satış Sonrası Direktörü, Kalite Mühendisi" maxlength="80">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                Şifre <?= ($editUser && !empty($editUser['id'])) ? '<span class="text-muted small">(boş = değişmez)</span>' : '<span class="text-danger">*</span>' ?>
                            </label>
                            <input name="password" type="password" class="form-control" autocomplete="new-password"
                                   <?= ($editUser && !empty($editUser['id'])) ? '' : 'required minlength="6"' ?>
                                   placeholder="<?= ($editUser && !empty($editUser['id'])) ? '(değiştirmek için girin)' : 'En az 6 karakter' ?>">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="aktif" id="chkAktif" value="1"
                                       <?= (!$editUser || !empty($editUser['aktif'])) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="chkAktif">Hesap <strong>aktif</strong>
                                    <span class="text-muted d-block small" style="font-size:.75rem">Kapatılırsa kullanıcı sisteme giriş yapamaz; kaydı ve geçmişi silinmez.</span></label>
                            </div>
                        </div>
                    </div>

                    <!-- Yetki matrisi -->
                    <div class="border rounded p-2 mt-3" id="yetkiKutu">
                        <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                            <strong class="small"><i class="bi bi-shield-lock me-1"></i>Yetki Matrisi — hangi modülde ne yapabilir?</strong>
                            <div class="ms-auto d-flex gap-1">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="btnSablon" title="Seçili rolün varsayılan yetkilerini matrise yaz">
                                    <i class="bi bi-magic me-1"></i>Rol şablonunu uygula</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnHepsi"><i class="bi bi-check-all me-1"></i>Hepsi</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnTemizle"><i class="bi bi-x-lg me-1"></i>Temizle</button>
                            </div>
                        </div>
                        <div class="alert alert-danger py-1 px-2 small mb-2 d-none" id="adminNot">
                            <i class="bi bi-shield-fill-check me-1"></i>Yönetici rolü her modülde her işleme yetkilidir; matris bu rol için dikkate alınmaz.
                        </div>
                        <?php if ($editUser && !empty($editUser['id']) && ($editUser['role'] ?? '') !== 'admin' && !yetki_normalize($editUser['yetkiler'] ?? null)): ?>
                        <div class="alert alert-warning py-1 px-2 small mb-2">
                            <i class="bi bi-hourglass-split me-1"></i>Bu kullanıcı henüz <strong>eski rol düzeninde</strong>. Aşağıdaki matris rol şablonundan
                            (ve varsa eski modül listesinden) önerildi; <strong>kaydettiğinizde</strong> matris devreye girer.
                        </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered ymatris mb-1" id="tblMatris">
                                <thead class="table-light">
                                    <tr>
                                        <th>Modül</th>
                                        <th title="Satırın tümü"><span class="small">Tümü</span></th>
                                        <?php foreach ($islemler as $ik => [$iAd, $iIkon, $iAcik]): ?>
                                        <th title="<?= h($iAcik) ?>">
                                            <div class="small"><i class="bi <?= h($iIkon) ?>"></i> <?= h($iAd) ?></div>
                                            <input type="checkbox" class="form-check-input sutun-chk" data-islem="<?= $ik ?>" title="Sütunun tümü">
                                        </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($modulTum as $mk => $m): $set = $seciliMatris[$mk] ?? []; ?>
                                    <tr data-mod="<?= h($mk) ?>" class="<?= $set ? '' : 'pasif' ?>">
                                        <td>
                                            <i class="bi <?= h($m['ikon']) ?> me-1 text-muted"></i><?= h($m['ad']) ?>
                                            <?php if ($m['gizli']): ?><span class="badge bg-secondary ms-1" style="font-size:.62rem" title="Modül şu an yönetici tarafından gizli; izin verilebilir, açıldığında geçerli olur">gizli</span><?php endif; ?>
                                        </td>
                                        <td><input type="checkbox" class="form-check-input satir-chk" <?= count($set) === count($islemler) ? 'checked' : '' ?>></td>
                                        <?php foreach ($islemler as $ik => $iv): ?>
                                        <td><input type="checkbox" class="form-check-input y-chk" name="y[<?= h($mk) ?>][]" value="<?= $ik ?>"
                                                   data-islem="<?= $ik ?>" <?= in_array($ik, $set, true) ? 'checked' : '' ?>></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="form-text">
                            <strong>Okuma</strong> modülü açar; diğer işlemler okumayı gerektirir (işaretlenince okuma otomatik gelir).
                            Hiç işaretlenmeyen modül kullanıcıya <strong>hiç görünmez</strong> (üst şeritte çıkmaz, adres yazılsa da 403).
                            Yetki değişikliği <strong>anında</strong> geçerlidir; kullanıcının yeniden giriş yapması gerekmez.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i><?= ($editUser && !empty($editUser['id'])) ? 'Güncelle' : 'Oluştur' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var SABLON = <?= json_encode($sablonJson, JSON_UNESCAPED_UNICODE) ?>;
    var ISLEM  = <?= json_encode(array_keys($islemler)) ?>;
    var tbl = document.getElementById('tblMatris'), sel = document.getElementById('selRol');
    var adminNot = document.getElementById('adminNot'), rolAcik = document.getElementById('rolAcik');

    function satirlar() { return Array.prototype.slice.call(tbl.querySelectorAll('tbody tr')); }
    function satirChk(tr, islem) { return tr.querySelector('.y-chk[data-islem="' + islem + '"]'); }

    // Satır tutarlılığı: oku dışı işlem → oku da açılır; oku kapanırsa satır boşalır; "tümü" ve pasif görünüm güncellenir
    function satirDuzelt(tr, kaynak) {
        var oku = satirChk(tr, 'oku');
        if (kaynak && kaynak.dataset.islem === 'oku' && !oku.checked) {
            tr.querySelectorAll('.y-chk').forEach(function (c) { c.checked = false; });
        } else if (kaynak && kaynak.dataset.islem !== 'oku' && kaynak.checked) {
            oku.checked = true;
        }
        var hepsi = ISLEM.every(function (i) { return satirChk(tr, i).checked; });
        var hic   = ISLEM.every(function (i) { return !satirChk(tr, i).checked; });
        tr.querySelector('.satir-chk').checked = hepsi;
        tr.classList.toggle('pasif', hic);
    }
    function sutunlariGuncelle() {
        ISLEM.forEach(function (i) {
            var hepsi = satirlar().every(function (tr) { return satirChk(tr, i).checked; });
            tbl.querySelector('.sutun-chk[data-islem="' + i + '"]').checked = hepsi;
        });
    }
    function matrisYaz(m) {
        satirlar().forEach(function (tr) {
            var set = (m && m[tr.dataset.mod]) || [];
            ISLEM.forEach(function (i) { satirChk(tr, i).checked = set.indexOf(i) >= 0; });
            satirDuzelt(tr, null);
        });
        sutunlariGuncelle();
    }
    function rolUygula() {
        var admin = sel.value === 'admin';
        adminNot.classList.toggle('d-none', !admin);
        tbl.querySelectorAll('input').forEach(function (c) { c.disabled = admin; });
        var o = sel.options[sel.selectedIndex];
        rolAcik.textContent = o ? (o.dataset.acik || '') : '';
    }

    tbl.addEventListener('change', function (e) {
        var t = e.target;
        if (t.classList.contains('y-chk')) { satirDuzelt(t.closest('tr'), t); sutunlariGuncelle(); }
        else if (t.classList.contains('satir-chk')) {
            var tr = t.closest('tr');
            ISLEM.forEach(function (i) { satirChk(tr, i).checked = t.checked; });
            satirDuzelt(tr, null); sutunlariGuncelle();
        } else if (t.classList.contains('sutun-chk')) {
            satirlar().forEach(function (tr) { satirChk(tr, t.dataset.islem).checked = t.checked; satirDuzelt(tr, satirChk(tr, t.dataset.islem)); });
            sutunlariGuncelle();
        }
    });
    document.getElementById('btnSablon').addEventListener('click', function () { matrisYaz(SABLON[sel.value] || {}); });
    document.getElementById('btnHepsi').addEventListener('click', function () {
        var m = {}; satirlar().forEach(function (tr) { m[tr.dataset.mod] = ISLEM.slice(); }); matrisYaz(m);
    });
    document.getElementById('btnTemizle').addEventListener('click', function () { matrisYaz({}); });
    sel.addEventListener('change', function () {
        rolUygula();
        // Yeni kullanıcıda rol değişince şablon otomatik dolar; düzenlemede yönetici isterse düğmeyle uygular
        if (!<?= ($editUser && !empty($editUser['id'])) ? 'true' : 'false' ?>) matrisYaz(SABLON[sel.value] || {});
    });

    satirlar().forEach(function (tr) { satirDuzelt(tr, null); });
    sutunlariGuncelle();
    rolUygula();
    // Yeni kullanıcı: matris boş açılmasın — seçili rolün şablonu önerilir
    if (!<?= ($editUser) ? 'true' : 'false' ?>) matrisYaz(SABLON[sel.value] || {});
    // Admin rolünde disabled kutular POST'a gitmez; sunucu zaten admin için matrisi yok sayar
    document.getElementById('frmKullanici').addEventListener('submit', function () {
        tbl.querySelectorAll('input').forEach(function (c) { c.disabled = false; });
    });
<?php if ($editUser): ?>
    new bootstrap.Modal(document.getElementById('modalKullanici')).show();
<?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
