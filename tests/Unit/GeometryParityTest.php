<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Unit;

use BadRush\Weathermap\Document\LinkDef;
use BadRush\Weathermap\Document\NodeDef;
use BadRush\Weathermap\Document\ScaleDef;
use BadRush\Weathermap\Document\ScaleStop;
use BadRush\Weathermap\Geometry\ArrowBuilder;
use BadRush\Weathermap\Geometry\EndpointOffset;
use BadRush\Weathermap\Geometry\LabelPlacer;
use BadRush\Weathermap\Geometry\LinkPathBuilder;
use BadRush\Weathermap\Geometry\ScaleResolver;
use PHPUnit\Framework\TestCase;

final class GeometryParityTest extends TestCase
{
    public function test_shared_geometry_fixtures_match_expected_coordinates(): void
    {
        $raw = file_get_contents(dirname(__DIR__).'/fixtures/geometry/cases.json');
        $this->assertIsString($raw);
        /** @var array<string, mixed> $fixtures */
        $fixtures = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        foreach ($fixtures['endpointOffsets'] as $case) {
            $node = NodeDef::fromArray(array_merge(['id' => 'n', 'label' => 'N'], $case['node']));
            $pt = EndpointOffset::resolve($node, $case['offset']);
            $this->assertEqualsWithDelta($case['expected']['x'], $pt->x, 0.01);
            $this->assertEqualsWithDelta($case['expected']['y'], $pt->y, 0.01);
        }

        foreach ($fixtures['links'] as $case) {
            $nodeA = NodeDef::fromArray($case['nodeA']);
            $nodeB = NodeDef::fromArray($case['nodeB']);
            $link = LinkDef::fromArray($case['link']);

            $paths = LinkPathBuilder::build($link, $nodeA, $nodeB, $case['parallelIndex'], $case['parallelCount']);
            $outPoly = ArrowBuilder::buildPolygon($paths['outSpine'], $link->width, $link->arrowStyle);
            $inPoly = ArrowBuilder::buildPolygon($paths['inSpine'], $link->width, $link->arrowStyle);

            $exp = $case['expected'];
            $this->assertCount($exp['centerlineCount'], $paths['centerline'], "Case {$case['name']} centerline count");

            $outSpineEnd = $paths['outSpine'][count($paths['outSpine']) - 1];
            $this->assertEqualsWithDelta($exp['outTip']['x'], $outSpineEnd->x, 0.05, "Case {$case['name']} outTip X");
            $this->assertEqualsWithDelta($exp['outTip']['y'], $outSpineEnd->y, 0.05, "Case {$case['name']} outTip Y");

            if (isset($exp['inTip'])) {
                $inSpineEnd = $paths['inSpine'][count($paths['inSpine']) - 1];
                $this->assertEqualsWithDelta($exp['inTip']['x'], $inSpineEnd->x, 0.05);
                $this->assertEqualsWithDelta($exp['inTip']['y'], $inSpineEnd->y, 0.05);
            }

            if (isset($exp['outLabelPos'])) {
                $outLbl = LabelPlacer::onCenterline($paths['centerline'], $link->outLabelPos);
                $this->assertEqualsWithDelta($exp['outLabelPos']['x'], $outLbl->x, 0.05);
                $this->assertEqualsWithDelta($exp['outLabelPos']['y'], $outLbl->y, 0.05);
            }

            if (isset($exp['inLabelPos'])) {
                $inLbl = LabelPlacer::onCenterline($paths['centerline'], $link->inLabelPos);
                $this->assertEqualsWithDelta($exp['inLabelPos']['x'], $inLbl->x, 0.05);
                $this->assertEqualsWithDelta($exp['inLabelPos']['y'], $inLbl->y, 0.05);
            }

            if (isset($exp['outPolygonCount'])) {
                $this->assertCount($exp['outPolygonCount'], $outPoly);
            }

            if (isset($exp['miterCorners'])) {
                foreach ($exp['miterCorners'] as $corner) {
                    $found = false;
                    foreach ($outPoly as $polyPt) {
                        if (abs($polyPt->x - $corner['x']) < 0.05 && abs($polyPt->y - $corner['y']) < 0.05) {
                            $found = true;
                            break;
                        }
                    }
                    $this->assertTrue($found, "Case {$case['name']} missing exact miter corner ({$corner['x']}, {$corner['y']}).");
                }
            }

            if (isset($exp['maxCoordX'])) {
                foreach ($outPoly as $polyPt) {
                    $this->assertLessThanOrEqual($exp['maxCoordX'], $polyPt->x, "Case {$case['name']} hairpin miter spike exceeded bound.");
                }
            }

            $this->assertGreaterThanOrEqual(7, count($outPoly));
            $this->assertGreaterThanOrEqual(7, count($inPoly));
        }

        $classic = ScaleDef::classicDefault();
        foreach ($fixtures['scales'] as $case) {
            $this->assertSame($case['expected'], ScaleResolver::resolveColor($classic, (float) $case['percent']));
        }
    }

    public function test_gradient_scale_and_down_stale_states(): void
    {
        $gradientScale = new ScaleDef('GRAD', [
            new ScaleStop(0.0, 100.0, [0, 0, 0], [100, 200, 50]),
        ]);

        $this->assertSame('#326419', ScaleResolver::resolveColor($gradientScale, 50.0));
        $this->assertSame(ScaleResolver::COLOR_DOWN, ScaleResolver::resolveColor($gradientScale, 50.0, isDown: true));
        $this->assertSame(ScaleResolver::COLOR_STALE, ScaleResolver::resolveColor($gradientScale, 50.0, isStale: true));
        $this->assertSame('#0f172a', ScaleResolver::contrastText('#ffffff'));
        $this->assertSame('#f8fafc', ScaleResolver::contrastText('#0f172a'));
    }
}
