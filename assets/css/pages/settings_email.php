<?php
// assets/pages/settings_email.php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';
require_once __DIR__ . '/../../app/includes/db.php'; // tai helpers.php, jos sf_get_pdo siellä

// Tarkista rooli (säädä oma funktio/roolinimi)
if (!function_exists('sf_current_user_has_role') || !sf_current_user_has_role('admin')) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$pdo  = sf_get_pdo();
$base = rtrim($config['base_url'] ?? '', '/');

// Pieni apufunktio asetusten lukemiseen
function sf_settings_get(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM sf_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)$row['setting_value'] : $default;
}

function sf_settings_set(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("
        INSERT INTO sf_settings (setting_key, setting_value)
        VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([':k' => $key, ':v' => $value]);
}

// Käsittele POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $host       = trim($_POST['smtp_host'] ?? '');
    $port       = trim($_POST['smtp_port'] ?? '');
    $encryption = trim($_POST['smtp_encryption'] ?? 'tls');
    $username   = trim($_POST['smtp_username'] ?? '');
    $fromEmail  = trim($_POST['smtp_from_email'] ?? '');
    $fromName   = trim($_POST['smtp_from_name'] ?? '');
    $password   = trim($_POST['smtp_password'] ?? '');

    sf_settings_set($pdo, 'smtp_host', $host);
    sf_settings_set($pdo, 'smtp_port', $port);
    sf_settings_set($pdo, 'smtp_encryption', $encryption);
    sf_settings_set($pdo, 'smtp_username', $username);
    sf_settings_set($pdo, 'smtp_from_email', $fromEmail);
    sf_settings_set($pdo, 'smtp_from_name', $fromName);

    // Salasana: päivitetään vain jos syötetty ei ole tyhjä
    if ($password !== '') {
        sf_settings_set($pdo, 'smtp_password', $password);
    }

    header("Location: {$base}/index.php?page=settings_email&saved=1");
    exit;
}

// GET: näytä lomake
$current = [
    'host'       => sf_settings_get($pdo, 'smtp_host', ''),
    'port'       => sf_settings_get($pdo, 'smtp_port', '587'),
    'encryption' => sf_settings_get($pdo, 'smtp_encryption', 'tls'),
    'username'   => sf_settings_get($pdo, 'smtp_username', ''),
    'from_email' => sf_settings_get($pdo, 'smtp_from_email', 'no-reply@tapojarvi.online'),
    'from_name'  => sf_settings_get($pdo, 'smtp_from_name', 'Safetyflash'),
    // salasanaa ei näytetä
];

?>
<div class="sf-container">
    <h1 class="sf-page-title">SMTP-asetukset</h1>

    <?php if (isset($_GET['saved'])): ?>
        <p style="color: green;">Asetukset tallennettu.</p>
    <?php endif; ?>

    <form method="post">
        <label>
            SMTP host
            <input type="text" name="smtp_host" value="<?= htmlspecialchars($current['host']) ?>" required>
        </label>

        <label>
            SMTP portti
            <input type="number" name="smtp_port" value="<?= htmlspecialchars($current['port']) ?>" required>
        </label>

        <label>
            Salaus
            <select name="smtp_encryption">
                <option value="none" <?= $current['encryption'] === 'none' ? 'selected' : '' ?>>Ei salausta</option>
                <option value="tls"  <?= $current['encryption'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                <option value="ssl"  <?= $current['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
            </select>
        </label>

        <label>
            SMTP käyttäjätunnus
            <input type="text" name="smtp_username" value="<?= htmlspecialchars($current['username']) ?>">
        </label>

        <label>
            SMTP salasana (jätä tyhjäksi jos et halua muuttaa)
            <input type="password" name="smtp_password" value="">
        </label>

        <label>
            Lähettäjän sähköposti (From)
            <input type="email" name="smtp_from_email" value="<?= htmlspecialchars($current['from_email']) ?>" required>
        </label>

        <label>
            Lähettäjän nimi (From-nimi)
            <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($current['from_name']) ?>" required>
        </label>

        <button type="submit" class="sf-btn sf-btn-primary">Tallenna</button>
    </form>
</div>