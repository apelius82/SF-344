<?php
// app/actions/save_edit.php
// Tallentaa muokkaukset ilman tilamuutosta ja ilman sähköpostilähetystä
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../services/FlashSaveService.php';
require_once __DIR__ . '/../services/FlashPermissionService.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/helpers.php';

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
    
    // ========== UPDATE SNAPSHOT FOR PUBLISHED FLASHES ==========
    // If the flash is in published state, update the snapshot
    try {
        $pdo = Database::getInstance();
        
        // Fetch flash to check state and type
        $stmt = $pdo->prepare("SELECT state, type, lang, translation_group_id, preview_filename FROM sf_flashes WHERE id = ? LIMIT 1");
        $stmt->execute([$flashId]);
        $flash = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($flash && $flash['state'] === 'published') {
            $currentUserId = $_SESSION['user_id'] ?? null;
            $groupId = $flash['translation_group_id'] ?: $flashId;
            
            // Määritä version_type flashin tyypin perusteella
            $flashType = $flash['type'] ?? 'yellow';
            $versionType = match($flashType) {
                'red' => 'ensitiedote',
                'yellow' => 'vaaratilanne',
                'green' => 'tutkintatiedote',
                default => 'vaaratilanne',
            };
            
            // Hae preview-kuva
            $previewFilename = $flash['preview_filename'] ?? null;
            if ($previewFilename) {
                $baseDir = dirname(__DIR__, 2);
                $previewPath = $baseDir . '/uploads/previews/' . basename($previewFilename);
                
                if (file_exists($previewPath)) {
                    // Hae olemassa oleva snapshot tälle tyypille ja kielelle
                    $stmtExisting = $pdo->prepare("
                        SELECT id, image_path FROM sf_flash_snapshots 
                        WHERE flash_id = ? AND version_type = ? AND lang = ?
                        LIMIT 1
                    ");
                    $stmtExisting->execute([$groupId, $versionType, sf_get_flash_lang($flash)]);
                    $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        // Korvaa olemassa oleva snapshot
                        $snapshotFullPath = $baseDir . $existing['image_path'];
                        if (copy($previewPath, $snapshotFullPath)) {
                            $stmtUpdate = $pdo->prepare("
                                UPDATE sf_flash_snapshots 
                                SET published_at = NOW(), published_by = ?
                                WHERE id = ?
                            ");
                            $stmtUpdate->execute([$currentUserId, $existing['id']]);
                            
                            if (function_exists('sf_app_log')) {
                                sf_app_log("save_edit.php: Updated existing snapshot for flash {$groupId}, type: {$versionType}");
                            }
                        }
                    }
                    // Jos ei ole olemassa olevaa, ei tehdä mitään - snapshot luodaan vasta julkaisussa
                }
            }
        }
    } catch (Throwable $e) {
        // Snapshot update failure should not prevent the save from succeeding
        error_log('save_edit.php: Snapshot update failed: ' . $e->getMessage());
        if (function_exists('sf_app_log')) {
            sf_app_log('save_edit.php: Snapshot update failed: ' . $e->getMessage(), LOG_LEVEL_ERROR);
        }
    }
    // ============================================================
    
    // Audit log for compliance
    sf_audit_log(
        'flash_edit',
        'flash',
        $flashId,
        [
            'action' => 'inline_edit_no_status_change',
        ],
        (int)($user['id'] ?? 0)
    );
    
    // Käynnistä worker prosessoimaan kuva
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
        'redirect' => $base . '/index.php?page=view&id=' . $flashId
    ]);
    
} catch (PermissionException $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('save_edit.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Tallennusvirhe: ' . $e->getMessage()]);
}