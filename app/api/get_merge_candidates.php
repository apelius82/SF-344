<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ . '/../includes/statuses.php';

global $config;
Database::setConfig($config['db'] ?? []);

function sf_merge_candidate_json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => false,
        'error' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function sf_merge_candidate_type_label(string $type, string $lang): string
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    sf_merge_candidate_json_error('Method not allowed', 405);
}

$user = sf_current_user();
$userId = (int)($user['id'] ?? 0);
$roleId = (int)($user['role_id'] ?? 0);
$isAdmin = ($roleId === 1);

if ($userId <= 0) {
    sf_merge_candidate_json_error('Authentication required', 401);
}

$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
$currentFlashId = (int)($_GET['flash_id'] ?? 0);
$query = trim((string)($_GET['q'] ?? ''));

if ($currentFlashId <= 0) {
    sf_merge_candidate_json_error('Invalid flash ID', 400);
}

try {
    $pdo = Database::getInstance();

    $stmtCurrent = $pdo->prepare("
        SELECT id, type, original_type, created_by, is_archived, translation_group_id
        FROM sf_flashes
        WHERE id = ?
        LIMIT 1
    ");
    $stmtCurrent->execute([$currentFlashId]);
    $currentFlash = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

    if (!$currentFlash) {
        sf_merge_candidate_json_error('Flash not found', 404);
    }

    $isOwner = ((int)($currentFlash['created_by'] ?? 0) === $userId);
    $isSafety = ($roleId === 3);
    $isComms = ($roleId === 4);

    if (!$isAdmin && !$isSafety && !$isComms && !$isOwner) {
        sf_merge_candidate_json_error('Permission denied', 403);
    }

    if (($currentFlash['type'] ?? '') !== 'green') {
        sf_merge_candidate_json_error('Only investigation reports can use merge', 400);
    }

    if (!empty($currentFlash['translation_group_id']) && (int)$currentFlash['translation_group_id'] !== (int)$currentFlash['id']) {
        sf_merge_candidate_json_error('Merge can only be done from the original investigation report', 400);
    }

    if (!empty($currentFlash['is_archived'])) {
        sf_merge_candidate_json_error('Archived report cannot be merged', 400);
    }

    $hasLinkedOriginalFlash = false;

    try {
        $stmtHasLinkedOriginalFlash = $pdo->prepare("
            SELECT COUNT(*)
            FROM sf_flash_snapshots
            WHERE flash_id = ?
              AND version_type IN ('ensitiedote', 'vaaratilanne')
        ");
        $stmtHasLinkedOriginalFlash->execute([$currentFlashId]);
        $hasLinkedOriginalFlash = ((int)$stmtHasLinkedOriginalFlash->fetchColumn() > 0);
    } catch (Throwable $e) {
        error_log('get_merge_candidates.php linked original check failed: ' . $e->getMessage());
        $hasLinkedOriginalFlash = false;
    }

    if ($hasLinkedOriginalFlash) {
        sf_merge_candidate_json_error('Original flash already linked', 400);
    }

    $whereSearch = '';
    $params = [
        $currentFlashId,
    ];
    $searchParams = [];

    if ($query !== '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        $whereSearch = "
            AND (
                f.title LIKE ?
                OR f.title_short LIKE ?
                OR f.summary LIKE ?
                OR f.site LIKE ?
                OR f.site_detail LIKE ?
            )
        ";
        $searchParams[] = $like;
        $searchParams[] = $like;
        $searchParams[] = $like;
        $searchParams[] = $like;
        $searchParams[] = $like;
    }

    $userIdJsonNumber = (string)$userId;
    $userIdJsonString = json_encode((string)$userId, JSON_UNESCAPED_UNICODE);

    $sql = "
        SELECT
            f.id,
            f.type,
            f.lang,
            f.title,
            f.title_short,
            f.summary,
            f.site,
            f.site_detail,
            f.state,
            f.created_by,
            f.occurred_at,
            f.updated_at,
            DATE_FORMAT(f.occurred_at, '%d.%m.%Y %H:%i') AS occurred_fmt,
            DATE_FORMAT(f.updated_at, '%d.%m.%Y %H:%i') AS updated_fmt,
            u.first_name,
            u.last_name
        FROM sf_flashes f
        LEFT JOIN sf_users u ON u.id = f.created_by
        WHERE f.id <> ?
          AND f.type IN ('red', 'yellow')
          AND f.is_archived = 0
          AND (f.translation_group_id IS NULL OR f.translation_group_id = f.id)
          AND (
                ? = 1
                OR f.created_by = ?
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
          {$whereSearch}
        ORDER BY
            CASE f.state
                WHEN 'published' THEN 1
                WHEN 'to_comms' THEN 2
                WHEN 'pending_review' THEN 3
                WHEN 'pending_supervisor' THEN 4
                WHEN 'request_info' THEN 5
                WHEN 'reviewed' THEN 6
                WHEN 'draft' THEN 7
                ELSE 8
            END,
            f.updated_at DESC,
            f.id DESC
        LIMIT 200
    ";

    $params[] = $isAdmin ? 1 : 0;
    $params[] = $userId;
    $params[] = $userId;
    $params[] = $userIdJsonNumber;
    $params[] = $userIdJsonString;
    $params[] = $userIdJsonNumber;
    $params[] = $userIdJsonString;

    if (!empty($searchParams)) {
        foreach ($searchParams as $searchParam) {
            $params[] = $searchParam;
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $creatorName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));

        $items[] = [
            'id' => (int)$row['id'],
            'type' => (string)$row['type'],
            'type_label' => sf_merge_candidate_type_label((string)$row['type'], $currentUiLang),
            'title' => (string)($row['title'] ?? ''),
            'title_short' => (string)($row['title_short'] ?? ''),
            'summary' => (string)($row['summary'] ?? ''),
            'site' => (string)($row['site'] ?? ''),
            'site_detail' => (string)($row['site_detail'] ?? ''),
            'state' => (string)($row['state'] ?? ''),
            'state_label' => (string)(sf_status_label((string)($row['state'] ?? ''), $currentUiLang) ?? (string)($row['state'] ?? '')),
            'lang' => (string)($row['lang'] ?? 'fi'),
            'occurred_at' => (string)($row['occurred_at'] ?? ''),
            'occurred_fmt' => (string)($row['occurred_fmt'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'updated_fmt' => (string)($row['updated_fmt'] ?? ''),
            'creator_name' => $creatorName,
        ];
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('get_merge_candidates.php: ' . $e->getMessage());
    sf_merge_candidate_json_error('Server error', 500);
}