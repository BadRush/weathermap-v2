<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Geometry;

/**
 * Catmull-Rom spline sampler and polyline splitting utilities,
 * adapted from PHP Network Weathermap 0.98a curve calculations.
 */
final class CatmullRom
{
    /**
     * Sample a Catmull-Rom spline passing through all control points.
     *
     * @param  list<Point>  $controlPoints
     * @return list<Point>
     */
    public static function sample(array $controlPoints, int $samplesPerSegment = 16): array
    {
        $count = count($controlPoints);
        if ($count <= 2) {
            return $controlPoints;
        }

        $samplesPerSegment = max(2, $samplesPerSegment);

        // Duplicate endpoints (classic Weathermap boundary condition)
        $pts = [$controlPoints[0], ...$controlPoints, $controlPoints[$count - 1]];
        $result = [];

        for ($i = 1; $i < count($pts) - 2; $i++) {
            $p0 = $pts[$i - 1];
            $p1 = $pts[$i];
            $p2 = $pts[$i + 1];
            $p3 = $pts[$i + 2];

            for ($step = 0; $step < $samplesPerSegment; $step++) {
                $t = $step / $samplesPerSegment;
                $result[] = self::evaluate($p0, $p1, $p2, $p3, $t);
            }
        }

        $result[] = $controlPoints[$count - 1];

        return $result;
    }

    private static function evaluate(Point $p0, Point $p1, Point $p2, Point $p3, float $t): Point
    {
        $t2 = $t * $t;
        $t3 = $t2 * $t;

        $x = 0.5 * (
            (2.0 * $p1->x)
            + (-$p0->x + $p2->x) * $t
            + (2.0 * $p0->x - 5.0 * $p1->x + 4.0 * $p2->x - $p3->x) * $t2
            + (-$p0->x + 3.0 * $p1->x - 3.0 * $p2->x + $p3->x) * $t3
        );

        $y = 0.5 * (
            (2.0 * $p1->y)
            + (-$p0->y + $p2->y) * $t
            + (2.0 * $p0->y - 5.0 * $p1->y + 4.0 * $p2->y - $p3->y) * $t2
            + (-$p0->y + 3.0 * $p1->y - 3.0 * $p2->y + $p3->y) * $t3
        );

        return new Point($x, $y);
    }

    /**
     * @param  list<Point>  $points
     */
    public static function polylineLength(array $points): float
    {
        $total = 0.0;
        $n = count($points);
        for ($i = 1; $i < $n; $i++) {
            $total += $points[$i - 1]->distanceTo($points[$i]);
        }

        return $total;
    }

    /**
     * Find the point along a polyline at a fractional distance in [0, 1].
     *
     * @param  list<Point>  $points
     */
    public static function pointAtFraction(array $points, float $fraction): Point
    {
        $n = count($points);
        if ($n === 0) {
            return new Point(0.0, 0.0);
        }
        if ($n === 1) {
            return $points[0];
        }

        $clamped = max(0.0, min(1.0, $fraction));
        $total = self::polylineLength($points);
        if ($total < 1e-9) {
            return $points[0];
        }

        $target = $total * $clamped;
        $walked = 0.0;

        for ($i = 1; $i < $n; $i++) {
            $segLen = $points[$i - 1]->distanceTo($points[$i]);
            if ($walked + $segLen >= $target && $segLen > 1e-9) {
                $localT = ($target - $walked) / $segLen;

                return $points[$i - 1]->lerp($points[$i], $localT);
            }
            $walked += $segLen;
        }

        return $points[$n - 1];
    }

    /**
     * Split a polyline at fractional arc-length into two directional spines:
     * - [0]: from start -> splitPoint
     * - [1]: from end -> splitPoint (reversed, so both arrows point toward splitPoint)
     *
     * @param  list<Point>  $points
     * @return array{0: list<Point>, 1: list<Point>}
     */
    public static function splitPolyline(array $points, float $fraction = 0.5): array
    {
        $n = count($points);
        if ($n < 2) {
            return [$points, array_reverse($points)];
        }

        $clamped = max(0.05, min(0.95, $fraction));
        $total = self::polylineLength($points);
        if ($total < 1e-9) {
            return [[$points[0], $points[$n - 1]], [$points[$n - 1], $points[0]]];
        }

        $target = $total * $clamped;
        $walked = 0.0;
        $first = [$points[0]];
        $second = [];
        $splitFound = false;

        for ($i = 1; $i < $n; $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $segLen = $a->distanceTo($b);

            if (! $splitFound && $walked + $segLen >= $target && $segLen > 1e-9) {
                $localT = ($target - $walked) / $segLen;
                $mid = $a->lerp($b, $localT);
                if ($first[count($first) - 1]->distanceTo($mid) > 1e-6) {
                    $first[] = $mid;
                }
                $second[] = $mid;
                if ($mid->distanceTo($b) > 1e-6) {
                    $second[] = $b;
                }
                $splitFound = true;
            } elseif (! $splitFound) {
                $first[] = $b;
            } else {
                $second[] = $b;
            }

            $walked += $segLen;
        }

        if (! $splitFound) {
            $mid = $points[$n - 1];
            $second = [$mid];
        }

        return [$first, array_reverse($second)];
    }

    /**
     * Truncate a polyline from the end by $trimDistance pixels, returning the shortened spine and exact tip direction.
     *
     * @param  list<Point>  $points
     * @return array{spine: list<Point>, tip: Point, direction: Vector}
     */
    public static function trimEnd(array $points, float $trimDistance): array
    {
        $n = count($points);
        $tip = $points[$n - 1];
        $total = self::polylineLength($points);

        if ($n < 2 || $total < 1e-9) {
            return [
                'spine' => $points,
                'tip' => $tip,
                'direction' => new Vector(1.0, 0.0),
            ];
        }

        $keepLength = max(0.0, $total - $trimDistance);
        $walked = 0.0;
        $trimmed = [$points[0]];

        for ($i = 1; $i < $n; $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $segLen = $a->distanceTo($b);

            if ($walked + $segLen >= $keepLength && $segLen > 1e-9) {
                $localT = ($keepLength - $walked) / $segLen;
                $endPt = $a->lerp($b, max(0.0, min(1.0, $localT)));
                if ($trimmed[count($trimmed) - 1]->distanceTo($endPt) > 1e-6) {
                    $trimmed[] = $endPt;
                }
                $dir = $endPt->vectorTo($tip)->normalise();
                if ($dir->length() < 1e-6) {
                    $dir = $a->vectorTo($b)->normalise();
                }

                return [
                    'spine' => $trimmed,
                    'tip' => $tip,
                    'direction' => $dir,
                ];
            }

            $trimmed[] = $b;
            $walked += $segLen;
        }

        $dir = $points[$n - 2]->vectorTo($tip)->normalise();

        return [
            'spine' => $trimmed,
            'tip' => $tip,
            'direction' => $dir,
        ];
    }
}
