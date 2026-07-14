<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/audit_log.php';

$base = rtrim($config['base_url'] ?? '', '/');
$lang = $_POST['lang'] ?? 'fi';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $base . '/assets/pages/login.php?lang=' . urlencode($lang));
    exit;
}

if (!sf_csrf_validate($_POST['csrf_token'] ?? null)) {
    header('Location: ' . $base . '/assets/pages/login.php?lang=' . urlencode($lang));
    exit;
}

$token = trim((string)($_POST['token'] ?? ''));
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($token === '') {
    header('Location: ' . $base . '/assets/pages/reset_password.php?error=invalid&lang=' . urlencode($lang));
    exit;
}

if ($newPassword !== $confirmPassword) {
    header('Location: ' . $base . '/assets/pages/reset_password.php?token=' . urlencode($token) . '&error=mismatch&lang=' . urlencode($lang));
    exit;
}

if (strlen($newPassword) < 8) {
    header('Location: ' . $base . '/assets/pages/reset_password.php?token=' . urlencode($token) . '&error=short&lang=' . urlencode($lang));
    exit;
}

$mysqli = sf_db();
$tokenHash = hash('sha256', $token);

$stmt = $mysqli->prepare(
    'SELECT pr.id, pr.user_id, pr.email
     FROM sf_password_resets pr
     INNER JOIN sf_users u ON u.id = pr.user_id
     WHERE pr.token_hash = ?
       AND pr.used_at IS NULL
       AND pr.expires_at > NOW()
       AND u.is_active = 1
     LIMIT 1'
);

if (!$stmt) {
    sf_app_log('password_reset: DB prepare failed: ' . $mysqli->error, LOG_LEVEL_ERROR);
    $mysqli->close();
    header('Location: ' . $base . '/assets/pages/reset_password.php?token=' . urlencode($token) . '&error=1&lang=' . urlencode($lang));
    exit;
}

$stmt->bind_param('s', $tokenHash);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    $mysqli->close();
    header('Location: ' . $base . '/assets/pages/reset_password.php?error=invalid&lang=' . urlencode($lang));
    exit;
}

$resetId = (int)$row['id'];
$userId = (int)$row['user_id'];
$userEmail = (string)$row['email'];

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
if ($newHash === false) {
    $mysqli->close();
    header('Location: ' . $base . '/assets/pages/reset_password.php?token=' . urlencode($token) . '&error=1&lang=' . urlencode($lang));
    exit;
}

$mysqli->begin_transaction();

try {
    $updateUserStmt = $mysqli->prepare('UPDATE sf_users SET password_hash = ? WHERE id = ?');
    if (!$updateUserStmt) {
        throw new RuntimeException('Could not prepare user update');
    }

    $updateUserStmt->bind_param('si', $newHash, $userId);
    if (!$updateUserStmt->execute()) {
        throw new RuntimeException('Could not update user password');
    }
    $updateUserStmt->close();

    $markUsedStmt = $mysqli->prepare('UPDATE sf_password_resets SET used_at = NOW() WHERE id = ?');
    if (!$markUsedStmt) {
        throw new RuntimeException('Could not prepare reset token update');
    }

    $markUsedStmt->bind_param('i', $resetId);
    if (!$markUsedStmt->execute()) {
        throw new RuntimeException('Could not mark token used');
    }
    $markUsedStmt->close();

    $invalidateOthersStmt = $mysqli->prepare(
        'UPDATE sf_password_resets
         SET used_at = NOW()
         WHERE user_id = ?
           AND id <> ?
           AND used_at IS NULL'
    );

    if ($invalidateOthersStmt) {
        $invalidateOthersStmt->bind_param('ii', $userId, $resetId);
        $invalidateOthersStmt->execute();
        $invalidateOthersStmt->close();
    }

    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    sf_app_log('password_reset: transaction failed: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    $mysqli->close();
    header('Location: ' . $base . '/assets/pages/reset_password.php?token=' . urlencode($token) . '&error=1&lang=' . urlencode($lang));
    exit;
}

sf_audit_log(
    'user_password_changed',
    'user',
    $userId,
    [
        'changed_via' => 'password_reset',
        'changed_user_id' => $userId,
        'changed_user_email' => $userEmail,
    ],
    $userId,
    'info'
);

$mysqli->close();

header('Location: ' . $base . '/assets/pages/login.php?password_reset=1&lang=' . urlencode($lang));
exit;