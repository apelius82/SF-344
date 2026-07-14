<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ . '/../includes/statuses.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/audit_log.php';

global $config;
Database::setConfig($config['db'] ?? []);

function sf_merge_json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sf_merge_type_label(string $type, string $lang): string
{
    $map = [
        'red' => 'first_release',
        'yellow' => 'dangerous_situation',
        'green' => 'investigation_report',
    ];

    $termKey = $map[$type] ?? null;
    if ($termKey !== null && function_exists('sf_term')) {
        $label = sf_term($termKey, $lang);
        if (is_string($label) && $label !== '') {
            return $label;
        }
    }

    return $type;
}

function sf_merge_create_snapshot_from_original(PDO $pdo, array $originalFlash, int $targetFlashId, int $publishedBy): ?string
{
    $baseDir = dirname(__DIR__, 2);

    $candidateFiles = [];
    $previewFilename = trim((string)($originalFlash['preview_filename'] ?? ''));
    $displaySnapshotPreview = trim((string)($originalFlash['display_snapshot_preview'] ?? ''));
    $previewFilename2 = trim((string)($originalFlash['preview_filename_2'] ?? ''));

    if ($previewFilename !== '') {
        $candidateFiles[] = $previewFilename;
    }
    if ($displaySnapshotPreview !== '') {
        $candidateFiles[] = $displaySnapshotPreview;
    }
    if ($previewFilename2 !== '') {
        $candidateFiles[] = $previewFilename2;
    }

    $previewPath = null;
    foreach ($candidateFiles as $candidate) {
        $testPath = $baseDir . '/uploads/previews/' . basename($candidate);
        if (is_file($testPath)) {
            $previewPath = $testPath;
            break;
        }
    }

    if ($previewPath === null) {
        return null;
    }

    $versionTypeMap = [
        'red' => 'ensitiedote',
        'yellow' => 'vaaratilanne',
    ];

    $versionType = $versionTypeMap[(string)($originalFlash['type'] ?? '')] ?? null;
    if ($versionType === null) {
        return null;
    }

    $lang = (string)($originalFlash['lang'] ?? 'fi');

    $publishedAt = null;
    if (!empty($originalFlash['published_at'])) {
        $publishedAt = (string)$originalFlash['published_at'];
    } elseif (!empty($originalFlash['updated_at'])) {
        $publishedAt = (string)$originalFlash['updated_at'];
    } elseif (!empty($originalFlash['created_at'])) {
        $publishedAt = (string)$originalFlash['created_at'];
    } else {
        $publishedAt = date('Y-m-d H:i:s');
    }

    $stmtExisting = $pdo->prepare("
        SELECT id, image_path
        FROM sf_flash_snapshots
        WHERE flash_id = ? AND version_type = ? AND lang = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtExisting->execute([$targetFlashId, $versionType, $lang]);
    $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $existingPath = $baseDir . '/' . ltrim((string)$existing['image_path'], '/');
        if (is_dir(dirname($existingPath))) {
            @copy($previewPath, $existingPath);
        }
        $stmtUpdate = $pdo->prepare("
            UPDATE sf_flash_snapshots
            SET published_at = ?, published_by = ?
            WHERE id = ?
        ");
        $stmtUpdate->execute([$publishedAt, $publishedBy, (int)$existing['id']]);
        return (string)$existing['image_path'];
    }

    $snapshotDir = $baseDir . '/storage/snapshots/' . $targetFlashId;
    if (!is_dir($snapshotDir)) {
        @mkdir($snapshotDir, 0755, true);
    }

    $extension = strtolower((string)pathinfo($previewPath, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'jpg';
    }

    $timestamp = date('Y-m-d_His');
    $snapshotFilename = $versionType . '_' . $timestamp . '.' . $extension;
    $snapshotPath = $snapshotDir . '/' . $snapshotFilename;

    if (!@copy($previewPath, $snapshotPath)) {
        return null;
    }

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM sf_flash_snapshots WHERE flash_id = ?");
    $stmtCount->execute([$targetFlashId]);
    $versionNumber = (int)$stmtCount->fetchColumn() + 1;

    $relativePath = '/storage/snapshots/' . $targetFlashId . '/' . $snapshotFilename;

    $stmtInsert = $pdo->prepare("
        INSERT INTO sf_flash_snapshots
            (flash_id, version_type, lang, version_number, image_path, published_at, published_by)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtInsert->execute([
        $targetFlashId,
        $versionType,
        $lang,
        $versionNumber,
        $relativePath,
        $publishedAt,
        $publishedBy,
    ]);

    return $relativePath;
}

function sf_merge_user_can_access_original(PDO $pdo, int $flashId, int $userId, bool $isAdmin): bool
{
    if ($isAdmin) {
        return true;
    }

    $userIdJsonNumber = (string)$userId;
    $userIdJsonString = json_encode((string)$userId, JSON_UNESCAPED_UNICODE);

    $sql = "
        SELECT COUNT(*)
        FROM sf_flashes f
        WHERE f.id = ?
          AND (
                f.created_by = ?
                OR EXISTS (
                    SELECT 1
                    FROM safetyflash_logs sl
                    WHERE sl.flash_id = COALESCE(f.translation_group_id, f.id)
                      AND sl.user_id = ?
                )
                OR (
                    f.state = 'pending_supervisor'
                    AND f.selected_approvers IS NOT NULL
                    AND JSON_VALID(f.selected_approvers)
                    AND (
                        JSON_CONTAINS(f.selected_approvers, ?)
                        OR JSON_CONTAINS(f.selected_approvers, ?)
                        OR (
                            JSON_TYPE(JSON_EXTRACT(f.selected_approvers, '$.approver_ids')) = 'ARRAY'
                            AND (
                                JSON_CONTAINS(JSON_EXTRACT(f.selected_approvers, '$.approver_ids'), ?)
                                OR JSON_CONTAINS(JSON_EXTRACT(f.selected_approvers, '$.approver_ids'), ?)
                            )
                        )
                    )
                )
          )
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $flashId,
        $userId,
        $userId,
        $userIdJsonNumber,
        $userIdJsonString,
        $userIdJsonNumber,
        $userIdJsonString,
    ]);

    return ((int)$stmt->fetchColumn() > 0);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sf_merge_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

if (!sf_csrf_validate($_POST['csrf_token'] ?? '')) {
    sf_merge_json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
}

$user = sf_current_user();
$userId = (int)($user['id'] ?? 0);
$roleId = (int)($user['role_id'] ?? 0);
$isAdmin = ($roleId === 1);
$isSafety = ($roleId === 3);
$isComms = ($roleId === 4);

if ($userId <= 0) {
    sf_merge_json_response(['ok' => false, 'error' => 'Authentication required'], 401);
}

$investigationId = (int)($_POST['investigation_id'] ?? 0);
$originalId = (int)($_POST['original_flash_id'] ?? 0);

if ($investigationId <= 0 || $originalId <= 0 || $investigationId === $originalId) {
    sf_merge_json_response(['ok' => false, 'error' => 'Invalid merge parameters'], 400);
}

$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

try {
    $pdo = Database::getInstance();

    $stmtInvestigation = $pdo->prepare("
        SELECT *
        FROM sf_flashes
        WHERE id = ?
        LIMIT 1
    ");
    $stmtInvestigation->execute([$investigationId]);
    $investigation = $stmtInvestigation->fetch(PDO::FETCH_ASSOC);

    if (!$investigation) {
        sf_merge_json_response(['ok' => false, 'error' => 'Investigation report not found'], 404);
    }

    $isOwner = ((int)($investigation['created_by'] ?? 0) === $userId);
    if (!$isAdmin && !$isSafety && !$isComms && !$isOwner) {
        sf_merge_json_response(['ok' => false, 'error' => 'Permission denied'], 403);
    }

    if (($investigation['type'] ?? '') !== 'green') {
        sf_merge_json_response(['ok' => false, 'error' => 'Target flash must be an investigation report'], 400);
    }

    if (!empty($investigation['translation_group_id']) && (int)$investigation['translation_group_id'] !== (int)$investigation['id']) {
        sf_merge_json_response(['ok' => false, 'error' => 'Merge can only be done to the original investigation report'], 400);
    }

    if (!empty($investigation['is_archived'])) {
        sf_merge_json_response(['ok' => false, 'error' => 'Archived investigation report cannot be modified'], 400);
    }

    $hasLinkedOriginalFlash = false;

    try {
        $stmtHasLinkedOriginalFlash = $pdo->prepare("
            SELECT COUNT(*)
            FROM sf_flash_snapshots
            WHERE flash_id = ?
              AND version_type IN ('ensitiedote', 'vaaratilanne')
        ");
        $stmtHasLinkedOriginalFlash->execute([$investigationId]);
        $hasLinkedOriginalFlash = ((int)$stmtHasLinkedOriginalFlash->fetchColumn() > 0);
    } catch (Throwable $e) {
        error_log('merge_investigation_flash.php linked original check failed: ' . $e->getMessage());
        $hasLinkedOriginalFlash = false;
    }

    if ($hasLinkedOriginalFlash) {
        sf_merge_json_response(['ok' => false, 'error' => 'Original flash already linked'], 400);
    }

    $stmtOriginal = $pdo->prepare("
        SELECT *
        FROM sf_flashes
        WHERE id = ?
        LIMIT 1
    ");
    $stmtOriginal->execute([$originalId]);
    $original = $stmtOriginal->fetch(PDO::FETCH_ASSOC);

    if (!$original) {
        sf_merge_json_response(['ok' => false, 'error' => 'Original flash not found'], 404);
    }

    if (!in_array((string)($original['type'] ?? ''), ['red', 'yellow'], true)) {
        sf_merge_json_response(['ok' => false, 'error' => 'Only first release or dangerous situation can be merged'], 400);
    }

    if (!empty($original['is_archived'])) {
        sf_merge_json_response(['ok' => false, 'error' => 'Selected flash is already archived'], 400);
    }

    if (!sf_merge_user_can_access_original($pdo, $originalId, $userId, $isAdmin)) {
        sf_merge_json_response(['ok' => false, 'error' => 'No permission to merge selected flash'], 403);
    }

    $stmtOriginalTranslations = $pdo->prepare("
        SELECT COUNT(*)
        FROM sf_flashes
        WHERE translation_group_id = ?
          AND id <> ?
    ");
    $stmtOriginalTranslations->execute([$originalId, $originalId]);
    $originalTranslationCount = (int)$stmtOriginalTranslations->fetchColumn();

    if ($originalTranslationCount > 0) {
        sf_merge_json_response([
            'ok' => false,
            'error' => 'Selected flash has language versions and cannot be merged with this action'
        ], 400);
    }

    $pdo->beginTransaction();

    $logFlashId = !empty($investigation['translation_group_id'])
        ? (int)$investigation['translation_group_id']
        : (int)$investigation['id'];

    $originalLogFlashId = !empty($original['translation_group_id'])
        ? (int)$original['translation_group_id']
        : (int)$original['id'];

    $stmtMoveSnapshots = $pdo->prepare("
        UPDATE sf_flash_snapshots
        SET flash_id = ?
        WHERE flash_id = ?
    ");
    $stmtMoveSnapshots->execute([$logFlashId, $originalId]);

    sf_merge_create_snapshot_from_original($pdo, $original, $logFlashId, $userId);

    $copyImages = false;
    $investigationHasImages = !empty($investigation['image_main']) || !empty($investigation['image_2']) || !empty($investigation['image_3']);
    $originalHasImages = !empty($original['image_main']) || !empty($original['image_2']) || !empty($original['image_3']);

    if (!$investigationHasImages && $originalHasImages) {
        $copyImages = true;
    }

    $newTitleShort = trim((string)($investigation['title_short'] ?? '')) !== ''
        ? (string)$investigation['title_short']
        : (string)($original['title_short'] ?? '');

    $newSummary = trim((string)($investigation['summary'] ?? '')) !== ''
        ? (string)$investigation['summary']
        : (string)($original['summary'] ?? '');

    $newSite = trim((string)($investigation['site'] ?? '')) !== ''
        ? (string)$investigation['site']
        : (string)($original['site'] ?? '');

    $newSiteDetail = trim((string)($investigation['site_detail'] ?? '')) !== ''
        ? (string)$investigation['site_detail']
        : (string)($original['site_detail'] ?? '');

    $newOccurredAt = !empty($investigation['occurred_at'])
        ? (string)$investigation['occurred_at']
        : (string)($original['occurred_at'] ?? '');

    $originalDisplaySnapshot = trim((string)($original['display_snapshot_preview'] ?? ''));
    $originalPreviewFilename = trim((string)($original['preview_filename'] ?? ''));
    $snapshotPreviewForDisplay = null;

    if ($originalDisplaySnapshot !== '') {
        $snapshotPreviewForDisplay = $originalDisplaySnapshot;
    } elseif ($originalPreviewFilename !== '') {
        $snapshotPreviewForDisplay = $originalPreviewFilename;
    }

    $displaySnapshotActive = ((string)($original['state'] ?? '') === 'published' && $snapshotPreviewForDisplay !== null) ? 1 : 0;

    $stmtUpdateInvestigation = $pdo->prepare("
        UPDATE sf_flashes
        SET
            original_type = :original_type,
            title_short = :title_short,
            summary = :summary,
            site = :site,
            site_detail = :site_detail,
            occurred_at = :occurred_at,
            image_main = :image_main,
            image_2 = :image_2,
            image_3 = :image_3,
            image1_transform = :image1_transform,
            image2_transform = :image2_transform,
            image3_transform = :image3_transform,
            image1_caption = :image1_caption,
            image2_caption = :image2_caption,
            image3_caption = :image3_caption,
            annotations_data = :annotations_data,
            grid_layout = :grid_layout,
            grid_bitmap = :grid_bitmap,
            display_snapshot_preview = :display_snapshot_preview,
            display_snapshot_active = :display_snapshot_active,
            display_duration_seconds = :display_duration_seconds,
            display_expires_at = :display_expires_at,
            updated_at = NOW()
        WHERE id = :id
    ");

    $stmtUpdateInvestigation->execute([
        ':original_type' => (string)$original['type'],
        ':title_short' => $newTitleShort,
        ':summary' => $newSummary,
        ':site' => $newSite,
        ':site_detail' => $newSiteDetail,
        ':occurred_at' => $newOccurredAt !== '' ? $newOccurredAt : null,
        ':image_main' => $copyImages ? ($original['image_main'] ?? null) : ($investigation['image_main'] ?? null),
        ':image_2' => $copyImages ? ($original['image_2'] ?? null) : ($investigation['image_2'] ?? null),
        ':image_3' => $copyImages ? ($original['image_3'] ?? null) : ($investigation['image_3'] ?? null),
        ':image1_transform' => $copyImages ? ($original['image1_transform'] ?? null) : ($investigation['image1_transform'] ?? null),
        ':image2_transform' => $copyImages ? ($original['image2_transform'] ?? null) : ($investigation['image2_transform'] ?? null),
        ':image3_transform' => $copyImages ? ($original['image3_transform'] ?? null) : ($investigation['image3_transform'] ?? null),
        ':image1_caption' => $copyImages ? ($original['image1_caption'] ?? null) : ($investigation['image1_caption'] ?? null),
        ':image2_caption' => $copyImages ? ($original['image2_caption'] ?? null) : ($investigation['image2_caption'] ?? null),
        ':image3_caption' => $copyImages ? ($original['image3_caption'] ?? null) : ($investigation['image3_caption'] ?? null),
        ':annotations_data' => $copyImages ? ($original['annotations_data'] ?? null) : ($investigation['annotations_data'] ?? null),
        ':grid_layout' => $copyImages ? ($original['grid_layout'] ?? null) : ($investigation['grid_layout'] ?? null),
        ':grid_bitmap' => $copyImages ? ($original['grid_bitmap'] ?? null) : ($investigation['grid_bitmap'] ?? null),
        ':display_snapshot_preview' => $snapshotPreviewForDisplay,
        ':display_snapshot_active' => $displaySnapshotActive,
        ':display_duration_seconds' => $original['display_duration_seconds'] ?? null,
        ':display_expires_at' => $original['display_expires_at'] ?? null,
        ':id' => $investigationId,
    ]);

    $stmtMoveLogs = $pdo->prepare("
        UPDATE safetyflash_logs
        SET flash_id = ?
        WHERE flash_id = ?
    ");
    $stmtMoveLogs->execute([$logFlashId, $originalLogFlashId]);

    try {
        $stmtMoveDisplayTargets = $pdo->prepare("
            UPDATE sf_flash_display_targets
            SET flash_id = ?
            WHERE flash_id = ?
        ");
        $stmtMoveDisplayTargets->execute([$investigationId, $originalId]);
    } catch (Throwable $e) {
        error_log('merge_investigation_flash.php display targets move (original=' . $originalId . ' -> investigation=' . $investigationId . '): ' . $e->getMessage());
    }

    try {
        $stmtMoveAdditionalInfo = $pdo->prepare("
            UPDATE sf_flash_additional_info
            SET flash_id = ?
            WHERE flash_id = ?
        ");
        $stmtMoveAdditionalInfo->execute([$investigationId, $originalId]);
    } catch (Throwable $e) {
        error_log('merge_investigation_flash.php additional info move: ' . $e->getMessage());
    }

    try {
        $stmtMoveExtraImages = $pdo->prepare("
            UPDATE sf_flash_images
            SET flash_id = ?
            WHERE flash_id = ?
        ");
        $stmtMoveExtraImages->execute([$investigationId, $originalId]);
    } catch (Throwable $e) {
        error_log('merge_investigation_flash.php extra images move: ' . $e->getMessage());
    }

    try {
        $stmtCopyBodyParts = $pdo->prepare("
            INSERT IGNORE INTO incident_body_part (incident_id, body_part_id)
            SELECT ?, body_part_id
            FROM incident_body_part
            WHERE incident_id = ?
        ");
        $stmtCopyBodyParts->execute([$investigationId, $originalId]);

        $stmtDeleteBodyParts = $pdo->prepare("
            DELETE FROM incident_body_part
            WHERE incident_id = ?
        ");
        $stmtDeleteBodyParts->execute([$originalId]);
    } catch (Throwable $e) {
        error_log('merge_investigation_flash.php body parts move: ' . $e->getMessage());
    }

    try {
        $stmtMoveSubscriptionsInsert = $pdo->prepare("
            INSERT INTO sf_comment_subscriptions (flash_id, user_id, unsubscribe_token, is_enabled, created_at, updated_at)
            SELECT ?, user_id, unsubscribe_token, is_enabled, created_at, updated_at
            FROM sf_comment_subscriptions
            WHERE flash_id = ?
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                unsubscribe_token = VALUES(unsubscribe_token),
                updated_at = NOW()
        ");
        $stmtMoveSubscriptionsInsert->execute([$logFlashId, $originalLogFlashId]);

        $stmtDeleteSubscriptions = $pdo->prepare("
            DELETE FROM sf_comment_subscriptions
            WHERE flash_id = ?
        ");
        $stmtDeleteSubscriptions->execute([$originalLogFlashId]);
    } catch (Throwable $e) {
        error_log('merge_investigation_flash.php subscriptions move: ' . $e->getMessage());
    }

    try {
        $stmtMoveReadsInsert = $pdo->prepare("
            INSERT INTO sf_flash_reads (flash_id, user_id, last_read_at)
            SELECT ?, user_id, last_read_at
            FROM sf_flash_reads
            WHERE flash_id = ?
            ON DUPLICATE KEY UPDATE
                last_read_at = GREATEST(sf_flash_reads.last_read_at, VALUES(last_read_at))
        ");
        $stmtMoveReadsInsert->execute([$logFlashId, $originalLogFlashId]);

        $stmtDeleteReads = $pdo->prepare("
            DELETE FROM sf_flash_reads
            WHERE flash_id = ?
        ");
        $stmtDeleteReads->execute([$originalLogFlashId]);
    } catch (Throwable $e) {
        error_log('merge_investigation_flash.php reads move: ' . $e->getMessage());
    }

    $originalTitle = trim((string)($original['title'] ?? ''));
    if ($originalTitle === '') {
        $originalTitle = trim((string)($original['title_short'] ?? ''));
    }
    if ($originalTitle === '') {
        $originalTitle = (string)(sf_merge_type_label((string)$original['type'], $currentUiLang));
    }

    $stateLabel = (string)(sf_status_label((string)($original['state'] ?? ''), $currentUiLang) ?? (string)($original['state'] ?? ''));
    $typeLabel = sf_merge_type_label((string)$original['type'], $currentUiLang);
    $userName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    if ($userName === '') {
        $userName = 'User #' . $userId;
    }

    $logTemplate = (string)(sf_term('log_original_flash_merged', $currentUiLang) ?? 'Original SafetyFlash "{title}" (ID {id}, type {type}, state {state}) merged into investigation report by {user}.');
    $logDescription = strtr($logTemplate, [
        '{title}' => $originalTitle,
        '{id}' => (string)$originalId,
        '{type}' => $typeLabel,
        '{state}' => $stateLabel,
        '{user}' => $userName,
    ]);

    sf_log_event($logFlashId, 'original_flash_merged', $logDescription);

    $stmtDeleteOriginal = $pdo->prepare("
        DELETE FROM sf_flashes
        WHERE id = ?
          AND type IN ('red', 'yellow')
        LIMIT 1
    ");
    $stmtDeleteOriginal->execute([$originalId]);

    sf_audit_log(
        'flash_merged_to_investigation',
        'flash',
        $investigationId,
        [
            'investigation_id' => $investigationId,
            'original_flash_id' => $originalId,
            'original_type' => $original['type'] ?? null,
            'original_state' => $original['state'] ?? null,
            'copied_images' => $copyImages ? 1 : 0,
        ],
        $userId
    );

    $pdo->commit();

    sf_merge_json_response([
        'ok' => true,
        'message' => (string)(sf_term('modal_merge_flash_success', $currentUiLang) ?? 'SafetyFlash linked successfully'),
        'redirect' => rtrim((string)($config['base_url'] ?? ''), '/') . '/index.php?page=view&id=' . $investigationId . '&tab=versions',
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('merge_investigation_flash.php: ' . $e->getMessage());
    sf_merge_json_response([
        'ok' => false,
        'error' => 'Server error'
    ], 500);
}