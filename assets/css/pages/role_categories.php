<?php
// assets/pages/role_categories.php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';

// Allow admin and safety team
if (!sf_is_admin_or_safety()) {
    http_response_code(403);
    echo 'Ei käyttöoikeutta. Vain pääkäyttäjät ja turvatiimi voivat hallita roolikategorioita.';
    exit;
}

$mysqli = sf_db();

// Get all role categories with user counts
$sql = "SELECT rc.id,
               rc.name,
               rc.type,
               rc.worksite,
               rc.is_active,
               rc.created_at,
               COUNT(urc.user_id) as user_count
        FROM role_categories rc
        LEFT JOIN user_role_categories urc ON rc.id = urc.role_category_id
        GROUP BY rc.id
        ORDER BY rc.type, rc.name";
$categories = [];
$res = $mysqli->query($sql);
while ($row = $res->fetch_assoc()) {
    $categories[] = $row;
}

// Get all active users for assignment
$usersRes = $mysqli->query("
    SELECT id, first_name, last_name, email 
    FROM sf_users 
    WHERE is_active = 1 
    ORDER BY last_name, first_name
");
$users = [];
while ($u = $usersRes->fetch_assoc()) {
    $users[] = $u;
}

// Get all worksites for dropdown
$worksitesRes = $mysqli->query("SELECT name FROM sf_worksites WHERE is_active = 1 AND show_in_worksite_lists = 1 ORDER BY name ASC");
$worksites = [];
while ($w = $worksitesRes->fetch_assoc()) {
    $worksites[] = $w['name'];
}

$baseUrl = rtrim($config['base_url'] ?? '', '/');
$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

$roleCategoryTypeLabels = [
    'supervisor' => sf_term('role_category_type_supervisor', $currentUiLang) ?: 'Työmaavastaava',
    'approver' => sf_term('role_category_type_approver', $currentUiLang) ?: 'Hyväksyjä',
    'reviewer' => sf_term('role_category_type_reviewer', $currentUiLang) ?: 'Tarkastaja',
];

$visibleRoleCategoryTypes = [
    'supervisor',
];
?>
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/role-categories.css', $baseUrl) ?>">

<div class="sf-page-container">
<div class="sf-role-categories-page">

    <section class="sf-role-overview-card">
        <div class="sf-role-overview-icon" aria-hidden="true">i</div>
        <div class="sf-role-overview-content">
            <h2><?= htmlspecialchars(sf_term('role_categories_overview_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            <p>
                <?= htmlspecialchars(sf_term('role_categories_overview_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
    </section>

    <!-- Worksite Filter -->
    <div class="sf-filter-bar">
        <label for="sfWorksiteFilter" class="sf-filter-label">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
            </svg>
                        <?= htmlspecialchars(sf_term('role_categories_filter_by_worksite', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </label>
        <select id="sfWorksiteFilter" class="sf-filter-select">
            <option value=""><?= htmlspecialchars(sf_term('role_categories_all_worksites', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
            <option value="__global__"><?= htmlspecialchars(sf_term('role_categories_global_categories', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
            <?php 
            // Get unique worksites from categories
            $uniqueWorksites = array_unique(array_filter(array_column($categories, 'worksite')));
            sort($uniqueWorksites);
            foreach ($uniqueWorksites as $ws): 
            ?>
                <option value="<?= htmlspecialchars($ws) ?>">
                    <?= htmlspecialchars($ws) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span id="sfFilterCount" class="sf-filter-count"></span>
    </div>

    <div class="sf-categories-header">
        <button class="sf-btn sf-btn-primary" id="sfAddCategoryBtn">
            + <?= htmlspecialchars(sf_term('role_categories_add_category', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <div class="sf-categories-grid">
        <?php foreach ($categories as $cat): ?>
            <?php
            $typeLabel = $roleCategoryTypeLabels[$cat['type']] ?? $cat['type'];
            $worksiteLabel = $cat['worksite'] ? $cat['worksite'] : (sf_term('role_categories_all_worksites', $currentUiLang) ?: 'Kaikki työmaat');
            ?>
            <div class="sf-category-card"
                 data-id="<?= (int)$cat['id']; ?>"
                 data-worksite="<?= $cat['worksite'] ? htmlspecialchars($cat['worksite'], ENT_QUOTES, 'UTF-8') : '__global__' ?>">
                <div class="sf-category-card-top">
                    <div class="sf-category-title-wrap">
    <span class="sf-category-status-dot <?= $cat['is_active'] ? 'active' : 'inactive' ?>"></span>

    <div>
        <h3><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></h3>
        <p><?= htmlspecialchars($worksiteLabel, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</div>

                    <?php if ($cat['type'] !== 'supervisor'): ?>
                        <span class="sf-category-badge sf-category-badge-<?= htmlspecialchars($cat['type'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="sf-category-metrics">
                    <div class="sf-category-metric">
                        <span class="sf-category-metric-label"><?= htmlspecialchars(sf_term('role_categories_users_count_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= (int)$cat['user_count'] ?></strong>
                    </div>

                </div>

                <div class="sf-category-actions">
                    <button class="sf-btn-small sf-manage-users-btn"
                            type="button"
                            data-id="<?= (int)$cat['id']; ?>"
                            data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?= htmlspecialchars(sf_term('role_categories_manage_users', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <button class="sf-btn-small sf-edit-category-btn"
                            type="button"
                            data-id="<?= (int)$cat['id']; ?>">
                                                <?= htmlspecialchars(sf_term('edit', $currentUiLang) ?: 'Muokkaa', ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <button class="sf-btn-small sf-btn-danger sf-delete-category-btn"
                            type="button"
                            data-id="<?= (int)$cat['id']; ?>"
                            data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars(sf_term('delete', $currentUiLang) ?: 'Poista', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($categories)): ?>
            <div class="sf-empty-state">
                <p><?= htmlspecialchars(sf_term('role_categories_empty_state', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Add/Edit Category Modal -->
<div id="sfCategoryModal" class="sf-modal hidden" style="display: none;">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h2 id="sfCategoryModalTitle"><?= htmlspecialchars(sf_term('role_categories_modal_add_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            <button class="sf-modal-close" id="sfCategoryModalClose">&times;</button>
        </div>
        <div class="sf-modal-body">
            <form id="sfCategoryForm">
                <input type="hidden" id="categoryId" name="id" value="">
                
                <div class="sf-form-group">
                    <label for="categoryName"><?= htmlspecialchars(sf_term('name', $currentUiLang) ?: 'Nimi', ENT_QUOTES, 'UTF-8') ?> *</label>
                    <input type="text" id="categoryName" name="name" required 
                           placeholder="<?= htmlspecialchars(sf_term('role_categories_name_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                
                <div class="sf-form-group">
                    <label for="categoryType"><?= htmlspecialchars(sf_term('type', $currentUiLang) ?: 'Tyyppi', ENT_QUOTES, 'UTF-8') ?> *</label>
                    <select id="categoryType" name="type" required>
                        <option value=""><?= htmlspecialchars(sf_term('role_categories_select_type', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="supervisor"><?= htmlspecialchars(sf_term('role_category_type_supervisor', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                    <small>
                        <?= htmlspecialchars(sf_term('role_categories_type_help', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </small>
                </div>
                
                <div class="sf-form-group">
                    <label for="categoryWorksite"><?= htmlspecialchars(sf_term('worksite', $currentUiLang) ?: 'Työmaa', ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="categoryWorksite" name="worksite">
                        <option value=""><?= htmlspecialchars(sf_term('role_categories_all_worksites', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php foreach ($worksites as $ws): ?>
                            <option value="<?= htmlspecialchars($ws) ?>">
                                <?= htmlspecialchars($ws) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small><?= htmlspecialchars(sf_term('role_categories_worksite_help', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                
                <div class="sf-form-group">
                    <label class="sf-active-checkbox">
                        <input type="checkbox" id="categoryIsActive" name="is_active" checked>
                        <span><?= htmlspecialchars(sf_term('active', $currentUiLang) ?: 'Aktiivinen', ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                </div>
                
                <div class="sf-form-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" id="sfCategoryFormCancel">
                                                <?= htmlspecialchars(sf_term('cancel', $currentUiLang) ?: 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="submit" class="sf-btn sf-btn-primary">
                        <?= htmlspecialchars(sf_term('save', $currentUiLang) ?: 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Users Modal -->
<div id="sfManageUsersModal" class="sf-modal hidden" style="display: none;">
    <div class="sf-modal-content sf-modal-large">
        <div class="sf-modal-header">
            <h2 id="sfManageUsersModalTitle"><?= htmlspecialchars(sf_term('role_categories_manage_users', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            <button class="sf-modal-close" id="sfManageUsersModalClose">&times;</button>
        </div>
        <div class="sf-modal-body">
            <input type="hidden" id="manageCategoryId" value="">
            
            <div class="sf-manage-users-container">
                <div class="sf-manage-users-section">
                    <h3><?= htmlspecialchars(sf_term('role_categories_current_users', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                    <div id="sfCurrentUsersList" class="sf-users-list">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>
                
                <div class="sf-manage-users-section">
                    <                    <h3><?= htmlspecialchars(sf_term('role_categories_add_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                    <select id="sfAddUserSelect" class="sf-select">
                        <option value=""><?= htmlspecialchars(sf_term('role_categories_select_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['email'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="sf-btn sf-btn-primary" id="sfAddUserBtn" style="margin-top: 10px;">
                        Lisää käyttäjä
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
window.SF_BASE_URL = <?php echo json_encode($baseUrl); ?>;
window.SF_ALL_USERS = <?php echo json_encode($users); ?>;
window.SF_ROLE_CATEGORY_TERMS = <?php echo json_encode([
    'modal_add_title' => sf_term('role_categories_modal_add_title', $currentUiLang),
    'modal_edit_title' => sf_term('role_categories_modal_edit_title', $currentUiLang),
    'manage_users_title' => sf_term('role_categories_manage_users_title', $currentUiLang),
    'load_error' => sf_term('role_categories_load_error', $currentUiLang),
    'unknown_error' => sf_term('unknown_error', $currentUiLang) ?: 'Tuntematon virhe',
    'delete_confirm' => sf_term('role_categories_delete_confirm', $currentUiLang),
    'delete_success' => sf_term('role_categories_delete_success', $currentUiLang),
    'delete_error' => sf_term('role_categories_delete_error', $currentUiLang),
    'select_user' => sf_term('role_categories_select_user', $currentUiLang),
    'user_added' => sf_term('role_categories_user_added', $currentUiLang),
    'add_user_error' => sf_term('role_categories_add_user_error', $currentUiLang),
    'save_success' => sf_term('role_categories_save_success', $currentUiLang),
    'save_error' => sf_term('role_categories_save_error', $currentUiLang),
    'users_load_error' => sf_term('role_categories_users_load_error', $currentUiLang),
    'no_users' => sf_term('role_categories_no_users', $currentUiLang),
    'remove_user' => sf_term('delete', $currentUiLang) ?: 'Poista',
    'remove_user_confirm' => sf_term('role_categories_remove_user_confirm', $currentUiLang),
    'user_removed' => sf_term('role_categories_user_removed', $currentUiLang),
    'remove_user_error' => sf_term('role_categories_remove_user_error', $currentUiLang),
    'count_one' => sf_term('role_categories_count_one', $currentUiLang),
    'count_many' => sf_term('role_categories_count_many', $currentUiLang),
    'count_total' => sf_term('role_categories_count_total', $currentUiLang),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="<?= sf_asset_url('assets/js/role-categories.js', $baseUrl) ?>"></script>