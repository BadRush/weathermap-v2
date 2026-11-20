<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import SceneSvg from '../components/SceneSvg.vue';
import { apiGet } from '../shared/api';
import type { Scene, SceneLink, SceneNode } from '../shared/types';

const props = defineProps<{
    mapId: number;
    mapName: string;
    canEdit: boolean;
}>();

const scene = ref<Scene | null>(null);
const loading = ref(true);
const errorMsg = ref<string | null>(null);
const zoom = ref<number | undefined>(undefined);
const secondsLeft = ref(60);
const graphFailed = ref<Record<string, boolean>>({});

const hoveredLink = ref<{ link: SceneLink; x: number; y: number } | null>(null);
const hoveredNode = ref<{ node: SceneNode; x: number; y: number } | null>(null);

let tickHandle: number | null = null;

async function fetchScene(): Promise<void> {
    try {
        const data = await apiGet<{ scene: Scene }>(`/weathermap/api/maps/${props.mapId}/scene`);
        scene.value = data.scene;
        secondsLeft.value = data.scene.refreshSeconds;
        errorMsg.value = null;
    } catch (e) {
        errorMsg.value = e instanceof Error ? e.message : 'Failed to load map scene.';
    } finally {
        loading.value = false;
    }
}

function handleHoverLink(payload: { link: SceneLink; event: MouseEvent } | null): void {
    if (!payload) {
        hoveredLink.value = null;
        return;
    }
    hoveredNode.value = null;
    hoveredLink.value = {
        link: payload.link,
        x: Math.min(window.innerWidth - 360, payload.event.clientX + 16),
        y: Math.min(window.innerHeight - 240, payload.event.clientY + 16),
    };
}

function handleHoverNode(payload: { node: SceneNode; event: MouseEvent } | null): void {
    if (!payload) {
        hoveredNode.value = null;
        return;
    }
    hoveredLink.value = null;
    hoveredNode.value = {
        node: payload.node,
        x: Math.min(window.innerWidth - 280, payload.event.clientX + 16),
        y: Math.min(window.innerHeight - 140, payload.event.clientY + 16),
    };
}

function onVisibilityChange(): void {
    if (!document.hidden && secondsLeft.value <= 1) {
        void fetchScene();
    }
}

function adjustZoom(delta: number): void {
    const current = zoom.value ?? 1;
    zoom.value = Math.max(0.4, Math.min(2.5, Math.round((current + delta) * 10) / 10));
}

onMounted(() => {
    void fetchScene();
    document.addEventListener('visibilitychange', onVisibilityChange);
    tickHandle = window.setInterval(() => {
        if (document.hidden) return;
        secondsLeft.value = Math.max(0, secondsLeft.value - 1);
        if (secondsLeft.value === 0) {
            void fetchScene();
        }
    }, 1000);
});

onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisibilityChange);
    if (tickHandle !== null) {
        window.clearInterval(tickHandle);
    }
});
</script>

<template>
    <div class="wm-root wm-viewer-shell">
        <header class="wm-toolbar">
            <a class="wm-btn" href="/weathermap">&larr; All Maps</a>
            <strong style="font-size: 15px">{{ mapName }}</strong>
            <span class="wm-badge wm-tabular" title="Auto-refresh countdown (pauses when tab hidden)">
                Refresh in {{ secondsLeft }}s
            </span>
            <div class="wm-spacer" />
            <button type="button" class="wm-btn" aria-label="Zoom out" @click="adjustZoom(-0.15)">-</button>
            <button type="button" class="wm-btn" @click="zoom = undefined">Fit</button>
            <button type="button" class="wm-btn" aria-label="Zoom in" @click="adjustZoom(0.15)">+</button>
            <button type="button" class="wm-btn" :disabled="loading" @click="fetchScene">Refresh</button>
            <a class="wm-btn" :href="`/weathermap/maps/${mapId}.png`" target="_blank" rel="noopener">PNG</a>
            <a class="wm-btn" :href="`/weathermap/kiosk?maps=${mapId}`">Kiosk</a>
            <a v-if="canEdit" class="wm-btn wm-btn-primary" :href="`/weathermap/maps/${mapId}/edit`">Edit Map</a>
        </header>

        <main class="wm-viewer-stage">
            <div v-if="loading && !scene" class="wm-skeleton" style="width: 100%; height: 520px" />

            <div v-else-if="errorMsg && !scene" class="wm-error-box" role="alert">
                <p>{{ errorMsg }}</p>
                <button type="button" class="wm-btn wm-btn-primary" @click="fetchScene">Retry</button>
            </div>

            <div v-else-if="scene" class="wm-canvas-scroll">
                <SceneSvg
                    :scene="scene"
                    :zoom="zoom"
                    @hover-link="handleHoverLink"
                    @hover-node="handleHoverNode"
                />
            </div>
        </main>

        <!-- Link Tooltip with Mini Graph -->
        <div
            v-if="hoveredLink"
            class="wm-tooltip wm-tabular"
            :style="{ left: `${hoveredLink.x}px`, top: `${hoveredLink.y}px` }"
            role="status"
        >
            <div style="font-weight: 700; margin-bottom: 6px; font-size: 13px">
                {{ hoveredLink.link.portLabel || `${hoveredLink.link.nodeA} ↔ ${hoveredLink.link.nodeB}` }}
            </div>
            <div class="wm-tt-grid">
                <span style="color: var(--wm-muted)">Out ({{ hoveredLink.link.nodeA }} → {{ hoveredLink.link.nodeB }})</span>
                <strong>
                    {{ hoveredLink.link.outArrow.bpsFormatted }}
                    <template v-if="hoveredLink.link.outArrow.percent !== null">({{ hoveredLink.link.outArrow.percent }}%)</template>
                </strong>
                <span style="color: var(--wm-muted)">In ({{ hoveredLink.link.nodeB }} → {{ hoveredLink.link.nodeA }})</span>
                <strong>
                    {{ hoveredLink.link.inArrow.bpsFormatted }}
                    <template v-if="hoveredLink.link.inArrow.percent !== null">({{ hoveredLink.link.inArrow.percent }}%)</template>
                </strong>
                <span style="color: var(--wm-muted)">Capacity</span>
                <span>{{ hoveredLink.link.outArrow.capacityFormatted }}</span>
            </div>
            <div v-if="hoveredLink.link.isDown" style="color: #fca5a5; font-weight: 700; margin-top: 6px">
                Status: PORT / DEVICE DOWN
            </div>
            <div v-else-if="hoveredLink.link.isStale" style="color: #fde68a; margin-top: 6px">
                Status: Data stale or unavailable
            </div>
            <img
                v-if="hoveredLink.link.graphUrl && !graphFailed[hoveredLink.link.id]"
                :src="`${hoveredLink.link.graphUrl}&width=320&height=110`"
                alt="24h traffic mini graph"
                width="320"
                height="110"
                style="display: block; width: 100%; height: auto; margin-top: 8px; border-radius: 4px; background: #020617"
                @error="graphFailed[hoveredLink.link.id] = true"
            />
        </div>

        <!-- Node Tooltip -->
        <div
            v-if="hoveredNode"
            class="wm-tooltip wm-tabular"
            :style="{ left: `${hoveredNode.x}px`, top: `${hoveredNode.y}px` }"
            role="status"
        >
            <div style="font-weight: 700; font-size: 13px">{{ hoveredNode.node.label || hoveredNode.node.id }}</div>
            <div v-if="hoveredNode.node.deviceId" style="color: var(--wm-muted); margin-top: 4px">
                LibreNMS Device #{{ hoveredNode.node.deviceId }}
            </div>
            <div v-if="hoveredNode.node.percent !== null" style="margin-top: 4px">
                Load: <strong>{{ hoveredNode.node.percent }}%</strong>
            </div>
            <div v-if="hoveredNode.node.infoUrl" style="color: var(--wm-primary); margin-top: 4px">
                Click to inspect
            </div>
        </div>
    </div>
</template>

<style scoped>
.wm-viewer-shell {
    min-height: calc(100vh - 60px);
    display: flex;
    flex-direction: column;
}
.wm-viewer-stage {
    flex: 1 1 auto;
    padding: 16px;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    overflow: auto;
}
.wm-canvas-scroll {
    max-width: 100%;
    overflow: auto;
    border-radius: 10px;
    border: 1px solid var(--wm-border);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.45);
}
.wm-tt-grid {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 4px 12px;
}
.wm-error-box {
    padding: 24px;
    border: 1px solid var(--wm-danger);
    border-radius: 10px;
    background: rgba(239, 68, 68, 0.1);
    text-align: center;
}
</style>
