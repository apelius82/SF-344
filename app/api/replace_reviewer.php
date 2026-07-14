<?php
declare(strict_types=1);

/**
 * Replace Reviewer API Endpoint
 * 
 * Removes all existing reviewers and adds a new one.
 * Sends email notification to the new reviewer.
 * 
 * Parameters:
 * - flash_id: The ID of the flash (required)
 * - user_id: The ID of the user to set as the new reviewer (required)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/services/email_services.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/ApprovalRouting.php';

$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Get parameters
    $flashId = isset($_POST['flash_id']) ? (int)$_POST['flash_id'] : 0;
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    
    if ($flashId <= 0 || $userId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Valid flash_id and user_id required']);
        exit;
    }
    
    // Verify flash exists
    $flashStmt = $pdo->prepare("SELECT id, title, lang FROM sf_flashes WHERE id = ? LIMIT 1");
    $flashStmt->execute([$flashId]);
    $flash = $flashStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$flash) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found']);
        exit;
    }
    
    // Verify user exists and is active
    $userStmt = $pdo->prepare("SELECT id, first_name, last_name, email, ui_lang FROM sf_users WHERE id = ? AND is_active = 1 LIMIT 1");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'User not found or inactive']);
        exit;
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Remove all existing reviewers for this flash
        $deleteStmt = $pdo->prepare("DELETE FROM flash_supervisors WHERE flash_id = ?");
        $deleteStmt->execute([$flashId]);
        
        // Add new reviewer
        $insertStmt = $pdo->prepare("
            INSERT INTO flash_supervisors (flash_id, user_id, assigned_at)
            VALUES (?, ?, NOW())
        ");
        $insertStmt->execute([$flashId, $userId]);

        // Sync selected_approvers in sf_flashes so list.php visibility reflects the change
        ApprovalRouting::saveSelectedApprovers($pdo, $flashId, [$userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    
    // Send email notification
    try {
        $userLang = $user['ui_lang'] ?? 'fi';
        $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $flashTitle = $flash['title'] ?? '';
        
        // Build email subject and body based on user's language
        $subjects = [
            'fi' => 'Sinut on asetettu SafetyFlash-tarkistajaksi',
            'sv' => 'Du har satts som SafetyFlash-granskare',
            'en' => 'You have been set as SafetyFlash reviewer',
            'it' => 'Sei stato impostato come revisore SafetyFlash',
            'el' => 'Έχετε οριστεί ως ελεγκτής SafetyFlash'
        ];
        
        $bodies = [
            'fi' => "Hei {$userName},\n\nSinut on asetettu tarkistajaksi SafetyFlashille: {$flashTitle}\n\nVoit tarkastella SafetyFlashia järjestelmässä.",
            'sv' => "Hej {$userName},\n\nDu har satts som granskare för SafetyFlash: {$flashTitle}\n\nDu kan granska SafetyFlash i systemet.",
            'en' => "Hello {$userName},\n\nYou have been set as the reviewer for SafetyFlash: {$flashTitle}\n\nYou can review the SafetyFlash in the system.",
            'it' => "Ciao {$userName},\n\nSei stato impostato come revisore per SafetyFlash: {$flashTitle}\n\nPuoi rivedere il SafetyFlash nel sistema.",
            'el' => "Γεια σου {$userName},\n\nΈχετε οριστεί ως ελεγκτής για το SafetyFlash: {$flashTitle}\n\nΜπορείτε να ελέγξετε το SafetyFlash στο σύστημα."
        ];
        
        $subject = $subjects[$userLang] ?? $subjects['en'];
        $body = $bodies[$userLang] ?? $bodies['en'];
        
        // Use existing email service
        sf_send_email(
            $subject,
            $body,      // HTML body
            $body,      // Plain text body
            [$user['email']],
            [],         // No attachments
            $flashId
        );
    } catch (Throwable $emailError) {
        // Log email error but don't fail the API call
        error_log('Failed to send reviewer notification email: ' . $emailError->getMessage());
    }
    
    echo json_encode([
        'ok' => true,
        'message' => 'Reviewer replaced successfully'
    ]);
    exit;
    
} catch (Throwable $e) {
    error_log('replace_reviewer error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}