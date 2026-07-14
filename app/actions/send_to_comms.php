<?php
// app/actions/send_to_comms.php
declare(strict_types=1);

// Convert warnings/notices to exceptions
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/../includes/protect.php';
    require_once __DIR__ . '/../includes/log_app.php';
    require_once __DIR__ . '/../includes/statuses.php';
    require_once __DIR__ . '/../includes/log.php';

    if (is_file(__DIR__ . '/helpers.php')) {
        require_once __DIR__ . '/helpers.php';
    }

    require_once __DIR__ . '/../services/PushNotificationService.php';
    require_once __DIR__ . '/../services/UserEventService.php';
    require_once __DIR__ . '/../../assets/lib/Database.php';
    require_once __DIR__ . '/../../assets/services/email_services.php';

    $base = rtrim($config['base_url'] ?? '', '/');

    // Only POST
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header("Location: {$base}/index.php?page=list");
        exit;
    }

    // ID from URL
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        sf_app_log("send_to_comms.php: Invalid ID");
        http_response_code(400);
        echo 'Virheellinen ID.';
        exit;
    }

    // Message from form
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($message !== '') {
        $message = mb_substr($message, 0, 2000);
    }

    // Capture new multi-step form data
    $languages = $_POST['languages'] ?? [];
    if (!is_array($languages)) {
        $languages = [];
    }
    
    // Simplified distribution - just a toggle now
    $widerDistribution = !empty($_POST['wider_distribution']) ? (int)$_POST['wider_distribution'] : 0;

    // Safety team can preselect whether worksite notification should be sent later.
    // No email is sent here. The actual sending still happens only in publish.php.
    $sendWorksiteNotificationPreselected = (
        isset($_POST['send_worksite_notification_preselected'])
        && (string)$_POST['send_worksite_notification_preselected'] === '1'
    ) ? 1 : 0;

    $screensOption = trim((string) ($_POST['screens_option'] ?? 'all'));

    // Display target selections come from Step 2 as display_targets[flash_id][]
    $allDisplayTargets = isset($_POST['display_targets']) && is_array($_POST['display_targets'])
        ? $_POST['display_targets']
        : [];

    // Backward compatibility variables
    $selectedCountries = isset($_POST['countries']) ? (array)$_POST['countries'] : [];
    $selectedWorksites = isset($_POST['worksites']) ? (array)$_POST['worksites'] : [];
    $worksites = $selectedWorksites;

    sf_app_log("send_to_comms.php: Processing flash {$id}");
    sf_app_log("send_to_comms.php: Languages: " . json_encode($languages));
    sf_app_log("send_to_comms.php: Wider distribution: " . ($widerDistribution ? 'Yes' : 'No'));
    sf_app_log("send_to_comms.php: Screens option: {$screensOption}");
    sf_app_log("send_to_comms.php: Selected countries: " . json_encode($selectedCountries));
    sf_app_log("send_to_comms.php: Selected worksites: " . json_encode($selectedWorksites));
    sf_app_log("send_to_comms.php: Selected display targets: " . json_encode($allDisplayTargets));

    // DB connection
    $pdo = Database::getInstance();

    // Fetch flash
    $stmt = $pdo->prepare("SELECT id, translation_group_id, state FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $flash = $stmt->fetch();

    if (!$flash) {
        sf_app_log("send_to_comms.php: Flash not found, id={$id}");
        
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        if ($isAjax) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => 'Tiedotetta ei löytynyt.'
            ]);
            exit;
        }
        
        header("Location: {$base}/index.php?page=list&notice=error");
        exit;
    }

    $logFlashId = !empty($flash['translation_group_id'])
        ? (int) $flash['translation_group_id']
        : (int) $flash['id'];
        
        $oldState = (string) ($flash['state'] ?? '');

// --- Permission + state guard ---
$user = function_exists('sf_current_user') ? sf_current_user() : null;
$roleId = (int)($user['role_id'] ?? ($_SESSION['role_id'] ?? 0));

$isAdmin  = ($roleId === 1);
$isSafety = ($roleId === 3);

if (!$isAdmin && !$isSafety) {
    sf_app_log("send_to_comms.php: Forbidden (role_id={$roleId})");
    
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Ei oikeuksia.'
        ]);
        exit;
    }
    
    http_response_code(403);
    echo 'Ei oikeuksia.';
    exit;
}

// sallitaan lähetys viestintään vain järkevistä tiloista
if (!in_array($oldState, ['pending_review', 'pending_supervisor', 'reviewed'], true)) {
    sf_app_log("send_to_comms.php: Invalid state '{$oldState}' for sending to comms (id={$id})");
    
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => 'Tiedotetta ei voi lähettää viestintään tässä tilassa.'
        ]);
        exit;
    }
    
    header("Location: {$base}/index.php?page=view&id={$id}&notice=error");
    exit;
}

// Update state for all languages
$newState = 'to_comms';
$updatedCount = sf_update_state_all_languages($pdo, $id, $newState);

// Store worksite notification preselection for all language versions.
// This does not send any email. It only controls the default state of the publish modal.
try {
    $groupIdForNotificationPreselection = !empty($flash['translation_group_id'])
        ? (int)$flash['translation_group_id']
        : (int)$flash['id'];

    $stmtWorksiteNotificationPreselection = $pdo->prepare("
        UPDATE sf_flashes
        SET send_worksite_notification_preselected = :preselected
        WHERE id = :gid OR translation_group_id = :gid2
    ");

    $stmtWorksiteNotificationPreselection->execute([
        ':preselected' => $sendWorksiteNotificationPreselected,
        ':gid' => $groupIdForNotificationPreselection,
        ':gid2' => $groupIdForNotificationPreselection,
    ]);
} catch (Throwable $worksiteNotificationPreselectionError) {
    sf_app_log(
        'send_to_comms.php: worksite notification preselection save ERROR for flash '
        . $id . ': ' . $worksiteNotificationPreselectionError->getMessage(),
        LOG_LEVEL_ERROR
    );
}

    sf_app_log("send_to_comms.php: Flash {$id} state updated to {$newState} for {$updatedCount} language version(s)");

    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

    // User ID
    $userId = null;
    if (function_exists('sf_current_user')) {
        $user = sf_current_user();
        $userId = isset($user['id']) ? (int) $user['id'] : null;
    } else {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    // Generoi yksi batch_id tälle lähetysoperaatiolle (ei kommenteille)
    $commsBatchId = sf_log_generate_batch_id();

    // Resolve all language version IDs in this translation group
    $groupId = !empty($flash['translation_group_id'])
        ? (int) $flash['translation_group_id']
        : (int) $flash['id'];

    $stmtVersList = $pdo->prepare("
        SELECT id
        FROM sf_flashes
        WHERE id = :gid OR translation_group_id = :gid2
    ");
    $stmtVersList->execute([':gid' => $groupId, ':gid2' => $groupId]);
    $allVersionIds = array_map('intval', $stmtVersList->fetchAll(PDO::FETCH_COLUMN));

    // Save display target preselections first, then build log/email text from saved data
    try {
        $allActiveDisplayIds = [];
        if ($screensOption === 'all') {
            $stmtAllDisplays = $pdo->prepare("
                SELECT k.id
                FROM sf_display_api_keys k
                LEFT JOIN sf_worksites w ON w.id = k.worksite_id
                WHERE k.is_active = 1
                  AND (w.id IS NULL OR w.show_in_display_targets = 1)
            ");
            $stmtAllDisplays->execute();
            $allActiveDisplayIds = array_map('intval', $stmtAllDisplays->fetchAll(PDO::FETCH_COLUMN));
        }

        if (!empty($allDisplayTargets) || !empty($allActiveDisplayIds)) {
            $stmtDelete = $pdo->prepare("DELETE FROM sf_flash_display_targets WHERE flash_id = ?");
            $stmtInsert = $pdo->prepare("
                INSERT INTO sf_flash_display_targets
                (flash_id, display_key_id, is_active, selected_by, selected_at, activated_at)
                VALUES (?, ?, 0, ?, NOW(), NULL)
            ");

            foreach ($allVersionIds as $verId) {
                if ($screensOption === 'all') {
                    $targetsForThis = $allActiveDisplayIds;
                } else {
                    $targetsForThis = $allDisplayTargets[$verId] ?? [];
                }

                $stmtDelete->execute([$verId]);

                if (!empty($targetsForThis)) {
                    foreach ($targetsForThis as $displayId) {
                        $displayId = (int) $displayId;
                        if ($displayId > 0) {
                            $stmtInsert->execute([$verId, $displayId, $userId]);
                        }
                    }
                }
            }

            if (function_exists('sf_log_event')) {
                sf_log_event($logFlashId, 'display_targets_preselected', sf_term('log_display_targets_preselected', $currentUiLang), $commsBatchId);
            }

            sf_app_log("send_to_comms.php: Display targets preselected for flash {$id}");
        }
    } catch (Throwable $dtError) {
        sf_app_log(
            "send_to_comms.php: display_targets ERROR for flash {$id}: " . $dtError->getMessage(),
            LOG_LEVEL_ERROR
        );
    }

    // Build selected display/worksite labels from SAVED display target rows
    $selectedWorksiteNames = [];

    try {
        if ($screensOption === 'all') {
            $selectedWorksiteNames[] = sf_term('comms_screens_all', $currentUiLang) ?? 'Kaikki näytöt';
        } elseif (!empty($allVersionIds)) {
            $placeholders = implode(',', array_fill(0, count($allVersionIds), '?'));

            $stmtDisplays = $pdo->prepare("
                SELECT DISTINCT COALESCE(NULLIF(k.label, ''), NULLIF(k.site, ''), CONCAT('#', k.id)) AS display_name
                FROM sf_flash_display_targets t
                INNER JOIN sf_display_api_keys k ON k.id = t.display_key_id
                WHERE t.flash_id IN ($placeholders)
                ORDER BY display_name ASC
            ");
            $stmtDisplays->execute($allVersionIds);
            $selectedWorksiteNames = array_values(array_filter($stmtDisplays->fetchAll(PDO::FETCH_COLUMN)));
        }
    } catch (Throwable $e) {
        sf_app_log(
            "send_to_comms.php: display label fetch ERROR for flash {$id}: " . $e->getMessage(),
            LOG_LEVEL_ERROR
        );
    }

    $worksitesText = !empty($selectedWorksiteNames)
        ? implode(', ', array_unique($selectedWorksiteNames))
        : (sf_term('comms_summary_none', $currentUiLang) ?? 'Ei valintoja');

    $worksites = $selectedWorksiteNames;

    $desc = "log_status_set|status:{$newState}";
    if ($message !== '') {
        $desc .= "\nlog_message_to_comms_label: " . $message;
    }
    if (!empty($languages)) {
        $langLabels = array_map('strtoupper', $languages);
        $desc .= "\nemail_selected_languages: " . implode(', ', $langLabels);
    }
    if ($widerDistribution) {
        $desc .= "\nemail_wider_distribution_yes";
    } else {
        $desc .= "\nemail_no_distribution";
    }

    $desc .= $sendWorksiteNotificationPreselected
        ? "\nlog_worksite_notification_preselected_yes"
        : "\nlog_worksite_notification_preselected_no";

    $desc .= "\nemail_selected_worksites: " . $worksitesText;

    // Log event
    if (function_exists('sf_log_event')) {
        sf_log_event($logFlashId, 'sent_to_comms', $desc, $commsBatchId);
    } else {
        $log = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
            VALUES (:flash_id, :user_id, :event_type, :description, :batch_id, NOW())
        ");
        $log->execute([
            ':flash_id'   => $logFlashId,
            ':user_id'    => $userId,
            ':event_type' => 'sent_to_comms',
            ':description'=> $desc,
            ':batch_id'   => $commsBatchId,
        ]);
    }

    // Separate state_changed event
    if ($oldState !== $newState) {
        $stateChangeDesc = "log_state_changed: {$oldState} → {$newState}";

        if (function_exists('sf_log_event')) {
            sf_log_event($logFlashId, 'state_changed', $stateChangeDesc, $commsBatchId);
        } else {
            $logStateChange = $pdo->prepare("
                INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
                VALUES (:flash_id, :user_id, :event_type, :description, :batch_id, NOW())
            ");
            $logStateChange->execute([
                ':flash_id'   => $logFlashId,
                ':user_id'    => $userId,
                ':event_type' => 'state_changed',
                ':description'=> $stateChangeDesc,
                ':batch_id'   => $commsBatchId,
            ]);
        }
    }

    // Display targets are already saved above before log/email creation

    // Email sending (don't fail whole request if email fails)
    try {
        if (function_exists('sf_mail_to_comms')) {
            sf_app_log("send_to_comms.php: Attempting to send email for flash {$id}");
            // Pass countries and worksitesText for email body
            sf_mail_to_comms($pdo, $id, $message, true, $languages, $widerDistribution, $screensOption, $worksites, $selectedCountries, $worksitesText);
            sf_app_log("send_to_comms.php: Email sent successfully for flash {$id}");
        } else {
            sf_app_log("send_to_comms.php: sf_mail_to_comms function not found");
        }
    } catch (Throwable $emailError) {
        sf_app_log(
            "send_to_comms.php: EMAIL ERROR for flash {$id}: " . $emailError->getMessage(),
            LOG_LEVEL_ERROR
        );
        error_log(
            "send_to_comms.php EMAIL ERROR: " . $emailError->getMessage() . "\n" . $emailError->getTraceAsString()
        );
    }
    UserEventService::createRoleEvents(
        $pdo,
        4,
        $id,
        'sent_to_comms',
        'action_required',
        $userId
    );
    try {
        PushNotificationService::sendWorkflowToRole(
            $pdo,
            4,
            $id,
            'push_comms_title',
            'push_comms_body'
        );
    } catch (Throwable $pushError) {
        sf_app_log('send_to_comms.php: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
    }

    // Audit log
    require_once __DIR__ . '/../includes/audit_log.php';
    $user = function_exists('sf_current_user') ? sf_current_user() : null;

    sf_audit_log(
        'flash_to_comms',
        'flash',
        (int) $id,
        [
            'new_status'   => $newState,
            'has_message'  => ($message !== ''),
        ],
        $user ? (int) $user['id'] : null
    );

    // Julkaisuohjeet tallennetaan jo sent_to_comms-lokiin.
    // Erillistä comment_added-järjestelmäkommenttia ei luoda,
    // jotta sama sisältö ei näy kommenttinäkymässä kahteen kertaan.

    // Return JSON for AJAX, else redirect
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'       => true,
            'message' => sf_term('notice_sent_to_comms', $currentUiLang),
            'redirect' => "{$base}/index.php?page=view&id=" . (int) $id,
        ]);
        exit;
    }

    header("Location: {$base}/index.php?page=view&id=" . (int) $id . "&notice=comms_sent");
    exit;

} catch (Throwable $e) {
    if (function_exists('sf_app_log')) {
        sf_app_log(
            basename(__FILE__) . ' FATAL ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            LOG_LEVEL_ERROR
        );
    } else {
        error_log(basename(__FILE__) . ' FATAL ERROR: ' . $e->getMessage());
    }

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => false,
            'message' => $e->getMessage(),
            'debug'   => $e->getFile() . ':' . $e->getLine(),
            'trace'   => array_slice(explode("\n", $e->getTraceAsString()), 0, 3),
        ]);
        exit;
    }

    $base = rtrim($config['base_url'] ?? '', '/');
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($base !== '') {
        header("Location: {$base}/index.php?page=view&id={$id}&notice=error");
    } else {
        header("Location: /index.php?page=view&id={$id}&notice=error");
    }
    exit;

} finally {
    restore_error_handler();
}