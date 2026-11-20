<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class MapDocument
{
    public const int CURRENT_SCHEMA_VERSION = 1;

    /**
     * @param  list<NodeDef>  $nodes
     * @param  list<LinkDef>  $links
     * @param  list<ScaleDef>  $scales
     * @param  list<AnnotationDef>  $annotations
     */
    public function __construct(
        public int $schemaVersion = self::CURRENT_SCHEMA_VERSION,
        public CanvasConfig $canvas = new CanvasConfig,
        public array $nodes = [],
        public array $links = [],
        public array $scales = [],
        public LegendDef $legend = new LegendDef,
        public MapSettings $settings = new MapSettings,
        public array $annotations = [],
    ) {}

    public static function empty(string $title = 'New Weathermap'): self
    {
        return new self(
            schemaVersion: self::CURRENT_SCHEMA_VERSION,
            canvas: new CanvasConfig(title: $title),
            nodes: [],
            links: [],
            scales: [ScaleDef::classicDefault()],
            legend: new LegendDef,
            settings: new MapSettings,
            annotations: [],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $migrated = SchemaMigrator::migrate($data);

        $nodes = [];
        if (isset($migrated['nodes']) && is_array($migrated['nodes'])) {
            foreach ($migrated['nodes'] as $node) {
                if (is_array($node)) {
                    $nodes[] = NodeDef::fromArray($node);
                }
            }
        }

        $links = [];
        if (isset($migrated['links']) && is_array($migrated['links'])) {
            foreach ($migrated['links'] as $link) {
                if (is_array($link)) {
                    $links[] = LinkDef::fromArray($link);
                }
            }
        }

        $scales = [];
        if (isset($migrated['scales']) && is_array($migrated['scales'])) {
            foreach ($migrated['scales'] as $scale) {
                if (is_array($scale)) {
                    $scales[] = ScaleDef::fromArray($scale);
                }
            }
        }
        if ($scales === []) {
            $scales = [ScaleDef::classicDefault()];
        }

        $annotations = [];
        if (isset($migrated['annotations']) && is_array($migrated['annotations'])) {
            foreach ($migrated['annotations'] as $annotation) {
                if (is_array($annotation)) {
                    $annotations[] = AnnotationDef::fromArray($annotation);
                }
            }
        }

        return new self(
            schemaVersion: (int) ($migrated['schemaVersion'] ?? self::CURRENT_SCHEMA_VERSION),
            canvas: CanvasConfig::fromArray(is_array($migrated['canvas'] ?? null) ? $migrated['canvas'] : []),
            nodes: $nodes,
            links: $links,
            scales: $scales,
            legend: LegendDef::fromArray(is_array($migrated['legend'] ?? null) ? $migrated['legend'] : []),
            settings: MapSettings::fromArray(is_array($migrated['settings'] ?? null) ? $migrated['settings'] : null),
            annotations: $annotations,
        );
    }

    public function getNode(string $id): ?NodeDef
    {
        foreach ($this->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        return null;
    }

    public function getScale(string $name): ScaleDef
    {
        foreach ($this->scales as $scale) {
            if ($scale->name === $name) {
                return $scale;
            }
        }

        return $this->scales[0] ?? ScaleDef::classicDefault();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'canvas' => $this->canvas->toArray(),
            'nodes' => array_map(static fn (NodeDef $n): array => $n->toArray(), $this->nodes),
            'links' => array_map(static fn (LinkDef $l): array => $l->toArray(), $this->links),
            'scales' => array_map(static fn (ScaleDef $s): array => $s->toArray(), $this->scales),
            'legend' => $this->legend->toArray(),
            'settings' => $this->settings->toArray(),
            'annotations' => array_map(static fn (AnnotationDef $a): array => $a->toArray(), $this->annotations),
        ];
    }
}
