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

$terms = [
    'page_title' => [
        'fi' => 'Salasanan palautus – Safetyflash',
        'sv' => 'Återställ lösenord – Safetyflash',
        'en' => 'Reset password – Safetyflash',
        'it' => 'Reimposta password – Safetyflash',
        'el' => 'Επαναφορά κωδικού – Safetyflash',
    ],
    'heading' => [
        'fi' => 'Palauta salasana',
        'sv' => 'Återställ lösenord',
        'en' => 'Reset password',
        'it' => 'Reimposta password',
        'el' => 'Επαναφορά κωδικού',
    ],
    'intro' => [
        'fi' => 'Syötä sähköpostiosoitteesi. Jos osoite löytyy järjestelmästä, lähetämme palautuslinkin sähköpostiisi.',
        'sv' => 'Ange din e-postadress. Om adressen finns i systemet skickar vi en återställningslänk till din e-post.',
        'en' => 'Enter your email address. If it exists in the system, we will send you a reset link.',
        'it' => 'Inserisci il tuo indirizzo email. Se esiste nel sistema, ti invieremo un link di ripristino.',
        'el' => 'Εισάγετε τη διεύθυνση email σας. Αν υπάρχει στο σύστημα, θα σας στείλουμε σύνδεσμο επαναφοράς.',
    ],
    'email_label' => [
        'fi' => 'Sähköposti',
        'sv' => 'E-post',
        'en' => 'Email',
        'it' => 'Email',
        'el' => 'Email',
    ],
    'email_placeholder' => [
        'fi' => 'esimerkki@tapojarvi.fi',
        'sv' => 'exempel@tapojarvi.fi',
        'en' => 'example@tapojarvi.fi',
        'it' => 'esempio@tapojarvi.fi',
        'el' => 'παράδειγμα@tapojarvi.fi',
    ],
    'submit_button' => [
        'fi' => 'Lähetä palautuslinkki',
        'sv' => 'Skicka återställningslänk',
        'en' => 'Send reset link',
        'it' => 'Invia link di ripristino',
        'el' => 'Αποστολή συνδέσμου επαναφοράς',
    ],
    'back_to_login' => [
        'fi' => 'Takaisin kirjautumiseen',
        'sv' => 'Tillbaka till inloggning',
        'en' => 'Back to login',
        'it' => 'Torna al login',
        'el' => 'Επιστροφή στη σύνδεση',
    ],
    'generic_success' => [
        'fi' => 'Jos sähköpostiosoite löytyy järjestelmästä, palautuslinkki on lähetetty.',
        'sv' => 'Om e-postadressen finns i systemet har en återställningslänk skickats.',
        'en' => 'If the email address exists in the system, a reset link has been sent.',
        'it' => 'Se l’indirizzo email esiste nel sistema, è stato inviato un link per il ripristino.',
        'el' => 'Αν η διεύθυνση email υπάρχει στο σύστημα, έχει σταλεί σύνδεσμος επαναφοράς.',
    ],
    'generic_error' => [
        'fi' => 'Palautuspyynnön lähetys ei onnistunut. Yritä uudelleen.',
        'sv' => 'Det gick inte att skicka återställningsbegäran. Försök igen.',
        'en' => 'Could not send the reset request. Please try again.',
        'it' => 'Impossibile inviare la richiesta di ripristino. Riprova.',
        'el' => 'Η αποστολή του αιτήματος επαναφοράς απέτυχε. Δοκιμάστε ξανά.',
    ],
];

function fp_term(string $key, string $lang, array $terms): string
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
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($uiLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars(fp_term('page_title', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?></title>
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
    max-width: 480px;
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
    margin-bottom: 24px;
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

.sf-login-success {
    background: #d1fae5;
    color: #065f46;
    padding: 14px 16px;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 24px;
    text-align: center;
    border: 1px solid #a7f3d0;
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
            <a href="?lang=<?php echo urlencode($langCode); ?>" class="sf-lang-btn <?php echo $langCode === $uiLang ? 'active' : ''; ?>">
                <img src="<?php echo htmlspecialchars($base . '/assets/img/' . $langData['icon'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($langData['label'], ENT_QUOTES, 'UTF-8'); ?>">
                <span><?php echo htmlspecialchars($langData['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="sf-login-card">
        <img src="<?php echo htmlspecialchars($base . '/assets/img/safetylogo.png', ENT_QUOTES, 'UTF-8'); ?>" class="sf-login-logo" alt="Safetyflash">

        <div class="sf-login-title"><?php echo htmlspecialchars(fp_term('heading', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?></div>
        <p class="sf-login-intro"><?php echo htmlspecialchars(fp_term('intro', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?></p>

        <?php if (!empty($_GET['sent'])): ?>
            <div class="sf-login-success">
                <?php echo htmlspecialchars(fp_term('generic_success', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($_GET['error'])): ?>
            <div class="sf-login-error">
                <?php echo htmlspecialchars(fp_term('generic_error', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo htmlspecialchars($base . '/app/api/password_forgot.php', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo sf_csrf_field(); ?>
            <input type="hidden" name="lang" value="<?php echo htmlspecialchars($uiLang, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="sf-field">
                <label class="sf-label" for="email"><?php echo htmlspecialchars(fp_term('email_label', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?></label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    class="sf-input"
                    placeholder="<?php echo htmlspecialchars(fp_term('email_placeholder', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?>"
                    autocomplete="email"
                    required
                >
            </div>

            <button class="sf-btn-login" type="submit">
                <?php echo htmlspecialchars(fp_term('submit_button', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?>
            </button>
        </form>

        <div style="text-align:center;">
            <a href="<?php echo htmlspecialchars($base . '/assets/pages/login.php?lang=' . urlencode($uiLang), ENT_QUOTES, 'UTF-8'); ?>" class="sf-secondary-link">
                <?php echo htmlspecialchars(fp_term('back_to_login', $uiLang, $terms), ENT_QUOTES, 'UTF-8'); ?>
            </a>
        </div>
    </div>
</div>
</body>
</html>