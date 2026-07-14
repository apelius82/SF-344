<?php
/**
 * Cache busting helper
 * Adds file modification time as version parameter to prevent stale cache
 */

/**
 * Generate asset URL with cache busting version parameter
 * 
 * @param string $path Relative path to asset (e.g., 'assets/js/form.js')
 * @param string $base Base URL prefix
 * @return string URL with version parameter
 */
function sf_asset_url(string $path, string $base = ''): string {
    // Construct full filesystem path using APP_ROOT constant if available,
    // otherwise fall back to __DIR__ traversal
    if (defined('APP_ROOT')) {
        $fullPath = APP_ROOT . '/' . ltrim($path, '/');
    } else {
        $fullPath = __DIR__ . '/../../' . ltrim($path, '/');
    }
    
    if (file_exists($fullPath)) {
        $version = filemtime($fullPath);
        // Handle filemtime() failure (permissions, race conditions, etc.)
        if ($version === false) {
            // Fallback to current timestamp if filemtime fails
            $version = time();
        }
        $separator = strpos($path, '?') === false ? '?' : '&';
        return rtrim($base, '/') . '/' . ltrim($path, '/') . $separator . 'v=' . $version;
    }
    
    // Fallback: return path without version if file not found
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}