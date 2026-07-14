<?php
// app/api/bundle_add_language.php
declare(strict_types=1);

/**
 * Bundle workflow: create a new language version draft from an already-saved flash.
 *
 * Copies images, text, annotations_data and grid_bitmap from the source so the
 * translator sees the original markings as a starting reference.
 *
 * POST params:
 *   source_id   (int)    – ID of the already-saved source flash
 *   target_lang (string) – language code for the new version
 *
 * Returns JSON: { success: bool, redirect: string, new_id: int }
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/log_app.php';

try {
    sf_app_log('[bundle_add_language] API called');

    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../services/FlashPermissionService.php';
    require_once __DIR__ . '/../../assets/lib/sf_terms.php';

    if (!function_exists('sf_current_user')) {
        echo json_encode(['success' => false, 'error' => 'Auth-funktio puuttuu']);
        exit;
    }

    $currentUser = sf_current_user();
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Kirjautuminen vaaditaan']);
        exit;
    }
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Vain POST sallittu']);
        exit;
    }

    $sourceId   = isset($_POST['source_id'])   ? (int)$_POST['source_id']          : 0;
    $targetLang = isset($_POST['target_lang'])  ? trim($_POST['target_lang'])        : '';

    if ($sourceId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Virheellinen source_id']);
        exit;
    }

    $allowedLangs = ['fi', 'sv', 'en', 'it', 'el'];
    if (!in_array($targetLang, $allowedLangs, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Virheellinen kohdekieli']);
        exit;
    }

    $pdo = Database::getInstance();

    // Load source flash
    $stmt = $pdo->prepare('SELECT * FROM sf_flashes WHERE id = ? LIMIT 1');
    $stmt->execute([$sourceId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Lähde-flashia ei löydy']);
        exit;
    }

    // Permission check via centralized role/state hierarchy
    $permissionService = new FlashPermissionService();
    if (!$permissionService->canEdit($currentUser, $source)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => sf_term('error_no_edit_permission', $currentUiLang)]);
        exit;
    }

    // Resolve translation group
    $groupId = !empty($source['translation_group_id'])
        ? (int)$source['translation_group_id']
        : (int)$source['id'];

    // Check that a version for this language does not already exist in the group
    $checkStmt = $pdo->prepare('
        SELECT id FROM sf_flashes
        WHERE (translation_group_id = ? OR id = ?) AND lang = ?
    ');
    $checkStmt->execute([$groupId, $groupId, $targetLang]);
    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Kieliversio on jo olemassa']);
        exit;
    }

    // Create the new language-version draft.
    // Images, grid_bitmap and annotations_data are preserved from the source so the user
    // sees the original markings as a reference and can adjust them for the new language.
    $insertStmt = $pdo->prepare('
        INSERT INTO sf_flashes (
            type, title, title_short, summary, description,
            root_causes, actions, site, site_detail, occurred_at,
            related_flash_id,
            image_main, image_2, image_3,
            image1_transform, image2_transform, image3_transform,
            grid_style, grid_layout,
            annotations_data, grid_bitmap,
            lang, translation_group_id, state,
            created_by, created_at, updated_at
        ) VALUES (
            :type, :title, :title_short, :summary, :description,
            :root_causes, :actions, :site, :site_detail, :occurred_at,
            :related_flash_id,
            :image_main, :image_2, :image_3,
            :image1_transform, :image2_transform, :image3_transform,
            :grid_style, :grid_layout,
            :annotations_data, :grid_bitmap,
            :lang, :translation_group_id, :state,
            :created_by, NOW(), NOW()
        )
    ');

    $insertStmt->execute([
        ':type'                 => $source['type'],
        ':title'                => $source['title'],
        ':title_short'          => $source['title_short'],
        ':summary'              => $source['summary'],
        ':description'          => $source['description'],
        ':root_causes'          => $source['root_causes'],
        ':actions'              => $source['actions'],
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
        ':grid_style'           => $source['grid_style'] ?? 'grid-3-main-top',
        ':grid_layout'          => $source['grid_layout'] ?? null,
        ':annotations_data'     => $source['annotations_data'] ?? null,
        ':grid_bitmap'          => $source['grid_bitmap'] ?? null,
        ':lang'                 => $targetLang,
        ':translation_group_id' => $groupId,
        ':state'                => 'draft',
        ':created_by'           => (int)$currentUser['id'],
    ]);

    $newId = (int)$pdo->lastInsertId();

    // Ensure the source flash has its own translation_group_id set to itself
    if (empty($source['translation_group_id'])) {
        $pdo->prepare('UPDATE sf_flashes SET translation_group_id = ? WHERE id = ?')
            ->execute([$source['id'], $source['id']]);
    }

    // Log the event
    require_once __DIR__ . '/../includes/log.php';
    require_once __DIR__ . '/../includes/audit_log.php';
    sf_log_event($newId, 'CREATED', sf_term('log_bundle_language_created', $currentUiLang) . ': ' . $targetLang);

    sf_audit_log(
        'flash_language_version_created',
        'flash',
        $newId,
        [
            'source_id'   => $sourceId,
            'target_lang' => $targetLang,
            'group_id'    => $groupId,
        ]
    );

    sf_app_log("[bundle_add_language] Created new draft id={$newId} lang={$targetLang} group={$groupId}");

    $base = rtrim($config['base_url'] ?? '', '/');

    echo json_encode([
        'success'  => true,
        'new_id'   => $newId,
        'redirect' => $base . '/index.php?page=form&id=' . $newId . '&step=1',
    ]);

} catch (PDOException $e) {
    sf_app_log('[bundle_add_language] PDO ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Tietokantavirhe: ' . $e->getMessage()]);
} catch (Throwable $e) {
    sf_app_log('[bundle_add_language] ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Palvelinvirhe: ' . $e->getMessage()]);
}