<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
define('SF_SKIP_AUTO_CSRF', true);
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/statuses.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
const SF_NEW_COMMENT_LOOKBACK_DAYS = 7;

function sf_list_updates_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sf_list_updates_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$payload = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($payload)) {
    $payload = [];
}

$idsInput = $payload['ids'] ?? ($_POST['ids'] ?? []);
if (is_string($idsInput)) {
    $idsInput = explode(',', $idsInput);
}
if (!is_array($idsInput)) {
    sf_list_updates_json(['ok' => false, 'error' => 'Invalid ids payload'], 400);
}

$ids = [];
foreach ($idsInput as $rawId) {
    $id = (int)$rawId;
    if ($id > 0) {
        $ids[$id] = true;
    }
}
$ids = array_keys($ids);

if (empty($ids)) {
    sf_list_updates_json(['ok' => true, 'updates' => []]);
}

if (count($ids) > 100) {
    $ids = array_slice($ids, 0, 100);
}

$user = sf_current_user();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    sf_list_updates_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$roleId = (int)($user['role_id'] ?? 0);
$isAdmin = $roleId === 1;
$isSafety = $roleId === 3;
$isComms = $roleId === 4;
$uiLang = (string)($_SESSION['ui_lang'] ?? 'fi');

try {
    $pdo = Database::getInstance();

    $currentUserCreatedAt = '1970-01-01 00:00:00';
    $stmtUserCreated = $pdo->prepare("SELECT created_at FROM sf_users WHERE id = :id LIMIT 1");
    $stmtUserCreated->execute([':id' => $userId]);
    $userCreatedRow = $stmtUserCreated->fetch(PDO::FETCH_ASSOC);
    if (!empty($userCreatedRow['created_at'])) {
        $currentUserCreatedAt = (string)$userCreatedRow['created_at'];
    }

    $idPlaceholders = [];
    $params = [
        ':read_user_id' => $userId,
        ':user_created_at' => $currentUserCreatedAt,
    ];
    foreach ($ids as $idx => $id) {
        $placeholder = ':id_' . $idx;
        $idPlaceholders[] = $placeholder;
        $params[$placeholder] = $id;
    }

    $visibilitySql = '';
    if (!$isAdmin) {
        $visibilitySql = " AND (
            f.state = 'published'
            OR f.created_by = :vis_uid
            OR (:is_safety = 1 AND f.state != 'draft')
            OR (:is_comms = 1 AND f.state = 'to_comms')
            OR (
                f.state = 'pending_supervisor'
                AND f.selected_approvers IS NOT NULL
                AND JSON_VALID(f.selected_approvers)
                AND (
                    JSON_CONTAINS(f.selected_approvers, :vis_uid_json_num)
                    OR JSON_CONTAINS(f.selected_approvers, :vis_uid_json_str)
                    OR (
                        JSON_TYPE(JSON_EXTRACT(f.selected_approvers, '$.approver_ids')) = 'ARRAY'
                        AND (
                            JSON_CONTAINS(JSON_EXTRACT(f.selected_approvers, '$.approver_ids'), :vis_uid_json_num2)
                            OR JSON_CONTAINS(JSON_EXTRACT(f.selected_approvers, '$.approver_ids'), :vis_uid_json_str2)
                        )
                    )
                )
            )
        )";

        $params[':vis_uid'] = $userId;
        $params[':is_safety'] = $isSafety ? 1 : 0;
        $params[':is_comms'] = $isComms ? 1 : 0;
        $params[':vis_uid_json_num'] = (string)$userId;
        $params[':vis_uid_json_str'] = json_encode((string)$userId);
        $params[':vis_uid_json_num2'] = (string)$userId;
        $params[':vis_uid_json_str2'] = json_encode((string)$userId);
    }

    $sql = "SELECT
                f.id,
                f.state,
                f.updated_at,
                (
                    SELECT COUNT(*)
                    FROM safetyflash_logs sl
                    WHERE sl.flash_id = COALESCE(f.translation_group_id, f.id)
                      AND sl.event_type = 'comment_added'
                      AND sl.created_at > GREATEST(
                          COALESCE(
                              (
                                  SELECT last_read_at
                                  FROM sf_flash_reads r
                                  WHERE r.flash_id = COALESCE(f.translation_group_id, f.id)
                                    AND r.user_id = :read_user_id
                                  LIMIT 1
                              ),
                              '1970-01-01 00:00:00'
                          ),
                          :user_created_at,
                          DATE_SUB(NOW(), INTERVAL " . (int)SF_NEW_COMMENT_LOOKBACK_DAYS . " DAY)
                      )
                ) AS new_comment_count
            FROM sf_flashes f
            WHERE f.id IN (" . implode(',', $idPlaceholders) . ")"
            . $visibilitySql;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updates = [];
    foreach ($rows as $row) {
        $state = (string)($row['state'] ?? '');
        $stateDef = function_exists('sf_status_get') ? sf_status_get($state) : null;
        $updates[(string)((int)$row['id'])] = [
            'state' => $state,
            'state_label' => sf_status_label($state, $uiLang),
            'state_badge_class' => trim((string)($stateDef['badge_class'] ?? 'sf-status sf-status--other')),
            'new_comment_count' => max(0, (int)($row['new_comment_count'] ?? 0)),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    sf_list_updates_json([
        'ok' => true,
        'updates' => $updates,
    ]);
} catch (Throwable $e) {
    error_log('get_list_updates.php error: ' . $e->getMessage());
    sf_list_updates_json(['ok' => false, 'error' => 'Server error'], 500);
}