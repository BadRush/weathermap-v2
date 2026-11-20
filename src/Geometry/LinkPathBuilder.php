<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Geometry;

use BadRush\Weathermap\Document\LinkDef;
use BadRush\Weathermap\Document\NodeDef;

final class LinkPathBuilder
{
    /**
     * Build the full centerline and split outbound/inbound spines for a link.
     *
     * @param  int  $parallelIndex  0-based index among parallel links sharing the same node pair
     * @param  int  $parallelCount  total parallel links sharing the same node pair
     * @return array{centerline: list<Point>, outSpine: list<Point>, inSpine: list<Point>}
     */
    public static function build(
        LinkDef $link,
        NodeDef $nodeA,
        NodeDef $nodeB,
        int $parallelIndex = 0,
        int $parallelCount = 1,
    ): array {
        $start = EndpointOffset::resolve($nodeA, $link->a->offset);
        $end = EndpointOffset::resolve($nodeB, $link->b->offset);

        $controlPoints = [$start];

        if ($link->via !== []) {
            foreach ($link->via as $v) {
                $controlPoints[] = new Point($v->x, $v->y);
            }
        } elseif ($parallelCount > 1 && strtoupper($link->a->offset) === 'C' && strtoupper($link->b->offset) === 'C') {
            // Auto-separate parallel links between the same two nodes using a perpendicular midpoint offset
            $mid = $start->lerp($end, 0.5);
            // Canonicalize axis direction so links A->B and B->A separate symmetrically
            $canonicalDir = ($nodeA->id <= $nodeB->id)
                ? $start->vectorTo($end)
                : $end->vectorTo($start);
            $normal = $canonicalDir->normal();
            $slot = $parallelIndex - (($parallelCount - 1) / 2.0);
            $spacing = max(28.0, $link->width * 4.0);
            $controlPoints[] = $mid->add($normal->scale($slot * $spacing));
        }

        $controlPoints[] = $end;

        $centerline = ($link->viaStyle === LinkDef::VIA_CURVED && count($controlPoints) > 2)
            ? CatmullRom::sample($controlPoints, 16)
            : $controlPoints;

        [$outSpine, $inSpine] = CatmullRom::splitPolyline($centerline, $link->splitPos);

        return [
            'centerline' => $centerline,
            'outSpine' => $outSpine,
            'inSpine' => $inSpine,
        ];
    }
}
