<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Geometry;

use BadRush\Weathermap\Document\NodeDef;

final class LabelPlacer
{
    /**
     * Place a bandwidth label along a half-link spine at normalized position t in [0, 1].
     * Note: Weathermap's classic BWLABELPOS uses percentage along the full link (e.g. 0.75 / 0.25),
     * while our normalized link fields inLabelPos / outLabelPos place labels cleanly along the full centerline.
     *
     * @param  list<Point>  $centerline
     */
    public static function onCenterline(array $centerline, float $fraction): Point
    {
        return CatmullRom::pointAtFraction($centerline, max(0.05, min(0.95, $fraction)));
    }

    /**
     * Resolve node label center position based on node geometry and labelOffset (C, N, S, E, W, NE, NW, SE, SW, or dx:dy).
     */
    public static function forNode(NodeDef $node): Point
    {
        $offset = trim($node->labelOffset);
        if ($offset === '' || strtoupper($offset) === 'C') {
            return new Point($node->x, $node->y);
        }

        if (preg_match('/^([+-]?\d+(?:\.\d+)?):([+-]?\d+(?:\.\d+)?)$/', $offset, $m) === 1) {
            return new Point($node->x + (float) $m[1], $node->y + (float) $m[2]);
        }

        $padX = ($node->iconWidth / 2.0) + 12.0;
        $padY = ($node->iconHeight / 2.0) + 12.0;
        $dir = strtoupper($offset);

        $dx = match ($dir) {
            'E', 'NE', 'SE' => $padX,
            'W', 'NW', 'SW' => -$padX,
            default => 0.0,
        };
        $dy = match ($dir) {
            'S', 'SE', 'SW' => $padY,
            'N', 'NE', 'NW' => -$padY,
            default => 0.0,
        };

        return new Point($node->x + $dx, $node->y + $dy);
    }
}
