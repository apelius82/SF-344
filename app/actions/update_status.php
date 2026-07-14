<?php
// app/actions/update.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$id = sf_validate_id();
$pdo = sf_get_pdo();

// Tähän tulee myöhemmin kenttien validointi ja päivitys.
// Tarvitaan kun tehdään edit-lomake view.php:hen.

sf_redirect($config['base_url'] . "/index.php?page=view&id=$id&notice=updated");