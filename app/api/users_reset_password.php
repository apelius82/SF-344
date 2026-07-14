<?php
// app/api/users_reset_password.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/services/email_services.php';

header('Content-Type: application/json; charset=utf-8');

sf_require_role([1]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$mysqli = sf_db();
$id = (int)($_POST['id'] ?? 0);
$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => sf_term('error_invalid_id', $currentUiLang)]);
    exit;
}

// Generoi uusi salasana
function sf_random_password(int $length = 10): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

$newPass = sf_random_password(10);
$hash    = password_hash($newPass, PASSWORD_DEFAULT);

$stmt = $mysqli->prepare('UPDATE sf_users SET password_hash = ? WHERE id = ?');
$stmt->bind_param('si', $hash, $id);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'error' => sf_term('error_db_reset', $currentUiLang)]);
    exit;
}

// Lokita salasanan vaihto (ilman arkaluontoisia tietoja)
$logPost = $_POST;
unset($logPost['password']); // Varmistetaan ettei mitään salasanakenttää lokiteta
sf_app_log("users_reset_password: Salasana vaihdettu, user_id=$id", LOG_LEVEL_INFO, $logPost);

// Lähetä sähköposti uudella salasanalla
$emailSent = false;
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $emailSent = sf_mail_welcome_new_user($pdo, $id, $newPass);
    if ($emailSent) {
        sf_app_log("users_reset_password: Salasana-sähköposti lähetetty, user_id=$id", LOG_LEVEL_INFO);
    } else {
        sf_app_log("users_reset_password: Sähköpostin lähetys epäonnistui, user_id=$id", LOG_LEVEL_WARNING);
    }
} catch (Throwable $e) {
    sf_app_log("users_reset_password: Email exception: " . $e->getMessage(), LOG_LEVEL_ERROR);
}

echo json_encode(['ok' => true, 'password' => $newPass, 'email_sent' => $emailSent]);