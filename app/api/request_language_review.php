<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ . '/../../assets/services/email_services.php';
require_once __DIR__ . '/../services/PushNotificationService.php';
require_once __DIR__ . '/../services/UserEventService.php';

$currentUser = sf_current_user();

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    sf_csrf_check();

    $pdo = Database::getInstance();

    $flashId = isset($_POST['flash_id']) ? (int)$_POST['flash_id'] : 0;
    $assignmentsJson = trim((string)($_POST['assignments'] ?? ''));
	$requestMessage = trim((string)($_POST['message'] ?? ''));

    if ($flashId <= 0 || $assignmentsJson === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
        exit;
    }

    $assignments = json_decode($assignmentsJson, true);

    if (!is_array($assignments)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid assignments']);
        exit;
    }

    $stmtFlash = $pdo->prepare("
        SELECT id, title, lang, type, state, translation_group_id
        FROM sf_flashes
        WHERE id = ?
        LIMIT 1
    ");
    $stmtFlash->execute([$flashId]);
    $flash = $stmtFlash->fetch(PDO::FETCH_ASSOC);

    if (!$flash) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found']);
        exit;
    }

    $roleId = (int)($currentUser['role_id'] ?? 0);
    $isAdmin = ($roleId === 1);
    $isSafety = ($roleId === 3);
    $isComms = ($roleId === 4);

    if (!$isAdmin && !$isSafety && !$isComms) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => sf_term('error_no_edit_permission', $_SESSION['ui_lang'] ?? 'fi')]);
        exit;
    }

    $allowedLanguages = ['sv', 'en', 'it', 'el'];
    $saved = [];

    $pdo->beginTransaction();

    foreach ($assignments as $assignment) {
        $languageCode = strtolower(trim((string)($assignment['language_code'] ?? '')));
        $canPublish = !empty($assignment['can_publish']) ? 1 : 0;

        $userIds = [];

        if (!empty($assignment['user_ids']) && is_array($assignment['user_ids'])) {
            foreach ($assignment['user_ids'] as $assignmentUserId) {
                $assignmentUserId = (int)$assignmentUserId;

                if ($assignmentUserId > 0) {
                    $userIds[] = $assignmentUserId;
                }
            }
        } elseif (!empty($assignment['user_id'])) {
            $assignmentUserId = (int)$assignment['user_id'];

            if ($assignmentUserId > 0) {
                $userIds[] = $assignmentUserId;
            }
        }

        $userIds = array_values(array_unique($userIds));

        if (!in_array($languageCode, $allowedLanguages, true) || empty($userIds)) {
            continue;
        }

        $translationGroupId = !empty($flash['translation_group_id'])
            ? (int)$flash['translation_group_id']
            : (int)$flash['id'];

        $stmtLanguageFlash = $pdo->prepare("
            SELECT id
            FROM sf_flashes
            WHERE (id = :group_id OR translation_group_id = :group_id_2)
              AND lang = :lang
            LIMIT 1
        ");
        $stmtLanguageFlash->execute([
            ':group_id' => $translationGroupId,
            ':group_id_2' => $translationGroupId,
            ':lang' => $languageCode,
        ]);

        $languageFlashId = (int)$stmtLanguageFlash->fetchColumn();

        if ($languageFlashId <= 0) {
            continue;
        }

        foreach ($userIds as $userId) {
            $stmtUser = $pdo->prepare("
                SELECT id, first_name, last_name, email, ui_lang
                FROM sf_users
                WHERE id = ?
                  AND is_active = 1
                LIMIT 1
            ");
            $stmtUser->execute([$userId]);
            $reviewer = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if (!$reviewer) {
                continue;
            }

            $stmtInsert = $pdo->prepare("
                INSERT INTO sf_flash_language_reviewers
                    (flash_id, language_code, user_id, assigned_by, assigned_at, can_publish, status)
                VALUES
                    (:flash_id, :language_code, :user_id, :assigned_by, NOW(), :can_publish, 'pending')
                ON DUPLICATE KEY UPDATE
                    assigned_by = VALUES(assigned_by),
                    assigned_at = NOW(),
                    can_publish = VALUES(can_publish),
                    status = 'pending',
                    completed_at = NULL,
                    published_at = NULL
            ");

            $stmtInsert->execute([
                ':flash_id' => $languageFlashId,
                ':language_code' => $languageCode,
                ':user_id' => $userId,
                ':assigned_by' => (int)$currentUser['id'],
                ':can_publish' => $canPublish,
            ]);

            $saved[] = [
                'flash_id' => $languageFlashId,
                'language_code' => $languageCode,
                'user_id' => $userId,
                'name' => trim((string)($reviewer['first_name'] ?? '') . ' ' . (string)($reviewer['last_name'] ?? '')),
                'email' => (string)($reviewer['email'] ?? ''),
            ];
        }

        $stmtUpdateLanguageFlashState = $pdo->prepare("
            UPDATE sf_flashes
            SET state = 'awaiting_publish',
                updated_at = NOW()
            WHERE id = ?
              AND state IN ('draft', 'to_comms')
        ");
        $stmtUpdateLanguageFlashState->execute([$languageFlashId]);
    }

    if (!$saved) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => sf_term('language_review_no_assignments', $_SESSION['ui_lang'] ?? 'fi')]);
        exit;
    }

    $logFlashId = !empty($flash['translation_group_id'])
        ? (int)$flash['translation_group_id']
        : (int)$flash['id'];

    $logRows = array_map(static function (array $row): string {
        return strtoupper((string)$row['language_code']) . ' – ' . (string)$row['name'];
    }, $saved);

    sf_log_event(
        $logFlashId,
        'language_review_requested',
        'Kielitarkistus pyydetty: ' . implode(', ', $logRows)
    );

    if ($requestMessage !== '') {
        $commentFlashType = (string)($flash['type'] ?? '');
        if (!in_array($commentFlashType, ['red', 'yellow', 'green'], true)) {
            $commentFlashType = '';
        }

        $commentDescription = "log_comment_type: " . $commentFlashType . "\n"
            . "log_comment_label: log_language_review_comment: " . mb_substr($requestMessage, 0, 2000);

        $stmtLanguageReviewComment = $pdo->prepare("
            INSERT INTO safetyflash_logs
                (flash_id, user_id, event_type, description, parent_comment_id, created_at)
            VALUES
                (:flash_id, :user_id, :event_type, :description, NULL, NOW())
        ");
        $stmtLanguageReviewComment->execute([
            ':flash_id' => $logFlashId,
            ':user_id' => (int)$currentUser['id'],
            ':event_type' => 'language_review_comment',
            ':description' => $commentDescription,
        ]);
    }

    foreach ($saved as $row) {
        UserEventService::createEvent(
            $pdo,
            (int)$row['user_id'],
            (int)$row['flash_id'],
            'language_review_requested',
            'action_required'
        );
    }

    $pdo->commit();

    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
    $flashTitle = (string)($flash['title'] ?? '');

    foreach ($saved as $row) {
        $flashUrl = $baseUrl . '/index.php?page=view&id=' . (int)$row['flash_id'];
        $stmtReviewer = $pdo->prepare("
            SELECT id, first_name, last_name, email, ui_lang
            FROM sf_users
            WHERE id = ?
            LIMIT 1
        ");
        $stmtReviewer->execute([(int)$row['user_id']]);
        $reviewer = $stmtReviewer->fetch(PDO::FETCH_ASSOC);

        if (!$reviewer || empty($reviewer['email'])) {
            continue;
        }

        $userLang = (string)($reviewer['ui_lang'] ?? 'fi');
        $reviewerName = trim((string)($reviewer['first_name'] ?? '') . ' ' . (string)($reviewer['last_name'] ?? ''));

        $subject = sf_term('language_review_email_subject', $userLang);
        $languageLabel = strtoupper((string)$row['language_code']);
        $subject = str_replace('{title}', $flashTitle, $subject);

        $bodyText = str_replace(
            ['{name}', '{title}', '{language}'],
            [$reviewerName, $flashTitle, $languageLabel],
            sf_term('language_review_email_body_text', $userLang)
        );

        $emailData = [
            'type' => 'yellow',
            'flash_id' => (int)$row['flash_id'],
            'subject' => $subject,
            'body_text' => $bodyText,
            'flash_title' => $flashTitle,
            'flash_worksite' => '',
            'flash_url' => $flashUrl,
            'message' => $requestMessage,
            'message_label' => $requestMessage !== ''
                ? sf_term('language_review_message_email_heading', $userLang)
                : '',
        ];

        $emailContent = sf_build_email_html($emailData, $userLang);

        try {
            sf_send_email(
                $subject,
                $emailContent['html'],
                $emailContent['text'],
                [(string)$reviewer['email']],
                [],
                (int)$row['flash_id'],
                'sf_language_review'
            );
        } catch (Throwable $emailError) {
            error_log('request_language_review email error: ' . $emailError->getMessage());
        }

        try {
            PushNotificationService::sendToUser(
                (int)$reviewer['id'],
                $subject,
                strip_tags((string)($emailContent['text'] ?? $bodyText)),
                $flashUrl,
                'sf_language_review'
            );
        } catch (Throwable $pushError) {
            error_log('request_language_review push error: ' . $pushError->getMessage());
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => sf_term('language_review_requested_success', $_SESSION['ui_lang'] ?? 'fi'),
        'assignments' => $saved,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('request_language_review error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}