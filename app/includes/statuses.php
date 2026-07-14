<?php
/**
 * SafetyFlash – Status definitions and helpers
 */

declare(strict_types=1);

if (!defined('SAFETYFLASH_STATUSES_LOADED')) {
    define('SAFETYFLASH_STATUSES_LOADED', true);
}

/**
 * Palauttaa kaikki statusmäärittelyt assosiatiivisena taulukkona.
 *
 * @return array<string,array<string,mixed>>
 */
function sf_status_definitions(): array
{
    static $definitions = null;

    if ($definitions !== null) {
        return $definitions;
    }

    $definitions = [
        'draft' => [
            'key'   => 'draft',
            'group' => 'open',
            'level' => 'info',
            'labels' => [
                'fi' => 'Luonnos',
                'sv' => 'Utkast',
                'en' => 'Draft',
                'it' => 'Bozza',
                'el' => 'Πρόχειρο',
            ],
            'descriptions' => [
                'fi' => 'Tiedotetta valmistellaan, ei vielä arvioitavana.',
                'sv' => 'Meddelandet förbereds, inte ännu under granskning.',
                'en' => 'Flash is being prepared, not yet under review.',
                'it' => 'La segnalazione è in preparazione, non ancora in revisione.',
                'el' => 'Το δελτίο ετοιμάζεται, δεν είναι ακόμη υπό αξιολόγηση.',
            ],
            'badge_class' => 'sf-status sf-status--draft',
        ],

'pending_supervisor' => [
    'key'   => 'pending_supervisor',
    'group' => 'open',
    'level' => 'warning',
    'labels' => [
        'fi' => 'Työmaavastaavan tarkistuksessa',
        'sv' => 'Under platsansvarigs granskning',
        'en' => 'In Site Supervisor Review',
        'it' => 'In verifica del responsabile del sito',
        'el' => 'Σε έλεγχο από τον υπεύθυνο εργοταξίου',
    ],
    'descriptions' => [
        'fi' => 'Tiedote odottaa työmaavastaavan hyväksyntää.',
        'sv' => 'Meddelandet väntar på arbetsledarens godkännande.',
        'en' => 'Flash is awaiting site manager approval.',
        'it' => 'La segnalazione è in attesa dell\'approvazione del responsabile del sito.',
        'el' => 'Το δελτίο αναμένει την έγκριση του υπεύθυνου εργοταξίου.',
    ],
    'badge_class' => 'sf-status sf-status--pending-supervisor',
],

'pending_review' => [
    'key'   => 'pending_review',
    'group' => 'open',
    'level' => 'warning',
    'labels' => [
        'fi' => 'Turvatiimin tarkistuksessa',
        'sv' => 'Under säkerhetsteamets granskning',
        'en' => 'In Safety Team Review',
        'it' => 'In verifica del team sicurezza',
        'el' => 'Σε έλεγχο από την ομάδα ασφάλειας',
    ],
    'descriptions' => [
        'fi' => 'Tiedote odottaa turvatiimin tarkistusta.',
        'sv' => 'Meddelandet väntar på säkerhetsteamets granskning.',
        'en' => 'Flash is awaiting safety team review.',
        'it' => 'La segnalazione è in attesa della revisione del team sicurezza.',
        'el' => 'Το δελτίο αναμένει έλεγχο από την ομάδα ασφάλειας.',
    ],
    'badge_class' => 'sf-status sf-status--pending',
],

        'request_info' => [
            'key'   => 'request_info',
            'group' => 'open',
            'level' => 'warning',
            'labels' => [
                'fi' => 'Lisätietoa pyydetty',
                'sv' => 'Mer information begärd',
                'en' => 'More information requested',
                'it' => 'Richieste maggiori informazioni',
                'el' => 'Ζητήθηκαν περισσότερες πληροφορίες',
            ],
            'descriptions' => [
                'fi' => 'Vastuuhenkilö on pyytänyt lisätietoa tekijältä.',
                'sv' => 'Granskaren har begärt mer information från skaparen.',
                'en' => 'Reviewer has requested more information from the creator.',
                'it' => 'Il revisore ha richiesto maggiori informazioni al creatore.',
                'el' => 'Ο αξιολογητής ζήτησε περισσότερες πληροφορίες από τον συντάκτη.',
            ],
            'badge_class' => 'sf-status sf-status--request-info',
        ],

        'in_investigation' => [
            'key'   => 'in_investigation',
            'group' => 'open',
            'level' => 'info',
            'labels' => [
                'fi' => 'Tutkinnassa',
                'sv' => 'Under utredning',
                'en' => 'In investigation',
                'it' => 'In indagine',
                'el' => 'Υπό διερεύνηση',
            ],
            'descriptions' => [
                'fi' => 'Tapausta tutkitaan tarkemmin (tutkintatiedote työn alla).',
                'sv' => 'Fallet utreds närmare (utredningsmeddelande pågår).',
                'en' => 'Case is under deeper investigation (investigation flash in progress).',
                'it' => 'Il caso è oggetto di indagine approfondita (segnalazione d’indagine in preparazione).',
                'el' => 'Η υπόθεση διερευνάται περαιτέρω (δελτίο διερεύνησης σε εξέλιξη).',
            ],
            'badge_class' => 'sf-status sf-status--investigation',
        ],

        'to_final_approver' => [
            'key'   => 'to_final_approver',
            'group' => 'open',
            'level' => 'info',
            'labels' => [
                'fi' => 'Lopullisella hyväksyjällä',
                'sv' => 'Hos slutlig godkännare',
                'en' => 'With final approver',
                'it' => 'Dal responsabile finale',
                'el' => 'Στον τελικό εγκριτή',
            ],
            'descriptions' => [
                'fi' => 'Tiedote on lähetetty lopulliselle hyväksyjälle.',
                'sv' => 'Meddelandet har skickats till slutlig godkännare.',
                'en' => 'Flash has been sent to final approver.',
                'it' => 'La segnalazione è stata inviata al responsabile finale.',
                'el' => 'Το δελτίο στάλθηκε στον τελικό εγκριτή.',
            ],
            'badge_class' => 'sf-status sf-status--final-approver',
        ],

        'to_comms' => [
            'key'   => 'to_comms',
            'group' => 'open',
            'level' => 'info',
            'labels' => [
                'fi' => 'Viestinnällä',
                'sv' => 'Hos kommunikation',
                'en' => 'In communications',
                'it' => 'In comunicazione',
                'el' => 'Στην επικοινωνία',
            ],
            'descriptions' => [
                'fi' => 'Tiedote on viestinnän käsiteltävänä.',
                'sv' => 'Meddelandet hanteras av kommunikation.',
                'en' => 'Flash is being handled by communications.',
                'it' => 'La segnalazione è gestita dal team comunicazione.',
                'el' => 'Το δελτίο βρίσκεται υπό διαχείριση από την επικοινωνία.',
            ],
            'badge_class' => 'sf-status sf-status--comms',
        ],

        'awaiting_publish' => [
            'key'   => 'awaiting_publish',
            'group' => 'open',
            'level' => 'info',
            'labels' => [
                'fi' => 'Odottaa julkaisua',
                'sv' => 'Väntar på publicering',
                'en' => 'Awaiting publish',
                'it' => 'In attesa di pubblicazione',
                'el' => 'Αναμένει δημοσίευση',
            ],
            'descriptions' => [
                'fi' => 'Kieliversio on valmis ja odottaa erillistä julkaisua omille näytöilleen.',
                'sv' => 'Språkversionen är klar och väntar på separat publicering till sina egna skärmar.',
                'en' => 'The language version is ready and awaiting separate publishing to its own displays.',
                'it' => 'La versione linguistica è pronta e in attesa di pubblicazione separata sui propri schermi.',
                'el' => 'Η γλωσσική έκδοση είναι έτοιμη και αναμένει ξεχωριστή δημοσίευση στις δικές της οθόνες.',
            ],
            'badge_class' => 'sf-status sf-status--awaiting-publish',
        ],

        'published' => [
            'key'   => 'published',
            'group' => 'closed',
            'level' => 'success',
            'labels' => [
                'fi' => 'Julkaistu',
                'sv' => 'Publicerad',
                'en' => 'Published',
                'it' => 'Pubblicato',
                'el' => 'Δημοσιεύτηκε',
            ],
            'descriptions' => [
                'fi' => 'Tiedote on julkaistu SafetyFlash-näytöille.',
                'sv' => 'Meddelandet har publicerats på SafetyFlash-skärmar.',
                'en' => 'Flash has been published to SafetyFlash screens.',
                'it' => 'La segnalazione è stata pubblicata sugli schermi SafetyFlash.',
                'el' => 'Το δελτίο δημοσιεύτηκε στις οθόνες SafetyFlash.',
            ],
            'badge_class' => 'sf-status sf-status--published',
        ],

        'rejected' => [
            'key'   => 'rejected',
            'group' => 'closed',
            'level' => 'danger',
            'labels' => [
                'fi' => 'Hylätty',
                'sv' => 'Avvisad',
                'en' => 'Rejected',
                'it' => 'Respinto',
                'el' => 'Απορρίφθηκε',
            ],
            'descriptions' => [
                'fi' => 'Tiedotetta ei hyväksytty julkaistavaksi.',
                'sv' => 'Meddelandet godkändes inte för publicering.',
                'en' => 'Flash was not approved for publishing.',
                'it' => 'La segnalazione non è stata approvata per la pubblicazione.',
                'el' => 'Το δελτίο δεν εγκρίθηκε για δημοσίευση.',
            ],
            'badge_class' => 'sf-status sf-status--rejected',
        ],

        'archived' => [
            'key'   => 'archived',
            'group' => 'closed',
            'level' => 'info',
            'labels' => [
                'fi' => 'Arkistoitu',
                'sv' => 'Arkiverad',
                'en' => 'Archived',
                'it' => 'Archiviato',
                'el' => 'Αρχειοθετήθηκε',
            ],
            'descriptions' => [
                'fi' => 'Tiedote on suljettu ja siirretty arkistoon.',
                'sv' => 'Meddelandet har stängts och flyttats till arkivet.',
                'en' => 'Flash has been closed and moved to archive.',
                'it' => 'La segnalazione è stata chiusa e spostata in archivio.',
                'el' => 'Το δελτίο έκλεισε και μεταφέρθηκε στο αρχείο.',
            ],
            'badge_class' => 'sf-status sf-status--archived',
        ],

        'closed' => [
            'key'   => 'closed',
            'group' => 'closed',
            'level' => 'success',
            'labels' => [
                'fi' => 'Suljettu',
                'sv' => 'Stängd',
                'en' => 'Closed',
                'it' => 'Chiuso',
                'el' => 'Κλειστό',
            ],
            'descriptions' => [
                'fi' => 'Tapaus on käsitelty loppuun.',
                'sv' => 'Ärendet är helt hanterat.',
                'en' => 'Case has been fully handled.',
                'it' => 'Il caso è stato gestito completamente.',
                'el' => 'Η υπόθεση έχει ολοκληρωθεί.',
            ],
            'badge_class' => 'sf-status sf-status--closed',
        ],
    ];

    return $definitions;
}

/** Alias vanhalle nimelle. */
function sf_statuses(): array
{
    return sf_status_definitions();
}

function sf_status_exists(string $key): bool
{
    $defs = sf_status_definitions();
    return isset($defs[$key]);
}

/**
 * @return array<string,mixed>|null
 */
function sf_status_get(string $key): ?array
{
    $defs = sf_status_definitions();
    return $defs[$key] ?? null;
}

function sf_status_label(string $key, string $lang = 'fi'): string
{
    $def = sf_status_get($key);
    if (!$def) {
        return $key;
    }

    $labels = $def['labels'] ?? [];
    if (isset($labels[$lang])) {
        return $labels[$lang];
    }

    // Fallback-järjestys
    if (isset($labels['fi'])) return $labels['fi'];
    if (isset($labels['en'])) return $labels['en'];

    // Jos ei löydy, palauta avain
    return $key;
}

function sf_status_description(string $key, string $lang = 'fi'): string
{
    $def = sf_status_get($key);
    if (!$def) {
        return '';
    }

    $descriptions = $def['descriptions'] ?? [];
    if (isset($descriptions[$lang])) {
        return $descriptions[$lang];
    }

    // Fallback-järjestys
    if (isset($descriptions['fi'])) return $descriptions['fi'];
    if (isset($descriptions['en'])) return $descriptions['en'];

    return '';
}

function sf_status_badge(string $key, string $lang = 'fi', string $extraClass = ''): string
{
    $def = sf_status_get($key);
    $label = sf_status_label($key, $lang);

    $badgeClass = $def['badge_class'] ?? 'sf-status';
    $classes = trim($badgeClass . ' ' . $extraClass);

    return '<span class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '">' .
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
        '</span>';
}

/**
 * Listan riviluokka – yhteensopivuus vanhan koodin kanssa.
 * list.php todennäköisesti kutsuu: sf_status_list_class($flash['status'])
 */
function sf_status_list_class(string $key): string
{
    $def = sf_status_get($key);
    if (!$def) {
        return 'sf-row sf-row--status-unknown';
    }

    $group = $def['group'] ?? 'open';
    $level = $def['level'] ?? 'info';

    $classes = [
        'sf-row',
        'sf-row--group-' . $group,
        'sf-row--level-' . $level,
        'sf-row--status-' . $key,
    ];

    return implode(' ', $classes);
}

/**
 * Placeholderien korvaus lokiteksteissä: [status:draft] → badge.
 */
function sf_status_inject_badges(string $text, string $lang = 'fi'): string
{
    return preg_replace_callback(
        '/\[status:([a-z0-9_]+)\]/i',
        function (array $matches) use ($lang): string {
            $key = $matches[1] ?? '';
            if (!sf_status_exists($key)) {
                return $matches[0];
            }
            return sf_status_badge($key, $lang);
        },
        $text
    );
}

/**
 * Korvaa lokiteksteissä olevat status-avainsanat luettaviksi labeleiksi.
 * Esim. "Tila: draft" → "Tila: Luonnos"
 * Tai "[status:draft]" → status badge.
 *
 * @param string $text  Lokirivin description-teksti
 * @param string $lang  Käyttöliittymän kieli (fi/sv/en/it/el)
 * @return string       HTML-turvallinen teksti, jossa statukset korvattu
 */
function sf_log_status_replace(string $text, string $lang = 'fi'): string
{
    // HTML sanitized by view.php strip_tags() - no escaping needed here
    $safe = $text; //

    // Korvataan [status:xxx] placeholderit badgeiksi (HUOM: sf_status_badge tekee itsekin escapea labeliin)
    $safe = sf_status_inject_badges($safe, $lang);

    // Korvataan myös "Tila: xxx" -muotoiset tekstit, joissa xxx on status-avain
    $definitions = sf_status_definitions();
    $statusKeys = array_keys($definitions);

    foreach ($statusKeys as $key) {
        $label = sf_status_label($key, $lang);

        // Korvaa esim. "Tila: draft" → "Tila: Luonnos"
        // Tukee myös "Status:" ja "State:" (vanha käytäntö)
        $safe = preg_replace(
            '/\b(Tila|Status|State):\s*' . preg_quote($key, '/') . '\b/i',
            '$1: ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            $safe
        );
    }

    return $safe;
}