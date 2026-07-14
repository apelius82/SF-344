<?php
/**
 * PWA Manifest - Lightweight version
 * 
 * This file must be fast and error-tolerant.
 * Does NOT load config.php to avoid database/session dependencies.
 */

// Minimal .env reader
$envFile = __DIR__ . '/.env';
$base = '';

if (file_exists($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes from value
            $value = trim($value, '"\'');
            
            if ($key === 'APP_BASE_URL' || $key === 'BASE_URL') {
                $base = rtrim($value, '/');
                break; // Found what we need
            }
        }
    }
}

// Fallback: Try to detect base URL from request
if (empty($base)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    
    // Use SERVER_NAME if available, fallback to HTTP_HOST with validation
    $host = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Basic Host header validation to prevent injection
    // Remove port if present, then validate hostname
    $hostWithoutPort = preg_replace('/:\d+$/', '', $host);
    // Strict hostname validation: alphanumeric start/end, allows dots and hyphens in middle
    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $hostWithoutPort) || 
        strpos($hostWithoutPort, '..') !== false) {
        $host = 'localhost'; // Fallback to safe default
        error_log('manifest.php: Invalid host detected, using localhost');
    }
    
    $scriptPath = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $base = $protocol . '://' . $host . $scriptPath;
    $base = rtrim($base, '/');
    
    error_log('manifest.php: APP_BASE_URL/BASE_URL not found in .env, using fallback: ' . $base);
}

// Set correct content type for manifest
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400'); // Cache 24h

$manifest = [
    "name" => "SafetyFlash - Tapojärvi",
    "short_name" => "SafetyFlash",
    "description" => "Tapojärvi SafetyFlash -turvallisuusilmoitusjärjestelmä",
	"start_url" => "{$base}/index.php?page=list&source=pwa_start",
    "display" => "standalone",
    "orientation" => "portrait-primary",
    "theme_color" => "#0f172a",
    "background_color" => "#0f172a",
    "lang" => "fi",
    "scope" => "{$base}/",
    "icons" => [
        // Any-ikonit (näytetään sellaisenaan)
        [
            "src" => "{$base}/assets/img/icons/pwa-icon-192.png",
            "sizes" => "192x192",
            "type" => "image/png",
            "purpose" => "any"
        ],
        [
            "src" => "{$base}/assets/img/icons/pwa-icon-512.png",
            "sizes" => "512x512",
            "type" => "image/png",
            "purpose" => "any"
        ],
        // Maskable-ikonit (rajataan ympyräksi/muodoksi)
        [
            "src" => "{$base}/assets/img/icons/pwa-icon-maskable-192.png",
            "sizes" => "192x192",
            "type" => "image/png",
            "purpose" => "maskable"
        ],
        [
            "src" => "{$base}/assets/img/icons/pwa-icon-maskable-512.png",
            "sizes" => "512x512",
            "type" => "image/png",
            "purpose" => "maskable"
        ]
    ],
    "categories" => ["business", "productivity"],
    "shortcuts" => [
        [
            "name" => "Uusi SafetyFlash",
            "short_name" => "Uusi",
            "url" => "{$base}/index.php?page=form",
            "icons" => [
                [
                    "src" => "{$base}/assets/img/icons/add_new_icon.png",
                    "sizes" => "96x96",
                    "type" => "image/png"
                ]
            ]
        ],
        [
            "name" => "Lista",
            "url" => "{$base}/index.php?page=list",
            "icons" => [
                [
                    "src" => "{$base}/assets/img/icons/list_icon.png",
                    "sizes" => "96x96",
                    "type" => "image/png"
                ]
            ]
        ]
    ]
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);