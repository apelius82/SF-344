<?php
// assets/pages/settings/tab_email_logs.php
declare(strict_types=1);

// Muuttujat tulevat settings.php:stä: $mysqli, $baseUrl, $currentUiLang

// Only admins and safety team can view email logs
if (!sf_is_admin_or_safety()) {
    echo '<p>Ei käyttöoikeutta.</p>';
    return;
}
?>

<h2>
    <img src="<?= $baseUrl ?>/assets/img/icons/calendar.svg" alt="" class="sf-heading-icon" aria-hidden="true">
    <?= htmlspecialchars(sf_term('email_log_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
</h2>

<!-- Email Log Filters -->
<div class="sf-email-logs-filters">
    <div class="sf-field">
        <label for="sfEmailLogFilterStatus"><?= htmlspecialchars(sf_term('email_log_filter_status', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
        <select id="sfEmailLogFilterStatus" class="sf-select">
            <option value=""><?= htmlspecialchars(sf_term('users_filter_login_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
            <option value="sent"><?= htmlspecialchars(sf_term('email_status_sent', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
            <option value="failed"><?= htmlspecialchars(sf_term('email_status_failed', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
            <option value="skipped"><?= htmlspecialchars(sf_term('email_status_skipped', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
        </select>
    </div>

    <div class="sf-field">
        <label for="sfEmailLogFilterRecipient"><?= htmlspecialchars(sf_term('email_log_filter_recipient', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="sfEmailLogFilterRecipient" class="sf-input" placeholder="<?= htmlspecialchars(sf_term('email_log_filter_recipient', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="sf-field">
        <label for="sfEmailLogFilterDateFrom"><?= htmlspecialchars(sf_term('email_log_filter_date_from', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="date" id="sfEmailLogFilterDateFrom" class="sf-input">
    </div>

    <div class="sf-field">
        <label for="sfEmailLogFilterDateTo"><?= htmlspecialchars(sf_term('email_log_filter_date_to', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="date" id="sfEmailLogFilterDateTo" class="sf-input">
    </div>

    <div class="sf-field" style="display: flex; align-items: flex-end; gap: 0.5rem;">
        <button type="button" class="sf-btn sf-btn-primary" id="sfEmailLogApplyFilters">
            <?= htmlspecialchars(sf_term('email_log_apply_filters', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button type="button" class="sf-btn sf-btn-secondary" id="sfEmailLogClearFilters">
            <?= htmlspecialchars(sf_term('email_log_clear_filters', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</div>

<!-- Email Logs Table -->
<div id="sfEmailLogsContainer">
    <table class="sf-email-logs-table">
        <thead>
            <tr>
                <th><?= htmlspecialchars(sf_term('email_log_time', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(sf_term('email_log_recipient', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(sf_term('email_log_subject', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(sf_term('email_log_status', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(sf_term('email_log_flash_id', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(sf_term('email_log_error', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody id="sfEmailLogTableBody">
            <tr>
                <td colspan="7" style="text-align: center; padding: 2rem;">
                    <?= htmlspecialchars(sf_term('users_loading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<div id="sfEmailLogPagination" class="sf-pagination" style="margin-top: 1.5rem;"></div>

<script>
(function() {
    const base = '<?= $baseUrl ?>';
    let currentPage = 1;
    
    // Load email logs
    function loadEmailLogs(page = 1) {
        const status = document.getElementById('sfEmailLogFilterStatus').value;
        const recipient = document.getElementById('sfEmailLogFilterRecipient').value;
        const dateFrom = document.getElementById('sfEmailLogFilterDateFrom').value;
        const dateTo = document.getElementById('sfEmailLogFilterDateTo').value;
        
        const params = new URLSearchParams({
            page: page.toString()
        });
        
        if (status) params.append('status', status);
        if (recipient) params.append('recipient', recipient);
        if (dateFrom) params.append('date_from', dateFrom);
        if (dateTo) params.append('date_to', dateTo);
        
        fetch(base + '/app/api/get_email_logs.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    alert(data.error || 'Error loading logs');
                    return;
                }
                
                renderEmailLogs(data.logs);
                renderPagination(data.page, data.totalPages);
                currentPage = data.page;
            })
            .catch(err => {
                console.error('Error loading email logs:', err);
                alert('Network error');
            });
    }
    
    // Render email logs in table
    function renderEmailLogs(logs) {
        const tbody = document.getElementById('sfEmailLogTableBody');
        
        // Get labels from table headers for data-label attributes
        const timeLabel = '<?= htmlspecialchars(sf_term('email_log_time', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
        const recipientLabel = '<?= htmlspecialchars(sf_term('email_log_recipient', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
        const subjectLabel = '<?= htmlspecialchars(sf_term('email_log_subject', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
        const statusLabel = '<?= htmlspecialchars(sf_term('email_log_status', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
        const flashIdLabel = '<?= htmlspecialchars(sf_term('email_log_flash_id', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
        const errorLabel = '<?= htmlspecialchars(sf_term('email_log_error', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
       const actionsLabel = '';
        
        if (logs.length === 0) {
            tbody.innerHTML = `<tr>
                <td colspan="7">
                    <div class="sf-email-logs-empty">
                        <div class="sf-email-logs-empty-icon">📧</div>
                        <div><?= htmlspecialchars(sf_term('email_log_no_results', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </td>
            </tr>`;
            return;
        }
        
        const statusLabels = {
            'sent': '<?= htmlspecialchars(sf_term('email_status_sent', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>',
            'failed': '<?= htmlspecialchars(sf_term('email_status_failed', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>',
            'skipped': '<?= htmlspecialchars(sf_term('email_status_skipped', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>'
        };
        
        const resendButtonText = '<?= htmlspecialchars(sf_term('email_resend_button', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
        
        tbody.innerHTML = logs.map(log => {
            const sentAt = new Date(log.sent_at).toLocaleString('<?= $currentUiLang ?>');
            const statusLabelText = statusLabels[log.status] || log.status;
            const flashLink = log.flash_id 
                ? `<a href="${base}/index.php?page=view&id=${log.flash_id}" class="sf-email-flash-link" target="_blank">${log.flash_id}</a>`
                : '–';
            const errorMsg = log.error_message || log.skip_reason || '–';
            
            // Show resend button only for sent emails with flash_id
            const resendButton = (log.status === 'sent' && log.flash_id)
                ? `<button type="button" class="sf-btn-small sf-btn-primary sf-email-resend-btn" 
                          data-flash-id="${log.flash_id}" 
                          data-log-id="${log.id}"
                          title="${resendButtonText}">
                       ${resendButtonText}
                   </button>`
                : '–';
            
            return `
                <tr>
                    <td data-label="${timeLabel}">${escapeHtml(sentAt)}</td>
                    <td data-label="${recipientLabel}">${escapeHtml(log.recipient_email)}</td>
                    <td data-label="${subjectLabel}">${escapeHtml(log.subject)}</td>
                    <td data-label="${statusLabel}"><span class="sf-email-status ${log.status}">${statusLabelText}</span></td>
                    <td data-label="${flashIdLabel}">${flashLink}</td>
                    <td data-label="${errorLabel}"><span class="sf-email-error-details" title="${escapeHtml(errorMsg)}">${escapeHtml(errorMsg)}</span></td>
                    <td data-label="${actionsLabel}">${resendButton}</td>
                </tr>
            `;
        }).join('');
    }
    
    // Render pagination
    function renderPagination(page, totalPages) {
        const container = document.getElementById('sfEmailLogPagination');
        
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let html = '';
        
        if (page > 1) {
            html += `<button class="sf-btn sf-btn-secondary sf-email-log-page" data-page="1">«</button>`;
            html += `<button class="sf-btn sf-btn-secondary sf-email-log-page" data-page="${page - 1}">‹</button>`;
        }
        
        html += `<span class="sf-page-info">Page ${page} / ${totalPages}</span>`;
        
        if (page < totalPages) {
            html += `<button class="sf-btn sf-btn-secondary sf-email-log-page" data-page="${page + 1}">›</button>`;
            html += `<button class="sf-btn sf-btn-secondary sf-email-log-page" data-page="${totalPages}">»</button>`;
        }
        
        container.innerHTML = html;
    }
    
    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Event listeners
    document.getElementById('sfEmailLogApplyFilters')?.addEventListener('click', () => {
        loadEmailLogs(1);
    });
    
    document.getElementById('sfEmailLogClearFilters')?.addEventListener('click', () => {
        document.getElementById('sfEmailLogFilterStatus').value = '';
        document.getElementById('sfEmailLogFilterRecipient').value = '';
        document.getElementById('sfEmailLogFilterDateFrom').value = '';
        document.getElementById('sfEmailLogFilterDateTo').value = '';
        loadEmailLogs(1);
    });
    
    document.addEventListener('click', (e) => {
        if (e.target.classList.contains('sf-email-log-page')) {
            const page = parseInt(e.target.dataset.page);
            loadEmailLogs(page);
        }
        
        // Handle resend button clicks
        if (e.target.closest('.sf-email-resend-btn')) {
            const btn = e.target.closest('.sf-email-resend-btn');
            const flashId = btn.dataset.flashId;
            const logId = btn.dataset.logId;
            
            const confirmMsg = '<?= htmlspecialchars(sf_term('email_resend_confirm', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Get CSRF token - this codebase uses input[name="csrf_token"] as primary method
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || 
                             document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            
            if (!csrfToken) {
                alert('Security token not found. Please refresh the page.');
                return;
            }
            
            // Disable button during request
            btn.disabled = true;
            const originalText = btn.textContent;
            btn.textContent = '<?= htmlspecialchars(sf_term('users_loading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
            
            const formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('csrf_token', csrfToken);
            
            fetch(base + '/app/api/resend_email.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    const successMsg = '<?= htmlspecialchars(sf_term('email_resend_success', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>'
                        .replace('{count}', data.count || 0);
                    alert(successMsg);
                    // Reload logs to see new entries
                    loadEmailLogs(currentPage);
                } else {
                    const errorMsg = data.error || '<?= htmlspecialchars(sf_term('email_resend_error', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>';
                    alert(errorMsg);
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
            })
            .catch(err => {
                console.error('Resend error:', err);
                alert('<?= htmlspecialchars(sf_term('email_resend_error', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>');
                btn.disabled = false;
                btn.textContent = originalText;
            });
        }
    });
    
    // Load initial logs
    loadEmailLogs(1);
})();
</script>