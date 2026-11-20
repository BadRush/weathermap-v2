<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class LegendDef
{
    public const string STYLE_CLASSIC = 'classic';

    public const string STYLE_HORIZONTAL = 'horizontal';

    public const string STYLE_VERTICAL = 'vertical';

    public function __construct(
        public bool $visible = true,
        public string $scaleName = 'DEFAULT',
        public int $x = 20,
        public int $y = 420,
        public string $title = 'Traffic Load',
        public string $style = self::STYLE_CLASSIC,
        public string $bgColor = '#1e293b',
        public string $outlineColor = '#475569',
        public string $textColor = '#f1f5f9',
        public int $fontSize = 10,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $style = is_string($data['style'] ?? null) ? $data['style'] : self::STYLE_CLASSIC;
        if (! in_array($style, [self::STYLE_CLASSIC, self::STYLE_HORIZONTAL, self::STYLE_VERTICAL], true)) {
            $style = self::STYLE_CLASSIC;
        }

        return new self(
            visible: (bool) ($data['visible'] ?? true),
            scaleName: is_string($data['scaleName'] ?? null) && $data['scaleName'] !== '' ? $data['scaleName'] : 'DEFAULT',
            x: (int) ($data['x'] ?? 20),
            y: (int) ($data['y'] ?? 420),
            title: is_string($data['title'] ?? null) ? $data['title'] : 'Traffic Load',
            style: $style,
            bgColor: is_string($data['bgColor'] ?? null) ? $data['bgColor'] : '#1e293b',
            outlineColor: is_string($data['outlineColor'] ?? null) ? $data['outlineColor'] : '#475569',
            textColor: is_string($data['textColor'] ?? null) ? $data['textColor'] : '#f1f5f9',
            fontSize: max(6, min(32, (int) ($data['fontSize'] ?? 10))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'visible' => $this->visible,
            'scaleName' => $this->scaleName,
            'x' => $this->x,
            'y' => $this->y,
            'title' => $this->title,
            'style' => $this->style,
            'bgColor' => $this->bgColor,
            'outlineColor' => $this->outlineColor,
            'textColor' => $this->textColor,
            'fontSize' => $this->fontSize,
        ];
    }
}
