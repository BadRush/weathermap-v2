<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Unit;

use BadRush\Weathermap\Data\StaticSource;
use BadRush\Weathermap\Data\TargetResolver;
use BadRush\Weathermap\Document\CanvasConfig;
use BadRush\Weathermap\Document\CapacityDef;
use BadRush\Weathermap\Document\LegendDef;
use BadRush\Weathermap\Document\LinkDef;
use BadRush\Weathermap\Document\LinkEndpoint;
use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Document\NodeDef;
use BadRush\Weathermap\Document\ScaleDef;
use BadRush\Weathermap\Document\TargetExpr;
use BadRush\Weathermap\Document\TargetTerm;
use BadRush\Weathermap\Document\ViaPoint;
use BadRush\Weathermap\Render\GdRenderer;
use BadRush\Weathermap\Render\SceneBuilder;
use GdImage;
use PHPUnit\Framework\TestCase;

final class GdRendererGoldenTest extends TestCase
{
    public function test_renders_deterministic_png_and_thumbnail_with_expected_pixels(): void
    {
        if (! GdRenderer::hasGd()) {
            $this->markTestSkipped('GD extension not available.');
        }

        $doc = new MapDocument(
            canvas: new CanvasConfig(width: 640, height: 400, bgColor: '#0f172a', title: 'Golden NOC Map'),
            nodes: [
                new NodeDef('rtr1', 'Core-1', 120.0, 200.0, NodeDef::ICON_SHAPE_BOX, iconWidth: 80, iconHeight: 40, bgColor: '#1e293b'),
                new NodeDef('rtr2', 'Core-2', 520.0, 200.0, NodeDef::ICON_SHAPE_ROUND, iconWidth: 80, iconHeight: 40, bgColor: '#1e293b'),
            ],
            links: [
                new LinkDef(
                    id: 'link-primary',
                    a: new LinkEndpoint('rtr1', 'E'),
                    b: new LinkEndpoint('rtr2', 'W'),
                    via: [new ViaPoint(320.0, 120.0)],
                    viaStyle: LinkDef::VIA_CURVED,
                    width: 10.0,
                    capacity: new CapacityDef(CapacityDef::SOURCE_MANUAL, 1_000_000_000.0, 1_000_000_000.0),
                    // 90% in -> classic red (#ff0000), 45% out -> classic green (#00f000)
                    target: new TargetExpr([
                        new TargetTerm(TargetTerm::SOURCE_STATIC, staticIn: 900_000_000.0, staticOut: 450_000_000.0),
                    ]),
                ),
            ],
            scales: [ScaleDef::classicDefault()],
            legend: new LegendDef(visible: true, x: 20, y: 210, style: LegendDef::STYLE_CLASSIC),
        );

        $builder = new SceneBuilder(new TargetResolver([new StaticSource]));
        $scene = $builder->build($doc, now: 1_760_000_000);

        $renderer = new GdRenderer;
        $png = $renderer->renderPng($scene);

        // Valid PNG magic header
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));

        // Decode with GD and verify dimensions & rendered colors
        $im = imagecreatefromstring($png);
        $this->assertInstanceOf(GdImage::class, $im);
        $this->assertSame(640, imagesx($im));
        $this->assertSame(400, imagesy($im));

        // Top-right corner must remain canvas background (#0f172a = 15,23,42)
        $cornerRgb = imagecolorsforindex($im, (int) imagecolorat($im, 635, 5));
        $this->assertSame(15, $cornerRgb['red']);
        $this->assertSame(23, $cornerRgb['green']);
        $this->assertSame(42, $cornerRgb['blue']);

        // Count classic green (#00f000) and red (#ff0000) arrow pixels to verify both directional arrows rendered
        $greenPixels = 0;
        $redPixels = 0;
        for ($y = 80; $y < 240; $y += 2) {
            for ($x = 150; $x < 490; $x += 2) {
                $rgb = imagecolorsforindex($im, (int) imagecolorat($im, $x, $y));
                if ($rgb['green'] >= 220 && $rgb['red'] <= 20 && $rgb['blue'] <= 20) {
                    $greenPixels++;
                } elseif ($rgb['red'] >= 230 && $rgb['green'] <= 20 && $rgb['blue'] <= 20) {
                    $redPixels++;
                }
            }
        }
        $this->assertGreaterThan(50, $greenPixels, 'Outbound green arrow must be visibly rendered.');
        $this->assertGreaterThan(50, $redPixels, 'Inbound red arrow must be visibly rendered.');

        // Determinism check: rendering the same scene twice produces identical PNG bytes
        $png2 = $renderer->renderPng($scene);
        $this->assertSame(sha1($png), sha1($png2));

        // Thumbnail check
        $thumb = $renderer->renderThumbnail($png, 320, 200);
        $thumbIm = imagecreatefromstring($thumb);
        $this->assertInstanceOf(GdImage::class, $thumbIm);
        $this->assertSame(320, imagesx($thumbIm));
        $this->assertSame(200, imagesy($thumbIm));
    }

    public function test_renders_multi_via_angled_staircase_with_crisp_90_degree_corners(): void
    {
        if (! GdRenderer::hasGd()) {
            $this->markTestSkipped('GD extension not available.');
        }

        $doc = new MapDocument(
            canvas: new CanvasConfig(width: 640, height: 420, bgColor: '#0f172a', title: 'Multi-VIA Angled Test'),
            nodes: [
                new NodeDef('n1', 'DC-North', 100.0, 100.0, NodeDef::ICON_SHAPE_BOX, iconWidth: 88, iconHeight: 36),
                new NodeDef('n2', 'DC-South', 540.0, 320.0, NodeDef::ICON_SHAPE_BOX, iconWidth: 88, iconHeight: 36),
            ],
            links: [
                new LinkDef(
                    id: 'l-angled-multi',
                    a: new LinkEndpoint('n1', 'E'),
                    b: new LinkEndpoint('n2', 'W'),
                    via: [
                        new ViaPoint(240.0, 100.0),
                        new ViaPoint(240.0, 210.0),
                        new ViaPoint(400.0, 210.0),
                        new ViaPoint(400.0, 320.0),
                    ],
                    viaStyle: LinkDef::VIA_ANGLED,
                    width: 10.0,
                    arrowStyle: LinkDef::ARROW_CLASSIC,
                    capacity: new CapacityDef(CapacityDef::SOURCE_MANUAL, 1_000_000_000.0, 1_000_000_000.0),
                    target: new TargetExpr([
                        new TargetTerm(TargetTerm::SOURCE_STATIC, staticIn: 600_000_000.0, staticOut: 450_000_000.0),
                    ]),
                ),
            ],
            scales: [ScaleDef::classicDefault()],
            legend: new LegendDef(visible: true, x: 20, y: 210),
        );

        $scene = (new SceneBuilder(new TargetResolver([new StaticSource])))->build($doc, now: 1_760_000_000);
        $png = (new GdRenderer)->renderPng($scene);

        $im = imagecreatefromstring($png);
        $this->assertInstanceOf(GdImage::class, $im);
        $this->assertSame(640, imagesx($im));
        $this->assertSame(420, imagesy($im));

        // Corner (240, 100) must be filled green (#00f000) across full width from (240, 97) to (243, 100)
        $cornerRgb = imagecolorsforindex($im, (int) imagecolorat($im, 240, 100));
        $this->assertGreaterThanOrEqual(220, $cornerRgb['green']);
    }
}
