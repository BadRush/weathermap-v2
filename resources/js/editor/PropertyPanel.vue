<script setup lang="ts">
import { ref } from 'vue';
import { apiGet } from '../shared/api';
import { formatBits } from '../shared/geometry';
import type {
    DeviceLookupItem,
    LinkDef,
    MapDocument,
    NodeDef,
    PortLookupItem,
    TargetTerm,
} from '../shared/types';

const props = defineProps<{
    doc: MapDocument;
    selectedNode: NodeDef | null;
    selectedLink: LinkDef | null;
    selectedViaIndex: number | null;
    multiCount: number;
    pngEnabled: boolean;
}>();

const emit = defineEmits<{
    (e: 'commit'): void;
    (e: 'delete-selected'): void;
    (e: 'open-assets', kind: 'background' | 'icon'): void;
    (e: 'update:pngEnabled', value: boolean): void;
    (e: 'select-via', index: number | null): void;
    (e: 'apply-route', mode: 'L' | 'Z'): void;
    (e: 'add-via-midpoint'): void;
}>();

const deviceQuery = ref('');
const deviceResults = ref<DeviceLookupItem[]>([]);
const portQuery = ref('');
const portResults = ref<PortLookupItem[]>([]);
const activeTermIdx = ref<number | null>(null);

let deviceDebounce: number | null = null;
let portDebounce: number | null = null;

function searchDevices(): void {
    if (deviceDebounce !== null) window.clearTimeout(deviceDebounce);
    deviceDebounce = window.setTimeout(async () => {
        try {
            const res = await apiGet<{ devices: DeviceLookupItem[] }>(
                `/weathermap/api/lookup/devices?q=${encodeURIComponent(deviceQuery.value)}`,
            );
            deviceResults.value = res.devices;
        } catch {
            deviceResults.value = [];
        }
    }, 220);
}

function pickDevice(d: DeviceLookupItem): void {
    if (!props.selectedNode) return;
    props.selectedNode.deviceId = d.device_id;
    if (!props.selectedNode.label || props.selectedNode.label.startsWith('Node ')) {
        props.selectedNode.label = d.display || d.hostname;
    }
    if (d.os) {
        props.selectedNode.deviceOsIcon = d.os;
    }
    deviceResults.value = [];
    deviceQuery.value = '';
    emit('commit');
}

function searchPorts(termIdx: number): void {
    activeTermIdx.value = termIdx;
    if (portDebounce !== null) window.clearTimeout(portDebounce);
    portDebounce = window.setTimeout(async () => {
        try {
            const res = await apiGet<{ ports: PortLookupItem[] }>(
                `/weathermap/api/lookup/ports?q=${encodeURIComponent(portQuery.value)}`,
            );
            portResults.value = res.ports;
        } catch {
            portResults.value = [];
        }
    }, 220);
}

function pickPort(p: PortLookupItem, term: TargetTerm): void {
    term.source = 'port';
    term.portId = p.port_id;
    portResults.value = [];
    portQuery.value = '';
    activeTermIdx.value = null;
    emit('commit');
}

function addTargetTerm(source: 'port' | 'rrd' | 'static'): void {
    if (!props.selectedLink) return;
    props.selectedLink.target.terms.push({
        source,
        portId: null,
        rrdPath: null,
        dsIn: 'INOCTETS',
        dsOut: 'OUTOCTETS',
        cf: 'AVERAGE',
        staticIn: source === 'static' ? 100_000_000 : 0,
        staticOut: source === 'static' ? 100_000_000 : 0,
        factor: 1,
        negate: false,
        reverse: false,
    });
    emit('commit');
}

function removeTargetTerm(idx: number): void {
    if (!props.selectedLink) return;
    props.selectedLink.target.terms.splice(idx, 1);
    emit('commit');
}

function clearViaPoints(): void {
    if (!props.selectedLink) return;
    props.selectedLink.via = [];
    emit('select-via', null);
    emit('commit');
}

function removeSingleVia(idx: number): void {
    if (!props.selectedLink) return;
    props.selectedLink.via.splice(idx, 1);
    if (props.selectedViaIndex === idx) {
        emit('select-via', null);
    } else if (props.selectedViaIndex !== null && props.selectedViaIndex > idx) {
        emit('select-via', props.selectedViaIndex - 1);
    }
    emit('commit');
}

function moveVia(idx: number, delta: -1 | 1): void {
    if (!props.selectedLink) return;
    const target = idx + delta;
    if (target < 0 || target >= props.selectedLink.via.length) return;
    const item = props.selectedLink.via[idx]!;
    props.selectedLink.via.splice(idx, 1);
    props.selectedLink.via.splice(target, 0, item);
    emit('select-via', target);
    emit('commit');
}
</script>

<template>
    <aside class="wm-inspector" aria-label="Properties inspector">
        <!-- Multi-select summary -->
        <template v-if="multiCount > 1">
            <h3 class="wm-panel-title">{{ multiCount }} Nodes Selected</h3>
            <p style="font-size: 13px; color: var(--wm-muted)">Drag any selected node on the canvas to move the group together.</p>
            <button type="button" class="wm-btn wm-btn-danger" style="width: 100%" @click="emit('delete-selected')">
                Delete Selected Nodes
            </button>
        </template>

        <!-- Selected Node Inspector -->
        <template v-else-if="selectedNode">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px">
                <h3 class="wm-panel-title" style="margin: 0">Node: {{ selectedNode.id }}</h3>
                <button type="button" class="wm-btn wm-btn-danger" @click="emit('delete-selected')">Delete</button>
            </div>

            <div class="wm-field">
                <label class="wm-label" for="wm-n-label">Label</label>
                <input id="wm-n-label" v-model="selectedNode.label" class="wm-input" @change="emit('commit')" />
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-n-x">X</label>
                    <input id="wm-n-x" v-model.number="selectedNode.x" type="number" class="wm-input wm-tabular" @change="emit('commit')" />
                </div>
                <div>
                    <label class="wm-label" for="wm-n-y">Y</label>
                    <input id="wm-n-y" v-model.number="selectedNode.y" type="number" class="wm-input wm-tabular" @change="emit('commit')" />
                </div>
            </div>

            <div class="wm-field">
                <label class="wm-label" for="wm-n-dev-search">Link to LibreNMS Device (ID: {{ selectedNode.deviceId ?? 'none' }})</label>
                <input
                    id="wm-n-dev-search"
                    v-model="deviceQuery"
                    class="wm-input"
                    placeholder="Search device hostname..."
                    @input="searchDevices"
                />
                <div v-if="deviceResults.length > 0" class="wm-dropdown">
                    <button
                        v-for="d in deviceResults"
                        :key="d.device_id"
                        type="button"
                        class="wm-dropdown-item"
                        @click="pickDevice(d)"
                    >
                        <strong>{{ d.display || d.hostname }}</strong>
                        <span style="color: var(--wm-muted); font-size: 11px">{{ d.os || 'device' }} #{{ d.device_id }}</span>
                    </button>
                </div>
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-n-icon">Icon Style</label>
                    <select id="wm-n-icon" v-model="selectedNode.iconType" class="wm-select" @change="emit('commit')">
                        <option value="shape:box">Box</option>
                        <option value="shape:round">Round</option>
                        <option value="device-os">Device OS</option>
                        <option value="asset">Uploaded Icon</option>
                    </select>
                </div>
                <div>
                    <label class="wm-label" for="wm-n-loff">Label Offset</label>
                    <input
                        id="wm-n-loff"
                        v-model="selectedNode.labelOffset"
                        class="wm-input"
                        placeholder="C, N, S, E, W, dx:dy"
                        @change="emit('commit')"
                    />
                </div>
            </div>

            <div v-if="selectedNode.iconType === 'asset'" class="wm-field">
                <button type="button" class="wm-btn" style="width: 100%" @click="emit('open-assets', 'icon')">
                    Choose / Upload Icon Image ({{ selectedNode.iconAssetId ? `#${selectedNode.iconAssetId}` : 'None' }})
                </button>
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-n-w">Width</label>
                    <input id="wm-n-w" v-model.number="selectedNode.iconWidth" type="number" min="16" max="512" class="wm-input wm-tabular" @change="emit('commit')" />
                </div>
                <div>
                    <label class="wm-label" for="wm-n-h">Height</label>
                    <input id="wm-n-h" v-model.number="selectedNode.iconHeight" type="number" min="16" max="512" class="wm-input wm-tabular" @change="emit('commit')" />
                </div>
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-n-bg">Fill Color</label>
                    <input id="wm-n-bg" v-model="selectedNode.bgColor" type="color" class="wm-input" style="padding: 2px" @change="emit('commit')" />
                </div>
                <div>
                    <label class="wm-label" for="wm-n-out">Outline</label>
                    <input id="wm-n-out" v-model="selectedNode.outlineColor" type="color" class="wm-input" style="padding: 2px" @change="emit('commit')" />
                </div>
            </div>

            <div class="wm-field">
                <label class="wm-label" for="wm-n-url">Custom Click URL (optional)</label>
                <input id="wm-n-url" v-model="selectedNode.infoUrl" class="wm-input" placeholder="/device/device=1/" @change="emit('commit')" />
            </div>
        </template>

        <!-- Selected Link Inspector -->
        <template v-else-if="selectedLink">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px">
                <h3 class="wm-panel-title" style="margin: 0">Link: {{ selectedLink.a.nodeId }} &harr; {{ selectedLink.b.nodeId }}</h3>
                <button type="button" class="wm-btn wm-btn-danger" @click="emit('delete-selected')">Delete</button>
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-l-offa">Offset A</label>
                    <input id="wm-l-offa" v-model="selectedLink.a.offset" class="wm-input" placeholder="C, N, E, 10:0" @change="emit('commit')" />
                </div>
                <div>
                    <label class="wm-label" for="wm-l-offb">Offset B</label>
                    <input id="wm-l-offb" v-model="selectedLink.b.offset" class="wm-input" placeholder="C, S, W, -10:0" @change="emit('commit')" />
                </div>
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-l-via">VIA Style</label>
                    <select id="wm-l-via" v-model="selectedLink.viaStyle" class="wm-select" @change="emit('commit')">
                        <option value="curved">Curved (Catmull-Rom)</option>
                        <option value="angled">Angled</option>
                    </select>
                </div>
                <div>
                    <label class="wm-label" for="wm-l-arrow">Arrow Style</label>
                    <select id="wm-l-arrow" v-model="selectedLink.arrowStyle" class="wm-select" @change="emit('commit')">
                        <option value="classic">Classic</option>
                        <option value="compact">Compact</option>
                    </select>
                </div>
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-l-width">Width (px)</label>
                    <input id="wm-l-width" v-model.number="selectedLink.width" type="number" min="2" max="40" class="wm-input wm-tabular" @change="emit('commit')" />
                </div>
                <div>
                    <label class="wm-label" for="wm-l-bwlabel">Bandwidth Label</label>
                    <select id="wm-l-bwlabel" v-model="selectedLink.bwLabel" class="wm-select" @change="emit('commit')">
                        <option value="percent">Percent (%)</option>
                        <option value="bits">Bits (K/M/G)</option>
                        <option value="none">None</option>
                    </select>
                </div>
            </div>

            <div class="wm-field">
                <label class="wm-label" for="wm-l-split">Split Position ({{ Math.round(selectedLink.splitPos * 100) }}%)</label>
                <input
                    id="wm-l-split"
                    v-model.number="selectedLink.splitPos"
                    type="range"
                    min="0.15"
                    max="0.85"
                    step="0.05"
                    style="width: 100%"
                    @change="emit('commit')"
                />
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-l-inpos">In Label Pos ({{ Math.round(selectedLink.inLabelPos * 100) }}%)</label>
                    <input id="wm-l-inpos" v-model.number="selectedLink.inLabelPos" type="range" min="0.1" max="0.9" step="0.05" @change="emit('commit')" />
                </div>
                <div>
                    <label class="wm-label" for="wm-l-outpos">Out Label Pos ({{ Math.round(selectedLink.outLabelPos * 100) }}%)</label>
                    <input id="wm-l-outpos" v-model.number="selectedLink.outLabelPos" type="range" min="0.1" max="0.9" step="0.05" @change="emit('commit')" />
                </div>
            </div>

            <!-- VIA Waypoints Manager -->
            <div class="wm-field" style="padding: 8px; border: 1px solid var(--wm-border); border-radius: 6px; background: rgba(15,23,42,0.5)">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px">
                    <span class="wm-label" style="margin: 0; color: var(--wm-primary)">VIA Waypoints ({{ selectedLink.via.length }})</span>
                    <button
                        v-if="selectedLink.via.length > 0"
                        type="button"
                        class="wm-btn wm-btn-danger"
                        style="min-height: 24px; padding: 2px 8px; font-size: 11px"
                        @click="clearViaPoints"
                    >
                        Clear
                    </button>
                </div>

                <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 4px; margin-bottom: 8px">
                    <button type="button" class="wm-btn" style="min-height: 28px; padding: 2px 6px; font-size: 11px" @click="emit('add-via-midpoint')">
                        + Bend
                    </button>
                    <button
                        type="button"
                        class="wm-btn"
                        style="min-height: 28px; padding: 2px 6px; font-size: 11px"
                        title="Generate 1-corner L-shape angled route (click again to flip)"
                        @click="emit('apply-route', 'L')"
                    >
                        L-Bend
                    </button>
                    <button
                        type="button"
                        class="wm-btn"
                        style="min-height: 28px; padding: 2px 6px; font-size: 11px"
                        title="Generate 2-corner Z-shape angled route (click again to flip)"
                        @click="emit('apply-route', 'Z')"
                    >
                        Z-Bend
                    </button>
                </div>

                <div v-if="selectedLink.via.length === 0" style="font-size: 11px; color: var(--wm-muted)">
                    Click the <strong>+</strong> midpoint handles on the canvas or double-click any segment to add bends.
                </div>

                <div v-else style="display: flex; flex-direction: column; gap: 5px">
                    <div
                        v-for="(vp, vIdx) in selectedLink.via"
                        :key="vIdx"
                        style="display: grid; grid-template-columns: 24px 1fr 1fr 24px 24px 24px; gap: 4px; align-items: center; padding: 3px; border-radius: 4px"
                        :style="{ background: selectedViaIndex === vIdx ? 'rgba(56,189,248,0.18)' : 'transparent' }"
                        @click="emit('select-via', vIdx)"
                    >
                        <span class="wm-tabular" style="font-size: 11px; font-weight: 700; color: var(--wm-muted)">#{{ vIdx + 1 }}</span>
                        <input
                            v-model.number="vp.x"
                            type="number"
                            aria-label="VIA X"
                            class="wm-input wm-tabular"
                            style="min-height: 26px; padding: 2px 6px; font-size: 12px"
                            @change="emit('commit')"
                        />
                        <input
                            v-model.number="vp.y"
                            type="number"
                            aria-label="VIA Y"
                            class="wm-input wm-tabular"
                            style="min-height: 26px; padding: 2px 6px; font-size: 12px"
                            @change="emit('commit')"
                        />
                        <button
                            type="button"
                            class="wm-btn"
                            style="min-height: 24px; padding: 0; font-size: 11px"
                            :disabled="vIdx === 0"
                            aria-label="Move waypoint up"
                            @click.stop="moveVia(vIdx, -1)"
                        >
                            &uarr;
                        </button>
                        <button
                            type="button"
                            class="wm-btn"
                            style="min-height: 24px; padding: 0; font-size: 11px"
                            :disabled="vIdx === selectedLink.via.length - 1"
                            aria-label="Move waypoint down"
                            @click.stop="moveVia(vIdx, 1)"
                        >
                            &darr;
                        </button>
                        <button
                            type="button"
                            class="wm-btn wm-btn-danger"
                            style="min-height: 24px; padding: 0; font-size: 11px"
                            aria-label="Remove waypoint"
                            @click.stop="removeSingleVia(vIdx)"
                        >
                            &times;
                        </button>
                    </div>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--wm-border); margin: 12px 0" />

            <!-- Capacity -->
            <div class="wm-field">
                <label class="wm-label" for="wm-l-capsrc">Capacity Source</label>
                <select id="wm-l-capsrc" v-model="selectedLink.capacity.source" class="wm-select" @change="emit('commit')">
                    <option value="ifSpeed">Auto from Port ifSpeed</option>
                    <option value="manual">Manual Override (rate-limited links)</option>
                </select>
            </div>
            <div v-if="selectedLink.capacity.source === 'manual'" class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-l-capin">In bps ({{ formatBits(selectedLink.capacity.inBps) }})</label>
                    <input id="wm-l-capin" v-model.number="selectedLink.capacity.inBps" type="number" min="1000" step="1000000" class="wm-input wm-tabular" @change="emit('commit')" />
                </div>
                <div>
                    <label class="wm-label" for="wm-l-capout">Out bps ({{ formatBits(selectedLink.capacity.outBps) }})</label>
                    <input id="wm-l-capout" v-model.number="selectedLink.capacity.outBps" type="number" min="1000" step="1000000" class="wm-input wm-tabular" @change="emit('commit')" />
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--wm-border); margin: 12px 0" />

            <!-- Target Expression Builder (LAG / aggregation / math) -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px">
                <span class="wm-label" style="margin: 0; color: var(--wm-primary)">Data Targets (summed)</span>
                <div style="display: flex; gap: 4px">
                    <button type="button" class="wm-btn" style="min-height: 28px; padding: 2px 8px" @click="addTargetTerm('port')">+ Port</button>
                    <button type="button" class="wm-btn" style="min-height: 28px; padding: 2px 8px" @click="addTargetTerm('rrd')">+ RRD</button>
                    <button type="button" class="wm-btn" style="min-height: 28px; padding: 2px 8px" @click="addTargetTerm('static')">+ Static</button>
                </div>
            </div>

            <div v-if="selectedLink.target.terms.length === 0" style="font-size: 12px; color: var(--wm-muted); margin-bottom: 10px">
                No datasource attached yet. Add a Port, RRD, or Static target.
            </div>

            <div
                v-for="(term, idx) in selectedLink.target.terms"
                :key="idx"
                style="padding: 8px; border: 1px solid var(--wm-border); border-radius: 6px; margin-bottom: 8px; background: rgba(15,23,42,0.6)"
            >
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px">
                    <span class="wm-badge">{{ term.source.toUpperCase() }} #{{ idx + 1 }}</span>
                    <button type="button" class="wm-btn wm-btn-danger" style="min-height: 24px; padding: 2px 8px" @click="removeTargetTerm(idx)">
                        &times;
                    </button>
                </div>

                <template v-if="term.source === 'port'">
                    <div class="wm-field" style="margin-bottom: 6px">
                        <label class="wm-label">Port ID: {{ term.portId ?? 'Unassigned' }}</label>
                        <input
                            v-model="portQuery"
                            class="wm-input"
                            placeholder="Search hostname or ifName..."
                            @focus="searchPorts(idx)"
                            @input="searchPorts(idx)"
                        />
                        <div v-if="activeTermIdx === idx && portResults.length > 0" class="wm-dropdown">
                            <button
                                v-for="p in portResults"
                                :key="p.port_id"
                                type="button"
                                class="wm-dropdown-item"
                                @click="pickPort(p, term)"
                            >
                                <strong>{{ p.hostname }} — {{ p.ifName }}</strong>
                                <span style="color: var(--wm-muted); font-size: 11px">
                                    {{ p.ifAlias || 'port' }} · {{ formatBits(p.ifSpeed) }}bps (#{{ p.port_id }})
                                </span>
                            </button>
                        </div>
                    </div>
                </template>

                <template v-else-if="term.source === 'rrd'">
                    <div class="wm-field" style="margin-bottom: 6px">
                        <label class="wm-label">Relative RRD Path (inside rrd_dir)</label>
                        <input v-model="term.rrdPath" class="wm-input" placeholder="core-rtr-01/port-id12.rrd" @change="emit('commit')" />
                    </div>
                    <div class="wm-row" style="margin-bottom: 6px">
                        <input v-model="term.dsIn" class="wm-input" placeholder="INOCTETS" aria-label="DS In" @change="emit('commit')" />
                        <input v-model="term.dsOut" class="wm-input" placeholder="OUTOCTETS" aria-label="DS Out" @change="emit('commit')" />
                    </div>
                </template>

                <template v-else>
                    <div class="wm-row" style="margin-bottom: 6px">
                        <div>
                            <label class="wm-label">In bps</label>
                            <input v-model.number="term.staticIn" type="number" class="wm-input wm-tabular" @change="emit('commit')" />
                        </div>
                        <div>
                            <label class="wm-label">Out bps</label>
                            <input v-model.number="term.staticOut" type="number" class="wm-input wm-tabular" @change="emit('commit')" />
                        </div>
                    </div>
                </template>

                <div style="display: flex; align-items: center; gap: 8px; font-size: 12px">
                    <label style="display: flex; align-items: center; gap: 4px">
                        Factor
                        <input v-model.number="term.factor" type="number" step="0.5" class="wm-input wm-tabular" style="width: 64px; min-height: 26px" @change="emit('commit')" />
                    </label>
                    <label style="display: flex; align-items: center; gap: 4px">
                        <input v-model="term.negate" type="checkbox" @change="emit('commit')" /> Minus
                    </label>
                    <label style="display: flex; align-items: center; gap: 4px">
                        <input v-model="term.reverse" type="checkbox" @change="emit('commit')" /> Swap
                    </label>
                </div>
            </div>

            <div class="wm-field">
                <label class="wm-label" for="wm-l-url">Custom Click URL (optional)</label>
                <input id="wm-l-url" v-model="selectedLink.infoUrl" class="wm-input" placeholder="/device/device=1/tab=port/..." @change="emit('commit')" />
            </div>
        </template>

        <!-- Canvas Inspector (when nothing is selected) -->
        <template v-else>
            <h3 class="wm-panel-title">Canvas Properties</h3>

            <div class="wm-field">
                <label class="wm-label" for="wm-c-title">Map Title</label>
                <input id="wm-c-title" v-model="doc.canvas.title" class="wm-input" @change="emit('commit')" />
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-c-w">Width (px)</label>
                    <input id="wm-c-w" v-model.number="doc.canvas.width" type="number" min="200" max="8000" class="wm-input wm-tabular" @change="emit('commit')" />
                </div>
                <div>
                    <label class="wm-label" for="wm-c-h">Height (px)</label>
                    <input id="wm-c-h" v-model.number="doc.canvas.height" type="number" min="200" max="8000" class="wm-input wm-tabular" @change="emit('commit')" />
                </div>
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-c-bg">Background</label>
                    <input id="wm-c-bg" v-model="doc.canvas.bgColor" type="color" class="wm-input" style="padding: 2px" @change="emit('commit')" />
                </div>
                <div>
                    <label class="wm-label" for="wm-c-ref">Refresh (sec)</label>
                    <input id="wm-c-ref" v-model.number="doc.settings.refreshSeconds" type="number" min="10" max="3600" class="wm-input wm-tabular" @change="emit('commit')" />
                </div>
            </div>

            <div class="wm-field">
                <button type="button" class="wm-btn" style="width: 100%" @click="emit('open-assets', 'background')">
                    Background Image ({{ doc.canvas.backgroundAssetId ? `#${doc.canvas.backgroundAssetId}` : 'None' }})
                </button>
                <button
                    v-if="doc.canvas.backgroundAssetId"
                    type="button"
                    class="wm-btn wm-btn-danger"
                    style="width: 100%; margin-top: 6px"
                    @click="doc.canvas.backgroundAssetId = null; doc.canvas.backgroundAssetUrl = null; emit('commit')"
                >
                    Remove Background Image
                </button>
            </div>

            <div class="wm-field">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px">
                    <input
                        type="checkbox"
                        :checked="pngEnabled"
                        @change="emit('update:pngEnabled', ($event.target as HTMLInputElement).checked)"
                    />
                    Scheduled 5-min PNG render (NOC / Thumbnails)
                </label>
            </div>
        </template>
    </aside>
</template>

<style scoped>
.wm-inspector {
    width: 320px;
    flex: 0 0 320px;
    background: var(--wm-surface);
    border-left: 1px solid var(--wm-border);
    padding: 14px;
    overflow-y: auto;
}
.wm-panel-title {
    font-size: 14px;
    font-weight: 700;
    margin: 0 0 12px;
    color: var(--wm-fg);
}
.wm-dropdown {
    margin-top: 4px;
    border: 1px solid var(--wm-border);
    border-radius: 6px;
    background: #020617;
    max-height: 180px;
    overflow-y: auto;
}
.wm-dropdown-item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    width: 100%;
    padding: 6px 8px;
    background: transparent;
    border: 0;
    border-bottom: 1px solid rgba(51, 65, 85, 0.5);
    color: var(--wm-fg);
    font-size: 12px;
    text-align: left;
    cursor: pointer;
}
.wm-dropdown-item:hover {
    background: rgba(56, 189, 248, 0.16);
}
</style>
