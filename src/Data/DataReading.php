<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Data;

final readonly class DataReading
{
    public function __construct(
        public ?float $inBps,
        public ?float $outBps,
        public ?float $capacityInBps = null,
        public ?float $capacityOutBps = null,
        public ?int $timestamp = null,
        public bool $isStale = false,
        public bool $isDown = false,
        public ?int $primaryPortId = null,
        public ?int $primaryDeviceId = null,
        public ?string $portLabel = null,
        public ?string $error = null,
    ) {}

    public static function empty(?string $error = null): self
    {
        return new self(
            inBps: null,
            outBps: null,
            isStale: true,
            error: $error,
        );
    }
}
