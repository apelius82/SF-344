<?php
// app/includes/log.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Generoi uniikki batch_id tallennusoperaatiolle.
 * Kutsutaan kerran per save-operaatio ja välitetään kaikkiin sf_log_event-kutsuihin.
 */
function sf_log_generate_batch_id(): string
{
    return sprintf(
        '%08x-%04x-4%03x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff),
        random_int(0x8000, 0xbfff),
        random_int(0, 0xffffffffffff)
    );
}

/**
 * Kirjaa tapahtuman safetyflash-lokiin.
 *
 * @param int         $flashId     Safetyflashin ID (sf_flashes.id)
 * @param string      $eventType   lyhyt koodi: created, updated, status_changed, comment_added, sent_to_review, published, etc.
 * @param string      $description ihmisen luettava selite lokiin
 * @param string|null $batchId     Valinnainen batch-tunniste niputusta varten
 */
function sf_log_event(int $flashId, string $eventType, string $description = '', ?string $batchId = null, ?int $workflowOrder = null, ?string $flashTypeAtEvent = null): void
{
    $mysqli = sf_db();
    $user   = sf_current_user();
    $userId = $user['id'] ?? null;

    if ($workflowOrder === null) {
        $workflowOrder = sf_log_workflow_order($eventType, $description);
    }

    if ($flashTypeAtEvent === null) {
        $flashTypeAtEvent = sf_log_flash_type($flashId);
    }

    $sql = "INSERT INTO safetyflash_logs
                (flash_id, user_id, event_type, description, batch_id, workflow_order, flash_type_at_event, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        error_log('sf_log_event prepare failed: ' . $mysqli->error);
        return;
    }

    $uid = $userId ? (int)$userId : 0;
    $stmt->bind_param('iisssis', $flashId, $uid, $eventType, $description, $batchId, $workflowOrder, $flashTypeAtEvent);

    if (!$stmt->execute()) {
        error_log('sf_log_event execute failed: ' . $stmt->error);
    }

    $stmt->close();
}

function sf_log_flash_type(int $flashId): ?string
{
    $mysqli = sf_db();

    $stmt = $mysqli->prepare("SELECT type FROM sf_flashes WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $flashId);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    $type = (string)($row['type'] ?? '');

    return in_array($type, ['red', 'yellow', 'green'], true) ? $type : null;
}

function sf_log_workflow_order(string $eventType, string $description = ''): int
{
    $text = mb_strtolower($description, 'UTF-8');

    if ($eventType === 'created' || $eventType === 'CREATED') {
        return 10;
    }

    if ($eventType === 'state_changed' && str_contains($text, 'pending_supervisor')) {
        return 20;
    }

    if ($eventType === 'supervisor_approved') {
        return 30;
    }

    if ($eventType === 'state_changed' && str_contains($text, 'pending_review') && !str_contains($text, 'to_comms') && !str_contains($text, 'viestinnällä')) {
        return 40;
    }

    if ($eventType === 'state_changed' && (str_contains($text, 'to_comms') || str_contains($text, 'viestinnällä'))) {
        return 50;
    }

    if ($eventType === 'sent_to_comms') {
        return 60;
    }

    if ($eventType === 'language_review_requested') {
        return 70;
    }

    if ($eventType === 'published' || $eventType === 'worksite_notification_sent') {
        return 80;
    }

    if ($eventType === 'investigation_created' || $eventType === 'type_changed') {
        return 15;
    }

    return 100;
}
/**
 * Log field-level changes as a single event.
 *
 * @deprecated Use direct INSERT into safetyflash_logs combined with sf_audit_log() instead.
 *
 * @param int   $flashId Flash ID
 * @param array $old     Old field values
 * @param array $new     New field values
 */
function sf_log_changes(int $flashId, array $old, array $new): void
{
    trigger_error('sf_log_changes() is deprecated, use direct INSERT into safetyflash_logs combined with sf_audit_log() instead', E_USER_DEPRECATED);
    if (!function_exists('sf_term')) {
        require_once __DIR__ . '/../../assets/lib/sf_terms.php';
    }

    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

    $fieldTermMap = [
        'title'       => 'log_title_changed',
        'title_short' => 'log_title_short_changed',
        'summary'     => 'log_summary_changed',
        'description' => 'log_description_changed',
        'site'        => 'log_site_changed',
        'site_detail' => 'log_site_detail_changed',
        'occurred_at' => 'log_occurred_at_changed',
        'root_causes' => 'log_root_causes_changed',
        'actions'     => 'log_actions_changed',
    ];

    $changes = [];

    foreach ($new as $key => $value) {
        if (!array_key_exists($key, $old)) {
            continue;
        }
        if ($old[$key] === $value) {
            continue;
        }

        // Ohitetaan tekniset kentät
        if (in_array($key, ['updated_at', 'created_at', 'preview_filename'], true)) {
            continue;
        }

        $oldVal = (string)($old[$key] ?? '');
        $newVal = (string)($value ?? '');

        $localizedName = isset($fieldTermMap[$key])
            ? sf_term($fieldTermMap[$key], $currentUiLang)
            : ucfirst($key);

        $changes[] = $localizedName . ": '{$oldVal}' → '{$newVal}'";
    }

    if (!$changes) {
        return;
    }

    $msg = implode("\n", $changes);
    sf_log_event($flashId, 'status_changed', $msg);
}