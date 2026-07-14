<?php
// app/includes/protect.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/session_activity.php';
require_once __DIR__ . '/filename_helpers.php';

/**
 * Protect both HTML actions and API endpoints:
 * - Require login (except explicitly public paths)
 * - For API/fetch calls: return JSON 401 instead of redirect
 * - Enforce CSRF for state-changing requests (POST/PUT/PATCH/DELETE)
 *   unless SF_SKIP_AUTO_CSRF is defined (for JSON-body endpoints that validate CSRF manually).
 */

function sf_is_fetch_request(): bool
{
    $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($xrw === 'xmlhttprequest') {
        return true;
    }

    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    return strpos($accept, 'application/json') !== false;
}

function sf_is_api_path(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return (strpos($uri, '/app/api/') !== false);
}

function sf_uri_ends_with(string $uri, string $suffix): bool
{
    return $suffix === '' ? true : (substr($uri, -strlen($suffix)) === $suffix);
}

/**
 * Check if current user has been invalidated (deleted or role changed)
 * If so, destroy session and force re-authentication
 */
function sf_check_user_invalidation(): void
{
    // Skip if no session or no user
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    
    $currentUserId = $_SESSION['user_id'] ?? 0;
    if ($currentUserId <= 0) {
        return;
    }
    
    // Check if user is in invalidated list
    if (!isset($_SESSION['invalidated_users']) || !is_array($_SESSION['invalidated_users'])) {
        return;
    }
    
    if (!in_array((int)$currentUserId, $_SESSION['invalidated_users'], true)) {
        return;
    }
    
    // User is invalidated - destroy session and force re-login
    $isApi = sf_is_api_path();
    $isFetch = sf_is_fetch_request();
    
    // Clear session
    $_SESSION = [];
    
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    
    session_destroy();
    
    // Return appropriate response
    if ($isApi || $isFetch) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Session invalidated - please login again',
            'code' => 'SESSION_INVALIDATED'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Redirect to login with message
    global $config;
    $base = rtrim($config['base_url'] ?? '', '/');
    header('Location: ' . $base . '/assets/pages/login.php?session_invalidated=1');
    exit;
}

// Allowlist: paths that must remain public
$current = $_SERVER['REQUEST_URI'] ?? '';

$publicPaths = [
    '/assets/pages/login.php',
    '/assets/pages/forgot_password.php',
    '/assets/pages/reset_password.php',
    '/app/api/login.php',
    '/app/api/login_process.php',
    '/app/api/password_forgot.php',
    '/app/api/password_reset.php',
    '/app/pages/logout.php',
];

// If current request is a public path, do nothing
foreach ($publicPaths as $pub) {
    if (sf_uri_ends_with($current, $pub)) {
        return;
    }
}

// Auth: JSON 401 for API/fetch, redirect for normal browser navigations
if (!sf_current_user()) {
    if (sf_is_api_path() || sf_is_fetch_request()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        // yhtenäinen formaatti frontendille
        echo json_encode(['success' => false, 'error' => 'Authentication required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Save the requested URL for redirect after login
    // Only save internal relative URLs to prevent open redirect vulnerabilities
    $requestedUrl = $_SERVER['REQUEST_URI'] ?? '';
    if (sf_is_safe_redirect_url($requestedUrl)) {
        $_SESSION['login_redirect'] = $requestedUrl;
    }
    
    sf_redirect_to_login();
}

// CHECK FOR INVALIDATED USERS (after successful auth, before activity tick)
sf_check_user_invalidation();

// Enforce inactivity timeout.
// Passive background requests must not keep the session alive.
$sfCurrentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

$sfPassiveSessionPathSuffixes = [
    '/app/api/get_user_events.php',
    '/app/api/session_keepalive.php',
];

$sfIsPassiveSessionRequest = false;

foreach ($sfPassiveSessionPathSuffixes as $sfPassivePathSuffix) {
    if (
        $sfCurrentPath === $sfPassivePathSuffix ||
        substr($sfCurrentPath, -strlen($sfPassivePathSuffix)) === $sfPassivePathSuffix
    ) {
        $sfIsPassiveSessionRequest = true;
        break;
    }
}

sf_session_activity_tick([
    'is_api' => sf_is_api_path(),
    'is_fetch' => sf_is_fetch_request(),
    'count_activity' => !$sfIsPassiveSessionRequest,
]);
// CSRF for state-changing requests
// NOTE: Some endpoints (e.g. JSON-body endpoints) validate CSRF manually.
// They can define SF_SKIP_AUTO_CSRF before including protect.php.
if (!defined('SF_SKIP_AUTO_CSRF')) {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        // For API/fetch: return JSON on failure
        if (sf_is_api_path() || sf_is_fetch_request()) {
            sf_csrf_check_strict();
        } else {
            sf_csrf_check();
        }
    }
}