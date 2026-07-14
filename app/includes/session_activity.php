<?php
// app/includes/session_activity.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit_log.php';

/**
 * Session activity tracking:
 * - Enforce inactivity timeout
 * - Log session_expired into audit log
 * - Update last activity timestamp only when request is real user activity
 *
 * Options:
 *  - is_api (bool)
 *  - is_fetch (bool)
 *  - count_activity (bool)
 */
function sf_session_activity_tick(array $opts = []): void
{
    global $config;

    $user = sf_current_user();
    if (!$user) {
        return;
    }

    $now = time();

    $timeout = (int)($config['session']['timeout'] ?? 0);
    $last    = isset($_SESSION['sf_last_activity']) ? (int)$_SESSION['sf_last_activity'] : $now;
    $gap     = max(0, $now - $last);

    $isApi = !empty($opts['is_api']);
    $isFetch = !empty($opts['is_fetch']);
    $countActivity = array_key_exists('count_activity', $opts) ? (bool)$opts['count_activity'] : true;

    if ($timeout > 0 && $gap > $timeout) {
        sf_audit_log(
            'session_expired',
            'user',
            (int)$user['id'],
            [
                'inactive_seconds' => $gap,
                'timeout_seconds'  => $timeout,
                'path'             => $_SERVER['REQUEST_URI'] ?? '',
                'ip'               => $_SERVER['REMOTE_ADDR'] ?? '',
                'ua'               => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
            ],
            (int)$user['id'],
            'info'
        );

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }

        session_destroy();

        if ($isApi || $isFetch) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Session expired',
                'code' => 'SESSION_EXPIRED'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        sf_redirect_to_login();
    }

    if ($countActivity) {
        $_SESSION['sf_last_activity'] = $now;
        sf_refresh_session_cookie();
    }
}