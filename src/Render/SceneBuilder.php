<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Render;

use BadRush\Weathermap\Data\BandwidthFormatter;
use BadRush\Weathermap\Data\TargetResolver;
use BadRush\Weathermap\Document\AnnotationDef;
use BadRush\Weathermap\Document\CapacityDef;
use BadRush\Weathermap\Document\LinkDef;
use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Document\NodeDef;
use BadRush\Weathermap\Geometry\ArrowBuilder;
use BadRush\Weathermap\Geometry\LabelPlacer;
use BadRush\Weathermap\Geometry\LinkPathBuilder;
use BadRush\Weathermap\Geometry\Point;
use BadRush\Weathermap\Geometry\ScaleResolver;
use BadRush\Weathermap\Support\LibreNmsBridge;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class SceneBuilder
{
    public function __construct(
        private readonly TargetResolver $resolver,
        private readonly ?string $timezone = null,
    ) {}

    public function build(MapDocument $doc, ?int $now = null, ?string $timezone = null): Scene
    {
        $now ??= time();
        $tz = self::resolveTimeZone($timezone ?? $this->timezone ?? LibreNmsBridge::timezone());
        $timestampText = 'Updated: '.(new DateTimeImmutable('@'.$now))->setTimezone($tz)->format('Y-m-d H:i:s T');

        // Index nodes by id
        $nodeMap = [];
        foreach ($doc->nodes as $node) {
            $nodeMap[$node->id] = $node;
        }

        // Count parallel links sharing an unordered node pair so LinkPathBuilder separates them automatically
        $pairGroups = [];
        foreach ($doc->links as $link) {
            $pairKey = self::pairKey($link->a->nodeId, $link->b->nodeId);
            $pairGroups[$pairKey][] = $link->id;
        }

        // Build scene links sorted by zOrder
        $sortedLinks = $doc->links;
        usort($sortedLinks, static fn (LinkDef $x, LinkDef $y): int => $x->zOrder <=> $y->zOrder);

        $sceneLinks = [];
        foreach ($sortedLinks as $link) {
            $nodeA = $nodeMap[$link->a->nodeId] ?? null;
            $nodeB = $nodeMap[$link->b->nodeId] ?? null;
            if ($nodeA === null || $nodeB === null) {
                continue;
            }

            $pairKey = self::pairKey($nodeA->id, $nodeB->id);
            $siblings = $pairGroups[$pairKey] ?? [$link->id];
            $parallelIndex = (int) array_search($link->id, $siblings, true);
            $parallelCount = count($siblings);

            $paths = LinkPathBuilder::build($link, $nodeA, $nodeB, $parallelIndex, $parallelCount);
            $outPoly = ArrowBuilder::buildPolygon($paths['outSpine'], $link->width, $link->arrowStyle);
            $inPoly = ArrowBuilder::buildPolygon($paths['inSpine'], $link->width, $link->arrowStyle);

            $reading = $this->resolver->resolve($link->target, $doc->settings, $now);

            $capIn = $link->capacity->source === CapacityDef::SOURCE_MANUAL
                ? $link->capacity->inBps
                : ($reading->capacityInBps ?? $link->capacity->inBps);
            $capOut = $link->capacity->source === CapacityDef::SOURCE_MANUAL
                ? $link->capacity->outBps
                : ($reading->capacityOutBps ?? $link->capacity->outBps);

            $outPct = ($reading->outBps !== null && $capOut > 0.0) ? ($reading->outBps / $capOut) * 100.0 : null;
            $inPct = ($reading->inBps !== null && $capIn > 0.0) ? ($reading->inBps / $capIn) * 100.0 : null;

            $scale = $doc->getScale($link->scaleName);
            $outColor = ScaleResolver::resolveColor($scale, $outPct, $reading->isDown, $reading->isStale);
            $inColor = ScaleResolver::resolveColor($scale, $inPct, $reading->isDown, $reading->isStale);

            $outLabelPt = LabelPlacer::onCenterline($paths['centerline'], $link->outLabelPos);
            $inLabelPt = LabelPlacer::onCenterline($paths['centerline'], $link->inLabelPos);

            $outLabelText = BandwidthFormatter::formatLinkLabel($link->bwLabel, $reading->outBps, $outPct, $reading->isDown, $reading->isStale);
            $inLabelText = BandwidthFormatter::formatLinkLabel($link->bwLabel, $reading->inBps, $inPct, $reading->isDown, $reading->isStale);

            $infoUrl = $link->infoUrl;
            if ($infoUrl === null && $reading->primaryPortId !== null) {
                $infoUrl = "/device/device={$reading->primaryDeviceId}/tab=port/port={$reading->primaryPortId}/";
            }

            $graphUrl = $reading->primaryPortId !== null
                ? "/graph.php?id={$reading->primaryPortId}&type=port_bits&legend=no&from=-86400&to=now"
                : null;

            $staticBadge = null;
            if ($link->staticLabel !== '' || $link->vendorIconAssetId !== null) {
                $badgeAnchor = LabelPlacer::onCenterline($paths['centerline'], $link->staticLabelPos);
                $badgePos = new Point(
                    $badgeAnchor->x + $link->staticLabelOffsetX,
                    $badgeAnchor->y + $link->staticLabelOffsetY,
                );
                $staticBadge = [
                    'text' => $link->staticLabel,
                    'pos' => $badgePos->toArray(),
                    'fontSize' => $link->staticLabelFontSize,
                    'bgColor' => $link->staticLabelBgColor,
                    'textColor' => $link->staticLabelTextColor,
                    'borderColor' => $link->outlineColor,
                    'iconAssetId' => $link->vendorIconAssetId,
                    'iconAssetUrl' => $link->vendorIconAssetId !== null ? "/weathermap/api/assets/{$link->vendorIconAssetId}" : null,
                    'iconSize' => $link->vendorIconSize,
                ];
            }

            $outCommentPos = $link->outComment !== ''
                ? (new Point($outLabelPt->x, $outLabelPt->y + $link->bwFontSize + 10.0))->toArray()
                : null;
            $inCommentPos = $link->inComment !== ''
                ? (new Point($inLabelPt->x, $inLabelPt->y + $link->bwFontSize + 10.0))->toArray()
                : null;

            $sceneLinks[] = [
                'id' => $link->id,
                'nodeA' => $nodeA->id,
                'nodeB' => $nodeB->id,
                'zOrder' => $link->zOrder,
                'width' => $link->width,
                'outlineColor' => $link->outlineColor,
                'centerline' => array_map(static fn (Point $p): array => $p->toArray(), $paths['centerline']),
                'outArrow' => [
                    'polygon' => array_map(static fn (Point $p): array => $p->toArray(), $outPoly),
                    'color' => $outColor,
                    'bps' => $reading->outBps,
                    'bpsFormatted' => $reading->outBps !== null ? BandwidthFormatter::formatBits($reading->outBps).'bps' : 'N/A',
                    'percent' => $outPct !== null ? round($outPct, 2) : null,
                    'capacityBps' => $capOut,
                    'capacityFormatted' => BandwidthFormatter::formatBits($capOut).'bps',
                    'label' => $outLabelText,
                    'labelPos' => $outLabelPt->toArray(),
                    'labelTextColor' => ScaleResolver::contrastText($outColor),
                ],
                'inArrow' => [
                    'polygon' => array_map(static fn (Point $p): array => $p->toArray(), $inPoly),
                    'color' => $inColor,
                    'bps' => $reading->inBps,
                    'bpsFormatted' => $reading->inBps !== null ? BandwidthFormatter::formatBits($reading->inBps).'bps' : 'N/A',
                    'percent' => $inPct !== null ? round($inPct, 2) : null,
                    'capacityBps' => $capIn,
                    'capacityFormatted' => BandwidthFormatter::formatBits($capIn).'bps',
                    'label' => $inLabelText,
                    'labelPos' => $inLabelPt->toArray(),
                    'labelTextColor' => ScaleResolver::contrastText($inColor),
                ],
                'bwFontSize' => $link->bwFontSize,
                'inComment' => $link->inComment,
                'outComment' => $link->outComment,
                'inCommentPos' => $inCommentPos,
                'outCommentPos' => $outCommentPos,
                'staticBadge' => $staticBadge,
                'isDown' => $reading->isDown,
                'isStale' => $reading->isStale,
                'dataTimestamp' => $reading->timestamp,
                'primaryPortId' => $reading->primaryPortId,
                'primaryDeviceId' => $reading->primaryDeviceId,
                'portLabel' => $reading->portLabel,
                'infoUrl' => $infoUrl,
                'graphUrl' => $graphUrl,
                'error' => $reading->error,
            ];
        }

        // Build scene nodes sorted by zOrder
        $sortedNodes = $doc->nodes;
        usort($sortedNodes, static fn (NodeDef $x, NodeDef $y): int => $x->zOrder <=> $y->zOrder);

        $sceneNodes = [];
        foreach ($sortedNodes as $node) {
            $bgColor = $node->bgColor;
            $value = null;
            $percent = null;
            $isDown = false;
            $isStale = false;

            if ($node->target !== null && ! $node->target->isEmpty()) {
                $reading = $this->resolver->resolve($node->target, $doc->settings, $now);
                $value = $reading->inBps;
                $isDown = $reading->isDown;
                $isStale = $reading->isStale;
                if ($value !== null && $node->maxValue > 0.0) {
                    $percent = ($value / $node->maxValue) * 100.0;
                }
                if ($node->scaleName !== null) {
                    $bgColor = ScaleResolver::resolveColor($doc->getScale($node->scaleName), $percent, $isDown, $isStale);
                }
            }

            $labelPos = LabelPlacer::forNode($node);
            $infoUrl = $node->infoUrl;
            if ($infoUrl === null && $node->deviceId !== null) {
                $infoUrl = "/device/device={$node->deviceId}/";
            }

            $sceneNodes[] = [
                'id' => $node->id,
                'label' => $node->label,
                'x' => round($node->x, 3),
                'y' => round($node->y, 3),
                'iconType' => $node->iconType,
                'iconAssetId' => $node->iconAssetId,
                'iconAssetUrl' => $node->iconAssetId !== null ? "/weathermap/api/assets/{$node->iconAssetId}" : null,
                'deviceOsIcon' => $node->deviceOsIcon,
                'iconWidth' => $node->iconWidth,
                'iconHeight' => $node->iconHeight,
                'deviceId' => $node->deviceId,
                'labelPos' => $labelPos->toArray(),
                'labelFontSize' => $node->labelFontSize,
                'labelColor' => $node->labelColor,
                'bgColor' => $bgColor,
                'outlineColor' => $node->outlineColor,
                'zOrder' => $node->zOrder,
                'infoUrl' => $infoUrl,
                'value' => $value,
                'percent' => $percent !== null ? round($percent, 2) : null,
                'isDown' => $isDown,
                'isStale' => $isStale,
            ];
        }

        // Build free-floating static labels (annotations) sorted by zOrder
        $sortedAnnotations = $doc->annotations;
        usort($sortedAnnotations, static fn (AnnotationDef $x, AnnotationDef $y): int => $x->zOrder <=> $y->zOrder);

        $sceneAnnotations = [];
        foreach ($sortedAnnotations as $ann) {
            if ($ann->text === '' && $ann->iconAssetId === null) {
                continue;
            }

            $sceneAnnotations[] = [
                'id' => $ann->id,
                'text' => $ann->text,
                'lines' => $ann->text !== '' ? explode("\n", $ann->text) : [],
                'x' => round($ann->x, 3),
                'y' => round($ann->y, 3),
                'style' => $ann->style,
                'align' => $ann->align,
                'fontSize' => $ann->fontSize,
                'textColor' => $ann->textColor,
                'bgColor' => $ann->bgColor,
                'borderColor' => $ann->borderColor,
                'iconAssetId' => $ann->iconAssetId,
                'iconAssetUrl' => $ann->iconAssetId !== null ? "/weathermap/api/assets/{$ann->iconAssetId}" : null,
                'iconPosition' => $ann->iconPosition,
                'iconSize' => $ann->iconSize,
                'infoUrl' => $ann->infoUrl,
                'zOrder' => $ann->zOrder,
            ];
        }

        // Build legend
        $legendData = null;
        if ($doc->legend->visible) {
            $scale = $doc->getScale($doc->legend->scaleName);
            $stops = [];
            foreach ($scale->stops as $stop) {
                $stops[] = [
                    'min' => $stop->min,
                    'max' => $stop->max,
                    'color1' => ScaleResolver::rgbToHex($stop->rgb1),
                    'color2' => $stop->rgb2 !== null ? ScaleResolver::rgbToHex($stop->rgb2) : null,
                    'label' => $stop->label ?? self::defaultStopLabel($stop->min, $stop->max),
                ];
            }

            $legendData = [
                'x' => $doc->legend->x,
                'y' => $doc->legend->y,
                'title' => $doc->legend->title,
                'style' => $doc->legend->style,
                'bgColor' => $doc->legend->bgColor,
                'outlineColor' => $doc->legend->outlineColor,
                'textColor' => $doc->legend->textColor,
                'fontSize' => $doc->legend->fontSize,
                'stops' => $stops,
            ];
        }

        $canvasData = $doc->canvas->toArray();
        $canvasData['backgroundAssetUrl'] = $doc->canvas->backgroundAssetId !== null
            ? "/weathermap/api/assets/{$doc->canvas->backgroundAssetId}"
            : null;

        return new Scene(
            generatedAt: $now,
            timestampText: $timestampText,
            canvas: $canvasData,
            nodes: $sceneNodes,
            links: $sceneLinks,
            legend: $legendData,
            refreshSeconds: $doc->settings->refreshSeconds,
            annotations: $sceneAnnotations,
        );
    }

    private static function resolveTimeZone(string $name): DateTimeZone
    {
        $trimmed = trim($name);
        if ($trimmed !== '') {
            try {
                return new DateTimeZone($trimmed);
            } catch (Throwable) {
                // Fall back to default WIB timezone below
            }
        }

        return new DateTimeZone(LibreNmsBridge::DEFAULT_TIMEZONE);
    }

    private static function pairKey(string $a, string $b): string
    {
        return $a <= $b ? "{$a}::{$b}" : "{$b}::{$a}";
    }

    private static function defaultStopLabel(float $min, float $max): string
    {
        if ($min === $max) {
            return (string) $min.'%';
        }

        return sprintf('%g - %g%%', $min, $max);
    }
}
