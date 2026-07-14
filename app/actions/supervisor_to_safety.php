<?php
declare(strict_types=1);

/**
 * Supervisor to Safety Team Action
 * 
 * Handles supervisor approval and forwarding to safety team.
 * Updates state from 'pending_supervisor' to 'pending_review'.
 */

// Set error handler to convert warnings/notices to exceptions
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../services/ApprovalRouting.php';
require_once __DIR__ . '/../services/PushNotificationService.php';
require_once __DIR__ . '/../services/UserEventService.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ . '/../includes/audit_log.php';

$flashId = isset($_POST['flash_id']) ? (int)$_POST['flash_id'] : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($flashId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid flash ID']);
    exit;
}

$pdo = Database::getInstance();
$currentUser = sf_current_user();

// Verify flash exists
$stmt = $pdo->prepare("
    SELECT id, state, selected_approvers 
    FROM sf_flashes 
    WHERE id = :id
");
$stmt->execute([':id' => $flashId]);
$flash = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flash) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Flash not found']);
    exit;
}

// Check if state is pending_supervisor
if ($flash['state'] !== 'pending_supervisor') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid state for this action']);
    exit;
}

// Check permissions: supervisor (selected approver) or admin
$isSupervisor = ApprovalRouting::isUserSupervisor($pdo, (int)$currentUser['id']);
$isSelectedApprover = ApprovalRouting::isUserSelectedApprover($pdo, $flashId, (int)$currentUser['id']);
$isAdmin = (int)$currentUser['role_id'] === 1;

if (!$isAdmin && (!$isSupervisor || !$isSelectedApprover)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied - you are not assigned as an approver']);
    exit;
}

// Update state to pending_review for all language versions in the bundle
sf_update_state_all_languages($pdo, $flashId, 'pending_review');

// Log event
require_once __DIR__ . '/../includes/log.php';

$supervisorBatchId = sf_log_generate_batch_id();

$desc = 'log_supervisor_approved';
if (!empty($message)) {
    $desc .= "\nlog_supervisor_message_label: " . $message;
}
sf_log_event($flashId, 'supervisor_approved', $desc, $supervisorBatchId);

$stateDesc = 'log_state_changed: pending_supervisor → pending_review';
sf_log_event($flashId, 'state_changed', $stateDesc, $supervisorBatchId);

// Save message as system comment so it appears in Comments tab
$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
if (!empty($message)) {
    $userId = $currentUser ? (int)$currentUser['id'] : null;
    $safeMessage = mb_substr($message, 0, 2000);
    $stmtSysComment = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (:flash_id, :user_id, :event_type, :description, NOW())
    ");
    $stmtSysComment->execute([
        ':flash_id'    => $flashId,
        ':user_id'     => $userId,
        ':event_type'  => 'comment_added',
        ':description' => "log_comment_label: log_supervisor_approved: " . $safeMessage,
    ]);
}

// Send email notification to safety team
require_once __DIR__ . '/../../assets/services/email_services.php';
sf_mail_to_safety_team($pdo, $flashId, 'pending_supervisor');

UserEventService::createRoleEvents(
    $pdo,
    3,
    $flashId,
    'sent_to_safety_team',
    'action_required',
    $currentUser ? (int)$currentUser['id'] : null
);

try {
    PushNotificationService::sendWorkflowToRole(
        $pdo,
        3,
        $flashId,
        'push_safety_team_title',
        'push_safety_team_body'
    );
} catch (Throwable $pushError) {
    sf_app_log('supervisor_to_safety: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
}

sf_audit_log('flash_supervisor_approved', 'flash', $flashId);

echo json_encode([
    'ok' => true,
    'message' => 'Sent to safety team',
    'redirect' => (isset($config['base_url']) ? $config['base_url'] : '') . '/index.php?page=view&id=' . $flashId
]);
    
} catch (Throwable $e) {
    if (function_exists('sf_app_log')) {
        sf_app_log(basename(__FILE__) . ' FATAL ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_LEVEL_ERROR);
    } else {
        error_log(basename(__FILE__) . ' FATAL ERROR: ' . $e->getMessage());
    }
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Server error occurred',
        'debug' => $e->getFile() . ':' . $e->getLine()
    ]);
    exit;
}

// Restore default error handler
restore_error_handler();