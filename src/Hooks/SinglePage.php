<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Hooks;

use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Policies\WeathermapMapPolicy;
use BadRush\Weathermap\Render\GdRenderer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

final class SinglePage implements SinglePageHook
{
    public function authorize(Authenticatable $user): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings, Authenticatable $user, WeathermapMapPolicy $policy): array
    {
        $maps = [];
        try {
            /** @var Collection<int, WeathermapMap> $all */
            $all = WeathermapMap::query()->orderBy('name')->get();
            $maps = $all
                ->filter(static fn (WeathermapMap $map): bool => $policy->view($user, $map))
                ->values()
                ->all();
        } catch (\Throwable) {
            // Table may not be migrated yet
        }

        return [
            'title' => __('weathermap::weathermap.title'),
            'content_view' => 'weathermap::page',
            'maps' => $maps,
            'canEdit' => $policy->canEdit($user),
            'hasFreeType' => GdRenderer::hasFreeType(),
        ];
    }
}
