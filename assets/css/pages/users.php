<?php
// This page has been moved to Settings > Users
// Redirect to the new location
require_once __DIR__ . '/../../config.php';

$baseUrl = defined('SF_BASE_URL') ? SF_BASE_URL : '';
header('Location: ' . $baseUrl . '/index.php?page=settings&tab=users');
exit;