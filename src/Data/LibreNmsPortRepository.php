<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Data;

use App\Models\Port;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reads live port traffic metrics directly from LibreNMS's `ports` and `devices` tables.
 * Uses App\Models\Port when available inside LibreNMS (to leverage getSpeeds()/getLabel()),
 * falling back to direct DB query for compatibility and isolated testing.
 */
final class LibreNmsPortRepository implements PortRepositoryInterface
{
    public function findById(int $portId): ?PortSnapshot
    {
        if (class_exists(Port::class)) {
            $port = Port::query()->with('device')->find($portId);
            if (! $port instanceof Model) {
                return null;
            }

            $speedIn = 0.0;
            $speedOut = 0.0;
            if (method_exists($port, 'getSpeeds')) {
                [$sOut, $sIn] = $port->getSpeeds();
                $speedIn = (float) $sIn;
                $speedOut = (float) $sOut;
            } else {
                $speed = (float) ($port->getAttribute('ifSpeed') ?? 0);
                $speedIn = $speed;
                $speedOut = $speed;
            }

            $operStatus = $port->getAttribute('ifOperStatus');
            $isOperUp = is_object($operStatus) && isset($operStatus->value)
                ? strtolower((string) $operStatus->value) === 'up'
                : strtolower((string) $operStatus) === 'up';

            $device = $port->getRelationValue('device');
            $isDeviceUp = ! $device instanceof Model ? true : (bool) ($device->getAttribute('status') ?? true);

            $ifName = $port->getAttribute('ifName');
            $ifDescr = $port->getAttribute('ifDescr');
            $label = method_exists($port, 'getLabel')
                ? (string) $port->getLabel()
                : (string) ($ifName ?: $ifDescr ?: ('Port #'.$portId));

            $pollTime = $port->getAttribute('poll_time');

            return new PortSnapshot(
                portId: (int) $port->getAttribute('port_id'),
                deviceId: (int) $port->getAttribute('device_id'),
                label: $label,
                inOctetsRate: (float) ($port->getAttribute('ifInOctets_rate') ?? 0.0),
                outOctetsRate: (float) ($port->getAttribute('ifOutOctets_rate') ?? 0.0),
                speedInBps: $speedIn,
                speedOutBps: $speedOut,
                isOperUp: $isOperUp,
                isDeviceUp: $isDeviceUp,
                pollTime: is_numeric($pollTime) ? (int) $pollTime : null,
            );
        }

        $row = DB::table('ports')
            ->leftJoin('devices', 'devices.device_id', '=', 'ports.device_id')
            ->where('ports.port_id', $portId)
            ->select([
                'ports.port_id',
                'ports.device_id',
                'ports.ifName',
                'ports.ifDescr',
                'ports.ifAlias',
                'ports.ifInOctets_rate',
                'ports.ifOutOctets_rate',
                'ports.ifSpeed',
                'ports.ifOperStatus',
                'ports.poll_time',
                'devices.status as device_status',
                'devices.hostname as device_hostname',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        $speed = (float) ($row->ifSpeed ?? 0.0);
        $name = (string) ($row->ifName ?: ($row->ifDescr ?: ('Port #'.$portId)));
        $hostname = is_string($row->device_hostname ?? null) && $row->device_hostname !== '' ? $row->device_hostname.' - ' : '';

        return new PortSnapshot(
            portId: (int) $row->port_id,
            deviceId: (int) $row->device_id,
            label: $hostname.$name,
            inOctetsRate: (float) ($row->ifInOctets_rate ?? 0.0),
            outOctetsRate: (float) ($row->ifOutOctets_rate ?? 0.0),
            speedInBps: $speed,
            speedOutBps: $speed,
            isOperUp: strtolower((string) ($row->ifOperStatus ?? 'up')) === 'up',
            isDeviceUp: ($row->device_status ?? 1) ? true : false,
            pollTime: is_numeric($row->poll_time ?? null) ? (int) $row->poll_time : null,
        );
    }
}
