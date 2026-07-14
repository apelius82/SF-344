<?php
// assets/pages/settings/tab_system_notices.php
declare(strict_types=1);

$baseUrl = rtrim($config['base_url'] ?? '', '/');
$csrfToken = $_SESSION['csrf_token'] ?? '';

$noticeEnabled = (bool)sf_get_setting('system_notice_enabled', false);
$noticeType = (string)sf_get_setting('system_notice_type', 'info');
$noticeTitle = (string)sf_get_setting('system_notice_title', '');
$noticeMessage = (string)sf_get_setting('system_notice_message', '');

$allowedNoticeTypes = ['info', 'warning', 'danger', 'maintenance'];
if (!in_array($noticeType, $allowedNoticeTypes, true)) {
    $noticeType = 'info';
}

$noticeTypeLabels = [
    'info' => sf_term('settings_system_notice_type_info', $currentUiLang),
    'warning' => sf_term('settings_system_notice_type_warning', $currentUiLang),
    'danger' => sf_term('settings_system_notice_type_danger', $currentUiLang),
    'maintenance' => sf_term('settings_system_notice_type_maintenance', $currentUiLang),
];

$noticeTypeIcons = [
    'info' => 'i',
    'warning' => '!',
    'danger' => '!',
    'maintenance' => '⚙',
];

$previewTitle = $noticeTitle !== '' ? $noticeTitle : sf_term('settings_system_notice_title_placeholder', $currentUiLang);
$previewMessage = $noticeMessage !== '' ? $noticeMessage : sf_term('settings_system_notice_message_placeholder', $currentUiLang);
?>

<div class="sf-system-notice-admin">
    <section class="sf-system-notice-hero">
        <div class="sf-system-notice-hero-icon" aria-hidden="true">▣</div>
        <div>
            <h2><?= htmlspecialchars(sf_term('settings_system_notice_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars(sf_term('settings_system_notice_help', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </section>

    <section class="sf-system-notice-panel">
        <div class="sf-system-notice-panel-header">
            <div>
                <h3><?= htmlspecialchars(sf_term('settings_system_notice_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                <p><?= htmlspecialchars(sf_term('settings_system_notice_enabled_help', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <label class="sf-system-notice-switch">
                <input type="checkbox" id="system_notice_enabled" <?= $noticeEnabled ? 'checked' : '' ?>>
                <span class="sf-system-notice-switch-track">
                    <span class="sf-system-notice-switch-thumb"></span>
                </span>
            </label>
        </div>

        <div class="sf-system-notice-divider"></div>

        <div class="sf-system-notice-grid">
            <div class="sf-system-notice-field">
                <label for="system_notice_type">
                    <?= htmlspecialchars(sf_term('settings_system_notice_type', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>

                <div class="sf-system-notice-select-wrap">
                    <span class="sf-system-notice-select-icon" id="systemNoticeTypeIcon" aria-hidden="true">
                        <?= htmlspecialchars($noticeTypeIcons[$noticeType], ENT_QUOTES, 'UTF-8') ?>
                    </span>

                    <select id="system_notice_type" class="sf-system-notice-input sf-system-notice-select">
                        <?php foreach ($allowedNoticeTypes as $type): ?>
                            <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= $noticeType === $type ? 'selected' : '' ?>>
                                <?= htmlspecialchars($noticeTypeLabels[$type], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="sf-system-notice-field">
                <label for="system_notice_title">
                    <?= htmlspecialchars(sf_term('settings_system_notice_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>

                <input
                    type="text"
                    id="system_notice_title"
                    class="sf-system-notice-input"
                    maxlength="160"
                    value="<?= htmlspecialchars($noticeTitle, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="<?= htmlspecialchars(sf_term('settings_system_notice_title_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>
        </div>

        <div class="sf-system-notice-field">
            <div class="sf-system-notice-label-row">
                <label for="system_notice_message">
                    <?= htmlspecialchars(sf_term('settings_system_notice_message', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <span id="systemNoticeMessageCounter"><?= mb_strlen($noticeMessage) ?> / 1200</span>
            </div>

            <textarea
                id="system_notice_message"
                class="sf-system-notice-input sf-system-notice-textarea"
                rows="5"
                maxlength="1200"
                placeholder="<?= htmlspecialchars(sf_term('settings_system_notice_message_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            ><?= htmlspecialchars($noticeMessage, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="sf-system-notice-divider"></div>

        <div class="sf-system-notice-preview-section">
            <div class="sf-system-notice-preview-heading">
                <?= htmlspecialchars(sf_term('settings_system_notice_preview', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="sf-system-notice-preview-card">
                <div class="sf-system-notice-banner sf-system-notice-banner-<?= htmlspecialchars($noticeType, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="sf-system-notice-icon" id="systemNoticePreviewIcon" aria-hidden="true">
                        <?= htmlspecialchars($noticeTypeIcons[$noticeType], ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <div class="sf-system-notice-content">
                        <strong id="systemNoticePreviewTitle">
                            <?= htmlspecialchars($previewTitle, ENT_QUOTES, 'UTF-8') ?>
                        </strong>
                        <p id="systemNoticePreviewMessage">
                            <?= htmlspecialchars($previewMessage, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>

                    <button type="button" class="sf-system-notice-close" aria-label="<?= htmlspecialchars(sf_term('system_notice_close', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                        ×
                    </button>
                </div>
            </div>
        </div>

        <div class="sf-system-notice-actions">
            <button type="button" id="saveSystemNotice" class="sf-system-notice-save-btn">
                <span aria-hidden="true">▣</span>
                <?= htmlspecialchars(sf_term('btn_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </section>
</div>

<script>
(function() {
    'use strict';

    const baseUrl = window.SF_BASE_URL || '<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>';
    const csrfToken = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';

    const saveBtn = document.getElementById('saveSystemNotice');
    const enabledInput = document.getElementById('system_notice_enabled');
    const typeInput = document.getElementById('system_notice_type');
    const titleInput = document.getElementById('system_notice_title');
    const messageInput = document.getElementById('system_notice_message');
    const previewTitle = document.getElementById('systemNoticePreviewTitle');
    const previewMessage = document.getElementById('systemNoticePreviewMessage');
    const previewBanner = document.querySelector('.sf-system-notice-preview-card .sf-system-notice-banner');
    const previewIcon = document.getElementById('systemNoticePreviewIcon');
    const typeIcon = document.getElementById('systemNoticeTypeIcon');
    const counter = document.getElementById('systemNoticeMessageCounter');

    const titlePlaceholder = <?= json_encode(sf_term('settings_system_notice_title_placeholder', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
    const messagePlaceholder = <?= json_encode(sf_term('settings_system_notice_message_placeholder', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;

    const typeIcons = {
        info: 'i',
        warning: '!',
        danger: '!',
        maintenance: '⚙'
    };

    function updatePreview() {
        const selectedType = typeInput.value;
        const selectedIcon = typeIcons[selectedType] || 'i';

        if (previewTitle) {
            previewTitle.textContent = titleInput.value.trim() || titlePlaceholder;
        }

        if (previewMessage) {
            previewMessage.textContent = messageInput.value.trim() || messagePlaceholder;
        }

        if (previewBanner) {
            previewBanner.className = 'sf-system-notice-banner sf-system-notice-banner-' + selectedType;
        }

        if (previewIcon) {
            previewIcon.textContent = selectedIcon;
        }

        if (typeIcon) {
            typeIcon.textContent = selectedIcon;
        }

        if (counter) {
            counter.textContent = String(messageInput.value.length) + ' / 1200';
        }
    }

    [typeInput, titleInput, messageInput].forEach(function(input) {
        if (input) {
            input.addEventListener('input', updatePreview);
            input.addEventListener('change', updatePreview);
        }
    });

    if (saveBtn) {
        saveBtn.addEventListener('click', async function() {
            const originalContent = saveBtn.innerHTML;

            saveBtn.disabled = true;
            saveBtn.textContent = <?= json_encode(sf_term('saving', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;

            try {
                const response = await fetch(baseUrl + '/app/api/save_system_notice.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        enabled: enabledInput.checked,
                        type: typeInput.value,
                        title: titleInput.value,
                        message: messageInput.value
                    })
                });

                const data = await response.json().catch(function() {
                    return {};
                });

                if (!response.ok || !data.success) {
                    throw new Error(data.error || <?= json_encode(sf_term('save_error', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>);
                }

                saveBtn.textContent = <?= json_encode(sf_term('saved', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;

                if (window.sfToast) {
                    window.sfToast('success', <?= json_encode(sf_term('saved', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>);
                }

                setTimeout(function() {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalContent;
                }, 1600);
            } catch (error) {
                alert(error.message || <?= json_encode(sf_term('save_error', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>);
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalContent;
            }
        });
    }

    updatePreview();
})();
</script>