<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ApiError, apiGet, apiJson } from '../shared/api';
import {
    type AlignGuide,
    buildArrowPolygon,
    buildLinkPaths,
    buildOrthogonalRoute,
    classicDefaultScale,
    findNearestSegmentIndex,
    lerp,
    placeLabelOnCenterline,
    placeNodeLabel,
    polygonToSvgPoints,
    resolveEndpointOffset,
    resolveScaleColor,
    rgbToHex,
    snapOrthogonal,
} from '../shared/geometry';
import type {
    AccessRule,
    AssetItem,
    LegendDef,
    LinkDef,
    MapDetail,
    MapDocument,
    NodeDef,
    Point,
    ScaleDef,
} from '../shared/types';
import { EditorHistory } from './history';
import AccessModal from './AccessModal.vue';
import AssetsModal from './AssetsModal.vue';
import ImportModal from './ImportModal.vue';
import PropertyPanel from './PropertyPanel.vue';
import ScaleLegendModal from './ScaleLegendModal.vue';

const props = defineProps<{
    mapId: number;
}>();

type ToolMode = 'select' | 'add-node' | 'add-link';

const mapName = ref('');
const revision = ref(1);
const pngEnabled = ref(true);
const accessRules = ref<AccessRule[]>([]);
const doc = ref<MapDocument | null>(null);
const loading = ref(true);
const saving = ref(false);
const dirty = ref(false);
const statusToast = ref<string | null>(null);
const errorBanner = ref<string | null>(null);

const tool = ref<ToolMode>('select');
const selectedNodeIds = ref<string[]>([]);
const selectedLinkId = ref<string | null>(null);
const selectedViaIndex = ref<number | null>(null);
const linkStartNodeId = ref<string | null>(null);
const newLinkViaStyle = ref<'angled' | 'curved'>('angled');
const draftLinkWaypoints = ref<Point[]>([]);
const draftCursorPos = ref<Point | null>(null);
const orthoSnap = ref(true);
const alignGuides = ref<AlignGuide[]>([]);
const routeFlipToggle = ref(false);

const zoom = ref(1);
const pan = ref<Point>({ x: 0, y: 0 });
const isPanning = ref(false);
const panStart = ref<Point>({ x: 0, y: 0 });

const dragState = ref<
    | null
    | { kind: 'nodes'; startSvg: Point; initialPositions: Record<string, Point> }
    | { kind: 'via'; linkId: string; viaIndex: number }
    | { kind: 'legend'; offset: Point }
    | { kind: 'marquee'; start: Point; current: Point }
>(null);

const scaleModalOpen = ref(false);
const assetsModalOpen = ref(false);
const assetsModalKind = ref<'background' | 'icon'>('background');
const importModalOpen = ref(false);
const accessModalOpen = ref(false);
const conflictDialogRef = ref<HTMLDialogElement | null>(null);
const conflictServerRevision = ref<number | null>(null);

const svgRef = ref<SVGSVGElement | null>(null);
let history: EditorHistory<MapDocument> | null = null;
const historyVersion = ref(0);

const canUndo = computed(() => {
    void historyVersion.value;
    return history?.canUndo() ?? false;
});
const canRedo = computed(() => {
    void historyVersion.value;
    return history?.canRedo() ?? false;
});

const selectedNode = computed<NodeDef | null>(() => {
    if (!doc.value || selectedNodeIds.value.length !== 1) return null;
    return doc.value.nodes.find((n) => n.id === selectedNodeIds.value[0]) ?? null;
});

const selectedLink = computed<LinkDef | null>(() => {
    if (!doc.value || !selectedLinkId.value) return null;
    return doc.value.links.find((l) => l.id === selectedLinkId.value) ?? null;
});

const previewLinks = computed(() => {
    if (!doc.value) return [];
    const nodeMap = new Map(doc.value.nodes.map((n) => [n.id, n]));
    const pairGroups = new Map<string, string[]>();
    for (const l of doc.value.links) {
        const key = l.a.nodeId <= l.b.nodeId ? `${l.a.nodeId}::${l.b.nodeId}` : `${l.b.nodeId}::${l.a.nodeId}`;
        const list = pairGroups.get(key) ?? [];
        list.push(l.id);
        pairGroups.set(key, list);
    }

    const scale = doc.value.scales[0] ?? classicDefaultScale();
    const previewOutColor = resolveScaleColor(scale, 35);
    const previewInColor = resolveScaleColor(scale, 50);

    return doc.value.links
        .map((link) => {
            const nodeA = nodeMap.get(link.a.nodeId);
            const nodeB = nodeMap.get(link.b.nodeId);
            if (!nodeA || !nodeB) return null;
            const key = nodeA.id <= nodeB.id ? `${nodeA.id}::${nodeB.id}` : `${nodeB.id}::${nodeA.id}`;
            const siblings = pairGroups.get(key) ?? [link.id];
            const idx = siblings.indexOf(link.id);
            const paths = buildLinkPaths(link, nodeA, nodeB, idx, siblings.length);
            const outPoly = buildArrowPolygon(paths.outSpine, link.width, link.arrowStyle);
            const inPoly = buildArrowPolygon(paths.inSpine, link.width, link.arrowStyle);
            return {
                link,
                outPoints: polygonToSvgPoints(outPoly),
                inPoints: polygonToSvgPoints(inPoly),
                outColor: previewOutColor,
                inColor: previewInColor,
                outLabelPos: placeLabelOnCenterline(paths.centerline, link.outLabelPos),
                inLabelPos: placeLabelOnCenterline(paths.centerline, link.inLabelPos),
            };
        })
        .filter((item): item is NonNullable<typeof item> => item !== null);
});

const activeLegendScale = computed<ScaleDef>(() => {
    if (!doc.value) return classicDefaultScale();
    return doc.value.scales.find((s) => s.name === doc.value!.legend.scaleName) ?? doc.value.scales[0] ?? classicDefaultScale();
});

function getLinkControlPoints(link: LinkDef): Point[] {
    if (!doc.value) return [];
    const nodeA = doc.value.nodes.find((n) => n.id === link.a.nodeId);
    const nodeB = doc.value.nodes.find((n) => n.id === link.b.nodeId);
    if (!nodeA || !nodeB) return [];
    return [
        resolveEndpointOffset(nodeA, link.a.offset),
        ...link.via.map((v) => ({ x: v.x, y: v.y })),
        resolveEndpointOffset(nodeB, link.b.offset),
    ];
}

const selectedLinkOverlay = computed(() => {
    if (!selectedLink.value) return null;
    const pts = getLinkControlPoints(selectedLink.value);
    if (pts.length < 2) return null;
    const midpoints: Array<{ segmentIndex: number; point: Point }> = [];
    for (let i = 0; i < pts.length - 1; i++) {
        midpoints.push({
            segmentIndex: i,
            point: lerp(pts[i]!, pts[i + 1]!, 0.5),
        });
    }
    return {
        link: selectedLink.value,
        polylinePoints: polygonToSvgPoints(pts),
        midpoints,
    };
});

const draftLinkPreview = computed(() => {
    if (tool.value !== 'add-link' || !linkStartNodeId.value || !doc.value) return null;
    const startNode = doc.value.nodes.find((n) => n.id === linkStartNodeId.value);
    if (!startNode) return null;
    const pts: Point[] = [{ x: startNode.x, y: startNode.y }, ...draftLinkWaypoints.value];
    if (draftCursorPos.value) {
        pts.push(draftCursorPos.value);
    }
    return {
        points: pts,
        polyline: polygonToSvgPoints(pts),
    };
});

async function loadMap(): Promise<void> {
    loading.value = true;
    errorBanner.value = null;
    try {
        const res = await apiGet<{ map: MapDetail }>(`/weathermap/api/maps/${props.mapId}`);
        mapName.value = res.map.name;
        revision.value = res.map.revision;
        pngEnabled.value = res.map.png_enabled;
        accessRules.value = res.map.accessRules;
        doc.value = res.map.document;
        history = new EditorHistory(res.map.document);
        historyVersion.value += 1;
        dirty.value = false;
    } catch (e) {
        errorBanner.value = e instanceof Error ? e.message : 'Failed to load map.';
    } finally {
        loading.value = false;
    }
}

function commitHistory(): void {
    if (!doc.value || !history) return;
    history.commit(doc.value);
    historyVersion.value += 1;
    dirty.value = true;
}

function undo(): void {
    if (!history) return;
    const prev = history.undo();
    if (prev) {
        doc.value = prev;
        historyVersion.value += 1;
        dirty.value = true;
    }
}

function redo(): void {
    if (!history) return;
    const next = history.redo();
    if (next) {
        doc.value = next;
        historyVersion.value += 1;
        dirty.value = true;
    }
}

async function saveMap(): Promise<void> {
    if (!doc.value || saving.value) return;
    saving.value = true;
    errorBanner.value = null;
    try {
        const res = await apiJson<{ map: MapDetail }>('PUT', `/weathermap/api/maps/${props.mapId}`, {
            name: mapName.value.trim() || 'Untitled Map',
            png_enabled: pngEnabled.value,
            revision: revision.value,
            document: doc.value,
        });
        revision.value = res.map.revision;
        dirty.value = false;
        showToast(`Saved (rev ${res.map.revision})`);
    } catch (e) {
        if (e instanceof ApiError && e.status === 409) {
            conflictServerRevision.value = typeof e.payload.currentRevision === 'number' ? e.payload.currentRevision : null;
            conflictDialogRef.value?.showModal();
        } else {
            errorBanner.value = e instanceof Error ? e.message : 'Save failed.';
        }
    } finally {
        saving.value = false;
    }
}

function showToast(msg: string): void {
    statusToast.value = msg;
    window.setTimeout(() => {
        if (statusToast.value === msg) statusToast.value = null;
    }, 2600);
}

function snap(v: number): number {
    if (!doc.value?.settings.snapToGrid) return Math.round(v);
    const g = doc.value.settings.gridSize || 20;
    return Math.round(v / g) * g;
}

function clientToSvg(e: MouseEvent): Point {
    const svg = svgRef.value;
    if (!svg) return { x: 0, y: 0 };
    const rect = svg.getBoundingClientRect();
    const x = (e.clientX - rect.left - pan.value.x) / zoom.value;
    const y = (e.clientY - rect.top - pan.value.y) / zoom.value;
    return { x, y };
}

function onCanvasMouseDown(e: MouseEvent): void {
    if (!doc.value) return;

    // Middle mouse button or Alt+drag pans the canvas
    if (e.button === 1 || e.altKey) {
        isPanning.value = true;
        panStart.value = { x: e.clientX - pan.value.x, y: e.clientY - pan.value.y };
        return;
    }

    if (e.button !== 0) return;
    const pt = clientToSvg(e);

    if (tool.value === 'add-node') {
        const nextNum = doc.value.nodes.length + 1;
        let id = `node_${nextNum}`;
        let suffix = nextNum;
        while (doc.value.nodes.some((n) => n.id === id)) {
            suffix += 1;
            id = `node_${suffix}`;
        }
        doc.value.nodes.push({
            id,
            label: `Node ${suffix}`,
            x: snap(pt.x),
            y: snap(pt.y),
            iconType: 'shape:box',
            iconAssetId: null,
            deviceOsIcon: null,
            iconWidth: 84,
            iconHeight: 38,
            deviceId: null,
            labelOffset: 'C',
            labelFontSize: 11,
            labelColor: '#f8fafc',
            bgColor: '#1e293b',
            outlineColor: '#38bdf8',
            zOrder: 600,
            infoUrl: null,
            scaleName: null,
            target: null,
            maxValue: 100,
        });
        selectedNodeIds.value = [id];
        selectedLinkId.value = null;
        tool.value = 'select';
        commitHistory();
        return;
    }

    if (tool.value === 'add-link' && linkStartNodeId.value) {
        const anchor =
            draftLinkWaypoints.value.length > 0
                ? draftLinkWaypoints.value[draftLinkWaypoints.value.length - 1]!
                : (() => {
                      const startNode = doc.value!.nodes.find((n) => n.id === linkStartNodeId.value);
                      return startNode ? { x: startNode.x, y: startNode.y } : null;
                  })();
        const rawPt = { x: snap(pt.x), y: snap(pt.y) };
        const snapped =
            anchor && (e.shiftKey || orthoSnap.value)
                ? snapOrthogonal(rawPt, [anchor], 14, e.shiftKey ? anchor : null).point
                : rawPt;
        draftLinkWaypoints.value = [...draftLinkWaypoints.value, { x: Math.round(snapped.x), y: Math.round(snapped.y) }];
        alignGuides.value = [];
        return;
    }

    if (tool.value === 'select') {
        selectedNodeIds.value = [];
        selectedLinkId.value = null;
        selectedViaIndex.value = null;
        dragState.value = { kind: 'marquee', start: pt, current: pt };
    }
}

function onNodeMouseDown(node: NodeDef, e: MouseEvent): void {
    if (!doc.value) return;
    e.stopPropagation();

    if (tool.value === 'add-link') {
        if (!linkStartNodeId.value) {
            linkStartNodeId.value = node.id;
            draftLinkWaypoints.value = [];
            draftCursorPos.value = null;
            showToast(`Start: ${node.label || node.id}. Click canvas to add bends (Shift=90°), then click destination node.`);
        } else if (linkStartNodeId.value !== node.id) {
            const id = `link_${Date.now().toString(36)}`;
            doc.value.links.push({
                id,
                a: { nodeId: linkStartNodeId.value, offset: 'C' },
                b: { nodeId: node.id, offset: 'C' },
                via: draftLinkWaypoints.value.map((p) => ({ x: p.x, y: p.y })),
                viaStyle: newLinkViaStyle.value,
                width: 7,
                arrowStyle: 'classic',
                splitPos: 0.5,
                bwLabel: 'percent',
                inLabelPos: 0.75,
                outLabelPos: 0.25,
                bwFontSize: 9,
                outlineColor: '#0f172a',
                scaleName: 'DEFAULT',
                capacity: { source: 'ifSpeed', inBps: 1_000_000_000, outBps: 1_000_000_000 },
                target: { terms: [] },
                infoUrl: null,
                inComment: '',
                outComment: '',
                zOrder: 300,
            });
            linkStartNodeId.value = null;
            draftLinkWaypoints.value = [];
            draftCursorPos.value = null;
            alignGuides.value = [];
            selectedNodeIds.value = [];
            selectedLinkId.value = id;
            selectedViaIndex.value = null;
            tool.value = 'select';
            commitHistory();
        }
        return;
    }

    selectedLinkId.value = null;
    selectedViaIndex.value = null;
    if (e.shiftKey) {
        if (selectedNodeIds.value.includes(node.id)) {
            selectedNodeIds.value = selectedNodeIds.value.filter((id) => id !== node.id);
        } else {
            selectedNodeIds.value = [...selectedNodeIds.value, node.id];
        }
    } else if (!selectedNodeIds.value.includes(node.id)) {
        selectedNodeIds.value = [node.id];
    }

    const initialPositions: Record<string, Point> = {};
    for (const id of selectedNodeIds.value) {
        const n = doc.value.nodes.find((item) => item.id === id);
        if (n) initialPositions[id] = { x: n.x, y: n.y };
    }
    dragState.value = { kind: 'nodes', startSvg: clientToSvg(e), initialPositions };
}

function onLinkClick(link: LinkDef, e: MouseEvent): void {
    e.stopPropagation();
    if (tool.value !== 'select') return;
    selectedNodeIds.value = [];
    selectedLinkId.value = link.id;
    selectedViaIndex.value = null;
}

function onLinkDoubleClick(link: LinkDef, e: MouseEvent): void {
    e.stopPropagation();
    const pt = clientToSvg(e);
    const controlPts = getLinkControlPoints(link);
    const segIdx = findNearestSegmentIndex(controlPts, pt);
    link.via.splice(segIdx, 0, { x: snap(pt.x), y: snap(pt.y) });
    selectedLinkId.value = link.id;
    selectedViaIndex.value = segIdx;
    selectedNodeIds.value = [];
    commitHistory();
}

function onMidpointMouseDown(link: LinkDef, segmentIndex: number, pt: Point, e: MouseEvent): void {
    e.stopPropagation();
    link.via.splice(segmentIndex, 0, { x: snap(pt.x), y: snap(pt.y) });
    selectedLinkId.value = link.id;
    selectedViaIndex.value = segmentIndex;
    selectedNodeIds.value = [];
    dragState.value = { kind: 'via', linkId: link.id, viaIndex: segmentIndex };
}

function onViaMouseDown(link: LinkDef, idx: number, e: MouseEvent): void {
    e.stopPropagation();
    selectedLinkId.value = link.id;
    selectedViaIndex.value = idx;
    selectedNodeIds.value = [];
    dragState.value = { kind: 'via', linkId: link.id, viaIndex: idx };
}

function removeViaPoint(link: LinkDef, idx: number, e: MouseEvent): void {
    e.preventDefault();
    e.stopPropagation();
    link.via.splice(idx, 1);
    selectedViaIndex.value = null;
    commitHistory();
}

function applyRoutePreset(kind: 'L' | 'Z'): void {
    if (!selectedLink.value || !doc.value) return;
    const nodeA = doc.value.nodes.find((n) => n.id === selectedLink.value!.a.nodeId);
    const nodeB = doc.value.nodes.find((n) => n.id === selectedLink.value!.b.nodeId);
    if (!nodeA || !nodeB) return;

    const start = resolveEndpointOffset(nodeA, selectedLink.value.a.offset);
    const end = resolveEndpointOffset(nodeB, selectedLink.value.b.offset);
    routeFlipToggle.value = !routeFlipToggle.value;
    const mode = kind === 'L' ? (routeFlipToggle.value ? 'L-h' : 'L-v') : routeFlipToggle.value ? 'Z-h' : 'Z-v';

    selectedLink.value.viaStyle = 'angled';
    selectedLink.value.via = buildOrthogonalRoute(start, end, mode);
    selectedViaIndex.value = null;
    commitHistory();
}

function addViaMidpoint(): void {
    if (!selectedLink.value) return;
    const pts = getLinkControlPoints(selectedLink.value);
    if (pts.length < 2) return;
    // Insert at the midpoint of the longest segment
    let longestIdx = 0;
    let longestLen = -1;
    for (let i = 0; i < pts.length - 1; i++) {
        const a = pts[i]!;
        const b = pts[i + 1]!;
        const len = Math.hypot(b.x - a.x, b.y - a.y);
        if (len > longestLen) {
            longestLen = len;
            longestIdx = i;
        }
    }
    const mid = lerp(pts[longestIdx]!, pts[longestIdx + 1]!, 0.5);
    selectedLink.value.via.splice(longestIdx, 0, { x: snap(mid.x), y: snap(mid.y) });
    selectedViaIndex.value = longestIdx;
    commitHistory();
}

function onLegendMouseDown(e: MouseEvent): void {
    if (!doc.value) return;
    e.stopPropagation();
    const pt = clientToSvg(e);
    dragState.value = {
        kind: 'legend',
        offset: { x: pt.x - doc.value.legend.x, y: pt.y - doc.value.legend.y },
    };
}

function onMouseMove(e: MouseEvent): void {
    if (isPanning.value) {
        pan.value = { x: e.clientX - panStart.value.x, y: e.clientY - panStart.value.y };
        return;
    }

    if (!doc.value) return;
    const pt = clientToSvg(e);

    // Live cursor preview while drawing multi-VIA link
    if (tool.value === 'add-link' && linkStartNodeId.value) {
        const anchor =
            draftLinkWaypoints.value.length > 0
                ? draftLinkWaypoints.value[draftLinkWaypoints.value.length - 1]!
                : (() => {
                      const startNode = doc.value!.nodes.find((n) => n.id === linkStartNodeId.value);
                      return startNode ? { x: startNode.x, y: startNode.y } : null;
                  })();
        const rawPt = { x: snap(pt.x), y: snap(pt.y) };
        if (anchor && (e.shiftKey || orthoSnap.value)) {
            const res = snapOrthogonal(rawPt, [anchor], 14, e.shiftKey ? anchor : null);
            draftCursorPos.value = res.point;
            alignGuides.value = res.guides;
        } else {
            draftCursorPos.value = rawPt;
            alignGuides.value = [];
        }
        return;
    }

    if (!dragState.value) return;

    if (dragState.value.kind === 'nodes') {
        const dx = pt.x - dragState.value.startSvg.x;
        const dy = pt.y - dragState.value.startSvg.y;
        for (const [id, orig] of Object.entries(dragState.value.initialPositions)) {
            const node = doc.value.nodes.find((n) => n.id === id);
            if (node) {
                node.x = snap(orig.x + dx);
                node.y = snap(orig.y + dy);
            }
        }
    } else if (dragState.value.kind === 'via') {
        const { linkId, viaIndex } = dragState.value;
        const link = doc.value.links.find((l) => l.id === linkId);
        const viaPt = link?.via[viaIndex];
        if (link && viaPt) {
            const rawPt = { x: snap(pt.x), y: snap(pt.y) };
            const controlPts = getLinkControlPoints(link);
            // Neighbor control points: prev is index `viaIndex`, next is index `viaIndex + 2`
            const prevPt = controlPts[viaIndex];
            const nextPt = controlPts[viaIndex + 2];
            const neighbors = [prevPt, nextPt].filter((p): p is Point => p !== undefined);

            if (e.shiftKey || orthoSnap.value) {
                const res = snapOrthogonal(rawPt, neighbors, 14, e.shiftKey ? (prevPt ?? null) : null);
                viaPt.x = Math.round(res.point.x);
                viaPt.y = Math.round(res.point.y);
                alignGuides.value = res.guides;
            } else {
                viaPt.x = rawPt.x;
                viaPt.y = rawPt.y;
                alignGuides.value = [];
            }
        }
    } else if (dragState.value.kind === 'legend') {
        doc.value.legend.x = snap(pt.x - dragState.value.offset.x);
        doc.value.legend.y = snap(pt.y - dragState.value.offset.y);
    } else if (dragState.value.kind === 'marquee') {
        dragState.value.current = pt;
    }
}

function onMouseUp(): void {
    alignGuides.value = [];
    if (isPanning.value) {
        isPanning.value = false;
        return;
    }
    if (!dragState.value || !doc.value) return;

    if (dragState.value.kind === 'marquee') {
        const minX = Math.min(dragState.value.start.x, dragState.value.current.x);
        const maxX = Math.max(dragState.value.start.x, dragState.value.current.x);
        const minY = Math.min(dragState.value.start.y, dragState.value.current.y);
        const maxY = Math.max(dragState.value.start.y, dragState.value.current.y);
        if (maxX - minX > 4 || maxY - minY > 4) {
            selectedNodeIds.value = doc.value.nodes
                .filter((n) => n.x >= minX && n.x <= maxX && n.y >= minY && n.y <= maxY)
                .map((n) => n.id);
        }
    } else {
        commitHistory();
    }

    dragState.value = null;
}

function onWheel(e: WheelEvent): void {
    if (!e.ctrlKey && !e.metaKey) return;
    e.preventDefault();
    const delta = e.deltaY < 0 ? 0.1 : -0.1;
    zoom.value = Math.max(0.25, Math.min(3, Math.round((zoom.value + delta) * 100) / 100));
}

function deleteSelected(): void {
    if (!doc.value) return;
    // If a specific VIA waypoint is selected, delete ONLY that waypoint (not the entire link)
    if (selectedLink.value && selectedViaIndex.value !== null) {
        selectedLink.value.via.splice(selectedViaIndex.value, 1);
        selectedViaIndex.value = null;
        commitHistory();
        return;
    }
    if (selectedLinkId.value) {
        doc.value.links = doc.value.links.filter((l) => l.id !== selectedLinkId.value);
        selectedLinkId.value = null;
        selectedViaIndex.value = null;
        commitHistory();
        return;
    }
    if (selectedNodeIds.value.length > 0) {
        const removed = new Set(selectedNodeIds.value);
        doc.value.nodes = doc.value.nodes.filter((n) => !removed.has(n.id));
        doc.value.links = doc.value.links.filter((l) => !removed.has(l.a.nodeId) && !removed.has(l.b.nodeId));
        selectedNodeIds.value = [];
        commitHistory();
    }
}

function openAssetsModal(kind: 'background' | 'icon'): void {
    assetsModalKind.value = kind;
    assetsModalOpen.value = true;
}

function onAssetSelected(asset: AssetItem): void {
    if (!doc.value) return;
    if (assetsModalKind.value === 'background') {
        doc.value.canvas.backgroundAssetId = asset.id;
        doc.value.canvas.backgroundAssetUrl = asset.url;
        commitHistory();
    } else if (selectedNode.value) {
        selectedNode.value.iconType = 'asset';
        selectedNode.value.iconAssetId = asset.id;
        commitHistory();
    }
}

function onApplyScaleLegend(payload: { scales: ScaleDef[]; legend: LegendDef }): void {
    if (!doc.value) return;
    doc.value.scales = payload.scales;
    doc.value.legend = payload.legend;
    commitHistory();
}

function onKeydown(e: KeyboardEvent): void {
    const tag = (e.target as HTMLElement | null)?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
        e.preventDefault();
        if (e.shiftKey) redo();
        else undo();
    } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') {
        e.preventDefault();
        redo();
    } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        void saveMap();
    } else if (e.key === 'Delete' || e.key === 'Backspace') {
        e.preventDefault();
        if (tool.value === 'add-link' && linkStartNodeId.value && draftLinkWaypoints.value.length > 0) {
            draftLinkWaypoints.value = draftLinkWaypoints.value.slice(0, -1);
            return;
        }
        deleteSelected();
    } else if (e.key === 'Escape') {
        tool.value = 'select';
        linkStartNodeId.value = null;
        draftLinkWaypoints.value = [];
        draftCursorPos.value = null;
        alignGuides.value = [];
    }
}

function onBeforeUnload(e: BeforeUnloadEvent): void {
    if (dirty.value) {
        e.preventDefault();
        e.returnValue = '';
    }
}

onMounted(() => {
    void loadMap();
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('beforeunload', onBeforeUnload);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('beforeunload', onBeforeUnload);
});
</script>

<template>
    <div class="wm-root wm-editor-shell">
        <header class="wm-toolbar">
            <a class="wm-btn" href="/weathermap">&larr; Maps</a>
            <input
                v-model="mapName"
                aria-label="Map name"
                class="wm-input"
                style="width: 210px; font-weight: 700"
                @change="dirty = true"
            />
            <span v-if="dirty" class="wm-badge wm-badge-dirty">Unsaved</span>
            <span v-if="statusToast" class="wm-badge" style="color: #86efac">{{ statusToast }}</span>

            <div style="display: flex; gap: 4px; margin-left: 8px">
                <button
                    type="button"
                    class="wm-btn"
                    :class="{ 'wm-btn-active': tool === 'select' }"
                    @click="tool = 'select'; linkStartNodeId = null; draftLinkWaypoints = []; draftCursorPos = null"
                >
                    Select
                </button>
                <button
                    type="button"
                    class="wm-btn"
                    :class="{ 'wm-btn-active': tool === 'add-node' }"
                    @click="tool = 'add-node'; linkStartNodeId = null; draftLinkWaypoints = []; draftCursorPos = null"
                >
                    + Node
                </button>
                <button
                    type="button"
                    class="wm-btn"
                    :class="{ 'wm-btn-active': tool === 'add-link' }"
                    @click="tool = 'add-link'; linkStartNodeId = null; draftLinkWaypoints = []; draftCursorPos = null"
                >
                    + Link
                </button>
                <select
                    v-if="tool === 'add-link'"
                    v-model="newLinkViaStyle"
                    aria-label="New link corner style"
                    class="wm-select"
                    style="width: auto; min-height: 36px"
                >
                    <option value="angled">Angled</option>
                    <option value="curved">Curved</option>
                </select>
            </div>

            <div style="display: flex; gap: 4px; margin-left: 6px">
                <button type="button" class="wm-btn" :disabled="!canUndo" title="Undo (Ctrl+Z)" @click="undo">Undo</button>
                <button type="button" class="wm-btn" :disabled="!canRedo" title="Redo (Ctrl+Shift+Z)" @click="redo">Redo</button>
            </div>

            <label v-if="doc" style="display: flex; align-items: center; gap: 6px; font-size: 12px; margin-left: 6px">
                <input v-model="doc.settings.snapToGrid" type="checkbox" /> Grid
            </label>
            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; margin-left: 4px" title="Snap dragged VIA points to align with neighbors (hold Shift to lock 90°)">
                <input v-model="orthoSnap" type="checkbox" /> Align 90°
            </label>

            <div class="wm-spacer" />

            <button type="button" class="wm-btn" aria-label="Zoom out" @click="zoom = Math.max(0.25, +(zoom - 0.15).toFixed(2))">-</button>
            <button type="button" class="wm-btn wm-tabular" @click="zoom = 1; pan = { x: 0, y: 0 }">{{ Math.round(zoom * 100) }}%</button>
            <button type="button" class="wm-btn" aria-label="Zoom in" @click="zoom = Math.min(3, +(zoom + 0.15).toFixed(2))">+</button>

            <button type="button" class="wm-btn" @click="scaleModalOpen = true">Scale &amp; Legend</button>
            <button type="button" class="wm-btn" @click="importModalOpen = true">Import .conf</button>
            <button type="button" class="wm-btn" @click="accessModalOpen = true">Access</button>
            <a class="wm-btn" :href="`/weathermap/maps/${mapId}`">View</a>
            <button type="button" class="wm-btn wm-btn-primary" :disabled="saving" :aria-busy="saving" @click="saveMap">
                {{ saving ? 'Saving...' : 'Save' }}
            </button>
        </header>

        <div v-if="errorBanner" style="padding: 8px 14px; background: rgba(239, 68, 68, 0.16); color: #fca5a5; font-size: 13px" role="alert">
            {{ errorBanner }}
        </div>

        <div class="wm-editor-body">
            <div v-if="loading || !doc" style="flex: 1; padding: 24px">
                <div class="wm-skeleton" style="height: 540px; width: 100%" />
            </div>

            <template v-else>
                <main class="wm-canvas-wrap" @wheel="onWheel">
                    <svg
                        ref="svgRef"
                        class="wm-editor-svg"
                        :style="{ cursor: tool === 'add-node' ? 'crosshair' : isPanning ? 'grabbing' : 'default' }"
                        @mousedown="onCanvasMouseDown"
                        @mousemove="onMouseMove"
                        @mouseup="onMouseUp"
                        @mouseleave="onMouseUp"
                    >
                        <defs>
                            <pattern
                                id="wm-grid"
                                :width="doc.settings.gridSize"
                                :height="doc.settings.gridSize"
                                patternUnits="userSpaceOnUse"
                            >
                                <path
                                    :d="`M ${doc.settings.gridSize} 0 L 0 0 0 ${doc.settings.gridSize}`"
                                    fill="none"
                                    stroke="rgba(148,163,184,0.14)"
                                    stroke-width="1"
                                />
                            </pattern>
                        </defs>

                        <g :transform="`translate(${pan.x}, ${pan.y}) scale(${zoom})`">
                            <!-- Canvas surface -->
                            <rect
                                x="0"
                                y="0"
                                :width="doc.canvas.width"
                                :height="doc.canvas.height"
                                :fill="doc.canvas.bgColor"
                                stroke="#334155"
                                stroke-width="1.5"
                            />
                            <image
                                v-if="doc.canvas.backgroundAssetId"
                                :href="`/weathermap/api/assets/${doc.canvas.backgroundAssetId}`"
                                x="0"
                                y="0"
                                :width="doc.canvas.width"
                                :height="doc.canvas.height"
                                preserveAspectRatio="none"
                            />
                            <rect
                                x="0"
                                y="0"
                                :width="doc.canvas.width"
                                :height="doc.canvas.height"
                                fill="url(#wm-grid)"
                                pointer-events="none"
                            />

                            <!-- Links -->
                            <g
                                v-for="item in previewLinks"
                                :key="item.link.id"
                                @click="onLinkClick(item.link, $event)"
                                @dblclick="onLinkDoubleClick(item.link, $event)"
                            >
                                <polygon
                                    v-if="item.outPoints"
                                    :points="item.outPoints"
                                    :fill="item.outColor"
                                    :stroke="selectedLinkId === item.link.id ? '#38bdf8' : item.link.outlineColor"
                                    :stroke-width="selectedLinkId === item.link.id ? 2.5 : 1"
                                    style="cursor: pointer"
                                />
                                <polygon
                                    v-if="item.inPoints"
                                    :points="item.inPoints"
                                    :fill="item.inColor"
                                    :stroke="selectedLinkId === item.link.id ? '#38bdf8' : item.link.outlineColor"
                                    :stroke-width="selectedLinkId === item.link.id ? 2.5 : 1"
                                    style="cursor: pointer"
                                />

                                <g v-if="item.link.bwLabel !== 'none'" pointer-events="none">
                                    <rect
                                        :x="item.outLabelPos.x - 16"
                                        :y="item.outLabelPos.y - 8"
                                        width="32"
                                        height="16"
                                        rx="3"
                                        :fill="item.outColor"
                                        stroke="#0f172a"
                                    />
                                    <text :x="item.outLabelPos.x" :y="item.outLabelPos.y" text-anchor="middle" dominant-baseline="central" font-size="8" font-weight="700" fill="#0f172a">
                                        35%
                                    </text>
                                    <rect
                                        :x="item.inLabelPos.x - 16"
                                        :y="item.inLabelPos.y - 8"
                                        width="32"
                                        height="16"
                                        rx="3"
                                        :fill="item.inColor"
                                        stroke="#0f172a"
                                    />
                                    <text :x="item.inLabelPos.x" :y="item.inLabelPos.y" text-anchor="middle" dominant-baseline="central" font-size="8" font-weight="700" fill="#0f172a">
                                        50%
                                    </text>
                                </g>

                                <!-- VIA handles rendered in dedicated selected-link overlay below -->
                            </g>

                            <!-- Selected-Link Control Overlay: dashed skeleton, midpoint '+' insert handles, numbered VIA dots -->
                            <g v-if="selectedLinkOverlay">
                                <polyline
                                    :points="selectedLinkOverlay.polylinePoints"
                                    fill="none"
                                    stroke="rgba(56,189,248,0.55)"
                                    stroke-width="1.2"
                                    stroke-dasharray="5 4"
                                    pointer-events="none"
                                />

                                <!-- Midpoint '+' ghost handles for inserting a VIA right inside that segment -->
                                <g
                                    v-for="mid in selectedLinkOverlay.midpoints"
                                    :key="`mid-${mid.segmentIndex}`"
                                    style="cursor: copy"
                                    @mousedown="onMidpointMouseDown(selectedLinkOverlay.link, mid.segmentIndex, mid.point, $event)"
                                >
                                    <circle
                                        :cx="mid.point.x"
                                        :cy="mid.point.y"
                                        r="7"
                                        fill="rgba(15,23,42,0.85)"
                                        stroke="#38bdf8"
                                        stroke-width="1.2"
                                        stroke-dasharray="2 2"
                                    />
                                    <text
                                        :x="mid.point.x"
                                        :y="mid.point.y"
                                        text-anchor="middle"
                                        dominant-baseline="central"
                                        font-size="10"
                                        font-weight="700"
                                        fill="#7dd3fc"
                                        pointer-events="none"
                                    >
                                        +
                                    </text>
                                </g>

                                <!-- Draggable numbered VIA handles -->
                                <g
                                    v-for="(vp, vIdx) in selectedLinkOverlay.link.via"
                                    :key="`via-${vIdx}`"
                                    style="cursor: move"
                                    @mousedown="onViaMouseDown(selectedLinkOverlay.link, vIdx, $event)"
                                    @contextmenu="removeViaPoint(selectedLinkOverlay.link, vIdx, $event)"
                                >
                                    <circle
                                        :cx="vp.x"
                                        :cy="vp.y"
                                        r="8"
                                        :fill="selectedViaIndex === vIdx ? '#f8fafc' : '#38bdf8'"
                                        :stroke="selectedViaIndex === vIdx ? '#0284c7' : '#0f172a'"
                                        :stroke-width="selectedViaIndex === vIdx ? 2.5 : 1.5"
                                    />
                                    <text
                                        :x="vp.x"
                                        :y="vp.y"
                                        text-anchor="middle"
                                        dominant-baseline="central"
                                        font-size="9"
                                        font-weight="700"
                                        fill="#0f172a"
                                        pointer-events="none"
                                    >
                                        {{ vIdx + 1 }}
                                    </text>
                                </g>
                            </g>

                            <!-- Live click-to-route multi-VIA preview while drawing a new link -->
                            <g v-if="draftLinkPreview" pointer-events="none">
                                <polyline
                                    :points="draftLinkPreview.polyline"
                                    fill="none"
                                    stroke="#38bdf8"
                                    stroke-width="2.5"
                                    stroke-dasharray="6 4"
                                />
                                <circle
                                    v-for="(dp, idx) in draftLinkWaypoints"
                                    :key="`draft-${idx}`"
                                    :cx="dp.x"
                                    :cy="dp.y"
                                    r="5"
                                    fill="#38bdf8"
                                    stroke="#0f172a"
                                    stroke-width="1.5"
                                />
                            </g>

                            <!-- Orthogonal alignment guides while dragging VIA or drawing waypoints -->
                            <g v-if="alignGuides.length > 0" pointer-events="none">
                                <line
                                    v-for="(g, gIdx) in alignGuides"
                                    :key="`guide-${gIdx}`"
                                    :x1="g.axis === 'x' ? g.value : 0"
                                    :y1="g.axis === 'y' ? g.value : 0"
                                    :x2="g.axis === 'x' ? g.value : doc.canvas.width"
                                    :y2="g.axis === 'y' ? g.value : doc.canvas.height"
                                    stroke="#22d3ee"
                                    stroke-width="1"
                                    stroke-dasharray="4 4"
                                    opacity="0.75"
                                />
                            </g>

                            <!-- Nodes -->
                            <g
                                v-for="node in doc.nodes"
                                :key="node.id"
                                style="cursor: grab"
                                @mousedown="onNodeMouseDown(node, $event)"
                            >
                                <image
                                    v-if="node.iconType === 'asset' && node.iconAssetId"
                                    :href="`/weathermap/api/assets/${node.iconAssetId}`"
                                    :x="node.x - node.iconWidth / 2"
                                    :y="node.y - node.iconHeight / 2"
                                    :width="node.iconWidth"
                                    :height="node.iconHeight"
                                />
                                <ellipse
                                    v-else-if="node.iconType === 'shape:round'"
                                    :cx="node.x"
                                    :cy="node.y"
                                    :rx="node.iconWidth / 2"
                                    :ry="node.iconHeight / 2"
                                    :fill="node.bgColor"
                                    :stroke="selectedNodeIds.includes(node.id) || linkStartNodeId === node.id ? '#38bdf8' : node.outlineColor"
                                    :stroke-width="selectedNodeIds.includes(node.id) || linkStartNodeId === node.id ? 2.5 : 1.5"
                                />
                                <rect
                                    v-else
                                    :x="node.x - node.iconWidth / 2"
                                    :y="node.y - node.iconHeight / 2"
                                    :width="node.iconWidth"
                                    :height="node.iconHeight"
                                    rx="6"
                                    :fill="node.bgColor"
                                    :stroke="selectedNodeIds.includes(node.id) || linkStartNodeId === node.id ? '#38bdf8' : node.outlineColor"
                                    :stroke-width="selectedNodeIds.includes(node.id) || linkStartNodeId === node.id ? 2.5 : 1.5"
                                />
                                <text
                                    :x="placeNodeLabel(node).x"
                                    :y="placeNodeLabel(node).y"
                                    text-anchor="middle"
                                    dominant-baseline="central"
                                    :font-size="node.labelFontSize"
                                    font-weight="700"
                                    :fill="node.labelColor"
                                    pointer-events="none"
                                >
                                    {{ node.label }}
                                </text>
                            </g>

                            <!-- Draggable Legend Preview -->
                            <g
                                v-if="doc.legend.visible"
                                :transform="`translate(${doc.legend.x}, ${doc.legend.y})`"
                                style="cursor: move"
                                @mousedown="onLegendMouseDown"
                            >
                                <rect
                                    x="0"
                                    y="0"
                                    :width="doc.legend.style === 'horizontal' ? 260 : 154"
                                    :height="doc.legend.style === 'horizontal' ? 64 : 32 + activeLegendScale.stops.length * 17"
                                    rx="6"
                                    :fill="doc.legend.bgColor"
                                    :stroke="doc.legend.outlineColor"
                                />
                                <text x="10" y="18" :font-size="doc.legend.fontSize" font-weight="700" :fill="doc.legend.textColor">
                                    {{ doc.legend.title }}
                                </text>
                                <template v-if="doc.legend.style !== 'horizontal'">
                                    <g v-for="(stop, idx) in activeLegendScale.stops" :key="idx">
                                        <rect x="10" :y="26 + idx * 17" width="22" height="12" :fill="rgbToHex(stop.rgb1)" />
                                        <text x="40" :y="36 + idx * 17" font-size="9" :fill="doc.legend.textColor">
                                            {{ stop.min === stop.max ? `${stop.min}%` : `${stop.min}-${stop.max}%` }}
                                        </text>
                                    </g>
                                </template>
                            </g>

                            <!-- Title preview -->
                            <text
                                v-if="doc.canvas.title"
                                :x="doc.canvas.titleX"
                                :y="doc.canvas.titleY"
                                :font-size="doc.canvas.titleFontSize"
                                font-weight="700"
                                :fill="doc.canvas.titleColor"
                                pointer-events="none"
                            >
                                {{ doc.canvas.title }}
                            </text>

                            <!-- Marquee multi-select box -->
                            <rect
                                v-if="dragState && dragState.kind === 'marquee'"
                                :x="Math.min(dragState.start.x, dragState.current.x)"
                                :y="Math.min(dragState.start.y, dragState.current.y)"
                                :width="Math.abs(dragState.current.x - dragState.start.x)"
                                :height="Math.abs(dragState.current.y - dragState.start.y)"
                                fill="rgba(56,189,248,0.12)"
                                stroke="#38bdf8"
                                stroke-dasharray="4 3"
                            />
                        </g>
                    </svg>
                </main>

                <PropertyPanel
                    :doc="doc"
                    :selected-node="selectedNode"
                    :selected-link="selectedLink"
                    :selected-via-index="selectedViaIndex"
                    :multi-count="selectedNodeIds.length"
                    :png-enabled="pngEnabled"
                    @commit="commitHistory"
                    @delete-selected="deleteSelected"
                    @open-assets="openAssetsModal"
                    @select-via="selectedViaIndex = $event"
                    @apply-route="applyRoutePreset"
                    @add-via-midpoint="addViaMidpoint"
                    @update:png-enabled="pngEnabled = $event; dirty = true"
                />
            </template>
        </div>

        <!-- Modals -->
        <ScaleLegendModal
            v-if="doc"
            :open="scaleModalOpen"
            :scales="doc.scales"
            :legend="doc.legend"
            @close="scaleModalOpen = false"
            @apply="onApplyScaleLegend"
        />

        <AssetsModal
            :open="assetsModalOpen"
            :kind="assetsModalKind"
            @close="assetsModalOpen = false"
            @select="onAssetSelected"
        />

        <ImportModal :open="importModalOpen" @close="importModalOpen = false" />

        <AccessModal
            :open="accessModalOpen"
            :map-id="mapId"
            :rules="accessRules"
            @close="accessModalOpen = false"
            @updated="accessRules = $event; showToast('Access updated')"
        />

        <!-- Revision conflict dialog -->
        <dialog ref="conflictDialogRef" class="wm-dialog" aria-labelledby="wm-conflict-title">
            <div class="wm-dialog-header">
                <h3 id="wm-conflict-title" style="margin: 0; font-size: 16px; color: #fde68a">Save Conflict</h3>
            </div>
            <div class="wm-dialog-body">
                <p style="margin: 0; font-size: 14px">
                    Another user saved a newer revision (rev {{ conflictServerRevision ?? '?' }}) of this map since you opened it.
                    Reload the latest version to avoid overwriting their changes.
                </p>
            </div>
            <div class="wm-dialog-footer">
                <button type="button" class="wm-btn" @click="conflictDialogRef?.close()">Keep Editing</button>
                <button type="button" class="wm-btn wm-btn-primary" @click="conflictDialogRef?.close(); loadMap()">
                    Reload Latest Map
                </button>
            </div>
        </dialog>
    </div>
</template>

<style scoped>
.wm-editor-shell {
    height: calc(100vh - 56px);
    min-height: 620px;
    display: flex;
    flex-direction: column;
}
.wm-editor-body {
    flex: 1 1 auto;
    display: flex;
    min-height: 0;
}
.wm-canvas-wrap {
    flex: 1 1 auto;
    background: #020617;
    overflow: hidden;
    position: relative;
}
.wm-editor-svg {
    width: 100%;
    height: 100%;
    display: block;
    user-select: none;
}
</style>
