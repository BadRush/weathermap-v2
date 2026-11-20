<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Feature;

use BadRush\Weathermap\Data\StaticSource;
use BadRush\Weathermap\Data\TargetResolver;
use BadRush\Weathermap\Document\CanvasConfig;
use BadRush\Weathermap\Document\LinkDef;
use BadRush\Weathermap\Document\LinkEndpoint;
use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Document\NodeDef;
use BadRush\Weathermap\Document\ScaleDef;
use BadRush\Weathermap\Document\TargetExpr;
use BadRush\Weathermap\Document\TargetTerm;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Render\GdRenderer;
use BadRush\Weathermap\Render\SceneBuilder;
use BadRush\Weathermap\Support\AssetManifest;
use BadRush\Weathermap\Tests\TestCase;

final class PagesAndPerformanceTest extends TestCase
{
    public function test_web_pages_and_dist_bundle_assets_render_cleanly(): void
    {
        $admin = $this->makeUser('Admin', roles: ['admin']);

        $map = WeathermapMap::query()->create([
            'name' => 'Operations Map',
            'slug' => 'operations-map',
            'document' => MapDocument::empty('Operations Map')->toArray(),
        ]);

        $this->actingAs($admin)->get('/weathermap')->assertOk()->assertSee('Operations Map');
        $this->actingAs($admin)->get("/weathermap/maps/{$map->id}")->assertOk()->assertSee('weathermap-viewer-root');
        $this->actingAs($admin)->get("/weathermap/maps/{$map->id}/edit")->assertOk()->assertSee('weathermap-editor-root');
        $this->actingAs($admin)->get("/weathermap/kiosk?maps={$map->id}&rotate=20")->assertOk()->assertSee('weathermap-kiosk-root');

        // Verify built Vite dist JS & CSS assets are served directly from package
        $viewerAssets = AssetManifest::entry('viewer');
        $this->assertNotEmpty($viewerAssets['js'], 'Built Vite viewer JS entry must exist in dist/.vite/manifest.json.');
        foreach ($viewerAssets['js'] as $url) {
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        }
        foreach ($viewerAssets['css'] as $url) {
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        }
    }

    public function test_renders_200_link_topology_within_performance_budget(): void
    {
        $nodes = [];
        for ($i = 0; $i < 100; $i++) {
            $col = $i % 10;
            $row = intdiv($i, 10);
            $nodes[] = new NodeDef("n{$i}", "Node-{$i}", 80.0 + ($col * 110.0), 80.0 + ($row * 80.0));
        }

        $links = [];
        for ($i = 0; $i < 220; $i++) {
            $a = $i % 99;
            $b = ($a + 1 + intdiv($i, 99)) % 100;
            if ($a === $b) {
                $b = ($b + 1) % 100;
            }
            $links[] = new LinkDef(
                id: "l{$i}",
                a: new LinkEndpoint("n{$a}"),
                b: new LinkEndpoint("n{$b}"),
                target: new TargetExpr([
                    new TargetTerm(TargetTerm::SOURCE_STATIC, staticIn: ($i % 10) * 90_000_000.0, staticOut: ($i % 8) * 100_000_000.0),
                ]),
            );
        }

        $doc = new MapDocument(
            canvas: new CanvasConfig(width: 1280, height: 900, title: 'Stress 220 Links'),
            nodes: $nodes,
            links: $links,
            scales: [ScaleDef::classicDefault()],
        );

        $start = microtime(true);
        $scene = (new SceneBuilder(new TargetResolver([new StaticSource])))->build($doc, now: 1_760_000_000);
        $png = (new GdRenderer)->renderPng($scene);
        $elapsed = microtime(true) - $start;

        $this->assertCount(100, $scene->nodes);
        $this->assertCount(220, $scene->links);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        $this->assertLessThan(5.0, $elapsed, "220-link scene + GD PNG render took {$elapsed}s (budget: 5.0s).");
    }
}
