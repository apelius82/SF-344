<?php
// assets/pages/settings/tab_analytics.php
declare(strict_types=1);

$uiLang = $_SESSION['ui_lang'] ?? 'fi';
$user = sf_current_user();

if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    echo '<div class="sf-analytics-page"><div class="sf-analytics-empty">' .
        htmlspecialchars(sf_term('analytics_no_permission', $uiLang), ENT_QUOTES, 'UTF-8') .
        '</div></div>';
    return;
}

$analyticsRoles = [];

if (isset($mysqli) && $mysqli instanceof mysqli) {
    $rolesResult = $mysqli->query('SELECT id, name FROM sf_roles ORDER BY id ASC');

    if ($rolesResult) {
        while ($roleRow = $rolesResult->fetch_assoc()) {
            $analyticsRoles[] = $roleRow;
        }
    }
}
?>

<div class="sf-analytics-page sf-analytics-page--settings">
    <section class="sf-analytics-settings-header">
        <div>
            <p class="sf-settings-eyebrow"><?= htmlspecialchars(sf_term('analytics_eyebrow', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
            <h2><?= htmlspecialchars(sf_term('analytics_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="sf-settings-panel-lead"><?= htmlspecialchars(sf_term('analytics_subtitle', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="sf-analytics-controls">
            <div class="sf-analytics-range" aria-label="<?= htmlspecialchars(sf_term('analytics_period', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <button type="button" class="sf-analytics-range-btn active" data-period="year"><?= htmlspecialchars(sf_term('analytics_period_year', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="sf-analytics-range-btn" data-period="month"><?= htmlspecialchars(sf_term('analytics_period_month', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="sf-analytics-range-btn" data-period="30">30 <?= htmlspecialchars(sf_term('analytics_days_short', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="sf-analytics-range-btn" data-period="day"><?= htmlspecialchars(sf_term('analytics_period_day', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
            </div>

                        <div class="sf-analytics-date-control">
                <label for="sfAnalyticsDate"><?= htmlspecialchars(sf_term('analytics_select_date', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <input id="sfAnalyticsDate" class="sf-analytics-date-input" type="date" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="sf-analytics-period-controls">
                <select id="sfAnalyticsYear" class="sf-analytics-select" aria-label="<?= htmlspecialchars(sf_term('analytics_select_year', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <?php for ($year = (int)date('Y'); $year >= ((int)date('Y') - 3); $year--): ?>
                        <option value="<?= $year ?>"><?= $year ?></option>
                    <?php endfor; ?>
                </select>

                <?php
                $analyticsMonths = [
                    1  => sf_term('month_january', $uiLang),
                    2  => sf_term('month_february', $uiLang),
                    3  => sf_term('month_march', $uiLang),
                    4  => sf_term('month_april', $uiLang),
                    5  => sf_term('month_may', $uiLang),
                    6  => sf_term('month_june', $uiLang),
                    7  => sf_term('month_july', $uiLang),
                    8  => sf_term('month_august', $uiLang),
                    9  => sf_term('month_september', $uiLang),
                    10 => sf_term('month_october', $uiLang),
                    11 => sf_term('month_november', $uiLang),
                    12 => sf_term('month_december', $uiLang),
                ];
                ?>

                <select id="sfAnalyticsMonth" class="sf-analytics-select" aria-label="<?= htmlspecialchars(sf_term('analytics_select_month', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <option value=""><?= htmlspecialchars(sf_term('analytics_all_months', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>

                    <?php foreach ($analyticsMonths as $monthNumber => $monthName): ?>
                        <option value="<?= $monthNumber ?>" <?= $monthNumber === (int)date('n') ? 'selected' : '' ?>>
                            <?= htmlspecialchars($monthName, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sf-analytics-user-filter">
                <label for="sfAnalyticsUserFilter"><?= htmlspecialchars(sf_term('analytics_user_filter', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>

                <select id="sfAnalyticsUserFilter" class="sf-analytics-select" aria-label="<?= htmlspecialchars(sf_term('analytics_user_filter', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <option value="exclude_admins" selected><?= htmlspecialchars(sf_term('analytics_filter_without_admins', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="all"><?= htmlspecialchars(sf_term('analytics_filter_all_users', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="admins"><?= htmlspecialchars(sf_term('analytics_filter_admins_only', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>

                    <?php foreach ($analyticsRoles as $analyticsRole): ?>
                        <option value="role_<?= (int)$analyticsRole['id'] ?>">
                            <?= htmlspecialchars(sf_role_name((int)$analyticsRole['id'], (string)($analyticsRole['name'] ?? ''), $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>

    <section class="sf-analytics-kpis">
        <article class="sf-analytics-kpi">
            <span><?= htmlspecialchars(sf_term('analytics_active_today', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            <strong data-analytics-value="active_today">–</strong>
        </article>

        <article class="sf-analytics-kpi">
            <span><?= htmlspecialchars(sf_term('analytics_active_period', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            <strong data-analytics-value="active_period">–</strong>
        </article>

        <article class="sf-analytics-kpi">
            <span><?= htmlspecialchars(sf_term('analytics_page_views', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            <strong data-analytics-value="page_views">–</strong>
        </article>

        <article class="sf-analytics-kpi">
            <span><?= htmlspecialchars(sf_term('analytics_flash_views', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            <strong data-analytics-value="flash_views">–</strong>
        </article>

        <article class="sf-analytics-kpi">
            <span><?= htmlspecialchars(sf_term('analytics_pwa_install_rate', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            <strong data-analytics-value="pwa_install_rate">–</strong>
        </article>

        <article class="sf-analytics-kpi">
            <span><?= htmlspecialchars(sf_term('analytics_read_full_rate', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            <strong data-analytics-value="read_100_rate">–</strong>
        </article>

        <article class="sf-analytics-kpi">
            <span><?= htmlspecialchars(sf_term('analytics_avg_duration', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            <strong data-analytics-value="avg_duration_seconds">–</strong>
        </article>
    </section>

    <section class="sf-analytics-grid">
        <article class="sf-analytics-panel sf-analytics-panel-wide sf-analytics-interactions-panel">
            <div class="sf-analytics-panel-head">
                <div>
                    <h2><?= htmlspecialchars(sf_term('analytics_internal_clicks', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars(sf_term('analytics_internal_clicks_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>

            <div id="sfAnalyticsInternalClicks" class="sf-analytics-interactions"></div>

            <div class="sf-analytics-subsection-head">
                <h3><?= htmlspecialchars(sf_term('analytics_internal_click_users', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            </div>

            <div id="sfAnalyticsInternalClickUsers" class="sf-analytics-table sf-analytics-table-scroll sf-analytics-table-compact"></div>

            <div class="sf-analytics-subsection-head">
                <h3><?= htmlspecialchars(sf_term('analytics_copy_events', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            </div>

            <div id="sfAnalyticsCopyEvents" class="sf-analytics-table sf-analytics-table-scroll sf-analytics-table-compact"></div>
        </article>

        <article class="sf-analytics-panel">
            <div class="sf-analytics-panel-head">
                <h2><?= htmlspecialchars(sf_term('analytics_pwa_usage', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <canvas id="sfAnalyticsPwaChart" height="160"></canvas>
            <div id="sfAnalyticsPwaLegend" class="sf-analytics-legend"></div>

            <div class="sf-analytics-mini-stats">
                <div>
                    <span><?= htmlspecialchars(sf_term('analytics_pwa_users', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong data-analytics-value="pwa_users">–</strong>
                </div>
                <div>
                    <span><?= htmlspecialchars(sf_term('analytics_pwa_install_rate', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong data-analytics-value="pwa_install_rate">–</strong>
                </div>
            </div>
        </article>

        <article class="sf-analytics-panel">
            <div class="sf-analytics-panel-head">
                <h2><?= htmlspecialchars(sf_term('analytics_devices', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <div id="sfAnalyticsDevices" class="sf-analytics-bars"></div>
        </article>

        <article class="sf-analytics-panel">
            <div class="sf-analytics-panel-head">
                <h2><?= htmlspecialchars(sf_term('analytics_top_pages', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <div id="sfAnalyticsTopPages" class="sf-analytics-table"></div>
        </article>

        <article class="sf-analytics-panel sf-analytics-panel-push">
            <div class="sf-analytics-panel-head">
                <div>
                    <h2><?= htmlspecialchars(sf_term('analytics_push_notifications', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars(sf_term('analytics_push_notifications_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>

            <div class="sf-analytics-push-permissions">
                <div>
                    <span><?= htmlspecialchars(sf_term('analytics_push_permission_granted', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong data-analytics-value="push_permission_granted">–</strong>
                </div>
                <div>
                    <span><?= htmlspecialchars(sf_term('analytics_push_permission_denied', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong data-analytics-value="push_permission_denied">–</strong>
                </div>
                <div>
                    <span><?= htmlspecialchars(sf_term('analytics_push_permission_default', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong data-analytics-value="push_permission_default">–</strong>
                </div>
            </div>

            <div class="sf-analytics-push-summary-large">
                <div class="sf-analytics-push-summary-row">
                    <span><?= htmlspecialchars(sf_term('analytics_push_sent', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong data-analytics-value="push_sent">–</strong>
                </div>

                <div class="sf-analytics-push-summary-row">
                    <span><?= htmlspecialchars(sf_term('analytics_push_opened', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong data-analytics-value="push_opened">–</strong>
                </div>

                <div class="sf-analytics-push-summary-rate">
                    <span><?= htmlspecialchars(sf_term('analytics_push_open_rate', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong data-analytics-value="push_open_rate">–</strong>
                </div>
            </div>
        </article>

        <article class="sf-analytics-panel sf-analytics-panel-wide">
            <div class="sf-analytics-panel-head">
                <h2><?= htmlspecialchars(sf_term('analytics_top_flashes', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <div id="sfAnalyticsTopFlashes" class="sf-analytics-table"></div>
        </article>

        <article class="sf-analytics-panel">
            <div class="sf-analytics-panel-head">
                <h2><?= htmlspecialchars(sf_term('analytics_sources', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <div id="sfAnalyticsSources" class="sf-analytics-bars"></div>
        </article>

        <article class="sf-analytics-panel">
            <div class="sf-analytics-panel-head">
                <h2><?= htmlspecialchars(sf_term('analytics_worksites', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <div id="sfAnalyticsWorksites" class="sf-analytics-table"></div>
        </article>

        <article class="sf-analytics-panel sf-analytics-panel-wide">
            <div class="sf-analytics-panel-head">
                <div>
                    <h2><?= htmlspecialchars(sf_term('analytics_daily_activity', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars(sf_term('analytics_daily_activity_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <div id="sfAnalyticsDailyActivity" class="sf-analytics-table sf-analytics-table-scroll"></div>
        </article>

        <article class="sf-analytics-panel sf-analytics-panel-wide">
            <div class="sf-analytics-panel-head">
                <div>
                    <h2><?= htmlspecialchars(sf_term('analytics_user_activity', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars(sf_term('analytics_user_activity_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <div id="sfAnalyticsUserActivity" class="sf-analytics-table sf-analytics-table-scroll"></div>
        </article>
    </section>

    <div id="sfAnalyticsLoading" class="sf-analytics-loading" aria-live="polite">
        <div class="sf-analytics-loading-card">
            <span class="sf-analytics-loader"></span>
            <strong><?= htmlspecialchars(sf_term('analytics_loading', $uiLang), ENT_QUOTES, 'UTF-8') ?></strong>
            <p><?= htmlspecialchars(sf_term('analytics_loading_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
</div>

<script>
window.SF_ANALYTICS_I18N = {
    noData: <?= json_encode(sf_term('analytics_no_data', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    loading: <?= json_encode(sf_term('analytics_loading', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    error: <?= json_encode(sf_term('analytics_load_error', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    views: <?= json_encode(sf_term('analytics_views', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    users: <?= json_encode(sf_term('analytics_users', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    events: <?= json_encode(sf_term('analytics_events', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
	    clicks: <?= json_encode(sf_term('analytics_clicks', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    lastClick: <?= json_encode(sf_term('analytics_last_click', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    action: <?= json_encode(sf_term('analytics_action', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    userDevices: <?= json_encode(sf_term('analytics_user_devices', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    avgDuration: <?= json_encode(sf_term('analytics_avg_duration', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    readFull: <?= json_encode(sf_term('analytics_read_full', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    readFullRate: <?= json_encode(sf_term('analytics_read_full_rate', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    uniqueReaders: <?= json_encode(sf_term('analytics_unique_readers', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    date: <?= json_encode(sf_term('analytics_date', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    user: <?= json_encode(sf_term('analytics_user', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    role: <?= json_encode(sf_term('analytics_role', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    activeDays: <?= json_encode(sf_term('analytics_active_days', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    fullReads: <?= json_encode(sf_term('analytics_full_reads', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    pushOpens: <?= json_encode(sf_term('analytics_push_opens', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    lastActive: <?= json_encode(sf_term('analytics_last_active', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    uniqueFlashes: <?= json_encode(sf_term('analytics_unique_flashes', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    pwaUsed: <?= json_encode(sf_term('analytics_pwa_used', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    yes: <?= json_encode(sf_term('common_yes', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    no: <?= json_encode(sf_term('common_no', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    pwa: <?= json_encode(sf_term('analytics_pwa', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    browser: <?= json_encode(sf_term('analytics_browser', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
    pageLabels: {
        dashboard: <?= json_encode(sf_term('analytics_page_dashboard', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        analytics: <?= json_encode(sf_term('analytics_page_analytics', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        list: <?= json_encode(sf_term('analytics_page_list', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        view: <?= json_encode(sf_term('analytics_page_view', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        settings: <?= json_encode(sf_term('analytics_page_settings', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        feedback: <?= json_encode(sf_term('analytics_page_feedback', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        updates: <?= json_encode(sf_term('analytics_page_updates', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        form: <?= json_encode(sf_term('analytics_page_form', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        form_language: <?= json_encode(sf_term('analytics_page_form_language', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        profile: <?= json_encode(sf_term('analytics_page_profile', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        playlist_manager: <?= json_encode(sf_term('analytics_page_playlist_manager', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        users: <?= json_encode(sf_term('analytics_page_users', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        unknown: <?= json_encode(sf_term('analytics_unknown', $uiLang), JSON_UNESCAPED_UNICODE) ?>
    },
    sourceLabels: {
        push: <?= json_encode(sf_term('analytics_source_push', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        list: <?= json_encode(sf_term('analytics_source_list', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        list_thumb: <?= json_encode(sf_term('analytics_source_list_thumb', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        list_open_button: <?= json_encode(sf_term('analytics_source_list_button', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        dashboard: <?= json_encode(sf_term('analytics_source_dashboard', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        dashboard_waiting: <?= json_encode(sf_term('analytics_source_dashboard_waiting', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        dashboard_latest: <?= json_encode(sf_term('analytics_source_dashboard_latest', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        dashboard_injury_recent: <?= json_encode(sf_term('analytics_source_dashboard_injury', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        dashboard_monthly: <?= json_encode(sf_term('analytics_source_dashboard_monthly', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        direct: <?= json_encode(sf_term('analytics_source_direct', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        external_referrer: <?= json_encode(sf_term('analytics_source_external', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        referrer: <?= json_encode(sf_term('analytics_source_referrer', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        unknown: <?= json_encode(sf_term('analytics_unknown', $uiLang), JSON_UNESCAPED_UNICODE) ?>
    }
};
</script>