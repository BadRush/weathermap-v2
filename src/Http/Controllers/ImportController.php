<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Http\Controllers;

use BadRush\Weathermap\Data\PortRepositoryInterface;
use BadRush\Weathermap\Import\ConfImporter;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Policies\WeathermapMapPolicy;
use BadRush\Weathermap\Support\LibreNmsBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportController extends Controller
{
    public function __construct(
        private readonly WeathermapMapPolicy $policy,
        private readonly ConfImporter $importer,
        private readonly PortRepositoryInterface $ports,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($this->policy->create($user), 403);

        $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'content' => ['nullable', 'string', 'max:1048576'],
            'file' => ['nullable', 'file', 'max:1024'],
        ]);

        $content = $request->input('content');
        $file = $request->file('file');
        if ($file instanceof UploadedFile) {
            $content = file_get_contents($file->getRealPath());
        }

        if (! is_string($content) || trim($content) === '') {
            return response()->json(['message' => 'Please provide a .conf file or raw configuration text.'], 422);
        }

        $result = $this->importer->import(
            $content,
            fn (int $portId): bool => $this->ports->findById($portId) !== null,
        );

        $doc = $result['document'];
        $report = $result['report'];

        $name = trim((string) ($request->input('name') ?: $doc->canvas->title ?: 'Imported Weathermap'));
        $base = Str::slug($name) ?: 'imported-map';
        $slug = $base;
        $i = 2;
        while (WeathermapMap::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        $userId = LibreNmsBridge::userId($user);

        $map = DB::transaction(function () use ($name, $slug, $doc, $userId): WeathermapMap {
            $map = WeathermapMap::query()->create([
                'name' => $name,
                'slug' => $slug,
                'document' => $doc->toArray(),
                'schema_version' => $doc->schemaVersion,
                'revision' => 1,
                'png_enabled' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $map->syncReferences($doc);

            return $map;
        });

        return response()->json([
            'map' => [
                'id' => $map->id,
                'name' => $map->name,
                'slug' => $map->slug,
                'editUrl' => "/weathermap/maps/{$map->id}/edit",
            ],
            'report' => $report->toArray(),
        ], 201);
    }
}
