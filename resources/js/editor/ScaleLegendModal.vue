<script setup lang="ts">
import { ref, watch } from 'vue';
import type { LegendDef, ScaleDef, ScaleStop } from '../shared/types';
import { classicDefaultScale, hexToRgb, rgbToHex } from '../shared/geometry';

const props = defineProps<{
    open: boolean;
    scales: ScaleDef[];
    legend: LegendDef;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'apply', payload: { scales: ScaleDef[]; legend: LegendDef }): void;
}>();

const dialogRef = ref<HTMLDialogElement | null>(null);
const localScales = ref<ScaleDef[]>([]);
const localLegend = ref<LegendDef>({ ...props.legend });
const activeScaleIdx = ref(0);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            localScales.value = JSON.parse(JSON.stringify(props.scales)) as ScaleDef[];
            if (localScales.value.length === 0) {
                localScales.value.push(classicDefaultScale());
            }
            localLegend.value = { ...props.legend };
            activeScaleIdx.value = 0;
            dialogRef.value?.showModal();
        } else {
            dialogRef.value?.close();
        }
    },
);

function currentStops(): ScaleStop[] {
    return localScales.value[activeScaleIdx.value]?.stops ?? [];
}

function addStop(): void {
    const scale = localScales.value[activeScaleIdx.value];
    if (!scale) return;
    scale.stops.push({ min: 0, max: 100, rgb1: [56, 189, 248], rgb2: null, label: null });
}

function removeStop(idx: number): void {
    const scale = localScales.value[activeScaleIdx.value];
    if (!scale) return;
    scale.stops.splice(idx, 1);
}

function updateRgb1(stop: ScaleStop, hex: string): void {
    stop.rgb1 = hexToRgb(hex);
}

function toggleGradient(stop: ScaleStop, enabled: boolean): void {
    stop.rgb2 = enabled ? [...stop.rgb1] : null;
}

function updateRgb2(stop: ScaleStop, hex: string): void {
    stop.rgb2 = hexToRgb(hex);
}

function resetClassic(): void {
    localScales.value[activeScaleIdx.value] = classicDefaultScale();
}

function save(): void {
    emit('apply', {
        scales: JSON.parse(JSON.stringify(localScales.value)) as ScaleDef[],
        legend: { ...localLegend.value },
    });
    emit('close');
}
</script>

<template>
    <dialog ref="dialogRef" class="wm-dialog" aria-labelledby="wm-scale-title" @cancel.prevent="emit('close')">
        <div class="wm-dialog-header">
            <h3 id="wm-scale-title" style="margin: 0; font-size: 16px">Scale &amp; Legend Editor</h3>
            <button type="button" class="wm-btn" aria-label="Close" @click="emit('close')">&times;</button>
        </div>

        <div class="wm-dialog-body">
            <h4 style="margin: 0 0 10px; font-size: 13px; color: var(--wm-primary)">Legend Settings</h4>
            <div class="wm-row wm-field">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px">
                    <input v-model="localLegend.visible" type="checkbox" />
                    Show Legend
                </label>
                <div>
                    <label class="wm-label" for="wm-leg-style">Style</label>
                    <select id="wm-leg-style" v-model="localLegend.style" class="wm-select">
                        <option value="classic">Classic (swatches)</option>
                        <option value="horizontal">Horizontal bar</option>
                        <option value="vertical">Vertical</option>
                    </select>
                </div>
            </div>

            <div class="wm-row wm-field">
                <div>
                    <label class="wm-label" for="wm-leg-title">Title</label>
                    <input id="wm-leg-title" v-model="localLegend.title" class="wm-input" type="text" />
                </div>
                <div class="wm-row">
                    <div>
                        <label class="wm-label" for="wm-leg-x">X</label>
                        <input id="wm-leg-x" v-model.number="localLegend.x" class="wm-input wm-tabular" type="number" />
                    </div>
                    <div>
                        <label class="wm-label" for="wm-leg-y">Y</label>
                        <input id="wm-leg-y" v-model.number="localLegend.y" class="wm-input wm-tabular" type="number" />
                    </div>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--wm-border); margin: 14px 0" />

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px">
                <h4 style="margin: 0; font-size: 13px; color: var(--wm-primary)">
                    Color Scale Stops ({{ localScales[activeScaleIdx]?.name ?? 'DEFAULT' }})
                </h4>
                <div style="display: flex; gap: 6px">
                    <button type="button" class="wm-btn" @click="resetClassic">Reset Classic 0.98</button>
                    <button type="button" class="wm-btn wm-btn-primary" @click="addStop">+ Add Stop</button>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 8px">
                <div
                    v-for="(stop, idx) in currentStops()"
                    :key="idx"
                    style="display: grid; grid-template-columns: 70px 70px 44px auto 44px 36px; gap: 6px; align-items: center"
                >
                    <input v-model.number="stop.min" class="wm-input wm-tabular" type="number" step="0.5" aria-label="Min percent" />
                    <input v-model.number="stop.max" class="wm-input wm-tabular" type="number" step="0.5" aria-label="Max percent" />
                    <input
                        type="color"
                        :value="rgbToHex(stop.rgb1)"
                        aria-label="Start color"
                        style="width: 40px; height: 32px; background: transparent; border: 1px solid var(--wm-border); border-radius: 4px"
                        @input="updateRgb1(stop, ($event.target as HTMLInputElement).value)"
                    />
                    <label style="display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--wm-muted)">
                        <input
                            type="checkbox"
                            :checked="stop.rgb2 !== null"
                            @change="toggleGradient(stop, ($event.target as HTMLInputElement).checked)"
                        />
                        Grad
                    </label>
                    <input
                        v-if="stop.rgb2"
                        type="color"
                        :value="rgbToHex(stop.rgb2)"
                        aria-label="End gradient color"
                        style="width: 40px; height: 32px; background: transparent; border: 1px solid var(--wm-border); border-radius: 4px"
                        @input="updateRgb2(stop, ($event.target as HTMLInputElement).value)"
                    />
                    <span v-else />
                    <button type="button" class="wm-btn wm-btn-danger" aria-label="Remove stop" @click="removeStop(idx)">
                        &times;
                    </button>
                </div>
            </div>
        </div>

        <div class="wm-dialog-footer">
            <button type="button" class="wm-btn" @click="emit('close')">Cancel</button>
            <button type="button" class="wm-btn wm-btn-primary" @click="save">Apply Scale &amp; Legend</button>
        </div>
    </dialog>
</template>
