<?php
// app/api/users_create.php
declare(strict_types=1);

// Ladataan konfiguraatio ja suojaukset
// HUOM: protect.php hoitaa session_start(), auth-tarkistuksen JA CSRF-validoinnin
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/services/email_services.php';

header('Content-Type: application/json; charset=utf-8');

// Vain pääkäyttäjä (role_id = 1)
sf_require_role([1]);

// Vain POST-metodi (protect.php on jo tarkastanut CSRF:n)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

/**
 * Generoi satunnainen salasana turvallisesti
 */
function sf_generate_random_password(int $length = 10): string
{
    // Merkkivalikoima: ei sekaantuvia merkkejä (I/l/1, O/0)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $charsLength = strlen($chars);
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $charsLength - 1)];
    }

    return $password;
}

// Yhdistä tietokantaan
$mysqli = sf_db();
if (!$mysqli) {
    sf_app_log('users_create: Tietokantayhteys epäonnistui', LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Tietokantavirhe']);
    exit;
}

// Lue lomakedata
$first = trim($_POST['first_name'] ?? '');
$last  = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$role  = (int)($_POST['role_id'] ?? 0);

// Kieli (valinnainen, oletus 'fi')
$uiLang = trim($_POST['ui_lang'] ?? 'fi');
$validLangs = ['fi', 'sv', 'en', 'it', 'el'];
if (!in_array($uiLang, $validLangs, true)) {
    $uiLang = 'fi';
}

// Roolikategoriat (valinnainen)
$roleCategoryIds = $_POST['role_category_ids'] ?? [];
if (!is_array($roleCategoryIds)) {
    $roleCategoryIds = [];
}
$roleCategoryIds = array_values(array_unique(array_map('intval', $roleCategoryIds)));
$roleCategoryIds = array_values(array_filter($roleCategoryIds, fn($v) => (int)$v > 0));

// Optional: multi roles (role_ids[])
$roleIds = $_POST['role_ids'] ?? [];
if (!is_array($roleIds)) {
    $roleIds = [$roleIds];
}
$roleIds = array_values(array_unique(array_map('intval', $roleIds)));
// Ensure primary role included
if ($role > 0 && !in_array($role, $roleIds, true)) {
    $roleIds[] = $role;
}
$roleIds = array_values(array_filter($roleIds, fn($v) => (int)$v > 0));

// Kotityömaa (valinnainen)
$homeWorksiteId = $_POST['home_worksite_id'] ?? '';
if ($homeWorksiteId === '' || $homeWorksiteId === null) {
    $homeWorksiteId = null;
} else {
    $homeWorksiteId = (int)$homeWorksiteId;
    if ($homeWorksiteId <= 0) {
        $homeWorksiteId = null;
    }
}

// Validoi pakolliset kentät
if ($first === '' || $last === '' || $email === '' || $role <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Täytä kaikki pakolliset kentät']);
    exit;
}

// Validoi sähköpostin muoto
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Virheellinen sähköpostiosoite']);
    exit;
}

// Tarkista onko sähköposti jo olemassa (aktiivinen tai poistettu)
$existingId = null;
$existingActive = null;

$stmt = $mysqli->prepare('SELECT id, is_active FROM sf_users WHERE email = ? LIMIT 1');
if (!$stmt) {
    sf_app_log('users_create: prepare epäonnistui (email check): ' . $mysqli->error, LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Tietokantavirhe']);
    exit;
}
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->bind_result($tmpId, $tmpActive);
if ($stmt->fetch()) {
    $existingId = (int)$tmpId;
    $existingActive = (int)$tmpActive;
}
$stmt->close();

if ($existingId !== null && $existingActive === 1) {
    echo json_encode(['ok' => false, 'error' => 'Tällä sähköpostilla on jo käyttäjä']);
    exit;
}

// Generoi salasana automaattisesti
$generatedPassword = sf_generate_random_password(10);
$hash = password_hash($generatedPassword, PASSWORD_DEFAULT);

// Päätös: luodaanko uusi vai aktivoidaanko poistettu
$newUserId = null;
$reactivated = false;

if ($existingId !== null && $existingActive === 0) {
    // REACTIVATE: estää UNIQUE(email) duplikaatit
    if ($homeWorksiteId === null) {
        $stmt = $mysqli->prepare(
            'UPDATE sf_users
             SET first_name=?, last_name=?, role_id=?, home_worksite_id=NULL, ui_lang=?, password_hash=?, is_active=1, updated_at=NOW()
             WHERE id=?'
        );
        if (!$stmt) {
            sf_app_log('users_create: prepare epäonnistui (reactivate): ' . $mysqli->error, LOG_LEVEL_ERROR);
            echo json_encode(['ok' => false, 'error' => 'Käyttäjän aktivointi epäonnistui']);
            exit;
        }
        $stmt->bind_param('ssissi', $first, $last, $role, $uiLang, $hash, $existingId);
    } else {
        $stmt = $mysqli->prepare(
            'UPDATE sf_users
             SET first_name=?, last_name=?, role_id=?, home_worksite_id=?, ui_lang=?, password_hash=?, is_active=1, updated_at=NOW()
             WHERE id=?'
        );
        if (!$stmt) {
            sf_app_log('users_create: prepare epäonnistui (reactivate+worksite): ' . $mysqli->error, LOG_LEVEL_ERROR);
            echo json_encode(['ok' => false, 'error' => 'Käyttäjän aktivointi epäonnistui']);
            exit;
        }
        $stmt->bind_param('ssiissi', $first, $last, $role, $homeWorksiteId, $uiLang, $hash, $existingId);
    }

    if (!$stmt->execute()) {
        sf_app_log('users_create: REACTIVATE epäonnistui: ' . $stmt->error, LOG_LEVEL_ERROR);
        $stmt->close();
        echo json_encode(['ok' => false, 'error' => 'Käyttäjän aktivointi epäonnistui']);
        exit;
    }
    $stmt->close();

    $newUserId = $existingId;
    $reactivated = true;

} else {
    // INSERT: uusi käyttäjä
    if ($homeWorksiteId === null) {
        $stmt = $mysqli->prepare(
            'INSERT INTO sf_users (first_name, last_name, email, role_id, home_worksite_id, ui_lang, password_hash, is_active, created_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?, 1, NOW())'
        );
        if (!$stmt) {
            sf_app_log('users_create: prepare epäonnistui (insert): ' . $mysqli->error, LOG_LEVEL_ERROR);
            echo json_encode(['ok' => false, 'error' => 'Käyttäjän luonti epäonnistui']);
            exit;
        }
        $stmt->bind_param('sssiss', $first, $last, $email, $role, $uiLang, $hash);
    } else {
        $stmt = $mysqli->prepare(
            'INSERT INTO sf_users (first_name, last_name, email, role_id, home_worksite_id, ui_lang, password_hash, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())'
        );
        if (!$stmt) {
            sf_app_log('users_create: prepare epäonnistui (insert+worksite): ' . $mysqli->error, LOG_LEVEL_ERROR);
            echo json_encode(['ok' => false, 'error' => 'Käyttäjän luonti epäonnistui']);
            exit;
        }
        $stmt->bind_param('sssiiss', $first, $last, $email, $role, $homeWorksiteId, $uiLang, $hash);
    }

    if (!$stmt->execute()) {
        sf_app_log('users_create: INSERT epäonnistui: ' . $stmt->error, LOG_LEVEL_ERROR);
        $stmt->close();
        echo json_encode(['ok' => false, 'error' => 'Käyttäjän luonti epäonnistui']);
        exit;
    }

    $newUserId = (int)$mysqli->insert_id; // varmin tapa
    $stmt->close();
}

// Sync roles to sf_user_roles jos taulu on olemassa
$rolesSynced = false;
$warning = null;

$chk = $mysqli->query("SHOW TABLES LIKE 'sf_user_roles'");
$tableExists = ($chk && $chk->num_rows > 0);
if ($chk) $chk->free();

if ($tableExists && $newUserId) {
    if (empty($roleIds) && $role > 0) {
        $roleIds = [$role];
    }

    // Jos reaktivoidaan, tyhjennä vanhat roolit ja kirjoita uudet
    $del = $mysqli->prepare('DELETE FROM sf_user_roles WHERE user_id = ?');
    if ($del) {
        $del->bind_param('i', $newUserId);
        $del->execute();
        $del->close();
    }

    $ins = $mysqli->prepare('INSERT IGNORE INTO sf_user_roles (user_id, role_id) VALUES (?, ?)');
    if ($ins) {
        foreach ($roleIds as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0) continue;
            $ins->bind_param('ii', $newUserId, $rid);
            $ins->execute();
        }
        $ins->close();
        $rolesSynced = true;
    } else {
        $warning = 'Role sync prepare failed';
        sf_app_log('users_create: role sync prepare failed: ' . $mysqli->error, LOG_LEVEL_WARNING);
    }
} elseif (!$tableExists) {
    $warning = 'sf_user_roles table missing (multi-role not saved)';
}

// Tallenna roolikategoriat
if (!empty($roleCategoryIds) && $newUserId) {
    // Tyhjennä vanhat
    $delStmt = $mysqli->prepare("DELETE FROM user_role_categories WHERE user_id = ?");
    $delStmt->bind_param('i', $newUserId);
    $delStmt->execute();
    $delStmt->close();
    
    // Lisää uudet
    $stmt = $mysqli->prepare('INSERT IGNORE INTO user_role_categories (user_id, role_category_id) VALUES (?, ?)');
    if ($stmt) {
        foreach ($roleCategoryIds as $catId) {
            $stmt->bind_param('ii', $newUserId, $catId);
            $stmt->execute();
        }
        $stmt->close();
    }
}

// Lähetä tervetulosähköposti
$emailSent = false;
try {
    // Create PDO connection for email service
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $emailSent = sf_mail_welcome_new_user($pdo, $newUserId, $generatedPassword);

    if ($emailSent) {
        sf_app_log("users_create: Käyttäjä " . ($reactivated ? "aktivoitu" : "luotu") . " (id=$newUserId), welcome email lähetetty osoitteeseen $email", LOG_LEVEL_INFO);
    } else {
        sf_app_log("users_create: Käyttäjä " . ($reactivated ? "aktivoitu" : "luotu") . " (id=$newUserId), mutta sähköpostin lähetys epäonnistui", LOG_LEVEL_WARNING);
    }
} catch (Throwable $e) {
    sf_app_log("users_create: Email exception: " . $e->getMessage(), LOG_LEVEL_ERROR);
    $emailSent = false;
}

$mysqli->close();

$out = [
    'ok' => true,
    'id' => $newUserId,
    'password_sent' => $emailSent,
    'reactivated' => $reactivated,
    'roles_synced' => $rolesSynced
];
if ($warning) $out['warning'] = $warning;

echo json_encode($out);
exit;