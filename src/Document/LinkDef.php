<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class LinkDef
{
    public const string VIA_CURVED = 'curved';

    public const string VIA_ANGLED = 'angled';

    public const string ARROW_CLASSIC = 'classic';

    public const string ARROW_COMPACT = 'compact';

    public const string BWLABEL_PERCENT = 'percent';

    public const string BWLABEL_BITS = 'bits';

    public const string BWLABEL_NONE = 'none';

    /**
     * @param  list<ViaPoint>  $via
     */
    public function __construct(
        public string $id,
        public LinkEndpoint $a,
        public LinkEndpoint $b,
        public array $via = [],
        public string $viaStyle = self::VIA_CURVED,
        public float $width = 7.0,
        public string $arrowStyle = self::ARROW_CLASSIC,
        public float $splitPos = 0.5,
        public string $bwLabel = self::BWLABEL_PERCENT,
        public float $inLabelPos = 0.75,
        public float $outLabelPos = 0.25,
        public int $bwFontSize = 9,
        public string $outlineColor = '#0f172a',
        public string $scaleName = 'DEFAULT',
        public CapacityDef $capacity = new CapacityDef,
        public TargetExpr $target = new TargetExpr,
        public ?string $infoUrl = null,
        public string $inComment = '',
        public string $outComment = '',
        public int $zOrder = 300,
        public string $staticLabel = '',
        public float $staticLabelPos = 0.5,
        public float $staticLabelOffsetX = 0.0,
        public float $staticLabelOffsetY = -18.0,
        public int $staticLabelFontSize = 10,
        public string $staticLabelBgColor = '#0f172a',
        public string $staticLabelTextColor = '#e2e8f0',
        public ?int $vendorIconAssetId = null,
        public int $vendorIconSize = 16,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $via = [];
        if (isset($data['via']) && is_array($data['via'])) {
            foreach ($data['via'] as $pt) {
                if (is_array($pt)) {
                    $via[] = ViaPoint::fromArray($pt);
                }
            }
        }

        $viaStyle = is_string($data['viaStyle'] ?? null) ? $data['viaStyle'] : self::VIA_CURVED;
        if (! in_array($viaStyle, [self::VIA_CURVED, self::VIA_ANGLED], true)) {
            $viaStyle = self::VIA_CURVED;
        }

        $arrowStyle = is_string($data['arrowStyle'] ?? null) ? $data['arrowStyle'] : self::ARROW_CLASSIC;
        if (! in_array($arrowStyle, [self::ARROW_CLASSIC, self::ARROW_COMPACT], true)) {
            $arrowStyle = self::ARROW_CLASSIC;
        }

        $bwLabel = is_string($data['bwLabel'] ?? null) ? $data['bwLabel'] : self::BWLABEL_PERCENT;
        if (! in_array($bwLabel, [self::BWLABEL_PERCENT, self::BWLABEL_BITS, self::BWLABEL_NONE], true)) {
            $bwLabel = self::BWLABEL_PERCENT;
        }

        return new self(
            id: is_string($data['id'] ?? null) && $data['id'] !== '' ? $data['id'] : 'link',
            a: LinkEndpoint::fromArray(is_array($data['a'] ?? null) ? $data['a'] : []),
            b: LinkEndpoint::fromArray(is_array($data['b'] ?? null) ? $data['b'] : []),
            via: $via,
            viaStyle: $viaStyle,
            width: max(2.0, min(40.0, is_numeric($data['width'] ?? null) ? (float) $data['width'] : 7.0)),
            arrowStyle: $arrowStyle,
            splitPos: max(0.1, min(0.9, is_numeric($data['splitPos'] ?? null) ? (float) $data['splitPos'] : 0.5)),
            bwLabel: $bwLabel,
            inLabelPos: max(0.05, min(0.95, is_numeric($data['inLabelPos'] ?? null) ? (float) $data['inLabelPos'] : 0.75)),
            outLabelPos: max(0.05, min(0.95, is_numeric($data['outLabelPos'] ?? null) ? (float) $data['outLabelPos'] : 0.25)),
            bwFontSize: max(6, min(28, (int) ($data['bwFontSize'] ?? 9))),
            outlineColor: is_string($data['outlineColor'] ?? null) ? $data['outlineColor'] : '#0f172a',
            scaleName: is_string($data['scaleName'] ?? null) && $data['scaleName'] !== '' ? $data['scaleName'] : 'DEFAULT',
            capacity: CapacityDef::fromArray(is_array($data['capacity'] ?? null) ? $data['capacity'] : null),
            target: TargetExpr::fromArray(is_array($data['target'] ?? null) ? $data['target'] : null),
            infoUrl: is_string($data['infoUrl'] ?? null) && $data['infoUrl'] !== '' ? $data['infoUrl'] : null,
            inComment: is_string($data['inComment'] ?? null) ? $data['inComment'] : '',
            outComment: is_string($data['outComment'] ?? null) ? $data['outComment'] : '',
            zOrder: (int) ($data['zOrder'] ?? 300),
            staticLabel: is_string($data['staticLabel'] ?? null) ? $data['staticLabel'] : '',
            staticLabelPos: max(0.05, min(0.95, is_numeric($data['staticLabelPos'] ?? null) ? (float) $data['staticLabelPos'] : 0.5)),
            staticLabelOffsetX: max(-400.0, min(400.0, is_numeric($data['staticLabelOffsetX'] ?? null) ? (float) $data['staticLabelOffsetX'] : 0.0)),
            staticLabelOffsetY: max(-400.0, min(400.0, is_numeric($data['staticLabelOffsetY'] ?? null) ? (float) $data['staticLabelOffsetY'] : -18.0)),
            staticLabelFontSize: max(7, min(24, (int) ($data['staticLabelFontSize'] ?? 10))),
            staticLabelBgColor: is_string($data['staticLabelBgColor'] ?? null) ? $data['staticLabelBgColor'] : '#0f172a',
            staticLabelTextColor: is_string($data['staticLabelTextColor'] ?? null) ? $data['staticLabelTextColor'] : '#e2e8f0',
            vendorIconAssetId: isset($data['vendorIconAssetId']) && is_numeric($data['vendorIconAssetId']) ? (int) $data['vendorIconAssetId'] : null,
            vendorIconSize: max(12, min(40, (int) ($data['vendorIconSize'] ?? 16))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'a' => $this->a->toArray(),
            'b' => $this->b->toArray(),
            'via' => array_map(static fn (ViaPoint $v): array => $v->toArray(), $this->via),
            'viaStyle' => $this->viaStyle,
            'width' => $this->width,
            'arrowStyle' => $this->arrowStyle,
            'splitPos' => $this->splitPos,
            'bwLabel' => $this->bwLabel,
            'inLabelPos' => $this->inLabelPos,
            'outLabelPos' => $this->outLabelPos,
            'bwFontSize' => $this->bwFontSize,
            'outlineColor' => $this->outlineColor,
            'scaleName' => $this->scaleName,
            'capacity' => $this->capacity->toArray(),
            'target' => $this->target->toArray(),
            'infoUrl' => $this->infoUrl,
            'inComment' => $this->inComment,
            'outComment' => $this->outComment,
            'zOrder' => $this->zOrder,
            'staticLabel' => $this->staticLabel,
            'staticLabelPos' => $this->staticLabelPos,
            'staticLabelOffsetX' => $this->staticLabelOffsetX,
            'staticLabelOffsetY' => $this->staticLabelOffsetY,
            'staticLabelFontSize' => $this->staticLabelFontSize,
            'staticLabelBgColor' => $this->staticLabelBgColor,
            'staticLabelTextColor' => $this->staticLabelTextColor,
            'vendorIconAssetId' => $this->vendorIconAssetId,
            'vendorIconSize' => $this->vendorIconSize,
        ];
    }
}
