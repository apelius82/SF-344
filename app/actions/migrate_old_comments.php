<?php
// app/actions/migrate_old_comments.php
declare(strict_types=1);

/**
 * Migrate old log entries to comment_added entries.
 *
 * Finds existing safetyflash_logs rows whose event_type is one of
 *   sent_to_comms, supervisor_approved, submission_comment, info_requested
 * and whose description contains a user-visible message, then creates a
 * matching event_type='comment_added' row so the message appears on the
 * Comments tab — exactly the same as the current code does for new actions.
 *
 * Usage (admin only):
 *   GET  /app/actions/migrate_old_comments.php          → dry-run preview
 *   GET  /app/actions/migrate_old_comments.php?confirm=1 → execute migration
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../assets/lib/Database.php';

// ── Auth: admin only ────────────────────────────────────────────────────────
$currentUser = sf_current_user();
if (!$currentUser || (int)($currentUser['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><p>403 – Ei oikeuksia. Vain ylläpitäjä voi ajaa tämän skriptin.</p></body></html>';
    exit;
}

$dryRun  = empty($_GET['confirm']);
$pdo     = Database::getInstance();

// ── Mapping: event_type → (message extractor, comment prefix) ───────────────
//
// Each entry:
//   'source_types'  – event_type values to scan
//   'extract'       – callable(string $description): string|null
//                     returns the message or null if description has no message
//   'prefix'        – string prepended to extracted message in new description
//
$mappings = [
    [
        'source_types' => ['sent_to_comms'],
        'extract'      => static function (string $desc): ?string {
            // Format: "log_status_set|status:to_comms\nlog_message_to_comms_label: <message>\n..."
            if (preg_match('/log_message_to_comms_label:\s*(.+)/u', $desc, $m)) {
                return trim($m[1]);
            }
            return null;
        },
        'prefix'       => 'log_comment_label: LÄHETETTY VIESTINTÄÄN: ',
    ],
    [
        'source_types' => ['supervisor_approved'],
        'extract'      => static function (string $desc): ?string {
            // Format: "log_supervisor_approved\nlog_supervisor_message_label: <message>"
            if (preg_match('/log_supervisor_message_label:\s*(.+)/u', $desc, $m)) {
                return trim($m[1]);
            }
            return null;
        },
        'prefix'       => 'log_comment_label: TYÖMAAVASTAAVA: ',
    ],
    [
        'source_types' => ['submission_comment'],
        'extract'      => static function (string $desc): ?string {
            // Description IS the message (raw submission comment)
            $msg = trim($desc);
            return $msg !== '' ? $msg : null;
        },
        'prefix'       => 'log_comment_label: LÄHETETTY TURVATIIMILLE: ',
    ],
    [
        'source_types' => ['info_requested', 'request_info'],
        'extract'      => static function (string $desc): ?string {
            // Format: "log_status_set|status:request_info\nlog_return_reason_label: <message>"
            if (preg_match('/log_return_reason_label:\s*(.+)/u', $desc, $m)) {
                return trim($m[1]);
            }
            return null;
        },
        'prefix'       => 'log_comment_label: PALAUTETTU KORJATTAVAKSI: ',
    ],
];

// ── Collect all source event_types we need to query ─────────────────────────
$allSourceTypes = [];
foreach ($mappings as $m) {
    foreach ($m['source_types'] as $t) {
        $allSourceTypes[] = $t;
    }
}
$allSourceTypes = array_unique($allSourceTypes);

// ── Fetch candidate source rows ──────────────────────────────────────────────
$placeholders = implode(',', array_fill(0, count($allSourceTypes), '?'));
$stmtSrc = $pdo->prepare("
    SELECT id, flash_id, user_id, event_type, description, created_at
    FROM safetyflash_logs
    WHERE event_type IN ($placeholders)
    ORDER BY flash_id, created_at
");
$stmtSrc->execute($allSourceTypes);
$sourceRows = $stmtSrc->fetchAll(PDO::FETCH_ASSOC);

// ── For duplicate detection: load existing comment_added descriptions per flash ─
// We index by flash_id → set of descriptions for fast lookup.
$existingComments = [];
$stmtExist = $pdo->query("
    SELECT flash_id, description
    FROM safetyflash_logs
    WHERE event_type = 'comment_added'
");
while ($row = $stmtExist->fetch(PDO::FETCH_ASSOC)) {
    $existingComments[(int)$row['flash_id']][$row['description']] = true;
}

// ── Process source rows ───────────────────────────────────────────────────────
$toInsert  = [];  // rows we will (or would) insert
$skipped   = [];  // rows already migrated / no message

foreach ($sourceRows as $row) {
    $flashId   = (int)$row['flash_id'];
    $eventType = $row['event_type'];

    // Find the applicable mapping
    $mapping = null;
    foreach ($mappings as $m) {
        if (in_array($eventType, $m['source_types'], true)) {
            $mapping = $m;
            break;
        }
    }
    if ($mapping === null) {
        continue;
    }

    // Extract message
    $message = ($mapping['extract'])($row['description'] ?? '');
    if ($message === null || $message === '') {
        $skipped[] = ['source_id' => $row['id'], 'reason' => 'no message'];
        continue;
    }

    // Build the comment description (truncate to 2000 chars — matches the limit
    // applied in send_to_comms.php, request_info.php, and supervisor_to_safety.php)
    $commentDesc = $mapping['prefix'] . mb_substr($message, 0, 2000);

    // Skip if identical description already exists for this flash
    if (!empty($existingComments[$flashId][$commentDesc])) {
        $skipped[] = ['source_id' => $row['id'], 'reason' => 'already exists'];
        continue;
    }

    $toInsert[] = [
        'source_id'   => (int)$row['id'],
        'flash_id'    => $flashId,
        'user_id'     => $row['user_id'] !== null ? (int)$row['user_id'] : null,
        'description' => $commentDesc,
        'created_at'  => $row['created_at'],
    ];

    // Mark as seen to prevent inserting the same text twice (within this run)
    $existingComments[$flashId][$commentDesc] = true;
}

// ── Execute inserts (if not dry-run) ─────────────────────────────────────────
$insertedCount = 0;
if (!$dryRun) {
    $stmtIns = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (:flash_id, :user_id, 'comment_added', :description, :created_at)
    ");

    foreach ($toInsert as $ins) {
        $stmtIns->execute([
            ':flash_id'    => $ins['flash_id'],
            ':user_id'     => $ins['user_id'],
            ':description' => $ins['description'],
            ':created_at'  => $ins['created_at'],
        ]);
        $insertedCount++;
    }
}

// ── Build source-row lookup for O(1) display ─────────────────────────────────
$sourceRowsById = [];
foreach ($sourceRows as $sr) {
    $sourceRowsById[(int)$sr['id']] = $sr;
}

// ── HTML output ───────────────────────────────────────────────────────────────
$confirmUrl = htmlspecialchars(
    (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/app/actions/migrate_old_comments.php')
    . '?confirm=1',
    ENT_QUOTES,
    'UTF-8'
);
$dryRunUrl  = htmlspecialchars(
    isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/app/actions/migrate_old_comments.php',
    ENT_QUOTES,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="fi">
<head>
<meta charset="utf-8">
<title>Migrate old comments</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 860px; margin: 2rem auto; padding: 0 1rem; color: #1e293b; }
  h1 { font-size: 1.4rem; }
  .badge { display: inline-block; padding: .2em .55em; border-radius: 4px; font-size: .8rem; font-weight: 600; }
  .dry   { background: #fef9c3; color: #713f12; }
  .live  { background: #dcfce7; color: #14532d; }
  table  { border-collapse: collapse; width: 100%; margin-top: 1rem; font-size: .85rem; }
  th, td { border: 1px solid #e2e8f0; padding: .45rem .65rem; text-align: left; }
  th     { background: #f1f5f9; }
  tr:hover td { background: #f8fafc; }
  .actions { margin-top: 1.5rem; display: flex; gap: 1rem; }
  .btn { padding: .55rem 1.2rem; border-radius: 6px; border: none; cursor: pointer; font-size: .95rem; text-decoration: none; display: inline-block; }
  .btn-confirm { background: #2563eb; color: #fff; }
  .btn-confirm:hover { background: #1d4ed8; }
  .btn-dryrun  { background: #e2e8f0; color: #334155; }
  .btn-dryrun:hover  { background: #cbd5e1; }
  .summary { background: #f0f9ff; border-left: 4px solid #3b82f6; padding: .75rem 1rem; border-radius: 0 6px 6px 0; margin: 1rem 0; }
  code { background: #f1f5f9; padding: .1em .35em; border-radius: 3px; font-size: .85em; }
</style>
</head>
<body>
<h1>Kommenttien takautuva siirto <span class="badge <?= $dryRun ? 'dry' : 'live' ?>"><?= $dryRun ? 'ESIKATSELU (dry-run)' : 'SUORITETTU' ?></span></h1>

<div class="summary">
  <strong>Löydettiin migratoitavia rivejä:</strong> <?= count($toInsert) ?> kpl &nbsp;|&nbsp;
  <strong>Ohitettuja (ei viestiä / jo olemassa):</strong> <?= count($skipped) ?> kpl
  <?php if (!$dryRun): ?>
    &nbsp;|&nbsp; <strong>Lisätty:</strong> <?= $insertedCount ?> riviä
  <?php endif; ?>
</div>

<?php if (!empty($toInsert)): ?>
<h2 style="font-size:1.1rem;margin-top:1.5rem"><?= $dryRun ? 'Lisättäisiin' : 'Lisättiin' ?> (<?= count($toInsert) ?> kpl)</h2>
<table>
  <thead>
    <tr>
      <th>Lähde-ID</th>
      <th>flash_id</th>
      <th>Alkuperäinen event_type</th>
      <th>Uusi kuvaus (comment_added)</th>
      <th>Päivämäärä</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($toInsert as $ins): ?>
    <tr>
      <td><?= htmlspecialchars((string)$ins['source_id'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= htmlspecialchars((string)$ins['flash_id'], ENT_QUOTES, 'UTF-8') ?></td>
      <td>
        <?= htmlspecialchars($sourceRowsById[$ins['source_id']]['event_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>
      </td>
      <td><code><?= htmlspecialchars(mb_substr($ins['description'], 0, 120) . (mb_strlen($ins['description']) > 120 ? '…' : ''), ENT_QUOTES, 'UTF-8') ?></code></td>
      <td><?= htmlspecialchars($ins['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($dryRun): ?>
<div class="actions">
  <?php if (!empty($toInsert)): ?>
  <a href="<?= $confirmUrl ?>" class="btn btn-confirm">✔ Suorita migraatio (lisää <?= count($toInsert) ?> riviä)</a>
  <?php else: ?>
  <p>Ei migratoitavia rivejä.</p>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="actions">
  <a href="<?= $dryRunUrl ?>" class="btn btn-dryrun">← Palaa esikatseluun</a>
</div>
<?php endif; ?>

</body>
</html>