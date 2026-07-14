<?php
// Output buffering to ensure redirects work properly
ob_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/session_activity.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/settings.php';

// Varmista sessio ennen $_SESSION käyttöä
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base = rtrim($config['base_url'] ?? '/', '/');
$currentPage = $_GET['page'] ?? 'list';

$allowedPages = ['dashboard', 'list', 'form', 'form_language', 'view', 'users', 'settings', 'profile', 'role_categories', 'feedback', 'playlist_manager', 'updates'];
if (!in_array($currentPage, $allowedPages, true)) {
    $currentPage = 'list';
}

// nykyinen käyttäjä & rooli
$user    = sf_current_user();
$isAdmin = $user && (int)$user['role_id'] === 1;

$sfHeaderDisplayTargets = [];

try {
    $db = Database::getInstance();
    $stmt = $db->query("
        SELECT
            id,
            api_key,
            COALESCE(NULLIF(label, ''), NULLIF(site, ''), CONCAT('#', id)) AS display_name
        FROM sf_display_api_keys
        WHERE is_active = 1
        ORDER BY sort_order ASC, display_name ASC, id ASC
    ");
    $sfHeaderDisplayTargets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $sfHeaderDisplayTargets = [];
}

$sfFirstDisplayTarget = $sfHeaderDisplayTargets[0] ?? null;
$sfFirstPlaylistUrl = $sfFirstDisplayTarget
    ? $base . '/app/api/display_playlist.php?key=' . rawurlencode((string)$sfFirstDisplayTarget['api_key']) . '&format=html&preview=1'
    : '';

// --- Updates notification badge ---
$unreadUpdatesCount = 0;
if ($currentPage === 'updates') {
    // User is on the Updates page – persist the current timestamp per user in the DB
    if ($user) {
        try {
            $db = Database::getInstance();
            $db->prepare("UPDATE sf_users SET updates_last_seen_at = NOW() WHERE id = ?")
               ->execute([(int)$user['id']]);
        } catch (Exception $e) {
            // Silently ignore DB errors
        }
    }
    // Invalidate the session cache so the badge disappears immediately
    unset($_SESSION['sf_updates_badge_cache']);
} else {
    // Count published updates created after the user's last visit.
    // Cache the count in the session for 120 seconds to reduce DB load.
    $cachedBadge = $_SESSION['sf_updates_badge_cache'] ?? null;
    if ($cachedBadge !== null && (time() - (int)($cachedBadge['fetched'] ?? 0)) < 120) {
        $unreadUpdatesCount = (int)($cachedBadge['count'] ?? 0);
    } else {
        try {
            $db = Database::getInstance();
            if ($user) {
                $stmt = $db->prepare(
                    "SELECT COUNT(*) AS cnt
                     FROM sf_changelog
                     WHERE is_published = 1
                       AND created_at > COALESCE(
                             (SELECT updates_last_seen_at FROM sf_users WHERE id = ?),
                             '1970-01-01 00:00:00'
                           )"
                );
                $stmt->execute([(int)$user['id']]);
            } else {
                $stmt = $db->query("SELECT 0 AS cnt");
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $unreadUpdatesCount = (int)($row['cnt'] ?? 0);
            $_SESSION['sf_updates_badge_cache'] = ['fetched' => time(), 'count' => $unreadUpdatesCount];
        } catch (Exception $e) {
            // Silently ignore DB errors – badge simply won't show
        }
    }
}

// Tuetut kielet
$availableLangs = ['fi' => 'FI', 'sv' => 'SV', 'en' => 'EN', 'it' => 'IT', 'el' => 'EL'];

// Täydet kielten nimet modaalissa näytettäväksi
$langFullNames = [
    'fi' => 'Suomi',
    'sv' => 'Svenska', 
    'en' => 'English',
    'it' => 'Italiano',
    'el' => 'Ελληνικά',
];

// UI-kieli (sessio > cookie > fi)
$uiLang = $_SESSION['ui_lang'] ?? $_COOKIE['ui_lang'] ?? 'fi';
if (!array_key_exists($uiLang, $availableLangs)) {
    $uiLang = 'fi';
}

// Jos kieli annetaan GET-parametrilla (?lang=sv), tallenna se sessioon + cookieen ja siivoa URL
if (isset($_GET['lang']) && array_key_exists($_GET['lang'], $availableLangs)) {
    $newLang = (string)$_GET['lang'];
    $_SESSION['ui_lang'] = $newLang;

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    setcookie('ui_lang', $newLang, [
        'expires'  => time() + (365 * 24 * 60 * 60),
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Jos käyttäjä on kirjautunut, tallennetaan kielivalinta myös tietokantaan, jotta käyttöliittymä ja sähköpostit pysyvät synkassa
    if ($user && !empty($user['id'])) {
        try {
            $mysqli = sf_db();
            $stmt = $mysqli->prepare('UPDATE sf_users SET ui_lang = ? WHERE id = ?');
            if ($stmt) {
                $uid = (int)$user['id'];
                $stmt->bind_param('si', $newLang, $uid);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('Kielen tallennus tietokantaan epäonnistui: ' . $e->getMessage());
        }
    }

    // Poista lang-parametri URL:sta ja ohjaa takaisin samalle sivulle
    $uri = $_SERVER['REQUEST_URI'] ?? '/index.php?page=list';
    $parts = parse_url($uri);
    $path  = $parts['path'] ?? '/index.php';
    parse_str($parts['query'] ?? '', $q);
    unset($q['lang']);
    $clean = $path . (empty($q) ? '' : ('?' . http_build_query($q)));

    // Jos headerit on jo lähetetty (header.php include HTML:n jälkeen), tee JS-redirect
    if (!headers_sent()) {
        header('Location: ' . $clean);
        exit;
    }

    echo '<script>window.location.href = ' . json_encode($clean) . ';</script>';
    exit;
}
// --- Yleinen notifikaatiologiikka kaikille sivuille ---
$notice = $_GET['notice'] ?? '';

$noticeData = [
    'logged_in'         => ['msg_key' => 'notice_logged_in',         'type' => 'success'],
    'sent_review'       => ['msg_key' => 'notice_sent_review',       'type' => 'success'],
    'saved_draft'       => ['msg_key' => 'notice_saved_draft',       'type' => 'info'],
    'sent'              => ['msg_key' => 'notice_sent',              'type' => 'info'],
    'saved'             => ['msg_key' => 'notice_saved',             'type' => 'info'],
    'deleted'           => ['msg_key' => 'notice_deleted',           'type' => 'danger'],
    'published'         => ['msg_key' => 'notice_published',         'type' => 'success'],
    'to_comms'          => ['msg_key' => 'notice_to_comms',          'type' => 'info'],
    'comms_sent'        => ['msg_key' => 'notice_to_comms',          'type' => 'info'],
    'info_requested'    => ['msg_key' => 'notice_info_requested',    'type' => 'info'],
    'translation_saved' => ['msg_key' => 'notice_translation_saved', 'type' => 'success'],
    'user_created'      => ['msg_key' => 'notice_user_created',      'type' => 'success'],
    'user_updated'      => ['msg_key' => 'notice_user_updated',      'type' => 'info'],
    'user_deleted'      => ['msg_key' => 'notice_user_deleted',      'type' => 'danger'],
    'user_pass_reset'   => ['msg_key' => 'notice_user_pass_reset',   'type' => 'info'],
    'bulk_deleted'      => ['msg_key' => 'notice_bulk_deleted',      'type' => 'success'],

    // Worksites
    'worksite_added'    => ['msg_key' => 'worksite_added',    'type' => 'success'],
    'worksite_enabled'  => ['msg_key' => 'worksite_enabled',  'type' => 'success'],
    'worksite_disabled' => ['msg_key' => 'worksite_disabled', 'type' => 'info'],

    // Kuvapankki
    'image_added'       => ['msg_key' => 'notice_image_added',   'type' => 'success'],
    'image_deleted'     => ['msg_key' => 'notice_image_deleted', 'type' => 'success'],
    'image_toggled'     => ['msg_key' => 'notice_image_toggled', 'type' => 'info'],
];

$noticeConfig = $noticeData[$notice] ?? null;
$noticeType   = $noticeConfig['type'] ?? '';

// Erikoiskäsittely bulk_deleted – näytä poistettujen määrä
if ($notice === 'bulk_deleted' && isset($_GET['count'])) {
    $count = (int)$_GET['count'];
    $noticeText = str_replace('{count}', (string)$count, sf_term('notice_bulk_deleted', $uiLang));
} else {
    $noticeText = $noticeConfig ? sf_term($noticeConfig['msg_key'], $uiLang) : '';
}

// Onko notifikaatioparametreja URL:ssa?
$hasNoticeParams = isset($_GET['notice']) || isset($_GET['count']) || isset($_GET['deleted']) || isset($_GET['saved']) || isset($_GET['error']) || isset($_GET['success']);

// 🔒 Vaadi kirjautuminen ennen kuin mitään HTML:ää tulostetaan
sf_require_login();
sf_session_activity_tick(['is_api' => false, 'is_fetch' => false]);
?>

<?php if ($noticeText): ?>
<div class="sf-toast sf-toast-<?= htmlspecialchars($noticeType) ?>" id="sfToast">
    <div class="sf-toast-icon">
        <?php if ($noticeType === 'success'): ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        <?php elseif ($noticeType === 'danger'): ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9" x2="9" y2="15"></line>
                <line x1="9" y1="9" x2="15" y2="15"></line>
            </svg>
        <?php else: ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
        <?php endif; ?>
    </div>

    <span class="sf-toast-text"><?= htmlspecialchars($noticeText) ?></span>

    <button class="sf-toast-close" type="button"
            onclick="document.getElementById('sfToast').remove();">
        ×
    </button>
</div>

<script>
    setTimeout(function () {
        const toast = document.getElementById('sfToast');
        if (toast) {
            toast.classList.add('sf-toast-hide');
            setTimeout(() => toast.remove(), 300);
        }
    }, 4000);
</script>
<?php endif; ?>

<?php if ($hasNoticeParams): ?>
<script>
(function() {
    var params = ['notice', 'count', 'deleted', 'saved', 'created', 'updated', 'error', 'success', 'reset', 'msg'];
    var url = new URL(window.location.href);
    var changed = false;

    for (var i = 0; i < params.length; i++) {
        if (url.searchParams.has(params[i])) {
            url.searchParams.delete(params[i]);
            changed = true;
        }
    }

    if (changed) {
        history.replaceState(null, '', url.pathname + url.search);
    }
})();
</script>
<?php endif; ?>

<div class="sf-nav">
    <div class="sf-nav-inner">
        <div class="sf-nav-left">
            <a href="<?= htmlspecialchars($base) ?>/index.php?page=list" class="sf-brand-link">
                <img
                  src="<?= htmlspecialchars($base) ?>/assets/img/tapojarvi_logo.png"
                  alt="Tapojärvi Logo"
                  class="tapojarvi-logo-img"
                >
            </a>
        </div>

        <div class="sf-nav-center">
            <nav class="sf-nav-links-wrapper" aria-label="Päävalikko">
                <div class="sf-nav-links">

                    <a href="<?= htmlspecialchars($base) ?>/index.php?page=dashboard"
                       class="sf-nav-link <?= $currentPage === 'dashboard' ? 'sf-nav-active' : '' ?>"
                       data-tooltip="<?= htmlspecialchars(sf_term('nav_dashboard', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/dashboard.svg"
                             alt=""
                             class="sf-nav-link-icon"
                             aria-hidden="true">
                        <span><?= htmlspecialchars(sf_term('nav_dashboard', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </a>

                    <a href="<?= htmlspecialchars($base) ?>/index.php?page=list"
                       class="sf-nav-link <?= $currentPage === 'list' ? 'sf-nav-active' : '' ?>"
                       data-tooltip="<?= htmlspecialchars(sf_term('nav_list', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/list.svg"
                             alt=""
                             class="sf-nav-link-icon"
                             aria-hidden="true">
                        <span><?= htmlspecialchars(sf_term('nav_list', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </a>

                    <a href="<?= htmlspecialchars($base) ?>/index.php?page=form"
                       class="sf-nav-cta <?= $currentPage === 'form' ? 'sf-nav-cta-active' : '' ?>"
                       data-tooltip="<?= htmlspecialchars(sf_term('nav_new_safetyflash', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/add_new_icon.svg"
                             alt=""
                             class="sf-nav-cta-icon-img"
                             aria-hidden="true">
                        <span class="sf-nav-cta-text">
                            <?= htmlspecialchars(sf_term('nav_new_safetyflash', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </a>

                    <a href="<?= htmlspecialchars($base) ?>/index.php?page=feedback"
                       class="sf-nav-link <?= $currentPage === 'feedback' ? 'sf-nav-active' : '' ?>"
                       data-tooltip="<?= htmlspecialchars(sf_term('nav_feedback', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                                                <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/feedback.svg"
                             alt=""
                             class="sf-nav-link-icon"
                             aria-hidden="true">
                        <span><?= htmlspecialchars(sf_term('nav_feedback', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </a>

<?php if ($user && (int)$user['role_id'] === 1): ?>
<?php $settingsLabel = htmlspecialchars(sf_term('settings_heading', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
<a href="<?= htmlspecialchars($base) ?>/index.php?page=settings"
   class="sf-nav-link sf-nav-link-icon-only <?= $currentPage === 'settings' ? 'sf-nav-active' : '' ?>"
   aria-label="<?= $settingsLabel ?>"
   title="<?= $settingsLabel ?>"
   data-tooltip="<?= $settingsLabel ?>">
    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/settings.svg"
         alt=""
         class="sf-nav-link-icon"
         aria-hidden="true">
    <span><?= $settingsLabel ?></span>
</a>

<?php endif; ?>

<a href="#modalKatsoAjolista"
   class="sf-nav-link sf-nav-link-icon-only"
   data-modal-open="#modalKatsoAjolista"
   aria-label="<?= htmlspecialchars(sf_term('playlist_modal_title', $uiLang) ?? 'Infonäyttöjen ajolistat', ENT_QUOTES, 'UTF-8') ?>"
   title="<?= htmlspecialchars(sf_term('playlist_modal_title', $uiLang) ?? 'Infonäyttöjen ajolistat', ENT_QUOTES, 'UTF-8') ?>"
   data-tooltip="<?= htmlspecialchars(sf_term('playlist_modal_title', $uiLang) ?? 'Infonäyttöjen ajolistat', ENT_QUOTES, 'UTF-8') ?>">
    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/screen.svg"
         alt=""
         class="sf-nav-link-icon"
         aria-hidden="true">
    <span><?= htmlspecialchars(sf_term('playlist_modal_title', $uiLang) ?? 'Infonäytöt', ENT_QUOTES, 'UTF-8') ?></span>
</a>

                </div>
            </nav>
        </div>

        <div class="sf-nav-right">
            <!-- Updates Icon (always visible, including mobile) -->
            <a href="<?= htmlspecialchars($base) ?>/index.php?page=updates"
               class="sf-nav-updates-btn <?= $currentPage === 'updates' ? 'sf-nav-active' : '' ?>"
               aria-label="<?= htmlspecialchars(sf_term('nav_updates', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
               title="<?= htmlspecialchars(sf_term('nav_updates', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/changelog_icon.svg"
                     alt=""
                     class="sf-nav-updates-icon"
                     aria-hidden="true">
                <span class="sf-nav-updates-text"><?= htmlspecialchars(sf_term('nav_updates', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($unreadUpdatesCount > 0): ?>
                <span class="sf-nav-badge" aria-label="<?= htmlspecialchars(sf_term('updates_new_badge', $uiLang), ENT_QUOTES, 'UTF-8') ?>"><?= $unreadUpdatesCount ?></span>
                <?php endif; ?>
            </a>

			            <?php if ($user): ?>
                        <div class="sf-user-events"
                 id="sfUserEvents"
                 data-base-url="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>"
                 data-csrf-token="<?= htmlspecialchars(sf_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="button"
                        class="sf-user-events-btn"
                        id="sfUserEventsBtn"
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-label="<?= htmlspecialchars(sf_term('events_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars(sf_term('events_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/notification.svg"
                         alt=""
                         class="sf-user-events-icon"
                         aria-hidden="true">
                    <span class="sf-user-events-text"><?= htmlspecialchars(sf_term('events_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sf-nav-badge sf-user-events-badge hidden" id="sfUserEventsBadge">0</span>
                </button>

                <div class="sf-user-events-panel hidden" id="sfUserEventsPanel" role="dialog" aria-label="<?= htmlspecialchars(sf_term('events_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="sf-user-events-head">
                        <strong><?= htmlspecialchars(sf_term('events_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></strong>
                        <button type="button"
                                class="sf-user-events-mark-all hidden"
                                id="sfUserEventsMarkAll"
                                aria-label="<?= htmlspecialchars(sf_term('events_mark_all_read', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                                title="<?= htmlspecialchars(sf_term('events_mark_all_read', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                            <span aria-hidden="true">✓</span>
                            <span><?= htmlspecialchars(sf_term('events_mark_all_short', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                        </button>
                    </div>
                    <div class="sf-user-events-list" id="sfUserEventsList">
                        <div class="sf-user-events-empty"><?= htmlspecialchars(sf_term('events_empty', $uiLang), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="sf-lang-switcher" id="sfLangSwitcher">
                <?php
                // Define language flags mapping
                $langFlags = [
                    'fi' => 'finnish-flag.png',
                    'sv' => 'swedish-flag.png',
                    'en' => 'english-flag.png',
                    'it' => 'italian-flag.png',
                    'el' => 'greece-flag.png',
                ];
                
                // Default flag file
                $defaultFlagFile = 'finnish-flag.png';

                // Build language links for current URL (?lang=xx)
                $uri = $_SERVER['REQUEST_URI'] ?? '/index.php?page=list';
                $parts = parse_url($uri);
                $path  = $parts['path'] ?? '/index.php';
                parse_str($parts['query'] ?? '', $q);
                unset($q['lang']);
                
                // Get current language flag
                $currentFlagFile = $langFlags[$uiLang] ?? $defaultFlagFile;
                ?>
                
                <!-- Mobile: Single flag + chevron button that opens modal -->
                <button type="button" 
                        class="sf-lang-trigger" 
                        data-modal-open="#sfLanguageModal"
                        aria-label="<?= htmlspecialchars(sf_term('nav_language_select', $uiLang) ?? 'Valitse kieli', ENT_QUOTES, 'UTF-8') ?>">
                    <img
                        src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/<?= htmlspecialchars($currentFlagFile, ENT_QUOTES, 'UTF-8') ?>"
                        alt="<?= htmlspecialchars(strtoupper($uiLang), ENT_QUOTES, 'UTF-8') ?>"
                        class="sf-lang-trigger-flag"
                    >
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="sf-lang-trigger-chevron">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
                
                <!-- Desktop: Flag buttons -->
                <div class="sf-lang-desktop">
                    <?php foreach ($availableLangs as $code => $label):
                        $q['lang'] = $code;
                        $flagFile = $langFlags[$code] ?? 'finnish-flag.png';
                        $href = $path . '?' . http_build_query($q);
                    ?>
                        <a
                            href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
                            class="sf-lang-flag-btn <?= $uiLang === $code ? 'active' : '' ?>"
                            aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                            aria-pressed="<?= $uiLang === $code ? 'true' : 'false' ?>"
                            title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <img
                                src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/<?= htmlspecialchars($flagFile, ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                                class="sf-lang-flag-img"
                            >
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($user): ?>
                <!-- PWA Install Button (Mobile Only) -->
                <button type="button"
                   id="sf-install-btn"
                   class="hidden"
                   title="<?= htmlspecialchars(sf_term('pwa_install_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                   aria-label="<?= htmlspecialchars(sf_term('pwa_install_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                </button>

                <button type="button"
                   class="sf-user-info <?= $currentPage === 'profile' ? 'sf-user-active' : '' ?>"
                   title="<?= htmlspecialchars($user['email'] ?? '') ?>"
                   data-modal-open="modalProfile">
                    <span class="sf-user-name">
                        <?= htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?>
                    </span>

                    <svg class="sf-user-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </button>
                <button type="button"
                    class="sf-nav-logout-btn"
                    data-modal-open="#sfLogoutModal"
                    title="<?= htmlspecialchars(sf_term('logout_confirm_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars(sf_term('logout_confirm_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    <span class="sf-nav-logout-text"><?= htmlspecialchars(sf_term('logout_confirm_ok', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/system_notice.php'; ?>

<?php if ($user): ?>
    <div id="sfPwaInstallPrompt" class="sf-pwa-install-prompt hidden" aria-live="polite">
        <div class="sf-pwa-install-prompt-inner">
            <div class="sf-pwa-install-prompt-content">
                <strong><?= htmlspecialchars(sf_term('pwa_install_prompt_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars(sf_term('pwa_install_prompt_text', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="sf-pwa-install-prompt-actions">
                <button type="button" class="sf-pwa-install-prompt-primary" id="sfPwaInstallPromptOpen">
                    <?= htmlspecialchars(sf_term('pwa_install_prompt_button', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>

                <button type="button" class="sf-pwa-install-prompt-secondary" id="sfPwaInstallPromptDismiss">
                    <?= htmlspecialchars(sf_term('pwa_install_prompt_dismiss', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>
<!-- Display playlist preview modal -->
<div class="sf-modal hidden" id="modalKatsoAjolista" role="dialog" aria-modal="true" aria-labelledby="modalKatsoAjolistaTitle">
    <div class="sf-modal-content sf-pm-preview-modal">
        <div class="sf-playlist-compact-head">
            <div class="sf-playlist-compact-title">
                <h3 id="modalKatsoAjolistaTitle">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/display.svg" alt="" aria-hidden="true">
                    <?= htmlspecialchars(sf_term('playlist_modal_title', $uiLang) ?? 'Infonäyttöjen ajolistat', ENT_QUOTES, 'UTF-8') ?>
                </h3>
                <p>
                    <?= htmlspecialchars(sf_term('playlist_modal_description', $uiLang) ?? 'Valitse infonäyttö nähdäksesi ajolistan.', ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('btn_close', $uiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>

        <div class="sf-playlist-compact-toolbar">
            <?php if (!empty($sfHeaderDisplayTargets)): ?>
                <div class="sf-playlist-target-selector sf-playlist-target-selector-compact">
                    <label for="sfPlaylistTargetSelect">
                        <?= htmlspecialchars(sf_term('label_select_display', $uiLang) ?? 'Näyttö', ENT_QUOTES, 'UTF-8') ?>
                    </label>

                    <select id="sfPlaylistTargetSelect" class="sf-playlist-target-select">
                        <?php foreach ($sfHeaderDisplayTargets as $displayTarget): ?>
                            <?php
                            $targetLabel = (string)($displayTarget['display_name'] ?? '');
                            $targetApiKey = (string)($displayTarget['api_key'] ?? '');
                            $targetPlaylistUrl = $base . '/app/api/display_playlist.php?key=' . rawurlencode($targetApiKey) . '&format=html&preview=1';
                            ?>
                            <option
                                value="<?= htmlspecialchars($targetPlaylistUrl, ENT_QUOTES, 'UTF-8') ?>"
                                data-label="<?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="sf-playlist-meta-row">
                <span class="sf-playlist-meta-pill">
                    <?= htmlspecialchars(sf_term('playlist_duration_label', $uiLang) ?? 'Näyttöaika', ENT_QUOTES, 'UTF-8') ?>
                    <strong id="sfPlaylistMetaDuration">—</strong>
                </span>

                <span class="sf-playlist-meta-pill" id="sfPlaylistMetaExpiresWrap">
                    <?= htmlspecialchars(sf_term('playlist_valid_until_label', $uiLang) ?? 'Voimassa asti', ENT_QUOTES, 'UTF-8') ?>
                    <strong id="sfPlaylistMetaExpires">—</strong>
                </span>
            </div>

            <div class="sf-playlist-nav" id="sfPlaylistNav">
                <button type="button" id="btnPlaylistPrev" class="sf-playlist-nav-btn"
                    title="<?= htmlspecialchars(sf_term('btn_playlist_prev', $uiLang) ?? 'Edellinen', ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars(sf_term('btn_playlist_prev', $uiLang) ?? 'Edellinen', ENT_QUOTES, 'UTF-8') ?>">&#9664;</button>

                <span id="sfPlaylistCounter" class="sf-playlist-counter">&#x2013; / &#x2013;</span>

                <button type="button" id="btnPlaylistPause" class="sf-playlist-nav-btn"
                    data-label-pause="<?= htmlspecialchars(sf_term('btn_playlist_pause', $uiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>"
                    data-label-resume="<?= htmlspecialchars(sf_term('btn_playlist_resume', $uiLang) ?? 'Jatka', ENT_QUOTES, 'UTF-8') ?>"
                    title="<?= htmlspecialchars(sf_term('btn_playlist_pause', $uiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars(sf_term('btn_playlist_pause', $uiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>"
                    aria-pressed="false">&#x23F8;</button>

                <button type="button" id="btnPlaylistNext" class="sf-playlist-nav-btn"
                    title="<?= htmlspecialchars(sf_term('btn_playlist_next', $uiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="<?= htmlspecialchars(sf_term('btn_playlist_next', $uiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?>">&#9654;</button>
            </div>
        </div>

        <div class="sf-pm-preview-body">
            <?php if ($sfFirstPlaylistUrl !== ''): ?>
                <div class="sf-playlist-preview-frame">
                    <div class="sf-playlist-loader" aria-live="polite">
                        <span class="sf-playlist-loader-spinner" aria-hidden="true"></span>
                        <span><?= htmlspecialchars(sf_term('playlist_loading', $uiLang) ?? 'Ladataan ajolistaa…', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>

                    <iframe src="<?= htmlspecialchars($sfFirstPlaylistUrl, ENT_QUOTES, 'UTF-8') ?>"
                            title="<?= htmlspecialchars((string)($sfFirstDisplayTarget['display_name'] ?? 'Ajolista'), ENT_QUOTES, 'UTF-8') ?>"
                            class="sf-pm-preview-iframe"
                            sandbox="allow-scripts allow-same-origin"
                            loading="lazy"></iframe>
                </div>
            <?php else: ?>
                <div class="sf-empty-state">
                    <p><?= htmlspecialchars(sf_term('playlist_empty', $uiLang) ?? 'Aktiivisia infonäyttöjä ei löytynyt.', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<!-- Logout confirm modal -->
<div id="sfLogoutModal" class="sf-modal hidden sf-modal-small sf-modal-centered" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3 id="sfLogoutTitle">
                <?= htmlspecialchars(sf_term('logout_confirm_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>

        <div class="sf-modal-body">
            <p>
                <?= htmlspecialchars(sf_term('logout_confirm_text', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('logout_confirm_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>

            <a class="sf-btn sf-btn-danger" href="<?= htmlspecialchars($base) ?>/app/api/logout.php">
                <?= htmlspecialchars(sf_term('logout_confirm_ok', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
</div>

<!-- PWA Install Modal -->
<div id="sfInstallModal" class="sf-modal hidden sf-modal-small sf-modal-centered" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content sf-mobile-sheet-content">
        <div class="sf-bottom-sheet-handle" data-install-modal-drag-handle aria-hidden="true"></div>

        <div class="sf-modal-header" data-install-modal-drag-zone>
            <h3 id="sfInstallTitle">
                <?= htmlspecialchars(sf_term('pwa_install_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>

        <div class="sf-modal-body">
            <p id="sfInstallMessage">
                <?= htmlspecialchars(sf_term('pwa_install_message', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <p id="sfInstallMessageIOS" style="display: none;">
                <?= htmlspecialchars(sf_term('pwa_install_ios_message', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('pwa_install_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>

            <button type="button" class="sf-btn sf-btn-primary" id="sfInstallConfirm">
                <?= htmlspecialchars(sf_term('pwa_install_button', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- Language Selection Modal -->
<div id="sfLanguageModal" class="sf-modal hidden sf-modal-small sf-modal-centered" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3>
                <?= htmlspecialchars(sf_term('nav_language_select', $uiLang) ?? 'Valitse kieli / Select language', ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>

        <div class="sf-modal-body">
            <div class="sf-lang-modal-options">
                <?php 
                // Fixed order for modal to ensure consistent display
                $modalLangOrder = ['fi', 'sv', 'en', 'it', 'el'];
                
                // Create separate query array for modal to avoid mutating shared state
                // Query params are scalar values, so shallow copy is sufficient
                foreach ($modalLangOrder as $code): 
                    if (!isset($availableLangs[$code])) continue; // Skip if language not available
                    $label = $availableLangs[$code];
                    $modalQ = $q; // Copy the query array
                    $modalQ['lang'] = $code;
                    $flagFile = $langFlags[$code] ?? $defaultFlagFile;
                    $href = $path . '?' . http_build_query($modalQ);
                    $displayLabel = $langFullNames[$code] ?? $label; // Use full name for modal
                ?>
                    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" 
                       class="sf-lang-modal-option <?= $uiLang === $code ? 'active' : '' ?>">
                        <img
                            src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/<?= htmlspecialchars($flagFile, ENT_QUOTES, 'UTF-8') ?>"
                            alt="<?= htmlspecialchars($displayLabel, ENT_QUOTES, 'UTF-8') ?>"
                            class="sf-lang-modal-flag"
                        >
                        <span class="sf-lang-modal-label"><?= htmlspecialchars($displayLabel) ?></span>
                        <?php if ($uiLang === $code): ?>
                            <svg class="sf-lang-modal-check" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const m = document.getElementById("sfLogoutModal");
    if (m && m.parentElement !== document.body) {
        document.body.appendChild(m);
    }

    // Move profile modal to body so it shares the same stacking context as logout modal
    const profileModal = document.getElementById("modalProfile");
    if (profileModal && profileModal.parentElement !== document.body) {
        document.body.appendChild(profileModal);
    }

    // Move language modal to body for proper z-index
    const langModal = document.getElementById("sfLanguageModal");
    if (langModal && langModal.parentElement !== document.body) {
        document.body.appendChild(langModal);
    }
});
</script>

<?php if ($currentPage !== 'view'): ?>
<script src="<?= sf_asset_url('assets/js/display-playlist.js', $base) ?>"></script>
<?php endif; ?>

<?php if ($user): ?>
<script>
window.SF_SESSION = <?= json_encode([
    'baseUrl' => $base,
    'timeoutSeconds' => (int)($config['session']['timeout'] ?? 0),
    'warningSeconds' => (int)($config['session']['warning_before'] ?? 300),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="<?= htmlspecialchars($base) ?>/assets/js/session-inactivity.js?v=1"></script>
<script src="<?= htmlspecialchars($base) ?>/assets/js/user-events.js?v=1"></script>
<?php endif; ?>

<script>
window.SF_BASE_URL = <?php echo json_encode($base, JSON_UNESCAPED_SLASHES); ?>;
window.SF_NOTICE_MESSAGES = <?php
    echo json_encode([
        'worksite_added'   => sf_term('worksite_added', $uiLang),
        'worksite_enabled' => sf_term('worksite_enabled', $uiLang),
        'worksite_disabled'=> sf_term('worksite_disabled', $uiLang),
        'image_added'      => sf_term('notice_image_added', $uiLang),
        'image_deleted'    => sf_term('notice_image_deleted', $uiLang),
        'image_toggled'    => sf_term('notice_image_toggled', $uiLang),
        'published_direct' => sf_term('notice_published_direct', $uiLang),
        'error'            => sf_term('notice_error', $uiLang),
        'missing_fields'   => sf_term('notice_missing_fields', $uiLang),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>;

window.SF_I18N = <?php
    echo json_encode([
        // Toast & Processing messages
        'processing_flash' => sf_term('processing_flash', $uiLang),
        'processing_complete' => sf_term('processing_complete', $uiLang),
        'processing_failed' => sf_term('processing_failed', $uiLang),
        'saving_flash' => sf_term('saving_flash', $uiLang),
        'generating_preview' => sf_term('generating_preview', $uiLang),
        'sending_for_review' => sf_term('sending_for_review', $uiLang),
        'processing_continues' => sf_term('processing_continues', $uiLang),
        'data_received_processing' => sf_term('data_received_processing', $uiLang),
        'please_wait' => sf_term('please_wait', $uiLang),
        'save_failed' => sf_term('save_failed', $uiLang),
        'draft_saved' => sf_term('draft_saved', $uiLang),
        'saving_draft' => sf_term('saving_draft', $uiLang),
        
        // Profile & User messages
        'profileUpdated' => sf_term('notice_profile_updated', $uiLang),
        'passwordChanged' => sf_term('notice_password_changed', $uiLang),
        'passwordsMismatch' => sf_term('profile_passwords_mismatch', $uiLang),

        // Push notifications
        'pushNotSupported' => sf_term('push_notifications_not_supported', $uiLang),
        'pushStatusChecking' => sf_term('push_notifications_status_checking', $uiLang),
        'pushStatusEnabled' => sf_term('push_notifications_status_enabled', $uiLang),
        'pushStatusDisabled' => sf_term('push_notifications_status_disabled', $uiLang),
        'pushStatusEnabling' => sf_term('push_notifications_status_enabling', $uiLang),
        'pushStatusDisabling' => sf_term('push_notifications_status_disabling', $uiLang),
        'pushStatusError' => sf_term('push_notifications_status_error', $uiLang),
        'pushEnabledToast' => sf_term('push_notifications_enabled_toast', $uiLang),
        'pushDisabledToast' => sf_term('push_notifications_disabled_toast', $uiLang),
        
        // Session messages
        'sessionInactiveTitle' => sf_term('session_inactive_title', $uiLang),
        'sessionInactiveText' => sf_term('session_inactive_text', $uiLang),
        'sessionInactiveHint' => sf_term('session_inactive_hint', $uiLang),
        'sessionContinueButton' => sf_term('session_continue_button', $uiLang),
        'sessionLogoutButton' => sf_term('session_logout_button', $uiLang),

        // Generic messages
        'error' => sf_term('notice_error', $uiLang),
        'success' => sf_term('notice_success', $uiLang),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>;

window.sfToast = function(a, b) {
    // ✅ Tukee molemmat kutsutavat:
    // 1) sfToast(type, message)  (oikea)
    // 2) sfToast(message, type)  (jos jokin tiedosto kutsuu vahingossa näin)

    const validTypes = ["success", "info", "warning", "danger", "error"];

    let type = a;
    let message = b;

    // Jos eka parametri ei ole tyyppi, mutta toka on -> vaihda
    if (!validTypes.includes(String(type)) && validTypes.includes(String(message))) {
        const tmp = type;
        type = message;
        message = tmp;
    }

    // Fallback jos type puuttuu / on outo
    if (!validTypes.includes(String(type))) type = "info";

    // Map "error" -> "danger" (CSS-luokka)
    const mappedType = (type === "error") ? "danger" : type;

    const existing = document.getElementById("sfToast");
    if (existing) existing.remove();

    const t = document.createElement("div");
    t.id = "sfToast";
    t.className = "sf-toast sf-toast-" + mappedType;

    const escapeHtml = (txt) => {
        const div = document.createElement("div");
        div.textContent = txt ?? "";
        return div.innerHTML;
    };

    let iconSvg = "";
    if (mappedType === "success") {
        iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
        </svg>`;
    } else if (mappedType === "danger") {
        iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="15" y1="9" x2="9" y2="15"></line>
            <line x1="9" y1="9" x2="15" y2="15"></line>
        </svg>`;
    } else {
        iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="12"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
        </svg>`;
    }

    t.innerHTML = `
        <div class="sf-toast-icon">${iconSvg}</div>
        <span class="sf-toast-text">${escapeHtml(message)}</span>
        <button class="sf-toast-close" type="button" aria-label="Close">×</button>
    `;
    t.querySelector(".sf-toast-close")?.addEventListener("click", () => t.remove());

    document.body.appendChild(t);

    clearTimeout(window.sfToast._timer);
    window.sfToast._timer = setTimeout(() => {
        if (t && t.parentElement) {
            t.classList.add("sf-toast-hide");
            setTimeout(() => {
                if (t && t.parentElement) t.remove();
            }, 300);
        }
    }, 4000);
};
</script>