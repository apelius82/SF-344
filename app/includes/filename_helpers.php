<?php
/**
 * SafetyFlash Filename Helpers
 * 
 * Helper functions for generating informative download filenames for SafetyFlash exports.
 * 
 * @package SafetyFlash
 * @subpackage Helpers
 */

// Prevent direct access
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    http_response_code(403);
    exit('Access Denied');
}

/**
 * Generate informative filename for SafetyFlash download/export
 * 
 * Format: SF_YYYY_MM_DD_TYPE_Worksite_Title_lang.jpg
 * Example: SF_2026_01_11_RED_Kevitsa_Liukastuminen-portaissa_fi.jpg
 * 
 * @param array $flash Flash data with keys: type, site, title, occurred_at/created_at, lang
 * @param int $cardNumber Optional card number for multi-card flashes (1 or 2)
 * @return string Filename like "SF_2026_01_11_RED_Kevitsa_Liukastuminen-portaissa_fi.jpg"
 */
function sf_generate_download_filename(array $flash, int $cardNumber = 0): string
{
    // Type mapping
    $typeMap = [
        'red' => 'RED',
        'yellow' => 'YELLOW', 
        'green' => 'GREEN',
    ];
    $type = $typeMap[$flash['type'] ?? 'red'] ?? 'RED';
    
    // Date from occurred_at or created_at
    $dateStr = $flash['occurred_at'] ?? $flash['created_at'] ?? date('Y-m-d');
    $timestamp = strtotime($dateStr);
    // Handle invalid date string
    if ($timestamp === false) {
        $timestamp = time();
    }
    $date = date('Y_m_d', $timestamp);
    
    // Worksite name - sanitize for filename
    $site = $flash['site'] ?? 'Unknown';
    $site = sf_sanitize_filename($site, 30);
    
    // Title - sanitize for filename (handle empty titles properly)
    $title = trim($flash['title'] ?? '');
    if ($title !== '') {
        // Transliterate Finnish/Swedish characters
        $title = strtr($title, [
            'ä' => 'a', 'Ä' => 'A',
            'ö' => 'o', 'Ö' => 'O',
            'å' => 'a', 'Å' => 'A',
            'ü' => 'u', 'Ü' => 'U',
        ]);
        
        // Replace non-alphanumeric with hyphen
        $title = preg_replace('/[^A-Za-z0-9]+/', '-', $title) ?? $title;
        
        // Remove leading/trailing hyphens
        $title = trim($title, '-');
        
        // Limit length
        if (strlen($title) > 40) {
            $title = substr($title, 0, 40);
            $title = rtrim($title, '-');
        }
    }
    
    // Language
    $lang = $flash['lang'] ?? 'fi';
    
    // Build filename
    if ($title !== '') {
        $filename = "SF_{$date}_{$type}_{$site}_{$title}_{$lang}";
    } else {
        $filename = "SF_{$date}_{$type}_{$site}_{$lang}";
    }
    
    // Add card number if specified (for green/tutkinta with 2 cards)
    if ($cardNumber > 0) {
        $filename .= "_card{$cardNumber}";
    }
    
    return $filename . '.jpg';
}

/**
 * Sanitize string for use in filename
 * - Replace spaces and special chars with hyphen
 * - Remove consecutive hyphens
 * - Limit length
 * 
 * @param string $name String to sanitize
 * @param int $maxLength Maximum length of resulting string
 * @return string Sanitized filename-safe string
 */
function sf_sanitize_filename(string $name, int $maxLength = 50): string
{
    // Transliterate Finnish/Swedish characters
    $name = strtr($name, [
        'ä' => 'a', 'Ä' => 'A',
        'ö' => 'o', 'Ö' => 'O',
        'å' => 'a', 'Å' => 'A',
        'ü' => 'u', 'Ü' => 'U',
    ]);
    
    // Replace non-alphanumeric with hyphen
    $name = preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? $name;
    
    // Remove leading/trailing hyphens
    $name = trim($name, '-');
    
    // Limit length
    if (strlen($name) > $maxLength) {
        $name = substr($name, 0, $maxLength);
        $name = rtrim($name, '-');
    }
    
    // Fallback if empty
    if ($name === '') {
        $name = 'Unknown';
    }
    
    return $name;
}