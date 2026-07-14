<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../../assets/services/email_services.php';

$base = rtrim($config['base_url'] ?? '', '/');
$lang = $_POST['lang'] ?? 'fi';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $base . '/assets/pages/forgot_password.php?lang=' . urlencode($lang));
    exit;
}

if (!sf_csrf_validate($_POST['csrf_token'] ?? null)) {
    header('Location: ' . $base . '/assets/pages/forgot_password.php?error=1&lang=' . urlencode($lang));
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: ' . $base . '/assets/pages/login.php?reset_requested=1&lang=' . urlencode($lang));
    exit;
}

$mysqli = sf_db();

$stmt = $mysqli->prepare(
    'SELECT id, email, first_name, last_name
     FROM sf_users
     WHERE LOWER(email) = LOWER(?)
       AND is_active = 1
     LIMIT 1'
);

if (!$stmt) {
    sf_app_log('password_forgot: DB prepare failed: ' . $mysqli->error, LOG_LEVEL_ERROR);
    $mysqli->close();
    header('Location: ' . $base . '/assets/pages/login.php?reset_requested=1&lang=' . urlencode($lang));
    exit;
}

$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result ? $result->fetch_assoc() : null;
$stmt->close();

if ($user) {
    $userId = (int)$user['id'];
    $userEmail = (string)$user['email'];
    $fullName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $requestedIp = substr((string)sf_get_client_ip(), 0, 45);
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    $invalidateStmt = $mysqli->prepare(
        'UPDATE sf_password_resets
         SET used_at = NOW()
         WHERE user_id = ?
           AND used_at IS NULL'
    );

    if ($invalidateStmt) {
        $invalidateStmt->bind_param('i', $userId);
        $invalidateStmt->execute();
        $invalidateStmt->close();
    }

    $insertStmt = $mysqli->prepare(
        'INSERT INTO sf_password_resets (user_id, email, token_hash, requested_ip, user_agent, expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), NOW())'
    );

    if ($insertStmt) {
        $insertStmt->bind_param('issss', $userId, $userEmail, $tokenHash, $requestedIp, $userAgent);
        $insertStmt->execute();
        $insertStmt->close();

        $resetLink = $base . '/assets/pages/reset_password.php?token=' . urlencode($rawToken) . '&lang=' . urlencode($lang);

        $subjectMap = [
            'fi' => 'Safetyflash – salasanan palautus',
            'sv' => 'Safetyflash – återställ lösenord',
            'en' => 'Safetyflash – password reset',
            'it' => 'Safetyflash – reimposta password',
            'el' => 'Safetyflash – επαναφορά κωδικού',
        ];

        $greetingMap = [
            'fi' => 'Hei',
            'sv' => 'Hej',
            'en' => 'Hello',
            'it' => 'Ciao',
            'el' => 'Γεια σας',
        ];

        $introMap = [
            'fi' => 'Olet pyytänyt Safetyflash-sovelluksen salasanan palautusta.',
            'sv' => 'Du har begärt återställning av lösenordet för Safetyflash-applikationen.',
            'en' => 'You requested a password reset for the Safetyflash application.',
            'it' => 'Hai richiesto il ripristino della password per l’applicazione Safetyflash.',
            'el' => 'Ζητήσατε επαναφορά κωδικού για την εφαρμογή Safetyflash.',
        ];

        $ctaMap = [
            'fi' => 'Aseta uusi salasana',
            'sv' => 'Ange nytt lösenord',
            'en' => 'Set a new password',
            'it' => 'Imposta una nuova password',
            'el' => 'Ορίστε νέο κωδικό',
        ];

        $buttonHelpMap = [
            'fi' => 'Jos painike ei avaudu, voit käyttää myös tätä linkkiä:',
            'sv' => 'Om knappen inte öppnas kan du även använda denna länk:',
            'en' => 'If the button does not open, you can also use this link:',
            'it' => 'Se il pulsante non si apre, puoi usare anche questo link:',
            'el' => 'Αν το κουμπί δεν ανοίγει, μπορείτε επίσης να χρησιμοποιήσετε αυτόν τον σύνδεσμο:',
        ];

        $expiryMap = [
            'fi' => 'Linkki on voimassa 60 minuuttia ja sen voi käyttää vain kerran.',
            'sv' => 'Länken är giltig i 60 minuter och kan endast användas en gång.',
            'en' => 'The link is valid for 60 minutes and can only be used once.',
            'it' => 'Il link è valido per 60 minuti e può essere usato solo una volta.',
            'el' => 'Ο σύνδεσμος ισχύει για 60 λεπτά και μπορεί να χρησιμοποιηθεί μόνο μία φορά.',
        ];

        $ignoreMap = [
            'fi' => 'Jos et pyytänyt salasanan palautusta, voit jättää tämän viestin huomiotta.',
            'sv' => 'Om du inte begärde återställning av lösenordet kan du ignorera detta meddelande.',
            'en' => 'If you did not request a password reset, you can ignore this message.',
            'it' => 'Se non hai richiesto il ripristino della password, puoi ignorare questo messaggio.',
            'el' => 'Αν δεν ζητήσατε επαναφορά κωδικού, μπορείτε να αγνοήσετε αυτό το μήνυμα.',
        ];

        $subject = $subjectMap[$lang] ?? $subjectMap['en'];
        $greeting = $greetingMap[$lang] ?? $greetingMap['en'];
        $intro = $introMap[$lang] ?? $introMap['en'];
        $cta = $ctaMap[$lang] ?? $ctaMap['en'];
        $buttonHelp = $buttonHelpMap[$lang] ?? $buttonHelpMap['en'];
        $expiry = $expiryMap[$lang] ?? $expiryMap['en'];
        $ignore = $ignoreMap[$lang] ?? $ignoreMap['en'];

        $safeName = htmlspecialchars($fullName !== '' ? $fullName : $userEmail, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
        $safeCta = htmlspecialchars($cta, ENT_QUOTES, 'UTF-8');
        $safeGreeting = htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8');
        $safeIntro = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
        $safeButtonHelp = htmlspecialchars($buttonHelp, ENT_QUOTES, 'UTF-8');
        $safeExpiry = htmlspecialchars($expiry, ENT_QUOTES, 'UTF-8');
        $safeIgnore = htmlspecialchars($ignore, ENT_QUOTES, 'UTF-8');

        $htmlBody = '
            <div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6;color:#111827;">
                <p>' . $safeGreeting . ' ' . $safeName . ',</p>
                <p>' . $safeIntro . '</p>
                <p><a href="' . $safeLink . '" style="display:inline-block;padding:12px 18px;background:#fee000;color:#111827;text-decoration:none;border-radius:8px;font-weight:700;">' . $safeCta . '</a></p>
                <p>' . $safeButtonHelp . '</p>
                <p><a href="' . $safeLink . '">' . $safeLink . '</a></p>
                <p>' . $safeExpiry . '</p>
                <p>' . $safeIgnore . '</p>
            </div>
        ';

        $textBody =
            $greeting . ' ' . ($fullName !== '' ? $fullName : $userEmail) . ",\n\n" .
            $intro . "\n\n" .
            $cta . ":\n" .
            $resetLink . "\n\n" .
            $expiry . "\n\n" .
            $ignore;

        try {
            sf_send_email($subject, $htmlBody, $textBody, [$userEmail], [], null);
        } catch (Throwable $e) {
            sf_app_log('password_forgot: send email failed: ' . $e->getMessage(), LOG_LEVEL_ERROR);
        }

        sf_audit_log(
            'user_password_reset_requested',
            'user',
            $userId,
            [
                'requested_user_id' => $userId,
                'requested_user_email' => $userEmail,
            ],
            $userId,
            'info'
        );
    }
}

$mysqli->close();

header('Location: ' . $base . '/assets/pages/login.php?reset_requested=1&lang=' . urlencode($lang));
exit;