<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class NodeDef
{
    public const string ICON_SHAPE_BOX = 'shape:box';

    public const string ICON_SHAPE_ROUND = 'shape:round';

    public const string ICON_DEVICE_OS = 'device-os';

    public const string ICON_ASSET = 'asset';

    public function __construct(
        public string $id,
        public string $label,
        public float $x,
        public float $y,
        public string $iconType = self::ICON_SHAPE_BOX,
        public ?int $iconAssetId = null,
        public ?string $deviceOsIcon = null,
        public int $iconWidth = 64,
        public int $iconHeight = 36,
        public ?int $deviceId = null,
        public string $labelOffset = 'C',
        public int $labelFontSize = 11,
        public string $labelColor = '#f8fafc',
        public string $bgColor = '#1e293b',
        public string $outlineColor = '#64748b',
        public int $zOrder = 600,
        public ?string $infoUrl = null,
        public ?string $scaleName = null,
        public ?TargetExpr $target = null,
        public float $maxValue = 100.0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $iconType = is_string($data['iconType'] ?? null) ? $data['iconType'] : self::ICON_SHAPE_BOX;
        if (! in_array($iconType, [self::ICON_SHAPE_BOX, self::ICON_SHAPE_ROUND, self::ICON_DEVICE_OS, self::ICON_ASSET], true)) {
            $iconType = self::ICON_SHAPE_BOX;
        }

        return new self(
            id: is_string($data['id'] ?? null) && $data['id'] !== '' ? $data['id'] : 'node',
            label: is_string($data['label'] ?? null) ? $data['label'] : '',
            x: is_numeric($data['x'] ?? null) ? (float) $data['x'] : 0.0,
            y: is_numeric($data['y'] ?? null) ? (float) $data['y'] : 0.0,
            iconType: $iconType,
            iconAssetId: isset($data['iconAssetId']) && is_numeric($data['iconAssetId']) ? (int) $data['iconAssetId'] : null,
            deviceOsIcon: is_string($data['deviceOsIcon'] ?? null) ? $data['deviceOsIcon'] : null,
            iconWidth: max(12, min(512, (int) ($data['iconWidth'] ?? 64))),
            iconHeight: max(12, min(512, (int) ($data['iconHeight'] ?? 36))),
            deviceId: isset($data['deviceId']) && is_numeric($data['deviceId']) ? (int) $data['deviceId'] : null,
            labelOffset: is_string($data['labelOffset'] ?? null) && $data['labelOffset'] !== '' ? $data['labelOffset'] : 'C',
            labelFontSize: max(6, min(48, (int) ($data['labelFontSize'] ?? 11))),
            labelColor: is_string($data['labelColor'] ?? null) ? $data['labelColor'] : '#f8fafc',
            bgColor: is_string($data['bgColor'] ?? null) ? $data['bgColor'] : '#1e293b',
            outlineColor: is_string($data['outlineColor'] ?? null) ? $data['outlineColor'] : '#64748b',
            zOrder: (int) ($data['zOrder'] ?? 600),
            infoUrl: is_string($data['infoUrl'] ?? null) && $data['infoUrl'] !== '' ? $data['infoUrl'] : null,
            scaleName: is_string($data['scaleName'] ?? null) && $data['scaleName'] !== '' ? $data['scaleName'] : null,
            target: isset($data['target']) && is_array($data['target']) ? TargetExpr::fromArray($data['target']) : null,
            maxValue: is_numeric($data['maxValue'] ?? null) && (float) $data['maxValue'] > 0 ? (float) $data['maxValue'] : 100.0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'x' => $this->x,
            'y' => $this->y,
            'iconType' => $this->iconType,
            'iconAssetId' => $this->iconAssetId,
            'deviceOsIcon' => $this->deviceOsIcon,
            'iconWidth' => $this->iconWidth,
            'iconHeight' => $this->iconHeight,
            'deviceId' => $this->deviceId,
            'labelOffset' => $this->labelOffset,
            'labelFontSize' => $this->labelFontSize,
            'labelColor' => $this->labelColor,
            'bgColor' => $this->bgColor,
            'outlineColor' => $this->outlineColor,
            'zOrder' => $this->zOrder,
            'infoUrl' => $this->infoUrl,
            'scaleName' => $this->scaleName,
            'target' => $this->target?->toArray(),
            'maxValue' => $this->maxValue,
        ];
    }
}
