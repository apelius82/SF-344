<?php
// app/includes/footer.php
$base = rtrim($config['base_url'] ?? '/', '/');
$currentPage = $_GET['page'] ?? 'list';
$uiLang = $_SESSION['ui_lang'] ?? 'fi';

// Get user info for admin check (same as header.php)
$user = sf_current_user();
$isAdmin = $user && (int)$user['role_id'] === 1;
?>

<?php if ($currentPage !== 'form' && $currentPage !== 'form_language' && $currentPage !== 'view'): ?>

<!-- Bottom Navigation (Mobile) - 5 buttons: Dashboard, Lista, Uusi (center), Palaute, Profiili -->
<nav class="sf-bottom-nav" aria-label="<?= htmlspecialchars(sf_term('mobile_nav', $uiLang) ?? 'Mobiilinavigaatio') ?>">
    <!-- Button 1: Dashboard -->
    <a href="<?= $base ?>/index.php?page=dashboard" 
       class="sf-bottom-nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
        <img src="<?= $base ?>/assets/img/icons/dashboard.svg" alt="" class="sf-bottom-nav-icon">
        <span><?= htmlspecialchars(sf_term('nav_dashboard', $uiLang) ?? 'Dashboard') ?></span>
    </a>

    <!-- Button 2: Lista -->
    <a href="<?= $base ?>/index.php?page=list" 
       class="sf-bottom-nav-item <?= $currentPage === 'list' ? 'active' : '' ?>">
        <img src="<?= $base ?>/assets/img/icons/list.svg" alt="" class="sf-bottom-nav-icon">
        <span><?= htmlspecialchars(sf_term('nav_list', $uiLang) ?? 'Lista') ?></span>
    </a>
    
    <!-- Button 3 (CENTER): UUSI SAFETYFLASH - Visually prominent -->
    <a href="<?= $base ?>/index.php?page=form" 
       class="sf-bottom-nav-cta <?= $currentPage === 'form' ? 'active' : '' ?>"
       aria-label="<?= htmlspecialchars(sf_term('nav_new_safetyflash', $uiLang) ?? 'Uusi Safetyflash') ?>">
        <img src="<?= $base ?>/assets/img/icons/add_new_icon.svg" 
             alt="" 
             class="sf-bottom-nav-cta-icon">
    </a>
    
    <!-- Button 4: Palaute -->
    <a href="<?= $base ?>/index.php?page=feedback" 
       class="sf-bottom-nav-item <?= $currentPage === 'feedback' ? 'active' : '' ?>">
        <img src="<?= $base ?>/assets/img/icons/feedback.svg" alt="" class="sf-bottom-nav-icon">
        <span><?= htmlspecialchars(sf_term('nav_feedback', $uiLang) ?? 'Palaute') ?></span>
    </a>
    
    <!-- Button 5: Profiili -->
    <button type="button" 
       class="sf-bottom-nav-item <?= $currentPage === 'profile' ? 'active' : '' ?>"
       data-modal-open="modalProfile">
        <img src="<?= $base ?>/assets/img/icons/profile.svg" alt="" class="sf-bottom-nav-icon">
        <span><?= htmlspecialchars(sf_term('nav_profile', $uiLang) ?? 'Profiili') ?></span>
    </button>
</nav>
<?php endif; ?>



<!-- Profiili-modal -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalProfile" role="dialog" aria-modal="true" aria-labelledby="modalProfileTitle">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h2 id="modalProfileTitle"><?= htmlspecialchars(sf_term('profile_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>
        
        <!-- Välilehdet -->
        <div class="sf-profile-tabs-scroll">
            <div class="sf-profile-tabs" role="tablist">
                <button class="sf-profile-tab active" data-tab="basics" role="tab" aria-selected="true"><?= htmlspecialchars(sf_term('profile_tab_basics', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                <button class="sf-profile-tab" data-tab="settings" role="tab" aria-selected="false"><?= htmlspecialchars(sf_term('profile_tab_settings', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                <button class="sf-profile-tab" data-tab="notifications" role="tab" aria-selected="false"><?= htmlspecialchars(sf_term('profile_tab_notifications', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                <button class="sf-profile-tab" data-tab="password" role="tab" aria-selected="false"><?= htmlspecialchars(sf_term('profile_tab_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </div>
        
        <!-- Välilehti 1: Perustiedot -->
        <div class="sf-profile-tab-content active" data-tab-content="basics">
            <form id="sfProfileModalForm">
                <?= sf_csrf_field() ?>
                
                <div class="sf-profile-section">
                    <h3><?= htmlspecialchars(sf_term('profile_personal_info', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                    
                    <div class="sf-field-row">
                        <div class="sf-field">
                            <label for="modalProfileFirst"><?= htmlspecialchars(sf_term('users_label_first_name', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="text" name="first_name" id="modalProfileFirst" class="sf-input" required>
                        </div>
                        <div class="sf-field">
                            <label for="modalProfileLast"><?= htmlspecialchars(sf_term('users_label_last_name', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="text" name="last_name" id="modalProfileLast" class="sf-input" required>
                        </div>
                    </div>
                    
                    <div class="sf-field">
                        <label for="modalProfileEmail"><?= htmlspecialchars(sf_term('users_label_email', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="email" name="email" id="modalProfileEmail" class="sf-input" required readonly>
                    </div>
                    
                    <div class="sf-field">
                        <label><?= htmlspecialchars(sf_term('users_label_role', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <div class="sf-profile-readonly" id="modalProfileRole">-</div>
                    </div>
                </div>
                
                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
<button type="submit" class="sf-btn sf-btn-primary">
    <?= htmlspecialchars(sf_term('btn_save_close', $uiLang), ENT_QUOTES, 'UTF-8') ?>
</button>
                </div>
            </form>
        </div>
        
        <!-- Välilehti 2: Asetukset -->
        <div class="sf-profile-tab-content" data-tab-content="settings">
            <form id="sfProfileSettingsForm">
                <?= sf_csrf_field() ?>
                
                <div class="sf-profile-section">
                    <h3><?= htmlspecialchars(sf_term('profile_worksite_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                    
                    <div class="sf-field">
                        <label for="modalProfileWorksite"><?= htmlspecialchars(sf_term('users_label_home_worksite', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <select name="home_worksite_id" id="modalProfileWorksite" class="sf-select">
                            <option value=""><?= htmlspecialchars(sf_term('users_home_worksite_none', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                            <!-- Worksites loaded dynamically -->
                        </select>
                        <p class="sf-help-text"><?= htmlspecialchars(sf_term('profile_worksite_help', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                
                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
<button type="submit" class="sf-btn sf-btn-primary">
    <?= htmlspecialchars(sf_term('btn_save_close', $uiLang), ENT_QUOTES, 'UTF-8') ?>
</button>
                </div>
            </form>
        </div>

        <!-- Välilehti 3: Ilmoitukset -->
        <div class="sf-profile-tab-content" data-tab-content="notifications">
            <form id="sfProfileNotificationsForm">
                <?= sf_csrf_field() ?>

                <div class="sf-profile-section">
                    <h3><?= htmlspecialchars(sf_term('profile_notifications_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>

                    <div class="sf-info-box sf-notif-mandatory-info">
                        <span>🔒</span>
                        <?= htmlspecialchars(sf_term('notif_mandatory_info', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <div class="sf-notification-device-card">
                        <div>
                            <strong><?= htmlspecialchars(sf_term('push_notifications_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars(sf_term('push_notifications_enable_help', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
<span id="sfPushStatusText" class="sf-push-status-text">
    <?= htmlspecialchars(sf_term('push_notifications_status_checking', $uiLang), ENT_QUOTES, 'UTF-8') ?>
</span>

<div id="sfPushInstallPrompt" class="sf-push-install-prompt" hidden>
    <strong><?= htmlspecialchars(sf_term('push_install_prompt_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></strong>
    <span><?= htmlspecialchars(sf_term('push_install_prompt_text', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>

    <button type="button" id="sfInstallAppButton" class="sf-push-install-button">
        <?= htmlspecialchars(sf_term('push_install_button', $uiLang), ENT_QUOTES, 'UTF-8') ?>
    </button>

    <span id="sfInstallAppHint" class="sf-push-install-hint" hidden>
        <?= htmlspecialchars(sf_term('push_install_ios_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?>
    </span>
</div>
                        </div>

                        <label class="sf-toggle">
                            <input type="checkbox" id="sfPushNotificationsToggle">
                            <span class="sf-toggle-slider"></span>
                        </label>
                    </div>

                    <div id="sfPushUnsupportedNotice" class="sf-info-box sf-push-unsupported-notice" hidden>
                        <span>ℹ️</span>
                        <?= htmlspecialchars(sf_term('push_notifications_not_supported', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <div class="sf-notification-bulk-actions">
    <div class="sf-notification-bulk-group">
        <span><?= htmlspecialchars(sf_term('notification_matrix_email', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
        <button type="button" class="sf-notification-bulk-btn" data-notif-bulk-channel="email" data-notif-bulk-value="1">
            <?= htmlspecialchars(sf_term('notification_select_all', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button type="button" class="sf-notification-bulk-btn" data-notif-bulk-channel="email" data-notif-bulk-value="0">
            <?= htmlspecialchars(sf_term('notification_clear_all', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <div class="sf-notification-bulk-group">
        <span><?= htmlspecialchars(sf_term('notification_matrix_push', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
        <button type="button" class="sf-notification-bulk-btn" data-notif-bulk-channel="push" data-notif-bulk-value="1">
            <?= htmlspecialchars(sf_term('notification_select_all', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button type="button" class="sf-notification-bulk-btn" data-notif-bulk-channel="push" data-notif-bulk-value="0">
            <?= htmlspecialchars(sf_term('notification_clear_all', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</div>

<div class="sf-notification-matrix">
                        <div class="sf-notification-matrix-head">
                            <div><?= htmlspecialchars(sf_term('notification_matrix_event', $uiLang), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><?= htmlspecialchars(sf_term('notification_matrix_email', $uiLang), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><?= htmlspecialchars(sf_term('notification_matrix_push', $uiLang), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <?php
                        $notificationGroups = [
                            [
                                'title' => 'notif_section_safetyflash',
								'items' => [
								    'sf_published_distribution',
								    'sf_published_creator',
								    'sf_published_participant',
								    'sf_published_general',
								    'sf_request_info',
                                    'sf_supervisor_approval',
                                    'sf_to_comms',
                                    'sf_worksite_notification',
                                ],
                            ],
                            [
                                'title' => 'notif_section_comments',
                                'items' => [
                                    'comment_on_own_flash',
                                    'comment_reply',
                                    'comment_mention',
                                    'comment_subscribed',
                                    'comment_comms_to_safety',
                                ],
                            ],
                            [
                                'title' => 'notif_section_product',
                                'items' => [
                                    'product_updates',
                                    'service_announcements',
                                ],
                            ],
                            [
                                'title' => 'notif_section_feedback',
                                'items' => [
                                    'feedback_status_change',
                                    'feedback_comment',
                                ],
                            ],
                        ];

                        foreach ($notificationGroups as $group):
                        ?>
                            <div class="sf-notification-matrix-group">
                                <?= htmlspecialchars(sf_term($group['title'], $uiLang), ENT_QUOTES, 'UTF-8') ?>
                            </div>

                            <?php foreach ($group['items'] as $cat): ?>
                                <div class="sf-notification-matrix-row">
                                    <div class="sf-notification-matrix-label">
                                        <strong>
                                            <?= htmlspecialchars(sf_term('notif_' . $cat . '_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                                        </strong>
                                        <span>
                                            <?= htmlspecialchars(sf_term('notif_' . $cat . '_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>

                                    <div class="sf-notification-matrix-toggle">
                                        <label class="sf-toggle">
                                            <input type="hidden" name="notif_pref[<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>]" value="0">
                                            <input
                                                type="checkbox"
                                                id="notifPref_<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                                                name="notif_pref[<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>]"
                                                value="1"
                                                data-notif-channel="email"
                                                data-notif-category="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                                                checked
                                            >
                                            <span class="sf-toggle-slider"></span>
                                        </label>
                                    </div>

                                    <div class="sf-notification-matrix-toggle">
                                        <label class="sf-toggle">
                                            <input type="hidden" name="push_pref[<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>]" value="0">
                                            <input
                                                type="checkbox"
                                                id="pushPref_<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                                                name="push_pref[<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>]"
                                                value="1"
                                                data-push-category="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                                                checked
                                            >
                                            <span class="sf-toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button
                    type="submit"
                    id="sfNotificationsFloatingSave"
                    class="sf-notifications-floating-save"
                    hidden
                    aria-live="polite"
                >
                    <span class="sf-notifications-floating-save-icon" aria-hidden="true">✓</span>
                    <span class="sf-notifications-floating-save-text">
                        <?= htmlspecialchars(sf_term('btn_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </button>

                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="submit" class="sf-btn sf-btn-primary">
                        <?= htmlspecialchars(sf_term('btn_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Välilehti 4: Salasana -->
        <div class="sf-profile-tab-content" data-tab-content="password">
            <form id="sfPasswordModalForm">
                <?= sf_csrf_field() ?>
                
                <div class="sf-profile-section">
                    <h3><?= htmlspecialchars(sf_term('profile_change_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>

                    <div id="sfPasswordModalFeedback" class="sf-help-text" style="display:none; margin-bottom:12px; font-weight:600;"></div>
                    
                    <div class="sf-field">
                        <label for="modalCurrentPassword"><?= htmlspecialchars(sf_term('profile_current_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="password" name="current_password" id="modalCurrentPassword" class="sf-input" required>
                    </div>
                    
                    <div class="sf-field-row">
                        <div class="sf-field">
                            <label for="modalNewPassword"><?= htmlspecialchars(sf_term('profile_new_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="password" name="new_password" id="modalNewPassword" class="sf-input" required minlength="8">
                        </div>
                        <div class="sf-field">
                            <label for="modalConfirmPassword"><?= htmlspecialchars(sf_term('profile_confirm_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="password" name="confirm_password" id="modalConfirmPassword" class="sf-input" required minlength="8">
                        </div>
                    </div>
                    
                    <button type="submit" class="sf-btn sf-btn-secondary">
                        <?= htmlspecialchars(sf_term('profile_change_password', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </form>
        </div>

        <?php if ($isAdmin): ?>
        <!-- Admin section (only for admins) -->
        <div class="sf-profile-admin-section">
            <h3><?= htmlspecialchars(sf_term('nav_admin', $uiLang) ?? 'Ylläpito', ENT_QUOTES, 'UTF-8') ?></h3>
            <div class="sf-profile-admin-links">
                <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/index.php?page=playlist_manager" class="sf-profile-admin-link">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/display.svg" alt="" class="sf-profile-admin-icon" aria-hidden="true">
                    <span><?= htmlspecialchars(sf_term('nav_display_playlists', $uiLang) ?? 'Infonäytöt', ENT_QUOTES, 'UTF-8') ?></span>
                </a>
                <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/index.php?page=settings" class="sf-profile-admin-link">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/settings.svg" alt="" class="sf-profile-admin-icon" aria-hidden="true">
                    <span><?= htmlspecialchars(sf_term('settings_heading', $uiLang) ?? 'Asetukset', ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Logout button -->
        <div class="sf-profile-logout-section">
            <a href="#sfLogoutModal" class="sf-btn sf-btn-danger sf-profile-logout-btn" data-modal-open="#sfLogoutModal">
                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/log_out.svg"
                     alt=""
                     class="sf-profile-logout-icon"
                     aria-hidden="true">
                <?= htmlspecialchars(sf_term('nav_logout', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
</div>