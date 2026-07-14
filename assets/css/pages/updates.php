<?php
/**
 * Updates Page
 *
 * Lists published changelog entries newest-first.
 * Content is shown in the user's active UI language with fallback to English then Finnish.
 * Clicking a title or the "Read more" button opens a modal with the full entry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

sf_require_login();

Database::setConfig($config['db'] ?? []);

$user    = sf_current_user();
$uiLang  = $_SESSION['ui_lang'] ?? 'fi';
$base    = rtrim($config['base_url'] ?? '', '/');
$userId  = (int)($user['id'] ?? 0);

// Load published changelog entries newest first
$db = Database::getInstance();
$stmt = $db->prepare(
    "SELECT *
     FROM sf_changelog
     WHERE is_published = 1
     ORDER BY COALESCE(publish_date, DATE(created_at)) DESC, created_at DESC"
);
$stmt->execute();
$entries = $stmt->fetchAll();

// Load the user's product_updates notification preference
$productUpdatesEnabled = true; // default: enabled (opt-out model)
if ($userId > 0) {
    try {
        $prefStmt = $db->prepare("
            SELECT enabled
            FROM sf_user_notification_preferences
            WHERE user_id = ? AND category = 'product_updates'
            LIMIT 1
        ");
        $prefStmt->execute([$userId]);
        $prefRow = $prefStmt->fetch(PDO::FETCH_ASSOC);
        if ($prefRow !== false) {
            $productUpdatesEnabled = (bool)(int)$prefRow['enabled'];
        }
    } catch (Throwable $e) {
        // Fail open — default enabled
    }
}

/**
 * Resolve translated title/content for the given language with fallback.
 *
 * @param array  $translations  Decoded JSON array
 * @param string $lang          Desired language code
 * @param string $field         'title' or 'content'
 * @return string
 */
function resolveTranslation(array $translations, string $lang, string $field): string
{
    if (!empty($translations[$lang][$field])) {
        return $translations[$lang][$field];
    }
    // Fallback chain: en → fi → first available
    foreach (['en', 'fi'] as $fallback) {
        if (!empty($translations[$fallback][$field])) {
            return $translations[$fallback][$field];
        }
    }
    foreach ($translations as $t) {
        if (!empty($t[$field])) {
            return $t[$field];
        }
    }
    return '';
}

/**
 * Sanitize changelog HTML content.
 * Allows safe formatting tags and removes all attributes.
 * Identical logic to sf_sanitize_ai_html() used on the view page.
 * Falls back to nl2br for plain-text (no HTML tags) content.
 */
function sf_updates_sanitize_html(string $html): string
{
    // Plain-text content: convert newlines to <br> tags
    if (strip_tags($html) === $html) {
        return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'));
    }
    // HTML content: strip disallowed tags and remove all attributes
    $allowed = '<p><br><strong><em><u><ol><ul><li><span>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/<(\w+)(?:\s[^>]*)?(\/?)>/', '<$1$2>', $html);
    return $html;
}
?>

<div class="sf-page-container">
    <div class="sf-updates-hero">
        <div class="sf-updates-hero-main">
            <div class="sf-page-header">
                <h1 class="sf-page-title">
                    <?= htmlspecialchars(sf_term('updates_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </h1>
            </div>
            <p class="sf-updates-description">
                <?= htmlspecialchars(sf_term('updates_description', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <div class="sf-updates-subscribe-card" id="sfUpdatesSubscribeCard">
            <div class="sf-updates-subscribe-icon">
    <img
        src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/notification.svg"
        alt=""
        aria-hidden="true">
</div>
            <div class="sf-updates-subscribe-body">
                <strong class="sf-updates-subscribe-title">
                    <?= htmlspecialchars(sf_term('updates_subscribe_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </strong>
            </div>
            <label class="sf-toggle sf-updates-subscribe-toggle" title="<?= htmlspecialchars(sf_term('updates_subscribe_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <input type="checkbox"
                       id="sfUpdatesSubscribeToggle"
                       data-base="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>"
                       data-csrf="<?= htmlspecialchars(sf_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"
                       data-toast-success="<?= htmlspecialchars(sf_term('updates_subscribed_toast', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                       data-toast-error="<?= htmlspecialchars(sf_term('updates_subscribe_error', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                       <?= $productUpdatesEnabled ? 'checked' : '' ?>>
                <span class="sf-toggle-slider"></span>
            </label>
        </div>
    </div>
    <script>
    (function () {
        var toggle = document.getElementById('sfUpdatesSubscribeToggle');
        if (!toggle) return;

        toggle.addEventListener('change', async function () {
            var enabled    = this.checked ? 1 : 0;
            var base       = this.dataset.base || '';
            var csrfToken  = this.dataset.csrf  || '';
            var toastOk    = this.dataset.toastSuccess || '';
            var toastErr   = this.dataset.toastError   || '';

            // Optimistic UI — already toggled by browser; revert on error
            try {
                var fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('notif_pref[product_updates]', enabled);

                var resp = await fetch(base + '/app/api/update_user_notification_preferences.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                var data = await resp.json();

                if (data.ok) {
                    if (typeof window.sfToast === 'function') window.sfToast('success', toastOk);
                } else {
                    // Revert
                    toggle.checked = !toggle.checked;
                    if (typeof window.sfToast === 'function') window.sfToast('error', toastErr || (data.error || ''));
                }
            } catch (e) {
                // Revert on network error
                toggle.checked = !toggle.checked;
                if (typeof window.sfToast === 'function') window.sfToast('error', toastErr);
            }
        });
    })();
    </script>

    <?php if (empty($entries)): ?>
        <div class="sf-updates-empty">
            <p><?= htmlspecialchars(sf_term('updates_empty', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <?php
        // Build sorted unique month list for filter buttons
        $months = [];
        foreach ($entries as $e) {
            $rawDate = !empty($e['publish_date']) ? $e['publish_date'] : $e['created_at'];
            $ts = strtotime($rawDate);
            if ($ts === false) { continue; }
            $key = date('Y-m', $ts);
            if (!isset($months[$key])) {
                // Localise month label: use IntlDateFormatter when available, otherwise a manual map
                if (class_exists('IntlDateFormatter')) {
                    $localeMap = ['fi' => 'fi_FI', 'sv' => 'sv_SE', 'en' => 'en_US', 'it' => 'it_IT', 'el' => 'el_GR'];
                    $locale = $localeMap[$uiLang] ?? 'en_US';
                    $fmt = new IntlDateFormatter(
                        $locale,
                        IntlDateFormatter::NONE,
                        IntlDateFormatter::NONE,
                        null,
                        null,
                        'MMMM yyyy'
                    );
                    $label = ucfirst($fmt->format($ts));
                } else {
                    $label = date('m/Y', $ts);
                }
                $months[$key] = $label;
            }
        }
        ?>
        <?php if (!empty($months)): ?>
        <div class="sf-updates-filter" role="group" aria-label="<?= htmlspecialchars(sf_term('updates_filter_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
            <span class="sf-updates-filter-label">
                <?= htmlspecialchars(sf_term('updates_filter_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <div class="sf-updates-filter-buttons">
                <button type="button"
                        class="sf-btn sf-btn-small sf-btn-primary sf-updates-filter-btn"
                        data-month="all">
                    <?= htmlspecialchars(sf_term('updates_filter_all', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <?php foreach ($months as $key => $label): ?>
                    <button type="button"
                            class="sf-btn sf-btn-small sf-btn-secondary sf-updates-filter-btn"
                            data-month="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
        // Create month-abbreviation formatter once (locale stays constant for all entries)
        $dateLocaleMap = ['fi' => 'fi_FI', 'sv' => 'sv_SE', 'en' => 'en_US', 'it' => 'it_IT', 'el' => 'el_GR'];
        $dateLocale    = $dateLocaleMap[$uiLang] ?? 'en_US';
        $monthAbbrFmt  = class_exists('IntlDateFormatter') ? new IntlDateFormatter(
            $dateLocale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            null,
            null,
            'MMM'
        ) : null;
        ?>
        <div class="sf-updates-timeline" id="sfUpdatesTimeline">
            <?php foreach ($entries as $entry): ?>
                <?php
                $translations = [];
                if (!empty($entry['translations'])) {
                    $decoded = json_decode($entry['translations'], true);
                    if (is_array($decoded)) {
                        $translations = $decoded;
                    }
                }
                $title   = resolveTranslation($translations, $uiLang, 'title');
                $content = resolveTranslation($translations, $uiLang, 'content');
                // Use publish_date when set, otherwise fall back to created_at
                $rawDate = !empty($entry['publish_date']) ? $entry['publish_date'] : $entry['created_at'];
                $displayTimestamp = strtotime($rawDate);
                if ($displayTimestamp === false) { $displayTimestamp = time(); }
                $dateStr  = date('d.m.Y', $displayTimestamp);
                $monthKey = date('Y-m', $displayTimestamp);
                $dateDayStr   = date('j', $displayTimestamp);
                $dateYearStr  = date('Y', $displayTimestamp);
                $dateMonthStr = $monthAbbrFmt
                    ? mb_strtoupper((string)$monthAbbrFmt->format($displayTimestamp))
                    : mb_strtoupper(date('M', $displayTimestamp));
                $entryId  = (int)$entry['id'];
// Sanitize content for safe HTML rendering
$sanitizedContent = sf_updates_sanitize_html($content);

// Build a short excerpt for the timeline card
$excerptSource = (string) $sanitizedContent;

/*
 * Lisätään välilyönti lohkoelementtien ja rivinvaihtojen kohdalle ennen tagien poistoa.
 * Muuten esimerkiksi </p><p> voi muuttua muotoon "lause.Seuraava lause".
 */
$excerptSource = preg_replace('/<\s*br\s*\/?>/iu', ' ', $excerptSource);
$excerptSource = preg_replace('/<\s*\/\s*(p|div|li|h[1-6]|ul|ol|blockquote)\s*>/iu', ' ', $excerptSource);

$plainExcerpt = trim(html_entity_decode(strip_tags($excerptSource), ENT_QUOTES, 'UTF-8'));
$plainExcerpt = preg_replace('/\s+/u', ' ', $plainExcerpt);

/*
 * Käytä ingressinä ensimmäistä kokonaista lausetta.
 * Jos pistettä ei löydy, fallback lyhyeen katkaisuun.
 */
$excerpt = '';

if (preg_match('/^(.+?[.!?])(?:\s|$)/u', $plainExcerpt, $matches)) {
    $excerpt = trim($matches[1]);
} else {
    $excerpt = mb_strlen($plainExcerpt) > 170
        ? rtrim(mb_substr($plainExcerpt, 0, 170)) . '…'
        : $plainExcerpt;
}

// Parse images
$images = [];
                if (!empty($entry['images'])) {
                    $decodedImages = json_decode($entry['images'], true);
                    if (is_array($decodedImages)) {
                        // Only keep paths that match our own upload path pattern (security)
                        foreach ($decodedImages as $imgPath) {
                            if (is_string($imgPath) && preg_match('#^uploads/changelog/[a-zA-Z0-9._-]+$#', $imgPath)) {
                                $images[] = $imgPath;
                            }
                        }
                    }
                }
                ?>
                <div class="sf-updates-item sf-card-appear" data-month="<?= htmlspecialchars($monthKey, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="sf-updates-item-date">
                        <span class="sf-updates-date-day"><?= htmlspecialchars($dateDayStr, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sf-updates-date-month"><?= htmlspecialchars($dateMonthStr, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sf-updates-date-year"><?= htmlspecialchars($dateYearStr, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="sf-updates-item-body<?= !empty($images) ? ' sf-updates-item-has-images' : '' ?>">
                        <div class="sf-updates-item-text">
                            <?php if ($title !== ''): ?>
                                <h2 class="sf-updates-item-title">
                                    <button type="button"
                                            class="sf-updates-title-btn"
                                            data-entry-id="<?= $entryId ?>"
                                            aria-haspopup="dialog">
                                        <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                </h2>
                            <?php endif; ?>
			<?php if ($excerpt !== ''): ?>
    <p class="sf-updates-item-excerpt">
        <?= htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>
                            <?php if ($content !== '' || !empty($images)): ?>
                                <button type="button"
                                        class="sf-btn sf-btn-small sf-btn-secondary sf-updates-read-more"
                                        data-entry-id="<?= $entryId ?>"
                                        aria-haspopup="dialog">
                                    <?= htmlspecialchars(sf_term('updates_read_more', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                                <!-- Hidden content rendered server-side for safe injection into modal -->
                                <div id="sf-update-content-<?= $entryId ?>"
                                     class="sf-updates-hidden-content"
                                     aria-hidden="true">
                                    <div class="sf-update-text-content"><?= $sanitizedContent ?></div>
                                    <?php if (!empty($images)): ?>
                                        <div class="sf-update-images">
                                            <?php foreach ($images as $imgPath): ?>
                                                <div class="sf-update-image-wrap">
                                                    <img src="<?= htmlspecialchars($base . '/' . $imgPath, ENT_QUOTES, 'UTF-8') ?>"
                                                         alt="<?= htmlspecialchars(sf_term('updates_screenshot_alt', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                                                         class="sf-update-image"
                                                         loading="lazy">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($images)): ?>
                            <div class="sf-updates-item-image-preview"
                                 data-entry-id="<?= $entryId ?>"
                                 role="button"
                                 tabindex="0"
                                 aria-haspopup="dialog">
                                <img src="<?= htmlspecialchars($base . '/' . $images[0], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars(sf_term('updates_screenshot_alt', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                                     class="sf-updates-item-preview-img"
                                     loading="lazy">
                                <?php if (count($images) > 1): ?>
                                    <span class="sf-updates-item-image-count">+<?= count($images) - 1 ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Detail Modal -->
<div id="sfUpdateDetailModal" class="sf-modal hidden" role="dialog" aria-modal="true" aria-labelledby="sfUpdateDetailModalTitle">
    <div class="sf-modal-content sf-updates-modal-content">
        <div class="sf-modal-header">
            <h3 id="sfUpdateDetailModalTitle" class="sf-updates-modal-title"></h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="<?= htmlspecialchars(sf_term('updates_close', $uiLang), ENT_QUOTES, 'UTF-8') ?>">×</button>
        </div>
        <div class="sf-modal-body sf-updates-modal-body" id="sfUpdateDetailModalBody"></div>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('updates_close', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- Image Lightbox Modal -->
<div id="sfUpdateImageLightbox" class="sf-modal sf-update-lightbox hidden" role="dialog" aria-modal="true">
    <button type="button" class="sf-update-lightbox-close" aria-label="<?= htmlspecialchars(sf_term('updates_close', $uiLang), ENT_QUOTES, 'UTF-8') ?>">×</button>
    <img id="sfUpdateImageLightboxImg" src="" alt="<?= htmlspecialchars(sf_term('updates_screenshot_alt', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
</div>

<style>
.sf-updates-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    width: 100%;
    min-width: 0;
    margin: 0 0 32px;
}

.sf-updates-hero-main {
    flex: 1 1 auto;
    min-width: 0;
    max-width: 860px;
}

.sf-updates-description {
    display: block;
    width: 100%;
    max-width: 760px;
    margin: 1.25rem 0 0;
    color: rgba(255, 255, 255, 0.78);
    font-size: 1rem;
    line-height: 1.5;
    overflow-wrap: break-word;
    word-break: normal;
}

.sf-updates-empty {
    padding: 48px 24px;
    text-align: center;
    color: var(--sf-muted);
    font-size: 1rem;
}

.sf-updates-filter {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    margin: 0 0 22px;
    padding-bottom: 18px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.22);
}
	.sf-updates-filter-btn:hover {
    background: rgba(254, 224, 0, 0.12);
    border-color: rgba(254, 224, 0, 0.55);
    color: #ffffff;
}

.sf-updates-filter-label {
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.78);
    white-space: nowrap;
    flex-shrink: 0;
}

.sf-updates-filter-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.sf-updates-filter-btn {
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.32);
    background: rgba(15, 23, 42, 0.72);
    color: rgba(255, 255, 255, 0.88);
    box-shadow: none;
}

.sf-updates-filter-btn.sf-btn-primary {
    border-color: #2563eb;
    background: #2563eb;
    color: #ffffff;
    box-shadow:
        0 0 0 1px rgba(37, 99, 235, 0.25),
        0 8px 24px rgba(37, 99, 235, 0.30);
}

.sf-updates-timeline {
    display: flex;
    flex-direction: column;
    gap: 0;
    padding: 24px 0;
    position: relative;
}

.sf-updates-timeline::before {
    content: '';
    position: absolute;
    left: 108px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--sf-border);
}

.sf-updates-item {
    display: flex;
    gap: 24px;
    padding: 0 0 32px 0;
    position: relative;
}

.sf-updates-item::before {
display: none;
}

.sf-updates-item-date {
    width: 96px;
    flex-shrink: 0;
    background: var(--sf-yellow, #FEE000);
    color: #1a1a1a;
    border-radius: 8px;
    padding: 10px 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    position: relative;
    align-self: flex-start;
    text-align: center;
}

.sf-updates-item-date::after {
    content: '';
    position: absolute;
    right: -10px;
    top: 14px;
    border-width: 8px 0 8px 10px;
    border-style: solid;
    border-color: transparent transparent transparent var(--sf-yellow, #FEE000);
}

.sf-updates-date-day {
    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1;
    color: #1a1a1a;
}

.sf-updates-date-month {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #1a1a1a;
}

.sf-updates-date-year {
    font-size: 0.7rem;
    font-weight: 500;
    color: rgba(26, 26, 26, 0.7);
}

.sf-updates-item-body {
    flex: 1;
    background:
        linear-gradient(135deg, rgba(15, 23, 42, 0.96), rgba(8, 15, 31, 0.98));
    border: 1px solid rgba(148, 163, 184, 0.28);
    border-radius: 18px;
    padding: 22px 24px;
    box-shadow: 0 18px 44px rgba(0, 0, 0, 0.22);
    text-align: left;
    transition:
        transform 0.18s ease,
        border-color 0.18s ease,
        box-shadow 0.18s ease,
        background 0.18s ease;
}

.sf-updates-item-body:hover {
    transform: translateY(-3px);
    border-color: rgba(254, 224, 0, 0.45);
    box-shadow: 0 24px 54px rgba(0, 0, 0, 0.34);
}

.sf-updates-item-title {
    font-size: 1.08rem;
    font-weight: 700;
    margin: 0 0 10px;
    color: #ffffff;
    text-align: left;
}

.sf-updates-title-btn {
    background: none;
    border: none;
    padding: 0;
    margin: 0;
    font-size: inherit;
    font-weight: inherit;
    color: inherit;
    cursor: pointer;
    text-align: left;
    text-decoration: underline;
    text-decoration-color: transparent;
    transition: text-decoration-color 0.15s;
    font-family: inherit;
    line-height: inherit;
}

.sf-updates-title-btn:hover,
.sf-updates-title-btn:focus-visible {
    text-decoration-color: currentColor;
    outline: none;
}

.sf-updates-hidden-content {
    display: none;
}

/* Modal content formatting */
.sf-updates-modal-content {
    max-width: 640px;
    width: 100%;
}

.sf-updates-modal-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--sf-text, #111827);
    margin: 0;
    text-align: left;
}

.sf-updates-modal-body {
    font-size: 0.93rem;
    color: var(--sf-text, #111827);
    line-height: 1.7;
    text-align: left;
}

.sf-updates-modal-body p {
    margin: 0 0 0.75em;
}

.sf-updates-modal-body p:last-child {
    margin-bottom: 0;
}

.sf-updates-modal-body ul,
.sf-updates-modal-body ol {
    margin: 0 0 0.75em;
    padding-left: 1.5em;
}

.sf-updates-modal-body li {
    margin-bottom: 0.25em;
}

@media (max-width: 600px) {
    .sf-updates-timeline::before {
        display: none;
    }
    .sf-updates-item::before {
        display: none;
    }
    .sf-updates-item {
        flex-direction: column;
        gap: 0;
        padding: 0 0 32px 0;
        margin-top: 16px;
    }
    .sf-updates-item-date {
        position: absolute;
        top: -14px;
        left: 16px;
        width: auto;
        flex-direction: row;
        gap: 6px;
        align-items: center;
        padding: 4px 14px;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 700;
        box-shadow: 0 2px 6px rgba(0,0,0,0.12);
        z-index: 2;
    }
    .sf-updates-item-date::after {
        display: none;
    }
    .sf-updates-date-day,
    .sf-updates-date-month,
    .sf-updates-date-year {
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1;
    }
    .sf-updates-item-body {
        width: 100%;
        padding-top: 24px;
    }
}

/* Update detail images */
.sf-update-images {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

.sf-update-image {
    width: 100%;
    height: auto;
    display: block;
    border-radius: 10px;
}

/* When images are present, widen the modal */
.sf-updates-modal-content.has-images {
    max-width: 920px;
}

/* Card body grid when images exist */
.sf-updates-item-has-images {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 16px;
    align-items: start;
}

.sf-updates-item-text {
    min-width: 0;
}

/* Large image preview on the right of the card */
.sf-updates-item-image-preview {
    position: relative;
    cursor: pointer;
    border-radius: 10px;
    overflow: hidden;
    flex-shrink: 0;
    align-self: start;
}

.sf-updates-item-preview-img {
    width: 200px;
    height: 130px;
    object-fit: cover;
    display: block;
    border-radius: 12px;
    border: 1px solid rgba(148, 163, 184, 0.32);
    transition: opacity 0.15s ease, transform 0.15s ease;
}

.sf-updates-item-image-preview:hover .sf-updates-item-preview-img {
    opacity: 0.85;
}

.sf-updates-item-image-count {
    position: absolute;
    bottom: 6px;
    right: 6px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 20px;
    pointer-events: none;
}

/* Image wrap in hidden content (no link) */
.sf-update-image-wrap {
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

/* Modal two-column layout */
.sf-updates-modal-body-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 24px;
    align-items: start;
}

.sf-updates-modal-body-text {
    min-width: 0;
}

.sf-updates-modal-body-images {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

@media (max-width: 768px) {
    .sf-updates-item-has-images {
        grid-template-columns: 1fr;
    }
    .sf-updates-item-image-preview {
        display: none;
    }
    .sf-updates-modal-body-grid {
        grid-template-columns: 1fr;
    }
}

/* Subscription banner card */
.sf-updates-subscribe-card {
    display: flex;
    align-items: center;
    gap: 12px;
    width: min(100%, 340px);
    flex-shrink: 0;
    background: rgba(15, 23, 42, 0.72);
    border: 1px solid rgba(148, 163, 184, 0.34);
    border-radius: 999px;
    padding: 10px 14px;
    margin: 62px 0 0;
    box-shadow: 0 16px 34px rgba(0, 0, 0, 0.18);
    backdrop-filter: blur(14px);
}

.sf-updates-subscribe-icon {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.sf-updates-subscribe-icon img {
    width: 20px;
    height: 20px;
    display: block;
    object-fit: contain;
    filter:
        brightness(0)
        saturate(100%)
        invert(85%)
        sepia(93%)
        saturate(1307%)
        hue-rotate(359deg)
        brightness(104%)
        contrast(104%);
    opacity: 1;
}

.sf-updates-subscribe-body {
    flex: 1;
    min-width: 0;
}

.sf-updates-subscribe-title {
    display: block;
    overflow: hidden;
    color: #ffffff;
    font-size: 0.88rem;
    font-weight: 700;
    line-height: 1.2;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sf-updates-subscribe-help {
    display: none;
}

.sf-updates-subscribe-toggle {
    flex-shrink: 0;
}
.sf-updates-item-excerpt {
    max-width: 760px;
    margin: -2px 0 14px;
    color: rgba(226, 232, 240, 0.78);
    font-size: 0.94rem;
    line-height: 1.58;
}

.sf-update-image-wrap {
    position: relative;
    cursor: zoom-in;
}

.sf-update-image-wrap::after {
    content: "Suurenna";
    position: absolute;
    right: 10px;
    bottom: 10px;
    padding: 5px 9px;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.82);
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 700;
    opacity: 0;
    transform: translateY(4px);
    transition: opacity 0.16s ease, transform 0.16s ease;
    pointer-events: none;
}

.sf-update-image-wrap:hover::after {
    opacity: 1;
    transform: translateY(0);
}

.sf-update-lightbox {
    position: fixed;
    inset: 0;
    z-index: 2147483647;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px;
    background: rgba(15, 23, 42, 0.88);
    backdrop-filter: blur(10px);
}

.sf-update-lightbox.hidden {
    display: none;
}

.sf-update-lightbox img {
    max-width: min(1200px, 96vw);
    max-height: 88vh;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 14px;
    background: #ffffff;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
    transform-origin: center center;
}

.sf-update-lightbox-close {
    position: fixed;
    top: 22px;
    right: 22px;
    width: 46px;
    height: 46px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.28);
    background: rgba(15, 23, 42, 0.76);
    color: #ffffff;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
}

.sf-update-lightbox-close:hover {
    background: rgba(239, 68, 68, 0.9);
}
@keyframes sfLightboxIn {
    from {
        opacity: 0;
        transform: scale(0.96);
    }

    to {
        opacity: 1;
        transform: scale(1);
    }
}

@keyframes sfLightboxZoomIn {
    from {
        opacity: 0.35;
        width: var(--sf-lightbox-start-w);
        height: var(--sf-lightbox-start-h);
        transform:
            translate(
                calc(var(--sf-lightbox-start-x) - 50vw),
                calc(var(--sf-lightbox-start-y) - 50vh)
            )
            scale(1);
    }

    to {
        opacity: 1;
        width: auto;
        height: auto;
        transform: translate(0, 0) scale(1);
    }
}
	.sf-update-lightbox.is-preparing img {
    opacity: 0;
}

.sf-update-lightbox-zoom-clone {
    position: fixed;
    z-index: 2147483649;
    object-fit: cover;
    border-radius: 10px;
    background: #ffffff;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
    pointer-events: none;
    transition:
        left 0.26s cubic-bezier(0.2, 0.8, 0.2, 1),
        top 0.26s cubic-bezier(0.2, 0.8, 0.2, 1),
        width 0.26s cubic-bezier(0.2, 0.8, 0.2, 1),
        height 0.26s cubic-bezier(0.2, 0.8, 0.2, 1),
        border-radius 0.26s cubic-bezier(0.2, 0.8, 0.2, 1);
}
@media (max-width: 900px) {
    .sf-updates-hero {
        display: block;
        margin-bottom: 22px;
    }

    .sf-updates-description {
        margin-top: 1rem;
        font-size: 0.95rem;
    }

    .sf-updates-subscribe-card {
        width: 100%;
        margin: 22px 0 0;
        border-radius: 16px;
        padding: 12px 14px;
    }

    .sf-updates-filter {
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 18px;
        padding-bottom: 16px;
    }

    .sf-updates-filter-label {
        width: 100%;
        font-size: 0.86rem;
    }

    .sf-updates-filter-buttons {
        gap: 8px;
    }
	.sf-updates-filter-btn:hover {
    background: rgba(254, 224, 0, 0.12);
    border-color: rgba(254, 224, 0, 0.55);
    color: #ffffff;
}

    .sf-updates-item-body {
        padding: 24px 18px 18px;
        border-radius: 18px;
    }
}

@media (max-width: 420px) {
    .sf-updates-subscribe-title {
        font-size: 0.84rem;
    }

    .sf-updates-subscribe-icon {
        font-size: 1rem;
    }

    .sf-updates-filter-buttons {
        flex-wrap: nowrap;
        overflow-x: auto;
        max-width: 100%;
        padding-bottom: 2px;
        -webkit-overflow-scrolling: touch;
    }

    .sf-updates-filter-btn {
        flex: 0 0 auto;
    }
}
	.sf-updates-read-more {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    padding: 0 18px;
    border: 1px solid rgba(148, 163, 184, 0.32);
    border-radius: 999px;
    background: rgba(241, 245, 249, 0.96);
    color: #1e293b;
    font-size: 0.9rem;
    font-weight: 700;
    line-height: 1;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
    transition:
        transform 0.16s ease,
        background 0.16s ease,
        box-shadow 0.16s ease;
}

.sf-updates-read-more:hover {
    transform: translateY(-1px);
    background: #ffffff;
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.24);
}

.sf-updates-read-more:focus-visible {
    outline: 3px solid rgba(254, 224, 0, 0.55);
    outline-offset: 3px;
}
</style>

<script>
(function () {
var modal = document.getElementById('sfUpdateDetailModal');
var modalTitle = document.getElementById('sfUpdateDetailModalTitle');
var modalBody = document.getElementById('sfUpdateDetailModalBody');

var lightbox = document.getElementById('sfUpdateImageLightbox');
var lightboxImg = document.getElementById('sfUpdateImageLightboxImg');
var lightboxClose = lightbox ? lightbox.querySelector('.sf-update-lightbox-close') : null;

function openImageLightbox(src, sourceImage) {
    if (!lightbox || !lightboxImg || !src) return;

    if (lightbox.parentElement !== document.body) {
        document.body.appendChild(lightbox);
    } else {
        document.body.appendChild(lightbox);
    }

    if (!sourceImage) {
        lightboxImg.src = src;
        lightbox.classList.remove('hidden');
        lightbox.classList.remove('is-preparing');
        document.body.classList.add('sf-modal-open');

        if (lightboxClose) {
            lightboxClose.focus({ preventScroll: true });
        }

        return;
    }

    var sourceRect = sourceImage.getBoundingClientRect();

    var clone = document.createElement('img');
    clone.src = src;
    clone.className = 'sf-update-lightbox-zoom-clone';
    clone.style.left = sourceRect.left + 'px';
    clone.style.top = sourceRect.top + 'px';
    clone.style.width = sourceRect.width + 'px';
    clone.style.height = sourceRect.height + 'px';

    document.body.appendChild(clone);

    lightboxImg.src = src;
    lightbox.classList.remove('hidden');
    lightbox.classList.add('is-preparing');
    document.body.classList.add('sf-modal-open');

    requestAnimationFrame(function () {
        var viewportWidth = window.innerWidth;
        var viewportHeight = window.innerHeight;

        var targetWidth = Math.min(viewportWidth * 0.96, 1200);
        var targetHeight = Math.min(viewportHeight * 0.88, viewportHeight - 64);

        var imageRatio = sourceImage.naturalWidth && sourceImage.naturalHeight
            ? sourceImage.naturalWidth / sourceImage.naturalHeight
            : sourceRect.width / sourceRect.height;

        if (targetWidth / targetHeight > imageRatio) {
            targetWidth = targetHeight * imageRatio;
        } else {
            targetHeight = targetWidth / imageRatio;
        }

        clone.style.left = ((viewportWidth - targetWidth) / 2) + 'px';
        clone.style.top = ((viewportHeight - targetHeight) / 2) + 'px';
        clone.style.width = targetWidth + 'px';
        clone.style.height = targetHeight + 'px';
        clone.style.borderRadius = '14px';
    });

    window.setTimeout(function () {
        clone.remove();
        lightbox.classList.remove('is-preparing');

        if (lightboxClose) {
            lightboxClose.focus({ preventScroll: true });
        }
    }, 260);
}

function closeImageLightbox() {
    if (!lightbox || !lightboxImg) return;

    lightbox.classList.add('hidden');
    lightboxImg.src = '';

    if (!modal || modal.classList.contains('hidden')) {
        document.body.classList.remove('sf-modal-open');
    }
}

    function openUpdateModal(entryId) {
        var titleBtn = document.querySelector('.sf-updates-title-btn[data-entry-id="' + entryId + '"]');
        var contentEl = document.getElementById('sf-update-content-' + entryId);

        if (modalTitle) {
            modalTitle.textContent = titleBtn ? titleBtn.textContent.trim() : '';
        }
        if (modalBody && contentEl) {
            var textEl = contentEl.querySelector('.sf-update-text-content');
            var imagesEl = contentEl.querySelector('.sf-update-images');

            if (textEl && imagesEl) {
                var grid = document.createElement('div');
                grid.className = 'sf-updates-modal-body-grid';

                var textCol = document.createElement('div');
                textCol.className = 'sf-updates-modal-body-text';
                textCol.innerHTML = textEl.innerHTML;

                var imagesCol = document.createElement('div');
                imagesCol.className = 'sf-updates-modal-body-images';
                imagesCol.innerHTML = imagesEl.innerHTML;

                grid.appendChild(textCol);
                grid.appendChild(imagesCol);
                modalBody.innerHTML = '';
                modalBody.appendChild(grid);
            } else {
                modalBody.innerHTML = contentEl.innerHTML;
            }
        }

        // Toggle wider modal if images are present
        var modalContent = modal ? modal.querySelector('.sf-updates-modal-content') : null;
        if (modalContent) {
            var hasImages = modalBody && modalBody.querySelector('.sf-update-images, .sf-updates-modal-body-images');
            modalContent.classList.toggle('has-images', !!hasImages);
        }

if (modal) {
    if (typeof window.sfOpenModal === 'function') {
        window.sfOpenModal(modal);
    } else {
        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');

        var closeBtn = modal.querySelector('.sf-modal-close-btn');
        if (closeBtn) {
            closeBtn.focus({ preventScroll: true });
        }
    }
}
    }

document.addEventListener('click', function (e) {
    var image = e.target.closest('.sf-update-image');
    if (image) {
        e.preventDefault();
        openImageLightbox(image.currentSrc || image.src, image);
        return;
    }

    var trigger = e.target.closest('.sf-updates-title-btn, .sf-updates-read-more, .sf-updates-item-image-preview');
    if (trigger) {
        e.preventDefault();
        var entryId = parseInt(trigger.dataset.entryId, 10);
        if (entryId > 0) {
            openUpdateModal(entryId);
        }
    }
});

if (lightbox) {
    lightbox.addEventListener('click', function (e) {
        if (e.target === lightbox) {
            closeImageLightbox();
        }
    });
}

if (lightboxClose) {
    lightboxClose.addEventListener('click', closeImageLightbox);
}

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') { return; }
        var trigger = e.target.closest('.sf-updates-item-image-preview');
        if (trigger) {
            e.preventDefault();
            var entryId = parseInt(trigger.dataset.entryId, 10);
            if (entryId > 0) {
                openUpdateModal(entryId);
            }
        }
    });

    // Month filter
    var filterBtns = document.querySelectorAll('.sf-updates-filter-btn');
    if (filterBtns.length) {
        filterBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var month = this.dataset.month;
                // Toggle active state on buttons
                filterBtns.forEach(function (b) {
                    b.classList.remove('sf-btn-primary');
                    b.classList.add('sf-btn-secondary');
                });
                this.classList.add('sf-btn-primary');
                this.classList.remove('sf-btn-secondary');
                // Show/hide timeline items
                var items = document.querySelectorAll('#sfUpdatesTimeline .sf-updates-item');
                items.forEach(function (item) {
                    if (month === 'all' || item.dataset.month === month) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        });
    }
	document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && lightbox && !lightbox.classList.contains('hidden')) {
        closeImageLightbox();
    }
});
})();
</script>