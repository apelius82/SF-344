<?php
// app/includes/security_headers.php
declare(strict_types=1);

/**
 * Apply baseline security headers for HTML + API responses.
 * Safe to include multiple times (idempotent-ish).
 *
 * NOTE: For static assets (CSS/JS/images) served by the web server,
 * headers should be configured at the server level.
 */
if (PHP_SAPI === 'cli') {
    return;
}

if (headers_sent()) {
    return;
}

// Environment
$isDev = (defined('APP_ENV') && APP_ENV === 'development');

// --- Content Security Policy ---
// Keep CSP compatible with current app structure (inline scripts).
// In production we avoid unsafe-eval.
$scriptSrc = $isDev ? "'self' 'unsafe-inline' 'unsafe-eval'" : "'self' 'unsafe-inline'";

$csp  = "default-src 'self'; ";
$csp .= "script-src {$scriptSrc}; ";
$csp .= "style-src 'self' 'unsafe-inline'; ";
$csp .= "img-src 'self' data: https:; ";
$csp .= "font-src 'self' data:; ";
$csp .= "connect-src 'self'; ";
$csp .= "object-src 'none'; ";
$csp .= "frame-ancestors 'none'; ";
$csp .= "base-uri 'self'; ";
$csp .= "form-action 'self';";

header('Content-Security-Policy: ' . $csp, false);

// --- Clickjacking / MIME sniffing / referrers ---
header('X-Frame-Options: DENY', false);
header('X-Content-Type-Options: nosniff', false);
header('Referrer-Policy: strict-origin-when-cross-origin', false);

// --- Permissions Policy (very restrictive by default) ---
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()', false);

// --- Modern cross-origin isolation defaults (safe for same-origin apps) ---
header('Cross-Origin-Opener-Policy: same-origin', false);
header('Cross-Origin-Resource-Policy: same-origin', false);

// --- Transport security (only if HTTPS) ---
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if ($isHttps && ! $isDev) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload', false);
}

// --- Cache control for authenticated app pages / API responses ---
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', false);
header('Pragma: no-cache', false);
header('Expires: 0', false);

// Remove info headers when possible
@header_remove('X-Powered-By');
@header_remove('X-AspNet-Version');
?>