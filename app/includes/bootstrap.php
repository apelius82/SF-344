<?php
/**
 * SafetyFlash System - Bootstrap Configuration
 *
 * Secure session configuration, security headers, and authentication/CSRF middleware
 * for all API actions.
 *
 * @package SafetyFlash
 * @subpackage Bootstrap
 * @version 1.0.0
 * @author Tapojärvi
 * @created 2025-12-30
 */

// Prevent direct access
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    http_response_code(403);
    exit('Access Denied');
}

// ============================================================================
// ENVIRONMENT CONFIGURATION
// ============================================================================

defined('APP_ROOT')     || define('APP_ROOT', dirname(dirname(__DIR__)));
defined('APP_PATH')     || define('APP_PATH', APP_ROOT . '/app');
defined('CONFIG_PATH')  || define('CONFIG_PATH', APP_PATH . '/config');
defined('INCLUDES_PATH')|| define('INCLUDES_PATH', APP_PATH . '/includes');
defined('LOGS_PATH')    || define('LOGS_PATH', APP_ROOT . '/assets/logs');
defined('TEMP_PATH')    || define('TEMP_PATH', APP_ROOT . '/temp');

// Ensure directories exist
foreach ([LOGS_PATH, TEMP_PATH] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
}

// Load environment configuration
$envFile = APP_ROOT . '/.env';
if (is_file($envFile)) {
    $envConfig = parse_ini_file($envFile, false) ?: [];
    foreach ($envConfig as $key => $value) {
        if (!defined($key)) {
            define($key, $value);
        }
    }
}

// Set error reporting based on environment
$isDevelopment = (defined('APP_ENV') && APP_ENV === 'development');
if ($isDevelopment) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

// ============================================================================
// SECURITY: SESSION CONFIGURATION
// ============================================================================

/**
 * Secure Session Configuration
 *
 * Best practices:
 * - Secure, HttpOnly, SameSite cookie flags
 * - Regeneration on authentication
 * - Strict session timeout
 */
$sessionLifetime = defined('SESSION_LIFETIME') ? (int) SESSION_LIFETIME : 3600;
$sessionDomain   = defined('SESSION_DOMAIN') ? (string) SESSION_DOMAIN : '';
$sessionSecure   = !$isDevelopment;

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'domain'   => $sessionDomain,
        'secure'   => $sessionSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
} else {
    session_set_cookie_params(
        $sessionLifetime,
        '/',
        $sessionDomain,
        $sessionSecure,
        true
    );
}

$sessionName = defined('SESSION_NAME') ? (string) SESSION_NAME : 'SAFETYFLASH_SESSION';
session_name($sessionName);

// Configure session storage (placeholder)
if (defined('SESSION_HANDLER') && SESSION_HANDLER === 'database') {
    // Database-based session handler can be configured here
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================================
// SECURITY: HTTP HEADERS MIDDLEWARE
// ============================================================================

/**
 * Security Headers Configuration
 *
 * Protects against:
 * - XSS
 * - Clickjacking
 * - MIME sniffing
 * - Insecure protocol downgrades
 * - Information disclosure
 */
class SecurityHeadersMiddleware
{
    /**
     * Apply security headers
     *
     * Note: sets JSON Content-Type by default (intended for API actions).
     */
    public static function apply(): void
    {
        // Content Security Policy
        $csp = "default-src 'self'; ";
        $csp .= "script-src 'self' 'unsafe-inline' 'unsafe-eval'; ";
        $csp .= "style-src 'self' 'unsafe-inline'; ";
        $csp .= "img-src 'self' data: https:; ";
        $csp .= "font-src 'self' data:; ";
        $csp .= "connect-src 'self'; ";
        $csp .= "frame-ancestors 'none'; ";
        $csp .= "base-uri 'self'; ";
        $csp .= "form-action 'self'; ";

        header("Content-Security-Policy: {$csp}", false);

        header('X-Content-Type-Options: nosniff', false);
        header('X-Frame-Options: DENY', false);

        // Legacy header (modern browsers rely on CSP)
        header('X-XSS-Protection: 1; mode=block', false);

        header('Referrer-Policy: strict-origin-when-cross-origin', false);
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()', false);

        // Enforce HTTPS (HSTS)
        if (!($GLOBALS['isDevelopment'] ?? false)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload', false);
        }

        // Disable caching for sensitive content
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', false);
        header('Pragma: no-cache', false);
        header('Expires: 0', false);

        // Remove server information (best-effort)
        @header_remove('Server');
        @header_remove('X-Powered-By');
        @header_remove('X-AspNet-Version');

        // Default to JSON for API endpoints (best-effort)
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', false);
        }
    }
}

// Apply security headers immediately
SecurityHeadersMiddleware::apply();

// ============================================================================
// SECURITY: CSRF TOKEN MANAGEMENT
// ============================================================================

/**
 * CSRF Token Manager
 */
class CSRFTokenManager
{
    public const TOKEN_SESSION_KEY = 'csrf_token';
    public const TOKEN_FORM_NAME   = '_csrf_token';
    public const TOKEN_LIFETIME    = 3600; // 1 hour
    public const TOKEN_LENGTH      = 32;

    // Normalized PHP server key for header "X-CSRF-Token"
    private const HEADER_SERVER_KEY = 'HTTP_X_CSRF_TOKEN';

    public static function init(): void
    {
        if (!isset($_SESSION[self::TOKEN_SESSION_KEY])) {
            self::generateToken();
        }
    }

    public static function generateToken(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));

        $_SESSION[self::TOKEN_SESSION_KEY] = [
            'value'      => $token,
            'created'    => time(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address' => self::getClientIP(),
        ];

        return $token;
    }

    public static function getToken(): string
    {
        if (!isset($_SESSION[self::TOKEN_SESSION_KEY])) {
            self::generateToken();
        }
        return (string)($_SESSION[self::TOKEN_SESSION_KEY]['value'] ?? '');
    }

    public static function validate(?string $token = null): bool
    {
        if ($token === null) {
            $token = self::getTokenFromRequest();
        }

        if (!isset($_SESSION[self::TOKEN_SESSION_KEY]) || !is_array($_SESSION[self::TOKEN_SESSION_KEY])) {
            return false;
        }

        $storedToken = $_SESSION[self::TOKEN_SESSION_KEY];
        $storedValue = (string)($storedToken['value'] ?? '');

        if ($token === null || $storedValue === '' || !hash_equals($storedValue, (string)$token)) {
            return false;
        }

        $created = (int)($storedToken['created'] ?? 0);
        if ($created <= 0 || (time() - $created) > self::TOKEN_LIFETIME) {
            return false;
        }

        if (($storedToken['user_agent'] ?? '') !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            return false;
        }

        // Optional IP validation (disable behind LB / mobile networks if needed)
        if (defined('CSRF_VALIDATE_IP') && CSRF_VALIDATE_IP !== false) {
            if (($storedToken['ip_address'] ?? '') !== self::getClientIP()) {
                return false;
            }
        }

        return true;
    }

    public static function getTokenFromRequest(): ?string
    {
        // Header
        if (!empty($_SERVER[self::HEADER_SERVER_KEY])) {
            return (string)$_SERVER[self::HEADER_SERVER_KEY];
        }

        // Form/query parameter
        if (isset($_REQUEST[self::TOKEN_FORM_NAME])) {
            return (string)$_REQUEST[self::TOKEN_FORM_NAME];
        }

        // JSON body
        $jsonInput = file_get_contents('php://input');
        if (!empty($jsonInput)) {
            $data = json_decode($jsonInput, true);
            if (is_array($data) && isset($data[self::TOKEN_FORM_NAME])) {
                return (string)$data[self::TOKEN_FORM_NAME];
            }
        }

        return null;
    }

    public static function getClientIP(): string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = (string)$_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip  = trim((string)($ips[0] ?? ''));
        } else {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
}

CSRFTokenManager::init();

// ============================================================================
// SECURITY: AUTHENTICATION MIDDLEWARE
// ============================================================================

class AuthenticationMiddleware
{
    public const AUTH_SESSION_KEY     = 'authenticated_user';
    public const AUTH_TOKEN_HEADER    = 'HTTP_X_AUTH_TOKEN';
    public const AUTH_BEARER_HEADER   = 'HTTP_AUTHORIZATION';
    public const MIN_PASSWORD_LENGTH  = 12;

    public static function isAuthenticated(): bool
    {
        return isset($_SESSION[self::AUTH_SESSION_KEY])
            && is_array($_SESSION[self::AUTH_SESSION_KEY])
            && !empty($_SESSION[self::AUTH_SESSION_KEY]['user_id']);
    }

    public static function getAuthenticatedUser(): ?array
    {
        return self::isAuthenticated() ? $_SESSION[self::AUTH_SESSION_KEY] : null;
    }

    public static function setAuthenticatedUser(array $userData): void
    {
        session_regenerate_id(true);
        CSRFTokenManager::generateToken();

        $_SESSION[self::AUTH_SESSION_KEY] = [
            'user_id'           => $userData['user_id'] ?? null,
            'username'          => $userData['username'] ?? null,
            'email'             => $userData['email'] ?? null,
            'role'              => $userData['role'] ?? 'user',
            'authenticated_at'  => time(),
            'last_activity'     => time(),
            'ip_address'        => CSRFTokenManager::getClientIP(),
            'user_agent'        => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];
    }

    public static function logout(): void
    {
        unset($_SESSION[self::AUTH_SESSION_KEY]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Verify API token authentication
     *
     * NOTE: expects tokens stored hashed (sha256) in sf_api_tokens.token_hash
     */
    public static function verifyAPIToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        if (!preg_match('/^[a-zA-Z0-9\-_]{32,128}$/', $token)) {
            return false;
        }

        try {
            global $config;

            $pdo = new \PDO(
                'mysql:host=' . $config['db']['host'] .
                ';dbname=' . $config['db']['name'] .
                ';charset=' . $config['db']['charset'],
                $config['db']['user'],
                $config['db']['pass'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            $tokenHash = hash('sha256', $token);

            $stmt = $pdo->prepare(
                'SELECT id, user_id
                 FROM sf_api_tokens
                 WHERE token_hash = :token_hash
                   AND is_revoked = 0
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1'
            );
            $stmt->execute([':token_hash' => $tokenHash]);
            $tokenRecord = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$tokenRecord) {
                return false;
            }

            $updateStmt = $pdo->prepare(
                'UPDATE sf_api_tokens SET last_used_at = NOW() WHERE id = :id'
            );
            $updateStmt->execute([':id' => (int)$tokenRecord['id']]);

            return true;
        } catch (\Throwable $e) {
            error_log('API token verification failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function getAuthorizationToken(): ?string
    {
        // Authorization: Bearer <token>
        $authHeader = $_SERVER[self::AUTH_BEARER_HEADER] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/', (string)$authHeader, $matches)) {
            return (string)$matches[1];
        }

        // Custom header: X-Auth-Token
        if (!empty($_SERVER[self::AUTH_TOKEN_HEADER])) {
            return (string)$_SERVER[self::AUTH_TOKEN_HEADER];
        }

        return null;
    }

    public static function validateSessionSecurity(): bool
    {
        if (!self::isAuthenticated()) {
            return false;
        }

        $user = $_SESSION[self::AUTH_SESSION_KEY];

        if (($user['user_agent'] ?? '') !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            return false;
        }

        if (defined('AUTH_VALIDATE_IP') && AUTH_VALIDATE_IP !== false) {
            if (($user['ip_address'] ?? '') !== CSRFTokenManager::getClientIP()) {
                return false;
            }
        }

        $sessionTimeout = defined('SESSION_TIMEOUT') ? (int) SESSION_TIMEOUT : 1800;
        if (time() - (int)($user['last_activity'] ?? 0) > $sessionTimeout) {
            self::logout();
            return false;
        }

        $_SESSION[self::AUTH_SESSION_KEY]['last_activity'] = time();
        return true;
    }
}

// ============================================================================
// API REQUEST MIDDLEWARE
// ============================================================================

class APIRequestMiddleware
{
    public const SAFE_METHODS           = ['GET', 'HEAD', 'OPTIONS'];
    public const STATE_CHANGING_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    public static function validate(bool $requireAuth = false, bool $validateCSRF = true): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $isStateChanging = in_array($method, self::STATE_CHANGING_METHODS, true);

        if (!in_array($method, array_merge(self::SAFE_METHODS, self::STATE_CHANGING_METHODS), true)) {
            return ['success' => false, 'error' => 'Invalid request method', 'code' => 'INVALID_METHOD'];
        }

        if ($requireAuth) {
            if (!AuthenticationMiddleware::isAuthenticated()) {
                return ['success' => false, 'error' => 'Authentication required', 'code' => 'AUTH_REQUIRED'];
            }
            if (!AuthenticationMiddleware::validateSessionSecurity()) {
                return ['success' => false, 'error' => 'Session validation failed', 'code' => 'SESSION_INVALID'];
            }
        }

        if ($validateCSRF && $isStateChanging) {
            if (!CSRFTokenManager::validate()) {
                return ['success' => false, 'error' => 'CSRF validation failed', 'code' => 'CSRF_INVALID'];
            }
        }

        return ['success' => true];
    }

    public static function getRequestData(): array
    {
        $data = [];

        if (!empty($_GET)) {
            $data = array_merge($data, $_GET);
        }
        if (!empty($_POST)) {
            $data = array_merge($data, $_POST);
        }

        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if ($contentType === 'application/json' || str_starts_with($contentType, 'application/json')) {
            $jsonInput = file_get_contents('php://input');
            if (!empty($jsonInput)) {
                $jsonData = json_decode($jsonInput, true);
                if (is_array($jsonData)) {
                    $data = array_merge($data, $jsonData);
                }
            }
        }

        return $data;
    }
}

// ============================================================================
// SECURITY: INPUT VALIDATION AND SANITIZATION
// ============================================================================

class InputValidator
{
    public static function sanitizeString($input): string
    {
        if (!is_string($input)) {
            return '';
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    public static function validateEmail($email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validateInteger($input): bool
    {
        return filter_var($input, FILTER_VALIDATE_INT) !== false;
    }

    public static function validateURL($url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public static function validatePassword(string $password, int $minLength = 12): array
    {
        $errors = [];

        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }

        return [
            'valid'  => count($errors) === 0,
            'errors' => $errors,
        ];
    }
}

// ============================================================================
// ERROR LOGGING AND MONITORING
// ============================================================================

class SecurityEventLogger
{
    public static function log(string $eventType, array $details = [], string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');

        if (!is_dir(LOGS_PATH)) {
            @mkdir(LOGS_PATH, 0750, true);
        }

        $logFile = LOGS_PATH . '/security.log';

        $user = AuthenticationMiddleware::getAuthenticatedUser();

        $logMessage = json_encode([
            'timestamp'  => $timestamp,
            'level'      => $level,
            'event_type' => $eventType,
            'details'    => $details,
            'ip_address' => CSRFTokenManager::getClientIP(),
            'user_id'    => $user['user_id'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ], JSON_UNESCAPED_UNICODE);

        error_log($logMessage . PHP_EOL, 3, $logFile);
    }

    public static function logAuthEvent(string $eventType, string $username, bool $success = true): void
    {
        self::log($eventType, [
            'username' => $username,
            'success'  => $success,
        ], $success ? 'info' : 'warning');
    }

    public static function logSecurityViolation(string $violationType, array $details = []): void
    {
        self::log('security_violation', array_merge([
            'violation_type' => $violationType,
        ], $details), 'error');
    }
}

// ============================================================================
// RATE LIMITING
// ============================================================================

class RateLimiter
{
    public static function checkLimit(string $identifier, int $maxRequests = 100, int $timeWindow = 60): array
    {
        $cacheKey  = "rate_limit_{$identifier}";
        $cacheFile = TEMP_PATH . '/' . md5($cacheKey);

        $data = [];
        if (is_file($cacheFile)) {
            $data = json_decode((string)file_get_contents($cacheFile), true) ?: [];
        }

        $now = time();
        $windowStart = $now - $timeWindow;

        // Remove old requests outside the time window
        $data = array_filter($data, static function ($timestamp) use ($windowStart) {
            return (int)$timestamp > $windowStart;
        });

        $requestCount = count($data);
        $limited = ($requestCount >= $maxRequests);

        // Add current request
        $data[$now . '_' . uniqid('', true)] = $now;

        file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));

        // Reset timestamp = newest request + window
        $latest = 0;
        foreach ($data as $ts) {
            $latest = max($latest, (int)$ts);
        }

        return [
            'limited'   => $limited,
            'remaining' => max(0, $maxRequests - $requestCount),
            'reset'     => $latest + $timeWindow,
        ];
    }
}

// ============================================================================
// INITIALIZATION COMPLETE
// ============================================================================

function bootstrap_initialize(array $config = []): void
{
    if (!empty($config['apply_headers'])) {
        SecurityHeadersMiddleware::apply();
    }

    if (!empty($config['log_event']) && is_array($config['log_event'])) {
        SecurityEventLogger::log(
            (string)($config['log_event']['type'] ?? 'bootstrap_event'),
            (array)($config['log_event']['details'] ?? [])
        );
    }
}

defined('BOOTSTRAP_READY') || define('BOOTSTRAP_READY', true);

if ($isDevelopment) {
    error_log('SafetyFlash Bootstrap initialized at ' . date('Y-m-d H:i:s'));
}