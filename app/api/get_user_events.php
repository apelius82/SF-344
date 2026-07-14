<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

$user = sf_current_user();

if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'events' => [], 'count' => 0]);
    exit;
}

$uiLang = $_SESSION['ui_lang'] ?? 'fi';

$labels = [
    'comment_added' => sf_term('events_comment_added', $uiLang),
    'pending_supervisor' => sf_term('events_pending_supervisor', $uiLang),
    'returned_to_creator' => sf_term('events_returned_to_creator', $uiLang),
    'sent_to_safety_team' => sf_term('events_sent_to_safety_team', $uiLang),
    'sent_to_comms' => sf_term('events_sent_to_comms', $uiLang),
    'investigation_pending' => sf_term('events_investigation_pending', $uiLang),
    'language_review_requested' => sf_term('events_language_review_requested', $uiLang),
    'flash_published' => sf_term('events_flash_published', $uiLang),
];

try {
    $pdo = Database::getInstance();

    $stmtCount = $pdo->prepare("
        SELECT COUNT(*)
        FROM sf_user_events
        WHERE user_id = ?
          AND is_read = 0
    ");
    $stmtCount->execute([(int)$user['id']]);
    $count = (int)$stmtCount->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, flash_id, event_type, event_group, title, url, created_at
        FROM sf_user_events
        WHERE user_id = ?
          AND is_read = 0
        ORDER BY 
            CASE WHEN event_group = 'action_required' THEN 0 ELSE 1 END,
            created_at DESC
        LIMIT 10
    ");
    $stmt->execute([(int)$user['id']]);

    $events = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eventType = (string)($row['event_type'] ?? '');

        $createdAtRaw = (string)$row['created_at'];
        $createdAtDisplay = $createdAtRaw;

        try {
            $createdAtDisplay = (new DateTime($createdAtRaw))->format('d.m.Y H:i');
        } catch (Throwable $dateError) {
            $createdAtDisplay = $createdAtRaw;
        }

        $events[] = [
            'id' => (int)$row['id'],
            'flash_id' => (int)$row['flash_id'],
            'event_type' => $eventType,
            'event_group' => (string)$row['event_group'],
            'label' => $labels[$eventType] ?? $eventType,
            'title' => (string)$row['title'],
            'url' => (string)$row['url'],
            'created_at' => $createdAtRaw,
            'created_at_display' => $createdAtDisplay,
        ];
    }

    echo json_encode([
        'ok' => true,
        'count' => $count,
        'events' => $events,
        'empty_text' => sf_term('events_empty', $uiLang),
        'heading' => sf_term('events_heading', $uiLang),
        'actions_heading' => sf_term('events_actions_heading', $uiLang),
        'info_heading' => sf_term('events_info_heading', $uiLang),
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log('get_user_events error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['ok' => false, 'events' => [], 'count' => 0]);
    exit;
}