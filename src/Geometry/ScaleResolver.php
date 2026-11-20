<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Geometry;

use BadRush\Weathermap\Document\ScaleDef;

final class ScaleResolver
{
    public const string COLOR_STALE = '#64748b';

    public const string COLOR_DOWN = '#991b1b';

    /**
     * Resolve a utilization percentage to a hex color string using the given scale.
     */
    public static function resolveColor(ScaleDef $scale, ?float $percent, bool $isDown = false, bool $isStale = false): string
    {
        if ($isDown) {
            return self::COLOR_DOWN;
        }
        if ($isStale || $percent === null || is_nan($percent)) {
            return self::COLOR_STALE;
        }

        $pct = max(0.0, min(100.0, $percent));

        foreach ($scale->stops as $stop) {
            // Exact zero stop (0..0) only matches exact zero
            if ($stop->min === $stop->max) {
                if ($pct === $stop->min) {
                    return self::rgbToHex($stop->rgb1);
                }

                continue;
            }

            if ($pct >= $stop->min && $pct <= $stop->max) {
                if ($stop->rgb2 === null) {
                    return self::rgbToHex($stop->rgb1);
                }

                $ratio = ($pct - $stop->min) / ($stop->max - $stop->min);
                $r = (int) round($stop->rgb1[0] + ($stop->rgb2[0] - $stop->rgb1[0]) * $ratio);
                $g = (int) round($stop->rgb1[1] + ($stop->rgb2[1] - $stop->rgb1[1]) * $ratio);
                $b = (int) round($stop->rgb1[2] + ($stop->rgb2[2] - $stop->rgb1[2]) * $ratio);

                return self::rgbToHex([$r, $g, $b]);
            }
        }

        $last = $scale->stops[count($scale->stops) - 1] ?? null;

        return $last !== null
            ? self::rgbToHex($last->rgb2 ?? $last->rgb1)
            : self::COLOR_STALE;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public static function rgbToHex(array $rgb): string
    {
        return sprintf(
            '#%02x%02x%02x',
            max(0, min(255, $rgb[0])),
            max(0, min(255, $rgb[1])),
            max(0, min(255, $rgb[2])),
        );
    }

    /**
     * Parse #rgb or #rrggbb to [r, g, b].
     *
     * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
     */
    public static function hexToRgb(string $hex): array
    {
        $clean = ltrim(trim($hex), '#');
        if (strlen($clean) === 3) {
            $clean = $clean[0].$clean[0].$clean[1].$clean[1].$clean[2].$clean[2];
        }
        if (strlen($clean) !== 6 || ! ctype_xdigit($clean)) {
            return [100, 116, 139];
        }

        return [
            max(0, min(255, (int) hexdec(substr($clean, 0, 2)))),
            max(0, min(255, (int) hexdec(substr($clean, 2, 2)))),
            max(0, min(255, (int) hexdec(substr($clean, 4, 2)))),
        ];
    }

    /**
     * Choose high-contrast foreground text color (#0f172a or #f8fafc) for a given hex background.
     */
    public static function contrastText(string $bgHex): string
    {
        [$r, $g, $b] = self::hexToRgb($bgHex);
        // Relative luminance approximation (WCAG)
        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255.0;

        return $luminance > 0.52 ? '#0f172a' : '#f8fafc';
    }
}
