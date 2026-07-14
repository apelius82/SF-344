<?php
// app/api/drafts_save.php
declare(strict_types=1);

define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$token = $data['csrf_token'] ?? '';
if (!sf_csrf_validate($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token validation failed']);
    exit;
}

$currentUser = sf_current_user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int)$currentUser['id'];
$flashType = $data['flash_type'] ?? 'unknown';
$formData = $data['form_data'] ?? [];

if (empty($formData) || !is_array($formData)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No form data provided']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Check if user has recently saved a flash (within 10 seconds)
    // If so, skip draft saving to prevent race condition after form submission
    $recentFlash = $pdo->prepare("
        SELECT id FROM sf_flashes 
        WHERE created_by = ? 
        AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND)
        LIMIT 1
    ");
    $recentFlash->execute([$userId]);
    if ($recentFlash->fetch()) {
        // Don't save draft - user just submitted a flash
        echo json_encode(['ok' => true, 'draft_id' => null, 'skipped' => true]);
        exit;
    }
    
    // KORJAUS: Tarkista onko käyttäjällä jo luonnos (riippumatta tyypistä)
    $stmt = $pdo->prepare("SELECT id FROM sf_drafts WHERE user_id = ?  LIMIT 1");
    $stmt->execute([$userId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Päivitä olemassa oleva luonnos
        $updateStmt = $pdo->prepare("
            UPDATE sf_drafts 
            SET flash_type = ?, form_data = ?, updated_at = NOW() 
            WHERE user_id = ? 
        ");
        $updateStmt->execute([
            $flashType,
            json_encode($formData, JSON_UNESCAPED_UNICODE),
            $userId
        ]);
        
        echo json_encode([
            'ok' => true,
            'draft_id' => (int)$existing['id'],
            'message' => 'Draft updated'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Luo uusi luonnos vain jos ei ole olemassa olevaa
    $insertStmt = $pdo->prepare("
        INSERT INTO sf_drafts (user_id, flash_type, form_data, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), NOW())
    ");
    $insertStmt->execute([
        $userId,
        $flashType,
        json_encode($formData, JSON_UNESCAPED_UNICODE)
    ]);
    
    $newDraftId = (int)$pdo->lastInsertId();
    
    echo json_encode([
        'ok' => true,
        'draft_id' => $newDraftId,
        'message' => 'Draft saved'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    error_log('drafts_save.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to save draft'
    ], JSON_UNESCAPED_UNICODE);
}