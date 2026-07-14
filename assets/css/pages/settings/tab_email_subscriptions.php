<?php
// assets/pages/settings/tab_email_subscriptions.php
declare(strict_types=1);

// Variables from settings.php: $mysqli, $baseUrl, $currentUiLang

if (!sf_is_admin_or_safety()) {
    echo '<p>Ei käyttöoikeutta.</p>';
    return;
}

$db = Database::getInstance();

// --- Category icon map (use system icons only) ---
$categoryIcons = [
	'sf_published_distribution'  => 'publish.svg',
	'sf_published_creator'       => 'publish_1.svg',
	'sf_published_participant'   => 'publish_1.svg',
	'sf_published_general'       => 'email.svg',
    'sf_request_info'            => 'info.svg',
    'sf_supervisor_approval'     => 'supervisor_icon.svg',
    'sf_to_supervisor'           => 'supervisor_icon.svg',
    'sf_to_safety_team'          => 'users.svg',
    'sf_to_comms'                => 'communications_icon.svg',
    'sf_worksite_notification'   => 'worksite.svg',
    'comment_on_own_flash'       => 'comment.svg',
    'comment_reply'              => 'reply.svg',
    'comment_mention'            => 'comment_icon.svg',
    'comment_subscribed'         => 'comment_count.svg',
    'comment_comms_to_safety'    => 'communications_icon.svg',
    'product_updates'            => 'changelog_icon.svg',
    'service_announcements'      => 'megaphone.svg',
    'feedback_status_change'     => 'info.svg',
    'feedback_comment'           => 'comment.svg',
];

// --- Fetch per-category stats ---
$statsStmt = $db->prepare(
    "SELECT category,
            SUM(enabled)       AS active_count,
            SUM(1 - enabled)   AS inactive_count
     FROM sf_user_notification_preferences
     GROUP BY category
     ORDER BY category"
);
$statsStmt->execute();
$categoryStats = [];
foreach ($statsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $categoryStats[$row['category']] = [
        'active'   => (int)$row['active_count'],
        'inactive' => (int)$row['inactive_count'],
    ];
}

// --- Fetch all users with their preferences ---
$usersStmt = $db->prepare(
    "SELECT u.id, u.first_name, u.last_name, u.email,
            r.name AS role_name, u.role_id
     FROM sf_users u
     LEFT JOIN sf_roles r ON r.id = u.role_id
     WHERE u.is_active = 1
     ORDER BY u.first_name, u.last_name"
);
$usersStmt->execute();
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all preferences and index by user_id+category
$prefsStmt = $db->prepare(
    "SELECT user_id, category, enabled FROM sf_user_notification_preferences"
);
$prefsStmt->execute();
$prefsByUser = [];
foreach ($prefsStmt->fetchAll(PDO::FETCH_ASSOC) as $pref) {
    $prefsByUser[(int)$pref['user_id']][$pref['category']] = (bool)$pref['enabled'];
}

// Collect all known categories
$allCategories = array_keys($categoryStats);
if (empty($allCategories)) {
    $allCategories = array_keys($categoryIcons);
}
sort($allCategories);

// Collect all known roles for filter
$rolesStmt = $db->prepare("SELECT id, name FROM sf_roles ORDER BY name");
$rolesStmt->execute();
$allRoles  = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>

<h2>
    <img src="<?= $baseUrl ?>/assets/img/icons/email.svg" alt="" class="sf-heading-icon" aria-hidden="true">
    <?= htmlspecialchars(sf_term('settings_subscriptions_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
</h2>
<p style="color:#64748b;margin:0 0 1.5rem;font-size:0.9rem;">
    <?= htmlspecialchars(sf_term('settings_subscriptions_description', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
</p>

<!-- Summary cards -->
<?php if (!empty($categoryStats)): ?>
<div class="sf-subs-cards">
    <?php foreach ($categoryStats as $cat => $counts):
        $icon = $categoryIcons[$cat] ?? 'email.svg';
        $label = sf_term('notif_' . $cat . '_label', $currentUiLang);
        if ($label === 'notif_' . $cat . '_label') {
            $label = str_replace(['sf_', 'comment_', '_'], ['', '', ' '], $cat);
            $label = ucfirst(trim($label));
        }
    ?>
    <div class="sf-subs-card">
        <div class="sf-subs-card-icon">
            <img src="<?= $baseUrl ?>/assets/img/icons/<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true">
        </div>
        <div class="sf-subs-card-body">
            <div class="sf-subs-card-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="sf-subs-card-counts">
                <span class="sf-subs-count-active" title="<?= htmlspecialchars(sf_term('settings_subscriptions_active', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <?= $counts['active'] ?>
                    <small><?= htmlspecialchars(sf_term('settings_subscriptions_active', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                </span>
                <span class="sf-subs-count-sep">·</span>
                <span class="sf-subs-count-inactive" title="<?= htmlspecialchars(sf_term('settings_subscriptions_inactive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <?= $counts['inactive'] ?>
                    <small><?= htmlspecialchars(sf_term('settings_subscriptions_inactive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                </span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filters and export -->
<div class="sf-subs-toolbar">
    <div class="sf-subs-filters">
        <input
            type="text"
            id="sfSubsSearch"
            class="sf-input sf-subs-filter-input"
            placeholder="<?= htmlspecialchars(sf_term('settings_subscriptions_search', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        >
        <select id="sfSubsCategoryFilter" class="sf-select sf-subs-filter-select">
            <option value=""><?= htmlspecialchars(sf_term('settings_subscriptions_all_categories', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
            <?php foreach ($allCategories as $cat):
                $label = sf_term('notif_' . $cat . '_label', $currentUiLang);
                if ($label === 'notif_' . $cat . '_label') {
                    $label = str_replace(['sf_', 'comment_', '_'], ['', '', ' '], $cat);
                    $label = ucfirst(trim($label));
                }
            ?>
            <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <select id="sfSubsRoleFilter" class="sf-select sf-subs-filter-select">
            <option value=""><?= htmlspecialchars(sf_term('settings_subscriptions_all_roles', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
            <?php foreach ($allRoles as $role): ?>
            <option value="<?= (int)$role['id'] ?>"><?= htmlspecialchars($role['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <a
        id="sfSubsExportBtn"
        href="<?= $baseUrl ?>/app/api/admin_subscriptions_export.php"
        class="sf-btn sf-btn-secondary sf-subs-export-btn"
        download
    >
        <img src="<?= $baseUrl ?>/assets/img/icons/download.svg" alt="" class="sf-btn-icon" aria-hidden="true">
        <?= htmlspecialchars(sf_term('settings_subscriptions_export_csv', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<!-- User table -->
<div class="sf-subs-table-wrap">
    <table class="sf-subs-table" id="sfSubsTable">
        <thead>
            <tr>
                <th><?= htmlspecialchars(sf_term('settings_subscriptions_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(sf_term('settings_subscriptions_email_col', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(sf_term('settings_subscriptions_role', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
                <?php foreach ($allCategories as $cat):
                    $icon = $categoryIcons[$cat] ?? 'email.svg';
                    $label = sf_term('notif_' . $cat . '_label', $currentUiLang);
                    if ($label === 'notif_' . $cat . '_label') {
                        $label = str_replace(['sf_', 'comment_', '_'], ['', '', ' '], $cat);
                        $label = ucfirst(trim($label));
                    }
                ?>
                <th class="sf-subs-cat-col" data-category="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $baseUrl ?>/assets/img/icons/<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" class="sf-subs-cat-icon">
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody id="sfSubsTableBody">
            <?php foreach ($users as $user):
                $userId   = (int)$user['id'];
                $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $email    = $user['email'] ?? '';
                $roleName = $user['role_name'] ?? '';
                $roleId   = (int)($user['role_id'] ?? 0);
                $userPrefs = $prefsByUser[$userId] ?? [];
            ?>
            <tr
                class="sf-subs-row"
                data-name="<?= htmlspecialchars(strtolower($fullName . ' ' . $email), ENT_QUOTES, 'UTF-8') ?>"
                data-role="<?= $roleId ?>"
            >
                <td class="sf-subs-td-name"><?= htmlspecialchars($fullName ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                <td class="sf-subs-td-email"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="sf-subs-td-role"><?= htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?></td>
                <?php foreach ($allCategories as $cat):
                    $enabled = $userPrefs[$cat] ?? null;
                ?>
                <td
                    class="sf-subs-td-cat"
                    data-category="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                    data-enabled="<?= $enabled === null ? '' : ($enabled ? '1' : '0') ?>"
                >
                    <?php if ($enabled === null): ?>
                        <span class="sf-subs-check sf-subs-check-na" title="—">—</span>
                    <?php elseif ($enabled): ?>
                        <span class="sf-subs-check sf-subs-check-on" title="Päällä">
                            <img src="<?= $baseUrl ?>/assets/img/icons/check.svg" alt="✓" class="sf-subs-check-icon">
                        </span>
                    <?php else: ?>
                        <span class="sf-subs-check sf-subs-check-off" title="Pois">—</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p id="sfSubsNoResults" class="sf-subs-no-results" style="display:none;">
        <?= htmlspecialchars(sf_term('settings_subscriptions_no_results', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
    </p>
</div>

<style>
/* Subscriptions summary cards */
.sf-subs-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.875rem;
    margin-bottom: 1.75rem;
}

.sf-subs-card {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.sf-subs-card-icon {
    flex-shrink: 0;
    width: 2.25rem;
    height: 2.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f1f5f9;
    border-radius: 8px;
}

.sf-subs-card-icon img {
    width: 1.2rem;
    height: 1.2rem;
    opacity: 0.7;
}

.sf-subs-card-body {
    flex: 1;
    min-width: 0;
}

.sf-subs-card-label {
    font-size: 0.8rem;
    color: #64748b;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 0.25rem;
}

.sf-subs-card-counts {
    display: flex;
    align-items: baseline;
    gap: 0.375rem;
    font-size: 0.9rem;
}

.sf-subs-count-active {
    font-size: 1.3rem;
    font-weight: 700;
    color: #16a34a;
    display: flex;
    align-items: baseline;
    gap: 0.25rem;
}

.sf-subs-count-active small {
    font-size: 0.7rem;
    font-weight: 500;
    color: #64748b;
}

.sf-subs-count-sep {
    color: #cbd5e1;
}

.sf-subs-count-inactive {
    font-size: 0.9rem;
    color: #94a3b8;
    display: flex;
    align-items: baseline;
    gap: 0.2rem;
}

.sf-subs-count-inactive small {
    font-size: 0.7rem;
}

/* Toolbar */
.sf-subs-toolbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.sf-subs-filters {
    display: flex;
    gap: 0.625rem;
    flex-wrap: wrap;
    flex: 1;
}

.sf-subs-filter-input {
    flex: 1;
    min-width: 180px;
    max-width: 280px;
}

.sf-subs-filter-select {
    min-width: 160px;
}

.sf-subs-export-btn {
    flex-shrink: 0;
}

/* Table */
.sf-subs-table-wrap {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
}

.sf-subs-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.sf-subs-table thead th {
    background: #f8fafc;
    color: #374151;
    font-weight: 600;
    padding: 0.625rem 0.875rem;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
}

.sf-subs-table thead th.sf-subs-cat-col {
    text-align: center;
    padding: 0.5rem 0.5rem;
    min-width: 2.5rem;
}

.sf-subs-cat-icon {
    width: 1rem;
    height: 1rem;
    opacity: 0.6;
    vertical-align: middle;
}

.sf-subs-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.1s ease;
}

.sf-subs-table tbody tr:last-child {
    border-bottom: none;
}

.sf-subs-table tbody tr:hover {
    background: #f8fafc;
}

.sf-subs-table td {
    padding: 0.55rem 0.875rem;
    color: #374151;
}

.sf-subs-td-name {
    font-weight: 500;
    white-space: nowrap;
}

.sf-subs-td-email {
    color: #64748b;
    font-size: 0.82rem;
}

.sf-subs-td-role {
    color: #64748b;
    font-size: 0.82rem;
    white-space: nowrap;
}

.sf-subs-td-cat {
    text-align: center;
}

.sf-subs-check {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.sf-subs-check-on {
    color: #16a34a;
}

.sf-subs-check-icon {
    width: 0.875rem;
    height: 0.875rem;
    filter: invert(40%) sepia(100%) saturate(400%) hue-rotate(100deg);
}

.sf-subs-check-off {
    color: #cbd5e1;
    font-size: 0.75rem;
}

.sf-subs-check-na {
    color: #e2e8f0;
    font-size: 0.75rem;
}

.sf-subs-no-results {
    text-align: center;
    padding: 2rem;
    color: #94a3b8;
}

.sf-subs-row.sf-subs-hidden {
    display: none;
}
</style>

<script>
(function() {
    'use strict';

    const searchInput    = document.getElementById('sfSubsSearch');
    const categoryFilter = document.getElementById('sfSubsCategoryFilter');
    const roleFilter     = document.getElementById('sfSubsRoleFilter');
    const exportBtn      = document.getElementById('sfSubsExportBtn');
    const tableBody      = document.getElementById('sfSubsTableBody');
    const noResults      = document.getElementById('sfSubsNoResults');
    const tableWrap      = document.querySelector('.sf-subs-table-wrap');
    const base           = '<?= $baseUrl ?>';

    function applyFilters() {
        const search   = (searchInput?.value || '').trim().toLowerCase();
        const category = categoryFilter?.value || '';
        const role     = roleFilter?.value || '';

        const rows = tableBody ? tableBody.querySelectorAll('.sf-subs-row') : [];
        let visibleCount = 0;

        rows.forEach(function(row) {
            const nameEmail = (row.dataset.name || '').toLowerCase();
            const rowRole   = row.dataset.role || '';

            let show = true;

            if (search && !nameEmail.includes(search)) {
                show = false;
            }

            if (role && rowRole !== role) {
                show = false;
            }

            if (category && show) {
                const catCell = row.querySelector('[data-category="' + category + '"]');
                const enabled = catCell ? catCell.dataset.enabled : '';
                if (enabled !== '1') {
                    show = false;
                }
            }

            if (show) {
                row.classList.remove('sf-subs-hidden');
                visibleCount++;
            } else {
                row.classList.add('sf-subs-hidden');
            }
        });

        if (noResults) {
            noResults.style.display = (visibleCount === 0) ? '' : 'none';
        }

        // Update export URL with current filters
        if (exportBtn) {
            const params = new URLSearchParams();
            if (search)   { params.set('search', search); }
            if (category) { params.set('category', category); }
            if (role)     { params.set('role_id', role); }
            exportBtn.href = base + '/app/api/admin_subscriptions_export.php' + (params.toString() ? '?' + params.toString() : '');
        }

        // Show/hide category columns in header
        if (category) {
            document.querySelectorAll('.sf-subs-cat-col').forEach(function(th) {
                th.style.display = (th.dataset.category === category) ? '' : 'none';
            });
            document.querySelectorAll('.sf-subs-td-cat').forEach(function(td) {
                td.style.display = (td.dataset.category === category) ? '' : 'none';
            });
        } else {
            document.querySelectorAll('.sf-subs-cat-col, .sf-subs-td-cat').forEach(function(el) {
                el.style.display = '';
            });
        }
    }

    if (searchInput)    { searchInput.addEventListener('input', applyFilters); }
    if (categoryFilter) { categoryFilter.addEventListener('change', applyFilters); }
    if (roleFilter)     { roleFilter.addEventListener('change', applyFilters); }
})();
</script>