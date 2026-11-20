<script setup lang="ts">
import { ref, watch } from 'vue';
import { apiUpload } from '../shared/api';
import type { ImportReport } from '../shared/types';

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const dialogRef = ref<HTMLDialogElement | null>(null);
const mapName = ref('');
const rawContent = ref('');
const selectedFile = ref<File | null>(null);
const importing = ref(false);
const errorMsg = ref<string | null>(null);
const result = ref<{ map: { id: number; name: string; editUrl: string }; report: ImportReport } | null>(null);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            result.value = null;
            errorMsg.value = null;
            dialogRef.value?.showModal();
        } else {
            dialogRef.value?.close();
        }
    },
);

function onFileSelect(e: Event): void {
    const input = e.target as HTMLInputElement;
    selectedFile.value = input.files?.[0] ?? null;
}

async function submitImport(): Promise<void> {
    importing.value = true;
    errorMsg.value = null;
    try {
        const fd = new FormData();
        if (mapName.value.trim() !== '') fd.append('name', mapName.value.trim());
        if (selectedFile.value) {
            fd.append('file', selectedFile.value);
        } else {
            fd.append('content', rawContent.value);
        }
        result.value = await apiUpload('/weathermap/api/import', fd);
    } catch (e) {
        errorMsg.value = e instanceof Error ? e.message : 'Import failed.';
    } finally {
        importing.value = false;
    }
}
</script>

<template>
    <dialog ref="dialogRef" class="wm-dialog" aria-labelledby="wm-import-title" @cancel.prevent="emit('close')">
        <div class="wm-dialog-header">
            <h3 id="wm-import-title" style="margin: 0; font-size: 16px">Import Classic Weathermap .conf</h3>
            <button type="button" class="wm-btn" aria-label="Close" @click="emit('close')">&times;</button>
        </div>

        <div class="wm-dialog-body">
            <template v-if="!result">
                <div class="wm-field">
                    <label class="wm-label" for="wm-imp-name">Map Name (optional, overrides TITLE)</label>
                    <input id="wm-imp-name" v-model="mapName" class="wm-input" type="text" placeholder="Core Backbone" />
                </div>

                <div class="wm-field">
                    <label class="wm-label" for="wm-imp-file">Upload .conf file</label>
                    <input id="wm-imp-file" type="file" accept=".conf,.txt" class="wm-input" @change="onFileSelect" />
                </div>

                <div class="wm-field">
                    <label class="wm-label" for="wm-imp-text">Or paste .conf contents</label>
                    <textarea
                        id="wm-imp-text"
                        v-model="rawContent"
                        rows="8"
                        class="wm-input"
                        style="font-family: ui-monospace, monospace"
                        placeholder="WIDTH 1000&#10;HEIGHT 640&#10;NODE rtr1&#10;  POSITION 200 300"
                    />
                </div>

                <div v-if="errorMsg" style="color: #fca5a5; font-size: 13px" role="alert">{{ errorMsg }}</div>
            </template>

            <template v-else>
                <div style="margin-bottom: 12px; padding: 10px; border-radius: 8px; background: rgba(34, 197, 94, 0.14); border: 1px solid rgba(34, 197, 94, 0.4)">
                    <strong>Imported "{{ result.map.name }}"</strong> —
                    {{ result.report.nodesImported }} nodes, {{ result.report.linksImported }} links,
                    {{ result.report.scalesImported }} custom scales.
                </div>

                <div v-if="result.report.convertedTargets.length > 0" class="wm-field">
                    <div class="wm-label">Converted Targets</div>
                    <ul style="margin: 4px 0 0 18px; font-size: 12px; color: var(--wm-fg)">
                        <li v-for="(item, i) in result.report.convertedTargets" :key="i">{{ item }}</li>
                    </ul>
                </div>

                <div v-if="result.report.warnings.length > 0" class="wm-field">
                    <div class="wm-label" style="color: #fde68a">Warnings</div>
                    <ul style="margin: 4px 0 0 18px; font-size: 12px; color: #fde68a">
                        <li v-for="(w, i) in result.report.warnings" :key="i">{{ w }}</li>
                    </ul>
                </div>

                <div v-if="result.report.skippedDirectives.length > 0" style="font-size: 12px; color: var(--wm-muted)">
                    Skipped legacy directives: {{ result.report.skippedDirectives.join(', ') }}
                </div>
            </template>
        </div>

        <div class="wm-dialog-footer">
            <button type="button" class="wm-btn" @click="emit('close')">Close</button>
            <button
                v-if="!result"
                type="button"
                class="wm-btn wm-btn-primary"
                :disabled="importing"
                @click="submitImport"
            >
                {{ importing ? 'Importing...' : 'Import Draft Map' }}
            </button>
            <a v-else class="wm-btn wm-btn-primary" :href="result.map.editUrl">Open Imported Map</a>
        </div>
    </dialog>
</template>
