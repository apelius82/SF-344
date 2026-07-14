<?php
// assets/pages/playlist_manager.php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';

// Allow admin and safety team (role_id 1 or 3)
$currentUser = sf_current_user();
$canManagePlaylist = $currentUser && in_array((int)($currentUser['role_id'] ?? 0), [1, 3], true);

$baseUrl = rtrim($config['base_url'] ?? '', '/');
$currentUiLang = $uiLang ?? ($_SESSION['ui_lang'] ?? 'fi');

require __DIR__ . '/../partials/playlist_manager.php';