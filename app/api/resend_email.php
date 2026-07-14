<?php
// app/api/resend_email.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/services/email_services.php';

header('Content-Type: application/json; charset=utf-8');

// Only admins can resend emails
if (!sf_is_admin()) {
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

// Verify CSRF token
$csrfToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (empty($csrfToken) || empty($sessionToken) || !hash_equals($sessionToken, $csrfToken)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Get flash_id from POST
$flashId = isset($_POST['flash_id']) ? (int)$_POST['flash_id'] : 0;

if ($flashId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid flash_id']);
    exit;
}

try {
    $pdo = sf_get_pdo();
    
    // Get flash details
    $stmt = $pdo->prepare("
        SELECT id, translation_group_id, lang, title, type, worksite, site, state, sent_to_distribution, has_personal_injury
        FROM sf_flashes 
        WHERE id = ? 
        LIMIT 1
    ");
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$flash) {
        echo json_encode(['ok' => false, 'error' => 'Flash not found']);
        exit;
    }
    
    // Check if flash was published and sent to distribution
    if ($flash['state'] !== 'published' || !$flash['sent_to_distribution']) {
        echo json_encode(['ok' => false, 'error' => 'Flash was not published or not sent to distribution']);
        exit;
    }
    
    $hasPersonalInjury = (bool)$flash['has_personal_injury'];
    $groupId = !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : $flashId;
    
    // Get all language versions in this translation group
    $langStmt = $pdo->prepare("
        SELECT id, lang 
        FROM sf_flashes 
        WHERE (id = ? OR translation_group_id = ?) AND state = 'published'
    ");
    $langStmt->execute([$groupId, $groupId]);
    $langVersions = $langStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create a map of lang -> flash_id
    $langToFlashId = [];
    foreach ($langVersions as $version) {
        $langToFlashId[$version['lang']] = (int)$version['id'];
    }
    
    // If no language versions found, use the original flash
    if (empty($langToFlashId)) {
        $langToFlashId[$flash['lang']] = $flashId;
    }
    
    $totalRecipients = 0;
    $errors = [];
    
    // Send to each language/country distribution group
    // Note: In this system, language codes (fi, sv, en, it, el) map directly to country codes
    // because distribution groups are organized by country/language combination.
    // The sf_mail_to_distribution_by_country function expects countryCode parameter
    // and uses it to query users by their distribution role (SF_ROLE_ID_DISTRIBUTION_FI, etc.)
    foreach ($langToFlashId as $lang => $targetFlashId) {
        $countryCode = $lang;
        
        try {
            // Use the existing distribution function
            if (function_exists('sf_mail_to_distribution_by_country')) {
                $recipientCount = sf_mail_to_distribution_by_country($pdo, $targetFlashId, $countryCode, $hasPersonalInjury);
                $totalRecipients += $recipientCount;
                sf_app_log("resend_email.php: Sent to {$countryCode}, recipients: {$recipientCount}");
            } else {
                sf_app_log("resend_email.php: sf_mail_to_distribution_by_country function not available", LOG_LEVEL_ERROR);
                $errors[] = "{$countryCode}: Distribution function not available";
            }
        } catch (Throwable $e) {
            sf_app_log("resend_email.php: Error sending to {$countryCode}: " . $e->getMessage(), LOG_LEVEL_ERROR);
            $errors[] = "{$countryCode}: " . $e->getMessage();
        }
    }
    
    // Log the resend action
    $userId = $_SESSION['user_id'] ?? null;
    $logStmt = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (?, ?, 'email_resent', ?, NOW())
    ");
    $logStmt->execute([
        $groupId,
        $userId,
        "Email resent to {$totalRecipients} recipient(s)"
    ]);
    
    if ($totalRecipients > 0) {
        $response = [
            'ok' => true,
            'count' => $totalRecipients,
            'message' => "Email resent to {$totalRecipients} recipient(s)"
        ];
        
        // Include partial failure warnings if any
        if (!empty($errors)) {
            $response['warnings'] = $errors;
            sf_app_log("resend_email.php: Partial success - sent to {$totalRecipients} but had errors: " . implode('; ', $errors), LOG_LEVEL_WARNING);
        }
        
        echo json_encode($response);
    } else {
        echo json_encode([
            'ok' => false,
            'error' => 'No recipients found or all emails failed',
            'details' => $errors
        ]);
    }
    
} catch (Throwable $e) {
    sf_app_log('resend_email.php ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    echo json_encode([
        'ok' => false,
        'error' => 'Internal error: ' . $e->getMessage()
    ]);
}