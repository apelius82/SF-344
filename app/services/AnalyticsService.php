<?php
// app/services/AnalyticsService.php
declare(strict_types=1);

final class AnalyticsService
{
    public static function track(PDO $pdo, array $data): void
{
    $eventType = trim((string)($data['event_type'] ?? ''));

    if ($eventType === '' || strlen($eventType) > 80) {
        return;
    }

    $userId = self::nullableInt($data['user_id'] ?? null);

    if ($userId !== null && self::isUserTrackingDisabled($pdo, $userId)) {
        return;
    }

        $metadata = $data['metadata'] ?? null;
        $metadataJson = null;

        if (is_array($metadata) && !empty($metadata)) {
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ipHash = $ip !== '' ? hash('sha256', $ip) : null;

        $stmt = $pdo->prepare("
            INSERT INTO sf_analytics_events (
                user_id,
                session_id,
                event_type,
                page,
                target_type,
                target_id,
                worksite_id,
                device_type,
                platform,
                browser,
                is_pwa,
                metadata_json,
                ip_hash,
                user_agent,
                created_at
            ) VALUES (
                :user_id,
                :session_id,
                :event_type,
                :page,
                :target_type,
                :target_id,
                :worksite_id,
                :device_type,
                :platform,
                :browser,
                :is_pwa,
                :metadata_json,
                :ip_hash,
                :user_agent,
                NOW()
            )
        ");

        $stmt->execute([
            ':user_id' => self::nullableInt($data['user_id'] ?? null),
            ':session_id' => self::nullableString($data['session_id'] ?? null, 128),
            ':event_type' => self::limitString($eventType, 80),
            ':page' => self::nullableString($data['page'] ?? null, 80),
            ':target_type' => self::nullableString($data['target_type'] ?? null, 80),
            ':target_id' => self::nullableInt($data['target_id'] ?? null),
            ':worksite_id' => self::nullableInt($data['worksite_id'] ?? null),
            ':device_type' => self::nullableString($data['device_type'] ?? null, 40),
            ':platform' => self::nullableString($data['platform'] ?? null, 80),
            ':browser' => self::nullableString($data['browser'] ?? null, 80),
            ':is_pwa' => !empty($data['is_pwa']) ? 1 : 0,
            ':metadata_json' => $metadataJson,
            ':ip_hash' => $ipHash,
            ':user_agent' => self::nullableString($_SERVER['HTTP_USER_AGENT'] ?? null, 500),
        ]);
    }

private static function nullableInt($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (int)$value : null;
}

private static function isUserTrackingDisabled(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT analytics_tracking_enabled
            FROM sf_users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);

        $value = $stmt->fetchColumn();

        return $value !== false && (int)$value === 0;
    } catch (Throwable $e) {
        error_log('Analytics tracking preference check failed: ' . $e->getMessage());
        return false;
    }
}

    private static function nullableString($value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        return self::limitString($value, $maxLength);
    }

    private static function limitString(string $value, int $maxLength): string
    {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
}