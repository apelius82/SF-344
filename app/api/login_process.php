<?php
// app/api/login_process.php
declare(strict_types=1);

// Load core dependencies
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/log_app.php';

$base = rtrim($config['base_url'] ?? '', '/');

// Selected UI language from login form
$lang = $_POST['lang'] ?? 'fi';

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $base . '/assets/pages/login.php?lang=' . urlencode($lang));
    exit;
}

// CSRF validation
if (!sf_csrf_validate($_POST['csrf_token'] ?? null)) {
    sf_audit_log('login_failed', 'user', null, [
        'reason' => 'csrf',
        'attempted_email' => trim($_POST['email'] ?? ''),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    ]);

    header('Location: ' . $base . '/assets/pages/login.php?error=csrf&lang=' . urlencode($lang));
    exit;
}

// Credentials
$email    = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    header('Location: ' . $base . '/assets/pages/login.php?error=1&lang=' . urlencode($lang));
    exit;
}

// Rate limiting check
require_once __DIR__ . '/../includes/rate_limit.php';

$clientIp = sf_get_client_ip();

// Check rate limit BEFORE attempting login
$rateCheck = sf_check_login_allowed($email, $clientIp);

if (!$rateCheck['allowed']) {
    sf_audit_log('login_blocked', 'user', null, [
        'reason' => 'rate_limit',
        'email' => $email,
        'ip' => $clientIp,
        'locked_until' => $rateCheck['locked_until'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    ]);
    
    // Calculate wait time in minutes for user-friendly message
    $waitMinutes = ceil($rateCheck['wait_seconds'] / 60);
    
    header('Location: ' . $base . '/assets/pages/login.php?error=rate_limit&wait=' . $waitMinutes . '&lang=' . urlencode($lang));
    exit;
}

// Fetch user
$mysqli = sf_db();

$stmt = $mysqli->prepare(
    'SELECT u.id, u.first_name, u.last_name, u.email, u.password_hash,
            u.role_id, u.is_active, u.home_worksite_id, u.email_notifications_enabled,
            r.name AS role_name
     FROM sf_users u
     LEFT JOIN sf_roles r ON r.id = u.role_id
     WHERE u.email = ?
     LIMIT 1'
);

if (!$stmt) {
    sf_app_log('login_process: DB prepare failed: ' . $mysqli->error, LOG_LEVEL_ERROR);
    $mysqli->close();
    header('Location: ' . $base . '/assets/pages/login.php?error=1&lang=' . urlencode($lang));
    exit;
}

$stmt->bind_param('s', $email);
$stmt->execute();

$result = $stmt->get_result();
$user   = $result ? $result->fetch_assoc() : null;

$stmt->close();

// Validate user + password + active flag
$valid = (
    $user &&
    (int)($user['is_active'] ?? 0) === 1 &&
    !empty($user['password_hash']) &&
    password_verify($password, (string)$user['password_hash'])
);

if (!$valid) {
    $reason = 'wrong_password';
    if (!$user) {
        $reason = 'user_not_found';
    } elseif ((int)($user['is_active'] ?? 0) !== 1) {
        $reason = 'inactive';
    }

    sf_audit_log('login_failed', 'user', $user ? (int)$user['id'] : null, [
        'attempted_email' => $email,
        'reason' => $reason,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    ]);

    // Record failed login attempt for rate limiting
    sf_record_login_attempt($email, $clientIp, false);

    $mysqli->close();

    header('Location: ' . $base . '/assets/pages/login.php?error=1&lang=' . urlencode($lang));
    exit;
}

// Successful login: regenerate session id (session fixation protection)
session_regenerate_id(true);

// Store user into session (without password hash)
sf_set_current_user([
    'id'               => (int)$user['id'],
    'first_name'       => $user['first_name'] ?? '',
    'last_name'        => $user['last_name'] ?? '',
    'email'            => $user['email'] ?? '',
    'role_id'          => (int)($user['role_id'] ?? 0),
    'role_name'        => $user['role_name'] ?? '',
    'home_worksite_id' => $user['home_worksite_id'] !== null ? (int)$user['home_worksite_id'] : null,
]);

// Persist UI language
$_SESSION['ui_lang'] = $lang;

// Session activity timestamps
$_SESSION['sf_last_activity'] = time();
$_SESSION['sf_last_resume_log'] = time();

// Regenerate CSRF token after login
sf_csrf_regenerate();

// Update last_login_at (best-effort)
try {
    $upd = $mysqli->prepare('UPDATE sf_users SET last_login_at = NOW() WHERE id = ?');
    if ($upd) {
        $uid = (int)$user['id'];
        $upd->bind_param('i', $uid);
        $upd->execute();
        $upd->close();
    }
} catch (Throwable $e) {
    sf_app_log('login_process: last_login_at update failed: ' . $e->getMessage(), LOG_LEVEL_WARNING);
}

$mysqli->close();

// Audit success
sf_audit_log('login_success', 'user', (int)$user['id'], [
    'email' => $email,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
]);

// Record successful login attempt (clears failed attempts)
sf_record_login_attempt($email, $clientIp, true);

// Redirect after successful login
// If there's a saved redirect URL, validate and use it; otherwise go to default page
if (!empty($_SESSION['login_redirect'])) {
    $redirect = $_SESSION['login_redirect'];
    unset($_SESSION['login_redirect']);
    
    // Validate that the redirect URL is safe (relative URL only, no external redirects)
    if (sf_is_safe_redirect_url($redirect)) {
        // Safe to redirect
        header('Location: ' . $redirect);
        exit;
    }
    // If validation fails, fall through to default redirect
}

// Default redirect
header('Location: ' . $base . '/index.php?page=list&notice=logged_in');
exit;