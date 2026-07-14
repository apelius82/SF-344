<?php
$publishFlash = $publishFlash ?? $flash;
$publishModalId = $publishModalId ?? 'modalPublish';
$publishFormId = $publishFormId ?? 'publishForm';
$publishIsLanguageVersion = $publishIsLanguageVersion ?? false;

$publishFlashId = (int)($publishFlash['id'] ?? $id ?? 0);
$publishFlashLang = (string)($publishFlash['lang'] ?? 'fi');
$publishFlashType = (string)($publishFlash['type'] ?? 'yellow');
$publishFlashTitle = (string)($publishFlash['title'] ?? '');
$publishFlashSite = (string)($publishFlash['worksite'] ?? $publishFlash['site'] ?? '');
$publishWorksiteNotificationDefault = (int)($publishFlash['send_worksite_notification_preselected'] ?? 1) === 1;

$publishTitle = $publishIsLanguageVersion
    ? (sf_term('publish_language_version', $currentUiLang) ?? 'Julkaise kieliversio')
    : (sf_term('modal_publish_title', $currentUiLang) ?? 'Julkaise SafetyFlash');

$publishButtonText = $publishIsLanguageVersion
    ? (sf_term('btn_publish_language_version', $currentUiLang) ?? 'Julkaise kieliversio')
    : (sf_term('btn_publish', $currentUiLang) ?? 'Julkaise');

$distributionCountries = [
    'fi' => ['label_key' => 'country_finland', 'icon' => 'finnish-flag.png'],
    'sv' => ['label_key' => 'country_sweden', 'icon' => 'swedish-flag.png'],
    'en' => ['label_key' => 'country_uk', 'icon' => 'english-flag.png'],
    'it' => ['label_key' => 'country_italy', 'icon' => 'italian-flag.png'],
    'el' => ['label_key' => 'country_greece', 'icon' => 'greece-flag.png'],
];
?>

<div
    class="sf-modal hidden sf-publish-stepper-modal"
    data-bottom-sheet="true"
    id="<?= htmlspecialchars($publishModalId, ENT_QUOTES, 'UTF-8') ?>"
    data-flash-id="<?= (int)$publishFlashId ?>"
    data-flash-lang="<?= htmlspecialchars($publishFlashLang, ENT_QUOTES, 'UTF-8') ?>"
    data-flash-type="<?= htmlspecialchars($publishFlashType, ENT_QUOTES, 'UTF-8') ?>"
    data-flash-title="<?= htmlspecialchars($publishFlashTitle, ENT_QUOTES, 'UTF-8') ?>"
    data-flash-site="<?= htmlspecialchars($publishFlashSite, ENT_QUOTES, 'UTF-8') ?>"
    data-current-step="1"
    role="dialog"
    aria-modal="true"
>
    <div class="sf-modal-content sf-modal-publish-stepper">

        <div class="sf-step-indicator sf-publish-step-indicator">
            <span class="sf-step active" data-publish-step-dot="1">1</span>
            <span class="sf-step-line" data-publish-step-line="1"></span>
            <span class="sf-step" data-publish-step-dot="2">2</span>
            <span class="sf-step-line" data-publish-step-line="2"></span>
            <span class="sf-step" data-publish-step-dot="3">3</span>
            <span class="sf-step-line" data-publish-step-line="3"></span>
            <span class="sf-step" data-publish-step-dot="4">4</span>
        </div>

        <form
            id="<?= htmlspecialchars($publishFormId, ENT_QUOTES, 'UTF-8') ?>"
            method="POST"
            action="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/app/actions/publish.php?id=<?= (int)$publishFlashId ?>"
        >
            <?= sf_csrf_field() ?>
            <input type="hidden" name="publish_mode" value="single">

            <div class="sf-publish-step" data-publish-step="1">
                <div class="sf-modal-header">
                    <h2>
                        <?= sf_lang_flag($publishFlashLang) ?>
                        <?= htmlspecialchars($publishTitle, ENT_QUOTES, 'UTF-8') ?>
                        — <?= htmlspecialchars(strtoupper($publishFlashLang), ENT_QUOTES, 'UTF-8') ?>
                        <small class="sf-publish-step-label">
                            <?= htmlspecialchars(sf_term('publish_step1_title', $currentUiLang) ?? 'Perustiedot', ENT_QUOTES, 'UTF-8') ?>
                        </small>
                    </h2>

                    <button
                        type="button"
                        class="sf-modal-close-btn"
                        data-modal-close="<?= htmlspecialchars($publishModalId, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        &times;
                    </button>
                </div>

                <div class="sf-modal-body">
                    <p><?= htmlspecialchars(sf_term('modal_publish_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>

                    <div class="sf-publish-options">
                        <label class="sf-checkbox-option">
                            <input type="checkbox" name="send_to_distribution" value="1" data-publish-distribution>
                            <span class="sf-checkbox-label">
                                <strong><?= htmlspecialchars(sf_term('publish_send_to_distribution', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></strong>
                                <small><?= htmlspecialchars(sf_term('publish_send_to_distribution_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                            </span>
                        </label>

                        <div class="sf-country-selection" data-publish-country-selection style="display:none;">
                            <label class="sf-label">
                                <?= htmlspecialchars(sf_term('publish_select_countries', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            </label>

                            <div class="sf-country-flags">
                                <?php foreach ($distributionCountries as $countryCode => $countryData): ?>
                                    <label class="sf-flag-chip">
                                        <input
                                            type="checkbox"
                                            name="distribution_countries[]"
                                            value="<?= htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') ?>"
                                            <?= $countryCode === $publishFlashLang ? 'checked' : '' ?>
                                        >
                                        <img
                                            src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/<?= htmlspecialchars($countryData['icon'], ENT_QUOTES, 'UTF-8') ?>"
                                            alt="<?= htmlspecialchars(sf_term($countryData['label_key'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($publishFlashType === 'red'): ?>
                            <label class="sf-checkbox-option sf-checkbox-warning">
                                <input
                                    type="checkbox"
                                    name="has_personal_injury"
                                    value="1"
                                    data-publish-injury
                                    <?= !empty($publishFlash['has_personal_injury']) ? 'checked' : '' ?>
                                >
                                <span class="sf-checkbox-label">
                                    <strong>⚠️ <?= htmlspecialchars(sf_term('publish_personal_injury', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <small><?= htmlspecialchars(sf_term('publish_personal_injury_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                                </span>
                            </label>
                        <?php endif; ?>

                        <div class="sf-email-subject-preview" data-publish-subject-preview style="display:none;">
                            <strong><?= htmlspecialchars(sf_term('publish_subject_preview', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
                            <code data-publish-subject-text></code>
                        </div>
                    </div>
                </div>

                <div class="sf-modal-footer">
                    <button
                        type="button"
                        class="sf-btn sf-btn-secondary"
                        data-modal-close="<?= htmlspecialchars($publishModalId, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <button type="button" class="sf-btn sf-btn-primary" data-publish-next>
                        <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                    </button>
                </div>
            </div>

            <div class="sf-publish-step hidden" data-publish-step="2">
                <div class="sf-modal-header sf-publish-display-header">
                    <h2>
                        <?= htmlspecialchars(sf_term('publish_step2_title', $currentUiLang) ?? 'Työmaan infonäytöt', ENT_QUOTES, 'UTF-8') ?>
                    </h2>

                    <button type="button" class="sf-publish-clear-targets-btn" data-publish-clear-targets>
                        <?= htmlspecialchars(sf_term('btn_clear_all', $currentUiLang) ?? 'Tyhjennä kaikki', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>

                <div class="sf-modal-body">
                    <p class="sf-publish-step-help">
                        <?= htmlspecialchars(sf_term('publish_display_targets_help', $currentUiLang) ?? 'Valitse infonäytöt, joille tämä kieliversio julkaistaan. Lista rajataan automaattisesti työmaan maa- ja kieliasetusten perusteella.', ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <?php
                    $originalFlashForPublishModal = $flash;
                    $originalContextForPublishModal = $context ?? null;

                    $flash = $publishFlash;
                    $context = 'publish';

                    unset($preselectedIds);
                    require __DIR__ . '/display_target_selector.php';
                    unset($preselectedIds);

                    $flash = $originalFlashForPublishModal;

                    if ($originalContextForPublishModal !== null) {
                        $context = $originalContextForPublishModal;
                    } else {
                        unset($context);
                    }
                    ?>
                </div>

                <div class="sf-modal-footer">
                    <button type="button" class="sf-btn sf-btn-secondary" data-publish-back>
                        ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <button type="button" class="sf-btn sf-btn-primary" data-publish-next>
                        <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                    </button>
                </div>
            </div>

            <div class="sf-publish-step hidden" data-publish-step="3">
                <div class="sf-modal-header">
                    <h2>
                        <?= htmlspecialchars(sf_term('publish_step3_title', $currentUiLang) ?? 'Aika-asetukset', ENT_QUOTES, 'UTF-8') ?>
                    </h2>
                </div>

                <div class="sf-modal-body">
                    <div class="sf-dt-compact-row">
                        <div class="sf-dt-section">
                            <?php require __DIR__ . '/publish_display_ttl.php'; ?>
                        </div>

                        <div class="sf-dt-section">
                            <?php require __DIR__ . '/publish_display_duration.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="sf-modal-footer">
                    <button type="button" class="sf-btn sf-btn-secondary" data-publish-back>
                        ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <button type="button" class="sf-btn sf-btn-primary" data-publish-next>
                        <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                    </button>
                </div>
            </div>

            <div class="sf-publish-step hidden" data-publish-step="4">
                <div class="sf-modal-header">
                    <h2>
                        <?= htmlspecialchars(sf_term('publish_step4_title', $currentUiLang) ?? 'Vahvista julkaisu', ENT_QUOTES, 'UTF-8') ?>
                    </h2>
                </div>

                <div class="sf-modal-body">
                    <div class="sf-publish-final-notification">
                        <div class="sf-publish-final-notification-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                <polyline points="22,6 12,13 2,6"></polyline>
                            </svg>
                        </div>

                        <div class="sf-publish-final-notification-text">
                            <strong>
                                <?= htmlspecialchars(sf_term('publish_worksite_notification', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                            <small>
                                <?= htmlspecialchars(sf_term('publish_worksite_notification_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            </small>
                            <small data-worksite-notification-count></small>
                        </div>

                        <label class="sf-publish-final-switch" aria-label="<?= htmlspecialchars(sf_term('publish_worksite_notification', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="send_worksite_notification" value="0">
                            <input
                                type="checkbox"
                                name="send_worksite_notification"
                                value="1"
                                <?= $publishWorksiteNotificationDefault ? 'checked' : '' ?>
                                data-worksite-notification-toggle
                            >
                            <span></span>
                        </label>
                    </div>

                    <div class="sf-publish-summary">
                        <dl class="sf-summary-list">
                            <dt><?= htmlspecialchars(sf_term('publish_summary_distribution', $currentUiLang) ?? 'Jakeluryhmä', ENT_QUOTES, 'UTF-8') ?></dt>
                            <dd data-summary-distribution>—</dd>

                            <dt><?= htmlspecialchars(sf_term('publish_summary_displays', $currentUiLang) ?? 'Infonäytöt', ENT_QUOTES, 'UTF-8') ?></dt>
                            <dd data-summary-displays>—</dd>

                            <dt><?= htmlspecialchars(sf_term('publish_summary_worksite_notification', $currentUiLang) ?? 'Ilmoitus työmaille', ENT_QUOTES, 'UTF-8') ?></dt>
                            <dd data-summary-worksite-notification>—</dd>

                            <dt><?= htmlspecialchars(sf_term('publish_summary_ttl', $currentUiLang) ?? 'Näkyvyysaika', ENT_QUOTES, 'UTF-8') ?></dt>
                            <dd data-summary-ttl>—</dd>

                            <dt><?= htmlspecialchars(sf_term('publish_summary_duration', $currentUiLang) ?? 'Näyttökesto', ENT_QUOTES, 'UTF-8') ?></dt>
                            <dd data-summary-duration>—</dd>
                        </dl>
                    </div>
                </div>

                <div class="sf-modal-footer">
                    <button type="button" class="sf-btn sf-btn-secondary" data-publish-back>
                        ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <button type="submit" class="sf-btn sf-btn-primary">
                        <?= sf_lang_flag($publishFlashLang) ?>
                        <?= htmlspecialchars($publishButtonText, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    if (!window.SFPublishStepper) {
        window.SFPublishStepper = {
            init: function (modal) {
                if (!modal || modal.dataset.publishStepperInitialized === '1') {
                    return;
                }

                modal.dataset.publishStepperInitialized = '1';

                var base = <?= json_encode($base) ?>;
                var csrfToken = <?= json_encode(sf_csrf_token()) ?>;

                function setStep(step) {
                    modal.setAttribute('data-current-step', String(step));

                    modal.querySelectorAll('[data-publish-step]').forEach(function (panel) {
                        panel.classList.toggle('hidden', panel.getAttribute('data-publish-step') !== String(step));
                    });

                    modal.querySelectorAll('[data-publish-step-dot]').forEach(function (dot) {
                        var dotStep = parseInt(dot.getAttribute('data-publish-step-dot'), 10);
                        dot.classList.toggle('active', dotStep === step);
                        dot.classList.toggle('done', dotStep < step);
                    });

                    modal.querySelectorAll('[data-publish-step-line]').forEach(function (line) {
                        var lineStep = parseInt(line.getAttribute('data-publish-step-line'), 10);
                        line.classList.toggle('done', lineStep < step);
                    });

                    if (step === 4) {
                        updateSummary();
                    }
                }

                function setTab(tabName) {
                    modal.querySelectorAll('[data-publish-tab]').forEach(function (tab) {
                        var active = tab.getAttribute('data-publish-tab') === tabName;
                        tab.classList.toggle('sf-dt-tab-active', active);
                        tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    });

                    modal.querySelectorAll('[data-publish-tab-panel]').forEach(function (panel) {
                        panel.classList.toggle(
                            'sf-dt-tab-panel-active',
                            panel.getAttribute('data-publish-tab-panel') === tabName
                        );
                    });
                }

                function formatCountText(count) {
                    var terms = window.SF_TERMS || {};
                    if (count === 0) {
                        return terms.publish_worksite_recipients_none || 'Ei vastaanottajia';
                    }

                    return (terms.publish_worksite_recipients_count || 'Ilmoitus lähetetään %d henkilölle').replace('%d', count);
                }

                function updateSubjectPreview() {
                    var distributionCheckbox = modal.querySelector('[data-publish-distribution]');
                    var injuryCheckbox = modal.querySelector('[data-publish-injury]');
                    var preview = modal.querySelector('[data-publish-subject-preview]');
                    var subjectText = modal.querySelector('[data-publish-subject-text]');
                    var countrySelection = modal.querySelector('[data-publish-country-selection]');

                    if (!distributionCheckbox || !preview || !subjectText) {
                        return;
                    }

                    var showPreview = distributionCheckbox.checked;

                    preview.style.display = showPreview ? 'block' : 'none';

                    if (countrySelection) {
                        countrySelection.style.display = showPreview ? 'block' : 'none';
                    }

                    if (!showPreview) {
                        return;
                    }

                    var flashType = modal.getAttribute('data-flash-type') || 'yellow';
                    var flashTitle = modal.getAttribute('data-flash-title') || '';
                    var flashSite = modal.getAttribute('data-flash-site') || '';

                    var typeLabels = {
                        red: '🔴 <?= addslashes(sf_term('email_type_red', $currentUiLang)) ?>',
                        yellow: '🟡 <?= addslashes(sf_term('email_type_yellow', $currentUiLang)) ?>',
                        green: '🟢 <?= addslashes(sf_term('email_type_green', $currentUiLang)) ?>'
                    };

                    var parts = [];

                    if (injuryCheckbox && injuryCheckbox.checked && flashType === 'red') {
                        parts.push('⚠️ <?= addslashes(sf_term('email_personal_injury_warning', $currentUiLang)) ?>');
                    }

                    parts.push(typeLabels[flashType] || typeLabels.yellow);

                    if (flashTitle) {
                        parts.push(flashTitle);
                    }

                    if (flashSite) {
                        parts.push('(' + flashSite + ')');
                    }

                    subjectText.textContent = parts.join(' - ');
                }

                function getSelectedDisplayKeyIds() {
                    var ids = [];

                    modal.querySelectorAll('input.dt-display-chip-cb:checked, input.sf-display-chip-input:checked').forEach(function (input) {
                        var id = parseInt(input.value, 10);
                        if (id > 0) {
                            ids.push(id);
                        }
                    });

                    return ids;
                }

                function updateWorksiteNotificationCount() {
                    var toggle = modal.querySelector('[data-worksite-notification-toggle]');
                    var countEl = modal.querySelector('[data-worksite-notification-count]');
                    var flashId = parseInt(modal.getAttribute('data-flash-id'), 10);

                    if (!toggle || !countEl || !flashId) {
                        return;
                    }

                    if (!toggle.checked) {
                        countEl.textContent = '';
                        modal.setAttribute('data-worksite-recipient-count', '');
                        updateSummary();
                        return;
                    }

                    var ids = getSelectedDisplayKeyIds();

                    if (ids.length === 0) {
                        countEl.textContent = formatCountText(0);
                        modal.setAttribute('data-worksite-recipient-count', '0');
                        updateSummary();
                        return;
                    }

                    countEl.textContent = (window.SF_TERMS && window.SF_TERMS.publish_worksite_recipients_loading) || 'Lasketaan...';

                    var body = new URLSearchParams();
                    body.append('flash_id', String(flashId));
                    body.append('csrf_token', csrfToken);

                    ids.forEach(function (id) {
                        body.append('display_key_ids[]', String(id));
                    });

                    fetch(base + '/app/api/worksite_notification_count.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: body.toString()
                    })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (data) {
                        if (data && data.ok) {
                            countEl.textContent = formatCountText(data.count);
                            modal.setAttribute('data-worksite-recipient-count', String(data.count));
                        } else {
                            countEl.textContent = '';
                            modal.setAttribute('data-worksite-recipient-count', '');
                        }

                        updateSummary();
                    })
                    .catch(function () {
                        countEl.textContent = '';
                        modal.setAttribute('data-worksite-recipient-count', '');
                        updateSummary();
                    });
                }

                function updateSummary() {
                    var distribution = modal.querySelector('[data-publish-distribution]');
                    var worksiteToggle = modal.querySelector('[data-worksite-notification-toggle]');

                    var summaryDistribution = modal.querySelector('[data-summary-distribution]');
                    var summaryDisplays = modal.querySelector('[data-summary-displays]');
                    var summaryWorksiteNotification = modal.querySelector('[data-summary-worksite-notification]');
                    var summaryTtl = modal.querySelector('[data-summary-ttl]');
                    var summaryDuration = modal.querySelector('[data-summary-duration]');

                    if (summaryDistribution) {
                        summaryDistribution.textContent = distribution && distribution.checked
                            ? ((window.SF_TERMS && window.SF_TERMS.publish_yes) || '✅ Kyllä')
                            : '—';
                    }

                    if (summaryDisplays) {
                        var labels = [];

                        modal.querySelectorAll('input.dt-display-chip-cb:checked, input.sf-display-chip-input:checked').forEach(function (input) {
                            var label = input.getAttribute('data-label');
                            var parent = input.closest('.sf-dt-result-item') || input.closest('.sf-display-chip');

                            if (!label && parent) {
                                label = parent.textContent.trim();
                            }

                            if (label) {
                                labels.push(label);
                            }
                        });

                        summaryDisplays.textContent = labels.length > 0 ? labels.join(', ') : '—';
                    }

                    if (summaryWorksiteNotification) {
                        var count = modal.getAttribute('data-worksite-recipient-count');

                        summaryWorksiteNotification.textContent =
                            worksiteToggle && worksiteToggle.checked && count !== ''
                                ? formatCountText(parseInt(count, 10))
                                : '—';
                    }

                    if (summaryTtl) {
                        var ttlInput = modal.querySelector('input[name="display_ttl_days"]:checked');
                        var ttlLabel = ttlInput ? ttlInput.closest('label') : null;
                        summaryTtl.textContent = ttlLabel ? ttlLabel.textContent.trim() : '—';
                    }

                    if (summaryDuration) {
                        var durationInput = modal.querySelector('input[name="display_duration_seconds"]:checked');
                        var durationLabel = durationInput ? durationInput.closest('label') : null;
                        summaryDuration.textContent = durationLabel ? durationLabel.textContent.trim() : '—';
                    }
                }

                function clearDisplayTargets() {
                    modal.querySelectorAll('input.dt-display-chip-cb, input.sf-display-chip-input').forEach(function (input) {
                        input.checked = false;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    });

                    modal.querySelectorAll('.sf-dt-selected-chip, .sf-display-selected-chip, .sf-selection-tag, .sf-sel-tag').forEach(function (chip) {
                        chip.remove();
                    });

                    updateWorksiteNotificationCount();
                    updateSummary();
                }

                modal.querySelectorAll('[data-publish-next]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var currentStep = parseInt(modal.getAttribute('data-current-step') || '1', 10);
                        setStep(Math.min(currentStep + 1, 4));
                    });
                });

                modal.querySelectorAll('[data-publish-back]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var currentStep = parseInt(modal.getAttribute('data-current-step') || '1', 10);
                        setStep(Math.max(currentStep - 1, 1));
                    });
                });

                modal.querySelectorAll('[data-publish-tab]').forEach(function (tab) {
                    tab.addEventListener('click', function () {
                        setTab(tab.getAttribute('data-publish-tab') || 'targets');
                    });
                });

                modal.querySelectorAll('[data-publish-clear-targets]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        clearDisplayTargets();
                    });
                });

                modal.addEventListener('change', function (event) {
                    if (
                        event.target.matches('[data-publish-distribution]') ||
                        event.target.matches('[data-publish-injury]')
                    ) {
                        updateSubjectPreview();
                        updateSummary();
                    }

                    if (event.target.matches('[data-worksite-notification-toggle]')) {
                        updateWorksiteNotificationCount();
                    }

                    if (
                        event.target.classList.contains('dt-display-chip-cb') ||
                        event.target.classList.contains('sf-display-chip-input')
                    ) {
                        clearTimeout(modal._sfWorksiteNotificationTimer);
                        modal._sfWorksiteNotificationTimer = setTimeout(function () {
                            updateWorksiteNotificationCount();
                            updateSummary();
                        }, 350);
                    }
                });

                setStep(1);
                setTab('targets');
                updateSubjectPreview();
                updateWorksiteNotificationCount();
            },

            open: function (modalId) {
                var modal = document.getElementById(modalId);

                if (!modal) {
                    return;
                }

                window.SFPublishStepper.init(modal);
                modal.classList.remove('hidden');
                document.body.classList.add('sf-modal-open');
            },

            close: function (modalId) {
                var modal = document.getElementById(modalId);

                if (!modal) {
                    return;
                }

                modal.classList.add('hidden');

                if (!document.querySelector('.sf-modal:not(.hidden)')) {
                    document.body.classList.remove('sf-modal-open');
                }
            }
        };
    }

    window.SFPublishStepper.init(document.getElementById(<?= json_encode($publishModalId) ?>));
})();
</script>