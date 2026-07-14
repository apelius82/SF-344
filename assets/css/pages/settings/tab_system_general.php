<?php
// assets/pages/settings/tab_system_general.php
declare(strict_types=1);

$baseUrl    = rtrim($config['base_url'] ?? '', '/');
$csrfToken  = $_SESSION['csrf_token'] ?? '';
?>

<section class="sf-settings-panel" aria-labelledby="sf-system-general-title">
    <div class="sf-settings-panel-header">
        <div>
            <p class="sf-settings-eyebrow">
                <?= htmlspecialchars(sf_term('settings_group_system', $currentUiLang) ?? 'Järjestelmä', ENT_QUOTES, 'UTF-8') ?>
            </p>
            <h2 id="sf-system-general-title">
                <?= htmlspecialchars(sf_term('settings_list_page', $currentUiLang) ?? 'Lista-sivu', ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <p class="sf-settings-panel-lead">
                <?= htmlspecialchars(sf_term('settings_general_intro', $currentUiLang) ?? 'Määritä listanäkymän reaaliaikaisuuteen ja muokkauslukituksiin liittyvät asetukset.', ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
    </div>

    <div class="sf-settings-card-grid">
        <article class="sf-settings-card">
            <div class="sf-settings-card-main">
                <div class="sf-settings-card-icon" aria-hidden="true">✏️</div>
                <div class="sf-settings-card-text">
                    <h3>
                        <?= htmlspecialchars(sf_term('settings_editing_indicator', $currentUiLang) ?? 'Näytä muokkaus-indikaattori', ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <p>
                        <?= htmlspecialchars(sf_term('settings_editing_indicator_desc', $currentUiLang) ?? 'Näyttää listalla reaaliaikaisesti kuka muokkaa mitäkin SafetyFlashia', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
            </div>

            <div class="sf-settings-card-control">
                <label class="sf-toggle sf-toggle-modern" for="editing_indicator_enabled">
                    <input type="checkbox" id="editing_indicator_enabled" name="editing_indicator_enabled" <?= sf_get_setting('editing_indicator_enabled', false) ? 'checked' : '' ?>>
                    <span class="sf-toggle-slider"></span>
                    <span class="sr-only">
                        <?= htmlspecialchars(sf_term('settings_editing_indicator', $currentUiLang) ?? 'Näytä muokkaus-indikaattori', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </label>
            </div>
        </article>

        <article class="sf-settings-card">
            <div class="sf-settings-card-main">
                <div class="sf-settings-card-icon" aria-hidden="true">⏱️</div>
                <div class="sf-settings-card-text">
                    <h3>
                        <?= htmlspecialchars(sf_term('settings_polling_interval', $currentUiLang) ?? 'Päivitysväli', ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <p>
                        <?= htmlspecialchars(sf_term('settings_polling_interval_desc', $currentUiLang) ?? 'Määrittää kuinka usein listanäkymä tarkistaa muokkaustilanteet.', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
            </div>

            <div class="sf-settings-card-control sf-settings-number-control">
                <input type="number" id="editing_indicator_interval" name="editing_indicator_interval" value="<?= (int)sf_get_setting('editing_indicator_interval', 30) ?>" min="10" max="120" class="sf-input-small">
                <span class="sf-input-suffix">
                    <?= htmlspecialchars(sf_term('seconds', $currentUiLang) ?? 'sekuntia', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </article>

        <article class="sf-settings-card">
            <div class="sf-settings-card-main">
                <div class="sf-settings-card-icon" aria-hidden="true">🔒</div>
                <div class="sf-settings-card-text">
                    <h3>
                        <?= htmlspecialchars(sf_term('settings_lock_timeout', $currentUiLang) ?? 'Lukituksen vanhenemisaika', ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <p>
                        <?= htmlspecialchars(sf_term('settings_lock_timeout_desc', $currentUiLang) ?? 'Määrittää kuinka kauan muokkauslukitus säilyy ilman käyttäjän aktiivisuutta.', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
            </div>

            <div class="sf-settings-card-control sf-settings-number-control">
                <input type="number" id="soft_lock_timeout" name="soft_lock_timeout" value="<?= (int)sf_get_setting('soft_lock_timeout', 15) ?>" min="5" max="60" class="sf-input-small">
                <span class="sf-input-suffix">
                    <?= htmlspecialchars(sf_term('minutes', $currentUiLang) ?? 'minuuttia', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </article>
    </div>

    <div class="sf-settings-actions sf-settings-actions-modern">
        <button type="button" id="saveSystemSettings" class="sf-btn sf-btn-primary">
            <?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</section>

<script>
(function() {
    'use strict';

    const baseUrl    = window.SF_BASE_URL || '<?= $baseUrl ?>';
    const csrfToken  = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';
    const saveBtn    = document.getElementById('saveSystemSettings');

    if (saveBtn) {
        saveBtn.addEventListener('click', async function() {
            const data = {
                editing_indicator_enabled: document.getElementById('editing_indicator_enabled')?.checked || false,
                editing_indicator_interval: parseInt(document.getElementById('editing_indicator_interval')?.value || '30', 10),
                soft_lock_timeout: parseInt(document.getElementById('soft_lock_timeout')?.value || '15', 10)
            };

            saveBtn.disabled = true;
            saveBtn.textContent = '<?= htmlspecialchars(sf_term('saving', $currentUiLang) ?? 'Tallennetaan...', ENT_QUOTES, 'UTF-8') ?>';

            try {
                const response = await fetch(baseUrl + '/app/api/save_settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(data)
                });

                if (response.ok) {
                    saveBtn.textContent = '<?= htmlspecialchars(sf_term('saved', $currentUiLang) ?? 'Tallennettu!', ENT_QUOTES, 'UTF-8') ?>';
                    setTimeout(() => {
                        saveBtn.textContent = '<?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>';
                        saveBtn.disabled = false;
                    }, 2000);
                } else {
                    const errorData = await response.json().catch(() => ({}));
                    const errorMsg = errorData.error || '<?= htmlspecialchars(sf_term('save_error', $currentUiLang) ?? 'Tallennus epäonnistui', ENT_QUOTES, 'UTF-8') ?>';
                    throw new Error(errorMsg);
                }
            } catch (e) {
                alert(e.message || '<?= htmlspecialchars(sf_term('save_error', $currentUiLang) ?? 'Tallennus epäonnistui', ENT_QUOTES, 'UTF-8') ?>');
                saveBtn.textContent = '<?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>';
                saveBtn.disabled = false;
            }
        });
    }
})();
</script>