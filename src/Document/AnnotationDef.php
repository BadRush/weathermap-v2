<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class AnnotationDef
{
    public const string STYLE_PLAIN = 'plain';

    public const string STYLE_CARD = 'card';

    public const string ALIGN_LEFT = 'left';

    public const string ALIGN_CENTER = 'center';

    public const string ALIGN_RIGHT = 'right';

    public const string ICON_LEFT = 'left';

    public const string ICON_TOP = 'top';

    public function __construct(
        public string $id,
        public string $text = '',
        public float $x = 100.0,
        public float $y = 100.0,
        public string $style = self::STYLE_CARD,
        public string $align = self::ALIGN_LEFT,
        public int $fontSize = 12,
        public string $textColor = '#f8fafc',
        public string $bgColor = '#1e293b',
        public string $borderColor = '#475569',
        public ?int $iconAssetId = null,
        public string $iconPosition = self::ICON_LEFT,
        public int $iconSize = 24,
        public ?string $infoUrl = null,
        public int $zOrder = 700,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $style = is_string($data['style'] ?? null) ? $data['style'] : self::STYLE_CARD;
        if (! in_array($style, [self::STYLE_PLAIN, self::STYLE_CARD], true)) {
            $style = self::STYLE_CARD;
        }

        $align = is_string($data['align'] ?? null) ? $data['align'] : self::ALIGN_LEFT;
        if (! in_array($align, [self::ALIGN_LEFT, self::ALIGN_CENTER, self::ALIGN_RIGHT], true)) {
            $align = self::ALIGN_LEFT;
        }

        $iconPosition = is_string($data['iconPosition'] ?? null) ? $data['iconPosition'] : self::ICON_LEFT;
        if (! in_array($iconPosition, [self::ICON_LEFT, self::ICON_TOP], true)) {
            $iconPosition = self::ICON_LEFT;
        }

        $rawText = is_string($data['text'] ?? null) ? $data['text'] : '';
        $normalizedText = str_replace(["\r\n", "\r"], "\n", $rawText);

        return new self(
            id: is_string($data['id'] ?? null) && $data['id'] !== '' ? $data['id'] : 'label',
            text: $normalizedText,
            x: is_numeric($data['x'] ?? null) ? (float) $data['x'] : 100.0,
            y: is_numeric($data['y'] ?? null) ? (float) $data['y'] : 100.0,
            style: $style,
            align: $align,
            fontSize: max(8, min(48, (int) ($data['fontSize'] ?? 12))),
            textColor: is_string($data['textColor'] ?? null) ? $data['textColor'] : '#f8fafc',
            bgColor: is_string($data['bgColor'] ?? null) ? $data['bgColor'] : '#1e293b',
            borderColor: is_string($data['borderColor'] ?? null) ? $data['borderColor'] : '#475569',
            iconAssetId: isset($data['iconAssetId']) && is_numeric($data['iconAssetId']) ? (int) $data['iconAssetId'] : null,
            iconPosition: $iconPosition,
            iconSize: max(12, min(128, (int) ($data['iconSize'] ?? 24))),
            infoUrl: is_string($data['infoUrl'] ?? null) && $data['infoUrl'] !== '' ? $data['infoUrl'] : null,
            zOrder: (int) ($data['zOrder'] ?? 700),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'x' => $this->x,
            'y' => $this->y,
            'style' => $this->style,
            'align' => $this->align,
            'fontSize' => $this->fontSize,
            'textColor' => $this->textColor,
            'bgColor' => $this->bgColor,
            'borderColor' => $this->borderColor,
            'iconAssetId' => $this->iconAssetId,
            'iconPosition' => $this->iconPosition,
            'iconSize' => $this->iconSize,
            'infoUrl' => $this->infoUrl,
            'zOrder' => $this->zOrder,
        ];
    }
}
