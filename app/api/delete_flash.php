<?php
declare(strict_types=1);


header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/file_cleanup.php';

// Tarkista kirjautuminen
$user = sf_current_user();
if (!$user) {
  echo json_encode(['success' => false, 'error' => 'Not authenticated']);
  exit;
}

// CSRF-tarkistus (token voi tulla GET- tai POST-parametrina)
$csrfToken = $_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '';
if (! sf_csrf_validate($csrfToken)) {
  echo json_encode(['success' => false, 'error' => 'Invalid security token']);
  exit;
}

// Tarkista ID
if (!isset($_GET['id'])) {
  echo json_encode(['success' => false, 'error' => 'Missing id']);
  exit;
}

$id = (int) $_GET['id'];

if ($id <= 0) {
  echo json_encode(['success' => false, 'error' => 'Invalid id']);
  exit;
}

// DB-yhteys
try {
    $pdo = Database::getInstance();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DB error']);
    exit;
}

// Hae flash-tiedot ENNEN poistoa (audit logia varten)
$stmt = $pdo->prepare('SELECT id, title FROM sf_flashes WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$flash = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flash) {
  echo json_encode(['success' => false, 'error' => 'Flash not found']);
  exit;
}

$flashId = (int) $flash['id'];
$flashTitle = $flash['title'] ?? '';

// Cleanup files BEFORE deleting database record
sf_cleanup_flash_files($pdo, $flashId);

// Delete related background jobs before the flash record.
// This is explicit defensive cleanup for environments where the
// ON DELETE CASCADE migration (002_sf_jobs_cascade_delete.sql) has
// not yet been applied; once it is applied the cascade handles it
// automatically but this explicit delete is harmless.
$stmt = $pdo->prepare('DELETE FROM sf_jobs WHERE flash_id = :flash_id');
$stmt->execute([':flash_id' => $flashId]);

// Poista tietokannasta
$stmt = $pdo->prepare('DELETE FROM sf_flashes WHERE id = :id');
$result = $stmt->execute([':id' => $id]);

if (!$result) {
  echo json_encode(['success' => false, 'error' => 'Delete failed']);
  exit;
}

// === AUDIT LOG - poisto onnistui ===
sf_audit_log(
  'flash_delete',
  'flash',
  $flashId,
  ['title' => $flashTitle],
  $user ? (int) $user['id'] : null
);

echo json_encode(['success' => true]);