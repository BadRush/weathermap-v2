<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Data;

use BadRush\Weathermap\Document\MapSettings;
use BadRush\Weathermap\Document\TargetExpr;
use BadRush\Weathermap\Document\TargetTerm;

/**
 * Resolves a TargetExpr (one or more port, RRD, or static terms) with scale factors,
 * direction reversal, negation, and summation (LAG/bundle aggregation).
 */
final class TargetResolver
{
    /**
     * @param  list<DataSourceInterface>  $sources
     */
    public function __construct(
        private readonly array $sources,
    ) {}

    public function resolve(TargetExpr $expr, MapSettings $settings, ?int $now = null): DataReading
    {
        $now ??= time();

        if ($expr->isEmpty()) {
            return DataReading::empty();
        }

        $totalIn = 0.0;
        $totalOut = 0.0;
        $totalCapIn = 0.0;
        $totalCapOut = 0.0;
        $hasCap = false;
        $hasValid = false;
        $allStale = true;
        $allDown = true;
        $anyTerm = false;
        $latestTs = null;
        $primaryPortId = null;
        $primaryDeviceId = null;
        $portLabel = null;
        $errors = [];

        foreach ($expr->terms as $term) {
            $anyTerm = true;
            $source = $this->findSource($term);
            if ($source === null) {
                $errors[] = "Unsupported datasource '{$term->source}'.";

                continue;
            }

            $reading = $source->read($term, $settings, $now);

            if ($primaryPortId === null && $reading->primaryPortId !== null) {
                $primaryPortId = $reading->primaryPortId;
                $primaryDeviceId = $reading->primaryDeviceId;
                $portLabel = $reading->portLabel;
            }

            if ($reading->error !== null) {
                $errors[] = $reading->error;
            }

            if ($reading->capacityInBps !== null && $reading->capacityInBps > 0.0) {
                $capIn = $term->reverse ? ($reading->capacityOutBps ?? $reading->capacityInBps) : $reading->capacityInBps;
                $capOut = $term->reverse ? $reading->capacityInBps : ($reading->capacityOutBps ?? $reading->capacityInBps);
                $totalCapIn += $capIn;
                $totalCapOut += $capOut;
                $hasCap = true;
            }

            if (! $reading->isDown) {
                $allDown = false;
            }
            if (! $reading->isStale) {
                $allStale = false;
            }

            if ($reading->timestamp !== null && ($latestTs === null || $reading->timestamp > $latestTs)) {
                $latestTs = $reading->timestamp;
            }

            if ($reading->inBps === null && $reading->outBps === null) {
                continue;
            }

            $hasValid = true;
            $in = $reading->inBps ?? 0.0;
            $out = $reading->outBps ?? 0.0;

            if ($term->reverse) {
                [$in, $out] = [$out, $in];
            }

            $multiplier = $term->factor * ($term->negate ? -1.0 : 1.0);
            $totalIn += $in * $multiplier;
            $totalOut += $out * $multiplier;
        }

        if (! $anyTerm || ! $hasValid) {
            return new DataReading(
                inBps: null,
                outBps: null,
                capacityInBps: $hasCap ? $totalCapIn : null,
                capacityOutBps: $hasCap ? $totalCapOut : null,
                timestamp: $latestTs,
                isStale: true,
                isDown: $anyTerm && $allDown,
                primaryPortId: $primaryPortId,
                primaryDeviceId: $primaryDeviceId,
                portLabel: $portLabel,
                error: $errors !== [] ? implode('; ', $errors) : null,
            );
        }

        return new DataReading(
            inBps: max(0.0, $totalIn),
            outBps: max(0.0, $totalOut),
            capacityInBps: $hasCap ? $totalCapIn : null,
            capacityOutBps: $hasCap ? $totalCapOut : null,
            timestamp: $latestTs ?? $now,
            isStale: $allStale,
            isDown: $allDown,
            primaryPortId: $primaryPortId,
            primaryDeviceId: $primaryDeviceId,
            portLabel: $portLabel,
            error: $errors !== [] ? implode('; ', $errors) : null,
        );
    }

    private function findSource(TargetTerm $term): ?DataSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->supports($term)) {
                return $source;
            }
        }

        return null;
    }
}
