<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class MapSettings
{
    public function __construct(
        public int $refreshSeconds = 60,
        public int $rrdPeriodSeconds = 900,
        public int $stalenessSeconds = 660,
        public int $gridSize = 20,
        public bool $snapToGrid = true,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return new self;
        }

        return new self(
            refreshSeconds: max(10, min(3600, (int) ($data['refreshSeconds'] ?? 60))),
            rrdPeriodSeconds: max(300, min(86400, (int) ($data['rrdPeriodSeconds'] ?? 900))),
            stalenessSeconds: max(60, min(86400, (int) ($data['stalenessSeconds'] ?? 660))),
            gridSize: max(5, min(100, (int) ($data['gridSize'] ?? 20))),
            snapToGrid: (bool) ($data['snapToGrid'] ?? true),
        );
    }

    /**
     * @return array{refreshSeconds: int, rrdPeriodSeconds: int, stalenessSeconds: int, gridSize: int, snapToGrid: bool}
     */
    public function toArray(): array
    {
        return [
            'refreshSeconds' => $this->refreshSeconds,
            'rrdPeriodSeconds' => $this->rrdPeriodSeconds,
            'stalenessSeconds' => $this->stalenessSeconds,
            'gridSize' => $this->gridSize,
            'snapToGrid' => $this->snapToGrid,
        ];
    }
}
