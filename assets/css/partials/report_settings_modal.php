<?php
/**
 * Partial: Report Settings Modal
 *
 * Provides a settings panel for configuring flash-level options:
 *   - Original type (Alkuperäinen tyyppi)
 *   - Shortcut to the body map (Merkitse kehonosat)
 *
 * Required variables (provided by view.php):
 *   $flash            – associative array from sf_flashes
 *   $currentUiLang    – active UI language code
 *   $base             – base URL
 */

$_settingsTypeMap = [
    'red'    => 'first_release',
    'yellow' => 'dangerous_situation',
];
$_settingsCurrentOriginalType = $flash['original_type'] ?? '';
// Gracefully handle legacy 'green' value: treat it as unset since it is no longer a valid option
if ($_settingsCurrentOriginalType === 'green') {
    $_settingsCurrentOriginalType = '';
}
$_settingsIsPublished = ($flash['state'] ?? '') === 'published';
$_settingsLockOriginalType = $_settingsIsPublished && $_settingsCurrentOriginalType !== '';
$_settingsCurrentType = $flash['type'] ?? '';
$_settingsShowBodyMap = ($_settingsCurrentType !== 'yellow' && $_settingsCurrentOriginalType !== 'yellow');
?>
<div class="sf-modal hidden" id="sfReportSettingsModal" role="dialog" aria-modal="true"
     aria-labelledby="sfReportSettingsModalTitle">
    <div class="sf-modal-content sf-settings-modal-content">

        <div class="sf-settings-modal-header">
            <h2 class="sf-settings-modal-title" id="sfReportSettingsModalTitle">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"
                     fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                    <path d="M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/>
                    <path d="M4 12h2M18 12h2M12 4v2M12 18v2M7 7l1.5 1.5M15.5 15.5L17 17M7 17l1.5-1.5M15.5 8.5L17 7"/>
                </svg>
                <?= htmlspecialchars(sf_term('settings_modal_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <button type="button" class="sf-settings-modal-close" data-modal-close
                    aria-label="<?= htmlspecialchars(sf_term('cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>

        <div class="sf-settings-modal-body">

            <!-- Original type -->
            <div class="sf-settings-field">
                <label for="sfOriginalTypeSelect" class="sf-settings-label">
                    <?= htmlspecialchars(sf_term('settings_original_type_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select id="sfOriginalTypeSelect" class="sf-settings-select"
                    <?= $_settingsLockOriginalType ? 'disabled' : '' ?>>
                    <option value=""><?= htmlspecialchars(sf_term('settings_original_type_none', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($_settingsTypeMap as $_typeCode => $_termKey): ?>
                    <option value="<?= htmlspecialchars($_typeCode, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $_settingsCurrentOriginalType === $_typeCode ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term($_termKey, $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <span id="sfOriginalTypeSaveStatus" class="sf-settings-save-status" aria-live="polite"></span>
            </div>

            <!-- Body map -->
            <?php if ($_settingsShowBodyMap): ?>
            <!-- Body map button moved to Lisätiedot tab -->
            <?php endif; ?>

        </div>

        <div class="sf-settings-modal-footer">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>

    </div>
</div>