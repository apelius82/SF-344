<?php
/**
 * SafetyFlash - Display Targets Management Modal
 *
 * Infonäyttöjen hallintamodaali — julkaistuille flasheille.
 * Sallii admin/turvatiimi/viestintä-käyttäjille muokata display-targeteja
 * julkaisun jälkeen.
 *
 * Required variables:
 * @var array $flash Flash data (id, lang, display_expires_at, display_duration_seconds)
 * @var PDO $pdo Database connection
 * @var string $currentUiLang Current UI language
 * @var string $base Base URL
 * @var int $id Flash ID
 */

$dtFlashId = (int)($flash['id'] ?? 0);
$dtIsExpired = false;
if (!empty($flash['display_expires_at'])) {
    $daysLeft = (int)ceil((strtotime($flash['display_expires_at']) - time()) / 86400);
    $dtIsExpired = $daysLeft <= 0;
}
$dtCurrentDuration = (int)($flash['display_duration_seconds'] ?? 30);

// Hae aktiiviset display-kohteet tätä flashia varten
$dtPreselectedIds = [];
try {
    $stmtDtPre = $pdo->prepare("SELECT display_key_id FROM sf_flash_display_targets WHERE flash_id = ? AND is_active = 1");
    $stmtDtPre->execute([$dtFlashId]);
    $dtPreselectedIds = $stmtDtPre->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $eDt) {
    // Silently ignore
}

// TTL-vaihtoehdot
$dtTtlOptions = [
    0  => ['key' => 'ttl_no_limit',  'label' => 'Ei rajaa'],
    7  => ['key' => 'ttl_1_week',    'label' => '1 viikko'],
    14 => ['key' => 'ttl_2_weeks',   'label' => '2 viikkoa'],
    30 => ['key' => 'ttl_1_month',   'label' => '1 kuukausi'],
    60 => ['key' => 'ttl_2_months',  'label' => '2 kuukautta'],
    90 => ['key' => 'ttl_3_months',  'label' => '3 kuukautta'],
];

// Duration-vaihtoehdot
$dtDurationOptions = [
    10 => 'duration_10s',
    15 => 'duration_15s',
    20 => 'duration_20s',
    30 => 'duration_30s',
    45 => 'duration_45s',
    60 => 'duration_60s',
];
?>

<!-- Infonäyttö-modaali -->
<div class="sf-modal hidden"
     id="displayTargetsModal"
     data-current-expires="<?= htmlspecialchars($flash['display_expires_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
     data-locale="<?= htmlspecialchars($currentUiLang ?? 'fi', ENT_QUOTES, 'UTF-8') ?>"
     role="dialog"
     aria-modal="true"
     aria-labelledby="displayTargetsModalTitle">
    <div class="sf-modal-backdrop" onclick="closeDisplayTargetsModal()"></div>
    <div class="sf-modal-content sf-dt-modal-content">
        <div class="sf-modal-header">
            <h3 id="displayTargetsModalTitle">
                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/display.svg" alt="" aria-hidden="true" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;">
                <?= htmlspecialchars(sf_term('display_targets_modal_title', $currentUiLang) ?? 'Infonäyttöjen hallinta', ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" class="sf-modal-close" onclick="closeDisplayTargetsModal()" aria-label="Sulje">✕</button>
        </div>

        <div class="sf-modal-body">
            <?php if ($dtIsExpired): ?>
            <div class="sf-dt-expired-banner sf-dt-expired-banner-modern" id="dtExpiredBanner" role="alert">
                <span class="sf-dt-expired-icon" aria-hidden="true">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/pending.svg" alt="">
                </span>

                <div class="sf-dt-expired-text">
                    <strong><?= htmlspecialchars(sf_term('display_expired_title', $currentUiLang) ?? 'Näyttöaika päättynyt', ENT_QUOTES, 'UTF-8') ?></strong>
                    <p><?= htmlspecialchars(sf_term('display_expired_description', $currentUiLang) ?? 'Valitse uusi näkyvyysaika ja julkaise tiedote uudelleen infonäytöille.', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <?php endif; ?>

            <div class="sf-dt-tabs" role="tablist" aria-label="<?= htmlspecialchars(sf_term('display_targets_modal_title', $currentUiLang) ?? 'Työmaanäyttöasetukset', ENT_QUOTES, 'UTF-8') ?>">
                <button type="button"
                        class="sf-dt-tab sf-dt-tab-active"
                        id="dtTabTiming"
                        data-tab="timing"
                        role="tab"
                        aria-selected="true"
                        aria-controls="dtPanelTiming">
                    <?= htmlspecialchars(sf_term('display_tab_timing', $currentUiLang) ?? 'Kestoasetukset', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="button"
                        class="sf-dt-tab"
                        id="dtTabTargets"
                        data-tab="targets"
                        role="tab"
                        aria-selected="false"
                        aria-controls="dtPanelTargets"
                        tabindex="-1">
                    <?= htmlspecialchars(sf_term('display_tab_targets', $currentUiLang) ?? 'Infonäyttökohteet', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>

            <div class="sf-dt-tab-panel sf-dt-tab-panel-active" id="dtPanelTiming" data-tab-panel="timing" role="tabpanel" aria-labelledby="dtTabTiming">
                <!-- TTL + Duration valitsimet vierekkäin -->
                <div class="sf-dt-compact-row">

                <!-- TTL valitsin -->
                <div class="sf-dt-section">
                    <div class="sf-ttl-header">
                        <svg class="sf-ttl-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <h4><?= htmlspecialchars(sf_term('display_ttl_heading', $currentUiLang) ?? 'Näkyvyysaika infonäytöillä', ENT_QUOTES, 'UTF-8') ?></h4>
                    </div>
                    <div class="sf-ttl-chips" id="dtTtlChips">
                        <?php foreach ($dtTtlOptions as $days => $opt): ?>
                            <?php $chipId = "dt_ttl_chip_{$days}"; ?>
                            <label for="<?= $chipId ?>" class="sf-ttl-chip">
                                <input type="radio" name="dt_display_ttl_days" id="<?= $chipId ?>"
                                       value="<?= $days ?>"
                                       class="sf-ttl-radio">
                                <span class="sf-chip-label">
                                    <?= htmlspecialchars(sf_term($opt['key'], $currentUiLang) ?? $opt['label'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                </div>

                <!-- Duration valitsin -->
                <div class="sf-dt-section">
                    <div class="sf-duration-header">
                        <svg class="sf-duration-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <h4><?= htmlspecialchars(sf_term('display_duration_heading', $currentUiLang) ?? 'Näyttökesto infonäytöllä', ENT_QUOTES, 'UTF-8') ?></h4>
                    </div>
                    <div class="sf-duration-chips" id="dtDurationChips">
                        <?php foreach ($dtDurationOptions as $seconds => $termKey): ?>
                            <?php $chipId = "dt_duration_chip_{$seconds}"; ?>
                            <label for="<?= $chipId ?>" class="sf-duration-chip <?= $seconds === $dtCurrentDuration ? 'sf-duration-chip-selected' : '' ?>">
                                <input type="radio" name="dt_display_duration_seconds" id="<?= $chipId ?>"
                                       value="<?= $seconds ?>"
                                       <?= $seconds === $dtCurrentDuration ? 'checked' : '' ?>
                                       class="sf-duration-radio">
                                <span class="sf-chip-label">
                                    <?= htmlspecialchars(sf_term($termKey, $currentUiLang) ?? "{$seconds}s", ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                </div><!-- /.sf-dt-compact-row -->
            </div>

            <div class="sf-dt-tab-panel" id="dtPanelTargets" data-tab-panel="targets" role="tabpanel" aria-labelledby="dtTabTargets">
                <!-- Näyttökohteet -->
                <div class="sf-dt-section">
                    <div class="sf-dt-displays-header">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:var(--sf-primary,#0066cc);">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                        <h4><?= htmlspecialchars(sf_term('display_targets_heading', $currentUiLang) ?? 'Infonäyttökohteet', ENT_QUOTES, 'UTF-8') ?></h4>
                    </div>
                    <?php
                        // Käytä modaalin omaa esivalintakyselyä (is_active=1) display_target_selector-includeessa
                        $preselectedIds = $dtPreselectedIds;
                        $dtOriginalFlash = $flash;
                        $flash = ['id' => $dtFlashId];
                        $context = 'safety_team';
                        require __DIR__ . '/display_target_selector.php';
                        $flash = $dtOriginalFlash;
                        unset($preselectedIds, $dtOriginalFlash);
                    ?>
                    <div class="sf-dt-no-displays-alert hidden" id="dtNoDisplaysWarning" role="alert">
                        <div class="sf-dt-no-displays-alert-icon" aria-hidden="true">⚠️</div>
                        <div class="sf-dt-no-displays-alert-content">
                            <strong><?= htmlspecialchars(sf_term('display_no_targets_title', $currentUiLang) ?? 'Ei näytöillä tallennuksen jälkeen', ENT_QUOTES, 'UTF-8') ?></strong>
                            <p><?= htmlspecialchars(sf_term('display_no_targets_warning', $currentUiLang) ?? 'Valitse vähintään yksi näyttökohde ennen tallennusta.', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </div>
            </div>

                                <div class="sf-dt-ttl-preview-card" id="dtTtlPreview"
                         data-current-label="<?= htmlspecialchars(sf_term('display_ttl_current_label', $currentUiLang) ?? 'Nykyinen', ENT_QUOTES, 'UTF-8') ?>"
                         data-new-label="<?= htmlspecialchars(sf_term('display_ttl_new_label', $currentUiLang) ?? 'Uusi valinta', ENT_QUOTES, 'UTF-8') ?>"
                         data-expires-prefix="<?= htmlspecialchars(sf_term('display_ttl_expires_prefix', $currentUiLang) ?? 'Poistuu', ENT_QUOTES, 'UTF-8') ?>"
                         data-days-left-label="<?= htmlspecialchars(sf_term('display_ttl_days_left', $currentUiLang) ?? 'pv jäljellä', ENT_QUOTES, 'UTF-8') ?>"
                         data-expired-label="<?= htmlspecialchars(sf_term('display_ttl_expired', $currentUiLang) ?? 'umpeutunut', ENT_QUOTES, 'UTF-8') ?>"
                         data-no-limit-text="<?= htmlspecialchars(sf_term('display_ttl_no_limit', $currentUiLang) ?? 'Näkyy toistaiseksi', ENT_QUOTES, 'UTF-8') ?>"
                         data-not-on-displays-text="<?= htmlspecialchars(sf_term('display_ttl_not_on_displays', $currentUiLang) ?? 'Ei näytöillä', ENT_QUOTES, 'UTF-8') ?>">
                        <div class="sf-dt-ttl-preview-row sf-dt-ttl-current" style="display:none;"></div>
                        <div class="sf-dt-ttl-preview-row sf-dt-ttl-new" style="display:none;"></div>
                    </div>

            <div id="dtSaveStatus" class="sf-dt-status" role="status" aria-live="polite"></div>
        </div>

        <div class="sf-modal-footer">
            <button type="button" class="sf-btn sf-btn-secondary" onclick="closeDisplayTargetsModal()">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnSaveDisplayTargets" data-flash-id="<?= $dtFlashId ?>">
                <?= htmlspecialchars(sf_term($dtIsExpired ? 'btn_republish_to_displays' : 'btn_save', $currentUiLang) ?? ($dtIsExpired ? 'Julkaise uudelleen näytöille' : 'Tallenna'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>