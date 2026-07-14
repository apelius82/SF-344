<?php
// app/pages/settings/tab_users.php
declare(strict_types=1);

// Muuttujat tulevat settings.php:stä: $mysqli, $baseUrl, $currentUiLang

// Hae työmaat (vain aktiiviset, ei passivoituja kotityömaa-valikkoon)
$worksites = [];
$worksitesRes = $mysqli->query("SELECT id, name, is_active FROM sf_worksites WHERE is_active = 1 AND show_in_worksite_lists = 1 ORDER BY name ASC");
while ($w = $worksitesRes->fetch_assoc()) {
    $worksites[] = $w;
}

// Hae roolit
$roles = [];
$rolesRes = $mysqli->query('SELECT id, name FROM sf_roles ORDER BY id ASC');
while ($r = $rolesRes->fetch_assoc()) {
    $roles[] = $r;
}

// Pagination
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// Show deleted users filter
$showDeleted = isset($_GET['show_deleted']) && $_GET['show_deleted'] === '1';

// Filter parameters from URL
$filterRole = isset($_GET['filter_role']) ? (int)$_GET['filter_role'] : 0;
$filterWorksite = isset($_GET['filter_worksite']) ? (int)$_GET['filter_worksite'] : 0;
$filterSearch = isset($_GET['filter_search']) ? trim($_GET['filter_search']) : '';
$filterLogin = isset($_GET['filter_login']) ? $_GET['filter_login'] : '';

// Build WHERE clause dynamically
$conditions = [];
$params = [];
$types = '';

if (!$showDeleted) {
    $conditions[] = 'u.is_active = 1';
}

if ($filterRole > 0) {
    $conditions[] = 'u.role_id = ?';
    $params[] = $filterRole;
    $types .= 'i';
}

if ($filterWorksite > 0) {
    $conditions[] = 'u.home_worksite_id = ?';
    $params[] = $filterWorksite;
    $types .= 'i';
}

if ($filterSearch !== '') {
    $conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchPattern = '%' . $filterSearch . '%';
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $types .= 'sss';
}

if ($filterLogin === 'logged') {
    $conditions[] = 'u.last_login_at IS NOT NULL';
} elseif ($filterLogin === 'never') {
    $conditions[] = 'u.last_login_at IS NULL';
}

$whereClause = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Count total users with filters - always use prepared statement
$countSql = "SELECT COUNT(*) as total FROM sf_users u $whereClause";
$countStmt = $mysqli->prepare($countSql);
if (count($params) > 0) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countRes = $countStmt->get_result();
$totalUsers = (int)$countRes->fetch_assoc()['total'];
$countStmt->close();

$totalPages = ceil($totalUsers / $perPage);

// Fetch users - use prepared statement with parameters
$sqlUsers = "
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.role_id,
        u.home_worksite_id,
        u.created_at,
        u.last_login_at,
        u.is_active,
        u.email_notifications_enabled,
        r.name AS role_name,
        ws.name AS home_worksite_name
    FROM sf_users u
    JOIN sf_roles r ON r.id = u.role_id
    LEFT JOIN sf_worksites ws ON ws.id = u.home_worksite_id
    $whereClause
    ORDER BY u.created_at DESC
    LIMIT ? OFFSET ?
";

// Add LIMIT and OFFSET parameters
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $mysqli->prepare($sqlUsers);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$resUsers = $stmt->get_result();

$users = [];
while ($row = $resUsers->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

// Hae lisäroolit kaikille käyttäjille yhdellä kyselyllä (optimized to avoid N+1)
$additionalRolesMap = [];
if (!empty($users)) {
    $userIds = array_map(function($u) { return (int)$u['id']; }, $users);
    // Safe: placeholders are generated internally, not from user input
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    
    $stmt = $mysqli->prepare("SELECT user_id, role_id FROM user_additional_roles WHERE user_id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);
    $stmt->execute();
    $resRoles = $stmt->get_result();
    
    while ($roleRow = $resRoles->fetch_assoc()) {
        $uid = (int)$roleRow['user_id'];
        if (!isset($additionalRolesMap[$uid])) {
            $additionalRolesMap[$uid] = [];
        }
        $additionalRolesMap[$uid][] = (int)$roleRow['role_id'];
    }
    $stmt->close();
}

// Liitä lisäroolit käyttäjätietoihin
foreach ($users as &$user) {
    $user['additional_roles'] = $additionalRolesMap[(int)$user['id']] ?? [];
}
unset($user); // Break reference

// Create role ID to name lookup map for efficient lookups
$roleIdToNameMap = [];
foreach ($roles as $r) {
    $roleIdToNameMap[(int)$r['id']] = sf_role_name((int)$r['id'], $r['name'], $currentUiLang);
}
?>

<?php 
$activeFilterCount = 0;
if ($filterRole > 0) $activeFilterCount++;
if ($filterWorksite > 0) $activeFilterCount++;
if ($filterSearch !== '') $activeFilterCount++;
if ($filterLogin !== '') $activeFilterCount++;
?>

<div class="sf-users-compact-toolbar">
    <div class="sf-users-compact-header">
        <div class="sf-users-title-wrap">
            <img src="<?= $baseUrl ?>/assets/img/icons/users.svg" alt="" class="sf-heading-icon" aria-hidden="true">
            <h2><?= htmlspecialchars(sf_term('users_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>

        <button class="sf-btn sf-btn-primary sf-users-add-btn" id="sfUserAddBtn" type="button">
            <span aria-hidden="true">+</span>
            <?= htmlspecialchars(sf_term('users_add_button', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <div class="sf-users-control-card <?= $activeFilterCount > 0 ? 'has-active-filters' : '' ?>">
        <button type="button" class="sf-filters-toggle" id="sfUsersFiltersToggle">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="22,3 2,3 10,12.46 10,19 14,21 14,12.46"/>
            </svg>
            <?= htmlspecialchars(sf_term('users_filter_toggle', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php if ($activeFilterCount > 0): ?>
                <span class="sf-filter-badge"><?= $activeFilterCount ?></span>
            <?php endif; ?>
        </button>

        <div class="sf-users-filters-content sf-users-compact-filters" id="sfUsersFiltersContent">
            <div class="sf-filter-group sf-filter-group-search">
                <label for="sfFilterSearch"><?= htmlspecialchars(sf_term('users_filter_search', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" id="sfFilterSearch" class="sf-filter-input" placeholder="<?= htmlspecialchars(sf_term('users_filter_search', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="sf-filter-group">
                <label for="sfFilterRole"><?= htmlspecialchars(sf_term('users_filter_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="sfFilterRole" class="sf-filter-select">
                    <option value=""><?= htmlspecialchars(sf_term('users_filter_login_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= (int)$role['id'] ?>" <?= $filterRole === (int)$role['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars(sf_role_name((int)$role['id'], $role['name'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sf-filter-group">
                <label for="sfFilterWorksite"><?= htmlspecialchars(sf_term('users_filter_worksite', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="sfFilterWorksite" class="sf-filter-select">
                    <option value=""><?= htmlspecialchars(sf_term('users_filter_login_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($worksites as $ws): ?>
                        <option value="<?= (int)$ws['id'] ?>" <?= $filterWorksite === (int)$ws['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sf-filter-group">
                <label for="sfFilterLoginStatus"><?= htmlspecialchars(sf_term('users_filter_login_status', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="sfFilterLoginStatus" class="sf-filter-select">
                    <option value=""><?= htmlspecialchars(sf_term('users_filter_login_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="logged" <?= $filterLogin === 'logged' ? 'selected' : '' ?>><?= htmlspecialchars(sf_term('users_filter_login_active', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="never" <?= $filterLogin === 'never' ? 'selected' : '' ?>><?= htmlspecialchars(sf_term('users_filter_login_never', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
            </div>

            <div class="sf-filter-group sf-filter-checkbox-group">
                <label>
                    <input type="checkbox" id="sfShowDeleted" <?= $showDeleted ? 'checked' : '' ?>>
                    <?= htmlspecialchars(sf_term('users_show_deleted', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
            </div>
        </div>

        <div class="sf-users-compact-actions">
            <div class="sf-users-bulk-inline">
                <label class="sf-bulk-actions-label" for="sfBulkAction">
                    <?= htmlspecialchars(sf_term('bulk_action_select', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:
                </label>

                <select id="sfBulkAction" class="sf-bulk-select">
                    <option value="">— <?= htmlspecialchars(sf_term('bulk_action_select', $currentUiLang), ENT_QUOTES, 'UTF-8') ?> —</option>
                    <option value="enable_emails"><?= htmlspecialchars(sf_term('bulk_enable_emails', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="disable_emails"><?= htmlspecialchars(sf_term('bulk_disable_emails', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                </select>

                <button type="button" class="sf-btn sf-btn-primary sf-users-run-btn" id="sfBulkApply">
                    <?= htmlspecialchars(sf_term('bulk_action_apply', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>

                <span id="sfBulkSelectedCount" class="sf-bulk-selected-count"></span>
            </div>

            <button type="button" class="sf-btn sf-btn-secondary sf-users-clear-btn" id="sfFilterClear">
                <?= htmlspecialchars(sf_term('users_filter_clear', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- Skeleton loading for users table -->
<div class="skeleton-wrapper">
    <div class="skeleton-container skeleton-table-container" id="skeletonTable">
        <div class="skeleton-table">
            <div class="skeleton-table-header">
                <div class="skeleton skeleton-th" style="width: 15%;"></div>
                <div class="skeleton skeleton-th" style="width: 25%;"></div>
                <div class="skeleton skeleton-th" style="width: 15%;"></div>
                <div class="skeleton skeleton-th" style="width: 15%;"></div>
                <div class="skeleton skeleton-th" style="width: 15%;"></div>
                <div class="skeleton skeleton-th" style="width: 15%;"></div>
            </div>
            <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="skeleton-table-row">
                <div class="skeleton skeleton-td" style="width: 15%;"></div>
                <div class="skeleton skeleton-td" style="width: 25%;"></div>
                <div class="skeleton skeleton-td" style="width: 15%;"></div>
                <div class="skeleton skeleton-td" style="width: 15%;"></div>
                <div class="skeleton skeleton-td" style="width: 15%;"></div>
                <div class="skeleton skeleton-td" style="width: 15%;"></div>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- Actual content -->
    <div class="actual-content">

<!-- Loading overlay -->
<div class="sf-users-loading hidden" id="sfUsersLoading">
    <div class="sf-loading-spinner"></div>
    <span><?= htmlspecialchars(sf_term('users_loading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
</div>

<!-- MOBIILI: Korttinäkymä -->
<div class="sf-users-cards">
    <?php if (count($users) === 0): ?>
        <div class="sf-no-results">
            <?= htmlspecialchars(sf_term('users_no_results', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <?php foreach ($users as $u): ?>
        <div class="sf-user-card"
             data-role-id="<?= (int)$u['role_id'] ?>"
             data-worksite-id="<?= (int)($u['home_worksite_id'] ?? 0) ?>"
             data-name="<?= htmlspecialchars(strtolower(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
             data-email="<?= htmlspecialchars(strtolower($u['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
             data-has-logged-in="<?= !empty($u['last_login_at']) ? '1' : '0' ?>">
            <div class="sf-user-card-header">
                <div class="sf-user-card-name">
                    <?= htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?>
                </div>
                <div class="sf-user-card-role">
                    <?= htmlspecialchars(sf_role_name((int)$u['role_id'], $u['role_name'] ?? '', $currentUiLang)) ?>
                    <?php if (!empty($u['additional_roles'])): ?>
                        <?php foreach ($u['additional_roles'] as $addRoleId): ?>
                            <?php if (isset($roleIdToNameMap[$addRoleId])): ?>
                                <span class="sf-role-badge">+<?= htmlspecialchars($roleIdToNameMap[$addRoleId], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sf-user-card-email">
                <?= htmlspecialchars($u['email'] ?? '') ?>
            </div>
            <div class="sf-user-card-last-login">
    <strong><?= htmlspecialchars(sf_term('users_col_last_login', $currentUiLang) ?? 'Viimeksi kirjautunut', ENT_QUOTES, 'UTF-8') ?>:</strong>
    <?php
    if (!empty($u['last_login_at'])) {
        echo htmlspecialchars(date('d.m.Y H:i', strtotime($u['last_login_at'])), ENT_QUOTES, 'UTF-8');
    } else {
        echo '<span class="sf-last-login-never">' . htmlspecialchars(sf_term('users_last_login_never', $currentUiLang) ?? 'Ei koskaan', ENT_QUOTES, 'UTF-8') . '</span>';
    }
    ?>
</div>

            <?php if (!empty($u['home_worksite_name'])): ?>
                <div class="sf-user-card-worksite">
                    <?= htmlspecialchars($u['home_worksite_name']) ?>
                </div>
            <?php endif; ?>

            <div class="sf-user-card-actions">
                <button
                    class="sf-btn-small sf-edit-user"
                    type="button"
                    title="<?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    data-id="<?= (int) $u['id'] ?>"
                    data-first="<?= htmlspecialchars($u['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-last="<?= htmlspecialchars($u['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-email="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-role="<?= (int) $u['role_id'] ?>"
                    data-home-worksite="<?= (int) ($u['home_worksite_id'] ?? 0) ?>"
                    data-ui-lang="<?= htmlspecialchars($u['ui_lang'] ?? 'fi', ENT_QUOTES, 'UTF-8') ?>"
                    data-additional-roles="<?= htmlspecialchars(implode(',', $u['additional_roles'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                >
                    <img src="<?= $baseUrl ?>/assets/img/icons/edit.svg" alt="" class="sf-icon" aria-hidden="true">
                    <span class="sf-btn-text"><?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>

                <button
                    class="sf-btn-small sf-reset-pass"
                    type="button"
                    title="<?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    data-id="<?= (int) $u['id'] ?>"
                >
                    <img src="<?= $baseUrl ?>/assets/img/icons/locked_icon.svg" alt="" class="sf-icon" aria-hidden="true">
                    <span class="sf-btn-text"><?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>

                <button
                    class="sf-btn-small sf-delete-user sf-btn-danger"
                    type="button"
                    title="<?= htmlspecialchars(sf_term('users_action_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars(sf_term('users_action_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    data-id="<?= (int) $u['id'] ?>"
                >
                    <img src="<?= $baseUrl ?>/assets/img/icons/delete_icon.svg" alt="" class="sf-icon" aria-hidden="true">
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- DESKTOP: Taulukkonäkymä -->
<div class="sf-users-table-container">
<table class="sf-table sf-table-users">
    <thead>
        <tr>
            <th style="width: 40px;"><input type="checkbox" id="sfSelectAllUsers" class="sf-user-checkbox"></th>
            <th><?= htmlspecialchars(sf_term('users_col_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_email', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(sf_term('users_col_home_worksite', $currentUiLang) ?? 'Kotityömaa', ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('users_col_created', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('users_col_last_login', $currentUiLang) ?? 'Viimeksi kirjautunut', ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('users_col_actions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
        </tr>
    </thead>

    <tbody>
        <?php if (count($users) === 0): ?>
            <tr>
                <td colspan="8" class="sf-no-results">
                    <?= htmlspecialchars(sf_term('users_no_results', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
            <tr data-role-id="<?= (int)$u['role_id'] ?>"
                data-worksite-id="<?= (int)($u['home_worksite_id'] ?? 0) ?>"
                data-name="<?= htmlspecialchars(strtolower(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                data-email="<?= htmlspecialchars(strtolower($u['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-has-logged-in="<?= !empty($u['last_login_at']) ? '1' : '0' ?>"
                data-is-active="<?= $u['is_active'] ? '1' : '0' ?>"
                data-user-id="<?= (int)$u['id'] ?>"
                <?= !$u['is_active'] ? 'class="sf-user-inactive"' : '' ?>>
                <td>
                    <?php if ($u['is_active']): ?>
                        <input type="checkbox" class="sf-user-checkbox sf-user-select" data-user-id="<?= (int)$u['id'] ?>">
                    <?php endif; ?>
                </td>
                <td data-label="<?= htmlspecialchars(sf_term('users_col_name', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="sf-user-name-with-icon">
                        <?= htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?>
                        <?php if (!empty($u['email_notifications_enabled'])): ?>
                            <img src="<?= $baseUrl ?>/assets/img/icons/publish.svg" 
                                 alt="" 
                                 class="sf-email-status-icon active" 
                                 title="<?= htmlspecialchars(sf_term('email_notifications_enabled', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                                 aria-hidden="true">
                        <?php else: ?>
                            <img src="<?= $baseUrl ?>/assets/img/icons/error.svg" 
                                 alt="" 
                                 class="sf-email-status-icon inactive" 
                                 title="<?= htmlspecialchars(sf_term('email_notifications_disabled', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                                 aria-hidden="true">
                        <?php endif; ?>
                    </span>
                </td>
                <td data-label="<?= htmlspecialchars(sf_term('users_col_email', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($u['email'] ?? '') ?>
                </td>
                <td data-label="<?= htmlspecialchars(sf_term('users_col_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(sf_role_name((int)$u['role_id'], $u['role_name'] ?? '', $currentUiLang)) ?>
                    <?php if (!empty($u['additional_roles'])): ?>
                        <?php foreach ($u['additional_roles'] as $addRoleId): ?>
                            <?php if (isset($roleIdToNameMap[$addRoleId])): ?>
                                <span class="sf-role-badge">+<?= htmlspecialchars($roleIdToNameMap[$addRoleId], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td data-label="<?= htmlspecialchars(sf_term('users_col_home_worksite', $currentUiLang) ?? 'Kotityömaa', ENT_QUOTES, 'UTF-8') ?>">
                    <?php
                    if (!empty($u['home_worksite_name'])) {
                        echo htmlspecialchars($u['home_worksite_name'], ENT_QUOTES, 'UTF-8');
                    } else {
                        echo htmlspecialchars(
                            sf_term('users_home_worksite_none', $currentUiLang) ?? '–',
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    }
                    ?>
                </td>
                <td data-label="<?= htmlspecialchars(sf_term('users_col_created', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($u['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td data-label="<?= htmlspecialchars(sf_term('users_col_last_login', $currentUiLang) ?? 'Viimeksi kirjautunut', ENT_QUOTES, 'UTF-8') ?>">
                    <?php
                    if (!empty($u['last_login_at'])) {
                        echo htmlspecialchars(
                            date('d.m.Y H:i', strtotime($u['last_login_at'])),
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    } else {
                        echo htmlspecialchars(
                            sf_term('users_last_login_never', $currentUiLang) ?? 'Ei koskaan',
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    }
                    ?>
                </td>
                <td data-label="<?= htmlspecialchars(sf_term('users_col_actions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($u['is_active']): ?>
                        <button
                            class="sf-btn-small sf-edit-user"
                            type="button"
                            title="<?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="<?= htmlspecialchars(sf_term('users_action_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            data-id="<?= (int) $u['id'] ?>"
                            data-first="<?= htmlspecialchars($u['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            data-last="<?= htmlspecialchars($u['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            data-email="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            data-role="<?= (int) $u['role_id'] ?>"
                            data-home-worksite="<?= (int) ($u['home_worksite_id'] ?? 0) ?>"
                            data-ui-lang="<?= htmlspecialchars($u['ui_lang'] ?? 'fi', ENT_QUOTES, 'UTF-8') ?>"
                            data-email-notifications="<?= !empty($u['email_notifications_enabled']) ? '1' : '0' ?>"
                            data-additional-roles="<?= htmlspecialchars(implode(',', $u['additional_roles'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <img src="<?= $baseUrl ?>/assets/img/icons/edit_icon.svg" alt="" class="sf-icon" aria-hidden="true">
                        </button>

                        <button
                            class="sf-btn-small sf-reset-pass"
                            type="button"
                            title="<?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="<?= htmlspecialchars(sf_term('users_action_reset_pass', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            data-id="<?= (int) $u['id'] ?>"
                        >
                            <img src="<?= $baseUrl ?>/assets/img/icons/locked_icon.svg" alt="" class="sf-icon" aria-hidden="true">
                        </button>

                        <button
                            class="sf-btn-small sf-delete-user sf-btn-danger"
                            type="button"
                            title="<?= htmlspecialchars(sf_term('users_action_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="<?= htmlspecialchars(sf_term('users_action_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            data-id="<?= (int) $u['id'] ?>"
                        >
                            <img src="<?= $baseUrl ?>/assets/img/icons/delete_icon.svg" alt="" class="sf-icon" aria-hidden="true">
                        </button>
                    <?php else: ?>
                        <button
                            class="sf-btn-small sf-reactivate-user sf-btn-success"
                            type="button"
                            title="<?= htmlspecialchars(sf_term('users_action_reactivate', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="<?= htmlspecialchars(sf_term('users_action_reactivate', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            data-id="<?= (int) $u['id'] ?>"
                        >
                            ↻
                        </button>
                        <span class="sf-user-inactive-badge"><?= htmlspecialchars(sf_term('users_status_inactive', $currentUiLang) ?? 'Poistettu', ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <div class="sf-pagination">
        <?php if ($pageNum > 1): ?>
            <button class="sf-page-btn sf-btn sf-btn-secondary" data-page="1" title="<?= htmlspecialchars(sf_term('pagination_first', $currentUiLang) ?? 'Ensimmäinen', ENT_QUOTES, 'UTF-8') ?>">«</button>
            <button class="sf-page-btn sf-btn sf-btn-secondary" data-page="<?= $pageNum - 1 ?>" title="<?= htmlspecialchars(sf_term('pagination_prev', $currentUiLang) ?? 'Edellinen', ENT_QUOTES, 'UTF-8') ?>">‹</button>
        <?php endif; ?>
        
        <span class="sf-page-info">
            <?= htmlspecialchars(sf_term('pagination_page', $currentUiLang) ?? 'Sivu', ENT_QUOTES, 'UTF-8') ?> 
            <?= $pageNum ?> 
            <?= htmlspecialchars(sf_term('pagination_of', $currentUiLang) ?? '/', ENT_QUOTES, 'UTF-8') ?> 
            <?= $totalPages ?>
        </span>
        
        <?php if ($pageNum < $totalPages): ?>
            <button class="sf-page-btn sf-btn sf-btn-secondary" data-page="<?= $pageNum + 1 ?>" title="<?= htmlspecialchars(sf_term('pagination_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?>">›</button>
            <button class="sf-page-btn sf-btn sf-btn-secondary" data-page="<?= $totalPages ?>" title="<?= htmlspecialchars(sf_term('pagination_last', $currentUiLang) ?? 'Viimeinen', ENT_QUOTES, 'UTF-8') ?>">»</button>
        <?php endif; ?>
    </div>
<?php endif; ?>

    </div> <!-- .actual-content -->
</div> <!-- .skeleton-wrapper -->

<!-- DEBUG START -->
<?php
$modalPath = __DIR__ . '/modals_users.php';
echo "<!-- Modal path: " . $modalPath . " -->";
echo "<!-- File exists: " . (file_exists($modalPath) ? 'YES' : 'NO') . " -->";
if (file_exists($modalPath)) {
    echo "<!-- File size: " . filesize($modalPath) . " bytes -->";
}
?>
<!-- DEBUG END -->

<?php include __DIR__ . '/modals_users.php'; ?>

<script>
(function() {
    console.log("tab_users.php: Inline script executing");
    
    // Hide skeleton loader when content is ready
    var skeleton = document.getElementById('skeletonTable');
    var actualContent = document.querySelector('.actual-content');
    var loadingEl = document.getElementById('sfUsersLoading');
    
    if (skeleton) {
        skeleton.style.display = 'none';
        console.log("tab_users.php: Skeleton hidden");
    }
    if (actualContent) {
        actualContent.style.opacity = '1';
    }
    if (loadingEl) {
        loadingEl.classList.add('hidden');
        console.log("tab_users.php: Loading overlay hidden");
    }
    
    // Dispatch event for users.js to know content is ready
    window.dispatchEvent(new CustomEvent('sf:users:loaded'));
    console.log("tab_users.php: sf:users:loaded event dispatched");
})();
</script>