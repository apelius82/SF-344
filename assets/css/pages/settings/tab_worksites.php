<?php
// app/pages/settings/tab_worksites.php
declare(strict_types=1);

if (!function_exists('sf_worksite_format_datetime')) {
    function sf_worksite_format_datetime(?string $value): string {
        if ($value === null || trim($value) === '') {
            return '—';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return '—';
        }
        return date('d.m.Y H:i', $ts);
    }
}

if (!function_exists('sf_worksite_strtolower')) {
    function sf_worksite_strtolower(string $value): string {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

// Hae työmaat, niiden display API-avaimet ja aktiivisten flashien määrä
$worksites = [];
$worksitesFallbackLevel = 0;
try {
$worksitesRes = $mysqli->query(
    'SELECT w.id, w.name, w.site_type, w.country_code, w.supported_publish_languages_json, w.is_active, w.created_at, w.updated_at,
            COALESCE(w.show_in_worksite_lists, 1) AS show_in_worksite_lists,
            COALESCE(w.show_in_display_targets, 1) AS show_in_display_targets,
            COALESCE(w.is_default_display, 0) AS is_default_display,
            k.api_key AS display_api_key,
            k.id AS display_key_id,
            COUNT(DISTINCT f.id) AS active_flash_count
      FROM sf_worksites w
      LEFT JOIN sf_display_api_keys k
             ON k.worksite_id = w.id
            AND k.is_active = 1
            AND (k.expires_at IS NULL OR k.expires_at > NOW())
      LEFT JOIN sf_flash_display_targets t
             ON t.display_key_id = k.id
            AND t.is_active = 1
      LEFT JOIN sf_flashes f
             ON f.id = t.flash_id
            AND f.state = \'published\'
            AND (f.display_expires_at IS NULL OR f.display_expires_at > NOW())
            AND f.display_removed_at IS NULL
      GROUP BY w.id, w.name, w.site_type, w.country_code, w.supported_publish_languages_json, w.is_active, w.created_at, w.updated_at, w.show_in_worksite_lists, w.show_in_display_targets, w.is_default_display, k.api_key, k.id
      ORDER BY w.name ASC'
);
    if (!$worksitesRes) {
        error_log('tab_worksites: primary query failed: ' . $mysqli->error);
        $worksitesFallbackLevel = 1;
        // Secondary fallback: keep API key join, but avoid optional sf_worksites columns.
        $worksitesRes = $mysqli->query(
            'SELECT w.id, w.name, NULL AS site_type, w.is_active,
                    NULL AS created_at, NULL AS updated_at,
                    1 AS show_in_worksite_lists, 1 AS show_in_display_targets, 0 AS is_default_display,
                    k.api_key AS display_api_key, k.id AS display_key_id,
                    0 AS active_flash_count
               FROM sf_worksites w
               LEFT JOIN sf_display_api_keys k ON k.worksite_id = w.id AND k.is_active = 1
              ORDER BY w.name ASC'
        );
        if (!$worksitesRes) {
            error_log('tab_worksites: secondary fallback failed: ' . $mysqli->error);
            $worksitesFallbackLevel = 2;
            // Minimal fallback: only sf_worksites table, preserve expected shape for UI.
            $worksitesRes = $mysqli->query(
                'SELECT id, name, NULL AS site_type, is_active,
                        NULL AS created_at, NULL AS updated_at,
                        1 AS show_in_worksite_lists, 1 AS show_in_display_targets, 0 AS is_default_display,
                        NULL AS display_api_key, NULL AS display_key_id,
                        0 AS active_flash_count
                   FROM sf_worksites
                  ORDER BY name ASC'
            );
            if (!$worksitesRes) {
                error_log('tab_worksites: minimal fallback failed: ' . $mysqli->error);
                $worksitesFallbackLevel = 3;
                // Ultra-minimal fallback: fill missing fields in PHP.
                $worksitesRes = $mysqli->query(
                    'SELECT id, name, is_active
                       FROM sf_worksites
                      ORDER BY name ASC'
                );
                if (!$worksitesRes) {
                    error_log('tab_worksites: ultra-minimal fallback failed: ' . $mysqli->error);
                }
            }
        }
    }
    if ($worksitesRes) {
        while ($w = $worksitesRes->fetch_assoc()) {
            if ($worksitesFallbackLevel >= 3) {
                $w['site_type'] = null;
                $w['created_at'] = null;
                $w['updated_at'] = null;
                $w['show_in_worksite_lists'] = 1;
                $w['show_in_display_targets'] = 1;
                $w['is_default_display'] = 0;
                $w['display_api_key'] = null;
                $w['display_key_id'] = null;
                $w['active_flash_count'] = 0;
            }
            $worksites[] = $w;
        }
        $worksitesRes->free();
    }
} catch (Throwable $e) {
    error_log('tab_worksites: worksites data fetch failed: ' . $e->getMessage());
}
$worksitesFallbackUsed = $worksitesFallbackLevel > 0;
$tabWorksitesUser = sf_current_user();
$tabWorksitesIsAdmin = (int)($tabWorksitesUser['role_id'] ?? 0) === 1;

$visibilityListsDesc = (string)(sf_term('settings_worksites_visibility_lists_desc', $currentUiLang) ?? 'Tulee työmaavalintoihin safetyflashia luotaessa (lomake, suodattimet).');
$visibilityDisplaysDesc = (string)(sf_term('settings_worksites_visibility_displays_desc', $currentUiLang) ?? 'Tulee display-targets -valintoihin julkaisussa (Xibo / Intra / muu kohde).');
$defaultDisplayDesc = (string)(sf_term('settings_worksites_default_display_desc', $currentUiLang) ?? 'Valitaan oletuksena infonäyttökohteeksi safetyflashin julkaisussa.');

$worksiteCountries = [
    'fi' => sf_term('settings_worksite_country_fi', $currentUiLang) ?? 'Suomi',
    'se' => sf_term('settings_worksite_country_se', $currentUiLang) ?? 'Ruotsi',
    'it' => sf_term('settings_worksite_country_it', $currentUiLang) ?? 'Italia',
    'gr' => sf_term('settings_worksite_country_gr', $currentUiLang) ?? 'Kreikka',
];

$publishLanguages = [
    'fi' => sf_term('language_review_language_fi', $currentUiLang) ?? 'Suomi',
    'sv' => sf_term('language_review_language_sv', $currentUiLang) ?? 'Ruotsi',
    'en' => sf_term('language_review_language_en', $currentUiLang) ?? 'Englanti',
    'it' => sf_term('language_review_language_it', $currentUiLang) ?? 'Italia',
    'el' => sf_term('language_review_language_el', $currentUiLang) ?? 'Kreikka',
];
?>

<div class="sf-worksites-toolbar">
    <h2>
        <img src="<?= $baseUrl ?>/assets/img/icons/worksite.svg" alt="" class="sf-heading-icon" aria-hidden="true">
        <?= htmlspecialchars(
            sf_term('settings_worksites_heading', $currentUiLang) ?? 'Työmaiden hallinta',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </h2>
    <div class="sf-worksites-toolbar-actions">
        <button type="button" class="sf-btn sf-btn-sm sf-btn-primary" data-modal-open="#modalAddWorksite">
            + <?= htmlspecialchars(sf_term('settings_worksites_add_button', $currentUiLang) ?? 'Lisää uusi työmaa', ENT_QUOTES, 'UTF-8') ?>
        </button>
        <?php if (!empty($worksites)): ?>
            <details class="sf-export-menu">
                <summary class="sf-btn sf-btn-sm sf-btn-outline-primary">
                    <?= htmlspecialchars(sf_term('btn_export_worksites', $currentUiLang) ?? 'Vie työmaat', ENT_QUOTES, 'UTF-8') ?>
                </summary>
                <div class="sf-export-menu-items" role="menu">
                    <a href="<?= htmlspecialchars($baseUrl . '/app/api/export_worksites.php?format=csv', ENT_QUOTES, 'UTF-8') ?>"
                       class="sf-export-btn"
                       role="menuitem"
                       download>
                        <?= htmlspecialchars(sf_term('btn_export_csv', $currentUiLang) ?? 'Lataa CSV', ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <a href="<?= htmlspecialchars($baseUrl . '/app/api/export_worksites.php?format=json', ENT_QUOTES, 'UTF-8') ?>"
                       class="sf-export-btn"
                       role="menuitem"
                       download>
                        <?= htmlspecialchars(sf_term('btn_export_json', $currentUiLang) ?? 'Lataa JSON', ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
            </details>
        <?php endif; ?>
    </div>
</div>

<p class="sf-notice sf-notice-info sf-worksites-info-notice">
    <span class="sf-worksites-info-icon" aria-hidden="true">
        <img src="<?= $baseUrl ?>/assets/img/icons/publish.svg" alt="">
    </span>
    <span>
        <?= htmlspecialchars(
            sf_term('settings_worksites_display_only_hint', $currentUiLang) ?? 'Voit lisätä myös pelkkiä näyttökohteita (esim. Intra), jotka eivät näy työmaavalinnoissa safetyflashia luotaessa. Poista sellaiselta rasti kohdasta Näytä työmaalistoissa ja jätä Näytä infonäytöissä päälle.',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </span>
</p>

<?php if ($worksitesFallbackUsed && $tabWorksitesIsAdmin): ?>
    <p class="sf-notice sf-notice-warning" style="margin:0 0 1rem;color:#b45309;">
        ⚠️ Huom: Käytössä on yksinkertaistettu työmaalista (admin-näkymä, vain kerran sivulataukseen). Syy: SQL-virhe — katso tuotannon PHP error log.
    </p>
<?php endif; ?>

<?php if (empty($worksites)): ?>
    <p class="sf-notice sf-notice-info">
        <?= htmlspecialchars(
            sf_term('settings_worksites_empty', $currentUiLang) ?? 'Ei työmaita. Lisää ensimmäinen työmaa yllä olevalla lomakkeella.',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>
<?php else: ?>
<div class="sf-worksites-filters" aria-label="<?= htmlspecialchars(sf_term('users_filter_toggle', $currentUiLang) ?? 'Suodattimet', ENT_QUOTES, 'UTF-8') ?>">
    <div class="sf-worksites-search-row">
        <div class="sf-worksites-search-wrap">
            <img src="<?= $baseUrl ?>/assets/img/icons/search_icon.svg" alt="" class="sf-worksites-search-icon" aria-hidden="true">
            <input type="search"
                   id="sfWorksiteSearch"
                   class="sf-worksites-search-input"
                   placeholder="<?= htmlspecialchars(sf_term('settings_worksites_filter_search_placeholder', $currentUiLang) ?? 'Hae työmaan nimellä', ENT_QUOTES, 'UTF-8') ?>"
                   aria-label="<?= htmlspecialchars(sf_term('settings_worksites_filter_search_placeholder', $currentUiLang) ?? 'Hae työmaan nimellä', ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" class="sf-worksites-search-clear" id="sfWorksiteSearchClear" aria-label="<?= htmlspecialchars(sf_term('users_filter_clear', $currentUiLang) ?? 'Tyhjennä', ENT_QUOTES, 'UTF-8') ?>" style="display:none;">✕</button>
        </div>
        <p id="sfWorksiteShowingCount" class="sf-worksites-showing-count" aria-live="polite">
            <?= htmlspecialchars(sprintf((sf_term('settings_worksites_showing_count', $currentUiLang) ?? 'Näytetään %d / %d työmaata'), count($worksites), count($worksites)), ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>
    <div class="sf-worksites-filter-chips" role="group" aria-label="<?= htmlspecialchars(sf_term('users_filter_toggle', $currentUiLang) ?? 'Suodattimet', ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="sf-filter-chip is-active" data-filter="all"><?= htmlspecialchars(sf_term('settings_worksites_filter_all', $currentUiLang) ?? 'Kaikki', ENT_QUOTES, 'UTF-8') ?></button>
        <button type="button" class="sf-filter-chip" data-filter="active"><?= htmlspecialchars(sf_term('settings_worksites_filter_active', $currentUiLang) ?? 'Aktiiviset', ENT_QUOTES, 'UTF-8') ?></button>
        <button type="button" class="sf-filter-chip" data-filter="inactive"><?= htmlspecialchars(sf_term('settings_worksites_filter_inactive', $currentUiLang) ?? 'Passiiviset', ENT_QUOTES, 'UTF-8') ?></button>
        <button type="button" class="sf-filter-chip" data-filter="lists"><?= htmlspecialchars(sf_term('settings_worksites_filter_lists', $currentUiLang) ?? 'Näytetään työmaalistoissa', ENT_QUOTES, 'UTF-8') ?></button>
        <button type="button" class="sf-filter-chip" data-filter="displays"><?= htmlspecialchars(sf_term('settings_worksites_filter_displays', $currentUiLang) ?? 'Näytetään infonäytöissä', ENT_QUOTES, 'UTF-8') ?></button>
    </div>
</div>

<div class="sf-worksites-list" id="sfWorksiteList" role="list">
    <?php foreach ($worksites as $ws): ?>
        <?php
        try {
            $siteTypeKey = $ws['site_type'] ?? null;
            if ($siteTypeKey === 'tunnel') {
                $siteTypeLabel = sf_term('site_type_tunnel', $currentUiLang) ?? 'Tunnelityömaa';
            } elseif ($siteTypeKey === 'opencast') {
                $siteTypeLabel = sf_term('site_type_opencast', $currentUiLang) ?? 'Avolouhos';
            } elseif ($siteTypeKey === 'other') {
                $siteTypeLabel = sf_term('site_type_other', $currentUiLang) ?? 'Muut toimipisteet';
            } else {
                $siteTypeLabel = sf_term('site_type_unspecified', $currentUiLang) ?? 'Määrittämätön';
            }
            $flashCount = (int)($ws['active_flash_count'] ?? 0);
            $isActive = (int)$ws['is_active'] === 1;
            $showInLists = (int)($ws['show_in_worksite_lists'] ?? 1) === 1;
            $showInDisplays = (int)($ws['show_in_display_targets'] ?? 1) === 1;
            $isDefaultDisplay = (int)($ws['is_default_display'] ?? 0) === 1;
            $worksiteId = (int)$ws['id'];
            $worksiteName = (string)($ws['name'] ?? '');
            $worksiteNameLower = sf_worksite_strtolower($worksiteName);
            $worksiteIdLabel = (string)(sf_term('settings_worksites_id_label', $currentUiLang) ?? 'ID');
            $worksiteSearchText = sf_worksite_strtolower($worksiteName . ' ' . $worksiteIdLabel . ' ' . $worksiteId);
            $manageModalId = 'sfWorksiteManageModal' . $worksiteId;
            $playlistUrl = !empty($ws['display_key_id'])
                ? (($baseUrl ?? '') . '/index.php?page=playlist_manager&display_key_id=' . (int)$ws['display_key_id'])
                : '';
            $slideshowUrl = !empty($ws['display_api_key'])
    ? (rtrim((string)($baseUrl ?? ''), '/') . '/app/api/display_playlist.php?key=' . urlencode((string)$ws['display_api_key']) . '&format=slideshow')
    : '';

$playlistPreviewUrl = !empty($ws['display_api_key'])
    ? (rtrim((string)($baseUrl ?? ''), '/') . '/app/api/display_playlist.php?key=' . urlencode((string)$ws['display_api_key']) . '&format=slideshow')
    : '';
            $tabOverviewId = $manageModalId . 'TabOverview';
            $tabVisibilityId = $manageModalId . 'TabVisibility';
            $tabDisplayId = $manageModalId . 'TabDisplay';
            $panelOverviewId = $manageModalId . 'PanelOverview';
            $panelVisibilityId = $manageModalId . 'PanelVisibility';
            $panelDisplayId = $manageModalId . 'PanelDisplay';
            $listsBadgeTitle = (string)sf_term($showInLists ? 'settings_worksites_badge_lists_on' : 'settings_worksites_badge_lists_off', $currentUiLang);
            $displaysBadgeTitle = (string)sf_term($showInDisplays ? 'settings_worksites_badge_displays_on' : 'settings_worksites_badge_displays_off', $currentUiLang);
        } catch (Throwable $worksiteRenderError) {
            $rowRenderErrorMessage = 'tab_worksites: row render failed for worksite id ' . (int)($ws['id'] ?? 0) . ': ' . $worksiteRenderError->getMessage();
            if (function_exists('sf_app_log') && defined('LOG_LEVEL_ERROR')) {
                sf_app_log($rowRenderErrorMessage, LOG_LEVEL_ERROR);
            } else {
                error_log($rowRenderErrorMessage);
            }
            continue;
        }
        ?>
        <div class="sf-worksite-row"
             role="listitem"
             tabindex="0"
             data-worksite-id="<?= $worksiteId ?>"
             data-name="<?= htmlspecialchars($worksiteSearchText, ENT_QUOTES, 'UTF-8') ?>"
             data-active="<?= $isActive ? '1' : '0' ?>"
             data-lists="<?= $showInLists ? '1' : '0' ?>"
             data-displays="<?= $showInDisplays ? '1' : '0' ?>"
             data-default-display="<?= $isDefaultDisplay ? '1' : '0' ?>"
             data-modal="#<?= htmlspecialchars($manageModalId, ENT_QUOTES, 'UTF-8') ?>">
            <div class="sf-worksite-card-icon" aria-hidden="true">
    <img src="<?= $baseUrl ?>/assets/img/icons/worksite.svg" alt="">
</div>

<div class="sf-worksite-row-main">
    <div class="sf-worksite-row-name">
        <?= htmlspecialchars($worksiteName, ENT_QUOTES, 'UTF-8') ?>
    </div>

    <div class="sf-worksite-row-meta">
        <span class="sf-worksite-type-badge">
            <?= htmlspecialchars($siteTypeLabel, ENT_QUOTES, 'UTF-8') ?>
        </span>

        <span class="sf-status-badge <?= $isActive ? 'is-active' : 'is-inactive' ?>">
            <?= htmlspecialchars($isActive ? (sf_term('settings_worksites_status_active', $currentUiLang) ?? 'Aktiivinen') : (sf_term('settings_worksites_status_inactive', $currentUiLang) ?? 'Passiivinen'), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
</div>

<div class="sf-worksite-row-toggles sf-ws-visibility-badges" data-no-row-click>
    <span class="sf-ws-badge <?= $showInLists ? 'is-on' : 'is-off' ?>"
      data-ws-badge-field="show_in_worksite_lists"
      title="<?= htmlspecialchars($listsBadgeTitle, ENT_QUOTES, 'UTF-8') ?>"
      aria-label="<?= htmlspecialchars($listsBadgeTitle, ENT_QUOTES, 'UTF-8') ?>">
    <span class="sf-ws-badge-label"><?= htmlspecialchars(sf_term('settings_worksites_toggle_lists_short', $currentUiLang) ?? 'Työmaavalinta', ENT_QUOTES, 'UTF-8') ?></span>
</span>

<span class="sf-ws-badge <?= $showInDisplays ? 'is-on' : 'is-off' ?>"
      data-ws-badge-field="show_in_display_targets"
      title="<?= htmlspecialchars($displaysBadgeTitle, ENT_QUOTES, 'UTF-8') ?>"
      aria-label="<?= htmlspecialchars($displaysBadgeTitle, ENT_QUOTES, 'UTF-8') ?>">
    <span class="sf-ws-badge-label"><?= htmlspecialchars(sf_term('settings_worksites_toggle_displays_short', $currentUiLang) ?? 'Näyttövalinta', ENT_QUOTES, 'UTF-8') ?></span>
</span>
    </span>

    <?php if ($playlistUrl !== ''): ?>
<button type="button"
        class="sf-ws-badge sf-ws-badge-playlist is-info sf-worksite-playlist-open"
        data-no-row-click
        data-playlist-modal="#modalKatsoAjolista"
        data-playlist-url="<?= htmlspecialchars($playlistPreviewUrl, ENT_QUOTES, 'UTF-8') ?>"
        data-playlist-label="<?= htmlspecialchars($worksiteName, ENT_QUOTES, 'UTF-8') ?>"
        data-manager-url="<?= htmlspecialchars($playlistUrl, ENT_QUOTES, 'UTF-8') ?>">
    <span class="sf-ws-badge-label">
        <?= htmlspecialchars(sprintf(sf_term('settings_worksites_meta_flash_count', $currentUiLang) ?? '%d ajolistassa', $flashCount), ENT_QUOTES, 'UTF-8') ?>
    </span>
</button>
    <?php else: ?>
        <span class="sf-ws-badge is-info">
            <span class="sf-ws-badge-label">
                <?= htmlspecialchars(sprintf(sf_term('settings_worksites_meta_flash_count', $currentUiLang) ?? '%d ajolistassa', $flashCount), ENT_QUOTES, 'UTF-8') ?>
            </span>
        </span>
    <?php endif; ?>
    </span>
</div>

<button type="button"
        class="sf-btn sf-btn-sm sf-btn-primary sf-worksite-manage-btn"
        data-no-row-click
        data-modal-open="#<?= htmlspecialchars($manageModalId, ENT_QUOTES, 'UTF-8') ?>">
    <img src="<?= $baseUrl ?>/assets/img/icons/settings.svg" alt="" aria-hidden="true">
    <?= htmlspecialchars(sf_term('settings_worksites_manage_btn', $currentUiLang) ?? 'Hallitse', ENT_QUOTES, 'UTF-8') ?>
</button>
        </div>

        <div class="sf-modal hidden sf-worksite-manage-modal" id="<?= htmlspecialchars($manageModalId, ENT_QUOTES, 'UTF-8') ?>" role="dialog" aria-modal="true" aria-labelledby="<?= htmlspecialchars($manageModalId, ENT_QUOTES, 'UTF-8') ?>Title">
            <div class="sf-modal-content sf-worksite-manage-content">
                <div class="sf-modal-header">
<h3 id="<?= htmlspecialchars($manageModalId, ENT_QUOTES, 'UTF-8') ?>Title"><?= htmlspecialchars($worksiteName, ENT_QUOTES, 'UTF-8') ?></h3>
<button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('settings_worksites_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
                </div>
                <div class="sf-worksite-manage-tabs" role="tablist" aria-label="<?= htmlspecialchars(sf_term('settings_worksites_manage_tabs_label', $currentUiLang) ?? 'Työmaan hallinnan välilehdet', ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button"
                            class="sf-ws-tab is-active"
                            id="<?= htmlspecialchars($tabOverviewId, ENT_QUOTES, 'UTF-8') ?>"
                            role="tab"
                            aria-selected="true"
                            aria-controls="<?= htmlspecialchars($panelOverviewId, ENT_QUOTES, 'UTF-8') ?>"
                            tabindex="0">
                        <?= htmlspecialchars(sf_term('settings_worksites_basic_info', $currentUiLang) ?? 'Perustiedot', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button"
                            class="sf-ws-tab"
                            id="<?= htmlspecialchars($tabVisibilityId, ENT_QUOTES, 'UTF-8') ?>"
                            role="tab"
                            aria-selected="false"
                            aria-controls="<?= htmlspecialchars($panelVisibilityId, ENT_QUOTES, 'UTF-8') ?>"
                            tabindex="-1">
                        <?= htmlspecialchars(sf_term('settings_worksites_tab_visibility', $currentUiLang) ?? 'Näkyvyys', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button"
                            class="sf-ws-tab"
                            id="<?= htmlspecialchars($tabDisplayId, ENT_QUOTES, 'UTF-8') ?>"
                            role="tab"
                            aria-selected="false"
                            aria-controls="<?= htmlspecialchars($panelDisplayId, ENT_QUOTES, 'UTF-8') ?>"
                            tabindex="-1">
                        <?= htmlspecialchars(sf_term('settings_worksites_tab_display', $currentUiLang) ?? 'Infonäyttö', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
                <div class="sf-modal-body sf-worksite-manage-body">
                    <section class="sf-ws-tab-panel"
                             id="<?= htmlspecialchars($panelOverviewId, ENT_QUOTES, 'UTF-8') ?>"
                             role="tabpanel"
                             aria-labelledby="<?= htmlspecialchars($tabOverviewId, ENT_QUOTES, 'UTF-8') ?>">
<form method="post"
      action="app/actions/worksites_save.php"
      data-sf-ajax="0"
      id="sfWorksiteBasicForm<?= $worksiteId ?>"
      class="sf-worksite-direct-edit sf-worksite-dirty-form">
                            <input type="hidden" name="form_action" value="edit">
                            <?= sf_csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $worksiteId ?>">
                            <input type="hidden" name="worksite_visibility_fields_present" value="1">

                            <h4><?= htmlspecialchars(sf_term('settings_worksites_basic_info', $currentUiLang) ?? 'Perustiedot', ENT_QUOTES, 'UTF-8') ?></h4>

                            <div class="sf-worksite-direct-edit-grid">
                                <div class="sf-worksite-direct-field sf-worksite-direct-field-name">
                                    <label for="editWsName<?= $worksiteId ?>">
                                        <?= htmlspecialchars(sf_term('settings_worksites_col_name', $currentUiLang) ?? 'Nimi', ENT_QUOTES, 'UTF-8') ?>
                                    </label>
                                    <div class="sf-worksite-name-edit-wrap">
<input type="text"
       id="editWsName<?= $worksiteId ?>"
       name="name"
       required
       class="sf-input sf-worksite-name-input"
       value="<?= htmlspecialchars($worksiteName, ENT_QUOTES, 'UTF-8') ?>"
       oninput="window.sfWorksiteEnableSave('sfWorksiteBasicForm<?= $worksiteId ?>')">
                                        <img src="<?= $baseUrl ?>/assets/img/icons/edit.svg" alt="" aria-hidden="true">
                                    </div>
                                </div>

                                <div class="sf-worksite-direct-field">
                                    <label for="editWsSiteType<?= $worksiteId ?>">
                                        <?= htmlspecialchars(sf_term('settings_worksites_site_type', $currentUiLang) ?? 'Työmaan tyyppi', ENT_QUOTES, 'UTF-8') ?>
                                    </label>
                                    <select id="editWsSiteType<?= $worksiteId ?>"
        name="site_type"
        class="sf-select"
        onchange="window.sfWorksiteEnableSave('sfWorksiteBasicForm<?= $worksiteId ?>')">
                                        <option value="" <?= empty($ws['site_type']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(sf_term('site_type_unspecified', $currentUiLang) ?? 'Määrittämätön', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                        <option value="tunnel" <?= ($ws['site_type'] ?? '') === 'tunnel' ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(sf_term('site_type_tunnel', $currentUiLang) ?? 'Tunnelityömaa', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                        <option value="opencast" <?= ($ws['site_type'] ?? '') === 'opencast' ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(sf_term('site_type_opencast', $currentUiLang) ?? 'Avolouhos', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                        <option value="other" <?= ($ws['site_type'] ?? '') === 'other' ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(sf_term('site_type_other', $currentUiLang) ?? 'Muut toimipisteet', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    </select>
                                </div>

                                <?php
                                $worksiteCountryCode = (string)($ws['country_code'] ?? '');
                                $supportedPublishLanguages = [];

                                if (!empty($ws['supported_publish_languages_json'])) {
                                    $decodedSupportedLanguages = json_decode((string)$ws['supported_publish_languages_json'], true);
                                    if (is_array($decodedSupportedLanguages)) {
                                        $supportedPublishLanguages = array_values(array_intersect(array_keys($publishLanguages), $decodedSupportedLanguages));
                                    }
                                }

                                if (empty($supportedPublishLanguages)) {
                                    $supportedPublishLanguages = ['fi'];
                                }
                                ?>

                                <div class="sf-worksite-direct-field">
                                    <label for="editWsCountry<?= $worksiteId ?>">
                                        <?= htmlspecialchars(sf_term('settings_worksite_country_label', $currentUiLang) ?? 'Maa', ENT_QUOTES, 'UTF-8') ?>
                                    </label>
                                    <select id="editWsCountry<?= $worksiteId ?>"
                                            name="country_code"
                                            class="sf-select"
                                            onchange="window.sfWorksiteEnableSave('sfWorksiteBasicForm<?= $worksiteId ?>')">
                                        <option value=""><?= htmlspecialchars(sf_term('settings_worksite_country_unspecified', $currentUiLang) ?? 'Ei määritetty', ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php foreach ($worksiteCountries as $countryCode => $countryLabel): ?>
                                            <option value="<?= htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') ?>" <?= $worksiteCountryCode === $countryCode ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($countryLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="sf-worksite-direct-field">
                                    <label>
                                        <?= htmlspecialchars(sf_term('settings_worksite_supported_languages_label', $currentUiLang) ?? 'Tuetut julkaisukielet', ENT_QUOTES, 'UTF-8') ?>
                                    </label>
                                    <div class="sf-worksite-language-checks">
                                        <?php foreach ($publishLanguages as $languageCode => $languageLabel): ?>
                                            <label class="sf-worksite-language-check">
                                                <input type="checkbox"
                                                       name="supported_publish_languages[]"
                                                       value="<?= htmlspecialchars($languageCode, ENT_QUOTES, 'UTF-8') ?>"
                                                       onchange="window.sfWorksiteEnableSave('sfWorksiteBasicForm<?= $worksiteId ?>')"
                                                       <?= in_array($languageCode, $supportedPublishLanguages, true) ? 'checked' : '' ?>>
                                                <span><?= htmlspecialchars($languageLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="sf-worksite-help-text">
                                        <?= htmlspecialchars(sf_term('settings_worksite_supported_languages_help', $currentUiLang) ?? 'Näitä kieliversioita voidaan julkaista tämän maan infonäytöille.', ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
								
                                <div class="sf-worksite-direct-readonly">
                                    <span><?= htmlspecialchars(sf_term('settings_worksites_id_label', $currentUiLang) ?? 'ID', ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= $worksiteId ?></strong>
                                </div>

                                <div class="sf-worksite-direct-readonly">
                                    <span><?= htmlspecialchars(sf_term('settings_worksites_col_created', $currentUiLang) ?? 'Luotu', ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= htmlspecialchars(sf_worksite_format_datetime(isset($ws['created_at']) ? (string)$ws['created_at'] : null), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>

                                <div class="sf-worksite-direct-readonly">
                                    <span><?= htmlspecialchars(sf_term('settings_worksites_col_updated', $currentUiLang) ?? 'Viimeksi päivitetty', ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= htmlspecialchars(sf_worksite_format_datetime(isset($ws['updated_at']) ? (string)$ws['updated_at'] : null), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                            </div>

                            <div class="sf-worksite-options-row">
                                <div class="sf-worksite-active-block">
                                    <label class="sf-worksite-active-switch">
                                        <input type="hidden" name="is_active" value="0">
                                        <input type="checkbox"
                                               name="is_active"
                                               value="1"
                                               onchange="window.sfWorksiteEnableSave('sfWorksiteBasicForm<?= $worksiteId ?>')"
                                               <?= $isActive ? 'checked' : '' ?>>
                                        <span class="sf-worksite-active-switch-track" aria-hidden="true">
                                            <span class="sf-worksite-active-switch-thumb"></span>
                                        </span>
                                        <span class="sf-worksite-active-switch-text">
                                            <?= htmlspecialchars($isActive ? (sf_term('settings_worksites_status_active', $currentUiLang) ?? 'Aktiivinen') : (sf_term('settings_worksites_status_inactive', $currentUiLang) ?? 'Passiivinen'), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </label>

                                    <p class="sf-worksite-active-help">
                                        <?= htmlspecialchars(sf_term('settings_worksite_active_help', $currentUiLang) ?? 'Aktiivinen työmaa näkyy käyttäjille valinnoissa ja voidaan liittää uusiin SafetyFlasheihin. Passiivinen työmaa piilotetaan valinnoista, mutta aiemmin luodut tiedot säilyvät.', ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                            </div>
                        </form>
                    </section>

                    <section class="sf-ws-tab-panel"
                             id="<?= htmlspecialchars($panelVisibilityId, ENT_QUOTES, 'UTF-8') ?>"
                             role="tabpanel"
                             aria-labelledby="<?= htmlspecialchars($tabVisibilityId, ENT_QUOTES, 'UTF-8') ?>"
                             hidden>
                        <p class="sf-worksite-visibility-tip">
                            <img src="<?= $baseUrl ?>/assets/img/icons/info.svg" alt="" aria-hidden="true">
                            <span><?= htmlspecialchars(sf_term('settings_worksites_visibility_tip', $currentUiLang) ?? 'Jos kohde on vain infonäyttö, pidä näyttölista käytössä ja poista työmaalista käytöstä.', ENT_QUOTES, 'UTF-8') ?></span>
                        </p>
                        <div class="sf-worksite-modal-visibility">
                            <label class="sf-ws-visibility-card" for="ws-modal-lists-<?= $worksiteId ?>">
<input type="checkbox"
       id="ws-modal-lists-<?= $worksiteId ?>"
       name="show_in_worksite_lists"
       value="1"
       form="sfWorksiteBasicForm<?= $worksiteId ?>"
       class="sf-worksite-visibility-toggle"
       data-worksite-id="<?= $worksiteId ?>"
       data-field="show_in_worksite_lists"
       onchange="window.sfWorksiteEnableSave('sfWorksiteBasicForm<?= $worksiteId ?>')"
       <?= $showInLists ? 'checked' : '' ?>>
                                <div class="sf-ws-visibility-card-head">
                                    <p class="sf-ws-visibility-card-title">
                                        <?= htmlspecialchars(sf_term('settings_worksites_toggle_lists_short', $currentUiLang) ?? 'Työmaalista', ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                    <span class="sf-ws-visibility-switch-wrap" aria-hidden="true">
                                        <span class="sf-ws-visibility-switch-status">
                                            <span class="is-on">ON ●</span>
                                            <span class="is-off">OFF</span>
                                        </span>
                                        <span class="sf-ws-visibility-switch"><span class="sf-ws-visibility-switch-thumb"></span></span>
                                    </span>
                                </div>
                                <p class="sf-worksite-help-text"><?= htmlspecialchars($visibilityListsDesc, ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="sf-worksite-help-text"><?= htmlspecialchars(sf_term('settings_worksites_visibility_lists_hint', $currentUiLang) ?? 'Esim: uuden safetyflashin lomake, Lista-välilehden Työmaa-suodatin.', ENT_QUOTES, 'UTF-8') ?></p>
                            </label>
                            <label class="sf-ws-visibility-card" for="ws-modal-displays-<?= $worksiteId ?>">
<input type="checkbox"
       id="ws-modal-displays-<?= $worksiteId ?>"
       name="show_in_display_targets"
       value="1"
       form="sfWorksiteBasicForm<?= $worksiteId ?>"
       class="sf-worksite-visibility-toggle"
       data-worksite-id="<?= $worksiteId ?>"
       data-field="show_in_display_targets"
       onchange="window.sfWorksiteEnableSave('sfWorksiteBasicForm<?= $worksiteId ?>')"
       <?= $showInDisplays ? 'checked' : '' ?>>
                                <div class="sf-ws-visibility-card-head">
                                    <p class="sf-ws-visibility-card-title">
                                        <?= htmlspecialchars(sf_term('settings_worksites_toggle_displays_short', $currentUiLang) ?? 'Näyttölista', ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                    <span class="sf-ws-visibility-switch-wrap" aria-hidden="true">
                                        <span class="sf-ws-visibility-switch-status">
                                            <span class="is-on">ON ●</span>
                                            <span class="is-off">OFF</span>
                                        </span>
                                        <span class="sf-ws-visibility-switch"><span class="sf-ws-visibility-switch-thumb"></span></span>
                                    </span>
                                </div>
                                <p class="sf-worksite-help-text"><?= htmlspecialchars($visibilityDisplaysDesc, ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="sf-worksite-help-text"><?= htmlspecialchars(sf_term('settings_worksites_visibility_displays_hint', $currentUiLang) ?? 'Esim: julkaistava safetyflash → Valitse näytöt.', ENT_QUOTES, 'UTF-8') ?></p>
                            </label>
                            <label class="sf-ws-visibility-card" for="ws-modal-default-display-<?= $worksiteId ?>">
<input type="checkbox"
       id="ws-modal-default-display-<?= $worksiteId ?>"
       name="is_default_display"
       value="1"
       form="sfWorksiteBasicForm<?= $worksiteId ?>"
       class="sf-worksite-visibility-toggle"
       data-worksite-id="<?= $worksiteId ?>"
       data-field="is_default_display"
       onchange="window.sfWorksiteEnableSave('sfWorksiteBasicForm<?= $worksiteId ?>')"
       <?= $isDefaultDisplay ? 'checked' : '' ?>>
                                <div class="sf-ws-visibility-card-head">
                                    <p class="sf-ws-visibility-card-title">
                                        <?= htmlspecialchars(sf_term('settings_worksites_default_display_label', $currentUiLang) ?? 'Oletuksena näyttölistalla', ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                    <span class="sf-ws-visibility-switch-wrap" aria-hidden="true">
                                        <span class="sf-ws-visibility-switch-status">
                                            <span class="is-on">ON ●</span>
                                            <span class="is-off">OFF</span>
                                        </span>
                                        <span class="sf-ws-visibility-switch"><span class="sf-ws-visibility-switch-thumb"></span></span>
                                    </span>
                                </div>
                                <p class="sf-worksite-help-text"><?= htmlspecialchars($defaultDisplayDesc, ENT_QUOTES, 'UTF-8') ?></p>
                            </label>
                        </div>
                    </section>

                    <section class="sf-ws-tab-panel"
                             id="<?= htmlspecialchars($panelDisplayId, ENT_QUOTES, 'UTF-8') ?>"
                             role="tabpanel"
                             aria-labelledby="<?= htmlspecialchars($tabDisplayId, ENT_QUOTES, 'UTF-8') ?>"
                             hidden>
                        <div class="sf-worksite-display-overview">
                            <div class="sf-worksite-display-card">
                                <span class="sf-worksite-summary-label">
                                    <?= htmlspecialchars(sf_term('settings_worksites_summary_active_playlists', $currentUiLang) ?? 'Aktiiviset ajolistat', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <div class="sf-worksite-display-count-row">
                                    <span class="sf-worksite-display-count"><?= htmlspecialchars((string)$flashCount, ENT_QUOTES, 'UTF-8') ?></span>

                                    <?php if ($playlistUrl !== ''): ?>
<button type="button"
        class="sf-btn sf-btn-outline-primary sf-worksite-playlist-btn sf-worksite-playlist-open"
        data-playlist-modal="#modalKatsoAjolista"
        data-playlist-url="<?= htmlspecialchars($playlistPreviewUrl, ENT_QUOTES, 'UTF-8') ?>"
        data-playlist-label="<?= htmlspecialchars($worksiteName, ENT_QUOTES, 'UTF-8') ?>"
        data-manager-url="<?= htmlspecialchars($playlistUrl, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars(sf_term('settings_worksites_col_playlist', $currentUiLang) ?? 'Ajolista', ENT_QUOTES, 'UTF-8') ?>
</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <h4><?= htmlspecialchars(sf_term('settings_worksites_col_api_key', $currentUiLang) ?? 'API-avain', ENT_QUOTES, 'UTF-8') ?></h4>

                        <?php if (!empty($ws['display_api_key'])): ?>
                            <div class="sf-worksite-copy-row">
                                <code id="sfWsApiKeyText<?= $worksiteId ?>" class="sf-worksite-code"><?= htmlspecialchars((string)$ws['display_api_key'], ENT_QUOTES, 'UTF-8') ?></code>
                                <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-copy-btn" data-copy-target="sfWsApiKeyText<?= $worksiteId ?>" data-copy-feedback="sfWsApiCopied<?= $worksiteId ?>">
                                    <?= htmlspecialchars(sf_term('btn_copy_api_key', $currentUiLang) ?? 'Kopioi', ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </div>
                            <span class="sf-copy-feedback" id="sfWsApiCopied<?= $worksiteId ?>"><?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>

                            <div class="sf-worksite-copy-row">
                                <code id="sfWsSlideshowUrl<?= $worksiteId ?>" class="sf-worksite-code"><?= htmlspecialchars($slideshowUrl, ENT_QUOTES, 'UTF-8') ?></code>
                                <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-copy-btn" data-copy-target="sfWsSlideshowUrl<?= $worksiteId ?>" data-copy-feedback="sfWsSlideshowCopied<?= $worksiteId ?>">
                                    <?= htmlspecialchars(sf_term('btn_copy', $currentUiLang) ?? 'Kopioi', ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </div>
                            <span class="sf-copy-feedback" id="sfWsSlideshowCopied<?= $worksiteId ?>"><?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>

                            <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-worksite-xibo-btn" data-modal-open="#xiboModal<?= $worksiteId ?>">
                                <?= htmlspecialchars(sf_term('xibo_col_heading', $currentUiLang) ?? 'Xibo-koodi', ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        <?php else: ?>
                            <p class="sf-worksite-info-callout">
                                <?= htmlspecialchars(sf_term('settings_worksites_no_api_key_detail', $currentUiLang) ?? 'Tällä työmaalla ei ole infonäyttöavainta. Avain luodaan automaattisesti, kun työmaa julkaistaan ensimmäisen kerran infonäytölle.', ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        <?php endif; ?>
                    </section>
                </div>
                <div class="sf-modal-footer sf-worksite-manage-footer sf-worksite-manage-footer-fixed">
<button type="submit"
        form="sfWorksiteBasicForm<?= $worksiteId ?>"
        class="sf-btn sf-btn-primary sf-worksite-save-btn"
        data-default-label="<?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>"
        data-saving-label="<?= htmlspecialchars(sf_term('settings_worksites_saving', $currentUiLang) ?? 'Tallennetaan', ENT_QUOTES, 'UTF-8') ?>"
        disabled>
    <span class="sf-worksite-save-spinner" aria-hidden="true"></span>
    <span class="sf-worksite-save-label"><?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?></span>
</button>

                    <button type="button" data-modal-close class="sf-btn sf-btn-secondary sf-worksite-close-btn">
                        <?= htmlspecialchars(sf_term('settings_worksites_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="sf-modal hidden" id="modalKatsoAjolista" role="dialog" aria-modal="true" aria-labelledby="modalKatsoAjolistaTitle">
    <div class="sf-modal-content sf-pm-preview-modal">
        <div class="sf-playlist-compact-head">
            <div class="sf-playlist-compact-title">
                <h3 id="modalKatsoAjolistaTitle">
                    <img src="<?= htmlspecialchars($baseUrl . '/assets/img/icons/display.svg', ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true">
                    <?= htmlspecialchars(sf_term('btn_view_playlist', $currentUiLang) ?? 'Katso ajolista', ENT_QUOTES, 'UTF-8') ?>
                    <span id="modalKatsoAjolistaLabel"></span>
                </h3>
                <p><?= htmlspecialchars((sf_term('settings_worksites_playlist_preview_hint', $currentUiLang) !== 'settings_worksites_playlist_preview_hint' ? sf_term('settings_worksites_playlist_preview_hint', $currentUiLang) : 'Esikatselu näyttää työmaan aktiivisen ajolistan.'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>

        <div class="sf-playlist-compact-toolbar">
            <a href="#"
               id="modalKatsoAjolistaManagerLink"
               class="sf-btn sf-btn-outline-primary sf-playlist-manager-link">
                <?= htmlspecialchars(sf_term('settings_worksites_open_playlist_manager', $currentUiLang) ?? 'Avaa ajolistan hallinta', ENT_QUOTES, 'UTF-8') ?>
            </a>

            <div class="sf-playlist-nav" id="sfPlaylistNav">
                <button type="button" id="btnPlaylistPrev" class="sf-playlist-nav-btn" aria-label="<?= htmlspecialchars(sf_term('btn_playlist_prev', $currentUiLang) ?? 'Edellinen', ENT_QUOTES, 'UTF-8') ?>">&#9664;</button>
                <span id="sfPlaylistCounter" class="sf-playlist-counter">&#x2013; / &#x2013;</span>
                <button type="button" id="btnPlaylistPause" class="sf-playlist-nav-btn" data-label-pause="<?= htmlspecialchars(sf_term('btn_playlist_pause', $currentUiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>" data-label-resume="<?= htmlspecialchars(sf_term('btn_playlist_resume', $currentUiLang) ?? 'Jatka', ENT_QUOTES, 'UTF-8') ?>" aria-pressed="false" aria-label="<?= htmlspecialchars(sf_term('btn_playlist_pause', $currentUiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>">&#9208;</button>
                <button type="button" id="btnPlaylistNext" class="sf-playlist-nav-btn" aria-label="<?= htmlspecialchars(sf_term('btn_playlist_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?>">&#9654;</button>
            </div>
        </div>

        <div class="sf-pm-preview-body">
            <iframe src="about:blank"
                    title="<?= htmlspecialchars(sf_term('btn_view_playlist', $currentUiLang) ?? 'Katso ajolista', ENT_QUOTES, 'UTF-8') ?>"
                    class="sf-pm-preview-iframe"
                    loading="lazy"></iframe>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($baseUrl . '/assets/js/display-playlist.js', ENT_QUOTES, 'UTF-8') ?>"></script>

<script>
(function () {
    'use strict';
	    window.sfWorksiteEnableSave = function (formId) {
        if (!formId) {
            return;
        }

        var saveButton = document.querySelector('.sf-worksite-save-btn[form="' + formId + '"]');

        if (!saveButton) {
            var form = document.getElementById(formId);
            saveButton = form ? form.querySelector('.sf-worksite-save-btn') : null;
        }

        if (!saveButton) {
            return;
        }

        saveButton.disabled = false;
        saveButton.removeAttribute('disabled');
    };

    var baseUrl = <?= json_encode(rtrim($baseUrl, '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var saveError = <?= json_encode(sf_term('save_error', $currentUiLang) ?? 'Tallennus epäonnistui', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var showingCountTemplate = <?= json_encode(sf_term('settings_worksites_showing_count', $currentUiLang) ?? 'Näytetään %d / %d työmaata', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var badgeListsOnText = <?= json_encode(sf_term('settings_worksites_badge_lists_on', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var badgeListsOffText = <?= json_encode(sf_term('settings_worksites_badge_lists_off', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var badgeDisplaysOnText = <?= json_encode(sf_term('settings_worksites_badge_displays_on', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var badgeDisplaysOffText = <?= json_encode(sf_term('settings_worksites_badge_displays_off', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function showError(message) {
        if (typeof window.sfToast === 'function') {
            window.sfToast('error', message || saveError);
        } else {
            alert(message || saveError);
        }
    }

    function getFocusableElements(modal) {
        if (!modal) return [];
        var all = modal.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])');
        return Array.prototype.slice.call(all).filter(function (el) {
            return el.offsetParent !== null;
        });
    }

    function initWorksiteTabs(modal) {
        if (!modal || modal.getAttribute('data-ws-tabs-init') === '1') return;
        var tabs = Array.prototype.slice.call(modal.querySelectorAll('[role="tab"]'));
        var panels = Array.prototype.slice.call(modal.querySelectorAll('.sf-ws-tab-panel[role="tabpanel"]'));
        if (tabs.length === 0 || panels.length === 0) return;

        function activateTab(tab, focus) {
            if (!tab) return;
            var controls = tab.getAttribute('aria-controls');
            tabs.forEach(function (item) {
                var isActive = item === tab;
                item.classList.toggle('is-active', isActive);
                item.setAttribute('aria-selected', isActive ? 'true' : 'false');
                item.setAttribute('tabindex', isActive ? '0' : '-1');
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.id !== controls;
            });
            if (focus) {
                tab.focus();
            }
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () {
                activateTab(tab, false);
            });
            tab.addEventListener('keydown', function (event) {
                var nextIndex = index;
                if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
                else if (event.key === 'Home') nextIndex = 0;
                else if (event.key === 'End') nextIndex = tabs.length - 1;
                else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    activateTab(tab, true);
                    return;
                } else {
                    return;
                }
                event.preventDefault();
                activateTab(tabs[nextIndex], true);
            });
        });

        modal.setAttribute('data-ws-tabs-init', '1');
        activateTab(tabs[0], false);
    }

    function resetWorksiteTabs(modal) {
        if (!modal) return;
        var firstTab = modal.querySelector('[role="tab"]');
        if (!firstTab) return;
        firstTab.click();
    }

    function openWorksiteModal(selector) {
        if (!selector) return;
        var modal = document.querySelector(selector);
        if (!modal) return;
        initWorksiteTabs(modal);
        resetWorksiteTabs(modal);
        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
        var focusable = getFocusableElements(modal);
        if (focusable.length > 0) {
            focusable[0].focus({ preventScroll: true });
        }
    }

    document.addEventListener('click', function (event) {
        var playlistButton = event.target.closest('.sf-worksite-playlist-open');
        if (playlistButton) {
            event.preventDefault();
            event.stopPropagation();

            var playlistModal = document.querySelector(playlistButton.getAttribute('data-playlist-modal'));
            if (!playlistModal) {
                return;
            }
			            if (playlistModal.parentElement !== document.body) {
                document.body.appendChild(playlistModal);
            }

            var iframe = playlistModal.querySelector('.sf-pm-preview-iframe');
            var label = playlistModal.querySelector('#modalKatsoAjolistaLabel');
            var counter = playlistModal.querySelector('#sfPlaylistCounter');
            var pauseButton = playlistModal.querySelector('#btnPlaylistPause');
            var playlistUrl = playlistButton.getAttribute('data-playlist-url') || '';
            var playlistLabel = playlistButton.getAttribute('data-playlist-label') || '';
            var managerUrl = playlistButton.getAttribute('data-manager-url') || '';
            var managerLink = playlistModal.querySelector('#modalKatsoAjolistaManagerLink');

            if (managerLink) {
                managerLink.setAttribute('href', managerUrl || '#');
                managerLink.classList.toggle('is-disabled', !managerUrl);
            }

            if (iframe && playlistUrl) {
                iframe.setAttribute('src', playlistUrl);
                iframe.setAttribute('title', playlistLabel || 'Ajolista');
            }

            if (label) {
                label.textContent = playlistLabel ? ' — ' + playlistLabel : '';
            }

            if (counter) {
                counter.textContent = '– / –';
            }

            if (pauseButton) {
                pauseButton.setAttribute('aria-pressed', 'false');
                pauseButton.textContent = '⏸';
            }

            document.querySelectorAll('.sf-worksite-manage-modal:not(.hidden)').forEach(function (openModal) {
                openModal.classList.add('hidden');
            });

            playlistModal.classList.remove('hidden');
            document.body.classList.add('sf-modal-open');
            return;
        }

        var modalTrigger = event.target.closest('.sf-worksite-row [data-modal-open]');
        if (modalTrigger) {
            event.preventDefault();
            openWorksiteModal(modalTrigger.getAttribute('data-modal-open'));
            return;
        }
        var row = event.target.closest('.sf-worksite-row');
        if (!row) return;
        if (event.target.closest('[data-no-row-click]')) return;
        openWorksiteModal(row.getAttribute('data-modal'));
    });

    document.addEventListener('keydown', function (event) {
        var row = event.target.closest('.sf-worksite-row');
        if (row && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            if (!event.target.closest('[data-no-row-click]')) {
                openWorksiteModal(row.getAttribute('data-modal'));
            }
        }

        if (event.key !== 'Tab') return;
        var modal = document.querySelector('.sf-worksite-manage-modal:not(.hidden)');
        if (!modal) return;
        var focusable = getFocusableElements(modal);
        if (focusable.length === 0) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

function sfMarkWorksiteFormDirty(field) {
    var form = field.closest('.sf-worksite-dirty-form');

    if (!form && field.getAttribute('form')) {
        form = document.getElementById(field.getAttribute('form'));
    }

    if (!form || !form.classList.contains('sf-worksite-dirty-form')) {
        return;
    }

    var saveButton = null;

    if (form.id) {
        saveButton = document.querySelector('.sf-worksite-save-btn[form="' + form.id + '"]');
    }

    if (!saveButton) {
        saveButton = form.querySelector('.sf-worksite-save-btn');
    }

    if (saveButton) {
        saveButton.disabled = false;
    }
}

    var searchInput = document.getElementById('sfWorksiteSearch');
    var searchClear = document.getElementById('sfWorksiteSearchClear');
    var list = document.getElementById('sfWorksiteList');
    var showingCount = document.getElementById('sfWorksiteShowingCount');
    if (!list) return;

    document.querySelectorAll('.sf-worksite-manage-modal').forEach(function (modal) {
        initWorksiteTabs(modal);
    });

    var rows = Array.prototype.slice.call(list.querySelectorAll('.sf-worksite-row'));
    var chips = Array.prototype.slice.call(document.querySelectorAll('.sf-filter-chip'));
    var activeFilter = 'all';

    function matchesFilter(row, filter) {
        if (filter === 'active') return row.getAttribute('data-active') === '1';
        if (filter === 'inactive') return row.getAttribute('data-active') === '0';
        if (filter === 'lists') return row.getAttribute('data-lists') === '1';
        if (filter === 'displays') return row.getAttribute('data-displays') === '1';
        return true;
    }

    function formatShowingCount(visible, total) {
        var index = 0;
        return showingCountTemplate.replace(/%d/g, function () {
            index += 1;
            return String(index === 1 ? visible : total);
        });
    }

    function applyFilters() {
        var term = ((searchInput && searchInput.value) || '').toLowerCase().trim();
        if (searchClear) {
            searchClear.style.display = term !== '' ? '' : 'none';
        }
        rows.forEach(function (row) {
            var name = row.getAttribute('data-name') || '';
            var matchesSearch = term === '' || name.indexOf(term) !== -1;
            var matchesChip = matchesFilter(row, activeFilter);
            var isVisible = matchesSearch && matchesChip;
            row.style.display = isVisible ? '' : 'none';
            row.hidden = !isVisible;
            row.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
        });
        if (showingCount) {
            var visibleCount = rows.filter(function (row) {
                return row.style.display !== 'none';
            }).length;
            showingCount.textContent = formatShowingCount(visibleCount, rows.length);
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    if (searchClear) {
        searchClear.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            applyFilters();
        });
    }

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            chips.forEach(function (btn) { btn.classList.remove('is-active'); });
            chip.classList.add('is-active');
            activeFilter = chip.getAttribute('data-filter') || 'all';
            applyFilters();
        });
    });

    function initDirtyForms() {
        function getSaveButton(form) {
            if (form && form.id) {
                var externalButton = document.querySelector('.sf-worksite-save-btn[form="' + form.id + '"]');
                if (externalButton) {
                    return externalButton;
                }
            }

            return form ? form.querySelector('.sf-worksite-save-btn') : null;
        }

        function getDirtyFormFromField(field) {
            if (!field) {
                return null;
            }

            var form = field.closest('.sf-worksite-dirty-form');

            if (!form && field.getAttribute('form')) {
                form = document.getElementById(field.getAttribute('form'));
            }

            return form && form.classList.contains('sf-worksite-dirty-form') ? form : null;
        }

        function updateActiveSwitchLabel(form) {
            var activeSwitch = form.querySelector('.sf-worksite-active-switch');
            var activeInput = form.querySelector('.sf-worksite-active-switch input[type="checkbox"]');
            var activeText = form.querySelector('.sf-worksite-active-switch-text');

            if (!activeSwitch || !activeInput || !activeText) {
                return;
            }

            activeSwitch.classList.toggle('is-active', activeInput.checked);
            activeSwitch.classList.toggle('is-inactive', !activeInput.checked);
            activeText.textContent = activeInput.checked
                ? '<?= htmlspecialchars(sf_term('settings_worksites_status_active', $currentUiLang) ?? 'Aktiivinen', ENT_QUOTES, 'UTF-8') ?>'
                : '<?= htmlspecialchars(sf_term('settings_worksites_status_inactive', $currentUiLang) ?? 'Passiivinen', ENT_QUOTES, 'UTF-8') ?>';
        }

        function markDirty(form) {
            var saveButton = getSaveButton(form);

            if (!saveButton) {
                return;
            }

            saveButton.disabled = false;
            saveButton.removeAttribute('disabled');
        }

        function setSaving(saveButton, isSaving) {
            var label = saveButton.querySelector('.sf-worksite-save-label');

            saveButton.classList.toggle('is-saving', isSaving);
            saveButton.toggleAttribute('aria-busy', isSaving);

            if (isSaving) {
                saveButton.disabled = true;

                if (label) {
                    label.textContent = saveButton.getAttribute('data-saving-label') || 'Tallennetaan';
                }

                return;
            }

            saveButton.disabled = true;

            if (label) {
                label.textContent = saveButton.getAttribute('data-default-label') || '<?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>';
            }
        }

        function showSuccess(message) {
            if (typeof window.sfToast === 'function') {
                window.sfToast('success', message || 'Tallennettu');
                return;
            }

            alert(message || 'Tallennettu');
        }

        document.querySelectorAll('.sf-worksite-dirty-form').forEach(function (form) {
            var saveButton = getSaveButton(form);

            if (!saveButton) {
                return;
            }

            saveButton.disabled = true;
            updateActiveSwitchLabel(form);

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                event.stopPropagation();

                setSaving(saveButton, true);

                fetch(form.getAttribute('action') || form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: new FormData(form)
                })
                    .then(function (response) {
                        return response.json().then(function (json) {
                            return {
                                ok: response.ok,
                                data: json
                            };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok || !result.data || result.data.ok === false) {
                            throw new Error((result.data && (result.data.error || result.data.message)) || saveError);
                        }

                        setSaving(saveButton, false);
                        showSuccess(result.data.message || 'Tallennettu');
                    })
                    .catch(function (error) {
                        saveButton.classList.remove('is-saving');
                        saveButton.removeAttribute('aria-busy');
                        saveButton.disabled = false;

                        var label = saveButton.querySelector('.sf-worksite-save-label');

                        if (label) {
                            label.textContent = saveButton.getAttribute('data-default-label') || '<?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>';
                        }

                        showError(error && error.message ? error.message : saveError);
                    });
            });
        });

        document.addEventListener('input', function (event) {
            var form = getDirtyFormFromField(event.target);

            if (form) {
                markDirty(form);
            }
        });

        document.addEventListener('change', function (event) {
            var form = getDirtyFormFromField(event.target);

            if (!form) {
                return;
            }

            updateActiveSwitchLabel(form);
            markDirty(form);
        });
    }

    initDirtyForms();
    applyFilters();
})();
</script>
<?php endif; ?>

<!-- Add Worksite Modal -->
<div class="sf-modal hidden" id="modalAddWorksite" role="dialog" aria-modal="true" aria-labelledby="modalAddWorksiteTitle">
    <div class="sf-modal-content sf-worksite-manage-content sf-worksite-add-content">
        <div class="sf-modal-header">
            <h3 id="modalAddWorksiteTitle">
                <?= htmlspecialchars(sf_term('settings_worksites_add_modal_title', $currentUiLang) ?? 'Lisää uusi työmaa', ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>

        <form method="post" action="app/actions/worksites_save.php" data-sf-ajax="1" id="formAddWorksite" class="sf-worksite-add-form">
            <div class="sf-worksite-manage-body">
                <input type="hidden" name="form_action" value="add">
                <input type="hidden" name="has_visibility_fields" value="1">
                <?= sf_csrf_field() ?>

                <div class="sf-worksite-direct-edit">
                    <h4><?= htmlspecialchars(sf_term('settings_worksites_basic_info', $currentUiLang) ?? 'Perustiedot', ENT_QUOTES, 'UTF-8') ?></h4>

                    <div class="sf-worksite-direct-edit-grid">
                        <div class="sf-worksite-direct-field">
                            <label for="ws-name">
                                <?= htmlspecialchars(sf_term('settings_worksites_col_name', $currentUiLang) ?? 'Nimi', ENT_QUOTES, 'UTF-8') ?>
                            </label>
                            <div class="sf-worksite-name-edit-wrap">
                                <input type="text"
                                       id="ws-name"
                                       name="name"
                                       required
                                       class="sf-input sf-worksite-name-input"
                                       autocomplete="off">
                                <img src="<?= htmlspecialchars($baseUrl . '/assets/img/icons/edit.svg', ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true">
                            </div>
                        </div>

                        <div class="sf-worksite-direct-field">
                            <label for="ws-site-type">
                                <?= htmlspecialchars(sf_term('settings_worksites_site_type', $currentUiLang) ?? 'Työmaan tyyppi', ENT_QUOTES, 'UTF-8') ?>
                            </label>
                            <select id="ws-site-type" name="site_type" class="sf-select">
                                <option value=""><?= htmlspecialchars(sf_term('site_type_unspecified', $currentUiLang) ?? 'Määrittämätön', ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="tunnel"><?= htmlspecialchars(sf_term('site_type_tunnel', $currentUiLang) ?? 'Tunnelityömaa', ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="opencast"><?= htmlspecialchars(sf_term('site_type_opencast', $currentUiLang) ?? 'Avolouhos', ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="other"><?= htmlspecialchars(sf_term('site_type_other', $currentUiLang) ?? 'Muut toimipisteet', ENT_QUOTES, 'UTF-8') ?></option>
                            </select>
                        </div>

                        <div class="sf-worksite-direct-field">
                            <label for="ws-country-code">
                                <?= htmlspecialchars(sf_term('settings_worksite_country_label', $currentUiLang) ?? 'Maa', ENT_QUOTES, 'UTF-8') ?>
                            </label>
                            <select id="ws-country-code" name="country_code" class="sf-select">
                                <option value=""><?= htmlspecialchars(sf_term('settings_worksite_country_unspecified', $currentUiLang) ?? 'Ei määritetty', ENT_QUOTES, 'UTF-8') ?></option>
                                <?php foreach ($worksiteCountries as $countryCode => $countryLabel): ?>
                                    <option value="<?= htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($countryLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sf-worksite-direct-field">
                            <label>
                                <?= htmlspecialchars(sf_term('settings_worksite_supported_languages_label', $currentUiLang) ?? 'Tuetut julkaisukielet', ENT_QUOTES, 'UTF-8') ?>
                            </label>
                            <div class="sf-worksite-language-checks">
                                <?php foreach ($publishLanguages as $languageCode => $languageLabel): ?>
                                    <label class="sf-worksite-language-check">
                                        <input type="checkbox"
                                               name="supported_publish_languages[]"
                                               value="<?= htmlspecialchars($languageCode, ENT_QUOTES, 'UTF-8') ?>"
                                               <?= $languageCode === 'fi' ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($languageLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="sf-worksite-help-text">
                                <?= htmlspecialchars(sf_term('settings_worksite_supported_languages_help', $currentUiLang) ?? 'Näitä kieliversioita voidaan julkaista tämän maan infonäytöille.', ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                        </div>
                    </div>
                </div>

                <div class="sf-worksite-add-visibility">
                    <h4><?= htmlspecialchars(sf_term('settings_worksites_visibility_heading', $currentUiLang) ?? 'Näkyvyys', ENT_QUOTES, 'UTF-8') ?></h4>

                    <p class="sf-worksite-visibility-tip">
                        <img src="<?= htmlspecialchars($baseUrl . '/assets/img/icons/check.svg', ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true">
                        <span><?= htmlspecialchars(sf_term('settings_worksites_visibility_tip', $currentUiLang) ?? 'Jos kohde on vain infonäyttö, pidä näyttölista käytössä ja poista työmaalista käytöstä.', ENT_QUOTES, 'UTF-8') ?></span>
                    </p>

                    <div class="sf-worksite-modal-visibility">
                        <label class="sf-ws-visibility-card" for="ws-show-in-lists">
                            <input type="checkbox" id="ws-show-in-lists" name="show_in_worksite_lists" value="1" checked>
                            <div class="sf-ws-visibility-card-head">
                                <p class="sf-ws-visibility-card-title">
                                    <?= htmlspecialchars(sf_term('settings_worksites_show_in_lists_label', $currentUiLang) ?? 'Työmaalista', ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <span class="sf-ws-visibility-switch-wrap" aria-hidden="true">
                                    <span class="sf-ws-visibility-switch-status">
                                        <span class="is-on">ON</span>
                                        <span class="is-off">OFF</span>
                                    </span>
                                    <span class="sf-ws-visibility-switch"><span class="sf-ws-visibility-switch-thumb"></span></span>
                                </span>
                            </div>
                            <p class="sf-worksite-help-text"><?= htmlspecialchars($visibilityListsDesc, ENT_QUOTES, 'UTF-8') ?></p>
                        </label>

                        <label class="sf-ws-visibility-card" for="ws-show-in-displays">
                            <input type="checkbox" id="ws-show-in-displays" name="show_in_display_targets" value="1" checked>
                            <div class="sf-ws-visibility-card-head">
                                <p class="sf-ws-visibility-card-title">
                                    <?= htmlspecialchars(sf_term('settings_worksites_show_in_displays_label', $currentUiLang) ?? 'Näyttölista', ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <span class="sf-ws-visibility-switch-wrap" aria-hidden="true">
                                    <span class="sf-ws-visibility-switch-status">
                                        <span class="is-on">ON</span>
                                        <span class="is-off">OFF</span>
                                    </span>
                                    <span class="sf-ws-visibility-switch"><span class="sf-ws-visibility-switch-thumb"></span></span>
                                </span>
                            </div>
                            <p class="sf-worksite-help-text"><?= htmlspecialchars($visibilityDisplaysDesc, ENT_QUOTES, 'UTF-8') ?></p>
                        </label>

                        <label class="sf-ws-visibility-card" for="ws-is-default-display">
                            <input type="checkbox" id="ws-is-default-display" name="is_default_display" value="1">
                            <div class="sf-ws-visibility-card-head">
                                <p class="sf-ws-visibility-card-title">
                                    <?= htmlspecialchars(sf_term('settings_worksites_default_display_label', $currentUiLang) ?? 'Valittu oletuksena infonäytöissä', ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <span class="sf-ws-visibility-switch-wrap" aria-hidden="true">
                                    <span class="sf-ws-visibility-switch-status">
                                        <span class="is-on">ON</span>
                                        <span class="is-off">OFF</span>
                                    </span>
                                    <span class="sf-ws-visibility-switch"><span class="sf-ws-visibility-switch-thumb"></span></span>
                                </span>
                            </div>
                            <p class="sf-worksite-help-text"><?= htmlspecialchars($defaultDisplayDesc, ENT_QUOTES, 'UTF-8') ?></p>
                        </label>
                    </div>
                </div>
            </div>

            <div class="sf-worksite-manage-footer-fixed">
                <button type="button" data-modal-close class="sf-btn sf-worksite-close-btn">
                    <?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-worksite-save-btn">
                    <?= htmlspecialchars(sf_term('settings_worksites_add_button', $currentUiLang) ?? 'Lisää uusi työmaa', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php
// Xibo modals - one per worksite that has an API key
foreach ($worksites as $ws):
    if (empty($ws['display_api_key'])) continue;
    $xiboKey = $ws['display_api_key'];
    $xiboLabel = htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8');
    $xiboWsId = (int)$ws['id'];
    $playlistBase = rtrim($baseUrl ?? '', '/') . '/app/api/display_playlist.php';
    $htmlUrl = $playlistBase . '?key=' . urlencode($xiboKey) . '&format=html';
    $jsonUrl = $playlistBase . '?key=' . urlencode($xiboKey);
    $jsonApiUrl = $jsonUrl . '&format=json';
    $embeddedHtml = '<div id="sf-slideshow">' . "\n"
        . '  <div id="sf-slide" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#1a1a2e;">' . "\n"
        . '    <p style="color:#aaa;font-size:1.5em;">Ladataan&#8230;</p>' . "\n"
        . '  </div>' . "\n"
        . '</div>' . "\n\n"
        . '<script>' . "\n"
        . '(function(){' . "\n"
        . '  var API_URL = "' . $jsonApiUrl . '";' . "\n"
        . '  var REFRESH_MIN = 5;' . "\n"
        . '  var container = document.getElementById("sf-slide");' . "\n"
        . '  var current = 0, items = [], timer = null;' . "\n\n"
        . '  function setXiboDuration(s){' . "\n"
        . '    if(typeof xiboIC!=="undefined"&&xiboIC.setDuration) xiboIC.setDuration(s);' . "\n"
        . '  }' . "\n\n"
        . '  function expireXibo(){' . "\n"
        . '    if(typeof xiboIC!=="undefined"&&xiboIC.expireNow) xiboIC.expireNow();' . "\n"
        . '  }' . "\n\n"
        . '  function exitShow(){' . "\n"
        . '    var TRIGGER_CODE = \'menes_nyt\';' . "\n"
        . '    fetch(\'/trigger\', {' . "\n"
        . '      method: \'POST\',' . "\n"
        . '      headers: { \'Content-Type\': \'application/json\' },' . "\n"
        . '      body: JSON.stringify({ trigger: TRIGGER_CODE })' . "\n"
        . '    })' . "\n"
        . '    .then(function(r){ console.log(\'Webhook JSON POST status:\', r.status); })' . "\n"
        . '    .catch(function(err){ console.error(\'Webhook JSON POST error:\', err); });' . "\n"
        . '    expireXibo();' . "\n"
        . '  }' . "\n\n"
        . '  function load(){' . "\n"
        . '    var xhr = new XMLHttpRequest();' . "\n"
        . '    xhr.open("GET", API_URL, true);' . "\n"
        . '    xhr.onload = function(){' . "\n"
        . '      if(xhr.status===200){' . "\n"
        . '        try {' . "\n"
        . '          var data = JSON.parse(xhr.responseText);' . "\n"
        . '          if(data.ok && data.items && data.items.length > 0){' . "\n"
        . '            startSlideshow(data.items);' . "\n"
        . '          } else {' . "\n"
        . '            showEmpty(data.fallback_image || null);' . "\n"
        . '          }' . "\n"
        . '        } catch(e){ showError(); }' . "\n"
        . '      } else { showError(); }' . "\n"
        . '    };' . "\n"
        . '    xhr.onerror = function(){ showError(); };' . "\n"
        . '    xhr.send();' . "\n"
        . '  }' . "\n\n"
        . '  function startSlideshow(list){' . "\n"
        . '    items = list; current = 0; clearTimeout(timer);' . "\n"
        . '    var total = 0;' . "\n"
        . '    for(var i=0;i<items.length;i++) total += (items[i].duration_seconds||30);' . "\n"
        . '    setXiboDuration(total);' . "\n"
        . '    preload(function(){ showSlide(); });' . "\n"
        . '  }' . "\n\n"
        . '  function preload(cb){' . "\n"
        . '    var n=0, t=items.length;' . "\n"
        . '    if(!t){cb();return;}' . "\n"
        . '    for(var i=0;i<t;i++){var img=new Image();img.onload=img.onerror=function(){n++;if(n>=t)cb();};img.src=items[i].image_url;}' . "\n"
        . '    setTimeout(function(){if(n<t)cb();},8000);' . "\n"
        . '  }' . "\n\n"
        . '  function showSlide(){' . "\n"
        . '    if(!items.length) return;' . "\n"
        . '    var item = items[current];' . "\n"
        . '    container.innerHTML =' . "\n"
        . '      \'<img src="\' + item.image_url + \'" alt="" style="max-width:100%;max-height:100%;object-fit:contain;">\';' . "\n"
        . '    var dur = (item.duration_seconds || 10) * 1000;' . "\n"
        . '    if(current === items.length - 1){' . "\n"
        . '      clearTimeout(timer);' . "\n"
        . '      timer = setTimeout(exitShow, dur);' . "\n"
        . '    } else {' . "\n"
        . '      clearTimeout(timer);' . "\n"
        . '      timer = setTimeout(function(){' . "\n"
        . '        current = (current + 1) % items.length;' . "\n"
        . '        showSlide();' . "\n"
        . '      }, dur);' . "\n"
        . '    }' . "\n"
        . '  }' . "\n\n"
        . '  function showEmpty(fallbackUrl){' . "\n"
        . '    if(fallbackUrl){' . "\n"
        . '      setXiboDuration(5);' . "\n"
        . '      container.innerHTML =' . "\n"
        . '        \'<img src="\' + fallbackUrl + \'" alt="" style="max-width:100%;max-height:100%;object-fit:contain;">\';' . "\n"
        . '      setTimeout(expireXibo, 5000);' . "\n"
        . '    } else {' . "\n"
        . '      setXiboDuration(1);' . "\n"
        . '      container.innerHTML = "";' . "\n"
        . '      document.body.style.background = "transparent";' . "\n"
        . '      expireXibo();' . "\n"
        . '    }' . "\n"
        . '  }' . "\n\n"
        . '  function showError(){' . "\n"
        . '    setXiboDuration(10);' . "\n"
        . '    container.innerHTML =' . "\n"
        . '      \'<p style="color:#f66;font-size:1.2em;text-align:center;">Yhteysvirhe</p>\';' . "\n"
        . '    setTimeout(expireXibo, 10000);' . "\n"
        . '  }' . "\n\n"
        . '  load();' . "\n"
        . '  setInterval(load, REFRESH_MIN * 60 * 1000);' . "\n"
        . '})();' . "\n"
        . '</script>';
    $embeddedCss = 'body, html {' . "\n"
        . '  margin: 0;' . "\n"
        . '  padding: 0;' . "\n"
        . '  width: 100%;' . "\n"
        . '  height: 100%;' . "\n"
        . '  overflow: hidden;' . "\n"
        . '  background: #1a1a2e;' . "\n"
        . '  font-family: -apple-system, "Segoe UI", sans-serif;' . "\n"
        . '}' . "\n\n"
        . '#sf-slideshow {' . "\n"
        . '  width: 100%;' . "\n"
        . '  height: 100%;' . "\n"
        . '}' . "\n\n"
        . '#sf-slide {' . "\n"
        . '  width: 100%;' . "\n"
        . '  height: 100%;' . "\n"
        . '  display: flex;' . "\n"
        . '  align-items: center;' . "\n"
        . '  justify-content: center;' . "\n"
        . '}' . "\n\n"
        . '#sf-slide img {' . "\n"
        . '  max-width: 100%;' . "\n"
        . '  max-height: 100%;' . "\n"
        . '  object-fit: contain;' . "\n"
        . '  animation: sf-fadein 0.6s ease;' . "\n"
        . '}' . "\n\n"
        . '@keyframes sf-fadein {' . "\n"
        . '  from { opacity: 0; }' . "\n"
        . '  to   { opacity: 1; }' . "\n"
        . '}';
?>
<div class="sf-modal hidden" id="xiboModal<?= $xiboWsId ?>" role="dialog" aria-modal="true" aria-labelledby="xiboModalTitle<?= $xiboWsId ?>">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3 id="xiboModalTitle<?= $xiboWsId ?>">
                <?= htmlspecialchars(sf_term('xibo_code_heading', $currentUiLang) ?? 'Xibo-integraatiokoodi', ENT_QUOTES, 'UTF-8') ?>
                — <?= $xiboLabel ?>
            </h3>
            <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>
        <div class="sf-modal-body" style="padding:1.25rem;">
            <p style="margin-bottom:1rem;color:var(--sf-text-secondary,#666);font-size:0.9rem;">
                <?= htmlspecialchars(sf_term('xibo_instructions', $currentUiLang) ?? 'Kopioi URL ja liitä se Xibo CMS:n Webpage-widgetin URL-kenttään', ENT_QUOTES, 'UTF-8') ?>
            </p>

            <div style="margin-bottom:1.25rem;">
                <strong style="display:block;margin-bottom:0.4rem;"><?= htmlspecialchars(sf_term('xibo_webpage_url_label', $currentUiLang) ?? 'Webpage Widget URL', ENT_QUOTES, 'UTF-8') ?></strong>
                <div style="display:flex;gap:0.5rem;align-items:stretch;">
                    <code id="xiboHtmlUrl<?= $xiboWsId ?>" style="flex:1;display:block;background:var(--sf-bg-secondary,#f5f5f5);padding:0.5rem 0.75rem;border-radius:4px;font-size:0.82rem;word-break:break-all;"><?= htmlspecialchars($htmlUrl, ENT_QUOTES, 'UTF-8') ?></code>
                    <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-xibo-copy-btn" data-copy-target="xiboHtmlUrl<?= $xiboWsId ?>" data-ws-id="<?= $xiboWsId ?>-url">
                        <?= htmlspecialchars(sf_term('xibo_copy_url', $currentUiLang) ?? 'Kopioi URL', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
                <span id="xiboCopied<?= $xiboWsId ?>-url" style="display:none;color:green;font-size:0.85rem;margin-top:0.25rem;"><?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div style="margin-bottom:1.25rem;">
                <strong style="display:block;margin-bottom:0.25rem;"><?= htmlspecialchars(sf_term('xibo_embedded_html_label', $currentUiLang) ?? 'HTML-kenttä (Embedded Widget)', ENT_QUOTES, 'UTF-8') ?></strong>
                <p style="margin:0 0 0.5rem;color:var(--sf-text-secondary,#666);font-size:0.85rem;"><?= htmlspecialchars(sf_term('xibo_embedded_instructions', $currentUiLang) ?? 'Liitä HTML ja CSS Xibon Embedded Widget -kenttiin', ENT_QUOTES, 'UTF-8') ?></p>
                <pre id="xiboEmbedHtml<?= $xiboWsId ?>" style="background:var(--sf-bg-secondary,#f5f5f5);padding:0.5rem 0.75rem;border-radius:4px;font-size:0.78rem;overflow:auto;max-height:200px;white-space:pre-wrap;word-break:break-all;margin:0 0 0.4rem;"><code><?= htmlspecialchars($embeddedHtml, ENT_QUOTES, 'UTF-8') ?></code></pre>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-xibo-copy-btn" data-copy-target="xiboEmbedHtml<?= $xiboWsId ?>" data-ws-id="<?= $xiboWsId ?>-html">
                        <?= htmlspecialchars(sf_term('xibo_copy_html', $currentUiLang) ?? 'Kopioi HTML', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <span id="xiboCopied<?= $xiboWsId ?>-html" style="display:none;color:green;font-size:0.85rem;"><?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <strong style="display:block;margin-bottom:0.4rem;"><?= htmlspecialchars(sf_term('xibo_embedded_css_label', $currentUiLang) ?? 'CSS-kenttä (Embedded Widget)', ENT_QUOTES, 'UTF-8') ?></strong>
                <pre id="xiboEmbedCss<?= $xiboWsId ?>" style="background:var(--sf-bg-secondary,#f5f5f5);padding:0.5rem 0.75rem;border-radius:4px;font-size:0.78rem;overflow:auto;max-height:200px;white-space:pre-wrap;word-break:break-all;margin:0 0 0.4rem;"><code><?= htmlspecialchars($embeddedCss, ENT_QUOTES, 'UTF-8') ?></code></pre>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-xibo-copy-btn" data-copy-target="xiboEmbedCss<?= $xiboWsId ?>" data-ws-id="<?= $xiboWsId ?>-css">
                        <?= htmlspecialchars(sf_term('xibo_copy_css', $currentUiLang) ?? 'Kopioi CSS', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <span id="xiboCopied<?= $xiboWsId ?>-css" style="display:none;color:green;font-size:0.85rem;"><?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </div>
        <div class="sf-modal-footer" style="padding:1rem 1.25rem;text-align:right;">
            <button type="button" data-modal-close class="sf-btn sf-btn-secondary">
                <?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.sf-xibo-copy-btn, .sf-copy-btn');
        if (!btn) return;
        var targetId = btn.getAttribute('data-copy-target');
        var wsId = btn.getAttribute('data-ws-id');
        var feedbackId = btn.getAttribute('data-copy-feedback');
        var el = document.getElementById(targetId);
        if (!el) return;
        var text = el.textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showCopied(wsId, feedbackId);
            }).catch(function () {
                fallbackCopy(text, wsId, feedbackId);
            });
        } else {
            fallbackCopy(text, wsId, feedbackId);
        }
    });

    function fallbackCopy(text, wsId, feedbackId) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); } catch (err) {}
        document.body.removeChild(ta);
        showCopied(wsId, feedbackId);
    }

    function showCopied(wsId, feedbackId) {
        var msg = feedbackId ? document.getElementById(feedbackId) : document.getElementById('xiboCopied' + wsId);
        if (!msg) return;
        msg.style.display = 'inline';
        setTimeout(function () { msg.style.display = 'none'; }, 2000);
    }
})();
	document.addEventListener('click', function (event) {
    var toggleButton = event.target.closest('.sf-ws-inline-edit-toggle');
    var cancelButton = event.target.closest('.sf-ws-inline-edit-cancel');

    if (!toggleButton && !cancelButton) {
        return;
    }

    var targetSelector = (toggleButton || cancelButton).getAttribute('data-ws-edit-target');
    if (!targetSelector) {
        return;
    }

    var panel = document.querySelector(targetSelector);
    if (!panel) {
        return;
    }

    if (toggleButton) {
        panel.classList.toggle('hidden');

        if (!panel.classList.contains('hidden')) {
            var firstInput = panel.querySelector('input[type="text"], select, button');
            if (firstInput) {
                firstInput.focus();
            }
        }
    }

    if (cancelButton) {
        panel.classList.add('hidden');
    }
});
</script>