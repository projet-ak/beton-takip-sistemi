<?php
/**
 * kullanicilar.php — Kullanıcı yönetimi (sadece admin)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }

require_auth(['admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle  = 'Kullanıcı Yönetimi — Beton Takip Sistemi';
$currentUid = current_user_id();
$error      = '';

// ── Kaydet / güncelle ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']    ?? '';
    $editId   = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $role     = $_POST['role']      ?? '';
    $password = $_POST['password']  ?? '';
    $aktif    = isset($_POST['aktif']) ? 1 : 0;

    $allowedRoles = ['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo'];

    if ($action === 'delete' && $editId) {
        if ($editId === $currentUid) {
            flash('error', 'Kendi hesabınızı silemezsiniz.');
        } else {
            // Soft delete (aktif=0) yerine gerçek silme
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$editId]);
            flash('success', 'Kullanıcı silindi.');
        }
        redirect('kullanicilar.php');
    }

    if (!$username || !$role || !in_array($role, $allowedRoles, true)) {
        $error = 'Kullanıcı adı ve rol zorunludur.';
    } else {
        try {
            if ($editId) {
                // Güncelleme
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET username=?,full_name=?,role=?,aktif=?,password_hash=? WHERE id=?");
                    $stmt->execute([$username, $fullName, $role, $aktif, $hash, $editId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username=?,full_name=?,role=?,aktif=? WHERE id=?");
                    $stmt->execute([$username, $fullName, $role, $aktif, $editId]);
                }
                flash('success', 'Kullanıcı güncellendi.');
            } else {
                // Yeni kullanıcı
                if (strlen($password) < 6) {
                    $error = 'Şifre en az 6 karakter olmalıdır.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,aktif) VALUES (?,?,?,?,?)");
                    $stmt->execute([$username, $hash, $fullName, $role, $aktif]);
                    flash('success', 'Yeni kullanıcı oluşturuldu.');
                    redirect('kullanicilar.php');
                }
            }
            if (!$error) { redirect('kullanicilar.php'); }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = 'Bu kullanıcı adı zaten kullanımda.';
            } else {
                $error = 'Veritabanı hatası: ' . h($e->getMessage());
            }
        }
    }
}

// ── Düzenlenecek kullanıcıyı çek ────────────────────────────────────────────
$editUser = null;
if (isset($_GET['edit'])) {
    $editUser = $pdo->prepare("SELECT id,username,full_name,role,aktif FROM users WHERE id=?");
    $editUser->execute([(int)$_GET['edit']]);
    $editUser = $editUser->fetch() ?: null;
}

// ── Kullanıcı listesi ────────────────────────────────────────────────────────
$users = $pdo->query("SELECT id,username,full_name,role,aktif,created_at FROM users ORDER BY id")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-people text-primary me-2"></i>Kullanıcı Yönetimi</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalKullanici">
        <i class="bi bi-person-plus me-1"></i> Yeni Kullanıcı
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Kullanıcı Adı</th>
                        <th>Ad Soyad</th>
                        <th>Rol</th>
                        <th>Durum</th>
                        <th>Kayıt Tarihi</th>
                        <th class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="text-muted"><?= (int)$u['id'] ?></td>
                        <td class="fw-semibold"><i class="bi bi-person me-1 text-muted"></i><?= h($u['username']) ?></td>
                        <td><?= h($u['full_name'] ?: '-') ?></td>
                        <td>
                            <?php
                            $roleColors = [
                                'admin'             => 'danger',
                                'teknik_ofis_admin' => 'warning text-dark',
                                'teknik_ofis'       => 'info text-dark',
                                'saha_sefi'         => 'primary',
                                'depo'              => 'secondary',
                            ];
                            $rc = $roleColors[$u['role']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $rc ?>"><?= role_label($u['role']) ?></span>
                        </td>
                        <td>
                            <?php if ($u['aktif']): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Aktif</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Pasif</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= format_date($u['created_at']) ?></td>
                        <td class="text-end">
                            <a href="kullanicilar.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
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
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="id" value="<?= $editUser ? (int)$editUser['id'] : 0 ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalKullaniciLabel">
                        <?= $editUser ? 'Kullanıcıyı Düzenle' : 'Yeni Kullanıcı' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kullanıcı Adı <span class="text-danger">*</span></label>
                        <input name="username" class="form-control" value="<?= h($editUser['username'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ad Soyad</label>
                        <input name="full_name" class="form-control" value="<?= h($editUser['full_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rol <span class="text-danger">*</span></label>
                        <select name="role" class="form-select" required>
                            <?php
                            $roles = ['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo'];
                            foreach ($roles as $r):
                                $sel = ($editUser && $editUser['role'] === $r) ? 'selected' : '';
                            ?>
                            <option value="<?= $r ?>" <?= $sel ?>><?= role_label($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            Şifre <?= $editUser ? '<span class="text-muted small">(boş bırakılırsa değişmez)</span>' : '<span class="text-danger">*</span>' ?>
                        </label>
                        <input name="password" type="password" class="form-control" <?= $editUser ? '' : 'required minlength="6"' ?> placeholder="<?= $editUser ? '(değiştirmek için girin)' : 'En az 6 karakter' ?>">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="aktif" id="chkAktif" value="1"
                               <?= (!$editUser || $editUser['aktif']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="chkAktif">Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i><?= $editUser ? 'Güncelle' : 'Oluştur' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editUser): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = new bootstrap.Modal(document.getElementById('modalKullanici'));
    modal.show();
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
