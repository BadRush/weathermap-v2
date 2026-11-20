<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Feature;

use BadRush\Weathermap\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

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
}
