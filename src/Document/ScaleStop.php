<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class ScaleStop
{
    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb1
     * @param  array{0: int, 1: int, 2: int}|null  $rgb2
     */
    public function __construct(
        public float $min,
        public float $max,
        public array $rgb1,
        public ?array $rgb2 = null,
        public ?string $label = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            min: is_numeric($data['min'] ?? null) ? (float) $data['min'] : 0.0,
            max: is_numeric($data['max'] ?? null) ? (float) $data['max'] : 100.0,
            rgb1: self::normalizeRgb($data['rgb1'] ?? [192, 192, 192]),
            rgb2: isset($data['rgb2']) && is_array($data['rgb2']) ? self::normalizeRgb($data['rgb2']) : null,
            label: is_string($data['label'] ?? null) ? $data['label'] : null,
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function normalizeRgb(mixed $raw): array
    {
        if (! is_array($raw) || count($raw) < 3) {
            return [192, 192, 192];
        }

        return [
            max(0, min(255, (int) $raw[0])),
            max(0, min(255, (int) $raw[1])),
            max(0, min(255, (int) $raw[2])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'min' => $this->min,
            'max' => $this->max,
            'rgb1' => $this->rgb1,
            'rgb2' => $this->rgb2,
            'label' => $this->label,
        ];
    }
}
