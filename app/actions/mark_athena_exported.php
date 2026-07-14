<?php
// app/actions/mark_athena_exported.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

$base = rtrim($config['base_url'] ?? '', '/');

// Only POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: {$base}/index.php?page=list");
    exit;
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Require authenticated user (protect.php already checks auth; get current user)
$currentUser = sf_current_user();
if (!$currentUser) {
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header("Location: {$base}/index.php?page=list");
    exit;
}

$userId = (int)$currentUser['id'];
$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

// Flash ID
$flashId = isset($_POST['flash_id']) ? (int)$_POST['flash_id'] : 0;
if ($flashId <= 0) {
    if ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Virheellinen ID.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header("Location: {$base}/index.php?page=list");
    exit;
}

$pdo = sf_get_pdo();

// Fetch flash to get logFlashId
$stmt = $pdo->prepare("
    SELECT id, translation_group_id, type, state, lang
    FROM sf_flashes
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$flashId]);
$flash = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flash) {
    if ($isAjax) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Tiedotetta ei löytynyt.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header("Location: {$base}/index.php?page=list");
    exit;
}

$logFlashId = !empty($flash['translation_group_id'])
    ? (int)$flash['translation_group_id']
    : (int)$flash['id'];

// Ensure athena exports table exists
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
} catch (Throwable $e) {
    error_log('mark_athena_exported: table creation error: ' . $e->getMessage());
}

// Insert export record
$exportedAt = date('Y-m-d H:i:s');
try {
    $ins = $pdo->prepare("
        INSERT INTO sf_flash_athena_exports (flash_id, user_id, exported_at, source)
        VALUES (:flash_id, :user_id, :exported_at, 'marked_done')
    ");
    $ins->execute([
        ':flash_id'    => $logFlashId,
        ':user_id'     => $userId,
        ':exported_at' => $exportedAt,
    ]);
} catch (Throwable $e) {
    error_log('mark_athena_exported: insert error: ' . $e->getMessage());
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Tietokantavirhe.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header("Location: {$base}/index.php?page=view&id={$flashId}&notice=error");
    exit;
}

// Insert system comment
try {
    $flashLang = strtoupper((string)($flash['lang'] ?? 'FI'));
$commentDesc = 'log_comment_label: ' . sf_term('log_athena_marked_done', $flash['lang'] ?? 'fi') . ' (' . $flashLang . ')';

    $stmtComment = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (:flash_id, :user_id, :event_type, :description, NOW())
    ");
    $stmtComment->execute([
        ':flash_id'    => $logFlashId,
        ':user_id'     => $userId,
        ':event_type'  => 'comment_added',
        ':description' => $commentDesc,
    ]);
} catch (Throwable $e) {
    error_log('mark_athena_exported: system comment error: ' . $e->getMessage());
}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'          => true,
        'exported_at' => $exportedAt,
        'user_name'   => $userName,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header("Location: {$base}/index.php?page=view&id={$flashId}&notice=athena_exported");
exit;