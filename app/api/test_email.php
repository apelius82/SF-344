<?php
/**
 * Test email sending
 * Admin only endpoint
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../../assets/lib/phpmailer/Exception.php';
require_once __DIR__ . '/../../assets/lib/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../../assets/lib/phpmailer/SMTP.php';

header('Content-Type: application/json');

// Admin only
$user = sf_current_user();
if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['test_email'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Test email address is required']);
    exit;
}

$testAddress = filter_var($input['test_email'], FILTER_VALIDATE_EMAIL);

if (!$testAddress) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

try {
    // Get SMTP settings from database
    $host = sf_get_setting('smtp_host', 'localhost');
    $port = (int) sf_get_setting('smtp_port', 587);
    $encryption = sf_get_setting('smtp_encryption', 'tls');
    $username = sf_get_setting('smtp_username', '');
    $password = sf_get_setting('smtp_password', '');
    $fromEmail = sf_get_setting('smtp_from_email', 'no-reply@example.com');
    $fromName = sf_get_setting('smtp_from_name', 'SafetyFlash');
    
    $mail = new PHPMailer(true);
    
    // Server settings
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = ($username !== '' || $password !== '');
    
    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = false;
    }
    
    if ($mail->SMTPAuth) {
        $mail->Username = $username;
        $mail->Password = $password;
    }
    
    // UTF-8
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    
    // Recipients
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($testAddress);
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = 'SafetyFlash - Testisähköposti / Test Email';
    $mail->Body = '
        <html>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <h2 style="color: #0f172a;">SafetyFlash - Testisähköposti</h2>
            <p>Tämä on testisähköposti SafetyFlash-järjestelmästä.</p>
            <p><strong>SMTP-asetukset toimivat oikein!</strong></p>
            <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;">
            <h2 style="color: #0f172a;">SafetyFlash - Test Email</h2>
            <p>This is a test email from the SafetyFlash system.</p>
            <p><strong>SMTP settings are working correctly!</strong></p>
        </body>
        </html>
    ';
    $mail->AltBody = 'Tämä on testisähköposti SafetyFlash-järjestelmästä. SMTP-asetukset toimivat oikein! / This is a test email from the SafetyFlash system. SMTP settings are working correctly!';
    
    $mail->send();
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $mail->ErrorInfo ?? $e->getMessage()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}