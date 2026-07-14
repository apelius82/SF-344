<?php

ob_start();

require_once __DIR__ . '/config.php';

// Debug-asetukset konfiguraation mukaan
if ($config['debug'] ?? false) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/assets/logs/php_errors.log');
}

require_once __DIR__ . '/app/includes/log_app.php';
require_once __DIR__ . '/app/includes/statuses.php';
require_once __DIR__ . '/app/includes/csrf.php';
require_once __DIR__ . '/app/includes/auth.php';

// Require authentication BEFORE any HTML output (prevents 'headers already sent')
sf_require_login();
require_once __DIR__ . '/assets/lib/sf_terms.php';

// UI-kieli (FI/EN)
$uiLang = $_SESSION['ui_lang'] ?? 'fi';
$termsConfig = sf_get_terms_config();
if (!in_array($uiLang, $termsConfig['languages'] ?? [], true)) {
    $uiLang = 'fi';
    $_SESSION['ui_lang'] = 'fi';
}

// Mikä sivu halutaan ladata?
$page = $_GET['page'] ?? 'list';

// Sallitut sivut
$allowed = [
    'dashboard'        => '/assets/pages/dashboard.php',
    'list'             => '/assets/pages/list.php',
    'form'             => '/assets/pages/form.php',
    'form_language'    => '/assets/pages/form_language.php',
    'view'             => '/assets/pages/view.php',
    'users'            => '/assets/pages/users.php',
    'settings'         => '/assets/pages/settings.php',
    'profile'          => '/assets/pages/profile.php',
    'role_categories'  => '/assets/pages/role_categories.php',
    'feedback'         => '/assets/pages/feedback.php',
    'playlist_manager' => '/assets/pages/playlist_manager.php',
    'updates'          => '/assets/pages/updates.php',
];

if (!isset($allowed[$page])) {
    $page = 'list';
}

$file = $allowed[$page];
$base = rtrim($config['base_url'], '/');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($uiLang) ?>">
<head>
    <meta charset="UTF-8">

    <!-- ===== MOBIILI META TAGIT ===== -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="format-detection" content="telephone=no">

<link rel="manifest" href="<?= $base; ?>/manifest.php">
<meta name="theme-color" content="#0f172a">

<link rel="icon" type="image/png" sizes="192x192" href="<?= $base; ?>/assets/img/icons/pwa-icon-192.png">
<link rel="shortcut icon" href="<?= $base; ?>/assets/img/icons/pwa-icon-192.png">
<link rel="apple-touch-icon" href="<?= $base; ?>/assets/img/icons/pwa-icon-192.png">

    <title>Safetyflash</title>

    <!-- Yleiset CSS -->
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/nav.css', $base) ?>">
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/global.css', $base) ?>">
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/layout.css', $base) ?>">
	<link rel="stylesheet" href="<?= sf_asset_url('assets/css/skeleton.css', $base) ?>">
	<link rel="stylesheet" href="<?= sf_asset_url('assets/css/system-notice.css', $base) ?>">

    <!-- Sivukohtaiset CSS -->
    <?php if ($page === 'dashboard'): ?>
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/dashboard.css', $base) ?>">
    <?php elseif ($page === 'list'): ?>
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/list.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/copy-to-clipboard.css', $base) ?>">
    <?php elseif ($page === 'form'): ?>
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/form.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/render.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/preview.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/image-library.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/extra_images.css', $base) ?>">
    <?php elseif ($page === 'form_language'): ?>
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/form.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/render.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/preview.css', $base) ?>">
    <?php elseif ($page === 'view'): ?>
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/view.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/view/workflow.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/view/meta-panel.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/view/activity-tabs.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/view/comments.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/view/events.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/view/versions.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/render.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/copy-to-clipboard.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/extra_images.css', $base) ?>">
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/vendor/quill.snow.css', $base) ?>">
<?php elseif ($page === 'settings'): ?>
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/settings.css', $base) ?>">
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/analytics.css', $base) ?>">
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/role-categories-select.css', $base) ?>">
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/vendor/quill.snow.css', $base) ?>">
    <?php elseif ($page === 'profile'): ?>
        <link rel="stylesheet" href="<?= sf_asset_url('assets/css/settings.css', $base) ?>">
    <?php endif; ?>

    <!-- MODALS VIIMEISENÄ - yliajaa sivukohtaiset -->
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/modals.css', $base) ?>">

    <!-- Infonäyttöjen ajolista -modaali on globaali header-toiminto -->
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/display-ttl.css', $base) ?>">

    <!-- html2canvas -->
    <script src="<?= sf_asset_url('assets/js/vendor/html2canvas.min.js', $base) ?>"></script>

    <!-- ===== PROGRESS BAR + SIVUN FADE (EI AJAX) ===== -->
    <style>
        /* Progress bar (yläreuna) */
        #sfProgress {
            position: fixed;
            top: 0;
            left: 0;
            height: 3px;
            width: 0%;
            background: #FEE000;
            z-index: 999999;
            opacity: 0;
            transform: translateZ(0);
            transition: width 220ms ease, opacity 180ms ease;
        }
        body.sf-loading #sfProgress {
            opacity: 1;
            width: 65%;
        }
        body.sf-loading.sf-loading-long #sfProgress {
            width: 90%;
        }

        /* Fade-out / fade-in */
        .sf-container {
            opacity: 1;
            transition: opacity 200ms ease;
        }
        body.sf-loading .sf-container {
            opacity: 0.35;
        }

        @media (prefers-reduced-motion: reduce) {
            .sf-container { transition: none; }
            #sfProgress { transition: none; }
        }
    </style>

    <!-- JS-konstantit -->
    <script>
        const SF_BASE_URL   = "<?= rtrim($config['base_url'], '/'); ?>";
        const SF_UPLOAD_URL = SF_BASE_URL + "/upload.php";
        const SF_SAVE_URL   = SF_BASE_URL + "/app/api/save_flash.php";
        const SF_IMAGES_URL = SF_BASE_URL + "/uploads/images";
        // Session timeout for warning (rounded to nearest 5 minutes)
        const SF_SESSION_TIMEOUT = <?= (int)(floor(($config['session']['timeout'] ?? 1800) / 300) * 300) ?>;

        // Tee saataville myös window.* (käytössä moduuleissa ja muissa skripteissä)
        window.SF_BASE_URL   = SF_BASE_URL;
        window.SF_UPLOAD_URL = SF_UPLOAD_URL;
        window.SF_SAVE_URL   = SF_SAVE_URL;
        window.SF_IMAGES_URL = SF_IMAGES_URL;
        window.SF_SESSION_TIMEOUT = SF_SESSION_TIMEOUT;
        window.SF_CSRF_TOKEN = <?= json_encode(sf_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        // Translations for JavaScript
        window.SF_I18N = {
            autosave_saved: "<?= htmlspecialchars(sf_term('autosave_saved', $uiLang), ENT_QUOTES, 'UTF-8') ?>",
            offline_title: "<?= htmlspecialchars(sf_term('offline_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>",
            offline_draft_message: "<?= htmlspecialchars(sf_term('offline_draft_message', $uiLang), ENT_QUOTES, 'UTF-8') ?>",
            offline_submit_message: "<?= htmlspecialchars(sf_term('offline_submit_message', $uiLang), ENT_QUOTES, 'UTF-8') ?>",
            btn_close: "<?= htmlspecialchars(sf_term('btn_close', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        };
        
        // Profile modal translations
        window.SF_PROFILE_I18N = {
            profileUpdated: "<?= htmlspecialchars(sf_term('notice_profile_updated', $uiLang), ENT_QUOTES, 'UTF-8') ?>",
            passwordChanged: "<?= htmlspecialchars(sf_term('notice_password_changed', $uiLang), ENT_QUOTES, 'UTF-8') ?>",
            passwordsMismatch: "<?= htmlspecialchars(sf_term('profile_passwords_mismatch', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        };
    </script>
</head>

<body
    data-page="<?= htmlspecialchars($page); ?>"
    class="<?= ($page === 'form' || $page === 'form_language') ? 'form-page' : ''; ?>"
>
<!-- Progress bar -->
<div id="sfProgress" aria-hidden="true"></div>

<!-- ===== VAAKAKÄYTÖN VAROITUS ===== -->
<div class="sf-rotate-warning" id="sfRotateWarning">
    <div class="sf-rotate-warning-icon">📱</div>
    <div class="sf-rotate-warning-text"><?= htmlspecialchars(sf_term('rotate_phone', $uiLang), ENT_QUOTES, 'UTF-8') ?></div>
    <div class="sf-rotate-warning-subtext"><?= htmlspecialchars(sf_term('rotate_phone_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?></div>
</div>

<?php include __DIR__ . '/app/includes/header.php'; ?>

<?php if ($page === 'form' || $page === 'form_language'): ?>
<!-- Draft Recovery Banner -->
<div id="sfDraftBanner" class="sf-draft-banner" style="display: none;">
    <div class="sf-draft-banner-content">
        <div class="sf-draft-banner-text">
            <span class="sf-draft-banner-icon">💾</span>
            <span><?= htmlspecialchars(sf_term('draft_recovery_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="sf-draft-banner-actions">
            <button type="button" id="sfDraftContinue" class="sf-draft-btn sf-draft-btn-primary">
                <?= htmlspecialchars(sf_term('draft_continue', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" id="sfDraftDiscard" class="sf-draft-btn sf-draft-btn-secondary">
                <?= htmlspecialchars(sf_term('draft_discard', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="sf-container" id="sfContainer">
    <?php include __DIR__ . $file; ?>
</div>

<?php require_once __DIR__ . '/app/includes/footer.php'; ?>

<script src="<?= sf_asset_url('assets/js/bottom-sheet.js', $base) ?>"></script>

<?php if ($page === 'list'): ?>
    <script src="<?= sf_asset_url('assets/js/list-filters.js', $base) ?>"></script>
    <script src="<?= sf_asset_url('assets/js/list-views.js', $base) ?>"></script>

<?php elseif ($page === 'dashboard'): ?>
    <script src="<?= sf_asset_url('assets/js/dashboard.js', $base) ?>"></script>
    <script src="<?= sf_asset_url('assets/js/dashboard-report.js', $base) ?>"></script>

<?php elseif ($page === 'form'): ?>
    <!-- Preview-kortin skaalaus -->
    <script src="<?= sf_asset_url('assets/js/previewScaler.js', $base) ?>"></script>
    <!-- Uusi modulaarinen lomakelogiikka -->
    <script type="module" src="<?= sf_asset_url('assets/js/form.js', $base) ?>"></script>
    <script src="<?= sf_asset_url('assets/js/image-library.js', $base) ?>"></script>
    <!-- Extra images upload module -->
    <script src="<?= sf_asset_url('assets/js/modules/extra_uploads.js', $base) ?>"></script>
    <!-- Auto-save module -->
    <script type="module" src="<?= sf_asset_url('assets/js/modules/autosave.js', $base) ?>"></script>
    <!-- Editing lock -->
    <script src="<?= sf_asset_url('assets/js/editing-lock.js', $base) ?>"></script>

<?php elseif ($page === 'form_language'): ?>
    <script src="<?= sf_asset_url('assets/js/form_language.js', $base) ?>"></script>

<?php elseif ($page === 'view'): ?>
    <script src="<?= sf_asset_url('assets/js/view.js', $base) ?>"></script>
    <script src="<?= sf_asset_url('assets/js/copy-to-clipboard.js', $base) ?>"></script>

<?php elseif ($page === 'users'): ?>
    <script src="<?= sf_asset_url('assets/js/users.js', $base) ?>"></script>

<?php elseif ($page === 'settings'): ?>
    <script src="<?= sf_asset_url('assets/js/users.js', $base) ?>"></script>
    <script src="<?= sf_asset_url('assets/js/settings.js', $base) ?>"></script>
    <script src="<?= sf_asset_url('assets/js/analytics-dashboard.js', $base) ?>"></script>

<?php endif; ?>

<!-- Globaali modaalien hallinta -->
<script src="<?= sf_asset_url('assets/js/modals.js', $base) ?>"></script>

<!-- Profile modal -->
<script src="<?= sf_asset_url('assets/js/profile-modal.js', $base) ?>"></script>

<!-- Skeleton loading handler -->
<script src="<?= sf_asset_url('assets/js/skeleton.js', $base) ?>"></script>

<!-- Mobiilituki -->
<script src="<?= sf_asset_url('assets/js/mobile.js', $base) ?>"></script>

<!-- Taustaprosessoinnin seuranta (bg_process=ID) -->
<script src="<?= sf_asset_url('assets/js/background-process.js', $base) ?>"></script>

<!-- Push-ilmoitusten selainrekisteröinti -->
<script src="<?= sf_asset_url('assets/js/push-notifications.js', $base) ?>"></script>

<!-- Käyttöanalytiikan yleinen seuranta -->
<script src="<?= sf_asset_url('assets/js/analytics.js', $base) ?>"></script>

<script>
(function () {
    const progress = document.getElementById("sfProgress");

    function startLoading() {
        document.body.classList.add("sf-loading");
        // jos lataus kestää > 600ms, nostetaan palkki lähemmäs loppua
        window.__sfLoadingTimer = window.setTimeout(() => {
            document.body.classList.add("sf-loading-long");
        }, 600);
    }

    function stopLoading() {
        window.clearTimeout(window.__sfLoadingTimer);
        document.body.classList.remove("sf-loading-long");
        if (progress) progress.style.width = "100%";
        // pieni viive, jotta “100%” näkyy
        window.setTimeout(() => {
            document.body.classList.remove("sf-loading");
            if (progress) progress.style.width = "";
        }, 140);
    }

    // Linkkiklikit (vain samaan originin navigaatio)
    document.addEventListener("click", (e) => {
        const a = e.target.closest("a");
        if (!a) return;

        // LISÄTTY: Ohita, jos linkillä on 'download'-attribuutti.
        if (a.hasAttribute("download")) return;

        // ohita: uuteen välilehteen, ankkurit, javascript:, tyhjät
        const href = a.getAttribute("href") || "";
        if (!href || href.startsWith("#") || href.startsWith("javascript:")) return;
        if (a.target && a.target !== "_self") return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        // Varmista sama origin (käytä URL-parsintaa)
        try {
            const url = new URL(href, window.location.href);
            if (url.origin !== window.location.origin) return;
        } catch (err) {
            return;
        }

        startLoading();
    }, true);

    // Lomakkeen submit
    document.addEventListener("submit", () => {
        startLoading();
    }, true);

    // Kun sivu on valmis
    window.addEventListener("pageshow", () => {
        // pageshow triggaa myös BFCache-paluuissa -> pysyy “freshinä”
        stopLoading();
    });

    window.addEventListener("load", () => {
        stopLoading();
    });

    // Toastin piilotus (kuten sinulla)
    document.addEventListener("DOMContentLoaded", function () {
        const t = document.getElementById('toastNotice');
        if (t) setTimeout(() => t.classList.add('hide'), 4000);
    });
})();
</script>

<!-- Draft Banner -->
<script>
(function() {
    const draftBanner = document.getElementById('sfDraftBanner');

    if (!draftBanner) {
        return;
    }

    if (!window.SF_AVAILABLE_DRAFTS || window.SF_AVAILABLE_DRAFTS.length === 0) {
        return;
    }

    draftBanner.style.display = 'block';

    const draftContinueBtn = document.getElementById('sfDraftContinue');
    const draftDiscardBtn = document.getElementById('sfDraftDiscard');

    if (draftContinueBtn) {
        draftContinueBtn.addEventListener('click', async function() {
            const draft = window.SF_AVAILABLE_DRAFTS[0];

            if (window.autoSave) {
                await window.autoSave.loadDraft(draft.id);
            }

            draftBanner.style.display = 'none';
        });
    }

    if (draftDiscardBtn) {
        draftDiscardBtn.addEventListener('click', async function() {
            const draft = window.SF_AVAILABLE_DRAFTS[0];

            if (window.autoSave) {
                await window.autoSave.deleteDraft(draft.id);
            }

            draftBanner.style.display = 'none';
        });
    }
})();
</script>

<!-- Service Worker Registration -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('<?= $base; ?>/sw.js.php')
            .then(function(registration) {
                console.log('SW registered:', registration.scope);
            })
            .catch(function(error) {
                console.log('SW registration failed:', error);
            });
    });
}
</script>

<!-- PWA Update Handler -->
<script src="<?= sf_asset_url('assets/js/pwa-update.js', $base) ?>"></script>

<!-- PWA Install Handler -->
<script src="<?= sf_asset_url('assets/js/pwa-install.js', $base) ?>"></script>

<!-- System Notices -->
<script src="<?= sf_asset_url('assets/js/system-notice.js', $base) ?>"></script>

<!-- Copy to Clipboard for list page -->
<?php if ($page === 'list'): ?>
<script src="<?= sf_asset_url('assets/js/copy-to-clipboard.js', $base) ?>"></script>
<?php endif; ?>

</body>
</html>