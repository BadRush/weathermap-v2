<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final readonly class TargetExpr
{
    /**
     * @param  list<TargetTerm>  $terms
     */
    public function __construct(
        public array $terms = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return new self([]);
        }

        $rawTerms = $data['terms'] ?? [];
        if (! is_array($rawTerms)) {
            return new self([]);
        }

        $terms = [];
        foreach ($rawTerms as $item) {
            if (is_array($item)) {
                $terms[] = TargetTerm::fromArray($item);
            }
        }

        return new self($terms);
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /**
     * @return array{terms: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'terms' => array_map(static fn (TargetTerm $t): array => $t->toArray(), $this->terms),
        ];
    }
}
