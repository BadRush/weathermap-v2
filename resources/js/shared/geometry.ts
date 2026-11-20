import type { LinkDef, NodeDef, Point, ScaleDef } from './types';

export interface Vector {
    dx: number;
    dy: number;
}

export const COLOR_STALE = '#64748b';
export const COLOR_DOWN = '#991b1b';

function round3(v: number): number {
    return Math.round(v * 1000) / 1000;
}

export function distance(a: Point, b: Point): number {
    return Math.hypot(b.x - a.x, b.y - a.y);
}

export function lerp(a: Point, b: Point, t: number): Point {
    return {
        x: a.x + (b.x - a.x) * t,
        y: a.y + (b.y - a.y) * t,
    };
}

export function vectorTo(a: Point, b: Point): Vector {
    return { dx: b.x - a.x, dy: b.y - a.y };
}

export function vectorLength(v: Vector): number {
    return Math.hypot(v.dx, v.dy);
}

export function normalise(v: Vector): Vector {
    const len = vectorLength(v);
    if (len < 1e-9) {
        return { dx: 0, dy: 0 };
    }
    return { dx: v.dx / len, dy: v.dy / len };
}

export function normalOf(v: Vector): Vector {
    const u = normalise(v);
    return { dx: -u.dy, dy: u.dx };
}

export function dot(a: Vector, b: Vector): number {
    return a.dx * b.dx + a.dy * b.dy;
}

export function cross(a: Vector, b: Vector): number {
    return a.dx * b.dy - a.dy * b.dx;
}

export function addVector(p: Point, v: Vector, scale = 1): Point {
    return { x: p.x + v.dx * scale, y: p.y + v.dy * scale };
}

export function resolveEndpointOffset(node: Pick<NodeDef, 'x' | 'y' | 'iconWidth' | 'iconHeight'>, offset: string): Point {
    const trimmed = offset.trim();
    if (trimmed === '' || trimmed.toUpperCase() === 'C') {
        return { x: node.x, y: node.y };
    }

    const xyMatch = /^([+-]?\d+(?:\.\d+)?):([+-]?\d+(?:\.\d+)?)$/.exec(trimmed);
    if (xyMatch) {
        return {
            x: node.x + Number(xyMatch[1]),
            y: node.y + Number(xyMatch[2]),
        };
    }

    const compassMatch = /^(NE|NW|SE|SW|N|S|E|W)(\d{1,3})?$/i.exec(trimmed);
    if (compassMatch) {
        const dir = (compassMatch[1] ?? '').toUpperCase();
        const pct = compassMatch[2] ? Number(compassMatch[2]) / 100 : 1;
        const hw = (node.iconWidth / 2) * pct;
        const hh = (node.iconHeight / 2) * pct;

        const dx = dir === 'E' || dir === 'NE' || dir === 'SE' ? hw : dir === 'W' || dir === 'NW' || dir === 'SW' ? -hw : 0;
        const dy = dir === 'S' || dir === 'SE' || dir === 'SW' ? hh : dir === 'N' || dir === 'NE' || dir === 'NW' ? -hh : 0;

        return { x: node.x + dx, y: node.y + dy };
    }

    return { x: node.x, y: node.y };
}

export function sampleCatmullRom(controlPoints: Point[], samplesPerSegment = 16): Point[] {
    const count = controlPoints.length;
    if (count <= 2) {
        return [...controlPoints];
    }

    const steps = Math.max(2, samplesPerSegment);
    const first = controlPoints[0]!;
    const last = controlPoints[count - 1]!;
    const pts: Point[] = [first, ...controlPoints, last];
    const result: Point[] = [];

    for (let i = 1; i < pts.length - 2; i++) {
        const p0 = pts[i - 1]!;
        const p1 = pts[i]!;
        const p2 = pts[i + 1]!;
        const p3 = pts[i + 2]!;

        for (let step = 0; step < steps; step++) {
            const t = step / steps;
            const t2 = t * t;
            const t3 = t2 * t;

            const x =
                0.5 *
                (2 * p1.x +
                    (-p0.x + p2.x) * t +
                    (2 * p0.x - 5 * p1.x + 4 * p2.x - p3.x) * t2 +
                    (-p0.x + 3 * p1.x - 3 * p2.x + p3.x) * t3);
            const y =
                0.5 *
                (2 * p1.y +
                    (-p0.y + p2.y) * t +
                    (2 * p0.y - 5 * p1.y + 4 * p2.y - p3.y) * t2 +
                    (-p0.y + 3 * p1.y - 3 * p2.y + p3.y) * t3);

            result.push({ x, y });
        }
    }

    result.push(last);
    return result;
}

export function polylineLength(points: Point[]): number {
    let total = 0;
    for (let i = 1; i < points.length; i++) {
        total += distance(points[i - 1]!, points[i]!);
    }
    return total;
}

export function pointAtFraction(points: Point[], fraction: number): Point {
    const n = points.length;
    if (n === 0) return { x: 0, y: 0 };
    if (n === 1) return points[0]!;

    const clamped = Math.max(0, Math.min(1, fraction));
    const total = polylineLength(points);
    if (total < 1e-9) return points[0]!;

    const target = total * clamped;
    let walked = 0;

    for (let i = 1; i < n; i++) {
        const a = points[i - 1]!;
        const b = points[i]!;
        const segLen = distance(a, b);
        if (walked + segLen >= target && segLen > 1e-9) {
            return lerp(a, b, (target - walked) / segLen);
        }
        walked += segLen;
    }

    return points[n - 1]!;
}

export function splitPolyline(points: Point[], fraction = 0.5): [Point[], Point[]] {
    const n = points.length;
    if (n < 2) {
        return [[...points], [...points].reverse()];
    }

    const clamped = Math.max(0.05, Math.min(0.95, fraction));
    const total = polylineLength(points);
    if (total < 1e-9) {
        return [
            [points[0]!, points[n - 1]!],
            [points[n - 1]!, points[0]!],
        ];
    }

    const target = total * clamped;
    let walked = 0;
    const first: Point[] = [points[0]!];
    const second: Point[] = [];
    let splitFound = false;

    for (let i = 1; i < n; i++) {
        const a = points[i - 1]!;
        const b = points[i]!;
        const segLen = distance(a, b);

        if (!splitFound && walked + segLen >= target && segLen > 1e-9) {
            const mid = lerp(a, b, (target - walked) / segLen);
            if (distance(first[first.length - 1]!, mid) > 1e-6) {
                first.push(mid);
            }
            second.push(mid);
            if (distance(mid, b) > 1e-6) {
                second.push(b);
            }
            splitFound = true;
        } else if (!splitFound) {
            first.push(b);
        } else {
            second.push(b);
        }

        walked += segLen;
    }

    if (!splitFound) {
        second.push(points[n - 1]!);
    }

    return [first, second.reverse()];
}

export function trimEnd(points: Point[], trimDistance: number): { spine: Point[]; tip: Point; direction: Vector } {
    const n = points.length;
    const tip = points[n - 1]!;
    const total = polylineLength(points);

    if (n < 2 || total < 1e-9) {
        return { spine: [...points], tip, direction: { dx: 1, dy: 0 } };
    }

    const keepLength = Math.max(0, total - trimDistance);
    let walked = 0;
    const trimmed: Point[] = [points[0]!];

    for (let i = 1; i < n; i++) {
        const a = points[i - 1]!;
        const b = points[i]!;
        const segLen = distance(a, b);

        if (walked + segLen >= keepLength && segLen > 1e-9) {
            const localT = Math.max(0, Math.min(1, (keepLength - walked) / segLen));
            const endPt = lerp(a, b, localT);
            if (distance(trimmed[trimmed.length - 1]!, endPt) > 1e-6) {
                trimmed.push(endPt);
            }
            let dir = normalise(vectorTo(endPt, tip));
            if (vectorLength(dir) < 1e-6) {
                dir = normalise(vectorTo(a, b));
            }
            return { spine: trimmed, tip, direction: dir };
        }

        trimmed.push(b);
        walked += segLen;
    }

    return {
        spine: trimmed,
        tip,
        direction: normalise(vectorTo(points[n - 2]!, tip)),
    };
}

export function buildLinkPaths(
    link: LinkDef,
    nodeA: NodeDef,
    nodeB: NodeDef,
    parallelIndex = 0,
    parallelCount = 1,
): { centerline: Point[]; outSpine: Point[]; inSpine: Point[] } {
    const start = resolveEndpointOffset(nodeA, link.a.offset);
    const end = resolveEndpointOffset(nodeB, link.b.offset);

    const controlPoints: Point[] = [start];

    if (link.via.length > 0) {
        for (const v of link.via) {
            controlPoints.push({ x: v.x, y: v.y });
        }
    } else if (parallelCount > 1 && link.a.offset.toUpperCase() === 'C' && link.b.offset.toUpperCase() === 'C') {
        const mid = lerp(start, end, 0.5);
        const canonicalDir = nodeA.id <= nodeB.id ? vectorTo(start, end) : vectorTo(end, start);
        const norm = normalOf(canonicalDir);
        const slot = parallelIndex - (parallelCount - 1) / 2;
        const spacing = Math.max(28, link.width * 4);
        controlPoints.push(addVector(mid, norm, slot * spacing));
    }

    controlPoints.push(end);

    const centerline =
        link.viaStyle === 'curved' && controlPoints.length > 2
            ? sampleCatmullRom(controlPoints, 16)
            : controlPoints;

    const [outSpine, inSpine] = splitPolyline(centerline, link.splitPos);
    return { centerline, outSpine, inSpine };
}

const MITER_LIMIT = 2.5;

function deduplicatePoints(points: Point[]): Point[] {
    const clean: Point[] = [];
    for (const pt of points) {
        if (clean.length === 0 || distance(clean[clean.length - 1]!, pt) > 1e-4) {
            clean.push(pt);
        }
    }
    return clean;
}

function appendCornerOffsets(
    body: Point[],
    index: number,
    halfBody: number,
    fallbackDir: Vector,
    leftEdge: Point[],
    rightEdge: Point[],
): void {
    const n = body.length;
    const curr = body[index]!;

    if (n < 2) {
        const norm = normalOf(fallbackDir);
        leftEdge.push(addVector(curr, norm, halfBody));
        rightEdge.push(addVector(curr, norm, -halfBody));
        return;
    }

    if (index === 0) {
        const v = vectorTo(body[0]!, body[1]!);
        const norm = vectorLength(v) > 1e-6 ? normalOf(v) : normalOf(fallbackDir);
        leftEdge.push(addVector(curr, norm, halfBody));
        rightEdge.push(addVector(curr, norm, -halfBody));
        return;
    }

    if (index === n - 1) {
        const v = vectorTo(body[n - 2]!, body[n - 1]!);
        const norm = vectorLength(v) > 1e-6 ? normalOf(v) : normalOf(fallbackDir);
        leftEdge.push(addVector(curr, norm, halfBody));
        rightEdge.push(addVector(curr, norm, -halfBody));
        return;
    }

    const prev = body[index - 1]!;
    const next = body[index + 1]!;

    const v1 = vectorTo(prev, curr);
    const v2 = vectorTo(curr, next);
    const len1 = vectorLength(v1);
    const len2 = vectorLength(v2);
    const u1 = normalise(v1);
    const u2 = normalise(v2);
    const n1 = normalOf(u1);
    const n2 = normalOf(u2);

    const sum: Vector = { dx: n1.dx + n2.dx, dy: n1.dy + n2.dy };
    const sumLen = vectorLength(sum);

    if (sumLen < 1e-6) {
        leftEdge.push(addVector(curr, n1, halfBody));
        leftEdge.push(addVector(curr, n2, halfBody));
        rightEdge.push(addVector(curr, n1, -halfBody));
        rightEdge.push(addVector(curr, n2, -halfBody));
        return;
    }

    const miter = normalise(sum);
    const denom = dot(miter, n1);
    const turn = cross(u1, u2);

    if (Math.abs(turn) < 1e-5 && dot(u1, u2) > 0) {
        leftEdge.push(addVector(curr, miter, halfBody));
        rightEdge.push(addVector(curr, miter, -halfBody));
        return;
    }

    const minDenom = 1 / MITER_LIMIT;
    const miterLen = denom > 1e-6 ? halfBody / denom : halfBody * MITER_LIMIT;
    const maxInner = Math.max(halfBody, Math.min(len1, len2) * 0.95);
    const clampedInner = Math.min(miterLen, maxInner, halfBody * MITER_LIMIT);

    if (denom >= minDenom) {
        if (turn > 0) {
            leftEdge.push(addVector(curr, miter, clampedInner));
            rightEdge.push(addVector(curr, miter, -miterLen));
        } else {
            leftEdge.push(addVector(curr, miter, miterLen));
            rightEdge.push(addVector(curr, miter, -clampedInner));
        }
        return;
    }

    if (turn > 0) {
        leftEdge.push(addVector(curr, miter, clampedInner));
        rightEdge.push(addVector(curr, n1, -halfBody));
        rightEdge.push(addVector(curr, n2, -halfBody));
    } else {
        leftEdge.push(addVector(curr, n1, halfBody));
        leftEdge.push(addVector(curr, n2, halfBody));
        rightEdge.push(addVector(curr, miter, -clampedInner));
    }
}

export function buildArrowPolygon(spine: Point[], width: number, arrowStyle: 'classic' | 'compact' = 'classic'): Point[] {
    const cleanSpine = deduplicatePoints(spine);
    const n = cleanSpine.length;
    if (n < 2) return [];
    const totalLen = polylineLength(cleanSpine);
    if (totalLen < 2) return [];

    const halfBody = Math.max(1, width / 2);
    let headHalfWidth = arrowStyle === 'compact' ? width * 0.9 : width * 1.25;
    let headLength = arrowStyle === 'compact' ? width * 1.8 : width * 2.5;

    if (headLength > totalLen * 0.65) {
        const scale = (totalLen * 0.65) / headLength;
        headLength *= scale;
        headHalfWidth = Math.max(halfBody * 1.2, headHalfWidth * scale);
    }

    if (n >= 3) {
        const prevDir = normalise(vectorTo(cleanSpine[n - 3]!, cleanSpine[n - 2]!));
        const lastDir = normalise(vectorTo(cleanSpine[n - 2]!, cleanSpine[n - 1]!));
        const lastSegLen = distance(cleanSpine[n - 2]!, cleanSpine[n - 1]!);
        const isSharpCorner = dot(prevDir, lastDir) < 0.906;

        if (isSharpCorner && lastSegLen < headLength && lastSegLen >= width * 1.1) {
            const clampedLen = lastSegLen * 0.92;
            const ratio = clampedLen / headLength;
            headLength = clampedLen;
            headHalfWidth = Math.max(halfBody * 1.25, headHalfWidth * Math.max(0.75, ratio));
        }
    }

    const { spine: trimmedSpine, tip, direction: tipDir } = trimEnd(cleanSpine, headLength);
    const body = deduplicatePoints(trimmedSpine);
    const bodyCount = body.length;
    if (bodyCount === 0) return [];

    const leftEdge: Point[] = [];
    const rightEdge: Point[] = [];

    for (let i = 0; i < bodyCount; i++) {
        appendCornerOffsets(body, i, halfBody, tipDir, leftEdge, rightEdge);
    }

    const shoulderCenter = body[bodyCount - 1]!;
    const headNormal = normalOf(tipDir);
    const leftShoulder = addVector(shoulderCenter, headNormal, headHalfWidth);
    const rightShoulder = addVector(shoulderCenter, headNormal, -headHalfWidth);

    const polygon: Point[] = [...leftEdge, leftShoulder, tip, rightShoulder];
    for (let i = rightEdge.length - 1; i >= 0; i--) {
        polygon.push(rightEdge[i]!);
    }

    return polygon.map((p) => ({ x: round3(p.x), y: round3(p.y) }));
}

export interface AlignGuide {
    axis: 'x' | 'y';
    value: number;
}

/**
 * Find the index of the polyline segment [controlPoints[i], controlPoints[i+1]] closest to clickPt.
 * Inserting a new VIA point at this index keeps multi-VIA link ordering clean without zig-zagging.
 */
export function findNearestSegmentIndex(controlPoints: Point[], clickPt: Point): number {
    if (controlPoints.length < 2) return 0;

    let bestIdx = 0;
    let bestDistSq = Number.POSITIVE_INFINITY;

    for (let i = 0; i < controlPoints.length - 1; i++) {
        const a = controlPoints[i]!;
        const b = controlPoints[i + 1]!;
        const abx = b.x - a.x;
        const aby = b.y - a.y;
        const abLenSq = abx * abx + aby * aby;

        let t = 0;
        if (abLenSq > 1e-9) {
            t = Math.max(0, Math.min(1, ((clickPt.x - a.x) * abx + (clickPt.y - a.y) * aby) / abLenSq));
        }
        const projX = a.x + abx * t;
        const projY = a.y + aby * t;
        const dx = clickPt.x - projX;
        const dy = clickPt.y - projY;
        const distSq = dx * dx + dy * dy;

        if (distSq < bestDistSq) {
            bestDistSq = distSq;
            bestIdx = i;
        }
    }

    return bestIdx;
}

/**
 * Snap a point to align horizontally or vertically with neighboring waypoints/endpoints.
 * When forceAxisFrom is set (e.g. holding Shift), forces strict 90-degree alignment from that anchor.
 */
export function snapOrthogonal(
    pt: Point,
    neighbors: Point[],
    threshold = 14,
    forceAxisFrom: Point | null = null,
): { point: Point; guides: AlignGuide[] } {
    if (forceAxisFrom) {
        const dx = Math.abs(pt.x - forceAxisFrom.x);
        const dy = Math.abs(pt.y - forceAxisFrom.y);
        if (dx >= dy) {
            return {
                point: { x: pt.x, y: forceAxisFrom.y },
                guides: [{ axis: 'y', value: forceAxisFrom.y }],
            };
        }
        return {
            point: { x: forceAxisFrom.x, y: pt.y },
            guides: [{ axis: 'x', value: forceAxisFrom.x }],
        };
    }

    let snappedX = pt.x;
    let snappedY = pt.y;
    let bestDx = threshold + 1;
    let bestDy = threshold + 1;
    const guides: AlignGuide[] = [];

    for (const nb of neighbors) {
        const dx = Math.abs(pt.x - nb.x);
        if (dx <= threshold && dx < bestDx) {
            bestDx = dx;
            snappedX = nb.x;
        }
        const dy = Math.abs(pt.y - nb.y);
        if (dy <= threshold && dy < bestDy) {
            bestDy = dy;
            snappedY = nb.y;
        }
    }

    if (bestDx <= threshold) guides.push({ axis: 'x', value: snappedX });
    if (bestDy <= threshold) guides.push({ axis: 'y', value: snappedY });

    return { point: { x: snappedX, y: snappedY }, guides };
}

/**
 * Generate clean 1-bend (L) or 2-bend (Z) orthogonal VIA points between start and end.
 */
export function buildOrthogonalRoute(start: Point, end: Point, mode: 'L-h' | 'L-v' | 'Z-h' | 'Z-v'): Point[] {
    if (mode === 'L-h') {
        return [{ x: Math.round(end.x), y: Math.round(start.y) }];
    }
    if (mode === 'L-v') {
        return [{ x: Math.round(start.x), y: Math.round(end.y) }];
    }
    if (mode === 'Z-h') {
        const midX = Math.round((start.x + end.x) / 2);
        return [
            { x: midX, y: Math.round(start.y) },
            { x: midX, y: Math.round(end.y) },
        ];
    }
    const midY = Math.round((start.y + end.y) / 2);
    return [
        { x: Math.round(start.x), y: midY },
        { x: Math.round(end.x), y: midY },
    ];
}

export function placeLabelOnCenterline(centerline: Point[], fraction: number): Point {
    const pt = pointAtFraction(centerline, Math.max(0.05, Math.min(0.95, fraction)));
    return { x: round3(pt.x), y: round3(pt.y) };
}

export function placeNodeLabel(node: Pick<NodeDef, 'x' | 'y' | 'iconWidth' | 'iconHeight' | 'labelOffset'>): Point {
    const offset = node.labelOffset.trim();
    if (offset === '' || offset.toUpperCase() === 'C') {
        return { x: round3(node.x), y: round3(node.y) };
    }

    const xyMatch = /^([+-]?\d+(?:\.\d+)?):([+-]?\d+(?:\.\d+)?)$/.exec(offset);
    if (xyMatch) {
        return {
            x: round3(node.x + Number(xyMatch[1])),
            y: round3(node.y + Number(xyMatch[2])),
        };
    }

    const padX = node.iconWidth / 2 + 12;
    const padY = node.iconHeight / 2 + 12;
    const dir = offset.toUpperCase();
    const dx = dir === 'E' || dir === 'NE' || dir === 'SE' ? padX : dir === 'W' || dir === 'NW' || dir === 'SW' ? -padX : 0;
    const dy = dir === 'S' || dir === 'SE' || dir === 'SW' ? padY : dir === 'N' || dir === 'NE' || dir === 'NW' ? -padY : 0;

    return { x: round3(node.x + dx), y: round3(node.y + dy) };
}

export function rgbToHex(rgb: [number, number, number]): string {
    const clamp = (c: number) => Math.max(0, Math.min(255, Math.round(c))).toString(16).padStart(2, '0');
    return `#${clamp(rgb[0])}${clamp(rgb[1])}${clamp(rgb[2])}`;
}

export function hexToRgb(hex: string): [number, number, number] {
    let clean = hex.trim().replace(/^#/, '');
    if (clean.length === 3) {
        clean = clean[0]! + clean[0]! + clean[1]! + clean[1]! + clean[2]! + clean[2]!;
    }
    if (!/^[0-9a-fA-F]{6}$/.test(clean)) {
        return [100, 116, 139];
    }
    return [
        parseInt(clean.slice(0, 2), 16),
        parseInt(clean.slice(2, 4), 16),
        parseInt(clean.slice(4, 6), 16),
    ];
}

export function resolveScaleColor(scale: ScaleDef, percent: number | null, isDown = false, isStale = false): string {
    if (isDown) return COLOR_DOWN;
    if (isStale || percent === null || Number.isNaN(percent)) return COLOR_STALE;

    const pct = Math.max(0, Math.min(100, percent));
    for (const stop of scale.stops) {
        if (stop.min === stop.max) {
            if (pct === stop.min) {
                return rgbToHex(stop.rgb1);
            }
            continue;
        }
        if (pct >= stop.min && pct <= stop.max) {
            if (!stop.rgb2) {
                return rgbToHex(stop.rgb1);
            }
            const ratio = (pct - stop.min) / (stop.max - stop.min);
            const r = Math.round(stop.rgb1[0] + (stop.rgb2[0] - stop.rgb1[0]) * ratio);
            const g = Math.round(stop.rgb1[1] + (stop.rgb2[1] - stop.rgb1[1]) * ratio);
            const b = Math.round(stop.rgb1[2] + (stop.rgb2[2] - stop.rgb1[2]) * ratio);
            return rgbToHex([r, g, b]);
        }
    }

    const last = scale.stops[scale.stops.length - 1];
    return last ? rgbToHex(last.rgb2 ?? last.rgb1) : COLOR_STALE;
}

export function contrastText(bgHex: string): string {
    const [r, g, b] = hexToRgb(bgHex);
    const lum = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
    return lum > 0.52 ? '#0f172a' : '#f8fafc';
}

export function polygonToSvgPoints(pts: Point[]): string {
    return pts.map((p) => `${round3(p.x)},${round3(p.y)}`).join(' ');
}

export function classicDefaultScale(): ScaleDef {
    return {
        name: 'DEFAULT',
        stops: [
            { min: 0, max: 0, rgb1: [192, 192, 192], rgb2: null, label: null },
            { min: 0, max: 1, rgb1: [255, 255, 255], rgb2: null, label: null },
            { min: 1, max: 10, rgb1: [140, 0, 255], rgb2: null, label: null },
            { min: 10, max: 25, rgb1: [32, 32, 255], rgb2: null, label: null },
            { min: 25, max: 40, rgb1: [0, 192, 255], rgb2: null, label: null },
            { min: 40, max: 55, rgb1: [0, 240, 0], rgb2: null, label: null },
            { min: 55, max: 70, rgb1: [240, 240, 0], rgb2: null, label: null },
            { min: 70, max: 85, rgb1: [255, 192, 0], rgb2: null, label: null },
            { min: 85, max: 100, rgb1: [255, 0, 0], rgb2: null, label: null },
        ],
    };
}

export function formatBits(bps: number): string {
    const abs = Math.abs(bps);
    const sign = bps < 0 ? '-' : '';
    const trim = (val: number) => {
        const s = val >= 100 ? val.toFixed(0) : val.toFixed(1);
        return s.endsWith('.0') ? s.slice(0, -2) : s;
    };
    if (abs >= 1e12) return `${sign}${trim(abs / 1e12)}T`;
    if (abs >= 1e9) return `${sign}${trim(abs / 1e9)}G`;
    if (abs >= 1e6) return `${sign}${trim(abs / 1e6)}M`;
    if (abs >= 1e3) return `${sign}${trim(abs / 1e3)}K`;
    return `${sign}${Math.round(abs)}`;
}
