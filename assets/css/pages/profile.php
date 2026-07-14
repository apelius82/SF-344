<?php
// assets/pages/profile.php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';

$currentUiLang = $uiLang ?? ($_SESSION['ui_lang'] ?? 'fi');
$base = rtrim($config['base_url'] ?? '', '/');

// Nykyinen käyttäjä
$user = sf_current_user();
if (!$user) {
    header('Location: ' . $base . '/index.php?page=list');
    exit;
}

$mysqli = sf_db();

// Hae työmaat
$worksitesRes = $mysqli->query("SELECT id, name FROM sf_worksites WHERE is_active = 1 AND show_in_worksite_lists = 1 ORDER BY name ASC");
$worksites = [];
while ($w = $worksitesRes->fetch_assoc()) {
    $worksites[] = $w;
}

// Hae roolin nimi
$roleStmt = $mysqli->prepare("SELECT name FROM sf_roles WHERE id = ?");
$roleStmt->bind_param('i', $user['role_id']);
$roleStmt->execute();
$roleResult = $roleStmt->get_result();
$roleName = $roleResult->fetch_assoc()['name'] ?? '–';
?>

<div class="sf-page-container">
    <div class="sf-page-header">
        <h1 class="sf-page-title"><?= htmlspecialchars(sf_term('profile_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>

<div class="sf-profile-page">

    <div class="sf-profile-card">
        <form id="sfProfileForm" class="sf-profile-form">
            <?= sf_csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">

            <div class="sf-profile-section">
                <h2><?= htmlspecialchars(sf_term('profile_personal_info', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>

                <div class="sf-field-row">
                    <div class="sf-field">
                        <label for="sfProfileFirst">
                            <?= htmlspecialchars(sf_term('users_label_first_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <input
                            type="text"
                            name="first_name"
                            id="sfProfileFirst"
                            class="sf-input"
                            value="<?= htmlspecialchars($user['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>
                    <div class="sf-field">
                        <label for="sfProfileLast">
                            <?= htmlspecialchars(sf_term('users_label_last_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <input
                            type="text"
                            name="last_name"
                            id="sfProfileLast"
                            class="sf-input"
                            value="<?= htmlspecialchars($user['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>
                </div>

                <div class="sf-field">
                    <label for="sfProfileEmail">
                        <?= htmlspecialchars(sf_term('users_label_email', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input
                        type="email"
                        name="email"
                        id="sfProfileEmail"
                        class="sf-input"
                        value="<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

                <div class="sf-field">
                    <label>
                        <?= htmlspecialchars(sf_term('users_label_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <div class="sf-profile-readonly"><?= htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?></div>
                    <p class="sf-help-text">
                        <?= htmlspecialchars(sf_term('profile_role_readonly', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
            </div>

            <div class="sf-profile-section">
                <h2><?= htmlspecialchars(sf_term('email_notifications_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>

                <div class="sf-email-notification-field">
                    <label class="sf-email-notification-label" for="sfEmailNotifications">
                        <?= htmlspecialchars(sf_term('email_notifications_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <label class="sf-toggle">
                        <input 
                            type="checkbox" 
                            id="sfEmailNotifications" 
                            <?= !empty($user['email_notifications_enabled']) ? 'checked' : '' ?>
                        >
                        <span class="sf-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <div class="sf-profile-section">
                <h2><?= htmlspecialchars(sf_term('profile_worksite_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>

                <div class="sf-field">
                    <label for="sfProfileWorksite">
                        <?= htmlspecialchars(sf_term('users_label_home_worksite', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <select name="home_worksite_id" id="sfProfileWorksite" class="sf-select">
                        <option value="">
                            <?= htmlspecialchars(sf_term('users_home_worksite_none', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php foreach ($worksites as $ws): ?>
                            <option
                                value="<?= (int)$ws['id'] ?>"
                                <?= (int)($user['home_worksite_id'] ?? 0) === (int)$ws['id'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="sf-help-text">
                        <?= htmlspecialchars(sf_term('profile_worksite_help', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
            </div>

            <div class="sf-profile-section">
                <h3><?= htmlspecialchars(sf_term('profile_language_heading', $currentUiLang) ?? 'Kieli', ENT_QUOTES, 'UTF-8') ?></h3>
                
                <div class="sf-field">
                    <label for="sfUserLanguage"><?= htmlspecialchars(sf_term('profile_language_label', $currentUiLang) ?? 'Sovelluksen ja sähköpostien kieli', ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="sfUserLanguage" name="ui_lang" class="sf-select">
                        <option value="fi" <?= ($user['ui_lang'] ?? 'fi') === 'fi' ? 'selected' : '' ?>>Suomi</option>
                        <option value="sv" <?= ($user['ui_lang'] ?? 'fi') === 'sv' ? 'selected' : '' ?>>Svenska</option>
                        <option value="en" <?= ($user['ui_lang'] ?? 'fi') === 'en' ? 'selected' : '' ?>>English</option>
                        <option value="it" <?= ($user['ui_lang'] ?? 'fi') === 'it' ? 'selected' : '' ?>>Italiano</option>
                        <option value="el" <?= ($user['ui_lang'] ?? 'fi') === 'el' ? 'selected' : '' ?>>Ελληνικά</option>
                    </select>
                    <p class="sf-field-help"><?= htmlspecialchars(sf_term('profile_language_help', $currentUiLang) ?? 'Valitse kieli jota käytät sovelluksessa ja sähköposteissa.', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>

            <div class="sf-profile-actions">
                <button type="submit" class="sf-btn sf-btn-primary">
                    <?= htmlspecialchars(sf_term('btn_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Salasanan vaihto -->
    <div class="sf-profile-card">
        <h2><?= htmlspecialchars(sf_term('profile_password_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>

        <form id="sfPasswordForm" class="sf-profile-form">
            <?= sf_csrf_field() ?>
            <div class="sf-field">
                <label for="sfCurrentPassword">
                    <?= htmlspecialchars(sf_term('profile_current_password', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input
                    type="password"
                    name="current_password"
                    id="sfCurrentPassword"
                    class="sf-input"
                    required
                >
            </div>

            <div class="sf-field-row">
                <div class="sf-field">
                    <label for="sfNewPassword">
                        <?= htmlspecialchars(sf_term('profile_new_password', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input
                        type="password"
                        name="new_password"
                        id="sfNewPassword"
                        class="sf-input"
                        required
                        minlength="8"
                    >
                </div>
                <div class="sf-field">
                    <label for="sfConfirmPassword">
                        <?= htmlspecialchars(sf_term('profile_confirm_password', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input
                        type="password"
                        name="confirm_password"
                        id="sfConfirmPassword"
                        class="sf-input"
                        required
                        minlength="8"
                    >
                </div>
            </div>

            <div class="sf-profile-actions">
                <button type="submit" class="sf-btn sf-btn-secondary">
                    <?= htmlspecialchars(sf_term('profile_change_password', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const base = '<?= $base ?>';
    const userId = <?= (int)$user['id'] ?>;

    // Email notification toggle
    const emailToggle = document.getElementById('sfEmailNotifications');
    if (emailToggle) {
        emailToggle.addEventListener('change', async function() {
            const enabled = this.checked ? 1 : 0;
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('email_notifications_enabled', enabled);

            try {
                const response = await fetch(base + '/app/api/update_user_notifications.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.ok) {
                    if (typeof window.sfToast === 'function') {
                        const message = enabled 
                            ? '<?= htmlspecialchars(sf_term('email_notifications_enabled', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>'
                            : '<?= htmlspecialchars(sf_term('email_notifications_disabled', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
                        window.sfToast('success', message);
                    }
                } else {
                    // Revert toggle on error
                    this.checked = !this.checked;
                    alert(result.error || '<?= htmlspecialchars(sf_term('error_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>');
                }
            } catch (err) {
                console.error('Email notification update error:', err);
                // Revert toggle on error
                this.checked = !this.checked;
                alert('<?= htmlspecialchars(sf_term('error_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>');
            }
        });
    }

    // Language change handler
    const languageSelect = document.getElementById('sfUserLanguage');
    if (languageSelect) {
        languageSelect.addEventListener('change', async function() {
            const newLang = this.value;
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('ui_lang', newLang);
            
            try {
                const response = await fetch(base + '/app/api/profile_update.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    // Reload page to apply new language
                    window.location.reload();
                } else {
                    alert('<?= htmlspecialchars(sf_term('error_prefix', $currentUiLang), ENT_QUOTES, 'UTF-8') ?> ' + (data.error || '<?= htmlspecialchars(sf_term('error_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('<?= htmlspecialchars(sf_term('error_network', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>');
            }
        });
    }

    // Profiilitietojen tallennus
    document.getElementById('sfProfileForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();

        const formData = new FormData(this);

        try {
            const response = await fetch(base + '/app/api/profile_update.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.ok) {
                // Show toast notification instead of reloading
                if (typeof window.sfToast === 'function') {
                    window.sfToast('success', '<?= htmlspecialchars(sf_term('notice_profile_updated', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>');
                }
            } else {
                alert(result.error || '<?= htmlspecialchars(sf_term('error_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>');
            }
        } catch (err) {
            console.error('Profile update error:', err);
            alert('<?= htmlspecialchars(sf_term('error_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>');
        }
    });

    // Salasanan vaihto
    document.getElementById('sfPasswordForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();

        const newPass = document.getElementById('sfNewPassword').value;
        const confirmPass = document.getElementById('sfConfirmPassword').value;

        if (newPass !== confirmPass) {
            alert("<?= htmlspecialchars(sf_term('profile_passwords_mismatch', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>");
            return;
        }

        const formData = new FormData(this);

        try {
            const response = await fetch(base + '/app/api/profile_password.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.ok) {
                // Show toast notification and clear password fields
                if (typeof window.sfToast === 'function') {
                    window.sfToast('success', '<?= htmlspecialchars(sf_term('notice_password_changed', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>');
                }
                // Clear password fields
                document.getElementById('sfCurrentPassword').value = '';
                document.getElementById('sfNewPassword').value = '';
                document.getElementById('sfConfirmPassword').value = '';
            } else {
                alert(result.error || '<?= htmlspecialchars(sf_term('error_password_change', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>');
            }
        } catch (err) {
            console.error('Password change error:', err);
            alert('<?= htmlspecialchars(sf_term('error_password_change', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>');
        }
    });
});
</script>