<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class TargetTerm
{
    public const string SOURCE_PORT = 'port';

    public const string SOURCE_RRD = 'rrd';

    public const string SOURCE_STATIC = 'static';

    public function __construct(
        public string $source,
        public ?int $portId = null,
        public ?string $rrdPath = null,
        public string $dsIn = 'INOCTETS',
        public string $dsOut = 'OUTOCTETS',
        public string $cf = 'AVERAGE',
        public float $staticIn = 0.0,
        public float $staticOut = 0.0,
        public float $factor = 1.0,
        public bool $negate = false,
        public bool $reverse = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $source = is_string($data['source'] ?? null) ? $data['source'] : self::SOURCE_STATIC;
        if (! in_array($source, [self::SOURCE_PORT, self::SOURCE_RRD, self::SOURCE_STATIC], true)) {
            $source = self::SOURCE_STATIC;
        }

        $cf = strtoupper(is_string($data['cf'] ?? null) ? $data['cf'] : 'AVERAGE');
        if (! in_array($cf, ['AVERAGE', 'MAX', 'MIN', 'LAST'], true)) {
            $cf = 'AVERAGE';
        }

        return new self(
            source: $source,
            portId: isset($data['portId']) && is_numeric($data['portId']) ? (int) $data['portId'] : null,
            rrdPath: is_string($data['rrdPath'] ?? null) ? $data['rrdPath'] : null,
            dsIn: is_string($data['dsIn'] ?? null) && $data['dsIn'] !== '' ? $data['dsIn'] : 'INOCTETS',
            dsOut: is_string($data['dsOut'] ?? null) && $data['dsOut'] !== '' ? $data['dsOut'] : 'OUTOCTETS',
            cf: $cf,
            staticIn: is_numeric($data['staticIn'] ?? null) ? (float) $data['staticIn'] : 0.0,
            staticOut: is_numeric($data['staticOut'] ?? null) ? (float) $data['staticOut'] : 0.0,
            factor: is_numeric($data['factor'] ?? null) ? (float) $data['factor'] : 1.0,
            negate: (bool) ($data['negate'] ?? false),
            reverse: (bool) ($data['reverse'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'portId' => $this->portId,
            'rrdPath' => $this->rrdPath,
            'dsIn' => $this->dsIn,
            'dsOut' => $this->dsOut,
            'cf' => $this->cf,
            'staticIn' => $this->staticIn,
            'staticOut' => $this->staticOut,
            'factor' => $this->factor,
            'negate' => $this->negate,
            'reverse' => $this->reverse,
        ];
    }
}
