<?php
// assets/pages/settings/tab_email.php
declare(strict_types=1);

$baseUrl = rtrim($config['base_url'] ?? '', '/');
$csrfToken = $_SESSION['csrf_token'] ?? '';

// Get current SMTP settings
$smtp_host = sf_get_setting('smtp_host', '');
$smtp_port = sf_get_setting('smtp_port', 587);
$smtp_encryption = sf_get_setting('smtp_encryption', 'tls');
$smtp_username = sf_get_setting('smtp_username', '');
$smtp_from_email = sf_get_setting('smtp_from_email', '');
$smtp_from_name = sf_get_setting('smtp_from_name', '');
?>

<style>
/* Email settings styles */
.sf-smtp-form {
    display: grid;
    gap: 16px;
    max-width: 500px;
}

.sf-smtp-form .sf-setting-row {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.sf-smtp-form label {
    font-weight: 500;
    font-size: 14px;
}

.sf-smtp-form input,
.sf-smtp-form select {
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 14px;
}

.sf-test-section {
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid #e2e8f0;
}

.sf-test-row {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-top: 16px;
}

.sf-test-row input {
    flex: 1;
    max-width: 300px;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
}

.sf-test-result {
    margin-top: 16px;
    padding: 12px 16px;
    border-radius: 6px;
}

.sf-test-result.success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}

.sf-test-result.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.hidden {
    display: none;
}
</style>

<div class="sf-settings-section">
    <h3><?= htmlspecialchars(sf_term('settings_smtp_settings', $currentUiLang) ?? 'SMTP-asetukset', ENT_QUOTES, 'UTF-8') ?></h3>
    
    <form class="sf-smtp-form">
        <div class="sf-setting-row">
            <label for="smtp_host"><?= htmlspecialchars(sf_term('settings_smtp_host', $currentUiLang) ?? 'SMTP-palvelin', ENT_QUOTES, 'UTF-8') ?></label>
            <input 
                type="text" 
                id="smtp_host" 
                name="smtp_host" 
                value="<?= htmlspecialchars((string)$smtp_host, ENT_QUOTES, 'UTF-8') ?>"
                placeholder="esim. mail.tapojarvi.online"
            >
        </div>
        
        <div class="sf-setting-row">
            <label for="smtp_port"><?= htmlspecialchars(sf_term('settings_smtp_port', $currentUiLang) ?? 'SMTP-portti', ENT_QUOTES, 'UTF-8') ?></label>
            <input 
                type="number" 
                id="smtp_port" 
                name="smtp_port" 
                value="<?= (int)$smtp_port ?>"
                min="1"
                max="65535"
            >
        </div>
        
        <div class="sf-setting-row">
            <label for="smtp_encryption"><?= htmlspecialchars(sf_term('settings_smtp_encryption', $currentUiLang) ?? 'Salaus', ENT_QUOTES, 'UTF-8') ?></label>
            <select id="smtp_encryption" name="smtp_encryption">
                <option value="none" <?= $smtp_encryption === 'none' ? 'selected' : '' ?>>Ei salausta</option>
                <option value="tls" <?= $smtp_encryption === 'tls' ? 'selected' : '' ?>>TLS</option>
                <option value="ssl" <?= $smtp_encryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
            </select>
        </div>
        
        <div class="sf-setting-row">
            <label for="smtp_username"><?= htmlspecialchars(sf_term('settings_smtp_username', $currentUiLang) ?? 'Käyttäjätunnus', ENT_QUOTES, 'UTF-8') ?></label>
            <input 
                type="text" 
                id="smtp_username" 
                name="smtp_username" 
                value="<?= htmlspecialchars((string)$smtp_username, ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="username"
            >
        </div>
        
        <div class="sf-setting-row">
            <label for="smtp_password"><?= htmlspecialchars(sf_term('settings_smtp_password', $currentUiLang) ?? 'Salasana', ENT_QUOTES, 'UTF-8') ?></label>
            <input 
                type="password" 
                id="smtp_password" 
                name="smtp_password" 
                value=""
                placeholder="Jätä tyhjäksi jos et halua muuttaa"
                autocomplete="new-password"
            >
        </div>
        
        <div class="sf-setting-row">
            <label for="smtp_from_email"><?= htmlspecialchars(sf_term('settings_smtp_from_email', $currentUiLang) ?? 'Lähettäjän osoite', ENT_QUOTES, 'UTF-8') ?></label>
            <input 
                type="email" 
                id="smtp_from_email" 
                name="smtp_from_email" 
                value="<?= htmlspecialchars((string)$smtp_from_email, ENT_QUOTES, 'UTF-8') ?>"
            >
        </div>
        
        <div class="sf-setting-row">
            <label for="smtp_from_name"><?= htmlspecialchars(sf_term('settings_smtp_from_name', $currentUiLang) ?? 'Lähettäjän nimi', ENT_QUOTES, 'UTF-8') ?></label>
            <input 
                type="text" 
                id="smtp_from_name" 
                name="smtp_from_name" 
                value="<?= htmlspecialchars((string)$smtp_from_name, ENT_QUOTES, 'UTF-8') ?>"
            >
        </div>
    </form>
</div>

<div class="sf-settings-actions">
    <button type="button" id="saveEmailSettings" class="sf-btn sf-btn-primary">
        <?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
    </button>
</div>

<div class="sf-settings-section sf-test-section">
    <h3><?= htmlspecialchars(sf_term('settings_test_email', $currentUiLang) ?? 'Testaa sähköpostin lähetys', ENT_QUOTES, 'UTF-8') ?></h3>
    <p>Lähetä testisähköposti varmistaaksesi että asetukset toimivat.</p>
    
    <div class="sf-test-row">
        <input 
            type="email" 
            id="test_email_address" 
            placeholder="Syötä testiosoite"
        >
        <button type="button" id="sendTestEmail" class="sf-btn sf-btn-secondary">
            Lähetä testiviesti
        </button>
    </div>
    
    <div id="testEmailResult" class="sf-test-result hidden"></div>
</div>

<script>
(function() {
    'use strict';
    
    const baseUrl = window.SF_BASE_URL || '<?= $baseUrl ?>';
    const csrfToken = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';
    const saveBtn = document.getElementById('saveEmailSettings');
    const testBtn = document.getElementById('sendTestEmail');
    const testEmailInput = document.getElementById('test_email_address');
    const testResult = document.getElementById('testEmailResult');
    
    // Save SMTP settings
    if (saveBtn) {
        saveBtn.addEventListener('click', async function() {
            const data = {
                smtp_host: document.getElementById('smtp_host')?.value || '',
                smtp_port: parseInt(document.getElementById('smtp_port')?.value || '587', 10),
                smtp_encryption: document.getElementById('smtp_encryption')?.value || 'tls',
                smtp_username: document.getElementById('smtp_username')?.value || '',
                smtp_password: document.getElementById('smtp_password')?.value || '',
                smtp_from_email: document.getElementById('smtp_from_email')?.value || '',
                smtp_from_name: document.getElementById('smtp_from_name')?.value || ''
            };
            
            saveBtn.disabled = true;
            saveBtn.textContent = 'Tallennetaan...';
            
            try {
                const response = await fetch(baseUrl + '/app/api/save_email_settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(data)
                });
                
                if (response.ok) {
                    saveBtn.textContent = 'Tallennettu!';
                    setTimeout(() => {
                        saveBtn.textContent = 'Tallenna';
                        saveBtn.disabled = false;
                    }, 2000);
                } else {
                    const errorData = await response.json().catch(() => ({}));
                    throw new Error(errorData.error || 'Tallennus epäonnistui');
                }
            } catch (e) {
                alert(e.message);
                saveBtn.textContent = 'Tallenna';
                saveBtn.disabled = false;
            }
        });
    }
    
    // Send test email
    if (testBtn) {
        testBtn.addEventListener('click', async function() {
            const testEmail = testEmailInput?.value?.trim();
            
            if (!testEmail) {
                alert('Syötä testiosoite');
                return;
            }
            
            testBtn.disabled = true;
            testBtn.textContent = 'Lähetetään...';
            testResult.classList.add('hidden');
            
            try {
                // Save settings first
                const settings = {
                    smtp_host: document.getElementById('smtp_host')?.value || '',
                    smtp_port: parseInt(document.getElementById('smtp_port')?.value || '587', 10),
                    smtp_encryption: document.getElementById('smtp_encryption')?.value || 'tls',
                    smtp_username: document.getElementById('smtp_username')?.value || '',
                    smtp_password: document.getElementById('smtp_password')?.value || '',
                    smtp_from_email: document.getElementById('smtp_from_email')?.value || '',
                    smtp_from_name: document.getElementById('smtp_from_name')?.value || ''
                };
                
                await fetch(baseUrl + '/app/api/save_email_settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(settings)
                });
                
                // Send test email
                const response = await fetch(baseUrl + '/app/api/test_email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ test_email: testEmail })
                });
                
                const result = await response.json();
                
                testResult.classList.remove('hidden', 'success', 'error');
                
                if (result.success) {
                    testResult.classList.add('success');
                    testResult.textContent = 'Testisähköposti lähetetty onnistuneesti!';
                } else {
                    testResult.classList.add('error');
                    testResult.textContent = 'Lähetys epäonnistui: ' + (result.error || '');
                }
            } catch (e) {
                testResult.classList.remove('hidden', 'success');
                testResult.classList.add('error');
                testResult.textContent = 'Lähetys epäonnistui: ' + e.message;
            } finally {
                testBtn.disabled = false;
                testBtn.textContent = 'Lähetä testiviesti';
            }
        });
    }
})();
</script>