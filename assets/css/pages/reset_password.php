<?php
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../assets/logs/php_errors.log');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/csrf.php';
require_once __DIR__ . '/../lib/sf_terms.php';

if (sf_current_user()) {
    header('Location: ' . rtrim($config['base_url'], '/') . '/index.php?page=list');
    exit;
}

$base = rtrim($config['base_url'], '/');

$uiLang = $_GET['lang'] ?? $_COOKIE['ui_lang'] ?? 'fi';
$supportedLangs = ['fi', 'sv', 'en', 'it', 'el'];
if (!in_array($uiLang, $supportedLangs, true)) {
    $uiLang = 'fi';
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

$terms = [
    'page_title' => [
        'fi' => 'Aseta uusi salasana – Safetyflash',
        'sv' => 'Ange nytt lösenord – Safetyflash',
        'en' => 'Set new password – Safetyflash',
        'it' => 'Imposta nuova password – Safetyflash',
        'el' => 'Ορισμός νέου κωδικού – Safetyflash',
    ],
    'heading' => [
        'fi' => 'Aseta uusi salasana',
        'sv' => 'Ange nytt lösenord',
        'en' => 'Set a new password',
        'it' => 'Imposta una nuova password',
        'el' => 'Ορίστε νέο κωδικό',
    ],
    'intro' => [
        'fi' => 'Syötä uusi salasana. Salasanan tulee olla vähintään 8 merkkiä pitkä.',
        'sv' => 'Ange ett nytt lösenord. Lösenordet måste vara minst 8 tecken långt.',
        'en' => 'Enter your new password. The password must be at least 8 characters long.',
        'it' => 'Inserisci una nuova password. La password deve contenere almeno 8 caratteri.',
        'el' => 'Εισάγετε νέο κωδικό. Ο κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.',
    ],
    'new_password' => [
        'fi' => 'Uusi salasana',
        'sv' => 'Nytt lösenord',
        'en' => 'New password',
        'it' => 'Nuova password',
        'el' => 'Νέος κωδικός',
    ],
    'confirm_password' => [
        'fi' => 'Vahvista uusi salasana',
        'sv' => 'Bekräfta nytt lösenord',
        'en' => 'Confirm new password',
        'it' => 'Conferma nuova password',
        'el' => 'Επιβεβαίωση νέου κωδικού',
    ],
    'submit_button' => [
        'fi' => 'Tallenna uusi salasana',
        'sv' => 'Spara nytt lösenord',
        'en' => 'Save new password',
        'it' => 'Salva nuova password',
        'el' => 'Αποθήκευση νέου κωδικού',
    ],
    'back_to_login' => [
        'fi' => 'Takaisin kirjautumiseen',
        'sv' => 'Tillbaka till inloggning',
        'en' => 'Back to login',
        'it' => 'Torna al login',
        'el' => 'Επιστροφή στη σύνδεση',
    ],
    'invalid_link' => [
        'fi' => 'Palautuslinkki on virheellinen tai vanhentunut.',
        'sv' => 'Återställningslänken är ogiltig eller har löpt ut.',
        'en' => 'The reset link is invalid or has expired.',
        'it' => 'Il link di ripristino non è valido o è scaduto.',
        'el' => 'Ο σύνδεσμος επαναφοράς δεν είναι έγκυρος ή έχει λήξει.',
    ],
    'password_mismatch' => [
        'fi' => 'Salasanat eivät täsmää.',
        'sv' => 'Lösenorden matchar inte.',
        'en' => 'Passwords do not match.',
        'it' => 'Le password non corrispondono.',
        'el' => 'Οι κωδικοί δεν ταιριάζουν.',
    ],
    'password_too_short' => [
        'fi' => 'Salasanan tulee olla vähintään 8 merkkiä pitkä.',
        'sv' => 'Lösenordet måste vara minst 8 tecken långt.',
        'en' => 'The password must be at least 8 characters long.',
        'it' => 'La password deve contenere almeno 8 caratteri.',
        'el' => 'Ο κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.',
    ],
    'generic_error' => [
        'fi' => 'Salasanan vaihto ei onnistunut. Yritä uudelleen.',
        'sv' => 'Lösenordsändringen misslyckades. Försök igen.',
        'en' => 'Could not change the password. Please try again.',
        'it' => 'Impossibile cambiare la password. Riprova.',
        'el' => 'Η αλλαγή κωδικού απέτυχε. Δοκιμάστε ξανά.',
    ],
];

function rp_term(string $key, string $lang, array $terms): string
{
    return $terms[$key][$lang] ?? $terms[$key]['en'] ?? $key;
}

$langFlags = [
    'fi' => ['label' => 'FI', 'icon' => 'finnish-flag.png'],
    'sv' => ['label' => 'SV', 'icon' => 'swedish-flag.png'],
    'en' => ['label' => 'EN', 'icon' => 'english-flag.png'],
    'it' => ['label' => 'IT', 'icon' => 'italian-flag.png'],
    'el' => ['label' => 'EL', 'icon' => 'greece-flag.png'],
];

$tokenIsValid = false;

if ($token !== '') {
    $mysqli = sf_db();
    $tokenHash = hash('sha256', $token);

    $stmt = $mysqli->prepare(
        'SELECT id
         FROM sf_password_resets
         WHERE token_hash = ?
           AND used_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );

    if ($stmt) {
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $result = $stmt->get_result();
        $tokenIsValid = (bool)($result && $result->fetch_assoc());
        $stmt->close();
    }

    $mysqli->close();
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($uiLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars(rp_term('page_title', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="stylesheet" href="<?php echo sf_asset_url('assets/css/global.css', $base); ?>">
<style>
* {
    box-sizing: border-box;
}

body {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    margin: 0;
    padding: 0;
    font-family: 'Open Sans', sans-serif;
    min-height: 100vh;
}

.sf-login-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 16px;
}

.sf-login-card {
    background: #ffffff;
    width: 100%;
    max-width: 520px;
    padding: 48px 40px;
    border-radius: 24px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.sf-login-logo {
    display: block;
    margin: 0 auto 24px;
    width: 180px;
    max-width: 60%;
    height: auto;
    object-fit: contain;
}

.sf-login-title {
    text-align: center;
    margin-bottom: 12px;
    font-size: 1.5rem;
    font-weight: 700;
    color: #111827;
    line-height: 1.3;
}

.sf-login-intro {
    text-align: center;
    margin: 0 0 28px;
    color: #4b5563;
    line-height: 1.6;
    font-size: 0.98rem;
}

.sf-field {
    margin-bottom: 20px;
}

.sf-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 1rem;
    color: #374151;
}

.sf-input {
    width: 100%;
    padding: 16px 18px;
    border-radius: 12px;
    border: 2px solid #e5e7eb;
    font-size: 1.05rem;
    background: #f9fafb;
}

.sf-input:focus {
    border-color: #fee000;
    outline: none;
    box-shadow: 0 0 0 4px rgba(254, 224, 0, 0.3);
    background: #ffffff;
}

.sf-btn-login {
    width: 100%;
    padding: 18px;
    background: #fee000;
    border: none;
    border-radius: 14px;
    font-size: 1.05rem;
    font-weight: 700;
    color: #111827;
    cursor: pointer;
    transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
}

.sf-btn-login:hover {
    background: #fcd34d;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(254, 224, 0, 0.4);
}

.sf-secondary-link {
    display: inline-block;
    margin-top: 18px;
    color: #1f2937;
    text-decoration: none;
    font-weight: 600;
}

.sf-secondary-link:hover {
    text-decoration: underline;
}

.sf-login-error {
    background: #fee2e2;
    color: #991b1b;
    padding: 14px 16px;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 24px;
    text-align: center;
    border: 1px solid #fecaca;
}

.sf-lang-switcher {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.sf-lang-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 20px;
    color: #ffffff;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s;
}

.sf-lang-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    border-color: rgba(255, 255, 255, 0.4);
}

.sf-lang-btn.active {
    background: #fee000;
    border-color: #fee000;
    color: #111827;
}

.sf-lang-btn img {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    object-fit: cover;
}

@media (max-width: 600px) {
    .sf-login-card {
        padding: 36px 28px;
        border-radius: 20px;
    }
}

@media (max-width: 400px) {
    .sf-login-card {
        padding: 28px 20px;
        border-radius: 16px;
    }
}
</style>
</head>
<body>
<div class="sf-login-wrapper">
    <div class="sf-lang-switcher">
        <?php foreach ($langFlags as $langCode => $langData): ?>
            <a href="?lang=<?php echo urlencode($langCode); ?>&token=<?php echo urlencode($token); ?>" class="sf-lang-btn <?php echo $langCode === $uiLang ? 'active' : ''; ?>">
                <img src="<?php echo htmlspecialchars($base . '/assets/img/' . $langData['icon'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($langData['label'], ENT_QUOTES, 'UTF-8'); ?>">
                <span><?php echo htmlspecialchars($langData['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="sf-login-card">
        <img src="<?php echo htmlspecialchars($base . '/assets/img/safetylogo.png', ENT_QUOTES, 'UTF-8'); ?>" class="sf-login-logo" alt="Safetyflash">

        <div class="sf-login-title"><?php echo htmlspecialchars(rp_term('heading', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?></div>

        <?php if (!$tokenIsValid): ?>
            <div class="sf-login-error">
                <?php echo htmlspecialchars(rp_term('invalid_link', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <div style="text-align:center;">
                <a href="<?php echo htmlspecialchars($base . '/assets/pages/login.php?lang=' . urlencode($uiLang), ENT_QUOTES, 'UTF-8'); ?>" class="sf-secondary-link">
                    <?php echo htmlspecialchars(rp_term('back_to_login', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?>
                </a>
            </div>
        <?php else: ?>
            <p class="sf-login-intro"><?php echo htmlspecialchars(rp_term('intro', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?></p>

            <?php if (!empty($_GET['error'])): ?>
                <div class="sf-login-error">
                    <?php
                    $errorKey = (string)$_GET['error'];
                    if ($errorKey === 'mismatch') {
                        echo htmlspecialchars(rp_term('password_mismatch', $uiLang, $terms), ENT_QUOTES, 'UTF-8');
                    } elseif ($errorKey === 'short') {
                        echo htmlspecialchars(rp_term('password_too_short', $uiLang, $terms), ENT_QUOTES, 'UTF-8');
                    } elseif ($errorKey === 'invalid') {
                        echo htmlspecialchars(rp_term('invalid_link', $uiLang, $terms), ENT_QUOTES, 'UTF-8');
                    } else {
                        echo htmlspecialchars(rp_term('generic_error', $uiLang, $terms), ENT_QUOTES, 'UTF-8');
                    }
                    ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo htmlspecialchars($base . '/app/api/password_reset.php', ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo sf_csrf_field(); ?>
                <input type="hidden" name="lang" value="<?php echo htmlspecialchars($uiLang, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="sf-field">
                    <label class="sf-label" for="new_password"><?php echo htmlspecialchars(rp_term('new_password', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="password" name="new_password" id="new_password" class="sf-input" minlength="8" required>
                </div>

                <div class="sf-field">
                    <label class="sf-label" for="confirm_password"><?php echo htmlspecialchars(rp_term('confirm_password', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="password" name="confirm_password" id="confirm_password" class="sf-input" minlength="8" required>
                </div>

                <button class="sf-btn-login" type="submit">
                    <?php echo htmlspecialchars(rp_term('submit_button', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?>
                </button>
            </form>

            <div style="text-align:center;">
                <a href="<?php echo htmlspecialchars($base . '/assets/pages/login.php?lang=' . urlencode($uiLang), ENT_QUOTES, 'UTF-8'); ?>" class="sf-secondary-link">
                    <?php echo htmlspecialchars(rp_term('back_to_login', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>