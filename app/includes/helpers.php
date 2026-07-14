<?php
/**
 * SafetyFlash General Helpers
 * 
 * General utility functions used across the application.
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
 * Format a datetime as relative time (e.g., "2 hours ago", "yesterday")
 * 
 * @param string $datetime Datetime string (any format parseable by strtotime)
 * @param string $lang Language code (fi, sv, en, it, el)
 * @return string Formatted relative time string
 */
function sf_time_ago(string $datetime, string $lang = 'fi'): string {
    $now = time();
    $time = strtotime($datetime);
    
    // Handle invalid datetime
    if ($time === false) {
        return $datetime;
    }
    
    $diff = $now - $time;
    
    // Less than 24 hours - show "today" or hours ago
    if ($diff < 86400) {
        $hours = floor($diff / 3600);
        if ($hours < 1) {
            return sf_term('time_ago_today', $lang);
        }
        return str_replace('{n}', (string)$hours, sf_term('time_ago_hours', $lang));
    }
    
    // Yesterday
    if ($diff < 172800) {
        return sf_term('time_ago_yesterday', $lang);
    }
    
    // Days ago
    $days = floor($diff / 86400);
    if ($days < 30) {
        return str_replace('{n}', (string)$days, sf_term('time_ago_days', $lang));
    }
    
    // Fallback to date
    return date('d.m.Y', $time);
}