<?php
/**
 * FlashImageService
 * 
 * Centralized image processing service for SafetyFlash.
 * Handles preview generation, worker job creation, and image uploads.
 * 
 * @package SafetyFlash
 * @subpackage Services
 */

declare(strict_types=1);

class FlashImageService
{
    /**
     * Insert (or replace) a job record in sf_jobs for the given flash.
     * Deletes any existing pending/in_progress rows first so there is always exactly
     * one active job per flash.
     *
     * @param int   $flashId  Flash ID
     * @param array $jobData  Associative array to be stored as JSON in job_data
     * @return void
     */
    public static function upsertJob(int $flashId, array $jobData): void
    {
        $pdo = Database::getInstance();
        $pdo->prepare("DELETE FROM sf_jobs WHERE flash_id = ? AND status IN ('pending', 'in_progress')")
            ->execute([$flashId]);
        $pdo->prepare("INSERT INTO sf_jobs (flash_id, job_data, status) VALUES (?, ?, 'pending')")
            ->execute([$flashId, json_encode($jobData, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Generate preview image for a flash
     * 
     * @param int $flashId Flash ID
     * @param array $data Form data containing preview_image_data
     * @return void
     */
    public function generatePreview(int $flashId, array $data): void
    {
        // Check if preview image data is provided
        if (empty($data['preview_image_data'])) {
            return;
        }
        
        $previewData = $data['preview_image_data'];
        
        // Extract base64 data from data URL
        if (preg_match('/^data:image\/(png|jpeg|jpg);base64,(.+)$/', $previewData, $matches)) {
            $imageData = base64_decode($matches[2]);
            
            if ($imageData === false) {
                error_log("FlashImageService: Failed to decode base64 image data for flash {$flashId}");
                return;
            }
            
            // Save preview image
            $uploadsDir = __DIR__ . '/../../uploads/previews/';
            if (!is_dir($uploadsDir)) {
                @mkdir($uploadsDir, 0755, true);
            }
            
            $filename = 'preview_' . $flashId . '_' . time() . '.png';
            $filepath = $uploadsDir . $filename;
            
            if (file_put_contents($filepath, $imageData) !== false) {
                // Update database with preview filename
                $pdo = Database::getInstance();
                $stmt = $pdo->prepare("UPDATE sf_flashes SET preview_filename = ? WHERE id = ?");
                $stmt->execute([$filename, $flashId]);
            } else {
                error_log("FlashImageService: Failed to write preview image file: {$filepath}");
            }
        }
    }
    
    /**
     * Create worker job in sf_jobs table for background image processing
     *
     * @param int $flashId Flash ID
     * @param array $postData POST data from form
     * @param array $files FILES array from upload
     * @return void
     */
    public function createWorkerJob(int $flashId, array $postData, array $files): void
    {
        $tempDataDir = __DIR__ . '/../../uploads/processes/';

        if (!is_dir($tempDataDir)) {
            @mkdir($tempDataDir, 0755, true);
        }

        $jobData = ['post' => $postData, 'files' => []];

        // Handle uploaded files – still moved to the filesystem so the worker can read them
        foreach ($files as $key => $file) {
            if (isset($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $safeName = preg_replace('/[^A-Za-z0-9\._-]/', '_', (string)($file['name'] ?? 'file'));
                $tmpPath = $tempDataDir . $flashId . '_' . $key . '_' . $safeName;

                if (move_uploaded_file($file['tmp_name'], $tmpPath)) {
                    $jobData['files'][$key] = $file;
                    $jobData['files'][$key]['tmp_name'] = $tmpPath;
                }
            }
        }

        // Write job data to the database
        try {
            self::upsertJob($flashId, $jobData);
        } catch (Throwable $e) {
            error_log("FlashImageService: Failed to create job record for flash {$flashId}: " . $e->getMessage());
        }
    }
    
    /**
     * Handle uploaded image file
     * 
     * @param array $file File array from $_FILES
     * @return string Filename of uploaded image
     * @throws Exception If upload fails
     */
    public function handleImageUpload(array $file): string
    {
        // Validate upload
        if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('Invalid file upload');
        }
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        $fileType = $file['type'] ?? '';
        
        if (!in_array($fileType, $allowedTypes, true)) {
            throw new Exception('Invalid file type. Only JPEG and PNG images are allowed.');
        }
        
        // Create uploads directory if needed
        $uploadsDir = __DIR__ . '/../../uploads/images/';
        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0755, true);
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'] ?? 'image.jpg', PATHINFO_EXTENSION) ?: 'jpg';
        $filename = 'img_' . uniqid() . '_' . time() . '.' . $extension;
        $filepath = $uploadsDir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        // Set proper permissions
        @chmod($filepath, 0644);
        
        return $filename;
    }
    
    /**
     * Handle temporary image files uploaded during form editing
     * 
     * @param int $flashId Flash ID
     * @param array $postData POST data containing temp_imageN fields
     * @return void
     */
    public function processTempImages(int $flashId, array $postData): void
    {
        $tempDir = __DIR__ . '/../../uploads/temp/';
        $imagesDir = __DIR__ . '/../../uploads/images/';
        
        if (!is_dir($imagesDir)) {
            @mkdir($imagesDir, 0755, true);
        }
        
        $pdo = Database::getInstance();
        
        // Whitelist of allowed database columns for security
        $allowedColumns = [
            1 => 'image_main',
            2 => 'image_2',
            3 => 'image_3'
        ];
        
        // Process each image slot
        foreach ($allowedColumns as $slot => $dbColumn) {
            $tempFilename = trim((string)($postData["temp_image{$slot}"] ?? ''));
            
            if ($tempFilename !== '' && strpos($tempFilename, 'temp_') === 0) {
                $tempPath = $tempDir . basename($tempFilename);
                
                if (is_file($tempPath)) {
                    // Create permanent filename
                    $ext = pathinfo($tempFilename, PATHINFO_EXTENSION) ?: 'jpg';
                    $permanentFilename = 'img_' . $flashId . '_' . $slot . '_' . time() . '.' . $ext;
                    $permanentPath = $imagesDir . $permanentFilename;
                    
                    // Move temp to permanent
                    if (rename($tempPath, $permanentPath)) {
                        // Update database using parameterized query
                        $sql = "UPDATE sf_flashes SET {$dbColumn} = :filename WHERE id = :id";
                        $updateStmt = $pdo->prepare($sql);
                        $updateStmt->execute([':filename' => $permanentFilename, ':id' => $flashId]);
                    }
                }
            }
        }
    }
}