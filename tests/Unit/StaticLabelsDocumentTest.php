<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Unit;

use BadRush\Weathermap\Data\StaticSource;
use BadRush\Weathermap\Data\TargetResolver;
use BadRush\Weathermap\Document\AnnotationDef;
use BadRush\Weathermap\Document\DocumentValidator;
use BadRush\Weathermap\Document\LinkDef;
use BadRush\Weathermap\Document\LinkEndpoint;
use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Document\NodeDef;
use BadRush\Weathermap\Render\SceneBuilder;
use PHPUnit\Framework\TestCase;

final class StaticLabelsDocumentTest extends TestCase
{
    public function test_serializes_and_restores_link_static_badges_and_annotations(): void
    {
        $doc = new MapDocument(
            nodes: [
                new NodeDef('r1', 'Core-1', 100.0, 100.0),
                new NodeDef('r2', 'Core-2', 400.0, 100.0),
            ],
            links: [
                new LinkDef(
                    id: 'l1',
                    a: new LinkEndpoint('r1'),
                    b: new LinkEndpoint('r2'),
                    staticLabel: 'Telkom Astinet 10G',
                    staticLabelPos: 0.45,
                    staticLabelOffsetX: 12.0,
                    staticLabelOffsetY: -24.0,
                    staticLabelFontSize: 11,
                    staticLabelBgColor: '#111827',
                    staticLabelTextColor: '#f8fafc',
                    vendorIconAssetId: 42,
                    vendorIconSize: 20,
                ),
            ],
            annotations: [
                new AnnotationDef(
                    id: 'ann1',
                    text: "DC Jakarta\r\nISP Primary",
                    x: 150.0,
                    y: 260.0,
                    style: AnnotationDef::STYLE_CARD,
                    align: AnnotationDef::ALIGN_CENTER,
                    fontSize: 14,
                    textColor: '#ffffff',
                    bgColor: '#0f172a',
                    borderColor: '#38bdf8',
                    iconAssetId: 42,
                    iconPosition: AnnotationDef::ICON_TOP,
                    iconSize: 32,
                    infoUrl: 'https://status.example.com',
                ),
            ],
        );

        $array = $doc->toArray();
        $this->assertSame([], DocumentValidator::validate($array));

        $restored = MapDocument::fromArray($array);
        $this->assertCount(1, $restored->links);
        $this->assertSame('Telkom Astinet 10G', $restored->links[0]->staticLabel);
        $this->assertSame(42, $restored->links[0]->vendorIconAssetId);
        $this->assertSame(20, $restored->links[0]->vendorIconSize);
        $this->assertSame(-24.0, $restored->links[0]->staticLabelOffsetY);

        $this->assertCount(1, $restored->annotations);
        $this->assertSame("DC Jakarta\nISP Primary", $restored->annotations[0]->text);
        $this->assertSame(AnnotationDef::ICON_TOP, $restored->annotations[0]->iconPosition);
        $this->assertSame(42, $restored->annotations[0]->iconAssetId);
    }

    public function test_validator_rejects_unsafe_annotation_urls_duplicate_ids_and_invalid_colors(): void
    {
        $payload = [
            'nodes' => [
                ['id' => 'n1'],
                ['id' => 'n2'],
            ],
            'links' => [
                [
                    'id' => 'l1',
                    'a' => ['nodeId' => 'n1'],
                    'b' => ['nodeId' => 'n2'],
                    'staticLabelBgColor' => 'not-a-hex',
                ],
            ],
            'annotations' => [
                [
                    'id' => 'note1',
                    'text' => 'XSS test',
                    'infoUrl' => 'javascript:alert(1)',
                    'textColor' => 'red',
                ],
                [
                    'id' => 'note1',
                    'text' => 'Duplicate ID',
                ],
            ],
        ];

        $errors = DocumentValidator::validate($payload);
        $joined = implode("\n", $errors);

        $this->assertStringContainsString('links[0].staticLabelBgColor', $joined);
        $this->assertStringContainsString('annotations[0].infoUrl', $joined);
        $this->assertStringContainsString('annotations[0].textColor', $joined);
        $this->assertStringContainsString("Duplicate annotation id 'note1'", $joined);
    }

    public function test_scene_builder_formats_timestamp_in_wib_by_default_and_supports_overrides(): void
    {
        $doc = MapDocument::empty('Timezone Check');
        $builder = new SceneBuilder(new TargetResolver([new StaticSource]));

        // 2025-10-09 08:53:20 UTC == 2025-10-09 15:53:20 WIB (Asia/Jakarta, UTC+7)
        $wibScene = $builder->build($doc, now: 1_760_000_000);
        $this->assertSame('Updated: 2025-10-09 15:53:20 WIB', $wibScene->timestampText);

        // Explicit WITA override
        $witaScene = $builder->build($doc, now: 1_760_000_000, timezone: 'Asia/Makassar');
        $this->assertSame('Updated: 2025-10-09 16:53:20 WITA', $witaScene->timestampText);

        // Invalid timezone safely falls back to WIB instead of throwing
        $fallbackScene = $builder->build($doc, now: 1_760_000_000, timezone: 'Invalid/Timezone');
        $this->assertSame('Updated: 2025-10-09 15:53:20 WIB', $fallbackScene->timestampText);
    }
}
