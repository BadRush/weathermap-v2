<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class LinkEndpoint
{
    public function __construct(
        public string $nodeId,
        public string $offset = 'C',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            nodeId: is_string($data['nodeId'] ?? null) ? $data['nodeId'] : '',
            offset: is_string($data['offset'] ?? null) && $data['offset'] !== '' ? $data['offset'] : 'C',
        );
    }

    /**
     * @return array{nodeId: string, offset: string}
     */
    public function toArray(): array
    {
        return [
            'nodeId' => $this->nodeId,
            'offset' => $this->offset,
        ];
    }
}
