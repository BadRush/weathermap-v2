<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Render;

use BadRush\Weathermap\Geometry\ScaleResolver;
use GdImage;
use RuntimeException;

/**
 * Server-side PNG renderer powered by PHP GD (PHP 8.4/8.5 strict compliant).
 * Renders the exact Scene produced by SceneBuilder so PNG output matches the browser SVG viewer.
 */
final class GdRenderer
{
    private readonly string $fontRegular;

    private readonly string $fontBold;

    public function __construct(?string $fontsDir = null)
    {
        $base = $fontsDir ?? dirname(__DIR__, 2).'/resources/fonts';
        $this->fontRegular = $base.'/DejaVuSans.ttf';
        $this->fontBold = $base.'/DejaVuSans-Bold.ttf';
    }

    public static function hasGd(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public static function hasFreeType(): bool
    {
        if (! self::hasGd()) {
            return false;
        }
        $info = gd_info();

        return ! empty($info['FreeType Support']);
    }

    /**
     * Render a Scene to raw PNG bytes.
     *
     * @param  (callable(int): ?string)|null  $assetFileResolver  Maps asset ID -> absolute local file path
     */
    public function renderPng(Scene $scene, ?callable $assetFileResolver = null): string
    {
        if (! self::hasGd()) {
            throw new RuntimeException('PHP GD extension is required for PNG rendering.');
        }

        $width = max(100, min(8000, (int) ($scene->canvas['width'] ?? 1000)));
        $height = max(100, min(8000, (int) ($scene->canvas['height'] ?? 640)));

        $im = imagecreatetruecolor($width, $height);
        if (! $im instanceof GdImage) {
            throw new RuntimeException('Failed to allocate GD image canvas.');
        }

        imagealphablending($im, true);
        imagesavealpha($im, true);
        if (function_exists('imageantialias')) {
            imageantialias($im, true);
        }

        // Fill background color
        $bgColor = $this->allocateHex($im, is_string($scene->canvas['bgColor'] ?? null) ? $scene->canvas['bgColor'] : '#0f172a');
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $bgColor);

        // Optional background image asset
        $bgAssetId = $scene->canvas['backgroundAssetId'] ?? null;
        if (is_int($bgAssetId) && $assetFileResolver !== null) {
            $bgPath = $assetFileResolver($bgAssetId);
            if (is_string($bgPath) && is_file($bgPath)) {
                $bgImg = $this->loadImageFile($bgPath);
                if ($bgImg instanceof GdImage) {
                    imagecopyresampled($im, $bgImg, 0, 0, 0, 0, $width, $height, imagesx($bgImg), imagesy($bgImg));
                }
            }
        }

        // 1. Draw Links
        foreach ($scene->links as $link) {
            $this->drawLink($im, $link, $assetFileResolver);
        }

        // 2. Draw Nodes
        foreach ($scene->nodes as $node) {
            $this->drawNode($im, $node, $assetFileResolver);
        }

        // 3. Draw Free-Floating Static Labels (Annotations)
        foreach ($scene->annotations as $annotation) {
            $this->drawAnnotation($im, $annotation, $assetFileResolver);
        }

        // 4. Draw Legend
        if (is_array($scene->legend)) {
            $this->drawLegend($im, $scene->legend);
        }

        // 5. Draw Title & Timestamp
        $title = is_string($scene->canvas['title'] ?? null) ? $scene->canvas['title'] : '';
        if ($title !== '') {
            $titleColor = $this->allocateHex($im, is_string($scene->canvas['titleColor'] ?? null) ? $scene->canvas['titleColor'] : '#f8fafc');
            $this->drawText(
                $im,
                $title,
                (int) ($scene->canvas['titleX'] ?? 20),
                (int) ($scene->canvas['titleY'] ?? 28),
                (int) ($scene->canvas['titleFontSize'] ?? 18),
                $titleColor,
                bold: true,
                centered: false,
            );
        }

        if ((bool) ($scene->canvas['showTimestamp'] ?? true)) {
            $tsColor = $this->allocateHex($im, is_string($scene->canvas['timestampColor'] ?? null) ? $scene->canvas['timestampColor'] : '#94a3b8');
            $this->drawText(
                $im,
                $scene->timestampText,
                (int) ($scene->canvas['timestampX'] ?? 20),
                (int) ($scene->canvas['timestampY'] ?? 50),
                (int) ($scene->canvas['timestampFontSize'] ?? 10),
                $tsColor,
                bold: false,
                centered: false,
            );
        }

        return $this->encodePng($im);
    }

    /**
     * Generate a downscaled PNG thumbnail from full-size PNG bytes.
     */
    public function renderThumbnail(string $pngBytes, int $maxWidth = 360, int $maxHeight = 220): string
    {
        if (! self::hasGd()) {
            throw new RuntimeException('PHP GD extension is required for thumbnail rendering.');
        }

        $src = @imagecreatefromstring($pngBytes);
        if (! $src instanceof GdImage) {
            throw new RuntimeException('Invalid PNG image data for thumbnail generation.');
        }

        $srcW = max(1, imagesx($src));
        $srcH = max(1, imagesy($src));
        $scale = min($maxWidth / $srcW, $maxHeight / $srcH, 1.0);
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        $dst = imagecreatetruecolor($dstW, $dstH);
        if (! $dst instanceof GdImage) {
            throw new RuntimeException('Failed to allocate GD thumbnail canvas.');
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $this->encodePng($dst);
    }

    /**
     * Re-encode an uploaded image (PNG, JPEG, or WebP) as clean PNG to neutralize any embedded payload.
     */
    public function sanitizeUploadedImage(string $rawBytes, int $maxDimension = 4096): string
    {
        if (! self::hasGd()) {
            throw new RuntimeException('PHP GD extension is required to process uploaded images.');
        }

        $src = @imagecreatefromstring($rawBytes);
        if (! $src instanceof GdImage) {
            throw new RuntimeException('Uploaded file is not a valid raster image (PNG/JPEG/WebP).');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $scale = min($maxDimension / $srcW, $maxDimension / $srcH, 1.0);
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        $dst = imagecreatetruecolor($dstW, $dstH);
        if (! $dst instanceof GdImage) {
            throw new RuntimeException('Failed to allocate sanitized GD image.');
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($dst, 0, 0, $dstW - 1, $dstH - 1, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $this->encodePng($dst);
    }

    /**
     * @param  array<string, mixed>  $link
     * @param  (callable(int): ?string)|null  $assetFileResolver
     */
    private function drawLink(GdImage $im, array $link, ?callable $assetFileResolver): void
    {
        $outlineHex = is_string($link['outlineColor'] ?? null) ? $link['outlineColor'] : '#0f172a';
        $outlineColor = $this->allocateHex($im, $outlineHex);
        $fontSize = (int) ($link['bwFontSize'] ?? 9);

        // Pass 1: draw both directional arrow polygons
        foreach (['outArrow', 'inArrow'] as $key) {
            $arrow = $link[$key] ?? null;
            if (! is_array($arrow)) {
                continue;
            }
            $poly = $arrow['polygon'] ?? [];
            if (! is_array($poly) || count($poly) < 3) {
                continue;
            }

            $flat = [];
            foreach ($poly as $pt) {
                if (is_array($pt)) {
                    $flat[] = (int) round((float) ($pt['x'] ?? 0));
                    $flat[] = (int) round((float) ($pt['y'] ?? 0));
                }
            }
            if (count($flat) < 6) {
                continue;
            }

            $fillColor = $this->allocateHex($im, is_string($arrow['color'] ?? null) ? $arrow['color'] : '#64748b');
            imagefilledpolygon($im, $flat, $fillColor);
            imagepolygon($im, $flat, $outlineColor);
        }

        // Pass 2: draw bandwidth label boxes on top of both arrows
        foreach (['outArrow', 'inArrow'] as $key) {
            $arrow = $link[$key] ?? null;
            if (! is_array($arrow)) {
                continue;
            }
            $label = is_string($arrow['label'] ?? null) ? $arrow['label'] : '';
            $labelPos = is_array($arrow['labelPos'] ?? null) ? $arrow['labelPos'] : null;
            if ($label !== '' && $labelPos !== null) {
                $fillColor = $this->allocateHex($im, is_string($arrow['color'] ?? null) ? $arrow['color'] : '#64748b');
                $lx = (int) round((float) ($labelPos['x'] ?? 0));
                $ly = (int) round((float) ($labelPos['y'] ?? 0));
                $textColor = $this->allocateHex($im, is_string($arrow['labelTextColor'] ?? null) ? $arrow['labelTextColor'] : '#f8fafc');
                $this->drawLabelBox($im, $label, $lx, $ly, $fontSize, $fillColor, $outlineColor, $textColor);
            }
        }

        // Pass 3: draw link static vendor badge and optional direction comments
        $staticBadge = $link['staticBadge'] ?? null;
        if (is_array($staticBadge)) {
            $this->drawLinkStaticBadge($im, $staticBadge, $assetFileResolver);
        }

        $commentBg = $this->allocateHex($im, '#0f172a');
        $commentText = $this->allocateHex($im, '#cbd5e1');
        $commentFontSize = max(7, $fontSize - 1);
        foreach ([['outComment', 'outCommentPos'], ['inComment', 'inCommentPos']] as [$textKey, $posKey]) {
            $comment = is_string($link[$textKey] ?? null) ? trim($link[$textKey]) : '';
            $pos = is_array($link[$posKey] ?? null) ? $link[$posKey] : null;
            if ($comment !== '' && $pos !== null) {
                $cx = (int) round((float) ($pos['x'] ?? 0));
                $cy = (int) round((float) ($pos['y'] ?? 0));
                $this->drawLabelBox($im, $comment, $cx, $cy, $commentFontSize, $commentBg, $outlineColor, $commentText);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $badge
     * @param  (callable(int): ?string)|null  $assetFileResolver
     */
    private function drawLinkStaticBadge(GdImage $im, array $badge, ?callable $assetFileResolver): void
    {
        $pos = is_array($badge['pos'] ?? null) ? $badge['pos'] : null;
        if ($pos === null) {
            return;
        }

        $text = is_string($badge['text'] ?? null) ? $badge['text'] : '';
        $fontSize = max(7, min(24, (int) ($badge['fontSize'] ?? 10)));
        $iconSize = max(12, min(40, (int) ($badge['iconSize'] ?? 16)));
        $iconAssetId = $badge['iconAssetId'] ?? null;

        $iconImg = null;
        if (is_int($iconAssetId) && $assetFileResolver !== null) {
            $path = $assetFileResolver($iconAssetId);
            if (is_string($path) && is_file($path)) {
                $iconImg = $this->loadImageFile($path);
            }
        }

        $hasIcon = $iconImg instanceof GdImage;
        $hasText = $text !== '';
        if (! $hasIcon && ! $hasText) {
            return;
        }

        [$tw, $th] = $hasText ? $this->measureText($text, $fontSize, bold: true) : [0, 0];
        $gap = ($hasIcon && $hasText) ? 5 : 0;
        $iconW = $hasIcon ? $iconSize : 0;
        $iconH = $hasIcon ? $iconSize : 0;

        $padX = 6;
        $padY = 4;
        $boxW = $iconW + $gap + $tw + ($padX * 2);
        $boxH = max($iconH, $th) + ($padY * 2);

        $cx = (int) round((float) ($pos['x'] ?? 0));
        $cy = (int) round((float) ($pos['y'] ?? 0));
        $x1 = (int) round($cx - ($boxW / 2.0));
        $y1 = (int) round($cy - ($boxH / 2.0));
        $x2 = $x1 + $boxW;
        $y2 = $y1 + $boxH;

        $bgColor = $this->allocateHex($im, is_string($badge['bgColor'] ?? null) ? $badge['bgColor'] : '#0f172a');
        $borderColor = $this->allocateHex($im, is_string($badge['borderColor'] ?? null) ? $badge['borderColor'] : '#334155');
        $textColor = $this->allocateHex($im, is_string($badge['textColor'] ?? null) ? $badge['textColor'] : '#e2e8f0');

        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $bgColor);
        imagerectangle($im, $x1, $y1, $x2, $y2, $borderColor);

        if ($iconImg instanceof GdImage) {
            $ix = $x1 + $padX;
            $iy = (int) round($cy - ($iconH / 2.0));
            $this->drawResampledContain($im, $iconImg, $ix, $iy, $iconSize, $iconSize);
        }

        if ($hasText) {
            $textCenterX = (int) round($x1 + $padX + $iconW + $gap + ($tw / 2.0));
            $this->drawText($im, $text, $textCenterX, $cy, $fontSize, $textColor, bold: true, centered: true);
        }
    }

    /**
     * @param  array<string, mixed>  $annotation
     * @param  (callable(int): ?string)|null  $assetFileResolver
     */
    private function drawAnnotation(GdImage $im, array $annotation, ?callable $assetFileResolver): void
    {
        $rawLines = $annotation['lines'] ?? [];
        $lines = [];
        if (is_array($rawLines)) {
            foreach ($rawLines as $line) {
                if (is_string($line)) {
                    $lines[] = $line;
                }
            }
        }

        $iconAssetId = $annotation['iconAssetId'] ?? null;
        $iconImg = null;
        if (is_int($iconAssetId) && $assetFileResolver !== null) {
            $path = $assetFileResolver($iconAssetId);
            if (is_string($path) && is_file($path)) {
                $iconImg = $this->loadImageFile($path);
            }
        }

        $hasIcon = $iconImg instanceof GdImage;
        $hasText = $lines !== [];
        if (! $hasIcon && ! $hasText) {
            return;
        }

        $ax = (int) round((float) ($annotation['x'] ?? 100));
        $ay = (int) round((float) ($annotation['y'] ?? 100));
        $style = is_string($annotation['style'] ?? null) ? $annotation['style'] : 'card';
        $align = is_string($annotation['align'] ?? null) ? $annotation['align'] : 'left';
        $fontSize = max(8, min(48, (int) ($annotation['fontSize'] ?? 12)));
        $iconPosition = is_string($annotation['iconPosition'] ?? null) ? $annotation['iconPosition'] : 'left';
        $iconSize = max(12, min(128, (int) ($annotation['iconSize'] ?? 24)));

        $lineHeight = max($fontSize + 4, (int) round($fontSize * 1.4));
        $lineWidths = [];
        $maxLineW = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                $lineWidths[] = 0;

                continue;
            }
            [$lw] = $this->measureText($line, $fontSize, bold: false);
            $lineWidths[] = $lw;
            if ($lw > $maxLineW) {
                $maxLineW = $lw;
            }
        }

        $textBlockW = $maxLineW;
        $textBlockH = $hasText ? (count($lines) * $lineHeight) : 0;
        $padX = $style === 'card' ? 10 : 2;
        $padY = $style === 'card' ? 8 : 2;
        $gap = ($hasIcon && $hasText) ? 8 : 0;

        if ($iconPosition === 'top') {
            $innerW = max($hasIcon ? $iconSize : 0, $textBlockW);
            $innerH = ($hasIcon ? $iconSize : 0) + $gap + $textBlockH;
        } else {
            $innerW = ($hasIcon ? $iconSize : 0) + $gap + $textBlockW;
            $innerH = max($hasIcon ? $iconSize : 0, $textBlockH);
        }

        $boxW = $innerW + ($padX * 2);
        $boxH = $innerH + ($padY * 2);

        if ($style === 'card') {
            $bgColor = $this->allocateHex($im, is_string($annotation['bgColor'] ?? null) ? $annotation['bgColor'] : '#1e293b');
            $borderColor = $this->allocateHex($im, is_string($annotation['borderColor'] ?? null) ? $annotation['borderColor'] : '#475569');
            imagefilledrectangle($im, $ax, $ay, $ax + $boxW, $ay + $boxH, $bgColor);
            imagerectangle($im, $ax, $ay, $ax + $boxW, $ay + $boxH, $borderColor);
        }

        if ($iconImg instanceof GdImage) {
            if ($iconPosition === 'top') {
                $ix = match ($align) {
                    'center' => $ax + $padX + (int) round(($innerW - $iconSize) / 2.0),
                    'right' => $ax + $padX + $innerW - $iconSize,
                    default => $ax + $padX,
                };
                $iy = $ay + $padY;
            } else {
                $ix = $ax + $padX;
                $iy = $ay + $padY + (int) round(($innerH - $iconSize) / 2.0);
            }
            $this->drawResampledContain($im, $iconImg, $ix, $iy, $iconSize, $iconSize);
        }

        if ($hasText) {
            $textColor = $this->allocateHex($im, is_string($annotation['textColor'] ?? null) ? $annotation['textColor'] : '#f8fafc');
            if ($iconPosition === 'top') {
                $textOriginX = $ax + $padX;
                $textAreaW = $innerW;
                $textOriginY = $ay + $padY + ($hasIcon ? $iconSize + $gap : 0);
            } else {
                $textOriginX = $ax + $padX + ($hasIcon ? $iconSize + $gap : 0);
                $textAreaW = $textBlockW;
                $textOriginY = $ay + $padY + (int) round(($innerH - $textBlockH) / 2.0);
            }

            foreach ($lines as $idx => $line) {
                if ($line === '') {
                    continue;
                }
                $lw = $lineWidths[$idx] ?? 0;
                $lineCenterY = $textOriginY + (int) round(($idx + 0.5) * $lineHeight);
                $lineCenterX = match ($align) {
                    'center' => $textOriginX + (int) round($textAreaW / 2.0),
                    'right' => $textOriginX + $textAreaW - (int) round($lw / 2.0),
                    default => $textOriginX + (int) round($lw / 2.0),
                };
                $this->drawText($im, $line, $lineCenterX, $lineCenterY, $fontSize, $textColor, bold: false, centered: true);
            }
        }
    }

    private function drawResampledContain(GdImage $dst, GdImage $src, int $x, int $y, int $boxW, int $boxH): void
    {
        $srcW = max(1, imagesx($src));
        $srcH = max(1, imagesy($src));
        $scale = min($boxW / $srcW, $boxH / $srcH);
        $drawW = max(1, (int) round($srcW * $scale));
        $drawH = max(1, (int) round($srcH * $scale));
        $offsetX = $x + (int) round(($boxW - $drawW) / 2.0);
        $offsetY = $y + (int) round(($boxH - $drawH) / 2.0);
        imagecopyresampled($dst, $src, $offsetX, $offsetY, 0, 0, $drawW, $drawH, $srcW, $srcH);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  (callable(int): ?string)|null  $assetFileResolver
     */
    private function drawNode(GdImage $im, array $node, ?callable $assetFileResolver): void
    {
        $cx = (int) round((float) ($node['x'] ?? 0));
        $cy = (int) round((float) ($node['y'] ?? 0));
        $w = max(12, (int) ($node['iconWidth'] ?? 64));
        $h = max(12, (int) ($node['iconHeight'] ?? 36));
        $x1 = (int) round($cx - $w / 2.0);
        $y1 = (int) round($cy - $h / 2.0);
        $x2 = $x1 + $w;
        $y2 = $y1 + $h;

        $fill = $this->allocateHex($im, is_string($node['bgColor'] ?? null) ? $node['bgColor'] : '#1e293b');
        $outline = $this->allocateHex($im, is_string($node['outlineColor'] ?? null) ? $node['outlineColor'] : '#64748b');
        $iconType = is_string($node['iconType'] ?? null) ? $node['iconType'] : 'shape:box';

        $drewAsset = false;
        $assetId = $node['iconAssetId'] ?? null;
        if ($iconType === 'asset' && is_int($assetId) && $assetFileResolver !== null) {
            $path = $assetFileResolver($assetId);
            if (is_string($path) && is_file($path)) {
                $iconImg = $this->loadImageFile($path);
                if ($iconImg instanceof GdImage) {
                    imagecopyresampled($im, $iconImg, $x1, $y1, 0, 0, $w, $h, imagesx($iconImg), imagesy($iconImg));
                    $drewAsset = true;
                }
            }
        }

        if (! $drewAsset) {
            if ($iconType === 'shape:round') {
                imagefilledellipse($im, $cx, $cy, $w, $h, $fill);
                imageellipse($im, $cx, $cy, $w, $h, $outline);
            } else {
                imagefilledrectangle($im, $x1, $y1, $x2, $y2, $fill);
                imagerectangle($im, $x1, $y1, $x2, $y2, $outline);
            }
        }

        $label = is_string($node['label'] ?? null) ? $node['label'] : '';
        if ($label !== '') {
            $labelPos = is_array($node['labelPos'] ?? null) ? $node['labelPos'] : ['x' => $cx, 'y' => $cy];
            $lx = (int) round((float) ($labelPos['x'] ?? $cx));
            $ly = (int) round((float) ($labelPos['y'] ?? $cy));
            $textColor = $this->allocateHex($im, is_string($node['labelColor'] ?? null) ? $node['labelColor'] : '#f8fafc');
            $fontSize = (int) ($node['labelFontSize'] ?? 11);

            $this->drawText($im, $label, $lx, $ly, $fontSize, $textColor, bold: true, centered: true);
        }
    }

    /**
     * @param  array<string, mixed>  $legend
     */
    private function drawLegend(GdImage $im, array $legend): void
    {
        $stops = is_array($legend['stops'] ?? null) ? $legend['stops'] : [];
        if ($stops === []) {
            return;
        }

        $x = (int) ($legend['x'] ?? 20);
        $y = (int) ($legend['y'] ?? 440);
        $title = is_string($legend['title'] ?? null) ? $legend['title'] : 'Traffic Load';
        $style = is_string($legend['style'] ?? null) ? $legend['style'] : 'classic';
        $fontSize = (int) ($legend['fontSize'] ?? 10);

        $bg = $this->allocateHex($im, is_string($legend['bgColor'] ?? null) ? $legend['bgColor'] : '#1e293b');
        $outline = $this->allocateHex($im, is_string($legend['outlineColor'] ?? null) ? $legend['outlineColor'] : '#475569');
        $text = $this->allocateHex($im, is_string($legend['textColor'] ?? null) ? $legend['textColor'] : '#f1f5f9');

        $pad = 10;
        $headerH = 22;

        if ($style === 'horizontal') {
            $barW = 240;
            $barH = 16;
            $boxW = $barW + ($pad * 2);
            $boxH = $headerH + $barH + 26;

            imagefilledrectangle($im, $x, $y, $x + $boxW, $y + $boxH, $bg);
            imagerectangle($im, $x, $y, $x + $boxW, $y + $boxH, $outline);
            $this->drawText($im, $title, $x + $pad, $y + 18, $fontSize, $text, bold: true, centered: false);

            $count = count($stops);
            $segW = $barW / max(1, $count);
            foreach ($stops as $idx => $stop) {
                if (! is_array($stop)) {
                    continue;
                }
                $sx1 = (int) round($x + $pad + ($idx * $segW));
                $sx2 = (int) round($x + $pad + (($idx + 1) * $segW));
                $sy1 = $y + $headerH + 4;
                $sy2 = $sy1 + $barH;
                $c1 = is_string($stop['color1'] ?? null) ? $stop['color1'] : '#64748b';
                $c2 = is_string($stop['color2'] ?? null) ? $stop['color2'] : null;
                $this->fillGradientRect($im, $sx1, $sy1, $sx2, $sy2, $c1, $c2, horizontal: true);
            }
            imagerectangle($im, $x + $pad, $y + $headerH + 4, $x + $pad + $barW, $y + $headerH + 4 + $barH, $outline);
            $this->drawText($im, '0%', $x + $pad, $y + $boxH - 6, max(7, $fontSize - 1), $text, bold: false, centered: false);
            $this->drawText($im, '100%', $x + $pad + $barW - 28, $y + $boxH - 6, max(7, $fontSize - 1), $text, bold: false, centered: false);

            return;
        }

        // Classic / vertical legend
        $rowH = 17;
        $swatchW = 22;
        $boxW = 154;
        $boxH = $headerH + (count($stops) * $rowH) + $pad;

        imagefilledrectangle($im, $x, $y, $x + $boxW, $y + $boxH, $bg);
        imagerectangle($im, $x, $y, $x + $boxW, $y + $boxH, $outline);
        $this->drawText($im, $title, $x + $pad, $y + 18, $fontSize, $text, bold: true, centered: false);

        foreach ($stops as $idx => $stop) {
            if (! is_array($stop)) {
                continue;
            }
            $ry = $y + $headerH + 4 + ($idx * $rowH);
            $c1 = is_string($stop['color1'] ?? null) ? $stop['color1'] : '#64748b';
            $c2 = is_string($stop['color2'] ?? null) ? $stop['color2'] : null;

            $this->fillGradientRect($im, $x + $pad, $ry, $x + $pad + $swatchW, $ry + 12, $c1, $c2, horizontal: true);
            imagerectangle($im, $x + $pad, $ry, $x + $pad + $swatchW, $ry + 12, $outline);

            $label = is_string($stop['label'] ?? null) ? $stop['label'] : '';
            $this->drawText($im, $label, $x + $pad + $swatchW + 8, $ry + 10, max(7, $fontSize - 1), $text, bold: false, centered: false);
        }
    }

    private function fillGradientRect(
        GdImage $im,
        int $x1,
        int $y1,
        int $x2,
        int $y2,
        string $hex1,
        ?string $hex2,
        bool $horizontal,
    ): void {
        if ($hex2 === null || $hex2 === $hex1) {
            $color = $this->allocateHex($im, $hex1);
            imagefilledrectangle($im, $x1, $y1, $x2, $y2, $color);

            return;
        }

        $rgb1 = ScaleResolver::hexToRgb($hex1);
        $rgb2 = ScaleResolver::hexToRgb($hex2);
        $span = max(1, $horizontal ? ($x2 - $x1) : ($y2 - $y1));

        for ($i = 0; $i <= $span; $i++) {
            $t = $i / $span;
            $r = max(0, min(255, (int) round($rgb1[0] + ($rgb2[0] - $rgb1[0]) * $t)));
            $g = max(0, min(255, (int) round($rgb1[1] + ($rgb2[1] - $rgb1[1]) * $t)));
            $b = max(0, min(255, (int) round($rgb1[2] + ($rgb2[2] - $rgb1[2]) * $t)));
            $c = imagecolorallocate($im, $r, $g, $b);
            if ($c === false) {
                continue;
            }
            if ($horizontal) {
                imageline($im, $x1 + $i, $y1, $x1 + $i, $y2, $c);
            } else {
                imageline($im, $x1, $y1 + $i, $x2, $y1 + $i, $c);
            }
        }
    }

    private function drawLabelBox(
        GdImage $im,
        string $text,
        int $cx,
        int $cy,
        int $fontSize,
        int $bgColor,
        int $outlineColor,
        int $textColor,
    ): void {
        [$tw, $th] = $this->measureText($text, $fontSize, bold: true);
        $padX = 5;
        $padY = 3;
        $x1 = (int) round($cx - ($tw / 2.0) - $padX);
        $y1 = (int) round($cy - ($th / 2.0) - $padY);
        $x2 = (int) round($cx + ($tw / 2.0) + $padX);
        $y2 = (int) round($cy + ($th / 2.0) + $padY);

        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $bgColor);
        imagerectangle($im, $x1, $y1, $x2, $y2, $outlineColor);
        $this->drawText($im, $text, $cx, $cy, $fontSize, $textColor, bold: true, centered: true);
    }

    private function drawText(
        GdImage $im,
        string $text,
        int $x,
        int $y,
        int $fontSize,
        int $color,
        bool $bold,
        bool $centered,
    ): void {
        $fontFile = $bold && is_file($this->fontBold) ? $this->fontBold : $this->fontRegular;

        if (self::hasFreeType() && is_file($fontFile)) {
            $ptSize = max(6.0, $fontSize * 0.78);
            if ($centered) {
                $bbox = imagettfbbox($ptSize, 0.0, $fontFile, $text);
                if (is_array($bbox)) {
                    $tw = abs($bbox[2] - $bbox[0]);
                    $th = abs($bbox[7] - $bbox[1]);
                    $drawX = (int) round($x - ($tw / 2.0));
                    $drawY = (int) round($y + ($th / 2.0));
                    imagettftext($im, $ptSize, 0.0, $drawX, $drawY, $color, $fontFile, $text);

                    return;
                }
            }

            imagettftext($im, $ptSize, 0.0, $x, $y, $color, $fontFile, $text);

            return;
        }

        // Fallback to GD built-in bitmap font when FreeType is unavailable
        $gdFont = $fontSize >= 14 ? 5 : ($fontSize >= 11 ? 4 : ($fontSize >= 9 ? 3 : 2));
        $fw = imagefontwidth($gdFont) * strlen($text);
        $fh = imagefontheight($gdFont);
        $drawX = $centered ? (int) round($x - ($fw / 2.0)) : $x;
        $drawY = $centered ? (int) round($y - ($fh / 2.0)) : max(0, $y - $fh);
        imagestring($im, $gdFont, $drawX, $drawY, $text, $color);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function measureText(string $text, int $fontSize, bool $bold): array
    {
        $fontFile = $bold && is_file($this->fontBold) ? $this->fontBold : $this->fontRegular;

        if (self::hasFreeType() && is_file($fontFile)) {
            $ptSize = max(6.0, $fontSize * 0.78);
            $bbox = imagettfbbox($ptSize, 0.0, $fontFile, $text);
            if (is_array($bbox)) {
                return [
                    max(1, (int) round(abs($bbox[2] - $bbox[0]))),
                    max(1, (int) round(abs($bbox[7] - $bbox[1]))),
                ];
            }
        }

        $gdFont = $fontSize >= 14 ? 5 : ($fontSize >= 11 ? 4 : ($fontSize >= 9 ? 3 : 2));

        return [
            max(1, imagefontwidth($gdFont) * strlen($text)),
            max(1, imagefontheight($gdFont)),
        ];
    }

    private function allocateHex(GdImage $im, string $hex): int
    {
        [$r, $g, $b] = ScaleResolver::hexToRgb($hex);
        $allocated = imagecolorallocate($im, $r, $g, $b);

        return $allocated !== false ? $allocated : 0;
    }

    private function loadImageFile(string $path): ?GdImage
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $img = @imagecreatefromstring($raw);

        return $img instanceof GdImage ? $img : null;
    }

    private function encodePng(GdImage $im): string
    {
        ob_start();
        $ok = imagepng($im, null, 6);
        $bytes = ob_get_clean();

        if (! $ok || ! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Failed to encode GD image to PNG.');
        }

        return $bytes;
    }
}
