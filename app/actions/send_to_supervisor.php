<?php
declare(strict_types=1);

/**
 * Send SafetyFlash to Supervisor Action
 * 
 * Handles sending a SafetyFlash to selected supervisors for approval.
 * Updates state to 'pending_supervisor' and sends email notifications.
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
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ . '/../includes/audit_log.php';

$flashId = isset($_POST['flash_id']) ? (int)$_POST['flash_id'] : 0;
$approverIds = isset($_POST['approver_ids']) ? json_decode($_POST['approver_ids'], true) : [];
$submissionComment = isset($_POST['submission_comment']) ? trim($_POST['submission_comment']) : '';

if ($flashId <= 0 || empty($approverIds)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

$pdo = Database::getInstance();
    
// Verify flash exists and user has permission
$currentUser = sf_current_user();
$stmt = $pdo->prepare("
    SELECT id, created_by, state, translation_group_id
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

// Check permissions: owner, admin or safety team
$isOwner = (int)$flash['created_by'] === (int)$currentUser['id'];
$isAdmin = (int)$currentUser['role_id'] === 1;
$isSafety = (int)$currentUser['role_id'] === 3;

if (!$isOwner && !$isAdmin && !$isSafety) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

// Validate allowed states
if (!in_array($flash['state'], ['draft', 'request_info', ''], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid state']);
    exit;
}

$isResubmission = ($flash['state'] === 'request_info');

// Log resubmission or initial submission
require_once __DIR__ . '/../includes/log_app.php';
if ($isResubmission) {
    sf_app_log("[send_to_supervisor] Resubmission from request_info state for flash {$flashId}");
} else {
    sf_app_log("[send_to_supervisor] Initial submission for flash {$flashId}");
}

// Save selected approvers
ApprovalRouting::saveSelectedApprovers($pdo, $flashId, $approverIds);

// Insert selected approvers into flash_supervisors table for display
$stmt = $pdo->prepare("DELETE FROM flash_supervisors WHERE flash_id = ?");
$stmt->execute([$flashId]);

$insertStmt = $pdo->prepare("
    INSERT INTO flash_supervisors (flash_id, user_id, assigned_at)
    VALUES (?, ?, NOW())
");
foreach ($approverIds as $approverId) {
    $insertStmt->execute([$flashId, (int)$approverId]);
}

// Update state to pending_supervisor
$stmt = $pdo->prepare("
    UPDATE sf_flashes 
    SET state = 'pending_supervisor', updated_at = NOW() 
    WHERE id = :id
");
$stmt->execute([':id' => $flashId]);

sf_app_log("[send_to_supervisor] State updated to pending_supervisor for flash {$flashId}");

// Bundle workflow: also update all sibling language versions in the same translation group
// and copy approver assignments so all versions go to supervisor at once.
// Only ONE email will be sent (below).
$bundleGroupId = !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : null;
if ($bundleGroupId !== null) {
    try {
        // Update sibling drafts to pending_supervisor
        $pdo->prepare("
            UPDATE sf_flashes
            SET state = 'pending_supervisor', updated_at = NOW()
            WHERE (id = :gid OR translation_group_id = :gid2)
              AND id != :current_id
              AND state IN ('draft', 'request_info', '')
        ")->execute([':gid' => $bundleGroupId, ':gid2' => $bundleGroupId, ':current_id' => $flashId]);

        // Fetch sibling IDs
        $sibStmt = $pdo->prepare("
            SELECT id FROM sf_flashes
            WHERE (id = :gid OR translation_group_id = :gid2)
              AND id != :current_id
        ");
        $sibStmt->execute([':gid' => $bundleGroupId, ':gid2' => $bundleGroupId, ':current_id' => $flashId]);
        $siblingIds = $sibStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($siblingIds)) {
            $delSib  = $pdo->prepare("DELETE FROM flash_supervisors WHERE flash_id = ?");
            $insSib  = $pdo->prepare("INSERT INTO flash_supervisors (flash_id, user_id, assigned_at) VALUES (?, ?, NOW())");
            foreach ($siblingIds as $sibId) {
                $sibId = (int)$sibId;
                $delSib->execute([$sibId]);
                foreach ($approverIds as $approverId) {
                    $insSib->execute([$sibId, (int)$approverId]);
                }
            }
            sf_app_log("[send_to_supervisor] Bundle: updated " . count($siblingIds) . " sibling(s) in group {$bundleGroupId}");
        }
    } catch (Throwable $bundleEx) {
        sf_app_log("[send_to_supervisor] Bundle group update error: " . $bundleEx->getMessage());
    }
}

// Log event
require_once __DIR__ . '/../includes/log.php';
sf_log_event($flashId, 'sent_to_supervisor', 'log_sent_to_supervisor');

$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

// If submission comment is provided, save it as a separate event
if ($submissionComment !== '') {
    $submissionComment = mb_substr($submissionComment, 0, 1000);
    sf_log_event($flashId, 'submission_comment', $submissionComment);

    // Also save as system comment so it appears in Comments tab
    $userId = $currentUser ? (int)$currentUser['id'] : null;
    $stmtSysComment = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (:flash_id, :user_id, :event_type, :description, NOW())
    ");
    $stmtSysComment->execute([
        ':flash_id'    => $flashId,
        ':user_id'     => $userId,
        ':event_type'  => 'comment_added',
        ':description' => "log_comment_label: log_sent_to_supervisor: " . $submissionComment,
    ]);
}

// Send email notifications to supervisors
require_once __DIR__ . '/../../assets/services/email_services.php';
$approvers = ApprovalRouting::getSelectedApprovers($pdo, $flashId);

error_log("send_to_supervisor: Sending notifications for flash {$flashId} to " . count($approvers) . " approvers");

$pushUserIds = [];

foreach ($approvers as $approver) {
    error_log("send_to_supervisor: Sending to {$approver['email']} (user_id={$approver['id']})");
    $result = sf_send_supervisor_notification($flashId, $approver['email'], $isResubmission, $submissionComment);

    if (!empty($approver['id'])) {
        $pushUserIds[] = (int)$approver['id'];
    }

    if ($result) {
        error_log("send_to_supervisor: Email sent successfully to {$approver['email']}");
    } else {
        error_log("send_to_supervisor: Email FAILED to {$approver['email']}");
    }
}
UserEventService::createEventsForUsers(
    $pdo,
    $pushUserIds,
    $flashId,
    'pending_supervisor',
    'action_required',
    null,
    $currentUser ? (int)$currentUser['id'] : null
);
try {
    PushNotificationService::sendWorkflowToUsers(
        $pdo,
        $pushUserIds,
        $flashId,
        'push_supervisor_title',
        'push_supervisor_body'
    );
} catch (Throwable $pushError) {
    sf_app_log('send_to_supervisor: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
}

sf_audit_log('flash_sent_to_supervisor', 'flash', $flashId);

echo json_encode([
    'ok' => true,
    'message' => 'Sent to supervisors',
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