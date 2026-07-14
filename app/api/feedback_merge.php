<?php
/**
 * API: Merge feedback (admin only)
 * - Merges source feedback into target feedback
 * - Source feedback gets marked as merged, hidden from list
 * - Source author can see target feedback
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

global $config;
Database::setConfig($config['db'] ?? []);

$user = sf_current_user();
if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}

if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
    exit;
}

try {
    $sourceId = (int)($_POST['source_id'] ?? 0);
    $targetId = (int)($_POST['target_id'] ?? 0);
    
    if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid feedback IDs']);
        exit;
    }
    
    $db = Database::getInstance();
    
    // Check both feedbacks exist
    $stmt = $db->prepare("SELECT id, title, merged_into_id FROM sf_feedback WHERE id IN (?, ?)");
    $stmt->execute([$sourceId, $targetId]);
    $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($feedbacks) !== 2) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Feedback not found']);
        exit;
    }
    
    // Target cannot be already merged
    $stmt = $db->prepare("SELECT merged_into_id FROM sf_feedback WHERE id = ?");
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if ($target && $target['merged_into_id']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Target feedback is already merged into another']);
        exit;
    }
    
    // Merge: update source to point to target
    $stmt = $db->prepare("UPDATE sf_feedback SET merged_into_id = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$targetId, $sourceId]);
    
    // Add system comment to target about merge
    require_once __DIR__ . '/../../assets/lib/sf_terms.php';
    $userLang = $_SESSION['ui_lang'] ?? 'fi';
    $mergeNote = "📎 " . str_replace('{id}', (string)$sourceId, sf_term('feedback_merge_note', $userLang));
    
    $stmt = $db->prepare("INSERT INTO sf_feedback_comments (feedback_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$targetId, (int)$user['id'], $mergeNote]);
    
    echo json_encode(['ok' => true, 'message' => 'Feedback merged']);
    
} catch (Exception $e) {
    error_log('Feedback merge error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}