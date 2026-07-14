<?php
// app/api/users_delete.php
declare(strict_types=1);

// Ladataan konfiguraatio ja suojaukset
// HUOM: protect.php hoitaa session_start(), auth-tarkistuksen JA CSRF-validoinnin
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
    sf_app_log('users_delete: Tietokantayhteys epäonnistui', LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => sf_term('error_database', $currentUiLang)]);
    exit;
}

// Lue poistettavan käyttäjän ID
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => sf_term('error_invalid_id', $currentUiLang)]);
    exit;
}

// Hae käyttäjän tiedot ennen poistoa (audit-lokia varten)
$stmt = $mysqli->prepare('SELECT id, first_name, last_name, email FROM sf_users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$userToDelete = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$userToDelete) {
    echo json_encode(['ok' => false, 'error' => sf_term('error_user_not_found', $currentUiLang)]);
    exit;
}

// Estä oman käyttäjätilin poisto
$currentUser = sf_current_user();
if ($currentUser && (int)($currentUser['id'] ?? 0) === $id) {
    echo json_encode(['ok' => false, 'error' => sf_term('error_cannot_delete_self', $currentUiLang)]);
    exit;
}

// Pehmeä poisto (is_active = 0)
$stmt = $mysqli->prepare('UPDATE sf_users SET is_active = 0, updated_at = NOW() WHERE id = ?');
$stmt->bind_param('i', $id);

if (!$stmt->execute()) {
    sf_app_log('users_delete: UPDATE epäonnistui: ' . $stmt->error, LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => sf_term('error_db_delete', $currentUiLang)]);
    exit;
}

$stmt->close();

// Invalidoi käyttäjän aktiiviset sessiot
// Lisätään poistetun käyttäjän ID invalidated_users-taulukkoon
if (!isset($_SESSION['invalidated_users'])) {
    $_SESSION['invalidated_users'] = [];
}
if (!in_array($id, $_SESSION['invalidated_users'])) {
    $_SESSION['invalidated_users'][] = $id;
}

$mysqli->close();

// Audit-lokitus
sf_audit_log(
    'user_delete',
    'user',
    $id,
    [
        'email' => $userToDelete['email'],
        'name'  => ($userToDelete['first_name'] ?? '') . ' ' . ($userToDelete['last_name'] ?? ''),
    ],
    $currentUser ? (int)$currentUser['id'] : null
);

// Lokita onnistuminen
sf_app_log("users_delete: Käyttäjä poistettu (deaktivoitu), id=$id, email=" . $userToDelete['email']);

echo json_encode(['ok' => true]);