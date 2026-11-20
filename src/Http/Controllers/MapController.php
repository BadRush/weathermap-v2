<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Http\Controllers;

use BadRush\Weathermap\Document\DocumentValidator;
use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Models\WeathermapMapAccess;
use BadRush\Weathermap\Policies\WeathermapMapPolicy;
use BadRush\Weathermap\Render\GdRenderer;
use BadRush\Weathermap\Services\MapRendererService;
use BadRush\Weathermap\Support\AssetManifest;
use BadRush\Weathermap\Support\LibreNmsBridge;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MapController extends Controller
{
    public function __construct(
        private readonly WeathermapMapPolicy $policy,
        private readonly MapRendererService $renderer,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->requireUser($request);
        /** @var Collection<int, WeathermapMap> $all */
        $all = WeathermapMap::query()->orderBy('name')->get();
        $maps = $all
            ->filter(fn (WeathermapMap $map): bool => $this->policy->view($user, $map))
            ->values();

        return view('weathermap::index', [
            'title' => __('weathermap::weathermap.title'),
            'maps' => $maps,
            'canEdit' => $this->policy->canEdit($user),
            'hasFreeType' => GdRenderer::hasFreeType(),
        ]);
    }

    public function show(Request $request, WeathermapMap $map): View
    {
        $user = $this->requireUser($request);
        abort_unless($this->policy->view($user, $map), 403);

        return view('weathermap::viewer', [
            'title' => $map->name,
            'map' => $map,
            'canEdit' => $this->policy->canEdit($user),
            'assets' => AssetManifest::entry('viewer'),
        ]);
    }

    public function edit(Request $request, WeathermapMap $map): View
    {
        $user = $this->requireUser($request);
        abort_unless($this->policy->update($user, $map), 403);

        return view('weathermap::editor', [
            'title' => __('weathermap::weathermap.editing_map', ['name' => $map->name]),
            'map' => $map,
            'assets' => AssetManifest::entry('editor'),
        ]);
    }

    public function kiosk(Request $request): View
    {
        $user = $this->requireUser($request);
        $rawIds = $request->string('maps')->toString();
        $requestedIds = array_values(array_filter(
            array_map('intval', explode(',', $rawIds)),
            static fn (int $id): bool => $id > 0,
        ));

        $query = WeathermapMap::query()->orderBy('name');
        if ($requestedIds !== []) {
            $query->whereIn('id', $requestedIds);
        }

        /** @var Collection<int, WeathermapMap> $all */
        $all = $query->get();
        $maps = $all
            ->filter(fn (WeathermapMap $map): bool => $this->policy->view($user, $map))
            ->values();

        // Preserve explicit order when `?maps=3,1,2` is supplied
        if ($requestedIds !== []) {
            $order = array_flip($requestedIds);
            $maps = $maps->sortBy(static fn (WeathermapMap $m): int => $order[$m->id] ?? PHP_INT_MAX)->values();
        }

        $rotateSeconds = max(5, min(600, $request->integer('rotate', 30)));

        return view('weathermap::kiosk', [
            'title' => __('weathermap::weathermap.kiosk_title'),
            'maps' => $maps->map(static fn (WeathermapMap $m): array => [
                'id' => $m->id,
                'name' => $m->name,
                'slug' => $m->slug,
            ])->all(),
            'rotateSeconds' => $rotateSeconds,
            'assets' => AssetManifest::entry('kiosk'),
        ]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        /** @var Collection<int, WeathermapMap> $all */
        $all = WeathermapMap::query()->orderBy('name')->get();
        $maps = $all
            ->filter(fn (WeathermapMap $map): bool => $this->policy->view($user, $map))
            ->values()
            ->map(static fn (WeathermapMap $m): array => [
                'id' => $m->id,
                'name' => $m->name,
                'slug' => $m->slug,
                'revision' => $m->revision,
                'png_enabled' => $m->png_enabled,
                'last_rendered_at' => $m->last_rendered_at?->toIso8601String(),
                'updated_at' => $m->updated_at?->toIso8601String(),
            ]);

        return response()->json(['maps' => $maps]);
    }

    public function apiStore(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        abort_unless($this->policy->create($user), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:160'],
            'png_enabled' => ['sometimes', 'boolean'],
            'document' => ['sometimes', 'array'],
        ]);

        $rawDoc = $validated['document'] ?? MapDocument::empty($validated['name'])->toArray();
        $errors = DocumentValidator::validate($rawDoc);
        if ($errors !== []) {
            return response()->json(['message' => 'Invalid map document.', 'errors' => $errors], 422);
        }

        $doc = MapDocument::fromArray($rawDoc);
        $userId = LibreNmsBridge::userId($user);

        $map = DB::transaction(function () use ($validated, $doc, $userId): WeathermapMap {
            $map = WeathermapMap::query()->create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'document' => $doc->toArray(),
                'schema_version' => $doc->schemaVersion,
                'revision' => 1,
                'png_enabled' => (bool) ($validated['png_enabled'] ?? true),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $map->syncReferences($doc);

            return $map;
        });

        return response()->json([
            'map' => $this->serializeMapDetail($map),
        ], 201);
    }

    public function apiShow(Request $request, WeathermapMap $map): JsonResponse
    {
        $user = $this->requireUser($request);
        abort_unless($this->policy->view($user, $map), 403);

        $map->load('accessRules');

        return response()->json([
            'map' => $this->serializeMapDetail($map),
            'canEdit' => $this->policy->update($user, $map),
        ]);
    }

    public function apiUpdate(Request $request, WeathermapMap $map): JsonResponse
    {
        $user = $this->requireUser($request);
        abort_unless($this->policy->update($user, $map), 403);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:160'],
            'png_enabled' => ['sometimes', 'boolean'],
            'revision' => ['required', 'integer', 'min:1'],
            'document' => ['required', 'array'],
        ]);

        if ((int) $validated['revision'] !== $map->revision) {
            return response()->json([
                'message' => __('weathermap::weathermap.revision_conflict'),
                'currentRevision' => $map->revision,
                'updatedAt' => $map->updated_at?->toIso8601String(),
            ], 409);
        }

        $errors = DocumentValidator::validate($validated['document']);
        if ($errors !== []) {
            return response()->json(['message' => 'Invalid map document.', 'errors' => $errors], 422);
        }

        $doc = MapDocument::fromArray($validated['document']);
        $userId = LibreNmsBridge::userId($user);

        DB::transaction(function () use ($map, $validated, $doc, $userId): void {
            if (isset($validated['name'])) {
                $map->name = $validated['name'];
            }
            if (isset($validated['png_enabled'])) {
                $map->png_enabled = (bool) $validated['png_enabled'];
            }
            $map->document = $doc->toArray();
            $map->schema_version = $doc->schemaVersion;
            $map->revision = $map->revision + 1;
            $map->updated_by = $userId;
            $map->save();

            $map->syncReferences($doc);
        });

        $this->renderer->invalidateSceneCache($map);

        return response()->json([
            'map' => $this->serializeMapDetail($map->fresh() ?? $map),
        ]);
    }

    public function apiDestroy(Request $request, WeathermapMap $map): JsonResponse
    {
        $user = $this->requireUser($request);
        abort_unless($this->policy->delete($user, $map), 403);

        $this->renderer->invalidateSceneCache($map);
        $this->renderer->deleteRenderedFiles($map);
        $map->delete();

        return response()->json(['deleted' => true]);
    }

    public function apiUpdateAccess(Request $request, WeathermapMap $map): JsonResponse
    {
        $user = $this->requireUser($request);
        abort_unless($this->policy->manageAccess($user, $map), 403);

        $validated = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.subject_type' => ['required', 'in:role,user'],
            'rules.*.subject_id' => ['required', 'string', 'min:1', 'max:128'],
        ]);

        DB::transaction(function () use ($map, $validated): void {
            $map->accessRules()->delete();
            $seen = [];
            foreach ($validated['rules'] as $rule) {
                $key = $rule['subject_type'].':'.$rule['subject_id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                WeathermapMapAccess::query()->create([
                    'map_id' => $map->id,
                    'subject_type' => $rule['subject_type'],
                    'subject_id' => $rule['subject_id'],
                ]);
            }
        });

        $map->load('accessRules');

        return response()->json([
            'accessRules' => $map->accessRules->map(static fn (WeathermapMapAccess $r): array => [
                'subject_type' => $r->subject_type,
                'subject_id' => $r->subject_id,
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMapDetail(WeathermapMap $map): array
    {
        return [
            'id' => $map->id,
            'name' => $map->name,
            'slug' => $map->slug,
            'revision' => $map->revision,
            'schema_version' => $map->schema_version,
            'png_enabled' => $map->png_enabled,
            'last_rendered_at' => $map->last_rendered_at?->toIso8601String(),
            'last_render_error' => $map->last_render_error,
            'document' => $map->toDocument()->toArray(),
            'accessRules' => $map->relationLoaded('accessRules')
                ? $map->accessRules->map(static fn (WeathermapMapAccess $r): array => [
                    'subject_type' => $r->subject_type,
                    'subject_id' => $r->subject_id,
                ])->values()->all()
                : [],
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'map';
        }
        $slug = $base;
        $counter = 2;
        while (WeathermapMap::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function requireUser(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return $user;
    }
}
