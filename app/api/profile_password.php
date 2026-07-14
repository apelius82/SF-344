<?php
// app/api/profile_password.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json; charset=utf-8');

sf_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$user = sf_current_user();
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'Ei kirjautunut']);
    exit;
}

$mysqli = sf_db();

$currentPassword = trim((string)($_POST['current_password'] ?? ''));
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    echo json_encode(['ok' => false, 'error' => 'Täytä kaikki kentät']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['ok' => false, 'error' => 'Salasanat eivät täsmää']);
    exit;
}

if (strlen($newPassword) < 8) {
    echo json_encode(['ok' => false, 'error' => 'Salasanan on oltava vähintään 8 merkkiä']);
    exit;
}

$stmt = $mysqli->prepare('SELECT password_hash FROM sf_users WHERE id = ? LIMIT 1');
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'Tietokantavirhe']);
    exit;
}

$userId = (int)$user['id'];
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row || empty($row['password_hash']) || !password_verify($currentPassword, (string)$row['password_hash'])) {
    echo json_encode(['ok' => false, 'error' => 'Nykyinen salasana on väärin']);
    exit;
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
if ($newHash === false) {
    echo json_encode(['ok' => false, 'error' => 'Salasanan hash muodostus epäonnistui']);
    exit;
}

$stmt = $mysqli->prepare('UPDATE sf_users SET password_hash = ? WHERE id = ?');
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'Tietokantavirhe']);
    exit;
}

$stmt->bind_param('si', $newHash, $userId);

if (!$stmt->execute()) {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Tietokantavirhe']);
    exit;
}

$stmt->close();

sf_audit_log(
    'user_password_changed',
    'user',
    $userId,
    [
        'changed_via' => 'profile_modal',
        'changed_user_id' => $userId,
        'changed_user_email' => (string)($user['email'] ?? ''),
    ],
    $userId,
    'info'
);

echo json_encode([
    'ok' => true,
    'message' => 'Salasana vaihdettu onnistuneesti.'
]);