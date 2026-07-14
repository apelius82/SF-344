<?php
/**
 * SafetyFlash Terms - Modular Index
 * 
 * Combines all term modules into a single configuration array.
 * Each category is in its own file for better maintainability.
 * 
 * @package SafetyFlash
 * @version 2.0.0
 */

$termsDir = __DIR__;

// Load all term modules
$modules = [
    'common'     => require $termsDir . '/common.php',
    'navigation' => require $termsDir . '/navigation.php',
    'dashboard'  => require $termsDir . '/dashboard.php',
    'analytics'  => require $termsDir . '/analytics.php',
    'form'       => require $termsDir . '/form.php',
    'view'       => require $termsDir . '/view.php',
    'list'       => require $termsDir . '/list.php',
    'settings'   => require $termsDir . '/settings.php',
    'logs'       => require $termsDir . '/logs.php',
    'statuses'   => require $termsDir . '/statuses.php',
    'modals'     => require $termsDir . '/modals.php',
    'buttons'    => require $termsDir . '/buttons.php',
    'messages'   => require $termsDir . '/messages.php',
    'images'     => require $termsDir . '/images.php',
    'display'    => require $termsDir . '/display.php',
    'body_map'   => require $termsDir . '/body_map.php',
    'updates'    => require $termsDir . '/updates.php',
    'push'       => require $termsDir . '/push.php',
];

// Merge all terms into one array
$allTerms = [];
foreach ($modules as $moduleName => $moduleTerms) {
    if (is_array($moduleTerms)) {
        $allTerms = array_merge($allTerms, $moduleTerms);
    }
}

// Return complete configuration (same format as before)
return [
    'languages' => ['fi', 'sv', 'en', 'it', 'el'],
    'terms' => $allTerms
];