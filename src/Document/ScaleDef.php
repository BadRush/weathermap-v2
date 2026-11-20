<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class ScaleDef
{
    /**
     * @param  list<ScaleStop>  $stops
     */
    public function __construct(
        public string $name = 'DEFAULT',
        public array $stops = [],
    ) {}

    /**
     * Classic PHP Network Weathermap 0.98a default color scale.
     */
    public static function classicDefault(): self
    {
        return new self(
            name: 'DEFAULT',
            stops: [
                new ScaleStop(0.0, 0.0, [192, 192, 192]),
                new ScaleStop(0.0, 1.0, [255, 255, 255]),
                new ScaleStop(1.0, 10.0, [140, 0, 255]),
                new ScaleStop(10.0, 25.0, [32, 32, 255]),
                new ScaleStop(25.0, 40.0, [0, 192, 255]),
                new ScaleStop(40.0, 55.0, [0, 240, 0]),
                new ScaleStop(55.0, 70.0, [240, 240, 0]),
                new ScaleStop(70.0, 85.0, [255, 192, 0]),
                new ScaleStop(85.0, 100.0, [255, 0, 0]),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $name = is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : 'DEFAULT';
        $rawStops = $data['stops'] ?? [];
        $stops = [];
        if (is_array($rawStops)) {
            foreach ($rawStops as $stop) {
                if (is_array($stop)) {
                    $stops[] = ScaleStop::fromArray($stop);
                }
            }
        }

        return new self($name, $stops);
    }

    /**
     * @return array{name: string, stops: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'stops' => array_map(static fn (ScaleStop $s): array => $s->toArray(), $this->stops),
        ];
    }
}
