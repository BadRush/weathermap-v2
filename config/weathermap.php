<?php

declare(strict_types=1);

return [
    'rrd_dir' => env('WEATHERMAP_RRD_DIR'),
    'rrdtool' => env('WEATHERMAP_RRDTOOL', 'rrdtool'),
    'rrdcached' => env('WEATHERMAP_RRDCACHED'),
    'storage_dir' => env('WEATHERMAP_STORAGE_DIR'),
    'vite_dev_server' => env('WEATHERMAP_VITE_DEV_SERVER'),
    'schedule_enabled' => env('WEATHERMAP_SCHEDULE_ENABLED', true),
    'timezone' => env('WEATHERMAP_TIMEZONE', 'Asia/Jakarta'),
];
