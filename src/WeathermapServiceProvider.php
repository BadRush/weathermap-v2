<?php

declare(strict_types=1);

namespace BadRush\Weathermap;

use BadRush\Weathermap\Console\ImportCommand;
use BadRush\Weathermap\Console\RenderCommand;
use BadRush\Weathermap\Console\SyncAssetsCommand;
use BadRush\Weathermap\Data\LibreNmsPortRepository;
use BadRush\Weathermap\Data\PortDbSource;
use BadRush\Weathermap\Data\PortRepositoryInterface;
use BadRush\Weathermap\Data\RrdSource;
use BadRush\Weathermap\Data\StaticSource;
use BadRush\Weathermap\Data\TargetResolver;
use BadRush\Weathermap\Hooks\MenuEntry;
use BadRush\Weathermap\Hooks\Settings;
use BadRush\Weathermap\Hooks\SinglePage;
use BadRush\Weathermap\Import\ConfImporter;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Policies\WeathermapMapPolicy;
use BadRush\Weathermap\Render\GdRenderer;
use BadRush\Weathermap\Render\SceneBuilder;
use BadRush\Weathermap\Services\AssetStorageService;
use BadRush\Weathermap\Services\MapRendererService;
use BadRush\Weathermap\Support\LibreNmsBridge;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

class WeathermapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/weathermap.php', 'weathermap');

        $this->app->singleton(PortRepositoryInterface::class, LibreNmsPortRepository::class);
        $this->app->singleton(WeathermapMapPolicy::class);
        $this->app->singleton(GdRenderer::class, static fn (): GdRenderer => new GdRenderer);
        $this->app->singleton(ConfImporter::class);

        $this->app->singleton(TargetResolver::class, function ($app): TargetResolver {
            /** @var PortRepositoryInterface $ports */
            $ports = $app->make(PortRepositoryInterface::class);

            return new TargetResolver([
                new PortDbSource($ports),
                new RrdSource(
                    rrdDir: LibreNmsBridge::rrdDir(),
                    rrdtoolBin: LibreNmsBridge::rrdtoolBinary(),
                    rrdcached: LibreNmsBridge::rrdcached(),
                ),
                new StaticSource,
            ]);
        });

        $this->app->singleton(SceneBuilder::class);
        $this->app->singleton(MapRendererService::class);
        $this->app->singleton(AssetStorageService::class);
    }

    public function boot(): void
    {
        $pluginName = LibreNmsBridge::PLUGIN_NAME;

        if ($this->app->bound(PluginManagerInterface::class)) {
            /** @var PluginManagerInterface $pluginManager */
            $pluginManager = $this->app->make(PluginManagerInterface::class);
            $pluginManager->publishHook($pluginName, MenuEntryHook::class, MenuEntry::class);
            $pluginManager->publishHook($pluginName, SinglePageHook::class, SinglePage::class);
            $pluginManager->publishHook($pluginName, SettingsHook::class, Settings::class);

            if (! $pluginManager->pluginEnabled($pluginName)) {
                return;
            }
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'weathermap');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'weathermap');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        Gate::policy(WeathermapMap::class, WeathermapMapPolicy::class);
        LibreNmsBridge::ensureEditPermissionExists();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RenderCommand::class,
                ImportCommand::class,
                SyncAssetsCommand::class,
            ]);

            if ((bool) config('weathermap.schedule_enabled', true)) {
                $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                    $schedule->command('weathermap:render')
                        ->everyFiveMinutes()
                        ->withoutOverlapping()
                        ->onOneServer();
                });
            }
        }
    }
}
