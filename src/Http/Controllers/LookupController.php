<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Http\Controllers;

use BadRush\Weathermap\Policies\WeathermapMapPolicy;
use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Provides permission-filtered Device & Port pickers for the Drag & Drop Editor,
 * honoring LibreNMS's `Device::hasAccess($user)` and `Port::hasAccess($user)` scopes.
 */
final class LookupController extends Controller
{
    public function __construct(
        private readonly WeathermapMapPolicy $policy,
    ) {}

    public function devices(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($this->policy->create($user), 403);

        $q = trim($request->string('q')->toString());
        $limit = max(1, min(50, $request->integer('limit', 25)));

        if (class_exists(Device::class)) {
            $deviceClass = Device::class;
            $query = $deviceClass::query();
            $model = $query->getModel();
            if (is_object($model) && method_exists($model, 'scopeHasAccess')) {
                $query->hasAccess($user);
            }
            if ($q !== '') {
                $like = '%'.$q.'%';
                $query->where(static function ($sub) use ($like): void {
                    $sub->where('hostname', 'like', $like)
                        ->orWhere('sysName', 'like', $like)
                        ->orWhere('display', 'like', $like)
                        ->orWhere('ip', 'like', $like);
                });
            }

            $items = $query->orderBy('hostname')->limit($limit)->get()->map(static fn ($d): array => [
                'device_id' => (int) $d->device_id,
                'hostname' => (string) $d->hostname,
                'display' => (string) ($d->display ?? $d->hostname),
                'os' => (string) ($d->os ?? ''),
                'status' => (bool) ($d->status ?? true),
            ])->values()->all();

            return response()->json(['devices' => $items]);
        }

        $query = DB::table('devices');
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(static function ($sub) use ($like): void {
                $sub->where('hostname', 'like', $like)
                    ->orWhere('sysName', 'like', $like);
            });
        }

        $items = $query->orderBy('hostname')->limit($limit)->get()->map(static fn (object $d): array => [
            'device_id' => (int) $d->device_id,
            'hostname' => (string) $d->hostname,
            'display' => (string) ($d->sysName ?: $d->hostname),
            'os' => (string) ($d->os ?? ''),
            'status' => (bool) ($d->status ?? true),
        ])->values()->all();

        return response()->json(['devices' => $items]);
    }

    public function ports(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($this->policy->create($user), 403);

        $q = trim($request->string('q')->toString());
        $deviceId = $request->query('device_id');
        $limit = max(1, min(60, $request->integer('limit', 30)));

        if (class_exists(Port::class)) {
            $portClass = Port::class;
            $query = $portClass::query()->with('device');
            $model = $query->getModel();
            if (is_object($model) && method_exists($model, 'scopeHasAccess')) {
                $query->hasAccess($user);
            }
            if (is_numeric($deviceId)) {
                $query->where('ports.device_id', (int) $deviceId);
            }
            if ($q !== '') {
                $like = '%'.$q.'%';
                $query->where(static function ($sub) use ($like): void {
                    $sub->where('ifName', 'like', $like)
                        ->orWhere('ifDescr', 'like', $like)
                        ->orWhere('ifAlias', 'like', $like)
                        ->orWhereHas('device', static function ($dq) use ($like): void {
                            $dq->where('hostname', 'like', $like)->orWhere('display', 'like', $like);
                        });
                });
            }

            $items = $query->orderBy('port_id')->limit($limit)->get()->map(static fn ($p): array => [
                'port_id' => (int) $p->port_id,
                'device_id' => (int) $p->device_id,
                'hostname' => is_object($p->device) ? (string) ($p->device->hostname ?? '') : '',
                'ifName' => (string) ($p->ifName ?: $p->ifDescr),
                'ifAlias' => (string) ($p->ifAlias ?? ''),
                'ifSpeed' => (float) ($p->ifSpeed ?? 0),
                'ifOperStatus' => is_object($p->ifOperStatus) && isset($p->ifOperStatus->value)
                    ? (string) $p->ifOperStatus->value
                    : (string) ($p->ifOperStatus ?? 'up'),
            ])->values()->all();

            return response()->json(['ports' => $items]);
        }

        $query = DB::table('ports')
            ->leftJoin('devices', 'devices.device_id', '=', 'ports.device_id')
            ->select([
                'ports.port_id',
                'ports.device_id',
                'devices.hostname',
                'ports.ifName',
                'ports.ifDescr',
                'ports.ifAlias',
                'ports.ifSpeed',
                'ports.ifOperStatus',
            ]);

        if (is_numeric($deviceId)) {
            $query->where('ports.device_id', (int) $deviceId);
        }
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(static function ($sub) use ($like): void {
                $sub->where('ports.ifName', 'like', $like)
                    ->orWhere('ports.ifDescr', 'like', $like)
                    ->orWhere('ports.ifAlias', 'like', $like)
                    ->orWhere('devices.hostname', 'like', $like);
            });
        }

        $items = $query->orderBy('ports.port_id')->limit($limit)->get()->map(static fn (object $p): array => [
            'port_id' => (int) $p->port_id,
            'device_id' => (int) $p->device_id,
            'hostname' => (string) ($p->hostname ?? ''),
            'ifName' => (string) ($p->ifName ?: ($p->ifDescr ?? '')),
            'ifAlias' => (string) ($p->ifAlias ?? ''),
            'ifSpeed' => (float) ($p->ifSpeed ?? 0),
            'ifOperStatus' => (string) ($p->ifOperStatus ?? 'up'),
        ])->values()->all();

        return response()->json(['ports' => $items]);
    }

    public function subjects(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($this->policy->create($user), 403);

        $roles = ['admin', 'global-read', 'user'];
        if (class_exists(Role::class)) {
            try {
                $roles = Role::query()->orderBy('name')->pluck('name')->all();
            } catch (\Throwable) {
                // Keep defaults if roles table not populated
            }
        }

        $users = [];
        try {
            $userTable = class_exists(User::class) ? (new User)->getTable() : 'users';
            $pk = class_exists(User::class) ? (new User)->getKeyName() : 'id';
            $rows = DB::table($userTable)->limit(200)->get();
            foreach ($rows as $row) {
                $id = $row->{$pk} ?? null;
                if ($id === null) {
                    continue;
                }
                $label = (string) ($row->username ?? $row->name ?? $row->email ?? ('User #'.$id));
                $users[] = ['id' => (string) $id, 'label' => $label];
            }
        } catch (\Throwable) {
            // Optional
        }

        return response()->json([
            'roles' => $roles,
            'users' => $users,
        ]);
    }
}
