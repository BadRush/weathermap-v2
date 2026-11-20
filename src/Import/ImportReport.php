<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Import;

final readonly class ImportReport
{
    /**
     * @param  list<string>  $convertedTargets
     * @param  list<string>  $warnings
     * @param  list<string>  $skippedDirectives
     */
    public function __construct(
        public int $nodesImported,
        public int $linksImported,
        public int $scalesImported,
        public array $convertedTargets = [],
        public array $warnings = [],
        public array $skippedDirectives = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nodesImported' => $this->nodesImported,
            'linksImported' => $this->linksImported,
            'scalesImported' => $this->scalesImported,
            'convertedTargets' => $this->convertedTargets,
            'warnings' => $this->warnings,
            'skippedDirectives' => $this->skippedDirectives,
        ];
    }
}
