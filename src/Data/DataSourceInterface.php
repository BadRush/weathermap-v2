<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Data;

use BadRush\Weathermap\Document\MapSettings;
use BadRush\Weathermap\Document\TargetTerm;

interface DataSourceInterface
{
    public function supports(TargetTerm $term): bool;

    public function read(TargetTerm $term, MapSettings $settings, int $now): DataReading;
}
