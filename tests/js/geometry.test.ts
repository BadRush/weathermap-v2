import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    buildArrowPolygon,
    buildLinkPaths,
    buildOrthogonalRoute,
    classicDefaultScale,
    contrastText,
    findNearestSegmentIndex,
    placeLabelOnCenterline,
    resolveEndpointOffset,
    resolveScaleColor,
    snapOrthogonal,
} from '../../resources/js/shared/geometry';
import type { LinkDef, NodeDef, ScaleDef } from '../../resources/js/shared/types';

interface FixtureData {
    endpointOffsets: Array<{
        node: Pick<NodeDef, 'x' | 'y' | 'iconWidth' | 'iconHeight'>;
        offset: string;
        expected: { x: number; y: number };
    }>;
    links: Array<{
        name: string;
        nodeA: NodeDef;
        nodeB: NodeDef;
        link: LinkDef;
        parallelIndex: number;
        parallelCount: number;
        expected: {
            centerlineCount: number;
            outTip: { x: number; y: number };
            inTip?: { x: number; y: number };
            outLabelPos?: { x: number; y: number };
            inLabelPos?: { x: number; y: number };
            outPolygonCount?: number;
            miterCorners?: Array<{ x: number; y: number }>;
            maxCoordX?: number;
        };
    }>;
    scales: Array<{ percent: number; expected: string }>;
}

const fixtures: FixtureData = JSON.parse(
    readFileSync(resolve(__dirname, '../fixtures/geometry/cases.json'), 'utf8'),
);

describe('TypeScript geometry parity with PHP engine', () => {
    it('resolves endpoint compass and xy offsets identically', () => {
        for (const item of fixtures.endpointOffsets) {
            const pt = resolveEndpointOffset(item.node, item.offset);
            expect(pt.x).toBeCloseTo(item.expected.x, 2);
            expect(pt.y).toBeCloseTo(item.expected.y, 2);
        }
    });

    it('builds link paths, splines, parallel offsets, and arrow polygons identically', () => {
        for (const item of fixtures.links) {
            const paths = buildLinkPaths(item.link, item.nodeA, item.nodeB, item.parallelIndex, item.parallelCount);
            const outPoly = buildArrowPolygon(paths.outSpine, item.link.width, item.link.arrowStyle);
            const inPoly = buildArrowPolygon(paths.inSpine, item.link.width, item.link.arrowStyle);

            expect(paths.centerline).toHaveLength(item.expected.centerlineCount);

            const outTip = paths.outSpine[paths.outSpine.length - 1]!;
            expect(outTip.x).toBeCloseTo(item.expected.outTip.x, 1);
            expect(outTip.y).toBeCloseTo(item.expected.outTip.y, 1);

            if (item.expected.inTip) {
                const inTip = paths.inSpine[paths.inSpine.length - 1]!;
                expect(inTip.x).toBeCloseTo(item.expected.inTip.x, 1);
                expect(inTip.y).toBeCloseTo(item.expected.inTip.y, 1);
            }

            if (item.expected.outLabelPos) {
                const lbl = placeLabelOnCenterline(paths.centerline, item.link.outLabelPos);
                expect(lbl.x).toBeCloseTo(item.expected.outLabelPos.x, 1);
                expect(lbl.y).toBeCloseTo(item.expected.outLabelPos.y, 1);
            }

            if (item.expected.inLabelPos) {
                const lbl = placeLabelOnCenterline(paths.centerline, item.link.inLabelPos);
                expect(lbl.x).toBeCloseTo(item.expected.inLabelPos.x, 1);
                expect(lbl.y).toBeCloseTo(item.expected.inLabelPos.y, 1);
            }

            if (item.expected.outPolygonCount) {
                expect(outPoly).toHaveLength(item.expected.outPolygonCount);
            }

            if (item.expected.miterCorners) {
                for (const corner of item.expected.miterCorners) {
                    const matched = outPoly.some(
                        (pt) => Math.abs(pt.x - corner.x) < 0.05 && Math.abs(pt.y - corner.y) < 0.05,
                    );
                    expect(matched, `Missing miter corner (${corner.x}, ${corner.y}) in ${item.name}`).toBe(true);
                }
            }

            if (item.expected.maxCoordX !== undefined) {
                for (const pt of outPoly) {
                    expect(pt.x).toBeLessThanOrEqual(item.expected.maxCoordX);
                }
            }

            expect(outPoly.length).toBeGreaterThanOrEqual(7);
            expect(inPoly.length).toBeGreaterThanOrEqual(7);
        }
    });

    it('supports segment-aware VIA insertion, orthogonal snap, and L/Z route builders', () => {
        const pts = [
            { x: 100, y: 100 },
            { x: 300, y: 100 },
            { x: 300, y: 300 },
            { x: 500, y: 300 },
        ];
        // Clicking near first horizontal segment inserts at index 0 (before first VIA)
        expect(findNearestSegmentIndex(pts, { x: 180, y: 105 })).toBe(0);
        // Clicking near middle vertical segment inserts at index 1
        expect(findNearestSegmentIndex(pts, { x: 295, y: 210 })).toBe(1);
        // Clicking near final horizontal segment inserts at index 2
        expect(findNearestSegmentIndex(pts, { x: 420, y: 296 })).toBe(2);

        // Soft orthogonal snap pulls within threshold & emits guides
        const snapped = snapOrthogonal({ x: 306, y: 195 }, [{ x: 300, y: 100 }, { x: 500, y: 200 }], 14);
        expect(snapped.point).toEqual({ x: 300, y: 200 });
        expect(snapped.guides).toHaveLength(2);

        // Forced axis snap (Shift held)
        const forced = snapOrthogonal({ x: 350, y: 130 }, [], 14, { x: 100, y: 100 });
        expect(forced.point).toEqual({ x: 350, y: 100 });

        // Auto L & Z orthogonal route builders
        expect(buildOrthogonalRoute({ x: 100, y: 100 }, { x: 500, y: 300 }, 'L-h')).toEqual([{ x: 500, y: 100 }]);
        expect(buildOrthogonalRoute({ x: 100, y: 100 }, { x: 500, y: 300 }, 'Z-h')).toEqual([
            { x: 300, y: 100 },
            { x: 300, y: 300 },
        ]);
    });

    it('resolves classic scale colors and gradient interpolation identically', () => {
        const classic = classicDefaultScale();
        for (const item of fixtures.scales) {
            expect(resolveScaleColor(classic, item.percent)).toBe(item.expected);
        }

        const grad: ScaleDef = {
            name: 'GRAD',
            stops: [{ min: 0, max: 100, rgb1: [0, 0, 0], rgb2: [100, 200, 50], label: null }],
        };
        expect(resolveScaleColor(grad, 50)).toBe('#326419');
        expect(contrastText('#ffffff')).toBe('#0f172a');
        expect(contrastText('#0f172a')).toBe('#f8fafc');
    });
});
