<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class CapacityDef
{
    public const string SOURCE_IFSPEED = 'ifSpeed';

    public const string SOURCE_MANUAL = 'manual';

    public function __construct(
        public string $source = self::SOURCE_IFSPEED,
        public float $inBps = 1_000_000_000.0,
        public float $outBps = 1_000_000_000.0,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return new self;
        }

        $source = is_string($data['source'] ?? null) ? $data['source'] : self::SOURCE_IFSPEED;
        if (! in_array($source, [self::SOURCE_IFSPEED, self::SOURCE_MANUAL], true)) {
            $source = self::SOURCE_IFSPEED;
        }

        $inBps = is_numeric($data['inBps'] ?? null) && (float) $data['inBps'] > 0
            ? (float) $data['inBps']
            : 1_000_000_000.0;
        $outBps = is_numeric($data['outBps'] ?? null) && (float) $data['outBps'] > 0
            ? (float) $data['outBps']
            : $inBps;

        return new self($source, $inBps, $outBps);
    }

    /**
     * @return array{source: string, inBps: float, outBps: float}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'inBps' => $this->inBps,
            'outBps' => $this->outBps,
        ];
    }
}
