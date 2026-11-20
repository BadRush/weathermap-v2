<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Data;

use BadRush\Weathermap\Document\MapSettings;
use BadRush\Weathermap\Document\TargetTerm;
use Symfony\Component\Process\Process;

/**
 * Reads time-series values via `rrdtool fetch` using strict array arguments (no shell interpolation),
 * supporting LibreNMS rrdcached via `--daemon` and enforcing a strict realpath jail inside `rrd_dir`.
 */
final class RrdSource implements DataSourceInterface
{
    public function __construct(
        private readonly string $rrdDir,
        private readonly string $rrdtoolBin = 'rrdtool',
        private readonly ?string $rrdcached = null,
    ) {}

    public function supports(TargetTerm $term): bool
    {
        return $term->source === TargetTerm::SOURCE_RRD;
    }

    public function read(TargetTerm $term, MapSettings $settings, int $now): DataReading
    {
        $relative = $term->rrdPath ?? '';
        $resolved = $this->resolveSafePath($relative);
        if ($resolved === null) {
            return DataReading::empty('Invalid or out-of-bounds RRD path.');
        }

        // With remote rrdcached, local file may not exist on this host; pass relative path when remote, full path when local.
        $isRemoteDaemon = $this->rrdcached !== null
            && $this->rrdcached !== ''
            && ! str_starts_with($this->rrdcached, 'unix:')
            && ! str_starts_with($this->rrdcached, '/');

        if (! $isRemoteDaemon && ! is_file($resolved)) {
            return DataReading::empty("RRD file not found: {$relative}");
        }

        $start = $now - max(300, $settings->rrdPeriodSeconds);
        $args = [
            $this->rrdtoolBin,
            'fetch',
            $isRemoteDaemon ? ltrim($relative, '/') : $resolved,
            $term->cf,
            '--start',
            (string) $start,
            '--end',
            (string) $now,
        ];

        if ($this->rrdcached !== null && $this->rrdcached !== '') {
            $args[] = '--daemon';
            $args[] = $this->rrdcached;
        }

        $process = new Process($args);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return DataReading::empty('rrdtool fetch failed: '.trim($process->getErrorOutput()));
        }

        return self::parseFetchOutput(
            output: $process->getOutput(),
            dsIn: $term->dsIn,
            dsOut: $term->dsOut,
            now: $now,
            stalenessSeconds: $settings->stalenessSeconds,
        );
    }

    /**
     * Resolve a relative RRD path strictly within `$this->rrdDir`. Returns null on traversal attempts.
     */
    public function resolveSafePath(string $relativePath): ?string
    {
        $clean = str_replace('\\', '/', trim($relativePath));
        if ($clean === '' || str_starts_with($clean, '/') || str_contains($clean, "\0")) {
            return null;
        }

        $segments = explode('/', $clean);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        $baseReal = realpath($this->rrdDir);
        if ($baseReal === false) {
            return null;
        }

        $candidate = $baseReal.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
        if (file_exists($candidate)) {
            $realCandidate = realpath($candidate);
            if ($realCandidate === false || ! str_starts_with($realCandidate, $baseReal.DIRECTORY_SEPARATOR)) {
                return null;
            }

            return $realCandidate;
        }

        return $candidate;
    }

    /**
     * Parse stdout from `rrdtool fetch` and extract the latest valid numeric values for dsIn and dsOut.
     */
    public static function parseFetchOutput(
        string $output,
        string $dsIn,
        string $dsOut,
        int $now,
        int $stalenessSeconds,
    ): DataReading {
        $lines = preg_split('/\r?\n/', trim($output));
        if ($lines === false || $lines === []) {
            return DataReading::empty('Empty rrdtool output.');
        }

        $headers = null;
        $rows = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if ($headers === null && ! str_contains($trimmed, ':')) {
                $parts = preg_split('/\s+/', $trimmed);
                if (is_array($parts)) {
                    $headers = $parts;
                }

                continue;
            }

            if (preg_match('/^(\d+):\s*(.+)$/', $trimmed, $m) === 1) {
                $ts = (int) $m[1];
                $vals = preg_split('/\s+/', trim($m[2]));
                if (is_array($vals)) {
                    $rows[] = ['ts' => $ts, 'vals' => $vals];
                }
            }
        }

        if ($headers === null || $rows === []) {
            return DataReading::empty('No data rows in rrdtool output.');
        }

        $inIdx = $dsIn === '-' ? null : array_search($dsIn, $headers, true);
        $outIdx = $dsOut === '-' ? null : array_search($dsOut, $headers, true);

        if ($inIdx === false && $dsIn !== '-') {
            return DataReading::empty("RRD dataset '{$dsIn}' not found.");
        }
        if ($outIdx === false && $dsOut !== '-') {
            return DataReading::empty("RRD dataset '{$dsOut}' not found.");
        }

        // Walk backwards to pick the most recent non-NaN row (classic Weathermap behavior for rrdcached lag)
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $row = $rows[$i];
            $rawIn = $inIdx === null ? '0' : ($row['vals'][$inIdx] ?? 'nan');
            $rawOut = $outIdx === null ? '0' : ($row['vals'][$outIdx] ?? 'nan');

            $inValid = $inIdx === null || (is_numeric($rawIn) && ! is_nan((float) $rawIn));
            $outValid = $outIdx === null || (is_numeric($rawOut) && ! is_nan((float) $rawOut));

            if ($inValid && $outValid) {
                $inVal = $inIdx === null ? 0.0 : (float) $rawIn;
                $outVal = $outIdx === null ? 0.0 : (float) $rawOut;

                // Standard LibreNMS octets datasets are bytes/sec -> convert to bps automatically if named *OCTETS
                $inScale = str_contains(strtoupper($dsIn), 'OCTETS') ? 8.0 : 1.0;
                $outScale = str_contains(strtoupper($dsOut), 'OCTETS') ? 8.0 : 1.0;

                return new DataReading(
                    inBps: $inVal * $inScale,
                    outBps: $outVal * $outScale,
                    timestamp: $row['ts'],
                    isStale: ($now - $row['ts']) > $stalenessSeconds,
                    isDown: false,
                );
            }
        }

        return DataReading::empty('All RRD samples are NaN.');
    }
}
