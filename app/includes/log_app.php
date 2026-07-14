<?php
/**
 * SafetyFlash System - Application Logging Module
 *
 * Lightweight structured logging used across the app (UI + API).
 * Provides sf_app_log() plus a few convenience wrappers.
 *
 * Log files are written under: app/logs/
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Log level constants
// ---------------------------------------------------------------------------

const LOG_LEVEL_DEBUG    = 'DEBUG';
const LOG_LEVEL_INFO     = 'INFO';
const LOG_LEVEL_WARNING  = 'WARNING';
const LOG_LEVEL_ERROR    = 'ERROR';
const LOG_LEVEL_CRITICAL = 'CRITICAL';

// ---------------------------------------------------------------------------
// Core logger
// ---------------------------------------------------------------------------

/**
 * Write message to application log.
 *
 * @param string      $message Log message
 * @param string      $level   One of LOG_LEVEL_*
 * @param array|null  $context Optional structured context (will be JSON encoded)
 * @param string|null $logFile Optional custom filename inside app/logs/
 */
function sf_app_log(
    string $message,
    string $level = LOG_LEVEL_INFO,
    ?array $context = null,
    ?string $logFile = null
): bool {
    $isDevelopment = (defined('APP_ENV') && APP_ENV === 'development');

    // In production we default to INFO and above to allow debugging
    // (changed from WARNING to INFO to capture email debug logs)
    $minLevel = $isDevelopment ? LOG_LEVEL_DEBUG : LOG_LEVEL_INFO;

    if (!sf_should_log($level, $minLevel)) {
        return true;
    }

    try {
        $logDir = __DIR__ . '/../logs'; // => app/logs

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }

        if ($logFile === null) {
            $logFile = match ($level) {
                LOG_LEVEL_ERROR, LOG_LEVEL_CRITICAL => 'sf_errors.log',
                default => 'sf_app.log',
            };
        }

        // Prevent path traversal via a crafted filename
        $logFile = basename($logFile);

        $logPath = $logDir . DIRECTORY_SEPARATOR . $logFile;

        $timestamp = date('Y-m-d H:i:s');
        $entry     = sf_format_log_entry($timestamp, $level, $message, $context);

        $bytes = @file_put_contents($logPath, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($bytes === false) {
            return false;
        }

        // Restrictive permissions
        @chmod($logPath, 0640);

        return true;
    } catch (Throwable $e) {
        // Last resort: PHP error_log (avoid throwing from logger)
        error_log('[sf_app_log] ' . $message);
        return false;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Compare levels to determine if a message should be logged.
 */
function sf_should_log(string $messageLevel, string $minLevel): bool
{
    $levels = [
        LOG_LEVEL_DEBUG    => 0,
        LOG_LEVEL_INFO     => 1,
        LOG_LEVEL_WARNING  => 2,
        LOG_LEVEL_ERROR    => 3,
        LOG_LEVEL_CRITICAL => 4,
    ];

    $msg = $levels[$messageLevel] ?? 1;
    $min = $levels[$minLevel] ?? 1;

    return $msg >= $min;
}

/**
 * Build a single line log entry.
 */
function sf_format_log_entry(
    string $timestamp,
    string $level,
    string $message,
    ?array $context = null
): string {
    $entry = sprintf('[%s] [%s] %s', $timestamp, $level, $message);

    if ($context && is_array($context) && !empty($context)) {
        $safeContext = sf_redact_sensitive_context($context);
        $json = json_encode($safeContext, JSON_UNESCAPED_UNICODE);

        if ($json !== false) {
            $entry .= ' | ' . $json;
        }
    }

    return $entry;
}

/**
 * Redact sensitive keys from logging context.
 */
function sf_redact_sensitive_context(array $context): array
{
    $sensitiveNeedles = [
        'password',
        'pass',
        'pwd',
        'token',
        'secret',
        'api_key',
        'csrf',
        'session',
        'cookie',
        'authorization',
    ];

    $out = [];

    foreach ($context as $key => $value) {
        $keyStr = is_string($key) ? strtolower($key) : (string)$key;

        $sensitive = false;
        foreach ($sensitiveNeedles as $needle) {
            if (strpos($keyStr, $needle) !== false) {
                $sensitive = true;
                break;
            }
        }

        if ($sensitive) {
            $out[$key] = '[REDACTED]';
            continue;
        }

        if (is_array($value)) {
            $out[$key] = sf_redact_sensitive_context($value);
            continue;
        }

        if (is_string($value) && strlen($value) > 2000) {
            $out[$key] = substr($value, 0, 2000) . '...[truncated]';
            continue;
        }

        $out[$key] = $value;
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Convenience wrappers (optional)
// ---------------------------------------------------------------------------

function sf_app_log_event(int $flashId, string $eventType, string $description = '', ?array $context = null): bool
{
    $ctx = array_merge([
        'flash_id'   => $flashId,
        'event_type' => $eventType,
    ], $context ?? []);

    $msg = "Flash #{$flashId} [{$eventType}] {$description}";

    return sf_app_log($msg, LOG_LEVEL_INFO, $ctx, 'events.log');
}

function sf_app_log_database(
    string $operation,
    string $table,
    ?int $affectedRows = null,
    bool $success = true,
    ?string $error = null
): bool {
    $level = $success ? LOG_LEVEL_DEBUG : LOG_LEVEL_WARNING;

    $ctx = [
        'operation' => $operation,
        'table'     => $table,
        'success'   => $success,
    ];

    if ($affectedRows !== null) {
        $ctx['affected_rows'] = $affectedRows;
    }
    if ($error !== null) {
        $ctx['error'] = $error;
    }

    return sf_app_log("DB {$operation} {$table}", $level, $ctx, 'database.log');
}

function sf_app_log_api(
    string $endpoint,
    string $method,
    int $statusCode,
    ?float $executionTime = null,
    ?array $context = null
): bool {
    $level = $statusCode >= 500 ? LOG_LEVEL_ERROR : LOG_LEVEL_DEBUG;

    $ctx = [
        'endpoint'    => $endpoint,
        'method'      => $method,
        'status_code' => $statusCode,
    ];

    if ($executionTime !== null) {
        $ctx['execution_time_ms'] = round($executionTime * 1000, 2);
    }

    if ($context) {
        $ctx = array_merge($ctx, $context);
    }

    return sf_app_log("{$method} {$endpoint} - {$statusCode}", $level, $ctx, 'api.log');
}

function sf_app_log_performance(string $operation, float $duration, ?array $context = null): bool
{
    // Only log if exceeds threshold (e.g., > 1 second)
    $threshold = 1.0;

    if ($duration < $threshold) {
        return true;
    }

    $ctx = [
        'operation'   => $operation,
        'duration_ms' => round($duration * 1000, 2),
    ];

    if ($context) {
        $ctx = array_merge($ctx, $context);
    }

    return sf_app_log("Performance: {$operation}", LOG_LEVEL_WARNING, $ctx, 'performance.log');
}