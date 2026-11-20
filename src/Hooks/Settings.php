<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Hooks;

use BadRush\Weathermap\Policies\WeathermapMapPolicy;
use BadRush\Weathermap\Render\GdRenderer;
use BadRush\Weathermap\Support\LibreNmsBridge;
use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

final class Settings implements SettingsHook
{
    public function authorize(Authenticatable $user, WeathermapMapPolicy $policy): bool
    {
        return $policy->canEdit($user);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        return [
            'title' => __('weathermap::weathermap.settings_title'),
            'content_view' => 'weathermap::settings',
            'settings' => array_merge([
                'default_refresh_seconds' => 60,
                'default_rrd_period' => 900,
                'editor_roles' => '',
                'timezone' => LibreNmsBridge::DEFAULT_TIMEZONE,
                'png_rendering_enabled' => true,
            ], $settings),
            'hasGd' => GdRenderer::hasGd(),
            'hasFreeType' => GdRenderer::hasFreeType(),
        ];
    }
}
