<?php
/**
 * SafetyFlash System - Global Configuration
 * 
 * Central configuration for the entire application including: 
 * - Database connectivity
 * - Security settings
 * - Storage paths and URLs
 * - Environment-specific settings
 * - Third-party service credentials
 * 
 * Environment variables are loaded from .env file in project root. 
 * All sensitive data should be stored in .env and never committed to version control.
 * 
 * @package SafetyFlash
 * @subpackage Core
 * @version 1.0.0
 * @author apelius82
 * @created 2025-12-30
 */

// ============================================================================
// ENVIRONMENT SETUP
// ============================================================================

// Set default timezone
date_default_timezone_set('Europe/Helsinki');

// Define application root
defined('APP_ROOT') or define('APP_ROOT', __DIR__);

// Load environment variables from .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $envLine) {
        // Skip comments
        if (strpos(trim($envLine), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE format
        if (strpos($envLine, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $envLine, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        // Set as environment variable and in $_ENV array
        if (! empty($key)) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;

            // Also define as constant for convenience
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}

// Set environment type (development, staging, production)
$environment = getenv('APP_ENV') ?: 'production';
if (! defined('APP_ENV')) {
    define('APP_ENV', $environment);
}

// ============================================================================
// SECURITY CONFIGURATION
// ============================================================================
// ============================================================================
// BASE URL AUTO-DETECTION (HOST-SAFE FOR SHARED HOSTING)
// ============================================================================
// IMPORTANT: In shared hosting the APP_BASE_URL in .env may point to an old domain.
// That can cause session cookies to be set for the wrong host and authenticated
// POST/AJAX actions to return 401. We therefore detect the current request host
// and scheme, and use APP_BASE_URL only for its PATH component (subfolder).
// ============================================================================
// BASE URL AUTO-DETECTION (HOST-SAFE FOR SHARED HOSTING)
// ============================================================================
// IMPORTANT: In shared hosting the APP_BASE_URL in .env may point to an old domain.
// That can cause session cookies to be set for the wrong host and authenticated
// POST/AJAX actions to return 401. We therefore detect the current request host
// and scheme, and use APP_BASE_URL only for its PATH component (subfolder).
//
// FIX: Some hosting setups report SCRIPT_NAME as "/index.php" even when the app
// is accessed from "/safetyflash-system/...". In that case dirname(SCRIPT_NAME)
// becomes "", and SF_BASE_URL loses "/safetyflash-system" → all asset URLs break.
// We therefore derive base_path primarily from REQUEST_URI and normalize away
// "/app/", "/assets/", "/uploads/" etc so API calls also resolve to the project root.
$sf_env_base  = (string)(getenv('APP_BASE_URL') ?: '');
$sf_base_path = '';

// 1) Prefer path from APP_BASE_URL (supports full URL or just "/subfolder")
if ($sf_env_base !== '') {
    $p = parse_url($sf_env_base, PHP_URL_PATH);
    if (is_string($p) && $p !== '') {
        $sf_base_path = rtrim($p, '/');
    }
}

/**
 * 2) Robust fallback: REQUEST_URI (works even if SCRIPT_NAME is "/index.php")
 * Normalize subpaths back to project root.
 */
if ($sf_base_path === '') {
    $reqPath = (string) parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $reqPath = str_replace('\\', '/', $reqPath);

    if ($reqPath !== '') {
        // Strip known internal folders so base becomes project root
        foreach (['/app/', '/assets/', '/uploads/', '/temp/', '/logs/', '/xibo/'] as $needle) {
            $pos = strpos($reqPath, $needle);
            if ($pos !== false) {
                $reqPath = substr($reqPath, 0, $pos);
                break;
            }
        }

        // If path ends with a script under base, use its directory
        if (preg_match('#/(index\.php|upload\.php)$#', $reqPath)) {
            $reqPath = dirname($reqPath);
        }

        $dir = rtrim($reqPath, '/');
        $sf_base_path = ($dir === '/' || $dir === '.' ) ? '' : $dir;
    }
}

// 3) Last fallback: SCRIPT_NAME
if ($sf_base_path === '') {
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $scriptName = str_replace('\\', '/', $scriptName);

    if ($scriptName !== '') {
        foreach (['/app/', '/assets/', '/uploads/', '/temp/', '/logs/', '/xibo/'] as $needle) {
            $pos = strpos($scriptName, $needle);
            if ($pos !== false) {
                $scriptName = substr($scriptName, 0, $pos);
                break;
            }
        }

        $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $sf_base_path = ($dir === '/' || $dir === '.' ) ? '' : $dir;
    }
}

if ($sf_base_path !== '' && $sf_base_path[0] !== '/') {
    $sf_base_path = '/' . $sf_base_path;
}

// Detect scheme/host from request (proxy-aware)
$sf_is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

$sf_scheme = $sf_is_https ? 'https' : 'http';
$sf_host   = (string)($_SERVER['HTTP_HOST'] ?? '');

if ($sf_host !== '') {
    $sf_base_url = $sf_scheme . '://' . $sf_host . $sf_base_path;
} else {
    // CLI fallback
    $sf_base_url = rtrim($sf_env_base, '/');
    if ($sf_base_url === '') {
        $sf_base_url = $sf_base_path;
    }
}

$sf_cookie_path = ($sf_base_path === '') ? '/' : ($sf_base_path . '/');

$sf_cookie_path = ($sf_base_path === '') ? '/' : ($sf_base_path . '/');
$config = [
    // ========================================================================
    // ENVIRONMENT & DEBUG
    // ========================================================================
    'environment' => APP_ENV,
    'debug' => APP_ENV === 'development' || getenv('APP_DEBUG') === 'true',

    // ========================================================================
    // APPLICATION IDENTITY
    // ========================================================================
    'app_name' => 'SafetyFlash System',
    'app_version' => '1.0.0',
'base_url' => rtrim($sf_base_url, '/'),
'base_path' => $sf_base_path,
    // ========================================================================
    // DATABASE CONFIGURATION
    // ========================================================================
    'db' => [
        'host'     => getenv('DB_HOST') ?: 'localhost',
        'port'     => (int) (getenv('DB_PORT') ?: 3306),
        'name'     => getenv('DB_NAME') ?: '',
        'user'     => getenv('DB_USER') ?: '',
        'pass'     => getenv('DB_PASS') ?: '',
        'charset'  => 'utf8mb4',
        'timezone' => '+00:00',
        'ssl'      => getenv('DB_SSL') === 'true', // Enable SSL for DB connections
        'verify_ssl' => getenv('DB_VERIFY_SSL') !== 'false', // Verify SSL certificates
    ],

    // ========================================================================
    // SESSION CONFIGURATION
    // ========================================================================
    'session' => [
        'path' => $sf_cookie_path, // Cookie path matches subfolder install
        'name' => getenv('SESSION_NAME') ?: 'SAFETYFLASH_SESSION',
        'lifetime' => (int) (getenv('SESSION_LIFETIME') ?: 28800), // 8 hours, rolling cookie
        'timeout' => (int) (getenv('SESSION_TIMEOUT') ?: 14400),   // 4 hours inactivity
        'warning_before' => (int) (getenv('SESSION_WARNING_BEFORE') ?: 300), // Show warning 5 minutes before logout
        'domain' => getenv('SESSION_DOMAIN') ?: '',
        'secure' => APP_ENV === 'production', // HTTPS only in production
        'httponly' => true,
        'samesite' => 'Strict',
    ],

    // ========================================================================
    // CSRF & SECURITY TOKENS
    // ========================================================================
    'csrf' => [
        'enabled' => getenv('CSRF_ENABLED') !== 'false',
        'validate_ip' => getenv('CSRF_VALIDATE_IP') === 'true',
        'token_lifetime' => (int) (getenv('CSRF_TOKEN_LIFETIME') ?: 3600),
    ],

    // ========================================================================
    // AUTHENTICATION
    // ========================================================================
    'auth' => [
        'validate_ip' => getenv('AUTH_VALIDATE_IP') === 'true',
        'lock_after_failed_attempts' => (int) (getenv('AUTH_LOCK_AFTER_ATTEMPTS') ?: 5),
        'lock_duration_minutes' => (int) (getenv('AUTH_LOCK_DURATION') ?: 15),
        'password_min_length' => (int) (getenv('AUTH_PASSWORD_MIN_LENGTH') ?: 12),
        'require_special_chars' => getenv('AUTH_REQUIRE_SPECIAL_CHARS') !== 'false',
        'session_regenerate_on_login' => true,
    ],

    // ========================================================================
    // FILE UPLOAD CONFIGURATION
    // ========================================================================
    'upload' => [
        'enabled' => getenv('UPLOAD_ENABLED') !== 'false',
        'max_file_size' => (int) (getenv('UPLOAD_MAX_SIZE') ?: 5 * 1024 * 1024), // 5MB
        'allowed_types' => explode(',', getenv('UPLOAD_ALLOWED_TYPES') ?: 'image/jpeg,image/png'),
        'allowed_extensions' => explode(',', getenv('UPLOAD_ALLOWED_EXT') ?: 'jpg,jpeg,png'),
        'scan_virus' => getenv('UPLOAD_SCAN_VIRUS') === 'true',
        'quarantine_suspicious' => getenv('UPLOAD_QUARANTINE') === 'true',
    ],

    // ========================================================================
    // STORAGE PATHS
    // ========================================================================
    'storage' => [
        'images_dir' => getenv('STORAGE_IMAGES_DIR') ?: __DIR__ . '/uploads/images',
'images_url' => getenv('STORAGE_IMAGES_URL') ?: (($sf_base_path === '' ? '' : $sf_base_path) . '/uploads/images'),
        'temp_dir' => getenv('STORAGE_TEMP_DIR') ?: __DIR__ . '/temp',
        'logs_dir' => getenv('STORAGE_LOGS_DIR') ?: __DIR__ . '/logs',
    ],

    // ========================================================================
    // LOGGING CONFIGURATION
    // ========================================================================
    'logging' => [
        'enabled' => getenv('LOGGING_ENABLED') !== 'false',
        'level' => APP_ENV === 'development' ? 'DEBUG' : 'WARNING',
        'max_file_size' => (int) (getenv('LOG_MAX_SIZE') ?: 10 * 1024 * 1024), // 10MB
        'retention_days' => (int) (getenv('LOG_RETENTION_DAYS') ?: 30),
        'audit_to_file' => getenv('LOG_AUDIT_TO_FILE') !== 'false',
        'audit_to_db' => getenv('LOG_AUDIT_TO_DB') !== 'false',
    ],

    // ========================================================================
    // RATE LIMITING
    // ========================================================================
    'rate_limit' => [
        'enabled' => getenv('RATE_LIMIT_ENABLED') !== 'false',
        'requests_per_minute' => (int) (getenv('RATE_LIMIT_RPM') ?: 100),
        'requests_per_hour' => (int) (getenv('RATE_LIMIT_RPH') ?: 2000),
        'burst_allowance' => 1.5, // Allow 50% burst
    ],

    // ========================================================================
    // API CONFIGURATION
    // ========================================================================
    'api' => [
        'enabled' => getenv('API_ENABLED') !== 'false',
        'require_authentication' => getenv('API_REQUIRE_AUTH') !== 'false',
        'require_https' => APP_ENV === 'production',
        'cors_enabled' => getenv('API_CORS_ENABLED') === 'true',
        'cors_origins' => explode(',', getenv('API_CORS_ORIGINS') ?: '*'),
    ],

    // ========================================================================
    // EMAIL CONFIGURATION
    // ========================================================================
    'email' => [
        'enabled' => getenv('EMAIL_ENABLED') === 'true',
        'driver' => getenv('EMAIL_DRIVER') ?: 'sendmail', // sendmail, smtp
        'from_address' => getenv('EMAIL_FROM') ?: 'noreply@safetyflash.local',
        'from_name' => getenv('EMAIL_FROM_NAME') ?: 'SafetyFlash System',
        'smtp' => [
            'host' => getenv('SMTP_HOST'),
            'port' => (int) (getenv('SMTP_PORT') ?: 587),
            'username' => getenv('SMTP_USER'),
            'password' => getenv('SMTP_PASS'),
            'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls', // tls, ssl
        ],
    ],

    // ========================================================================
    // FEATURES & FLAGS
    // ========================================================================
    'features' => [
        'two_factor_auth' => getenv('FEATURE_2FA') === 'true',
        'user_registration' => getenv('FEATURE_REGISTRATION') !== 'false',
        'password_reset' => getenv('FEATURE_PASSWORD_RESET') !== 'false',
        'api_keys' => getenv('FEATURE_API_KEYS') === 'true',
        'webhooks' => getenv('FEATURE_WEBHOOKS') === 'true',
    ],

    // ========================================================================
    // CACHE CONFIGURATION (if used)
    // ========================================================================
    'cache' => [
        'enabled' => getenv('CACHE_ENABLED') === 'true',
        'driver' => getenv('CACHE_DRIVER') ?: 'file', // file, redis, memcached
        'ttl' => (int) (getenv('CACHE_TTL') ?: 3600),
    ],
];

// ============================================================================
// INITIALIZE SERVICES
// ============================================================================

/**
 * Initialize Database Service
 * 
 * Sets up PDO database connection singleton
 */
require_once __DIR__ . '/assets/lib/Database.php';
Database::setConfig($config['db'] ?? []);

/**
 * Cache busting helper
 * 
 * Provides sf_asset_url() function for automatic cache busting
 */
require_once __DIR__ . '/assets/lib/cache_bust.php';

/**
 * Validate critical configuration
 * 
 * Ensures required settings are present
 */
if (empty($config['db']['name']) || empty($config['db']['user'])) {
    if ($config['debug']) {
        die('ERROR: Database configuration is incomplete. Check .env file.');
    } else {
        error_log('Configuration error: Database settings missing');
        http_response_code(500);
        die('System configuration error');
    }
}

// ============================================================================
// APPLICATION CONSTANTS
// ============================================================================

// Define commonly used paths as constants
defined('CONFIG_PATH') or define('CONFIG_PATH', __DIR__ . '/app/config');
defined('INCLUDES_PATH') or define('INCLUDES_PATH', __DIR__ . '/app/includes');
defined('PAGES_PATH') or define('PAGES_PATH', __DIR__ . '/assets/pages');
defined('API_PATH') or define('API_PATH', __DIR__ . '/app/api');
defined('UPLOADS_PATH') or define('UPLOADS_PATH', $config['storage']['images_dir']);
defined('LOGS_PATH') or define('LOGS_PATH', $config['storage']['logs_dir']);
defined('TEMP_PATH') or define('TEMP_PATH', $config['storage']['temp_dir']);

// ============================================================================
// SECURITY HEADERS
// ============================================================================

// Apply baseline security headers for all PHP responses (HTML + API)
require_once __DIR__ . '/app/includes/security_headers.php';
// ============================================================================
// RETURN CONFIGURATION
// ============================================================================

return $config;

?>