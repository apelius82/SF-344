<?php
// app/actions/set_language.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

$base = rtrim($config['base_url'] ?? '', '/');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $base . '/index.php?page=list');
    exit;
}

// CSRF-tarkistus (jos token mukana, validoi)
$csrfToken = $_POST['csrf_token'] ?? null;
if ($csrfToken !== null && !sf_csrf_validate($csrfToken)) {
    header('Location: ' . $base . '/index.php?page=list');
    exit;
}

$lang = $_POST['lang'] ?? 'fi';
$allowed = ['fi', 'sv', 'en', 'it', 'el'];

if (!in_array($lang, $allowed, true)) {
    $lang = 'fi';
}

$_SESSION['ui_lang'] = $lang;

$user = sf_current_user();
if ($user && !empty($user['id'])) {
    try {
        $mysqli = sf_db();
        $stmt = $mysqli->prepare('UPDATE sf_users SET ui_lang = ? WHERE id = ?');
        if ($stmt) {
            $uid = (int)$user['id'];
            $stmt->bind_param('si', $lang, $uid);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Kielen tallennus tietokantaan epäonnistui: ' . $e->getMessage());
    }
}

// cookie myös
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

setcookie('ui_lang', $lang, [
    'expires'  => time() + (365 * 24 * 60 * 60),
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

$redirect = $_POST['redirect'] ?? ($_SERVER['HTTP_REFERER'] ?? ($base . '/index.php?page=list'));

// salli relative redirectit ("/index.php?...") tai saman hostin absolute
$parsedUrl  = parse_url($redirect);
$parsedBase = parse_url($base);

if (
    isset($parsedUrl['host']) &&
    isset($parsedBase['host']) &&
    $parsedUrl['host'] !== $parsedBase['host']
) {
    $redirect = $base . '/index.php?page=list';
}

header('Location: ' . $redirect);
exit;