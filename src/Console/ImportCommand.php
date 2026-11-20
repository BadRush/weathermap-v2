<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Console;

use BadRush\Weathermap\Data\PortRepositoryInterface;
use BadRush\Weathermap\Import\ConfImporter;
use BadRush\Weathermap\Models\WeathermapMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportCommand extends Command
{
    protected $signature = 'weathermap:import
        {file : Path to a classic Weathermap .conf file}
        {--name= : Override the imported map name}';

    protected $description = 'Import a classic PHP Network Weathermap .conf file into the LibreNMS Weathermap database';

    public function handle(ConfImporter $importer, PortRepositoryInterface $ports): int
    {
        $fileArg = $this->argument('file');
        $file = is_string($fileArg) ? $fileArg : '';
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Cannot read file: {$file}");

            return self::FAILURE;
        }

        $content = file_get_contents($file);
        if (! is_string($content) || trim($content) === '') {
            $this->error("File is empty: {$file}");

            return self::FAILURE;
        }

        $result = $importer->import(
            $content,
            fn (int $portId): bool => $ports->findById($portId) !== null,
        );

        $doc = $result['document'];
        $report = $result['report'];

        $nameOption = $this->option('name');
        $name = is_string($nameOption) && trim($nameOption) !== ''
            ? trim($nameOption)
            : ($doc->canvas->title !== '' ? $doc->canvas->title : pathinfo($file, PATHINFO_FILENAME));

        $base = Str::slug($name) ?: 'imported-map';
        $slug = $base;
        $i = 2;
        while (WeathermapMap::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        $map = DB::transaction(function () use ($name, $slug, $doc): WeathermapMap {
            $map = WeathermapMap::query()->create([
                'name' => $name,
                'slug' => $slug,
                'document' => $doc->toArray(),
                'schema_version' => $doc->schemaVersion,
                'revision' => 1,
                'png_enabled' => true,
            ]);
            $map->syncReferences($doc);

            return $map;
        });

        $this->info("Imported map #{$map->id} '{$map->name}' (slug: {$map->slug})");
        $this->line("  Nodes: {$report->nodesImported} | Links: {$report->linksImported} | Scales: {$report->scalesImported}");

        if ($report->convertedTargets !== []) {
            $this->line('Converted targets:');
            foreach ($report->convertedTargets as $item) {
                $this->line("  - {$item}");
            }
        }

        if ($report->warnings !== []) {
            $this->warn('Warnings:');
            foreach ($report->warnings as $warning) {
                $this->warn("  ! {$warning}");
            }
        }

        if ($report->skippedDirectives !== []) {
            $this->line('Skipped legacy directives: '.implode(', ', $report->skippedDirectives));
        }

        return self::SUCCESS;
    }
}
