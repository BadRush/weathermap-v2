<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Services;

use BadRush\Weathermap\Models\WeathermapAsset;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Render\GdRenderer;
use BadRush\Weathermap\Render\Scene;
use BadRush\Weathermap\Render\SceneBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

final class MapRendererService
{
    public const int SCENE_CACHE_TTL = 30;

    public function __construct(
        private readonly SceneBuilder $sceneBuilder,
        private readonly GdRenderer $gdRenderer,
    ) {}

    public function getScene(WeathermapMap $map, bool $fresh = false): Scene
    {
        $cacheKey = self::sceneCacheKey($map);
        if (! $fresh) {
            $cached = Cache::get($cacheKey);
            if ($cached instanceof Scene) {
                return $cached;
            }
        }

        $scene = $this->sceneBuilder->build($map->toDocument());
        Cache::put($cacheKey, $scene, self::SCENE_CACHE_TTL);

        return $scene;
    }

    public function invalidateSceneCache(WeathermapMap $map): void
    {
        Cache::forget(self::sceneCacheKey($map));
    }

    public static function sceneCacheKey(WeathermapMap $map): string
    {
        return "weathermap:scene:{$map->id}:r{$map->revision}";
    }

    public function pngPath(WeathermapMap $map): string
    {
        return $this->storageBaseDir().'/maps/'.$map->id.'.png';
    }

    public function thumbnailPath(WeathermapMap $map): string
    {
        return $this->storageBaseDir().'/maps/'.$map->id.'-thumb.png';
    }

    /**
     * Render and persist both the full PNG and thumbnail PNG for a map outside the webroot.
     */
    public function renderPngToStorage(WeathermapMap $map): bool
    {
        if (! GdRenderer::hasGd()) {
            $map->last_render_error = 'PHP GD extension is not enabled.';
            $map->save();

            return false;
        }

        try {
            $scene = $this->sceneBuilder->build($map->toDocument());
            $png = $this->gdRenderer->renderPng($scene, fn (int $assetId): ?string => $this->resolveAssetDiskPath($assetId));
            $thumb = $this->gdRenderer->renderThumbnail($png);

            $dir = dirname($this->pngPath($map));
            File::ensureDirectoryExists($dir, 0750);

            // Atomic write via temp file + rename so concurrent readers never observe partial PNGs
            $tmpPng = $this->pngPath($map).'.tmp.'.uniqid('', true);
            $tmpThumb = $this->thumbnailPath($map).'.tmp.'.uniqid('', true);
            file_put_contents($tmpPng, $png);
            file_put_contents($tmpThumb, $thumb);
            rename($tmpPng, $this->pngPath($map));
            rename($tmpThumb, $this->thumbnailPath($map));

            $map->last_rendered_at = now();
            $map->last_render_error = null;
            $map->save();

            return true;
        } catch (Throwable $e) {
            $map->last_render_error = $e->getMessage();
            $map->save();

            return false;
        }
    }

    public function deleteRenderedFiles(WeathermapMap $map): void
    {
        @unlink($this->pngPath($map));
        @unlink($this->thumbnailPath($map));
    }

    public function resolveAssetDiskPath(int $assetId): ?string
    {
        $asset = WeathermapAsset::query()->find($assetId);
        if ($asset === null) {
            return null;
        }

        $full = $this->storageBaseDir().'/'.ltrim($asset->path, '/');

        return is_file($full) ? $full : null;
    }

    public function storageBaseDir(): string
    {
        $configured = config('weathermap.storage_dir');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return storage_path('app/weathermap');
    }
}
