<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Geometry;

final readonly class Point
{
    public function __construct(
        public float $x,
        public float $y,
    ) {}

    public function distanceTo(self $other): float
    {
        return hypot($other->x - $this->x, $other->y - $this->y);
    }

    public function add(Vector $v): self
    {
        return new self($this->x + $v->dx, $this->y + $v->dy);
    }

    public function vectorTo(self $other): Vector
    {
        return new Vector($other->x - $this->x, $other->y - $this->y);
    }

    public function lerp(self $other, float $t): self
    {
        return new self(
            $this->x + ($other->x - $this->x) * $t,
            $this->y + ($other->y - $this->y) * $t,
        );
    }

    /**
     * @return array{x: float, y: float}
     */
    public function toArray(): array
    {
        return [
            'x' => round($this->x, 3),
            'y' => round($this->y, 3),
        ];
    }
}
