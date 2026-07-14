<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../lib/sf_terms.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/file_cleanup.php';


header('Content-Type: application/json; charset=utf-8');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF validation
$csrfToken = $_POST['csrf_token'] ?? '';
if (!sf_csrf_validate($csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$flashId = (int)($_POST['flash_id'] ?? 0);
if ($flashId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid flash ID']);
    exit;
}

$currentUser = sf_current_user();
if (!$currentUser) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Only admin (role_id=1) and safety team (role_id=3) can archive
if (!in_array($currentUser['role_id'], [1, 3])) {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Check flash exists and is published
    $stmt = $pdo->prepare("SELECT id, state, is_archived, title FROM sf_flashes WHERE id = ?");
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$flash) {
        echo json_encode(['success' => false, 'error' => 'Flash not found']);
        exit;
    }
    
    if ($flash['state'] !== 'published') {
        echo json_encode(['success' => false, 'error' => 'Only published flashes can be archived']);
        exit;
    }
    
    if ($flash['is_archived']) {
        echo json_encode(['success' => false, 'error' => 'Already archived']);
        exit;
    }
sf_cleanup_after_publish($pdo, $flashId);
    // Archive: clear heavy data, keep text and preview
    $stmt = $pdo->prepare("
        UPDATE sf_flashes SET
            is_archived = 1,
            archived_at = NOW(),
            archived_by = ?,
            -- Clear heavy data
            annotations_data = NULL,
            image1_transform = NULL,
            image2_transform = NULL,
            image3_transform = NULL,
            grid_bitmap = NULL,
            -- Keep: title, title_short, description, root_causes, actions, preview_filename, type, site, lang, etc.
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$currentUser['id'], $flashId]);
    
    // Get UI language for messages
    $uiLang = $_SESSION['ui_lang'] ?? 'fi';
    
    // Log event
    $logMessage = sf_term('archive_log_message', $uiLang) ?: 'SafetyFlash archived';
    sf_log_event($flashId, 'archived', $logMessage);
    
    // Audit log
    sf_audit_log(
        'flash_archive',
        'flash',
        $flashId,
        ['title' => $flash['title'] ?? null],
        (int)$currentUser['id']
    );
    
    $successMessage = sf_term('archive_success', $uiLang);
    echo json_encode(['success' => true, 'message' => $successMessage]);
    
} catch (Throwable $e) {
    error_log('archive_flash.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}