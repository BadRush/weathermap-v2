<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Render;

/**
 * Deterministic, render-ready Scene DTO shared between the browser SVG Viewer
 * and the server-side PHP GD PNG Renderer so both outputs stay pixel-consistent.
 */
final readonly class Scene
{
    /**
     * @param  array<string, mixed>  $canvas
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $links
     * @param  array<string, mixed>|null  $legend
     * @param  list<array<string, mixed>>  $annotations
     */
    public function __construct(
        public int $generatedAt,
        public string $timestampText,
        public array $canvas,
        public array $nodes,
        public array $links,
        public ?array $legend,
        public int $refreshSeconds,
        public array $annotations = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generatedAt' => $this->generatedAt,
            'timestampText' => $this->timestampText,
            'canvas' => $this->canvas,
            'nodes' => $this->nodes,
            'links' => $this->links,
            'annotations' => $this->annotations,
            'legend' => $this->legend,
            'refreshSeconds' => $this->refreshSeconds,
        ];
    }
}
