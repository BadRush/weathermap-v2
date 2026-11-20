<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Import;

use BadRush\Weathermap\Data\BandwidthFormatter;
use BadRush\Weathermap\Document\CanvasConfig;
use BadRush\Weathermap\Document\CapacityDef;
use BadRush\Weathermap\Document\DocumentValidator;
use BadRush\Weathermap\Document\LegendDef;
use BadRush\Weathermap\Document\LinkDef;
use BadRush\Weathermap\Document\LinkEndpoint;
use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Document\MapSettings;
use BadRush\Weathermap\Document\NodeDef;
use BadRush\Weathermap\Document\ScaleDef;
use BadRush\Weathermap\Document\ScaleStop;
use BadRush\Weathermap\Document\TargetExpr;
use BadRush\Weathermap\Document\TargetTerm;
use BadRush\Weathermap\Document\ViaPoint;
use BadRush\Weathermap\Geometry\ScaleResolver;

/**
 * Parses classic PHP Network Weathermap 0.97/0.98 .conf files and imports them
 * into the modern versioned MapDocument format alongside a transparent ImportReport.
 */
final class ConfImporter
{
    /**
     * @param  (callable(int): bool)|null  $portExistsChecker  Optional callback to validate extracted port IDs
     * @return array{document: MapDocument, report: ImportReport}
     */
    public function import(string $confContent, ?callable $portExistsChecker = null): array
    {
        $lines = preg_split('/\r?\n/', $confContent) ?: [];

        $global = [
            'width' => 1000,
            'height' => 640,
            'bgColor' => '#0f172a',
            'title' => 'Imported Weathermap',
            'titleX' => 20,
            'titleY' => 28,
            'timestampX' => 20,
            'timestampY' => 50,
            'keyPos' => null,
            'keyStyle' => LegendDef::STYLE_CLASSIC,
            'keyTitle' => 'Traffic Load',
            'keyScale' => 'DEFAULT',
            'set' => [],
        ];

        /** @var array<string, list<ScaleStop>> $scalesRaw */
        $scalesRaw = [];
        /** @var array<string, array<string, mixed>> $nodesRaw */
        $nodesRaw = [];
        /** @var array<string, array<string, mixed>> $linksRaw */
        $linksRaw = [];
        /** @var array<string, mixed> $nodeDefault */
        $nodeDefault = [];
        /** @var array<string, mixed> $linkDefault */
        $linkDefault = [];

        $convertedTargets = [];
        $warnings = [];
        $skippedDirectives = [];

        $section = 'GLOBAL';
        $currentId = '';

        foreach ($lines as $lineNo => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Strip inline comments not inside quotes
            $line = self::stripComment($line);
            if ($line === '') {
                continue;
            }

            $tokens = self::tokenize($line);
            if ($tokens === []) {
                continue;
            }

            $keyword = strtoupper($tokens[0]);

            if ($keyword === 'NODE' && isset($tokens[1])) {
                $section = 'NODE';
                $currentId = $tokens[1];
                if ($currentId !== 'DEFAULT' && ! isset($nodesRaw[$currentId])) {
                    $nodesRaw[$currentId] = $nodeDefault;
                    $nodesRaw[$currentId]['id'] = $currentId;
                }

                continue;
            }

            if ($keyword === 'LINK' && isset($tokens[1])) {
                $section = 'LINK';
                $currentId = $tokens[1];
                if ($currentId !== 'DEFAULT' && ! isset($linksRaw[$currentId])) {
                    $linksRaw[$currentId] = $linkDefault;
                    $linksRaw[$currentId]['id'] = $currentId;
                    $linksRaw[$currentId]['via'] = [];
                }

                continue;
            }

            if ($section === 'GLOBAL') {
                $this->parseGlobalDirective($keyword, $tokens, $global, $scalesRaw, $warnings, $skippedDirectives, $lineNo + 1);
            } elseif ($section === 'NODE') {
                if ($currentId === 'DEFAULT') {
                    $this->parseNodeDirective($keyword, $tokens, $nodeDefault, $warnings, $skippedDirectives, $lineNo + 1);
                } else {
                    $this->parseNodeDirective($keyword, $tokens, $nodesRaw[$currentId], $warnings, $skippedDirectives, $lineNo + 1);
                }
            } elseif ($section === 'LINK') {
                if ($currentId === 'DEFAULT') {
                    $this->parseLinkDirective($keyword, $tokens, $linkDefault, $warnings, $skippedDirectives, $lineNo + 1);
                } else {
                    $this->parseLinkDirective($keyword, $tokens, $linksRaw[$currentId], $warnings, $skippedDirectives, $lineNo + 1);
                }
            }
        }

        // Resolve relative NODE POSITION references (e.g. POSITION othernode +50 -20)
        $resolvedCoords = [];
        foreach ($nodesRaw as $id => $n) {
            if (isset($n['x'], $n['y'])) {
                $resolvedCoords[$id] = [(float) $n['x'], (float) $n['y']];
            }
        }
        // Two-pass resolution for chained relative positions
        for ($pass = 0; $pass < 3; $pass++) {
            foreach ($nodesRaw as $id => $n) {
                if (isset($n['relNode']) && is_string($n['relNode']) && isset($resolvedCoords[$n['relNode']])) {
                    [$bx, $by] = $resolvedCoords[$n['relNode']];
                    $resolvedCoords[$id] = [$bx + (float) ($n['relDx'] ?? 0), $by + (float) ($n['relDy'] ?? 0)];
                }
            }
        }

        // Build NodeDef list
        $nodeDefs = [];
        foreach ($nodesRaw as $id => $n) {
            [$x, $y] = $resolvedCoords[$id] ?? [100.0, 100.0];
            $targetExpr = null;
            if (is_string($n['targetRaw'] ?? null) && $n['targetRaw'] !== '') {
                $targetExpr = $this->parseTargetString($n['targetRaw'], "NODE {$id}", $convertedTargets, $warnings, $portExistsChecker);
            }

            $infoUrl = is_string($n['infoUrl'] ?? null) && DocumentValidator::isSafeUrl($n['infoUrl']) ? $n['infoUrl'] : null;

            $nodeDefs[] = new NodeDef(
                id: $id,
                label: is_string($n['label'] ?? null) ? $n['label'] : $id,
                x: $x,
                y: $y,
                iconType: is_string($n['iconType'] ?? null) ? $n['iconType'] : NodeDef::ICON_SHAPE_BOX,
                iconWidth: (int) ($n['iconWidth'] ?? 64),
                iconHeight: (int) ($n['iconHeight'] ?? 36),
                labelOffset: is_string($n['labelOffset'] ?? null) ? $n['labelOffset'] : 'C',
                zOrder: (int) ($n['zOrder'] ?? 600),
                infoUrl: $infoUrl,
                scaleName: is_string($n['scaleName'] ?? null) ? $n['scaleName'] : null,
                target: $targetExpr,
                maxValue: is_numeric($n['maxValue'] ?? null) ? (float) $n['maxValue'] : 100.0,
            );
        }

        // Build LinkDef list
        $linkDefs = [];
        foreach ($linksRaw as $id => $l) {
            if (! isset($l['nodeA'], $l['nodeB'])) {
                $warnings[] = "LINK {$id}: skipped because NODES directive is missing.";

                continue;
            }
            $nodeAId = (string) $l['nodeA'];
            $nodeBId = (string) $l['nodeB'];
            if (! isset($nodesRaw[$nodeAId]) || ! isset($nodesRaw[$nodeBId])) {
                $warnings[] = "LINK {$id}: skipped because endpoints '{$nodeAId}'/'{$nodeBId}' were not found.";

                continue;
            }

            $targetExpr = new TargetExpr;
            if (is_string($l['targetRaw'] ?? null) && $l['targetRaw'] !== '') {
                $targetExpr = $this->parseTargetString($l['targetRaw'], "LINK {$id}", $convertedTargets, $warnings, $portExistsChecker);
            }

            $via = [];
            if (is_array($l['via'] ?? null)) {
                foreach ($l['via'] as $pt) {
                    $via[] = new ViaPoint((float) $pt[0], (float) $pt[1]);
                }
            }

            $capacity = new CapacityDef;
            if (isset($l['bwIn']) && is_numeric($l['bwIn']) && (float) $l['bwIn'] > 0.0) {
                $bwIn = (float) $l['bwIn'];
                $bwOut = isset($l['bwOut']) && is_numeric($l['bwOut']) ? (float) $l['bwOut'] : $bwIn;
                $capacity = new CapacityDef(CapacityDef::SOURCE_MANUAL, $bwIn, $bwOut);
            }

            $infoUrl = is_string($l['infoUrl'] ?? null) && DocumentValidator::isSafeUrl($l['infoUrl']) ? $l['infoUrl'] : null;

            $linkDefs[] = new LinkDef(
                id: $id,
                a: new LinkEndpoint($nodeAId, is_string($l['offsetA'] ?? null) ? $l['offsetA'] : 'C'),
                b: new LinkEndpoint($nodeBId, is_string($l['offsetB'] ?? null) ? $l['offsetB'] : 'C'),
                via: $via,
                viaStyle: is_string($l['viaStyle'] ?? null) ? $l['viaStyle'] : LinkDef::VIA_CURVED,
                width: is_numeric($l['width'] ?? null) ? max(2.0, min(40.0, (float) $l['width'])) : 7.0,
                arrowStyle: is_string($l['arrowStyle'] ?? null) ? $l['arrowStyle'] : LinkDef::ARROW_CLASSIC,
                splitPos: is_numeric($l['splitPos'] ?? null) ? max(0.1, min(0.9, (float) $l['splitPos'])) : 0.5,
                bwLabel: is_string($l['bwLabel'] ?? null) ? $l['bwLabel'] : LinkDef::BWLABEL_PERCENT,
                inLabelPos: is_numeric($l['inLabelPos'] ?? null) ? (float) $l['inLabelPos'] : 0.75,
                outLabelPos: is_numeric($l['outLabelPos'] ?? null) ? (float) $l['outLabelPos'] : 0.25,
                scaleName: is_string($l['scaleName'] ?? null) ? $l['scaleName'] : 'DEFAULT',
                capacity: $capacity,
                target: $targetExpr,
                infoUrl: $infoUrl,
                inComment: is_string($l['inComment'] ?? null) ? $l['inComment'] : '',
                outComment: is_string($l['outComment'] ?? null) ? $l['outComment'] : '',
                zOrder: (int) ($l['zOrder'] ?? 300),
            );
        }

        // Build ScaleDef list
        $scaleDefs = [];
        foreach ($scalesRaw as $name => $stops) {
            $scaleDefs[] = new ScaleDef($name, $stops);
        }
        if ($scaleDefs === []) {
            $scaleDefs[] = ScaleDef::classicDefault();
        }

        $keyPos = is_array($global['keyPos']) ? $global['keyPos'] : [20, max(60, (int) $global['height'] - 190)];
        $rrdPeriod = isset($global['set']['rrd_period']) && is_numeric($global['set']['rrd_period'])
            ? (int) $global['set']['rrd_period']
            : 900;

        $document = new MapDocument(
            schemaVersion: MapDocument::CURRENT_SCHEMA_VERSION,
            canvas: new CanvasConfig(
                width: max(100, min(8000, (int) $global['width'])),
                height: max(100, min(8000, (int) $global['height'])),
                bgColor: (string) $global['bgColor'],
                title: (string) $global['title'],
                titleX: (int) $global['titleX'],
                titleY: (int) $global['titleY'],
                timestampX: (int) $global['timestampX'],
                timestampY: (int) $global['timestampY'],
            ),
            nodes: $nodeDefs,
            links: $linkDefs,
            scales: $scaleDefs,
            legend: new LegendDef(
                visible: true,
                scaleName: (string) $global['keyScale'],
                x: (int) $keyPos[0],
                y: (int) $keyPos[1],
                title: (string) $global['keyTitle'],
                style: (string) $global['keyStyle'],
            ),
            settings: new MapSettings(rrdPeriodSeconds: $rrdPeriod),
        );

        $report = new ImportReport(
            nodesImported: count($nodeDefs),
            linksImported: count($linkDefs),
            scalesImported: count($scalesRaw),
            convertedTargets: $convertedTargets,
            warnings: $warnings,
            skippedDirectives: array_values(array_unique($skippedDirectives)),
        );

        return ['document' => $document, 'report' => $report];
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $global
     * @param  array<string, list<ScaleStop>>  $scalesRaw
     * @param  list<string>  $warnings
     * @param  list<string>  $skipped
     */
    private function parseGlobalDirective(
        string $keyword,
        array $tokens,
        array &$global,
        array &$scalesRaw,
        array &$warnings,
        array &$skipped,
        int $lineNo,
    ): void {
        switch ($keyword) {
            case 'WIDTH':
                if (isset($tokens[1]) && is_numeric($tokens[1])) {
                    $global['width'] = (int) $tokens[1];
                }
                break;
            case 'HEIGHT':
                if (isset($tokens[1]) && is_numeric($tokens[1])) {
                    $global['height'] = (int) $tokens[1];
                }
                break;
            case 'BGCOLOR':
                if (isset($tokens[1], $tokens[2], $tokens[3])) {
                    $global['bgColor'] = ScaleResolver::rgbToHex([(int) $tokens[1], (int) $tokens[2], (int) $tokens[3]]);
                }
                break;
            case 'TITLE':
                $global['title'] = implode(' ', array_slice($tokens, 1));
                break;
            case 'TITLEPOS':
                if (isset($tokens[1], $tokens[2])) {
                    $global['titleX'] = (int) $tokens[1];
                    $global['titleY'] = (int) $tokens[2];
                    if (count($tokens) > 3) {
                        $global['title'] = implode(' ', array_slice($tokens, 3));
                    }
                }
                break;
            case 'TIMEPOS':
                if (isset($tokens[1], $tokens[2])) {
                    $global['timestampX'] = (int) $tokens[1];
                    $global['timestampY'] = (int) $tokens[2];
                }
                break;
            case 'KEYPOS':
                // KEYPOS [scalename] x y [title]
                if (isset($tokens[1], $tokens[2]) && is_numeric($tokens[1]) && is_numeric($tokens[2])) {
                    $global['keyPos'] = [(int) $tokens[1], (int) $tokens[2]];
                    if (count($tokens) > 3) {
                        $global['keyTitle'] = implode(' ', array_slice($tokens, 3));
                    }
                } elseif (isset($tokens[1], $tokens[2], $tokens[3]) && is_numeric($tokens[2]) && is_numeric($tokens[3])) {
                    $global['keyScale'] = $tokens[1];
                    $global['keyPos'] = [(int) $tokens[2], (int) $tokens[3]];
                    if (count($tokens) > 4) {
                        $global['keyTitle'] = implode(' ', array_slice($tokens, 4));
                    }
                }
                break;
            case 'KEYSTYLE':
                $styleRaw = strtolower($tokens[2] ?? $tokens[1] ?? 'classic');
                if (in_array($styleRaw, [LegendDef::STYLE_CLASSIC, LegendDef::STYLE_HORIZONTAL, LegendDef::STYLE_VERTICAL], true)) {
                    $global['keyStyle'] = $styleRaw;
                }
                break;
            case 'SCALE':
                $this->parseScaleDirective($tokens, $scalesRaw);
                break;
            case 'SET':
                if (isset($tokens[1], $tokens[2])) {
                    $global['set'][$tokens[1]] = implode(' ', array_slice($tokens, 2));
                }
                break;
            case 'BACKGROUND':
                $warnings[] = "Line {$lineNo}: BACKGROUND '{$tokens[1]}' was not imported automatically; upload the image in the Editor.";
                break;
            case 'HTMLSTYLE':
            case 'HTMLOUTPUTFILE':
            case 'IMAGEOUTPUTFILE':
            case 'FONTDEFINE':
            case 'HTMLSTYLESHEET':
            case 'IMAGEURI':
                $skipped[] = $keyword;
                break;
            default:
                $skipped[] = $keyword;
                break;
        }
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, list<ScaleStop>>  $scalesRaw
     */
    private function parseScaleDirective(array $tokens, array &$scalesRaw): void
    {
        // SCALE [name] min max r1 g1 b1 [r2 g2 b2]
        $idx = 1;
        $scaleName = 'DEFAULT';
        if (isset($tokens[1]) && ! is_numeric($tokens[1])) {
            $scaleName = $tokens[1];
            $idx = 2;
        }

        if (! isset($tokens[$idx], $tokens[$idx + 1], $tokens[$idx + 2], $tokens[$idx + 3], $tokens[$idx + 4])) {
            return;
        }

        $min = (float) $tokens[$idx];
        $max = (float) $tokens[$idx + 1];
        $rgb1 = [(int) $tokens[$idx + 2], (int) $tokens[$idx + 3], (int) $tokens[$idx + 4]];
        $rgb2 = null;

        if (isset($tokens[$idx + 5], $tokens[$idx + 6], $tokens[$idx + 7])
            && is_numeric($tokens[$idx + 5]) && is_numeric($tokens[$idx + 6]) && is_numeric($tokens[$idx + 7])) {
            $rgb2 = [(int) $tokens[$idx + 5], (int) $tokens[$idx + 6], (int) $tokens[$idx + 7]];
        }

        $scalesRaw[$scaleName][] = new ScaleStop($min, $max, $rgb1, $rgb2);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $node
     * @param  list<string>  $warnings
     * @param  list<string>  $skipped
     */
    private function parseNodeDirective(
        string $keyword,
        array $tokens,
        array &$node,
        array &$warnings,
        array &$skipped,
        int $lineNo,
    ): void {
        switch ($keyword) {
            case 'POSITION':
                if (isset($tokens[1], $tokens[2]) && is_numeric($tokens[1]) && is_numeric($tokens[2])) {
                    $node['x'] = (float) $tokens[1];
                    $node['y'] = (float) $tokens[2];
                } elseif (isset($tokens[1], $tokens[2], $tokens[3])) {
                    $node['relNode'] = $tokens[1];
                    $node['relDx'] = (float) $tokens[2];
                    $node['relDy'] = (float) $tokens[3];
                }
                break;
            case 'LABEL':
                $node['label'] = implode(' ', array_slice($tokens, 1));
                break;
            case 'LABELOFFSET':
                if (isset($tokens[1])) {
                    $node['labelOffset'] = isset($tokens[2]) ? "{$tokens[1]}:{$tokens[2]}" : $tokens[1];
                }
                break;
            case 'ICON':
                if (isset($tokens[1], $tokens[2], $tokens[3]) && is_numeric($tokens[1]) && is_numeric($tokens[2])) {
                    $node['iconWidth'] = (int) $tokens[1];
                    $node['iconHeight'] = (int) $tokens[2];
                    $shape = strtolower($tokens[3]);
                } else {
                    $shape = strtolower($tokens[1] ?? '');
                }
                if ($shape === 'round' || $shape === 'rbox') {
                    $node['iconType'] = NodeDef::ICON_SHAPE_ROUND;
                } elseif ($shape === 'box') {
                    $node['iconType'] = NodeDef::ICON_SHAPE_BOX;
                } elseif ($shape !== '' && $shape !== 'none') {
                    $warnings[] = "Line {$lineNo}: NODE ICON '{$shape}' converted to box shape; upload custom icons via Editor.";
                }
                break;
            case 'INFOURL':
                $node['infoUrl'] = $tokens[1] ?? null;
                break;
            case 'TARGET':
                $node['targetRaw'] = implode(' ', array_slice($tokens, 1));
                break;
            case 'USESCALE':
                $node['scaleName'] = $tokens[1] ?? 'DEFAULT';
                break;
            case 'MAXVALUE':
                if (isset($tokens[1]) && is_numeric($tokens[1])) {
                    $node['maxValue'] = (float) $tokens[1];
                }
                break;
            case 'ZORDER':
                if (isset($tokens[1]) && is_numeric($tokens[1])) {
                    $node['zOrder'] = (int) $tokens[1];
                }
                break;
            case 'OVERLIBGRAPH':
            case 'OVERLIBCAPTION':
            case 'OVERLIBWIDTH':
            case 'OVERLIBHEIGHT':
                $skipped[] = $keyword;
                break;
            default:
                $skipped[] = $keyword;
                break;
        }
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $link
     * @param  list<string>  $warnings
     * @param  list<string>  $skipped
     */
    private function parseLinkDirective(
        string $keyword,
        array $tokens,
        array &$link,
        array &$warnings,
        array &$skipped,
        int $lineNo,
    ): void {
        switch ($keyword) {
            case 'NODES':
                if (isset($tokens[1], $tokens[2])) {
                    [$link['nodeA'], $link['offsetA']] = self::splitNodeEndpoint($tokens[1]);
                    [$link['nodeB'], $link['offsetB']] = self::splitNodeEndpoint($tokens[2]);
                }
                break;
            case 'VIA':
                if (isset($tokens[1], $tokens[2]) && is_numeric($tokens[1]) && is_numeric($tokens[2])) {
                    $link['via'][] = [(float) $tokens[1], (float) $tokens[2]];
                }
                break;
            case 'VIASTYLE':
                $v = strtolower($tokens[1] ?? 'curved');
                $link['viaStyle'] = $v === 'angled' ? LinkDef::VIA_ANGLED : LinkDef::VIA_CURVED;
                break;
            case 'WIDTH':
                if (isset($tokens[1]) && is_numeric($tokens[1])) {
                    $link['width'] = (float) $tokens[1];
                }
                break;
            case 'BANDWIDTH':
                if (isset($tokens[1])) {
                    $bwIn = BandwidthFormatter::parseBandwidth($tokens[1]);
                    $bwOut = isset($tokens[2]) ? BandwidthFormatter::parseBandwidth($tokens[2]) : $bwIn;
                    if ($bwIn !== null) {
                        $link['bwIn'] = $bwIn;
                        $link['bwOut'] = $bwOut ?? $bwIn;
                    } else {
                        $warnings[] = "Line {$lineNo}: could not parse BANDWIDTH '{$tokens[1]}'.";
                    }
                }
                break;
            case 'ARROWSTYLE':
                $a = strtolower($tokens[1] ?? 'classic');
                $link['arrowStyle'] = $a === 'compact' ? LinkDef::ARROW_COMPACT : LinkDef::ARROW_CLASSIC;
                break;
            case 'SPLITPOS':
                if (isset($tokens[1]) && is_numeric($tokens[1])) {
                    $link['splitPos'] = ((float) $tokens[1]) / 100.0;
                }
                break;
            case 'BWLABEL':
                $mode = strtolower($tokens[1] ?? 'percent');
                $link['bwLabel'] = match ($mode) {
                    'bits', 'unformatted' => LinkDef::BWLABEL_BITS,
                    'none' => LinkDef::BWLABEL_NONE,
                    default => LinkDef::BWLABEL_PERCENT,
                };
                break;
            case 'BWLABELPOS':
                if (isset($tokens[1], $tokens[2]) && is_numeric($tokens[1]) && is_numeric($tokens[2])) {
                    $link['inLabelPos'] = ((float) $tokens[1]) / 100.0;
                    $link['outLabelPos'] = ((float) $tokens[2]) / 100.0;
                }
                break;
            case 'TARGET':
                $link['targetRaw'] = implode(' ', array_slice($tokens, 1));
                break;
            case 'INFOURL':
                $link['infoUrl'] = $tokens[1] ?? null;
                break;
            case 'INCOMMENT':
                $link['inComment'] = implode(' ', array_slice($tokens, 1));
                break;
            case 'OUTCOMMENT':
                $link['outComment'] = implode(' ', array_slice($tokens, 1));
                break;
            case 'USESCALE':
                $link['scaleName'] = $tokens[1] ?? 'DEFAULT';
                break;
            case 'ZORDER':
                if (isset($tokens[1]) && is_numeric($tokens[1])) {
                    $link['zOrder'] = (int) $tokens[1];
                }
                break;
            case 'OVERLIBGRAPH':
            case 'OVERLIBCAPTION':
            case 'OVERLIBWIDTH':
            case 'OVERLIBHEIGHT':
                $skipped[] = $keyword;
                break;
            default:
                $skipped[] = $keyword;
                break;
        }
    }

    /**
     * @param  list<string>  $convertedTargets
     * @param  list<string>  $warnings
     * @param  (callable(int): bool)|null  $portExistsChecker
     */
    private function parseTargetString(
        string $raw,
        string $context,
        array &$convertedTargets,
        array &$warnings,
        ?callable $portExistsChecker,
    ): TargetExpr {
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        $terms = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $negate = false;
            $factor = 1.0;
            $expr = $part;

            if (str_starts_with($expr, '-')) {
                $negate = true;
                $expr = substr($expr, 1);
            }

            if (preg_match('/^(\d+(?:\.\d+)?)\*(.+)$/', $expr, $m) === 1) {
                $factor = (float) $m[1];
                $expr = $m[2];
            }

            // Explicit unsupported / unsafe legacy datasources
            if (str_starts_with($expr, '!')
                || preg_match('/^(snmp|snmp2c|snmpv3|fping|mrtg|tabfile|cactihost|cactithold|cactimonitor|dbsample):/i', $expr) === 1
            ) {
                $warnings[] = "{$context}: skipped unsupported/unsafe TARGET '{$part}'.";

                continue;
            }

            // static:in:out
            if (preg_match('/^static:([+-]?\d+(?:\.\d+)?):([+-]?\d+(?:\.\d+)?)$/i', $expr, $m) === 1) {
                $terms[] = new TargetTerm(
                    source: TargetTerm::SOURCE_STATIC,
                    staticIn: (float) $m[1],
                    staticOut: (float) $m[2],
                    factor: $factor,
                    negate: $negate,
                );
                $convertedTargets[] = "{$context}: static:{$m[1]}:{$m[2]} -> static";

                continue;
            }

            // Strip optional gauge: or rrd: prefix
            $isGauge = false;
            if (str_starts_with(strtolower($expr), 'gauge:')) {
                $isGauge = true;
                $expr = substr($expr, 6);
            } elseif (str_starts_with(strtolower($expr), 'rrd:')) {
                $expr = substr($expr, 4);
            }

            // LibreNMS port RRD pattern: [path/]port-id<N>.rrd[:INOCTETS:OUTOCTETS]
            if (preg_match('#(?:^|/)port-id(\d+)\.rrd(?::([^:]+):([^:]+))?$#i', $expr, $m) === 1) {
                $portId = (int) $m[1];
                $dsIn = strtoupper($m[2] ?? 'INOCTETS');
                $dsOut = strtoupper($m[3] ?? 'OUTOCTETS');

                $portValid = $portExistsChecker === null || $portExistsChecker($portId);
                if ($portValid && $dsIn === 'INOCTETS' && $dsOut === 'OUTOCTETS') {
                    $terms[] = new TargetTerm(
                        source: TargetTerm::SOURCE_PORT,
                        portId: $portId,
                        factor: $factor,
                        negate: $negate,
                    );
                    $convertedTargets[] = "{$context}: {$part} -> port:{$portId}";

                    continue;
                }

                if (! $portValid) {
                    $warnings[] = "{$context}: port #{$portId} not found in LibreNMS DB; kept as RRD target.";
                }
            }

            // Generic RRD target: path/file.rrd[:dsIn:dsOut]
            if (preg_match('#^(.+\.rrd)(?::([^:]+):([^:]+))?$#i', $expr, $m) === 1) {
                $rrdPath = ltrim($m[1], './');
                // Strip leading /opt/librenms/rrd/ if present
                $rrdPath = (string) preg_replace('#^/?(?:opt/librenms/rrd|data/rrd)/#', '', $rrdPath);
                $dsIn = $m[2] ?? 'INOCTETS';
                $dsOut = $m[3] ?? 'OUTOCTETS';

                if (str_contains($rrdPath, '..') || str_starts_with($rrdPath, '/')) {
                    $warnings[] = "{$context}: skipped unsafe RRD path '{$m[1]}'.";

                    continue;
                }

                $effectiveFactor = $isGauge && str_contains(strtoupper($dsIn), 'OCTETS') ? $factor / 8.0 : $factor;
                $terms[] = new TargetTerm(
                    source: TargetTerm::SOURCE_RRD,
                    rrdPath: $rrdPath,
                    dsIn: $dsIn,
                    dsOut: $dsOut,
                    factor: $effectiveFactor,
                    negate: $negate,
                );
                $convertedTargets[] = "{$context}: {$part} -> rrd:{$rrdPath}";

                continue;
            }

            $warnings[] = "{$context}: unrecognized TARGET '{$part}' was skipped.";
        }

        return new TargetExpr($terms);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitNodeEndpoint(string $raw): array
    {
        $pos = strpos($raw, ':');
        if ($pos === false) {
            return [$raw, 'C'];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + 1) ?: 'C'];
    }

    private static function stripComment(string $line): string
    {
        $inQuote = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '"') {
                $inQuote = ! $inQuote;
            } elseif ($ch === '#' && ! $inQuote) {
                return trim(substr($line, 0, $i));
            }
        }

        return trim($line);
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $line): array
    {
        if (preg_match_all('/"([^"]*)"|(\S+)/', $line, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $tokens = [];
        foreach ($matches as $m) {
            $quoted = $m[1] ?? '';
            $tokens[] = $quoted !== '' ? $quoted : ($m[2] ?? '');
        }

        return $tokens;
    }
}
