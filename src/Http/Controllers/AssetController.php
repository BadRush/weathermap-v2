<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Http\Controllers;

use BadRush\Weathermap\Models\WeathermapAsset;
use BadRush\Weathermap\Policies\WeathermapMapPolicy;
use BadRush\Weathermap\Services\AssetStorageService;
use BadRush\Weathermap\Services\MapRendererService;
use BadRush\Weathermap\Support\LibreNmsBridge;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use RuntimeException;

final class AssetController extends Controller
{
    public function __construct(
        private readonly WeathermapMapPolicy $policy,
        private readonly AssetStorageService $storage,
        private readonly MapRendererService $renderer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($this->policy->create($user), 403);

        $kind = $request->query('kind');
        $query = WeathermapAsset::query()->orderByDesc('id');
        if (is_string($kind) && in_array($kind, [WeathermapAsset::KIND_BACKGROUND, WeathermapAsset::KIND_ICON], true)) {
            $query->where('kind', $kind);
        }

        /** @var Collection<int, WeathermapAsset> $rows */
        $rows = $query->limit(100)->get();
        $assets = $rows->map(static fn (WeathermapAsset $a): array => [
            'id' => $a->id,
            'kind' => $a->kind,
            'original_name' => $a->original_name,
            'mime' => $a->mime,
            'size' => $a->size,
            'url' => "/weathermap/api/assets/{$a->id}",
        ]);

        return response()->json(['assets' => $assets]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($this->policy->create($user), 403);

        $request->validate([
            'kind' => ['required', 'in:background,icon'],
            'file' => ['required', 'file', 'max:2048'],
        ]);

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json(['message' => 'Uploaded file missing.'], 422);
        }

        try {
            $asset = $this->storage->storeUpload($file, (string) $request->input('kind'), LibreNmsBridge::userId($user));
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'asset' => [
                'id' => $asset->id,
                'kind' => $asset->kind,
                'original_name' => $asset->original_name,
                'mime' => $asset->mime,
                'size' => $asset->size,
                'url' => "/weathermap/api/assets/{$asset->id}",
            ],
        ], 201);
    }

    public function show(Request $request, WeathermapAsset $asset): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $path = $this->renderer->resolveAssetDiskPath($asset->id);
        if ($path === null || ! is_file($path)) {
            abort(404);
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            abort(500);
        }

        $etag = '"'.$asset->sha256.'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
