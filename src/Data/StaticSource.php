<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Data;

use BadRush\Weathermap\Document\MapSettings;
use BadRush\Weathermap\Document\TargetTerm;

final class StaticSource implements DataSourceInterface
{
    public function supports(TargetTerm $term): bool
    {
        return $term->source === TargetTerm::SOURCE_STATIC;
    }

    public function read(TargetTerm $term, MapSettings $settings, int $now): DataReading
    {
        return new DataReading(
            inBps: $term->staticIn,
            outBps: $term->staticOut,
            timestamp: $now,
            isStale: false,
            isDown: false,
        );
    }
}
