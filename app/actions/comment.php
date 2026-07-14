<?php
// app/actions/comment.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/statuses.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../assets/services/email_services.php';
require_once __DIR__ . '/../services/UserEventService.php';

$base = rtrim($config['base_url'] ?? '', '/');

// Vain POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: {$base}/index.php?page=list");
    exit;
}

// ID URL-paramista
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Virheellinen ID.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    header("Location: {$base}/index.php?page=list");
    exit;
}

// Kommentti lomakkeelta
$rawMessage = trim($_POST['message'] ?? '');
if ($rawMessage === '') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Kommentti ei voi olla tyhjä.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    header("Location: {$base}/index.php?page=view&id={$id}");
    exit;
}
$message = mb_substr($rawMessage, 0, 2000);

// PDO
$pdo = sf_get_pdo();

// Haetaan flash
$stmt = $pdo->prepare("
    SELECT id, translation_group_id, state, created_by, title, type
    FROM sf_flashes
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$flash = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flash) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Tiedotetta ei löytynyt.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    header("Location: {$base}/index.php?page=list");
    exit;
}

$logFlashId = !empty($flash['translation_group_id'])
    ? (int)$flash['translation_group_id']
    : (int)$flash['id'];

$currentState   = $flash['state'] ?? '';
$flashCreatorId = isset($flash['created_by']) ? (int)$flash['created_by'] : null;

// UI-kieli
$currentUiLang = $uiLang ?? ($_SESSION['lang'] ?? 'fi');

// Kommentti teksti
$commentLabels = [
    'fi' => 'Kommentti',
    'sv' => 'Kommentar',
    'en' => 'Comment',
    'it' => 'Commento',
    'el' => 'Σχόλιο',
];
$commentLabel = $commentLabels[$currentUiLang] ?? 'Comment';

// Lokikuvaus - tallennetaan avaimella, käännetään näyttöhetkellä.
// Mukana tallennushetken SafetyFlash-tyyppi, jotta tutkintatiedotteen kommenteista erottaa,
// liittyikö kommentti alkuperäiseen ensitiedotteeseen, vaaratilanteeseen vai tutkintatiedotteeseen.
$commentFlashType = (string)($flash['type'] ?? '');
if (!in_array($commentFlashType, ['red', 'yellow', 'green'], true)) {
    $commentFlashType = '';
}

$desc = "log_comment_type: " . $commentFlashType . "\n"
    . "log_comment_label: " . $message;

// Käyttäjä
$user   = sf_current_user();
$userId = $user ? (int)$user['id'] : ($_SESSION['user_id'] ?? null);

// Get parent comment ID if replying
$parentCommentId = !empty($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : null;

// Get comment notification preference from modal toggle
$commentNotificationsEnabled = isset($_POST['comment_notifications_enabled'])
    ? ((int)$_POST['comment_notifications_enabled'] === 1)
    : true;

// Kirjataan loki RYHMÄN JUUREEN safetyflash_logs-tauluun
$stmtLog = $pdo->prepare("
    INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, parent_comment_id, created_at)
    VALUES (:flash_id, :user_id, :event_type, :description, :parent_comment_id, NOW())
");
$stmtLog->execute([
    ':flash_id'   => $logFlashId,
    ':user_id'    => $userId,
    ':event_type' => 'comment_added',
    ':description'=> $desc,
    ':parent_comment_id'  => $parentCommentId,
]);

$commentId = (int)$pdo->lastInsertId();

UserEventService::createCommentEvents(
    $pdo,
    $logFlashId,
    $commentId,
    $userId ? (int)$userId : null,
    $flashCreatorId,
    $parentCommentId
);

// Save current user's per-flash notification preference
if ($userId && function_exists('sf_set_comment_subscription')) {
    sf_set_comment_subscription(
        $pdo,
        $logFlashId,
        (int)$userId,
        $commentNotificationsEnabled
    );
}

// ========== AUDIT LOG ==========
sf_audit_log(
    'flash_comment',
    'flash',
    (int)$id,
    [
        'title'   => $flash['title'] ?? null,
        'comment' => mb_substr($message, 0, 200),
    ],
    $user ? (int)$user['id'] : null
);
// ================================

// @mention notifications: users explicitly tagged in the comment.
// Server-side validation: hidden mentioned_user_ids[] is trusted only if the
// user's full @name is actually present in the comment text.
$mentionedIds = [];

if (!empty($_POST['mentioned_user_ids']) && is_array($_POST['mentioned_user_ids'])) {
    foreach ($_POST['mentioned_user_ids'] as $mid) {
        $mid = (int)$mid;

        if ($mid > 0) {
            $mentionedIds[] = $mid;
        }
    }
}

$mentionedIds = array_values(array_unique($mentionedIds));

if (!empty($mentionedIds)) {
    $placeholders = implode(',', array_fill(0, count($mentionedIds), '?'));

    $stmtMentionedUsers = $pdo->prepare("
        SELECT id, first_name, last_name
        FROM sf_users
        WHERE id IN ($placeholders)
          AND is_active = 1
    ");
    $stmtMentionedUsers->execute($mentionedIds);

    $validMentionedIds = [];

    foreach ($stmtMentionedUsers->fetchAll(PDO::FETCH_ASSOC) as $mentionedUser) {
        $mentionedUserId = (int)($mentionedUser['id'] ?? 0);
        $mentionedName = trim(
            (string)($mentionedUser['first_name'] ?? '') . ' ' . (string)($mentionedUser['last_name'] ?? '')
        );

        if ($mentionedUserId <= 0 || $mentionedName === '') {
            continue;
        }

        if (mb_stripos($message, '@' . $mentionedName) !== false) {
            $validMentionedIds[] = $mentionedUserId;
        }
    }

    $mentionedIds = array_values(array_unique($validMentionedIds));
}

// If the comment contains valid @mentions, notify only mentioned users.
// Otherwise use the normal comment notification flow.
if (!empty($mentionedIds)) {
    if (function_exists('sf_mail_mention_notifications')) {
        sf_mail_mention_notifications(
            $pdo,
            $logFlashId,
            $message,
            $userId ? (int)$userId : null,
            $mentionedIds
        );
    }
} elseif (function_exists('sf_mail_comment_notifications')) {
    sf_mail_comment_notifications(
        $pdo,
        $logFlashId,
        $message,
        $userId ? (int)$userId : null,
        $flashCreatorId,
        $parentCommentId
    );
}

// Jos ollaan viestintävaiheessa
if ($currentState === 'to_comms' && function_exists('sf_current_user_has_role')) {
    if (sf_current_user_has_role('comms') && function_exists('sf_mail_comms_comment_to_safety')) {
        sf_mail_comms_comment_to_safety($pdo, $logFlashId, $message, $userId, $flashCreatorId);
    }
}

// Check if AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    // Return JSON response for AJAX
    header('Content-Type: application/json; charset=utf-8');
    
    // Get user info for the response
    $currentUser = sf_current_user();
    $firstName = trim((string)($currentUser['first_name'] ?? ''));
    $lastName = trim((string)($currentUser['last_name'] ?? ''));
    $fullName = trim($firstName . ' ' . $lastName);
    
    $commentFlashTypeLabels = [
        'red' => function_exists('sf_term') ? sf_term('first_release', $currentUiLang) : 'Ensitiedote',
        'yellow' => function_exists('sf_term') ? sf_term('dangerous_situation', $currentUiLang) : 'Vaaratilanne',
        'green' => function_exists('sf_term') ? sf_term('investigation_report', $currentUiLang) : 'Tutkintatiedote',
    ];

    echo json_encode([
        'ok' => true,
        'message' => 'Comment added successfully',
        'comment' => [
            'id' => $commentId,
            'text' => $message,
            'author' => $fullName,
            'created_at' => date('Y-m-d H:i:s'),
            'parent_comment_id' => $parentCommentId,
            'flash_type' => $commentFlashType,
            'flash_type_label' => $commentFlashTypeLabels[$commentFlashType] ?? '',
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normal redirect for non-AJAX requests
header("Location: {$base}/index.php?page=view&id={$id}&notice=comment_added");
exit;