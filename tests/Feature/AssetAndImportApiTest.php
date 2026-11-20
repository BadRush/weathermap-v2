<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Feature;

use BadRush\Weathermap\Document\AnnotationDef;
use BadRush\Weathermap\Document\LinkDef;
use BadRush\Weathermap\Document\LinkEndpoint;
use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Document\NodeDef;
use BadRush\Weathermap\Models\WeathermapAsset;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Services\AssetStorageService;
use BadRush\Weathermap\Services\MapRendererService;
use BadRush\Weathermap\Tests\TestCase;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class AssetAndImportApiTest extends TestCase
{
    public function test_asset_upload_sanitizes_png_and_rejects_svg(): void
    {
        $admin = $this->makeUser('Admin', roles: ['admin']);

        // Reject SVG upload (XSS protection)
        $svg = UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($admin)->postJson('/weathermap/api/assets', [
            'kind' => 'icon',
            'file' => $svg,
        ])->assertUnprocessable();

        // Accept valid PNG, re-encode via GD, serve authenticated with ETag
        $pngFile = UploadedFile::fake()->image('switch.png', 120, 80);
        $uploadRes = $this->actingAs($admin)->postJson('/weathermap/api/assets', [
            'kind' => 'icon',
            'file' => $pngFile,
        ])->assertCreated();

        $assetUrl = (string) $uploadRes->json('asset.url');
        $getRes = $this->actingAs($admin)->get($assetUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $etag = (string) $getRes->headers->get('ETag');
        $this->actingAs($admin)->withHeaders(['If-None-Match' => $etag])->get($assetUrl)->assertStatus(304);
    }

    public function test_import_endpoint_and_lookup_and_bundle_serving(): void
    {
        $admin = $this->makeUser('Admin', roles: ['admin']);

        DB::table('devices')->insert(['device_id' => 1, 'hostname' => 'core-01.example', 'sysName' => 'core-01', 'os' => 'linux', 'status' => true]);
        DB::table('ports')->insert([
            'port_id' => 42,
            'device_id' => 1,
            'ifName' => 'eth0',
            'ifAlias' => 'Uplink ISP',
            'ifSpeed' => 10_000_000_000,
            'ifOperStatus' => 'up',
        ]);

        // Lookup endpoints
        $this->actingAs($admin)->getJson('/weathermap/api/lookup/devices?q=core')
            ->assertOk()
            ->assertJsonCount(1, 'devices');

        $this->actingAs($admin)->getJson('/weathermap/api/lookup/ports?q=Uplink')
            ->assertOk()
            ->assertJsonPath('ports.0.port_id', 42);

        // Import classic .conf via API
        $conf = (string) file_get_contents(dirname(__DIR__).'/fixtures/conf/sample.conf');
        $this->actingAs($admin)->postJson('/weathermap/api/import', [
            'content' => $conf,
        ])
            ->assertCreated()
            ->assertJsonPath('report.nodesImported', 2)
            ->assertJsonPath('report.linksImported', 2);

        // Bundle endpoint serves bundled DejaVuSans font and blocks traversal
        $this->get('/weathermap/assets/fonts/DejaVuSans.ttf')
            ->assertOk()
            ->assertHeader('Content-Type', 'font/ttf');

        $this->get('/weathermap/assets/../../composer.json')->assertNotFound();
    }

    public function test_vendor_folder_reuse_sync_and_safe_delete(): void
    {
        $admin = $this->makeUser('Admin', roles: ['admin']);

        // Reject SVG upload for kind=vendor
        $svg = UploadedFile::fake()->createWithContent('bad-vendor.svg', '<svg><script>alert(1)</script></svg>');
        $this->actingAs($admin)->postJson('/weathermap/api/assets', [
            'kind' => 'vendor',
            'file' => $svg,
        ])->assertUnprocessable();

        // Upload valid vendor logo PNG
        $vendorPng = UploadedFile::fake()->image('telkom-astinet.png', 64, 64);
        $uploadRes = $this->actingAs($admin)->postJson('/weathermap/api/assets', [
            'kind' => 'vendor',
            'file' => $vendorPng,
        ])->assertCreated();

        $vendorAssetId = (int) $uploadRes->json('asset.id');
        $this->assertSame('vendor', $uploadRes->json('asset.kind'));

        // Filter query returns matching vendor asset
        $this->actingAs($admin)->getJson('/weathermap/api/assets?kind=vendor&q=telkom')
            ->assertOk()
            ->assertJsonCount(1, 'assets')
            ->assertJsonPath('assets.0.id', $vendorAssetId);

        // Reuse the SAME vendor asset across 2 different maps (link badge + free-floating annotation)
        $map1Doc = new MapDocument(
            nodes: [
                new NodeDef('n1', 'PE-1', 100.0, 120.0),
                new NodeDef('n2', 'PE-2', 400.0, 120.0),
            ],
            links: [
                new LinkDef(
                    id: 'l1',
                    a: new LinkEndpoint('n1'),
                    b: new LinkEndpoint('n2'),
                    staticLabel: 'Telkom Astinet',
                    vendorIconAssetId: $vendorAssetId,
                ),
            ],
        );
        $map1 = WeathermapMap::query()->create([
            'name' => 'Backbone A',
            'slug' => 'backbone-a',
            'document' => $map1Doc->toArray(),
        ]);

        $map2Doc = new MapDocument(
            annotations: [
                new AnnotationDef(
                    id: 'ann1',
                    text: 'ISP Upstream',
                    iconAssetId: $vendorAssetId,
                ),
            ],
        );
        $map2 = WeathermapMap::query()->create([
            'name' => 'Backbone B',
            'slug' => 'backbone-b',
            'document' => $map2Doc->toArray(),
        ]);

        // Verify /scene returns staticBadge & annotations referencing the reused asset
        $this->actingAs($admin)->getJson("/weathermap/api/maps/{$map1->id}/scene")
            ->assertOk()
            ->assertJsonPath('scene.links.0.staticBadge.text', 'Telkom Astinet')
            ->assertJsonPath('scene.links.0.staticBadge.iconAssetId', $vendorAssetId);

        $this->actingAs($admin)->getJson("/weathermap/api/maps/{$map2->id}/scene")
            ->assertOk()
            ->assertJsonPath('scene.annotations.0.iconAssetId', $vendorAssetId);

        // Deleting an in-use vendor icon must return 409 Conflict with map names
        $this->actingAs($admin)->deleteJson("/weathermap/api/assets/{$vendorAssetId}")
            ->assertStatus(409)
            ->assertJsonFragment(['Backbone A'])
            ->assertJsonFragment(['Backbone B']);

        // Remove references -> delete must succeed (200) and remove disk file
        /** @var MapRendererService $renderer */
        $renderer = $this->app->make(MapRendererService::class);
        $diskPath = $renderer->resolveAssetDiskPath($vendorAssetId);
        $this->assertNotNull($diskPath);
        $this->assertFileExists($diskPath);

        $map1->delete();
        $map2->delete();

        $this->actingAs($admin)->deleteJson("/weathermap/api/assets/{$vendorAssetId}")
            ->assertOk()
            ->assertJsonPath('deleted', true);
        $this->assertFileDoesNotExist($diskPath);

        // Drop a real PNG and an evil SVG into storage/app/weathermap/assets/vendors/ and test Sync Folder
        /** @var AssetStorageService $storage */
        $storage = $this->app->make(AssetStorageService::class);
        $dropDir = $storage->vendorDropDir();
        File::ensureDirectoryExists($dropDir, 0750);

        $im = imagecreatetruecolor(32, 32);
        $this->assertInstanceOf(GdImage::class, $im);
        $cyan = (int) imagecolorallocate($im, 56, 189, 248);
        imagefilledrectangle($im, 0, 0, 31, 31, $cyan);
        imagepng($im, $dropDir.'/biznet-metro.png');
        file_put_contents($dropDir.'/evil.svg', '<svg><script>alert(1)</script></svg>');

        $sync1 = $this->actingAs($admin)->postJson('/weathermap/api/assets/sync-vendors')
            ->assertOk()
            ->assertJsonPath('imported', 1);
        $this->assertNotEmpty($sync1->json('errors'));
        $this->assertDatabaseHas('weathermap_assets', [
            'kind' => WeathermapAsset::KIND_VENDOR,
            'original_name' => 'biznet-metro.png',
        ]);

        // Second sync must be idempotent (0 newly imported, 1 skipped)
        $this->actingAs($admin)->postJson('/weathermap/api/assets/sync-vendors')
            ->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('skipped', 1);
    }
}
