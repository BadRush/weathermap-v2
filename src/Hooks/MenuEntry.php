<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Hooks;

use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Policies\WeathermapMapPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

final class MenuEntry implements MenuEntryHook
{
    public function authorize(Authenticatable $user): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function handle(string $pluginName, array $settings, Authenticatable $user, WeathermapMapPolicy $policy): array
    {
        $maps = [];
        try {
            /** @var Collection<int, WeathermapMap> $all */
            $all = WeathermapMap::query()->orderBy('name')->get();
            $maps = $all
                ->filter(static fn (WeathermapMap $map): bool => $policy->view($user, $map))
                ->take(20)
                ->values()
                ->all();
        } catch (\Throwable) {
            // Table may not be migrated yet
        }

        return ['weathermap::menu', [
            'pluginName' => $pluginName,
            'maps' => $maps,
            'canEdit' => $policy->canEdit($user),
        ]];
    }
}
