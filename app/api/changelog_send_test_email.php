<?php
/**
 * API Endpoint: Send test product-update email to the currently logged-in admin.
 *
 * This allows an admin to preview the product_update notification email
 * before sending it to all subscribers. The email is sent only to the
 * requesting admin and does NOT set email_sent_at on the entry.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ . '/../../assets/services/email_template.php';
require_once __DIR__ . '/../../assets/services/email_services.php';

global $config;
Database::setConfig($config['db'] ?? []);

$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sf_csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$updateId = (int)($_POST['update_id'] ?? 0);
if ($updateId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid update ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Fetch the changelog entry (published or draft)
    $stmt = $pdo->prepare("SELECT * FROM sf_changelog WHERE id = ? LIMIT 1");
    $stmt->execute([$updateId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Update not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Determine admin's email and language
    $adminEmail = (string)($user['email'] ?? '');
    if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Admin email not available'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $validLangs = ['fi', 'sv', 'en', 'it', 'el'];
    $lang = in_array((string)($user['ui_lang'] ?? ''), $validLangs, true) ? (string)$user['ui_lang'] : 'fi';

    // Parse translations
    $translations = [];
    if (!empty($entry['translations'])) {
        $decoded = json_decode((string)$entry['translations'], true);
        if (is_array($decoded)) {
            $translations = $decoded;
        }
    }

    // Resolve title and content for admin's language
    $resolveField = static function (array $trans, string $fieldLang, string $field): string {
        if (!empty($trans[$fieldLang][$field])) {
            return (string)$trans[$fieldLang][$field];
        }
        foreach (['en', 'fi'] as $fb) {
            if (!empty($trans[$fb][$field])) {
                return (string)$trans[$fb][$field];
            }
        }
        foreach ($trans as $t) {
            if (!empty($t[$field])) {
                return (string)$t[$field];
            }
        }
        return '';
    };

    $title   = $resolveField($translations, $lang, 'title');
    $content = $resolveField($translations, $lang, 'content');

    // Sanitize HTML content
    if (strip_tags($content) !== $content) {
        $allowed = '<p><br><strong><em><u><ol><ul><li><span>';
        $content = strip_tags($content, $allowed);
        $content = preg_replace('/<(\w+)(?:\s[^>]*)?(\/?)>/', '<$1$2>', $content) ?? $content;
    }

    // Resolve publish date
    $rawDate     = !empty($entry['publish_date']) ? (string)$entry['publish_date'] : (string)$entry['created_at'];
    $ts          = strtotime($rawDate);
    $displayDate = ($ts !== false) ? date('d.m.Y', $ts) : '';

    // Build updates URL
    $baseUrl     = rtrim($config['base_url'] ?? 'https://safetyflash.tapojarvi.online', '/');
    $updatesUrl  = $baseUrl . '/index.php?page=updates';

    $subjectTemplate = sf_email_term('email_product_update_subject', $lang);
    $subject = '[TEST] ' . str_replace('{title}', $title, $subjectTemplate);

    $emailData = [
        'title'        => $title,
        'content'      => $content,
        'publish_date' => $displayDate,
        'updates_url'  => $updatesUrl,
    ];

    $emailContent = sf_generate_update_email($emailData, $lang);

    // Send to admin only (test — does NOT touch email_sent_at)
    sf_send_email($subject, $emailContent['html'], $emailContent['text'], [$adminEmail], [], null, 'product_updates');

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('changelog_send_test_email error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}