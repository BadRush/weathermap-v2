<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class CanvasConfig
{
    public function __construct(
        public int $width = 1000,
        public int $height = 640,
        public string $bgColor = '#0f172a',
        public ?int $backgroundAssetId = null,
        public string $title = 'Network Weathermap',
        public int $titleX = 20,
        public int $titleY = 28,
        public int $titleFontSize = 18,
        public string $titleColor = '#f8fafc',
        public bool $showTimestamp = true,
        public int $timestampX = 20,
        public int $timestampY = 50,
        public int $timestampFontSize = 10,
        public string $timestampColor = '#94a3b8',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            width: max(100, min(8000, (int) ($data['width'] ?? 1000))),
            height: max(100, min(8000, (int) ($data['height'] ?? 640))),
            bgColor: is_string($data['bgColor'] ?? null) ? $data['bgColor'] : '#0f172a',
            backgroundAssetId: isset($data['backgroundAssetId']) && is_numeric($data['backgroundAssetId'])
                ? (int) $data['backgroundAssetId']
                : null,
            title: is_string($data['title'] ?? null) ? $data['title'] : 'Network Weathermap',
            titleX: (int) ($data['titleX'] ?? 20),
            titleY: (int) ($data['titleY'] ?? 28),
            titleFontSize: max(6, min(72, (int) ($data['titleFontSize'] ?? 18))),
            titleColor: is_string($data['titleColor'] ?? null) ? $data['titleColor'] : '#f8fafc',
            showTimestamp: (bool) ($data['showTimestamp'] ?? true),
            timestampX: (int) ($data['timestampX'] ?? 20),
            timestampY: (int) ($data['timestampY'] ?? 50),
            timestampFontSize: max(6, min(48, (int) ($data['timestampFontSize'] ?? 10))),
            timestampColor: is_string($data['timestampColor'] ?? null) ? $data['timestampColor'] : '#94a3b8',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'bgColor' => $this->bgColor,
            'backgroundAssetId' => $this->backgroundAssetId,
            'title' => $this->title,
            'titleX' => $this->titleX,
            'titleY' => $this->titleY,
            'titleFontSize' => $this->titleFontSize,
            'titleColor' => $this->titleColor,
            'showTimestamp' => $this->showTimestamp,
            'timestampX' => $this->timestampX,
            'timestampY' => $this->timestampY,
            'timestampFontSize' => $this->timestampFontSize,
            'timestampColor' => $this->timestampColor,
        ];
    }
}
