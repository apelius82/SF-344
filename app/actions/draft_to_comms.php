<?php
// app/actions/draft_to_comms.php
// Moves a single draft translation directly to to_comms when sibling versions
// have already advanced (to_comms / awaiting_publish / published).
declare(strict_types=1);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/../includes/protect.php';
    require_once __DIR__ . '/../includes/log.php';
    require_once __DIR__ . '/../includes/log_app.php';
    require_once __DIR__ . '/../includes/audit_log.php';
    require_once __DIR__ . '/helpers.php';

    $base = rtrim($config['base_url'] ?? '', '/');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header("Location: {$base}/index.php?page=list");
        exit;
    }

    // CSRF is validated automatically by protect.php for POST requests.

    $id = sf_validate_id();
    if ($id <= 0) {
        sf_redirect($base . '/index.php?page=list&notice=error');
    }

    $pdo = sf_get_pdo();

    $currentUser = sf_current_user();
    $userId      = isset($currentUser['id'])      ? (int)$currentUser['id']      : 0;
    $roleId      = isset($currentUser['role_id']) ? (int)$currentUser['role_id'] : 0;
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

    $isAdmin  = ($roleId === 1);
    $isSafety = ($roleId === 3);
    $isComms  = ($roleId === 4);

    // Fetch flash
    $stmt = $pdo->prepare("
        SELECT id, translation_group_id, state, created_by
        FROM sf_flashes
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$flash) {
        sf_redirect($base . "/index.php?page=list&notice=error");
    }

    $oldState = (string)($flash['state'] ?? '');
    $createdBy = (int)($flash['created_by'] ?? 0);
    $isOwner   = ($userId > 0 && $createdBy === $userId);

    // Permission: creator, admin, safety or comms
    if (!$isOwner && !$isAdmin && !$isSafety && !$isComms) {
        sf_app_log("draft_to_comms.php: Forbidden (user={$userId}, role={$roleId}, flash={$id})");
        http_response_code(403);
        echo 'Ei oikeuksia.';
        exit;
    }

    // Must be a draft
    if ($oldState !== 'draft') {
        sf_app_log("draft_to_comms.php: Invalid state '{$oldState}' for flash {$id}");
        sf_redirect($base . "/index.php?page=view&id={$id}&notice=error");
    }

    // Must belong to a translation group
    $gid = !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : 0;
    if ($gid <= 0) {
        sf_app_log("draft_to_comms.php: Flash {$id} has no translation_group_id");
        sf_redirect($base . "/index.php?page=view&id={$id}&notice=error");
    }

    // Must have at least one sibling already in to_comms / awaiting_publish / published
    $stmtSib = $pdo->prepare("
        SELECT COUNT(*) FROM sf_flashes
        WHERE (id = :gid OR translation_group_id = :gid2)
          AND id != :self
          AND state IN ('to_comms', 'awaiting_publish', 'published')
    ");
    $stmtSib->execute([':gid' => $gid, ':gid2' => $gid, ':self' => $id]);
    $hasAdvancedSibling = (int)$stmtSib->fetchColumn() > 0;

    if (!$hasAdvancedSibling) {
        sf_app_log("draft_to_comms.php: No advanced sibling for flash {$id}, redirecting to normal review");
        sf_redirect($base . "/index.php?page=view&id={$id}&notice=error");
    }

    // Update only this flash to to_comms
    $stmtUpdate = $pdo->prepare("
        UPDATE sf_flashes
        SET state = 'to_comms',
            updated_at = NOW()
        WHERE id = :id
          AND state = 'draft'
    ");
    $stmtUpdate->execute([':id' => $id]);

    sf_app_log("draft_to_comms.php: Flash {$id} moved draft→to_comms by user {$userId}");

    // Determine log flash id (group root / self)
    $logFlashId = $gid > 0 ? $gid : $id;

    // Log batch
    $batchId = sf_log_generate_batch_id();

    // Operational log: state_changed
    $stateChangeDesc = "log_state_changed: {$oldState} → to_comms";
    sf_log_event($logFlashId, 'state_changed', $stateChangeDesc, $batchId);

    // Audit log
    sf_audit_log(
        'flash_to_comms',
        'flash',
        $id,
        [
            'from_state'           => $oldState,
            'to_state'             => 'to_comms',
            'translation_group_id' => $gid,
            'direct_from_draft'    => true,
        ],
        $userId ?: null
    );

    sf_redirect($base . '/index.php?page=view&id=' . $id . '&saved=1');

} catch (Throwable $e) {
    if (function_exists('sf_app_log')) {
        sf_app_log(
            'draft_to_comms.php FATAL ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            LOG_LEVEL_ERROR
        );
    } else {
        error_log('draft_to_comms.php FATAL ERROR: ' . $e->getMessage());
    }

    $base = isset($config['base_url']) ? rtrim($config['base_url'], '/') : '';
    // Prefer the already-validated $id if set; fall back to raw GET param
    $id = isset($id) ? (int)$id : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if ($base !== '') {
        header("Location: {$base}/index.php?page=view&id={$id}&notice=error");
    } else {
        header("Location: /index.php?page=view&id={$id}&notice=error");
    }
    exit;
} finally {
    restore_error_handler();
}