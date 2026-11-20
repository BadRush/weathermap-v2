<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $map_id
 * @property string $subject_type
 * @property string $subject_id
 */
class WeathermapMapAccess extends Model
{
    public const string SUBJECT_ROLE = 'role';

    public const string SUBJECT_USER = 'user';

    protected $table = 'weathermap_map_access';

    protected $fillable = [
        'map_id',
        'subject_type',
        'subject_id',
    ];

    /**
     * @return BelongsTo<WeathermapMap, $this>
     */
    public function map(): BelongsTo
    {
        return $this->belongsTo(WeathermapMap::class, 'map_id');
    }
}
