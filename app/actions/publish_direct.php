<?php
declare(strict_types=1);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/../includes/protect.php';
    require_once __DIR__ . '/../includes/log.php';
    require_once __DIR__ . '/../includes/log_app.php';
    require_once __DIR__ . '/../includes/statuses.php';
    require_once __DIR__ . '/../includes/audit_log.php';
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/../includes/file_cleanup.php';
    require_once __DIR__ . '/../../assets/lib/sf_terms.php';
    require_once __DIR__ . '/../services/UserEventService.php';

    $base = rtrim($config['base_url'] ?? '', '/');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header("Location: {$base}/index.php?page=list");
        exit;
    }

    sf_csrf_check();

    $id  = sf_validate_id();
    $pdo = sf_get_pdo();

    if ($id <= 0) {
        sf_redirect($base . "/index.php?page=list&notice=error");
    }

    $currentUser = sf_current_user();
    $userId = isset($currentUser['id']) ? (int)$currentUser['id'] : 0;
    $roleId = isset($currentUser['role_id']) ? (int)$currentUser['role_id'] : 0;
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

    $isAdmin = ($roleId === 1);
    $isSafety = ($roleId === 3);

    if (!$isAdmin && !$isSafety) {
        http_response_code(403);
        echo 'Ei oikeuksia.';
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, translation_group_id, title, state, lang, type
        FROM sf_flashes
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$flash) {
        sf_redirect($base . "/index.php?page=list&notice=error");
    }

    $oldState = (string)($flash['state'] ?? '');

    if (!in_array($oldState, ['pending_supervisor', 'pending_review'], true)) {
        sf_redirect($base . "/index.php?page=view&id={$id}&notice=error");
    }

    $message = trim((string)($_POST['message'] ?? ''));
    if ($message === '') {
        sf_redirect($base . "/index.php?page=view&id={$id}&notice=error");
    }
    $message = mb_substr($message, 0, 2000);

    $groupId = !empty($flash['translation_group_id'])
        ? (int)$flash['translation_group_id']
        : (int)$flash['id'];

    $logFlashId = $groupId;

    $stmtVersions = $pdo->prepare("
        SELECT id, lang
        FROM sf_flashes
        WHERE id = :gid OR translation_group_id = :gid2
        ORDER BY FIELD(lang, 'fi', 'sv', 'en', 'it', 'el')
    ");
    $stmtVersions->execute([
        ':gid' => $groupId,
        ':gid2' => $groupId,
    ]);
    $versions = $stmtVersions->fetchAll(PDO::FETCH_ASSOC);

    if (!$versions) {
        sf_redirect($base . "/index.php?page=view&id={$id}&notice=error");
    }

    $versionIds = array_map(static function (array $row): int {
        return (int)$row['id'];
    }, $versions);

    $placeholders = implode(',', array_fill(0, count($versionIds), '?'));

    $updateSql = "
        UPDATE sf_flashes
        SET state = 'published',
            status = 'JULKAISTU',
            published_at = COALESCE(published_at, NOW()),
            updated_at = NOW()
        WHERE id = ?
    ";
    $stmtUpdate = $pdo->prepare($updateSql);
    $stmtUpdate->execute([$id]);

    $clearSnapshotSql = "
        UPDATE sf_flashes
        SET display_snapshot_active = 0,
            display_snapshot_preview = NULL
        WHERE id = ?
    ";
    $stmtClearSnapshot = $pdo->prepare($clearSnapshotSql);
    $stmtClearSnapshot->execute([$id]);

    $stmtType = $pdo->prepare("
        SELECT type
        FROM sf_flashes
        WHERE id = ?
        LIMIT 1
    ");
    $stmtType->execute([$groupId]);
    $flashType = $stmtType->fetchColumn();

    $versionType = match ($flashType) {
        'red' => 'ensitiedote',
        'yellow' => 'vaaratilanne',
        'green' => 'tutkintatiedote',
        default => 'vaaratilanne',
    };

    $stmtPreview = $pdo->prepare("
        SELECT preview_filename
        FROM sf_flashes
        WHERE id = ?
        LIMIT 1
    ");
    $stmtPreview->execute([$groupId]);
    $previewFilename = $stmtPreview->fetchColumn();

    if ($previewFilename) {
        $previewFilename = basename((string)$previewFilename);
    }

    $baseDir = dirname(__DIR__, 2);
    $previewPath = $previewFilename ? $baseDir . '/uploads/previews/' . $previewFilename : null;

    if ($previewPath && file_exists($previewPath)) {
        $stmtExisting = $pdo->prepare("
            SELECT id, image_path
            FROM sf_flash_snapshots
            WHERE flash_id = ? AND version_type = ? AND lang = ?
            LIMIT 1
        ");
        $stmtExisting->execute([$groupId, $versionType, $flash['lang'] ?? 'fi']);
        $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $snapshotFullPath = $baseDir . $existing['image_path'];
            if (copy($previewPath, $snapshotFullPath)) {
                $stmtSnapshotUpdate = $pdo->prepare("
                    UPDATE sf_flash_snapshots
                    SET published_at = NOW(), published_by = ?
                    WHERE id = ?
                ");
                $stmtSnapshotUpdate->execute([$userId, $existing['id']]);
            }
        } else {
            $snapshotDir = $baseDir . '/storage/snapshots/' . $groupId;
            if (!is_dir($snapshotDir)) {
                mkdir($snapshotDir, 0755, true);
            }

            $timestamp = date('Y-m-d_His');
            $snapshotFilename = $versionType . '_' . $timestamp . '.jpg';
            $snapshotPath = $snapshotDir . '/' . $snapshotFilename;

            if (copy($previewPath, $snapshotPath)) {
                $relativeImagePath = '/storage/snapshots/' . $groupId . '/' . $snapshotFilename;

                $stmtCount = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM sf_flash_snapshots
                    WHERE flash_id = ?
                ");
                $stmtCount->execute([$groupId]);
                $versionNumber = (int)$stmtCount->fetchColumn() + 1;

                $stmtSnapshotInsert = $pdo->prepare("
                    INSERT INTO sf_flash_snapshots
                    (flash_id, version_type, lang, version_number, image_path, published_at, published_by)
                    VALUES (:flash_id, :version_type, :lang, :version_number, :image_path, NOW(), :published_by)
                ");
                $stmtSnapshotInsert->execute([
                    ':flash_id' => $groupId,
                    ':version_type' => $versionType,
                    ':lang' => $flash['lang'] ?? 'fi',
                    ':version_number' => $versionNumber,
                    ':image_path' => $relativeImagePath,
                    ':published_by' => $userId,
                ]);
            }
        }
    }

    $commentDescription = "log_comment_label: " . $message;
    $stmtComment = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (:flash_id, :user_id, :event_type, :description, NOW())
    ");
    $stmtDirectLog->execute([
        ':flash_id' => $logFlashId,
        ':user_id' => $userId,
        ':event_type' => 'published_direct',
        ':description' => 'log_published_direct',
        ':batch_id' => $publishDirectBatchId,
    ]);

    UserEventService::createPublishedParticipantEvents(
        $pdo,
        $logFlashId,
        $userId > 0 ? $userId : null
    );

    // Sisarversioiden siirto awaiting_publish-tilaan
    if ($oldState !== 'published') {
        $stmtSiblingUpdate = $pdo->prepare("
            UPDATE sf_flashes
            SET state  = 'awaiting_publish',
                status = 'ODOTTAA_JULKAISUA',
                updated_at = NOW()
            WHERE (id = :gid OR translation_group_id = :gid2)
              AND id != :current_id
              AND state NOT IN ('published', 'archived', 'awaiting_publish')
        ");
        $stmtSiblingUpdate->execute([
            ':gid'        => $groupId,
            ':gid2'       => $groupId,
            ':current_id' => $id,
        ]);

        // Sanity check: verify no sibling still has an empty/null state (catches silent ENUM truncation).
        $verifyStmt = $pdo->prepare("
            SELECT id, state FROM sf_flashes
            WHERE (id = :gid OR translation_group_id = :gid2)
              AND id != :current_id
              AND (state = '' OR state IS NULL)
        ");
        $verifyStmt->execute([':gid' => $groupId, ':gid2' => $groupId, ':current_id' => $id]);
        $broken = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($broken)) {
            sf_app_log('publish_direct.php: SIBLING STATE WRITE FAILED (silent ENUM truncation?) for ids=' .
                implode(',', array_column($broken, 'id')), LOG_LEVEL_ERROR);
        }
    }

    // Generoi yksi batch_id tälle julkaisuoperaatiolle (ei kommenteille)
    $publishDirectBatchId = sf_log_generate_batch_id();

    $stmtDirectLog = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
        VALUES (:flash_id, :user_id, :event_type, :description, :batch_id, NOW())
    ");
    $stmtDirectLog->execute([
        ':flash_id' => $logFlashId,
        ':user_id' => $userId,
        ':event_type' => 'published_direct',
        ':description' => 'log_published_direct',
        ':batch_id' => $publishDirectBatchId,
    ]);

    if ($oldState !== 'published') {
        $stateChangeDesc = "log_state_changed: {$oldState} → published";

        $stmtStateChange = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
            VALUES (:flash_id, :user_id, :event_type, :description, :batch_id, NOW())
        ");
        $stmtStateChange->execute([
            ':flash_id' => $logFlashId,
            ':user_id' => $userId,
            ':event_type' => 'state_changed',
            ':description' => $stateChangeDesc,
            ':batch_id' => $publishDirectBatchId,
        ]);
    }

    sf_audit_log(
        'flash_publish_direct',
        'flash',
        (int)$id,
        [
            'title' => $flash['title'] ?? null,
            'from_state' => $oldState,
            'to_state' => 'published',
            'translation_group_id' => $groupId,
            'message' => mb_substr($message, 0, 200),
        ],
        $userId ?: null
    );

    sf_app_log("publish_direct.php: Flash {$id} published directly by user {$userId}. Group {$groupId}.");

    sf_redirect($base . "/index.php?page=view&id={$id}&notice=published_direct");
} catch (Throwable $e) {
    if (function_exists('sf_app_log')) {
        sf_app_log('publish_direct.php FATAL ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_LEVEL_ERROR);
    } else {
        error_log('publish_direct.php FATAL ERROR: ' . $e->getMessage());
    }

    $base = '';
    if (isset($config['base_url'])) {
        $base = rtrim($config['base_url'], '/');
    }

    $id = $_GET['id'] ?? 0;

    if ($base !== '') {
        header("Location: {$base}/index.php?page=view&id={$id}&notice=error");
    } else {
        header("Location: /index.php?page=view&id={$id}&notice=error");
    }
    exit;
}

restore_error_handler();