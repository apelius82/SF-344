<?php
// assets/pages/view.php
declare(strict_types=1);

// Error display is disabled in production. Server logs handle PHP errors.

require_once __DIR__ . '/../../app/includes/protect.php';
require_once __DIR__ .'/../../app/includes/statuses.php';
require_once __DIR__ . '/../../app/actions/helpers.php';
require_once __DIR__ . '/../../app/services/FlashPermissionService.php';


$base = rtrim($config['base_url'] ?? '', '/');

// --- DB: PDO ---
try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    $errorLang = $_SESSION['ui_lang'] ?? 'fi';
    echo '<p>' . htmlspecialchars(sf_term('db_error', $errorLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// --- ID ---
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    $errorLang = $_SESSION['ui_lang'] ?? 'fi';
    echo '<p>' . htmlspecialchars(sf_term('invalid_id', $errorLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// --- Safetyflash ---
$stmt = $pdo->prepare("
    SELECT *,
        DATE_FORMAT(created_at, '%d.%m.%Y %H:%i')   AS createdFmt,
        DATE_FORMAT(updated_at, '%d.%m.%Y %H:%i')   AS updatedFmt,
        DATE_FORMAT(occurred_at, '%d.%m.%Y %H:%i')  AS occurredFmt
    FROM sf_flashes
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$flash = $stmt->fetch();

if (!$flash) {
    $errorLang = $_SESSION['ui_lang'] ?? 'fi';
    echo '<p>' . htmlspecialchars(sf_term('flash_not_found', $errorLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// Record user's last read timestamp
$currentUser = sf_current_user();
if ($currentUser && $flash) {
    // Use translation_group_id if available (marks all language versions as read)
    $flashId = !empty($flash['translation_group_id'])
        ? (int)$flash['translation_group_id']
        : (int)$flash['id'];
    $userId = (int)$currentUser['id'];
    
    // Upsert the last_read_at timestamp
    try {
        $readStmt = $pdo->prepare("
            INSERT INTO sf_flash_reads (flash_id, user_id, last_read_at)
            VALUES (:flash_id, :user_id, NOW())
            ON DUPLICATE KEY UPDATE last_read_at = NOW()
        ");
        $readStmt->execute([
            ':flash_id' => $flashId,
            ':user_id' => $userId
        ]);

        try {
            $eventReadStmt = $pdo->prepare("
                UPDATE sf_user_events
                SET is_read = 1,
                    read_at = NOW()
                WHERE user_id = :user_id
                  AND flash_id = :flash_id
                  AND is_read = 0
            ");
            $eventReadStmt->execute([
                ':user_id' => $userId,
                ':flash_id' => $flashId,
            ]);
        } catch (Throwable $eventReadError) {
            error_log('view.php: Failed to mark user events read: ' . $eventReadError->getMessage());
        }
    } catch (Throwable $e) {
        // Silently fail if table doesn't exist yet - migration might not be applied
        error_log('Failed to update flash read timestamp: ' . $e->getMessage());
    }
}

$uiLang          = $_SESSION['ui_lang'] ?? 'fi';
$currentUiLang   = $uiLang ?? 'fi';

// Load existing body parts for quick-edit
$existing_body_parts = [];
try {
    $bpStmt = $pdo->prepare("
        SELECT bp.svg_id
        FROM incident_body_part ibp
        JOIN body_parts bp ON bp.id = ibp.body_part_id
        WHERE ibp.incident_id = :id
        ORDER BY bp.sort_order
    ");
    $bpStmt->execute([':id' => $id]);
    $existing_body_parts = array_column($bpStmt->fetchAll(PDO::FETCH_ASSOC), 'svg_id');
} catch (Throwable $e) {
    error_log('view.php: Failed to load body parts: ' . $e->getMessage());
}

// Load additional info entries for this flash
$additionalInfoEntries = [];

/**
 * Sanitize HTML content from the additional info WYSIWYG editor.
 * Strips all disallowed tags and removes all attributes from allowed tags.
 * Allowed tags match the SAFE_TAGS list in the client-side JS.
 */
function sf_sanitize_ai_html(string $html): string {
    $allowed = '<p><br><strong><em><u><ol><ul><li><span>';
    $html = strip_tags($html, $allowed);
    // Remove all attributes from allowed tags; preserve self-closing slash (e.g. <br />)
    $html = preg_replace('/<(\w+)(?:\s[^>]*)?(\/?)>/', '<$1$2>', $html);
    return $html;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sf_flash_additional_info (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            flash_id   INT UNSIGNED NOT NULL,
            user_id    INT UNSIGNED NOT NULL,
            content    TEXT         NOT NULL,
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_flash_id (flash_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $aiStmt = $pdo->prepare("
        SELECT ai.id, ai.user_id, ai.content, ai.created_at,
               u.first_name, u.last_name
        FROM sf_flash_additional_info ai
        LEFT JOIN sf_users u ON u.id = ai.user_id
        WHERE ai.flash_id = ?
        ORDER BY ai.created_at ASC
    ");
    $aiStmt->execute([$id]);
    $additionalInfoEntries = $aiStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('view.php: Failed to load additional info: ' . $e->getMessage());
}

// --- Athena export table + status ---
$athenaExportRow = null;
$athenaExported = false;
$athenaExportOutdated = false;

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sf_flash_athena_exports (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            flash_id    INT UNSIGNED NOT NULL,
            user_id     INT UNSIGNED NOT NULL,
            exported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            source      ENUM('post_publish_modal','manual_download','marked_done') NOT NULL DEFAULT 'marked_done',
            KEY idx_flash (flash_id),
            KEY idx_exported_at (exported_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Fetch latest Athena export row (logFlashId not yet set, calculated below)
    $tmpLogFlashId = !empty($flash['translation_group_id'])
        ? (int)$flash['translation_group_id']
        : (int)$flash['id'];

    $athenaStmt = $pdo->prepare("
        SELECT ae.id, ae.exported_at, ae.source,
               u.first_name, u.last_name
        FROM sf_flash_athena_exports ae
        LEFT JOIN sf_users u ON u.id = ae.user_id
        WHERE ae.flash_id = ?
        ORDER BY ae.exported_at DESC
        LIMIT 1
    ");
    $athenaStmt->execute([$tmpLogFlashId]);
    $athenaExportRow = $athenaStmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($athenaExportRow['exported_at'])) {
        try {
            $athenaExportedAt = new DateTime((string)$athenaExportRow['exported_at']);
            $flashUpdatedAt = new DateTime((string)($flash['updated_at'] ?? $flash['created_at'] ?? 'now'));

            $athenaExported = $athenaExportedAt >= $flashUpdatedAt;
            $athenaExportOutdated = $athenaExportedAt < $flashUpdatedAt;
        } catch (Throwable $e) {
            $athenaExported = false;
            $athenaExportOutdated = true;
        }
    }
} catch (Throwable $e) {
    error_log('view.php: Athena export check error: ' . $e->getMessage());
}

// Check if user can manage reviewers (admin, safety team, or original creator)
$canManageReviewers = false;
if ($currentUser) {
    $userRoleId = (int)($currentUser['role_id'] ?? 0);
    $userId = (int)($currentUser['id'] ?? 0);
    $flashCreatorId = (int)($flash['created_by'] ?? 0);
    
    // Admin (1), Safety Team (3), or original creator
    $canManageReviewers = ($userRoleId === 1 || $userRoleId === 3 || $userId === $flashCreatorId);
}

// Lokia varten ryhmän juuri
$logFlashId = !empty($flash['translation_group_id'])
    ? (int)$flash['translation_group_id']
    : (int)$flash['id'];

// Current user's per-flash comment notification preference
$commentNotificationsChecked = true;
$currentUserIdForComments = (int)($currentUser['id'] ?? 0);

if ($currentUserIdForComments > 0) {
    try {
        $stmtCommentPref = $pdo->prepare("
            SELECT is_enabled
            FROM sf_comment_subscriptions
            WHERE flash_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmtCommentPref->execute([$logFlashId, $currentUserIdForComments]);
        $commentPrefRow = $stmtCommentPref->fetch(PDO::FETCH_ASSOC);

        if ($commentPrefRow !== false) {
            $commentNotificationsChecked = ((int)$commentPrefRow['is_enabled'] === 1);
        }
    } catch (Throwable $e) {
        $commentNotificationsChecked = true;
    }
}

// Varmista, että batch_id-sarake ja kommenttien tykkäystaulu ovat olemassa
try {
    $pdo->exec("ALTER TABLE safetyflash_logs ADD COLUMN IF NOT EXISTS batch_id VARCHAR(36) DEFAULT NULL");
    $pdo->exec("ALTER TABLE safetyflash_logs ADD INDEX IF NOT EXISTS idx_batch_id (batch_id)");
	$pdo->exec("ALTER TABLE safetyflash_logs ADD COLUMN IF NOT EXISTS workflow_order INT NOT NULL DEFAULT 100");
	$pdo->exec("ALTER TABLE safetyflash_logs ADD COLUMN IF NOT EXISTS flash_type_at_event VARCHAR(20) DEFAULT NULL");
	$pdo->exec("ALTER TABLE safetyflash_logs ADD INDEX IF NOT EXISTS idx_workflow_order (workflow_order)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sf_comment_likes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            comment_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_comment_user (comment_id, user_id),
            KEY idx_comment_id (comment_id),
            KEY idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    error_log('view.php: comment likes migration warning: ' . $e->getMessage());
}

// Hae lokit koko kieliryhmästä, jotta FI / EN / EL -julkaisut näkyvät omilla lipuillaan.
$logs = [];
$logStmt = $pdo->prepare("
    SELECT 
        l.id,
        l.flash_id AS log_flash_id,
        lf.lang AS log_lang,
	    COALESCE(l.flash_type_at_event, lf.type) AS log_flash_type,
		l.workflow_order,
        l.event_type,
        l.description,
        l.created_at,
        l.user_id,
        l.batch_id,
        u.first_name,
        u.last_name,
        (
            SELECT COUNT(*)
            FROM sf_comment_likes cl
            WHERE cl.comment_id = l.id
        ) AS like_count,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM sf_comment_likes cl2
                WHERE cl2.comment_id = l.id
                  AND cl2.user_id = ?
            )
            THEN 1
            ELSE 0
        END AS current_user_liked,
        COALESCE((
            SELECT GROUP_CONCAT(
                TRIM(CONCAT(COALESCE(lu.first_name, ''), ' ', COALESCE(lu.last_name, '')))
                ORDER BY cl_names.created_at ASC
                SEPARATOR ', '
            )
            FROM sf_comment_likes cl_names
            LEFT JOIN sf_users lu ON lu.id = cl_names.user_id
            WHERE cl_names.comment_id = l.id
        ), '') AS like_names
    FROM safetyflash_logs l
    LEFT JOIN sf_users u ON u.id = l.user_id
    LEFT JOIN sf_flashes lf ON lf.id = l.flash_id
    WHERE l.flash_id = ?
       OR lf.translation_group_id = ?
    ORDER BY l.created_at DESC, l.id DESC
");
$logStmt->execute([(int)($currentUser['id'] ?? 0), $logFlashId, $logFlashId]);
$logs = $logStmt->fetchAll();

// Fallback: jos lokitaulu on tyhjä, näytä vähintään luontiaika
if (empty($logs)) {
    $creatorName = trim(($flash['created_by_first_name'] ?? '') .' ' .($flash['created_by_last_name'] ?? ''));
    if ($creatorName === '') $creatorName = null;

    $logs = [[
        'id' => 0,
        'event_type' => 'created',
        'description' => sf_term('log_created', $currentUiLang) ?? 'Created',
        'created_at' => $flash['created_at'] ?? ($flash['createdFmtRaw'] ?? null),
        'first_name' => $creatorName ? ($flash['created_by_first_name'] ?? '') : null,
        'last_name'  => $creatorName ? ($flash['created_by_last_name'] ?? '') : null,
    ]];
}

// SafetyFlash-prosessin progress bar
if (!function_exists('sf_workflow_escape')) {
    function sf_workflow_escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sf_workflow_user_name')) {
    function sf_workflow_user_name(array $row): string
    {
        $firstName = trim((string)($row['first_name'] ?? ''));
        $lastName = trim((string)($row['last_name'] ?? ''));

        return trim($firstName . ' ' . $lastName);
    }
}

if (!function_exists('sf_workflow_format_datetime')) {
    function sf_workflow_format_datetime($value): string
    {
        if (empty($value)) {
            return '-';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return (string) $value;
        }

        return date('d.m.Y H:i', $timestamp);
    }
}

if (!function_exists('sf_workflow_type_label')) {
    function sf_workflow_type_label(string $type, string $lang): string
    {
        $map = [
            'red' => 'first_release',
            'yellow' => 'dangerous_situation',
            'green' => 'investigation_report',
        ];

        $termKey = $map[$type] ?? '';

        if ($termKey === '') {
            return $type;
        }

        $label = sf_term($termKey, $lang);

        return $label !== $termKey ? $label : $type;
    }
}

if (!function_exists('sf_workflow_find_first_event')) {
    function sf_workflow_find_first_event(array $logsAsc, array $eventTypes, array $descriptionNeedles = []): ?array
    {
        foreach ($logsAsc as $logRow) {
            $eventType = (string)($logRow['event_type'] ?? '');
            $description = (string)($logRow['description'] ?? '');

            if (in_array($eventType, $eventTypes, true)) {
                return $logRow;
            }

            foreach ($descriptionNeedles as $needle) {
                if ($needle !== '' && stripos($description, $needle) !== false) {
                    return $logRow;
                }
            }
        }

        return null;
    }
}

if (!function_exists('sf_workflow_find_last_event')) {
    function sf_workflow_find_last_event(array $logsAsc, array $eventTypes, array $descriptionNeedles = []): ?array
    {
        for ($i = count($logsAsc) - 1; $i >= 0; $i--) {
            $logRow = $logsAsc[$i];
            $eventType = (string)($logRow['event_type'] ?? '');
            $description = (string)($logRow['description'] ?? '');

            if (in_array($eventType, $eventTypes, true)) {
                return $logRow;
            }

            foreach ($descriptionNeedles as $needle) {
                if ($needle !== '' && stripos($description, $needle) !== false) {
                    return $logRow;
                }
            }
        }

        return null;
    }
}

if (!function_exists('sf_workflow_event_timestamp')) {
    function sf_workflow_event_timestamp(?array $event): int
    {
        if ($event === null || empty($event['created_at'])) {
            return 0;
        }

        $timestamp = strtotime((string)$event['created_at']);

        return $timestamp === false ? 0 : $timestamp;
    }
}

if (!function_exists('sf_workflow_find_published_event')) {
    function sf_workflow_find_published_event(array $logsAsc): ?array
    {
        foreach ($logsAsc as $logRow) {
            $eventType = (string)($logRow['event_type'] ?? '');
            $description = (string)($logRow['description'] ?? '');

            if (in_array($eventType, ['published', 'published_direct', 'direct_published'], true)) {
                return $logRow;
            }

            if (
                stripos($description, 'log_status_set: published') !== false
                || stripos($description, 'published_direct') !== false
                || stripos($description, 'direct_published') !== false
                || stripos($description, 'julkaistu suoraan') !== false
                || stripos($description, 'suoraan julkaistu') !== false
            ) {
                return $logRow;
            }

            if (
                $eventType === 'state_changed'
                && (
                    stripos($description, '→ published') !== false
                    || stripos($description, '-> published') !== false
                    || stripos($description, ' to published') !== false
                )
            ) {
                return $logRow;
            }
        }

        return null;
    }
}

if (!function_exists('sf_workflow_find_last_named_event')) {
    function sf_workflow_find_last_named_event(array $logsAsc): ?array
    {
        for ($i = count($logsAsc) - 1; $i >= 0; $i--) {
            $logRow = $logsAsc[$i];

            if (trim(sf_workflow_user_name($logRow)) !== '') {
                return $logRow;
            }
        }

        return null;
    }
}

if (!function_exists('sf_workflow_current_active_step')) {
    function sf_workflow_current_active_step(string $state): string
    {
        if ($state === 'pending_supervisor') {
            return 'supervisor';
        }

        if ($state === 'pending_review') {
            return 'safety';
        }

        if ($state === 'to_comms' || $state === 'awaiting_publish') {
            return 'comms';
        }

        if ($state === 'published') {
            return 'published';
        }

        return 'created';
    }
}

if (!function_exists('sf_workflow_build_steps')) {
    function sf_workflow_build_steps(array $logsAsc, array $flash, string $phaseKey, bool $isCurrentPhase, string $currentUiLang): array
    {
        $state = (string)($flash['state'] ?? 'draft');
        $activeStep = $isCurrentPhase ? sf_workflow_current_active_step($state) : '';

        $stepOrder = ['created', 'supervisor', 'safety', 'comms', 'published'];

        $createdEvent = null;

        if ($phaseKey === 'investigation') {
            $createdEvent = sf_workflow_find_first_event(
                $logsAsc,
                ['investigation_created'],
                ['log_investigation_created']
            );
        }

        if ($createdEvent === null) {
            $createdEvent = sf_workflow_find_first_event(
                $logsAsc,
                ['created'],
                ['log_created']
            );
        }

        $supervisorApprovedEvent = sf_workflow_find_last_event(
            $logsAsc,
            ['supervisor_approved'],
            ['log_supervisor_approved']
        );

        $sentToCommsEvent = sf_workflow_find_first_event(
            $logsAsc,
            ['sent_to_comms'],
            ['to_comms', 'awaiting_publish']
        );

        $publishedDirectEvent = sf_workflow_find_first_event(
            $logsAsc,
            ['published_direct'],
            ['published_direct']
        );

        $publishedEvent = sf_workflow_find_published_event($logsAsc);
        $publishedFallbackEvent = $publishedEvent !== null
            ? $publishedEvent
            : sf_workflow_find_last_named_event($logsAsc);

        $isPublished = $publishedEvent !== null || $state === 'published';

        $supervisorApprovedUser = $supervisorApprovedEvent !== null
            ? sf_workflow_user_name($supervisorApprovedEvent)
            : '';

        $hasProgressedPastSupervisor = $sentToCommsEvent !== null || $publishedEvent !== null || $isPublished;

        $isDirectPublished = $publishedDirectEvent !== null || (
            $isPublished
            && $supervisorApprovedEvent === null
            && $sentToCommsEvent === null
        );

        $isSupervisorSkipped = $isDirectPublished || (
            $hasProgressedPastSupervisor
            && ($supervisorApprovedEvent === null || trim($supervisorApprovedUser) === '')
        );

        $isSafetySkipped = $isDirectPublished || (
            $isPublished
            && $sentToCommsEvent === null
        );

        $isCommsSkipped = $isDirectPublished || (
            $isPublished
            && $sentToCommsEvent === null
        );

$returnedForCorrectionsEvent = sf_workflow_find_last_event(
    $logsAsc,
    ['request_info', 'info_requested', 'returned_for_corrections', 'returned_to_supervisor'],
    ['request_info', 'info_requested', 'pending_supervisor', 'palaut']
);

$supervisorStartEvent = $createdEvent;

if (
    $returnedForCorrectionsEvent !== null
    && sf_workflow_event_timestamp($returnedForCorrectionsEvent) > sf_workflow_event_timestamp($createdEvent)
) {
    $supervisorStartEvent = $returnedForCorrectionsEvent;
}

        $labels = [
            'created' => sf_term('workflow_step_created', $currentUiLang),
            'supervisor' => sf_term('workflow_step_supervisor', $currentUiLang),
            'safety' => sf_term('workflow_step_safety', $currentUiLang),
            'comms' => sf_term('workflow_step_comms', $currentUiLang),
            'published' => sf_term('workflow_step_published', $currentUiLang),
        ];

        $fallbackUser = trim((string)($flash['created_by_first_name'] ?? '') . ' ' . (string)($flash['created_by_last_name'] ?? ''));
        $fallbackCreatedAt = (string)($flash['created_at'] ?? '');

        $activeIndex = array_search($activeStep, $stepOrder, true);
        $activeIndex = $activeIndex === false ? 0 : (int)$activeIndex;

        $workflowTimeline = [
            'created' => [
                'start_event' => $createdEvent,
                'complete_event' => $createdEvent,
                'target' => sf_term('workflow_target_system', $currentUiLang),
                'start_fallback_date' => $fallbackCreatedAt,
                'start_fallback_user' => $fallbackUser,
                'complete_fallback_date' => $fallbackCreatedAt,
                'complete_fallback_user' => $fallbackUser,
            ],
            'supervisor' => [
                'start_event' => $supervisorStartEvent,
                'complete_event' => $supervisorApprovedEvent,
                'target' => sf_term('workflow_target_supervisor', $currentUiLang),
                'start_fallback_date' => $fallbackCreatedAt,
                'start_fallback_user' => $fallbackUser,
                'complete_fallback_date' => '',
                'complete_fallback_user' => '',
                'skipped' => $isSupervisorSkipped || $isDirectPublished,
            ],
            'safety' => [
                'start_event' => $isSupervisorSkipped ? $createdEvent : $supervisorApprovedEvent,
                'complete_event' => $sentToCommsEvent,
                'target' => sf_term('workflow_target_safety', $currentUiLang),
                'start_fallback_date' => '',
                'start_fallback_user' => '',
                'complete_fallback_date' => '',
                'complete_fallback_user' => '',
                'skipped' => $isSafetySkipped,
            ],
            'comms' => [
                'start_event' => $sentToCommsEvent,
                'complete_event' => $publishedEvent,
                'target' => sf_term('workflow_target_comms', $currentUiLang),
                'start_fallback_date' => '',
                'start_fallback_user' => '',
                'complete_fallback_date' => '',
                'complete_fallback_user' => '',
                'skipped' => $isCommsSkipped,
            ],
            'published' => [
                'start_event' => $publishedFallbackEvent,
                'complete_event' => $publishedFallbackEvent,
                'target' => sf_term('workflow_target_published', $currentUiLang),
                'start_fallback_date' => (string)($flash['published_at'] ?? $flash['updated_at'] ?? ''),
                'start_fallback_user' => $fallbackUser,
                'complete_fallback_date' => (string)($flash['published_at'] ?? $flash['updated_at'] ?? ''),
                'complete_fallback_user' => $fallbackUser,
            ],
        ];

        $steps = [];

        foreach ($stepOrder as $index => $stepKey) {
            $stepData = $workflowTimeline[$stepKey];
            $startEvent = $stepData['start_event'];
            $completeEvent = $stepData['complete_event'];

            $stepState = !empty($stepData['skipped'])
                ? 'skipped'
                : ($completeEvent !== null ? 'done' : 'pending');

            if ($isCurrentPhase && $state !== 'published') {
                if ($index < $activeIndex) {
                    $stepState = 'done';
                } elseif ($index === $activeIndex) {
                    $stepState = 'active';
                } else {
                    $stepState = 'pending';
                    $startEvent = null;
                    $completeEvent = null;
                }
            }

            if (
                $startEvent !== null
                && $completeEvent !== null
                && sf_workflow_event_timestamp($completeEvent) < sf_workflow_event_timestamp($startEvent)
            ) {
                $completeEvent = null;
            }

            if (
                $isCurrentPhase
                && $state === 'published'
                && in_array($stepKey, ['supervisor', 'safety', 'comms'], true)
                && $completeEvent === null
            ) {
                $stepState = 'skipped';
            }

            if ($isCurrentPhase && $state === 'published' && $stepState !== 'skipped') {
                $stepState = 'done';
            }

            if ($stepState === 'active') {
                $completeEvent = null;
            }

            if ($stepState === 'skipped') {
                $startEvent = null;
                $completeEvent = null;
            }

$startDate = $startEvent['created_at'] ?? $stepData['start_fallback_date'];
$startUser = $startEvent !== null ? sf_workflow_user_name($startEvent) : $stepData['start_fallback_user'];

$completeDate = $completeEvent['created_at'] ?? $stepData['complete_fallback_date'];
$completeUser = $completeEvent !== null ? sf_workflow_user_name($completeEvent) : $stepData['complete_fallback_user'];

if ($stepState === 'pending' || $stepState === 'active') {
    $completeDate = '';
    $completeUser = '';
}

if ($stepState === 'skipped') {
    $startDate = '';
    $startUser = '';
    $completeDate = '';
    $completeUser = sf_term('workflow_tooltip_skipped', $currentUiLang);
}

            $steps[] = [
                'key' => $stepKey,
                'label' => $labels[$stepKey] !== ('workflow_step_' . $stepKey) ? $labels[$stepKey] : ucfirst($stepKey),
                'state' => $stepState,
                'target' => $stepData['target'],
                'started_at' => sf_workflow_format_datetime($startDate),
                'started_by' => trim((string)$startUser),
                'completed_at' => sf_workflow_format_datetime($completeDate),
                'completed_by' => trim((string)$completeUser),
            ];
        }

        return $steps;
    }
}

$logsAscForWorkflow = array_reverse($logs);

$investigationStartTimestamp = null;

foreach ($logsAscForWorkflow as $workflowLogRow) {
    $workflowEventType = (string)($workflowLogRow['event_type'] ?? '');

    if ($workflowEventType === 'investigation_created') {
        $workflowTimestamp = strtotime((string)($workflowLogRow['created_at'] ?? ''));

        if ($workflowTimestamp !== false) {
            $investigationStartTimestamp = $workflowTimestamp;
            break;
        }
    }
}

$currentFlashType = (string)($flash['type'] ?? '');
$originalFlashType = (string)($flash['original_type'] ?? '');

$hasInvestigationPhase = $currentFlashType === 'green' || $investigationStartTimestamp !== null;
$hasOriginalPhase = in_array($currentFlashType, ['red', 'yellow'], true)
    || in_array($originalFlashType, ['red', 'yellow'], true)
    || $investigationStartTimestamp !== null;

$originalWorkflowLogs = [];
$investigationWorkflowLogs = [];

foreach ($logsAscForWorkflow as $workflowLogRow) {
    $workflowTimestamp = strtotime((string)($workflowLogRow['created_at'] ?? ''));

    if ($investigationStartTimestamp !== null && $workflowTimestamp !== false) {
        if ($workflowTimestamp < $investigationStartTimestamp) {
            $originalWorkflowLogs[] = $workflowLogRow;
        } else {
            $investigationWorkflowLogs[] = $workflowLogRow;
        }
    } else {
        if ($currentFlashType === 'green') {
            $investigationWorkflowLogs[] = $workflowLogRow;
        } else {
            $originalWorkflowLogs[] = $workflowLogRow;
        }
    }
}

$sfWorkflowPhases = [];

if ($hasOriginalPhase) {
    $resolvedOriginalType = in_array($originalFlashType, ['red', 'yellow'], true)
        ? $originalFlashType
        : $currentFlashType;

    $originalPhaseFlash = $flash;
    $originalPhaseFlash['type'] = $resolvedOriginalType;

    $sfWorkflowPhases[] = [
        'key' => 'original',
        'number' => '1',
        'type_key' => $resolvedOriginalType,
        'icon' => $base . '/assets/img/icons/' . ($resolvedOriginalType === 'red' ? 'type-red.svg' : 'type-yellow.svg'),
        'title' => sf_workflow_type_label($resolvedOriginalType, $currentUiLang),
        'subtitle' => sf_term('workflow_phase_original_subtitle', $currentUiLang),
        'status' => $hasInvestigationPhase ? sf_term('workflow_status_completed', $currentUiLang) : sf_status_label((string)($flash['state'] ?? 'draft'), $currentUiLang),
        'status_class' => $hasInvestigationPhase ? 'done' : 'active',
        'steps' => sf_workflow_build_steps(
            $originalWorkflowLogs,
            $originalPhaseFlash,
            'original',
            !$hasInvestigationPhase,
            $currentUiLang
        ),
    ];
}

if ($hasInvestigationPhase) {
    $sfWorkflowPhases[] = [
        'key' => 'investigation',
        'number' => $hasOriginalPhase ? '2' : '1',
        'type_key' => 'green',
        'icon' => $base . '/assets/img/icons/type-green.svg',
        'title' => sf_term('investigation_report', $currentUiLang),
        'subtitle' => $hasOriginalPhase
            ? sf_term('workflow_phase_investigation_subtitle', $currentUiLang)
            : sf_term('workflow_phase_standalone_investigation_subtitle', $currentUiLang),
        'status' => sf_status_label((string)($flash['state'] ?? 'draft'), $currentUiLang),
        'status_class' => ((string)($flash['state'] ?? '') === 'published') ? 'done' : 'active',
        'steps' => sf_workflow_build_steps(
            $investigationWorkflowLogs,
            $flash,
            'investigation',
            true,
            $currentUiLang
        ),
    ];
}

$sfCurrentWorkflowPhase = !empty($sfWorkflowPhases)
    ? $sfWorkflowPhases[array_key_last($sfWorkflowPhases)]
    : null;

$sfCurrentWorkflowState = (string)($flash['state'] ?? 'draft');
$sfCurrentWorkflowStatusLabel = function_exists('sf_status_label')
    ? sf_status_label($sfCurrentWorkflowState, $currentUiLang)
    : $sfCurrentWorkflowState;

$sfCurrentWorkflowStatusClass = $sfCurrentWorkflowState === 'published'
    ? 'done'
    : 'active';

$sfCurrentWorkflowStatusToneClass = match ($sfCurrentWorkflowState) {
    'pending_supervisor' => 'tone-pending-supervisor',
    'pending_review' => 'tone-pending',
    'to_comms', 'awaiting_publish' => 'tone-comms',
    'reviewed' => 'tone-reviewed',
    'published' => 'tone-published',
    'request_info' => 'tone-request-info',
    'draft' => 'tone-draft',
    default => 'tone-other',
};

// Onko tämä kieliversio vai alkuperäinen flash?
$isTranslation = !empty($flash['translation_group_id'])
    && (int) $flash['translation_group_id'] !== (int) $flash['id'];

$editUrl  = $base .  '/index.php?page=form&id=' .  $id;

// --- Työmaavastaavan tarkistus: näytä kenellä tarkistuksessa ---
$pendingSupervisorUsers = [];
$selectedSupervisorIds = [];

if (($flash['state'] ?? '') === 'pending_supervisor') {
    $rawSel = $flash['selected_approvers'] ?? null;

    if (!empty($rawSel)) {
        $decoded = json_decode((string)$rawSel, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Tue sekä [1,2] että {"approver_ids":[1,2]} muodot
            if (isset($decoded['approver_ids']) && is_array($decoded['approver_ids'])) {
                $decoded = $decoded['approver_ids'];
            }

            $selectedSupervisorIds = array_values(array_unique(array_map('intval', $decoded)));
            $selectedSupervisorIds = array_values(array_filter($selectedSupervisorIds, fn($v) => (int)$v > 0));
        }
    }

    if (!empty($selectedSupervisorIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedSupervisorIds), '?'));
        $stmtSup = $pdo->prepare("
            SELECT id, first_name, last_name, email
            FROM sf_users
            WHERE id IN ($placeholders)
            ORDER BY last_name, first_name
        ");
        $stmtSup->execute($selectedSupervisorIds);
        $pendingSupervisorUsers = $stmtSup->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

// --- Tuetut kielet ja lippujen ikonit ---
$supportedLangs = [
    'fi' => ['label' => 'FI', 'icon' => 'finnish-flag.png'],
    'sv' => ['label' => 'SV', 'icon' => 'swedish-flag.png'],
    'en' => ['label' => 'EN', 'icon' => 'english-flag.png'],
    'it' => ['label' => 'IT', 'icon' => 'italian-flag.png'],
    'el' => ['label' => 'EL', 'icon' => 'greece-flag.png'], // 'el' on Kreikan kielikoodi
];

// Lippu-apufunktio kieliversioiden visuaaliseen tunnistamiseen
if (!function_exists('sf_lang_flag')) {
    function sf_lang_flag(string $lang): string {
        return match($lang) {
            'fi' => '🇫🇮',
            'sv' => '🇸🇪',
            'en' => '🇬🇧',
            'it' => '🇮🇹',
            'el' => '🇬🇷',
            default => '🏳️',
        };
    }
}

// --- Kieliversiot & preview ---
require_once __DIR__ .'/../services/render_services.php';

$currentId   = (int) ($flash['id'] ?? 0);
$currentLang = $flash['lang'] ?? 'fi';

$translationGroupId = !empty($flash['translation_group_id'])
    ? (int) $flash['translation_group_id']
    : $currentId;

$translations = [];
if ($translationGroupId > 0 && function_exists('sf_get_flash_translations')) {
    $translations = sf_get_flash_translations($pdo, $translationGroupId);
    if (!isset($translations[$currentLang]) && $currentId > 0) {
        $translations[$currentLang] = $currentId;
    }
}

// Julkaistun kieliversion tieto luetaan vain safetyflash_logs.description-kentästä.
// Luotettava muoto on esimerkiksi: log_language_version: FI
// Vanhoille lokeille ilman tätä merkintää ei arvata kieltä sf_flashes.lang- tai published_at-tiedoista.

// Check preview status
$previewStatus = $flash['preview_status'] ?? 'completed';
$isPreviewPending = ($previewStatus === 'pending' || $previewStatus === 'processing');

// Jos preview_filename puuttuu, yritä generoida se
if (empty($flash['preview_filename']) && $currentId > 0 && function_exists('sf_generate_flash_preview') && !$isPreviewPending) {
    try {
        sf_generate_flash_preview($pdo, $currentId);
        // Hae uudelleen
        $stmtPrev = $pdo->prepare("SELECT preview_filename, preview_status FROM sf_flashes WHERE id = ?");
        $stmtPrev->execute([$currentId]);
        $prevRow = $stmtPrev->fetch();
        if ($prevRow && !empty($prevRow['preview_filename'])) {
            $flash['preview_filename'] = $prevRow['preview_filename'];
        }
        if ($prevRow && !empty($prevRow['preview_status'])) {
            $previewStatus = $prevRow['preview_status'];
            $isPreviewPending = ($previewStatus === 'pending' || $previewStatus === 'processing');
        }
    } catch (Throwable $e) {
        error_log("Could not auto-generate preview for flash {$currentId}: " .$e->getMessage());
    }
}

// Cache-buster kuville (estää vanhan kuvan näkymisen viewissä)
$sfCacheBust = function (string $url, ?string $absPath): string {
    if (!empty($absPath) && is_file($absPath)) {
        $v = (string) filemtime($absPath);
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . rawurlencode($v);
    }
    return $url;
};

// --- Preview-kuva 1 ---
$previewUrl = "{$base}/assets/img/camera-placeholder.png";
$previewAbsPath = null;

if (!empty($flash['preview_filename'])) {
    $filename = $flash['preview_filename'];
    $previewPathNew = __DIR__ .'/../../uploads/previews/' .$filename;
    $previewPathOld = __DIR__ .'/../../img/' .$filename; // legacy

    if (is_file($previewPathNew)) {
        $previewUrl = "{$base}/uploads/previews/" .$filename;
        $previewAbsPath = $previewPathNew;
    } elseif (is_file($previewPathOld)) {
        $previewUrl = "{$base}/img/" .$filename;
        $previewAbsPath = $previewPathOld;
    }
}

$previewUrl = $sfCacheBust($previewUrl, $previewAbsPath);

// --- Preview-kuva 2 (vain tutkintatiedotteille) ---
$previewUrl2 = null;
$hasSecondCard = false;
$previewAbsPath2 = null;

// Check if second card exists by checking if the file exists (simpler and more reliable)
if ($flash['type'] === 'green' && !empty($flash['preview_filename_2'])) {
    $filename2 = $flash['preview_filename_2'];
    $previewPath2New = __DIR__ .'/../../uploads/previews/' .$filename2;
    $previewPath2Old = __DIR__ .'/../../img/' .$filename2;
    
    if (is_file($previewPath2New)) {
        $previewUrl2 = "{$base}/uploads/previews/" .$filename2;
        $previewAbsPath2 = $previewPath2New;
        $hasSecondCard = true;
    } elseif (is_file($previewPath2Old)) {
        $previewUrl2 = "{$base}/img/" .$filename2;
        $previewAbsPath2 = $previewPath2Old;
        $hasSecondCard = true;
    }
}

if (!empty($previewUrl2)) {
    $previewUrl2 = $sfCacheBust($previewUrl2, $previewAbsPath2);
}
// UUSI: Editorissa generoitu rasteri (uploads/edited) – luetaan annotations_datasta
$sfAnn = json_decode($flash['annotations_data'] ?? '', true);
$sfEditedImages = (is_array($sfAnn) && isset($sfAnn['edited_images']) && is_array($sfAnn['edited_images']))
    ? $sfAnn['edited_images']
    : [];

$sfGetEditedUrl = function (int $slot) use ($sfEditedImages, $base, $sfCacheBust): ?string {
    $key = (string) $slot;
    if (!empty($sfEditedImages[$key])) {
        $file = $sfEditedImages[$key];
        $url  = $base .'/uploads/edited/' .$file;
        $abs  = __DIR__ .'/../../uploads/edited/' .$file;
        return $sfCacheBust($url, $abs);
    }
    return null;
};

// Kuvapolkujen muodostaminen JS:lle
$getImageUrlForJs = function ($filename) use ($base) {
    if (empty($filename)) {
        return '';
    }
    
    // Tarkista ensin uploads/images
    $path = "uploads/images/{$filename}";
    $fullPath = __DIR__ ."/../../{$path}";
    if (file_exists($fullPath)) {
        return "{$base}/{$path}";
    }
    
    // Tarkista uploads/library (kuvakirjasto)
    $libPath = "uploads/library/{$filename}";
    $libFullPath = __DIR__ ."/../../{$libPath}";
    if (file_exists($libFullPath)) {
        return "{$base}/{$libPath}";
    }
    
    // Vanha polku (legacy)
    $oldPath = "img/{$filename}";
    $oldFullPath = __DIR__ . "/../../{$oldPath}";
    if (file_exists($oldFullPath)) {
        return "{$base}/{$oldPath}";
    }
    
    // Palauta tyhjä jos ei löydy
    return '';
};

// Hae originaalin grid_bitmap jos tämä on kieliversio
$originalGridBitmap = $flash['grid_bitmap'] ?? '';
$originalGridBitmapUrl = '';

if (empty($originalGridBitmap) && ! empty($flash['translation_group_id'])) {
    // Hae originaalin grid_bitmap
    $origStmt = $pdo->prepare("SELECT grid_bitmap FROM sf_flashes WHERE id = ?  LIMIT 1");
    $origStmt->execute([(int)$flash['translation_group_id']]);
    $origRow = $origStmt->fetch();
    if ($origRow && !empty($origRow['grid_bitmap'])) {
        $originalGridBitmap = $origRow['grid_bitmap'];
    }
}

// Muodosta grid_bitmap URL
if (! empty($originalGridBitmap)) {
    if (strpos($originalGridBitmap, 'data:image/') === 0) {
        $originalGridBitmapUrl = $originalGridBitmap;
    } else {
        $gridPath = __DIR__ . '/../../uploads/grids/' . $originalGridBitmap;
        if (file_exists($gridPath)) {
            $originalGridBitmapUrl = $base .  '/uploads/grids/' . $originalGridBitmap;
        }
    }
}

// Prepare flash data for JavaScript - ALWAYS use parent ID for creating translations
$parentFlashId = !empty($flash['translation_group_id']) 
    ? (int)$flash['translation_group_id'] 
    : (int)$flash['id'];

$flashDataForJs = [
    'id' => $parentFlashId,  // Use parent ID for translation creation
    'current_id' => (int)$flash['id'],  // Current flash being viewed
    'translation_group_id' => $flash['translation_group_id'] ?? null,
    'type' => $flash['type'],
    'title' => $flash['title'],
    'title_short' => $flash['title_short'] ?? $flash['summary'] ?? '',
    'description' => $flash['description'] ?? '',
    'root_causes' => $flash['root_causes'] ?? '',
    'actions' => $flash['actions'] ??  '',
    'site' => $flash['site'] ??  '',
    'site_detail' => $flash['site_detail'] ?? '',
    'occurred_at' => $flash['occurred_at'] ?? '',
    'lang' => $flash['lang'] ??  'fi',
    'image_main' => $flash['image_main'] ?? '',
    'image_2' => $flash['image_2'] ?? '',
    'image_3' => $flash['image_3'] ?? '',
    'image_main_url' => ($sfGetEditedUrl(1) ?: $getImageUrlForJs($flash['image_main'] ?? null)),
    'image_2_url' => ($sfGetEditedUrl(2) ?: $getImageUrlForJs($flash['image_2'] ?? null)),
    'image_3_url' => ($sfGetEditedUrl(3) ?: $getImageUrlForJs($flash['image_3'] ?? null)),
    'image1_transform' => $flash['image1_transform'] ?? '',
    'image2_transform' => $flash['image2_transform'] ?? '',
    'image3_transform' => $flash['image3_transform'] ?? '',
    'grid_style' => $flash['grid_style'] ?? 'grid-3-main-top',
    'grid_bitmap' => $originalGridBitmap,
    'grid_bitmap_url' => $originalGridBitmapUrl,
];

// --- Tyyppien labelit termistön kautta ---
$typeKeyMap = [
    'red'    => 'first_release',
    'yellow' => 'dangerous_situation',
    'green'  => 'investigation_report',
];
$typeKey   = $typeKeyMap[$flash['type']] ?? null;
$typeLabel = $typeKey ? sf_term($typeKey, $currentUiLang) : 'Safetyflash';

// --- Apu: generaattori lokirivin avataria varten (nimi -> initials) ---
function sf_avatar_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($initials) >= 2) break;
    }
    return $initials ?: 'SF';
}
// ===== TOIMINTOJEN MÄÄRITYS KÄYTTÄJÄN ROOLIN JA TILAN MUKAAN =====
$currentUser = sf_current_user();
$roleId = (int)($currentUser['role_id'] ?? 0);
$currentUserId = (int)($currentUser['id'] ?? 0);
$createdBy = (int)($flash['created_by'] ?? 0);
$stateVal = $flash['state'] ?? 'draft';

$isOwner = ($currentUserId > 0 && $createdBy === $currentUserId);
$isAdmin = ($roleId === 1);
$isSafety = ($roleId === 3);
$isComms = ($roleId === 4);

$permissionService = new FlashPermissionService();
$isAssignedLanguageReviewer = $permissionService->isAssignedLanguageReviewer($currentUserId, $flash);
$canPublishAssignedLanguage = $permissionService->canPublishLanguageVersion($currentUser ?: [], $flash);

$actions = [];

// Check if archived - if so, disable most actions
$isArchived = !empty($flash['is_archived']);

// Kommentointi kaikille kirjautuneille (ei arkistoiduille)
if (!$isArchived) {
    $actions[] = 'comment';
}

// If archived, no further actions allowed
if ($isArchived) {
    // Archived flashes cannot be edited or modified
    // Only viewing is allowed
} else {
    // Tarkista onko käännösryhmässä jo edenneitä sisarversioita (to_comms, awaiting_publish, published)
    $hasAdvancedSibling = false;
    if (!empty($flash['translation_group_id'])) {
        $gid = (int)$flash['translation_group_id'];
        $stmtAdv = $pdo->prepare("
            SELECT COUNT(*) FROM sf_flashes
            WHERE (id = :gid OR translation_group_id = :gid2)
              AND id != :self
              AND state IN ('to_comms', 'awaiting_publish', 'published')
        ");
        $stmtAdv->execute([':gid' => $gid, ':gid2' => $gid, ':self' => (int)$flash['id']]);
        $hasAdvancedSibling = (int)$stmtAdv->fetchColumn() > 0;
    }

    // Määritä toiminnot tilan ja roolin mukaan
switch ($stateVal) {
    case 'draft':
        if ($isOwner || $isAdmin) {
            $actions[] = 'edit';
            $actions[] = 'delete';
        }
        if ($hasAdvancedSibling) {
            // Sisarus on jo edennyt → ohitetaan supervisor-kierros, mennään suoraan viestintään
            if ($isOwner || $isAdmin || $isSafety || $isComms) {
                $actions[] = 'send_to_comms_direct';
            }
            if ($isAdmin || $isSafety || $isComms) {
                $actions[] = 'publish_single';
                if (!in_array('edit', $actions, true)) {
                    $actions[] = 'edit';
                }
            }
        } else {
            // Tavallinen flow: lähetä tarkistettavaksi
            if ($isOwner || $isAdmin) {
                $actions[] = 'send_to_review';
            }
        }
        break;

case 'pending_supervisor':
        require_once __DIR__ . '/../../app/services/ApprovalRouting.php';
        $isSupervisor = ApprovalRouting::isUserSupervisor($pdo, $currentUserId);
        $isSelectedApprover = ApprovalRouting::isUserSelectedApprover($pdo, (int)$id, $currentUserId);

        if (($isSupervisor && $isSelectedApprover) || $isAdmin || $isSafety) {
            $actions[] = 'edit';
            $actions[] = 'send_to_safety';
            $actions[] = 'request';
        }

        if ($isAdmin || $isSafety) {
            $actions[] = 'approve_to_comms';
            $actions[] = 'publish_direct';
        }

        break;

    case 'pending_review':
        if ($isSafety || $isAdmin) {
            $actions[] = 'edit';
            $actions[] = 'request';
            $actions[] = 'comms';
            $actions[] = 'publish_direct';
        }
        break;

case 'request_info': 
    if ($isOwner || $isAdmin) {
        $actions[] = 'edit';
        // send_to_review poistettu - se näkyy jo lomakkeen esikatselussa
    }
    break;

    case 'reviewed':
        if ($isSafety || $isAdmin) {
            $actions[] = 'edit';
            $actions[] = 'comms';
        }
        break;

    case 'to_comms':
        if ($isComms || $isAdmin) {
            $actions[] = 'edit';
            $actions[] = 'publish';
            $actions[] = 'request';     // Palauta turvatiimille
        }
        // Turvatiimi voi myös muokata viestinnällä-tilassa
        if ($isSafety) {
            $actions[] = 'edit';
        }
        break;

    case 'awaiting_publish':
        if ($isComms || $isAdmin || $isSafety || $canPublishAssignedLanguage) {
            $actions[] = 'edit';
            $actions[] = 'publish_single';
        } elseif ($isAssignedLanguageReviewer) {
            $actions[] = 'edit';
        }
        break;

    case 'published': 
        if ($isAdmin || $isSafety || $isComms) {
            $actions[] = 'edit';
        }
        // Add archive action for admin and safety team
        if (($isAdmin || $isSafety) && !$isArchived) {
            $actions[] = 'archive';
        }
        // Infonäyttöjen hallinta julkaistuille flasheille
        if ($isAdmin || $isSafety || $isComms) {
            $actions[] = 'display_targets';
        }
        break;
}

// Poista duplikaatit
$actions = array_unique($actions);
// Admin voi aina poistaa
if ($isAdmin && ! in_array('delete', $actions)) {
    $actions[] = 'delete';
}

$languageReviewOpenLanguageCodes = [];
$hasLanguageReviewRequestForGroup = false;

try {
    $stmtLanguageReviewVersions = $pdo->prepare("
        SELECT id, lang, state
        FROM sf_flashes
        WHERE (id = :group_id OR translation_group_id = :group_id_2)
          AND lang IN ('sv', 'en', 'it', 'el')
    ");
    $stmtLanguageReviewVersions->execute([
        ':group_id' => $translationGroupId,
        ':group_id_2' => $translationGroupId,
    ]);

    $languageReviewVersionRows = $stmtLanguageReviewVersions->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $languageReviewVersionIds = [];

    foreach ($languageReviewVersionRows as $languageReviewVersionRow) {
        $languageReviewVersionIds[] = (int)$languageReviewVersionRow['id'];

        if (($languageReviewVersionRow['state'] ?? '') !== 'published') {
            $languageReviewOpenLanguageCodes[] = (string)$languageReviewVersionRow['lang'];
        }
    }

    $languageReviewOpenLanguageCodes = array_values(array_unique($languageReviewOpenLanguageCodes));

    if (!empty($languageReviewVersionIds)) {
        $languageReviewPlaceholders = implode(',', array_fill(0, count($languageReviewVersionIds), '?'));

        $stmtLanguageReviewRequests = $pdo->prepare("
            SELECT COUNT(*)
            FROM sf_flash_language_reviewers
            WHERE flash_id IN ($languageReviewPlaceholders)
              AND status IN ('pending', 'in_progress', 'completed', 'published')
        ");
        $stmtLanguageReviewRequests->execute($languageReviewVersionIds);

        $hasLanguageReviewRequestForGroup = ((int)$stmtLanguageReviewRequests->fetchColumn() > 0);
    }
} catch (Throwable $e) {
    error_log('view.php language review visibility check failed: ' . $e->getMessage());
    $languageReviewOpenLanguageCodes = [];
    $hasLanguageReviewRequestForGroup = false;
}

$canRequestLanguageReview =
    !$isArchived
    && ($isAdmin || $isComms)
    && !$hasLanguageReviewRequestForGroup
    && count($languageReviewOpenLanguageCodes) > 0;

if ($canRequestLanguageReview && !in_array('language_review', $actions, true)) {
    $actions[] = 'language_review';
}
}

$hasActions = ! empty($actions);

// Determine if current user can edit this flash (used in Images tab JS)
$canEdit = in_array('edit', $actions, true);

// Determine if current user can add extra images (broader than canEdit - allows owner/admin/safety in all states)
$canAddExtraImages = $isOwner || $isAdmin || $isSafety;

// Determine if current user can access report settings (Settings modal, body map for all types)
$canAccessSettings = !$isArchived && ($isAdmin || $isSafety || $isOwner);

$footerMenuActions = array_values(array_filter($actions, static function ($action) {
    return !in_array($action, ['comment', 'edit'], true);
}));

$mobileFooterMenuActions = $footerMenuActions;

// Body parts can be edited with broader permission rules than other inline settings
$canEditBodyParts = !$isArchived && $permissionService->canEditBodyParts($currentUser ?: [], $flash);

// Merge existing original flash into investigation report
// original_type can already be set manually from settings, so the merge button
// must stay visible until an actual original flash has been linked.
$hasLinkedOriginalFlash = false;

try {
    $stmtHasLinkedOriginalFlash = $pdo->prepare("
        SELECT COUNT(*) 
        FROM sf_flash_snapshots
        WHERE flash_id = ?
          AND version_type IN ('ensitiedote', 'vaaratilanne')
    ");
    $stmtHasLinkedOriginalFlash->execute([(int)$flash['id']]);
    $hasLinkedOriginalFlash = ((int)$stmtHasLinkedOriginalFlash->fetchColumn() > 0);
} catch (Throwable $e) {
    error_log('view.php merge visibility check failed: ' . $e->getMessage());
    $hasLinkedOriginalFlash = false;
}

$canMergeOriginalFlash =
    !$isArchived
    && !$isTranslation
    && (($flash['type'] ?? '') === 'green')
    && !$hasLinkedOriginalFlash
    && ($isAdmin || $isSafety || $isComms || $isOwner || $canEdit);

$viewHasFooter =
    in_array('edit', $actions, true)
    || !empty($footerMenuActions)
    || $canMergeOriginalFlash;

$mobileHasFooter = $viewHasFooter;

$iconBase = $base .'/assets/img/icons/';
?>
<?php $stateCss = preg_replace('/[^a-z0-9_\-]/i', '', (string)($flash['state'] ?? '')); ?>
<div class="sf-page-container">
<div class="view-container view-state-<?= htmlspecialchars($stateCss, ENT_QUOTES, 'UTF-8') ?>">
    <div class="view-back" style="display: flex; justify-content: space-between; align-items: flex-start;">
        <a
          href="<?= htmlspecialchars($base) ?>/index.php?page=list"
          class="btn-back"
          aria-label="<?= htmlspecialchars(sf_term('back_to_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        >
          <span aria-hidden="true">←</span>
          <span class="btn-back-label-desktop"><?= htmlspecialchars(sf_term('back_to_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
          <span class="btn-back-label-mobile"><?= htmlspecialchars(sf_term('btn_back', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </a>
        <?php if (($flash['type'] ?? '') === 'green'): ?>
        <div class="sf-view-back-right">
            <button
               id="btnGenerateReport"
               data-report-url="<?= htmlspecialchars($base) ?>/app/api/generate_report.php?id=<?= (int)$id ?>"
               class="btn-report-topright"
               aria-label="<?= htmlspecialchars(sf_term('btn_report', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $iconBase ?>report.svg" alt="" class="btn-report-icon">
                <span><?= htmlspecialchars(sf_term('btn_report', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($viewHasFooter): ?>
    <div
        class="view-footer-actions"
        role="toolbar"
        aria-label="<?= htmlspecialchars(sf_term('footer_actions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        data-mobile-has-footer="<?= $mobileHasFooter ? '1' : '0' ?>"
    >
        <div class="view-footer-buttons-4col">

            <?php if (in_array('comment', $actions, true)): ?>
                <button class="footer-btn fb-comment sf-footer-primary-action" id="footerComment" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_comment', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>comment_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_comment', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('edit', $actions, true)): ?>
                <button class="footer-btn fb-edit sf-footer-primary-action" id="footerEdit" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>edit_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (!empty($footerMenuActions) || $canMergeOriginalFlash): ?>
                <div class="sf-footer-actions-details">
                    <button class="footer-btn sf-footer-actions-toggle" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_actions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= $iconBase ?>send_forward_icon.svg" alt="" class="footer-icon">
                        <span class="btn-label"><?= htmlspecialchars(sf_term('footer_actions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </button>

                    <div class="sf-mobile-actions-menu">

                        <?php if (in_array('delete', $actions, true)): ?>
                            <button class="footer-btn fb-delete sf-footer-menu-action" id="footerDelete" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                                <img src="<?= $iconBase ?>delete_icon.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('request', $actions, true)): ?>
                            <button class="footer-btn fb-request sf-footer-menu-action" id="footerRequest" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_return', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                                <img src="<?= $iconBase ?>reverse_icon.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_return', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('approve_to_comms', $actions, true)): ?>
                            <?php
                            $lblApproveToComms = [
                                'fi' => 'Hyväksy → viestintään',
                                'sv' => 'Godkänn → kommunikation',
                                'en' => 'Approve → Comms',
                                'it' => 'Approva → Comunicazione',
                                'el' => 'Έγκριση → Επικοινωνία',
                            ][$currentUiLang] ?? 'Approve → Comms';
                            ?>
                            <button
                                class="footer-btn fb-comms sf-footer-menu-action"
                                id="footerApproveToComms"
                                type="button"
                                data-modal-open="#modalToComms"
                                aria-label="<?= htmlspecialchars($lblApproveToComms, ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <img src="<?= $iconBase ?>communications_icon.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars($lblApproveToComms, ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('comms', $actions, true)): ?>
                            <button class="footer-btn fb-comms sf-footer-menu-action" id="footerComms" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_to_comms', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                                <img src="<?= $iconBase ?>communications_icon.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_to_comms', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('send_to_safety', $actions, true)): ?>
                            <button class="footer-btn fb-send-safety sf-footer-menu-action" id="footerSendSafety" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_send_to_safety', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                                <img src="<?= $iconBase ?>send_forward_icon.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_send_to_safety', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('publish_direct', $actions, true)): ?>
                            <button
                                class="footer-btn fb-publish sf-footer-menu-action"
                                id="footerPublishDirect"
                                type="button"
                                data-modal-open="#modalPublishDirect"
                                aria-label="<?= htmlspecialchars(sf_term('footer_publish_direct', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <img src="<?= $iconBase ?>forward_icon-2.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_publish_direct', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('publish', $actions, true)): ?>
                            <button
                                class="footer-btn fb-publish sf-footer-menu-action sf-footer-mobile-only-action"
                                id="footerPublish"
                                type="button"
                                onclick="openPublishModal()"
                                aria-label="<?= htmlspecialchars(sf_term('footer_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <img src="<?= $iconBase ?>forward_icon-2.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('publish_single', $actions, true)): ?>
                            <button
                                class="footer-btn fb-publish sf-footer-menu-action"
                                id="footerPublishSingle"
                                type="button"
                                onclick="openPublishSingleModal()"
                                aria-label="<?= htmlspecialchars(sf_term('btn_publish_language_version', $currentUiLang) ?? 'Julkaise kieliversio', ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <img src="<?= $iconBase ?>forward_icon-2.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('btn_publish_language_version', $currentUiLang) ?? 'Julkaise kieliversio', ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('archive', $actions, true)): ?>
                            <button class="footer-btn fb-archive sf-footer-menu-action" id="footerArchive" type="button" aria-label="<?= htmlspecialchars(sf_term('btn_archive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                                <img src="<?= $iconBase ?>archive_icon.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('btn_archive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('display_targets', $actions, true)): ?>
                            <button
                                class="footer-btn fb-display-targets sf-footer-menu-action"
                                id="footerDisplayTargets"
                                type="button"
                                data-modal-open="#displayTargetsModal"
                                aria-label="<?= htmlspecialchars(sf_term('footer_display_targets', $currentUiLang) ?? 'Infonäytöt', ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <img src="<?= $iconBase ?>display.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_display_targets', $currentUiLang) ?? 'Infonäytöt', ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('language_review', $actions, true)): ?>
                            <button
                                class="footer-btn fb-language-review sf-footer-menu-action"
                                id="footerLanguageReview"
                                type="button"
                                data-modal-open="#modalLanguageReview"
                                aria-label="<?= htmlspecialchars(sf_term('footer_language_review', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <img src="<?= $iconBase ?>translate_icon.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_language_review', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if ($canMergeOriginalFlash): ?>
                            <button
                                class="footer-btn fb-merge sf-footer-menu-action"
                                id="footerMergeFlash"
                                type="button"
                                aria-label="<?= htmlspecialchars(sf_term('footer_merge_flash', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <img src="<?= $iconBase ?>link.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_merge_flash', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array('send_to_review', $actions, true)): ?>
                            <a href="<?= htmlspecialchars($base) ?>/index.php?page=form&id=<?= (int) $id ?>&step=6" class="footer-btn fb-comms sf-footer-menu-action">
                                <img src="<?= $iconBase ?>supervisor_icon.svg" alt="" class="footer-icon">
                                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_send_to_review', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        <?php endif; ?>

                        <?php if (in_array('send_to_comms_direct', $actions, true)): ?>
                            <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/draft_to_comms.php?id=<?= (int)$id ?>" class="footer-form sf-footer-menu-form">
                                <?= sf_csrf_field() ?>
                                <button type="submit" class="footer-btn fb-publish sf-footer-menu-action" aria-label="<?= htmlspecialchars(sf_term('footer_to_comms', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                                    <img src="<?= $iconBase ?>forward_icon-2.svg" alt="" class="footer-icon">
                                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_to_comms', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                                </button>
                            </form>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
    document.addEventListener('click', function(event) {
        const footerMenu = event.target.closest('.sf-footer-actions-details');
        const openFooterMenu = document.querySelector('.sf-footer-actions-details.is-open');

        if (footerMenu) {
            const toggle = event.target.closest('.sf-footer-actions-toggle');
            const menuAction = event.target.closest('.sf-footer-menu-action');

            if (toggle) {
                event.preventDefault();
                footerMenu.classList.toggle('is-open');
                return;
            }

            if (menuAction) {
                footerMenu.classList.remove('is-open');
                return;
            }
        }

        if (openFooterMenu && !openFooterMenu.contains(event.target)) {
            openFooterMenu.classList.remove('is-open');
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key !== 'Escape') {
            return;
        }

        const openFooterMenu = document.querySelector('.sf-footer-actions-details.is-open');
        if (openFooterMenu) {
            openFooterMenu.classList.remove('is-open');
        }
    });
    </script>
    <?php endif; // End of hasActions check ?>

    <div class="sf-view-language-status-row">
        <div
          class="lang-switcher"
          role="tablist"
          aria-label="<?= htmlspecialchars(sf_term('view_languages_aria', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        >
            <?php foreach ($supportedLangs as $langCode => $langData):
                $hasTranslation = isset($translations[$langCode]);
                $isActive = ($langCode === $currentLang);
                
                if ($isArchived && ! $hasTranslation) {
                    continue;
                }
                
                $tooltipText = '';
                if ($isActive) {
                    $tooltipText = sf_term('lang_tooltip_active_' . $langCode, $currentUiLang) ?? '';
                } elseif ($hasTranslation) {
                    $tooltipText = sf_term('lang_tooltip_goto_' . $langCode, $currentUiLang) ?? '';
                } else {
                    $tooltipText = sf_term('lang_tooltip_add_' . $langCode, $currentUiLang) ?? '';
                }
                
                $addButtonText = sf_term('lang_add_button_text', $currentUiLang) ?? '+Lisää';
            ?>
                <div class="lang-chip <?= $isActive ? 'active' : '' ?> <?= $hasTranslation ? 'has-version' : 'no-version' ?>" role="button" tabindex="0" title="<?= htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($hasTranslation): ?>
                        <a href="index.php?page=view&id=<?= (int)$translations[$langCode] ?>" class="lang-link">
                            <img class="lang-flag-img" src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon']) ?>" alt="<?= htmlspecialchars($langData['label']) ?>">
                            <span class="lang-label"><?= htmlspecialchars($langData['label']) ?></span>
                        </a>
                    <?php elseif (! $isArchived): ?>
                        <button type="button" class="lang-add-button" data-lang="<?= htmlspecialchars($langCode) ?>" data-lang-label="<?= htmlspecialchars($langData['label']) ?>" data-base-id="<?= (int)$currentId ?>" onclick="sfConfirmTranslation(this)">
                            <img class="lang-flag-img" src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon']) ?>" alt="<?= htmlspecialchars($langData['label']) ?>">
                            <span class="lang-label"><?= htmlspecialchars($addButtonText) ?></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (($flash['type'] ?? '') === 'green'): ?>
            <?php
            $showAthenaMissingBadge = !$athenaExported && ($isAdmin || $isSafety || $isComms || $isOwner);
            ?>
            <?php if ($athenaExported): ?>
                <?php
                $athenaExportedDate = '';
                if (!empty($athenaExportRow['exported_at'])) {
                    try {
                        $athenaDate = new DateTime((string)$athenaExportRow['exported_at']);
                        $athenaExportedDate = $athenaDate->format('j.n.Y');
                    } catch (Throwable $e) {
                        $athenaExportedDate = '';
                    }
                }

                $athenaExportedText = sf_term('badge_athena_exported', $currentUiLang);
                if ($athenaExportedDate !== '') {
                    $athenaExportedText .= ' ' . $athenaExportedDate;
                }
                ?>
                <div class="sf-athena-status-card sf-athena-status-card--top">
                    <span class="sf-athena-badge sf-athena-badge--ok" id="sfAthenaBadge">
                        <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/forward_icon-2.svg" alt="" class="sf-athena-badge__icon" aria-hidden="true">
                        <span class="sf-athena-badge__text">
                            <?= htmlspecialchars($athenaExportedText, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </span>
                </div>
            <?php elseif ($showAthenaMissingBadge): ?>
                <div class="sf-athena-status-card sf-athena-status-card--top">
                    <span class="sf-athena-badge sf-athena-badge--missing" id="sfAthenaBadge">
                        <span class="sf-athena-badge__icon">!</span>
                        <span class="sf-athena-badge__text">
                            <?= htmlspecialchars(sf_term('badge_athena_not_exported', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </span>

                    <button
                        type="button"
                        class="sf-athena-action-btn"
                        onclick="document.getElementById('sfAthenaReminderModal')?.classList.remove('hidden'); document.body.classList.add('sf-modal-open');"
                    >
                        <?= htmlspecialchars(sf_term('btn_athena_mark_done', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- UUSI RAKENNE ALKAA TÄSTÄ -->
    <div class="view-layout">
        <!-- Vasen palsta -->
        <div class="view-left">
            <div class="view-box preview-box" 
                 data-flash-id="<?= (int)$flash['id'] ?>"
                 data-preview-status="<?= htmlspecialchars($previewStatus) ?>">
                <!-- Loading spinner for preview image -->
                <div class="preview-loading-spinner" id="previewSpinner">
                    <div class="spinner"></div>
                    <span class="spinner-text"><?= htmlspecialchars(sf_term('loading', $currentUiLang) ?: 'Ladataan...', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                
                <?php if ($flash['type'] === 'green' && $hasSecondCard): ?>
                    <!-- TUTKINTATIEDOTE: Välilehdet kahdelle kortille -->
                    <div class="sf-view-preview-tabs" id="sfViewPreviewTabs">
                        <button type="button"
                                class="sf-view-tab-btn active"
                                data-target="preview1">
                            <?= htmlspecialchars(sf_term('card_1_summary', $currentUiLang) ?? '1. Yhteenveto', ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button"
                                class="sf-view-tab-btn"
                                data-target="preview2">
                            <?= htmlspecialchars(sf_term('card_2_investigation', $currentUiLang) ?? '2. Juurisyyt & toimenpiteet', ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>

                    <div class="sf-view-preview-cards">
                        <div class="sf-view-preview-card active" id="viewPreview1">
<div class="sf-preview-image-loader" data-sf-preview-loader>
    <div class="sf-preview-image-skeleton" aria-hidden="true">
        <div class="sf-preview-skeleton-line sf-preview-skeleton-line--top"></div>
        <div class="sf-preview-skeleton-card"></div>
        <div class="sf-preview-skeleton-line sf-preview-skeleton-line--bottom"></div>
    </div>

    <img src="<?= htmlspecialchars($previewUrl) ?>" alt="Preview kortti 1"
         class="preview-image preview-image-clickable sf-preview-image-loading" id="viewPreviewImage1"
         loading="eager"
         decoding="async"
         data-preview-fullscreen-trigger="true"
         data-preview-title="<?= htmlspecialchars(sf_term('card_1_summary', $currentUiLang) ?? '1. Yhteenveto', ENT_QUOTES, 'UTF-8') ?>"
         tabindex="0"
         role="button">
</div>
                        </div>
                        <div class="sf-view-preview-card" id="viewPreview2" style="display:none;">
                            <?php if ($previewUrl2): ?>
                                <div class="sf-preview-image-loader" data-sf-preview-loader>
    <div class="sf-preview-image-skeleton" aria-hidden="true">
        <div class="sf-preview-skeleton-line sf-preview-skeleton-line--top"></div>
        <div class="sf-preview-skeleton-card"></div>
        <div class="sf-preview-skeleton-line sf-preview-skeleton-line--bottom"></div>
    </div>

    <img src="<?= htmlspecialchars($previewUrl2) ?>" alt="Preview kortti 2"
         class="preview-image preview-image-clickable sf-preview-image-loading" id="viewPreviewImage2"
         loading="lazy"
         decoding="async"
         data-preview-fullscreen-trigger="true"
         data-preview-title="<?= htmlspecialchars(sf_term('card_2_investigation', $currentUiLang) ?? '2. Juurisyyt & toimenpiteet', ENT_QUOTES, 'UTF-8') ?>"
         tabindex="0"
         role="button">
</div>
                            <?php else: ?>
                                <div class="sf-preview-placeholder">
                                    <p>
                                        <?= htmlspecialchars(
                                            sf_term('preview_2_not_generated', $currentUiLang)
                                            ?? 'Kortin 2 preview-kuvaa ei ole vielä generoitu.',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Kaksi latausnappia vierekkäin (EI dropdown) -->
                    <!-- Note: Already inside hasSecondCard block, but double-check both files exist -->
                    <?php if (!empty($flash['preview_filename']) && $previewUrl2): ?>
                    <div class="sf-download-buttons">
                        <a href="<?= htmlspecialchars($previewUrl) ?>" 
                           download="<?= htmlspecialchars(sf_generate_download_filename($flash, 1)) ?>"
                           class="sf-btn-download">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <polyline points="7 10 12 15 17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <line x1="12" y1="15" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span><?= htmlspecialchars(sf_term('card_1_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                        <a href="<?= htmlspecialchars($previewUrl2) ?>" 
                           download="<?= htmlspecialchars(sf_generate_download_filename($flash, 2)) ?>"
                           class="sf-btn-download">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <polyline points="7 10 12 15 17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <line x1="12" y1="15" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span><?= htmlspecialchars(sf_term('card_2_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- NORMAALI: Yksi preview-kuva (red/yellow tai green ilman toista korttia) -->
                    <?php if ($isPreviewPending): ?>
                        <!-- Skeleton placeholder when preview is being generated -->
                        <div class="skeleton-preview-placeholder">
                            <div class="skeleton-preview-box">
                                <div class="skeleton-preview-image skeleton"></div>
                            </div>
                            <div class="sf-preview-pending-message sf-generating-overlay">
                                <div class="sf-generating-content">
                                    <div class="sf-generating-spinner"></div>
                                    <div class="sf-generating-text"><?= htmlspecialchars(sf_term('preview_being_generated', $currentUiLang) ?: 'Esikatselukuvaa luodaan...', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="sf-preview-progress-wrap">
                                        <div class="sf-preview-progress-bar" style="width: 10%"></div>
                                    </div>
                                    <div class="sf-preview-progress-text">10%</div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
<div class="sf-preview-image-loader" data-sf-preview-loader>
    <div class="sf-preview-image-skeleton" aria-hidden="true">
        <div class="sf-preview-skeleton-line sf-preview-skeleton-line--top"></div>
        <div class="sf-preview-skeleton-card"></div>
        <div class="sf-preview-skeleton-line sf-preview-skeleton-line--bottom"></div>
    </div>

    <img src="<?= htmlspecialchars($previewUrl) ?>" alt="Preview"
         class="preview-image preview-image-clickable sf-preview-image-loading" id="viewPreviewImage"
         loading="eager"
         decoding="async"
         data-preview-fullscreen-trigger="true"
         data-preview-title="<?= htmlspecialchars(sf_term('preview_and_save', $currentUiLang) ?? 'Esikatselu', ENT_QUOTES, 'UTF-8') ?>"
         tabindex="0"
         role="button">
</div>
                    <?php endif; ?>

                    <?php if (!empty($flash['preview_filename']) && !$isPreviewPending): ?>
                        <div class="preview-download-wrapper">
                            <a href="<?= htmlspecialchars($previewUrl) ?>"
                               download="<?= htmlspecialchars(sf_generate_download_filename($flash)) ?>"
                               class="btn-download-preview"
                               title="<?= htmlspecialchars(sf_term('download_preview', $currentUiLang) ?? 'Lataa kuva', ENT_QUOTES, 'UTF-8') ?>">
                                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"
                                          stroke="currentColor" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                    <polyline points="7 10 12 15 17 10"
                                              stroke="currentColor" stroke-width="2"
                                              stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                    <line x1="12" y1="15" x2="12" y2="3"
                                          stroke="currentColor" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span>
                                    <?= htmlspecialchars(sf_term('download_preview', $currentUiLang) ?? 'Lataa JPG', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div> <!-- .preview-box -->

            <?php if (!empty($sfWorkflowPhases) && !empty($sfCurrentWorkflowPhase)): ?>
                <details class="sf-workflow-progress-card sf-workflow-progress-card--accordion view-box">
                    <summary class="sf-workflow-summary">
                        <span class="sf-workflow-summary-left">
                            <span class="sf-workflow-phase-number sf-workflow-phase-icon-wrap">
                                <img
                                    src="<?= htmlspecialchars((string)$sfCurrentWorkflowPhase['icon'], ENT_QUOTES, 'UTF-8') ?>"
                                    alt=""
                                    class="sf-workflow-phase-icon"
                                    loading="lazy"
                                >
                            </span>

                            <span class="sf-workflow-summary-text">
                                <span class="sf-workflow-summary-title">
                                    <?= htmlspecialchars((string)$sfCurrentWorkflowPhase['title'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </span>
                        </span>

                        <span class="sf-workflow-summary-status">
                            <span class="sf-workflow-status-label">
                                <?= htmlspecialchars(sf_term('view_status', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            </span>

<span class="sf-workflow-status-value sf-workflow-status-value--<?= htmlspecialchars($sfCurrentWorkflowStatusClass, ENT_QUOTES, 'UTF-8') ?> sf-workflow-status-value--<?= htmlspecialchars($sfCurrentWorkflowStatusToneClass, ENT_QUOTES, 'UTF-8') ?>">
                                <?php if ($sfCurrentWorkflowStatusClass === 'active'): ?>
                                    <span class="sf-workflow-step-spinner" aria-hidden="true"></span>
                                <?php else: ?>
                                    <span class="sf-workflow-step-check" aria-hidden="true">✓</span>
                                <?php endif; ?>

                                <span>
                                    <?= htmlspecialchars((string)$sfCurrentWorkflowStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </span>
                        </span>

                        <span class="sf-workflow-summary-arrow" aria-hidden="true"></span>
                    </summary>

                    <div class="sf-workflow-accordion-body">
                        <div class="sf-workflow-phase-list sf-workflow-phase-list--compact">
                            <?php foreach ($sfWorkflowPhases as $workflowPhase): ?>
                                <article class="sf-workflow-phase sf-workflow-phase--compact sf-workflow-phase--<?= htmlspecialchars($workflowPhase['key'], ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="sf-workflow-phase-meta">
                                        <div class="sf-workflow-phase-number sf-workflow-phase-icon-wrap">
                                            <img
                                                src="<?= htmlspecialchars((string)$workflowPhase['icon'], ENT_QUOTES, 'UTF-8') ?>"
                                                alt=""
                                                class="sf-workflow-phase-icon"
                                                loading="lazy"
                                            >
                                        </div>

                                        <div class="sf-workflow-phase-text">
                                            <div class="sf-workflow-phase-title-row">
                                                <h3 class="sf-workflow-phase-title">
                                                    <?= htmlspecialchars((string)$workflowPhase['title'], ENT_QUOTES, 'UTF-8') ?>
                                                </h3>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="sf-workflow-steps sf-workflow-steps--compact">
                                        <?php foreach ($workflowPhase['steps'] as $workflowStep): ?>
                                            <?php
                                            $stepState = (string)($workflowStep['state'] ?? 'pending');

                                            $stepStateText = $stepState === 'done'
                                                ? sf_term('workflow_tooltip_done', $currentUiLang)
                                                : ($stepState === 'active'
                                                    ? sf_term('workflow_tooltip_active', $currentUiLang)
                                                    : ($stepState === 'skipped'
                                                        ? sf_term('workflow_tooltip_skipped', $currentUiLang)
                                                        : sf_term('workflow_tooltip_pending', $currentUiLang)));

                                            $workflowTooltipTitle = (string)($workflowPhase['title'] ?? '') . ' • ' . (string)($workflowStep['label'] ?? '');

                                            $workflowTooltipTargetLabel = sf_term('workflow_tooltip_target', $currentUiLang);
                                            $workflowTooltipStartedLabel = sf_term('workflow_tooltip_started', $currentUiLang);
                                            $workflowTooltipHandledLabel = sf_term('workflow_tooltip_handled', $currentUiLang);

if ((string)($workflowStep['key'] ?? '') === 'created') {
    $workflowTooltipHandledLabel = sf_term('workflow_tooltip_created', $currentUiLang);
} elseif ((string)($workflowStep['key'] ?? '') === 'supervisor') {
    $workflowTooltipHandledLabel = sf_term('workflow_tooltip_approved', $currentUiLang);
} elseif ((string)($workflowStep['key'] ?? '') === 'safety') {
    $workflowTooltipHandledLabel = sf_term('workflow_tooltip_sent_to_comms', $currentUiLang);
} elseif (in_array((string)($workflowStep['key'] ?? ''), ['comms', 'published'], true)) {
    $workflowTooltipHandledLabel = sf_term('workflow_tooltip_published', $currentUiLang);
}
                                            $workflowTooltipHandledByLabel = sf_term('workflow_tooltip_handled_by', $currentUiLang);

$workflowStepKey = (string)($workflowStep['key'] ?? '');

$workflowTooltipTargetValue = (string)($workflowStep['target'] ?? '-');
$workflowTooltipStartedValue = (string)($workflowStep['started_at'] ?? '-');
$workflowTooltipHandledValue = (string)($workflowStep['completed_at'] ?? '-');

if ($workflowStepKey === 'published') {
    $workflowTooltipHandledLabel = sf_term('workflow_tooltip_published', $currentUiLang);
    $workflowTooltipHandledValue = $workflowTooltipHandledValue !== '-' && $workflowTooltipHandledValue !== ''
        ? $workflowTooltipHandledValue
        : $workflowTooltipStartedValue;
}

                                            $workflowTooltipHandledByValue = trim((string)($workflowStep['completed_by'] ?? '')) !== ''
                                                ? (string)$workflowStep['completed_by']
                                                : sf_term('workflow_tooltip_handled_by_missing', $currentUiLang);

                                            $workflowTooltipAria = $workflowTooltipTitle . '. ' .
                                                $stepStateText . '. ' .
                                                $workflowTooltipTargetLabel . ': ' . $workflowTooltipTargetValue . '. ' .
                                                $workflowTooltipStartedLabel . ': ' . $workflowTooltipStartedValue . '. ' .
                                                $workflowTooltipHandledLabel . ': ' . $workflowTooltipHandledValue . '. ' .
                                                $workflowTooltipHandledByLabel . ': ' . $workflowTooltipHandledByValue . '.';
                                            ?>
                                            <button
                                                type="button"
                                                class="sf-workflow-step sf-workflow-step--compact sf-workflow-step--<?= htmlspecialchars($stepState, ENT_QUOTES, 'UTF-8') ?>"
                                                aria-label="<?= htmlspecialchars($workflowTooltipAria, ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                                <span class="sf-workflow-step-marker" aria-hidden="true">
                                                    <?php if ($stepState === 'done'): ?>
                                                        <span class="sf-workflow-step-check">✓</span>
                                                    <?php elseif ($stepState === 'active'): ?>
                                                        <span class="sf-workflow-step-spinner"></span>
<?php elseif ($stepState === 'skipped'): ?>
    <span class="sf-workflow-step-skipped">⏭</span>
                                                    <?php else: ?>
                                                        <span class="sf-workflow-step-dot"></span>
                                                    <?php endif; ?>
                                                </span>

                                                <span class="sf-workflow-step-label">
                                                    <?= htmlspecialchars((string)$workflowStep['label'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>

                                                <span class="sf-workflow-tooltip" aria-hidden="true">
                                                    <span class="sf-workflow-tooltip-title">
                                                        <?= htmlspecialchars($workflowTooltipTitle, ENT_QUOTES, 'UTF-8') ?>
                                                    </span>

                                                    <span class="sf-workflow-tooltip-state sf-workflow-tooltip-state--<?= htmlspecialchars($stepState, ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($stepStateText, ENT_QUOTES, 'UTF-8') ?>
                                                    </span>

                                                    <?php if ($workflowStepKey !== 'published'): ?>
    <span class="sf-workflow-tooltip-row">
        <span class="sf-workflow-tooltip-key">
            <?= htmlspecialchars($workflowTooltipTargetLabel, ENT_QUOTES, 'UTF-8') ?>
        </span>
        <span class="sf-workflow-tooltip-value">
            <?= htmlspecialchars($workflowTooltipTargetValue, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </span>

    <span class="sf-workflow-tooltip-row">
        <span class="sf-workflow-tooltip-key">
            <?= htmlspecialchars($workflowTooltipStartedLabel, ENT_QUOTES, 'UTF-8') ?>
        </span>
        <span class="sf-workflow-tooltip-value">
            <?= htmlspecialchars($workflowTooltipStartedValue, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </span>
<?php endif; ?>

                                                    <span class="sf-workflow-tooltip-row">
                                                        <span class="sf-workflow-tooltip-key">
                                                            <?= htmlspecialchars($workflowTooltipHandledLabel, ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                        <span class="sf-workflow-tooltip-value">
                                                            <?= htmlspecialchars($workflowTooltipHandledValue, ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    </span>

                                                    <span class="sf-workflow-tooltip-row">
                                                        <span class="sf-workflow-tooltip-key">
                                                            <?= htmlspecialchars($workflowTooltipHandledByLabel, ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                        <span class="sf-workflow-tooltip-value">
                                                            <?= htmlspecialchars($workflowTooltipHandledByValue, ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    </span>
                                                </span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
            <?php endif; ?>

            <!-- KOMMENTIT JA TAPAHTUMALOKI TAB-NÄKYMÄ -->
            <div class="sf-view-activity-section view-box">
                <div class="sf-activity-tabs">
    <button class="sf-activity-tab active" data-tab="comments" data-sf-analytics-click="view_tab_comments_open" data-sf-analytics-source="view_activity_tabs">
        <img src="<?= $base ?>/assets/img/icons/comment.svg" alt="" class="sf-tab-icon">
        <span><?= htmlspecialchars(sf_term('activity_tab_comments', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
        <span class="sf-tab-badge" id="commentCount">0</span>
    </button>
    <button class="sf-activity-tab" data-tab="events" data-sf-analytics-click="view_tab_events_open" data-sf-analytics-source="view_activity_tabs">
        <img src="<?= $base ?>/assets/img/icons/timeline.svg" alt="" class="sf-tab-icon">
        <span><?= htmlspecialchars(sf_term('activity_tab_events', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
    </button>
    <button class="sf-activity-tab" data-tab="additionalInfo" data-sf-analytics-click="view_tab_additional_info_open" data-sf-analytics-source="view_activity_tabs">
        <img src="<?= $base ?>/assets/img/icons/list.svg" alt="" class="sf-tab-icon">
        <span><?= htmlspecialchars(sf_term('additional_info_tab_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
    </button>
    <button class="sf-activity-tab" data-tab="versions" data-sf-analytics-click="view_tab_versions_open" data-sf-analytics-source="view_activity_tabs">
        <img src="<?= $base ?>/assets/img/icons/version-document.svg" alt="" class="sf-tab-icon">
        <span><?= htmlspecialchars(sf_term('activity_tab_versions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
    </button>
    <button class="sf-activity-tab" data-tab="images" id="imagesTabBtn" data-sf-analytics-click="view_tab_media_open" data-sf-analytics-source="view_activity_tabs">
        <img src="<?= $base ?>/assets/img/icons/image.svg" alt="" class="sf-tab-icon">
        <span><?= htmlspecialchars(sf_term('activity_tab_images', $currentUiLang) ?: 'Media', ENT_QUOTES, 'UTF-8') ?></span>
    </button>
</div>

                <!-- KOMMENTIT TAB -->
                <div class="sf-tab-content active" id="tabComments">
                    <div class="sf-comments-container">

                        <form
                            method="post"
                            action="<?= htmlspecialchars($base) ?>/app/actions/comment.php?id=<?= (int)$id ?>"
                            class="sf-quick-comment-form"
                        >
                            <?= sf_csrf_field() ?>

<input type="hidden" name="comment_notifications_enabled" value="0">

                            <div class="sf-quick-comment-avatar">
                                <?= htmlspecialchars(sf_avatar_initials(trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>
                            </div>

                            <div class="sf-quick-comment-main">
<textarea
    id="quickCommentMessage"
    name="message"
    rows="1"
    maxlength="2000"
    class="sf-quick-comment-input"
    placeholder="<?= htmlspecialchars(sf_term('modal_comment_placeholder', $currentUiLang) ?: 'Kirjoita kommentti...', ENT_QUOTES, 'UTF-8') ?>"
    aria-label="<?= htmlspecialchars(sf_term('modal_comment_label', $currentUiLang) ?: 'Kommentti', ENT_QUOTES, 'UTF-8') ?>"
    autocomplete="off"
    required
></textarea>

                                <div class="sf-quick-comment-footer">
                                    <div class="sf-comment-toggle-wrap sf-comment-toggle-wrap--quick">
                                        <span class="sf-comment-toggle-text">
                                            <?= htmlspecialchars(sf_term('comment_email_subscribe', $_SESSION['ui_lang'] ?? 'fi'), ENT_QUOTES, 'UTF-8') ?>
                                        </span>

                                        <label class="sf-switch" for="commentNotifyInline">
<input
    type="checkbox"
    id="commentNotifyInline"
    name="comment_notifications_enabled"
    value="1"
    <?= !empty($commentNotificationsChecked) ? 'checked' : '' ?>
>
                                            <span class="sf-switch-slider"></span>
                                        </label>
                                    </div>

                                    <button type="submit" class="sf-quick-comment-submit">
                                        <?= htmlspecialchars(sf_term('btn_comment_send', $currentUiLang) ?: 'Lähetä', ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                </div>
                            </div>
                        </form>

                        <?php
                        // Suodata vain kommentit ja julkaisuun liittyvät kommenttilokit.
                        // Piilotetaan vanhat automaattiset "Lähetetty viestintään" -duplikaatit,
                        // koska sama sisältö näytetään nykyisin sent_to_comms-lokin Julkaisuohjeet-rivillä.
                        $comments = array_filter($logs, function($log) {
                            $eventType = $log['event_type'] ?? '';
                            $description = trim((string)($log['description'] ?? ''));

                            if (
                                $eventType === 'comment_added'
                                && preg_match('/^log_comment_label:\s*log_sent_to_comms:/u', $description)
                            ) {
                                return false;
                            }

                            return in_array($eventType, ['comment_added', 'submission_comment', 'language_review_comment', 'sent_to_comms'], true);
                        });
                        
                        if (empty($comments)):
                        ?>
                            <div class="sf-empty-state">
                                <img src="<?= $base ?>/assets/img/icons/no-comments.svg" alt="" class="sf-empty-icon">
                                <p><?= htmlspecialchars(sf_term('comments_empty', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php else:
                            // Group comments by parent
                            $topLevelComments = [];
                            $repliesByParent = [];
                            
                            foreach ($comments as $comment) {
                                $parentId = $comment['parent_id'] ?? null;
                                if ($parentId) {
                                    if (!isset($repliesByParent[$parentId])) {
                                        $repliesByParent[$parentId] = [];
                                    }
                                    $repliesByParent[$parentId][] = $comment;
                                } else {
                                    $topLevelComments[] = $comment;
                                }
                            }
                            
                            // Function to render a single comment
                            function renderComment($comment, $repliesByParent, $currentUserId, $isAdmin, $currentUiLang, $base, $currentFlashType = '', $isReply = false) {
                                $first = trim((string)($comment['first_name'] ?? ''));
                                $last = trim((string)($comment['last_name'] ?? ''));
                                $fullName = trim($first . ' ' . $last);
                                $avatarInitials = sf_avatar_initials($fullName);
                                
                                $eventType = $comment['event_type'] ?? '';

                                // Parse kommentti description-kentästä
                                $descRaw = (string)($comment['description'] ?? '');

                                $isWorkflowCommentAdded = (
                                    $eventType === 'comment_added'
                                    && preg_match('/Työmaavastaava hyväksyi:/u', $descRaw)
                                );

                                $isSubmissionComment = (
                                    in_array($eventType, ['submission_comment', 'sent_to_comms'], true)
                                    || $isWorkflowCommentAdded
                                );

                                $isLanguageReviewComment = ($eventType === 'language_review_comment');

                                $commentFlashType = '';
                                if (preg_match('/^log_comment_type:\s*(red|yellow|green)\s*$/mi', $descRaw, $typeMatch)) {
                                    $commentFlashType = strtolower(trim((string)$typeMatch[1]));
                                    $descRaw = preg_replace('/^log_comment_type:\s*(red|yellow|green)\s*$/mi', '', $descRaw);
                                    $descRaw = trim((string)$descRaw);
                                }

                                $commentFlashTypeLabels = [
                                    'red' => sf_term('first_release', $currentUiLang),
                                    'yellow' => sf_term('dangerous_situation', $currentUiLang),
                                    'green' => sf_term('investigation_report', $currentUiLang),
                                ];
                                if (
                                    $commentFlashType === ''
                                    && (
                                        in_array($eventType, ['submission_comment', 'sent_to_comms', 'language_review_comment'], true)
                                        || $isWorkflowCommentAdded
                                    )
                                ) {
                                    $commentFlashType = (string)$currentFlashType;
                                }

                                $commentFlashTypeLabel = $commentFlashTypeLabels[$commentFlashType] ?? '';

                                $commentText = '';
                                $commentStructuredRows = [];
                                $commentSourceText = $descRaw;

                                $formatCommentLanguageList = static function (string $languageValue) use ($currentUiLang): string {
    $languageTermKeys = [
        'fi' => 'language_review_language_fi',
        'sv' => 'language_review_language_sv',
        'en' => 'language_review_language_en',
        'it' => 'language_review_language_it',
        'el' => 'language_review_language_el',
    ];

    $parts = array_filter(array_map('trim', explode(',', $languageValue)));

    $formatted = array_map(static function (string $part) use ($languageTermKeys, $currentUiLang): string {
        $code = strtolower(trim($part));
        $termKey = $languageTermKeys[$code] ?? null;

        return $termKey ? sf_term($termKey, $currentUiLang) : strtoupper($part);
    }, $parts);

    return implode(', ', $formatted);
};

                                if (preg_match('/log_comment_label:\s*(.+)/is', $descRaw, $match)) {
                                    $commentSourceText = trim($match[1]);
                                }

                                $commentSourceText = str_replace(["<br />", "<br/>", "<br>"], "\n", $commentSourceText);
                                $commentSourceText = preg_replace('/\R/u', "\n", $commentSourceText);
                                $commentSourceText = trim((string)$commentSourceText);

                                if (preg_match('/^log_sent_to_comms:\s*(.+)$/su', $commentSourceText, $sentMatch)) {
                                    $commentSourceText = trim($sentMatch[1]);
                                }

                                $commentText = $commentSourceText;

                                $commentLines = array_values(array_filter(array_map('trim', explode("\n", $commentSourceText)), static function ($line) {
                                    return $line !== '';
                                }));

                                $shouldRenderStructuredComment = (
                                    $eventType === 'sent_to_comms'
                                    || count($commentLines) > 1
                                );

                                if ($shouldRenderStructuredComment) {
                                    foreach ($commentLines as $commentLine) {
                                        if ($commentLine === '') {
                                            continue;
                                        }

                                        if (preg_match('/^log_status_set\|status:(\w+)$/u', $commentLine)) {
                                            continue;
                                        }

                                        if (preg_match('/^log_status_set:\s*(.+)$/u', $commentLine)) {
                                            continue;
                                        }

                                        if (preg_match('/^log_message_to_comms_label:\s*(.+)$/u', $commentLine, $matches)) {
                                            $commentStructuredRows[] = [
                                                'label' => sf_term('log_message_to_comms_label', $currentUiLang),
                                                'value' => trim($matches[1]),
                                            ];
                                            continue;
                                        }

                                        if (preg_match('/^(email_selected_languages|log_selected_languages):\s*(.+)$/u', $commentLine, $matches)) {
                                            $commentStructuredRows[] = [
                                                'label' => sf_term('email_selected_languages', $currentUiLang),
                                                'value' => $formatCommentLanguageList(trim($matches[2])),
                                            ];
                                            continue;
                                        }

                                        if (preg_match('/^(email_selected_worksites|log_selected_displays|log_selected_worksites):\s*(.+)$/u', $commentLine, $matches)) {
                                            $commentStructuredRows[] = [
                                                'label' => sf_term('log_selected_displays', $currentUiLang),
                                                'value' => trim($matches[2]),
                                            ];
                                            continue;
                                        }

                                        if (preg_match('/^(Julkaisuohjeet|Valitut kieliversiot|Valitut infonäytöt):\s*(.+)$/u', $commentLine, $matches)) {
                                            $structuredLabel = trim($matches[1]);
                                            $structuredValue = trim($matches[2]);

                                            if ($structuredLabel === 'Valitut kieliversiot') {
                                                $structuredValue = $formatCommentLanguageList($structuredValue);
                                            }

                                            $commentStructuredRows[] = [
                                                'label' => $structuredLabel,
                                                'value' => $structuredValue,
                                            ];
                                            continue;
                                        }

                                        if ($commentLine === 'email_no_distribution' || $commentLine === 'Ei laajempaa jakelua') {
    $commentStructuredRows[] = [
        'label' => sf_term('email_distribution_label', $currentUiLang),
        'value' => sf_term('email_no_distribution', $currentUiLang),
    ];
    continue;
}

if ($commentLine === 'email_wider_distribution_yes') {
    $commentStructuredRows[] = [
        'label' => sf_term('email_distribution_label', $currentUiLang),
        'value' => sf_term('email_wider_distribution_yes', $currentUiLang),
    ];
    continue;
}

if (
    $commentLine === 'log_worksite_notification_preselected_yes'
    || $commentLine === 'Työmaakohtainen sähköposti-ilmoitus esivalittu lähetettäväksi julkaisun yhteydessä'
) {
    $commentStructuredRows[] = [
        'label' => sf_term('worksite_notification_label', $currentUiLang),
        'value' => sf_term('log_worksite_notification_preselected_yes', $currentUiLang),
    ];
    continue;
}

if ($commentLine === 'log_worksite_notification_preselected_no') {
    $commentStructuredRows[] = [
        'label' => sf_term('worksite_notification_label', $currentUiLang),
        'value' => sf_term('log_worksite_notification_preselected_no', $currentUiLang),
    ];
    continue;
}

                                        if (preg_match('/^([^:]+):\s*(.+)$/u', $commentLine, $matches)) {
                                            $commentStructuredRows[] = [
                                                'label' => trim($matches[1]),
                                                'value' => trim($matches[2]),
                                            ];
                                            continue;
                                        }

                                        $translatedLine = sf_term($commentLine, $currentUiLang);
                                        $commentStructuredRows[] = [
                                            'label' => '',
                                            'value' => $translatedLine !== $commentLine ? $translatedLine : $commentLine,
                                        ];
                                    }

                                    $plainRows = [];
                                    foreach ($commentStructuredRows as $structuredRow) {
                                        $label = trim((string)($structuredRow['label'] ?? ''));
                                        $value = trim((string)($structuredRow['value'] ?? ''));

                                        if ($label !== '' && $value !== '') {
                                            $plainRows[] = $label . ': ' . $value;
                                        } elseif ($value !== '') {
                                            $plainRows[] = $value;
                                        }
                                    }

                                    $commentText = implode("\n", $plainRows);
                                } elseif (preg_match('/^(log_\w+):\s*(.*)$/su', $commentText, $nestedMatch)) {
                                    $nestedKey = $nestedMatch[1];
                                    $nestedValue = trim($nestedMatch[2]);
                                    $nestedTerm = sf_term($nestedKey, $currentUiLang);

                                    if ($nestedTerm !== $nestedKey) {
                                        $commentText = $nestedValue !== ''
                                            ? $nestedTerm . ': ' . $nestedValue
                                            : $nestedTerm;
                                    }
                                } elseif (preg_match('/^(log_\w+)$/su', $commentText, $plainLogMatch)) {
                                    $plainKey = $plainLogMatch[1];
                                    $plainTerm = sf_term($plainKey, $currentUiLang);

                                    if ($plainTerm !== $plainKey) {
                                        $commentText = $plainTerm;
                                    }
                                }


                                // Relatiivinen aika
                                $timeAgo = sf_time_ago($comment['created_at'], $currentUiLang);
                                
                                $isOwnComment = ($comment['user_id'] ?? 0) == ($currentUserId ?? 0);
$replyClass = $isReply ? 'sf-comment-reply' : '';
$parentIdAttr = !empty($comment['parent_id']) ? ' data-parent-id="' . (int)$comment['parent_id'] . '"' : '';

$likeCount = (int)($comment['like_count'] ?? 0);
$currentUserLiked = !empty($comment['current_user_liked']);
$likeNames = trim((string)($comment['like_names'] ?? ''));
$likeTitle = $likeNames !== ''
    ? $likeNames
    : sf_term('comment_like', $currentUiLang);
                            ?>
                                <div class="sf-comment-item <?= $isOwnComment ? 'sf-comment-own' : '' ?> <?= $replyClass ?>" data-comment-id="<?= (int)$comment['id'] ?>"<?= $parentIdAttr ?>>
                                    <div class="sf-comment-avatar" data-name="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($avatarInitials, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="sf-comment-content">
                                        <div class="sf-comment-header">
                                            <span class="sf-comment-author"><?= htmlspecialchars($fullName ?: 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($commentFlashTypeLabel !== ''): ?>
                                                <span class="sf-comment-badge sf-comment-type-badge sf-comment-type-badge--<?= htmlspecialchars($commentFlashType, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($commentFlashTypeLabel, ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($isSubmissionComment): ?>
                                                <span class="sf-comment-badge sf-badge-submission">
                                                    <img src="assets/img/icons/information.svg" alt="">
                                                    <?php if ($eventType === 'sent_to_comms'): ?>
                                                        <?= htmlspecialchars(sf_term('log_message_to_comms_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars(sf_term('submission_comment_badge', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($isLanguageReviewComment): ?>
                                                <span class="sf-comment-badge sf-badge-language-review">
                                                    <img src="assets/img/icons/translate_icon.svg" alt="">
                                                    <?= htmlspecialchars(sf_term('language_review_comment_badge', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="sf-comment-time" title="<?= htmlspecialchars($comment['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($timeAgo, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                        <div class="sf-comment-body">
                                            <?php
                                            $commentRowsToRender = $commentStructuredRows;

                                            if (empty($commentRowsToRender)) {
                                                $fallbackLines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$commentText)), static function ($line) {
                                                    return $line !== '';
                                                }));

                                                if (count($fallbackLines) > 1) {
                                                    foreach ($fallbackLines as $fallbackLine) {
                                                        if (preg_match('/^([^:]{2,90}):\s*(.*)$/u', $fallbackLine, $lineMatch)) {
                                                            $commentRowsToRender[] = [
                                                                'label' => trim($lineMatch[1]),
                                                                'value' => trim($lineMatch[2]),
                                                            ];
                                                        } else {
                                                            $commentRowsToRender[] = [
                                                                'label' => '',
                                                                'value' => $fallbackLine,
                                                            ];
                                                        }
                                                    }
                                                }
                                            }
                                            ?>

                                            <?php if (!empty($commentRowsToRender)): ?>
                                                <ul class="sf-comment-structured-list">
                                                    <?php foreach ($commentRowsToRender as $structuredRow): ?>
                                                        <?php
                                                        $structuredLabel = trim((string)($structuredRow['label'] ?? ''));
                                                        $structuredValue = trim((string)($structuredRow['value'] ?? ''));
                                                        ?>
                                                        <?php if ($structuredLabel !== '' || $structuredValue !== ''): ?>
                                                            <li>
                                                                <?php if ($structuredLabel !== ''): ?>
                                                                    <strong><?= htmlspecialchars($structuredLabel, ENT_QUOTES, 'UTF-8') ?>:</strong>
                                                                <?php endif; ?>
                                                                <?php if ($structuredValue !== ''): ?>
                                                                    <span><?= htmlspecialchars($structuredValue, ENT_QUOTES, 'UTF-8') ?></span>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <?= nl2br(htmlspecialchars($commentText, ENT_QUOTES, 'UTF-8')) ?>
                                            <?php endif; ?>
                                        </div>

<div class="sf-comment-footer">
    <?php if (!$isSubmissionComment): ?>
        <div class="sf-comment-actions">
            <button type="button" class="sf-comment-action-btn btn-reply-comment" data-comment-id="<?= (int)$comment['id'] ?>">
                <img src="<?= $base ?>/assets/img/icons/reply.svg" alt="" class="sf-action-icon">
                <?= htmlspecialchars(sf_term('comment_reply', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php if ($isOwnComment || $isAdmin): ?>
                <button type="button" class="sf-comment-action-btn btn-edit-comment" data-comment-id="<?= (int)$comment['id'] ?>">
                    <img src="<?= $base ?>/assets/img/icons/create.svg" alt="" class="sf-action-icon">
                    <?= htmlspecialchars(sf_term('comment_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="button" class="sf-comment-action-btn btn-delete-comment sf-text-danger" data-comment-id="<?= (int)$comment['id'] ?>">
                    <img src="<?= $base ?>/assets/img/icons/delete.svg" alt="" class="sf-action-icon">
                    <?= htmlspecialchars(sf_term('comment_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="sf-comment-actions"></div>
    <?php endif; ?>

    <button
        type="button"
        class="sf-comment-like-btn<?= $currentUserLiked ? ' is-liked' : '' ?>"
        data-comment-id="<?= (int)$comment['id'] ?>"
        data-liked="<?= $currentUserLiked ? '1' : '0' ?>"
        title="<?= htmlspecialchars($likeTitle, ENT_QUOTES, 'UTF-8') ?>"
        aria-label="<?= htmlspecialchars(sf_term('comment_like', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
    >
        <span class="sf-comment-like-icon" aria-hidden="true">👍</span>
        <span class="sf-comment-like-count"><?= $likeCount ?></span>
    </button>
</div>
                                    </div>
                                </div>
                            <?php
                                // Render replies recursively
                                if (isset($repliesByParent[$comment['id']])) {
                                    foreach ($repliesByParent[$comment['id']] as $reply) {
                                        renderComment($reply, $repliesByParent, $currentUserId, $isAdmin, $currentUiLang, $base, (string)($flash['type'] ?? ''), true);
                                    }
                                }
                            }
                        ?>
                            <div class="sf-comments-list">
                                <?php
                                // Render all top-level comments with their replies
                                foreach ($topLevelComments as $topComment) {
                                    renderComment($topComment, $repliesByParent, $currentUserId, $isAdmin, $currentUiLang, $base, (string)($flash['type'] ?? ''), false);
                                }
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- TAPAHTUMAT TAB -->
                <div class="sf-tab-content" id="tabEvents">
                    <div class="sf-events-timeline">
                        <?php
                        // Helper function to translate flash type names
                        function sf_translate_flash_type($typeKey, $lang) {
                            $typeMap = [
                                'red' => 'first_release',
                                'yellow' => 'dangerous_situation',
                                'green' => 'investigation_report',
                            ];
                            
                            $termKey = $typeMap[$typeKey] ?? null;
                            return $termKey ? sf_term($termKey, $lang) : $typeKey;
                        }
                        
// Suodata tapahtumat: ei kommentteja eikä teknisiä tilamuutoksia,
// jotka näkyvät jo varsinaisena toimintotapahtumana.
$events = array_filter($logs, function($log) {
    $eventType = (string)($log['event_type'] ?? '');
    $description = (string)($log['description'] ?? '');

    if (in_array($eventType, [
        'comment_added',
        'language_review_comment',
        'submission_comment',
        'display_targets_preselected',
    ], true)) {
        return false;
    }

    if ($eventType === 'state_changed') {
        if (
            stripos($description, 'info_requested') !== false
            || stripos($description, 'Lisätietoa pyydetty') !== false
            || stripos($description, 'returned_for_corrections') !== false
            || stripos($description, 'returned_to_supervisor') !== false
            || stripos($description, 'palautettu') !== false
            || stripos($description, 'korjattavaksi') !== false
        ) {
            return false;
        }

        if (
            stripos($description, 'awaiting_publish') !== false
            || stripos($description, 'Odottaa julkaisua') !== false
        ) {
            return false;
        }
    }

    return true;
});
                        $events = array_values($events);

                        // Ryhmittele tapahtumat batch_id:n mukaan
                        // Vanhat lokit ilman batch_id:tä ryhmitellään aikaleiman perusteella (±2s, sama käyttäjä)
                        $batchGroups  = []; // batch_id → [events]
                        $noIdGroups   = []; // array of [events] arrays (aika-ryhmitellyt)
                        // Toleranssi vanhoille lokeille ilman batch_id:tä (sekunteina)
                        $legacyGroupingWindowSeconds = 2;

foreach ($events as $event) {
    $bid = $event['batch_id'] ?? null;
    $eventLogFlashId = (int)($event['log_flash_id'] ?? 0);
    $eventDescriptionForGroup = (string)($event['description'] ?? '');
    $eventLogLangForGroup = strtoupper(trim((string)($event['log_lang'] ?? '')));

    $eventTypeForSemanticGroup = (string)($event['event_type'] ?? '');
    $eventUserForSemanticGroup = (string)($event['user_id'] ?? '');
    $eventMinuteForSemanticGroup = date('Y-m-d H:i', strtotime((string)($event['created_at'] ?? 'now')));

    $isInvestigationArchiveSemanticEvent =
        $eventTypeForSemanticGroup === 'original_archived'
        || stripos($eventDescriptionForGroup, 'Alkuperäinen sisältö arkistoitu') !== false
        || stripos($eventDescriptionForGroup, 'Original content archived') !== false;

    $isInvestigationCreatedSemanticEvent =
        in_array($eventTypeForSemanticGroup, ['investigation_created', 'original_type_changed'], true)
        || stripos($eventDescriptionForGroup, 'Tutkintatiedote luotu') !== false
        || stripos($eventDescriptionForGroup, 'original_type_changed') !== false
        || stripos($eventDescriptionForGroup, 'Tyyppi:') !== false
        || stripos($eventDescriptionForGroup, 'type:') !== false;

    $isInvestigationSubmittedSemanticEvent =
        $eventTypeForSemanticGroup === 'state_changed'
        && (
            stripos($eventDescriptionForGroup, 'Työmaavastaavan tarkistuksessa') !== false
            || stripos($eventDescriptionForGroup, 'pending_supervisor') !== false
        );

    if (
        (string)($flash['type'] ?? '') === 'green'
        && (
            $isInvestigationArchiveSemanticEvent
            || $isInvestigationCreatedSemanticEvent
            || $isInvestigationSubmittedSemanticEvent
        )
    ) {
        $bid = 'semantic|investigation_created_and_submitted|' . $eventLogFlashId . '|' . $eventUserForSemanticGroup . '|' . $eventMinuteForSemanticGroup;
    }

    $eventLanguageForGroup = '';

    if (preg_match('/log_language_version:\s*(FI|EN|SV|IT|EL)/i', $eventDescriptionForGroup, $eventLanguageGroupMatches)) {
        $eventLanguageForGroup = strtoupper($eventLanguageGroupMatches[1]);
    } elseif (preg_match('/Kieliversio luotu(?:\s*\(bundle\))?:\s*(fi|sv|en|it|el)/iu', $eventDescriptionForGroup, $eventCreatedLanguageGroupMatches)) {
        $eventLanguageForGroup = strtoupper($eventCreatedLanguageGroupMatches[1]);
    } elseif (in_array($eventLogLangForGroup, ['FI', 'SV', 'EN', 'IT', 'EL'], true)) {
        $eventLanguageForGroup = $eventLogLangForGroup;
    }

    $isWorksiteNotificationEventForLanguageGroup =
        $eventTypeForSemanticGroup === 'worksite_notification_sent'
        || stripos($eventDescriptionForGroup, 'Työmaailmoitus lähetetty') !== false
        || stripos($eventDescriptionForGroup, 'worksite notification sent') !== false
        || stripos($eventDescriptionForGroup, 'log_worksite_notification_sent') !== false;

    if ($eventLanguageForGroup === '' && $isWorksiteNotificationEventForLanguageGroup) {
        foreach ($events as $publishedEventForLanguage) {
            $publishedEventTypeForLanguage = (string)($publishedEventForLanguage['event_type'] ?? '');

            if ($publishedEventTypeForLanguage !== 'published') {
                continue;
            }

            if ((string)($publishedEventForLanguage['user_id'] ?? '') !== $eventUserForSemanticGroup) {
                continue;
            }

            $publishedEventMinuteForLanguage = date('Y-m-d H:i', strtotime((string)($publishedEventForLanguage['created_at'] ?? 'now')));

            if ($publishedEventMinuteForLanguage !== $eventMinuteForSemanticGroup) {
                continue;
            }

            $publishedEventDescriptionForLanguage = (string)($publishedEventForLanguage['description'] ?? '');

            if (preg_match('/log_language_version:\s*(FI|EN|SV|IT|EL)/i', $publishedEventDescriptionForLanguage, $publishedLanguageForWorksiteMatch)) {
                $eventLanguageForGroup = strtoupper($publishedLanguageForWorksiteMatch[1]);
                break;
            }
        }
    }

if (!empty($bid)) {
        $eventTypeForBatch = (string)($event['event_type'] ?? '');
        $eventDescriptionForBatch = (string)($event['description'] ?? '');
        $eventUserForBatch = (string)($event['user_id'] ?? '');
        $eventMinuteForBatch = date('Y-m-d H:i', strtotime((string)($event['created_at'] ?? 'now')));

        if (strpos((string)$bid, 'semantic|investigation_created_and_submitted|') === 0) {
            $batchKey = (string)$bid;
        } elseif (in_array($eventTypeForBatch, ['created', 'CREATED'], true)) {
            $batchKey = $bid . '|created';
        } elseif (
            $eventTypeForBatch === 'language_review_requested'
            || (
                $eventTypeForBatch === 'state_changed'
                && (
                    stripos($eventDescriptionForBatch, '→ awaiting_publish') !== false
                    || stripos($eventDescriptionForBatch, '-> awaiting_publish') !== false
                )
            )
        ) {
            $batchKey = 'semantic|language_review_requested|' . $eventUserForBatch . '|' . $eventMinuteForBatch;
} elseif (
    in_array($eventTypeForBatch, ['published', 'worksite_notification_sent'], true)
    || stripos($eventDescriptionForBatch, 'log_status_set: published') !== false
    || stripos($eventDescriptionForBatch, '→ published') !== false
    || stripos($eventDescriptionForBatch, '-> published') !== false
) {
    $batchKey = 'semantic|published|' . $eventUserForBatch . '|' . $eventMinuteForBatch . '|lang:' . $eventLanguageForGroup;
        } elseif (
            $eventTypeForBatch === 'state_changed'
            && stripos($eventDescriptionForBatch, 'pending_review') !== false
            && (
                stripos($eventDescriptionForBatch, 'to_comms') !== false
                || stripos($eventDescriptionForBatch, 'Viestinnällä') !== false
            )
        ) {
            $batchKey = 'semantic|safety_team_approved|' . $bid;
        } elseif ($eventTypeForBatch === 'sent_to_comms') {
            $batchKey = 'semantic|sent_to_comms|' . $bid;
        } elseif (
            $eventTypeForBatch === 'state_changed'
            && (
                preg_match('/log_state_changed:\s*[^\\n]*?(→|->)\s*pending_review/i', $eventDescriptionForBatch)
                || preg_match('/log_state_changed:\s*[^\\n]*?(→|->)\s*Turvatiimin tarkistuksessa/iu', $eventDescriptionForBatch)
            )
        ) {
            $batchKey = 'semantic|sent_to_safety_team|' . $bid;
        } elseif ($eventTypeForBatch === 'state_changed') {
            $batchKey = $bid . '|state_changed';
        } else {
            $batchKey = $bid;
        }

        $batchGroups[$batchKey][] = $event;
    } else {
        $matched = false;

        foreach ($noIdGroups as &$group) {
            $firstTs = strtotime($group[0]['created_at'] ?? '');
            $thisTs = strtotime($event['created_at'] ?? '');

            $sameUser = (string)($group[0]['user_id'] ?? '') === (string)($event['user_id'] ?? '');
            $sameFlashVersion = (int)($group[0]['log_flash_id'] ?? 0) === $eventLogFlashId;
            $sameEventType = (string)($group[0]['event_type'] ?? '') === (string)($event['event_type'] ?? '');

            $groupFirstEventType = (string)($group[0]['event_type'] ?? '');
            $currentEventType = (string)($event['event_type'] ?? '');

            $investigationChainEventTypes = [
                'original_archived',
                'investigation_created',
                'original_type_changed',
                'state_changed',
            ];

            $isInvestigationChainGroup =
                in_array($groupFirstEventType, $investigationChainEventTypes, true)
                && in_array($currentEventType, $investigationChainEventTypes, true);

            $isSameMoment =
                $firstTs !== false
                && $thisTs !== false
                && abs($firstTs - $thisTs) <= $legacyGroupingWindowSeconds;

            if (
                $sameUser
                && $sameFlashVersion
                && $isSameMoment
                && (
                    $sameEventType
                    || $isInvestigationChainGroup
                )
            ) {
                $group[] = $event;
                $matched = true;
                break;
            }
        }

        unset($group);

        if (!$matched) {
            $noIdGroups[] = [$event];
        }
    }
}

                        // Yhdistä kaikki ryhmät yhdeksi listaksi.
                        $displayGroups = array_values($batchGroups);

                        foreach ($noIdGroups as $group) {
                            $displayGroups[] = $group;
                        }

                        $sfGroupContainsPublishedEvent = static function(array $group): bool {
                            foreach ($group as $item) {
                                $eventType = (string)($item['event_type'] ?? '');
                                $description = (string)($item['description'] ?? '');

                                if (
                                    $eventType === 'published'
                                    || stripos($description, 'log_status_set: published') !== false
                                    || stripos($description, '→ published') !== false
                                    || stripos($description, '-> published') !== false
                                    || stripos($description, '→ Julkaistu') !== false
                                ) {
                                    return true;
                                }
                            }

                            return false;
                        };

                        $sfGroupContainsWorksiteNotification = static function(array $group): bool {
                            foreach ($group as $item) {
                                $eventType = (string)($item['event_type'] ?? '');
                                $description = (string)($item['description'] ?? '');

                                if (
                                    $eventType === 'worksite_notification_sent'
                                    || stripos($description, 'Työmaailmoitus lähetetty') !== false
                                    || stripos($description, 'worksite notification sent') !== false
                                    || stripos($description, 'log_worksite_notification_sent') !== false
                                ) {
                                    return true;
                                }
                            }

                            return false;
                        };

                        $sfGroupLanguageCode = static function(array $group): string {
                            foreach ($group as $item) {
                                $description = (string)($item['description'] ?? '');

                                if (preg_match('/log_language_version:\s*(FI|SV|EN|IT|EL)/i', $description, $match)) {
                                    return strtoupper($match[1]);
                                }

                                $logLang = strtoupper(trim((string)($item['log_lang'] ?? '')));

                                if (in_array($logLang, ['FI', 'SV', 'EN', 'IT', 'EL'], true)) {
                                    return $logLang;
                                }
                            }

                            return '';
                        };

                        $sfGroupUserId = static function(array $group): string {
                            foreach ($group as $item) {
                                $userId = trim((string)($item['user_id'] ?? ''));

                                if ($userId !== '') {
                                    return $userId;
                                }
                            }

                            return '';
                        };

                        $sfGroupFlashIdForMerge = static function(array $group): int {
                            foreach ($group as $item) {
                                $flashId = (int)($item['log_flash_id'] ?? $item['flash_id'] ?? 0);

                                if ($flashId > 0) {
                                    return $flashId;
                                }
                            }

                            return 0;
                        };

                        $sfGroupMinute = static function(array $group): string {
                            foreach ($group as $item) {
                                $timestamp = strtotime((string)($item['created_at'] ?? ''));

                                if ($timestamp !== false) {
                                    return date('Y-m-d H:i', $timestamp);
                                }
                            }

                            return '';
                        };

                        $mergedWorksiteNotificationIndexes = [];

                        foreach ($displayGroups as $sourceIndex => $sourceGroup) {
                            if (!$sfGroupContainsWorksiteNotification($sourceGroup)) {
                                continue;
                            }

                            if ($sfGroupContainsPublishedEvent($sourceGroup) && $sfGroupLanguageCode($sourceGroup) !== '') {
                                continue;
                            }

                            $sourceUserId = $sfGroupUserId($sourceGroup);
                            $sourceFlashId = $sfGroupFlashIdForMerge($sourceGroup);
                            $sourceMinute = $sfGroupMinute($sourceGroup);

                            if ($sourceUserId === '' || $sourceFlashId <= 0 || $sourceMinute === '') {
                                continue;
                            }

                            $candidateIndexes = [];

                            foreach ($displayGroups as $targetIndex => $targetGroup) {
                                if ($targetIndex === $sourceIndex) {
                                    continue;
                                }

                                if (!$sfGroupContainsPublishedEvent($targetGroup)) {
                                    continue;
                                }

                                if ($sfGroupLanguageCode($targetGroup) === '') {
                                    continue;
                                }

                                if ($sfGroupUserId($targetGroup) !== $sourceUserId) {
                                    continue;
                                }

                                if ($sfGroupFlashIdForMerge($targetGroup) !== $sourceFlashId) {
                                    continue;
                                }

                                if ($sfGroupMinute($targetGroup) !== $sourceMinute) {
                                    continue;
                                }

                                $candidateIndexes[] = $targetIndex;
                            }

                            if (count($candidateIndexes) === 1) {
                                $targetIndex = $candidateIndexes[0];
                                $displayGroups[$targetIndex] = array_merge($displayGroups[$targetIndex], $sourceGroup);
                                $mergedWorksiteNotificationIndexes[$sourceIndex] = true;
                            }
                        }

                        if (!empty($mergedWorksiteNotificationIndexes)) {
                            $displayGroups = array_values(array_filter(
                                $displayGroups,
                                static function($group, $index) use ($mergedWorksiteNotificationIndexes) {
                                    return !isset($mergedWorksiteNotificationIndexes[$index]);
                                },
                                ARRAY_FILTER_USE_BOTH
                            ));
                        }

                        $sfEventGroupTime = static function(array $group): int {
                            $times = [];

                            foreach ($group as $item) {
                                $time = strtotime((string)($item['created_at'] ?? ''));

                                if ($time !== false) {
                                    $times[] = $time;
                                }
                            }

                            return !empty($times) ? max($times) : 0;
                        };

                        $sfEventGroupId = static function(array $group): int {
                            $ids = [];

                            foreach ($group as $item) {
                                $ids[] = (int)($item['id'] ?? 0);
                            }

                            return !empty($ids) ? max($ids) : 0;
                        };

                        $sfEventGroupBatchId = static function(array $group): string {
                            foreach ($group as $item) {
                                $batchId = trim((string)($item['batch_id'] ?? ''));

                                if ($batchId !== '') {
                                    return $batchId;
                                }
                            }

                            return '';
                        };

                        $sfEventGroupKind = static function(array $group): string {
                            $text = '';
                            $types = [];

                            foreach ($group as $item) {
                                $type = (string)($item['event_type'] ?? '');
                                $description = (string)($item['description'] ?? '');

                                $types[] = $type;
                                $text .= "\n" . $description;
                            }

                            $normalizedText = mb_strtolower($text, 'UTF-8');

                               if (
                                strpos($normalizedText, 'alkuperäinen sisältö arkistoitu') !== false
                                || strpos($normalizedText, 'original content archived') !== false
                            ) {
                                return 'original_archived';
                            }

                            if (
                                strpos($normalizedText, 'tutkintatiedote luotu') !== false
                                || strpos($normalizedText, 'original_type_changed') !== false
                            ) {
                                return 'investigation_created';
                            }

                            if (in_array('created', $types, true) || in_array('CREATED', $types, true)) {
                                return 'created';
                            }

                            if (
                                in_array('supervisor_approved', $types, true)
                                || strpos($normalizedText, 'työmaavastaava hyväksyi') !== false
                                || strpos($normalizedText, 'log_supervisor_approved') !== false
                            ) {
                                return 'supervisor_approved';
                            }

                            if (
                                strpos($normalizedText, 'pending_review') !== false
                                && (
                                    strpos($normalizedText, 'to_comms') !== false
                                    || strpos($normalizedText, 'viestinnällä') !== false
                                )
                            ) {
                                return 'safety_team_approved';
                            }

                            if (in_array('sent_to_comms', $types, true)) {
                                return 'sent_to_comms';
                            }

                            if (
                                strpos($normalizedText, 'status:pending_supervisor') !== false
                                || strpos($normalizedText, 'pending_supervisor') !== false
                                || strpos($normalizedText, 'työmaavastaavan tarkistuksessa') !== false
                            ) {
                                return 'submitted_to_supervisor';
                            }

                            if (
                                strpos($normalizedText, 'status:pending_review') !== false
                                || strpos($normalizedText, 'pending_review') !== false
                                || strpos($normalizedText, 'turvatiimin tarkistuksessa') !== false
                            ) {
                                return 'sent_to_safety_team';
                            }

                            if (in_array('language_review_requested', $types, true)) {
                                return 'language_review_requested';
                            }

                            if (
                                in_array('published', $types, true)
                                || in_array('worksite_notification_sent', $types, true)
                            ) {
                                return 'published';
                            }

                            return 'other';
                        };

                        $sfWorkflowOrder = [
                            'created' => 10,
                            'investigation_created' => 15,
                            'submitted_to_supervisor' => 20,
                            'supervisor_approved' => 30,
                            'sent_to_safety_team' => 40,
                            'safety_team_approved' => 50,
                            'sent_to_comms' => 60,
                            'language_review_requested' => 70,
                            'published' => 80,
                            'other' => 100,
                        ];

                        $sfGroupLogFlashId = static function(array $group): int {
                            foreach ($group as $item) {
                                $logFlashId = (int)($item['log_flash_id'] ?? 0);

                                if ($logFlashId > 0) {
                                    return $logFlashId;
                                }
                            }

                            return 0;
                        };

                        $sfGroupUserId = static function(array $group): int {
                            foreach ($group as $item) {
                                $userId = (int)($item['user_id'] ?? 0);

                                if ($userId > 0) {
                                    return $userId;
                                }
                            }

                            return 0;
                        };

                        $sfMergeWorkflowPairs = static function(array $groups) use ($sfEventGroupKind, $sfEventGroupTime, $sfGroupLogFlashId, $sfGroupUserId): array {
                            $mergedGroups = [];
                            $usedIndexes = [];

                            foreach ($groups as $index => $group) {
                                if (isset($usedIndexes[$index])) {
                                    continue;
                                }

                                $kind = $sfEventGroupKind($group);
                                $time = $sfEventGroupTime($group);
                                $flashId = $sfGroupLogFlashId($group);
                                $userId = $sfGroupUserId($group);

                                foreach ($groups as $candidateIndex => $candidateGroup) {
                                    if ($candidateIndex === $index || isset($usedIndexes[$candidateIndex])) {
                                        continue;
                                    }

                                    $candidateKind = $sfEventGroupKind($candidateGroup);
                                    $candidateTime = $sfEventGroupTime($candidateGroup);
                                    $candidateFlashId = $sfGroupLogFlashId($candidateGroup);
                                    $candidateUserId = $sfGroupUserId($candidateGroup);

                                    if ($time <= 0 || $candidateTime <= 0 || abs($time - $candidateTime) > 180) {
                                        continue;
                                    }

                                    $sameFlash = $flashId > 0 && $candidateFlashId > 0 && $flashId === $candidateFlashId;
                                    $sameUserAndMoment = $userId > 0 && $candidateUserId > 0 && $userId === $candidateUserId;

                                    $isCreatedAndSubmittedPair =
                                        in_array($kind, ['created', 'investigation_created', 'submitted_to_supervisor'], true)
                                        && in_array($candidateKind, ['created', 'investigation_created', 'submitted_to_supervisor'], true)
                                        && $kind !== $candidateKind
                                        && (
                                            in_array($kind, ['created', 'investigation_created'], true)
                                            || in_array($candidateKind, ['created', 'investigation_created'], true)
                                        );

                                    $isSupervisorAndSafetyTeamPair =
                                        in_array($kind, ['supervisor_approved', 'sent_to_safety_team'], true)
                                        && in_array($candidateKind, ['supervisor_approved', 'sent_to_safety_team'], true)
                                        && $kind !== $candidateKind;

                                    $isSafetyTeamAndPublishPair =
                                        in_array($kind, ['safety_team_approved', 'sent_to_comms'], true)
                                        && in_array($candidateKind, ['safety_team_approved', 'sent_to_comms'], true)
                                        && $kind !== $candidateKind;

                                    $isInvestigationCreatedAndSubmittedPair =
                                        in_array($kind, ['original_archived', 'investigation_created', 'submitted_to_supervisor'], true)
                                        && in_array($candidateKind, ['original_archived', 'investigation_created', 'submitted_to_supervisor'], true)
                                        && $kind !== $candidateKind;

                                    if (
                                        ($isInvestigationCreatedAndSubmittedPair && ($sameFlash || $sameUserAndMoment || abs($time - $candidateTime) <= 180))
                                        || ($isCreatedAndSubmittedPair && ($sameFlash || $sameUserAndMoment))
                                        || (($isSupervisorAndSafetyTeamPair || $isSafetyTeamAndPublishPair) && $sameFlash)
                                    ) {
                                        $group = array_merge($group, $candidateGroup);
                                        $usedIndexes[$candidateIndex] = true;
                                    }
                                }

                                $usedIndexes[$index] = true;
                                $mergedGroups[] = $group;
                            }

                            return array_values($mergedGroups);
                        };

                        $displayGroups = $sfMergeWorkflowPairs($displayGroups);

                        // Pääjärjestys: uusimmat tapahtumat ensin.
                        // Tässä ei käytetä koko listan workflow-lajittelua,
                        // koska se sotkee saman minuutin tapahtumat.
                        usort($displayGroups, static function($a, $b) use (
                            $sfEventGroupTime,
                            $sfEventGroupId
                        ) {
                            $timeA = $sfEventGroupTime($a);
                            $timeB = $sfEventGroupTime($b);

                            if ($timeA !== $timeB) {
                                return $timeB <=> $timeA;
                            }

                            return $sfEventGroupId($b) <=> $sfEventGroupId($a);
                        });

                        // Korjaa vain tarkat workflow-parit, jos ne ovat vierekkäin väärässä järjestyksessä.
                        // Tämä ei poista, yhdistä eikä siirrä muita tapahtumia.
                        $sfWorkflowValue = static function(array $group): int {
                            $values = array_map(static function($item) {
                                return (int)($item['workflow_order'] ?? 100);
                            }, $group);

                            return !empty($values) ? min($values) : 100;
                        };

                        $changed = true;

                        while ($changed) {
                            $changed = false;
                            $count = count($displayGroups);

                            for ($i = 0; $i < $count - 1; $i++) {
                                $currentOrder = $sfWorkflowValue($displayGroups[$i]);
                                $nextOrder = $sfWorkflowValue($displayGroups[$i + 1]);

                                $currentLogFlashId = $sfGroupLogFlashId($displayGroups[$i]);
                                $nextLogFlashId = $sfGroupLogFlashId($displayGroups[$i + 1]);

                                $sameLogFlash = $currentLogFlashId > 0
                                    && $nextLogFlashId > 0
                                    && $currentLogFlashId === $nextLogFlashId;

                                $isWrongPair = $sameLogFlash && (
                                    ($currentOrder === 20 && $nextOrder === 10) ||
                                    ($currentOrder === 40 && $nextOrder === 30) ||
                                    ($currentOrder === 60 && $nextOrder === 50)
                                );

                                if ($isWrongPair) {
                                    $tmp = $displayGroups[$i];
                                    $displayGroups[$i] = $displayGroups[$i + 1];
                                    $displayGroups[$i + 1] = $tmp;
                                    $changed = true;
                                    break;
                                }
                            }
                        }                        if (empty($displayGroups)):
                        ?>
                            <div class="sf-empty-state">
                                <img src="<?= $base ?>/assets/img/icons/no-events.svg" alt="" class="sf-empty-icon">
                                <p><?= htmlspecialchars(sf_term('events_empty', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($displayGroups as $group):
                                $event = $group[0]; // Ensisijainen tapahtuma ryhmässä
                                $first = trim((string)($event['first_name'] ?? ''));
                                $last = trim((string)($event['last_name'] ?? ''));
                                $fullName = trim($first . ' ' . $last);
                                
$eventType = (string)($event['event_type'] ?? 'UNKNOWN_EVENT');

$eventLabel = sf_term('log_' . $eventType, $currentUiLang);
if ($eventLabel === 'log_' . $eventType) {
    $eventLabel = sf_term($eventType, $currentUiLang);
}

$eventIcons = [
    'created' => 'create.svg',
    'CREATED' => 'create.svg',
    'investigation_created' => 'type-green.svg',
    'edited' => 'create.svg',
    'updated' => 'create.svg',

    'state_changed' => 'status-change.svg',

    'sent_to_supervisor' => 'send_forward_icon.svg',
    'sent_to_review' => 'send_forward_icon.svg',
    'sent_to_comms' => 'forward_icon-2.svg',
    'supervisor_approved' => 'forward_icon-2.svg',

    'submission_comment' => 'comment.svg',
    'comment_added' => 'comment.svg',

    'info_requested' => 'reverse_icon.svg',
    'request_info' => 'reverse_icon.svg',
    'returned_for_corrections' => 'reverse_icon.svg',
    'returned_to_supervisor' => 'reverse_icon.svg',

    'published' => 'forward_icon-2.svg',
    'approved' => 'forward_icon-2.svg',
    'rejected' => 'reject.svg',

    'archived' => 'archive.svg',
    'original_archived' => 'archive_icon.svg',
    'deleted' => 'delete.svg',

    'image_1_edited_changed' => 'create.svg',
    'image_2_edited_changed' => 'create.svg',
    'image_3_edited_changed' => 'create.svg',
    'image_1_changed' => 'image.svg',
    'image_2_changed' => 'image.svg',
    'image_3_changed' => 'image.svg',
    'image_1_repositioned' => 'create.svg',
    'image_2_repositioned' => 'create.svg',
    'image_3_repositioned' => 'create.svg',
    'image_caption_1_changed' => 'create.svg',
    'image_caption_2_changed' => 'create.svg',
    'image_caption_3_changed' => 'create.svg',

    'grid_layout_changed' => 'layout.svg',
    'layout_mode_changed' => 'layout.svg',
    'appearance_changed' => 'create.svg',
    'annotations_changed' => 'annotation.svg',
    'original_type_changed' => 'type-change.svg',

    'translation_saved' => 'translate_icon.svg',
    'language_version_created' => 'translate_icon.svg',
    'language_review_requested' => 'translate_icon.svg',

    'display_targets_preselected' => 'display.svg',
    'distribution_sent' => 'distribution.svg',
    'worksite_notification_sent' => 'send.svg',
];

$groupDescriptionsRaw = [];
$groupEventTypes = [];

foreach ($group as $groupEventForState) {
    $groupEventTypes[] = (string)($groupEventForState['event_type'] ?? '');
    $groupDescriptionsRaw[] = (string)($groupEventForState['description'] ?? '');
}

$groupDescriptionText = implode("\n", $groupDescriptionsRaw);

$hasPublishedTransition = false;
$hasSentToPublish = false;
$hasSafetyTeamApproval = false;
$hasSubmittedToReview = false;
$hasCreatedAndSubmittedToReview = false;
$hasInvestigationCreatedAndSubmittedToReview = false;
$hasSafetyTeamApprovedAndSentToPublish = false;
$hasSupervisorApprovedAndSentToSafetyTeam = false;
$hasSentToSafetyTeam = false;
$hasReturnedForCorrections = false;
$hasSupervisorApproval = false;
$hasCreatedEvent = false;
$hasLanguageReviewRequest = false;
$hasInvestigationStarted = false;

foreach ($group as $groupEventForState) {
    $groupEventType = (string)($groupEventForState['event_type'] ?? '');
    $groupDescription = (string)($groupEventForState['description'] ?? '');

    if (
        in_array($groupEventType, ['published', 'worksite_notification_sent'], true)
        || stripos($groupDescription, '→ published') !== false
        || stripos($groupDescription, '-> published') !== false
        || stripos($groupDescription, 'log_status_set: published') !== false
    ) {
        $hasPublishedTransition = true;
    }

if ($groupEventType === 'sent_to_comms') {
    $hasSentToPublish = true;
}

if (
    $groupEventType === 'state_changed'
    && stripos($groupDescription, 'pending_review') !== false
    && (
        stripos($groupDescription, '→ to_comms') !== false
        || stripos($groupDescription, '-> to_comms') !== false
    )
) {
    $hasSafetyTeamApproval = true;
}

    if (
        $groupEventType === 'supervisor_approved'
        || stripos($groupDescription, 'Työmaavastaava hyväksyi') !== false
        || stripos($groupDescription, 'log_supervisor_approved') !== false
    ) {
        $hasSupervisorApproval = true;
    }

    if (
        in_array($groupEventType, ['request_info', 'info_requested', 'returned_for_corrections', 'returned_to_supervisor'], true)
        || stripos($groupDescription, 'palaut') !== false
        || stripos($groupDescription, 'korjattav') !== false
    ) {
        $hasReturnedForCorrections = true;
    }

    if (
        in_array($groupEventType, ['created', 'CREATED'], true)
        || stripos($groupDescription, 'Safetyflash luotu') !== false
        || stripos($groupDescription, 'Kieliversio luotu') !== false
    ) {
        $hasCreatedEvent = true;
    }

    if (
        $groupEventType === 'language_review_requested'
        || stripos($groupDescription, 'Kielitarkistus pyydetty') !== false
        || stripos($groupDescription, 'language_review') !== false
    ) {
        $hasLanguageReviewRequest = true;
    }

    if (
        $groupEventType === 'original_type_changed'
        || stripos($groupDescription, 'Tutkintatiedote luotu') !== false
        || stripos($groupDescription, 'type:') !== false
        || stripos($groupDescription, 'investigation') !== false
    ) {
        $hasInvestigationStarted = true;
    }

    if (
        $groupEventType === 'state_changed'
        && (
            stripos($groupDescription, 'pending_supervisor') !== false
            || stripos($groupDescription, 'Työmaavastaavan tarkistuksessa') !== false
            || stripos($groupDescription, '→ Työmaavastaavan tarkistuksessa') !== false
            || stripos($groupDescription, '-> Työmaavastaavan tarkistuksessa') !== false
        )
    ) {
        $hasSubmittedToReview = true;
    }
if (
    $groupEventType === 'state_changed'
    && (
        preg_match('/log_state_changed:\s*[^\\n]*?(→|->)\s*pending_review/i', $groupDescription)
        || preg_match('/log_state_changed:\s*[^\\n]*?(→|->)\s*Turvatiimin tarkistuksessa/iu', $groupDescription)
    )
) {
    $hasSentToSafetyTeam = true;
}
}

$hasCreatedAndSubmittedToReview = $hasCreatedEvent && $hasSubmittedToReview;
$hasInvestigationCreatedAndSubmittedToReview = $hasInvestigationStarted && $hasSubmittedToReview;
$hasSafetyTeamApprovedAndSentToPublish = $hasSafetyTeamApproval && $hasSentToPublish;
$hasSupervisorApprovedAndSentToSafetyTeam = $hasSupervisorApproval && $hasSentToSafetyTeam;

if ($hasPublishedTransition) {
    $eventLabel = sf_term('log_flash_published', $currentUiLang);
} elseif ($hasSafetyTeamApprovedAndSentToPublish) {
    $eventLabel = sf_term('log_safety_team_approved_and_sent_to_publish', $currentUiLang);
} elseif ($hasInvestigationCreatedAndSubmittedToReview) {
    $eventLabel = sf_term('log_investigation_created_and_submitted_to_review', $currentUiLang);
} elseif ($hasCreatedAndSubmittedToReview) {
    $eventLabel = sf_term('log_safetyflash_created_and_submitted_to_review', $currentUiLang);
} elseif ($hasSupervisorApprovedAndSentToSafetyTeam || $hasSupervisorApproval) {
    $eventLabel = sf_term('log_supervisor_approved_and_sent_to_safety_team', $currentUiLang);
} elseif ($hasSentToPublish) {
    $eventLabel = sf_term('log_sent_to_publish', $currentUiLang);
} elseif ($hasSafetyTeamApproval) {
    $eventLabel = sf_term('log_safety_team_approved', $currentUiLang);
} elseif ($hasSupervisorApproval) {
    $eventLabel = sf_term('log_supervisor_approved', $currentUiLang);
} elseif ($hasReturnedForCorrections) {
    $eventLabel = sf_term('log_returned_for_corrections', $currentUiLang);
} elseif ($hasSentToSafetyTeam) {
    $eventLabel = sf_term('log_sent_to_safety_team', $currentUiLang);
} elseif ($hasInvestigationStarted) {
    $eventLabel = sf_term('log_investigation_started', $currentUiLang);
} elseif ($hasCreatedEvent) {
    $eventLabel = sf_term('log_safetyflash_created', $currentUiLang);
} elseif ($hasSubmittedToReview) {
    $eventLabel = sf_term('log_submitted_to_review', $currentUiLang);
} elseif ($hasLanguageReviewRequest) {
    $eventLabel = sf_term('log_language_review_requested', $currentUiLang);
}

$iconFile = $eventIcons[$eventType] ?? 'create.svg';
$secondaryIconFile = '';

$sfResolveTypeIconForLogEvent = static function(array $logEvent) use ($flash, $investigationStartTimestamp): string {
    $resolvedFlashType = (string)($logEvent['log_flash_type'] ?? '');

    if ($resolvedFlashType === '') {
        $resolvedFlashType = (string)($flash['type'] ?? '');
    }

    $eventTimestamp = strtotime((string)($logEvent['created_at'] ?? ''));

    if (
        (string)($flash['type'] ?? '') === 'green'
        && in_array((string)($flash['original_type'] ?? ''), ['red', 'yellow'], true)
        && $investigationStartTimestamp !== null
        && $eventTimestamp !== false
        && $eventTimestamp < $investigationStartTimestamp
    ) {
        $resolvedFlashType = (string)$flash['original_type'];
    }

    if ($resolvedFlashType === 'red') {
        return 'type-red.svg';
    }

    if ($resolvedFlashType === 'yellow') {
        return 'type-yellow.svg';
    }

    if ($resolvedFlashType === 'green') {
        return 'type-green.svg';
    }

    return 'type-change.svg';
};

if ($hasInvestigationCreatedAndSubmittedToReview) {
    $iconFile = 'type-green.svg';
    $secondaryIconFile = 'send_forward_icon.svg';
} elseif ($hasCreatedAndSubmittedToReview) {
    $createdLogEventForIcon = $event;

    foreach ($group as $groupEventForIconType) {
        if (in_array((string)($groupEventForIconType['event_type'] ?? ''), ['created', 'CREATED'], true)) {
            $createdLogEventForIcon = $groupEventForIconType;
            break;
        }
    }

    $iconFile = $sfResolveTypeIconForLogEvent($createdLogEventForIcon);
    $secondaryIconFile = 'send_forward_icon.svg';
} elseif ($hasSafetyTeamApprovedAndSentToPublish) {
    $iconFile = 'forward_icon-2.svg';
    $secondaryIconFile = 'send_forward_icon.svg';
} elseif ($hasSupervisorApprovedAndSentToSafetyTeam || $hasSupervisorApproval) {
    $iconFile = 'forward_icon-2.svg';
    $secondaryIconFile = 'send_forward_icon.svg';
} elseif ($hasPublishedTransition || $hasSafetyTeamApproval) {
    $iconFile = 'forward_icon-2.svg';
} elseif ($hasSentToPublish) {
    $iconFile = 'send_forward_icon.svg';
} elseif ($hasReturnedForCorrections) {
    $iconFile = 'reverse_icon.svg';
} elseif ($hasSentToSafetyTeam || $hasSubmittedToReview) {
    $iconFile = 'send_forward_icon.svg';
} elseif ($hasInvestigationStarted) {
    $iconFile = 'type-green.svg';
} elseif ($hasCreatedEvent) {
        $createdFlashType = (string)($event['log_flash_type'] ?? $flash['type'] ?? '');

    $createdEventTimestamp = strtotime((string)($event['created_at'] ?? ''));

    if (
        (string)($flash['type'] ?? '') === 'green'
        && in_array((string)($flash['original_type'] ?? ''), ['red', 'yellow'], true)
        && $investigationStartTimestamp !== null
        && $createdEventTimestamp !== false
        && $createdEventTimestamp < $investigationStartTimestamp
    ) {
        $createdFlashType = (string)$flash['original_type'];
    }

    if ($createdFlashType === 'red') {
        $iconFile = 'type-red.svg';
    } elseif ($createdFlashType === 'yellow') {
        $iconFile = 'type-yellow.svg';
    } elseif ($createdFlashType === 'green') {
        $iconFile = 'type-green.svg';
    } else {
        $iconFile = 'type-change.svg';
    }
} elseif ($hasLanguageReviewRequest) {
    $iconFile = 'translate_icon.svg';
}

$languageFlagFiles = [
    'FI' => 'finnish-flag.png',
    'EN' => 'english-flag.png',
    'SV' => 'swedish-flag.png',
    'IT' => 'italian-flag.png',
    'EL' => 'greece-flag.png',
];

$languageFlagFile = null;
$languageCode = '';

$languageBadgeAllowedTypes = [
    'published',
    'edited',
    'updated',
    'created',
    'CREATED',
    'translation_saved',
    'language_version_created',
    'image_1_edited_changed',
    'image_2_edited_changed',
    'image_3_edited_changed',
    'image_1_changed',
    'image_2_changed',
    'image_3_changed',
    'image_1_repositioned',
    'image_2_repositioned',
    'image_3_repositioned',
    'image_caption_1_changed',
    'image_caption_2_changed',
    'image_caption_3_changed',
];

$canShowLanguageBadge = false;

if ($hasPublishedTransition || $hasCreatedEvent || in_array($eventType, $languageBadgeAllowedTypes, true)) {
    $canShowLanguageBadge = true;
}

if (
    $hasSubmittedToReview
    || $hasSentToPublish
    || $hasSupervisorApproval
    || $hasReturnedForCorrections
    || $eventType === 'language_review_requested'
) {
    $canShowLanguageBadge = false;
}

if ($canShowLanguageBadge) {
    foreach ($group as $groupEventForIcon) {
        $descriptionForIcon = (string)($groupEventForIcon['description'] ?? '');

        if (preg_match('/log_language_version:\s*(FI|EN|SV|IT|EL)/i', $descriptionForIcon, $languageMatches)) {
            $languageCode = strtoupper($languageMatches[1]);
            break;
        }

        if (preg_match('/Kieliversio luotu(?:\s*\(bundle\))?:\s*(fi|sv|en|it|el)/iu', $descriptionForIcon, $createdLangMatches)) {
            $languageCode = strtoupper($createdLangMatches[1]);
            break;
        }
    }

    if ($languageCode !== '') {
        $languageFlagFile = $languageFlagFiles[$languageCode] ?? null;
    }
}
                                
                                $timeAgo = sf_time_ago($event['created_at'], $currentUiLang);

                                // Helper closure: kääntää yhden tapahtuman kuvauksen HTML:ksi
                                $parseEventDesc = function(string $descRaw) use ($currentUiLang, $flash, $hasPublishedTransition, $hasSubmittedToReview, $hasSupervisorApprovedAndSentToSafetyTeam, $hasSafetyTeamApprovedAndSentToPublish): string {
                                    $descToShow = '';
                                    $lines = explode("\n", $descRaw);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

if (preg_match('/^log_language_version:\s*(FI|EN|SV|IT|EL)$/i', $line)) {
    continue;
}

if ($hasPublishedTransition && preg_match('/^log_status_set:\s*published$/i', $line)) {
    continue;
}

if ($hasPublishedTransition && preg_match('/^log_state_changed:\s*→\s*awaiting_publish$/iu', $line)) {
    continue;
}

if ($hasSubmittedToReview && preg_match('/^Safetyflash luotu$/iu', $line)) {
    continue;
}

if ($hasSubmittedToReview && preg_match('/^Tutkintatiedote luotu$/iu', $line)) {
    continue;
}

if ($hasSubmittedToReview && preg_match('/^log_investigation_created$/i', $line)) {
    continue;
}

if ($hasSubmittedToReview && preg_match('/^Alkuperäinen sisältö arkistoitu\|data:/iu', $line)) {
    $originalType = (string)($flash['original_type'] ?? '');

    if ($originalType === 'yellow') {
        $line = sf_term('log_dangerous_situation_content_archived', $currentUiLang);
    } elseif ($originalType === 'red') {
        $line = sf_term('log_first_release_content_archived', $currentUiLang);
    } else {
        $line = sf_term('log_original_content_archived', $currentUiLang);
    }
}

if (preg_match('/^Alkuperäinen sisältö arkistoitu$/iu', $line)) {
    $originalType = (string)($flash['original_type'] ?? '');

    if ($originalType === 'yellow') {
        $line = sf_term('log_dangerous_situation_content_archived', $currentUiLang);
    } elseif ($originalType === 'red') {
        $line = sf_term('log_first_release_content_archived', $currentUiLang);
    } else {
        $line = sf_term('log_original_content_archived', $currentUiLang);
    }
}

if ($hasSupervisorApprovedAndSentToSafetyTeam && preg_match('/^Tila asetettu:\s*/iu', $line)) {
    continue;
}

if ($hasSafetyTeamApprovedAndSentToPublish && preg_match('/^Tila asetettu:\s*/iu', $line)) {
    continue;
}

if ($hasSafetyTeamApprovedAndSentToPublish && preg_match('/^log_status_set:\s*(to_comms|awaiting_publish|published)$/i', $line)) {
    continue;
}

if (preg_match('/^log_display_targets_preselected$/i', $line) || preg_match('/^display_targets_preselected$/i', $line)) {
    continue;
}

$line = str_replace('Valitut työmaat:', sf_term('log_selected_displays', $currentUiLang) . ':', $line);
                                        $translatedLine = $line;
                                        // 1. OCCURRED_AT
                                        if (preg_match('/^occurred_at:\s*(.+?)\s*→\s*(.+)$/u', $line, $matches)) {
                                            $beforeLabel = sf_term('occurred_at', $currentUiLang);
                                            $before = trim($matches[1]);
                                            $after  = str_replace('T', ' ', trim($matches[2]));
                                            $translatedLine = '<strong>' . $beforeLabel . ':</strong> ' . $before . ' → ' . $after;
                                        }
                                        // 2. STATUS PIPE
                                        elseif (preg_match('/^(log_\w+)\|status:(\w+)$/u', $line, $matches)) {
                                            $translatedLine = '<strong>' . sf_term($matches[1], $currentUiLang) . ':</strong> ' . sf_status_label($matches[2], $currentUiLang);
                                        }
                                        // 3. DISTRIBUTION SENT (new format: counts:fi:5,se:3)
                                        elseif (preg_match('/^log_distribution_sent\|counts:(.+)$/u', $line, $matches)) {
                                            $recipientsLabel = sf_term('log_recipients_count', $currentUiLang);
                                            $parts = [];
                                            foreach (explode(',', $matches[1]) as $pair) {
                                                $pairParts = explode(':', trim($pair), 2);
                                                if (count($pairParts) !== 2) {
                                                    continue;
                                                }
                                                $cc  = trim($pairParts[0]);
                                                $cnt = trim($pairParts[1]);
                                                if ($cc !== '') {
                                                    $countryName = sf_term("country_name_{$cc}", $currentUiLang);
                                                    if ($countryName === "country_name_{$cc}") {
                                                        $countryName = strtoupper($cc);
                                                    }
                                                    $parts[] = $countryName . ($cnt !== '' ? ': ' . $cnt . ' ' . $recipientsLabel : '');
                                                }
                                            }
                                            $translatedLine = '<strong>' . sf_term('log_distribution_sent', $currentUiLang) . ':</strong> ' . implode('; ', $parts);
                                        }
                                        // 3b. DISTRIBUTION SENT (legacy format: countries:…|details:…)
                                        elseif (preg_match('/^log_distribution_sent\|countries:([^|]+)\|details:(.+)$/u', $line, $matches)) {
                                            $translatedLine = '<strong>' . sf_term('log_distribution_sent', $currentUiLang) . ':</strong> ' . trim($matches[2]);
                                        }
                                        // 4. MULTI-PARAM PIPE
                                        elseif (preg_match('/^(log_\w+)\|(.+)$/u', $line, $matches)) {
                                            $logTranslated = sf_term($matches[1], $currentUiLang);
                                            $params = [];
                                            foreach (explode('|', $matches[2]) as $part) {
                                                if (strpos($part, ':') !== false) {
                                                    [$k, $v] = array_map('trim', explode(':', $part, 2));
                                                    $params[$k] = $v;
                                                }
                                            }
                                            $translatedLine = isset($params['details'])
                                                ? '<strong>' . $logTranslated . ':</strong> ' . $params['details']
                                                : '<strong>' . $logTranslated . '</strong>';
                                        }
                                        // 5. LABEL
                                        elseif (preg_match('/^(log_\w+_label):\s*(.+)$/u', $line, $matches)) {
                                            $translatedLine = '<strong>' . sf_term($matches[1], $currentUiLang) . ':</strong> ' . trim($matches[2]);
                                        }
                                        // 6. FIELD CHANGE with quotes
                                        elseif (preg_match('/^([a-z_]+):\s*"([^"]+)"\s*→\s*"([^"]+)"$/u', $line, $matches)) {
                                            $translatedLine = '<strong>' . sf_term($matches[1], $currentUiLang) . ':</strong> ' . $matches[2] . ' → ' . $matches[3];
                                        }
                                        // 6b. TYPE CHANGE
                                        elseif (preg_match('/^type:\s*(\w+)\s*→\s*(\w+)$/u', $line, $matches)) {
                                            $translatedLine = '<strong>' . sf_term('type', $currentUiLang) . ':</strong> '
                                                . sf_translate_flash_type(trim($matches[1]), $currentUiLang)
                                                . ' → '
                                                . sf_translate_flash_type(trim($matches[2]), $currentUiLang);
                                        }
                                        // 7. FIELD CHANGE without quotes
                                        elseif (preg_match('/^([a-z_]+):\s*([^→]+)\s*→\s*(.+)$/u', $line, $matches)) {
                                            $oldValue = trim($matches[2]);
                                            $newValue = trim($matches[3]);
                                            $oldT = sf_status_label($oldValue, $currentUiLang);
                                            if ($oldT && $oldT !== $oldValue && preg_match('/^[a-z_]+$/', $oldValue)) $oldValue = $oldT;
                                            $newT = sf_status_label($newValue, $currentUiLang);
                                            if ($newT && $newT !== $newValue && preg_match('/^[a-z_]+$/', $newValue)) $newValue = $newT;
                                            $translatedLine = '<strong>' . sf_term($matches[1], $currentUiLang) . ':</strong> ' . $oldValue . ' → ' . $newValue;
                                        }
// 8. SIMPLE KEY:VALUE
elseif (preg_match('/^(log_\w+|[a-z_]+):\s*(.+)$/u', $line, $matches)) {
    $key   = trim($matches[1]);
    $value = trim($matches[2]);
    $keyT  = sf_term($key, $currentUiLang);

    if ($keyT === $key && strpos($key, 'log_') !== 0) {
        $translatedLine = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
    } else {
        $translatedValue = $value;

        if (function_exists('sf_status_label')) {
            $statusLabel = sf_status_label($value, $currentUiLang);

            if ($statusLabel !== $value) {
                $translatedValue = $statusLabel;
            }
        }

        $translatedLine = '<strong>' . htmlspecialchars($keyT, ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($translatedValue, ENT_QUOTES, 'UTF-8');
    }
}
                                        // 9. Käännä koko rivi
                                        else {
                                            $translated = sf_term($line, $currentUiLang);
                                            if ($translated !== $line) {
                                                $translatedLine = $translated;
                                            }
                                        }
                                        if ($descToShow !== '') {
                                            $descToShow .= "\n";
                                        }
                                        $descToShow .= $translatedLine;
                                    }
                                    $descProcessed = function_exists('sf_log_status_replace')
                                        ? sf_log_status_replace($descToShow, $currentUiLang)
                                        : $descToShow;
                                    return strip_tags($descProcessed, '<span><strong>');
                                };

// Kerää kaikkien ryhmän tapahtumien kuvaukset loogisessa järjestyksessä.
usort($group, static function($a, $b) {
    $weight = [
        'created' => 10,
        'CREATED' => 10,
        'original_type_changed' => 15,
        'state_changed' => 20,
        'supervisor_approved' => 30,
        'sent_to_comms' => 60,
        'language_review_requested' => 70,
        'worksite_notification_sent' => 80,
        'distribution_sent' => 85,
        'published' => 90,
    ];

    $aType = (string)($a['event_type'] ?? '');
    $bType = (string)($b['event_type'] ?? '');

    $wa = $weight[$aType] ?? 50;
    $wb = $weight[$bType] ?? 50;

    if ($wa !== $wb) {
        return $wa <=> $wb;
    }

    $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;

    return $ta <=> $tb;
});

$groupDescriptions = [];

foreach ($group as $groupEvent) {
    $groupEventTypeForDescription = (string)($groupEvent['event_type'] ?? '');
    $groupEventDescription = (string)($groupEvent['description'] ?? '');

    if (in_array($groupEventTypeForDescription, ['display_targets_preselected', 'published'], true)) {
        continue;
    }

    if ($hasPublishedTransition && $groupEventTypeForDescription === 'state_changed') {
        if (
            stripos($groupEventDescription, '→ awaiting_publish') !== false
            || stripos($groupEventDescription, '-> awaiting_publish') !== false
            || stripos($groupEventDescription, 'log_state_changed: awaiting_publish') !== false
        ) {
            continue;
        }
    }

    if ($hasLanguageReviewRequest && $groupEventTypeForDescription === 'state_changed') {
        if (
            stripos($groupEventDescription, '→ awaiting_publish') !== false
            || stripos($groupEventDescription, '-> awaiting_publish') !== false
        ) {
            continue;
        }
    }

    if ($hasSubmittedToReview && preg_match('/Safetyflash luotu/iu', $groupEventDescription)) {
        continue;
    }

    $parsed = $parseEventDesc($groupEventDescription);

    if ($parsed !== '') {
        $groupDescriptions[] = $parsed;
    }
}

if ($hasSubmittedToReview) {
    $groupUserId = (int)($event['user_id'] ?? 0);
    $groupTime = strtotime((string)($event['created_at'] ?? ''));

    foreach ($logs as $logForSubmissionMessage) {
        if (($logForSubmissionMessage['event_type'] ?? '') !== 'submission_comment') {
            continue;
        }

        if ((int)($logForSubmissionMessage['user_id'] ?? 0) !== $groupUserId) {
            continue;
        }

        $submissionTime = strtotime((string)($logForSubmissionMessage['created_at'] ?? ''));

        if ($groupTime === false || $submissionTime === false || abs($groupTime - $submissionTime) > 180) {
            continue;
        }

        $submissionDescription = (string)($logForSubmissionMessage['description'] ?? '');

        if (preg_match('/log_comment_label:\s*(.+)/is', $submissionDescription, $submissionMatch)) {
            $submissionText = trim($submissionMatch[1]);

            if ($submissionText !== '') {
                $groupDescriptions[] =
                    '<strong>' .
                    htmlspecialchars(sf_term('message', $currentUiLang), ENT_QUOTES, 'UTF-8') .
                    ':</strong> ' .
                    htmlspecialchars($submissionText, ENT_QUOTES, 'UTF-8');
            }
        }

        break;
    }
}

$groupDescriptions = array_values(array_unique($groupDescriptions));
							$eventFlashType = (string)($event['log_flash_type'] ?? $flash['type'] ?? '');

$eventTimestamp = strtotime((string)($event['created_at'] ?? ''));

if (
    (string)($flash['type'] ?? '') === 'green'
    && in_array((string)($flash['original_type'] ?? ''), ['red', 'yellow'], true)
    && $investigationStartTimestamp !== null
    && $eventTimestamp !== false
    && $eventTimestamp < $investigationStartTimestamp
) {
    $eventFlashType = (string)$flash['original_type'];
}
$eventFlashTypeLabels = [
    'red' => sf_term('first_release', $currentUiLang),
    'yellow' => sf_term('dangerous_situation', $currentUiLang),
    'green' => sf_term('investigation_report', $currentUiLang),
];
$eventFlashTypeLabel = $eventFlashTypeLabels[$eventFlashType] ?? '';
$isBatch = count($group) > 1;

$isPublishedMilestone =
    $hasPublishedTransition
    || $eventType === 'published';

$publishedLanguageCode = '';

if ($isPublishedMilestone) {
    $eventDescriptionForPublishedLanguage = (string)($event['description'] ?? '');

    if (preg_match('/log_language_version:\s*(FI|SV|EN|IT|EL)/i', $eventDescriptionForPublishedLanguage, $publishedLanguageMatch)) {
        $publishedLanguageCode = strtoupper($publishedLanguageMatch[1]);
    }

    if ($publishedLanguageCode === '') {
        foreach ($group as $publishedGroupEvent) {
            $publishedGroupDescription = (string)($publishedGroupEvent['description'] ?? '');

            if (preg_match('/log_language_version:\s*(FI|SV|EN|IT|EL)/i', $publishedGroupDescription, $publishedGroupLanguageMatch)) {
                $publishedLanguageCode = strtoupper($publishedGroupLanguageMatch[1]);
                break;
            }
        }
    }

    if (in_array($publishedLanguageCode, ['FI', 'SV', 'EN', 'IT', 'EL'], true)) {
        $languageCode = $publishedLanguageCode;
        $languageFlagFile = $languageFlagFiles[$publishedLanguageCode] ?? null;
    }

    $secondaryIconFile = '';
}

$showPublishedLanguageMainIcon = $isPublishedMilestone && !empty($languageFlagFile);
?>
<div class="sf-event-item<?= $isBatch ? ' sf-event-item--batch' : '' ?><?= $isPublishedMilestone ? ' sf-event-item--published' : '' ?>" data-event-type="<?= htmlspecialchars($eventType, ENT_QUOTES, 'UTF-8') ?>">
<div class="sf-event-icon<?= (!$showPublishedLanguageMainIcon && !empty($languageFlagFile)) ? ' sf-event-icon--has-language' : '' ?><?= !empty($secondaryIconFile) ? ' sf-event-icon--has-secondary' : '' ?><?= $showPublishedLanguageMainIcon ? ' sf-event-icon--published-language' : '' ?>">
    <?php if ($showPublishedLanguageMainIcon): ?>
        <img
            src="<?= $base ?>/assets/img/<?= htmlspecialchars($languageFlagFile, ENT_QUOTES, 'UTF-8') ?>"
            alt="<?= htmlspecialchars($languageCode ?? '', ENT_QUOTES, 'UTF-8') ?>"
            class="sf-event-main-icon sf-event-main-icon--language-flag"
        >
    <?php else: ?>
        <img
            src="<?= $base ?>/assets/img/icons/<?= htmlspecialchars($iconFile, ENT_QUOTES, 'UTF-8') ?>"
            alt=""
            class="sf-event-main-icon"
        >
    <?php endif; ?>

    <?php if (!empty($secondaryIconFile)): ?>
        <img
            src="<?= $base ?>/assets/img/icons/<?= htmlspecialchars($secondaryIconFile, ENT_QUOTES, 'UTF-8') ?>"
            alt=""
            class="sf-event-secondary-icon"
        >
    <?php endif; ?>

    <?php if (!$showPublishedLanguageMainIcon && !empty($languageFlagFile)): ?>
        <img
            src="<?= $base ?>/assets/img/<?= htmlspecialchars($languageFlagFile, ENT_QUOTES, 'UTF-8') ?>"
            alt="<?= htmlspecialchars($languageCode ?? '', ENT_QUOTES, 'UTF-8') ?>"
            class="sf-event-language-badge"
        >
    <?php endif; ?>
</div>
                                    <div class="sf-event-content">
                                        <div class="sf-event-header">
                                            <span class="sf-event-label"><?= htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8') ?></span>

<?php if ($eventFlashTypeLabel !== ''): ?>
    <span class="sf-comment-badge sf-comment-type-badge sf-comment-type-badge--<?= htmlspecialchars($eventFlashType, ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($eventFlashTypeLabel, ENT_QUOTES, 'UTF-8') ?>
    </span>
<?php endif; ?>
                                            <span class="sf-event-time"><?= htmlspecialchars($timeAgo, ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <?php if (!empty($fullName)): ?>
                                            <div class="sf-event-user">
                                                <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($groupDescriptions) || !empty($publishedLanguageCode)): ?>
                                            <div class="sf-event-description">
                                                <?php if (!empty($groupDescriptions)): ?>
                                                    <?php if ($isBatch): ?>
                                                        <ul class="sf-event-batch-list">
                                                            <?php foreach ($groupDescriptions as $descItem): ?>
                                                                <li><?= nl2br($descItem) ?></li>
                                                            <?php endforeach; ?>

                                                            <?php if (!empty($publishedLanguageCode)): ?>
                                                                <li>
                                                                    <strong><?= htmlspecialchars(sf_term('log_published_language', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
                                                                    <?= htmlspecialchars($publishedLanguageCode, ENT_QUOTES, 'UTF-8') ?>
                                                                </li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <?php
                                                        $descLines = array_values(array_filter(
                                                            explode("\n", $groupDescriptions[0]),
                                                            fn($l) => trim($l) !== ''
                                                        ));
                                                        ?>
                                                        <?php if (count($descLines) > 1 || !empty($publishedLanguageCode)): ?>
                                                            <ul class="sf-event-batch-list">
                                                                <?php foreach ($descLines as $descLine): ?>
                                                                    <li><?= $descLine ?></li>
                                                                <?php endforeach; ?>

                                                                <?php if (!empty($publishedLanguageCode)): ?>
                                                                    <li>
                                                                        <strong><?= htmlspecialchars(sf_term('log_published_language', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
                                                                        <?= htmlspecialchars($publishedLanguageCode, ENT_QUOTES, 'UTF-8') ?>
                                                                    </li>
                                                                <?php endif; ?>
                                                            </ul>
                                                        <?php else: ?>
                                                            <?= $groupDescriptions[0] ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php elseif (!empty($publishedLanguageCode)): ?>
                                                    <ul class="sf-event-batch-list">
                                                        <li>
                                                            <strong><?= htmlspecialchars(sf_term('log_published_language', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
                                                            <?= htmlspecialchars($publishedLanguageCode, ENT_QUOTES, 'UTF-8') ?>
                                                        </li>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- LISÄTIEDOT TAB -->
                <div class="sf-tab-content" id="tabAdditionalInfo">
                    <div class="sf-additional-info-container">

                        <p class="sf-additional-info-description" style="margin-bottom: 1rem; color: #4b5563; font-size: 0.92rem;">
                            <?= htmlspecialchars(sf_term('additional_info_description', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <?php
                        // Show body map button for all flash types (red, yellow, green)
                        $showBodyMapInTab = (
                            in_array($flash['type'], ['red', 'yellow', 'green'], true) ||
                            in_array($flash['original_type'] ?? '', ['red', 'yellow'], true)
                        );
                        ?>
                        <?php if ($showBodyMapInTab && $canEditBodyParts): ?>
                        <div class="sf-additional-info-bodymap" style="margin-bottom: 1.25rem;">
                            <button type="button" id="sfTabBodyMapBtn"
                                    class="sf-btn sf-btn-secondary"
                                    style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/injury_icon.svg"
                                     width="18" height="18" alt="" aria-hidden="true">
                                <?= htmlspecialchars(sf_term('body_map_open_btn', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </div>
                        <?php endif; ?>

                        <?php if ($canAccessSettings): ?>
                        <div class="sf-additional-info-form" style="margin-bottom: 1.25rem;">
                            <button type="button" id="sfOpenAddAdditionalInfoBtn" class="sf-btn sf-btn-primary">
                                <?= htmlspecialchars(sf_term('additional_info_add_btn', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </div>
                        <?php endif; ?>

                        <div class="sf-additional-info-list" id="sfAdditionalInfoList">
                            <?php foreach ($additionalInfoEntries as $aiEntry): ?>
                                <?php
                                $aiFirst   = trim((string)($aiEntry['first_name'] ?? ''));
                                $aiLast    = trim((string)($aiEntry['last_name'] ?? ''));
                                $aiName    = trim($aiFirst . ' ' . $aiLast) ?: sf_term('additional_info_unknown_author', $currentUiLang);
                                $aiIsOwn   = $canAccessSettings && ((int)($aiEntry['user_id'] ?? 0) === $currentUserId || $isAdmin);
                                ?>
                                <div class="sf-comment-item" data-ai-id="<?= (int)$aiEntry['id'] ?>">
                                    <div class="sf-comment-content">
                                        <div class="sf-comment-header">
                                            <div>
                                                <span class="sf-comment-author"><?= htmlspecialchars($aiName, ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="sf-comment-time">&middot; <?= htmlspecialchars($aiEntry['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <?php if ($aiIsOwn): ?>
                                            <div class="sf-comment-actions">
                                                <button type="button"
                                                        class="sf-comment-action-btn btn-edit-additional-info"
                                                        data-ai-id="<?= (int)$aiEntry['id'] ?>"
                                                        data-content="<?= htmlspecialchars($aiEntry['content'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/create.svg" alt="" class="sf-action-icon">
                                                    <?= htmlspecialchars(sf_term('comment_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                                <button type="button"
                                                        class="sf-comment-action-btn btn-delete-additional-info sf-text-danger"
                                                        data-ai-id="<?= (int)$aiEntry['id'] ?>">
                                                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/delete.svg" alt="" class="sf-action-icon">
                                                    <?= htmlspecialchars(sf_term('comment_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="sf-comment-body">
                                            <?= sf_sanitize_ai_html($aiEntry['content']) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>
                <!-- VERSIOT TAB -->
                <div class="sf-tab-content" id="tabVersions">
                    <div class="sf-versions-container">
                        <?php
                        // Fetch published versions
                        $stmtVersions = $pdo->prepare("
                            SELECT s.*, u.first_name, u.last_name 
                            FROM sf_flash_snapshots s
                            LEFT JOIN sf_users u ON s.published_by = u.id
                            WHERE s.flash_id = ?
                            ORDER BY s.published_at DESC
                        ");
                        $stmtVersions->execute([$logFlashId]);
                        $snapshots = $stmtVersions->fetchAll();
                        $totalVersions = count($snapshots);
                        
                        ?>
                        
                        
                        <?php if (empty($snapshots)): ?>
                            <div class="sf-empty-state">
                                <img src="<?= $base ?>/assets/img/icons/version.svg" alt="" class="sf-empty-icon">
                                <p><?= htmlspecialchars(sf_term('version_no_versions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php else: ?>
                            <div class="sf-version-list">
                                <?php 
                                // Define version type mappings once before loop
                                $versionTypeIcons = [
                                    'ensitiedote' => 'icon-red.png',
                                    'vaaratilanne' => 'icon-yellow.png',
                                    'tutkintatiedote' => 'icon-green.png',
                                    'paivitys' => 'icon-yellow.png',
                                ];
                                $versionTypeColors = [
                                    'ensitiedote' => 'red',
                                    'vaaratilanne' => 'yellow',
                                    'tutkintatiedote' => 'green',
                                    'paivitys' => 'yellow',
                                ];
                                
                                $currentVersionTypeMap = [
                                    'red' => 'ensitiedote',
                                    'yellow' => 'vaaratilanne',
                                    'green' => 'tutkintatiedote',
                                ];

                                $currentFlashVersionType = $currentVersionTypeMap[$flash['type'] ?? 'green'] ?? 'tutkintatiedote';
                                $currentFlashLang = strtoupper((string)($flash['lang'] ?? 'FI'));

                                usort($snapshots, static function (array $a, array $b) use ($currentFlashVersionType, $currentFlashLang): int {
                                    $aType = (string)($a['version_type'] ?? '');
                                    $bType = (string)($b['version_type'] ?? '');
                                    $aLang = strtoupper((string)($a['lang'] ?? 'FI'));
                                    $bLang = strtoupper((string)($b['lang'] ?? 'FI'));

                                    $aIsCurrent = ($aType === $currentFlashVersionType && $aLang === $currentFlashLang);
                                    $bIsCurrent = ($bType === $currentFlashVersionType && $bLang === $currentFlashLang);

                                    if ($aIsCurrent && !$bIsCurrent) {
                                        return -1;
                                    }
                                    if (!$aIsCurrent && $bIsCurrent) {
                                        return 1;
                                    }

                                    $aPublished = strtotime((string)($a['published_at'] ?? '')) ?: 0;
                                    $bPublished = strtotime((string)($b['published_at'] ?? '')) ?: 0;

                                    if ($aPublished === $bPublished) {
                                        return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
                                    }

                                    return $bPublished <=> $aPublished;
                                });

                                $currentVersionMarked = false;
                                $totalVersions = count($snapshots);

                                foreach ($snapshots as $index => $snapshot): 
                                    $versionTypeLabel = sf_term('version_' . $snapshot['version_type'], $currentUiLang) ?? $snapshot['version_type'];
                                    $publisherName = trim(($snapshot['first_name'] ?? '') . ' ' . ($snapshot['last_name'] ?? ''));
                                    if ($publisherName === '') {
                                        $publisherName = sf_term('log_system_user', $currentUiLang) ?? 'System';
                                    }
                                    $publishedDate = date('d.m.Y', strtotime($snapshot['published_at']));
                                    $publishedTime = date('H:i', strtotime($snapshot['published_at']));
                                    $versionNum = $totalVersions - $index;
                                    
                                    // Map snapshot's version_type to icon and color
                                    $snapshotVersionType = $snapshot['version_type'] ?? 'vaaratilanne';
                                    $versionIcon = $versionTypeIcons[$snapshotVersionType] ?? 'icon-yellow.png';
                                    $versionColorClass = $versionTypeColors[$snapshotVersionType] ?? 'yellow';

                                    $snapshotLang = strtoupper($snapshot['lang'] ?? 'FI');

                                    $isLatest = false;
                                    if (
                                        !$currentVersionMarked
                                        && $snapshotVersionType === $currentFlashVersionType
                                        && $snapshotLang === $currentFlashLang
                                    ) {
                                        $isLatest = true;
                                        $currentVersionMarked = true;
                                    }
                                    
                                    // Language display
                                    $langFlags = [
                                        'FI' => '🇫🇮',
                                        'SV' => '🇸🇪',
                                        'EN' => '🇬🇧',
                                        'IT' => '🇮🇹',
                                        'EL' => '🇬🇷',
                                    ];
                                    $langFlag = $langFlags[$snapshotLang] ?? '';
                                ?>
                                    <div class="sf-version-card sf-version-type-<?= htmlspecialchars($versionColorClass, ENT_QUOTES, 'UTF-8') ?><?= $isLatest ? ' sf-version-latest' : '' ?>">
                                        <?php if ($isLatest): ?>
                                            <span class="sf-version-badge"><?= htmlspecialchars(sf_term('version_current', $currentUiLang) ?? 'Current', ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        
                                        <div class="sf-version-header">
                                            <div class="sf-version-icon-wrapper">
                                                <!-- USE TYPE-SPECIFIC ICON -->
                                                <img src="<?= $base ?>/assets/img/<?= htmlspecialchars($versionIcon, ENT_QUOTES, 'UTF-8') ?>" alt="" class="sf-version-icon">
                                            </div>
                                            <div class="sf-version-title-block">
                                                <h4 class="sf-version-type-label">
                                                    <?= htmlspecialchars($versionTypeLabel, ENT_QUOTES, 'UTF-8') ?>
                                                    <span class="sf-version-lang-badge"><?= $langFlag ?> <?= $snapshotLang ?></span>
                                                </h4>
                                                <span class="sf-version-number">
                                                    <?= htmlspecialchars(sf_term('version_number', $currentUiLang) ?? 'Version', ENT_QUOTES, 'UTF-8') ?> <?= $versionNum ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="sf-version-meta-row">
                                            <div class="sf-version-meta-item">
                                                <img src="<?= $base ?>/assets/img/icons/timeline.svg" alt="" class="sf-version-meta-icon">
                                                <span class="sf-version-date"><?= htmlspecialchars($publishedDate, ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="sf-version-time"><?= htmlspecialchars($publishedTime, ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="sf-version-meta-item">
                                                <img src="<?= $base ?>/assets/img/icons/user.svg" alt="" class="sf-version-meta-icon">
                                                <span class="sf-version-publisher"><?= htmlspecialchars($publisherName, ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <button class="sf-version-view-btn" 
                                                    onclick="openVersionModal('<?= htmlspecialchars($base . $snapshot['image_path'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($versionTypeLabel, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($snapshot['published_at'], ENT_QUOTES, 'UTF-8') ?>')">
                                                <img src="<?= $base ?>/assets/img/icons/eye_icon.svg" alt="" class="sf-btn-icon">
                                                <?= htmlspecialchars(sf_term('version_view', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- IMAGES TAB -->
                <div class="sf-tab-content" id="tabImages">
                    <div class="images-tab-content">
                        <div class="images-loading" id="imagesLoading">
                            <div class="images-spinner" role="status" aria-live="polite" aria-label="<?= htmlspecialchars(sf_term('images_loading', $currentUiLang) ?: 'Ladataan kuvia...', ENT_QUOTES, 'UTF-8') ?>"></div>
                            <p><?= htmlspecialchars(sf_term('images_loading', $currentUiLang) ?: 'Ladataan kuvia...', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div id="imagesUploadContainer" class="sf-media-toolbar" style="display: <?= !empty($canAddExtraImages) ? 'flex' : 'none' ?>;">
    <button type="button" id="imagesUploadBtn" class="sf-media-add-btn sf-media-add-btn--primary" aria-label="<?= htmlspecialchars(sf_term('add_images_btn', $currentUiLang) ?: 'Lisää kuvia', ENT_QUOTES, 'UTF-8') ?>">
        <span class="sf-media-add-icon">+</span>
        <span><?= htmlspecialchars(sf_term('add_images_btn', $currentUiLang) ?: 'Lisää kuvia', ENT_QUOTES, 'UTF-8') ?></span>
    </button>
    <button type="button" id="imagesUploadVideoBtn" class="sf-media-add-btn sf-media-add-btn--secondary" aria-label="<?= htmlspecialchars(sf_term('add_video_btn', $currentUiLang) ?: 'Lisää video', ENT_QUOTES, 'UTF-8') ?>">
        <span class="sf-media-add-icon">+</span>
        <span><?= htmlspecialchars(sf_term('add_video_btn', $currentUiLang) ?: 'Lisää video', ENT_QUOTES, 'UTF-8') ?></span>
    </button>
</div>
                        <div class="images-drop-overlay" id="imagesDropOverlay" aria-hidden="true">
                            <span><?= htmlspecialchars(sf_term('upload_drop_here', $currentUiLang) ?: 'Pudota tähän', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="images-grid" id="imagesGrid" style="display: none;">
                            <!-- Images will be loaded here by JavaScript -->
                        </div>
                        <div class="no-images-message" id="noImagesMessage" style="display: none;">
                            <p><?= htmlspecialchars(sf_term('no_media_message', $currentUiLang) ?: 'Ei mediaa saatavilla.', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </div>

            </div>
        </div>

<!-- Oikea palsta -->
        <div class="view-right">

            <?php
            // Hae kaikki kieliversiot oikean palstan tilakorttia varten
            $stmtAllLangVers = $pdo->prepare("
                SELECT id, lang, state, title, published_at FROM sf_flashes
                WHERE id = :gid OR translation_group_id = :gid2
                ORDER BY FIELD(lang, 'fi', 'sv', 'en', 'it', 'el')
            ");
            $stmtAllLangVers->execute([':gid' => $translationGroupId, ':gid2' => $translationGroupId]);
            $allLangVersions = $stmtAllLangVers->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php /* Kieliversiot-osio poistettu sidebarista — tuplatieto, näkyy jo yläosan välilehtinä */ ?>

            <?php
            // Tarkista onko tämä julkaisematon kieliversio jonka ryhmässä on jo julkaistuja,
            // tai kieliversio awaiting_publish-tilassa (odottaa erillistä julkaisua)
            $isUnpublishedTranslation = false;
            if ($flash['state'] === 'awaiting_publish') {
                // awaiting_publish-tilassa näytetään aina julkaisupainike
                $isUnpublishedTranslation = true;
            } elseif ($flash['state'] === 'draft' && !empty($flash['translation_group_id'])) {
                $stmtGroupPublished = $pdo->prepare("
                    SELECT COUNT(*) FROM sf_flashes
                    WHERE (id = ? OR translation_group_id = ?)
                      AND state IN ('to_comms', 'awaiting_publish', 'published') AND id != ?
                ");
                $gidCheck = (int)$flash['translation_group_id'];
                $stmtGroupPublished->execute([$gidCheck, $gidCheck, $flash['id']]);
                $isUnpublishedTranslation = (int)$stmtGroupPublished->fetchColumn() > 0;
            }
            ?>

            <?php if (in_array('publish', $actions, true)): ?>
            <div class="sf-card sf-publish-single-card">
                <h4>
                    <?= sf_lang_flag($flash['lang']) ?>
                    <?= htmlspecialchars(sf_term('footer_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </h4>
                <p class="sf-help-text">
                    <?= htmlspecialchars(sf_term('publish_original_description', $currentUiLang) ?? 'Julkaise SafetyFlash infonäytöille ja valituille jakelukanaville.', ENT_QUOTES, 'UTF-8') ?>
                </p>
                <button type="button"
                        class="sf-btn sf-btn-primary"
                        id="btnPublishOriginal"
                        data-flash-id="<?= (int)$flash['id'] ?>"
                        onclick="openPublishModal()">
                    <?= htmlspecialchars(sf_term('footer_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?> →
                </button>
            </div>
            <?php endif; ?>

            <?php if ($isUnpublishedTranslation && in_array('publish_single', $actions, true)): ?>
            <div class="sf-card sf-publish-single-card">
                <h4>
                    <?= sf_lang_flag($flash['lang']) ?>
                    <?= htmlspecialchars(sf_term('publish_language_version', $currentUiLang) ?? 'Julkaise kieliversio', ENT_QUOTES, 'UTF-8') ?>
                </h4>
                <p class="sf-help-text">
                    <?= htmlspecialchars(sf_term('publish_single_description', $currentUiLang) ?? 'Tämä kieliversio on luonnos. Julkaise se erikseen omille infonäytöilleen.', ENT_QUOTES, 'UTF-8') ?>
                </p>
                <button type="button"
                        class="sf-btn sf-btn-primary"
                        id="btnPublishSingleLang"
                        data-flash-id="<?= (int)$flash['id'] ?>"
                        data-flash-lang="<?= htmlspecialchars($flash['lang'], ENT_QUOTES, 'UTF-8') ?>"
                        onclick="openPublishSingleModal()">
                    <?= htmlspecialchars(sf_term('btn_publish_language_version', $currentUiLang) ?? 'Julkaise tämä kieliversio →', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
            <?php endif; ?>



            <?php if (file_exists(__DIR__ . '/../partials/view_playlist_status.php')): ?>
                <?php require __DIR__ . '/../partials/view_playlist_status.php'; ?>
            <?php endif; ?>

            <div class="view-meta-include">
                <?php require __DIR__ . '/../partials/view_meta_box.php'; ?>
            </div>

        </div>
    </div> <!-- .view-layout -->

</div> <!-- .view-container -->
</div> <!-- .sf-page-container -->

<!-- Hidden select for body map quick-edit (pre-populated with current body parts) -->
<select id="sfInjuredPartsHidden" multiple class="sf-form-hidden" style="display:none;" aria-hidden="true">
    <?php foreach ($existing_body_parts as $svgId): ?>
        <option value="<?= htmlspecialchars($svgId, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($svgId, ENT_QUOTES, 'UTF-8') ?></option>
    <?php endforeach; ?>
</select>

<?php
// Body map modal — available for all report types
$uiLang = $currentUiLang;
include __DIR__ . '/../partials/body_map_modal.php';
?>

<!-- ===== MODALIT ===== -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalEdit" role="dialog" aria-modal="true" aria-labelledby="modalEditTitle">
    <div class="sf-modal-content">
        <h2 id="modalEditTitle">
            <?= htmlspecialchars(sf_term('modal_edit_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('modal_edit_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button
              type="button"
              class="sf-btn sf-btn-secondary"
              data-modal-close="modalEdit"
            >
              <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button
              type="button"
              class="sf-btn sf-btn-primary"
              id="modalEditOk"
            >
              <?= htmlspecialchars(sf_term('btn_ok_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<div class="sf-modal hidden" data-bottom-sheet="true" id="modalComment" role="dialog" aria-modal="true" aria-labelledby="modalCommentTitle">
    <div class="sf-modal-content">
        <h2 id="modalCommentTitle">
            <?= htmlspecialchars(sf_term('modal_comment_title', $currentUiLang) ?? 'Lisää kommentti', ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/comment.php?id=<?= (int)$id ?>" id="commentForm">
            <?= sf_csrf_field() ?>
            <input type="hidden" id="editCommentId" name="comment_id" value="">
            <label for="commentMessage">
                <?= htmlspecialchars(sf_term('modal_comment_label', $currentUiLang) ?? 'Kommentti', ENT_QUOTES, 'UTF-8') ?>
            </label>
            <div style="position:relative;">
                <textarea
                  id="commentMessage"
                  name="message"
                  rows="4"
                  maxlength="2000"
                  placeholder="<?= htmlspecialchars(sf_term('modal_comment_placeholder', $currentUiLang) ?? '', ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="off"
                ></textarea>
                <div id="mentionDropdown" class="sf-mention-dropdown" style="display:none;" role="listbox" aria-label="User suggestions"></div>
            </div>
            <div id="mentionedUsersContainer"></div>

<div id="commentNotifyWrap" style="margin-top:14px;">
    <input type="hidden" name="comment_notifications_enabled" value="0">
    <label for="commentNotificationsEnabled" style="display:flex;align-items:center;gap:10px;font-weight:600;cursor:pointer;">
        <input
            type="checkbox"
            id="commentNotificationsEnabled"
            name="comment_notifications_enabled"
            value="1"
            <?= !empty($commentNotificationsChecked) ? 'checked' : '' ?>
        >
        <?= htmlspecialchars(sf_term('comment_email_subscribe', $_SESSION['ui_lang'] ?? 'fi'), ENT_QUOTES, 'UTF-8') ?>
    </label>
</div>

            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalComment"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                  <?= htmlspecialchars(sf_term('btn_comment_send', $currentUiLang) ?? 'Tallenna kommentti', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($canAccessSettings): ?>

<div class="sf-modal hidden" data-bottom-sheet="true" id="modalMergeFlash" role="dialog" aria-modal="true" aria-labelledby="modalMergeFlashTitle">
    <div class="sf-modal-content sf-modal-merge-flash">
        <h2 id="modalMergeFlashTitle">
            <?= htmlspecialchars(sf_term('modal_merge_flash_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>

        <p style="margin-bottom: 1rem; color: #4b5563; line-height: 1.5;">
            <?= htmlspecialchars(sf_term('modal_merge_flash_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <div style="margin-bottom: 1rem;">
            <input
                type="text"
                id="sfMergeSearchInput"
                class="sf-input"
                placeholder="<?= htmlspecialchars(sf_term('modal_merge_flash_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                style="width: 100%;"
            >
        </div>

        <div id="sfMergeFlashStatus" style="margin-bottom: 0.75rem; color: #6b7280; font-size: 0.95rem;"></div>

        <div
            id="sfMergeCandidateList"
            style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 380px; overflow-y: auto; margin-bottom: 1rem;"
        ></div>

        <div
            id="sfMergeConfirmBox"
            style="display: none; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem; margin-bottom: 1rem;"
        >
            <p style="margin: 0; color: #374151; line-height: 1.5;">
                <?= htmlspecialchars(sf_term('modal_merge_flash_confirm_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalMergeFlash">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="sfMergeConfirmBtn" disabled>
                <?= htmlspecialchars(sf_term('btn_merge_flash', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<div class="sf-modal hidden" data-bottom-sheet="true" id="sfAdditionalInfoModal" role="dialog" aria-modal="true" aria-labelledby="sfAdditionalInfoModalTitle">
    <div class="sf-modal-content">
        <h2 id="sfAdditionalInfoModalTitle">
            <?= htmlspecialchars(sf_term('additional_info_modal_add_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form id="sfAdditionalInfoForm">
    <input type="hidden" id="sfAdditionalInfoEditId" value="">
    <div id="sfAdditionalInfoEditorLabel" class="sf-label">
        <?= htmlspecialchars(sf_term('additional_info_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div id="sfAdditionalInfoEditor" style="min-height: 140px; background: #fff;" role="textbox" aria-multiline="true" aria-labelledby="sfAdditionalInfoEditorLabel"></div>
    <span id="sfAdditionalInfoStatus" style="display:block; font-size: 0.875rem; min-height: 1.2em;" aria-live="polite"></span>
            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="sfAdditionalInfoModal">
                    <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary" id="sfAdditionalInfoSubmitBtn">
                    <?= htmlspecialchars(sf_term('btn_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="sf-modal hidden" data-bottom-sheet="true" id="modalRequestInfo" role="dialog" aria-modal="true" aria-labelledby="modalRequestInfoTitle">
    <div class="sf-modal-content">
        <h2 id="modalRequestInfoTitle">
            <?= htmlspecialchars(sf_term('modal_request_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/request_info.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>
            <label for="reqMessage">
                <?= htmlspecialchars(sf_term('modal_request_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <textarea
              id="reqMessage"
              name="message"
              rows="4"
              placeholder="<?= htmlspecialchars(sf_term('modal_request_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            ></textarea>
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalRequestInfo"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                  <?= htmlspecialchars(sf_term('btn_send_request', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Send to Communications (Multi-step) -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalToComms" role="dialog" aria-modal="true" aria-labelledby="modalToCommsTitle">
    <div class="sf-modal-content sf-modal-comms">
        
        <form id="commsForm">
        <?= sf_csrf_field() ?>

        <!-- STEP 1: Language Versions -->
        <div class="sf-comms-step" id="commsStep1">
            <h2 id="modalToCommsTitle">
                <?= htmlspecialchars(sf_term('modal_to_comms_title', $currentUiLang) ?? 'Lähetä viestintään', ENT_QUOTES, 'UTF-8') ?>
            </h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step active">1</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">2</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">3</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">4</span>
            </div>

                <div class="sf-field">
                    <label class="sf-label">
                        <?= htmlspecialchars(sf_term('comms_step1_languages', $currentUiLang) ?? 'Valitse kieliversiot', ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <p class="sf-help-text">
                        <?= htmlspecialchars(sf_term('comms_step1_languages_help', $currentUiLang) ?? 'Valitse mitkä kieliversiot lähetetään viestintään', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    
                    <div class="sf-language-chips">
                        <?php foreach ($supportedLangs as $langCode => $langData): 
                            $isDefault = ($langCode === 'fi');
                        ?>
                            <label class="sf-chip-toggle <?= $isDefault ? 'selected' : '' ?>">
                                <input type="checkbox" 
                                       name="languages[]" 
                                       value="<?= htmlspecialchars($langCode) ?>"
                                       <?= $isDefault ? 'checked' : '' ?>>
                                <img src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon']) ?>" 
                                     alt="<?= htmlspecialchars($langData['label']) ?>"
                                     class="lang-flag-img"
                                     style="width: 26px; height: 26px; border-radius: 50%; object-fit: cover;">
                                <span><?= htmlspecialchars($langData['label']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalToComms">
                    <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnCommsStep1Next">
                    <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                </button>
            </div>
        </div>

        <!-- STEP 2: Display targets -->
        <div class="sf-comms-step hidden sf-comms-display-step" id="commsStep2">
            <div class="sf-comms-display-header">
                <h2><?= htmlspecialchars(sf_term('publish_step2_title', $currentUiLang) ?? 'Työmaan infonäytöt', ENT_QUOTES, 'UTF-8') ?></h2>

                <div class="sf-step-indicator sf-step-indicator-inline">
                    <span class="sf-step done">✓</span>
                    <span class="sf-step-line done"></span>
                    <span class="sf-step active">2</span>
                    <span class="sf-step-line"></span>
                    <span class="sf-step">3</span>
                    <span class="sf-step-line"></span>
                    <span class="sf-step">4</span>
                </div>
            </div>

            <input type="hidden" name="screens_option" value="selected">

            <div id="commsScreensSelection" class="sf-comms-display-selection">
                <?php
                $commsOriginalFlash = $flash;
                $stmtCommsVersions = $pdo->prepare("
                    SELECT id, lang, title FROM sf_flashes
                    WHERE id = :gid OR translation_group_id = :gid2
                    ORDER BY FIELD(lang, 'fi', 'sv', 'en', 'it', 'el')
                ");
                $stmtCommsVersions->execute([':gid' => $translationGroupId, ':gid2' => $translationGroupId]);
                $commsLangVersions = $stmtCommsVersions->fetchAll(PDO::FETCH_ASSOC);
                $commsVersionCount = count($commsLangVersions);

                foreach ($commsLangVersions as $commsVer):
                    $flash = $commsVer;
                    $context = 'safety_team';
                    unset($preselectedIds);
                    ?>

                    <?php if ($commsVersionCount > 1): ?>
                        <div class="sf-comms-language-section">
                            <?php
                            $langData = $supportedLangs[$commsVer['lang']] ?? null;
                            if ($langData): ?>
                                <img
                                    src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon']) ?>"
                                    alt="<?= htmlspecialchars($langData['label'], ENT_QUOTES, 'UTF-8') ?>"
                                    class="sf-comms-language-flag"
                                >
                                <span><?= htmlspecialchars($langData['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span><?= htmlspecialchars(strtoupper($commsVer['lang']), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    require __DIR__ . '/../partials/display_target_selector.php';
                endforeach;

                $flash = $commsOriginalFlash;
                ?>
            </div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="btnCommsStep2Back">
                    ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnCommsStep2Next">
                    <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                </button>
            </div>
        </div>

        <!-- STEP 3: Distribution (Simplified) -->
        <div class="sf-comms-step hidden" id="commsStep3">
            <h2><?= htmlspecialchars(sf_term('modal_to_comms_title', $currentUiLang) ?? 'Lähetä viestintään', ENT_QUOTES, 'UTF-8') ?></h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step active">3</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">4</span>
            </div>

            <div class="sf-field">
                <label class="sf-label">
                    <?= htmlspecialchars(sf_term('comms_step3_wider_distribution', $currentUiLang) ?? 'Laajempi jakelu', ENT_QUOTES, 'UTF-8') ?>
                </label>
                <p class="sf-help-text">
                    <?= htmlspecialchars(sf_term('comms_step3_wider_distribution_help', $currentUiLang) ?? 'Lähetä SafetyFlash myös laajemmalle jakelulistalle', ENT_QUOTES, 'UTF-8') ?>
                </p>
                
                <div class="sf-toggle-card">
                    <div class="sf-toggle-card-content">
                        <div class="sf-toggle-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21.21 15.89A10 10 0 1 1 8 2.83"/>
                                <path d="M22 12A10 10 0 0 0 12 2v10z"/>
                            </svg>
                        </div>
                        <div class="sf-toggle-text">
                            <strong><?= htmlspecialchars(sf_term('comms_wider_distribution_label', $currentUiLang) ?? 'Lähetä laajempaan jakeluun', ENT_QUOTES, 'UTF-8') ?></strong>
                            <small id="widerDistributionLabel"><?= htmlspecialchars(sf_term('comms_wider_distribution_no', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </div>
                    <label class="sf-modern-toggle">
                        <input type="checkbox" name="wider_distribution" id="widerDistribution" value="1">
                        <span class="sf-modern-toggle-track">
                            <span class="sf-modern-toggle-thumb"></span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="btnCommsStep3Back">
                    ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnCommsStep3Next">
                    <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                </button>
            </div>
        </div>

        <!-- STEP 4: Summary & Message -->
        <div class="sf-comms-step hidden" id="commsStep4">
            <h2><?= htmlspecialchars(sf_term('modal_to_comms_title', $currentUiLang) ?? 'Lähetä viestintään', ENT_QUOTES, 'UTF-8') ?></h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step active">4</span>
            </div>

            <div class="sf-publish-final-notification sf-comms-worksite-notification-card">
                <div class="sf-publish-final-notification-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                        <polyline points="22,6 12,13 2,6"></polyline>
                    </svg>
                </div>

                <div class="sf-publish-final-notification-text">
                    <strong>
                        <?= htmlspecialchars(sf_term('publish_worksite_notification', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <small>
                        <?= htmlspecialchars(sf_term('publish_worksite_notification_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </small>
                    <small id="commsWorksiteNotificationCount"></small>
                </div>

                <label class="sf-publish-final-switch" aria-label="<?= htmlspecialchars(sf_term('publish_worksite_notification', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="send_worksite_notification_preselected" value="0">
                    <input
                        type="checkbox"
                        name="send_worksite_notification_preselected"
                        id="commsSendWorksiteNotification"
                        value="1"
                        checked
                    >
                    <span></span>
                </label>
            </div>

            <div class="sf-comms-summary">
                <h3><?= htmlspecialchars(sf_term('comms_summary_title', $currentUiLang) ?? 'Yhteenveto', ENT_QUOTES, 'UTF-8') ?></h3>
                
                <div class="sf-summary-item">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/globe.svg" alt="" class="sf-summary-icon">
                    <strong><?= htmlspecialchars(sf_term('comms_summary_languages', $currentUiLang) ?? 'Kieliversiot', ENT_QUOTES, 'UTF-8') ?></strong>
                    <span id="commsSummaryLanguages">-</span>
                </div>
                
                <div class="sf-summary-item">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/screen.svg" alt="" class="sf-summary-icon">
                    <strong><?= htmlspecialchars(sf_term('comms_summary_screens', $currentUiLang) ?? 'Xibo-näytöt', ENT_QUOTES, 'UTF-8') ?></strong>
                    <span id="commsSummaryScreens">-</span>
                </div>
                
                <div class="sf-summary-item">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/megaphone.svg" alt="" class="sf-summary-icon">
                    <strong><?= htmlspecialchars(sf_term('comms_summary_distribution', $currentUiLang) ?? 'Jakelu', ENT_QUOTES, 'UTF-8') ?></strong>
                    <span id="commsSummaryDistribution">-</span>
                </div>

                <div class="sf-summary-item">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/distribution.svg" alt="" class="sf-summary-icon">
                    <strong><?= htmlspecialchars(sf_term('publish_summary_worksite_notification', $currentUiLang) ?? 'Ilmoitus työmaille', ENT_QUOTES, 'UTF-8') ?></strong>
                    <span id="commsSummaryWorksiteNotification">-</span>
                </div>
            </div>

            <div class="sf-field" style="margin-top: 1.5rem;">
                <label for="commsMessage" class="sf-label">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/comment.svg" alt="" style="width: 16px; height: 16px; opacity: 0.7; margin-right: 4px; vertical-align: middle;">
                    <?= htmlspecialchars(sf_term('modal_to_comms_label', $currentUiLang) ?? 'Viesti viestintään (valinnainen)', ENT_QUOTES, 'UTF-8') ?>
                </label>
                <textarea
                  id="commsMessage"
                  name="message"
                  rows="4"
                  class="sf-textarea"
                  placeholder="<?= htmlspecialchars(sf_term('modal_to_comms_placeholder', $currentUiLang) ?? 'Lisätiedot viestintätiimille...', ENT_QUOTES, 'UTF-8') ?>"
                ></textarea>
            </div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="btnCommsStep4Back">
                    ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary" id="btnCommsSend">
                    <?= htmlspecialchars(sf_term('btn_send_comms', $currentUiLang) ?? 'Lähetä viestintään', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </div>

        </form>
    </div>
</div>

<div class="sf-modal hidden" data-bottom-sheet="true" id="modalPublishDirect" role="dialog" aria-modal="true" aria-labelledby="modalPublishDirectTitle">
    <div class="sf-modal-content">
        <h2 id="modalPublishDirectTitle">
            <?= htmlspecialchars(sf_term('modal_publish_direct_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>

        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/publish_direct.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>

            <p>
                <?= htmlspecialchars(sf_term('modal_publish_direct_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>

            <label for="publishDirectMessage">
                <?= htmlspecialchars(sf_term('modal_publish_direct_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>

            <textarea
                id="publishDirectMessage"
                name="message"
                rows="5"
                required
                placeholder="<?= htmlspecialchars(sf_term('modal_publish_direct_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            ></textarea>

            <p class="sf-help-text">
                <?= htmlspecialchars(sf_term('modal_publish_direct_help', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>

            <div class="sf-modal-actions">
                <button
                    type="button"
                    class="sf-btn sf-btn-secondary"
                    data-modal-close="modalPublishDirect"
                >
                    <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>

                <button type="submit" class="sf-btn sf-btn-primary">
                    <?= htmlspecialchars(sf_term('btn_publish_direct', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Send to Safety Team (from Supervisor) -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalSendSafety" role="dialog" aria-modal="true" aria-labelledby="modalSendSafetyTitle">
    <div class="sf-modal-content">
        <h2 id="modalSendSafetyTitle">
            <?= htmlspecialchars(sf_term('modal_send_safety_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/supervisor_to_safety.php">
            <?= sf_csrf_field() ?>
            <input type="hidden" name="flash_id" value="<?= (int)$id ?>">
            <label for="safetyMessage">
                <?= htmlspecialchars(sf_term('modal_send_safety_message_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <textarea
              id="safetyMessage"
              name="message"
              rows="4"
              placeholder="<?= htmlspecialchars(sf_term('modal_send_safety_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            ></textarea>
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalSendSafety"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                  <?= htmlspecialchars(sf_term('footer_send_to_safety', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$publishFlash = $flash;
$publishModalId = 'modalPublish';
$publishFormId = 'publishForm';
$publishIsLanguageVersion = false;
require __DIR__ . '/../partials/publish_stepper_modal.php';
?>

<!-- Publish Single Language Version Modal -->
<?php
$publishFlash = $flash;
$publishModalId = 'publishSingleModal';
$publishFormId = 'publishSingleForm';
$publishIsLanguageVersion = true;
require __DIR__ . '/../partials/publish_stepper_modal.php';
?>

<div class="sf-modal hidden" data-bottom-sheet="true" id="modalDelete" role="dialog" aria-modal="true" aria-labelledby="modalDeleteTitle">
    <div class="sf-modal-content">
        <h2 id="modalDeleteTitle">
            <?= htmlspecialchars(sf_term('modal_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <div id="deleteModalContent">
            <!-- Content will be populated by JavaScript -->
            <p>
                <?= htmlspecialchars(sf_term('modal_delete_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/delete.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalDelete"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-danger">
                  <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($canRequestLanguageReview)): ?>
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalLanguageReview" role="dialog" aria-modal="true" aria-labelledby="modalLanguageReviewTitle">
    <div class="sf-modal-content sf-language-review-modal">
        <div class="sf-modal-header">
            <h2 id="modalLanguageReviewTitle">
                <?= htmlspecialchars(sf_term('language_review_modal_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <button type="button" class="sf-modal-close-btn" data-modal-close="modalLanguageReview">×</button>
        </div>

        <div class="sf-modal-body">
            <p class="sf-language-review-help">
                <?= htmlspecialchars(sf_term('language_review_modal_help', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>

            <div class="sf-language-review-message-field">
                <label class="sf-label" for="languageReviewMessage">
                    <?= htmlspecialchars(sf_term('language_review_message_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <textarea
                    id="languageReviewMessage"
                    class="sf-language-review-message"
                    rows="3"
                    placeholder="<?= htmlspecialchars(sf_term('language_review_message_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                ></textarea>
            </div>

            <div class="sf-language-review-list">
                <?php foreach ($languageReviewOpenLanguageCodes as $reviewLang): ?>
                    <?php
                    $langData = $supportedLangs[$reviewLang] ?? null;
                    $translationId = isset($translations[$reviewLang]) ? (int)$translations[$reviewLang] : 0;

                    if (!$langData || $translationId <= 0) {
                        continue;
                    }
                    ?>
                    <div class="sf-language-review-row" data-language-review-row data-lang="<?= htmlspecialchars($reviewLang, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="sf-language-review-lang">
                            <img class="sf-language-review-flag" src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                            <div>
                                <strong><?= htmlspecialchars($langData['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars(sf_term('language_review_language_' . $reviewLang, $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>

                        <div class="sf-language-review-user">
                            <label class="sf-label" for="languageReviewerSearch_<?= htmlspecialchars($reviewLang, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(sf_term('language_review_select_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            </label>

                            <div class="sf-search-select">
                                <input
                                    type="text"
                                    id="languageReviewerSearch_<?= htmlspecialchars($reviewLang, ENT_QUOTES, 'UTF-8') ?>"
                                    class="sf-search-input language-review-search"
                                    data-lang="<?= htmlspecialchars($reviewLang, ENT_QUOTES, 'UTF-8') ?>"
                                    placeholder="<?= htmlspecialchars(sf_term('language_review_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                                    autocomplete="off"
                                >
                                <div class="sf-search-dropdown hidden language-review-dropdown" id="languageReviewerDropdown_<?= htmlspecialchars($reviewLang, ENT_QUOTES, 'UTF-8') ?>"></div>
                            </div>

                            <input type="hidden" class="language-review-user-ids" id="languageReviewerIds_<?= htmlspecialchars($reviewLang, ENT_QUOTES, 'UTF-8') ?>" value="[]">
                            <div class="selected-reviewer-display hidden" id="languageReviewerSelected_<?= htmlspecialchars($reviewLang, ENT_QUOTES, 'UTF-8') ?>"></div>
                        </div>

<label class="sf-language-review-publish" for="languageReviewCanPublish_<?= htmlspecialchars($reviewLang, ENT_QUOTES, 'UTF-8') ?>">
    <input
        type="checkbox"
        id="languageReviewCanPublish_<?= htmlspecialchars($reviewLang, ENT_QUOTES, 'UTF-8') ?>"
        name="language_review_can_publish[<?= htmlspecialchars($reviewLang, ENT_QUOTES, 'UTF-8') ?>]"
        class="language-review-can-publish"
        value="1"
        checked
    >
    <span><?= htmlspecialchars(sf_term('language_review_can_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
</label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalLanguageReview">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" id="btnSendLanguageReviewRequests" class="sf-btn sf-btn-primary">
                <?= htmlspecialchars(sf_term('language_review_send_requests', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reviewer Modals -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalAddReviewer" role="dialog" aria-modal="true" aria-labelledby="modalAddReviewerTitle">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h2 id="modalAddReviewerTitle">
                <?= htmlspecialchars(sf_term('reviewer_add_modal_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <button type="button" class="sf-modal-close-btn" data-modal-close="modalAddReviewer">×</button>
        </div>
        
        <div class="sf-modal-body">
            <div class="sf-field">
                <label for="reviewerSearch" class="sf-label">
                    <?= htmlspecialchars(sf_term('reviewer_select_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div class="sf-search-select">
                    <input type="text" 
                           id="reviewerSearch" 
                           class="sf-search-input"
                           placeholder="<?= htmlspecialchars(sf_term('reviewer_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off">
                    <div class="sf-search-dropdown hidden" id="reviewerSearchDropdown">
                        <!-- Dynamically populated by JavaScript -->
                    </div>
                </div>
                <input type="hidden" id="selectedReviewerId" value="">
                <div id="selectedReviewerDisplay" class="selected-reviewer-display hidden"></div>
            </div>
        </div>
        
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalAddReviewer">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" id="btnAddReviewer" class="sf-btn sf-btn-primary">
                <?= htmlspecialchars(sf_term('add_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<div class="sf-modal hidden" data-bottom-sheet="true" id="modalReplaceReviewer" role="dialog" aria-modal="true" aria-labelledby="modalReplaceReviewerTitle">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h2 id="modalReplaceReviewerTitle">
                <?= htmlspecialchars(sf_term('reviewer_replace_modal_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <button type="button" class="sf-modal-close-btn" data-modal-close="modalReplaceReviewer">×</button>
        </div>
        
        <div class="sf-modal-body">
            <div class="sf-field" id="currentReviewersSection">
                <label class="sf-label">
                    <?= htmlspecialchars(sf_term('reviewer_current_reviewers', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div id="currentReviewersDisplay" class="current-reviewers-display">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
            
            <div class="sf-field">
                <label for="reviewerSearchReplace" class="sf-label">
                    <?= htmlspecialchars(sf_term('reviewer_select_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div class="sf-search-select">
                    <input type="text" 
                           id="reviewerSearchReplace" 
                           class="sf-search-input"
                           placeholder="<?= htmlspecialchars(sf_term('reviewer_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off">
                    <div class="sf-search-dropdown hidden" id="reviewerSearchReplaceDropdown">
                        <!-- Dynamically populated by JavaScript -->
                    </div>
                </div>
                <input type="hidden" id="selectedReviewerIdReplace" value="">
                <div id="selectedReviewerDisplayReplace" class="selected-reviewer-display hidden"></div>
            </div>
        </div>
        
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalReplaceReviewer">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" id="btnReplaceReviewer" class="sf-btn sf-btn-primary">
                <?= htmlspecialchars(sf_term('replace_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<script>
// Store flash data for delete modal
window.sfFlashData = {
    id: <?= (int)$id ?>,
    translationGroupId: <?= !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : 'null' ?>,
    lang: '<?= htmlspecialchars($flash['lang'] ?? 'fi', ENT_QUOTES, 'UTF-8') ?>',
    isTranslation: <?= !empty($flash['translation_group_id']) ? 'true' : 'false' ?>,
    uiLang: '<?= htmlspecialchars($currentUiLang, ENT_QUOTES, 'UTF-8') ?>',
    title: '<?= htmlspecialchars($flash['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>',
    type: '<?= htmlspecialchars($flash['type'] ?? '', ENT_QUOTES, 'UTF-8') ?>',
    site: '<?= htmlspecialchars($flash['site'] ?? '', ENT_QUOTES, 'UTF-8') ?>'
};

// Translation terms for JavaScript
window.sfDeleteTerms = {
    delete_original_confirm_title: <?= json_encode(sf_term('delete_original_confirm_title', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_original_confirm_message: <?= json_encode(sf_term('delete_original_confirm_message', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_original_versions_count: <?= json_encode(sf_term('delete_original_versions_count', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_translation_confirm_title: <?= json_encode(sf_term('delete_translation_confirm_title', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_translation_confirm_message: <?= json_encode(sf_term('delete_translation_confirm_message', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_translation_which: <?= json_encode(sf_term('delete_translation_which', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_fi: <?= json_encode(sf_term('lang_name_fi', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_sv: <?= json_encode(sf_term('lang_name_sv', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_en: <?= json_encode(sf_term('lang_name_en', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_it: <?= json_encode(sf_term('lang_name_it', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_el: <?= json_encode(sf_term('lang_name_el', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>
};

// Update delete modal content when opened
document.addEventListener('DOMContentLoaded', function() {
    const deleteButtons = document.querySelectorAll('[data-modal-open="modalDelete"]');
    
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            updateDeleteModalContent();
        });
    });
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getTypeInfo(type) {
    const typeMap = {
        'red': { dot: '🔴', label: 'Ensitiedote' },
        'yellow': { dot: '🟡', label: 'Vaaratilanne' },
        'green': { dot: '🟢', label: 'Tutkintatiedote' }
    };
    return typeMap[type] || { dot: '⚪', label: type };
}

function updateDeleteModalContent() {
    const modalTitle = document.getElementById('modalDeleteTitle');
    const modalContent = document.getElementById('deleteModalContent');
    const flashData = window.sfFlashData;
    const terms = window.sfDeleteTerms;
    
    if (!flashData.isTranslation) {
        // Deleting original - check for translations
        const groupId = flashData.id;
        
        // Fetch translations via AJAX
        const url = new URL('<?= htmlspecialchars($base) ?>/app/api/get_flash_translations.php', window.location.origin);
        url.searchParams.set('group_id', groupId);
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.ok && data.translations && Object.keys(data.translations).length > 0) {
                    // Has translations - show warning
                    const count = Object.keys(data.translations).length;
                    const langNames = [];
                    
                    for (const lang in data.translations) {
                        const langKey = 'lang_name_' + lang;
                        if (terms[langKey]) {
                            langNames.push(terms[langKey]);
                        }
                    }
                    
                    modalTitle.textContent = terms.delete_original_confirm_title;
                    
                    const typeInfo = getTypeInfo(flashData.type);
                    const flashDetails = typeInfo.dot + ' ' + typeInfo.label + ' – \'' + escapeHtml(flashData.title) + '\' (' + escapeHtml(flashData.site) + ')';
                    
                    let html = '<p style="margin-bottom: 1rem;"><strong>Poistetaan:</strong> ' + flashDetails + '</p>';
                    html += '<p style="margin-bottom: 0.5rem;">' + terms.delete_original_confirm_message + '</p>';
                    html += '<p style="margin-bottom: 0.5rem;"><strong>' + terms.delete_original_versions_count.replace('%d', count) + '</strong></p>';
                    html += '<ul style="margin-left: 1.5rem;">';
                    langNames.forEach(name => {
                        html += '<li>' + escapeHtml(name) + '</li>';
                    });
                    html += '</ul>';
                    
                    modalContent.innerHTML = html;
                } else {
                    // No translations - use default message
                    modalTitle.textContent = terms.delete_original_confirm_title;
                    
                    const typeInfo = getTypeInfo(flashData.type);
                    const flashDetails = typeInfo.dot + ' ' + typeInfo.label + ' – \'' + escapeHtml(flashData.title) + '\' (' + escapeHtml(flashData.site) + ')';
                    
                    let html = '<p style="margin-bottom: 1rem;"><strong>Poistetaan:</strong> ' + flashDetails + '</p>';
                    html += '<p>' + terms.delete_original_confirm_message + '</p>';
                    
                    modalContent.innerHTML = html;
                }
            })
            .catch(error => {
                console.error('Error fetching translations:', error);
                // Fallback to default message
                modalTitle.textContent = terms.delete_original_confirm_title;
                
                const typeInfo = getTypeInfo(flashData.type);
                const flashDetails = typeInfo.dot + ' ' + typeInfo.label + ' – \'' + escapeHtml(flashData.title) + '\' (' + escapeHtml(flashData.site) + ')';
                
                let html = '<p style="margin-bottom: 1rem;"><strong>Poistetaan:</strong> ' + flashDetails + '</p>';
                html += '<p>' + terms.delete_original_confirm_message + '</p>';
                
                modalContent.innerHTML = html;
            });
    } else {
        // Deleting translation
        const langKey = 'lang_name_' + flashData.lang;
        const langName = terms[langKey] || flashData.lang;
        
        // Get language flag emoji
        const langFlags = {
            'fi': '🇫🇮',
            'sv': '🇸🇪', 
            'en': '🇬🇧',
            'it': '🇮🇹',
            'el': '🇬🇷'
        };
        const flag = langFlags[flashData.lang] || '🏳️';
        
        modalTitle.textContent = terms.delete_translation_confirm_title;
        
        let html = '<p style="margin-bottom: 1rem;"><strong>Poistetaan:</strong> ' + flag + ' ' + escapeHtml(langName) + ' kieliversio tiedotteesta \'' + escapeHtml(flashData.title) + '\'</p>';
        html += '<p>' + terms.delete_translation_confirm_message + '</p>';
        
        modalContent.innerHTML = html;
    }
}
</script>

<!-- ARKISTOI-MODAALI -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalArchive" role="dialog" aria-modal="true" aria-labelledby="modalArchiveTitle">
    <div class="sf-modal-content">
        <h2 id="modalArchiveTitle">
            <?= htmlspecialchars(sf_term('archive_confirm_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('archive_confirm_message', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button
              type="button"
              class="sf-btn sf-btn-secondary"
              data-modal-close="modalArchive"
            >
              <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="modalArchiveConfirm">
              <?= htmlspecialchars(sf_term('btn_archive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- POISTA KOMMENTTI -MODAALI -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalDeleteComment" role="dialog" aria-modal="true" aria-labelledby="modalDeleteCommentTitle">
    <div class="sf-modal-content">
        <h2 id="modalDeleteCommentTitle">
            <?= htmlspecialchars(sf_term('comment_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('comment_delete_confirm', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="sf-help-text" style="color: #dc2626; margin-top: 0.5rem;">
            <?= htmlspecialchars(sf_term('comment_delete_warning', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalDeleteComment">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-danger" id="modalDeleteCommentConfirm">
                <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- POISTA LISÄTIETO -MODAALI -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalDeleteInfo" role="dialog" aria-modal="true" aria-labelledby="modalDeleteInfoTitle">
    <div class="sf-modal-content">
        <h2 id="modalDeleteInfoTitle">
            <?= htmlspecialchars(sf_term('comment_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('comment_delete_confirm', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalDeleteInfo">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-danger" id="modalDeleteInfoConfirm">
                <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- DELETE LOG ENTRY MODAL (admin only) -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalDeleteLog" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <h2><?= htmlspecialchars(sf_term('log_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars(sf_term('log_delete_confirm', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalDeleteLog">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-danger" id="confirmDeleteLog">
                <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- KIELIVERSIO-MODAALI (vaiheittainen) -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalTranslation" role="dialog" aria-modal="true" aria-labelledby="modalTranslationTitle">
    <div class="sf-modal-content sf-modal-translation">
        
        <!-- VAIHE 1: Lomake -->
        <div class="sf-translation-step" id="translationStep1">
            <h2 id="modalTranslationTitle">
                <?php echo htmlspecialchars(sf_term('modal_translation_title', $currentUiLang) ?? 'Luo kieliversio', ENT_QUOTES, 'UTF-8'); ?>
            </h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step active">1</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">2</span>
            </div>

            <form id="translationForm">
                <input type="hidden" name="source_id" value="<?php echo (int)$flash['id']; ?>">
                <input type="hidden" name="target_lang" id="translationTargetLang" value="">
                
                <div class="sf-field">
                    <label class="sf-label">
                        <?php echo htmlspecialchars(sf_term('translation_target_lang', $currentUiLang) ?? 'Kohdekieli', ENT_QUOTES, 'UTF-8'); ?>
                    </label>
                    <div class="sf-translation-lang-display" id="translationLangDisplay"></div>
                </div>

                <div class="sf-field">
                    <label for="translationTitleShort" class="sf-label">
                        <?php echo htmlspecialchars(sf_term('short_title_label', $currentUiLang) ?? 'Lyhyt kuvaus', ENT_QUOTES, 'UTF-8'); ?> *
                    </label>
                    <textarea 
                        name="title_short" 
                        id="translationTitleShort" 
                        class="sf-textarea" 
                        rows="2" 
                        maxlength="125"
                        required
                    ></textarea>
                    <div class="sf-char-count"><span id="titleCharCount">0</span>/125</div>
                </div>

                <div class="sf-field">
                    <label for="translationDescription" class="sf-label">
                        <?php echo htmlspecialchars(sf_term('description_label', $currentUiLang) ?? 'Kuvaus', ENT_QUOTES, 'UTF-8'); ?> *
                    </label>
                    <textarea 
                        name="description" 
                        id="translationDescription" 
                        class="sf-textarea" 
                        rows="5"
                        maxlength="900"
                        required
                    ></textarea>
                    <div class="sf-char-count"><span id="descCharCount">0</span>/900</div>
                </div>

                <?php if ($flash['type'] === 'green'): ?>
                    <div class="sf-field">
                        <label for="translationRootCauses" class="sf-label">
                            <?php echo htmlspecialchars(sf_term('root_cause_label', $currentUiLang) ?? 'Juurisyyt', ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                        <textarea name="root_causes" id="translationRootCauses" class="sf-textarea" rows="3"></textarea>
                    </div>

                    <div class="sf-field">
                        <label for="translationActions" class="sf-label">
                            <?php echo htmlspecialchars(sf_term('actions_label', $currentUiLang) ?? 'Toimenpiteet', ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                        <textarea name="actions" id="translationActions" class="sf-textarea" rows="3"></textarea>
                    </div>
                <?php endif; ?>
            </form>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalTranslation">
                    <?php echo htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8'); ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnToStep2">
                    <?php echo htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8'); ?> →
                </button>
            </div>
        </div>

        <!-- VAIHE 2: Esikatselu -->
        <div class="sf-translation-step hidden" id="translationStep2">
            <h2>
                <?php echo htmlspecialchars(sf_term('preview_and_save', $currentUiLang) ?? 'Esikatselu ja tallennus', ENT_QUOTES, 'UTF-8'); ?>
            </h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step active">2</span>
            </div>

            <div class="sf-translation-preview-wrapper">
                <div id="sfTranslationPreviewContainer">
                    <?php require __DIR__ .'/../partials/preview_modal.php'; ?>
                </div>
            </div>

            <div id="translationStatus" class="sf-translation-status"></div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="btnBackToStep1">
                    ← <?php echo htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8'); ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnSaveTranslation">
                    <?php echo htmlspecialchars(sf_term('btn_save_translation', $currentUiLang) ?? 'Tallenna kieliversio', ENT_QUOTES, 'UTF-8'); ?>
                </button>
            </div>
        </div>

    </div>
</div>

<!-- KIELIVERSIO VAHVISTUSMODAALI (kevyt) -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="modalTranslationConfirm" role="dialog" aria-modal="true" aria-labelledby="modalTranslationConfirmTitle">
    <div class="sf-modal-backdrop" onclick="sfCloseTranslationConfirm()"></div>
    <div class="sf-modal-content sf-modal-confirm">
        <div class="sf-modal-header">
            <h3 id="modalTranslationConfirmTitle">
                <span style="margin-right: 8px;">🌐</span>
                <?php echo htmlspecialchars(sf_term('modal_translation_confirm_title', $currentUiLang) ?? 'Luo kieliversio', ENT_QUOTES, 'UTF-8'); ?>
            </h3>
            <button class="sf-modal-close" onclick="sfCloseTranslationConfirm()">✕</button>
        </div>
        <div class="sf-modal-body">
            <p class="sf-confirm-question">
                <?php echo htmlspecialchars(sf_term('modal_translation_confirm_message', $currentUiLang) ?? 'Haluatko luoda kieliversion tälle SafetyFlashille?', ENT_QUOTES, 'UTF-8'); ?>
            </p>
            
            <div class="sf-confirm-card" id="translationConfirmCard">
                <!-- Target language row with flag -->
                <div class="sf-confirm-lang-row" id="translationConfirmLangRow">
                    <!-- Populated by JS: flag image + language name + code -->
                </div>
                
                <!-- Source flash info -->
                <div class="sf-confirm-source">
                    <span class="sf-confirm-source-label">
                        <?php echo htmlspecialchars(sf_term('confirm_source_flash_label', $currentUiLang), ENT_QUOTES, 'UTF-8'); ?>:
                    </span>
                    <span class="sf-confirm-source-title" id="translationConfirmSourceTitle">
                        <!-- Populated by JS from SF_FLASH_DATA -->
                    </span>
                </div>
                
                <!-- Meta row: site + type badge -->
                <div class="sf-confirm-meta">
                    <span class="sf-confirm-site" id="translationConfirmSite">
                        <!-- Populated by JS: 📍 Site name -->
                    </span>
                    <span class="sf-confirm-type-badge" id="translationConfirmType">
                        <!-- Populated by JS: colored dot + type name -->
                    </span>
                </div>
            </div>
        </div>
        <div class="sf-modal-footer">
            <button type="button" class="sf-btn sf-btn-secondary" onclick="sfCloseTranslationConfirm()">
                <?php echo htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnConfirmTranslation">
                <?php echo htmlspecialchars(sf_term('btn_create_translation', $currentUiLang) ?? 'Kyllä, luo kieliversio', ENT_QUOTES, 'UTF-8'); ?>
            </button>
        </div>
    </div>
</div>

<!-- VERSION MODAL -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="versionModal" role="dialog" aria-modal="true" aria-labelledby="versionModalTitle">
    <div class="sf-modal-backdrop" onclick="closeVersionModal()"></div>
    <div class="sf-modal-content sf-version-modal">
        <div class="sf-modal-header">
            <h3 id="versionModalTitle"><?= htmlspecialchars(sf_term('version_ensitiedote', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            <span id="versionModalDate" class="sf-version-modal-date"></span>
            <button class="sf-modal-close" onclick="closeVersionModal()">✕</button>
        </div>
        <div class="sf-modal-body">
            <img id="versionModalImage" src="" alt="SafetyFlash version" class="sf-version-image">
        </div>
        <div class="sf-modal-footer">
            <a id="versionDownloadBtn" href="" download class="sf-btn sf-version-download-btn">
                <img src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/download.svg" alt="" class="sf-version-download-icon">
                <span><?= htmlspecialchars(sf_term('version_download', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        </div>
    </div>
</div>

</div>

<?php if (in_array('display_targets', $actions ?? []) && file_exists(__DIR__ . '/../partials/modal_display_targets.php')): ?>
    <?php require __DIR__ . '/../partials/modal_display_targets.php'; ?>
<?php endif; ?>

<?php if ($canAccessSettings): ?>
<?php require __DIR__ . '/../partials/report_settings_modal.php'; ?>
<?php endif; ?>

<?php if (($flash['type'] ?? '') === 'green' && !$athenaExported): ?>
<?php require __DIR__ . '/../partials/athena_reminder_modal.php'; ?>
<?php endif; ?>


<?php /* Footer action bar siirretty ylös (näkyy heti sivun latautuessa). */ ?>

<!-- html2canvas tarvitaan kuvan generointiin -->
<script src="<?= sf_asset_url('assets/js/vendor/html2canvas.min.js', $base) ?>"></script>

<!-- Quill WYSIWYG editor (vendored locally) -->
<script src="<?= sf_asset_url('assets/js/vendor/quill.min.js', $base) ?>"></script>
<script src="<?= sf_asset_url('assets/js/vendor/purify.min.js', $base) ?>"></script>

<!-- Safetyflash CSS & JS -->
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/display-ttl.css', $base) ?>">
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/preview.css', $base) ?>">
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/copy-to-clipboard.css', $base) ?>">
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/image_captions.css', $base) ?>">
<!-- view.js and copy-to-clipboard.js are loaded in index.php with versioning, removed duplicates here -->
<script src="<?= sf_asset_url('assets/js/translation.js', $base) ?>"></script>
<script src="<?= sf_asset_url('assets/js/display-playlist.js', $base) ?>"></script>
<script src="<?= sf_asset_url('assets/js/comms-modal.js', $base) ?>"></script>
<?php if (in_array('display_targets', $actions ?? [])): ?>
<script src="<?= sf_asset_url('assets/js/display-targets-modal.js', $base) ?>"></script>
<?php endif; ?>

<?php if (($flash['type'] ?? '') === 'green'): ?>
<script>
<?php
// Determine whether to show the Athena reminder modal
$showAthenaReminder = !$athenaExported
    && ($flash['state'] ?? '') === 'published'
    && isset($_GET['published']) && (int)$_GET['published'] === 1;
$currentUserName = $currentUser
    ? trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))
    : '';
?>
window.SF_ATHENA_CFG = {
    flashId:     <?= json_encode($id) ?>,
    logFlashId:  <?= json_encode($logFlashId) ?>,
    baseUrl:     <?= json_encode($base) ?>,
    csrfToken:   <?= json_encode(sf_csrf_token()) ?>,
    reportUrl:   <?= json_encode("{$base}/app/api/generate_report.php?id={$id}") ?>,
    showReminder: <?= json_encode($showAthenaReminder) ?>,
    i18n: {
        marked_done:          <?= json_encode(sf_term('btn_already_exported_athena', $currentUiLang)) ?>,
        pdf_downloaded:       <?= json_encode(sf_term('btn_download_and_mark_athena', $currentUiLang)) ?>,
        badge_exported_by:    <?= json_encode(sf_term('badge_athena_exported_by', $currentUiLang)) ?>,
        badge_athena_exported:<?= json_encode(sf_term('badge_athena_exported', $currentUiLang)) ?>,
        current_user:         <?= json_encode($currentUserName) ?>
    }
};
</script>
<script src="<?= sf_asset_url('assets/js/athena_reminder.js', $base) ?>"></script>
<?php endif; ?>

<script>
window.SF_LIST_I18N = window.SF_LIST_I18N || {};
window.SF_LIST_I18N.editingIndicator = <?= json_encode(sf_term('editing_indicator', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
window.SF_BASE_URL = window.SF_BASE_URL || <?= json_encode($base) ?>;
</script>
<script src="<?= sf_asset_url('assets/js/editing-indicator.js', $base) ?>"></script>

<!-- Sivukohtaiset datat -->
<script>
window.SF_LOG_SHOW_MORE   = <?php echo json_encode(sf_term('log_show_more', $currentUiLang)); ?>;
window.SF_LOG_SHOW_LESS   = <?php echo json_encode(sf_term('log_show_less', $currentUiLang)); ?>;
window.SF_BASE_URL        = <?php echo json_encode($base); ?>;
window.SF_CSRF_TOKEN      = <?php echo json_encode(sf_csrf_token()); ?>;
window.SF_FLASH_ID        = <?php echo json_encode($id); ?>;
window.SF_CAN_EDIT        = <?php echo json_encode($canEdit); ?>;
window.SF_EDIT_URL        = <?php echo json_encode($editUrl); ?>;

window.SF_ANALYTICS_CONTEXT = {
    page: 'view',
    target_type: 'flash',
    target_id: <?php echo json_encode((int)$id); ?>,
    flash_id: <?php echo json_encode((int)$id); ?>,
    flash_group_id: <?php echo json_encode((int)$logFlashId); ?>,
    flash_type: <?php echo json_encode((string)($flash['type'] ?? '')); ?>,
    flash_state: <?php echo json_encode((string)($flash['state'] ?? '')); ?>,
    flash_lang: <?php echo json_encode((string)($flash['lang'] ?? 'fi')); ?>,
    worksite_id: <?php echo json_encode(isset($flash['worksite_id']) ? (int)$flash['worksite_id'] : null); ?>,
    site: <?php echo json_encode((string)($flash['site'] ?? ''), JSON_UNESCAPED_UNICODE); ?>
};
window.SF_TERMS = {
    comment_delete_confirm: <?php echo json_encode(sf_term('comment_delete_confirm', $currentUiLang)); ?>,
    comment_deleted: <?php echo json_encode(sf_term('comment_deleted', $currentUiLang)); ?>,
    comment_updated: <?php echo json_encode(sf_term('comment_updated', $currentUiLang)); ?>,
    comment_delete_error: <?php echo json_encode(sf_term('comment_delete_error', $currentUiLang)); ?>,
    comment_update_error: <?php echo json_encode(sf_term('comment_update_error', $currentUiLang)); ?>,
    comment_add_error: <?php echo json_encode(sf_term('comment_add_error', $currentUiLang)); ?>,
    comment_error_empty: <?php echo json_encode(sf_term('comment_error_empty', $currentUiLang)); ?>,
    comment_added: <?php echo json_encode(sf_term('comment_added', $currentUiLang)); ?>,
    comment_reply: <?php echo json_encode(sf_term('comment_reply', $currentUiLang)); ?>,
    comment_edit: <?php echo json_encode(sf_term('comment_edit', $currentUiLang)); ?>,
    comment_like: <?php echo json_encode(sf_term('comment_like', $currentUiLang)); ?>,
	comment_like_error: <?php echo json_encode(sf_term('comment_like_error', $currentUiLang)); ?>,
    modal_comment_edit_title: <?php echo json_encode(sf_term('modal_comment_edit_title', $currentUiLang)); ?>,
    modal_comment_reply_title: <?php echo json_encode(sf_term('modal_comment_reply_title', $currentUiLang)); ?>,
    modal_comment_title: <?php echo json_encode(sf_term('modal_comment_title', $currentUiLang)); ?>,
    comments_empty: <?php echo json_encode(sf_term('comments_empty', $currentUiLang)); ?>,
    time_just_now: <?php echo json_encode(sf_term('time_just_now', $currentUiLang)); ?>,
    // Communications modal terms
    comms_summary_none: <?php echo json_encode(sf_term('comms_summary_none', $currentUiLang) ?? 'Ei valintoja'); ?>,
    comms_screens_all: <?php echo json_encode(sf_term('comms_screens_all', $currentUiLang) ?? 'Kaikki näytöt'); ?>,
    comms_all_countries: <?php echo json_encode(sf_term('comms_all_countries', $currentUiLang) ?? 'Kaikki maat'); ?>,
    comms_summary_worksites: <?php echo json_encode(sf_term('comms_summary_worksites', $currentUiLang) ?? 'työmaata'); ?>,
    comms_screens_selected: <?php echo json_encode(sf_term('comms_screens_selected', $currentUiLang) ?? 'Valitse työmaat'); ?>,
    comms_summary_no_distribution: <?php echo json_encode(sf_term('comms_summary_no_distribution', $currentUiLang) ?? 'Ei jakelulistoja'); ?>,
    comms_error_no_languages: <?php echo json_encode(sf_term('comms_error_no_languages', $currentUiLang) ?? 'Valitse vähintään yksi kieliversio'); ?>,
    comms_wider_distribution_yes: <?php echo json_encode(sf_term('comms_wider_distribution_yes', $currentUiLang) ?? 'Kyllä, lähetä laajempaan jakeluun'); ?>,
    comms_wider_distribution_no: <?php echo json_encode(sf_term('comms_wider_distribution_no', $currentUiLang) ?? 'Ei, vain valitut näytöt'); ?>,
    comms_summary_yes: <?php echo json_encode(sf_term('comms_summary_yes', $currentUiLang) ?? 'Kyllä'); ?>,
    comms_summary_no: <?php echo json_encode(sf_term('comms_summary_no', $currentUiLang) ?? 'Ei'); ?>,
    country_finland: <?php echo json_encode(sf_term('country_finland', $currentUiLang) ?? 'Suomi'); ?>,
    country_italy: <?php echo json_encode(sf_term('country_italy', $currentUiLang) ?? 'Italia'); ?>,
    country_greece: <?php echo json_encode(sf_term('country_greece', $currentUiLang) ?? 'Kreikka'); ?>,
    status_sending: <?php echo json_encode(sf_term('status_sending', $currentUiLang) ?? 'Lähetetään...'); ?>,
    error_sending: <?php echo json_encode(sf_term('error_sending', $currentUiLang) ?? 'Virhe lähetyksessä'); ?>,
    error_network: <?php echo json_encode(sf_term('error_network', $currentUiLang) ?? 'Verkkovirhe'); ?>,
    btn_send_comms: <?php echo json_encode(sf_term('btn_send_comms', $currentUiLang) ?? 'Lähetä viestintään'); ?>,
    log_delete_error: <?php echo json_encode(sf_term('log_delete_error', $currentUiLang)); ?>,
    log_deleted: <?php echo json_encode(sf_term('log_deleted', $currentUiLang)); ?>,
    // Extra images gallery terms
    extra_img_delete_confirm: <?php echo json_encode(sf_term('extra_img_delete_confirm', $currentUiLang)); ?>,
    delete_success: <?php echo json_encode(sf_term('delete_success', $currentUiLang)); ?>,
    delete_error: <?php echo json_encode(sf_term('delete_error', $currentUiLang)); ?>,
    unknown_error: <?php echo json_encode(sf_term('unknown_error', $currentUiLang)); ?>,
    select_image_files: <?php echo json_encode(sf_term('select_image_files', $currentUiLang)); ?>,
    images_loading_error: <?php echo json_encode(sf_term('images_loading_error', $currentUiLang)); ?>,
    images_uploading: <?php echo json_encode(sf_term('images_uploading', $currentUiLang)); ?>,
    images_upload_partial: <?php echo json_encode(sf_term('images_upload_partial', $currentUiLang)); ?>,
    upload_success: <?php echo json_encode(sf_term('upload_success', $currentUiLang)); ?>,
    upload_error: <?php echo json_encode(sf_term('upload_error', $currentUiLang)); ?>,
    upload_retrying: <?php echo json_encode(sf_term('upload_retrying', $currentUiLang)); ?>,
    upload_modal_title: <?php echo json_encode(sf_term('upload_modal_title', $currentUiLang)); ?>,
    upload_drag_text: <?php echo json_encode(sf_term('upload_drag_text', $currentUiLang)); ?>,
    upload_drop_here: <?php echo json_encode(sf_term('upload_drop_here', $currentUiLang)); ?>,
    upload_camera_btn: <?php echo json_encode(sf_term('upload_camera_btn', $currentUiLang)); ?>,
    extra_img_invalid_type: <?php echo json_encode(sf_term('extra_img_invalid_type', $currentUiLang)); ?>,
    extra_img_too_large: <?php echo json_encode(sf_term('extra_img_too_large', $currentUiLang)); ?>,
    extra_img_processing: <?php echo json_encode(sf_term('extra_img_processing', $currentUiLang)); ?>,
    extra_img_remove: <?php echo json_encode(sf_term('extra_img_remove', $currentUiLang)); ?>,
    btn_cancel: <?php echo json_encode(sf_term('btn_cancel', $currentUiLang)); ?>,
    btn_delete: <?php echo json_encode(sf_term('btn_delete', $currentUiLang)); ?>,
    // Translation confirmation modal terms
    type_red: <?php echo json_encode(sf_term('type_red', $currentUiLang)); ?>,
    type_yellow: <?php echo json_encode(sf_term('type_yellow', $currentUiLang)); ?>,
    type_green: <?php echo json_encode(sf_term('type_green', $currentUiLang)); ?>,
    confirm_creating_translation: <?php echo json_encode(sf_term('confirm_creating_translation', $currentUiLang)); ?>,
    // Publish summary terms
    publish_yes: <?php echo json_encode(sf_term('publish_yes', $currentUiLang) ?? '✅ Kyllä'); ?>,
    // Worksite notification terms
    publish_worksite_recipients_count: <?php echo json_encode(sf_term('publish_worksite_recipients_count', $currentUiLang) ?? 'Ilmoitus lähetetään %d henkilölle'); ?>,
    publish_worksite_recipients_none:  <?php echo json_encode(sf_term('publish_worksite_recipients_none', $currentUiLang) ?? 'Ei vastaanottajia'); ?>,
    publish_worksite_recipients_loading: <?php echo json_encode(sf_term('publish_worksite_recipients_loading', $currentUiLang) ?? 'Lasketaan...'); ?>
};
window.SF_FLASH_DATA      = <?php echo json_encode($flashDataForJs); ?>;
window.SF_SUPPORTED_LANGS = <?php echo json_encode($supportedLangs); ?>;
window.SF_CSRF_TOKEN      = <?php echo json_encode(sf_csrf_token()); ?>;
window.SF_ARCHIVE_BTN_TEXT = <?php echo json_encode(sf_term('btn_archive', $currentUiLang)); ?>;
window.SF_ARCHIVING_TEXT  = <?php echo json_encode(sf_term('archiving_in_progress', $currentUiLang) ?: 'Archiving...'); ?>;

// Käännökset translation.js:lle - kaikki tuetut kielet
window.SF_TRANSLATIONS = {
    metaLabels: {
        fi: { site: <?php echo json_encode(sf_term('preview_meta_site', 'fi')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'fi')); ?> },
        sv: { site: <?php echo json_encode(sf_term('preview_meta_site', 'sv')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'sv')); ?> },
        en: { site: <?php echo json_encode(sf_term('preview_meta_site', 'en')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'en')); ?> },
        it: { site: <?php echo json_encode(sf_term('preview_meta_site', 'it')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'it')); ?> },
        el: { site: <?php echo json_encode(sf_term('preview_meta_site', 'el')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'el')); ?> }
    },
    messages: {
        validationFillRequired: <?php echo json_encode(sf_term('validation_fill_required', $currentUiLang)); ?>,
        generatingImage: <?php echo json_encode(sf_term('generating_image', $currentUiLang)); ?>,
        saving: <?php echo json_encode(sf_term('status_saving', $currentUiLang)); ?>,
        translationSaved: <?php echo json_encode(sf_term('translation_saved', $currentUiLang)); ?>,
        errorPrefix: <?php echo json_encode(sf_term('error_prefix', $currentUiLang)); ?>,
        saveTranslationButton: <?php echo json_encode(sf_term('save_translation_button', $currentUiLang)); ?>
    }
};

// Hide loading spinner and fade in preview image
document.addEventListener('DOMContentLoaded', function() {
    const previewSpinner = document.getElementById('previewSpinner');
    const previewImages = document.querySelectorAll('.preview-box .preview-image');

    if (previewSpinner) {
        if (previewImages.length > 0) {
            // Function to hide spinner and show image with fade-in
            const showImageFn = function(img) {
                previewSpinner.classList.add('loaded');
                img.classList.add('loaded');
            };

            previewImages.forEach(function(img) {
                // If image is already loaded (from cache)
                if (img.complete && img.naturalHeight !== 0) {
                    showImageFn(img);
                } else {
                    // Wait for image to load
                    img.addEventListener('load', function() {
                        showImageFn(img);
                    });
                    // Handle error - still hide spinner
                    img.addEventListener('error', function() {
                        previewSpinner.classList.add('loaded');
                    });
                }
            });

            // Fallback: show everything after 3 seconds regardless
            setTimeout(function() {
                previewSpinner.classList.add('loaded');
                previewImages.forEach(function(img) {
                    img.classList.add('loaded');
                });
            }, 3000);
        } else {
            // No preview images in DOM (e.g. skeleton/pending state) — hide spinner immediately
            previewSpinner.classList.add('loaded');
        }
    }

    // ===== COPY TO CLIPBOARD BUTTONS =====
    if (window.SafetyFlashCopy) {
        // Load translations for copy buttons
        window.SF_I18N = window.SF_I18N || {};
        window.SF_I18N.copy_image = <?php echo json_encode(sf_term('copy_image', $currentUiLang)); ?>;
        window.SF_I18N.report_generating = <?php echo json_encode(sf_term('report_generating', $currentUiLang)); ?>;
window.SF_I18N.report_success = <?php echo json_encode(sf_term('report_success', $currentUiLang)); ?>;
window.SF_I18N.report_error = <?php echo json_encode(sf_term('report_error', $currentUiLang)); ?>;
window.SF_I18N.report_button_loading = <?php echo json_encode(sf_term('report_button_loading', $currentUiLang)); ?>;
window.SF_I18N.report_button_done = <?php echo json_encode(sf_term('report_button_done', $currentUiLang)); ?>;
window.SF_I18N.report_button_error = <?php echo json_encode(sf_term('report_button_error', $currentUiLang)); ?>;
        window.SF_I18N.copying_image = <?php echo json_encode(sf_term('copying_image', $currentUiLang)); ?>;
        window.SF_I18N.image_copied = <?php echo json_encode(sf_term('image_copied', $currentUiLang)); ?>;
        window.SF_I18N.copy_failed = <?php echo json_encode(sf_term('copy_failed', $currentUiLang)); ?>;
        window.SF_I18N.preview_error = <?php echo json_encode(sf_term('preview_generation_error', $currentUiLang)); ?>;
        window.SF_I18N.refresh_page = <?php echo json_encode(sf_term('preview_refresh_page', $currentUiLang)); ?>;

        // Add copy button for card 1 (all flash types)
        const viewPreview1 = document.getElementById('viewPreview1');
        const viewPreviewImage = document.getElementById('viewPreviewImage');
        const previewBox = document.querySelector('.preview-box');
        
        if (viewPreview1) {
            // Tutkintatiedote with tabs - add button to card container
            window.SafetyFlashCopy.addCopyButton(viewPreview1, {
    label: window.SF_I18N.copy_image,
    copyingLabel: window.SF_I18N.copying_image,
    successMessage: window.SF_I18N.image_copied,
    errorMessage: window.SF_I18N.copy_failed,
    position: 'top-right',
    analyticsEvent: 'view_image_copy',
    analyticsMetadata: {
        source: 'view_preview',
        preview_card: 'primary'
    }
});
        } else if (viewPreviewImage && previewBox) {
            // Normal flash (red/yellow) - add button to preview-box
window.SafetyFlashCopy.addCopyButton(previewBox, {
    label: window.SF_I18N.copy_image,
    copyingLabel: window.SF_I18N.copying_image,
    successMessage: window.SF_I18N.image_copied,
    errorMessage: window.SF_I18N.copy_failed,
    position: 'top-right',
    analyticsEvent: 'view_image_copy',
    analyticsMetadata: {
        source: 'view_preview',
        preview_card: 'single'
    }
});
        }

        // Add copy button for card 2 (tutkintatiedote only, if exists)
        const viewPreview2 = document.getElementById('viewPreview2');
        if (viewPreview2 && viewPreview2.querySelector('img')) {
            window.SafetyFlashCopy.addCopyButton(viewPreview2, {
    label: window.SF_I18N.copy_image,
    copyingLabel: window.SF_I18N.copying_image,
    successMessage: window.SF_I18N.image_copied,
    errorMessage: window.SF_I18N.copy_failed,
    position: 'top-right',
    analyticsEvent: 'view_image_copy',
    analyticsMetadata: {
        source: 'view_preview',
        preview_card: 'secondary'
    }
});
        }
    }
});
</script>
<!-- Preview Polling Module -->
<script src="<?= sf_asset_url('assets/js/preview-polling.js', $base) ?>"></script>
<script>
// Initialize polling for view page
document.addEventListener('DOMContentLoaded', function() {
    const previewBox = document.querySelector('.preview-box[data-preview-status]');
    if (!previewBox) return;
    
    const flashId = previewBox.dataset.flashId;
    const previewStatus = previewBox.dataset.previewStatus;
    const id = parseInt(flashId);
    
    if (!flashId || isNaN(id) || id <= 0) return;
    
    if (previewStatus === 'pending' || previewStatus === 'processing') {
        const progressBar = document.querySelector('.sf-preview-progress-bar');
        const progressText = document.querySelector('.sf-preview-progress-text');
        const pendingMessage = document.querySelector('.sf-preview-pending-message');
        const previewImage = document.querySelector('.preview-image');
        
        window.SFPreviewPolling.start(id, {
            onProgress: (id, progress, status) => {
                if (progressBar) {
                    progressBar.style.width = progress + '%';
                }
                if (progressText) {
                    progressText.textContent = progress + '%';
                }
            },
            onComplete: (id, previewUrl, previewUrl2) => {
                // Replace skeleton with actual image with fade-in
                const skeletonPlaceholder = document.querySelector('.skeleton-preview-placeholder');
                if (skeletonPlaceholder && previewUrl) {
                    const img = document.createElement('img');
                    img.src = previewUrl;
                    img.alt = 'Preview';
                    img.className = 'preview-image';
                    img.loading = 'eager';
                    img.style.opacity = '0';
                    
                    img.onload = () => {
                        skeletonPlaceholder.style.opacity = '0';
                        setTimeout(() => {
                            skeletonPlaceholder.parentNode.replaceChild(img, skeletonPlaceholder);
                            img.style.transition = 'opacity 0.5s ease';
                            img.style.opacity = '1';
                        }, 300);
                    };
                }
                
                // Update status attribute
                previewBox.dataset.previewStatus = 'completed';
            },
            onFailed: (id) => {
                if (progressText) {
                    progressText.textContent = window.SF_I18N?.preview_error || 'Error';
                }
                if (pendingMessage) {
                    pendingMessage.classList.add('sf-generating-failed');
                }
            },
            onTimeout: (id) => {
                if (progressText) {
                    progressText.textContent = window.SF_I18N?.refresh_page || 'Refresh page';
                }
            }
        });
    }
});

// ========== VERSION MODAL FUNCTIONS ==========
function openVersionModal(imagePath, versionType, publishedAt) {
    const modal = document.getElementById('versionModal');
    const title = document.getElementById('versionModalTitle');
    const date = document.getElementById('versionModalDate');
    const image = document.getElementById('versionModalImage');
    const downloadBtn = document.getElementById('versionDownloadBtn');
    
    title.textContent = versionType;
    
    // Format date
    const dateObj = new Date(publishedAt);
    const langCode = '<?= $currentUiLang ?>';
    date.textContent = '<?= htmlspecialchars(sf_term('version_published', $currentUiLang), ENT_QUOTES, 'UTF-8') ?> ' + 
                       dateObj.toLocaleString(langCode);
    
    image.src = imagePath;
    downloadBtn.href = imagePath;
    
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeVersionModal() {
    const modal = document.getElementById('versionModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

// ESC key closes version modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const versionModal = document.getElementById('versionModal');
        if (versionModal && !versionModal.classList.contains('hidden')) {
            closeVersionModal();
        }
    }
});

// ===== REVIEWER FUNCTIONALITY =====
(function() {
    'use strict';
    
    const flashId = <?= (int)$id ?>;
    const baseUrl = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>';
    const uiLang = '<?= htmlspecialchars($currentUiLang, ENT_QUOTES, 'UTF-8') ?>';
    
    // Translation terms
    const terms = {
        reviewerAdded: <?= json_encode(sf_term('reviewer_added', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        reviewerReplaced: <?= json_encode(sf_term('reviewer_replaced', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        reviewerRemoved: <?= json_encode(sf_term('reviewer_removed', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        reviewerError: <?= json_encode(sf_term('reviewer_error', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        reviewerRemoveConfirm: <?= json_encode(sf_term('reviewer_remove_confirm', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        errorPrefix: <?= json_encode(sf_term('error_prefix', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        errorNetwork: <?= json_encode(sf_term('error_network', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>
    };
    
    // XSS prevention helper
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Show notification
    function showNotification(message, type = 'success') {
        // Use existing toast notification system
        if (typeof window.sfToast === 'function') {
            window.sfToast(type, message);
        } else if (window.sfShowNotification) {
            window.sfShowNotification(message, type);
        } else {
            // Luo yksinkertainen toast-ilmoitus fallbackina
            const toast = document.createElement('div');
            toast.className = 'sf-toast sf-toast-' + type;
            toast.textContent = message;
            toast.style.cssText = 'position:fixed;top:80px;right:20px;z-index:100001;padding:12px 20px;border-radius:10px;color:#fff;font-size:14px;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.15);opacity:0;transform:translateX(40px);transition:all 0.3s ease;' +
                (type === 'error' ? 'background:#ef4444;' : type === 'warning' ? 'background:#f59e0b;' : 'background:#10b981;');
            document.body.appendChild(toast);
            requestAnimationFrame(() => { toast.style.opacity = '1'; toast.style.transform = 'translateX(0)'; });
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(40px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
    }
    
    // Open modal helper
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            document.body.classList.add('sf-modal-open');
        }
    }
    
    // Close modal helper
    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            const openModals = document.querySelectorAll('.sf-modal:not(.hidden)');
            if (openModals.length === 0) {
                document.body.classList.remove('sf-modal-open');
            }
        }
    }

    const footerLanguageReview = document.getElementById('footerLanguageReview');

    if (footerLanguageReview && !footerLanguageReview._sf_attached) {
        footerLanguageReview.addEventListener('click', function() {
            openModal('modalLanguageReview');
        });

        footerLanguageReview._sf_attached = true;
    }

    // Fetch and refresh reviewer list
    function refreshReviewerList() {
        fetch(baseUrl + '/app/api/get_flash_reviewers.php?flash_id=' + flashId)
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    updateReviewerDisplay(data.reviewers);
                }
            })
            .catch(err => {
                console.error('Error refreshing reviewer list:', err);
            });
    }
    
    // Update reviewer display
    function updateReviewerDisplay(reviewers) {
        const reviewerList = document.getElementById('reviewerList');
        const reviewerEmpty = document.getElementById('reviewerEmpty');
        
        if (reviewers && reviewers.length > 0) {
            if (reviewerEmpty) reviewerEmpty.classList.add('hidden');
            if (reviewerList) {
                reviewerList.innerHTML = '';
                reviewers.forEach(reviewer => {
                    const card = createReviewerCard(reviewer);
                    reviewerList.appendChild(card);
                });
                reviewerList.classList.remove('hidden');
            }
        } else {
            if (reviewerList) reviewerList.classList.add('hidden');
            if (reviewerEmpty) reviewerEmpty.classList.remove('hidden');
        }
    }
    
    // Create reviewer card element
    function createReviewerCard(reviewer) {
        const card = document.createElement('div');
        card.className = 'reviewer-card';
        card.dataset.userId = reviewer.user_id;
        
        const name = escapeHtml((reviewer.first_name || '') + ' ' + (reviewer.last_name || '')).trim();
        const email = escapeHtml(reviewer.email || '');
        const assignedAt = escapeHtml(reviewer.assigned_at_formatted || '');
        
        card.innerHTML = `
            <div class="reviewer-info">
                <div class="reviewer-name">${name || 'ID ' + reviewer.user_id}</div>
                ${email ? `<div class="reviewer-email">${email}</div>` : ''}
                ${assignedAt ? `<div class="reviewer-assigned"><?= htmlspecialchars(sf_term('reviewer_assigned_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>: ${assignedAt}</div>` : ''}
            </div>
            <?php if ($canManageReviewers): ?>
            <button type="button" class="reviewer-remove-btn" data-user-id="${reviewer.user_id}" data-flash-id="${flashId}" title="<?= htmlspecialchars(sf_term('remove_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <svg viewBox="0 0 24 24" focusable="false">
                    <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
            <?php endif; ?>
        `;
        
        return card;
    }
    
    // Search users for reviewers
    let searchTimeout = null;
    function setupUserSearch(inputId, dropdownId, selectedIdField, displayField) {
        const searchInput = document.getElementById(inputId);
        const dropdown = document.getElementById(dropdownId);
        const selectedId = document.getElementById(selectedIdField);
        const display = document.getElementById(displayField);
        
        if (!searchInput || !dropdown) return;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length < 2) {
                dropdown.classList.add('hidden');
                dropdown.innerHTML = '';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                fetch(baseUrl + '/app/api/search_reviewers.php?query=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        if (data.ok && data.users) {
                            displaySearchResults(data.users, dropdown, selectedId, display, searchInput);
                        }
                    })
                    .catch(err => {
                        console.error('Search error:', err);
                    });
            }, 300);
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });
    }
    
    // Display search results
    function displaySearchResults(users, dropdown, selectedIdField, displayField, searchInput) {
        if (users.length === 0) {
            dropdown.innerHTML = '<div class="sf-dropdown-item sf-dropdown-empty"><?= htmlspecialchars(sf_term('reviewer_no_users_found', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></div>';
            dropdown.classList.remove('hidden');
            return;
        }
        
        dropdown.innerHTML = users.map(user => {
            const name = escapeHtml(user.name || (user.first_name + ' ' + user.last_name));
            const email = escapeHtml(user.email || '');
            const roleName = escapeHtml(user.role_name || '');
            
            return `<div class="sf-dropdown-item" data-user-id="${user.id}" data-name="${name}" data-email="${email}">
                <div class="user-info">
                    <div class="user-name">${name}</div>
                    <div class="user-details">${email}${roleName ? ' - ' + roleName : ''}</div>
                </div>
            </div>`;
        }).join('');
        
        dropdown.classList.remove('hidden');
        
        // Handle selection
        dropdown.querySelectorAll('.sf-dropdown-item').forEach(item => {
            if (!item.classList.contains('sf-dropdown-empty')) {
                item.addEventListener('click', function() {
                    const userId = this.dataset.userId;
                    const userName = this.dataset.name;
                    const userEmail = this.dataset.email;
                    
                    selectedIdField.value = userId;
searchInput.value = '';
dropdown.classList.add('hidden');
dropdown.innerHTML = '';
                    
                    // Show selected user
                    displayField.innerHTML = `
                        <div class="selected-user-chip">
                            <span>${escapeHtml(userName)}</span>
                            <button type="button" class="remove-selection">×</button>
                        </div>
                    `;
                    displayField.classList.remove('hidden');
                    
                    // Handle removal
                    displayField.querySelector('.remove-selection').addEventListener('click', function() {
                        selectedIdField.value = '';
                        displayField.classList.add('hidden');
                        displayField.innerHTML = '';
                    });
                });
            }
        });
    }
    
    // Add reviewer button handlers
    document.querySelectorAll('.reviewer-action-btn[data-action="add"]').forEach(btn => {
        btn.addEventListener('click', function() {
            const selectedId = document.getElementById('selectedReviewerId');
            const display = document.getElementById('selectedReviewerDisplay');
            if (selectedId) selectedId.value = '';
            if (display) {
                display.classList.add('hidden');
                display.innerHTML = '';
            }
            openModal('modalAddReviewer');
        });
    });
    
    // Replace reviewer button handlers
    document.querySelectorAll('.reviewer-action-btn[data-action="replace"]').forEach(btn => {
        btn.addEventListener('click', function() {
            // Fetch current reviewers and display them
            fetch(baseUrl + '/app/api/get_flash_reviewers.php?flash_id=' + flashId)
                .then(response => response.json())
                .then(data => {
                    if (data.ok) {
                        const display = document.getElementById('currentReviewersDisplay');
                        if (display && data.reviewers && data.reviewers.length > 0) {
                            display.innerHTML = data.reviewers.map(r => {
                                const name = escapeHtml((r.first_name || '') + ' ' + (r.last_name || '')).trim();
                                return `<div class="current-reviewer-chip">${name || 'ID ' + r.user_id}</div>`;
                            }).join('');
                        } else if (display) {
                            display.innerHTML = '<div class="no-reviewers"><?= htmlspecialchars(sf_term('reviewer_no_reviewers', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></div>';
                        }
                    }
                });
            
            const selectedId = document.getElementById('selectedReviewerIdReplace');
            const display = document.getElementById('selectedReviewerDisplayReplace');
            if (selectedId) selectedId.value = '';
            if (display) {
                display.classList.add('hidden');
                display.innerHTML = '';
            }
            openModal('modalReplaceReviewer');
        });
    });
    
    // Remove reviewer button handlers (event delegation)
    document.addEventListener('click', function(e) {
        const removeBtn = e.target.closest('.reviewer-remove-btn');
        if (removeBtn) {
            const userId = removeBtn.dataset.userId;
            const flashId = removeBtn.dataset.flashId;
            
            if (confirm(terms.reviewerRemoveConfirm)) {
                removeReviewer(flashId, userId);
            }
        }
    });
    
    // Add reviewer action
    const btnAddReviewer = document.getElementById('btnAddReviewer');
    if (btnAddReviewer) {
        btnAddReviewer.addEventListener('click', function() {
            const userId = document.getElementById('selectedReviewerId').value;
            
            if (!userId) {
                showNotification('<?= htmlspecialchars(sf_term('reviewer_select_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('user_id', userId);
            formData.append('csrf_token', window.SF_CSRF_TOKEN);
            
            fetch(baseUrl + '/app/api/add_reviewer.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    showNotification(terms.reviewerAdded, 'success');
                    closeModal('modalAddReviewer');
                    refreshReviewerList();
                } else {
                    showNotification(terms.errorPrefix + ': ' + (data.error || terms.reviewerError), 'error');
                }
            })
            .catch(err => {
                console.error('Add reviewer error:', err);
                showNotification(terms.errorNetwork, 'error');
            });
        });
    }
    
    // Replace reviewer action
    const btnReplaceReviewer = document.getElementById('btnReplaceReviewer');
    if (btnReplaceReviewer) {
        btnReplaceReviewer.addEventListener('click', function() {
            const userId = document.getElementById('selectedReviewerIdReplace').value;
            
            if (!userId) {
                showNotification('<?= htmlspecialchars(sf_term('reviewer_select_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('user_id', userId);
            formData.append('csrf_token', window.SF_CSRF_TOKEN);
            
            fetch(baseUrl + '/app/api/replace_reviewer.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    showNotification(terms.reviewerReplaced, 'success');
                    closeModal('modalReplaceReviewer');
                    refreshReviewerList();
                } else {
                    showNotification(terms.errorPrefix + ': ' + (data.error || terms.reviewerError), 'error');
                }
            })
            .catch(err => {
                console.error('Replace reviewer error:', err);
                showNotification(terms.errorNetwork, 'error');
            });
        });
    }
    
    // Remove reviewer function
    function removeReviewer(flashId, userId) {
        const formData = new FormData();
        formData.append('flash_id', flashId);
        formData.append('user_id', userId);
        formData.append('csrf_token', window.SF_CSRF_TOKEN);
        
        fetch(baseUrl + '/app/api/remove_reviewer.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.ok) {
                showNotification(terms.reviewerRemoved, 'success');
                refreshReviewerList();
            } else {
                showNotification(terms.errorPrefix + ': ' + (data.error || terms.reviewerError), 'error');
            }
        })
        .catch(err => {
            console.error('Remove reviewer error:', err);
            showNotification(terms.errorNetwork, 'error');
        });
    }
    
    // Setup search functionality for both modals
    setupUserSearch('reviewerSearch', 'reviewerSearchDropdown', 'selectedReviewerId', 'selectedReviewerDisplay');
    setupUserSearch('reviewerSearchReplace', 'reviewerSearchReplaceDropdown', 'selectedReviewerIdReplace', 'selectedReviewerDisplayReplace');

    let languageReviewSearchTimeout = null;

    document.querySelectorAll('.language-review-search').forEach(input => {
        input.addEventListener('input', function() {
            clearTimeout(languageReviewSearchTimeout);

            const query = this.value.trim();
            const lang = this.dataset.lang;
            const dropdown = document.getElementById('languageReviewerDropdown_' + lang);

            if (!dropdown) {
                return;
            }

            if (query.length < 2) {
                dropdown.classList.add('hidden');
                dropdown.innerHTML = '';
                return;
            }

            languageReviewSearchTimeout = setTimeout(() => {
                fetch(baseUrl + '/app/api/search_language_reviewers.php?query=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        if (!data.ok || !Array.isArray(data.users)) {
                            dropdown.classList.add('hidden');
                            dropdown.innerHTML = '';
                            return;
                        }

                        if (data.users.length === 0) {
                            dropdown.innerHTML = '<div class="sf-dropdown-item sf-dropdown-empty"><?= htmlspecialchars(sf_term('reviewer_no_users_found', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></div>';
                            dropdown.classList.remove('hidden');
                            return;
                        }

                        dropdown.innerHTML = data.users.map(user => {
                            const name = escapeHtml(user.name || '');
                            const email = escapeHtml(user.email || '');
                            const roleName = escapeHtml(user.role_name || '');

                            return `<div class="sf-dropdown-item" data-user-id="${user.id}" data-name="${name}" data-email="${email}">
                                <div class="user-info">
                                    <div class="user-name">${name}</div>
                                    <div class="user-details">${email}${roleName ? ' - ' + roleName : ''}</div>
                                </div>
                            </div>`;
                        }).join('');

                        dropdown.classList.remove('hidden');

                        dropdown.querySelectorAll('.sf-dropdown-item').forEach(item => {
                            item.addEventListener('click', function() {
                                const userId = this.dataset.userId || '';
                                const name = this.dataset.name || '';
                                const email = this.dataset.email || '';

                                const hidden = document.getElementById('languageReviewerIds_' + lang);
                                const selected = document.getElementById('languageReviewerSelected_' + lang);

                                let selectedUsers = [];

                                if (hidden) {
                                    try {
                                        selectedUsers = JSON.parse(hidden.value || '[]');
                                    } catch (error) {
                                        selectedUsers = [];
                                    }

                                    const exists = selectedUsers.some(user => String(user.id) === String(userId));

                                    if (!exists) {
                                        selectedUsers.push({
                                            id: parseInt(userId, 10),
                                            name: name,
                                            email: email
                                        });
                                    }

                                    hidden.value = JSON.stringify(selectedUsers);
                                }

                                input.value = '';
                                dropdown.classList.add('hidden');
                                dropdown.innerHTML = '';

                                if (selected) {
                                    selected.innerHTML = selectedUsers.map(user => `
                                        <div class="selected-user-chip" data-user-id="${user.id}">
                                            <span>${escapeHtml(user.name || '')}${user.email ? ' · ' + escapeHtml(user.email) : ''}</span>
                                            <button type="button" class="remove-selection" data-lang="${lang}" data-user-id="${user.id}">×</button>
                                        </div>
                                    `).join('');

                                    selected.classList.toggle('hidden', selectedUsers.length === 0);

                                    selected.querySelectorAll('.remove-selection').forEach(button => {
                                        button.addEventListener('click', function() {
                                            const rowLang = this.dataset.lang;
                                            const removeUserId = this.dataset.userId;
                                            const rowHidden = document.getElementById('languageReviewerIds_' + rowLang);
                                            const rowSelected = document.getElementById('languageReviewerSelected_' + rowLang);

                                            let rowUsers = [];

                                            if (rowHidden) {
                                                try {
                                                    rowUsers = JSON.parse(rowHidden.value || '[]');
                                                } catch (error) {
                                                    rowUsers = [];
                                                }

                                                rowUsers = rowUsers.filter(user => String(user.id) !== String(removeUserId));
                                                rowHidden.value = JSON.stringify(rowUsers);
                                            }

                                            if (rowSelected) {
                                                this.closest('.selected-user-chip')?.remove();
                                                rowSelected.classList.toggle('hidden', rowSelected.querySelectorAll('.selected-user-chip').length === 0);
                                            }
                                        });
                                    });
                                }
                            });
                        });
                    })
                    .catch(err => {
                        console.error('Language reviewer search error:', err);
                        dropdown.classList.add('hidden');
                    });
            }, 300);
        });
    });

    document.addEventListener('click', function(e) {
        document.querySelectorAll('.language-review-dropdown').forEach(dropdown => {
            const row = dropdown.closest('[data-language-review-row]');
            if (row && !row.contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });
    });

    const btnSendLanguageReviewRequests = document.getElementById('btnSendLanguageReviewRequests');

    if (btnSendLanguageReviewRequests) {
        btnSendLanguageReviewRequests.addEventListener('click', function() {
            const assignments = [];

            document.querySelectorAll('[data-language-review-row]').forEach(row => {
                const lang = row.dataset.lang;
                const userIdsField = row.querySelector('.language-review-user-ids');
                const publishField = row.querySelector('.language-review-can-publish');

                let selectedUsers = [];

                if (userIdsField) {
                    try {
                        selectedUsers = JSON.parse(userIdsField.value || '[]');
                    } catch (error) {
                        selectedUsers = [];
                    }
                }

                const userIds = selectedUsers
                    .map(user => parseInt(user.id || '0', 10))
                    .filter(userId => userId > 0);

                if (lang && userIds.length > 0) {
                    assignments.push({
                        language_code: lang,
                        user_ids: userIds,
                        can_publish: !!(publishField && publishField.checked)
                    });
                }
            });

            if (assignments.length === 0) {
                showNotification(<?= json_encode(sf_term('language_review_select_at_least_one', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>, 'warning');
                return;
            }

            const originalText = btnSendLanguageReviewRequests.textContent;
            btnSendLanguageReviewRequests.disabled = true;
            btnSendLanguageReviewRequests.textContent = <?= json_encode(sf_term('language_review_sending', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;

            const formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('assignments', JSON.stringify(assignments));
            formData.append('message', document.getElementById('languageReviewMessage')?.value || '');
            formData.append('csrf_token', window.SF_CSRF_TOKEN);

            fetch(baseUrl + '/app/api/request_language_review.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    showNotification(data.message || <?= json_encode(sf_term('language_review_requested_success', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>, 'success');
                    closeModal('modalLanguageReview');
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    showNotification(terms.errorPrefix + ': ' + (data.error || <?= json_encode(sf_term('reviewer_error', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>), 'error');
                }
            })
            .catch(err => {
                console.error('Language review request error:', err);
                showNotification(terms.errorNetwork, 'error');
            })
            .finally(() => {
                btnSendLanguageReviewRequests.disabled = false;
                btnSendLanguageReviewRequests.textContent = originalText;
            });
        });
    }
    
})();
</script>
<div class="sf-view-preview-fullscreen-modal hidden" id="sfViewPreviewFullscreenModal" aria-hidden="true">
    <div class="sf-view-preview-fullscreen-backdrop" id="sfViewPreviewFullscreenBackdrop"></div>

    <div class="sf-view-preview-fullscreen-dialog" role="dialog" aria-modal="true" aria-labelledby="sfViewPreviewFullscreenTitle">
        <div class="sf-view-preview-fullscreen-header">
            <h3 id="sfViewPreviewFullscreenTitle"><?= htmlspecialchars(sf_term('preview_and_save', $currentUiLang) ?? 'Esikatselu', ENT_QUOTES, 'UTF-8') ?></h3>

            <div class="sf-view-preview-fullscreen-toolbar">
                <button type="button" class="sf-view-preview-fullscreen-toolbtn" id="sfViewPreviewZoomOut" aria-label="Loitonna">−</button>
                <button type="button" class="sf-view-preview-fullscreen-toolbtn" id="sfViewPreviewZoomReset" aria-label="Sovita ruutuun">Sovita ruutuun</button>
                <button type="button" class="sf-view-preview-fullscreen-toolbtn" id="sfViewPreviewZoomIn" aria-label="Lähennä">+</button>
                <button type="button" class="sf-view-preview-fullscreen-close" id="sfViewPreviewFullscreenClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?: 'Sulje', ENT_QUOTES, 'UTF-8') ?>">×</button>
            </div>
        </div>

        <div class="sf-view-preview-fullscreen-body" id="sfViewPreviewFullscreenBody">
            <img
                id="sfViewPreviewFullscreenImage"
                src=""
                alt=""
                class="sf-view-preview-fullscreen-image"
            >
        </div>
    </div>
</div>

<!-- Image Lightbox Modal -->
<div class="image-lightbox" id="imageLightbox">
    <button class="image-lightbox-close" id="lightboxClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?: 'Sulje', ENT_QUOTES, 'UTF-8') ?>">&times;</button>
    <div class="image-lightbox-content">
        <img id="lightboxImage" src="" alt="">
    </div>
</div>

<!-- Upload Modal -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="uploadModal" role="dialog" aria-modal="true" aria-labelledby="uploadModalTitle">
    <div class="sf-modal-backdrop"></div>
    <div class="sf-modal-content sf-upload-modal-content">
        <div class="sf-modal-header">
            <h3 id="uploadModalTitle"><?= htmlspecialchars(sf_term('upload_modal_title', $currentUiLang) ?: 'Lisää kuvia', ENT_QUOTES, 'UTF-8') ?></h3>
            <button class="sf-modal-close" id="uploadModalClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?: 'Sulje', ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <div class="sf-modal-body">
            <div class="sf-upload-drop-zone" id="uploadDropZone">
                <div class="sf-upload-drop-icon">📁</div>
                <div class="sf-upload-drop-text"><?= htmlspecialchars(sf_term('upload_drag_text', $currentUiLang) ?: 'Vedä ja pudota kuvia tähän', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="sf-upload-drop-hint"><?= htmlspecialchars(sf_term('or', $currentUiLang) ?: 'tai', ENT_QUOTES, 'UTF-8') ?></div>
                <button type="button" class="sf-btn sf-btn-primary sf-upload-browse-btn" id="uploadBrowseBtn">
                    <?= htmlspecialchars(sf_term('upload_browse_btn', $currentUiLang) ?: 'Lataa koneelta', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="button" class="sf-btn sf-btn-secondary sf-upload-camera-btn" id="uploadCameraBtn" aria-label="<?= htmlspecialchars(sf_term('upload_camera_btn', $currentUiLang) ?: 'Ota kuva', ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(sf_term('upload_camera_btn', $currentUiLang) ?: 'Ota kuva', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <input type="file" id="uploadFileInput" name="images[]" accept="image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif" multiple style="display: none;" aria-label="<?= htmlspecialchars(sf_term('add_images_btn', $currentUiLang) ?: 'Lisää kuvia', ENT_QUOTES, 'UTF-8') ?>">
<input type="file" id="uploadCameraInput" name="camera_image" accept="image/*" capture="environment" style="display: none;" aria-label="<?= htmlspecialchars(sf_term('upload_camera_btn', $currentUiLang) ?: 'Ota kuva', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="sf-upload-progress" id="uploadProgress">
                <div class="sf-upload-progress-bar">
                    <div class="sf-upload-progress-fill" id="uploadProgressFill">0%</div>
                </div>
                <div class="sf-upload-progress-text" id="uploadProgressText" aria-live="polite"></div>
            </div>
        </div>
    </div>
</div>

<!-- Video Upload Modal -->
<div class="sf-modal hidden" data-bottom-sheet="true" id="videoUploadModal" role="dialog" aria-modal="true" aria-labelledby="videoUploadModalTitle">
    <div class="sf-modal-backdrop"></div>
    <div class="sf-modal-content sf-upload-modal-content">
        <div class="sf-modal-header">
            <h3 id="videoUploadModalTitle"><?= htmlspecialchars(sf_term('add_video_btn', $currentUiLang) ?: 'Lisää video', ENT_QUOTES, 'UTF-8') ?></h3>
            <button class="sf-modal-close" id="videoUploadModalClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?: 'Sulje', ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <div class="sf-modal-body">
            <div class="sf-upload-drop-zone" id="videoUploadDropZone">
                <div class="sf-upload-drop-icon">🎬</div>
                <div class="sf-upload-drop-text"><?= htmlspecialchars(sf_term('upload_drag_text', $currentUiLang) ?: 'Vedä ja pudota video tähän', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="sf-upload-drop-hint"><?= htmlspecialchars(sf_term('or', $currentUiLang) ?: 'tai', ENT_QUOTES, 'UTF-8') ?></div>
                <button type="button" class="sf-btn sf-btn-primary" id="videoUploadBrowseBtn">
                    <?= htmlspecialchars(sf_term('upload_browse_btn', $currentUiLang) ?: 'Valitse tiedosto', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <input type="file" id="videoUploadFileInput" name="video_file" accept=".mp4,.webm,.ogv,.ogg,.mov,.avi,.mkv" style="display: none;" aria-label="<?= htmlspecialchars(sf_term('add_video_btn', $currentUiLang) ?: 'Lisää video', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="sf-upload-progress" id="videoUploadProgress">
                <div class="sf-upload-progress-bar">
                    <div class="sf-upload-progress-fill" id="videoUploadProgressFill">0%</div>
                </div>
                <div class="sf-upload-progress-text" id="videoUploadProgressText" aria-live="polite"></div>
            </div>
        </div>
    </div>
</div>

<!-- Video Player Modal (native <dialog>) -->
<dialog id="sfVideoModal" class="sf-video-modal" aria-labelledby="sfVideoModalLabel">
    <div class="sf-video-modal-inner">
        <div class="sf-video-modal-header">
            <span id="sfVideoModalLabel" class="sf-video-modal-title"><?= htmlspecialchars(sf_term('video_modal_title', $currentUiLang) ?: 'Videotoisto', ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" class="sf-video-modal-close" id="sfVideoModalClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?: 'Sulje', ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <video id="sfVideoModalPlayer" class="sf-video-modal-player" controls playsinline preload="metadata">
            <source id="sfVideoModalSource" src="" type="video/mp4">
            <?= htmlspecialchars(sf_term('browser_no_video', $currentUiLang) ?: 'Selaimesi ei tue videotoistoa.', ENT_QUOTES, 'UTF-8') ?>
        </video>
    </div>
</dialog>

<!-- Images Tab JavaScript -->
<?php
require_once __DIR__ . '/../../app/includes/csrf.php';
$viewCsrfToken = sf_csrf_token();
?>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($viewCsrfToken, ENT_QUOTES, 'UTF-8') ?>" id="sfViewCsrfToken">
<script>
window.SF_CSRF_TOKEN = <?= json_encode($viewCsrfToken) ?>;
</script>
<script src="<?= sf_asset_url('assets/js/modules/extra_images_view.js', $base) ?>"></script>
<script>
(function() {
    'use strict';
    
    const flashId = <?= (int)$id ?>;
    const baseUrl = '<?= $base ?>';
    const canEdit = <?= json_encode($canEdit ?? false) ?>;
    const canAddExtraImages = <?= json_encode($canAddExtraImages ?? false) ?>;
    
    <?php
    // Build main images array for JavaScript
    $mainImages = [];
    $imageFields = [
        'image_main' => ['caption' => 'image1_caption', 'imageType' => 'main1'],
        'image_2' => ['caption' => 'image2_caption', 'imageType' => 'main2'],
        'image_3' => ['caption' => 'image3_caption', 'imageType' => 'main3']
    ];
    foreach ($imageFields as $field => $meta) {
        if (!empty($flash[$field])) {
            $filename = $flash[$field];
            $mainImages[] = [
                'url' => $getImageUrlForJs($filename),
                // Main images use full-size for both URL and thumb_url since they're already optimized
                // and don't have separate thumbnails in the uploads/images directory
                'thumb_url' => $getImageUrlForJs($filename),
                'isMain' => true,
                'filename' => $filename,
                'caption' => $flash[$meta['caption']] ?? '',
                'imageType' => $meta['imageType'],
                'flash_id' => (int)$id
            ];
        }
    }
    ?>
    const mainImages = <?= json_encode($mainImages) ?>;
    
    let imagesLoaded = false;
    
    // Set SF_BASE_URL for the module
    window.SF_BASE_URL = baseUrl;
    
    // Activity tab switching logic
    (function () {
        const activitySection = document.querySelector('.sf-view-activity-section');
        const allowedTabs = ['comments', 'events', 'additionalInfo', 'versions', 'images'];

        function getTabContentId(tabName) {
            return 'tab' + tabName.charAt(0).toUpperCase() + tabName.slice(1);
        }

        function activateActivityTab(tabName) {
            if (!activitySection || allowedTabs.indexOf(tabName) === -1) {
                return;
            }

            if (tabName === 'images' && !imagesLoaded) {
                imagesLoaded = true;

                if (typeof window.initExtraImages === 'function') {
                    window.initExtraImages(flashId, canEdit, mainImages, canAddExtraImages);
                }
            }

            const tabButtons = activitySection.querySelectorAll('.sf-activity-tab');
            const tabContents = activitySection.querySelectorAll('.sf-tab-content');

            tabButtons.forEach(function (button) {
                const isActive = button.dataset.tab === tabName;
                button.classList.toggle('active', isActive);
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            tabContents.forEach(function (content) {
                content.classList.remove('active');
                content.hidden = true;
            });

            const targetContent = document.getElementById(getTabContentId(tabName));

            if (targetContent) {
                targetContent.classList.add('active');
                targetContent.hidden = false;
            }
        }

        if (activitySection) {
            activitySection.addEventListener('click', function (event) {
                const clickedTab = event.target.closest('.sf-activity-tab');

                if (!clickedTab || !activitySection.contains(clickedTab)) {
                    return;
                }

                event.preventDefault();

                const targetTab = clickedTab.dataset.tab;

                if (!targetTab) {
                    return;
                }

                activateActivityTab(targetTab);
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        const initialTab = urlParams.get('tab');

        if (initialTab && allowedTabs.indexOf(initialTab) !== -1) {
            activateActivityTab(initialTab);
        } else {
            activateActivityTab('comments');
        }
    })();
    
    /**
     * Close lightbox
     */
    function closeLightbox() {
        const lightbox = document.getElementById('imageLightbox');
        if (lightbox) {
            lightbox.classList.remove('active');
        }
    }
    
    // Lightbox close handlers
    const lightboxClose = document.getElementById('lightboxClose');
    const lightbox = document.getElementById('imageLightbox');
    
    if (lightboxClose) {
        lightboxClose.addEventListener('click', closeLightbox);
    }
    
    if (lightbox) {
        // Close on background click
        lightbox.addEventListener('click', function(e) {
            if (e.target === lightbox) {
                closeLightbox();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && lightbox.classList.contains('active')) {
                closeLightbox();
            }
        });
    }
})();

// ===== PUBLISH MODALS =====
(function () {
    'use strict';

    window.openPublishModal = function () {
        if (window.SFPublishStepper && typeof window.SFPublishStepper.open === 'function') {
            window.SFPublishStepper.open('modalPublish');
        }
    };

    window.openPublishSingleModal = function () {
        if (window.SFPublishStepper && typeof window.SFPublishStepper.open === 'function') {
            window.SFPublishStepper.open('publishSingleModal');
        }
    };

    window.closePublishSingleModal = function () {
        if (window.SFPublishStepper && typeof window.SFPublishStepper.close === 'function') {
            window.SFPublishStepper.close('publishSingleModal');
        }
    };
})();
</script>

<?php if ($canMergeOriginalFlash): ?>
<script>
(function () {
    'use strict';

    var mergeBtn = document.getElementById('footerMergeFlash');
    var mergeModal = document.getElementById('modalMergeFlash');
    var searchInput = document.getElementById('sfMergeSearchInput');
    var statusBox = document.getElementById('sfMergeFlashStatus');
    var listBox = document.getElementById('sfMergeCandidateList');
    var confirmBox = document.getElementById('sfMergeConfirmBox');
    var confirmBtn = document.getElementById('sfMergeConfirmBtn');

    if (!mergeBtn || !mergeModal || !searchInput || !statusBox || !listBox || !confirmBtn) {
        return;
    }

    var baseUrl = <?= json_encode($base, JSON_UNESCAPED_UNICODE) ?>;
    var investigationId = <?= (int)$flash['id'] ?>;
    var csrfToken = <?= json_encode(sf_csrf_token(), JSON_UNESCAPED_UNICODE) ?>;

    var texts = {
        loading: <?= json_encode(sf_term('modal_merge_flash_loading', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        empty: <?= json_encode(sf_term('modal_merge_flash_empty', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        success: <?= json_encode(sf_term('modal_merge_flash_success', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        mergeButton: <?= json_encode(sf_term('btn_merge_flash', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        occurredPrefix: <?= json_encode(sf_term('modal_merge_flash_occurred_label', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        creatorPrefix: <?= json_encode(sf_term('modal_merge_flash_creator_label', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>
    };

    var selectedFlashId = 0;
    var selectedCard = null;
    var searchTimer = null;

    function openMergeModal() {
        mergeModal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
        searchInput.value = '';
        selectedFlashId = 0;
        selectedCard = null;
        confirmBtn.disabled = true;
        confirmBox.style.display = 'none';
        loadCandidates('');
        window.setTimeout(function () {
            searchInput.focus();
        }, 50);
    }

    function closeMergeModal() {
        mergeModal.classList.add('hidden');
        var anyOpen = document.querySelector('.sf-modal:not(.hidden)');
        if (!anyOpen) {
            document.body.classList.remove('sf-modal-open');
        }
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function typeColor(type) {
        if (type === 'red') {
            return '#dc2626';
        }
        if (type === 'yellow') {
            return '#d97706';
        }
        return '#2563eb';
    }

    function selectCandidate(card, flashId) {
        selectedFlashId = flashId;
        selectedCard = card;

        Array.prototype.forEach.call(listBox.querySelectorAll('.sf-merge-card'), function (item) {
            item.style.borderColor = '#e5e7eb';
            item.style.boxShadow = 'none';
            item.style.background = '#ffffff';
            item.setAttribute('aria-pressed', 'false');
        });

        card.style.borderColor = '#2563eb';
        card.style.boxShadow = '0 0 0 3px rgba(37, 99, 235, 0.18)';
        card.style.background = '#eff6ff';
        card.setAttribute('aria-pressed', 'true');

        confirmBtn.disabled = false;
        confirmBox.style.display = 'block';
    }

    function renderCandidates(items) {
        listBox.innerHTML = '';
        selectedFlashId = 0;
        selectedCard = null;
        confirmBtn.disabled = true;
        confirmBox.style.display = 'none';

        if (!items || !items.length) {
            statusBox.textContent = texts.empty;
            return;
        }

        statusBox.textContent = '';

        items.forEach(function (item) {
            var title = item.title && item.title.trim() !== '' ? item.title : (item.title_short || item.summary || ('#' + item.id));
            var summary = item.summary || '';
            var worksite = item.site || '';
            var siteDetail = item.site_detail || '';
            var creator = item.creator_name || '';
            var occurred = item.occurred_fmt || '';
            var color = typeColor(item.type);

            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'sf-merge-card';
            card.setAttribute('aria-pressed', 'false');
            card.style.textAlign = 'left';
            card.style.width = '100%';
            card.style.border = '1px solid #e5e7eb';
            card.style.background = '#ffffff';
            card.style.borderRadius = '14px';
            card.style.padding = '1rem';
            card.style.cursor = 'pointer';
            card.style.transition = 'all 0.16s ease';

            card.innerHTML = ''
                + '<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;">'
                + '  <div style="min-width:0;">'
                + '    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;flex-wrap:wrap;">'
                + '      <span style="display:inline-flex;align-items:center;gap:0.35rem;font-weight:700;color:' + color + ';">'
                + '        <span style="width:10px;height:10px;border-radius:999px;background:' + color + ';display:inline-block;"></span>'
                +          escapeHtml(item.type_label || item.type)
                + '      </span>'
                + '      <span style="display:inline-flex;padding:0.2rem 0.55rem;border-radius:999px;background:#f3f4f6;color:#374151;font-size:0.8rem;">'
                +          escapeHtml(item.state_label || item.state)
                + '      </span>'
                + '    </div>'
                + '    <div style="font-weight:700;color:#111827;margin-bottom:0.35rem;">' + escapeHtml(title) + '</div>'
                + (summary !== '' ? '<div style="color:#4b5563;font-size:0.95rem;line-height:1.45;margin-bottom:0.55rem;">' + escapeHtml(summary) + '</div>' : '')
                + '    <div style="display:flex;flex-direction:column;gap:0.2rem;color:#6b7280;font-size:0.88rem;">'
                + (worksite !== '' ? '<div><strong style="color:#374151;">' + escapeHtml(worksite) + '</strong>' + (siteDetail !== '' ? ' – ' + escapeHtml(siteDetail) : '') + '</div>' : '')
                + (occurred !== '' ? '<div><strong style="color:#374151;">' + escapeHtml(texts.occurredPrefix) + ':</strong> ' + escapeHtml(occurred) + '</div>' : '')
                + (creator !== '' ? '<div><strong style="color:#374151;">' + escapeHtml(texts.creatorPrefix) + ':</strong> ' + escapeHtml(creator) + '</div>' : '')
                + '    </div>'
                + '  </div>'
                + '  <div style="font-size:0.85rem;color:#6b7280;white-space:nowrap;">#' + escapeHtml(item.id) + '</div>'
                + '</div>';

            card.addEventListener('click', function () {
                selectCandidate(card, item.id);
            });

            listBox.appendChild(card);
        });
    }

    function loadCandidates(query) {
        statusBox.textContent = texts.loading;
        listBox.innerHTML = '';
        confirmBtn.disabled = true;
        confirmBox.style.display = 'none';

        var url = baseUrl + '/app/api/get_merge_candidates.php?flash_id=' + encodeURIComponent(investigationId);
        if (query && query.trim() !== '') {
            url += '&q=' + encodeURIComponent(query.trim());
        }

        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            if (!data.ok) {
                throw new Error(data.error || 'Load failed');
            }
            renderCandidates(data.items || []);
        })
        .catch(function (error) {
            statusBox.textContent = error && error.message ? error.message : 'Error';
        });
    }

    mergeBtn.addEventListener('click', function () {
        openMergeModal();
    });

    searchInput.addEventListener('input', function () {
        var value = this.value;
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () {
            loadCandidates(value);
        }, 250);
    });

    confirmBtn.addEventListener('click', function () {
        if (!selectedFlashId) {
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = texts.loading;

        var formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('investigation_id', String(investigationId));
        formData.append('original_flash_id', String(selectedFlashId));

        fetch(baseUrl + '/app/api/merge_investigation_flash.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            if (!data.ok) {
                throw new Error(data.error || 'Merge failed');
            }

            if (typeof window.sfToast === 'function') {
                window.sfToast('success', data.message || texts.success);
            }

            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }

            window.location.reload();
        })
        .catch(function (error) {
            confirmBtn.disabled = false;
            confirmBtn.textContent = texts.mergeButton;

            if (typeof window.sfToast === 'function') {
                window.sfToast('error', error && error.message ? error.message : 'Merge failed');
            } else {
                window.alert(error && error.message ? error.message : 'Merge failed');
            }
        });
    });

    document.addEventListener('click', function (event) {
        if (event.target === mergeModal) {
            closeMergeModal();
        }
    });
})();
</script>
<?php endif; ?>

<!-- Image Captions Module -->
<script src="<?= sf_asset_url('assets/js/modules/image_captions.js', $base) ?>"></script>

<script src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/js/body-map.js"></script>
<script>
(function () {
    'use strict';

    var flashId   = <?= (int)$flash['id'] ?>;
    var csrfToken = window.SF_CSRF_TOKEN || '';
    var apiUrl    = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/app/api/update_body_parts.php';

    var saveMsgs = {
        success: <?= json_encode(sf_term('body_map_save_success', $currentUiLang) ?: 'Loukkaantumiset tallennettu', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        error:   <?= json_encode(sf_term('body_map_save_error', $currentUiLang)   ?: 'Tallennus epäonnistui', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    };

    function init() {
        var saveBtn = document.getElementById('sfBodyMapSaveBtn');
        if (!saveBtn) { return; }

        saveBtn.addEventListener('click', function () {
            var hiddenSelect = document.getElementById('sfInjuredPartsHidden');
            if (!hiddenSelect) { return; }

            var parts = Array.from(hiddenSelect.options)
                .filter(function (o) { return o.selected; })
                .map(function (o) { return o.value; });

            var formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('csrf_token', csrfToken);
            parts.forEach(function (p) { formData.append('injured_parts[]', p); });

            fetch(apiUrl, { method: 'POST', body: formData })
                .then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                })
                .then(function (data) {
                    if (data.ok) {
                        // Sync hidden select and tags with the server-confirmed saved parts
                        if (Array.isArray(data.saved_parts) && window.BodyMap) {
                            window.BodyMap.init();
                        }
                        if (typeof showNotification === 'function') {
                            showNotification(saveMsgs.success, 'success');
                        }
                    } else {
                        if (typeof showNotification === 'function') {
                            showNotification(saveMsgs.error + (data.error ? ': ' + data.error : ''), 'error');
                        }
                    }
                })
                .catch(function () {
                    if (typeof showNotification === 'function') {
                        showNotification(saveMsgs.error, 'error');
                    }
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
<?php if ($canAccessSettings): ?>
<script>
(function () {
    'use strict';

    var flashId    = <?= (int)$flash['id'] ?>;
    var csrfToken  = window.SF_CSRF_TOKEN || '';
    var settingsApiUrl = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/app/api/save_report_settings.php';

    var settingsMsgs = {
        saved: <?= json_encode(sf_term('settings_original_type_saved', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        error: <?= json_encode(sf_term('settings_original_type_error', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    };

    function initSettingsModal() {
        // Auto-save original type on change
        var originalTypeSelect = document.getElementById('sfOriginalTypeSelect');
        if (originalTypeSelect) {
            originalTypeSelect.addEventListener('change', function () {
                saveOriginalType(this.value);
            });
        }
    }

    function saveOriginalType(value) {
        var statusEl = document.getElementById('sfOriginalTypeSaveStatus');
        if (statusEl) { statusEl.textContent = ''; }

        var formData = new FormData();
        formData.append('flash_id', flashId);
        formData.append('original_type', value);
        formData.append('csrf_token', csrfToken);

        fetch(settingsApiUrl, { method: 'POST', body: formData })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function (data) {
                if (data.ok) {
                    if (statusEl) {
                        statusEl.textContent = settingsMsgs.saved;
                        statusEl.className = 'sf-settings-save-status sf-settings-save-ok';
                        setTimeout(function () { statusEl.textContent = ''; statusEl.className = 'sf-settings-save-status'; }, 2500);
                    }
                } else {
                    if (statusEl) {
                        statusEl.textContent = settingsMsgs.error;
                        statusEl.className = 'sf-settings-save-status sf-settings-save-error';
                    }
                    if (typeof showNotification === 'function') {
                        showNotification(settingsMsgs.error, 'error');
                    }
                }
            })
            .catch(function () {
                if (statusEl) {
                    statusEl.textContent = settingsMsgs.error;
                    statusEl.className = 'sf-settings-save-status sf-settings-save-error';
                }
                if (typeof showNotification === 'function') {
                    showNotification(settingsMsgs.error, 'error');
                }
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSettingsModal);
    } else {
        initSettingsModal();
    }
})();
</script>
<?php endif; ?>

<!-- Body map buttons in Lisätiedot tab and right metadata panel -->
<?php if ($canEditBodyParts && $showBodyMapInTab): ?>
<script>
(function () {
    'use strict';

    function openBodyMapModal() {
        var bodyMapModal = document.getElementById('sfBodyMapModal');

        if (!bodyMapModal) {
            return;
        }

        bodyMapModal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');

        var focusable = bodyMapModal.querySelector('button, [href], input, select, textarea');

        if (focusable) {
            focusable.focus({ preventScroll: true });
        }
    }

    function initBodyMapButtons() {
        var tabBtn = document.getElementById('sfTabBodyMapBtn');
        var metaBtn = document.getElementById('sfMetaBodyMapBtn');

        if (tabBtn) {
            tabBtn.addEventListener('click', openBodyMapModal);
        }

        if (metaBtn) {
            metaBtn.addEventListener('click', openBodyMapModal);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBodyMapButtons);
    } else {
        initBodyMapButtons();
    }
})();
</script>
<?php endif; ?>

<!-- Additional Info AJAX -->
<?php if ($canAccessSettings): ?>
<script>
(function () {
    'use strict';

    var flashId          = <?= (int)$flash['id'] ?>;
    var csrfToken        = window.SF_CSRF_TOKEN || '';
    var additionalApiUrl = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/app/api/save_additional_info.php';

    var deleteApiUrl = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/app/api/delete_additional_info.php';

    var aiMsgs = {
        saved:            <?= json_encode(sf_term('additional_info_saved', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        error:            <?= json_encode(sf_term('additional_info_save_error', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        unknownAuthor:    <?= json_encode(sf_term('additional_info_unknown_author', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        titleAdd:         <?= json_encode(sf_term('additional_info_modal_add_title', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        titleEdit:        <?= json_encode(sf_term('additional_info_modal_edit_title', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        editBtnLabel:     <?= json_encode(sf_term('comment_edit', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        deleteBtnLabel:   <?= json_encode(sf_term('comment_delete', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        deleteConfirm:    <?= json_encode(sf_term('comment_delete_confirm', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        deleteSuccess:    <?= json_encode(sf_term('comment_deleted', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        deleteError:      <?= json_encode(sf_term('additional_info_save_error', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        baseUrl:          '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>',
    };

    var quillEditor = null;

    var SAFE_TAGS = ['P', 'BR', 'STRONG', 'EM', 'U', 'OL', 'UL', 'LI', 'SPAN'];
    function sanitizeHtml(html) {
        if (typeof DOMPurify !== 'undefined') {
            return DOMPurify.sanitize(html, { ALLOWED_TAGS: SAFE_TAGS, ALLOWED_ATTR: [] });
        }
        // DOMPurify not loaded — block submission to prevent unsanitized content
        return null;
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getQuill() {
        if (quillEditor) { return quillEditor; }
        if (typeof Quill === 'undefined') { return null; }
        var editorEl = document.getElementById('sfAdditionalInfoEditor');
        if (!editorEl) { return null; }
        quillEditor = new Quill('#sfAdditionalInfoEditor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    ['clean']
                ]
            }
        });
        return quillEditor;
    }

    function openModal(editId, prefillHtml) {
        var modal    = document.getElementById('sfAdditionalInfoModal');
        var titleEl  = document.getElementById('sfAdditionalInfoModalTitle');
        var editIdEl = document.getElementById('sfAdditionalInfoEditId');
        var status   = document.getElementById('sfAdditionalInfoStatus');
        if (!modal) { return; }

        editIdEl.value = editId || '';
        if (titleEl) { titleEl.textContent = editId ? aiMsgs.titleEdit : aiMsgs.titleAdd; }
        if (status)  { status.textContent = ''; }

        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');

        var q = getQuill();
        if (q) {
            if (prefillHtml) {
                q.clipboard.dangerouslyPasteHTML(sanitizeHtml(prefillHtml));
            } else {
                q.setContents([]);
            }
            setTimeout(function () { q.focus(); }, 50);
        }
    }

    function closeModal() {
        var modal = document.getElementById('sfAdditionalInfoModal');
        if (!modal) { return; }
        modal.classList.add('hidden');
        document.body.classList.remove('sf-modal-open');
    }

    function renderNewEntry(entry) {
        var name  = ((entry.first_name || '') + ' ' + (entry.last_name || '')).trim() || aiMsgs.unknownAuthor;
        var div   = document.createElement('div');
        div.className    = 'sf-comment-item';
        div.dataset.aiId = entry.id;
        var contentHtml  = sanitizeHtml(entry.content || '');
        div.innerHTML =
            '<div class="sf-comment-content">' +
                '<div class="sf-comment-header">' +
                    '<div>' +
                        '<span class="sf-comment-author">' + escapeHtml(name) + '</span>' +
                        ' <span class="sf-comment-time">&middot; ' + escapeHtml(entry.created_at || '') + '</span>' +
                    '</div>' +
                    '<div class="sf-comment-actions">' +
                        '<button type="button" class="sf-comment-action-btn btn-edit-additional-info"' +
                            ' data-ai-id="' + escapeHtml(String(entry.id)) + '"' +
                            ' data-content="' + escapeHtml(entry.content || '') + '">' +
                            '<img src="' + escapeHtml(aiMsgs.baseUrl) + '/assets/img/icons/create.svg" alt="" class="sf-action-icon">' +
                            ' ' + escapeHtml(aiMsgs.editBtnLabel) +
                        '</button>' +
                        '<button type="button" class="sf-comment-action-btn btn-delete-additional-info sf-text-danger"' +
                            ' data-ai-id="' + escapeHtml(String(entry.id)) + '">' +
                            '<img src="' + escapeHtml(aiMsgs.baseUrl) + '/assets/img/icons/delete.svg" alt="" class="sf-action-icon">' +
                            ' ' + escapeHtml(aiMsgs.deleteBtnLabel) +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="sf-comment-body">' + contentHtml + '</div>' +
            '</div>';
        return div;
    }

    function updateEntryInList(id, content) {
        var item = document.querySelector('.sf-comment-item[data-ai-id="' + id + '"]');
        if (!item) { return; }
        var contentEl  = item.querySelector('.sf-comment-body');
        var editBtn    = item.querySelector('.btn-edit-additional-info');
        if (contentEl) { contentEl.innerHTML = sanitizeHtml(content); }
        if (editBtn)   { editBtn.dataset.content = content; }
    }

    function submitForm() {
        var editIdEl    = document.getElementById('sfAdditionalInfoEditId');
        var status      = document.getElementById('sfAdditionalInfoStatus');
        var submitBtn   = document.getElementById('sfAdditionalInfoSubmitBtn');
        var editId      = editIdEl ? editIdEl.value.trim() : '';

        var q = getQuill();
        // Use getText() to reliably check if the editor is empty (strips all HTML)
        var plainText = q ? q.getText().trim() : '';
        if (!plainText) { return; }

        // Get and sanitize the HTML content before sending
        var content = q ? sanitizeHtml(q.root.innerHTML) : '';
        if (content === null) {
            // DOMPurify library failed to load — block submission
            if (status) {
                status.textContent = aiMsgs.error;
                status.style.color = '#dc2626';
            }
            return;
        }
        // Safety net: if sanitizer stripped everything (shouldn't happen when plainText is set)
        if (!content) { return; }

        if (submitBtn) { submitBtn.disabled = true; }
        if (status)    { status.textContent = ''; }

        var formData = new FormData();
        formData.append('flash_id',   flashId);
        formData.append('content',    content);
        formData.append('csrf_token', csrfToken);
        if (editId) { formData.append('id', editId); }

        fetch(additionalApiUrl, { method: 'POST', body: formData })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function (data) {
                if (data.ok && data.entry) {
                    closeModal();
                    document.body.classList.remove('sf-modal-open');
                    if (typeof showNotification === 'function') {
                        showNotification(aiMsgs.saved, 'success');
                    }
                    setTimeout(function () {
                        var url = new URL(window.location.href);
                        url.searchParams.set('tab', 'additionalInfo');
                        window.location.href = url.toString();
                    }, 500);
                } else {
                    if (status) {
                        status.textContent = aiMsgs.error;
                        status.style.color = '#dc2626';
                    }
                }
            })
            .catch(function () {
                if (status) {
                    status.textContent = aiMsgs.error;
                    status.style.color = '#dc2626';
                }
            })
            .finally(function () {
                if (submitBtn) { submitBtn.disabled = false; }
            });
    }

    function init() {
        // "Add text" button opens modal
        var openBtn = document.getElementById('sfOpenAddAdditionalInfoBtn');
        if (openBtn) {
            openBtn.addEventListener('click', function () {
                openModal('', '');
            });
        }

        // Form submit inside modal
        var form = document.getElementById('sfAdditionalInfoForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                submitForm();
            });
        }

        // Edit buttons on existing entries (delegated)
        var list = document.getElementById('sfAdditionalInfoList');
        if (list) {
            list.addEventListener('click', function (e) {
                var btn = e.target.closest('.btn-edit-additional-info');
                if (!btn) { return; }
                openModal(btn.dataset.aiId, btn.dataset.content);
            });
        }

        // Delete buttons on existing entries – open app modal for confirmation
        var pendingDeleteAiId = null;

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-delete-additional-info');
            if (!btn) { return; }
            var aiId = btn.dataset.aiId;
            if (!aiId) { return; }
            pendingDeleteAiId = aiId;
            var deleteModal = document.getElementById('modalDeleteInfo');
            if (deleteModal) {
                deleteModal.classList.remove('hidden');
                document.body.classList.add('sf-modal-open');
                var focusable = deleteModal.querySelector('button');
                if (focusable) { focusable.focus({ preventScroll: true }); }
            }
        });

        var deleteInfoConfirmBtn = document.getElementById('modalDeleteInfoConfirm');
        if (deleteInfoConfirmBtn) {
            deleteInfoConfirmBtn.addEventListener('click', function () {
                var aiId = pendingDeleteAiId;
                if (!aiId) { return; }
                pendingDeleteAiId = null;

                var deleteModal = document.getElementById('modalDeleteInfo');
                if (deleteModal) {
                    deleteModal.classList.add('hidden');
                    if (!document.querySelector('.sf-modal:not(.hidden)')) {
                        document.body.classList.remove('sf-modal-open');
                    }
                }

                var deleteBtn = document.querySelector('.btn-delete-additional-info[data-ai-id="' + aiId + '"]');
                if (deleteBtn) { deleteBtn.disabled = true; }

                var fd = new FormData();
                fd.append('id', aiId);
                fd.append('csrf_token', csrfToken);
                fetch(deleteApiUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            if (typeof showNotification === 'function') {
                                showNotification(aiMsgs.deleteSuccess, 'success');
                            }
                            var item = deleteBtn ? deleteBtn.closest('.sf-comment-item') : null;
                            if (item) {
                                item.style.transition = 'opacity 0.3s';
                                item.style.opacity = '0';
                            }
                            setTimeout(function () {
                                var url = new URL(window.location.href);
                                url.searchParams.set('tab', 'additionalInfo');
                                window.location.href = url.toString();
                            }, 400);
                        } else {
                            if (deleteBtn) { deleteBtn.disabled = false; }
                            if (typeof showNotification === 'function') {
                                showNotification(aiMsgs.deleteError, 'error');
                            }
                        }
                    })
                    .catch(function () {
                        if (deleteBtn) { deleteBtn.disabled = false; }
                        if (typeof showNotification === 'function') {
                            showNotification(aiMsgs.deleteError, 'error');
                        }
                    });
            });
        }

        // Close modal on backdrop click
        var modal = document.getElementById('sfAdditionalInfoModal');
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) { closeModal(); }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
<?php endif; ?>
<script>
(function () {
    'use strict';

    function closeWorkflowTooltips(exceptStep) {
        document.querySelectorAll('.sf-workflow-step.is-tooltip-open').forEach(function (step) {
            if (exceptStep && step === exceptStep) {
                return;
            }

            step.classList.remove('is-tooltip-open');
            step.setAttribute('aria-expanded', 'false');
        });
    }

    function initMobileWorkflowTooltips() {
        document.querySelectorAll('.sf-workflow-step').forEach(function (step) {
            if (step._sfMobileTooltipAttached) {
                return;
            }

            step.setAttribute('aria-expanded', 'false');

            step.addEventListener('click', function (event) {
                if (!window.matchMedia('(max-width: 640px)').matches) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                var willOpen = !step.classList.contains('is-tooltip-open');

                closeWorkflowTooltips(step);

                step.classList.toggle('is-tooltip-open', willOpen);
                step.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });

            step._sfMobileTooltipAttached = true;
        });

        document.addEventListener('click', function () {
            if (!window.matchMedia('(max-width: 640px)').matches) {
                return;
            }

            closeWorkflowTooltips(null);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeWorkflowTooltips(null);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMobileWorkflowTooltips);
    } else {
        initMobileWorkflowTooltips();
    }
})();
</script>