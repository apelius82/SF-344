<?php
/**
 * SafetyFlash - Xibo Display Playlist API
 * 
 * Julkinen API joka palauttaa aktiiviset flashit Xibo-infonäytöille.
 * Käyttää API-avainautentikointia. CORS-tuettu.
 * 
 * @package SafetyFlash
 * @subpackage API
 * @created 2026-02-19
 * @updated 2026-02-19 - API key authentication
 * 
 * USAGE EXAMPLES:
 * 
 * 1. JSON format (default):
 *    GET /app/api/display_playlist.php?key=sf_dk_xxx...
 *    Returns: JSON array of active flashes
 * 
 * 2. HTML slideshow:
 *    GET /app/api/display_playlist.php?key=sf_dk_xxx...&format=html
 *    Returns: Full HTML page with auto-rotating slideshow
 * 
 * 3. Slideshow content only (for iframe):
 *    GET /app/api/display_playlist.php?key=sf_dk_xxx...&format=slideshow
 *    Returns: HTML content without full page wrapper
 * 
 * XIBO INTEGRATION:
 * 
 * In Xibo CMS, create a new "Webpage" widget with URL:
 * https://your-domain.com/app/api/display_playlist.php?key=sf_dk_xxx...&format=html
 * 
 * Or use Embedded content with JavaScript to fetch JSON and display images.
 * See docs/XIBO_EMBEDDED_WIDGET.md for ready-to-use templates.
 * 
 * QUERY PARAMETERS:
 * - key (required): API key that determines site and language automatically
 * - format (optional): json|html|slideshow, default: json
 * 
 * AUTHENTICATION:
 * - API key validates against sf_display_api_keys table
 * - Checks is_active = 1 and expires_at is not passed
 * - Updates last_used_at and last_used_ip on each request
 * - Returns 401 if key missing, 403 if invalid/expired/inactive
 * 
 * RESPONSE:
 * - JSON format includes duration_seconds for each flash
 * - Slideshow uses per-image duration_seconds value
 * 
 * RATE LIMITING: Max 60 requests/minute per IP
 */

declare(strict_types=1);

// Simple rate limiting
function checkRateLimit(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $cacheFile = sys_get_temp_dir() . '/sf_api_rate_' . md5($ip) . '.json';
    
    $now = time();
    $window = 60; // 1 minute
    $maxRequests = 60;
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        $requests = array_filter($data['requests'] ?? [], fn($t) => $t > $now - $window);
        
        if (count($requests) >= $maxRequests) {
            return false;
        }
        
        $requests[] = $now;
        file_put_contents($cacheFile, json_encode(['requests' => $requests]));
    } else {
        file_put_contents($cacheFile, json_encode(['requests' => [$now]]));
    }
    
    return true;
}

try {
    // Rate limiting check
    if (!checkRateLimit()) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Rate limit exceeded. Max 60 requests per minute.']);
        exit;
    }
    
    // CORS headers
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    // Load dependencies
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../assets/lib/Database.php';
    
    // Connect to database
    $pdo = Database::getInstance();
    
    // Query parameters
    $apiKey = $_GET['key'] ?? null;
    $format = $_GET['format'] ?? 'json';
    
    // API key is required
    if (!$apiKey) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing required parameter: key']);
        exit;
    }
    
    // Validate API key and get site/lang from database
    $stmt = $pdo->prepare("
        SELECT id, site, lang, is_active, expires_at, last_used_at, last_used_ip
        FROM sf_display_api_keys
        WHERE api_key = :api_key
        LIMIT 1
    ");
    $stmt->execute([':api_key' => $apiKey]);
    $keyData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if key exists
    if (!$keyData) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }
    
    // Check if key is active
    if (!(bool)$keyData['is_active']) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'API key is deactivated']);
        exit;
    }
    
    // Check if key has expired
    if ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'API key has expired']);
        exit;
    }
    
    // Update usage information only occasionally.
    // Xibo displays may poll this endpoint often, so updating the database on every request
    // can slow down playlist loading when multiple displays refresh at the same time.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $lastUsedAt = $keyData['last_used_at'] ?? null;
    $lastUsedIp = $keyData['last_used_ip'] ?? null;

    $shouldUpdateUsage = false;

    if (empty($lastUsedAt)) {
        $shouldUpdateUsage = true;
    } elseif (strtotime((string)$lastUsedAt) < time() - 600) {
        $shouldUpdateUsage = true;
    } elseif ((string)$lastUsedIp !== (string)$clientIp) {
        $shouldUpdateUsage = true;
    }

    if ($shouldUpdateUsage) {
        $updateStmt = $pdo->prepare("
            UPDATE sf_display_api_keys
            SET last_used_at = NOW(), last_used_ip = :ip
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':ip' => $clientIp,
            ':id' => (int)$keyData['id'],
        ]);
    }
    
    // Get site and lang from API key
    $site = $keyData['site'];
    $lang = $keyData['lang'] ?? 'fi';
    
    // Validate format
    if (!in_array($format, ['json', 'html', 'slideshow'], true)) {
        $format = 'json';
    }
    
    // Validate language only for display metadata compatibility
    if (!in_array($lang, ['fi', 'sv', 'en', 'it', 'el'], true)) {
        $lang = 'fi';
    }
    
    // Get display key id for JOIN
    $displayKeyId = (int)$keyData['id'];

    // Fetch active flashes for this specific display (via sf_flash_display_targets).
    // Language is intentionally NOT used as a restriction here:
    // any flash language can be published to any display if that display
    // has been explicitly selected for the flash.
    $stmt = $pdo->prepare("
        SELECT
            f.id,
            f.title,
            CASE
                WHEN f.state <> 'published'
                     AND f.display_snapshot_active = 1
                     AND f.display_snapshot_preview IS NOT NULL
                    THEN f.display_snapshot_preview
                ELSE f.preview_filename
            END AS preview_filename,
            CASE
                WHEN f.state <> 'published'
                     AND f.display_snapshot_active = 1
                     AND f.original_type IS NOT NULL
                    THEN f.original_type
                ELSE f.type
            END AS type,
            f.published_at,
            f.created_at,
            f.display_duration_seconds,
            f.display_expires_at,
            COALESCE(t.sort_order, 0) AS sort_order
        FROM sf_flashes f
        INNER JOIN sf_flash_display_targets t ON t.flash_id = f.id
        WHERE t.display_key_id = :display_key_id
          AND t.is_active = 1
          AND (
              f.state = 'published'
              OR (
                  f.state NOT IN ('archived', 'rejected', 'deleted', 'closed')
                  AND f.display_snapshot_active = 1
                  AND f.display_snapshot_preview IS NOT NULL
              )
          )
          AND (f.display_expires_at IS NULL OR f.display_expires_at > NOW())
          AND f.display_removed_at IS NULL
        ORDER BY COALESCE(t.sort_order, 0) ASC, f.published_at DESC
        LIMIT 100
    ");

    $stmt->execute([':display_key_id' => $displayKeyId]);
    $flashes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build image URLs
    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    
    $items = array_map(function($flash) use ($baseUrl) {
        $imageUrl = $flash['preview_filename'] 
            ? $baseUrl . '/uploads/previews/' . $flash['preview_filename']
            : $baseUrl . '/assets/images/placeholder.jpg';
        
        return [
            'id' => (int)$flash['id'],
            'title' => $flash['title'] ?? '',
            'image_url' => $imageUrl,
            'duration_seconds' => (int)($flash['display_duration_seconds'] ?? 30),
            'display_expires_at' => $flash['display_expires_at'] ?? null,
            'type' => $flash['type'] ?? 'yellow',
            'published_at' => $flash['published_at'] ?? $flash['created_at'],
            'sort_order' => (int)($flash['sort_order'] ?? 0),
        ];
    }, $flashes);
    
    // Return based on format
    if ($format === 'json') {
        header('Content-Type: application/json');

        // Fetch fallback image setting
        $fallbackImage = null;
        try {
            $fbStmt = $pdo->prepare("SELECT setting_value FROM sf_settings WHERE setting_key = 'display_fallback_image' LIMIT 1");
            $fbStmt->execute();
            $fbRow = $fbStmt->fetch(PDO::FETCH_ASSOC);
            $fbPath = $fbRow['setting_value'] ?? '';
            if ($fbPath) {
                $fallbackImage = $baseUrl . '/' . ltrim($fbPath, '/');
            }
        } catch (Exception $e) {
            // Silently ignore if sf_settings not yet migrated
        }

        echo json_encode([
            'ok' => true,
            'site' => $site,
            'lang' => $lang,
            'count' => count($items),
            'items' => $items,
            'fallback_image' => $fallbackImage,
            'updated_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // HTML/Slideshow format - override restrictive CSP set by config.php/bootstrap.php
    // so this page can be embedded in an iframe from the same origin (playlist preview modal)
    header('Content-Security-Policy: frame-ancestors \'self\'', true);
    header('X-Frame-Options: SAMEORIGIN', true);
    header('Cross-Origin-Resource-Policy: same-origin', true);

    $includeHtmlWrapper = ($format === 'html');
	$isPreviewMode = isset($_GET['preview']) && (string)$_GET['preview'] === '1';
    
    if ($includeHtmlWrapper) {
        echo "<!DOCTYPE html>\n";
        echo "<html lang=\"{$lang}\">\n";
        echo "<head>\n";
        echo "<meta charset=\"UTF-8\">\n";
        echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
        echo "<!-- Playlist refresh is handled by JavaScript below. -->\n";
		echo "<title>SafetyFlash Display - {$site}</title>\n";
    }
    
echo "<style>\n";
    echo "* { margin: 0; padding: 0; box-sizing: border-box; }\n";

    if ($isPreviewMode) {
        echo "html, body { width: 100%; height: 100%; background: #f8fafc; overflow: hidden; font-family: Arial, sans-serif; }\n";
        echo "body { display: flex; align-items: flex-start; justify-content: center; }\n";
        echo ".sf-slideshow-container { width: 100vw; height: 100vh; position: relative; background: #f8fafc; }\n";
        echo ".sf-slide { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; transition: opacity 0.45s ease-in-out; display: flex; align-items: flex-start; justify-content: center; padding: 14px; background: #f8fafc; }\n";
        echo ".sf-slide.active { opacity: 1; z-index: 1; }\n";
        echo ".sf-slide img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 16px; background: #ffffff; box-shadow: 0 14px 34px rgba(15, 23, 42, 0.16); }\n";
        echo ".sf-no-content { color: #0f172a; text-align: center; padding: 2rem; font-size: 1.25rem; }\n";
        echo ".sf-progress-bar { position: fixed; bottom: 0; left: 0; height: 4px; background: #fee000; width: 0; transition: width linear; z-index: 10; }\n";
        echo "@media (max-width: 640px) { .sf-slide { padding: 8px 8px 4px; align-items: flex-start; } .sf-slide img { width: 100%; height: auto; max-height: none; border-radius: 12px; } }\n";
    } else {
        echo "body { background: #000; overflow: hidden; font-family: Arial, sans-serif; }\n";
        echo ".sf-slideshow-container { width: 100vw; height: 100vh; position: relative; }\n";
        echo ".sf-slide { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; transition: opacity 0.8s ease-in-out; display: flex; align-items: center; justify-content: center; }\n";
        echo ".sf-slide.active { opacity: 1; z-index: 1; }\n";
        echo ".sf-slide img { max-width: 100%; max-height: 100%; object-fit: contain; }\n";
        echo ".sf-no-content { color: #fff; text-align: center; padding: 2rem; font-size: 1.5rem; }\n";
        echo ".sf-progress-bar { position: fixed; bottom: 0; left: 0; height: 4px; background: rgba(255, 255, 255, 0.5); width: 0; transition: width linear; z-index: 10; }\n";
    }

    echo ".sf-standby { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100vw; height: 100vh; background: linear-gradient(135deg, #0f1923 0%, #1a2a3a 50%, #0f1923 100%); color: #fff; text-align: center; padding: 2rem; }\n";
    echo ".sf-standby-icon { margin-bottom: 1.5rem; opacity: 0.7; }\n";
    echo ".sf-standby-msg { font-size: 1.4rem; font-weight: 500; opacity: 0.9; margin-bottom: 1rem; }\n";
    echo ".sf-standby-clock { font-size: 2.5rem; font-weight: 300; letter-spacing: 0.05em; opacity: 0.8; font-variant-numeric: tabular-nums; }\n";
    echo ".sf-standby-date { font-size: 1rem; opacity: 0.5; margin-top: 0.5rem; }\n";
    echo "</style>\n";
    
    if ($includeHtmlWrapper) {
        echo "</head>\n";
        echo "<body>\n";
    }
    
    echo "<div class=\"sf-slideshow-container\" id=\"slideshow\">\n";
    
    if (empty($items)) {
        $standbyMessages = [
            'fi' => 'Ei aktiivisia tiedotteita tällä näytöllä',
            'sv' => 'Inga aktiva meddelanden på denna skärm',
            'en' => 'No active safety notices on this display',
            'it' => 'Nessun avviso attivo su questo display',
            'el' => 'Δεν υπάρχουν ενεργές ειδοποιήσεις σε αυτήν την οθόνη',
        ];
        $standbyMsg = htmlspecialchars($standbyMessages[$lang] ?? $standbyMessages['en'], ENT_QUOTES, 'UTF-8');
        echo "<div class=\"sf-standby\" id=\"sfStandby\">\n";
        echo "  <div class=\"sf-standby-icon\">\n";
        echo "    <svg xmlns=\"http://www.w3.org/2000/svg\" width=\"72\" height=\"72\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><path d=\"M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z\"/></svg>\n";
        echo "  </div>\n";
        echo "  <div class=\"sf-standby-msg\">{$standbyMsg}</div>\n";
        echo "  <div class=\"sf-standby-clock\" id=\"sfClock\"></div>\n";
        echo "  <div class=\"sf-standby-date\" id=\"sfDate\"></div>\n";
        echo "</div>\n";
        echo "<script>(function(){\n";
        echo "  function pad(n){return n<10?'0'+n:n;}\n";
        echo "  function tick(){\n";
        echo "    var d=new Date();\n";
        echo "    document.getElementById('sfClock').textContent=pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());\n";
        echo "    document.getElementById('sfDate').textContent=pad(d.getDate())+'.'+pad(d.getMonth()+1)+'.'+d.getFullYear();\n";
        echo "  }\n";
        echo "  tick();\n";
        echo "  setInterval(tick,1000);\n";
        echo "})();</script>\n";
    } else {
        foreach ($items as $index => $item) {
            $activeClass = ($index === 0) ? ' active' : '';
            $itemDuration = (int)($item['duration_seconds'] ?? 30);
            $itemExpiresAt = htmlspecialchars((string)($item['display_expires_at'] ?? ''), ENT_QUOTES, 'UTF-8');

            echo "<div class=\"sf-slide{$activeClass}\" data-duration=\"{$itemDuration}\" data-expires-at=\"{$itemExpiresAt}\">\n";
            echo "<img src=\"" . htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') . "\" alt=\"" . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . "\" loading=\"lazy\">\n";
            echo "</div>\n";
        }
    }
    
    echo "</div>\n";
    echo "<div class=\"sf-progress-bar\" id=\"progressBar\"></div>\n";
    
    if (!empty($items)) {
        echo "<script>\n";
        echo "(function() {\n";
        echo "  const slides = document.querySelectorAll('.sf-slide');\n";
        echo "  const progressBar = document.getElementById('progressBar');\n";
        echo "  const total = slides.length;\n";
        echo "  let currentIndex = 0;\n";
        echo "  let paused = false;\n";
        echo "  let timer = null;\n";
        echo "\n";
        echo "  function notifyParent(type, data) {\n";
        echo "    try {\n";
        echo "      window.parent.postMessage(Object.assign({ type }, data), window.location.origin);\n";
        echo "    } catch(e) {}\n";
        echo "  }\n";
        echo "\n";
        echo "  function animateProgress() {\n";
        echo "    const duration = parseInt(slides[currentIndex].getAttribute('data-duration') || '30', 10);\n";
        echo "    progressBar.style.transition = 'none';\n";
        echo "    progressBar.style.width = '0%';\n";
        echo "    setTimeout(function() {\n";
        echo "      if (!paused) {\n";
        echo "        progressBar.style.transition = 'width ' + duration + 's linear';\n";
        echo "        progressBar.style.width = '100%';\n";
        echo "      }\n";
        echo "    }, 50);\n";
        echo "  }\n";
        echo "\n";
        echo "  function scheduleNext() {\n";
        echo "    clearTimeout(timer);\n";
        echo "    if (!paused && total > 1) {\n";
        echo "      const duration = parseInt(slides[currentIndex].getAttribute('data-duration') || '30', 10);\n";
        echo "      timer = setTimeout(function() { showSlide(currentIndex + 1); }, duration * 1000);\n";
        echo "    }\n";
        echo "  }\n";
        echo "\n";
        echo "  function showSlide(index) {\n";
        echo "    slides[currentIndex].classList.remove('active');\n";
        echo "    currentIndex = ((index % total) + total) % total;\n";
        echo "    slides[currentIndex].classList.add('active');\n";
        echo "    const activeSlide = slides[currentIndex];\n";
        echo "    const duration = parseInt(activeSlide.getAttribute('data-duration') || '30', 10);\n";
        echo "    const expiresAt = activeSlide.getAttribute('data-expires-at') || '';\n";
        echo "    notifyParent('sf-playlist-slide', { current: currentIndex, total, duration_seconds: duration, expires_at: expiresAt });\n";
        echo "    animateProgress();\n";
        echo "    scheduleNext();\n";
        echo "  }\n";
        echo "\n";
        echo "  window.addEventListener('message', function(event) {\n";
        echo "    const data = event.data;\n";
        echo "    if (!data || typeof data !== 'object') return;\n";
        echo "    if (data.action === 'next') {\n";
        echo "      showSlide(currentIndex + 1);\n";
        echo "    } else if (data.action === 'prev') {\n";
        echo "      showSlide(currentIndex - 1);\n";
        echo "    } else if (data.action === 'pause') {\n";
        echo "      paused = true;\n";
        echo "      clearTimeout(timer);\n";
        echo "      progressBar.style.transition = 'none';\n";
        echo "      notifyParent('sf-playlist-state', { paused: true });\n";
        echo "    } else if (data.action === 'resume') {\n";
        echo "      paused = false;\n";
        echo "      animateProgress();\n";
        echo "      scheduleNext();\n";
        echo "      notifyParent('sf-playlist-state', { paused: false });\n";
        echo "    }\n";
        echo "  });\n";
        echo "\n";
        echo "  if (slides.length > 0) {\n";
                echo "    const activeSlide = slides[currentIndex];\n";
        echo "    const duration = parseInt(activeSlide.getAttribute('data-duration') || '30', 10);\n";
        echo "    const expiresAt = activeSlide.getAttribute('data-expires-at') || '';\n";
        echo "    notifyParent('sf-playlist-slide', { current: 0, total, duration_seconds: duration, expires_at: expiresAt });\n";
        echo "    animateProgress();\n";
        echo "    scheduleNext();\n";
        echo "  }\n";
        echo "\n";
        echo "  // Reload playlist data every 5 minutes\n";
        echo "  setTimeout(function() { window.location.reload(); }, 300000);\n";
        echo "})();\n";
        echo "</script>\n";
    }
    
    if ($includeHtmlWrapper) {
        echo "</body>\n";
        echo "</html>\n";
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}