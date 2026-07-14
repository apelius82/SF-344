<?php
/**
 * FlashSaveService
 * 
 * Centralized save service for SafetyFlash.
 * Orchestrates saving flash data with permission checks, change detection, logging, and worker job creation.
 * 
 * @package SafetyFlash
 * @subpackage Services
 */

declare(strict_types=1);

require_once __DIR__ . '/FlashPermissionService.php';
require_once __DIR__ . '/FlashLogService.php';
require_once __DIR__ . '/FlashImageService.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/image_helpers.php';

if (!function_exists('sf_term')) {
    require_once __DIR__ . '/../../assets/lib/sf_terms.php';
}

class PermissionException extends Exception {}

class FlashSaveService
{
    private FlashPermissionService $permissionService;
    private FlashLogService $logService;
    
    public function __construct()
    {
        $this->permissionService = new FlashPermissionService();
        $this->logService = new FlashLogService();
    }
    
    /**
     * Main save method - orchestrates the entire save process
     * 
     * @param int $flashId Flash ID to save
     * @param array $data Form data to save
     * @param array $user Current user data
     * @return array Result array with 'ok' and 'flash_id'
     * @throws Exception If flash not found or other errors occur
     * @throws PermissionException If user lacks permission
     */
    public function save(int $flashId, array $data, array $user): array
    {
        $pdo = Database::getInstance();
        
        // 1. Fetch existing flash
        $stmt = $pdo->prepare("SELECT * FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $flash = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$flash) {
            throw new Exception('Flash not found');
        }
        
        // 2. Check edit permission
        if (!$this->permissionService->canEdit($user, $flash)) {
            throw new PermissionException('No edit permission');
        }
        
        // 3. Check type change permission
        if (isset($data['type']) && $data['type'] !== $flash['type']) {
            if (!$this->permissionService->canChangeType($user, $flash)) {
                throw new PermissionException('Cannot change flash type');
            }
        }
        
        // 4. Detect changes
        $changes = $this->detectChanges($flash, $data);
        
        // 5. Update database (state NEVER changes in inline edit)
        $resolvedGridBitmap = $this->updateFlash($flashId, $data, $flash);

        // Propagate the resolved permanent grid bitmap filename so that the
        // worker job (created below) receives the correct filename and not the
        // now-moved temp file.
        if ($resolvedGridBitmap !== '') {
            $data['grid_bitmap'] = $resolvedGridBitmap;
        }
        
        // Generate a single batch_id for all log entries of this save operation
        $batchId = sf_log_generate_batch_id();

        $pendingWorkerIds = [];

        // Propagate type change to all sibling language versions in the same translation group
        if (isset($data['type']) && $data['type'] !== $flash['type']) {
            try {
                $newTypeForGroup = trim((string)$data['type']);
                $groupId = !empty($flash['translation_group_id'])
                    ? (int)$flash['translation_group_id']
                    : (int)$flashId;

                // Compute original_type the same way updateFlash() does
                $originalTypeForGroup = $flash['original_type'] ?? null;
                if ($originalTypeForGroup === null) {
                    $originalTypeForGroup = $flash['type'];
                }

                // Collect sibling IDs and their current (old) types BEFORE the UPDATE
                // so we can log the accurate before/after transition for each sibling.
                $sibStmt = $pdo->prepare("
                    SELECT id, type FROM sf_flashes
                    WHERE (id = :group_id OR translation_group_id = :group_id2)
                      AND id != :flash_id
                ");
                $sibStmt->execute([
                    ':group_id'  => $groupId,
                    ':group_id2' => $groupId,
                    ':flash_id'  => $flashId,
                ]);
                $siblings = $sibStmt->fetchAll(PDO::FETCH_ASSOC);

                $syncStmt = $pdo->prepare("
                    UPDATE sf_flashes
                    SET type = :type,
                        original_type = COALESCE(original_type, :original_type),
                        processing_status = 'pending',
                        is_processing = 1,
                        preview_status = 'pending',
                        updated_at = NOW()
                    WHERE (id = :group_id OR translation_group_id = :group_id2)
                      AND id != :flash_id
                ");
                $syncStmt->execute([
                    ':type'          => $newTypeForGroup,
                    ':original_type' => $originalTypeForGroup,
                    ':group_id'      => $groupId,
                    ':group_id2'     => $groupId,
                    ':flash_id'      => $flashId,
                ]);

                foreach ($siblings as $sib) {
                    $sibId      = (int)$sib['id'];
                    $sibOldType = (string)$sib['type'];

                    // Fetch sibling's own DB data to use its language-specific content
                    $sibFlashStmt = $pdo->prepare("SELECT * FROM sf_flashes WHERE id = ? LIMIT 1");
                    $sibFlashStmt->execute([$sibId]);
                    $sibFlash = $sibFlashStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$sibFlash) {
                        continue;
                    }

                    $this->logService->logTypeChange(
                        $sibId,
                        $sibOldType,
                        $newTypeForGroup,
                        (int)($user['id'] ?? 0),
                        $batchId
                    );

                    // Build job data from the sibling's own content so the worker
                    // renders its language-specific text with the new type/template.
                    // Both 'short_text' and 'title_short' are provided because the
                    // worker reads $post['short_text'] ?? $post['title_short'].
                    $sibJobData = [
                        'id'                 => $sibId,
                        'user_id'            => $user['id'] ?? null,
                        'type'               => $newTypeForGroup,
                        'lang'               => $sibFlash['lang'] ?? 'fi',
                        'title'              => $sibFlash['title'] ?? '',
                        'title_short'        => $sibFlash['title_short'] ?? '',
                        'short_text'         => $sibFlash['title_short'] ?? '',
                        'summary'            => $sibFlash['summary'] ?? '',
                        'description'        => $sibFlash['description'] ?? '',
                        'site'               => $sibFlash['site'] ?? '',
                        'site_detail'        => $sibFlash['site_detail'] ?? '',
                        'occurred_at'        => $sibFlash['occurred_at'] ?? '',
                        'root_causes'        => $sibFlash['root_causes'] ?? '',
                        'actions'            => $sibFlash['actions'] ?? '',
                        'annotations_data'   => $sibFlash['annotations_data'] ?? '[]',
                        'grid_layout'        => $sibFlash['grid_layout'] ?? 'grid-1',
                        'grid_bitmap'        => $sibFlash['grid_bitmap'] ?? '',
                        'font_size_override' => $sibFlash['font_size_override'] ?? '',
                        'layout_mode'        => $sibFlash['layout_mode'] ?? 'auto',
                        'image_main'         => $sibFlash['image_main'] ?? '',
                        'image_2'            => $sibFlash['image_2'] ?? '',
                        'image_3'            => $sibFlash['image_3'] ?? '',
                        'image1_caption'     => $sibFlash['image1_caption'] ?? '',
                        'image2_caption'     => $sibFlash['image2_caption'] ?? '',
                        'image3_caption'     => $sibFlash['image3_caption'] ?? '',
                        'image1_transform'   => $sibFlash['image1_transform'] ?? '',
                        'image2_transform'   => $sibFlash['image2_transform'] ?? '',
                        'image3_transform'   => $sibFlash['image3_transform'] ?? '',
                    ];
                    $this->createJobFile($sibId, $sibJobData);
                    $pendingWorkerIds[] = $sibId;
                }

                if (function_exists('sf_app_log')) {
                    sf_app_log("[FlashSaveService] Propagated type change ({$flash['type']} → {$newTypeForGroup}) to " . count($siblings) . " sibling(s) in group {$groupId}");
                }
            } catch (Throwable $e) {
                error_log("FlashSaveService: Failed to propagate type change to siblings: " . $e->getMessage());
            }
        }

        // 6. Log changes
        if (isset($changes['type'])) {
            $this->logService->logTypeChange(
                $flashId,
                $flash['type'],
                $data['type'],
                (int)($user['id'] ?? 0),
                $batchId
            );
            // Remove type from general changes to avoid duplicate logging
            unset($changes['type']);
        }
        
        // Log all other changes
        if (!empty($changes)) {
            $this->logService->logEdit($flashId, $changes, (int)($user['id'] ?? 0), $batchId);
        }
        
        // Log detailed image-related changes
        // Resolve new image values (same logic as updateFlash)
        $newImageMain = trim((string)($data['library_image_1'] ?? ''));
        if ($newImageMain === '') {
            $newImageMain = trim((string)($data['existing_image_1'] ?? ''));
        }
        $newImage2 = trim((string)($data['library_image_2'] ?? ''));
        if ($newImage2 === '') {
            $newImage2 = trim((string)($data['existing_image_2'] ?? ''));
        }
        $newImage3 = trim((string)($data['library_image_3'] ?? ''));
        if ($newImage3 === '') {
            $newImage3 = trim((string)($data['existing_image_3'] ?? ''));
        }

        // Per-image: detect image swap – store term key, view.php translates at render time
        if ((string)($flash['image_main'] ?? '') !== $newImageMain) {
            sf_log_event($flashId, 'image_1_changed', 'log_image_1_changed', $batchId);
        }
        if ((string)($flash['image_2'] ?? '') !== $newImage2) {
            sf_log_event($flashId, 'image_2_changed', 'log_image_2_changed', $batchId);
        }
        if ((string)($flash['image_3'] ?? '') !== $newImage3) {
            sf_log_event($flashId, 'image_3_changed', 'log_image_3_changed', $batchId);
        }

        // Per-image: detect transform (repositioning) changes
        $transformMap = [
            'image1_transform' => ['log_image_1_repositioned', 'image_1_repositioned'],
            'image2_transform' => ['log_image_2_repositioned', 'image_2_repositioned'],
            'image3_transform' => ['log_image_3_repositioned', 'image_3_repositioned'],
        ];
        foreach ($transformMap as $dbField => [$termKey, $eventType]) {
            $oldVal = (string)($flash[$dbField] ?? '');
            $newVal = trim((string)($data[$dbField] ?? ''));
            if ($oldVal !== $newVal) {
                sf_log_event($flashId, $eventType, $termKey, $batchId);
            }
        }

        // Per-image: detect caption changes
        $captionMap = [
            'image1_caption' => ['log_image_caption_1_changed', 'image_caption_1_changed'],
            'image2_caption' => ['log_image_caption_2_changed', 'image_caption_2_changed'],
            'image3_caption' => ['log_image_caption_3_changed', 'image_caption_3_changed'],
        ];
        foreach ($captionMap as $dbField => [$termKey, $eventType]) {
            $oldVal = (string)($flash[$dbField] ?? '');
            $newVal = trim((string)($data[$dbField] ?? ''));
            if ($oldVal !== $newVal) {
                sf_log_event($flashId, $eventType, $termKey, $batchId);
            }
        }

        // Annotations changes
        $oldAnnotations = (string)($flash['annotations_data'] ?? '');
        $newAnnotations = trim((string)($data['annotations_data'] ?? '[]'));
        if ($oldAnnotations !== $newAnnotations) {
            sf_log_event($flashId, 'annotations_changed', 'log_annotations_changed', $batchId);
        }

        // Grid layout / bitmap changes
        $oldGridLayout = (string)($flash['grid_layout'] ?? '');
        $newGridLayout = trim((string)($data['grid_layout'] ?? 'grid-1'));
        $oldGridBitmap = (string)($flash['grid_bitmap'] ?? '');
        if ($oldGridLayout !== $newGridLayout || ($resolvedGridBitmap !== '' && $oldGridBitmap !== $resolvedGridBitmap)) {
            sf_log_event($flashId, 'grid_layout_changed', 'log_grid_layout_changed', $batchId);
        }

        // Appearance settings (font size, layout mode)
        $oldFontSize  = (string)($flash['font_size_override'] ?? '');
        $newFontSize  = !empty($data['font_size_override']) ? trim((string)$data['font_size_override']) : '';
        $oldLayoutMode = (string)($flash['layout_mode'] ?? '');
        $newLayoutMode = !empty($data['layout_mode']) ? trim((string)$data['layout_mode']) : 'auto';
        if ($oldFontSize !== $newFontSize) {
            sf_log_event($flashId, 'font_size_changed', "log_font_size_changed: {$oldFontSize} → {$newFontSize}", $batchId);
        }

        if ($oldLayoutMode !== $newLayoutMode) {
            sf_log_event($flashId, 'layout_mode_changed', "log_layout_mode_changed: {$oldLayoutMode} → {$newLayoutMode}", $batchId);
        }

        $imageEditMap = [
            'image1_edited_data' => ['log_image_1_edited_changed', 'image_1_edited_changed'],
            'image2_edited_data' => ['log_image_2_edited_changed', 'image_2_edited_changed'],
            'image3_edited_data' => ['log_image_3_edited_changed', 'image_3_edited_changed'],
        ];

        foreach ($imageEditMap as $dbField => [$termKey, $eventType]) {
            if (!array_key_exists($dbField, $data)) {
                continue;
            }

            $oldVal = trim((string)($flash[$dbField] ?? ''));
            $newVal = trim((string)($data[$dbField] ?? ''));

            if ($oldVal !== $newVal) {
                sf_log_event($flashId, $eventType, $termKey, $batchId);
            }
        }
        
        // 7. Create worker job for image generation
        // Debug logging for preview data
        $preview1Length = isset($data['preview_image_data']) ? strlen($data['preview_image_data']) : 0;
        $preview2Length = isset($data['preview_image_data_2']) ? strlen($data['preview_image_data_2']) : 0;
        sf_app_log("[FlashSaveService] Creating worker job for flash {$flashId}: preview_image_data length: {$preview1Length}, preview_image_data_2 length: {$preview2Length}");
        
        $this->createJobFile($flashId, array_merge($data, ['user_id' => $user['id'] ?? null]));
        $pendingWorkerIds[] = $flashId;
        
        return ['ok' => true, 'flash_id' => $flashId, 'pending_worker_ids' => $pendingWorkerIds];
    }
    
    /**
     * Validate user permissions
     * 
     * @param int $flashId Flash ID
     * @param array $data Form data
     * @param array $user Current user
     * @throws PermissionException If user lacks permission
     * @return void
     */
    public function validatePermissions(int $flashId, array $data, array $user): void
    {
        $pdo = Database::getInstance();
        
        $stmt = $pdo->prepare("SELECT * FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $flash = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$flash) {
            throw new Exception('Flash not found');
        }
        
        if (!$this->permissionService->canEdit($user, $flash)) {
            throw new PermissionException('No edit permission');
        }
        
        // Check type change permission if type is being changed
        if (isset($data['type']) && $data['type'] !== $flash['type']) {
            if (!$this->permissionService->canChangeType($user, $flash)) {
                throw new PermissionException('Cannot change flash type');
            }
        }
    }
    
    /**
     * Detect what changed between original and new data
     * 
     * @param array $original Original flash data
     * @param array $data New data
     * @return array Array of changes with old and new values
     */
    public function detectChanges(array $original, array $data): array
    {
        $changes = [];
        
        // Fields to track for changes
$trackFields = [
    'type',
    'lang',
    'title',
    'title_short',
    'summary',
    'description',
    'site',
    'site_detail',
    'occurred_at',
    'root_causes',
    'actions'
];
        
        foreach ($trackFields as $field) {
            if (isset($data[$field]) && isset($original[$field])) {
                $oldValue = (string)$original[$field];
                $newValue = (string)$data[$field];
                
                if ($field === 'occurred_at') {
                    $oldTs = strtotime($oldValue);
                    $newTs = strtotime($newValue);
                    if ($oldTs !== false && $newTs !== false && $oldTs === $newTs) {
                        continue; // Same datetime, just different format
                    }
                }
                
                if ($oldValue !== $newValue) {
                    $changes[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue
                    ];
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * Update flash in database
     * 
     * @param int $flashId Flash ID
     * @param array $data Form data
     * @param array $currentFlash Current flash data from database (optional, fetched if not provided)
     * @return string Resolved permanent grid bitmap filename (empty string if none)
     * @throws Exception If database update fails
     */
    public function updateFlash(int $flashId, array $data, ?array $currentFlash = null): string
    {
        $pdo = Database::getInstance();
        
        // Parse occurred_at date if present
        $occurredAt = null;
        if (!empty($data['occurred_at'])) {
            $ts = strtotime($data['occurred_at']);
            if ($ts !== false) {
                $occurredAt = date('Y-m-d H:i:s', $ts);
            }
        }
        
        // Check if type is changing and original_type should be preserved
        if ($currentFlash === null) {
            $stmt = $pdo->prepare("SELECT type, original_type FROM sf_flashes WHERE id = ?");
            $stmt->execute([$flashId]);
            $currentFlash = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $originalType = $currentFlash['original_type'] ?? null;
        $newType = trim((string)($data['type'] ?? 'yellow'));
        $oldType = $currentFlash['type'] ?? '';
        
        // If type is changing and original_type is not yet set, store the old type
        if ($newType !== $oldType && $originalType === null) {
            $originalType = $oldType;
        }
        
        $sql = "UPDATE sf_flashes SET
            title = :title,
            title_short = :title_short,
            summary = :summary,
            description = :description,
            type = :type,
            original_type = :original_type,
            site = :site,
            site_detail = :site_detail,
            occurred_at = :occurred_at,
            lang = :lang,
            root_causes = :root_causes,
            actions = :actions,
            annotations_data = :annotations_data,
            image_main = :image_main,
            image_2 = :image_2,
            image_3 = :image_3,
            image1_caption = :image1_caption,
            image2_caption = :image2_caption,
            image3_caption = :image3_caption,
            image1_transform = :image1_transform,
            image2_transform = :image2_transform,
            image3_transform = :image3_transform,
            image1_edited_data = :image1_edited_data,
            image2_edited_data = :image2_edited_data,
            image3_edited_data = :image3_edited_data,
            grid_layout = :grid_layout,
            grid_bitmap = :grid_bitmap,
            font_size_override = :font_size_override,
            layout_mode = :layout_mode,
            processing_status = 'pending',
            is_processing = 1,
            preview_status = 'pending',
            updated_at = NOW()
            WHERE id = :id";
        
        // Process grid_bitmap before database update
        $gridBitmapValue = trim((string)($data['grid_bitmap'] ?? ''));
        $gridBitmapFilename = '';

        if ($gridBitmapValue === '') {
            $gridBitmapFilename = '';
        } elseif (strncmp($gridBitmapValue, 'temp_grid_', 10) === 0) {
            $tempFilename = basename($gridBitmapValue);
            $tempPath = __DIR__ . '/../../uploads/temp/' . $tempFilename;
            $gridsDir = __DIR__ . '/../../uploads/grids/';

            if (!is_dir($gridsDir)) {
                @mkdir($gridsDir, 0755, true);
            }

            if (is_file($tempPath)) {
                $tmpExt = strtolower((string)pathinfo($tempFilename, PATHINFO_EXTENSION));
                $allowedExts = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

                if (!in_array($tmpExt, $allowedExts, true)) {
                    $tmpExt = 'png';
                }

                if ($tmpExt === 'jpeg') {
                    $tmpExt = 'jpg';
                }

                $permanentFilename = 'grid_' . $flashId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $tmpExt;
                $permanentPath = $gridsDir . $permanentFilename;

                if (@rename($tempPath, $permanentPath)) {
                    @chmod($permanentPath, 0644);
                    $gridBitmapFilename = $permanentFilename;
                } elseif (@copy($tempPath, $permanentPath)) {
                    @chmod($permanentPath, 0644);
                    @unlink($tempPath);
                    $gridBitmapFilename = $permanentFilename;
                } else {
                    error_log("FlashSaveService: Failed to move temp grid bitmap for flash {$flashId}: {$tempPath}");
                    $gridBitmapFilename = '';
                }
            } else {
                error_log("FlashSaveService: Temp grid bitmap file not found for flash {$flashId}: {$tempPath}");
                $gridBitmapFilename = '';
            }
        } else {
            $gridBitmapFilename = sf_save_grid_bitmap_to_file($gridBitmapValue, $flashId);
        }

        // Resolve image values: prefer library image selection over existing, if set
        $imageMain = trim((string)($data['library_image_1'] ?? ''));
        if ($imageMain === '') {
            $imageMain = trim((string)($data['existing_image_1'] ?? ''));
        }
        $image2 = trim((string)($data['library_image_2'] ?? ''));
        if ($image2 === '') {
            $image2 = trim((string)($data['existing_image_2'] ?? ''));
        }
        $image3 = trim((string)($data['library_image_3'] ?? ''));
        if ($image3 === '') {
            $image3 = trim((string)($data['existing_image_3'] ?? ''));
        }

        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            ':title' => trim((string)($data['title'] ?? '')),
            ':title_short' => trim((string)($data['title_short'] ?? '')),
            ':summary' => trim((string)($data['summary'] ?? '')),
            ':description' => trim((string)($data['description'] ?? '')),
            ':type' => $newType,
            ':original_type' => $originalType,
            ':site' => trim((string)($data['site'] ?? '')),
            ':site_detail' => trim((string)($data['site_detail'] ?? '')),
            ':occurred_at' => $occurredAt,
            ':lang' => trim((string)($data['lang'] ?? 'fi')),
            ':root_causes' => trim((string)($data['root_causes'] ?? '')),
            ':actions' => trim((string)($data['actions'] ?? '')),
            ':annotations_data' => trim((string)($data['annotations_data'] ?? '[]')),
            ':image_main' => $imageMain,
            ':image_2'    => $image2,
            ':image_3'    => $image3,
            ':image1_caption' => trim((string)($data['image1_caption'] ?? '')),
            ':image2_caption' => trim((string)($data['image2_caption'] ?? '')),
            ':image3_caption' => trim((string)($data['image3_caption'] ?? '')),
            ':image1_transform' => trim((string)($data['image1_transform'] ?? '')),
            ':image2_transform' => trim((string)($data['image2_transform'] ?? '')),
            ':image3_transform' => trim((string)($data['image3_transform'] ?? '')),
            ':image1_edited_data' => trim((string)($data['image1_edited_data'] ?? '')),
            ':image2_edited_data' => trim((string)($data['image2_edited_data'] ?? '')),
            ':image3_edited_data' => trim((string)($data['image3_edited_data'] ?? '')),
            ':grid_layout' => trim((string)($data['grid_layout'] ?? 'grid-1')),
            ':grid_bitmap' => $gridBitmapFilename,
            ':font_size_override' => !empty($data['font_size_override']) ? trim((string)$data['font_size_override']) : null,
            ':layout_mode' => !empty($data['layout_mode']) ? trim((string)$data['layout_mode']) : 'auto',
            ':id' => $flashId,
        ]);
        
        if (!$success) {
            throw new Exception('Failed to update flash in database');
        }

        return $gridBitmapFilename;
    }
    
    /**
     * Create job record in sf_jobs table for worker to process flash image
     *
     * @param int $flashId Flash ID
     * @param array $data Form data (POST data)
     * @return void
     */
    public function createJobFile(int $flashId, array $data): void
    {
        // Create job data for worker
        $jobData = [
            'post' => $data,
            'files' => [] // Edit mode doesn't upload new files, just updates previews
        ];

        try {
            FlashImageService::upsertJob($flashId, $jobData);
        } catch (Throwable $e) {
            error_log("FlashSaveService: Failed to create job record for flash {$flashId}: " . $e->getMessage());
        }
    }
    
    /**
     * Trigger worker execution for image processing
     * Tries background execution first, falls back to inline
     * 
     * @param int $flashId Flash ID to process
     * @return void
     */
    private function triggerWorker(int $flashId): void
    {
        // DEFENSIVE: never trigger a worker while an outer transaction is active.
        // An inline worker opens a fresh DB connection which cannot see the parent
        // transaction's locks, causing a 1205 Lock wait timeout.
        try {
            if (Database::getInstance()->inTransaction()) {
                error_log("FlashSaveService::triggerWorker() refused: outer transaction is active. flash_id={$flashId}");
                if (function_exists('sf_app_log')) {
                    sf_app_log("[FlashSaveService] Refusing to trigger worker for flash {$flashId} while transaction is active", 'WARN');
                }
                return;
            }
        } catch (Throwable $e) {
            // If we can't check the transaction state, log and proceed cautiously with the original logic
            error_log("FlashSaveService::triggerWorker() could not check transaction state: " . $e->getMessage());
        }

        $workerPath = __DIR__ . '/../api/process_flash_worker.php';
        
        if (!file_exists($workerPath)) {
            error_log("FlashSaveService: Worker script not found");
            return;
        }
        
        // Try background execution first (non-blocking).
        // Also verify shell_exec is not in the disable_functions list (shared hosting).
        $shellExecDisabled = !function_exists('shell_exec')
            || in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

        if (!$shellExecDisabled) {
            $phpBinary = PHP_BINARY ?: 'php';
            $cmd = escapeshellarg($phpBinary) . " " . escapeshellarg($workerPath) . " " . escapeshellarg((string)$flashId) . " > /dev/null 2>&1 &";
            
            $result = @shell_exec($cmd);
            if ($result !== null) {
                sf_app_log("[FlashSaveService] Worker triggered in background for flash {$flashId}");
                return;
            }
        }
        
        // Fallback: inline execution (blocking but reliable)
        sf_app_log("[FlashSaveService] Falling back to inline worker execution for flash {$flashId}");
        
        if (!defined('SF_ALLOW_WEB_WORKER')) {
            define('SF_ALLOW_WEB_WORKER', true);
        }

        // CRITICAL: Force a fresh DB connection for the worker.
        // The current request may have held the singleton PDO connection long enough
        // that MySQL's wait_timeout has closed it, causing error 2006 on the worker's
        // first prepare(). Reconnect here so the worker starts with a live connection.
        try {
            Database::reconnect();
        } catch (Throwable $e) {
            error_log("FlashSaveService: Failed to refresh DB connection before worker: " . $e->getMessage());
        }

        // Save and restore GET to avoid side effects
        $originalGet = $_GET;
        $_GET['flash_id'] = $flashId;
        
        try {
            require $workerPath;
        } catch (Throwable $e) {
            error_log("FlashSaveService: Inline worker failed: " . $e->getMessage());
        }
        
        $_GET = $originalGet;
    }
}