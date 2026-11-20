<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Data;

use BadRush\Weathermap\Document\MapSettings;
use BadRush\Weathermap\Document\TargetTerm;

final class PortDbSource implements DataSourceInterface
{
    public function __construct(
        private readonly PortRepositoryInterface $ports,
    ) {}

    public function supports(TargetTerm $term): bool
    {
        return $term->source === TargetTerm::SOURCE_PORT;
    }

    public function read(TargetTerm $term, MapSettings $settings, int $now): DataReading
    {
        if ($term->portId === null || $term->portId <= 0) {
            return DataReading::empty('Missing portId.');
        }

        $port = $this->ports->findById($term->portId);
        if ($port === null) {
            return DataReading::empty("Port #{$term->portId} not found.");
        }

        $isDown = ! $port->isDeviceUp || ! $port->isOperUp;
        $isStale = $port->pollTime !== null && ($now - $port->pollTime) > $settings->stalenessSeconds;

        // LibreNMS stores octets/sec; multiply by 8 for bits/sec
        $inBps = max(0.0, $port->inOctetsRate * 8.0);
        $outBps = max(0.0, $port->outOctetsRate * 8.0);

        return new DataReading(
            inBps: $inBps,
            outBps: $outBps,
            capacityInBps: $port->speedInBps > 0.0 ? $port->speedInBps : null,
            capacityOutBps: $port->speedOutBps > 0.0 ? $port->speedOutBps : null,
            timestamp: $port->pollTime ?? $now,
            isStale: $isStale,
            isDown: $isDown,
            primaryPortId: $port->portId,
            primaryDeviceId: $port->deviceId,
            portLabel: $port->label,
        );
    }
}
