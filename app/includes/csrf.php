<?php
// app/includes/csrf.php
// CSRF-suojaus

/**
 * Generoi CSRF-token ja tallenna sessioon
 */
function sf_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Palauttaa piilotetun input-kentän CSRF-tokenilla
 */
function sf_csrf_field(): string {
    $token = sf_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' .htmlspecialchars($token, ENT_QUOTES, 'UTF-8') .'">';
}

/**
 * Validoi CSRF-token
 */
function sf_csrf_validate(? string $token = null): bool {
    if ($token === null) {
        // Check X-CSRF-Token header first (for fetch/AJAX requests)
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    }
    
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Tarkista CSRF ja keskeytä jos virheellinen (legacy-käytös).
 *
 * HUOM: Useat endpointit kutsuvat sf_csrf_check():ä odottaen, että se
 * pysäyttää pyynnön epäonnistuessa (esim. save_flash.php).
 */
function sf_csrf_check(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!sf_csrf_validate()) {
            http_response_code(403);
            $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
            if (function_exists('sf_term')) {
                die(sf_term('error_csrf_invalid', $currentUiLang));
            } else {
                die('Security validation failed. Please refresh the page and try again.');
            }
        }
    }
}

/**
 * Soft-check: palauta true/false mutta älä keskeytä.
 * Käytä jos haluat itse käsitellä virheen.
 */
function sf_csrf_check_soft(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        return sf_csrf_validate();
    }
    return true;
}

/**
 * Tarkista CSRF ja palauta JSON-virhe jos epäonnistuu.
 * (Sopii fetch()/API-kutsuihin.)
 */
function sf_csrf_check_strict(): void {
    if (!sf_csrf_check_soft()) {
        http_response_code(403);

        // Best-effort logging without hard dependency on bootstrap.php
        if (class_exists('SecurityEventLogger')) {
            SecurityEventLogger::log('csrf_validation_failed', [
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'request_uri'    => $_SERVER['REQUEST_URI'] ?? ''
            ], 'warning');
        } elseif (function_exists('sf_app_log')) {
            sf_app_log('csrf_validation_failed', LOG_LEVEL_WARNING, [
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'request_uri'    => $_SERVER['REQUEST_URI'] ?? ''
            ]);
        } else {
            error_log('csrf_validation_failed: ' . ($_SERVER['REQUEST_URI'] ?? ''));
        }

        header('Content-Type: application/json; charset=utf-8');
        die(json_encode([
            'success' => false,
            'error'   => 'Security token validation failed'
        ], JSON_UNESCAPED_UNICODE));
    }
}

/**
 * Uudista CSRF-token (käytä kirjautumisen jälkeen)
 */
function sf_csrf_regenerate(): string {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
/**
 * Hae CSRF-token HTML-attribuuttina (data-*)
 * Käytä JavaScriptissa: fetch() kutsun headers-osiossa
 */
function sf_csrf_token_attr(): string {
    $token = sf_csrf_token();
    return 'data-csrf-token="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"';
}

/**
 * Hae CSRF-token JSON-muodossa (API-kutsuja varten)
 */
function sf_csrf_token_json(): string {
    $token = sf_csrf_token();
    return json_encode([
        'csrf_token' => $token,
        'header_name' => 'X-CSRF-Token',
        'form_name' => '_csrf_token'
    ]);
}