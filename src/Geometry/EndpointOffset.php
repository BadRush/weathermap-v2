<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Geometry;

use BadRush\Weathermap\Document\NodeDef;

final class EndpointOffset
{
    /**
     * Resolve a Weathermap offset string (compass N/S/E/W/NE/NW/SE/SW/C, optional percentage like N95, or dx:dy)
     * relative to a node's center and icon box dimensions.
     */
    public static function resolve(NodeDef $node, string $offset): Point
    {
        $trimmed = trim($offset);
        if ($trimmed === '' || strtoupper($trimmed) === 'C') {
            return new Point($node->x, $node->y);
        }

        // Explicit dx:dy offset
        if (preg_match('/^([+-]?\d+(?:\.\d+)?):([+-]?\d+(?:\.\d+)?)$/', $trimmed, $m) === 1) {
            return new Point($node->x + (float) $m[1], $node->y + (float) $m[2]);
        }

        // Compass offset with optional percentage (default 100% of half width/height)
        if (preg_match('/^(NE|NW|SE|SW|N|S|E|W)(\d{1,3})?$/i', $trimmed, $m) === 1) {
            $dir = strtoupper($m[1]);
            $pct = isset($m[2]) ? ((float) $m[2]) / 100.0 : 1.0;
            $hw = ($node->iconWidth / 2.0) * $pct;
            $hh = ($node->iconHeight / 2.0) * $pct;

            $dx = match ($dir) {
                'E', 'NE', 'SE' => $hw,
                'W', 'NW', 'SW' => -$hw,
                default => 0.0,
            };

            $dy = match ($dir) {
                'S', 'SE', 'SW' => $hh,
                'N', 'NE', 'NW' => -$hh,
                default => 0.0,
            };

            return new Point($node->x + $dx, $node->y + $dy);
        }

        return new Point($node->x, $node->y);
    }
}
