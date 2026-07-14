<?php
declare(strict_types=1);

final class UserEventService
{
    public static function rootFlashId(array $flash): int
    {
        return !empty($flash['translation_group_id'])
            ? (int)$flash['translation_group_id']
            : (int)$flash['id'];
    }

    public static function createEvent(
        PDO $pdo,
        int $userId,
        int $flashId,
        string $eventType,
        string $eventGroup,
        ?int $sourceLogId = null
    ): void {
        if ($userId <= 0 || $flashId <= 0 || $eventType === '') {
            return;
        }

        $stmtFlash = $pdo->prepare("
            SELECT id, translation_group_id, title
            FROM sf_flashes
            WHERE id = :id OR translation_group_id = :id2
            ORDER BY CASE WHEN id = :id3 THEN 0 ELSE 1 END, id ASC
            LIMIT 1
        ");
        $stmtFlash->execute([
            ':id' => $flashId,
            ':id2' => $flashId,
            ':id3' => $flashId,
        ]);

        $flash = $stmtFlash->fetch(PDO::FETCH_ASSOC);
        if (!$flash) {
            return;
        }

        $rootFlashId = self::rootFlashId($flash);
        $title = trim((string)($flash['title'] ?? 'SafetyFlash'));
        if ($title === '') {
            $title = 'SafetyFlash';
        }

        $sourcePart = $sourceLogId ? (string)$sourceLogId : 'latest';
        $eventKey = $eventType . ':' . $rootFlashId . ':' . $sourcePart;
        $url = 'index.php?page=view&id=' . $rootFlashId;

        $stmt = $pdo->prepare("
            INSERT INTO sf_user_events
                (user_id, flash_id, event_type, event_group, event_key, source_log_id, title, url, is_read, created_at, read_at)
            VALUES
                (:user_id, :flash_id, :event_type, :event_group, :event_key, :source_log_id, :title, :url, 0, NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                is_read = 0,
                read_at = NULL,
                created_at = NOW(),
                title = VALUES(title),
                url = VALUES(url)
        ");

        $stmt->execute([
            ':user_id' => $userId,
            ':flash_id' => $rootFlashId,
            ':event_type' => $eventType,
            ':event_group' => $eventGroup,
            ':event_key' => $eventKey,
            ':source_log_id' => $sourceLogId,
            ':title' => $title,
            ':url' => $url,
        ]);
    }

    public static function createEventsForUsers(
        PDO $pdo,
        array $userIds,
        int $flashId,
        string $eventType,
        string $eventGroup,
        ?int $sourceLogId = null,
        ?int $excludeUserId = null
    ): void {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static function (int $id) use ($excludeUserId): bool {
            if ($id <= 0) {
                return false;
            }

            if ($excludeUserId !== null && $id === $excludeUserId) {
                return false;
            }

            return true;
        })));

        foreach ($userIds as $userId) {
            self::createEvent($pdo, $userId, $flashId, $eventType, $eventGroup, $sourceLogId);
        }
    }

    public static function createCommentEvents(
        PDO $pdo,
        int $logFlashId,
        int $commentLogId,
        ?int $fromUserId,
        ?int $flashCreatorId,
        ?int $parentCommentId = null
    ): void {
        $recipientIds = [];

        if (!empty($flashCreatorId)) {
            $recipientIds[] = (int)$flashCreatorId;
        }

        if (!empty($parentCommentId)) {
            $stmtParent = $pdo->prepare("
                SELECT user_id
                FROM safetyflash_logs
                WHERE id = ?
                LIMIT 1
            ");
            $stmtParent->execute([(int)$parentCommentId]);
            $parentUserId = (int)$stmtParent->fetchColumn();

            if ($parentUserId > 0) {
                $recipientIds[] = $parentUserId;
            }
        }

        $stmtSubscribers = $pdo->prepare("
            SELECT user_id
            FROM sf_comment_subscriptions
            WHERE flash_id = ?
              AND is_enabled = 1
        ");
        $stmtSubscribers->execute([$logFlashId]);
        $recipientIds = array_merge($recipientIds, array_map('intval', $stmtSubscribers->fetchAll(PDO::FETCH_COLUMN)));

        $stmtCommenters = $pdo->prepare("
            SELECT DISTINCT user_id
            FROM safetyflash_logs
            WHERE flash_id = ?
              AND event_type = 'comment_added'
              AND user_id IS NOT NULL
        ");
        $stmtCommenters->execute([$logFlashId]);
        $recipientIds = array_merge($recipientIds, array_map('intval', $stmtCommenters->fetchAll(PDO::FETCH_COLUMN)));

        self::createEventsForUsers(
            $pdo,
            $recipientIds,
            $logFlashId,
            'comment_added',
            'info',
            $commentLogId,
            $fromUserId
        );
    }

    public static function createRoleEvents(
        PDO $pdo,
        int $roleId,
        int $flashId,
        string $eventType,
        string $eventGroup,
        ?int $excludeUserId = null
    ): void {
        $userIds = [];

        $stmtPrimary = $pdo->prepare("
            SELECT id
            FROM sf_users
            WHERE role_id = ?
              AND is_active = 1
        ");
        $stmtPrimary->execute([$roleId]);
        $userIds = array_merge($userIds, array_map('intval', $stmtPrimary->fetchAll(PDO::FETCH_COLUMN)));

        try {
            $stmtExtra = $pdo->prepare("
                SELECT u.id
                FROM sf_user_roles ur
                JOIN sf_users u ON u.id = ur.user_id
                WHERE ur.role_id = ?
                  AND u.is_active = 1
            ");
            $stmtExtra->execute([$roleId]);
            $userIds = array_merge($userIds, array_map('intval', $stmtExtra->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
            error_log('UserEventService::createRoleEvents extra roles failed: ' . $e->getMessage());
        }

        self::createEventsForUsers(
            $pdo,
            $userIds,
            $flashId,
            $eventType,
            $eventGroup,
            null,
            $excludeUserId
        );
    }

    public static function createReturnedToCreatorEvent(PDO $pdo, int $flashId): void
    {
        $stmt = $pdo->prepare("
            SELECT id, translation_group_id, created_by
            FROM sf_flashes
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$flashId]);
        $flash = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$flash || empty($flash['created_by'])) {
            return;
        }

        self::createEvent(
            $pdo,
            (int)$flash['created_by'],
            self::rootFlashId($flash),
            'returned_to_creator',
            'action_required'
        );
    }

    public static function createPublishedParticipantEvents(
        PDO $pdo,
        int $flashId,
        ?int $publisherUserId = null
    ): void {
        if ($flashId <= 0) {
            return;
        }

        $stmtGroup = $pdo->prepare("
            SELECT id, translation_group_id
            FROM sf_flashes
            WHERE id = ?
            LIMIT 1
        ");
        $stmtGroup->execute([$flashId]);
        $flash = $stmtGroup->fetch(PDO::FETCH_ASSOC);

        if (!$flash) {
            return;
        }

        $rootFlashId = self::rootFlashId($flash);

        $stmtFlashes = $pdo->prepare("
            SELECT id, created_by
            FROM sf_flashes
            WHERE id = :root_id
               OR translation_group_id = :root_group_id
        ");
        $stmtFlashes->execute([
            ':root_id' => $rootFlashId,
            ':root_group_id' => $rootFlashId,
        ]);

        $flashIds = [];
        $recipientIds = [];

        foreach ($stmtFlashes->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rowFlashId = (int)($row['id'] ?? 0);
            if ($rowFlashId > 0) {
                $flashIds[] = $rowFlashId;
            }

            $createdBy = (int)($row['created_by'] ?? 0);
            if ($createdBy > 0) {
                $recipientIds[] = $createdBy;
            }
        }

        $flashIds[] = $rootFlashId;
        $flashIds = array_values(array_unique(array_filter(array_map('intval', $flashIds))));

        if (empty($flashIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($flashIds), '?'));

        $stmtLogUsers = $pdo->prepare("
            SELECT DISTINCT user_id
            FROM safetyflash_logs
            WHERE flash_id IN ($placeholders)
              AND user_id IS NOT NULL
              AND user_id > 0
        ");
        $stmtLogUsers->execute($flashIds);
        $recipientIds = array_merge($recipientIds, array_map('intval', $stmtLogUsers->fetchAll(PDO::FETCH_COLUMN)));

        $stmtSupervisors = $pdo->prepare("
            SELECT DISTINCT user_id
            FROM flash_supervisors
            WHERE flash_id IN ($placeholders)
              AND user_id IS NOT NULL
              AND user_id > 0
        ");
        $stmtSupervisors->execute($flashIds);
        $recipientIds = array_merge($recipientIds, array_map('intval', $stmtSupervisors->fetchAll(PDO::FETCH_COLUMN)));

        $stmtSubscribers = $pdo->prepare("
            SELECT DISTINCT user_id
            FROM sf_comment_subscriptions
            WHERE flash_id IN ($placeholders)
              AND is_enabled = 1
              AND user_id IS NOT NULL
              AND user_id > 0
        ");
        $stmtSubscribers->execute($flashIds);
        $recipientIds = array_merge($recipientIds, array_map('intval', $stmtSubscribers->fetchAll(PDO::FETCH_COLUMN)));

        try {
            $stmtLanguageReviewers = $pdo->prepare("
                SELECT DISTINCT user_id
                FROM sf_flash_language_reviewers
                WHERE flash_id IN ($placeholders)
                  AND user_id IS NOT NULL
                  AND user_id > 0
            ");
            $stmtLanguageReviewers->execute($flashIds);
            $recipientIds = array_merge($recipientIds, array_map('intval', $stmtLanguageReviewers->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
            error_log('UserEventService::createPublishedParticipantEvents language reviewers failed: ' . $e->getMessage());
        }

        self::createEventsForUsers(
            $pdo,
            $recipientIds,
            $rootFlashId,
            'flash_published',
            'info',
            null,
            $publisherUserId
        );
    }

    public static function markFlashEventsRead(PDO $pdo, int $userId, int $flashId): void
    {
        if ($userId <= 0 || $flashId <= 0) {
            return;
        }

        $stmt = $pdo->prepare("
            UPDATE sf_user_events
            SET is_read = 1,
                read_at = NOW()
            WHERE user_id = :user_id
              AND flash_id = :flash_id
              AND is_read = 0
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':flash_id' => $flashId,
        ]);
    }
}