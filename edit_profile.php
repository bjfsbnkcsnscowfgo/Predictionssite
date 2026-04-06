<?php
/**
 * Edit Profile Page
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
startSecureSession();
requireLogin();

$db = getDB();
$currentUser = getCurrentUser();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'profile';

        if ($action === 'profile') {
            $bio = trim($_POST['bio'] ?? '');
            $email = trim($_POST['email'] ?? '');

            // Validate bio
            if (mb_strlen($bio) > 500) {
                $errors[] = 'Bio must be 500 characters or fewer.';
            }

            // Validate email
            if (empty($email)) {
                $errors[] = 'Email is required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } else {
                // Check email uniqueness (excluding self)
                $stmtE = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :uid LIMIT 1");
                $stmtE->execute([':email' => $email, ':uid' => $currentUser['id']]);
                if ($stmtE->fetch()) {
                    $errors[] = 'This email address is already in use by another account.';
                }
            }

            if (empty($errors)) {
                $stmt = $db->prepare("UPDATE users SET bio = :bio, email = :email, updated_at = NOW() WHERE id = :uid");
                $stmt->execute([
                    ':bio' => $bio,
                    ':email' => $email,
                    ':uid' => $currentUser['id'],
                ]);
                $success = 'Profile updated successfully!';
                // Refresh user data
                $currentUser['bio'] = $bio;
                $currentUser['email'] = $email;
            }
        } elseif ($action === 'password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmNewPassword = $_POST['confirm_new_password'] ?? '';

            if (empty($currentPassword)) {
                $errors[] = 'Current password is required.';
            }
            if (empty($newPassword)) {
                $errors[] = 'New password is required.';
            } elseif (strlen($newPassword) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            }
            if ($newPassword !== $confirmNewPassword) {
                $errors[] = 'New passwords do not match.';
            }

            if (empty($errors)) {
                // Verify current password
                $stmtPw = $db->prepare("SELECT password_hash FROM users WHERE id = :uid LIMIT 1");
                $stmtPw->execute([':uid' => $currentUser['id']]);
                $row = $stmtPw->fetch();

                if (!password_verify($currentPassword, $row['password_hash'])) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :uid");
                    $stmt->execute([':hash' => $newHash, ':uid' => $currentUser['id']]);
                    $success = 'Password changed successfully!';
                }
            }
        }
    }
}

$pageTitle = 'Edit Profile';
$currentPage = 'edit_profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="page-header">
            <h1><i class="fas fa-cog text-accent me-2"></i>Edit Profile</h1>
            <p>Update your profile information and account settings</p>
        </div>

        <div class="mb-3">
            <a href="<?= SITE_URL ?>/profile.php?id=<?= (int)$currentUser['id'] ?>" class="text-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Profile
            </a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?= sanitize($success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php if (count($errors) === 1): ?>
                    <?= sanitize($errors[0]) ?>
                <?php else: ?>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= sanitize($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Profile Info -->
        <div class="card mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-user me-2"></i>Profile Information</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="profile">

                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-user me-1"></i> Username</label>
                        <input type="text" class="form-control" value="<?= sanitize($currentUser['username']) ?>" disabled>
                        <div class="form-text">Username cannot be changed.</div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label"><i class="fas fa-envelope me-1"></i> Email</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= sanitize($currentUser['email']) ?>" required>
                    </div>

                    <div class="mb-4">
                        <label for="bio" class="form-label"><i class="fas fa-comment me-1"></i> Bio</label>
                        <textarea class="form-control" id="bio" name="bio" rows="4" maxlength="500"
                                  placeholder="Tell others about yourself..."><?= sanitize($currentUser['bio'] ?? '') ?></textarea>
                        <div class="char-counter"><span id="bioCount"><?= mb_strlen($currentUser['bio'] ?? '') ?></span>/500</div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="password">

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password"
                               required minlength="8" placeholder="Minimum 8 characters">
                    </div>

                    <div class="mb-4">
                        <label for="confirm_new_password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" required>
                    </div>

                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key me-1"></i>Change Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bio = document.getElementById('bio');
    const bioCount = document.getElementById('bioCount');
    bio.addEventListener('input', function() {
        bioCount.textContent = this.value.length;
        if (this.value.length > 500) bioCount.parentElement.classList.add('over-limit');
        else bioCount.parentElement.classList.remove('over-limit');
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
