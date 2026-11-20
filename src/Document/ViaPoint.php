<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class ViaPoint
{
    public function __construct(
        public float $x,
        public float $y,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            x: is_numeric($data['x'] ?? null) ? (float) $data['x'] : 0.0,
            y: is_numeric($data['y'] ?? null) ? (float) $data['y'] : 0.0,
        );
    }

    /**
     * @return array{x: float, y: float}
     */
    public function toArray(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
        ];
    }
}
