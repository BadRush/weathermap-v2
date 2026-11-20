<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $map_id
 * @property string $ref_type
 * @property int|null $port_id
 * @property int|null $device_id
 * @property string $element_id
 * @property string|null $rrd_path
 */
class WeathermapMapReference extends Model
{
    protected $table = 'weathermap_map_references';

    protected $fillable = [
        'map_id',
        'ref_type',
        'port_id',
        'device_id',
        'element_id',
        'rrd_path',
    ];

    /**
     * @return BelongsTo<WeathermapMap, $this>
     */
    public function map(): BelongsTo
    {
        return $this->belongsTo(WeathermapMap::class, 'map_id');
    }
}
