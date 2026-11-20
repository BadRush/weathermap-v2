<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Unit;

use BadRush\Weathermap\Data\BandwidthFormatter;
use BadRush\Weathermap\Data\PortDbSource;
use BadRush\Weathermap\Data\PortRepositoryInterface;
use BadRush\Weathermap\Data\PortSnapshot;
use BadRush\Weathermap\Data\RrdSource;
use BadRush\Weathermap\Data\StaticSource;
use BadRush\Weathermap\Data\TargetResolver;
use BadRush\Weathermap\Document\MapSettings;
use BadRush\Weathermap\Document\TargetExpr;
use BadRush\Weathermap\Document\TargetTerm;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DataResolutionTest extends TestCase
{
    public function test_lag_aggregation_with_ports_and_math(): void
    {
        $now = 1_760_000_000;
        $repo = new class($now) implements PortRepositoryInterface
        {
            public function __construct(private readonly int $now) {}

            public function findById(int $portId): ?PortSnapshot
            {
                return match ($portId) {
                    10 => new PortSnapshot(10, 1, 'rtr1 - eth0', 25_000_000.0, 12_500_000.0, 1_000_000_000.0, 1_000_000_000.0, true, true, $this->now - 60),
                    11 => new PortSnapshot(11, 1, 'rtr1 - eth1', 12_500_000.0, 25_000_000.0, 1_000_000_000.0, 1_000_000_000.0, true, true, $this->now - 30),
                    12 => new PortSnapshot(12, 2, 'rtr2 - down', 0.0, 0.0, 1_000_000_000.0, 1_000_000_000.0, false, true, $this->now - 30),
                    default => null,
                };
            }
        };

        $resolver = new TargetResolver([
            new PortDbSource($repo),
            new StaticSource,
        ]);

        // Sum two 1G ports (200M+100M in, 100M+200M out) and subtract static 50M/25M
        $expr = new TargetExpr([
            new TargetTerm(TargetTerm::SOURCE_PORT, portId: 10),
            new TargetTerm(TargetTerm::SOURCE_PORT, portId: 11),
            new TargetTerm(TargetTerm::SOURCE_STATIC, staticIn: 50_000_000.0, staticOut: 25_000_000.0, negate: true),
        ]);

        $reading = $resolver->resolve($expr, new MapSettings, $now);
        $this->assertEqualsWithDelta(250_000_000.0, $reading->inBps, 0.01);
        $this->assertEqualsWithDelta(275_000_000.0, $reading->outBps, 0.01);
        $this->assertEqualsWithDelta(2_000_000_000.0, $reading->capacityInBps, 0.01);
        $this->assertFalse($reading->isDown);
        $this->assertFalse($reading->isStale);
        $this->assertSame(10, $reading->primaryPortId);

        // Down port is flagged down
        $downReading = $resolver->resolve(new TargetExpr([new TargetTerm(TargetTerm::SOURCE_PORT, portId: 12)]), new MapSettings, $now);
        $this->assertTrue($downReading->isDown);
    }

    public function test_rrd_source_path_jail_blocks_traversal_and_reads_real_rrd(): void
    {
        $tmpDir = sys_get_temp_dir().'/wm-rrd-'.uniqid('', true);
        mkdir($tmpDir.'/rtr1', 0750, true);

        try {
            $source = new RrdSource($tmpDir);

            // Path traversal attempts must be rejected
            $this->assertNull($source->resolveSafePath('../etc/passwd'));
            $this->assertNull($source->resolveSafePath('/etc/passwd'));
            $this->assertNull($source->resolveSafePath('rtr1/../../secret.rrd'));
            $this->assertNotNull($source->resolveSafePath('rtr1/port-id5.rrd'));

            // Parse fetch output picks latest non-NaN row and scales OCTETS * 8
            $sampleFetch = <<<'TXT'
                            INOCTETS           OUTOCTETS

1760000000: 1.2500000000e+07 6.2500000000e+06
1760000300: -nan -nan
TXT;
            $parsed = RrdSource::parseFetchOutput($sampleFetch, 'INOCTETS', 'OUTOCTETS', 1_760_000_400, 660);
            $this->assertEqualsWithDelta(100_000_000.0, $parsed->inBps, 1.0);
            $this->assertEqualsWithDelta(50_000_000.0, $parsed->outBps, 1.0);
            $this->assertFalse($parsed->isStale);

            // Real rrdtool end-to-end test when rrdtool binary is installed
            $check = new Process(['rrdtool', '--version']);
            $check->run();
            if ($check->isSuccessful()) {
                // Step-aligned timestamp (divisible by 300) so RRDtool stores exact bucket values
                $now = 1_760_001_000;
                $rrdFile = $tmpDir.'/rtr1/port-id5.rrd';
                (new Process([
                    'rrdtool', 'create', $rrdFile,
                    '--start', (string) ($now - 900),
                    '--step', '300',
                    'DS:INOCTETS:GAUGE:600:0:U',
                    'DS:OUTOCTETS:GAUGE:600:0:U',
                    'RRA:AVERAGE:0.5:1:10',
                ]))->mustRun();

                (new Process(['rrdtool', 'update', $rrdFile, ($now - 600).':1000000:500000']))->mustRun();
                (new Process(['rrdtool', 'update', $rrdFile, ($now - 300).':2000000:1000000']))->mustRun();
                (new Process(['rrdtool', 'update', $rrdFile, $now.':2000000:1000000']))->mustRun();

                $reading = $source->read(
                    new TargetTerm(TargetTerm::SOURCE_RRD, rrdPath: 'rtr1/port-id5.rrd', dsIn: 'INOCTETS', dsOut: 'OUTOCTETS'),
                    new MapSettings(rrdPeriodSeconds: 900, stalenessSeconds: 660),
                    $now,
                );
                $this->assertEqualsWithDelta(16_000_000.0, $reading->inBps, 100.0);
                $this->assertEqualsWithDelta(8_000_000.0, $reading->outBps, 100.0);
                $this->assertFalse($reading->isStale);
            }
        } finally {
            @unlink($tmpDir.'/rtr1/port-id5.rrd');
            @rmdir($tmpDir.'/rtr1');
            @rmdir($tmpDir);
        }
    }

    public function test_bandwidth_formatter(): void
    {
        $this->assertSame('500', BandwidthFormatter::formatBits(500.0));
        $this->assertSame('12.5K', BandwidthFormatter::formatBits(12_500.0));
        $this->assertSame('250M', BandwidthFormatter::formatBits(250_000_000.0));
        $this->assertSame('1.5G', BandwidthFormatter::formatBits(1_500_000_000.0));
        $this->assertSame(10_000_000_000.0, BandwidthFormatter::parseBandwidth('10G'));
        $this->assertSame(2_500_000_000.0, BandwidthFormatter::parseBandwidth('2.5G'));
    }
}
