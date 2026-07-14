<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// Ladataan sovelluksen lokifunktio
require_once __DIR__ . '/../includes/log_app.php';

try {
    sf_app_log("[create_language_version] API called - starting");

    // Polut
    $configPath = __DIR__ . '/../../config.php';
    $authPath   = __DIR__ . '/../includes/auth.php';

    if (!file_exists($configPath)) {
        sf_app_log("[create_language_version] ERROR: config.php not found at: {$configPath}");
        echo json_encode(['success' => false, 'error' => 'Konfiguraatiotiedostoa ei loydy']);
        exit;
    }
    if (!file_exists($authPath)) {
        sf_app_log("[create_language_version] ERROR: auth.php not found at: {$authPath}");
        echo json_encode(['success' => false, 'error' => 'Auth-tiedostoa ei loydy']);
        exit;
    }

    require_once $configPath;
    require_once $authPath;
    require_once __DIR__ . '/../services/FlashPermissionService.php';
    require_once __DIR__ . '/../../assets/lib/sf_terms.php';

    sf_app_log("[create_language_version] Config and auth loaded");

    if (!isset($config)) {
        sf_app_log("[create_language_version] ERROR: \$config not defined");
        echo json_encode(['success' => false, 'error' => 'Konfiguraatio puuttuu']);
        exit;
    }

    if (!function_exists('sf_current_user')) {
        sf_app_log("[create_language_version] ERROR: sf_current_user function not found");
        echo json_encode(['success' => false, 'error' => 'Auth-funktio puuttuu']);
        exit;
    }

    $currentUser = sf_current_user();
    if (!$currentUser) {
        sf_app_log("[create_language_version] ERROR: User not logged in");
        echo json_encode(['success' => false, 'error' => 'Kirjautuminen vaaditaan']);
        exit;
    }
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

    sf_app_log("[create_language_version] User authenticated: ID=" . $currentUser['id']);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sf_app_log("[create_language_version] ERROR: Invalid method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
        echo json_encode(['success' => false, 'error' => 'Vain POST sallittu']);
        exit;
    }

    // Hae parametrit
    $sourceId         = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
    $targetLang       = isset($_POST['target_lang']) ? trim($_POST['target_lang']) : '';
    $titleShort       = isset($_POST['title_short']) ? trim($_POST['title_short']) : '';
    $description      = isset($_POST['description']) ? trim($_POST['description']) : '';
    $rootCauses       = isset($_POST['root_causes']) ? trim($_POST['root_causes']) : '';
    $actions          = isset($_POST['actions']) ? trim($_POST['actions']) : '';

    sf_app_log("[create_language_version] Params: source_id={$sourceId}, target_lang={$targetLang}");

    if ($sourceId <= 0) {
        sf_app_log("[create_language_version] ERROR: Invalid source_id: {$sourceId}");
        echo json_encode(['success' => false, 'error' => 'Virheellinen lahde-ID']);
        exit;
    }

    $allowedLangs = ['fi', 'sv', 'en', 'it', 'el'];
    if (!in_array($targetLang, $allowedLangs, true)) {
        sf_app_log("[create_language_version] ERROR: Invalid target_lang: {$targetLang}");
        echo json_encode(['success' => false, 'error' => 'Virheellinen kohdekieli']);
        exit;
    }

    if ($titleShort === '' || $description === '') {
        sf_app_log("[create_language_version] ERROR: Missing required fields");
        echo json_encode(['success' => false, 'error' => 'Otsikko ja kuvaus ovat pakollisia']);
        exit;
    }

    // Tietokantayhteys
    sf_app_log("[create_language_version] Connecting to database");
    $pdo = Database::getInstance();
    sf_app_log("[create_language_version] Database connected");

    // Hae lahde-flash
    $stmt = $pdo->prepare("SELECT * FROM sf_flashes WHERE id = ?");
    $stmt->execute([$sourceId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        sf_app_log("[create_language_version] ERROR: Source flash not found: {$sourceId}");
        echo json_encode(['success' => false, 'error' => 'Lahdetiedotetta ei loytynyt']);
        exit;
    }

    sf_app_log("[create_language_version] Source flash found: type=" . $source['type']);

    // Permission check via centralized role/state hierarchy
    $permissionService = new FlashPermissionService();
    if (!$permissionService->canEdit($currentUser, $source)) {
        sf_app_log("[create_language_version] ERROR: Permission denied for user " . $currentUser['id']);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => sf_term('error_no_edit_permission', $currentUiLang)]);
        exit;
    }

    // Translation group
    $groupId = !empty($source['translation_group_id'])
        ? (int)$source['translation_group_id']
        : (int)$source['id'];

    sf_app_log("[create_language_version] Translation group ID: {$groupId}");

    $manualImportStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM safetyflash_logs
        WHERE event_type = 'admin_import'
          AND flash_id IN (:source_id, :group_id)
    ");
    $manualImportStmt->execute([
        ':source_id' => (int)$source['id'],
        ':group_id' => (int)$groupId,
    ]);

    $isManualImportedFlash = ((int)$manualImportStmt->fetchColumn() > 0);

    $translationState = $isManualImportedFlash
        ? (string)($source['state'] ?? 'published')
        : 'draft';

    $translationCreatedAt = !empty($source['created_at'])
        ? (string)$source['created_at']
        : date('Y-m-d H:i:s');

    $translationUpdatedAt = !empty($source['updated_at'])
        ? (string)$source['updated_at']
        : $translationCreatedAt;

    // Tarkista ettei kieliversio jo ole olemassa
    $checkStmt = $pdo->prepare("
        SELECT id FROM sf_flashes 
        WHERE (translation_group_id = ? OR id = ?) AND lang = ?
    ");
    $checkStmt->execute([$groupId, $groupId, $targetLang]);
    if ($checkStmt->fetch()) {
        sf_app_log("[create_language_version] ERROR: Translation already exists for lang: {$targetLang}");
        echo json_encode(['success' => false, 'error' => 'Kieliversio on jo olemassa']);
        exit;
    }

    // Lisaa uusi kieliversio
    sf_app_log("[create_language_version] Inserting new translation");
    
    // --- SERVER-SIDE PREVIEW GENERATION ---
    require_once __DIR__ . '/../services/PreviewRenderer.php';
    
    $renderer = new PreviewRenderer();
    
    $previewData = [
        'type' => $source['type'],
        'lang' => $targetLang,
        'short_text' => $titleShort,
        'description' => $description,
        'site' => $source['site'],
        'site_detail' => $source['site_detail'] ?? '',
        'occurred_at' => $source['occurred_at'] ?? '',
        'root_causes' => $rootCauses,
        'actions' => $actions,
        'grid_bitmap' => $source['grid_bitmap'] ?? '',
        'card_number' => 'single',
    ];
    
    $previewFilename = null;
    $previewFilename2 = null;
    $previewsDir = __DIR__ . '/../../uploads/previews/';
    
    $date = $isManualImportedFlash
        ? date('Y_m_d', strtotime($translationCreatedAt))
        : date('Y_m_d');
    $siteSafe = preg_replace('/[^a-zA-Z0-9\-_]/', '', substr($source['site'] ?? '', 0, 30)) ?: 'Site';
    $titleSafe = preg_replace('/[^a-zA-Z0-9\-_]/', '', substr($titleShort, 0, 50)) ?: 'Flash';
    $langSafe = strtoupper($targetLang);
    $typeSafe = strtoupper($source['type']);
    
    $needsSecondCard = ($source['type'] === 'green' && $renderer->needsSecondCard($previewData));
    
    if ($needsSecondCard) {
        $previewData['card_number'] = '1';
    }
    
    // Card 1
    $card1Base64 = $renderer->render($previewData, 'final');
    if ($card1Base64) {
        $cardSuffix = ($source['type'] === 'green') ? '-1' : '';
        $previewFilename = "SF_{$date}_{$typeSafe}_{$siteSafe}-{$titleSafe}-{$langSafe}{$cardSuffix}.jpg";
        
        file_put_contents($previewsDir . $previewFilename, base64_decode($card1Base64));
        sf_app_log("[create_language_version] Card 1 saved: {$previewFilename}");
    }
    
    // Card 2 only when the same renderer logic says content really overflows card 1
    if ($needsSecondCard) {
        $previewData['card_number'] = '2';
        $card2Base64 = $renderer->render($previewData, 'final');
        if ($card2Base64) {
            $previewFilename2 = "SF_{$date}_{$typeSafe}_{$siteSafe}-{$titleSafe}-{$langSafe}-2.jpg";
            file_put_contents($previewsDir . $previewFilename2, base64_decode($card2Base64));
            sf_app_log("[create_language_version] Card 2 saved: {$previewFilename2}");
        }
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO sf_flashes (
            type, title, title_short, summary, description, 
            root_causes, actions, site, site_detail, occurred_at,
            related_flash_id,
            image_main, image_2, image_3,
            image1_transform, image2_transform, image3_transform,
            annotations_data, grid_bitmap,
            grid_style,
            lang, translation_group_id, state, preview_status,
            preview_filename, preview_filename_2,
            created_by, created_at, updated_at
        ) VALUES (
            :type, :title, :title_short, :summary, :description,
            :root_causes, :actions, :site, :site_detail, :occurred_at,
            :related_flash_id,
            :image_main, :image_2, :image_3,
            :image1_transform, :image2_transform, :image3_transform,
            :annotations_data, :grid_bitmap,
            :grid_style,
            :lang, :translation_group_id, :state, :preview_status,
            :preview_filename, :preview_filename_2,
            :created_by, :created_at, :updated_at
        )
    ");

    $insertStmt->execute([
        ':type'                 => $source['type'],
        ':title'                => $source['title'],
        ':title_short'          => $titleShort,
        ':summary'              => $titleShort,
        ':description'          => $description,
        ':root_causes'          => $rootCauses,
        ':actions'              => $actions,
        ':site'                 => $source['site'],
        ':site_detail'          => $source['site_detail'],
        ':occurred_at'          => $source['occurred_at'],
        ':related_flash_id'     => !empty($source['related_flash_id']) ? (int)$source['related_flash_id'] : null,
        ':image_main'           => $source['image_main'],
        ':image_2'              => $source['image_2'],
        ':image_3'              => $source['image_3'],
        ':image1_transform'     => $source['image1_transform'],
        ':image2_transform'     => $source['image2_transform'],
        ':image3_transform'     => $source['image3_transform'],
        ':annotations_data'     => $source['annotations_data'] ?? null,
        ':grid_bitmap'          => $source['grid_bitmap'] ?? null,
        ':grid_style'           => $source['grid_style'] ?? 'grid-3-main-top',
        ':lang'                 => $targetLang,
        ':translation_group_id' => $groupId,
        ':state'                => $translationState,
        ':preview_status'       => 'completed',
        ':preview_filename'     => $previewFilename,
        ':preview_filename_2'   => $previewFilename2,
        ':created_by'           => (int)$currentUser['id'],
        ':created_at'           => $translationCreatedAt,
        ':updated_at'           => $translationUpdatedAt,
    ]);

    $newId = (int)$pdo->lastInsertId();
    sf_app_log("[create_language_version] New translation created with ID: {$newId}");

    // Paivita alkuperaisen flashin translation_group_id jos tyhja
    if (empty($source['translation_group_id'])) {
        $updateStmt = $pdo->prepare("UPDATE sf_flashes SET translation_group_id = ? WHERE id = ?");
        $updateStmt->execute([$source['id'], $source['id']]);
        sf_app_log("[create_language_version] Updated source flash translation_group_id");
    }

    // Kirjaa tapahtuma myös safetyflash_logs-tauluun
    require_once __DIR__ . '/../includes/log.php';
    $logDesc = sf_term('log_translation_created', $currentUiLang) . ":  {$targetLang}";
    sf_log_event($newId, 'CREATED', $logDesc);

    $base = rtrim($config['base_url'] ?? '', '/');

    sf_app_log("[create_language_version] SUCCESS - Translation created, redirecting to view");

    echo json_encode([
        'success'  => true,
        'message'  => sf_term('notice_translation_created', $currentUiLang),
        'new_id'   => $newId,
        'redirect' => $base . '/index.php?page=view&id=' . $newId,
    ]);

} catch (PDOException $e) {
    sf_app_log(
        "[create_language_version] PDO ERROR: " .
        $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine()
    );
    echo json_encode([
        'success' => false,
        'error'   => 'Tietokantavirhe: ' . $e->getMessage(),
    ]);
} catch (Throwable $e) {
    sf_app_log(
        "[create_language_version] ERROR: " .
        $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine()
    );
    sf_app_log("[create_language_version] Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'error'   => 'Palvelinvirhe: ' . $e->getMessage(),
    ]);
}