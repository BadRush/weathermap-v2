<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Data;

/**
 * Thin read-only snapshot of a LibreNMS port row so domain logic stays decoupled from Eloquent internals.
 */
final readonly class PortSnapshot
{
    public function __construct(
        public int $portId,
        public int $deviceId,
        public string $label,
        public float $inOctetsRate,
        public float $outOctetsRate,
        public float $speedInBps,
        public float $speedOutBps,
        public bool $isOperUp,
        public bool $isDeviceUp,
        public ?int $pollTime,
    ) {}
}
