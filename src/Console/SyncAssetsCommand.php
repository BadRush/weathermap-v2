<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Console;

use BadRush\Weathermap\Services\AssetStorageService;
use Illuminate\Console\Command;

final class SyncAssetsCommand extends Command
{
    protected $signature = 'weathermap:sync-assets';

    protected $description = 'Scan storage/app/weathermap/assets/vendors/ and index reusable vendor logo images';

    public function handle(AssetStorageService $storage): int
    {
        $summary = $storage->syncVendorFolder();

        $this->info("Scanned vendor icon folder: {$summary['dir']}");
        $this->line("Imported: {$summary['imported']} | Skipped: {$summary['skipped']}");

        foreach ($summary['errors'] as $err) {
            $this->warn($err);
        }

        return self::SUCCESS;
    }
}
