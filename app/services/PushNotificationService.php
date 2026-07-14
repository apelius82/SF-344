<?php
// app/services/PushNotificationService.php
declare(strict_types=1);

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ . '/AnalyticsService.php';

class PushNotificationService
{
public static function sendToUser(int $userId, string $title, string $body, string $url = '', string $category = ''): array
{
    $pdo = Database::getInstance();

    if ($category !== '' && !self::shouldSendPush($pdo, $userId, $category)) {
        return [
            'ok' => true,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 1,
            'error' => null,
        ];
    }
        $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';

        if (!file_exists($autoloadPath)) {
            return [
                'ok' => false,
                'sent' => 0,
                'failed' => 0,
                'error' => 'Composer autoload missing',
            ];
        }

        require_once $autoloadPath;

        $publicKey = (string)(getenv('VAPID_PUBLIC_KEY') ?: '');
        $privateKey = (string)(getenv('VAPID_PRIVATE_KEY') ?: '');
        $subject = (string)(getenv('VAPID_SUBJECT') ?: '');

        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            return [
                'ok' => false,
                'sent' => 0,
                'failed' => 0,
                'error' => 'VAPID configuration missing',
            ];
        }

        $stmt = $pdo->prepare("
            SELECT id, endpoint, p256dh, auth
            FROM sf_push_subscriptions
            WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$userId]);

        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$subscriptions) {
            return [
                'ok' => true,
                'sent' => 0,
                'failed' => 0,
                'error' => null,
            ];
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);

        $notificationId = bin2hex(random_bytes(16));
        $flashId = self::extractFlashIdFromUrl($url);

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => '/assets/img/icons/pwa-icon-192.png',
            'badge' => '/assets/img/icons/pwa-icon-192.png',
            'notification_id' => $notificationId,
            'user_id' => $userId,
            'flash_id' => $flashId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sent = 0;
        $failed = 0;

        foreach ($subscriptions as $row) {
            $subscription = Subscription::create([
                'endpoint' => (string)$row['endpoint'],
                'keys' => [
                    'p256dh' => (string)$row['p256dh'],
                    'auth' => (string)$row['auth'],
                ],
            ]);

            $webPush->queueNotification($subscription, $payload);
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = (string)$report->getRequest()->getUri();
            $endpointHash = hash('sha256', $endpoint);

            if ($report->isSuccess()) {
                $sent++;

                AnalyticsService::track($pdo, [
                    'user_id' => $userId,
                    'session_id' => 'push_' . $notificationId,
                    'event_type' => 'push_sent',
                    'page' => 'push',
                    'target_type' => $flashId > 0 ? 'flash' : 'push',
                    'target_id' => $flashId > 0 ? $flashId : null,
                    'device_type' => 'unknown',
                    'platform' => 'push',
                    'browser' => 'push',
                    'is_pwa' => 1,
                    'metadata' => [
                        'notification_id' => $notificationId,
                        'subscription_id' => isset($row['id']) ? (int)$row['id'] : null,
                        'url' => $url,
                        'title' => $title,
                    ],
                ]);

                continue;
            }

            $failed++;
            $reason = substr((string)$report->getReason(), 0, 1000);

            $update = $pdo->prepare("
                UPDATE sf_push_subscriptions
                SET
                    is_active = CASE
                        WHEN :expired = 1 THEN 0
                        ELSE is_active
                    END,
                    last_error = :last_error,
                    updated_at = NOW()
                WHERE endpoint_hash = :endpoint_hash
            ");

            $update->execute([
                ':expired' => $report->isSubscriptionExpired() ? 1 : 0,
                ':last_error' => $reason,
                ':endpoint_hash' => $endpointHash,
            ]);
        }

        return [
            'ok' => true,
            'sent' => $sent,
            'failed' => $failed,
            'error' => null,
        ];
    }

    public static function sendWorkflowToUsers(PDO $pdo, array $userIds, int $flashId, string $titleTerm, string $bodyTerm, array $context = [], string $category = ''): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return [
                'ok' => true,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $flash = self::getFlashSummary($pdo, $flashId);
        if (!$flash) {
            return [
                'ok' => false,
                'sent' => 0,
                'failed' => 0,
                'error' => 'Flash not found',
            ];
        }

        $baseUrl = self::getBaseUrl();
        $url = $baseUrl . '/index.php?page=view&id=' . $flashId;

        $totalSent = 0;
        $totalFailed = 0;

        foreach ($userIds as $userId) {
            $lang = self::getUserLang($pdo, $userId);

            $title = self::formatMessage(sf_term($titleTerm, $lang), $flash, $lang, $context);
            $body = self::formatMessage(sf_term($bodyTerm, $lang), $flash, $lang, $context);

            $result = self::sendToUser($userId, $title, $body, $url, $category);

            $totalSent += (int)($result['sent'] ?? 0);
            $totalFailed += (int)($result['failed'] ?? 0);
        }

        return [
            'ok' => true,
            'sent' => $totalSent,
            'failed' => $totalFailed,
        ];
    }

    public static function sendWorkflowToRole(PDO $pdo, int $roleId, int $flashId, string $titleTerm, string $bodyTerm, string $category = ''): array
    {
        $userIds = self::getUserIdsByRole($pdo, $roleId);

return self::sendWorkflowToUsers(
    $pdo,
    $userIds,
    $flashId,
    $titleTerm,
    $bodyTerm,
    [],
    $category
);
    }
    public static function sendWorkflowToEmails(PDO $pdo, array $emails, int $flashId, string $titleTerm, string $bodyTerm, array $context = [], string $category = ''): array
    {
        $emails = array_values(array_unique(array_filter(array_map(static function ($email) {
            return mb_strtolower(trim((string)$email));
        }, $emails))));

        if (!$emails) {
            return [
                'ok' => true,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($emails), '?'));

        $stmt = $pdo->prepare("
            SELECT id
            FROM sf_users
            WHERE LOWER(email) IN ($placeholders)
              AND is_active = 1
        ");
        $stmt->execute($emails);

        $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

return self::sendWorkflowToUsers(
    $pdo,
    $userIds,
    $flashId,
    $titleTerm,
    $bodyTerm,
    $context,
    $category
);
    }
    public static function sendReturnedToCreator(PDO $pdo, int $flashId): array
    {
        $stmt = $pdo->prepare("
            SELECT created_by
            FROM sf_flashes
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$flashId]);
        $creatorId = (int)$stmt->fetchColumn();

        if ($creatorId <= 0) {
            return [
                'ok' => true,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        return self::sendWorkflowToUsers(
            $pdo,
            [$creatorId],
            $flashId,
            'push_returned_title',
            'push_returned_body'
        );
    }
private static function shouldSendPush(PDO $pdo, int $userId, string $category): bool
{
    try {
        if ($category === '') {
            return true;
        }

        $stmt = $pdo->prepare("
            SELECT is_active
            FROM sf_users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        if ((int)$row['is_active'] !== 1) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT enabled
            FROM sf_user_push_preferences
            WHERE user_id = ? AND category = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $category]);

        $pref = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pref === false) {
            return true;
        }

        return (int)$pref['enabled'] === 1;
    } catch (Throwable $e) {
        if (function_exists('sf_app_log')) {
            sf_app_log('PushNotificationService::shouldSendPush ERROR: ' . $e->getMessage(), LOG_LEVEL_WARNING);
        }

        return true;
    }
}
    private static function getUserIdsByRole(PDO $pdo, int $roleId): array
    {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id
            FROM sf_users u
            WHERE u.is_active = 1
              AND (
                  u.role_id = :role_id
                  OR u.id IN (
                      SELECT uar.user_id
                      FROM user_additional_roles uar
                      WHERE uar.role_id = :role_id2
                  )
              )
        ");
        $stmt->execute([
            ':role_id' => $roleId,
            ':role_id2' => $roleId,
        ]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function getFlashSummary(PDO $pdo, int $flashId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT id, type, title, site
            FROM sf_flashes
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$flashId]);

        $flash = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$flash) {
            return null;
        }

        return $flash;
    }

    private static function getUserLang(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare("
            SELECT ui_lang
            FROM sf_users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);

        $lang = (string)($stmt->fetchColumn() ?: 'fi');
        return in_array($lang, ['fi', 'sv', 'en', 'it', 'el'], true) ? $lang : 'fi';
    }

    private static function formatMessage(string $template, array $flash, string $lang, array $context = []): string
    {
        $type = self::getTypeLabel((string)($flash['type'] ?? ''), $lang);
        $title = trim((string)($flash['title'] ?? ''));
        $worksite = trim((string)($flash['site'] ?? ''));

        if ($worksite === '') {
            $worksite = sf_term('unknown', $lang);
            if ($worksite === 'unknown') {
                $worksite = '-';
            }
        }

        return strtr($template, [
            '{id}' => (string)($flash['id'] ?? ''),
            '{type}' => $type,
            '{title}' => $title,
            '{worksite}' => $worksite,
            '{from}' => trim((string)($context['from'] ?? '')),
            '{message}' => trim((string)($context['message'] ?? '')),
        ]);
    }

    private static function getTypeLabel(string $type, string $lang): string
    {
        if ($type === 'red') {
            return sf_term('first_release', $lang);
        }

        if ($type === 'yellow') {
            return sf_term('dangerous_situation', $lang);
        }

        if ($type === 'green') {
            return sf_term('investigation_report', $lang);
        }

        return 'SafetyFlash';
    }

    private static function getBaseUrl(): string
    {
        global $config;

        if (isset($config['base_url']) && trim((string)$config['base_url']) !== '') {
            return rtrim((string)$config['base_url'], '/');
        }

        return 'https://safetyflash.tapojarvi.online';
    }

    private static function extractFlashIdFromUrl(string $url): int
    {
        if ($url === '') {
            return 0;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['query'])) {
            return 0;
        }

        parse_str((string)$parts['query'], $query);

        return isset($query['id']) && is_numeric($query['id']) ? (int)$query['id'] : 0;
    }
}