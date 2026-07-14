<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

global $config;
Database::setConfig($config['db'] ?? []);

$user = sf_current_user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$user['id'];
$commentId = (int)($_POST['comment_id'] ?? 0);

if ($commentId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid comment ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getInstance();

    $db->exec("
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

    $checkStmt = $db->prepare("
        SELECT id, flash_id
        FROM safetyflash_logs
        WHERE id = :comment_id
          AND event_type IN ('comment_added', 'submission_comment', 'language_review_comment')
        LIMIT 1
    ");
    $checkStmt->execute([':comment_id' => $commentId]);
    $comment = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Comment not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existingStmt = $db->prepare("
        SELECT id
        FROM sf_comment_likes
        WHERE comment_id = :comment_id
          AND user_id = :user_id
        LIMIT 1
    ");
    $existingStmt->execute([
        ':comment_id' => $commentId,
        ':user_id' => $userId,
    ]);

    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $deleteStmt = $db->prepare("
            DELETE FROM sf_comment_likes
            WHERE comment_id = :comment_id
              AND user_id = :user_id
        ");
        $deleteStmt->execute([
            ':comment_id' => $commentId,
            ':user_id' => $userId,
        ]);

        $liked = false;
        $auditAction = 'comment_like_removed';
    } else {
        $insertStmt = $db->prepare("
            INSERT INTO sf_comment_likes (comment_id, user_id, created_at)
            VALUES (:comment_id, :user_id, NOW())
        ");
        $insertStmt->execute([
            ':comment_id' => $commentId,
            ':user_id' => $userId,
        ]);

        $liked = true;
        $auditAction = 'comment_like_added';
    }

    $countStmt = $db->prepare("
        SELECT COUNT(*) AS like_count
        FROM sf_comment_likes
        WHERE comment_id = :comment_id
    ");
    $countStmt->execute([':comment_id' => $commentId]);
    $likeCount = (int)$countStmt->fetchColumn();

    $namesStmt = $db->prepare("
        SELECT TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS full_name
        FROM sf_comment_likes cl
        LEFT JOIN sf_users u ON u.id = cl.user_id
        WHERE cl.comment_id = :comment_id
        ORDER BY cl.created_at ASC, cl.id ASC
    ");
    $namesStmt->execute([':comment_id' => $commentId]);

    $likerNames = [];
    foreach ($namesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim((string)($row['full_name'] ?? ''));
        if ($name !== '') {
            $likerNames[] = $name;
        }
    }

    sf_audit_log(
        $auditAction,
        'comment',
        $commentId,
        [
            'flash_id' => (int)$comment['flash_id'],
            'like_count' => $likeCount,
        ],
        $userId,
        'info'
    );

    echo json_encode([
        'ok' => true,
        'liked' => $liked,
        'like_count' => $likeCount,
        'liker_names' => $likerNames,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Comment like toggle error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}