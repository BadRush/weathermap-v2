<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Models;

use BadRush\Weathermap\Document\MapDocument;
use BadRush\Weathermap\Document\TargetTerm;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $document
 * @property int $schema_version
 * @property int $revision
 * @property bool $png_enabled
 * @property CarbonInterface|null $last_rendered_at
 * @property string|null $last_render_error
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read EloquentCollection<int, WeathermapMapAccess> $accessRules
 * @property-read EloquentCollection<int, WeathermapMapReference> $references
 */
class WeathermapMap extends Model
{
    protected $table = 'weathermap_maps';

    protected $fillable = [
        'name',
        'slug',
        'document',
        'schema_version',
        'revision',
        'png_enabled',
        'last_rendered_at',
        'last_render_error',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document' => 'array',
            'schema_version' => 'integer',
            'revision' => 'integer',
            'png_enabled' => 'boolean',
            'last_rendered_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /**
     * @return HasMany<WeathermapMapReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(WeathermapMapReference::class, 'map_id');
    }

    /**
     * @return HasMany<WeathermapMapAccess, $this>
     */
    public function accessRules(): HasMany
    {
        return $this->hasMany(WeathermapMapAccess::class, 'map_id');
    }

    public function toDocument(): MapDocument
    {
        $raw = is_array($this->document) ? $this->document : [];

        return MapDocument::fromArray($raw);
    }

    /**
     * Synchronize the relational reference index table with the map JSON document.
     */
    public function syncReferences(MapDocument $doc): void
    {
        $this->references()->delete();

        $rows = [];
        $now = now();

        foreach ($doc->nodes as $node) {
            if ($node->deviceId !== null) {
                $rows[] = [
                    'map_id' => $this->id,
                    'ref_type' => 'device',
                    'port_id' => null,
                    'device_id' => $node->deviceId,
                    'element_id' => $node->id,
                    'rrd_path' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($node->target !== null) {
                foreach ($node->target->terms as $term) {
                    $row = self::termReferenceRow($this->id, $node->id, $term, $now);
                    if ($row !== null) {
                        $rows[] = $row;
                    }
                }
            }
        }

        foreach ($doc->links as $link) {
            foreach ($link->target->terms as $term) {
                $row = self::termReferenceRow($this->id, $link->id, $term, $now);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        if ($rows !== []) {
            WeathermapMapReference::query()->insert($rows);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function termReferenceRow(int $mapId, string $elementId, TargetTerm $term, CarbonInterface $now): ?array
    {
        if ($term->source === TargetTerm::SOURCE_PORT && $term->portId !== null) {
            return [
                'map_id' => $mapId,
                'ref_type' => 'port',
                'port_id' => $term->portId,
                'device_id' => null,
                'element_id' => $elementId,
                'rrd_path' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($term->source === TargetTerm::SOURCE_RRD && $term->rrdPath !== null) {
            return [
                'map_id' => $mapId,
                'ref_type' => 'rrd',
                'port_id' => null,
                'device_id' => null,
                'element_id' => $elementId,
                'rrd_path' => $term->rrdPath,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return null;
    }
}
