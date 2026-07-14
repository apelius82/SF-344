<?php
// app/services/email_services.php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../app/includes/log_app.php'; 
require_once __DIR__ . '/../lib/phpmailer/Exception.php';
require_once __DIR__ . '/../lib/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../lib/phpmailer/SMTP.php';
require_once __DIR__ . '/email_template.php';
require_once __DIR__ . '/render_services.php';
require_once __DIR__ . '/../../app/services/PushNotificationService.php';

/**
 * Roolien ID:t sf_roles-taulussa.
 * Nämä vastaavat tietokannan arvoja:
 * 1 = Pääkäyttäjä, 2 = Kirjoittaja, 3 = Turvatiimi, 4 = Viestintä
 * 5 = Jakelu (Suomi), 6 = Jakelu (Ruotsi), 7 = Jakelu (Englanti)
 * 8 = Jakelu (Italia), 9 = Jakelu (Kreikka)
 */
const SF_ROLE_ID_ADMIN        = 1;
const SF_ROLE_ID_AUTHOR       = 2; // Kirjoittaja
const SF_ROLE_ID_SAFETY_TEAM  = 3; // Turvatiimi
const SF_ROLE_ID_COMMS        = 4; // Viestintä
const SF_ROLE_ID_DISTRIBUTION_FI = 5; // SafetyFlash-jakelu (Suomi)
const SF_ROLE_ID_DISTRIBUTION_SV = 6; // SafetyFlash-jakelu (Ruotsi)
const SF_ROLE_ID_DISTRIBUTION_EN = 7; // SafetyFlash-jakelu (Englanti)
const SF_ROLE_ID_DISTRIBUTION_IT = 8; // SafetyFlash-jakelu (Italia)
const SF_ROLE_ID_DISTRIBUTION_EL = 9; // SafetyFlash-jakelu (Kreikka)

// Legacy constant for backward compatibility
const SF_ROLE_ID_DISTRIBUTION = 5;

/**
 * Hae yksittäinen asetus sf_settings-taulusta.
 * Jos asetusta ei ole, palautetaan oletusarvo.
 */
function sf_get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM sf_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $default;
    }
    return (string)$row['setting_value'];
}

/**
 * Returns true for categories that must always be delivered regardless of user preferences.
 * Mandatory categories bypass the notification preference check entirely.
 *
 * @param string $category Notification category key
 * @return bool
 */
function sf_is_mandatory_category(string $category): bool
{
    return in_array($category, [
        'system_welcome',
        'system_password_reset',
        'system_security',
    ], true);
}

/**
 * Get a user's notification preference for a specific category.
 * Returns true (enabled) if no row exists in the preferences table (opt-out model).
 *
 * @param PDO    $pdo      Database connection
 * @param int    $userId   User ID
 * @param string $category Notification category key
 * @return bool
 */
function sf_get_user_notification_pref(PDO $pdo, int $userId, string $category): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT enabled
            FROM sf_user_notification_preferences
            WHERE user_id = ? AND category = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $category]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return true; // No row → default enabled
        }
        return (bool)$row['enabled'];
    } catch (Throwable $e) {
        sf_app_log('sf_get_user_notification_pref ERROR: ' . $e->getMessage());
        return true; // Fail open
    }
}

/**
 * Check if a user has email notifications enabled for the given category.
 *
 * - Mandatory categories (system_welcome, system_password_reset, system_security)
 *   always return true regardless of user settings.
 * - Inactive users are always blocked (even for non-mandatory categories).
 * - External recipients (not in the database) are always allowed.
 * - When $category is provided, the sf_user_notification_preferences table is
 *   consulted.  When empty, falls back to the legacy email_notifications_enabled
 *   column for backward compatibility.
 *
 * @param PDO    $pdo      Database connection
 * @param string $email    Recipient email address
 * @param string $category Notification category key (empty = legacy fallback)
 * @return bool True if the email should be sent
 */
function sf_should_send_email(PDO $pdo, string $email, string $category = ''): bool
{
    try {
        // Mandatory categories bypass all preference checks
        if ($category !== '' && sf_is_mandatory_category($category)) {
            sf_app_log("sf_should_send_email: Mandatory category={$category}, bypassing checks for {$email}");
            return true;
        }

        // Look up the user
        $stmt = $pdo->prepare("
            SELECT id, is_active, email_notifications_enabled
            FROM sf_users
            WHERE LOWER(email) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // External recipient (not in database) → allow
        if ($row === false) {
            sf_app_log("sf_should_send_email: External recipient (not in database), allowing send: {$email}");
            return true;
        }

        // Inactive user → block
        if ((int)$row['is_active'] !== 1) {
            sf_app_log("sf_should_send_email: User INACTIVE (is_active=0), blocking send: {$email}");
            return false;
        }

        $userId = (int)$row['id'];

        // Category-based preference check (new table)
        if ($category !== '') {
            $result = sf_get_user_notification_pref($pdo, $userId, $category);
            sf_app_log("sf_should_send_email: user={$email}, category={$category}, result=" . ($result ? 'YES' : 'NO'));
            return $result;
        }

        // Fallback: no category → use legacy email_notifications_enabled column
        $shouldSend = (bool)$row['email_notifications_enabled'];
        sf_app_log("sf_should_send_email: Active user email={$email}, notifications_enabled=" . ($shouldSend ? 'YES' : 'NO'));
        return $shouldSend;

    } catch (Throwable $e) {
        sf_app_log('sf_should_send_email ERROR: ' . $e->getMessage());
        // On error, default to sending (fail open)
        return true;
    }
}

/**
 * Log email attempt to database.
 * 
 * @param PDO $pdo Database connection
 * @param int|null $flashId SafetyFlash ID (null if not related to a flash)
 * @param string $recipient Recipient email address
 * @param string $subject Email subject
 * @param string $status 'sent', 'failed', or 'skipped'
 * @param string|null $skipReason Reason for skipping (if status is 'skipped')
 * @param string|null $errorMessage Error message (if status is 'failed')
 * @return void
 */
function sf_log_email(PDO $pdo, ?int $flashId, string $recipient, string $subject, string $status, ?string $skipReason = null, ?string $errorMessage = null): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sf_email_logs (flash_id, recipient_email, subject, status, skip_reason, error_message)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$flashId, $recipient, $subject, $status, $skipReason, $errorMessage]);
    } catch (Throwable $e) {
        sf_app_log('sf_log_email ERROR: ' . $e->getMessage());
    }
}

/**
 * Lähettää sähköpostin käyttäen SMTP-asetuksia (PHPMailer).
 * Supports both plain text and HTML/multipart emails.
 *
 * @param string   $subject      Sähköpostin otsikko
 * @param string   $htmlBody     HTML sisältö (if empty, uses plain text only)
 * @param string   $textBody     Plain text sisältö
 * @param string[] $recipients   Vastaanottajat
 * @param array[]  $attachments  Optional array of attachment paths. Each element should have 'path' and optionally 'name' keys
 * @param int|null $flashId      Optional SafetyFlash ID for logging
 * @param string   $category     Notification category key for preference filtering (empty = legacy fallback)
 */
function sf_send_email(string $subject, string $htmlBody, string $textBody, array $recipients, array $attachments = [], ?int $flashId = null, string $category = ''): void
{
    sf_app_log('sf_send_email: CALLED, recipients=' . implode(',', $recipients));

    if (empty($recipients)) {
        sf_app_log('sf_send_email: EMPTY RECIPIENTS, abort');
        return;
    }

    // Luodaan oma PDO-yhteys asetuksia varten (ei käytetä sf_get_pdo:a)
    try {
        require __DIR__ . '/../../config.php';
        $pdo = new PDO(
            "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
            $config['db']['user'],
            $config['db']['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        sf_app_log('sf_send_email: PDO INIT ERROR: ' . $e->getMessage());
        return;
    }

    // Filter recipients based on email notification preferences
    $allowedRecipients = [];
    $skippedRecipients = [];
    
    foreach ($recipients as $recipient) {
        $recipient = trim($recipient);
        if ($recipient === '') {
            continue;
        }
        
        if (sf_should_send_email($pdo, $recipient, $category)) {
            $allowedRecipients[] = $recipient;
        } else {
            $skippedRecipients[] = $recipient;
            // Log skipped email
            sf_log_email($pdo, $flashId, $recipient, $subject, 'skipped', 'User has disabled email notifications', null);
            sf_app_log("sf_send_email: SKIPPED recipient=$recipient (notifications disabled)");
        }
    }
    
    // If no recipients left after filtering, return
    if (empty($allowedRecipients)) {
        sf_app_log('sf_send_email: ALL RECIPIENTS FILTERED OUT, abort');
        return;
    }

    // Luetaan SMTP-asetukset tietokannasta
    $host       = sf_get_setting($pdo, 'smtp_host', 'localhost');
    $port       = (int) (sf_get_setting($pdo, 'smtp_port', '25'));
    $encryption = sf_get_setting($pdo, 'smtp_encryption', 'none'); // tls/ssl/none
    $username   = sf_get_setting($pdo, 'smtp_username', '');
    $password   = sf_get_setting($pdo, 'smtp_password', '');
    $fromEmail  = sf_get_setting($pdo, 'smtp_from_email', 'no-reply@tapojarvi.online');
    $fromName   = sf_get_setting($pdo, 'smtp_from_name', 'Safetyflash');

    $mail = new PHPMailer(true);

    try {
        // Palvelinasetukset
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = ($username !== '' || $password !== '');
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false; // ei salausta
        }
        if ($mail->SMTPAuth) {
            $mail->Username = $username;
            $mail->Password = $password;
        }

        // UTF-8
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        // From
        $mail->setFrom($fromEmail, $fromName);

        // Vastaanottajat - only add allowed recipients
        foreach ($allowedRecipients as $to) {
            $mail->addAddress($to);
        }

        // Sisältö - Check if HTML is provided
        if (!empty($htmlBody)) {
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;      // HTML version
            $mail->AltBody = $textBody;      // Plain text alternative
        } else {
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body    = $textBody;      // Plain text only (backward compatible)
        }

        // Add attachments if provided
        foreach ($attachments as $attachment) {
            if (isset($attachment['path']) && file_exists($attachment['path'])) {
                $name = $attachment['name'] ?? basename($attachment['path']);
                $mail->addAttachment($attachment['path'], $name);
            }
        }

        $mail->send();
        sf_app_log('sf_send_email: MAIL SENT OK');
        
        // Log successful sends for each recipient
        foreach ($allowedRecipients as $recipient) {
            sf_log_email($pdo, $flashId, $recipient, $subject, 'sent', null, null);
        }
    } catch (Exception $e) {
        $errorMsg = $mail->ErrorInfo;
        sf_app_log('sf_send_email: SMTP ERROR: ' . $errorMsg);
        
        // Log failed sends for each recipient
        foreach ($allowedRecipients as $recipient) {
            sf_log_email($pdo, $flashId, $recipient, $subject, 'failed', null, $errorMsg);
        }
    }
}

/**
 * Build HTML and plain text email from template
 * 
 * @param array $data Email data for template
 * @param string $lang Language code (fi, sv, en, it, el)
 * @return array ['html' => string, 'text' => string]
 */
function sf_build_email_html(array $data, string $lang = 'fi'): array
{
    return [
        'html' => sf_generate_email_html($data, $lang),
        'text' => sf_generate_email_text($data, $lang),
    ];
}

/**
 * Get user's preferred language from database
 * 
 * @param PDO $pdo Database connection
 * @param string $email User email
 * @return string Language code (fi, sv, en, it, el)
 */
function sf_get_user_language(PDO $pdo, string $email): string
{
    $stmt = $pdo->prepare("SELECT ui_lang FROM sf_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $lang = $row['ui_lang'] ?? 'fi';
    
    // Validate language code
    $validLangs = ['fi', 'sv', 'en', 'it', 'el'];
    if (!in_array($lang, $validLangs, true)) {
        $lang = 'fi';
    }
    
    return $lang;
}

/**
 * Get flash details for email
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId SafetyFlash ID
 * @return array|null Flash details or null if not found
 */
function sf_get_flash_details(PDO $pdo, int $flashId): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT 
                f.id,
                f.type,
                f.title,
                f.site as worksite,
                f.preview_filename,
                f.translation_group_id,
                f.state
            FROM sf_flashes f
            WHERE f.id = ? 
            LIMIT 1
        ");
        $stmt->execute([$flashId]);
        $flash = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$flash) {
            error_log("sf_get_flash_details: Flash {$flashId} NOT FOUND in database");
            sf_app_log("sf_get_flash_details: Flash {$flashId} NOT FOUND");
        } else {
            error_log("sf_get_flash_details: Flash {$flashId} FOUND - type={$flash['type']}, state={$flash['state']}");
        }
        
        return $flash ?: null;
        
    } catch (Throwable $e) {
        error_log("sf_get_flash_details ERROR for flash {$flashId}: " . $e->getMessage());
        sf_app_log("sf_get_flash_details ERROR for flash {$flashId}: " . $e->getMessage());
        return null;
    }
}

/**
 * Build SafetyFlash URL
 * 
 * @param int $flashId SafetyFlash ID
 * @return string Full URL to SafetyFlash
 */
function sf_build_flash_url(int $flashId): string
{
    // Try to get base URL from config
    try {
        require __DIR__ . '/../../config.php';
        $baseUrl = 'https://safetyflash.tapojarvi.online';
    } catch (Throwable $e) {
        // Fallback if config can't be loaded
        $baseUrl = 'https://safetyflash.tapojarvi.online';
    }
    
    return $baseUrl . '/index.php?page=view&id=' . $flashId;
}

/**
 * Build preview attachment array for email
 * 
 * @param string|null $previewFilename Preview filename from database
 * @param int $flashId SafetyFlash ID for attachment naming
 * @return array Empty array if no preview, or array with attachment data
 */
function sf_build_preview_attachment(?string $previewFilename, int $flashId): array
{
    if (empty($previewFilename)) {
        return [];
    }
    
    $previewPath = __DIR__ . '/../../uploads/previews/' . $previewFilename;
    if (!file_exists($previewPath)) {
        return [];
    }
    
    return [[
        'path' => $previewPath,
        'name' => 'safetyflash_' . $flashId . '.jpg'
    ]];
}

/**
 * Palauttaa annettua roolia vastaavien aktiivisten käyttäjien sähköpostit.
 * Huomioi sekä pääroolit (sf_users.role_id) että lisäroolit (user_additional_roles).
 *
 * @param PDO $pdo
 * @param int $roleId sf_roles.id
 * @return string[]
 */
function sf_get_emails_by_role(PDO $pdo, int $roleId): array
{
    // Hae käyttäjät joiden päärooli TAI lisärooli on annettu rooli
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.email
        FROM sf_users u
        WHERE u.is_active = 1
          AND u.email <> ''
          AND u.email_notifications_enabled = 1
          AND (
              u.role_id = :role_id
              OR u.id IN (
                  SELECT uar.user_id 
                  FROM user_additional_roles uar 
                  WHERE uar.role_id = :role_id2
              )
          )
    ");
    $stmt->execute([':role_id' => $roleId, ':role_id2' => $roleId]);

    $emails = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $email = trim((string)$row['email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

/**
 * Turvatiimille menevät viestit (rooli: SF_ROLE_ID_SAFETY_TEAM).
 */
function sf_get_safety_team_emails(PDO $pdo): array
{
    return sf_get_emails_by_role($pdo, SF_ROLE_ID_SAFETY_TEAM);
}

/**
 * Viestintä-tiimille menevät viestit (rooli: SF_ROLE_ID_COMMS).
 */
function sf_get_comms_team_emails(PDO $pdo): array
{
    return sf_get_emails_by_role($pdo, SF_ROLE_ID_COMMS);
}

/**
 * Jakeluryhmälle menevät viestit (rooli: SF_ROLE_ID_DISTRIBUTION).
 */
function sf_get_distribution_emails(PDO $pdo): array
{
    return sf_get_emails_by_role($pdo, SF_ROLE_ID_DISTRIBUTION);
}

/**
 * Palauttaa maakohtaisen jakeluryhmän role_id:n.
 * 
 * @param string $countryCode Maakoodi (fi, sv, en, it, el)
 * @return int Role ID
 */
function sf_get_distribution_role_id(string $countryCode): int
{
    $roleMap = [
        'fi' => SF_ROLE_ID_DISTRIBUTION_FI,
        'sv' => SF_ROLE_ID_DISTRIBUTION_SV,
        'en' => SF_ROLE_ID_DISTRIBUTION_EN,
        'it' => SF_ROLE_ID_DISTRIBUTION_IT,
        'el' => SF_ROLE_ID_DISTRIBUTION_EL,
    ];
    return $roleMap[$countryCode] ?? SF_ROLE_ID_DISTRIBUTION_FI; // Default to Finland
}

/**
 * Hakee maakohtaisen jakeluryhmän sähköpostit.
 * 
 * @param PDO $pdo Database connection
 * @param string $countryCode Maakoodi (fi, sv, en, it, el)
 * @return string[] Email addresses
 */
function sf_get_distribution_emails_by_country(PDO $pdo, string $countryCode): array
{
    $roleId = sf_get_distribution_role_id($countryCode);
    return sf_get_emails_by_role($pdo, $roleId);
}

/**
 * Get preview attachments from database
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId SafetyFlash ID
 * @return array Attachment data for sf_send_email
 */
function sf_get_preview_attachments(PDO $pdo, int $flashId): array
{
    $stmt = $pdo->prepare("SELECT preview_filename FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmt->execute([$flashId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row || empty($row['preview_filename'])) {
        return [];
    }
    
    return sf_build_preview_attachment($row['preview_filename'], $flashId);
}

/**
 * Lähettää SafetyFlashin maakohtaiselle jakeluryhmälle kyseisen maan kielellä.
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId SafetyFlash ID (original, will find language version)
 * @param string $countryCode Maakoodi (fi, sv, en, it, el)
 * @param bool $hasPersonalInjury Onko henkilövahinkoja
 * @return int Lähetettyjen sähköpostien määrä
 */
function sf_mail_to_distribution_by_country(PDO $pdo, int $flashId, string $countryCode, bool $hasPersonalInjury = false): int
{
    sf_app_log("sf_mail_to_distribution_by_country: flashId={$flashId}, country={$countryCode}");
    
    $recipients = sf_get_distribution_emails_by_country($pdo, $countryCode);
    if (empty($recipients)) {
        sf_app_log("sf_mail_to_distribution_by_country: No recipients for country {$countryCode}");
        return 0;
    }
    
    // Hae kyseisen maan kieliversio flashista
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_to_distribution_by_country: Flash {$flashId} not found");
        return 0;
    }
    
    $groupId = !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : $flashId;
    
    // Etsi kyseisen kielen versio
    $langStmt = $pdo->prepare("
        SELECT id FROM sf_flashes 
        WHERE (id = ? OR translation_group_id = ?) AND lang = ?
        LIMIT 1
    ");
    $langStmt->execute([$groupId, $groupId, $countryCode]);
    $langFlash = $langStmt->fetch();
    
    $targetFlashId = $langFlash ? (int)$langFlash['id'] : $flashId;
    
    // Käytä maan kieltä sähköpostissa
    $emailLang = $countryCode;
    
    // Rakenna otsikko kyseisellä kielellä
    $typeEmoji = match($flash['type'] ?? 'yellow') {
        'red' => '🔴',
        'yellow' => '🟡',
        'green' => '🟢',
        default => '🟡',
    };
    $typeName = sf_email_term("email_type_{$flash['type']}", $emailLang);
    $title = $flash['title'] ?? '';
    $site = $flash['worksite'] ?? $flash['site'] ?? '';
    
    $subjectParts = [];
    if ($hasPersonalInjury && $flash['type'] === 'red') {
        $injuryWarning = sf_email_term('email_personal_injury_warning', $emailLang);
        $subjectParts[] = "⚠️ {$injuryWarning}";
    }
    $subjectParts[] = "{$typeEmoji} {$typeName}";
    if ($title) $subjectParts[] = $title;
    if ($site) $subjectParts[] = "({$site})";
    
    $subject = implode(' - ', array_filter($subjectParts));
    
    // Rakenna sähköpostidata
    $emailData = [
        'type' => $flash['type'] ?? 'yellow',
        'flash_id' => $targetFlashId,
        'subject' => $subject,
        'body_text' => sf_email_term('email_distribution_body', $emailLang),
        'flash_title' => $flash['title'] ?? '',
        'flash_worksite' => $flash['worksite'] ?? $flash['site'] ?? '',
        'flash_url' => sf_build_flash_url($targetFlashId),
        'lang' => $emailLang,
    ];
    
    // Add injury warning to body if applicable
    if ($hasPersonalInjury && $flash['type'] === 'red') {
        $emailData['message'] = sf_email_term('email_personal_injury_notice', $emailLang);
        $emailData['message_label'] = '⚠️ ' . sf_email_term('email_warning', $emailLang);
    }
    
    // Build email
    $email = sf_build_email_html($emailData, $emailLang);
    
    // Lähetä sähköposti
    sf_send_email(
        $subject,
        $email['html'],
        $email['text'],
        $recipients,
        sf_get_preview_attachments($pdo, $targetFlashId),
        $flashId,
        'sf_published_distribution'
    );
    
    return count($recipients);
}

/**
 * Julkaisuosoitteet.
 *
 * Ei käytetä kovakoodattuja vastaanottajia.
 * Varsinainen jakelu hoidetaan työmaa-, rooli- ja käyttäjäasetusten kautta.
 */
function sf_get_publish_target_emails(): array
{
    return [];
}

/**
 * Haetaan tekijän sähköposti flashin perusteella (sf_flashes.created_by -> sf_users.email).
 */
function sf_get_flash_creator_email(PDO $pdo, int $flashId): ?string
{
    $stmt = $pdo->prepare("
        SELECT u.email
        FROM sf_flashes f
        LEFT JOIN sf_users u ON u.id = f.created_by
        WHERE f.id = ?
        LIMIT 1
    ");
    $stmt->execute([$flashId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['email'])) {
        return null;
    }

    return trim((string)$row['email']);
}

/**
 * Send notification to supervisor(s) for approval
 * 
 * @param int $flashId Flash ID
 * @param string $recipientEmail Supervisor email
 * @param bool $isResubmission Whether this is a resubmission from request_info state (optional, defaults to false)
 * @return bool Success status
 */
function sf_send_supervisor_notification(int $flashId, string $recipientEmail, bool $isResubmission = false, string $submissionComment = ''): bool {
    error_log("DEBUG: sf_send_supervisor_notification called - flashId={$flashId}, email={$recipientEmail}");
    sf_app_log("sf_send_supervisor_notification: CALLED for flashId={$flashId}, email={$recipientEmail}");
    
    if (empty($recipientEmail)) {
        error_log('DEBUG: sf_send_supervisor_notification - Empty recipient email');
        sf_app_log('sf_send_supervisor_notification: Empty recipient email');
        return false;
    }
    
    // Check if recipient is current user (admin self-test case)
    if (isset($_SESSION['user_id'])) {
        $currentUserId = (int)$_SESSION['user_id'];
        error_log("DEBUG: Current user ID: {$currentUserId}");
        
        try {
            require __DIR__ . '/../../config.php';
            $pdo = new PDO(
                "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
                $config['db']['user'],
                $config['db']['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            
            $stmt = $pdo->prepare("SELECT email FROM sf_users WHERE id = ? LIMIT 1");
            $stmt->execute([$currentUserId]);
            $currentUserEmail = $stmt->fetchColumn();
            
            if ($currentUserEmail && $currentUserEmail === $recipientEmail) {
                error_log("DEBUG: Recipient is current user - this may be a self-test scenario");
            }
        } catch (Throwable $e) {
            error_log('DEBUG: Could not check current user email: ' . $e->getMessage());
        }
    }
    
    try {
        require __DIR__ . '/../../config.php';
        $pdo = new PDO(
            "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
            $config['db']['user'],
            $config['db']['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        error_log('DEBUG: sf_send_supervisor_notification - PDO INIT ERROR: ' . $e->getMessage());
        sf_app_log('sf_send_supervisor_notification: PDO INIT ERROR: ' . $e->getMessage());
        return false;
    }
    
    // Check if we should send email to this recipient
    if (!sf_should_send_email($pdo, $recipientEmail, 'sf_supervisor_approval')) {
        error_log("DEBUG: sf_send_supervisor_notification - Email notifications disabled for {$recipientEmail}");
        sf_app_log("sf_send_supervisor_notification: SKIPPED for {$recipientEmail} (notifications disabled)");
        // Note: We don't have the subject yet, so we'll log with a generic subject
        sf_log_email($pdo, $flashId, $recipientEmail, 'Supervisor Notification', 'skipped', 'User has disabled email notifications', null);
        return false;
    }
    
    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        error_log("DEBUG: sf_send_supervisor_notification - Flash {$flashId} not found");
        sf_app_log("sf_send_supervisor_notification: Flash {$flashId} not found");
        return false;
    }
    
    // Get user language
    $lang = sf_get_user_language($pdo, $recipientEmail);
    error_log("DEBUG: User language: {$lang}");
    
    // Determine email subject and body based on submission type
    if ($isResubmission) {
        $subject = sf_email_term('email_supervisor_resubmission_subject', $lang);
        $bodyText = sf_email_term('email_supervisor_resubmission_body', $lang);
    } else {
        $subject = sf_email_term('email_supervisor_subject', $lang);
        $bodyText = sf_email_term('email_supervisor_body', $lang);
    }
    
    // Add submission comment if provided
    if (!empty($submissionComment)) {
        $bodyText .= "\n\n" . sf_email_term('submission_comment_email_label', $lang) . ":\n" . $submissionComment;
    }
    
    // Build email data
    $emailData = [
        'type' => $flash['type'] ?? 'yellow',
        'flash_id' => $flashId,
        'subject' => $subject,
        'body_text' => $bodyText,
        'flash_title' => $flash['title'] ?? '',
        'flash_worksite' => $flash['worksite'] ?? '',
        'flash_url' => sf_build_flash_url($flashId),
    ];
    
    // Build email
    $email = sf_build_email_html($emailData, $lang);
    $emailSubject = $subject . " (ID: {$flashId})";
    
    error_log("DEBUG: Sending email with subject: {$emailSubject}");
    sf_send_email($emailSubject, $email['html'], $email['text'], [$recipientEmail], [], $flashId, 'sf_supervisor_approval');
    
    error_log("DEBUG: Email send completed");
    sf_app_log("sf_send_supervisor_notification: Email sent to {$recipientEmail}");
    
    return true;
}

/**
 * Turvatiimille: uusi tai uudelleen lähetetty tarkistukseen.
 *
 * Käyttö:
 *  - kun tila vaihtuu esim. draft -> pending_review TAI request_info -> pending_review,
 *    kutsu sf_mail_to_safety_team($pdo, $flashId, $stateBefore)
 *
 * $stateBefore:
 *  - jos ennen oli 'request_info' -> teksti kertoo että tekijä on päivittänyt ja lähettänyt uudelleen
 *  - muuten -> "Uusi Safetyflash on lähetetty tarkistettavaksi."
 *  - jos 'pending_supervisor' -> työmaavastaava on hyväksynyt ja lähettänyt turvatiimille
 */
function sf_mail_to_safety_team(PDO $pdo, int $flashId, string $stateBefore): void
{
    sf_app_log("sf_mail_to_safety_team: CALLED for flashId={$flashId}, stateBefore={$stateBefore}");

    $recipients = sf_get_safety_team_emails($pdo);
    if (empty($recipients)) {
        sf_app_log('sf_mail_to_safety_team: NO RECIPIENTS (Turvatiimi-ryhmä tyhjä)');
        return;
    }

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_to_safety_team: Flash {$flashId} not found");
        return;
    }

    // Group recipients by language for efficient sending
    $recipientsByLang = [];
    foreach ($recipients as $email) {
        $lang = sf_get_user_language($pdo, $email);
        if (!isset($recipientsByLang[$lang])) {
            $recipientsByLang[$lang] = [];
        }
        $recipientsByLang[$lang][] = $email;
    }

    // Send email in each language
    foreach ($recipientsByLang as $lang => $langRecipients) {
        // Determine appropriate body text based on previous state
        $bodyText = '';
        if ($stateBefore === 'request_info') {
            $bodyText = sf_email_term('email_resubmitted_for_review_body', $lang);
        } elseif ($stateBefore === 'pending_supervisor') {
            $bodyText = sf_email_term('email_supervisor_approved_body', $lang);
        } else {
            $bodyText = sf_email_term('email_new_flash_for_review_body', $lang);
        }
        
        // Build email data
        $emailData = [
            'type' => $flash['type'] ?? 'yellow',
            'flash_id' => $flashId,
            'subject' => sf_email_term('email_new_flash_for_review_subject', $lang),
            'body_text' => $bodyText,
            'flash_title' => $flash['title'] ?? '',
            'flash_worksite' => $flash['worksite'] ?? '',
            'flash_url' => sf_build_flash_url($flashId),
        ];

        // Build email
        $email = sf_build_email_html($emailData, $lang);
        $subject = sf_email_term('email_new_flash_for_review_subject', $lang) . " (ID: {$flashId})";

        sf_send_email($subject, $email['html'], $email['text'], $langRecipients, [], $flashId, 'sf_supervisor_approval');
    }
}

/**
 * Tekijälle: turvatiimi pyytää lisätietoja (request_info).
 * Tämä EI mene rooliryhmille, vaan vain Safetyflashin luojalle.
 */
function sf_mail_request_info(PDO $pdo, int $flashId, string $message): void
{
    sf_app_log("sf_mail_request_info: CALLED for flashId={$flashId}");

    $email = sf_get_flash_creator_email($pdo, $flashId);
    if ($email === null) {
        sf_app_log("sf_mail_request_info: NO CREATOR EMAIL for flashId={$flashId}");
        return;
    }

    sf_app_log("sf_mail_request_info: SENDING TO {$email}");

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_request_info: Flash {$flashId} not found");
        return;
    }

    // Get user language
    $lang = sf_get_user_language($pdo, $email);

    // Build email data
    $emailData = [
        'type' => $flash['type'] ?? 'yellow',
        'flash_id' => $flashId,
        'subject' => sf_email_term('email_request_info_subject', $lang),
        'body_text' => sf_email_term('email_request_info_body', $lang),
        'flash_title' => $flash['title'] ?? '',
        'flash_worksite' => $flash['worksite'] ?? '',
        'flash_url' => sf_build_flash_url($flashId),
        'message' => $message,
        'message_label' => sf_email_term('email_message_from_safety_team', $lang),
    ];

    // Build email
    $email_content = sf_build_email_html($emailData, $lang);
    $subject = sf_email_term('email_request_info_subject', $lang) . " (ID: {$flashId})";

    sf_send_email($subject, $email_content['html'], $email_content['text'], [$email], [], $flashId, 'sf_request_info');
}

/**
 * Viestinnälle: turvatiimi lähetti flashin viestintään (to_comms).
 * Lisäksi voidaan cc:llä tekijä (ccCreator = true).
 * Tämä kutsutaan, kun tila vaihtuu to_comms-tilaan.
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId Flash ID
 * @param string $message Optional message to communications team
 * @param bool $ccCreator Whether to CC the flash creator
 * @param array $languages Selected language versions (e.g., ['fi', 'en'])
 * @param int $widerDistribution 1 if wider distribution requested, 0 otherwise
 * @param string $screensOption 'all' or 'selected'
 * @param array $worksites Array of worksite IDs (integers)
 * @param array $selectedCountries Array of country codes (e.g., ['fi', 'it'])
 * @param string $worksitesText Formatted text of selected worksites for display (e.g., "🇫🇮 Suomi, Työmaa1")
 */
function sf_mail_to_comms(
    PDO $pdo, 
    int $flashId, 
    string $message, 
    bool $ccCreator = true,
    array $languages = [],
    int $widerDistribution = 0,
    string $screensOption = 'all',
    array $worksites = [],
    array $selectedCountries = [],
    string $worksitesText = ''
): void
{
    sf_app_log("sf_mail_to_comms: CALLED for flashId={$flashId}");
    
    // Log new parameters
    if (!empty($languages)) {
        sf_app_log("sf_mail_to_comms: Selected languages: " . implode(', ', $languages));
    }
    sf_app_log("sf_mail_to_comms: Wider distribution: " . ($widerDistribution ? 'Yes' : 'No'));
    sf_app_log("sf_mail_to_comms: Screens option: {$screensOption}");

    $recipients = sf_get_comms_team_emails($pdo);

    if ($ccCreator) {
        $creator = sf_get_flash_creator_email($pdo, $flashId);
        if ($creator !== null) {
            $recipients[] = $creator;
        }
    }

    if (empty($recipients)) {
        sf_app_log('sf_mail_to_comms: NO RECIPIENTS (Viestintä-ryhmä + cc tyhjä)');
        return;
    }

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_to_comms: Flash {$flashId} not found");
        return;
    }

    // Group recipients by language
    $recipientsByLang = [];
    foreach ($recipients as $email) {
        $lang = sf_get_user_language($pdo, $email);
        if (!isset($recipientsByLang[$lang])) {
            $recipientsByLang[$lang] = [];
        }
        $recipientsByLang[$lang][] = $email;
    }

    // Send email in each language
    foreach ($recipientsByLang as $lang => $langRecipients) {
        // Build email data
        $emailData = [
            'type' => $flash['type'] ?? 'yellow',
            'flash_id' => $flashId,
            'subject' => sf_email_term('email_to_comms_subject', $lang),
            'body_text' => sf_email_term('email_to_comms_body', $lang),
            'flash_title' => $flash['title'] ?? '',
            'flash_worksite' => $flash['worksite'] ?? '',
            'flash_url' => sf_build_flash_url($flashId),
            'message' => $message,
            'message_label' => sf_email_term('email_message_for_comms', $lang),
            // New multi-step data
            'languages' => $languages,
            'wider_distribution' => $widerDistribution,
            'screens_option' => $screensOption,
            'worksites' => $worksites,
            'selected_countries' => $selectedCountries,
            'worksites_text' => $worksitesText,
        ];

        // Build email
        $email = sf_build_email_html($emailData, $lang);
        $subject = sf_email_term('email_to_comms_subject', $lang) . " (ID: {$flashId})";

        sf_send_email($subject, $email['html'], $email['text'], $langRecipients, [], $flashId, 'sf_to_comms');
    }
}

/**
 * Turvatiimille: viestintä kommentoi to_comms-tilassa (lisäkysymys tms.).
 *
 * Tämä funktio EI lähetä viestiä luojalle, vaan nimenomaan turvatiimiroolille.
 * Kutsu tätä, kun:
 *  - tila on 'to_comms'
 *  - kommentoija on viestintä-roolissa
 *  - lisätään kommentti lokiin
 */
function sf_mail_comms_comment_to_safety(
    PDO $pdo,
    int $logFlashId,
    string $message,
    ?int $fromUserId,
    ?int $creatorId
): void {
    sf_app_log("sf_mail_comms_comment_to_safety: CALLED for groupId={$logFlashId}");

    $recipients = sf_get_safety_team_emails($pdo);
    if (empty($recipients)) {
        sf_app_log('sf_mail_comms_comment_to_safety: NO RECIPIENTS (Turvatiimi-ryhmä tyhjä)');
        return;
    }

    // Get flash details
    $flash = sf_get_flash_details($pdo, $logFlashId);
    if (!$flash) {
        sf_app_log("sf_mail_comms_comment_to_safety: Flash {$logFlashId} not found");
        return;
    }

    // Group recipients by language
    $recipientsByLang = [];
    foreach ($recipients as $email) {
        $lang = sf_get_user_language($pdo, $email);
        if (!isset($recipientsByLang[$lang])) {
            $recipientsByLang[$lang] = [];
        }
        $recipientsByLang[$lang][] = $email;
    }

    // Send email in each language
    foreach ($recipientsByLang as $lang => $langRecipients) {
        // Build email data
        $emailData = [
            'type' => $flash['type'] ?? 'yellow',
            'flash_id' => $logFlashId,
            'subject' => sf_email_term('email_comms_comment_subject', $lang),
            'body_text' => sf_email_term('email_comms_comment_body', $lang),
            'flash_title' => $flash['title'] ?? '',
            'flash_worksite' => $flash['worksite'] ?? '',
            'flash_url' => sf_build_flash_url($logFlashId),
            'message' => $message,
            'message_label' => sf_email_term('email_comment_label', $lang),
        ];

        // Build email
        $email = sf_build_email_html($emailData, $lang);
        $subject = sf_email_term('email_comms_comment_subject', $lang) . " (ID: {$logFlashId})";

        sf_send_email($subject, $email['html'], $email['text'], $langRecipients, [], $logFlashId, 'comment_comms_to_safety');
    }
}

function sf_mail_published(PDO $pdo, int $flashId): void
{
    sf_app_log("sf_mail_published: CALLED for flashId={$flashId}");

    $recipients = sf_get_publish_target_emails();
    if (empty($recipients)) {
        sf_app_log('sf_mail_published: NO RECIPIENTS');
        return;
    }

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_published: Flash {$flashId} not found");
        return;
    }

    // Get user language (use default 'fi' since this is a general publish target)
    $lang = 'fi';

    // Get language versions (translations)
    $translationUrls = [];
    $groupId = !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : $flashId;
    $translationsData = sf_get_flash_translations($pdo, $groupId);
    
    foreach ($translationsData as $tlang => $tid) {
        if ($tid != $flashId) { // Don't include current flash
            $translationUrls[$tlang] = sf_build_flash_url($tid);
        }
    }

    // Build email data
    $emailData = [
        'type' => $flash['type'] ?? 'yellow',
        'flash_id' => $flashId,
        'subject' => sf_email_term('email_published_subject', $lang),
        'body_text' => sf_email_term('email_published_body', $lang) . "\n\n" . sf_email_term('email_login_to_view', $lang),
        'flash_title' => $flash['title'] ?? '',
        'flash_worksite' => $flash['worksite'] ?? '',
        'flash_url' => sf_build_flash_url($flashId),
        'translations' => $translationUrls,
    ];

    // Build email
    $email = sf_build_email_html($emailData, $lang);
    $subject = sf_email_term('email_published_subject', $lang) . " (ID: {$flashId})";

    // Prepare attachments (preview image if available)
    $attachments = sf_build_preview_attachment($flash['preview_filename'] ?? null, $flashId);

    sf_send_email($subject, $email['html'], $email['text'], $recipients, $attachments, $flashId, 'sf_published_general');

try {
    PushNotificationService::sendWorkflowToEmails(
        $pdo,
        $recipients,
        $flashId,
        'push_published_title',
        'push_published_body',
        [],
        'sf_published_general'
    );
} catch (Throwable $pushError) {
    sf_app_log('sf_mail_published: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
}
}

/**
 * Tekijälle: SafetyFlash on julkaistu.
 * Lähetetään ilmoitus tekijälle kun hänen SafetyFlashinsa julkaistaan.
 */
function sf_mail_published_to_creator(PDO $pdo, int $flashId): void
{
    sf_app_log("sf_mail_published_to_creator: CALLED for flashId={$flashId}");

    $email = sf_get_flash_creator_email($pdo, $flashId);
    if ($email === null) {
        sf_app_log("sf_mail_published_to_creator: NO CREATOR EMAIL for flashId={$flashId}");
        return;
    }

    sf_app_log("sf_mail_published_to_creator: SENDING TO {$email}");

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_published_to_creator: Flash {$flashId} not found");
        return;
    }

    // Get user language
    $lang = sf_get_user_language($pdo, $email);

    // Build email data
    $emailData = [
        'type' => $flash['type'] ?? 'yellow',
        'flash_id' => $flashId,
        'subject' => sf_email_term('email_your_flash_published_subject', $lang),
        'body_text' => sf_email_term('email_your_flash_published_body', $lang),
        'flash_title' => $flash['title'] ?? '',
        'flash_worksite' => $flash['worksite'] ?? '',
        'flash_url' => sf_build_flash_url($flashId),
    ];

    // Build email
    $email_content = sf_build_email_html($emailData, $lang);
    $subject = sf_email_term('email_your_flash_published_subject', $lang) . " (ID: {$flashId})";

    // Prepare attachments (preview image)
    $attachments = sf_build_preview_attachment($flash['preview_filename'] ?? null, $flashId);

    sf_send_email($subject, $email_content['html'], $email_content['text'], [$email], $attachments, $flashId, 'sf_published_creator');

try {
    PushNotificationService::sendWorkflowToEmails(
        $pdo,
        [$email],
        $flashId,
        'push_creator_published_title',
        'push_creator_published_body',
        [],
        'sf_published_creator'
    );
} catch (Throwable $pushError) {
    sf_app_log('sf_mail_published_to_creator: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
}
}

/**
 * Prosessissa mukana olleille hyväksyjille: SafetyFlash on julkaistu.
 * Lähetetään työmaavastaavalle ja turvatiimiläiselle, jotka ovat käsitelleet SafetyFlashin.
 */
function sf_mail_published_to_process_participants(PDO $pdo, int $flashId): void
{
    sf_app_log("sf_mail_published_to_process_participants: CALLED for flashId={$flashId}");

    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_published_to_process_participants: Flash {$flashId} not found");
        return;
    }

    $creatorEmail = sf_get_flash_creator_email($pdo, $flashId);

    $stmt = $pdo->prepare("
        SELECT DISTINCT u.email
        FROM safetyflash_logs l
        INNER JOIN sf_users u ON u.id = l.user_id
        WHERE l.flash_id = :flash_id
          AND u.is_active = 1
          AND u.email <> ''
          AND l.event_type IN ('supervisor_approved', 'sent_to_comms')
    ");
    $stmt->execute([':flash_id' => $flashId]);

    $recipients = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $email = trim((string)($row['email'] ?? ''));

        if ($email === '') {
            continue;
        }

        if ($creatorEmail !== null && mb_strtolower($email) === mb_strtolower($creatorEmail)) {
            continue;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $email;
        }
    }

    $recipients = array_values(array_unique($recipients));

    if (empty($recipients)) {
        sf_app_log("sf_mail_published_to_process_participants: NO PROCESS PARTICIPANTS for flashId={$flashId}");
        return;
    }

    $attachments = sf_build_preview_attachment($flash['preview_filename'] ?? null, $flashId);

    $recipientsByLang = [];
    foreach ($recipients as $recipientEmail) {
        $lang = sf_get_user_language($pdo, $recipientEmail);
        $recipientsByLang[$lang][] = $recipientEmail;
    }

    foreach ($recipientsByLang as $lang => $langRecipients) {
        $emailData = [
            'type' => $flash['type'] ?? 'yellow',
            'flash_id' => $flashId,
            'subject' => sf_email_term('email_process_participant_published_subject', $lang),
            'body_text' => sf_email_term('email_process_participant_published_body', $lang),
            'flash_title' => $flash['title'] ?? '',
            'flash_worksite' => $flash['worksite'] ?? '',
            'flash_url' => sf_build_flash_url($flashId),
        ];

        $emailContent = sf_build_email_html($emailData, $lang);
        $subject = sf_email_term('email_process_participant_published_subject', $lang) . " (ID: {$flashId})";

        sf_send_email(
            $subject,
            $emailContent['html'],
            $emailContent['text'],
            $langRecipients,
            $attachments,
            $flashId,
            'sf_published_participant'
        );
    }

try {
    PushNotificationService::sendWorkflowToEmails(
        $pdo,
        $recipients,
        $flashId,
        'push_process_participant_published_title',
        'push_process_participant_published_body',
        [],
        'sf_published_participant'
    );
} catch (Throwable $pushError) {
    sf_app_log('sf_mail_published_to_process_participants: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
}
}
/**
 * Lähetä julkaistu SafetyFlash jakelulistalle.
 * 
 * @param PDO $pdo Tietokantayhteys
 * @param int $flashId SafetyFlash ID
 * @param bool $hasPersonalInjury Onko henkilövahinkoja (lisää otsikkoon varoituksen)
 */
function sf_mail_to_distribution(PDO $pdo, int $flashId, bool $hasPersonalInjury = false): void
{
    sf_app_log("sf_mail_to_distribution: CALLED for flashId={$flashId}, injury={$hasPersonalInjury}");

    $recipients = sf_get_distribution_emails($pdo);
    if (empty($recipients)) {
        sf_app_log('sf_mail_to_distribution: NO RECIPIENTS (Jakelu-ryhmä tyhjä)');
        return;
    }

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_to_distribution: Flash {$flashId} not found");
        return;
    }

    // Group recipients by language
    $recipientsByLang = [];
    foreach ($recipients as $email) {
        $lang = sf_get_user_language($pdo, $email);
        if (!isset($recipientsByLang[$lang])) {
            $recipientsByLang[$lang] = [];
        }
        $recipientsByLang[$lang][] = $email;
    }

    // Get language versions (translations)
    $groupId = !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : $flashId;
    $translationsData = sf_get_flash_translations($pdo, $groupId);

    // Send email in each language
    foreach ($recipientsByLang as $lang => $langRecipients) {
        // Build translation URLs for this language
        $translationUrls = [];
        foreach ($translationsData as $tlang => $tid) {
            if ($tid != $flashId) { // Don't include current flash
                $translationUrls[$tlang] = sf_build_flash_url($tid);
            }
        }

        // Build subject with type and optional injury warning
        $typeEmoji = match($flash['type'] ?? 'yellow') {
            'red' => '🔴',
            'yellow' => '🟡',
            'green' => '🟢',
            default => '🟡',
        };
        $typeName = sf_email_term("email_type_{$flash['type']}", $lang);
        $title = $flash['title'] ?? '';
        $site = $flash['worksite'] ?? '';
        
        // Build subject line
        $subjectParts = [];
        if ($hasPersonalInjury && $flash['type'] === 'red') {
            $injuryWarning = sf_email_term('email_personal_injury_warning', $lang);
            $subjectParts[] = "⚠️ {$injuryWarning}";
        }
        $subjectParts[] = "{$typeEmoji} {$typeName}";
        if ($title) {
            $subjectParts[] = $title;
        }
        if ($site) {
            $subjectParts[] = "({$site})";
        }
        $subject = implode(' - ', array_filter($subjectParts));

        // Build email data
        $emailData = [
            'type' => $flash['type'] ?? 'yellow',
            'flash_id' => $flashId,
            'subject' => $subject,
            'body_text' => sf_email_term('email_distribution_body', $lang),
            'flash_title' => $flash['title'] ?? '',
            'flash_worksite' => $flash['worksite'] ?? '',
            'flash_url' => sf_build_flash_url($flashId),
            'translations' => $translationUrls,
        ];

        // Add injury warning to body if applicable
        if ($hasPersonalInjury && $flash['type'] === 'red') {
            $emailData['message'] = sf_email_term('email_personal_injury_notice', $lang);
            $emailData['message_label'] = '⚠️ ' . sf_email_term('email_warning', $lang);
        }

        // Build email
        $email = sf_build_email_html($emailData, $lang);

        // Prepare attachments (preview image if available)
        $attachments = sf_build_preview_attachment($flash['preview_filename'] ?? null, $flashId);

        sf_send_email($subject, $email['html'], $email['text'], $langRecipients, $attachments, $flashId, 'sf_published_distribution');

        try {
PushNotificationService::sendWorkflowToEmails(
    $pdo,
    $langRecipients,
    $flashId,
    'push_published_title',
    'push_published_body',
    [],
    'sf_published_distribution'
);
        } catch (Throwable $pushError) {
            sf_app_log('sf_mail_to_distribution: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
        }
    }
}

/**
 * Build login URL
 * 
 * @return string Full URL to login page
 */
function sf_build_login_url(): string
{
    // Try to get base URL from config
    try {
        require __DIR__ . '/../../config.php';
        $baseUrl = 'https://safetyflash.tapojarvi.online';
    } catch (Throwable $e) {
        // Fallback if config can't be loaded
        $baseUrl = 'https://safetyflash.tapojarvi.online';
    }
    
    return $baseUrl . '/assets/pages/login.php';
}

/**
 * Lähetä tervetulosähköposti uudelle käyttäjälle automaattisesti generoidulla salasanalla
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $generatedPassword Plaintext password (to be sent only once)
 * @return bool Success status
 */
function sf_mail_welcome_new_user(PDO $pdo, int $userId, string $generatedPassword): bool
{
    sf_app_log("sf_mail_welcome_new_user: CALLED for userId={$userId}");
    
    // Fetch user details from database
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.email, u.role_id, u.ui_lang, r.name as role_name
        FROM sf_users u
        LEFT JOIN sf_roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        sf_app_log("sf_mail_welcome_new_user: User {$userId} not found", LOG_LEVEL_ERROR);
        return false;
    }
    
    $firstName = $user['first_name'] ?? '';
    $lastName = $user['last_name'] ?? '';
    $email = $user['email'] ?? '';
    $roleId = (int)($user['role_id'] ?? 0);
    $roleName = $user['role_name'] ?? '';
    
    // Validate email
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sf_app_log("sf_mail_welcome_new_user: Invalid email for user {$userId}", LOG_LEVEL_ERROR);
        return false;
    }
    
    // Get user language (use ui_lang from user or default to 'fi')
    $lang = $user['ui_lang'] ?? 'fi';
    $validLangs = ['fi', 'sv', 'en', 'it', 'el'];
    if (!in_array($lang, $validLangs, true)) {
        $lang = 'fi';
    }
    
    // Get localized role name
    require_once __DIR__ . '/../lib/sf_terms.php';
    $localizedRoleName = sf_role_name($roleId, $roleName, $lang);
    
    // Get user's role categories
    $stmt = $pdo->prepare("
        SELECT rc.name, rc.type, rc.worksite
        FROM user_role_categories urc
        JOIN role_categories rc ON urc.role_category_id = rc.id
        WHERE urc.user_id = ? AND rc.is_active = 1
        ORDER BY rc.type, rc.name
    ");
    $stmt->execute([$userId]);
    $roleCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format role categories for email
    $roleCategoriesText = '';
    if (!empty($roleCategories)) {
        $roleCategoriesText = "\n\n" . sf_email_term('email_your_role_categories', $lang) . ":\n";
        foreach ($roleCategories as $cat) {
            $typeName = sf_email_term("email_role_type_{$cat['type']}", $lang);
            $roleCategoriesText .= "- {$cat['name']} ($typeName)";
            if ($cat['worksite']) {
                $roleCategoriesText .= " - {$cat['worksite']}";
            }
            $roleCategoriesText .= "\n";
        }
    }
    
    // Build email data
    $emailData = [
        'type' => 'welcome',
        'subject' => sf_email_term('email_welcome_subject', $lang),
        'body_text' => sf_email_term('email_welcome_body', $lang),
        'user_name' => trim("{$firstName} {$lastName}"),
        'user_email' => $email,
        'user_role' => $localizedRoleName,
        'role_categories' => $roleCategoriesText,
        'generated_password' => $generatedPassword,
        'login_url' => sf_build_login_url(),
        'instructions' => sf_email_term('email_welcome_instructions', $lang),
        'lang' => $lang,
    ];
    
    // Build HTML and plain text email
    $emailContent = sf_build_email_html($emailData, $lang);
    
    // Send email
    try {
        sf_send_email(
            sf_email_term('email_welcome_subject', $lang),
            $emailContent['html'],
            $emailContent['text'],
            [$email],
            [],
            null,
            'system_welcome'
        );
        
        sf_app_log("sf_mail_welcome_new_user: Welcome email sent successfully to {$email}");
        return true;
    } catch (Throwable $e) {
        sf_app_log("sf_mail_welcome_new_user: Email failed for user ID {$userId}: " . $e->getMessage(), LOG_LEVEL_ERROR);
        return false;
    }
}

/**
 * Send email notification when someone comments on feedback (non-self)
 */
function sf_send_feedback_comment_notification(int $feedbackId, string $feedbackTitle, string $comment, array $commenter, int $authorUserId): bool {
    global $config;
    
    try {
        $pdo = Database::getInstance();
        
        // Get author email and language
        $stmt = $pdo->prepare("SELECT email, ui_lang, first_name FROM sf_users WHERE id = ?");
        $stmt->execute([$authorUserId]);
        $author = $stmt->fetch();
        
        if (!$author || empty($author['email'])) {
            return false;
        }
        
        // Check notification preference (includes global email_notifications_enabled check)
        if (!sf_should_send_email($pdo, $author['email'], 'feedback_comment')) {
            sf_app_log("sf_send_feedback_comment_notification: SKIPPED for user_id={$authorUserId} (preference disabled)");
            return false;
        }
        
        $lang = $author['ui_lang'] ?? 'fi';
        $validLangs = ['fi', 'sv', 'en', 'it', 'el'];
        if (!in_array($lang, $validLangs, true)) {
            $lang = 'fi';
        }
        $commenterName = trim(($commenter['first_name'] ?? '') . ' ' . ($commenter['last_name'] ?? ''));
        
        $feedbackUrl = rtrim($config['base_url'] ?? '', '/') . '/index.php?page=feedback#feedback-' . $feedbackId;
        
        $subject = sf_term('email_feedback_comment_subject', $lang);
        $subject = str_replace('{title}', $feedbackTitle, $subject);
        
        $bodyText = sf_term('email_feedback_comment_body', $lang);
        
        // Truncate long comments
        $commentPreview = mb_strlen($comment) > 500 ? mb_substr($comment, 0, 500) . '…' : $comment;
        
        $emailData = [
            'type' => 'info',
            'subject' => $subject,
            'body_text' => $bodyText,
            'flash_title' => $feedbackTitle,
            'flash_url' => $feedbackUrl,
            'message' => $commenterName . ': ' . $commentPreview,
            'message_label' => sf_term('email_feedback_comment_by', $lang),
            'cta_text' => sf_term('email_feedback_view_link', $lang),
        ];
        
        $email = sf_build_email_html($emailData, $lang);
        sf_send_email($subject, $email['html'], $email['text'], [$author['email']], [], null, 'feedback_comment');
        
        return true;
        
    } catch (Throwable $e) {
        error_log('Feedback comment email error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send email notification when feedback status changes
 */
function sf_send_feedback_status_notification(int $feedbackId, string $feedbackTitle, string $oldStatus, string $newStatus, string $adminNotes, int $authorUserId): bool {
    global $config;
    
    try {
        $pdo = Database::getInstance();
        
        // Get author email and language
        $stmt = $pdo->prepare("SELECT email, ui_lang, first_name FROM sf_users WHERE id = ?");
        $stmt->execute([$authorUserId]);
        $author = $stmt->fetch();
        
        if (!$author || empty($author['email'])) {
            return false;
        }
        
        // Check notification preference (includes global email_notifications_enabled check)
        if (!sf_should_send_email($pdo, $author['email'], 'feedback_status_change')) {
            sf_app_log("sf_send_feedback_status_notification: SKIPPED for user_id={$authorUserId} (preference disabled)");
            return false;
        }
        
        $lang = $author['ui_lang'] ?? 'fi';
        $validLangs = ['fi', 'sv', 'en', 'it', 'el'];
        if (!in_array($lang, $validLangs, true)) {
            $lang = 'fi';
        }
        
        $feedbackUrl = rtrim($config['base_url'] ?? '', '/') . '/index.php?page=feedback#feedback-' . $feedbackId;
        
        $subject = sf_term('email_feedback_status_subject', $lang);
        $subject = str_replace('{title}', $feedbackTitle, $subject);
        
        $bodyText = sf_term('email_feedback_status_body', $lang);
        $newStatusLabel = sf_term('feedback_status_' . $newStatus, $lang);
        $bodyText = str_replace('{status}', $newStatusLabel, $bodyText);
        
        $oldStatusLabel = sf_term('feedback_status_' . $oldStatus, $lang);
        
        // Build status change summary as the message field
        $statusSummary = sf_term('email_feedback_status_old', $lang) . ': ' . $oldStatusLabel
            . "\n" . sf_term('email_feedback_status_new', $lang) . ': ' . $newStatusLabel;
        if (!empty($adminNotes)) {
            $statusSummary .= "\n\n" . sf_term('email_feedback_status_admin_notes', $lang) . ":\n" . $adminNotes;
        }
        
        $emailData = [
            'type' => 'info',
            'subject' => $subject,
            'body_text' => $bodyText,
            'flash_title' => $feedbackTitle,
            'flash_url' => $feedbackUrl,
            'message' => $statusSummary,
            'message_label' => sf_term('email_feedback_status_new', $lang),
            'cta_text' => sf_term('email_feedback_view_link', $lang),
        ];
        
        $email = sf_build_email_html($emailData, $lang);
        sf_send_email($subject, $email['html'], $email['text'], [$author['email']], [], null, 'feedback_status_change');
        
        return true;
        
    } catch (Throwable $e) {
        error_log('Feedback status email error: ' . $e->getMessage());
        return false;
    }
}
/**
 * Save or update per-flash comment notification preference.
 */
function sf_set_comment_subscription(PDO $pdo, int $flashId, int $userId, bool $enabled): void
{
    $stmtExisting = $pdo->prepare("
        SELECT unsubscribe_token
        FROM sf_comment_subscriptions
        WHERE flash_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmtExisting->execute([$flashId, $userId]);
    $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);

    $token = !empty($existing['unsubscribe_token'])
        ? (string)$existing['unsubscribe_token']
        : bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("
        INSERT INTO sf_comment_subscriptions (flash_id, user_id, unsubscribe_token, is_enabled, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            unsubscribe_token = VALUES(unsubscribe_token),
            is_enabled = VALUES(is_enabled),
            updated_at = NOW()
    ");
    $stmt->execute([
        $flashId,
        $userId,
        $token,
        $enabled ? 1 : 0,
    ]);
}

/**
 * Send comment notifications to:
 * - original flash creator
 * - parent comment author (if reply)
 * - users subscribed to this flash's comment thread
 */
function sf_mail_comment_notifications(
    PDO $pdo,
    int $logFlashId,
    string $message,
    ?int $fromUserId,
    ?int $flashCreatorId,
    ?int $parentCommentId = null
): void {
    try {
        $flash = sf_get_flash_details($pdo, $logFlashId);
        if (!$flash) {
            sf_app_log("sf_mail_comment_notifications: Flash {$logFlashId} not found");
            return;
        }

        $flashTitle = (string)($flash['title'] ?? '');
        $flashUrl = sf_build_flash_url($logFlashId);

        $fromName = 'Järjestelmä';
        if (!empty($fromUserId)) {
            $stmtFrom = $pdo->prepare("
                SELECT first_name, last_name
                FROM sf_users
                WHERE id = ?
                LIMIT 1
            ");
            $stmtFrom->execute([(int)$fromUserId]);
            $fromUser = $stmtFrom->fetch(PDO::FETCH_ASSOC);
            if ($fromUser) {
                $tmpName = trim(((string)($fromUser['first_name'] ?? '')) . ' ' . ((string)($fromUser['last_name'] ?? '')));
                if ($tmpName !== '') {
                    $fromName = $tmpName;
                }
            }
        }

        $replyToUserId = null;
        $replyToName = '';
        if (!empty($parentCommentId)) {
            $stmtParent = $pdo->prepare("
                SELECT l.user_id, u.first_name, u.last_name
                FROM safetyflash_logs l
                LEFT JOIN sf_users u ON u.id = l.user_id
                WHERE l.id = ?
                LIMIT 1
            ");
            $stmtParent->execute([(int)$parentCommentId]);
            $parentRow = $stmtParent->fetch(PDO::FETCH_ASSOC);

            if ($parentRow) {
                $replyToUserId = !empty($parentRow['user_id']) ? (int)$parentRow['user_id'] : null;
                $replyToName = trim(((string)($parentRow['first_name'] ?? '')) . ' ' . ((string)($parentRow['last_name'] ?? '')));
            }
        }

        $recipientIds = [];

        if (!empty($flashCreatorId)) {
            $recipientIds[] = (int)$flashCreatorId;
        }

        if (!empty($replyToUserId)) {
            $recipientIds[] = (int)$replyToUserId;
        }

        $stmtSubs = $pdo->prepare("
            SELECT user_id
            FROM sf_comment_subscriptions
            WHERE flash_id = ? AND is_enabled = 1
        ");
        $stmtSubs->execute([$logFlashId]);
        $subscriberIds = array_map('intval', $stmtSubs->fetchAll(PDO::FETCH_COLUMN));
        $recipientIds = array_merge($recipientIds, $subscriberIds);

        $recipientIds = array_values(array_unique(array_filter($recipientIds, function ($value) use ($fromUserId) {
            $value = (int)$value;
            if ($value <= 0) {
                return false;
            }
            if (!empty($fromUserId) && $value === (int)$fromUserId) {
                return false;
            }
            return true;
        })));

        if (empty($recipientIds)) {
            sf_app_log("sf_mail_comment_notifications: No recipients for flash {$logFlashId}");
            return;
        }

        $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
        $stmtRecipients = $pdo->prepare("
            SELECT
                u.id,
                u.email,
                u.ui_lang,
                u.first_name,
                u.last_name,
                s.unsubscribe_token
            FROM sf_users u
            LEFT JOIN sf_comment_subscriptions s
                ON s.user_id = u.id
               AND s.flash_id = ?
            WHERE u.id IN ($placeholders)
              AND u.is_active = 1
              AND u.email IS NOT NULL
              AND u.email <> ''
        ");
        $stmtRecipients->execute(array_merge([$logFlashId], $recipientIds));
        $recipients = $stmtRecipients->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recipients as $recipient) {
            $recipientId = (int)$recipient['id'];
            $recipientEmail = (string)$recipient['email'];
            $recipientLang = (string)($recipient['ui_lang'] ?? 'fi');
            $recipientFirstName = trim((string)($recipient['first_name'] ?? ''));

            if (empty($recipient['unsubscribe_token'])) {
                sf_set_comment_subscription($pdo, $logFlashId, $recipientId, true);

                $stmtTokenRefresh = $pdo->prepare("
                    SELECT unsubscribe_token
                    FROM sf_comment_subscriptions
                    WHERE flash_id = ? AND user_id = ?
                    LIMIT 1
                ");
                $stmtTokenRefresh->execute([$logFlashId, $recipientId]);
                $tokenRow = $stmtTokenRefresh->fetch(PDO::FETCH_ASSOC);

                if ($tokenRow && !empty($tokenRow['unsubscribe_token'])) {
                    $recipient['unsubscribe_token'] = $tokenRow['unsubscribe_token'];
                }
            }

            $subject = sf_email_term('email_comment_notification_subject', $recipientLang);
            if ($subject === 'email_comment_notification_subject' || $subject === '') {
                $subject = 'Uusi kommentti SafetyFlashiin';
            }
            $subject .= ': ' . $flashTitle;

            $greeting = sf_email_term('email_greeting', $recipientLang);
            if ($greeting === 'email_greeting' || $greeting === '') {
                $greeting = 'Hei';
            }

            $commentLabel = sf_email_term('email_comment_label', $recipientLang);
            if ($commentLabel === 'email_comment_label' || $commentLabel === '') {
                $commentLabel = 'Kommentti';
            }

            $flashLabel = sf_email_term('email_flash_label', $recipientLang);
            if ($flashLabel === 'email_flash_label' || $flashLabel === '') {
                $flashLabel = 'Tiedote';
            }

            $replyTargetLabel = sf_email_term('email_reply_target_label', $recipientLang);
            if ($replyTargetLabel === 'email_reply_target_label' || $replyTargetLabel === '') {
                $replyTargetLabel = 'Vastauksen kohde';
            }

            $openFlashLabel = sf_email_term('email_open_flash_label', $recipientLang);
            if ($openFlashLabel === 'email_open_flash_label' || $openFlashLabel === '') {
                $openFlashLabel = 'Avaa tiedote';
            }

            if (!empty($replyToUserId) && $recipientId === (int)$replyToUserId) {
                $intro = sf_email_term('email_comment_reply_to_you_body', $recipientLang);
                if ($intro === 'email_comment_reply_to_you_body' || $intro === '') {
                    $intro = '%s vastasi kommenttiisi SafetyFlashissa.';
                }
                $commentCategory = 'comment_reply';
            } elseif (!empty($flashCreatorId) && $recipientId === (int)$flashCreatorId) {
                $intro = sf_email_term('email_comment_on_your_flash_body', $recipientLang);
                if ($intro === 'email_comment_on_your_flash_body' || $intro === '') {
                    $intro = '%s lisäsi uuden kommentin sinun SafetyFlashiisi.';
                }
                $commentCategory = 'comment_on_own_flash';
            } else {
                $intro = sf_email_term('email_comment_on_followed_flash_body', $recipientLang);
                if ($intro === 'email_comment_on_followed_flash_body' || $intro === '') {
                    $intro = '%s lisäsi uuden kommentin seuraamaasi SafetyFlashiin.';
                }
                $commentCategory = 'comment_subscribed';
            }

            $safeMessage = trim((string)$message);
            $safeMessage = preg_replace("/\r\n|\r|\n/", "\n", $safeMessage);

            $bodyParts = [];

            if ($recipientFirstName !== '') {
                $bodyParts[] = $greeting . ' ' . $recipientFirstName . ',';
            } else {
                $bodyParts[] = $greeting . ',';
            }

            $bodyParts[] = sprintf($intro, $fromName);

            $bodyParts[] = $openFlashLabel . ":\n" . $flashUrl;

            $bodyText = implode("\n\n", $bodyParts);

            $unsubscribeToken = (string)($recipient['unsubscribe_token'] ?? '');
            $unsubscribeUrl = '';

            if ($unsubscribeToken !== '') {
                $unsubscribeUrl = rtrim((string)($GLOBALS['config']['base_url'] ?? ''), '/')
                    . '/app/actions/unsubscribe_comment_notifications.php?token='
                    . urlencode($unsubscribeToken);
            }

            $emailData = [
                'type' => $flash['type'] ?? 'yellow',
                'subject' => $subject,
                'body_text' => $bodyText,
                'flash_title' => $flashTitle,
                'flash_worksite' => $flash['worksite'] ?? '',
                'flash_url' => $flashUrl,
                'comment_text' => $safeMessage,
                'reply_target_name' => $replyToName,
                'unsubscribe_url' => $unsubscribeUrl,
            ];

            $email = sf_build_email_html($emailData, $recipientLang);
            sf_send_email($subject, $email['html'], $email['text'], [$recipientEmail], [], $logFlashId, $commentCategory);

            try {
                PushNotificationService::sendWorkflowToUsers(
                    $pdo,
                    [$recipientId],
                    $logFlashId,
                    'push_comment_title',
                    'push_comment_body',
                    [
                        'from' => $fromName,
                        'message' => $safeMessage,
                    ]
                );
            } catch (Throwable $pushError) {
                sf_app_log('sf_mail_comment_notifications: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
            }
        }
    } catch (Throwable $e) {
        sf_app_log('sf_mail_comment_notifications ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    }
}

/**
 * Send @mention notification emails to users tagged in a comment.
 *
 * @param PDO      $pdo           Database connection
 * @param int      $logFlashId    Flash group ID used for the comment log
 * @param string   $message       The comment text containing @mentions
 * @param int|null $fromUserId    User ID who wrote the comment (excluded from recipients)
 * @param int[]    $mentionedIds  User IDs that were explicitly @mentioned
 */
function sf_mail_mention_notifications(
    PDO $pdo,
    int $logFlashId,
    string $message,
    ?int $fromUserId,
    array $mentionedIds
): void {
    if (empty($mentionedIds)) {
        return;
    }

    try {
        $flash = sf_get_flash_details($pdo, $logFlashId);
        if (!$flash) {
            sf_app_log("sf_mail_mention_notifications: Flash {$logFlashId} not found");
            return;
        }

        $flashTitle = (string)($flash['title'] ?? '');
        $flashUrl   = sf_build_flash_url($logFlashId);

        $fromName = 'Järjestelmä';
        if (!empty($fromUserId)) {
            $stmtFrom = $pdo->prepare("
                SELECT first_name, last_name
                FROM sf_users
                WHERE id = ?
                LIMIT 1
            ");
            $stmtFrom->execute([(int)$fromUserId]);
            $fromUser = $stmtFrom->fetch(PDO::FETCH_ASSOC);
            if ($fromUser) {
                $tmpName = trim(((string)($fromUser['first_name'] ?? '')) . ' ' . ((string)($fromUser['last_name'] ?? '')));
                if ($tmpName !== '') {
                    $fromName = $tmpName;
                }
            }
        }

        // Filter out the commenter themselves
        $recipientIds = array_values(array_unique(array_filter(
            array_map('intval', $mentionedIds),
            function (int $id) use ($fromUserId): bool {
                return $id > 0 && (empty($fromUserId) || $id !== (int)$fromUserId);
            }
        )));

        if (empty($recipientIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
        $stmtRecipients = $pdo->prepare("
            SELECT
                u.id,
                u.email,
                u.ui_lang,
                u.first_name,
                u.last_name,
                s.unsubscribe_token
            FROM sf_users u
            LEFT JOIN sf_comment_subscriptions s
                ON s.user_id = u.id
               AND s.flash_id = ?
            WHERE u.id IN ($placeholders)
              AND u.is_active = 1
              AND u.email IS NOT NULL
              AND u.email <> ''
        ");
        $stmtRecipients->execute(array_merge([$logFlashId], $recipientIds));
        $recipients = $stmtRecipients->fetchAll(PDO::FETCH_ASSOC);

        $safeMessage = trim((string)$message);
        $safeMessage = preg_replace("/\r\n|\r|\n/", "\n", $safeMessage);

        foreach ($recipients as $recipient) {
            $recipientId        = (int)$recipient['id'];
            $recipientEmail     = (string)$recipient['email'];
            $recipientLang      = (string)($recipient['ui_lang'] ?? 'fi');
            $recipientFirstName = trim((string)($recipient['first_name'] ?? ''));

            if (!sf_should_send_email($pdo, $recipientEmail, 'comment_mention')) {
                continue;
            }

            // Do not automatically subscribe mentioned users to future comment notifications.
            // A mention notification is a one-time notification only.

            $subject = sf_email_term('email_mention_notification_subject', $recipientLang);
            if ($subject === 'email_mention_notification_subject' || $subject === '') {
                $subject = 'Sinut mainittiin SafetyFlash-kommentissa';
            }
            $subject .= ': ' . $flashTitle;

            $greeting = sf_email_term('email_greeting', $recipientLang);
            if ($greeting === 'email_greeting' || $greeting === '') {
                $greeting = 'Hei';
            }

            $openFlashLabel = sf_email_term('email_open_flash_label', $recipientLang);
            if ($openFlashLabel === 'email_open_flash_label' || $openFlashLabel === '') {
                $openFlashLabel = 'Avaa tiedote';
            }

            $intro = sf_email_term('email_mention_body', $recipientLang);
            if ($intro === 'email_mention_body' || $intro === '') {
                $intro = '%s mainitsi sinut kommentissa SafetyFlashissa.';
            }

            $bodyParts = [];

            if ($recipientFirstName !== '') {
                $bodyParts[] = $greeting . ' ' . $recipientFirstName . ',';
            } else {
                $bodyParts[] = $greeting . ',';
            }

            $bodyParts[] = sprintf($intro, $fromName);
            $bodyParts[] = $openFlashLabel . ":\n" . $flashUrl;

            $bodyText = implode("\n\n", $bodyParts);

            $unsubscribeToken = (string)($recipient['unsubscribe_token'] ?? '');
            $unsubscribeUrl   = '';
            if ($unsubscribeToken !== '') {
                $unsubscribeUrl = rtrim((string)($GLOBALS['config']['base_url'] ?? ''), '/')
                    . '/app/actions/unsubscribe_comment_notifications.php?token='
                    . urlencode($unsubscribeToken);
            }

            $emailData = [
                'type'           => $flash['type'] ?? 'yellow',
                'subject'        => $subject,
                'body_text'      => $bodyText,
                'flash_title'    => $flashTitle,
                'flash_worksite' => $flash['worksite'] ?? '',
                'flash_url'      => $flashUrl,
                'comment_text'   => $safeMessage,
                'reply_target_name' => '',
                'unsubscribe_url'   => $unsubscribeUrl,
            ];

            $email = sf_build_email_html($emailData, $recipientLang);
            sf_send_email($subject, $email['html'], $email['text'], [$recipientEmail], [], $logFlashId, 'comment_mention');

            try {
                PushNotificationService::sendWorkflowToUsers(
                    $pdo,
                    [$recipientId],
                    $logFlashId,
                    'push_mention_title',
                    'push_mention_body',
                    [
                        'from' => $fromName,
                        'message' => $safeMessage,
                    ]
                );
            } catch (Throwable $pushError) {
                sf_app_log('sf_mail_mention_notifications: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
            }
        }
    } catch (Throwable $e) {
        sf_app_log('sf_mail_mention_notifications ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    }
}

/**
 * Kokoaa vastaanottajalistan työmaa-ilmoituksille.
 *
 * Hakee kaikille valituille infonäyttöavaimille niiden työmaan
 * työmaavastaavat ja kotityömaakäyttäjät. Jättää pois SafetyFlashin
 * alkuperäisen työmaan käyttäjät sekä sähköposti-ilmoitukset
 * poistaneet käyttäjät.
 *
 * @param PDO   $pdo             Tietokantayhteys
 * @param int   $flashId         SafetyFlash ID (hakee lähdetyömaan nimen)
 * @param int[] $displayKeyIds   Valitut infonäyttöavainten ID:t
 * @return array<int,array{email:string,ui_lang:string}> Dedup-lista [email, ui_lang]
 */
function sf_get_worksite_notification_recipients(PDO $pdo, int $flashId, array $displayKeyIds): array
{
    if (empty($displayKeyIds)) {
        return [];
    }

    // Hae lähdetyömaan nimi (sf_flashes.site)
    $stmtFlash = $pdo->prepare("SELECT site FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmtFlash->execute([$flashId]);
    $sourceWorksite = trim((string)($stmtFlash->fetchColumn() ?: ''));

    // Hae infonäyttöavaimien worksite_id:t
    $placeholders = implode(',', array_fill(0, count($displayKeyIds), '?'));
    $stmtKeys = $pdo->prepare("
        SELECT DISTINCT k.worksite_id
        FROM sf_display_api_keys k
        WHERE k.id IN ($placeholders)
          AND k.worksite_id IS NOT NULL
          AND k.is_active = 1
    ");
    $stmtKeys->execute(array_values($displayKeyIds));
    $worksiteIds = $stmtKeys->fetchAll(PDO::FETCH_COLUMN);

    if (empty($worksiteIds)) {
        return [];
    }

    // Hae työmaat ja suodata pois lähdetyömaa (nimellä, case-insensitive)
    $wsPlaceholders = implode(',', array_fill(0, count($worksiteIds), '?'));
    $stmtWs = $pdo->prepare("
        SELECT id, name FROM sf_worksites WHERE id IN ($wsPlaceholders)
    ");
    $stmtWs->execute(array_values($worksiteIds));
    $worksites = $stmtWs->fetchAll(PDO::FETCH_ASSOC);

    // Kerää vastaanottajat: email => ui_lang  (dedup)
    $recipients = [];

    foreach ($worksites as $ws) {
        $wsId   = (int)$ws['id'];
        $wsName = trim((string)$ws['name']);

        // Ohita SafetyFlashin alkuperäinen työmaa
        if ($sourceWorksite !== '' && mb_strtolower($wsName) === mb_strtolower($sourceWorksite)) {
            sf_app_log("sf_get_worksite_notification_recipients: skipping source worksite '{$wsName}'");
            continue;
        }

        // 1) Työmaavastaavat (role_categories.type = 'supervisor', worksite = työmaan nimi)
        $stmtSup = $pdo->prepare("
            SELECT DISTINCT u.email, COALESCE(u.ui_lang, 'fi') AS ui_lang
            FROM sf_users u
            INNER JOIN user_role_categories urc ON urc.user_id = u.id
            INNER JOIN role_categories rc ON rc.id = urc.role_category_id
            LEFT JOIN sf_user_notification_preferences np
                ON np.user_id = u.id AND np.category = 'sf_worksite_notification'
            WHERE rc.type = 'supervisor'
              AND rc.is_active = 1
              AND LOWER(rc.worksite) = LOWER(?)
              AND u.is_active = 1
              AND u.email <> ''
              AND COALESCE(np.enabled, 1) = 1
        ");
        $stmtSup->execute([$wsName]);
        foreach ($stmtSup->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = trim($row['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($recipients[$email])) {
                $recipients[$email] = $row['ui_lang'];
            }
        }

        // 2) Käyttäjät, joilla työmaa on kotityömaana
        $stmtHome = $pdo->prepare("
            SELECT DISTINCT u.email, COALESCE(u.ui_lang, 'fi') AS ui_lang
            FROM sf_users u
            LEFT JOIN sf_user_notification_preferences np
                ON np.user_id = u.id AND np.category = 'sf_worksite_notification'
            WHERE u.home_worksite_id = ?
              AND u.is_active = 1
              AND u.email <> ''
              AND COALESCE(np.enabled, 1) = 1
        ");
        $stmtHome->execute([$wsId]);
        foreach ($stmtHome->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = trim($row['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($recipients[$email])) {
                $recipients[$email] = $row['ui_lang'];
            }
        }
    }

    // Muunna [email => ui_lang] muotoon [{email, ui_lang}, ...]
    $result = [];
    foreach ($recipients as $email => $lang) {
        $result[] = ['email' => $email, 'ui_lang' => $lang];
    }
    return $result;
}

/**
 * Lähettää työmaa-ilmoitukset SafetyFlashin julkaisusta.
 *
 * Jokainen vastaanottaja saa ilmoituksen omalla kielellään.
 * Virheet kirjataan lokiin, mutta eivät keskeytä prosessia.
 *
 * @param PDO   $pdo           Tietokantayhteys
 * @param int   $flashId       SafetyFlash ID
 * @param int[] $displayKeyIds Valitut infonäyttöavainten ID:t
 * @param int   $publishedBy   Julkaisijan käyttäjä-ID (lokitusta varten)
 * @return int Lähetettyjen (tai yritettyjen) sähköpostien määrä
 */
function sf_mail_worksite_notification(PDO $pdo, int $flashId, array $displayKeyIds, int $publishedBy = 0): int
{
    sf_app_log("sf_mail_worksite_notification: CALLED flashId={$flashId}, keys=" . implode(',', $displayKeyIds));

    $recipients = sf_get_worksite_notification_recipients($pdo, $flashId, $displayKeyIds);
    if (empty($recipients)) {
        sf_app_log("sf_mail_worksite_notification: no recipients for flashId={$flashId}");
        return 0;
    }

    // Hae flash-tiedot
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_worksite_notification: flash {$flashId} not found");
        return 0;
    }

    $groupId = !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : $flashId;

    // Ryhmittele vastaanottajat kielen mukaan
    $byLang = [];
    foreach ($recipients as $r) {
        $validLangs = ['fi', 'sv', 'en', 'it', 'el'];
        $lang = in_array($r['ui_lang'], $validLangs, true) ? $r['ui_lang'] : 'fi';
        $byLang[$lang][] = $r['email'];
    }

    $totalSent = 0;

    foreach ($byLang as $lang => $emails) {
        // Etsi kyseisen kielen flash-versio
        $stmtLang = $pdo->prepare("
            SELECT id FROM sf_flashes
            WHERE (id = ? OR translation_group_id = ?) AND lang = ?
            LIMIT 1
        ");
        $stmtLang->execute([$groupId, $groupId, $lang]);
        $langFlashId = (int)($stmtLang->fetchColumn() ?: $flashId);

        $subject = sf_email_term('email_worksite_notification_subject', $lang)
                   . ' (ID: ' . $flashId . ')';

        $emailData = [
            'type'           => $flash['type'] ?? 'yellow',
            'flash_id'       => $langFlashId,
            'subject'        => sf_email_term('email_worksite_notification_subject', $lang),
            'body_text'      => sf_email_term('email_worksite_notification_body', $lang),
            'flash_title'    => $flash['title'] ?? '',
            'flash_worksite' => $flash['worksite'] ?? '',
            'flash_url'      => sf_build_flash_url($langFlashId),
        ];

        $emailContent = sf_build_email_html($emailData, $lang);
        $attachments  = sf_get_preview_attachments($pdo, $langFlashId);

        try {
            sf_send_email($subject, $emailContent['html'], $emailContent['text'], $emails, $attachments, $flashId, 'sf_worksite_notification');

            try {
                PushNotificationService::sendWorkflowToEmails(
    $pdo,
    $emails,
    $langFlashId,
    'push_worksite_notification_title',
    'push_worksite_notification_body',
    [],
    'sf_worksite_notification'
);
            } catch (Throwable $pushError) {
                sf_app_log('sf_mail_worksite_notification: push notification failed: ' . $pushError->getMessage(), LOG_LEVEL_WARNING);
            }

            $totalSent += count($emails);
            sf_app_log("sf_mail_worksite_notification: sent to " . count($emails) . " recipients in lang={$lang}");
        } catch (Throwable $e) {
            sf_app_log("sf_mail_worksite_notification: ERROR lang={$lang}: " . $e->getMessage(), LOG_LEVEL_ERROR);
        }
    }

    sf_app_log("sf_mail_worksite_notification: total sent={$totalSent} for flashId={$flashId}");
    return $totalSent;
}

/**
 * Send notification email to all users who have subscribed to product_updates
 * when a new changelog entry is published.
 *
 * Only sends when the entry has not yet had an email sent (idempotent via
 * sf_changelog.email_sent_at — column added in migration 2026_05_changelog_email_sent).
 *
 * @param PDO $pdo
 * @param int $updateId  ID of the published sf_changelog entry
 */
function sf_mail_product_update(PDO $pdo, int $updateId): void
{
    sf_app_log("sf_mail_product_update: CALLED for updateId={$updateId}");

    // 1. Fetch the changelog entry
    $stmtEntry = $pdo->prepare("
        SELECT id, translations, publish_date, created_at, email_sent_at
        FROM sf_changelog
        WHERE id = ? AND is_published = 1
        LIMIT 1
    ");
    $stmtEntry->execute([$updateId]);
    $entry = $stmtEntry->fetch(PDO::FETCH_ASSOC);

    if (!$entry) {
        sf_app_log("sf_mail_product_update: Entry {$updateId} not found or not published");
        return;
    }

    // 2. Idempotency guard: skip if email already sent
    if (!empty($entry['email_sent_at'])) {
        sf_app_log("sf_mail_product_update: Email already sent at {$entry['email_sent_at']} for updateId={$updateId}, skipping");
        return;
    }

    // 3. Parse translations
    $translations = [];
    if (!empty($entry['translations'])) {
        $decoded = json_decode((string)$entry['translations'], true);
        if (is_array($decoded)) {
            $translations = $decoded;
        }
    }

    // 4. Resolve publish date for display
    $rawDate = !empty($entry['publish_date']) ? (string)$entry['publish_date'] : (string)$entry['created_at'];
    $ts = strtotime($rawDate);
    $displayDate = ($ts !== false) ? date('d.m.Y', $ts) : '';

    // 5. Build base URL for updates page
    try {
        require __DIR__ . '/../../config.php';
        $baseUrl = rtrim($config['base_url'] ?? 'https://safetyflash.tapojarvi.online', '/');
    } catch (Throwable $e) {
        $baseUrl = 'https://safetyflash.tapojarvi.online';
    }
    $updatesUrl = $baseUrl . '/index.php?page=updates';

    // 6. Fetch all active users with product_updates enabled
    //    An absent row means default = enabled (opt-out model).
    $stmtUsers = $pdo->prepare("
        SELECT u.id, u.email, u.ui_lang
        FROM sf_users u
        WHERE u.is_active = 1
          AND u.email IS NOT NULL
          AND u.email <> ''
          AND (
              NOT EXISTS (
                  SELECT 1 FROM sf_user_notification_preferences p
                  WHERE p.user_id = u.id AND p.category = 'product_updates'
              )
              OR EXISTS (
                  SELECT 1 FROM sf_user_notification_preferences p
                  WHERE p.user_id = u.id AND p.category = 'product_updates' AND p.enabled = 1
              )
          )
    ");
    $stmtUsers->execute();
    $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        sf_app_log("sf_mail_product_update: No subscribers for updateId={$updateId}");
        // Mark as sent so we don't retry unnecessarily
        $pdo->prepare("UPDATE sf_changelog SET email_sent_at = NOW() WHERE id = ?")->execute([$updateId]);
        return;
    }

    // 7. Group users by language
    $validLangs = ['fi', 'sv', 'en', 'it', 'el'];
    $byLang = [];
    foreach ($users as $u) {
        $lang = in_array((string)($u['ui_lang'] ?? ''), $validLangs, true) ? (string)$u['ui_lang'] : 'fi';
        $byLang[$lang][] = (string)$u['email'];
    }

    // Helper: resolve title/content for a language with fallback chain
    $resolveField = static function (array $trans, string $lang, string $field): string {
        if (!empty($trans[$lang][$field])) {
            return (string)$trans[$lang][$field];
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

    // Sanitize HTML content (same logic as updates.php)
    $sanitizeHtml = static function (string $html): string {
        if (strip_tags($html) === $html) {
            return $html; // plain text — leave for nl2br in template
        }
        $allowed = '<p><br><strong><em><u><ol><ul><li><span>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace('/<(\w+)(?:\s[^>]*)?(\/?)>/', '<$1$2>', $html) ?? $html;
        return $html;
    };

    // 8. Send per language group
    $totalSent = 0;
    foreach ($byLang as $lang => $emails) {
        $title   = $resolveField($translations, $lang, 'title');
        $content = $sanitizeHtml($resolveField($translations, $lang, 'content'));

        if ($title === '') {
            // Fallback: try any language for the title
            $title = $resolveField($translations, 'fi', 'title');
        }

        $subjectTemplate = sf_email_term('email_product_update_subject', $lang);
        $subject = str_replace('{title}', $title, $subjectTemplate);

        $emailData = [
            'title'        => $title,
            'content'      => $content,
            'publish_date' => $displayDate,
            'updates_url'  => $updatesUrl,
        ];

        $emailContent = sf_generate_update_email($emailData, $lang);

        try {
            sf_send_email($subject, $emailContent['html'], $emailContent['text'], $emails, [], null, 'product_updates');
            $totalSent += count($emails);
            sf_app_log("sf_mail_product_update: Sent to " . count($emails) . " recipients (lang={$lang}) for updateId={$updateId}");
        } catch (Throwable $e) {
            sf_app_log("sf_mail_product_update: ERROR lang={$lang}: " . $e->getMessage(), LOG_LEVEL_ERROR);
        }
    }

    // 9. Mark email as sent (idempotency flag)
    try {
        $pdo->prepare("UPDATE sf_changelog SET email_sent_at = NOW() WHERE id = ?")->execute([$updateId]);
        sf_app_log("sf_mail_product_update: Marked email_sent_at for updateId={$updateId}, totalSent={$totalSent}");
    } catch (Throwable $e) {
        sf_app_log("sf_mail_product_update: Could not update email_sent_at: " . $e->getMessage(), LOG_LEVEL_WARNING);
    }
}
