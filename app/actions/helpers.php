<?php
// app/actions/helpers.php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

function sf_get_pdo(): PDO {
    return Database::getInstance();
}

function sf_redirect(string $url): never {
    header("Location: $url");
    exit;
}

function sf_validate_id(): int {
    return max(0, (int)($_GET['id'] ?? 0));
}

/**
 * Update SafetyFlash state for all language versions
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId Any language version ID
 * @param string $newState New state value
 * @return int Number of rows updated
 */
function sf_update_state_all_languages(PDO $pdo, int $flashId, string $newState): int {
    // Fetch translation group info
    $stmt = $pdo->prepare("SELECT id, translation_group_id FROM sf_flashes WHERE id = :id");
    $stmt->execute([':id' => $flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$flash) {
        return 0;
    }
    
    // Determine group ID (parent or self)
    $groupId = $flash['translation_group_id'] ?: $flash['id'];
    
    // Update all language versions.
    // When moving to a terminal state (published/archived), update all versions.
    // Otherwise, skip versions already in a terminal state so they aren't pulled back.
    $terminalStates = ['published', 'archived', 'awaiting_publish'];
    if (in_array($newState, $terminalStates, true)) {
        $updateStmt = $pdo->prepare("
            UPDATE sf_flashes 
            SET state = :new_state, 
                updated_at = NOW()
            WHERE translation_group_id = :group_id1 OR id = :group_id2
        ");
        $updateStmt->execute([
            ':new_state' => $newState,
            ':group_id1' => $groupId,
            ':group_id2' => $groupId
        ]);
    } else {
        // Build named placeholders for terminal states to avoid mixing
        // named (:param) and positional (?) parameters, which PDO forbids
        $terminalPlaceholders = [];
        $terminalParams = [];
        foreach ($terminalStates as $i => $state) {
            $key = ':terminal_' . $i;
            $terminalPlaceholders[] = $key;
            $terminalParams[$key] = $state;
        }
        $placeholders = implode(', ', $terminalPlaceholders);

        $updateStmt = $pdo->prepare("
            UPDATE sf_flashes 
            SET state = :new_state, 
                updated_at = NOW()
            WHERE (translation_group_id = :group_id1 OR id = :group_id2)
              AND state NOT IN ($placeholders)
        ");
        $updateStmt->execute(array_merge([
            ':new_state'  => $newState,
            ':group_id1'  => $groupId,
            ':group_id2'  => $groupId,
        ], $terminalParams));
    }
    
    return $updateStmt->rowCount();
}

/**
 * Convert timestamp to relative time ("2h ago")
 * 
 * @param string $datetime MySQL datetime (YYYY-MM-DD HH:MM:SS)
 * @param string $lang Language code
 * @return string Relative time string
 */
function sf_time_ago(string $datetime, string $lang = 'fi'): string
{
    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return $datetime;
    }
    
    $diff = time() - $timestamp;
    
    // Juuri nyt (alle 60s)
    if ($diff < 60) {
        $translations = [
            'fi' => 'juuri nyt',
            'sv' => 'just nu',
            'en' => 'just now',
            'it' => 'proprio ora',
            'el' => 'μόλις τώρα',
        ];
        return $translations[$lang] ?? $translations['en'];
    }
    
    // Minuutit (1-59 min)
    if ($diff < 3600) {
        $mins = floor($diff / 60);
        $units = [
            'fi' => $mins . ' min sitten',
            'sv' => $mins . ' min sedan',
            'en' => $mins . ' min ago',
            'it' => $mins . ' min fa',
            'el' => $mins . ' λεπτά πριν',
        ];
        return $units[$lang] ?? $units['en'];
    }
    
    // Tunnit (1-23h)
    if ($diff < 86400) {
        $hours = floor($diff / 3600);
        $units = [
            'fi' => $hours . 'h sitten',
            'sv' => $hours . 'h sedan',
            'en' => $hours . 'h ago',
            'it' => $hours . 'h fa',
            'el' => $hours . 'ώ πριν',
        ];
        return $units[$lang] ?? $units['en'];
    }
    
    // ✅ YLI 24H -> Aina täsmäaika kellonaikoineen
    return date('d.m.Y H:i', $timestamp);
}


/**
 * Get flag emoji for language code
 *
 * @param string $lang Language code
 * @return string Flag emoji
 */
if (!function_exists('sf_lang_flag')) {
    function sf_lang_flag(string $lang): string {
        return match($lang) {
            'fi' => '🇫🇮',
            'sv' => '🇸🇪',
            'en' => '🇬🇧',
            'it' => '🇮🇹',
            'el' => '🇬🇷',
            default => '🏳️',
        };
    }
}

/**
 * Get flash language with default fallback
 * 
 * @param array $flash Flash data array
 * @return string Language code (fi, sv, en, it, el)
 */
function sf_get_flash_lang(array $flash): string {
    return !empty($flash['lang']) ? $flash['lang'] : 'fi';
}