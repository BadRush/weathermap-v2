<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Geometry;

final readonly class Vector
{
    public function __construct(
        public float $dx,
        public float $dy,
    ) {}

    public function length(): float
    {
        return hypot($this->dx, $this->dy);
    }

    public function normalise(): self
    {
        $len = $this->length();
        if ($len < 1e-9) {
            return new self(0.0, 0.0);
        }

        return new self($this->dx / $len, $this->dy / $len);
    }

    /**
     * Unit normal rotated 90 degrees counter-clockwise in screen space.
     */
    public function normal(): self
    {
        $u = $this->normalise();

        return new self(-$u->dy, $u->dx);
    }

    public function scale(float $factor): self
    {
        return new self($this->dx * $factor, $this->dy * $factor);
    }

    public function add(self $other): self
    {
        return new self($this->dx + $other->dx, $this->dy + $other->dy);
    }

    public function dot(self $other): float
    {
        return ($this->dx * $other->dx) + ($this->dy * $other->dy);
    }

    public function cross(self $other): float
    {
        return ($this->dx * $other->dy) - ($this->dy * $other->dx);
    }
}
