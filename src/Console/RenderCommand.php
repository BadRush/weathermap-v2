<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Console;

use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Render\GdRenderer;
use BadRush\Weathermap\Services\MapRendererService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class RenderCommand extends Command
{
    protected $signature = 'weathermap:render
        {--map= : Render only a specific map ID or slug}
        {--force : Render even if png_enabled is false}';

    protected $description = 'Render Network Weathermap maps to PNG images and thumbnails for NOC screens and embeds';

    public function handle(MapRendererService $renderer): int
    {
        if (! GdRenderer::hasGd()) {
            $this->error('PHP GD extension is not installed; skipping PNG rendering.');

            return self::FAILURE;
        }

        $mapFilter = $this->option('map');
        $force = (bool) $this->option('force');

        $query = WeathermapMap::query()->orderBy('id');
        if (is_string($mapFilter) && $mapFilter !== '') {
            if (ctype_digit($mapFilter)) {
                $query->where('id', (int) $mapFilter);
            } else {
                $query->where('slug', $mapFilter);
            }
        } elseif (! $force) {
            $query->where('png_enabled', true);
        }

        /** @var Collection<int, WeathermapMap> $maps */
        $maps = $query->get();
        if ($maps->isEmpty()) {
            $this->info('No maps matched for rendering.');

            return self::SUCCESS;
        }

        $rendered = 0;
        $failed = 0;

        foreach ($maps as $map) {
            if ($renderer->renderPngToStorage($map)) {
                $rendered++;
                $this->line("<info>Rendered</info> #{$map->id} {$map->name}");
            } else {
                $failed++;
                $this->error("Failed #{$map->id} {$map->name}: ".($map->last_render_error ?? 'Unknown error'));
            }
        }

        $this->info("Weathermap render complete: {$rendered} succeeded, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
