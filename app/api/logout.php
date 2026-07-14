<?php
// app/api/logout.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

$base = rtrim($config['base_url'], '/');

// Tallenna käyttäjätiedot ja kieli ennen session tuhoamista
$user = sf_current_user();
$uiLang = $_SESSION['ui_lang'] ?? $_COOKIE['ui_lang'] ?? 'fi';

// === AUDIT LOG - uloskirjautuminen ===
if ($user) {
    sf_audit_log(
        'logout',
        'user',
        (int) $user['id'],
        ['email' => $user['email']]
    );
}

// Tyhjennä sessio
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

// Ohjaa login-sivulle viestillä
header('Location: ' . $base . '/assets/pages/login.php?logged_out=1&lang=' . urlencode($uiLang));
exit;