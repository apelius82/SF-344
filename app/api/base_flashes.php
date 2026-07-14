<?php
// safetyflash-system/app/api/base_flashes.php

declare(strict_types=1);

header('Content-Type:  application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

// Sallitaan vain GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Method not allowed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Vaadi kirjautuminen
$currentUser = sf_current_user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Authentication required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
// Tarkistetaan DB-konfiguraatio
if (empty($config['db']) || !is_array($config['db'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database configuration missing',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = $config['db'];

// Yhteys tietokantaan (sama logiikka kuin save_flash.php:ssä)
$mysqli = @new mysqli(
    $db['host'] ?? 'localhost',
    $db['user'] ?? '',
    $db['pass'] ?? '',
    $db['name'] ?? ''
);

if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database connection failed',
        'details' => $mysqli->connect_error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli->set_charset($db['charset'] ?? 'utf8mb4');

// Haetaan kaikki punaiset + keltaiset, joista voidaan tehdä tutkintatiedote
// Voit myöhemmin rajata esim. workflow_stateen tms. jos haluat.
$sql = "
    SELECT
        id,
        type,
        lang,
        title_short,
        summary,
        site,
        site_detail,
        occurred_at,
        description,
        current_status,
        root_causes,
        actions,
        state,
        status,
        created_at,
        updated_at,
        image_main,
        image_2,
        image_3
    FROM sf_flashes
    WHERE type IN ('red','yellow')
      AND state = 'published'
    ORDER BY created_at DESC
    LIMIT 200
";

$result = $mysqli->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Query failed',
        'details' => $mysqli->error,
    ], JSON_UNESCAPED_UNICODE);
    $mysqli->close();
    exit;
}

$rows = [];
while ($row = $result->fetch_assoc()) {

    // Muutetaan kantadatum (2025-11-21 10:41:00) HTML datetime-local -muotoon (2025-11-21T10:41)
    $occurred_at_input = null;
    if (!empty($row['occurred_at'])) {
        $occurred_at_input = str_replace(' ', 'T', substr($row['occurred_at'], 0, 16));
    }

    $rows[] = [
        'id'                => (int) $row['id'],
        'type'              => $row['type'],
        'lang'              => $row['lang'],
        'title_short'       => $row['title_short'],
        'summary'           => $row['summary'],
        'site'              => $row['site'],
        'site_detail'       => $row['site_detail'],
        'occurred_at'       => $row['occurred_at'],
        'occurred_at_input' => $occurred_at_input,
        'description'       => $row['description'],
        'current_status'    => $row['current_status'],
        'root_causes'       => $row['root_causes'],
        'actions'           => $row['actions'],
        'state'             => $row['state'],
        'status'            => $row['status'],
        'created_at'        => $row['created_at'],
        'updated_at'        => $row['updated_at'],
        'image_main'        => $row['image_main'],
        'image_2'           => $row['image_2'],
        'image_3'           => $row['image_3'],
    ];
}

$result->free();
$mysqli->close();

// Palautetaan pelkkä lista, juuri siinä muodossa mitä form.js odottaa
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);