<?php
/**
 * ReportImageGenerator - A4 PDF report generation using Imagick
 * 
 * Generates 2480x3508px (A4 @300dpi) JPEG report images from SafetyFlash data
 * Uses template background and overlays text and images using Imagick
 * Converts to PDF format for download
 * 
 * Architecture follows PreviewImageGenerator.php pattern
 * 
 * @package SafetyFlash
 * @subpackage Services
 */

declare(strict_types=1);

class ReportImageGenerator
{
    // A4 dimensions @300dpi
    private const WIDTH = 2480;
    private const HEIGHT = 3508;
    private const QUALITY = 85;
    
    // Color definitions (same as PreviewImageGenerator)
    private const COLORS = [
        'yellow' => '#FEE000',
        'red' => '#C81E1E',
        'green' => '#009650',
        'black' => '#000000',
        'white' => '#FFFFFF',
        'gray_light' => '#F0F0F0',
        'gray_dark' => '#3C3C3C',
        'black_box' => '#1a1a1a',
    ];
    
    // Layout constants (A4 @300dpi scale)
    private const LEFT_MARGIN = 180;
    private const RIGHT_MARGIN = 180;
    private const CONTENT_START_Y = 450;  // After header
    private const CONTENT_END_Y = 3200;   // Before footer
    private const CONTENT_WIDTH = 2120;   // WIDTH - LEFT_MARGIN - RIGHT_MARGIN
    
    // Type badge dimensions
    private const BADGE_HEIGHT = 80;
    private const BADGE_RADIUS = 10;
    
    // Grid bitmap constraints
    private const GRID_MAX_WIDTH = 2120;
    private const GRID_MAX_HEIGHT = 900;
    private const GRID_CORNER_RADIUS = 20;
    
    // Font sizes @300dpi
    private const FONT_TITLE = 60;
    private const FONT_SHORT = 36;
    private const FONT_DESCRIPTION = 30;
    private const FONT_META = 28;
    private const FONT_META_LABEL = 24;
    private const FONT_HEADER = 32;
    
    // Line heights
    private const LINE_HEIGHT_TITLE = 70;
    private const LINE_HEIGHT_DESC = 40;
    private const LINE_HEIGHT_META = 36;
    
    // Spacing
    private const SPACING_SECTION = 40;
    private const SPACING_SMALL = 20;
    
    // Content section constants
    private const CONTENT_SECTION_PADDING = 40;
    private const MAX_CONTENT_SECTION_LINES = 30;
    
    // Meta box dimensions
    private const META_BOX_HEIGHT = 120;
    private const META_BOX_WIDTH = 1020; // Half of content width minus gap
    private const META_BOX_GAP = 80;
    
    private string $uploadsDir;
    private string $templatesDir;
    private string $fontsDir;
    
    public function __construct(string $uploadsDir)
    {
        $this->uploadsDir = rtrim($uploadsDir, '/');
        $this->templatesDir = dirname(__DIR__, 2) . '/assets/img/templates';
        $this->fontsDir = dirname(__DIR__, 2) . '/assets/fonts';
        
        if (!extension_loaded('imagick')) {
            throw new RuntimeException('Imagick extension is not loaded');
        }
        
        if (!is_dir($this->templatesDir)) {
            throw new RuntimeException('Templates directory not found: ' . $this->templatesDir);
        }
    }
    
    /**
     * Generate A4 report image and convert to PDF
     * 
     * @param array $flash Flash data from database
     * @return string|null Base64-encoded PDF data or null on failure
     */
    public function generate(array $flash): ?string
    {
        try {
            $type = $flash['type'] ?? 'yellow';
            $lang = $flash['lang'] ?? 'fi';
            
            // Load template background
            $templatePath = $this->getTemplatePath();
            if (!file_exists($templatePath)) {
                throw new RuntimeException('Template not found: ' . $templatePath);
            }
            
            $imagick = new Imagick($templatePath);
            
            try {
                // Current Y position for content
                $currentY = self::CONTENT_START_Y;
                
                // 1. Type badge (top left)
                $currentY = $this->drawTypeBadge($imagick, $type, $lang, $currentY);
                $currentY += self::SPACING_SECTION;
                
                // 2. Date (top right, same level as badge)
                $this->drawDate($imagick, $flash, self::CONTENT_START_Y);
                
                // 3. Title
                $currentY = $this->drawTitle($imagick, $flash, $currentY);
                $currentY += self::SPACING_SECTION;
                
                // 4. Short description / Summary
                $currentY = $this->drawShortDescription($imagick, $flash, $currentY);
                $currentY += self::SPACING_SECTION;
                
                // 5. Meta boxes (Site and Date side by side)
                $currentY = $this->drawMetaBoxes($imagick, $flash, $lang, $currentY);
                $currentY += self::SPACING_SECTION;
                
                // 6. Grid bitmap image
                $currentY = $this->drawGridBitmap($imagick, $flash, $currentY);
                $currentY += self::SPACING_SECTION;
                
                // 7. Long description
                $currentY = $this->drawDescription($imagick, $flash, $currentY);
                
                // 7b. Extra images (image_2, image_3)
                $currentY = $this->drawExtraImages($imagick, $flash, $currentY);
                
                // 8. Root causes and actions (shown when content exists, any type)
                $rootCauses = trim((string)($flash['root_causes'] ?? ''));
                $actions = trim((string)($flash['actions'] ?? ''));
                if (!empty($rootCauses) || !empty($actions)) {
                    $currentY += self::SPACING_SECTION;
                    $currentY = $this->drawRootCausesAndActions($imagick, $flash, $lang, $currentY);
                }
                
                // Convert to PDF
                $imagick->setImageFormat('pdf');
                $pdfData = $imagick->getImageBlob();
                
                return base64_encode($pdfData);
                
            } finally {
                $imagick->clear();
                $imagick->destroy();
            }
            
        } catch (\Throwable $e) {
            error_log("ReportImageGenerator error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Draw type badge with color and text
     */
    private function drawTypeBadge(Imagick $imagick, string $type, string $lang, int $y): int
    {
        $labels = $this->getLabels($lang);
        $typeKey = 'type_' . $type;
        $badgeText = $labels[$typeKey] ?? strtoupper($type);
        
        // Get color
        $color = self::COLORS[$type] ?? self::COLORS['yellow'];
        
        // Draw rounded rectangle
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel($color));
        $draw->roundRectangle(
            self::LEFT_MARGIN,
            $y,
            self::LEFT_MARGIN + 600,
            $y + self::BADGE_HEIGHT,
            self::BADGE_RADIUS,
            self::BADGE_RADIUS
        );
        $imagick->drawImage($draw);
        
        // Draw text
        $draw2 = new ImagickDraw();
        $draw2->setFont($this->getFont('Bold'));
        $draw2->setFontSize(self::FONT_META_LABEL);
        $draw2->setFillColor(new ImagickPixel(self::COLORS['white']));
        $imagick->annotateImage(
            $draw2,
            self::LEFT_MARGIN + 30,
            $y + self::BADGE_HEIGHT - 25,
            0,
            $badgeText
        );
        
        return $y + self::BADGE_HEIGHT;
    }
    
    /**
     * Draw date in top right
     */
    private function drawDate(Imagick $imagick, array $flash, int $y): void
    {
        $dateText = '';
        $occurredAt = $flash['occurred_at'] ?? $flash['created_at'] ?? '';
        
        if (!empty($occurredAt)) {
            try {
                $dt = new DateTime($occurredAt);
                $dateText = $dt->format('d.m.Y');
            } catch (Exception $e) {
                $dateText = '';
            }
        }
        
        if (empty($dateText)) {
            return;
        }
        
        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Regular'));
        $draw->setFontSize(self::FONT_META);
        $draw->setFillColor(new ImagickPixel(self::COLORS['gray_dark']));
        
        // Right-align
        $metrics = $imagick->queryFontMetrics($draw, $dateText);
        $textWidth = $metrics['textWidth'] ?? 200;
        
        $imagick->annotateImage(
            $draw,
            self::WIDTH - self::RIGHT_MARGIN - $textWidth,
            $y + 60,
            0,
            $dateText
        );
    }
    
    /**
     * Draw title
     */
    private function drawTitle(Imagick $imagick, array $flash, int $y): int
    {
        $title = trim((string)($flash['title'] ?? $flash['title_short'] ?? ''));
        
        if (empty($title)) {
            return $y;
        }
        
        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Bold'));
        $draw->setFontSize(self::FONT_TITLE);
        $draw->setFillColor(new ImagickPixel(self::COLORS['black']));
        
        // Wrap text
        $lines = $this->wrapText($title, 40);
        
        foreach ($lines as $i => $line) {
            $imagick->annotateImage(
                $draw,
                self::LEFT_MARGIN,
                $y + ($i * self::LINE_HEIGHT_TITLE),
                0,
                $line
            );
        }
        
        return $y + (count($lines) * self::LINE_HEIGHT_TITLE);
    }
    
    /**
     * Draw short description
     */
    private function drawShortDescription(Imagick $imagick, array $flash, int $y): int
    {
        $summary = trim((string)($flash['title_short'] ?? $flash['summary'] ?? ''));
        
        if (empty($summary)) {
            return $y;
        }
        
        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Regular'));
        $draw->setFontSize(self::FONT_SHORT);
        $draw->setFillColor(new ImagickPixel(self::COLORS['gray_dark']));
        
        // Wrap text
        $lines = $this->wrapText($summary, 60);
        
        foreach ($lines as $i => $line) {
            $imagick->annotateImage(
                $draw,
                self::LEFT_MARGIN,
                $y + ($i * self::LINE_HEIGHT_DESC),
                0,
                $line
            );
        }
        
        return $y + (count($lines) * self::LINE_HEIGHT_DESC);
    }
    
    /**
     * Draw meta boxes (Site and Occurred At)
     */
    private function drawMetaBoxes(Imagick $imagick, array $flash, string $lang, int $y): int
    {
        $labels = $this->getLabels($lang);
        
        // Site box (left)
        $site = trim((string)($flash['site'] ?? ''));
        $siteDetail = trim((string)($flash['site_detail'] ?? ''));
        $siteText = $site;
        if (!empty($siteDetail)) {
            $siteText .= "\n" . $siteDetail;
        }
        
        $this->drawMetaBox(
            $imagick,
            self::LEFT_MARGIN,
            $y,
            $labels['site'] ?? 'Työmaa:',
            $siteText
        );
        
        // Date box (right)
        $occurredAt = $flash['occurred_at'] ?? $flash['created_at'] ?? '';
        $dateText = '';
        if (!empty($occurredAt)) {
            try {
                $dt = new DateTime($occurredAt);
                $dateText = $dt->format('d.m.Y H:i');
            } catch (Exception $e) {
                $dateText = '';
            }
        }
        
        $this->drawMetaBox(
            $imagick,
            self::LEFT_MARGIN + self::META_BOX_WIDTH + self::META_BOX_GAP,
            $y,
            $labels['date'] ?? 'Milloin?',
            $dateText
        );
        
        return $y + self::META_BOX_HEIGHT;
    }
    
    /**
     * Draw a single meta box
     */
    private function drawMetaBox(Imagick $imagick, int $x, int $y, string $label, string $value): void
    {
        // Background
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel(self::COLORS['gray_light']));
        $draw->rectangle($x, $y, $x + self::META_BOX_WIDTH, $y + self::META_BOX_HEIGHT);
        $imagick->drawImage($draw);
        
        // Label
        $draw2 = new ImagickDraw();
        $draw2->setFont($this->getFont('Bold'));
        $draw2->setFontSize(self::FONT_META_LABEL);
        $draw2->setFillColor(new ImagickPixel(self::COLORS['gray_dark']));
        $imagick->annotateImage($draw2, $x + 20, $y + 35, 0, $label);
        
        // Value
        $draw3 = new ImagickDraw();
        $draw3->setFont($this->getFont('Regular'));
        $draw3->setFontSize(self::FONT_META);
        $draw3->setFillColor(new ImagickPixel(self::COLORS['black']));
        
        // Wrap value text
        $lines = $this->wrapText($value, 35);
        foreach (array_slice($lines, 0, 2) as $i => $line) {
            $imagick->annotateImage(
                $draw3,
                $x + 20,
                $y + 70 + ($i * self::LINE_HEIGHT_META),
                0,
                $line
            );
        }
    }
    
    /**
     * Draw grid bitmap image
     */
    private function drawGridBitmap(Imagick $imagick, array $flash, int $y): int
    {
        $gridBitmap = $flash['grid_bitmap'] ?? '';
        
        if (empty($gridBitmap)) {
            return $y;
        }
        
        // Find grid bitmap file
        $gridPath = $this->findGridBitmap($gridBitmap);
        
        if (!$gridPath || !file_exists($gridPath)) {
            return $y;
        }
        
        try {
            $gridImage = new Imagick($gridPath);
            
            // Get original dimensions
            $origWidth = $gridImage->getImageWidth();
            $origHeight = $gridImage->getImageHeight();
            
            // Calculate scaled dimensions (maintain aspect ratio)
            $scale = min(
                self::GRID_MAX_WIDTH / $origWidth,
                self::GRID_MAX_HEIGHT / $origHeight
            );
            
            $newWidth = (int)($origWidth * $scale);
            $newHeight = (int)($origHeight * $scale);
            
            // Resize
            $gridImage->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);
            
            // Add rounded corners via mask (compatible with all Imagick versions)
            // Create a white rounded rectangle mask
            $mask = new Imagick();
            $mask->newImage($newWidth, $newHeight, new ImagickPixel('transparent'));
            $mask->setImageFormat('png');
            
            $maskDraw = new ImagickDraw();
            $maskDraw->setFillColor(new ImagickPixel('white'));
            $maskDraw->roundRectangle(0, 0, $newWidth - 1, $newHeight - 1, self::GRID_CORNER_RADIUS, self::GRID_CORNER_RADIUS);
            $mask->drawImage($maskDraw);
            
            // Apply mask using COMPOSITE_DSTIN: keeps destination (image) where source (mask) is present
            $gridImage->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
            $mask->clear();
            $mask->destroy();
            
            // Center horizontally
            $x = self::LEFT_MARGIN + (self::CONTENT_WIDTH - $newWidth) / 2;
            
            // Composite onto main image
            $imagick->compositeImage($gridImage, Imagick::COMPOSITE_OVER, (int)$x, $y);
            
            $gridImage->clear();
            $gridImage->destroy();
            
            return $y + $newHeight;
            
        } catch (\Throwable $e) {
            error_log("Error loading grid bitmap: " . $e->getMessage());
            return $y;
        }
    }
    
    /**
     * Draw long description
     */
    private function drawDescription(Imagick $imagick, array $flash, int $y): int
    {
        $description = trim((string)($flash['description'] ?? ''));
        
        if (empty($description)) {
            return $y;
        }
        
        $draw = new ImagickDraw();
        $draw->setFont($this->getFont('Regular'));
        $draw->setFontSize(self::FONT_DESCRIPTION);
        $draw->setFillColor(new ImagickPixel(self::COLORS['black']));
        
        // Available space
        $maxLines = (int)((self::CONTENT_END_Y - $y) / self::LINE_HEIGHT_DESC);
        
        // Wrap and draw
        return $this->drawWrappedText(
            $imagick,
            $draw,
            $description,
            self::LEFT_MARGIN,
            $y,
            self::CONTENT_WIDTH,
            self::FONT_DESCRIPTION,
            self::LINE_HEIGHT_DESC,
            $maxLines
        );
    }
    
    /**
     * Draw root causes and actions STACKED (full width, one below the other)
     */
    private function drawRootCausesAndActions(Imagick $imagick, array $flash, string $lang, int $y): int
    {
        $rootCauses = trim((string)($flash['root_causes'] ?? ''));
        $actions = trim((string)($flash['actions'] ?? ''));
        
        if (empty($rootCauses) && empty($actions)) {
            return $y;
        }
        
        $labels = $this->getLabels($lang);
        
        // Root causes (full width)
        if (!empty($rootCauses)) {
            $y = $this->drawContentSection(
                $imagick,
                self::LEFT_MARGIN,
                $y,
                self::CONTENT_WIDTH,
                $labels['root_causes'] ?? 'Juurisyyt',
                $rootCauses
            );
            $y += self::SPACING_SECTION;
        }
        
        // Actions (full width, below root causes)
        if (!empty($actions)) {
            $y = $this->drawContentSection(
                $imagick,
                self::LEFT_MARGIN,
                $y,
                self::CONTENT_WIDTH,
                $labels['actions'] ?? 'Toimenpiteet',
                $actions
            );
        }
        
        return $y;
    }
    
    /**
     * Draw a full-width content section with header bar and content below
     */
    private function drawContentSection(Imagick $imagick, int $x, int $y, int $width, string $header, string $content): int
    {
        // Header box with rounded top corners
        $headerHeight = 70;
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel(self::COLORS['black_box']));
        $draw->roundRectangle($x, $y, $x + $width, $y + $headerHeight, 10, 10);
        $imagick->drawImage($draw);
        
        // Header text
        $draw2 = new ImagickDraw();
        $draw2->setFont($this->getFont('Bold'));
        $draw2->setFontSize(self::FONT_HEADER);
        $draw2->setFillColor(new ImagickPixel(self::COLORS['white']));
        $imagick->annotateImage($draw2, $x + 30, $y + 48, 0, $header);
        
        // Light background for content area
        $contentStartY = $y + $headerHeight;
        $content = $this->formatBulletPoints($content);
        
        // Calculate how many lines we need
        $charsPerLine = (int)($width / (self::FONT_DESCRIPTION * 0.55));
        $lines = $this->wrapTextWithParagraphs($content, $charsPerLine);
        $contentHeight = count($lines) * self::LINE_HEIGHT_DESC + self::CONTENT_SECTION_PADDING;
        
        // Draw light gray background for content
        $bgDraw = new ImagickDraw();
        $bgDraw->setFillColor(new ImagickPixel('#F8F9FA'));
        $bgDraw->rectangle($x, $contentStartY, $x + $width, $contentStartY + $contentHeight);
        $imagick->drawImage($bgDraw);
        
        // Draw content text
        $draw3 = new ImagickDraw();
        $draw3->setFont($this->getFont('Regular'));
        $draw3->setFontSize(self::FONT_DESCRIPTION);
        $draw3->setFillColor(new ImagickPixel(self::COLORS['black']));
        
        $maxLines = (int)((self::CONTENT_END_Y - $contentStartY - 20) / self::LINE_HEIGHT_DESC);
        
        $endY = $this->drawWrappedText(
            $imagick,
            $draw3,
            $content,
            $x + 30,
            $contentStartY + 30,
            $width - 60,
            self::FONT_DESCRIPTION,
            self::LINE_HEIGHT_DESC,
            $maxLines
        );
        
        return $endY + 10;
    }
    
    /**
     * Draw a content column with header
     */
    private function drawContentColumn(Imagick $imagick, int $x, int $y, int $width, string $header, string $content): void
    {
        // Header box
        $headerHeight = 60;
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel(self::COLORS['black_box']));
        $draw->rectangle($x, $y, $x + $width, $y + $headerHeight);
        $imagick->drawImage($draw);
        
        // Header text
        $draw2 = new ImagickDraw();
        $draw2->setFont($this->getFont('Bold'));
        $draw2->setFontSize(self::FONT_HEADER);
        $draw2->setFillColor(new ImagickPixel(self::COLORS['white']));
        $imagick->annotateImage($draw2, $x + 20, $y + 45, 0, $header);
        
        // Content
        $contentY = $y + $headerHeight + 20;
        $draw3 = new ImagickDraw();
        $draw3->setFont($this->getFont('Regular'));
        $draw3->setFontSize(self::FONT_DESCRIPTION);
        $draw3->setFillColor(new ImagickPixel(self::COLORS['black']));
        
        // Format bullet points
        $content = $this->formatBulletPoints($content);
        
        $this->drawWrappedText(
            $imagick,
            $draw3,
            $content,
            $x + 20,
            $contentY,
            $width - 40,
            self::FONT_DESCRIPTION,
            self::LINE_HEIGHT_DESC,
            15  // Max lines
        );
    }
    
    /**
     * Draw wrapped text with automatic font size reduction to fit
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
            return $y;
        }
        
        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        
        // Auto-fit: reduce font size until all content fits
        $minFontSize = 18;
        
        for ($fs = $fontSize; $fs >= $minFontSize; $fs--) {
            $draw->setFontSize($fs);
            
            // Scale line height proportionally
            $lh = (int)round($lineHeight * ($fs / max(1, $fontSize)));
            $lh = max(15, $lh);
            
            $charsPerLine = (int)($width / ($fs * 0.55));
            $charsPerLine = max(18, $charsPerLine);
            
            // Wrap with paragraph support
            $lines = $this->wrapTextWithParagraphs($text, $charsPerLine);
            
            if (count($lines) <= $maxLines) {
                // Fits! Draw it
                $linesDrawn = 0;
                foreach ($lines as $i => $line) {
                    $currentY = $y + ($i * $lh);
                    $imagick->annotateImage($draw, $x, $currentY, 0, $line);
                    $linesDrawn++;
                }
                return $y + ($linesDrawn * $lh);
            }
        }
        
        // If still doesn't fit, draw what we can with smallest font
        $draw->setFontSize($minFontSize);
        $lh = (int)round($lineHeight * ($minFontSize / max(1, $fontSize)));
        $lh = max(15, $lh);
        $charsPerLine = (int)($width / ($minFontSize * 0.55));
        $lines = $this->wrapTextWithParagraphs($text, $charsPerLine);
        
        foreach (array_slice($lines, 0, $maxLines) as $i => $line) {
            $currentY = $y + ($i * $lh);
            $imagick->annotateImage($draw, $x, $currentY, 0, $line);
        }
        
        return $y + (min(count($lines), $maxLines) * $lh);
    }
    
    /**
     * Wrap text with paragraph breaks support
     */
    private function wrapTextWithParagraphs(string $text, int $maxCharsPerLine): array
    {
        $paragraphs = preg_split("/\n/", $text);
        $allLines = [];
        
        foreach ($paragraphs as $para) {
            $para = (string)$para;
            
            if (trim($para) === '') {
                $allLines[] = '';
            } else {
                $wrapped = $this->wrapText(trim($para), $maxCharsPerLine);
                $allLines = array_merge($allLines, $wrapped);
            }
        }
        
        return $allLines;
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
        
        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // Split into lines
        $lines = explode("\n", $text);
        $formatted = [];
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) continue;
            
            // Detect "- " at line start
            if (preg_match('/^-\s*(.+)$/', $trimmed, $matches)) {
                $formatted[] = '• ' . trim($matches[1]);
            } else {
                $formatted[] = $trimmed;
            }
        }
        
        return implode("\n", $formatted);
    }
    
    /**
     * Draw additional images (image_2, image_3) in the report
     */
    private function drawExtraImages(Imagick $imagick, array $flash, int $y): int
    {
        $imageFields = ['image_2', 'image_3'];
        $uploadsBase = $this->uploadsDir;
        
        foreach ($imageFields as $field) {
            $filename = trim((string)($flash[$field] ?? ''));
            if (empty($filename)) continue;
            
            // Try to find the image file
            $imagePath = null;
            $candidates = [
                $uploadsBase . '/images/' . $filename,
                $uploadsBase . '/edited/' . $filename,
                $uploadsBase . '/' . $filename,
                dirname($uploadsBase) . '/img/' . $filename,
            ];
            
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    $imagePath = $candidate;
                    break;
                }
            }
            
            if (!$imagePath) continue;
            
            try {
                $y += self::SPACING_SECTION;
                
                $extraImg = new Imagick($imagePath);
                $origW = $extraImg->getImageWidth();
                $origH = $extraImg->getImageHeight();
                
                // Scale to fit content width, max height 600px
                $maxW = self::CONTENT_WIDTH;
                $maxH = 600;
                $scale = min($maxW / $origW, $maxH / $origH, 1.0);
                
                $newW = (int)($origW * $scale);
                $newH = (int)($origH * $scale);
                
                $extraImg->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, 1);
                
                // Add rounded corners
                $mask = new Imagick();
                $mask->newImage($newW, $newH, new ImagickPixel('transparent'));
                $mask->setImageFormat('png');
                $maskDraw = new ImagickDraw();
                $maskDraw->setFillColor(new ImagickPixel('white'));
                $maskDraw->roundRectangle(0, 0, $newW - 1, $newH - 1, 15, 15);
                $mask->drawImage($maskDraw);
                $extraImg->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
                $mask->clear();
                $mask->destroy();
                
                // Center horizontally
                $x = self::LEFT_MARGIN + (int)((self::CONTENT_WIDTH - $newW) / 2);
                
                $imagick->compositeImage($extraImg, Imagick::COMPOSITE_OVER, $x, $y);
                
                $extraImg->clear();
                $extraImg->destroy();
                
                $y += $newH;
            } catch (\Throwable $e) {
                error_log("Error drawing extra image {$field}: " . $e->getMessage());
            }
        }
        
        return $y;
    }
    
    /**
     * Find grid bitmap file
     */
    private function findGridBitmap(string $gridBitmap): ?string
    {
        if (empty($gridBitmap)) {
            return null;
        }
        
        // Check if it's a data URL
        if (strpos($gridBitmap, 'data:image/') === 0) {
            // Save data URL to temp file
            return $this->saveDataUrlToTemp($gridBitmap);
        }
        
        // Try common locations
        $candidates = [
            $this->uploadsDir . '/grids/' . basename($gridBitmap),
            $this->uploadsDir . '/' . basename($gridBitmap),
            $this->uploadsDir . '/images/' . basename($gridBitmap),
        ];
        
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        
        return null;
    }
    
    /**
     * Save data URL to temporary file
     */
    private function saveDataUrlToTemp(string $dataUrl): ?string
    {
        try {
            $parts = explode(',', $dataUrl, 2);
            if (count($parts) !== 2) {
                return null;
            }
            
            $data = base64_decode($parts[1]);
            if ($data === false) {
                return null;
            }
            
            $tmpFile = tempnam(sys_get_temp_dir(), 'grid_');
            file_put_contents($tmpFile, $data);
            
            return $tmpFile;
        } catch (\Throwable $e) {
            error_log("Error saving data URL to temp: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get template path
     */
    private function getTemplatePath(): string
    {
        return $this->templatesDir . '/SF_report_bg.jpg';
    }
    
    /**
     * Get font path
     */
    private function getFont(string $variant = 'Regular'): string
    {
        // Try local OpenSans font first
        $fontPath = $this->fontsDir . '/OpenSans-' . $variant . '.ttf';
        
        if (file_exists($fontPath)) {
            return $fontPath;
        }
        
        // Fallback to system DejaVu font
        $fallbackFont = $variant === 'Bold' ? 'DejaVu-Sans-Bold' : 'DejaVu-Sans';
        
        return $fallbackFont;
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
                'root_causes' => 'Juurisyyt',
                'actions' => 'Toimenpiteet',
            ],
            'sv' => [
                'site' => 'Arbetsplats:',
                'date' => 'När?',
                'type_yellow' => 'FAROSITUATIONEN',
                'type_red' => 'FÖRSTA RAPPORT',
                'type_green' => 'UNDERSÖKNINGSRAPPORT',
                'root_causes' => 'Grundorsaker',
                'actions' => 'Åtgärder',
            ],
            'en' => [
                'site' => 'Worksite:',
                'date' => 'When?',
                'type_yellow' => 'HAZARD',
                'type_red' => 'INCIDENT',
                'type_green' => 'INVESTIGATION',
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
}