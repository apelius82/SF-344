<?php
// app/includes/auth.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/log_app.php';

/**
 * IMPORTANT:
 * Many API endpoints include auth.php (directly or via protect.php) without calling session_start().
 * In v155 a regression removed session_start() from here which caused authenticated API calls to
 * behave like the user is logged out (session not initialised).
 *
 * We start the session here in a safe, idempotent way.
 */
if (session_status() === PHP_SESSION_NONE) {
    // Best-effort secure cookie flags without breaking HTTP environments.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);

    $sessionCfg = is_array($config['session'] ?? null) ? $config['session'] : [];

    $lifetime = (int)($sessionCfg['lifetime'] ?? 0);
    $timeout  = (int)($sessionCfg['timeout'] ?? 0);
    $path     = (string)($sessionCfg['path'] ?? '/');
    $domain   = (string)($sessionCfg['domain'] ?? '');
    $samesite = (string)($sessionCfg['samesite'] ?? 'Lax');

    // Server-side session storage must live at least as long as the app expects.
    // Add safety margin so GC does not remove active sessions too early.
    $gcMaxLifetime = max($lifetime, $timeout, 14400);
    $gcMaxLifetime += 600;

    ini_set('session.gc_maxlifetime', (string)$gcMaxLifetime);
    ini_set('session.cookie_httponly', '1');

    // Never force Secure cookies on HTTP (would break sessions).
    $secure   = $isHttps;
    $httponly = true;

    if (!headers_sent()) {
        if (!empty($sessionCfg['name'])) {
            session_name((string) $sessionCfg['name']);
        }

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ]);
        } else {
            session_set_cookie_params(
                $lifetime,
                $path . '; samesite=' . $samesite,
                $domain,
                $secure,
                $httponly
            );
        }
    }

    session_start();
}

/**
 * Refresh session cookie expiry for active users.
 * This makes the cookie lifetime rolling instead of fixed from login time.
 */
function sf_refresh_session_cookie(): void
{
    global $config;

    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    $sessionCfg = is_array($config['session'] ?? null) ? $config['session'] : [];
    $lifetime   = (int)($sessionCfg['lifetime'] ?? 0);

    if ($lifetime <= 0) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);

    $params = session_get_cookie_params();

    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $lifetime,
            'path'     => $params['path'] ?? '/',
            'domain'   => $params['domain'] ?? '',
            'secure'   => $isHttps,
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    } else {
        setcookie(
            session_name(),
            session_id(),
            time() + $lifetime,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            $isHttps,
            (bool)($params['httponly'] ?? true)
        );
    }
}

/**
 * Luo uuden mysqli-yhteyden.
 */
function sf_db(): mysqli
{
    global $config;

    $db = $config['db'] ?? [];
    $mysqli = new mysqli(
        $db['host'] ?? 'localhost',
        $db['user'] ?? '',
        $db['pass'] ?? '',
        $db['name'] ?? ''
    );

    if ($mysqli->connect_error) {
        error_log('SafetyFlash DB connection error: ' . $mysqli->connect_error);
        http_response_code(503);
        die('Service temporarily unavailable. Please try again later.');
    }

    $mysqli->set_charset($db['charset'] ?? 'utf8mb4');
    return $mysqli;
}

/**
 * Aseta nykyinen käyttäjä sessioon ja synkkaa myös user_id-avain.
 *
 * Käytä tätä onnistuneen kirjautumisen jälkeen:
 *   sf_set_current_user($userRow);
 */
function sf_set_current_user(array $user): void
{
    $_SESSION['sf_user'] = $user;
    // Tämä avain on se, jota save_flash.php käyttää created_by:tä varten
    $_SESSION['user_id'] = $user['id'] ?? null;
}

/**
 * Palauta nykyinen käyttäjä sessiosta.
 * Samalla varmistetaan, että user_id-avain on synkassa.
 */
function sf_current_user(): ?array
{
    if (!isset($_SESSION['sf_user'])) {
        return null;
    }

    $user = $_SESSION['sf_user'];

    // Synkkaa user_id, jos sitä ei ole vielä asetettu
    if (!isset($_SESSION['user_id']) && isset($user['id'])) {
        $_SESSION['user_id'] = $user['id'];
    }

    return $user;
}

/**
 * Ohjaa oikeaan login-sivuun base_url:n mukaan.
 */
function sf_redirect_to_login(): void
{
    global $config;

    // base_url konfigista
    $base = rtrim($config['base_url'] ?? '', '/');

    // fallback — jos joku jättäisi base_urlin määrittelemättä
    if (!$base) {
        $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $base = $dir !== '/' ? $dir : '';
    }

    header('Location: ' . $base . '/assets/pages/login.php');
    exit;
}

/**
 * Vaadi kirjautuminen.
 *
 * Jos käyttäjä avaa sovelluksen sisäisen linkin kirjautumatta,
 * tallennetaan pyydetty osoite sessioon. Onnistuneen kirjautumisen
 * jälkeen login_process.php ohjaa käyttäjän takaisin tähän osoitteeseen.
 */
function sf_require_login(): void
{
    if (!sf_current_user()) {
        $requestedUrl = $_SERVER['REQUEST_URI'] ?? '';

        if ($requestedUrl !== '' && sf_is_safe_redirect_url($requestedUrl)) {
            $_SESSION['login_redirect'] = $requestedUrl;
        }

        sf_redirect_to_login();
    }
}

/**
 * Vaadi tietty rooli / roolilista.
 *
 * @param array<int> $allowedRoleIds
 */
function sf_require_role(array $allowedRoleIds): void
{
    $user = sf_current_user();
    if (!$user || !in_array((int)$user['role_id'], $allowedRoleIds, true)) {
        http_response_code(403);
        echo 'Ei käyttöoikeutta.';
        exit;
    }
}
function sf_current_user_has_role(string $roleName): bool {
    $user = sf_current_user();
    if (!$user || !isset($user['role_id'])) {
        return false;
    }
    
    // Mäppää rooli-id:t nimiin
    $roleMap = [
        1 => ['admin', 'Pääkäyttäjä'],
        2 => ['writer', 'Kirjoittaja'],
        3 => ['safety_team', 'Turvatiimi'],
        4 => ['comms', 'Viestintä']
    ];
    
    $userRoleId = (int)$user['role_id'];
    if (isset($roleMap[$userRoleId])) {
        return in_array($roleName, $roleMap[$userRoleId], true);
    }
    
    return false;
}
/**
 * Tarkista että käyttäjä on kirjautunut
 */
function auth_is_logged_in(): bool {
    return isset($_SESSION['sf_user']) && isset($_SESSION['sf_user']['id']);
}

/**
 * Tarkista että käyttäjällä on tietty rooli
 * @param array<string> $roles Esim. ['admin', 'writer']
 */
function auth_check_role(array $roles): bool {
    $user = sf_current_user();
    if (!$user) {
        return false;
    }
    
    $roleMap = [
        1 => 'admin',
        2 => 'writer',
        3 => 'safety_team',
        4 => 'comms'
    ];
    
    $userRole = $roleMap[(int)$user['role_id']] ?? null;
    return $userRole && in_array($userRole, $roles, true);
}

/**
 * Check if user is admin or safety team member
 * 
 * @return bool True if user has admin (1) or safety team (2) role
 */
function sf_is_admin_or_safety(): bool {
    $user = sf_current_user();
    if (!$user) {
        return false;
    }
    
    $roleId = (int)($user['role_id'] ?? 0);
    return in_array($roleId, [1, 2], true);
}

/**
 * Validate that a redirect URL is safe (internal, relative URL only)
 * Prevents open redirect vulnerabilities and path traversal attacks
 * 
 * @param string $url URL to validate
 * @return bool True if URL is safe to redirect to
 */
function sf_is_safe_redirect_url(string $url): bool
{
    if (empty($url)) {
        return false;
    }
    
    // Allow root path as a valid redirect
    if ($url === '/') {
        return true;
    }
    
    // Parse the URL to check its components
    $parsedUrl = parse_url($url);
    
    // URL must parse successfully
    if ($parsedUrl === false) {
        return false;
    }
    
    // Must not have scheme (http://, https://, etc.) or host (external domain)
    // Only relative URLs are allowed
    if (isset($parsedUrl['scheme']) || isset($parsedUrl['host'])) {
        return false;
    }
    
    // Must have a path component
    if (!isset($parsedUrl['path'])) {
        return false;
    }
    
    // Path must start with / (relative to root)
    if (strpos($parsedUrl['path'], '/') !== 0) {
        return false;
    }
    
    // Check for path traversal attacks (../)
    // Normalize the path and ensure it doesn't try to go above root
    $normalizedPath = str_replace('\\', '/', $parsedUrl['path']);
    $parts = explode('/', $normalizedPath);
    $safeparts = [];
    $depth = 0; // Track directory depth
    
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            // Going up a directory
            if ($depth > 0) {
                array_pop($safeparts);
                $depth--;
            } else {
                // Trying to go above root - reject
                return false;
            }
        } else {
            $safeparts[] = $part;
            $depth++;
        }
    }
    
    return true;
}

/**
 * Tarkista onko käyttäjällä tietty rooli (pää- tai lisärooli)
 *
 * @param int $userId Käyttäjän ID
 * @param int $roleId Roolin ID
 * @return bool True jos käyttäjällä on rooli
 */
function sf_user_has_role(int $userId, int $roleId): bool
{
    $pdo = Database::getInstance();
    
    // Tarkista päärooli
    $stmt = $pdo->prepare("SELECT role_id FROM sf_users WHERE id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && (int)$user['role_id'] === $roleId) {
        return true;
    }
    
    // Tarkista lisäroolit
    $stmt = $pdo->prepare("
        SELECT 1 FROM user_additional_roles 
        WHERE user_id = ? AND role_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $roleId]);
    
    return $stmt->fetchColumn() !== false;
}

/**
 * Hae käyttäjän kaikki roolit (päärooli + lisäroolit)
 *
 * @param int $userId Käyttäjän ID
 * @return array Rooli-ID:t
 */
function sf_get_user_roles(int $userId): array
{
    $pdo = Database::getInstance();
    $roles = [];
    
    // Hae päärooli
    $stmt = $pdo->prepare("SELECT role_id FROM sf_users WHERE id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['role_id']) {
        $roles[] = (int)$user['role_id'];
    }
    
    // Hae lisäroolit
    $stmt = $pdo->prepare("SELECT role_id FROM user_additional_roles WHERE user_id = ?");
    $stmt->execute([$userId]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $roles[] = (int)$row['role_id'];
    }
    
    return array_values(array_unique($roles));
}

/**
 * Lisää käyttäjälle lisärooli
 *
 * @param int $userId Käyttäjän ID
 * @param int $roleId Roolin ID
 * @param int|null $createdBy Lisääjän käyttäjä-ID
 * @return bool Onnistuiko
 */
function sf_add_user_role(int $userId, int $roleId, ?int $createdBy = null): bool
{
    $pdo = Database::getInstance();
    
    try {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO user_additional_roles (user_id, role_id, created_by, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        return $stmt->execute([$userId, $roleId, $createdBy]);
    } catch (Throwable $e) {
        sf_app_log("sf_add_user_role failed: " . $e->getMessage(), LOG_LEVEL_ERROR);
        return false;
    }
}

/**
 * Poista käyttäjältä lisärooli
 *
 * @param int $userId Käyttäjän ID
 * @param int $roleId Roolin ID
 * @return bool Onnistuiko
 */
function sf_remove_user_role(int $userId, int $roleId): bool
{
    $pdo = Database::getInstance();
    
    try {
        $stmt = $pdo->prepare("DELETE FROM user_additional_roles WHERE user_id = ? AND role_id = ?");
        return $stmt->execute([$userId, $roleId]);
    } catch (Throwable $e) {
        sf_app_log("sf_remove_user_role failed: " . $e->getMessage(), LOG_LEVEL_ERROR);
        return false;
    }
}

/**
 * Aseta käyttäjän lisäroolit (korvaa vanhat)
 *
 * @param int $userId Käyttäjän ID
 * @param array $roleIds Rooli-ID:t
 * @param int|null $createdBy Lisääjän käyttäjä-ID
 * @return bool Onnistuiko
 */
function sf_set_user_additional_roles(int $userId, array $roleIds, ?int $createdBy = null): bool
{
    $pdo = Database::getInstance();
    
    try {
        $pdo->beginTransaction();
        
        // Poista vanhat lisäroolit
        $stmt = $pdo->prepare("DELETE FROM user_additional_roles WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // Lisää uudet (deduplicate array to avoid unnecessary inserts)
        if (!empty($roleIds)) {
            $roleIds = array_unique(array_map('intval', $roleIds));
            
            $stmt = $pdo->prepare("
                INSERT INTO user_additional_roles (user_id, role_id, created_by, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            
            foreach ($roleIds as $roleId) {
                $stmt->execute([$userId, $roleId, $createdBy]);
            }
        }
        
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        sf_app_log("sf_set_user_additional_roles failed: " . $e->getMessage(), LOG_LEVEL_ERROR);
        return false;
    }
}