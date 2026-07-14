<?php
/**
 * Generate PDF Report API Endpoint
 * 
 * Generates an A4 PDF report for a SafetyFlash record
 * 
 * @package SafetyFlash
 * @subpackage API
 */

declare(strict_types=1);

// Require authentication
require_once __DIR__ . '/../includes/protect.php';

// Check if Composer autoload exists
$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Composer dependencies not installed. Please run: composer install']);
    exit;
}
require_once $autoloadPath;

use Dompdf\Dompdf;
use Dompdf\Options;

// Get flash ID from query parameter
$flashId = (int)($_GET['id'] ?? 0);

if ($flashId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid flash ID']);
    exit;
}

try {
    // Get database connection
    $pdo = Database::getInstance();
    
    // Fetch flash record
    $stmt = $pdo->prepare("
        SELECT 
            id, type, lang, title, title_short, summary, description, 
            root_causes, actions, site, site_detail, 
            occurred_at, created_at, grid_bitmap,
            image_main, image_2, image_3,
            image1_caption, image2_caption, image3_caption,
            preview_filename, preview_filename_2,
            display_snapshot_preview, original_type, translation_group_id
        FROM sf_flashes 
        WHERE id = ?
        LIMIT 1
    ");
    
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$flash) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Flash not found']);
        exit;
    }
    
    // Only allow PDF generation for investigation reports (green type)
    if ($flash['type'] !== 'green') {
        http_response_code(403);
        die('PDF report is only available for investigation reports');
    }
    
    // Fetch extra images with captions
    $stmt = $pdo->prepare("
        SELECT id, filename, caption 
        FROM sf_flash_images 
        WHERE flash_id = ? 
        ORDER BY created_at ASC 
        LIMIT 50
    ");
    $stmt->execute([$flashId]);
    $extraImages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch additional info entries
    $additionalInfoEntries = [];
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sf_flash_additional_info (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                flash_id   INT UNSIGNED NOT NULL,
                user_id    INT UNSIGNED NOT NULL,
                content    TEXT         NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_flash_id (flash_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $aiStmt = $pdo->prepare("
            SELECT ai.content, ai.created_at, u.first_name, u.last_name
            FROM sf_flash_additional_info ai
            LEFT JOIN sf_users u ON u.id = ai.user_id
            WHERE ai.flash_id = ?
            ORDER BY ai.created_at ASC
        ");
        $aiStmt->execute([$flashId]);
        $additionalInfoEntries = $aiStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $aiErr) {
        error_log('generate_report: failed to fetch additional_info: ' . $aiErr->getMessage());
    }
    
    // Configure Dompdf
    $options = new Options();
    $appRoot = dirname(__DIR__, 2);
    $options->set('chroot', $appRoot);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isFontSubsettingEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    
    // Create Dompdf instance
    $dompdf = new Dompdf($options);
    
    // Render the PDF template
    ob_start();
    include __DIR__ . '/../views/pdf_report.php';
    $html = ob_get_clean();
    
    // Load HTML into Dompdf
    $dompdf->loadHtml($html);
    
    // Set paper size and orientation
    $dompdf->setPaper('A4', 'portrait');
    
    // Render PDF
    $dompdf->render();
    
    // Get PDF content
    $pdfData = $dompdf->output();
    
    // Generate filename
    if (function_exists('sf_generate_download_filename')) {
        $baseFilename = sf_generate_download_filename($flash, 0);
        $filename = preg_replace('/\.jpg$/', '.pdf', $baseFilename);
    } else {
        $filename = 'safetyflash_report_' . $flashId . '.pdf';
    }
    
    // Log to audit using mysqli (same as protect.php uses)
    try {
        $mysqli = sf_db();
        $userId = $_SESSION['user_id'] ?? null;
        $userEmail = $_SESSION['sf_user']['email'] ?? null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $details = json_encode([
            'filename' => $filename,
            'flash_title' => $flash['title'] ?? '',
            'flash_type' => $flash['type'],
            'site' => $flash['site'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        
        $stmt = $mysqli->prepare("
            INSERT INTO sf_audit_log 
            (user_id, user_email, action, target_type, target_id, details, ip_address, user_agent, log_level, created_at)
            VALUES (?, ?, 'report_pdf_generated', 'flash', ?, ?, ?, ?, 'info', NOW())
        ");
        $stmt->bind_param('ississ', $userId, $userEmail, $flashId, $details, $ipAddress, $userAgent);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $auditErr) {
        error_log("Audit log error: " . $auditErr->getMessage());
    }
    
    // If context=athena, record the Athena export before streaming
    $context = $_GET['context'] ?? '';
    if ($context === 'athena') {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId) {
            // Determine logFlashId (translation group root or flash.id)
            $logFlashId = !empty($flash['translation_group_id'])
                ? (int)$flash['translation_group_id']
                : (int)$flash['id'];

            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS sf_flash_athena_exports (
                        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        flash_id    INT UNSIGNED NOT NULL,
                        user_id     INT UNSIGNED NOT NULL,
                        exported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        source      ENUM('post_publish_modal','manual_download','marked_done') NOT NULL DEFAULT 'marked_done',
                        KEY idx_flash (flash_id),
                        KEY idx_exported_at (exported_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                $insAthena = $pdo->prepare("
                    INSERT INTO sf_flash_athena_exports (flash_id, user_id, source)
                    VALUES (:flash_id, :user_id, 'manual_download')
                ");
                $insAthena->execute([
                    ':flash_id' => $logFlashId,
                    ':user_id'  => (int)$userId,
                ]);
            } catch (Throwable $athenaErr) {
                error_log('generate_report: athena export insert error: ' . $athenaErr->getMessage());
            }

            // System comment
            try {
                $stmtAthenaComment = $pdo->prepare("
                    INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
                    VALUES (:flash_id, :user_id, :event_type, :description, NOW())
                ");
                $stmtAthenaComment->execute([
                    ':flash_id'    => $logFlashId,
                    ':user_id'     => (int)$userId,
                    ':event_type'  => 'comment_added',
					':description' => 'log_comment_label: ' . sf_term('log_athena_exported', $flash['lang'] ?? 'fi') . ' (' . strtoupper((string)($flash['lang'] ?? 'fi')) . ')',
                ]);
            } catch (Throwable $commentErr) {
                error_log('generate_report: athena system comment error: ' . $commentErr->getMessage());
            }
        }
    }

    // Send PDF response
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfData));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $pdfData;
    exit;
    
} catch (\Throwable $e) {
    error_log("Generate report error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Internal server error',
        'debug_message' => $e->getMessage(),
        'debug_file' => $e->getFile(),
        'debug_line' => $e->getLine()
    ]);
    exit;
}