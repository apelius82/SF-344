<?php
// app/api/editing_lock.php
// Actions: acquire, release, heartbeat, check

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$flashId = (int)($_POST['flash_id'] ?? $_GET['flash_id'] ?? 0);

if ($flashId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid flash_id']);
    exit;
}

$pdo = Database::getInstance();
$userId = (int)$user['id'];

// Lock expires after 15 minutes
$lockExpiryMinutes = 15;

switch ($action) {
    case 'check':
        // Check if someone else is editing
        $stmt = $pdo->prepare("
            SELECT f.editing_user_id, f.editing_started_at, 
                   u.first_name, u.last_name
            FROM sf_flashes f
            LEFT JOIN sf_users u ON f.editing_user_id = u.id
            WHERE f.id = ?
        ");
        $stmt->execute([$flashId]);
        $row = $stmt->fetch();
        
        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Flash not found']);
            exit;
        }
        
        $editingUserId = $row['editing_user_id'] ? (int)$row['editing_user_id'] : null;
        $editingStarted = $row['editing_started_at'];
        
        // Check if lock is expired
        $isExpired = false;
        if ($editingStarted) {
            $startedTime = strtotime($editingStarted);
            if ($startedTime !== false) {
                $isExpired = (time() - $startedTime) > ($lockExpiryMinutes * 60);
            }
        }
        
        if ($editingUserId && $editingUserId !== $userId && !$isExpired && $editingStarted) {
            $editorName = trim($row['first_name'] . ' ' . $row['last_name']);
            $startedTime = strtotime($editingStarted);
            $minutesAgo = $startedTime !== false ? round((time() - $startedTime) / 60) : 0;
            
            echo json_encode([
                'ok' => true,
                'locked' => true,
                'editor_name' => $editorName,
                'editor_id' => $editingUserId,
                'minutes_ago' => $minutesAgo,
                'started_at' => $editingStarted
            ]);
        } else {
            echo json_encode(['ok' => true, 'locked' => false]);
        }
        break;
        
    case 'acquire':
        // Acquire the lock (or refresh if already owned)
        $stmt = $pdo->prepare("
            UPDATE sf_flashes 
            SET editing_user_id = ?, editing_started_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userId, $flashId]);
        echo json_encode(['ok' => true, 'message' => 'Lock acquired']);
        break;
        
    case 'heartbeat':
        // Refresh the lock timestamp (only if we own it)
        $stmt = $pdo->prepare("
            UPDATE sf_flashes 
            SET editing_started_at = NOW()
            WHERE id = ? AND editing_user_id = ?
        ");
        $stmt->execute([$flashId, $userId]);
        echo json_encode(['ok' => true]);
        break;
        
    case 'release':
        // Release the lock (only if we own it)
        $stmt = $pdo->prepare("
            UPDATE sf_flashes 
            SET editing_user_id = NULL, editing_started_at = NULL
            WHERE id = ? AND editing_user_id = ?
        ");
        $stmt->execute([$flashId, $userId]);
        echo json_encode(['ok' => true, 'message' => 'Lock released']);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action']);
}