<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $kind
 * @property string $original_name
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property int|null $created_by
 */
class WeathermapAsset extends Model
{
    public const string KIND_BACKGROUND = 'background';

    public const string KIND_ICON = 'icon';

    public const string KIND_VENDOR = 'vendor';

    /** @var list<string> */
    public const array ALLOWED_KINDS = [
        self::KIND_BACKGROUND,
        self::KIND_ICON,
        self::KIND_VENDOR,
    ];

    protected $table = 'weathermap_assets';

    protected $fillable = [
        'kind',
        'original_name',
        'path',
        'mime',
        'size',
        'sha256',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'created_by' => 'integer',
        ];
    }
}
