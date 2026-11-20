<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Services;

use RuntimeException;

final class AssetInUseException extends RuntimeException
{
    /**
     * @param  list<string>  $mapNames
     */
    public function __construct(
        public readonly array $mapNames,
    ) {
        $list = implode(', ', array_slice($mapNames, 0, 5));
        $suffix = count($mapNames) > 5 ? ' (+'.(count($mapNames) - 5).' more)' : '';
        parent::__construct("Asset cannot be deleted because it is currently used by: {$list}{$suffix}.");
    }
}
