<?php
declare(strict_types=1);

// app/cron/cleanup_old_jobs.php
// Ajetaan cronilla: 0 2 * * * php /path/to/cleanup_old_jobs.php
// Poistaa vanhat sf_jobs-rivit (completed/failed yli 7 päivää vanhat)
// ja orphan-tiedostot uploads/processes/-kansiosta.

require_once __DIR__ . '/../../config.php';

const JOBS_MAX_AGE_DAYS_COMPLETED = 7;
const JOBS_MAX_AGE_DAYS_ABANDONED = 30;

$pdo = Database::getInstance();

// 1. Delete completed/failed jobs older than 7 days
$stmtCompleted = $pdo->prepare(
    "DELETE FROM sf_jobs
     WHERE status IN ('completed', 'failed')
       AND updated_at < NOW() - INTERVAL ? DAY"
);
$stmtCompleted->execute([JOBS_MAX_AGE_DAYS_COMPLETED]);
$deletedCompleted = $stmtCompleted->rowCount();

// 2. Delete abandoned (still pending/in_progress) jobs older than 30 days
$stmtAbandoned = $pdo->prepare(
    "DELETE FROM sf_jobs
     WHERE status IN ('pending', 'in_progress')
       AND created_at < NOW() - INTERVAL ? DAY"
);
$stmtAbandoned->execute([JOBS_MAX_AGE_DAYS_ABANDONED]);
$deletedAbandoned = $stmtAbandoned->rowCount();

// 3. Clean up orphan temp files in uploads/processes/ older than 30 days
$processDir = __DIR__ . '/../../uploads/processes/';
$deletedFiles = 0;
$fileErrors   = 0;

if (is_dir($processDir)) {
    $maxFileAge = JOBS_MAX_AGE_DAYS_ABANDONED * 24 * 60 * 60;
    foreach (glob($processDir . '*') as $file) {
        if (!is_file($file)) {
            continue;
        }
        $fileTime = filemtime($file);
        if ($fileTime === false) {
            continue;
        }
        if ($fileTime < time() - $maxFileAge) {
            if (@unlink($file)) {
                $deletedFiles++;
                echo "Deleted orphan file: " . basename($file) . "\n";
            } else {
                $fileErrors++;
                error_log("cleanup_old_jobs: Failed to delete: " . basename($file));
            }
        }
    }
}

echo "Cleanup complete:\n";
echo "  Completed/failed job rows deleted (older than " . JOBS_MAX_AGE_DAYS_COMPLETED . " days): {$deletedCompleted}\n";
echo "  Abandoned job rows deleted (older than " . JOBS_MAX_AGE_DAYS_ABANDONED . " days): {$deletedAbandoned}\n";
echo "  Orphan process files deleted: {$deletedFiles} (errors: {$fileErrors})\n";

exit(0);
