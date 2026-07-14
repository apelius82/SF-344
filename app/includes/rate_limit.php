<?php
// app/includes/rate_limit.php
declare(strict_types=1);

// Configuration - prevent redefinition
if (!defined('SF_LOGIN_MAX_ATTEMPTS')) {
    define('SF_LOGIN_MAX_ATTEMPTS', 5);
}
if (!defined('SF_LOGIN_LOCKOUT_MINUTES')) {
    define('SF_LOGIN_LOCKOUT_MINUTES', 15);
}
if (!defined('SF_LOGIN_ATTEMPT_WINDOW_MINUTES')) {
    define('SF_LOGIN_ATTEMPT_WINDOW_MINUTES', 15);
}

// Prevent function redefinition errors
if (!function_exists('sf_check_login_allowed')) {
    function sf_check_login_allowed(string $email, string $ip): array
    {
        try {
            $mysqli = sf_db();
            
            $windowStart = date('Y-m-d H:i:s', strtotime('-' . SF_LOGIN_ATTEMPT_WINDOW_MINUTES . ' minutes'));
            
            $stmt = $mysqli->prepare("
                SELECT COUNT(*) as attempt_count, MAX(attempted_at) as last_attempt
                FROM sf_login_attempts 
                WHERE (ip_address = ? OR email = ?) 
                AND attempted_at > ?
                AND success = 0
            ");
            
            if (!$stmt) {
                return ['allowed' => true, 'remaining' => SF_LOGIN_MAX_ATTEMPTS, 'locked_until' => null, 'wait_seconds' => 0];
            }
            
            $stmt->bind_param('sss', $ip, $email, $windowStart);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            $attemptCount = (int)($row['attempt_count'] ?? 0);
            $lastAttempt = $row['last_attempt'] ?? null;
            
            if ($attemptCount >= SF_LOGIN_MAX_ATTEMPTS && $lastAttempt) {
                $lockoutEnd = strtotime($lastAttempt) + (SF_LOGIN_LOCKOUT_MINUTES * 60);
                $now = time();
                
                if ($now < $lockoutEnd) {
                    return [
                        'allowed' => false,
                        'remaining' => 0,
                        'locked_until' => date('Y-m-d H:i:s', $lockoutEnd),
                        'wait_seconds' => $lockoutEnd - $now
                    ];
                }
            }
            
            return [
                'allowed' => true,
                'remaining' => max(0, SF_LOGIN_MAX_ATTEMPTS - $attemptCount),
                'locked_until' => null,
                'wait_seconds' => 0
            ];
        } catch (Throwable $e) {
            return ['allowed' => true, 'remaining' => SF_LOGIN_MAX_ATTEMPTS, 'locked_until' => null, 'wait_seconds' => 0];
        }
    }
}

if (!function_exists('sf_record_login_attempt')) {
    function sf_record_login_attempt(string $email, string $ip, bool $success): void
    {
        try {
            $mysqli = sf_db();
            
            $stmt = $mysqli->prepare("
                INSERT INTO sf_login_attempts (email, ip_address, success, attempted_at)
                VALUES (?, ?, ?, NOW())
            ");
            
            if (!$stmt) {
                return;
            }
            
            $successInt = $success ? 1 : 0;
            $stmt->bind_param('ssi', $email, $ip, $successInt);
            $stmt->execute();
            $stmt->close();
            
            if ($success) {
                $stmt = $mysqli->prepare("DELETE FROM sf_login_attempts WHERE email = ? AND success = 0");
                if ($stmt) {
                    $stmt->bind_param('s', $email);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } catch (Throwable $e) {
            // Silently fail
        }
    }
}

if (!function_exists('sf_get_client_ip')) {
    function sf_get_client_ip(): string
    {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return $_SERVER['REMOTE_ADDR'];
        }
        
        return '0.0.0.0';
    }
}

if (!function_exists('sf_cleanup_old_login_attempts')) {
    function sf_cleanup_old_login_attempts(): int
    {
        try {
            $mysqli = sf_db();
            $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $stmt = $mysqli->prepare("DELETE FROM sf_login_attempts WHERE attempted_at < ?");
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('s', $cutoff);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            return $deleted;
        } catch (Throwable $e) {
            return 0;
        }
    }
}