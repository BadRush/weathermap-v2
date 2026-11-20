<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Document;

final class SchemaMigrator
{
    /**
     * Upgrade older persisted JSON map documents to the current schema version.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function migrate(array $data): array
    {
        $version = isset($data['schemaVersion']) && is_numeric($data['schemaVersion'])
            ? (int) $data['schemaVersion']
            : 0;

        if ($version < 1) {
            $data['schemaVersion'] = 1;
            if (! isset($data['scales']) || ! is_array($data['scales']) || $data['scales'] === []) {
                $data['scales'] = [ScaleDef::classicDefault()->toArray()];
            }
        }

        return $data;
    }
}
