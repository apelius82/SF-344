<?php
// app/actions/save_comms_edit.php
// Viestinnän ja turvatiimin muokkaus - EI muuta tilaa, tallentaa vain sisältömuutokset
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../services/FlashSaveService.php';
require_once __DIR__ . '/../services/FlashPermissionService.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$flashId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($flashId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid flash ID']);
    exit;
}

try {
    $user = sf_current_user();
    if (!$user) {
        throw new Exception('Not authenticated');
    }
    
    // Initialize save service
    $saveService = new FlashSaveService();
    
    // Save flash (handles all validation, permissions, logging, worker job)
    $saveService->save($flashId, $_POST, $user);
    
    // Audit log for compliance
    sf_audit_log(
        'flash_edit',
        'flash',
        $flashId,
        [
            'action' => 'comms_inline_edit_no_status_change',
        ],
        (int)($user['id'] ?? 0)
    );
    
    // Start worker to process flash image
    $workerScript = __DIR__ . '/../api/process_flash_worker.php';
    if (function_exists('shell_exec')) {
        $disabled = (string) ini_get('disable_functions');
        if (stripos($disabled, 'shell_exec') === false) {
            $phpExecutable = PHP_BINARY ?: 'php';
            $cmd = escapeshellcmd($phpExecutable)
                . ' ' . escapeshellarg($workerScript)
                . ' ' . escapeshellarg((string) $flashId)
                . ' > /dev/null 2>&1 &';
            @shell_exec($cmd);
        }
    }
    
    // Return success with redirect
    $base = rtrim($config['base_url'] ?? '', '/');
    echo json_encode([
        'ok' => true,
        'flash_id' => $flashId,
        'redirect' => $base . '/index.php?page=view&id=' . $flashId . '&notice=saved'
    ]);
    
} catch (PermissionException $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('save_comms_edit.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Tallennusvirhe: ' . $e->getMessage()]);
}