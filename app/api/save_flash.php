<?php
// app/api/save_flash.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ . '/../includes/image_helpers.php';
require_once __DIR__ . '/../services/UserEventService.php';

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function sf_json_response(array $data, int $statusCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function sf_finish_request(): void
{
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }

    if (function_exists('litespeed_finish_request')) {
        @litespeed_finish_request();
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ob_flush();
    @flush();
}

function sf_shell_exec_available(): bool
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = (string) ini_get('disable_functions');
    if ($disabled === '') {
        return true;
    }

    $disabledList = array_map('trim', explode(',', $disabled));
    return !in_array('shell_exec', $disabledList, true);
}

/**
 * Handle extra images (additional images feature)
 * Moves uploaded extra images from temp to permanent storage and inserts records into database
 * 
 * @param array $post POST data containing extra_images JSON
 * @param int $flashId Flash ID to associate images with
 * @param PDO $pdo Database connection
 * @return void
 */
function sf_handle_extra_images(array $post, int $flashId, PDO $pdo): void
{
    $extraImagesJson = trim((string)($post['extra_images'] ?? ''));
    if ($extraImagesJson === '') {
        return;
    }
    
    $extraImages = json_decode($extraImagesJson, true);
    if (!is_array($extraImages) || empty($extraImages)) {
        return;
    }
    
    // Create extra images directory if needed
    $extraImagesDir = __DIR__ . '/../../uploads/extra_images/';
    if (!is_dir($extraImagesDir)) {
        @mkdir($extraImagesDir, 0755, true);
    }
    
    $tempDir = __DIR__ . '/../../uploads/temp/';
    
    foreach ($extraImages as $extraImage) {
        $tempFilename = trim((string)($extraImage['filename'] ?? ''));
        $originalFilename = trim((string)($extraImage['original_filename'] ?? ''));
        
        // Security: validate filename is from temp and use basename
        if ($tempFilename === '' || strpos($tempFilename, 'temp_extra_') !== 0) {
            continue; // Skip invalid entries
        }
        
        // Security: Use basename to prevent directory traversal
        $tempFilename = basename($tempFilename);
        $tempPath = $tempDir . $tempFilename;
        $tempThumbPath = $tempDir . 'thumb_' . $tempFilename;
        
        // Verify file exists in temp directory before moving
        if (!is_file($tempPath)) {
            continue; // Skip if file doesn't exist
        }
        
        // Generate permanent filename
        $ext = pathinfo($tempFilename, PATHINFO_EXTENSION) ?: 'jpg';
        $permanentFilename = 'extra_' . $flashId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $permanentPath = $extraImagesDir . $permanentFilename;
        $permanentThumbPath = $extraImagesDir . 'thumb_' . $permanentFilename;
        
        // Move temp files to permanent location
        if (rename($tempPath, $permanentPath)) {
            // Move thumbnail if it exists
            if (is_file($tempThumbPath)) {
                @rename($tempThumbPath, $permanentThumbPath);
            }
            
            // Insert into database
            $insertStmt = $pdo->prepare("
                INSERT INTO sf_flash_images (flash_id, filename, original_filename, created_at)
                VALUES (:flash_id, :filename, :original_filename, NOW())
            ");
            $insertStmt->execute([
                ':flash_id' => $flashId,
                ':filename' => $permanentFilename,
                ':original_filename' => $originalFilename
            ]);
        }
    }
}

/**
 * Handle extra videos (additional videos feature)
 * Moves uploaded videos from temp to permanent storage and inserts records into database
 *
 * @param array $post POST data containing extra_videos JSON
 * @param int $flashId Flash ID to associate videos with
 * @param PDO $pdo Database connection
 * @return void
 */
function sf_handle_extra_videos(array $post, int $flashId, PDO $pdo): void
{
    $extraVideosJson = trim((string)($post['extra_videos'] ?? ''));
    if ($extraVideosJson === '') {
        return;
    }

    $extraVideos = json_decode($extraVideosJson, true);
    if (!is_array($extraVideos) || empty($extraVideos)) {
        return;
    }

    // Create extra images directory if needed (videos share the same dir)
    $extraImagesDir = __DIR__ . '/../../uploads/extra_images/';
    if (!is_dir($extraImagesDir)) {
        @mkdir($extraImagesDir, 0755, true);
    }

    $tempDir = __DIR__ . '/../../uploads/temp/';

    $allowedExtensions = ['mp4', 'webm', 'ogv', 'mov', 'avi', 'mkv'];

    foreach ($extraVideos as $extraVideo) {
        $tempFilename     = trim((string)($extraVideo['filename'] ?? ''));
        $originalFilename = trim((string)($extraVideo['original_filename'] ?? ''));

        // Security: validate filename is from temp and use basename
        if ($tempFilename === '' || strpos($tempFilename, 'temp_video_') !== 0) {
            continue;
        }

        $tempFilename = basename($tempFilename);
        $tempPath     = $tempDir . $tempFilename;

        if (!is_file($tempPath)) {
            continue;
        }

        $rawExt = pathinfo($tempFilename, PATHINFO_EXTENSION);
        if ($rawExt === '' || $rawExt === false) {
            @unlink($tempPath);
            continue;
        }
        $ext = strtolower($rawExt);
        if (!in_array($ext, $allowedExtensions, true)) {
            @unlink($tempPath);
            continue;
        }

        $permanentFilename = 'video_' . $flashId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $permanentPath     = $extraImagesDir . $permanentFilename;

        if (rename($tempPath, $permanentPath)) {
            $insertStmt = $pdo->prepare("
                INSERT INTO sf_flash_images (flash_id, filename, original_filename, media_type, created_at)
                VALUES (:flash_id, :filename, :original_filename, 'video', NOW())
            ");
            $insertStmt->execute([
                ':flash_id'          => $flashId,
                ':filename'          => $permanentFilename,
                ':original_filename' => $originalFilename,
            ]);
        }
    }
}

// -----------------------------------------------------------------------------
// Main image slot swap
// -----------------------------------------------------------------------------

/**
 * Swap image slot data in $post so that the selected slot becomes slot 1 (image_main).
 * This implements physical slot reordering at save time: all downstream code that reads
 * image_main, image_2, image_3 and their associated fields will automatically use the
 * correct image without any schema changes.
 *
 * Fields swapped between slot 1 and slot $slot:
 *   - temp_imageN          (temp file from immediate upload)
 *   - existing_image_N     (existing DB filename)
 *   - library_image_N      (library image filename)
 *   - imageN_transform     (zoom/pan/rotate JSON)
 *   - imageN_caption       (caption text)
 *   - imageN_edited_data   (edited image data URL from image editor)
 *
 * annotations_data JSON keys "image1"/"image2"/"image3" are also swapped.
 *
 * @param int   $slot Target slot (1 = no-op, 2 or 3 = swap with slot 1)
 * @param array $post POST data array (modified in place)
 * @return void
 */
function sf_swap_image_slots(int $slot, array &$post): void
{
    if ($slot !== 2 && $slot !== 3) {
        return;
    }

    $pairs = [
        ['temp_image1',       "temp_image{$slot}"],
        ['existing_image_1',  "existing_image_{$slot}"],
        ['library_image_1',   "library_image_{$slot}"],
        ['image1_transform',  "image{$slot}_transform"],
        ['image1_caption',    "image{$slot}_caption"],
        ['image1_edited_data', "image{$slot}_edited_data"],
    ];

    foreach ($pairs as [$a, $b]) {
        $tmp    = $post[$a] ?? '';
        $post[$a] = $post[$b] ?? '';
        $post[$b] = $tmp;
    }

    // Swap annotation keys in annotations_data JSON
    $annotationsRaw = trim((string)($post['annotations_data'] ?? ''));
    if ($annotationsRaw !== '' && $annotationsRaw !== '[]') {
        $annotations = json_decode($annotationsRaw, true);
        if (is_array($annotations)) {
            $key1   = 'image1';
            $keyN   = "image{$slot}";
            $tmp    = $annotations[$key1] ?? [];
            $annotations[$key1] = $annotations[$keyN] ?? [];
            $annotations[$keyN] = $tmp;
            $post['annotations_data'] = json_encode($annotations, JSON_UNESCAPED_UNICODE);
        }
    }

    // Reset main_image_slot to 1 — selection has been applied as a physical swap
    $post['main_image_slot'] = 1;
}

// -----------------------------------------------------------------------------
// Request validation
// -----------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sf_json_response(['ok' => false, 'error' => 'Method Not Allowed'], 405);
    exit;
}

// CSRF protection
$csrfToken = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    sf_json_response(['ok' => false, 'error' => sf_term('error_csrf_invalid', $currentUiLang)], 403);
    exit;
}

$post  = $_POST;
$files = $_FILES;

// Apply main image slot selection: physically swap slot N → slot 1 in $post
// so all downstream code (FlashSaveService, image processing, worker) sees
// the correct image in image_main without any changes to their logic.
sf_swap_image_slots((int)($post['main_image_slot'] ?? 1), $post);

$id = isset($post['id']) ? (int) $post['id'] : 0;

$title = trim((string) ($post['title'] ?? ''));

// Debug logging (only if debug mode is enabled)
if (!empty($config['debug'])) {
    error_log('[save_flash.php] Title received: ' . ($title === '' ? 'EMPTY' : $title));
    error_log('[save_flash.php] POST keys: ' . implode(', ', array_keys($post)));
}

if ($title === '') {
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    if (!empty($config['debug'])) {
        error_log('[save_flash.php] Title validation FAILED - returning error');
    }
    sf_json_response(['ok' => false, 'error' => sf_term('error_title_required', $currentUiLang)], 400);
    exit;
}

$submissionType = trim((string) ($post['submission_type'] ?? 'review'));

// Check if this is a translation child
$isTranslationChild = isset($post['is_translation_child']) && $post['is_translation_child'] === '1';

// For translation children, override submission type to 'translation' UNLESS the user
// explicitly chose 'review' (bundle send-to-supervisor flow).
if ($isTranslationChild && $submissionType !== 'review') {
    $submissionType = 'translation';
}

// Read submission comment (user's message to reviewer)
$submissionComment = isset($post['submission_comment']) ? trim($post['submission_comment']) : '';
if ($submissionComment !== '') {
    $submissionComment = mb_substr($submissionComment, 0, 1000);
}

// Check if approver IDs are provided (for supervisor workflow)
$approverIds = isset($post['approver_ids']) ? json_decode($post['approver_ids'], true) : [];
$hasSupervisors = is_array($approverIds) && !empty($approverIds);

// Determine new state based on submission type and whether supervisors are selected
if ($submissionType === 'draft') {
    $newState = 'draft';
} elseif ($submissionType === 'translation') {
    $newState = 'draft'; // Translation children saved individually are saved as drafts
} elseif ($hasSupervisors) {
    $newState = 'pending_supervisor'; // Send to supervisor first
} else {
    $newState = 'pending_review'; // No supervisors, go directly to safety team
}

$currentUserForAdminImport = sf_current_user();
$isAdminImportUser = $currentUserForAdminImport && (int)($currentUserForAdminImport['role_id'] ?? 0) === 1;
$adminImportEnabled = $id === 0 && !$isTranslationChild && $isAdminImportUser && (($post['admin_import_enabled'] ?? '') === '1');

$createdAtValue = null;
$adminImportLogText = '';

if ($adminImportEnabled) {
    $allowedStates = [
        'draft',
        'pending_supervisor',
        'pending_review',
        'safety_review',
        'pending_comms',
        'approved',
        'published',
        'archived',
        'returned',
    ];
    $requestedState = trim((string)($post['admin_initial_state'] ?? 'published'));

    if (in_array($requestedState, $allowedStates, true)) {
        $newState = $requestedState;
    } else {
        $newState = 'published';
    }

    $createdAtMode = trim((string)($post['admin_created_at_mode'] ?? 'now'));

    if ($createdAtMode === 'occurred_at') {
        $rawCreatedAt = trim((string)($post['occurred_at'] ?? $post['event_date'] ?? ''));
    } elseif ($createdAtMode === 'custom') {
        $rawCreatedAt = trim((string)($post['admin_created_at_custom'] ?? ''));
    } else {
        $rawCreatedAt = '';
    }

    if ($rawCreatedAt !== '') {
        $createdAtTs = strtotime($rawCreatedAt);
        if ($createdAtTs !== false) {
            $createdAtValue = date('Y-m-d H:i:s', $createdAtTs);
        }
    }

    if ($createdAtValue === null) {
        $createdAtValue = date('Y-m-d H:i:s');
    }

    $adminImportLogText = 'Admin määritti SafetyFlashin tuontitiedot käsin. Luontipäivä: ' . $createdAtValue . '. Tallennustila: ' . $newState . '.';
}

// Map form field names -> DB field names
$site = trim((string) ($post['site'] ?? $post['worksite'] ?? ''));

$occurredRaw = trim((string) ($post['occurred_at'] ?? $post['event_date'] ?? ''));
$occurredAt  = null;
if ($occurredRaw !== '') {
    $ts = strtotime($occurredRaw);
    if ($ts !== false) {
        $occurredAt = date('Y-m-d H:i:s', $ts);
    }
}

$titleShort = trim((string) ($post['title_short'] ?? $post['short_text'] ?? ''));
$summary    = trim((string) ($post['summary'] ?? ''));
if ($summary === '' && $titleShort !== '') {
    $summary = $titleShort;
}

// Apply summary fallback to POST data for consistency
if (empty($post['summary']) && !empty($titleShort)) {
    $post['summary'] = $titleShort;
}

// Ensure created_by is set
$currentUser = sf_current_user();
$createdBy = null;

if ($currentUser && isset($currentUser['id'])) {
    $createdBy = (int) $currentUser['id'];
} elseif (isset($_SESSION['user_id'])) {
    $createdBy = (int) $_SESSION['user_id'];
}

if ($createdBy !== null && $createdBy <= 0) {
    $createdBy = null;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    // Tarkista onko kyseessä tutkintatiedote joka päivittää olemassaolevan
    $relatedFlashId = isset($post['related_flash_id']) ? (int) $post['related_flash_id'] : 0;
    $type = trim((string) ($post['type'] ?? 'yellow'));
    $isInvestigationUpdate = ($type === 'green' && $relatedFlashId > 0 && $id === 0);

    // =========================================================================
    // MUOKKAUS: Olemassa olevan flashin päivitys
    // =========================================================================
    if ($id > 0) {
        // Use FlashSaveService for UPDATE (inline edit),
        // BUT allow state change when resubmitting from draft/request_info
        require_once __DIR__ . '/../services/FlashSaveService.php';
        
        try {
            $saveService = new FlashSaveService();
            
            // Build user array for permission checks (use current user, not creator)
            $user = [
                'id' => $currentUser['id'] ?? ($createdBy ?? 0),
                'role_id' => $currentUser['role_id'] ?? 0
            ];
            
            // Fetch current state/type/site for resubmission logic (don't trust POST for locked fields)
            $stmt = $pdo->prepare("SELECT state, type, site FROM sf_flashes WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $oldState = $row['state'] ?? '';
            $oldType  = $row['type'] ?? '';
            $oldSite  = $row['site'] ?? '';
            
            // Normalize field names for service (form uses alternative names)
            $normalizedPost = $post;

            // Map worksite -> site
            if (!isset($normalizedPost['site']) && isset($normalizedPost['worksite'])) {
                $normalizedPost['site'] = $normalizedPost['worksite'];
            }

            // Preserve locked worksite for translation child even if browser does not submit disabled select
            if ($isTranslationChild && (!isset($normalizedPost['site']) || trim((string)$normalizedPost['site']) === '')) {
                $normalizedPost['site'] = $oldSite;
            }

            // Map short_text -> title_short
            if (!isset($normalizedPost['title_short']) && isset($normalizedPost['short_text'])) {
                $normalizedPost['title_short'] = $normalizedPost['short_text'];
            }

            // Map event_date -> occurred_at
            if (!isset($normalizedPost['occurred_at']) && isset($normalizedPost['event_date'])) {
                $normalizedPost['occurred_at'] = $normalizedPost['event_date'];
            }
            // Note: summary fallback already applied to $post at line 127-132
            
            // 1) Save content changes (service does NOT change state)
            $result = $saveService->save($id, $normalizedPost, $user);
            $pendingWorkerIds = $result['pending_worker_ids'] ?? [];

            // After FlashSaveService::save(), the temp grid bitmap file has been
            // moved to its permanent location and the DB has been updated.
            // Read the resolved permanent filename back from DB so that the
            // $jobData built later (line ~750) contains the correct value.
            $gridStmt = $pdo->prepare("SELECT grid_bitmap FROM sf_flashes WHERE id = ?");
            $gridStmt->execute([$id]);
            $gridRow = $gridStmt->fetch(PDO::FETCH_ASSOC);
            if ($gridRow && !empty($gridRow['grid_bitmap'])) {
                $post['grid_bitmap'] = $gridRow['grid_bitmap'];
            }

            $newId = $id;

            // 2) If sending again for review FROM draft/request_info -> advance state + store selected approvers
            if ($submissionType === 'review' && in_array($oldState, ['draft', 'request_info'], true)) {

                $sel = $hasSupervisors ? json_encode(array_map('intval', $approverIds)) : null;

                $stmt = $pdo->prepare("
                    UPDATE sf_flashes SET
                        state = :state,
                        selected_approvers = :selected_approvers,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':state' => $newState, // calculated earlier: pending_supervisor OR pending_review
                    ':selected_approvers' => $sel,
                    ':id' => $id
                ]);

                // Insert selected approvers into flash_supervisors table for display in view page
                if ($hasSupervisors && $newState === 'pending_supervisor') {
                    // Clear any existing supervisor assignments before inserting new ones
                    // (needed if user changes supervisors during resubmission)
                    $stmt = $pdo->prepare("DELETE FROM flash_supervisors WHERE flash_id = ?");
                    $stmt->execute([$id]);
                    
                    $insertStmt = $pdo->prepare("
                        INSERT INTO flash_supervisors (flash_id, user_id, assigned_at)
                        VALUES (?, ?, NOW())
                    ");
                    foreach ($approverIds as $approverId) {
                        $insertStmt->execute([$id, (int)$approverId]);
                    }
                }

                // Log state change
                try {
                    require_once __DIR__ . '/../includes/log.php';

                    $logStatus = "log_state_changed: {$oldState} → {$newState}";
                    sf_log_event($newId, 'state_changed', $logStatus);
                    
                    // Log submission comment if provided (not batched – comments are always separate)
                    if ($submissionComment !== '') {
                        sf_log_event($newId, 'submission_comment', $submissionComment);
                    }
                } catch (Throwable $e) {
                    error_log('save_flash: Lokitus epäonnistui (resubmission): ' . $e->getMessage());
                }

            } else {
                // Inline edit rule: preserve existing state (no routing/emails)
                $newState = $oldState;
            }
            
            // Handle extra images for EDIT path
            sf_handle_extra_images($post, $newId, $pdo);
            sf_handle_extra_videos($post, $newId, $pdo);
            
        } catch (PermissionException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
            sf_json_response(['ok' => false, 'error' => $e->getMessage()], 403);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
            error_log('save_flash: UPDATE failed: ' . $e->getMessage());
            sf_json_response(['ok' => false, 'error' => sf_term('error_save_server', $currentUiLang)], 500);
            exit;
        }

    // =========================================================================
    // TUTKINTATIEDOTE: Päivitä alkuperäinen safetyflash
    // =========================================================================
    } elseif ($isInvestigationUpdate) {
        $origStmt = $pdo->prepare("SELECT * FROM sf_flashes WHERE id = :id LIMIT 1");
        $origStmt->execute([':id' => $relatedFlashId]);
        $origFlash = $origStmt->fetch(PDO::FETCH_ASSOC);

        if (!$origFlash) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
            sf_json_response(['ok' => false, 'error' => sf_term('error_original_flash_not_found', $currentUiLang)], 404);
            exit;
        }

        $oldType = (string) ($origFlash['type'] ?? '');
        $oldState = (string) ($origFlash['state'] ?? '');
        $translationGroupId = !empty($origFlash['translation_group_id']) 
            ? (int) $origFlash['translation_group_id'] 
            : $relatedFlashId;

        // 1. Arkistoi alkuperäinen sisältö lokiin
        try {
            require_once __DIR__ . '/../includes/log.php';
            
            $originalData = [
                'id' => $origFlash['id'],
                'type' => $origFlash['type'],
                'title' => $origFlash['title'],
                'title_short' => $origFlash['title_short'],
                'description' => $origFlash['description'],
                'state' => $origFlash['state'],
                'lang' => $origFlash['lang'],
                'created_at' => $origFlash['created_at'],
                'updated_at' => $origFlash['updated_at'],
            ];
            
            $archiveDesc = 'log_original_archived|data:' . json_encode($originalData, JSON_UNESCAPED_UNICODE);
            sf_log_event($translationGroupId, 'original_archived', $archiveDesc);
        } catch (Throwable $e) {
            error_log('save_flash: Alkuperäisen arkistointi epäonnistui: ' . $e->getMessage());
        }

        // 2. Arkistoi ja poista kieliversiot
        try {
            $transStmt = $pdo->prepare("
                SELECT id, lang, title, title_short, description, state, created_at, updated_at
                FROM sf_flashes 
                WHERE translation_group_id = :group_id AND id != :id
            ");
            $transStmt->execute([':group_id' => $translationGroupId, ':id' => $translationGroupId]);
            $translations = $transStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($translations)) {
                $archiveData = json_encode($translations, JSON_UNESCAPED_UNICODE);
                $archiveDesc = 'log_translations_archived|count:' . count($translations) . '|data:' . $archiveData;
                sf_log_event($translationGroupId, 'translations_archived', $archiveDesc);
                
                // Poista kieliversiot
                $deleteStmt = $pdo->prepare("
                    DELETE FROM sf_flashes 
                    WHERE translation_group_id = :group_id AND id != :id
                ");
                $deleteStmt->execute([':group_id' => $translationGroupId, ':id' => $translationGroupId]);
            }
        } catch (Throwable $e) {
            error_log('save_flash: Kieliversioiden arkistointi epäonnistui: ' . $e->getMessage());
        }

        // 3. Päivitä alkuperäinen -> tutkintatiedote (SAMA ID!)
        // Store original type if not already set
        $originalTypeValue = $origFlash['original_type'] ?? null;
        if ($originalTypeValue === null && $oldType !== 'green') {
            $originalTypeValue = $oldType;
        }

        // Snapshot the current preview so Xibo displays remain uninterrupted during the
        // investigation workflow (the flash leaves 'published' state but must stay visible).
        $snapshotPreview = null;
        $snapshotActive = 0;
        if ($oldState === 'published' && !empty($origFlash['preview_filename'])) {
            $snapshotPreview = $origFlash['preview_filename'];
            $snapshotActive = 1;
        }

        $sql = "UPDATE sf_flashes SET
            type = 'green',
            original_type = :original_type,
            title = :title,
            title_short = :title_short,
            summary = :summary,
            description = :description,
            root_causes = :root_causes,
            actions = :actions,
            state = :state,
            processing_status = 'pending',
            is_processing = 1,
            preview_status = 'pending',
            preview_filename = NULL,
            preview_filename_2 = NULL,
            display_snapshot_preview = :display_snapshot_preview,
            display_snapshot_active = :display_snapshot_active,
            font_size_override = :font_size_override,
            layout_mode = :layout_mode,
            updated_at = NOW()
            WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':original_type' => $originalTypeValue,
            ':title'       => $title,
            ':title_short' => $titleShort,
            ':summary'     => $summary,
            ':description' => trim((string) ($post['description'] ?? '')),
            ':root_causes' => trim((string) ($post['root_causes'] ?? '')),
            ':actions'     => trim((string) ($post['actions'] ?? '')),
            ':state'       => $newState,
            ':display_snapshot_preview' => $snapshotPreview,
            ':display_snapshot_active'  => $snapshotActive,
            ':font_size_override' => !empty($post['font_size_override']) ? trim((string) $post['font_size_override']) : null,
            ':layout_mode' => !empty($post['layout_mode']) ? trim((string) $post['layout_mode']) : 'auto',
            ':id'          => $relatedFlashId,
        ]);

        $newId = $relatedFlashId;

        // 4. Kirjaa lokiin tutkintatiedotteen luonti
        try {
            $batchId = sf_log_generate_batch_id();
            
            sf_log_event($relatedFlashId, 'investigation_created', 'log_investigation_created', $batchId);
            
            if ($oldState !== $newState) {
                $logStatus = "log_state_changed: {$oldState} → {$newState}";
                sf_log_event($relatedFlashId, 'state_changed', $logStatus, $batchId);
            }
            
            $logType = "type: {$oldType} → green";
            sf_log_event($relatedFlashId, 'type_changed', $logType, $batchId);
        } catch (Throwable $e) {
            error_log('save_flash: Lokitus epäonnistui (investigation): ' . $e->getMessage());
        }
        
        // Handle extra images for Investigation Update path
        sf_handle_extra_images($post, $newId, $pdo);
        sf_handle_extra_videos($post, $newId, $pdo);

    // =========================================================================
    // NORMAALI: Uuden luonti
    // =========================================================================
    } else {
        // Store grid_bitmap value for processing after INSERT
        $gridBitmapValue = trim((string) ($post['grid_bitmap'] ?? ''));

        // Determine original_type for standalone investigations (green type without a related flash)
        $standaloneOriginalType = null;
        $isStandaloneInvestigation = ($type === 'green' && $relatedFlashId === 0 && !empty($post['standalone_investigation']));
        if ($isStandaloneInvestigation) {
            $allowedOriginalTypes = ['red', 'yellow', 'green'];
            $rawStandaloneOriginalType = trim((string) ($post['standalone_original_type'] ?? ''));
            if (in_array($rawStandaloneOriginalType, $allowedOriginalTypes, true)) {
                $standaloneOriginalType = $rawStandaloneOriginalType;
            }
        }

        $sql = "INSERT INTO sf_flashes
            (title, title_short, summary, description, type, original_type, site, site_detail, occurred_at, lang, state, created_by,
             root_causes, actions, processing_status, is_processing, annotations_data, image1_transform, image2_transform, image3_transform, grid_layout, grid_bitmap,
             image1_caption, image2_caption, image3_caption,
             selected_approvers, preview_status, font_size_override, layout_mode, created_at, updated_at)
            VALUES
            (:title, :title_short, :summary, :description, :type, :original_type, :site, :site_detail, :occurred_at, :lang, :state, :created_by,
             :root_causes, :actions, 'pending', 1, :annotations_data, :image1_transform, :image2_transform, :image3_transform, :grid_layout, :grid_bitmap,
             :image1_caption, :image2_caption, :image3_caption,
               :selected_approvers, 'pending', :font_size_override, :layout_mode, :created_at, :updated_at)";
		
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':title'            => $title,
            ':title_short'      => $titleShort,
            ':summary'          => $summary,
            ':description'      => trim((string) ($post['description'] ?? '')),
            ':type'             => $type,
            ':original_type'    => $standaloneOriginalType,
            ':site'             => $site,
            ':site_detail'      => trim((string) ($post['site_detail'] ?? '')),
            ':occurred_at'      => $occurredAt,
            ':lang'             => trim((string) ($post['lang'] ?? 'fi')),
            ':state'            => $newState,
            ':created_by'       => $createdBy,
            ':root_causes'      => trim((string) ($post['root_causes'] ?? '')),
            ':actions'          => trim((string) ($post['actions'] ?? '')),
            ':annotations_data' => trim((string) ($post['annotations_data'] ?? '[]')),
            ':image1_transform' => trim((string) ($post['image1_transform'] ?? '')),
            ':image2_transform' => trim((string) ($post['image2_transform'] ?? '')),
            ':image3_transform' => trim((string) ($post['image3_transform'] ?? '')),
            ':grid_layout'      => trim((string) ($post['grid_layout'] ?? 'grid-1')),
            ':grid_bitmap'      => '',
            ':image1_caption'   => trim((string) ($post['image1_caption'] ?? '')),
            ':image2_caption'   => trim((string) ($post['image2_caption'] ?? '')),
            ':image3_caption'   => trim((string) ($post['image3_caption'] ?? '')),
            ':selected_approvers' => $hasSupervisors ? json_encode(array_map('intval', $approverIds)) : null,
            ':font_size_override' => !empty($post['font_size_override']) ? trim((string) $post['font_size_override']) : null,
            ':layout_mode' => !empty($post['layout_mode']) ? trim((string) $post['layout_mode']) : 'auto',
            ':created_at' => $createdAtValue ?? date('Y-m-d H:i:s'),
            ':updated_at' => $createdAtValue ?? date('Y-m-d H:i:s'),
        ]);

        $newId = (int) $pdo->lastInsertId();
        
        // Process grid_bitmap now that we have the real flash ID
        // Note: Two-step approach (INSERT then UPDATE) is required because:
        // 1. The filename needs the flash ID for uniqueness
        // 2. We must get the ID from lastInsertId() after INSERT
        // 3. This avoids orphaned files if INSERT fails
        if ($gridBitmapValue !== '') {
            $gridBitmapFilename = '';

            // Check if it's a pre-uploaded temp file (temp_grid_ prefix)
            if (strncmp($gridBitmapValue, 'temp_grid_', 10) === 0) {
                // Move the temp file to permanent storage in uploads/grids/
                $tempFilename = basename($gridBitmapValue);
                $tempPath     = __DIR__ . '/../../uploads/temp/' . $tempFilename;
                $gridsDir     = __DIR__ . '/../../uploads/grids/';

                if (!is_dir($gridsDir)) {
                    @mkdir($gridsDir, 0755, true);
                }

                if (is_file($tempPath)) {
                    // Determine extension from the temp filename
                    $tmpExt = strtolower(pathinfo($tempFilename, PATHINFO_EXTENSION));
                    $allowedExts = ['png', 'jpg', 'gif', 'webp'];
                    if (!in_array($tmpExt, $allowedExts, true)) {
                        $tmpExt = 'png';
                    }
                    $permanentFilename = 'grid_' . $newId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $tmpExt;
                    $permanentPath     = $gridsDir . $permanentFilename;

                    if (@rename($tempPath, $permanentPath)) {
                        @chmod($permanentPath, 0644);
                        $gridBitmapFilename = $permanentFilename;
                        error_log("save_flash: Moved temp grid bitmap to permanent for flash {$newId}: {$permanentFilename}");
                    } else {
                        // rename() failed (e.g. cross-device) — try copy+delete
                        if (@copy($tempPath, $permanentPath)) {
                            @chmod($permanentPath, 0644);
                            @unlink($tempPath);
                            $gridBitmapFilename = $permanentFilename;
                            error_log("save_flash: Copied temp grid bitmap to permanent for flash {$newId}: {$permanentFilename}");
                        } else {
                            error_log("save_flash: Failed to move/copy temp grid bitmap for flash {$newId}: {$tempPath}");
                        }
                    }
                } else {
                    error_log("save_flash: Temp grid bitmap file not found for flash {$newId}: {$tempPath}");
                }
            } else {
                // Base64 data URL or existing filename — use existing helper (backward compat)
                $gridBitmapFilename = sf_save_grid_bitmap_to_file($gridBitmapValue, $newId);
            }

            // Only update if save was successful (non-empty filename returned)
            if ($gridBitmapFilename !== '') {
                $updateGridStmt = $pdo->prepare("UPDATE sf_flashes SET grid_bitmap = :grid_bitmap WHERE id = :id");
                $updateGridStmt->execute([':grid_bitmap' => $gridBitmapFilename, ':id' => $newId]);
                // Update post data so worker job receives the permanent grid bitmap filename
                $post['grid_bitmap'] = $gridBitmapFilename;
            } else {
                error_log("save_flash: Failed to save grid bitmap for flash {$newId}");
            }
        }

        // Insert selected approvers into flash_supervisors table for display in view page
        if ($hasSupervisors && $newState === 'pending_supervisor') {
            $insertStmt = $pdo->prepare("
                INSERT INTO flash_supervisors (flash_id, user_id, assigned_at)
                VALUES (?, ?, NOW())
            ");
            foreach ($approverIds as $approverId) {
                $insertStmt->execute([$newId, (int)$approverId]);
            }
        }

        try {
            require_once __DIR__ . '/../includes/log.php';
            $batchId = sf_log_generate_batch_id();
            sf_log_event($newId, 'created', 'flash_create', $batchId);
            
            // Log initial state for new flashes – use pipe format for single status
            if ($newState !== '') {
                $logStatus = "log_state_changed|status:{$newState}";
                sf_log_event($newId, 'state_changed', $logStatus, $batchId);
            }

            if ($adminImportLogText !== '') {
                sf_log_event($newId, 'admin_import', $adminImportLogText, $batchId);
            }
            
            // Log submission comment if provided (not batched – comments are always separate)
            if ($submissionComment !== '' && $submissionType === 'review') {
                sf_log_event($newId, 'submission_comment', $submissionComment);
            }
        } catch (Throwable $e) {
            error_log('save_flash: Lokitus epäonnistui (create): ' . $e->getMessage());
        }
        
        // Handle extra images for CREATE path
        sf_handle_extra_images($post, $newId, $pdo);
        sf_handle_extra_videos($post, $newId, $pdo);
    }

    // =========================================================================
    // TARKISTA JA KÄSITTELE TEMP-KUVAT (immediate upload)
    // =========================================================================
    $tempDir = __DIR__ . '/../../uploads/temp/';
    $imagesDir = __DIR__ . '/../../uploads/images/';
    
    if (!is_dir($imagesDir)) {
        @mkdir($imagesDir, 0755, true);
    }

    foreach ([1 => 'image_main', 2 => 'image_2', 3 => 'image_3'] as $slot => $dbColumn) {
        $tempFilename = trim((string)($post["temp_image{$slot}"] ?? ''));
        
        if ($tempFilename !== '' && strpos($tempFilename, 'temp_') === 0) {
            $tempPath = $tempDir . basename($tempFilename);
            
            if (is_file($tempPath)) {
                // Luo pysyvä tiedostonimi
                $ext = pathinfo($tempFilename, PATHINFO_EXTENSION) ?: 'jpg';
                $permanentFilename = 'img_' . $newId . '_' . $slot . '_' . time() . '.' . $ext;
                $permanentPath = $imagesDir . $permanentFilename;
                
                // Siirrä temp → pysyvä
                if (rename($tempPath, $permanentPath)) {
                    // Päivitä tietokantaan
                    $updateStmt = $pdo->prepare("UPDATE sf_flashes SET {$dbColumn} = :filename WHERE id = :id");
                    $updateStmt->execute([':filename' => $permanentFilename, ':id' => $newId]);
                }
            }
        }
    }

    // =========================================================================
    // KÄSITTELE KUVAPANKIN KUVAT (library images)
    // Jos käyttäjä valitsi kuvan kuvapankista (eikä ladannut tiedostoa),
    // tallennetaan kuvapankki-tiedostonimi suoraan tietokantaan.
    // =========================================================================
    $libraryDir = __DIR__ . '/../../uploads/library/';
    foreach ([1 => 'image_main', 2 => 'image_2', 3 => 'image_3'] as $slot => $dbColumn) {
        // Ohitetaan jos tähän slottiin tallennettiin jo ladattu (temp) kuva
        $tempFilename = trim((string)($post["temp_image{$slot}"] ?? ''));
        if ($tempFilename !== '' && strpos($tempFilename, 'temp_') === 0) {
            continue;
        }

        $libraryFilename = basename(trim((string)($post["library_image_{$slot}"] ?? '')));
        if ($libraryFilename === '' || strpos($libraryFilename, 'lib_') !== 0) {
            continue;
        }

        // Tarkista tiedoston olemassaolo kuvapankki-hakemistossa
        if (!is_file($libraryDir . $libraryFilename)) {
            continue;
        }

        // Tallenna kuvapankki-tiedostonimi tietokantaan (tiedosto pysyy library-hakemistossa)
        $updateStmt = $pdo->prepare("UPDATE sf_flashes SET {$dbColumn} = :filename WHERE id = :id");
        $updateStmt->execute([':filename' => $libraryFilename, ':id' => $newId]);
    }

    // Tallenna väliaikaiset tiedot (kuvat ja dataURLit) tietokantaan (sf_jobs)
    $tempDataDir = __DIR__ . '/../../uploads/processes/';
    if (!is_dir($tempDataDir)) {
        @mkdir($tempDataDir, 0755, true);
    }

    $jobData = ['post' => $post, 'files' => []];
    $jobData['post']['user_id'] = $_SESSION['user_id'] ?? null;

    foreach ($files as $key => $file) {
        if (isset($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $safeName = preg_replace('/[^A-Za-z0-9\._-]/', '_', (string) ($file['name'] ?? 'file'));
            $tmpPath  = $tempDataDir . $newId . '_' . $key . '_' . $safeName;

            if (move_uploaded_file($file['tmp_name'], $tmpPath)) {
                $jobData['files'][$key] = $file;
                $jobData['files'][$key]['tmp_name'] = $tmpPath;
            }
        }
    }

    $pdo->commit();

    // =========================================================================
    // LOUKKAANTUNEET KEHONOSAT (body parts) — tallennetaan pivot-tauluun
    // Ensitiedotteissa (type='red'), vaaratilannetiedotteissa (type='yellow')
    // ja tutkintatiedotteissa (type='green')
    // =========================================================================
    if (in_array($type, ['red', 'yellow', 'green'], true) && isset($post['injured_parts']) && is_array($post['injured_parts'])) {
        try {
            $pdo->beginTransaction();

            // Poista aiemmat valinnat
            $delStmt = $pdo->prepare("DELETE FROM incident_body_part WHERE incident_id = ?");
            $delStmt->execute([$newId]);

            if (!empty($post['injured_parts'])) {
                // Hae validit svg_id → id -parit yhdellä kyselyllä
                $placeholders = implode(',', array_fill(0, count($post['injured_parts']), '?'));
                $bpStmt = $pdo->prepare(
                    "SELECT id, svg_id FROM body_parts WHERE svg_id IN ({$placeholders})"
                );
                $bpStmt->execute(array_values($post['injured_parts']));
                $bpRows = $bpStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($bpRows)) {
                    $insertBpStmt = $pdo->prepare(
                        "INSERT IGNORE INTO incident_body_part (incident_id, body_part_id) VALUES (?, ?)"
                    );
                    foreach ($bpRows as $bpRow) {
                        $insertBpStmt->execute([$newId, (int)$bpRow['id']]);
                    }
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('save_flash: Ruumiinosien tallennus epäonnistui: ' . $e->getMessage());
            // Ei keskeytä — loukkaantumistietojen tallennus on toissijainen
        }
    }
    // EMAIL-ILMOITUKSET (COMMITIN JÄLKEEN)
    // Lähetetään VAIN kun tila muuttuu (SafetyFlash etenee seuraavalle käyttäjäryhmälle)
    // Pelkkä muokkaus (sama tila) EI triggeröi sähköpostia
    // Translation children: NO EMAILS, NO REVIEW REQUESTS
    // =========================================================================
    // Uusi SafetyFlash (ei oldState) tai tilamuutos → lähetä sähköposti
    $shouldSendEmail = !isset($oldState) || ($oldState !== $newState);

    // Skip emails for standalone translation saves (not bundle review submissions)
    if ($submissionType === 'translation') {
        $shouldSendEmail = false;
    }

    if ($shouldSendEmail && $newState === 'pending_supervisor' && $hasSupervisors) {
        try {
            require_once __DIR__ . '/../../assets/services/email_services.php';
            require_once __DIR__ . '/../services/ApprovalRouting.php';
            require_once __DIR__ . '/../services/PushNotificationService.php';
            
            $approvers = ApprovalRouting::getSelectedApprovers($pdo, $newId);
            $pushUserIds = [];

            foreach ($approvers as $approver) {
                sf_send_supervisor_notification($newId, $approver['email'], false, $submissionComment);

                if (!empty($approver['id'])) {
                    $pushUserIds[] = (int)$approver['id'];
                }
            }

            UserEventService::createEventsForUsers(
                $pdo,
                $pushUserIds,
                $newId,
                'pending_supervisor',
                'action_required',
                null,
                isset($currentUser['id']) ? (int)$currentUser['id'] : null
            );

            PushNotificationService::sendWorkflowToUsers(
                $pdo,
                $pushUserIds,
                $newId,
                'push_supervisor_title',
                'push_supervisor_body'
            );

            error_log("save_flash: Email sent to supervisors for flash {$newId} (state change: " . ($oldState ?? '(initial)') . " → {$newState})");
        } catch (Throwable $e) {
            error_log('save_flash: Email-lähetys epäonnistui: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // BUNDLE WORKFLOW: Update all sibling language versions in the same
    // translation group to pending_supervisor when one member is submitted.
    // Approver assignments are copied to all siblings so the supervisor sees
    // the full bundle.  Only ONE email is sent (above) for the whole group.
    // =========================================================================
    if ($newState === 'pending_supervisor' && $hasSupervisors) {
        try {
            // Resolve group ID for this flash
            $flashGroupRow = $pdo->prepare("SELECT translation_group_id FROM sf_flashes WHERE id = ?");
            $flashGroupRow->execute([$newId]);
            $flashGroupData = $flashGroupRow->fetch(PDO::FETCH_ASSOC);
            $bundleGroupId = !empty($flashGroupData['translation_group_id'])
                ? (int)$flashGroupData['translation_group_id']
                : null;

            if ($bundleGroupId !== null) {
                // Update all other group members that are still in a submittable state
                $updateSiblingsStmt = $pdo->prepare("
                    UPDATE sf_flashes
                    SET state = 'pending_supervisor', updated_at = NOW()
                    WHERE (id = :gid OR translation_group_id = :gid2)
                      AND id != :current_id
                      AND state IN ('draft', 'request_info', '')
                ");
                $updateSiblingsStmt->execute([
                    ':gid'        => $bundleGroupId,
                    ':gid2'       => $bundleGroupId,
                    ':current_id' => $newId,
                ]);

                // Copy approver assignments to sibling flashes
                $siblingsStmt = $pdo->prepare("
                    SELECT id FROM sf_flashes
                    WHERE (id = :gid OR translation_group_id = :gid2)
                      AND id != :current_id
                ");
                $siblingsStmt->execute([
                    ':gid'        => $bundleGroupId,
                    ':gid2'       => $bundleGroupId,
                    ':current_id' => $newId,
                ]);
                $siblingIds = $siblingsStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($siblingIds)) {
                    $delSiblingApprovers = $pdo->prepare(
                        "DELETE FROM flash_supervisors WHERE flash_id = ?"
                    );
                    $insSiblingApprovers = $pdo->prepare(
                        "INSERT INTO flash_supervisors (flash_id, user_id, assigned_at) VALUES (?, ?, NOW())"
                    );
                    foreach ($siblingIds as $sibId) {
                        $sibId = (int)$sibId;
                        $delSiblingApprovers->execute([$sibId]);
                        foreach ($approverIds as $approverId) {
                            $insSiblingApprovers->execute([$sibId, (int)$approverId]);
                        }
                    }
                    error_log("save_flash: Bundle group {$bundleGroupId} - updated " . count($siblingIds) . " sibling(s) to pending_supervisor");
                }
            }
        } catch (Throwable $e) {
            error_log('save_flash: Bundle group update failed: ' . $e->getMessage());
        }
    }
    // HUOM: Poistettu turha pending_review -sähköpostilogiikka
    // Työmaavastaavia on aina jokaisella työmaalla, joten draft → pending_review polkua ei ole
    // Turvatiimi saa sähköpostin vasta kun työmaavastaava hyväksyy (pending_supervisor → pending_review)
    // Tämä käsitellään tiedostossa: app/actions/supervisor_to_safety.php

    // =========================================================================
    // POISTA KÄYTTÄJÄN LUONNOKSET KUN LÄHETETÄÄN TARKISTUKSEEN
    // Luonnosten poisto EI tapahdu kun tallennetaan luonnosta (submission_type='draft')
    // (Transaktion ulkopuolella - varmistaa että poisto tapahtuu)
    // =========================================================================
    if ($submissionType === 'review' && $createdBy !== null && $createdBy > 0) {
        try {
            $deleteDraftStmt = $pdo->prepare("DELETE FROM sf_drafts WHERE user_id = ?");
            $deleteDraftStmt->execute([$createdBy]);
            error_log('save_flash: Deleted drafts for user_id=' . $createdBy);
        } catch (Throwable $e) {
            // Luonnosten poiston epäonnistuminen ei saa estää tallennusta
            error_log('save_flash: Draft deletion failed: ' . $e->getMessage());
        }
    }

    // Audit
    try {
        $action = ($id > 0) ? 'flash_updated' :  'flash_created';
        sf_audit_log(
            $action,
            'flash',
            $newId,
            ['title' => $title, 'state' => $newState, 'type' => $type, 'site' => $site],
            $createdBy
        );
    } catch (Throwable $e) {
        error_log('save_flash:  Audit-lokitus epäonnistui: ' . $e->getMessage());
    }


    // Insert job into sf_jobs table (after commit so a failure here never rolls back the flash save)
    try {
        require_once __DIR__ . '/../services/FlashImageService.php';
        FlashImageService::upsertJob($newId, $jobData);
    } catch (Throwable $e) {
        error_log("save_flash: Failed to create sf_jobs record for flash {$newId}: " . $e->getMessage());
    }

    // Respond immediately
    $useShell = sf_shell_exec_available();
    $payload  = ['ok' => true, 'flash_id' => $newId, 'bg_mode' => $useShell ? 'shell_exec' : 'inline'];

    sf_json_response($payload, 200);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    sf_finish_request();

    @ignore_user_abort(true);

    // Collect all flash IDs that need worker processing.
    // EDIT path: FlashSaveService::save() returns pending_worker_ids (main flash + siblings).
    // CREATE / INVESTIGATION paths: $pendingWorkerIds is not set; fall back to $newId.
    $workerIds = [];
    if (empty($pendingWorkerIds)) {
        $workerIds[] = (int)$newId;
    } else {
        foreach ($pendingWorkerIds as $wid) {
            $workerIds[] = (int)$wid;
        }
    }
    $workerIds = array_values(array_unique($workerIds));

    if ($useShell) {
        $workerScript  = __DIR__ . '/process_flash_worker.php';
        $phpExecutable = PHP_BINARY ?: 'php';

        foreach ($workerIds as $wid) {
            $command = escapeshellcmd($phpExecutable)
                . ' ' . escapeshellarg($workerScript)
                . ' ' . escapeshellarg((string)$wid)
                . ' > /dev/null 2>&1 &';
            @shell_exec($command);
        }
        exit;
    }

    @set_time_limit(300);
    if (!defined('SF_ALLOW_WEB_WORKER')) {
        define('SF_ALLOW_WEB_WORKER', true);
    }

    // Inline fallback: run one worker per ID. Transaction is already committed, so no locks.
    foreach ($workerIds as $wid) {
        $_GET['flash_id'] = (string)$wid;
        try {
            require __DIR__ . '/process_flash_worker.php';
        } catch (Throwable $e) {
            error_log("save_flash: Inline worker for flash {$wid} failed: " . $e->getMessage());
        }
    }
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = 'save_flash.php ERROR: ' . $e->getMessage();
    error_log($msg . "\n" . $e->getTraceAsString());

    if (function_exists('sf_app_log')) {
        sf_app_log($msg, LOG_LEVEL_ERROR, [
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'trace'       => $e->getTraceAsString(),
        ]);
    }
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    $resp = ['ok' => false, 'error' => sf_term('error_save_server', $currentUiLang)];
    if (!empty($config['debug'])) {
        $resp['debug'] = $e->getMessage();
    }

    sf_json_response($resp, 500);
    exit;
}