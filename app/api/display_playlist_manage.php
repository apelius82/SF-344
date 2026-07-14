<?php
/**
 * SafetyFlash - Display Playlist Management API
 * 
 * API endpoint poistaa/palauttaa flasheja infonäyttö-playlistasta.
 * Vain adminit, turvatiimi ja viestintä voivat käyttää.
 * 
 * @package SafetyFlash
 * @subpackage API
 * @created 2026-02-19
 * 
 * POST Parameters:
 * - flash_id (int): Flash ID
 * - action (string): "remove" tai "restore"
 * - csrf_token (string): CSRF token
 * 
 * Response (JSON):
 * - ok (bool): Onnistuiko toiminto
 * - status (string): "removed", "active"
 * - message (string): Käyttäjäystävällinen viesti
 */

declare(strict_types=1);

// Set error handler
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // Lataa tarvittavat tiedostot
    require_once __DIR__ . '/../includes/protect.php';
    require_once __DIR__ . '/../includes/csrf.php';
    require_once __DIR__ . '/../includes/log_app.php';
    require_once __DIR__ . '/../actions/helpers.php';
    require_once __DIR__ . '/../../assets/lib/sf_terms.php';
    
    // Pakota JSON-vastaus
    header('Content-Type: application/json; charset=utf-8');
    
    // Vain POST-pyynnöt
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }
    
    // Tarkista CSRF
    if (!sf_csrf_validate()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
        exit;
    }
    
    // Tarkista kirjautuminen
    $user = sf_current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    // Tarkista oikeudet: admin (1), turvatiimi (3), viestintä (4)
    $roleId = (int)($user['role_id'] ?? 0);
    if (!in_array($roleId, [1, 3, 4], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
        exit;
    }
    
    // Lue JSON body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Fallback POST-parametreihin
        $data = $_POST;
    }
    
    $flashId = (int)($data['flash_id'] ?? 0);
    $action = (string)($data['action'] ?? '');
    
    if ($flashId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid flash_id']);
        exit;
    }
    
    if (!in_array($action, ['remove', 'restore'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action. Must be "remove" or "restore"']);
        exit;
    }
    
    $pdo = sf_get_pdo();
    $userId = (int)$user['id'];
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    
    // Hae flash
    $stmt = $pdo->prepare("
        SELECT id, translation_group_id, title, state, display_expires_at, display_removed_at
        FROM sf_flashes 
        WHERE id = :id 
        LIMIT 1
    ");
    $stmt->execute([':id' => $flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$flash) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found']);
        exit;
    }
    
    // Määritä translation group
    $groupId = $flash['translation_group_id'] ?: $flash['id'];
    
    if ($action === 'remove') {
        // Poista playlistasta
        $stmtUpdate = $pdo->prepare("
            UPDATE sf_flashes 
            SET display_removed_at = NOW(),
                display_removed_by = :user_id
            WHERE id = :id OR translation_group_id = :id2
        ");
        $stmtUpdate->execute([
            ':user_id' => $userId,
            ':id' => $groupId,
            ':id2' => $groupId
        ]);
        
        // Lokita tapahtuma
        $logDesc = sf_term('log_display_removed', $currentUiLang) ?? 'Poistettu infonäyttö-playlistasta';
        $logStmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
            VALUES (:flash_id, :user_id, 'display_removed', :description, NOW())
        ");
        $logStmt->execute([
            ':flash_id' => $groupId,
            ':user_id' => $userId,
            ':description' => $logDesc
        ]);
        
        sf_app_log("Display playlist: Flash {$groupId} removed by user {$userId}");
        
        $message = sf_term('msg_removed_from_playlist', $currentUiLang) ?? 'Poistettu playlistasta';
        
        echo json_encode([
            'ok' => true,
            'status' => 'removed',
            'message' => $message
        ]);
        
    } else {
        // Palauta playlistaan
        
        // Jos display_expires_at on menneisyydessä, päivitä se +30 päivää
        $expiresAt = $flash['display_expires_at'] ?? null;
        $newExpiresAt = null;
        
        if ($expiresAt && strtotime($expiresAt) < time()) {
            $newExpiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        }
        
        if ($newExpiresAt) {
            $stmtUpdate = $pdo->prepare("
                UPDATE sf_flashes 
                SET display_removed_at = NULL,
                    display_removed_by = NULL,
                    display_expires_at = :new_expires
                WHERE id = :id OR translation_group_id = :id2
            ");
            $stmtUpdate->execute([
                ':new_expires' => $newExpiresAt,
                ':id' => $groupId,
                ':id2' => $groupId
            ]);
        } else {
            $stmtUpdate = $pdo->prepare("
                UPDATE sf_flashes 
                SET display_removed_at = NULL,
                    display_removed_by = NULL
                WHERE id = :id OR translation_group_id = :id2
            ");
            $stmtUpdate->execute([
                ':id' => $groupId,
                ':id2' => $groupId
            ]);
        }
        
        // Lokita tapahtuma
        $logDesc = sf_term('log_display_restored', $currentUiLang) ?? 'Palautettu infonäyttö-playlistaan';
        if ($newExpiresAt) {
            $logDesc .= " (uusi vanhenemisaika: {$newExpiresAt})";
        }
        
        $logStmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
            VALUES (:flash_id, :user_id, 'display_restored', :description, NOW())
        ");
        $logStmt->execute([
            ':flash_id' => $groupId,
            ':user_id' => $userId,
            ':description' => $logDesc
        ]);
        
        sf_app_log("Display playlist: Flash {$groupId} restored by user {$userId}");
        
        $message = sf_term('msg_restored_to_playlist', $currentUiLang) ?? 'Palautettu playlistaan';
        
        echo json_encode([
            'ok' => true,
            'status' => 'active',
            'message' => $message
        ]);
    }
    
} catch (Throwable $e) {
    if (function_exists('sf_app_log')) {
        sf_app_log('display_playlist_manage API error: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    }
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Server error',
        'debug' => $e->getMessage()
    ]);
}

restore_error_handler();
