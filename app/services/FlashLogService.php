<?php
/**
 * FlashLogService
 *
 * Centralized logging service for SafetyFlash.
 * Handles logging of edits, type changes, state changes, and field changes to safetyflash_logs table.
 *
 * @deprecated Use direct INSERT into safetyflash_logs combined with sf_audit_log() instead.
 *
 * @package SafetyFlash
 * @subpackage Services
 */

declare(strict_types=1);

if (!function_exists('sf_term')) {
    require_once __DIR__ . '/../../assets/lib/sf_terms.php';
}

class FlashLogService
{
    private function getLogTargetAndLang(PDO $pdo, int $flashId): array
    {
        $stmt = $pdo->prepare("SELECT id, translation_group_id, lang FROM sf_flashes WHERE id = ? LIMIT 1");
        $stmt->execute([$flashId]);
        $flash = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$flash) {
            return [
                'log_flash_id' => $flashId,
                'lang' => '',
            ];
        }

        return [
            'log_flash_id' => !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : (int)$flash['id'],
            'lang' => strtoupper((string)($flash['lang'] ?? '')),
        ];
    }

    public function logEdit(int $flashId, array $changes, int $userId, ?string $batchId = null): void
    {
        $pdo = Database::getInstance();

        $logContext = $this->getLogTargetAndLang($pdo, $flashId);
        $logFlashId = $logContext['log_flash_id'];
        $logLang = $logContext['lang'];

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

        $longFields = ['description', 'root_causes', 'actions', 'summary'];

        $changeDescriptions = [];

        foreach ($changes as $field => $change) {
            if (isset($change['old']) && isset($change['new'])) {
                $termKey = $fieldTermMap[$field] ?? $field;

                if (in_array($field, $longFields, true)) {
                    $changeDescriptions[] = $termKey;
                } else {
                    $changeDescriptions[] = "{$termKey}: {$change['old']} → {$change['new']}";
                }
            }
        }

        $description = !empty($changeDescriptions)
            ? implode("\n", $changeDescriptions)
            : 'log_flash_edited';

        if ($logLang !== '') {
            $description = "log_language_version: {$logLang}\n" . $description;
        }

        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
            VALUES (?, ?, 'edited', ?, ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description, $batchId]);
    }

    public function logTypeChange(int $flashId, string $oldType, string $newType, int $userId, ?string $batchId = null): void
    {
        $pdo = Database::getInstance();

        $logContext = $this->getLogTargetAndLang($pdo, $flashId);
        $logFlashId = $logContext['log_flash_id'];
        $logLang = $logContext['lang'];

        $description = "type: {$oldType} → {$newType}";

        if ($logLang !== '') {
            $description = "log_language_version: {$logLang}\n" . $description;
        }

        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
            VALUES (?, ?, 'type_changed', ?, ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description, $batchId]);
    }

    public function logStateChange(int $flashId, string $oldState, string $newState, int $userId, ?string $batchId = null): void
    {
        $pdo = Database::getInstance();

        $logContext = $this->getLogTargetAndLang($pdo, $flashId);
        $logFlashId = $logContext['log_flash_id'];
        $logLang = $logContext['lang'];

        $description = "log_state_changed: {$oldState} → {$newState}";

        if ($logLang !== '') {
            $description = "log_language_version: {$logLang}\n" . $description;
        }

        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
            VALUES (?, ?, 'state_changed', ?, ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description, $batchId]);
    }

    public function logFieldChange(int $flashId, string $fieldName, string $oldValue, string $newValue, int $userId, ?string $batchId = null): void
    {
        $pdo = Database::getInstance();

        $logContext = $this->getLogTargetAndLang($pdo, $flashId);
        $logFlashId = $logContext['log_flash_id'];
        $logLang = $logContext['lang'];

        $oldValueShort = mb_substr($oldValue, 0, 50);
        $newValueShort = mb_substr($newValue, 0, 50);

        if (mb_strlen($oldValue) > 50) {
            $oldValueShort .= '...';
        }

        if (mb_strlen($newValue) > 50) {
            $newValueShort .= '...';
        }

        $description = "{$fieldName}: \"{$oldValueShort}\" → \"{$newValueShort}\"";

        if ($logLang !== '') {
            $description = "log_language_version: {$logLang}\n" . $description;
        }

        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
            VALUES (?, ?, 'field_changed', ?, ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description, $batchId]);
    }
}