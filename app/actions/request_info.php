<?php
// app/actions/request_info.php
declare(strict_types=1);

// Set error handler to convert warnings/notices to exceptions
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/statuses.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../services/PushNotificationService.php';
require_once __DIR__ . '/../services/UserEventService.php';
require_once __DIR__ . '/../../assets/services/email_services.php'; 
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

$base = rtrim($config['base_url'], '/');

// Tämä sivu käsittelee vain Palauta-lomakkeen POST-pyynnön
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: {$base}/index.php?page=list");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: {$base}/index.php?page=list");
    exit;
}

// Tässä käytetään helpers.php:n sf_get_pdo()-funktiota
$pdo = sf_get_pdo();

// Haetaan flash, jotta tiedetään ryhmätunnus (yhteinen loki kieliversioille)
$stmt = $pdo->prepare("SELECT id, translation_group_id, state FROM sf_flashes WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$flash = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flash) {
    header("Location: {$base}/index.php?page=list");
    exit;
}

$logFlashId = !empty($flash['translation_group_id'])
    ? (int)$flash['translation_group_id']
    : (int)$flash['id'];

$oldState = (string)($flash['state'] ?? '');
$newState = 'request_info';

// Päivitetään tila KAIKILLE kieliversioille
$updatedCount = sf_update_state_all_languages($pdo, $id, $newState);

// Lomakkeelta tullut viesti
$message = trim($_POST['message'] ?? '');

// Kirjataan info_requested tapahtuma
$desc = "log_status_set|status:{$newState}";
if ($message !== '') {
    $safeMsg = mb_substr($message, 0, 2000);
    $desc .= "\nlog_return_reason_label: " . $safeMsg;
}

// Kirjataan loki RYHMÄN JUUREEN → näkyy kaikissa kieliversioissa
// Generoi yksi batch_id tälle operaatiolle
$requestInfoBatchId = sf_log_generate_batch_id();
sf_log_event($logFlashId, 'info_requested', $desc, $requestInfoBatchId);

// Kirjataan myös erillinen state_changed tapahtuma
if ($oldState !== $newState) {
    $stateChangeDesc = "log_state_changed: {$oldState} → {$newState}";
    sf_log_event($logFlashId, 'state_changed', $stateChangeDesc, $requestInfoBatchId);
}

// Audit log
require_once __DIR__ . '/../includes/audit_log.php';
$user = sf_current_user();
sf_audit_log(
    'flash_info_request',
    'flash',
    (int)$id,
    [
        'new_status' => $newState,
        'message_length' => mb_strlen($message),
    ],
    $user ? (int)$user['id'] : null
);

// Save message as system comment so it appears in Comments tab
if ($message !== '') {
    $userId = $user ? (int)$user['id'] : ($_SESSION['user_id'] ?? null);
    $systemCommentDesc = "log_comment_label: log_return_reason_label: " . mb_substr($message, 0, 2000);
    $stmtSysComment = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (:flash_id, :user_id, :event_type, :description, NOW())
    ");
    $stmtSysComment->execute([
        ':flash_id'    => $logFlashId,
        ':user_id'     => $userId,
        ':event_type'  => 'comment_added',
        ':description' => $systemCommentDesc,
    ]);
}

// Lähetä sähköposti tekijälle
if (function_exists('sf_mail_request_info')) {
    try {
        sf_app_log("request_info: calling sf_mail_request_info for flashId={$id}");
        // HUOM: käytetään yksittäisen flashin id:tä ($id), ei translation_group_id:tä
        sf_mail_request_info($pdo, $id, $message);
        sf_app_log("request_info: sf_mail_request_info DONE for flashId={$id}");
    } catch (Throwable $e) {
        // Kirjoitetaan omaan sovelluslokiin, mutta EI kaadeta käyttäjää
        sf_app_log('request_info: sf_mail_request_info ERROR: ' . $e->getMessage());
    }
}
UserEventService::createReturnedToCreatorEvent($pdo, $id);
try {
    PushNotificationService::sendReturnedToCreator($pdo, $id);
} catch (Throwable $pushError) {
    sf_app_log('request_info: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
}

// Palauta JSON jos AJAX-pyyntö, muuten redirect
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'message' => 'Returned for corrections',
        'redirect' => "{$base}/index.php?page=view&id={$id}"
    ]);
    exit;
}

header("Location: {$base}/index.php?page=view&id={$id}&notice=request_info");
exit;

} catch (Throwable $e) {
    // Log error for debugging
    if (function_exists('sf_app_log')) {
        sf_app_log(basename(__FILE__) . ' FATAL ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_LEVEL_ERROR);
    } else {
        error_log(basename(__FILE__) . ' FATAL ERROR: ' . $e->getMessage());
    }
    
    // Check if this was an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        // Return JSON error
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Server error occurred',
            'debug' => $e->getFile() . ':' . $e->getLine()
        ]);
        exit;
    }
    
    // Fallback redirect for non-AJAX
    // Try to get base URL from config, but use a safe default if not available
    $base = '';
    if (isset($config['base_url'])) {
        $base = rtrim($config['base_url'], '/');
    }
    $id = $_GET['id'] ?? 0;
    if ($base !== '') {
        header("Location: {$base}/index.php?page=view&id={$id}&notice=error");
    } else {
        header("Location: /index.php?page=view&id={$id}&notice=error");
    }
    exit;
}

// Restore default error handler
restore_error_handler();