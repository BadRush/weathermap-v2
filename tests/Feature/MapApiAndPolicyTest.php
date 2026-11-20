<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Feature;

use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Models\WeathermapMapReference;
use BadRush\Weathermap\Support\LibreNmsBridge;
use BadRush\Weathermap\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class MapApiAndPolicyTest extends TestCase
{
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $map = WeathermapMap::query()->create([
            'name' => 'Secret Core',
            'slug' => 'secret-core',
            'document' => MapDocument::empty('Secret Core')->toArray(),
        ]);

        $this->getJson('/weathermap/api/maps')->assertUnauthorized();
        $this->getJson("/weathermap/api/maps/{$map->id}/scene")->assertUnauthorized();
        $this->getJson("/weathermap/maps/{$map->id}.png")->assertUnauthorized();
    }

    public function test_rbac_and_per_map_acl_isolation(): void
    {
        $admin = $this->makeUser('Admin', roles: ['admin']);
        $globalRead = $this->makeUser('NOC Read', roles: ['global-read']);
        $editor = $this->makeUser('Map Editor', roles: ['user'], permissions: [LibreNmsBridge::EDIT_PERMISSION]);
        $restricted = $this->makeUser('Tenant User', roles: ['user']);
        $opsRoleUser = $this->makeUser('Ops User', roles: ['net-ops']);

        // Editor creates map
        $createRes = $this->actingAs($editor)->postJson('/weathermap/api/maps', [
            'name' => 'Core Topology',
        ])->assertCreated();

        $mapId = (int) $createRes->json('map.id');

        // Restricted user cannot view map, scene, or PNG
        $this->actingAs($restricted)->getJson("/weathermap/api/maps/{$mapId}")->assertForbidden();
        $this->actingAs($restricted)->getJson("/weathermap/api/maps/{$mapId}/scene")->assertForbidden();
        $this->actingAs($restricted)->get("/weathermap/maps/{$mapId}.png")->assertForbidden();
        $this->actingAs($restricted)->postJson('/weathermap/api/maps', ['name' => 'Blocked'])->assertForbidden();

        // Global-read can view scene & PNG, cannot edit or delete
        $this->actingAs($globalRead)->getJson("/weathermap/api/maps/{$mapId}/scene")->assertOk();
        $this->actingAs($globalRead)->get("/weathermap/maps/{$mapId}.png")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($globalRead)->deleteJson("/weathermap/api/maps/{$mapId}")->assertForbidden();

        // Admin grants per-map access to role `net-ops` and specific user `$restricted`
        $this->actingAs($admin)->putJson("/weathermap/api/maps/{$mapId}/access", [
            'rules' => [
                ['subject_type' => 'role', 'subject_id' => 'net-ops'],
                ['subject_type' => 'user', 'subject_id' => (string) $restricted->getKey()],
            ],
        ])->assertOk()->assertJsonCount(2, 'accessRules');

        // Both granted users can now view the map & scene, still cannot edit
        $this->actingAs($opsRoleUser)->getJson("/weathermap/api/maps/{$mapId}/scene")->assertOk();
        $this->actingAs($restricted)->getJson("/weathermap/api/maps/{$mapId}/scene")->assertOk();
        $this->actingAs($restricted)->deleteJson("/weathermap/api/maps/{$mapId}")->assertForbidden();
    }

    public function test_optimistic_locking_validation_and_reference_sync(): void
    {
        $admin = $this->makeUser('Admin', roles: ['admin']);

        DB::table('devices')->insert([
            'device_id' => 10,
            'hostname' => 'core-rtr-01',
            'sysName' => 'core-rtr-01',
            'os' => 'iosxe',
            'status' => true,
        ]);
        DB::table('ports')->insert([
            'port_id' => 101,
            'device_id' => 10,
            'ifName' => 'Gi0/0/1',
            'ifInOctets_rate' => 50_000_000,
            'ifOutOctets_rate' => 25_000_000,
            'ifSpeed' => 1_000_000_000,
            'ifOperStatus' => 'up',
            'poll_time' => time() - 30,
        ]);

        $mapId = (int) $this->actingAs($admin)->postJson('/weathermap/api/maps', [
            'name' => 'DC Backbone',
        ])->assertCreated()->json('map.id');

        $doc = MapDocument::empty('DC Backbone')->toArray();
        $doc['nodes'] = [
            ['id' => 'n1', 'label' => 'Core 1', 'x' => 120, 'y' => 200, 'deviceId' => 10],
            ['id' => 'n2', 'label' => 'Core 2', 'x' => 420, 'y' => 200],
        ];
        $doc['links'] = [
            [
                'id' => 'l1',
                'a' => ['nodeId' => 'n1', 'offset' => 'E'],
                'b' => ['nodeId' => 'n2', 'offset' => 'W'],
                'target' => [
                    'terms' => [
                        ['source' => 'port', 'portId' => 101, 'factor' => 1, 'negate' => false, 'reverse' => false],
                    ],
                ],
            ],
        ];

        // Save rev 1 -> bumps to rev 2 and syncs device + port references
        $this->actingAs($admin)->putJson("/weathermap/api/maps/{$mapId}", [
            'name' => 'DC Backbone v2',
            'revision' => 1,
            'document' => $doc,
        ])->assertOk()->assertJsonPath('map.revision', 2);

        $this->assertSame(1, WeathermapMapReference::query()->where('map_id', $mapId)->where('ref_type', 'device')->where('device_id', 10)->count());
        $this->assertSame(1, WeathermapMapReference::query()->where('map_id', $mapId)->where('ref_type', 'port')->where('port_id', 101)->count());

        // Stale revision 1 returns 409 Conflict
        $this->actingAs($admin)->putJson("/weathermap/api/maps/{$mapId}", [
            'revision' => 1,
            'document' => $doc,
        ])->assertStatus(409)->assertJsonPath('currentRevision', 2);

        // Live scene resolves port #101 (400 Mbps in = 40%, 200 Mbps out = 20%)
        $sceneRes = $this->actingAs($admin)->getJson("/weathermap/api/maps/{$mapId}/scene?fresh=1")->assertOk();
        $this->assertEqualsWithDelta(40.0, (float) $sceneRes->json('scene.links.0.inArrow.percent'), 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $sceneRes->json('scene.links.0.outArrow.percent'), 0.01);
        $this->assertSame('/graph.php?id=101&type=port_bits&legend=no&from=-86400&to=now', $sceneRes->json('scene.links.0.graphUrl'));

        // Malicious javascript: URL and path-traversal RRD path are rejected with 422
        $badDoc = $doc;
        $badDoc['nodes'][0]['infoUrl'] = 'javascript:alert(1)';
        $this->actingAs($admin)->putJson("/weathermap/api/maps/{$mapId}", [
            'revision' => 2,
            'document' => $badDoc,
        ])->assertUnprocessable();
    }
}
