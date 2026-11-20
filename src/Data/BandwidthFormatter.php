<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Data;

use BadRush\Weathermap\Document\LinkDef;

final class BandwidthFormatter
{
    /**
     * Format bits-per-second using SI suffixes (K, M, G, T) consistent with classic Weathermap %k formatting.
     */
    public static function formatBits(float $bps): string
    {
        $abs = abs($bps);
        $sign = $bps < 0.0 ? '-' : '';

        if ($abs >= 1_000_000_000_000.0) {
            return $sign.self::trimTrailingZero($abs / 1_000_000_000_000.0).'T';
        }
        if ($abs >= 1_000_000_000.0) {
            return $sign.self::trimTrailingZero($abs / 1_000_000_000.0).'G';
        }
        if ($abs >= 1_000_000.0) {
            return $sign.self::trimTrailingZero($abs / 1_000_000.0).'M';
        }
        if ($abs >= 1_000.0) {
            return $sign.self::trimTrailingZero($abs / 1_000.0).'K';
        }

        return $sign.(string) (int) round($abs);
    }

    public static function formatPercent(float $percent): string
    {
        if ($percent < 10.0 && $percent > 0.0) {
            return number_format($percent, 1).'%';
        }

        return (string) (int) round($percent).'%';
    }

    public static function formatLinkLabel(string $mode, ?float $bps, ?float $percent, bool $isDown, bool $isStale): string
    {
        if ($mode === LinkDef::BWLABEL_NONE) {
            return '';
        }
        if ($isDown) {
            return 'DOWN';
        }
        if ($isStale || $bps === null || $percent === null) {
            return 'N/A';
        }

        return match ($mode) {
            LinkDef::BWLABEL_BITS => self::formatBits($bps),
            default => self::formatPercent($percent),
        };
    }

    /**
     * Parse Weathermap bandwidth strings like "100M", "10G", "2.5G", "500K" into bits per second.
     */
    public static function parseBandwidth(string $raw): ?float
    {
        $clean = trim($raw);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT])?(?:bps|b)?$/i', $clean, $m) !== 1) {
            return null;
        }

        $val = (float) $m[1];
        $unit = strtoupper($m[2] ?? '');

        return match ($unit) {
            'K' => $val * 1_000.0,
            'M' => $val * 1_000_000.0,
            'G' => $val * 1_000_000_000.0,
            'T' => $val * 1_000_000_000_000.0,
            default => $val,
        };
    }

    private static function trimTrailingZero(float $value): string
    {
        $formatted = $value >= 100.0
            ? number_format($value, 0, '.', '')
            : number_format($value, 1, '.', '');

        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
    }
}
