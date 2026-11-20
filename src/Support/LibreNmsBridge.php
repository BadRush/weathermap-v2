<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Support;

use App\Facades\LibrenmsConfig;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Thin compatibility adapter bridging LibreNMS 26.x host APIs (LibrenmsConfig, Spatie RBAC,
 * PluginManager settings) so the package stays decoupled and cleanly testable in isolation.
 */
final class LibreNmsBridge
{
    public const string PLUGIN_NAME = 'weathermapV2';

    public const string EDIT_PERMISSION = 'weathermap.edit';

    public const string DEFAULT_TIMEZONE = 'Asia/Jakarta';

    public static function config(string $key, mixed $default = null): mixed
    {
        if (class_exists(LibrenmsConfig::class)) {
            return LibrenmsConfig::get($key, $default);
        }

        return config("weathermap.{$key}", $default);
    }

    public static function rrdDir(): string
    {
        $val = self::config('rrd_dir', storage_path('app/weathermap/rrd'));

        return is_string($val) && $val !== '' ? $val : storage_path('app/weathermap/rrd');
    }

    public static function rrdtoolBinary(): string
    {
        $val = self::config('rrdtool', 'rrdtool');

        return is_string($val) && $val !== '' ? $val : 'rrdtool';
    }

    public static function rrdcached(): ?string
    {
        $val = self::config('rrdcached', null);

        return is_string($val) && $val !== '' ? $val : null;
    }

    /**
     * Resolve the IANA timezone used for map "Updated: ..." timestamps.
     * Priority: Plugin Settings -> config('weathermap.timezone') / WEATHERMAP_TIMEZONE -> Asia/Jakarta (WIB).
     */
    public static function timezone(): string
    {
        $candidates = [];

        try {
            if (app()->bound(PluginManagerInterface::class)) {
                $settings = self::pluginSettings();
                if (is_string($settings['timezone'] ?? null) && trim($settings['timezone']) !== '') {
                    $candidates[] = trim($settings['timezone']);
                }
            }
        } catch (Throwable) {
            // Ignore when invoked outside the Laravel container
        }

        try {
            if (app()->bound('config')) {
                $configured = config('weathermap.timezone');
                if (is_string($configured) && trim($configured) !== '') {
                    $candidates[] = trim($configured);
                }
            }
        } catch (Throwable) {
            // Ignore when invoked outside the Laravel container
        }

        $candidates[] = self::DEFAULT_TIMEZONE;

        foreach ($candidates as $tz) {
            try {
                new DateTimeZone($tz);

                return $tz;
            } catch (Throwable) {
                continue;
            }
        }

        return self::DEFAULT_TIMEZONE;
    }

    /**
     * @return array<string, mixed>
     */
    public static function pluginSettings(): array
    {
        if (app()->bound(PluginManagerInterface::class)) {
            /** @var PluginManagerInterface $pm */
            $pm = app(PluginManagerInterface::class);
            try {
                return $pm->getSettings(self::PLUGIN_NAME);
            } catch (Throwable) {
                return [];
            }
        }

        $fallback = config('weathermap.plugin_settings', []);

        return is_array($fallback) ? $fallback : [];
    }

    public static function userId(Authenticatable $user): ?int
    {
        $id = $user->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Return role names associated with a user (supports Spatie HasRoles and simple test user models).
     *
     * @return list<string>
     */
    public static function userRoles(Authenticatable $user): array
    {
        if (method_exists($user, 'getRoleNames')) {
            /** @var iterable<mixed> $names */
            $names = $user->getRoleNames();
            $out = [];
            foreach ($names as $name) {
                if (is_string($name) && $name !== '') {
                    $out[] = $name;
                }
            }

            return $out;
        }

        if ($user instanceof Model) {
            $rolesAttr = $user->getAttribute('roles');
            if (is_array($rolesAttr)) {
                return array_values(array_filter($rolesAttr, static fn (mixed $r): bool => is_string($r) && $r !== ''));
            }
        }

        return [];
    }

    public static function userHasRole(Authenticatable $user, string $role): bool
    {
        if (method_exists($user, 'hasRole')) {
            return (bool) $user->hasRole($role);
        }

        return in_array($role, self::userRoles($user), true);
    }

    public static function userHasPermission(Authenticatable $user, string $permission): bool
    {
        if (method_exists($user, 'hasPermissionTo')) {
            try {
                return (bool) $user->hasPermissionTo($permission);
            } catch (Throwable) {
                return false;
            }
        }

        if ($user instanceof Model) {
            $perms = $user->getAttribute('permissions');
            if (is_array($perms)) {
                return in_array($permission, $perms, true);
            }
        }

        return false;
    }

    /**
     * Ensure the custom Spatie permission `weathermap.edit` exists inside LibreNMS without failing
     * if migrations haven't run yet or Spatie Permission is not installed.
     */
    public static function ensureEditPermissionExists(): void
    {
        if (! class_exists(Permission::class)) {
            return;
        }

        try {
            $perm = Permission::firstOrCreate(
                ['name' => self::EDIT_PERMISSION, 'guard_name' => 'web'],
            );

            if (class_exists(Role::class)) {
                $admin = Role::where('name', 'admin')->first();
                if (is_object($admin) && method_exists($admin, 'givePermissionTo')) {
                    $admin->givePermissionTo($perm);
                }
            }
        } catch (Throwable) {
            // Database may not be migrated yet during composer/package discovery
        }
    }
}
