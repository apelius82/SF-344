<?php
// assets/pages/settings.php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';
require_once __DIR__ . '/../../app/includes/statuses.php';
require_once __DIR__ . '/../../app/includes/settings.php';
require_once __DIR__ . '/../../app/includes/log_app.php';

$baseUrl = rtrim($config['base_url'] ?? '', '/');

// Allow admin and safety team
if (!sf_is_admin_or_safety()) {
    http_response_code(403);
    echo 'Ei käyttöoikeutta asetussivulle.';
    exit;
}

// UI-kieli
$currentUiLang = $uiLang ?? ($_SESSION['ui_lang'] ?? 'fi');

// DB-yhteys
$mysqli = sf_db();

// -------------------------------------------------------
// Backward compatibility: redirect old flat ?tab= URLs
// to the new ?tab=<group>&sub=<subtab> structure.
// -------------------------------------------------------
$legacyTabMap = [
    'role_categories' => ['tab' => 'users',   'sub' => 'role_categories'],
    'image_library'   => ['tab' => 'images',  'sub' => 'library'],
    'audit_log'       => ['tab' => 'logs',    'sub' => 'app'],
    'email_logs'      => ['tab' => 'logs',    'sub' => 'email'],
    'email'           => ['tab' => 'email',   'sub' => 'settings'],
    'system'          => ['tab' => 'system',  'sub' => 'general'],
    'updates'         => ['tab' => 'system',  'sub' => 'updates'],
    'content'         => ['tab' => 'images',  'sub' => 'library'],
];

$rawTab = $_GET['tab'] ?? 'users';
$rawSubExists = isset($_GET['sub']) && $_GET['sub'] !== '';

if (!$rawSubExists && isset($legacyTabMap[$rawTab])) {
    $redir = $legacyTabMap[$rawTab];
    $q = http_build_query(['page' => 'settings', 'tab' => $redir['tab'], 'sub' => $redir['sub']]);
    header("Location: {$baseUrl}/index.php?{$q}", true, 301);
    exit;
}

// -------------------------------------------------------
// Tab group definitions
// -------------------------------------------------------
$tabGroups = [
    'users'     => ['users', 'role_categories'],
    'worksites' => [],
    'analytics' => [],
    'images'    => ['library', 'fallback'],
    'email'     => ['settings', 'subscriptions'],
    'logs'      => ['app', 'email'],
    'system'    => ['general', 'templates', 'updates', 'notices'],
];

// File to include per group+subtab
$tabFileMap = [
    'users'    => [
        'users'           => 'tab_users.php',
        'role_categories' => 'tab_role_categories.php',
    ],
    'worksites' => [
        'worksites' => 'tab_worksites.php',
    ],
    'analytics' => [
        'analytics' => 'tab_analytics.php',
    ],
    'images'    => [
        'library'  => 'tab_image_library.php',
        'fallback' => 'tab_images_fallback.php',
    ],
    'email'     => [
        'settings'      => 'tab_email.php',
        'subscriptions' => 'tab_email_subscriptions.php',
    ],
    'logs'      => [
        'app'   => 'tab_audit_log.php',
        'email' => 'tab_email_logs.php',
    ],
    'system'    => [
        'general'   => 'tab_system_general.php',
        'templates' => 'tab_system_templates.php',
		'updates' => 'tab_updates.php',
		'notices' => 'tab_system_notices.php',    ],
];

// Icons per top-level group
$tabGroupIcons = [
    'users'     => 'users.svg',
    'worksites' => 'worksite.svg',
    'analytics' => 'stats-total.svg',
    'images'    => 'image.svg',
    'email'     => 'email.svg',
    'logs'      => 'lista.svg',
    'system'    => 'settings.svg',
];

// Icons per subtab
$subIcons = [
    'users'           => 'user.svg',
    'role_categories' => 'users.svg',
    'library'         => 'image.svg',
    'fallback'        => 'display.svg',
    'settings'        => 'settings.svg',
    'subscriptions'   => 'email.svg',
    'app'             => 'calendar.svg',
    'email'           => 'lista.svg',
    'general'         => 'screen.svg',
    'templates'       => 'version-document.svg',
'updates'         => 'changelog_icon.svg',
'notices'         => 'alert-circle.svg',
];

// Resolve active tab group
$tab = array_key_exists($rawTab, $tabGroups) ? $rawTab : 'users';
$subs = $tabGroups[$tab];

// Resolve active subtab
$rawSub = $_GET['sub'] ?? '';
$sub    = in_array($rawSub, $subs, true) ? $rawSub : ($subs[0] ?? $tab);

// Resolve file to include
$activeFile = $tabFileMap[$tab][$sub] ?? '';
?>
<div class="sf-page-container sf-settings-shell">
    <div class="sf-page-header">
        <h1 class="sf-page-title">
            <?= htmlspecialchars(
                sf_term('settings_heading', $currentUiLang) ?? 'Asetukset',
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </h1>
    </div>

<div class="sf-settings-page">

<!-- Top-level tab groups -->
<div class="sf-tabs" aria-label="<?= htmlspecialchars(sf_term('settings_heading', $currentUiLang) ?? 'Asetukset', ENT_QUOTES, 'UTF-8') ?>">

    <?php foreach ($tabGroups as $groupKey => $groupSubs):
        $isActive  = ($tab === $groupKey);
        $groupIcon = $tabGroupIcons[$groupKey] ?? 'settings.svg';
        // Link goes to first subtab (or just the group for worksites)
        $firstSub  = $groupSubs[0] ?? '';
        $linkParams = ['page' => 'settings', 'tab' => $groupKey];
        if ($firstSub !== '') {
            $linkParams['sub'] = $firstSub;
        }
        $groupTermKey = 'settings_group_' . $groupKey;
        $groupLabel   = sf_term($groupTermKey, $currentUiLang);
        if ($groupLabel === $groupTermKey) {
            // Fallback to old tab term if group term not set
            $groupLabel = sf_term('settings_tab_' . $groupKey, $currentUiLang) ?? ucfirst($groupKey);
        }
    ?>
    <a href="<?= $baseUrl ?>/index.php?<?= htmlspecialchars(http_build_query($linkParams), ENT_QUOTES, 'UTF-8') ?>"
       class="sf-tab <?= $isActive ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/<?= htmlspecialchars($groupIcon, ENT_QUOTES, 'UTF-8') ?>"
             alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?></span>
    </a>
    <?php endforeach; ?>

</div>

    <div class="sf-tabs-content">

        <?php if (!empty($subs)): ?>
        <!-- Subtabs (only shown when current group has subtabs) -->
        <nav class="sf-subtabs" aria-label="<?= htmlspecialchars(sf_term('settings_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <?php foreach ($subs as $subKey):
                $isSubActive = ($sub === $subKey);
                $subIcon = $subIcons[$subKey] ?? 'settings.svg';
                $subParams = ['page' => 'settings', 'tab' => $tab, 'sub' => $subKey];
                // Resolve subtab label
                $subTermKey = 'settings_subtab_' . $subKey;
                $subLabel   = sf_term($subTermKey, $currentUiLang);
                if ($subLabel === $subTermKey) {
                    // Fall back to existing tab terms
                    $fallbacks = [
                        'users'           => 'settings_tab_users',
                        'role_categories' => 'settings_tab_role_categories',
                        'library'         => 'settings_tab_image_library',
                        'app'             => 'settings_tab_audit_log',
                        'email'           => 'settings_tab_email_logs',
                        'updates'         => 'settings_tab_updates',
                    ];
                    if (isset($fallbacks[$subKey])) {
                        $subLabel = sf_term($fallbacks[$subKey], $currentUiLang);
                    }
                    if ($subLabel === ($fallbacks[$subKey] ?? $subTermKey)) {
                        $subLabel = ucfirst(str_replace('_', ' ', $subKey));
                    }
                }
            ?>
            <a href="<?= $baseUrl ?>/index.php?<?= htmlspecialchars(http_build_query($subParams), ENT_QUOTES, 'UTF-8') ?>"
               class="sf-subtab <?= $isSubActive ? 'active' : '' ?>">
                <img src="<?= $baseUrl ?>/assets/img/icons/<?= htmlspecialchars($subIcon, ENT_QUOTES, 'UTF-8') ?>"
                     alt="" class="sf-subtab-icon" aria-hidden="true">
                <span><?= htmlspecialchars($subLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <?php
        // Load active tab content
        $tabFile = __DIR__ . '/settings/' . $activeFile;
        if ($activeFile !== '' && file_exists($tabFile)) {
            include $tabFile;
        } else {
            echo '<p>Välilehteä ei löydy.</p>';
        }
        ?>
    </div>
</div>
</div>