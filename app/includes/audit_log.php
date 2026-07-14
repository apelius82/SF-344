<?php
/**
 * SafetyFlash System - Audit Logging Module
 *
 * Writes security-relevant events to the sf_audit_log table (and optionally to a file).
 * This is used by many actions and API endpoints, so this file MUST be syntax-safe.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/log_app.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/**
 * Actions that should always be logged to file as well (defense-in-depth).
 */
const CRITICAL_AUDIT_ACTIONS = [
    'user_login',
    'user_logout',
    'user_login_failed',
    'user_created',
    'user_updated',
    'user_deleted',
    'user_password_changed',
    'user_role_changed',
    'flash_created',
    'flash_updated',
    'flash_deleted',
    'flash_published',
    'flash_status_changed',
    'image_uploaded',
    'image_deleted',
    'worksite_created',
    'worksite_updated',
    'worksite_deleted',
    'admin_action',
    'csrf_validation_failed',
    'auth_failed',
    'permission_denied',
    'settings_updated',
    'flash_info_request',
    'flash_to_comms',
    'flash_publish_direct',
];

// ---------------------------------------------------------------------------
// Core audit logger
// ---------------------------------------------------------------------------

/**
 * Log an audit event.
 *
 * @param string      $action      Audit action code (e.g. 'user_login', 'flash_deleted')
 * @param string|null $targetType  Entity type (e.g. 'user', 'flash', 'worksite')
 * @param int|null    $targetId    Entity id
 * @param array|null  $details     Additional details (stored as JSON)
 * @param int|null    $userId      User id (if null -> use current session user if available)
 * @param string|null $logLevel    'debug'|'info'|'warning'|'error'|'critical'
 */
function sf_audit_log(
    string $action,
    ?string $targetType = null,
    ?int $targetId = null,
    ?array $details = null,
    ?int $userId = null,
    ?string $logLevel = null
): bool {
    // Default log level
    if ($logLevel === null) {
        $logLevel = in_array($action, CRITICAL_AUDIT_ACTIONS, true) ? 'info' : 'debug';
    }

    // User context
    $userEmail = null;
    $userName  = null;

    if ($userId === null) {
        $user = sf_current_user();
        if ($user) {
            $userId    = isset($user['id']) ? (int)$user['id'] : null;
            $userEmail = $user['email'] ?? null;
            // sf_users käyttää first_name/last_name -kenttiä (ei "username")
$userName  = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
if ($userName === '') {
    $userName = null;
}
        }
    } else {
        // Lookup email/name if we have a user id
        try {
            $mysqli = sf_db();
            $stmt = $mysqli->prepare('SELECT email, first_name, last_name FROM sf_users WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($row) {
                    $userEmail = $row['email'] ?? null;
                    $tmpName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
$userName = $tmpName !== '' ? $tmpName : null;
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            // Don't fail the request because of audit lookup
            sf_app_log('Audit user lookup failed: ' . $e->getMessage(), LOG_LEVEL_WARNING);
        }
    }

    // Request context
    $ipAddress     = sf_get_client_ip();
    $userAgent     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $requestUri    = $_SERVER['REQUEST_URI'] ?? '';
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

    // Build details payload
    $payload = [
        'timestamp'       => date('c'),
        'action'          => $action,
        'target_type'     => $targetType,
        'target_id'       => $targetId,
        'ip_address'      => $ipAddress,
        'request_uri'     => $requestUri,
        'request_method'  => $requestMethod,
    ];

    if ($details) {
        $payload['custom_details'] = sf_redact_sensitive_fields($details);
    }

    $detailsJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($detailsJson === false) {
        $detailsJson = '{}';
    }

    $dbOk = false;

    // --- Database audit log ---
    try {
        $mysqli = sf_db();

// Huom: kaikissa ympäristöissä sf_audit_log-taulussa ei ole user_name -kenttää.
$sql = 'INSERT INTO sf_audit_log
            (user_id, user_email, action, target_type, target_id, details, ip_address, user_agent, log_level, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception('Audit prepare failed: ' . $mysqli->error);
        }

        // NOTE: mysqli bind_param can handle NULL for nullable columns in most setups.
$stmt->bind_param(
            'isssissss',
            $userId,
            $userEmail,
            $action,
            $targetType,
            $targetId,
            $detailsJson,
            $ipAddress,
            $userAgent,
            $logLevel
        );

        $dbOk = $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        sf_app_log('Audit DB write failed: ' . $e->getMessage(), LOG_LEVEL_WARNING);
        $dbOk = false;
    }

    // --- File audit log (critical actions OR DB failure) ---
    if (in_array($action, CRITICAL_AUDIT_ACTIONS, true) || !$dbOk) {
        sf_audit_log_to_file([
            'timestamp'   => date('c'),
            'action'      => $action,
            'user_id'     => $userId,
            'user_email'  => $userEmail,
            'user_name'   => $userName,
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'details'     => $payload,
            'log_level'   => $logLevel,
        ]);
    }

    return $dbOk;
}

/**
 * Append a single JSON line to app/logs/audit.log.
 *
 * @param array $entry
 */
function sf_audit_log_to_file(array $entry): bool
{
    try {
        $logDir = __DIR__ . '/../logs'; // app/logs
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }

        $file = $logDir . DIRECTORY_SEPARATOR . 'audit.log';

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            $line = '{}';
        }

        $bytes = @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($bytes === false) {
            return false;
        }

        @chmod($file, 0640);
        return true;
    } catch (Throwable $e) {
        // Last resort
        error_log('Audit file write failed: ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------
// Convenience wrappers (optional)
// ---------------------------------------------------------------------------

function sf_audit_log_auth(
    string $eventType,
    ?int $userId,
    ?string $username,
    bool $success = true,
    ?string $reason = null
): bool {
    $action = 'user_' . $eventType;
    if (!$success) {
        $action .= '_failed';
    }

    $details = [
        'username' => $username,
        'success'  => $success,
    ];

    if ($reason) {
        $details['reason'] = $reason;
    }

    return sf_audit_log($action, 'user', $userId, $details, $userId, $success ? 'info' : 'warning');
}

function sf_audit_log_data_change(
    string $entityType,
    int $entityId,
    string $operation,
    ?array $oldData = null,
    ?array $newData = null,
    ?int $userId = null
): bool {
    $action = $entityType . '_' . $operation;

    $details = [
        'operation' => $operation,
    ];

    if ($oldData) {
        $details['old_values'] = sf_redact_sensitive_fields($oldData);
    }
    if ($newData) {
        $details['new_values'] = sf_redact_sensitive_fields($newData);
    }

    return sf_audit_log($action, $entityType, $entityId, $details, $userId);
}

function sf_audit_log_access_denied(
    string $attemptedAction,
    ?string $targetType = null,
    ?int $targetId = null,
    ?string $reason = null
): bool {
    return sf_audit_log(
        'permission_denied',
        $targetType,
        $targetId,
        [
            'attempted_action' => $attemptedAction,
            'reason'           => $reason,
        ],
        null,
        'warning'
    );
}

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

/**
 * Best-effort IP detection.
 *
 * NOTE: X-Forwarded-For can be spoofed unless your proxy strips/sets it.
 * If you're behind a trusted reverse proxy (nginx, Cloudflare, etc.),
 * configure it to pass the correct client IP and consider whitelisting
 * proxy IPs server-side.
 */
function sf_get_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_REAL_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $value) {
        if (!$value) {
            continue;
        }

        // X-Forwarded-For may contain a list: client, proxy1, proxy2...
        if (strpos($value, ',') !== false) {
            $parts = array_map('trim', explode(',', $value));
        } else {
            $parts = [trim($value)];
        }

        foreach ($parts as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

/**
 * Redact sensitive keys from arrays before storing to audit log.
 */
function sf_redact_sensitive_fields(array $data): array
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

    foreach ($data as $key => $value) {
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
            $out[$key] = sf_redact_sensitive_fields($value);
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


/**
 * Human readable labels for audit action codes (UI).
 *
 * This is used in Settings -> Audit log filters/table.
 */
function sf_audit_action_label(string $action, string $lang = 'fi'): string
{
    $labels = [
        'login_success' => [
            'fi' => 'Kirjautuminen onnistui',
            'sv' => 'Inloggning lyckades',
            'en' => 'Login success',
            'it' => 'Accesso riuscito',
            'el' => 'Επιτυχής σύνδεση',
        ],
        'login_failed' => [
            'fi' => 'Kirjautuminen epäonnistui',
            'sv' => 'Inloggning misslyckades',
            'en' => 'Login failed',
            'it' => 'Accesso fallito',
            'el' => 'Αποτυχία σύνδεσης',
        ],
        'logout' => [
            'fi' => 'Uloskirjautuminen',
            'sv' => 'Utloggning',
            'en' => 'Logout',
            'it' => 'Disconnessione',
            'el' => 'Αποσύνδεση',
        ],
        'permission_denied' => [
            'fi' => 'Pääsy estetty',
            'sv' => 'Åtkomst nekad',
            'en' => 'Access denied',
            'it' => 'Accesso negato',
            'el' => 'Πρόσβαση απορρίφθηκε',
        ],
        'csrf_validation_failed' => [
            'fi' => 'CSRF-tarkistus epäonnistui',
            'sv' => 'CSRF-kontroll misslyckades',
            'en' => 'CSRF validation failed',
            'it' => 'Convalida CSRF fallita',
            'el' => 'Η επικύρωση CSRF απέτυχε',
        ],

        // Safetyflash
        'flash_create' => [
            'fi' => 'Safetyflash luotu',
            'sv' => 'Safetyflash skapad',
            'en' => 'Safetyflash created',
            'it' => 'Safetyflash creato',
            'el' => 'Δημιουργήθηκε Safetyflash',
        ],
        'flash_update' => [
            'fi' => 'Safetyflash päivitetty',
            'sv' => 'Safetyflash uppdaterad',
            'en' => 'Safetyflash updated',
            'it' => 'Safetyflash aggiornato',
            'el' => 'Safetyflash ενημερώθηκε',
        ],
        'flash_delete' => [
            'fi' => 'Safetyflash poistettu',
            'sv' => 'Safetyflash borttagen',
            'en' => 'Safetyflash deleted',
            'it' => 'Safetyflash eliminato',
            'el' => 'Safetyflash διαγράφηκε',
        ],
        'flash_publish' => [
            'fi' => 'Safetyflash julkaistu',
            'sv' => 'Safetyflash publicerad',
            'en' => 'Safetyflash published',
            'it' => 'Safetyflash pubblicato',
            'el' => 'Safetyflash δημοσιεύτηκε',
        ],
        'flash_bulk_delete' => [
            'fi' => 'Useita Safetyflasheja poistettu',
            'sv' => 'Flera Safetyflash borttagna',
            'en' => 'Bulk delete',
            'it' => 'Eliminazione multipla Safetyflash',
            'el' => 'Μαζική διαγραφή Safetyflash',
        ],
        'flash_comment' => [
            'fi' => 'Kommentti lisätty',
            'sv' => 'Kommentar tillagd',
            'en' => 'Comment added',
            'it' => 'Commento aggiunto',
            'el' => 'Προστέθηκε σχόλιο',
        ],
        'report_pdf_generated' => [
            'fi' => 'PDF-raportti generoitu',
            'sv' => 'PDF-rapport genererad',
            'en' => 'PDF report generated',
            'it' => 'Rapporto PDF generato',
            'el' => 'Δημιουργήθηκε αναφορά PDF',
        ],

        // Users
        'user_create' => [
            'fi' => 'Käyttäjä luotu',
            'sv' => 'Användare skapad',
            'en' => 'User created',
            'it' => 'Utente creato',
            'el' => 'Ο χρήστης δημιουργήθηκε',
        ],
        'user_update' => [
            'fi' => 'Käyttäjä päivitetty',
            'sv' => 'Användare uppdaterad',
            'en' => 'User updated',
            'it' => 'Utente aggiornato',
            'el' => 'Ο χρήστης ενημερώθηκε',
        ],
        'user_delete' => [
            'fi' => 'Käyttäjä poistettu',
            'sv' => 'Användare borttagen',
            'en' => 'User deleted',
            'it' => 'Utente eliminato',
            'el' => 'Ο χρήστης διαγράφηκε',
        ],
        'user_password_changed' => [
            'fi' => 'Käyttäjän salasana vaihdettu',
            'sv' => 'Användarens lösenord ändrat',
            'en' => 'User password changed',
            'it' => 'Password utente modificata',
            'el' => 'Ο κωδικός χρήστη άλλαξε',
        ],
        'settings_updated' => [
            'fi' => 'Asetukset päivitetty',
            'sv' => 'Inställningar uppdaterade',
            'en' => 'Settings updated',
            'it' => 'Impostazioni aggiornate',
            'el' => 'Οι ρυθμίσεις ενημερώθηκαν',
        ],
        'flash_info_request' => [
            'fi' => 'Palautettu korjattavaksi',
            'sv' => 'Returnerad för korrigering',
            'en' => 'Returned for corrections',
            'it' => 'Restituito per correzioni',
            'el' => 'Επιστράφηκε για διορθώσεις',
        ],
        'flash_to_comms' => [
            'fi' => 'Lähetetty viestintään',
            'sv' => 'Skickad till kommunikation',
            'en' => 'Sent to communications',
            'it' => 'Inviato alle comunicazioni',
            'el' => 'Στάλθηκε στις επικοινωνίες',
        ],
        'flash_publish_direct' => [
            'fi' => 'Julkaistu suoraan',
            'sv' => 'Publicerad direkt',
            'en' => 'Published directly',
            'it' => 'Pubblicato direttamente',
            'el' => 'Δημοσιεύτηκε απευθείας',
        ],

        // Additional flash actions
        'flash_supervisor_approved' => [
            'fi' => 'Työmaavastaava hyväksyi',
            'sv' => 'Platschefen godkände',
            'en' => 'Supervisor approved',
            'it' => 'Supervisore approvato',
            'el' => 'Ο επόπτης ενέκρινε',
        ],
        'flash_sent_to_supervisor' => [
            'fi' => 'Lähetetty työmaavastaavalle',
            'sv' => 'Skickad till platschef',
            'en' => 'Sent to supervisor',
            'it' => 'Inviato al supervisore',
            'el' => 'Στάλθηκε στον επόπτη',
        ],
        'flash_language_version_created' => [
            'fi' => 'Kieliversio luotu',
            'sv' => 'Språkversion skapad',
            'en' => 'Language version created',
            'it' => 'Versione linguistica creata',
            'el' => 'Δημιουργήθηκε γλωσσική έκδοση',
        ],
        'flash_created' => [
            'fi' => 'Safetyflash luotu',
            'sv' => 'Safetyflash skapad',
            'en' => 'Safetyflash created',
            'it' => 'Safetyflash creato',
            'el' => 'Δημιουργήθηκε Safetyflash',
        ],
        'flash_updated' => [
            'fi' => 'Safetyflash päivitetty',
            'sv' => 'Safetyflash uppdaterad',
            'en' => 'Safetyflash updated',
            'it' => 'Safetyflash aggiornato',
            'el' => 'Safetyflash ενημερώθηκε',
        ],
        'flash_status_changed' => [
            'fi' => 'Tila muutettu',
            'sv' => 'Status ändrad',
            'en' => 'Status changed',
            'it' => 'Stato modificato',
            'el' => 'Η κατάσταση άλλαξε',
        ],

        // Additional user actions
        'user_login' => [
            'fi' => 'Kirjautuminen',
            'sv' => 'Inloggning',
            'en' => 'Login',
            'it' => 'Accesso',
            'el' => 'Σύνδεση',
        ],
        'user_logout' => [
            'fi' => 'Uloskirjautuminen',
            'sv' => 'Utloggning',
            'en' => 'Logout',
            'it' => 'Disconnessione',
            'el' => 'Αποσύνδεση',
        ],
        'user_login_failed' => [
            'fi' => 'Kirjautuminen epäonnistui',
            'sv' => 'Inloggning misslyckades',
            'en' => 'Login failed',
            'it' => 'Accesso fallito',
            'el' => 'Αποτυχία σύνδεσης',
        ],
        'user_created' => [
            'fi' => 'Käyttäjä luotu',
            'sv' => 'Användare skapad',
            'en' => 'User created',
            'it' => 'Utente creato',
            'el' => 'Ο χρήστης δημιουργήθηκε',
        ],
        'user_updated' => [
            'fi' => 'Käyttäjä päivitetty',
            'sv' => 'Användare uppdaterad',
            'en' => 'User updated',
            'it' => 'Utente aggiornato',
            'el' => 'Ο χρήστης ενημερώθηκε',
        ],
        'user_deleted' => [
            'fi' => 'Käyttäjä poistettu',
            'sv' => 'Användare borttagen',
            'en' => 'User deleted',
            'it' => 'Utente eliminato',
            'el' => 'Ο χρήστης διαγράφηκε',
        ],
    ];

    if (isset($labels[$action])) {
        return $labels[$action][$lang] ?? $labels[$action]['en'] ?? $action;
    }

    // Fallback heuristics (turn snake_case into Title Case)
    $pretty = str_replace('_', ' ', $action);
    $pretty = preg_replace('/\s+/', ' ', $pretty);
    $pretty = trim((string)$pretty);

    return $pretty !== '' ? ucfirst($pretty) : $action;
}