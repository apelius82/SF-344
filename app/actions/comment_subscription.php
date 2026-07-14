<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../assets/services/email_services.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    if (!function_exists('sf_current_user')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ei oikeuksia']);
        exit;
    }

    $user = sf_current_user();
    $userId = (int)($user['id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ei oikeuksia']);
        exit;
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Virheellinen ID']);
        exit;
    }

    if (!function_exists('sf_csrf_validate') || !sf_csrf_validate($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Virheellinen CSRF-tunniste']);
        exit;
    }

    $enabled = isset($_POST['comment_notifications_enabled']) && (int)$_POST['comment_notifications_enabled'] === 1;

    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("SELECT id, translation_group_id FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$flash) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'SafetyFlashia ei löytynyt']);
        exit;
    }

    $logFlashId = !empty($flash['translation_group_id'])
        ? (int)$flash['translation_group_id']
        : (int)$flash['id'];

    if (!function_exists('sf_set_comment_subscription')) {
        throw new RuntimeException('sf_set_comment_subscription function missing');
    }

    sf_set_comment_subscription($pdo, $logFlashId, $userId, $enabled);

    $uiLang = $_SESSION['ui_lang'] ?? 'fi';

    echo json_encode([
        'ok' => true,
        'message' => $enabled
            ? (sf_term('comment_notifications_enabled_notice', $uiLang) ?? 'Kommentti-ilmoitukset otettu käyttöön')
            : (sf_term('comment_notifications_disabled_notice', $uiLang) ?? 'Kommentti-ilmoitukset poistettu käytöstä')
    ]);
    exit;

} catch (Throwable $e) {
    sf_app_log('comment_subscription.php ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Tallennus epäonnistui'
    ]);
    exit;
}