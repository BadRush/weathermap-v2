<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Tests\Unit;

use BadRush\Weathermap\Document\CapacityDef;
use BadRush\Weathermap\Document\LinkDef;
use BadRush\Weathermap\Document\NodeDef;
use BadRush\Weathermap\Document\TargetTerm;
use BadRush\Weathermap\Import\ConfImporter;
use PHPUnit\Framework\TestCase;

final class ConfImporterTest extends TestCase
{
    public function test_imports_classic_conf_with_defaults_relative_nodes_lag_and_warnings(): void
    {
        $conf = file_get_contents(dirname(__DIR__).'/fixtures/conf/sample.conf');
        $this->assertIsString($conf);

        $importer = new ConfImporter;
        $result = $importer->import($conf, static fn (int $portId): bool => in_array($portId, [42, 43], true));

        $doc = $result['document'];
        $report = $result['report'];

        $this->assertSame(1200, $doc->canvas->width);
        $this->assertSame(720, $doc->canvas->height);
        $this->assertSame('Core Backbone Network', $doc->canvas->title);
        $this->assertSame(1100, $doc->settings->rrdPeriodSeconds);

        // Two nodes, with core2 positioned relative to core1 (+560, +40) and inheriting NODE DEFAULT
        $this->assertSame(2, $report->nodesImported);
        $core1 = $doc->getNode('core1');
        $core2 = $doc->getNode('core2');
        $this->assertNotNull($core1);
        $this->assertNotNull($core2);
        $this->assertSame(220.0, $core1->x);
        $this->assertSame(260.0, $core1->y);
        $this->assertSame(780.0, $core2->x);
        $this->assertSame(300.0, $core2->y);
        $this->assertSame(NodeDef::ICON_SHAPE_ROUND, $core1->iconType);
        $this->assertSame('S', $core1->labelOffset);

        // Two links
        $this->assertSame(2, $report->linksImported);
        $lag = $doc->links[0];
        $this->assertSame('core1-core2-lag', $lag->id);
        $this->assertSame('E', $lag->a->offset);
        $this->assertSame('W', $lag->b->offset);
        $this->assertSame(LinkDef::ARROW_COMPACT, $lag->arrowStyle);
        $this->assertSame(LinkDef::BWLABEL_BITS, $lag->bwLabel);
        $this->assertSame(CapacityDef::SOURCE_MANUAL, $lag->capacity->source);
        $this->assertSame(20_000_000_000.0, $lag->capacity->inBps);
        $this->assertCount(2, $lag->target->terms);
        $this->assertSame(TargetTerm::SOURCE_PORT, $lag->target->terms[0]->source);
        $this->assertSame(42, $lag->target->terms[0]->portId);
        $this->assertSame(43, $lag->target->terms[1]->portId);

        // Backup link keeps static target, skips unsafe !external and snmp: with warnings
        $backup = $doc->links[1];
        $this->assertSame(LinkDef::VIA_ANGLED, $backup->viaStyle);
        $this->assertCount(1, $backup->target->terms);
        $this->assertSame(TargetTerm::SOURCE_STATIC, $backup->target->terms[0]->source);

        // Warnings and skipped legacy directives are transparently reported
        $warningJoined = implode("\n", $report->warnings);
        $this->assertStringContainsString('BACKGROUND', $warningJoined);
        $this->assertStringContainsString('unsafe.sh', $warningJoined);
        $this->assertStringContainsString('snmp:', $warningJoined);
        $this->assertContains('OVERLIBGRAPH', $report->skippedDirectives);
        $this->assertContains('HTMLSTYLE', $report->skippedDirectives);
    }
}
