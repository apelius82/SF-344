<?php
// assets/pages/settings/tab_audit_log.php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/includes/audit_log.php';

// Suodattimet
$filterAction   = $_GET['action']    ?? '';
$filterUser     = $_GET['user']      ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to']   ?? '';
$filterIp       = $_GET['ip']        ?? '';
$logPage        = max(1, (int) ($_GET['p'] ?? 1));
$perPage        = 30;
$offset         = ($logPage - 1) * $perPage;
$currentUrl     = $_SERVER['REQUEST_URI'] ?? '/index.php?page=settings&tab=logs&sub=app';

// Rakenna kysely
$where  = [];
$params = [];
$types  = '';

if ($filterAction !== '') {
    $where[]  = 'action = ?';
    $params[] = $filterAction;
    $types   .= 's';
}

if ($filterUser !== '') {
    $where[]  = '(user_email LIKE ? OR CAST(user_id AS CHAR) = ?)';
    $params[] = "%{$filterUser}%";
    $params[] = $filterUser;
    $types   .= 'ss';
}

if ($filterDateFrom !== '') {
    $where[]  = 'created_at >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
    $types   .= 's';
}

if ($filterDateTo !== '') {
    $where[]  = 'created_at <= ?';
    $params[] = $filterDateTo . ' 23:59:59';
    $types   .= 's';
}

if ($filterIp !== '') {
    $where[]  = 'ip_address LIKE ?';
    $params[] = "%{$filterIp}%";
    $types   .= 's';
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Laske kokonaismäärä
$countSql  = "SELECT COUNT(*) AS total FROM sf_audit_log {$whereClause}";
$countStmt = $mysqli->prepare($countSql);
if ($params) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRow  = $countStmt->get_result()->fetch_assoc();
$totalRows = (int) ($totalRow['total'] ?? 0);
$totalPages = (int) ceil($totalRows / $perPage);

// Hae lokit
$sql  = "SELECT * FROM sf_audit_log {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $mysqli->prepare($sql);

$allParams = array_merge($params, [$perPage, $offset]);
$allTypes  = $types . 'ii';

if ($allParams) {
    $stmt->bind_param($allTypes, ...$allParams);
}
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Hae uniikit toiminnot
$actionsResult    = $mysqli->query('SELECT DISTINCT action FROM sf_audit_log ORDER BY action');
$availableActions = $actionsResult ? $actionsResult->fetch_all(MYSQLI_ASSOC) : [];
?>

<h2>
    <img src="<?= $baseUrl ?>/assets/img/icons/calendar.svg" alt="" class="sf-heading-icon" aria-hidden="true">
    <?= htmlspecialchars(
        sf_term('audit_log_heading', $currentUiLang) ?? 'Tapahtumaloki',
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</h2>
<p class="sf-audit-subtitle">
    <?= htmlspecialchars(sf_term('audit_log_total', $currentUiLang) ?? 'Yhteensä', ENT_QUOTES, 'UTF-8') ?>
    <strong><?= number_format($totalRows) ?></strong>
    <?= htmlspecialchars(sf_term('audit_log_events', $currentUiLang) ?? 'tapahtumaa', ENT_QUOTES, 'UTF-8') ?>
</p>

<!-- SUODATTIMET -->
<form method="get" class="sf-audit-filters">
    <input type="hidden" name="page" value="settings">
    <input type="hidden" name="tab" value="logs">
    <input type="hidden" name="sub" value="app">
    
    <div class="sf-filter-row">
        <div class="sf-filter-group">
            <label for="f-action">
    <?= htmlspecialchars(sf_term('audit_filter_action', $currentUiLang) ?? 'Toiminto', ENT_QUOTES, 'UTF-8') ?>
</label>
            <select name="action" id="f-action" class="sf-filter-select">
                <option value="">
    <?= htmlspecialchars(sf_term('filter_all', $currentUiLang) ?? 'Kaikki', ENT_QUOTES, 'UTF-8') ?>
</option>
                <?php foreach ($availableActions as $a): ?>
                    <option value="<?= htmlspecialchars($a['action']) ?>" 
                            <?= $filterAction === $a['action'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_audit_action_label($a['action'], $currentUiLang)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sf-filter-group">
<label for="f-user">
    <?= htmlspecialchars(sf_term('audit_filter_user', $currentUiLang) ?? 'Käyttäjä', ENT_QUOTES, 'UTF-8') ?>
</label>
<input type="text" name="user" id="f-user" class="sf-filter-input"
       placeholder="<?= htmlspecialchars(sf_term('audit_filter_user_placeholder', $currentUiLang) ?? 'Sähköposti tai ID', ENT_QUOTES, 'UTF-8') ?>"
       value="<?= htmlspecialchars($filterUser) ?>">
        </div>

        <div class="sf-filter-group">
<label for="f-ip">
    <?= htmlspecialchars(sf_term('audit_filter_ip', $currentUiLang) ?? 'IP-osoite', ENT_QUOTES, 'UTF-8') ?>
</label>
            <input type="text" name="ip" id="f-ip" class="sf-filter-input"
                   placeholder="192.168..."
                   value="<?= htmlspecialchars($filterIp) ?>">
        </div>

        <div class="sf-filter-group">
<label for="f-from">
    <?= htmlspecialchars(sf_term('audit_filter_date_from', $currentUiLang) ?? 'Alkaen', ENT_QUOTES, 'UTF-8') ?>
</label>            <input type="date" name="date_from" id="f-from" class="sf-filter-input"
                   value="<?= htmlspecialchars($filterDateFrom) ?>">
        </div>

        <div class="sf-filter-group">
<label for="f-to">
    <?= htmlspecialchars(sf_term('audit_filter_date_to', $currentUiLang) ?? 'Päättyen', ENT_QUOTES, 'UTF-8') ?>
</label>            <input type="date" name="date_to" id="f-to" class="sf-filter-input"
                   value="<?= htmlspecialchars($filterDateTo) ?>">
        </div>

    </div>
    
    <div class="sf-filter-buttons">
        <button type="submit" class="sf-btn sf-btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <?= htmlspecialchars(sf_term('audit_btn_filter', $currentUiLang) ?? 'Suodata', ENT_QUOTES, 'UTF-8') ?>
            </button>

        <a href="<?= $baseUrl ?>/index.php?page=settings&tab=logs&sub=app"
           class="sf-btn sf-btn-secondary">
            <?= htmlspecialchars(sf_term('audit_btn_clear', $currentUiLang) ?? 'Tyhjennä', ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>
</form>

<!-- TAULUKKO -->
<form method="post"
      action="<?= htmlspecialchars($baseUrl . '/app/actions/audit_log_delete.php', ENT_QUOTES, 'UTF-8') ?>"
      id="sfAuditBulkDeleteForm"
      class="sf-audit-bulk-delete-form"
      onsubmit="return sfConfirmAuditBulkDelete();">
    <?= sf_csrf_field() ?>
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>">

    <div class="sf-audit-bulk-bar">
        <label class="sf-audit-select-all">
            <input type="checkbox" id="sfAuditSelectAll">
            <span><?= htmlspecialchars(sf_term('audit_bulk_select_all', $currentUiLang) ?? 'Valitse kaikki näkyvät', ENT_QUOTES, 'UTF-8') ?></span>
        </label>

        <span class="sf-audit-bulk-count" id="sfAuditBulkCount">
            <?= htmlspecialchars(sf_term('audit_bulk_none_selected', $currentUiLang) ?? 'Ei valittuja rivejä', ENT_QUOTES, 'UTF-8') ?>
        </span>

        <button type="submit"
                class="sf-btn sf-btn-danger sf-audit-bulk-delete-btn"
                id="sfAuditBulkDeleteBtn"
                disabled>
            <?= htmlspecialchars(sf_term('audit_bulk_delete_btn', $currentUiLang) ?? 'Poista valitut', ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</form>

<!-- TAULUKKO -->
<div class="sf-audit-table-wrapper">
    <table class="sf-table sf-audit-table">
        <thead>
<tr>
<th class="sf-audit-select-col">
    <span class="sf-visually-hidden"><?= htmlspecialchars(sf_term('audit_bulk_select_row', $currentUiLang) ?? 'Valitse rivi', ENT_QUOTES, 'UTF-8') ?></span>
</th>
<th><?= htmlspecialchars(sf_term('audit_col_time', $currentUiLang) ?? 'Aika', ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('audit_col_user', $currentUiLang) ?? 'Käyttäjä', ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('audit_col_action', $currentUiLang) ?? 'Toiminto', ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('audit_col_target', $currentUiLang) ?? 'Kohde', ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('audit_col_ip', $currentUiLang) ?? 'IP', ENT_QUOTES, 'UTF-8') ?></th>
<th><?= htmlspecialchars(sf_term('audit_col_details', $currentUiLang) ?? 'Tiedot', ENT_QUOTES, 'UTF-8') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr>
<td colspan="7" class="sf-no-results">
    <?= htmlspecialchars(sf_term('audit_no_results', $currentUiLang) ?? 'Ei tapahtumia', ENT_QUOTES, 'UTF-8') ?>
</td>                </tr>
            <?php else: ?>
                <?php 
                // Define labels for data-label attributes
                $labelTime = htmlspecialchars(sf_term('audit_col_time', $currentUiLang) ?? 'Aika', ENT_QUOTES, 'UTF-8');
                $labelUser = htmlspecialchars(sf_term('audit_col_user', $currentUiLang) ?? 'Käyttäjä', ENT_QUOTES, 'UTF-8');
                $labelAction = htmlspecialchars(sf_term('audit_col_action', $currentUiLang) ?? 'Toiminto', ENT_QUOTES, 'UTF-8');
                $labelTarget = htmlspecialchars(sf_term('audit_col_target', $currentUiLang) ?? 'Kohde', ENT_QUOTES, 'UTF-8');
                $labelIp = htmlspecialchars(sf_term('audit_col_ip', $currentUiLang) ?? 'IP', ENT_QUOTES, 'UTF-8');
                $labelDetails = htmlspecialchars(sf_term('audit_col_details', $currentUiLang) ?? 'Tiedot', ENT_QUOTES, 'UTF-8');
                ?>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $actionClass = 'action-default';
                    if (str_contains($log['action'], 'delete')) {
                        $actionClass = 'action-danger';
                    } elseif (str_contains($log['action'], 'create')) {
                        $actionClass = 'action-success';
                    } elseif ($log['action'] === 'login') {
                        $actionClass = 'action-info';
                    } elseif ($log['action'] === 'login_failed') {
                        $actionClass = 'action-warning';
                    } elseif (str_contains($log['action'], 'update')) {
                        $actionClass = 'action-update';
                    }

                    $details = $log['details'] ? json_decode($log['details'], true) : null;
                    ?>
                     <tr>
                    <tr>
                        <td class="sf-audit-select-cell" data-label="<?= htmlspecialchars(sf_term('audit_bulk_select_row', $currentUiLang) ?? 'Valitse', ENT_QUOTES, 'UTF-8') ?>">
                            <input type="checkbox"
                                   class="sf-audit-row-checkbox"
                                   name="log_ids[]"
                                   value="<?= (int)$log['id'] ?>"
                                   form="sfAuditBulkDeleteForm"
                                   aria-label="<?= htmlspecialchars(sf_term('audit_bulk_select_row', $currentUiLang) ?? 'Valitse rivi', ENT_QUOTES, 'UTF-8') ?>">
                        </td>

                        <td class="sf-audit-time" data-label="<?= $labelTime ?>">
                            <?= date('d.m.Y H:i:s', strtotime($log['created_at'])) ?>
                        </td>

                        <td class="sf-audit-user" data-label="<?= $labelUser ?>">
                            <?php if (!empty($log['user_email'])): ?>
                                <span class="user-email">
                                    <?= htmlspecialchars($log['user_email'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <span class="user-id">
                                    #<?= (int) $log['user_id'] ?>
                                </span>
                            <?php else: ?>
                                <span class="user-anonymous">–</span>
                            <?php endif; ?>
                        </td>

                        <td class="sf-audit-action-cell" data-label="<?= $labelAction ?>">
                            <span class="sf-audit-action <?= $actionClass ?>">
                                <?= htmlspecialchars(
                                    sf_audit_action_label($log['action'], $currentUiLang),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                        </td>

                        <td class="sf-audit-target" data-label="<?= $labelTarget ?>">
                            <?php if (!empty($log['target_type'])): ?>
                                <?= htmlspecialchars($log['target_type'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($log['target_id'])): ?>
                                    <span class="target-id">
                                        <?php if (($log['target_type'] ?? '') === 'flash'): ?>
                                            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/index.php?page=view&id=<?= (int) $log['target_id'] ?>"
                                               class="sf-audit-target-link">
                                                #<?= (int) $log['target_id'] ?>
                                            </a>
                                        <?php else: ?>
                                            #<?= (int) $log['target_id'] ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                        </td>

                        <td class="sf-audit-ip" data-label="<?= $labelIp ?>">
                            <code><?= htmlspecialchars($log['ip_address'] ?? '–', ENT_QUOTES, 'UTF-8') ?></code>
                        </td>

                        <td class="sf-audit-details" data-label="<?= $labelDetails ?>">
                            <?php if ($details): ?>
                                <button
                                    type="button"
                                    class="sf-btn-details"
                                    onclick="sfShowDetails(this)"
                                    data-details="<?= htmlspecialchars(
                                        json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    aria-label="<?= htmlspecialchars(sf_term('audit_details_open', $currentUiLang) ?? 'Näytä tiedot', ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    📄
                                </button>
                            <?php else: ?>
                                <span class="sf-audit-no-details">–</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- SIVUTUS -->
<?php if ($totalPages > 1): ?>
    <?php
    $paginationParams = http_build_query(array_filter([
    'page'      => 'settings',
    'tab'       => 'logs',
    'sub'       => 'app',
    'action'    => $filterAction,
    'user'      => $filterUser,
    'date_from' => $filterDateFrom,
    'date_to'   => $filterDateTo,
    'ip'        => $filterIp,
], static function ($value) {
    return $value !== null && $value !== '';
}));
    ?>
    <div class="sf-pagination">
        <?php if ($logPage > 1): ?>
            <a
                href="?<?= $paginationParams ?>&p=<?= $logPage - 1 ?>"
                class="sf-btn sf-btn-secondary"
            >
                ← Edellinen
            </a>
        <?php endif; ?>

        <span class="sf-page-info">
            Sivu <?= $logPage ?> / <?= $totalPages ?>
        </span>

        <?php if ($logPage < $totalPages): ?>
            <a
                href="?<?= $paginationParams ?>&p=<?= $logPage + 1 ?>"
                class="sf-btn sf-btn-secondary"
            >
                Seuraava →
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- DETAILS MODAALI -->
<div class="sf-modal hidden" id="sfDetailsModal">
    <div class="sf-modal-content" style="max-width: 600px; width: 95%;">
        <h3>Lisätiedot</h3>
        <div id="sfDetailsContent" style="margin: 16px 0; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; max-height: 50vh; overflow-y: auto;"></div>
        <div class="sf-modal-actions">
            <button
                type="button"
                class="sf-btn sf-btn-secondary"
                onclick="sfCloseDetails()"
            >
                Sulje
            </button>
        </div>
    </div>
</div>

<script>
const sfAuditDict = {
    keys: {
        'timestamp': '<?= htmlspecialchars(sf_term('audit_col_time', $currentUiLang) ?? 'Aika', ENT_QUOTES, 'UTF-8') ?>',
        'action': '<?= htmlspecialchars(sf_term('audit_col_action', $currentUiLang) ?? 'Toiminto', ENT_QUOTES, 'UTF-8') ?>',
        'target_type': '<?= htmlspecialchars(sf_term('audit_target_type', $currentUiLang) ?? 'Kohteen tyyppi', ENT_QUOTES, 'UTF-8') ?>',
        'target_id': '<?= htmlspecialchars(sf_term('audit_target_id', $currentUiLang) ?? 'Kohteen ID', ENT_QUOTES, 'UTF-8') ?>',
        'ip_address': '<?= htmlspecialchars(sf_term('audit_col_ip', $currentUiLang) ?? 'IP-osoite', ENT_QUOTES, 'UTF-8') ?>',
        'request_uri': '<?= htmlspecialchars(sf_term('audit_request_uri', $currentUiLang) ?? 'Sivu (URI)', ENT_QUOTES, 'UTF-8') ?>',
        'request_method': '<?= htmlspecialchars(sf_term('audit_request_method', $currentUiLang) ?? 'Pyyntö (Method)', ENT_QUOTES, 'UTF-8') ?>',
        'custom_details.email': '<?= htmlspecialchars(sf_term('audit_email', $currentUiLang) ?? 'Sähköposti', ENT_QUOTES, 'UTF-8') ?>',
        'custom_details.user_agent': '<?= htmlspecialchars(sf_term('audit_user_agent', $currentUiLang) ?? 'Selain/laite', ENT_QUOTES, 'UTF-8') ?>',
        'email': '<?= htmlspecialchars(sf_term('audit_email', $currentUiLang) ?? 'Sähköposti', ENT_QUOTES, 'UTF-8') ?>',
        'user_agent': '<?= htmlspecialchars(sf_term('audit_user_agent', $currentUiLang) ?? 'Selain/laite', ENT_QUOTES, 'UTF-8') ?>',
        'reason': '<?= htmlspecialchars(sf_term('audit_reason', $currentUiLang) ?? 'Syy', ENT_QUOTES, 'UTF-8') ?>',
        'attempted_email': '<?= htmlspecialchars(sf_term('audit_attempted_email', $currentUiLang) ?? 'Yritetty sähköposti', ENT_QUOTES, 'UTF-8') ?>',
        'locked_until': '<?= htmlspecialchars(sf_term('audit_locked_until', $currentUiLang) ?? 'Lukittu saakka', ENT_QUOTES, 'UTF-8') ?>',
        'ip': '<?= htmlspecialchars(sf_term('audit_col_ip', $currentUiLang) ?? 'IP-osoite', ENT_QUOTES, 'UTF-8') ?>'
    },
    actions: {
        <?php foreach ($availableActions as $a): ?>
        "<?= htmlspecialchars($a['action'], ENT_QUOTES, 'UTF-8') ?>": "<?= htmlspecialchars(sf_audit_action_label($a['action'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?>",
        <?php endforeach; ?>
        "login_success": "<?= htmlspecialchars(sf_audit_action_label('login_success', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>",
        "login_failed": "<?= htmlspecialchars(sf_audit_action_label('login_failed', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
    },
    values: {
        'wrong_password': 'Väärä salasana',
        'user_not_found': 'Käyttäjää ei löytynyt',
        'inactive': 'Käyttäjätili ei ole aktiivinen',
        'csrf': 'Istunto vanhentunut (CSRF)',
        'rate_limit': 'Liikaa yrityksiä (Rate limit)'
    }
};

function sfShowDetails(btn) {
    const rawData = btn.dataset.details;
    const contentContainer = document.getElementById('sfDetailsContent');
    
    try {
        const data = JSON.parse(rawData);
        let html = '<table class="sf-table" style="width: 100%; border-collapse: collapse; font-size: 0.875rem; margin: 0; border: none;"><tbody>';
        
        function renderRows(obj, prefix = '') {
            for (const [key, value] of Object.entries(obj)) {
                const displayKeyPath = prefix ? prefix + '.' + key : key;
                const lowerPath = displayKeyPath.toLowerCase();
                
                // Käännetään avain jos se löytyy sanakirjasta, muuten muotoillaan paremmin
                let displayKeyName = sfAuditDict.keys[lowerPath] || key.replace(/_/g, ' ');
                // Ensimmäinen kirjain isoksi
                displayKeyName = displayKeyName.charAt(0).toUpperCase() + displayKeyName.slice(1);
                
                if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
                    renderRows(value, displayKeyPath);
                } else {
                    let displayVal = value;
                    if (value === null || value === '') {
                        displayVal = '<span style="color: #9ca3af; font-style: italic;">-</span>';
                    } else if (typeof value === 'boolean') {
                        displayVal = value ? 'Kyllä' : 'Ei';
                    } else if (Array.isArray(value)) {
                        displayVal = value.join(', ');
                    } else {
                        // Käännetään arvo tarvittaessa
                        if (lowerPath === 'action' || lowerPath === 'custom_details.action') {
                            displayVal = sfAuditDict.actions[value] || value;
                        } else if (lowerPath === 'reason' || lowerPath === 'custom_details.reason') {
                            displayVal = sfAuditDict.values[value] || value;
                        }
                        
                        // Estetään XSS
                        const div = document.createElement('div');
                        div.textContent = String(displayVal);
                        displayVal = div.innerHTML;
                    }
                    
                    html += `<tr>
                        <th style="padding: 10px 16px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 600; color: #4b5563; width: 35%; background: transparent; text-transform: none;">${displayKeyName}</th>
                        <td style="padding: 10px 16px; border-bottom: 1px solid #e5e7eb; color: #111827; word-break: break-word; background: transparent;">${displayVal}</td>
                    </tr>`;
                }
            }
        }
        
        renderRows(data);
        html += '</tbody></table>';
        contentContainer.innerHTML = html;
    } catch (e) {
        // Fallback
        contentContainer.innerHTML = '<pre style="white-space: pre-wrap; font-size: 0.875rem; padding: 16px; margin: 0;">' + 
            rawData.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;") + 
            '</pre>';
    }
    
    document.getElementById('sfDetailsModal').classList.remove('hidden');
}

function sfCloseDetails() {
    document.getElementById('sfDetailsModal').classList.add('hidden');
}
	function sfUpdateAuditBulkDeleteState() {
    const checkboxes = Array.from(document.querySelectorAll('.sf-audit-row-checkbox'));
    const checked = checkboxes.filter(function (checkbox) {
        return checkbox.checked;
    });

    const selectAll = document.getElementById('sfAuditSelectAll');
    const countLabel = document.getElementById('sfAuditBulkCount');
    const deleteButton = document.getElementById('sfAuditBulkDeleteBtn');

    if (selectAll) {
        selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
    }

    if (countLabel) {
        countLabel.textContent = checked.length === 0
            ? '<?= htmlspecialchars(sf_term('audit_bulk_none_selected', $currentUiLang) ?? 'Ei valittuja rivejä', ENT_QUOTES, 'UTF-8') ?>'
            : checked.length + ' <?= htmlspecialchars(sf_term('audit_bulk_selected_suffix', $currentUiLang) ?? 'riviä valittu', ENT_QUOTES, 'UTF-8') ?>';
    }

    if (deleteButton) {
        deleteButton.disabled = checked.length === 0;
    }
}

function sfConfirmAuditBulkDelete() {
    const checked = document.querySelectorAll('.sf-audit-row-checkbox:checked');

    if (checked.length === 0) {
        return false;
    }

    return window.confirm(
        '<?= htmlspecialchars(sf_term('audit_bulk_delete_confirm', $currentUiLang) ?? 'Poistetaanko valitut lokirivit?', ENT_QUOTES, 'UTF-8') ?>'
    );
}

document.addEventListener('change', function (event) {
    if (event.target && event.target.id === 'sfAuditSelectAll') {
        document.querySelectorAll('.sf-audit-row-checkbox').forEach(function (checkbox) {
            checkbox.checked = event.target.checked;
        });

        sfUpdateAuditBulkDeleteState();
        return;
    }

    if (event.target && event.target.classList.contains('sf-audit-row-checkbox')) {
        sfUpdateAuditBulkDeleteState();
    }
});

sfUpdateAuditBulkDeleteState();
</script>