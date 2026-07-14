<?php
// Aseta error logging
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../assets/logs/php_errors.log');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ .  '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/csrf.php';
require_once __DIR__ . '/../lib/sf_terms.php';

// Jos jo kirjautunut -> ohjaa etusivulle
if (sf_current_user()) {
    header('Location: ' . rtrim($config['base_url'], '/') . '/index.php');
    exit;
}

$base = rtrim($config['base_url'], '/');

// Kielivalinta (oletuksena fi)
$uiLang = $_GET['lang'] ?? $_COOKIE['ui_lang'] ?? 'fi';
$supportedLangs = ['fi', 'sv', 'en', 'it', 'el'];
if (!in_array($uiLang, $supportedLangs)) {
    $uiLang = 'fi';
}
// Käännökset login-sivulle
$loginTerms = [
    'page_title' => [
        'fi' => 'Kirjaudu – Safetyflash',
        'sv' => 'Logga in – Safetyflash',
        'en' => 'Login – Safetyflash',
        'it' => 'Accedi – Safetyflash',
        'el' => 'Σύνδεση – Safetyflash',
    ],

    'logged_out_message' => [
        'fi' => 'Olet kirjautunut ulos. Tervetuloa takaisin!',
        'sv' => 'Du har loggat ut. Välkommen tillbaka!',
        'en' => 'You have been logged out. Welcome back!',
        'it' => 'Sei stato disconnesso. Bentornato!',
        'el' => 'Αποσυνδεθήκατε. Καλώς ήρθατε πίσω!',
    ],

    'login_heading' => [
        'fi' => 'Kirjaudu Safetyflash-järjestelmään',
        'sv' => 'Logga in på Safetyflash',
        'en' => 'Log in to Safetyflash',
        'it' => 'Accedi a Safetyflash',
        'el' => 'Σύνδεση στο Safetyflash',
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
        'it' => 'esempio@tapojarvi.fi', // ← korjattu: poistettu väli ennen fi
        'el' => 'παράδειγμα@tapojarvi.fi',
    ],

    'password_label' => [
        'fi' => 'Salasana',
        'sv' => 'Lösenord',
        'en' => 'Password',
        'it' => 'Password',
        'el' => 'Κωδικός',
    ],

    'password_placeholder' => [
        'fi' => '••••••••',
        'sv' => '••••••••',
        'en' => '••••••••',
        'it' => '••••••••',
        'el' => '••••••••',
    ],

    'login_button' => [
        'fi' => 'Kirjaudu sisään',
        'sv' => 'Logga in',
        'en' => 'Log in',
        'it' => 'Accedi',
        'el' => 'Σύνδεση',
    ],

'login_loading' => [
    'fi' => 'Kirjaudutaan...',
    'sv' => 'Loggar in...',
    'en' => 'Logging in...',
    'it' => 'Accesso in corso...',
    'el' => 'Σύνδεση...',
],

'forgot_password_link' => [
    'fi' => 'Unohtuiko salasana?',
    'sv' => 'Glömt lösenordet?',
    'en' => 'Forgot your password?',
    'it' => 'Password dimenticata?',
    'el' => 'Ξεχάσατε τον κωδικό;',
],

'reset_requested_message' => [
    'fi' => 'Jos sähköpostiosoite löytyy järjestelmästä, palautuslinkki on lähetetty.',
    'sv' => 'Om e-postadressen finns i systemet har en återställningslänk skickats.',
    'en' => 'If the email address exists in the system, a reset link has been sent.',
    'it' => 'Se l’indirizzo email esiste nel sistema, è stato inviato un link per il ripristino.',
    'el' => 'Αν η διεύθυνση email υπάρχει στο σύστημα, έχει σταλεί σύνδεσμος επαναφοράς.',
],

'password_reset_success_message' => [
    'fi' => 'Salasana vaihdettu onnistuneesti. Voit nyt kirjautua uudella salasanalla.',
    'sv' => 'Lösenordet har ändrats. Du kan nu logga in med det nya lösenordet.',
    'en' => 'Your password has been changed. You can now log in with your new password.',
    'it' => 'La password è stata cambiata con successo. Ora puoi accedere con la nuova password.',
    'el' => 'Ο κωδικός άλλαξε επιτυχώς. Μπορείτε τώρα να συνδεθείτε με τον νέο κωδικό.',
],
];
// Apufunktio käännösten hakuun
function lt(string $key, string $lang, array $terms): string {
    return $terms[$key][$lang] ?? $terms[$key]['en'] ?? $key;
}

// Kielilippujen tiedot
$langFlags = [
    'fi' => ['label' => 'FI', 'icon' => 'finnish-flag.png'],
    'sv' => ['label' => 'SV', 'icon' => 'swedish-flag.png'],
    'en' => ['label' => 'EN', 'icon' => 'english-flag.png'],
    'it' => ['label' => 'IT', 'icon' => 'italian-flag.png'],
    'el' => ['label' => 'EL', 'icon' => 'greece-flag.png'],
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($uiLang); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars(lt('page_title', $uiLang, $loginTerms)); ?></title>
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/global.css', $base) ?>">
<style>
/* ===== Modern Login Page - Responsive ===== */

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

.sf-app-splash {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    background:
        radial-gradient(circle at center, rgba(255,255,255,0.07) 0%, rgba(255,255,255,0.02) 34%, rgba(255,255,255,0) 62%),
        linear-gradient(160deg, #111827 0%, #0f172a 55%, #020617 100%);
    align-items: center;
    justify-content: center;
    transition: opacity 0.6s ease, visibility 0.6s ease;
}

@media (max-width: 768px) {
    .sf-app-splash {
        display: flex;
    }
}

.sf-app-splash.is-hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}

.sf-app-splash-logo {
    width: 210px;
    max-width: 54vw;
    height: auto;
    animation: sfSplashLogo 1.25s cubic-bezier(0.22, 1, 0.36, 1) both;
    filter: drop-shadow(0 18px 42px rgba(0,0,0,0.35));
}

@keyframes sfSplashLogo {
    0% {
        opacity: 0;
        transform: scale(0.82) translateY(14px);
        filter: blur(6px);
    }

    45% {
        opacity: 1;
        transform: scale(1.04) translateY(0);
        filter: blur(0);
    }

    100% {
        opacity: 1;
        transform: scale(1);
        filter: blur(0);
    }
}

.sf-login-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 16px;
    opacity: 0;
    transform: translateY(14px) scale(0.985);
    animation: sfLoginEnter 0.45s ease 0.55s forwards;
}

@keyframes sfLoginEnter {
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
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
    margin: 0 auto 32px;
    width: 180px;
    max-width: 60%;
    height: auto;
    object-fit: contain;
}

.sf-login-title {
    text-align: center;
    margin-bottom: 32px;
    font-size: 1.5rem;
    font-weight: 700;
    color: #111827;
    line-height: 1.3;
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
    font-size: 1.1rem;
    transition: border-color 0.2s, box-shadow 0.2s;
    background: #f9fafb;
}

.sf-input:focus {
    border-color: #fee000;
    outline: none;
    box-shadow: 0 0 0 4px rgba(254, 224, 0, 0.3);
    background: #ffffff;
}

.sf-input::placeholder {
    color: #9ca3af;
}

/* ===== KIRJAUTUMISPAINIKE ANIMAATIOLLA ===== */
.sf-btn-login {
    width: 100%;
    padding: 18px;
    background: #fee000;
    border: none;
    border-radius: 14px;
    font-size: 1.15rem;
    font-weight: 700;
    color: #111827;
    cursor: pointer;
    margin-top: 8px;
    transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    position: relative;
}

.sf-btn-login:hover {
    background: #fcd34d;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(254, 224, 0, 0.4);
}

.sf-btn-login:active {
    transform: translateY(0);
    box-shadow: 0 4px 12px rgba(254, 224, 0, 0.3);
}

.sf-btn-login .btn-text,
.sf-btn-login .btn-icon {
    transition: opacity 0.2s, transform 0.2s;
}

.sf-btn-login .btn-icon {
    font-size: 1.2em;
    transition: transform 0.2s;
}

.sf-btn-login:hover .btn-icon {
    transform: translateX(4px);
}

/* Latausanimaatio */
.sf-btn-login .btn-loading {
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.sf-btn-login .btn-loading.hidden {
    display: none;
}

.sf-btn-login.loading .btn-text,
.sf-btn-login.loading .btn-icon {
    display: none;
}

.sf-btn-login.loading .btn-loading {
    display: inline-flex;
}

/* Spinner pyörii */
.spinner {
    width: 22px;
    height: 22px;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Painike disabloituna */
.sf-btn-login.loading {
    pointer-events: none;
    opacity: 0.85;
    background: #fcd34d;
}

.sf-login-actions {
    margin-top: 16px;
    text-align: center;
}

.sf-forgot-link {
    display: inline-block;
    color: #1f2937;
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    border-bottom: 1px solid transparent;
    transition: border-color 0.2s ease, color 0.2s ease;
}

.sf-forgot-link:hover {
    color: #111827;
    border-color: #111827;
}

.sf-login-footer {
    text-align: center;
    margin-top: 28px;
    font-size: 0.9rem;
    color: #6b7280;
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
.sf-login-success {
    background: #ecfdf5;
    color: #064e3b;
    padding: 14px 16px;
    border-radius: 14px;
    font-size: 0.95rem;
    font-weight: 700;
    margin-bottom: 24px;
    text-align: left;
    border: 1px solid #a7f3d0;
    display: flex;
    align-items: center;
    gap: 10px;
    line-height: 1.4;
}

.sf-login-success-icon {
    width: 24px;
    height: 24px;
    flex: 0 0 24px;
    display: block;
}

/* ===== KIELIVALITSIN ===== */
.sf-lang-switcher {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 20px;
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

/* ===== TABLET ===== */
@media (max-width: 600px) {
    .sf-login-wrapper {
        padding: 12px;
        padding-top: 5vh;
    }

    .sf-login-card {
        padding: 36px 28px;
        border-radius: 20px;
        max-width: 100%;
    }

    .sf-login-logo {
        width: 120px;
        margin-bottom: 24px;
    }

.sf-login-title {
    font-size: 2rem;
	line-height: 1.1;
	margin: 12px 0 20px;
}

    .sf-label {
        font-size: 0.95rem;
    }

    .sf-input {
        padding: 14px 16px;
        font-size: 1rem;
    }

    .sf-btn-login {
        padding: 16px;
        font-size: 1.1rem;
    }

    .sf-lang-switcher {
        flex-wrap: wrap;
    }

    .sf-lang-btn {
        padding: 5px 10px;
        font-size: 0.8rem;
    }

    .sf-lang-btn img {
        width: 18px;
        height: 18px;
    }
}

/* ===== MOBIILI (PIENI) ===== */
@media (max-width: 400px) {
    .sf-login-wrapper {
        padding: 8px;
        padding-top: 4vh;
    }

    .sf-login-card {
        padding: 28px 20px;
        border-radius: 16px;
    }

    .sf-login-logo {
        width: 120px;
        margin-bottom: 24px;
    }

    .sf-login-title {
        font-size: 1.2rem;
        margin-bottom: 24px;
    }

    .sf-field {
        margin-bottom: 20px;
    }

    .sf-label {
        font-size: 0.9rem;
        margin-bottom: 6px;
    }

    .sf-input {
        padding: 14px;
        font-size: 1rem;
        border-radius: 10px;
    }

    .sf-btn-login {
        padding: 16px;
        font-size: 1.05rem;
        border-radius: 12px;
    }

    .sf-login-footer {
        font-size: 0.8rem;
        margin-top: 24px;
    }

    .sf-lang-btn {
        padding: 4px 8px;
        font-size: 0.75rem;
    }

    .sf-lang-btn img {
        width: 16px;
        height: 16px;
    }
}

/* ===== LANDSCAPE MOBIILI ===== */
@media (max-height: 500px) and (orientation: landscape) {
    .sf-login-wrapper {
        padding: 12px;
        align-items: center;
    }

    .sf-login-card {
        padding: 24px 32px;
        max-width: 500px;
    }

    .sf-login-logo {
        width: 120px;
        margin-bottom: 16px;
    }

    .sf-login-title {
        font-size: 1.1rem;
        margin-bottom: 16px;
    }

    .sf-field {
        margin-bottom: 12px;
    }

    .sf-input {
        padding: 12px 14px;
    }

    .sf-btn-login {
        padding: 14px;
    }

    .sf-lang-switcher {
        margin-bottom: 12px;
    }
}

/* ===== ISOT NÄYTÖT ===== */
@media (min-width: 1200px) {
    .sf-login-card {
        max-width: 520px;
        padding: 56px 48px;
    }

    .sf-login-logo {
        width: 200px;
    }

    .sf-login-title {
        font-size: 1.6rem;
    }

    .sf-input {
        font-size: 1.15rem;
        padding: 18px 20px;
    }

    .sf-btn-login {
        font-size: 1.2rem;
        padding: 20px;
    }
}
</style>
</head>
<body>

<div class="sf-app-splash" aria-hidden="true">
    <img src="<?php echo $base; ?>/assets/img/icons/pwa-icon-512.png"
         class="sf-app-splash-logo"
         alt="SafetyFlash">
</div>

<div class="sf-login-wrapper">

    <!-- KIELIVALITSIN -->
    <div class="sf-lang-switcher">
        <?php foreach ($langFlags as $langCode => $langData): ?>
            <a href="?lang=<?php echo $langCode; ?>"
               class="sf-lang-btn <?php echo $langCode === $uiLang ? 'active' : ''; ?>">
                <img src="<?php echo $base; ?>/assets/img/<?php echo $langData['icon']; ?>"
                     alt="<?php echo $langData['label']; ?>">
                <span><?php echo $langData['label']; ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="sf-login-card">

        <img src="<?php echo $base; ?>/assets/img/safetylogo.png"
             class="sf-login-logo"
             alt="Safetyflash">

        <div class="sf-login-title">
            <?php echo htmlspecialchars(lt('login_heading', $uiLang, $loginTerms)); ?>
        </div>

<?php if (!empty($_GET['logged_out'])): ?>
    <div class="sf-login-success">
        <img src="<?php echo $base; ?>/assets/img/icons/check.svg"
             class="sf-login-success-icon"
             alt="">
        <span><?php echo htmlspecialchars(lt('logged_out_message', $uiLang, $loginTerms)); ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($_GET['reset_requested'])): ?>
    <div class="sf-login-success">
        <img src="<?php echo $base; ?>/assets/img/icons/check.svg"
             class="sf-login-success-icon"
             alt="">
        <span><?php echo htmlspecialchars(lt('reset_requested_message', $uiLang, $loginTerms)); ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($_GET['password_reset'])): ?>
    <div class="sf-login-success">
        <img src="<?php echo $base; ?>/assets/img/icons/check.svg"
             class="sf-login-success-icon"
             alt="">
        <span><?php echo htmlspecialchars(lt('password_reset_success_message', $uiLang, $loginTerms)); ?></span>
    </div>
<?php endif; ?>

<?php if (isset($_GET['session_invalidated'])): ?>
    <div class="sf-login-error">
        <?php echo htmlspecialchars(sf_term('session_invalidated_message', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if (!empty($_GET['error'])): ?>
    <?php if ($_GET['error'] === 'rate_limit'): ?>
        <div class="sf-login-error">
            <?php 
                $waitMinutes = (int)($_GET['wait'] ?? 15);
                $errorMessage = sf_term('error_rate_limit', $uiLang);
                // Replace {minutes} placeholder
                $errorMessage = str_replace('{minutes}', (string)$waitMinutes, $errorMessage);
                echo htmlspecialchars($errorMessage);
            ?>
        </div>
    <?php else: ?>
        <div class="sf-login-error">
            <?php echo htmlspecialchars(sf_term('login_error', $uiLang)); ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

        <form method="post" action="<?php echo $base; ?>/app/api/login_process.php" id="loginForm">
            <?= sf_csrf_field() ?>
            <input type="hidden" name="lang" value="<?php echo htmlspecialchars($uiLang); ?>">

            <div class="sf-field">
                <label class="sf-label" for="email">
                    <?php echo htmlspecialchars(lt('email_label', $uiLang, $loginTerms)); ?>
                </label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    class="sf-input"
                    placeholder="<?php echo htmlspecialchars(lt('email_placeholder', $uiLang, $loginTerms)); ?>"
                    autocomplete="email"
                    required
                >
            </div>

            <div class="sf-field">
                <label class="sf-label" for="password">
                    <?php echo htmlspecialchars(lt('password_label', $uiLang, $loginTerms)); ?>
                </label>
                <input
                    type="password"
                    name="password"
                    id="password"
                    class="sf-input"
                    placeholder="<?php echo htmlspecialchars(lt('password_placeholder', $uiLang, $loginTerms)); ?>"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button class="sf-btn-login" type="submit" id="loginBtn">
                <span class="btn-text">
                    <?php echo htmlspecialchars(lt('login_button', $uiLang, $loginTerms)); ?>
                </span>
                <span class="btn-icon">→</span>
                <span class="btn-loading hidden">
                    <svg class="spinner" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.3" />
                        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                    </svg>
                    <span><?php echo htmlspecialchars(lt('login_loading', $uiLang, $loginTerms)); ?></span>
                </span>
            </button>
        </form>

        <div class="sf-login-actions">
            <a href="<?php echo $base; ?>/assets/pages/forgot_password.php?lang=<?php echo urlencode($uiLang); ?>" class="sf-forgot-link">
                <?php echo htmlspecialchars(lt('forgot_password_link', $uiLang, $loginTerms)); ?>
            </a>
        </div>

        <div class="sf-login-footer">
            <?php echo htmlspecialchars(sf_term('footer_text', $uiLang)); ?> &copy; <?php echo date('Y'); ?>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const splash = document.querySelector('.sf-app-splash');
    const form = document.getElementById('loginForm');
    const btn = document.getElementById('loginBtn');

const isPWA =
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true;

const isMobile =
    window.matchMedia('(max-width: 768px)').matches;

if (splash && isMobile && !isPWA) {

    window.setTimeout(function() {
        splash.classList.add('is-hidden');
    }, 1350);

} else if (splash) {

    splash.remove();

}

    if (form && btn) {
        form.addEventListener('submit', function() {
            btn.classList.add('loading');
        });
    }
});
</script>

</body>
</html>