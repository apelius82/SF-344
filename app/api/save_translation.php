<?php
// app/api/save_translation.php
declare(strict_types=1);

// Set error handler to convert warnings/notices to exceptions
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../includes/protect.php';
    require_once __DIR__ . '/../includes/statuses.php';
    require_once __DIR__ . '/../includes/log.php';
    require_once __DIR__ . '/../includes/log_app.php';
    require_once __DIR__ . '/../services/FlashPermissionService.php';
    require_once __DIR__ . '/../../assets/lib/sf_terms.php';
    require_once __DIR__ . '/../../assets/lib/Database.php';

    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

    // CSRF protection
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => sf_term('error_csrf_invalid', $currentUiLang),
        ]);
        exit;
    }

    // DB: PDO
    $pdo = Database::getInstance();

    // Read POST
    $fromId  = isset($_POST['from_id']) ? (int) $_POST['from_id'] : 0;
    $newLang = isset($_POST['lang']) ? trim((string) $_POST['lang']) : '';
    $groupId = isset($_POST['translation_group_id']) ? (int) $_POST['translation_group_id'] : 0;

    $title       = isset($_POST['title']) ? trim((string) $_POST['title']) : '';
    $titleShort  = isset($_POST['title_short']) ? trim((string) $_POST['title_short']) : '';
    $summary     = isset($_POST['summary']) ? trim((string) $_POST['summary']) : '';
    $description = isset($_POST['description']) ? trim((string) $_POST['description']) : '';
    $rootCausesPost = isset($_POST['root_causes']) ? trim((string) $_POST['root_causes']) : '';
    $actionsPost = isset($_POST['actions']) ? trim((string) $_POST['actions']) : '';

    if ($fromId <= 0 || $newLang === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => sf_term('error_missing_params', $currentUiLang),
        ]);
        exit;
    }

    // Fetch base flash
    $stmt = $pdo->prepare('SELECT * FROM sf_flashes WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $fromId]);
    $baseFlash = $stmt->fetch();

    if (!$baseFlash) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => sf_term('error_base_flash_not_found', $currentUiLang),
        ]);
        exit;
    }

    // Permission check via centralized role/state hierarchy
    $currentUser = sf_current_user();
    $permissionService = new FlashPermissionService();
    if (!$permissionService->canEdit($currentUser, $baseFlash)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => sf_term('error_no_edit_permission', $currentUiLang),
        ]);
        exit;
    }

    // Ensure translation_group_id
    if ($groupId <= 0) {
        $groupId = !empty($baseFlash['translation_group_id'])
            ? (int) $baseFlash['translation_group_id']
            : (int) $baseFlash['id'];
    }

    // DEBUG LOGGING - Track translation parent ID flow to prevent overwrites
    $debugMsg = "Translation save: from_id={$fromId}, baseFlash id=" . ($baseFlash['id'] ?? 'null') . 
                ", baseFlash translation_group_id=" . ($baseFlash['translation_group_id'] ?? 'null') . 
                ", groupId={$groupId}";
    
    if (function_exists('sf_app_log')) {
        sf_app_log($debugMsg, LOG_LEVEL_DEBUG);
    } else {
        error_log($debugMsg);
    }

    // Update base flash group id if missing
    if (empty($baseFlash['translation_group_id'])) {
        $u = $pdo->prepare('UPDATE sf_flashes SET translation_group_id = :gid WHERE id = :id');
        $u->execute([
            ':gid' => $groupId,
            ':id'  => (int) $baseFlash['id'],
        ]);
    }

    // Prepare row data
    $type       = $baseFlash['type'] ?? '';
    // Use POST title if provided, otherwise fall back to titleShort, then baseFlash title
    if ($title === '') {
        $title = $titleShort ?: ($baseFlash['title'] ?? '');
    }
    $site       = $baseFlash['site'] ?? '';
    $siteDetail = $baseFlash['site_detail'] ?? null;
    $occurredAt = $baseFlash['occurred_at'] ?? null;
    $imageMain  = $baseFlash['image_main'] ?? null;
    $image2     = $baseFlash['image_2'] ?? null;
    $image3     = $baseFlash['image_3'] ?? null;
    $preview    = $baseFlash['preview_filename'] ?? null;
    
    // Use POST values if provided, otherwise fallback to base flash
    $rootCauses = $rootCausesPost ?: ($baseFlash['root_causes'] ?? '');
    $actions = $actionsPost ?: ($baseFlash['actions'] ?? '');

    $annotationsData = $baseFlash['annotations_data'] ?? '{}';
    $image1Transform = $baseFlash['image1_transform'] ?? '';
    $image2Transform = $baseFlash['image2_transform'] ?? '';
    $image3Transform = $baseFlash['image3_transform'] ?? '';
    $gridLayout      = $baseFlash['grid_layout'] ?? 'grid-1';
    $gridBitmap      = $baseFlash['grid_bitmap'] ?? '';

    $state     = 'draft';
    $createdBy = $_SESSION['user_id'] ?? null;

    // --- SERVER-SIDE PREVIEW GENERATION ---
    require_once __DIR__ . '/../services/PreviewRenderer.php';
    
    $renderer = new PreviewRenderer();
    
    $previewData = [
        'type' => $type,
        'lang' => $newLang,
        'short_text' => $titleShort,
        'description' => $description,
        'site' => $site,
        'site_detail' => $siteDetail ?? '',
        'occurred_at' => $occurredAt ?? '',
        'root_causes' => $rootCauses,
        'actions' => $actions,
        'grid_bitmap' => $gridBitmap,
        'card_number' => 'single',
    ];
    
    $previewFilename = null;
    $previewFilename2 = null;
    $previewsDir = __DIR__ . '/../../uploads/previews/';
    
    $date = date('Y_m_d');
    $siteSafe = preg_replace('/[^a-zA-Z0-9\-_]/', '', substr($site, 0, 30)) ?: 'Site';
    $titleSafe = preg_replace('/[^a-zA-Z0-9\-_]/', '', substr($titleShort, 0, 50)) ?: 'Flash';
    $langSafe = strtoupper($newLang);
    $typeSafe = strtoupper($type);
    
    $needsSecondCard = ($type === 'green' && $renderer->needsSecondCard($previewData));
    
    if ($needsSecondCard) {
        $previewData['card_number'] = '1';
    }
    
    // Card 1
    $card1Base64 = $renderer->render($previewData, 'final');
    if ($card1Base64) {
        $cardSuffix = ($type === 'green') ? '-1' : '';
        $previewFilename = "SF_{$date}_{$typeSafe}_{$siteSafe}-{$titleSafe}-{$langSafe}{$cardSuffix}.jpg";
        
        file_put_contents($previewsDir . $previewFilename, base64_decode($card1Base64));
        error_log("save_translation.php: Preview saved: {$previewFilename}");
    }
    
    // Card 2 only when the same renderer logic says content really overflows card 1
    if ($needsSecondCard) {
        $previewData['card_number'] = '2';
        $card2Base64 = $renderer->render($previewData, 'final');
        if ($card2Base64) {
            $previewFilename2 = "SF_{$date}_{$typeSafe}_{$siteSafe}-{$titleSafe}-{$langSafe}-2.jpg";
            file_put_contents($previewsDir . $previewFilename2, base64_decode($card2Base64));
            error_log("save_translation.php: Preview 2 saved: {$previewFilename2}");
        }
    }

    // Insert new language version
    $ins = $pdo->prepare('
        INSERT INTO sf_flashes
        (translation_group_id, lang, type, title, title_short, summary, description,
         site, site_detail, occurred_at, state,
         image_main, image_2, image_3, preview_filename, preview_filename_2, preview_status,
         annotations_data, image1_transform, image2_transform, image3_transform,
         grid_layout, grid_bitmap, root_causes, actions, created_at, created_by)
        VALUES
        (:tgid, :lang, :type, :title, :title_short, :summary, :description,
         :site, :site_detail, :occurred_at, :state,
         :image_main, :image_2, :image_3, :preview_filename, :preview_filename_2, :preview_status,
         :annotations_data, :image1_transform, :image2_transform, :image3_transform,
         :grid_layout, :grid_bitmap, :root_causes, :actions, :created_at, :created_by)
    ');

    $ins->execute([
        ':tgid'               => $groupId,
        ':lang'               => $newLang,
        ':type'               => $type,
        ':title'              => $title,
        ':title_short'        => $titleShort,
        ':summary'            => $summary,
        ':description'        => $description,
        ':site'               => $site,
        ':site_detail'        => $siteDetail,
        ':occurred_at'        => $occurredAt,
        ':state'              => $state,
        ':image_main'         => $imageMain,
        ':image_2'            => $image2,
        ':image_3'            => $image3,
        ':preview_filename'   => $previewFilename,
        ':preview_filename_2' => $previewFilename2,
        ':preview_status'     => $previewFilename ? 'completed' : 'pending',
        ':annotations_data'   => $annotationsData,
        ':image1_transform'   => $image1Transform,
        ':image2_transform'   => $image2Transform,
        ':image3_transform'   => $image3Transform,
        ':grid_layout'        => $gridLayout,
        ':grid_bitmap'        => $gridBitmap,
        ':root_causes'        => $rootCauses,
        ':actions'            => $actions,
        ':created_at'         => $baseFlash['created_at'],
        ':created_by'         => $createdBy,
    ]);

    $newId = (int) $pdo->lastInsertId();

    // VALIDATION: Ensure new translation doesn't have same ID as parent
    if ($newId === $groupId) {
        $errorMsg = "Critical data integrity error: New translation got same ID as parent! newId={$newId}, groupId={$groupId}";
        if (function_exists('sf_app_log')) {
            sf_app_log($errorMsg, LOG_LEVEL_CRITICAL);
        } else {
            error_log($errorMsg);
        }
        throw new Exception("Data integrity error: Translation ID matches parent ID");
    }

    // VALIDATION: Verify the parent still exists after insert
    $checkStmt = $pdo->prepare('SELECT id FROM sf_flashes WHERE id = :id');
    $checkStmt->execute([':id' => $groupId]);
    if (!$checkStmt->fetch()) {
        $errorMsg = "Critical data integrity error: Parent flash {$groupId} no longer exists after translation insert!";
        if (function_exists('sf_app_log')) {
            sf_app_log($errorMsg, LOG_LEVEL_CRITICAL);
        } else {
            error_log($errorMsg);
        }
        throw new Exception("Data integrity error: Parent flash no longer exists");
    }

    if ($newId) {
        $logFlashId = (int) $groupId;
        $statusLabel = function_exists('sf_status_label') ? sf_status_label($state, $currentUiLang) : $state;

        $descTemplate = sf_term('log_translation_saved', $currentUiLang);
        $statusPrefix = sf_term('log_status_prefix', $currentUiLang);
        $desc = str_replace('{lang}', $newLang, $descTemplate) . ". {$statusPrefix}: {$statusLabel}.";

        if (function_exists('sf_log_event')) {
            sf_log_event($logFlashId, 'translation_saved', $desc);
        } else {
            $log = $pdo->prepare("
                INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
                VALUES (:flash_id, :user_id, :event_type, :description, NOW())
            ");
            $userId = $_SESSION['user_id'] ?? null;
            $log->execute([
                ':flash_id'   => $logFlashId,
                ':user_id'    => $userId,
                ':event_type' => 'translation_saved',
                ':description'=> $desc,
            ]);
        }

        $base = rtrim($config['base_url'] ?? '', '/');

        echo json_encode([
            'success'  => true,
            'message'  => sf_term('notice_translation_saved', $currentUiLang),
            'new_id'   => $newId,
            'redirect' => $base . '/index.php?page=view&id=' . $newId,
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => sf_term('error_translation_save_failed', $currentUiLang),
    ]);
    exit;

} catch (Throwable $e) {
    if (function_exists('sf_app_log')) {
        sf_app_log(
            'save_translation.php ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            LOG_LEVEL_ERROR
        );
    } else {
        error_log('save_translation.php ERROR: ' . $e->getMessage());
    }

    http_response_code(500);
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'debug'   => $e->getFile() . ':' . $e->getLine(),
    ]);
    exit;

} finally {
    restore_error_handler();
}