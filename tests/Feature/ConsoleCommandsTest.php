<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Feature;

use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Services\MapRendererService;
use BadRush\Weathermap\Tests\TestCase;

final class ConsoleCommandsTest extends TestCase
{
    public function test_render_command_generates_png_and_thumbnail(): void
    {
        $map = WeathermapMap::query()->create([
            'name' => 'Scheduled Render Map',
            'slug' => 'scheduled-render-map',
            'document' => MapDocument::empty('Scheduled Render Map')->toArray(),
            'png_enabled' => true,
        ]);

        $this->artisan('weathermap:render')->assertSuccessful();

        $map->refresh();
        $this->assertNotNull($map->last_rendered_at);
        $this->assertNull($map->last_render_error);

        /** @var MapRendererService $renderer */
        $renderer = $this->app->make(MapRendererService::class);
        $this->assertFileExists($renderer->pngPath($map));
        $this->assertFileExists($renderer->thumbnailPath($map));
    }

    public function test_import_command_creates_map_from_conf_file(): void
    {
        $fixture = dirname(__DIR__).'/fixtures/conf/sample.conf';

        $this->artisan('weathermap:import', [
            'file' => $fixture,
            '--name' => 'CLI Imported Map',
        ])->assertSuccessful();

        $map = WeathermapMap::query()->where('name', 'CLI Imported Map')->first();
        $this->assertNotNull($map);
        $this->assertCount(2, $map->toDocument()->nodes);
        $this->assertCount(2, $map->toDocument()->links);
    }
}
