<script setup lang="ts">
import { ref, watch } from 'vue';
import { apiGet, apiUpload } from '../shared/api';
import type { AssetItem } from '../shared/types';

const props = defineProps<{
    open: boolean;
    kind: 'background' | 'icon';
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'select', asset: AssetItem): void;
}>();

const dialogRef = ref<HTMLDialogElement | null>(null);
const assets = ref<AssetItem[]>([]);
const loading = ref(false);
const uploading = ref(false);
const errorMsg = ref<string | null>(null);

async function loadAssets(): Promise<void> {
    loading.value = true;
    errorMsg.value = null;
    try {
        const res = await apiGet<{ assets: AssetItem[] }>(`/weathermap/api/assets?kind=${props.kind}`);
        assets.value = res.assets;
    } catch (e) {
        errorMsg.value = e instanceof Error ? e.message : 'Failed to load assets.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            dialogRef.value?.showModal();
            void loadAssets();
        } else {
            dialogRef.value?.close();
        }
    },
);

async function onFileChange(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    uploading.value = true;
    errorMsg.value = null;
    try {
        const fd = new FormData();
        fd.append('kind', props.kind);
        fd.append('file', file);
        const res = await apiUpload<{ asset: AssetItem }>('/weathermap/api/assets', fd);
        assets.value = [res.asset, ...assets.value.filter((a) => a.id !== res.asset.id)];
    } catch (e) {
        errorMsg.value = e instanceof Error ? e.message : 'Upload failed.';
    } finally {
        uploading.value = false;
        input.value = '';
    }
}
</script>

<template>
    <dialog ref="dialogRef" class="wm-dialog" aria-labelledby="wm-assets-title" @cancel.prevent="emit('close')">
        <div class="wm-dialog-header">
            <h3 id="wm-assets-title" style="margin: 0; font-size: 16px">
                {{ kind === 'background' ? 'Background Images' : 'Node Icons' }}
            </h3>
            <button type="button" class="wm-btn" aria-label="Close" @click="emit('close')">&times;</button>
        </div>

        <div class="wm-dialog-body">
            <div class="wm-field">
                <label class="wm-label" for="wm-asset-upload">Upload PNG, JPEG, or WebP (max 2 MB; SVG blocked)</label>
                <input
                    id="wm-asset-upload"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    :disabled="uploading"
                    class="wm-input"
                    @change="onFileChange"
                />
            </div>

            <div v-if="errorMsg" style="color: #fca5a5; font-size: 13px; margin-bottom: 10px" role="alert">
                {{ errorMsg }}
            </div>

            <div v-if="loading" class="wm-skeleton" style="height: 120px" />

            <div v-else-if="assets.length === 0" style="color: var(--wm-muted); font-size: 13px; padding: 16px 0">
                No uploaded {{ kind }} images yet.
            </div>

            <div v-else style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px">
                <button
                    v-for="asset in assets"
                    :key="asset.id"
                    type="button"
                    class="wm-btn"
                    style="flex-direction: column; padding: 8px; height: auto"
                    @click="emit('select', asset); emit('close')"
                >
                    <img
                        :src="asset.url"
                        :alt="asset.original_name"
                        style="width: 100%; height: 72px; object-fit: contain; background: #020617; border-radius: 4px"
                    />
                    <span style="font-size: 11px; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                        {{ asset.original_name }}
                    </span>
                </button>
            </div>
        </div>

        <div class="wm-dialog-footer">
            <button type="button" class="wm-btn" @click="emit('close')">Close</button>
        </div>
    </dialog>
</template>
