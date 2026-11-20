<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Geometry;

use BadRush\Weathermap\Document\LinkDef;

/**
 * Generates closed Weathermap directional arrow polygons along a spine.
 * Uses true miter joins (constant link thickness across 90-degree & arbitrary bends)
 * with bevel fallback on acute hairpins so SVG and PHP GD stay pixel-identical.
 */
final class ArrowBuilder
{
    private const float MITER_LIMIT = 2.5;

    /**
     * Build a closed polygon for a half-link arrow along $spine (pointing toward the final point).
     *
     * @param  list<Point>  $spine
     * @return list<Point>
     */
    public static function buildPolygon(array $spine, float $width, string $arrowStyle = LinkDef::ARROW_CLASSIC): array
    {
        $spine = self::deduplicatePoints($spine);
        $n = count($spine);
        if ($n < 2) {
            return [];
        }

        $totalLen = CatmullRom::polylineLength($spine);
        if ($totalLen < 2.0) {
            return [];
        }

        $halfBody = max(1.0, $width / 2.0);
        if ($arrowStyle === LinkDef::ARROW_COMPACT) {
            $headHalfWidth = $width * 0.9;
            $headLength = $width * 1.8;
        } else {
            // Classic Weathermap proportions: 2x width shoulder, 4x width head length (scaled cleanly for halfBody)
            $headHalfWidth = $width * 1.25;
            $headLength = $width * 2.5;
        }

        // Prevent arrow head from exceeding 65% of the half-link length on short links
        if ($headLength > $totalLen * 0.65) {
            $scale = ($totalLen * 0.65) / $headLength;
            $headLength *= $scale;
            $headHalfWidth = max($halfBody * 1.2, $headHalfWidth * $scale);
        }

        // Corner-safe clamping: avoid cutting diagonally across a sharp bend right before the tip
        if ($n >= 3) {
            $prevDir = $spine[$n - 3]->vectorTo($spine[$n - 2])->normalise();
            $lastDir = $spine[$n - 2]->vectorTo($spine[$n - 1])->normalise();
            $lastSegLen = $spine[$n - 2]->distanceTo($spine[$n - 1]);
            $isSharpCorner = $prevDir->dot($lastDir) < 0.906; // > ~25 deg turn

            if ($isSharpCorner && $lastSegLen < $headLength && $lastSegLen >= $width * 1.1) {
                $clampedLen = $lastSegLen * 0.92;
                $ratio = $clampedLen / $headLength;
                $headLength = $clampedLen;
                $headHalfWidth = max($halfBody * 1.25, $headHalfWidth * max(0.75, $ratio));
            }
        }

        $trimmed = CatmullRom::trimEnd($spine, $headLength);
        $body = self::deduplicatePoints($trimmed['spine']);
        $tip = $trimmed['tip'];
        $tipDir = $trimmed['direction'];

        $bodyCount = count($body);
        if ($bodyCount === 0) {
            return [];
        }

        $leftEdge = [];
        $rightEdge = [];

        for ($i = 0; $i < $bodyCount; $i++) {
            self::appendCornerOffsets($body, $i, $halfBody, $tipDir, $leftEdge, $rightEdge);
        }

        $shoulderCenter = $body[$bodyCount - 1];
        $headNormal = $tipDir->normal();
        $leftShoulder = $shoulderCenter->add($headNormal->scale($headHalfWidth));
        $rightShoulder = $shoulderCenter->add($headNormal->scale(-$headHalfWidth));

        // Walk clockwise: start -> left body -> left shoulder -> tip -> right shoulder -> reversed right body
        $polygon = $leftEdge;
        $polygon[] = $leftShoulder;
        $polygon[] = $tip;
        $polygon[] = $rightShoulder;

        for ($i = count($rightEdge) - 1; $i >= 0; $i--) {
            $polygon[] = $rightEdge[$i];
        }

        return $polygon;
    }

    /**
     * Compute constant-width miter join offsets at vertex $index, with bevel fallback on acute hairpins.
     *
     * @param  list<Point>  $body
     * @param  list<Point>  $leftEdge
     * @param  list<Point>  $rightEdge
     */
    private static function appendCornerOffsets(
        array $body,
        int $index,
        float $halfBody,
        Vector $fallbackDir,
        array &$leftEdge,
        array &$rightEdge,
    ): void {
        $n = count($body);
        $curr = $body[$index];

        if ($n < 2) {
            $norm = $fallbackDir->normal();
            $leftEdge[] = $curr->add($norm->scale($halfBody));
            $rightEdge[] = $curr->add($norm->scale(-$halfBody));

            return;
        }

        if ($index === 0) {
            $v = $body[0]->vectorTo($body[1]);
            $norm = $v->length() > 1e-6 ? $v->normal() : $fallbackDir->normal();
            $leftEdge[] = $curr->add($norm->scale($halfBody));
            $rightEdge[] = $curr->add($norm->scale(-$halfBody));

            return;
        }

        if ($index === $n - 1) {
            $v = $body[$n - 2]->vectorTo($body[$n - 1]);
            $norm = $v->length() > 1e-6 ? $v->normal() : $fallbackDir->normal();
            $leftEdge[] = $curr->add($norm->scale($halfBody));
            $rightEdge[] = $curr->add($norm->scale(-$halfBody));

            return;
        }

        $prev = $body[$index - 1] ?? null;
        $next = $body[$index + 1] ?? null;
        if ($prev === null || $next === null) {
            $norm = $fallbackDir->normal();
            $leftEdge[] = $curr->add($norm->scale($halfBody));
            $rightEdge[] = $curr->add($norm->scale(-$halfBody));

            return;
        }

        $v1 = $prev->vectorTo($curr);
        $v2 = $curr->vectorTo($next);
        $len1 = $v1->length();
        $len2 = $v2->length();
        $u1 = $v1->normalise();
        $u2 = $v2->normalise();
        $n1 = $u1->normal();
        $n2 = $u2->normal();

        $sum = $n1->add($n2);
        $sumLen = $sum->length();

        // Exact 180-degree reversal: bevel both sides cleanly
        if ($sumLen < 1e-6) {
            $leftEdge[] = $curr->add($n1->scale($halfBody));
            $leftEdge[] = $curr->add($n2->scale($halfBody));
            $rightEdge[] = $curr->add($n1->scale(-$halfBody));
            $rightEdge[] = $curr->add($n2->scale(-$halfBody));

            return;
        }

        $miter = $sum->normalise();
        $denom = $miter->dot($n1);
        $turn = $u1->cross($u2);

        // Nearly collinear segments: single normal offset
        if (abs($turn) < 1e-5 && $u1->dot($u2) > 0.0) {
            $leftEdge[] = $curr->add($miter->scale($halfBody));
            $rightEdge[] = $curr->add($miter->scale(-$halfBody));

            return;
        }

        $minDenom = 1.0 / self::MITER_LIMIT;
        $miterLen = $denom > 1e-6 ? ($halfBody / $denom) : ($halfBody * self::MITER_LIMIT);
        $maxInner = max($halfBody, min($len1, $len2) * 0.95);
        $clampedInner = min($miterLen, $maxInner, $halfBody * self::MITER_LIMIT);

        if ($denom >= $minDenom) {
            // Standard miter join: crisp constant width across 90-degree & moderate corners
            if ($turn > 0.0) {
                // +n (leftEdge) is inner corner, -n (rightEdge) is outer corner
                $leftEdge[] = $curr->add($miter->scale($clampedInner));
                $rightEdge[] = $curr->add($miter->scale(-$miterLen));
            } else {
                // +n (leftEdge) is outer corner, -n (rightEdge) is inner corner
                $leftEdge[] = $curr->add($miter->scale($miterLen));
                $rightEdge[] = $curr->add($miter->scale(-$clampedInner));
            }

            return;
        }

        // Acute hairpin (< ~47 deg): bevel the outer corner to prevent spike overshoot
        if ($turn > 0.0) {
            $leftEdge[] = $curr->add($miter->scale($clampedInner));
            $rightEdge[] = $curr->add($n1->scale(-$halfBody));
            $rightEdge[] = $curr->add($n2->scale(-$halfBody));
        } else {
            $leftEdge[] = $curr->add($n1->scale($halfBody));
            $leftEdge[] = $curr->add($n2->scale($halfBody));
            $rightEdge[] = $curr->add($miter->scale(-$clampedInner));
        }
    }

    /**
     * @param  list<Point>  $points
     * @return list<Point>
     */
    private static function deduplicatePoints(array $points): array
    {
        $clean = [];
        foreach ($points as $pt) {
            $count = count($clean);
            if ($count === 0 || $clean[$count - 1]->distanceTo($pt) > 1e-4) {
                $clean[] = $pt;
            }
        }

        return $clean;
    }
}
