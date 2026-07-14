<?php
/**
 * PreviewImageGenerator - Server-side preview image generation using Imagick
 * 
 * Generates 1920x1080 JPEG preview images from SafetyFlash data
 * Uses pre-designed template backgrounds and overlays text and images using Imagick
 * 
 * @package SafetyFlash
 * @subpackage Services
 */

declare(strict_types=1);

class PreviewImageGenerator
{
    private const WIDTH = 1920;
    private const HEIGHT = 1080;
    private const QUALITY = 85;
    
    // Color definitions
    private const COLORS = [
        'yellow' => '#FEE000',
        'red' => '#C81E1E',
        'green' => '#009650',
        'black' => '#000000',
        'white' => '#FFFFFF',
        'gray_light' => '#F0F0F0',
        'gray_dark' => '#3C3C3C',
        'black_box' => '#1a1a1a',  // For header boxes and backgrounds
    ];
    
    // --- YLEISET MARGINAALIT ---
    private const LEFT_MARGIN = 116; 
    private const START_Y = 220;     // Mustan yläpalkin alku
    
    // 1. LYHYT KUVAUS (Musta laatikko)
    // Kuvassa: 920 x 100 px
    private const TITLE_X = 116;
    private const TITLE_Y = 220;
    private const TITLE_WIDTH = 920;
    private const TITLE_HEIGHT = 100;
    
    // 2. PITKÄ KUVAUS (Ylempi sininen laatikko)
    // Kuvassa: 920 x 225 px
    private const DESC_X = 116;
    private const DESC_Y = 320;      // 220 + 100
    private const DESC_WIDTH = 920;
    private const DESC_HEIGHT = 225;
    
    // 3. JAETTU ALUE (Juurisyyt & Toimenpiteet)
    // Sijainti: Pitkän kuvauksen alla
    // Laskettu Y: 320 (alku) + 225 (korkeus) + 20 (väli) = 565
    private const SPLIT_Y = 565;
    private const SPLIT_WIDTH = 450; // Sama leveys kuin meta-laatikoilla
    private const SPLIT_HEIGHT = 290; // Tila ennen meta-laatikoita
    
    private const ROOT_X = 116;           // Vasen palsta
    private const ACTION_X = 586;         // Oikea palsta (116 + 450 + 20px väli)
    
    // 4. METATIEDOT (Harmaat laatikot alhaalla)
    // Kuvassa: 450 x 115 px
    // Sijainti: 90px alareunasta -> Y = 1080 - 90 - 115 = 875
    private const META_Y = 875;
    private const META_SITE_BOX_WIDTH = 540;
    private const META_DATE_BOX_WIDTH = 360;
    private const META_BOX_HEIGHT = 115;
    private const META_BOX1_X = 116;   // Paikka/TYÖMAA
    private const META_BOX2_X = 676;  // 116 + 540 + 20px väli
    private const META_LABEL_SIZE = 18;
    private const META_VALUE_SIZE = 22;
    private const META_VALUE_OFFSET = 30;
    private const META_PADDING_LEFT = 15;   // Internal left padding
    private const META_PADDING_TOP = 20;    // Internal top padding
    private const META_TEXT_WRAP = 25;  // Max characters per line in meta box
    private const META_LINE_HEIGHT = 22;  // Line height for wrapped meta text
    private const META_MAX_LINES = 3;  // Maximum lines for meta text to prevent overflow
    // Gray background for meta boxes - hex color with alpha
   private const META_BG_COLOR = '#D8D8D8';
    private const META_BG_OPACITY = 0.85;
    
    // 5. KUVA-ALUE (Pinkki)
    private const IMAGE_X = 1086; // 116 + 920 + 50
    private const IMAGE_Y = 220;
    private const IMAGE_WIDTH = 750;
    private const IMAGE_HEIGHT = 800;
    
    // --- FONTIT ---
    private const FONT_TITLE = 42;    // Mahtuu 100px korkeuteen
    private const FONT_BODY = 28;     // Mahtuu hyvin 225px ja jaettuihin laatikoihin
    private const LINE_HEIGHT = 36;
    
    // Split view layout padding and spacing
    private const SPLIT_VIEW_PADDING = 20;  // Internal padding for content boxes
    private const SPLIT_VIEW_PADDING_SMALL = 15;  // Smaller padding for section content
    private const SPLIT_VIEW_HEADER_HEIGHT = 40;  // Height of section headers (Root Causes, Actions)
    private const SPLIT_VIEW_HEADER_OFFSET = 5;  // Vertical offset for header text positioning
    private const SPLIT_VIEW_TOP_PADDING = 10;  // Top padding for description and section content
    private const SPLIT_VIEW_LINE_HEIGHT_MULTIPLIER = 1.3;  // Line height multiplier for content sections
    private const SPLIT_VIEW_META_BUFFER = 20;  // Buffer space between content and meta boxes to prevent overlap
    private const SPLIT_VIEW_MIN_CONTENT_LINES = 5;  // Minimum lines for root causes/actions content
    private const SPLIT_VIEW_MAX_CONTENT_LINES = 10;  // Maximum lines for root causes/actions content
    
    // Legacy constants for backward compatibility with existing layouts
    private const TITLE_FONT_SIZE = 38;  // Fits in 160px height
    private const TITLE_LINE_HEIGHT = 45;
    private const DESC_FONT_SIZE = 26;
    private const DESC_LINE_HEIGHT = 30;
    private const DESC_WRAP = 75;  // Characters per line at 26px font in 920px width
    
    // Character limits for single slide green type (matching capture.js logic)
    private const CHAR_LIMIT_SINGLE_SLIDE = 900;  // Total character limit
    private const ROOT_CAUSES_SINGLE_LIMIT = 500;  // Root causes field limit
    private const ACTIONS_SINGLE_LIMIT = 500;      // Actions field limit
    private const DESC_SINGLE_LIMIT = 400;         // Description field limit
    private const ROOT_CAUSES_ACTIONS_COMBINED_LIMIT = 800;  // Combined root causes + actions limit
    
    // Line-based calculation constants for better accuracy
    private const MAX_COLUMN_LINES = 14;  // Max lines that fit in a column on single-slide layout
    private const CHARS_PER_LINE = 45;    // Average characters per line
    
    // Font size ratios (proportional scaling) - same as JavaScript
    private const FONT_RATIOS = [
        'shortTitle' => 1.6,
        'description' => 1.0,
        'rootCauses' => 0.9,
        'actions' => 0.9,
    ];

    // Preset sizes (base size for description) - same as JavaScript
    private const FONT_PRESETS = [
        'XS' => 14,
        'S' => 16,
        'M' => 18,
        'L' => 20,
        'XL' => 22,
    ];

    // Font size calculation constants - aligned with PreviewRenderer and frontend JS
    private const FONT_SIZE_AUTO_MAX = 24;  // Maximum base size for auto mode
    private const FONT_SIZE_AUTO_MIN = 14;  // Minimum base size for auto mode
    private const FONT_SIZE_AUTO_STEP = 1;  // Step size when searching for optimal size

    // Layout constraint constants for card fitting calculations - same as JavaScript
    private const CARD1_DESC_MAX_HEIGHT = 420;   // Max height for description on card 1
    private const CARD1_DESC_WIDTH = 880;        // Width for description text (TEXT_COL_WIDTH 920 - 40 padding)
    private const COLUMN_MAX_HEIGHT = 400;       // Max height for root causes/actions columns
    private const COLUMN_WIDTH = 420;            // Width for columns ((920-20)/2 - 30 padding)
    private const HEADERS_SPACING = 100;         // Extra space for headers and spacing (header boxes + gaps)
    private const SINGLE_CARD_MAX_HEIGHT = 850;  // Total max height for single card
    private const CHAR_WIDTH_RATIO = 0.48;       // Approximate character width as ratio of font size
                                                  // (calibrated for Open Sans font - actual average ~0.48)
    
    private ?PDO $pdo;
    private string $uploadsDir;
    private string $previewsDir;
    private string $templatesDir;
    
    public function __construct(?PDO $pdo, string $uploadsDir, string $previewsDir)
    {
        $this->pdo = $pdo;
        $this->uploadsDir = rtrim($uploadsDir, '/');
        $this->previewsDir = rtrim($previewsDir, '/');
        $this->templatesDir = dirname(__DIR__, 2) . '/assets/img/templates';
        
        if (!extension_loaded('imagick')) {
            throw new RuntimeException('Imagick extension is not loaded');
        }
        
        if (!is_dir($this->previewsDir)) {
            @mkdir($this->previewsDir, 0755, true);
        }
        
        if (!is_dir($this->templatesDir)) {
            throw new RuntimeException('Templates directory not found: ' . $this->templatesDir);
        }
    }
    
    /**
     * Generate preview image for a flash
     * 
     * @param array $flashData Flash data from database
     * @return array|string|null Returns array with both filenames for two-card generation:
     *                           ['filename1' => 'card1.jpg', 'filename2' => 'card2.jpg']
     *                           Returns string for single card generation: 'card.jpg'
     *                           Returns null on failure
     */
    public function generate(array $flashData): array|string|null
    {
        try {
            $flashId = (int) ($flashData['id'] ?? 0);
            if ($flashId <= 0) {
                throw new RuntimeException('Invalid flash ID');
            }
            
            $type = $flashData['type'] ?? 'yellow';
            $lang = $flashData['lang'] ?? 'fi';
            
            // Improved logging
            error_log("PreviewImageGenerator: Starting generation for flash {$flashId}, type={$type}, lang={$lang}");
            
            // Check if green type needs two cards
            $needsSecondCard = ($type === 'green' && $this->needsSecondCard($flashData));
            error_log("PreviewImageGenerator: needsSecondCard=" . ($needsSecondCard ? 'true' : 'false'));
            
            // Generate filename(s) based on flash data
            $filename1 = $this->generateFilename($flashData, 1);
            $outputPath1 = $this->previewsDir . '/' . $filename1;
            
            // Get template path with fallback for missing two-card templates
            try {
                $templatePath1 = $this->getTemplatePath($type, $lang, $needsSecondCard ? 1 : null);
            } catch (RuntimeException $e) {
                error_log("PreviewImageGenerator: Two-card template not found, falling back to single-card: " . $e->getMessage());
                // Fallback to single-card template
                $templatePath1 = $this->getTemplatePath($type, $lang, null);
                $needsSecondCard = false;
            }
            
            error_log("PreviewImageGenerator: Using template: {$templatePath1}");
            
            // Render card 1
            $this->renderCard($flashData, $templatePath1, $outputPath1, 1);
            
            // Verify file was created
            if (!file_exists($outputPath1)) {
                throw new RuntimeException('Output file was not created: ' . $outputPath1);
            }
            
            error_log("PreviewImageGenerator: Card 1 generated successfully: {$filename1}");
            
            // If green type needs second card, generate it
            if ($needsSecondCard) {
                $filename2 = $this->generateFilename($flashData, 2);
                $outputPath2 = $this->previewsDir . '/' . $filename2;
                
                try {
                    $templatePath2 = $this->getTemplatePath($type, $lang, 2);
                } catch (RuntimeException $e) {
                    error_log("PreviewImageGenerator: Card 2 template not found: " . $e->getMessage());
                    // Return only card 1 if card 2 template is missing
                    return $filename1;
                }
                
                $this->renderCard($flashData, $templatePath2, $outputPath2, 2);
                
                if (!file_exists($outputPath2)) {
                    error_log("PreviewImageGenerator: Card 2 file was not created, returning only card 1");
                    return $filename1;
                }
                
                error_log("PreviewImageGenerator: Card 2 generated successfully: {$filename2}");
                
                // Return BOTH filenames as array
                return [
                    'filename1' => $filename1,
                    'filename2' => $filename2
                ];
            }
            
            return $filename1;
            
        } catch (Throwable $e) {
            error_log('PreviewImageGenerator::generate failed for flash ' . ($flashData['id'] ?? 'unknown') . ': ' . $e->getMessage());
            error_log('PreviewImageGenerator::generate stack trace: ' . $e->getTraceAsString());
            return null;
        }
    }
    
    /**
     * Generate descriptive filename for preview
     */
    private function generateFilename(array $flashData, int $cardNumber = 1): string
    {
        $site = $flashData['site'] ?? 'Site';
        $title = $flashData['title_short'] ?? $flashData['summary'] ?? 'Flash';
        $lang = strtoupper($flashData['lang'] ?? 'FI');
        $type = strtoupper($flashData['type'] ?? 'YELLOW');
        
        $occurredAt = $flashData['occurred_at'] ?? null;
        $date = $occurredAt ? date('Y_m_d', strtotime($occurredAt)) : date('Y_m_d');
        
        // Sanitize for filename - transliterate unicode to ASCII if possible
        $siteSafe = $this->sanitizeFilename($site, 30);
        $titleSafe = $this->sanitizeFilename($title, 50);
        
        if (trim($siteSafe) === '') $siteSafe = 'Site';
        if (trim($titleSafe) === '') $titleSafe = 'Flash';
        
        $cardSuffix = $cardNumber > 1 ? "_{$cardNumber}" : '';
        
        return "SF_{$date}_{$type}_{$siteSafe}-{$titleSafe}-{$lang}{$cardSuffix}.jpg";
    }
    
    /**
     * Sanitize string for use in filename
     */
    private function sanitizeFilename(string $text, int $maxLength): string
    {
        // Try to transliterate unicode characters to ASCII
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        }
        
        // Remove any remaining non-alphanumeric characters (except dash and underscore)
        $text = preg_replace('/[^a-zA-Z0-9\-_]/', '', $text);
        
        return substr($text, 0, $maxLength);
    }
    
    /**
     * Get template path for given type, language, and card number
     */
    private function getTemplatePath(string $type, string $lang, ?int $cardNumber): string
    {
        if ($type === 'green' && $cardNumber !== null) {
            // Two-card green model
            $filename = "SF_bg_green_{$cardNumber}_{$lang}.jpg";
        } else {
            // Single card (red, yellow, or single-slide green)
            $filename = "SF_bg_{$type}_{$lang}.jpg";
        }
        
        $path = $this->templatesDir . '/' . $filename;
        
        if (!file_exists($path)) {
            throw new RuntimeException("Template not found: {$filename}");
        }
        
        return $path;
    }
    
    /**
     * Estimate the number of lines needed to display text
     * Takes into account line breaks (bullets) and text wrapping
     * @param string $text Text to estimate
     * @param int $charsPerLine Average characters per line
     * @return int Estimated number of lines
     */
    private function estimateLines(string $text, int $charsPerLine = self::CHARS_PER_LINE): int
    {
        if (empty($text)) {
            return 0;
        }
        
        $lines = 0;
        $paragraphs = explode("\n", $text);
        
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            
            // Each paragraph/bullet point is at least 1 line
            // Additional lines based on character count
            $lines += max(1, (int)ceil(mb_strlen($p) / $charsPerLine));
        }
        
        return $lines;
    }
    
    /**
     * Calculate all font sizes from base size using ratios
     */
    private function calculateFontSizes(int $baseSize): array
    {
        return [
            'shortTitle' => (int) round($baseSize * self::FONT_RATIOS['shortTitle']),
            'description' => (int) round($baseSize * self::FONT_RATIOS['description']),
            'rootCauses' => (int) round($baseSize * self::FONT_RATIOS['rootCauses']),
            'actions' => (int) round($baseSize * self::FONT_RATIOS['actions']),
        ];
    }

    /**
     * Get font sizes based on user preference or auto-calculation
     */
    private function getFontSizes(array $flashData): array
    {
        $overrideRaw = $flashData['font_size_override'] ?? null;
        $override = is_string($overrideRaw) ? trim($overrideRaw) : $overrideRaw;
        $overrideUpper = is_string($override) ? strtoupper($override) : $override;
        $overrideLower = is_string($override) ? strtolower($override) : $override;
        $type = $flashData['type'] ?? 'yellow';

        if ($type === 'green') {
            // Auto = laske optimaalinen koko yhdelle kortille
            if ($override === null || $override === '' || $overrideLower === 'auto') {
                $baseSize = $this->calculateOptimalBaseSizeFrom($flashData, self::FONT_SIZE_AUTO_MAX);
                return $this->calculateFontSizes($baseSize);
            }

            if (is_numeric($override)) {
                $size = max(self::FONT_SIZE_AUTO_MIN, min(self::FONT_SIZE_AUTO_MAX, (int) $override));
                return $this->calculateFontSizes($size);
            }

            // Manuaalinen valinta = käytä täsmälleen käyttäjän valitsemaa kokoa
            if (is_string($overrideUpper) && isset(self::FONT_PRESETS[$overrideUpper])) {
                return $this->calculateFontSizes(self::FONT_PRESETS[$overrideUpper]);
            }

            // Fallback
            $baseSize = $this->calculateOptimalBaseSizeFrom($flashData, self::FONT_SIZE_AUTO_MAX);
            return $this->calculateFontSizes($baseSize);
        }

        if (is_numeric($override)) {
            $size = max(self::FONT_SIZE_AUTO_MIN, min(self::FONT_SIZE_AUTO_MAX, (int) $override));
            return $this->calculateFontSizes($size);
        }

        if (is_string($overrideUpper) && isset(self::FONT_PRESETS[$overrideUpper])) {
            return $this->calculateFontSizes(self::FONT_PRESETS[$overrideUpper]);
        }

        return $this->calculateFontSizes(self::FONT_SIZE_AUTO_MAX);
    }

    /**
     * Calculate optimal base size to fit content on single card
     */
    private function calculateOptimalBaseSize(array $flashData): int
    {
        return $this->calculateOptimalBaseSizeFrom($flashData, self::FONT_SIZE_AUTO_MAX);
    }

    /**
     * Calculate optimal base size starting from a maximum
     * Tries progressively smaller sizes until content fits
     */
    private function calculateOptimalBaseSizeFrom(array $flashData, int $maxBase): int
    {
        $title = trim((string) ($flashData['title_short'] ?? ''));
        $description = trim((string) ($flashData['description'] ?? ''));
        $rootCauses = trim((string) ($flashData['root_causes'] ?? ''));
        $actions = trim((string) ($flashData['actions'] ?? ''));
        
        // Try from largest to smallest
        for ($baseSize = $maxBase; $baseSize >= self::FONT_SIZE_AUTO_MIN; $baseSize -= self::FONT_SIZE_AUTO_STEP) {
            $sizes = $this->calculateFontSizes($baseSize);
            
            if ($this->contentFitsOnSingleCard($title, $description, $rootCauses, $actions, $sizes)) {
                return $baseSize;
            }
        }
        
        return self::FONT_SIZE_AUTO_MIN; // Minimum
    }

    /**
     * Check if content fits on single card with given font sizes
     */
    private function contentFitsOnSingleCard(
        string $title,
        string $description,
        string $rootCauses,
        string $actions,
        array $sizes
    ): bool {
        $layout = $this->buildGreenCardLayout([
            'type' => 'green',
            'title_short' => $title,
            'description' => $description,
            'root_causes' => $rootCauses,
            'actions' => $actions,
            'font_size_override' => null,
        ], $sizes);

        return !$layout['needs_second_card'];
    }

    private function buildGreenCardLayout(array $flashData, ?array $sizesOverride = null): array
    {
        $sizes = $sizesOverride ?: $this->getFontSizes($flashData);

        $description = $this->normalizePlainText((string) ($flashData['description'] ?? ''));
        $rootCauses = $this->formatBulletPoints($this->normalizePlainText((string) ($flashData['root_causes'] ?? '')));
        $actions = $this->formatBulletPoints($this->normalizePlainText((string) ($flashData['actions'] ?? '')));

        $descFontSize = (int) $sizes['description'];
        $contentFontSize = min((int) $sizes['rootCauses'], (int) $sizes['actions']);

        $descHeightSingle = $this->measurePlainTextHeight($description, self::CARD1_DESC_WIDTH, $descFontSize);

        $contentTopY = self::SPLIT_Y + self::SPLIT_VIEW_HEADER_HEIGHT + self::SPLIT_VIEW_TOP_PADDING + $contentFontSize;
        $metaTopY = self::META_Y - self::SPLIT_VIEW_META_BUFFER;
        $availableHeightSingle = max(0, ($metaTopY - 16) - $contentTopY);
        $availableHeightSingle = max(0, $availableHeightSingle - $contentFontSize - 10);

        [$rootCard1Single, $rootCard2Single] = $this->splitBulletContentForHeight(
            $rootCauses,
            self::SPLIT_WIDTH - (self::SPLIT_VIEW_PADDING_SMALL * 2),
            $contentFontSize,
            (int) $availableHeightSingle
        );

        [$actionsCard1Single, $actionsCard2Single] = $this->splitBulletContentForHeight(
            $actions,
            self::SPLIT_WIDTH - (self::SPLIT_VIEW_PADDING_SMALL * 2),
            $contentFontSize,
            (int) $availableHeightSingle
        );

        $fitsSingle =
            $descHeightSingle <= self::CARD1_DESC_MAX_HEIGHT &&
            $rootCard2Single === '' &&
            $actionsCard2Single === '';

        if ($fitsSingle) {
            return [
                'needs_second_card' => false,
                'card1' => [
                    'description' => $description,
                    'root_causes' => $rootCard1Single,
                    'actions' => $actionsCard1Single,
                ],
                'card2' => [
                    'description' => '',
                    'root_causes' => '',
                    'actions' => '',
                ],
            ];
        }

        $descriptionAvailableHeightCard1 = max(0, (self::META_Y - 10) - self::DESC_Y);
        [$card1Description, $card2Description] = $this->splitPlainTextForHeight(
            $description,
            self::DESC_WIDTH,
            $descFontSize,
            (int) $descriptionAvailableHeightCard1
        );

        $card2DescriptionHeight = 0;
        if (trim($card2Description) !== '') {
            $card2DescriptionHeight = max(
                130,
                45 + 30 + $this->measurePlainTextHeight($card2Description, 1690, $descFontSize)
            );
        }

        $columnsStartY = 390 + $card2DescriptionHeight + (trim($card2Description) !== '' ? 25 : 0);
        $card2AvailableHeight = max(260, (self::META_Y - 20) - ($columnsStartY + 45));

        [$card2RootCauses, $overflowRootCauses] = $this->splitBulletContentForHeight(
            $rootCauses,
            845 - 40,
            $contentFontSize,
            (int) $card2AvailableHeight
        );

        [$card2Actions, $overflowActions] = $this->splitBulletContentForHeight(
            $actions,
            845 - 40,
            $contentFontSize,
            (int) $card2AvailableHeight
        );

        if ($overflowRootCauses !== '' || $overflowActions !== '') {
            $card2RootCauses = $rootCauses;
            $card2Actions = $actions;
        }

        return [
            'needs_second_card' => true,
            'card1' => [
                'description' => $card1Description,
                'root_causes' => '',
                'actions' => '',
            ],
            'card2' => [
                'description' => $card2Description,
                'root_causes' => $card2RootCauses,
                'actions' => $card2Actions,
            ],
        ];
    }

    private function normalizePlainText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim((string) $text);
    }

    private function estimateLinesWithFontSize(
        string $text,
        int $maxWidth,
        int $fontSize,
        float $charWidthRatio = self::CHAR_WIDTH_RATIO,
        int $minCharsPerLine = 1
    ): int {
        $text = $this->normalizePlainText($text);

        if ($text === '') {
            return 0;
        }

        $charsPerLine = (int) floor($maxWidth / max(1, ($fontSize * $charWidthRatio)));
        $charsPerLine = max($minCharsPerLine, $charsPerLine);

        $lines = $this->wrapTextWithParagraphs($text, $charsPerLine);

        return count($lines);
    }

    private function measurePlainTextHeight(string $text, int $maxWidth, int $fontSize): int
    {
        $text = $this->normalizePlainText($text);

        if ($text === '') {
            return 0;
        }

        $lineHeight = (int) round($fontSize * 1.35);
        $charsPerLine = (int) floor($maxWidth / max(1, ($fontSize * 0.55)));
        $charsPerLine = max(18, $charsPerLine);

        $lines = $this->wrapTextWithParagraphs($text, $charsPerLine);

        return count($lines) * $lineHeight;
    }

    private function splitPlainTextForHeight(
        string $text,
        int $maxWidth,
        int $fontSize,
        int $availableHeight
    ): array {
        $text = $this->normalizePlainText($text);

        if ($text === '') {
            return ['', ''];
        }

        $lineHeight = (int) round($fontSize * 1.35);
        $maxLines = max(1, (int) floor($availableHeight / max(1, $lineHeight)));

        $charsPerLine = (int) floor($maxWidth / max(1, ($fontSize * 0.55)));
        $charsPerLine = max(18, $charsPerLine);

        $lines = $this->wrapTextWithParagraphs($text, $charsPerLine);

        if (count($lines) <= $maxLines) {
            return [$text, ''];
        }

        $first = implode("\n", array_slice($lines, 0, $maxLines));
        $rest = implode("\n", array_slice($lines, $maxLines));

        return [$this->normalizePlainText($first), $this->normalizePlainText($rest)];
    }

    private function measureBulletTextHeight(
        string $text,
        int $maxWidth,
        int $fontSize
    ): int {
        $text = trim((string) $text);

        if ($text === '') {
            return 0;
        }

        $lineHeight = (int) round($fontSize * 1.4);
        $itemSpacing = (int) round($lineHeight * 0.3);
        $bulletWidth = (int) round($fontSize * 1.2);

        $height = 0;

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (mb_strpos($trimmed, '• ') === 0) {
                $content = trim(mb_substr($trimmed, 2));
                $textWidth = max(1, $maxWidth - $bulletWidth);
            } else {
                $content = $trimmed;
                $textWidth = $maxWidth;
            }

            $lineCount = $this->estimateLinesWithFontSize($content, $textWidth, $fontSize, 0.55, 8);
            $height += max(1, $lineCount) * $lineHeight;
            $height += $itemSpacing;
        }

        return max(0, $height - $itemSpacing);
    }

    private function splitBulletItems(string $text): array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return [];
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $items = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $items[] = $line;
        }

        return $items;
    }

    private function joinBulletItems(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static function ($item) {
            return $item !== '';
        }));

        return implode("\n", $items);
    }

    private function splitBulletContentForHeight(
        string $text,
        int $maxWidth,
        int $fontSize,
        int $availableHeight
    ): array {
        $items = $this->splitBulletItems($text);

        if (empty($items)) {
            return ['', ''];
        }

        $firstCardItems = [];
        $currentHeight = 0;

        $lineHeight = (int) round($fontSize * 1.4);
        $itemSpacing = (int) round($lineHeight * 0.3);
        $bulletWidth = (int) round($fontSize * 1.2);

        foreach ($items as $item) {
            $trimmed = trim($item);

            if (mb_strpos($trimmed, '• ') === 0) {
                $content = trim(mb_substr($trimmed, 2));
                $textWidth = max(1, $maxWidth - $bulletWidth);
            } else {
                $content = $trimmed;
                $textWidth = $maxWidth;
            }

            $lineCount = $this->estimateLinesWithFontSize($content, $textWidth, $fontSize, 0.55, 8);
            $itemHeight = (max(1, $lineCount) * $lineHeight) + $itemSpacing;

            if (($currentHeight + $itemHeight) > $availableHeight) {
                break;
            }

            $firstCardItems[] = $item;
            $currentHeight += $itemHeight;
        }

        $remainingItems = array_slice($items, count($firstCardItems));

        return [
            $this->joinBulletItems($firstCardItems),
            $this->joinBulletItems($remainingItems),
        ];
    }

    private function needsSecondCard(array $flashData): bool
    {
        return $this->buildGreenCardLayout($flashData)['needs_second_card'];
    }

    public function needsSecondCardPublic(array $flashData): bool
    {
        return $this->needsSecondCard($flashData);
    }
    
    /**
     * Render a card using template background and Imagick text overlay
     */
    private function renderCard(array $flashData, string $templatePath, string $outputPath, int $cardNumber): void
    {
        $imagick = new Imagick($templatePath);
        
        try {
            $type = $flashData['type'] ?? 'yellow';
            $lang = $flashData['lang'] ?? 'fi';
            
            // Extract data
            $title = $flashData['title_short'] ?? $flashData['summary'] ?? '';
            $description = $flashData['description'] ?? '';
            $site = $flashData['site'] ?? '';
            $siteDetail = $flashData['site_detail'] ?? '';
            $occurredAt = $flashData['occurred_at'] ?? null;
            $rootCauses = $this->formatBulletPoints(trim((string) ($flashData['root_causes'] ?? '')));
            $actions = $this->formatBulletPoints(trim((string) ($flashData['actions'] ?? '')));
            
            // Format site text
            $siteText = $site;
            if ($siteDetail) {
                $siteText .= ' – ' . $siteDetail;
            }
            if (!$siteText) $siteText = '–';
            
            // Format date - handle ISO 8601 format properly
            $dateText = '–';
            if ($occurredAt) {
                $tz = new DateTimeZone('Europe/Helsinki');
                
                // Try multiple date formats - parse as local Helsinki time
                $dt = DateTime::createFromFormat('Y-m-d\TH:i', $occurredAt, $tz)
                    ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $occurredAt, $tz)
                    ?: DateTime::createFromFormat('Y-m-d H:i:s', $occurredAt, $tz)
                    ?: DateTime::createFromFormat('Y-m-d H:i', $occurredAt, $tz);
                
                if (!$dt) {
                    // Fallback to strtotime if none of the formats match
                    $ts = strtotime($occurredAt);
                    if ($ts !== false) {
                        $dt = new DateTime('@' . $ts);
                        $dt->setTimezone($tz);
                    }
                }
                
                if ($dt) {
                    $dateText = $dt->format('d.m.Y H:i');
                }
            }
            
            // Get labels
            $labels = $this->getLabels($lang);
            $siteLabel = $labels['site'];
            $dateLabel = $labels['date'];
            
            $greenLayout = null;
            if ($type === 'green') {
                $greenLayout = $this->buildGreenCardLayout($flashData);
            }

            if ($cardNumber === 2 && $type === 'green') {
                $this->renderCard2(
                    $imagick,
                    $title,
                    $greenLayout['card2']['description'],
                    $greenLayout['card2']['root_causes'],
                    $greenLayout['card2']['actions'],
                    $labels,
                    $flashData
                );
            } elseif ($type === 'green' && $cardNumber === 1 && !$greenLayout['needs_second_card']) {
                $this->renderInvestigationSplitView(
                    $imagick,
                    $title,
                    $greenLayout['card1']['description'],
                    $greenLayout['card1']['root_causes'],
                    $greenLayout['card1']['actions'],
                    $siteText,
                    $dateText,
                    $siteLabel,
                    $dateLabel,
                    $labels,
                    $flashData
                );
            } elseif ($type === 'green' && $cardNumber === 1 && $greenLayout['needs_second_card']) {
                $this->renderCard1(
                    $imagick,
                    $title,
                    $greenLayout['card1']['description'],
                    $siteText,
                    $dateText,
                    $siteLabel,
                    $dateLabel,
                    $flashData
                );
            } else {
                $this->renderCard1($imagick, $title, $description, $siteText, $dateText, $siteLabel, $dateLabel, $flashData);
            }
            
            // Save to JPEG
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(self::QUALITY);
            $imagick->writeImage($outputPath);
            
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }
    
    /**
     * Render card 1 content (title, description, meta, image)
     */
    private function renderCard1(
        Imagick $imagick,
        string $title,
        string $description,
        string $siteText,
        string $dateText,
        string $siteLabel,
        string $dateLabel,
        array $flashData
    ): void {
        // Draw title (bold) with dynamic font size
        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Bold'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['black']));
        
        // Get font sizes using the same fitting logic as split-view and final renderer
        $fontSizes = $this->getFontSizes($flashData);
        $titleFontSize = (int) $fontSizes['shortTitle'];
        $descFontSize = (int) $fontSizes['description'];
        
        $draw->setFontSize($titleFontSize);
        // Calculate line width based on font size (920px width)
        $titleLines = $this->wrapText($title, (int)(self::TITLE_WIDTH / ($titleFontSize * 0.5)));
        $titleY = self::TITLE_Y;
        foreach ($titleLines as $i => $line) {
            $y = $titleY + ($i * ($titleFontSize + 7));  // Dynamic line height
            $imagick->annotateImage($draw, self::TITLE_X, $y, 0, $line);
        }
        
        // Calculate dynamic description Y position based on title height
        $titleHeight = count($titleLines) * ($titleFontSize + 7);
        $descY = $titleY + $titleHeight + 25;  // 25px gap after title
        
        // Draw description (regular) - ALWAYS FIT (no truncation, auto font downscale)
        $draw->setFont($this->getFont('Regular'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['gray_dark']));
        
        // $descFontSize is already calculated from total length above
        
        $draw->setFontSize($descFontSize);

        // Normalisoi rivinvaihdot ja rajoita tyhjien rivien määrä (ettei tila lopu “turhaan”)
        $description = str_replace(["\r\n", "\r"], "\n", (string)$description);
        $description = preg_replace("/\n{3,}/", "\n\n", $description);

        // Laske montako riviä mahtuu ennen meta-aluetta
        $bottomLimitY = self::META_Y - 10;
        $availableHeight = max(0, $bottomLimitY - $descY);
        $maxDescLines = (int) floor($availableHeight / ($descFontSize * 1.3));
        $maxDescLines = max(6, min($maxDescLines, 30));

        // Piirrä aina kokonaan (pienennä fonttia tarvittaessa)
        $this->drawWrappedText(
            $imagick,
            $draw,
            (string)$description,
            self::DESC_X,
            $descY,
            self::DESC_WIDTH,
            $descFontSize,
            (int)($descFontSize * 1.3),
            $maxDescLines
        );
        
        // Draw meta info (site and date) at fixed position
        $this->renderMetaInfo($imagick, $siteLabel, $siteText, $dateLabel, $dateText, self::META_Y);
        
        // Composite grid bitmap image
        $this->compositeImage($imagick, $flashData);
    }
    
    /**
     * Render card 2 content (root causes and actions)
     */
    private function renderCard2(
        Imagick $imagick,
        string $title,
        string $description,
        string $rootCauses,
        string $actions,
        array $labels,
        array $flashData
    ): void {
        $titleBoxX = 95;
        $titleBoxY = 247;
        $titleBoxW = 1730;
        $titleBoxH = 130;

        $leftColX = 95;
        $rightColX = 970;
        $colW = 845;
        $headerH = 45;

        $sizes = $this->getFontSizes($flashData + [
            'title_short' => $title,
            'short_title' => $title,
            'description' => $description,
            'root_causes' => $rootCauses,
            'actions' => $actions,
        ]);

        $titleFontSize = (int) $sizes['shortTitle'];
        $descFontSize = (int) $sizes['description'];
        $contentFontSize = min((int) $sizes['rootCauses'], (int) $sizes['actions']);

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel(self::COLORS['black_box']));
        $draw->rectangle($titleBoxX, $titleBoxY, $titleBoxX + $titleBoxW, $titleBoxY + $titleBoxH);
        $imagick->drawImage($draw);

        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Bold'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['white']));
        $draw->setFontSize($titleFontSize);

        $titleLines = $this->wrapTextWithParagraphs(
            $title,
            (int) floor(($titleBoxW - 40) / max(1, ($titleFontSize * 0.55)))
        );
        $titleLines = array_slice($titleLines, 0, 2);
        $titleY = $titleBoxY + ($titleBoxH + $titleFontSize) / 2;

        foreach ($titleLines as $i => $line) {
            $y = $titleY + ($i * ($titleFontSize + 7));
            $imagick->annotateImage($draw, $titleBoxX + 20, $y, 0, $line);
        }

        $currentY = 390;

        if (trim($description) !== '') {
            $descHeaderY = $currentY;
            $descBodyY = $descHeaderY + $headerH + 10;

            $draw = new ImagickDraw();
            $draw->setFillColor(new ImagickPixel(self::COLORS['black_box']));
            $draw->rectangle($titleBoxX, $descHeaderY, $titleBoxX + $titleBoxW, $descHeaderY + $headerH);
            $imagick->drawImage($draw);

            $draw = new ImagickDraw();
            $draw->setFont($this->getFont('Bold'));
            $draw->setFillColor(new ImagickPixel(self::COLORS['white']));
            $draw->setFontSize(18);
            $imagick->annotateImage($draw, $titleBoxX + 20, $descHeaderY + 30, 0, $labels['description']);

            $draw = new ImagickDraw();
            $draw->setFont($this->getFont('Regular'));
            $draw->setFillColor(new ImagickPixel(self::COLORS['black']));
            $draw->setFontSize($descFontSize);

            $lineHeight = (int) round($descFontSize * 1.35);
            $maxLines = max(2, (int) floor(130 / max(1, $lineHeight)));

            $linesDrawn = $this->drawWrappedText(
                $imagick,
                $draw,
                $description,
                $titleBoxX + 20,
                $descBodyY + $descFontSize,
                $titleBoxW - 40,
                $descFontSize,
                $lineHeight,
                $maxLines
            );

            $currentY = $descBodyY + ($linesDrawn * $lineHeight) + 35;
        }

        $rootHeaderY = $currentY;
        $actionsHeaderY = $currentY;
        $contentY = $rootHeaderY + $headerH + 10;
        $availableHeight = max(220, (self::META_Y - 20) - $contentY);
        $contentLineHeight = (int) round($contentFontSize * 1.35);
        $maxContentLines = max(5, (int) floor($availableHeight / max(1, $contentLineHeight)));

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel(self::COLORS['black_box']));
        $draw->rectangle($leftColX, $rootHeaderY, $leftColX + $colW, $rootHeaderY + $headerH);
        $imagick->drawImage($draw);

        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Bold'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['white']));
        $draw->setFontSize(18);
        $imagick->annotateImage($draw, $leftColX + 20, $rootHeaderY + 30, 0, $labels['root_causes']);

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel(self::COLORS['black_box']));
        $draw->rectangle($rightColX, $actionsHeaderY, $rightColX + $colW, $actionsHeaderY + $headerH);
        $imagick->drawImage($draw);

        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Bold'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['white']));
        $draw->setFontSize(18);
        $imagick->annotateImage($draw, $rightColX + 20, $actionsHeaderY + 30, 0, $labels['actions']);

        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Regular'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['black']));
        $draw->setFontSize($contentFontSize);

        $this->drawWrappedText(
            $imagick,
            $draw,
            $rootCauses,
            $leftColX + 20,
            $contentY + $contentFontSize,
            $colW - 40,
            $contentFontSize,
            $contentLineHeight,
            $maxContentLines
        );

        $this->drawWrappedText(
            $imagick,
            $draw,
            $actions,
            $rightColX + 20,
            $contentY + $contentFontSize,
            $colW - 40,
            $contentFontSize,
            $contentLineHeight,
            $maxContentLines
        );
    }
    
    /**
     * Render meta information (site and date) with gray background boxes
     */
    private function renderMetaInfo(
        Imagick $imagick,
        string $siteLabel,
        string $siteText,
        string $dateLabel,
        string $dateText,
        int $metaY
    ): void {
        $draw = new ImagickDraw();
        
        $siteBoxWidth = self::META_SITE_BOX_WIDTH;
        $dateBoxWidth = self::META_DATE_BOX_WIDTH;
        $boxHeight = self::META_BOX_HEIGHT;
        $boxRadius = 12;
        $boxY = $metaY - 25;
        
        $draw->setFillColor(new ImagickPixel(self::META_BG_COLOR));
        $draw->setFillOpacity(self::META_BG_OPACITY);
        $draw->setStrokeOpacity(0);

        $draw->roundRectangle(
            self::META_BOX1_X - 10,
            $boxY,
            self::META_BOX1_X - 10 + $siteBoxWidth,
            $boxY + $boxHeight,
            $boxRadius,
            $boxRadius
        );
        
        $draw->roundRectangle(
            self::META_BOX2_X - 10,
            $boxY,
            self::META_BOX2_X - 10 + $dateBoxWidth,
            $boxY + $boxHeight,
            $boxRadius,
            $boxRadius
        );
        
        // Apply the backgrounds
        $imagick->drawImage($draw);
        
        // Now draw the text (create new draw object for text)
        $draw = new ImagickDraw();
        
        // Apply padding to text positions
        $textX1 = self::META_BOX1_X + 24;
        $textX2 = self::META_BOX2_X + 24;
        $textYLabel = $metaY + 27;
        $textYValue = $metaY + 61;

        // Site box - label
        $draw->setFont($this->getFont('Bold'));
        $draw->setFontSize(16);
        $draw->setFillColor(new ImagickPixel(self::COLORS['gray_dark']));
        $imagick->annotateImage($draw, $textX1, $textYLabel, 0, rtrim($siteLabel, ':'));
        
        $metaValueFontSize = 20;
        $siteLineHeight = 24;

        $draw->setFont($this->getFont('Regular'));
        $draw->setFontSize($metaValueFontSize);
        $draw->setFillColor(new ImagickPixel(self::COLORS['black']));

        $locationLines = [];

        if (mb_strlen($siteText, 'UTF-8') <= 34) {
            $locationLines[] = $siteText;
        } else {
            $siteParts = preg_split('/\s+–\s+/u', $siteText, 2);

            if (is_array($siteParts) && count($siteParts) === 2) {
                $locationLines[] = trim($siteParts[0]);
                $locationLines[] = trim($siteParts[1]);
            } else {
                $locationLines = $this->wrapText($siteText, 34);
            }
        }

        $lineY = $textYValue;
        foreach (array_slice($locationLines, 0, 2) as $line) {
            if ($line === '') {
                continue;
            }

            $imagick->annotateImage($draw, $textX1, $lineY, 0, $line);
            $lineY += $siteLineHeight;
        }
        
        // Date box - label
        $draw->setFont($this->getFont('Bold'));
        $draw->setFontSize(16);
        $draw->setFillColor(new ImagickPixel(self::COLORS['gray_dark']));
        $imagick->annotateImage($draw, $textX2, $textYLabel, 0, rtrim($dateLabel, ':'));
        
        // Date box - value, matched with site value size
        $draw->setFont($this->getFont('Regular'));
        $draw->setFontSize($metaValueFontSize);
        $draw->setFillColor(new ImagickPixel(self::COLORS['black']));
        $imagick->annotateImage($draw, $textX2, $textYValue, 0, $dateText);
    }
	
    /**
     * Composite grid bitmap or images onto the canvas (single large image on right side)
     */
    private function compositeImage(Imagick $imagick, array $flashData): void
    {
        // Get the primary image to display (grid_bitmap or first available image)
        $imageData = $this->getPrimaryImage($flashData);
        
        if (empty($imageData)) {
            return;
        }
        
        try {
            // Create image from data
            $imageImagick = new Imagick();
            
            if (strpos($imageData, 'data:image/') === 0) {
                // Base64 data URL
                $imageImagick->readImageBlob(base64_decode(explode(',', $imageData)[1]));
            } else {
                // File path
                $imageImagick->readImage($imageData);
            }
            
            // Resize to fit the image area (750x750) while maintaining aspect ratio
// Zoomataan kuva hieman isommaksi ennen sijoittelua
$zoomFactor = 1.12;

$imageImagick->resizeImage(
    (int)(self::IMAGE_WIDTH * $zoomFactor),
    (int)(self::IMAGE_HEIGHT * $zoomFactor),
    Imagick::FILTER_LANCZOS,
    1,
    true
);

// Hae uusi koko zoomin jälkeen
$imgWidth = $imageImagick->getImageWidth();
$imgHeight = $imageImagick->getImageHeight();

// Säädetään pystysuuntaista painopistettä (alemmas)
$focusY = 0.30; // pienempi = alemmas

$overflowY = max(0, $imgHeight - self::IMAGE_HEIGHT);
$offsetY = self::IMAGE_Y - ($overflowY * $focusY);

// Keskitys X-suunnassa
$offsetX = self::IMAGE_X + (self::IMAGE_WIDTH - $imgWidth) / 2;
            
            // Composite the image
            $imagick->compositeImage($imageImagick, Imagick::COMPOSITE_OVER, (int)$offsetX, (int)$offsetY);
            
            $imageImagick->clear();
            $imageImagick->destroy();
            
        } catch (Throwable $e) {
            error_log('Failed to composite image: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the primary image to display
     * @return string|null Image data (path or base64 string) or null if no image
     */
    private function getPrimaryImage(array $flashData): ?string
    {
        // Priority: grid_bitmap > individual images (image_main, image_2, image_3)
        
        // 1. Check for grid bitmap (final composite) - if present, use only this
        $gridBitmap = $flashData['grid_bitmap'] ?? '';
        if (!empty($gridBitmap)) {
            if (strpos($gridBitmap, 'data:image/') === 0) {
                return $gridBitmap; // Base64 data URL
            } else {
                $gridPath = $this->uploadsDir . '/grids/' . $gridBitmap;
                if (file_exists($gridPath)) {
                    return $gridPath; // Return file path for better performance
                }
            }
        }
        
        // 2. Check for individual images - return first available
        $imageSources = [
            ['edited' => 'image1_edited_data', 'original' => 'image_main'],
            ['edited' => 'image2_edited_data', 'original' => 'image_2'],
            ['edited' => 'image3_edited_data', 'original' => 'image_3'],
        ];
        
        foreach ($imageSources as $source) {
            // Check for edited version first
            $edited = $flashData[$source['edited']] ?? '';
            if (!empty($edited) && strpos($edited, 'data:image/') === 0) {
                return $edited;
            }
            
            // Check for original image file
            $imageFile = $flashData[$source['original']] ?? '';
            if (!empty($imageFile)) {
                $imagePath = $this->uploadsDir . '/images/' . $imageFile;
                if (file_exists($imagePath)) {
                    return $imagePath;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get font path for text rendering
     */
    private function getFont(string $variant = 'Regular'): string
    {
        // Use local OpenSans font from project assets
        $fontPath = __DIR__ . '/../../assets/fonts/OpenSans-' . $variant . '.ttf';
        
        if (!file_exists($fontPath)) {
            throw new RuntimeException('Font file not found: ' . $fontPath);
        }
        
        return $fontPath;
    }
    
    /**
     * Get labels for given language
     */
    private function getLabels(string $lang): array
    {
        $labels = [
            'fi' => [
                'site' => 'Työmaa:',
                'date' => 'Milloin?',
                'type_yellow' => 'VAARATILANNE',
                'type_red' => 'ENSITIEDOTE',
                'type_green' => 'TUTKINTATIEDOTE',
                'description' => 'Kuvaus',
                'root_causes' => 'Juurisyyt',
                'actions' => 'Toimenpiteet',
            ],
            'sv' => [
                'site' => 'Arbetsplats:',
                'date' => 'När?',
                'type_yellow' => 'FAROSITUASJON',
                'type_red' => 'FÖRSTA RAPPORT',
                'type_green' => 'UNDERSÖKNINGSRAPPORT',
                'description' => 'Beskrivning',
                'root_causes' => 'Grundorsaker',
                'actions' => 'Åtgärder',
            ],
            'en' => [
                'site' => 'Worksite:',
                'date' => 'When?',
                'type_yellow' => 'HAZARD',
                'type_red' => 'INCIDENT',
                'type_green' => 'INVESTIGATION',
                'description' => 'Description',
                'root_causes' => 'Root Causes',
                'actions' => 'Actions',
            ],
            'it' => [
                'site' => 'Cantiere:',
                'date' => 'Quando?',
                'type_yellow' => 'PERICOLO',
                'type_red' => 'INCIDENTE',
                'type_green' => 'INDAGINE',
                'root_causes' => 'Cause Radice',
                'actions' => 'Azioni',
            ],
            'el' => [
                'site' => 'Εργοτάξιο:',
                'date' => 'Πότε?',
                'type_yellow' => 'ΚΙΝΔΥΝΟΣ',
                'type_red' => 'ΣΥΜΒΑΝ',
                'type_green' => 'ΕΡΕΥΝΑ',
                'root_causes' => 'Βασικές Αιτίες',
                'actions' => 'Ενέργειες',
            ],
        ];
        
        return $labels[$lang] ?? $labels['fi'];
    }
    
    
    /**
     * Wrap text to fit width (character-based approximation)
     */
    private function wrapText(string $text, int $maxCharsPerLine): array
    {
        if (empty($text)) {
            return [];
        }
        
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';
        
        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            
            if (mb_strlen($testLine) <= $maxCharsPerLine) {
                $currentLine = $testLine;
            } else {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                $currentLine = $word;
            }
        }
        
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }
        
        return $lines;
    }
    
    /**
     * Format bullet points - convert dashes to proper bullets
     */
    private function formatBulletPoints(string $text): string
    {
        if (empty(trim($text))) {
            return '';
        }
        
        // Normalisoi rivinvaihdot
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // Jaa riveihin
        $lines = explode("\n", $text);
        $formatted = [];
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) continue;
            
            // Tunnista rivin alussa oleva "- " tai "-"
            if (preg_match('/^-\s*(.+)$/', $trimmed, $matches)) {
                $formatted[] = '• ' . trim($matches[1]);
            } else {
                // Ei bullet-merkintää, lisää sellaisenaan
                $formatted[] = $trimmed;
            }
        }
        
        return implode("\n", $formatted);
    }
    
    /**
     * Get dynamic font size based on content length
     */
    private function getDynamicFontSize(int $length, array $thresholds, int $minSize): int
    {
        foreach ($thresholds as $threshold => $size) {
            if ($length < $threshold) {
                return $size;
            }
        }
        return $minSize;
    }
    
    /**
     * Calculate total content length for dynamic font sizing
     */
    private function calculateTotalContentLength(array $flashData): int
    {
        $title = trim((string) ($flashData['title_short'] ?? ''));
        $description = trim((string) ($flashData['description'] ?? ''));
        $rootCauses = trim((string) ($flashData['root_causes'] ?? ''));
        $actions = trim((string) ($flashData['actions'] ?? ''));
        
        return mb_strlen($title) + mb_strlen($description) + mb_strlen($rootCauses) + mb_strlen($actions);
    }
    
    /**
     * Get font sizes based on total content length
     * Returns array with keys: title, description, content
     */
    private function getFontSizesByTotalLength(int $totalLength): array
    {
        if ($totalLength < 500) {
            return ['title' => 38, 'description' => 26, 'content' => 22];
        } elseif ($totalLength < 700) {
            return ['title' => 36, 'description' => 24, 'content' => 20];
        } elseif ($totalLength < 900) {
            return ['title' => 34, 'description' => 22, 'content' => 18];
        } else {
            return ['title' => 32, 'description' => 20, 'content' => 16];
        }
    }
    
    /**
     * Wrap text with paragraph breaks support
     * Handles \n newlines in text to preserve user-defined paragraph breaks
     */
    private function wrapTextWithParagraphs(string $text, int $maxCharsPerLine): array
    {
        if (empty($text)) {
            return [];
        }
        
        // Split by newlines first to handle paragraph breaks
        $paragraphs = explode("\n", $text);
        $allLines = [];
        
        foreach ($paragraphs as $para) {
            if (trim($para) === '') {
                // Empty line for paragraph break
                $allLines[] = '';
            } else {
                // Wrap the paragraph
                $wrapped = $this->wrapText(trim($para), $maxCharsPerLine);
                $allLines = array_merge($allLines, $wrapped);
            }
        }
        
        return $allLines;
    }
    
    /**
     * Draw wrapped text with truncation support
     * 
     * @param Imagick $imagick The image object to draw on
     * @param ImagickDraw $draw The drawing object configured with font and color
     * @param string $text Text to draw
     * @param int $x X coordinate
     * @param int $y Y coordinate (top of first line)
     * @param int $width Available width in pixels
     * @param int $fontSize Font size in pixels
     * @param int $lineHeight Line height in pixels
     * @param int $maxLines Maximum number of lines to draw
     * @return int Number of lines drawn
     */
    private function drawWrappedText(
        Imagick $imagick,
        ImagickDraw $draw,
        string $text,
        int $x,
        int $y,
        int $width,
        int $fontSize,
        int $lineHeight,
        int $maxLines
    ): int {
        $text = trim((string)$text);
        if ($text === '') {
            return 0;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        $draw->setFontSize($fontSize);

        $charsPerLine = (int) floor($width / max(1, ($fontSize * 0.55)));
        $charsPerLine = max(18, $charsPerLine);

        $lines = $this->wrapTextWithParagraphs($text, $charsPerLine);
        $linesToDraw = array_slice($lines, 0, $maxLines);

        foreach ($linesToDraw as $i => $line) {
            $currentY = $y + ($i * $lineHeight);
            $imagick->annotateImage($draw, $x, $currentY, 0, $line);
        }

        return count($linesToDraw);
    }
    
    /**
     * Render Investigation (Green) type with split view layout
     * Shows description in top box, root causes and actions in split bottom boxes
     * 
     * @param Imagick $imagick The image object
     * @param string $title Short title text
     * @param string $description Long description text
     * @param string $rootCauses Root causes text
     * @param string $actions Actions text
     * @param string $siteText Site information
     * @param string $dateText Date information
     * @param string $siteLabel Site label (translated)
     * @param string $dateLabel Date label (translated)
     * @param array $labels All translated labels
     * @param array $flashData Full flash data for image compositing
     */
    private function renderInvestigationSplitView(
        Imagick $imagick,
        string $title,
        string $description,
        string $rootCauses,
        string $actions,
        string $siteText,
        string $dateText,
        string $siteLabel,
        string $dateLabel,
        array $labels,
        array $flashData
    ): void {
        $sizes = $this->getFontSizes($flashData);

        $titleFontSize = (int) $sizes['shortTitle'];
        $descFontSize = (int) $sizes['description'];
        $contentFontSize = min((int) $sizes['rootCauses'], (int) $sizes['actions']);

        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Bold'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['black']));
        $draw->setFontSize($titleFontSize);

        $titleY = self::TITLE_Y + $titleFontSize + ((self::TITLE_HEIGHT - $titleFontSize) / 2);
        $this->drawWrappedText(
            $imagick,
            $draw,
            $title,
            self::TITLE_X + self::SPLIT_VIEW_PADDING,
            $titleY,
            self::TITLE_WIDTH - (self::SPLIT_VIEW_PADDING * 2),
            $titleFontSize,
            $titleFontSize + self::SPLIT_VIEW_HEADER_OFFSET,
            2
        );

        $titleLines = $this->wrapTextWithParagraphs(
            $title,
            (int) floor((self::TITLE_WIDTH - (self::SPLIT_VIEW_PADDING * 2)) / max(1, ($titleFontSize * 0.55)))
        );
        $titleLineCount = min(count($titleLines), 2);
        $titleHeight = $titleLineCount * ($titleFontSize + self::SPLIT_VIEW_HEADER_OFFSET);

        $descStartY = self::TITLE_Y + $titleHeight + 40;

        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Regular'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['black']));
        $draw->setFontSize($descFontSize);

        $descY = $descStartY + $descFontSize + self::SPLIT_VIEW_TOP_PADDING;
        $this->drawWrappedText(
            $imagick,
            $draw,
            $description,
            self::DESC_X + self::SPLIT_VIEW_PADDING,
            $descY,
            self::DESC_WIDTH - (self::SPLIT_VIEW_PADDING * 2),
            $descFontSize,
            (int) ($descFontSize * self::SPLIT_VIEW_LINE_HEIGHT_MULTIPLIER),
            5
        );

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel(self::COLORS['black_box']));
        $draw->rectangle(
            self::ROOT_X,
            self::SPLIT_Y,
            self::ROOT_X + self::SPLIT_WIDTH,
            self::SPLIT_Y + self::SPLIT_VIEW_HEADER_HEIGHT
        );
        $imagick->drawImage($draw);

        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Bold'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['white']));
        $draw->setFontSize(18);
        $imagick->annotateImage(
            $draw,
            self::ROOT_X + self::SPLIT_VIEW_PADDING_SMALL,
            self::SPLIT_Y + 18 + self::SPLIT_VIEW_HEADER_OFFSET,
            0,
            $labels['root_causes']
        );

        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Regular'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['black']));
        $draw->setFontSize($contentFontSize);

        $rootContentY = self::SPLIT_Y + self::SPLIT_VIEW_HEADER_HEIGHT + $contentFontSize + self::SPLIT_VIEW_TOP_PADDING;
        $metaTopY = self::META_Y - self::SPLIT_VIEW_META_BUFFER;
        $safetyMargin = 16;
        $availableHeight = max(0, ($metaTopY - $safetyMargin) - $rootContentY);

        [$rootCard1, $rootCard2] = $this->splitBulletContentForHeight(
            $rootCauses,
            self::SPLIT_WIDTH - (self::SPLIT_VIEW_PADDING_SMALL * 2),
            $contentFontSize,
            $availableHeight
        );

        $this->drawWrappedText(
            $imagick,
            $draw,
            $rootCard1,
            self::ROOT_X + self::SPLIT_VIEW_PADDING_SMALL,
            $rootContentY,
            self::SPLIT_WIDTH - (self::SPLIT_VIEW_PADDING_SMALL * 2),
            $contentFontSize,
            (int) ($contentFontSize * self::SPLIT_VIEW_LINE_HEIGHT_MULTIPLIER),
            999
        );

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel(self::COLORS['black_box']));
        $draw->rectangle(
            self::ACTION_X,
            self::SPLIT_Y,
            self::ACTION_X + self::SPLIT_WIDTH,
            self::SPLIT_Y + self::SPLIT_VIEW_HEADER_HEIGHT
        );
        $imagick->drawImage($draw);

        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Bold'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['white']));
        $draw->setFontSize(18);
        $imagick->annotateImage(
            $draw,
            self::ACTION_X + self::SPLIT_VIEW_PADDING_SMALL,
            self::SPLIT_Y + 18 + self::SPLIT_VIEW_HEADER_OFFSET,
            0,
            $labels['actions']
        );

        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Regular'));
        $draw->setFillColor(new ImagickPixel(self::COLORS['black']));
        $draw->setFontSize($contentFontSize);

        [$actionsCard1, $actionsCard2] = $this->splitBulletContentForHeight(
            $actions,
            self::SPLIT_WIDTH - (self::SPLIT_VIEW_PADDING_SMALL * 2),
            $contentFontSize,
            $availableHeight
        );

        $actionContentY = $rootContentY;
        $this->drawWrappedText(
            $imagick,
            $draw,
            $actionsCard1,
            self::ACTION_X + self::SPLIT_VIEW_PADDING_SMALL,
            $actionContentY,
            self::SPLIT_WIDTH - (self::SPLIT_VIEW_PADDING_SMALL * 2),
            $contentFontSize,
            (int) ($contentFontSize * self::SPLIT_VIEW_LINE_HEIGHT_MULTIPLIER),
            999
        );

        $this->renderMetaInfo($imagick, $siteLabel, $siteText, $dateLabel, $dateText, self::META_Y);
        $this->compositeImage($imagick, $flashData);
    }
}