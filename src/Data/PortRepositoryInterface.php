<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Data;

interface PortRepositoryInterface
{
    public function findById(int $portId): ?PortSnapshot;
}
