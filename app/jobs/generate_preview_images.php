#!/usr/bin/env php
<?php
/**
 * Generate Preview Images - Background Job
 * 
 * Processes pending preview image generation requests
 * Designed to run via cron every minute: * * * * * php /path/to/generate_preview_images.php
 * 
 * @package SafetyFlash
 * @subpackage Jobs
 */

declare(strict_types=1);

// Ensure running from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

// Limit execution time
set_time_limit(300);

// Load configuration
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../services/PreviewImageGenerator.php';

// Simple file-based lock to prevent concurrent execution
$lockFile = __DIR__ . '/../../uploads/processes/preview_generation.lock';
$lockDir = dirname($lockFile);

if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}

// Check for stale lock (older than 5 minutes)
if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 300) {
        // Lock is stale, remove it
        error_log('PreviewJob: Removing stale lock file (age: ' . $lockAge . ' seconds)');
        @unlink($lockFile);
    }
}

$fp = fopen($lockFile, 'c+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    // Another instance is running
    if ($fp) fclose($fp);
    exit(0);
}

// Touch the lock file to update timestamp
touch($lockFile);

try {
    // Get database connection
    $pdo = Database::getInstance();
    
    // Paths
    $uploadsDir = __DIR__ . '/../../uploads';
    $previewsDir = $uploadsDir . '/previews';
    
    // Ensure previews directory exists
    if (!is_dir($previewsDir)) {
        @mkdir($previewsDir, 0755, true);
    }
    
    // Create generator instance
    $generator = new PreviewImageGenerator($pdo, $uploadsDir, $previewsDir);
    
    // Find flashes with pending preview status
    $stmt = $pdo->prepare("
        SELECT 
            id, type, lang, site, site_detail, occurred_at,
            title, title_short, summary, description,
            root_causes, actions,
            image_main, image_2, image_3,
            image1_edited_data, grid_bitmap, grid_layout
        FROM sf_flashes
        WHERE preview_status = 'pending'
        ORDER BY created_at ASC
        LIMIT 10
    ");
    
    $stmt->execute();
    $flashes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($flashes)) {
        // No pending previews
        flock($fp, LOCK_UN);
        fclose($fp);
        exit(0);
    }
    
    $processed = 0;
    $failed = 0;
    
    foreach ($flashes as $flash) {
        $flashId = (int) $flash['id'];
        
        try {
            // Update status to 'processing'
            $updateStmt = $pdo->prepare("
                UPDATE sf_flashes 
                SET preview_status = 'processing'
                WHERE id = ?
            ");
            $updateStmt->execute([$flashId]);
            
            // Generate preview
            $result = $generator->generate($flash);
            
            if ($result) {
                if (is_array($result)) {
                    // Two-card green type - update both preview_filename and preview_filename_2
                    // Validate array structure
                    if (!isset($result['filename1']) || !isset($result['filename2'])) {
                        throw new RuntimeException(
                            'Generator result array must contain both filename1 and filename2 keys. ' .
                            'Got: ' . json_encode(array_keys($result))
                        );
                    }
                    
                    $updateStmt = $pdo->prepare("
                        UPDATE sf_flashes 
                        SET preview_filename = ?,
                            preview_filename_2 = ?,
                            preview_status = 'completed',
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$result['filename1'], $result['filename2'], $flashId]);
                    
                    $processed++;
                    error_log("PreviewJob: Generated 2 previews for flash {$flashId}: {$result['filename1']}, {$result['filename2']}");
                } else {
                    // Single card - update preview_filename and clear any stale second card reference
                    $updateStmt = $pdo->prepare("
                        UPDATE sf_flashes 
                        SET preview_filename = ?,
                            preview_filename_2 = NULL,
                            preview_status = 'completed',
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$result, $flashId]);
                    
                    $processed++;
                    error_log("PreviewJob: Generated preview for flash {$flashId}: {$result}");
                }
                
            } else {
                // Generation failed
                throw new RuntimeException('Generator returned null');
            }
            
        } catch (Throwable $e) {
            // Mark as failed and store error message in database
            $errorMessage = $e->getMessage();
            error_log("PreviewJob: Failed to generate preview for flash {$flashId}: " . $errorMessage);
            error_log("PreviewJob: Stack trace: " . $e->getTraceAsString());
            
            $updateStmt = $pdo->prepare("
                UPDATE sf_flashes 
                SET preview_status = 'failed',
                    preview_error = ?
                WHERE id = ?
            ");
            $updateStmt->execute([mb_substr($errorMessage, 0, 1000), $flashId]);
            
            $failed++;
        }
    }
    
    // Log summary
    if ($processed > 0 || $failed > 0) {
        error_log("PreviewJob: Processed {$processed} previews, {$failed} failed");
    }
    
} catch (Throwable $e) {
    error_log('PreviewJob: Fatal error: ' . $e->getMessage());
} finally {
    // Release lock
    if (isset($fp) && $fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

exit(0);