<?php
/**
 * SafetyFlash - Display API Keys Management
 * 
 * API-avainten hallinta infonäyttö-playlistalle.
 * Vain admin-käyttäjät (role_id = 1) voivat käyttää.
 * 
 * @package SafetyFlash
 * @subpackage API
 * @created 2026-02-19
 * 
 * ENDPOINTS:
 * 
 * POST /app/api/display_api_keys_manage.php
 *   - Create new API key
 *   - Required: csrf_token, site, label (optional), lang (optional)
 *   - Returns: { ok: true, api_key: "sf_dk_xxx...", xibo_url: "...", label: "...", site: "...", lang: "..." }
 * 
 * GET /app/api/display_api_keys_manage.php
 *   - List all API keys (metadata only, no keys)
 *   - Returns: { ok: true, keys: [...] }
 * 
 * POST /app/api/display_api_keys_manage.php?action=delete
 *   - Deactivate API key (set is_active = 0)
 *   - Required: csrf_token, api_key_id
 *   - Returns: { ok: true }
 */

declare(strict_types=1);

try {
    // Load dependencies
    require_once __DIR__ . '/../includes/protect.php';
    require_once __DIR__ . '/../includes/csrf.php';
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../assets/lib/Database.php';
    
    // Admin-only access (role_id = 1)
    $user = sf_current_user();
    if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden. Admin access required.']);
        exit;
    }
    
    $pdo = Database::getInstance();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    // GET: List all API keys
    if ($method === 'GET') {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                LEFT(api_key, 10) as api_key_preview,
                site,
                label,
                lang,
                is_active,
                created_at,
                last_used_at,
                last_used_ip,
                expires_at
            FROM sf_display_api_keys
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format keys
        $formatted = array_map(function($key) {
            return [
                'id' => (int)$key['id'],
                'api_key_preview' => $key['api_key_preview'] . '...',
                'site' => $key['site'],
                'label' => $key['label'],
                'lang' => $key['lang'],
                'is_active' => (bool)$key['is_active'],
                'created_at' => $key['created_at'],
                'last_used_at' => $key['last_used_at'],
                'last_used_ip' => $key['last_used_ip'],
                'expires_at' => $key['expires_at'],
            ];
        }, $keys);
        
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'keys' => $formatted], JSON_PRETTY_PRINT);
        exit;
    }
    
    // POST: Create new key OR delete key
    if ($method === 'POST') {
        // Validate CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!sf_csrf_validate($csrfToken)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
        
        // DELETE action: Deactivate API key
        if ($action === 'delete') {
            $apiKeyId = (int)($_POST['api_key_id'] ?? 0);
            
            if ($apiKeyId <= 0) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Missing or invalid api_key_id']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                UPDATE sf_display_api_keys 
                SET is_active = 0 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $apiKeyId]);
            
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        
        // CREATE action: Generate new API key
        $site = trim($_POST['site'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $lang = trim($_POST['lang'] ?? 'fi');
        
        // Validate required parameters
        if (empty($site)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing required parameter: site']);
            exit;
        }
        
        // Validate language
        if (!in_array($lang, ['fi', 'sv', 'en', 'it', 'el'], true)) {
            $lang = 'fi';
        }
        
        // Generate API key: sf_dk_ + 48 hex characters (24 bytes)
        $apiKey = 'sf_dk_' . bin2hex(random_bytes(24));
        
        // Insert into database
        $stmt = $pdo->prepare("
            INSERT INTO sf_display_api_keys 
            (api_key, site, label, lang, is_active, created_by, created_at)
            VALUES (:api_key, :site, :label, :lang, 1, :created_by, NOW())
        ");
        $stmt->execute([
            ':api_key' => $apiKey,
            ':site' => $site,
            ':label' => $label ?: null,
            ':lang' => $lang,
            ':created_by' => (int)$user['id'],
        ]);
        
        // Build Xibo URL
        $baseUrl = rtrim($config['base_url'] ?? '', '/');
        $xiboUrl = $baseUrl . '/app/api/display_playlist.php?key=' . urlencode($apiKey);
        
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'api_key' => $apiKey,
            'xibo_url' => $xiboUrl,
            'site' => $site,
            'label' => $label,
            'lang' => $lang,
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Unsupported method
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}
