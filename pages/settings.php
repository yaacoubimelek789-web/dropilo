<?php
$uid = (int) $_SESSION['user_id'];
$pageTitle = 'Settings';
$currentPage = 'settings';

$message = '';
$error = '';

// Helper for public prefix (fixes undefined variable error)
$appPublicPrefix = $appPublicPrefix ?? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

// Handle Profile Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Email is required.';
    } else {
        $st = $app->pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
        $st->execute([$name, $email, $uid]);
        $_SESSION['user_name'] = $name ?: $email;
        $message = 'Profile updated successfully.';
    }
}

// Handle Password Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    $st = $app->pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $st->execute([$uid]);
    $user = $st->fetch();
    
    if (!password_verify($current, $user['password_hash'])) {
        $error = 'Incorrect current password.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $app->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $uid]);
        $message = 'Password changed successfully.';
    }
}

// Handle Cropped Avatar Upload (Base64)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avatar_data'])) {
    $data = $_POST['avatar_data'];
    if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
        $data = substr($data, strpos($data, ',') + 1);
        $type = strtolower($type[1]); // jpg, png, gif

        if (!in_array($type, ['jpg', 'jpeg', 'png', 'gif'])) {
            $error = 'Invalid image type.';
        } else {
            $data = base64_decode($data);
            if ($data === false) {
                $error = 'Failed to decode image.';
            } else {
                $uploadDir = $base . '/public/uploads/avatars/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $filename = 'user_' . $uid . '_' . time() . '.' . $type;
                $targetPath = $uploadDir . $filename;
                $webPath = $appPublicPrefix . '/uploads/avatars/' . $filename;
                
                if (file_put_contents($targetPath, $data)) {
                    $app->pdo->prepare("UPDATE users SET avatar_url = ? WHERE id = ?")->execute([$webPath, $uid]);
                    $message = 'Avatar updated successfully.';
                } else {
                    $error = 'Failed to save avatar file.';
                }
            }
        }
    } else {
        $error = 'Invalid image data.';
    }
}

// Fetch current user details
$st = $app->pdo->prepare("SELECT name, email, avatar_url FROM users WHERE id = ?");
$st->execute([$uid]);
$user = $st->fetch();

// Inject Cropper.js
$content = '
<!-- Cropper.js -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

<div class="settings-container">
    <div class="settings-header">
        <div class="header-main">
            <h1>Settings</h1>
            <div class="header-line"></div>
        </div>
        <p class="header-subtitle">Manage your account, security preferences, and profile appearance.</p>
    </div>

    ' . ($message ? '<div class="alert alert-success">' . htmlspecialchars($message) . '</div>' : '') . '
    ' . ($error ? '<div class="alert alert-error">' . htmlspecialchars($error) . '</div>' : '') . '

    <div class="settings-grid">
        <!-- Profile Card -->
        <div class="card settings-card collapsed-mobile" id="profile-card">
            <div class="card-header-modern" onclick="toggleSettingsCard(\'profile-card\')">
                <div class="header-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <h3>Profile Information</h3>
                </div>
                <span class="mobile-chevron">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </span>
            </div>
            <div class="card-body">
                <div class="avatar-upload-container">
                    <div class="avatar-preview">
                        ' . (!empty($user['avatar_url']) ? '<img src="' . htmlspecialchars($user['avatar_url']) . '" id="avatar-display-img">' : '<div class="avatar-placeholder">' . strtoupper(substr($user['name'] ?: $user['email'], 0, 1)) . '</div>') . '
                    </div>
                    <div class="avatar-actions">
                        <label for="avatar-input" class="btn btn-secondary btn-sm">Change Photo</label>
                        <input type="file" id="avatar-input" accept="image/*" style="display:none;">
                        <p class="help-text">JPG, PNG or GIF. Max 2MB.</p>
                    </div>
                </div>

                <form method="post" class="settings-form">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="name" value="' . htmlspecialchars($user['name'] ?? '') . '" class="form-control" placeholder="Your name">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" value="' . htmlspecialchars($user['email'] ?? '') . '" class="form-control" placeholder="name@company.com" required>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Security Card -->
        <div class="card settings-card collapsed-mobile" id="security-card">
            <div class="card-header-modern" onclick="toggleSettingsCard(\'security-card\')">
                <div class="header-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <h3>Account Security</h3>
                </div>
                <span class="mobile-chevron">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </span>
            </div>
            <div class="card-body">
                <form method="post" class="settings-form">
                    <input type="hidden" name="update_password" value="1">
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" class="form-control" placeholder="••••••••" required>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="Minimum 6 characters" required minlength="6">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Repeat new password" required>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-warning">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Cropper Modal -->
<div id="cropper-modal" class="modal-overlay">
    <div class="modal-card cropper-modal-card">
        <div class="card-header">
            <h3>Adjust your photo</h3>
            <button type="button" class="close-btn" onclick="closeCropper()">&times;</button>
        </div>
        <div class="cropper-container">
            <img id="cropper-image" src="">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeCropper()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveCroppedImage()">Save Profile Picture</button>
        </div>
    </div>
</div>

<form id="avatar-data-form" method="post" style="display:none;">
    <input type="hidden" name="avatar_data" id="avatar-data-input">
</form>

<script>
function toggleSettingsCard(cardId) {
    if (window.innerWidth > 768) return; // Only toggle on mobile
    const card = document.getElementById(cardId);
    if (card) {
        card.classList.toggle("collapsed-mobile");
    }
}

let cropper = null;
const avatarInput = document.getElementById("avatar-input");
const cropperModal = document.getElementById("cropper-modal");
const cropperImage = document.getElementById("cropper-image");

avatarInput.addEventListener("change", function(e) {
    const files = e.target.files;
    if (files && files.length > 0) {
        const file = files[0];
        const reader = new FileReader();
        reader.onload = function(event) {
            cropperImage.src = event.target.result;
            cropperModal.classList.add("active");
            
            if (cropper) cropper.destroy();
            cropper = new Cropper(cropperImage, {
                aspectRatio: 1,
                viewMode: 1,
                dragMode: "move",
                autoCropArea: 1,
                restore: false,
                guides: false,
                center: false,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
            });
        };
        reader.readAsDataURL(file);
    }
});

function closeCropper() {
    cropperModal.classList.remove("active");
    avatarInput.value = "";
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }
}

function saveCroppedImage() {
    if (!cropper) return;
    
    // Get cropped canvas
    const canvas = cropper.getCroppedCanvas({
        width: 300,
        height: 300,
        imageSmoothingEnabled: true,
        imageSmoothingQuality: "high",
    });
    
    const base64 = canvas.toDataURL("image/jpeg", 0.9);
    document.getElementById("avatar-data-input").value = base64;
    document.getElementById("avatar-data-form").submit();
}
</script>

<style>
.settings-container { max-width: 1000px; margin: 0 auto; padding-bottom: 3rem; }

/* Settings Grid */
.settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 2rem; }
.settings-card { height: auto; display: flex; flex-direction: column; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
.card-header-modern { padding: 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
.header-title { display: flex; align-items: center; gap: 0.75rem; color: #0f172a; }
.header-title svg { color: var(--cart-green); opacity: 0.8; }
.card-header-modern h3 { margin: 0; font-size: 1.1rem; font-weight: 800; }
.mobile-chevron { display: none; color: #94a3b8; transition: transform 0.3s ease; }

.card-body { padding: 1.5rem; flex: 1; transition: all 0.3s ease; }

.avatar-upload-container { display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2rem; padding-bottom: 2rem; border-bottom: 1px solid #f1f5f9; }
.avatar-preview { width: 80px; height: 80px; border-radius: 50%; overflow: hidden; background: #f1f5f9; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
.avatar-preview img { width: 100%; height: 100%; object-fit: cover; }
.avatar-placeholder { font-size: 2rem; font-weight: 800; color: #94a3b8; }
.avatar-actions .help-text { font-size: 0.75rem; color: #64748b; margin-top: 0.5rem; }

.settings-form .form-group { margin-bottom: 1.25rem; }
.settings-form label { display: block; font-size: 0.85rem; font-weight: 700; color: #475569; margin-bottom: 0.5rem; }
.settings-form .form-control { width: 100%; padding: 0.75rem 1rem; border: 1px solid #e2e8f0; border-radius: 0.75rem; font-size: 0.95rem; transition: all 0.2s; }
.settings-form .form-control:focus { border-color: var(--cart-green); outline: none; box-shadow: 0 0 0 3px rgba(93, 214, 44, 0.1); }

.form-actions { margin-top: 2rem; display: flex; justify-content: flex-end; }

/* Cropper Modal */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 10000; backdrop-filter: blur(4px); }
.modal-overlay.active { display: flex; }
.cropper-modal-card { background: #fff; width: 90%; max-width: 600px; border-radius: 1.25rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden; }
.cropper-container { max-height: 400px; background: #000; display: flex; align-items: center; justify-content: center; }
.cropper-container img { max-width: 100%; max-height: 400px; }
.modal-footer { padding: 1.25rem; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 1rem; }
.card-header { padding: 1.25rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; }
.card-header h3 { margin: 0; font-size: 1.1rem; }
.close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #94a3b8; }

@media (max-width: 768px) {
    .settings-header h1 { font-size: 2rem; }
    .header-main { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
    .header-line { width: 100%; height: 3px; top: 0; }
    
    .settings-grid { grid-template-columns: 1fr; gap: 1rem; }
    
    .mobile-chevron { display: block; }
    
    /* Collapsible logic */
    .settings-card.collapsed-mobile .card-body { 
        display: none;
    }
    .settings-card.collapsed-mobile .card-header-modern {
        border-bottom: none;
    }
    .settings-card:not(.collapsed-mobile) .mobile-chevron {
        transform: rotate(180deg);
    }
}
</style>
';

require $base . '/layouts/layout.php';
