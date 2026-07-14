<?php
// app/pages/settings/modals_users.php
// Modaalit käyttäjien hallintaan

// Hae roolikategoriat
$roleCategories = [];
$roleCategoriesRes = $mysqli->query("
    SELECT id, name, type, worksite 
    FROM role_categories 
    WHERE is_active = 1 
    ORDER BY type, name ASC
");
if ($roleCategoriesRes) {
    while ($rc = $roleCategoriesRes->fetch_assoc()) {
        $roleCategories[] = $rc;
    }
    $roleCategoriesRes->free();
}
?>

<!-- MODAALI – Lisää / Muokkaa käyttäjä -->
<div class="sf-modal hidden sf-user-modal" id="sfUserModal" role="dialog" aria-modal="true" aria-labelledby="sfUserModalTitle">
    <div class="sf-modal-content sf-user-manage-content">
        <div class="sf-modal-header">
            <h2 id="sfUserModalTitle">
                <?= htmlspecialchars(sf_term('users_modal_add_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <button type="button" class="sf-modal-close-btn sf-user-modal-close" data-modal-close aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">×</button>
        </div>
        
        <!-- Tabs -->
        <!-- Tabs -->
        <div class="sf-profile-tabs">
            <button type="button" class="sf-profile-tab active" data-tab="basics"><?= htmlspecialchars(sf_term('profile_tab_basics', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="sf-profile-tab" data-tab="settings"><?= htmlspecialchars(sf_term('profile_tab_settings', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="sf-profile-tab" data-tab="notifications"><?= htmlspecialchars(sf_term('profile_tab_notifications', $currentUiLang) ?? 'Ilmoitukset', ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="sf-profile-tab" data-tab="password"><?= htmlspecialchars(sf_term('profile_tab_password', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
        
        <!-- SINGLE FORM for all tabs -->
        <form id="sfUserForm">
            <?php
            // CSRF-token lomakkeeseen
            $csrfToken = $_SESSION['csrf_token'] ?? '';
            ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" id="sfUserId">
            
<!-- Tab 1: Basics -->
<div class="sf-profile-tab-content active" data-tab-content="basics">

    <div class="sf-profile-section">
        <h3><?= htmlspecialchars(sf_term('profile_personal_info', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>

        <div class="sf-field-row">
            <div class="sf-field">
                <label><?= htmlspecialchars(sf_term('users_label_first_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="first_name" id="sfUserFirst" required>
            </div>

            <div class="sf-field">
                <label><?= htmlspecialchars(sf_term('users_label_last_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" name="last_name" id="sfUserLast" required>
            </div>
        </div>

        <div class="sf-field">
            <label><?= htmlspecialchars(sf_term('users_label_email', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="email" name="email" id="sfUserEmail" required>
        </div>

        <div class="sf-field">
            <div class="sf-role-label-row">
                <label><?= htmlspecialchars(sf_term('users_label_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <button type="button" class="sf-role-help-btn" id="sfRoleHelpOpen">
                    <?= htmlspecialchars(sf_term('users_role_help_button', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>

            <select name="role_id" id="sfUserRole" required>
                <option value="" selected disabled>
                    <?= htmlspecialchars(sf_term('users_select_role_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= (int)$r['id'] ?>">
                        <?= htmlspecialchars(sf_role_name((int)$r['id'], $r['name'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sf-field">
            <label><?= htmlspecialchars(sf_term('users_label_language', $currentUiLang) ?? 'Kieli', ENT_QUOTES, 'UTF-8') ?></label>
            <select name="ui_lang" id="sfUserLanguage" required>
                <option value="fi">Suomi</option>
                <option value="sv">Svenska</option>
                <option value="en">English</option>
                <option value="it">Italiano</option>
                <option value="el">Ελληνικά</option>
            </select>
        </div>
    </div>

</div>

<!-- Tab 2: Settings -->
<div class="sf-profile-tab-content" data-tab-content="settings">
    <div class="sf-profile-section">
        <h3><?= htmlspecialchars(sf_term('profile_worksite_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>

        <div class="sf-field">
            <label><?= htmlspecialchars(sf_term('users_label_home_worksite', $currentUiLang) ?? 'Kotityömaa', ENT_QUOTES, 'UTF-8') ?></label>
            <select name="home_worksite_id" id="sfUserHomeWorksite">
                <option value="">
                    <?= htmlspecialchars(sf_term('users_home_worksite_none', $currentUiLang) ?? '–', ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php foreach ($worksites as $ws): ?>
                    <option value="<?= (int)$ws['id'] ?>">
                        <?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="sf-profile-section">
        <h3><?= htmlspecialchars(sf_term('users_role_categories_heading', $currentUiLang) ?? 'Työmaavastaavuudet', ENT_QUOTES, 'UTF-8') ?></h3>

        <div class="sf-field">
            <p class="sf-field-help"><?= htmlspecialchars(sf_term('users_role_categories_help', $currentUiLang) ?? 'Valitse mihin työmaihin henkilö on työmaavastaava, hyväksyjä tai tarkastaja SafetyFlasheille. Tämä on erillinen pääroolin valinnasta.', ENT_QUOTES, 'UTF-8') ?></p>

            <div class="sf-selection-panel">
                <div class="sf-selection-panel-head">
                    <span class="sf-selection-title"><?= htmlspecialchars(sf_term('selected_assignments_label', $currentUiLang) ?: 'Valitut', ENT_QUOTES, 'UTF-8') ?></span>
                    <button type="button" class="sf-add-selection-btn" id="sfCategoryAddBtn">
                        + <?= htmlspecialchars(sf_term('add_assignment_button', $currentUiLang) ?: 'Lisää työmaavastaavuus', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>

                <div class="sf-selected-categories" id="sfSelectedCategories"></div>

                <div class="sf-search-select sf-search-select-collapsed" id="sfCategorySearchWrap">
                    <input type="text"
                           id="sfCategorySearch"
                           class="sf-search-input"
                           placeholder="<?= htmlspecialchars(sf_term('search_worksite_placeholder', $currentUiLang) ?? '🔍 Hae työmaata tai roolia...', ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off"
                           value="">
                    <div class="sf-search-dropdown hidden" id="sfCategoryDropdown"></div>
                </div>
            </div>

            <div id="sfRoleCategories" style="display: none;">
                <?php foreach ($roleCategories as $cat): ?>
                    <input type="checkbox"
                           name="role_category_ids[]"
                           value="<?= (int)$cat['id'] ?>"
                           class="sf-role-category-checkbox"
                           data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>"
                           data-type="<?= htmlspecialchars($cat['type'], ENT_QUOTES, 'UTF-8') ?>"
                           data-worksite="<?= htmlspecialchars($cat['worksite'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="sf-profile-section" id="sfAdditionalRolesSection" style="display:none;">
        <h3><?= htmlspecialchars(sf_term('additional_roles', $currentUiLang) ?: 'Lisäroolit', ENT_QUOTES, 'UTF-8') ?></h3>

        <div class="sf-field">
            <p class="sf-field-help"><?= htmlspecialchars(sf_term('additional_roles_help', $currentUiLang) ?: 'Valitse roolit, joiden sähköpostit käyttäjä saa pääroolinsa lisäksi.', ENT_QUOTES, 'UTF-8') ?></p>

            <div class="sf-selection-panel">
                <div class="sf-selection-panel-head">
                    <span class="sf-selection-title"><?= htmlspecialchars(sf_term('selected_roles_label', $currentUiLang) ?: 'Valitut lisäroolit', ENT_QUOTES, 'UTF-8') ?></span>
                    <button type="button" class="sf-add-selection-btn" id="sfRoleAddBtn">
                        + <?= htmlspecialchars(sf_term('add_role_button', $currentUiLang) ?: 'Lisää rooli', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>

                <div class="sf-selected-roles" id="sfSelectedRoles"></div>

                <div class="sf-search-select sf-search-select-collapsed" id="sfRoleSearchWrap">
                    <input type="text"
                           id="sfRoleSearch"
                           class="sf-search-input"
                           placeholder="<?= htmlspecialchars(sf_term('search_role_placeholder', $currentUiLang) ?? '🔍 Hae roolia...', ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off"
                           value="">
                    <div class="sf-search-dropdown hidden" id="sfRoleDropdown"></div>
                </div>
            </div>

            <div id="sfAdditionalRoles" style="display: none;">
                <?php foreach ($roles as $role): ?>
                    <input type="checkbox"
                           name="additional_roles[]"
                           value="<?= (int)$role['id'] ?>"
                           class="sf-additional-role-checkbox"
                           data-name="<?= htmlspecialchars(sf_role_name((int)$role['id'], $role['name'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tab 3: Notifications -->
<div class="sf-profile-tab-content" data-tab-content="notifications">
    <div class="sf-profile-section sf-user-empty-tab-section" id="sfNotificationNewUserInfo">
        <div class="sf-user-modal-info-card">
            <img src="assets/img/icons/notification.svg" alt="" class="sf-user-modal-info-icon">
            <div>
                <h3><?= htmlspecialchars(sf_term('users_notifications_new_user_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                <p><?= htmlspecialchars(sf_term('users_notifications_new_user_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>

    <div class="sf-profile-section" id="sfEmailNotificationSection">
        <h3><?= htmlspecialchars(sf_term('profile_notifications_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>

        <div id="sfEmailNotificationField" style="display:none;">
            <div class="sf-info-box sf-notif-mandatory-info">
                <span>🔒</span> <?= htmlspecialchars(sf_term('notif_mandatory_info', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </div>

            <?php
$notificationGroups = [
    'notif_section_safetyflash' => [
        'sf_published_distribution',
        'sf_published_creator',
        'sf_published_participant',
        'sf_published_general',
        'sf_request_info',
                    'sf_supervisor_approval',
                    'sf_to_comms',
                    'sf_worksite_notification',
                ],
                'notif_section_comments' => [
                    'comment_on_own_flash',
                    'comment_reply',
                    'comment_mention',
                    'comment_subscribed',
                    'comment_comms_to_safety',
                ],
                'notif_section_product' => [
                    'product_updates',
                    'service_announcements',
                ],
                'notif_section_feedback' => [
                    'feedback_status_change',
                    'feedback_comment',
                ],
            ];

            foreach ($notificationGroups as $groupTitleKey => $categories):
            ?>
                <div class="sf-notif-group">
                    <h4 class="sf-notif-group-title"><?= htmlspecialchars(sf_term($groupTitleKey, $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h4>

                    <?php foreach ($categories as $cat): ?>
                        <div class="sf-email-notification-field">
                            <div class="sf-notif-label-wrap">
                                <label class="sf-email-notification-label" for="adminNotifPref_<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(sf_term('notif_' . $cat . '_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                </label>
                                <span class="sf-help-text"><?= htmlspecialchars(sf_term('notif_' . $cat . '_help', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>

                            <label class="sf-toggle">
                                <input type="hidden" name="notif_pref[<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>]" value="0">
<input type="checkbox"
       id="adminNotifPref_<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
       name="notif_pref[<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>]"
       value="1"
       data-notif-channel="email"
       data-notif-category="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
       checked>
                                <span class="sf-toggle-slider"></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Tab 4: Password -->
<div class="sf-profile-tab-content" data-tab-content="password">
    <div class="sf-profile-section">
        <h3><?= htmlspecialchars(sf_term('profile_change_password', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>

        <div id="sfAutoPasswordInfo" class="sf-info-box sf-user-modal-info-inline" style="display:none;">
            <img src="assets/img/icons/locked_icon.svg" alt="" class="sf-user-modal-info-small-icon">
            <p><?= htmlspecialchars(sf_term('users_auto_password_info', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div id="sfPasswordField">
            <div class="sf-field">
                <label><?= htmlspecialchars(sf_term('users_label_password_new', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="password" name="password" id="sfUserPassword">
            </div>
        </div>
    </div>
</div>
            
            <!-- Actions - shown on every tab, inside form -->
            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                    <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                    <?= htmlspecialchars(sf_term('btn_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAALI – Roolien kuvaukset -->
<div class="sf-modal hidden" id="sfRoleHelpModal" role="dialog" aria-modal="true" aria-labelledby="sfRoleHelpTitle">
    <div class="sf-modal-content sf-role-help-modal-content">
        <div class="sf-role-help-header">
            <div>
                <h2 id="sfRoleHelpTitle"><?= htmlspecialchars(sf_term('users_role_help_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars(sf_term('users_role_help_intro', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <button type="button" class="sf-modal-close-btn" id="sfRoleHelpClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">×</button>
        </div>

        <?php
        $primaryRoleHelpItems = [
            [
                'title' => 'users_role_help_admin_title',
                'text' => 'users_role_help_admin_text',
            ],
            [
                'title' => 'users_role_help_safety_title',
                'text' => 'users_role_help_safety_text',
            ],
            [
                'title' => 'users_role_help_comms_title',
                'text' => 'users_role_help_comms_text',
            ],
            [
                'title' => 'users_role_help_user_title',
                'text' => 'users_role_help_user_text',
            ],
            [
                'title' => 'users_role_help_distribution_title',
                'text' => 'users_role_help_distribution_text',
            ],
        ];
        ?>

        <section class="sf-role-help-section">
            <h3 class="sf-role-help-section-title">
                <?= htmlspecialchars(sf_term('users_role_help_primary_roles_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h3>

            <div class="sf-role-help-grid">
                <?php foreach ($primaryRoleHelpItems as $item): ?>
                    <article class="sf-role-help-card">
                        <h3><?= htmlspecialchars(sf_term($item['title'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                        <p><?= htmlspecialchars(sf_term($item['text'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="sf-role-help-section sf-role-help-section-muted">
            <h3 class="sf-role-help-section-title">
                <?= htmlspecialchars(sf_term('users_role_help_worksite_roles_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <p class="sf-role-help-section-intro">
                <?= htmlspecialchars(sf_term('users_role_help_worksite_roles_intro', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>

            <article class="sf-role-help-card sf-role-help-card-wide sf-role-help-card-accent">
                <div class="sf-role-help-card-head">
                    <h3><?= htmlspecialchars(sf_term('users_role_help_supervisor_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                    <span><?= htmlspecialchars(sf_term('users_role_help_defined_worksites_tab', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <p><?= htmlspecialchars(sf_term('users_role_help_supervisor_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
        </section>

        <section class="sf-role-help-section sf-role-help-section-muted">
            <h3 class="sf-role-help-section-title">
                <?= htmlspecialchars(sf_term('users_role_help_case_roles_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <p class="sf-role-help-section-intro">
                <?= htmlspecialchars(sf_term('users_role_help_case_roles_intro', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>

            <article class="sf-role-help-card sf-role-help-card-wide sf-role-help-card-muted">
                <div class="sf-role-help-card-head">
                    <h3><?= htmlspecialchars(sf_term('users_role_help_proofreader_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                    <span><?= htmlspecialchars(sf_term('users_role_help_defined_case_by_case', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <p><?= htmlspecialchars(sf_term('users_role_help_proofreader_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
        </section>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-primary" id="sfRoleHelpOk">
                <?= htmlspecialchars(sf_term('btn_close', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- MODAALI – Poisto -->
<div class="sf-modal hidden" id="sfDeleteModal">
    <div class="sf-modal-content">
        <h2><?= htmlspecialchars(sf_term('users_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
        <p>
            <?= htmlspecialchars(sf_term('users_delete_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <strong id="sfDeleteUserName"></strong>?
        </p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" id="sfDeleteCancel">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-danger" id="sfDeleteConfirm">
                <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- MODAALI – Salasanan resetointi -->
<div class="sf-modal hidden" id="sfResetModal">
    <div class="sf-modal-content">
        <h2><?= htmlspecialchars(sf_term('users_reset_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
        <p>
            <?= htmlspecialchars(sf_term('users_reset_text_prefix', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <strong id="sfResetUserName"></strong>
            <?= htmlspecialchars(sf_term('users_reset_text_suffix', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" id="sfResetCancel">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="sfResetConfirm">
                <?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- MODAALI – Secure Password Display -->
<div class="sf-modal hidden" id="sfPasswordModal">
    <div class="sf-modal-content sf-password-modal">
        <h2><?= htmlspecialchars(sf_term('password_modal_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="sf-password-warning">
            <?= htmlspecialchars(sf_term('password_modal_warning', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-password-display-group">
            <div class="sf-password-field-wrapper">
                <input type="password" id="sfPasswordDisplay" class="sf-password-display" readonly>
                <button type="button" class="sf-btn sf-btn-secondary sf-btn-toggle-password" id="sfTogglePassword">
                    <span class="sf-toggle-show"><?= htmlspecialchars(sf_term('password_show', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sf-toggle-hide hidden"><?= htmlspecialchars(sf_term('password_hide', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            </div>
            <button type="button" class="sf-btn sf-btn-primary" id="sfCopyPassword">
                <?= htmlspecialchars(sf_term('password_copy_button', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
        <div class="sf-password-countdown">
            <?= htmlspecialchars(sf_term('password_closes_in', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <span id="sfPasswordCountdown">60</span>s
        </div>
    </div>
</div>

<!-- MODAALI – Bulk Action Vahvistus -->
<div class="sf-modal hidden" id="sfBulkConfirmModal">
    <div class="sf-modal-content">
        <h2 id="sfBulkConfirmTitle"><?= htmlspecialchars(sf_term('bulk_confirm_title_users', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
        <p id="sfBulkConfirmText"></p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" id="sfBulkConfirmCancel">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="sfBulkConfirmOk">
                <?= htmlspecialchars(sf_term('btn_confirm', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    // Hae CSRF-token
    function getCsrfToken() {
        var tokenInput = document.querySelector('input[name="csrf_token"]');
        if (tokenInput && tokenInput.value) {
            return tokenInput.value;
        }
        var metaToken = document.querySelector('meta[name="csrf-token"]');
        if (metaToken) {
            return metaToken.getAttribute('content');
        }
        return '';
    }
	    function openUserModal(modal) {
        if (!modal) {
            return;
        }

        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }

        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
    }

    function closeUserModal(modal) {
        if (!modal) {
            return;
        }

        modal.classList.add('hidden');

        if (!document.querySelector('.sf-modal:not(.hidden), .sf-library-modal:not(.hidden)')) {
            document.body.classList.remove('sf-modal-open');
        }
    }
    
    // Note: Tab switching is handled globally by profile-modal.js
    
    // Delegoidut event listenerit - toimivat AJAX-latauksen jälkeenkin
    document.addEventListener('click', function (e) {

        // LISÄÄ KÄYTTÄJÄ -nappi
        if (e.target.closest('#sfUserAddBtn')) {
            var modal = document.getElementById('sfUserModal');

            if (modal) {
                // Reset form
                var form = document.getElementById('sfUserForm');
                if (form) form.reset();
                
                // Clear user ID
                document.getElementById('sfUserId').value = '';
                
                // Clear role category selections
                document.querySelectorAll('.sf-role-category-checkbox').forEach(function(cb) {
                    cb.checked = false;
                });
                // Clear category search input
                var categorySearchInput = document.getElementById('sfCategorySearch');
                if (categorySearchInput) categorySearchInput.value = '';
                if (window.sfUpdateSelectedChips) {
                    window.sfUpdateSelectedChips();
                }
                
                // Reset additional roles chips
                var roleCheckboxes = document.querySelectorAll('.sf-additional-role-checkbox');
                roleCheckboxes.forEach(function(cb) {
                    cb.checked = false;
                });
                // Clear the visual container
                var selectedRolesContainer = document.getElementById('sfSelectedRoles');
                if (selectedRolesContainer) {
                    selectedRolesContainer.innerHTML = '<p class="sf-no-selection"><?= htmlspecialchars(sf_term('no_additional_roles_selected', $currentUiLang) ?? 'Ei valittuja lisärooleja', ENT_QUOTES, 'UTF-8') ?></p>';
                }
                // Clear role search input
                var roleSearchInput = document.getElementById('sfRoleSearch');
                if (roleSearchInput) roleSearchInput.value = '';
                if (window.sfUpdateSelectedRoleChips) {
                    window.sfUpdateSelectedRoleChips();
                }
                
                document.getElementById('sfUserModalTitle').textContent =
                    '<?= htmlspecialchars(sf_term('users_modal_add_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
                
                // Hide password field and show auto-password info for new users
                var passwordField = document.getElementById('sfPasswordField');
                var autoPasswordInfo = document.getElementById('sfAutoPasswordInfo');
                var emailNotificationField = document.getElementById('sfEmailNotificationField');
                if (passwordField) passwordField.style.display = 'none';
                if (autoPasswordInfo) autoPasswordInfo.style.display = 'block';
                if (emailNotificationField) emailNotificationField.style.display = 'none';
                
                // Reset to first tab
                modal.querySelectorAll('.sf-profile-tab').forEach(function(t) {
                    t.classList.remove('active');
                });
                modal.querySelectorAll('.sf-profile-tab-content').forEach(function(c) {
                    c.classList.remove('active');
                });
                var firstTab = modal.querySelector('[data-tab="basics"]');
                var firstContent = modal.querySelector('[data-tab-content="basics"]');
                if (firstTab) firstTab.classList.add('active');
                if (firstContent) firstContent.classList.add('active');
                
                openUserModal(modal);
            }
            return;
        }

        // MUOKKAA KÄYTTÄJÄ -nappi
        var editBtn = e.target.closest('.sf-edit-user');
        if (editBtn) {
            var modal = document.getElementById('sfUserModal');
            if (modal) {
                var userId = editBtn.dataset.id || '';
                
                // Set user ID in form
                document.getElementById('sfUserId').value = userId;
                
                // Set basic info
                document.getElementById('sfUserFirst').value = editBtn.dataset.first || '';
                document.getElementById('sfUserLast').value = editBtn.dataset.last || '';
                document.getElementById('sfUserEmail').value = editBtn.dataset.email || '';
                document.getElementById('sfUserRole').value = editBtn.dataset.role || '';
                
                // Set settings info
                document.getElementById('sfUserHomeWorksite').value = editBtn.dataset.homeWorksite || '';
                
                // Set language
                document.getElementById('sfUserLanguage').value = editBtn.dataset.uiLang || 'fi';
                
                // Load and set role categories
                fetch('<?= $baseUrl ?>/app/api/get_user_role_categories.php?user_id=' + userId)
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok && data.category_ids) {
                            document.querySelectorAll('.sf-role-category-checkbox').forEach(checkbox => {
                                checkbox.checked = data.category_ids.includes(parseInt(checkbox.value));
                            });
                            // Clear category search input
                            var categorySearchInput = document.getElementById('sfCategorySearch');
                            if (categorySearchInput) categorySearchInput.value = '';
                            // Update the visual chips after loading categories
                            if (window.sfUpdateSelectedChips) {
                                window.sfUpdateSelectedChips();
                            }
                        }
                    })
                    .catch(err => {
                        console.error('Error loading role categories:', err);
                        // Continue without role categories - non-critical error
                    });
                
                // Set password field
                document.getElementById('sfUserPassword').value = '';
                
                document.getElementById('sfUserModalTitle').textContent =
                    '<?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
                
                // Show password field and hide auto-password info for editing
                var passwordField = document.getElementById('sfPasswordField');
                var autoPasswordInfo = document.getElementById('sfAutoPasswordInfo');
                var emailNotificationField = document.getElementById('sfEmailNotificationField');
                if (passwordField) passwordField.style.display = 'block';
                if (autoPasswordInfo) autoPasswordInfo.style.display = 'none';
                
                // Show email notification field for editing and set its value
                if (emailNotificationField) {
                    emailNotificationField.style.display = 'block';
                    var checkbox = document.getElementById('sfUserEmailNotifications');
                    if (checkbox) {
                        checkbox.checked = editBtn.dataset.emailNotifications === '1';
                    }
                }
                
                // Load and set additional roles
                var additionalRolesSection = document.getElementById('sfAdditionalRolesSection');
                if (additionalRolesSection) {
                    additionalRolesSection.style.display = 'block';
                    
                    var additionalRoleIds = [];
                    if (editBtn.dataset.additionalRoles) {
                        additionalRoleIds = editBtn.dataset.additionalRoles.split(',').map(function(id) {
                            return id.trim();
                        }).filter(function(id) {
                            return id !== '';
                        });
                    }
                    
                    // Uncheck all first
                    document.querySelectorAll('.sf-additional-role-checkbox').forEach(function(cb) {
                        cb.checked = false;
                    });
                    
                    // Check selected ones (if any)
                    additionalRoleIds.forEach(function(roleId) {
                        var checkbox = document.querySelector('.sf-additional-role-checkbox[value="' + roleId + '"]');
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                    
                    // Clear role search input
                    var roleSearchInput = document.getElementById('sfRoleSearch');
                    if (roleSearchInput) roleSearchInput.value = '';
                    
                    // CRITICAL: Update the visual chips AFTER setting checkboxes
                    if (window.sfUpdateSelectedRoleChips) {
                        window.sfUpdateSelectedRoleChips();
                    }
                }
                
                // Reset to first tab
                modal.querySelectorAll('.sf-profile-tab').forEach(function(t) {
                    t.classList.remove('active');
                });
                modal.querySelectorAll('.sf-profile-tab-content').forEach(function(c) {
                    c.classList.remove('active');
                });
                var firstTab = modal.querySelector('[data-tab="basics"]');
                var firstContent = modal.querySelector('[data-tab-content="basics"]');
                if (firstTab) firstTab.classList.add('active');
                if (firstContent) firstContent.classList.add('active');
                
                openUserModal(modal);
            }
            return;
        }

        // POISTA KÄYTTÄJÄ -nappi
        var deleteBtn = e.target.closest('.sf-delete-user');
        if (deleteBtn) {
            var modal = document.getElementById('sfDeleteModal');
            if (modal) {
                var row = deleteBtn.closest('tr') || deleteBtn.closest('.sf-user-card');
                var name = '';

                if (row) {
                    var nameEl = row.querySelector('td') || row.querySelector('.sf-user-card-name');
                    name = nameEl ? nameEl.textContent.trim() : '';
                }

                document.getElementById('sfDeleteUserName').textContent = name;
                modal.dataset.userId = deleteBtn.dataset.id || '';
                openUserModal(modal);
            }
            return;
        }

        // NOLLAA SALASANA -nappi
        var resetBtn = e.target.closest('.sf-reset-pass');
        if (resetBtn) {
            var modal = document.getElementById('sfResetModal');
            if (modal) {
                var row = resetBtn.closest('tr') || resetBtn.closest('.sf-user-card');
                var email = '';

                if (row) {
                    var emailEl = row.querySelector('td:nth-child(2)') || row.querySelector('.sf-user-card-email');
                    email = emailEl ? emailEl.textContent.trim() : '';
                }

                document.getElementById('sfResetUserName').textContent = email;
                modal.dataset.userId = resetBtn.dataset.id || '';
                openUserModal(modal);
            }
            return;
        }

        // PERUUTA-napit (use data-modal-close attribute for consistency)
        if (e.target.closest('#sfUserModal [data-modal-close]')) {
            var m = document.getElementById('sfUserModal');
            if (m) closeUserModal(m);
            return;
        }
        if (e.target.closest('#sfDeleteCancel')) {
            var m = document.getElementById('sfDeleteModal');
            if (m) m.classList.add('hidden');
            return;
        }
        if (e.target.closest('#sfResetCancel')) {
            var m = document.getElementById('sfResetModal');
            if (m) m.classList.add('hidden');
            return;
        }

        // POISTA VAHVISTA
        if (e.target.closest('#sfDeleteConfirm')) {
            var modal = document.getElementById('sfDeleteModal');
            var userId = modal ? modal.dataset.userId : null;

            if (userId) {
                var csrfToken = getCsrfToken();
                var body = 'sf_action=delete&id=' + encodeURIComponent(userId);
                if (csrfToken) {
                    body += '&csrf_token=' + encodeURIComponent(csrfToken);
                }
                
                fetch('<?= $baseUrl ?>/app/actions/users_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                }).then(function () {
                    window.location.reload();
                });
            }
            return;
        }

        // NOLLAA VAHVISTA
        if (e.target.closest('#sfResetConfirm')) {
            var modal = document.getElementById('sfResetModal');
            var userId = modal ? modal.dataset.userId : null;

            if (userId) {
                var csrfToken = getCsrfToken();
                var body = 'sf_action=reset_password&id=' + encodeURIComponent(userId);
                if (csrfToken) {
                    body += '&csrf_token=' + encodeURIComponent(csrfToken);
                }
                
                fetch('<?= $baseUrl ?>/app/actions/users_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                }).then(function () {
                    window.location.reload();
                });
            }
            return;
        }
    });

    // LOMAKKEEN SUBMIT
    // Ei käsitellä submitia täällä, koska assets/js/users.js hoitaa
    // sekä uuden käyttäjän luonnin että muokkauksen.
})();

// Tag-based multi-select for role categories
(function() {
    const addButton = document.getElementById('sfCategoryAddBtn');
    const searchWrap = document.getElementById('sfCategorySearchWrap');
    const searchInput = document.getElementById('sfCategorySearch');
    const dropdown = document.getElementById('sfCategoryDropdown');
    const selectedContainer = document.getElementById('sfSelectedCategories');
    const checkboxes = document.querySelectorAll('.sf-role-category-checkbox');

    if (!searchInput || !dropdown || !selectedContainer) return;

    const terms = {
        site_manager: '<?= htmlspecialchars(sf_term('role_type_site_manager', $currentUiLang) ?? 'Työmaavastaava', ENT_QUOTES, 'UTF-8') ?>',
        approver: '<?= htmlspecialchars(sf_term('role_type_approver', $currentUiLang) ?? 'Hyväksyjä', ENT_QUOTES, 'UTF-8') ?>',
        reviewer: '<?= htmlspecialchars(sf_term('role_type_reviewer', $currentUiLang) ?? 'Tarkastaja', ENT_QUOTES, 'UTF-8') ?>',
        no_selection: '<?= htmlspecialchars(sf_term('no_assignments_selected', $currentUiLang) ?? 'Ei valittuja työmaavastaavuuksia', ENT_QUOTES, 'UTF-8') ?>',
        no_results: '<?= htmlspecialchars(sf_term('no_search_results', $currentUiLang) ?? 'Ei tuloksia', ENT_QUOTES, 'UTF-8') ?>',
        global: '<?= htmlspecialchars(sf_term('worksite_global', $currentUiLang) ?? 'Globaali', ENT_QUOTES, 'UTF-8') ?>'
    };

    function getTypeLabel(type) {
        return terms[type] || type;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    const categories = Array.from(checkboxes).map(cb => {
        const localizedType = getTypeLabel(cb.dataset.type);
        return {
            id: cb.value,
            name: cb.dataset.name || '',
            type: cb.dataset.type || '',
            worksite: cb.dataset.worksite || '',
            checkbox: cb,
            searchText: `${cb.dataset.worksite || ''} ${cb.dataset.name || ''} ${localizedType} ${cb.dataset.type || ''}`.toLowerCase()
        };
    });

    function updateSelectedChips() {
        const selected = categories.filter(c => c.checkbox.checked);

        selectedContainer.innerHTML = selected.length === 0
            ? '<p class="sf-no-selection">' + escapeHtml(terms.no_selection) + '</p>'
            : selected.map(c => `
                <span class="sf-category-chip" data-id="${escapeHtml(c.id)}">
                    <span class="sf-chip-text">
                        ${escapeHtml(c.worksite ? c.worksite + ' - ' : '')}${escapeHtml(getTypeLabel(c.type))}
                    </span>
                    <button type="button" class="sf-chip-remove" data-id="${escapeHtml(c.id)}" aria-label="Poista">×</button>
                </span>
            `).join('');
    }

    function renderDropdown(query) {
        const normalizedQuery = String(query || '').toLowerCase().trim();

        const matches = categories.filter(c => {
            if (c.checkbox.checked) {
                return false;
            }

            if (normalizedQuery === '') {
                return true;
            }

            return c.searchText.includes(normalizedQuery);
        }).slice(0, 20);

        if (matches.length === 0) {
            dropdown.innerHTML = '<div class="sf-dropdown-empty">' + escapeHtml(terms.no_results) + '</div>';
        } else {
            dropdown.innerHTML = matches.map(c => `
                <div class="sf-dropdown-item" data-id="${escapeHtml(c.id)}">
                    <span class="sf-dropdown-worksite">${escapeHtml(c.worksite || terms.global)}</span>
                    <span class="sf-dropdown-type sf-type-${escapeHtml(c.type.replace(/_/g, '-'))}">${escapeHtml(getTypeLabel(c.type))}</span>
                </div>
            `).join('');
        }

        dropdown.classList.remove('hidden');
    }

function openSearch() {
    if (searchWrap) {
        searchWrap.classList.add('is-open');
    }

    searchInput.value = '';
    renderDropdown('');

    window.setTimeout(function() {
        searchInput.focus();

        const scrollTarget = searchWrap || searchInput;
        const scrollContainer = scrollTarget.closest('.sf-profile-tab-content');

        if (scrollContainer) {
            const targetRect = scrollTarget.getBoundingClientRect();
            const containerRect = scrollContainer.getBoundingClientRect();
            const extraSpace = 180;
            const overflowBottom = targetRect.bottom + extraSpace - containerRect.bottom;

            if (overflowBottom > 0) {
                scrollContainer.scrollTo({
                    top: scrollContainer.scrollTop + overflowBottom,
                    behavior: 'smooth'
                });
            }
        } else {
            scrollTarget.scrollIntoView({
                block: 'nearest',
                behavior: 'smooth'
            });
        }
    }, 80);
}

    function closeSearch() {
        dropdown.classList.add('hidden');

        if (searchWrap) {
            searchWrap.classList.remove('is-open');
        }

        searchInput.value = '';
    }

    if (addButton) {
        addButton.addEventListener('click', function(e) {
            e.preventDefault();
            openSearch();
        });
    }

    searchInput.addEventListener('focus', function() {
        renderDropdown(searchInput.value);
    });

    searchInput.addEventListener('input', function() {
        renderDropdown(this.value);
    });

    dropdown.addEventListener('click', function(e) {
        const item = e.target.closest('.sf-dropdown-item');
        if (!item) return;

        const id = item.dataset.id;
        const category = categories.find(c => c.id === id);

        if (category) {
            category.checkbox.checked = true;
            updateSelectedChips();
            closeSearch();
        }
    });

    selectedContainer.addEventListener('click', function(e) {
        const removeButton = e.target.closest('.sf-chip-remove');
        if (!removeButton) return;

        const id = removeButton.dataset.id;
        const category = categories.find(c => c.id === id);

        if (category) {
            category.checkbox.checked = false;
            updateSelectedChips();
        }
    });

    document.addEventListener('click', function(e) {
        const clickedInside = e.target.closest('#sfCategorySearchWrap') || e.target.closest('#sfCategoryAddBtn');

        if (!clickedInside) {
            closeSearch();
        }
    });

    updateSelectedChips();

    window.sfUpdateSelectedChips = updateSelectedChips;
})();

// Tag-based multi-select for additional roles
(function() {
    const addButton = document.getElementById('sfRoleAddBtn');
    const searchWrap = document.getElementById('sfRoleSearchWrap');
    const searchInput = document.getElementById('sfRoleSearch');
    const dropdown = document.getElementById('sfRoleDropdown');
    const selectedContainer = document.getElementById('sfSelectedRoles');
    const checkboxes = document.querySelectorAll('.sf-additional-role-checkbox');

    if (!searchInput || !dropdown || !selectedContainer) return;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    const roles = Array.from(checkboxes).map(cb => ({
        id: cb.value,
        name: cb.dataset.name || '',
        checkbox: cb,
        searchText: String(cb.dataset.name || '').toLowerCase()
    }));

    function updateSelectedRoleChips() {
        const selected = roles.filter(r => r.checkbox.checked);

        selectedContainer.innerHTML = selected.length === 0
            ? '<p class="sf-no-selection"><?= htmlspecialchars(sf_term('no_additional_roles_selected', $currentUiLang) ?? 'Ei valittuja lisärooleja', ENT_QUOTES, 'UTF-8') ?></p>'
            : selected.map(r => `
                <span class="sf-category-chip sf-role-chip" data-id="${escapeHtml(r.id)}">
                    <span class="sf-chip-text">${escapeHtml(r.name)}</span>
                    <button type="button" class="sf-chip-remove" data-id="${escapeHtml(r.id)}" aria-label="Poista">×</button>
                </span>
            `).join('');
    }

    function renderDropdown(query) {
        const normalizedQuery = String(query || '').toLowerCase().trim();

        const matches = roles.filter(r => {
            if (r.checkbox.checked) {
                return false;
            }

            if (normalizedQuery === '') {
                return true;
            }

            return r.searchText.includes(normalizedQuery);
        }).slice(0, 20);

        if (matches.length === 0) {
            dropdown.innerHTML = '<div class="sf-dropdown-empty"><?= htmlspecialchars(sf_term('no_search_results', $currentUiLang) ?? 'Ei tuloksia', ENT_QUOTES, 'UTF-8') ?></div>';
        } else {
            dropdown.innerHTML = matches.map(r => `
                <div class="sf-dropdown-item" data-id="${escapeHtml(r.id)}">
                    <span class="sf-dropdown-role-name">${escapeHtml(r.name)}</span>
                </div>
            `).join('');
        }

        dropdown.classList.remove('hidden');
    }

function openSearch() {
    if (searchWrap) {
        searchWrap.classList.add('is-open');
    }

    searchInput.value = '';
    renderDropdown('');

    window.setTimeout(function() {
        searchInput.focus();

        const scrollTarget = searchWrap || searchInput;
        const scrollContainer = scrollTarget.closest('.sf-profile-tab-content');

        if (scrollContainer) {
            const targetRect = scrollTarget.getBoundingClientRect();
            const containerRect = scrollContainer.getBoundingClientRect();
            const extraSpace = 180;
            const overflowBottom = targetRect.bottom + extraSpace - containerRect.bottom;

            if (overflowBottom > 0) {
                scrollContainer.scrollTo({
                    top: scrollContainer.scrollTop + overflowBottom,
                    behavior: 'smooth'
                });
            }
        } else {
            scrollTarget.scrollIntoView({
                block: 'nearest',
                behavior: 'smooth'
            });
        }
    }, 80);
}

    function closeSearch() {
        dropdown.classList.add('hidden');

        if (searchWrap) {
            searchWrap.classList.remove('is-open');
        }

        searchInput.value = '';
    }

    if (addButton) {
        addButton.addEventListener('click', function(e) {
            e.preventDefault();
            openSearch();
        });
    }

    searchInput.addEventListener('focus', function() {
        renderDropdown(searchInput.value);
    });

    searchInput.addEventListener('input', function() {
        renderDropdown(this.value);
    });

    dropdown.addEventListener('click', function(e) {
        const item = e.target.closest('.sf-dropdown-item');
        if (!item) return;

        const id = item.dataset.id;
        const role = roles.find(r => r.id === id);

        if (role) {
            role.checkbox.checked = true;
            updateSelectedRoleChips();
            closeSearch();
        }
    });

    selectedContainer.addEventListener('click', function(e) {
        const removeButton = e.target.closest('.sf-chip-remove');
        if (!removeButton) return;

        const id = removeButton.dataset.id;
        const role = roles.find(r => r.id === id);

        if (role) {
            role.checkbox.checked = false;
            updateSelectedRoleChips();
        }
    });

    document.addEventListener('click', function(e) {
        const clickedInside = e.target.closest('#sfRoleSearchWrap') || e.target.closest('#sfRoleAddBtn');

        if (!clickedInside) {
            closeSearch();
        }
    });

    updateSelectedRoleChips();

    window.sfUpdateSelectedRoleChips = updateSelectedRoleChips;
})();
</script></script>