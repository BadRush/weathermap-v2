<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import SceneSvg from '../components/SceneSvg.vue';
import { apiGet } from '../shared/api';
import type { Scene } from '../shared/types';

interface KioskMapItem {
    id: number;
    name: string;
    slug: string;
}

const props = defineProps<{
    maps: KioskMapItem[];
    rotateSeconds: number;
}>();

const currentIndex = ref(0);
const paused = ref(false);
const elapsed = ref(0);
const scene = ref<Scene | null>(null);
const errorMsg = ref<string | null>(null);

const currentMap = computed(() => props.maps[currentIndex.value] ?? null);
const progressPct = computed(() => (props.maps.length <= 1 ? 0 : Math.min(100, (elapsed.value / props.rotateSeconds) * 100)));

let timer: number | null = null;

async function loadCurrent(): Promise<void> {
    const target = currentMap.value;
    if (!target) return;
    try {
        const res = await apiGet<{ scene: Scene }>(`/weathermap/api/maps/${target.id}/scene`);
        scene.value = res.scene;
        errorMsg.value = null;
    } catch (e) {
        errorMsg.value = e instanceof Error ? e.message : 'Unable to load scene.';
    }
}

function step(delta: number): void {
    if (props.maps.length === 0) return;
    currentIndex.value = (currentIndex.value + delta + props.maps.length) % props.maps.length;
    elapsed.value = 0;
    void loadCurrent();
}

function toggleFullscreen(): void {
    if (!document.fullscreenElement) {
        void document.documentElement.requestFullscreen();
    } else {
        void document.exitFullscreen();
    }
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'ArrowRight') step(1);
    else if (e.key === 'ArrowLeft') step(-1);
    else if (e.key === ' ') {
        e.preventDefault();
        paused.value = !paused.value;
    } else if (e.key.toLowerCase() === 'f') {
        toggleFullscreen();
    }
}

onMounted(() => {
    void loadCurrent();
    window.addEventListener('keydown', onKeydown);
    timer = window.setInterval(() => {
        if (document.hidden || paused.value) return;
        elapsed.value += 1;
        if (props.maps.length > 1 && elapsed.value >= props.rotateSeconds) {
            step(1);
        } else if (props.maps.length === 1 && scene.value && elapsed.value >= scene.value.refreshSeconds) {
            elapsed.value = 0;
            void loadCurrent();
        }
    }, 1000);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    if (timer !== null) window.clearInterval(timer);
});
</script>

<template>
    <div class="wm-root wm-kiosk">
        <div v-if="maps.length === 0" class="wm-kiosk-empty">
            <h2>No maps available for Kiosk mode</h2>
            <a class="wm-btn wm-btn-primary" href="/weathermap">Return to Weathermap</a>
        </div>

        <template v-else>
            <div class="wm-kiosk-stage">
                <div v-if="errorMsg && !scene" role="alert">{{ errorMsg }}</div>
                <SceneSvg v-else-if="scene" :scene="scene" class="wm-kiosk-svg" />
            </div>

            <div class="wm-kiosk-hud">
                <span style="font-weight: 700">{{ currentMap?.name }}</span>
                <span v-if="maps.length > 1" class="wm-badge wm-tabular">
                    {{ currentIndex + 1 }} / {{ maps.length }}
                </span>
                <button v-if="maps.length > 1" type="button" class="wm-btn" aria-label="Previous map" @click="step(-1)">
                    &lsaquo;
                </button>
                <button
                    v-if="maps.length > 1"
                    type="button"
                    class="wm-btn"
                    :aria-label="paused ? 'Resume rotation' : 'Pause rotation'"
                    @click="paused = !paused"
                >
                    {{ paused ? 'Resume' : 'Pause' }}
                </button>
                <button v-if="maps.length > 1" type="button" class="wm-btn" aria-label="Next map" @click="step(1)">
                    &rsaquo;
                </button>
                <button type="button" class="wm-btn" @click="toggleFullscreen">Fullscreen</button>
                <a class="wm-btn" href="/weathermap">Exit</a>
            </div>

            <div v-if="maps.length > 1" class="wm-kiosk-bar">
                <div class="wm-kiosk-bar-fill" :style="{ width: `${progressPct}%` }" />
            </div>
        </template>
    </div>
</template>

<style scoped>
.wm-kiosk {
    position: fixed;
    inset: 0;
    background: #020617;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.wm-kiosk-stage {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 8px;
    min-height: 0;
}
.wm-kiosk-svg {
    width: 100%;
    height: 100%;
    max-width: 100%;
    max-height: 100%;
}
.wm-kiosk-hud {
    position: fixed;
    right: 16px;
    bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 10px;
    background: rgba(15, 23, 42, 0.86);
    border: 1px solid var(--wm-border);
    opacity: 0.28;
    transition: opacity 180ms ease-out;
}
.wm-kiosk-hud:hover,
.wm-kiosk-hud:focus-within {
    opacity: 1;
}
.wm-kiosk-bar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    background: rgba(255, 255, 255, 0.08);
}
.wm-kiosk-bar-fill {
    height: 100%;
    background: var(--wm-primary);
    transition: width 300ms linear;
}
.wm-kiosk-empty {
    margin: auto;
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 12px;
    align-items: center;
}
</style>
