<?php
// app/api/users_reactivate.php
declare(strict_types=1);

// Ladataan konfiguraatio ja suojaukset
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json; charset=utf-8');

// Vain pääkäyttäjä (role_id = 1)
sf_require_role([1]);

// Vain POST-metodi
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

// Yhdistä tietokantaan
$mysqli = sf_db();
$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

if (!$mysqli) {
    sf_app_log('users_reactivate: Tietokantayhteys epäonnistui', LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => sf_term('error_database', $currentUiLang)]);
    exit;
}

// Lue aktivoitavan käyttäjän ID
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => sf_term('error_invalid_id', $currentUiLang)]);
    exit;
}

// Hae käyttäjän tiedot ennen aktivointia (audit-lokia varten)
$stmt = $mysqli->prepare('SELECT id, first_name, last_name, email, is_active FROM sf_users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$userToReactivate = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$userToReactivate) {
    echo json_encode(['ok' => false, 'error' => sf_term('error_user_not_found', $currentUiLang)]);
    exit;
}

// Tarkista että käyttäjä on deaktivoitu
if ($userToReactivate['is_active']) {
    echo json_encode(['ok' => false, 'error' => sf_term('error_user_already_active', $currentUiLang) ?? 'Käyttäjä on jo aktiivinen']);
    exit;
}

// Aktivoi käyttäjä (is_active = 1)
$stmt = $mysqli->prepare('UPDATE sf_users SET is_active = 1, updated_at = NOW() WHERE id = ?');
$stmt->bind_param('i', $id);

if (!$stmt->execute()) {
    sf_app_log('users_reactivate: UPDATE epäonnistui: ' . $stmt->error, LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => sf_term('error_db_update', $currentUiLang)]);
    exit;
}

$stmt->close();
$mysqli->close();

// Audit-lokitus
$currentUser = sf_current_user();
sf_audit_log(
    'user_reactivate',
    'user',
    $id,
    [
        'email' => $userToReactivate['email'],
        'name'  => ($userToReactivate['first_name'] ?? '') . ' ' . ($userToReactivate['last_name'] ?? ''),
    ],
    $currentUser ? (int)$currentUser['id'] : null
);

// Lokita onnistuminen
sf_app_log("users_reactivate: Käyttäjä aktivoitu uudelleen, id=$id, email=" . $userToReactivate['email']);

echo json_encode(['ok' => true]);