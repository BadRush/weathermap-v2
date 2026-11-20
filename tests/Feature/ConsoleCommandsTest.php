<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Feature;

use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Models\WeathermapAsset;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Services\AssetStorageService;
use BadRush\Weathermap\Services\MapRendererService;
use BadRush\Weathermap\Tests\TestCase;
use GdImage;
use Illuminate\Support\Facades\File;

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

    public function test_sync_assets_command_indexes_vendor_folder_idempotently(): void
    {
        /** @var AssetStorageService $storage */
        $storage = $this->app->make(AssetStorageService::class);
        $dropDir = $storage->vendorDropDir();
        File::ensureDirectoryExists($dropDir, 0750);

        $im = imagecreatetruecolor(24, 24);
        $this->assertInstanceOf(GdImage::class, $im);
        $orange = (int) imagecolorallocate($im, 249, 115, 22);
        imagefilledrectangle($im, 0, 0, 23, 23, $orange);
        imagepng($im, $dropDir.'/indosat-logo.png');

        $this->artisan('weathermap:sync-assets')->assertSuccessful();
        $this->assertSame(1, WeathermapAsset::query()->where('kind', WeathermapAsset::KIND_VENDOR)->count());

        // Second run should remain idempotent
        $this->artisan('weathermap:sync-assets')->assertSuccessful();
        $this->assertSame(1, WeathermapAsset::query()->where('kind', WeathermapAsset::KIND_VENDOR)->count());
    }
}
